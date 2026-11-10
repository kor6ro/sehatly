# Alternatif Render tanpa kartu: Northflank Sandbox Free

Render menolak kartu? Northflank punya **Sandbox Free** yang tidak meminta kartu:

- 2 service, 2 job, 1 database
- sampai **$20/bulan resource gratis** di Northflank Cloud
- **always on, tidak tidur**
- Dockerfile, GitHub, custom domain, TLS otomatis
- 1 service Laravel kecil cukup (0.5 vCPU / 1 GB ≈ $12/bulan, masih di bawah $20)

Ini pengganti Render untuk API. Frontend tetap di Cloudflare Pages, database tetap TiDB, realtime tetap Pusher.

> `render.yaml` tidak dipakai di Northflank. Env diisi manual dari
> `deploy/.env.render.ready` (nama file hanya warisan; isinya tetap berlaku).

---

## 1. Buat akun dan project

1. Buka <https://northflank.com> → **Sign up** (bisa pakai GitHub).
2. Buat **Project** baru: nama `sehatly`.
3. Pilih region **Asia Southeast** (`asia-southeast`) — paling dekat ke Indonesia.

## 2. Buat service dari GitHub

1. Di project → **Create service** → **Build from Git repository**.
2. Hubungkan GitHub, pilih repo `kor6ro/sehatly`, branch `main`.
3. Build type: **Dockerfile**.
   - Dockerfile path: `Dockerfile`
   - Build context: `/` (root repo)
4. Service type: **Deployment** (web service), 1 instance.
5. Compute: pilih plan terkecil yang muat (0.5 vCPU / 1 GB). Jangan pilih GPU.

## 3. Port dan health check

1. Networking → **Add port**:
   - Port: `10000`
   - Protocol: `HTTP`
   - Public: ya
2. Health check:
   - Path: `/up`
   - Port: `10000`
3. Tambahkan environment variable:

```text
PORT=10000
```

Entrypoint container sudah membaca `PORT`; tanpa ini default-nya juga 10000.

## 4. Environment variables

Buka file:

```powershell
notepad C:\laragon\www\sehatly\deploy\.env.render.ready
```

Copy **semua** baris ke Northflank → **Environment** / **Runtime variables**.
Northflank mendukung **upload file `.env`** di editor runtime variables: upload
`deploy/.env.render.ready` langsung, lalu pastikan `PORT=10000` ikut masuk.
Kalau tidak ada tombol upload, gunakan mode JSON atau tempel satu per satu.
Yang paling penting:

```text
APP_ENV=local
APP_DEBUG=false
APP_URL=https://api.k6devs.my.id
APP_KEY=...
NIK_CIPHER_KEY=...
DB_HOST=...
DB_PORT=4000
DB_DATABASE=sehatly
DB_USERNAME=...
DB_PASSWORD=...
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=...
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...
PUSHER_APP_CLUSTER=ap1
CORS_ALLOWED_ORIGINS=...
TRUSTED_PROXIES=*
```

Jangan menambahkan `PORT` dua kali; kalau sudah ada, biarkan satu.

> Kalau UI Northflank punya tombol **Import .env** / **Bulk add**, gunakan itu untuk
> menempel seluruh isi file. Kalau tidak, tambahkan satu per satu.

## 5. Deploy

1. Klik **Deploy**.
2. Tunggu build Docker (Composer + extension PHP).
3. Buka **Logs**. Cari `sehatly: migrate` dan `Apache` listening.
4. Buka URL `*.code.run` yang diberikan Northflank + `/up` → harus `200`.

Kalau `/up` 500:
- cek `APP_KEY` dan `NIK_CIPHER_KEY` terisi,
- cek `DB_*` dan `MYSQL_ATTR_SSL_CA`,
- cek log migrasi (TiDB CHECK constraint).

## 6. Custom domain API

1. Northflank → **Domains** (level project/team) → **Add domain**:
   `api.k6devs.my.id`.
2. Northflank memberi target CNAME + record verifikasi (bentuknya `*.code.run`
   atau TXT/CNAME).
3. Di Cloudflare → zone `k6devs.my.id` → **DNS → Records → Add record**:
   - Type: **CNAME**
   - Name: `api.sehatly`
   - Target: dari Northflank
   - Proxy: **DNS only** (abu-abu) saat verifikasi
4. Klik **Verify** di Northflank. Setelah domain terverifikasi, link domain ke
   port `10000` pada service.
5. Setelah sertifikat Northflank terbit, kamu boleh mengaktifkan proxy
   Cloudflare dengan SSL/TLS mode **Full (Strict)**. Kalau ragu, biarkan
   **DNS only**; API tetap HTTPS dari Northflank.

> Cloudflare proxy **harus dimatikan saat verifikasi** domain Northflank.
> Kalau proxy sudah terlanjur aktif, matikan, verify ulang, baru nyalakan.

## 7. Setelah API hidup

1. Cek `https://api.k6devs.my.id/up` → 200.
2. Tambahkan CNAME portal Pages (kalau belum):

| Type | Name | Target | Proxy |
|---|---|---|---|
| CNAME | `sehatly` | `sehatly-web.pages.dev` | Proxied |
| CNAME | `admin.sehatly` | `sehatly-web.pages.dev` | Proxied |
| CNAME | `dokter.sehatly` | `sehatly-web.pages.dev` | Proxied |
| CNAME | `apotek.sehatly` | `sehatly-web.pages.dev` | Proxied |
| CNAME | `kurir.sehatly` | `sehatly-web.pages.dev` | Proxied |
| CNAME | `marketing.sehatly` | `sehatly-web.pages.dev` | Proxied |
| CNAME | `www.sehatly` | `sehatly-web.pages.dev` | Proxied |

3. Login admin di `https://admin.sehatly.k6devs.my.id`.

---

## Kalau Northflank juga bermasalah

### Alwaysdata Free (PHP native, tanpa Docker)

- <https://www.alwaysdata.com> → Free (1 GB disk, 256 MB RAM).
- Buat site PHP 8.4, upload repo (tanpa `frontend/` dan `node_modules`), jalankan
  `composer install --no-dev --optimize-autoloader` via SSH.
- Document root: `www/sehatly/public`.
- Set env yang sama di panel Alwaysdata; `MYSQL_ATTR_SSL_CA` bisa diarahkan ke
  file `ca.pem` yang di-upload.
- Tidak ada Docker/worker; API tetap jalan, Pusher tetap dipakai.

### Cloudflare Tunnel ke laptop (paling cepat)

Cocok kalau juri mengecek pada waktu tertentu dan laptop bisa tetap menyala.

1. Install `cloudflared` di Windows.
2. `cloudflared tunnel login` → pilih zone `k6devs.my.id`.
3. `cloudflared tunnel create sehatly-api`.
4. Buat `config.yml`:
   ```yaml
   tunnel: <uuid>
   credentials-file: C:\Users\axioo\.cloudflared\<uuid>.json
   ingress:
     - hostname: api.k6devs.my.id
       service: http://localhost:8000
     - service: http_status:404
   ```
5. `cloudflared tunnel route dns sehatly-api api.k6devs.my.id`
6. Jalankan API dengan env dari `deploy/.env.render.ready`:
   ```powershell
   php artisan serve --host=127.0.0.1 --port=8000
   ```
7. `cloudflared tunnel run sehatly-api`.

Kekurangan: laptop harus nyala, tidak boleh sleep, dan koneksi internet stabil.
