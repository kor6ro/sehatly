# F14 — Fase 0 Sehatly: inventaris nyata (read-only)

Diambil langsung dari kode pada 2026-10-02. Titik awal: **admin klinik membuka aplikasi** (tidak ada permukaan admin hari ini, jadi hitungan tidak bisa dimulai).

## Sumber

| # | Sumber | Catatan | Akses |
|---|---|---|---|
| S1 | `routes/api.php` | 80 route `/api/v1` (README); **tidak ada satu pun route ber-prefix/segmen `admin`**; daftar `permission:` yang benar-benar dipakai: `booking.lihat`, `booking.buat`, `booking.batal`, `konsultasi.mulai`, `konsultasi.chat`, `konsultasi.selesai`, `rekam_medis.simpan`, `rekam_medis.final`, `surat_keterangan.buat`, `obat.cari`, `resep.buat`, `resep.lihat`, `resep.verifikasi`, `pesanan.buat`, `pesanan.lihat`, `pembayaran.bayar`, `notifikasi.lihat` | 2026-10-02 |
| S2 | `app/Support/Rbac/RbacCatalog.php` | 7 `users.tipe` (pasien, dokter, perawat, apoteker, kurir, admin, superadmin); 5 role; 24 permission. Role **admin** memegang: `jadwal.lihat`, `booking.lihat`, `booking.batal`, `pesanan.lihat`, `promo.validasi`, `pdp.kelola`, `audit.lihat`, `notifikasi.lihat`, `dokter.lihat`, `dokter.profil`. **Tidak** memegang `rekam_medis.lihat`, `resep.lihat`, `pembayaran.bayar` | 2026-10-02 |
| S3 | `docs/openapi.yaml` | **41 path** terpublikasi; **nol** path `/api/v1/admin*`; 4 path `dokter` semuanya GET publik (`/dokter`, `/dokter/{dokter}`, `/dokter/{dokter}/jadwal`, `/dokter/{dokter}/slot`) | 2026-10-02 |
| S4 | `database/migrations/2026_10_01_000035_dokter_jadwal_table.php` | Kolom `dokter_jadwal`: `dokter_id`, `faskes_id` NULL (`NULL = layanan online murni`), `tipe_layanan` ENUM(`online`,`klinik`,`home_visit`), **`hari` TINYINT UNSIGNED (0=Minggu..6=Sabtu)**, `jam_mulai`, `jam_selesai`, `durasi_slot_menit` default 15, `kuota_per_sesi` NULL (NULL = tanpa kuota, bukan 0), `berlaku_mulai`, `berlaku_sampai` NULL, `status_aktif` default 1. **Tanpa UNIQUE** — duplikasi/overlap jadwal adalah invariant aplikasi | 2026-10-02 |
| S5 | `database/migrations/2026_10_01_000036_dokter_libur_table.php` | `dokter_libur` hanya `dokter_id`, `tanggal`, `alasan`(200, NULL) — **libur seharian saja**; setengah hari tidak representable; tanpa unique | 2026-10-02 |
| S6 | `database/migrations/2026_10_01_000031_dokter_table.php` | `dokter`: `nomor_str` UNIQUE, `str_berlaku_sampai` NOT NULL, `nomor_sip` NULL, `sip_berlaku_sampai` NULL, `file_str_url`/`file_sip_url`, `status_verifikasi` ENUM(`pending`,`terverifikasi`,`ditolak`) default `pending`, `status_aktif` default 1, `tersedia_telemedisin` default 1, `biaya_konsultasi_online`, `jumlah_konsultasi`, `jumlah_ulasan`, `rating_rata_rata` | 2026-10-02 |
| S7 | `docs/schema-notes.md` (Batch D/E) | **`status_aktif=1` + `status_verifikasi='pending'` adalah kombinasi default yang sah** — "hanya dokter terverifikasi yang tampil" adalah aturan aplikasi (`v_dokter_katalog`), bukan constraint. Jadwal tidak self-validating: `hari=7` representable, tanpa cek overlap, `berlaku_sampai >= berlaku_mulai` tidak dijamin | 2026-10-02 |
| S8 | `database/migrations/2026_10_01_000073_audit_log_table.php` + `app/Services/Audit/*` | `audit_log` append-only (tanpa `diubah_at`), `aksi` ENUM(create,read,update,delete,login,logout,download,export), `tabel_target`, `record_id` VARCHAR(64), `data_lama`/`data_baru` JSON, `ip_address`, `user_agent`, `endpoint`, `dibuat_at`. **Tidak ada endpoint baca.** `AuditObserverRegistrar` memasang observer pada model dalam closure FK dari `pasien`/`users` (dokter & dokter_jadwal termasuk). `AuditColumnPolicy` merahasiakan: `dokter.file_str_url`/`file_sip_url` **ditolak**, `nomor_str` **dimasking** | 2026-10-02 |
| S9 | `routes/api.php` komentar baris ~1381-1399 | `pdp.kelola` **tidak punya konsumen** setelah F02, dan itu disengaja: route consent milik pemilik data; kode disimpan "for the future admin READ surface". `PdpNotificationTest` mengunci klaim ini | 2026-10-02 |
| S10 | `web/src/app/router.tsx` | 33 entri `path`; nol route admin. Menu sidebar disesuaikan per `user.tipe`, tetapi tidak ada permukaan admin yang bisa dituju | 2026-10-02 |
| S11 | `app/Models/Invoice.php`, `Pembayaran.php`, `Booking.php` | Sumber agregasi laporan yang ada: `invoice` (`nomor_invoice`, `subtotal`, `diskon`, `biaya_admin`, `total`, `status`, `lunas_at`), `pembayaran` (`invoice_id`, `metode_id`, `jumlah`, `gateway`, `status`, `dibayar_at`), `booking` (`dokter_id`, `tanggal_kunjungan`, `slot_mulai`, `status`, `nomor_antrian`). **Tidak ada endpoint agregasi/laporan** | 2026-10-02 |
| S12 | `web/ux/flows.md` F14 + `web/ux/patterns/F13.md` §7 | Booking punya 8 status (`menunggu_pembayaran`,`terjadwal`,`check_in`,`berlangsung`,`selesai`,`no_show`,`dibatalkan`,`kadaluarsa`) tetapi **`no_show` tidak punya penulis** (tidak ada endpoint) → laporan "tidak hadir" tidak dapat diandalkan hari ini | 2026-10-02 |

## Fakta yang membentuk gap F14 (semua terverifikasi dari kode)

1. **Tidak ada backend admin.** 0 route, 0 request FormRequest, 0 policy untuk tindakan admin. `docs/openapi.yaml` tidak memuat satu path admin pun [S1,S3].
2. **Empat kode izin menganggur**: `dokter.lihat`, `jadwal.lihat`, `audit.lihat` (tidak dipakai route mana pun) dan `pdp.kelola` (sengaja tanpa konsumen, disimpan untuk permukaan baca admin) [S1,S2,S9]. `dokter.profil` dipakai untuk profil dokter sendiri (bukan admin).
3. **`jam operasional` jadwal read-only**: `dokter_jadwal` & `dokter_libur` hanya bisa dibaca lewat `GET /dokter/{id}/jadwal` publik; tidak ada operasi tulis [S1,S3,S4,S5].
4. **Validasi jadwal harus dibangun di aplikasi** — skema tidak mencegah overlap, `hari=7`, `berlaku_sampai < berlaku_mulai`, atau duplikasi; dan `kuota_per_sesi=NULL` ≠ 0 [S4,S7].
5. **Libur hanya seharian** [S5] — UI tidak boleh menawarkan "libur setengah hari" seolah bisa disimpan.
6. **Audit trail sudah ada mesinnya, belum ada layarnya**: observer otomatis + redaksi, tetapi nol endpoint baca; `audit.lihat` menunggu konsumen [S2,S8].
7. **Tidak ada permission untuk menulis master dokter/jadwal.** Katalog tidak memuat `dokter.kelola`, `jadwal.kelola`, `dokter.verifikasi`, `laporan.lihat`. Menambah kode = perubahan data `RbacCatalog` + re-seed (bukan kode route) — pola yang sudah dinyatakan di komentar kode [S2].
8. **Laporan tidak mungkin dari endpoint yang ada**: daftar booking admin tidak ada (`GET /dokter/booking` = `tipe:dokter`), `GET /invoice/{id}` per-id + Policy, `GET /resep` = apoteker. Agregasi butuh endpoint baru di atas tabel `booking`/`invoice`/`pembayaran` [S1,S3,S11].
9. **Admin bukan pembaca rekam medis**: role admin tidak memegang `rekam_medis.lihat` maupun `resep.lihat` [S2] → laporan admin harus agregat/administratif, bukan klinis.
10. **Privasi kredensial**: nomor STR otomatis dimasking di audit; file STR/SIP dilarang masuk audit [S8] — handler file untuk admin perlu keputusan akses terpisah (belum ada).

## Yang TIDAK bisa diverifikasi

- Tidak bisa menjalankan alur admin (tidak ada), jadi hitungan layar/ketukan Sehatly = 0/belum ada.
- `docs/timezone-policy.md` **tidak ada di repositori** (disebut migration) — kebijakan zona jadwal belum formal; `_global.md` §5 menetapkan simpan UTC + tampil berlabel, tetapi jam dinding `dokter_jadwal` tetap waktu lokal (Asia/Jakarta) dan pertanyaan faskes WITA/WIT masih terbuka (F13 §12 #8).
