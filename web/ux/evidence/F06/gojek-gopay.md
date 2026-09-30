# F06 Pembayaran — Gojek / GoPay

**Tanggal akses semua sumber: 2026-10-01.** Tanpa login, tanpa akun, tanpa pengisian form.
Tidak ada screenshot (`web/ux/refs/` kosong); klaim memakai URL + tanggal akses.

## Sumber

| # | URL | Tipe sumber | Versi/platform |
|---|---|---|---|
| 1 | https://www.gojek.com | Situs resmi | web, footer `© 2023 Gojek \| PT GoTo Gojek Tokopedia Tbk` |
| 2 | https://www.gopay.co.id | Situs resmi | web, footer `© 2023-2025 GoPay - PT Dompet Anak Bangsa` |
| 3 | https://gopay.co.id/bantuan | Pusat bantuan resmi | web |
| 4 | https://gopay.co.id/bantuan/bayar-via-kode-qr/bagaimana-cara-bayar-dengan-qr | FAQ resmi | langkah bayar QR |
| 5 | https://gopay.co.id/bantuan/bayar-via-kode-qr/pembayaran-dengan-scan-qr-tidak-dikenali | FAQ resmi | error QR |
| 6 | https://gopay.co.id/bantuan/top-up/bagaimana-cara-top-up-saldo-gopay | FAQ resmi | langkah top-up |
| 7 | https://gopay.co.id/bantuan/top-up/top-up-gopay-saya-belum-masuk | FAQ resmi | state pending |
| 8 | https://gopay.co.id/bantuan/gopay-later/limit-terpotong-untuk-transaksi-yang-gagal | FAQ resmi | state gagal |
| 9 | https://gopay.co.id/cara-top-up/ | Halaman resmi | tabel biaya admin per kanal |
| 10 | https://play.google.com/store/apps/details?id=com.gojek.app | Listing Google Play | Android; "Updated on Sep 30, 2026"; 100M+; 4,7★/6,71 jt ulasan; catatan rilis v5.77 |
| 11 | https://play.google.com/store/apps/details?id=com.gojek.gopay | Listing Google Play | Android; "Updated on Sep 29, 2026"; 100M+; 4,6★/2 jt ulasan; v2.18 |
| 12 | https://docs.midtrans.com/docs/gopay | Dokumentasi developer pihak-ketiga | Midtrans, halaman diperbarui 2026 |
| 13 | https://docs.midtrans.com/docs/coreapi-e-money-integration.md | Dokumentasi developer pihak-ketiga | Midtrans — UX e-wallet |
| 14 | https://ejurnal.lkpkaryaprima.id/index.php/juktisi/article/view/750 | Riset akademik independen | 2026-01-09, evaluasi heuristik GoPay vs DANA |
| 15 | https://medium.com/@katherinenatalia2/ui-ux-case-study-gopay-app-5cb7c1c001f8 | Teardown independen | 2024 |
| 16 | https://planetdoug.life/2026/01/05/why-you-need-the-gopay-e-wallet-app-not-just-gojek-in-indonesia/ | Artikel observasi independen | 2026-01-05 |
| 17 | https://pageflows.com/post/android/adding-to-cart/gojek/ | Rekaman flow pihak ketiga | Page Flows |

Catatan independensi: #12/#13 (Midtrans) berasal dari grup GoTo yang sama dengan Gojek →
dianggap **afiliasi**, bukan independen. Independen: **#14, #15, #16, ulasan #10/#11** →
**>= 2 sumber independen terpenuhi**.

**Status aktif 2026 — TERVERIFIKASI:** Play Store Gojek "Updated on Sep 30, 2026" (v5.77),
GoPay "Updated on Sep 29, 2026" (v2.18); situs aktif.

## Langkah terlihat

> Bukan observasi langsung; tidak ada transaksi/pembelian yang dijalankan.

**A. Bayar QRIS (sumber #4):**
1. Beranda GoPay — aksi: tap tombol **QRIS** — hasil: kamera terbuka.
2. Layar kamera — aksi: scan QR — hasil: nominal terbaca.
3. Layar isian — aksi: masukkan nominal — hasil: ringkasan.
4. Layar ringkasan — aksi: tap **"Bayar"** — hasil: pembayaran selesai.

**B. Checkout merchant via Midtrans (sumber #13):**
- Desktop: merchant menampilkan **QR code** → buka aplikasi GoPay → tap **Pay** → arahkan
  kamera → cek detail → tap **Pay** → selesai (`payment_type: qris`).
- Smartphone: pengguna **otomatis diarahkan ke aplikasi GoPay** → selesai di aplikasi
  (`payment_type: gopay`). Sumber #12: *"starting from 1 April 2026, user will be redirected
  to GoPay app only to complete their payment (gradual rollout)."*

**C. Pembayaran dikunci PIN (sumber #13):** pengguna klik Pay lalu *"inputs Security PIN"*.

## Hitungan

**Titik awal seragam F06:** faktur sudah ada, pengguna wajib membayar. **Tugas inti:**
pembayaran dimulai / instruksi diterima.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar | 1 (halaman konfirmasi GoPay) + sebelumnya 1 halaman merchant dengan QR | #13 |
| Ketukan | 3 (tap Bayar → tap Pay → konfirmasi PIN) | #13, #4 |
| Field | 1 (PIN) | #13 |

Catatan: jalur mobile mengharuskan **pindah aplikasi** sejak 1 Apr 2026 (#12) — konteks
terputus, tidak dihitung sebagai layar tambahan tetapi tercatat sebagai gesekan.

## State terlihat

- **Pending:** top-up *"mohon menunggu hingga 2x24 jam di hari kerja"* (#7); Indomaret <24 jam
  (#6).
- **Gagal:** limit GoPay Later dipulihkan *"maksimal 1x24 jam"* (#8); nilai `result` Snap =
  `success` / `failure` / `abort` (batal pengguna) (#12).
- **Error QR:** *"Pembayaran dengan scan QR tidak dikenali"* + saran: cek penerima, update
  aplikasi, scan ulang, ganti metode (#5).
- **Expiry:** *"GoPay Deeplink and Dynamic QRIS's transaction expiry is 15 minutes (min 20s,
  max 7 days)"* (#12).
- **Sukses:** tercatat di "Riwayat Transaksi" + notifikasi (#7).
- **Offline:** **TIDAK TERVERIFIKASI**.
- **Kosong/loading:** **TIDAK TERVERIFIKASI** (tidak didokumentasikan).

## Red flag

- **Tidak ada fake countdown / pre-checked add-on / tombol batal tersembunyi** yang terlihat
  di halaman publik. Countdown yang teramati hanya promo event di listing Play (*"Ends in
  2 days"*) — promo, bukan tekanan checkout.
- **Biaya admin top-up dipublikasikan terbuka** (BCA Rp1.000, Indomaret Rp1.500, Alfamart
  Rp2.000, dst. — #9), bukan biaya tersembunyi.
- **Ditandai:** biaya admin tambahan VA BRI Rp500 mulai 1 Nov 2026 diumumkan lebih dulu
  namun *"langsung memotong saldo"* (#9) — transparan tapi friction.
- **Opini akademik (bukan dark pattern):** 6 temuan *"Major Usability Problems"* termasuk
  *"lack of system status transparency, high user cognitive load, ... weak support for help
  and error handling"* (#14).

## Yang TIDAK bisa diverifikasi

- **TIDAK TERVERIFIKASI:** state offline eksplisit pada alur bayar.
- **TIDAK TERVERIFIKASI:** jumlah/tampilan layar pending pembayaran merchant di aplikasi
  GoPay (butuh transaksi nyata).
- **TIDAK TERVERIFIKASI:** apakah ada add-on asuransi/pre-check di layar checkout.
- **TIDAK TERVERIFIKASI:** kontras, ukuran target sentuh, dukungan screen reader → kriteria 4
  rubrik = N/V.
- **TIDAK TERVERIFIKASI:** consent/masking data pengguna di sisi GoPay di luar PIN dan
  pernyataan izin BI/OJK (#2) → kriteria 6 diberi nilai **3** (dasar: PIN + pernyataan
  regulasi), bukan 5.
- **TIDAK TERVERIFIKASI:** perilaku iOS App Store (tidak diakses).
