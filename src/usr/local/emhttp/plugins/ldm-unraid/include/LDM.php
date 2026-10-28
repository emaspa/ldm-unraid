<?php
/* ldm-unraid: read Windows dynamic disks (LDM) on Unraid.
 *
 * The on-disk parsing follows libldm (https://github.com/mdbooth/libldm) and
 * the Linux kernel's block/partitions/ldm.c. Volumes are assembled with
 * dmsetup using the same device names ldmtool uses (ldm_vol_<group>_<volume>).
 *
 * Copyright 2026 Emanuele Sparvoli. GPL-3.0-or-later.
 */

const LDM_PLUGIN = 'ldm-unraid';
const LDM_DM_PREFIX = 'ldm_vol_';
const LDM_DM_UUID_PREFIX = 'LDM-';
const LDM_MOUNT_ROOT = '/mnt/disks';

// GPT partition type of the LDM metadata partition, as stored on disk
// (5808C8AA-7E8F-42E0-85D2-E1E90434CFB3, mixed endian).
const LDM_GPT_METADATA = "\xAA\xC8\x08\x58\x8F\x7E\xE0\x42\x85\xD2\xE1\xE9\x04\x34\xCF\xB3";
const LDM_GPT_METADATA_STR = '5808c8aa-7e8f-42e0-85d2-e1e90434cfb3';
const LDM_GPT_DATA_STR = 'af9b60a0-1431-4f62-bc68-3311714a69ad';

class LdmException extends Exception {}

/* ---------------------------------------------------------------------------
 * Binary helpers
 */

function ldm_be16(string $b, int $o): int { return unpack('n', $b, $o)[1]; }
function ldm_be32(string $b, int $o): int { return unpack('N', $b, $o)[1]; }
function ldm_be64(string $b, int $o): int { return unpack('J', $b, $o)[1]; }
function ldm_le32(string $b, int $o): int { return unpack('V', $b, $o)[1]; }
function ldm_le64(string $b, int $o): int { return unpack('P', $b, $o)[1]; }

function ldm_cstr(string $b, int $o, int $len): string
{
	$s = substr($b, $o, $len);
	$z = strpos($s, "\0");
	return $z === false ? $s : substr($s, 0, $z);
}

function ldm_guid_str(string $raw): string
{
	$h = bin2hex($raw);
	return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20, 12);
}

function ldm_read(string $dev, int $off, int $len): string
{
	$fh = @fopen($dev, 'rb');
	if (!$fh) throw new LdmException("cannot open $dev");
	try {
		if (fseek($fh, $off) !== 0) throw new LdmException("cannot seek $dev to $off");
		$data = '';
		while (strlen($data) < $len) {
			$chunk = fread($fh, $len - strlen($data));
			if ($chunk === false || $chunk === '') break;
			$data .= $chunk;
		}
	} finally {
		fclose($fh);
	}
	if (strlen($data) != $len) throw new LdmException("short read on $dev at $off");
	return $data;
}

/* Cursor over a VBLK record body: LDM stores most fields as
 * length-prefixed big-endian integers and length-prefixed strings. */
class LdmCursor
{
	public int $p = 0;
	public function __construct(public string $b) {}

	public function varInt(int $max = 8): int
	{
		$n = ord($this->b[$this->p++]);
		if ($n > $max) throw new LdmException("VBLK integer of $n bytes");
		$v = 0;
		for ($i = 0; $i < $n; $i++) $v = ($v << 8) | ord($this->b[$this->p++]);
		return $v;
	}
	public function varStr(): string
	{
		$n = ord($this->b[$this->p++]);
		$s = substr($this->b, $this->p, $n);
		$this->p += $n;
		return $s;
	}
	public function skipVar(): void { $n = ord($this->b[$this->p++]); $this->p += $n; }
	public function skip(int $n): void { $this->p += $n; }
	public function u8(): int { return ord($this->b[$this->p++]); }
	public function be64(): int { $v = ldm_be64($this->b, $this->p); $this->p += 8; return $v; }
	public function raw(int $n): string { $s = substr($this->b, $this->p, $n); $this->p += $n; return $s; }
}

/* ---------------------------------------------------------------------------
 * Device discovery
 */

/* udev properties of a block device (E: lines of /run/udev/data). */
function ldm_udev(string $sysdir): array
{
	$dev = trim((string)@file_get_contents("$sysdir/dev"));
	$props = [];
	foreach (@file("/run/udev/data/b$dev", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
		if (str_starts_with($line, 'E:') && ($eq = strpos($line, '=')) !== false) {
			$props[substr($line, 2, $eq - 2)] = substr($line, $eq + 1);
		}
	}
	return $props;
}

/* Does udev's view of the disk's partitions say LDM? This needs no disk I/O,
 * so spun-down disks stay asleep. */
function ldm_udev_says_ldm(string $sysdir, array $udev): ?bool
{
	if (!$udev) return null;
	$table = $udev['ID_PART_TABLE_TYPE'] ?? '';
	if ($table === '') return false;
	$name = basename($sysdir);
	foreach (glob("$sysdir/$name*", GLOB_ONLYDIR) ?: [] as $pdir) {
		if (!is_file("$pdir/partition")) continue;
		$pu = ldm_udev($pdir);
		$type = strtolower($pu['ID_PART_ENTRY_TYPE'] ?? '');
		if ($type === '0x42' || $type === LDM_GPT_METADATA_STR || $type === LDM_GPT_DATA_STR) return true;
		// With CONFIG_LDM_PARTITION the kernel replaces the single 0x42
		// partition of an MBR dynamic disk with one partition per LDM extent.
		// Those match no partition table entry, so udev has no ID_PART_ENTRY_*.
		if ($table === 'dos' && $pu && !isset($pu['ID_PART_ENTRY_NUMBER'])) return true;
	}
	return false;
}

/* Whole-disk block devices that may hold LDM metadata. Devices named in
 * $only are always probed; otherwise udev data is used as a prefilter. */
function ldm_candidate_devices(?array $only = null): array
{
	$out = [];
	foreach (glob('/sys/block/*') ?: [] as $sysdir) {
		$name = basename($sysdir);
		$dev = "/dev/$name";
		if (preg_match('/^(ram|zram|dm-|md|nbd|sr|fd)/', $name)) continue;
		if ($only !== null && !in_array($dev, $only) && !in_array(@realpath($dev), $only)) continue;
		$size = (int)@file_get_contents("$sysdir/size") * 512;
		if ($size == 0) continue;
		$udev = ldm_udev($sysdir);
		if ($only === null) {
			$ldm = ldm_udev_says_ldm($sysdir, $udev);
			if ($ldm === false) continue;
			if ($ldm === null && str_starts_with($name, 'loop')) continue;
		}
		$out[$dev] = [
			'device' => $dev,
			'size'   => $size,
			'secsize'=> (int)(@file_get_contents("$sysdir/queue/logical_block_size") ?: 512),
			'model'  => str_replace('_', ' ', $udev['ID_MODEL'] ?? ''),
			'serial' => $udev['ID_SERIAL_SHORT'] ?? '',
		];
	}
	return $out;
}

/* Array and pool members, which must never be touched. */
function ldm_unraid_assigned(): array
{
	$devs = [];
	$ini = '/var/local/emhttp/disks.ini';
	if (is_file($ini)) {
		foreach (parse_ini_file($ini, true) ?: [] as $disk) {
			if (!empty($disk['device'])) $devs[] = '/dev/'.$disk['device'];
		}
	}
	return $devs;
}

/* ---------------------------------------------------------------------------
 * On-disk structures
 */

function ldm_parse_privhead(string $b): array
{
	if (substr($b, 0, 8) !== 'PRIVHEAD') throw new LdmException('PRIVHEAD not found');
	return [
		'version'         => ldm_be16($b, 12).'.'.ldm_be16($b, 14),
		'disk_guid'       => strtolower(ldm_cstr($b, 0x30, 64)),
		'host_guid'       => strtolower(ldm_cstr($b, 0x70, 64)),
		'dg_guid'         => strtolower(ldm_cstr($b, 0xB0, 64)),
		'dg_name'         => ldm_cstr($b, 0xF0, 32),
		'data_start'      => ldm_be64($b, 0x11B),
		'data_size'       => ldm_be64($b, 0x123),
		'config_start'    => ldm_be64($b, 0x12B),
		'config_size'     => ldm_be64($b, 0x133),
	];
}

/* Locate and parse the PRIVHEAD of one disk. Returns null if it isn't LDM. */
function ldm_read_privhead(string $dev, int $secsize, int $size): ?array
{
	$mbr = ldm_read($dev, 0, 512);
	if (substr($mbr, 510, 2) !== "\x55\xAA") return null;
	$type = ord($mbr[446 + 4]);
	$style = null;
	$offsets = [];

	if ($type === 0x42) {
		$style = 'mbr';
		// Primary copy in sector 6, backup in the last sector of the disk.
		$offsets = [6 * $secsize, $size - $secsize];
	} elseif ($type === 0xEE) {
		$style = 'gpt';
		$hdr = ldm_read($dev, $secsize, 92);
		if (substr($hdr, 0, 8) !== 'EFI PART') return null;
		$lba = ldm_le64($hdr, 72);
		$count = ldm_le32($hdr, 80);
		$esize = ldm_le32($hdr, 84);
		if ($esize < 128 || $count > 4096) return null;
		$table = ldm_read($dev, $lba * $secsize, $count * $esize);
		for ($i = 0; $i < $count; $i++) {
			$e = substr($table, $i * $esize, $esize);
			if (substr($e, 0, 16) === LDM_GPT_METADATA) {
				// PRIVHEAD is the last LBA of the LDM metadata partition.
				$offsets[] = ldm_le64($e, 40) * $secsize;
				break;
			}
		}
	} else {
		return null;
	}

	$err = null;
	foreach ($offsets as $off) {
		try {
			$ph = ldm_parse_privhead(ldm_read($dev, $off, 512));
			$ph['style'] = $style;
			return $ph;
		} catch (LdmException $e) {
			$err = $e;
		}
	}
	if ($err) throw new LdmException("$dev: ".$err->getMessage());
	return null;
}

/* Parse the LDM database (TOCBLOCK -> VMDB -> VBLKs) of one disk. */
function ldm_parse_config(string $config, int $secsize): array
{
	// TOCBLOCK copies live in sectors 1, 2, 2045 and 2046 of the database.
	$toc = null;
	foreach ([2, 1, 2045, 2046] as $s) {
		$t = substr($config, $s * $secsize, 512);
		if (substr($t, 0, 8) === 'TOCBLOCK') { $toc = $t; break; }
	}
	if ($toc === null) throw new LdmException('TOCBLOCK not found');

	$vmdb_off = null;
	foreach ([36, 70] as $o) {
		if (ldm_cstr($toc, $o, 8) === 'config') { $vmdb_off = ldm_be64($toc, $o + 10) * $secsize; break; }
	}
	if ($vmdb_off === null) throw new LdmException('TOCBLOCK has no config bitmap');

	$v = substr($config, $vmdb_off, 512);
	if (substr($v, 0, 4) !== 'VMDB') throw new LdmException('VMDB not found');
	$vmdb = [
		'vblk_size'   => ldm_be32($v, 8),
		'vblk_first'  => ldm_be32($v, 12),
		'version'     => ldm_be16($v, 18).'.'.ldm_be16($v, 20),
		'dg_name'     => ldm_cstr($v, 22, 31),
		'dg_guid'     => strtolower(ldm_cstr($v, 53, 64)),
		'seq'         => ldm_be64($v, 117),
		'n_vol'       => ldm_be32($v, 133),
		'n_comp'      => ldm_be32($v, 137),
		'n_part'      => ldm_be32($v, 141),
		'n_disk'      => ldm_be32($v, 145),
	];
	$size = $vmdb['vblk_size'];
	if ($size < 32 || $size > 65536) throw new LdmException("bad VBLK size $size");
	$data_size = $size - 16;

	// Records larger than one VBLK are split across several entries that share
	// a record id; collect the fragments before parsing.
	$records = [];
	$frags = [];
	for ($off = $vmdb_off + $vmdb['vblk_first']; $off + $size <= strlen($config); $off += $size) {
		$h = substr($config, $off, 16);
		if (substr($h, 0, 4) !== 'VBLK') break;
		$rid = ldm_be32($h, 8);
		$entry = ldm_be16($h, 12);
		$total = ldm_be16($h, 14);
		$body = substr($config, $off + 16, $data_size);
		if ($total > 1) {
			if ($entry >= $total) throw new LdmException("VBLK record $rid entry $entry of $total");
			$frags[$rid]['total'] = $total;
			$frags[$rid]['parts'][$entry] = $body;
		} else {
			$records[] = $body;
		}
	}
	foreach ($frags as $rid => $f) {
		if (count($f['parts']) != $f['total']) {
			throw new LdmException("VBLK record $rid has ".count($f['parts'])." of {$f['total']} fragments");
		}
		ksort($f['parts']);
		$records[] = implode('', $f['parts']);
	}

	$db = ['vmdb' => $vmdb, 'dg' => null, 'vols' => [], 'comps' => [], 'parts' => [], 'disks' => []];
	foreach ($records as $r) ldm_parse_vblk($r, $db);

	foreach (['vol' => 'vols', 'comp' => 'comps', 'part' => 'parts', 'disk' => 'disks'] as $k => $list) {
		if (count($db[$list]) != $vmdb["n_$k"]) {
			throw new LdmException("expected {$vmdb["n_$k"]} $k records, found ".count($db[$list]));
		}
	}
	return $db;
}

function ldm_parse_vblk(string $r, array &$db): void
{
	// Record head: status (2), flags (1), type/revision (1), size (4).
	$flags = ord($r[2]);
	$type = ord($r[3]) & 0x0F;
	$rev = (ord($r[3]) & 0xF0) >> 4;
	$c = new LdmCursor(substr($r, 8));

	switch ($type) {
	case 0x00: // blank
		return;

	case 0x01: // volume
		if ($rev != 5) throw new LdmException("unsupported volume VBLK revision $rev");
		$vol = ['id' => $c->varInt(4), 'name' => $c->varStr()];
		$vol['kind'] = $c->varStr();      // "gen" or "raid5"
		$c->skipVar();                    // unknown
		$vol['state'] = rtrim($c->raw(14), "\0");
		$vol['int_type'] = $c->u8();      // 3 = gen, 4 = raid5
		$c->skip(1 + 1 + 3);              // unknown, volume number, zeroes
		$vol['vflags'] = $c->u8();
		$vol['n_comps'] = $c->varInt(4);
		$c->skip(16);                     // commit id, id
		$vol['size'] = $c->varInt();
		$c->skip(4);
		$vol['part_type'] = $c->u8();
		$vol['guid'] = ldm_guid_str($c->raw(16));
		if ($flags & 0x08) $c->varStr();  // id1
		if ($flags & 0x20) $c->varStr();  // id2
		if ($flags & 0x80) $c->varInt();  // size2
		$vol['hint'] = ($flags & 0x02) ? $c->varStr() : '';
		if (!in_array($vol['int_type'], [3, 4])) throw new LdmException("unsupported volume type {$vol['int_type']}");
		$db['vols'][$vol['id']] = $vol;
		return;

	case 0x02: // component
		if ($rev != 3) throw new LdmException("unsupported component VBLK revision $rev");
		$comp = ['id' => $c->varInt(4)];
		$c->skipVar();                    // name
		$c->skipVar();                    // state
		$comp['type'] = $c->u8();         // 1 = striped, 2 = spanned, 3 = raid
		$c->skip(4);
		$comp['n_parts'] = $c->varInt(4);
		$c->skip(16);                     // log commit id, zeroes
		$comp['parent'] = $c->varInt(4);
		$c->skip(1);
		$comp['chunk'] = 0;
		$comp['columns'] = 0;
		if ($flags & 0x10) {
			$comp['chunk'] = $c->varInt();
			$comp['columns'] = $c->varInt(4);
		}
		if (!in_array($comp['type'], [1, 2, 3])) throw new LdmException("unsupported component type {$comp['type']}");
		$db['comps'][$comp['id']] = $comp;
		return;

	case 0x03: // partition
		if ($rev != 3) throw new LdmException("unsupported partition VBLK revision $rev");
		$part = ['id' => $c->varInt(4), 'name' => $c->varStr()];
		$c->skip(12);                     // zeroes, log commit id
		$part['start'] = $c->be64();
		$part['vol_offset'] = $c->be64();
		$part['size'] = $c->varInt();
		$part['parent'] = $c->varInt(4);
		$part['disk_id'] = $c->varInt(4);
		$part['index'] = ($flags & 0x08) ? $c->varInt(4) : 0;
		$db['parts'][$part['id']] = $part;
		return;

	case 0x04: // disk
		$disk = ['id' => $c->varInt(4), 'name' => $c->varStr()];
		if ($rev == 3) $disk['guid'] = strtolower($c->varStr());
		elseif ($rev == 4) $disk['guid'] = ldm_guid_str($c->raw(16));
		else throw new LdmException("unsupported disk VBLK revision $rev");
		$db['disks'][$disk['id']] = $disk;
		return;

	case 0x05: // disk group
		if ($rev != 3 && $rev != 4) throw new LdmException("unsupported disk group VBLK revision $rev");
		$db['dg'] = ['id' => $c->varInt(4), 'name' => $c->varStr()];
		return;

	default:
		throw new LdmException("unknown VBLK type $type");
	}
}

/* ---------------------------------------------------------------------------
 * Scan: build disk groups and volumes from every LDM disk found
 */

function ldm_scan(?array $devices = null): array
{
	$assigned = ldm_unraid_assigned();
	$found = [];      // disk guid => member info
	$dbs = [];        // dg guid => [seq, db]
	$errors = [];

	foreach (ldm_candidate_devices($devices) as $dev => $info) {
		if (in_array($dev, $assigned)) continue;
		try {
			$ph = ldm_read_privhead($dev, $info['secsize'], $info['size']);
			if ($ph === null) continue;
			$cfg = ldm_read($dev, $ph['config_start'] * $info['secsize'], $ph['config_size'] * $info['secsize']);
			$db = ldm_parse_config($cfg, $info['secsize']);
		} catch (LdmException $e) {
			$errors[] = "$dev: ".$e->getMessage();
			continue;
		}
		$found[$ph['disk_guid']] = $info + ['privhead' => $ph, 'seq' => $db['vmdb']['seq']];
		$g = $ph['dg_guid'];
		// Windows updates every member's copy of the database; when copies
		// differ, the highest committed sequence is the current one.
		if (!isset($dbs[$g]) || $db['vmdb']['seq'] > $dbs[$g]['seq']) {
			$dbs[$g] = ['seq' => $db['vmdb']['seq'], 'db' => $db];
		}
	}

	$groups = [];
	foreach ($dbs as $g => $entry) {
		try {
			$groups[] = ldm_build_group($g, $entry['db'], $found);
		} catch (LdmException $e) {
			$errors[] = "disk group $g: ".$e->getMessage();
		}
	}
	return ['groups' => $groups, 'errors' => $errors];
}

function ldm_build_group(string $guid, array $db, array $found): array
{
	$dgname = $db['dg']['name'] ?? $db['vmdb']['dg_name'];
	$seq = $db['vmdb']['seq'];

	$disks = [];
	foreach ($db['disks'] as $id => $d) {
		$m = $found[$d['guid']] ?? null;
		$disks[$id] = [
			'name'    => $d['name'],
			'guid'    => $d['guid'],
			'device'  => $m['device'] ?? null,
			'model'   => $m['model'] ?? '',
			'serial'  => $m['serial'] ?? '',
			'size'    => $m['size'] ?? 0,
			'secsize' => $m['secsize'] ?? 512,
			'data_start' => $m['privhead']['data_start'] ?? 0,
			'stale'   => $m !== null && $m['seq'] != $seq,
		];
	}

	// partitions -> components -> volumes
	$comps = $db['comps'];
	foreach ($comps as &$cp) $cp['parts'] = [];
	unset($cp);
	foreach ($db['parts'] as $p) {
		if (!isset($disks[$p['disk_id']])) throw new LdmException("partition {$p['name']} references unknown disk {$p['disk_id']}");
		if (!isset($comps[$p['parent']])) throw new LdmException("partition {$p['name']} has no parent component");
		$comps[$p['parent']]['parts'][] = $p;
	}

	$vols = [];
	foreach ($db['vols'] as $vid => $v) {
		$v['comps'] = [];
		$vols[$vid] = $v;
	}
	foreach ($comps as $cp) {
		if (count($cp['parts']) != $cp['n_parts']) throw new LdmException("component {$cp['id']} expects {$cp['n_parts']} partitions, found ".count($cp['parts']));
		if (!isset($vols[$cp['parent']])) throw new LdmException("component {$cp['id']} has no parent volume");
		usort($cp['parts'], fn($a, $b) => $a['index'] <=> $b['index']);
		$vols[$cp['parent']]['comps'][] = $cp;
	}

	$out = [];
	foreach ($vols as $v) {
		if (count($v['comps']) != $v['n_comps']) throw new LdmException("volume {$v['name']} expects {$v['n_comps']} components, found ".count($v['comps']));
		$out[] = ldm_describe_volume($v, $dgname, $disks);
	}
	usort($out, fn($a, $b) => strnatcmp($a['name'], $b['name']));

	return [
		'guid'   => $guid,
		'name'   => $dgname,
		'seq'    => $seq,
		'disks'  => array_values($disks),
		'volumes'=> $out,
	];
}

function ldm_volume_type(array $v): string
{
	$c = $v['comps'][0];
	if ($c['type'] == 3) return 'raid5';
	if ($c['type'] == 1) return 'striped';
	if (count($v['comps']) > 1) return 'mirrored';
	return count($c['parts']) > 1 ? 'spanned' : 'simple';
}

/* Turn a parsed volume into something the CLI and UI can show, including the
 * device-mapper table that would assemble it (null when it can't be). */
function ldm_describe_volume(array $v, string $dgname, array $disks): array
{
	$type = ldm_volume_type($v);
	$members = [];
	$missing = [];
	foreach ($v['comps'] as $ci => $c) {
		foreach ($c['parts'] as $p) {
			$d = $disks[$p['disk_id']];
			$members[] = [
				'partition' => $p['name'],
				'disk'      => $d['name'],
				'device'    => $d['device'],
				'plex'      => $ci,
			];
			if ($d['device'] === null) $missing[$d['name']] = true;
		}
	}

	$table = null;
	$problem = null;
	$readonly_only = false;
	try {
		switch ($type) {
		case 'simple':
		case 'spanned':
			$table = ldm_table_linear($v['comps'][0], $disks);
			break;
		case 'striped':
			$table = ldm_table_striped($v['comps'][0], $disks, $v['size']);
			break;
		case 'mirrored':
			// Unraid's kernel has no dm-raid, so read one complete copy.
			$readonly_only = true;
			foreach ($v['comps'] as $c) {
				try { $table = ldm_table_linear($c, $disks); break; }
				catch (LdmException $e) { $problem = $e->getMessage(); }
			}
			if ($table !== null) $problem = null;
			break;
		case 'raid5':
			throw new LdmException('RAID-5 volumes are not supported (Unraid has no dm-raid)');
		}
	} catch (LdmException $e) {
		$problem = $e->getMessage();
	}

	$dmname = ldm_dm_name($dgname, $v['name']);
	return [
		'name'      => $v['name'],
		'group'     => $dgname,
		'guid'      => $v['guid'],
		'type'      => $type,
		'size'      => $v['size'] * 512,
		'hint'      => $v['hint'],
		'part_type' => sprintf('0x%02x', $v['part_type']),
		'members'   => $members,
		'missing'   => array_keys($missing),
		'dm_name'   => $dmname,
		'dm_uuid'   => LDM_DM_UUID_PREFIX.$v['name'].'-'.$v['guid'],
		'table'     => $table,
		'problem'   => $problem,
		'readonly_only' => $readonly_only,
	];
}

/* LDM counts in the disk's logical sectors; device-mapper always counts in
 * 512-byte sectors. */
function ldm_scale(array $disk): int { return intdiv($disk['secsize'], 512); }

function ldm_need_disk(array $disk, string $part): void
{
	if ($disk['device'] === null) throw new LdmException("disk {$disk['name']} (partition $part) is missing");
}

function ldm_table_linear(array $comp, array $disks): string
{
	$parts = $comp['parts'];
	usort($parts, fn($a, $b) => $a['vol_offset'] <=> $b['vol_offset']);
	$lines = [];
	$pos = 0;
	foreach ($parts as $p) {
		$d = $disks[$p['disk_id']];
		ldm_need_disk($d, $p['name']);
		$k = ldm_scale($d);
		if ($p['vol_offset'] * $k != $pos) throw new LdmException("partition {$p['name']} offset does not follow the previous extent");
		$len = $p['size'] * $k;
		$lines[] = sprintf('%d %d linear %s %d', $pos, $len, $d['device'], ($d['data_start'] + $p['start']) * $k);
		$pos += $len;
	}
	return implode("\n", $lines);
}

function ldm_table_striped(array $comp, array $disks, int $vol_size): string
{
	$parts = $comp['parts'];
	$n = count($parts);
	if ($comp['columns'] && $comp['columns'] != $n) throw new LdmException("striped component has {$comp['columns']} columns but $n partitions");
	$k = ldm_scale($disks[$parts[0]['disk_id']]);
	$chunk = $comp['chunk'] * $k;
	if ($chunk <= 0) throw new LdmException('striped component without chunk size');
	// dm-striped needs a length that is a whole number of stripes.
	$len = intdiv($vol_size * $k, $chunk * $n) * $chunk * $n;
	$devs = [];
	foreach ($parts as $p) {
		$d = $disks[$p['disk_id']];
		ldm_need_disk($d, $p['name']);
		if (ldm_scale($d) != $k) throw new LdmException('striped volume spans disks with different sector sizes');
		$devs[] = sprintf('%s %d', $d['device'], ($d['data_start'] + $p['start']) * $k);
	}
	return sprintf('0 %d striped %d %d %s', $len, $n, $chunk, implode(' ', $devs));
}

function ldm_dm_name(string $dg, string $vol): string
{
	// Same naming as ldmtool so the two tools see each other's devices.
	return preg_replace('/[^A-Za-z0-9_.+-]/', '_', LDM_DM_PREFIX.$dg.'_'.$vol);
}

/* ---------------------------------------------------------------------------
 * Runtime state: assembled devices and mounts
 */

function ldm_run(string $cmd, ?string $stdin = null): array
{
	$p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($p)) return [127, '', "cannot run $cmd"];
	if ($stdin !== null) fwrite($pipes[0], $stdin);
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return [proc_close($p), trim($out), trim($err)];
}

function ldm_log(string $msg): void
{
	ldm_run('logger -t '.LDM_PLUGIN.' '.escapeshellarg($msg));
}

/* dm devices whose uuid marks them as LDM volumes: name => [uuid, ro]. */
function ldm_dm_devices(): array
{
	[$rc, $out] = ldm_run('dmsetup info -c --noheadings --separator "|" -o name,uuid,attr 2>/dev/null');
	$devs = [];
	if ($rc != 0) return $devs;
	foreach (explode("\n", $out) as $line) {
		$f = explode('|', trim($line));
		if (count($f) < 3 || !str_starts_with($f[1], LDM_DM_UUID_PREFIX)) continue;
		$devs[$f[0]] = ['uuid' => $f[1], 'readonly' => ($f[2][3] ?? '') === 'r'];
	}
	return $devs;
}

function ldm_mount_of(string $dmname): ?array
{
	$dev = "/dev/mapper/$dmname";
	$real = @realpath($dev);
	foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
		$f = explode(' ', $line);
		$src = stripcslashes($f[0]);
		if ($src === $dev || ($real && $src === $real)) {
			$opts = explode(',', $f[3]);
			return ['mountpoint' => stripcslashes($f[1]), 'fstype' => $f[2], 'readonly' => in_array('ro', $opts)];
		}
	}
	return null;
}

function ldm_blkid(string $dev): array
{
	[$rc, $out] = ldm_run('blkid -p -o export '.escapeshellarg($dev).' 2>/dev/null');
	$r = [];
	foreach (explode("\n", $out) as $line) {
		if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) $r[$m[1]] = $m[2];
	}
	return $r;
}

/* Scan plus live state, the shape the UI and `ldmctl status` show. */
function ldm_status(?array $devices = null): array
{
	$scan = ldm_scan($devices);
	$dm = ldm_dm_devices();
	foreach ($scan['groups'] as &$g) {
		foreach ($g['volumes'] as &$v) {
			$v['assembled'] = isset($dm[$v['dm_name']]);
			$v['dm_readonly'] = $dm[$v['dm_name']]['readonly'] ?? null;
			$v['mount'] = $v['assembled'] ? ldm_mount_of($v['dm_name']) : null;
			$v['fs'] = ldm_volume_fs($v);
			$v['share'] = ldm_share_state($v);
		}
		unset($v);
	}
	unset($g);
	$scan['smb_users'] = ldm_smb_users();
	$scan['smb_enabled'] = ldm_smb_enabled();
	$scan['server'] = ldm_emhttp_var('NAME') ?: gethostname();
	return $scan;
}

const LDM_FS_CACHE = '/tmp/'.LDM_PLUGIN.'/fs-cache.json';

/* Filesystem type and label. Read from the assembled device when there is
 * one. Otherwise map the volume read-only under a temporary name just long
 * enough for blkid (an NTFS label can sit past the first extent), and cache
 * the answer per volume layout so repeated status calls don't do it again. */
function ldm_volume_fs(array $v): array
{
	if ($v['assembled'] ?? false) return ldm_fs_from(ldm_blkid("/dev/mapper/{$v['dm_name']}"));
	if ($v['table'] === null) return ldm_fs_from([]);

	$key = $v['guid'].':'.md5($v['table']);
	$cache = json_decode((string)@file_get_contents(LDM_FS_CACHE), true) ?: [];
	if (isset($cache[$key])) return $cache[$key];

	$name = 'ldm_probe_'.substr(md5($key), 0, 12);
	[$rc] = ldm_run('dmsetup create '.escapeshellarg($name).' --readonly', $v['table']."\n");
	if ($rc != 0) return ldm_fs_from([]);
	$fs = ldm_fs_from(ldm_blkid("/dev/mapper/$name"));
	ldm_run('dmsetup remove '.escapeshellarg($name));

	$cache[$key] = $fs;
	@mkdir(dirname(LDM_FS_CACHE), 0755, true);
	@file_put_contents(LDM_FS_CACHE, json_encode($cache));
	return $fs;
}

function ldm_fs_from(array $id): array
{
	return ['type' => $id['TYPE'] ?? null, 'label' => $id['LABEL'] ?? null, 'uuid' => $id['UUID'] ?? null];
}

/* ---------------------------------------------------------------------------
 * Actions
 */

function ldm_find_volume(array $status, string $ref): array
{
	$hits = [];
	foreach ($status['groups'] as $g) {
		foreach ($g['volumes'] as $v) {
			if (in_array($ref, [$v['guid'], $v['dm_name'], "{$v['group']}/{$v['name']}"], true)
				|| ($v['fs']['label'] !== null && strcasecmp($v['fs']['label'], $ref) == 0)
				|| (count($status['groups']) == 1 && $ref === $v['name'])) {
				$hits[$v['guid']] = $v;
			}
		}
	}
	if (count($hits) == 0) throw new LdmException("no LDM volume matches '$ref'");
	if (count($hits) > 1) throw new LdmException("'$ref' matches more than one volume; use the volume GUID");
	return array_values($hits)[0];
}

function ldm_assemble(array $v, bool $rw = false): void
{
	if ($v['table'] === null) throw new LdmException("{$v['group']}/{$v['name']}: ".($v['problem'] ?? 'cannot be assembled'));
	if ($rw && $v['readonly_only']) throw new LdmException("{$v['type']} volumes can only be opened read-only");
	$dm = ldm_dm_devices();
	if (isset($dm[$v['dm_name']])) {
		if ($rw && $dm[$v['dm_name']]['readonly']) {
			if (ldm_mount_of($v['dm_name'])) throw new LdmException("{$v['dm_name']} is mounted read-only; unmount it first");
			ldm_remove($v);
		} else {
			return;
		}
	}
	foreach ($v['members'] as $m) {
		if ($m['device'] !== null) ldm_check_member_free($m['device']);
	}
	$cmd = 'dmsetup create '.escapeshellarg($v['dm_name']).' --uuid '.escapeshellarg($v['dm_uuid']).($rw ? '' : ' --readonly');
	[$rc, $out, $err] = ldm_run($cmd, $v['table']."\n");
	if ($rc != 0) throw new LdmException("dmsetup create failed: $err");
	ldm_run('udevadm settle --timeout=10');
	ldm_log("assembled {$v['type']} volume {$v['group']}/{$v['name']} as /dev/mapper/{$v['dm_name']} (".($rw ? 'rw' : 'ro').')');
}

/* Refuse to map a disk that something else has mounted or claimed. */
function ldm_check_member_free(string $dev): void
{
	$base = basename($dev);
	$held = [];
	foreach (glob("/sys/class/block/$base/holders/*") ?: [] as $h) $held[] = basename($h);
	foreach (glob("/sys/class/block/$base/$base*/holders/*") ?: [] as $h) $held[] = basename($h);
	foreach ($held as $h) {
		$uuid = @file_get_contents("/sys/class/block/$h/dm/uuid");
		if ($uuid !== false && str_starts_with(trim($uuid), LDM_DM_UUID_PREFIX)) continue;
		throw new LdmException("$dev is in use by $h");
	}
	foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
		$src = explode(' ', $line)[0];
		if ($src === $dev || preg_match('#^'.preg_quote($dev, '#').'p?\d+$#', $src)) {
			throw new LdmException("$src is mounted");
		}
	}
}

function ldm_remove(array $v): void
{
	$dm = ldm_dm_devices();
	if (!isset($dm[$v['dm_name']])) return;
	if ($m = ldm_mount_of($v['dm_name'])) throw new LdmException("{$v['dm_name']} is mounted at {$m['mountpoint']}");
	[$rc, $out, $err] = ldm_run('dmsetup remove '.escapeshellarg($v['dm_name']));
	if ($rc != 0) throw new LdmException("dmsetup remove failed: $err");
	ldm_log("removed /dev/mapper/{$v['dm_name']}");
}

function ldm_mountpoint_for(array $v): string
{
	$base = $v['fs']['label'] ?: $v['group'].'_'.$v['name'];
	$base = trim(preg_replace('/[^A-Za-z0-9 ._+-]/', '_', $base), ' .');
	if ($base === '') $base = $v['dm_name'];
	$mp = LDM_MOUNT_ROOT.'/'.$base;
	for ($i = 2; file_exists($mp) && (!is_dir($mp) || count(scandir($mp)) > 2 || ldm_is_mountpoint($mp)); $i++) {
		$mp = LDM_MOUNT_ROOT."/{$base}_$i";
	}
	return $mp;
}

function ldm_is_mountpoint(string $dir): bool
{
	foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
		if (stripcslashes(explode(' ', $line)[1]) === $dir) return true;
	}
	return false;
}

/* Mount options per filesystem, in the style Unassigned Devices uses. */
function ldm_mount_commands(string $fstype, bool $rw): array
{
	$mode = $rw ? 'rw' : 'ro';
	$common = "$mode,relatime,nodev,nosuid";
	switch ($fstype) {
	case 'ntfs':
		// Read-only: the kernel ntfs3 driver, falling back to ntfs-3g.
		// Read-write: ntfs-3g, which is what Unassigned Devices uses by default.
		$cmds = [['ntfs-3g', "$common,nls=utf8,umask=000"]];
		if (!$rw) array_unshift($cmds, ['ntfs3', "$common,umask=000"]);
		return $cmds;
	case 'exfat':
		return [['exfat', "$common,umask=000"]];
	case 'vfat':
		return [['vfat', "$common,iocharset=utf8,umask=000"]];
	case 'refs':
		throw new LdmException('ReFS cannot be read on Linux');
	default:
		return [[$fstype, $common]];
	}
}

function ldm_mount(array $v, bool $rw = false): string
{
	if ($m = ldm_mount_of($v['dm_name'])) {
		if ($m['readonly'] == !$rw) return $m['mountpoint'];
		throw new LdmException("already mounted ".($m['readonly'] ? 'read-only' : 'read-write')." at {$m['mountpoint']}");
	}
	ldm_assemble($v, $rw);
	$dev = "/dev/mapper/{$v['dm_name']}";
	$id = ldm_blkid($dev);
	$fstype = $id['TYPE'] ?? null;
	if (!$fstype) throw new LdmException("no filesystem found on $dev");
	$v['fs']['label'] = $id['LABEL'] ?? null;

	$mp = ldm_mountpoint_for($v);
	if (!is_dir(LDM_MOUNT_ROOT)) @mkdir(LDM_MOUNT_ROOT, 0755, true);
	if (!is_dir($mp) && !@mkdir($mp, 0777, true)) throw new LdmException("cannot create $mp");

	$errs = [];
	foreach (ldm_mount_commands($fstype, $rw) as [$t, $opts]) {
		[$rc, $out, $err] = ldm_run('mount -t '.escapeshellarg($t).' -o '.escapeshellarg($opts).' '.escapeshellarg($dev).' '.escapeshellarg($mp));
		if ($rc == 0) {
			ldm_log("mounted $dev ($t, ".($rw ? 'rw' : 'ro').") at $mp");
			try {
				ldm_share_apply($v);
			} catch (LdmException $e) {
				ldm_log("not sharing $mp: ".$e->getMessage());
			}
			return $mp;
		}
		$errs[] = "$t: ".($err ?: $out ?: "exit $rc");
	}
	@rmdir($mp);
	throw new LdmException("mount failed: ".implode('; ', $errs));
}

function ldm_unmount(array $v): void
{
	ldm_share_remove($v['dm_name']);
	$m = ldm_mount_of($v['dm_name']);
	if ($m) {
		[$rc, $out, $err] = ldm_run('umount '.escapeshellarg($m['mountpoint']));
		if ($rc != 0) throw new LdmException("umount failed: ".($err ?: $out));
		@rmdir($m['mountpoint']);
		ldm_log("unmounted {$m['mountpoint']}");
	}
	ldm_remove($v);
}

/* Unmount and remove every LDM device-mapper device, whether or not its
 * disks are still present. Returns the errors. */
function ldm_teardown_all(): array
{
	$errs = [];
	foreach (array_keys(ldm_dm_devices()) as $name) {
		try {
			ldm_unmount(['dm_name' => $name]);
		} catch (LdmException $e) {
			$errs[] = "$name: ".$e->getMessage();
		}
	}
	return $errs;
}

/* ---------------------------------------------------------------------------
 * SMB sharing
 *
 * Settings live on the flash drive per volume GUID, so a volume keeps its
 * share settings across reboots and remounts. A share exists only while its
 * volume is mounted. Each one is a file in /etc/samba/ldm-unraid included
 * from /etc/samba/smb-unassigned.conf, which Unraid's smb.conf includes.
 * Unassigned Devices rewrites that file too, but keeps every include line
 * whose file exists.
 */

const LDM_CFG_DIR = '/boot/config/plugins/'.LDM_PLUGIN;
const LDM_SHARES_CFG = LDM_CFG_DIR.'/shares.json';
const LDM_SMB_DIR = '/etc/samba/'.LDM_PLUGIN;
const LDM_SMB_INCLUDES = '/etc/samba/smb-unassigned.conf';

function ldm_emhttp_var(string $key): ?string
{
	$var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
	return $var[$key] ?? null;
}

function ldm_smb_enabled(): bool
{
	return ldm_emhttp_var('shareSMBEnabled') !== 'no';
}

/* Unraid user accounts that can be given share access (root never can). */
function ldm_smb_users(): array
{
	$users = @parse_ini_file('/var/local/emhttp/users.ini', true) ?: [];
	return array_values(array_filter(array_keys($users), fn($u) => $u !== 'root'));
}

function ldm_shares_cfg(): array
{
	return json_decode((string)@file_get_contents(LDM_SHARES_CFG), true) ?: [];
}

/* Validate and store the share settings of one volume. */
function ldm_share_configure(array $v, bool $enabled, string $security, array $users): array
{
	if (!in_array($security, ['public', 'private'])) throw new LdmException("unknown share security '$security'");
	$known = ldm_smb_users();
	$access = [];
	foreach ($users as $u => $a) {
		if (!in_array($u, $known, true)) throw new LdmException("unknown user '$u'");
		if (!in_array($a, ['no', 'read-only', 'read-write'])) throw new LdmException("unknown access '$a' for $u");
		if ($a !== 'no') $access[$u] = $a;
	}
	$cfg = ldm_shares_cfg();
	$cfg[$v['guid']] = ['enabled' => $enabled, 'security' => $security, 'users' => $access];
	@mkdir(LDM_CFG_DIR, 0755, true);
	if (@file_put_contents(LDM_SHARES_CFG, json_encode($cfg, JSON_PRETTY_PRINT)."\n") === false) {
		throw new LdmException('cannot write '.LDM_SHARES_CFG);
	}
	ldm_share_apply($v);
	return $cfg[$v['guid']];
}

function ldm_share_file(string $dmname): string
{
	return LDM_SMB_DIR."/$dmname.conf";
}

/* Share name of an active share, read back from its file. */
function ldm_share_active(string $dmname): ?string
{
	$conf = @file_get_contents(ldm_share_file($dmname));
	return ($conf !== false && preg_match('/^\[([^\]]+)\]/', $conf, $m)) ? $m[1] : null;
}

function ldm_share_state(array $v): array
{
	$c = ldm_shares_cfg()[$v['guid']] ?? [];
	return [
		'enabled'  => $c['enabled'] ?? false,
		'security' => $c['security'] ?? 'public',
		'users'    => (object)($c['users'] ?? []),
		'name'     => ldm_share_active($v['dm_name']),
	];
}

/* Bring the share in line with the settings and the mount state. */
function ldm_share_apply(array $v): void
{
	$c = ldm_shares_cfg()[$v['guid']] ?? null;
	$m = ldm_mount_of($v['dm_name']);
	if (!$c || !$c['enabled'] || !$m) {
		ldm_share_remove($v['dm_name']);
		return;
	}
	if (!ldm_smb_enabled()) throw new LdmException('SMB is disabled in Settings, SMB');

	$name = ldm_share_name(basename($m['mountpoint']), $v['dm_name']);
	// The group and volume names come from the disk's LDM database. A newline in either
	// would start a new smb.conf line and a trailing backslash would continue onto the
	// path line, so control characters and backslashes are replaced first.
	$desc = preg_replace('/[\x00-\x1f\x7f\\\\]/', '_', "{$v['group']}/{$v['name']}");
	$lines = [
		"[$name]",
		"\tcomment = Windows dynamic disk $desc",
		"\tpath = {$m['mountpoint']}",
		"\tbrowseable = yes",
		"\tread only = ".($m['readonly'] ? 'Yes' : 'No'),
	];
	if ($c['security'] === 'public') {
		$lines[] = "\tguest ok = Yes";
	} else {
		$users = $c['users'] ?? [];
		if (!$users) {
			// Nobody has access: keep the share but let no one in.
			$lines[] = "\tinvalid users = @users";
		} else {
			$lines[] = "\tvalid users = ".implode(' ', array_keys($users));
			$rw = array_keys(array_filter($users, fn($a) => $a === 'read-write'));
			$ro = array_keys(array_filter($users, fn($a) => $a === 'read-only'));
			// A write list overrides "read only", so leave it out on read-only mounts.
			if ($rw && !$m['readonly']) $lines[] = "\twrite list = ".implode(' ', $rw);
			if ($ro && !$m['readonly']) $lines[] = "\tread list = ".implode(' ', $ro);
		}
	}
	if (ldm_emhttp_var('enableFruit') === 'yes') {
		$lines[] = "\tvfs objects = catia fruit streams_xattr";
		$lines[] = "\tfruit:encoding = native";
	}
	// Close the section so whatever smb.conf includes next stays global.
	$conf = implode("\n", $lines)."\n[global]\n";

	$file = ldm_share_file($v['dm_name']);
	@mkdir(LDM_SMB_DIR, 0755, true);
	if (@file_get_contents($file) === $conf && ldm_smb_include_present($file)) return;
	file_put_contents($file, $conf);
	ldm_smb_include($file, true);
	ldm_run('smbcontrol all reload-config');
	ldm_log("sharing {$m['mountpoint']} as SMB share '$name' (".($c['security'] === 'public' ? 'public' : 'private').', '.($m['readonly'] ? 'read-only' : 'read-write').')');
}

function ldm_share_remove(string $dmname): void
{
	$name = ldm_share_active($dmname);
	$file = ldm_share_file($dmname);
	if (!is_file($file)) return;
	ldm_smb_include($file, false);
	@unlink($file);
	ldm_run('smbcontrol all reload-config');
	if ($name !== null) {
		ldm_run('smbcontrol all close-share '.escapeshellarg($name));
		ldm_log("stopped sharing '$name'");
	}
}

/* The mount point's name, unless another share already uses it. */
function ldm_share_name(string $want, string $dmname): string
{
	$taken = [];
	$own = realpath(ldm_share_file($dmname));
	$files = array_merge(['/etc/samba/smb-shares.conf', '/boot/config/smb-extra.conf'],
		glob('/etc/samba/unassigned-shares/*.conf') ?: [], glob(LDM_SMB_DIR.'/*.conf') ?: []);
	foreach ($files as $f) {
		if (realpath($f) === $own) continue;
		foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m)) $taken[] = strtolower($m[1]);
		}
	}
	foreach (glob('/mnt/user/*', GLOB_ONLYDIR) ?: [] as $d) $taken[] = strtolower(basename($d));
	$name = $want;
	for ($i = 2; in_array(strtolower($name), $taken) || in_array(strtolower($name), ['global', 'homes', 'printers']); $i++) {
		$name = "{$want}_$i";
	}
	return $name;
}

function ldm_smb_include_present(string $file): bool
{
	return in_array("include = $file", @file(LDM_SMB_INCLUDES, FILE_IGNORE_NEW_LINES) ?: []);
}

function ldm_smb_include(string $file, bool $add): void
{
	$line = "include = $file";
	$lines = @file(LDM_SMB_INCLUDES, FILE_IGNORE_NEW_LINES) ?: [];
	$lines = array_values(array_filter($lines, fn($l) => trim($l) !== $line));
	if ($add) $lines[] = $line;
	file_put_contents(LDM_SMB_INCLUDES, $lines ? implode("\n", $lines)."\n" : '');
}
