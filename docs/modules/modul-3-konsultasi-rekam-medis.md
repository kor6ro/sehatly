# Modul 3 - Konsultasi, Chat, Rekam Medis, Surat Keterangan (status: selesai, 15 route terverifikasi)

Modul 3 mencakup siklus hidup konsultasi telemedisin (mulai, terima,
selesai), transkrip chat realtime per konsultasi, rekam medis beserta rantai
amandemennya, dan surat keterangan (termasuk surat rujukan yang menulis baris
`rujukan` dalam transaksi yang sama). Basis diverifikasi:
`http://127.0.0.1:8123/api/v1` (`php artisan serve --port=8123`, database
pengembangan `telemedisin_db`).

Semua respons memakai amplop `{"success","data","message"}`; gagal memakai
`{"success":false,"message","errors":{}}`. Pada endpoint daftar, `meta`
adalah **saudara sejajar** `data`, bukan di dalam `data`. Sebuah 422 dapat
membawa **lebih dari satu pesan per field** -- contohnya ditunjukkan pada
bagian 6, jangan digabung menjadi satu.

Nilai `<NO_TELEPON>`, `<KODE_OTP>`, `<ACCESS_TOKEN>`, `<NAMA>`,
`<NOMOR_SURAT>`, `<QR_TOKEN>`, `<TIMESTAMP>` di bawah adalah placeholder
yang jelas-jelas palsu. Jangan commit nilai asli: bukan token, bukan OTP,
bukan nomor telepon, bukan NIK.

```bash
BASE_URL="http://127.0.0.1:8123/api/v1"
```

## Tabel endpoint (15 route, semua dieksekusi)

`A` = butuh `Authorization: Bearer <ACCESS_TOKEN>`. `-` = anonim. Tidak ada
route Modul 3 yang menulis `rujukan` lewat path miliknya sendiri; rujukan
hanya terbit sebagai efek samping `surat_rujukan` (bagian 13).

| # | Metode | Path | Auth | Guard tambahan | Status terverifikasi |
|---|--------|------|------|----------------|----------------------|
| 1 | POST | `/konsultasi/mulai` | A | - | 201 |
| 2 | PUT | `/konsultasi/{id}/terima` | A | `tipe:dokter` + `permission:konsultasi.mulai` | 200 |
| 3 | GET | `/konsultasi/{id}` | A | - | 200 |
| 4 | GET | `/konsultasi/{id}/chat` | A | - | 200 |
| 5 | POST | `/konsultasi/{id}/chat` | A | `permission:konsultasi.chat` | 201 |
| 6 | POST | `/konsultasi/{id}/chat/baca` | A | `permission:konsultasi.chat` | 200 |
| 7 | PUT | `/konsultasi/{id}/selesai` | A | `tipe:dokter` + `permission:konsultasi.selesai` | 200 |
| 8 | POST | `/konsultasi/{id}/rekam-medis` | A | `tipe:dokter` + `permission:rekam_medis.simpan` | 201 |
| 9 | PUT | `/rekam-medis/{id}` | A | `tipe:dokter` + `permission:rekam_medis.simpan` | 200 |
| 10 | PUT | `/rekam-medis/{id}/final` | A | `tipe:dokter` + `permission:rekam_medis.final` | 200 |
| 11 | POST | `/rekam-medis/{id}/amandemen` | A | `tipe:dokter` + `permission:rekam_medis.final` | 201 |
| 12 | GET | `/rekam-medis/{id}` | A | - | 200 |
| 13 | POST | `/konsultasi/{id}/surat-keterangan` | A | `tipe:dokter` + `permission:surat_keterangan.buat` | 201 |
| 14 | GET | `/pasien/surat-keterangan` | A | - | 200 |
| 15 | GET | `/surat-keterangan/{nomor_surat}/verify?token=` | - | - | 200 |

`POST /konsultasi/{id}/resep` memang menempel pada prefix `konsultasi`
tetapi milik Modul 4 (dijaga `tipe:dokter` + `permission:resep.buat`) dan
tidak diresepkan di sini.

## Cara memperoleh token (urutan persis yang dipakai verifikasi)

Login di API ini dua langkah dan `POST /auth/login` **tidak** mengembalikan
token -- ia hanya mengirim OTP; pasangan token terbit dari
`POST /auth/otp/verify`. Kode OTP dibaca dari
`storage/logs/laravel.log` (baris `sehatly.otp`, kanal `LogOtpSender`),
bukan dari badan respons: plaintext `data.otp.kode` hanya ada saat
`APP_ENV=local`, dan klien yang bergantung padanya akan rusak di produksi.

```bash
BASE_URL="http://127.0.0.1:8123/api/v1"
# 1. Daftar pasien baru.
curl.exe -s -X POST "$BASE_URL/auth/sign-up" -H "Content-Type: application/json" -d '{"nama_lengkap":"<NAMA>","no_telepon":"<NO_TELEPON>","password":"<PASSWORD>","jenis_kelamin":"L","tanggal_lahir":"1990-01-01","alamat_lengkap":"<ALAMAT>"}'
# 2. Baca kode dari log (penerima disamarkan di log, cocokkan dari baris terbaru).
#    [2026-09-29 09:39:33] local.NOTICE: sehatly.otp {"tujuan":"verifikasi_telepon","penerima":"081*******31","kode":"<KODE_OTP>"}
# 3. Tukar OTP menjadi pasangan token.
curl.exe -s -X POST "$BASE_URL/auth/otp/verify" -H "Content-Type: application/json" -d '{"no_telepon":"<NO_TELEPON>","kode":"<KODE_OTP>","tujuan":"verifikasi_telepon"}'
# 4. Pakai header Authorization: Bearer <ACCESS_TOKEN> untuk route bertanda A.
```

Akun dokter tidak bisa didaftar lewat API (register selalu `tipe=pasien`),
sehingga token dokter untuk verifikasi ini diperoleh dengan masuk memakai
akun dokter fixture yang kata sandinya disetel di database pengembangan,
lalu alur login dua-langkah yang sama:
`POST /auth/login` (menerima OTP, tanpa token) lalu
`POST /auth/otp/verify` dengan `tujuan=login`.

## Aritmetika 14 vs 15 (temuan basi)

Todo 37 menulis "6 endpoint Modul 3 + 5 rekam-medis + 3 surat = 14". Route
table menjawab lain, dan yang otoritatif adalah route table:

- Klaim "6 endpoint konsultasi" basi terhadap kode: ada **7**
  (`mulai`, `terima`, `show`, `chat.index`, `chat.store`, `chat.baca`,
  `selesai`). `PUT /konsultasi/{id}/terima` tidak ada di rencana tetapi
  wajib ada secara struktural -- `PUT /selesai` menghitung
  `total_durasi_detik` dari `mulai_at` dan menolak 422 selama kolom itu
  NULL, dan tidak ada endpoint rencana yang menulis `mulai_at`. Tanpa
  langkah terima, status `berlangsung` tidak terjangkau dan endpoint
  selesai hanya bisa menjawab 422. Kode izin `konsultasi.mulai` yang
  mengawalinya juga tidak dipakai endpoint lain.
- Klaim "5 rekam-medis" benar: 4 di bawah `/rekam-medis` ditambah
  `POST /konsultasi/{id}/rekam-medis` yang menempel pada prefix konsultasi
  (filter path `rekam-medis` saja menjawab 4 dan terlihat seperti satu
  route hilang).
- Klaim "3 surat" benar tetapi filter path `surat-keterangan` menjawab
  **2**, karena `GET /pasien/surat-keterangan` adalah path pasien.
- Jadi **7 + 5 + 3 = 15**, bukan 14. Filter yang membuktikan angka tanpa
  menghitung dua kali:

```powershell
& "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" artisan route:list --path=api/v1 --json | & "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" -r '$r=json_decode(stream_get_contents(STDIN),true); ...'
```

- Tidak ada route yang URI-nya mengandung `rujukan` (**0**). Rujukan tidak
  punya endpoint sendiri; ia terbit di dalam `POST
  /konsultasi/{id}/surat-keterangan` bertipe `surat_rujukan` (bagian 13).

## 1. `POST /konsultasi/mulai` - 201

Dua bentuk yang saling lepas: `booking_id` (milik pemanggil sendiri,
berstatus `terjadwal` atau `check_in`) ATAU `dokter_id` + `tipe`. `room_id`
berupa UUID4 baru dan status awal selalu `menunggu_dokter`. Kolom milik
mesin (`status`, `mulai_at`, `selesai_at`, `room_id`, `pasien_id`,
`biaya_konsultasi`) adalah `prohibited`, bukan sekadar tak dikenal.

`tipe` adalah ENUM tiga nilai `konsultasi.tipe`: `chat`, `video_call`,
`telepon`. Bukan ENUM empat nilai `booking.tipe_layanan` -- keduanya punya
nama sama dan isi beda.

```bash
curl.exe -s -X POST "$BASE_URL/konsultasi/mulai" \
  -H "Authorization: Bearer <ACCESS_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"dokter_id":1,"tipe":"chat"}'
```

Respons terverifikasi (HTTP 201, disingkat pada relasi):

```json
{"success":true,"data":{"konsultasi":{"id":8,"booking_id":null,"pasien_id":6,"dokter_id":1,"tipe":"chat","status":"menunggu_dokter","room_id":"<UUID>","mulai_at":null,"selesai_at":null,"total_durasi_detik":null,"catatan_subjektif":null,"catatan_objektif":null,"catatan_asessment":null,"catatan_plan":null,"diagnosis_kerja":null,"saran_tindak_lanjut":null,"biaya_konsultasi":"50000.00","dibuat_at":"<TIMESTAMP>","diubah_at":"<TIMESTAMP>","pasien":{"id":6,"nik":null,"nama_lengkap":"<NAMA>"},"dokter":{"id":1,"nama_lengkap":"<NAMA>"},"booking":null}},"message":"Konsultasi berhasil dimulai."}
```

Kunci respons: `data.konsultasi.{id,booking_id,pasien_id,dokter_id,tipe,
status,room_id,mulai_at,selesai_at,total_durasi_detik,catatan_subjektif,
catatan_objektif,catatan_asessment,catatan_plan,diagnosis_kerja,
saran_tindak_lanjut,biaya_konsultasi,dibuat_at,diubah_at,pasien,dokter,
booking}`. `nik` pada pasien tertanam adalah `null` di sini karena akun
pendaftaran API tidak membawa NIK; bila ada, ia tampil dalam bentuk
samaran (4 digit awal + 8 bullet U+2022 + 4 digit akhir, mis.
`3273••••••••0021`), tidak pernah mentah.

Badan kosong (HTTP 422, ketiga field diminta sekaligus):

```json
{"success":false,"message":"The given data was invalid.","errors":{"booking_id":["The booking field is required when dokter is not present."],"dokter_id":["The dokter field is required when booking is not present."],"tipe":["The tipe konsultasi field is required when booking is not present."]}}
```

Dokter yang tidak ada atau tidak layak (belum terverifikasi, STR
kedaluwarsa, nonaktif, telemedisin mati) menjawab 404 dengan badan yang
sama seperti id yang tidak ada -- bukan oracle keberadaan.

## 2. `PUT /konsultasi/{id}/terima` - 200

Hanya dokter. `menunggu_dokter` -> `berlangsung`, dan ini satu-satunya
tempat `mulai_at` dicap. Tanpa badan.

```bash
curl.exe -s -X PUT "$BASE_URL/konsultasi/8/terima" \
  -H "Authorization: Bearer <ACCESS_TOKEN_DOKTER>"
```

Respons terverifikasi (HTTP 200): bentuk yang sama seperti bagian 1 dengan
`status=berlangsung` dan `mulai_at` terisi. Pasien yang memanggilnya
menjawab 403 (`tipe:dokter` di middleware).

## 3. `GET /konsultasi/{id}` - 200

Sesi beserta `pasien`, `dokter`, `booking`, empat field SOAP dan
`total_durasi_detik`. Terbaca oleh pasiennya, dokternya, atau
`admin`/`superadmin`; pihak ketiga mendapat 404.

```bash
curl.exe -s "$BASE_URL/konsultasi/8" \
  -H "Authorization: Bearer <ACCESS_TOKEN>"
```

Respons terverifikasi (HTTP 200): bentuk `data.konsultasi` yang sama
seperti bagian 1, pesan `Detail konsultasi berhasil dimuat.`

Konsultasi milik pasien lain (HTTP 404):

```json
{"success":false,"message":"Resource not found.","errors":{}}
```

Tanpa Bearer (HTTP 401):

```json
{"success":false,"message":"Unauthenticated.","errors":{}}
```

## 4. `GET /konsultasi/{id}/chat` - 200

Riwayat pesan **terurut menaik (tertua dulu)**, berpaginasi, `per_page`
dibatasi 100. `meta` sejajar `data`. Setiap baris menulis sisi pengirim
pada `pengirim_tipe` (`pasien`, `dokter`, `sistem`) -- diturunkan dari
baris profil yang dimiliki pemanggil, bukan salinan `users.tipe`.

```bash
curl.exe -s "$BASE_URL/konsultasi/8/chat?per_page=10" \
  -H "Authorization: Bearer <ACCESS_TOKEN>"
```

Respons terverifikasi (HTTP 200, daftar disingkat):

```json
{"success":true,"data":{"pesan":[{"id":12,"konsultasi_id":8,"pengirim_user_id":9,"pengirim_tipe":"sistem","tipe_pesan":"sistem","isi":"Konsultasi dimulai. Menunggu dokter.","file_url":null,"file_nama":null,"file_ukuran_kb":null,"dibaca_at":null,"terkirim_at":"<TIMESTAMP>"},{"id":15,"konsultasi_id":8,"pengirim_user_id":9,"pengirim_tipe":"pasien","tipe_pesan":"teks","isi":"Dok, saya batuk sudah tiga hari disertai pilek.","file_url":null,"file_nama":null,"file_ukuran_kb":null,"dibaca_at":null,"terkirim_at":"<TIMESTAMP>"}]},"message":"Riwayat chat berhasil dimuat.","meta":{"current_page":1,"last_page":1,"per_page":10,"total":4,"from":1,"to":4}}
```

Baris `sistem` ditulis oleh layanan (saat mulai, terima, selesai, dan saat
dokumen terbit), bukan oleh manusia.

## 5. `POST /konsultasi/{id}/chat` - 201

`tipe_pesan` adalah ENUM delapan nilai: `teks`, `gambar`, `dokumen`,
`audio`, `video_note`, `resep`, `surat_keterangan`, `sistem`. Tiga yang
terakhir adalah 422 di sini -- layanan yang menulisnya saat dokumen
terbit. `isi` wajib untuk `teks`; `berkas` (multipart, field bernama
`berkas`) wajib untuk empat tipe berkas.

```bash
curl.exe -s -X POST "$BASE_URL/konsultasi/8/chat" \
  -H "Authorization: Bearer <ACCESS_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"tipe_pesan":"teks","isi":"Dok, saya batuk sudah tiga hari disertai pilek."}'
```

Respons terverifikasi (HTTP 201):

```json
{"success":true,"data":{"pesan":{"id":15,"konsultasi_id":8,"pengirim_user_id":9,"pengirim_tipe":"pasien","tipe_pesan":"teks","isi":"Dok, saya batuk sudah tiga hari disertai pilek.","file_url":null,"file_nama":null,"file_ukuran_kb":null,"dibaca_at":null,"terkirim_at":"<TIMESTAMP>"}},"message":"Pesan berhasil dikirim."}
```

## 6. `POST /konsultasi/{id}/chat/baca` - 200

Mencap `dibaca_at` pada pesan **pihak lawan** yang masih NULL dan menjawab
jumlahnya. Baris sistem dilewati dua arah. Tanpa badan (selain `{}`).

```bash
curl.exe -s -X POST "$BASE_URL/konsultasi/8/chat/baca" \
  -H "Authorization: Bearer <ACCESS_TOKEN_DOKTER>" \
  -H "Content-Type: application/json" \
  -d '{}'
```

Respons terverifikasi (HTTP 200):

```json
{"success":true,"data":{"konsultasi_id":8,"jumlah_ditandai_baca":1},"message":"Pesan ditandai sudah dibaca."}
```

Contoh 422 **dua pesan dalam satu field** (jangan digabung -- amplop
meneruskan `$e->errors()` apa adanya):

```bash
curl.exe -s -X POST "$BASE_URL/konsultasi/8/chat" \
  -H "Authorization: Bearer <ACCESS_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"tipe_pesan":123,"isi":"test"}'
```

```json
{"success":false,"message":"The given data was invalid.","errors":{"tipe_pesan":["The tipe pesan field must be a string.","The selected tipe pesan is invalid."]}}
```

## 7. `PUT /konsultasi/{id}/selesai` - 200

Hanya dokter. `berlangsung` atau `menunggu_resep` -> `selesai`, mencap
`selesai_at` dan `total_durasi_detik` yang dihitung, serta menulis enam
field klinis (empat SOAP `konsultasi`: `catatan_subjektif`,
`catatan_objektif`, `catatan_asessment` (satu `s`), `catatan_plan`, plus
`diagnosis_kerja` dan `saran_tindak_lanjut`). Keenamnya opsional -- kolom
DDL-nya NULL. `status`, `mulai_at`, `selesai_at`, `total_durasi_detik`
adalah `prohibited` bahkan bagi dokter.

Status `konsultasi` mengenal enam nilai (`menunggu_dokter`, `berlangsung`,
`menunggu_resep`, `selesai`, `dibatalkan`, `gagal`) dengan sepuluh sisi
legal; tiga status akhir terminal dan tidak punya sisi keluar.

```bash
curl.exe -s -X PUT "$BASE_URL/konsultasi/8/selesai" \
  -H "Authorization: Bearer <ACCESS_TOKEN_DOKTER>" \
  -H "Content-Type: application/json" \
  -d '{"catatan_subjektif":"Batuk tiga hari disertai pilek, tidak demam.","catatan_objektif":"Tenggorokan kemerahan ringan.","catatan_asessment":"ISPA ringan.","catatan_plan":"Istirahat dan obat simtomatik.","diagnosis_kerja":"ISPA","saran_tindak_lanjut":"Kontrol bila batuk lebih dari seminggu."}'
```

Respons terverifikasi (HTTP 200): bentuk `data.konsultasi` yang sama
dengan `status=selesai`, `selesai_at` terisi, `total_durasi_detik=177`,
dan keenam field klinis terisi. Pesan `Konsultasi berhasil diselesaikan.`

Pemanggilan kedua (HTTP 422 -- `selesai` terminal, tanpa sisi keluar):

```json
{"success":false,"message":"The given data was invalid.","errors":{"status":["Konsultasi dengan status tersebut sudah selesai dan tidak dapat diubah lagi."]}}
```

Pasien yang memanggilnya menjawab 403 (`tipe:dokter`), bahkan sebelum
aturan status dibaca.

## 8. `POST /konsultasi/{id}/rekam-medis` - 201

Membuat rekam medis sebagai **DRAF** (`status_dokumen=draft`, `versi=1`
ditulis eksplisit karena default DDL adalah `final`). Isi adalah empat
belas kolom konten (`keluhan_utama`, lima riwayat/hasil, `subjektif`,
`objektif`, `asesmen`, `plan`, `diagnosis_kerja`,
`instruksi_tindak_lanjut`, `status_tindak_lanjut`, `jadwal_kontrol`);
kolom identitas (`pasien_id`, `dokter_id`, `konsultasi_id`,
`status_dokumen`, `versi`, ...) adalah `prohibited`. Perhatikan ejaannya:
kolom `rekam_medis` memakai `subjektif/objektif/asesmen/plan` -- beda
dengan kolom `konsultasi` pada bagian 7. Kunci asing yang tidak ada di
DDL (`icd10_kode`, `icd9cm_kode`) tidak dikirim di sini; diagnosa dan
tindakan adalah baris anak tersendiri.

```bash
curl.exe -s -X POST "$BASE_URL/konsultasi/8/rekam-medis" \
  -H "Authorization: Bearer <ACCESS_TOKEN_DOKTER>" \
  -H "Content-Type: application/json" \
  -d '{"keluhan_utama":"Batuk tiga hari disertai pilek","subjektif":"Batuk tiga hari, pilek, tidak demam","objektif":"Faring hiperemis ringan","asesmen":"ISPA ringan","plan":"Istirahat dan simtomatik","diagnosis_kerja":"ISPA"}'
```

Respons terverifikasi (HTTP 201, disingkat): `data.rekam_medis`
berisi `id=18`, `uuid`, `pasien_id=6`, `dokter_id=1`, `konsultasi_id=8`,
`tipe_kunjungan=telemedisin`, `tanggal_periksa` (default jam server bila
absen), keenam field isi terisi dan sisanya null, `status_dokumen=draft`,
`versi=1`, `adalah_versi_terkini=true`, pasien/dokter tertanam (NIK
samaran atau null), `diagnosa=[]`, `tindakan=[]`, `lampiran=[]`,
`persetujuan=[]`, dan rantai versi. Pesan
`Rekam medis berhasil disimpan sebagai draft.`

## 9. `PUT /rekam-medis/{id}` - 200

Ubah-di-tempat, hanya selama `status_dokumen=draft`. Field datar
(top-level), sama seperti pembuatan; `tanggal_periksa` boleh diubah di
sini (format `Y-m-d H:i:s`).

```bash
curl.exe -s -X PUT "$BASE_URL/rekam-medis/18" \
  -H "Authorization: Bearer <ACCESS_TOKEN_DOKTER>" \
  -H "Content-Type: application/json" \
  -d '{"plan":"Istirahat, simtomatik, dan kontrol bila memburuk"}'
```

Respons terverifikasi (HTTP 200): `data.rekam_medis` bentuk yang sama,
pesan `Rekam medis berhasil diperbarui.` Id yang bukan milik dokter
(atau tidak ada) menjawab 404 `Resource not found.`

Mengubah rekam yang sudah final/diamendemen (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"status_dokumen":["Rekam medis yang sudah final atau diamendemen tidak dapat diubah langsung. Gunakan endpoint amandemen."]}}
```

## 10. `PUT /rekam-medis/{id}/final` - 200

Menandatangani draf: mencap `status_dokumen=final` dan
`ditandatangani_at`, sekali saja. Tanpa badan.

```bash
curl.exe -s -X PUT "$BASE_URL/rekam-medis/18/final" \
  -H "Authorization: Bearer <ACCESS_TOKEN_DOKTER>"
```

Respons terverifikasi (HTTP 200): `data.rekam_medis` bentuk yang sama,
pesan `Rekam medis berhasil difinalisasi.` Final kedua menjawab 422.

## 11. `POST /rekam-medis/{id}/amandemen` - 201

Satu-satunya cara mengubah rekam yang sudah ditandatangani: baris asli
**tidak pernah disentuh**; baris baru disisipkan pada `MAX(versi)+1`
dengan `uuid` baru dan `status_dokumen=diamendemen`. Kumpulan perubahan
bersarang di bawah `perubahan`; `tanggal_periksa` dilarang di sini karena
ia kunci kelompok rantai.

```bash
curl.exe -s -X POST "$BASE_URL/rekam-medis/18/amandemen" \
  -H "Authorization: Bearer <ACCESS_TOKEN_DOKTER>" \
  -H "Content-Type: application/json" \
  -d '{"perubahan":{"plan":"Istirahat, simtomatik, kontrol seminggu","instruksi_tindak_lanjut":"Minum obat teratur"}}'
```

Respons terverifikasi (HTTP 201): `data.rekam_medis` berisi `id=19`,
`versi=2`, `status_dokumen=diamendemen`, `plan` dan
`instruksi_tindak_lanjut` baru, dan rantai versi berisi v1 (`final`) plus
v2. Pesan `Amandemen rekam medis berhasil dibuat.`

## 12. `GET /rekam-medis/{id}` - 200

Setiap baca yang berhasil menulis **satu** baris `akses_rekam_medis_log`
dalam transaksi yang sama -- kewajiban yang ditegakkan oleh event model,
bukan oleh konvensi controller. Orang asing 404 tanpa baris log; akun
tanpa baris profil 403 tanpa baris log. Bukan daftar: `meta` tidak ada
sama sekali (bukan null).

```bash
curl.exe -s "$BASE_URL/rekam-medis/18" \
  -H "Authorization: Bearer <ACCESS_TOKEN>"
```

Respons terverifikasi (HTTP 200): `data.rekam_medis` bentuk penuh,
pesan `Detail rekam medis berhasil dimuat.`

## 13. `POST /konsultasi/{id}/surat-keterangan` - 201

Menerbitkan satu surat. `tipe` adalah ENUM empat nilai:
`surat_sakit`, `surat_sehat`, `surat_rujukan`, `surat_kematian`.
`surat_sakit`/`surat_sehat`/`surat_kematian` memakai jendela
`tanggal_mulai`/`tanggal_selesai` (format `Y-m-d`) yang darinya
`jumlah_hari` dihitung; `surat_rujukan` wajib memakai jendela itu juga
**ditambah** blok rujukan (`faskes_tujuan_id`, dan opsional
`diagnosis_kerja`, `icd10_kode`, `alasan_rujukan`, `berlaku_sampai`,
`nomor_sep`), dan hanya terbit setelah persetujuan PDP
`berbagi_data_medis` yang disetujui -- penolakan adalah 403 dan tidak
meninggalkan baris apa pun. `nomor_surat` dan `qr_token` (UUID) ditulis
mesin; `pasien_id`/`dokter_id` adalah `prohibited`.

Persetujuan yang dipakai verifikasi ini (dicatat oleh pasien sebelum
rujukan; endpoint milik Modul 5, dipakai apa adanya):

```bash
curl.exe -s -X POST "$BASE_URL/pdp/persetujuan" \
  -H "Authorization: Bearer <ACCESS_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"jenis":"berbagi_data_medis","versi_dokumen":"v1","disetujui":true}'
```

Surat sakit:

```bash
curl.exe -s -X POST "$BASE_URL/konsultasi/8/surat-keterangan" \
  -H "Authorization: Bearer <ACCESS_TOKEN_DOKTER>" \
  -H "Content-Type: application/json" \
  -d '{"tipe":"surat_sakit","tanggal_mulai":"2026-09-29","tanggal_selesai":"2026-10-01","isi":"Pasien memerlukan istirahat sakit selama tiga hari."}'
```

Respons terverifikasi (HTTP 201, token dan nomor disamarkan):

```json
{"success":true,"data":{"surat_keterangan":{"id":1,"nomor_surat":"<NOMOR_SURAT>","konsultasi_id":8,"tipe":"surat_sakit","pasien_id":6,"dokter_id":1,"tanggal_mulai":"2026-09-29","tanggal_selesai":"2026-10-01","jumlah_hari":3,"isi":"Pasien memerlukan istirahat sakit selama tiga hari.","qr_token":"<QR_TOKEN>","file_url":null,"dibuat_at":"<TIMESTAMP>","pasien":{"id":6,"nik":null,"nama_lengkap":"<NAMA>"},"dokter":{"id":1,"nama_lengkap":"<NAMA>"},"rujukan":[]},"rujukan":null},"message":"Surat keterangan berhasil dibuat."}
```

Surat rujukan (setelah persetujuan; tanpa `tanggal_mulai` ia 422
`Surat dengan periode wajib menyertakan tanggal mulai.`):

```bash
curl.exe -s -X POST "$BASE_URL/konsultasi/8/surat-keterangan" \
  -H "Authorization: Bearer <ACCESS_TOKEN_DOKTER>" \
  -H "Content-Type: application/json" \
  -d '{"tipe":"surat_rujukan","isi":"Dirujuk untuk pemeriksaan lanjutan.","tanggal_mulai":"2026-09-29","tanggal_selesai":"2026-10-13","faskes_tujuan_id":1,"diagnosis_kerja":"ISPA","alasan_rujukan":"Perlu pemeriksaan penunjang","berlaku_sampai":"2026-10-29"}'
```

Respons terverifikasi (HTTP 201): `data.surat_keterangan` bertipe
`surat_rujukan` dengan `rujukan` berisi satu baris (`status=aktif`,
`dokter_perujuk_id` dari dokter yang masuk), dan `data.rujukan`
menggemakan baris yang sama sebagai objek tunggal (null untuk surat
non-rujukan). Pesan
`Surat rujukan berhasil dibuat beserta rujukan ke faskes tujuan.`

## 14. `GET /pasien/surat-keterangan` - 200

Daftar surat milik pemanggil, berpaginasi, `meta` sejajar. Tanpa
`permission:`/`tipe:` -- kepemilikan diputuskan oleh
`PasienRecordAccess::ownPasien()`: akun tanpa baris pasien 403, surat
milik pasien lain tidak ada (bukan ditolak).

```bash
curl.exe -s "$BASE_URL/pasien/surat-keterangan" \
  -H "Authorization: Bearer <ACCESS_TOKEN>"
```

Respons terverifikasi (HTTP 200): `data.surat_keterangan` array bentuk
yang sama seperti bagian 13 ditambah `meta` paginasi. Akun dokter (tanpa
baris pasien) menjawab 403 `This action is unauthorized.`

## 15. `GET /surat-keterangan/{nomor_surat}/verify?token=` - 200, publik

Sengaja **tanpa auth**: QR adalah artefak fisik yang dipindai resepsionis
tanpa akun. Harganya dibayar sadar: respons hanya memuat vonis, nomor
dokumen, tipe, dokter penanda, tanggal terbit, dan nama pasien yang
disamarkan kata per kata -- tanpa NIK sama sekali, tanpa isi surat, tanpa
id pengganti, dan token tidak pernah digemakan. Token salah DAN nomor yang
tidak ada menjawab `valid:false` yang sama byte per byte, sehingga
endpoint bukan oracle keberadaan. Selalu 200; satu-satunya 422 adalah
`token` yang hilang.

```bash
curl.exe -s "$BASE_URL/surat-keterangan/<NOMOR_SURAT>/verify?token=<QR_TOKEN>"
```

Terverifikasi, token benar (HTTP 200):

```json
{"success":true,"data":{"valid":true,"nomor_surat":"<NOMOR_SURAT>","tipe":"surat_sakit","dokter":"<NAMA>","tanggal":"2026-09-29","pasien_nama_masked":"D••••••••••• M•••• T•••"},"message":"Surat keterangan terverifikasi."}
```

Token salah (HTTP 200, bukan 404 -- disengaja):

```json
{"success":true,"data":{"valid":false,"nomor_surat":null,"tipe":null,"dokter":null,"tanggal":null,"pasien_nama_masked":null},"message":"Token QR tidak cocok dengan surat keterangan tersebut."}
```

Tanpa `token` (HTTP 422):

```json
{"success":false,"message":"The given data was invalid.","errors":{"token":["Token QR wajib diisi."]}}
```

## Realtime: kanal privat per konsultasi

Satu-satunya kanal yang diotorisasi aplikasi adalah
`konsultasi.{id}` (pola server tanpa prefix; klien berlangganan
`private-konsultasi.{id}`). Aturannya tunggal dan dipakai dua permukaan:
`KonsultasiChannelAccess::allows()` -- pasien konsultasi (lewat
`pasien.user_id`) atau dokternya (lewat `dokter.user_id`), dan bukan
siapa pun. Pesan disiarkan sebagai event `chat.pesan`
(`KonsultasiMessageSent`, `ShouldBroadcastNow`) dengan muatan yang sama
persis seperti baris resource REST, sehingga keduanya tidak bisa
melenceng.

Klien mengirim ke endpoint otorisasi siaran bawaan framework (grup API,
bukan grup web -- memakai yang web adalah kegagalan integrasi paling
umum):

```bash
curl.exe -s -X POST "http://127.0.0.1:8123/api/broadcasting/auth" \
  -H "Authorization: Bearer <ACCESS_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"socket_id":"123.456","channel_name":"private-konsultasi.8"}'
```

Hasil terverifikasi melawan server live:

| pemanggil | kanal | status | badan |
|---|---|---|---|
| pasien konsultasi 8 | `private-konsultasi.8` | 200 | `{"auth":"<APP_KEY>:<SIGNATURE>"}` |
| pasien konsultasi 8 | `private-konsultasi.3` (milik pasien lain) | 403 | `{"success":false,"message":"This action is unauthorized.","errors":{}}` |
| anonim | `private-konsultasi.8` | 401 | `{"success":false,"message":"Unauthenticated.","errors":{}}` |

Id kanal yang cacat (`abc`, `01`, di luar 64-bit) ditolak 403, bukan 500
-- parameternya `int|string` yang dinormalisasi eksplisit.

### Menjalankan Reverb secara lokal (tiga proses)

1. `php artisan serve --port=8123` -- API dan endpoint
   `/api/broadcasting/auth`. Tanpanya: semua recipe curl gagal
   koneksi (`Unable to connect`), dan auth socket tidak ada yang
   menandatangani.
2. `php artisan reverb:start` -- server soket (default `REVERB_HOST`
   `localhost`, `REVERB_PORT` `8080`, skema `http` sesuai `.env`).
   Tanpanya: REST tetap 200/201 (pengiriman REST tidak digabungkan ke
   broadcaster -- seluruh recipe di dokumen ini dieksekusi tanpa
   `reverb:start` berjalan dan semuanya hijau), tetapi tidak ada frame
   yang menjangkau klien soket; klien Echo menampilkan indikator
   menyambung-ulang, bukan layar kosong.
3. `npm run dev` di `web/` -- klien (`laravel-echo` 2.5.0,
   `broadcaster:'reverb'`, tanpa `cluster`, `authEndpoint:
   '/api/broadcasting/auth'` dengan header Bearer). Tanpanya: tidak ada
   UI yang berlangganan; API dan data tidak terpengaruh.

Kejujuran bukti: pengiriman soket ke klien live kedua **tidak**
didemonstrasikan dalam tugas ini -- tidak ada klien soket yang dibuka.
Yang dibuktikan live adalah otorisasi kanal (tabel di atas) dan fakta
REST-tanpa-broadcaster tetap hijau. Bentuk event dan muatannya tercakup
oleh suite (`tests/Feature/Realtime/`), bukan oleh recipe di sini.

## Kesenjangan yang diketahui (dicatat, bukan ditutupi)

- **Rantai amandemen tidak punya kolom tautan.** `rekam_medis` tidak punya
  self-FK dan tidak punya `parent_id`; rantai hanya dapat direkonstruksi
  bila setiap versi berbagi `konsultasi_id` non-null, dikelompokkan
  `(pasien_id, dokter_id, tanggal_periksa)` diurut `versi`. Untuk rekam
  tanpa konsultasi tidak ada kunci utas yang andal, dan tidak ada yang
  mencegah dua baris `versi=1, status_dokumen=final`.
- **Kode ICD-10/ICD-9 rekam medis tanpa FK dan hanya divalidasi di lapisan
  aplikasi.** `rekam_medis_diagnosa.icd10_kode` dan
  `rekam_medis_tindakan.icd9cm_kode` adalah string berindeks telanjang;
  kode yang tidak ada di master adalah 422 aplikasi, bukan penolakan
  skema.
- **Tidak ada path tulis `ulasan_dokter`** (sesuai rencana).
- **Tidak ada endpoint `rujukan` mandiri** -- 0 route ber-URI `rujukan`.
  Rujukan hanya terbit bersama `surat_rujukan`; tidak ada daftar, baca,
  atau pakai rujukan lewat HTTP.
- **Dokter tak layak absen dari direktori** -- terverifikasi live:
  `GET /dokter` hanya memuat dua dokter `terverifikasi`; dokter ketiga
  (`status_verifikasi=pending`) tidak ada. Aturan yang sama (cek tanggal
  STR + `dihapus_at`) berlaku di sisi tulis konsultasi.
- **404 untuk baris pasien lain, 403 untuk pemanggil tanpa baris** --
  terverifikasi live pada `GET /konsultasi/{id}` (404) dan
  `GET /pasien/surat-keterangan` oleh akun dokter (403). Jangan
  menyatukannya: perbedaan itu disengaja agar id bukan oracle.
- **Kode `permission:`/`tipe:` yang tidak dikenal adalah 500, bukan
  403.** `EnsurePermission` melempar `LogicException` untuk kode di luar
  `RbacCatalog::PERMISSIONS`. Tidak ada route yang membawanya (suite
  menolaknya -- setiap string `permission:`/`tipe:` di `routes/api.php`
  diparse dan diwajibkan resolve), sehingga tidak ada recipe 500 di sini;
  perilaku ini terverifikasi-kode, bukan tereksekusi-live.
- `perawat` dan `kurir` adalah nilai `users.tipe` asli yang tidak memegang
  peran apa pun, sehingga setiap `permission:` mengunci mereka permanen;
  pada permukaan ini mereka ditolak oleh fakta baris, bukan peran.
- Tidak ada endpoint daftar rekam medis -- pasien tidak bisa menelusuri
  rekamnya sendiri lewat HTTP (keputusan cakupan rencana, bukan lupa).
- `surat_kematian` dapat diterbitkan untuk pasien mana pun;
  `pasien.is_meninggal` tidak dikonsultasikan. Keterbatasan skema,
  dilaporkan apa adanya.
