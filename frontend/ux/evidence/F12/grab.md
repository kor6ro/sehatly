# Evidence F12 — Grab (jendela pembatalan + biaya + sengketa; lokal Indonesia)

Tanggal akses semua sumber: **2026-10-02**. Sumber publik (kebijakan/help), tanpa akun. Grab dipakai untuk pola **jendela waktu pembatalan berbayar, tombol konfirmasi yang menampilkan biaya, dan jalur sengketa/refund biaya** — bukan untuk UI pemesanan.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://www.grab.com/id/en/terms-policies/advance-booking-terms | Syarat resmi **Advance Booking** (Indonesia/Inggris) | Web; “Last modified: September 4, 2026” | Dibaca penuh 2026-10-02 |
| S2 | https://www.grab.com/sg/cancellationpolicy | Halaman kebijakan pembatalan resmi (SG) | Web; perubahan berlaku 18 Jul 2022 | Dibaca penuh 2026-10-02 |
| S3 | https://www.grab.com/ph/blog/passengercancelfees | Artikel resmi “Understanding Grab passenger cancellation fees & refund policy” (PH) | Web; **24 Agustus 2026** | Dibaca penuh 2026-10-02 |
| S4 | https://www.grab.com/id/en/terms-policies/transport-delivery-logistics | Syarat & Kebijakan Grab Indonesia | Web; **29 Juli 2026** | **Hanya cuplikan indeks:** boleh batal kapan pun sebelum layanan diterima; dapat dikenai biaya; sengketa lewat Help Centre; refund ke kartu/OVO |
| S5 | https://help.grab.com/passenger/id-id/4404522627481-Melihat-tarif-dan-tagihan | Kategori Help Centre ID (“Tarif & biaya”) memuat artikel “Saya dikenakan biaya pembatalan” | Web | **Fetch gagal: butuh JavaScript**; keberadaan artikel dari indeks pencarian 2026-10-02 |

## Langkah terlihat (fakta)

**Jendela & biaya — Advance Booking Indonesia (S1):**
- Booking dijadwalkan 90 hari–75 menit di muka; dana **ditahan (pre-auth “on hold”)** dan baru dipotong setelah perjalanan selesai; **jika dibatalkan, dana dikembalikan sesuai kebijakan** (S1).
- **Biaya pembatalan hanya dikenakan** bila (a) dibatalkan **<60 menit sebelum** waktu jemput, atau (b) dibatalkan **setelah** waktu jemput. **Selain itu gratis** (S1).
- Jika pengemudi **tidak datang/terlambat**, pengguna boleh membatalkan setelah waktu jemput **tanpa biaya**; bila tidak ada pengemudi yang dialokasikan, pengguna mendapat **voucher kompensasi** (S1).
- Tidak hadir dalam **15 menit** setelah waktu jemput → **dikenai biaya** (S1).
- **Transparansi:** biaya/voucher “shall be communicated … through emails, in-app notification, and/or at the **service information card shown at the booking screen**” (S1).

**Jendela & biaya — pola lintas negara (S2, S3):**
- SG: gratis bila batal **dalam 3 menit** setelah dapat pengemudi, atau bila pengemudi **>5 menit melewati ETA**; biaya **dibatalkan** bila pengemudi tidak tiba dalam 3 menit dari ETA pertama (S2).
- PH: **grace 5 menit**; setelah itu biaya; no-show setelah pengemudi menunggu >5 menit; **hanya satu biaya per booking** (S3).
- Di PH, biaya hanya berlaku setelah pengguna menekan tombol **“PAY TO CANCEL”** — biaya tampil di tombol (S3).

**Jalur sengketa/refund biaya (S3):**
1. Buka app → **Account** → **Help Centre**.
2. **Activity** → pilih perjalanan yang dikenai biaya.
3. Ketuk **“I was charged a cancellation fee wrongly”**.
4. Isi form singkat → kirim. Sistem memverifikasi **GPS pengemudi + timestamp**; jika terbukti salah, biaya **dikembalikan ke GrabPay Wallet/kartu** (S3).
- Jalur alternatif S2: “reach out to us via our in-app Help Centre … and we’ll be happy to assist”.
- Biaya **100% ke pengemudi**, bukan ke platform (S2, S3); tercatat di **e-receipt** terpisah (S3).

## Hitungan (titik awal yang sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Ketukan membatalkan | 2 (Batal → “PAY TO CANCEL”/konfirmasi berbiaya) | S3 |
| Langkah sengketa biaya | **4 langkah** in-app (Account → Help Centre → Activity → trip → form) | S3 |
| Field sengketa | short form (tidak dirinci) + screenshot | S3, S4 |
| Jendela gratis | 3 menit (SG), 5 menit (PH), 60 menit sebelum jemput (ID Advance Booking) | S1–S3 |
| Field reschedule | TIDAK ADA — Grab tidak menyediakan reschedule; “Rent: booking (pick-up, duration, ride type, payment method) cannot be amended once booked” (S1) | S1 |

## State terlihat

- **Gratis vs berbiaya:** dinyatakan sebagai ambang menit yang eksplisit per layanan (S1–S3). ✓
- **Biaya muncul di muka:** di tombol “PAY TO CANCEL” (PH) dan info card saat booking (ID) (S1, S3). ✓
- **Sengketa:** ada state “sedang diverifikasi GPS/timestamp” dan hasil refund (S3). ✓
- **Penalti pihak pengemudi** (agar tidak membatalkan sepihak) dinyatakan (S2). ✓
- Loading/kosong/error/offline: tidak didokumentasikan → N/V.

## Red flag

- Tidak ada dark pattern terverifikasi: pembatalan tidak disembunyikan, biaya dinyatakan sebelum konfirmasi, ada jalur sengketa.
- **Yang tidak boleh disalin:** **angka** jendela (3/5/60 menit) dan biaya (Rp/₱) — itu kebijakan bisnis Grab, bukan pola. Sehatly memakai satu kebijakan tunggal untuk janji dokter.
- Kebijakan berbeda per negara + produk menyulitkan pengguna menemukan aturan yang berlaku (S1 vs S2 vs S3); Sehatly: satu halaman kebijakan yang sama di semua layar.

## Yang TIDAK bisa diverifikasi

1. UI native Grab (Advance Booking) secara langsung — S5 butuh JavaScript; tidak ada screenshot diambil. Klaim UI dari dokumentasi.
2. Apakah layar pembatalan menampilkan sisa waktu/jendela secara real-time — tidak didokumentasikan.
3. Bentuk form sengketa (field persisnya) — hanya “brief report form”.
4. Refund dana **perjalanan** (bukan biaya batal) untuk Advance Booking: hanya dinyatakan “will be refunded, subject to applicable cancellation policies” (S1); waktu proses tidak ada.
5. S4 hanya cuplikan indeks.

## Independensi sumber

- S1–S4 domain `grab.com` (satu penerbit); S5 `help.grab.com` (penerbit sama). **Satu penerbit** → tidak boleh menang rubrik. Dipakai sebagai sumber **mekanisme** (jendela eksplisit, biaya di tombol, sengketa 4 langkah, hold vs charge, 100% ke penyedia jasa).
