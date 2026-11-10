# Deploy API ke Heroku (GitHub Student Pack, $13/bulan)

Heroku adalah jalur paling sederhana untuk Laravel:

- PHP buildpack resmi, Composer otomatis
- selalu hidup dengan **Basic dyno** ($7/bulan)
- kredit mahasiswa **$13/bulan selama 24 bulan** (total $312)
- custom domain + SSL otomatis
- tidak perlu Docker

Kalau Heroku tetap meminta kartu, pakai **Azure for Students** (lihat bagian
akhir; Azure menyatakan *no credit card required*).

---

## 1. Klaim kredit

1. Buka <https://education.github.com/pack>.
2. Cari **Heroku** → **Get access** / klaim offer.
3. Login/daftar Heroku dengan akun GitHub yang sama.
4. Pastikan kredit **$13/month** muncul di Heroku → Billing.

Kalau Heroku meminta kartu sebelum mengizinkan app, jangan lanjut; pakai Azure.

## 2. Siapkan app Heroku

Install Heroku CLI, lalu:

```powershell
heroku login
heroku create sehatly-api
```

Repo sudah punya `Procfile`:

```text
web: vendor/bin/heroku-php-apache2 public/
```

Heroku otomatis memakai PHP buildpack karena ada `composer.json`.

## 3. Config vars

Heroku → app → **Settings → Config Vars → Reveal Config Vars → Bulk edit**.
Tempel isi `deploy/.env.render.ready`, dengan perubahan:

- **hapus** `PORT=10000` (Heroku mengatur PORT sendiri)
- `LOG_CHANNEL=stderr` (biar log masuk ke Heroku)
- `TRUSTED_PROXIES=*` (Heroku router)
- `APP_URL=https://api.k6devs.my.id`
- `APP_ENV=local`, `APP_DEBUG=false`
- `DB_*` TiDB, `PUSHER_*`, `CORS_ALLOWED_ORIGINS` — biarkan seperti file
- `MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt`

Atau lewat CLI (hati-hati secret muncul di shell history):

```powershell
heroku config:set APP_ENV=local APP_DEBUG=false
heroku config:set APP_KEY="..." NIK_CIPHER_KEY="..."
heroku config:set DB_HOST="..." DB_PORT=4000 DB_DATABASE=sehatly
heroku config:set DB_USERNAME="..." DB_PASSWORD="..."
heroku config:set BROADCAST_CONNECTION=pusher
heroku config:set PUSHER_APP_ID="..." PUSHER_APP_KEY="..." PUSHER_APP_SECRET="..."
heroku config:set CORS_ALLOWED_ORIGINS="https://sehatly.k6devs.my.id,..."
```

**Penting:** config vars harus sudah ada **sebelum** push pertama, karena
`composer install` menjalankan `php artisan package:discover`, dan
`PenjagaPengirimanProduksi` butuh `APP_ENV=local` + `OTP_DRIVER=log`.

## 4. Deploy

```powershell
git push heroku main
heroku ps:scale web=1:basic
heroku open
```

Cek:

```text
https://sehatly-api.herokuapp.com/up
```

Harus `200`.

## 5. Custom domain

```powershell
heroku domains:add api.k6devs.my.id
heroku domains
```

Heroku memberi target CNAME (`...herokudns.com`). Di Cloudflare:

| Type | Name | Target | Proxy |
| --- | --- | --- | --- |
| CNAME | `api.sehatly` | target Heroku | **DNS only** |

Tunggu sertifikat ACM Heroku terbit. Setelah aktif, cek:

```text
https://api.k6devs.my.id/up
```

## 6. Setelah API hidup

1. Rebuild Pages dengan:
   ```text
   VITE_API_ORIGIN=https://api.k6devs.my.id
   ```
   Lalu `npm run build` + `wrangler pages deploy frontend/dist --project-name sehatly-web --branch main`.
2. Tambahkan 7 CNAME portal Pages.
3. Login admin/pasien, tes chat Pusher.

## 7. Kalau Heroku minta kartu: Azure for Students

Azure for Students dari GitHub Student Pack:

- **$100 credit**
- **tanpa kartu kredit**
- App Service / Container Apps, custom domain, SSL

Langkah besarnya:

1. Klaim **Microsoft Azure** di GitHub Student Pack.
2. Daftar **Azure for Students** dengan email kampus; verifikasi via GitHub.
3. Install Azure CLI, `az login`.
4. Deploy container dari Dockerfile ke **Azure Container Apps** atau **App Service for Containers**.
5. Set env yang sama dengan `deploy/.env.render.ready`, port `10000`,
   health check `/up`.

Kalau sampai langkah ini, kirim pesan; aku bantu susun perintah `az`-nya.

## Catatan

- Heroku Basic dyno tidak tidur. Jangan pakai Eco/F1 yang tidur.
- Filesystem ephemeral; upload hilang saat dyno restart.
- Scheduler tidak berjalan; demo tidak butuh.
- Jangan menyimpan data pasien asli.
