**Windows Dynamic Disks** reads volumes from disks that Windows converted to dynamic (LDM): simple, spanned, striped and mirrored volumes on MBR or GPT disks.

Volumes are assembled with device-mapper and mounted under /mnt/disks, read-only by default, and can be shared over SMB. Nothing is written to the disks unless you choose Mount R/W.

Find them on the Main page under Windows Dynamic Disks, or run `ldmctl status`.
