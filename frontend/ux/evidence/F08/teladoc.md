# Evidence F08 — Teladoc Health (kunjungan & message center)

Titik awal hitungan (berlaku semua kandidat F08): **user membuka ruang konsultasi** (di sini: masuk ke kunjungan/ruang tunggu dari akun yang sudah masuk). Tugas inti: **satu pesan terkirim dan diakui**, atau sesi ditutup.

## Sumber

| # | URL | Jenis sumber | Tanggal akses | Versi/platform |
|---|---|---|---|---|
| 1 | https://www.teladochealth.com/helpcenter/article/about-visits-and-troubleshooting-faqs | Help center resmi — join waiting room, estimasi waktu tunggu, 4 status notifikasi, aturan no-show, cancel visit | 2026-10-01 | Web/app (AS) |
| 2 | https://www.teladoc.com/faq | FAQ resmi — message center (ringkasan pasca-kunjungan, dermatologi asinkron, tanya-jawab 7 hari) | 2026-10-01 | Web (AS) |
| 3 | https://www.teladochealth.com/start/how-it-works | Halaman resmi — 3 langkah onboarding + tangkapan layar aplikasi | 2026-10-01 | Web |
| 4 | https://www.teladochealth.com/individuals/24-7-care | Halaman resmi — "average wait times under 15 minutes", tangkapan layar 3 langkah | 2026-10-01 | Web |
| 5 | https://play.google.com/store/apps/details?id=com.teladoc.members&hl=en | Listing Google Play (deskripsi + 15 tangkapan layar, "Updated on Sep 16, 2026") | 2026-10-01 | Android |
| 6 | https://ir.teladoc.com dan siaran pers Q2 2026 (29 Jul 2026), Teladoc One (23 Jul 2026) | Hubungan investor/siaran pers — bukti masih beroperasi 2026 | 2026-10-01 | Web |

**Batas akses:** `member.teladoc.com` butuh akun → tidak ada login/pendaftaran; UI kunjungan yang sebenarnya tidak diamati. Halaman help resmi #1–#2 berupa teks; tangkapan layar hanya di #3, #4, #5 (pemasaran/listing). Tanpa akun, tanpa pengiriman data apa pun.

## Langkah terlihat (fakta)

1. Masuk ruang tunggu (#1): "log in to your account… Then click **'Join waiting room.'**" — satu aksi dari beranda akun.
2. Dua mode kunjungan (#1): **ASAP/on-demand** (langsung masuk ruang tunggu) vs **terjadwal** (masuk pada hari/jam; pengingat email/SMS "10 minutes prior").
3. Estimasi waktu tunggu ditampilkan saat permintaan (#1): "You will be given an estimated wait time when you request your video visit."
4. Empat status yang selalu dinotifikasi (#1): permintaan dikonfirmasi → provider meninjau riwayat (5–10 menit sebelum kunjungan) → provider siap melihat → kunjungan selesai + treatment plan.
5. Kehilangan giliran ditangani (#1): "If you are not in the waiting room when the provider arrives, they will wait a few minutes… we will attempt to connect you with the next available provider prior to canceling your visit. You will receive a new set of notifications…"
6. Tunggu tidak mengikat layar (#1): "you do not have to leave your app open… You will receive a text or email notification indicating the visit is about to start."
7. Uji perangkat sebelum kunjungan (#1): halaman tes audio/video di desktop ("runs simple tests on both your audio and video quality"); "No tests are required when joining from a mobile device."
8. Pesan tindak lanjut lewat message center (#2): ringkasan kunjungan dikirim via message center; dermatologi sepenuhnya asinkron (foto + tanya-jawab ≤7 hari via message center); pemberitahuan "new message" via email/teks.
9. Batalkan kunjungan (#1): beranda → "Upcoming Visit" → tombol "Cancel Visit".
10. Video tidak bisa diakses dari FaceTime/Skype — harus dari dalam aplikasi/akun (#1).

## Hitungan (titik awal: membuka ruang konsultasi → sesi ditutup / pesan terkirim)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | N/V | |
| Field | N/V | |
| Ketukan | 1 untuk masuk ruang tunggu ("Join waiting room", #1) | |

**N/V untuk total:** langkah masuk terdokumentasi (1 ketukan), tetapi langkah dari "provider siap" sampai kunjungan selesai/pesan terkirim tidak terdokumentasi → total layar/ketukan sampai tugas inti **tidak terverifikasi**. K1 dinilai **N/V** di `scores/F08.md`.

## State terlihat

- **Antre/menunggu:** estimasi waktu tunggu + "Join waiting room" ✓ (#1).
- **Sukses:** 4 status notifikasi terdefinisi ✓ (#1).
- **Gagal/no-show:** antrean ulang ke provider berikutnya sebelum kunjungan dibatalkan ✓ (#1).
- **Error perangkat:** tes audio/video desktop; tanpa tes di mobile ✓ (#1).
- **Offline:** tidak meninggalkan aplikasi; pengingat teks/email ✓ sebagian (#1).
- **Loading/kosong dalam chat:** tidak terdokumentasi → N/V.

## Red flag

- Tidak ditemukan dark pattern pada sumber resmi yang diakses.
- Klaim pemasaran "average wait times under 15 minutes" (#4) adalah **klaim perusahaan, bukan jaminan** — jangan dijadikan pola angka tetap untuk Sehatly.

## Yang TIDAK bisa diverifikasi

- Tampilan UI message center, tick status baca/terkirim, indikator mengetik → tidak ada dokumentasi publik (chat async hanya untuk dermatologi dan pasca-kunjungan).
- Jumlah layar sebenarnya; tangkapan layar hanya pemasaran.
- Privasi notifikasi layar kunci; aksesibilitas.
- Ketersediaan/pricing untuk pasar Indonesia (produk AS/Kanada, berlisensi per negara bagian) → K5 skor 1.
- **Rubrik: K1 efisiensi, K4 aksesibilitas, K6 privasi, K7 beban kognitif = N/V.**
