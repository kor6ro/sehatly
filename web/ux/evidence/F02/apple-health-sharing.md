# F02 — Apple Health: "Share your health data" (berbagi data kesehatan)

Flow: pasien diminta menyetujui pemrosesan data kesehatan → keputusan tercatat dan bisa ditarik.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa perangkat Apple, tanpa akun; hanya
dokumen publik Apple (support, HIG, legal).

## Sumber

| # | Sumber | Jenis | Versi/platform terlihat |
|---|---|---|---|
| S1 | https://support.apple.com/guide/iphone/share-your-health-data-iph5ede58c3d/ios [akses 2026-10-01] | Panduan pengguna iPhone (Apple Support) | iOS 26/27/18 |
| S2 | https://support.apple.com/en-us/108779 [akses 2026-10-01] | Artikel how-to Apple Support | iOS/iPadOS; artikel diperbarui 14 Sep 2026 |
| S3 | https://www.apple.com/legal/privacy/data/en/health-app/ [akses 2026-10-01] | Dokumen privasi/legal Apple untuk Health | diperbarui 14 Sep 2026 |
| S4 | https://developer.apple.com/design/human-interface-guidelines/privacy [akses 2026-10-01] | Apple HIG (isi via endpoint data resmi `…/tutorials/data/design/human-interface-guidelines/privacy.json`) | 2026 |
| S5 | https://developer.apple.com/design/human-interface-guidelines/accessibility [akses 2026-10-01] | Apple HIG (isi via `…/accessibility.json`) | 2026 |
| S6 | https://support.apple.com/en-us/102515 [akses 2026-10-01] | Artikel privasi Apple | diperbarui 20 Mei 2026 |

- **Kandidat masih aktif di 2026**: dokumen legal diperbarui 14 Sep 2026 [S3]; artikel
  support 14 Sep 2026 [S2]; HIG terjangkau 2026 [S4][S5].
- **Batasan riset**: aplikasi native di perangkat Apple — **tidak ada tampilan layar yang
  diamati**; seluruh langkah berasal dari dokumen langkah resmi.

## Langkah terlihat (fakta)

**A. Berbagi data ke orang lain (share with someone)** [S1]:
1. Buka **Health** → ketuk **Sharing**.
2. (Pertama kali) ketuk **Share with Someone**.
3. Cari di Kontak → pilih orang (orang harus ada di Kontak Anda).
4. **See Suggested Topics** atau **Set Up Manually** → pilih topik data.
5. Gulir, pilih opsi per topik → **Next** untuk tiap layar.
6. Ketuk **Share** → **Done**.
- Tinjauan setelah berbagi: ketuk orang → **View Shared Data** untuk melihat/mengubah topik
  yang dibagikan [S1][S3].
- **Penarikan**: layar yang sama → **Stop Sharing** / **Remove Account**; dokumen legal:
  "You can stop sharing … at any time" dan perangkat penerima menghapus riwayat bersama [S3].

**B. Izin per-aplikasi (aplikasi membaca/menulis data Health)** [S2][S3]:
- Aplikasi "must request the ability to read data from or write data to your Health app" dan
  "must explain why they are requesting access"; setiap aplikasi wajib punya kebijakan privasi [S3].
- Pengguna mengatur per izin kategori (baca/tulis) di Profile → Apps [S2].
- Prompt izin menjelaskan aplikasi peminta **dan alasannya** [S6].

**C. Keamanan/privasi** [S3]: data kesehatan/kebugaran (kecuali Medical ID) terkunci
perangkat = terenkripsi dan tidak dapat diakses; sinkron iCloud terenkripsi transit+istirahat;
dengan iOS 12+ & 2FA Apple "will not be able to read" data tersinkron; berbagi dengan penyedia
layanan disimpan di server khusus, Apple tidak memegang kunci, selaras HIPAA.
**Dicatat**: bila iCloud aktif, "your Health app data is **backed up by default**" (bisa
dimatikan) [S3].

**D. HIG (syarat desain)** [S4]: "Request access only to data that you actually need";
"Be transparent about how your app collects and uses people's data"; salinan izin = *purpose
string* yang menjelaskan alasan; minta izin hanya saat benar-benar diperlukan [S4].
Aksesibilitas [S5]: kontrol iOS **default 44×44 pt, minimum 28×28 pt**; teks dapat
diperbesar **≥200%** (Dynamic Type); kontras mengacu WCAG **4,5:1** (normal) / **3:1** (≥18 pt).

## Hitungan

Titik awal: **pasien sudah masuk, diminta menyetujui pemrosesan data kesehatan** → tugas
inti: **satu keputusan tercatat dan bisa ditarik**.

| Skenario | Layar/langkah | Ketukan | Field |
|---|---|---|---|
| Berbagi data ke orang lain (disetujui) | 6 (Health → Sharing → Share with Someone → kontak → topik → Share/Done) [S1] | 6 | 0 (pilihan topik = tombol) |
| Izin 1 kategori untuk 1 aplikasi | 2 (prompt izin + konfirmasi) [S2][S6] | 1–2 | 0 |
| Menarik berbagi (Stop Sharing) | 3 (Sharing → orang → Stop Sharing) [S1][S3] | 3 | 0 |
| Menonaktifkan izin aplikasi | 3 (Profile → Apps → matikan kategori) [S2] | 3+ | 0 |

Dihitung dari langkah terdokumentasi (bukan pengamatan layar).

## State terlihat

- **TIDAK TERVERIFIKASI**: loading, kosong, error, sukses, offline layar Sharing (tidak ada
  dokumentasi publik state-state itu).
- Terdokumentasi (bukan state layar): prompt izin dengan alasan [S6]; "View Shared Data"
  untuk tinjauan perubahan [S1]; tombol matikan izin per kategori [S2]; "stop sharing at any
  time" [S3].

## Red flag

- **Tidak ditemukan dark pattern persetujuan** pada sumber yang dibaca (izin minta alasan,
  penarikan tersedia, tinjauan data sebelum/bagi).
- **Dicatat, bukan diskualifikasi**: cadangan iCloud Health **default aktif** bila iCloud
  menyala [S3] — itu pengaturan cadangan terenkripsi (bukan persetujuan berbagi), sudah
  disebut eksplisit dan bisa dimatikan. Bila pemilik produk menganggapnya
  opt-out-by-default, Apple tetap boleh dipakai hanya untuk langkah B/A (izin & berbagi).

## Yang TIDAK bisa diverifikasi

1. Tampilan layar/naskah prompt persis (tanpa perangkat iOS); urutan tap nyata vs dokumen.
2. Apakah tinjauan tipe data **wajib** pada setiap aksi berbagi.
3. Klaim "HealthKit tidak dipakai iklan / tidak dijual" — **tidak ditemukan** pada dokumen
   legal yang di-fetch [S3] → **TIDAK TERVERIFIKASI**.
4. Store listing aplikasi Health (aplikasi bawaan iOS — tidak ada listing toko); versi
   platform hanya dari halaman support.
5. Padanan Android (Samsung Health dll.) — di luar cakupan yang diverifikasi.
