# F10 — SATUSEHAT Mobile (Kemenkes RI): Resume Medis / riwayat

Flow: pasien sudah login → menemukan rekam medis/riwayat lama → membacanya → (unduh/ekspor?).
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; tidak ada screenshot.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://satusehat.kemkes.go.id/mobile [akses 2026-10-01] | Situs resmi aplikasi | Tautan store, FAQ, privasi; status aktif 2026 |
| S2 | https://satusehat.kemkes.go.id/platform/docs/id/kyc/ [akses 2026-10-01] | Dokumentasi developer resmi (v7.23) | PHR: kunjungan sebelumnya, pengobatan, diagnosis; akses via ikon Rekam Medis di beranda/menu Profil |
| S3 | https://kemkes.go.id/id/satusehat-mobile-versi-6-0-1-hadirkan-cara-login-baru-dan-akses-hasil-tes-laboratorium [akses 2026-10-01] | Rilis pers resmi Kemenkes, 14 Mar 2024 | Resume Medis sejak Nov 2023; riwayat kunjungan + diagnosis; hasil lab di v6.0.1 |
| S4 | https://satusehat.kemkes.go.id/platform/terms-and-privacy [akses 2026-10-01] | S&K/privasi platform resmi | Kirim data by default; dasar hukum UU PDP Pasal 20(2)(c); akses BPJS & Dinas Kesehatan |
| S5 | https://satusehat.kemkes.go.id/rekammedis [akses 2026-10-01] | Portal RME (untuk nakes) | Kode akses 6 digit dari akun pasien; "Pasien akan mengetahui ketika Anda melihat data pasien" |
| S6 | https://satusehat.kemkes.go.id/mobile/faq [akses 2026-10-01] | FAQ resmi | Kategori "Resume Medis": akses, data kosong, data salah/duplikat, keamanan, RME keluarga |
| S7 | https://satusehat.kemkes.go.id/mobile/faq/topic?categoryId=bc5893fc-017c-431a-9161-66d152ec6aac [akses 2026-10-01] | FAQ resmi (kategori Resume Medis) | Judul topik terlihat; badan dirender JS |
| S8 | https://play.google.com/store/apps/details?hl=id&id=com.telkom.tracencare [akses 2026-10-01] | Store listing resmi | Update 28 Agu 2026, v8.9.1, 50 jt+ unduhan, 3,4★, 1,12 jt ulasan |

- **Batasan riset:** badan jawaban FAQ dirender JavaScript (mengembalikan "Loading...") → hanya judul topik + tanggal "Terakhir diperbarui" yang terverifikasi; app native tidak bisa diamati.

## Langkah terlihat (fakta)

1. **Akses PHR/Resume Medis** [S2]: RME dirangkum sebagai Personal Health Record; memberi "visibilitas terhadap **kunjungan institusi kesehatan sebelumnya, pengobatan, diagnosis, dan data medis historis lainnya**"; diakses lewat **ikon Rekam Medis di beranda atau menu Profil**; verifikasi profil (KYC) cukup sekali.
2. **Syarat tampil** [S3]: "mengakses catatan medis mereka dari ponsel **setelah profil akun terverifikasi ('centang biru')**"; login PIN 6 digit.
3. **Isi yang muncul** [S3]: "data riwayat kunjungan dan hasil diagnosis juga dapat muncul"; v6.0.1 menambah hasil pemeriksaan laboratorium; gambar radiologi (USG, EKG, CT Scan, MRI) masih uji coba.
4. **Judul topik FAQ** [S6][S7]: "Bagaimana cara mengakses Resume Medis…" (diperbarui 28 Jul 2026), "Apa itu Resume Medis?", "…kenapa data saya masih kosong?" (31 Okt 2024), "Bagaimana keamanan data untuk RME?" (11 Jul 2024), "Apakah saya bisa melihat RME anggota keluarga?" (11 Jul 2024), data salah/duplikat, near real-time, WNA.
5. **Anti-snooping di sisi nakes** [S5]: nakes membuka data dengan kode akses 6 digit dari akun pasien; "Pasien akan mengetahui ketika Anda melihat data pasien".

## Hitungan

Titik awal: **pasien sudah login, ingin menemukan rekam medis lama** → tugas inti: **ditemukan, terbaca, dan (bila memungkinkan) diunduh**.

| Skenario | Layar | Ketukan/step | Field |
|---|---|---|---|
| Buka Resume Medis dari beranda/Profil | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI (badan FAQ tidak terbaca) | 0 (KYC sekali) |
| End-to-end temukan → baca → unduh | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI |
| Unduh/ekspor PDF | **Tidak ditemukan bukti adanya fitur** | — | — |

## State terlihat

- **Kosong (dikonfirmasi via judul FAQ):** "…kenapa data saya masih kosong?" [S6/S7].
- **Data salah/duplikat:** topik FAQ "data tidak sesuai dan/atau duplikat" [S6].
- **Near real-time:** topik FAQ khusus [S6].
- **Error (laporan pengguna di store listing):** status pemeriksaan tertunda; "setiap di buka log out terus"; "OTP Not Found" [S8].
- **Loading / offline:** **TIDAK TERVERIFIKASI**.

## Red flag

- **Kirim data kesehatan by default tanpa opt-in** [S4]: "Secara standar (by default) Data Kesehatan dikirimkan secara otomatis ke SATUSEHAT" dengan dasar kewajiban hukum (UU PDP Pasal 20(2)(c); PMK 24/2022). Bukan pilihan pengguna — dicatat sebagai fakta kebijakan platform, bukan pola UI yang ditiru.
- **Akses pihak ketiga** [S4]: data dapat diakses "institusi lain yang berwenang … seperti **BPJS Kesehatan dan Dinas Kesehatan**".
- **Data safety store** [S8]: aplikasi dapat membagikan Kesehatan & kebugaran, Foto/video, Aktivitas aplikasi ke pihak ketiga.
- **Risiko salah taut identitas:** adanya topik FAQ "Data rekam medis yang muncul bukan data milik saya" [S6].
- **Positif (bukan red flag):** pasien diberi tahu saat nakes melihat datanya [S5]; KYC sekali + PIN [S2][S3].
- Tidak ditemukan paywall/upsell di sumber resmi.

## Yang TIDAK bisa diverifikasi

- Langkah tap persis (badan FAQ JS "Loading...").
- State loading/offline; isi pesan error.
- Keberadaan tombol unduh/ekspor/share/PDF — **tidak ada sumber yang menyebutnya; jangan disimpulkan ada**.
- Teks lengkap Pemberitahuan Privasi & S&K (halaman panjang, tidak seluruhnya terbaca).
- Tampilan layar native app.
