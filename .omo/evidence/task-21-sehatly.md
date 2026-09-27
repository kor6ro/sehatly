# Task 21 evidence — `/me`, patient profile, family members and allergies

Repo `C:\Users\axioo\Desktop\sehatly`, branch `feat/sehatly-telemedicine`.
PHP `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17, not on `PATH`).
laravel/framework **13.33**, Laravel Sanctum 4.3.3, Pest 4, MySQL 8.

---

## 1. Endpoint surface

Eleven routes appended to `routes/api.php`. Every one carries `auth:sanctum` and **no**
`permission:` and **no** `tipe:` — the reasoning is in §3.

| Method | Path | Controller action | Middleware |
|---|---|---|---|
| `GET` | `/api/v1/me` | `MeController@show` | `auth:sanctum` |
| `GET` | `/api/v1/pasien/profil` | `PasienController@profilShow` | `auth:sanctum` |
| `PUT` | `/api/v1/pasien/profil` | `PasienController@profilUpdate` | `auth:sanctum` |
| `GET` | `/api/v1/pasien/anggota-keluarga` | `PasienController@anggotaKeluargaIndex` | `auth:sanctum` |
| `POST` | `/api/v1/pasien/anggota-keluarga` | `PasienController@anggotaKeluargaStore` | `auth:sanctum` |
| `PUT` | `/api/v1/pasien/anggota-keluarga/{id}` | `PasienController@anggotaKeluargaUpdate` | `auth:sanctum`, `whereNumber('id')` |
| `DELETE` | `/api/v1/pasien/anggota-keluarga/{id}` | `PasienController@anggotaKeluargaDestroy` | `auth:sanctum`, `whereNumber('id')` |
| `GET` | `/api/v1/pasien/alergi` | `PasienController@alergiIndex` | `auth:sanctum` |
| `POST` | `/api/v1/pasien/alergi` | `PasienController@alergiStore` | `auth:sanctum` |
| `PUT` | `/api/v1/pasien/alergi/{id}` | `PasienController@alergiUpdate` | `auth:sanctum`, `whereNumber('id')` |
| `DELETE` | `/api/v1/pasien/alergi/{id}` | `PasienController@alergiDestroy` | `auth:sanctum`, `whereNumber('id')` |

Route names: `me`, `pasien.profil.show`, `pasien.profil.update`,
`pasien.anggota-keluarga.{index,store,update,destroy}`,
`pasien.alergi.{index,store,update,destroy}`.

### Verbatim `php artisan route:list --path=api/v1`

```
PS> php artisan route:list --path=api/v1
 GET|HEAD api/v1/auth/devices .. auth.devices.index - Api\V1\AuthController@devicesIndex
 POST api/v1/auth/devices .. auth.devices.store - Api\V1\AuthController@devicesStore
 DELETE api/v1/auth/devices/{deviceId} .. auth.devices.destroy - Api\V1\AuthController@devicesDestroy
 POST api/v1/auth/login .. auth.login - Api\V1\AuthController@login
 POST api/v1/auth/logout .. auth.logout - Api\V1\AuthController@logout
 POST api/v1/auth/otp/verify .. auth.otp.verify - Api\V1\AuthController@verifyOtp
 POST api/v1/auth/refresh .. auth.refresh - Api\V1\AuthController@refresh
 POST api/v1/auth/register .. auth.register - Api\V1\AuthController@register
 GET|HEAD api/v1/me .. me - Api\V1\MeController@show
 GET|HEAD api/v1/pasien/alergi .. pasien.alergi.index - Api\V1\PasienController@alergiIndex
 POST api/v1/pasien/alergi .. pasien.alergi.store - Api\V1\PasienController@alergiStore
 PUT api/v1/pasien/alergi/{id} .. pasien.alergi.update - Api\V1\PasienController@alergiUpdate
 DELETE api/v1/pasien/alergi/{id} .. pasien.alergi.destroy - Api\V1\PasienController@alergiDestroy
 GET|HEAD api/v1/pasien/anggota-keluarga .. pasien.anggota-keluarga.index - Api\V1\PasienController@alergiInd…
 POST api/v1/pasien/anggota-keluarga .. pasien.anggota-keluarga.store - Api\V1\PasienController@alergiKeluargaSto…
 PUT api/v1/pasien/anggota-keluarga/{id} .. pasien.anggota-keluarga.update - Api\V1\PasienController@alergiKeluar…
 DELETE api/v1/pasien/anggota-keluarga/{id} .. pasien.anggota-keluarga.destroy - Api\V1\PasienController@alergiKelu…
 GET|HEAD api/v1/pasien/profil .. pasien.profil.show - Api\V1\PasienController@profilShow
 PUT api/v1/pasien/profil .. pasien.profil.update - Api\V1\PasienController@profilUpdate

 Showing [19] routes
```

`ROUTE_LIST_EXIT=0`.

### Verbatim `php artisan route:list --path=api/v1/pasien`

```
 Showing [10] routes
```

`PASIEN_EXIT=0`.

**The plan says 8. Ten ship.** See §8, finding F-1.

---

## 2. The ownership rule, and exactly where it lives

`app/Services/Pasien/PasienRecordAccess.php` — one class, one rule, and **every** call site
in `PasienController` is one of five statements:

```php
$pasien = $this->access->ownPasien($user);                      // 403 if the account owns none
$rows   = $this->access->anggotaKeluargaQuery($pasien);          // Child::whereBelongsTo($pasien)
$one    = $this->access->anggotaKeluargaOrFail($pasien, $id);    // 404 if not found under that scope
// ... and the same two for alergiQuery() / alergiOrFail()
```

**The rule, verbatim from the class docblock:**

1. `Pasien::where('user_id', $user->getKey())->first()`; `null` =>
   `AccessDeniedHttpException` => **403**.
2. Every child row is reached **only** as `Child::whereBelongsTo($pasien)` — the explicit
   `whereBelongsTo` form, not a post-filter — so the tenant filter is the first thing on the
   query (`Illuminate\Database\Eloquent\Concerns\QueriesRelationships::whereBelongsTo`,
   verified in `vendor/`).
3. A row addressed by `{id}` that is not found **under that scope** => `ModelNotFoundException`
   => **404**, never 403.

**Why the root is decidable at all:** `telemedicine_test.sql:220` is
`user_id BIGINT UNSIGNED NOT NULL UNIQUE`, so at most one `pasien` row names a `users` row.
That single uniqueness is what makes "your own record" a lookup rather than a search, and
everything below it (family, allergies, bookings, prescriptions, invoices) hangs off that one
root.

**Why 403 and 404 are different answers** — the refusal is about the *caller* in one case and
about *a row* in the other, and only the second is a cross-tenant existence oracle. This is
the plan's own rule ("receives 404 (not 403, not 200) so existence is not leaked across
tenants") and the same rule `AuthController::devicesDestroy()` already applies to another
account's `device_id`. The tests prove the two cannot be told apart: another patient's row and
a row that never existed produce **byte-identical** 404 bodies.

`ownPasien()` reads through `Pasien`'s `SoftDeletes` scope, so a soft-deleted profile answers
403 and cannot be resurrected through a still-live account (tested).

---

## 3. Permissions and `tipe` — every one, and why

**Zero `permission:` and zero `tipe:` on all eleven routes.** This is a decision, read against
`App/Support/Rbac/RbacCatalog.php` in full:

| candidate | verdict | reason |
|---|---|---|
| any of the 24 codes | **rejected** | `RbacCatalog::PERMISSIONS` names booking, jadwal, konsultasi, rekam_medis, surat_keterangan, resep, obat, pesanan, pembayaran, promo, notifikasi, audit, pdp and dokter actions. **None names a patient profile, a family member or an allergy.** Writing one would be inventing policy, which todo 4's brief forbids, and it would require editing `app/Support/Rbac/` — which this todo is forbidden to touch. `EnsurePermission` throws a `LogicException` (a **500**, not a 403) for a code not in the catalogue, so a typo here is a build-time mistake. |
| `tipe:pasien` | **rejected, deliberately** | It *would* resolve, and it is the honest alternative to record. It is not used because it answers "which account type is this", which cannot express "is this row yours", and because a `pasien`-typed account with **no `pasien` row passes it** and is then refused by `ownPasien()` anyway — so the service is load-bearing regardless and a second, weaker gate in front of it is one more thing to keep in sync. |
| `permission:<anything>` for `perawat` / `kurir` | **rejected** | `RbacCatalog` documents `perawat` and `kurir` as real `users.tipe` ENUM values (`:139`) that hold **no role** and therefore no grant. Any `permission:` code is a permanent lockout for those two account types. Neither owns a `pasien` row, so the data check refuses them correctly (tested for all six non-patient types including `superadmin`). |

The strongest statement the tests make: **a `superadmin`, which holds all 24 permission codes,
still receives 403 on all seven mutating routes** — so the gate is a data check, not a grant,
and no amount of permission turns a non-patient into a patient.

Tripwire kept: `PasienProfileTest` re-parses `routes/api.php` on every run and asserts every
`permission:`/`tipe:` string resolves against `RbacCatalog` **and that the match set is
empty**, so a later todo cannot introduce an unknown code and get a 500 at runtime.

---

## 4. How `ApiResponse` was widened, and what was migrated

`app/Support/ApiResponse.php`:

- `success()` gained a **fourth, optional** parameter `?array $meta = null`. When `null`, the
  key is **omitted entirely** — not emitted as `"meta": null` — so every envelope the class
  has always produced is byte-identical, which is what
  `ApiKernelTest::test_success_response_uses_the_exact_success_envelope` (an exact-body
  assertion) requires.
- `meta` is appended **after** `message`, never inserted at position two, so `data` stays
  between `success` and `message` as the mobile client and the OpenAPI generator require.
- Two derived helpers, so the key names live in one place:
  `pageMeta(LengthAwarePaginator): {current_page, last_page, per_page, total, from, to}` and
  `singlePageMeta(int $total)` for a list that is deliberately one page.

**Migrated:** `AuthController::devicesIndex()` moved from
`data: {devices: [...], total: N}` to `data: {devices: [...]}` + `meta: {…}`. The list is
still not paginated (an account has a handful of devices) but is reported as the degenerate
single page it is, so a client parses one list envelope for every list endpoint. The one
assertion in `AuthFlowTest` that read `data.total` became `meta.total` plus
`meta.current_page` / `meta.last_page` plus an assertion that `data` has **no** `total` key;
`PasienProfileTest` asserts the same migration from the other end, so either file being
reverted alone is a failure.

---

## 5. Files

### New — `app/Services/Pasien/`
- `PasienRecordAccess.php` — the ownership rule (§2)

### New — `app/Http/Controllers/Api/V1/`
- `MeController.php`
- `PasienController.php`

### New — `app/Http/Requests/Pasien/`
- `PasienRequest.php` (abstract base: `authorize() === true`, with the reason)
- `IndexPasienRequest.php` (abstract `?page=` / `?per_page=`, cap 100)
- `IndexAnggotaKeluargaRequest.php`, `IndexAlergiRequest.php`
- `UpdatePasienProfileRequest.php`
- `AnggotaKeluargaRequest.php` (abstract field set), `StoreAnggotaKeluargaRequest.php`,
  `UpdateAnggotaKeluargaRequest.php`
- `AlergiRequest.php` (abstract field set), `StoreAlergiRequest.php`, `UpdateAlergiRequest.php`

### New — `app/Http/Resources/`
- `PasienResource.php`, `PasienAnggotaKeluargaResource.php`, `PasienAlergiResource.php`,
  `DokterAkunResource.php`

### New — `app/Support/`
- `NikMasker.php`

### Modified (5, all named in the commit pathspec)
- `app/Support/ApiResponse.php` — the `meta` key
- `app/Http/Controllers/Api/V1/AuthController.php` — `devicesIndex` migrated
- `app/Http/Resources/UserResource.php` — extended with `whenLoaded('pasien')` /
  `whenLoaded('dokter')`; **not** duplicated
- `routes/api.php` — appended
- `tests/Feature/Auth/AuthFlowTest.php` — the one `data.total` assertion

### New — tests
- `tests/Feature/Pasien/PasienProfileTest.php`

### Untouched, as required
`app/Models/**`, `app/Support/Rbac/**`, `app/Services/Auth/**`, `database/migrations/**`,
`database/seeders/**`, `database/factories/**`, `telemedicine_test.sql`,
`docs/schema-notes.md`, `docs/migration-order.md`, `config/fortify.php`,
`app/Http/Controllers/Settings/*`, `web/src/**`. Verified by
`git status --porcelain -- <those paths>` returning **empty**.

`mobile/` does not exist and no Dart or Flutter file was created.

---

## 6. A.26 gates

### (a) Non-ASCII scan

Regex `[^\x00-\x7F\u2013\u2014\u2022\u2026\u2192\u2212\u00A7\u2225]`, PCRE2 `\x{…}` form (the
`\uXXXX` form is PCRE1 and does not compile here — worth knowing, it is the A.26 regex
written the way a .NET developer would read it and it fails loudly rather than silently
passing).

```
app/Support/ApiResponse.php                                              clean
app/Support/NikMasker.php                                                clean
app/Services/Pasien/PasienRecordAccess.php                               clean
app/Http/Controllers/Api/V1/AuthController.php                           clean
app/Http/Controllers/Api/V1/MeController.php                            clean
app/Http/Controllers/Api/V1/PasienController.php                        clean
app/Http/Resources/UserResource.php                                      clean
app/Http/Resources/PasienResource.php                                    clean
app/Http/Resources/PasienAnggotaKeluargaResource.php                    clean
app/Http/Resources/PasienAlergiResource.php                             clean
app/Http/Resources/DokterAkunResource.php                               clean
app/Http/Requests/Pasien/PasienRequest.php                              clean
app/Http/Requests/Pasien/IndexPasienRequest.php                         clean
app/Http/Requests/Pasien/IndexAnggotaKeluargaRequest.php               clean
app/Http/Requests/Pasien/IndexAlergiRequest.php                         clean
app/Http/Requests/Pasien/UpdatePasienProfileRequest.php                 clean
app/Http/Requests/Pasien/AnggotaKeluargaRequest.php                     clean
app/Http/Requests/Pasien/StoreAnggotaKeluargaRequest.php                clean
app/Http/Requests/Pasien/UpdateAnggotaKeluargaRequest.php               clean
app/Http/Requests/Pasien/AlergiRequest.php                              clean
app/Http/Requests/Pasien/StoreAlergiRequest.php                        clean
app/Http/Requests/Pasien/UpdateAlergiRequest.php                        clean
routes/api.php                                                           clean
tests/Feature/Pasien/PasienProfileTest.php                              clean
tests/Feature/Auth/AuthFlowTest.php                                      clean

files=25  bytes=268069  flagged_codepoints=0  permitted_typographic_uses=0
NONASCII_EXIT=0
```

**Zero**, and not even the permitted typographic set is *used*: every authored file is pure
ASCII, so the allowance is not load-bearing and a future non-ASCII edit has to be deliberate.
The NIK mask character is written `"\u{2022}"` — an ASCII escape that **emits** U+2022 — so
the source stays clean while the wire format carries the plan's bullet run.

### (b) Token audit — the only gate that sees corrupted ASCII

Every `snake_case` token from the same 25 files, checked against a **531-token DDL
vocabulary** built with the project's own `App\Support\Schema\SqlSchemaParser` (the same
parser `sehatly:verify-schema` uses): all 75 table names, all 672 column names, every named
index, both view names, and every quoted literal in the reference file (which is how ENUM
members and `COMMENT` values enter). A token **not** in that vocabulary and **within
Levenshtein distance 2** of one is flagged — that is the `doker_umum`-for-`dokter_umum`
shape.

```
DDL vocabulary: 531 tokens (tables, columns, index names, enum members, quoted literals)

distinct snake_case tokens: 124
  present verbatim in the DDL : 85
  NEAR-MISS against a DDL token: 0
  application vocabulary       : 39

=== explicit check for the A.18 / A.26 class ===
  doker_umum               occurrences: 0
  doker                    occurrences: 0
  doktor                   occurrences: 0
  dketter_umum             occurrences: 0
  pasien_agama             occurrences: 0
  pasien_aliologi          occurrences: 0
  user_devcies             occurrences: 0
  user_devciies            occurrences: 0
  nama_lengakap            occurrences: 0
  tanggal_lahirr           occurrences: 0
  tipe_alergin             occurrences: 0
  keparahaan               occurrences: 0
  hubungan_keluarg         occurrences: 0
  dicatat_oleh_user_id     occurrences: 9
  provinsi_id              occurrences: 16
  kabupaten_kota_id        occurrences: 16
  kecamatan_id             occurrences: 16
  kelurahan_id             occurrences: 12
  tahun_lulus              occurrences: 8
```

**Zero near-misses.** The 39 "application vocabulary" tokens are PHP built-ins
(`array_keys`, `str_contains`, `mb_strlen`, `preg_match_all`, …), the API's own request/response
field names (`per_page`, `current_page`, `last_page`, `access_token`, `refresh_token`,
`token_type`, `ttl_detik`), the Sanctum table name, and one deliberately invalid test value
(`obat_antibiotik`, used to prove `Rule::in` refuses an out-of-enum `tipe_alergen`). Each was
read individually.

`keparahan` and `tahun_lulus` read 0 in the probe list only because the token extractor
requires an underscore; both are DDL columns and both are covered by the live tests below.

**The audit is not decorative — it is also four live tests** that re-parse
`telemedicine_test.sql` with `SqlSchemaParser` on every run and compare with `toBe` (which
checks **order** as well as membership):

- `pasien_alergi.tipe_alergen` => `AlergiRequest::TIPE_ALERGEN` (4 values, DDL order)
- `pasien_alergi.keparahan` => `AlergiRequest::KEPARAHAN` (4 values, DDL order)
- `pasien_anggota_keluarga.jenis_kelamin` => `AnggotaKeluargaRequest::JENIS_KELAMIN`
- `pasien_alergi.keparahan` `DEFAULT 'ringan'` => `AlergiRequest::KEPARAHAN_DEFAULT`
  (`ColumnSpec::$default` is the **raw DDL token with quotes**, so the quotes are stripped in
  the test rather than restated in the constant)
- `pasien`'s five foreign keys and its four bare reference columns, measured
- `pasien_anggota_keluarga` and `pasien_alergi` have `dibuat_at` and **neither** `diubah_at`
  nor `dihapus_at` — which is what forces the hard deletes

---

## 7. Tests

### `php artisan test --filter=PasienProfileTest`

```
PasienProfileTest: tests=102 passed=102 failed=0 errors=0 assertions=1072
```

### `php artisan test --filter=AuthFlowTest`

```
AuthFlowTest: tests=54 passed=54 failed=0 errors=0 assertions=455
```

(441 → 455 assertions: the migrated `meta` block plus the "no `data.total`" assertion.)

### The coverage matrix

For **each of the seven mutating endpoints**, data-driven:

| caller | status | test |
|---|---|---|
| own record | 2xx, and the table is asserted, not just the status | 3 CRUD tests |
| **another patient's row, addressed by `{id}`** | **404**, body byte-identical to a row that never existed, and the other patient's row read back **unchanged** | 2 tests (family, allergy) |
| **a non-patient account** (`superadmin`, `dokter`, `admin`, `perawat`, `kurir`, `apoteker` — the first holding all 24 permissions) | **403**, and nothing written | 1 test x 7 datasets |
| a `pasien`-typed account with **no `pasien` row** | **403**, not a 500 | 1 test x 7 datasets |
| anonymous | 401 envelope, byte-identical, no `Location` header | 1 test x 11 datasets |
| a client-supplied `pasien_id` / `dicatat_oleh_user_id` | 201/200, and the row lands under the **caller's** ids | 2 tests |

Plus: 11 forbidden profile keys (`tipe`, `status`, `no_telepon`, `nik`, `jenis_kelamin`,
`tanggal_lahir`, `nomor_rm`, `user_id`, `catatan_alergi`, `is_meninggal`, `rhesus`) each sent
in a **200** payload and each asserted unchanged on both rows; 10 profile width/format
failures; 11 family field failures; 5 out-of-enum allergy values x create **and** update, each
asserting the valid row is untouched; `per_page=101` / `per_page=0` / `page=0` / `page=abc`;
a non-numeric `{id}`; a soft-deleted profile; pagination `meta` on both list endpoints
including `current_page`/`last_page`/`per_page`/`total`/`from`/`to`; a masked NIK and KK with
no password hash anywhere in the **serialised body**; a doctor `/me` with
`spesialisasi`/`pendidikan` arrays ordered `is_utama`-first and `tahun_lulus`-desc and **no**
`nomor_str`/`nomor_sip`/file URL; the whole route table with its middleware; and the six DDL
parity assertions of §6(b).

### The delta against the pre-existing red baseline

Baseline captured **before** any change on this branch (`e95e333`):

```
result=failed  tests=261  passed=250  failed=11  errors=  assertions=6578  duration_ms=116497
```

After, on `telemedisin_db_test`, with no concurrent process:

```
PS> php artisan test
ARTISAN_TEST_EXIT=1
result=failed  tests=391  passed=380  failed=11  errors=  assertions=7938  duration_ms=161593

FAILURES:
  AuthenticationTest::__pest_evaluable_users_can_authenticate_using_the_login_screen
  EmailVerificationTest::__pest_evaluable_email_can_be_verified
  PasswordConfirmationTest::__pest_evaluable_password_can_be_confirmed
  PasswordResetTest::__pest_evaluable_reset_password_link_can_be_requested
  PasswordResetTest::__pest_evaluable_reset_password_screen_can_be_rendered
  PasswordResetTest::__pest_evaluable_password_can_be_reset_with_valid_token
  RegistrationTest::__pest_evaluable_new_users_can_register
  PasswordUpdateTest::__pest_evaluable_password_can_be_updated
  ProfileUpdateTest::__pest_evaluable_profile_information_can_be_updated
  ProfileUpdateTest::__pest_evaluable_email_verification_status_is_unchanged_when_the_email_address_is_unchanged
  ProfileUpdateTest::__pest_evaluable_user_can_delete_their_account
```

| | baseline | after | delta |
|---|---|---|---|
| tests | 261 | 391 | **+130** |
| passed | 250 | 380 | **+130** |
| **failed** | **11** | **11** | **0 — unchanged, and the same eleven** |
| errors | 0 | 0 | 0 |
| assertions | 6578 | 7938 | +1360 |

`artisan test` exits 1 **because of the eleven pre-existing failures**, which is the baseline
behaviour and not a regression. The claim this todo makes is the delta: **+130 tests, +130
passed, +0 failures.**

**+130 = +102 (this todo) + 28 (the parallel todo-22 executor's `DokterDirectoryTest`, present
and fully green in the working tree at run time — verified with
`vendor/bin/pest --list-tests tests/Feature/Dokter` = 28).** None of the 28 is authored by this
todo and none is in its commit.

The eleven are the Fortify/Inertia **web scaffold**, owned by todo 30 and untouched:
`Settings/ProfileController.php:39` writes `email_verified_at` (5), Fortify password
confirmation and reset read `users.password` where the column is `kata_sandi_hash` (4),
`EmailVerificationTest` awaits `Illuminate\Auth\Events\Verified` which this contract does not
define (2). `config/fortify.php` and the `Settings\*` controllers were **not** edited and no
test was deleted or skipped to make the number smaller.

---

## 8. Findings — plan errors, tensions and stale claims

**F-1 — the plan's route count is unsatisfiable. `route:list --path=api/v1/pasien` will never
list 8 routes; it lists 10, and the plan's own prose is what makes it 10.**
The todo's acceptance criterion says 8. Its prose enumerates
`GET`/`PUT /pasien/profil` (2), `GET`/`POST /pasien/anggota-keluarga` (2),
`PUT`/`DELETE /pasien/anggota-keluarga/{id}` (2), `GET`/`POST /pasien/alergi` (2),
`PUT`/`DELETE /pasien/alergi/{id}` (2) = **10**. All ten ship; the count is not met by
dropping endpoints. This is the **same defect class as todo 20's "lists all 6 routes"** for
seven named operations — the third occurrence of a plan count contradicting its own prose.
`GET /api/v1/me` is an eleventh route, outside the `pasien` path filter.

**F-2 — the brief's "cross-patient denied 403" and the plan's "404, not 403" are in tension.
Both were implemented, with the split made explicit.** Row-addressed access to another
patient's row is **404**, because the plan states it as a hard acceptance criterion with a
reason (non-disclosure) and because `AuthController::devicesDestroy()` already does the same.
403 is implemented for the **capability** case: an account that is not a patient — including a
`superadmin` holding all 24 permission codes — is refused 403 on all seven mutating routes, and
a `pasien`-typed account with no `pasien` row is also 403. A literal 403-for-another-patient's-row
would let a caller enumerate ids across tenants and is refused as a criterion. Both statuses
are tested on every mutating endpoint.

**F-3 — `DokterResource` was already taken.** The plan's todo 21 says to add `DokterResource`;
the plan's todo 22 says it creates `DokterResource` **and** `DokterDetailResource` for the
public directory. A parallel executor had already created
`app/Http/Resources/DokterResource.php` (a projection of the `v_dokter_katalog` **view**, with
`spesialisasi` as a `GROUP_CONCAT` string) in the shared working tree when this todo was
written. Two projections of two different tables with two different audiences are not
reconcilable by widening either one, and todo 22 was actively rewriting and reverting that
file during this todo's run. This todo therefore ships
`app/Http/Resources/DokterAkunResource.php` — named for what it publishes (the doctor row the
**account owns**), so the two cannot overwrite each other and a reader can tell from the name
which projection a route is getting. Consolidating them is a decision for whichever of todos
22 or 23 still owns the directory. **Todo 22 must know this file exists and must not
reintroduce the collision.**

**F-4 — the plan's own `Enum` instruction cannot be followed literally, and the reason is the
same one that bit todo 19.** The plan asks for "`Rule::enum`-equivalent rules".
`Illuminate\Validation\Rules\Enum` requires a real PHP enum **class**, and on
laravel/framework 13.33 the `enum:` **cast** is a silent no-op for exactly the same reason.
This project has no PHP enum for a MySQL ENUM (every model casts these columns to `string`),
so `Rule::in()` is the equivalent and is what ships, matching `RegisterRequest` and
`StoreDeviceRequest`. The four-and-four value lists are re-parsed out of the DDL on every test
run, which is the property that matters.

**F-5 — `PUT` is implemented as a partial update, which the plan does not specify.** The plan
names `PUT` and says nothing about partiality. Full replacement was rejected: a mobile client
editing one field of a family member would have to re-send the birth date and NIK, and a client
that got one wrong would blank a real one. Only the keys present in the body are written; the
response is always the whole row afterwards, so a client never has to reason about which fields
stuck. Both the profile update and both `PUT`s behave this way and it is tested.

**F-6 — deletes are HARD deletes, and the schema forces it.** `pasien_anggota_keluarga`
(`:269`) and `pasien_alergi` (`:282`) declare `dibuat_at` and nothing else — no `dihapus_at`,
no `diubah_at`. Only `users` (`:148`) and `pasien` (`:249`) are soft-deletable, which is why
`SoftDeletes` is on those two models and no other. Consequence recorded rather than worked
around: **a delete leaves no trace in these tables.** `audit_log` (`:1118`) is where such an
event belongs and its write path is not in this todo's scope; named here so the gap is a
decision on the record rather than an oversight.

**F-7 — three `pasien` columns are deliberately withheld from `PasienResource`, and one
deliberate widening of the plan's rule.** `user_id` (the row is always the caller's own),
`nomor_ihs_satusehat` (a Kemenkes SATUSEHAT identity no client here may set) and
`catatan_alergi` — the DDL's own **second, unsynchronised allergy source** (`:242`), beside the
`pasien_alergi` table this API owns an endpoint for; publishing both from one response is how a
client ends up showing two disagreeing allergy lists. Conversely the plan requires masking
`pasien.nik` only, and this todo **also masks `pasien.nomor_kk` and
`pasien_anggota_keluarga.nik`** — same class of personal identifier under UU PDP 27/2022
Article 20. That is a deliberate widening of the plan's literal rule, recorded in
`PasienResource` and `PasienResource`'s own test, and its cost is stated there: a client cannot
read a family member's NIK back in full and must keep what it was given.

**F-8 — an addition beyond the plan: the `pasien` wilayah chain is checked for coherence.**
`master_kelurahan → master_kecamatan → master_kabupaten_kota → master_provinsi` is a chain of
three `NOT NULL` references and nothing in the schema stops a write that pairs a Bandung
kelurahan with a Bali province. `UpdatePasienProfileRequest::after()` walks up from whichever
of the four is present and compares the ancestor against the supplied **or already-stored**
value. At most three indexed primary-key reads. The plan asks only for `Rule::exists`.

**F-9 — the four `pasien` wilayah columns have NO foreign key, so `exists` is the only check
the schema permits, and that is measured rather than asserted.** `telemedicine_test.sql:236-238`
declares `provinsi_id`, `kabupaten_kota_id`, `kecamatan_id` and `kelurahan_id` with no
`FOREIGN KEY` at all — they are part of the DDL's 23 bare columns, which is also why `Pasien`
has no `provinsi()` relation. A test parses the file and asserts all four are FK-free **and**
that the four that do have one (`golongan_darah_id`, `agama_id`, `pendidikan_id`,
`status_pernikahan_id`) still do, so the docblock claim fails the suite if the schema moves.

**F-10 — three real defects this todo found in its own code, all fixed before the suite was
claimed green.** Recorded because each read as a shipped-code bug:
1. **`PasienResource` type-hinted `?Illuminate\Support\Carbon` on a private date helper** and
   500'd on every `/me`. On laravel/framework 13.33 the app boots with
   `Date::use(CarbonImmutable::class)`, so `Model::asDateTime()` returns a
   `Carbon\CarbonImmutable` — a **sibling** of `Illuminate\Support\Carbon`, not an instance of
   it. The helpers are gone; the casts are called inline, as `UserResource` already did.
2. **`PasienController` imported `IndexAlergiRequest` in neither the `use` block nor the
   signature's namespace**, so `GET /api/v1/pasien/alergi` was a
   `ReflectionException` → 500. Caught by the empty-list test.
3. **A create that omitted `keparahan` answered `"keparahan": null` while the row said
   `ringan`.** `keparahan` is `NOT NULL DEFAULT 'ringan'` and the key is absent from the
   insert, so the **database** supplies the value — and Eloquent does not read a column back
   after an insert. Fixed with a `refresh()` after the save, with a comment saying why; the
   alternative (restating `'ringan'` in PHP) would make application code a second source of
   truth for a DDL default.

**F-11 — a test-fixture trap worth knowing: `RefreshDatabase` uses transactions, and a
rollback does not reset an `AUTO_INCREMENT` counter.** The `/me` doctor test hard-coded
`master_spesialisasi` ids 1 and 2 and was correct in the first test that ran and MySQL 1452 by
the hundredth. The ids are now read from the table, and the reason is in the test's docblock.
The same trap applies to `wilayahChain()`, which uses `insertOrIgnore()` rather than a
`static $planted` latch for exactly this reason.

**F-12 — `master_kabupaten_kota`, `master_kecamatan` and `master_kelurahan` are NEVER seeded.**
`telemedicine_test.sql`'s section `[16]` inserts 38 `master_provinsi` rows and nothing else,
so `MasterWilayahSeeder` covers one table of the four. The address chain these tests validate
against is therefore planted as a fixture in `beforeEach`. A test that assumed otherwise would
have failed with a `Rule::exists` 422 that looks like a schema bug.

**F-13 — `Rule::in` does not trim, but the framework does.** `'lingkungan '` with a trailing
space is **accepted** (201), because `Illuminate\Foundation\Http\Middleware\TrimStrings` is in
the global stack and normalises the input before validation sees it. A test that had assumed
the trailing space was an out-of-enum value would have failed for the right reason by accident.
There is now an explicit test asserting both the middleware's presence and the 201, so a future
change to the stack turns it from 201 to 422 and somebody can connect the two.
(Note the class is `Illuminate\Foundation\Http\Middleware\TrimStrings`, **not**
`Illuminate\Http\Middleware\TrimStrings`; the first draft of that test used the wrong one and
failed.)

**F-14 — `tipe_alergen` is not validated against `master_obat`, deliberately.**
`pasien_alergi.nama_alergen` (`:278`) is `VARCHAR(150)` with no foreign key. An
`exists:master_obat,nama_generik` rule is the plausible-looking rule that is wrong: an allergy
is frequently to a food, a latex or a household chemical with no row in a medicine catalogue,
and the schema's own recorded limitation is best-effort name matching against the
`resep_item.nama_obat` snapshot.

**F-15 — todo 22's "13 Modul 1 endpoints" cannot be reconciled with what has shipped.** Todo 20
delivered **8** auth routes and this todo **11**, so Module 1 is at **19**. The plan's global
claim of "~50 endpoints across the five modules" is not checkable against anything yet
delivered. Flagged for the todo-25 module summary, not acted on here.

**F-16 — the todo-20 record's claim about `/auth/devices` was accurate and is now discharged.**
`.omo/evidence/task-20-sehatly.md` and its ledger entry both say "`GET /auth/devices` returns
`data.total` because `ApiResponse` has no `meta` key — TODO 21 MUST WIDEN IT". It had no
`meta` key; it does now, and the devices response has been migrated (§4).

---

## 9. Concurrency, and the scratch database

A parallel todo-22 executor was running the full suite — which executes a real
`migrate:fresh --seed` against the shared `telemedisin_db_test` through
`tests/Unit/RbacMigrateFreshSeedTest.php` — while this todo was iterating. Three runs produced
19, 19 and 102 errors whose messages were all `1146 Table 'telemedisin_db_test.migrations'
doesn't exist` / `1050 Table 'cache' already exists` / `1824 Failed to open the referenced
table 'master_kabupaten_kota'`: the signature of two processes rebuilding one schema. **No
failure in this todo was a code failure**; each was re-run and green.

To iterate without being a lottery, a scratch database `telemedisin_db_t21` was created,
used, and **dropped again** (`dropped telemedisin_db_t21`). `telemedisin_db` was never opened
for writing and `migrate:rollback` was never run. The authoritative numbers in §7 are from
`telemedisin_db_test` with no concurrent process.

Two runs of the full suite on `telemedisin_db_test` in a quiet window both reported
**391 / 380 / 11 / 0 / 7938**; they are quoted once in §7 and the second is the run that
produced the `duration_ms=161593` figure.

---

## 10. Other verification

```
php artisan route:list --path=api/v1                exit 0   19 routes
php artisan route:list --path=api/v1/pasien        exit 0   10 routes
php artisan test --filter=PasienProfileTest        exit 0   102 tests / 102 passed / 1072 assertions
php artisan test --filter=AuthFlowTest             exit 0    54 tests /  54 passed /  455 assertions
vendor/bin/pint --test                            exit 0   {"tool":"pint","result":"passed"}   (whole tree)
php artisan sehatly:verify-schema                  exit 0   Discrepancies: 7 (0 drift, 7 informational)
                                                            " PASS - 75 tables, 2 views verified. Nothing was written."
```

`telemedicine_test.sql` SHA-256:

```
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
```

byte-identical to the contract's hash. `git status --porcelain` over every forbidden path
returns empty. `test ! -e mobile` succeeds. `Pint` passes over the **whole** tree, not only
the files this todo authored.

`sehatly:verify-schema` still reports the **7 registered extra tables as informational** and
was not chased to 0, per A.25: suppressing them means deleting the registry rows, which turns
all seven into `undocumented_extra_table` drift and breaks exit 0 outright.

### What the only change to pre-existing content of `routes/api.php` is

`vendor/bin/pint`'s `fully_qualified_strict_types` + `ordered_imports` fixers added two
`use` lines so the appended group could name its controllers short. `git diff routes/api.php`
is one two-line hunk above the auth block and one append below it:

```
@@ -3,6 +3,8 @@
 declare(strict_types=1);
 
 use App\Http\Controllers\Api\V1\AuthController;
+use App\Http\Controllers\Api\V1\MeController;
+use App\Http\Controllers\Api\V1\PasienController;
 use Illuminate\Support\Facades\Route;
@@ -98,3 +100,108 @@
             ->name('devices.destroy');
     });
 });
+   ... 105 appended lines ...
```

The eight auth route definitions, their middleware, their names and their order are
**byte-identical**. `AuthFlowTest`'s own route-table assertion and this todo's both still
enumerate the same eight, so a future edit to either side fails the suite.
