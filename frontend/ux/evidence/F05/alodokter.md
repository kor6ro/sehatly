# F05 Bukti: Alodokter (alodokter.com)

Tanggal akses semua sumber: **2026-10-01**. Alur pengamatan: **web publik, browser sungguhan (Playwright/Chromium), tanpa akun, tanpa login, tanpa submit**.

## Sumber

| # | URL | Tanggal | Jenis sumber |
|---|---|---|---|
| A1 | https://www.alodokter.com/cari-dokter/dr-etty-aminah-sppd | 2026-10-01 | Public web — profil dokter, diamati langsung |
| A2 | https://www.alodokter.com/cari-dokter | 2026-10-01 | Public web — indeks buat janji, diamati langsung |
| A3 | https://www.alodokter.com/konsultasi-dokter | 2026-10-01 | Public web — halaman konsultasi online, diamati langsung |
| A4 | https://www.alodokter.com/syarat-dan-ketentuan | "Terakhir diperbarui: 26 Agustus 2026" | Public web — S&K resmi (alur buat janji, reschedule, notifikasi) |
| A5 | https://play.google.com/store/apps/details?id=com.alodokter.android&hl=en | 2026-10-01 | Store listing — "Updated on Sep 23, 2026" |
| A6 | https://apps.apple.com/id/app/alodokter-chat-bersama-dokter/id1405482962 | 2026-10-01 | Store listing — v8.9.0 (±2026-09-29), v8.8.0 (27 Agu), v8.7.0 (20 Jul), v8.6.0 (18 Jun) |
| A7 | https://www.reddit.com/r/Perempuan/comments/1v2dl1h/nanya_soal_booking_sesi_offline_konseling/ | 2026-07-21 | Forum pengguna — anekdot (opini, bukan fakta produk) |

**Masih aktif 2026:** ya. Play Store "Updated on Sep 23, 2026" (A5, diakses 2026-10-01), riwayat versi App Store Juni–September 2026 (A6), S&K diperbarui 26 Agustus 2026 (A4). **Aplikasi native tidak diamati**; store listing hanya bukti aktivitas.

## Langkah yang terlihat

**Titik awal sama: pengguna di profil dokter dan ingin memesan slot.**

Alur A — "Buat Janji" tatap muka (diamati langsung, web, 2026-10-01):

1. **Layar — profil dokter** (A1): nama & spesialisasi, rating **"100% / 9 pasien"**, bukti sosial **"95 pasien telah buat janji dengan dokter ini"**, "Profil Dokter", dan blok **"Lokasi & Jadwal Praktik"**.
2. **Kartu per faskes** (contoh 2 faskes): nama RS + biaya (RS Ananda Bekasi Rp230.000; Mitra Keluarga Bekasi Barat Rp325.000), tab tanggal (Jumat 02 Oktober 2026 "(Besok)" / Senin 05 Okt / Rabu 07 Okt), pita jam + rentang (Pagi 09:30–11:30; Siang 15:00–17:00), tombol **"Buat Janji"**, tautan **"Pilih Tanggal"** dan **"Lihat Jadwal"**.
3. **Aksi — ketuk "Buat Janji". Hasil:** panel **gerbang nomor ponsel inline** menggantikan kartu: **"Masukkan Nomor Ponsel / Masukkan nomor ponsel Anda untuk melakukan buat janji melalui Alodokter."**, kotak isian **"Nomor Ponsel Anda"**, tombol **"Selanjutnya" (nonaktif)**. Di bawah halaman juga ada bagian terpisah "Buat Janji Konsultasi" ("Tindakan Medis: Konsultasi Penyakit Dalam").
4. **BERHENTI — gerbang nomor ponsel.** Tidak ada data dikirim, booking tidak pernah dikonfirmasi.

Alur B — chat dengan dokter (diamati): halaman `/konsultasi-dokter` (A3) **tidak menawarkan chat di web**; satu-satunya aksi adalah tautan unduh Google Play / App Store ("Konsultasi Dokter Langsung di **Aplikasi** Alodokter") → chat online **gated ke aplikasi**.

Alur reschedule (didokumentasikan di S&K A4, 2026-08-26): Inbox (ikon pesan) → pilih janji → "Atur Ulang Buat Janji" → "Jadwal Lain". Reschedule **maksimal 1×**, tanggal baru dalam 7 hari; wajib bawa "Surat Konfirmasi" + KTP/SIM/Paspor; pemberitahuan perubahan jadwal dikirim via **WhatsApp** ke nomor yang didaftarkan saat booking.

## Hitungan

Titik awal: **pengguna di profil dokter, ingin memesan slot**, sampai tugas inti (booking terkonfirmasi) — **konfirmasi tidak pernah tercapai** (gerbang nomor ponsel).

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar sampai berhenti | **2** (profil dokter → gerbang telepon inline; gerbang = panel di URL yang sama) | diamati 2026-10-01 |
| Ketukan esensial sampai berhenti | **1** ("Buat Janji"; slot sudah terpilih di kartu faskes); ganti tanggal +1 | diamati |
| Field terlihat sebelum konfirmasi | **1** (nomor ponsel; "Selanjutnya" nonaktif sampai terisi) | diamati |
| Layar/ketukan sampai konfirmasi penuh | **TIDAK TERVERIFIKASI** (di balik gerbang); S&K (A4) menyebut konfirmasi berupa **"Surat Konfirmasi"** | dokumen resmi |
| Baris consent di layar gerbang pertama | **tidak ada** (diamati) | diamati |

## State yang terlihat

| State | Status | Bukti |
|---|---|---|
| Loading | **TIDAK TERVERIFIKASI** | komponen web terender tanpa state loading terekam |
| Kosong (tidak ada ketersediaan) | **TIDAK TERVERIFIKASI** | semua hari yang diamati punya slot |
| Error | **TIDAK TERVERIFIKASI** | tidak dipicu |
| Disabled/panduan | **DIAMATI** | "Selanjutnya" nonaktif sampai nomor ponsel diisi |
| Sukses | **TERDOKUMENTASI, tidak diamati** | S&K A4: "Surat Konfirmasi"; klaim Play Store "kepastian jadwal dokter dan estimasi biaya" (A5) |
| Offline | **TIDAK TERVERIFIKASI** | — |

## Red flag

1. **Aksi utama web (chat) ditutupi funnel unduh aplikasi — diamati.** Satu-satunya aksi halaman `/konsultasi-dokter` adalah tautan toko aplikasi (A3, 2026-10-01). *Opini: gesekan bagi pengguna desktop; bukan pola menipu.*
2. **Tidak ada baris consent di gerbang pertama — diamati.** Tidak terlihat teks persetujuan Syarat/Privasi di layar nomor ponsel (A1). Kehadiran consent di layar berikutnya: TIDAK TERVERIFIKASI. *Untuk UU PDP, ini celah bukti, bukan bukti pelanggaran.*
3. **Keandalan slot — anekdot, bukan fakta.** Satu laporan pengguna (A7, 2026-07-21): slot yang dibooking lalu tidak tersedia dan jadwal dokter hilang via email. **Satu sumber, opini** → dipakai sebagai hipotesis, bukan dasar skor.
4. **Notifikasi jadwal via WhatsApp (didokumentasikan A4):** ruang lingkup datanya (apakah berisi detail medis) **TIDAK TERVERIFIKASI**.
5. **TIDAK ditemukan:** hitung mundur palsu, tombol batal tersembunyi (aturan batal/reschedule justru dipublikasikan di S&K), upsell menutupi aksi utama.

**Kesimpulan red flag:** tidak ada red flag daftar-keras rubrik yang terbukti → **Alodokter layak jadi sumber pola** (dengan catatan #2).

## Yang TIDAK bisa diverifikasi

- Semua layar **aplikasi native** (Buat Janji + Chat Bersama Dokter).
- **Semua langkah setelah gerbang:** form data pasien, verifikasi asuransi, pembayaran, layar konfirmasi, UI batal.
- Alur chat web (tidak ada; gated aplikasi).
- State loading, kosong, error, offline.
- Apakah slot ditahan/garansi saat konfirmasi (hanya anekdot A7).
- Consent di layar setelah nomor ponsel.
