# Evidence F01 — Sehatly Fase 0 (inventaris kode nyata)

Tanggal inventaris: **2026-10-02**. Read-only terhadap kode. Semua klaim di bawah berasal dari file yang dibaca langsung (path + baris). Ini **baseline fakta**, bukan penilaian UX.

## 1. Endpoint yang ada (dan yang tidak)

Grup `Route::prefix('auth')` di `routes/api.php:83`; semua path di bawah `/api/v1/auth/...`.

| Method + path | Nama | Middleware | File:baris |
|---|---|---|---|
| `POST /api/v1/auth/register` | `auth.register` | `throttle:auth-otp-send` (10/60 dtk) | `routes/api.php:88-90` |
| `POST /api/v1/auth/login` | `auth.login` | `throttle:auth-login` (5/60, per identifier) + `throttle:auth-login-ip` (60/60) | `routes/api.php:92-99` |
| `POST /api/v1/auth/otp/verify` | `auth.otp.verify` | `throttle:auth-otp-verify` (5/300 dtk, per `user_otp.id`) | `routes/api.php:101-103` |
| `POST /api/v1/auth/refresh` | `auth.refresh` | `throttle:auth-refresh` (30/60) | `routes/api.php:105-107` |
| `POST /api/v1/auth/logout` | `auth.logout` | `auth:sanctum` | `routes/api.php:115-116` |
| `GET /api/v1/auth/devices` | `auth.devices.index` | `auth:sanctum` | `routes/api.php:118` |
| `POST /api/v1/auth/devices` | `auth.devices.store` | `auth:sanctum` | `routes/api.php:119` |
| `DELETE /api/v1/auth/devices/{deviceId}` | `auth.devices.destroy` | `auth:sanctum` | `routes/api.php:124-125` |

Anggaran limiter: `app/Providers/AppServiceProvider.php` (`auth-login` :39, `auth-login-ip` :49, `auth-otp-send` :52, `auth-otp-verify` :61, `auth-refresh` :569-580). `otp-kirim`, `otp-kirim-jam`, `auth-register` **tidak dipasang** (`routes/api.php:54-65`; tes `tests/Feature/Security/RouteThrottlingTest.php:199-200`).

**Tidak ada route kirim ulang OTP (resend).** Blok auth tepat 8 route, diuji sebagai himpunan tertutup `tests/Feature/Auth/AuthFlowTest.php:1231-1246`. Dinyatakan eksplisit: "There is **no resend endpoint**. To re-issue a login OTP, call [login again]" — `docs/mobile-integration.md:396`, juga `:399`.

## 2. Logika OTP backend

`app/Services/Auth/OtpService.php`:
- Panjang kode **6 digit** (`KODE_DIGIT = 6`, :94), nol di depan dipertahankan (`randomKode()` `str_pad`, :272-277).
- **TTL 5 menit** (`TTL_MENIT = 5`, :97; `kedaluwarsa_at = now()->addMinutes(5)`, :147).
- **Sekali pakai**: `consume()` mengunci baris `FOR UPDATE`, menandai `sudah_dipakai=true` (:199-237, flip :232-233). Urutan cek: `sudah_dipakai` sebelum `kedaluwarsa_at` (:224-230).
- **Disimpan sebagai hash SHA-256**, bukan plaintext (:258-261; tes `AuthFlowTest.php:410-420`).
- **Kirim ulang = panggil `login` lagi**: `issue()` menutup kode lama dengan `kedaluwarsa_at = now()` dalam transaksi yang sama (:149-163). Tidak ada endpoint khusus.
- **Tidak ada kolom penghitung percobaan** di `user_otp` (:37-42). Satu-satunya pembatas adalah throttle 5/300 dtk per kode. Pada percobaan ke-6 kode `login` **dibakar** (`AppServiceProvider.php:101-103`, :550-552); kode `verifikasi_telepon` (registrasi) **tidak dibakar** (`RateLimitingTest.php:743-794`).
- Tujuan yang diterbitkan hanya `login` dan `verifikasi_telepon` (`TUJUAN_DI_TERBITKAN`, :110-113); konstanta lain (`verifikasi_email`, `reset_kata_sandi`) ada di enum tetapi tidak dipakai (:65-74).

**Normalisasi nomor telepon: TIDAK ADA.** `no_telepon` dicocokkan persis (`AuthRequest.php:63-66`; lookup `AuthController.php:533-535`). Regex registrasi mengizinkan `+` opsional (`/^\+?[0-9]{8,20}$/`, `RegisterRequest.php:63`), sehingga `+62812...` dan `0812...` adalah dua akun berbeda.

### Pesan error (422, `errors.kode`)
Dipetakan di `AuthController.php:574-581`; isi `app/Services/Auth/OtpRejected.php`:
- Tidak dikenal / tidak cocok: `Kode OTP tidak valid.` (:29, :46) — akun tak dikenal pun diberi pesan yang sama, bukan 404 (`AuthController.php:279-281`).
- Sudah dipakai: `Kode OTP sudah pernah dipakai.` (:32, :51).
- Kedaluwarsa: `Kode OTP sudah kedaluwarsa. Silakan minta kode baru.` (:35, :56).

### Sukses verifikasi (satu-satunya penerbit token)
`AuthController::verifyOtp()` :314-317 → `data: { user, token }`, message `Verifikasi berhasil.`
- `AuthTokenResource` (`app/Http/Resources/AuthTokenResource.php:44-51`): `token_type='Bearer'`, `access_token`, `expires_in`, `access_token_expires_at`, `refresh_token` (80 karakter), `refresh_token_expires_at`.
- `UserResource` (`app/Http/Resources/UserResource.php:72-93`): `id, uuid, nama_lengkap, no_telepon, email, tipe, status, bahasa, foto_profil, telepon_terverifikasi, email_terverifikasi, last_login_at, dibuat_at` (+ relasi `whenLoaded`).
- OTP plaintext hanya muncul di respons saat `app()->environment('local')` (`IssuedOtp.php:56-59`).
- Pengiriman: driver `log` secara default (`config/otp.php:22`); alternatif Fonnte (`PemilihPengirimOtp.php:20`). Template WA: `Kode OTP Sehatly: {kode}. Berlaku 5 menit. Jangan bagikan kode ini kepada siapa pun.` (`FonnteOtpSender.php:62-63`).

## 3. Registrasi

`RegisterRequest.php:50-85` — 9 field: `nama_lengkap` (wajib, min:3), `no_telepon` (wajib, unik), `email` (opsional, unik), `password` (wajib, min:8, **tanpa `confirmed`**), `jenis_kelamin` (`L|P`), `tanggal_lahir` (Y-m-d, 1900–hari ini), `tempat_lahir` (opsional), `alamat_lengkap` (wajib, min:5), `bahasa` (`id|en`, opsional).

- **Tidak ada field consent/persetujuan** di request maupun `RegisterInput` FE (`web/src/lib/api/auth.ts:39-50`). Server menulis `tipe='pasien'`, `status='pending_verifikasi'`, `telepon_terverifikasi=false` sebagai literal (`AuthController.php:162-166`).
- Sukses: 201 `data: { user, otp: { tujuan, kedaluwarsa_at, ttl_detik, kode } }`, message `Pendaftaran berhasil. Kode OTP telah dikirim.` (`AuthController.php:192-200`).
- **Consent UU PDP dicatat setelah login**, bukan saat daftar: `POST/GET /api/v1/pdp/persetujuan`, `GET /api/v1/pdp/dokumen` (`routes/api.php:1490-1502`); UI di `/profil/privasi` (`web/src/pages/privasi-page.tsx`, `web/src/lib/api/pdp-persetujuan.ts`; jenis wajib `syarat_ketentuan`, `kebijakan_privasi`, `berbagi_data_medis` :59-62). Komentar di `register-page.tsx:270-292` menyatakan checkbox sengaja tidak dirender karena server tidak menerima field consent.

## 4. Login

- Identifier: `no_telepon` **atau** `email`; jika keduanya diisi, telepon menang (`AuthRequest.php:70-76`, `:84-96`; `AuthController.php:531-542`).
- Kredensial: kata sandi (`Hash::check`, `AuthController.php:562`); identifier tak dikenal tetap menjalankan bcrypt umpan agar waktu respons serupa (:552-560, dipanggil :224).
- **Tidak mengembalikan token**: `data: { otp: { tujuan: 'login', kedaluwarsa_at, ttl_detik, kode } }`, message `Kode OTP telah dikirim.` (:249-258).
- Salah kata sandi / akun tak dikenal: 401 `Nomor telepon, email, atau kata sandi salah.` — byte-identik untuk keduanya (:226-239).
- Akun `nonaktif`/`ditangguhkan`: 403 `Akun ini sedang dinonaktifkan. Hubungi administrator.` (:110, :241-247).

## 5. Perangkat dan sesi

- `StoreDeviceRequest.php:49-56`: `device_id` (wajib, min:3), `platform` (`android|ios|web`), `fcm_token` (opsional), `app_versi` (opsional).
- `GET /auth/devices`: `data: { devices }` + `meta`; urut `last_active_at` desc (`AuthController.php:414-430`; tes `AuthFlowTest.php:1137-1145`).
- `POST /auth/devices`: upsert `(user_id, device_id)`, `aktif=true`; 201/200 (`AuthController.php:444-485`).
- `DELETE /auth/devices/{deviceId}`: `aktif=false`; 404 untuk perangkat akun lain (:500-518).
- `POST /auth/logout`: mencabut refresh token, menghapus access token, dan **menonaktifkan SEMUA** `user_devices` aktif (:365-388). Respons memuat `{refresh_token:{dicabut}, access_token:{dihapus}, perangkat:{dimatikan}}`.
- Access token Sanctum kedaluwarsa 1440 menit (`TokenService.php:264-269`); refresh token 80 karakter, TTL 30 hari (:61, :71). Rotasi + deteksi replay: token yang sudah dipakai memicu pencabutan **semua** refresh token akun → 401 `Sesi tidak valid. Silakan masuk kembali.` (:173-207).
- **Tidak ada `device_id` di `user_refresh_tokens`** → pencabutan refresh per-perangkat tidak mungkin (:29-41).

## 6. UI frontend (`web/src`)

Route: `/login` (`router.tsx:101-105`), `/register` (:106-110), `/otp` (:111-115); halaman publik `/kebijakan-privasi`, `/syarat-ketentuan` (:135-144).

**Input OTP:**
- `input-otp` (`web/package.json:33`), komponen `web/src/components/ui/input-otp.tsx`.
- 6 digit (`KODE_DIGIT = 6`, `otp-page.tsx:30`; `maxLength` :53; 6 slot :63-67).
- `autoComplete="one-time-code"` + `inputMode="numeric"` (:57-58) → dukungan autofill SMS dan paste dari library.
- Tombol submit nonaktif sampai 6 digit (:262).

**Timer:** `useCountdown(kedaluwarsaAt, ttlDetik)` dari server (:81-117; fallback 300 dtk :145). Copy: `Kode berlaku X menit Y detik lagi.` (:256); saat habis: `Kode mungkin sudah kedaluwarsa. Kirim ulang dengan masuk kembali.` (:257). Tombol submit **tidak** dinonaktifkan saat timer habis (:76-79).

**Kirim ulang: TIDAK ADA.** Satu-satunya pemulihan adalah tombol `Kembali` (:269-279) yang membersihkan state dan kembali ke `/login`.

**State error:**
- Validasi (`errors.kode`) inline di field OTP (:154-155, :247).
- Banner non-validasi: `Gagal memverifikasi` (:219-227); `Akun tidak ditemukan` (:229-239).
- Konteks hilang (`OtpContextMissing`): `Sesi verifikasi tidak ditemukan` + penjelasan + `Kembali ke halaman masuk` (:293-325).

**Copy (persis):**
- Login: judul `Masuk`, deskripsi `Masukkan nomor telepon atau email yang terdaftar.`, tombol `Lanjutkan`/`Mengirim kode...`, footer `Belum punya akun? Daftar` (`login-page.tsx:138-146`, :245); toggle `Telepon`/`Email` (:213, :221); helper `Langkah kedua adalah kode OTP yang dikirim ke nomor telepon terdaftar...` (:249-250).
- Daftar: judul `Daftar`, deskripsi `Buat akun pasien. Verifikasi OTP diperlukan sebelum akun dapat dipakai.`; kalimat consent `Dengan mendaftar, Anda menyetujui syarat dan ketentuan serta kebijakan privasi Sehatly.` (`register-page.tsx:142-150`, :276-292).
- OTP: judul `Kode OTP`, deskripsi `Masukkan 6 digit kode yang dikirim ke {identifier tersamarkan}.` (:216); tombol `Verifikasi`/`Memverifikasi...` (:266).

**Keamanan sesi:** token di `sessionStorage` (`token.ts:40-42`), device id `crypto.randomUUID` (:116-148). `http.ts` `afterResponse` (:64-156): 401 → refresh single-flight → ulang request; 401 dari `/auth/refresh` = terminal → `Sesi tidak dapat diperpanjang. Silakan masuk kembali.` (:74-78); sesi berakhir `Sesi berakhir. Silakan masuk kembali.` (:82-85, :446-447).

**Logout UI:** `app-shell.tsx:307-331` (mutasi `signOut`, flash `Anda telah keluar.`), tombol `Keluar` :637.

**Biometrik/passkey: TIDAK ADA** di `web/src`; kolom passkey sudah dihapus dari skema (`docs/schema-notes.md:116,127`; `docs/migration-order.md:151`).

**UI manajemen perangkat: TIDAK ADA** — tidak ada pemanggil `GET/POST/DELETE /auth/devices` di `web/src` selain tipe generated (`web/src/types/api.d.ts:231-259`).

## 7. OpenAPI (`docs/openapi.yaml`)

8 operasi: `getApiV1AuthDevices` (:770-801), `postApiV1AuthDevices` (:802-840), `deleteApiV1AuthDevicesDeviceId` (:841-877), `postApiV1AuthLogin` (:878-914), `postApiV1AuthLogout` (:915-954), `postApiV1AuthOtpVerify` (:955-990), `postApiV1AuthRefresh` (:991-1026), `postApiV1AuthRegister` (:1027-1062).

Skema body: `RegisterRequestBody` (:4902-4951; wajib `alamat_lengkap, jenis_kelamin, nama_lengkap, no_telepon, password, tanggal_lahir`), `LoginRequestBody` (:4790-4808; wajib `password`), `VerifyOtpRequestBody` (:6054-6084; wajib `kode` regex `^[0-9]{6}$` + `tujuan`), `RefreshTokenRequestBody` (:4889-4901; `refresh_token` size 80), `LogoutRequestBody` (:4809-4821), `StoreDeviceRequestBody` (:5366-5391).

Catatan: generator menulis status sukses `201` untuk semua POST (login/logout/refresh/verify) meski controller mengembalikan 200.

## 8. Tes yang membuktikan perilaku (`tests/Feature/Auth/AuthFlowTest.php`)

- Registrasi: struktur `data.user`+`data.otp` (:323-376); OTP plaintext tidak ada di luar local (:378-392); hash 64 char (:410-420); tanpa token (:422-432); `tipe/status` dari klien diabaikan (:434-452); duplikat telepon/email 422 (:508-528); rate limit 10/menit (:530-552).
- Login: OTP tanpa token, `ttl` 300 (:558-583); salah sandi 401 byte-identik dengan akun tak dikenal (:602-637); akun nonaktif 403 (:639-653); rate limit 5/menit (:667-681).
- Verifikasi: sukses mengaktifkan akun + `personal_access_tokens.name = api:<device_id>` (:687-728); kode berawalan nol (:730-748); salah → `Kode OTP tidak valid.` (:750-768); kedaluwarsa (:770-784); replay → `Kode OTP sudah pernah dipakai.` (:786-809); kode lama yang digantikan → 422 kedaluwarsa, `liveCodeCount == 1` (:811-848); rate limit 5/menit (:880-896).
- Refresh: rotasi (:918-940); replay → 401 dan cabut semua refresh (:942-966). Logout/perangkat (:1001-1225).
- `RateLimitingTest.php`: percobaan ke-6 membakar kode login (:700-741); kode registrasi tidak bisa diminta ulang sehingga tidak dibakar (:743-794).

## 9. Konsekuensi untuk F01 (ringkas)

1. **Pemulihan OTP tidak ada di UI**: kedaluwarsa/salah → hanya `Kembali` ke login; tidak ada "kirim ulang" meski backend bisa menerbitkan kode baru lewat `login`.
2. **Timer bisa menyesatkan**: setelah 0, tombol verifikasi tetap aktif dan tidak ada jalan keluar selain kembali.
3. **Nomor telepon tanpa normalisasi**: `0812...` vs `+62812...` = akun berbeda; tidak ada petunjuk format di UI.
4. **Consent UU PDP tidak diambil saat daftar** — hanya tautan; ledger consent ada di `/profil/privasi` setelah login.
5. **Manajemen perangkat ada di API tetapi tidak ada UI** — tidak bisa mencabut sesi perangkat.
6. **Tidak ada passkey/biometrik** (kolom pun sudah tidak ada di skema).
7. **Rate limit 5 percobaan per kode** dan kode `login` dibakar pada percobaan ke-6; UI tidak pernah menjelaskan konsekuensi ini.
