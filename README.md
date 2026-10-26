# ldm-unraid

An Unraid plugin that reads Windows dynamic disks, the LDM format Windows uses for spanned, striped and mirrored volumes. It finds the disks, reads the LDM database Windows keeps on every member disk, rebuilds each volume with device-mapper, and mounts it under `/mnt/disks`.

Unraid can't do this on its own. Unassigned Devices only sees the raw LDM partitions, so a spanned or striped volume shows up as pieces it can't mount. Worse, the first piece can look like a whole NTFS volume when it isn't one.

## What works

| Volume type | Support |
|---|---|
| Simple | read-only or read-write |
| Spanned | read-only or read-write |
| Striped (RAID-0) | read-only or read-write |
| Mirrored (RAID-1) | read-only, from one complete copy; works with one disk missing |
| RAID-5 | not supported, Unraid's kernel has no dm-raid module |

- MBR and GPT dynamic disks, including disk groups that mix both.
- NTFS, exFAT and FAT32. NTFS mounts read-only with the kernel's `ntfs3` driver and read-write with `ntfs-3g`. Linux can't read ReFS.
- Mounts are read-only by default, and so is the device-mapper device underneath. Nothing reaches the disks unless you pick **Mount R/W**.

## Install

In the Unraid web UI go to **Plugins → Install Plugin** and paste:

```
https://raw.githubusercontent.com/emaspa/ldm-unraid/main/ldm-unraid.plg
```

It needs Unraid 7.0 or later and nothing else. The plugin is PHP plus the `dmsetup`, `blkid` and `mount` tools Unraid already ships.

## Use

Plug in **all** the disks of the volume. A **Windows Dynamic Disks** section then appears on the **Main** page, below Unassigned Devices, with **Mount** and **Mount R/W** buttons for each volume. A mounted volume lives at `/mnt/disks/<label>`, where Docker containers such as Krusader and the built-in file browser can reach it.

From the command line:

```
ldmctl status                  # disk groups, volumes, what's mounted
ldmctl mount Data              # by filesystem label, volume GUID, or GROUP/VolumeN
ldmctl mount Data --rw
ldmctl unmount Data
ldmctl unmount-all
```

To move the data into the array, mount read-only, copy from `/mnt/disks/<label>` to a share with your usual tool, then unmount. The plugin unmounts its volumes when the array stops.

## Sharing over SMB

Click the **SMB** cell of a volume to share it. Choose **Public** for guest access, or **Private** to give each Unraid user no access, read-only or read/write. The share takes the mount point's name, so SAM1 becomes `\\<server>\SAM1`, and gets a suffix if a user share or another share already has that name.

A share exists only while its volume is mounted, and a read-only mount always gives a read-only share. The settings are saved per volume on the flash drive, so the share comes back the next time you mount the volume. The same from the command line:

```
ldmctl share SAM1 public
ldmctl share SAM1 private alice=rw bob=ro
ldmctl share SAM1 off
```

The plugin registers shares the same way Unassigned Devices does, through `/etc/samba/smb-unassigned.conf`, so the two don't clash.

## Things to know

**Don't mount the pieces in Unassigned Devices.** Unraid's kernel splits an MBR dynamic disk into one partition per LDM extent, and the first extent of a spanned volume carries a valid-looking NTFS boot sector. A read-write mount of that piece will damage the volume.

**Shut Windows down cleanly first.** Fast Startup and hibernation leave NTFS dirty. Read-only mounts still work; `ntfs-3g` refuses read-write ones.

**Mirrors are read from one copy.** Without dm-raid nothing keeps two copies in sync, so mirrored volumes stay read-only.

**When the disks disagree**, the plugin trusts the database copy with the highest commit sequence. That's the one Windows wrote last.

Device names match `ldmtool`'s, `/dev/mapper/ldm_vol_<group>_<volume>`, so each tool recognises devices the other created.

## How it was tested

Windows 11 `diskpart` created the volumes on four virtual disks, two GPT and two MBR. There's a simple, a striped and a spanned NTFS volume, a mirror, and a spanned exFAT volume whose extents run in reverse disk order. `tests/windows/mk.ps1` builds them and `tests/windows/fill.ps1` fills each with random data and records SHA-256 hashes. On Unraid 7.3.4, `tests/verify.sh` attaches the images as loop devices, mounts every volume and compares the hashes. The device-mapper tables also match what `ldmtool` produces for the same images.

It has also run on real hardware: two Samsung 870 QVO 2TB SSDs that Windows had striped into one 4 TB NTFS volume, moved from a Windows PC into an Unraid 7.3.4 server. The plugin found the disk group, mounted the volume read-only with 1.5 TB of data, and shared it over SMB. Zip archives on it pass `unzip -t` both locally and after copying over SMB. Each archive alternates between the two disks every 64 KiB, so a wrong stripe order or offset would fail the CRC.

The code converts offsets on disks with 4K logical sectors to device-mapper's 512-byte sectors, but I haven't tested that against Windows-made images yet.

## Credits

The LDM parsing follows [libldm](https://github.com/mdbooth/libldm) by Matthew Booth and the Linux kernel's `block/partitions/ldm.c`.

## Licence

GPL-3.0-or-later.

## Support

If this saved your data, you can [buy me a coffee on Ko-fi](https://ko-fi.com/emaspa).
