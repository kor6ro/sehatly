# F05 Bukti: Halodoc (halodoc.com)

Tanggal akses semua sumber: **2026-10-01**. Alur pengamatan: **web publik, browser sungguhan (Playwright/Chromium), tanpa akun, tanpa login, tanpa submit**.

## Sumber

| # | URL | Tanggal | Jenis sumber |
|---|---|---|---|
| H1 | https://www.halodoc.com/cari-dokter/nama/dr-h-edwin-setiabudi-sp-pd-kkv-finasim | 2026-10-01 | Public web — halaman profil dokter, diamati langsung |
| H2 | https://www.halodoc.com/faq/questions/bagaimana-cara-membuat-janji-offline-di-halodoc | 2026-10-01 | Public web — artikel bantuan resmi |
| H3 | https://www.halodoc.com/faq/questions/bagaimana-cara-menjadwalkan-konsultasi-online | 2026-10-01 | Public web — artikel bantuan resmi |
| H4 | https://www.halodoc.com/faq/questions/bagaimana-cara-saya-konsultasi-online-di-halodoc | 2026-10-01 | Public web — artikel bantuan resmi |
| H5 | https://play.google.com/store/apps/details?id=com.linkdokter.halodoc.android&hl=en | 2026-10-01 | Store listing (Play Store) — "Updated on Sep 30, 2026" |
| H6 | https://apps.apple.com/id/app/halodoc-dokter-obat-lab/id1067217981?l=id | 2026-10-01 | Store listing (App Store) — riwayat versi v32.700 (16 Sep) … v32.800 (±2026-09-30) |
| H7 | https://www.antaranews.com/berita/5582099/rayakan-1-dekade-halodoc-komitmen-perkuat-ekosistem-kesehatan-digital | 2026-05-25 | Berita (ANTARA) |
| H8 | https://finansial.bisnis.com/read/20260728/89/1991618/halodoc-for-business-permudah-pengelolaan-kesehatan-karyawan | 2026-07-28 | Berita (Bisnis.com) |
| R1 | `web/ux/refs/halodoc/f05/halodoc-login-wall.png` | 2026-10-01 | Screenshot sesi pengamatan (dinding login nomor ponsel) |

**Masih aktif 2026:** ya. Play Store "Updated on Sep 30, 2026" (H5, diakses 2026-10-01), riwayat versi App Store September–Oktober 2026 (H6), berita Mei & Juli 2026 (H7, H8). Platform: web publik + iOS/Android. **Aplikasi native tidak diamati** (tanpa perangkat/akun); store listing hanya dipakai sebagai bukti aktivitas dan klaim fitur.

## Langkah yang terlihat

**Titik awal sama untuk semua aplikasi: pengguna berada di profil dokter dan ingin memesan slot.**

Alur A — buat janji tatap muka (diamati langsung, web, 2026-10-01):

1. **Layar — profil dokter** (H1): nama, spesialisasi, pengalaman, lencana **"Slot Guarantee"**, faskes (RS Immanuel), lokasi, **"Bayar di rumah sakit"**, biaya **Rp 310.000**, blok "Pilih tanggal dan waktu kunjungan".
2. **Aksi — pilih tanggal** lewat chip tanggal (Besok 2 Okt / Sabtu 3 Okt / Senin 5 Okt / Selasa 6 Okt / Jumat 9 Okt) lewat tombol "Pilih". **Hasil:** pita jam "Pagi" dengan slot 08:00 / 09:00 / 10:00.
3. **Aksi — ketuk slot jam** (08:00). **Hasil:** tombol **"Buat Janji" berpindah dari nonaktif → aktif**; ringkasan pilihan muncul: "Jumat 2 Oktober • 08:00" + nama dokter. Sebelum slot dipilih, tombol nonaktif dengan petunjuk inline **"Pilih slot waktu utk melanjutkan"**.
4. **Aksi — ketuk "Buat Janji". Hasil:** modal **dinding login**: "Masukkan Nomor Ponsel / Masukkan nomor ponsel untuk masuk ke Halodoc atau membuat akun baru", pemilih kode negara + kotak nomor ponsel + tombol **"Lanjut" (nonaktif)**; baris consent: *"Dengan masuk atau mendaftar, saya menyetujui Ketentuan Penggunaan Halodoc dan Kebijakan Privasi Halodoc."* (screenshot R1).
5. **BERHENTI — dinding login nomor ponsel.** Tidak ada data dikirim, booking tidak pernah dikonfirmasi.

Alur B — konsultasi online / terjadwal (didokumentasikan di FAQ resmi, tidak diamati langsung):

- Langsung: halaman utama → "Chat dengan Dokter" → pilih dokter → "Konsultasi Sekarang" → pilih profil pasien → pembayaran → konsultasi dimulai (6 langkah; H4).
- Terjadwal: "Chat dengan Dokter" → pilih dokter → "Jadwalkan" → pilih tanggal & jam → pilih profil pasien → pembayaran → **"Jadwal konsultasi berhasil dibuat"**, jendela hadir 10 menit (7 langkah; H3).
- Buat janji offline (H2): 6 langkah, termasuk pilih metode pembayaran "selesaikan pembayaran" dan konfirmasi ≤6 jam sebelum slot.

## Hitungan

Titik awal: **pengguna di profil dokter, ingin memesan slot**, sampai tugas inti (booking terkonfirmasi) — **konfirmasi tidak pernah tercapai** dalam pengamatan (dinding login). Angka di bawah = jarak yang benar-benar diamati + sisa langkah dari FAQ resmi.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar sampai berhenti | **2** (profil dokter → modal login) | diamati 2026-10-01 |
| Ketukan esensial sampai berhenti | **2** (pilih slot → "Buat Janji"); ganti tanggal +1–2 | diamati |
| Field terlihat sebelum konfirmasi | **2** (kode negara + nomor ponsel; "Lanjut" nonaktif sampai valid) | diamati |
| Layar/ketukan sampai konfirmasi penuh | **TIDAK TERVERIFIKASI** (di balik login); FAQ H2 menyebut **6 langkah** selesai | dokumen resmi |
| Status awal booking setelah sukses | `menunggu_pembayaran` (konsultasi online); janji offline: bayar di faskes (H1) vs "selesaikan pembayaran" di FAQ (H2) — **kontradiksi teramati** | dokumen resmi |

## State yang terlihat

| State | Status | Bukti |
|---|---|---|
| Loading | **TIDAK TERVERIFIKASI** | halaman terender tanpa indikator loading terekam |
| Kosong (tidak ada ketersediaan) | **TIDAK TERVERIFIKASI** | tidak ada hari tanpa slot yang diamati |
| Error | **TIDAK TERVERIFIKASI** | tidak dipicu |
| Disabled/panduan | **DIAMATI** | "Buat Janji" nonaktif + petunjuk "Pilih slot waktu utk melanjutkan"; field telepon + "Lanjut" nonaktif |
| Sukses | **TERDOKUMENTASI, tidak diamati** | FAQ H2: "Janji temu kamu berhasil dibuat" (konfirmasi ≤6 jam sebelum slot); H3: "Jadwal konsultasi berhasil dibuat" |
| Offline | **TIDAK TERVERIFIKASI** | — |

## Red flag

1. **Consent menyatu di gerbang auth (teramati).** Consent ke Syarat Penggunaan **dan** Kebijakan Privasi disajikan sebagai teks pasif di bawah dialog login nomor ponsel, bukan opt-in tersendiri (R1, 2026-10-01). Untuk layanan kesehatan, ini menengah: terlihat, tapi tidak eksplisit.
2. **Kontradiksi biaya (teramati).** Profil menyatakan **"Bayar di rumah sakit"** (H1), sedangkan FAQ alur janji offline yang sama menyuruh **"selesaikan pembayaran"** di aplikasi (H2). Keduanya diakses 2026-10-01. Perilaku sebenarnya di balik login: TIDAK TERVERIFIKASI.
3. **TIDAK ditemukan:** hitung mundur palsu, tombol batal tersembunyi, upsell menutupi aksi utama. Kebijakan pembatalan & pengembalian dana justru **tampil di halaman profil** (diamati, H1).

**Kesimpulan red flag:** pelanggaran rubrik yang terverifikasi = consent menyatu (kategori "consent terselip", ringan) + kontradiksi biaya. **Halodoc TIDAK didiskualifikasi sebagai sumber pola** (tidak ada red flag daftar-keras rubrik yang terbukti: hitung mundur palsu / batal tersembunyi / upsell menutupi aksi utama / biaya baru setelah komitmen).

## Yang TIDAK bisa diverifikasi

- Semua layar **aplikasi native** (iOS/Android).
- **Semua langkah setelah login:** form data pasien, asuransi, metode bayar, layar konfirmasi akhir, UI batal, penampilan notifikasi (apakah berisi data medis).
- Pengalaman "Chat dengan Dokter" web setelah login.
- State loading, kosong, error, offline (tidak dipicu/diamati).
- Apakah halaman profil menampilkan **sisa kuota slot** atau kebijakan batal untuk janji offline (hanya kebijakan umum yang terlihat).
