# Hentikan API lokal + Cloudflare Tunnel demo.

$ErrorActionPreference = 'SilentlyContinue'

$proses = Get-CimInstance Win32_Process | Where-Object {
    $_.CommandLine -and (
        $_.CommandLine -match 'artisan serve' -or
        $_.CommandLine -match 'php -S 127\.0\.0\.1:8000' -or
        ($_.CommandLine -match 'cloudflared' -and $_.CommandLine -match 'sehatly-api')
    )
}

if (-not $proses) {
    Write-Host 'Tidak ada proses demo yang berjalan.'
    exit 0
}

foreach ($p in $proses) {
    try {
        Stop-Process -Id $p.ProcessId -Force
        Write-Host "Dihentikan: PID $($p.ProcessId) $($p.Name)"
    } catch {
        Write-Host "Gagal menghentikan PID $($p.ProcessId): $($_.Exception.Message)"
    }
}

Write-Host ''
Write-Host 'Tunnel dan API sudah mati. Selama mati, https://api.k6devs.my.id tidak bisa diakses.'
