# F10 — Halodoc (Riwayat Transaksi → hasil tes → Unduh PDF)

Flow: pasien sudah login → menemukan riwayat/hasil lama → membacanya → mengunduh PDF.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; tidak ada screenshot.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.halodoc.com/faq/questions/bagaimana-cara-mengunduh-hasil-tes-homecare-saya [akses 2026-10-01] | FAQ resmi | 6 langkah unduh PDF hasil tes Homecare |
| S2 | https://www.halodoc.com/faq/questions/bagaimana-cara-memantau-kondisi-saya-di-halodoc [akses 2026-10-01] | FAQ resmi | Riwayat Transaksi → konsultasi → Catatan Perkembangan |
| S3 | https://www.halodoc.com/faq/questions/bagaimana-cara-melihat-pesanan-saya-sebelumnya-di-halodoc [akses 2026-10-01] | FAQ resmi | Riwayat Transaksi + filter Pemesanan Obat |
| S4 | https://www.halodoc.com/faq/questions/apa-itu-rujukan-dan-bagaimana-saya-bisa-mendapatkannya [akses 2026-10-01] | FAQ resmi | Riwayat Transaksi → konsultasi → rujukan |
| S5 | https://www.halodoc.com/syarat-dan-ketentuan [akses 2026-10-01] | S&K resmi, diperbarui 18 Mar 2026 | Definisi "Riwayat Kesehatan Pengguna"; klausul pelepasan tuntutan (6.1.13–6.1.14) |
| S6 | https://www.halodoc.com/pemberitahuan-privasi [akses 2026-10-01] | Halaman privasi | **404**; versi yang dirujuk S&K = `/kebijakan-privasi` — tidak difetch → isi **TIDAK TERVERIFIKASI** |

- **Batasan riset:** app native tidak bisa diamati; bukti = FAQ resmi + S&K.

## Langkah terlihat (fakta)

1. **Unduh hasil tes Homecare** [S1]: (1) pilih **'Riwayat Transaksi'** di menu bawah; (2) pilih pesananmu; (3) **jika hasil sudah tersedia**, pilih **'Lihat Hasil'**; (4) pilih **'Unduh PDF'** di pojok kanan atas; (5) preview PDF terbuka → menu pojok kanan atas → **'Unduh'**; (6) file tersimpan ke lokasi pilihan.
2. **Catatan perkembangan konsultasi** [S2]: (1) 'Riwayat Transaksi'; (2) pilih konsultasi; (3) pilih **'Catatan Perkembangan'** di atas chatroom; (4) lihat perkembangan.
3. **Pesanan sebelumnya** [S3]: 'Riwayat Transaksi' + filter Pemesanan Obat.
4. **Rujukan** [S4]: 'Riwayat Transaksi' → pilih konsultasi → daftar rujukan.
5. **Definisi "Riwayat Kesehatan Pengguna"** [S5]: riwayat kesehatan/pemeriksaan medis, termasuk **Catatan Dokter dan/atau Rekomendasi Obat**; pengguna "memiliki kendali penuh atas bagaimana informasi kesehatan Anda dibagikan"; dapat membagikan riwayat ke dokter lain; dapat menghubungi Halodoc untuk mengelola/mencabut persetujuan.

## Hitungan

Titik awal: **pasien sudah login, ingin menemukan rekam medis/hasil lama** → tugas inti: **ditemukan, terbaca, diunduh**.

| Skenario | Layar | Ketukan/step | Field |
|---|---|---|---|
| Unduh hasil tes Homecare (FAQ bernomor) | TIDAK TERVERIFIKASI | **6 langkah** [S1] | 0 |
| Baca catatan perkembangan konsultasi | TIDAK TERVERIFIKASI | **3–4 langkah** [S2] | 0 |
| End-to-end temukan rekam medis klinis → unduh | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI — unduh hanya terbukti untuk hasil tes Homecare | TIDAK TERVERIFIKASI |

## State terlihat

- **Ketersediaan bersyarat** [S1]: "**Jika** hasilmu sudah tersedia, pilih 'Lihat Hasil'" → hasil belum siap adalah state yang diakui.
- **Loading / kosong / error / offline:** **TIDAK TERVERIFIKASI**.

## Red flag

- **Kerangka "transaksi" untuk data klinis** [S1]–[S4]: riwayat kesehatan disimpan di dalam **'Riwayat Transaksi'**, bukan layar rekam medis tersendiri → istilahnya menyesatkan untuk tugas "buka rekam medis".
- **Pelepasan tuntutan atas penyebaran riwayat** [S5, S&K 6.1.13–6.1.14]: dengan membagikan Riwayat Kesehatan ke dokter lain, pengguna "melepaskan segala tuntutan hukum dan/atau ganti rugi kepada Halodoc" atas penyebaran/penggunaan oleh penyedia lain → beban risiko dipindahkan ke pengguna.
- **Unduh PDF hanya terbukti untuk hasil tes Homecare** [S1]; catatan konsultasi **TIDAK TERVERIFIKASI** bisa diekspor.
- Privasi: halaman privasi yang dicoba **404** [S6]; detail pemrosesan data **TIDAK TERVERIFIKASI**.
- Tidak ditemukan paywall untuk unduhan.

## Yang TIDAK bisa diverifikasi

- State loading/empty/error/offline.
- Apakah catatan konsultasi/rekam medis umum bisa diekspor.
- Isi kebijakan privasi (`/kebijakan-privasi` tidak difetch).
- Tampilan layar native; data-safety store (tidak difetch langsung).
