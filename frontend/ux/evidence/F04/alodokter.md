# Evidence F04 — Alodokter (profil dokter & kepercayaan)

Tanggal akses semua sumber: **2026-10-02**. Native app tidak dibuka. Jenis: halaman web publik (HTML + JSON-LD) + help provider Alomedika + Play listing.

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.alodokter.com/cari-dokter/dr-nur-alim-fitradjaja-sppd | Profil dokter | HTTP 200; JSON-LD + cuplikan terender |
| S2 | https://www.alodokter.com/cari-dokter/dokter-penyakit-dalam | Daftar spesialisasi | JSON-LD banyak dokter |
| S3 | https://www.alomedika.com/alomedika-point-dan-mypatient-di-alomedika | Help provider (ekosistem sama) | Mekanisme ulasan + balasan |
| S4 | https://play.google.com/store/apps/details?id=com.alodokter.android | Play listing | >20 rb dokter; >1.500 RS/klinik |

## Fakta terlihat — profil (S1)

- `aggregateRating`: **ratingValue 5, reviewCount 10**.
- Cuplikan terender: spesialisasi "Dokter Penyakit Dalam"; **"100%"**; **"163 pasien telah buat janji"**; kartu kecil "100% / 10 pasien"; "Profil Dokter" (bio); bagian **"Lokasi & Jadwal Praktik"**.
- Faskes + biaya: "RS Permata Bekasi **Rp240.000**"; "Rumah Sakit Puspa Husada" (JSON-LD Rp123.500).
- Jadwal: `openingHoursSpecification` per faskes (mis. Sen–Kam 10:00–15:00; Puspa Husada Sel/Kam 19:00–20:30).
- Ulasan (JSON-LD): `review[]` dengan **nama lengkap + tanggal** — "Deny Arianto" (2024-06-08), "Heri Maryono" (2021-05-23), "Rukilah" (2020-11-29), "Tri Julianto Wibowo" (2024-12-11), "Muhamad Armin" (2024-02-22, **isi kosong**).
- Layanan: `hasOfferCatalog` (konsultasi + tindakan penunjang).
- Kredensial: hanya nama + gelar/spesialisasi; **tidak ada nomor STR/SIP, tidak ada tautan registry**.
- CTA: "Buat Janji" (judul halaman).

## Fakta terlihat — daftar (S2)

- `ratingValue` berupa **persentase**, bukan bintang (100%, 95%, 98%, 96%) + `reviewCount` (55, 46, 93, 123, 28…) + harga (Rp150.000–800.000) + RS + `next_schedule` ("Tersedia Hari Ini" / "Jadwal Berikutnya : 19 Sep 2026, 08.00 Pagi").

## State parsial/kosong (teramati, penting)

- **"Saat ini belum terdapat jadwal dokter yang tersedia untuk konsultasi dengan Anda. Silakan pilih dokter lainnya untuk berkonsultasi."** — muncul dua kali pada profil yang **tetap menampilkan rating + 10 ulasan**. Ini state jadwal kosong yang jujur + aksi lanjut.

## Mekanisme ulasan (S3)

- Dokter dapat melihat ulasan pasien di "MyPatient" dan **membalas**; balasan tampil **publik** di profil "Ulasan Dokter". Ulasan berasal dari pasien telemedicine atau "Buat Janji".

## Red flag

- Rating tampil **"100%"** (gaya rekomendasi) sementara data terstruktur 5/10 → model mental campuran.
- Ulasan tertua 2020 (usang) dan satu ulasan berisi kosong tetap dihitung.
- **Nama lengkap reviewer ada di JSON-LD** (belum dikonfirmasi bagaimana dirender) → risiko privasi reviewer.
- Label "Tersedia Hari Ini" dapat terbaca sebagai urgensi.

## Yang TIDAK bisa diverifikasi

Inisial/badge verifikasi reviewer di UI; pagination/filter/sort ulasan; apakah nama reviewer dimasking di render; perilaku setelah CTA.

## Independensi sumber

S1–S4 = **satu penerbit: Alodokter (termasuk Alomedika)**. Tidak memenuhi syarat pemenang; sumber pola lokal untuk **profil berisi jadwal + harga per faskes**, **state jadwal kosong yang jujur**, dan **balasan dokter publik**.
