# Modul 1 - Profil dan Data Pasien (`/api/v1/me`, `/api/v1/pasien`)

Tujuh route mandiri pasien. Semua butuh header
`Authorization: Bearer <ACCESS_TOKEN>`. Tidak ada middleware `permission:`
atau `tipe:` pada route ini; penegakannya ada di
`App\Services\Pasien\PasienRecordAccess`: pemanggil yang bukan pemilik baris
`pasien` mendapat 403, dan baris milik pasien lain mendapat 404.

Empat route `GET|POST|PUT|DELETE /pasien/anggota-keluarga` pernah menjadi bagian
dari daftar ini dan dihapus pada 2026-10-15, karena tidak ada yang bertindak di
atas nilainya: `booking.pasien_id` selalu milik pemanggil, `BookingResource` hanya
memantulkan id angka, dan tidak ada service, policy, atau query punya cabang di
atasnya - sehingga dokter tidak bisa tahu tubuh siapa yang dikonsultasikan.
Alasan lengkapnya ada di catatan di `routes/api.php`. Tabel
`pasien_anggota_keluarga` dan kedua foreign key ke arahnya tetap ada; yang hilang
hanya kemampuan aplikasi menulisnya, dan kini `POST /api/v1/booking` menolak
`anggota_keluarga_id` dengan 422.

- `GET /me` mengembalikan pengguna beserta relasi `pasien` dan `dokter`.
- NIK tidak pernah mentah di respons; bentuknya menyimpan 4 digit awal dan
  4 digit akhir, sisanya bullet (contoh `3273••••••••0002`).
- `kata_sandi_hash` tidak pernah muncul di badan mana pun.
- `PUT /pasien/profil` mengabaikan `tipe`, `status`, `no_telepon`, dan `nik`
  karena kunci itu tidak ada di FormRequest yang divalidasi.
- Daftar memakai `?page=&per_page=` (maksimal 100) dan blok `meta`
  sejajar `data`.

```bash
BASE_URL="http://127.0.0.1:8123/api/v1"
AUTH="Authorization: Bearer <ACCESS_TOKEN>"
```

## 1. `GET /me` - 200

```bash
curl.exe -s "$BASE_URL/me" -H "$AUTH"
```

Respons terverifikasi (HTTP 200, dipadatkan):

```json
{"success":true,"data":{"user":{"id":6,"uuid":"<UUID>","nama_lengkap":"<NAMA_LENGKAP>","no_telepon":"<NO_TELEPON>","email":"<EMAIL>","tipe":"pasien","status":"aktif","bahasa":"id","foto_profil":null,"telepon_terverifikasi":true,"email_terverifikasi":false,"last_login_at":"<TIMESTAMP>","dibuat_at":"<TIMESTAMP>","pasien":{"id":3,"nomor_rm":"<NOMOR_RM>","nik":null,"nomor_kk":null,"nama_lengkap":"<NAMA_LENGKAP>","jenis_kelamin":"L","tanggal_lahir":"1990-01-01","tempat_lahir":"Bandung","golongan_darah_id":null,"rhesus":"tidak_diketahui","agama_id":null,"pendidikan_id":null,"pekerjaan":"Guru","status_pernikahan_id":null,"alamat_lengkap":"<ALAMAT>","provinsi_id":null,"kabupaten_kota_id":null,"kecamatan_id":null,"kelurahan_id":null,"rt":"001","rw":"002","kode_pos":"40115","tinggi_badan_cm":"170.5","berat_badan_kg":"65.25","is_meninggal":false,"tanggal_meninggal":null,"dibuat_at":"<TIMESTAMP>","diubah_at":"<TIMESTAMP>"},"dokter":null}},"message":"Profil berhasil dimuat."}
```

Untuk akun dokter, `pasien` bernilai `null` dan `dokter` terisi; untuk akun
pasien, `dokter` bernilai `null`. Tanpa Bearer (HTTP 401):

```json
{"success":false,"message":"Unauthenticated.","errors":{}}
```

## 2. `GET /pasien/profil` - 200

```bash
curl.exe -s "$BASE_URL/pasien/profil" -H "$AUTH"
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"profile":{"id":5,"nomor_rm":"<NOMOR_RM>","nik":null,"nomor_kk":null,"nama_lengkap":"<NAMA_LENGKAP>","jenis_kelamin":"L","tanggal_lahir":"1990-01-01","tempat_lahir":null,"golongan_darah_id":null,"rhesus":"tidak_diketahui","agama_id":null,"pendidikan_id":null,"pekerjaan":null,"status_pernikahan_id":null,"alamat_lengkap":"<ALAMAT>","provinsi_id":null,"kabupaten_kota_id":null,"kecamatan_id":null,"kelurahan_id":null,"rt":null,"rw":null,"kode_pos":null,"tinggi_badan_cm":null,"berat_badan_kg":null,"is_meninggal":false,"tanggal_meninggal":null,"dibuat_at":"<TIMESTAMP>","diubah_at":"<TIMESTAMP>"}},"message":"Profil pasien berhasil dimuat."}
```

## 3. `PUT /pasien/profil` - 200

Mengubah nama, tempat lahir, FK master, pekerjaan, blok alamat, tinggi dan
berat. `tipe`, `status`, `no_telepon`, `nik` diabaikan.

```bash
curl.exe -s -X PUT "$BASE_URL/pasien/profil" \
  -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"nama_lengkap":"<NAMA_LENGKAP>","tempat_lahir":"Bandung","pekerjaan":"Guru","alamat_lengkap":"<ALAMAT>","rt":"001","rw":"002","kode_pos":"40115","tinggi_badan_cm":170.5,"berat_badan_kg":65.25,"tipe":"superadmin","status":"aktif"}'
```

Respons terverifikasi (HTTP 200, `users.tipe` tetap `pasien`,
`users.status` tetap `aktif`):

```json
{"success":true,"data":{"profile":{"id":5,"nomor_rm":"<NOMOR_RM>","nik":null,"nomor_kk":null,"nama_lengkap":"<NAMA_LENGKAP>","jenis_kelamin":"L","tanggal_lahir":"1990-01-01","tempat_lahir":"Bandung","golongan_darah_id":null,"rhesus":"tidak_diketahui","agama_id":null,"pendidikan_id":null,"pekerjaan":"Guru","status_pernikahan_id":null,"alamat_lengkap":"<ALAMAT>","provinsi_id":null,"kabupaten_kota_id":null,"kecamatan_id":null,"kelurahan_id":null,"rt":"001","rw":"002","kode_pos":"40115","tinggi_badan_cm":"170.5","berat_badan_kg":"65.25","is_meninggal":false,"tanggal_meninggal":null,"dibuat_at":"<TIMESTAMP>","diubah_at":"<TIMESTAMP>"}},"message":"Profil berhasil diperbarui."}
```

FK master tak dikenal (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"agama_id":["The selected agama is invalid."]}}
```

Format kode pos salah (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"kode_pos":["The kode pos field format is invalid."]}}
```

## 4. `GET /pasien/alergi` - 200

```bash
curl.exe -s "$BASE_URL/pasien/alergi" -H "$AUTH"
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"alergi":[]},"message":"Daftar alergi berhasil dimuat.","meta":{"current_page":1,"last_page":1,"per_page":15,"total":0,"from":null,"to":null}}
```

## 5. `POST /pasien/alergi` - 201

`keparahan` boleh dikosongkan dan default-nya `ringan`.

```bash
curl.exe -s -X POST "$BASE_URL/pasien/alergi" \
  -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"tipe_alergen":"obat","nama_alergen":"Amoxicillin","reaksi":"Gatal dan kemerahan","keparahan":"sedang"}'
```

Respons terverifikasi (HTTP 201):

```json
{"success":true,"data":{"alergi":{"id":4,"tipe_alergen":"obat","nama_alergen":"Amoxicillin","reaksi":"Gatal dan kemerahan","keparahan":"sedang","dicatat_oleh_user_id":<USER_ID>,"dibuat_at":"<TIMESTAMP>"}},"message":"Alergi berhasil ditambahkan."}
```

Kunci: `data.alergi.{id,tipe_alergen,nama_alergen,reaksi,keparahan,
dicatat_oleh_user_id,dibuat_at}`. Enum tak dikenal (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"nama_alergen":["The nama alergen field must be at least 2 characters."],"keparahan":["The selected keparahan is invalid."]}}
```

## 6. `PUT /pasien/alergi/{id}` - 200

```bash
curl.exe -s -X PUT "$BASE_URL/pasien/alergi/<ID>" \
  -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"keparahan":"berat"}'
```

Respons terverifikasi (HTTP 200; `reaksi` dan `dicatat_oleh_user_id`
tidak berubah):

```json
{"success":true,"data":{"alergi":{"id":4,"tipe_alergen":"obat","nama_alergen":"Amoxicillin","reaksi":"Gatal dan kemerahan","keparahan":"berat","dicatat_oleh_user_id":<USER_ID>,"dibuat_at":"<TIMESTAMP>"}},"message":"Alergi berhasil diperbarui."}
```

Id milik pasien lain (HTTP 404, tanpa membocorkan keberadaan baris):

```json
{"success":false,"message":"Resource not found.","errors":{}}
```

## 7. `DELETE /pasien/alergi/{id}` - 200

```bash
curl.exe -s -X DELETE "$BASE_URL/pasien/alergi/<ID>" -H "$AUTH"
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"deleted":true,"id":4},"message":"Alergi berhasil dihapus."}
```

## Semantik lintas-penyewa (terverifikasi)

Dengan dua akun pasien sungguhan: pasien A mengubah atau menghapus alergi
milik pasien B selalu mendapat 404
`"Resource not found."`, dan baris milik B tetap ada saat dibaca dengan
token B. Pemanggil bertipe `dokter` yang memukul route pasien mendapat 403
`"This action is unauthorized."`.
