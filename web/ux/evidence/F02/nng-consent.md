# F02 — Nielsen Norman Group: consent, cookie permission, onboarding, privacy copy

Format: `[URL] [akses 2026-10-01]`. Tidak dinilai sebagai aplikasi; sumber langkah untuk
kriteria 2 dan 7 pada `web/ux/patterns/F02.md`.

## Sumber

| # | Sumber | Jenis | Tanggal terbit terlihat |
|---|---|---|---|
| S1 | https://www.nngroup.com/articles/cookie-permissions/ [akses 2026-10-01] | Artikel usability NN/g | 10 Nov 2023 (artikel hidup, footer © 1998-2026) |
| S2 | https://www.nngroup.com/articles/privacy-policies-terms-use-pages/ [akses 2026-10-01] | Artikel NN/g | 26 Jul 2020 |
| S3 | https://www.nngroup.com/articles/informed-consent/ [akses 2026-10-01] | Artikel NN/g | 3 Jul 2022 |
| S4 | https://www.nngroup.com/articles/permission-requests/ [akses 2026-10-01] | Artikel NN/g | 28 Apr 2019 |

## Langkah terlihat (fakta dari artikel)

1. **Tiga pilihan selalu tersedia sejak awal**: "Accept all", "Deny all / strictly
   necessary", "Manage settings" — bila penolakan disembunyikan, pengguna mengira hanya ada
   "Accept all" [S1].
2. **Hindari pola menipu**: tombol "Accept all" berkontras tinggi, negasi ganda
   ("Do not sell…"), tombol X ambigu; tombol Close yang berarti "hanya yang esensial"
   harus ditandai jelas [S1].
3. **Kontrol granular**: banner/bingkai diminimalkan, pilihan per tujuan, bahasa sederhana
   dengan poin-poin (bukan paragraf hukum panjang) [S1].
4. **Dokumen privasi dibaca sedikit**: hanya "20–28% kata pada halaman web dibaca";
   rekomendasi: ringkasan bahasa sederhana, tanggal pembaruan, daftar isi fungsional,
   tautan konsisten di footer [S2].
5. **Consent riset: checkbox modular** — persetujuan per aspek (partisipasi, audio, video)
   lewat checkbox terpisah, bukan satu persetujuan selimut; sertakan klausul sukarela [S3].
6. **Permintaan izin (permission)**: rumus "[aplikasi] ingin mengakses [sumber] agar Anda
   dapat [manfaat]"; jelaskan alasan **sebelum** dialog sistem; hindari pola gelap; izin
   harus bisa dibatalkan/diubah [S4].

## Hitungan

Bukan aplikasi — tidak dihitung.

## State terlihat

Tidak berlaku (artikel, bukan UI). Yang disyaratkan: pilihan setara terlihat sejak awal,
tanpa langkah tersembunyi [S1].

## Red flag

Mendeskripsikan dark pattern yang harus dihindari (accept-all menonjol, negasi ganda,
tombol X ambigu) [S1].

## Yang TIDAK bisa diverifikasi

- Angka pembacaan "20–28%" berasal dari studi NN/g 2020, tanpa data primer yang di-fetch
  di artikel itu sendiri (dikutip apa adanya dari [S2]).
- Penerapan spesifik pada aplikasi kesehatan (artikel umum, bukan telemedicine).
- Tidak ditemukan artikel NN/g khusus "consent untuk data kesehatan" → **TIDAK
  TERVERIFIKASI**.
