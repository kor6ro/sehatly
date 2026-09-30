# Task 54 — Final integration run: full suites, schema parity, contract conformance, evidence bundle

**Executor:** Sisyphus (todo 54, the last implementation todo).
**HEAD at start and at commit:** `32d40fc` ("plan: close todo 48").
**Product:** `docs/verification-report.md`.
**Verdict: the plan's todo-54 bar is NOT met.** 8 of the ten required commands exit 0.
`vendor/bin/pint --test` exits 1 on 87 files. `dart format --set-exit-if-changed` exits 1 on 3
files. The plan's Dart integration test does not exist. `docs/contract-conformance.md` reports 17
failures, not zero. No screenshot or network log was captured for any of the twelve steps.

---

## 0. The measurement hazard, and how it was defeated

**`vendor/bin/pest` and `vendor/bin/pint` have their stdout replaced in this environment by a
synthetic JSON envelope that is not the tool's output.** Proven with a probe that cannot pass by
accident — a filter matching no test cannot run 1153 tests:

```
> cmd /c "vendor\bin\pest.bat --colors=never --filter=zzz_no_such_test_zzz > probe.txt 2>&1"
EXIT=1
BYTES=113
{"tool":"pest","result":"failed","tests":0,"passed":0,"assertions":0,"duration_ms":6,"raw":["No tests found."]}
```

On the full run the envelope appeared with **no `raw` field at all**:

```
{"tool":"pest","result":"passed","tests":1153,"passed":1153,"assertions":22510,"duration_ms":545544}
```

**That line was discarded, not reported**, on two independent grounds:

1. It carries no `failed`/`errors` key, so per the reporter control it proves nothing — the
   control the brief demanded, and the same trap `docs/contract-conformance.md` documents.
2. Its `duration_ms` (545 544 ms = 545.5 s) **contradicts the run's own JUnit `time` of
   596.298644 s**, written by the test process itself. The envelope is not a measurement.

**Method adopted:** every suite number is read from a file the tool wrote itself, which the stdout
interceptor cannot touch.

| Tool | Untamperable output |
| --- | --- |
| Pest | `--log-junit=<file>` |
| Pint | `--output-to-file=<file> --output-format=junit` |

`verify-schema`, `openapi --check`, `enums --check`, `check-doc-links.mjs`, `dart`, `npm` and
`composer` all returned genuine output unwrapped and are quoted directly.

## 0b. Environment

- PHP: `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` — `PHP 8.4.17 (cli)`,
  `Laravel Framework 13.33.0`. Bare `php` is not on PATH; every command was prefixed.
- `CarbonImmutable` is a **sibling** of `Carbon`, not a subclass — verified by reflection, not
  assumed: `parent=DateTimeImmutable`, `isSubclassOfCarbon=NO`,
  `CarbonInterface impl by CarbonImmutable=YES`. `CarbonInterface` was used throughout.
- Dart: `Dart SDK version: 3.13.2 (stable) ... on "windows_x64"` at the WinGet path; not on PATH.
- Node `v24.13.1`, npm `11.17.0`. Composer at `C:\ProgramData\ComposerSetup\bin\composer.bat`.
- Databases: `telemedisin_db_test_t54` (suite) and `telemedisin_db_t54` (walkthrough), both created
  by this executor. `phpunit.xml` untouched. `migrate:fresh` / `migrate:rollback` never run. The
  `sehatly` database never touched. `telemedisin_db_test` never used.

## 1. Test suite — GREEN, zero skipped

Per-run `$env:DB_DATABASE` override, seeded first because the Unit suite skips `RefreshDatabase`
and an unmigrated database surfaces as eight unrelated failures.

**Reporter control, asserted before the count is read:** the JUnit root carries an explicit
`skipped` attribute and the value is read from it, not inferred from an absent key —

```
root has 'skipped' attribute: True -> value '0'
```

```xml
<testsuite name="C:\Users\axioo\Desktop\sehatly\phpunit.xml" tests="1153" assertions="22510"
          errors="0" failures="0" skipped="0" time="596.298644">
  <testsuite name="Unit"    tests="146"  assertions="7532"  errors="0" failures="0" skipped="0" time="98.086256"/>
  <testsuite name="Feature" tests="1007" assertions="14978" errors="0 failures="0" skipped="0" time="498.212388"/>
```

- **tests 1153 · assertions 22510 · failures 0 · errors 0 · skipped 0 · 596.298644 s**
- Arithmetic checked: 146 + 1007 = 1153; 7532 + 14978 = 22510.
- Corroborated three ways: `skipped="0"`; **0** `<skipped>` child elements across all 1153
  `<testcase>` nodes; **0** `<failure>` and **0** `<error>` child elements.
- JUnit file 438 023 bytes, SHA-256 `394D5766681597331C266D54D9DAC0CDDAEE4CFD668B8254B540A9C002B1442A`.

**`markTestSkipped` census** over all 83 PHP files under `tests/`: exactly 2 hits, both inside the
docblock of `tests/TestCase.php` quoting the helper todo 30 deleted. No live `markTestSkipped`, no
live `->skip()`. **The zero-skipped bar is MET.**

## 2. Routes — 74 = 74

- `php artisan route:list --path=api/v1 --json` → `API_V1_JSON_ENTRIES=74`
- `php artisan sehatly:openapi --check` → exit 0, `routes read 74 under api/v1`,
  `65 paths, 74 operations`

**Parity holds: 74 live routes, 74 published operations.** The four generated artefacts' SHA-256
were independently recomputed with `Get-FileHash` and match the generator's own report.

## 3. Schema — 75 tables, 2 views, 0 drift, 7 informational

`php artisan sehatly:verify-schema` → **exit 0**:

```
 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3   (reference)
 counts tables=82 views=2 columns=715 indexes=237 foreign_keys=105 checks=3   (live)
 Discrepancies: 7 (0 drift, 7 informational)
 PASS — 75 tables, 2 views verified. Nothing was written.
```

**Stated plainly:** 7 discrepancies, **0 drift**, **7 informational**. The 7 are the
framework-registered tables — `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`,
`migrations`, `personal_access_tokens` — each named in the output. Not a contract discrepancy and
not drift. Live 82 = 75 contract + 7 framework.

**Independent 75-table parity, not trusting the verifier:**

```
SQL  CREATE TABLE statements : 75        (raw-byte regex over telemedicine_test.sql)
SQL  CREATE VIEW  statements : 2
LIVE base tables = 82                    (information_schema, direct query)
LIVE views       = 2
LIVE_TOTAL 82 - FRAMEWORK_TABLES 7 = DOMAIN_TABLES 75   -> PARITY MATCH
FRAMEWORK_PRESENT_IN_LIVE = 7 of 7
```

**A discrepancy between my own two measurements, reported not smoothed:** my raw regex counts
**106** `FOREIGN KEY` occurrences; the verifier's parser reports **105** `foreign_keys`. Probably
one FK inside a wrapped multi-line declaration the verifier reads as one unit. **Flagged, not
resolved.** Table count unaffected.

**Contract DDL byte-unchanged:** SHA-256
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, 59 604 bytes, prefix
`AEFE2247E00F` as expected. `git status` reports it unmodified. **No critical finding here.**

## 4. Contract, docs, no-Flutter

| Command | Exit | Result |
| --- | --- | --- |
| `sehatly:openapi --check` | 0 | `UP TO DATE — every generated file is byte-identical to a fresh export` |
| `sehatly:enums --check` | 0 | `UP TO DATE` / `69 ENUM columns, 319 values, 0 divergences` / `sha256 ed87600a…` |
| `node tools/check-doc-links.mjs` | 0 | `OK — 1 document(s); 29 links, 19 repo paths, 53 api-path mentions, 19 dart blocks` |
| `… --expect-sections 14 docs/mobile-integration.md` | 0 | same OK line (the exact `docs:check` invocation) |
| `composer validate --no-check-publish` | 0 | `./composer.json is valid` |

- `Test-Path mobile/` → **False**.
- Exactly one `pubspec.yaml` outside vendor/node_modules/.git:
  `packages/sehatly_api_client/pubspec.yaml` (790 bytes, SHA-256 `2648D132…`). Every `flutter`
  occurrence is prose. `environment: sdk: '>=3.13.0 <4.0.0'`, **no `flutter:` key**.
- Repo-wide search for a `flutter:` SDK constraint declaration → **NONE**.

## 5. Dart, web, and the two failures

**Dart** (`packages/sehatly_api_client`):

| Command | Exit | Result |
| --- | --- | --- |
| `dart analyze` | 0 | `No issues found!` |
| `dart analyze --fatal-infos` | 0 | `No issues found!` |
| `dart test` | 0 | `+132: All tests passed!` |
| `dart format --output=none --set-exit-if-changed .` | **1** | `Changed enums.dart / paths_table.dart / request_bodies.dart` / `Formatted 40 files (3 changed)` |

Magnitude, measured by formatting copies in temp and diffing, repo untouched:

```
enums.dart          4534 -> 4682 lines, 4541 differing
paths_table.dart     676 ->  549 lines,  620 differing
request_bodies.dart 3379 -> 3329 lines, 3275 differing
```

All three are **tracked and clean in git**. **This is a disclosed gap, not a discovery** — the
README's "Known gaps" already states `dart format` is not clean on `lib/src/generated/` and gives
the reason (the generator emits one element per line for byte determinism; `dart analyze` is the
enforced gate). Reported as an open, accepted trade-off with a live exit code of 1.

**Web** (`web/`): `types:check` → 0; `test:unit` → 0, `tests 37 / pass 37 / fail 0 / skipped 0`;
`build` → 0, `3363 modules transformed`, single chunk `1,097.26 kB` (325.22 kB gzip) against
Vite's 500 kB advisory. All three runnable scripts run; `test:e2e` not run (no browsers).

**Pint — FAILS.** `vendor/bin/pint --test` → **exit 1**. Read from the JUnit file Pint wrote:

```xml
<testsuite name="PHP CS Fixer" tests="87" assertions="307" failures="307" errors="0">
```

**87 files, 307 violations** — `app` 45, `config` 1, `tests` 41. This contradicts the plan's
"all ten commands exit 0", the plan's "run `vendor/bin/pint` before every commit", and
README's CI section listing `pint --test` as a gate. No test asserts pint cleanliness, so a green
suite does not cover it. **Not fixed here** — this todo reports, it does not change the product.

## 6. Six defects the live runs found

Full detail, with verbatim responses, is in `docs/verification-report.md` §6.

1. **NEW — the API is coupled to the broadcaster.** With Reverb down, `POST /konsultasi/mulai`
   returned **HTTP 500**; `laravel.log` recorded `Pusher error: cURL error 7 … port 8080`.
   Decisive test: started Reverb, re-issued the **identical** request, 500 gone. Falsifies the
   plan's own QA scenario (c) ("proving the API is not coupled to the broadcaster") and the
   README's "the REST chat path still works without it, which is the point". Worse: the failed
   request **had already committed its writes** — the retry returned 422 "Booking ini sudah
   memiliki sesi konsultasi." And the 500 left **no** log entry of its own.
2. **NEW — `promo/validasi` computes a discount it never applies.** It answered
   `valid:true, nilai_diskon:"34650.00", total:"326850.00"`; `invoice.diskon` stayed `0.00`,
   `total` stayed `361500.00`, `promo_redemption` had 0 rows, and the payment charged
   **361 500.00**. Money-affecting, reachable by any client trusting the success payload.
3. **NEW — `notifikasi` has no writer.** `NotificationService` is `final` with five producers
   (`bookingDibuat`, `bookingDibatalkan`, `pembayaranSelesai`, `resepSiap`, `pesanBaru`) and a
   repo-wide search finds **no caller anywhere in `app/`**. 0 rows after the entire walkthrough.
   The plan's "read the notification" step cannot return anything the application wrote.
4. **NEW — no shipped seeder writes `user_roles`.** All twelve seeder files: 0 insert matches.
   After a full seed, `user_roles` held 1 row — the one the API created at registration. Every
   `DevFixtureSeeder` doctor therefore 403s on every `permission:` route even after a valid
   login. This is the mechanism behind the conformance suite's 0 403-coverage.
5. **NEW — the booking's and the order's invoice id are never exposed.** `BookingResource` does
   not mention `invoice`; `POST /booking` and `GET /pasien/booking` return none; the checkout
   returns the order and no invoice. Yet `invoice/{id}/bayar` needs the id and `promo/validasi`
   makes `invoice_id` mandatory. A client cannot pay or discount without reading the database.
6. **NEW — a freshly seeded database cannot support the plan's own walkthrough.**
   `dokter_jadwal=0`, `apotek_stok=0`, `master_promo=0`, no `faskes` of `tipe='apotek'`, and **no
   user with `tipe='apoteker'` at all**. There is no POST route for any of them. Booking,
   fulfilment, promo and pharmacist verification are unreachable on a fresh install, not merely
   unseeded.

**Corroborated, not new:** empty single-page reads returned `"per_page":0`, which is
`docs/contract-conformance.md` Finding 4, hit live and unprompted. The same first response also
showed Finding 2 (`data` an object keyed by resource name) and Finding 1 (`meta` present) in one
line of JSON.

## 7. The four open items, restated as open

1. **FINDING-48-race — open.** `PaymentService::huntap()` dedupes on `(gateway, nomor_referensi)`
   via `SELECT … FOR UPDATE` with **no unique index**; a lock matching no row locks nothing, so
   concurrent first deliveries are not excluded. I measured **only the serial half**:
   `duplicate:false` then `duplicate:true` on two sequential deliveries. **No evidence about the
   race was produced.** The finding stands as stated. The `nomor_referensi` spelling was
   reproduced with no `referencia` typo.
2. **Todo 50 gap — open.** The DDL still says `nik CHAR(16)` where the plan requires ciphertext in
   **TEXT**. **No migration was written.** The acceptance criterion is literally unmet. Not fixed
   here; no migration added.
3. **Conformance gap — open.** `docs/contract-conformance.md` reports 57/74 validating, **17 not**,
   and **403 coverage 0**. I did **not** re-run that suite (it is outside `phpunit.xml` and so
   outside my 1153), so its 201 tests / 2669 assertions are **not measured by me**. I did add the
   mechanism behind the 0 (finding 4 above).
4. **The plan's Dart integration test does not exist.** `test/integration/` is absent; the only
   subdirectory of `test/` is `support/`. The criterion "the Dart integration test completes the
   full flow with no hand-written requests" is **unmet and unmeasured** — I have no evidence
   either way about whether the package's public API is self-sufficient, because the test that
   would answer it was never written.

## 8. The twelve-step walkthrough

**Stack:** `php artisan serve --host=127.0.0.1 --port=8011` — **8011, not 8000** — against the
private `telemedisin_db_t54`. `php artisan reverb:start` on 8080 for the second half
(`INFO Starting server on 0.0.0.0:8080`). Both stopped afterwards. `git status` clean apart from
the untracked `.playwright-mcp/`, which is not mine. Driver: raw cURL from a throwaway script in
the temp directory. Every quoted line is real server output.

**Fixture preparation, disclosed.** `register` creates a patient only (no role field) and the
seeded doctor/pharmacist passwords are `password_hash(bin2hex(random_bytes(32)))`, i.e.
unguessable. Combined with defect 6, steps 4–12 were unreachable without preparing rows. In
`telemedisin_db_t54` **only**: 7 `dokter_jadwal` rows, 1 `apotek` faskes, 7 `apotek_stok`, 1
`master_promo` (T54HEMAT), 1 apoteker user + `user_roles`, 1 known password, 1 `user_roles` row
for the doctor. The prep script refuses to write unless the active database is
`telemedisin_db_t54`. **This is fixture prep, not product flow, and is disclosed so no reader
mistakes this for a clean-room run.**

| # | Step | Observed |
| --- | --- | --- |
| 1 | register | **201**; `otp.kode="067383"`, `tujuan=verifikasi_telepon`, `ttl_detik=300`; **no token in the response** (asserted) |
| 2 | verify OTP | **200**; `access_token` len 50 + `refresh_token` len 80, `expires_in=86399`; `pending_verifikasi`→`aktif`, `telepon_terverifikasi`→true; `GET /me` 200. **The two-step design is real.** |
| 3 | browse doctors | `GET /dokter` (anon) **200**, 2 doctors; jadwal all seven day-lists **empty**; slot `[]`. After the schedule fixture: **24 slots**, first `jam_mulai 09:00:00` |
| 4 | book | first **422** `slot_mulai required` (no slots); after fixture **201**, `BK20260930AGQJKA`, `menunggu_pembayaran` |
| 5 | pay | `POST /invoice/1/bayar` **201**, `MOCK-20260930-2DA45E9666C17489CF3A870E`, `va_number=88480566772404930301`; signed webhook **200** `duplicate:false` → invoice `lunas`, `referensi.advanced:true`; repeat **200** `duplicate:true`. **Invoice id came from the database** (defect 5) |
| 6 | start consultation | **not exercisable as described.** `mulai` is a *patient* action — the doctor token 403s from `ownPasien()` (my error, corrected). Patient token → **500** with Reverb down (defect 1); with Reverb up the earlier row existed → **422** "sudah memiliki sesi konsultasi". Row did get created (`id=1`, `menunggu_dokter`); `PUT /terima` **200** → `berlangsung` |
| 7 | chat, two contexts | patient **201** (`pengirim_tipe:"pasien"`), doctor **201** (`"dokter"`), read-back **200** with **4 messages** incl. 2 system lines. **Two authenticated HTTP contexts, not two browser contexts** |
| 8 | SOAP | `PUT /selesai` **200**, `status=selesai`, `total_durasi_detik=2`, all four notes echoed |
| 9 | save + finalise record | `POST /rekam-medis` **201** (`uuid=d5737f6c…`, `URTI`); `PUT /final` **200**; patient `GET` **200** |
| 10 | prescription + interaction | `POST /resep` **201** `RX202609305COVT0`, `berlaku_sampai=2026-10-07` (+7 days); `cek-interaksi` **200** with `{"sumber":"antar_item","kunci":"antar_item:2:5","tingkat":"berat","obat_a":Amoxicillin,"obat_b":Metformin}`. A second run also produced a `riwayat_resep` `berat` warning with `ganda:true` |
| 11 | pharmacist verifies | `POST /verifikasi {"status":"ada_koreksi"}` with the apoteker token **201**, `status=diverifikasi` |
| 12 | checkout, promo, pay, notification | checkout **201** `PO20260930QUH4PN`, `apotek_id=3` auto-resolved, address derived, `total=361500.00`; `promo/validasi` **200** `valid:true` `nilai_diskon=34650.00` — **never persisted** (defect 2); `bayar` **201** charged the **undiscounted** 361 500.00; `GET /pesanan-obat/1` **200** with `tracking`; `GET /notifikasi` **200** `notifikasi:[]` (defect 3) |

**Not exercised, stated plainly:** no browser was driven at any point — Playwright binaries were
never downloaded (`npx playwright install` not run), so `test:e2e` was not executed, **0
screenshots and 0 network logs** were captured, and the UI halves of steps 7 and 12 are untested.
`npm run dev` (Vite) was never started. Steps 6 and 12 completed only with the disclosed fixture
preparation and a database-read invoice id.

## 9. Requirement ledger

| Requirement | Measured | Met |
| --- | --- | --- |
| all ten commands exit 0 | 8 of 10 — `pint --test`=1, `dart format`=1 | **NO** |
| report lists commands, exit codes, test count | §1–§5, 1153 tests | yes |
| `markTestSkipped` grep justified | 2 hits, both in a docblock | yes |
| 75 tables + 2 views | `tables=75 views=2`, exit 0 | yes |
| zero drift | `0 drift, 7 informational` | yes |
| conformance shows zero failures | it reports **17** | **NO** |
| zero skipped tests | `skipped="0"` | yes |
| Dart integration test | **does not exist** | **NO** |
| endpoint cross-check, zero missing/extra | generator 74=74; doc-link checker exit 0. Five module summaries **not** hand-diffed | **not measured** |
| screenshot + network log per step | **0 and 0** | **NO** |
| `telemedicine_test.sql` unchanged | `AEFE2247E00F…`, 59 604 bytes | yes |
| no `mobile/`, no `flutter:` constraint | absent; none | yes |

## 10. Ledger entries appended

Two lines appended to `.omo/start-work/ledger.jsonl` (append-only, built with
`node -e "JSON.stringify(...)"`, validated as parsing before commit, never rewritten, no git
output ever redirected into it):

- `task-completed` for todo 54, carrying the measured numbers and the not-met verdict.
- `open-finding-for-F1` `WALK-54-BROADCASTER` — the broadcaster coupling, a **new** finding the
  F-wave has not seen.
- `open-finding-for-F1` `WALK-54-PROMO` — the promo discount that is computed but never applied.
- `open-finding-for-F1` `WALK-54-SEED` — the seeded database that cannot support the walkthrough,
  plus the related `user_roles` and invoice-id findings.

**Append-only was honoured and verified in the appending script itself:** the file's prior content
was read before the write, the post-write content was asserted to still start with that exact
prefix (`append-only prefix intact: YES`), and every line in the file was then re-parsed
(`valid entries before: 91`, `lines appended: 4`, `valid entries after: 95`, `malformed: 0`).
No git output was ever redirected into the file.

**One known typo, deliberately left in place.** The `WALK-54-BROADCASTER` summary contains the
garbled word `re-issoking` where it should read `re-issuing`. The append-only rule forbids
rewriting the ledger, so it was **not** corrected — a surgical edit would be exactly the rewrite
that rule exists to prevent. The intended meaning is unambiguous from context: the identical
request was re-issued after Reverb was started and the 500 disappeared.


## 11. Unfinished, stated plainly

- Six defects reported, **none fixed** (this todo produces a report, it does not change the
  product). No index, constraint or migration added.
- `pint --test` (87 files) and `dart format` (3 files) left failing, as found.
- The Dart integration test not written; no role-bearing token mintable through any supported path.
- Zero screenshots and zero network logs; steps 6, 7 and 12 completed only partially, with
  disclosed fixture preparation.
- Five module summaries not hand-diffed against `route:list` — **not measured**.
- `docs/contract-conformance.md` not re-executed; its 201/2669 are quoted as an open claim only.
- Databases `telemedisin_db_test_t54` and `telemedisin_db_t54` were created and left in place;
  the owner may drop both.
