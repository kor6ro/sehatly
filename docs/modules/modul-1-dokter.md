# Modul 1 - Direktori Dokter (`/api/v1/dokter`, `/api/v1/master-spesialisasi`)

Tiga route publik (tanpa auth). Daftar hanya memuat dokter yang memenuhi
aturan visibilitas; respons detail tidak pernah memuat `nomor_str` atau
`file_str_url`.

Aturan visibilitas (sumber: `v_dokter_katalog` + `DokterDirectoryService`):

- `status_verifikasi = 'terverifikasi'`,
- `str_berlaku_sampai >= hari ini`,
- `status_aktif = 1`,
- akun tidak di-soft-delete (`dihapus_at` null).

Terverifikasi melawan fixture: dokter terverifikasi muncul di daftar;
dokter `pending` (id 3) tidak muncul dan `GET /dokter/3` menjadi 404.
Tidak ada fixture STR-kedaluwarsa atau soft-delete, jadi dua pengecualian
itu berstatus terverifikasi-kode, bukan terverifikasi-fixture.

Perbedaan bentuk `spesialisasi` yang penting: di **daftar** ia berupa string
gabungan koma (`"Spesialis Anak, Spesialis Kulit & Kelamin"`); di **detail**
ia berupa array objek dengan `is_utama`.

Pagination memakai blok `meta` **sejajar** `data`, bukan di dalam `data`:

```json
{"success":true,"data":{"dokter":[...]},"message":"Daftar dokter berhasil dimuat.","meta":{"current_page":1,"last_page":2,"per_page":1,"total":2,"from":1,"to":1}}
```

```bash
BASE_URL="http://127.0.0.1:8123/api/v1"
```

## 1. `GET /dokter` - 200

Filter: `spesialisasi` (kode atau id), `tipe`, `search` (nama),
`tersedia_telemedisin`. Urut `?sort=` (F03 §4.4): `relevan` (bawaan, yaitu
`rating_rata_rata DESC` lalu `jumlah_konsultasi DESC`), `rating`, `pengalaman`,
`biaya_asc`, `biaya_desc`, `ulasan`. `?page=&per_page=` (maksimal 100).

```bash
curl.exe -s "$BASE_URL/dokter?per_page=2"
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"dokter":[{"id":2,"nama_lengkap":"<NAMA_DOKTER>","tipe":"dokter_spesialis","spesialisasi":"Spesialis Anak, Spesialis Kulit & Kelamin","biaya_konsultasi_online":"150000.00","rating_rata_rata":"4.85","jumlah_konsultasi":980,"status_verifikasi":"terverifikasi"}]},"message":"Daftar dokter berhasil dimuat.","meta":{"current_page":1,"last_page":2,"per_page":1,"total":2,"from":1,"to":1}}
```

Kunci tiap item: `{id,nama_lengkap,tipe,pengalaman_tahun,spesialisasi,
biaya_konsultasi_online,rating_rata_rata,jumlah_ulasan,jumlah_konsultasi,
status_verifikasi}`. `pengalaman_tahun` berasal dari kolom `dokter`;
`jumlah_ulasan` **dihitung ulang** dari `ulasan_dokter`, bukan kolom
tersimpan `dokter.jumlah_ulasan`.

Filter terverifikasi:

- `?spesialisasi=SP.PD` - 200, hanya dokter dengan spesialisasi itu.
- `?search=Fixture` - 200, cocok dengan `nama_lengkap`.
- `?spesialisasi=BOGUS` - 422:

```json
{"success":false,"message":"The given data was invalid.","errors":{"spesialisasi":["The selected spesialisasi is invalid."]}}
```

- `?per_page=101` - 422:

```json
{"success":false,"message":"The given data was invalid.","errors":{"per_page":["The per page field must not be greater than 100."]}}
```

- `?page=0` - 422:

```json
{"success":false,"message":"The given data was invalid.","errors":{"page":["The page field must be at least 1."]}}
```

## 2. `GET /dokter/{id}` - 200

Profil penuh satu dokter. `{id}` harus numerik (`whereNumber`);
`/dokter/abc` menjadi 404.

```bash
curl.exe -s "$BASE_URL/dokter/1"
```

Respons terverifikasi (HTTP 200, kunci `data.dokter`):

`{id,nama_lengkap,foto_profil,tipe,pengalaman_tahun,bio,
biaya_konsultasi_online,biaya_luar_jam,durasi_default_menit,
rating_rata_rata,jumlah_ulasan,jumlah_konsultasi,tersedia_telemedisin,
status_verifikasi,spesialisasi,pendidikan,faskes,dibuat_at}`.

Contoh entri `spesialisasi` (`is_utama` pertama):

```json
{"id":1,"kode":"UMUM","nama":"Dokter Umum","tipe":"dokter_umum","is_utama":true}
```

Respons ini **tidak** memuat `nomor_str` maupun `file_str_url`
(terverifikasi dengan pencarian substring pada badan penuh).
`pendidikan` dan `faskes` berupa array (kosong untuk fixture dokter 1).

Dokter tak memenuhi syarat atau tak ada (HTTP 404):

```json
{"success":false,"message":"Resource not found.","errors":{}}
```

Terverifikasi untuk `/dokter/3` (verifikasi `pending`), `/dokter/999999`,
dan `/dokter/abc`.

## 3. `GET /master-spesialisasi` - 200

Daftar spesialisasi untuk filter. Route ini di luar cakupan dua-route
todo 22.

```bash
curl.exe -s "$BASE_URL/master-spesialisasi"
```

Respons terverifikasi (HTTP 200, baris pertama):

```json
{"success":true,"data":{"spesialisasi":[{"id":16,"kode":"GIGI","nama":"Dokter Gigi","tipe":"spesialis"},{"id":3,"kode":"SP.A","nama":"Spesialis Anak","tipe":"spesialis"},{"id":8,"kode":"SP.B","nama":"Spesialis Bedah","tipe":"spesialis"}]}}
```

Kunci tiap item: `{id,kode,nama,tipe}`.
