# Modul 1 - Autentikasi (`/api/v1/auth`)

Sepuluh route autentikasi. Semua respons memakai amplop
`{"success","data","message"}`; gagal memakai
`{"success":false,"message","errors":{}}`; kegagalan OTP dan 429 dapat memuat
`meta` (`sisa_percobaan`, `retry_after`).

Aturan utama:

- `POST /auth/login` **tidak** mengembalikan token. Ia hanya mengembalikan OTP.
- Token (`access_token` 50 karakter, `refresh_token` 80 karakter) hanya
  diterbitkan oleh `POST /auth/otp/verify`.
- `POST /auth/refresh` memutar refresh token di dalam transaksi. Memakai ulang
  refresh token lama menghanguskan **semua** refresh token milik pengguna itu
  lalu mengembalikan 401 `"Sesi tidak valid. Silakan masuk kembali."`.
- `POST /auth/otp/resend` menerbitkan kode baru **tanpa kata sandi**, menutup
  kode lama untuk tujuan yang sama, dan menjawab generik (tidak membocorkan
  keberadaan akun).
- `POST /auth/logout` bersifat **per perangkat**: hanya refresh token yang
  dikirim yang dicabut. `POST /auth/logout-all` adalah aksi terpisah yang
  mencabut semua refresh token dan menonaktifkan semua perangkat.
- Nomor telepon dinormalisasi ke bentuk lokal `08...`; `+62812...` dan
  `0812...` adalah akun yang sama.
- Consent wajib UU PDP (`syarat_ketentuan`, `kebijakan_privasi`) diambil saat
  daftar dan dicatat atomik di `persetujuan_pdp`.
- Kode OTP dikembalikan di dalam respons **hanya** saat `APP_ENV=local`.

Nilai `<NO_TELEPON>`, `<KODE_OTP>`, `<ACCESS_TOKEN>`, `<REFRESH_TOKEN>`,
`<EMAIL>`, `<UUID>` di bawah adalah placeholder. Jangan commit nilai asli.

```bash
BASE_URL="http://127.0.0.1:8123/api/v1"
```

## 1. `POST /auth/sign-up` - 201

Membuat `users` (`status=pending_verifikasi`, `tipe=pasien`) beserta baris
`pasien`, mencatat dua baris consent wajib di `persetujuan_pdp`
(`syarat_ketentuan`, `kebijakan_privasi`, dengan versi dari `config/pdp.php` dan
IP permintaan) dalam transaksi yang sama, lalu mengirim OTP
`verifikasi_telepon`. Kedua field consent wajib bernilai `accepted`.

```bash
curl.exe -s -X POST "$BASE_URL/auth/sign-up" \
  -H "Content-Type: application/json" \
  -d '{"nama_lengkap":"<NAMA_LENGKAP>","no_telepon":"<NO_TELEPON>","email":"<EMAIL>","password":"<PASSWORD>","jenis_kelamin":"L","tanggal_lahir":"1990-01-01","tempat_lahir":"Jakarta","alamat_lengkap":"<ALAMAT>","bahasa":"id","persetujuan_syarat_ketentuan":true,"persetujuan_kebijakan_privasi":true}'
```

Respons terverifikasi (HTTP 201):

```json
{"success":true,"data":{"user":{"id":6,"uuid":"<UUID>","nama_lengkap":"<NAMA_LENGKAP>","no_telepon":"<NO_TELEPON>","email":"<EMAIL>","tipe":"pasien","status":"pending_verifikasi","bahasa":"id","foto_profil":null,"telepon_terverifikasi":false,"email_terverifikasi":false,"last_login_at":null,"dibuat_at":"<TIMESTAMP>"},"otp":{"tujuan":"verifikasi_telepon","kedaluwarsa_at":"<TIMESTAMP>","ttl_detik":300,"kode":"<KODE_OTP>"}},"message":"Pendaftaran berhasil. Kode OTP telah dikirim."}
```

Kunci respons: `data.user.{id,uuid,nama_lengkap,no_telepon,email,tipe,status,
bahasa,foto_profil,telepon_terverifikasi,email_terverifikasi,last_login_at,
dibuat_at}`, `data.otp.{tujuan,kedaluwarsa_at,ttl_detik,kode}`.

Badan tidak valid (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"nama_lengkap":["The nama lengkap field must be at least 3 characters."],"no_telepon":["The nomor telepon field format is invalid."],"password":["The password field must be at least 8 characters."],"jenis_kelamin":["The selected jenis kelamin is invalid."],"tanggal_lahir":["The tanggal lahir field must match the format Y-m-d."],"alamat_lengkap":["The alamat lengkap field must be at least 5 characters."]}}
```

Nomor ganda (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"no_telepon":["The nomor telepon has already been taken."]}}
```

## 2. `POST /auth/otp/verify` - 200

Menukar OTP menjadi pasangan token. Untuk pendaftaran, `tujuan` adalah
`verifikasi_telepon`; untuk masuk, `tujuan` adalah `login`.

```bash
curl.exe -s -X POST "$BASE_URL/auth/otp/verify" \
  -H "Content-Type: application/json" \
  -d '{"no_telepon":"<NO_TELEPON>","kode":"<KODE_OTP>","tujuan":"verifikasi_telepon","device_id":"<DEVICE_ID>"}'
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"token":{"token_type":"Bearer","access_token":"<ACCESS_TOKEN>","expires_in":86399,"access_token_expires_at":"<TIMESTAMP>","refresh_token":"<REFRESH_TOKEN>","refresh_token_expires_at":"<TIMESTAMP>"},"user":{"id":6,"uuid":"<UUID>","nama_lengkap":"<NAMA_LENGKAP>","no_telepon":"<NO_TELEPON>","email":"<EMAIL>","tipe":"pasien","status":"aktif","bahasa":"id","foto_profil":null,"telepon_terverifikasi":true,"email_terverifikasi":false,"last_login_at":"<TIMESTAMP>","dibuat_at":"<TIMESTAMP>"}}}
```

Kunci token: `data.token.{token_type,access_token,expires_in,
access_token_expires_at,refresh_token,refresh_token_expires_at}`.
Panjang teramati: `access_token` 50 karakter, `refresh_token` 80 karakter,
`expires_in` 86399.

OTP dipakai ulang (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"kode":["Kode OTP sudah pernah dipakai."]}}
```

Kode salah (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"kode":["Kode OTP tidak valid."]}}
```

Tujuan tidak dikenal (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"kode":["The kode OTP field format is invalid."],"tujuan":["The selected tujuan OTP is invalid."]}}
```

Tanpa pengenal (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"no_telepon":["Isi no_telepon atau email."],"email":["Isi no_telepon atau email."]}}
```

## 3. `POST /auth/login` - 200

Langkah pertama masuk. Mengembalikan **OTP saja**, bukan token.

```bash
curl.exe -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"no_telepon":"<NO_TELEPON>","password":"<PASSWORD>"}'
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"otp":{"tujuan":"login","kedaluwarsa_at":"<TIMESTAMP>","ttl_detik":300,"kode":"<KODE_OTP>"}},"message":"Kode OTP telah dikirim."}
```

Kredensial salah (HTTP 401):

```json
{"success":false,"message":"Nomor telepon, email, atau kata sandi salah.","errors":{}}
```

Nomor tak dikenal mengembalikan badan 401 yang sama (tidak membocorkan
keberadaan akun). Lanjutkan dengan `POST /auth/otp/verify` memakai
`tujuan=login` untuk mendapatkan token.

## 4. `POST /auth/refresh` - 200

Memutar refresh token. Token lama ditandai `dicabut=1` di dalam transaksi.

```bash
curl.exe -s -X POST "$BASE_URL/auth/refresh" \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"<REFRESH_TOKEN>"}'
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"token":{"token_type":"Bearer","access_token":"<ACCESS_TOKEN>","expires_in":86399,"access_token_expires_at":"<TIMESTAMP>","refresh_token":"<REFRESH_TOKEN>","refresh_token_expires_at":"<TIMESTAMP>"}},"message":"Token berhasil diperbarui."}
```

Memutar ulang token lama (HTTP 401, dan **semua** refresh token pengguna itu
dicabut sebagai respons pencurian):

```json
{"success":false,"message":"Sesi tidak valid. Silakan masuk kembali.","errors":{}}
```

Token 80-karakter tak dikenal mengembalikan 401 yang sama. Token pendek
(HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"refresh_token":["The refresh token field must be 80 characters."]}}
```

Catatan: access token yang baru diterbitkan tetap valid setelah deteksi
pemakaian ulang refresh token; yang dicabut adalah seluruh refresh token,
bukan access token (`GET /me` dengan access token baru tetap 200).

## 5. `POST /auth/logout` - 200

Butuh header Bearer **dan** `refresh_token` di badan. Bersifat **per
perangkat**: menghapus access token saat ini dan mencabut refresh token yang
diberikan saja; baris `user_devices` tidak diubah (`perangkat.dimatikan` selalu
`0`, dipertahankan agar klien lama tidak rusak). Untuk keluar dari semua
perangkat, panggil `POST /auth/logout-all` (bagian 10).

```bash
curl.exe -s -X POST "$BASE_URL/auth/logout" \
  -H "Authorization: Bearer <ACCESS_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"<REFRESH_TOKEN>"}'
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"refresh_token":{"dicabut":true},"access_token":{"dihapus":true},"perangkat":{"dimatikan":0}},"message":"Logout berhasil."}
```

Jika refresh token sudah dicabut lebih dulu (misalnya oleh rotasi),
`refresh_token.dicabut` menjadi `false` dan sisanya tetap berhasil.
Tanpa `refresh_token` (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"refresh_token":["The refresh token field is required."]}}
```

Tanpa header Bearer (HTTP 401):

```json
{"success":false,"message":"Unauthenticated.","errors":{}}
```

Setelah logout, `GET /me` dengan access token lama menjadi 401.

## 6. `POST /auth/devices` - 201

Mendaftarkan atau memperbarui (upsert per `device_id`) perangkat milik
pengguna terautentikasi. Butuh Bearer.

```bash
curl.exe -s -X POST "$BASE_URL/auth/devices" \
  -H "Authorization: Bearer <ACCESS_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"<DEVICE_ID>","platform":"android","fcm_token":"<FCM_TOKEN>","app_versi":"1.4.2"}'
```

Respons terverifikasi (HTTP 201):

```json
{"success":true,"data":{"device":{"device_id":"<DEVICE_ID>","platform":"android","fcm_token":"<FCM_TOKEN>","app_versi":"1.4.2","aktif":true,"last_active_at":"<TIMESTAMP>","dibuat_at":"<TIMESTAMP>"}},"message":"Perangkat berhasil didaftarkan."}
```

Mengirim ulang `device_id` yang sama memperbarui baris (upsert),
bukan membuat duplikat. Platform di luar enum (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"platform":["The selected platform is invalid."]}}
```

## 7. `GET /auth/devices` - 200

Daftar perangkat milik pengguna terautentikasi. Butuh Bearer. Ini route
kedelapan yang tidak ada di cakupan enam-route todo 20.

```bash
curl.exe -s "$BASE_URL/auth/devices" \
  -H "Authorization: Bearer <ACCESS_TOKEN>"
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"devices":[{"device_id":"<DEVICE_ID>","platform":"android","fcm_token":"<FCM_TOKEN>","app_versi":"1.4.2","aktif":true,"last_active_at":"<TIMESTAMP>","dibuat_at":"<TIMESTAMP>"}]},"message":"Daftar perangkat berhasil dimuat.","meta":{"current_page":1,"last_page":1,"per_page":1,"total":1,"from":1,"to":1}}
```

Tanpa Bearer (HTTP 401):

```json
{"success":false,"message":"Unauthenticated.","errors":{}}
```

## 8. `DELETE /auth/devices/{deviceId}` - 200

Mencabut (menandai `aktif=false`) perangkat milik sendiri. `{deviceId}`
adalah string `device_id`, bukan id numerik. Butuh Bearer.

```bash
curl.exe -s -X DELETE "$BASE_URL/auth/devices/<DEVICE_ID>" \
  -H "Authorization: Bearer <ACCESS_TOKEN>"
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"device":{"device_id":"<DEVICE_ID>","platform":"android","fcm_token":"<FCM_TOKEN>","app_versi":null,"aktif":false,"last_active_at":"<TIMESTAMP>","dibuat_at":"<TIMESTAMP>"}},"message":"Perangkat berhasil dicabut."}
```

Perangkat milik pengguna lain atau tak dikenal (HTTP 404):

```json
{"success":false,"message":"Resource not found.","errors":{}}
```

## 9. `POST /auth/otp/resend` - 200

Menerbitkan ulang kode OTP tanpa kata sandi. Badan:
`{tujuan, no_telepon?, email?, device_id?}` (`tujuan` = `verifikasi_telepon`
atau `login`). Kode lama untuk tujuan yang sama ditutup. Respons **generik**:
akun tak dikenal, akun nonaktif, dan akun nyata menjawab badan yang sama persis,
sehingga endpoint tidak bisa dipakai untuk mendata akun. Dibatasi
`throttle:auth-otp-resend` (3 per 5 menit per akun + IP).

```bash
curl.exe -s -X POST "$BASE_URL/auth/otp/resend" \
  -H "Content-Type: application/json" \
  -d '{"no_telepon":"<NO_TELEPON>","tujuan":"verifikasi_telepon"}'
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"otp":{"kedaluwarsa_at":"<TIMESTAMP>","ttl_detik":300,"kanal":"log"}},"message":"Jika akun terdaftar, kode OTP baru telah dikirim."}
```

`kanal` adalah kanal yang benar-benar dipakai driver aktif (`whatsapp` untuk
`fonnte`, `log` untuk driver lokal). Rate limit (HTTP 429) memuat
`meta.retry_after` dan header `Retry-After`.

## 10. `POST /auth/logout-all` - 200

"Keluar dari semua perangkat". Butuh Bearer, tanpa badan. Mencabut **semua**
refresh token akun, menghapus access token saat ini, dan menonaktifkan semua
baris `user_devices`.

```bash
curl.exe -s -X POST "$BASE_URL/auth/logout-all" \
  -H "Authorization: Bearer <ACCESS_TOKEN>"
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"refresh_token":{"dicabut":true,"jumlah":2},"access_token":{"dihapus":true},"perangkat":{"dimatikan":3}},"message":"Logout dari semua perangkat berhasil."}
```
