# Demo Tunnel sementara (sebelum Heroku/Azure siap)

Tunnel ini membuat API Laravel di laptop bisa diakses publik di:

```text
https://api.k6devs.my.id
```

Frontend Pages sudah di-build dengan alamat API itu, jadi tidak perlu rebuild
selama host API tetap sama.

> `api.sehatly.k6devs.my.id` **tidak dipakai** karena itu sub-subdomain dan
> tidak tercakup Universal SSL Cloudflare gratis. API memakai host satu level
> `api.k6devs.my.id`.

## Menyalakan

Klik dua kali:

```text
deploy/MULAI-DEMO.cmd
```

Atau:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/mulai-demo.ps1
```

Script membuka dua jendela:

1. `php artisan serve` di `http://127.0.0.1:8000`
2. `cloudflared tunnel run sehatly-api`

Tunggu sampai muncul `API publik : 200`. Selama dua jendela itu terbuka,
juri bisa mengakses API.

## Mematikan

Klik dua kali:

```text
deploy/HENTIKAN-DEMO.cmd
```

Atau:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/hentikan-demo.ps1
```

Setelah dimatikan, `https://api.k6devs.my.id` tidak bisa diakses. Laptop boleh
sleep/dimatikan.

## Hanya jam kerja

Default manual: nyalakan saat mulai kerja, matikan saat selesai. Kalau ingin
otomatis Senin–Jumat:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/jadwalkan-demo.ps1 -Mulai 08:00 -Selesai 17:00
```

Task Scheduler akan membuat `Sehatly Demo Start` dan `Sehatly Demo Stop`.
Kalau laptop mati saat jam mulai, task tidak jalan; jalankan
`MULAI-DEMO.cmd` manual.

## Login demo

- Admin: `081900000001` / `Admin#Sehatly2026`
- Pasien: `081000000001` (OTP otomatis; klik Verifikasi)

Mode `APP_ENV=local` membuat OTP muncul di respons dan diisi otomatis oleh UI.
Jangan pakai data pasien asli.

## Saat Heroku/Azure siap

1. Deploy API ke Heroku/Azure.
2. Pastikan `/up` di host baru `200`.
3. Ganti CNAME Cloudflare `api.k6devs.my.id` dari tunnel ke target Heroku/Azure.
4. Stop tunnel. Frontend tidak perlu di-build ulang karena host API tetap sama.

## Cek cepat

- `https://api.k6devs.my.id/up` → 200
- `https://sehatly-web.pages.dev` → landing
- Admin login → dashboard
- Chat konsultasi → Pusher (lihat WS di DevTools)
