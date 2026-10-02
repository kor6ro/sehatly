# Evidence F12 — Sehatly Fase 0 (inventaris kode nyata)

Tanggal inventaris: **2026-10-02**. Read-only terhadap kode; hanya membaca. Semua klaim di bawah berasal dari file yang dibaca langsung atau inventaris agent dengan path + nomor baris. Ini **baseline fakta**, bukan penilaian UX.

## 1. Endpoint yang ada (dan yang tidak)

| Method + path | Nama | Middleware | File:baris |
|---|---|---|---|
| `PUT /api/v1/booking/{id}/batalkan` | `booking.batalkan` | `whereNumber('id')`, `permission:booking.batal` | `routes/api.php:257-260` |
| `POST /api/v1/booking` | `booking.store` | `permission:booking.buat`, `throttle:booking` | `routes/api.php:253-255` |
| `GET /api/v1/pasien/booking` | `booking.index` | `permission:booking.lihat` | `routes/api.php:237-239` |
| `GET /api/v1/dokter/booking` | `dokter.booking.index` | `permission:booking.lihat`, `tipe:dokter` | `routes/api.php:262-264` |
| `POST /api/v1/invoice/{id}/bayar` | `invoice.bayar` | `whereNumber`, `permission:pembayaran.bayar` | `routes/api.php:1294-1297` |
| `GET /api/v1/invoice/{id}` | `invoice.show` | `whereNumber`, `permission:pembayaran.bayar` | `routes/api.php:1330-1333` |
| `POST /api/v1/webhook/payment/{gateway}` | `webhook.pembayaran` | `whereIn` 4 gateway, `throttle:webhook-payment` | `routes/api.php:1345-1348` |
| `POST /api/v1/promo/validasi` | `promo.validasi` | `throttle:promo-validasi` | `routes/api.php:952-956` |

- **Tidak ada** route reschedule/jadwal-ulang di `routes/api.php` (pencarian `reschedule|jadwal_ulang|pindah jadwal|ubah jadwal` = nihil di seluruh repo).
- **Tidak ada** route refund. `docs/openapi.yaml` juga tidak memuat path refund/reschedule; satu-satunya `refund` adalah skema enum (`EnumPembayaranStatus`, `refund.status`).
- `docs/openapi.yaml:479-524`: request body batalkan → `CancelBookingRequestBody` (`openapi.yaml:3389-3400`): hanya `alasan_pembatalan` tipe string, aturan `string|max:255`, **`required: []`** (opsional), `additionalProperties: false`.

## 2. Status booking (8) dan guard pembatalan

`app/Http/Requests/Booking/BookingRequest.php:62-71` — `STATUS_SEMUA` (urutan DDL):
`menunggu_pembayaran`, `terjadwal`, `check_in`, `berlangsung`, `selesai`, `dibatalkan`, `no_show`, `kadaluarsa`.

- `BookingRequest.php:53`: `STATUS_TIDAK_BISA_DIBATALKAN = ['berlangsung','selesai','dibatalkan','kadaluarsa']`.
- Artinya **boleh dibatalkan menurut kode**: `menunggu_pembayaran`, `terjadwal`, `check_in`, **`no_show`**.
- Slot dilepas hanya oleh `dibatalkan`/`kadaluarsa`: `app/Services/Booking/SlotAvailabilityService.php:279` `STATUS_TIDAK_MENGKONSUMSI = ['dibatalkan','kadaluarsa']`.
- `check_in` dapat memulai konsultasi (`KonsultasiService::STATUS_BOOKING_BISA_MULAI = ['terjadwal','check_in']`) dan **masih bisa dibatalkan** — celah guard.
- `no_show` **tidak diproduksi** oleh kode mana pun di `app/` (hanya muncul di daftar enum), tetapi **bisa dibatalkan** (flip ke `dibatalkan`, melepas slot) — celah data.
- **Tidak ada jendela waktu** dan **tidak ada biaya** di kode mana pun (pencarian `batal.*window|fee|biaya pembatalan` = nihil).

## 3. Alur batal backend (fakta)

`app/Services/Booking/BookingService.php:166-222`:
1. Kepemilikan: pasien → `PasienRecordAccess::bookingOrFail`; dokter → `dokterBookingOrFail`; akun tanpa baris pasien/dokter → **403**; baris milik tenant lain → **404** (tidak membocorkan).
2. Guard status (`BookingRequest::STATUS_TIDAK_BISA_DIBATALKAN`) → `ValidationException` field `status`: “Booking dengan status tersebut tidak dapat dibatalkan.”
3. Menulis `status='dibatalkan'`, `dibatalkan_oleh` (`pasien|dokter|sistem`), `alasan_pembatalan = $alasan`.
4. **Membatalkan invoice terkait tanpa syarat** (`BookingService.php:194-199`):
   ```php
   Invoice::query()
       ->where('referensi_tipe', 'booking')
       ->where('referensi_id', $booking->getKey())
       ->update(['status' => 'dibatalkan']);
   ```
5. Notifikasi ke **pihak lain**; `alasan` bebas masuk body (`BookingService.php:212-218`) — risiko UU PDP yang sudah dicatat F11 (`patterns/F11.md` §7 #1).

**Cacat ledger yang harus disadari F12:** langkah 4 tidak memeriksa status pembayaran. Booking yang **sudah dibayar** (`pembayaran.status='berhasil'`, `invoice.status='lunas'`) akan dibuat menjadi **`invoice.status='dibatalkan'`**, sementara baris `pembayaran` tetap `berhasil` dan **tidak ada baris `refund`**. Ini persis keadaan yang diperingatkan oleh migrasi refund: “A service that sets `pembayaran.status='refund'` without writing a `refund` row, or writes a `refund` row without flipping the status, produces a state the schema permits and the ledger cannot explain” (`database/migrations/2026_10_01_000064_refund_table.php:21-25`). Di sini bahkan lebih buruk: pembayaran `berhasil` + invoice `dibatalkan` + tanpa refund. **Uang masuk, invoice tidak mengakui, tidak ada jejak refund.**

## 4. Refund: model ada, jalur nol

**Model/skema:**
- `app/Models/Refund.php`: `$table='refund'`, `CREATED_AT='dibuat_at'`, `UPDATED_AT=null`; kolom `pembayaran_id`, `jumlah`, `alasan`, `status`; relasi `pembayaran()`.
- `database/migrations/2026_10_01_000064_refund_table.php`: 6 kolom; `status ENUM('diajukan','diproses','berhasil','ditolak') DEFAULT 'diajukan'`; `dibuat_at` saja → **transisi tidak bertimestamp**; tidak ada FK ke `pembayaran.status`; `jumlah` tidak direkonsiliasi (`docs/schema-notes.md:1496-1502`).
- `app/Models/Pembayaran.php:74-76`: relasi `refund(): HasMany` — satu-satunya pemakaian.

**Enum:**
- `PembayaranStatus::Refund = 'refund'`; `KEADAAN_AKHIR` memuat `refund`; `SETTLE = ['berhasil','gagal','kedaluwarsa']` **tidak** memuat refund (`app/Enums/PembayaranStatus.php:50-81`).
- `InvoiceStatus` memuat `refund_sebagian`/`refund_penuh` (`app/Enums/InvoiceStatus.php:46-47`) — **tidak pernah ditulis oleh kode mana pun**.
- Webhook menolak `refund` dengan **422** (`MockPaymentGatewayService::statusDari`, komentar `:388-412`); test menegaskan: `tests/Feature/Payment/PaymentWebhookTest.php` — “a status outside the three settlement outcomes is a 422, never a write”.

**Tidak ada jalur tulis:**
- `PaymentService` (L232-295 mulai, L342-422 webhook) tidak punya metode refund; `PaymentGatewayService` (interface) tidak punya metode refund; `MockPaymentGatewayService` menolak refund; `config/payment.php` tidak punya konfigurasi refund.
- Pencarian `Refund::`/`new Refund`/`->refund()` di `app/` = hanya relasi baca. **Model Refund tidak pernah dibuat barisnya.**
- Gateway yang dikenal enum: `midtrans`, `xendit`, `doku`, `flip` (`app/Enums/PembayaranGateway.php:48-53`); konfigurasi aktif `payment.gateway=mock`, `payment.gateway_pembayaran=midtrans`.
- **Tidak ada test** yang membuat/menguji refund; `refund` muncul hanya di assertion paritas skema.

## 5. Reschedule: nol di seluruh repo

Hasil pencarian (case-insensitive) `reschedule`, `reschedul`, `jadwal_ulang`, `jadwal ulang`, `pindah jadwal`, `ubah jadwal`:
- `web/src` → 0; `app` → 0; `routes` → 0; `docs/openapi.yaml` → 0.
- Tidak ada UI, fungsi API client, route, FormRequest, service, atau kolom. **Greenfield total.**

## 6. UI yang ada (batal) — fakta

`web/src/features/booking/booking-list.tsx` (satu-satunya dialog batal di app):
- Trigger baris: tombol `outline` “**Batalkan**” + ikon `XCircle`; **disembunyikan** (bukan disabled) bila `!bisaDibatalkan(status)` (L209, L455-468); baris non-cancellable menampilkan teks “Status `{status}` tidak dapat dibatalkan.” (L470-472).
- Dialog: judul “**Batalkan booking**” (L235); deskripsi menyebut nomor booking, status `dibatalkan`, slot dilepas, “Tindakan ini dicatat pada server dengan nama akun Anda.” (L237-242).
- Field alasan: label “**Alasan pembatalan**”, hint “**Opsional, maksimal 255 karakter.**”, `maxLength=255`, `placeholder="Contoh: jadwal saya berubah."`, memakai `FieldInput` (**input satu baris, bukan textarea**) (L245-262).
- Tombol: “Batal” (outline) dan “**Batalkan booking**” (`destructive`, disabled saat pending) (L271-333).
- Payload: `{}` bila kosong, else `{ alasan_pembatalan: trimmed }` (L290-300).
- Mutasi: `PUT booking/${id}/batalkan` (`web/src/lib/api/booking.ts:260-269`), invalidasi `['v1','booking']` + slot dokter (`booking.ts:326-339`).
- Baris `dibatalkan`: badge + catatan “Slot ini sudah dilepas dan dapat dipesan kembali.” + “Dibatalkan oleh pasien/dokter/sistem” + alasan (`booking-status-badge.tsx:131-145`, `booking-list.tsx:438-442`). **Tidak ada tombol jadwal ulang.**
- **Tidak ada halaman detail booking** (`/booking/:id` tidak ada; route: `/booking`, `/booking/:dokterId`, `/dokter/booking` — `web/src/app/router.tsx:194-208`).
- **Tidak ada UI refund**: `web/src/lib/api/pembayaran.ts` hanya fetch invoice/metode/promo/bayar; label status pembayaran `refund` = “Dikembalikan” (L73) tetapi tidak ada alur.
- `BookingResource` (L31-65) mengembalikan `id, nomor_booking, pasien_id, anggota_keluarga_id, dokter_id, jadwal_id, faskes_id, tipe_layanan, tanggal_kunjungan, slot_mulai, slot_selesai, nomor_antrian, keluhan, lampiran_keluhan, is_rujukan, is_konsultasi_lanjutan, status, dibatalkan_oleh, alasan_pembatalan, dibuat_oleh_user_id, dibuat_at` + `pasien` (whenLoaded). **Tidak ada field reschedule/refund.**

## 7. Kesiapan kit UI (untuk pattern)

- **Ada** (`web/src/components/ui/`): `alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, dialog, dropdown-menu, icon, input, input-otp, label, navigation-menu, select, separator, sheet, sidebar, skeleton, sonner, spinner, textarea, toggle, toggle-group, tooltip, placeholder-pattern`.
- **Tidak ada**: `switch`, `radio-group`, `tabs`, `accordion`, `progress`, **`alert-dialog`**. → Dialog konfirmasi memakai `dialog`; pilihan alasan memakai `select`/`toggle-group`; tidak ada `radio-group`.
- Token: `web/src/styles/app.css` (`--destructive`, `--warning`, `--success`, `--muted`, `--ring`, `--radius`); font `'Instrument Sans'`.

## 8. Konsekuensi untuk F12 (ringkas)

1. **Batal jalan, tetapi kebijakan kosong** — tidak ada jendela/biaya; guard tidak memuat `check_in` & `no_show`.
2. **Pembatalan booking berbayar merusak ledger** — invoice → `dibatalkan`, tanpa baris refund, pembayaran tetap `berhasil` (P0).
3. **Reschedule = nol** — endpoint, kolom, UI, test tidak ada (greenfield; butuh keputusan pemilik).
4. **Refund = nol jalur** — tabel/status menunggu dipakai; webhook & gateway menolak refund; tidak ada rekonsiliasi; tidak ada status tracking untuk pasien.
5. **Alasan batal bebas teks dan bocor ke notifikasi** — perlu aturan UU PDP + pola alasan terstruktur (tanpa kolom baru; tetap `alasan_pembatalan`).
6. **Tidak ada tempat tinggal aksi** — tidak ada `/booking/:id`; aksi hanya di baris daftar.
