# F06 Pembayaran — Midtrans (Snap / QRIS / e-wallet checkout)

Sumber berupa **dokumentasi developer publik**, bukan aplikasi konsumen. Status diverifikasi
dari halaman docs yang terbuka.

**Tanggal akses semua sumber: 2026-10-01.** Tanpa login, tanpa akun, tanpa pengisian form.
Tidak ada screenshot (`web/ux/refs/` kosong); klaim memakai URL + tanggal akses.

## Sumber

| # | URL | Tipe sumber | Versi/platform |
|---|---|---|---|
| 1 | https://docs.midtrans.com | Homepage docs vendor | "Announcement - Sept 2026" |
| 2 | https://docs.midtrans.com/docs/snap.md | Docs vendor | Snap: pop-up / redirect |
| 3 | https://docs.midtrans.com/docs/snap-advanced-feature.md | Docs vendor | expiry, redirect URL, callbacks, collect_* |
| 4 | https://docs.midtrans.com/docs/transaction-status-cycle.md | Docs vendor | siklus status transaksi |
| 5 | https://docs.midtrans.com/docs/https-notification-webhooks.md | Docs vendor | webhook/IPN + signature |
| 6 | https://docs.midtrans.com/reference/api-headers | API reference | `Idempotency-Key`, `X-Payment-Locale` |
| 7 | https://docs.midtrans.com/docs/coreapi-e-money-integration.md | Docs vendor | UX e-wallet (QR vs deeplink per device) |
| 8 | https://docs.midtrans.com/docs/qris-payment-method-in-midtrans.md | Docs vendor | QRIS |
| 9 | https://docs.midtrans.com/docs/gopay | Docs vendor | expiry GoPay 15 menit; redirect ke app GoPay 1 Apr 2026 |
| 10 | https://docs.midtrans.com/docs/payment-link-overview.md | Docs vendor | hosted Payment Link + expiry + max usage |
| 11 | https://docs.midtrans.com/docs/default-expiry-time-for-each-payment-method.md | Docs vendor | tabel expiry **berupa gambar** |
| 12 | https://docs.midtrans.com/docs/get-status-api-requests.md | Docs vendor | pembacaan status |

**Status aktif 2026 — TERVERIFIKASI:** homepage menampilkan pengumuman September 2026
(BIN filtering Snap, email payment link, deprecasi Mobile SDK).

**Independensi:** seluruh bukti dari `docs.midtrans.com` (vendor) → **1 penerbit** →
**TIDAK memenuhi syarat >= 2 sumber independen** untuk jadi pemenang flow. Dipakai hanya
sebagai **sumber pola** untuk siklus status/idempotensi, dengan catatan ini.

## Langkah terlihat

**A. Dua mode checkout (sumber #2):** Snap = pop-up embedded **atau** redirect ke halaman
hosted Midtrans; Core API = UI dikontrol merchant sepenuhnya.

**B. Redirect Snap (sumber #3):** URL akhir membawa `?order_id=xxx&status_code=xxx&
transaction_status=xxx`; redirect dengan `transaction_status=pending` mungkin terjadi
(mis. 3DS2) sehingga status wajib dikonfirmasi via webhook.

**C. E-wallet per device (sumber #7):**
- Desktop/tablet: tampil **QR code** → buka app GoPay → **Pay** → scan → bayar.
- Smartphone: **deeplink** ke aplikasi GoPay → bayar di aplikasi.
- Sumber #9: *"starting from 1 April 2026, user will be redirected to GoPay app only to
  complete their payment (gradual rollout)"*; *"expiry is 15 minutes (min 20s, max 7 days)"*.

**D. Payment Link hosted (sumber #10):** pengguna masukkan Name/Phone/Email bila tidak ada
customer → pilih metode → ikuti instruksi; link punya **expiry date** dan **Maximum Usage**.

**E. Kontrol UI yang tersedia (sumber #3):** `enabled_payments` + sorting rekomendasi,
expiry halaman/transaksi, tema/logo/bahasa, `collect_email|name|address|phone` =
`required|optional|none`, JS callback `onSuccess`/`onPending`/`onError`/`onClose`.

## Hitungan

**Titik awal seragam F06:** faktur transaksi sudah dibuat, pengguna wajib membayar. **Tugas
inti:** pembayaran dimulai / instruksi diterima.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar | 1 (halaman Snap/redirect) | #2, #3 |
| Ketukan | 2 (pilih metode → ikuti instruksi/bayar) | #2, #7 |
| Field | 0 untuk VA/QRIS/e-wallet; jumlah field kartu **TIDAK TERVERIFIKASI** | #2 |

## State terlihat

Lifecycle dari #4 dan #12:

- `pending` → `settlement` / `expire` / `cancel` / `deny`.
- `capture` → `settlement` (default H+1) atau `cancel`.
- `settlement` → `refund` / `chargeback` / `partial_refund` / `partial_chargeback` /
  reversal `deny*`.
- **Reversal case:** Permata VA, Mandiri Bill, Indomaret — `settlement` bisa berubah jadi
  `deny` dalam 1–5 menit → perlakukan sebagai "belum bayar".
- **Snap:** sebelum memilih metode, `GET Status` bisa `404 / Payment not found`.
- `fraud_status`: `accept` / `deny`.

Lain-lain:
- **Idempotensi (sumber #6):** header `Idempotency-Key` di semua POST kecuali `/token` dan
  `/account`; lifetime **5 menit**, maks **46 karakter**, balasan **HTTP 202** bila request
  pertama masih diproses; **tidak didukung** untuk Permata VA, CIMB Clicks, KlikBCA,
  Indomaret.
- **Duplikat order (dari indeks docs):** `406 "order_id has been paid and utilized"` —
  Order ID wajib unik.
- **Webhook (sumber #5):** POST JSON dengan `transaction_status`, `fraud_status`,
  `signature_key`; contoh payload per metode (GoPay, QRIS, ShopeePay, VA, Indomaret, Alfamart).
- **Lokalisasi (sumber #6):** `X-Payment-Locale` = `id-ID` (default) / `en-EN`.
- **Kosong/offline/loading sisi pengguna:** **TIDAK TERVERIFIKASI** (docs tidak merinci).

## Red flag

- **Tidak ada dark pattern yang terlihat** di dokumentasi vendor.
- **Ditandai:** nilai default expiry per metode hanya dipublikasikan sebagai **gambar** (#11)
  → tidak bisa dibaca/diuji sebagai bukti angka.
- Klaim "optimized" dll. dari vendor = **opini vendor**, bukan bukti.

## Yang TIDAK bisa diverifikasi

- **TIDAK TERVERIFIKASI:** angka pasti default expiry per metode (halaman #11 = gambar).
- **TIDAK TERVERIFIKASI:** urutan/jumlah field form kartu di halaman Snap.
- **TIDAK TERVERIFIKASI:** catatan aksesibilitas/WCAG halaman hosted → kriteria 4 rubrik =
  N/V.
- **TIDAK TERVERIFIKASI:** tampilan pesan error ke pengguna akhir (hanya kode/URL redirect).
- **TIDAK TERVERIFIKASI:** konsistensi/kepatuhan silang lintas sumber independen (1 penerbit).
