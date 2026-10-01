# F10 — NN/g: linimasa/riwayat, pencarian, empty state, filter (domain lain)

Sumber pola untuk langkah **menyusun & menemukan entri riwayat** (bukan aplikasi kesehatan).
Format: `[URL] [judul] [tanggal rilis] [akses 2026-10-01]`.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.nngroup.com/articles/alphabetical-sorting-must-mostly-die/ [akses 2026-10-01] | Artikel NN/g (Jakob Nielsen, 3 Okt 2010) | Pengurutan logis vs A–Z; "time lines" sebagai grouping |
| S2 | https://www.nngroup.com/articles/how-users-read-on-the-web/ [akses 2026-10-01] | Artikel NN/g (Jakob Nielsen, 30 Sep 1997) | Scanning: 79% scan, 16% baca kata per kata |
| S3 | https://www.nngroup.com/articles/right-justified-navigation-menus/ [akses 2026-10-01] | Artikel NN/g (Jakob Nielsen, 27 Apr 2008) | Eyetracking: scan menyusuri tepi KIRI daftar |
| S4 | https://www.nngroup.com/articles/search-visible-and-simple/ [akses 2026-10-01] | Artikel NN/g (Jakob Nielsen, 12 Mei 2001) | Pencarian terlihat & sederhana |
| S5 | https://www.nngroup.com/articles/search-no-results-serp/ [akses 2026-10-01] | Artikel NN/g (Kathryn Whitenton, 5 Jan 2014) | Pedoman halaman "tidak ada hasil" |
| S6 | https://www.nngroup.com/articles/empty-state-interface-design/ [akses 2026-10-01] | Artikel NN/g (Kate Kaplan, 19 Sep 2021) | Empty state aplikasi kompleks |
| S7 | https://www.nngroup.com/articles/filters-vs-facets/ [akses 2026-10-01] | Artikel NN/g (Kathryn Whitenton, 16 Mar 2014) | Filter vs faceted navigation |

## Langkah terlihat (fakta � sumber pedoman, bukan aplikasi)

1. **Kronologi mengalahkan alfabet** [S1]: A–Z menyembunyikan logika bawaan data; **"time lines"** dan lokasi geografis disebut eksplisit sebagai grouping logis yang lebih berguna; data ordinal "almost always" diurutkan sesuai urutannya; prioritas panjang daftar sebaiknya dari **importance/frequency**, bukan A–Z.
2. **Scanning** [S2]: 79% partisipan selalu scan halaman baru, hanya 16% baca kata per kata; teks scannable (sub-heading bermakna, daftar berbutir, satu ide per paragraf) menaikkan usability **+47%**; "marketese" menurunkan kredibilitas.
3. **Tepi kiri daftar** [S3]: pengguna menyusuri tepi kiri daftar; item dibaca lebih lanjut hanya bila 1–2 kata paling kiri menarik → **left-justify, mulai tiap baris dengan kata paling informatif, jangan diawali banyak kata sama**.
4. **Pencarian** [S4]: harus berupa **field yang bisa diketik**, di atas halaman (pojok kanan/kiri keduanya bekerja); query rata-rata **2,0 kata**; keberhasilan menurun tajam (query 1: 51%, 2: 32%, 3: 18%) dan hampir separuh yang gagal langsung menyerah; default scope "all" + nyatakan scope di atas hasil.
5. **Tidak ada hasil** [S5]: (1) jelaskan jelas tidak ada yang cocok, (2) tawarkan titik awal lanjut, (3) jangan mengejek; pesan "no matches" kecil **tidak pernah difiksasi mata** (eyetracking NexTag) → pesan kosong harus terlihat + sediakan search box berisi query asli.
6. **Empty state** [S6]: jangan default total-kosong; tampilkan **system-status message** — contoh persis: **"There are no records to display for the selected date range"**; pesan "No records" yang ternyata belum selesai loading merusak kepercayaan; sediakan jalur langsung ke tugas kunci.
7. **Filter** [S7]: filter sederhana **sering lebih cepat dipahami** daripada faset; faset lebih fleksibel tapi mahal & menambah interaction cost → pertimbangkan matang sebelum berinvestasi.

## Hitungan

Tidak berlaku (sumber pedoman, bukan aplikasi).

## State terlihat

- [S5] halaman no-results; [S6] empty state vs loading (jangan menyebut "tidak ada data" sebelum proses selesai).

## Red flag

- Tidak ada. (Opini/tip desain tidak dihitung sebagai bukti aplikasi.)

## Yang TIDAK bisa diverifikasi

- **Tidak ada artikel NN/g khusus "timeline design"** yang bisa ditemukan & di-fetch 2026-10-01 → klaim keberadaannya **TIDAK TERVERIFIKASI**; jangan disitasi.
- Angka spesifik untuk domain medis/riwayat (sumber umum, bukan domain medis).
