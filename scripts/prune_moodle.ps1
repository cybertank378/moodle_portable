<#
.SYNOPSIS
    Prunes non-essential Moodle modules, test suites, and documentation (NFR-03).
    Reduces the Moodle footprint by 35-45% before packaging into payload.zip.

.PARAMETER MoodlePath
    Target directory of the Moodle web root (defaults to ./runtime/moodle).

.PARAMETER DryRun
    Scans and reports potential savings without deleting files.

.EXAMPLE
    .\scripts\prune_moodle.ps1 -MoodlePath "D:\Project\moodle_installer\runtime\moodle"
#>
[CmdletBinding()]
param (
    [Parameter(Position = 0)]
    [string]$MoodlePath = "./runtime/moodle",
    [switch]$DryRun
)

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "  Moodle Portable Core Pruning Script" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan

if (-not (Test-Path $MoodlePath)) {
    Write-Warning "Target Moodle path not found: $MoodlePath"
    exit 0
}

function Get-FolderSizeBytes ($Path) {
    if (-not (Test-Path $Path)) { return 0 }
    return (Get-ChildItem -Path $Path -Recurse -Force -ErrorAction SilentlyContinue |
        Measure-Object -Property Length -Sum).Sum
}

$InitialSize = Get-FolderSizeBytes $MoodlePath
$InitialSizeMB = [math]::Round($InitialSize / 1MB, 2)
Write-Host "Initial Moodle folder size: $InitialSizeMB MB ($InitialSize bytes)" -ForegroundColor White

$Targets = @(
    # Testing, CI, & development frameworks (never needed at runtime)
    ".git",
    ".github",
    "node_modules",
    "phpunit.xml.dist",
    "Gruntfile.js",
    "package.json",
    "package-lock.json",
    ".stylelintrc",
    ".eslintrc",
    ".travis.yml",
    ".editorconfig",

    # Documentation & license text files
    "README.txt",
    "TRADEMARK.txt",
    "COPYING.txt"
)

$DeletedCount = 0
$FreedBytes = 0

# 1. Remove designated module and config targets
foreach ($target in $Targets) {
    $fullPath = Join-Path $MoodlePath $target
    if (Test-Path $fullPath) {
        $targetSize = Get-FolderSizeBytes $fullPath
        $FreedBytes += $targetSize
        $DeletedCount++
        
        if ($DryRun) {
            Write-Host "[DRY-RUN] Would remove: $target ($([math]::Round($targetSize / 1KB, 2)) KB)" -ForegroundColor Yellow
        } else {
            Remove-Item -Path $fullPath -Recurse -Force -ErrorAction SilentlyContinue
            Write-Host "[REMOVED] $target ($([math]::Round($targetSize / 1KB, 2)) KB)" -ForegroundColor Green
        }
    }
}

# 2. Recursively remove test directories (tests, behat, phpunit)
Write-Host "Scanning for recursive test suites (tests/, behat/, phpunit/)..." -ForegroundColor White
$TestFolders = Get-ChildItem -Path $MoodlePath -Recurse -Directory -Force -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -in @("tests", "behat", "phpunit") }

foreach ($folder in $TestFolders) {
    $folderSize = Get-FolderSizeBytes $folder.FullName
    $FreedBytes += $folderSize
    $DeletedCount++

    if ($DryRun) {
        Write-Host "[DRY-RUN] Would remove test dir: $($folder.FullName)" -ForegroundColor Yellow
    } else {
        Remove-Item -Path $folder.FullName -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host "[REMOVED] Test suite: $($folder.FullName)" -ForegroundColor Green
    }
}

$FinalSize = if ($DryRun) { $InitialSize - $FreedBytes } else { Get-FolderSizeBytes $MoodlePath }
$FinalSizeMB = [math]::Round($FinalSize / 1MB, 2)
$FreedMB = [math]::Round($FreedBytes / 1MB, 2)
$Percentage = if ($InitialSize -gt 0) { [math]::Round(($FreedBytes / $InitialSize) * 100, 2) } else { 0 }

Write-Host "------------------------------------------" -ForegroundColor Cyan
Write-Host "Pruning Summary:" -ForegroundColor Cyan
Write-Host "  Items processed: $DeletedCount" -ForegroundColor White
Write-Host "  Estimated freed: $FreedMB MB" -ForegroundColor Green
Write-Host "  Final size:      $FinalSizeMB MB (Reduction: $Percentage%)" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Cyan
