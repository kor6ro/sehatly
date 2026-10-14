# F10 — MySiloam (portal pasien Siloam Hospitals)

Flow: pasien sudah login → menemukan rekam medis lama → membacanya → (unduh/ekspor?).
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; tidak ada screenshot.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://play.google.com/store/apps/details?hl=id&id=com.siloam.android [akses 2026-10-01] | Store listing resmi (Play Store ID) | Update 24 Sep 2026, v11.2.0, 1 jt+ unduhan, 4,9★, 42,7 rb ulasan; developer PT Siloam International Hospitals Tbk |
| S2 | https://apps.apple.com/id/app/mysiloam/id1456325611 [akses 2026-10-01] | Store listing resmi (App Store ID) | v11.3.1 (3 hari sebelum akses), iOS 15.0+, 4,8★; riwayat versi mencatat akses rekam medis keluarga (v8.7.0) & "medical record activation" (v9.6.0) |
| S3 | https://mysiloam.siloamhospitals.com/ [akses 2026-10-01] | Situs resmi | SPA React — hanya "You need to enable JavaScript"; konten **TIDAK TERVERIFIKASI** |
| S4 | https://mysiloam.siloamhospitals.com/privacy-policy [akses 2026-10-01] | Kebijakan privasi resmi | SPA — konten **TIDAK TERVERIFIKASI** |
| S5 | `https://mysiloam.vercel.app/` [akses 2026-10-01] | **Deployment pihak ketiga (Vercel)** | **DITOLAK sebagai sumber resmi**; tidak dipakai untuk klaim apa pun |

- **Batasan riset:** satu-satunya bukti yang bisa diambil hari ini = deskripsi store resmi + ulasan; situs resmi dan kebijakan privasi tidak bisa dirender (JS).

## Langkah terlihat (fakta)

1. **Fitur "MySiloam Rekam Medis"** [S1]: "Akses riwayat pengobatan Anda selama di Rumah Sakit Siloam mulai tahun **2019**, seperti: **Resume Medis, Hasil Laboratorium, dan Radiologi**"; pantau tagihan & status pemulangan rawat inap; **Resep Obat dari dokter dan Tebus Ulang Resep**.
2. **Patient Portal** [S2]: "Access your medication history in Siloam Hospitals from 2019, such as: Drug purchase history • Monitor your current bills and inpatient discharge status • **Laboratory and Radiology Result**."
3. **Rekam medis keluarga** [S2, riwayat versi v8.7.0, 27 Feb 2025]: "view the medical records of family members through more integrated profiles".
4. **Aktivasi rekam medis** [S2, riwayat versi v9.6.0, 2 Des 2025]: "Medical record activation now with more complete and clear information" → ada proses aktivasi sebelum rekam medis bisa diakses.

## Hitungan

Titik awal: **pasien sudah login, ingin menemukan rekam medis lama** → tugas inti: **ditemukan, terbaca, dan (bila memungkinkan) diunduh**.

| Skenario | Layar | Ketukan/step | Field |
|---|---|---|---|
| Buka riwayat pengobatan / Resume Medis | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI (tak ada sumber menyebut angka) | TIDAK TERVERIFIKASI |
| Aktivasi rekam medis (v9.6.0) | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI |
| End-to-end temukan → baca → unduh | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI |
| Unduh/ekspor PDF | **Tidak ditemukan bukti adanya fitur** | — | — |

## State terlihat

- **Error jaringan** [S1, teks ulasan tampil di listing]: saat membuka rekam medis "selalu muncul periksa jaringan seluler".
- **Sesi/OTP** [S1][S2, ulasan]: "Keep Logout" (11 Jul) — "tiap cek antrian selalu logout dan login ulang"; "OTP error".
- **Loading / kosong / offline:** **TIDAK TERVERIFIKASI**.

## Red flag

- **Data kesehatan tertaut identitas + tracking** [S2, App Privacy]: Health & Fitness *linked to you* (App Functionality); **Diagnostics dipakai untuk tracking across apps/websites milik perusahaan lain**; data iklan/marketing memakai Name + User ID.
- **Data safety Play** [S1]: "Tidak ada data yang dibagikan kepada pihak ketiga", tetapi mengumpulkan Info pribadi, **Kesehatan dan kebugaran**, + 5 lainnya; terenkripsi saat transit.
- **Reliabilitas akses rendah** [S1][S2, ulasan berulang]: keluhan logout/OTP/network saat memakai fitur rekam medis → risiko pasien gagal membuka rekam medis lama. (Ulasan = laporan pengguna, bukan temuan auditable; dipakai sebagai indikator, bukan vonis.)
- Tidak ada bukti paywall; tidak ada bukti tombol unduh/ekspor.

## Yang TIDAK bisa diverifikasi

- Jumlah layar/ketukan/field; isi form aktivasi.
- State loading/empty/offline; isi pesan error resmi.
- Keberadaan tombol unduh/ekspor/share/PDF.
- Teks kebijakan privasi resmi (SPA tidak render).
- Apakah "Resume Medis" terbuka sebagai dokumen atau hanya ringkasan data.
- Semua tampilan layar native.
