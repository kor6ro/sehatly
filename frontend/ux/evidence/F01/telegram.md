# Evidence F01 — Telegram (pola kode, kirim ulang, konfirmasi login)

Tanggal akses: **2026-10-02**. Sumber resmi API Telegram. Jenis: dokumentasi teknis. Bukti ini **level API**, bukan UI pengguna — dipakai hanya untuk mekanisme (resend `next_type`/`timeout`, invalidasi kode, konfirmasi sesi).

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://core.telegram.org/api/auth | Dokumentasi resmi auth | Aktif; dibaca 2026-10-02 |

## Fakta yang terlihat (S1)

- **Kanal kode bukan hanya SMS:** pesan layanan di app Telegram, SMS, email, panggilan suara, flash call, missed call, Fragment SMS, Firebase SMS (app resmi + Play Integrity/APNs).
- **Kirim ulang:** `auth.resendCode`; server mengembalikan `next_type` dan `timeout` — "if the message takes too long (`timeout` seconds), resend a code of type `next_type`".
- **2FA:** bila aktif, login mengembalikan `SESSION_PASSWORD_NEEDED` → alur password SRP (`auth.checkPassword`); password salah → `PASSWORD_HASH_INVALID`.
- **Konfirmasi login:** sesi lain menerima `updateNewAuthorization`; pengguna mendapat pilihan **Yes/No** untuk mengonfirmasi/mematikan sesi baru; auto-confirm setelah periode yang diatur server.
- **Keamanan:** kode **otomatis tidak berlaku bila diteruskan atau di-screenshot**; ada flood limit percobaan login; future auth tokens (hingga 20) bisa melewati kode; tersedia **QR login** dan **passkey login**.

## Red flag

Tidak ada dark pattern. Justru pola pertahanan: kode batal otomatis jika diteruskan/di-screenshot; sesi baru harus dikonfirmasi.

## Yang TIDAK bisa diverifikasi

Tata letak layar OTP aplikasi Telegram; apakah SMS adalah kanal default (Telegram lebih memilih kode dalam app); autocomplete/paste di app; durasi `timeout` aktual.

## Independensi sumber

Satu penerbit (Telegram). Tidak memenuhi syarat pemenang; dipakai sebagai sumber mekanisme kirim ulang & konfirmasi sesi (2 mekanisme yang bisa ditiru secara konsep tanpa menyalin aset/teks).
