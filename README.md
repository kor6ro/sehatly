# Sehatly

Telemedicine platform: a Laravel 13 API for appointments, video/text consultations,
prescriptions and clinic administration; a React SPA in `web/`; and a pure-Dart
client package for the mobile team.

The API surface is **78 routes** under `/api/v1`. Every one of them is published
as an OpenAPI 3.1 document that is *generated from the running application*, not
maintained by hand, and a drift check fails the build if the two disagree.

---

## Contents

- [Prerequisites](#prerequisites)
- [The PHP binary is not on PATH](#the-php-binary-is-not-on-path)
- [Setup](#setup)
- [Running it](#running-it)
- [The test suites](#the-test-suites)
- [The generated contract](#the-generated-contract)
- [No Flutter app exists in this repository](#no-flutter-app-exists-in-this-repository)
- [For the mobile team](#for-the-mobile-team)
- [`build.cssMinify: 'esbuild'` is required, not a preference](#buildcssminify-esbuild-is-required-not-a-preference)
- [Documentation index](#documentation-index)
- [CI](#ci)

---

## Prerequisites

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | **8.4.17** (8.3+ satisfies `composer.json`) | With the `pdo_mysql` extension. Built and verified on 8.4.17 |
| Composer | 2.x | `composer.json:12` requires `php: ^8.3` |
| MySQL | **8.0** | The schema uses `ENUM`, `CHECK` and generated columns; SQLite is not a substitute |
| Node.js | **22.12+** or 20.19+ | Vite 8's floor. Verified on 24.13.1 |
| npm | 10+ | Ships with Node. Verified on 11.17.0 |
| Dart SDK | **3.13.x** (stable) | Standalone. Verified on 3.13.2. **No Flutter SDK is needed or used** |

### The PHP binary is not on PATH

On the machine this was built on, the global `php` on `PATH` is **8.2.29**, which
does **not** satisfy `composer.json`'s `php: ^8.3`. The correct binary is:

```console
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe
```

Every command in this README that invokes PHP assumes you either put that
directory on `PATH` or prefix the command with the absolute path:

```console
# Either:
$env:PATH = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64;$env:PATH"

# Or, per command:
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe artisan serve
```

`composer.json`'s `@php` scripts use whichever `php` Composer resolves, so the
same substitution is needed there.

The Dart SDK is likewise **not on `PATH`**. It is installed at:

```console
C:\Users\axioo\AppData\Local\Microsoft\WinGet\Packages\Google.DartSDK_Microsoft.Winget.Source_8wekyb3d8bbwe\dart-sdk\bin\dart.exe
```

And `jq` is **not installed** on this host, so the plan's `route:list | jq length`
is written everywhere as the portable equivalent:

```console
php artisan route:list --path=api/v1 --json | php -r "echo count(json_decode(stream_get_contents(STDIN), true)), PHP_EOL;"
```

---

## Setup

```console
composer install
npm --prefix web install --ignore-scripts
npm --prefix tools/openapi-types install        # the OpenAPI type generator
npm run build
```

Or `composer run setup`, which does the first four steps in order.

### Create the databases

Two are needed. `telemedisin_db` is the development database; `telemedisin_db_test`
is what the suite runs against, and `phpunit.xml` pins it by name.

```console
php artisan db:create-test-database
```

That command creates `telemedisin_db_test` with `utf8mb4 / utf8mb4_unicode_ci` if
it is missing, and is a no-op when it already exists. Create `telemedisin_db`
yourself (`CREATE DATABASE telemedisin_db CHARACTER SET utf8mb4 COLLATE
utf8mb4_unicode_ci;`), then:

```console
php artisan migrate:fresh --seed
```

### Environment

```console
cp .env.example .env
php artisan key:generate
```

Three values in `.env.example` are **not** optional:

| Variable | Why |
| --- | --- |
| `NIK_CIPHER_KEY` | Encrypts `pasien.nik` at rest and builds its blind index. Deliberately **not** derived from `APP_KEY`: coupling them would re-derive every index whenever the app key rotates. Any read or write of a NIK column raises while it is unset. |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | Produced by `php artisan reverb:install`. `REVERB_APP_SECRET` signs broadcast authorization; anything holding it can authorize any channel. |
| `BROADCAST_CONNECTION=reverb` | Already set in `.env.example`. `BROADCAST_CONNECTION=null` disables realtime; the REST chat path still works without it, which is the point. |

---

## Running it

Four processes. Each in its own terminal.

```console
php artisan serve                       # http://127.0.0.1:8000
php artisan reverb:start                # ws://localhost:8080
npm run dev                             # Vite dev server for web/
```

`php artisan dev` runs the API and Reverb together in one process. The Vite dev
server proxies `/api` to `http://localhost:8000`.

---

## The test suites

```console
php artisan test                        # 1283 tests, 23971 assertions
npm run types:check                     # tsc --noEmit over web/, including the generated types
npm run build                           # the SPA production build
npm --prefix web run test:unit          # 14 web unit tests
cd packages/sehatly_api_client
dart analyze                            # no issues
dart test                               # 132 tests
```

`php artisan test` needs `telemedisin_db_test` to exist and to be migrated. The
`Unit` suite does **not** use `RefreshDatabase`, so an unmigrated test database
surfaces as eight unrelated failures rather than as one obvious cause.

---

## The generated contract

Four artefacts are generated from the live route table, and a drift check fails
the build if any of them is stale:

| Artefact | Produced by | From |
| --- | --- | --- |
| `docs/openapi.yaml` | `php artisan sehatly:openapi` | `Route::getRoutes()` + each `FormRequest::rules()` |
| `packages/sehatly_api_client/lib/src/generated/enums.dart` | the same command | the same walk, plus `docs/enums.json` |
| `.../request_bodies.dart` | the same command | the same walk |
| `.../paths_table.dart` | the same command | the same walk |
| `web/src/types/api.d.ts` | `npm run types:generate` | `docs/openapi.yaml` |

```console
php artisan sehatly:openapi          # rewrite every generated file
php artisan sehatly:openapi --check  # exit 1 if any is stale; writes nothing
composer run contract                # enums --check + openapi --check
npm run types:generate               # regenerate api.d.ts from the document
```

**Nothing in `docs/openapi.yaml` is transcribed.** Paths, methods, middleware,
status codes and request rules are read from the route table and from each
controller method's injected `FormRequest`, so a route added, renamed or removed
changes the document on the next run — and `--check` fails until the regenerated
file is committed.

### The drift check can fail, and that is demonstrated

A check that cannot fail is a file copy. `OpenApiCommandTest` therefore proves it
red in the same run it proves it green:

- `the committed document is a fresh export` — the GREEN control.
- `a hand-edit to one path in docs/openapi.yaml is DETECTED and refused` — renames
  one published path, asserts exit 1, asserts the `DRIFT` line and the byte
  offset, asserts the file was **not** repaired, then restores and asserts green.
- `the generated Dart files are a fresh export, and a hand-edit to one is DETECTED`
  — the same, for `paths_table.dart`.
- `a mutated route table -- a POST with no FormRequest -- IS detected` — registers
  a violating route in memory, asserts the command refuses to publish, removes it.

Drift is a **byte-level** SHA-256 comparison, and two exports over an unchanged
route table are byte-identical (`157f20a94785670b9633ff3f535ab3b4a614c541394d8f2e29d8557db9862d4f`,
302337 bytes, asserted by the suite on every run).

### The command also enforces the Definition of Done

Every `POST`/`PUT`/`PATCH` must validate through a `FormRequest`, read from the
controller method's signature. An endpoint that validates inline fails
`sehatly:openapi` at generation time, which is the cheapest moment to find out.

Three routes are exempt, each with a written reason recorded in
`app/Support/OpenApi/RouteInventory.php`, and the test suite asserts the list
cannot grow silently:

| Route | Why |
| --- | --- |
| `POST /api/v1/webhook/payment/{gateway}` | A payment gateway holds no Sanctum token. The body is HMAC-SHA256-verified, not field-validated. |
| `PUT /api/v1/notifikasi/{id}/baca` | No body: `{id}` is a path parameter and the write is a `dibaca_at` transition on a row the caller's own `user_id` selects. |
| `PUT /api/v1/notifikasi/baca-semua` | No body: the same bulk transition over every unread row. |

### What the document does and does not claim

Exact: the `{success, data, message}` envelope, `meta` as a **top-level fourth
key** (not nested in `data`), `errors` as `field -> array of messages` (a field can
fail more than one rule), the Sanctum bearer scheme, every status code, every
`permission:`/`tipe:` guard, the rate limits, and the request rules.

Untyped: every response's `data`. Deriving a response shape would mean running the
application. The hand-written DTOs in
`packages/sehatly_api_client/lib/src/model/dto.dart` are the response side, each
naming the `App\Http\Resources\*` class it was transcribed from.

Two things `openapi-typescript` 7.13.0 does **not** project into `api.d.ts`, so
they are checked in the PHP suite instead where the document itself is read:
`minItems: 1` (so an `errors` value is `string[]`, not a non-empty tuple) and
per-operation `security`.

---

## No Flutter app exists in this repository

There is no `mobile/` directory and no `flutter:` SDK constraint anywhere. The
mobile entry point is **`packages/sehatly_api_client`** plus `docs/modules/`.

`packages/sehatly_api_client` is a **pure-Dart** package: `sdk: '>=3.13.0 <4.0.0'`
with no `flutter:` constraint, so it resolves, analyzes and tests with a bare
`dart` binary on a machine that has no Flutter — and it is importable from any
Flutter app the mobile team writes. Its only runtime dependency is `dio`.

`tests/Unit/NoFlutterMobileTest.php` enforces this, because `test ! -e mobile` was
the one plan guardrail that had no automatic proof. It walks every `pubspec.yaml`
in the repository and asserts:

1. no `mobile/` directory or file at the root;
2. no `pubspec.yaml` declares a `flutter:` / `flutter_test:` / `flutter_web_plugins:`
   SDK constraint;
3. no `pubspec.yaml` depends on `flutter`, `flutter_test` or `flutter_web_plugins`;
4. the pure-Dart client **still depends on `dio`** and still wraps it.

Point 4 matters in both directions: a "no Flutter" test satisfied by gutting the
package is not a guard, it is vandalism. The guard was verified red — a probe
`pubspec.yaml` with a `flutter:` constraint turns two of the four tests red, and a
bare `mobile/` directory turns the first red. Both removed.

---

## For the mobile team

- **Use `get: ^4.7.3`.** GetX 5.0-rc deprecates the bindings API (`Bindings`,
  `Get.lazyPut`, `GetxController`) that every example in `docs/modules/` is
  written against, so taking 5.x-rc now means rewriting the call sites later.
- `packages/sehatly_api_client/README.md` carries a copy-pasteable `pubspec.yaml`
  dependency block, a bootstrap example, the base-URL/environment table, and the
  OWASP MASVS-STORAGE-1 reasoning for the token-storage split.
- **The concrete secure storage and push implementations live in your app, not
  here.** `TokenStore` and `PushTokenProvider` are interfaces in the package;
  `flutter_secure_storage` and `firebase_messaging` are Flutter plugins, and
  depending on them would make the package untestable without the Flutter SDK.
  Both adapters are copy-pasteable blocks in that README.
- `lib/src/generated/request_bodies.dart` gives every write endpoint a typed
  request with its required fields as constructor parameters, so a missing
  required field is a compile error rather than a 422. `enums.dart` gives every
  MySQL ENUM column a Dart `enum` in DDL declaration order, so an impossible
  status value will not compile.
- `lib/src/generated/paths_table.dart` holds every registered path as a constant.

---

## `build.cssMinify: 'esbuild'` is required, not a preference

`web/vite.config.ts` sets `build.cssMinify: 'esbuild'` explicitly. **Vite 8 changed
the default from `esbuild` to Lightning CSS**, and Lightning CSS strips vendor
prefixes. The shadcn/Radix `sidebar`, `dialog`, `dropdown-menu`, `sheet` and
`sonner` components all rely on the unprefixed `backdrop-filter`, so with the Vite 8
default they render with **no blur at all** — a silent visual regression that no
type check and no test catches.

---

## Documentation index

### Module summaries

| Document | Covers |
| --- | --- |
| [`docs/modules/modul-1-ringkasan.md`](docs/modules/modul-1-ringkasan.md) | Module 1 overview |
| [`docs/modules/modul-1-auth.md`](docs/modules/modul-1-auth.md) | Register, OTP, login, refresh, devices |
| [`docs/modules/modul-1-dokter.md`](docs/modules/modul-1-dokter.md) | Doctor directory, schedules, slots |
| [`docs/modules/modul-1-pasien.md`](docs/modules/modul-1-pasien.md) | Patient profile, family, allergies |
| [`docs/modules/modul-2-jadwal-booking.md`](docs/modules/modul-2-jadwal-booking.md) | Scheduling and booking |
| [`docs/modules/modul-3-konsultasi-rekam-medis.md`](docs/modules/modul-3-konsultasi-rekam-medis.md) | Consultation and medical records |
| [`docs/modules/README.md`](docs/modules/README.md) | Index and cross-references |

### Contract and schema

| Document | What it is |
| --- | --- |
| [`docs/openapi.yaml`](docs/openapi.yaml) | **Generated.** The OpenAPI 3.1 contract for all 78 `/api/v1` routes |
| [`docs/enums.json`](docs/enums.json) | **Generated.** Every ENUM column and value, cross-checked against the DDL |
| [`docs/schema-notes.md`](docs/schema-notes.md) | Deferred constraints, extra tables, known schema defects |
| [`docs/migration-order.md`](docs/migration-order.md) | Why the migrations are in this order |
| [`docs/pre-existing-defects.md`](docs/pre-existing-defects.md) | Defects found in the scaffold, before this work |

`docs/mobile-integration.md` and `docs/contract-conformance.md` are named by the
plan and belong to later todos; they are not in this repository yet, and this
index does not pretend otherwise.

---

## CI

`.github/workflows/contract.yml` runs three jobs:

- **generated contract is fresh** — `sehatly:enums --check`, `sehatly:openapi
  --check`, regenerates `api.d.ts` and fails if it differs from the committed copy,
  then `types:check`, `build`, `dart analyze`, `dart test`, and the no-Flutter
  guard.
- **PHP suite and schema parity** — `migrate:fresh --seed`, `pint --test`,
  `sehatly:verify-schema`, `artisan test`.
- **web suite** — `types:check`, the web unit tests, `build`.

Every step writes nothing on the contract side: `--check` renders in memory and
compares SHA-256, so a stale file fails loudly instead of being silently repaired.

---

## Known gaps

Stated rather than hidden:

- `dart format --set-exit-if-changed` is **not** clean on
  `packages/sehatly_api_client/lib/src/generated/`. The generator emits one
  collection element per line for byte determinism rather than reproducing the
  formatter's cost model, and `--check` depends on those bytes being stable.
  `dart analyze` is clean and is the enforced gate. Reasoning is in
  `app/Support/OpenApi/DartContractGenerator.php`.
- The three `FormRequest` exemptions above are exemptions to the Definition of
  Done, not endorsements. `PUT /notifikasi/{id}/baca` would be better served by a
  `FormRequest` with an empty `rules()` if the DoD ever stops being negotiable.
