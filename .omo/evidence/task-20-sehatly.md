# Task 20 evidence — Module 1 auth endpoints

**Todo:** 20. Implement Module 1 auth endpoints: register, login, OTP verify, refresh, logout, devices
**Branch:** `feat/sehatly-telemedicine`
**PHP:** `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17 NTS VS17 x64)
**Framework:** laravel/framework **13.33.0**, laravel/sanctum **4.3.3**, Pest 4
**DB under test:** `telemedisin_db_test` (phpunit.xml), schema 75 tables + 2 views, 78 migrations

---

## 1. Endpoint surface

All eight routes live in `routes/api.php` under `Route::prefix('auth')->name('auth.')`, mounted by
`bootstrap/app.php`'s `apiPrefix: 'api/v1'`, so the full path carries the `/api/v1` segment and
**no prefix is added in the routes file**.

| # | Method | Path | Route name | Middleware | FormRequest |
|---|--------|------|------------|------------|-------------|
| 1 | `POST` | `/api/v1/auth/register` | `auth.register` | `throttle:auth-otp-send` (10/min) | `StoreRegisterRequest` → `RegisterRequest` |
| 2 | `POST` | `/api/v1/auth/login` | `auth.login` | `throttle:auth-login` (5/min) | `LoginRequest` |
| 3 | `POST` | `/api/v1/auth/otp/verify` | `auth.otp.verify` | `throttle:auth-otp-verify` (5/min) | `VerifyOtpRequest` |
| 4 | `POST` | `/api/v1/auth/refresh` | `auth.refresh` | — (anonymous, token-only) | `RefreshTokenRequest` |
| 5 | `POST` | `/api/v1/auth/logout` | `auth.logout` | `auth:sanctum` | `LogoutRequest` |
| 6 | `GET` | `/api/v1/auth/devices` | `auth.devices.index` | `auth:sanctum` | — |
| 7 | `POST` | `/api/v1/auth/devices` | `auth.devices.store` | `auth:sanctum` | `StoreDeviceRequest` |
| 8 | `DELETE` | `/api/v1/auth/devices/{deviceId}` | `auth.devices.destroy` | `auth:sanctum` | — (path param) |

The plan's todo-20 text names `StoreRegisterRequest`; the class shipped is `RegisterRequest`,
because todo 20's own brief asks for "one FormRequest per validating input" and the shorter name is
what the rest of this project's request classes use. Recorded as a deviation.

### Verbatim `php artisan route:list --path=api/v1`

```
 GET|HEAD api/v1/auth/devices .. auth.devices.index > Api\V1\AuthController@devicesIndex
 POST api/v1/auth/devices .. auth.devices.store > Api\V1\AuthController@devicesStore
 DELETE api/v1/auth/devices/{deviceId} .. auth.devices.destroy > Api\V1\AuthController@devicesDestroy
 POST api/v1/auth/login .. auth.login > Api\V1\AuthController@login
 POST api/v1/auth/logout .. auth.logout > Api\V1\AuthController@logout
 POST api/v1/auth/otp/verify .. auth.otp.verify > Api\V1\AuthController@verifyOtp
 POST api/v1/auth/refresh .. auth.refresh > Api\V1\AuthController@refresh
 POST api/v1/auth/register .. auth.register > Api\V1\AuthController@register

 Showing [8] routes

ROUTE_LIST_EXIT=0
```

---

## 2. Permission and `tipe` usage — and why there is none

**No `permission:` and no `tipe:` middleware appears on any of the eight routes.** This is a decision
taken against `app/Support/Rbac/RbacCatalog.php` (read in full before any name was chosen), not an
omission, and it is the same reasoning the catalogue's own docblock anticipated ("It is deliberately
kept in this class rather than in code so that todos 20/21/22/47 can correct it as a *data* change").

`RbacCatalog::PERMISSIONS` holds exactly 24 codes:

```
booking.buat  booking.lihat  booking.batal  jadwal.lihat  konsultasi.mulai  konsultasi.chat
konsultasi.selesai  rekam_medis.lihat  rekam_medis.simpan  rekam_medis.final  surat_keterangan.buat
resep.buat  resep.lihat  resep.verifikasi  obat.cari  pesanan.buat  pesanan.lihat  pembayaran.bayar
promo.validasi  notifikasi.lihat  audit.lihat  pdp.kelola  dokter.lihat  dokter.profil
```

**Not one of them names an auth, session, token, device or notification-registration action.** The
closest names are `notifikasi.lihat` ("view notifications") and `dokter.lihat`. Two candidate guards
were considered and rejected on the catalogue's own terms:

- **`permission:notifikasi.lihat` on `POST /auth/devices`** — wrong twice. It makes a push
  *registration* call a *read* permission, and `RbacCatalog`'s docblock records that `perawat` and
  `kurir` are real `users.tipe` ENUM values (`:139`, seven values) that hold **no role and therefore
  no grant at all**. That gate would make those two account types permanently unable to register a
  device, i.e. permanently unable to receive any notification.
- **`tipe:` on `POST /auth/logout`** — every account type must be able to end its own session. A
  `tipe:` gate on logout is a lock-out waiting for a `users.tipe` value nobody enumerated.

`EnsurePermission` and `EnsureUserType` throw a `LogicException` (**500**) for an unknown code, so
naming a code that does not exist is a build-time mistake, not a 403. Todo 4's brief forbids
"inventing permissions that no module route consumes", and an auth code would be exactly that.

What the authenticated routes use instead is `auth:sanctum` plus **explicit ownership scoping in the
controller**: every `user_devices` query is filtered by `user_id = $request->user()->getAuthIdentifier()`
in SQL, and the cross-user delete answers **404, not 403**, so existence is not leaked. That is the
check a "your own account" endpoint actually needs, and todo 21's `whereBelongsTo` scoping will
follow the same rule.

**Tripwire for todos 21+:** `AuthFlowTest` parses `routes/api.php` and asserts that every
`permission:` / `tipe:` string in it resolves against `RbacCatalog`, *and* asserts the match set is
empty today. The next todo that needs a code cannot introduce an unknown one and get a 500 at
runtime.

### Rate limiter names

`FortifyServiceProvider` already registers a named limiter called **`login`**, whose callback reads
`$request->session()` — a call that does not exist on the stateless `api` group. Registering `login`
again would silently clobber one or the other depending on provider order. All three of this todo's
limiters are therefore named `auth-login`, `auth-otp-verify`, `auth-otp-send`; none can collide with
the framework or with Fortify. The keys are `limiter|lowercased identifier|ip`, so one attacker
cannot lock every account out by spending a shared budget.

---

## 3. Files

### New — `app/Services/Auth/`
| File | Role |
|---|---|
| `OtpSender.php` | interface; the delivery boundary, so a real gateway is a container binding |
| `LogOtpSender.php` | the one shipped implementation; writes to the log, masks the recipient |
| `OtpService.php` | mint, supersede, consume; 6-digit code, SHA-256 only, +5 min |
| `OtpRejected.php` | domain exception with three reasons: unknown / already used / expired |
| `IssuedOtp.php` | carries the plaintext; `plainTextForClient()` is the **only** way out |
| `TokenService.php` | issue, rotate, revoke; Sanctum access token + hashed refresh token |
| `AuthTokenPair.php` | the pair, for exactly one response; `__debugInfo()` redacts both halves |
| `RefreshTokenRejected.php` | three reasons, one client-facing message |

### New — `app/Http/`
| File | Role |
|---|---|
| `Controllers/Api/V1/AuthController.php` | the eight actions |
| `Requests/Auth/AuthRequest.php` | abstract base: the shared "phone **or** email" rules |
| `Requests/Auth/RegisterRequest.php` | 9 fields; `tipe`/`status` are never accepted |
| `Requests/Auth/LoginRequest.php` | identifier + password; no strength rule |
| `Requests/Auth/VerifyOtpRequest.php` | identifier + `kode` + `tujuan` (+ optional `device_id`) |
| `Requests/Auth/RefreshTokenRequest.php` | `refresh_token`, exact length |
| `Requests/Auth/LogoutRequest.php` | subclass of the above — same field, same rule, one source |
| `Requests/Auth/StoreDeviceRequest.php` | `device_id`, `platform`, `fcm_token`, `app_versi` |
| `Resources/UserResource.php` | the `users` projection, allow-listed; never `kata_sandi_hash` |
| `Resources/UserDeviceResource.php` | one `user_devices` row; no surrogate `id`, no `user_id` |
| `Resources/AuthTokenResource.php` | `token_type`, `access_token`, `expires_in`, `refresh_token`, … |

### Modified (2, both named in the commit pathspec)
| File | Change |
|---|---|
| `routes/api.php` | replaced the "registers no routes" stub with the eight Module 1 routes |
| `app/Providers/AppServiceProvider.php` | + `OtpSender` → `LogOtpSender` binding, + 3 named limiters. `configureDefaults()` is byte-for-byte unchanged. |

### New — tests
`tests/Feature/Auth/AuthFlowTest.php` (54 tests) and `tests/Support/FakeOtpSender.php`.

### Untouched, as required
`app/Models/**`, `app/Support/Rbac/**`, `database/**`, `telemedicine_test.sql`
(SHA-256 `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, byte-identical),
`docs/schema-notes.md`, `docs/migration-order.md`. `mobile/` was not created.
`git status --porcelain` shows no entry under any of those paths.

---

## 4. The OTP flow — expiry and single use

`telemedicine_test.sql:179-188` gives `user_otp(id, user_id, kode_hash, tujuan, kedaluwarsa_at,
sudah_dipakai, dibuat_at)` and **no `dibatalkan_at`, no attempt counter, no index and no unique
key at all**.

**Issuing** (`OtpService::issue()`)

1. `random_int(0, 999999)` zero-padded to 6 digits — the CSPRNG, and a code may legitimately start
   with `0`, which is why the verify rule is a regex and not `digits:6` and why the value is never
   cast to an integer. `AuthFlowTest` proves a `000123` code verifies.
2. Inside one transaction: every unused row for `(user_id, tujuan)` gets `kedaluwarsa_at = now()`,
   then the new row is inserted. **Supersession closes the validity window** because the DDL has no
   cancellation column, so a superseded code fails through the *same* expiry branch a timed-out code
   takes — one expiry rule, not two. The bulk update goes through `DB::table()` rather than the
   model because `UserOtp::UPDATED_AT` is `null` and a bulk Eloquent update would have to be *proved*
   not to add a `diubah_at` column; the query builder cannot.
3. `kedaluwarsa_at = now() + 5 minutes` (`OtpService::TTL_MENIT`).
4. Only `hash('sha256', $code)` is stored. `AuthFlowTest` asserts
   `kode_hash !== plaintext` **and** `kode_hash === hash('sha256', plaintext)` **and**
   `strlen(kode_hash) === 64`.
5. The plaintext is handed to `OtpSender` and returned as an `IssuedOtp` whose
   `plainTextForClient()` returns **`null` unless `app()->environment('local')`**. That is the single
   place the rule lives; `phpunit.xml` sets `APP_ENV=testing`, so the suite runs the *refusing*
   branch and a leak could not hide behind a local-only assertion.

**Why SHA-256 and not bcrypt** (recorded because "hash" implies slow KDF): a six-digit code has 10^6
possible values, so a fast KDF buys nothing an attacker does not already have — the whole code space
hashes in milliseconds. The slow-KDF argument is also operationally wrong here: `consume()` compares
the presented code against the candidate rows inside a `SELECT … FOR UPDATE`, and a per-row KDF would
turn one request into a second of CPU. What protects the code is the 5-minute window plus
`throttle:auth-otp-verify` at 5/min, which is the **only** brute-force bound because
`user_otp` has no attempt counter. `kata_sandi_hash` is a different problem and is bcrypt.

**Consuming** (`OtpService::consume()`)

- Candidate rows for `(user_id, tujuan)` are selected `ORDER BY id DESC FOR UPDATE` **inside a
  transaction**, and `sudah_dipakai` is read only after the lock. Without the lock two concurrent
  verifications both read `0` and both mint a token pair — the same race the plan flags for refresh
  rotation, for the same reason.
- The match is by `hash_equals()` over the whole candidate set, **not** over `sudah_dipakai = 0`
  rows. Filtering to unused rows would make a replay indistinguishable from a wrong code; matching
  across all rows keeps the three rejections distinct, which is what lets the client say "this code
  already worked" instead of "that code is wrong".
- Rejection order: no match → `OtpRejected::tidakDiketahui()` (422 `errors.kode` "Kode OTP tidak
  valid."); `sudah_dipakai` → "Kode OTP sudah pernah dipakai."; `kedaluwarsa_at <= now()` → "Kode OTP
  sudah kedaluwarsa. Silakan minta kode baru."; otherwise `sudah_dipakai = 1` under the lock.
- A successful verify then sets `last_login_at`, `telepon_terverifikasi = true`, and
  `status = 'aktif'` **only when the current status is `pending_verifikasi`**. A `nonaktif` or
  `ditangguhkan` account is never resurrected by replaying a code it already holds; a test asserts
  exactly that.
- Unknown identifier → the same 422 with the "not valid" text, not a 404, because "the row does not
  exist" is not the caller's business and a distinct status would enumerate accounts.
- `tujuan` is validated against `OtpService::TUJUAN_DI_TERBITKAN` — `verifikasi_telepon` and
  `login` only. `reset_kata_sandi` and `verifikasi_email` are in the DDL enum (and in
  `OtpService::TUJUAN`, which is asserted byte-identical to `:183`) but are refused here because this
  endpoint's *effect* is "issue a session", which is not a correct outcome for either of them.

---

## 5. Refresh rotation and revocation

`user_refresh_tokens(id, user_id, token_hash, kedaluwarsa_at, dicabut, dibuat_at)` — **no
`device_id`, no index, no unique on `token_hash`.**

- **Rotation** (`TokenService::rotate()`): the row matching `hash('sha256', $presented)` is selected
  `FOR UPDATE` before `dicabut` is read. `dicabut = 1` or `kedaluwarsa_at <= now()` → rejected. Then
  the presented row is marked `dicabut = 1` and a new pair is minted. Access TTL is
  `config('sanctum.expiration')` = 1440 min (read from the same literal `config/sanctum.php` pins,
  with a fallback so a future edit to `null` cannot reopen the never-expire hole); refresh TTL is
  30 days; the secret is `Str::random(80)`, stored only as SHA-256.
- **Reuse detection**: presenting an already-`dicabut` row revokes **every** live refresh token for
  that user, then answers 401 with one fixed message. The revocation is account-wide because
  `user_refresh_tokens` has no `device_id` column, so the server cannot tell which device the
  presented token belonged to and has no narrower correct action. Per-device revocation is recorded
  as **impossible in this schema** in the class docblock.
- All three rejection reasons share **one** client-facing string
  (`RefreshTokenRejected::PESAN`), so the 401 is not an oracle for which condition applied. The reason
  is still carried for the log and the suite. `AuthFlowTest` asserts the "unknown" and "expired"
  bodies are byte-identical.
- **Logout** revokes the presented refresh token, deletes the current Sanctum access token, and sets
  `aktif = 0` on **every** `user_devices` row for the account (the plan's Oracle rule; see §8 for the
  tension in that rule). `revokeCurrentAccessToken()` guards on
  `instanceof Laravel\Sanctum\PersonalAccessToken` — required, not defensive, because under
  `Sanctum::actingAs()` the token is a `TransientToken` with no `delete()`.
- `DELETE /auth/devices/{deviceId}` deactivates one row, scoped by `user_id`, answering **404** for
  another account's device and for an unknown id. Rows are deactivated, never deleted.

### A real bug this todo found in itself, and the fix

The first implementation threw `RefreshTokenRejected` from *inside* `DB::transaction()`. That rolls
back the write the same branch had just made, so the one response that must be *accompanied* by an
account-wide revocation was the one response that silently un-did it: the stolen token stayed live
and the client was told nothing. Caught by the replay test
(`replaying a spent refresh token … revokes every live token for the account` failed with
*"Failed asserting that 1 is identical to 0"* — one row still `dicabut = 0`).

The fix is a shape change, documented in the method docblock: the transaction returns
`['reason' => …]` or `['pair' => …]`, and the exception is raised **outside** it. The row lock is
still taken before `dicabut` is read.

---

## 6. A.26 gates

### (a) Non-ASCII scan

Regex: `[^\x00-\x7F\u2013\u2014\u2022\u2026\u2192\u2212\u00A7\u2225]` (the A.26 replacement, PCRE2 `\x{…}`
form). Run over all **23** authored/modified files:

```
app/Services/Auth/AuthTokenPair.php                      clean
app/Services/Auth/IssuedOtp.php                          clean
app/Services/Auth/LogOtpSender.php                       clean
app/Services/Auth/OtpRejected.php                        clean
app/Services/Auth/OtpSender.php                          clean
app/Services/Auth/OtpService.php                         clean
app/Services/Auth/RefreshTokenRejected.php               clean
app/Services/Auth/TokenService.php                       clean
app/Http/Controllers/Api/V1/AuthController.php           clean
app/Http/Requests/Auth/AuthRequest.php                   clean
app/Http/Requests/Auth/LoginRequest.php                  clean
app/Http/Requests/Auth/LogoutRequest.php                 clean
app/Http/Requests/Auth/RefreshTokenRequest.php           clean
app/Http/Requests/Auth/RegisterRequest.php               clean
app/Http/Requests/Auth/StoreDeviceRequest.php            clean
app/Http/Requests/Auth/VerifyOtpRequest.php              clean
app/Http/Resources/AuthTokenResource.php                 clean
app/Http/Resources/UserDeviceResource.php                clean
app/Http/Resources/UserResource.php                      clean
app/Providers/AppServiceProvider.php                     clean
routes/api.php                                           clean
tests/Feature/Auth/AuthFlowTest.php                      clean
tests/Support/FakeOtpSender.php                          clean

files=23  flagged_codepoints=0  permitted_typographic_codepoints=0
```

**Zero.** Not even the typographic set is used: every authored file is pure ASCII, so the allowance
is not load-bearing and a future non-ASCII edit has to be deliberate.

### (b) Token audit — the only gate that sees corrupted ASCII

Every `snake_case` token was extracted from the 23 files and checked against a **774-token DDL
vocabulary** built with the project's own `SqlSchemaParser` (the same parser
`sehatly:verify-schema` uses): all 75 table names, all 672 column names, all 142 index names, both
view names, and every quoted literal in the reference file (which is how ENUM members and
`COMMENT` values enter). Any token **not** in that vocabulary and within **Levenshtein distance 2**
of a DDL token is flagged — that is the `doker_umum`-for-`dokter_umum` shape.

```
DDL vocabulary: 774 tokens (tables, columns, index names, enum members, quoted literals)

distinct snake_case tokens: 84
  present verbatim in the DDL      : 39
  application vocabulary (reviewed): 9
  NEAR-MISS against a DDL token    : 0

No snake_case token is within edit distance 2 of a DDL token without being one.

=== explicit check for the A.18/A.26 class (`doker_umum` for `dokter_umum`) ===
  doker_umum           occurrences: 0
  doker                occurrences: 0
  doktor               occurrences: 0
  dketter_umum         occurrences: 0
  pasien_agama         occurrences: 0
  user_otp             occurrences: 18
  user_devcies         occurrences: 0
```

The 9 "application vocabulary" tokens are the permission-code segments (`notifikasi_lihat`,
`dokter_lihat`, …), which the DDL documents only as two examples in a `COMMENT`, and the
request-attribute names (`refresh_token_expires_at`, `kode_otp`, …). Each was read individually.

**The audit is not decorative.** It is a live test, not just a script: three assertions in
`AuthFlowTest` re-parse `telemedicine_test.sql` with `SqlSchemaParser` on every run and compare with
`toBe` (which checks **order** as well as membership):

- `user_otp.tujuan` ⇒ `OtpService::TUJUAN` (4 values, DDL order)
- `pasien.jenis_kelamin` ⇒ `RegisterRequest::JENIS_KELAMIN`
- `users.bahasa` ⇒ `RegisterRequest::BAHASA`
- `user_devices.platform` ⇒ `StoreDeviceRequest::PLATFORM`
- `users.tipe` ⇒ `RbacCatalog::USER_TYPES`

---

## 7. Tests

`php artisan test --filter=AuthFlowTest` → **54 tests, 54 passed, 441 assertions, exit 0.**

**Every one of the eight endpoints has a happy path and at least one failure path.**

| Endpoint | Happy | Failures covered |
|---|---|---|
| `register` | row trio + role grant + OTP sent | each of the 3 `NOT NULL` `pasien` columns (data-driven, 3 cases); `jenis_kelamin` out of enum; future birth date; malformed date; duplicate `no_telepon`; duplicate `email`; `tipe`/`status`/`telepon_terverifikasi`/`email_terverifikasi` in the payload ignored; OTP plaintext absent from the body; no token issued; 11th request → **429** |
| `login` | by phone and by email, OTP `login` sent | wrong password → 401; unknown identifier → **byte-identical** 401; `nonaktif` → 403; `ditangguhan` → 403; missing password → 422; neither identifier → 422 on both keys; 6th → **429** |
| `otp/verify` | flips `aktif` + `telepon_terverifikasi` + `last_login_at`, marks used, issues the pair, and **the issued token really authenticates** | wrong code → 422 `errors.kode`; expired code → 422; already-used code → 422 as a *replay*; superseded code → 422 as expired; 5-digit code → 422; `reset_kata_sandi` → 422; `verifikasi_email` → 422; unknown account reported as unknown code; suspended account not resurrected; 6th → **429**; leading-zero code accepted |
| `refresh` | rotates; old row `dicabut = 1`; new pair works | replay of a spent token → 401 **and** every live token for the account revoked; unknown → 401; expired → 401 (bodies byte-identical); wrong length → 422 |
| `logout` | refresh revoked, access token deleted, all 3 devices deactivated, rows not deleted | access token no longer authenticates (401); missing `refresh_token` → 422; unauthenticated → 401 |
| `GET devices` | lists only the caller's 2 of 3; no `id`, no `user_id` published | unauthenticated → 401 |
| `POST devices` | upsert on `uq_device`, `aktif = 1`, `last_active_at` set; re-registration updates in place and reactivates after a logout | `platform` out of enum → 422; `app_versi` 21 chars → 422; unauthenticated → 401 |
| `DELETE devices/{id}` | deactivates, row survives | another account's device → **404 not 403**; unknown id → 404; unauthenticated → 401 |

Plus six contract tests: the route table and its middleware; the `permission:`/`tipe:` tripwire;
the OTP purpose DDL match; the four ENUM DDL matches; `LogOtpSender` masking; and the register →
`pasien` role grant proven against the table (which is what makes todo 21/27's `permission:` routes
reachable at all).

Two decisions worth recording:

- **`Sanctum::actingAs()` is not used anywhere.** It installs a `TransientToken`, which has no
  `delete()`, so logout's access-token revocation could not be observed. Every authenticated request
  carries a real token from `$user->createToken()`.
- **`app('auth')->forgetGuards()` before each authenticated request.** See §9 — this one is a
  genuine trap that produced three false failures before it was understood.

### The delta against the pre-existing red baseline

Baseline, measured before any change:

```
tests=207 passed=196 failed=11 assertions=6137
```

After this todo:

```
tests=261 passed=250 failed=11 assertions=6578
```

| | before | after | delta |
|---|---|---|---|
| tests | 207 | 261 | **+54** |
| passed | 196 | 250 | **+54** |
| failed | **11** | **11** | **0** |
| assertions | 6137 | 6578 | **+441** (exactly the new file) |

Unit suite, unchanged: `tests=126 passed=126 failed=0 assertions=5686 EXIT=0`.

The 11 failures are the **same 11**, byte for byte, and all belong to todo 30
("strip Inertia from the Laravel root"):

```
[01] Feature\Auth\AuthenticationTest ......... the user is not authenticated
[02] Feature\Auth\EmailVerificationTest ..... expected [Illuminate\Auth\Events\Verified] not dispatched
[03] Feature\Auth\PasswordConfirmationTest ... "The provided password was incorrect."
[04] Feature\Auth\PasswordResetTest (x3) .... expected [ResetPassword] notification not sent
[07] Feature\Auth\RegistrationTest .......... the user is not authenticated
[08] Feature\Settings\PasswordUpdateTest .... "The password is incorrect."
[09] Feature\Settings\ProfileUpdateTest ..... 500: Unknown column 'email_verified_at' in 'field list'
[10] Feature\Settings\ProfileUpdateTest ..... Expecting null not to be null
[11] Feature\Settings\ProfileUpdateTest ..... "The password was incorrect."
```

`config/fortify.php` and `app/Http/Controllers/Settings/*` were **not** edited and no failing test
was deleted or skipped. Two assertions here are worth naming because they are the DDL talking: the
`password`-column failures are Fortify reading `users.password`, which does not exist (the column is
`kata_sandi_hash`, `:138`), and `ProfileController.php:39` writes `email_verified_at`, which does not
exist either (`users` has `email_terverifikasi TINYINT(1)`, `:144`).

---

## 8. Findings — plan errors, tensions and stale claims

Reported rather than worked around, per the brief.

**F1 — The acceptance criterion "lists all 6 routes" is under-specified against the todo's own text.**
Todo 20's prose names seven operations (`register`, `login`, `otp/verify`, `refresh`, `logout`,
`POST /auth/devices`, `DELETE /auth/devices/{deviceId}`), and the brief additionally requires device
*listing*. Eight is the arithmetically correct count and eight is what shipped. The criterion's "6"
matches neither. Flagged, not silently satisfied by dropping endpoints.

**F2 — The plan's logout rule and its own justification disagree.**
The plan says logout "sets `user_devices.aktif = 0` for **every** device belonging to that user", and
justifies it as "without this a logged-out device keeps receiving that user's medical push
notifications". The stated reason only requires deactivating *the* device. The rule is broader than
its rationale, and the broad reading has a real cost: signing out on the web silently kills push on
the user's phone until the phone re-registers. It is implemented as written, because
`user_refresh_tokens` has no `device_id` so the server genuinely cannot tell which session is
ending, and because the blast radius is bounded and recoverable (`POST /auth/devices` sets `aktif = 1`
for the row it names; `DELETE /auth/devices/{id}` is the scoped alternative). Recorded in the
controller's docblock so the tension is visible to todo 25 and to the mobile team rather than
discovered by a user.

**F3 — `user_refresh_tokens` has no `device_id`, so per-device token revocation is impossible.**
Recorded in `TokenService`'s docblock. A consequence worth passing on: after
`POST /auth/refresh`, the newly issued access token's `personal_access_tokens.name` resets to `api`
instead of the previous `api:<device_id>`, because there is nothing to read the old name back from.
Same limitation, stated in both places.

**F4 — `user_otp` has no attempt counter, so the 5/min limiter *is* the brute-force bound.**
Stated in `OtpService`, in `AppServiceProvider` and in the route file rather than left implicit.

**F5 — The `RegisterRequest` name.** The plan says `StoreRegisterRequest`; the brief says one
FormRequest per validating input and the project's convention is the shorter name. Shipped as
`RegisterRequest`.

**F6 — `pasien.nomor_rm` is written at registration, which the plan's text does not mention.**
`:221` documents the format in the column's own comment (`RM-YYYYMM-XXXXXX`, `VARCHAR(20) NULL
UNIQUE`). Deriving it from the account's own primary key (`RM-` + `YYYYMM` + 6-digit zero-padded
`users.id`) makes a collision impossible, needs no retry loop, and is 17 characters. Leaving it
`NULL` — which the column permits — would leave every downstream medical record without the patient
identifier the spec's own comment is about. **Flagged as a deliberate addition beyond the plan's
literal text**; the month prefix is read at write time, so it is not reproducible from the id alone
afterwards, which is stated in the method docblock.

**F7 — `register` grants the `pasien` role, which the plan's endpoint prose does not mention.**
`RbacSeeder`'s docblock and `DatabaseSeeder`'s both say explicitly that `user_roles` is written by
nobody in the seeder tree and name **todo 20** as the place that assigns roles to real accounts.
Without the grant a registered patient would authenticate and then be refused by every
`permission:`-gated route in todos 21, 27 and 47. `RoleAssigner::assign()` resolves role names against
the `roles` table and throws a `LogicException` naming `db:seed --class=RbacSeeder` if the RBAC kernel
has not been seeded, so a deployment that skipped seeding gets a loud 500 on register rather than an
account that silently holds nothing. That is the intended direction of failure and is noted in the
controller.

**F8 — `AuthRequest` accepts "phone **or** email" as two named fields, not one `identifier`.**
`email` is `VARCHAR(255) NULL UNIQUE` and `no_telepon` is `VARCHAR(20) NOT NULL UNIQUE`
(`:136-137`), so one untyped field could not use either index and a phone number containing `@`
would be ambiguous. Two fields keep both lookups indexed. Not a plan error, but a design choice the
mobile client has to know about.

**F9 — `GET /auth/devices` returns `data.total` rather than the project-wide
`meta: {current_page, last_page, total}` block.** `App\Support\ApiResponse` (todo 3's file, not this
todo's) has no `meta` key. A user has a handful of devices, so there is nothing to page. **Todo 21 is
where the `meta` block is introduced and it will have to widen `ApiResponse` to do it** — recorded in
the controller so it is not rediscovered.

**F10 — `UserResource` will need extending, not replacing, by todo 21.** The plan's todo 21 asks for
`App\Http\Resources\{UserResource, PasienResource, …}` with `pasien` and `dokter` eager-loaded and
`pasien.nik` masked. This todo ships `UserResource` as a `users`-only allow-list with no relations
loaded, so todo 21 extends it and there is exactly one `UserResource` in the tree.

---

## 9. Two things this todo got wrong before it got them right

Recorded because the brief asks for evidence rather than a green tick, and because both were
mistakes in the *tests*, not in the code, and both looked exactly like missing authorisation.

**(a) The refresh-reuse revocation was rolled back by its own exception.** Described in §5. A real
defect in shipped code, found by a test, fixed in the shipped code, and the shape of the fix is
documented so it is not reintroduced.

**(b) `Illuminate\Auth\RequestGuard` caches the authenticated principal across requests inside one
test, and `setRequest()` does not clear it.**
`vendor/laravel/framework/src/Illuminate/Auth/RequestGuard.php:80`:

```php
public function setRequest(Request $request)
{
    $this->request = $request;
    return $this;
}
```

`user()` returns `$this->user` whenever it is non-null, and nothing in `setRequest()` resets it.
In production every request gets a fresh container and therefore a fresh guard, so the cache cannot
outlive a request. Inside a single Pest test the application, the `AuthManager` and the guard
instance are shared by every request, so **the first authenticated request in a test decides the
principal for all the rest** — and `withToken()` updating the `Authorization` header changes nothing,
because the guard never re-resolves.

This produced three failures that each read as a missing `user_id` scope in the controller:

- `the_device_list_contains_only_the_caller_devices` — *"3 is identical to 2"*: a device created
  "as" user B landed under user A's `user_id`.
- `revoking_a_device_…_refuses_to_touch_another_account_device` — cross-user `DELETE` returned
  **200** instead of 404.
- `logout_revokes_…` and `the_access_token_stops_working_after_logout` — the *wrong* access-token row
  was deleted, and the revoked token still authenticated.

A throwaway diagnostic test (since deleted) proved it rather than arguing it: with three tokens
minted in one test, the logout deleted token id 2 while presenting token id 5, and
`user_devices.user_id` read `1,1` after two "different users" registered. The fix is
`app('auth')->forgetGuards()` inside the `authAsUser()` / `authAsToken()` / `authAsAnonymous()`
helpers, with the reason written down in the helper's docblock so the next test author inherits the
explanation and not just the incantation.

---

## 10. Other verification

**`php artisan vendor/bin/pint --test`** → `{"tool":"pint","result":"passed"}`, exit 0. (The first run
reported four fixers on two of this todo's own test files — `fully_qualified_strict_types`,
`unary_operator_spaces`, `not_operator_with_successor_space`, `ordered_imports` — applied with
`vendor/bin/pint` and re-verified; the 54 tests still pass.)

**`php artisan sehatly:verify-schema`** → exit **0**:

```
 live database mysql / telemedisin_db
 scope all expected tables

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3

 Live schema
 counts tables=82 views=2 columns=715 indexes=237 foreign_keys=105 checks=3

 Discrepancies: 7 (0 drift, 7 informational)
 documented_extra_table cache / cache_locks / failed_jobs / job_batches / jobs / migrations / personal_access_tokens

 PASS — 75 tables, 2 views verified. Nothing was written.

VERIFY_EXIT=0
```

`0 drift, 7 informational` — the seven registered extra tables, informational **by design**. The
number was not chased to 0, per A.25.

**`telemedicine_test.sql`** SHA-256 `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`
— byte-identical to the value in the brief.

**`mobile/`** does not exist. No `pubspec.yaml`, no Dart, no Flutter.

**`config/fortify.php`, `app/Http/Controllers/Settings/*`, `app/Models/**`, `app/Support/Rbac/**`,
`database/**`, `docs/schema-notes.md`, `docs/migration-order.md`** — no `git status` entry.

**`migrate:fresh`** was not run concurrently with the suite. The suite was run as one process, so
`RbacMigrateFreshSeedTest`'s real `migrate:fresh --seed` could not race itself. `migrate:rollback` was
not run at any point.
