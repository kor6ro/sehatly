# Task 24 -- Sehatly pure-Dart API client (Module 1)

Branch `feat/sehatly-telemedicine`. Package `packages/sehatly_api_client`.
Follow-up pass closing three disclosed gaps: the missing evidence file, a
token-burn bug in the auth interceptor, and an exhaustive token audit.

---

## 1. Toolchain: Dart is NOT on PATH

`dart` is not on `PATH` on this machine. The SDK is installed via WinGet and
must be invoked by absolute path:

```
C:\Users\axioo\AppData\Local\Microsoft\WinGet\Packages\Google.DartSDK_Microsoft.Winget.Source_8wekyb3d8bbwe\dart-sdk\bin\dart.exe
```

Verified version, verbatim:

```
Dart SDK version: 3.13.2 (stable) (Tue Aug 25 01:01:12 2026 -0700) on "windows_x64"
```

Every command below is run with `workdir` set to
`packages\sehatly_api_client`. `pubspec.yaml` declares
`environment: sdk: '>=3.13.0 <4.0.0'` and there is deliberately **no**
`flutter:` key, so the package resolves, analyzes, tests and runs its tooling
with this bare binary.

There is no `mobile/` directory in the repository, no Flutter dependency, and
no `import 'package:flutter/...'` anywhere in `lib/`. A later todo asserts
`! -e mobile`; that assertion holds.

---

## 2. Resolved dependency set

`pubspec.yaml` declares exactly one runtime dependency and two dev
dependencies. The versions below are the **resolved** ones from
`pubspec.lock`, not the constraints.

| package | constraint | resolved | role |
| --- | --- | --- | --- |
| `dio` | `^5.11.1` | `5.11.1` | HTTP, interceptors, adapter seam |
| `lints` | `^6.1.0` | `6.1.0` | dev, lint ruleset |
| `test` | `^1.26.2` | `1.32.0` | dev, test runner |

`dio` transitively pulls `dio_web_adapter 2.2.2`, `http_parser 4.1.2`,
`meta 1.19.0` and `collection 1.19.1`. The test runner adds `test_api 0.7.14`,
`test_core 0.6.20`, `coverage 1.15.1`, `shelf 1.4.2`, `vm_service 15.3.0`,
`webkit_inspection_protocol 1.2.1` and `yaml 3.1.4`. `pubspec.lock` is tracked
on purpose: this package is consumed from the same repository, not from
pub.dev, so a reproducible resolution is worth more than a loose one.

`pubspec.lock` lists 47 packages in total. No Flutter SDK appears in the graph.

---

## 3. Public API surface

One import gives a mobile app every Module 1 endpoint:

```dart
import 'package:sehatly_api_client/sehatly_api_client.dart';
```

`lib/sehatly_api_client.dart` is a barrel of 19 `export` directives and no
logic. What it publishes:

- `SehatlyApiClient` -- the entry point, with `.secure(...)` and `.plain(...)`
  factories and the `auth` / `me` / `pasien` / `dokter` endpoint groups.
- `AuthApi`, `MeApi`, `PasienApi`, `DokterApi` -- the 22 Module 1 endpoints,
  with every path a named constant in `lib/src/api/paths.dart` rather than a
  string at a call site.
- `ApiException` (aliased `ApiError`) -- the only exception type that escapes,
  with `isValidationError`, `isUnauthorized`, `isForbidden`, `isNotFound`,
  `isTooManyRequests`, `isServerError`, `isNetworkError`, `isSlotTaken`,
  `isConsentRequired`.
- `ApiEnvelope`, `ApiMeta`, `Paginated` -- the response envelope and the
  pagination block.
- `jsonMap`, `jsonList`, `jsonInt`, `jsonString`, `jsonBool`, `jsonDateTime` --
  the total JSON accessor set, exported so a caller reading
  `ApiEnvelope.raw` parses a `data` member the same way this package does.
- `TokenStore`, `RefreshTokenStore`, `SecureTokenStore`, `PlainTokenStore`,
  `TokenStorage` -- the storage split.
- `RefreshCoordinator`, `AuthInterceptor` -- single-flight rotation and the
  bearer header.
- `SecureKeyValueBackend`, `PlainKeyValueBackend` -- the two backend
  interfaces a host app implements.
- The DTOs (`User`, `PasienProfile`, `UserDevice`, `AnggotaKeluarga`,
  `PasienAlergi`, `DokterListing`, `DokterDetail`, `DokterAccount`,
  `MasterSpesialisasi`, `OtpChallenge`, `TokenPair`, `DeletedRow`, and the
  nested `DokterSpesialisasi` / `DokterPendidikan` / `DokterFaskes` /
  `DokterAkunSpesialisasi`) and the DDL `ENUM` vocabularies in
  `lib/src/model/enums.dart`, transcribed from `telemedicine_test.sql` with the
  source line named on every constant.
- `FakeApiClient` in `lib/src/fake/fake_api.dart` -- an in-package
  `HttpClientAdapter` stand-in so a consuming app can build UI without a server.

The endpoint coverage is exactly the 22 routes `php artisan route:list
--path=api/v1` reports, and the package is Flutter-free by construction rather
than by convention: the concrete `flutter_secure_storage` adapter is about
twenty lines living in the **consuming app**, behind
`SecureKeyValueBackend`.

---

## 4. How single-flight refresh works, and how the test proves it

### The invariant

When N in-flight requests each receive a 401, **exactly one**
`POST /auth/refresh` reaches the network and all N await that one call.

This is a correctness requirement, not a performance one. The server's
contract is that a refresh token is **revoked on every use** and that a
*replayed* one revokes **every live refresh token for the account**. So a screen
that fires six parallel reads on resume and issues six refreshes would have
calls two through six each read as theft, and collectively sign the user out of
every device.

### Two guards, both load-bearing

**Guard 1, the in-flight future** (`RefreshCoordinator.refreshInFlight`). The
first caller installs a `Completer`'s future *synchronously*, before its first
`await`, so every later caller in the same event-loop turn sees a non-null value
and awaits the same future.

**Guard 2, the rotated-away token** (`RefreshCoordinator._rotatedAwayAccessToken`).
`AuthInterceptor` extends `QueuedInterceptor`, so its `onError` callbacks run one
at a time. By the time the second 401 is handled, guard 1's refresh has already
completed and cleared the in-flight future -- so guard 1 alone would fire a
second rotation. Guard 2 remembers which access token was current immediately
before the last rotation and answers a 401 presenting that exact token from the
rotation that already happened, with no network call at all.

Neither guard subsumes the other: guard 1 covers simultaneity, guard 2 covers
serialisation. Removing guard 2 is safe under a plain `Interceptor` and fatal
under a `QueuedInterceptor`.

The rotation itself goes out on a **separate, interceptor-free** `Dio`. That is
the structural reason a refresh loop cannot be built: if the refresh used the
client's own `Dio`, the interceptor would attach the stale `Authorization`
header and a 401 from `/auth/refresh` would re-enter the interceptor and
recurse.

A refresh failure is **terminal**. `TokenService::rotate()` revokes the
presented token on every use, so a 401 there means the token was already spent
and the server has already revoked the chain. Both stores are cleared,
`onSessionExpired` fires exactly once (latched), and the error propagates.

### The proof

`test/refresh_single_flight_test.dart`, first test in the
`single-flight refresh` group:

> `'5 concurrent 401s produce exactly ONE refresh call and 5 replays'`

Mechanics, which matter because the test would otherwise be asserting on
scheduling luck:

- The count lives in **`ScriptedAdapter`**, at `HttpClientAdapter.fetch`, not
  inside `RefreshCoordinator`. The claim is about requests that *reached the
  network*; a counter inside the coordinator counts its own decisions, which is
  one step earlier and would still read `1` if the coordinator decided once and
  then dispatched twice.
- The refresh handler `await`s a 20 ms delay, so all five 401s are handled while
  the refresh is genuinely in flight. "Concurrent" is a measured property of the
  run, not an assumption about event-loop interleaving.
- The first five `/me` calls answer 401; the sixth and later answer 200. The
  assertion is `refreshCalls == 1` **and** the five replays all succeed with the
  rotated token, which additionally proves the replay path presents
  `Bearer fresh-access` rather than the stale credential.

Two more tests in that file pin the guards individually: the in-flight future
under genuine simultaneity, and the rotated-away token under the serialisation
that `QueuedInterceptor` creates. A third asserts
`isA<QueuedInterceptor>()` on the registered interceptor, with the message
"the plan mandates a QueuedInterceptor" -- **left untouched by this pass**.

### The replay-deadlock fix, preserved

The previous executor found a re-entrancy deadlock and it is still in place,
deliberately:

`QueuedInterceptor` runs one `onError` at a time per instance. While this
`onError` is running, its instance's error queue is busy, so a second error
arriving on that same instance is appended to the queue and **never started**.
A replay dispatched on the same `Dio` that then received a 401 would queue its
error behind the very task awaiting it; neither would run, and the request would
hang until the caller's own timeout with no exception and no log line.

So the replay goes out on a **third, separate `Dio`** carrying an equivalent
`AuthInterceptor` instance that shares the same `RefreshCoordinator` and the same
storage. Its error queue is not the occupied one, so the replay's 401 is handled
immediately and is passed through by the `retriedKey` guard. That instance is
terminal by construction -- every request dispatched to it is a replay and every
replay carries `retriedKey` -- and it is given no replay `Dio` of its own, which
makes a fourth hop impossible rather than merely unlikely.

**This pass did not collapse the three-`Dio` arrangement, did not replace
`QueuedInterceptor` with a plain `Interceptor`, and did not touch either
mechanism.** The interceptor change in section 7 adds a guard *before* the
retry logic and is orthogonal to all of it.

---

## 5. The storage split, and how the implementation is selected

Two separate factories, not one with a boolean:

| constructor | parameter type | requires |
| --- | --- | --- |
| `SehatlyApiClient.secure(...)` | `SecureKeyValueBackend` | nothing extra |
| `SehatlyApiClient.plain(...)` | `PlainKeyValueBackend` | `allowInsecureStorage: true` |

Selection is by **parameter type**, so a plain in-memory map cannot be passed to
`secure()` without a compile error -- the mistake is caught by the analyzer
rather than by a runtime flag. `.secure()` is the constructor a shipping mobile
app uses. `.plain()` is for desktop development, CLI scripts and unit tests, and
carries an additional explicit opt-in (`allowInsecureStorage: true`) whose
absence is a compile error too. Two names rather than one, so the choice is
visible at the call site and greppable.

`TokenStorage` is the shared type both produce, and it splits the two tokens
into `accessTokens` and `refreshTokens` handles (`SecureTokenStore` /
`RefreshTokenStore`) so a caller can rotate the pair without being able to
address one half arbitrarily.

The concrete `flutter_secure_storage` adapter is **not** in this package. It is
the consuming app's twenty lines behind `SecureKeyValueBackend`, which keeps the
package Flutter-free and therefore verifiable on a CI runner with no Flutter
installed. The OWASP MASVS-STORAGE-1 reasoning for the split is documented on
`TokenStorage.plain` and in the library docblock.

`test/storage_test.dart` covers the file-backed backend, including that a
corrupt file reads as absent rather than as a crash and that `deleteAll` empties
the file so every subsequent read is absent.

---

## 6. The three commits

`b38d942`, `792cb16`, `3546282` -- in that order on `feat/sehatly-telemedicine`.

### `b38d942` feat(api-client): add pure-Dart SehatlyApiClient (Module 1)

35 files changed, 9736 insertions. The package itself: 20 library files, 7 test
files, `pubspec.yaml` and `pubspec.lock`.

**Why it was wrong.** It swept in the generated `.dart_tool` directory. That is
machine-specific build output and does not belong in version control:

- `.dart_tool/package_config.json` (314 lines) records **absolute local paths**
  to the SDK and to every package in the resolution, rooted at
  `C:\Users\axioo\...`. On any other checkout it points at a directory that does
  not exist.
- `.dart_tool/package_graph.json` (472 lines) is the same class of problem.
- `package_config.json` is regenerated by `dart pub get` and is *supposed* to be
  per-checkout. Committing one is worse than committing nothing: it looks like
  a lock file, so a reader trusts it.
- `.dart_tool/pub/bin/test/test.dart-3.13.2.snapshot` -- **30,519,792 bytes**,
  roughly 30 MB -- is a compiled test-runner snapshot **pinned to SDK 3.13.2
  exactly**. It is not forward compatible, not backward compatible, and not
  portable across platforms.
- `.dart_tool/test/incremental_kernel.Ly9AZGFydD0zLjEz` -- 5,676,928 bytes --
  is an incremental-compilation kernel, also SDK-build-specific, and its name
  embeds a content hash so it churns on every run.

Net effect: a ~36 MB commit of unusable binaries plus two files of absolute
paths that would mislead anyone reading the repository on another machine.

### `792cb16` chore(api-client): stop tracking generated .dart_tool artifacts

1 file changed, 19 insertions: `packages/sehatly_api_client/.gitignore`. Its own
message states the reasoning -- `dart_tool` holds machine-specific output,
`package_config.json` records absolute local paths, the compiled snapshot and
`incremental_kernel.*` are tied to the exact SDK build -- and that
`pubspec.lock` stays tracked because the package is consumed from this
repository rather than from pub.dev.

**Why it was insufficient.** A `.gitignore` only governs *untracked* paths. The
files were already tracked, so the new rules matched nothing and all four
remained in the index. The commit changed no tracked content at all.

### `3546282` chore(api-client): untrack generated .dart_tool artifacts

4 files changed, 786 deletions: the four files removed from the index and the
working tree. This is the commit that actually fixed it.

The lesson worth recording, because it is the same trap twice: **a
`.gitignore` is not a deletion.** Untracking requires `git rm --cached`.

---

## 7. Gap (a) -- the missing evidence file

This file. It is gap (a).

## 8. Gap (b) -- the token-burn bug in the auth interceptor

### The bug, confirmed by reading the code

`AuthInterceptor.onError` read `retriedKey` and `allowUnsafeRetryKey` but never
read `noAuthKey`. `onRequest` did read it, so a request marked anonymous left
the interceptor with **no `Authorization` header**. Its 401 is therefore the
server refusing an unidentified caller -- a guard regression on a public route,
or a policy change -- and **no rotation can repair it**, because there is no
credential to repair it *with*.

The pre-fix gate sequence in `onError` was: not 401 -> pass; `retriedKey` ->
pass; not a safe method and not `allowUnsafeRetryKey` -> pass; **otherwise
refresh**.

### Why "otherwise refresh" costs a one-shot token

`POST /auth/refresh` **revokes the presented refresh token on every use**, and
the server treats a *replayed* one as theft and revokes **every live refresh
token for the account**. So the failing path was:

1. a 401 off a route the caller never authenticated to spends a refresh token;
2. the rotation succeeds, so the client looks fine;
3. but a concurrent request, or the caller's own next refresh, now presents a
   token the server has already spent;
4. the server reads that as theft and signs the account out of every device.

A public-route 401 escalated into an account-wide sign-out. The honest response
to a guard regression is the 401.

### The two concrete instances

**`GET /api/v1/dokter`, `GET /api/v1/dokter/{dokter}`,
`GET /api/v1/master-spesialisasi`.** These are `anonymous: true` **GETs**, so
they cleared the `safeMethods` gate outright -- they drove a real rotation on any
401. `DokterApi.index`, `.show` and `.spesialisasi` are exactly the three
endpoints that reach the public directory with no session.

**`POST /api/v1/auth/login` -- the severe one, and the one a user hits by
accident.** `AuthApi.login` sends `anonymous: true, allowUnsafeRetry: true`. A
**wrong password is a 401** (`AuthController` returns
`'Nomor telepon, email, atau kata sandi salah.'` with
`Response::HTTP_UNAUTHORIZED`). So a mistyped password spent a refresh token. The
same applies to `register`, `verifyOtp`, `refresh`, and to `AuthApi.refresh`
itself, where a 401 from the rotation endpoint previously re-entered the
interceptor and attempted a nested rotation.

### The fix

`packages/sehatly_api_client/lib/src/auth/auth_interceptor.dart`, added to
`onError` immediately after the status check and before `retriedKey`:

```dart
    // Explicitly anonymous. This is a 401 for a request that carried no
    // credential, so no rotation can repair it, and attempting one is not a
    // no-op: `POST /auth/refresh` revokes the presented refresh token on every
    // use, so spending one here burns a one-shot token to fix nothing. If the
    // token was already spent, or a concurrent request spent it first, the
    // server reads the replay as theft and revokes *every* live refresh token for
    // the account -- turning a public-route 401 into a sign-out the caller never
    // asked for. A 401 on `/dokter` or `/master-spesialisasi` is a server-side
    // guard regression, and the honest response to a regression is the 401.
    //
    // Checked before the method gate and before [retriedKey] because it is the
    // strongest of the three statements: there is nothing here to refresh *with*.
    // The flag rides along on a replay, because `_replayOptionsFor` copies
    // `extra` across, so an anonymous request can never re-enter this path.
    if (options.extra[noAuthKey] == true) {
      handler.next(err);
      return;
    }
```

The marker is the **existing** `AuthInterceptor.noAuthKey`
(`'sehatly_no_auth'`), set in `RequestOptions.extra` by
`ApiTransport._options(anonymous: anonymous)`, which is what `AuthApi` and
`DokterApi` pass. No new option was introduced; the same key that already
suppressed the header now also suppresses the refresh, so the two decisions
cannot disagree.

Ordering is deliberate: the anonymous check comes **first** because it is the
strongest of the three statements. The method gate and the retry terminator
remain in place and still govern every non-anonymous request.

The class docblock and the `noAuthKey` doc comment were updated to record the
second read, so the key's contract is stated in one place.

### The tests

`test/interceptor_test.dart`, new group `the anonymous-401 refresh gate`, three
tests, **all newly added -- no existing test was modified, weakened, skipped or
deleted**:

1. **`'a 401 from an anonymous GET spends no refresh token'`** -- `GET /dokter`
   answers 401. Asserts `countOf('/auth/refresh') == 0`, `countOf('/dokter') == 1`
   (no replay either), that the stored refresh token is still `refresh-1` and the
   stored access token still `access-1` -- *untouched, not merely unused* -- and
   that `sessionExpired` never fired, because a public-route 401 is not the end
   of the session.
2. **`'a 401 from an anonymous POST spends no refresh token'`** -- the
   wrong-password login. Same four assertions. This is the case a user reaches
   by mistyping.
3. **`'an authenticated 401 on the same path still refreshes'`** -- the control.
   `GET /me` 401s once, then succeeds, and must still rotate exactly once and
   replay once. Without this, tests 1 and 2 would also pass against a build that
   suppressed *every* refresh, which is not a fix but the opposite of one: it
   would turn an expired access token into a hard sign-out on every screen.

### Proof the tests are not vacuous

The gate was temporarily removed from `onError` and the suite re-run. Both new
failure tests failed, and the control still passed:

```
00:02 +7 -1: the anonymous-401 refresh gate a 401 from an anonymous GET spends no refresh token [E]
  Expected: <0>
    Actual: <1>
00:02 +7 -2: the anonymous-401 refresh gate a 401 from an anonymous POST spends no refresh token [E]
  Expected: <0>
    Actual: <1>
00:02 +7 -2: the anonymous-401 refresh gate an authenticated 401 on the same path still refreshes
00:02 +11 -2: Some tests failed.
```

`Actual: <1>` is a refresh token **actually spent**. The gate was then restored
and the suite returned to green. So the tests fail without the fix and pass
with it.

---

## 9. Gap (c) -- the exhaustive token audit

The previous audit was a spot check of auth, allergy, dokter and pagination
fields. This one is exhaustive and **machine-checked and repeatable**.

### How to run it

```
cd packages\sehatly_api_client
dart run tool/audit_wire_fields.dart              # the gate
dart run tool/audit_wire_fields.dart --verbose    # plus every parsed field set
```

Exit 0 on a clean contract, exit 1 on drift. It is a gate, not a report.

### What it does

- **Server side.** A character-level lexer over `app/Http/Resources/*.php` and
  the `data` literal of every `ApiResponse::success(...)` call in
  `app/Http/Controllers/Api/V1/*.php`. It skips comments and strings and tracks
  bracket depth, because a regex cannot tell a key from a key inside a nested
  array, a docblock or a string -- and all of those occur here. Resources are
  scoped **per method**, so `DokterDetailResource`'s top level stays separable
  from its `spesialisasi`, `pendidikan` and `faskes` private helpers.
- **Client side.** Every `['key']` string index each DTO reads, chunked on lines
  starting in column zero so a top-level helper function is not swallowed by the
  preceding class. Nested reads are emitted dotted:
  `jsonMap(json['refresh_token'])['dicabut']` is `refresh_token.dicabut`.
- **Wrapper keys.** The `data` envelope key per endpoint, taken from
  `envelope.dataMap['k']` and `Paginated.fromEnvelope(key: 'k')` at the call
  site, or from the result DTO when the method hands the whole `data` object to
  it (`register`, `login`, `otp/verify`, `logout`, both deletes).
- **Failure classes.** A **client-only** field -- the client reads a key the
  server never sends -- always fails; the value is silently absent at runtime
  and the DTO reports a zero-valued field. A **server-only** field fails unless a
  written justification exists in `_dropJustifications`, which forces every
  intentional drop to be recorded and keeps a later addition to the client from
  making the entry stale.
- **Self-audit.** It also checks every token-pair object literal in `test/`
  against `AuthTokenResource`, which is the audit applied to the code written
  while closing these gaps.

### Why a lexer, and the three bugs it took

Each of these produced a *plausible wrong answer* rather than an error, and each
was found only by printing what the scanner had extracted:

1. `'id' =>` has a **space** before the arrow. Testing `source[i + 1] == '='`
   never matches, so every resource parsed as having zero fields and the audit
   reported 113 client-only fields -- the exact inverse of the truth.
2. Clearing the in-string flag on the closing quote is **mandatory**. Leaving it
   set makes the next quote in the file read as a *closing* one, so the scanner
   desynchronises and recovers a single field per file by accident. AuthTokenResource
   "parsed" as exactly one field.
3. **Method scoping matters.** `DokterDetailResource` publishes four shapes.
   Merging them per file makes a nested object's fields indistinguishable from
   the top level's. Separately, chunking the Dart side on `^class` alone made
   `DeletedRow` -- the last class in `dto.dart` -- swallow the trailing
   `_akunSpesialisasi` function, so a correct two-field DTO reported five
   client-only fields.

A fourth was a spec error, and the tool now refuses to run rather than report
around it: four of the original client method names were wrong
(`showProfile` vs `profil`, `listAlergi` vs `alergi`, and two more), which made
those four endpoints compare against nothing. `_compare` now throws
`StateError` naming the method it could not find.

### The result

**326 field comparisons across all 22 Module 1 endpoints: 326 matched, 0
client-only, 0 server-only.**

The audit covers 22 wrapper comparisons and 30 DTO-to-resource comparisons:

| # | endpoint | `data` keys (client reads = server writes) | DTO / resource shape | matched | client-only | server-only |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `POST /auth/register` | `otp, user` | `User` / `UserResource` | 15 | 0 | 0 |
| 2 | | | `OtpChallenge` / inline `otp` map | 4 | 0 | 0 |
| 3 | `POST /auth/login` | `otp` | `OtpChallenge` / inline `otp` map | 4 | 0 | 0 |
| 4 | `POST /auth/otp/verify` | `token, user` | `User` / `UserResource` | 15 | 0 | 0 |
| 5 | | | `TokenPair` / `AuthTokenResource` | 6 | 0 | 0 |
| 6 | `POST /auth/refresh` | `token` | `TokenPair` / `AuthTokenResource` | 6 | 0 | 0 |
| 7 | `POST /auth/logout` | `access_token, perangkat, refresh_token` | `LogoutResult` / inline ack, dotted | 3 | 0 | 0 |
| 8 | `GET /auth/devices` | `devices` | `UserDevice` / `UserDeviceResource` | 7 | 0 | 0 |
| 9 | `POST /auth/devices` | `device` | `UserDevice` / `UserDeviceResource` | 7 | 0 | 0 |
| 10 | `DELETE /auth/devices/{deviceId}` | `device` | `UserDevice` / `UserDeviceResource` | 7 | 0 | 0 |
| 11 | `GET /me` | `user` | `User` / `UserResource` | 15 | 0 | 0 |
| 12 | | | `PasienProfile` / `PasienResource` | 28 | 0 | 0 |
| 13 | | | `DokterAccount` / `DokterAkunResource` | 18 | 0 | 0 |
| 14 | | | `DokterAkunSpesialisasi` / `DokterAkunResource.spesialisasi` | 5 | 0 | 0 |
| 15 | `GET /pasien/profil` | `profile` | `PasienProfile` / `PasienResource` | 28 | 0 | 0 |
| 16 | `PUT /pasien/profil` | `profile` | `PasienProfile` / `PasienResource` | 28 | 0 | 0 |
| 17 | `GET /pasien/anggota-keluarga` | `anggota_keluarga` | `AnggotaKeluarga` / `PasienAnggotaKeluargaResource` | 10 | 0 | 0 |
| 18 | `POST /pasien/anggota-keluarga` | `anggota_keluarga` | `AnggotaKeluarga` / `PasienAnggotaKeluargaResource` | 10 | 0 | 0 |
| 19 | `PUT /pasien/anggota-keluarga/{id}` | `anggota_keluarga` | `AnggotaKeluarga` / `PasienAnggotaKeluargaResource` | 10 | 0 | 0 |
| 20 | `DELETE /pasien/anggota-keluarga/{id}` | `deleted, id` | `DeletedRow` / inline ack | 2 | 0 | 0 |
| 21 | `GET /pasien/alergi` | `alergi` | `PasienAlergi` / `PasienAlergiResource` | 7 | 0 | 0 |
| 22 | `POST /pasien/alergi` | `alergi` | `PasienAlergi` / `PasienAlergiResource` | 7 | 0 | 0 |
| 23 | `PUT /pasien/alergi/{id}` | `alergi` | `PasienAlergi` / `PasienAlergiResource` | 7 | 0 | 0 |
| 24 | `DELETE /pasien/alergi/{id}` | `deleted, id` | `DeletedRow` / inline ack | 2 | 0 | 0 |
| 25 | `GET /dokter` | `dokter` | `DokterListing` / `DokterResource` | 8 | 0 | 0 |
| 26 | `GET /dokter/{dokter}` | `dokter` | `DokterDetail` / `DokterDetailResource` | 18 | 0 | 0 |
| 27 | | | `DokterSpesialisasi` / `.spesialisasi` helper | 5 | 0 | 0 |
| 28 | | | `DokterPendidikan` / inline `pendidikan` | 4 | 0 | 0 |
| 29 | | | `DokterFaskes` / `.faskes` helper | 8 | 0 | 0 |
| 30 | `GET /master-spesialisasi` | `spesialisasi` | `MasterSpesialisasi` / `MasterSpesialisasiResource` | 4 | 0 | 0 |

**Findings.**

- **Zero client-only fields.** Every key the client reads exists server-side, on
  all 22 endpoints. This is the result that matters: a client-only field is a
  value that is silently `null` at runtime and surfaces as a zero-valued field
  in the UI.
- **Zero server-only fields.** The client models every field the server
  publishes. `_dropJustifications` is therefore **empty**, and the gate fails if
  any server field is ever left unmodelled without a written reason.
- **Test fixtures:** the six token keys used in this package's tests are exactly
  `AuthTokenResource`'s six -- `token_type`, `access_token`, `expires_in`,
  `access_token_expires_at`, `refresh_token`, `refresh_token_expires_at` -- with
  0 fixture-only keys and 0 uncovered server keys.

**A field name that differs between the two sides, verified as correct rather
than assumed:** `UserDeviceResource` does not publish the surrogate `id` or
`user_id`; the client does not read them either. The audit passes on 7 of 7
because both sides omit them. Same for `DokterResource`, where
`jumlah_ulasan` and `durasi_default_menit` are absent from the list projection
and are modelled on `DokterDetailResource` instead -- 8 of 8 on the list, 18 of
18 on the detail.

### The gate is not vacuous

A `phantom_field` read was temporarily injected into `MasterSpesialisasi`:

```
TOTALS  matched=326  client-only=1  server-only=0
   !! client-only: phantom_field
client-only units: 1
AUDIT FAIL          (exit 1)
```

and after `git checkout` of the file:

```
TOTALS  matched=326  client-only=0  server-only=0
AUDIT PASS          (exit 0)
```

The `UNJUSTIFIED` server-only path was likewise exercised for real: intermediate
runs against the unmodified sources reported four units as `(UNJUSTIFIED)`
(`RegisterResult` and `VerifyOtpResult` against `UserResource`, and
`DokterDetailResource`'s nested shapes) before the spec pairing was corrected.

---

## 10. `meta` placement, and the `data`-embedded fallback

**`meta` is a top-level sibling of `data` on every list endpoint.** Verified
against the source, not assumed: `DokterController::index()` passes
`ApiResponse::pageMeta($paginator)` as the **fourth** argument to
`ApiResponse::success()`, and `ApiResponse::success()` appends `'meta'` to the
payload array only when non-null. The key order is fixed and load-bearing:
`success -> data -> message -> meta`. A response with no `meta` has **no `meta`
key at all**, not `"meta": null`.

The list endpoints that answer it: `GET /dokter` (`pageMeta`),
`GET /pasien/alergi` and `GET /pasien/anggota-keluarga` (`pageMeta`), and
`GET /auth/devices` plus `GET /master-spesialisasi`
(`ApiResponse::singlePageMeta($total)`, the degenerate `current_page = 1`,
`last_page = 1` block for a deliberately unpaginated list).

**The earlier brief of mine was wrong** that `/dokter` carried its pagination
inside `data`. It does not; the same migration is recorded for
`GET /auth/devices` in `AuthController::devicesIndex()`'s own docblock, which
used to answer `data: {devices: [...], total: N}`.

**Can the `data`-embedded fallback be removed? It must stay, for now.** The
reasoning is in `Paginated`'s class docblock and I agree with it: a client that
cannot read the older shape is worse than one that reads both, and the
migration is not frozen -- `AuthController` is still documenting the old shape
in its own docblock, which means the codebase is mid-migration rather than
past it. `Paginated.fromDataFallback` is therefore a documented fallback, not
the expected path, and `Paginated.fromEnvelope` tries `meta` first
(`ApiMeta.tryParse(meta) ?? fromDataFallback(dataMap)`).

The audit gives this a second, independent confirmation: if any list endpoint
still answered the embedded shape, the client's list DTO key set and that
endpoint's resource would have diverged, and 0 server-only / 0 client-only
across all five list endpoints is consistent with all five using top-level
`meta`. If a future todo wants the fallback gone, the correct trigger is the
`AuthController::devicesIndex()` docblock being rewritten to stop describing
the old shape -- not a client-side cleanup.

---

## 11. Verification

Run from `packages\sehatly_api_client`, using the absolute `dart.exe` above.

`dart analyze` -- exit 0:

```
Analyzing sehatly_api_client...
No issues found!
```

`dart test` -- exit 0, **71 tests** (was 68 before this pass; three added, none
removed, modified or skipped):

```
00:00 +71: test\storage_test.dart: FileKeyValueBackend deleteAll empties the file and every read is then absent
00:00 +71: All tests passed!
```

`dart run tool/audit_wire_fields.dart` -- exit 0:

```
Module 1 wire-field audit
endpoints: 22
wrapper comparisons: 22
dto/resource comparisons: 30

TOTALS  matched=326  client-only=0  server-only=0
...
client-only units: 0
unjustified server-only units: 0
fixture-only token keys: 0
token keys with no fixture: 0
AUDIT PASS
```

All three files authored or modified in this pass are **pure ASCII** (0 bytes
above 127), checked byte-wise:

| file | bytes | non-ASCII |
| --- | --- | --- |
| `lib/src/auth/auth_interceptor.dart` | 17742 | 0 |
| `test/interceptor_test.dart` | 18568 | 0 |
| `tool/audit_wire_fields.dart` | 50355 | 0 |
| `.omo/evidence/task-24-sehatly.md` (this file) | -- | 0 |

---

## 12. Explicit findings not closed by this pass

1. **`packages/sehatly_api_client/README.md` does not exist, but
   `lib/sehatly_api_client.dart:40` points at it.** The library docblock says
   "See `README.md` for the OWASP MASVS-STORAGE-1 reasoning and the
   copy-pasteable adapter." That is a dangling reference: a reader following it
   finds no file. The *content* it promises is not lost -- it is in the
   `TokenStorage.plain` docblock and in `lib/src/storage/token_store.dart` --
   but the copy-pasteable `flutter_secure_storage` adapter exists nowhere in the
   repository. This is a real gap in the previous executor's work and I did not
   silently paper over it by either deleting the reference or inventing a README
   outside this task's scope. It should be a follow-up: either write the README
   with the adapter, or correct the docblock to name the files that do hold the
   reasoning.

2. **The audit's spec table is hand-maintained** -- 22 rows binding each endpoint
   to its client method and its DTO/resource pairing. Every *field name* on both
   sides is read out of the source, so a typo in a field name cannot hide, but a
   newly added endpoint is not automatically covered. The tool now fails loudly
   on a bad method or action name, which converts a silent false-negative into a
   hard error, but adding an endpoint still means adding a row. Deriving the
   table from `routes/api.php` would remove the hand-maintenance; that is a
   larger change than this pass's scope.

3. **The audit cannot see a field the server publishes conditionally at
   runtime.** `UserResource` emits `pasien` and `dokter` through
   `whenLoaded()`, so they are present on `GET /me` and absent on
   `register` / `otp/verify`. The lexer sees the key in the source and the client
   reads it on all three, so it compares as matched -- which is the right answer
   for "does the client read a key the server can send", but it is not a proof
   that the key is present on every one of those three responses. The
   `whenLoaded` semantics are documented on `User.pasien` / `User.dokter` and on
   `_readNested` in `dto.dart`; asserting them per-endpoint is a runtime concern
   that belongs in the PHP feature tests, not in a static audit.
