# F06 Pembayaran — Tokopedia

**Tanggal akses semua sumber: 2026-10-01.** Tanpa login, tanpa akun, tanpa pengisian form.
Tidak ada screenshot (`web/ux/refs/` kosong); klaim memakai URL + tanggal akses.

## Sumber

| # | URL | Tipe sumber | Versi/platform |
|---|---|---|---|
| 1 | https://www.tokopedia.com/ | Situs resmi | web; banner bertanggal `01 Oct 26`; footer `© 2009 - 2026, PT Tokopedia` |
| 2 | https://www.tokopedia.com/terms | Syarat & ketentuan resmi | footer 2026 |
| 3 | https://www.tokopedia.com/help/article/bagaimana-cara-belanja-di-tokopedia | Pusat bantuan resmi | langkah belanja/bayar |
| 4 | https://www.tokopedia.com/help/article/metode-bayar-di-tokopedia | Pusat bantuan resmi | daftar metode bayar |
| 5 | https://www.tokopedia.com/help/article/metode-bayar-virtual-account-di-tokopedia | Pusat bantuan resmi | 15 bank VA |
| 6 | https://www.tokopedia.com/help/article/syarat-dan-ketentuan-pembayaran-dengan-gerai-retail | Pusat bantuan resmi | biaya admin + batas 2x24 jam gerai |
| 7 | https://www.tokopedia.com/help/article/apa-itu-qris | Pusat bantuan resmi | QRIS: 75 detik, min Rp10.000/maks Rp5.000.000 |
| 8 | https://www.tokopedia.com/help/article/pesanan-dibatalkan-otomatis | Pusat bantuan resmi | penyebab auto-cancel |
| 9 | https://www.tokopedia.com/help/article/transaksi-digital-belum-berhasil | Pusat bantuan resmi | transaksi gagal |
| 10 | https://www.tokopedia.com/help/article/t-int126-cara-batalkan-transaksi-di-tokopedia | Pusat bantuan resmi | batal sebelum bayar |
| 11 | https://www.tokopedia.com/help/article/saya-belum-menerima-pengembalian-dana-atas-pembatalan-pesanan | Pusat bantuan resmi | alur refund per metode |
| 12 | https://mediakonsumen.com/2021/10/19/surat-pembaca/... | Surat pembaca konsumen independen | 2021/2022 — keluhan pembatalan |

**Terhalang (limitasi):**
- `https://support.tokopedia.com/` → transport error.
- `https://www.tokopedia.com/bantuan` → HTTP 410 Gone.
- `https://seller.tokopedia.com/` → memerlukan JavaScript (shell kosong).
- Layar checkout/pembayaran sebenarnya → **butuh login**, tidak diakses.

**Status aktif 2026 — TERVERIFIKASI:** banner `01 Oct 26` di homepage + footer `© 2009 -
2026` di semua artikel bantuan.

**Independensi:** #1-#11 semuanya milik Tokopedia. #12 independen tetapi historis (2021) dan
membahas aspek berbeda → **hanya 1 sumber independen yang relevan** → **TIDAK memenuhi syarat
>= 2 sumber independen** untuk jadi pemenang flow.

## Langkah terlihat

> Bukan observasi langsung; checkout sesungguhnya butuh login dan tidak diakses.

**A. Belanja sampai tagihan (sumber #3):**
1. Pilih produk — aksi: `+Keranjang` / `Beli Langsung` — hasil: keranjang terisi.
2. Keranjang — aksi: `Cek Keranjang` → klik `Beli` — hasil: halaman pembayaran.
3. Halaman pembayaran — aksi: klik `Pilih Pembayaran`, pilih metode, klik `Bayar` — hasil:
   instruksi pembayaran. Peringatan resmi: *"Pastikan kamu membayar sebelum batas waktu yang
   diberikan habis, agar tidak terjadi pembatalan pesanan."*

**B. Status dan pembatalan (sumber #8, #10):**
- Status `"Menunggu Pembayaran"` → setelah 1x24 jam (T&C #2) pesanan bisa dibatalkan sistem.
- Batal mandiri: `Menunggu Pembayaran` → ikon titik tiga → `Batalkan Transaksi`, berlaku
  selama pembayaran belum terverifikasi.

**C. QRIS (sumber #7):** pilih QRIS → bayar dalam **75 detik**; QR kedaluwarsa → transaksi
diulang; refund ke Saldo Refund maks H+2 hari kerja.

## Hitungan

**Titik awal seragam F06:** faktur/pesanan sudah ada (status `Menunggu Pembayaran`),
pengguna wajib membayar. **Tugas inti:** pembayaran dimulai / instruksi diterima.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar | 1 (halaman pembayaran berisi daftar metode) | #3 |
| Ketukan | 3 (buka pilihan pembayaran → pilih metode → `Bayar`) | #3 |
| Field | 0 untuk VA/QRIS/gerai (transfer manual dinonaktifkan 5 Mar 2025, #4) | #4 |

Jalur gerai retail menambah 1 ketukan memilih gerai (dari alur #6) → ketukan 4.
**TIDAK TERVERIFIKASI:** jumlah field untuk kartu kredit (alur gateway tidak terbuka).

## State terlihat

- **Pending:** status `"Menunggu Pembayaran"` (#3, #10); VA terverifikasi *"1x24 jam"* (#5).
- **Expired/auto-cancel:** 1x24 jam umum (#2); gerai retail **2x24 jam** lalu batal otomatis
  (#6); QRIS 75 detik (#7).
- **Gagal:** daftar penyebab di #8 (lewat batas waktu, stok habis, seller tidak respons
  1x24 jam) dan #9 (transaksi digital belum berhasil).
- **Sukses/refund:** per metode — GoPay/OVO/DANA H+1, VA/retail/QRIS → Saldo Refund, kartu
  kredit 14 hari kerja (#11).
- **Offline/kosong/loading:** **TIDAK TERVERIFIKASI**.

## Red flag

- **Tidak ada dark pattern terkonfirmasi** (tanpa bukti hitung mundur palsu, tombol batal
  tersembunyi, consent terselip, upsell menutupi aksi utama).
- **Ditandai:** jendela QRIS **75 detik** (#7) sangat sempit → tekanan waktu nyata (fakta,
  bukan timer palsu).
- **Ditandai:** biaya admin gerai **Rp2.000–Rp3.000** diatur di artikel S&K terpisah (#6),
  bukan di halaman harga → risiko "biaya kejutan" bila tidak dibaca.
- Keluhan pembatalan instan 3 jam hanya dari surat pembaca konsumen historis (#12, 2021) →
  **bukan fakta 2026**, tidak dipakai sebagai bukti.

## Yang TIDAK bisa diverifikasi

- **TIDAK TERVERIFIKASI:** tampilan layar checkout/pembayaran sesungguhnya (butuh login).
- **TIDAK TERVERIFIKASI:** nilai hitung mundur yang tampil di UI (selain ambang 75 detik
  QRIS yang terdokumentasi).
- **TIDAK TERVERIFIKASI:** apakah asuransi/proteksi pengiriman tercentang otomatis.
- **TIDAK TERVERIFIKASI:** kontras, target sentuh, screen reader → kriteria 4 rubrik = N/V.
- **TIDAK TERVERIFIKASI:** masking/consent data saat bayar kartu → kriteria 6 = nilai dasar 3
  hanya dari kewajiban 3D Secure (#4).
- **TIDAK TERVERIFIKASI:** `support.tokopedia.com` dan `/bantuan` (410) — sumber tutup/
  terhalang.
