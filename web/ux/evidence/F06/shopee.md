# F06 Pembayaran — Shopee

**Tanggal akses semua sumber: 2026-10-01.** Tanpa login, tanpa akun, tanpa pengisian form.
Tidak ada screenshot (`web/ux/refs/` kosong); klaim memakai URL + tanggal akses.

## Sumber

| # | URL | Tipe sumber | Versi/platform |
|---|---|---|---|
| 1 | https://help.shopee.co.id/portal/4/article/73077 | Pusat bantuan resmi | 16 metode pembayaran |
| 2 | https://help.shopee.co.id/portal/4/article/73138 | Pusat bantuan resmi | batas waktu bayar per metode + pengingat |
| 3 | https://help.shopee.co.id/portal/4/article/103359 | Pusat bantuan resmi | pembayaran belum terverifikasi |
| 4 | https://help.shopee.co.id/portal/4/article/72650 | Pusat bantuan resmi | COD |
| 5 | https://help.shopee.co.id/portal/9/article/73166 | Pusat bantuan resmi | bayar tunai Alfamart/gerai |
| 6 | https://help.shopee.co.id/portal/4/article/73455 | Pusat bantuan resmi | SPayLater (bunga/denda) |
| 7 | https://help.shopee.co.id/portal/4/article/72891 | Pusat bantuan resmi | pembatalan pesanan |
| 8 | https://seller.shopee.co.id/edu/article/15642 | Pusat edukasi penjual | artikel Jan 2026 |
| 9 | https://shopeepay.co.id/ | Situs resmi | footer `© 2026 ShopeePay` |

**Terhalang:** `https://shopee.co.id/` → hasil fetch kosong (shell JavaScript); root pusat
bantuan hanya menampilkan kerangka — artikel individual tetap terbuka (di-fetch sukses, judul
artikel terkonfirmasi pada 2026-10-01). Layar checkout sesungguhnya butuh login → tidak
diakses.

**Status aktif 2026 — TERVERIFIKASI:** artikel pusat bantuan terbaca utuh, pusat edukasi
penjual bertanggal Jan 2026, ShopeePay footer 2026.

**Independensi:** #1-#9 seluruhnya milik Shopee → **0 sumber independen terverifikasi** →
**TIDAK memenuhi syarat >= 2 sumber independen** untuk jadi pemenang flow.

## Langkah terlihat

> Bukan observasi langsung; checkout tidak dijalankan.

**A. Bayar tunai gerai (sumber #5):**
1. Halaman checkout — aksi: buka `Metode Pembayaran` → `Bayar Tunai di Mitra/Agen` — hasil:
   daftar gerai.
2. Pilih gerai — aksi: `Konfirmasi` — hasil: kembali ke ringkasan.
3. Ringkasan — aksi: `Buat Pesanan` — hasil: kode pembayaran + petunjuk.
4. Kasir gerai — aksi: bayar tunai — hasil: pesanan selesai dibayar.

**B. QRIS (sumber #1):** pilih QRIS → kode QR muncul → bayar via aplikasi bank/dompet.

**C. Pengingat tenggat (sumber #2):** halaman **Pesanan Saya → Belum Bayar** menampilkan
*"...pengingat yang menunjukkan kapan pembayaran Anda jatuh tempo sekaligus informasi mengenai
metode pembayaran yang harus dilakukan"*.

## Hitungan

**Titik awal seragam F06:** faktur/pesanan sudah ada (status belum bayar), pengguna wajib
membayar. **Tugas inti:** pembayaran dimulai / instruksi diterima.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar | 2 (daftar Belum Bayar + halaman metode pembayaran) | #2, #5 |
| Ketukan | 5 (buka pesanan → metode → pilih gerai → `Konfirmasi` → `Buat Pesanan`) | #5 |
| Field | 0 untuk gerai/VA/QRIS | #5 |

Jalur VA/QRIS tanpa langkah pilih gerai → ketukan 4. **TIDAK TERVERIFIKASI:** jumlah field
kartu kredit (diproses pihak ketiga).

## State terlihat

- **Pending + tenggat per metode (sumber #2):** ShopeePay & SPayLater **1 jam**; kartu
  kredit/debit online & OneKlik **3 jam**; Virtual Account, Indomaret, Alfamart **1x24 jam**.
- **Expired:** *"Jika pesanan tidak dibayar dalam batas waktu ... pembayaran akan dibatalkan
  secara otomatis oleh Shopee."* (#2)
- **QRIS (sumber #1):** kode QR berlaku **20 menit**, masa berlaku pembayaran **24 jam**;
  QR kedaluwarsa → **kode QR baru dibuat**.
- **Belum terverifikasi (sumber #3):** penyebab = koneksi, sistem, atau lewat batas; hubungi
  CS bila >1x24 jam.
- **Gagal/batal (sumber #7):** ajukan `Batalkan Pesanan` + alasan → **hanya 1 permintaan per
  pesanan**; seller boleh menolak bila pesanan sudah dikemas; refund diproses otomatis sesuai
  metode bayar.
- **Sukses:** bukti pembayaran tersimpan (gerai, #5).
- **Offline/kosong/loading:** **TIDAK TERVERIFIKASI**.

## Red flag

- **Tidak ada dark pattern terkonfirmasi** (tanpa bukti hitung mundur palsu, tombol batal
  tersembunyi, consent terselip, pre-checked add-on).
- **Ditandai:** batas bayar sangat ketat (1 jam ShopeePay/SPayLater, 3 jam kartu — #2) →
  tekanan waktu nyata meski tenggat ditampilkan transparan di halaman Belum Bayar.
- **Ditandai:** biaya layanan gerai + denda keterlambatan SPayLater **5%** (#6) —
  terdokumentasi di pusat bantuan, bukti transparansi, tetapi biaya di luar harga barang.
- **Ditandai:** pembatalan dibatasi 1x dan bisa ditolak seller (#7) → pemulihan terbatas.

## Yang TIDAK bisa diverifikasi

- **TIDAK TERVERIFIKASI:** tampilan layar checkout/pembayaran sesungguhnya (`shopee.co.id`
  mengembalikan shell kosong; layar butuh login).
- **TIDAK TERVERIFIKASI:** nilai hitung mundur persis yang tampil di UI (hanya ambang batas
  per metode yang terdokumentasi).
- **TIDAK TERVERIFIKASI:** apakah asuransi/proteksi tercentang otomatis.
- **TIDAK TERVERIFIKASI:** kontras, target sentuh, screen reader → kriteria 4 rubrik = N/V.
- **TIDAK TERVERIFIKASI:** consent/masking data → kriteria 6 = nilai dasar 3 hanya dari
  kewajiban 3D Secure untuk kartu (#1).
