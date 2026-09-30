# Evidence F08 — Halodoc (konsultasi chat)

Titik awal hitungan (berlaku semua kandidat F08): **user membuka ruang konsultasi**. Tugas inti: **satu pesan terkirim dan diakui**, atau sesi ditutup.

## Sumber

| # | URL | Jenis sumber | Tanggal akses | Versi/platform |
|---|---|---|---|---|
| 1 | https://www.halodoc.com/faq/questions/mengapa-saya-tidak-dapat-memulai-chat-dengan-dokter-atau-masuk-ke-chatroom | FAQ resmi Halodoc — dibuka/di-fetch langsung oleh riset ini | 2026-10-01 | Web, "Chat Dokter" |
| 2 | https://www.halodoc.com/faq/category/chat-dokter | Indeks FAQ resmi "Chat Dokter" | 2026-10-01 | Web |
| 3 | https://www.halodoc.com/faq/questions/bagaimana-cara-saya-konsultasi-online-di-halodoc | FAQ resmi — 6 langkah konsultasi online (hingga chat dimulai) | 2026-10-01 | Web |
| 4 | https://www.halodoc.com/faq/questions/apakah-konsultasi-via-panggilan-suara-dan-video-tersedia-di-halodoc | FAQ resmi — panggilan suara/video dari dalam chat, menu titik tiga, badge "Video Tersedia" | 2026-10-01 | Web |
| 5 | https://www.halodoc.com/faq/questions/mengapa-saya-tidak-bisa-membuka-atau-mengunduh-lampiran-dari-dokter-resep-digital-catatan-rekomendasi-istirahat-rekomendasi-tes-dan-lainnya | FAQ resmi — kegagalan membuka/mengunduh lampiran | 2026-10-01 | Web |
| 6 | https://www.halodoc.com/artikel/cara-chat-dokter-spesialis-online-panduan-lengkap | Artikel editorial resmi Halodoc — durasi sesi chat | 2026-10-01 | Web |
| 7 | https://www.antaranews.com/berita/4441149/cara-konsultasi-dokter-online-melalui-halodoc | Media independen (Antara) — langkah konsultasi | 2026-10-01 (artikel 4 Nov 2024) | Web |
| 8 | https://play.google.com/store/apps/details?id=com.linkdokter.halodoc.android&hl=en | Listing Google Play (deskripsi, tangkapan layar, ulasan, "Updated on Sep 30, 2026") | 2026-10-01 | Android |

**Batas akses:** aplikasi native tidak dipasang, tidak ada akun/login. Halaman FAQ resmi bersifat teks (tanpa tangkapan layar UI chat). Tangkapan layar aplikasi hanya terlihat di listing Google Play (#8). Klaim "iklan/ketersediaan" hanya dipakai bila ada URL di atas.

## Langkah terlihat (fakta)

1. Enam langkah resmi sampai chat dimulai (#3): pilih "Chat dengan Dokter" → cari dokter → pilih "Konsultasi Sekarang" → pilih profil pasien → selesaikan pembayaran → "Konsultasimu akan dimulai setelah kamu menyelesaikan pembayaran dan dokter menerima permintaanmu."
2. Ketersediaan dokter ditandai **titik hijau**; dokter video ditandai badge **"Video Tersedia"** (#3, #4).
3. Dari dalam chat, metode dapat ditukar lewat **ikon titik tiga di pojok kanan atas** → pilih panggilan suara/video (#4). Video bergantung pada ketersediaan dokter ("kemungkinan dokter sedang menangani pasien lain", #4).
4. Durasi sesi chat: "sekitar 30 menit sampai 1 jam per sesi, atau sampai dokter/pasien mengakhiri chat lebih dulu" (#6, artikel editorial).
5. Lampiran (resep digital, catatan dokter, rekomendasi istirahat/tes) gagal dibuka/diunduh → arahan resmi: "perbarui aplikasi Halodoc-mu hingga ke versi terbaru. Jika masih mengalami kendala, silakan hubungi CS" (#5).
6. Chat tidak bisa dimulai/masuk chatroom → arahan sama: perbarui aplikasi, lalu hubungi CS (#1).
7. Ulasan Google Play 2 Juni 2026 (#8, ulasan pengguna — bukan dokumentasi resmi): pesan "hanya menampilkan ikon jam dalam waktu yang cukup lama" → indikator status pengiriman berupa ikon jam ada di aplikasi, tetapi **hanya terverifikasi dari ulasan pengguna**.

## Hitungan (titik awal: membuka ruang konsultasi → satu pesan terkirim & diakui)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | N/V | |
| Field | N/V | |
| Ketukan | N/V | |

**N/V:** titik awal hitungan (di dalam ruang chat setelah dokter menerima) tidak terdokumentasi di sumber publik mana pun; 6 langkah resmi (#3) berangkat dari **halaman utama**, bukan dari ruang konsultasi, sehingga tidak dapat dipakai untuk hitungan bersama. Jumlah layar/ketukan sampai pesan terkirim **tidak terverifikasi**.

## State terlihat

- **Error masuk chat / lampiran:** pesan arahan jelas tetapi pemulihan manual eksternal (update app + CS) (#1, #5). ✓
- **Status pengiriman dalam chat:** ikon jam untuk pesan tertunda — hanya dari ulasan pengguna (#8) → lemah. ✓ sebagian
- **Loading/kosong/offline/reconnect dalam chat:** tidak terdokumentasi → N/V.
- **Status dokter:** titik hijau tersedia, badge video tersedia (#3, #4). ✓

## Red flag

- Tidak ditemukan hitung mundur palsu, tombol batal tersembunyi, atau upsell pada sumber yang diakses.
- **Kualitas pemulihan rendah sebagai pola:** satu-satunya arahan resmi untuk kegagalan chat adalah "perbarui aplikasi / hubungi CS" — pemulihan manual di luar aplikasi, tanpa tombol coba lagi. Jangan ditiru (lihat `scores/F08.md` K3).

## Yang TIDAK bisa diverifikasi

- Tampilan dalam chat: gelembung pesan, status terkirim/dibaca, indikator "sedang mengetik", lampiran dikirim dari UI (tidak ada dokumentasi/tangkapan layar resmi).
- Harga konsultasi tampil sebelum komitmen (tidak diambil dari sumber di atas → tidak dipakai dalam skor).
- Privasi notifikasi layar kunci / masking data medis.
- Aksesibilitas (target sentuh, kontras, pembaca layar).
- **Rubrik: K1 efisiensi, K4 aksesibilitas, K7 beban kognitif = N/V.**
