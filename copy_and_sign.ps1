$source = "D:\Project\moodle_installer\target\x86_64-pc-windows-msvc\release\aksaventra_engine.exe"
$dest = "D:\Project\moodle_installer\aksaventra_engine.exe"
$releasesDest = "D:\Project\aksaventra-releases\aksaventra_engine.exe"

Copy-Item -Path $source -Destination $dest -Force
$cert = Get-Item "Cert:\CurrentUser\My\DFBD32D704EFDB5195CCFCD369F5E2D85B8F86C6"
Set-AuthenticodeSignature -Certificate $cert -FilePath $dest -HashAlgorithm SHA256
Copy-Item -Path $dest -Destination $releasesDest -Force
Write-Host "[OK] aksaventra_engine.exe successfully copied, signed, and updated in releases!"
