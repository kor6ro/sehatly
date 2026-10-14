# F09 — Apple Health Medications

Flow: pengguna membuka satu obat/resep → memahami dosis → tahu aksi berikutnya.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Sumber = dokumentasi publik resmi Apple (tanpa perangkat login).

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://support.apple.com/en-us/105064 [akses 2026-10-01] | Dokumen resmi Apple (publish 14 Sep 2026) | Add/log medications |
| S2 | https://support.apple.com/guide/iphone/track-your-medications-iph811670c81/ios [akses 2026-10-01] | iPhone User Guide resmi (iOS 26/27, © 2026) | Track medications |
| S3 | https://support.apple.com/guide/iphone/learn-more-about-your-medications-iph2fcefa8d6/ios [akses 2026-10-01] | iPhone User Guide resmi | Bagian "About" + Drug Interactions |
| S4 | https://www.apple.com/legal/privacy/data/en/health-app [akses 2026-10-01] | Legal resmi | Enkripsi, proses on-device, sharing |
| S5 | https://www.apple.com/privacy/docs/Health_Privacy_White_Paper_May_2023.pdf [akses 2026-10-01] | White paper resmi | Enkripsi saat terkunci |
| S6 | https://developer.apple.com/design/human-interface-guidelines/accessibility [akses 2026-10-01] | Apple HIG resmi | Dynamic Type, 44 pt, kontras |
| S7 | https://developer.apple.com/design/human-interface-guidelines/typography [akses 2026-10-01] | Apple HIG resmi | Skala Dynamic Type + AX1–AX5 |
| S8 | https://developer.apple.com/design/human-interface-guidelines/buttons [akses 2026-10-01] | Apple HIG resmi | Hit region ≥44×44 pt |
| S9 | https://developer.apple.com/documentation/healthkit/protecting-user-privacy [akses 2026-10-01] | Dokumen developer resmi | HealthKit: dilarang iklan/jual data |

- Platform/versi: iOS/iPadOS/watchOS; fitur sejak iOS 16; dokumentasi per 2026 (iOS 27).
- **Batasan riset:** halaman HIG "HealthKit" perlu JavaScript saat fetch; isi diverifikasi lewat cuplikan indeks pencarian dari URL resmi yang sama. HIG mengatur desain app, bukan skrin Health app sendiri — UI Medications didokumentasikan di Apple Support.

## Langkah terlihat (fakta)

1. Tambah obat: cari nama → Medication Type → **Strength** → jadwal (hari/waktu/siklus) → Durasi → bentuk/warna → detail opsional → interaksi obat [S1].
2. Detail obat: ketuk obat → gulir ke **"About"** → **untuk apa obat, cara kerja, efek samping potensial, cara pelafalan** [S3].
3. **Drug Interactions** screen + **Interaction Factors** (mis. alkohol); ketuk interaksi untuk detail [S3]. Tersedia **hanya di Amerika Serikat**; obat yang tidak terdaftar tidak disertakan [S1].
4. Pengingat: **Dose Reminders** + **Follow Up Reminders** (30 menit setelah jadwal bila belum di-log) [S1]; **Critical Alerts** tampil di Lock Screen & berbunyi walau Focus/senyap [S2].
5. Aksi notifikasi (tanpa buka app): iPhone/iPad → **Skipped / Taken**; Apple Watch → Skipped, Taken, **Remind Me in 10 Minutes** [S1].
6. Ketuk obat → lihat seberapa sering diambil/dilewati; **Export PDF** daftar obat [S1]. Prompt saat **zona waktu berubah** [S1]. Siri "Log my 6AM medications as taken" [S2].
7. Sharing kesehatan bisa menyertakan obat yang diminum [S1][S4]. Disclaimer resmi: bukan pengganti penilaian medis profesional [S1].

## Hitungan

Titik awal: **pengguna membuka satu obat** → tugas inti: paham dosis + tahu aksi berikutnya.

| Skenario | Layar | Ketukan | Field |
|---|---|---|---|
| Dari notifikasi (log dosis) | 0 layar app | 1 (ketuk "Taken" di notifikasi) | 0 |
| Buka detail obat | 2 (Health → Medications → obat; +1 gulir "About") | 2–3 | 0 |

Dosis (Strength + jadwal) dan aksi (Taken/Skipped) berada satu lapis dari titik mulai [S1].

## State terlihat

- **Error/pemulihan terdokumentasi:** "Schedule Unavailable" untuk tipe jadwal tak didukung → **Reset Schedule**; peringatan "the schedule will not appear on your device"; bila Apple Watch tak bisa diperbarui, pengingat hanya di iPhone/iPad [S1].
- Prompt perubahan zona waktu [S1].
- Loading/kosong/offline: **TIDAK TERVERIFIKASI** (tidak didokumentasikan).

## Red flag

- Tidak ditemukan indikasi dark pattern; tidak ada langganan (bawaan OS).
- Catatan privasi terdokumentasi (bukan pelanggaran): **Critical Alerts tampil di Lock Screen** [S2] dan **Medical ID bisa diakses dari layar kunci secara by-design** [S5]. Tidak ada laporan publik kebocoran nama obat di notifikasi → **TIDAK TERVERIFIKASI** (tidak didokumentasikan Apple).
- Batas AS untuk interaksi obat [S1] — relevan untuk dipakai di Indonesia.

## Yang TIDAK bisa diverifikasi

- Tata letak visual persis, teks state kosong, perilaku offline.
- Apakah nama obat muncul di pratinjau notifikasi dan interaksinya dengan pengaturan Show Previews (tidak ada dokumen Apple spesifik).
- Perilaku Dynamic Type **khusus skrin Medications** (HIG hanya memberi persyaratan umum ≥200%/AX5).
