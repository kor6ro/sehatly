# Isi interaktif deploy/.env.deploy tanpa membuka editor.
#
# Jalankan:
#   powershell -ExecutionPolicy Bypass -File deploy/isi-env.ps1
#
# Script tidak mencetak password/secret. Tekan Enter untuk mempertahankan nilai
# yang sudah ada (kecuali field yang masih placeholder, yang harus diisi).

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$path = Join-Path $PSScriptRoot '.env.deploy'

if (-not (Test-Path $path)) {
    Copy-Item (Join-Path $PSScriptRoot '.env.deploy.example') $path
}

function Baca-Env([string] $file) {
    $map = @{}

    foreach ($line in Get-Content $file) {
        if ($line -match '^\s*#' -or $line -match '^\s*$') { continue }
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$') {
            $map[$matches[1]] = $matches[2].Trim()
        }
    }

    return $map
}

function Tanya([string] $label, [string] $current) {
    $tampil = if ($current -match 'REPLACE') { '' } else { $current }
    $petunjuk = if ($tampil -ne '') { " [$tampil]" } else { '' }
    $jawab = Read-Host "$label$petunjuk"

    if ([string]::IsNullOrWhiteSpace($jawab)) {
        return $current
    }

    return $jawab.Trim()
}

function Tanya-Rahasia([string] $label, [string] $current) {
    $secure = Read-Host "$label (Enter = pertahankan)" -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)

    try {
        $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }

    if ([string]::IsNullOrEmpty($plain)) {
        return $current
    }

    return $plain
}

$cfg = Baca-Env $path

Write-Host ''
Write-Host '=== TiDB Cloud ==='
$cfg['TIDB_HOST'] = Tanya 'Host TiDB' $cfg['TIDB_HOST']
$cfg['TIDB_PORT'] = Tanya 'Port TiDB' $cfg['TIDB_PORT']
$cfg['TIDB_DATABASE'] = Tanya 'Nama database' $cfg['TIDB_DATABASE']
$cfg['TIDB_USER'] = Tanya 'Username TiDB (prefix.root)' $cfg['TIDB_USER']
$cfg['TIDB_PASSWORD'] = Tanya-Rahasia 'Password TiDB' $cfg['TIDB_PASSWORD']
$cfg['TIDB_CA_PATH'] = Tanya 'Path ca.pem (opsional)' $cfg['TIDB_CA_PATH']

Write-Host ''
Write-Host '=== Pusher ==='
$cfg['PUSHER_APP_ID'] = Tanya 'Pusher app id' $cfg['PUSHER_APP_ID']
$cfg['PUSHER_APP_KEY'] = Tanya 'Pusher key' $cfg['PUSHER_APP_KEY']
$cfg['PUSHER_APP_SECRET'] = Tanya-Rahasia 'Pusher secret' $cfg['PUSHER_APP_SECRET']
$cfg['PUSHER_APP_CLUSTER'] = Tanya 'Pusher cluster' $(if ($cfg['PUSHER_APP_CLUSTER']) { $cfg['PUSHER_APP_CLUSTER'] } else { 'ap1' })

$urutan = @(
    'APP_KEY', 'NIK_CIPHER_KEY',
    'TIDB_HOST', 'TIDB_PORT', 'TIDB_DATABASE', 'TIDB_USER', 'TIDB_PASSWORD', 'TIDB_CA_PATH',
    'PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET', 'PUSHER_APP_CLUSTER',
    'RENDER_API_KEY', 'CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ACCOUNT_ID',
    'DOMAIN', 'API_DOMAIN'
)

$baris = @(
    '# Salin file ini menjadi deploy/.env.deploy, lalu isi nilai asli.',
    '# File ini gitignored. Jangan commit dan jangan kirim isinya ke chat.',
    ''
)

foreach ($key in $urutan) {
    if ($cfg.ContainsKey($key)) {
        $baris += "$key=$($cfg[$key])"
    }
}

Set-Content -Encoding utf8 $path ($baris -join "`n")

Write-Host ''
Write-Host 'Tersimpan di deploy/.env.deploy.'
Write-Host 'Langkah berikutnya: powershell -ExecutionPolicy Bypass -File deploy/siapkan-demo.ps1'
