# Verification report — todo 54

**Produced by:** the todo-54 executor, at HEAD `32d40fc`.
**Rule this report obeys:** every number below came from a command *this executor ran*, with the
output captured in the session. Nothing is copied from `.omo/evidence/`, from the ledger, from
`docs/contract-conformance.md`, or from any earlier report. Where a figure was not obtainable it
is written **"not measured"** rather than borrowed.

**Verdict: the plan's todo-54 bar is NOT met.** `pint --test` fails on 87 files, `dart format`
exits 1, the plan's own Dart integration test does not exist, and the seeded database cannot
support the walkthrough without manual row insertion. Four items that earlier todos reported as
addressable are restated as **open** in §7, and §6 records **six defects the live runs found**,
three of which are new to this report.

---

## 0. A tooling hazard that invalidates a naive reading of this session

Before any result: **the stdout of `vendor/bin/pest` and `vendor/bin/pint` in this environment is
replaced by a synthetic JSON envelope.** It is not the tool's own output. Proven with a cheap
probe that cannot pass by accident:

```
> cmd /c "vendor\bin\pest.bat --colors=never --filter=zzz_no_such_test_zzz > probe.txt 2>&1"
EXIT=1
BYTES=113
{"tool":"pest","result":"failed","tests":0,"passed":0,"assertions":0,"duration_ms":6,"raw":["No tests found."]}
```

A filter matching nothing cannot run 1153 tests. The envelope reports `tests: 0`, which is
correct, and it carries the genuine text in `raw`. On the **full** run the same envelope was
emitted with **no `raw` field at all**:

```
{"tool":"pest","result":"passed","tests":1153,"passed":1153,"assertions":22510,"duration_ms":545544}
```

That line was **discarded, not reported.** Two independent reasons:

1. It has no `failed`/`errors` key, so per the reporter control it proves nothing.
2. Its `duration_ms` of **545 544 ms (545.5 s)** contradicts the run's own JUnit `time` of
   **596.298644 s**, which the test process wrote itself. The envelope is not a measurement.

Everything below therefore comes from **files the tools wrote themselves** — `--log-junit` for
Pest, `--output-to-file --output-format=junit` for Pint — which the stdout interceptor cannot
touch. `verify-schema`, `openapi --check`, `enums --check`, `check-doc-links.mjs`, `dart`,
`npm` and `composer` all returned their real output unwrapped and are quoted directly.

---

## 1. Test suite — GREEN, zero skipped

`phpunit.xml` was **not modified**. A private database was used via a per-run override:

```powershell
$env:DB_DATABASE = "telemedisin_db_test_t54"
php artisan migrate --seed --force          # exit 0
vendor\bin\pest --colors=never --log-junit=<tmp>\t54-junit.xml
```

**Reporter control, asserted before the count is read.** The JUnit root carries an explicit
`skipped` attribute; the value is read from the attribute, not inferred from an absent key.

```
root has 'skipped' attribute: True -> value '0'
```

`docs/contract-conformance.md` warns that the Pest JSON reporter omits `failed`/`errors` when the
count is zero. The JUnit format does not have that defect, which is why it was chosen.

### Measured

| Measure | Value | Source |
| --- | --- | --- |
| tests | **1153** | `<testsuite ... tests="1153">` |
| assertions | **22510** | `assertions="22510"` |
| **failures** | **0** | `failures="0"` |
| **errors** | **0** | `errors="0"` |
| **skipped** | **0** | `skipped="0"` (attribute present) |
| duration | **596.298644 s** | `time="596.298644"` |
| JUnit file | 438 023 bytes, SHA-256 `394D5766681597331C266D54D9DAC0CDDAEE4CFD668B8254B540A9C002B1442A` | `Get-FileHash` |

Verbatim root element:

```xml
<testsuite name="C:\Users\axioo\Desktop\sehatly\phpunit.xml" tests="1153" assertions="22510"
          errors="0" failures="0" skipped="0" time="596.298644">
```

Suite split, read from the same file:

```
  Unit       tests=146    assertions=7532    errors=0 failures=0 skipped=0 time=98.086256
  Feature    tests=1007   assertions=14978   errors=0 failures=0 skipped=0 time=498.212388
```

Arithmetic checked: 146 + 1007 = 1153, and 7532 + 14978 = 22510.

**The zero-skipped bar is MET, and it is corroborated three ways** — the `skipped="0"` attribute,
the presence of **0** `<skipped>` child elements across all 1153 `<testcase>` nodes, and the
presence of **0** `<failure>` and **0** `<error>` child elements.

### `markTestSkipped` census

`grep` over all 83 PHP files under `tests/` for `markTestSkipped|->skip(|skipIf|@skip|skip(`:

```
tests/TestCase.php:19:  *         $this->markTestSkipped(...);
tests/TestCase.php:32:  * 3. **It was the only `markTestSkipped` in the repository.** ...
```

**Two hits, both inside a docblock**, quoting the helper that todo 30 deleted. There is no live
`markTestSkipped` call and no live `->skip()` anywhere in the suite. The plan's criterion
("returns only individually justified entries") is satisfied.

---

## 2. Routes — 74 = 74, measured two ways

```powershell
php artisan route:list --path=api/v1 --json > t54-routes.json
```

```
API_V1_JSON_ENTRIES=74
```

Independently, by the generator itself:

```
php artisan sehatly:openapi --check
```

```
 UP TO DATE -- every generated file is byte-identical to a fresh export
 routes read 74 under api/v1
 paths / operations 65 paths, 74 operations
 openapi.yaml 286466 bytes, sha256 b9e30b49ea9ba82b74dd1528d4b3420c11d18c8681fd670016c206b5877ac077
 enums.dart 150895 bytes, sha256 ed37322f9b11a7e2bb2a9872d06245ef4977ebe98518b5af96f9761821364c84
 request_bodies.dart 128362 bytes, sha256 e3912bcbe1385f88d2703a6692477cfcb3dfc3c89f44e10d4a78544367fe78b3
 paths_table.dart 19203 bytes, sha256 994cd90e7b9a2e1ff496cfe81842c8f9906a6e4e07abcd1908d29050776b9fdc
OPENAPI_CHECK_EXIT=0
```

**74 live routes, 74 published operations — parity holds, and `--check` exits 0.** The four
generated artefacts' hashes were independently recomputed with `Get-FileHash` and match the
generator's own report byte for byte.

`docs/contract-conformance.md` records 74 operations in `docs/openapi.yaml` and 74 in
`Route::getRoutes()`. I did not re-run its `SpecRouteParityTest`; **that suite lives in
`tests/Contract/`, which `phpunit.xml` does not declare, so it was NOT included in the 1153 above.
Its own 201 tests / 2669 assertions are "not measured" by me.**

### Spec-route parity cross-check

`php artisan sehatly:openapi --check` walks the live route table and re-renders the document; a
route added, renamed or removed would change the bytes and fail the check. It exits 0. **74 = 74.**

---

## 3. Schema — 75 tables, 2 views, zero drift, 7 informational

```powershell
php artisan sehatly:verify-schema
```

Exit **0**. Verbatim, the parts that carry the numbers:

```
 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables; ...)

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3

 Live schema
 counts tables=82 views=2 columns=715 indexes=237 foreign_keys=105 checks=3

 Discrepancies: 7 (0 drift, 7 informational)
 ...
 PASS — 75 tables, 2 views verified. Nothing was written.
```

**Stated plainly, as required:** the verifier reports **7 discrepancies**, of which **0 are
drift** and **7 are informational**. The 7 are the framework-registered tables, each named in the
output: `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`,
`personal_access_tokens`. They are **not** a discrepancy against the contract and **not** drift.
Total live base tables is **82** = 75 contract tables + those 7.

### The 75-table parity check, measured independently of the verifier

Raw-byte regex over the read-only DDL, and `information_schema` queried directly:

```
SQL  CREATE TABLE statements : 75
SQL  CREATE VIEW  statements : 2
SQL  FOREIGN KEY clauses     : 106
LIVE base tables = 82
LIVE views       = 2
```

Then the subtraction, computed rather than asserted:

```
LIVE_TOTAL        = 82
FRAMEWORK_TABLES  = 7  -> cache, cache_locks, failed_jobs, job_batches, jobs, migrations, personal_access_tokens
DOMAIN_TABLES     = 75
PARITY: contract CREATE TABLE (75) == live minus 7 framework (75) -> MATCH
FRAMEWORK_PRESENT_IN_LIVE = 7 of 7
```

**75 = 75, MATCH.**

**One discrepancy between my own two measurements, reported rather than smoothed over:** my raw
regex counts **106** occurrences of `FOREIGN KEY` in `telemedicine_test.sql`, while the
verifier's parser reports **105** `foreign_keys`. The likely cause is that one FK sits inside one
of the 11 wrapped multi-line `ENUM`/`CHECK` declarations the verifier reads as a single unit. I
did not chase it to a conclusion — **flagged, not resolved.** It does not affect the table count.

### The contract DDL is byte-unchanged

```powershell
Get-FileHash telemedicine_test.sql -Algorithm SHA256
```

```
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
prefix: AEFE2247E00F      size: 59,604 bytes
```

**Matches the expected `AEFE2247E00F...` prefix. Byte-identical. No drift, no critical finding
here.** `git status` reports the file unmodified.

---

## 4. Generated contract, docs, and the no-Flutter guard

| Command | Exit | Measured result |
| --- | --- | --- |
| `php artisan sehatly:openapi --check` | **0** | UP TO DATE; 74 routes, 65 paths, 74 operations (see §2) |
| `php artisan sehatly:enums --check` | **0** | `UP TO DATE -- docs/enums.json is byte-identical to a fresh export` / `live / DDL 69 ENUM columns, 319 values, 0 divergences` / `sha256 ed87600aba847dbf27c8dfb1a590a88e42efabfeefe56817e9d5e527a52c2be6` |
| `node tools/check-doc-links.mjs` | **0** | `OK -- 1 document(s); 29 links, 19 repo paths, 53 api-path mentions, 19 dart blocks, 0 external URLs skipped` |
| `node tools/check-doc-links.mjs --expect-sections 14 docs/mobile-integration.md` | **0** | same OK line — this is the exact invocation `package.json`'s `docs:check` uses |
| `composer validate --no-check-publish` | **0** | `./composer.json is valid` |

### No Flutter app, no Flutter constraint

```
Test-Path mobile/ = False
```

`pubspec.yaml` files in the repository, excluding `vendor`/`node_modules`/`.git`:

```
C:\Users\axioo\Desktop\sehatly\packages\sehatly_api_client\pubspec.yaml
```

Exactly one. Read as raw bytes (790 bytes, SHA-256 `2648D13217CA2BE6A5649F5AB87900B37A2528BE0D920ECE7B2A1500BB6E39F1`).
Every occurrence of the string `flutter` in it is inside prose — the description
(`Pure-Dart (Flutter-free)`) and a comment explaining why there is deliberately no `flutter:`
key. The environment block is:

```yaml
environment:
  sdk: '>=3.13.0 <4.0.0'
```

Repo-wide search for a `flutter:` SDK constraint declaration:

```
NONE
```

**`mobile/` is absent and no `pubspec.yaml` declares a `flutter:` SDK constraint. Both hold.**

---

## 5. Dart client, web client, and Pint

### Dart — `packages/sehatly_api_client`

SDK at the path named in the brief, **not on PATH**:

```
Dart SDK version: 3.13.2 (stable) (Tue Aug 25 01:01:12 2026 -0700) on "windows_x64"
```

| Command | Exit | Result |
| --- | --- | --- |
| `dart analyze` | **0** | `Analyzing sehatly_api_client...` / `No issues found!` |
| `dart analyze --fatal-infos` | **0** | `No issues found!` (stricter than required, also clean) |
| `dart test` | **0** | `00:00 +132: All tests passed!` |
| `dart format --output=none --set-exit-if-changed .` | **1** | **`Changed lib\src\generated\enums.dart` / `Changed lib\src\generated\paths_table.dart` / `Changed lib\src\generated\request_bodies.dart` / `Formatted 40 files (3 changed) in 0.28 seconds.`** |

**`dart analyze` passes. `dart format` FAILS — exit 1, 3 of 40 files.** Magnitude measured by
formatting copies in a temp directory and diffing line counts, without touching the repository:

```
lib\src\generated\enums.dart            original_lines=4534  formatted_lines=4682  differing_lines=4541
lib\src\generated\paths_table.dart      original_lines=676   formatted_lines=549   differing_lines=620
lib\src\generated\request_bodies.dart   original_lines=3379  formatted_lines=3329  differing_lines=3275
```

All three are **tracked, committed and clean in git** (`git ls-files --error-unmatch` succeeds
on all three; `git status` on the directory is empty). **This is a disclosed gap, not a
surprise:** the README's "Known gaps" section already states that
`dart format --set-exit-if-changed` is not clean on `lib/src/generated/` and gives the reason —
the generator emits one collection element per line for byte determinism, and `dart analyze` is
the enforced gate. I report it as an **open, accepted trade-off with a live exit code of 1**,
not as a new defect.

### Web — `web/`

| Command | Exit | Result |
| --- | --- | --- |
| `npm run types:check` (`tsc --noEmit`) | **0** | no diagnostics |
| `npm run test:unit` | **0** | `tests 37 / suites 0 / pass 37 / fail 0 / cancelled 0 / skipped 0 / todo 0` — 37 named tests, **0 skipped** |
| `npm run build` (`vite build`) | **0** | `✓ 3363 modules transformed.` / `dist/assets/index-BL2d11Un.js 1,097.26 kB │ gzip: 325.22 kB` / `✓ built in 1.15s` |

`web/package.json` has exactly six scripts — `dev`, `build`, `preview`, `types:check`,
`test:unit`, `test:e2e`. **All three that are runnable offline were run. `test:e2e`
(`playwright test`) was NOT run — see §8.**

The build emits a chunk-size warning: a single **1 097.26 kB** JS chunk (325.22 kB gzipped)
against Vite's 500 kB advisory. Recorded as an observation; not a failure.

### Pint — **FAILS**

`vendor/bin/pint --test` returned **exit 1**. Because stdout is intercepted for this tool, the
figure was taken from a JUnit file Pint wrote itself:

```powershell
vendor\bin\pint --test --output-to-file=<tmp>\t54-pint.xml --output-format=junit
```

```xml
<testsuite name="PHP CS Fixer" tests="87" assertions="307" failures="307" errors="0">
  <property name="about" value="PHP CS Fixer 3.95.25 ..."/>
```

```
PINT_EXIT=1
PINT junit root: tests=87 assertions=307 failures=307 errors=0
distinct <testcase> file entries = 87
files needing fixes, by top-level directory:
  app          45
  config        1
  tests        41
  total = 87
```

**87 files carry 307 code-style violations at HEAD `32d40fc`.** This directly contradicts two
things the repository asserts about itself:

- the plan's todo-54 criterion **"all ten commands exit 0"** (`pint --test` is one of the ten), and
- the plan's commit strategy, **"Run `vendor/bin/pint` (not `--test`) before every commit"**,
  together with `README.md`'s CI section, which lists `pint --test` as a gating job.

A green `php artisan test` does **not** cover this: no test asserts pint cleanliness. **I did not
fix it** — this todo produces a report, it does not change the product.

---

## 6. Defects the live runs found

Six. Three are new to this report; three corroborate or sharpen what earlier todos recorded.
**None was fixed here.**

### 6.1 NEW — the API is coupled to the broadcaster; a write returns 500 when Reverb is down

With `BROADCAST_CONNECTION=reverb` and nothing listening on 8080:

```
POST /konsultasi/mulai  ->  HTTP 500
{"success":false,"message":"Internal server error.","errors":{}}
```

`storage/logs/laravel.log`, the last entry at that moment:

```
local.ERROR: Pusher error: cURL error 7: Failed to connect to localhost port 8080 after 2248 ms
  ... at vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/PusherBroadcaster.php:171
  #0 Illuminate/Broadcasting/BroadcastEvent.php(109): ...->broadcast(..., 'chat.pesan', Array)
```

**The decisive experiment.** I started Reverb (`php artisan reverb:start`; log:
`INFO Starting server on 0.0.0.0:8080`) and re-issued the **identical** request. The 500 was gone
(it became a 422, a different and expected validation answer). Causality established: the 500
was the broadcaster, not the request.

This **falsifies two claims in the repository**:

- the plan's own QA scenario (c) for todo 54 — *"stop `reverb:start` mid-walk and assert the REST
  chat path still works while realtime is degraded, proving the API is not coupled to the
  broadcaster"* — the coupling is real;
- `README.md` — *"`BROADCAST_CONNECTION=null` disables realtime; the REST chat path still works
  without it, which is the point."* It does not: the default `.env` sets `reverb`, and with
  Reverb unreachable a **write** endpoint 500s.

**Worse, the failure is not atomic.** The request that returned 500 had already committed its
database writes. A subsequent retry of the same request returned:

```
POST /konsultasi/mulai -> HTTP 422
{"errors":{"booking_id":["Booking ini sudah memiliki sesi konsultasi."]}}
```

and the row was there:

```
{"id":1,"booking_id":1,"pasien_id":3,"dokter_id":1,"tipe":"chat","status":"menunggu_dokter",
 "room_id":"e17f9b79-2b2d-477b-b650-ab4ef9f7aa21","mulai_at":null,"selesai_at":null,
 "biaya_konsultasi":"0.00","dibuat_at":"2026-09-29 23:46:43","diubah_at":"2026-09-29 23:46:43"}
```

with `konsulasi_chat` already holding the system message. So a client that retries a 500 gets a
422 that reads like a business-rule rejection. **Also note: the 500 left no entry in
`laravel.log` at all** — the last log line is the Pusher error, so a 500 of this class is
invisible to log-based alerting.

### 6.2 NEW — `promo/validasi` computes a discount it never applies

The walkthrough applied a live promo to a real invoice:

```
POST /promo/validasi  {"kode":"T54HEMAT","invoice_id":2}  ->  HTTP 200
{"promo":{"kode":"T54HEMAT","tipe_diskon":"persen"},
 "valid":true,"nilai_diskon":"34650.00","total":"326850.00",
 "rincian":{"subtotal":"346500.00","diskon":"34650.00","biaya_pengiriman":"15000.00","total":"326850.00"},
 "alasan":[]}
```

The response says the total becomes **326 850.00**. The invoice row immediately afterwards:

```
{"id":2,"subtotal":"346500.00","diskon":"0.00","biaya_admin":"0.00",
 "biaya_pengiriman":"15000.00","total":"361500.00","status":"menunggu_pembayaran"}
promo_redemption rows = 0
```

**`diskon` is still `0.00`, `total` is still `361 500.00`, and no redemption was recorded.** The
payment that followed charged the full amount:

```
POST /invoice/2/bayar -> HTTP 201   "jumlah":"361500.00"
```

So the endpoint is a **preview that misreports its own effect**: it promises a 34 650.00 discount
and the money charged ignores it. This is a money-affecting defect on Module 5 and it is
reachable by any client that follows the endpoint's own success payload.

### 6.3 NEW — `notifikasi` has no writer; the read side is unreachable by construction

`GET /api/v1/notifikasi` was called at three points across the walkthrough (after the
consultation, after the prescription, after checkout). Every time:

```
{"data":{"notifikasi":[]},"meta":{...,"total":0,"unread":0}}
notifications = 0
DB notifikasi rows = 0
```

Cause, measured not guessed: `App\Services\Notifikasi\NotificationService` is `final` and offers
exactly five producers — `bookingDibuat`, `bookingDibatalkan`, `pembayaranSelesai`, `resepSiap`,
`pesanBaru`. A repository-wide search for `NotificationService` in `app/` returns only: its own
file, a docblock in `app/Enums/NotifikasiTipe.php`, docblocks in
`NotifikasiController.php` and `NotifikasiResource.php`, and the push transport in
`AppServiceProvider.php`. **No caller anywhere.**

The table's columns are `id, user_id, judul, isi, tipe, tautan, payload, dibaca_at, dibuat_at`.
Every one of the five notifiable events in this walkthrough — booking created, payment settled,
chat message sent, prescription ready, order placed — produced **zero** rows. The plan's final
step, *"read the notification"*, cannot return anything the application wrote. The notification
feature is read-side scaffolding over a write side that is not wired.

### 6.4 NEW — no shipped seeder writes `user_roles`, so every fixture account 403s

The first doctor token I minted came back `HTTP 403 This action is unauthorized.` even though the
login and OTP verify both returned 200 and a valid token.

Cause, measured across all twelve seeder files:

```
ArtikelKategoriSeeder.php / DatabaseSeeder.php / DevFixtureSeeder.php / IcdSeeder.php /
LabSeeder.php / MasterUmumSeeder.php / MasterWilayahSeeder.php / MetodePembayaranSeeder.php /
ObatSeeder.php / PenjaminSeeder.php / RbacSeeder.php / SpesialisasiSeeder.php
   -> user_roles-insert matches = 0   (all twelve)
```

After a full `migrate --seed`, `user_roles` held **1** row — the one `POST /auth/register` created
for the patient I registered through the API. `RbacSeeder` documents the omission as deliberate
("It writes no `user_roles` rows"), and nothing else fills it. So **every account that
`DevFixtureSeeder` creates — all three doctors — holds zero RBAC grants and is refused with 403 on
every `permission:`-guarded route**, including after a fully successful login. I had to insert
`user_roles` by hand to continue. This is the same root cause behind the 403-coverage gap in §7.3.

### 6.5 NEW — the booking's and the order's invoice id are never exposed

`POST /booking` returns the booking; `GET /pasien/booking` returns the booking. **Neither carries
an invoice.** `app/Http/Resources/BookingResource.php` — `BookingResource mentions invoice? NO`.

But the booking *does* create one:

```
{"id":1,"nomor_invoice":"INV20260930NOPRYO","referensi_tipe":"booking","referensi_id":1,
 "subtotal":"50000.00","total":"50000.00","status":"menunggu_pembayaran"}
```

and `POST /resep/{id}/checkout` likewise returns only the order (`PO20260930QUH4PN`), with no
invoice. Yet paying requires `POST /api/v1/invoice/{id}/bayar` and discounting requires
`POST /api/v1/promo/validasi` with a mandatory `invoice_id`. **A client following the published
API cannot learn the invoice id, so it cannot pay or discount.** I supplied both ids by reading
`information_schema`-level tables directly. There is no `GET /api/v1/invoice/{id}`;
`docs/mobile-integration.md` itself already flags that the plan names one and "that endpoint does
not exist".

### 6.6 NEW — a freshly seeded database cannot support the plan's own walkthrough

`migrate --seed` on a private database, then counted:

```
dokter_jadwal = 0        (no schedule -> no slots -> booking impossible)
dokter_libur  = 0
apotek_stok   = 0        (no stock to sell)
faskes        = 2        (tipe = klinik, laboratorium -- NO tipe='apotek')
master_promo  = 0        (no promo to apply)
users         = 7        (3 dokter, 2 pasien, 1 unverified dokter, 1 registered patient)
```

`roles` contains `pasien, dokter, apoteker, admin, superadmin` — but **no user with
`tipe = 'apoteker'` is ever created**, so the pharmacist verification step has no account.

**There is also no API to create any of these rows.** The route table has no POST for
`dokter_jadwal`, none for `master_promo`, none for `faskes`, none for `apotek_stok`, and no
role-assignment endpoint. So on a fresh install the booking, prescription-fulfilment, promo and
pharmacist-verification steps are **unreachable through the product surface, not merely unseeded.**

Live proof of the booking half, before any fixture rows existed:

```
GET  /dokter/2/jadwal  -> 200  {"jadwal":{"0":[],"1":[],"2":[],"3":[],"4":[],"5":[],"6":[]}}
GET  /dokter/2/slot?tanggal=2026-09-30 -> 200  {"slots":[]}
POST /booking          -> 422  {"errors":{"slot_mulai":["The jam mulai field is required."]}}
```

and the payment half:

```
POST /konsultasi/mulai -> 422
{"errors":{"booking_id":["Konsultasi hanya dapat dimulai dari booking dengan status terjadwal atau check_in."]}}
```

because the booking sat at `menunggu_pembayaran` — the gateway webhook is the only thing that
advances it, and nothing in a fresh install sends one.

### Corroborated, not new

`GET /dokter/2/jadwal` and `GET /dokter/2/slot` both answered with `"per_page":0` on an empty
single-page result. That is exactly `docs/contract-conformance.md` **Finding 4** (the published
`PaginatedMeta.per_page` declares `minimum: 1`; `ApiResponse::singlePageMeta()` sets it to the row
count). I hit it live, unprompted, which is corroboration rather than discovery.

The same first response also showed Finding 2 and Finding 1 in one line of JSON —
`data` came back as an **object** keyed by resource name (`{"agama":[...]}`) with `meta` present
as a top-level fourth key, where the published `PaginatedEnvelope` types `data` as an array and
forbids `meta` on non-paginated reads. Both reproduced.

One further observation, not classified as a defect: `GET /notifikasi` returns a `meta` block
carrying a **seventh key, `unread`**, which the six paginated endpoints I exercised do not emit.
Whether `PaginatedMeta` in `docs/openapi.yaml` declares it was **not measured**.

---

## 7. The four open items, restated as open

These are **not** closed. I am not presenting them as closed.

### 7.1 FINDING-48-race — the webhook dedupe is safe serially, unproven concurrently

`PaymentService::huntap()` dedupes a duplicate delivery with `SELECT ... FOR UPDATE` on
`(gateway, nomor_referensi)`. **There is no unique index on that pair, and a row lock matching no
row locks nothing**, so two concurrent *first* deliveries of the same event are not excluded.
The serial case is safe and tested; the concurrent case is unproven and unfixed.

**What I measured, and it is only the serial half.** First delivery:

```
POST /webhook/payment/midtrans  ->  HTTP 200
{"duplicate":false, "pembayaran":{"status":"berhasil","terminal":true},
 "invoice":{"status":"lunas"}, "referensi":{"tipe":"booking","id":1,"status":"terjadwal","advanced":true}}
```

Identical delivery immediately after:

```
POST /webhook/payment/midtrans  ->  HTTP 200
{"duplicate":true, ...same state...}
```

**`duplicate: false` then `duplicate: true` — the serial dedupe works.** I issued the two
requests sequentially and did **not** run them concurrently, so this report adds **no evidence
about the race**. The finding stands exactly as stated. I reproduced the `nomor_referensi` spelling
(`MOCK-20260930-2DA45E9666C17489CF3A870E`) with no `referencia` typo.

The webhook was signed with the **committed default** secret from `config/services.php`
(`UBAH-SEKRET-WEBHOOK-MIDTRANS-0000000000000001`); `.env` sets no
`PAYMENT_WEBHOOK_SECRET_MIDTRANS`. In a real deployment that placeholder is the HMAC key, which
is its own concern and is **not measured** here beyond the observation that it is a committed
constant with no environment override.

### 7.2 Todo 50 gap — the NIK column type is still `CHAR(16)`

The NIK cipher and the HMAC blind index exist. The DDL still declares `nik CHAR(16)` where the
plan requires ciphertext in **TEXT**, and **no migration was written**. The acceptance criterion
is literally unmet. `php artisan migrate:fresh` was **not** run, no migration was added, and
`telemedicine_test.sql` was not edited. **Open.**

### 7.3 Conformance gap — 17 of 74 live responses do not match their published schema

`docs/contract-conformance.md` reports **57/74** live responses validating and **17 not**, and
**403 coverage of 0** because no role-bearing token was minted. **I did not re-run that suite**
(it is outside `phpunit.xml` and therefore outside my 1153), so its 201 tests / 2669 assertions
are **not measured by me** and I am quoting it only as the open claim it is.

What I *did* add is the mechanism behind the 0. §6.4 shows the shipped seeders write no
`user_roles` rows at all, so a role-bearing token cannot be minted from seeded data. The gap is
not a missing test; it is a missing writer.

### 7.4 The plan's Dart integration test does not exist

The plan requires a fresh integration test at
`packages/sehatly_api_client/test/integration/` exercising auth → booking → consultation →
prescription → checkout **against the live server using only the package's public API**, and
states that "if the mobile team would need to write anything the package does not already
provide, this test fails and the gap is closed."

```
--- dart test dirs ---
support
--- integration dir present? ---
False
```

`test/integration/` **does not exist**. The only subdirectory of `test/` is `support/`. The
acceptance criterion is **unmet and unmeasured** — I have no evidence either way about whether
the package's public API is self-sufficient for that flow, because the test that was supposed to
answer it was never written.

---

## 8. The twelve-step walkthrough — what I observed

**Stack, as required:** `php artisan serve --host=127.0.0.1 --port=8011` — **port 8011, not
8000** — against a **private** database `telemedisin_db_t54` via `$env:DB_DATABASE`, so
`telemedisin_db_test` and `sehatly` were never touched. `php artisan reverb:start` on 8080
(`INFO Starting server on 0.0.0.0:8080`) for the second half. No `migrate:fresh`, no
`migrate:rollback`. Both processes were stopped afterwards; `git status` is clean apart from the
untracked `.playwright-mcp/`, which is not mine.

**Driver:** raw cURL from a throwaway PHP script in the temp directory. Every line quoted below
is real server output.

### Fixture preparation, stated plainly

`POST /auth/register` creates a **patient only** — `RegisterRequest::rules()` has no role field,
and the seeded doctor/pharmacist accounts carry `password_hash(bin2hex(random_bytes(32)))`, i.e.
deliberately unguessable. Combined with §6.6, steps 4–12 could not be reached without preparing
rows. In `telemedisin_db_t54` **only**, I inserted:

```
dokter_jadwal rows inserted : 7      (dokter 1, days 0-6, 09:00-12:00, 15-min slots, 30 days)
faskes(apotek) inserted      : id=3
apotek_stok rows inserted    : 7      (one per seeded drug)
master_promo inserted        : id=1 kode=T54HEMAT (10%, max 50000, 30 days)
apoteker user inserted       : users.id=7 role_id=3(apoteker)
known password set for       : doker1.dev@example.test
user_roles inserted for doker1: users.id=1 role_id=2 dokter
```

The script refuses to write unless the active database is `telemedisin_db_t54`. **This is
fixture preparation, not part of the product flow, and it is disclosed here so no reader mistakes
the walkthrough for a clean-room run.** No repository file was changed by any of it.

### What each step actually did

| # | Step | Observed |
| --- | --- | --- |
| 1 | register a patient | `POST /auth/register` → **201**. `data.otp.kode = "067383"`, `tujuan=verifikasi_telepon`, `ttl_detik=300`. **Response carries NO token** — asserted: `register response contains a token? NO`. `status=pending_verifikasi`. |
| 2 | verify the OTP | `POST /auth/otp/verify` → **200**, `kode=067383`. `data.token.access_token` (len 50) + `refresh_token` (len 80), `expires_in=86399`, `token_type=Bearer`. `status` `pending_verifikasi` → `aktif`, `telepon_terverifikasi` → `true`. `GET /me` → 200. **The two-step design is real and confirmed.** |
| 3 | browse doctors | `GET /dokter` (no token) → **200**, 2 doctors. `GET /dokter/2/jadwal` → 200, **all seven day-lists empty**. `GET /dokter/2/slot?tanggal=2026-09-30` → 200, `slots: []`. After the schedule fixture: **24 slots**, first `jam_mulai 09:00:00`. |
| 4 | book a slot | First attempt → **422** `slot_mulai required` (no slots existed). After the schedule fixture: `POST /booking` → **201**, `id=1`, `nomor_booking=BK20260930AGQJKA`, `status=menunggu_pembayaran`. |
| 5 | pay | `POST /invoice/1/bayar {metode_id:12}` → **201**, `nomor_referensi=MOCK-20260930-2DA45E9666C17489CF3A870E`, gateway `midtrans`, `va_number=88480566772404930301`, `status=pending`. The signed webhook `POST /webhook/payment/midtrans` → **200**, `duplicate:false`, payment `berhasil`, invoice `lunas`, and **`referensi: {"tipe":"booking","id":1,"status":"terjadwal","advanced":true}`**. Repeat delivery → **200**, `duplicate:true`. **The invoice id came from the database — see §6.5.** |
| 6 | start a consultation | **Not exercisable in the intended shape.** `POST /konsultasi/mulai` is a **patient** action; calling it with the doctor token returned **403** from `ownPasien()` (my error, corrected). With the patient token it returned **500** when Reverb was down (§6.1) and, once the broadcaster was up, the row from the failed attempt already existed so it returned **422** `Booking ini sudah memiliki sesi konsultasi.` The consultation did get created: `id=1`, `status=menunggu_dokter`, `room_id=e17f9b79-…`. `PUT /konsultasi/1/terima` (doctor) → **200**, `status=berlangsung`, `mulai_at=2026-09-29T23:48:34Z`. |
| 7 | chat in two contexts | `POST /konsultasi/1/chat` as **patient** → **201** (`pengirim_tipe:"pasien"`). Same endpoint as **doctor** → **201** (`pengirim_tipe:"dokter"`). `GET /konsultasi/1/chat` as patient → **200**, **4 messages** including two system lines ("Konsultasi dimulai. Menunggu dokter.", "Dokter telah bergabung. Konsultasi berlangsung."). **This is two authenticated contexts over HTTP, not two browser windows** — see §9. |
| 8 | complete the SOAP | `PUT /konsultasi/1/selesai` with the four SOAP fields → **200**, `status=selesai`, `selesai_at=2026-09-29T23:48:36Z`, `total_durasi_detik=2`, all four notes echoed back. |
| 9 | save and finalise the record | `POST /konsultasi/1/rekam-medis` → **201**, `rekam_medis.id=1`, `uuid=d5737f6c-…`, `diagnosis_kerja=URTI`. `PUT /rekam-medis/1/final` → **200**. `GET /rekam-medis/1` as the **patient** → **200**, full record returned. |
| 10 | prescription with an interaction warning | `POST /konsultasi/1/resep` (Amoxicillin 2 + Metformin 5) → **201**, `nomor_resep=RX202609305COVT0`, `berlaku_sampai=2026-10-07` (exactly +7 days). `GET /resep/1/cek-interaksi` → **200** with a real warning: `{"sumber":"antar_item","kunci":"antar_item:2:5","tingkat":"berat","obat_a":{"id":2,"nama":"Amoxicillin"},"obat_b":{"id":5,"nama":"Metformin"}}`. **A second run also produced a `riwayat_resep` warning, `tingkat:"berat"`, `ganda:true`.** |
| 11 | verify as a pharmacist | `POST /resep/1/verifikasi {status:"ada_koreksi"}` with the **apoteker** token → **201**, `status=diverifikasi`. The role gate behaved exactly as `routes/api.php` documents (`tipe:apoteker` + `permission:resep.verifikasi`). |
| 12 | checkout, promo, pay, notification | `POST /resep/1/checkout` → **201**, `nomor_pesanan=PO20260930QUH4PN`, `apotek_id=3` auto-resolved, `alamat_kirim` derived from the patient's own address, `subtotal=346500.00`, `total=361500.00`. `POST /promo/validasi` → **200**, `valid:true`, `nilai_diskon=34650.00` — **and the discount was never persisted: see §6.2.** `POST /invoice/2/bayar` → **201**, charged the **undiscounted** 361 500.00. `GET /pesanan-obat/1` → **200** with a `tracking` array. `GET /notifikasi` → **200** with `notifikasi: []`, `unread: 0` — **see §6.3.** |

### What I could NOT exercise

Stated plainly rather than papered over:

1. **No browser was driven at any point.** Playwright browser binaries were never downloaded
   (`docs/pre-existing-defects.md` §4 records `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`; `npx playwright
   install` was not run), so `web/`'s `test:e2e` script was **not executed**, no screenshot was
   captured, and no `web/dist` UI state was verified in a browser. **Steps 7 and 12's UI halves are
   "not exercised".** I substituted two authenticated HTTP contexts for "two browser contexts",
   which is weaker and is labelled as such above.
2. **No network log per step** was captured. I recorded status codes and response bodies, which is
   not the same artefact the plan asked for.
3. `npm run dev` (Vite) was never started, so no SPA request was observed.
4. Steps 6 and 12 could not be completed in the shape the plan describes, for the reasons in
   §6.1, §6.5 and §6.6. They were completed only with the disclosed fixture preparation and a
   database-read invoice id.

---

## 9. Requirement-by-requirement ledger

| Plan requirement | Measured | Met? |
| --- | --- | --- |
| all ten commands exit 0 | 8 of 10. `pint --test` = **1**; `dart format --set-exit-if-changed` = **1** | **NO** |
| report lists each command, exit code, total test count | §1–§5, every command with its exit code; 1153 tests | yes |
| `markTestSkipped` grep returns only justified entries | 2 hits, both inside a `tests/TestCase.php` docblock | yes |
| `verify-schema` reports 75 tables and 2 views | `tables=75 views=2`, `PASS — 75 tables, 2 views verified`, exit 0 | yes |
| zero drift | `Discrepancies: 7 (0 drift, 7 informational)` | yes |
| `docs/contract-conformance.md` shows zero failures | it reports **17 failures**; not re-run by me | **NO** (see §7.3) |
| zero skipped tests | `skipped="0"`, 0 `<skipped>` elements | yes |
| Dart integration test completes the full flow with no hand-written requests | `test/integration/` **does not exist** | **NO** (see §7.4) |
| endpoint cross-check: zero missing, zero extra | `check-doc-links.mjs` exit 0; generator reports 74 = 74. I did **not** diff all five module summaries by hand | **not measured** |
| a screenshot plus network log for each of the twelve steps | **0 screenshots, 0 network logs** — no browser available | **NO** |
| `telemedicine_test.sql` byte-unchanged | `AEFE2247E00F…`, 59 604 bytes | yes |
| no `mobile/`, no `flutter:` constraint | absent; one pubspec, no `flutter:` key | yes |

---

## 10. What this todo did not do

- It changed **no** product file. `git status` is clean apart from the untracked
  `.playwright-mcp/`, which predates this todo and is not mine.
- It did **not** add an index, a constraint or a migration; did **not** run `migrate:fresh` or
  `migrate:rollback`; did **not** touch the `sehatly` database; did **not** modify `phpunit.xml`;
  did **not** edit `telemedicine_test.sql`, `docs/openapi.yaml`, `docs/contract-conformance.md`,
  `docs/timezone-policy.md`, `docs/mobile-integration.md` or `README.md`.
- It **did not fix** the six defects in §6, including the money-affecting promo bug and the
  broadcaster coupling. Each is reported, not repaired.
- It **did not** fix `pint --test` (87 files) or `dart format` (3 files).
- It **did not** write the Dart integration test, and it did not mint a role-bearing token
  through any supported path, because none exists.
- Database `telemedisin_db_t54` was created for this todo and left in place with its fixture rows.
  Database `telemedisin_db_test_t54` was created for the suite run. Neither is shared state; the
  owner may drop both.

## 11. Recommended next actions, in priority order

1. **Fix the promo path** (§6.2) — a validated discount that is never applied and a patient
   charged the undiscounted total is the most consequential defect found.
2. **Decouple the broadcaster or make broadcast failure non-fatal** (§6.1), and make the write
   atomic with respect to the 500.
3. **Expose the invoice id** on the booking and the order (§6.5), or add `GET /api/v1/invoice/{id}`.
4. **Decide who writes `user_roles`** (§6.4) and **who calls `NotificationService`** (§6.3) — two
   features are currently inert.
5. **Add a schedule, a pharmacy, stock and a promo to the seeders** (§6.6) so the walkthrough is
   runnable on a fresh install.
6. **Run `vendor/bin/pint`** and commit the result, or amend the CI gate to match reality (§5).
7. Write the Dart integration test (§7.4) and close todo 50's `CHAR(16)` → `TEXT` gap (§7.2).
