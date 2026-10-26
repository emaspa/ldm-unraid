#!/bin/bash
# Build the ldm-unraid .txz package: ./build.sh <version>
set -euo pipefail

cd "$(dirname "$0")"
NAME=ldm-unraid
VERSION="${1:?usage: build.sh <version>}"
OUT="build/${NAME}-${VERSION}-x86_64-1.txz"

rm -rf build
mkdir -p build

# Files and symlinks only: a directory header for a shared parent such as
# usr/local/sbin would replace its ownership and mode on the host.
(cd src && find . ! -type d -printf '%P\0' | tar --null --no-recursion \
  --owner=root --group=root --numeric-owner -cJf "../$OUT" -T -)

md5sum "$OUT" | awk '{print $1}' > "$OUT.md5"
echo "$OUT  md5 $(cat "$OUT.md5")"
