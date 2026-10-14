# F02 — SATUSEHAT Mobile (Kemenkes RI)

Flow: pasien diminta menyetujui pemrosesan data kesehatan → keputusan tercatat dan bisa ditarik.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun, tanpa instal, tanpa login.

## Sumber

| # | Sumber | Jenis | Versi/platform terlihat |
|---|---|---|---|
| S1 | https://play.google.com/store/apps/details?id=com.telkom.tracencare&hl=en [akses 2026-10-01] | Store listing Google Play | "SATUSEHAT Mobile", Ministry of Health Republic of Indonesia; **v8.9.1**; **Updated on Aug 28, 2026**; 3,4★ / 1,12M ulasan; 50 jt+ unduhan; "Rated for 3+" |
| S2 | https://apps.apple.com/us/app/satusehat-mobile/id1504600374 [akses 2026-10-01] | Store listing Apple App Store | v8.9.1 (31 Agu); usia 16+; seller Pusdatin Kemkes; panel App Privacy: "Data Not Collected" (disclaimer: "has not been verified by Apple") |
| S3 | https://satusehat.kemkes.go.id/mobile [akses 2026-10-01] | Situs resmi Kemenkes | halaman aplikasi |
| S4 | https://satusehat.kemkes.go.id/mobile/faq/topic/214790a9-6894-46e9-9f13-0565ba3201f6 [akses 2026-10-01] | Pemberitahuan privasi resmi | "Terakhir diperbarui: 27 August 2024"; konten dirender JS → saat fetch tampil "Loading…" |
| S5 | https://satusehat.kemkes.go.id/sdmk/syarat-penggunaan [akses 2026-10-01] | Ketentuan platform SATUSEHAT | menyebut "beberapa pemrosesan dilaksanakan berdasarkan **persetujuan (consent) Pengguna**" dan "Data persetujuan atau izin pengguna terhadap akses data" |
| S6 | https://www.kemkes.go.id/eng/understanding-satusehat-2 [akses 2026-10-01] | Penjelasan resmi Kemenkes | rekam medis pasien "accessible via mobile phone **with the user's consent**" |
| S7 | https://satusehat.kemkes.go.id/platform/docs/id/fhir/resources/patient [akses 2026-10-01] | Dokumentasi platform (FHIR) | portal v7.23; sumber `Patient` |

- **Kandidat masih aktif di 2026** (aturan verifikasi 5): Play diperbarui 28 Agu 2026 [S1];
  App Store v8.9.1 [S2].
- **Batasan riset**: aplikasi native → tidak diinstal, tidak login; hanya store listing +
  situs/dokumen publik.

## Langkah terlihat (fakta)

1. Deskripsi Play: aplikasi adalah transformasi PeduliLindungi; fitur **"Electronic Medical
   Records … integrated health services"** dan **"platform to share health information"** [S1].
2. Data safety Play: **may share** "Health and fitness, Photos and videos, and App activity";
   **may collect** "Location, Personal info, and Device or other IDs"; "Data is encrypted in
   transit"; "You can request that data be deleted" [S1].
3. Panel App Privacy App Store menyatakan **"Data Not Collected"** — berbeda dari deklarasi
   Play [S2]; Apple menambahkan disclaimer bahwa pernyataan developer "has not been verified".
4. Tautan Privacy Policy dari store mengarah ke halaman pemberitahuan privasi resmi yang
   bertanggal 27 Agu 2024 tetapi kontennya dimuat via JS [S4].
5. Dokumen platform menyebut persetujuan/izin pengguna untuk pemrosesan & akses data [S5]
   dan Kemenkes menyebut akses rekam medis "with the user's consent" [S6] — **tingkat
   platform**, bukan layar aplikasi.
6. **Tidak ada** dokumen FHIR `Consent` di dokumentasi platform yang di-fetch [S7] →
   **TIDAK TERVERIFIKASI**.

## Hitungan

Titik awal: **pasien sudah masuk, diminta menyetujui pemrosesan data kesehatan** → tugas
inti: **satu keputusan tercatat dan bisa ditarik**.

| Skenario | Layar | Ketukan | Field |
|---|---|---|---|
| Menyetujui pemrosesan data di SATUSEHAT Mobile | **TIDAK TERVERIFIKASI** | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI |
| Menarik persetujuan | **TIDAK TERVERIFIKASI** | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI |

Tidak ada satu pun langkah dalam aplikasi yang dapat diamati dari sumber publik; **hitungan
kosong bukan berarti aplikasi ini efisien** — tidak bisa dinilai.

## State terlihat

- **TIDAK TERVERIFIKASI**: loading, kosong, error, sukses, offline — tidak ada dokumentasi
  publik state layar persetujuan.
- Terdokumentasi (bukan state layar): deklarasi data safety per store [S1][S2]; halaman
  privasi bertanggal 27 Agu 2024 [S4].
- Ulasan Play yang tampil berasal dari 2021 dan membahas login/OTP (bukan F02) [S1].

## Red flag

- **Tidak ditemukan dark pattern persetujuan yang bisa diamati** (layar tidak terlihat) —
  tidak memenuhi syarat diskualifikasi rubrik, **dan bukan berarti bebas**.
- **Dicatat (transparansi, bukan diskualifikasi):** deklarasi data safety **saling
  bertentangan** antara Play ("may share … Health and fitness") [S1] dan App Privacy Apple
  ("Data Not Collected") [S2] — satu dari dua salah; menurunkan kepercayaan pada dokumen
  toko aplikasi.

## Yang TIDAK bisa diverifikasi

1. Seluruh layar dalam aplikasi: pemicu persetujuan, pilihan ya/tidak, penarikan, versi
   dokumen, riwayat keputusan.
2. Apakah ada kotak tercentang awal, konsensi terselip, atau consent wall — **tidak bisa
   diperiksa tanpa aplikasi** (aturan: jangan membuat akun/memasang aplikasi).
3. Isi penuh pemberitahuan privasi [S4] (konten JS); versi/halaman kebijakan terbaru 2026.
4. Hubungan paket Play `com.telkom.tracencare` dengan merek "SATUSEHAT Mobile" diverifikasi
   dari judul+penerbit di listing [S1] (aplikasi adalah transformasi PeduliLindungi);
   tautan Privacy Policy di listing tidak diikuti sampai halaman tujuannya.
5. Perilaku offline/loading/error di aplikasi.
