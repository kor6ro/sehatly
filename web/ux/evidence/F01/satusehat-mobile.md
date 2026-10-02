# Evidence F01 — SATUSEHAT Mobile (Kemenkes, lokal Indonesia)

Tanggal akses semua sumber: **2026-10-02**. Native app tidak dibuka; tidak ada versi web publik. Bukti = FAQ publik (judul topik) + Play listing. Jenis: help center resmi + store listing.

## Sumber

| # | URL | Jenis | Catatan akses |
|---|---|---|---|
| S1 | https://satusehat.kemkes.go.id/mobile/faq | FAQ resmi | Aktif; isi jawaban **"Loading..."** (dirender via API internal, bukan HTML publik) |
| S2 | https://satusehat.kemkes.go.id/mobile/faq/topic?categoryId=66a03477-fecd-4a34-a181-e8c0bf580beb | Kategori "Akun dan Keamanan" | Judul topik terbaca; isi tidak |
| S3 | https://satusehat.kemkes.go.id/mobile/faq/topic?categoryId=4d445909-f5f8-49c7-bdfa-128363974485 | Kategori "Kendala Login" | 7 judul topik terbaca; isi tidak |
| S4 | https://play.google.com/store/apps/details?id=com.telkom.tracencare | Play Store listing | Aktif; updated 28 Agu 2026; v8.9.1; 3,4★; 1,12 jt ulasan; 50 jt+ unduhan |
| S5 | Google Play reviews (Agu–Sep 2026) | Ulasan pengguna (komunitas) | Ditandai "user-reported" |

## Langkah terlihat (fakta = keberadaan topik, bukan isi)

- **Registrasi:** topik "Kenapa saya tidak dapat registrasi akun?" ada (S2) — langkah tidak dapat dibaca.
- **PIN:** topik "Apa itu PIN", "Bagaimana cara membuat PIN", "Apa yang harus dihindari", "memperbarui PIN", "lupa PIN" (S2) → alur PIN ada.
- **Biometrik:** topik "Apa itu Biometrik", syarat, cara mengaktifkan, manfaat, cara menonaktifkan, gagal verifikasi biometrik (S2) → alur biometrik ada.
- **Login:** topik "lupa akun", "kendala saat masuk", "masuk dengan No. HP atau email", **"masuk dengan Identitas Kependudukan Digital (IKD)"**, ubah email/telepon, "login jika nomor HP lupa/ganti/hilang" (S3).
- Play listing: v8.9.1 menambahkan **verifikasi profil mandiri (KYC)**; data safety menyatakan mengumpulkan lokasi + info pribadi (S4).

## Hitungan

Tidak dapat ditentukan (isi jawaban FAQ tidak tersedia di HTML publik; tidak ada versi web app).

## State terlihat

Tidak ada. Semua state (loading/kosong/error/expired OTP) **tidak dapat dibaca**.

## Red flag

- **User-reported (S5, 25 Agu 2026):** "OTP Berkali kali sudah sesuai pas di Confirm, OTP Not Found … Login pakai email dan No HP akun tidak di Temukan" — sinyal loop OTP + pencarian akun gagal. Bukan fakta resmi.
- **User-reported (S5, 8 Sep 2026):** logout paksa setelah update.

## Yang TIDAK bisa diverifikasi

Seluruh alur registrasi/OTP: field, tombol, jumlah digit OTP, timer, kirim ulang, state kode salah/kedaluwarsa, aturan PIN, langkah biometrik, autocomplete/paste, manajemen perangkat, logout. FAQ menjawab via komponen dinamis; tidak ada web app publik. → **N/V dominan; tidak cukup bukti untuk diskor sebagai kandidat.**

## Independensi sumber

S1–S4 semuanya **Kemenkes RI** (satu penerbit). S5 = ulasan pengguna. Tidak memenuhi syarat pemenang; dipakai hanya sebagai konteks bahwa alur PIN/biometrik/IKD lazim di layanan kesehatan nasional.
