# Evidence F04 — Sehatly Fase 0 (inventaris kode nyata: profil dokter & kepercayaan)

Tanggal inventaris: **2026-10-02**. Read-only terhadap kode. Semua klaim berasal dari file yang dibaca langsung (path + baris). Ini **baseline fakta**, bukan penilaian UX.

## 1. Endpoint profil

| Method + path | Nama | File:baris |
|---|---|---|
| `GET /api/v1/dokter/{dokter}` | `dokter.show` (publik, `whereNumber`) | `routes/api.php:326-328` |
| `GET /api/v1/dokter/{dokter}/jadwal` | `dokter.jadwal` (publik) | `routes/api.php:353-355` |
| `GET /api/v1/dokter/{dokter}/slot` | `dokter.slot` (publik, `?tanggal=` wajib) | `routes/api.php:357-359` |

**Tidak ada endpoint ulasan** (`GET /dokter/{id}/ulasan`) — grep `ulasan|review` di `routes/` hanya menemukan komentar terkait; `docs/openapi.yaml` nol path `/ulasan`.

## 2. Field profil (`DokterDetailResource.php:78-111`)

Top-level: `id, nama_lengkap, foto_profil, tipe, pengalaman_tahun, bio, biaya_konsultasi_online, biaya_luar_jam, durasi_default_menit, rating_rata_rata, jumlah_ulasan, jumlah_konsultasi, tersedia_telemedisin, status_verifikasi, dibuat_at`.
Array: `spesialisasi[]` (`id, kode, nama, tipe, is_utama`, :123-134), `pendidikan[]` (`id, jenjang, institusi, tahun_lulus`, :97-105), `faskes[]` (`faskes_id, is_utama, status_aktif, kode_faskes, nama, tipe, kelas_rs, alamat`, :155-169).

**Diredaksikan sengaja** (`DokterDetailResource.php:29-39`; tes `DokterDirectoryTest.php:871-917`): `nomor_str`, `str_berlaku_sampai`, `nomor_sip`, `sip_berlaku_sampai`, `file_str_url`, `file_sip_url`, kontak langsung. Hanya `status_verifikasi` yang ditampilkan.

## 3. Model `ulasan_dokter` ada, pemakaian nol

Migrasi `database/migrations/2026_10_01_000069_ulasan_dokter_table.php:191-326`:

| Kolom | Tipe / catatan | Baris |
|---|---|---|
| `id` | PK | :194 |
| `konsultasi_id` | **NOT NULL UNIQUE** (1 konsultasi = 1 ulasan) | :212 |
| `pasien_id` | FK | :220, :304 |
| `dokter_id` | FK | :225, :305 |
| `rating` | TINYINT NOT NULL, CHECK 1–5 | :240, :324 |
| `rating_komunikasi` | TINYINT NULL, CHECK 1–5 | :247, :325 |
| `rating_akurasi` | TINYINT NULL, CHECK 1–5 | :250, :326 |
| `isi` | TEXT NULL | :255 |
| `is_anonim` | TINYINT(1) NOT NULL **DEFAULT 1** | :267 |
| `balasan_dokter` | TEXT NULL | :272 |
| `dibalas_at` | DATETIME NULL | :279 |
| `dibuat_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | :287 |

Tidak ada `diubah_at`, tidak ada kolom status/enum; hanya CHECK 1–5. Index `(dokter_id, rating)` :294.

**Referensi kode: NOL.** Tidak ada route, controller, service, atau resource yang membaca/menulis `ulasan_dokter`. Pemakaian hanya relasi model (`app/Models/Dokter.php:200-203`, `app/Models/Pasien.php:271-275`), model `app/Models/UlasanDokter.php`, dan kebijakan audit (`app/Services/Audit/AuditColumnPolicy.php:116`). Agregat `rating_rata_rata`/`jumlah_ulasan` di tabel `dokter` (`migration ...dokter_table.php:106-107`) **tidak pernah dihitung ulang dari tabel ini** — tidak ada jalur tulis ulasan sama sekali.

## 4. Jadwal: endpoint ada, tidak tampil di profil

- `GET /dokter/{dokter}/jadwal` mengembalikan `{ jadwal: {0..6} }`, tiap jendela 8 field: `jadwal_id, hari, tipe_layanan, faskes_id, jam_mulai, jam_selesai, durasi_slot_menit, kuota_per_sesi` (`DokterJadwalResource.php:104-142`).
- `dokter_jadwal` (`migration ...dokter_jadwal_table.php:92-121`): `tipe_layanan ENUM('online','klinik','home_visit')`, `hari 0=Minggu..6=Sabtu`, `berlaku_mulai`/`berlaku_sampai`, `status_aktif`.
- **Halaman detail tidak memanggil endpoint jadwal/slot sama sekali.** Grep `booking|jadwal|slot|Pesan` di `web/src/pages/doctor-detail-page.tsx` = **0 hasil**. Jadwal baru muncul di halaman booking (`/booking/:dokterId`).

## 5. UI profil (`web/src/pages/doctor-detail-page.tsx`)

- **Jadwal: tidak ada. Daftar ulasan: tidak ada** (hanya agregat `Rating` :163 dan `Jumlah ulasan` :168). **CTA booking: tidak ada** — satu-satunya navigasi adalah `Link to="/dokter"` dengan copy `Kembali ke direktori` (:108-114).
- Field yang dirender: nama, tipe, badge `Telemedisin` (:289), `Informasi dokter` (:129) berisi `Biaya konsultasi online` (:135), `Biaya di luar jam` (:140), `Durasi default` (:145), `Pengalaman` (:154), `Rating` (:163), `Jumlah ulasan` (:168), `Jumlah konsultasi` (:177), `Terdaftar` (:181); `Tentang dokter` (:187) dengan fallback `Bio belum diisi` / `Dokter ini belum menambahkan deskripsi singkat pada profilnya.` (:192-193); `Spesialisasi` (:203) + fallback `Spesialisasi belum dicatat` / `Belum ada relasi spesialisasi untuk dokter ini.` (:208-209); `Pendidikan` (:238) + fallback `Riwayat pendidikan belum dicatat` / `Belum ada data jenjang atau institusi untuk dokter ini.` (:243-244); `Fasilitas` (:295) + fallback `Belum ada afiliasi` / `Dokter ini tidak tertaut ke fasilitas kesehatan mana pun.` (:300-301), badge `Afiliasi tidak aktif` (:334); `Informasi lain` (:274) + `Tidak ditampilkan` (:354) dan catatan privasi `Nomor STR, nomor SIP, berkas STR, dan kontak langsung dokter tidak dipublikasikan. Kontak pasien ke dokter dilakukan melalui pemesanan konsultasi.` (:360-362).
- **State**: loading `Memuat profil...` (:52); error + `Kembali ke direktori` (:71, :95); `Profil dokter` (:52, :63, :82).
- `-detail` **tidak menampilkan `status_verifikasi`** (field ada di resource, dirender hanya di `Informasi lain`? — tidak; badge yang tampil hanya `Telemedisin`). Tidak ada indikator "terverifikasi" dengan teks+ikon.

## 6. Skema pendukung (fakta)

- `dokter` (`migration ...dokter_table.php:74-122`): `tipe ENUM(7)`, `nomor_str, str_berlaku_sampai, nomor_sip, sip_berlaku_sampai, nomor_ihs_satusehat, pengalaman_tahun, bio, biaya_konsultasi_online, biaya_luar_jam, durasi_default_menit, rating_rata_rata DECIMAL(3,2), jumlah_ulasan, jumlah_konsultasi, tersedia_telemedisin, status_verifikasi ENUM('pending','terverifikasi','ditolak'), file_str_url, file_sip_url, status_aktif`.
- `faskes` (`migration ...faskes_table.php:72-112`): `kode_faskes, nama, tipe ENUM(5), kelas_rs, alamat, provinsi_id, kabupaten_kota_id, kecamatan_id, latitude, longitude, akreditasi ENUM(5), jam_operasional JSON, status_aktif`.
- `master_spesialisasi` (`...000030...:48-54`): `kode UNIQUE, nama, tipe ENUM('dokter_umum','spesialis','subspesialis')`.

## 7. Yang TIDAK ada (bukti)

| Tidak ada | Bukti |
|---|---|
| Endpoint/daftar ulasan | grep `ulasan` di `routes/` = komentar; `openapi.yaml` = 0 path; tidak ada konsumen `UlasanDokter` di `app/Http` |
| Jadwal di halaman profil | `doctor-detail-page.tsx` grep `jadwal\|slot\|booking` = 0 |
| CTA pesan/booking di profil | hanya `Kembali ke direktori` (:108-114) |
| Badge verifikasi terlihat | `status_verifikasi` ada di resource tetapi tidak dirender di halaman |
| Form tulis ulasan | tidak ada route/komponen; tabel tanpa jalur tulis |
| `poliklinik` | `database/` = 0 |

## 8. Konsekuensi untuk F04 (ringkas)

1. **Profil tidak bisa dikonversi**: tidak ada CTA booking; pengunjung harus kembali ke direktori dan memilih ulang.
2. **Jadwal tidak terlihat di profil** padahal endpoint jadwal ada dan publik — keputusan "apakah dokter ini praktik hari Kamis?" tidak bisa dijawab dari profil.
3. **Ulasan tidak dapat dibaca** meski model + kolom agregat ada; angka `jumlah_ulasan` bisa menyesatkan tanpa isi.
4. **Kredensial tidak terlihat**: STR/SIP sengaja tidak dipublikasikan (keputusan privasi), tetapi tidak ada pengganti sinyal kepercayaan yang terlihat (badge "Terverifikasi" tidak dirender).
5. **State parsial ada** (bio/spesialisasi/pendidikan/faskes kosong) dengan copy jujur — modal bagus untuk dipertahankan.
