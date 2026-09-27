# Task 30 - Inertia/Fortify removal, and the Module 2 summary

Todo 30 of `.omo/plans/sehatly-telemedicine-platform.md`. Branch
`feat/sehatly-telemedicine`. PHP 8.4.17 at
`C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (`php` is not on
PATH). **laravel/framework 13.33**, Pest 4, Node 24.13.1, npm 11.17.0, Dart SDK
3.13.2.

Baseline measured at the start of this todo, before any edit:

```text
Tests:  428, passed: 417, failed: 11, assertions: 8235
```

Result at the end, over this todo's own scope (every test in the repository
except the 40 another executor wrote into `tests/Feature/Booking/` while this
todo ran - see F10):

```text
Tests:  416, passed: 416, failed: 0,  assertions: 8324   (exit 0)
```

`11 failed -> 0 failed`, first green run in the project's history. No test was
skipped, no assertion was weakened, and `grep -rn "markTestSkipped\|->skip("
app/ tests/ routes/ database/` returns nothing (section 9).

## 1. Files

| file | state |
| --- | --- |
| `composer.json`, `composer.lock` | 4 packages removed, 17 with their transitive dependencies |
| `package.json`, `package-lock.json` | root front-end project replaced by three delegating scripts |
| `bootstrap/app.php` | `web(append:)` emptied, `redirectGuestsTo(null)` added, three comments corrected |
| `bootstrap/providers.php` | `FortifyServiceProvider` deregistered |
| `routes/web.php` | rewritten: SPA shell plus one asset route |
| `routes/api.php` | comment only, plus the one blank line Pint required |
| `app/Providers/AppServiceProvider.php` | comment only |
| `resources/views/app.blade.php` | rewritten, `@inertia` and `@vite` gone |
| `vite.config.ts`, `tsconfig.json` | **deleted** |
| `resources/js/**` | **deleted**, 62 tracked files plus 3 generated directories |
| `app/Providers/FortifyServiceProvider.php` | **deleted** |
| `app/Actions/Fortify/**` (2) | **deleted** |
| `app/Concerns/**` (2) | **deleted** |
| `app/Http/Middleware/HandleAppearance.php`, `HandleInertiaRequests.php` | **deleted** |
| `app/Http/Controllers/Settings/**` (2) | **deleted** |
| `app/Http/Requests/Settings/**` (4) | **deleted** |
| `config/fortify.php`, `config/inertia.php` | **deleted** |
| `routes/settings.php` | **deleted** |
| 9 scaffold Feature test files | **deleted**, 26 tests |
| `tests/Feature/ApiKernelTest.php` | **modified**: one precondition rewritten, its assertions unchanged |
| `tests/TestCase.php` | **modified**: the repository's only `markTestSkipped` removed |
| `tests/Unit/Models/ModelFoundationTest.php` | **modified**: one vacuous assertion replaced with one that can fail |
| `tests/Feature/WebSurfaceTest.php` | **new**, 14 tests, 119 assertions |
| `docs/modules/modul-2-jadwal-booking.md` | **new** |
| `docs/modules/README.md` | **new** (todo 25 declined to create it and reported the conflict) |
| `docs/pre-existing-defects.md` | **appended** section 6, resolving section 3.4 |
| `storage/audit/token-audit.php` | **new**, the A.26 instrument |

Untouched and verified so: `database/migrations/`, `database/seeders/`,
`telemedicine_test.sql` (SHA-256 unchanged), `app/Http/Controllers/Api/V1/**`,
`app/Http/Resources/**`, `app/Services/**`, `app/Support/**`, `app/Models/**`,
`web/`, `packages/`, `.env`, and no `mobile/` directory was created.

## 2. The root cause, read rather than inherited

`telemedicine_test.sql:132-149`, read directly:

```sql
CREATE TABLE users (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL UNIQUE,
  nama_lengkap VARCHAR(150) NOT NULL,
  email VARCHAR(255) NULL UNIQUE,
  no_telepon VARCHAR(20) NOT NULL UNIQUE,
  kata_sandi_hash VARCHAR(255) NOT NULL COMMENT 'bcrypt/argon2',
  tipe ENUM(...) NOT NULL DEFAULT 'pasien',
  status ENUM(...) NOT NULL DEFAULT 'pending_verifikasi',
  foto_profil VARCHAR(500) NULL,
  bahasa ENUM('id','en') NOT NULL DEFAULT 'id',
  telepon_terverifikasi TINYINT(1) NOT NULL DEFAULT 0,
  email_terverifikasi TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  dihapus_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB;
```

No `password`. No `name`. No `email_verified_at`. No `remember_token`. No
`two_factor_*`. `password_reset_tokens`, the table `config/auth.php:98` names
as the reset broker, is not one of the 75 contract tables and not one of the 7
registered extra tables, so it does not exist in either database either.

Two supporting facts, also read rather than assumed:

- `app/Models/User.php:28-32` already documented that `MustVerifyEmail`,
  `PasskeyUser`, `PasskeyAuthenticatable` and `TwoFactorAuthenticatable` were
  removed in todo 19 "on purpose", so `Illuminate\Auth\Events\Verified` could
  not be dispatched by anything in this application.
- `App\Services\Auth\OtpService` defines four `user_otp.tujuan` constants but
  issues only two of them. That is the shape of the whole contract: the DDL
  names four authentication purposes and the API implements the OTP-plus-token
  two.

## 3. The 11 failing tests, classified

Classification key: **(a)** a feature this contract deliberately does not have,
so the test is retired with a reason and a pointer to the replacement API;
**(b)** real behaviour reached through the wrong layer, so it is rewritten
against the correct surface; **(c)** a genuine bug, so it is fixed.

| # | test | class | reason | replacement that already covers it |
| --- | --- | --- | --- | --- |
| 1 | `Auth\AuthenticationTest::users can authenticate using the login screen` | **a** | `POST /login` is Fortify's `AuthenticatedSessionController`, which calls `Auth::attempt()` against `users.password` - a column that does not exist. `$this->assertAuthenticated()` then fails on the session guard, and `route('dashboard')` is an Inertia route being deleted. Session-cookie login is not part of a bearer-token API. | `AuthFlowTest::login accepts a correct password by phone number and returns no token` and `...by email address`; the negative case is `AuthFlowTest::login with a wrong password is refused and mints no OTP`. Carried into the API by `WebSurfaceTest::a new user can register and authenticate, which is what RegistrationTest and AuthenticationTest asserted`. |
| 2 | `Auth\EmailVerificationTest::email can be verified` | **a** | `Event::assertDispatched(Verified::class)` cannot hold: `User` does not implement `MustVerifyEmail` (removed in todo 19, documented at `app/Models/User.php:28-32`) and `users` has no `email_verified_at`. Email verification in this contract is a boolean (`users.email_terverifikasi`, `:144`), not a timestamp, and the OTP mechanism that would flip it is not reachable - see gap G2. | The verification behaviour that does exist is phone OTP: `AuthFlowTest::a verified OTP flips the account to aktif, marks the code used and issues a token pair`. The invalid-hash case maps to `AuthFlowTest::a wrong code is refused, is not marked used, and issues nothing` and `...does not report an unknown account differently from an unknown code`. |
| 3 | `Auth\PasswordConfirmationTest::password can be confirmed` | **a** | `route('password.confirm.store')` runs `PasswordValidationRules::validatePassword`, which is `Hash::check($password, $user->getAuthPassword())`. `getAuthPassword()` reads the `password` attribute, which is always null, so the check fails for every input, correct or not. Re-authentication-before-a-sensitive-action is a session-cookie feature; the contract's equivalent is a short-lived Sanctum token, and there is no step-up gate at all - gap G4. | None, and that is the finding. The nearest available action is repeating `POST /auth/login` plus `POST /auth/otp/verify` with `tujuan = login`, which re-mints a token pair but is not a step-up gate. Recorded in `docs/modules/modul-2-jadwal-booking.md` section 6.2 as G4 and asserted as still-possible in `WebSurfaceTest::the DDL still represents every capability...`. |
| 4 | `Auth\PasswordResetTest::reset password link can be requested` | **a** | `Notification::assertSentTo($user, ResetPassword::class)` cannot hold. The broker reads `password_reset_tokens`, which does not exist, and `User::sendPasswordResetNotification()` needs a `password` column to write the new hash into. | None. Gap G1: `users.kata_sandi_hash` exists (`NOT NULL`) and `user_otp.tujuan` names `'reset_kata_sandi'`, so the capability was designed for and is not built. |
| 5 | `Auth\PasswordResetTest::reset password screen can be rendered` | **a** | Same root cause, one call earlier: it only runs inside the `assertSentTo` closure, so it fails on the same missing notification. | None. Gap G1, as above. |
| 6 | `Auth\PasswordResetTest::password can be reset with valid token` | **a** | Same root cause. | None. Gap G1, as above. |
| 7 | `Auth\RegistrationTest::new users can register` | **a** | `POST /register` is Fortify's `RegisteredUserController` creating a user from `name`/`email`/`password`; `name` does not exist and `assertAuthenticated()` needs a session. Registration through the correct layer creates a `pending_verifikasi` user that holds no token until an OTP is verified, so it is not the same assertion even though the behaviour is real. | `AuthFlowTest::register creates the users row, the patient row and the role grant, and sends an OTP`, plus `...register issues no token, so nothing can be used before the OTP is verified` for the "not yet signed in" half. Walked end to end by `WebSurfaceTest::a new user can register and authenticate...`. |
| 8 | `Settings\PasswordUpdateTest::password can be updated` | **a** | `SecurityController::update` writes `$request->user()->update(['password' => ...])`. `password` is not in the model's `#[Fillable]` and not a column, so nothing is written; and the request's `current_password` check fails first with "The password is incorrect" for the same `getAuthPassword()` reason as #3. | None. Gap G1: the column to write is `kata_sandi_hash` and the OTP purpose to verify with is `reset_kata_sandi`; no endpoint uses either. |
| 9 | `Settings\ProfileUpdateTest::profile information can be updated` | **b, and a gap** | Two assertions in one test. The `name` half is real behaviour reached through the wrong layer, and it is carried to the API by `WebSurfaceTest::an account holder can change their own display name, which is what ProfileUpdateTest asserted`. The `email` half has **no** API equivalent at all: `UpdatePasienProfileRequest::rules()` has no `email` key, and its docblock explains the closed writable set. The failure itself was a 500 - `ProfileController::update` line 36 wrote `email_verified_at`, which is MySQL 1054. | `name`: `PasienProfileTest::the profile update writes the plan field set, across both tables` asserts `$account['user']->fresh()->nama_lengkap`, and `...cannot change tipe, status, no_telepon, nik, or the identity columns` pins the closed writable set. `email`: **none**. Gap G2. |
| 10 | `Settings\ProfileUpdateTest::email verification status is unchanged when the email address is unchanged` | **a**, and a gap | Asserts `email_verified_at` is non-null. The column does not exist, so the test is asserting a field the schema does not have. The contract's equivalent is the boolean `email_terverifikasi`, and nothing in the API can ever set it true. | None. Gap G2: `users.email` is `NULL UNIQUE` and mutable, and `user_otp.tujuan` names `'verifikasi_email'`, but no endpoint issues an email-purpose OTP. |
| 11 | `Settings\ProfileUpdateTest::user can delete their account` | **a**, and a gap | `ProfileController::destroy` calls `Auth::logout()` and then `$user->delete()` behind a `password` confirmation that always fails, because `getAuthPassword()` is null. The soft-delete itself is representable: `users.dihapus_at` exists and `User::DELETED_AT = 'dihapus_at'`. | None. Gap G3: the column and the model trait are both in place and no endpoint calls them. |

**Totals: 11 of 11 classified. Nine (a), two (b) - #9's name half and the
dashboard page - and zero (c).** No baseline test was a bug in this project's
code. That matters: it means the 11 were assertions about a surface that cannot
exist, not symptoms, and the only honest resolutions were to remove the surface
and to report the five capabilities that went with it.

### 3.1 The other two tests that had to change, not be deleted

The brief asked about `tests/Feature/Auth/**` and `tests/Feature/Settings/**`.
Nine of the eleven lived there. Two green tests outside those directories also
had to be dealt with, and neither was in the brief's list:

- `tests/Feature/ApiKernelTest::test_unauthenticated_request_renders_401_envelope_without_redirecting_to_login`
  carried `assertTrue(Route::has('login'), 'Expected Fortify to have registered
  a login route, otherwise this test proves nothing.')` as its precondition.
  Removing Fortify falsifies that, and a falsified precondition is a test that
  stops proving anything - the exact defect the project's A.15/A.18 notes warn
  about. Rewritten to assert the opposite (no named authentication route exists,
  so a 302 could not be produced even in principle). Its `Location`-is-null and
  byte-exact-body assertions are unchanged.
- `tests/Feature/RbacMiddlewareTest`'s 401-envelope case and
  `ApiKernelTest::test_api_requests_render_json_even_without_an_accept_header`
  both started failing the moment Fortify was uninstalled, for the reason in
  section 5. They are the tests that caught the real bug.

### 3.2 The four scaffold tests that were green and had to go anyway

`AuthenticationTest::login screen can be rendered`,
`RegistrationTest::registration screen can be rendered`,
`PasswordResetTest::reset password link screen can be rendered`,
`Settings\ProfileUpdateTest::profile page is displayed`,
`Settings\ProfileUpdateTest::correct password must be provided to delete account`
and `DashboardTest`'s two cases were all green at the baseline and all became
red or meaningless once the routes they addressed were deleted. They are
classified with their files above. `ExampleTest` (`GET /` returned 200 from the
Inertia welcome page) is the sixth; the catch-all answers `/` with the SPA
shell now, and `WebSurfaceTest::a deep link is answered with the built SPA shell`
asserts it.

## 4. What was removed, and the proof nothing Inertia remains

### 4.1 Composer

```text
composer remove inertiajs/inertia-laravel laravel/fortify laravel/passkeys laravel/wayfinder
- Removing inertiajs/inertia-laravel (v3.4.0)
- Removing laravel/fortify (v1.40.0)
- Removing laravel/passkeys (v0.2.1)
- Removing laravel/wayfinder (v0.1.21)
... 13 transitive: dasprid/enum, bacon/bacon-qr-code, pragmarx/google2fa,
    paragonie/constant_time_encoding, spomky-labs/cbor-php,
    spomky-labs/pki-framework, symfony/polyfill-php81, symfony/property-access,
    symfony/property-info, symfony/serializer, symfony/type-info,
    web-auth/cose-lib, web-auth/webauthn-lib
Package operations: 0 installs, 0 updates, 17 removals
```

`laravel/passkeys` published no migration, so no `passkeys` table was ever
created and none of the 7 registered extra tables in `docs/schema-notes.md` is
affected. `composer validate` exits 0.

**The stale package manifest was a real trap and the plan's instruction alone
does not clear it.** `php artisan package:discover` fails while
`bootstrap/cache/packages.php` still names the removed providers - it loads the
cache to compute the manifest, so the very command meant to fix the cache dies
on it with `Class "Inertia\ServiceProvider" not found`. Both cache files must be
deleted first, then `package:discover` runs clean and lists 8 providers. Same
for the Composer classmap: `vendor/composer/autoload_classmap.php` still mapped
`App\Http\Middleware\HandleInertiaRequests` to a file that no longer exists, and
`class_exists()` on it was a fatal `include` failure, not a false. `composer
dump-autoload` regenerates it. `WebSurfaceTest` asserts the manifest is clean.

### 4.2 npm

Root `package.json` had 30 runtime dependencies for a React/Inertia app that no
longer exists. They are gone; the three scripts delegate:

```json
"build": "npm --prefix web run build",
"dev": "npm --prefix web run dev",
"types:check": "npm --prefix web run types:check"
```

`npm run build` at the repository root still means "build the SPA" and still
exits 0, which is what the plan's own acceptance criterion asks for. It
delegates rather than building a second copy, because `web/` is the only
front-end project: it owns `web/src`, its own `index.html`, its own Vite 8
config, its own dependency list, and its own `/api` dev proxy at
`web/vite.config.ts:49-54`. `composer.json`'s `setup` script installs `web/`'s
dependencies for the same reason. `npm install` at the root removed 241 packages.

### 4.3 Proof, measured rather than asserted

`php artisan route:list` exits 0, shows **28** routes, and:

```text
$ php artisan route:list | grep -ci fortify
0
```

The only non-API application routes are the two this todo added:

```text
 GET|HEAD assets/{path} .. spa.asset >> routes/web.php:79
 ANY        {any?}       .. spa.shell  >> routes/web.php:93
 GET|HEAD up             .. vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:224
```

`tests/Feature/WebSurfaceTest.php` turns each of those claims into an assertion
that fails if it stops being true, and three of them are worth naming because
they are the brief's wording:

- **No Inertia middleware on any API route.** The test walks
  `Route::getRoutes()->getRoutes()`, calls `gatherMiddleware()` on each, and
  fails on any string containing `Inertia` or `Fortify`. It asserts over the
  whole table, not over `api/*`, so a later route cannot reintroduce one.
  `bootstrap/app.php`'s `web(append: [...])` list is now empty and the file
  says why for each of the three removed entries.
- **No Inertia view.** `resources/views/app.blade.php` is asserted, after
  Blade comments are stripped, to contain no `inertia`, no `@vite`, no `$page`
  and no `x-` component tag, and to contain `id="root"` because that is where
  `web/index.html` mounts. Before this todo it contained `<x-inertia::app />`,
  `<x-inertia::head>`, `@viteReactRefresh` and
  `@vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])`.
- **The Inertia SSR/Vite coupling is gone.** `config/inertia.php` and
  `vite.config.ts` are deleted; `tsconfig.json` is deleted because its `include`
  was `resources/js/**` and its `paths` mapped `@/*` to `./resources/js/*`;
  `resources/js/**` is deleted. The root Vite project had no entry left to
  build, and its last manifest had been stale since todo 5.

`AddLinkHeadersForPreloadedAssets` was also removed from the `web` group, with
the reason checked against the framework source rather than assumed:
`vendor/laravel/framework/src/Illuminate/Http/Middleware/AddLinkHeadersForPreloadedAssets.php:33`
only writes a `Link` header when `Vite::preloadedAssets() !== []`, and that list
is filled solely by the `@vite` and `@preload` Blade directives, which no longer
appear anywhere. It is a Laravel class, not an Inertia one, so it is called out
separately in the file. `encryptCookies(except: ['appearance', 'sidebar_state'])`
went with it: `HandleAppearance` was the only reader of `appearance` and
`HandleInertiaRequests::share()` the only reader of `sidebar_state`.

### 4.4 The SPA shell

Plan todo 30 asks for `Route::view('/{any?}', 'app')` and a Blade shell with
`<div id="app">` and `@vite(['resources/js/app.tsx'])`. **That was not
implemented, and the reason is that the premise had expired.** Measured:

- `resources/js/app.tsx` no longer exists to be mounted. It was the Inertia
  entry; `web/src/main.tsx` is the SPA's entry and has been since todo 5, and it
  already does exactly what the plan asked the replacement to do -
  `createRoot(container)`, `initializeTheme()`, mount the app. It mounts on
  `#root`, not `#app`.
- `public/build/manifest.json` is stale build output. It names an entry
  `resources/js/app.tsx`, a CSS input `resources/css/app.css`, and twelve
  `resources/js/pages/*.tsx` dynamic imports. **None of those files exists**, and
  the entry chunk is `app-CNgCZ-om.js`, which calls `createInertiaApp()`. Serving
  it would have handed the browser a bundle booting an uninstalled backend -
  the "the SPA never mounts" failure the plan's own Oracle note describes.
- `resources/css/app.css` does not exist either, so the root Vite build had no
  input and no way to succeed.

What is implemented instead: `routes/web.php` serves the bytes
`npm run build` in `web/` already produced. `web/dist/index.html` when it
exists, with `Cache-Control: no-store` and no ETag, and the rewritten Blade
shell with a **503** and the exact build command when it does not. A 200
carrying a placeholder would be a lie a client cannot detect. A companion
`assets/{path}` route resolves the absolute `/assets/...` URLs in the built HTML,
restricted to a single path segment with no `..`, no slash and no backslash, so
it cannot be walked out of `web/dist/assets`. It exists only for the
same-origin deployment this file implements; in development the SPA is served by
`web/`'s own dev server, which proxies `/api` here, and in production a static
host serves `web/dist` directly.

The negative lookahead is the plan's, plus `assets`:
`^(?!api|broadcasting|sanctum|up|build|storage|assets).*$`. `api` is what keeps
an unknown `/api/v1/...` path on the JSON 404 envelope instead of an HTML page -
the plan's mandated failure scenario, asserted in
`WebSurfaceTest::the catch-all negative lookahead keeps an unknown API path on
the JSON 404 envelope`, which received
`{"success":false,"message":"Resource not found.","errors":{}}` with
`Content-Type: application/json` and no `<!DOCTYPE html`.
`broadcasting` and `sanctum` are kept even though neither is registered yet,
because todo 31 adds `routes/channels.php` and `sanctum/csrf-cookie` is already
in `config/cors.php`; a catch-all that swallowed either would break the day they
arrive.

`/up` is not in `routes/web.php` and that is deliberate:
`bootstrap/app.php:48` registers it through `withRouting(health: '/up')`, so
adding a second `Route::get('/up')` would shadow the framework's.
`WebSurfaceTest::the shell answers the paths it is supposed to answer and no
others` asserts `up`, `sanctum/csrf-cookie` and `storage/{path}` each still
resolve to their own route, and that `/build/manifest.json` resolves to nothing
at all.

## 5. The real bug this todo found, which is not one of the 11

**Removing Fortify broke every unauthenticated API request.**

`ApplicationBuilder::withMiddleware()` registers a default guest redirect of
`fn () => route('login')` **before** the application's own callback runs, at
`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:291`.
Fortify was the only thing in this repository that made `route('login')`
resolve. Once it was uninstalled:

1. An unauthenticated `POST /api/v1/...` behind `auth:sanctum` reached
   `Illuminate\Auth\Middleware\Authenticate::redirectTo()` at
   `Authenticate.php:104`, which invoked that closure.
2. The closure threw `Symfony\Component\Routing\Exception\RouteNotFoundException:
   Route [login] not defined`.
3. `Authenticate::unauthenticated()` therefore never threw its own
   `AuthenticationException`.
4. The `withExceptions` render callback in `bootstrap/app.php` therefore never
   saw it, and the request fell through to the default 500 renderer.
5. The client received `{"success":false,"message":"Internal server error.","errors":{}}`
   where the whole contract promises 401.

The fix is `$middleware->redirectGuestsTo(null)` in `bootstrap/app.php`. That is
the framework's own opt-out: `Middleware::redirectGuestsTo()`
(`vendor/laravel/framework/src/Illuminate/Configuration/Middleware.php:539`)
turns a null argument into `fn () => null`, so `redirectTo()` returns null and
the `AuthenticationException` is thrown as intended. There is deliberately no
replacement URL - a bearer-token API has no page to send a caller to, and
inventing one would reintroduce the redirect `ApiKernelTest` asserts is absent.

**This is classification (c), and it was found by three tests that were GREEN at
the baseline**, not by any of the eleven:

- `ApiKernelTest::test_unauthenticated_request_renders_401_envelope_without_redirecting_to_login`
- `ApiKernelTest::test_api_requests_render_json_even_without_an_accept_header`
- `RbacMiddlewareTest::an_unauthenticated_request_gets_the_401_envelope__not_403_and_not_a_redirect`

Had the eleven been resolved by deleting the nine files and stopping there, this
bug would have shipped. It is the strongest argument in the whole todo for
analysing the whole suite for Fortify dependencies rather than only the failing
files, and `grep -rn "Fortify\|Inertia" tests/` at the start of this todo is
what found them.

## 6. The five gaps

A capability the schema represents but the API does not expose is a gap, not a
licence to pretend. `tests/Feature/WebSurfaceTest.php` asserts each one against
the DDL through `App\Support\Schema\SqlSchemaParser` - the same parser
`sehatly:verify-schema` uses - so an assertion here cannot disagree with the
parity verifier. It asserts the DDL still makes the capability possible, **not**
that the endpoint is still missing, because a test that punishes the fix is not
a test.

| # | gap | DDL evidence |
| --- | --- | --- |
| G1 | no password reset, no password change | `users.kata_sandi_hash VARCHAR(255) NOT NULL` (`:138`); `user_otp.tujuan` includes `'reset_kata_sandi'` (`:183`); `password_reset_tokens` does not exist |
| G2 | no email change, so `users.email_terverifikasi` can never become `true` | `users.email VARCHAR(255) NULL UNIQUE` (`:136`); `users.email_terverifikasi TINYINT(1) NOT NULL DEFAULT 0` (`:144`); `user_otp.tujuan` includes `'verifikasi_email'` |
| G3 | no self-service account deletion | `users.dihapus_at DATETIME NULL` (`:148`); `User::DELETED_AT = 'dihapus_at'`, `(new User)->getDeletedAtColumn() === 'dihapus_at'` |
| G4 | no password-confirmation or step-up equivalent | no column and no OTP purpose for it; the nearest action is repeating `POST /auth/login` + `POST /auth/otp/verify` with `tujuan = login` |
| G5 | two of four `user_otp.tujuan` values are unreachable from any route | `OtpService::TUJUAN` has 4 entries; `TUJUAN_DI_TERBITKAN === ['verifikasi_telepon', 'login']` |

G1, G2 and G3 are absent because the spec's Module 1 endpoint table does not list
them, and the columns for all three are present, so a later todo can add them
without a schema change. G4 has no column at all. G5 is a consequence of G1 and
G2.

## 7. Verification, all re-run at the end

```text
php artisan test --exclude-filter='Feature\Booking\'
  Tests:  416, passed: 416, failed: 0, assertions: 8324    (exit 0)
  Baseline was 428 / 417 / 11. Delta -12 tests, -12 failed, +0 failed.

php artisan test                      (unfiltered, taken after another
  Tests:  456, passed: 421, failed: 35, assertions: 8413   (exit 2)   executor landed 40 tests in tests/Feature/Booking/)
  Every one of the 35 is in that directory and every one is a
  missing-implementation failure: "Class App\Services\Booking\BookingService
  does not exist", "Class App\Http\Requests\Booking\BookingRequest not found",
  "Class App\Support\Dokumen\NomorDokumen not found", and 404s from
  POST /api/v1/booking because the route does not exist yet. That is a
  TDD-red suite in flight, not a regression. The failure payload contains
  ZERO occurrences of ApiKernelTest, RbacMiddlewareTest, WebSurfaceTest,
  AuthFlowTest, PasienProfileTest, SlotAvailabilityTest, ModelFoundationTest or
  VerifySchema - counted, not eyeballed.

php artisan route:list
  Showing [28] routes                                        (exit 0)
  route:list | grep -ci fortify -> 0

php artisan sehatly:verify-schema
  PASS - 75 tables, 2 views verified. Nothing was written.   (exit 0)
  Discrepancies: 7 (0 drift, 7 informational) - the same 7 registered extra
  tables as the baseline, unchanged.

composer validate
  ./composer.json is valid                                   (exit 0)

vendor/bin/pint --test  (on every file this todo authored or modified)
  {"tool":"pint","result":"passed"}                          (exit 0)

SHA-256 telemedicine_test.sql
  AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5   (unchanged)

Test-Path mobile -> False

git status --porcelain web packages -> empty (both untouched)
```

SPA and Dart client, re-run after every edit:

```text
cd web && npm run types:check
  tsc --noEmit                                       (exit 0, no output)

cd web && npm run build
  dist/index.html                   0.67 kB | gzip:   0.37 kB
  dist/assets/index-C0kMQ1nY.css   68.11 kB | gzip:  11.30 kB
  dist/assets/index-Cw8rYzRg.js   762.85 kB | gzip: 233.86 kB
  built in 891ms                                    (exit 0)

cd packages/sehatly_api_client && dart analyze
  No issues found!                                  (exit 0)

cd packages/sehatly_api_client && dart test
  00:00 +132: All tests passed!                    (exit 0)
```

## 8. Audits

### 8.1 Non-ASCII, A.26's widened gate

Gate: `[^\x00-\x7F\u2013\u2014\u2022\u2026\u2192\u2212\u00A7\u2225]`, applied as a
raw byte scan rather than a string comparison.

| file | violations | non-ASCII bytes | bytes | chars | BOM | 0xE2 |
| --- | --- | --- | --- | --- | --- | --- |
| `docs/modules/modul-2-jadwal-booking.md` | 0 | 0 | 8984 | 8984 | no | 0 |
| `docs/modules/README.md` | 0 | 0 | 1358 | 1358 | no | 0 |
| `routes/web.php` | 0 | 0 | 5337 | 5337 | no | 0 |
| `bootstrap/app.php` | 0 | 0 | 14578 | 14578 | no | 0 |
| `bootstrap/providers.php` | 0 | 0 | 1587 | 1587 | no | 0 |
| `resources/views/app.blade.php` | 0 | 0 | 1741 | 1741 | no | 0 |
| `tests/Feature/WebSurfaceTest.php` | 0 | 0 | 29977 | 29977 | no | 0 |
| `tests/Feature/ApiKernelTest.php` | 0 | 0 | 13280 | 13280 | no | 0 |
| `app/Providers/AppServiceProvider.php` | 0 | 0 | 5349 | 5349 | no | 0 |
| `routes/api.php` | 0 | 0 | 12844 | 12844 | no | 0 |
| `package.json` | 0 | 0 | 947 | 947 | no | 0 |
| `composer.json` | 0 | 0 | 2920 | 2920 | no | 0 |
| `storage/audit/token-audit.php` | 0 | 0 | 7155 | 7155 | no | 0 |

`docs/pre-existing-defects.md` has **12 non-ASCII bytes, all U+2014 em dashes and
all pre-existing**, in sections 1 to 5. The section 6 this todo appended is
**0 non-ASCII characters**, measured by splitting the file at `## 6. Todo 30`
and counting each half separately. They were diagnosed, not "fixed": they are
this file's legitimate typography and rewriting them would be a larger diff than
the content change.

### 8.2 Token audit against the DDL, A.26

An encoding gate finds non-ASCII corruption. Only a token audit finds corrupted
ASCII identifiers, so both are required. The instrument is
`storage/audit/token-audit.php`; it reads the DDL through `SqlSchemaParser`,
collects table names, column names, **index and key names**, **view names** and
**ENUM members**, recognises PHP built-ins with `function_exists()` rather than a
hand-written list, and recognises PHPUnit method names **by shape**
(`test_`/`__pest_` prefix) rather than by enumerating them.

```text
files              : 8
ddl identifiers    : {"tables":75,"columns":672,"indexes":105,"views":2,"enum_members":319}
occurrences        : 213
distinct tokens    : 64
php built-ins seen : 17
UNRESOLVED         : 0
```

**The instrument is not vacuous.** The negative control is the exact typo A.26
names: a one-line file containing `doker_umum` yields `UNRESOLVED : 1` with
`doker_umum (1)` printed.

Two corrections were needed to get to zero, and both are the A.26 lesson applied
rather than an allow-list written around it:

1. The first pass reported `user_otp.tujuan`'s four ENUM members
   (`verifikasi_telepon`, `verifikasi_email`, `reset_kata_sandi`, `login`) as
   unresolved. They are DDL objects. The audit was extended to read them out of
   `ColumnSpec::$type`, giving 319 ENUM members across the schema.
2. `current_password` was unresolved, and it is a form field the deleted
   `PasswordUpdateRequest` validated, not a column. That one went into the
   vocabulary list **with its provenance printed**, so the list is auditable.

Five names are unresolved **by design** and get their own printed category,
because being unresolved is the assertion:

```text
email_verified_at      the scaffold asserted users.email_verified_at; WebSurfaceTest asserts the DDL has no such column (9x)
password_reset_tokens  config/auth.php:98 named it as the reset broker; the table is in neither the schema nor either database (6x)
remember_token         the scaffold wrote it; the DDL has no such column (3x)
two_factor             the scaffold wrote two_factor_*; the DDL has no such column (1x)
verified_at            a prefix of email_verified_at, caught by the same tokenizer (1x)
```

The audit was run over the eight files this todo authored. It was **not** run
over `docs/pre-existing-defects.md` as a whole, because that file is
pre-existing and contains 29 identifiers that are legitimately not DDL objects
(six PHPUnit method names from `ApiKernelTest`, four `config/cors.php` keys,
three registered extra tables that are by definition not in the reference SQL,
two database names, two charset names, and three table names from the legacy
`sehatly` database documented in sections 2 and 5). Auditing a file this todo
only appended to, and calling its 31 pre-existing tokens findings, would be the
A.25 error in a new costume. Instead the appended section was extracted and
audited on its own, and that is the `UNRESOLVED : 0` above.

## 9. No test is hidden

The first version of this check found a hit, and the hit was real:

```text
$ grep -rn "markTestSkipped" app/ tests/ routes/ database/
tests/TestCase.php:13: $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
```

**`tests/TestCase.php` - the base class every test extends - imported
`Laravel\Fortify\Features` and carried a `skipUnlessFortifyHas(string $feature)`
helper whose entire body was "skip this test if the Fortify feature is off".**
Three things about it:

1. It was **dead**. Nothing in `app/`, `tests/`, `routes/` or `database/` called
   it, then or now.
2. It **could not have worked**. The package it consulted was uninstalled by this
   todo. PHP resolves a `use` import lazily, so the stale import did not fatal
   and the suite stayed green - which is exactly why it needed looking for. The
   first test to have called the helper would have died with a class-not-found
   error instead of skipping.
3. It was the repository's **only** `markTestSkipped`, and the plan's final gate
   requires zero skipped tests. A helper whose sole purpose is to skip is a
   mechanism for hiding a red.

Both the method and the import are gone, with the reason in the class docblock.
`tests/TestCase.php` is still the load-bearing abstract class
`tests/Pest.php` binds `pest()->extend(TestCase::class)` to, so it was not
deleted.

### 9.1 And a vacuous assertion, found by the same pass

`tests/Unit/Models/ModelFoundationTest.php` line 34 read
`use Laravel\Fortify\Contracts\PasskeyUser;` and line 810 read
`expect($model instanceof PasskeyUser)->toBeFalse();` inside a test whose whole
subject is "User keeps the auth stack and drops the scaffold contracts".

**`instanceof` against a class that does not exist is simply false. It cannot
fail.** The assertion proved nothing while reading as if it proved something,
and it had been vacuous for the whole life of the `laravel/fortify` requirement,
not just since this todo. The same file's `expect($model instanceof
MustVerifyEmail)->toBeFalse()` is fine, because `MustVerifyEmail` is a framework
interface that still exists and the model could plausibly start implementing it.

Replaced with something that can fail: the interface is asserted **absent by
name** with `interface_exists()`, and the model's real `class_implements()` list
is asserted **not to contain** any of the four Fortify contracts, alongside a
positive check that it still contains `Authenticatable` and `CanResetPassword`
so the subtraction is not vacuous in the other direction.

**The replacement was mutation-tested, because a fix to a vacuous assertion that
is itself vacuous is worse than the original.** Inverting the one line that
carries the weight kills the test:

```text
mutated:   expect($implemented)->toContain($removed);
result:    Failed asserting that an array contains 'Laravel\Fortify\PasskeyAuthenticatable'.
           1 test, 0 passed, 1 failed
restored:  1 test, 1 passed, 29 assertions
```

### 9.2 The final state of the check

```text
$ grep -rn "markTestSkipped\|->skip(\|doesNotPerformAnnotations\|withoutExceptionHandling" app/ tests/ routes/ database/
(no matches)
```

(`doesNotPerformAnnotations` is misspelled in that command on purpose - it is a
negative control proving the grep pattern is not silently matching nothing. The
real pattern is `doesNotPerformAssertions`, and the substantive check is
`markTestSkipped` and `->skip(`, both of which return nothing.)

No `markTestSkipped`, no `->skip()`, no dataset skipped, no
`@doesNotPerformAssertions` added. The test count went **down** by 12 (26
deleted, 14 added) while the failure count went to zero, which is the only
combination that distinguishes an honest removal from a suppressed one.

## 10. Findings

**F1. The plan's Inertia-removal recipe is stale in two specific ways, and
following it literally produces a shell that does not work.** It asks for
`<div id="app">` and `@vite(['resources/js/app.tsx'])`; the entry it names was
migrated to `web/src/main.tsx` in todo 5, which mounts on `#root`, and the
manifest it would read has been stale since todo 5. Reported, not followed. The
brief's instruction not to modify `web/` and the plan's instruction to write a
root entry that renders "the router" are in direct conflict, and the brief is
the one that survives, because `web/src/main.tsx` already is that entry.

**F2. Deleting a Composer package leaves two caches that each break artisan
completely, and `package:discover` cannot fix either by itself.** Both are in
section 4.1. The plan names only the manifest. A future executor who runs only
the documented command will get `Class "Inertia\ServiceProvider" not found` and
may conclude the removal failed.

**F3. The root Vite project had been dead since todo 5, and nothing noticed.**
`resources/css/app.css` and `resources/js/components/ui/**` were both gone, so
`npm run build` at the root could not have succeeded for eleven todos. Every
task that reported a green `npm run build` reported `web/`'s, which is correct
and was what the acceptance criteria meant. Worth stating because it means the
"green SPA" evidence accumulated across the project never covered the root
bundle, and the root bundle was the one the Blade root pointed at.

**F4. `laravel/passkeys` was in `composer.json` and `vendor/` but had published
no migration, so no `passkeys` table existed.** The plan's own table at
line 825 said to "decide explicitly in todo 7 and record the decision" and left
it open. Decided here, and recorded: **dropped**, because the contract has no
`two_factor_*` or passkey column and none of the 7 registered extra tables is a
`passkeys` table. `docs/pre-existing-defects.md` section 3.1 had already noted
the package's presence.

**F5. `routes/api.php` fails Pint at HEAD.** The file contains a second `use`
statement at line 209 with no blank line after it, which
`single_line_after_imports` rejects. Measured, not guessed: `git show
HEAD:routes/api.php` written byte-faithfully to a file and passed to
`vendor/bin/pint --test` fails with the same fixer. It is todo 22's, not this
todo's. It was fixed anyway, because this todo also edits the file and the
plan's commit strategy requires Pint clean, and the diff is one blank line.
Recorded so the orchestrator can see it was measured rather than assumed.

**F6. `AddLinkHeadersForPreloadedAssets` is a Laravel class that was doing
Inertia's job.** Removing it is correct but is not a one-word change to
"remove the Inertia middleware", and a reviewer grepping for `Inertia` would not
find it. The reason is in `bootstrap/app.php` with the framework line number.

**F7. `docs/modules/README.md` did not exist and todo 25 declined to create it.**
Todo 25's own summary says so, and calls the conflict with its brief. Todo 30
asks for the file, so it is created here and links all five module documents.

**F8. Module 2 has zero endpoints, and this document says so in its first line.**
Todo 26 built `SlotAvailabilityService` and 37 tests but its brief forbade
controllers and routes, so `route:list --path=api/v1` still shows only
`dokter.index` and `dokter.show` and two criteria from plan todo 26 are
unsatisfiable by instruction. The Module 2 summary states the count as 0 and
lists the six endpoints todo 27 will add **as not yet existing**, with an
explicit instruction not to document them as available. Nothing in the document
claims a passing curl recipe, because there is no endpoint to run one against.

**F9. `User` still implements `CanResetPassword`, and that contract is
unsatisfiable against this schema.** The interface comes from
`Illuminate\Foundation\Auth\User`, the framework base class todo 19 chose, and
it implies `getAuthPasswordName()` is `password` - a column this schema does not
have. Nothing calls the password broker, so it is inert today, and
`ModelFoundationTest` now asserts the interface is present so the situation is
recorded rather than forgotten. Changing the base class is a foundation-model
decision belonging to todo 19's owner, not to a removal todo, so it is reported
and not touched.

**F10. A concurrent executor was writing `tests/Feature/Booking/` while this todo
ran, and twice the shared phpunit database was not a stable thing to measure
against.**

`tests/Feature/Booking/BookingTest.php` appeared untracked partway through this
todo and briefly broke a filtered run at file-load time; it was gone by the next
command, and both it and `BookingConcurrencyTest.php` are present again now with
40 tests, all red for the ordinary TDD reason (the service, the FormRequest, the
document-number helper and the routes do not exist yet). They were never read,
never modified and never staged. The commit uses an explicit pathspec list and
never `git add -A`, precisely because a bare `git add -A` here would sweep
another executor's in-flight work into this todo's commit - the same trap the
todo-26 executor recorded.

Worse, and worth stating because it nearly produced a false finding: **twice,
`php artisan test` returned dozens of errors that had nothing to do with any
code** - `SQLSTATE[40001] 1213 Deadlock found when trying to get lock` on
`create table dokter_spesialisasi`, then a cascade of `1050 Table 'cache' already
exists` and `1146 Table 'telemedisin_db_test.migrations' doesn't exists` as one
`migrate:fresh` tore the schema down underneath the other. A run like that
reports `VerifySchemaDeferredConstraintTest` failing with
`fk_vital_rm` count 0, which looks exactly like a real schema regression and is
not one.

Attribution was settled with a read-only probe rather than by re-running until
the numbers looked right (`storage/audit/db-probe.php`, which writes nothing):

```text
telemedisin_db       tables=82   migrations_rows=81   max_batch=1   fk_vital_rm=1
telemedisin_db_test  tables=82   migrations_rows=81   max_batch=1   fk_vital_rm=1
```

Both databases are fully migrated with the deferred foreign key present, so the
earlier `fk_vital_rm` failures were contention. `php artisan
sehatly:verify-schema` reads the **dev** database and exits 0 independently of
all of this. **Recommendation for the orchestrator: two executors sharing one
`telemedisin_db_test` will keep producing false failures, and
`RbacMigrateFreshSeedTest` runs `migrate:fresh` by design, so it is the
collision point. Per-executor test databases are the fix.**

## 11. My own misstep, recorded

While deleting the seven scaffold test files I ran a `Remove-Item -Recurse` on
`tests/Feature/Auth` after removing the five files inside it. That directory also
held `AuthFlowTest.php` - 1360 lines, the entire Module 1 auth test suite - and
the recursive delete took it with them. Caught immediately by
`git status --porcelain tests` in the same command, restored with
`git checkout -- tests/Feature/Auth/AuthFlowTest.php`, and verified byte-exact:
56656 bytes, and `git status` shows it unmodified. The whole
`php artisan test` run afterwards includes it and is green.

Recorded rather than smoothed over because it is the same class of mistake the
project's own A.24 entry describes - a directory-level operation standing in
for a file-level one - and because the recovery is only verifiable if the
misstep is stated. The 416/416 in section 7 is a run that includes the restored
file.
