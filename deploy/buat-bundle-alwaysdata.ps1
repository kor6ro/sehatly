# Build a zip/tar bundle for Alwaysdata Free (PHP native, no Docker).
#
# Output: deploy/dist/sehatly-alwaysdata.tar.gz
# The bundle includes vendor/ (no dev packages) and a .env generated from
# deploy/.env.render.ready. Both the bundle and .env are gitignored.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File deploy/buat-bundle-alwaysdata.ps1

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$bundle = Join-Path $env:TEMP 'sehatly-alwaysdata-bundle'
$tar = Join-Path $env:TEMP 'sehatly-src.tar'
$ready = Join-Path $PSScriptRoot '.env.render.ready'
$outDir = Join-Path $PSScriptRoot 'dist'
$out = Join-Path $outDir 'sehatly-alwaysdata.tar.gz'

if (-not (Test-Path $ready)) {
    throw "File $ready tidak ada. Jalankan deploy/siapkan-demo.ps1 dulu."
}

Remove-Item -Recurse -Force $bundle -ErrorAction SilentlyContinue
Remove-Item -Force $tar -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force $bundle | Out-Null
New-Item -ItemType Directory -Force $outDir | Out-Null

Push-Location $root
try {
    git archive --format=tar HEAD -o $tar
    tar -xf $tar -C $bundle
} finally {
    Pop-Location
}

foreach ($dir in @('frontend', 'tests', 'docs', 'specs', 'tools', '.github', 'deploy')) {
    Remove-Item -Recurse -Force (Join-Path $bundle $dir) -ErrorAction SilentlyContinue
}

foreach ($file in @('Dockerfile', '.dockerignore', 'render.yaml', 'package.json', 'package-lock.json')) {
    Remove-Item -Force (Join-Path $bundle $file) -ErrorAction SilentlyContinue
}

# Laravel storage skeleton (git does not keep empty directories).
foreach ($dir in @(
    'storage/app/public',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache'
)) {
    New-Item -ItemType Directory -Force (Join-Path $bundle $dir) | Out-Null
}

# Build .env for Alwaysdata. The render-ready file is compatible except for the
# port/proxy/log settings, which are Render-specific.
$lines = Get-Content $ready
$envLines = New-Object System.Collections.Generic.List[string]

foreach ($line in $lines) {
    if ($line -match '^PORT=') { continue }
    if ($line -match '^TRUSTED_PROXIES=') { $envLines.Add('TRUSTED_PROXIES='); continue }
    if ($line -match '^LOG_CHANNEL=') { $envLines.Add('LOG_CHANNEL=single'); continue }

    $envLines.Add($line)
}

$envLines.Add('APP_MAINTENANCE_DRIVER=file')
$envText = ($envLines -join "`n") + "`n"
$utf8 = New-Object System.Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $bundle '.env'), $envText, $utf8)

Write-Host 'Install dependency produksi (tanpa dev)...'
Push-Location $bundle
try {
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
    $composerExit = $LASTEXITCODE
} finally {
    Pop-Location
}

if ($composerExit -ne 0) {
    throw "composer install gagal (exit $composerExit)."
}

Push-Location $bundle
try {
    tar -czf $out .
} finally {
    Pop-Location
}

$size = [math]::Round((Get-Item $out).Length / 1MB, 1)
Write-Host ''
Write-Host "Bundle siap: $out ($size MB)"
Write-Host 'Upload ke Alwaysdata lewat scp, lalu extract ke ~/www/sehatly.'
