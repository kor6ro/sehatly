# Mobile integration guide

The document the mobile team reads first. It is concrete enough that an engineer
who has never seen this codebase can integrate against the API without asking a
question, and every claim in it is checkable: the endpoint paths are the ones the
router registers, the code blocks are the ones in
[`packages/sehatly_api_client`](../packages/sehatly_api_client), and the rules
are the ones in the code that enforces them.

## Read this before you install anything

**This repository ships no Flutter app.** There is no `mobile/` directory, no
Flutter SDK constraint in any `pubspec.yaml`, and no `package:flutter` import
anywhere in the tree. The mobile entry point is the **pure-Dart** package
[`packages/sehatly_api_client`](../packages/sehatly_api_client), and this
document.

That distinction is load-bearing rather than pedantic. A guide that says "add this
to your pubspec" without saying the SDK is pure Dart sends every integrator down
a Flutter install they do not need, on a machine that may not have one, for a
package that resolves, analyses and tests with a bare `dart` binary. The
statement is not a convention here: `tests/Unit/NoFlutterMobileTest.php` walks
every `pubspec.yaml` in the repository and fails the PHP suite if a `flutter:`
constraint or a `flutter` dependency appears. Your app is a Flutter app; this
package is not, and both statements are true at once.

| this repository provides | your app provides |
| --- | --- |
| the HTTP layer, the token lifecycle, the typed error and pagination models | the widgets, the routing, the secure-storage adapter |
| every path constant, and the generated request bodies and enums | the FCM adapter, the socket adapter, the `TokenStore` implementations |
| the REST chat surface and the realtime subscription logic | the UI, the localisation, the analytics |

The three things that genuinely need a platform -- a hardware-backed keystore, a
push token, and a live socket -- are declared as **interfaces** in the package
and implemented in your app. They are copy-pasteable, and section 3 and section
10 carry them.

## Contents

| section | what it settles |
| --- | --- |
| [1. Prerequisites, environment, installation](#1-prerequisites-environment-and-installation) | pure-Dart constraint, base URLs, HTTPS, cleartext, the `pubspec.yaml` block |
| [2. Auth lifecycle](#2-auth-lifecycle) | the two-step login, the six-call sequence, the Dio client and its interceptors, rotation and reuse detection |
| [3. Token storage](#3-token-storage) | `flutter_secure_storage` versus `GetStorage`, `allowBackup`, `InvalidKeyException` |
| [4. Error catalogue](#4-error-catalogue) | every status the API returns, the exact envelope, the decision tree |
| [5. Pagination](#5-pagination) | `?page=&per_page=`, the `meta` block, the 100 cap, the one ascending list |
| [6. Date, time and timezone](#6-date-time-and-timezone) | the two-rule policy, and why `23:30` must not become `16:30` |
| [7. File uploads](#7-file-uploads) | which endpoints take multipart, the field names, the MIME allow-lists |
| [8. Masked and sensitive fields](#8-masked-and-sensitive-fields) | the NIK mask, `kata_sandi_hash`, the OTP echo |
| [9. Realtime chat](#9-realtime-chat) | channel naming, the auth endpoint, no replay, de-duplication |
| [10. Push notifications](#10-push-notifications) | device registration, the two platform traps, token rotation |
| [11. Contoh integrasi dengan GetX](#11-contoh-integrasi-dengan-getx) | `GetxController`, `Bindings`, and the storage split in GetX terms |
| [12. Server-side rules the client must NOT re-implement](#12-server-side-rules-the-client-must-not-re-implement) | twelve rules the server owns |
| [13. Endpoint cookbook](#13-endpoint-cookbook) | one snippet per module, using the package's typed endpoints |
| [14. Troubleshooting](#14-troubleshooting) | symptom to cause, for the failures that actually occur |

Companion documents: the OpenAPI contract
([`openapi.yaml`](openapi.yaml), generated from the live route table and
drift-checked), the machine-readable enum lists ([`enums.json`](enums.json)),
the per-module summaries under [`docs/modules/`](modules/README.md), the root
[README](../README.md#no-flutter-app-exists-in-this-repository) on the no-Flutter
constraint, and the storage and push adapters in the package's
[README](../packages/sehatly_api_client/README.md).

---

## 1. Prerequisites, environment and installation

### 1.1 The pure-Dart constraint

[`packages/sehatly_api_client/pubspec.yaml`](../packages/sehatly_api_client/pubspec.yaml)
declares:

```yaml
name: sehatly_api_client
environment:
  sdk: '>=3.13.0 <4.0.0'
dependencies:
  dio: ^5.11.1
```

There is deliberately **no `flutter:` key**. The consequences for you:

| consequence | what to do |
| --- | --- |
| the package resolves on a CI runner with no Flutter | nothing; this is the point |
| the package cannot import `package:flutter/...` | put your adapter in your app, behind `SecureKeyValueBackend` |
| `dart pub get` inside the package works, `flutter pub get` does not need to | not an issue in a Flutter app; `flutter pub get` handles a pure-Dart dependency fine |
| the SDK floor is 3.13.0 | your app's `environment.sdk` must be at least `>=3.13.0` |

### 1.2 Base URLs, per environment

`SehatlyEnvironment` carries the four environments as an enum, so a wrong base URL
is a compile error rather than a 404 with an HTML body:

| `SehatlyEnvironment` | `baseUrl` | connect timeout | receive timeout | OTP code echoed in the response |
| --- | --- | --- | --- | --- |
| `local` | `http://127.0.0.1:8000/api/v1` | 10 s | 30 s | **yes** (server `APP_ENV=local` only) |
| `staging` | `https://staging.sehatly.id/api/v1` | 20 s | 60 s | no |
| `production` | `https://api.sehatly.id/api/v1` | 30 s | 90 s | no |
| `test` | `http://127.0.0.1:8000/api/v1` | 5 s | 15 s | same rule as `local` |

Three things about that table that are easy to get wrong:

- **The base URL already ends in the API prefix.** `bootstrap/app.php` mounts the
  group with `apiPrefix: 'api/v1'`, so a path you pass to the client starts at
  the group root and carries no prefix. Appending the prefix a second time builds
  a URL that 404s.
- **`127.0.0.1` is not `localhost` on purpose.** On Windows `localhost` resolves
  to the IPv6 address first, and a PHP dev server bound only to the IPv4 socket
  answers a connection refusal for the IPv6 attempt.
- **The OTP echo is a property of the *server's* environment, not of the enum.**
  `OtpService` publishes the plaintext code outside production. A `SehatlyEnvironment.test`
  client pointed at a production server still gets no code.

### 1.3 HTTPS, and cleartext only for a local loopback

Release builds must talk to `https://`. The API sets HSTS and a strict CSP on
every response, and a bearer token over cleartext is a bearer token anyone on the
network can replay.

For local development the mobile app must permit cleartext to **one** of two
hosts, and to no other:

| platform | host that reaches the developer machine | Android manifest |
| --- | --- | --- |
| Android emulator | `10.0.2.2` | a network-security config allowing cleartext for `10.0.2.2` only |
| iOS simulator | `localhost` | n/a; ATS exception for `localhost` |
| physical device | the machine's LAN address | add that address to the same allow-list, and never ship the build |

**The trap.** `SehatlyEnvironment.local` points at `127.0.0.1`, and an Android
emulator's `127.0.0.1` is *the emulator itself*, not your laptop. An Android
emulator therefore cannot use the enum's `local` value. Two ways out, both real:

```dart
import 'package:sehatly_api_client/sehatly_api_client.dart';

// `backend` is your SecureKeyValueBackend -- see section 3.2 for the adapter.
final SehatlyEnvironment environment =
    SehatlyEnvironment.forName(const String.fromEnvironment('SEHATLY_ENV')) ??
    SehatlyEnvironment.local;

final String emulatorBaseUrl = const String.fromEnvironment(
  'SEHATLY_BASE_URL',
  defaultValue: '',
);

final TokenStorage storage = TokenStorage.secure(backend);

final SehatlyApiClient client = emulatorBaseUrl.isEmpty
    ? SehatlyApiClient.secure(backend: backend, environment: environment)
    : SehatlyApiClient(
        // The low-level constructor, for a base URL the enum does not carry.
        baseUrl: emulatorBaseUrl,
        storage: storage,
        connectTimeout: environment.connectTimeout,
        receiveTimeout: environment.receiveTimeout,
        sendTimeout: environment.sendTimeout,
      );
```

```console
# Android emulator against `php artisan serve` on the host
flutter run --dart-define=SEHATLY_BASE_URL=http://10.0.2.2:8000/api/v1
```

### 1.4 Installation

Add the package to **your** app's `pubspec.yaml`. It is `publish_to: none`, so it
is consumed from a path or a git reference, never from pub.dev:

```yaml
dependencies:
  get: ^4.7.3
  flutter_secure_storage:
  get_storage:
  firebase_messaging:
  pusher_channels_flutter:
  sehatly_api_client:
    path: ../sehatly/packages/sehatly_api_client
    # or, from a clone of this repository:
    # git:
    #   url: https://github.com/<org>/sehatly.git
    #   ref: main
    #   path: packages/sehatly_api_client
```

Only the `get: ^4.7.3` constraint carries a version, and the reason is
load-bearing: GetX 5.0-rc deprecates the `Bindings` / `BindingsBuilder` /
`Get.lazyPut` / `GetxController` API that section 11 is written against, in
favour of a `Binding` + `List<Bind>` API that does not exist in a stable release.
Pinning `^4.7.3` is part of following the example.

The other four are deliberately versionless in this document. **No version of
`flutter_secure_storage`, `get_storage`, `firebase_messaging` or
`pusher_channels_flutter` is pinned anywhere in this repository**, so this guide
does not assert one; pin them in your own lockfile and treat the choice as yours.
`dio` is not in that list because the package depends on it already and you
should not declare it.

One import gives you everything:

```dart
import 'package:sehatly_api_client/sehatly_api_client.dart';
```

---

## 2. Auth lifecycle

### 2.1 The load-bearing fact: `/auth/login` returns no token

Both registration and login are **two requests**. The password step mints an OTP
and stops. The only endpoint in the entire API that mints a token is
`POST /api/v1/auth/otp/verify`.

```text
POST /api/v1/auth/register   -> 201  { user, otp }      NO token
    ... the user reads the SMS ...
POST /api/v1/auth/otp/verify -> 200  { user, token }    the only token in the API

POST /api/v1/auth/login      -> 200  { otp }            NO token
    ... the user reads the SMS ...
POST /api/v1/auth/otp/verify -> 200  { user, token }    the only token in the API
```

This is a deliberate control, not an omission: a phone-proved second factor is
only a control while the second step still requires the phone. Issuing a token
from `/auth/login` would make the OTP a confirmation screen.

The package makes the mistake a **compile error** rather than a null. `LoginResult`
has no `token` member at all, so a call site that reaches for one does not
compile. Do not hand-roll a request to work around that.

### 2.2 The six-call sequence, with real request and response bodies

**Call 1 -- register.** `POST /api/v1/auth/register`, unauthenticated, throttled
at 10 requests per minute.

```json
{
  "nama_lengkap": "Siti Aminah",
  "no_telepon": "08123456789",
  "password": "a-long-enough-password",
  "jenis_kelamin": "P",
  "tanggal_lahir": "1994-03-17",
  "alamat_lengkap": "Jl. Merdeka No. 12, Jakarta",
  "email": "siti@example.test",
  "bahasa": "id"
}
```

`jenis_kelamin`, `tanggal_lahir` and `alamat_lengkap` are **required**, and they
are required because the server creates the `pasien` row in the same transaction.
There is no `nik` field on this request; sending one is a 422.

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 12,
      "uuid": "3f1c...",
      "nama_lengkap": "Siti Aminah",
      "no_telepon": "08123456789",
      "email": "siti@example.test",
      "tipe": "pasien",
      "status": "pending_verifikasi",
      "bahasa": "id",
      "telepon_terverifikasi": false,
      "email_terverifikasi": false,
      "dibuat_at": "2026-09-29T13:41:26.000000Z"
    },
    "otp": {
      "id": 91,
      "tujuan": "verifikasi_telepon",
      "kedaluwarsa_at": "2026-09-29T13:46:26.000000Z",
      "ttl_detik": 300,
      "kode": "014725"
    }
  },
  "message": "Registrasi berhasil. Kode OTP telah dikirim."
}
```

`data.otp.kode` is populated **only** when the server runs with `APP_ENV=local`.
In staging and production it is `null` and the code arrives over SMS. Never write
a screen that requires it.

**Call 2 -- verify the OTP.** `POST /api/v1/auth/otp/verify`, unauthenticated.

```json
{
  "kode": "014725",
  "tujuan": "verifikasi_telepon",
  "no_telepon": "08123456789",
  "device_id": "install-8f2c-11ab"
}
```

`kode` is a **string** of exactly six digits and may begin with `0`; parse it as
an integer and `014725` becomes `14725`, which can never match. `tujuan` is
narrowed by the server to the two issuable values: `verifikasi_telepon` after a
registration and `login` after a login. `device_id` is recorded as the Sanctum
token's *name*; it does not create a `user_devices` row and it does not survive a
refresh.

```json
{
  "success": true,
  "data": {
    "user": { "id": 12, "status": "aktif", "telepon_terverifikasi": true },
    "token": {
      "token_type": "Bearer",
      "access_token": "1|yExxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
      "expires_in": 86400,
      "access_token_expires_at": "2026-09-30T13:41:26.000000Z",
      "refresh_token": "rt_9fAbcxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
      "refresh_token_expires_at": "2026-10-29T13:41:26.000000Z"
    }
  },
  "message": "Verifikasi OTP berhasil."
}
```

Persist the pair yourself. The package deliberately does not write storage inside
`verifyOtp`, so the choice of store stays yours:

```dart
Future<void> main() async {
  final VerifyOtpResult verified = await client.auth.verifyOtp(
    kode: enteredCode,
    tujuan: OtpTujuanDiterbitkan.verifikasiTelepon,
    noTelepon: '08123456789',
    deviceId: installationId,
  );

  await client.persistSession(verified.token);
}
```

**Call 3 -- an authenticated call.** Every protected route takes
`Authorization: Bearer <access_token>`. The package's interceptor attaches it.

**Call 4 -- refresh.** `POST /api/v1/auth/refresh`, unauthenticated, because the
caller presents a refresh token rather than a bearer token.

```json
{ "refresh_token": "rt_9fAbcxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" }
```

**Call 5 -- logout.** `POST /api/v1/auth/logout`, authenticated.
`refresh_token` is **required**; the server refuses an optional one so that "log
out" has exactly one meaning.

```json
{
  "success": true,
  "data": {
    "refresh_token": { "dicabut": true },
    "access_token": { "dihapus": true },
    "perangkat": { "dimatikan": 2 }
  },
  "message": "Logout berhasil."
}
```

Those three booleans are **reports, not commands**. `refreshDicabut: false` is not
an error; it means the token was already gone, which is exactly what an
idempotent sign-out should tolerate. Note the three different Indonesian roots:
`dicabut`, `dihapus`, `dimatikan`.

**Call 6 -- the local clear.** `SehatlyApiClient.signOut()` calls logout and then
clears both stores *in a `finally`*, so an unreachable server still leaves no
live credential on a device the user believes they have signed out of. That is
broad -- logout deactivates **every** `user_devices` row for the account, because
`user_refresh_tokens` has no `device_id` column and the server cannot tell which
device a session belongs to. To end one session, use
`DELETE /api/v1/auth/devices/{deviceId}`.

### 2.3 Token lifetimes, and the OTP attempt rules

| credential | lifetime | source |
| --- | --- | --- |
| access token | 1440 minutes (24 h) | `config('sanctum.expiration')` |
| refresh token | 30 days | `TokenService::REFRESH_TTL_HARI` |
| OTP code | 5 minutes, 6 digits | `OtpService::TTL_MENIT`, `OtpService::KODE_DIGIT` |

OTP attempts: **5 per issued code, in a 300-second window, keyed on the code's own
row id.** The sixth attempt does not merely return 429 -- it **burns the code**,
so the user must request a new one. Every rejection before that is a 422 with the
reason under `errors.kode`, which is how a client tells "resend" from "start over".
`reset_kata_sandi` and `verifikasi_email` exist in the column and are **refused**
by this endpoint on purpose: its effect is "issue a session", and neither needs one.

There is **no resend endpoint**. To re-issue a login OTP, call
`POST /api/v1/auth/login` again, which is throttled at 5 per minute on the
identifier. Issuing a new OTP invalidates the previous unused one for the same
purpose, so "resend" is a fresh login call, not a re-read of the old code.

An unknown identifier and a wrong password produce the **same** 401 body and take
the same time by way of a decoy hash, so `/auth/login` is not a phone-number
oracle. A **403** there means the account is `nonaktif` or `ditangguhkan`, which is
a different answer and deserves different UI copy.

### 2.4 The Dio client and its interceptors

The package owns the `Dio`, its timeouts, its `validateStatus`, the bearer header
and the refresh. You construct it once (section 11 shows where).

**It is not one `Dio`.** There are three, and each exists for a structural reason
documented in `packages/sehatly_api_client/lib/src/client.dart`:

| instance | interceptors | why it is separate |
| --- | --- | --- |
| the authenticated client | `AuthInterceptor` + yours | every normal request |
| the refresh client | **none** | there is no `onError` to re-enter, so a refresh cannot recurse, and no stale `Authorization` to present |
| the replay client | `AuthInterceptor` only | `AuthInterceptor` is a `QueuedInterceptor`: replaying through the authenticated client deadlocks the moment the replay itself 401s, because its error queues behind the very task awaiting it |

The behaviour that matters to you:

- **Single-flight refresh.** N simultaneous 401s produce **one** rotation. This is
  a property of *one* `RefreshCoordinator`; two coordinators would be two
  single-flights, which is the bug.
- **Re-read the token on every request**, not once at construction. The token
  rotates, so a header map captured at startup is a stale credential.
- **Rotation is a one-shot.** The server revokes the presented refresh token on
  every use, so a client that retries a refresh with the same token gets a **401**,
  and the server's answer to that is to revoke **every** live refresh token for
  the account. A 401 from the refresh path is therefore **terminal**: the package
  clears both stores and fires `onSessionExpired` exactly once. Route to login.
- **Only some requests are replayable.** `AuthApi` opts individual `POST`s in
  (`allowUnsafeRetry: true`) because replaying a non-idempotent write is worse
  than a 401. If you add your own endpoint through `client.transport`, decide the
  same thing explicitly.

```dart
final client = SehatlyApiClient.secure(
  backend: secureBackend,
  environment: SehatlyEnvironment.staging,
  onSessionExpired: (ApiException error) {
    // Fires once, when the session is unrecoverable. Clear navigation state
    // and send the user to the OTP screen. Do not retry.
    Get.offAllNamed('/login');
  },
);
```

`onSessionExpired` defaults to a no-op, which is deliberate: it fires from inside
a `catch` in an interceptor on whatever request happened to be in flight, and
there is nobody there to receive an exception.

---

## 3. Token storage

### 3.1 The split, and the OWASP reason for it

| value | store | why |
| --- | --- | --- |
| access token | `flutter_secure_storage` | minutes of lifetime, but a replayable credential |
| refresh token | `flutter_secure_storage` | **30 days of lifetime.** This is what a stolen device replays to mint access tokens indefinitely |
| theme, locale, cached profile, FCM token | `GetStorage` | a convenience cache; losing it costs a refetch, not a session |

OWASP MASVS-STORAGE-1 requires credentials at rest to live in a hardware-backed
keystore. On Android that is the EncryptedSharedPreferences/Keystore path; on iOS
it is the keychain. A bearer token in an unencrypted file is readable by any app
with storage access on an old Android, and readable regardless on a rooted
device.

The package encodes this in the type system rather than in a comment. There are
two constructors, not one with a flag:

```dart
// A shipping app. `backend` must be a SecureKeyValueBackend, so a plain map
// does not compile.
final client = SehatlyApiClient.secure(
  backend: FlutterSecureStorageBackend(),
  environment: SehatlyEnvironment.production,
);

// A desktop build, a CLI script, a unit test. The acknowledgement is a named
// parameter, so "I meant this" is a line in a diff rather than an assumption.
final desktopClient = SehatlyApiClient.plain(
  backend: backend,
  allowInsecureStorage: true,
  environment: SehatlyEnvironment.local,
);
```

`allowInsecureStorage` is a **required** named argument. Omitting it is a compile
error; that is the mechanism.

### 3.2 The `flutter_secure_storage` adapter

```dart
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:sehatly_api_client/sehatly_api_client.dart';

class FlutterSecureStorageBackend implements SecureKeyValueBackend {
  FlutterSecureStorageBackend([FlutterSecureStorage? storage])
    : _storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(encryptedSharedPreferences: true),
            iOptions: IOSOptions(
              accessibility: KeychainAccessibility.first_unlock,
            ),
          );

  final FlutterSecureStorage _storage;

  @override
  String get description => 'flutter_secure_storage';

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);

  @override
  Future<void> deleteAll() => _storage.deleteAll();
}
```

`KeychainAccessibility.first_unlock` is deliberate, not a default you inherited: a
background fetch that wakes the app while the device is still locked must be able
to read the refresh token, or push handling fails on every lock screen.

### 3.3 `android:allowBackup="false"`, and the exception it prevents

Android's Auto Backup copies an app's private storage to the user's cloud
account. The Android Keystore key that decrypts those files is **not** part of the
backup, so on restore the ciphertext comes back and the key does not, and every
read throws:

```text
InvalidKeyException: Failed to unwrap key
```

There is no recovery path in the app: the value is unreadable, permanently, and
the only fix is to sign the user out and hope the refresh never happens again.
Set `android:allowBackup="false"` in the application tag of your manifest so the
ciphertext is never copied out in the first place. This is the only Android
manifest setting this guide asks for, and the reason is this exception, not
general hygiene.

### 3.4 The two keys, and the interface you can swap

The package writes two namespaced keys -- `sehatly.access_token` and
`sehatly.refresh_token` -- so a shared keychain cannot collide with an unrelated
value. The interfaces are `TokenStore` and `RefreshTokenStore`, separate types on
purpose: the two tokens have different lifetimes and different blast radii, and
"clear the session" is not expressible as two independent `clear()` calls without a
caller that remembers to do both. Implement both, or use
`InMemoryKeyValueBackend` for a test.

---

## 4. Error catalogue

### 4.1 The failure envelope

Every failure under the API is rendered by a single callback in
`bootstrap/app.php`, so there are exactly two failure shapes and no third:

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "no_telepon": ["Format nomor telepon tidak valid."],
    "password": ["Kata sandi minimal 8 karakter."]
  }
}
```

- `message` is a **stable, translatable, field-independent** string. The server
  deliberately does not forward Laravel's default validation summary, which
  promotes the first field error and appends "(and N more errors)" and would make
  `message` data-dependent. Key your UI off the status code; render `errors`.
- `errors` maps a field to a **list** of messages, and a field can carry more than
  one. `POST /api/v1/konsultasi/{id}/chat` does it deliberately: send a text
  message with a file attached and you get **two messages on the same
  `berkas` field**. A client that renders only `errors[field].first` silently
  drops half the reason. There is a test in the PHP suite that pins this.
- `errors` is `{}` -- an empty object, never `[]` -- for every 401, 403, 404 and
  sanitised 500. An empty `errors` is a normal state, never a parse failure.

The package parses all of this into `ApiException` (aliased `ApiError`) with
`statusCode`, `message`, `errors`, `method`, `path` and the underlying
`DioException`, plus the helpers `isValidationError`, `isUnauthorized`,
`isForbidden`, `isNotFound`, `isTooManyRequests`, `isServerError`,
`isNetworkError`, `isSlotTaken` and `isConsentRequired`.

### 4.2 Every status the API returns

| status | meaning | client behaviour |
| --- | --- | --- |
| 200 | read, update, and every delete | `data` is present |
| 201 | created, and the "device upsert collided" case | `data` is present |
| 204 | **never returned.** Every delete answers 200 with `{ "id": 7, "deleted": true }` so the client can confirm which row went | do not write a 204 branch |
| 401 | no token, an expired token, a spent refresh token, wrong password | on a protected route: the interceptor refreshes once. On `/auth/refresh`: **terminal**, clear storage and route to login |
| 403 | permission, user type, or PDP consent | three different screens; see below |
| 404 | the row does not exist **for this caller** | not an error to retry. A row owned by somebody else is a 404 by design, because a 403 would confirm it exists |
| 405 | wrong method for a registered path | a client bug; log the path |
| 419 | CSRF mismatch | should be unreachable: the API is stateless and the bearer scheme carries no CSRF token |
| 422 | validation, and a rejected OTP code | render `errors`; check `errors.kode` before offering a resend |
| 429 | throttled | read `Retry-After`; see section 4.4 |
| 5xx | server fault | the body is always the fixed string `Internal server error.`; nothing more specific exists, and no stack trace is ever published |
| 0 | the request never reached the server | `isNetworkError`: connection refused, DNS failure, timeout, bad certificate |

### 4.3 The three 403 causes

| cause | middleware | what the client should do |
| --- | --- | --- |
| missing RBAC grant | `permission:<code>` | a bug or a mis-scoped session. Log the code, do not prompt for login |
| wrong account type | `tipe:dokter`, `tipe:apoteker` | a UI problem: this screen is not for this account. Hide it |
| PDP consent missing | `App\Services\Pdp\PdpConsent` | a consent *flow*. The patient must grant `berbagi_data_medis` first |

The consent one is the only 403 that is expected during normal use: a referral
letter (`surat_rujukan`) requires an approved `berbagi_data_medis` consent, and
without it the server answers 403 and leaves no rows behind. The refusal message
is a **constant on purpose** -- naming the missing consent kind would confirm to
the doctor a fact about the *patient* they did not have before asking.

`ApiException.isConsentRequired` matches on a 403 plus either a consent key in
`errors` or one of `consent`, `persetujuan`, `setuju`, `pdp` in the message, so
you do not have to match on the message text yourself. **401 always precedes 403**,
including on a route whose only guard is a `permission:` one, so an unauthenticated
caller gets the 401 envelope and never the 403 envelope.

### 4.4 Decision tree

```text
on ApiException e
|-- e.isNetworkError ............... statusCode 0: retry with backoff, never prompt for login
|-- e.isUnauthorized
|  |-- the failing path is the refresh path ... session over: clear, route to login, do not retry
|  `-- any other path ................ the interceptor already retried once; surface the error
|-- e.isTooManyRequests ............ read Retry-After; disable the action for that many seconds
|-- e.isValidationError
|  |-- errors has "kode" ........... an OTP failure: expired / wrong / already used
|  |-- errors has "slot" ........... the slot is gone; offer the next one
|  `-- otherwise ................... render every list entry per field
|-- e.isForbidden
|  |-- e.isConsentRequired .......... start the PDP consent flow
|  |-- the screen is wrong for the account type ... hide it
|  `-- otherwise ................... a permission bug; log the permission code
|-- e.isNotFound ................... the row is gone or not yours; remove it from the UI
|-- e.isServerError ................ generic copy; offer retry; do not parse the body
`-- anything else .................. log method, path and status
```

---

## 5. Pagination

### 5.1 The block

List endpoints answer `?page=` and `?per_page=`, and the block is a **fourth,
top-level key** -- not nested inside `data`:

```json
{
  "success": true,
  "data": { "dokter": [ /* ... */ ] },
  "message": "",
  "meta": {
    "current_page": 2,
    "last_page": 5,
    "per_page": 15,
    "total": 68,
    "from": 16,
    "to": 30
  }
}
```

| key | the trap |
| --- | --- |
| `per_page` | the page size the server **applied**, after its cap. A request for `per_page=500` answers `per_page: 100`. Render `meta.per_page`, never the value you asked for |
| `from` / `to` | `null` on an empty page, never `0`. `from: 0` renders as "0-0 of 0" and reads as a bug to a user |
| `meta` | **absent** on a single-resource response, not `null`. `ApiEnvelope.isPaginated` distinguishes the two |

### 5.2 The cap, and the unpaged lists

`per_page` is capped at **100** on every list endpoint. Two lists are deliberately
unpaged and answer the degenerate block `current_page: 1, last_page: 1`:
`GET /api/v1/auth/devices` and `GET /api/v1/master-spesialisasi`. An account has a
handful of devices, and a pager over them would be one disabled button. The
package's `Paginated<T>` holds all three real states -- a single resource with no
`meta`, a real page, and a degenerate one -- so one call site handles all of them.

### 5.3 The one ascending list

Every list is newest-first **except** `GET /api/v1/konsultasi/{id}/chat`,
which the service orders by `terkirim_at` then `id`, **ascending**. A transcript
that renders newest-first is a transcript that reads backwards, and the message
you are looking at is the one at the bottom. Do not reverse it client-side; the
ascending order is also the order the realtime backfill arrives in, so reversing
one and not the other duplicates and reorders.

```dart
Future<void> main() async {
  // The consultation is in the path, so `per_page` is the only query
  // parameter. The rows arrive under `data.pesan` and `meta` is a top-level
  // sibling of `data`, never nested inside it.
  final ApiEnvelope<Object?> page = await client.transport.get<Object?>(
    apiv1konsultasiidchat,
    queryParameters: <String, Object?>{'per_page': 50},
    parseData: parseDataObject,
  );

  final List<Object?> rows = jsonList(page.dataMap['pesan']);
  final ApiMeta? meta = page.pagination;

  if (meta == null || !meta.hasNextPage) {
    return;
  }

  // Ask for the next page. `meta.perPage` is the size the server APPLIED.
  final ApiEnvelope<Object?> next = await client.transport.get<Object?>(
    apiv1konsultasiidchat,
    queryParameters: <String, Object?>{
      'per_page': meta.perPage,
      'page': meta.nextPage,
    },
    parseData: parseDataObject,
  );

  rows.addAll(jsonList(next.dataMap['pesan']));
}
```

---

## 6. Date, time and timezone

This is the section where a plausible-looking client is most likely to be wrong,
because a wrong instant still **parses**. The full analysis is
[`timezone-policy.md`](timezone-policy.md#the-time-rule-stated-on-its-own);
these are the two rules and what they mean for your code.

### 6.1 Rule 1 -- an instant is UTC, published with a `Z`

```json
"dibuat_at": "2026-09-29T13:41:26.000000Z"
```

Every one of the 55 `TIMESTAMP` columns is an instant, and most `DATETIME`
columns are too. `DateTime.parse` handles these, and
`toUtc().toIso8601String()` re-emits them safely.

### 6.2 Rule 2 -- a wall clock is published exactly as authored

```json
"tanggal_kunjungan": "2026-10-05",
"slot_mulai": "17:00:00",
"tanggal_resep": "2026-10-05 17:00:00"
```

No offset, no conversion, no `Z`. `pasien.tanggal_lahir`, every `DATE`, and all
four `TIME` columns are wall clocks. `dokter_jadwal.jam_mulai` is `17:00:00` at
the clinic, and it is not 17:00 UTC and not 09:00 UTC.

### 6.3 The three wall-clock `DATETIME` columns whose names lie

| column | published as | reads like | actually is |
| --- | --- | --- | --- |
| `konsultasi.mulai_at` | ISO-8601 instant with `Z` | a wall clock | an instant |
| `resep.tanggal_resep` | `2026-10-05 17:00:00`, no offset | an instant | a **wall clock** |
| `home_care_pesanan.jadwal_kunjungan` | `2026-10-05 07:00:00`, no offset | an instant | a **wall clock** |

Two `*_at` columns are wall clocks and one is not. This is why the server's own
enforcement test is an explicit allow-list rather than a suffix rule, and why you
must not infer the rule from the column name either.

### 6.4 Worked example: why `23:30` must not become `16:30`

A late clinic slot, `booking.slot_mulai = 23:30:00`, on `tanggal_kunjungan =
2026-10-05`. WIB is a fixed `+07:00` with no daylight saving, so the instant is
`2026-10-05T16:30:00Z`. Round-trip it wrongly and you get this:

```dart
final String published = '2026-10-05 23:30:00';

// WRONG. DateTime.parse on a bare wall clock yields a LOCAL DateTime, and
// toUtc() then shifts it by the device's own UTC offset. On a device set to
// UTC+7 this is accidentally right; on a device set to UTC it is 16:30 UTC
// rendered as 23:30 local, and on a device in UTC-5 it is a completely
// different day. The value is wrong by a different amount on every device.
final DateTime wrong = DateTime.parse(published).toUtc();

// RIGHT. Keep the string. A wall clock is a string, not an instant.
const String right = '2026-10-05 23:30:00';
```

The rule that makes this safe is short enough to memorise:

> If the published string has **no offset and no `Z`**, it is a wall clock: store
> and display the string. Never call `toUtc()`, `toLocal()`, `toIso8601String()`
> or pass it to a `DateTime` constructor.

`toIso8601String()` is the specific trap for `tanggal_kunjungan`: it appends an
offset to a value that must not have one, and the result then round-trips
through every other client's `DateTime.parse` as if it were an instant.

To turn a slot into a real moment -- the only legitimate route, and one the
client should rarely need -- pair the `DATE` with the `TIME` and label the result
`+07:00` yourself. That is what `App\Support\WaktuIndonesia::toInstant()` does
server-side, and it is the only place the zone is named.

A related trap: the server stores `resep.berlaku_sampai` as a `DATE` counting
**calendar days on paper** from `tanggal_resep`. Seven days is not 604800000
milliseconds, and a client that compares instants will disagree with the server by
a day twice a year. Section 12 says what to trust instead.

---

## 7. File uploads

### 7.1 One endpoint takes multipart

`POST /api/v1/konsultasi/{id}/chat` is the **only** endpoint in the API that
accepts a file. The field is `berkas`, which is the one request field in the whole
project that is not a DDL column name, because there is no column for the upload
itself: the three it fills are `file_url`, `file_nama` and `file_ukuran_kb`.

The body and the file are **exclusive per message type**, and both rules fire at
once. `berkas` is `required_if` the file-backed types and `prohibited_unless`
those same types, so a text message with a file attached produces two messages on
the same field.

| `tipe_pesan` | `isi` | `berkas` | accepted MIME types |
| --- | --- | --- | --- |
| `teks` | required | **prohibited** | -- |
| `gambar` | optional | required | `image/jpeg`, `image/png`, `image/gif`, `image/webp` |
| `dokumen` | optional | required | `application/pdf`, the four Office OOXML types, `application/msword`, `application/vnd.ms-excel`, `application/vnd.ms-powerpoint`, `text/plain`, `text/csv` |
| `audio` | optional | required | `audio/mpeg`, `audio/mp4`, `audio/aac`, `audio/ogg`, `audio/wav`, `audio/x-wav`, `audio/webm` |
| `video_note` | optional | required | `video/mp4`, `video/quicktime`, `video/webm`, `video/3gpp` |

The ceiling is **10240 KB** (10 MB) and the MIME list is an **allow-list** per
type, not a `text/` or `application/` prefix -- `application/octet-stream` and
`application/x-msdownload` are both `application/`, and a transcript two clients
will render is not the place to accept arbitrary executables under a `dokumen`
label. `isi` is bounded at 16000 characters, which is under `TEXT`'s 65535-**byte**
ceiling so a long message is a 422 you can read rather than a 500 you cannot.

```dart
Future<void> main() async {
  final FormData form = FormData.fromMap(<String, Object?>{
    'tipe_pesan': 'gambar',
    'isi': 'Ini hasil rontgen saya.',
    'berkas': await MultipartFile.fromFile(path, filename: 'rontgen.jpg'),
  });

  final Response<Object?> response = await client.dio.post<Object?>(
    apiv1konsultasiidchat,
    data: form,
  );
}
```

`konsultasi_id`, `pengirim_user_id`, `pengirim_tipe`, `dibaca_at` and
`terkirim_at` are all `prohibited` on this request: the consultation is in the
path and the sender and the timestamps are the server's to write. Sending them is
a 422 naming every one of them.

### 7.2 `file_url` on a referral letter is a URL, not an upload

`POST /api/v1/konsultasi/{id}/surat-keterangan` takes a `file_url` **string**.
There is no multipart on that endpoint and no upload endpoint anywhere. If the
letter needs an attachment, your app hosts the file and passes the URL; the server
validates the field's shape and forbids the client from setting `file_url` after
the fact, because a doctor who could rewrite the URL on a signed letter could
point a verification QR at somebody else's document.

---

## 8. Masked and sensitive fields

### 8.1 The NIK mask

`App\Support\NikMasker` keeps the **first four** and the **last four**
characters and replaces every character in between with `U+2022 BULLET`:

| stored | published |
| --- | --- |
| `3273010108900021` | `3273••••••••0021` |

Three properties to rely on:

- **The masked string is exactly as long as the input**, so the response leaks
  nothing beyond the length the `CHAR(16)` column already fixes.
- A value shorter than eight characters is returned **untouched**, because there
  is no interior to hide and a "masked" three-character value that reveals all
  three is worse than saying so. This cannot arise for a `CHAR(16)` written by
  this API.
- `null` in, `null` out. An empty identifier is the absence of one, not a value
  to mask -- returning a bullet run would make "no NIK" look like "a NIK exists".

`pasien.nomor_kk` is also `CHAR(16)` and goes through the same function.

**Never log a raw NIK, never put one in a sample, and never round-trip a masked
value into an input field.** A masked NIK is not a valid NIK, and the server
validates the plaintext form; submitting `3273••••••••0021` is a 422. The
`PUT /api/v1/pasien/profil` request does not accept `nik` at all.

### 8.2 Names are masked on public endpoints, by a different rule

`App\Support\NamaMasker` keeps the first character of each word and replaces the
rest: `Siti Aminah` becomes `S••••• A•••••`. It is a separate class because the
two fields have different widths and different acceptable losses -- a
first-four/last-four rule applied to a name publishes most of it. It is used on
responses any stranger may obtain. A medical record is deliberately **unmasked**,
because it is read by the record's own patient and their own doctor and a name
that reads `S••••• A•••••` in a clinical note is worse than useless.

### 8.3 What never appears

| value | rule |
| --- | --- |
| `kata_sandi_hash` | never returned by any endpoint, on any surface |
| the OTP plaintext | in the response **only** when the server runs with `APP_ENV=local` |
| a hashed OTP | the server stores `sha256(kode)`; the plaintext exists only in transit and, in local, in the response |
| another account's row | a 404, never a 403, precisely so existence is not confirmed |
| a NIK in a log line | the server masks the recipient in its own OTP log line; do the same in yours |

---

## 9. Realtime chat

### 9.1 What exists, and what does not

The broadcaster **is** built: `config/broadcasting.php` exists, `bootstrap/app.php`
calls `withBroadcasting()`, `routes/channels.php` declares the one channel, and
`App\Events\KonsultasiMessageSent` implements `ShouldBroadcastNow`. The REST chat
path works with `BROADCAST_CONNECTION=null`, and that independence is the point:
stopping the broker degrades delivery, it does not break the API.

`laravel_reverb` is **not** a dependency of the Dart package, and
`RealtimeSocket` is the seam you implement. The package's README carries a
`pusher_channels_flutter` adapter; it is a starting point, not a locked-in choice,
because both Reverb and Soketi speak the Pusher protocol. Running the broker
locally is three processes, and the Module 3 summary has the commands
([Realtime: kanal privat per konsultasi](modules/modul-3-konsultasi-rekam-medis.md#realtime-kanal-privat-per-konsultasi)).

### 9.2 Channel naming -- the two strings are different

| | string |
| --- | --- |
| logical name, what your code passes around | `konsultasi.5` |
| wire name, what the broker sees | `private-konsultasi.5` |
| authorisation pattern, in `routes/channels.php` | `konsultasi.{id}` |

`PrivateChannel::__construct()` adds the `private-` prefix itself, and
`normalizeChannelName()` strips it before matching the pattern. Three consequences:

- Passing an already-prefixed name in publishes `private-private-konsultasi.5`,
  a channel that was never authorised.
- A pattern written as `private-konsultasi.{id}` never matches anything, because
  the incoming request is normalised to `konsultasi.5` first. Every authorisation
  would fall through to a 403.
- The channel is namespaced on purpose. A `{id}`-only pattern would authorise any
  authenticated user against any string in the app. Authorisation is the
  consultation's patient and its doctor and nobody else.

Authorisation is `GET|POST /api/broadcasting/auth`, and it needs the
`Authorization: Bearer` header. A request carrying only a subscription and no
bearer is refused, and a refusal on a session-authenticated route would be a
redirect or a 419 with no error anywhere -- which is why the endpoint is named as
a constant in the package (`broadcastAuthEndpoint`).

### 9.3 The event and its payload

`App\Events\KonsultasiMessageSent` publishes on `PrivateChannel('konsultasi.{id}')`
-- the constructor adds the prefix, so the wire name is
`private-konsultasi.{id}` -- under the broadcast name `chat.pesan`. The payload
is the same already-serialised array the REST history
endpoint returns, passed in by the caller. The wire key is **`konsultasi_id`**, not
`konsultasi`: a resource serialises a model's attributes, so a model whose column
is `konsultasi_id` publishes `konsultasi_id`, and a client reading `konsultasi`
scores every message in the transcript as consultation `0`. There is a Dart test
pinning that key for exactly that reason.

### 9.4 Three protocol facts that shape the client

1. **The broker does not replay.** A message broadcast while the socket was down
   is not buffered for you. The gap has to be closed over REST by the caller, and
   `RealtimeClient.onResync` is the hook: return the rows for the channels that
   were missed, and anything already seen is withheld automatically.
2. **A re-subscribe can re-deliver.** Re-sending the subscribe makes the broker
   replay whatever it considers in flight, so the same
   `konsultasi_chat.id` can arrive twice. Dedupe is keyed on **the message's own
   primary key**, never on arrival order and never on `konsultasi_id` (which every
   message in one transcript shares). The package's set is bounded at 500 ids,
   oldest evicted, and `duplicateSuppressedCount` is the honest count of what was
   withheld. A frame with no id is **delivered rather than swallowed** -- it
   cannot be deduplicated, and swallowing it loses a message.
3. **An authorisation failure is a normal outcome.** A revoked consultation, a
   signed-out session and a rate-limited auth endpoint all surface as a refused
   subscribe, and one refused channel must not silence the others on the same
   connection.

```dart
Future<void> main() async {
  final RealtimeClient realtime = RealtimeClient(
    socket: PusherRealtimeSocket(
      host: 'reverb.example.test',
      port: 8080,
      appKey: reverbAppKey,
      secret: reverbSecret,
    ),
    storage: client.storage,
    onResync: (RealtimeResync gap) async {
      // The broker replayed nothing, so the gap is closed over REST.
      return fetchChatHistory(konsultasiId: currentKonsultasiId);
    },
    onFailure: (String reason) => log.warning('realtime: $reason'),
  );

  realtime.messages.listen(render);
  await realtime.subscribeKonsultasi(konsultasiId: 5);
  realtime.connect();
}
```

`connect()` is separate from `subscribe` on purpose: a subscribe is authorised
over HTTP, so a transport that opened its socket inside that `await` would hold a
connection open across a keychain read. Your app decides when the socket opens.
`disconnect()` is not `dispose()` -- `disconnect()` keeps the subscriptions and the
dedupe set so a backgrounded app comes back to the same channels without
re-issuing the subscribe.

---

## 10. Push notifications

### 10.1 Registration

`POST /api/v1/auth/devices` upserts on `(user_id, device_id)` and sets
`aktif = 1`, which is what makes a device deactivated by a logout usable again.

| field | required | notes |
| --- | --- | --- |
| `device_id` | yes | 3-255 characters, **your** installation identifier. On iOS use `identifierForVendor`; both are alphanumeric, which is why the route segment is a string and not a number |
| `platform` | yes | `android`, `ios` or `web` |
| `fcm_token` | no | nullable column |
| `app_versi` | no | 20 characters, `[0-9A-Za-z.+_-]` only |

It answers **201 for an insert and 200 for the upsert it collided with**, so the
status distinguishes "first time" from "already known" without a second request.
`GET /api/v1/auth/devices` returns the caller's own rows, most recently active
first, and `DELETE /api/v1/auth/devices/{deviceId}` deactivates one. The row is
deactivated, never deleted -- `user_devices` is the only record of which
installations hold a push registration. A `device_id` belonging to another account
is a **404, not a 403**, because a 403 would confirm it exists.

```dart
Future<void> main() async {
  final PushRegistration registration = PushRegistration(
    auth: client.auth,
    provider: FirebasePushProvider(),
    platform: DevicePlatform.android,
    appVersi: '1.4.2',
    onRegistered: (UserDevice row) => log.info('device ${row.deviceId}'),
    onFailed: (ApiException error) => log.warning('refused: ${error.message}'),
    onWarning: (String reason) => log.warning('device side: $reason'),
  );

  await registration.register(deviceId: installationId);
  registration.start();
}
```

Two things about that call that are not obvious:

- `register` returns `null` and posts **nothing** when the user refuses
  permission. `user_devices.fcm_token` is nullable, so posting a null would
  overwrite a working token on that row and silently stop push for a device that
  was fine. A refusal is a `null` return, not an error.
- Call `start()` **once**. It is idempotent, so calling it from `initState` is
  safe, and it is what keeps the device registered when FCM rotates the token --
  which it does on a restore-from-backup, on a data wipe, and on rotations the
  app never asks about. Without it the app keeps a token the platform has already
  discarded and every notification is delivered to nobody while the API answers
  200. Call `dispose()` when the owning screen goes away.

### 10.2 The two platform traps

Both are silent: the app builds, the token is retrieved, and push simply never
arrives.

**iOS.** The app is a `UIScene` app, so
`application(_:didFinishLaunchingWithOptions:)` must call
`FLTFirebaseMessagingPlugin.configureNotificationCenterDelegate()` as the **first
statement**, before `super`. Before it, iOS does not hand the plugin the
notification-centre delegate and every tap is dropped without an error. And
because the app is a `UIScene` app, a tap that cold-starts the app arrives as a
`remoteNotification` user activity rather than through the delegate, so it also
has to be forwarded out of the scene connection.

```swift
// ios/Runner/AppDelegate.swift
@main
@objc class AppDelegate: FlutterAppDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    // Must be the FIRST statement, before `super`.
    FLTFirebaseMessagingPlugin.configureNotificationCenterDelegate()
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }
}
```

**Android.** The manifest needs `POST_NOTIFICATIONS` plus the default-channel
meta-data. Android 13+ will not show a notification at all without the
permission, and without the channel meta-data FCM silently falls back to a default
channel that does not exist on the device, so the system drops the notification
with no callback to your app.

```xml
<!-- android/app/src/main/AndroidManifest.xml -->
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
  <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
  <uses-permission android:name="android.permission.INTERNET" />

  <application android:name="${applicationName}">
    <meta-data
        android:name="com.google.firebase.messaging.default_notification_channel_id"
        android:value="@string/default_notification_channel_id" />
  </application>
</manifest>
```

The notification surface itself is REST, not realtime:
`GET /api/v1/notifikasi`, `PUT /api/v1/notifikasi/{id}/baca` and
`PUT /api/v1/notifikasi/baca-semua`.

---

## 11. Contoh integrasi dengan GetX

The mobile team builds with GetX, and re-deriving how to wrap
`sehatly_api_client` is exactly the rework this section exists to prevent.

**Two rules for everything in this section.** The controller calls
`sehatly_api_client` and **nothing else** -- it never constructs a network client
and never imports an HTTP package, so the bearer header, the single-flight refresh
and the typed error cannot be bypassed by accident. And the storage split from
section 3 is the same split in GetX terms.

The examples use the `Bindings` + `void dependencies()` + `Get.lazyPut` API of
`get: ^4.7.3`. The 5.0.0 release candidate deprecates `Bindings` and
`BindingsBuilder` in favour of a `Binding` + `List<Bind>` API that does not exist
in a stable release, so pinning `^4.7.3` in the consuming app is part of
following this example. If you upgrade to a 5.x release candidate you will be
rewriting these call sites; that is the trade, made knowingly.

### 11.1 The bindings -- one client, constructed once

```dart
import 'package:get/get.dart';
import 'package:sehatly_api_client/sehatly_api_client.dart';

class AuthBinding extends Bindings {
  AuthBinding({required this.backend});

  final SecureKeyValueBackend backend;

  @override
  void dependencies() {
    // The ONE client. Every screen reaches it through Get.find, so there is one
    // Dio, one connection pool, one interceptor chain and one token store for
    // the whole app. Building a second client would build a second
    // RefreshCoordinator, which is two single-flights -- the bug the package
    // exists to prevent.
    Get.lazyPut<SehatlyApiClient>(
      () => SehatlyApiClient.secure(
        backend: backend,
        environment: SehatlyEnvironment.staging,
        onSessionExpired: (ApiException _) => Get.offAllNamed('/login'),
      ),
    );

    Get.lazyPut<AuthController>(AuthController.new);
  }
}
```

### 11.2 The controller

```dart
class AuthController extends GetxController {
  AuthController({SehatlyApiClient? client})
    : _client = client ?? Get.find<SehatlyApiClient>();

  final SehatlyApiClient _client;

  static const String destination = '/';

  final Rx<AuthStage> stage = AuthStage.idle.obs;
  final RxString message = ''.obs;
  final Rxn<User> user = Rxn<User>();

  bool get isBusy => stage.value == AuthStage.busy;

  Future<void> submitLogin({
    required String identifier,
    required String password,
  }) async {
    stage.value = AuthStage.busy;
    message.value = '';

    try {
      // Step one of two. This call mints an OTP and returns NO token.
      await _client.auth.login(
        password: password,
        noTelepon: identifier.startsWith('0') ? identifier : null,
        email: identifier.contains('@') ? identifier : null,
      );

      stage.value = AuthStage.awaitingOtp;
    } on ApiException catch (error) {
      _fail(error);
    }
  }

  Future<void> submitOtp(String kode) async {
    stage.value = AuthStage.busy;
    message.value = '';

    try {
      // Step two. The ONLY endpoint in the API that mints a token. `kode` is a
      // String, not an int: it may begin with 0.
      final VerifyOtpResult verified = await _client.auth.verifyOtp(
        kode: kode,
        tujuan: OtpTujuanDiterbitkan.login,
        noTelepon: pendingIdentifier,
      );

      await _client.persistSession(verified.token);

      user.value = await _client.me.show();
      stage.value = AuthStage.done;
      Get.offAllNamed(destination);
    } on ApiException catch (error) {
      _fail(error);
    }
  }

  Future<void> signOut() async {
    stage.value = AuthStage.busy;
    await _client.signOut();
    user.value = null;
    stage.value = AuthStage.idle;
    Get.offAllNamed('/login');
  }

  void _fail(ApiException error) {
    // `errors` maps a field to a LIST. Render every entry: a chat message with
    // a file attached produces two messages on the same field, and taking
    // [0] silently drops half the reason.
    final List<String> all = error.errors.values
        .expand((List<String> messages) => messages)
        .toList();

    message.value = all.isEmpty ? error.message : all.first;
    stage.value = error.isValidationError
        ? AuthStage.awaitingOtp
        : AuthStage.idle;
  }
}

enum AuthStage { idle, busy, awaitingOtp, done }
```

Three idioms worth naming, because they are what makes loading, empty and error
look the same everywhere:

- `stage` is the single source of truth for "is something happening", and
  `isBusy` is the only thing a widget binds a spinner to.
- `Rxn<User>` rather than `Rx<User>` with a sentinel, so "no user yet" is a real
  state instead of a magic value.
- The failure path routes back to `awaitingOtp` for a validation error and to
  `idle` otherwise, so a wrong OTP code does not throw the user back to the
  password field.

### 11.3 The storage split in GetX terms

| value | GetX term | the package's interface |
| --- | --- | --- |
| access token | `flutter_secure_storage` | `TokenStore` |
| refresh token | `flutter_secure_storage` | `RefreshTokenStore` |
| theme, locale, cached profile, FCM token | `GetStorage` | not the package's business |
| FCM lifecycle | `firebase_messaging` | `PushTokenProvider` |

`GetStorage` is deliberately **not** in the package's dependency list, for the
same reason `flutter_secure_storage` is not: both are your app's plugins, and
depending on them would make the package untestable without the Flutter SDK. The
split itself is already encoded -- `SehatlyApiClient.secure` requires a
`SecureKeyValueBackend` by type, and `SehatlyApiClient.plain` requires a
`PlainKeyValueBackend` **plus** an explicit `allowInsecureStorage: true`.

```dart
Future<void> main() async {
  // Non-secret preferences. Never a token.
  final GetStorage box = GetStorage();

  await box.write('locale', 'id');
  await box.write('cached_profile', profile.toJson());
  await box.write('fcm_token', token);
}
```

---

## 12. Server-side rules the client must NOT re-implement

Twelve rules the server owns. Duplicating any of them in the client produces a
subtly wrong answer that no type check and no test catches.

| rule | what the server does | what the client must do |
| --- | --- | --- |
| slot availability | a slot is free only while **consuming** bookings are **strictly less than** `dokter_jadwal.kuota_per_sesi`, and only if the date is not a `dokter_libur` entry | render `GET /api/v1/dokter/{dokter}/slot`. Never compute availability locally |
| `kuota_per_sesi` null | the DDL allows `NULL`; the service substitutes **1**, and the resource publishes the **null**, not the 1 | treat a published `null` as "the server will use 1" and do not display `1` as if it were stored |
| prescription expiry | `resep.berlaku_sampai` is `tanggal_resep + 7` **calendar days**, computed, and there is **no** `kadaluarsa` trigger anywhere in the schema | trust the `is_kedaluwarsa` flag. Never compare dates locally, and never store your own expiry |
| a prescription's terminal state | `terminal` is published on every prescription surface alongside `is_kedaluwarsa` | render the flag. A local computation will disagree with the server on the boundary day |
| pharmacist verification | **exactly one** verification per prescription. A `ditolak` result is **terminal** and there is no resubmit path | hide the verify action after one attempt, whatever the result |
| referral discounts | `is_rujukan` and `is_konsultasi_lanjutan` are stored boolean flags and **never** produce an automatic discount | never apply a client-side discount for either flag |
| a referral letter | `surat_rujukan` requires an **approved** `berbagi_data_medis` PDP consent; without it the server answers **403** and writes no rows | on `isConsentRequired`, start the consent flow. Do not retry the letter |
| drug-interaction warnings | advisory. The server returns them in the response and does not block. A `kontraindikasi` item requires a non-empty `catatan_dokter` | surface the warnings; do not gate checkout on them |
| payment state | webhook state changes **asynchronously** | poll. Note: the plan names `GET /api/v1/invoice/{id}` and **that endpoint does not exist**; the pollable authenticated read for an order is `GET /api/v1/pesanan-obat/{id}` |
| stock | decremented **server-side at payment**, not at checkout | a total you displayed before payment can legitimately differ from the post-payment order state. Re-render from the order, never from your own arithmetic |
| a patient row | created in the same transaction as the `users` row, which is why register requires `jenis_kelamin`, `tanggal_lahir` and `alamat_lengkap` | do not defer those fields to the profile screen; a patient with no `pasien` row cannot book |
| record amendment | the amendment chain is reconstructed by grouping on `(pasien_id, dokter_id, tanggal_periksa)`, because the schema has **no** parent/amendment column | render the chain from the grouping, and do not invent a parent id |

---

## 13. Endpoint cookbook

The package wraps the **Module 1** surface with typed endpoint classes:
`client.auth`, `client.me`, `client.pasien` and `client.dokter`. Everything in
Modules 2 to 5 is reachable, and every path exists as a constant in
`packages/sehatly_api_client/lib/src/generated/paths_table.dart`, but there is no
hand-written wrapper class for it yet -- call those through `client.transport`,
which still gives you the bearer header, the single-flight refresh and the typed
`ApiException`.

### 13.1 Module 1 -- auth, profile, directory

```dart
Future<void> main() async {
  // Register. Returns no token; the OTP is in `data.otp`.
  final RegisterResult registered = await client.auth.register(
    namaLengkap: 'Siti Aminah',
    noTelepon: '08123456789',
    password: 'a-long-enough-password',
    jenisKelamin: JenisKelamin.p,
    tanggalLahir: '1994-03-17',
    alamatLengkap: 'Jl. Merdeka No. 12, Jakarta',
  );

  // Login. Returns no token either.
  final LoginResult login = await client.auth.login(
    password: 'a-long-enough-password',
    noTelepon: '08123456789',
  );

  // The only token in the API. Then persist it.
  final VerifyOtpResult verified = await client.auth.verifyOtp(
    kode: '014725',
    tujuan: OtpTujuanDiterbitan.login,
    noTelepon: '08123456789',
  );
  await client.persistSession(verified.token);

  // The caller's own account, with the patient and doctor relations loaded.
  final User me = await client.me.show();

  // The patient profile. `nik` and `nomor_kk` arrive MASKED; see section 8.
  final PasienProfile profile = await client.pasien.profil();

  // The public directory. Unauthenticated: it works with no session at all.
  final Paginated<DokterListing> doctors = await client.dokter.index(
    spesialisasi: 'kardiologi',
    tersediaTelemedisin: true,
    page: const PageQuery(page: 1, perPage: 20),
  );

  // A doctor, their schedule and their bookable slots. All unauthenticated.
  final DokterDetail detail = await client.dokter.show('7');
}
```

### 13.2 Module 2 -- schedules, slots, booking

All four are authenticated-or-public as the route table says: the directory and
the slot reads are public, and the booking writes need a token and a
`booking.buat` grant. They go through `client.transport`; the path constants come
from the generated table.

```dart
Future<void> main() async {
  // GET /api/v1/dokter/{dokter}/jadwal - public. The doctor is the path
  // segment, so it is NOT a query parameter; this call takes none.
  final ApiEnvelope<Object?> jadwal = await client.transport.get<Object?>(
    apiv1dokterdokterjadwal,
    anonymous: true,
    parseData: parseDataObject,
  );

  // GET /api/v1/dokter/{dokter}/slot - public. `tanggal` is the only query
  // parameter and it is REQUIRED. The server owns availability; render what it
  // returns and do not compute a second opinion.
  final ApiEnvelope<Object?> slots = await client.transport.get<Object?>(
    apiv1dokterdokterslot,
    queryParameters: <String, Object?>{'tanggal': '2026-10-05'},
    anonymous: true,
    parseData: parseDataObject,
  );

  // POST /api/v1/booking - authenticated, permission booking.buat.
  // `dokter_id`, `tipe_layanan`, `tanggal_kunjungan` and `slot_mulai` are
  // required. There is NO `slot_selesai` on the request -- the end time is the
  // schedule window's -- and sending one is a 422. `pasien_id` is prohibited:
  // the patient is the caller's own profile row. `tipe_layanan` is one of
  // `chat`, `video_call`, `kunjungan_klinik`, `home_visit`.
  final ApiEnvelope<Object?> booked = await client.transport.post<Object?>(
    apiv1booking,
    body: <String, Object?>{
      'dokter_id': 7,
      'jadwal_id': 31,
      'tipe_layanan': 'video_call',
      'tanggal_kunjungan': '2026-10-05',
      'slot_mulai': '17:00:00',
      'keluhan': 'Nyeri dada',
    },
    parseData: parseDataObject,
  );
}
```

### 13.3 Module 3 -- consultation, chat, medical record

```dart
Future<void> main() async {
  // POST /api/v1/konsultasi/mulai - the patient opens a consultation.
  // Exactly one of `booking_id` or `dokter_id`, plus `tipe` (one of `chat`,
  // `video_call`, `telepon`).
  await client.transport.post<Object?>(
    apiv1konsultasimulai,
    body: <String, Object?>{'booking_id': 55, 'tipe': 'chat'},
    parseData: parseDataObject,
  );

  // GET /api/v1/konsultasi/{id}/chat - the ONLY ascending list in the API.
  // `per_page` is the only query parameter: the consultation is in the path,
  // so `konsultasi_id` is NOT accepted here. The rows arrive under
  // `data.pesan`.
  final ApiEnvelope<Object?> history = await client.transport.get<Object?>(
    apiv1konsultasiidchat,
    queryParameters: <String, Object?>{'per_page': 50},
    parseData: parseDataObject,
  );

  // POST /api/v1/konsultasi/{id}/chat/baca - read receipts. The body is empty:
  // every field its FormRequest names is `prohibited`.
  await client.transport.post<Object?>(
    apiv1konsultasiidchatbaca,
    body: <String, Object?>{},
    parseData: parseDataObject,
  );
}
```

### 13.4 Module 4 -- reference data, drug catalogue, PDP consent

The fourteen `GET /api/v1/referensi/*` endpoints are the **only data endpoints
callable without a token**, alongside the doctor directory, the OTP/login calls
and the public letter verification. That is deliberate: a login-screen province
picker must work before the user has a token. The full enum vocabulary is also
baked into the package as compile-checked Dart enums in
`packages/sehatly_api_client/lib/src/generated/enums.dart`, so you can build
every dropdown from constants rather than string literals, and
[`docs/contract-conformance.md`](contract-conformance.md) is the PHP suite that
holds real responses to the published schemas.

```dart
Future<void> main() async {
  // GET /api/v1/referensi/provinsi - public, no token
  final ApiEnvelope<Object?> provinces = await client.transport.get<Object?>(
    apiv1referensiprovinsi,
    anonymous: true,
    parseData: parseDataObject,
  );

  // GET /api/v1/referensi/enums - the whole vocabulary in one call, public.
  final ApiEnvelope<Object?> vocabulary = await client.transport.get<Object?>(
    apiv1referensienums,
    anonymous: true,
    parseData: parseDataObject,
  );

  // GET /api/v1/obat/{id}/stok - authenticated. `{id}` is the drug; the only
  // query parameters are `apotek_id` and `jumlah`.
  final ApiEnvelope<Object?> stock = await client.transport.get<Object?>(
    apiv1obatidstok,
    queryParameters: <String, Object?>{'apotek_id': 3, 'jumlah': 10},
    parseData: parseDataObject,
  );

  // POST /api/v1/pdp/persetujuan - grant the consent a referral letter needs.
  // `versi_dokumen` is a STRING (max 20 characters), not a number.
  await client.transport.post<Object?>(
    apiv1pdppersetujuan,
    body: <String, Object?>{
      'jenis': 'berbagi_data_medis',
      'disetujui': true,
      'versi_dokumen': '2026-09-29',
    },
    parseData: parseDataObject,
  );
}
```

### 13.5 Module 5 -- prescriptions, checkout, payment

```dart
Future<void> main() async {
  // GET /api/v1/resep/{id}/cek-interaksi - advisory warnings, does not block.
  // No query parameters: the prescription is in the path. The warnings arrive
  // under `data.warning` and `data.warning_grup`, and `data.wajib_catatan`
  // says whether the pharmacist's note is mandatory.
  final ApiEnvelope<Object?> interactions = await client.transport.get<Object?>(
    apiv1resepidcekInteraksi,
    parseData: parseDataObject,
  );

  // POST /api/v1/resep/{id}/checkout - permission pesanan.buat. Every field is
  // optional; `items` is `prohibited` because the prescription already fixed
  // what is being bought.
  final ApiEnvelope<Object?> checkout = await client.transport.post<Object?>(
    apiv1resepidcheckout,
    body: <String, Object?>{'metode_id': 4, 'kode_promo': 'HEMAT10'},
    parseData: parseDataObject,
  );

  // POST /api/v1/invoice/{id}/bayar - permission pembayaran.bayar. The field is
  // `metode_id`, and the state changes asynchronously; poll
  // GET /api/v1/pesanan-obat/{id} after this rather than assuming it settled.
  final ApiEnvelope<Object?> paid = await client.transport.post<Object?>(
    apiv1invoiceidbayar,
    body: <String, Object?>{'metode_id': 4},
    parseData: parseDataObject,
  );
}
```

Every `POST` above that a network retry could duplicate carries
`allowUnsafeRetry` in the package's own endpoints. When you add one through
`client.transport`, pass `allowUnsafeRetry: true` **only** if the write is
idempotent; a retried non-idempotent write is worse than a surfaced 401.

### 13.6 Typed request bodies and enums

Two generated files remove the two most common integration bugs:

| file | what it gives you |
| --- | --- |
| `packages/sehatly_api_client/lib/src/generated/request_bodies.dart` | every write endpoint's required fields as constructor parameters, so a missing required field is a **compile error** rather than a 422 |
| `packages/sehatly_api_client/lib/src/generated/enums.dart` | a Dart `enum` for every MySQL `ENUM` column, in DDL declaration order, so an impossible status will not compile |

Both are regenerated by `php artisan sehatly:openapi` from the live route table
and drift-checked by `--check`, so neither can disagree with the server.

---

## 14. Troubleshooting

| symptom | cause | what to do |
| --- | --- | --- |
| 401 immediately after a successful OTP verify | the token pair was never persisted -- `verifyOtp` does not write storage | call `client.persistSession(verified.token)` |
| an infinite 401 loop | two `SehatlyApiClient` instances, so two `RefreshCoordinator`s, each rotating on its own | build the client once, in a binding, and reach it with `Get.find` |
| 401 on every request after a refresh that "worked" | you retried the refresh with the same token. The server revokes the presented refresh token on **every** use, and a reuse revokes **every** live refresh token for the account | treat a 401 from the refresh path as terminal. Clear storage and route to login; do not retry |
| 403 on a private channel subscription | the subscribe went out without an `Authorization` header, or to a session-authenticated route that answered a redirect or a 419 | use `GET|POST /api/broadcasting/auth` through the package's constant, with the bearer header, and strip the `private-` prefix from the channel name on the way in |
| a channel subscribes and never fires, with no error | you wrote `private-konsultasi.5` yourself, so the broker saw `private-private-konsultasi.5` | pass the **logical** name `konsultasi.5`; `RealtimeClient` adds the prefix |
| duplicate messages in a transcript | a re-subscribe replayed frames in flight, or a REST backfill returned rows already delivered live | dedupe on `konsultasi_chat.id`. The package's bounded set is 500 ids and it delivers a frame with no id rather than swallowing it |
| messages missing after a reconnect | **the broker does not replay.** Nothing was buffered for you | implement `onResync` and backfill over `GET /api/v1/konsultasi/{id}/chat` |
| every message in a transcript shows consultation `0` | you read `konsultasi` and the wire key is **`konsultasi_id`** | rename the key. There is a Dart test pinning it |
| an empty doctor list | the directory only lists doctors eligible for telemedicine, verified, with a matching specialisation or search term | check `tersedia_telemedisin` and the `spesialisasi` value against `GET /api/v1/referensi/spesialisasi`; an unverified doctor is filtered server-side and the client cannot see them |
| slots that never refresh | the page cached the slot list | re-read `GET /api/v1/dokter/{dokter}/slot` on focus, and after any failed booking, because a 409/422 on `slot` means the slot went |
| a booking refused with a `slot` error | someone else took it; active bookings reached `kuota_per_sesi` | offer the next slot. Do not retry the same one |
| a prescription looks live after its validity day | you compared dates locally | trust `is_kedaluwarsa` and `terminal`. Expiry is computed, never stored, and there is no database trigger |
| interaction warnings that look wrong | they are **advisory**; the server publishes them without blocking, and a `kontraindikasi` item requires a non-empty `catatan_dokter` | show them, and do not gate checkout on them |
| a verify button that will not submit | the prescription was already verified once, including a rejection | it is terminal. There is no resubmit path |
| `InvalidKeyException: Failed to unwrap key` after a reinstall | Android Auto Backup restored the ciphertext without the Keystore key | set `android:allowBackup="false"`; the value is unrecoverable |
| a 422 that shows half the reason | `errors[field]` is a **list** and one field can carry several messages | render every entry, not `[0]` |
| an OTP code that never matches | it was parsed as an integer, so a leading `0` was lost | `kode` is a `String` of exactly six digits |
| a 403 that will not clear | a referral letter needs an approved `berbagi_data_medis` PDP consent | start the consent flow; the letter request will not succeed before it |
| a payment that stays pending | the webhook is asynchronous | poll the order; note that the plan's `GET /api/v1/invoice/{id}` does not exist |
| a 429 with no visible wait | the client ignored the headers | read `Retry-After`; the server also sets `X-RateLimit-Limit` and `X-RateLimit-Remaining` on both sides of the boundary |
| the doctor directory works but every other call 401s | the directory is genuinely unauthenticated, so a working directory proves nothing about your token | check `client.currentAccessToken()` and that the interceptor is attached |
| an endpoint 404s that `route:list` says exists | the base URL already ends in the API prefix and the path added it a second time | pass group-relative paths, or a constant from the generated paths table |
| a 404 on a row the user is looking at | the row belongs to another account, and that is a 404 by design | remove it from the UI. A 403 would have confirmed it exists |
| a chat file rejected as a mime type | the allow-list is per `tipe_pesan` and is not a prefix rule | send a `dokumen` as one of the nine listed types, not as an arbitrary binary |

---

## Where the numbers come from

Every count in this document is read from the repository, not from the plan:

```powershell
& "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" artisan route:list --path=api/v1 --json |
  & "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" -r "echo count(json_decode(stream_get_contents(STDIN), true)), PHP_EOL;"
```

The plan and the route table disagree in places, and where they do this document
follows the route table and says so: the plan's error-catalogue list includes a
`204` the API never returns; the plan's server-rules table sends the client to
poll an invoice endpoint that is not registered. The generated contract
([`openapi.yaml`](openapi.yaml)) is the tie-breaker, and it is regenerated from
the live route table with a drift check that fails the build when it disagrees.
The remaining arithmetic, and the environment notes for this host, are in the
[module index](modules/README.md#angka) and in
[`docs/pre-existing-defects.md`](pre-existing-defects.md#1-toolchain); the bare
`php` on `PATH` is 8.2 and will not boot the application, which is why the command
above is absolute ([the README says so too](../README.md#the-php-binary-is-not-on-path)).

This document is checked by `node tools/check-doc-links.mjs`, which resolves
every relative link, every cross-document anchor, every repository path named in
prose, and every `/api` path against the live route table, and which hands every
fenced `dart` block to the Dart formatter.

```console
npm run docs:check
```
