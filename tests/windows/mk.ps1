$ErrorActionPreference = 'Stop'
function N($s) {
  $d = @(Get-Disk | Where-Object SerialNumber -eq $s)
  if ($d.Count -ne 1 -or $d[0].FriendlyName -ne 'Red Hat VirtIO' -or $d[0].Size -gt 1GB -or $d[0].IsBoot -or $d[0].IsSystem) { throw "refusing: $s" }
  $d[0].Number
}
$a1 = N LDMTESTA1; $a2 = N LDMTESTA2; $b1 = N LDMTESTB1; $b2 = N LDMTESTB2
"A1=$a1 A2=$a2 B1=$b1 B2=$b2"
foreach ($n in $a1, $a2) { if ((Get-Disk -Number $n).PartitionStyle -eq 'RAW') { Initialize-Disk -Number $n -PartitionStyle GPT } }
foreach ($n in $b1, $b2) { if ((Get-Disk -Number $n).PartitionStyle -eq 'RAW') { Initialize-Disk -Number $n -PartitionStyle MBR } }
$dp = @"
select disk $a1
convert dynamic
select disk $a2
convert dynamic
select disk $b1
convert dynamic
select disk $b2
convert dynamic
create volume simple size=40 disk=$a1
format fs=ntfs label=SIMPLEA quick
assign letter=P
create volume stripe size=40 disk=$a1,$a2
format fs=ntfs label=STRIPEA quick
assign letter=Q
create volume simple size=50 disk=$a2
extend size=60 disk=$a1
extend size=40 disk=$a2
format fs=ntfs label=SPANA quick
assign letter=R
create volume mirror size=60 disk=$b1,$b2
format fs=ntfs label=MIRRORB quick
assign letter=S
create volume simple size=80 disk=$b2
extend size=90 disk=$b1
format fs=exfat label=SPANB quick
assign letter=T
list disk
list volume
"@
$f = "$env:TEMP\ldm.txt"; $dp | Set-Content -Encoding ascii $f
diskpart /s $f
