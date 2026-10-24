# Laporan Recon — Multi-Portal Sehatly (Fase 0)

> **Status:** read-only recon + rekonstruksi.
> **Hierarki sumber (dipakai berurutan):** kode aktual → dokumen repo → plan v2 (`docs/update-plan-pilihan-a.md`).
> **Aturan:** konflik **tidak** diselesaikan diam-diam; dicatat lalu diputuskan di GERBANG 1.
>
> ⚠️ **CATATAN REKONSTRUKSI (2026-10-18).** Versi pertama laporan ini hilang saat insiden workspace
> (lihat §0). Dokumen ini direkonstruksi dari fragmen halaman bebas DB sesi + catatan sesi, sehingga
> tabel temuan `F-01…F-26`, daftar konflik `K-1…K-7`, dan tabel keputusan `DG1…DG12` dipertahankan
> semirip mungkin dengan aslinya. Bagian yang tidak dapat dipulihkan ditandai **[rekonstruksi]**.
> Fakta baseline di §3/§10 sudah diperbarui dengan keadaan repo **per 2026-10-18 17:30** dan dapat
> berbeda dari angka saat laporan pertama ditulis.

---

## 0. Insiden Workspace & Konteks Berjalan (baru)

Saat Fase 0 hampir selesai, terjadi dua hal yang mengubah peta:

1. **Kontensi penulis lain.** Ada pekerja/agent lain yang menulis ke repo yang sama (`main`):
   commit baru lalu di-push, plus satu suite `php artisan test` berjalan paralel di
   `telemedisin_db_test` (kontensi murni: `1050 Table already exists`, `1146 …migrations doesn't exist`,
   `1824 Failed to open the referenced table 'master_kabupaten_kota'`).
2. **Reset workspace.** `vendor/` (sebagian), `frontend/node_modules`, `frontend/dist`,
   `tools/openapi-types/node_modules`, dan `packages/**/.dart_tool` sempat hilang, dan index git
   berubah dari 16.743 entri ter-stage → bersih. Semuanya **sudah dipulihkan** (composer/npm install),
   dan deliverable `docs/portal-recon-report.md` direkonstruksi di sini.

**Commit yang masuk ke `main` (dan `origin/main`) selama Fase 0** — semuanya menyentuh tepat
pekerjaan WS-0.5/WS-1 yang direncanakan:

| SHA | Judul |
|---|---|
| `0096bba3` | docs(openapi): regenerate spec for admin.dokter.store (120 → 121 routes) |
| `4f632fb5` | chore(types): regenerate api.d.ts for admin.dokter.store (121 routes) |
| `6a5ca473` | test(contract): pin the route census to 121 operations and 92 bearer |
| `23692676` | test(rbac): pin the multi-portal route census and the seeded role grants |
| `f2293436` | style: apply Pint to the seven files lint.yml still rejects |

Konsekuensi: **F-12 (kontrak merah), F-13/F-14 (hijau-kan), dan sebagian DG1 sudah dikerjakan
di `main`** — statusnya perlu diverifikasi ulang (lihat §10), bukan diasumsikan hijau.

---

## 1. Ringkasan Eksekutif

Yang **sudah ada dan benar** jauh lebih banyak daripada dugaan v1: RBAC multi-portal, migrasi ENUM
`marketing`, DDL `telemedicine_test.sql` yang sudah sinkron, guard `tipe:`/`permission:`, backend
**Kurir** yang lengkap + teruji, FCM (`fcm_token` + `POST /auth/devices`), dan kerangka enam portal FE.

Yang **benar-benar belum ada**: **backend Marketing (WS-4)** dan beberapa gap FE (lazy-load D1,
guard DEV D6, `frontend/src/lib/api/marketing.ts`, 3 halaman marketing masih placeholder).

Recon menemukan **masalah baseline** yang harus diputuskan lebih dulu:

1. **Kontrak API merah di `main` (saat recon).** Aplikasi mengekspos **121** operasi `/api/v1`,
   sedangkan `docs/openapi.yaml` ter-commit hanya **120** (tanpa `POST /api/v1/admin/dokter`), dan
   `tests/Contract/SpecRouteParityTest.php:83` mem-pin `120`. → **sejak itu sudah diperbaiki** oleh
   commit `0096bba3` / `4f632fb5` / `6a5ca473` (pin 121 + 92 bearer).
2. **`tests/Unit/Console/OpenApiCommandTest.php:265,269` menjalankan `sehatly:openapi` tanpa `--check`**
   sehingga **menulis ulang** `docs/openapi.yaml` (berkas *tracked*) saat test.
3. **Pint merah 8 berkas**, semuanya **pre-existing di `main`** (bukan akibat pekerjaan kita).
   → commit `f2293436` mengklaim memperbaiki ("seven files") — perlu diverifikasi.

**Jalur kritis pekerjaan:** WS-0.5 → WS-1 → WS-4 → WS-5 → WS-7 (Marketing) → WS-9.

---

## 2. Sumber, Metode, dan Batas **[rekonstruksi]**

- **Recon statis R1–R20**: pembacaan `routes/api.php`, RBAC catalog, migrasi, guard middleware,
  `config/*`, FE `app/`+`lib/`+`features/`, seeder, dan CI workflow.
- **3 delegasi subagent read-only**: (a) frontend portal/guard/router, (b) kurir + marketing + FCM +
  seeder, (c) kontrak/Dart.
- **Ringkasan policy docs**: `docs/pre-existing-defects.md`, `docs/contract-conformance.md`,
  `docs/schema-notes.md`, `docs/migration-order.md`.
- **Batas**: pekerjaan besar yang **belum ada** di repo: backend Marketing (WS-4) dan
  `frontend/src/lib/api/marketing.ts`. `packages/sehatly_api_client` kosong (tanpa `pubspec.yaml`),
  dan CI melarang `mobile/` → tidak ada pekerjaan mobile yang bisa dikerjakan sekarang.
- **Tidak dijalankan**: dua suite test paralel pada DB test yang sama (aturan anti-kontensi).

---

## 3. Baseline Quality Gate

**Backend**

- `php artisan sehatly:verify-schema` → **PASS (0 drift, 9 informational)**.
- `pint --test` → **FAIL 8 berkas**, seluruhnya pre-existing di `main`:
  `BuatDokterRequest.php`, `StoreBookingRequest.php`, `KurirPesananResource.php`,
  `PasienAlergiResource.php`, `AdminDokterService.php`, `KurirPesananService.php`, `NikMasker.php`,
  `AdminSeeder.php`.
- **Kontrak**: live `/api/v1` = **121** operasi (diverifikasi via `route:list --path=api/v1 --json`
  dan skrip penghitung yang identik dengan test) vs doc HEAD 120; pin test 120.
- `docs/openapi.yaml` (working tree saat itu) 121 ops, byte-identik dengan hasil render
  (`sha256 b77e8de76281ebe7bd098576f363f85bee8b361ba75c262e833c2245431781f6`, 464.117 byte)
  → **ada perbaikan yang belum di-commit**.
- `tests/Contract/SpecRouteParityTest.php:83` → `expect($operations)->toBe(120)`.
- `tests/Unit/Console/OpenApiCommandTest.php:265,269` → `Artisan::call('sehatly:openapi')`
  **tanpa** `--check` → menulis ulang berkas tracked.

**Frontend** (baseline yang terukur waktu itu)

- `types:check` → PASS.
- `test:unit` → PASS 127/127.
- `build` → PASS tetapi **satu chunk 1,45 MB** → bukti D1 (lazy-load per portal) belum ada.
- E2E → **tidak dijalankan** (browser Playwright belum diunduh).

**Kontrak API**

- `SpecRouteParityTest` merah pada saat recon; **status terbaru: pin 121** (`0096bba3`…`6a5ca473`).

### Hasil verifikasi ulang WS-0.5 (2026-10-18, `feat/multi-portal-v2` = `f2293436`)

| Item | Perintah | Hasil |
|---|---|---|
| Format | `vendor\bin\pint --test` | ✅ **PASS** (exit 0) — F-14 tuntas |
| Skema | `php artisan sehatly:verify-schema` | ✅ **PASS** — 0 drift, 9 informational |
| Kontrak | `php artisan sehatly:openapi --check` | ✅ **UP TO DATE** — 121 operations / 104 paths, sha256 `b77e8de76281ebe7bd098576f363f85bee8b361ba75c262e833c2245431781f6`, 464.117 byte |
| Pin kontrak | `tests/Contract/SpecRouteParityTest.php:83` | ✅ `toBe(121)` |
| Kontrak + generator | `php artisan test tests/Contract tests/Unit/Console/OpenApiCommandTest.php` | ✅ **310/310 PASS**, 6.314 assertion, ≈31 s |
| Suite penuh | `php artisan test` | ✅ **1.573/1.573 PASS**, 26.966 assertion, ≈18 m 33 s |
| Integritas setelah test | `git status --porcelain` + `Get-FileHash docs/openapi.yaml` | ✅ bersih; `docs/openapi.yaml` tetap `b77e8de7…` |

**Deviasi sadar dari rekomendasi §DG1 (F-13).** Rekomendasi awal: buat `OpenApiCommandTest`
tidak menulis berkas tracked sama sekali (pakai `--out` ke temp). Implementasi yang ada sekarang
menulis `docs/openapi.yaml` **lalu memulihkannya di `finally`**, sebagai bagian dari pola
"mutation transcript" yang terdokumentasi di header berkas — tujuannya justru membuktikan
drift-check bisa **merah** nyata. Karena `--check` + restore-nya terbukti menjaga tree bersih
(lihat baris integritas di atas), tes **dipertahankan**; mengubahnya ke temp akan menurunkan
kekuatan bukti tes tersebut. Butuh persetujuan bila Anda ingin tetap di-hardening.

---

## 4. Temuan (dengan bukti)

Status: **✅** sesuai plan · **⚠️** ada tapi perlu dirapikan/perbaiki · **❌** belum ada / bertentangan.

| ID | Temuan | Bukti | Status vs plan | Tindakan |
|---|---|---|---|---|
| F-01 | Migrasi `000089` menambah `marketing` di akhir ENUM; `down()` mengembalikan 7 anggota (lossy, sengaja) | `database/migrations/2026_10_01_000089_add_marketing_to_users_tipe.php` | ✅ | Skip (verify) |
| F-02 | `RbacCatalog`: USER_TYPES 8 (marketing terakhir), ROLES punya kurir+marketing, 5 permission di-append di akhir, superadmin 33 izin | `app/Support/Rbac/RbacCatalog.php:245-248,297-303,368-383,407-445` | ✅ | Skip (verify) |
| F-03 | DDL `users.tipe` di `telemedicine_test.sql` **sudah** memuat `marketing` (sinkron) | `telemedicine_test.sql` (CREATE TABLE users → kolom `tipe`, via R6); `docs/openapi.yaml:8251`; `docs/enums.json:478` | ✅ | Skip |
| F-04 | Guard `tipe:`/`permission:` benar; `tipe:` menerima multi-nilai (any-of); **tanpa** bypass superadmin | `bootstrap/app.php:192-195`; `EnsureUserType.php:26,68,78`; `EnsurePermission.php:26,99` | ✅ | Skip |
| F-05 | `bootstrap/app.php` **tanpa** `statefulApi()` → auth murni bearer; daftar stateful inert | `bootstrap/app.php`; `config/sanctum.php:21-35` | ⚠️ (D4) | Verify, jangan tambah stateful |
| F-06 | CORS **default** memuat origin dev (`localhost:5173/5174`) dan semua host `.test` | `config/cors.php:42-65` | ❌ **melawan D5** | Build (WS-2) |
| F-07 | Login nomor tak dikenal **auto-membuat akun `pasien`**; tidak ada param `portal` | `AuthController.php:299-333,1012-1032` | ⚠️ (D3 gap) | Putuskan DG5 |
| F-08 | Backend Kurir lengkap & teruji: antrean bersama (**tanpa** kepemilikan per-kurir by design), akses luar lingkup → **404** (bukan 422), double-take aman via lock | `KurirPesananService.php:63-71,138-146`; `KurirPesananTest.php:314-327` | ✅ (menggugurkan §5.2 v1) | Verify |
| F-09 | `KurirPesananResource` menahan `pasien_id`/resep/harga dan **tidak** memakai masker — karena tak ada PII untuk dimasker | `KurirPesananResource.php:16-32,52-64` | ⚠️ (D9) tapi plan literal minta "pakai masker" | Verify (konflik K-3) |
| F-10 | Notifikasi pasien saat ambil/antar: **TIDAK ADA** (`KurirPesananService` tak memakai `NotificationService`) | `KurirPesananService.php` (import 5-11, 89) | ❌ vs WS-3 | Putuskan DG9 |
| F-11 | Backend Marketing: **tidak ada** controller/service/route/test. Hanya model `Artikel`, `ArtikelKategori`, `MasterPromo`, `PromoRedemption` + `PromoService` (untuk invoice) | glob `**/*Marketing*` kosong; `routes/api.php` (tak ada `tipe:marketing`) | ❌ | **Build** (WS-4) |
| F-12 | **Kontrak merah**: live 121 ops vs doc HEAD 120; pin test 120 | lihat §3 | ❌ | Perbaiki (DG1) — **sudah dikerjakan di `main`** |
| F-13 | Test **menulis ulang** `docs/openapi.yaml` (tanpa `--check`) | `tests/Unit/Console/OpenApiCommandTest.php:265,269` | ⚠️ hygiene | Perbaiki (DG1) |
| F-14 | Pint merah 8 berkas, pre-existing di `main` | §3 | ⚠️ | Perbaiki (DG1) — **claim commit `f2293436`** |
| F-15 | Build FE = **satu chunk 1,45 MB** → D1 (lazy-load per portal) belum ada | output build; `frontend/src/app/router.tsx:3-8` (import statis) | ❌ vs D1 | Build (WS-6) |
| F-16 | `?portal=` dihormati **tanpa** guard `import.meta.env.DEV`; E2E bergantung padanya | `frontend/src/lib/portal.ts:96-106`; `router.tsx:23-27` | ⚠️ vs D6 | Putuskan DG4 |
| F-17 | `portalOrigin()` **dan** `portalBeranda()` ada (prefix-swap, tanpa env var) | `portal.ts:138-150` | ✅ (sebagian WS-6) | Skip (verify) |
| F-18 | `RequirePortalAuth` benar: tanpa token → `/login` (bukan `/?masuk=1`); tipe salah → `PortalSalahPage` (gaya-404), **bukan** `ForbiddenState` | `guards.tsx:171-221`; alasan di `guards.tsx:158-164` | ⚠️ vs WS-6 literal | Verify (konflik K-2) |
| F-19 | `admin-gate.tsx` = duplikat fungsional role-check admin (dipakai 7 halaman); tombolnya menuju `/dashboard` yang **bukan** route portal admin | `features/admin/admin-gate.tsx:33-66`; `admin-routes.tsx` (tak ada `/dashboard`) | ⚠️ | Putuskan DG8 |
| F-20 | FE Marketing: 3 halaman placeholder; `lib/api/marketing.ts` **tidak ada** | recon frontend; `frontend/src/lib/api/` | ❌ | Build (WS-7) |
| F-21 | FE Kurir: 3 halaman memakai `lib/api/kurir.ts` **nyata** | recon frontend; `frontend/src/lib/api/kurir.ts` | ✅ | Skip (verify) |
| F-22 | Seeder **tidak** membuat akun demo `kurir`/`marketing`; `DemoAkunTest` hanya uji 3 akun | `DemoDataSeeder.php:121,174-175`; `DemoAkunTest.php` | ❌ vs WS-1 | Build (DG10) |
| F-23 | `user_devices.fcm_token` **ada**; `POST /auth/devices` menyimpannya | migrasi `000018_user_devices_table.php:29`; `AuthController.php:850,860` | ✅ (§5.5 FCM) | Skip (verify) |
| F-24 | `packages/sehatly_api_client` hanya `.dart_tool` (tanpa `pubspec.yaml`/`lib/`) → klien Dart tidak ada; CI melarang `mobile/` | glob; `.github/workflows/contract.yml:104-112`; `tools/check-doc-links.mjs:994-997` | ❌ (tak ada kerja mobile) | Catat backlog (DG12) |
| F-25 | Sampah git **ter-stage** (belum di HEAD): `web/` 16.739, `.dart_tool` 4; `.gitignore` tak mengabaikan `web/**` & `**/.dart_tool` | `git diff --cached --name-only`; `.gitignore` | ⚠️ hygiene | Putuskan DG11 |
| F-26 | Tidak ada spec E2E per portal; browser Playwright belum terunduh **[rekonstruksi — ekor baris terpotong di fragmen]** | `tests/e2e/akun.ts`; kondisi environment | ❌ vs WS-9 | Build (WS-9) |

---

## 5. Konflik (kode vs dokumen vs plan)

> Sesuai aturan: konflik **tidak** diselesaikan diam-diam; dicatat dan diputuskan di GERBANG 1.

- **K-1 — D6 (`?portal=` DEV-only) vs jalur E2E yang ada.** Plan (D6, "Final") ingin `?portal=` hanya
  di DEV. Kenyataannya `?portal=` **adalah** mekanisme E2E saat ini (Playwright memakai satu origin
  `SEHATLY_BASE_URL`): `router.tsx:23-27`, `portal.ts:12-20`. Membatasinya ke DEV akan **mematahkan
  E2E berjalan** kecuali disediakan jalur lain (mis. override build-time yang tidak ada di bundle
  produksi). → **DG4**.
- **K-2 — WS-6 "tipe salah → `ForbiddenState`" vs implementasi `PortalSalahPage`.** Kode sengaja
  memilih `PortalSalahPage` (menjawab seperti 404, tidak membocorkan tipe yang diinginkan) — keputusan
  pemilik, `guards.tsx:158-164`. → **verify, jangan "perbaiki" ke `ForbiddenState`** tanpa persetujuan.
- **K-3 — WS-3 "resource memakai masker" vs kenyataan.** Tidak ada PII untuk dimasker karena
  `KurirPesananResource` menahan identitas pasien/resep/harga sepenuhnya. → masker tidak relevan;
  **verify**.
- **K-4 — §6 tabel marketing tanpa `dokter.lihat` vs kode memberi `dokter.lihat`** (beralasan: portal
  menampilkan direktori dokter yang diiklankan), `RbacCatalog.php:373-382`. → **DG3b**.
- **K-5 — D5 "default prod CORS bersih" vs kode menaruh origin dev di default**
  (`config/cors.php:42-65`). → **DG7**.
- **K-6 — Dokumen kontrak usang.** `docs/contract-conformance.md` menyebut **74** operasi dan split
  auth 148; test menyebut **120**. Dokumen kalah dari kode/test. → catat; jangan jadikan acuan angka.
- **K-7 — Pin test 120 vs live 121** (§3/F-12). → **DG1**.

---

## 6. Keputusan yang Dibutuhkan (GERBANG 1)

> Rekomendasi saya tercantum; yang perlu **keputusan Anda** ditandai.

| ID | Keputusan | Rekomendasi |
|---|---|---|
| **DG1** | **Baseline merah**: kontrak (pin 120→121 + doc usang + `OpenApiCommandTest` menulis file tracked) + Pint 8 berkas. Perbaiki dulu sebagai commit terpisah? | **YA** → tambahkan **WS-0.5 (perbaikan baseline, path-scoped, commit terpisah)**: regenerate `docs/openapi.yaml`; ubah pin test `120→121`; buat `OpenApiCommandTest` tak menulis file tracked (pakai temp); `pint` 8 berkas. Tanpa ini, kegagalan "baru" tak bisa dibedakan dari cacat lama. |
| **DG2** | Backend Marketing: apakah `superadmin` ikut lolos (`tipe:marketing,superadmin`) atau hanya `tipe:marketing`? | Ikutkan `superadmin` **hanya bila** konvensi lain menuntut; default `tipe:marketing` + `permission:`. |
| **DG3** | (a) kurir `pesanan.lihat` | (a) **sudah benar dicabut** → (skip). |
| | (b) marketing `dokter.lihat` | (b) **pertahankan** (ada alasan nyata & sudah didokumentasikan) atau **hapus** agar persis §6. |
| **DG4** | D6 `?portal=` DEV-only: implementasikan? Menyentuh E2E. | Implementasikan **DEV-only** + sediakan jalur E2E non-produksi. Butuh persetujuan karena mengubah E2E. |
| **DG5** | D3 hint `portal` di login (cegah auto-register pasien dari portal staf & cegah OTP ke nomor staf salah portal). | Implementasikan `portal` opsional; bila tipe ≠ tipe portal → 401 generik **tanpa** OTP. Boleh ditunda tapi disarankan masuk WS-2. |
| **DG6** | D4 stateful Sanctum. | **Biarkan inert** (jangan tambah `statefulApi()`); cukup tegaskan komentar bearer-only. |
| **DG7** | D5 CORS: pindahkan origin dev/`.test` dari default ke env. | **YA** (hardening; default prod bersih + perbarui `.env.example`). |
| **DG8** | `admin-gate.tsx`: (i) tombol `/dashboard` salah di portal admin; (ii) duplikasi vs `RequirePortalAuth`. | (i) perbaiki → `PORTAL_HOME.admin`. (ii) **pertahankan** sebagai defence-in-depth (jangan lebur; blast radius 7 halaman) tetapi samakan UI refusal. |
| **DG9** | WS-3 notifikasi pasien saat ambil/antar (belum ada). | Tambahkan lewat `NotificationService` (kecil) — atau tandai ditunda. |
| **DG10** | Akun demo `kurir` & `marketing` di seeder + perluas `DemoAkunTest`. | **YA** — tanpa akun, WS-6/7/9 tak bisa diuji. |
| **DG11** | Sampah git (16.743 entri ter-stage). | Tambah `.gitignore` (`web/**`, `web/dist`, `**/.dart_tool/`); **unstage** butuh izin (menyentuh index). Commit tetap **path-scoped**. |
| **DG12** | Klien Dart. | Tidak ada → WS-5 hanya web (openapi/enums/api.d.ts). Catat sebagai backlog mobile. |

---

## 7. Kebersihan Repo (R20)

- 16.743 entri **ter-stage** (`git diff --cached`), **tidak ada** di `HEAD` (semua status `A`) →
  **histori git bersih**; risiko hanya bila seseorang menjalankan `git add -A`/`git commit -A`.
- `.gitignore` belum mengabaikan `web/**` dan `**/.dart_tool/` → perlu ditambah (DG11).
- **Per 2026-10-18 17:30**: index sudah bersih (0 staged/0 modified) setelah insiden §0; 1.146 berkas
  tracked lengkap; `.env` utuh (`APP_ENV=local`, `DB_DATABASE=telemedisin_db`; phpunit → `telemedisin_db_test`).

---

## 8. Rencana WS Terkoreksi (setelah keputusan)

> Aturan kepemilikan berkas bila paralel: `router.tsx`/`guards.tsx`/`portal.ts` hanya agen FE WS-6.

| WS | Tindakan | Inti |
|---|---|---|
| **WS-0.5** (baru, jika DG1=YA) | **build** | Perbaiki baseline: regenerate openapi, pin 120→121, `OpenApiCommandTest` tak menulis file tracked, `pint` 8 berkas. Commit terpisah, path-scoped. |
| **WS-1** RBAC & skema | **skip/verify** | Migrasi 089 ✅, katalog ✅, DDL ✅. Sisa: seeder idempotent + **akun demo kurir/marketing** (DG10) + perluas `DemoAkunTest`. |
| **WS-2** Konfigurasi & hardening | **verify/build** | Build D5 (CORS dari env, `.env.example`). Verify D4 (jangan ubah stateful). Opsional D3 (DG5). |
| **WS-3** Backend Kurir | **verify** | Guard/scoping/state-machine/tracking ✅ teruji. Gap: notifikasi pasien (DG9). Masker: N/A (K-3). |
| **WS-4** Backend Marketing | **build** | Dari nol. Ikuti pola `PromoService`/`PromoHitungan` yang sudah ada; FormRequest tiap write; Resource tiap response; tanpa hard delete (arsip/status). |
| **WS-5** Sinkronisasi kontrak | **build** | Regenerate openapi/enums/api.d.ts setelah WS-3/4; perbaiki pin & `OpenApiCommandTest`. Tidak ada Dart (DG12). |
| **WS-6** Infrastruktur portal FE | **build/verify** | Build D1 (lazy-load) & D6 (DG4). Verify `RequirePortalAuth`, `portalOrigin`. Rapikan `admin-gate` link (DG8). Session-expired: sudah portal-aware → verify. |
| **WS-7** Halaman portal FE | **verify/build** | Kurir → nyata (verify). Marketing: build `lib/api/marketing.ts` + hub. |
| **WS-8** Dev/deploy (serve) | **build** | Dokumentasi jalur serve (dist 503 bila belum build); README 6 portal. |
| **WS-9** Pengujian | **build** | Test lintas-tipe (pasien→endpoint staf 403); unit portal/guard; E2E per portal (unduh browser Playwright dulu). |
| **WS-10** Ditunda | — | Payment, FCM kredensial, klien mobile, pembuatan akun staf oleh admin, pembersihan prefix path. |

**Jalur kritis:** WS-0.5 → WS-1 → WS-4 → WS-5 → WS-7 (Marketing) → WS-9.

---

## 9. GERBANG 1 — Menunggu Persetujuan

Recon selesai. Saya **berhenti di sini**.

Yang saya butuhkan dari Anda:

1. **DG1** — setujui **WS-0.5 (perbaikan baseline)** sebagai langkah pertama? *(sangat disarankan;
   sebagian sudah dikerjakan di `main` oleh penulis lain — perlu verifikasi ulang, §10).*
2. **DG2–DG11** — keputusan pada tabel §6 (terutama **DG4** & **DG6/DG3b** yang menyentuh perilaku,
   dan **DG11** yang menyentuh index git).
3. Konfirmasi **urutan WS** di §8.

Setelah persetujuan, Fase 1 dimulai dari **WS-0.5** (bila disetujui) lalu **WS-1**, dengan workflow
per-WS: delegasi → implementasi → `reviewer` read-only → quality gate → commit path-scoped.

### Keputusan GERBANG 1 — DITERIMA (2026-10-18)

| ID | Keputusan pemilik |
|---|---|
| **DG1** | **YA** — jalankan WS-0.5 (verifikasi/perbaikan baseline) sebagai langkah pertama. |
| **DG4** | **Implementasikan DEV-only** untuk `?portal=` **plus** jalur E2E non-produksi (menyentuh E2E — lihat K-1). |
| **DG2, DG3b, DG5–DG12** | **Terima seluruh rekomendasi** pada tabel §6. |

**Implikasi operasional:** Fase 1 dimulai dari WS-0.5 (selesai — lihat §3), lalu WS-1
(seeder akun demo `kurir`/`marketing` + perluas `DemoAkunTest`), lalu WS-4 → WS-5 → WS-7 → WS-9
sesuai jalur kritis §8. DG4 masuk WS-6, DG7/DG5 masuk WS-2, DG9 masuk WS-3.

---

## 10. Pembaruan Baseline Backend

- `php artisan test` (suite penuh): ✅ **1.573 tes, 1.573 pass, 26.966 assertion, ≈18 m 33 s**, dijalankan
  **serial** setelah sesi penulis lain berhenti (run pertama sebelumnya bertabrakan pada
  `telemedisin_db_test` — kontensi murni, §0). Keluaran penanda: `migrations=92`, `derived-missing
  tables=0 views=0`.
- `php artisan test tests/Contract tests/Unit/Console/OpenApiCommandTest.php`: ✅ **310/310 pass**,
  6.314 assertion, ≈31 s; `docs/openapi.yaml` tetap utuh sesudahnya.
- **WS-0.5 dinyatakan `verified` (tidak ada perubahan kode).** Seluruh itemnya sudah terpenuhi di
  `f2293436`: spec 121 + pin 121, `--check` UP TO DATE, Pint hijau, dan integritas tracked file
  terjaga. Sisa satu-satunya adalah deviasi sadar F-13 di §3 (butuh persetujuan bila ingin diubah).
- **Perkembangan dari penulis lain** (per 2026-10-18 17:30, `main` = `f2293436`):
  - `docs/openapi.yaml` sudah berisi **121** `operationId` (memperbaiki F-12).
  - `tests/Contract/SpecRouteParityTest.php:83` sudah di-pin **`toBe(121)`** (memperbaiki F-12).
  - `chore(types)` regenerate `api.d.ts` untuk 121 route.
  - `test(rbac)` pin route census multi-portal + seeded role grants.
  - `style` apply Pint ke berkas yang masih ditolak `lint.yml` — **perlu diverifikasi** apakah ke-8
    berkas dari §3 benar hijau (judul commit menyebut "seven files").
  - Semua di atas **sudah di-push** ke `origin/main`.

> **Status verifikasi laporan ini:** `verified` untuk baseline backend (§3/§10 — suite penuh hijau,
> dijalankan serial). Baseline frontend (typecheck/unit/build/E2E) di laporan ini masih dari angka
> recon dan **belum dijalankan ulang** pada sesi ini → `partially_verified` untuk bagian FE.
