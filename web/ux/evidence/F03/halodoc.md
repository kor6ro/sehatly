# Evidence F03 — Halodoc (cari dokter & filter)

Tanggal akses semua sumber: **2026-10-02**. Native app tidak dibuka. Jenis: halaman web publik + Play listing. Login wall: **tidak** untuk landing/kategori; hasil terisi tidak terverifikasi.

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.halodoc.com/cari-dokter | Web publik | HTTP 200 |
| S2 | https://www.halodoc.com/cari-dokter/dokter-umum | Web publik | HTTP 200; shell zero-result |
| S3 | https://play.google.com/store/apps/details?id=com.linkdokter.halodoc.android | Play listing | Aktif; updated 30 Sep 2026; 4,8★; ~504 rb ulasan; 10 jt+ |
| S4 | https://www.halodoc.com/syarat-dan-ketentuan | T&C publik | Via indeks |

## Fakta terlihat

- **Letak pencarian:** blok "Janji Temu Dokter" dengan input + tombol "Cari" (S1). Pintasan populer: "Rumah Sakit Terdekat, Dokter Spesialis Anak Terdekat, Dokter Spesialis Kandungan Terdekat".
- **Navigasi spesialisasi:** grid 8 tile ("Sp. Kandungan & Kebidanan, Sp. Kulit & Kelamin, Sp. THT, Sp. Jiwa, Sp. Penyakit Dalam, Sp. Anak, Sp. Mata, Dokter Gigi") + "Lihat Semua" (S1).
- **Filter:** **tidak ada kontrol filter statis** di landing; halaman faskes ("Pilih Dokter di Faskes") menampilkan kartu RS dengan 2 field (nama + tipe faskes). Tipe widget filter di layar hasil: **TIDAK TERVERIFIKASI**.
- **Kartu hasil dokter:** tidak terender (JS/API). **TIDAK TERVERIFIKASI.**
- **Jumlah hasil, sort, pagination, chip filter aktif:** **TIDAK TERVERIFIKASI.**
- **Zero-result (template teramati S2):** "Maaf, dokter tidak dapat ditemukan" + tombol **"Ubah Lokasi"** + "Mohon periksa ejaan atau masukan lokasi lain dan cari kembali" + daftar tipe kueri yang diterima ("Nama dokter…", "Nama spesialisasi…"). Catatan: ini kemungkinan shell JS default, bukan state pasca-pencarian nyata → string teramati, layar nyata tidak.
- **Sponsor:** tidak teramati; pelabelan iklan **TIDAK TERVERIFIKASI**.

## Hitungan

| Besaran | Nilai |
|---|---|
| Filter terlihat di landing | **0** (8 tile spesialisasi) |
| Ketukan menerapkan filter | tidak dapat ditentukan |
| Field kartu hasil | tidak dapat ditentukan (kartu RS = 2) |

## Red flag

- **Metadata menyesatkan:** `<title>` halaman `/cari-dokter` = "Daftar Rumah Sakit Lengkap di Indonesia", sementara H1/OG = pencarian janji dokter (S1).
- **Pemulihan zero-result tipis:** hanya "Ubah Lokasi"; tidak ada saran spesialisasi/kata kunci alternatif (S2).
- Sponsor tidak dapat dipastikan berlabel atau tidak.

## Yang TIDAK bisa diverifikasi

Placeholder pencarian; kontrol & tipe filter; chip filter aktif; sort; field kartu; jumlah hasil; pagination vs infinite; hasil terisi tanpa login; pelabelan iklan; zero-result untuk kueri spesialisasi yang valid.

## Independensi sumber

S1–S4 = **satu penerbit: Halodoc**. Tidak memenuhi syarat pemenang; sumber pola lokal untuk **kotak cari + pintasan populer + tile spesialisasi** dan contoh zero-result.
