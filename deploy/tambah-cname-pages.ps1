# Tambahkan/update CNAME portal Pages di Cloudflare.
#
# Prasyarat: deploy/.env.deploy berisi CLOUDFLARE_API_TOKEN dengan izin
#   Zone -> DNS -> Edit
#   Zone -> Zone -> Read
# untuk zone k6devs.my.id.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File deploy/tambah-cname-pages.ps1

[CmdletBinding()]
param()

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$ErrorActionPreference = 'Stop'

$envPath = Join-Path $PSScriptRoot '.env.deploy'
$zoneName = 'k6devs.my.id'
$target = 'sehatly-web.pages.dev'

if (-not (Test-Path $envPath)) {
    throw "File $envPath tidak ada."
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

$cfg = Baca-Env $envPath
$token = $cfg['CLOUDFLARE_API_TOKEN']

if ([string]::IsNullOrWhiteSpace($token)) {
    throw 'CLOUDFLARE_API_TOKEN kosong di deploy/.env.deploy.'
}

$headers = @{
    Authorization = "Bearer $token"
    'Content-Type' = 'application/json'
}

try {
    $zones = Invoke-RestMethod -Uri "https://api.cloudflare.com/client/v4/zones?name=$zoneName" `
        -Headers $headers -Method Get -TimeoutSec 30
} catch {
    throw "Gagal membaca zone. Cek izin token (Zone.Zone:Read). Detail: $($_.ErrorDetails.Message)"
}

if ($zones.result.Count -eq 0) {
    throw "Zone $zoneName tidak ditemukan di akun token ini."
}

$zoneId = $zones.result[0].id
Write-Host "Zone: $zoneName ($zoneId)"

$names = @(
    'sehatly',
    'www.sehatly',
    'admin.sehatly',
    'dokter.sehatly',
    'apotek.sehatly',
    'kurir.sehatly',
    'marketing.sehatly'
)

foreach ($name in $names) {
    $full = "$name.$zoneName"

    try {
        $existing = Invoke-RestMethod `
            -Uri "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records?name=$full" `
            -Headers $headers -Method Get -TimeoutSec 30
    } catch {
        throw "Gagal membaca record $full. Detail: $($_.ErrorDetails.Message)"
    }

    $body = @{
        type = 'CNAME'
        name = $full
        content = $target
        proxied = $false
        ttl = 1
    } | ConvertTo-Json

    try {
        if ($existing.result.Count -gt 0) {
            $id = $existing.result[0].id
            $r = Invoke-RestMethod `
                -Uri "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/$id" `
                -Headers $headers -Method Put -Body $body -TimeoutSec 30
            Write-Host "UPDATE $full -> $($r.result.content) (proxied=$($r.result.proxied))"
        } else {
            $r = Invoke-RestMethod `
                -Uri "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records" `
                -Headers $headers -Method Post -Body $body -TimeoutSec 30
            Write-Host "CREATE $full -> $($r.result.content) (proxied=$($r.result.proxied))"
        }
    } catch {
        Write-Host "GAGAL $full : $($_.ErrorDetails.Message)"
    }
}

Write-Host ''
Write-Host 'Selesai. Tunggu 1-5 menit lalu cek:'
Write-Host "  https://sehatly.$zoneName"
Write-Host "  https://admin.sehatly.$zoneName/login"

