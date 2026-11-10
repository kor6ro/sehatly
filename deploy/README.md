# Deploy gratis: 6 portal di Cloudflare Pages + API Render + TiDB + Pusher

Runbook ini memasang **mode demo** dari Sehatly:

```
sehatly.k6devs.my.id            -> Portal Pasien
admin.sehatly.k6devs.my.id      -> Portal Admin
dokter.sehatly.k6devs.my.id     -> Portal Dokter
apotek.sehatly.k6devs.my.id     -> Portal Apoteker
kurir.sehatly.k6devs.my.id      -> Portal Kurir
marketing.sehatly.k6devs.my.id  -> Portal Marketing
                                   (satu build SPA di Cloudflare Pages)

api.sehatly.k6devs.my.id        -> Laravel API -> TiDB Cloud (gratis)
ws                              -> Pusher Channels (gratis)
```

Kode SPA sudah memilih portal dari hostname (`frontend/src/lib/portal.ts`),
jadi keenam hostname hanya perlu menyajikan **index.html yang sama**. Tidak ada
build terpisah per portal.

> **Ini mode demo, bukan produksi.** `APP_ENV=local` sengaja dipakai karena
> `PenjagaPengirimanProduksi` menolak boot dengan `OTP_DRIVER=log`, dan mode ini
> juga yang membuat kode OTP muncul di respons login. Jangan pernah memasukkan
> data pasien asli, dan jangan memakai akun demo untuk apa pun yang sensitif.

> **Render menolak kartu?** Ada alternatif tanpa kartu:
> lihat [`deploy/NORTHFLANK.md`](NORTHFLANK.md). Northflank Sandbox Free
> mendukung Dockerfile, always-on, custom domain, dan tidak meminta kartu.

---

## 0. Yang perlu disiapkan

- [ ] Domain aktif (contoh `sehatly.k6devs.my.id`). DNS subdomain dikelola di panel my.id; Cloudflare dipakai untuk Pages, bukan nameserver.
- [ ] Repo sudah di GitHub (Render dan Pages men-deploy dari Git).
- [ ] PHP 8.4 + Composer + MySQL berjalan di Laragon untuk seed awal.
- [ ] Akun gratis: Cloudflare, Render, TiDB Cloud, Pusher.

Waktu realistis: 45–90 menit, kebanyakan menunggu build pertama.

### Batas free tier yang perlu diketahui

| Layanan | Batas | Akibat |
|---|---|---|
| Render free | tidur setelah 15 menit tanpa trafik, 512 MB, disk ephemeral | request pertama bisa ±50 detik; upload hilang saat restart |
| Render free | 750 jam/bulan | cukup untuk satu web service |
| TiDB Starter | 5 GiB, 50 juta RU/bulan | cukup untuk demo |
| Pusher free | 100 koneksi bersamaan, 200 ribu pesan/hari | cukup untuk demo |
| Cloudflare Pages | 100 custom domain per project, bandwidth tak terbatas | 6 portal aman |

Scheduler Laravel (`booking:kadaluarsa`, `pengingat:kirim`) **tidak berjalan**
di Render free karena tidak ada cron/worker gratis. Reminder dan kedaluarsa
booking tidak otomatis; itu batas yang diterima di mode demo.

---

## 1. TiDB Cloud Starter (database)

1. Daftar di <https://tidbcloud.com> (tanpa kartu).
2. **Create Cluster → Starter → MySQL-compatible**.
3. Region: **AWS Singapore (`ap-southeast-1`)** — paling dekat ke Indonesia.
4. Setelah cluster jadi, buka **Connect** dan catat:
   - `host` (contoh `gateway01.ap-southeast-1.prod.aws.tidbcloud.com`)
   - `port` (`4000`)
   - `username` (contoh `3pTAoNNegb47Uc8.root` — **prefix acak wajib ikut**)
   - `password`
   - tombol **Download CA cert** (simpan `ca.pem`)
5. Buka **SQL Editor**, buat database:

```sql
CREATE DATABASE sehatly CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Migrasi `ulasan_dokter` memasang tiga CHECK constraint. TiDB mematikan
-- fitur ini secara default; nyalakan dulu supaya `php artisan migrate` tidak
-- gagal atau diam-diam melewatinya.
SET GLOBAL tidb_enable_check_constraint = ON;
```

Kalau `SET GLOBAL` ditolak, jalankan migrasi dari langkah 5 dengan
`SET SESSION tidb_enable_check_constraint = ON` di SQL Editor terpisah, atau
lewati tiga CHECK tersebut (aplikasi tetap memvalidasi rating 1–5).

### Catat nilai untuk Render

| Env | Nilai |
| --- | --- |
| `DB_HOST` | host dari tombol Connect |
| `DB_PORT` | `4000` |
| `DB_DATABASE` | `sehatly` |
| `DB_USERNAME` | `<prefix>.root` |
| `DB_PASSWORD` | password cluster |
| `MYSQL_ATTR_SSL_CA` | `/etc/ssl/certs/ca-certificates.crt` (default image) |

TiDB mewajibkan TLS. CA bundle Debian di image cukup untuk handshake
terenkripsi; kalau koneksi ditolak, unggah `ca.pem` sebagai **Secret File** di
Render di `/etc/secrets/tidb-ca.pem` lalu set `MYSQL_ATTR_SSL_CA` ke path itu.

---

## 2. Pusher Channels (realtime)

1. Daftar di <https://pusher.com> (tanpa kartu).
2. **Channels → Create app**:
   - name: `sehatly`
   - cluster: **`ap1` (Singapore)**
   - frontend: React
3. Buka **App Keys**, catat:
   - `app_id`
   - `key`
   - `secret`
   - `cluster` (`ap1`)

Nilai `key` aman dipublikasikan ke browser; `secret` hanya untuk Render.

---

## 3. Render (API Laravel)

### 3.1 Deploy dari blueprint

1. Daftar di <https://render.com> dengan akun GitHub.
2. **New → Blueprint**, pilih repo Sehatly.
3. Render membaca `render.yaml`. `APP_URL` dan `CORS_ALLOWED_ORIGINS` sudah
   terisi untuk `sehatly.k6devs.my.id`; isi nilai `sync: false` yang tersisa:

| Env | Nilai |
| --- | --- |
| `APP_KEY` | hasil `php artisan key:generate --show` |
| `NIK_CIPHER_KEY` | `base64:` + 32 byte acak |
| `DB_HOST` | dari TiDB |
| `DB_USERNAME` | `<prefix>.root` |
| `DB_PASSWORD` | dari TiDB |
| `PUSHER_APP_ID` | dari Pusher |
| `PUSHER_APP_KEY` | dari Pusher |
| `PUSHER_APP_SECRET` | dari Pusher |

Kalau kamu memakai layout datar (`k6devs.my.id`) atau domain lain, ubah
`APP_URL` dan `CORS_ALLOWED_ORIGINS` di `render.yaml` sebelum Apply.

Generate dua key dari Laragon:

```powershell
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe artisan key:generate --show
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
```

`CORS_ALLOWED_ORIGINS` — daftar origin portal (sudah diisi domainmu), **tanpa** trailing
slash, pisahkan dengan koma:

```text
https://sehatly.k6devs.my.id,https://www.sehatly.k6devs.my.id,https://admin.sehatly.k6devs.my.id,https://dokter.sehatly.k6devs.my.id,https://apotek.sehatly.k6devs.my.id,https://kurir.sehatly.k6devs.my.id,https://marketing.sehatly.k6devs.my.id
```

4. Klik **Apply**. Build pertama memasang PHP extension + Composer; tunggu
   sampai status **Live**.
5. Buka `https://<nama-service>.onrender.com/up` — harus `200 OK`.

Kalau build/deploy gagal, buka **Logs**. Kesalahan umum ada di bagian
Troubleshooting.

### 3.2 Custom domain API

1. Render → service → **Settings → Custom Domains → Add Custom Domain**:
   `api.sehatly.k6devs.my.id`.
2. Render memberi target CNAME (contoh `sehatly-api.onrender.com`).
3. Di panel DNS `my.id`, buat record:

| Type | Name | Value |
| --- | --- | --- |
| CNAME | `api.sehatly` | `sehatly-api.onrender.com` |

Kalau panel meminta nama lengkap, isi `api.sehatly.k6devs.my.id`. Jangan
mengaktifkan proxy Cloudflare untuk record ini — zone-nya bukan milik
Cloudflare, dan Render harus menerbitkan sertifikatnya sendiri. Tunggu status
certificate **Verified** di Render.

> API tidak butuh wildcard. Hanya `api.sehatly.k6devs.my.id`.

### 3.3 Seed data demo dari Laragon

Render free tidak punya shell, jadi seed dijalankan dari mesin lokal yang bisa
mencapai TiDB. **Jalankan sebelum atau sesudah deploy; `migrate --force` di
container hanya no-op kalau tabel sudah ada.**

Cara cepat (setelah `deploy/.env.deploy` diisi):

```powershell
powershell -ExecutionPolicy Bypass -File deploy/siapkan-demo.ps1
```

Script itu melakukan `migrate:fresh --seed` ke TiDB, lalu membuat
`deploy/.env.render.ready` dan `deploy/.env.pages.ready` berisi semua env yang
tinggal kamu copy ke dashboard. Tidak ada secret yang dicetak ke layar.

Atau manual:

```powershell
$php = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe"

$env:APP_ENV          = "local"
$env:DB_CONNECTION    = "mysql"
$env:DB_HOST          = "gateway01.ap-southeast-1.prod.aws.tidbcloud.com"
$env:DB_PORT          = "4000"
$env:DB_DATABASE      = "sehatly"
$env:DB_USERNAME      = "3pTAoNNegb47Uc8.root"
$env:DB_PASSWORD      = "password-tidb"
$env:MYSQL_ATTR_SSL_CA = "C:\path\ke\ca.pem"   # opsional; coba tanpa ini dulu

& $php artisan migrate:fresh --seed --force
```

Setelah selesai, hapus variabel supaya Laragon kembali memakai MySQL lokal:

```powershell
Remove-Item Env:APP_ENV,Env:DB_CONNECTION,Env:DB_HOST,Env:DB_PORT,Env:DB_DATABASE,Env:DB_USERNAME,Env:DB_PASSWORD,Env:MYSQL_ATTR_SSL_CA -ErrorAction SilentlyContinue
```

Akun demo ada di `testaccount.md`; yang paling penting:

| Portal | Nomor | Sandi |
| --- | --- | --- |
| Admin | `081900000001` | `Admin#Sehatly2026` |
| Superadmin | `081900000002` | `Superadmin#Sehatly2026` |
| Pasien | `081000000001` | `Demo#Pasien2026` |
| Dokter | `081000000002` | `Demo#Dokter2026` |
| Apoteker | `081000000003` | `Demo#Apoteker2026` |
| Kurir | `081000000004` | `Demo#Kurir2026` |
| Marketing | `081000000005` | `Demo#Marketing2026` |

Login staf memakai nomor + sandi, lalu OTP. Di mode demo kode OTP ikut di
respons `POST /auth/masuk` dan otomatis diisi oleh halaman login. Dialog pasien
memakai kode yang sama dan menampilkannya di kotak OTP, jadi cukup klik
**Verifikasi**.

---

## 4. Cloudflare Pages (hosting SPA)

### 4.1 DNS: tambahkan CNAME di panel my.id

`k6devs.my.id` adalah subdomain gratis dari `my.id`, jadi **nameserver apex
`my.id` bukan milikmu** dan tidak perlu dipindah ke Cloudflare. Yang kamu
kelola adalah record DNS untuk `k6devs.my.id` di panel penyedia `my.id`
(biasanya ada menu DNS Management).

Cloudflare tetap dipakai untuk **Pages**, bukan untuk DNS zone ini. Pages
menerima custom domain lewat CNAME, jadi tidak perlu mengubah nameserver.

> Kalau panel my.id tidak mengizinkan nama record bertitik (`admin.sehatly`),
> gunakan layout datar `admin.k6devs.my.id` dan pasien di `www.k6devs.my.id`
> (lihat catatan di akhir bagian 4.3). Kode mendukung keduanya, tetapi layout
> bertingkat lebih aman karena tidak butuh CNAME di apex.

### 4.2 Buat project Pages

1. **Workers & Pages → Create → Pages → Connect to Git**.
2. Pilih repo Sehatly.
3. Isi:

| Field | Nilai |
| --- | --- |
| Project name | `sehatly-web` |
| Production branch | `main` |
| Framework preset | `Vite` |
| Root directory | `frontend` |
| Build command | `npm ci && npm run build` |
| Build output directory | `dist` |

4. **Environment variables (Production)** — contek `deploy/env.pages.example`:

```text
NODE_VERSION=22
VITE_API_ORIGIN=https://api.sehatly.k6devs.my.id
VITE_BROADCAST_DRIVER=pusher
VITE_PUSHER_APP_KEY=<key dari Pusher>
VITE_PUSHER_CLUSTER=ap1
VITE_PUSHER_FORCE_TLS=true
```

`VITE_*` di-inline saat build. Setiap kali env berubah, klik **Retry deployment**
atau push commit baru — bukan sekadar restart.

5. **Save and Deploy**. Setelah selesai, buka `https://sehatly-web.pages.dev`
   dan pastikan landing pasien tampil.

### 4.3 Tambahkan custom domain + CNAME

1. Di Pages → project → **Custom domains → Set up a domain**, masukkan:

```text
sehatly.k6devs.my.id
admin.sehatly.k6devs.my.id
dokter.sehatly.k6devs.my.id
apotek.sehatly.k6devs.my.id
kurir.sehatly.k6devs.my.id
marketing.sehatly.k6devs.my.id
```

Opsional: `www.sehatly.k6devs.my.id` (kalau ingin `www` juga berfungsi).

2. Cloudflare akan menampilkan target CNAME (biasanya
   `sehatly-web.pages.dev`) dan mungkin TXT verifikasi.
3. Di panel DNS `my.id`, buat record berikut. Nama di kolom Name relatif
   terhadap `k6devs.my.id`:

| Type | Name | Value |
| --- | --- | --- |
| CNAME | `sehatly` | `sehatly-web.pages.dev` |
| CNAME | `admin.sehatly` | `sehatly-web.pages.dev` |
| CNAME | `dokter.sehatly` | `sehatly-web.pages.dev` |
| CNAME | `apotek.sehatly` | `sehatly-web.pages.dev` |
| CNAME | `kurir.sehatly` | `sehatly-web.pages.dev` |
| CNAME | `marketing.sehatly` | `sehatly-web.pages.dev` |
| CNAME | `api.sehatly` | `sehatly-api.onrender.com` |

Kalau panel meminta nama lengkap, isi `sehatly.k6devs.my.id`,
`admin.sehatly.k6devs.my.id`, dst. Untuk API, target CNAME adalah alamat
Render dari langkah 3.2, bukan `sehatly-api.onrender.com` jika nama service-mu
berbeda.

4. Kembali ke Pages dan klik **Verify**. Tunggu status **Active** dan sertifikat
   TLS terbit (beberapa menit). Kalau TLS gagal, cek **CAA records** di panel
   my.id: izinkan `letsencrypt.org`, `pki.goog`, dan `ssl.com` (Cloudflare
   memakai salah satunya untuk menerbitkan sertifikat).
5. Untuk API, tambahkan `api.sehatly.k6devs.my.id` sebagai custom domain di
   Render (bagian 3.2) dan CNAME di panel my.id.

Semua hostname portal menyajikan project Pages yang sama; SPA memilih portal
dari hostname.

> Jangan menambahkan `api.sehatly.k6devs.my.id` ke Pages. Itu milik Render.

**Kalau panel my.id tidak mendukung nama bertitik:** pakai layout datar.
Pasien di `www.k6devs.my.id`, portal staf di `admin.k6devs.my.id` dst. Layout
datar membuat tombol "Beranda" di portal staf mengarah ke `https://k6devs.my.id`,
jadi pasien harus tersedia di host itu juga. Kalau CNAME di `k6devs.my.id`
(apex subdomain) ditolak, layout bertingkat adalah satu-satunya pilihan yang
bersih.

Opsional: buat **Redirect Rule** `www.sehatly.k6devs.my.id/*` →
`https://sehatly.k6devs.my.id/$1` (301) supaya canonical. Kalau tidak, `www`
tetap berfungsi sebagai portal pasien.

---

## 5. Verifikasi

- [ ] `https://api.sehatly.k6devs.my.id/up` → `200`
- [ ] `https://sehatly.k6devs.my.id` → landing pasien
- [ ] `https://admin.sehatly.k6devs.my.id/login` → halaman login admin
- [ ] `https://dokter.sehatly.k6devs.my.id/login` → halaman login dokter
- [ ] `https://apotek.sehatly.k6devs.my.id/login`
- [ ] `https://kurir.sehatly.k6devs.my.id/login`
- [ ] `https://marketing.sehatly.k6devs.my.id/login` dan `/daftar`
- [ ] Login admin berhasil (nomor `081900000001`, sandi `Admin#Sehatly2026`)
- [ ] DevTools → Network: request ke `https://api.sehatly.k6devs.my.id/api/v1/...` 200
- [ ] DevTools → Network → WS: koneksi ke Pusher (`wss://ws-ap1.pusher.com`)
- [ ] Halaman admin **Akun** menampilkan daftar pengguna
- [ ] Buat undangan di **Akun → Undang**, buka URL aktivasi, minta OTP
- [ ] Chat konsultasi mengirim pesan tanpa refresh (Pusher)

Kalau salah satu gagal, cek urutan ini: CORS → token → hostname portal →
build env Pages.

---

## 6. Troubleshooting

### Render: container exit / 500 saat boot

Buka **Logs**. Penyebab yang paling sering:

- `APP_KEY` kosong → isi dengan `base64:...` dari `key:generate --show`.
- `NIK_CIPHER_KEY` kosong → isi `base64:` + 32 byte.
- `Could not resolve host` / `Connection refused` → cek `DB_HOST`, `DB_PORT=4000`,
  dan prefix username TiDB.
- `Access denied` / timeout ke TiDB → pastikan public endpoint cluster aktif dan,
  kalau ada pengaturan IP allowlist, izinkan `0.0.0.0/0` (Render free memakai IP
  keluar yang berubah).
- `SSL connection is required` → set `MYSQL_ATTR_SSL_CA` ke CA bundle atau
  unggah `ca.pem` sebagai Secret File.
- Migrasi gagal di `ulasan_dokter` → `SET GLOBAL tidb_enable_check_constraint = ON;`
- `Refusing to boot: OTP_DRIVER is "log"` → jangan set `APP_ENV=production`
  di mode demo. Biarkan `APP_ENV=local` dari `render.yaml`.

### CORS error di browser

Pesan `No 'Access-Control-Allow-Origin'` berarti origin portal tidak ada di
`CORS_ALLOWED_ORIGINS`. Harus **persis** sama: skema (`https`), host, tanpa
trailing slash, tanpa path. Ubah env di Render → **Manual Deploy → Deploy latest
commit** (perubahan env butuh restart).

### 404 di deep link Pages

Pastikan `frontend/public/_redirects` ikut ter-build (file ini ada di repo) dan
output directory-nya `dist`. Setelah mengubah, purge cache Cloudflare atau
deploy ulang.

### Portal salah / 404 di `/admin/...`

- Buka `https://admin.sehatly.k6devs.my.id`, bukan `https://sehatly.k6devs.my.id/admin`.
- `www.admin.sehatly.k6devs.my.id` tidak dikenali; jangan pakai.
- Setelah menambah custom domain, tunggu status **Active** dan TLS **Active**.

### Pusher tidak connect

- Backend: `BROADCAST_CONNECTION=pusher`, `PUSHER_APP_*` terisi, redeploy.
- Frontend: `VITE_BROADCAST_DRIVER=pusher`, `VITE_PUSHER_APP_KEY` terisi, lalu
  **build ulang** Pages.
- Cluster harus sama di kedua sisi (`ap1`).
- Buka DevTools → Network → WS; kalau `POST /api/broadcasting/auth` 401, token
  sesi tidak terkirim.

### Upload gambar hilang

Render free memakai filesystem ephemeral. Itu bukan bug. Untuk demo, upload
ulang setelah container restart. Solusi permanen: Cloudflare R2 + disk `s3`
(butuh paket `league/flysystem-aws-s3-v3`), di luar cakupan runbook ini.

### Reminder/booking kedaluarsa tidak jalan

Scheduler tidak berjalan di Render free. Kalau perlu, tambahkan cron eksternal
gratis (mis. cron-job.org) ke endpoint yang menjalankan `schedule:run`, atau
pindah ke host yang mendukung worker/cron.

### `npm run build` gagal di Pages

- Root directory harus `frontend`, bukan repo root.
- `npm ci` membutuhkan `package-lock.json` di `frontend/` (ada).
- `NODE_VERSION=22`.
- Cek log build: kalau ada import yang hilang, jalankan `npm run types:check`
  dan `npm run build` di lokal dulu.

---

## 7. Setelah demo

Kalau nanti dipakai sungguhan:

1. Ganti `APP_ENV=production`, `APP_DEBUG=false`.
2. Isi OTP driver nyata (`fonnte` dengan token, atau gateway resmi) dan push
   (`fcm` + service account).
3. Ganti keempat `PAYMENT_WEBHOOK_SECRET_*` dengan nilai acak.
4. Pindah upload ke R2/S3.
5. Tambahkan worker + scheduler (Render berbayar, Railway, atau VPS).
6. Jangan pakai TiDB free untuk data medis produksi; pindah ke MySQL 8 terkelola
   dengan backup dan enkripsi yang sesuai UU PDP.

---

## 8. File deploy di repo

| File | Gunanya |
| --- | --- |
| `Dockerfile` | image API PHP 8.4 + Apache untuk Render |
| `deploy/entrypoint.sh` | port Render, migrate, config cache |
| `deploy/apache-vhost.conf` | DocumentRoot `public/` + rewrite |
| `deploy/php.ini` | OPcache + limit upload 20 MB |
| `render.yaml` | blueprint service + env |
| `deploy/env.render.example` | checklist env backend |
| `deploy/env.pages.example` | checklist env build frontend |
| `deploy/seed-tidb.ps1` | seed database demo ke TiDB dari Laragon |
| `deploy/siapkan-demo.ps1` | seed + generate env Render/Pages siap copy |
| `frontend/public/_redirects` | SPA fallback di Pages |
| `frontend/public/_headers` | cache asset + header keamanan |
| `config/broadcasting.php` | koneksi `pusher` di samping `reverb` |
| `frontend/src/lib/echo.ts` | pilih Reverb atau Pusher saat build |
