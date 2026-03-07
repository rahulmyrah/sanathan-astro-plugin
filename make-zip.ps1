<#
.SYNOPSIS
    Build a distributable ZIP for the sanathan-app WordPress plugin
    and update plugin-info.json with the new version metadata.

.DESCRIPTION
    Run this script from inside the sanathan-app\ folder.

    Workflow:
      1. Bump SAS_VERSION in sanathan-app.php
      2. Run this script
      3. Commit & push all changes + tags to GitHub
      4. Create a GitHub Release tagged  v{version}  with the generated ZIP
      5. WordPress sites running the plugin will see "Update Available" within 6 hours

    Uses `git archive` (not Compress-Archive) to guarantee forward-slash paths
    in the ZIP — required for correct extraction on Linux/Hostinger servers.

.EXAMPLE
    cd "C:\Users\rahul\Desktop\Sanathan APP\Sanathan App - Vedic Astro\sanathan-app"
    .\make-zip.ps1
#>

# ── Locate plugin root ────────────────────────────────────────────────────────

$scriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginMain = Join-Path $scriptDir 'sanathan-app.php'

if ( -not (Test-Path $pluginMain) ) {
    Write-Error "Could not find sanathan-app.php. Run this script from inside the sanathan-app folder."
    exit 1
}

# ── Read current version from plugin header ───────────────────────────────────

$mainContent = Get-Content $pluginMain -Raw
if ( $mainContent -match "define\s*\(\s*'SAS_VERSION'\s*,\s*'([^']+)'" ) {
    $version = $Matches[1]
} else {
    Write-Error "Could not read SAS_VERSION from sanathan-app.php"
    exit 1
}

Write-Host ""
Write-Host "  ╔══════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "  ║   Sanathan App — Build Script                    ║" -ForegroundColor Cyan
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

    Write-Host ""
    $changelogEntry = Read-Host "  Changelog entry for v$version (e.g. 'Fixed predictions cache')"
    if ( -not $changelogEntry ) { $changelogEntry = "Bug fixes and improvements." }

    $newEntry         = "<h4>$version</h4><ul><li>$changelogEntry</li></ul>"
    $info.changelog   = "$newEntry`n$($info.changelog)"
    $info.version     = $version
    $info.last_updated = (Get-Date -Format 'yyyy-MM-dd')

    if ( $info.download_url -match 'releases/download/v[^/]+/' ) {
        $info.download_url = $info.download_url -replace 'releases/download/v[^/]+/', "releases/download/v$version/"
    }

    $info | ConvertTo-Json -Depth 5 | Set-Content $infoFile -Encoding UTF8
    Write-Host "  ✔ plugin-info.json updated to v$version" -ForegroundColor Green
} else {
    Write-Warning "  plugin-info.json not found — skipping JSON update."
}

# ── Build ZIP using git archive (forward-slash paths, Linux-safe) ─────────────

$parentDir = Split-Path -Parent $scriptDir
$zipName   = 'sanathan-app.zip'
$zipPath   = Join-Path $parentDir $zipName

if ( Test-Path $zipPath ) { Remove-Item $zipPath -Force }

Push-Location $scriptDir
# --prefix=sanathan-app/ sets the root folder name inside the ZIP
git archive --format=zip --prefix=sanathan-app/ HEAD -o $zipPath 2>&1
$gitResult = $LASTEXITCODE
Pop-Location

if ( $gitResult -ne 0 ) {
    Write-Error "git archive failed. Make sure all changes are committed."
    exit 1
}

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
Write-Host "   2. git push origin main" -ForegroundColor White
Write-Host "   3. gh release create v$version $zipPath --title 'v$version' --notes '$changelogEntry'" -ForegroundColor White
Write-Host "      (or use GitHub web UI to create the release and upload the ZIP)" -ForegroundColor DarkGray
Write-Host ""
Write-Host "  WordPress will auto-detect the update within 6 hours!" -ForegroundColor Green
Write-Host "  ─────────────────────────────────────────────────────" -ForegroundColor DarkGray
Write-Host ""
