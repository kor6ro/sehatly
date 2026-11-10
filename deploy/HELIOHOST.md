# Alternatif no-card: HelioHost Johnny Free (Plesk, PHP 8.4)

HelioHost adalah nonprofit yang menyediakan shared hosting gratis:

- **tanpa kartu kredit**
- PHP 8.4 / 8.5, Plesk, MariaDB, SFTP/file manager
- 1 GB storage, unlimited bandwidth
- always-on (bukan free tier yang tidur)
- 5 domain

Kekurangan: signup **Johnny Free** cepat penuh (rebutan), dan `exec()`/SSH
dibatasi. Karena itu bundle deploy sudah berisi `vendor/` dan `.env`, jadi
tidak perlu Composer/artisan di server.

## 1. Daftar

1. Buka <https://heliohost.org/signup/>.
2. Pilih **Johnny — Free**.
3. Saat diminta domain, pilih **HelioHost subdomain** (jangan domain sendiri),
   misalnya `sehatly-api`. Nanti alamatnya seperti:
   `https://sehatly-api.heliohost.us`
4. Selesaikan pendaftaran. Tidak ada kartu.

> Kalau slot Johnny penuh, coba lagi beberapa saat. Alternatif no-card lain:
> InfinityFree / AwardSpace (PHP 8.3, lebih terbatas), atau minta panitia lomba
> apakah menyediakan server.

## 2. Siapkan Plesk

1. Login <https://heliohost.org/login> → **Plesk**.
2. **Websites & Domains → [domain] → PHP** → pilih **8.4**.
   Perubahan PHP butuh sampai 2 jam (Apache restart).
3. Pastikan ekstensi ini aktif (biasanya sudah): `pdo_mysql`, `mbstring`,
   `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `intl`, `zip`.

## 3. Upload API

Bundle ada di:

```text
deploy/dist/sehatly-alwaysdata.zip  (10 MB)
```

1. Plesk → **Files** → masuk ke `httpdocs` (atau folder aplikasi).
2. Upload `sehatly-alwaysdata.zip`.
3. Klik kanan → **Extract**.
4. Pastikan struktur menjadi:
   ```text
   httpdocs/
     app/
     bootstrap/
     config/
     database/
     public/
     resources/
     routes/
     storage/
     vendor/
     artisan
     .env
     composer.json
   ```

## 4. Document root

Plesk harus mengarah ke folder `public/`.

- Kalau ada **Websites & Domains → Document Root**, set ke
  `httpdocs/public`.
- Kalau tidak ada, gunakan **Laravel Toolkit** di Plesk: install Laravel
  (toolkit otomatis mengatur document root), lalu timpa file aplikasinya
  dengan isi zip ini (kecuali folder `public` tetap sebagai docroot).
- Jangan arahkan browser ke `httpdocs/` langsung; file `.env` bisa terbaca.

## 5. Permission

Di Plesk File Manager, set writable:

```text
storage/
bootstrap/cache/
```

Kalau ada opsi permission, gunakan `775`. Minimal:

```text
storage/app/public
storage/framework/cache
storage/framework/sessions
storage/framework/views
storage/logs
bootstrap/cache
```

## 6. .env

Zip sudah berisi `.env` dari `deploy/.env.render.ready` (APP_KEY, NIK, TiDB,
Pusher, CORS). Satu hal yang perlu disesuaikan:

```text
APP_URL=https://<subdomain>.heliohost.us
```

Ganti dengan alamat HelioHost yang diberikan. `APP_URL` dipakai untuk URL
gambar/aset; API/login tetap jalan walau ini belum diubah.

`MYSQL_ATTR_SSL_CA` diisi `/etc/ssl/certs/ca-certificates.crt`. Kalau koneksi
TiDB gagal, cek path CA di server (HelioHost berbasis RPM kadang memakai
`/etc/pki/tls/certs/ca-bundle.crt`) atau upload `ca.pem` dari TiDB dan arahkan
ke path file itu.

## 7. Tes

1. Buka `https://<subdomain>.heliohost.us/up` → harus `200`.
2. Buka `https://<subdomain>.heliohost.us/api/v1/dokter` → JSON.
3. Kalau 500:
   - Plesk → Logs / error log,
   - cek PHP version 8.4,
   - cek permission `storage` & `bootstrap/cache`,
   - cek `APP_KEY`, `NIK_CIPHER_KEY`, `DB_*`, CA path.

## 8. Arahkan frontend Pages ke API baru

Karena API sekarang memakai subdomain HelioHost, build ulang Pages dengan:

```text
VITE_API_ORIGIN=https://<subdomain>.heliohost.us
VITE_BROADCAST_DRIVER=pusher
VITE_PUSHER_APP_KEY=<key>
VITE_PUSHER_CLUSTER=ap1
VITE_PUSHER_FORCE_TLS=true
```

Lalu deploy ulang:

```powershell
npm run build
wrangler pages deploy frontend/dist --project-name sehatly-web --branch main
```

CORS di `.env` sudah berisi origin portal (`https://sehatly.k6devs.my.id`,
`https://admin...`, dst). Alamat API sendiri **tidak** perlu masuk CORS.

## 9. DNS portal

Tambahkan 7 CNAME Pages di Cloudflare (proxy Proxied):

| Name | Target |
| --- | --- |
| `sehatly` | `sehatly-web.pages.dev` |
| `admin.sehatly` | `sehatly-web.pages.dev` |
| `dokter.sehatly` | `sehatly-web.pages.dev` |
| `apotek.sehatly` | `sehatly-web.pages.dev` |
| `kurir.sehatly` | `sehatly-web.pages.dev` |
| `marketing.sehatly` | `sehatly-web.pages.dev` |
| `www.sehatly` | `sehatly-web.pages.dev` |

Tidak perlu `api.sehatly`; API memakai subdomain HelioHost.

## Catatan lomba

- Data tetap dummy; `APP_ENV=local`.
- HelioHost free bisa suspend jika beban berlebihan; API demo kecil, aman.
- Login ke HelioHost minimal sekali setiap 30 hari agar akun tidak dianggap
  tidak aktif.
- Kalau `exec()` dibutuhkan untuk artisan, jangan; bundle sudah siap tanpa itu.
