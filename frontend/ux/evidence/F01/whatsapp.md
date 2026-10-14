# Evidence F01 — WhatsApp (pengiriman & pengisian OTP, pola kanal)

Tanggal akses semua sumber: **2026-10-02**. Sumber resmi Meta. Jenis: dokumentasi produk + best practices. Ini **bukan aplikasi pembanding penuh** — hanya pola pengiriman/pengisian OTP.

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/authentication-templates/authentication-templates | Dokumentasi resmi template autentikasi | Aktif; diperbarui 17 Jun 2026 |
| S2 | .../authentication-templates/copy-code-button-authentication-templates | Dokumentasi resmi copy-code | Diperbarui 24 Jun 2026 |
| S3 | .../authentication-templates/autofill-button-authentication-templates | Dokumentasi resmi one-tap autofill | Aktif |
| S4 | .../authentication-templates/zero-tap-authentication-templates | Dokumentasi resmi zero-tap | Aktif |
| S5 | .../authentication-templates/authentication-best-practices/ | Best practices resmi | Isi via indeks Meta (fetch langsung 400/403) |
| S6 | https://whatsappbusiness.com/blog/one-time-password-otp-guide/ | Panduan OTP resmi bisnis | Terbit 7 Jul 2025 |
| S7 | https://web.dev/articles/sms-otp-form | Pedoman web (Google) — formulir OTP SMS | Diperbarui 9 Des 2020 |

## Fakta yang terlihat

- **Template pesan** (S1): teks tetap tidak dapat dikustomisasi — `"<VERIFICATION_CODE> is your verification code."`; opsional `"For your security, do not share this code."`; opsional **peringatan kedaluwarsa** `"This code expires in <NUM_MINUTES> minutes."`; tombol **one-tap autofill**, **copy code**, atau **tanpa tombol (zero-tap)**.
- **iOS 26+ (efektif 15 Jun 2026):** keyboard menawarkan **autofill OTP native dari push notification** (S2).
- **One-tap autofill = opsi disarankan** (menyelesaikan auth tanpa keluar app); zero-tap mengirim kode via broadcast Android `com.whatsapp.otp.OTP_RETRIEVED`; copy-code menyalin ke clipboard (S1, S3, S4).
- **Hanya perangkat WhatsApp utama** yang menerima pesan autentikasi (keamanan perangkat tertaut) (S1).
- **Saran UX** (S5): pastikan nomor WhatsApp sebelum mengirim; nyatakan kanal yang dipilih; beri sinyal saat kode terambil otomatis; jangan jadikan WhatsApp kanal default.
- **Keamanan** (S5): nomor didaur ulang; **jangan** perlakukan nomor+akun WhatsApp sebagai identitas untuk pemulihan akun; verifikasi kepemilikan dengan OTP awal dan/atau tantangan tambahan; **forwarding pesan auth dimatikan**; pesan E2E-encrypted.
- Tombol "I didn't request a code" masih **beta** (per 24 Jun 2026) (S2).
- **Pedoman formulir OTP (S7):** `type="text"`, `inputmode="numeric"`, **`autocomplete="one-time-code"`**, `pattern="\d{6}"`; format SMS `@BOUND_DOMAIN #OTP_CODE` di baris terakhir; WebOTP API. Studi kasus Goibibo: **retry OTP pada pendaftaran turun 25%** dengan WebOTP. Catatan keamanan: "SMS OTP bukan metode paling aman… OTP tidak phishing-resistant".

## Red flag

Tidak ada dark pattern pada dokumentasi. Catatan penting: **phone recycling** → nomor tidak boleh diperlakukan sebagai identitas permanen (relevan untuk Sehatly yang memakai `no_telepon` sebagai identifier utama).

## Yang TIDAK bisa diverifikasi

Penegakan kedaluwarsa OTP di sisi server; latensi kirim; hitung mundur yang terlihat pengguna; ketersediaan per negara; TTL default yang tampak ke pengguna (contoh `message_send_ttl_seconds` 60 bersifat server-side).

## Independensi sumber

S1–S6 = **Meta**; S7 = **Google**. Untuk klaim pengisian OTP (`autocomplete="one-time-code"`, WebOTP, studi kasus Goibibo), ada **2 penerbit independen (Meta + Google)**. Untuk template/kanal WhatsApp, hanya 1 penerbit.
