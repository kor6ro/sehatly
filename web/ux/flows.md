# Matriks Flow Sehatly dan Kandidat Benchmark (v0.2)

Kolom "Status di Sehatly" diisi pada Fase 0 dari KODE NYATA (bukan ingatan):
`routes/api.php` (74 route `/api/v1`), `web/src/app/router.tsx` (26 leaf route),
`web/src/pages/*`, `web/src/features/*`, `routes/channels.php`.

**Peringatan:** kolom kandidat berasal dari pengetahuan umum dan bisa usang.
Aplikasi bisa sudah tutup, berganti nama, atau berubah total. Agent WAJIB
memverifikasi bahwa tiap kandidat masih aktif dan bisa dipelajari secara legal
sebelum dipakai. Kandidat boleh ditambah atau dibuang berdasarkan bukti.

**Catatan batas Fase 0:** status di bawah = "apa yang ada di kode saat ini",
bukan "apakah alurnya bagus". Beberapa alur punya UI lengkap tetapi diblokir
endpoint backend yang belum ada (mis. faktur). Yang seperti itu ditandai di
kolom "Gap utama" sebagai **dependensi backend**, bukan kekurangan UX.

**Koreksi 2026-10-01:** klaim lama "route `.../slot` belum terdaftar" **SALAH**.
Diukur langsung: `GET /api/v1/dokter/5/slot?tanggal=2026-10-08` menjawab **HTTP 200**
(16 slot, `timezone: Asia/Jakarta`), dan `route:list` menampilkan `dokter.jadwal`
dan `dokter.slot`. Yang benar: fallback di `slot-picker.tsx`/`jadwal.ts` adalah
**kode mati ber-docstring usang**, dan sebagian besar dokter seed tidak punya
`dokter_jadwal` (data, bukan endpoint). Rincian di `report.md` §3.

## Cara membaca status

- **ada** — layar ada, endpoint ada, alur inti dapat diselesaikan.
- **sebagian** — sebagian layar/endpoint ada, ada lubang yang terlihat.
- **belum** — tidak ada UI (dan/atau tidak ada endpoint).
- **Realtime** — hanya ada satu kanal Reverb di seluruh produk: `konsultasi.{id}`
  (event `chat.pesan`). Semua alur lain REST/polling. Ditulis "tidak ada" bila
  tidak memakai Reverb.

## Tabel status (Fase 0)

| ID | Flow | Peran | Status di Sehatly | Endpoint backend pendukung | Realtime (Reverb) | Gap utama |
|---|---|---|---|---|---|---|
| F01 | Daftar dan login (OTP) | Pasien | ada | `POST /auth/register`, `POST /auth/login`, `POST /auth/otp/verify`, `POST /auth/refresh`, `POST /auth/logout`, `GET/POST /auth/devices`, `DELETE /auth/devices/{deviceId}` | tidak ada | Tidak ada tombol "kirim ulang kode" (kembali ke login); tidak ada biometrik. OTP 6 digit, TTL 5 menit, sekali pakai. |
| F02 | Consent dan privasi data | Semua | **belum** | `POST /pdp/persetujuan`, `GET /pdp/persetujuan` (5 slot consent, berversi) | tidak ada | API lengkap; **UI belum ada sama sekali** (tidak ada layar consent, penarikan, atau kebijakan privasi). |
| F03 | Cari dokter dan filter | Pasien | ada | `GET /dokter` (search, spesialisasi, tipe, tersedia_telemedisin), `GET /master-spesialisasi`, `GET /referensi/spesialisasi` | tidak ada | Tidak ada sort yang dipilih pengguna; tidak ada filter faskes/geografi. |
| F04 | Profil dokter dan kepercayaan | Pasien | ada | `GET /dokter/{dokter}`, `GET /dokter/{dokter}/jadwal`, `GET /dokter/{dokter}/slot` | tidak ada | Tidak ada daftar ulasan (model `ulasan_dokter` ada, endpoint tidak); jadwal tidak tampil di profil (baru di halaman booking). |
| F05 | Pilih jadwal dan booking | Pasien | sebagian | `GET /dokter/{dokter}/jadwal`, `GET /dokter/{dokter}/slot`, `POST /booking`, `GET /pasien/booking`, `GET /dokter/booking`, `PUT /booking/{id}/batalkan` | tidak ada | Endpoint `.../slot` **terdaftar dan menjawab 200** (diverifikasi 2026-10-01); **fallback kode mati + docstring usang sudah dihapus** (Sesi 3). Waktu slot kini ditampilkan dengan label zona; tombol kirim dinonaktifkan saat luring (F15 tipis). Gap nyata: sebagian dokter seed tidak punya `dokter_jadwal` (data); `nomor_antrian` tidak pernah diisi; tidak ada jadwal ulang (F12). |
| F06 | Pembayaran | Pasien | sebagian | `POST /invoice/{id}/bayar`, **`GET /invoice/{id}` (baru, 2026-10-01)**, `POST /webhook/payment/{gateway}`, `POST /promo/validasi`, `GET /referensi/metode-pembayaran` | tidak ada | **`GET /invoice/{id}` sudah ada**, dengan `InvoicePolicy` + tes Pest IDOR (pasien B tidak bisa membuka invoice pasien A). Sisa gap: checkout masih tidak mengembalikan `invoice_id` dan tidak ada daftar invoice/pesanan → UI masih harus menemukan id-nya. Status pembayaran kini dapat dibaca. |
| F07 | Ruang tunggu dan cek perangkat | Pasien, dokter | **belum** | Tidak ada endpoint ruang tunggu / cek perangkat | tidak ada | Tidak ada izin kamera/mikrofon, tes perangkat, atau posisi antrean di seluruh kode. |
| F08 | Konsultasi chat/video | Pasien, dokter | sebagian | `POST /konsultasi/mulai`, `GET /konsultasi/{id}`, `PUT /konsultasi/{id}/terima`, `GET/POST /konsultasi/{id}/chat`, `POST /konsultasi/{id}/chat/baca`, `PUT /konsultasi/{id}/selesai` | **`konsultasi.{id}` → `private-konsultasi.{id}`, event `chat.pesan`** | Chat matang (reconnect, dedupe, read receipt REST). **Video belum ada** (tanpa WebRTC/getUserMedia). Tidak ada kontrol "terima"/"mulai konsultasi" di UI walau endpoint `terima` ada. Tidak ada typing indicator, read receipt tidak realtime. |
| F09 | Resep dan detail obat | Pasien | ada | `GET /obat`, `POST /konsultasi/{id}/resep`, `GET /pasien/resep`, `GET /resep/{id}`, `GET /resep/{id}/cek-interaksi`, `POST /resep/{id}/verifikasi`, `GET /obat/{id}/stok`, `POST /resep/{id}/checkout`, `GET /pesanan-obat/{id}` | tidak ada | Antrean apoteker berbasis **id yang diketik manual** (tidak ada endpoint daftar antrean). Tidak ada endpoint majukan status pesanan. |
| F10 | Riwayat dan rekam medis | Pasien | sebagian | `POST /konsultasi/{id}/rekam-medis`, `GET /rekam-medis/{id}`, `PUT /rekam-medis/{id}`, `PUT /rekam-medis/{id}/final`, `POST /rekam-medis/{id}/amandemen`, `GET /pasien/resep`, `GET /pasien/surat-keterangan`, `GET /surat-keterangan/{nomor_surat}/verify` | tidak ada | **Tidak ada endpoint daftar rekam medis** (index menurunkan id dari resep). Tidak ada linimasa kunjungan, pencarian, unduh/ekspor. |
| F11 | Notifikasi dan pengingat | Pasien, dokter | sebagian | `GET /notifikasi`, `PUT /notifikasi/{id}/baca`, `PUT /notifikasi/baca-semua`, `GET/POST /auth/devices`, `DELETE /auth/devices/{deviceId}` (+ FCM push dari 5 produser: booking dibuat/dibatalkan, pembayaran selesai, resep siap, pesan baru) | tidak ada | **Kotak masuk in-app ada** (`/notifikasi` + bel; badge `meta.unread`, tandai satu/semua, filter; diverifikasi dari kode 2026-10-02). Gap: tidak ada pengingat terjadwal, preferensi kanal, jam tenang, status pengiriman, atau arsip; `tautan` API belum dipetakan ke rute SPA (baris dirender teks); body push `bookingDibatalkan` memuat `alasan` bebas (risiko UU PDP). Benchmark + pola: `web/ux/patterns/F11.md`; skor: `web/ux/scores/F11.md`. |
| F12 | Batal, jadwal ulang, refund | Pasien | sebagian | `PUT /booking/{id}/batalkan` | tidak ada | Batal ada (alasan opsional ≤255 + guard status). **Tidak ada jadwal ulang** dan **tidak ada refund**: tabel `refund` + enum `refund_sebagian/refund_penuh` ada tetapi nol jalur tulis, gateway/interface tidak punya API refund, webhook menolak `refund` (422). **Cacat ledger P0 (terverifikasi 2026-10-02):** `BookingService::batalkan()` selalu mengubah invoice → `dibatalkan` tanpa memeriksa status pembayaran dan tanpa menulis baris `refund` — booking lunas bisa dibatalkan dengan uang tanpa jejak. Guard batal belum memuat `check_in`/`no_show`; tidak ada jendela/biaya. Benchmark + pola: `web/ux/patterns/F12.md`; skor: `web/ux/scores/F12.md`; bukti: `web/ux/evidence/F12/*.md`. |
| F13 | Dashboard dokter (jadwal, antrean, catatan, tulis resep) | Dokter | sebagian | `GET /dokter/booking`, `PUT /konsultasi/{id}/terima`, `PUT /konsultasi/{id}/selesai`, rekam medis, `POST /konsultasi/{id}/resep`, `GET /obat`, `POST /konsultasi/{id}/surat-keterangan` | `konsultasi.{id}` (hanya di layar konsultasi) | Tidak ada dasbor dokter terpadu (antrean, jadwal hari ini, "mulai ≤2 ketukan"). Tidak ada tulis jadwal (`dokter_jadwal` read-only). Tidak ada validasi dosis (hanya peringatan interaksi). |
| F14 | Admin klinik (dokter, jadwal, laporan) | Admin klinik | **belum** | Tidak ada endpoint admin | tidak ada | Tidak ada route/layar admin. **Diverifikasi 2026-10-02:** 0 path admin dari 41 path `docs/openapi.yaml`; `dokter.lihat`, `jadwal.lihat`, `audit.lihat`, `pdp.kelola` nol konsumen route; `dokter_jadwal`/`dokter_libur` read-only (tidak ada operasi tulis); `audit_log` punya observer+redaksi tetapi tanpa endpoint baca; tidak ada agregasi laporan; tidak ada aksi massal. **Blokir backend:** kode izin `dokter.kelola`/`jadwal.kelola`/`laporan.lihat` belum ada di `RbacCatalog`. Benchmark + pola: `web/ux/patterns/F14.md`; skor: `web/ux/scores/F14.md`; bukti: `web/ux/evidence/F14/*.md`. |
| F15 | State umum: loading, kosong, error, offline, sesi habis | Semua | sebagian | `GET /me`, `GET /referensi/enums`, 13 tabel `GET /referensi/{slug}` | tidak ada | Loading skeleton, kosong, error + retry, sesi habis (refresh single-flight → redirect) sudah ada. **Potongan tipis offline kini ada** (Sesi 3): banner `navigator.onLine`, tombol kirim nonaktif, draf input tidak hilang, **tanpa antrean mutasi**. Sisa: offline-first penuh/antrean tulis, dan pengingat (F11). |

## Backend ada, UI belum

- **F02 Consent & privasi** — `POST/GET /pdp/persetujuan` lengkap dan berversi,
  tetapi 0 pemakaian di `web/src`. Ini win kepatuhan (UU PDP) termurah: API-nya
  sudah ada, tinggal UI.
- **F08 `PUT /konsultasi/{id}/terima`** — endpoint "dokter menerima konsultasi"
  ada, tetapi tidak ada tombol yang memanggilnya. `POST /konsultasi/mulai` juga
  belum punya pemicu UI eksplisit.
- **F08 video** — `tipe_layanan=video_call` dan kolom `room_id` ada, tetapi tidak
  ada provisioning token video / sinyal mulai-selesai. Praktis greenfield.
- **F11 pengingat, preferensi, dan status kirim** — produser notifikasi (5 metode/4 tipe),
  token perangkat, dan **kotak masuk in-app** (`/notifikasi`, bel, tandai baca) sudah ada;
  **tidak ada** endpoint/tabel untuk pengingat terjadwal, preferensi kanal, jam tenang,
  atau status pengiriman, dan tidak ada kolom kanal/arsip di `notifikasi`
  (perlu backend baru; kontrak + AC terblokir ada di `patterns/F11.md` §12).
- **F09 antrean apoteker** — tidak ada endpoint daftar antrean; `ApotekerQueuePage`
  meminta id resep diketik manual.
- **F14 admin** — `dokter.lihat`, `pdp.kelola`, dan kode admin lain ada di
  `RbacCatalog` tetapi tidak ada endpoint yang mengonsumsinya. Perlu endpoint
  backend baru sebelum UI. **Benchmark selesai 2026-10-02** (`patterns/F14.md`
  §4 memuat kontrak API usulan + kode izin yang harus ditambah di `RbacCatalog`);
  urutan yang diusulkan: setujui kontrak izin/endpoint → backend + tes → UI.

## UI ada, backend belum / hanya sebagian

- **F05 slot (DIBATALKAN setelah verifikasi 2026-10-01)** — `GET /dokter/{dokter}/slot`
  **terdaftar dan menjawab 200**; `slot-picker.tsx` mendeteksi 404 router yang tidak
  lagi terjadi → kode mati + docstring usang. Gap sebenarnya: data `dokter_jadwal`
  kosong untuk sebagian dokter seed.
- **F06 faktur** — tidak ada `GET /invoice/{id}` dan `POST /resep/{id}/checkout`
  tidak mengembalikan `invoice_id`; `pembayaran-menunggu.tsx` meminta invoice id
  diketik manual. Juga tidak ada daftar pesanan/faktur.
- **F13 majukan status pesanan** — `PesananObatStateMachine` mendefinisikan 6
  status, tetapi `PesananObatService::ubahStatus()` tidak dipanggil route mana
  pun; 4 dari 6 status tidak dapat diproduksi.
- **F10 daftar rekam medis** — sengaja tidak ada (dilaporkan sebagai gap);
  `rekam-dan-konsultasi-index-page.tsx` menurunkan id dari resep.

## Titik realtime (Reverb)

Hanya satu: **F08**, kanal `konsultasi.{id}` (wire: `private-konsultasi.{id}`),
event `chat.pesan`, dikonsumsi `use-konsultasi-channel.ts` → `chat-window.tsx`.
Auth kanal di `routes/channels.php` (hanya pasien & dokter pemilik konsultasi),
auth endpoint `POST /api/broadcasting/auth`. Tidak ada kanal notifikasi,
status-booking, resep, atau video.

## Usulan prioritas (berdasarkan Fase 0)

Default prompt: F05, F08, F09, F06. Setelah melihat kode, usulan saya:

- **P0 (kerjakan lebih dulu):**
  1. **F06 Pembayaran** — alur uang paling berisiko; ada lubang input manual
     (invoice id) yang harus ditutup desainnya, dan pola pembayaran lokal
     (QRIS/VA/e-wallet) sangat bernilai untuk konteks Indonesia.
  2. **F05 Booking** — inti produk; pemilih slot perlu pola yang benar sebelum
     endpoint slot dipasang.
  3. **F08 Konsultasi** — chat sudah jalan; pola ruang konsultasi, status, dan
     pemulihan koneksi perlu dibakukan (dan video perlu keputusan).
  4. **F09 Resep** — paling matang; benchmark di sini mengubah sedikit, tetapi
     penting untuk menetapkan standar keterbacaan data medis.
- **P1:** F02 Consent (greenfield + kepatuhan), F07 Ruang tunggu/cek perangkat
  (greenfield, mendukung video), F03/F04 (penemuan dokter), F13 (dasbor dokter).
- **P2:** F01, F10, F11, F12, F14, F15. **F14 admin** perlu keputusan lingkup
  backend lebih dulu (tidak ada endpoint).
- **F15 offline** disarankan sebagai potongan lintas-flow kecil yang dikerjakan
  bersama _global.md, bukan flow penuh.

**Usulan urutan sesi:** Sesi 1 = F05, F06, F08, F09 (default). Sesi 2 = F02, F07,
F03, F04. Sesi 3 = F13, F10, F11, F12. Sesi 4 = F14, F01, F15. Boleh diubah
pemilik produk.

## Kandidat awal (belum diverifikasi — diverifikasi di Fase 1)

| Flow | Lokal Indonesia | Telemedicine/global | Domain lain (pola spesifik) |
|---|---|---|---|
| F01 | Mobile JKN, SATUSEHAT Mobile, aplikasi bank (OTP/biometrik) | — | pola OTP (input-otp), Apple/Google passkeys |
| F02 | SATUSEHAT Mobile, Halodoc | Apple Health (berbagi data) | pola consent GDPR, OneTrust |
| F03 | Halodoc, Alodokter | Practo, Doctolib, Zocdoc | pola filter/pencarian marketplace |
| F04 | Alodokter | Practo, Doctolib, Zocdoc | halaman kepercayaan/e-commerce |
| F05 | Halodoc | Doctolib, Zocdoc, Practo | Calendly (slot picker) |
| F06 | Halodoc, Gojek/GoPay, Tokopedia | — | checkout Midtrans/Xendit, Baymard |
| F07 | — | Doxy.me, Teladoc, Amwell | Google Meet (pre-join) |
| F08 | Halodoc | Teladoc, Amwell | WhatsApp (pola chat) |
| F09 | Halodoc, Kimia Farma | MyChart, Apple Health Medications | label obat BPOM |
| F10 | MySiloam, SATUSEHAT Mobile | MyChart, Apple Health | linimasa/timeline app |
| F11 | Halodoc, SATUSEHAT Mobile | NHS App, Medisafe | Google Calendar, WhatsApp (+ jam tenang: Slack) |
| F12 | Traveloka, Halodoc | Doctolib, Zocdoc | pola refund e-commerce |
| F13 | — | Practo Ray, Doctolib Pro, Doxy.me | pola dasbor padat (Linear/Notion) |
| F14 | — | Practo Ray, Jane App, SimplePractice | tabel data padat |
| F15 | Gojek, aplikasi bank | — | pola offline-first (PWA) |
