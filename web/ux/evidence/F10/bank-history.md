# F10 — Pola riwayat transaksi bank/fintech: pengelompokan, filter, ekspor (domain lain)

Sumber pola untuk langkah **menyusun linimasa riwayat + filter + ekspor** (bukan kesehatan).
Format: `[URL] [akses 2026-10-01] [jenis sumber]`.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://merchanthelp.bankofamerica.com/Transaction_View_and_Export_in_Merchant_Services [akses 2026-10-01] | Dokumentasi bank (publik), halaman tertanggal 1 Okt 2026 | Default 7 hari, filter rentang 13 bulan, ekspor ≤2.500 baris CSV/JSON |
| S2 | https://cpsportal.jackhenry.com/content/webhelp/Transaction_History.html [akses 2026-10-01] | Dokumentasi fintech (publik), © 1999–2026 | Pencarian rentang 1 tahun, batas 1.000 record, ekspor Excel/CSV, grouping & sortir kolom |
| S3 | https://www.swedbank.ee/private/d2d/accounts/info?language=ENG [akses 2026-10-01] | Dokumentasi bank (publik) | Riwayat dari 2000, maks 1.000 entri tampil, cari nama/jumlah (parsial/tidak case-sensitive), simpan xls/csv/pdf/asice |
| S4 | https://www.saasui.design/blog/saas-activity-feed-audit-log-ux-patterns [akses 2026-10-01] | Artikel pola industri (Rakesh Mondal, 25 Jul 2026) | Date divider, waktu relatif+absolut, filter, state ujung riwayat |
| S5 | https://designpixil.com/blog/fintech-dashboard-design [akses 2026-10-01] | Artikel agensi (Anant Jain, update Sep 2026) | Tabel transaksi: pilih kolom, filter aditif+chip, status teks+warna, paginasi vs infinite scroll |
| S6 | https://tiffwdesign.com/bank-transaction-history-search-filter [akses 2026-10-01] | Case study portofolio (proyek sejak Mar 2024, TD Bank US) | Advanced search & filter di transaction history; **detail UI TIDAK TERVERIFIKASI** |

## Langkah terlihat (fakta � sumber pedoman, bukan aplikasi)

1. **Rentang default & batas** [S1]: Transaction List default **7 hari terakhir**; filter rentang hingga **13 bulan**; ekspor maks **2.500** hasil ke **CSV/JSON**, file memuat **filter/kustomisasi yang diterapkan**; melebihi → wajib menyaring dulu.
2. **Batas keras per query** [S2]: pencarian rentang hingga 1 tahun, tetapi satu query hanya mengembalikan **1.000 record terbaru**; ekspor **Excel/CSV**; grouping dengan men-drag kolom; sortir klik header; show/hide kolom; filter per kolom; items per page; preferensi grid tersimpan.
3. **Pencarian transaksi** [S3]: dari tahun 2000; maks **1.000 entri** tampil sekaligus; cari nama payer/payee, detail pembayaran, nomor akun, atau **jumlah**; mendukung **bagian kata maupun frasa persis, tidak case-sensitive**; filter kategori (kartu, masuk, keluar, tunai); **group by payee/payer**; simpan xls/csv/pdf/asice (format narrow mempertahankan grouped & filtered).
4. **Linimasa terkelompok** [S4]: kelompokkan per hari dengan **date divider ("Today", "Yesterday", tanggal)**; default **waktu relatif** ("3 minutes ago") dengan **timestamp absolut** saat hover/sekunder (untuk audit log, absolut + zona waktu jadi requirement); event terkait bisa di-collapse; **state ujung: "Beginning of history" / "No earlier activity"**; loading = **skeleton row**; filter = actor, object, tipe event, rentang tanggal; search melengkapi filter.
5. **Tabel transaksi** [S5]: pilih urutan kolom sesuai alur kerja + kontrol kolom tersimpan; **sort & filter jadi kontrol primer**; **filter aditif dengan chip aktif yang terlihat & bisa dihapus satu per satu**; **status = warna + label teks, bukan warna saja**; **paginasi hampir selalu lebih baik dari infinite scroll** untuk tabel transaksi (tahu jumlah record, lompat halaman, kembali ke posisi); angka tabular, amount rata kanan; **freshness stamp** ("As of 09:41 UTC"); progressive disclosure ringkasan → detail → audit trail.
6. **Pencarian/filtel di transaction history** [S6]: bukti industri memakai advanced search & filter; **semua detail UI dari sumber ini TIDAK TERVERIFIKASI** (case study sangat tipis) → jangan dipakai untuk klaim spesifik.

## Hitungan

Tidak berlaku langsung (domain lain); batas numerik di atas dipakai sebagai pertimbangan kapasitas daftar (mis. paginasi & batas baris), bukan hitungan ketukan aplikasi kesehatan.

## State terlihat

- Loading (skeleton row), empty, dan **end-of-history** ("Beginning of history") [S4]; pesan "no matching" per NN/g (lihat `nng-history-search.md`).

## Red flag

- Tidak ditemukan dark pattern di sumber-sumber ini.
- Batas 1.000/2.500 baris adalah keterbatasan teknis yang **dijelaskan terbuka** — bukan dark pattern; patut ditiru transparansinya.

## Yang TIDAK bisa diverifikasi

- Metrik riset pengguna apa pun (sumber = dokumentasi fitur & artikel pola, bukan studi usability).
- [S6] detail pola apa pun (date grouping, jumlah klik, layout filter).
- Kelayakan batas-batas tersebut untuk data rekam medis Sehatly (menyesuaikan produk).
