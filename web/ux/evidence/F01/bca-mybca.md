# Evidence F01 — BCA mobile / myBCA (bank lokal: OTP, biometrik, perangkat)

Tanggal akses semua sumber: **2026-10-02**. Native app tidak dibuka; bukti = T&C resmi, halaman bantuan OTP, Play listing. Jenis: dokumen resmi + help page + store listing.

## Sumber

| # | URL | Jenis | Catatan akses |
|---|---|---|---|
| S1 | https://www.bca.co.id/en/Syarat-dan-Ketentuan/mybca (+ PDF https://pustaka.bca.co.id/files/bca-co-id/snk-mybca/20250717-snk-mybca-en.pdf) | Syarat & ketentuan resmi | Aktif |
| S2 | https://www.bca.co.id/en/Individu/layanan/e-banking/mybca/otp | Halaman bantuan OTP resmi | Aktif; 12 langkah bernomor |
| S3 | https://play.google.com/store/apps/details?id=com.bca.mybca.omni.android | Play myBCA | Aktif; updated 22 Sep 2026; 4,0★; 106 rb ulasan; 10 jt+ |
| S4 | https://play.google.com/store/apps/details?id=com.bca | Play BCA mobile | Aktif; updated 17 Sep 2026; 4,0★; 1,38 jt ulasan; 50 jt+ |
| S5 | Google Play reviews (Sep 2026) | Ulasan pengguna (komunitas) | Ditandai "user-reported" |

## Langkah terlihat (S1/S2)

**Registrasi myBCA:** pasang app → buat **BCA ID (6–21 karakter) + BCA ID Password** → login dengan BCA ID/password **atau biometrik** (bila aktif) → proses verifikasi → pilih rekening/produk yang ditautkan → **buat PIN 6 digit**.
**Registrasi via web:** bila sudah punya KlikBCA Individu → **User ID + PIN + respons Appli 1 dari KeyBCA**; bila belum → **OTP dikirim ke nomor HP e-banking terdaftar**.
**Login biometrik:** wajib ada ≥1 biometrik di perangkat + aktivasi di Settings. **Nonaktif otomatis** bila: biometrik dihapus dari perangkat; password BCA ID diubah; login dengan BCA ID+password; biometrik baru didaftarkan; **tidak login biometrik 30 hari berturut-turut**.
**Pengaturan kirim OTP (12 langkah):** login → For You → Info & Transactions → OTP & Approval (lihat kode); Settings → Notifications → **Set OTP Code Delivery** → pilih **tujuan utama dan cadangan** → Save → masukkan PIN → tersimpan.

## Hitungan

| Besaran | Nilai | Dasar |
|---|---|---|
| Field registrasi | 3 kredensial (BCA ID, password, PIN) + verifikasi | S1 |
| Langkah registrasi | ≈5–6 | S1 |
| Login | 2 field atau 1 aksi biometrik | S1 |
| Konfigurasi kanal OTP | ≈8 ketukan | S2 |

## State terlihat

- OTP **dapat diarahkan ke tujuan utama + cadangan** (recovery delivery) — S2. ✓
- Aturan nonaktif biometrik dinyatakan eksplisit (termasuk timeout 30 hari) — S1. ✓
- Kode OTP tampil di menu "OTP & Approval" (riwayat kode) — S2. ✓
- State kode salah/kedaluwarsa, timer: tidak dinyatakan → N/V.

## Red flag

- **Biometrik mati diam-diam setelah 30 hari** dan setiap ganti password → pengguna terlempar kembali ke password+OTP tanpa penjelasan di muka (terverifikasi S1; bukan dark pattern, tetapi beban kepercayaan).
- **User-reported (S5):** "setiap habis update aplikasi, password terblokir otomatis" dan logout paksa. Bukan fakta resmi.

## Yang TIDAK bisa diverifikasi

Jumlah digit OTP; masa berlaku OTP; label/cooldown **kirim ulang**; ambang lockout kode salah; layar kode kedaluwarsa; dukungan `autocomplete="one-time-code"`/paste; layar OTP saat registrasi mobile; device binding selain aturan biometrik; langkah logout.

## Independensi sumber

S1–S4 = **satu penerbit: BCA**. S5 = ulasan pengguna. Tidak memenuhi syarat pemenang; dipakai sebagai sumber pola per langkah (aturan biometrik & kanal OTP cadangan).
