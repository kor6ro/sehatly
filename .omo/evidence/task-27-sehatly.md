# Todo 27 — Booking create, list, cancel, doctor-side list

## Endpoints and middleware (`php artisan route:list --path=booking`)

```
POST api/v1/booking              booking.store         → BookingController@store
PUT  api/v1/booking/{id}/batalkan booking.batalkan      → BookingController@batalkan
GET  api/v1/dokter/booking       dokter.booking.index  → BookingController@indexDokter
GET  api/v1/pasien/booking       pasien.booking.index  → BookingController@indexPasien
```

Middleware, asserted by `BookingTest` against the live route table:
- all four: `auth:sanctum`
- POST: `permission:booking.buat`
- both GETs: `permission:booking.lihat`
- `GET dokter/booking` additionally: `tipe:dokter`
- PUT: `permission:booking.batal`

The brief says "three endpoints"; the plan implements four routes (patient list
and doctor list are distinct). Shipped four, reported not faked. `PUT
booking/{id}/batalkan` carries `->whereNumber('id')`; no route-model binding
anywhere, per the file's convention. `GET dokter/booking` is registered BEFORE
the public `dokter/{dokter}` wildcard so the literal is never swallowed.

## DDL citations behind each rule (`telemedicine_test.sql`, SHA-256 unchanged)

`aefe2247e00f09acb02235168ac289cdfa74f762d604ada71f68e328574b27f5`
(byte-identical to the todo-26/30 baseline; no migration, seeder, or SQL byte
touched).

- No slot-triple constraint: only `UNIQUE(nomor_booking)` (`:500`) plus
  `INDEX idx_booking_dokter (dokter_id, tanggal_kunjungan)` (`:528`) and
  `INDEX idx_booking_pasien` (`:529`). Asserted against the LIVE table
  (`SHOW INDEX`), so a migration adding one fails the suite. The guard is
  therefore application-level, which is why the `dokter` row lock exists.
- `booking.jadwal_id BIGINT UNSIGNED NULL` (`:504`); `slot_mulai` /
  `slot_selesai TIME NOT NULL` (`:508-:509`) — an instant booking is a real row.
- `tipe_layanan ENUM('chat','video_call','kunjungan_klinik','home_visit')`,
  NOT NULL, no default (`:506`). Validated with `Rule::in`, never `Rule::enum`.
- `status` eight-value ENUM in DDL order (`:515-516`):
  `menunggu_pembayaran, terjadwal, check_in, berlangsung, selesai, dibatalkan,
  no_show, kadaluarsa`, default `'menunggu_pembayaran'`. The eighth value is
  `no_show`; an earlier plan draft said seven and was wrong.
- Exclusion set `('dibatalkan','kadaluarsa')` is REUSED from
  `SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI` (asserted as identity),
  never restated.
- `dibatalkan_oleh ENUM('pasien','dokter','sistem') NULL` (`:517`);
  `alasan_pembatalan` (`:518`); `dibuat_oleh_user_id NOT NULL` with FK (`:519`).
- `nomor_antrian SMALLINT UNSIGNED NULL` (`:510`): no counter, no uniqueness —
  left null rather than filled with a guess.
- `lampiran_keluhan JSON NULL` (`:512`); `keluhan TEXT NULL` (`:511`).
- `is_rujukan` / `is_konsultasi_lanjutan TINYINT(1) NOT NULL DEFAULT 0`
  (`:513-:514`): provenance facts, never discounts.
- `dokter_jadwal` (`:470-:488`): `hari` 0=Minggu..6=Sabtu (`:475`),
  `durasi_slot_menit` (`:478`), `kuota_per_sesi` (`:479`),
  `berlaku_sampai` inclusive (`:481`), `faskes_id NULL = online murni` (`:473`),
  `tipe_layanan` three values without `chat` (`:474`).
- `dokter_libur` (`:490-:496`); `invoice` (`:936-:956`) with `total` the only
  money column WITHOUT a default (`:946`); `dokter.biaya_konsultasi_online
  DECIMAL(12,2)` (`:420`); `durasi_default_menit` (`:422`); `str_berlaku_sampai`
  (`:414`); `users.tipe` seven values (`:139`); `pasien.nik CHAR(16) NULL
  UNIQUE` (`:222`).

## Locking strategy and the interleaving proof

Every write runs in one `DB::transaction` whose FIRST statement is
`Dokter::whereKey($id)->lockForUpdate()->firstOrFail()` — always, whether or
not `jadwal_id` is supplied. An earlier draft locked `dokter_jadwal` when
present and `dokter` otherwise; under that design a scheduled and an instant
request lock different rows and both pass the check. The `dokter_jadwal` row,
when named, is locked second. The capacity answer is a current read —
`SELECT id ... FOR UPDATE` counted in PHP, never an aggregate — so a retry
after commit observes the row the first transaction wrote. Overlap is
half-open both ends; quota is `count < ($kuota_per_sesi ?? 1)`. Only
`UniqueConstraintViolationException` retries (3 nomor attempts, inside the
transaction); a lock-wait 1205 propagates so a competitor can observe it.

`BookingConcurrencyTest` proves it with two real connections: fixtures are
committed out of the `RefreshDatabase` wrapper, a second connection is built
from a copied config with `innodb_lock_wait_timeout = 1` and explicit
`REPEATABLE READ` on both sides, and a synchronous `DB::listen()` listener
runs the real service on the second connection the instant the first executes
`FROM dokter ... FOR UPDATE`. Measured: B answers MySQL 1205 while A holds the
row; after A commits, B is refused by capacity (`SlotTakenException`); five
alternating creates yield exactly one row and four refusals; a scheduled and
an instant request serialise on the one `dokter` row with the dokter lock
ordered before the jadwal lock.

Slot geometry is never re-derived: an explicitly named row, or the published
slot `getSlotTerbuka()` resolves to, decides end/venue/quota; `chat` never
consults the schedule (`dokter_jadwal.tipe_layanan` has no `chat`); a start
nothing publishes on a doctor running schedules is refused; otherwise the
instant path derives the end from `dokter.durasi_default_menit` with the STR
(`StrBerlaku::berlakuPada`, consultation date, inclusive, fail-closed),
elapsed-today (database `CURDATE()`), and positive-length rules.

## Red/green transcript (isolated DB `telemedisin_db_test_sisyphus`)

- RED, implementation absent:
  `{"tool":"pest","result":"failed","tests":40,"passed":5,"assertions":89,
  "duration_ms":67237,"failed":27,"errors":8}` — 27 failures are HTTP 404s plus
  the route-registration and request-class structural tests; all 8 errors are
  missing production classes (`BookingService` x4, `BookingRequest` x2,
  `NomorDokumen` x2). No warnings, no DB errors.
- GREEN, implementation present:
  `{"tool":"pest","result":"passed","tests":40,"passed":40,"assertions":419,
  "duration_ms":41887}` (post-Pint; pre-Pint green was 40/40/419 at 42156ms).

## Mutations (both killed, reverted byte-identical by SHA-256)

- M1 remove the `dokter` `lockForUpdate`: the interleaving test fails — the
  first observed statement is `select * from dokter ... limit 1` without
  `for update`, so no serialization point exists.
- M2 close the interval (`<=` / `>=`): the back-to-back boundary test fails —
  a booking ending exactly when the slot begins is refused 422 instead of 201.

## Audits

- ASCII gate `[^x00-x7F\u2013\u2014\u2022\u2026\u2192\u2212\u00A7\u2225]` over
  all 16 authored/modified files: 0 violations (pure ASCII throughout).
- Token audit: 64 distinct `snake_case` tokens; 11 not in the DDL, every one
  reviewed — 8 PHP builtins/language tokens, `per_page` (paginator/query
  param), `telemedicine_test` (the filename), and `lewat_waktu` (a docblock
  reference to `ALASAN_LEWAT_WAKTU`, spelling verified). UNRESOLVED 0 real
  identifiers.
- `vendor/bin/pint --test`: clean on every authored/modified file (3 files
  auto-fixed: `BookingService`, `BookingTest`, `BookingConcurrencyTest`;
  green re-verified after).

## Test delta and verifier

- Baselines (shared DB, concurrent todo-30 churn): 428/417/11, then 445/397/28.
- Final full suite on the isolated DB: 456 tests, 454 passed, 2 failed — both
  failures pin the database NAME (`telemedisin_db_test`) and are artifacts of
  the isolation override, not code. Those two files pass 14/14 on the standard
  database.
- Cross-todo pins updated as their own comments anticipate (route table closed
  set + pasien count 10→11 + guard allow-map; both `permission:`/`tipe:`
  tripwires now pin exactly the five Module-2 codes, all resolving against
  `RbacCatalog`).
- `php artisan route:list --path=booking`: 4 routes above, exit 0.
- `php artisan sehatly:verify-schema`: PASS, 75 tables, 2 views, 0 drift,
  7 informational (the 7 registered framework extra tables). Nothing written.

## Findings

- F1 brief-vs-plan: "three endpoints" vs four routes; shipped four.
- F2 todo-44 `InvoiceService` does not exist; the booking invoice is created
  inside the booking transaction as a documented narrow seam (same `BK`/`INV`
  shape, fee from `dokter.biaya_konsultasi_online`, zero discount/admin/
  shipping, status left to the DDL default).
- F3 test-authoring defects fixed in own files, none weakening assertions:
  `withToken()` capturing the TestCase; 1-of-5 missing try/catch; quota final
  count 1→2 (fixture + created); Tuesday 2026-12-08 for the non-working
  weekday (`BKU_TANGGAL_LAIN` is a worked Monday, proven by the weekday test);
  collision half-2 planting attempts [5,6,7] (global sequence; 1-3 collide
  with half-1 at fixture time); explicit `menunggu_pembayaran` on the expiry
  fixtures (`bkuBookingRow`'s `terjadwal` default is load-bearing for the
  doctor-404 test); `bkcSelesai()` now also removes the committed RBAC seed
  rows (`RbacSeeder` inserts are plain/non-idempotent by design).
- F4 measurement, not assumption: `lockForUpdate()->count()` aggregate locking
  was not relied on — the count is a row-returning `pluck('id')` counted in
  PHP, so the locked rows are observed, not inferred.
- F5 shared-DB collisions independently confirm todo-30's ledger: two
  executors on one `telemedisin_db_test` drop each other's tables mid-run
  (observed twice: `migrations` missing / `cache` already exists). All
  booking runs above used the private `telemedisin_db_test_sisyphus`
  (created empty via the app's own connection; `DB_DATABASE` override wins
  over `phpunit.xml` since it sets no `force`).
- F6 `chat` bypasses schedule lookup by type (see locking section); without
  it the collision test's second half cannot reach numbering.
