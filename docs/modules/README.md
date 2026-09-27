# Ringkasan modul

Satu berkas per modul. Setiap berkas menyatakan statusnya sendiri di baris
pertama, dan tidak ada satu pun yang mendokumentasikan endpoint yang belum ada.

| modul | berkas | status | endpoint |
|---|---|---|---|
| 1 - ringkasan | [`modul-1-ringkasan.md`](modul-1-ringkasan.md) | selesai | 22 |
| 1 - auth | [`modul-1-auth.md`](modul-1-auth.md) | selesai | 8 |
| 1 - pasien | [`modul-1-pasien.md`](modul-1-pasien.md) | selesai | 11 |
| 1 - dokter | [`modul-1-dokter.md`](modul-1-dokter.md) | selesai | 3 |
| 2 - jadwal dan booking | [`modul-2-jadwal-booking.md`](modul-2-jadwal-booking.md) | **belum lengkap** | **0** |

Modul 2 tidak sengaja diselesaikan pada berkas mana pun. Endpoint jadwal,
slot, dan booking dibangun pada todo 27, dan sisi React-nya pada todo 28.

## Angka

Jumlah route di bawah `/api/v1` adalah fakta yang harus dibaca dari route table,
bukan dari dokumen ini:

```powershell
& "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" artisan route:list --path=api/v1
```

Rencana menyebut "13 endpoint Modul 1", dan angka itu tidak cocok dengan
rencana itu sendiri maupun dengan implementasi. `modul-1-ringkasan.md` bagian
"Aritmetika 13 vs 22" menghitung ulang dan menyimpulkan bahwa yang
otoritatif adalah route table. Dokumen-dokumen di direktori ini memakai angka
yang diukur, bukan angka yang direncanakan.
