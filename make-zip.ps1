<#
.SYNOPSIS
    Build the sanathan-app plugin ZIP and upload it to the Hostinger server
    so WordPress shows "Update Available" in the Plugins dashboard immediately.

.DESCRIPTION
    Run this script from inside the sanathan-app\ folder.

    What it does:
      1. Reads SAS_VERSION from sanathan-app.php
      2. Asks for a changelog entry
      3. Updates plugin-info.json  (version, last_updated, changelog)
      4. Builds sanathan-app.zip via git archive  (Linux-safe paths)
      5. FTP-uploads  sanathan-app.zip  →  server:/plugin-updates/
      6. FTP-uploads  plugin-info.json  →  server:/plugin-updates/
      7. WordPress picks up the new version within seconds.

    ── FTP CONFIG  (fill in once, then never touch again) ───────────────────────
#>

# ═══════════════════════════════════════════════════════════════════════════════
#  ▼▼▼  FILL IN YOUR HOSTINGER FTP DETAILS HERE  ▼▼▼
# ═══════════════════════════════════════════════════════════════════════════════

$FTP_HOST = "ftp.sanathan.app"          # FTP host  (check Hostinger → FTP Accounts)
$FTP_USER = "your_ftp_username"         # FTP username
$FTP_PASS = "your_ftp_password"         # FTP password
$FTP_DIR  = "/public_html/wp-content/uploads/plugin-updates"  # remote directory

# ═══════════════════════════════════════════════════════════════════════════════

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ── Locate plugin root ────────────────────────────────────────────────────────

$scriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginMain = Join-Path $scriptDir 'sanathan-app.php'

if ( -not (Test-Path $pluginMain) ) {
    Write-Error "Cannot find sanathan-app.php. Run from inside the sanathan-app folder."
    exit 1
}

# ── Banner ────────────────────────────────────────────────────────────────────

Write-Host ""
Write-Host "  ╔══════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "  ║   Sanathan App — Release Script                  ║" -ForegroundColor Cyan
Write-Host "  ╚══════════════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

# ── Read version ──────────────────────────────────────────────────────────────

$mainContent = Get-Content $pluginMain -Raw
if ( $mainContent -match "define\s*\(\s*'SAS_VERSION'\s*,\s*'([^']+)'" ) {
    $version = $Matches[1]
} else {
    Write-Error "Cannot read SAS_VERSION from sanathan-app.php. Bump it first."
    exit 1
}

Write-Host "  Plugin version : $version" -ForegroundColor Yellow
Write-Host "  FTP host       : $FTP_HOST" -ForegroundColor DarkGray
Write-Host "  Remote dir     : $FTP_DIR" -ForegroundColor DarkGray
Write-Host ""

# ── Changelog entry ───────────────────────────────────────────────────────────

$changelogEntry = Read-Host "  Changelog entry for v$version (press Enter to skip)"
if ( -not $changelogEntry ) { $changelogEntry = "Bug fixes and improvements." }

# ── Confirm ───────────────────────────────────────────────────────────────────

$confirm = Read-Host "  Build + upload v$version to $FTP_HOST ? [Y/n]"
if ( $confirm -ne '' -and $confirm -notmatch '^[Yy]' ) {
    Write-Host "  Aborted." -ForegroundColor Red
    exit 0
}

# ── Update plugin-info.json ───────────────────────────────────────────────────

$infoFile = Join-Path $scriptDir 'plugin-info.json'
if ( Test-Path $infoFile ) {
    $info = Get-Content $infoFile -Raw | ConvertFrom-Json

    $newEntry = "<h4>$version</h4><ul><li>$changelogEntry</li></ul>"

    # Prepend only if this version isn't already the first entry
    if ( -not ($info.changelog -match "^<h4>$([regex]::Escape($version))</h4>") ) {
        $info.changelog = "$newEntry$($info.changelog)"
    }

    $info.version      = $version
    $info.last_updated = (Get-Date -Format 'yyyy-MM-dd')

    $info | ConvertTo-Json -Depth 5 | Set-Content $infoFile -Encoding UTF8
    Write-Host ""
    Write-Host "  ✔ plugin-info.json updated to v$version" -ForegroundColor Green
}

# ── Build ZIP ─────────────────────────────────────────────────────────────────

$parentDir = Split-Path -Parent $scriptDir
$zipPath   = Join-Path $parentDir 'sanathan-app.zip'

if ( Test-Path $zipPath ) { Remove-Item $zipPath -Force }

# Commit any local changes first (git archive only packs committed files)
Push-Location $scriptDir
Write-Host "  Committing changes…" -ForegroundColor DarkGray
git add -A 2>&1 | Out-Null
git commit -m "Release v$version" 2>&1 | Out-Null

Write-Host "  Building ZIP…" -ForegroundColor DarkGray
git archive --format=zip --prefix=sanathan-app/ HEAD -o $zipPath 2>&1
$gitOk = $LASTEXITCODE
Pop-Location

if ( $gitOk -ne 0 ) {
    Write-Error "git archive failed. Is git installed and is this a git repo?"
    exit 1
}

$zipSizeKB = [math]::Round( (Get-Item $zipPath).Length / 1KB, 1 )
Write-Host "  ✔ ZIP built     : $zipPath  ($zipSizeKB KB)" -ForegroundColor Green

# ── FTP upload helper ─────────────────────────────────────────────────────────

function FtpUpload {
    param(
        [string] $localPath,
        [string] $remotePath   # full path e.g. /public_html/wp-content/uploads/plugin-updates/file.zip
    )

    $fileName = Split-Path $localPath -Leaf
    $uri      = "ftp://${FTP_HOST}${remotePath}"

    Write-Host "  Uploading $fileName…" -ForegroundColor DarkGray

    $request                   = [System.Net.FtpWebRequest]::Create($uri)
    $request.Method            = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $request.Credentials      = New-Object System.Net.NetworkCredential($FTP_USER, $FTP_PASS)
    $request.UseBinary         = $true
    $request.UsePassive        = $true
    $request.KeepAlive         = $false
    $request.Timeout           = 120000   # 2 min

    $fileBytes = [System.IO.File]::ReadAllBytes($localPath)
    $request.ContentLength = $fileBytes.Length

    $stream = $request.GetRequestStream()
    $stream.Write($fileBytes, 0, $fileBytes.Length)
    $stream.Close()

    $response   = $request.GetResponse()
    $statusDesc = $response.StatusDescription
    $response.Close()

    Write-Host "  ✔ $fileName  → $uri" -ForegroundColor Green
    Write-Host "    Server: $statusDesc" -ForegroundColor DarkGray
}

# ── Upload both files ─────────────────────────────────────────────────────────

Write-Host ""
Write-Host "  ── Uploading to $FTP_HOST ─────────────────────────" -ForegroundColor Cyan

try {
    FtpUpload -localPath $zipPath   -remotePath "${FTP_DIR}/sanathan-app.zip"
    FtpUpload -localPath $infoFile  -remotePath "${FTP_DIR}/plugin-info.json"
}
catch {
    Write-Host ""
    Write-Host "  ❌ FTP upload failed: $_" -ForegroundColor Red
    Write-Host ""
    Write-Host "  Check your FTP_HOST / FTP_USER / FTP_PASS at the top of make-zip.ps1" -ForegroundColor DarkYellow
    Write-Host "  The ZIP is at: $zipPath  — you can upload it manually via Hostinger File Manager." -ForegroundColor DarkYellow
    exit 1
}

# ── Optional: also push to GitHub ────────────────────────────────────────────

$hasGit = $null -ne (Get-Command git -ErrorAction SilentlyContinue)
if ( $hasGit ) {
    Push-Location $scriptDir
    $pushGit = Read-Host "`n  Also push to GitHub? [Y/n]"
    if ( $pushGit -eq '' -or $pushGit -match '^[Yy]' ) {
        git push origin main 2>&1
        Write-Host "  ✔ Pushed to GitHub" -ForegroundColor Green
    }
    Pop-Location
}

# ── Done ─────────────────────────────────────────────────────────────────────

Write-Host ""
Write-Host "  ─────────────────────────────────────────────────────" -ForegroundColor DarkGray
Write-Host "  ✅  v$version is live on the server!" -ForegroundColor Green
Write-Host ""
Write-Host "  Go to:  WP Admin → Plugins" -ForegroundColor White
Write-Host "  You should see:  'Update Available — v$version'" -ForegroundColor White
Write-Host "  Click:  Update Now" -ForegroundColor White
Write-Host "  ─────────────────────────────────────────────────────" -ForegroundColor DarkGray
Write-Host ""
