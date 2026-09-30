# F06 Pembayaran — Halodoc

Sumber utama: dokumentasi publik + listing toko + artikel media/akademik. **Tidak ada
screenshot yang diambil** (`web/ux/refs/` kosong); setiap klaim memakai URL + tanggal akses.
**Tanggal akses semua sumber: 2026-10-01.** Tanpa login, tanpa akun, tanpa pengisian form.

## Sumber

| # | URL | Tipe sumber | Versi/platform |
|---|---|---|---|
| 1 | https://www.halodoc.com | Situs resmi (publik) | Web, footer `© 2016-2026, PT Media Dokter Investama` |
| 2 | https://www.halodoc.com/syarat-dan-ketentuan | Dokumen legal resmi | "Terakhir diperbarui 18 Maret 2026" |
| 3 | https://www.halodoc.com/app/pharmacy/tnc | T&C resmi (apotek) | halaman resmi |
| 4 | https://www.halodoc.com/faq/category/pembayaran-dan-penarikan-saldo | Pusat bantuan resmi | FAQ 2026 |
| 5 | https://www.halodoc.com/faq/questions/metode-pembayaran-apa-saja-yang-bisa-digunakan-untuk-chat-dengan-dokter | FAQ resmi | metode bayar konsultasi |
| 6 | https://www.halodoc.com/faq/questions/apakah-ada-pilihan-metode-pembayaran-di-tempat-cash-on-delivery | FAQ resmi | COD |
| 7 | https://www.halodoc.com/faq/questions/mengapa-saya-tidak-bisa-menyelesaikan-pembayaran-untuk-transaksi-halodoc | FAQ resmi | kegagalan bayar |
| 8 | https://www.halodoc.com/faq/questions/berapa-lama-estimasi-pengembalian-dana-refund-halodoc | FAQ resmi | refund |
| 9 | https://play.google.com/store/apps/details?id=com.linkdokter.halodoc.android | Listing Google Play (publik) | Android; "Updated on Sep 30, 2026"; 10M+ unduhan; 4,8★/504K ulasan |
| 10 | https://apps.apple.com/id/app/halodoc-dokter-obat-lab/id1067217981 | Listing App Store (publik) | iOS v32.800; 346 rb ulasan |
| 11 | https://money.kompas.com/read/2023/09/15/231150826/cara-pembayaran-halodoc-lewat-dana-dan-gopay | Artikel media independen | 2023 — langkah bayar via DANA/GoPay |
| 12 | https://blogs.halodoc.io/learn-unlearn-and-relearn-by-paying-off-tech-debt/ | Blog teknis vendor | 2020 — alur checkout & deep-link GoPay |
| 13 | https://dl.acm.org/doi/10.1145/3387263.3387267 | Riset akademik (ACM) | 2020, studi heuristik Nielsen pada Halodoc |
| 14 | https://jsi.cs.ui.ac.id/index.php/jsi/article/download/1063/419 | Riset akademik (JSI) | 2021, studi usability |
| 15 | https://medium.com/@nugi4l/ui-ux-study-case-redesign-halodoc-mobile-apps-0a944708268f | Artikel teardown independen | 2025 |
| 16 | https://ejurnal.lkpkaryaprima.id/index.php/juktisi/article/view/750 | Riset akademik | 2026-01-09 (pembanding GoPay/DANA, dipakai lintas-kandidat) |

Sumber independen (bukan milik Halodoc): #11, #13, #14, #15, ulasan #9 → **>= 2 sumber
independen terpenuhi**.

**Status aktif 2026 — TERVERIFIKASI:** situs menampilkan footer `© 2016-2026` dan banner
bertanggal September 2026; Play Store "Updated on Sep 30, 2026"; App Store v32.800.

## Langkah terlihat

> Semua dari dokumentasi/teardown publik. **Bukan observasi langsung** — alur checkout tidak
> pernah dijalankan (dilarang membuat akun/mengirim form).

**A. Konsultasi chat (sumber #11, 2023):**
1. Layar tagihan — aksi: periksa "detail tagihan sudah benar" — hasil: lanjut.
2. Layar pilih metode pembayaran — aksi: pilih metode, tap "Bayar" — hasil: keluar ke
   aplikasi dompet (DANA/GoPay).
3. Layar aplikasi dompet — aksi: masukkan PIN — hasil: "Pembayaran Halodoc berhasil".

Deep-link keluar-masuk dikonfirmasi vendor (#12): *"...user selects the Go-pay payment option
and they will be navigated out of the Halodoc app and once the payment is done, the user is
brought back to the payment screen."*

**B. Toko kesehatan/farmasi (sumber #2):** halaman **"Ringkasan Pembayaran"** menampilkan
Harga Produk, Biaya Penanganan, Biaya Layanan, Biaya Pengiriman sebelum pembayaran.

**C. Batas waktu (sumber #3):** *"Anda wajib melakukan konfirmasi pembayaran dalam batas waktu
yang ditentukan. Jika ... melebihi batas waktu ... berhak membatalkan semua pesanan."*

**D. Ketersediaan metode (sumber #7):** bila metode tidak tersedia, Halodoc *"menampilkan
peringatan pada halaman beranda"*.

## Hitungan

**Titik awal (seragam untuk semua kandidat F06):** faktur/tagihan sudah ada dan pengguna
wajib membayar. **Tugas inti selesai:** pembayaran dimulai ATAU instruksi pembayaran diterima.

Jalur e-wallet (sumber #11) — dari tagihan sampai pembayaran dimulai:

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar | 2 di aplikasi + 1 di aplikasi dompet pihak ketiga | tagihan → pilih metode → layar dompet (#11) |
| Ketukan | 3 (pilih metode, Bayar, konfirmasi/PIN di dompet) | langkah #11 |
| Field | 1 (PIN di aplikasi dompet pihak ketiga) | langkah #11 |

Jalur Virtual Account: layar 2, ketukan 2, field 0 — instruksi VA menyusul setelah "Bayar".
**TIDAK TERVERIFIKASI:** urutan/jumlah langkah persis jalur VA Halodoc (tidak ada sumber yang
merinci; langkah di atas dari tutorial DANA/GoPay 2023).

## State terlihat

- **Pending / batas waktu:** T&C menyebut deadline + pembatalan otomatis, **tanpa angka
  durasi** (sumber #2/#3).
- **Metode tidak tersedia:** peringatan di halaman beranda (sumber #7) — pencegahan awal.
- **Sukses/refund:** GoPay/DANA/ShopeePay/OVO maks 1 jam; VA/LinkAja/AstraPay masuk Saldo
  Halodoc; kartu maks 14 hari (sumber #8).
- **Gagal (laporan pengguna 2026):** *"it freezes when I try to pay with card ... Tried at
  least 10 times"* — ulasan Play Store 9 Sep 2026 (sumber #9).
- **Ragu status sukses:** *"...made them doubt whether the transaction was successful"*
  (sumber #15).
- **Kosong/offline/loading:** **TIDAK TERVERIFIKASI** (tidak ada dokumentasi state tersebut).

## Red flag

- **Tidak ada dark pattern yang terkonfirmasi** pada sumber publik: tidak ada bukti hitung
  mundur palsu, tombol batal tersembunyi, atau consent terselip.
- **Ditandai (bukan dark pattern):** studi akademik menemukan masalah pada *"visibility of
  system status"*, *"recognize, diagnose and recover from errors"*, *"user control and
  freedom"* (sumber #13) dan temuan **KUT6**: *"The writing on the button goes to pay which
  creates a misperception"* (sumber #14) → CTA "bayar" berpotensi menyesatkan.
- **Biaya berlapis** (Penanganan, Layanan, Pengiriman) disebut di T&C, tampil di Ringkasan
  Pembayaran — bukan kejutan terkonfirmasi, tapi perlu dipantau (sumber #2).
- Keluhan sulit membatalkan berasal dari teardown Medium 2025 (sumber #15) → opini, bukan
  fakta terukur.

## Yang TIDAK bisa diverifikasi

- **TIDAK TERVERIFIKASI:** durasi/batas waktu pembayaran VA Halodoc (angka menit/jam).
- **TIDAK TERVERIFIKASI:** QRIS sebagai metode Halodoc (tidak ada halaman resmi yang
  menyebut "QRIS").
- **TIDAK TERVERIFIKASI:** polling/refresh otomatis pada layar menunggu pembayaran.
- **TIDAK TERVERIFIKASI:** tampilan layar pending (nomor VA, hitung mundur, teks persis) —
  tidak ada tangkapan layar publik.
- **TIDAK TERVERIFIKASI:** aksesibilitas (kontras, target sentuh, screen reader) — tidak ada
  sumber yang membahasnya → kriteria 4 rubrik = N/V.
- **TIDAK TERVERIFIKASI:** privasi/consent pembayaran (masking, sesi, notifikasi) → kriteria
  6 rubrik = N/V.
- **TIDAK TERVERIFIKASI:** adanya tulisan Baymard/NN/g spesifik tentang Halodoc.
