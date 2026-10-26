#!/bin/bash
# End-to-end check on an Unraid box with the plugin installed.
# Usage: tests/verify.sh <dir with ldmA1.img ldmA2.img ldmB1.img ldmB2.img> <hashes.txt>
# The images come from tests/windows/mk.ps1 + fill.ps1 run in a Windows VM;
# hashes.txt is fill.ps1's output (label fs size filesize sha256).
set -euo pipefail
IMG=$1; HASHES=$2
loops=()
cleanup() { ldmctl unmount-all || true; for l in "${loops[@]}"; do losetup -d "$l"; done; }
trap cleanup EXIT
for n in A1 A2 B1 B2; do loops+=("$(losetup -f --show -P "$IMG/ldm$n.img")"); done
udevadm settle
ldmctl status
fail=0
while read -r label fs size fsize sha; do
	sha=${sha%$'\r'}
	mp=$(ldmctl mount "$label")
	got=$(sha256sum "$mp/big.bin" | cut -d' ' -f1)
	if [ "$got" = "$sha" ]; then echo "ok   $label ($fs) $mp"; else echo "FAIL $label: $got != $sha"; fail=1; fi
	ldmctl unmount "$label"
done < "$HASHES"
exit $fail
