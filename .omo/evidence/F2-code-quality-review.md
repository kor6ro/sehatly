# F2 — Gate code-quality review

**Role:** F2 gate reviewer. I did not build this. I have no authority to change
product code; every item below is a finding for an owner, not a patch.

**Verdict: REQUEST CHANGES** — 1 BLOCKER, 5 MAJOR, 10 MINOR.

---

## 1. The diff surface I actually reviewed

```
merge base  git merge-base HEAD main  ->  2d3b3b458977c7f071e1882fd53d63f96486d97e
HEAD        23e9899  "plan: close todo 54"
git diff --stat 2d3b3b4..HEAD  ->  910 files changed, 260803 insertions(+), 14597 deletions(-)
```

910 files is not reviewable as a single pass, so I worked it by area.

**Read in full by me:** `bootstrap/app.php` · `routes/api.php` (all 1278 lines) ·
`app/Providers/AppServiceProvider.php:270-570` · `app/Services/Payment/PaymentService.php` (all 584) ·
`app/Support/NikCipher.php` (all 583) · `app/Support/WaktuIndonesia.php` (all 197) ·
`app/Http/Controllers/Api/V1/AuthController.php` (all 648) · `app/Services/Auth/TokenService.php` (all 299) ·
`phpunit.xml` · `.github/workflows/contract.yml` · `docs/contract-conformance.md:1-243`.

**Greps I ran myself over the whole tree:** every `catch` in `app/Http` + `app/Services` (0 empty) ·
every `DATE`/`DATETIME`/`TIME` column write and comparison site · every `?Carbon` / `Carbon $` type hint ·
`as any` / `@ts-ignore` / `@ts-expect-error` in `web/src` (0) · `markTestSkipped|markTestIncomplete|->skip\(|todo\(` in `tests/` ·
`nik_cipher|nik_index` in `database/migrations/` (0) · `Log::` in `app/` · `new Notifikasi` in `app/`.

**Delegated, then cross-checked against my own reads:** `tests/**` (quality, 0-skipped, login
bytes, webhook race, NIK column type) · all 81 migrations vs `telemedicine_test.sql` · dead code
and duplication across `app/`+`routes/`+`web/src/`+`packages/` · the pure-Dart client
(`dart analyze`).

**Measured independently (see §2):** the full suite on a private database.

### What I could not cover

- The 91 added `.tsx` and 47 added `.ts` files under `web/` were **not** read line by line. I swept
  `web/src` for duplication, dead exports, the drifted SOAP constant and `as any`, and checked the
  e2e specs only for debug leftovers.
- The 65 deleted `.tsx` files and the deleted `resources/js/` are not reviewed (deletions).
- I did not run `dart test` (132 tests), `npm run test:unit`, or `npm run types:check`. I ran
  `dart analyze` only.
- I did not run `sehatly:verify-schema` or `sehatly:openapi --check`; I read the migrations and
  the committed artefacts and diffed them statically instead.
- The 403/429 coverage gaps in `docs/contract-conformance.md` are the document's own claims; I did
  not re-measure them.
- I did not attempt to re-derive per-test names from the 3.1 MB JUnit XML; I report the root and
  per-file-suite counts, which the reporter's own nesting makes partly redundant.

---

## 2. Control first on the reporter

The Pest/PHPUnit JSON reporter omits `failed`/`errors` when the count is zero, so I did not trust
a summary line. I created a private database, migrated and seeded it, ran the suite, and parsed the
JUnit XML structurally.

```powershell
php C:\...\mkdb.php                                    # CREATE DATABASE telemedisin_db_test_f2rev
$env:DB_DATABASE = 'telemedisin_db_test_f2rev'        # PHPUnit <env> does not override an existing var
php artisan migrate --force --seed
php artisan test --log-junit ...\f2-junit.xml          # 738 s
```

`phpunit.xml` was not touched. `migrate:fresh` / `migrate:rollback` were never run. `telemedisin_db_test`,
`telemedisin_db` and `sehatly` were not touched.

```
<testsuite name="...\phpunit.xml" tests="1153" assertions="21950"
           errors="62" failures="0" skipped="0" time="738.06">
  <testsuite name="Unit"    tests="146"  assertions="7532"  errors="0"  failures="0" skipped="0">
  <testsuite name="Feature" tests="1007" assertions="14418" errors="62" failures="0" skipped="0">
```

| | claimed | measured by me |
|---|---|---|
| tests | 1153 | **1153** — matches |
| failed | 0 | **0 failures, 62 errors** |
| skipped | 0 | **0** — matches, confirmed independently |
| assertions | 22510 | **21950** (−560, consistent with 62 tests aborting before their assertions) |

**`skipped="0"` is TRUE and I verified it three ways:** the JUnit attribute on the root, `Unit` and
`Feature`; `rg 'markTestSkipped|markTestIncomplete|->skip\(|todo\('` across `tests/` returning 3 hits
that are all inside one docblock in `tests/TestCase.php:19,31,32` describing a helper that was
removed (`abstract class TestCase extends BaseTestCase {}`); and no `.env.testing` and no skip
attributes in `phpunit.xml`.

**The 62 errors are one root cause and it is real — BLOCKER 1 below.**

---

## 3. BLOCKER

### B1 — The verification number is not reproducible from a clean database, because `RbacSeeder` is not idempotent

**`database/seeders/RbacSeeder.php:143-152` (and the `roles` insert reached at `:176`)**

```php
public function run(): void
{
    $this->seedRoles();          // DB::table('roles')->insert($rows)            :167
    $this->seedPermissions();
    $roleIds = DB::table('roles')->pluck('id', 'nama');
    $permissionIds = DB::table('permissions')->pluck('id', 'kode');
    $rows = $this->rolePermissionRows($roleIds, $permissionIds);
    DB::table('role_permissions')->insert($rows);   // :151
}
```

Three plain `insert()` calls. No `insertOrIgnore`, no `upsert`, no delete-first. The class docblock
is candid that they are "plain and unreset" and depends on `DatabaseSeeder`'s `TRUNCATE` reset — and
`database/seeders/DatabaseSeeder.php:56-60` says outright that a seeder run on its own against a
populated table "now fails with MySQL **1062**".

Eleven Feature test files call `$this->seed(RbacSeeder::class)` — `KonsultasiTest.php:160`,
`WebSurfaceTest.php:250`, `AuthFlowTest.php:314`, `SuratKeteranganTest.php:398`, `BookingTest.php:376`,
`ResepTodo39ContractTest.php:31`, `ResepTodo40Test.php:71`, `RbacMiddlewareTest.php:128` and three
more. Those calls are safe **only** because `RefreshDatabase` wraps each test in a transaction that
rolls the inserts back.

Run the workflow the README prescribes on a database that is already seeded and the guarantee is
gone. Measured, on a private database created and seeded minutes earlier:

```
Illuminate\Database\UniqueConstraintViolationException: SQLSTATE[23000]: Integrity constraint
violation: 1062 Duplicate entry 'pasien' for key 'roles.roles_nama_unique'
  ... Database: telemedisin_db_test_f2rev
  at database/seeders/RbacSeeder.php:176
  at database/seeders/RbacSeeder.php:146
```

13 Feature classes error: `SuratKeteranganTest` 59, `PasienProfileTest` 73 errors + 17 failures,
`ReferensiEndpointTest` 49, `KonsultasiTest` 27 + 4, `ObatInteraksiServiceTest` 27,
`PaymentWebhookTest` 22, `PdpNotificationTest` 15 + 3, `RbacMiddlewareTest` 14, `AuditLoggingTest` 13,
`RateLimitingTest` 12, `SecurityHeadersTest` 10, `KonsultasiSchemaTest` 7, `PaymentConcurrencyTest` 3.
The per-file counts are cumulative across the reporter's nested suites; the authoritative figure is
the root `errors="62"`.

**Why it matters.** The F1–F4 gates rest on "1153/1153, 0 failed, 0 skipped, 22510 assertions".
That number reproduces only against a database in a particular state. On a clean database built the
documented way, the same command gives 62 errors. A gate whose evidence cannot be re-derived by the
next person is not a gate, and this project has already shipped one defect that passed 1152 tests.
Note that `PaymentConcurrencyTest` and `PaymentWebhookTest` — the very tests that prove the webhook
lock — are among the classes that errored, so the dedup evidence is *also* unreproducible on a clean
database.

**Fix (one file, no schema change).** Make the three inserts idempotent:
`DB::table('roles')->upsert($rows, ['nama'], ['deskripsi'])`, the same for `permissions` on `kode`, and
`role_permissions` on its composite key. That also makes `php artisan db:seed` re-runnable, which is a
property the current design cannot offer at all. Add a `Unit` test that runs `RbacSeeder` twice
against a seeded database and asserts the second run is a no-op — the primitive already exists at
`tests/Unit/RbacMigrateFreshSeedTest.php`.

Secondary, same area: `phpunit.xml` sets neither `failOnSkipped` nor `failOnIncomplete`, so the
plan-54 zero-skip requirement has no mechanical gate. `git show --name-only 23e9899 -- tests/` is
empty — the commit that closed todo 54 added no test and no config. Add both attributes.

---

## 4. MAJOR

### M1 — Ten of thirteen rate limiters are defined and none of them is mounted; two protect unauthenticated endpoints

**`app/Providers/AppServiceProvider.php:288-312`**

The inventory table is honest and then declines to act:

```
| `auth-login`     | identifier     | 5   | 60 s   | `POST /auth/login` |
| `auth-login-ip`  | client IP      | 60  | 60 s   | **not mounted** |
| `otp-kirim`      | identifier     | 3   | 60 s   | **not mounted** |
| `otp-kirim-jam`  | identifier     | 10  | 3600 s | **not mounted** |
| `auth-register`  | client IP      | 3   | 3600 s | **not mounted** |
| `auth-refresh`   | SHA-256 of token | 30 | 60 s  | **not mounted** |
| `booking`        | user id        | 10  | 60 s   | **not mounted** |
| `checkout`       | user id        | 5   | 60 s   | **not mounted** |
| `webhook-payment`| gateway + IP   | 60  | 60 s   | **not mounted** |
| `promo-validasi` | user id        | 20  | 60 s   | **not mounted** |
| `chat`           | consultation id| 60  | 60 s   | **not mounted** |

> "The eight unmounted entries are registered … but `routes/api.php` is owned by another executor
> **this round**, so the `->middleware('throttle:...')` line that would mount each one is a FINDING
> … rather than something this file may do. A registered limiter protects nobody until a route names
> it."

The table lists **ten**, the sentence says eight. More to the point: this was 54/54 and nothing
followed up. Measured from the live route table — three `throttle:` in the entire application:

```
POST api/v1/auth/login       ==> api, ThrottleRequests:auth-login
POST api/v1/auth/otp/verify  ==> api, ThrottleRequests:auth-otp-verify
POST api/v1/auth/register    ==> api, ThrottleRequests:auth-otp-send
```

The two that matter most are on endpoints that are **not** `auth:sanctum`:

- `routes/api.php:87` `POST /auth/refresh` — unauthenticated, presents a refresh token, does a
  database write per call, no ceiling.
- `routes/api.php:1174` `POST /webhook/payment/{gateway}` — unauthenticated, HMAC-verified, no ceiling.

And `AppServiceProvider.php:517-518` diagnoses an oracle and then leaves the limiter unmounted:

> "A pure calculation with no state of its own, which is exactly what makes it an oracle: 21 calls a
> minute is enough to enumerate a promo table."

`RateLimitingTest.php:24` (`each unmounted limiter really refuses through the framework middleware,
at its own ceiling`, 9 tests) proves the limiters *work* on a probe route. It does not prove any real
route uses them.

**The published contract is not lying** — I checked: `docs/openapi.yaml` carries exactly three
`x-ratelimit` blocks and `OpenApiDocumentBuilder::limitFor()` reads the limiter off the route's own
`throttle:` middleware, so the 429s published are the 429s enforced. The gap is enforcement, not
documentation.

**Fix.** Add `->middleware('throttle:auth-refresh')` to `routes/api.php:87`,
`->middleware('throttle:webhook-payment')` to `:1174`, and `promo-validasi`, `checkout`, `booking`,
`chat`, `otp-kirim`, `otp-kirim-jam`, `auth-register`, `auth-login-ip` to their routes. Then
regenerate `docs/openapi.yaml` (`php artisan sehatly:openapi`) and commit the new `x-ratelimit`
blocks, so the contract starts advertising what is actually enforced. Correct the "eight" to "ten".

### M2 — The published contract is wrong for 17 of 74 operations, and the generator fix was deferred to a todo that never came

**`docs/openapi.yaml:2720-2757`**

```yaml
SuccessEnvelope:
  properties: { success, data, message }
  additionalProperties: false          # :2736 — and there is NO `meta` property
PaginatedEnvelope:
  properties:
    data:
      type: array                      # :2745
      items: { type: object }
```

Both are wrong against the running application, and the second one is the dangerous one:

- `ApiResponse::success()` appends a top-level `meta` sibling for every list endpoint. 16 operations
  publish `SuccessEnvelope`, which forbids it. The generator's rule is
  `OpenApiDocumentBuilder::envelopeFor()` — it picks `PaginatedEnvelope` only when the operation's
  `FormRequest` rules contain `page`/`per_page`, and a GET has no `FormRequest`. The rule cannot tell
  "does not page" from "pages without being asked", so a `singlePageMeta()` list is published as
  non-paginated.
- **Every paginated endpoint answers `data` as a JSON object keyed by resource name**
  (`{"dokter":[]}`, `{"provinsi":[]}`) because each controller wraps its collection in a named key.
  `PaginatedEnvelope` declares `type: array`. A client typed from this reads a list and gets a map.

That is not theoretical — it is already in the shipped generated TypeScript:

**`web/src/types/api.d.ts:1138`** → `data: Record<string, never>[];`

`docs/contract-conformance.md:135-137` calls it "the most consequential finding for the mobile team:
it is the one that yields a model which compiles and then misbehaves at runtime."

**Why it is still open at HEAD.** `docs/contract-conformance.md:98-100`:

> "In every case the **document is the wrong side**, and the fix belongs to
> `App\Support\OpenApi\OpenApiDocumentBuilder` — **which this todo is forbidden to edit**."

Six more findings sit behind that one: `PaginatedEnvelope` *requires* `meta` while
`POST /konsultasi/{id}/chat/baca` sends none (`:153-162`); `PaginatedMeta.per_page` declares
`minimum: 1` while `singlePageMeta()` sends the row count, so an empty list sends `per_page: 0`
(`:166-181`); the QR verifier needs an unpublished `?token=` and answers an undocumented 422
(`:186-199`); the webhook publishes `security: []` and an undocumented 401 (`:201-213`); 23 GETs
publish a 422 with nothing describing it (`:215-233`).

The lock-in assertions in `tests/Contract/ContractDivergenceTest.php` **pass while the defect
exists**, by design — so nothing in the default suite is red.

**Fix.** `envelopeFor()` must select the paginated envelope from whether the controller actually
passes a meta block, not from whether a request body happens to carry a pagination field; and
`PaginatedEnvelope.data` must be `type: object`. Both are generator changes, and at 54/54 the
"forbidden to edit" constraint no longer applies. Then regenerate `docs/openapi.yaml`,
`web/src/types/api.d.ts` and the Dart generated files together.

### M3 — `tests/Contract/` is in no testsuite and in no CI job, so 201 tests gate nothing

**`phpunit.xml:7-14`**

```xml
<testsuites>
    <testsuite name="Unit">    <directory>tests/Unit</directory>    </testsuite>
    <testsuite name="Feature"> <directory>tests/Feature</directory> </testsuite>
</testsuites>
```

`tests/Contract/` is absent. Raw top-level test declarations are Unit 137, Feature 977, **Contract
39** — and the runtime counts from my own run are Unit 146, Feature 1007, i.e. `Unit + Feature`
only. So the "1153" headline is a **sum of two separate invocations**, not one. CI runs only the
first:

- `.github/workflows/contract.yml:191` → `php artisan test` (Unit + Feature)
- `.github/workflows/tests.yml:53` → `./vendor/bin/pest` (same testsuites)
- `rg 'tests/Contract|composer (run )?contract' .github/workflows/` → **zero findings**

`composer.json:54-58` defines a `contract` script that does run it, and
`docs/contract-conformance.md:241` records the exclusion knowingly ("`phpunit.xml` could not be edited
to register a third testsuite"). But nothing invokes it, so the "74/74 spec parity, 201/201 tests"
result is only ever produced by a human remembering to type `composer run contract`.

Three mutually inconsistent headline numbers exist: `README.md:142` says "1100 tests, 21630
assertions", `tests/Contract/Pest.php:50` says "the 1152-test main suite", and the reported figure is
1153/22510. My own run measured 1153/21950.

**Fix.** Add a third `<testsuite name="Contract">` to `phpunit.xml`, or add a
`php artisan test tests/Contract` step to `contract.yml`. Given M2, I would keep the conformance
suite out of the default `php artisan test` until the generator is corrected — otherwise every
developer sees 17 red. But it must be in CI one way or the other, or M2 has no tripwire.

### M4 — A hand-copied cross-language constant has drifted, and its docblock asserts a count the code contradicts

**`app/Services/RekamMedis/RekamMedisService.php:158-173`** — 14 writable columns:

```php
public const KOLOM_ISI = [
    'keluhan_utama', 'riwayat_penyakit_sekarang', 'riwayat_penyakit_dahulu',
    'riwayat_keluarga', 'riwayat_psikososial', 'hasil_pemeriksaan_fisik',
    'subjektif', 'objektif', 'asesmen', 'plan', 'diagnosis_kerja',
    'instruksi_tindak_lanjut', 'status_tindak_lanjut', 'jadwal_kontrol',
];
```

**`web/src/features/rekam-medis/rekam-medis-edit-form.tsx:291-305`** — twelve:

```ts
/** The fourteen writable columns, in `RekamMedisService::KOLOM_ISI` order. */
const KOLOM = [
    'keluhan_utama', ..., 'diagnosis_kerja', 'instruksi_tindak_lanjut',
] as const satisfies readonly (keyof RekamMedisIsi)[];
```

`status_tindak_lanjut` and `jadwal_kontrol` are missing, and the docblock says "fourteen" over a
list of twelve. `KOLOM` is what renders the inputs — `rekam-medis-edit-form.tsx:174`
`{KOLOM.map((kolom) => (`. Both fields *are* in `defaultValues` (`:76-77`) so they are submitted, but
neither has an input: **a doctor cannot set `status_tindak_lanjut` or `jadwal_kontrol` from the
SPA.** `jadwal_kontrol` is a follow-up appointment date and `status_tindak_lanjut` is the DDL's
five-value ENUM — both are clinically load-bearing.

This is precisely the "a service compared a DATE against a UTC-derived day" failure shape: a constant
copied across a language boundary, asserted by nothing, silently diverging, with a comment that
claims the invariant holds.

**Fix.** Restore both entries in `KOLOM` and correct the docblock, or — better — generate the list
into `web/src/lib/api/types.ts` from `KOLOM_ISI` the way `docs/enums.json` already is, and add an
assertion in the PHP suite that the generated TypeScript field list equals `KOLOM_ISI`. The
`satisfies readonly (keyof RekamMedisIsi)[]` already catches a *spelling* mistake; it cannot catch a
*missing* member, which is why this drifted.

### M5 — Dart: a throwing `clearAll()` leaves the refresh `Completer` unsettled, so every waiter hangs forever

**`packages/sehatly_api_client/lib/src/auth/refresh_coordinator.dart:249-269`**

```dart
} catch (error, stackTrace) {
  final ApiException failure = _asApiException(error);
  _rotatedAwayAccessToken = null;
  _lastRotation = null;

  await _storage.clearAll();                       // :257  <-- CAN THROW

  if (!_didExpireSession) {                        // :259
    _didExpireSession = true;
    onSessionExpired(failure);                     // :261
  }
  completer.completeError(failure, stackTrace);     // :264  <-- SKIPPED if :257 throws
} finally {
  if (identical(_refreshInFlight, future)) {        // :266
    _refreshInFlight = null;                       // :267
  }
}
```

`TokenStorage.clearAll()` rethrows on purpose — **`token_storage.dart:120-122`**:

```dart
if (firstError != null) {
  Error.throwWithStackTrace(firstError, firstStack ?? StackTrace.current);
}
```

So when the secure-storage backend throws on delete (device locked mid-refresh, keystore error,
disk full on the file backend), four things happen:

1. `:264` never runs. The `Completer` is never completed and never completed with an error — its
   future **never settles**.
2. Every caller awaiting `refreshAfterUnauthorized` hangs indefinitely with **no exception and no
   log line** — the exact failure `auth_interceptor.dart:38-41` describes as unacceptable.
3. `onSessionExpired` at `:261` never fires, so the user is never routed to login. The session is
   dead and the app does not know.
4. The `finally` at `:265-268` **does** clear `_refreshInFlight`, so the *next* 401 starts a fresh
   refresh. This masks the hang behind apparent progress.

Untested: `test/storage_test.dart:114-119` explicitly proves `clearAll()` throws, and grepping
`test/` finds no test that drives a throwing backend through `RefreshCoordinator`.

The two dedup guards themselves are **correct** and I verified them: `refresh_coordinator.dart:195`
installs `_refreshInFlight` synchronously before the first `await`, and `:139-145` memoises the
rotated-away token for the `QueuedInterceptor` case. `auth_interceptor.dart:253` +
`:366` terminate the retry loop, and `replayDio` is given no `replayDio` of its own (`:295-304`), so
no infinite loop is possible. The defect is purely the ordering inside the `catch`.

Secondary instance, same root cause, lower severity: `expireSession()` awaits `clearAll()` at
`refresh_coordinator.dart:164` before the `_didExpireSession` guard, so `onSessionExpired` is lost on
a throwing clear. It does not hang a caller (the throw lands in `auth_interceptor.dart:286`).

**Fix.** Wrap `:257` in its own `try { … } catch { /* best-effort clear */ }` so `:264` always runs;
attach a clear failure to the context rather than letting a storage problem replace a terminal
session outcome. Same for `:164`. Add a test that drives a throwing backend through
`RefreshCoordinator` — the primitive is at `test/storage_test.dart:110-119`.

---

## 5. MINOR

### m1 — `NotificationService` is never called, so nothing ever writes `notifikasi`

`app/Services/Notifikasi/NotificationService.php:139` is the **only** `new Notifikasi` in `app/`.
`app/Http/Controllers/Api/V1/NotifikasiController.php` only reads (`:107`, `:136`, `:160`, `:212`).
`rg 'NotificationService' app/` returns only a `use` import, three `{@see}` docblocks and the class
declaration — no call site.

So `GET /api/v1/notifikasi`, `PUT /notifikasi/{id}/baca` and `PUT /notifikasi/baca-semua` — three of
the 74 operations — serve a table that nothing in the application ever writes. The class docblock at
`NotificationService.php:28` says so: "This todo wires NO event producer." The routes are not dead
code; the *producer* is unwired, so the feature is permanently empty.

**Fix.** Wire the producers (booking confirmed/cancelled, payment settled, prescription verified,
resep fulfilled) to `NotificationService`, or state the three routes as not-yet-live in
`docs/openapi.yaml`. Note `F1`'s `WALK-54-SEED` finding in the ledger is adjacent but distinct.

### m2 — Two config keys are read that do not exist, so a patient-facing bank name is hardcoded

`app/Services/Payment/MockPaymentGatewayService.php:300-301`

```php
'nama_bank'    => (string) config('payment.nama_bank', 'Bank Uji Pembayaran'),
'nama_pemilik' => (string) config('payment.nama_pemilik', 'NASAB SEHATLY'),
```

`config/payment.php` defines `gateway`, `gateway_pembayaran`, `implementasi`, `metode_tipe_qr`,
`kedaluwarsa_detik` — **neither `nama_bank` nor `nama_pemilik` is there**, and neither has an entry
in `.env.example`. The defaults are unreachable by configuration. These strings are published in the
virtual-account instructions the patient is shown, so an operator cannot set them without a code
change.

**Fix.** Add `'nama_bank' => env('PAYMENT_NAMA_BANK', 'Bank Uji Pembayaran')` and
`'nama_pemilik' => env('PAYMENT_NAMA_PEMILIK', 'NASAB SEHATLY')` to `config/payment.php`, with the
two vars in `.env.example`. Alternatively, if the mock gateway is not meant to be configurable, drop
the `config()` calls and the phantom configurability.

### m3 — `referencia` typo in 15 docblocks, naming a path that does not exist

`app/Http/Controllers/Api/V1/ReferensiController.php:21,74,102` ·
`app/Http/Requests/Referensi/IndexReferensiRequest.php:13` · all ten
`app/Http/Resources/Referensi/*Resource.php:12` (Agama, GolonganDarah, HubunganKeluarga, Icd9cm,
Icd10, KabupatenKota, Kecamatan, Kelurahan, MetodePembayaran, Pendidikan, Provinsi, StatusPernikahan)
· `app/Services/Payment/PaymentService.php:94` (`nomor_referencia`).

Every one documents `/api/v1/referencia/…` or `nomor_referencia`. The real path is
`/api/v1/referensi/…` and the real column is `nomor_referensi` (`telemedicine_test.sql:963`). A
reviewer or integrator following a docblock lands on a 404.

**Fix.** `referencia` → `referensi` in those 15 lines. (Style, but it is 15 wrong facts in prose
that a reader will trust.)

### m4 — `NikCipher`'s write half is dead, and the required migration is not in the record

`app/Support/NikCipher.php:241-243` declares `COL_PAYLOAD = 'nik_cipher'` and
`COL_INDEX = 'nik_index'`. `rg 'nik_cipher|nik_index' database/migrations/` returns **zero** hits —
neither column exists in any of the 81 migrations. Six Resources call `NikCipher::mask()`;
`encrypt()`, `index()`, `indexMatches()`, `keyFingerprint()`, `indexKeyFingerprint()` and `hasKey()`
are called only from tests. Roughly 60% of a 583-line class exists to serve columns the schema does
not have.

Worse, `docs/schema-notes.md` — the project's designated record of deferred constraints and known
schema defects — contains no mention of `nik_cipher` or `nik_index`. The requirement survives only in
`.omo/evidence/task-50-sehatly.md`, which is executor-owned, not a schema record.

**Fix.** Add the two-column shape (`nik_cipher TEXT NULL`, `nik_index CHAR(16) UNIQUE NULL`) to
`docs/schema-notes.md`'s deferred-constraint section with the exact DDL, referencing
`telemedicine_test.sql:222`'s `WAJIB dienkripsi` comment. See §6 for the severity judgement.

### m5 — The scaffold placeholder test is still in the suite

`tests/Unit/ExampleTest.php:3-5`

```php
test('that true is true', function () {
    expect(true)->toBeTrue();
});
```

One unfalsifiable test out of 1153 (0.09%). It is the only genuinely vacuous assertion I found;
the ten other `expect(true|false)->toBeTrue()` sites are a legitimate "unreachable sentinel" idiom
inside `try` blocks (`tests/Feature/Audit/HardDeleteTest.php:89` reads
`expect(false)->toBeTrue($class.'::forceDelete() returned without throwing')`), and the eight
single-variable forms are all backed by real queries or real try/catch flags.

**Fix.** Delete the file. It is the Laravel default and it is the only assertion in the repository
that cannot fail.

### m6 — One "byte-identical" claim is not a byte comparison

`tests/Contract/SanctumAuthConformanceTest.php:193-197`

```php
// Byte-identical to the absent-token body. A caller must not be able to tell
// "no token" from "wrong token" -- that distinction is a token oracle.
expect($body->success)->toBeFalse();
expect($body->message)->toBe('Unauthenticated.');
expect(str_contains($raw, '"errors":{}'))->toBeTrue();
```

The comment promises byte-identity; the code asserts three field-level values and never compares
against the absent-token response at all. The **real** test is
`tests/Feature/Auth/AuthFlowTest.php:636`:

```php
expect($unknown->getContent())->toBe($wrong->getContent());
```

`getContent()` is the full unparsed body and `toBe` is strict identity — a genuine full-byte
comparison, with different phone numbers on the two requests so any echo of the identifier would
break it. That one passes review. The Contract copy claims credit it has not earned, and by M3 it
never runs.

**Fix.** Either make `:195-197` a real `getContent()` comparison against the absent-token body, or
delete the comment's claim.

### m7 — The `HttpExceptionInterface` branch would publish a 5xx message

`bootstrap/app.php` (the `render()` callback, `HttpExceptionInterface` arm):

```php
$e instanceof HttpExceptionInterface => ApiResponse::error(
    $e->getMessage() ?: 'Request rejected.', [], $e->getStatusCode(),
),
```

The message is forwarded for **every** status. A 4xx is fine and deliberate. A 5xx `HttpException`
would publish its message verbatim to any caller — so the day someone writes
`abort(503, 'upstream db: mysql://root:…@host/db timed out')` the sanitised-500 guarantee silently
stops holding for that route. Nothing in the app raises one today, which is why this is MINOR.

**Fix.** `statusCode >= 500 ? 'Internal server error.' : ($e->getMessage() ?: 'Request rejected.')`.
One line, and it makes the guarantee structural rather than dependent on nobody writing that call.

Everything else in the handler is correct and I verified the ordering against the framework source:
`Illuminate\Foundation\Http\Kernel::handle()` calls `reportException($e)` at `Kernel.php:146`
**before** `renderException($request, $e)` at `:148`, so the throwable is logged before the sanitised
body is produced. The diagnostic detail really does stay server-side.

### m8 — 323 identical lines between two React dialogs

`web/src/components/allergy/alergi-dialog.tsx:18` and
`web/src/components/family/anggota-keluarga-dialog.tsx:15` are byte-identical for ~304 lines of
code; `web/src/pages/allergy-page.tsx:149` and `web/src/pages/family-page.tsx:155` for ~84. The
allergy and family-member screens differ only in which columns they bind.

**Fix.** Extract the shared field/table body into one component parameterised by the column
descriptor. As written, a fix to one is silently not a fix to the other — which is how m1's class of
drift gets in.

### m9 — Two `rules()` methods that are byte-identical pure overrides

`app/Http/Requests/RekamMedis/SimpanRekamMedisRequest.php:32-45` and
`app/Http/Requests/RekamMedis/UbahRekamMedisRequest.php:28-41` are the same 14 lines, and both classes
extend `RekamMedisRequest`, which already provides `kolomIsi()` and `kolomMilikSistem()`. The second
is a pure override of the first.

**Fix.** Move the body to `RekamMedisRequest` and delete both overrides, keeping only what genuinely
differs (`tanggal_periksa` is already in the shared `KOLOM_TANGGAL_PERIKSA` handling at
`RekamMedisService.php:295`).

### m10 — One orphan model, contradicting its own migration

`app/Models/KlaimBpjs.php:31` is the only true orphan of the 284 classes in `app/` — no reference in
any other file. `database/migrations/2026_10_01_000067_klaim_bpjs_table.php:21-22` states the intent:

> "Todos 19, 44, 45 and 46 own everything else; this todo authors the table and nothing else.
> **No Model, no Resource, no Controller**"

The model exists and the migration says it should not. Either drop the model or update the
migration's stated contract.

Also dead: 17 exports in `web/src` that are declared and never referenced anywhere in `web/`
including tests — `web/src/lib/api/jadwal.ts:132,188`, `web/src/lib/token.ts:76,93`,
`web/src/lib/format.ts:233`, `web/src/lib/tanggal.ts:113`, `web/src/lib/api/rekam-medis.ts:47,246`,
`web/src/lib/api/konsultasi.ts:55,336`, `web/src/lib/api/auth.ts:141`,
`web/src/lib/api/resep-peringatan.ts:113,303`, `web/src/lib/api/types.ts:1268,1373,1490`,
`web/src/hooks/use-konsultasi-channel.ts:49`. `jadwal.ts:188` (`jadwalOptions`) is the clearest
asymmetry: its sibling `slotOptions` is used, this one is not. (The 18 type-only exports in
`web/src/types/api.contract.ts` are intentional compile-time contract assertions — not dead.)

---

## 6. My own verdicts on the two items I was asked to judge

### 6a — `PaymentService::huntap()`: the stated race is **not** a defect. The real one is in `mulai()`.

**The brief's premise is: "A lock matching no row locks nothing and there is no unique index on that
pair, so two concurrent first-deliveries are not excluded."** I read that carefully and I think it
conflates *first webhook delivery* with *first row creation*, and the distinction is the whole
answer.

The `pembayaran` row is created by `mulai()` at initiation —
`app/Services/Payment/PaymentService.php:266-289` — long before any gateway can deliver a
notification. So when `huntap()` runs, the row **always exists**; there is no "first delivery"
insert to exclude.

`SELECT … WHERE gateway = ? AND nomor_referensi = ? FOR UPDATE` on an existing row is a **current
read** under REPEATABLE READ. Transaction B blocks on the row lock; when it unblocks, A has
committed and B's locking read resolves against the *latest committed* version — now terminal — so
B takes the duplicate branch at `:354-361` and writes nothing.

And because the predicate matches no index, the statement is a full scan, and InnoDB's `FOR UPDATE`
locks **every row it examines**, not just the one it wants. So the exclusion is *stronger* than
`PaymentService.php:85-96` claims, not weaker: two unrelated settlements serialise behind each other,
which is a throughput cost the docblock already states honestly. The "no row" case throws
`ModelNotFoundException` → 404 and writes nothing, so it has no correctness hole either.

`tests/Feature/Payment/PaymentConcurrencyTest.php:250-437` proves exactly this with **two real MySQL
connections**, `innodb_lock_wait_timeout = 1`, isolation asserted on both, the real
`PaymentService::terimaWebhook()` (not a mock) colliding on MySQL error **1205**, and the negative
half — asserting B never issued an `update pembayaran` (`:332`). It also proves the converse
property at `:399-437`. I am satisfied this is correctly closed, and the docblock's own analysis
(`:64-96`) is accurate.

**The genuinely unclosed race is 20 lines earlier, in `mulai()`.**
`PaymentService.php:254-289` is check-then-act:

```php
return DB::transaction(function () use ($invoice, $metode, $transaksi, $jumlah): array {
    $ada = Pembayaran::query()
        ->where('invoice_id', $invoice->getKey())
        ->where('status', PembayaranStatus::Pending->value)
        ->first();                                   // :255-258  no lockForUpdate()
    if ($ada !== null) { throw ValidationException::...; }
    ...
    $pembayaran->save();                             // :289
    return [$pembayaran->fresh(), $transaksi];
});
```

The read is a plain one, so under REPEATABLE READ it resolves against the transaction's own snapshot
and cannot see a concurrent transaction's committed insert. Two concurrent `mulai()` calls on one
invoice therefore both see "no pending row" and both insert. `pembayaran` has no uniqueness that
would stop it — I confirmed the DDL myself: `telemedicine_test.sql:958-973`, the only non-primary
index is `INDEX idx_bayar_status (status, dibayar_at)` at `:972`, and no `UNIQUE` appears anywhere on
the table. The migration matches: `database/migrations/2026_10_01_000063_pembayaran_table.php:206,217,257`.

It is made worse by `:252`: `createTransaction()` is called **before** the transaction opens, so two
real gateway transactions are created and two virtual accounts are issued to the same patient. The
class docblock at `:218-223` describes the hazard exactly —

> "Two `pending` rows on one invoice means two virtual accounts the patient could pay, and
> `pembayaran` has no uniqueness that would stop it"

— and then relies on a guard that is not atomic.

**Severity: MAJOR** (it issues a duplicate live payment instrument, and it is reachable by a patient
double-tapping a flaky connection — no attacker required). **Fix, without touching the read-only
DDL:** take the pending-row read as a current read — `->lockForUpdate()` — and move
`createTransaction()` inside the transaction after that guard, so a lost race is detected before a
provider is ever asked to create anything. That is the same `lockForUpdate()` discipline the class
already applies in `huntap()`, applied to the one place that lacks it.

### 6b — `nik CHAR(16)` vs a TEXT ciphertext requirement: real, correctly diagnosed, and **latent rather than live**. MINOR.

**Facts I verified myself:**

- `telemedicine_test.sql:222` — `nik CHAR(16) NULL UNIQUE COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP'`
- `telemedicine_test.sql:263` — `nik CHAR(16) NULL` on `pasien_anggota_keluarga`
- `database/migrations/2026_10_01_000020_pasien_table.php:72-73` — `$table->char('nik', 16)->nullable()->unique()`
- `database/migrations/2026_10_01_000021_pasien_anggota_keluarga_table.php:32` — `$table->char('nik', 16)->nullable()`

**There is no migration-vs-DDL drift here.** The migrations reproduce the contract DDL exactly. The
DDL itself is the constraint, and the plan forbids changing it. Any finding phrased as "the migration
contradicts the DDL" would be wrong.

`NikCipher::encrypt()` produces an 88-character base64 payload — 6 header + 16 IV + 32 ciphertext +
12 MAC = 66 bytes, which is 22 base64 groups (`app/Support/NikCipher.php:178-184`,
`:PAYLOAD_LENGTH`). `CHAR(16)` provably cannot hold it, and
`tests/Feature/Pasien/NikCipherTest.php:444-493` proves it by reading `information_schema` on the
live database and then making **MySQL itself** raise error 1406 on a real insert of the 88-character
payload into the real `pasien.nik` column. That is a good test: it lets the database say no rather
than asserting a string length.

**But the ciphertext column is not "too short" — it is absent.**
`rg 'nik_cipher|nik_index' database/migrations/` → zero hits. So `PasienResource.php:93` reads a
non-existent attribute, Eloquent returns `null`, and `NikCipher::mask(null, $plaintext)`
(`app/Support/NikCipher.php:416-423`) takes the plaintext branch and masks the plaintext `nik`.

**And that is harmless today, because nothing ever writes a NIK.**
`app/Http/Controllers/Api/V1/PasienController.php:130` — `UpdatePasienProfileRequest` deliberately
excludes `nik` (along with `tipe`, `status`, `no_telepon`) from its validated keys, and no other
endpoint accepts one. So there is **no truncation, no corruption, no `tooShort` → 500, and no
plaintext NIK at rest.** The `UNIQUE` on `pasien.nik` is currently satisfied by a column nothing
populates.

**So the severity is MINOR, and the reason matters:** the defect is that the DDL's own `WAJIB
dienkripsi` requirement is unmet and the machinery to meet it is unwired — a readiness and
documentation hazard, not a data-protection incident. The risk is forward-looking: the first person
to add a NIK write endpoint must either author the two-column migration or silently write plaintext
into `CHAR(16)`, violating the contract DDL's stated intent. There is nothing in the codebase that
will stop them.

**Fix:** record the exact required DDL in `docs/schema-notes.md` under deferred constraints (see
m4) so the next author cannot miss it, and add a `No endpoint may write a NIK until `nik_cipher` and
`nik_index` exist` assertion — a test in the shape of `NikCipherAuditTest.php:159-222`, which already
sweeps all 75 tables for any column named `nik`. The DDL change itself is a schema-owner decision,
correctly outside this plan's authority.

---

## 7. Checked and clean — stated so the list is not padded

I looked for these and they are not findings. Listing them is the point: a review that reports only
problems is not distinguishable from a review that did not look.

**Type safety.** Zero `as any`, `@ts-ignore` or `@ts-expect-error` in `web/src` (the two hits are a
prose comment at `web/src/lib/token.ts:26` and one `eslint-disable` on a deliberate
`booking.spec.ts:277` `console.log`). `dart analyze` is clean including `--fatal-infos`;
`analysis_options.yaml:12-14` enables `strict-casts`, `strict-inference`, `strict-raw-types` and
`:36` enables `avoid_dynamic_calls`. **Zero `as` casts in the entire Dart package.** One `jsonDecode`
(`token_storage.dart:243`) and it is validated at `:245-247`. One null-assertion operator in the
package (`api_envelope.dart:98`) and it is dominated by its guard. `int.parse`/`double.parse` do not
appear at all — only `tryParse` at `core/json.dart:63,76`. PHP `mixed` (233 occurrences) is confined
to genuine boundary sites — `validated()` arrays, raw driver values, config — and is narrowed before
use.

**The CarbonImmutable / Carbon sibling bug is fully closed.** `rg '(\?Carbon |Carbon \$\w+)' app/`
returns exactly one hit and it is a comment: `app/Http/Resources/PasienResource.php:68`. Every
boundary uses `DateTimeInterface` — `WaktuIndonesia.php:43-50` (and the class returns
`CarbonInterface` throughout), `SuratKeteranganResource.php:139-160`, `RekamMedisResource.php:226-248`,
`RujukanResource.php:67-87`, `DokterDetailResource.php:175-181`, `ResepStateMachine.php:219-232`,
`RekamMedisReadScope.php:177-184`, `StrBerlaku.php:162-180`, `IssuedOtp.php:31-32`. The reason is
written down at `app/Support/WaktuIndonesia.php:43-50` and repeated at each site. This is the bug
class the brief warned about, and it does not recur.

**Date/time.** No remaining UTC-derived-day site. Every `DATE` write or comparison goes through
`WaktuIndonesia`: `BookingService.php:224-238`, `ResepService.php:452-477`,
`PesananObatService.php:752`, `ResepStateMachine.php:213-232`, `ObatInteraksiService.php:458-465`,
`DokterDirectoryService.php:415-431`, `StrBerlaku.php:178-204`, `AuthController.php:608-611`,
`SuratKeteranganService.php:356-362,541-545,575-576`. `docs/timezone-policy.md` exists and is
enforced. I specifically chased `app/Services/RekamMedis/RekamMedisService.php:309`
(`$baris->tanggal_periksa = … ?? Carbon::now()`) because it looked like the same bug — it is not:
`telemedicine_test.sql:630` is `DATETIME`, an instant, so `Carbon::now()` is correct there. Every
`Carbon::now()` assignment I traced lands on a `DATETIME`/`TIMESTAMP`; no `DATE` column receives a
UTC-derived value anywhere.

**Error handling.** Zero empty `catch` blocks in `app/`. Every `catch` in `app/Http` and
`app/Services` either rethrows, translates to a typed envelope, or records a failure — enumerated
all 30. No error path returns a success shape. The `HttpExceptionInterface` 5xx gap is m7.

**Exception handler.** `bootstrap/app.php` renders every `api/*` failure through one `match`; the
500 branch is a fixed `'Internal server error.'`; ordering verified against
`Illuminate\Foundation\Http\Kernel.php:146` then `:148`.

**NIK never raw.** `app/Services/Audit/AuditColumnPolicy.php:197-202` masks `nik`, `nomor_kk`,
`nomor_ihs_satusehat`, `nomor_rm`, `nomor_str`, `no_telepon`, `email`; `:220-226` gives the reason per
column; `:374` routes them through `NikMasker`. The three `Log::` sites in `app/`
(`Auth\LogOtpSender.php:44`, `Notifikasi\LogPushDispatcher.php:45`,
`Notifikasi\NotificationService.php:191,209`) carry no NIK — `LogOtpSender::mask()` masks the
destination. The Dart package has zero NIK in any log, `print`, or error message across all 82 `nik`
matches; `TokenPair.toString()` and `OtpChallenge.toString()` redact (`model/dto.dart:118-119,188`).

**Login failure byte-identity is real.** See m6.

**Token rotation is correct.** `app/Services/Auth/TokenService.php:175-191` takes the row lock
**before** reading `dicabut` — the docblock at `:161-165` explains that without that order two
concurrent refreshes both mint a pair and the reuse detector never fires. The reuse-detection write
is raised **outside** the transaction (`:143-156`) so the throw does not roll back the revocation.
Both are the right choice and both are documented.

**PDP consent is enforced.** `app/Services/SuratKeterangan/SuratKeteranganService.php:255` calls
`PdpConsent::require()`; the version rule is at `PdpConsentService.php:144-172` with a
`PerubahanVersiException` on the lost-update race (`:168-172`).

**Migrations are sound.** 81 files, all with a non-empty `down()`. **Zero `Schema::table()` calls**,
so the "up adds two columns, down removes one" class is impossible by construction. All 81
`Schema::create` targets distinct. 105 schema-level foreign keys, every parent created in an earlier
migration; the 106th is the raw `fk_vital_rm` at `2026_10_01_000076_..._table.php:155` (parent
`rekam_medis` = migration 42 < 76). All 75 contract tables created; the 6 extra infrastructure tables
(`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`) are
registered at `docs/schema-notes.md:75-80`. **Zero column-level drift** across 75 tables / 672
columns against `telemedicine_test.sql`, after ruling out the false-positive classes (uppercased
ENUM members, `softDeletes()` nullability, `->default(false)` → `default '0'`, table-level
`->unique('col')`).

**Authorisation is complete.** 74 `/api/v1` operations. Every route is either behind
`auth:sanctum` or is one of the five deliberately public ones, and each ungated route is argued in
place in `routes/api.php` (the public `dokter` reads at `:251-320`, the reference reads at
`:648-727`, the QR verifier at `:577-613`, the webhook at `:1109-1132`). `{id}` is bound with
`whereNumber('id')` or `where('id','(\d+)')` everywhere it is a surrogate key. `tests/Unit/RbacMigrateFreshSeedTest.php`
and `tests/Feature/RbacMiddlewareTest.php` close the loop on the catalogue.

**No debug leftovers, no TODO/FIXME/HACK markers, no commented-out code blocks** in `app/`, `routes/`,
`database/`, `packages/`, `web/src`. The `TODO` hits in `app/Support/Dokster/StrBerlaku.php` and
`app/Providers/AppServiceProvider.php` are prose references to "the plan's TODO-51", not markers.

**Test quality is high where it is high.** Zero `Mockery::mock` and zero `createMock` anywhere in
`tests/` — the suite uses recording doubles (`tests/Support/FakeOtpSender.php`,
`tests/Feature/Pdp/pdp47-helpers.php:440-477`) and asserts on production-observable state, never on
the double's own configured value. Several tests carry explicit negative controls proving they can go
red: `PaymentConcurrencyTest.php:305-334` ("a first version of this test did not say so — and survived
removing `lockForUpdate()` entirely"), `HardDeleteTest.php:105-108` ("a phantom instance proves
nothing about the guard"), `OpenApiCommandTest` (proves the drift check red). The two named gaps
(m5, m6) are the exceptions, not the rule.

---

## 8. Itemised fix list, by severity

| # | Sev | Location | What | Fix |
|---|---|---|---|---|
| **B1** | BLOCKER | `database/seeders/RbacSeeder.php:143-152,167,176` | Non-idempotent `insert()` ×3. 62 Feature tests error on any already-seeded database; `php artisan db:seed` cannot be run twice. Makes the 1153/22510 headline unreproducible. | `upsert` on `nama` / `kode` / composite key. Add a "seed twice" test. Add `failOnSkipped` + `failOnIncomplete` to `phpunit.xml`. |
| **M1** | MAJOR | `app/Providers/AppServiceProvider.php:288-312`; `routes/api.php:87,1174` | 10 of 13 limiters unmounted, incl. two on unauthenticated endpoints; docblock defers to a later executor that never came; says "eight", lists ten. | Mount all 10, regenerate `docs/openapi.yaml`, fix the count. |
| **M2** | MAJOR | `docs/openapi.yaml:2736,2745`; `web/src/types/api.d.ts:1138`; `app/Support/OpenApi/OpenApiDocumentBuilder.php` `envelopeFor()` | 17/74 operations publish a wrong schema; `PaginatedEnvelope.data` is `array` but the wire format is an object. Fix was deferred as "forbidden to edit". | Fix the generator's envelope selection and `data` type; regenerate all four artefacts. |
| **M3** | MAJOR | `phpunit.xml:7-14`; `.github/workflows/contract.yml:191`; `tests.yml:53` | 201 conformance tests in no testsuite and no CI job. | Add a third testsuite, or a CI step. |
| **M4** | MAJOR | `app/Services/RekamMedis/RekamMedisService.php:158-173` vs `web/src/features/rekam-medis/rekam-medis-edit-form.tsx:291-305,174` | Hand-copied column list drifted 14→12; `status_tindak_lanjut` and `jadwal_kontrol` unreachable from the SPA; docblock asserts the wrong count. | Restore both fields; generate the list rather than copying it; add a cross-language assertion. |
| **M5** | MAJOR | `packages/sehatly_api_client/lib/src/auth/refresh_coordinator.dart:257` (cf. `:264`, `:164`) | A throwing `clearAll()` leaves the `Completer` unsettled — every waiter hangs with no exception and `onSessionExpired` never fires. Untested. | Wrap the clear in its own `try`; add a throwing-backend test. |
| **m1** | MINOR | `app/Services/Notifikasi/NotificationService.php:139` (no caller) | 3 routes serve a table nothing writes. | Wire producers, or mark not-yet-live in the contract. |
| **m2** | MINOR | `app/Services/Payment/MockPaymentGatewayService.php:300-301` | `config('payment.nama_bank')` / `nama_pemilik` do not exist. | Add both keys + `.env.example` entries, or drop the `config()` calls. |
| **m3** | MINOR | `ReferensiController.php:21,74,102`, `IndexReferensiRequest.php:13`, 10 × `Resources/Referensi/*.php:12`, `PaymentService.php:94` | `referencia` typo naming a 404 path, 15×. | `referencia` → `referensi`. |
| **m4** | MINOR | `app/Support/NikCipher.php:241-243`; `docs/schema-notes.md` | `nik_cipher`/`nik_index` do not exist; the write half of the cipher is test-only; the required migration is unrecorded outside executor-owned evidence. | Add the exact DDL to `docs/schema-notes.md`; add a "no NIK write until these columns exist" assertion. |
| **m5** | MINOR | `tests/Unit/ExampleTest.php:3-5` | `expect(true)->toBeTrue()` — the only unfalsifiable test in the repo. | Delete. |
| **m6** | MINOR | `tests/Contract/SanctumAuthConformanceTest.php:193-197` | Comment claims byte-identity; code asserts 3 fields and never compares. Never runs (M3). | Make it a real `getContent()` comparison, or drop the claim. |
| **m7** | MINOR | `bootstrap/app.php` `HttpExceptionInterface` arm | `$e->getMessage()` forwarded for 5xx too, so a future `abort(503, '…secret…')` would publish it. | Clamp `statusCode >= 500` to the fixed string. |
| **m8** | MINOR | `web/src/components/allergy/alergi-dialog.tsx:18` / `family/anggota-keluarga-dialog.tsx:15` (323 lines); `pages/allergy-page.tsx:149` / `pages/family-page.tsx:155` (84 lines) | Byte-identical React screens. | Extract a shared component parameterised by column descriptor. |
| **m9** | MINOR | `SimpanRekamMedisRequest.php:32-45` / `UbahRekamMedisRequest.php:28-41` | Byte-identical `rules()`, both pure overrides of the shared base. | Hoist to `RekamMedisRequest`. |
| **m10** | MINOR | `app/Models/KlaimBpjs.php:31`; 17 dead `web/src` exports | One orphan model (its migration says "No Model"); 17 unused exports. | Delete the model or update the migration; remove the exports. |

**Also worth doing, not gating:** close the latent `expireSession()` ordering bug at
`refresh_coordinator.dart:164` (same root cause as M5, no hang), and add the cross-language
`KOLOM_ISI` assertion named in M4 — the `satisfies readonly (keyof RekamMedisIsi)[]` already catches
a misspelling, which is why the drift had to be a *missing member* to go unnoticed.

---

## 9. Why this is REQUEST CHANGES and not APPROVE

I want to be precise about the reasoning, because a lot here is good and a reviewer who only reads
the findings list will over-read it.

This branch is, in several respects, the best-executed work I have reviewed. The CarbonImmutable
sibling trap — the specific defect the brief told me to hunt, one that has already shipped a real
bug in this project — is closed everywhere and documented at every site. The timezone policy is
enforced rather than described. The exception handler is correct and its ordering is right for the
right reason. Login failure is byte-identical and timing-matched. Token rotation takes its lock before
its reuse read and raises the revocation write outside the transaction. Migrations reproduce a
75-table read-only contract with zero drift and are all reversible. The Dart client is stricter than
the PHP one. The concurrency tests use real connections and prove the converse.

None of that is in dispute, and none of it is a substitute for B1.

**B1 is a BLOCKER because the branch's own acceptance evidence does not reproduce.** I followed the
documented setup on a database I created myself, and got 62 errors instead of 0. The cause is a
seeder that cannot be run twice, guarded only by a transaction wrapper the seeder does not know
about. That is not a style problem: it means the number the gates are signed off on is a property of
one developer's database state rather than of the code. And it took out `PaymentConcurrencyTest` and
`PaymentWebhookTest` — the tests that carry the entire evidence base for §6a — along with the rest.
A gate whose evidence cannot be re-derived by the next reader is not a gate, and this project has
already shipped one defect that passed 1152 tests.

M1–M3 are the same shape at lower stakes: three things the code correctly identifies and then defers
— limiters that "protect nobody until a route names them", a generator this todo was "forbidden to
edit", a testsuite the plan "could not register". All three are honest, and all three are still open
at 54/54. The honest framing is that the work was done to a good standard and the follow-through was
not, which is a different problem from being done badly and is a different fix.

M4 and M5 are ordinary defects of the kind this project has shipped before: a constant copied across
a language boundary that drifted while its docblock asserted the invariant held, and an error path
that can leave a caller hanging with no exception.

I have deliberately kept the MINOR list to ten real items and not padded it, and I have stated
plainly in §7 the eight categories where I looked hard and found nothing — including the two named
defects, which on the evidence I found are correctly handled and correctly argued. §6 is my own
verdict on both, and on `huntap()` it is *against* the framing in the brief: the dedup is sound, the
unclosed race is 20 lines earlier in `mulai()`, and that one is not covered by any test.

---

*Reviewer: F2. No product file was modified. Verification artefacts written to
`C:\Users\axioo\AppData\Local\Temp\opencode\` only; the private database
`telemedisin_db_test_f2rev` was created for this review and `phpunit.xml` was left untouched.*
