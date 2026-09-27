# Pre-existing defects and environment findings

Recorded by **todo 1** (baseline worktree + toolchain), before any feature work.

This file is a **record of what was already broken or already drifted in the
repository and on this host at the moment todo 1 started**. It is not a TODO
list. Anything listed here is inherited state that later todos must not
mistake for a regression they introduced, and several items are prerequisites
that later todos are explicitly blocked on.

Baseline commit for this record: see `.omo/evidence/task-1-sehatly.md`.

---

## 1. Toolchain

### 1.1 PHP — the project requires 8.3+, but PATH resolves to 8.2

`composer.json` requires `"php": "^8.3"`. The **first `php` on `PATH` is
8.2.29**, which does NOT satisfy the constraint:

| | Path | Version |
|---|---|---|
| First on `PATH` (wrong) | `C:\php-8.2.29\php.exe` | 8.2.29 |
| Laragon copy (wrong) | `C:\laragon\bin\php\php-8.2.29-nts-Win32-vs16-x64\php.exe` | 8.2.29 |
| **Project-local (CORRECT)** | **`C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe`** | **8.4.17** |

Confirmed:

```
PS> C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe -v
PHP 8.4.17 (cli) (built: Jan 13 2026 17:46:46) (NTS Visual C++ 2022 x64)
Copyright (c) The PHP Group
Built by The PHP Group
Zend Engine v4.4.17, Copyright (c) Zend Technologies
```

**Consequence for every later todo:** the `php` constraint cannot be relaxed to
`^8.2`, and any bare `php` / `composer` / `artisan` invocation in this repo will
silently use 8.2.29 and misbehave. Prepend the 8.4.17 bin directory to `PATH`
before running PHP tooling:

```powershell
$env:Path = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64;" +
            [Environment]::GetEnvironmentVariable("Path","Machine") + ";" +
            [Environment]::GetEnvironmentVariable("Path","User")
php -v            # must print 8.4.17
```

Verified under that `PATH`: `php artisan --version` -> `Laravel Framework 13.33.0`.

Composer: `C:\ProgramData\ComposerSetup\bin\composer.bat`, version 2.9.4.

### 1.2 Dart SDK — was NOT installed; installed by todo 1

`dart` was absent at todo 1 start (`CommandNotFoundException`). The plan
forbids a Flutter dependency, so the **standalone Dart SDK** was installed:

```powershell
winget install --id Google.DartSDK --exact --source winget `
  --accept-package-agreements --accept-source-agreements --silent --disable-interactivity
```

> Note: the plan/task text suggests `Dart.DartSDK`. **That package id does not
> exist.** The correct id on the `winget` source is `Google.DartSDK`.

Resolved version:

```
Dart SDK version: 3.13.2 (stable) (Tue Aug 25 01:01:12 2026 -0700) on "windows_x64"
```

Binary:
`C:\Users\axioo\AppData\Local\Microsoft\WinGet\Packages\Google.DartSDK_Microsoft.Winget.Source_8wekyb3d8bbwe\dart-sdk\bin\dart.exe`

winget added that directory to the **User** `PATH`, so `dart` is available in
any newly launched shell. Already-running shells (including an agent shell that
was started before the install) must refresh `PATH` from
`[Environment]::GetEnvironmentVariable("Path","User")` before `dart` resolves.

No Flutter SDK was installed and no `pubspec.yaml` / `mobile/` exists.

### 1.3 `jq` — NOT installed; mandatory substitution rule

`jq` is **not installed** on this host (`CommandNotFoundException`).

Every `jq`-based acceptance criterion in
`.omo/plans/sehatly-telemedicine-platform.md` must be executed as the
Windows-portable equivalent instead. The plan file is **not** edited; this is
the authoritative substitution:

```powershell
# plan:  ... | jq 'length'
php artisan route:list --path=api/v1 --json | php -r 'echo count(json_decode(stream_get_contents(STDIN), true));'
```

Same assertion (a count printed to stdout), portable to PowerShell 5.1.
Other `jq` filters must be re-expressed as `php -r` one-liners or
`ConvertFrom-Json` / `Select-String` pipelines. POSIX `wc -l` maps to
`Measure-Object -Line`; `test -f X` maps to `Test-Path X`; `grep -rn P D` maps
to `Select-String -Path (Get-ChildItem D -Recurse) -Pattern P`.

---

## 2. Data services

### 2.1 MySQL 8 — reachable, and the `sehatly` database already exists

Observation only; **nothing was reconfigured**.

| | |
|---|---|
| Server | MySQL **8.0.30** |
| Endpoint | `127.0.0.1:3306` (listening; owning PID 17484) |
| Client binary | `C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe` |
| Credentials | user `root`, **empty password** |
| Auth identity | `root@localhost` |

Matches `.env` and `.env.example` as found:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sehatly
DB_USERNAME=root
DB_PASSWORD=
```

Proof of a real connection:

```
PS> mysql -h 127.0.0.1 -P 3306 -u root --connect-timeout=10 -e "SELECT VERSION(), CURRENT_USER(), @@port;"
server_version  auth_user       port
8.0.30          root@localhost  3306
```

`SHOW DATABASES;` already lists **`sehatly`** alongside unrelated databases
(`db_simprapkl`, `gawaiseken`, `laravel`, `manajemen-surat`, `trading_journal`,
`ukk`, `ukk_pengaduan_sekolah`, ...). The `sehatly` schema therefore **already
contains data predating this plan** — see 3.5 below. Todo 2 owns the decision
about it; todo 1 only records that the server is up and reachable.

---

## 3. Repository defects found at baseline

### 3.1 `laravel/passkeys` present in lock/vendor/code but ABSENT from `composer.json`

This was the manifest/lock drift that made `composer validate` fail.

| Location | State at baseline |
|---|---|
| `composer.json` `require` | **missing `laravel/passkeys`** |
| `composer.lock` `packages` | contains `laravel/passkeys` **`v0.2.1`** |
| `vendor/laravel/passkeys/` | **present** on disk |
| `vendor/composer/installed.json` | `laravel/passkeys` **`v0.2.1`** |
| `app/Models/User.php` | L13 `use Laravel\Fortify\Contracts\PasskeyUser;`, L14 `Laravel\Fortify\PasskeyAuthenticatable`, L15 `Laravel\Fortify\TwoFactorAuthenticatable`, L32 `implements ... PasskeyUser`, L35 `use ... PasskeyAuthenticatable, TwoFactorAuthenticatable;` |
| root `package.json:16` | `"@laravel/passkeys": "^0.2.0"` |

So the application **depends on passkeys at runtime** while the manifest never
declared it. Repaired by todo 1: added `"laravel/passkeys": "^0.2.1"` to
`composer.json` `require` — pinned to the version already resolved in the lock
and already installed in `vendor/`, so **no dependency version was changed**.
`composer.lock` was refreshed with `composer update --lock`, which reported
`Nothing to modify in lock file` and rewrote only the `content-hash`
(`5c25a1b148b331626159dada7986c7d0` -> `16c594d6deac6e4b3e26e9e52761a4ba`); 146
locked packages before and after, zero version changes. `composer validate`
now exits 0.

There is also an **untracked** migration
`database/migrations/2024_01_01_000000_create_passkeys_table.php` that backs
this feature, and an untracked
`database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php`.

### 3.2 Compiled Blade views are untracked build output, and the ignore stub was deleted

`storage/framework/views/.gitignore` (tracked in `main` as the idiomatic
`*` + `!.gitignore` stub) had been **deleted in the working tree**, which
exposed ~26 untracked compiled view caches:

```
storage/framework/views/03cc807da253af92657ac893f02c75cd.php
... (26 total, incl. 58f6b5ecbad88fc4da15879dbc7f6e69.blade.php)
```

The stub was restored by todo 1 (byte-identical to `main`), so compiled views
are ignored again and are not committed:

```
PS> git check-ignore -v storage/framework/views/03cc807da253af92657ac893f02c75cd.php
storage/framework/views/.gitignore:1:*   storage/framework/views/03cc807da253af92657ac893f02c75cd.php
```

This is required, not cosmetic: without it the baseline commit would have
committed machine-specific compiled output.

### 3.3 Renamed tooling config: starter-kit leftovers

Deleted in the working tree, replaced by TypeScript/ESM equivalents:

- `.prettierrc` -> deleted (no replacement tracked)
- `.prettierignore` -> deleted
- `eslint.config.js` -> deleted
- `vite.config.js` -> deleted, untracked **`vite.config.ts`** added

A `.gitignore` diff in the same worktree tracks the JS->TS migration. These
files belong to the starter-kit; later todos own them. Recorded, not touched.

### 3.4 Deleted auth scaffolding vs. surviving `config/fortify.php`

Deleted: `app/Http/Controllers/Auth/*` (8 controllers), `app/Http/Requests/Auth/LoginRequest.php`,
`app/Http/Controllers/Settings/PasswordController.php`,
`resources/js/pages/settings/password.tsx`, `routes/auth.php`.

Untracked and newly added in their place: `app/Providers/FortifyServiceProvider.php`,
`config/fortify.php`, `config/inertia.php`, `app/Http/Controllers/Settings/SecurityController.php`,
`app/Http/Middleware/HandleAppearance.php`, and a passkey/2FA component set
(`manage-passkeys.tsx`, `manage-two-factor.tsx`, `passkey-item.tsx`,
`passkey-register.tsx`, `passkey-verify.tsx`, `two-factor-*` etc.).

The suite still contains `tests/Feature/Auth/*` and
`tests/Feature/Settings/PasswordUpdateTest.php`, which reference some of the
deleted classes. **The test suite was not run at baseline** — todo 2 introduces
the first DB-engine test run. Expect auth/password tests to fail against this
baseline until those todos land.

### 3.5 `telemedicine_test.sql` is untracked, pre-existing data, and READ-ONLY

`telemedicine_test.sql` sits untracked in the repo root. It is **not** authored
by this plan — it is a pre-existing dump. Under todo 1's law it is
**read-only**: never edit, reformat, re-encode, or reorder it. Todo 1's baseline
commit deliberately **tracks** it (via `git add -A`) precisely so that no later
executor can delete uncommitted user work, and so `git diff --exit-code
telemedicine_test.sql` can be used as a byte-identity probe. Todos 8 and 18 own
its actual treatment.

### 3.6 Untracked rebranding assets

Also pre-existing and untracked at baseline; the baseline commit tracks them for
the same safety reason:

- `Sehatly Logo Icon.png`
- `Sehatly.svg`
- `WhatsApp Image 2026-09-26 at 7.07.11 PM.jpeg`

(The reference `resources/js/components/app-logo.tsx` / `app-logo-icon.tsx`
were modified in the worktree to consume them.)

### 3.7 `composer.json` still carried starter-kit identity

`name` was `laravel/react-starter-kit` and `description` was
`"The skeleton application for the Laravel framework."`. Todo 1 set them to
`sehatly/telemedicine-api` and a telemedicine-specific description. The
`"php": "^8.3"` constraint was **not** touched.

---

## 4. What todo 1 installed or created on this host

| Thing | Status |
|---|---|
| Dart SDK 3.13.2 (standalone, via winget) | **installed**, on User `PATH` |
| `web/` (`package.json`, `package-lock.json`, `playwright.config.ts`, `.gitignore`) | **created** |
| `@playwright/test` + `playwright` 1.63.0 in `web/node_modules` | **installed**; `node_modules` gitignored |
| Playwright **browser binaries** | **NOT downloaded** (`PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`). Whoever first runs a real spec must run `npx playwright install` in `web/`. |
| Flutter SDK / `pubspec.yaml` / `mobile/` | deliberately absent |
| MySQL configuration | **untouched** (observation only) |

---

## 5. Todo 2 findings — MySQL 8 confirmed, and the `sehatly` data decision

Appended by **todo 2** (move dev and test databases to MySQL 8). Section 2.1
above is todo 1's observation; this section records what todo 2 re-verified
against a live connection and what it did about the pre-existing `sehatly`
schema. **Nothing in sections 1-4 was edited.**

### 5.1 Connection details (re-verified, not copied from todo 1)

| | |
|---|---|
| Server | MySQL **8.0.30** |
| Endpoint | `127.0.0.1:3306` |
| Credentials | user `root`, **empty password** |
| `php` used | `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` |
| Laravel | 13.33.0 |

```
PS> php artisan tinker --execute="dump(DB::selectOne('select version() as v')->v);"
"8.0.30"
```

### 5.2 `sehatly` holds PRE-EXISTING data. It was left completely alone.

`sehatly` exists and is **not empty**. It is inherited user data, not something
this plan created, so todo 2 issued **no** `DROP`, `TRUNCATE`, `DELETE` or
`ALTER` against it — only `SELECT` from `information_schema`:

| Database | Tables | Charset | Collation |
|---|---|---|---|
| `sehatly` | **10** | `utf8mb4` | `utf8mb4_unicode_ci` |
| `telemedisin_db` | 0 (empty, created by todo 2) | `utf8mb4` | `utf8mb4_unicode_ci` |
| `telemedisin_db_test` | 0 (empty, created by todo 2) | `utf8mb4` | `utf8mb4_unicode_ci` |

`sehatly`'s ten tables are the stock Laravel scaffolding plus this repo's
additions: `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`,
`migrations`, `passkeys`, `password_reset_tokens`, `sessions`, `users` — with
`migrations` holding **5** already-applied migration rows and `sessions` holding
**1** live row at the time of writing. This is consistent with a previous
partial `php artisan migrate` on the default connection.

**This is the drift todo 2 was asked to resolve, and it is now visible in two
places rather than one:** `.env` said `sehatly` while
`telemedicine_test.sql:10-12` creates `telemedisin_db`. Both names now exist on
the server, so nothing breaks either way, but they point at different histories.

### 5.3 What todo 2 did about it

- Created **`telemedisin_db`** (did not exist) and **`telemedisin_db_test`**
  (did not exist) with `utf8mb4` / `utf8mb4_unicode_ci`, matching
  `telemedicine_test.sql:10-12`. Tables are **not** created by todo 2 — the
  migrations own that (todos 7-18).
- Repointed `.env.example` `DB_DATABASE` `sehatly` -> `telemedisin_db`, so the
  template now agrees with the SQL dump.
- Repointed `phpunit.xml` `DB_CONNECTION` `sqlite` / `DB_DATABASE` `:memory:`
  -> `mysql` / `telemedisin_db_test`.
- **`sehatly` itself was not dropped, truncated, emptied, migrated, or renamed.**

### 5.4 OPEN, for the orchestrator: `.env` still points at `sehatly`

> `.env` is **not** version-controlled and todo 2 did **not** edit it. Creating
> the databases did not require it, so per the task's data-safety rule it was
> left as found. It currently reads `DB_DATABASE=sehatly`, which means artisan
> commands still operate on the pre-existing legacy schema rather than on
> `telemedisin_db`.

One line in `.env` fixes it:

```
DB_DATABASE=sehatly   ->   DB_DATABASE=telemedisin_db
```

This is deliberately left to the owner because it is the exact moment the plan
stops writing to the legacy database, and that switch should be a conscious
choice, not a side effect of a database-provisioning commit. Once flipped,
`php artisan migrate` will populate the empty `telemedisin_db`; `sehatly`
remains on disk as a recoverable fallback.

---

## 6. Todo 30 - section 3.4 resolved, and what replaced it

Section 3.4 recorded that the Laravel starter kit's auth scaffolding had been
deleted while its replacements - `FortifyServiceProvider`, `config/fortify.php`,
`config/inertia.php`, `SecurityController`, `HandleAppearance` and the passkey
and 2FA React components - had been added in its place, and that the suite
still contained `tests/Feature/Auth/*` and
`tests/Feature/Settings/PasswordUpdateTest.php` referencing the deleted
classes. It predicted "auth/password tests to fail against this baseline".

Measured at the start of todo 30: **428 tests, 417 passed, 11 failed**, and
every one of the 11 was in that predicted set. Todo 30 removed the scaffold for
real, and the suite is now **416 tests, 416 passed, 0 failed**.

### 6.1 Why those tests could not have passed

Not "the classes were deleted" - the deeper reason is that the contract's
`users` table cannot support them. `telemedicine_test.sql:132-149` declares
`nama_lengkap`, `email`, `no_telepon`, `kata_sandi_hash`, `tipe`, `status`,
`foto_profil`, `bahasa`, `telepon_terverifikasi`, `email_terverifikasi`,
`last_login_at`, `dibuat_at`, `diubah_at` and `dihapus_at`. It declares **no**
`password`, **no** `name`, **no** `email_verified_at`, **no** `remember_token`
and **no** `two_factor_*` column, and there is no `password_reset_tokens` table
anywhere in the schema or in either database. The scaffold's own tests wrote
`User::factory()->create(['password' => ...])` and asserted
`$user->email_verified_at`, which are MySQL 1054 by construction.

### 6.2 Files removed, and the baseline each was checked against

Every path below was verified present in the todo-1 baseline commit `b443f3b`
before deletion, as the plan requires. `resources/js/{actions,routes,wayfinder}`
were gitignored generated output and so appear in no commit; they are named in
`b443f3b`'s `.gitignore`.

| path | why |
|---|---|
| `app/Providers/FortifyServiceProvider.php` | every view it registered was `Inertia::render()`, and `Inertia\Inertia` no longer exists |
| `app/Actions/Fortify/CreateNewUser.php`, `ResetUserPassword.php` | write `name` / `email_verified_at` / `password`, none of which exist |
| `app/Concerns/PasswordValidationRules.php`, `ProfileValidationRules.php` | the two validation traits the two actions above needed |
| `app/Http/Middleware/HandleInertiaRequests.php` | extended `Inertia\Middleware` |
| `app/Http/Middleware/HandleAppearance.php` | shared the `appearance` cookie into the Inertia root view |
| `app/Http/Controllers/Settings/ProfileController.php` | wrote `email_verified_at` (its line 36) and soft-deleted on a `password` check |
| `app/Http/Controllers/Settings/SecurityController.php` | wrote `password`; its passkey query needs a `passkeys` table that does not exist |
| `app/Http/Requests/Settings/*` (4 files) | validated `current_password` and `password` against columns that do not exist |
| `config/fortify.php` | `use Laravel\Fortify\Features` - the class is gone |
| `config/inertia.php` | configures the uninstalled adapter |
| `routes/settings.php` | registered the settings, `appearance.edit` and `.well-known/passkey-endpoints` routes |
| `resources/js/**` (62 tracked files plus 3 generated directories) | the whole Inertia page, layout, hook, type and component tree |
| `vite.config.ts`, `tsconfig.json` | the root front-end build; its only entry was `resources/js/app.tsx` and its `tsconfig` `include` was `resources/js/**` |
| `resources/views/app.blade.php` | **rewritten, not deleted** - see 6.4 |

`config/auth.php` was deliberately **kept**. It is stock Laravel configuration
and it declares the `users` Eloquent provider that both `auth:sanctum` and
Sanctum resolve through.

Composer packages removed: `inertiajs/inertia-laravel`, `laravel/fortify`,
`laravel/passkeys`, `laravel/wayfinder`, and the 13 transitive packages that
only they pulled in (including `web-auth/webauthn-lib`, `pragmarx/google2fa`
and `symfony/serializer`). No `passkeys` migration was ever published, so no
`passkeys` table existed and none of the 7 registered extra tables in
`docs/schema-notes.md` is affected.

### 6.3 The 11 failures, classified

Each was classified as (a) a feature this contract deliberately does not have,
(b) real behaviour reached through the wrong layer, or (c) a genuine bug. The
full table, with the reason and the API test that already covers the behaviour,
is in `.omo/evidence/task-30-sehatly.md` section 3. **Eleven were (a), two were
(b), and none was (c).**

### 6.4 `resources/views/app.blade.php` and the SPA shell

The plan asked for the file to be rewritten as a bare shell with a
`<div id="app">` and `@vite(['resources/js/app.tsx'])`. That was not done,
because the premise had expired. The `@vite` argument no longer exists, and the
manifest it would have read (`public/build/manifest.json`) had been stale since
todo 5: it names `resources/css/app.css` and twelve
`resources/js/pages/*.tsx` modules, none of which still exist, and its entry
chunk calls `createInertiaApp()` against an uninstalled backend. The
`resources/js` entry also no longer exists to be mounted - `web/src/main.tsx`
is the SPA's entry and it has been since todo 5, and it mounts on `#root`, not
`#app`.

What the file is now: a bare shell with `<div id="root">`, no `@inertia`, no
`@vite`, and no `$page`. It is rendered with a **503** and only when
`web/dist/index.html` is absent, so a deployment that never built the SPA says
so instead of serving a 200 that mounts nothing. When the build exists,
`routes/web.php` serves the real `web/dist/index.html` byte for byte.

### 6.5 One real bug, and it was not one of the 11

Removing Fortify broke every unauthenticated API request.
`ApplicationBuilder::withMiddleware()` registers a default guest redirect of
`fn () => route('login')` at
`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:291`,
and Fortify was the only thing that made `route('login')` resolve. Without it,
`Illuminate\Auth\Middleware\Authenticate::redirectTo()` threw
`RouteNotFoundException`, the middleware's own `AuthenticationException` was
never thrown, the render callback in `bootstrap/app.php` never saw it, and an
unauthenticated `POST /api/v1/...` answered a sanitised **500** where the whole
contract promises a 401.

It was caught by three tests that were **green** at the baseline -
`ApiKernelTest::test_unauthenticated_request_renders_401_envelope_without_redirecting_to_login`,
`ApiKernelTest::test_api_requests_render_json_even_without_an_accept_header` and
`RbacMiddlewareTest`'s 401-envelope case - and by nothing among the 11. The fix
is `$middleware->redirectGuestsTo(null)` in `bootstrap/app.php`, the framework's
own opt-out, which installs `fn () => null` so the `AuthenticationException` is
thrown and rendered as 401.

The first of those three also had to be **rewritten**, not deleted. Its
precondition was `assertTrue(Route::has('login'), '... otherwise this test
proves nothing')` - an assertion that removal falsifies, and whose falsification
would have turned a real regression into a red test. It now asserts the
opposite, which is the property that stays true as the application grows: no
named authentication route exists for a redirect to name.

