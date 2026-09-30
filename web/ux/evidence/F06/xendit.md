# F06 Pembayaran — Xendit (Payment Session / hosted checkout)

Sumber berupa **dokumentasi developer publik**, bukan aplikasi konsumen.

**Tanggal akses semua sumber: 2026-10-01.** Tanpa login, tanpa akun, tanpa pengisian form.
Tidak ada screenshot (`web/ux/refs/` kosong); klaim memakai URL + tanggal akses.

## Sumber

| # | URL | Tipe sumber | Versi/platform |
|---|---|---|---|
| 1 | https://docs.xendit.co | Homepage docs vendor | docs baru + `archive.docs.xendit.co` |
| 2 | https://docs.xendit.co/docs/how-payment-sessions-work.md | Docs vendor | mode PAYMENT_LINK / COMPONENTS |
| 3 | https://docs.xendit.co/docs/migrate-to-payment-session.md | Docs vendor | legacy Invoice → Session (updated 2026-07-27) |
| 4 | https://docs.xendit.co/docs/qris.md | Docs vendor | QRIS: expiry 48 jam, min 1/maks 10.000.000 |
| 5 | https://docs.xendit.co/docs/bca-virtual-account.md | Docs vendor | VA + instruksi ATM/m-BCA/KlikBCA |
| 6 | https://docs.xendit.co/docs/gopay.md | Docs vendor | expiry 0,5 jam; sejak 1 Jan 2026 hanya via app GoPay |
| 7 | https://docs.xendit.co/docs/ovo.md | Docs vendor | expiry 0,25 jam; push notification |
| 8 | https://docs.xendit.co/docs/dana.md | Docs vendor | expiry 24 jam |
| 9 | https://docs.xendit.co/docs/shopeepay-e-wallets-id.md | Docs vendor | expiry 0,5 jam |
| 10 | https://docs.xendit.co/docs/alfamart.md / https://docs.xendit.co/docs/indomaret.md | Docs vendor | OTC: expiry 48 jam, settlement T+5 |
| 11 | https://docs.xendit.co/docs/id-credit-cards.md | Docs vendor | kartu: expiry 0,166 jam, 3DS2 |
| 12 | https://docs.xendit.co/docs/transaction-status.md | Docs vendor | status uang masuk & settlement |
| 13 | https://docs.xendit.co/docs/handling-webhooks.md | Docs vendor | webhook duplikat, dedupe, balas 2xx cepat |
| 14 | https://docs.xendit.co/apidocs/webhook-behavior.md | API reference | jadwal retry 6x |
| 15 | https://docs.xendit.co/apidocs/payment-webhook-notification.md | API reference | `payment.capture`/`authorization`/`failure`, `x-callback-token` |
| 16 | https://docs.xendit.co/apidocs/create-payment-request.md | API reference | status payment request, failure codes |
| 17 | https://docs.xendit.co/docs/payments-via-api-overview.md | Docs vendor | action: REDIRECT_CUSTOMER / PRESENT_TO_CUSTOMER |
| 18 | https://github.com/xendit/xendit-node/blob/master/docs/PaymentRequest.md | SDK resmi (organisasi sama) | `idempotencyKey` |

**Status aktif 2026 — TERVERIFIKASI:** docs aktif; halaman kunci ber-timestamp 2026
(`how-payments-api-work` 2026-09-30, `migrate-to-payment-session` 2026-07-27).

**Independensi:** #1-#18 semua milik satu organisasi (Xendit, termasuk repo GitHub resminya)
→ **1 penerbit** → **TIDAK memenuhi syarat >= 2 sumber independen** untuk jadi pemenang flow.
Dipakai hanya sebagai **sources pola** (expiry per metode, kode kegagalan spesifik) dengan
catatan ini.

## Langkah terlihat

**A. Dua mode checkout (sumber #2):** `mode: PAYMENT_LINK` → redirect ke halaman Xendit
hosted; `mode: COMPONENTS` → field aman tertanam di situs merchant (pelanggan tidak
keluar domain). Kutipan: *"Customers are redirected to a Xendit-hosted checkout page."*

**B. Alur QRIS (sumber #4):** pilih QRIS → QR muncul → scan di m-banking/dompet → cek
nominal → konfirmasi.

**C. Alur OTC (sumber #10):** pilih Alfamart/Indomaret → terima payment code/barcode → bayar
tunai di kasir.

**D. Alur VA (sumber #5):** instruksi m-BCA/KlikBCA/ATM ditampilkan setelah pembayaran
dibuat.

**E. Kategori aksi pengguna (sumber #17):** `REDIRECT_CUSTOMER` (e-wallet/3DS),
`PRESENT_TO_CUSTOMER` (QR/VA), atau tanpa aksi (MIT/subscription).

## Hitungan

**Titik awal seragam F06:** faktur transaksi sudah dibuat, pengguna wajib membayar. **Tugas
inti:** pembayaran dimulai / instruksi diterima.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar | 1 (halaman hosted Xendit) | #2 |
| Ketukan | 2 (pilih metode → QR/instruksi tampil) | #4, #5 |
| Field | 0 untuk QRIS/VA/OTC; jumlah field kartu **TIDAK TERVERIFIKASI** | #11 |

## State terlihat

**Expiry per metode (terpublikasi di tabel fitur — sumber #4-#11):**

| Metode | Expiry | Settlement |
|---|---|---|
| QRIS | 48 jam | T+1 |
| GoPay | 0,5 jam | T+1 |
| OVO | 0,25 jam | — |
| DANA | 24 jam | — |
| ShopeePay | 0,5 jam | — |
| Alfamart/Indomaret | 48 jam | T+5 |
| Kartu | 0,166 jam | T+2 |

**Status (sumber #12, #16):**
- Money-in: `SUCCESS+PENDING` → `SETTLED` / `EARLY_SETTLED`; `VOIDED`; `REVERSED`.
- Payment object: `AUTHORIZED`, `CANCELED`, `SUCCEEDED`, `FAILED`, `EXPIRED`, `PENDING`.
- Payment request: `ACCEPTING_PAYMENTS`, `REQUIRES_ACTION`, `AUTHORIZED`, `CANCELED`,
  `EXPIRED`, `SUCCEEDED`, `FAILED`.
- Payment Session: `ACTIVE` → `COMPLETED` / `EXPIRED` / `CANCELED`.
- Legacy Invoice: `PENDING`, `PAID`, `SETTLED`, `EXPIRED` (mapping di #3).

**Failure codes spesifik (sumber #16):** `PAYMENT_REQUEST_EXPIRED`, `USER_DECLINED_PAYMENT`,
`INSUFFICIENT_BALANCE`, `CARD_DECLINED`, `DECLINED_BY_ISSUER`, `AUTHENTICATION_FAILED`.

**Webhook (sumber #13, #14, #15):** dedupe via `payment_id`/`capture_id`; wajib balas 2xx
cepat; retry maks 6x (15m, 45m, 2h, 3h, 6h, 12h); *"Do not expect any fixed sequence"*;
verifikasi `x-callback-token`; `payment.failure` dipancarkan untuk **setiap** percobaan gagal
(#3).

**Idempotensi (sumber #18 + halaman `create-payment` via indeks):** header `Idempotency-Key`;
`reference_id` wajib unik untuk channel CARDS (#16).

**Offline/kosong/loading sisi pengguna:** **TIDAK TERVERIFIKASI**.

## Red flag

- **Tidak ada dark pattern yang terlihat** di dokumentasi vendor.
- **Ditandai (bukan bukti):** klaim *"Optimized for conversion and security by default"*
  (#2) = klaim vendor, bukan pengamatan.
- **Ditandai:** halaman kustomisasi hosted checkout (`docs.xendit.co/docs/customize-checkout-
  page`) → **HTTP 404** pada 2026-10-01; opsi tampilan (warna/bahasa/format jam) hanya
  terbaca dari indeks pencarian → laporan sekunder.

## Yang TIDAK bisa diverifikasi

- **TIDAK TERVERIFIKASI:** catatan aksesibilitas/WCAG hosted checkout → kriteria 4 rubrik =
  N/V.
- **TIDAK TERVERIFIKASI:** seksi idempotency eksplisit di endpoint v3 `/payment_requests`
  (bukti berasal dari endpoint lain + SDK).
- **TIDAK TERVERIFIKASI:** jumlah/urutan field form kartu di halaman hosted.
- **TIDAK TERVERIFIKASI:** isi halaman kustomisasi (404).
- **TIDAK TERVERIFIKASI:** konsistensi lintas sumber independen (1 penerbit).
