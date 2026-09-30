# F09 — BPOM (label & leflet obat) + panduan keterbacaan medis

Sumber **fakta regulator**, bukan aplikasi — tidak dinilai dengan rubrik 8 kriteria, dipakai sebagai dasar "apa yang WAJIB terbaca" dan angka aksesibilitas.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`.

## Sumber

| # | Sumber | Jenis |
|---|---|---|
| B1 | https://peraturan.bpk.go.id/Download/353660/Peraturan_BPOM_No._10_2024.pdf [akses 2026-10-01] | Regulasi resmi (PDF, terunduh) |
| B2 | https://standar-otskk.pom.go.id/berita/perbpom-no-10-tahun-2024 [akses 2026-10-01] | Berita direktorat BPOM |
| B3 | https://standarobat.pom.go.id/sisobat/storage/standard/40-keputusan-kepala-badan-pengawas-obat-dan-makanan-nomor-279-tahun-2024-tentang-standar-informasi-obat.pdf [akses 2026-10-01] | Standar resmi BPOM (PDF) |
| B4 | https://peraturan.bpk.go.id/Details/220387/perka-bpom-no-24-tahun-2017 [akses 2026-10-01] | Metadata regulasi resmi |
| B5 | https://registrasiobat.pom.go.id/files/regulations/PERATURAN%20KEPALA%20BPOM%20NOMOR%2024%20TAHUN%202017...pdf [akses 2026-10-01] | PDF resmi registrasi obat |
| B6 | https://www.pom.go.id/katabpom/cek-klik [akses 2026-10-01] | Edukasi konsumen BPOM (Cek KLIK) |
| B7 | https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html ; .../resize-text.html ; .../text-spacing.html ; .../target-size-minimum ; .../target-size-enhanced.html [akses 2026-10-01] | Standar W3C (WCAG 2.2) |
| B8 | https://www.ecfr.gov/current/title-21/chapter-I/subchapter-C/part-208 [akses 2026-10-01] | Regulasi FDA (21 CFR 208) |
| B9 | https://www.fda.gov/media/128446/download [akses 2026-10-01] | Guidance FDA (IFU, 2022) |
| B10 | https://health.ec.europa.eu/system/files/2016-11/2009_01_12_readability_guideline_final_en_0.pdf [akses 2026-10-01] | Pedoman Komisi UE (readability) |
| B11 | https://www.ismp.org/sites/default/files/attachments/2021-10/Label%20%26%20Package%20Handouts.pdf ; https://www.ismp.org/sites/default/files/attachments/2017-11/tallmanletters.pdf [akses 2026-10-01] | ISMP (patient-safety) |
| B12 | https://developer.apple.com/design/human-interface-guidelines/buttons ; .../accessibility [akses 2026-10-01] | Apple HIG |
| B13 | https://m3.material.io/foundations/designing/structure [akses 2026-10-01] | Material 3 (Google) |
| B14 | https://www.nngroup.com/articles/writing-for-lower-literacy-users/ ; https://www.nngroup.com/articles/medical-usability/ [akses 2026-10-01] | NN/g (artikel) |

## Fakta A — Informasi wajib pada penandaan/label obat (BPOM)

**Peraturan BPOM No. 10 Tahun 2024** (Penandaan Obat Bahan Alam, Obat Kuasi, Suplemen Kesehatan; diundangkan 3 Jun 2024, diumumkan 25 Jun 2024; penyesuaian 24 bulan) [B1][B2]:

- **Pasal 6(1)** — item wajib: (a) nama produk & bentuk sediaan; (b) nama & alamat industri/Pelaku Usaha; (c) pemberi/penerima kontrak; (d) pemberi/penerima lisensi; (e) isi bersih/berat bersih/jumlah; (f) komposisi; (g) bahan tambahan; (h) klaim khasiat; (i) **aturan pakai/cara penggunaan**; (j) **kontraindikasi, efek samping, interaksi, peringatan/perhatian**; (k) **nomor Izin Edar**; (l) kode produksi; (m) **kedaluwarsa**; (n) kondisi penyimpanan; (o) 2D barcode; (p) logo/tulisan tertentu; (q) informasi lain.
- **Pasal 6(6)**: item a,b,c,d,e,j,k,l,m,n,o,p,q ditempatkan pada bagian **"paling mudah dilihat dan dibaca"**.
- **Pasal 7(1)** (label terkecil): tetap wajib nama produk, nama & alamat Pelaku Usaha, nomor Izin Edar, kode produksi, kedaluwarsa; **Pasal 7(2)**: label ≤ 10 cm² → alamat boleh ke kemasan sekunder.
- **Pasal 10**: informasi tertulis wajib **"teratur, jelas, mudah dilihat, mudah dibaca, dan proporsional dengan luas permukaan"**.
- **Pasal 5**: informasi harus objektif, lengkap, tidak menyesatkan.

**Keputusan Kepala BPOM No. 279 Tahun 2024 — Standar Informasi Obat** [B3]:
- **Informasi Produk untuk Pasien** wajib berbahasa Indonesia **mudah dipahami umum**, huruf Latin, angka Arab; hindari istilah medis rumit; kalimat pendek jelas; efek samping dikelompokkan **menurut frekuensi**; boleh bentuk tanya-jawab; untuk obat OTC wajib pada kemasan terkecil dan **"jelas terbaca selama penggunaan obat"**.
- Field contoh: nama obat, komposisi zat aktif, kekuatan, indikasi, posologi/cara pemberian (+apa bila terlewat), kontraindikasi, nomor Izin Edar.

**PerKa BPOM No. 24 Tahun 2017** (Kriteria & Tata Laksana Registrasi Obat) [B4][B5]:
- **Pasal 4(1)(c)**: Informasi Produk & Label harus lengkap, objektif, tidak menyesatkan.
- **Pasal 29**: Dokumen Informasi Produk = RPK/Brosur (tenaga kesehatan) + **Informasi Produk untuk Pasien**; OTC wajib pada kemasan terkecil & terbaca selama pemakaian; minimum di **Lampiran X**.
- **Pasal 30**: Dokumen Label (etiket, strip/blister, ampul/vial, catch cover, outer); minimum di **Lampiran XI**.
- **⚠ Inkonsistensi status**: peraturan.go.id mencantumkan 24/2017 "Tidak Berlaku — dicabut PerBPOM 15/2023", padahal 15/2023 berjudul "Perubahan Keempat". **TIDAK TERVERIFIKASI** penyelesaiannya → jangan dikutip sebagai "masih berlaku penuh" tanpa konfirmasi hukum.

**Edukasi konsumen BPOM: Cek KLIK** = Cek Kemasan, Cek Label, Cek Izin Edar, Cek Kedaluwarsa [B6].

**TIDAK TERVERIFIKASI:** isi Lampiran X & XI resmi (hanya sekunder); teks lengkap PerKa 5166/2010 (hanya mirror non-resmi); pedoman keterbacaan khusus WHO (tidak ditemukan; yang ada TRS 902 Annex 9).

## Fakta B — Angka keterbacaan & aksesibilitas (untuk AC Sehatly)

- **WCAG 2.2 SC 1.4.3 (AA)**: kontras teks ≥ **4,5:1**; teks besar (≥18pt / ≥14pt tebal) ≥ 3:1 [B7].
- **WCAG 2.2 SC 1.4.4 (AA)**: teks bisa diperbesar **200%** tanpa kehilangan isi/fungsi [B7].
- **WCAG 2.2 SC 1.4.12 (AA)**: line-height ≥ **1,5×**, jarak paragraf ≥ **2×**, letter-spacing ≥ **0,12×**, word-spacing ≥ **0,16×** ukuran font [B7].
- **WCAG 2.2 SC 2.5.8 (AA)**: target sentuh ≥ **24×24 CSS px**; **SC 2.5.5 (AAA)** ≥ **44×44 CSS px** [B7].
- **Apple HIG**: hit region tombol ≥ **44×44 pt** (visionOS 60×60); iOS/iPadOS default kontrol 44×44 pt, minimum 28×28 pt; Dynamic Type ≥ **200%** (watchOS 140%) [B12].
- **Material 3**: target sentuh ≥ **48×48 dp** (≈9 mm), rekomendasi 7–10 mm [B13].
- **FDA 21 CFR 208**: Medication Guide bahasa non-teknis, ukuran huruf **≥10 point**, legibel, penekanan pakai kotak/tebal/garisbawah [B8]; guidance IFU: sans-serif, ≥10 point, hindari huruf kapital semua, aktif, hindari singkatan, hitam di putih [B9].
- **Pedoman UE (readability)**: label ≥ **7 point** (x-height ≥1,4 mm), line spacing ≥3 mm, hindari teks rata dua sisi, uji kegunaan ke ≥20 pasien (≥90% benar) [B10].
- **ISMP**: label berisiko tinggi = padat, kurang white space, huruf kapital semua, font kecil; pakai **tall man lettering** untuk nama obat serupa [B11].
- **NN/g**: pengguna literasi rendah "membuldoze" teks per kata, hindari scroll panjang, boimat konten utama di awal; studi Pfizer: keberhasilan tugas naik 46%→82% dengan tulisan level 6 SD [B14].

## Implikasi untuk Sehatly (fakta → kebutuhan, bukan opini)

- Field wajib terbaca pada detail resep Sehatly (mengikuti BPOM): **nama obat, bentuk sediaan, kekuatan/komposisi, aturan pakai, peringatan/kontraindikasi, nomor izin edar bila tersedia, kedaluwarsa, jumlah** [B1][B3].
- Angka minimum yang bisa dipakai sebagai AC: kontras ≥4,5:1; target sentuh ≥44 px (AAA, konsisten HIG/Material); teks obat **tidak boleh dipotong** (selaras Pasal 10 "mudah dibaca" [B1] + WCAG 1.4.4) [B7][B12].
