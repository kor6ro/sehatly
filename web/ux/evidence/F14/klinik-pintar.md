# F14 — Klinik Pintar (klinikOS): jadwal nakes, akses pengguna, laporan pemilik

Titik awal hitungan: **admin klinik membuka aplikasi → tugas inti selesai** (ubah jadwal tenaga medis / ubah akses pengguna / lihat laporan).

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://bantuan.klinikpintar.id/books/panduan-aplikasi-klinik-pintar/page/menambahkanmengedit-jadwal-tenaga-medis | Help center resmi (fetch langsung) | Form Jadwal Reguler: hari, jam mulai/selesai, slot ATAU estimasi, status aktif, kadaluarsa opsional, metode aturan opsional; slot = kuota pasien | 2026-10-02 |
| S2 | https://bantuan.klinikpintar.id/books/panduan-aplikasi-klinik-pintar/page/menambahkan-praktik-tenaga-medis | Help center resmi | Alur 2 tahap: **+Jadwal Praktik** (poli + nakes) lalu **+Jadwal Reguler**; menu Jadwal Dokter → Kelola Jadwal | 2026-10-02 |
| S3 | https://bantuan.klinikpintar.id/books/panduan-aplikasi-klinik-pintar/page/menambahkan-akses-pengguna | Help center resmi (fetch langsung) | "Kelola Akses": centang fitur = **view/hanya lihat**, radio **Kelola** = akses edit; akses diberi nama | 2026-10-02 |
| S4 | https://bantuan.klinikpintar.id/books/panduan-aplikasi-klinik-pintar/page/menambahkan-akun-pengguna | Help center resmi (fetch langsung) | Peran akun: **Admin Klinik** (full view+edit), **Owner** (hanya dasbor & laporan), **User** (sesuai akses yang dibuat); akun nakes dibuat terpisah via Master Data → Tenaga Kesehatan → "Buat Akun Nakes" | 2026-10-02 |
| S5 | https://bantuan.klinikpintar.id/books/panduan-aplikasi-klinik-pintar/page/laporan-pendapatan dan revisi /revisions/529 | Help center resmi | Laporan Pendapatan: pilih periode tanggal → export → **popup konfirmasi** → file Excel terunduh; ada "Lihat Detil" per hari | 2026-10-02 |
| S6 | https://bantuan.klinikpintar.id/books/panduan-aplikasi-klinik-pintar/page/laporan-layanan-unduh-menjadi-file-excel | Help center resmi | Laporan Layanan: jumlah layanan + pendapatan per periode, unduh Excel | 2026-10-02 |
| S7 | https://akp.app.klinikpintar.id/fitur/laporankeuangan/detail | Halaman produk resmi | Klaim fitur: pendapatan dipecah per pembiayaan/metode pembayaran/poli/layanan/obat/transaksi; unduh laporan | 2026-10-02 |
| S8 | https://bantuan.klinikpintar.id/books/faq | Help center resmi | FAQ aktif (maintenance, kendala) | 2026-10-02 |
| S9 | https://klinikpintar.id/blog-klinik/portal-panduan-penggunaan-aplikasi-klinik-pintar | Blog resmi | Portal panduan aktif | 2026-10-02 |

**Verifikasi 2026:** aktif — help center & blog dapat diakses 2026-10-02, situs produk `akp.app.klinikpintar.id` aktif, halaman FAQ memuat prosedur kendala terkini. Versi produk: tidak menyebut nomor versi; screenshot panduan 2024.

## Langkah terlihat (fakta)

1. **Melihat/mengedit jadwal** [S1]: menu **Jadwal Praktik → Kelola Jadwal** → cari nama nakes → **Edit Nakes** (ikon pensil) → **+Jadwal Reguler** → isi form → **Simpan**. "Lakukan alur yang sama untuk menambahkan jadwal dokter di hari lainnya."
2. **Isi form jadwal reguler** [S1]: pilih hari; pilih jam mulai & selesai (jam bisa diketik dulu); tentukan **estimasi waktu pelayanan ATAU jumlah slot** (salah satu terisi otomatis dari jam praktik); **status jadwal aktif/non-aktif**; **kadaluarsa jadwal opsional** (untuk jadwal sementara); **metode aturan opsional**. Slot didefinisikan sebagai **kuota pasien** (contoh: 25 pasien per jam praktik; tanpa batas = isi angka besar).
3. **Prasyarat data** [S1,S2]: jadwal hanya bisa ditambah setelah **poliklinik** dan **tenaga medis** ada; praktik (poli+nakes) ditambahkan sebelum jadwal reguler.
4. **Akses pengguna** [S3]: Master Data → Akun → **Kelola Akses** → **+Akses** → beri **Nama Akses**, centang fitur (view) dan/atau aktifkan radio **Kelola** (edit) per fitur → Simpan.
5. **Akun pengguna & peran** [S4]: Master Data → Akun → +Akun → data user (username unik, email pribadi, +62, telepon) → **Peran**: Admin Klinik (full), Owner (hanya dasbor+laporan), User (akses custom dari langkah 4). Akun nakes: Master Data → Tenaga Kesehatan → Edit Nakes → **Buat Akun Nakes** + password.
6. **Laporan** [S5,S6]: menu **Closing & Laporan → Laporan Keuangan → Pendapatan** (atau Layanan) → pilih periode tanggal → baca angka harian/jumlah pasien/jumlah invoice → **export** → popup konfirmasi → Excel detail terunduh.
7. **Konfirmasi export** [S5 revisi 529]: klik export → "Akan muncul popup untuk konfirmasi, klik export maka akan terunduh file excel".

## Hitungan

Dari **admin membuka aplikasi** → **ubah jadwal nakes** (jadwal sudah ada; asumsi):

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 3 (Kelola Jadwal → form nakes → form Jadwal Reguler) | S1 |
| Ketukan | ±6 (Edit Nakes, +Jadwal Reguler, pilih hari, jam, status, Simpan) | S1 |
| Field | 6–8 (hari, jam mulai, jam selesai, slot/estimasi, status, kadaluarsa, metode aturan) | S1 |

**Tambah 1 jadwal per hari lain = ulangi seluruh form** (tidak ada "salin ke hari lain" di dokumentasi) [S1].
**Laporan periode → export Excel**: 2 layar, ±4 ketukan + 1 popup konfirmasi [S5].

## State terlihat

- **Konfirmasi**: popup konfirmasi sebelum export [S5 revisi 529].
- **Prasyarat**: alur diblokir sampai poliklinik & tenaga medis ada (dinyatakan di catatan, bukan pesan error yang terlihat) [S1].
- **Detail per hari**: tombol "Lihat Detil" (ikon kaca pembesar) pada laporan [S5].
- **Loading/kosong/error/offline**: **TIDAK TERVERIFIKASI** di sumber publik.
- **Akses ditolak**: tidak ada bukti tampilan; hanya deskripsi pembatasan akses [S3].

## Red flag

- Tidak ditemukan dark pattern pada sumber resmi: tidak ada hitung mundur palsu, upsell menutupi aksi, atau izin tanpa konteks.
- Catatan operasional: menghapus/menonaktifkan jadwal yang sudah punya reservasi **tidak dibahas** di halaman jadwal (tidak ada bukti pengamanan); ini dicatat sebagai **TIDAK TERVERIFIKASI**, bukan red flag.
- Kepatuhan: halaman **Verifikasi Profil Pasien (KYC)** & integrasi SATUSEHAT/BPJS ada di help center (tidak dinilai F14).

## Yang TIDAK bisa diverifikasi

- Sisi dalam aplikasi (di balik login): layout asli, jumlah klik presisi, state loading/kosong/error/offline.
- Konflik jadwal (overlap) & penghapusan jadwal yang sudah terpakai booking: tidak ada dokumentasi.
- Audit trail perubahan jadwal/akses: tidak ada dokumentasi.
- Apakah export punya batas baris/rentang tanggal.
- Versi aplikasi dan tanggal rilis.
