# Evidence F11 — Sehatly Fase 0 (inventaris kode, bukan aplikasi pembanding)

Sumber: **kode repositori** (bukan URL publik). Tanggal verifikasi: **2026-10-02**.
Jenis: pembacaan langsung `routes/api.php`, `app/Http/Controllers/Api/V1/NotifikasiController.php`,
`app/Http/Resources/NotifikasiResource.php`, `app/Models/Notifikasi.php`, `app/Enums/NotifikasiTipe.php`,
`app/Http/Requests/Notifikasi/IndexNotifikasiRequest.php`, `app/Services/Notifikasi/*`,
`app/Services/{Booking,Konsultasi,Payment,Resep}/*`, `app/Http/Controllers/Api/V1/AuthController.php`,
`app/Http/Resources/UserDeviceResource.php`, `docs/openapi.yaml`, `web/src/pages/notifikasi-page.tsx`,
`web/src/features/notifikasi/*`, `web/src/lib/api/notifikasi.ts`, `web/src/app/router.tsx`.
Catatan: ini **baseline**, bukan kandidat pola (satu sumber = kode sendiri).

## 1. Endpoint yang benar-benar ada

| Route | Middleware | Catatan |
|---|---|---|
| `GET /api/v1/notifikasi` | `auth:sanctum`, `permission:notifikasi.lihat` | `routes/api.php:1457-1459`; `docs/openapi.yaml:1278`; tanpa throttle; query `unread` (`true`/`1`/`false`/`0`), `page`, `per_page` maks 100 (`IndexNotifikasiRequest`) |
| `PUT /api/v1/notifikasi/{id}/baca` | + `throttle:notifikasi-baca` (10/60 s) | Tanpa body; idempoten — `dibaca_at` pertama dipertahankan; bukan milik pemanggil → **404** (bukan 403) |
| `PUT /api/v1/notifikasi/baca-semua` | + `throttle:notifikasi-baca` | Tanpa body; menjawab `{ditandai: n}` = jumlah baris yang **berubah**; chunk 500 per transaksi |
| `GET/POST /api/v1/auth/devices`, `DELETE /api/v1/auth/devices/{deviceId}` | `auth:sanctum` | `routes/api.php:118-125`; `POST` body `device_id` (3–255), `platform` ∈ `android|ios|web`, `fcm_token` opsional ≤255, `app_versi` ≤20 |

Respons list: `data.notifikasi[]` + `meta` paginasi + `meta.unread` (dihitung query terpisah atas **seluruh** baris `dibaca_at IS NULL` milik pemanggil; tidak terpengaruh `?unread`/`?page`).
Respons baris (`NotifikasiResource`): `{id, judul, isi, tipe, tautan, payload, dibaca_at, dibuat_at}`. `user_id` sengaja tidak diterbitkan.
`tautan` = **path API** (`/api/v1/booking/1001`, `/api/v1/invoice/12`, `/api/v1/resep/{id}`, `/api/v1/konsultasi/{id}/chat`); `payload` = objek berisi id (`booking_id`, `invoice_id`, `resep_id`, `konsultasi_id`, `pengirim_user_id`; `alasan` bebas teks pada pembatalan).

## 2. Tabel `notifikasi` (skema nyata, `telemedicine_test.sql:1036`)

Kolom: `id`, `user_id`, `judul VARCHAR(200)`, `isi VARCHAR(500)`, `tipe`, `tautan VARCHAR(500) NULL`, `payload JSON NULL`, `dibaca_at DATETIME NULL`, `dibuat_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`.
**Tidak ada** kolom kanal (`channel`), status kirim (`dikirim_at`/`status_kirim`), percobaan, arsip, atau soft-delete. Indeks `idx_notif (user_id, dibaca_at)`.
`NotifikasiTipe` (ENUM 7): `booking`, `pembayaran`, `resep`, `chat`, `lab`, `promo`, `sistem`. Yang benar-benar ditulis service: **4** (`booking`, `pembayaran`, `resep`, `chat`); `lab`, `promo`, `sistem` **tanpa produser**.

## 3. Produser (5 metode, 4 tipe) dan penerimanya

| Produser | Penerima | `tautan`/payload | Bukti |
|---|---|---|---|
| `BookingService::bookingDibuat` | pasien (`$pasien->user`) | `/api/v1/booking/{id}` | `app/Services/Booking/BookingService.php:118` |
| `BookingService::bookingDibatalkan` | pihak lawan (`dokter?->user` bila pembuat pasien, `pasien?->user` bila pembuat dokter) | + `alasan` **teks bebas** masuk `isi` dan `payload` | `BookingService.php:213`; `NotificationService::bookingDibatalkan` |
| `PaymentService::pembayaranSelesai` | pasien | `/api/v1/invoice/{id}` | `PaymentService.php:404` |
| `ResepVerifikasiService::resepSiap` | pasien (hanya saat transisi status “siap”) | `/api/v1/resep/{id}` | `ResepVerifikasiService.php:431` |
| `KonsultasiService::pesanBaru` | **sisi lawan bicara**: pasien→dokter, dokter→pasien | `/api/v1/konsultasi/{id}/chat` | `KonsultasiService.php:549` |

`NotificationService::kirim()` menyimpan baris lewat model (audit observer) lalu `dorong()`: satu push per perangkat `user_devices` dengan `aktif=1` dan `fcm_token` tidak kosong. Push membawa `judul` + `isi` + `data {tipe, tautan, notifikasi_id}` (`FcmPushDispatcher`). FCM HTTP v1; status:
- sukses → tanpa log khusus;
- perangkat tidak aktif / tanpa token → `notifikasi.push.lewat`;
- error jaringan/kredensial/5xx → `notifikasi.push.fcm_gagal` (log);
- token mati (`UNREGISTERED`/`NOT_FOUND`/`INVALID_ARGUMENT`) → `aktif=0` + `notifikasi.push.fcm_token_mati`.
**Tidak ada kolom/endpoint status pengiriman yang bisa dibaca klien.** `PushDispatcher` hanya punya satu verb `kirim()`.
Driver push dipilih `config('push.driver')` ∈ `log|fcm`; `log` = `LogPushDispatcher` (tidak mengirim apa pun, menulis log).

## 4. `user_devices`

Kolom: `user_id`, `device_id` (unik per user), `platform ENUM('android','ios','web')`, `fcm_token`, `app_versi`, `aktif`, `last_active_at`, `dibuat_at`.
`GET /auth/devices` → `UserDeviceResource` **mengembalikan `fcm_token`** (keputusan backend: token milik pemanggil), plus `device_id`, `platform`, `app_versi`, `aktif`, `last_active_at`.

## 5. UI yang sudah ada (in-app inbox)

- `web/src/app/app-shell.tsx:516` memasang `NotificationBell` (satu-satunya; komentar melarang dua lonceng karena dua langganan query).
- `notification-bell.tsx`: dropdown `DropdownMenu`, 5 baris (`per_page: 5`), badge `meta.unread` (menampilkan `99+`), polling `NOTIFIKASI_REFETCH_MS = 30_000` (berhenti saat tab tidak aktif, `refetchIntervalInBackground` default false), “Tandai semua dibaca”, tautan “Lihat semua notifikasi” → `/notifikasi`.
- `notifikasi-page.tsx`: tombol filter **Semua** / **Belum dibaca** (`?unread=true`, tombol nonaktif bila `unread===0`), “Tandai semua dibaca”, teks “{n} belum dibaca”.
- `notifikasi-list.tsx`: `<ul>` baris `{label tipe, judul, isi, waktu, “Tandai dibaca”/“Dibaca”}`, paginasi (`Pagination`), `SkeletonRows`/`ErrorState`/`EmptyState`. **`tautan` dirender sebagai teks mono, bukan tautan** (karena path API akan 404 bila dipakai sebagai rute SPA) — belum ada pemetaan deep-link.
- Route `/notifikasi` ada di `router.tsx:345` di dalam `AppShell` + `RequireAuth`; `errorElement` ada.
- `labelTipeNotifikasi` memetakan 7 enum ke label Indonesia; `formatWaktu` dipakai untuk `dibuat_at`.

## 6. Yang TIDAK ada (gap Fase 0)

1. **Pengingat terjadwal**: 0 hasil pencarian `pengingat|reminder|preferensi|quiet|dnd|scheduled` di `routes/api.php`; 0 model/migrasi bernama pengingat/reminder; tidak ada scheduler/queue job pengingat. (Diperiksa 2026-10-02.)
2. **Preferensi kanal**: tidak ada tabel/endpoint; `notifikasi` tidak punya kolom kanal.
3. **Status pengiriman yang bisa dibaca pengguna**: hanya baris log (`notifikasi.push.*`); tidak ada endpoint/kolom.
4. **Jam tenang (quiet hours)**: tidak ada di mana pun.
5. **Arsip/hapus notifikasi**: tidak ada kolom soft-delete dan tidak ada endpoint destroy (`NotifikasiController` hanya 3 route).
6. **Deep-link**: `tautan` API tidak dipetakan ke rute SPA; baris tampil sebagai teks.
7. **Web push**: tidak ada `serviceWorker`/`PushManager`/`Notification.requestPermission` di `web/src`; FCM hanya untuk aplikasi mobile (`user_devices.platform` termasuk `web`, tetapi tidak ada implementasi web push).
8. **UI perangkat**: halaman/komponen untuk `GET/POST/DELETE /auth/devices` tidak ada di `web/src` (hanya muncul di tipe hasil generate).
9. **Privasi body push**: `bookingDibatalkan` menyalin `alasan` (teks bebas, bisa memuat informasi klinis) ke `isi` yang dikirim sebagai body FCM; tidak ada penyaringan.
10. **RBAC**: `perawat` dan `kurir` tidak punya grant `notifikasi.lihat` → 403 permanen (dicatat di docblock controller; diperbaiki lewat data `RbacCatalog`, bukan UI).
