$ErrorActionPreference = 'Stop'
$out = @()
foreach ($l in 'P','Q','R','S','T') {
  $v = Get-Volume -DriveLetter $l
  $free = $v.SizeRemaining
  $sz = [int64]([math]::Floor($free * 0.85 / 1MB) * 1MB)
  $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
  $buf = New-Object byte[] (1MB)
  $fs = [IO.File]::OpenWrite("${l}:\big.bin")
  for ($i = 0; $i -lt $sz / 1MB; $i++) { $rng.GetBytes($buf); $fs.Write($buf, 0, $buf.Length) }
  $fs.Close()
  Set-Content -Path "${l}:\hello.txt" -Value "volume $l $($v.FileSystemLabel)"
  $h = (Get-FileHash "${l}:\big.bin" -Algorithm SHA256).Hash.ToLower()
  $out += "$($v.FileSystemLabel) $($v.FileSystem) $($v.Size) $sz $h"
}
$out
Write-VolumeCache P; Write-VolumeCache Q; Write-VolumeCache R; Write-VolumeCache S; Write-VolumeCache T
