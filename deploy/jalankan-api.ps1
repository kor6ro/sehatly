# Jalankan API Laravel lokal untuk Cloudflare Tunnel.
# Dipanggil oleh MULAI-DEMO.cmd. Jangan dijalankan dua kali.

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php = 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe'
$ready = Join-Path $PSScriptRoot '.env.render.ready'
$deploy = Join-Path $PSScriptRoot '.env.deploy'

if (-not (Test-Path $php)) {
    throw "PHP 8.4 tidak ditemukan di $php"
}

if (-not (Test-Path $ready)) {
    throw "File $ready tidak ada. Jalankan deploy/siapkan-demo.ps1 dulu."
}

function Baca-Env([string] $file) {
    $map = @{}

    if (-not (Test-Path $file)) {
        return $map
    }

    foreach ($line in Get-Content $file) {
        if ($line -match '^\s*#' -or $line -match '^\s*$') { continue }
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$') {
            $map[$matches[1]] = $matches[2].Trim()
        }
    }

    return $map
}

$cfg = Baca-Env $ready
$local = Baca-Env $deploy

foreach ($key in $cfg.Keys) {
    Set-Item "Env:$key" $cfg[$key]
}

# Render/Linux path -> CA bundle Windows untuk koneksi TiDB dari laptop.
$ca = $local['TIDB_CA_PATH']
if ([string]::IsNullOrWhiteSpace($ca)) {
    $ca = 'C:\laragon\etc\ssl\cacert.pem'
}
Set-Item 'Env:MYSQL_ATTR_SSL_CA' $ca

# Izinkan juga alias pages.dev selama custom domain belum aktif.
$cors = $cfg['CORS_ALLOWED_ORIGINS']
if ($cors -notmatch 'sehatly-web.pages.dev') {
    $cors = $cors.TrimEnd(',') + ',https://sehatly-web.pages.dev'
}
Set-Item 'Env:CORS_ALLOWED_ORIGINS' $cors

# Port diatur oleh argumen artisan serve, bukan env.
Remove-Item 'Env:PORT' -ErrorAction SilentlyContinue

Set-Location $root

Write-Host 'Membersihkan config cache...'
& $php artisan config:clear

Write-Host ''
Write-Host 'API lokal: http://127.0.0.1:8000'
Write-Host 'Tunnel  : https://api.k6devs.my.id'
Write-Host 'Biarkan jendela ini terbuka selama demo.'
Write-Host ''

& $php artisan serve --host=127.0.0.1 --port=8000
