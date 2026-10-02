# Evidence F11 — Halodoc (pengingat obat + kotak masuk)

Tanggal akses semua sumber: **2026-10-02**. Titik awal hitungan bersama: **pengguna sudah login dan ingin (A) menindaklanjuti satu notifikasi yang masuk, atau (B) membuat satu pengingat obat + mengatur preferensinya**.
Batasan riset (aturan BENCHMARK_PROMPT #4): aplikasi native tidak dapat diakses langsung; tidak ada akun dibuat, tidak ada form dikirim, tidak ada aplikasi dipasang. Seluruh fakta di bawah berasal dari dokumen publik.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://www.halodoc.com/faq/questions/apa-itu-fitur-pengingat-obat | FAQ resmi “Pengingat Obat” | Web (footer © 2016–2026); fitur Android/iOS | Dibaca penuh 2026-10-02 |
| S2 | https://www.halodoc.com/faq/category/pengingat-obat | Kategori FAQ resmi — 11 judul pertanyaan | Web | Dibaca penuh 2026-10-02 (daftar judul; isi jawaban accordion tidak terekstrak) |
| S3 | https://www.halodoc.com/artikel/pengingat-obat-di-aplikasi-halodoc | Artikel resmi fitur | Web; diterbitkan 2021-02-21 | Indeks pencarian 2026-10-02 |
| S4 | https://www.halodoc.com/artikel/tanya-dokter-dan-beli-obat-pakai-asuransi-di-halodoc | Artikel resmi: letak menu fitur | Web; diterbitkan 2020-05-26 | Indeks pencarian 2026-10-02 |
| S5 | https://www.halodoc.com/syarat-dan-ketentuan | S&K resmi butir 16 & 23 tentang Fitur Pengingat Obat | Web | Indeks pencarian 2026-10-02 |
| S6 | https://play.google.com/store/apps/details?id=com.linkdokter.halodoc.android&hl=id | Listing Google Play (aktif; deskripsi 2026) | Android | Indeks pencarian 2026-10-02 |

## Langkah terlihat (fakta)

1. Fitur **Pengingat Obat** ada: “mengingatkanmu mengonsumsi obat sesuai dengan waktu yang sudah diatur. Jika jamnya sudah tiba, kamu akan mendapat **notifikasi di ponsel**” — dan pengguna diminta memastikan **suara ponsel menyala** agar notifikasi berbunyi (S1).
2. Letak menu per artikel 2020: “menu **More** pada halaman utama aplikasi Halodoc, lalu klik **Reminder**” (S4). Apakah letak ini masih sama pada 2026 **TIDAK TERVERIFIKASI** (artikel 6 tahun; FAQ 2026 tidak menyebut letak menu).
3. FAQ resmi kategori “Pengingat Obat” mendaftarkan 11 topik: apa itu; cara menggunakan; **menghapus** pengingat; penggunaan untuk **anggota keluarga**; **mengubah rincian**; **lebih dari satu pengingat**; notifikasi terhapus tidak sengaja; beberapa pengingat dalam **waktu bersamaan**; tombol **“Tunda”** (snooze); **laporan hasil pengingat** (S2). Judul-judul ini adalah fakta bahwa kemampuan tersebut didokumentasikan; **isi langkah tiap jawaban tidak dapat dibaca** oleh crawler (accordion/JS) → langkah detail TIDAK TERVERIFIKASI.
4. S&K butir 23: “Setelah Pengguna mengatur pengingat waktu di dalam Fitur Pengingat Obat maka Fitur Pengingat Obat dapat membantu Pengguna untuk mengingatkan kembali jadwal konsumsi obat.” Butir 16 menyebut Pengingat Obat sebagai fitur Penunjang Kesehatan (S5).
5. Listing Play 2026 tetap memuat aplikasi aktif dengan layanan konsultasi, obat, homecare (S6) — aktivitas produk terverifikasi, bukan kualitas UX.

## Hitungan (titik awal sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | **TIDAK TERVERIFIKASI** | tidak ada tangkapan layar/hitungan resmi |
| Ketukan | **TIDAK TERVERIFIKASI** (2020: 2 ketukan hanya untuk *membuka* fitur: More → Reminder) | S4 |
| Field | **TIDAK TERVERIFIKASI** | isi jawaban FAQ tidak terekstrak |

## State terlihat

- **Sukses (terjadwal):** notifikasi ponsel pada jam yang diatur (S1). ✓
- **Snooze:** ada tombol “Tunda” (S2, judul pertanyaan). Mekanisme/interval **TIDAK TERVERIFIKASI**.
- **Notifikasi terhapus tidak sengaja:** ada topik FAQ (S2), isi pemulihan **TIDAK TERVERIFIKASI**.
- **Beberapa pengingat bersamaan:** ada topik FAQ (S2), perilaku **TIDAK TERVERIFIKASI**.
- Loading/kosong/error/offline: **tidak ada sumber** → N/V.

## Red flag

- Tidak ditemukan dark pattern (hitung mundur palsu, upsell menutupi aksi, consent terselip) pada sumber yang diakses.
- Catatan privasi (bukan dark pattern): **tidak ada dokumentasi** apakah nama obat tampil di notifikasi layar kunci. Untuk UU PDP, ini gap yang harus ditutup Sehatly dengan keputusan eksplisit (jangan meniru diam-diam).

## Yang TIDAK bisa diverifikasi

- Langkah membuat/mengubah/menghapus pengingat, jumlah field, susunan layar.
- Apakah notifikasi menampilkan nama obat/dosis di layar kunci.
- Preferensi kanal (in-app vs push), jam tenang, status pengiriman, sinkronisasi multi-perangkat.
- Perilaku untuk anggota keluarga (judul FAQ ada; mekanisme tidak terbaca).
- Aksesibilitas (kontras, target sentuh, pembaca layar) — UI tidak diamati.

## Independensi sumber

Seluruh klaim substantif berasal dari **satu penerbit (halodoc.com: S1–S5)**; listing Play (S6) diterbitkan Halodoc sendiri. → **Tidak memenuhi syarat ≥2 sumber independen** untuk ditetapkan sebagai pemenang; dipakai hanya sebagai sumber pola langkah.
