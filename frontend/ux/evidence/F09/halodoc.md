# F09 — Halodoc (Tebus Resep / detail obat)

Flow: pasien membuka satu resep → memahami dosis → tahu aksi berikutnya.
Semua klaim bertanda `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa bukti = ditandai TIDAK TERVERIFIKASI. Tidak ada screenshot yang diambil (tidak ada akses ke layar dalam aplikasi).

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.halodoc.com/faq/questions/bagaimana-cara-menebus-resep-di-halodoc [akses 2026-10-01] | Pusat bantuan resmi (FAQ) | Versi 2026, alur AI |
| S2 | https://www.halodoc.com/faq/questions/apakah-saya-bisa-mendapatkan-resep-digital-dari-konsultasi-online [akses 2026-10-01] | Pusat bantuan resmi | Alur e-resep konsultasi |
| S3 | https://www.halodoc.com/artikel/cara-beli-obat-di-halodoc-dengan-mudah-dan-praktis [akses 2026-10-01] | Artikel resmi (review 17 Mar 2026) | Langkah + batasan |
| S4 | https://www.halodoc.com/artikel/beli-obat-online-dari-apotek-terdekat-di-halodoc-store [akses 2026-10-01] | Artikel resmi (14 Jul 2026) | Fulfillment + tracking |
| S5 | https://www.halodoc.com/syarat-dan-ketentuan [akses 2026-10-01] | Halaman legal resmi (AI, 18 Mar 2026) | State error, biaya, sekali pakai |
| S6 | https://www.halodoc.com/ [akses 2026-10-01] | Situs resmi | Aktif, footer © 2016-2026 |
| S7 | https://play.google.com/store/apps/details?id=com.linkdokter.halodoc.android&hl=id&gl=ID [akses 2026-10-01] | Store listing | Update 30 Sep 2026, 10 jt+ unduhan, 4,8 (504 rb) |
| S8 | https://apps.apple.com/id/app/halodoc-dokter-obat-lab/id1067217981?l=id [akses 2026-10-01] | Store listing | iOS 15.0+, 228 MB |
| S9 | https://www.akurat.co/infotech/798600/... [akses 2026-10-01] | Artikel pers | ISO 27001:2022 & ISO 27701:2019 |
| S10 | https://brandlist.id/kesehatan/telemedisin/halodoc/ [akses 2026-10-01] | Agregator ulasan pihak ketiga | Keluhan pengguna |

- Versi/platform: Android `com.linkdokter.halodoc.android` (update 30 Sep 2026); iOS id1067217981; web halodoc.com.
- **Batasan riset:** aplikasi native di belakang login → tidak ada akses layar dalam aplikasi; tidak dibuat akun, tidak login, tidak mengirim data. `help.halodoc.com` gagal dimuat; `halodoc.com/obat-dan-vitamin/resep` JS-rendered (hanya kata "Halodoc" yang terbaca).

## Langkah terlihat (fakta)

**Alur A — Tebus Resep (unggah foto, 2026) [S1][S3]:**
1. Buka "Toko Kesehatan" → tombol **"Tebus Resep"** (di samping tombol riwayat) atau pilih dari kotak pencarian.
2. Panduan foto (pengguna pertama kali) → ambil foto/galeri; instruksi resmi: pastikan **nama obat dan dosis terbaca jelas**.
3. Sistem membaca resep otomatis (AI) dan mencocokkan ke katalog; validasi tim ±2 menit.
4. Jika disetujui, obat **otomatis masuk keranjang bersama e-resep** → bayar seperti biasa.
5. Pembatasan resmi: resep racikan, obat NAPZA, resep BPJS tidak bisa diproses; jika tidak bisa dibaca, CS menghubungi; jika aplikasi ditutup, proses lanjut di latar belakang + notifikasi. [S3]

**Alur B — e-resep dari konsultasi online [S2]:**
1. Di resep digital, ketuk **"Cek Harga"** → obat dicari & masuk keranjang.
2. **"Lanjut ke Pembayaran"** → pesanan sah setelah bayar.

**Alur C — beli obat biasa [S4]:** cari → "Tambah" → "Lihat Keranjang" (sistem pilih apotek terdekat) → pilih jenis pengiriman → "Lanjut Bayar" → metode bayar. Termasuk **pelacakan pesanan real time**.

**Fakta terkait [S5]:** semua biaya (harga produk, handling fee, service fee, ongkir) tampil di halaman **"Ringkasan Pembayaran"**; harga platform bisa beda dengan harga offline; resep sah hanya boleh dipakai **sekali**; fitur tebus resep memakai AI dan bukan tenaga medis — tetap diverifikasi apotek.

## Hitungan

Titik awal sama: **pasien membuka satu resep** → tugas inti: paham dosis + tahu aksi berikutnya.
Dihitung dari langkah yang didokumentasikan (bukan pengamatan langsung):

| Skenario | Layar | Ketukan | Field |
|---|---|---|---|
| Alur B e-resep (dari detail konsultasi) | 2 (detail resep → keranjang) | 2 ("Cek Harga", "Lanjut ke Pembayaran") | 0 (tidak ada input ulang item) |
| Alur A tebus resep | 4 (pilih tebus resep → foto → validasi → keranjang) | 3 + 1 foto | 0 field teks (foto menggantikan ketik) |

Dosis terbaca di keranjang/aturan pakai sebelum pembayaran [S1][S2].

## State terlihat

- **Kosong/gagal verifikasi:** item ditandai **"Tidak Tersedia"** / **"Tidak Dapat Dibeli"** [S5].
- **Stok habis:** notifikasi **"Stok Habis"**, pengguna **dikembalikan ke keranjang** [S5].
- **Validasi manual:** jika identifikasi otomatis di bawah akurasi minimum → rujuk ke CS [S5].
- **Latar belakang:** proses berlanjut saat aplikasi ditutup + notifikasi selesai [S3].
- Loading / kosong umum / offline: **TIDAK TERVERIFIKASI** (tidak didokumentasikan publik).

## Red flag

- Tidak ditemukan dark pattern terindikasi dari rubrik (tanpa hitung mundur palsu, tanpa tombol batal tersembunyi, biaya tampil di Ringkasan Pembayaran sebelum bayar [S5]).
- Keluhan pengguna terverifikasi di store/agregator (bukan diskualifikasi): pengiriman instan dibatalkan + saldo tak kembali (ulasan Play Sep 2026 [S7]); "tebus obat online terasa lebih mahal" [S10].
- Data safety Play: berbagi lokasi & info pribadi [S7] — dicatat, bukan red flag rubrik.

## Yang TIDAK bisa diverifikasi

- Tata letak layar dalam aplikasi (di balik login): posisi dosis, jumlah, harga, pemilihan apotek (antar/ambil).
- Opsi **ambil di apotek** di alur 2026 — sumber resmi 2026 hanya menyebut pengiriman; klaim blog 2023 (resep digital bisa ditebus di apotek mitra) **tidak dikonfirmasi sumber resmi** → tidak dipakai.
- State loading/kosong/offline di UI.
- Isi halaman `/obat-dan-vitamin/resep` (JS) dan `help.halodoc.com` (gagal dimuat).
