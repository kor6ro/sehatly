# Evidence F01 — GoPay / Gojek (OTP, kirim ulang, kanal, sesi)

Tanggal akses semua sumber: **2026-10-02**. Native app tidak dibuka; bukti = help center resmi + blog kebijakan + Play listing. Jenis: help page resmi + store listing.

## Sumber

| # | URL | Jenis | Catatan akses |
|---|---|---|---|
| S1 | https://www.gojek.com/en-id/help/akun/cara-masuk-ke-akun-gojek | Help resmi — alur login | Aktif |
| S2 | https://www.gojek.com/en-id/help/akun/masuk-dengan-face-id-sidik-jari | Help resmi — biometrik | Aktif |
| S3 | https://www.gojek.com/en-id/help/akun/saya-belum-menerima-kode-otp | Help resmi — OTP tidak diterima | Aktif |
| S4 | https://www.gojek.com/en-id/help/gopay/cara-mengatur-pin-gopay | Help resmi — PIN GoPay | Aktif |
| S5 | https://www.gojek.com/blog/gojek/satu-akun-satu-perangkat (24 Feb 2024) | Blog kebijakan resmi | Aktif |
| S6 | https://play.google.com/store/apps/details?id=com.gojek.app | Play Gojek | Aktif; updated 30 Sep 2026; v5.77; 4,7★; 6,71 jt ulasan |
| S7 | https://play.google.com/store/apps/details?id=com.gojek.gopay | Play GoPay | Aktif; updated 29 Sep 2026; v2.18; 4,7★; 2,01 jt ulasan |
| S8 | Google Play reviews (Sep 2026) | Ulasan pengguna (komunitas) | Ditandai "user-reported" |

## Langkah terlihat (S1–S4)

**Login:** masukkan nomor HP terdaftar → pilih kanal **SMS atau WhatsApp** → "Send OTP" → masukkan **4 digit** → Continue. Alternatif: **tautan login via SMS** (satu ketukan) atau **silent/missed-call login** yang memverifikasi SIM otomatis.
**One Tap:** login tanpa verifikasi bila perangkat ditandai **trusted device**. **Biometrik:** aktifkan dari **Quick Login** setelah login OTP; maksimum **3 percobaan (Android) / 2 (iOS)**, lalu wajib passcode perangkat.
**Kirim ulang:** tunggu **≥30 detik**, lalu tombol **"Resend OTP"**; kanal bisa ditukar SMS↔WhatsApp.
**PIN GoPay:** 6 digit diketik dua kali → **OTP via SMS**; setelah **3 PIN salah** + ganti PIN → transaksi terkunci **60 menit**.
**Sesi/perangkat:** **satu akun = satu perangkat**; login di perangkat kedua melogout perangkat lain (S5; pesan "You're currently logged in on another device").
**Ganti nomor:** Login → "I've changed my number?" → nomor lama → kode ke **email** terdaftar → kode ke **nomor baru** via SMS → Continue.

## Hitungan

| Besaran | Nilai | Dasar |
|---|---|---|
| Login OTP | 1 field nomor + 1 field kode (4 digit), ≈3 ketukan | S1 |
| Silent/missed call | **0 field kode** | S1 |
| Kirim ulang | 1 ketukan setelah ≥30 dtk; tukar kanal 1 ketukan | S3 |
| Pemulihan ganti nomor | ≈4 langkah, 2 kode | S1 |

## State terlihat

- OTP tidak datang → jalur pemulihan eksplisit (tunggu 30 dtk, kirim ulang, tukar kanal) — S3. ✓
- Batas percobaan biometrik + fallback passcode — S2. ✓
- Konsekuensi PIN salah (60 menit) dinyatakan — S4. ✓
- State kode salah/kedaluwarsa & timer: tidak dinyatakan → N/V.

## Red flag

- **Satu perangkat satu akun** — memaksa logout perangkat lain (terverifikasi S5); dapat terasa seperti kehilangan sesi paksa.
- **Lock 60 menit** setelah 3 PIN salah (terverifikasi S4).
- **User-reported (S8):** loop login GoPay "looping nomor terus"; CS menyarankan cek sinyal SMS OTP. Bukan fakta resmi.

## Yang TIDAK bisa diverifikasi

Masa berlaku OTP; UI hitung mundur; layar kode kedaluwarsa/salah; dukungan paste/autocomplete; apakah app GoPay mandiri punya OTP in-app; layar daftar trusted device (konsep trusted device ada, daftar mandiri tidak terdokumentasi); lockout OTP salah.

## Independensi sumber

S1–S7 = **satu penerbit: Gojek/GoPay** (help, blog, store listing). S8 = ulasan pengguna. Tidak memenuhi syarat pemenang; sumber pola terkuat untuk **kirim ulang + pilihan kanal + login cepat**, dan pembanding kebijakan sesi.
