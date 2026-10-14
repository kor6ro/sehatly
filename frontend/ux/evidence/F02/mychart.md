# F02 — Epic MyChart (berbagi data kesehatan & akses aplikasi pihak ketiga)

Flow: pasien diminta menyetujui pemrosesan data kesehatan → keputusan tercatat dan bisa ditarik.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; hanya bantuan resmi,
halaman fitur, dan store listing.

## Sumber

| # | Sumber | Jenis | Versi/platform terlihat |
|---|---|---|---|
| S1 | https://www.mychart.org/l/en-us/help/app-access-grant/ [akses 2026-10-01] | Bantuan resmi MyChart/Epic | — |
| S2 | https://www.mychart.org/l/en-us/help/app-access-deny-revoke/ [akses 2026-10-01] | Bantuan resmi MyChart/Epic | — |
| S3 | https://www.mychart.org/l/en-us/help/proxy/ [akses 2026-10-01] | Bantuan resmi MyChart/Epic | kebijakan per organisasi kesehatan |
| S4 | https://www.mychart.org/l/en-us/features/share/ [akses 2026-10-01] | Halaman fitur resmi | — |
| S5 | https://shareeverywhere.epic.com/faq [akses 2026-10-01] | FAQ resmi Epic (Share Everywhere) | — |
| S6 | https://www.epic.com/careeverywhere/ [akses 2026-10-01] | Halaman produk resmi Epic | 2026 |
| S7 | https://apps.apple.com/us/app/mychart/id382952264 [akses 2026-10-01] | Store listing Apple | v**11.9.3** (21 Sep); 4,6★ / 721 rb; iOS/iPadOS **18.0+**; usia 16+ |
| S8 | https://play.google.com/store/apps/details?id=epic.mychart.android [akses 2026-10-01] | Store listing Google Play | **diperbarui 17 Sep 2026**; 10 jt+ unduhan; data terenkripsi transit; tidak ada data dibagikan ke pihak ketiga; bisa minta hapus |
| S9 | https://www.mychart.org/l/en-us/help/ [akses 2026-10-01] | Indeks bantuan resmi | 2026 |

- **Kandidat masih aktif di 2026**: Play diperbarui 17 Sep 2026 [S8]; App Store v11.9.3
  (Sep) [S7].
- **Ketergantungan konfigurasi (kutipan resmi)**: "what you can see and do within the MyChart
  app depends on which features your healthcare organization has enabled and whether they're
  using the latest version of Epic software" [S7]; "Each healthcare organization sets their
  own policies around proxy access" [S3].
- **Batasan riset**: portal di balik login; panduan berasal dari vendor + organisasi.

## Langkah terlihat (fakta)

**A. Izin aplikasi pihak ketiga (explicit opt-in)** [S1]:
1. Mulai berbagi dari aplikasi yang meminta akses (MyChart) → masuk (log in).
2. Pilih **whose information** akan dibagikan.
3. **"Review how the app uses the information"** — layar tinjauan cara pakai data.
4. **"Choose which types of information to share and how long the app has access"** —
   pilih jenis data **dan durasi** akses.
5. **"Confirm you want to share"** — konfirmasi eksplisit.
- Artinya: persetujuan = (i) tinjauan, (ii) pilihan per jenis + masa berlaku, (iii) konfirmasi.

**B. Penarikan / penolakan** [S2]:
- **Revoke**: MyChart → **Linked Apps and Devices** → **Stop sharing**; setelah itu aplikasi
  berhenti menerima info baru, tetapi "the app might still have information it already
  received" (hapus di aplikasi tersebut).
- **Deny vs revoke**: menolak = aplikasi tidak melihat apa pun dan izin bisa diberikan lagi;
  revoke = menghentikan akses yang sudah ada. **Pemberian ulang (re-grant) dimungkinkan.**

**C. Akses keluarga/orang lain (proxy)** [S3]:
1. Sharing Hub → **Manage friends and family access** → **Invite friends or family**.
2. Penerima menerima undangan dan **mengonfirmasi bahwa ia mengenal Anda**, lalu masuk ke
   akunnya sendiri.
3. Sebagian organisasi mensyaratkan **formulir kertas** (kebijakan masing-masing).
4. Penghapusan/berhenti sharing tersedia di layar akses yang sama [S3].

**D. Bagikan sementara ke penyedia (Share Everywhere)** [S4][S5]:
- Kode sekali pakai, **berlaku sampai dipakai, maksimal 60 menit**; penerima wajib tahu
  **tanggal lahir** pasien; 3 kali salah TGL lahir → kode batal; dilindungi reCAPTCHA;
  akses berakhir saat keluar [S5].
- Catatan legal di situs MyChart: "local laws [may] require you to sign a consent form before
  your records can be shared between organizations" [S4].

**E. Halaman Care Everywhere (Epic)** [S6] hanya direktori penyedia/statistik — **tidak**
memuat deskripsi UI persetujuan pasien.

## Hitungan

Titik awal: **pasien sudah masuk, diminta menyetujui pemrosesan data kesehatan** → tugas
inti: **satu keputusan tercatat dan bisa ditarik**.

| Skenario | Layar/langkah | Ketukan | Field |
|---|---|---|---|
| Memberi izin aplikasi (opt-in) | 5 (masuk → pilih subjek → tinjau cara pakai → pilih jenis+durasi → konfirmasi) [S1] | ~5 | 0–2 (pilihan jenis & durasi) |
| Menarik izin (Stop sharing) | 3 (Linked Apps and Devices → Stop sharing) [S2] | 3 | 0 |
| Menolak lalu memberi ulang | layar yang sama [S2] | 3 | 0 |
| Undang anggota keluarga (proxy) | 3 sisi pasien (Sharing Hub → Manage → Invite) [S3] | 3 | 1 (undangan) |
| Verifikasi penerima proxy | 2 sisi penerima (konfirmasi kenal + masukkan TGL lahir) [S3] | 2 | 1 (tanggal lahir) |

Dihitung dari langkah terdokumentasi (bukan pengamatan layar).

## State terlihat

- **TIDAK TERVERIFIKASI**: loading, kosong, error, sukses, offline layar Sharing/izin (tidak
  ada dokumentasi publik state-state itu).
- Terdokumentasi (bukan state layar): **deny** vs **revoke** sebagai dua kondisi berbeda +
  kemampuan re-grant [S2]; kode kedaluwarsa & pembatalan setelah salah TGL lahir [S5];
  disclaimer "app might still have information it already received" [S2].

## Red flag

- **Tidak ditemukan dark pattern** pada sumber resmi: pilihan per jenis + durasi, konfirmasi
  eksplisit, jalan tarik jelas, tidak ada consent wall yang terlihat.
- Dicatat (bukan diskualifikasi): keragaman antar-faskes ("paper form", kebijakan proxy per
  organisasi) [S3][S7] — sumber kebingungan, bukan tipuan; dan data yang sudah diterima app
  pihak ketiga tidak otomatis hilang saat revoke [S2].

## Yang TIDAK bisa diverifikasi

1. Seluruh layar dalam aplikasi (urutan tap, tata letak, jumlah layar persis).
2. Rating Play Store (konten listing yang di-fetch tidak menampilkan bintang) → **TIDAK
   TERVERIFIKASI** (hanya "Rated for 3+" dan 10 jt+ unduhan).
3. Kelengkapan/keseragaman lintas faskes (ketergantungan konfigurasi resmi) [S7].
4. UI persetujuan Care Everywhere di sisi pasien (halaman Epic [S6] tidak memuatnya; catatan
  rilis aplikasi yang menyebut "opt in … card" hanya terbaca lewat highlight pencarian →
   tidak dipakai sebagai bukti).
5. Naskah persis layar "Review how the app uses the information" (kutipan dari halaman bantuan).
