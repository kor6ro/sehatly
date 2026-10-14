# Evidence F03 — Alodokter (cari dokter & filter)

Tanggal akses semua sumber: **2026-10-02**. Native app tidak dibuka. Jenis: halaman web publik (HTML statis + JSON-LD) + Play listing.

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.alodokter.com/cari-dokter | Web publik | HTTP 200; komponen client |
| S2 | https://www.alodokter.com/cari-dokter/dokter-umum | Web publik | HTTP 200; JSON-LD 10 dokter |
| S3 | https://www.alodokter.com/cari-dokter/kedokteran-umum/all | Web publik | Daftar; kartu dirender JS |
| S4 | https://www.alodokter.com/cari-dokter/kategori | Web publik | HTTP 200 |
| S5 | https://www.alodokter.com/cari-rumah-sakit | Web publik | Filter faskes |
| S6 | https://play.google.com/store/apps/details?id=com.alodokter.android | Play listing | Aktif |

## Fakta terlihat

- **Pencarian:** satu omnibox `<omni-search base-url="/cari-dokter/">` (S1). Placeholder: **TIDAK TERVERIFIKASI** (tidak ada di HTML statis).
- **Filter:** landing dokter memakai **tile spesialisasi** (Kandungan, Anak, THT, Paru, Mata, Kulit, Penyakit Dalam, Psikiater) + "Lihat Semua" (S1/S4). Halaman faskes (S5) memakai **3 grup filter**: "Gunakan Lokasi Saya / Semua Lokasi", "Pilih Spesialisasi Dokter", "Pilih Tindakan Medis". Filter direktori dokter selain spesialisasi/lokasi: **TIDAK TERVERIFIKASI**.
- **Kartu hasil (JSON-LD S2 + listing S3):** nama; spesialisasi; RS/klinik + alamat; harga `makesOffer` (Rp); rating (`aggregateRating`); **`next_schedule`** ("Tersedia Hari Ini" / "Jadwal Berikutnya : 19 Sep 2026, 08.00 Pagi"); "X pasien telah buat janji"; "Ulasan Dokter (N)"; "Biaya Konsultasi Rp…" → **≈6 field**.
- **Jumlah hasil:** tidak teramati → N/V.
- **Sort:** tidak teramati → **TIDAK TERVERIFIKASI** (tidak ada kontrol sort).
- **Pagination:** `numberOfItems: 10` pada JSON-LD (petunjuk ukuran halaman 10) + varian "/all" → **TIDAK TERVERIFIKASI**.
- **Zero-result:** string global "Pencarian Tidak Ditemukan — Maaf, Kota yang Anda cari tidak ditemukan. Silakan lakukan pencarian ulang." (bukan khusus dokter) → recovery = cari ulang.
- **Iklan:** infrastruktur iklan programatik terpasang (Criteo, Google DoubleClick/GPT dengan `dfp-container`/`adunit`, Facebook Pixel, Insider); **pelabelan slot di daftar dokter: TIDAK TERVERIFIKASI**. Rating ditampilkan sebagai **persentase** (100%, 95%, 98%), bukan bintang.

## Hitungan

| Besaran | Nilai |
|---|---|
| Grup filter (faskes) | **3** |
| Tile spesialisasi (dokter) | **8** |
| Field kartu hasil | **≈6** |

## Red flag

- **Adtech berat di halaman kesehatan** (Criteo/GPT/FB Pixel); pelabelan iklan tidak terverifikasi.
- Harga `0` di JSON-LD sebagian dokter → ambiguitas "gratis vs tidak ditampilkan".
- Rating persentase + label ketersediaan "Tersedia Hari Ini" bisa terbaca sebagai urgensi.

## Yang TIDAK bisa diverifikasi

Placeholder; set/tipe filter direktori dokter; chip filter aktif; sort; jumlah hasil; pagination; persistensi filter; zero-result khusus dokter; pelabelan sponsor; perilaku drawer mobile.

## Independensi sumber

S1–S6 = **satu penerbit: Alodokter**. Tidak memenuhi syarat pemenang; sumber pola lokal terkuat untuk **kartu hasil berisi ketersediaan + estimasi biaya sebelum komitmen** dan state jadwal kosong.
