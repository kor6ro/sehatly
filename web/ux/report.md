# Laporan Benchmark UX Sehatly

Tanggal riset: **2026-10-01** · Cakupan sesi: **F05, F06, F08, F09** (sesuai default prompt; 3–4 flow per sesi)
· Penulis: riset berbasis bukti · Status: **menunggu persetujuan** (jangan implementasi sebelum disetujui)

> **Temuan pemblokir:** `web/design-tokens.md` **tidak ada**, padahal `web/AGENTS.md`,
> `web/opencode.json`, dan `web/ux/BENCHMARK_PROMPT.md` mewajibkannya. Sumber token nyata =
> `web/src/styles/app.css`. Lihat §5 keputusan #1.

---

## 1. Ringkasan

**Flow yang dikerjakan:** F05 Pilih jadwal & booking, F06 Pembayaran, F08 Konsultasi chat/video,
F09 Resep & detail obat.

**Aplikasi/sumber yang dinilai (semua diverifikasi aktif 2026, sumber publik & legal):**

| Flow | Kandidat lokal | Telemedicine/global | Domain lain / pedoman |
|---|---|---|---|
| F05 | Halodoc, Alodokter | Practo, Doctolib, Zocdoc | Calendly (slot picker) |
| F06 | Halodoc, Gojek/GoPay, Tokopedia, Traveloka, Shopee | — | Midtrans, Xendit (dokumentasi), Baymard |
| F08 | Halodoc | Teladoc, Amwell, Doxy.me | WhatsApp, Apple HIG/Messages, NN/g, WCAG |
| F09 | Halodoc, Kimia Farma | MyChart, Apple Health Medications | Medisafe, BPOM (regulasi) |

**Pemenang per langkah (bukan "tiru aplikasi X"):**

- **F05** — tidak ada pemenang tunggal. *Best-of*: ringkasan keputusan & CTA nonaktif-beralasan
  **Halodoc**; tanggal+jam dalam satu halaman, field minimal, layar konfirmasi **Calendly**;
  slot terpakai tetap terlihat **Halodoc + Practo**. Keyakinan **sedang**.
- **F06** — pemenang rubrik **Gojek/GoPay** (3,41; 85% bobot terverifikasi; ≥2 sumber independen).
  Pola *best-of*: ringkasan total sebelum detail **Baymard/Shopee**; pengelompokan + pemangkasan
  metode **Midtrans/Baymard**; siklus status + anti bayar-ganda **Midtrans**; expiry per metode +
  pesan gagal spesifik **Xendit**. Keyakinan **sedang–tinggi**.
- **F08** — pemenang rubrik **WhatsApp** (4,27; 75% terverifikasi). Pola: status kirim/dibaca
  bertingkat + indikator mengetik **WhatsApp**; strip reconnect/dedupe/resync **Sehatly Fase 0**;
  pesan error & pemulihan draft **NN/g + Baymard**; privasi notifikasi & aksesibilitas **Apple HIG + WCAG**.
  Keyakinan **sedang**. Video: **tidak ada pemenang**, butuh backend sinyal baru.
- **F09** — tidak ada pemenang tunggal (Apple 3,80 tertinggi, 100% terverifikasi, tetapi skor
  konteks Indonesia = 1). *Best-of*: daftar→detail 2 ketukan **MyChart**; hierarki dosis &
  informasi wajib **Apple Health + BPOM**; aksi per status **Apple**; pemulihan stok **Halodoc**;
  privasi nama obat di notifikasi **Medisafe**. Keyakinan **sedang**.

---

## 2. Tabel pola terpilih

| Flow | Pola terpilih (ringkas) | Sumber utama | Skor pemenang | Keyakinan |
|---|---|---|---|---|
| **F05** | Ringkasan sebelum komitmen; tanggal+jam satu halaman; slot tak tersedia terlihat + alasan; CTA nonaktif + alasan inline; form detail minimal; sukses + aksi batal | Halodoc (3,77), Calendly (3,77); pembanding Practo, Alodokter | 3,77 @85% | **Sedang** |
| **F06** | Ringkasan total di atas; CTA tunggal nonaktif sampai valid; metode dikelompokkan; instruksi VA/QR + expiry; polling status terminal; promo di bawah | Gojek/GoPay (3,41) + Midtrans, Xendit, Baymard, Shopee | 3,41 @85% | **Sedang–tinggi** |
| **F08** | Status sesi di header; baris menunggu eksplisit; indikator mengetik; status kirim bertingkat; lampiran berbatas; pemulihan draft; strip reconnect + resync | WhatsApp (4,27) + NN/g, Apple, WCAG, Sehatly F0 | 4,27 @75% | **Sedang** |
| **F09** | Daftar resep berstatus; band tindakan berikutnya; item wajib-field terbaca penuh; panel interaksi berlabel; stok habis bukan dead-end; lacak pesanan; notifikasi tanpa nama obat | Apple Health (3,80) + MyChart, Halodoc, Medisafe, BPOM | 3,80 @100% (bukan pemenang tunggal) | **Sedang** |
| **F11** | Kotak masuk + filter + tandai baca; deep-link aman `tautan` API → rute SPA; pengingat terjadwal (obat/janji) + preferensi 2 tingkat + jam tenang; status dibaca vs terkirim dipisah; body push generik tanpa data medis | WhatsApp (3,59 @85%) pemenang rubrik; best-of: NHS App, SATUSEHAT Mobile, Medisafe, Google Calendar, Slack | 3,59 @85% (GCal 3,88 gagal independensi; SATUSEHAT 3,63 @40%) | **Sedang** |
| **F12** | Batal: dialog konsekuensi (biaya/refund di muka, alasan terstruktur opsional, peringatan ireversibilitas, privasi); jadwal ulang: slot baru + pengaman "jadwal lama tetap berlaku sampai jadwal baru tersimpan" + handling selisih; refund: status enum `refund.status` + jumlah/tujuan/SLA per metode + state gateway lambat; integritas ledger P0 | **NHS App (3,40 @100%, ≥2 penerbit) pemenang rubrik**; best-of: Grab (3,83 @90% gagal independensi), Halodoc, Traveloka, Booking.com; Sehatly Fase 0 | 3,40 @100% (Grab skor tertinggi 3,83 tetapi 1 penerbit) | **Sedang** |
| **F14** | Daftar dokter + kredensial (STR/SIP, verifikasi per-dokter, nonaktif ber-blast-radius); jadwal rutin multi-hari + salin + publish + libur seharian + blokir hapus berbooking; laporan periode agregat **tanpa export** (jujur, karena endpoint tidak ada) + peringatan ledger F12; audit trail read-only termasking; aksi massal Select All + action bar + type-to-confirm | **Jane (3,85 @100%, ≥2 penerbit) pemenang rubrik**; best-of: Shopify (4,29 @85% **gagal independensi**), Klinik Pintar (3,59), GitHub (3,47), Trustmedis (3,18), Practo Ray (3,00); regulasi Kemenkes STR/SIP + NN/g + NIST 800-92 | 3,85 @100% | **Sedang** (semua kontrak API = usulan; backend admin nol) |

> Baris **F11** ditambahkan **2026-10-02** dari sesi benchmark lanjutan (di luar cakupan awal F05/F06/F08/F09 di laporan ini); detail: `web/ux/patterns/F11.md`, skor `web/ux/scores/F11.md`, bukti `web/ux/evidence/F11/*.md`. Baris **F12** ditambahkan **2026-10-02** dari sesi benchmark lanjutan yang sama; detail: `web/ux/patterns/F12.md`, skor `web/ux/scores/F12.md`, bukti `web/ux/evidence/F12/*.md`. Baris **F14** ditambahkan **2026-10-02**; detail: `web/ux/patterns/F14.md`, skor `web/ux/scores/F14.md`, bukti `web/ux/evidence/F14/*.md`. Baris lain tidak diubah.

Detail lengkap: `web/ux/patterns/F05.md`, `F06.md`, `F08.md`, `F09.md`; lintas flow:
`web/ux/patterns/_global.md`; skor: `web/ux/scores/*.md`; bukti: `web/ux/evidence/*/*.md`.

---

## 3. Gap Sehatly terbesar (berurut dampak) + prioritas Fase 6

Fase 6 dijalankan pada aplikasi nyata (Laravel di `:8000`, Vite di `:5173`, DB ter-seed).
**34 screenshot** state saat ini di 390 px & 1280 px tersimpan di `web/ux/refs/current/`
(tidak di-commit). Catatan: akun demo ter-seed tidak punya data transaksional, sehingga halaman
detail (konsultasi/resep/pesanan berisi) tidak tertangkap; yang tertangkap adalah halaman publik,
daftar, dan **state kosong** — yang justru penting.

| # | Gap | Bukti | P | Usaha | Risiko regresi |
|---|---|---|---|---|---|
| 1 | **F06: tidak ada daftar pesanan/invoice; checkout tidak mengembalikan `invoice_id`.** `GET /invoice/{id}` **sudah ditambahkan 2026-10-01** (dengan `InvoicePolicy` + tes Pest IDOR: pasien B tidak bisa membuka invoice pasien A), jadi status pembayaran kini bisa dibaca bila id diketahui. Sisa: UI masih harus menemukan id invoice (tidak dipublikasikan checkout, tidak ada daftar). | `web/ux/flows.md` F06; `app/Policies/InvoicePolicy.php`; `tests/Feature/Payment/InvoiceReadTest.php` | **P1** | **M** (sisa: publikasikan id / daftar) | Sedang |
| 2 | **DIVERIFIKASI 2026-10-01 — BUKAN gap backend.** `GET /api/v1/dokter/5/slot?tanggal=2026-10-08` → **HTTP 200**, 16 slot, `timezone=Asia/Jakarta`; `route:list` menampilkan `dokter.jadwal` + `dokter.slot`. **Kode mati + docstring usang sudah dihapus** (Sesi 3, `feat/f05-booking`); label zona & F15 tipis ditambahkan. Sisa: isi `dokter_jadwal` untuk dokter seed (data, bukan endpoint). | `slot-picker.tsx:258-295`, `lib/api/jadwal.ts:9-34`; pengukuran langsung 2026-10-01 | **P2** | **S** (bersih-bersih + seed) | Rendah |
| 3 | **F02: API consent UU PDP ada, UI nol.** Tidak ada layar persetujuan/penarikan/kebijakan privasi. | `web/src` tanpa pemakaian `/pdp/persetujuan`; `flows.md` F02 | **P0** | **S–M** | Rendah |
| 4 | **F08: kontrol sesi tidak lengkap** — tidak ada tombol "terima/mulai" walau `PUT /terima` ada; read receipt & typing belum realtime (keputusan: whisper `chat.mengetik` + `last_read_at` per peserta). **Copy debug F08 sudah dihapus 2026-10-01** (statistik realtime, nama endpoint, placeholder "Patienten"). Catatan: nama endpoint masih bocor di `PageHeader description` ~15 halaman lain — belum disapu. | `konsultasi-page.tsx`, `chat-window.tsx`; `patterns/F08.md` §12 | **P1** | **M** | Sedang |
| 5 | **F07/F08: video absen total** (tanpa WebRTC/getUserMedia/sinyal). Halaman pra-panggilan & izin berkonteks juga belum ada. | grep `getUserMedia/mediaDevices` = nihil; `flows.md` F07/F08 | **P1** | **L** (backend baru) | Tinggi |
| 6 | **F09/F13: antrean apoteker berbasis id ketik** (tidak ada endpoint daftar); **tidak ada endpoint majukan status pesanan** (`PesananObatService::ubahStatus` tak dipanggil route). | `apoteker-verifikasi-queue.tsx`; `PesananObatStateMachine`; `flows.md` F09 | **P1** | **M–L** | Sedang |
| 7 | **F10: tidak ada endpoint daftar rekam medis** (index menurunkan id dari resep); tanpa linimasa kunjungan, pencarian, unduh/ekspor. | `rekam-dan-konsultasi-index-page.tsx`; `flows.md` F10 | **P1** | **M** | Sedang |
| 8 | **F12: cacat ledger P0 + tidak ada jadwal ulang & refund.** `BookingService::batalkan()` selalu mengubah invoice → `dibatalkan` tanpa memeriksa `pembayaran.status` dan tanpa menulis baris `refund` — booking lunas bisa berakhir "dibatalkan" dengan uang tanpa jejak. Jadwal ulang greenfield; `refund` (tabel + enum) nol jalur tulis; webhook menolak `refund` 422; guard batal belum memuat `check_in`/`no_show`. | `BookingService.php:194-199`; `docs/schema-notes.md:1496-1502`; benchmark `web/ux/patterns/F12.md` §12; `web/ux/evidence/F12/sehatly-fase0.md` | **P0 (ledger) / P1 (fitur)** | **L** (backend baru) | Tinggi |
| 9 | **F14 admin klinik absen total** (route/layar/aksi massal tak ada; kode RBAC `dokter.lihat`/`jadwal.lihat`/`audit.lihat`/`pdp.kelola` tak dikonsumsi). **Benchmark selesai 2026-10-02** — `patterns/F14.md` §4 memuat kontrak API usulan & 3 kode izin baru (`dokter.kelola`, `jadwal.kelola`, `laporan.lihat`) yang wajib disetujui lebih dulu; tanpa itu UI mustahil. | `web/ux/evidence/F14/sehatly-fase0.md`; `web/ux/patterns/F14.md` §4/§12; `flows.md` F14 | **P2** | **L** (backend baru) | Tinggi |
| 10 | **F15 offline belum ditangani di mana pun** (tanpa `navigator.onLine`/banner/antrean tulis); plus preferensi/reminder notifikasi F11 belum ada. | grep `offline/navigator.onLine` = nihil; `flows.md` F11/F15 | **P2** | **M** (potongan lintas-flow) | Sedang |

**Rekomendasi urutan:** (a) putuskan 5 hal di §5; (b) tutup gap backend P0 (#1, #2, #3) agar UI
pattern bisa dibangun tanpa fallback; (c) implementasi **satu flow per sesi** mengikuti
`web/AGENTS.md` dan AC di `patterns/<flow>.md`.

---

## 4. Yang TIDAK terverifikasi dan batasan riset

1. **Aksesibilitas = N/V untuk SEMUA aplikasi kandidat.** Tidak ada sumber publik yang mengukur
   kontras, target sentuh, fokus keyboard, atau screen reader. Karena itu, dokumen pola memakai
   syarat aksesibilitas milik Sehatly sendiri (`web/AGENTS.md`: ≥44 px, kontras ≥4,5:1, status
   tidak warna-saja) dan pedoman WCAG/Apple HIG — bukan tiruan dari aplikasi kandidat.
2. **Aplikasi native tidak dapat diakses langsung** (tanpa akun, tanpa emulator, tidak menyalin
   aplikasi). Bukti berasal dari web publik, store listing, help center, dokumentasi design system,
   artikel teardown, dan regulator. Hitungan layar/ketukan native = dari langkah terdokumentasi,
   **bukan** pengamatan langsung.
3. **Semua alur kandidat berhenti di gerbang login/form/PII.** Tidak ada akun dibuat, tidak ada
   form dikirim. Langkah setelah gerbang sebagian besar **TIDAK TERVERIFIKASI**.
4. **Blokir akses:** Doctolib & Zocdoc menolak akses otomatis (HTTP 403, `robots.txt` melarang
   area booking); halaman booking Calendly = shell JS; `faq.whatsapp.com` mengembalikan HTTP 400
   (isi diverifikasi lewat indeks pencarian URL kanonik yang sama); halaman Apple HIG butuh JS
   (diverifikasi lewat endpoint JSON resmi Apple). Semua dicatat di file bukti masing-masing.
5. **Satu penerbit ≠ independen.** Xendit, Midtrans, Traveloka, Shopee, Tokopedia hanya punya
   bukti dari satu penerbit → **tidak boleh jadi pemenang**, hanya sumber pola (dicatat per langkah).
   **Doctolib didiskualifikasi** karena red flag terverifikasi (consent riset AI kesehatan
   default-in/opt-out, The Local 2026-08-04).
6. **Fase 6:** akun demo ter-seed tidak punya data transaksional → halaman detail berisi tidak
   tertangkap; hanya state kosong/publik. Screenshot 34 file di `web/ux/refs/current/`.
7. **Kandidat dibuang:** Google Calendar appointment scheduling (F05) — tidak ada bukti aktivitas
   dan observabilitas alur di sesi ini.
8. **BPOM:** isi Lampiran PerKa 24/2017 vs PerBPOM 15/2023 belum tuntas; dipakai hanya untuk
   daftar field wajib label, bukan sengketa hukum.
9. **Seluruh daftar kandidat awal di `flows.md` adalah titik awal dari pengetahuan umum** dan
   sudah diverifikasi ulang; kandidat yang tidak bisa dibuktikan dibuang, bukan ditempel.

---

## 5. Keputusan pemilik produk (2026-10-01)

**Sudah diputuskan:**

1. **Token:** `web/AGENTS.md` dan `web/opencode.json` diarahkan ke `web/src/styles/app.css`;
   `design-tokens.md` **tidak dibuat** (satu sumber kebenaran). Agent wajib mengaudit `app.css`
   terhadap aturan gaya dan **melaporkan selisih saja** — hasil di `web/ux/app-css-audit.md`
   (selisih pasti: `--destructive-foreground` identik dengan `--destructive` di mode terang → kontras 1:1).
2. **Urutan gap backend P0 (jalur bahagia: booking → bayar):** (1) verifikasi route slot
   **[SELESAI: route ada, HTTP 200]** → bukan gap backend; (2) `invoice_id` + `GET /invoice/{id}`
   (wajib **Policy + tes Pest IDOR**: "pasien B tidak bisa membuka invoice pasien A"); (3) UI consent
   F02 (gerbang UU PDP, API sudah ada); (4) antrean apoteker menyusul bersama F09. Wajib juga:
   audit **double-booking** (lock/unique constraint) pada booking, dan `composer contract` setelah
   perubahan enum/OpenAPI.
3. **Zona waktu:** simpan **UTC**; tampilkan **dikonversi ke zona perangkat + selalu berlabel**
   (mis. "09.00 WIB"); bila zona perangkat berbeda dari zona jadwal dokter, tampilkan **kedua** zona
   di konfirmasi. Label tidak boleh dihilangkan (zona perangkat bisa salah).
4. **F08 disetujui sebagian:** `chat.mengetik` = **client whisper event** (tanpa DB/antrean);
   `chat.dibaca` = simpan **`last_read_at` per peserta** (bukan per pesan); video = **fase terpisah**;
   pasien hanya **"Keluar dari sesi" + konfirmasi**, sedangkan **yang mengakhiri konsultasi tetap dokter**
   (transisi status ditegakkan server, refund ke F12); pesan medis **dilarang "hapus untuk semua orang"**;
   copy debug dihapus (**selesai**).
5. **Offline F15:** **belum** dijadikan pekerjaan global sebelum F05/F06. Potongan tipis saja:
   komponen state bersama (loading/kosong/error/coba lagi), sesi habis, banner "tidak ada koneksi",
   draf input tidak hilang, penanganan konflik slot. **Dilarang mengantre mutasi booking/pembayaran
   saat offline** (risiko idempotensi/double charge) — cukup deteksi, blokir dengan pesan jelas,
   jaga input.

**Masih butuh persetujuan:**

- **`@axe-core/playwright`** sebagai dev-dependency untuk cek aksesibilitas otomatis di AC —
  diminta pemilik, menunggu konfirmasi karena menambah dependensi.
- **Lokasi pasti "Fase 4B"** dari prompt audit pertama (double booking / lock / unique constraint):
  tidak ditemukan literal di repo. Interpretasi kerja: `.omo/drafts/sehatly-telemedicine-platform.md`
  bagian "F8. Schema-level constraints" (tidak ada unique index di `booking`; transaction + row lock;
  predikat overlap `slot_mulai < baru_selesai AND slot_selesai > baru_mulai`). Mohon konfirmasi.

---

## 6. Usulan validasi dengan pengguna nyata

Benchmark **bukan** pengganti uji pengguna. Usulan: **think-aloud moderat**, **6–8 partisipan** —
5 pasien (termasuk 1–2 lansia dan 1–2 pengguna Android kelas menengah-bawah dengan jaringan
terbatas) dan 2–3 dokter (pengguna berulang, waktu sempit). Tanpa data kesehatan nyata; pakai akun
demo & data fiktif. Ukur: berhasil/gagal, waktu, jumlah kesalahan, jumlah bantuan, dan kutipan
verbal. Lima tugas:

1. **F05 Booking** — dari profil dokter, pesan slot; lalu **ubah tanggal** sebelum konfirmasi;
   kemudian coba pesan slot yang baru saja diambil orang lain. *Ukur:* ≤4 langkah; data tidak
   hilang saat slot bentrok.
2. **F06 Pembayaran** — bayar satu tagihan dengan **QRIS** dan dengan **VA**, lalu simulasikan
   **pembayaran gagal/kedaluwarsa** dan lanjutkan. *Ukur:* total terlihat sebelum bayar; status
   akhir jelas; tidak ada pembayaran ganda.
3. **F08 Konsultasi** — masuk ruang, kirim pesan + lampiran, **putuskan jaringan 10 detik**,
   pastikan pesan tersambung ulang tanpa duplikat, akhiri sesi. *Ukur:* status kirim/dibaca
   terbaca; pemulihan ≤1 aksi.
4. **F09 Resep** — buka satu resep, jelaskan **dosis & aturan pakai dengan kata sendiri**, lalu
   temukan aksi berikutnya; ulangi pada **resep kedaluwarsa**. *Ukur:* nol salah tafsir dosis;
   jalan keluar jelas.
5. **F11/F10 Riwayat** — temukan kembali resep lama dan surat/ rekam medis dari linimasa/daftar.
   *Ukur:* ketemu ≤3 langkah; tanpa menampilkan data medis di notifikasi layar kunci.

Selain itu, uji **pemahaman consent (F02)** dengan bahasa awam: minta partisipan menjelaskan apa
yang mereka setujui dan bagaimana menariknya.

---

## Lampiran — peta berkas

```
web/ux/
  flows.md                     # Fase 0: status + endpoint + realtime per flow (v0.2)
  rubric.md, pattern-template.md, BENCHMARK_PROMPT.md
  evidence/{F05,F06,F08,F09,F11,F12,F13,F14}/*.md
  scores/{F05,F06,F08,F09,F11,F12,F13,F14}.md
  patterns/{F05,F06,F08,F09,F11,F12,F13,F14}.md, patterns/_global.md
  refs/current/*.png           # Fase 6: 34 screenshot 390/1280 (tidak di-commit)
  report.md                    # dokumen ini
```

**Berkas kode/token/db tidak diubah selama riset.** Yang berubah hanya berkas di `web/ux/`.
