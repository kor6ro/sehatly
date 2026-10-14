# F10 — Mobile JKN (BPJS Kesehatan): Info Riwayat Pelayanan / i-Care

Flow: peserta sudah login → menemukan riwayat pelayanan/rekam medis lama → membacanya → (unduh?).
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; tidak ada screenshot.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://bpjs-kesehatan.go.id/user-manual-mobile-jkn/pelayanan%20jkn.html [akses 2026-10-01] | User manual resmi BPJS | "Panduan untuk melihat riwayat layanan peserta"; riwayat sesi chat konsultasi |
| S2 | https://bpjs-kesehatan.go.id/user-manual-mobile-jkn/mobilejkn/melihatriwayat.html [akses 2026-10-01] | Halaman resmi (video only) | Tidak ada teks langkah |
| S3 | https://play.google.com/store/apps/details?hl=id&id=app.bpjs.mobile [akses 2026-10-01] | Store listing resmi | Daftar fitur saat ini **tidak lagi menyebut riwayat pelayanan** |
| S4 | https://apps.apple.com/id/app/mobile-jkn/id1237601115 [akses 2026-10-01] | Store listing resmi | v5.0.0 (15 Sep 2026), 4,8★, 13+, "Infrequent Medical Treatment Information"; daftar fitur tidak menyebut riwayat pelayanan |
| S5 | https://www.idxchannel.com/milenomic/apa-itu-i-care-bpjs-kesehatan-cara-cek-rekam-medis-lewat-aplikasi-mobile-jkn/all [akses 2026-10-01] | Press (IDX Channel, 6 Des 2024) | i-Care JKN: riwayat 1 tahun, diagnosa/tindakan/obat, informed consent |
| S6 | https://ehealth.co.id/blog/post/i-care-jkn-inovasi-digital-untuk-akses-riwayat-kesehatan-peserta-jkn/ [akses 2026-10-01] | Press (eHealth.co.id, 14 Apr 2025) | Data i-Care & rentang 12 bulan |
| S7 | https://www.pojoksatu.id/edugov/1087033829/... [akses 2026-10-01] | Press (Pojok Satu, 5 Jan 2026) | Isi riwayat: tanggal, jenis layanan, poli, diagnosa |

## Langkah terlihat (fakta)

1. **Lihat riwayat layanan** [S1]: "**Tap menu lainnya lalu tap Info Riwayat Pelayanan**" → "Informasi riwayat pelayanan ditampilkan".
2. **Riwayat chat konsultasi** [S1]: "Di menu Konsultasi Dokter, peserta bisa melihat **riwayat sesi chatnya** dengan meng-klik tombol riwayat di pojok kanan. Klik Sesi Chat tersebut untuk melihat detail percakapannya."
3. **i-Care** [S5]: buka Mobile JKN → login → menu "**i-Care/Info riwayat pelayanan**" → "Aplikasi akan menampilkan semua riwayat pelayanan yang pernah Anda ambil"; akses nakes memakai password, username, dan **informed consent**.
4. **Isi riwayat** [S5][S6][S7]: riwayat **1 tahun / 12 bulan terakhir**; mencakup diagnosa, tindakan medis, obat (S5/S6); tanggal kunjungan, jenis layanan, poli, diagnosa (S7); "tanpa harus meminta salinan rekam medis secara manual di fasilitas kesehatan" (S7).
5. **Catatan penting:** deskripsi fitur Play Store & App Store per Sep 2026 **tidak mencantumkan** "riwayat pelayanan" [S3][S4] — keberadaan fitur dibuktikan manual resmi + press, bukan listing terbaru.

## Hitungan

Titik awal: **peserta sudah login, ingin menemukan riwayat/rekam medis lama** → tugas inti: **ditemukan dan terbaca** (unduh tidak tersedia).

| Skenario | Layar | Ketukan/step | Field |
|---|---|---|---|
| Buka Info Riwayat Pelayanan | TIDAK TERVERIFIKASI | **2 tap** ("menu lainnya" → "Info Riwayat Pelayanan") [S1] | 0 |
| Buka riwayat chat konsultasi → detail percakapan | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI (deskriptif: klik tombol riwayat → klik sesi) | 0 |
| End-to-end temukan → baca → unduh | TIDAK TERVERIFIKASI | **Unduh/ekspor tidak ditemukan buktinya** | — |

## State terlihat

- **TIDAK TERVERIFIKASI** untuk loading/kosong/error/offline — sumber resmi & press tidak menyebutnya.

## Red flag

- **Kebijakan privasi iOS hanya menaut ke halaman utama** `http://www.bpjs-kesehatan.go.id` [S4] — transparansi privasi lemah; isi kebijakan **TIDAK TERVERIFIKASI**.
- **Riwayat sensitif tanpa kontrol ekspor yang terlihat** [S5]: diagnosa/tindakan/obat ditampilkan, tetapi tidak ada bukti kontrol unduh/ekspor di sisi pasien.
- **Fitur menghilang dari deskripsi store** [S3][S4] — konsistensi dokumen bermasalah; pasien mungkin tidak menemukan fitur yang didokumentasikan manual.
- Tidak ditemukan paywall/upsell.

## Yang TIDAK bisa diverifikasi

- State loading/empty/error/offline.
- Jumlah layar/field lebih rinci; isi layar detail.
- Keberadaan unduh/PDF/cetak.
- Isi kebijakan privasi.
- Apakah fitur masih aktif di versi store Sep 2026 (tidak disebut di deskripsi terbaru).
