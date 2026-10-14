# Evidence F01 — Bank Jago (perangkat baru, 2-step verification, manajemen sesi)

Tanggal akses semua sumber: **2026-10-02**. Native app tidak dibuka; bukti = help center resmi + halaman produk + Play listing. Jenis: help page resmi + store listing.

## Sumber

| # | URL | Jenis | Catatan akses |
|---|---|---|---|
| S1 | https://www.jago.com/en/id/support/faq/use-jago/log-in-to-jago/using-biometric | Help resmi — biometrik | Aktif |
| S2 | https://www.jago.com/en/support/faq/log-in-dengan-perangkat-baru | Help resmi — login perangkat baru | Konten terbaca |
| S3 | https://www.jago.com/en/syariah/support/faq/use-jago/log-in-to-jago/log-in-using-other-device | Help resmi (Syariah) — perangkat lain | Konten terbaca |
| S4 | https://www.jago.com/en/syariah/support/faq/use-jago/more/notification-and-device | Help resmi — notifikasi & perangkat | Konten terbaca |
| S5 | https://www.jago.com/en/jago/digital/security-and-control | Halaman produk keamanan | Aktif |
| S6 | https://play.google.com/store/apps/details?id=com.jago.digitalBanking | Play listing | Aktif; 4,7★; 271 rb ulasan; 10 jt+ unduhan |

## Langkah terlihat (S1–S4)

**Biometrik:** sidik jari (Android+iOS) atau Face ID (**iOS saja**), tergantung perangkat. Setup saat login pertama atau More → gear → toggle Fingerprint/Touch ID/Face ID → Activate Now → validasi.
**Login perangkat baru — ponsel lama tersedia:** login di ponsel baru → notifikasi di app Jago ponsel lama → ketuk → **tinjau nama perangkat + waktu** → "This Was Me" → masukkan **kode verifikasi ke nomor terdaftar** → buat **PIN perangkat baru** + konfirmasi.
**Login perangkat baru — ponsel lama tidak ada:** email/nomor terdaftar + password → **"Verify on this device instead" / "Try another way"** → **nomor e-KTP** → **verifikasi wajah selfie** (Jago) **atau** pertanyaan keamanan (Syariah) → **kode OTP/verifikasi** → buat PIN baru. **Gagal verifikasi wajah → diarahkan ke Customer Service (manual).**
**Banyak perangkat** boleh login bersamaan, tetapi setiap perangkat baru wajib 2-Step Verification lebih dulu.
**Manajemen sesi (Settings):** Device Access/Manage Devices, Change App PIN, Activate biometric, Change/Forgot password, Transaction Authentication, Log Out.

## Hitungan

| Besaran | Nilai | Dasar |
|---|---|---|
| Login perangkat baru | 2–3 field + 1–2 kode + PIN baru; **6–8 langkah** | S2/S3 |
| Setup biometrik | ≈6 langkah | S1 |
| Menu pengaturan keamanan | 9 entri terdokumentasi | S4 |

## State terlihat

- **Notifikasi ke perangkat lama** sebagai kanal persetujuan, dengan tampilan device+time (mencegah takeover diam-diam) — S2. ✓
- Jalur alternatif saat perangkat lama hilang: e-KTP + selfie/pertanyaan keamanan — S3. ✓
- **Fallback manusia:** gagal selfie → CS (pemulihan tidak self-serve) — S3. ✓
- State kode salah/kedaluwarsa, timer, kirim ulang: tidak dinyatakan → N/V.

## Red flag

- Tidak ada pemulihan mandiri bila verifikasi wajah gagal → bergantung CS (terverifikasi S3).
- Gesekan tinggi: e-KTP + selfie (atau pertanyaan keamanan) untuk perangkat baru — trade-off keamanan vs kemudahan.

## Yang TIDAK bisa diverifikasi

Jumlah digit OTP; timer/resend; state kedaluwarsa/salah; autocomplete/paste; aturan re-enroll biometrik; isi layar Device Access. Artikel khusus "Getting an OTP" mengembalikan **HTTP 403** (bot-protected) — isi timer/resend tidak terverifikasi.

## Independensi sumber

S1–S6 = **satu penerbit: Bank Jago** (+ Play hosting). Tidak memenuhi syarat pemenang; sumber pola terkuat untuk **verifikasi perangkat baru + manajemen sesi**.
