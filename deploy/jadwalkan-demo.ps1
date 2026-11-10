# Jadwalkan demo hanya pada jam kerja (Windows Task Scheduler).
#
# Default: Senin-Jumat, mulai 08:00, stop 17:00.
# Ubah sesuai jam kerja:
#   powershell -ExecutionPolicy Bypass -File deploy/jadwalkan-demo.ps1 -Mulai 09:00 -Selesai 16:00
#
# Catatan: task hanya jalan kalau laptop menyala. Kalau laptop mati/sleep,
# jalankan MULAI-DEMO.cmd manual saat mulai kerja.

[CmdletBinding()]
param(
    [string] $Mulai = '08:00',
    [string] $Selesai = '17:00'
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$mulaiScript = Join-Path $PSScriptRoot 'mulai-demo.ps1'
$stopScript = Join-Path $PSScriptRoot 'hentikan-demo.ps1'

if (-not (Test-Path $mulaiScript) -or -not (Test-Path $stopScript)) {
    throw 'Script demo tidak ditemukan.'
}

$days = @(
    'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'
)

$startAction = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-ExecutionPolicy Bypass -WindowStyle Hidden -File `"$mulaiScript`""
$stopAction = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-ExecutionPolicy Bypass -WindowStyle Hidden -File `"$stopScript`""

$startTrigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek $days -At $Mulai
$stopTrigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek $days -At $Selesai

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -WakeToRun `
    -StartWhenAvailable

Register-ScheduledTask -TaskName 'Sehatly Demo Start' `
    -Action $startAction -Trigger $startTrigger -Settings $settings -Force | Out-Null
Register-ScheduledTask -TaskName 'Sehatly Demo Stop' `
    -Action $stopAction -Trigger $stopTrigger -Settings $settings -Force | Out-Null

Write-Host "Jadwal dibuat: Senin-Jumat $Mulai - $Selesai."
Write-Host 'Cek di Task Scheduler: Sehatly Demo Start / Sehatly Demo Stop.'
Write-Host 'Kalau laptop mati saat jam mulai, jalankan MULAI-DEMO.cmd manual.'
