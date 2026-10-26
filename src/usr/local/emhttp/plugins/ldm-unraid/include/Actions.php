<?php
/* AJAX endpoint for the Windows Dynamic Disks section on the Main page.
 * GET  action=status                       -> scan and live state
 * POST action=mount|unmount, volume=<guid>  -> change state (mode=ro|rw for mount)
 * POST bodies are form-encoded, so the CSRF token the webGui appends arrives
 * in $_POST like any other field. */

require_once __DIR__.'/LDM.php';

header('Content-Type: application/json');

function reply(array $r): never
{
	echo json_encode($r, JSON_UNESCAPED_SLASHES);
	exit;
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $post ? ($_POST['action'] ?? '') : ($_GET['action'] ?? 'status');

try {
	if (!$post) {
		if ($action !== 'status') reply(['ok' => false, 'error' => "unknown action $action"]);
		reply(['ok' => true] + ldm_status());
	}

	$ref = (string)($_POST['volume'] ?? '');
	if ($ref === '') reply(['ok' => false, 'error' => 'no volume given']);
	$v = ldm_find_volume(ldm_status(), $ref);

	switch ($action) {
	case 'mount':
		$mp = ldm_mount($v, ($_POST['mode'] ?? 'ro') === 'rw');
		reply(['ok' => true, 'mountpoint' => $mp]);
	case 'unmount':
		ldm_unmount($v);
		reply(['ok' => true]);
	default:
		reply(['ok' => false, 'error' => "unknown action $action"]);
	}
} catch (LdmException $e) {
	reply(['ok' => false, 'error' => $e->getMessage()]);
}
