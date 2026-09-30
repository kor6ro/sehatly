# F06 Pembayaran — Baymard Institute (sumber riset, bukan aplikasi)

Baymard **bukan aplikasi** → tidak dihitung layar/ketukan dan **tidak dinilai sebagai
kandidat pemenang** pada `web/ux/scores/F06.md`. Dipakai sebagai sumber rekomendasi usability
checkout/payment yang terpublikasi publik.

**Tanggal akses semua sumber: 2026-10-01.** Tanpa login, tanpa bayar, tanpa bypass paywall.
Studi penuh berbayar (Baymard Premium) **tidak dibuka**.

## Sumber

| # | URL | Tipe sumber | Akses |
|---|---|---|---|
| 1 | https://baymard.com/publications | — | **HTTP 404** (URL lama, tidak dipakai) |
| 2 | https://baymard.com/blog/payment-ux | Artikel publik (gratis) | 2026-10-01, terbit 1 Jun 2026 |
| 3 | https://baymard.com/blog/checkout-flow-ux-optimization | Artikel publik | 2026-10-01, terbit 23 Apr 2026 |
| 4 | https://baymard.com/research/checkout-usability | Ringkasan riset publik | 2026-10-01 |
| 5 | https://baymard.com/blog/current-state-of-checkout-UX | Artikel publik | 2026-10-01 (via pencarian) |
| 6 | https://baymard.com/blog/audit-checkout-flow-hidden-friction | Artikel publik | 2026-10-01 (via pencarian) |
| 7 | https://baymard.com/lists/cart-abandonment-rate | Statistik publik | 2026-10-01 (via pencarian) |

**Status aktif 2026 — TERVERIFIKASI:** situs live, footer `© Baymard Institute 2026`,
artikel ber-tanggal 2026.

## Langkah terlihat

Tidak berlaku — Baymard tidak memiliki alur checkout sendiri. Yang terlihat adalah
**rekomendasi yang dipublikasikan** (dikutip singkat, diparafrase/diolah ulang untuk Sehatly):

- **Total wajib terlihat sebelum detail pembayaran:** *"The complete order total — inclusive
  of all shipping costs, taxes, and fees — must be visible before users are asked to enter
  payment details."* — #2.
- **Metode bayar jangan ditampilkan sekaligus:** kelompokkan (kartu, e-wallet, BNPL),
  preselect metode paling umum, jangan "auto-funnel" ke pihak ketiga tanpa pilihan sadar,
  ubah label CTA bila redirect keluar — #2, #3.
- **Jangan menghapus data yang sudah diisi saat error:** *"never clear entered data when
  there's an error — especially card data (34% of sites fail to retain data after a
  validation error)."* — #2.
- **Pesan error spesifik:** *"Error messages should use plain language to state exactly what
  is wrong and how to fix it … yet 98% of sites don't do this."* — #3.
- **Checkout linear:** *"keep your process completely linear – never show the same page
  twice."* — #3.
- **Jangan memaksa daftar akun:** guest checkout harus paling menonjol — #3, #5.
- **Statistik:** cart abandonment rata-rata 70,19–70,22% (#4, #7); alasan teratas = **40%
  biaya tambahan**, 19% tidak percaya metode bayar, 12% tak bisa lihat total di awal (#7);
  64% desktop & 63% mobile checkout "mediocre atau lebih buruk" (#5).

## Hitungan

**TIDAK TERVERIFIKASI / tidak berlaku:** Baymard bukan aplikasi → tidak ada layar, ketukan,
atau field yang bisa dihitung dari titik awal F06.

## State terlihat

Tidak berlaku (artikel, bukan aplikasi). State yang **direkomendasikan** Baymard: pesan
error per-field dengan langkah perbaikan, autoscroll ke field error, data terbukti saat
validasi gagal (#2, #6).

## Red flag

- **PAYWALLED:** studi penuh (Checkout Usability report 718 halaman, 134 guidelines, database
  benchmark 41.000+ skor) berbayar — **tidak dibuka, tidak diakali** (#4).
- **`https://baymard.com/publications` → 404** — URL yang benar: `/blog/...` dan
  `/research/checkout-usability` (#1).
- **Tidak ada** indikasi dark pattern di sisi Baymard; sebaliknya Baymard adalah sumber
  anti-dark-pattern.

## Yang TIDAK bisa diverifikasi

- **TIDAK TERVERIFIKASI:** isi lengkap panduan premium (`/premium/...`, GC050) — tidak
  diakses.
- **TIDAK TERVERIFIKASI:** angka benchmark per-industri e-commerce Indonesia (data Baymard
  dominan pasar Barat) → jangan dipakai sebagai angka pasti untuk Sehatly.
- **TIDAK TERVERIFIKASI:** angka "134 guidelines"/"41.000+ skor" dari sumber primer — hanya
  terbaca di ringkasan halaman riset publik.
