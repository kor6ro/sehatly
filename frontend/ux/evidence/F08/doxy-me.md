# Evidence F08 — Doxy.me (ruang tunggu & chat dalam panggilan)

**Silang dengan F07** (ruang tunggu/cek perangkat): kekuatan doxy.me ada di ruang tunggu + pra-kontrol; relevansi F08-nya adalah status masuk dan chat dalam panggilan.

Titik awal hitungan (berlaku semua kandidat F08): **user membuka ruang konsultasi** (di sini: membuka tautan ruang tunggu provider). Tugas inti: **satu pesan terkirim dan diakui**, atau sesi ditutup.

## Sumber

| # | URL | Jenis sumber | Tanggal akses | Versi/platform |
|---|---|---|---|---|
| 1 | https://helpcenter.doxy.me/en/articles/8272808-waiting-room | Help center resmi — "Waiting Room", diperbarui **20 Mei 2026**; di-fetch langsung oleh riset ini | 2026-10-01 | Web, semua paket |
| 2 | https://helpcenter.doxy.me/en/articles/8272841-for-patients-technical-guide | Help center resmi — panduan teknis pasien (tanpa akun), diperbarui **12 Agu 2026** | 2026-10-01 | Web |
| 3 | https://doxy.me/ | Situs resmi — "One URL / No downloads / No patient login", tangkapan layar UI panggilan & ruang tunggu | 2026-10-01 | Web |
| 4 | https://doxy.me/product/releases | Catatan rilis resmi (rilis bulanan hingga Sep 2026) — bukti aktif 2026 | 2026-10-01 | Web |
| 5 | https://doxy.me/precall-test | Uji pra-panggilan publik (aplikasi JS; fetch tanpa JS hanya judul) | 2026-10-01 | Web |
| 6 | Ruang tunggu provider publik, contoh: https://calmpsych.doxy.me/welcome | Ruang tunggu nyata milik pihak ketiga; fetch langsung mengembalikan halaman "JavaScript is disabled" → bukti ruangan adalah aplikasi JS | 2026-10-01 | Web |
| 7 | Ruang tunggu provider publik lain (hasil render indeks pencarian): nerveandpain.doxy.me/waitingroom, mlchc.doxy.me/checkin, lbh.doxy.me/lott, dralexisshields.doxy.me/dralexis | Teks milik provider (bukan spesifikasi platform); diverifikasi lewat render indeks pencarian karena JS-gated | 2026-10-01 | Web |

**Batas akses:** tanpa akun, tanpa pendaftaran, tanpa memulai panggilan nyata; tidak ada data kesehatan yang dikirim. Tangkapan layar resmi tersedia di #1 (artikel help) dan #3 (situs).

## Langkah terlihat (fakta)

1. Pasien membuka tautan provider — **tanpa akun doxy.me** (#2: "You don't need a doxy.me account to join your visit - simply click your provider's link"; #3: "No patient login").
2. Halaman ruang tunggu menampilkan baris status tetap: **"Available" / "No one is available yet"**, plus utilitas **"Pre-call Test"** dan **"5 tips for a great call"** (teramati konsisten di ruang-ruang publik #7).
3. Teks ruang tunggu bawaan (#1): "Welcome to Dr. Example's waiting room. **Dr. Example can see that you have checked in and will start your call shortly.** While you are waiting, we invite you to review the information below."
4. Check-in: centang ToS lalu tombol **Check In**; "When you click Check In, a temporary photo will be taken to help [provider] recognize you" (pola bawaan; terlihat di ruang publik #7 — teks khusus provider).
5. Persiapan perangkat (#1): "Confirm your video and microphone are working. You should see your video and a **green sound indicator** when you talk. If they are not working, please **refresh your browser or restart your device, then check in again**."
6. Pesan error koneksi: **"Network Appears Unstable"** dan panduan pre-call test (artikel help.doxy.me lama, 7 Mei 2026, via render indeks pencarian).
7. Chat dalam panggilan: hanya terdokumentasi di teks milik satu provider (#7, dralexisshields): kotak chat muncul dengan mengklik nama; "**The icon will turn RED if I have sent you a message**" → indikator pesan belum dibaca bersifat **kustom provider, bukan spesifikasi platform**.
8. Aktivitas 2026: catatan rilis bulanan hingga Sep 2026 (#4); artikel help diperbarui Mei/Agu 2026 (#1, #2).

## Hitungan (titik awal: membuka ruang tunggu → sesi ditutup / pesan terkirim)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | ≥2 (ruang tunggu → ruang panggilan) | #1, #3 — tangkapan layar situs menampilkan keduanya; nilai persis tak diuji |
| Ketukan | ≥3 (centang ToS → Check In; di panggilan: buka chat → kirim) | #1, #7 |
| Field | 1 (pesan dalam panggilan) + 1 centang | #1, #7 |

**Catatan:** tugas inti "satu pesan terkirim dan diakui" di dalam panggilan **tidak terverifikasi** (hanya teks satu provider, bukan spesifikasi resmi) → hitungan diturunkan sebagian; **K1 dinilai N/V** di `scores/F08.md`. "Session concluded" (akhir panggilan) tidak terdokumentasi di sumber teks resmi.

## State terlihat

- **Loading/kosong:** status "No one is available yet" (keadaan kosong/antre terdokumentasi) ✓ (#7).
- **Error perangkat/koneksi:** cek pra-panggilan, indikator suara hijau, "Network Appears Unstable", refresh/restart lalu check-in lagi ✓ (#1, #7).
- **Sukses:** check-in terkonfirmasi ("can see that you have checked in") ✓ (#1).
- **Offline/reconnect dalam panggilan:** tidak terdokumentasi → N/V.
- **Pra-panggilan (silang F07):** halaman uji publik + tips ✓ (#5).

## Red flag

- Tidak ditemukan dark pattern pada sumber resmi.
- Catatan privasi (bukan diskualifikasi): foto sementara saat check-in **diungkapkan sebelum diambil** (#1/#7) — pola transparan, namun Sehatly tidak memerlukan foto check-in.

## Yang TIDAK bisa diverifikasi

- Kontrol dalam panggilan, jumlah layar persis, status "dokter tersambung", dan akhir panggilan — butuh sesi nyata (tidak dibuat).
- Semua teks ruang tunggu provider bersifat render indeks pencarian (JS-gated) → ilustratif, bukan spesifikasi stabil.
- Indikator "ikon jadi merah" hanya milik satu provider.
- Aksesibilitas; privasi notifikasi.
- **Rubrik: K1 efisiensi dan K4 aksesibilitas = N/V.**
