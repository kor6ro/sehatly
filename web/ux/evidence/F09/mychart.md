# F09 — Epic MyChart (Medications)

Flow: pasien membuka satu resep/obat → memahami dosis → tahu aksi berikutnya.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; tidak ada screenshot.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.mychart.org/Features [akses 2026-10-01] | Situs vendor resmi (© 1999–2026) | Daftar fitur Medications |
| S2 | https://www.mychart.org/l/en-us/features/view-medications-test-results-bills/ [akses 2026-10-01] | Halaman fitur resmi | Refills, notifikasi |
| S3 | https://mychart.conehealth.com/mychart/en-US/docs/QSG.pdf [akses 2026-10-01] | Panduan pasien org (diperbarui Jan 2026) | Field detail obat |
| S4 | https://mychart.utmedicinesa.com/mychart/en-US/docs/MCPUG.pdf [akses 2026-10-01] | Panduan pasien org | Refill, add, hapus |
| S5 | https://mychart-np.et1041.epichosted.com/mychartpoc/en-US/docs/AcumenMyChartPatientGuide.pdf [akses 2026-10-01] | Panduan pasien di-host Epic | Dosis + preskriptor + Learn more |
| S6 | https://apps.apple.com/us/app/mychart/id382952264?platform=ipad [akses 2026-10-01] | Store listing | v11.9.1 (24 Agu 2026), iOS 18.0+ |
| S7 | https://play.google.com/store/apps/details?hl=en_US&id=epic.mychart.android [akses 2026-10-01] | Store listing | Update 9 Jul 2026 |

- Vendor: Epic Systems Corporation. **Ketergantungan konfigurasi resmi:** "what you can see and do… depends on which features your healthcare organization has enabled and whether they're using the latest version of Epic software" [S6].
- **Batasan riset:** portal pasien di balik login; panduan berasal dari organisasi kesehatan (bukan satu skrin universal Epic).

## Langkah terlihat (fakta)

1. Daftar obat: **"Review your medication list and instructions for taking each medication, and report medications you're no longer taking"** [S1].
2. Detail per obat (panduan org): **dosis yang diresepkan, instruksi minum, dan dokter pemberi resep**, plus tautan **"Learn more"** untuk precautions & efek samping [S5] (dan [S3][S4] dengan redaksi serupa).
3. Aksi dari daftar/detail: **Request Refills** (pilih obat + komentar → apotek → kirim; hasil masuk inbox) [S4]; **Remove** + alasan [S3]; **Add a Medication** → pencarian → grafik berubah **setelah tinjauan dokter** [S4].
4. **Personal Notes** pada Medications hanya terlihat oleh pasien, bukan dokter [S3].
5. **Care Companion**: pengingat obat, konten edukasi, tugas lacak, check-in berkala [S1].
6. Apple Watch: notifikasi + **review medications** [S1]. Proxy/keluarga didukung [S1].

## Hitungan

Titik awal: **pasien membuka satu resep/obat** → tugas inti: paham dosis + tahu aksi berikutnya.
Dihitung dari langkah terdokumentasi (bukan pengamatan langsung):

| Skenario | Layar | Ketukan | Field |
|---|---|---|---|
| Buka obat dari daftar | 2 (daftar → detail) | 2 (buka Medications, ketuk obat) | 0 |
| Lanjut ke aksi (refill) | 3 | +1 "Request Refills" | komentar (opsional) |

Dosis + instruksi + preskriptor tampil di layar kedua [S5].

## State terlihat

- **TIDAK TERVERIFIKASI** untuk loading/kosong/error/offline Medications — tidak ada dokumentasi publik.
- Terdokumentasi (bukan state layar): pesan inbox saat refill diproses [S4]; push "new information is available" [S1]; perubahan grafik menunggu tinjauan dokter [S4].

## Red flag

- Tidak ditemukan indikasi dark pattern; tidak ada langganan upsell (portal gratis lewat faskes pemegang layanan [S6]).
- Keluhan pengguna terverifikasi (bukan diskualifikasi): ulasan Play 7 Okt 2025 — update obat & check-in sulit diselesaikan saat pengguna minum banyak obat [S7].
- Keterbatasan fitur bergantung konfigurasi faskes & versi Epic [S6] — sumber kebingungan, dicatat.

## Yang TIDAK bisa diverifikasi

- Tata letak/render skrin detail (di balik login), label persis tiap organisasi.
- State loading/kosong/error/offline dan isi notifikasi.
- Keseragaman field "Learn more" lintas faskes.
