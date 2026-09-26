---
slug: sehatly-telemedicine-platform
status: review_round_1_changes_folded
intent: clear
review_required: true
plan_path: .omo/plans/sehatly-telemedicine-platform.md
plan_sha256: null
review_round_id: rr-2026-09-26-a
round_status: changes_requested
pending-action: run review round 2 after folding round-1 findings
review:
  momus:
    status: in_flight
    workspace_root: C:\Users\axioo\Desktop\sehatly
    runtime_home: null
    target: .omo/plans/sehatly-telemedicine-platform.md
    round_id: rr-2026-09-26-a
    plan_sha256: null
    launch_id: launch-momus-1
    session: ses_f216e4be5ffeELt4HZophl1Np5
    result: CHANGES_REQUESTED
  independent:
    status: pending
    workspace_root: C:\Users\axioo\Desktop\sehatly
    runtime_home: null
    target: .omo/plans/sehatly-telemedicine-platform.md
    round_id: rr-2026-09-26-a
    plan_sha256: null
    launch_id: launch-oracle-1
    session: ses_f216e24b0ffeLifWhbvx6P4udZ
    result: CHANGES_REQUESTED
fix_summary_round_1: |
  FOLDED (both reviewers, independently verified against the repo and the SQL):
  - CRITICAL - the NIK design was arithmetically impossible. AES-256-CBC ciphertext of a 16-byte NIK is 32 bytes (44 chars base64 / 64 hex) and cannot fit `CHAR(16)`; MySQL 8 strict mode raises 1406. Task 50 REWRITTEN: add `nik_cipher TEXT` (random-IV, correct crypto) + `nik_hash CHAR(16)` (16-char HMAC, carries the UNIQUE), keep the original column for legacy rows, mask by decrypting. Recorded in schema-notes per spec 4.4. The originally approved Q3=A is therefore REVISED.
  - CRITICAL - `dihapus_at` contradiction removed. SQL `:148`/`:249` are `TIMESTAMP NULL`. Scope MUST-NOT, task 9 body, and task 9's acceptance criterion all corrected to `$table->softDeletes('dihapus_at')`.
  - CRITICAL - `booking.status` has 8 values, not 7. Task 11, 28, 42 corrected.
  - CRITICAL - the booking lock had TWO serialization points (`dokter_jadwal` vs `dokter`), so a scheduled and an instant request for the same doctor and time could BOTH succeed. Now always locks `dokter` first, `dokter_jadwal` second. Added a mixed-path concurrency test (2 with `jadwal_id` + 3 without) that the old design would fail.
  - CRITICAL - refresh rotation had no `lockForUpdate()`, so two concurrent refreshes both minted tokens and reuse detection was defeated. Fixed, plus revoke-all on reuse detection (no `device_id` column exists).
  - CRITICAL - `POST /auth/register` could not insert a `pasien` row: `jenis_kelamin`, `tanggal_lahir`, `alamat_lengkap` are NOT NULL with no default (MySQL 1364). Register now collects all three.
  - CRITICAL - logout never revoked FCM device tokens, so a logged-out device kept receiving medical push. Now sets `user_devices.aktif = 0` and adds `DELETE /auth/devices/{deviceId}`.
  - CRITICAL - the webhook had no defined signature scheme and no forged-request test. HMAC-SHA256 over the raw body defined, `{gateway}` validated against the 4-value ENUM, two new tests added.
  - CRITICAL - task 18's removal list was self-contradictory and left the passkeys + 2FA migrations, which would fail `sehatly:verify-schema`. Explicit delete/keep lists plus `composer remove laravel/passkeys`.
  - CRITICAL - `resources/js/app.tsx` was never rewritten, so after Inertia removal the SPA would never mount. Now `createRoot` + router + alias re-point, and the wayfinder/actions/routes generated dirs are deleted.
  - MAJOR - promo quota was counted without a lock (real financial-loss race). Now `lockForUpdate()` on `master_promo`.
  - MAJOR - the timezone policy conflated machine-written UTC with operator-written local `*_at`, and missed `master_promo.mulai_at`/`selesai_at` (every promo would fire 7 hours off). Policy rewritten with explicit instant list, allow-list-based assertion, and a 7-hour regression test.
  - MAJOR - todo 1's acceptance criterion did not assert the safety property the whole dirty-worktree guard relies on. Now asserts `git ls-files --others --exclude-standard | wc -l` == 0, with a failure scenario proving `git commit -am` would NOT satisfy it.
  - MAJOR - the toolchain was never established: PHP on PATH is 8.2.29 but `composer.json:12` requires `^8.3`; no Dart SDK; no `jq`; no Playwright. Task 1 Part 0 added, with an explicit halt if Dart is unavailable and a ban on lowering the PHP constraint.
  - MAJOR - `v_dokter_katalog` aliases its PK as `dokter_id`, so Eloquent's default `id` does not exist. `$primaryKey` now specified.
  - MAJOR - the global "no unmasked NIK" rule had one enforcement point while 5 Resources eager-load `pasien`. A data-driven sweep across every GET route was added.
  - MAJOR - the audit observer could duplicate PHI into `audit_log` and hash the NIK into a permanent linkable identifier. Both now have failing-if-regressed tests.
  - MINOR - task 28's duplicated QA block removed; wave counts corrected (W3 = 4, W6 = 7); referensi 13 -> 14; UI components 33 -> 26; mojibake fixed (`n 谢iktropika`, `kons Boscoultasi`, `N masker`, `Accept-Refusing`); task 49's library pinned to `opis/json-schema` ^2.4; the `justfile`/composer slash resolved; `jq` replaced with a portable `php -r` count; `nomor_booking` UNIQUE collision retry added.
  NOT YET FOLDED (disclosed to the user):
  - ~35 off-by-one `telemedicine_test.sql` line citations: `status_dokumen` :645 not :654, `versi` :646 not :655, `konsultasi.booking_id` :538 not :539, `idx_konsultasi_pasien` :560 not :561, `uq_interaksi` :739 not :738, `berlaku_sampai` :755 not :761, `resep.status` :751 not :750, `resep_verifikasi.status` :790 not :789, `promo_redemption.invoice_id` :1004 not :1005, `idx_bayar_status` :972 not :970, `idx_invoice`/`idx_ref` :954-955 not :953-954, CHECKs :1055-1057 not :1052-1054, `nama_alergen` :278 not :277, `idx_icd10`(riwayat) :297 not :291, `hari` comment :475 not :474, `idx_jadwal` :487 not :479, `idx_booking_dokter` :528 not :527, `idx_diag_icd10` :666 not :665, `idx_notif` :1047 not :1045, `dokter_jadwal` fields :473-487 not :474-479, `pasien_riwayat_penyakit` index :297, `master_spesialisasi` seed :1236-1252 not :1234-1240, `input-otp` package.json:37 not :27, `User.php` Passkey imports :13,14,35 not :26,32,34, `composer.json` require :12-17 not :14-18, `idx_pasien_lahir` :255 not :257, `dokter.biaya_konsultasi_online` :420 not :425.
  - Remaining MINOR/MAJOR items: no `dokter_jadwal` write endpoint (Modul 2's calendar is read-only in production and only the dev fixture has a schedule); `apotek_stok` may have no row for a given (apotek, obat) pair so the guarded decrement rejects a legitimately stocked drug; CORS `allowed_origins` / `supports_credentials` unspecified (an executor could set `*` + credentials, a real vulnerability); OTP resend enables a victim-lockout DoS; `user_otp.tujuan` is not verified on the verify call; no SMS gateway or queue worker exists in the environment.
  The plan has NOT passed review. A round 2 is required.
approach: Root Laravel 13 becomes a pure /api/v1 Sanctum token API on MySQL 8; a React 19 SPA in web/ consumes it; 75 migrations mirror telemedicine_test.sql 1:1 with an information_schema parity verifier; five module waves (Auth, Booking, Konsultasi, Resep, Pesanan/Audit) each ship backend + web + Pest tests; NO Flutter app - the mobile team is served by a generated contract (OpenAPI 3.1 + pure-Dart client package + integration guide + enum catalogue + contract-conformance suite); a cross-cutting compliance wave closes with NIK encryption, timezone policy, throttling and a final full-suite run.
---

# Draft: sehatly-telemedicine-platform

> PLANNER ARTIFACT NOTE: the ulw-plan scaffold script (`scripts/scaffold-plan.mjs`) could not be
> executed because this session exposes no shell/process-execution tool. The draft and plan
> templates below are reproduced byte-faithfully from `buildDraft()` / `buildPlanSkeleton()` in
> that script (read at
> `C:\Users\axioo\.cache\opencode\packages\oh-my-openagent@latest\node_modules\oh-my-openagent\dist\skills\ulw-plan\scripts\scaffold-plan.mjs:154-286`).
> Section headers are kept verbatim in template order.

## Components (topology ledger)
<!-- Lock the SHAPE before depth. One row per top-level component that can succeed or fail independently. -->
<!-- id | outcome (one line) | status: active|deferred | evidence path -->

| id | outcome (one line) | status | evidence path |
| --- | --- | --- | --- |
| C0 | Environment + repo re-topology: decide where backend/web/mobile live and what happens to the fused Inertia starter kit | active | `bootstrap/app.php:11-29`, `composer.json:14-18`, `package.json:13-47`, `routes/web.php:1-11`, `resources/js/app.tsx:1-40` |
| C1 | Schema fidelity layer: 75 Laravel migrations that match `telemedicine_test.sql` 1:1 (columns, types, FK, index, unique, CHECK, views) + seeders | active | `telemedicine_test.sql:58-1160`, `database/migrations/` (only 4 files today) |
| C2 | Auth & RBAC: Sanctum bearer tokens, OTP login, refresh-token rotation, device registration, `permission:` middleware on `roles`/`permissions` tables | active | `telemedicine_test.sql:132-216`, `config/auth.php:18-49`, `vendor/laravel/` (no sanctum) |
| C3 | API kernel: `/api/v1` routing, uniform success/error envelope, API Resources, ISO-8601 UTC, pagination meta, CORS, soft delete on `dihapus_at` | active | `bootstrap/app.php:12-16` (no `api:`), `bootstrap/app.php:26-29`, no `config/cors.php` |
| C4 | Domain services M2-M5: slot availability + booking lock, consultation chat broadcast, SOAP/rekam-medis versioning, drug-interaction & allergy engine, invoice/payment/promo, PDP consent + audit observers | active | `telemedicine_test.sql:470-534`, `:536-619`, `:621-706`, `:708-845`, `:936-1034`, `:1118-1157` |
| C5 | React web client for pasien/dokter/apoteker/admin against real `/api/v1` endpoints | active | `resources/js/` (Inertia SSR only, 12 pages) |
| C6 | Flutter + GetX mobile client for pasien & dokter with live chat + FCM push | active | no `mobile/` dir; no Flutter SDK found on this machine |
| C7 | Compliance: `audit_log` observer coverage, `akses_rekam_medis_log` on every read, `persetujuan_pdp` gates | active | `telemedicine_test.sql:1118-1157` |
| C8 | Verification: Pest feature tests per module (happy + failure), migration-vs-SQL parity check, agent-executed QA | active | `tests/Pest.php:17-19`, `composer.json:26-28` |

## Open assumptions (announced defaults)
<!-- Record any default you adopt instead of asking, so the user can veto it at the gate. -->
<!-- assumption | adopted default | rationale | reversible? -->

- Research fan-out status at time of writing: 4 background lanes dispatched (Laravel 13 API stack, Flutter/GetX stack, React SPA stack, internal repo map). Their findings are NOT yet folded in; this draft must be re-read and updated before the approval gate.
- Test framework: **Pest 4** (`pestphp/pest ^4.0` + `pest-plugin-laravel ^4.0` already in `composer.json:26-28`, `tests/Pest.php` already bootstrapped). The spec allows "Pest atau PHPUnit"; the repo already answers it. Rationale: evidence over preference. Reversible: yes (high effort to switch).

## Findings (cited - path:lines)

> Provenance: F1-F8 verified by the planner with direct Read/Glob/Grep. F9-F13 come from the
> `explore` lane (session `ses_f21b0fc80ffeRNICd5XPBXYQIJ`) and are **claims until independently
> re-verified**; each one that changes the plan is re-checked below and marked CONFIRMED or
> CORRECTED.

### F1. The workspace is NOT empty - it is a Laravel 13 + Inertia + React starter kit fused at the repo root
- `composer.json:14-18` - requires `php ^8.3`, `laravel/framework ^13.17`, `inertiajs/inertia-laravel ^3.0`, `laravel/fortify ^1.37.2`, `laravel/wayfinder ^0.1.14`. **Laravel 13 is real and already installed.**
- `composer.json:26-28` - dev deps already include `pestphp/pest ^4.0` and `pestphp/past-plugin-laravel ^4.0`, `laravel/pint`, `mockery`.
- `composer.json:3` - package name is still the untouched scaffold name `laravel/react-starter-kit`.
- `package.json:14-16` - `@inertiajs/react ^3.0.0` + `@inertiajs/vite ^3.0.0`. `package.json:31-34` - `laravel-vite-plugin ^3.0.0`.
- `package.json:15,28-29,43` - React 19.2, TypeScript 5.7, Vite 8, Tailwind 4.
- `resources/js/app.tsx:1,11` - `createInertiaApp(...)`. The web client is **server-rendered Inertia**, not a JSON API consumer.
- `routes/web.php:5,8` - `Route::inertia('/', 'welcome')`, `Route::inertia('dashboard', 'dashboard')`. Only 11 lines total; `routes/settings.php` is required at line 10.
- `resources/js/pages/**` - 12 Inertia pages: `welcome`, `dashboard`, `settings/{security,profile,appearance}`, `auth/{login,register,forgot-password,reset-password,confirm-password,two-factor-challenge,verify-email}`.
- `resources/js/components/ui/**` - 33 shadcn/Radiv UI components already present (button, card, dialog, input, input-otp, select, sidebar, sonner, table-less, etc.). `components.json` present. This UI kit is reusable by a non-Inertia SPA.
- `vite.config.ts:12-30` - plugin chain `laravel()`, `inertia()`, `react()`, `babel(reactCompilerPreset)`, `tailwindcss()`, `wayfinder({formVariants:true})`.

### F2. There is NO API layer at all
- `bootstrap/app.php:12-16` - `withRouting(web: routes/web.php, commands: routes/console.php, health: '/up')`. **No `api:` key** - Laravel is not even loading an API route file. `php artisan install:api` has never been run.
- `routes/api.php` **does not exist** (glob over `routes/*.php` returned only `web.php`, `settings.php`, `console.php`).
- No `config/sanctum.php`, no `config/broadcasting.php`, no `config/cors.php`, no `config/reverb.php` (glob over `config/`). Broadcasting was never installed.
- `vendor/laravel/` contains only `passkeys`, `serializable-closure`, `fortify`, `wayfinder`, `tinker`. **Sanctum is NOT installed. Reverb is NOT installed. No Pusher SDK. No Horizon. No Excel.**
- `config/auth.php:40-44` - only one guard: `web` => `driver: session`. No `sanctum` guard.
- `app/Http/Controllers/` - only `Controller.php`, `Settings/ProfileController.php`, `Settings/SecurityController.php`. No `app/Services/`, no `app/Policies/`, no `app/Observers/` (all three globs returned nothing).
- `bootstrap/app.php:26-29` - `shouldRenderJsonWhen($request->is('api/*') || $request->expectsJson())` exists but is inert because no `api/*` routes exist.
- `bootstrap/app.php:17-25` - `withMiddleware` registers no aliases; no `permission` alias; no CORS configuration.

### F3. The existing `users` table and `User` model are schema-incompatible with the spec
- `database/migrations/0001_01_01_000000_create_users_table.php:14-22` creates `id, name, email(unique), email_verified_at, password, remember_token, timestamps`. The same file also creates `password_reset_tokens` (`:24-28`) and `sessions` (`:30-37`).
- `telemedicine_test.sql:132-149` requires `users` = `id, uuid(unique), nama_lengkap, email(nullable unique), no_telepon(unique), kata_sandi_hash, tipe(ENUM 7), status(ENUM 4), foto_profil, bahasa(ENUM), telepon_terverifikasi, email_terverifikasi, last_login_at, dibuat_at, diubah_at, dihapus_at`.
- **Column-level conflicts:** `name` vs `nama_lengkap`; `password` vs `kata_sandi_hash`; `created_at/updated_at` vs `dibuat_at/diubah_at`; `email_verified_at` vs `email_terverifikasi`; no `no_telepon`; no `uuid`; no `tipe`; no `status`; no `dihapus_at`.
- `app/Models/User.php:30-31` uses Laravel 13 attribute syntax `#[Fillable(['name','email','password'])]` + `#[Hidden([...])]`; `:32` implements `MustVerifyEmail`, `PasskeyUser`; `:35` uses `PasskeyAuthenticatable, TwoFactorAuthenticatable`. `User.php:20-23` docblock still declares the scaffold fields.
- `database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php` and `database/migrations/2024_01_01_000000_create_passkeys_table.php` add `two_factor_secret`/`two_factor_recovery_codes`/`two_factor_confirmed_at` and a `passkeys` table - **neither exists in `telemedicine_test.sql`**.
- `database/factories/UserFactory.php` and `tests/Feature/**` (8 test files) all assume the scaffold `users` shape and Fortify flows.
- `config/auth.php:96-100` points the password-reset broker at `password_reset_tokens`, a table absent from the spec schema.

### F4. Database engine and name mismatch
- `.env.example:23-28` - `DB_CONNECTION=mysql`, `DB_DATABASE=sehatly`. `config/database.php:20` default is `env('DB_CONNECTION','sqlite')` and `database/database.sqlite` exists on disk.
- `telemedicine_test.sql:10-12` creates database **`telemedisin_db`** (utf8mb4/utf8mb4_unicode_ci). The env default (`sehatly`) and the SQL (`telemedisin_db`) disagree.
- `telemedicine_test.sql:7` header says "MySQL 8.0+". The file is **1349 lines** total. The schema uses `ENUM`, `JSON`, `TINYINT/MEDIUMINT UNSIGNED`, inline `INDEX`, `CHECK` constraints (`:1052-1053`), `CREATE OR REPLACE VIEW` (`:1170`, `:1190`), `GROUP_CONCAT` (`:1178`), and a post-hoc `ALTER TABLE ... ADD CONSTRAINT` cross-FK (`:1161-1163`). **This is not SQLite-portable**; running tests on SQLite would not prove schema parity.
- `.env.example:30-40` - `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `BROADCAST_CONNECTION=log`. The `cache`, `jobs`, `sessions` tables are Laravel infrastructure and are **absent from `telemedicine_test.sql`**.

### F5. Schema facts established by direct grep (authoritative counts)
- `telemedicine_test.sql` contains exactly **75 `CREATE TABLE` statements** (grep `^CREATE TABLE (\w+)`, matches at lines 58, 64, 72, 80, 89, 94, 99, 104, 109, 115, 122, 132, 151, 157, 163, 171, 179, 190, 204, 218, 259, 274, 286, 300, 312, 333, 340, 360, 388, 402, 409, 437, 447, 457, 470, 490, 498, 536, 563, 581, 599, 621, 657, 669, 681, 692, 708, 731, 742, 767, 786, 797, 819, 829, 847, 860, 868, 876, 894, 905, 925, 936, 958, 975, 985, 1000, 1012, 1036, 1050, 1068, 1074, 1093, 1118, 1134, 1147). The user's section 0 text says "71 tabel"; the SQL file header (`:7`) says "75 tabel". **75 is correct - the prose count is wrong.**
- 2 views: `v_dokter_katalog` (`:1170-1187`), `v_pendapatan_bulanan` (`:1190-1196`).
- Section `[14]` post-hoc FK: `ALTER TABLE pasien_tanda_vital ADD CONSTRAINT fk_vital_rm` (`:1161-1163`).
- Seed data sections `[16.1]`-`[16.9]` at `:1202`-`:1349` (provinsi, agama/golongan/pendidikan/pernikahan/hubungan, spesialisasi, penjamin, metode pembayaran, ICD-10, ICD-9-CM, master_obat 7 rows, master_lab_tindakan 10 rows, master_lab_paket 3 rows, artikel_kategori 6 rows).

### F6. Table-in-module cross-reference (derived from spec module tables vs. the 75)
Referenced by an explicit module: 48 tables.
Migration-only (no module endpoint) but REQUIRED as FK targets or reference data for Modules 1-5:
- `master_provinsi`, `master_kabupaten_kota`, `master_kecamatan`, `master_kelurahan` (FK from `pasien:249-252`, nullable; FK from `faskes:381-383`)
- `master_agama`, `master_golongan_darah`, `master_pendidikan`, `master_status_pernikahan` (FK from `pasien:242-245`)
- `master_hubungan_keluarga` (FK from `pasien_anggota_keluarga:266`)
- `master_icd10` (`rekam_medis_diagnosa.icd10_kode:660` - no FK, but M3 diagnosis entry needs the codes; `pasien_riwayat_penyakit.icd10_kode:290`)
- `master_icd9cm` (`rekam_medis_tindakan.icd9cm_kode:671` - no FK, needed by M3)
- `dokter_pendidikan` (**explicitly required by M1**: "Detail dokter (spesialisasi, rating, pendidikan)")
- `pasien_tanda_vital` (FK to `rekam_medis` added at `:1146-1149`)
Genuinely out of every module's endpoint list (migration + model only, explicitly documented):
`master_penjamin`, `pasien_penjamin`, `dokter_faskes`, `master_lab_tindakan`, `master_lab_paket`, `lab_paket_item`, `lab_permintaan`, `lab_permintaan_detail`, `lab_hasil`, `klaim_bpjs`, `ulasan_dokter`, `artikel_kategori`, `artikel`, `home_care_pesanan`.
**Gap found:** `dokter.rating_rata_rata` / `jumlah_ulasan` (`:426-427`) are read by M1's `GET /dokter/{id}`, and `ulasan_dokter` has `konsultasi_id UNIQUE` (`:1052`), but **no module defines a review/ulasan create endpoint**. Rating can only ever be seed data. Must be recorded as a noted gap, not silently invented.

### F7. Environment prerequisite risk
- Glob over `C:\Users\axioo` for `flutter.bat` / dart / php / mysql under common install roots returned **no files**. No Flutter SDK detected at the standard locations. This session has **no shell tool**, so `php -v`, `mysql --version`, `node -v`, and `flutter doctor` could not be executed to confirm.
- `vendor/` and `bootstrap/cache/packages.php` exist, so `composer install` has been run at least once.

### F8. Schema-level constraints that dictate design (found by reading the SQL, not assumed)- **Double-booking:** the spec permits "DB transaction + lock, **atau** unique constraint". `booking` (`:498-534`) has NO unique index on `(dokter_id, tanggal_kunjungan, slot_mulai)` - only `INDEX idx_booking_dokter (dokter_id, tanggal_kunjungan)` and `INDEX idx_booking_pasien`. Since the SQL must not change, the constraint route is unavailable -> transaction + row lock is the only correct option. There is no natural row to lock except `dokter_jadwal`, so that row must be locked `FOR UPDATE` and the overlap re-counted inside the transaction. Overlap predicate: `slot_mulai < :baru_selesai AND slot_selesai > :baru_mulai` with `status NOT IN ('dibatalkan','kadaluarsa')`.
- **`kuota_per_sesi`** (`dokter_jadwal:475`, nullable SMALLINT) means "slot free" is not always "zero bookings" - it is `count < (kuota_per_sesi ?? 1)`. A naive `count == 0` check is a bug.
- **`obat_interaksi`** has `UNIQUE KEY uq_interaksi (obat_a_id, obat_b_id)` (`:738`) - one direction only. The interaction check MUST query both `(a=X,b=Y)` and `(a=Y,b=X)`, or it will silently miss half of all interactions.
- **`resep_item.obat_id` is nullable** (`:770`, "NULL = racikan / obat non-katalog"). Interaction and allergy checks must skip NULL `obat_id` rows; racikan items are uncheckable by design and that limitation must be documented in code.
- **`resep.berlaku_sampai`** is `NOT NULL` (`:761`) with comment "berlaku 7 hari" -> `tanggal_resep + 7 days`.
- **`rekam_medis.status_dokumen` DEFAULT is `'final'`** (`:654`) even though the flow requires `draft` -> `final`. Creating a record must therefore pass `status_dokumen` explicitly or it lands `final` and becomes unamendable.
- **`rekam_medis.versi`** TINYINT DEFAULT 1 (`:655`) -> amendment = new row with `versi + 1`, per spec.
- **`pasien.nik CHAR(16) NULL UNIQUE`** with comment "WAJIB dienkripsi (application-level/TDE)" (`:222-223`). **CONFLICT:** Laravel's `encrypted` cast uses a random IV, so two rows with the same NIK produce different ciphertext and the UNIQUE index stops deduping. Needs an explicit decision.
- **`audit_log.data_lama` / `data_baru`** are JSON (`:1122-1123`) - a full-model snapshot would bloat the table; the observer needs a column allow-list.
- **`ulasan_dokter.rating`** uses `CHECK (rating BETWEEN 1 AND 5)` (`:1053`) - an inline table CHECK that Laravel's Blueprint has no first-class builder for.
- **`dibuat_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP` / `diubah_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`** appear on `users:146-147`, `pasien:253-254`, `dokter:434-435`, `booking:531-532`, `kons repelasi:558-559`, `resep:764-765`, `invoice:955-956`, `notifikasi` etc. Laravel's `$table->timestamps()` produces nullable timestamps with NO default and NO `ON UPDATE`, so 1:1 parity needs explicit column definitions plus a raw `ALTER` for the `ON UPDATE` clause.
- **Timezone duality:** `booking.tanggal_kunjungan DATE` + `slot_mulai/slot_selesai TIME` (`:513-515`) are wall-clock; `konsultasi.mulai_at DATETIME` (`:551`) is an instant. The spec mandates "semua timestamp response ISO 8601 UTC" (section 1.2). A coherent rule is required or slots will shift by 7 hours.
- **`invoice.referensi_tipe`** enum includes `lab_permintaan` and `home_care` (`:941`) which no module in scope produces -> `InvoiceService` must be generic and only 4 of 6 branches reachable in M1-M5.
- **`promo_redemption.invoice_id` is NOT NULL** (`:1005`) but `POST /promo/validasi` is described as a pure check -> persistence point for the redemption must be defined (invoice generation vs. payment).
- **Views** must be created/dropped with raw `DB::statement`; `v_dokter_katalog` uses `GROUP_CONCAT` so it is MySQL-only. `down()` must `DROP VIEW IF EXISTS` **before** dropping tables (FK-check ordering, mirroring `:20-56`).
- **Section `[14]` post-hoc FK** (`:1161-1163`) means `pasien_tanda_vital.rekam_medis_id` must be created as a plain column first and constrained in a LATER migration.
- **`rekam_medis_diagnosa` / `rekam_medis_tindakan` ICD columns have NO foreign keys** (`:660`, `:671`) - they are denormalized code strings validated against `master_icd10` / `master_icd9cm` at the application layer only.
- **FK creation order** is load-bearing (the SQL deliberately orders `CREATE TABLE`s to avoid circular deps, `:57-56`). The 75 migrations must reproduce the exact creation order captured in F5.

### F9. CONFIRMED (planner direct read) - the test suite is hard-wired to in-memory SQLite
- `phpunit.xml:26-27` - `<env name="DB_CONNECTION" value="sqlite"/>` and `<env name="DB_DATABASE" value=":memory:"/>`.
- `phpunit.xml:24,30,25,29,31` - `BROADCAST_CONNECTION=null`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `MAIL_MAILER=array`, `SESSION_DRIVER=array`.
- **This is the single hardest blocker in the whole request.** A 1:1-parity requirement against a MySQL-8-only schema (ENUM, JSON, unsigned ints, inline INDEX, CHECK, VIEW, GROUP_CONCAT) is **unprovable** on SQLite: Laravel would silently degrade `enum()` to `varchar`, `json()` to `text`, and unsigned widths would be lost. A green suite on SQLite would be exactly the "misleading success output" the plan must not accept.
- Therefore either (a) the suite runs against a real MySQL 8 (`DB_CONNECTION=mysql` + a `telemedisin_db_test` schema, `RefreshDatabase` per test), or (b) a **separate, explicit schema-parity verifier** compares `information_schema` against the SQL file, because the functional tests alone cannot prove 1:1. Option (b) is additive and works even if the user keeps SQLite for speed.

### F10. Pre-existing dependency drift (unrelated to the spec, but blocks any `composer install`)
- `explore` reports `laravel/passkeys` is present in `composer.lock` and `vendor/laravel/passkeys/` but **absent from `composer.json` `require`** (`composer.json:14-18` lists only php, inertia-laravel, fortify, framework, tinker, wayfinder). CONFIRMED by the planner: `composer.json:14-18` contains no `laravel/passkeys`, and `package.json:16` lists `@laravel/passkeys ^0.2.0` on the JS side.
- Consequence: `composer install`/`composer validate` will report the lock file is out of sync, and `app/Models/User.php:26,34` (`Laravel\Fortify\Contracts\PasskeyUser`, `PasskeyAuthenticatable`) plus the 3 generated `resources/js/actions/Laravel/Passkeys/**` files depend on a package the manifest does not declare.
- `explore` also reports no `config/passkeys.php` and no `app/Models/Passkey.php` despite `database/migrations/2024_01_01_000000_create_passkeys_table.php` creating a `passkeys` table.
- This is `dirty_worktree` collateral, NOT spec scope. It must be a noted pre-existing defect, not silently "fixed" as part of the telemedicine work unless the user asks.

### F11. Dirty worktree - a hard planning constraint
- `.git/HEAD` = `ref: refs/heads/main`. `explore` reports a single commit `2d3b3b4 "first commit"` with roughly 140 modified/deleted paths and ~60 untracked paths, including rebranding assets at the repo root (`Sehatly.svg`, a logo image) and the untracked `telemedicine_test.sql`.
- `telemedicine_test.sql` and `WhatsApp Image 2026-09-26 at 7.07.11 PM.jpeg` are both present at the repo root and untracked.
- **Implication:** there is no clean baseline to diff against, and any "delete the Inertia starter kit" step risks destroying uncommitted user work. Per the dirty-worktree rule the plan must (a) baseline-commit or stash before destructive work, (b) never delete untracked files it did not create, and (c) keep rebranding assets out of scope.

### F12. Inertia config is already inconsistent (pre-existing)
- `explore` reports `config/inertia.php` has `ssr.enabled = true` with `url = http://127.0.0.1:13714` while `resources/js/ssr.*` was deleted, yet `package.json:7` still defines `build:ssr`. It also reports `testing.ensure_pages_exist = true`, which makes Inertia page tests assert the page module resolves.
- Relevant because the starter kit's own test suite is not green; a plan that claims "existing tests pass" would be false.

### F13. Additional starter-kit surface the explore lane found
- `app/Actions/Fortify/CreateNewUser.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Concerns/PasswordValidationRules.php`, `app/Concerns/ProfileValidationRules.php` exist and are Fortify-coupled.
- `app/Providers/AppServiceProvider.php` sets `Date::use(CarbonImmutable::class)`, `DB::prohibitDestructiveCommands(app()->isProduction())`, and `Password::defaults()` (min 12 + uncompromised in production). `BCRYPT_ROUNDS=12` (`.env.example:16`).
- `app/Providers/FortifyServiceProvider.php` registers Inertia views for login/reset/forgot/verify-email/register/2fa/confirm-password and rate limiters `login` (5/min), `two-factor` (5/min), `passkeys` (10/min).
- `tests/TestCase.php` provides `skipUnlessFortifyHas(string $feature)`.
- `routes/settings.php` also serves `GET /.well-known/passkey-endpoints` and `settings/appearance` - more Fortify/passkey surface not in the spec schema.
- **Confirmed absent (planner glob):** `app/Console`, `app/Events`, `app/Jobs`, `app/Listeners`, `app/Policies`, `app/Services`, `app/Http/Resources`, `routes/channels.php`, and any model other than `User`.

### F14. CORRECTION to an `explore` claim (do not propagate)
- `explore` asserted that `2024_01_01_000000_create_passkeys_table.php` "sorts BEFORE the `0001_01_01_*` base migrations, so on a fresh migrate the passkeys FK to `users` is created before `users` exists."
- **This is wrong.** Laravel orders migrations by filename string comparison; `"0001_01_01_..."` < `"2024_01_01_..."` because `'0'` (0x30) < `'2'` (0x32). `users` is created first. No reordering is needed, and the plan must not include a "fix" for a bug that does not exist.
- The explore lane's separate claim that the passkeys migration declares both `->constrained()` and a redundant explicit `index('user_id')` is plausible but UNVERIFIED, and is out of spec scope either way.

### F15. CONFIRMED against Laravel 13.x official docs (Context7, `/laravel/docs/__branch__13.x`)
- **API prefix is a first-class bootstrap parameter.** `->withRouting(api: __DIR__.'/../routes/api.php', apiPrefix: 'api/v1', ...)` - source `routing.md`. So the spec's `/api/v1` base path is a one-line change to `bootstrap/app.php:12-16`, not a route-group workaround. Default prefix is `api`.
- **`php artisan install:api` creates `routes/api.php` AND installs Laravel Sanctum** - source `routing.md`, "Basic Routing > The Default Route Files > API Routes". It also assigns routes to the stateless `api` middleware group.
- **Custom middleware alias syntax confirmed:** `$middleware->alias(['subscribed' => EnsureUserIsSubscribed::class])` inside `->withMiddleware()`, plus `$middleware->api(prepend: [...])` / `$middleware->api(append: [...])` - source `middleware.md`. The spec's `permission:kode.permission` middleware is therefore a first-class, documented pattern.
- **Sanctum on Laravel 13:** `Laravel\Sanctum\HasApiTokens` trait; `$user->createToken(string $name, array $abilities = [], ?DateTimeInterface $expiresAt = null)`; `$request->user()->currentAccessToken()->delete()` to revoke the active token; `config/sanctum.php` `'expiration' => <minutes>` for the global default; per-token expiry via the 3rd `createToken` arg; `php artisan sanctum:prune-expired` to clean up. **Default is tokens NEVER expire** - the spec mandates a refresh flow, so an explicit `expiration` MUST be set or the refresh token is decorative.
- **Indonesian timestamp columns are officially supported:** `public const CREATED_AT = 'dibuat_at';` / `public const UPDATED_AT = 'diubah_at';` on the model - source `eloquent.md`, "Timestamps > Customization". No package or hack needed.
- **Soft delete with a custom column:** `$table->softDeletes('dihapus_at', precision: 0)` - source `migrations.md`. **BUT this creates a `TIMESTAMP`, while `telemedicine_test.sql:148` and `:257` declare `dihapus_at DATETIME NULL DEFAULT NULL`.** For 1:1 parity the migration must use `$table->dateTime('dihapus_at')->nullable()` and the model must set `const DELETED_AT = 'dihapus_at'` - `softDeletes()` alone is a silent parity break.
- **`ON UPDATE CURRENT_TIMESTAMP` has no Blueprint helper.** Not present in the Laravel 13 migration docs. Since `telemedicine_test.sql` uses it on every `diubah_at` and on `users.dibuat_at`/`diubah_at`, `:146-147`, `:253-254`, `:434-435`, `:531-532`, `:558-559`, `:764-765`, `:955-956`, parity requires a raw `DB::statement('ALTER TABLE ... MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')` in a follow-up migration. Do not assume a fluent method exists.

### F16. Flutter / GetX - three findings that CONTRADICT the spec's stated premises
Source: librarian lane `ses_f21b09b68ffeEzhvT5c8RbM3PP`, verified by downloading and inspecting the actual pub.dev tarballs (not just READMEs).

1. **GetX has a breaking API split. The version choice is load-bearing.**
   - Stable **`get: 4.7.3`** (verified inside the published tarball): `abstract class Bindings { void dependencies(); }` is NOT deprecated; `class BindingsBuilder<T> extends Bindings` with a `.put(...)` factory; `Get.put`/`Get.lazyPut` present; SDK `>=2.15.0 <4.0.0`, Flutter `>=3.13.0`.
   - Prerelease **`5.0.0-release-candidate-9.3.3`**: `Bindings` carries `@Deprecated('Use Binding instead')` and `BindingsBuilder` is **commented out entirely**. Replaced by `abstract class Binding extends BindingsInterface<List<Bind>>` returning `List<Bind>` with `Bind.lazyPut<T>(...)` / `Bind.put<T>(...)`.
   - **Consequence:** the plan must pin `get: ^4.7.3` exactly. Writing 5.0-style bindings would not compile on stable, and pinning the RC would adopt an unreleased breaking API. This is a decision, not an accident.
   - **Community consensus (2026) is that GetX is in maintenance mode and Riverpod is the recommended default** (r/FlutterDev Jan 2026 "pick anything except getx"; multiple 2026 comparison articles). The maintainer's own 4.7.0 changelog says version 5 "is being moved to stable, but many people are still relying on version 4." The spec mandates GetX, so the plan uses GetX - but the TL;DR must state the trade-off honestly rather than pretend it is uncontroversial.

2. **The spec's `GetConnect` interceptor premise is wrong.** The spec says "pakai `GetConnect` atau `Dio`" and implies `onRequest`/`onError`/`AsyncMiddleware`. Verified by reading `get_connect/connect.dart` from the 4.7.3 tarball: **GetX has no `AsyncMiddleware` at all** (GitHub code search across `jonataslaw/getx` returns 0 hits) and no `onRequest`/`onError` hooks. GetConnect's real surface is `addRequestModifier` (sync), `addAuthenticator` (async, re-invoked on 401), `addResponseModifier` (sync), and `maxAuthRetries` (default 1).
   - **GetConnect's `addAuthenticator` does not deduplicate concurrent 401s** - N parallel requests produce N refresh calls, which can cascade into a refresh-token rotation race. Dio's `QueuedInterceptor` exists precisely to serialize that.
   - **Decision: Dio 5.11.1** (`InterceptorsWrapper` / `QueuedInterceptor`, `onRequest`/`onError`, `DioException`, `DioExceptionType`, `LogInterceptor` added LAST) for the mobile HTTP layer, because refresh-token rotation correctness is a security property, not a preference. GetX is still used for state/DI/routing exactly as the spec requires - only the HTTP client is Dio.

3. **Storing Sanctum tokens in `GetStorage` is a security anti-pattern, and the spec mandates it.** The spec says: "Simpan token dengan `GetStorage` (bukan SharedPreferences manual)."
   - `get_storage` 2.1.1 is **plain unencrypted JSON** in the app sandbox. `read<T>()` is synchronous, `write` is async-flushed, `erase()` wipes, and it supports String/int/double/Map/List - but there is no encryption and no keystore involvement.
   - OWASP names this exactly: **MASVS-STORAGE-1**, **MASWE-0001** ("Sensitive Data Stored Unencrypted in Private Storage"), **MASWE-0003** (keys outside the platform keystore), and **MASTG-KNOW-0047** (on a rooted device any app with root can read another app's SharedPreferences/prefs file, whereas the KeyStore is enforced at kernel level). Android backup extraction is a second leak vector.
   - `flutter_secure_storage` 11.2.0 is the consensus answer: RSA-OAEP + AES-GCM on Android, Keychain on iOS, `minSdk 23`. It requires `android:allowBackup="false"` and backup exclusion, or it throws `java.security.InvalidKeyException: Failed to unwrap key` on restore.
   - **This is safety-critical and the user explicitly specified the insecure option, so it survives as an owner-decision rather than a silent default.** The defensible split: access + refresh tokens in `flutter_secure_storage`; theme, locale, cached profile, FCM token in `GetStorage`. Neither is a silver bullet against a rooted device - short-lived access tokens plus rotation plus server-side revocation is the real control - but plaintext refresh tokens in a JSON file is not a defensible default for a medical app under UU PDP.

### F17. Real-time transport - the spec's "WebSocket broadcasting" needs a protocol decision
- **Laravel Reverb and Soketi both speak the Pusher protocol, NOT Socket.IO.** Therefore `socket_io_client` (3.1.6) is the WRONG client for Reverb - it only fits the abandoned pre-Reverb `laravel-websockets` package.
- **`echo_dart` does not exist on pub.dev (404).** Every Dart port of Laravel Echo is stale: `laravel_echo` 0.2.9 (last upload ~5 years ago), `laravel_echo_null` (2023, forces `socket_io_client` + a patched server), `wivoce_laravel_echo_client`, `lecho`. Do not plan against any of them.
- **Best fit: `laravel_reverb` 0.7.1** (pub.dev, verified publisher `gaitco.com`, actively published) - pure Dart Pusher-protocol client with an Echo-style API, ref-counted subscriptions, auto-reconnect **with re-authorization**, presence channels, and a `ReverbFake` test double. Depends only on `web_socket_channel ^3.0.0`, `http ^1.2.0`, `stream_channel ^2.1.2`.
- Wire protocol facts (from `laravel/reverb` `EventHandler.php`): client sends `pusher:connection_established`, `pusher:subscribe`, `pusher:unsubscribe`, `pusher:ping`; server replies `pusher:connection_established`, `pusher_internal:subscription_succeeded` (note the different prefix), and `pusher:ping` -> client answers `pusher:pong`. Private channels go on the wire as `private-<name>`.
- Auth endpoint: `POST /broadcasting/auth` (or `/api/broadcasting/auth` for the API group), body `{socket_id, channel_name}`, response `{auth: "key:sig"}` for private and `{auth, channel_data}` for presence. Because the app is token-authenticated, the request must carry `Authorization: Bearer <sanctum token>` - this is a common integration bug and belongs in the failure QA.

### F18. Flutter toolchain reality for a new project in Sept 2026
- Current stable **Flutter 3.47.0** (released 2026-08-12), bundling Dart 3.13.3. 2026 cadence: 3.41 (Feb) / 3.44 (May) / 3.47 (Aug) / 3.50 (Nov).
- **Flutter 3.50 (Nov 2026) deprecates the core design libraries.** `package:flutter/material.dart` and `package:flutter/cupertino.dart` are migrating to the standalone `material_ui` / `cupertino_ui` packages (1.0), with `dart fix --apply --code=migrate_design_widgets` as the migration path. A brand-new app started now on 3.47 will need that codemod before it can move to 3.50.
- Android baseline: **minSdk 24, compileSdk/targetSdk 36, Java 17, KGP 2.4.0, AGP 9.1.0, Gradle 9.3.1.**
- `firebase_messaging` 16.7.0 (Flutter Favorite) requires `firebase_core ^4.14.0`. iOS **UIScene** apps (mandatory since Flutter 3.13) must call `FLTFirebaseMessagingPlugin.configureNotificationCenterDelegate()` as the first line of `AppDelegate.didFinishLaunchingWithOptions` **before** `super` - this is the single most common iOS FCM failure and belongs in the failure QA path. Android also needs the `POST_NOTIFICATIONS` permission and a `default_notification_channel_id` meta-data entry.
- `get_storage` 2.1.1 depends on `get >=4.0.0 <6.0.0`, so it is compatible with the pinned `get: ^4.7.3`.

## Decisions (with rationale)

- **Intent: CLEAR.** The spec names the outcome, the modules, the tables, the endpoints, the business rules, the stack, and the DoD. Open items are owner-preferences, not outcome ambiguity. No high-accuracy modifier was given -> `review_required: false`; the review is offered at handoff, not assumed.
- **Classification: Architecture** (5 modules, 3 codebases, 75 tables, long-term system design). Per `full-workflow.md` Phase 0 this mandates deep explore + external research + the dynamic adversarial lanes, not a Standard interview.
- **Pest 4 adopted** for backend tests (F: composer already pins it).
- **The workspace is not a blank slate - it is a live Laravel 13 + Inertia + React starter kit with a dirty worktree.** Every "greenfield" assumption in the spec is false here. This single fact reshapes the whole plan and is the reason the repo-topology question below is unavoidable.
- **Test DB engine is NOT a preference - it decides whether the DoD is provable.** F9: `phpunit.xml:26-27` pins the suite to in-memory SQLite while the schema is MySQL-8-only. A green SQLite suite would be exactly the "misleading success output" the plan must reject, so the plan needs a real schema-parity verifier regardless of which engine the user picks.
- **Three spec premises are factually wrong and will be corrected in the plan, not silently obeyed:** (1) `GetConnect` has no `onRequest`/`onError`/`AsyncMiddleware` (F16.2); (2) `GetStorage` is unencrypted plaintext and is the wrong home for Sanctum tokens (F16.3); (3) Reverb/Soketi speak the Pusher protocol, so `socket_io_client` is the wrong client and `echo_dart` does not exist (F17). In each case the plan follows the spec's *intent* with the correct mechanism and says so explicitly.
- **`telemedicine_test.sql` says 75 tables, not 71.** The prose in section 0 is wrong; the file is authoritative (F5).

### F19. React lane - verified version baseline and THREE landmines
Source: librarian lane `ses_f21b06965ffeTgw7hrVjFHTlAJ`; all versions read from the npm registry / Packagist on 2026-09-26, several confirmed by reading package source at a resolved tag SHA.

Current: `react` **19.3.0**, `vite` **8.3.1**, `tailwindcss` + `@tailwindcss/vite` **4.3.3**, `laravel-vite-plugin` **3.2.0**, `@vitejs/plugin-react` **6.1.1**, `@tanstack/react-query` **5.104.0**, `ky` **2.1.0**, `axios` **1.20.0**, `react-router` **8.4.0**, `react-hook-form` **7.89.0**, `zod` **4.6.5**, `@hookform/resolvers` **5.9.1**, `@daypicker/react` **10.0.1**, `laravel-echo` **2.5.0**, `pusher-js` **8.6.0**, `lucide-react` **1.48.0**, `laravel/framework` **v13.33.0**, `laravel/sanctum` **v4.3.3**, `laravel/reverb` **v1.12.0**, `inertiajs/inertia-laravel` **v3.4.0**.

1. **LANDMINE - Vite 8 silently breaks the existing shadcn UI kit.** Vite 8 (Rolldown, released 2026-03-12) changed the default of `build.cssMinify` from `esbuild` to **`lightningcss`**. Lightning CSS strips vendor prefixes, and the shadcn/Radix `sidebar`, `dialog`, `dropdown-menu`, `sheet`, and `sonner` components all rely on **unprefixed `backdrop-filter`** (the `backdrop-blur-*` utilities). Confirmed in Vite source: the esbuild path is only taken `if (config.build.cssMinify === 'esbuild')`, so the default no longer produces prefixed output. **Every one of the 33 existing UI components would render with a broken frosted-glass backdrop.** Fix: set `build.cssMinify: 'esbuild'` in `vite.config.ts` (or in the new `web/vite.config.ts`).
2. **LANDMINE - TypeScript is two majors ahead.** `typescript` latest is **7.0.2** (GA 2026-07-08, the Go-native port); `6.0.0-beta` and `7.0.1-rc` also exist, and `7.1.0-dev.*` is already publishing. The repo pins `^5.7.2` (`package.json:47`). A new `web/` app should start on a current TS, not inherit 5.7.
3. **LANDMINE - `laravel-vite-plugin` v3 has no SPA support and no SPA fallback.** Confirmed by reading `src/index.ts` at the v3.2.0 SHA: there is **no `spa` option** in the plugin config, and the only fallback it serves is a 404 splash page triggered when the Vite dev server receives `/index.html` directly. Therefore a pure SPA needs its own catch-all route (`Route::view('/{any?}', 'app')->where('any', '^(?!api|broadcasting|sanctum|up|build|storage).*$')`) or every deep link 404s on hard refresh.
4. **Good news - `laravel/wayfinder` has NO Inertia dependency.** Its `composer.json` requires only `php ^8.2`, `illuminate/console`, `illuminate/routing`, and `phpstan/phpdoc-parser`. It generates typed TS from `routes/*.php`, so it survives the Inertia removal and can generate typed `/api/v1/...` builders if the API routes are named. **Caveat: still pre-1.0 at v0.1.21** - pin exactly and keep it optional.
5. **TanStack Query v5 defaults** (confirmed in source at the v5.104.0 SHA): `staleTime: 0`, `gcTime: 5 min`, `retry: 3`. Queries must be mutations-safe: `mutations.retry = 0`. Note the deciding factor for this app is **role-switch cache invalidation** (`queryClient.clear()` on logout), which only a real query cache gives you. There is also a new `staleTime: 'static'` option in v5 (`StaleTime = number | 'static'`) worth using for master/reference data such as the 38 provinces and the ICD-10 list.
6. **Vite 8 requires Node `^20.19.0 || >=22.12.0`**, and `build.rollupOptions` is deprecated in favour of `rolldownOptions` (`laravel-vite-plugin` v3 already uses `rolldownOptions` internally, so the plugin is Vite-8-ready).
7. **`@daypicker/react` is the new package name** for react-day-picker v10 - `react-day-picker` v10.0.1 is a re-export shim. M2's date picker must import from `@daypicker/react`.
8. **No dominant QRIS library exists.** The Indonesian QRIS npm/PHP ecosystem is fragmented with tiny unmaintained packages (<600 downloads). **Decision: generate the QRIS payload server-side in PHP** (CRC16-CCITT over the EMVCo TLV string - roughly 20 lines, fully testable) and render the image on the client with a plain QR renderer. Do not plan a third-party QRIS dependency.

### F20. Laravel Reverb is first-party in Laravel 13 - no `pusher` shim needed
Confirmed by reading `laravel/framework` `BroadcastManager` and `config/broadcasting.php` at the 13.x SHA:
- `config/broadcasting.php` lists supported connections as `"reverb", "pusher", "ably", "mercure", "redis", "log", "null"`, and the `reverb` connection block uses `'driver' => 'reverb'` with the same `key`/`secret`/`app_id`/`options.{host,port,scheme,useTLS}` shape as pusher but **without `cluster`**.
- `BroadcastManager::createReverbDriver()` simply delegates to `createPusherDriver()` - i.e. Laravel 13 ships a real `reverb` driver because Reverb implements the Pusher protocol.
- **Therefore `BROADCAST_CONNECTION=reverb` works, and the JS side should use `broadcaster: 'reverb'` - not the older `broadcaster: 'pusher'` + `cluster` recipe from pre-13 blog posts.**
- `laravel-echo` v2.5.0 is a TypeScript rewrite (pnpm monorepo, `packages/laravel-echo`). Confirmed in its source: `reverb` is a first-class member of the `Broadcaster` map, and for `broadcaster: 'reverb'` the options type is `PusherOptions` **with `cluster` omitted** - so passing `cluster` is not just unnecessary, it is wrong. Echo v2 also has first-class `bearerToken` and `csrfToken` options (the `bearerToken` authorizer sets `Authorization: Bearer` on the auth request) and a default `authEndpoint: '/broadcasting/auth'`.
- Under the chosen topology (Q1=A) the web SPA is **same-origin**, so `laravel-echo` + `pusher-js` in `web/` works. The Flutter app uses `laravel_reverb` 0.7.1 with the same endpoint.

### F21. Sanctum's own documentation contradicts the spec's "token-only, wajib" mandate - resolved in the spec's favour, but recorded
Confirmed verbatim from the Laravel 13 Sanctum docs: *"You should not use API tokens to authenticate your own first-party SPA. Instead, use Sanctum's built-in SPA authentication features."* Sanctum's recommended first-party-SPA path is the **stateful cookie** flow (`EnsureFrontendRequestsAreStateful` + `config/sanctum.php`'s `stateful` allow-list + `GET /sanctum/csrf-cookie`), which is strictly more secure than a JS-readable bearer token because the token is never exposed to JavaScript.
- Verified in `laravel/sanctum` v4.3.3 source: `config/sanctum.php` has a `stateful` array still driven by the `SANCTUM_STATEFUL_DOMAINS` env var; `EnsureFrontendRequestsAreStateful` matches the request's **`Referer` header, falling back to `Origin`**, against that allow-list using `Str::is()` (wildcards supported); and `SanctumServiceProvider` **prepends** this middleware to the priority list so it runs before the `api` group's CSRF handling. Echo's `authEndpoint` must therefore be the API-group path `/api/broadcasting/auth` when the SPA holds a bearer token, and it must send that token - the single most common Reverb integration bug.
- **Resolution: the spec explicitly and emphatically mandates token-based auth ("wajib, bukan session cookie") for BOTH clients, so the plan implements bearer tokens for React and Flutter alike and treats the cookie mode as a documented, unselected alternative.** This is recorded so the choice is visible and reversible, not because it is the strongest security posture - under the chosen same-origin topology the cookie mode would be available and would be preferable.

## Scope IN

- All 75 tables as Laravel migrations matching `telemedicine_test.sql` 1:1, plus the 2 views, plus seeders for section `[16]`.
- Modules 1-5 backend: the ~48 module tables get Models + Relationships + Resources + FormRequests + Controllers + Services + Feature Tests.
- The `/api/v1` REST kernel, Sanctum + OTP + refresh + device registration, `permission:` middleware.
- React web client (pasien/dokter/apoteker/admin) on real endpoints.
- Flutter + GetX mobile client (pasien/dokter) with live chat and FCM.
- Compliance: `audit_log` observer, `akses_rekam_medis_log` on read, `persetujuan_pdp` gating.
- `telemedicine_test.sql` treated as read-only. Any needed extra table/column recorded as a separate note, never an edit to the file (spec section 4.4).

## Scope OUT (Must NOT have)

- Editing `telemedicine_test.sql` in any way.
- Inventing endpoints for tables no module assigned (notably `ulasan_dokter` creation, `artikel`, `home_care_pesanan`, `lab_*`, `klaim_bpjs`, `faskes` CRUD, `dokter_jadwal` write endpoints - none are in the spec's endpoint tables).
- Real SMS/WhatsApp provider, real payment gateway settlement, real Agora/Twilio, real SATUSEHAT/BPJS V-Claim calls - spec allows stubs; stub behind an interface.
- Reducing scope to an "MVP"/"phase 1" - the spec defines the deliverable and all 5 modules are in it.
- Changing column names/types/relations to suit Laravel convenience.

## Open questions

Filter 1 (evidence-answerable -> explored, not asked) already resolved: test framework (Pest 4, already pinned), API prefix mechanism (`apiPrefix`), middleware alias mechanism (`$middleware->alias`), Sanctum API (`install:api`), custom timestamp columns (`CREATED_AT`/`UPDATED_AT` consts), custom soft-delete column (`DELETED_AT` const - and `softDeletes()` alone is a parity bug), GetX version pin, Pusher-protocol client choice, and the 75-table count.

Surviving owner-decisions (irreversible / destructive / safety-critical / cross-cutting product shape):

**Q1 - Repo topology and the fate of the existing fused Inertia starter kit.** Destructive + cross-cutting. The spec offers 3-repo or a `backend/web/mobile` monorepo, but reality is one git repo whose root IS Laravel+Inertia+React, on a dirty worktree with uncommitted rebranding work.
- (A) RECOMMENDED: root stays the Laravel API, add `web/` (new React SPA) + `mobile/` (Flutter). Strip Inertia from the API surface; keep/reuse the 33 shadcn UI components by moving them into `web/`.
- (B) Keep Inertia SSR as the web client, add only `/api/v1` for Flutter. Cheapest, but the React client then never calls `/api/v1`, which contradicts spec 1.1/1.2 and the DoD line "Komponen React terhubung ke endpoint asli".
- (C) Physically move Laravel into `backend/`. Most literal to spec 1.3, highest risk (vendor, bootstrap/cache, .env, storage paths all churn).

**Q2 - Test/dev database engine.** The DoD "migration match 1:1" is unprovable on SQLite, and `phpunit.xml:26-27` currently pins the suite to in-memory SQLite.
- (A) RECOMMENDED: MySQL 8 for dev AND test (`telemedisin_db` + `telemedisin_db_test`), AND a dedicated schema-parity verifier that diffs `information_schema` against `telemedicine_test.sql`.
- (B) Keep SQLite for fast functional tests, rely solely on the parity verifier for schema fidelity.
- (C) MySQL only, no separate parity verifier (weakest evidence).

**Q3 - `pasien.nik` encryption vs. its UNIQUE index.** Data/schema shape + UU PDP 27/2022 compliance. The SQL says `nik CHAR(16) NULL UNIQUE` with comment "WAJIB dienkripsi", which is self-contradictory under random-IV encryption.
- (A) RECOMMENDED: deterministic encryption (fixed IV derived via HMAC from `APP_KEY`) so ciphertext is stable and UNIQUE still works. No schema change. Caveat: leaks equality, and `APP_KEY` rotation invalidates rows.
- (B) Add a `nik_hash CHAR(64)` blind-index column (permitted as a *noted* addition by spec 4.4, without editing the SQL file) + random-IV encryption. Strongest, most moving parts.
- (C) Store plaintext, mask only in API responses, defer encryption. Fastest, weakest compliance.

**Q4 - Mobile token storage (safety-critical, and the spec mandates the insecure option).** Spec says "Simpan token dengan `GetStorage`". `get_storage` is unencrypted JSON; OWASP MASVS-STORAGE-1 / MASWE-0001 / MASTG-KNOW-0047 cover exactly this.
- (A) RECOMMENDED: `flutter_secure_storage` 11.2.0 for access + refresh tokens; `GetStorage` for theme/locale/profile/FCM token.
- (B) Follow the spec literally: tokens in `GetStorage`.

**Q5 - Test strategy confirmation.** (skill requires confirming this every time)
- (A) RECOMMENDED: tests-after for migrations/CRUD, TDD for the four high-risk domain engines (slot availability + booking lock, drug interaction + allergy, rekam-medis versioning/amendment, webhook idempotency). All Pest 4, all agent-executed.
- (B) TDD everywhere.
- (C) Tests-after everywhere.

**Q6 - Sanctum requires a `personal_access_tokens` table that the SQL does not contain.** The spec mandates Sanctum AND gives us `user_refresh_tokens`; Sanctum's PAT store is a hard dependency of `HasApiTokens`.
- (A) RECOMMENDED: install Sanctum (PAT table added as a documented schema note, not an edit to the SQL) for access tokens; use `user_refresh_tokens` for the refresh/rotation flow. Honors "Sanctum wajib".
- (B) Skip Sanctum; hand-roll a first-party `auth:api` guard over `user_refresh_tokens` only. Perfect schema fidelity, deviates from the spec's explicit mandate.

**Q7 - Payer-context for the 14 module-orphaned tables.** `ulasan_dokter`, `artikel*`, `home_care_pesanan`, `lab_*`, `klaim_bpjs`, `pasien_penjamin`, `dokter_faskes`, `master_penjamin` are in the SQL but no module assigns them an endpoint. Note: `dokter.rating_rata_rata` is read by M1's `GET /dokter/{id}` yet nothing in Modules 1-5 can create a review, so ratings can only ever be seed data.
- (A) RECOMMENDED: migration + Model only for all 14, no endpoints, recorded as an explicit noted gap. Zero invented scope.
- (B) Also add minimal read endpoints where M1 already implies them (reviews list on doctor detail).

### Scope change accepted 2026-09-26 (user, in-session)
- **D1. Mobile scope reduced to DOCUMENTATION + CONTRACT, not implementation.** The user asked for "cukup web dev" plus clear documentation so the Flutter team can integrate easily. The six Flutter-implementation todos (old 24, 29, 36, 42, 49 and the mobile half of 5) were **repurposed in place, keeping their numbers** so no renumbering was needed:
  - old 24 -> **pure-Dart API client package** `packages/sehatly_api_client` (Dio + single-flight refresh + storage-split interfaces + typed endpoint classes + fake client + `dart test`)
  - old 29 -> **Dart realtime + push integration** (`laravel_reverb` wrapper, reconnect/no-replay, dedup, FCM via interface + README code blocks)
  - old 36 -> **`docs/mobile-integration.md`**, the 12-section human guide
  - old 42 -> **`docs/enums.json` + 13 public `/api/v1/referensi/*` endpoints**, generated from `information_schema`
  - old 49 -> **contract-conformance suite** validating every response against the OpenAPI schemas
  - old 5 -> web-only skeleton; explicit `test ! -e mobile` acceptance criteria added
- **D2. `review_required` is now `true`** - the user explicitly asked for the dual high-accuracy review.
- **D3. Q4 is now advisory rather than implemented** - the secure storage split ships as the Dart package's default plus the guide's rationale, so the mobile team inherits it instead of re-deciding.
- **D4. Two new todos' worth of contract work was added rather than one**: the enum catalogue (42) and the conformance suite (49). Rationale: with no mobile app in this repo, the *only* thing standing between the Flutter team and a working integration is the accuracy of the generated contract, and both of these are the mechanisms that keep it honest. Prose alone would drift.
- Todo count unchanged at **54**; success criteria renumbered to 15 and now include four mobile-handoff criteria.

## Approval gate
status: approved
approved_by: user via Q1-Q4 answers (all four accepted the recommended option), which the workflow counts as approval to write the plan
plan_authorizes: writing `.omo/plans/sehatly-telemedicine-platform.md` ONLY - never implementation

### Resolved decisions
- **Q1 = A.** Repo root stays the Laravel API; new React SPA in `web/`; Flutter in `mobile/`. Inertia is stripped from the API surface. The 33 shadcn components are relocated into `web/` rather than rewritten. A same-origin Blade shell + catch-all route serves the SPA shell (required because `laravel-vite-plugin` v3 has no SPA fallback - F19.3).
- **Q2 = A.** MySQL 8 for dev AND test (`telemedisin_db` + `telemedisin_db_test`), plus a dedicated `information_schema` parity verifier diffing against `telemedicine_test.sql`. `phpunit.xml:26-27` gets rewritten.
- **Q3 = A.** Deterministic encryption for `pasien.nik` - fixed IV derived via HMAC from `APP_KEY` - so ciphertext is stable and the existing `UNIQUE` index keeps working. No edit to the SQL file. Documented trade-offs: equality leaks, and `APP_KEY` rotation invalidates existing rows.
- **Q4 = A.** `flutter_secure_storage` 11.2.0 holds the access + refresh tokens; `GetStorage` holds theme, locale, cached profile, and the FCM token. Requires `android:allowBackup="false"` plus backup exclusion, or restore throws `java.security.InvalidKeyException: Failed to unwrap key`.
- **Q5 = default A (not asked; skipped = default).** Tests-after for migrations and CRUD; **TDD for the four high-risk domain engines** - slot availability + booking lock, drug interaction + allergy, rekam-medis versioning/amendment, and webhook idempotency. All Pest 4, all agent-executed.
- **Q6 = default A (not asked; skipped = default).** Sanctum is installed and used for access tokens (its `personal_access_tokens` table recorded as a **documented schema note**, never an edit to the SQL file), while `user_refresh_tokens` drives refresh/rotation. `config/sanctum.php` `expiration` MUST be set explicitly, because the verified default is that Sanctum tokens **never expire** - otherwise the refresh flow the spec mandates is decorative.
- **Q7 = default A (not asked; skipped = default).** The 14 module-orphaned tables get migrations + Models only, no invented endpoints, recorded as an explicit noted gap - including the finding that `dokter.rating_rata_rata` is read by M1 but nothing in Modules 1-5 can create a `ulasan_dokter` row.
- **Dio, not GetConnect, for Flutter HTTP** (F16.2) - because `addAuthenticator` does not deduplicate concurrent 401s and would race refresh-token rotation. GetX is retained for state/DI/routing exactly as the spec requires.
- **`laravel_reverb` 0.7.1 for Flutter, `laravel-echo` 2.5.0 + `pusher-js` 8.6.0 for web** (F17, F20). No `socket_io_client`, no `echo_dart`.
- **Reverb driver, not pusher** (F20) - `BROADCAST_CONNECTION=reverb`, `broadcaster: 'reverb'` in Echo, and **no `cluster` option**.
- **TypeScript 7.0.2, not 5.7** (F19.2). **`build.cssMinify: 'esbuild'`** is mandatory or the relocated shadcn kit loses its `backdrop-filter` (F19.1).
- **OpenAPI spec as the shared contract** for web + mobile, alongside optionally retaining Wayfinder v0.1.21 for typed web route builders (F19.4, F19.8).
