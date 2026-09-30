# F09 — Kimia Farma (Tebus Resep / Kimia Farma Mobile)

Flow: pasien membuka satu resep → memahami dosis → tahu aksi berikutnya.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tidak ada screenshot; tidak ada login/akun.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.kimiafarma.co.id/ [akses 2026-10-01] | Situs resmi | Aktif, footer 2026, >1.300 apotek |
| S2 | https://www.kimiafarmaapotek.co.id/kimia-farma-mobile/ [akses 2026-10-01] | Halaman resmi | "Unggah resep dokter", Chat Apoteker |
| S3 | https://www.kimiafarmaapotek.co.id/syarat-dan-ketentuan-pada-menu-sukha-aplikasi-livin/ [akses 2026-10-01] | Halaman legal resmi | Alur tebus resep + screening apoteker |
| S4 | https://www.bankmandiri.co.id/tbrs-fitur-tebus-resep-kimia-farma [akses 2026-10-01] | FAQ resmi bank (via indeks pencarian; fetch langsung = halaman generik) | 23 langkah Livin' Sukha |
| S5 | https://play.google.com/store/apps/details?id=com.kimiafarma.online&hl=id&gl=ID [akses 2026-10-01] | Store listing | Update 9 Mar 2026, 1 jt+, 3,6 (16,5 rb) |
| S6 | https://apps.apple.com/id/app/kimia-farma-mobile-beli-obat/id1521628838 [akses 2026-10-01] | Store listing | v3.6.1, 22/05/2024 (riwayat berhenti 2024) |
| S7 | https://psef.kemkes.go.id/ [akses 2026-10-01] | Registry pemerintah (Kemenkes) | "KIMIA FARMA APOTEK" terdaftar PSEF |
| S8 | https://brandlist.id/kesehatan/apotek/kimia-farma-mobile/ [akses 2026-10-01] | Agregator ulasan pihak ketiga | Analisis 327 ulasan Play |
| S9 | https://mediakonsumen.com/2022/03/15/surat-pembaca/komplain-proses-refund-kimia-farma-mobile [akses 2026-10-01] | Pengaduan konsumen | Refund 2022 |
| S10 | https://www.kimiafarmaapotek.co.id/id/ini-dia-hal-yang-harus-diperhatikan-saat-membeli-obat-online/ [akses 2026-10-01] | Artikel resmi | Foto resep → verifikasi apotek |

- Platform: Android `com.kimiafarma.online` v3.6.3 (Play, 9 Mar 2026); iOS v3.6.1 (2024).
- **Batasan riset:** alur langkah-demi-langkah dalam aplikasi Kimia Farma Mobile sendiri tidak dipublikasikan; satu-satunya alur resmi bertahap adalah via Livin' Sukha (S4). Tidak ada akun/login.

## Langkah terlihat (fakta)

**Alur Kimia Farma Mobile / KFA [S3][S10][S2]:**
1. Unggah foto resep dokter ("Unggah resep dokter dan kami akan langsung memprosesnya…") [S2].
2. **Resep fisik asli wajib** diserahkan ke kurir; kurir mengambil resep asli **setelah pembayaran** [S3].
3. Apoteker melakukan **screening** dan bisa **menerima atau menolak** resep; jika diterima, pengguna membayar nominal yang tampil [S3].
4. Apotek terdekat memenuhi & kurir antar [S3]. Metode bayar pada T&C: **virtual account Bank Mandiri** [S3].
5. Ada fitur **Chat Apoteker** [S2].

**Alur Livin' Sukha (Bank Mandiri) [S4] — 23 langkah terdokumentasi:**
Sukha → Kimia Farma → banner "Tebus Resep" → disclaimer → izin kamera → foto resep → "Gunakan" → "Gunakan Lokasi Saat Ini" → izin lokasi → konfirmasi titik tujuan → "Pilih Lokasi" → isi **Data Penerima** → "Simpan" → **"Ajukan Resep"**. Pelacakan: "Lihat Riwayat" → "Detail" → status **"Resep Dalam Proses"** → **"Resep Disetujui"** → **"Lanjutkan Pembayaran"** → "Pilih Pengiriman" → "Bayar" → PIN → sukses [S4].

## Hitungan

Titik awal: **pasien membuka satu resep** → tugas inti: paham dosis + tahu aksi berikutnya.

| Skenario | Layar | Ketukan | Field |
|---|---|---|---|
| Livin' Sukha, dari banner Tebus Resep s/d status pertama | ~10 layar terdokumentasi [S4] | 13 ketukan (sampai "Ajukan Resep") | Lokasi (otomatis) + Data Penerima (≥3 field) |
| Livin' Sukha, s/d selesai bayar | ~15 layar terdokumentasi [S4] | 23 ketukan | + PIN |
| Kimia Farma Mobile (T&C) | TIDAK TERVERIFIKASI langkahnya | TIDAK TERVERIFIKASI | unggah foto + alamat |

Catatan: pengamatan dosis terjadi setelah resep disetujui; posisi tampilan dosis di aplikasi **tidak diverifikasi**.

## State terlihat

- Status bernama terdokumentasi: **"Resep Dalam Proses"**, **"Resep Disetujui"** [S4].
- Screening apoteker bisa **menolak** resep (terima/tolak) [S3].
- Data safety Play: mengumpulkan **kesehatan & kebugaran, aktivitas aplikasi**, berbagi **ID perangkat/lainnya**, terenkripsi transit, bisa minta hapus data [S5].
- Loading / kosong / error jaringan / offline: **TIDAK TERVERIFIKASI**.

## Red flag

- Tidak ada indikasi dark pattern per rubrik dari sumber resmi.
- Keluhan terverifikasi pihak ketiga (bukan diskualifikasi, tapi dicatat): CS skor 1,6★ & login 2,5★ dari 327 ulasan Play; keluhan "sudah bayar tidak dikirim, tidak ada jalur batal jelas" [S8]; pengaduan refund 2022 [S9]; ulasan Play (Jan 2023) resep disetujui tapi nilai transaksi di bawah minimum sehingga item lain harus ditambahkan [S5].
- iOS tidak diperbarui sejak Mei 2024 [S6] — risiko pengalaman usang di platform itu.

## Yang TIDAK bisa diverifikasi

- Urutan layar dalam aplikasi Kimia Farma Mobile sendiri (hanya alur Livin' Sukha yang bertahap).
- Cara dosis, jumlah, satuan, dan harga ditampilkan ke pasien pada layar resep.
- Opsi ambil di apotek (T&C menyebut pengiriman kurir).
- State loading/kosong/offline UI.
- Aktivitas iOS 2026 (riwayan versi berhenti 22/05/2024).
- FAQ resmi bertahap milik Kimia Farma sendiri: tidak ditemukan.
