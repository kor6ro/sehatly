# Modul 1 - Ringkasan (Auth, Profil Pasien, Direktori Dokter)

Modul 1 mencakup registrasi dan masuk dua-langkah, profil dan data mandiri
pasien, serta direktori dokter publik. Basis diverifikasi:
`http://127.0.0.1:8123/api/v1` (`php artisan serve --port=8123`).

Resep per endpoint ada di:

- `modul-1-auth.md` - 8 route `/api/v1/auth/*`.
- `modul-1-pasien.md` - 11 route `/api/v1/me` + `/api/v1/pasien/*`.
- `modul-1-dokter.md` - 3 route `/api/v1/dokter*` + `/api/v1/master-spesialisasi`.

## Manifest berkas (backend)

Route: `routes/api.php`, `bootstrap/app.php` (grup `api`, alias middleware,
render amplop error).

Auth (`App\Http\Controllers\Api\V1\AuthController.php`):

- `App\Services\Auth\OtpService.php`, `OtpSender.php`
  (`LogOtpSender` untuk lokal), `TokenService.php`.
- `App\Http\Requests\Auth\RegisterRequest.php`,
  `LoginRequest.php`, `VerifyOtpRequest.php`, `RefreshTokenRequest.php`,
  `LogoutRequest.php`, `StoreDeviceRequest.php`.

Profil dan data pasien (`MeController.php`, `PasienController.php`):

- `App\Services\Pasien\PasienRecordAccess.php` (403 pemanggil vs 404 baris).
- `App\Http\Requests\Pasien\UpdateProfileRequest.php`,
  `StoreAnggotaKeluargaRequest.php`, `UpdateAnggotaKeluargaRequest.php`,
  `StoreAlergiRequest.php`, `UpdateAlergiRequest.php`.
- `App\Http\Resources\UserResource.php`, `PasienResource.php`,
  `PasienAnggotaKeluargaResource.php`, `PasienAlergiResource.php`.

Direktori dokter (`DokterController.php`):

- `App\Services\Dokter\DokterDirectoryService.php`, `DokterKatalog.php`.
- `App\Http\Requests\Dokter\IndexDokterRequest.php`.
- `App\Http\Resources\DokterResource.php`, `DokterDetailResource.php`.

Lintas modul:

- `App\Support\ApiResponse.php` (amplop sukses/gagal, `meta` paginasi).
- `App\Support\NikMasker.php` (NIK 4 digit awal + 4 digit akhir).
- `App\Http\Middleware\EnsurePermission.php`, `EnsureUserType.php`.
- `App\Support\Rbac\Caller.php`, `Rbac\RbacCatalog.php`.
- `App\Models\User.php`, `Pasien.php`, `Dokter.php`, `UserDevice.php`,
  `UserOtp.php`, `UserRefreshToken.php`, dan master terkait.

Web (`web/`) dan mobile (`packages/`, `mobile/`): tidak ada berkas Modul 1
(todo 23/24 belum dikerjakan).

## Tabel endpoint (22 route, semua terverifikasi)

`A` = butuh `Authorization: Bearer <ACCESS_TOKEN>`. Tidak ada route Modul 1
yang memakai `permission:`; scoping pasien dikerjakan
`PasienRecordAccess`, bukan middleware `tipe:`.

| # | Metode | Path | Auth | Status terverifikasi |
|---|--------|------|------|----------------------|
| 1 | POST | `/auth/register` | - | 201 |
| 2 | POST | `/auth/otp/verify` | - | 200 |
| 3 | POST | `/auth/login` | - | 200 |
| 4 | POST | `/auth/refresh` | - | 200 |
| 5 | POST | `/auth/logout` | A | 200 |
| 6 | POST | `/auth/devices` | A | 201 |
| 7 | GET | `/auth/devices` | A | 200 |
| 8 | DELETE | `/auth/devices/{deviceId}` | A | 200 |
| 9 | GET | `/me` | A | 200 |
| 10 | GET | `/pasien/profil` | A | 200 |
| 11 | PUT | `/pasien/profil` | A | 200 |
| 12 | GET | `/pasien/anggota-keluarga` | A | 200 |
| 13 | POST | `/pasien/anggota-keluarga` | A | 201 |
| 14 | PUT | `/pasien/anggota-keluarga/{id}` | A | 200 |
| 15 | DELETE | `/pasien/anggota-keluarga/{id}` | A | 200 |
| 16 | GET | `/pasien/alergi` | A | 200 |
| 17 | POST | `/pasien/alergi` | A | 201 |
| 18 | PUT | `/pasien/alergi/{id}` | A | 200 |
| 19 | DELETE | `/pasien/alergi/{id}` | A | 200 |
| 20 | GET | `/dokter` | - | 200 |
| 21 | GET | `/dokter/{id}` | - | 200 |
| 22 | GET | `/master-spesialisasi` | - | 200 |

## Cara memperoleh token (urutan persis yang dipakai verifikasi)

```bash
BASE_URL="http://127.0.0.1:8123/api/v1"
# 1. Daftar; baca data.otp.kode dari respons (hanya di APP_ENV=local).
curl.exe -s -X POST "$BASE_URL/auth/register" -H "Content-Type: application/json" -d '{"nama_lengkap":"<NAMA>","no_telepon":"<NO_TELEPON>","password":"<PASSWORD>","jenis_kelamin":"L","tanggal_lahir":"1990-01-01","alamat_lengkap":"<ALAMAT>"}'
# 2. Verifikasi; data.token.access_token adalah Bearer, data.token.refresh_token untuk refresh/logout.
curl.exe -s -X POST "$BASE_URL/auth/otp/verify" -H "Content-Type: application/json" -d '{"no_telepon":"<NO_TELEPON>","kode":"<KODE_OTP>","tujuan":"verifikasi_telepon"}'
# 3. Pakai header Authorization: Bearer <ACCESS_TOKEN> untuk route bertanda A.
```

Alur masuk akun yang sudah ada: `POST /auth/login` (menerima OTP, tanpa
token) lalu `POST /auth/otp/verify` dengan `tujuan=login`.

## Aritmetika 13 vs 22 (temuan basi)

Todo 25 menulis "13 endpoint Modul 1", tetapi angka itu tidak cocok dengan
rencana itu sendiri maupun implementasi:

- Todo 20 (auth): judul menyebut tujuh operasi, acceptance-nya menuntut
  `route:list --path=api/v1/auth` memuat **6 route**. Implementasi memuat
  **8** (register, login, otp/verify, refresh, logout, POST/GET/DELETE
  devices). Permukaan perangkat (daftar + cabut) tumbuh dua route.
- Todo 21 (pasien): acceptance-nya menuntut
  `route:list --path=api/v1/pasien` memuat **8 route** (filter path ini
  mengecualikan `/me`). Berkas route sendiri mengoreksi: prosanya menyebut
  10 route di bawah `/pasien` dan semuanya dikirim, plus `/me` sebagai
  kesebelas. Implementasi: **10 + 1 = 11**.
- Todo 22 (dokter): acceptance-nya menuntut
  `route:list --path=api/v1/dokter` memuat **2 route**. Implementasi
  memuat **3** (`GET /dokter`, `GET /dokter/{id}`,
  `GET /master-spesialisasi`). Todo 25 sendiri mencatat
  `master_spesialisasi` sebagai "ditambahkan di luar tabel spec".
- 6 + 8 + 2 = 16 menurut acceptance rencana, bukan 13. Jadi "13" basi
  terhadap rencana maupun kode. Yang otoritatif adalah route table:
  **8 + 11 + 3 = 22**, dan dokumen ini meresepkan semuanya.

## Temuan basi lain yang dilaporkan, bukan ditutupi

- Paginasi `GET /dokter` berada di `meta` **sejajar** `data`, bukan di dalam
  `data`. Klaim brief sebaliknya salah; sumber (`ApiResponse::pageMeta`)
  dan respons 200 membuktikan bentuk sejajar.
- Tidak ada kredensial login manual yang usable dari fixture:
  `DevFixtureSeeder.php` memakai hash kata sandi acak yang tidak bisa
  dipakai masuk. Alur register lalu login yang dipakai verifikasi adalah
  satu-satunya jalan.
- Struktur `spesialisasi` berbeda antara daftar (string gabungan koma) dan
  detail (array objek dengan `is_utama`).
- STR-kedaluwarsa dan akun soft-delete tidak punya fixture, sehingga
  pengecualiannya terverifikasi-kode (`DokterDirectoryService`,
  `dihapus_at`) dan terdokumentasi jujur sebagai demikian.
- Todo 25 meminta indeks `docs/modules/README.md`, tetapi lingkup tugas ini
  hanya membolehkan berkas baru `docs/modules/modul-1-*.md`, sehingga
  README tidak dibuat/diubah dan konfliknya dilaporkan di sini.

## Tes (daftar berkas + perintah, tidak dijalankan ulang di sini)

Berkas Pest Modul 1:

- `tests/Feature/Auth/AuthFlowTest.php`
- `tests/Feature/Pasien/PasienProfileTest.php`
- `tests/Feature/Dokter/DokterDirectoryTest.php`
- `tests/Feature/RbacMiddlewareTest.php`
- `tests/Feature/RbacCatalogTest.php`

Perintah (tidak dijalankan dalam tugas ini agar `telemedisin_db_test`
tidak tersentuh; baseline brief: 391 tes, 380 lolos, 11 gagal di
scaffold web Fortify/Inertia yang tidak terkait):

```powershell
& "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" artisan test --filter="AuthFlowTest|PasienProfileTest|DokterDirectoryTest|RbacMiddlewareTest|RbacCatalogTest"
```

Verifikasi tugas ini memakai curl langsung terhadap server lokal, bukan
suite tersebut.

## Kesenjangan yang diketahui

- Tidak ada path tulis `ulasan_dokter` (sesuai rencana).
- `GET /master-spesialisasi` ada di luar tabel spec (sesuai rencana).
- 14 tabel yatim modul (sesuai rencana).
- Tidak ada route booking/konsultasi (modul 2+ belum ada); jangan
  mendokumentasikannya.
