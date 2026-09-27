# Modul 2 - Jadwal dan Booking (BELUM LENGKAP)

> **Status: belum lengkap. Modul 2 saat ini memiliki 0 endpoint.**
> Yang sudah ada adalah satu service, `SlotAvailabilityService`, beserta 37
> test-nya, dari todo 26. Endpoint jadwal, endpoint slot, dan endpoint booking
> sedang dibangun pada todo 27, dan sisi React-nya pada todo 28. Dokumen ini
> sengaja tidak mendokumentasikan endpoint yang belum ada. Daftar lengkap
> kesenjangan ada di bagian 6.

Diverifikasi terhadap route table yang benar-benar ada:

```powershell
& "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" artisan route:list --path=api/v1
```

Hasilnya 22 route, semuanya milik Modul 1: auth, `/me`, `/pasien/*`,
`/dokter*`, dan `/master-spesialisasi`. Tidak ada satu pun route Modul 2.

## 1. Manifest berkas

| berkas | status | isi |
|---|---|---|
| `app/Services/Booking/SlotAvailabilityService.php` | ada (todo 26) | `getJadwal()`, `getSlotTerbuka()`, dan empat aturan ketersediaan slot |
| `app/Support/Dokter/StrBerlaku.php` | ada (todo 26) | batas STR bersama, satu ejaan untuk dua service |
| `app/Models/DokterJadwal.php` | ada (todo 19) | memetakan `dokter_jadwal`, `telemedicine_test.sql:470-488` |
| `app/Models/DokterLibur.php` | ada (todo 19) | memetakan `dokter_libur`, `:490-496` |
| `app/Models/Booking.php` | ada (todo 19) | memetakan `booking`, `:498-534` |
| `tests/Feature/Dokter/SlotAvailabilityTest.php` | ada (todo 26) | 37 test, 285 asersi |
| controller, route, FormRequest, Resource Modul 2 | belum ada | todo 27 |
| `web/src/features/booking/` | belum ada | todo 28 |
| seeder atau factory `dokter_jadwal` dan `dokter_libur` | tidak ada, dan memang tidak boleh ada | bagian 6.1 |

## 2. Permukaan yang sudah jadi: service, bukan endpoint

`SlotAvailabilityService` adalah satu-satunya bagian Modul 2 yang ada, dan
belum dapat dipanggil lewat HTTP. Dua method publiknya:

| method | argumen | keluaran |
|---|---|---|
| `getJadwal()` | `Dokter $dokter` | `array<int, list<array{jadwal_id, hari, tipe_layanan, faskes_id, jam_mulai, jam_selesai, durasi_slot_menit, kuota_per_sesi}>>`. Tujuh kunci selalu ada, `0` = Minggu sampai `6` = Sabtu. |
| `getSlotTerbuka()` | `Dokter $dokter`, `string $tanggal` (`Y-m-d`), `?Carbon $acuan` | `list<array{jadwal_id, jam_mulai, jam_selesai, tipe_layanan, faskes_id, tersedia, alasan}>`. Melempar `InvalidArgumentException` bila `$tanggal` bukan tanggal kalender yang nyata. |

Nilai `alasan` yang mungkin: `libur`, `penuh`, `lewat_waktu`, atau `null` bila
slot tersedia.

Empat aturan yang sudah diuji dan dikunci test; bukti lengkapnya di
`.omo/evidence/task-26-sehatly.md`:

1. **Jendela kerja.** Diambil dari `dokter_jadwal` dengan `status_aktif = 1`,
   `berlaku_mulai` tidak lebih besar dari tanggal, dan `berlaku_sampai` NULL
   atau tidak lebih kecil dari tanggal. `berlaku_sampai` bersifat inklusif.
2. **Libur.** Satu baris `dokter_libur` menutup slot hari itu. Slot tetap
   dikembalikan dengan `tersedia: false` dan `alasan: libur`, bukan dihapus,
   karena `alasan` adalah field yang ada untuk membawa alasannya.
3. **Tabrakan.** Setiap `booking` dengan `status NOT IN ('dibatalkan',
   'kadaluarsa')` yang tumpang tindih adalah konsumen slot. Kuota dibaca sebagai
   `count < (kuota_per_sesi ?? 1)`, sehingga booking ke-k mengisi kuota k. Slot
   yang berakhir tepat ketika slot berikutnya mulai, dan sebaliknya, tidak
   dianggap tumpang tindih.
4. **STR.** `dokter.str_berlaku_sampai` dibandingkan terhadap tanggal
   konsultasi, inklusif, dan gagal-tertutup bila NULL.

Semua nilai waktu adalah jam dinding `Asia/Jakarta`
(`SlotAvailabilityService::ZONA_WAKTU`) dan tidak pernah dikonversi ke UTC.

## 3. Tabel endpoint

| # | Metode | Path | Auth | Status |
|---|---|---|---|---|
| - | - | - | - | Belum ada endpoint Modul 2. Lihat bagian 1. |

Yang akan ditambahkan todo 27, mengikuti teks rencana:
`POST /api/v1/booking`, `GET /api/v1/pasien/booking`, `GET /api/v1/dokter/booking`,
dan `PUT /api/v1/booking/{id}/batalkan`. Dan mengikuti nama yang dipakai
docblock `SlotAvailabilityService` untuk dua methodnya:
`GET /api/v1/dokter/{dokter}/jadwal` dan
`GET /api/v1/dokter/{dokter}/slot?tanggal=YYYY-MM-DD`.

Jangan mendokumentasikan keenam endpoint itu sebagai tersedia. Yang ada
sekarang adalah 22 route Modul 1, dan itulah yang akan dijawab route table.

## 4. Resep curl

Tidak ada, karena tidak ada endpoint Modul 2 untuk dipanggil.

Yang bisa diuji hari ini hanya permukaan Modul 1, yang resep lengkapnya sudah
ada di `modul-1-auth.md`, `modul-1-pasien.md`, dan `modul-1-dokter.md`. Urutan
tokennya sama seperti yang akan dipakai Modul 2: `POST /auth/register`, lalu
`POST /auth/otp/verify` dengan `tujuan = verifikasi_telepon`, dan
`data.token.access_token` dipakai sebagai header `Authorization: Bearer`.

## 5. Tes

Berkas Pest Modul 2 yang ada:

- `tests/Feature/Dokter/SlotAvailabilityTest.php` - 37 test, 285 asersi.

Perintah:

```powershell
& "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" artisan test --filter=SlotAvailabilityTest
```

Perintah untuk seluruh suite, yang mencakup Modul 1 dan Modul 2:

```powershell
& "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" artisan test
```

Angka hasil ukur ada di `.omo/evidence/task-30-sehatly.md` bagian 7; dokumen ini
tidak mengulangnya supaya tidak basi setiap kali suite bertambah test.

## 6. Kesenjangan yang diketahui

### 6.1 Modul 2 sendiri

- **Nol endpoint.** Todo 26 membangun service dan 37 test-nya, tetapi brief todo
  26 melarang controller dan route, sehingga kriteria rencana "`route:list
  --path=api/v1/dokter` memuat 2 route baru" tidak dapat dipenuhi. Kekosongan
  itu dilaporkan di `.omo/evidence/task-26-sehatly.md` temuan F1, bukan
  diselesaikan secara diam-diam.
- **Tidak ada jalur tulis `dokter_jadwal` maupun `dokter_libur`.** DDL tidak
  menyediakan endpoint tulis untuk keduanya dan rencana tidak mendaftarkannya,
  sehingga setiap jendela dan setiap hari libur di seluruh suite dibuat oleh
  builder milik test itu sendiri. `database/factories` masih hanya berisi
  `UserFactory`.
- **Tidak ada controller, FormRequest, Resource, atau pemakaian `ApiResponse`**
  untuk Modul 2.
- **Sisi React belum ada.** Todo 28, yaitu kalender, pemilih slot, form booking,
  dan daftar booking, belum dikerjakan; `web/src/features/booking/` tidak ada.
- **Tidak ada seeder dev untuk Modul 2**, jadi direktori dokter tidak menampilkan
  satu pun dokter dengan jadwal. Lihat `docs/modules/modul-1-dokter.md`.

### 6.2 Kesenjangan yang dibuka oleh penghapusan scaffold web (todo 30)

Sebelas test scaffold yang dihapus todo 30 menguji kapabilitas yang tidak ada
di kontrak ini. Lima hal berikut tidak punya padanan API apa pun, dan semuanya
dilaporkan, bukan dihapus diam-diam. Rincian per-test ada di
`.omo/evidence/task-30-sehatly.md` bagian 3; eksekusinya dikunci di
`tests/Feature/WebSurfaceTest.php`.

| # | Kesenjangan | Bukti DDL bahwa ini masih mungkin |
|---|---|---|
| G1 | Tidak ada reset kata sandi maupun ganti kata sandi. | `users.kata_sandi_hash` ada, `telemedicine_test.sql:138`, `NOT NULL`, dan `user_otp.tujuan` memuat `'reset_kata_sandi'`, `:183`. Tabel `password_reset_tokens` yang dirujuk `config/auth.php:98` tidak ada di skema maupun di database. |
| G2 | Tidak ada ganti alamat email, sehingga `users.email_terverifikasi` tidak pernah bisa menjadi `true` lewat API. | `users.email` adalah `VARCHAR(255) NULL UNIQUE`, `:136`, dan `user_otp.tujuan` memuat `'verifikasi_email'`. Kolom `email_verified_at` tidak ada. |
| G3 | Tidak ada penghapusan akun oleh pemiliknya sendiri. | `users.dihapus_at DATETIME NULL`, `:148`, dengan `SoftDeletes` dan `DELETED_AT = 'dihapus_at'`. Tidak ada endpoint yang memanggilnya. |
| G4 | Tidak ada padanan konfirmasi kata sandi, atau step-up auth. | Tidak ada kolom dan tidak ada OTP purpose untuk itu. Yang paling mendekati adalah mengulang `POST /auth/login` lalu `POST /auth/otp/verify` dengan `tujuan = login`, yang menerbitkan ulang pasangan token tetapi bukan gerbang step-up. |
| G5 | Dua dari empat nilai `user_otp.tujuan` tidak terjangkau lewat route mana pun. | `OtpService::TUJUAN` berisi empat nilai, sedangkan `TUJUAN_DI_TERBITKAN` hanya `['verifikasi_telepon', 'login']`. |

Empat kesenjangan pertama disengaja tidak ada di rencana, karena tabel endpoint
Modul 1 tidak mendaftarkannya. Kolom untuk keempatnya semuanya ada di DDL,
sehingga sebuah todo berikutnya bisa menambahkannya tanpa perubahan skema. G5
adalah akibat langsung dari G1 dan G2.

### 6.3 Warisan lain

- Tidak ada jalur tulis `ulasan_dokter`, sesuai rencana.
- `GET /api/v1/master-spesialisasi` ada di luar tabel endpoint spec, sesuai
  rencana.
- 14 tabel yatim modul: modelnya ada, tetapi tidak ada Resource maupun
  Controller, sesuai rencana.
- `docs/timezone-policy.md`, yang dirujuk rencana todo 26 sebagai otoritas zona
  waktu, belum ada; itu deliverable todo 51. Aturannya sendiri dinyatakan di
  docblock `SlotAvailabilityService` beserta rujukan DDL-nya, dan diuji di sana.
