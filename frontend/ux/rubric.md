# Rubrik Penilaian UX (v0.1)

Dipakai untuk menilai tiap aplikasi di tiap flow. Skala 1-5 per kriteria.

## Aturan skoring
- Hanya nilai yang ada buktinya. Tanpa bukti = **N/V** (tidak terverifikasi), dikeluarkan dari perhitungan, bobot dinormalisasi ulang.
- Pemenang flow wajib punya: minimal 2 sumber bukti independen, dan >= 70% bobot terverifikasi.
- Skor selalu disertai satu kalimat alasan berbasis fakta yang terlihat (bukan "terasa bagus").
- Hitungan efisiensi (layar, ketukan, field) dilakukan dari titik awal yang sama untuk semua aplikasi di flow itu.

## Kriteria

| # | Kriteria | Bobot | Yang diukur | 1 (buruk) | 3 (cukup) | 5 (sangat baik) |
|---|---|---|---|---|---|---|
| 1 | Efisiensi tugas | 20% | Layar, ketukan, dan field sampai tugas inti selesai; data terisi otomatis; tidak ada langkah mubazir | Langkah dan field berlebih, input diulang | Wajar, ada sedikit pengulangan | Terpendek di antara kandidat, data diingat, satu tujuan per langkah |
| 2 | Kejelasan dan kepercayaan | 15% | Informasi untuk mengambil keputusan (harga, kredensial, jadwal, durasi, status) tampil sebelum diminta komitmen | Ada biaya/kondisi kejutan | Informasi ada tapi tersebar | Semua tampil di tempat yang tepat, status selalu jelas |
| 3 | Pencegahan dan pemulihan error | 15% | Validasi inline, pesan spesifik, slot bentrok, pembayaran gagal, koneksi putus, draft tersimpan | Error generik, data hilang | Pesan jelas tapi pemulihan manual | Dicegah di awal, pemulihan satu ketukan, tidak ada data hilang |
| 4 | Aksesibilitas dan keterbacaan | 15% | Kontras, ukuran teks, target sentuh >= 44 px, fokus terlihat, label, tidak bergantung warna, ramah lansia | Teks kecil, target sempit, warna saja | Sebagian besar memenuhi | Memenuhi WCAG AA secara konsisten, nyaman untuk lansia |
| 5 | Ketahanan konteks Indonesia | 10% | Jaringan lambat, Android menengah-bawah, hemat data, resume setelah putus, metode bayar lokal (QRIS, VA, e-wallet), bahasa natural, zona waktu WIB/WITA/WIT | Mengandaikan jaringan bagus, istilah asing | Sebagian diperhatikan | Dirancang untuk kondisi lapangan Indonesia |
| 6 | Privasi dan keamanan yang terlihat | 10% | Consent eksplisit, penjelasan penggunaan data, masking data sensitif, sesi wajar, notifikasi tidak membocorkan data medis | Consent terselip, data sensitif terbuka | Ada consent dasar | Transparan, kontrol ada di pengguna, selaras UU PDP |
| 7 | Beban kognitif dan konsistensi | 10% | Hierarki jelas, istilah medis dijelaskan, komponen konsisten, tidak ada kebisingan visual | Padat dan membingungkan | Cukup jelas | Tenang, hierarki tegas, istilah dijelaskan di tempatnya |
| 8 | Kelayakan di stack Sehatly | 5% | Bisa dibangun dengan shadcn/Radix/Tailwind tanpa library baru atau aset berlisensi | Butuh library/aset khusus | Butuh sedikit kustomisasi | Langsung bisa dengan komponen yang ada |

Untuk flow **dokter dan admin** (F13, F14), kriteria 5 diganti menjadi "Efisiensi pengguna berulang": shortcut, aksi massal, kepadatan informasi yang tetap terbaca.

## Red flag (diskualifikasi sebagai pola untuk ditiru)
- Dark pattern: hitung mundur palsu, tombol batal/tutup tersembunyi, consent terselip, upsell menutupi aksi utama.
- Izin kamera, mikrofon, atau lokasi diminta tanpa konteks dan alasan.
- Data medis tampil di notifikasi layar kunci.
- Biaya baru muncul setelah pengguna sudah berkomitmen.
- Informasi darurat/peringatan medis disembunyikan di balik beberapa ketukan.

## Skor akhir
`skor = jumlah(bobot_terverifikasi x nilai) / jumlah(bobot_terverifikasi)`, lalu laporkan juga persentase bobot yang terverifikasi sebagai tingkat keyakinan.
