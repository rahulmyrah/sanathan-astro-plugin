<#
.SYNOPSIS
    Build a distributable ZIP for the sanathan-astro-services WordPress plugin
    and update plugin-info.json with the new version metadata.

.DESCRIPTION
    Run this script from inside the sanathan-astro-services\ folder (or from the
    parent directory — it auto-detects location).

    Workflow:
      1. Bump SAS_VERSION in sanathan-astro-services.php
      2. Run this script
      3. Commit & push all changes to GitHub
      4. Create a GitHub Release tagged  v{version}
      5. Upload sanathan-astro-services.zip as a release asset
      6. WordPress sites running the plugin will see "Update Available" within 6 hours
         (or immediately after clicking "Check for updates")

.EXAMPLE
    cd "C:\Users\rahul\Desktop\Sanathan APP\Sanathan App - Vedic Astro\sanathan-astro-services"
    .\make-zip.ps1
#>

# ── Locate plugin root ────────────────────────────────────────────────────────

$scriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginMain  = Join-Path $scriptDir 'sanathan-astro-services.php'

if ( -not (Test-Path $pluginMain) ) {
    # Try one level up (script may be in parent)
    $scriptDir  = Join-Path $scriptDir 'sanathan-astro-services'
    $pluginMain = Join-Path $scriptDir 'sanathan-astro-services.php'
}

if ( -not (Test-Path $pluginMain) ) {
    Write-Error "Could not find sanathan-astro-services.php. Run this script from inside the plugin folder."
    exit 1
}

# ── Read current version from plugin header ───────────────────────────────────

$mainContent = Get-Content $pluginMain -Raw
if ( $mainContent -match "define\s*\(\s*'SAS_VERSION'\s*,\s*'([^']+)'" ) {
    $version = $Matches[1]
} else {
    Write-Error "Could not read SAS_VERSION from sanathan-astro-services.php"
    exit 1
}

Write-Host ""
Write-Host "  ╔══════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "  ║   Sanathan Astro Services — Build Script         ║" -ForegroundColor Cyan
Write-Host "  ╚══════════════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Plugin version : $version" -ForegroundColor Yellow
Write-Host "  Source folder  : $scriptDir" -ForegroundColor DarkGray
Write-Host ""

# ── Confirm before building ───────────────────────────────────────────────────

$confirm = Read-Host "  Build ZIP for v$version ? [Y/n]"
if ( $confirm -ne '' -and $confirm -notmatch '^[Yy]' ) {
    Write-Host "  Aborted." -ForegroundColor Red
    exit 0
}

# ── Update plugin-info.json ───────────────────────────────────────────────────

$infoFile = Join-Path $scriptDir 'plugin-info.json'
if ( Test-Path $infoFile ) {
    $info = Get-Content $infoFile -Raw | ConvertFrom-Json

    # Prompt for changelog entry
    Write-Host ""
    $changelogEntry = Read-Host "  Changelog entry for v$version (e.g. 'Fixed predictions cache')"
    if ( -not $changelogEntry ) { $changelogEntry = "Bug fixes and improvements." }

    # Build the new changelog — prepend the new version entry
    $newEntry    = "<h4>$version</h4><ul><li>$changelogEntry</li></ul>"
    $oldChangelog = $info.changelog
    $info.changelog    = "$newEntry`n$oldChangelog"
    $info.version      = $version
    $info.last_updated = (Get-Date -Format 'yyyy-MM-dd')

    # Update download_url to match new version tag
    if ( $info.download_url -match 'releases/download/v[^/]+/' ) {
        $info.download_url = $info.download_url -replace 'releases/download/v[^/]+/', "releases/download/v$version/"
    }

    $info | ConvertTo-Json -Depth 5 | Set-Content $infoFile -Encoding UTF8
    Write-Host ""
    Write-Host "  ✔ plugin-info.json updated to v$version" -ForegroundColor Green
} else {
    Write-Warning "  plugin-info.json not found — skipping JSON update."
}

# ── Build the ZIP ─────────────────────────────────────────────────────────────

$parentDir   = Split-Path -Parent $scriptDir
$zipName     = 'sanathan-astro-services.zip'
$zipPath     = Join-Path $parentDir $zipName

# Temp staging folder so the ZIP contains the correct folder name
$stagingRoot = Join-Path $env:TEMP 'sas-build'
$stagingDir  = Join-Path $stagingRoot 'sanathan-astro-services'

if ( Test-Path $stagingRoot ) { Remove-Item $stagingRoot -Recurse -Force }
New-Item -ItemType Directory -Path $stagingDir | Out-Null

# ── Copy plugin files (exclude dev/build artefacts) ──────────────────────────

$excludePatterns = @(
    '*.zip',
    '*.ps1',
    '.git',
    '.gitignore',
    'node_modules',
    '.DS_Store',
    'Thumbs.db'
)

function Copy-Filtered {
    param( [string]$Source, [string]$Dest )

    foreach ( $item in Get-ChildItem -Path $Source ) {
        $skip = $false
        foreach ( $pat in $excludePatterns ) {
            if ( $item.Name -like $pat ) { $skip = $true; break }
        }
        if ( $skip ) { continue }

        $destItem = Join-Path $Dest $item.Name
        if ( $item.PSIsContainer ) {
            New-Item -ItemType Directory -Path $destItem -Force | Out-Null
            Copy-Filtered -Source $item.FullName -Dest $destItem
        } else {
            Copy-Item -Path $item.FullName -Destination $destItem -Force
        }
    }
}

Copy-Filtered -Source $scriptDir -Dest $stagingDir

# ── Compress ──────────────────────────────────────────────────────────────────

if ( Test-Path $zipPath ) { Remove-Item $zipPath -Force }
Compress-Archive -Path $stagingDir -DestinationPath $zipPath

Remove-Item $stagingRoot -Recurse -Force

$zipSize = [math]::Round( (Get-Item $zipPath).Length / 1KB, 1 )
Write-Host ""
Write-Host "  ✔ ZIP created  : $zipPath" -ForegroundColor Green
Write-Host "    Size         : $zipSize KB" -ForegroundColor DarkGray
Write-Host ""

# ── Next-step instructions ────────────────────────────────────────────────────

Write-Host "  ─────────────────────────────────────────────────────" -ForegroundColor DarkGray
Write-Host "  Next steps to publish v$version :" -ForegroundColor Cyan
Write-Host ""
Write-Host "   1. git add -A && git commit -m 'Release v$version'" -ForegroundColor White
Write-Host "   2. git tag v$version" -ForegroundColor White
Write-Host "   3. git push origin main --tags" -ForegroundColor White
Write-Host "   4. On GitHub → Releases → Draft a new release" -ForegroundColor White
Write-Host "      Tag      : v$version" -ForegroundColor DarkGray
Write-Host "      Asset    : $zipName (drag the ZIP in)" -ForegroundColor DarkGray
Write-Host "   5. Publish the release — WordPress will auto-detect the update!" -ForegroundColor White
Write-Host ""
Write-Host "  ─────────────────────────────────────────────────────" -ForegroundColor DarkGray
Write-Host ""
