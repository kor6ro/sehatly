# F10 — Alodokter (riwayat konsultasi / EMR — kandidat lemah)

Flow: pasien sudah login → menemukan riwayat/rekam medis lama → membacanya → unduh.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; tidak ada screenshot.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.alodokter.com/syarat-dan-ketentuan [akses 2026-10-01] | S&K resmi, diperbarui 26 Agu 2026 | Definisi "Riwayat Kesehatan Pengguna"; daftar layanan **tanpa** fitur rekam medis pasien |
| S2 | https://www.alodokter.com/privasi [akses 2026-10-01] | Kebijakan privasi resmi | Info kesehatan pribadi tidak ditampilkan ke pihak lain selain pengguna & penyedia layanan |
| S3 | https://play.google.com/store/apps/details?hl=id&id=com.alodokter.android [akses 2026-10-01] | Store listing resmi | Update 23 Sep 2026, 4,8★, 10 jt+; data safety: membagikan Lokasi, Info pribadi, Audio ke pihak ketiga |
| S4 | https://apps.apple.com/id/app/alodokter-chat-bersama-dokter/id1405482962 [akses 2026-10-01 via indeks pencarian] | Store listing resmi | v8.5.0 (3 Jun): "membaca riwayat chat" |
| S5 | https://news.dailysocial.id/post/alodokter-electronic-medical-record/ [akses 2026-10-01] | Press (DailySocial, 24 Mar 2023) | EMR diluncurkan; manfaat untuk tenaga medis |
| S6 | https://infokomputer.grid.id/... [akses 2026-10-01] | Press (InfoKomputer, 4 Apr 2023) | EMR: riwayat kesehatan pasien + jejak medis dokter |
| S7 | https://www.alodokter.com/sekarang-semua-bisa-ngobrol-gratis-dengan-dokter [akses 2026-10-01] | Artikel resmi lama (2016) | Klaim lama "layanan rekam medis" — tidak dikonfirmasi sumber 2026 |

## Langkah terlihat (fakta)

1. **Akses riwayat pasien yang terbukti** [S4]: "membaca riwayat chat tanpa terganggu, langsung kembali ke pesan terbaru, hingga membalas atau menyalin pesan penting saat berkonsultasi" — yaitu **riwayat chat**, bukan rekam medis klinis.
2. **Definisi S&K** [S1]: "Riwayat Kesehatan Pengguna" = informasi kondisi medis, diagnosis, pengobatan, dan riwayat konsultasi; layanan eksplisit = artikel, Chat Bersama Dokter, Buat Janji, Aloshop, langganan, Alochoice — **tidak ada fitur "rekam medis" khusus pasien**.
3. **EMR untuk tenaga medis** [S5][S6]: fitur Electronic Medical Record mencatat riwayat kesehatan pasien dan jejak medis dokter (history chat, riwayat tindakan, pembelian obat); manfaat dinyatakan "membantu dokter mengakses informasi pasien" — **bukan** fitur pasien.
4. **Klaim lama** [S7, 2016]: "Layanan rekam medis yang memudahkan kamu mengakses kondisi kesehatanmu setiap saat" — tidak dikonfirmasi sumber 2026.

## Hitungan

| Skenario | Layar | Ketukan/step | Field |
|---|---|---|---|
| Temukan rekam medis lama → baca | **TIDAK TERVERIFIKASI** — tidak ada langkah resmi untuk tugas ini | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI |
| Unduh/ekspor | **Tidak ditemukan bukti adanya fitur** | — | — |

## State terlihat

- **TIDAK TERVERIFIKASI** untuk loading/kosong/error/offline.

## Red flag

- **Berorientasi dokter, bukan pasien** [S5][S6]: EMR dipresentasikan sebagai alat tenaga medis; akses pasien atas rekam medis lengkap tidak dibuktikan.
- **Data safety store** [S3]: aplikasi dapat membagikan Lokasi, Info pribadi, dan Audio ke pihak ketiga.
- **Ketidakkonsistenan dokumen** [S1] vs [S7]: klaim "rekam medis" 2016 tidak muncul di S&K 2026.
- Tidak ditemukan paywall.

## Yang TIDAK bisa diverifikasi

- Langkah/tap apa pun untuk menemukan rekam medis.
- State apa pun.
- Apakah pasien bisa melihat hasil lab/resep sendiri; apakah ada unduh/PDF.
- Apakah fitur EMR pasien benar ada di versi 2026.

**Kesimpulan riset:** kandidat dipertahankan untuk dicatat, tetapi **tidak layak jadi sumber pola** untuk F10 (tugas inti tidak terbukti ada di produk).
