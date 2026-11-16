$files = @(
    "$PSScriptRoot\aksaventra_engine.exe",
    "$PSScriptRoot\aksaventra_vault.pak",
    "$PSScriptRoot\aksaventra.cer",
    "$PSScriptRoot\Buka_Aksaventra.bat",
    "$PSScriptRoot\Izinkan_SmartScreen.bat"
)
Compress-Archive -Path $files -DestinationPath "$PSScriptRoot\aksaventra-portable-v2.2.4-windows-x64.zip" -Force -CompressionLevel Fastest
Write-Output "ZIP created: $((Get-Item "$PSScriptRoot\aksaventra-portable-v2.2.4-windows-x64.zip").Length) bytes"

