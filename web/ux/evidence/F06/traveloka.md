# F06 Pembayaran — Traveloka

**Tanggal akses semua sumber: 2026-10-01.** Tanpa login, tanpa akun, tanpa pengisian form.
Tidak ada screenshot (`web/ux/refs/` kosong); klaim memakai URL + tanggal akses.

## Sumber

| # | URL | Tipe sumber | Versi/platform |
|---|---|---|---|
| 1 | https://www.traveloka.com/en-id/help | Pusat bantuan resmi | web; footer `Copyright © 2026 Traveloka` |
| 2 | https://www.traveloka.com/id-id/help | Pusat bantuan resmi | web; footer 2026 |
| 3 | https://www.traveloka.com/id-id/help/general-info/general-information-payment/paying-in-idr/paying-in-idr/what-are-the-payment-methods-accepted-for-idr-transactions | Pusat bantuan resmi | metode bayar IDR |
| 4 | https://www.traveloka.com/en-en/help/general-info/general-information-payment/general-payment-info/general-payment-info/how-to-pay-for-my-booking | Pusat bantuan resmi | langkah bayar + time limit |
| 5 | https://www.traveloka.com/id-id/help/bus-shuttle/bus-ticket-payment/payment-problem/payment-problem/late-payment | Pusat bantuan resmi | telat bayar |
| 6 | https://www.traveloka.com/id-id/help/travelokapay-product-paylater/bill-and-collection/bill/bill/my-paylater-payment-due-dates | Pusat bantuan resmi | jatuh tempo TPayLater |
| 7 | https://www.traveloka.com/id-id/help/flight/flight-managing-booking/flight-changes/refund/how-to-cancel-and-refund | Pusat bantuan resmi | alur refund |
| 8 | https://www.traveloka.com/id-id/termsandconditions | Syarat & ketentuan resmi | **"Terakhir diperbarui 10 September 2026"** |

**Terhalang:** layar checkout/pembayaran sesungguhnya butuh login → tidak diakses. Tidak ada
teardown/panduan pihak ketiga yang berhasil diverifikasi untuk Traveloka.

**Status aktif 2026 — TERVERIFIKASI:** help center footer `© 2026`; T&C diperbarui
10 Sep 2026.

**Independensi:** semua sumber di atas milik Traveloka → **0 sumber independen** → **TIDAK
memenuhi syarat >= 2 sumber independen** untuk jadi pemenang flow.

## Langkah terlihat

> Bukan observasi langsung; checkout tidak dijalankan.

**A. Bayar booking (sumber #4):**
1. Selesaikan detail booking — aksi: pilih metode pembayaran — hasil: daftar metode.
2. Halaman metode — aksi: klik tombol **"Pay with"** — hasil: instruksi/kanal pembayaran.
3. Instruksi: *"Please make sure to settle your payment before the time limit expires. To
   check how much time you have left ... go to **Purchase List**."*

**B. Konfirmasi (sumber #4):** e-ticket/voucher dikirim via email; bila belum diterima dalam
**60 menit**, pengguna diminta unggah bukti pembayaran → verifikasi **±15 menit** setelah
unggahan.

**C. Telat bayar (sumber #5):** hubungi CS dengan bukti transfer (nama rekening, kanal,
tanggal/jam, nominal, nomor pesanan) — pemulihan manual via manusia.

**D. Refund (sumber #7):** pengajuan wajib login ke akun terdaftar; refund ke metode awal
atau Rekening Bank/Refund Balance; hotel ±5 hari kerja setelah disetujui lalu 2-14 hari kerja;
tiket pesawat mengikuti kebijakan maskapai + biaya Traveloka Rp30.000/pax/rute (#8).

## Hitungan

**Titik awal seragam F06:** faktur/booking sudah ada, pengguna wajib membayar. **Tugas
inti:** pembayaran dimulai / instruksi diterima.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar | 1 (halaman memilih metode pembayaran) | #4 |
| Ketukan | 2 (pilih metode → `Pay with`) | #4 |
| Field | 0 untuk transfer bank/ATM/minimarket/e-wallet | #3 |

**TIDAK TERVERIFIKASI:** jumlah field kartu kredit (diproses pihak ketiga, tidak terbuka);
**TIDAK TERVERIFIKASI:** jumlah layar instruksi VA setelah `Pay with` (tidak didokumentasikan
— hanya dikirim ke Purchase List/e-ticket).

## State terlihat

- **Pending:** booking belum tentu langsung terkonfirmasi setelah bayar (T&C #8 §2.3);
  konfirmasi via email; fallback unggah bukti ±15 menit (#4).
- **Expired/auto-cancel:** *"Jika Anda gagal melakukan pembayaran ... dalam batas waktu yang
  ditentukan, pemesanan ... akan dibatalkan secara otomatis"* (T&C #8 §35). **Nilai
  time-limit tidak dipublikasikan.**
- **Gagal/late:** lapor CS dengan bukti transfer (#5) — pemuliman manual.
- **Sukses:** e-ticket/voucher di email (#4).
- **Offline/kosong/loading:** **TIDAK TERVERIFIKASI**.

## Red flag

- **TERFLAG (dipakai sebagai alasan membatasi pengambilan pola):** T&C #8 §1.5 secara
  eksplisit menyatakan harga saat penelusuran *"dapat berubah pada saat Anda mencapai
  halaman pembayaran"* → risiko harga berubah menjelang komitmen. Ini klausul legal, **bukan
  observasi UI**; karena tidak bisa dikonfirmasi di layar, Traveloka **tidak dipakai sebagai
  sumber pola tampilan harga/biaya** dalam dokumen pattern.
- **TERFLAG:** T&C §6.8 — opsi refund kupon/poin menghapus hak menerima uang tunai;
  auto-cancel pada keterlambatan; biaya pembatalan/handling bisa dipotong.
- **Tidak ada** bukti hitung mundur palsu, tombol batal tersembunyi, atau pre-checked add-on
  di halaman publik.

## Yang TIDAK bisa diverifikasi

- **TIDAK TERVERIFIKASI:** nilai/nama tampilan hitung mundur "waktu pembayaran" (hanya frasa
  "time limit" + Purchase List).
- **TIDAK TERVERIFIKASI:** apakah harga benar-benar berubah di halaman bayar pada praktik UI
  (hanya klausul T&C).
- **TIDAK TERVERIFIKASI:** tampilan layar checkout sesungguhnya (butuh login).
- **TIDAK TERVERIFIKASI:** kontras, target sentuh, screen reader → kriteria 4 rubrik = N/V.
- **TIDAK TERVERIFIKASI:** consent/masking data → kriteria 6 = nilai dasar 3 hanya dari
  peringatan transfer ke rekening resmi PT Trinusa Travelindo di pusat bantuan (#3).
