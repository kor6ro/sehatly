# Evidence F01 — Mobile JKN (BPJS Kesehatan, lokal Indonesia)

Tanggal akses semua sumber: **2026-10-02**. Native app tidak dibuka; bukti = manual pengguna resmi + Play listing + halaman publik. Jenis: help/manual resmi + store listing.

## Sumber

| # | URL | Jenis | Catatan akses |
|---|---|---|---|
| S1 | https://bpjs-kesehatan.go.id/user-manual-mobile-jkn/akun-mobile-jkn.html | Manual pengguna resmi | Dibaca penuh 2026-10-02 |
| S2 | https://play.google.com/store/apps/details?id=app.bpjs.mobile | Play Store listing | Aktif; updated 8 Jul 2026; 4,3★; 1,05 jt ulasan; 100 jt+ unduhan |
| S3 | https://registrasi.bpjs-kesehatan.go.id/ | Portal web registrasi terpisah (bukan app) | Halaman kosong saat di-fetch; field terlihat via indeks: Username + Email + Captcha; Ubah Password: Username + Password Lama + Password Baru + Konfirmasi + Captcha |
| S4 | Google Play reviews (Sep 2026) | Ulasan pengguna (komunitas, bukan fakta resmi) | Cuplikan; ditandai "user-reported" |

## Langkah terlihat (fakta, S1)

**Registrasi (≈6–7 langkah):** Daftar → form **NIK, Nama, Tanggal Lahir, Captcha** → "Verifikasi Data" → input **nomor handphone** → "kirim kode verifikasi" → halaman T&C → centang **"saya setuju"** → "selanjutnya" → halaman verifikasi **kode OTP** → "registrasi".
**Login:** Masuk → pilih jenis identitas → **NIK/NOKA + Password + Captcha** → MASUK.
**Biometrik (terdokumentasi):** tombol sidik jari di halaman login; aktivasi lewat Profil → "Ubah ke ON (Login dengan Biometrik)" → T&C → password → tombol verifikasi → password lagi → ON. Mendukung sidik jari dan face recognition.
**Lupa kata sandi:** halaman login → "lupa kata sandi" → **NIK + Captcha** → Lanjut → pilih kanal **SMS / E-Mail / Pertanyaan Data Pribadi**.
**Ganti PIN / kata sandi:** Profil → ubah PIN (PIN lama → verifikasi → PIN baru → verifikasi → setuju); ubah kata sandi (lama + baru + konfirmasi + captcha → simpan → **wajib login ulang**).
**Logout:** menu lainnya → keluar → pop-up → Setuju.
**Session persistence:** toggle "Simpan Data Username" mengisi otomatis nomor kartu terakhir.

## Hitungan (titik awal sama: belum punya akun / sudah punya akun)

| Besaran | Nilai | Dasar |
|---|---|---|
| Field registrasi | **5** (NIK, Nama, Tgl Lahir, Captcha, No. HP) + 1 field OTP | S1 |
| Langkah registrasi | ≈6–7 | S1 |
| Field login | **3–4** (jenis identitas, NIK/NOKA, password, captcha) | S1 |
| Lupa kata sandi | 2 field (NIK, captcha) lalu 1 pilihan kanal | S1 |
| Aktivasi biometrik | ≥6 langkah termasuk **dua kali** input password | S1 |

## State terlihat

- T&C ditampilkan **sebelum** OTP (consent eksplisit di titik daftar) — S1. ✓
- Ganti kata sandi → **login ulang wajib** (sesi lama tidak dibiarkan hidup) — S1. ✓
- Captcha di registrasi & login (pencegahan bot) — S1.
- Loading/kosong/error/expired OTP: **tidak didokumentasikan** → N/V.

## Red flag

- **Diskrepansi alur:** manual resmi tidak menyebut kapan password dibuat, padahal login menuntut password — titik pembuatan password tidak terdokumentasi (bukan dark pattern; cacat dokumen).
- Captcha berulang **user-reported (S4):** "captcha salah belasan kali", logout spontan, reset password berulang. Bukan fakta terverifikasi, tetapi sinyal gesekan untuk pengguna kelas bawah.

## Yang TIDAK bisa diverifikasi

1. Apakah kode OTP 6 digit (klaim pihak ketiga, bukan manual).
2. Timer kedaluwarsa, tombol/cooldown **kirim ulang**, state kode salah/kedaluwarsa, copy error.
3. Dukungan `autocomplete="one-time-code"`/paste.
4. Batas perangkat/sesi (klaim "maks 3 NIK per perangkat" hanya di situs SEO berkualitas rendah).
5. Kapan/layar mana password dibuat saat registrasi.
6. Apakah biometrik bisa di-enroll ulang tanpa password.

## Independensi sumber

S1 (manual BPJS) dan S2 (Play listing BPJS) = **satu penerbit: BPJS Kesehatan**. S4 = ulasan pengguna, bukan penerbit independen yang memverifikasi. → Mobile JKN **tidak memenuhi syarat pemenang ≥2 penerbit**; dipakai sebagai sumber pola per langkah.
