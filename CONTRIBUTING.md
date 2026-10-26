# Contributing to ldm-unraid

Thanks for helping. Questions before you start are welcome in a GitHub issue.

ldm-unraid is an Unraid plugin that reads Windows dynamic disks. It is plain PHP and shell: `include/LDM.php` parses the LDM database and drives `dmsetup`, `mount` and Samba; `scripts/ldmctl` is the command-line tool; `WindowsDynamicDisks.page` and `include/Actions.php` are the Main page section and its endpoint. There are no compiled parts and no build dependencies beyond `tar` and `xz`.

## What helps most

- **Disks it reads wrong, or not at all.** Open an issue with the output of `ldmctl status --json` and `ldmctl table <volume>`, your Unraid version, and what Windows showed for the volume in Disk Management (type, size, which disks). If you can, attach the LDM database: it is the last 1 MiB of an MBR dynamic disk, or the "LDM metadata partition" of a GPT one, and holds no file data.
- **Volume types not covered yet.** RAID-5 needs dm-raid, which Unraid's kernel doesn't ship. Disks with 4K logical sectors are handled in code but untested.
- **Code.** Ask first for anything large.

## Testing

Run `php -l` on every PHP file and `ldmctl` (CI does this too, plus `xmllint` on the `.plg`).

Behaviour is tested against volumes made by Windows itself. `tests/windows/mk.ps1` creates them with `diskpart` on four throwaway virtual disks (it refuses any disk that isn't a small VirtIO disk with an `LDMTEST*` serial), and `tests/windows/fill.ps1` fills them and records SHA-256 hashes. Copy the disk images to an Unraid box with the plugin installed and run `tests/verify.sh <image dir> tests/windows/hashes.txt`. Attach all four disks to Windows and shut it down before copying the images: unplugging some of them while Windows runs makes it write "missing disk" records into the others.

## Rules

- Read-only stays the default everywhere. A change that can write to a member disk needs an explicit user choice in front of it.
- Never map a disk that is an array or pool member, or that something else has mounted or claimed.
- Don't read a disk just to find out whether it is LDM; use udev's data, so spun-down disks stay asleep.
- The `.plg` is XML served to every user from `main`. A bare `&` or `<` anywhere in it, changelog included, breaks every install and update.
- Don't bump the version, tag, or edit the `.plg` `version` and `md5` entities. The release workflow owns them.
- Commit messages say what changed and why in plain sentences. No em dashes in prose, comments or messages.
