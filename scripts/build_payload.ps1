<#
.SYNOPSIS
    Downloads, extracts, configures, prunes, and packages the 64-bit WNMP runtime into payload.zip.

.DESCRIPTION
    Builds the production-ready payload.zip for Moodle Portable:
    - Moodle 5.0.11 (with pruning via prune_moodle.ps1)
    - PHP 8.2.12 x64 (Win32-vs16-x64) + pre-configured php.ini for Moodle
    - Nginx 1.31.7
    - MariaDB 13.0.2 x64 (winx64)
    - Isolated moodledata directory

.PARAMETER CacheDir
    Directory where downloaded archives are cached to avoid re-downloading.

.PARAMETER StagingDir
    Temporary workspace for unpacking and configuring components.

.PARAMETER OutputZip
    Destination file path for payload.zip (defaults to ./payload.zip).
#>
[CmdletBinding()]
param (
    [string]$CacheDir = "./.cache_downloads",
    [string]$StagingDir = "./staging_payload",
    [string]$OutputZip = "./payload.zip",
    [switch]$SkipDownload
)

$ErrorActionPreference = "Stop"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  Moodle Portable 64-bit Payload Builder" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# Resolve absolute paths
$ProjectRoot = (Get-Item $PSScriptRoot).Parent.FullName
$CachePath = Join-Path $ProjectRoot $CacheDir
$StagingPath = Join-Path $ProjectRoot $StagingDir
$OutputPath = Join-Path $ProjectRoot $OutputZip

if (!(Test-Path $CachePath)) {
    New-Item -ItemType Directory -Path $CachePath -Force | Out-Null
}

$Downloads = @{
    "moodle" = @{
        Url = "https://download.moodle.org/download.php/direct/stable500/moodle-5.0.11.zip"
        File = Join-Path $CachePath "moodle-5.0.11.zip"
        MinSize = 50MB
    }
    "php" = @{
        Url = "https://windows.php.net/downloads/releases/archives/php-8.2.12-Win32-vs16-x64.zip"
        File = Join-Path $CachePath "php-8.2.12-Win32-vs16-x64.zip"
        MinSize = 20MB
    }
    "nginx" = @{
        Url = "https://nginx.org/download/nginx-1.31.7.zip"
        File = Join-Path $CachePath "nginx-1.31.7.zip"
        MinSize = 1MB
    }
    "mariadb" = @{
        Url = "https://linux.domainesia.com/mariadb///mariadb-13.0.2/winx64-packages/mariadb-13.0.2-winx64.zip"
        File = Join-Path $CachePath "mariadb-13.0.2-winx64.zip"
        MinSize = 50MB
    }
}

# --- 1. DOWNLOAD PHASE ---
Write-Host "`n[1/5] Verifying and Downloading Components..." -ForegroundColor Yellow
foreach ($key in $Downloads.Keys) {
    $info = $Downloads[$key]
    $dest = $info.File
    $url = $info.Url
    $min = $info.MinSize

    $needsDownload = $true
    if (Test-Path $dest) {
        $size = (Get-Item $dest).Length
        if ($size -ge $min) {
            Write-Host "  [OK] Cached $key exists ($([math]::Round($size/1MB, 2)) MB)" -ForegroundColor Green
            $needsDownload = $false
        } else {
            Write-Warning "  [WARN] Cached $key file is too small ($size bytes). Re-downloading..."
        }
    }

    if ($needsDownload) {
        if ($SkipDownload) {
            throw "File $dest not found and -SkipDownload was specified!"
        }
        Write-Host "  -> Downloading $key from: $url" -ForegroundColor White
        curl.exe -fL --retry 3 --retry-delay 5 --progress-bar -o $dest $url
        $size = (Get-Item $dest).Length
        if ($size -lt $min) {
            throw "Downloaded $key file is smaller than expected ($size bytes)."
        }
        Write-Host "  -> $key downloaded successfully ($([math]::Round($size/1MB, 2)) MB)" -ForegroundColor Green
    }
}

# --- 2. PREPARE STAGING DIRECTORY ---
Write-Host "`n[2/5] Preparing Staging Directory: $StagingPath..." -ForegroundColor Yellow
if (Test-Path $StagingPath) {
    Write-Host "  Cleaning existing staging directory..."
    Remove-Item -Path $StagingPath -Recurse -Force
}
New-Item -ItemType Directory -Path $StagingPath -Force | Out-Null

$MoodleStaging = Join-Path $StagingPath "moodle"
$PhpStaging = Join-Path $StagingPath "php"
$NginxStaging = Join-Path $StagingPath "nginx"
$MariaDbStaging = Join-Path $StagingPath "mariadb"
$MoodledataStaging = Join-Path $StagingPath "moodledata"

# Create moodledata placeholder
New-Item -ItemType Directory -Path $MoodledataStaging -Force | Out-Null
Set-Content -Path (Join-Path $MoodledataStaging ".gitkeep") -Value "" -Encoding utf8

# --- 3. UNPACK AND CONFIGURE COMPONENTS ---
Write-Host "`n[3/5] Extracting & Configuring Components..." -ForegroundColor Yellow

# 3.1 Nginx
Write-Host "  -> Extracting Nginx..." -ForegroundColor White
$tempNginx = Join-Path $StagingPath "temp_nginx"
Expand-Archive -Path $Downloads["nginx"].File -DestinationPath $tempNginx -Force
$nginxFolder = Get-ChildItem -Path $tempNginx -Directory | Select-Object -First 1
Move-Item -Path $nginxFolder.FullName -Destination $NginxStaging
Remove-Item -Path $tempNginx -Recurse -Force
Write-Host "     Nginx extracted to: $NginxStaging" -ForegroundColor Green

# 3.2 PHP 8.2 x64
Write-Host "  -> Extracting PHP 8.2 x64..." -ForegroundColor White
New-Item -ItemType Directory -Path $PhpStaging -Force | Out-Null
Expand-Archive -Path $Downloads["php"].File -DestinationPath $PhpStaging -Force

# Generate php.ini configured for Moodle
Write-Host "  -> Generating optimized php.ini for Moodle..." -ForegroundColor White
$phpIniContent = @"
[PHP]
engine = On
short_open_tag = Off
precision = 14
output_buffering = 4096
zlib.output_compression = Off
implicit_flush = Off
serialize_precision = -1
zend.enable_gc = On
zend.multibyte = Off

max_execution_time = 300
max_input_time = 120
max_input_vars = 5000
memory_limit = 512M

error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
display_errors = Off
display_startup_errors = Off
log_errors = On
log_errors_max_len = 1024
ignore_repeated_errors = Off
ignore_repeated_source = Off
report_memleaks = On

post_max_size = 128M
default_mimetype = "text/html"
default_charset = "UTF-8"

enable_dl = Off
file_uploads = On
upload_max_filesize = 128M
max_file_uploads = 20

allow_url_fopen = On
allow_url_include = Off
default_socket_timeout = 60

extension_dir = "ext"

; Required extensions for Moodle
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=exif
extension=mysqli
extension=openssl
extension=pdo_mysql
extension=soap
extension=sodium
extension=zip

[Date]
date.timezone = UTC

[opcache]
zend_extension=opcache
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 60
opcache.fast_shutdown = 1
"@
Set-Content -Path (Join-Path $PhpStaging "php.ini") -Value $phpIniContent -Encoding utf8
Write-Host "     PHP 8.2 configured with Moodle requirements." -ForegroundColor Green

# 3.3 MariaDB 13.0.2 x64
Write-Host "  -> Extracting MariaDB 13.0.2 x64..." -ForegroundColor White
$tempMaria = Join-Path $StagingPath "temp_mariadb"
Expand-Archive -Path $Downloads["mariadb"].File -DestinationPath $tempMaria -Force
$mariaFolder = Get-ChildItem -Path $tempMaria -Directory | Select-Object -First 1
Move-Item -Path $mariaFolder.FullName -Destination $MariaDbStaging
Remove-Item -Path $tempMaria -Recurse -Force

# Prune non-essential MariaDB files (debug symbols, tests, sql-bench) to save space
Write-Host "  -> Pruning non-essential MariaDB directories (sql-bench, mysql-test)..." -ForegroundColor White
$mariaPruneTargets = @("sql-bench", "mysql-test", "docs", "man")
foreach ($target in $mariaPruneTargets) {
    $targetPath = Join-Path $MariaDbStaging $target
    if (Test-Path $targetPath) {
        Remove-Item -Path $targetPath -Recurse -Force -ErrorAction SilentlyContinue
    }
}
Write-Host "     MariaDB extracted and optimized." -ForegroundColor Green

# 3.4 Moodle 5.0.11
Write-Host "  -> Extracting Moodle 5.0.11..." -ForegroundColor White
$tempMoodle = Join-Path $StagingPath "temp_moodle"
Expand-Archive -Path $Downloads["moodle"].File -DestinationPath $tempMoodle -Force
$moodleFolder = Join-Path $tempMoodle "moodle"
if (Test-Path $moodleFolder) {
    Move-Item -Path $moodleFolder -Destination $MoodleStaging
} else {
    Move-Item -Path $tempMoodle -Destination $MoodleStaging
}
if (Test-Path $tempMoodle) {
    Remove-Item -Path $tempMoodle -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Host "     Moodle extracted." -ForegroundColor Green

# --- 4. PRUNE MOODLE (NFR-03) ---
Write-Host "`n[4/5] Pruning Moodle Core (scripts/prune_moodle.ps1)..." -ForegroundColor Yellow
$pruneScript = Join-Path $ProjectRoot "scripts/prune_moodle.ps1"
if (Test-Path $pruneScript) {
    & $pruneScript -MoodlePath $MoodleStaging
} else {
    Write-Warning "Prune script not found at $pruneScript, skipping Moodle pruning."
}

# --- 5. COMPRESS INTO PAYLOAD.ZIP ---
Write-Host "`n[5/5] Compressing Staging into $OutputPath..." -ForegroundColor Yellow
if (Test-Path $OutputPath) {
    Remove-Item -Path $OutputPath -Force
}

# Compress staging directory contents directly into payload.zip
# We use System.IO.Compression to ensure relative paths inside zip match:
# moodle/..., php/..., nginx/..., mariadb/..., moodledata/...
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $StagingPath,
    $OutputPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false
)

$finalZipSize = (Get-Item $OutputPath).Length
$finalZipMB = [math]::Round($finalZipSize / 1MB, 2)
Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "  SUCCESS: payload.zip generated!" -ForegroundColor Green
Write-Host "  Output File: $OutputPath" -ForegroundColor White
Write-Host "  Final Size:  $finalZipMB MB ($finalZipSize bytes)" -ForegroundColor White
Write-Host "==========================================================" -ForegroundColor Green
