# Pola Lintas Flow Sehatly (`_global.md`)

Status: draf | Tanggal: 2026-10-01 | Cakupan: F05 (booking), F06 (pembayaran), F08 (konsultasi), F09 (resep).

Dokumen ini adalah sumber kebenaran untuk hal-hal yang **dipakai bersama** oleh banyak flow.
Jika sebuah pola flow bertentangan dengan dokumen ini, dokumen ini menang kecuali disebut lain.
Semua keputusan di sini dapat diuji; AC/Playwright-nya mengikuti AC flow masing-masing.

> **Penting — `web/design-tokens.md` TIDAK ADA.** `web/AGENTS.md`, `web/opencode.json`, dan
> `web/ux/BENCHMARK_PROMPT.md` menyuruh membaca `web/design-tokens.md`, tetapi berkas itu
> tidak ada di repositori. Sumber token yang nyata adalah `web/src/styles/app.css`
> (`@theme` + `:root` + `.dark`). Lihat §6 usulan.

---

## 1. Pola navigasi

### 1.1 Kerangka layar
- **Pra-sesi** (`/`, `/login`, `/register`, `/otp`): `AuthLayout` — kartu terpusat, tanpa nav akun.
- **Sesi pasien/dokter/apoteker** (semua `/dashboard`, `/booking`, `/konsultasi`, `/pasien/resep`, dst.):
  `RequireAuth` → `AppShell` (sidebar di desktop, header + drawer di mobile). 26 leaf route,
  **setiap leaf wajib punya `errorElement`** (alasan panjang ada di `router.tsx`: error di level
  `AppShell` menghapus sidebar → F3-01).
- **Direktori dokter publik** (`/dokter`, `/dokter/:id`): di luar `AppShell` karena API-nya publik.

### 1.2 Aturan navigasi (berlaku semua flow)
1. **Satu tujuan per layar.** Jangan gabungkan dua tugas utama (mis. "pilih jadwal" dan "bayar").
2. **Tombol kembali selalu ada** di header layar yang punya induk (`← Booking saya`,
   `← Kembali ke direktori`). Label menyebut tujuan, bukan "Kembali" telanjang.
3. **Rute detail selalu punya jalur keluar** meski data gagal dimuat: `NotFoundState`/`ForbiddenState`
   harus menyediakan tautan kembali.
4. **Jangan menaruh id sensitif di URL** yang bisa terbaca: rute memakai id numerik resource,
   bukan nama/token/NIK. Token QR surat keterangan hanya di query verifier publik yang memang
   dirancang untuk itu.
5. **Perubahan status tidak mengubah rute.** Booking yang selesai tetap di `/booking`, konsultasi
   ditutup tetap di `/konsultasi/:id` (kecuali pasien memilih keluar).
6. **Sidebar menyembunyikan menu yang tidak relevan per `user.tipe`**, tetapi tidak pernah
   menyembunyikan cara keluar/logout.

### 1.3 Konflik navigasi
- Tidak ada konflik antar pola F05/F06/F08/F09; keempatnya memakai `AppShell` yang sama.
- **Utang lama:** `app-shell.tsx` masih menampilkan tautan generik yang tidak disesuaikan tipe.
  Catat sebagai perbaikan terpisah; jangan diubah di dalam sesi implementasi satu flow.

---

## 2. Glosarium istilah (satu istilah per konsep)

Dipilih dari istilah yang sudah dipakai enum/kode, lalu dinormalkan. **Jangan sinonimkan.**

| Konsep | Istilah resmi (UI) | Sinonim yang DILARANG | Rujukan kode |
|---|---|---|---|
| Ketersediaan mingguan dokter | **Jadwal dokter** | "schedule", "availability" | `dokter_jadwal` |
| Satu unit waktu yang bisa dipesan | **Slot** (atau "Jam tersedia" pada label pendek) | "jam", "waktu" tanpa konteks | `SlotAvailabilityService` |
| Janji temu terpesan | **Janji temu** (halaman: "Booking") | mencampur "booking"/"reservasi"/"appointment" | `booking` |
| Tindakan memesan | **Pesan jadwal** | "booking" sebagai kata kerja campur | `POST /booking` |
| Sesi konsultasi berjalan | **Konsultasi** | "chat", "sesi" (kecuali "ruang konsultasi") | `konsultasi` |
| Pesan dalam konsultasi | **Pesan** | "chat" sebagai nomina UI | `konsultasi_chat` |
| Obat yang diresepkan | **Resep** | "prescription" | `resep` |
| Zat aktif | **Obat** | "medicine", "drug" | `master_obat` |
| Aturan minum | **Aturan pakai** | "dosis" untuk jadwal minum | `resep_item.aturan_pakai` |
| Jumlah zat per satuan | **Kekuatan** / **Dosis** | "strength" | `resep_item.dosis` |
| Tagihan | **Tagihan** (di awal) / **Invoice** hanya di admin | "bill" | `invoice` |
| Pesanan obat | **Pesanan** | "order" | `pesanan_obat` |
| Status "belum dibayar" | **Menunggu pembayaran** | "pending", "unpaid" | enum `menunggu_pembayaran` |
| Status dibatalkan | **Dibatalkan** | "cancel", "cancelled" | semua enum `dibatalkan` |

**Aturan penulisan status:** selalu **teks + ikon + warna**, tidak pernah warna saja
(`booking-status-badge`, `resep-status-badge`, `status-badge` sudah begitu). Huruf awal kapital,
bukan KAPITAL SEMUA.

**Konflik istilah yang harus ditutup:** kode memakai kata Inggris (`booking`, `checkout`) di
docstring dan sebagian label pengembang. UI pengguna tetap Bahasa Indonesia; istilah Inggris
hanya boleh muncul di komentar kode, bukan di layar.

---

## 3. Pola umpan balik (feedback)

| Kejadian | Mekanisme | Alasan |
|---|---|---|
| Validasi field gagal (422 `errors`) | **Inline** di bawah field (`Field`, `FormErrorSummary`) | Pesan dekat sumber; tidak hilang saat user memperbaiki (NN/g, `F08` §2). |
| Aksi berhasil yang tidak mengubah halaman | **Toast `sonner`** singkat, maks 1 baris | Konfirmasi tanpa mengganggu. |
| Aksi berhasil yang mengubah status (booking, bayar, checkout) | **Kartu/Alert inline** persisten di halaman + tautan lanjut | Status harus terbaca ulang, bukan hilang dalam 3 detik (`F06` §2, `F05` §2). |
| Gagal jaringan / 5xx | **`ErrorState` inline + tombol "Coba lagi"** | Sudah ada; jangan toast error jaringan. |
| 401 sesi habis | Refresh single-flight → jika gagal, flash + redirect `/login` | Sudah ada di `http.ts` + `root-layout.tsx`. |
| Slot bentrok (422 `slot`) | `SlotTakenNotice` `role="alert"` di atas form, isi form dipertahankan | `F05` §2. |
| Koneksi realtime putus | **Strip status persisten** + tombol "Hubungkan ulang" | `F08` §2; memakai `role="status"` `aria-live="polite"`. |

**Aturan keras umpan balik:**
1. **Tidak ada data medis di toast, judul tab, atau URL** (nama obat, dosis, diagnosis, isi pesan).
   Toast sukses memakai kalimat generik ("Resep berhasil dibuat."), bukan isinya.
2. **Jangan toast untuk error validasi** — itu tugas inline.
3. **Satu toast per aksi.** Jangan menumpuk toast.
4. **Pesan error spesifik, bukan "Terjadi kesalahan".** Sebut apa yang salah dan langkah berikutnya.

---

## 4. Konfirmasi aksi berisiko

Aksi berisiko = menghapus data, membatalkan janji/pesanan, mengakhiri konsultasi, menolak resep,
menyimpan override kontraindikasi. Pola tunggal:

1. **Dialog** (`Dialog` shadcn) — bukan `window.confirm`, bukan toast.
2. **Judul menyebut akibat**, bukan pertanyaan kabur: "Batalkan janji temu?" — bukan "Anda yakin?".
3. **Isi menyatakan konsekuensi konkret**: biaya (jika ada), apakah bisa diulang, apakah slot
   dilepas, apakah data hilang.
4. **Tombol konfirmasi memakai kata kerja + objek** ("Batalkan janji temu", "Akhiri konsultasi",
   "Simpan tanpa catatan"), styling `destructive` untuk yang merusak.
5. **Tombol batal selalu terlihat** sebagai aksi sekunder di dialog yang sama; **tidak** boleh
   tombol X satu-satunya jalan keluar.
6. **Alasan opsional/wajib** bila kebijakan memerlukannya — pembatalan booking sudah punya dialog
   beralasan (`booking-list.tsx`); pertahankan.
7. **Fokus keyboard** masuk ke dialog dan kembali ke pemicu saat ditutup.

**Konflik yang diselesaikan:** `F05` (batalkan booking) sudah memakai `Dialog` + alasan;
`F06` (id invoice/dropout) dan `F09` (checkout) belum seragam. Semua memakai pola di atas.

**Aksi TIDAK boleh pakai dialog konfirmasi:** aksi aditif dan mudah dibalik (tandai dibaca,
salin nomor, buka lampiran). Terlalu banyak dialog melatih pengguna menekan "Ya" tanpa membaca.

---

## 5. Pola zona waktu dan format

- **Simpan UTC.** Timestamp instan (mis. `konsultasi.mulai_at`) disimpan/dikirim **UTC**. Jam
  dinding jadwal dokter (`dokter_jadwal.jam_mulai`, `booking.tanggal_kunjungan`) tetap Asia/Jakarta
  karena itu makna kolomnya.
- **Tampilkan dikonversi ke zona perangkat pengguna, selalu berlabel** (`WIB`/`WITA`/`WIT`), mis.
  "09.00 WIB". Waktu tanpa label = cacat. Label tidak boleh dihilangkan — zona perangkat bisa diset
  manual dan salah.
- **Bila zona perangkat ≠ zona jadwal dokter, tampilkan KEDUA zona di konfirmasi** (mis. "09.00 WIB
  (10.00 WITA)"). *(Keputusan pemilik 2026-10-01; menggantikan pertanyaan terbuka lama.)*
- **Format:** `04 Okt 2026` (tanggal), `09.30` (jam, titik pemisah gaya Indonesia), `Rp 45.000`.
  Angka uang pakai `tabular-nums`.
- **Durasi** ditulis satuan Indonesia: "15 menit", bukan "15 min".

---

## 6. Usulan perubahan token (AJUKAN, jangan ubah sendiri)

Aturan `web/AGENTS.md` mewajibkan token, tetapi sumber token yang dirujuknya tidak ada. Usulan:

1. **Buat `web/design-tokens.md`** sebagai dokumen kanonik yang memuat: daftar token dari
   `app.css` (`--background`, `--foreground`, `--card`, `--popover`, `--primary`, `--secondary`,
   `--muted`, `--accent`, `--destructive`, `--success`, `--warning`, `--border`, `--input`,
   `--ring`, `--radius` + turunannya `--radius-lg/md/sm`, `--chart-*`, `--sidebar-*`), font
   `'Instrument Sans'`, larangan hex/rgb, dan aturan pemakaian. **Alternatif** bila tidak ingin
   file baru: perbaiki `web/AGENTS.md` untuk menunjuk ke `web/src/styles/app.css`. Jangan lakukan
   keduanya tanpa persetujuan.
2. **Tidak ada token `--spacing-*` dan `--font-size-*`.** `AGENTS.md` menyebut "spacing wajib token"
   tetapi tidak ada token spacing; skala spacing adalah default Tailwind v4. Ajukan salah satu:
   (a) tambahkan skala spacing/teks sebagai token, atau (b) ubah `AGENTS.md` untuk menyatakan
   bahwa skala default Tailwind adalah token resmi. **Jangan** menambah token diam-diam.
3. **Ukuran teks data medis minimum.** Tidak ada token ukuran teks. Ajukan aturan tetap:
   data medis (nama obat, dosis, aturan pakai, waktu konsultasi) minimal **16 px** (`text-base`),
   dosis/aturan pakai disarankan **18 px** (`text-lg`), dan **dilarang** dipotong ellipsis.
   Ini dapat diuji tanpa token baru.
4. **Token `--info` belum ada.** Status "menunggu/dalam proses" saat ini memakai `--muted`/`--primary`.
   Ajukan evaluasi apakah perlu satu hue netral "info"; **jangan tambah** sampai disetujui
   (hanya satu warna aksen; warna lain hanya status).
5. **QR sebagai gambar.** Menampilkan `qr_string` sebagai gambar QR butuh pustaka baru. Dilarang
   menambah library UI tanpa izin. Ajukan keputusan: pakai teks kode (sekarang) atau setujui
   pustaka QR.

---

## 7. Konflik antar pola dan resolusinya

| # | Konflik | Sumber | Resolusi |
|---|---|---|---|
| 1 | **Offline belum ditangani di mana pun.** | `F05` §12 #6, `F06` §12 #7, `F15` di `flows.md` | **Diputuskan (2026-10-01): potongan tipis, bukan pekerjaan global.** Bangun: komponen state bersama (loading/kosong/error/coba lagi), sesi habis, banner "tidak ada koneksi", draf input tidak hilang, penanganan konflik slot. **Dilarang mengantre mutasi booking/pembayaran saat offline** (idempotensi/double charge) — deteksi, blokir dengan pesan jelas, jaga input. Antrean tulis penuh menyusul belakangan. |
| 2 | **Zona waktu:** konversi vs label WIB. | `F05` §12 #2 | **Diputuskan (2026-10-01):** simpan UTC, tampilkan dikonversi ke zona perangkat + label, tampilkan kedua zona bila berbeda (lihat §5). |
| 3 | **Lokasi aksi utama vs alat sekunder.** F06 menaruh promo di bawah instruksi (tegangan dengan Baymard); F09 menaruh band "tindakan berikutnya" di atas. | `F06` §2 langkah 8, `F09` §2 langkah 2 | Tetapkan aturan: **status + aksi utama selalu di atas**; alat sekunder (promo, filter, ekspor) **di bawah** konten utama, tidak menutupi CTA. |
| 4 | **Kop produksi vs copy debug.** | `F08` §12 #3 | Aturan global: **UI tidak boleh memuat nama endpoint/istilah developer.** **F08 selesai 2026-10-01** (statistik realtime, `private-konsultasi...`, `KonsultasiChannelAccess`, placeholder "Patienten" dihapus; atribut `data-*` untuk tes dipertahankan). Sisa: nama endpoint di `PageHeader description` ~15 halaman lain — sapu di sesi terpisah. |
| 5 | **Label tombol.** Kode memakai `booking`/`checkout`; pola memakai kata kerja Indonesia. | `F05`, `F06`, `F09` | Semua CTA primer Bahasa Indonesia, kata kerja + objek ("Lanjut ke pemesanan", "Bayar sekarang", "Kirim booking"). |
| 6 | **Akhiri sesi F08.** | `F08` §12 #1 | **Diputuskan (2026-10-01):** pasien memakai **"Keluar dari sesi" + konfirmasi** (tanpa mengubah status konsultasi); **yang mengakhiri konsultasi tetap dokter**; transisi status ditegakkan server; refund ditangani F12. |
| 7 | **Data hilang saat error.** F04/F05/F06/F08 sama-sama mensyaratkan input tidak hilang; F06 menambah invoice id manual yang rawan salah. | `F06` §2 langkah 2, `F05` §2 | Aturan global: **draft dipertahankan** pada 422/5xx; field manual diberi validasi inline + tidak dikosongkan saat error. |
| 8 | **Bahasa istilah Inggris di enum.** `dipenuhi`, `dikirim` (resep) vs `menunggu_pembayaran` (booking) — campuran. | enum `docs/enums.json` | Glosarium §2 memetakan label Indonesia; **jangan** mengubah nilai enum, hanya label tampilannya. |

---

## 8. Definisi "selesai" lintas flow (Definition of Done UX)

Setiap implementasi flow wajib memenuhi, di luar AC spesifik flow:

1. Tiga state inti ada: **loading** (skeleton `SkeletonRows`, bukan spinner layar penuh),
   **kosong** (`EmptyState` dengan tindakan lanjut), **error** (`ErrorState` + "Coba lagi" hanya
   untuk retryable; `ForbiddenState`/`NotFoundState` untuk sisanya).
2. **Offline** ditangani sesuai §7 #1 (setelah diputuskan).
3. **Target sentuh ≥ 44 px**, kontras ≥ 4,5:1, fokus keyboard terlihat, semua input berlabel —
   divalidasi otomatis dengan `@axe-core/playwright` di AC (menunggu persetujuan dependensi).
4. **Status = teks + ikon + warna**, tidak pernah warna saja.
5. **Tidak ada data sensitif** di toast/URL/judul tab/notifikasi.
6. **Copy Bahasa Indonesia** tenang dan jelas; istilah mengikuti §2; tanpa lorem ipsum; data
   realistis (nama dokter/obat Indonesia).
7. **Tidak ada penambahan library** dan **tidak ada perubahan token** tanpa persetujuan.
8. Dokumentasi visual: screenshot 390 px & 1280 px, `npm run types:check`, `npm run test:unit`,
   dan `npm run test:e2e` untuk alur kritis.

---

## 9. Pertanyaan terbuka lintas flow

1. ~~`web/design-tokens.md` dibuat atau `AGENTS.md` diperbaiki?~~ **DIPUTUSKAN (2026-10-01):** `app.css` adalah satu-satunya sumber token; `AGENTS.md` + `opencode.json` sudah diarahkan; audit ada di `web/ux/app-css-audit.md`.
2. `_global.md` masih **draf** — mohon persetujuan sebelum implementasi flow pertama.
3. ~~Banner offline global sebelum F05/F06?~~ **DIPUTUSKAN:** potongan tipis saja (lihat §7 #1).
4. ~~Zona waktu dikonversi atau WIB berlabel?~~ **DIPUTUSKAN:** konversi + label; tampilkan ganda bila beda (lihat §5).
5. **Terbuka:** apakah `--info` dan skala spacing/ukuran teks ditambahkan sebagai token, atau aturan diperlonggar di `AGENTS.md`? (usulan §6)
6. **Terbuka:** persetujuan `@axe-core/playwright`; lokasi pasti "Fase 4B" (audit double-booking).
