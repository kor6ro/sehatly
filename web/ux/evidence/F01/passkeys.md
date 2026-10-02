# Evidence F01 — Passkeys (jalur cepat login; multi-penerbit)

Tanggal akses semua sumber: **2026-10-02**. Jenis: dokumentasi platform (Google, Apple), pedoman web (web.dev/Google), riset usability (NN/g). Ini **standar/pattern**, bukan aplikasi.

## Sumber

| # | URL | Penerbit | Catatan |
|---|---|---|---|
| S1 | https://developers.google.com/identity/passkeys | Google | Aktif |
| S2 | https://developers.google.com/identity/passkeys/developer-guides | Google | Aktif |
| S3 | https://developers.google.com/identity/passkeys/use-cases | Google | Diperbarui 19 Mei 2025 |
| S4 | https://support.google.com/accounts/answer/13548313 | Google | Aktif |
| S5 | https://support.apple.com/en-us/102195 | Apple | Aktif; terbit 16 Sep 2024 |
| S6 | https://web.dev/articles/passkey-registration | Google (web.dev) | Diperbarui 9 Apr 2026 |
| S7 | https://www.nngroup.com/articles/passwordless-accounts/ | NN/g | 25 Jun 2023 |

## Fakta yang terlihat

- **Klaim manfaat (S1):** passkey = "alternatif yang lebih aman dan lebih mudah daripada password"; memakai biometrik/PIN/pola; **phishing-resistant**; hanya public key di server; "dapat menghilangkan kebutuhan akan SMS atau OTP berbasis app saat sign-in"; menurunkan biaya SMS.
- **Perangkat/browser (S4):** Windows 10+, macOS Ventura, ChromeOS 109; Android 9+, iOS 16+; kunci FIDO2. Browser: Chrome 109+, Safari 16+, Edge 109+, Firefox 122+. Passkey baru bisa butuh **7 hari** sebelum tersedia saat sign-in. Jangan buat passkey di perangkat bersama.
- **Apple (S5):** passkey menggantikan password (WebAuthn); private key tidak pernah dibagikan; sinkron via iCloud Keychain (E2E); akun dengan iCloud Keychain wajib 2FA; pemulihan bergantung escrow iCloud + SMS ke nomor terdaftar + passcode perangkat.
- **Migrasi/fallback (S2, S3):** pertahankan password + 2FA selama transisi; "asking for an additional credential called one-time password (OTP) is a common practice"; gunakan autofill suggestion di form username/password agar pengguna passkey & password terlayani; reauth = buka kunci perangkat; lintas perangkat via QR.
- **Batasan ekosistem (S4/S6):** passkey tersinkron di dalam ekosistem (Android/Google Password Manager; Apple/iCloud Keychain), tidak antar-ekosistem.
- **Prasyarat (S6):** akun sudah terverifikasi dengan metode aman (email/telepon) dalam jendela waktu singkat; peringatan agar tidak memverifikasi hanya dengan password.
- **NN/g (S7):** OTP punya dua biaya — menunggu kode (signifikan di koneksi buruk) dan mengakses+mengetik kode; biaya akses minimal bila SMS muncul sebagai saran keyboard; **83% memakai biometrik setidaknya sesekali (CI 73–87%)**; rekomendasi: setelah akun passwordless, tawarkan password + biometrik; **biarkan pengguna memilih OTP via email atau SMS**; passkey lintas perangkat via QR.
- **Peringatan keamanan SMS OTP (web.dev S7 di whatsapp.md / S6):** nomor didaur ulang/dibajak; OTP tidak phishing-resistant.

## Red flag

Tidak ada. Catatan penting untuk konteks Indonesia: dukungan bergantung OS (Android 9+/iOS 16+) dan **sinkronisasi terkunci per ekosistem** — lansia dengan perangkat lama/berbagi bisa kesulitan; pemulihan tetap bersandar pada SMS/passcode.

## Yang TIDAK bisa diverifikasi

Tidak ada spesifikasi "pola fallback OTP" tunggal — fallback bersifat implementasi (Google tetap menyarankan OTP selama transisi). Cakupan perangkat Indonesia tidak diukur di sumber mana pun.

## Independensi sumber

**Google (S1–S4, S6), Apple (S5), NN/g (S7) = ≥2 penerbit independen.** Satu-satunya kandidat F01 yang memenuhi ambang independensi rubrik; namun cakupannya hanya kaki **login** (bukan registrasi/OTP), dan kelayakan stack Sehatly rendah (butuh endpoint challenge WebAuthn + perubahan model sesi).
