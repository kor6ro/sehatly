# Sehatly

Telemedicine platform: a Laravel 13 API for appointments, video/text consultations,
prescriptions and clinic administration; and a React SPA in `frontend/`.

The API surface is **111 routes** under `/api/v1`. Every one of them is published
as an OpenAPI 3.1 document that is *generated from the running application*, not
maintained by hand, and a drift check fails the build if the two disagree.

---

## Contents

- [Repository layout](#repository-layout)
- [Prerequisites](#prerequisites)
- [The PHP binary is not on PATH](#the-php-binary-is-not-on-path)
- [Setup](#setup)
- [Running it](#running-it)
- [The test suites](#the-test-suites)
- [The generated contract](#the-generated-contract)
- [`build.cssMinify: 'esbuild'` is required, not a preference](#buildcssminify-esbuild-is-required-not-a-preference)
- [Documentation index](#documentation-index)
- [CI](#ci)

---

## Repository layout

**The repository root is the backend.** `app/`, `routes/`, `config/` and the rest are
Laravel's own directory layout and cannot be relocated without fighting the framework.
The front end is one directory beside them, and everything else is tooling or
documentation.

| Path | What it is |
| --- | --- |
| `app/` `bootstrap/` `config/` `database/` `routes/` `public/` `storage/` | **Backend.** The Laravel 13 API — 131 routes under `/api/v1`. |
| `resources/views/app.blade.php` | **Backend.** One Blade file, rendered *only* when the SPA has not been built, as a deliberate 503 rather than a placeholder that lies. |
| `frontend/` | **Front end.** The React SPA: `frontend/src`, its own `index.html`, Vite 8 config and dependency list. Builds to `frontend/dist` (gitignored), which `routes/web.php` serves as the shell. Also holds `frontend/tests/` (Playwright e2e) and `frontend/ux/` (the F01–F14 design research corpus). |
| `tests/` | The PHP suite (Pest). |
| `docs/` | The generated OpenAPI contract, the ENUM catalogue, and the decision documents. |
| `docs/assets/` | Brand assets: the Sehatly logo lockup and the icon, as PNG and SVG. |
| `tools/openapi-types/` | Generates `frontend/src/types/api.d.ts` from `docs/openapi.yaml`. Its own toolchain on purpose: it pins TypeScript 5 while `frontend/` compiles against TypeScript 7, and npm cannot satisfy both in one tree. |
| `tools/check-doc-links.mjs` | Checks markdown links, route references and fenced code blocks across the documentation. |
| `vendor/` `node_modules/` `frontend/dist/` | Installed or built artifacts. Gitignored. |
| `package.json` (root) | Delegates every script into `frontend/` and `tools/`, so `npm run build` from the root still means "build the SPA". It declares **zero** dependencies of its own; its lockfile exists only because CI runs `npm ci` at the root. |
| `telemedicine_test.sql` | The reference DDL. **Read-only**: migrations must reproduce it, and a test locks its bytes. Never edit, reformat or reorder it. |

A request takes one path:

```
browser  →  frontend/dist/index.html   (SPA shell, served by routes/web.php)
         →  GET /api/v1/...            (the Laravel API, routes/api.php)
         →  MySQL
```

---

## Prerequisites

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | **8.4.17** (8.3+ satisfies `composer.json`) | With the `pdo_mysql` extension. Built and verified on 8.4.17 |
| Composer | 2.x | `composer.json:12` requires `php: ^8.3` |
| MySQL | **8.0** | The schema uses `ENUM`, `CHECK` and generated columns; SQLite is not a substitute |
| Node.js | **22.12+** or 20.19+ | Vite 8's floor. Verified on 24.13.1 |
| npm | 10+ | Ships with Node. Verified on 11.17.0 |

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

And `jq` is **not installed** on this host, so the plan's `route:list | jq length`
is written everywhere as the portable equivalent:

```console
php artisan route:list --path=api/v1 --json | php -r "echo count(json_decode(stream_get_contents(STDIN), true)), PHP_EOL;"
```

---

## Setup

```console
composer install
npm --prefix frontend install --ignore-scripts
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
npm run dev                             # Vite dev server for frontend/
```

`php artisan dev` runs the API and Reverb together in one process. The Vite dev
server proxies `/api` to `http://localhost:8000`.

---

## Multi-portal (6 portal)

Satu build SPA yang disajikan dari enam hostname; hostname-lah satu-satunya
yang berbeda. Kode, origin API, dan token sama untuk semuanya. Portal yang
dipakai ditentukan sekali saat pemuatan modul melalui `detectPortal()`
(`frontend/src/lib/portal.ts`), jadi navigasi sisi klien menjaga portal yang
sama — `?portal=` diperlukan hanya untuk permintaan dokumen pertama.

| Hostname | Portal | Tipe akun yang diizinkan |
|----------|--------|--------------------------|
| `sehatly.com` / `sehatly.test` | Pasien | `pasien` |
| `admin.sehatly.com` / `admin.sehatly.test` | Admin klinik | `admin`, `superadmin` |
| `dokter.sehatly.com` / `dokter.sehatly.test` | Dokter | `dokter` |
| `apotek.sehatly.com` / `apotek.sehatly.test` | Apoteker | `apoteker` |
| `kurir.sehatly.com` / `kurir.sehatly.test` | Kurir | `kurir` |
| `marketing.sehatly.com` / `marketing.sehatly.test` | Marketing | `marketing` |

Semua portal mengarah ke API yang sama di `/api/v1`. Setiap rute API
dikelilingi oleh guard `tipe:` + `permission:` (backend adalah otoritas
keamanan; frontend hanya UX), dan setiap portal punya halaman login sendiri
— tidak ada dialog publik untuk admin/dokter/dll.

Jalur serve (lihat juga `resources/views/app.blade.php` dan `routes/web.php`):

- SPA sudah build (`frontend/dist/index.html` ada): 200 dengan shell
  asli, deep link seperti `/dashboard/anything` boot router.
- SPA belum build: **503** dengan perintah build, bukan halaman placeholder
  200 yang boysong. API tetap tersedia di `/api/v1`.

Sebelum produksi, jalankan `npm run build` di `frontend/` (produksi
secara langsung biasanya mengalihkan ke `frontend/dist`).

---

## The test suites

```console
php artisan test                        # the PHP suite
npm run types:check                     # tsc --noEmit over frontend/, including the generated types
npm run build                           # the SPA production build
npm --prefix frontend run test:unit     # web unit tests
```

`php artisan test` needs `telemedisin_db_test` to exist and to be migrated. The
`Unit` suite does **not** use `RefreshDatabase`, so an unmigrated test database
surfaces as eight unrelated failures rather than as one obvious cause.

---

## The generated contract

Two artefacts are generated from the live route table, and a drift check fails
the build if either is stale:

| Artefact | Produced by | From |
| --- | --- | --- |
| `docs/openapi.yaml` | `php artisan sehatly:openapi` | `Route::getRoutes()` + each `FormRequest::rules()` |
| `frontend/src/types/api.d.ts` | `npm run types:generate` | `docs/openapi.yaml` |

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
- `a mutated route table -- a POST with no FormRequest -- IS detected` — registers
  a violating route in memory, asserts the command refuses to publish, removes it.

Drift is a **byte-level** SHA-256 comparison, and two exports over an unchanged
route table are byte-identical (`c8881622c57cd8048bd86c5c132cac7028f8564d5d0e793f1a9f742590224fce`,
399719 bytes, asserted by the suite on every run).

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
application. Read it against the matching `app/Http/Resources/*` class, which is
what the endpoint actually returns.

Two things `openapi-typescript` 7.13.0 does **not** project into `api.d.ts`, so
they are checked in the PHP suite instead where the document itself is read:
`minItems: 1` (so an `errors` value is `string[]`, not a non-empty tuple) and
per-operation `security`.

---

## No Flutter app in this repository

There is no `mobile/` directory and no `flutter:` SDK constraint anywhere. This
repository is a Laravel API plus a React SPA; a mobile app would be a new
top-level application arriving on its own, not a folder someone adds quietly.

CI keeps that true with three commands in `.github/workflows/contract.yml`:
`test ! -e mobile`, plus two greps over every `pubspec.yaml` for a `flutter:`
constraint or a `flutter` dependency. The API itself stays client-agnostic — a
mobile client integrates over the same REST surface the SPA uses, which
`docs/mobile-integration.md` describes endpoint by endpoint.

---

## `build.cssMinify: 'esbuild'` is required, not a preference

`frontend/vite.config.ts` sets `build.cssMinify: 'esbuild'` explicitly. **Vite 8 changed
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
| [`docs/openapi.yaml`](docs/openapi.yaml) | **Generated.** The OpenAPI 3.1 contract for all 111 `/api/v1` routes |
| [`docs/enums.json`](docs/enums.json) | **Generated.** Every ENUM column and value, cross-checked against the DDL |
| [`docs/schema-notes.md`](docs/schema-notes.md) | Deferred constraints, extra tables, known schema defects |
| [`docs/migration-order.md`](docs/migration-order.md) | Why the migrations are in this order |
| [`docs/pre-existing-defects.md`](docs/pre-existing-defects.md) | Defects found in the scaffold, before this work |
| [`docs/mobile-integration.md`](docs/mobile-integration.md) | Integrating against the API from any client: auth, errors, pagination, realtime, push |
| [`docs/contract-conformance.md`](docs/contract-conformance.md) | How the running application is checked against its own published contract |
| [`docs/verification-report.md`](docs/verification-report.md) | What was verified, how, and what was not |
| [`docs/timezone-policy.md`](docs/timezone-policy.md) | The date and time rules, and why they are not left to the ORM |
| [`docs/assets/`](assets/) | The Sehatly logo lockup and the icon, as PNG and SVG |

---

## CI

`.github/workflows/contract.yml` runs three jobs:

- **generated contract is fresh** — `sehatly:enums --check`, `sehatly:openapi
  --check`, regenerates `api.d.ts` and fails if it differs from the committed copy,
  then `types:check`, `build`, and the no-Flutter guard.
- **PHP suite and schema parity** — `migrate:fresh --seed`, `pint --test`,
  `sehatly:verify-schema`, `artisan test`.
- **web suite** — `types:check`, the web unit tests, `build`.

Every step writes nothing on the contract side: `--check` renders in memory and
compares SHA-256, so a stale file fails loudly instead of being silently repaired.

---

## Known gaps

Stated rather than hidden:

- The three `FormRequest` exemptions above are exemptions to the Definition of
  Done, not endorsements. `PUT /notifikasi/{id}/baca` would be better served by a
  `FormRequest` with an empty `rules()` if the DoD ever stops being negotiable.
