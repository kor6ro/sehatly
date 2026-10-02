# Evidence F01 — NN/g (riset OTP, login, biometrik)

Tanggal akses semua sumber: **2026-10-02**. Jenis: riset usability publik. Dipakai sebagai pedoman lintas kandidat, bukan aplikasi.

## Sumber

| # | URL | Catatan |
|---|---|---|
| S1 | https://www.nngroup.com/articles/passwordless-accounts/ | "Passwordless Accounts: One-Time Passwords (OTPs) and Passkeys" — Raluca Budiu, 25 Jun 2023 |
| S2 | https://www.nngroup.com/videos/2-factor-authentication/ | Video 2-FA — Tim Neusesser, 20 Mei 2025 (isi video tidak ada di teks halaman) |
| S3 | https://www.nngroup.com/articles/mobile-behavior-india/ | "Mobile User Behavior in India" — 4 Sep 2016 |

## Temuan persis (S1)

- **"The Problem with Passwords":** survei — **76%** menyimpan password di ponsel; **17%** password manager pihak ketiga; **10%** keduanya. Password manager kadang gagal mengenali field atau menghasilkan password yang tidak cocok dengan aturan situs.
- **"Passwordless Accounts"/OTP:** OTP menghemat langkah mengingat/mengetik password, tetapi punya **dua biaya**: (1) **menunggu kode tersedia** — "can be significant in areas with poor connectivity"; (2) **mengakses dan memasukkan kode**. Biaya akses minimal bila SMS "muncul sebagai saran di atas keyboard"; **OTP email lebih sulit** (pindah app, risiko filter spam).
- **Rekomendasi persis:** setelah akun passwordless, **tawarkan membuat password DAN biometrik**; **"For OTPs, let users choose between email and text messages"**; **"For passkeys, support multiple devices by allowing users to scan a QR code."** Survei: **83% memakai biometrik setidaknya sesekali** (95% CI 73–87%).
- **"Passkeys":** biaya interaksi lebih rendah dari OTP (tanpa mengetik, copy/paste, klik tautan); lebih aman/phishing-resistant; butuh QR lintas ekosistem.

## Temuan persis (S3)

Bagian "One-Time Passwords (OTP) for Registration and Login": pengguna memasukkan nomor, situs mengirim SMS OTP; "Often they won't even have to type the OTP: the site will grab the OTP from the received text message and will enter it into the login screen." OTP juga dipakai di desktop dengan ponsel di dekatnya; setiap login OTP baru — "No need for remembering passwords!"

## Temuan (S2)

Ringkasan halaman video: "2-FA is one of the simplest ways to protect user data, but you must balance security with the potential impact on usability." Isi rinci video **tidak ada di teks** → N/V.

## Red flag

NN/g secara eksplisit memperingatkan biaya menunggu OTP **berat di koneksi buruk** — relevan langsung untuk pengguna Sehatly. Tidak ada dark pattern.

## Yang TIDAK bisa diverifikasi

Rekomendasi pemulihan error OTP yang rinci (hanya di video 2-FA); tidak ditemukan artikel NN/g khusus "SMS-OTP error recovery" di sesi ini.

## Independensi sumber

Satu penerbit (NN/g), tetapi **independen dari semua aplikasi kandidat** — dipakai sebagai dasar pola lintas langkah bersama sumber lain (Meta/Google/Baymard).
