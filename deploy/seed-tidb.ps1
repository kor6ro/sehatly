# Seed the demo database into TiDB from the Laragon checkout.
#
# Render's free plan has no shell and no one-off jobs, so the first seed has to
# run from a machine that can reach TiDB. This script sets the DB_* variables
# for one `migrate:fresh --seed` invocation; it does not edit `.env`, so your
# local MySQL setup is untouched afterwards.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File deploy/seed-tidb.ps1 `
#     -DbHost gateway01.ap-southeast-1.prod.aws.tidbcloud.com `
#     -DbUser 3pTAoNNegb47Uc8.root `
#     -DbPassword 'password' `
#     -CaPath C:\path\to\ca.pem
#
# Omit -CaPath if PDO can negotiate TLS with the system CA bundle (Linux/Render
# uses /etc/ssl/certs/ca-certificates.crt, but this script runs on Windows and
# usually needs TiDB's downloaded ca.pem).
#
# The command is DESTRUCTIVE against the target database: it drops and recreates
# every table. It asks for confirmation unless -Force is given.

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $DbHost,

    [Parameter(Mandatory = $true)]
    [string] $DbUser,

    [Parameter(Mandatory = $true)]
    [string] $DbPassword,

    [string] $DbName = 'sehatly',
    [int] $DbPort = 4000,
    [string] $CaPath = '',
    [switch] $Force
)

$ErrorActionPreference = 'Stop'

$php = 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe'
if (-not (Test-Path $php)) {
    throw "PHP 8.4 tidak ditemukan di $php. Sesuaikan variabel `$php di script ini."
}

if (-not $Force) {
    Write-Host "Target : $DbHost`:$DbPort/$DbName"
    Write-Host "User   : $DbUser"
    Write-Host ''
    Write-Host 'Perintah ini menghapus dan mengisi ulang SEMUA tabel di database target.'
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
    $env:DB_HOST = $DbHost
    $env:DB_PORT = [string] $DbPort
    $env:DB_DATABASE = $DbName
    $env:DB_USERNAME = $DbUser
    $env:DB_PASSWORD = $DbPassword

    if ($CaPath -ne '') {
        $env:MYSQL_ATTR_SSL_CA = (Resolve-Path $CaPath).Path
    }

    & $php artisan migrate:fresh --seed --force
    $kode = $LASTEXITCODE
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
    throw "Seed gagal dengan exit code $kode. Periksa pesan di atas."
}

Write-Host ''
Write-Host 'Seed selesai. Akun demo ada di testaccount.md.'
