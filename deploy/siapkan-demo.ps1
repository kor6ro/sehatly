# Siapkan demo Sehatly: seed TiDB + buat file env Render/Pages.
#
# Prasyarat: deploy/.env.deploy sudah diisi (copy dari .env.deploy.example).
# Script ini TIDAK mencetak password/secret. File hasilnya juga gitignored.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File deploy/siapkan-demo.ps1
#   powershell -ExecutionPolicy Bypass -File deploy/siapkan-demo.ps1 -Force
#
# -Force melewati konfirmasi sebelum migrate:fresh --seed (MENGHAPUS tabel).

[CmdletBinding()]
param(
    [switch] $Force
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php = 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe'
$envPath = Join-Path $PSScriptRoot '.env.deploy'

if (-not (Test-Path $php)) {
    throw "PHP 8.4 tidak ditemukan di $php. Sesuaikan variabel `$php di script ini."
}

if (-not (Test-Path $envPath)) {
    throw "File $envPath belum ada. Copy dari deploy/.env.deploy.example lalu isi."
}

function Baca-Env([string] $path) {
    $map = @{}

    foreach ($line in Get-Content $path) {
        if ($line -match '^\s*#' -or $line -match '^\s*$') {
            continue
        }

        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$') {
            $key = $matches[1]
            $value = $matches[2].Trim()

            if ($value -match '^"(.*)"$') { $value = $matches[1] }
            elseif ($value -match "^'(.*)'$") { $value = $matches[1] }

            $map[$key] = $value
        }
    }

    return $map
}

function Acak-Base64([int] $bytes = 32) {
    $buffer = New-Object byte[] $bytes
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($buffer)
    $rng.Dispose()

    return [Convert]::ToBase64String($buffer)
}

function Nilai([hashtable] $map, [string] $key, [string] $default = '') {
    if ($map.ContainsKey($key) -and $map[$key] -ne '') {
        return $map[$key]
    }

    return $default
}

$cfg = Baca-Env $envPath

$wajib = @(
    'TIDB_HOST', 'TIDB_USER', 'TIDB_PASSWORD',
    'PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET'
)

$kurang = @()
foreach ($key in $wajib) {
    $nilai = Nilai $cfg $key

    if ($nilai -eq '' -or $nilai -match 'REPLACE') {
        $kurang += $key
    }
}

if ($kurang.Count -gt 0) {
    throw "Isi dulu di deploy/.env.deploy: $($kurang -join ', ')"
}

$domain = Nilai $cfg 'DOMAIN' 'sehatly.k6devs.my.id'
$apiDomain = Nilai $cfg 'API_DOMAIN' "api.$domain"
$dbHost = Nilai $cfg 'TIDB_HOST'
$dbPort = Nilai $cfg 'TIDB_PORT' '4000'
$dbName = Nilai $cfg 'TIDB_DATABASE' 'sehatly'
$dbUser = Nilai $cfg 'TIDB_USER'
$dbPass = Nilai $cfg 'TIDB_PASSWORD'
$caPath = Nilai $cfg 'TIDB_CA_PATH'
$pusherId = Nilai $cfg 'PUSHER_APP_ID'
$pusherKey = Nilai $cfg 'PUSHER_APP_KEY'
$pusherSecret = Nilai $cfg 'PUSHER_APP_SECRET'
$pusherCluster = Nilai $cfg 'PUSHER_APP_CLUSTER' 'ap1'

$appKey = Nilai $cfg 'APP_KEY'
$nikKey = Nilai $cfg 'NIK_CIPHER_KEY'

if ($appKey -eq '') {
    $appKey = (& $php artisan key:generate --show | Select-Object -Last 1).Trim()
}

if ($nikKey -eq '') {
    $nikKey = 'base64:' + (Acak-Base64 32)
}

$cors = @(
    "https://$domain",
    "https://www.$domain",
    "https://admin.$domain",
    "https://dokter.$domain",
    "https://apotek.$domain",
    "https://kurir.$domain",
    "https://marketing.$domain"
) -join ','

$webhookMidtrans = Nilai $cfg 'PAYMENT_WEBHOOK_SECRET_MIDTRANS' (Acak-Base64 32)
$webhookXendit = Nilai $cfg 'PAYMENT_WEBHOOK_SECRET_XENDIT' (Acak-Base64 32)
$webhookDuitku = Nilai $cfg 'PAYMENT_WEBHOOK_SECRET_DUITKU' (Acak-Base64 32)
$webhookOy = Nilai $cfg 'PAYMENT_WEBHOOK_SECRET_OY' (Acak-Base64 32)

if (-not $Force) {
    Write-Host "Target database : $dbHost`:$dbPort/$dbName"
    Write-Host "Domain          : $domain"
    Write-Host ''
    Write-Host 'Script ini menjalankan migrate:fresh --seed. SEMUA tabel di database target dihapus.'
    $jawab = Read-Host 'Ketik SEED untuk melanjutkan'

    if ($jawab -ne 'SEED') {
        Write-Host 'Dibatalkan.'
        exit 1
    }
}

$sebelumnya = @{}
foreach ($nama in @(
    'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
    'DB_USERNAME', 'DB_PASSWORD', 'MYSQL_ATTR_SSL_CA'
)) {
    $sebelumnya[$nama] = [Environment]::GetEnvironmentVariable($nama, 'Process')
}

try {
    $env:APP_ENV = 'local'
    $env:DB_CONNECTION = 'mysql'
    $env:DB_HOST = $dbHost
    $env:DB_PORT = $dbPort
    $env:DB_DATABASE = $dbName
    $env:DB_USERNAME = $dbUser
    $env:DB_PASSWORD = $dbPass

    if ($caPath -ne '') {
        $env:MYSQL_ATTR_SSL_CA = (Resolve-Path $caPath).Path
    }

    Push-Location $root
    try {
        & $php artisan migrate:fresh --seed --force
        $kode = $LASTEXITCODE
    } finally {
        Pop-Location
    }
} finally {
    foreach ($nama in $sebelumnya.Keys) {
        if ($null -eq $sebelumnya[$nama]) {
            Remove-Item "Env:$nama" -ErrorAction SilentlyContinue
        } else {
            Set-Item "Env:$nama" $sebelumnya[$nama]
        }
    }
}

if ($kode -ne 0) {
    throw "Seed gagal dengan exit code $kode."
}

$render = @"
# Salin ke Render -> Environment. File ini gitignored.
APP_ENV=local
APP_DEBUG=false
APP_NAME=Sehatly
APP_URL=https://$apiDomain
APP_KEY=$appKey
NIK_CIPHER_KEY=$nikKey
LOG_CHANNEL=stderr
LOG_LEVEL=warning
TRUSTED_PROXIES=*

DB_CONNECTION=mysql
DB_HOST=$dbHost
DB_PORT=$dbPort
DB_DATABASE=$dbName
DB_USERNAME=$dbUser
DB_PASSWORD=$dbPass
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
DB_TIMEZONE=+00:00
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

OTP_DRIVER=log
PUSH_DRIVER=log

BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=$pusherId
PUSHER_APP_KEY=$pusherKey
PUSHER_APP_SECRET=$pusherSecret
PUSHER_APP_CLUSTER=$pusherCluster

CORS_ALLOWED_ORIGINS=$cors

PAYMENT_WEBHOOK_SECRET_MIDTRANS=$webhookMidtrans
PAYMENT_WEBHOOK_SECRET_XENDIT=$webhookXendit
PAYMENT_WEBHOOK_SECRET_DUITKU=$webhookDuitku
PAYMENT_WEBHOOK_SECRET_OY=$webhookOy
"@

$pages = @"
# Salin ke Cloudflare Pages -> Environment variables (Production).
NODE_VERSION=22
VITE_API_ORIGIN=https://$apiDomain
VITE_BROADCAST_DRIVER=pusher
VITE_PUSHER_APP_KEY=$pusherKey
VITE_PUSHER_CLUSTER=$pusherCluster
VITE_PUSHER_FORCE_TLS=true
"@

Set-Content -Encoding utf8 (Join-Path $PSScriptRoot '.env.render.ready') $render
Set-Content -Encoding utf8 (Join-Path $PSScriptRoot '.env.pages.ready') $pages

Write-Host ''
Write-Host 'Selesai.'
Write-Host "  Render env : deploy/.env.render.ready"
Write-Host "  Pages env  : deploy/.env.pages.ready"
Write-Host ''
Write-Host "API URL      : https://$apiDomain"
Write-Host "Pasien       : https://$domain"
Write-Host "Admin        : https://admin.$domain"
Write-Host ''
Write-Host 'Kedua file hasil TIDAK di-commit. Jangan kirim isinya ke chat.'

