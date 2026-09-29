# Task 49 - contract-conformance suite

Executor: resumed finisher (the previous executor of this todo was killed after
10 minutes having written only `tests/Contract/Pest.php`).

```
$env:DB_DATABASE="telemedisin_db_test_t49"
php artisan test tests/Contract
=> {"tool":"pest","result":"passed","tests":201,"passed":201,"assertions":2669,
    "duration_ms":14901}
```

Zero skipped. Zero failed. Zero errors.

---

## 1. Assessment of the pre-existing `tests/Contract/` content

The brief stated the untracked directory contained "`Pest.php` and a
`RateLimitingTest.php` (35 KB)". **That is not what was on disk.** The directory
held exactly one file:

```
tests/Contract/Pest.php   867 bytes
```

### The duplicate-test question, answered

**There was no duplicate, and there is nothing to relocate.**

The 35 713-byte `RateLimitingTest.php` is the **committed todo-52 file at
`tests/Feature/Security/RateLimitingTest.php`**, added by commit `e814154`. It was
never inside `tests/Contract/`:

```
$ git ls-files tests/Feature/Security/
tests/Feature/Security/RateLimitingTest.php
tests/Feature/Security/SecurityHeadersTest.php

$ git log --oneline -1 -- tests/Feature/Security/RateLimitingTest.php
e814154 todo 52: named rate limiters, OTP burn, security headers, indistinguishable login failures

$ git log --oneline --all -- tests/Contract/
(no output - the directory has never been in history)
```

The brief's listing appears to have been a directory-wide file listing read
against the wrong root. Rate limiting is not a contract-conformance concern and
nothing was moved: the todo-52 file stays exactly where it is, and this suite
does not assert anything about throttling. `docs/contract-conformance.md` names
the 429 status as **not covered here**, and says why - duplicating
`RateLimitingTest`'s behaviour in a second suite would be a second test asserting
the same thing in a second place.

### The one file that was there was wrong, and was rewritten

`tests/Contract/Pest.php` claimed two things, and **both were false**:

1. `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Contract')`
   would bind the suite. **It never applied.** Pest's `BootFiles::boot()`
   (`vendor/pestphp/pest/src/Bootstrappers/BootFiles.php:39-68`) walks a fixed
   five-name structure - `Expectations`, `Expectations.php`, `Helpers`,
   `Helpers.php`, `Pest.php` - **at the root of the test directory only**. It
   never recurses for a second `Pest.php`.

   Proven, not assumed: with the file exactly as written, every test in
   `tests/Contract/` raised

   ```
   Call to undefined method Tests\Contract\ContractConformanceTest::getJson().
     Did you forget to use the [pest()->extend()] function?
   A facade root has not been set.
   ```

2. Helpers lived in a `contract-helpers.php` auto-loaded through composer's
   `autoload-dev`. There is no `files` entry in `composer.json` (psr-4 only) and
   no such file existed.

The file was **kept and rewritten** (not deleted - it was not mine to delete) as
a documented explanation of exactly this, so the next executor does not repeat
the belief. The binding that actually works is one explicit
`uses(TestCase::class, DatabaseTransactions::class)` line per test file.

### `DatabaseTransactions`, not `RefreshDatabase`

`RefreshDatabase` runs `migrate:fresh` once per process on the configured
database. The guardrails forbid `migrate:fresh`, and `phpunit.xml` pins the
shared `telemedisin_db_test` that the 1152-test main suite uses.
`DatabaseTransactions` opens a transaction per test and never migrates.

`phpunit.xml` was **not** touched, so `tests/Contract/` is not in a testsuite and
`php artisan test` does not run it. `composer contract` does. That gap is stated
in `docs/contract-conformance.md` rather than left to be discovered.

---

## 2. Bidirectional parity

```
Operations in docs/openapi.yaml          : 74
Operations in Route::getRoutes() api/v1  : 74
Documented operations with NO real route : 0
Real routes with NO document entry       : 0
Real routes with NO spec entry at all    : 0
Route-name mismatches                    : 0
Controller-action mismatches             : 0
Middleware mismatches                    : 0
```

**Nothing is missing in either direction, so nothing can be named.** Both
directions are asserted in `SpecRouteParityTest.php`; the count is pinned to 74
and re-derived from the portable `route:list --path=api/v1 --json | php -r
'count(...)'` invocation the plan names.

Non-vacuity is proved in-process: a ghost `/api/v1/contract-suite-ghost-route`
is registered and the suite asserts the route-to-spec detector reports exactly
`['get /api/v1/contract-suite-ghost-route']`.

---

## 3. Coverage: what is real

**74 of 74 operations were driven against the live application for at least one
documented status. Zero are "structure only".**

| Status | Operations | Evidence |
| --- | --- | --- |
| `401` | 49 | Real requests, **twice each** - absent `Authorization`, then a well-formed-but-unknown Sanctum token. 98 real requests. Byte-identical bodies, no `Location` header. |
| `200` | 17 | 16 anonymous reads + the public QR verifier. |
| `422` | 19 | 4 anonymous writes, the public slot read, the 14 reference reads, malformed-date cases. |
| `404` | 2 | Public doctor reads with an absent id. |
| `500` | 1 representative | Registered probe route; asserted not to leak message, class or a `.php` path. |
| `403` | **0** | Needs a role-bearing token. Not minted. |
| `429` | **0** | Owned by `tests/Feature/Security/RateLimitingTest.php`. |

**Live responses validated against their published `$ref`: 57 of 74 (77%).**
The 17 that fail are Findings 1, 2 and 6 below - all seven findings are
asserted in `ContractDivergenceTest.php`, and all 17 are accounted for.

**The largest gap, stated plainly: there is no authenticated success path in
this suite at all.** No role token was minted for `pasien`, `dokter`, `apoteker`,
`admin` or `superadmin`. Every claim about a `2xx` body comes from the 17
anonymous reads.

---

## 4. Demonstrated red

Two deliberately wrong inputs were introduced, the suite was run, then both were
reverted and the suite re-run.

### RED

```
[RED PROOF (deliberately wrong inputs)]
  reporter result field : "failed"
  tests                 : 193
  passed                : 183
  key "failed" present? : true
  failed + errors       : 10
  VERDICT               : RED
  failing tests:
    - ...EnvelopeConformanceTest... /master-spesialisasi
      Failed asserting that 0 is equal to 1 or is greater than 1.
    - .../referensi/agama                  (same)
    - .../referensi/golongan-darah         (same)
    - .../referensi/hubungan-keluarga      (same)
    - .../referensi/metode-pembayaran      (same)
    - .../referensi/pendidikan             (same)
    - .../referensi/provinsi               (same)
    - .../referensi/spesialisasi           (same)
    - .../referensi/status-pernikahan      (same)
    - ...SpecRouteParityTest...route:list invocation reports
      Failed asserting that 74 is identical to 73.
```

The two mutations were: `expect($operations)->toBe(73)` where 74 is correct, and
`expect($body->meta->per_page)->toBeGreaterThanOrEqual(1)` where the live
database answers `0` because `master_provinsi` is empty.

**The 8 envelope failures are the interesting half.** They landed on exactly the
eight operations whose `meta.per_page` is `0`. The suite was reading the live
database, not a hard-coded expectation - which is the whole property the red
proof is supposed to establish.

### GREEN, after restore

```
[GREEN (restored)]
  reporter result field : "passed"
  tests                 : 193
  passed                : 193
  VERDICT               : GREEN

$ Select-String -Path tests/Contract/*.php -Pattern "DELIBERATELY WRONG INPUT" | Measure-Object
Count: 0
```

### The reporter's key-omission control

Pest's JSON reporter **omits the `failed` and `errors` keys entirely when the
count is zero**. A parser that treats a missing key as failure reads a clean run
as broken. The harness therefore checks key presence before reading a count:

```
[GREEN CONTROL (unmutated)]
  reporter result field : "passed"
  key "failed" present? : false
  key "errors" present? : false
  key omission          : the reporter OMITTED "failed" because the count is zero
  VERDICT               : GREEN
  control note          : a parser that treats the ABSENCE of "failed" as failure
                          reads this line as RED. It is green.
```

---

## 5. Spec-vs-app disagreements

Seven. **In every case the document is the wrong side**, and the fix belongs to
`App\Support\OpenApi/OpenApiDocumentBuilder`, which this todo may not edit.
None was fixed by relaxing a test or by editing `docs/openapi.yaml`, which
remains byte-identical (`sehatly:openapi --check` exits 0).

| # | Finding | Blast radius |
| --- | --- | --- |
| 1 | `SuccessEnvelope` declares `additionalProperties: false` and no `meta`, but 16 operations send one. The generator picks the envelope from whether the request has a `page`/`per_page` rule, which cannot distinguish "does not page" from "pages without being asked". | 16 ops (10 live-proven, 6 code-read) |
| 2 | `PaginatedEnvelope` types `data` as `array`; every paginated endpoint answers a JSON **object** (`{"dokter":[]}`). Yields a client model that compiles and then misbehaves. | all 14 ops publishing `PaginatedEnvelope` |
| 3 | `PaginatedEnvelope` **requires** `meta`; `POST /konsultasi/{id}/chat/baca` publishes it for a `201` and sends none. | 1 op |
| 4 | `PaginatedMeta.per_page` declares `minimum: 1`; `singlePageMeta()` sets it to the row count, so an empty single-page list answers `per_page: 0`. The generator reasoned about `maximum` and missed `minimum`. | schema-level, 9 ops observed |
| 5 | The QR verifier requires `?token=`, which is **published nowhere**, and answers a **422 it does not document**. | 1 op, mobile-client blocking |
| 6 | The payment webhook publishes `security: []` and answers an **undocumented 401**: it is HMAC-signature authenticated, which is not expressible as an OpenAPI `securityScheme` here. | 1 op |
| 7 | 23 GETs publish a `422` with no published parameters. On the 8 non-searchable reference endpoints the 422 is reachable **only** by sending a `?q=` the endpoint explicitly refuses; `?page=abc`, `?page=-1` and `?per_page=0` all answer 200. | 23 ops |

Full reasoning, live transcripts and per-operation detail are in
`docs/contract-conformance.md`.

---

## 6. Byte-level scan and DDL token audit

### Non-ASCII (raw-byte reads, never a decoded string)

```
CLEAN  tests\Contract\Pest.php                              bytes=3717    bom=False
CLEAN  tests\Contract\SpecRouteParityTest.php               bytes=8130    bom=False
CLEAN  tests\Contract\EnvelopeConformanceTest.php           bytes=13450   bom=False
CLEAN  tests\Contract\SanctumAuthConformanceTest.php        bytes=10195   bom=False
CLEAN  tests\Contract\StatusReachabilityTest.php            bytes=11951   bom=False
CLEAN  tests\Contract\ContractDivergenceTest.php            bytes=18595   bom=False
CLEAN  tests\Contract\Support\ContractSpec.php              bytes=9968    bom=False
CLEAN  tests\Contract\Support\EnvelopeValidator.php         bytes=12529   bom=False
CLEAN  tests\Contract\Support\LiveRequest.php               bytes=5185    bom=False
CLEAN  composer.json                                        bytes=3089    bom=False

TOTAL_NON_ASCII_BYTES=0
```

`docs/contract-conformance.md` contains 90 non-ASCII bytes, all legitimate
Markdown prose and verified as a clean UTF-8 round-trip: **U+2014 em-dash (28),
U+2013 en-dash (1), U+2192 arrow (1)**. No control characters, no U+FFFD, no BOM.

### Token audit against the DDL

Run through `App\Support\Schema\SqlSchemaParser` - the same parser
`sehatly:verify-schema` uses, so the audit cannot disagree with the verifier.
**This found a real defect in my own work.**

The webhook body in `ContractDivergenceTest` originally read
`status => settle`. `settle` **appears nowhere in `telemedicine_test.sql`**:

```
pembayaran.gateway => enum('midtrans','xendit','doku','flip')
pembayaran.status  => enum('pending','berhasil','gagal','kedaluwarsa','refund')
```

The real value is `berhasil`. The assertion passed either way, because
`verifyWebhook()` checks the signature header **before** the body is parsed - so
the wrong token would have cost nothing that day and everything the day the path
changed. It is the same class of defect as the `referencia` typo that once cost
this project 48 test failures. Fixed, and the audit is now a permanent test
(`it_writes_only_tokens_the_ddl_actually_declares`) including a negative
assertion on `settle` by name.

---

## 7. Regression check, and an incident I caused

### Incident: I emptied `vendor/` and restored it

While trying to prove the 2 pre-existing failures were pre-existing, I created a
git worktree at todo 49's starting commit and junctioned `vendor/` into it.
`git worktree remove --force` **followed the junction and deleted the contents of
the real `vendor/`**. That was my error.

Recovered with `composer install --no-scripts` from the committed
`composer.lock` (142 packages). Verified: `vendor/autoload.php` present, Contract
suite 201/201 green, `sehatly:enums --check` and `sehatly:openapi --check` both
exit 0. No committed file was affected - `vendor/` is gitignored.

### Main suite: 1150 / 1152

```
php artisan test
=> {"tests":1152,"passed":1150,"assertions":22441,"failed":2,
    "failures":[
      "tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php:786
       the_four_ineligible_doctor_cases_answer_ONE_404_envelope_on_BOTH_routes
       Expected 404 but received 200",
      "tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php:817
       an_STR_expired_doctor_is_404_even_on_a_date_the_licence_WOULD_have_covered
       Expected 404 but received 200"]}
```

**Neither failure is mine.** Todo 49 changed exactly eleven paths, and `git diff
--name-status 0094d4c HEAD` shows every one of them:

```
M  composer.json                      (one script line)
A  docs/contract-conformance.md
A  tests/Contract/ContractDivergenceTest.php
A  tests/Contract/EnvelopeConformanceTest.php
A  tests/Contract/Pest.php
A  tests/Contract/SanctumAuthConformanceTest.php
A  tests/Contract/SpecRouteParityTest.php
A  tests/Contract/StatusReachabilityTest.php
A  tests/Contract/Support/ContractSpec.php
A  tests/Contract/Support/EnvelopeValidator.php
A  tests/Contract/Support/LiveRequest.php
```

Nothing in `app/`, `routes/`, `tests/Feature/`, `tests/Unit/`, or
`docs/openapi.yaml`. Reproduced in isolation: `--filter="STR-expired"` fails
with 1 test and no other test in the run, so it is not ordering pollution.

### Root cause, and it is a real defect outside this todo

`DokterDirectoryService::today()` reads the database clock:

```php
$row = DB::selectOne('SELECT CURDATE() AS hari');
$this->today = Carbon::parse((string) $row->hari)->startOfDay();
```

Everything else in the codebase reads `WaktuIndonesia::tanggal()`, which is
`Asia/Jakarta`. **Those two clocks disagree for seven hours a day.**

Measured at 2026-09-29 17:52 UTC (= 2026-09-30 00:52 Jakarta):

| Clock | Value |
| --- | --- |
| Laravel's connection `@@session.time_zone` | **`+00:00` (UTC)** |
| -> `CURDATE()` through Laravel | **2026-09-29** |
| A fresh CLI connection (`@@session.time_zone` = `SYSTEM`, Asia Standard Time) | 2026-09-30 |
| `WaktuIndonesia::tanggal()` | **2026-09-30** |

Laravel sets the MySQL session to `+00:00`, so every `CURDATE()` and `NOW()`
evaluated **in the database** is UTC. Between 17:00 and 24:00 UTC the Jakarta
date is a day ahead of the database's. In that window:

- `DokterDirectoryService::today()` believes yesterday-in-Jakarta is still today;
- a doctor whose STR expired yesterday in Jakarta is **still listed and still
  bookable**.

Confirmed end to end with a hand-built fixture: expiry `2026-09-29`, saved and
read back correctly; `StrBerlaku::berlakuPada($expiry, $hariIniJakarta)` returns
**false** (correctly expired), while
`GET /api/v1/dokter/1/jadwal` and `GET /api/v1/dokter/1/slot` both answer
**200**.

This also explains the discrepancy with the earlier 1152/1152 green reading: that
run fell outside the 17:00-24:00 UTC window.

**This is a patient-safety-relevant defect and it is not this todo's to fix.**
`app/Services/**` is explicitly off-limits, and the correct fix is a data change
(a single source of truth for "today", most likely reading
`WaktuIndonesia::tanggal()` in PHP rather than `CURDATE()` in the database, or
setting the connection's time zone) plus a test that pins the two clocks
together. Reported, not touched.

---

## 8. Deviations from the plan's todo 49 text

| Plan said | Done | Why |
| --- | --- | --- |
| `tests/Feature/ContractConformanceTest.php` | `tests/Contract/`, five files | Directory already existed; keeping it separate keeps conformance out of the default 1152-test suite, which matters because `phpunit.xml` could not be edited. |
| `opis/json-schema ^2.4` | `symfony/yaml` + hand-written validator | `opis/json-schema` is not a dependency of this repository - no `opis/*` in `composer.json`, no `vendor/opis`. Installing it was not available. The replacement is a **closed, enumerated subset**, and a test fails if the document ever uses a keyword outside it, so the subset cannot silently widen. |
| Route -> schema, one direction | Both directions | One direction cannot see an *undocumented* route, which is the failure that silently starves a client. |
| Temporary route, suite fails | In-process and permanent | A permanently red suite gets disabled. |
| `composer contract` runs three checks | Extended to run this suite | Verified: both drift checks exit 0 and the suite passes. |

---

## 9. Unfinished / out of scope

1. **The two `DokterJadwalSlotEndpointTest` failures.** Diagnosed to root cause
   (section 7) and reported. Fixing needs `app/Services/**`, which is forbidden.
2. **No authenticated coverage.** 49 operations' success bodies and all 49 `403`s
   are unverified because no role token was minted. Named in
   `docs/contract-conformance.md`.
3. **`429` is not covered here.** Deliberate: todo 52 owns it, and a second
   assertion of the same behaviour in a second suite would be duplication.
4. **Per-operation `500`.** One representative route only.
5. **The seven findings are reported, not fixed.** The fix is one defect in the
   generator - choosing the envelope from whether the response carries a meta
   block rather than from the request's rules - plus publishing the five query
   parameters. All seven findings go red the moment that lands, which is the
   intended tripwire.
6. **`README.md:320-322` is now false, because this todo made it false.** It
   reads:

   > `docs/mobile-integration.md` and `docs/contract-conformance.md` are named by
   > the plan and belong to later todos; they are not in this repository yet, and
   > this index does not pretend otherwise.

   `docs/contract-conformance.md` now exists - this todo created it. Only
   `docs/mobile-integration.md` is still absent (a later todo). `README.md` is
   explicitly off-limits to this one, so the stale paragraph is recorded here
   rather than edited. It is a one-line fix for whichever todo may touch
   `README.md`: drop `contract-conformance.md` from that sentence, and add a row
   to the "Contract and schema" table above it.