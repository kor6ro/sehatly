# Mulai demo: API lokal + Cloudflare Tunnel.
# Jalankan lewat MULAI-DEMO.cmd (klik dua kali) atau:
#   powershell -ExecutionPolicy Bypass -File deploy/mulai-demo.ps1

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$apiScript = Join-Path $PSScriptRoot 'jalankan-api.ps1'
$tunnelConfig = Join-Path $env:USERPROFILE '.cloudflared\sehatly-api.yml'
$cloudflared = (Get-Command cloudflared -ErrorAction SilentlyContinue)

if ($cloudflared -eq $null) {
    throw 'cloudflared tidak ditemukan di PATH.'
}

if (-not (Test-Path $tunnelConfig)) {
    throw "Config tunnel tidak ditemukan: $tunnelConfig"
}

# Jangan menumpuk proses kalau sebelumnya belum dihentikan.
$sudah = Get-CimInstance Win32_Process | Where-Object {
    $_.CommandLine -and (
        $_.CommandLine -match 'artisan serve' -or
        ($_.CommandLine -match 'cloudflared' -and $_.CommandLine -match 'sehatly-api')
    )
}

if ($sudah) {
    Write-Host 'Demo sepertinya sudah berjalan. Jalankan HENTIKAN-DEMO.cmd dulu.'
    exit 1
}

Write-Host 'Menyalakan API Laravel...'
Start-Process powershell -ArgumentList @(
    '-NoExit',
    '-ExecutionPolicy', 'Bypass',
    '-File', $apiScript
)

Start-Sleep -Seconds 4

Write-Host 'Menyalakan Cloudflare Tunnel...'
Start-Process -FilePath $cloudflared.Source -ArgumentList @(
    'tunnel',
    '--config', $tunnelConfig,
    'run', 'sehatly-api'
)

Start-Sleep -Seconds 10

try {
    $r = Invoke-WebRequest -Uri 'http://127.0.0.1:8000/up' -UseBasicParsing -TimeoutSec 10
    Write-Host "API lokal  : $($r.StatusCode)"
} catch {
    Write-Host "API lokal  : belum siap ($($_.Exception.Message))"
}

try {
    $r = Invoke-WebRequest -Uri 'https://api.k6devs.my.id/up' -UseBasicParsing -TimeoutSec 20
    Write-Host "API publik : $($r.StatusCode)"
} catch {
    Write-Host "API publik : belum siap ($($_.Exception.Message))"
}

Write-Host ''
Write-Host 'Demo hidup. Buka:'
Write-Host '  https://sehatly.k6devs.my.id'
Write-Host '  https://sehatly-web.pages.dev'
Write-Host ''
Write-Host 'Setelah selesai, jalankan HENTIKAN-DEMO.cmd.'
