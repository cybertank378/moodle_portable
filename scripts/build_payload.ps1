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

.PARAMETER OutputPak
    Destination file path for encrypted payload.pak container (defaults to ./payload.pak).
#>
[CmdletBinding()]
param (
    [string]$CacheDir = "./.cache_downloads",
    [string]$StagingDir = "./staging_payload",
    [string]$OutputZip = "./payload.zip",
    [string]$OutputPak = "./payload.pak",
    [switch]$SkipDownload,
    [switch]$SkipPak
)

$ErrorActionPreference = "Stop"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  Aksaventra Portable 64-bit Payload Builder" -ForegroundColor Cyan
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
    "moodle"  = @{
        Url     = "https://download.moodle.org/download.php/direct/stable500/moodle-5.0.11.zip"
        File    = Join-Path $CachePath "moodle-5.0.11.zip"
        MinSize = 50MB
    }
    "php"     = @{
        Url     = "https://windows.php.net/downloads/releases/archives/php-8.2.12-Win32-vs16-x64.zip"
        File    = Join-Path $CachePath "php-8.2.12-Win32-vs16-x64.zip"
        MinSize = 20MB
    }
    "nginx"   = @{
        Url     = "https://nginx.org/download/nginx-1.31.7.zip"
        File    = Join-Path $CachePath "nginx-1.31.7.zip"
        MinSize = 1MB
    }
    "mariadb" = @{
        Url     = "https://linux.domainesia.com/mariadb///mariadb-13.0.2/winx64-packages/mariadb-13.0.2-winx64.zip"
        File    = Join-Path $CachePath "mariadb-13.0.2-winx64.zip"
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
        }
        else {
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
    cmd.exe /c "rmdir /s /q `"$StagingPath`"" 2>$null
    if (Test-Path $StagingPath) {
        Start-Sleep -Milliseconds 600
        cmd.exe /c "rmdir /s /q `"$StagingPath`"" 2>$null
    }
    if (Test-Path $StagingPath) {
        Remove-Item -Path $StagingPath -Recurse -Force -ErrorAction SilentlyContinue
    }
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
$tempNginx = Join-Path $ProjectRoot ".temp_nginx_extract"
if (Test-Path $tempNginx) {
    cmd.exe /c "rmdir /s /q `"$tempNginx`"" 2>$null
    Remove-Item -Path $tempNginx -Recurse -Force -ErrorAction SilentlyContinue
}
Expand-Archive -Path $Downloads["nginx"].File -DestinationPath $tempNginx -Force
$nginxFolder = Get-ChildItem -Path $tempNginx -Directory | Select-Object -First 1
Move-Item -Path $nginxFolder.FullName -Destination $NginxStaging -Force
cmd.exe /c "rmdir /s /q `"$tempNginx`"" 2>$null

# Create logs and temp directories required by Nginx
$nginxLogs = Join-Path $NginxStaging "logs"
$nginxTemp = Join-Path $NginxStaging "temp"
New-Item -ItemType Directory -Path $nginxLogs -Force | Out-Null
New-Item -ItemType Directory -Path $nginxTemp -Force | Out-Null
Set-Content -Path (Join-Path $nginxLogs ".gitkeep") -Value "" -Encoding utf8
Set-Content -Path (Join-Path $nginxTemp ".gitkeep") -Value "" -Encoding utf8

Write-Host "     Nginx extracted to: $NginxStaging" -ForegroundColor Green

# 3.2 PHP 8.2 x64
Write-Host "  -> Extracting PHP 8.2 x64..." -ForegroundColor White
New-Item -ItemType Directory -Path $PhpStaging -Force | Out-Null
Expand-Archive -Path $Downloads["php"].File -DestinationPath $PhpStaging -Force

# Generate php.ini configured for Aksaventra
Write-Host "  -> Generating optimized php.ini for Aksaventra..." -ForegroundColor White
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

; Required extensions for Aksaventra
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
Write-Host "     PHP 8.2 configured with Aksaventra requirements." -ForegroundColor Green

# 3.3 MariaDB 13.0.2 x64 ZIP Extraction
Write-Host "  -> Extracting MariaDB 13.0.2 x64 ZIP..." -ForegroundColor White
$tempMaria = Join-Path $ProjectRoot ".temp_mariadb_extract"
if (Test-Path $tempMaria) {
    cmd.exe /c "rmdir /s /q `"$tempMaria`"" 2>$null
    Remove-Item -Path $tempMaria -Recurse -Force -ErrorAction SilentlyContinue
}
Expand-Archive -Path $Downloads["mariadb"].File -DestinationPath $tempMaria -Force
$mariaFolder = Get-ChildItem -Path $tempMaria -Directory | Select-Object -First 1
Move-Item -Path $mariaFolder.FullName -Destination $MariaDbStaging -Force
cmd.exe /c "rmdir /s /q `"$tempMaria`"" 2>$null
Write-Host "     MariaDB portable runtime extracted to: $MariaDbStaging" -ForegroundColor Green

# 3.4 Aksaventra Core System 5.0.11
Write-Host "  -> Extracting Aksaventra Core System 5.0.11..." -ForegroundColor White
$tempMoodle = Join-Path $ProjectRoot ".temp_moodle_extract"
if (Test-Path $tempMoodle) {
    cmd.exe /c "rmdir /s /q `"$tempMoodle`"" 2>$null
    Remove-Item -Path $tempMoodle -Recurse -Force -ErrorAction SilentlyContinue
}
Expand-Archive -Path $Downloads["moodle"].File -DestinationPath $tempMoodle -Force
$moodleFolder = Join-Path $tempMoodle "moodle"
if (Test-Path $moodleFolder) {
    Move-Item -Path $moodleFolder -Destination $MoodleStaging -Force
}
else {
    Move-Item -Path $tempMoodle -Destination $MoodleStaging -Force
}
if (Test-Path $tempMoodle) {
    cmd.exe /c "rmdir /s /q `"$tempMoodle`"" 2>$null
    Remove-Item -Path $tempMoodle -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Host "     Aksaventra Core extracted to: $MoodleStaging" -ForegroundColor Green

# --- 4. PRUNE ENGINE CORE (NFR-03) ---
Write-Host "`n[4/6] Pruning Aksaventra Core (scripts/prune_moodle.ps1)..." -ForegroundColor Yellow
$pruneScript = Join-Path $ProjectRoot "scripts/prune_moodle.ps1"
if (Test-Path $pruneScript) {
    & $pruneScript -MoodlePath $MoodleStaging
}
else {
    Write-Warning "Prune script not found at $pruneScript, skipping pruning."
}

# --- 4.1 COMPREHENSIVE PACKAGE COMPLETENESS VERIFICATION ---
Write-Host "`nVerifying Complete Package Integrity (Zero Missing Components)..." -ForegroundColor Yellow
$RequiredItems = @(
    @{ Path = Join-Path $MoodleStaging "index.php"; Label = "Aksaventra Web Root (index.php)" },
    @{ Path = Join-Path $MoodleStaging "version.php"; Label = "Aksaventra Version Definition (version.php)" },
    @{ Path = Join-Path $MoodleStaging "admin\cli\install.php"; Label = "Aksaventra CLI Installer (admin/cli/install.php)" },
    @{ Path = Join-Path $PhpStaging "php.exe"; Label = "PHP 8.2 CLI Binary (php.exe)" },
    @{ Path = Join-Path $PhpStaging "php-cgi.exe"; Label = "PHP 8.2 FastCGI Binary (php-cgi.exe)" },
    @{ Path = Join-Path $PhpStaging "php.ini"; Label = "PHP Configuration (php.ini)" },
    @{ Path = Join-Path $PhpStaging "ext\php_curl.dll"; Label = "PHP Extension: curl" },
    @{ Path = Join-Path $PhpStaging "ext\php_intl.dll"; Label = "PHP Extension: intl" },
    @{ Path = Join-Path $PhpStaging "ext\php_mbstring.dll"; Label = "PHP Extension: mbstring" },
    @{ Path = Join-Path $PhpStaging "ext\php_mysqli.dll"; Label = "PHP Extension: mysqli" },
    @{ Path = Join-Path $PhpStaging "ext\php_openssl.dll"; Label = "PHP Extension: openssl" },
    @{ Path = Join-Path $PhpStaging "ext\php_pdo_mysql.dll"; Label = "PHP Extension: pdo_mysql" },
    @{ Path = Join-Path $PhpStaging "ext\php_sodium.dll"; Label = "PHP Extension: sodium" },
    @{ Path = Join-Path $PhpStaging "ext\php_zip.dll"; Label = "PHP Extension: zip" },
    @{ Path = Join-Path $PhpStaging "ext\php_fileinfo.dll"; Label = "PHP Extension: fileinfo" },
    @{ Path = Join-Path $PhpStaging "ext\php_gd.dll"; Label = "PHP Extension: gd" },
    @{ Path = Join-Path $NginxStaging "nginx.exe"; Label = "Nginx Web Server Binary (nginx.exe)" },
    @{ Path = Join-Path $NginxStaging "conf\mime.types"; Label = "Nginx MIME Types Configuration" },
    @{ Path = Join-Path $NginxStaging "conf\fastcgi_params"; Label = "Nginx FastCGI Parameters" },
    @{ Path = Join-Path $MariaDbStaging "bin\mariadbd.exe"; Label = "MariaDB Server Binary (bin\mariadbd.exe)" },
    @{ Path = Join-Path $MariaDbStaging "bin\mysql.exe"; Label = "MariaDB Client Binary (bin\mysql.exe)" },
    @{ Path = Join-Path $MariaDbStaging "bin\mysql_install_db.exe"; Label = "MariaDB Database Initialization Binary (bin\mysql_install_db.exe)" },
    @{ Path = Join-Path $MoodledataStaging ".gitkeep"; Label = "Aksaventra Data Directory" }
)

$MissingCount = 0
foreach ($item in $RequiredItems) {
    $exists = Test-Path $item.Path
    if (-not $exists -and $item.Fallback) {
        $exists = Test-Path $item.Fallback
    }
    if ($exists) {
        Write-Host "  [OK] $($item.Label)" -ForegroundColor Green
    }
    else {
        Write-Host "  [MISSING] $($item.Label): $($item.Path)" -ForegroundColor Red
        $MissingCount++
    }
}

if ($MissingCount -gt 0) {
    throw "Package verification failed: $MissingCount critical components are missing from staging! Cannot build container."
}

# Explicitly assert MariaDB is clean without pre-existing runtime data directory
$mariaMysqlDb = Join-Path $MariaDbStaging "data"
if (Test-Path $mariaMysqlDb) {
    throw "Integrity error: MariaDB data directory ($mariaMysqlDb) exists in staging! Database data must be initialized fresh on target machine."
}
Write-Host "  [OK] MariaDB portable runtime verified clean (no runtime data, ready for target initialization)" -ForegroundColor Green
Write-Host "  All required packages and components verified 100% complete!" -ForegroundColor Green

# --- 5. COMPRESS INTO PAYLOAD.ZIP ---
Write-Host "`n[5/6] Compressing Staging into $OutputPath..." -ForegroundColor Yellow
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
Write-Host "  SUCCESS: payload.zip generated!" -ForegroundColor Green
Write-Host "  Output File: $OutputPath" -ForegroundColor White
Write-Host "  Final Size:  $finalZipMB MB ($finalZipSize bytes)" -ForegroundColor White

# --- 6. GENERATE ENCRYPTED CONTAINER (.PAK) ---
if (-not $SkipPak) {
    $PakPath = Join-Path $ProjectRoot $OutputPak
    Write-Host "`n[6/6] Packaging into Encrypted Container ($PakPath)..." -ForegroundColor Yellow
    if (Test-Path $PakPath) {
        Remove-Item -Path $PakPath -Force
    }

    cargo run --release --manifest-path (Join-Path $ProjectRoot "Cargo.toml") -- --pack-container $OutputPath $PakPath
    if (Test-Path $PakPath) {
        $pakSize = (Get-Item $PakPath).Length
        $pakMB = [math]::Round($pakSize / 1MB, 2)
        Write-Host "  SUCCESS: Secure container generated!" -ForegroundColor Green
        Write-Host "  Output File: $PakPath" -ForegroundColor White
        Write-Host "  Final Size:  $pakMB MB ($pakSize bytes)" -ForegroundColor White
    }
}

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "  Build Pipeline Complete!" -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green
