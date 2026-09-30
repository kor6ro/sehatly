# Evidence F08 — Amwell (kunjungan video + waiting room)

Titik awal hitungan (berlaku semua kandidat F08): **user membuka ruang konsultasi** (di sini: daftar/kunjungan yang tersedia). Tugas inti: **satu pesan terkirim dan diakui**, atau sesi ditutup.

## Sumber

| # | URL | Jenis sumber | Tanggal akses | Versi/platform |
|---|---|---|---|---|
| 1 | https://patients.amwell.com/how-it-works | Situs pasien resmi — 3 langkah (Enroll/Choose/Visit) + grafis UI | 2026-10-01 | Web (AS) |
| 2 | https://patients.amwell.com/services/online-urgent-care | Situs pasien resmi — daftar dokter + tombol "Connect", waiting room + notifikasi SMS, FAQ | 2026-10-01 | Web (AS) |
| 3 | https://patients.amwell.com/blog/2020/02/what-to-expect-first-online-doctor-visit | Blog resmi pasien — langkah kunjungan pertama, antrean terlihat ("patients waiting ahead of you"), ±10 menit | 2026-10-01 | Web |
| 4 | https://patients.amwell.com/how-it-works/troubleshooting | Situs pasien resmi — FAQ pemecahan masalah | 2026-10-01 | Web |
| 5 | https://providers.amwell.com/how-a-telemedicine-visit-works dan https://providers.amwell.com/faq | Situs penyedia resmi — pemberitahuan pasien masuk waiting room, tinjau intake sebelum video | 2026-10-01 | Web |
| 6 | https://play.google.com/store/apps/details?id=com.americanwell.android.member.amwell&hl=en | Listing Google Play (deskripsi + tangkapan layar, "Updated on Sep 21, 2026") | 2026-10-01 | Android |
| 7 | https://investors.amwell.com (Q2 2026 4 Agu 2026; Q1 2026 5 Mei 2026) dan siaran pers VA LOI 8 Sep 2026 | Hubungan investor/siaran pers — bukti masih beroperasi 2026 | 2026-10-01 | Web |
| 8 | Dokumen institusional pihak ketiga (ditemukan via indeks pencarian, tidak terunduh penuh): manual Amwell 2020 (americanwell-atlas.s3.amazonaws.com), McLaren Health Plan (PDF >5 MB, gagal diunduh), Northern Light Health "Amwell Office Visit Workflow" (27 Agu 2025) | Dokumen institusional — detail sisi penyedia (timer antrean, chat box, "Switch to Phone", wrap-up) | 2026-10-01 | PDF/halaman institusi; **kualitas sumber rendah-sedang, sebagian lama** |

**Batas akses:** tanpa akun, tanpa login; UI pasien tidak diamati. Halaman resmi #1–#5 teks + grafis pemasaran; tangkapan layar di #6. Detail dalam-panggilan terperinci hanya dari #8 (pihak ketiga, sebagian 2020) → dipakai hati-hati.

## Langkah terlihat (fakta)

1. Kunjungan on-demand (#2): "doctors who are available for a visit will appear on your screen… select who you want to see by clicking on the **green 'Connect' button**."
2. Antrean (#2): "**If no one is available, you will be placed in a 'waiting room' and notified by text message once a doctor becomes available.**"
3. Kedalaman antrean terlihat sebelum masuk (#3): "you can also see **if any patients are waiting ahead of you**."
4. Durasi (#3): "On average, patients wait 10 minutes or fewer to see a doctor" (klaim); kunjungan tipikal 10 menit (#2).
5. Langkah konsumen (#6, deskripsi listing): "Download the app / Choose the type of visit / Choose your provider."
6. Konten pasca-kunjungan (#2, #4): ringkasan sesi & after-visit summary hanya dibagikan bila pasien setuju ("You will be asked if you want a copy of your session notes… shared with your primary care provider"); slip sakit masuk sebagai secure message.
7. Sisi penyedia (#5): "We'll notify you when a patient enters your waiting room… review the patient's intake information before initiating the video visit."
8. Fallback koneksi (#8, sumber pihak ketiga/lama — diperlakukan hati-hati): "Switch to Phone" dan chat box dalam kunjungan, timer berapa lama pasien menunggu → **bukan dokumentasi resmi pasien**.

## Hitungan (titik awal: membuka ruang konsultasi → sesi ditutup / pesan terkirim)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | N/V | |
| Field | N/V | |
| Ketukan | N/V | |

**N/V:** alur pasien dari "membuka ruang" sampai kunjungan selesai tidak terdokumentasi langkah demi langkah (satu-satunya tombol yang terdokumentasi adalah "Connect"/masuk waiting room, bukan dari dalam ruang). Chat pasien-dokter tidak terdokumentasi sama sekali di sumber resmi.

## State terlihat

- **Antre:** waiting room + notifikasi SMS saat dokter tersedia ✓ (#2).
- **Kedalaman antrean:** "patients waiting ahead of you" ✓ (#3).
- **Konten/durasi:** durasi & ringkasan kunjungan ✓ (#2, #3).
- **Gagal koneksi:** hanya dari dokumen pihak ketiga lama ("Switch to Phone") → lemah, tidak dipakai untuk skor.
- **Loading/kosong/offline/reconnect/chat status:** tidak terdokumentasi → N/V.

## Red flag

- Tidak ditemukan dark pattern pada sumber resmi.
- Klaim "wait 10 minutes or fewer" adalah klaim pemasaran — jangan dijadikan janji angka.

## Yang TIDAK bisa diverifikasi

- UI dalam kunjungan, chat, read receipt, indikator mengetik, urutan layar.
- Privasi notifikasi layar kunci; aksesibilitas.
- Status 2026 untuk produk konsumen vs pergeseraan B2B/SaaS (pendapatan platform vs kunjungan) — relevan untuk menilai apakah pola konsumen masih dipertahankan.
- Dokumen #8 tidak terunduh penuh / lama → hanya catatan, bukan dasar skor.
- **Rubrik: K1, K3, K4, K7 = N/V.**
