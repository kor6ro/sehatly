# Todo 1 evidence — `chore(api): baseline worktree and sync composer manifest with lock`

Repo: `C:\Users\axioo\Desktop\sehatly`
Branch: `feat/sehatly-telemedicine` (created from `main` @ `2d3b3b4`)
Host: Windows, PowerShell 5.1

All commands below were run from the repo root unless stated otherwise.
Long/network commands were given explicit timeouts; every reported result is an
observed exit code, never an inference.

> **PowerShell substitutions used** (POSIX -> PS 5.1), same assertion each time:
> `wc -l` -> `Measure-Object -Line`; `test -f X` -> `Test-Path X`;
> `grep -rn P D` -> `Select-String -Path (Get-ChildItem D -Recurse) -Pattern P`;
> `jq` -> `php -r` (see `docs/pre-existing-defects.md` §1.3).

---

## 1. PRE-STATE (M8) — captured before any change

`git rev-parse HEAD`:

```
2d3b3b458977c7f071e1882fd53d63f96486d97e
```

`git log --oneline -5` / `git branch --show-current`:

```
2d3b3b4 first commit
main
```

`git ls-files --others --exclude-standard | Measure-Object -Line`:

```
   79
```

**PRE-STATE UNTRACKED COUNT = 79.**

Verbatim `git status --porcelain` (pre-state, 176 entries):

```
 M .env.example
 M .github/workflows/lint.yml
 M .gitignore
 D .prettierignore
 D .prettierrc
 D app/Http/Controllers/Auth/AuthenticatedSessionController.php
 D app/Http/Controllers/Auth/ConfirmablePasswordController.php
 D app/Http/Controllers/Auth/EmailVerificationNotificationController.php
 D app/Http/Controllers/Auth/EmailVerificationPromptController.php
 D app/Http/Controllers/Auth/NewPasswordController.php
 D app/Http/Controllers/Auth/PasswordResetLinkController.php
 D app/Http/Controllers/Auth/RegisteredUserController.php
 D app/Http/Controllers/Auth/VerifyEmailController.php
 D app/Http/Controllers/Settings/PasswordController.php
 M app/Http/Controllers/Settings/ProfileController.php
 M app/Http/Middleware/HandleInertiaRequests.php
 D app/Http/Requests/Auth/LoginRequest.php
 M app/Http/Requests/Settings/ProfileUpdateRequest.php
 M app/Models/User.php
 M app/Providers/AppServiceProvider.php
 M artisan
 M bootstrap/app.php
 M bootstrap/providers.php
 M components.json
 M composer.json
 M composer.lock
 M config/app.php
 M config/auth.php
 M config/cache.php
 M config/database.php
 M config/filesystems.php
 M config/logging.php
 M config/mail.php
 M config/queue.php
 M config/services.php
 M config/session.php
 M database/factories/UserFactory.php
 M database/migrations/0001_01_01_000001_create_cache_table.php
 M database/migrations/0001_01_01_000002_create_jobs_table.php
 M database/seeders/DatabaseSeeder.php
 D eslint.config.js
 M package-lock.json
 M package.json
 M phpunit.xml
 M public/index.php
 M resources/css/app.css
 M resources/js/app.tsx
 M resources/js/components/app-content.tsx
 M resources/js/components/app-header.tsx
 M resources/js/components/app-logo-icon.tsx
 M resources/js/components/app-logo.tsx
 M resources/js/components/app-shell.tsx
 M resources/js/components/app-sidebar-header.tsx
 M resources/js/components/app-sidebar.tsx
 D resources/js/components/appearance-dropdown.tsx
 M resources/js/components/appearance-tabs.tsx
 M resources/js/components/breadcrumbs.tsx
 M resources/js/components/delete-user.tsx
 D resources/js/components/heading-small.tsx
 M resources/js/components/heading.tsx
 D resources/js/components/icon.tsx
 M resources/js/components/input-error.tsx
 M resources/js/components/nav-footer.tsx
 M resources/js/components/nav-main.tsx
 M resources/js/components/nav-user.tsx
 M resources/js/components/text-link.tsx
 M resources/js/components/ui/alert.tsx
 M resources/js/components/ui/avatar.tsx
 M resources/js/components/ui/badge.tsx
 M resources/js/components/ui/breadcrumb.tsx
 M resources/js/components/ui/button.tsx
 M resources/js/components/ui/card.tsx
 M resources/js/components/ui/checkbox.tsx
 M resources/js/components/ui/collapsible.tsx
 M resources/js/components/ui/dialog.tsx
 M resources/js/components/ui/dropdown-menu.tsx
 M resources/js/components/ui/icon.tsx
 M resources/js/components/ui/input.tsx
 M resources/js/components/ui/label.tsx
 M resources/js/components/ui/navigation-menu.tsx
 M resources/js/components/ui/placeholder-pattern.tsx
 M resources/js/components/ui/select.tsx
 M resources/js/components/ui/separator.tsx
 M resources/js/components/ui/sheet.tsx
 M resources/js/components/ui/sidebar.tsx
 M resources/js/components/ui/skeleton.tsx
 M resources/js/components/ui/toggle-group.tsx
 M resources/js/components/ui/toggle.tsx
 M resources/js/components/ui/tooltip.tsx
 M resources/js/layouts/app-layout.tsx
 M resources/js/layouts/app/app-header-layout.tsx
 M resources/js/layouts/app/app-sidebar-layout.tsx
 M resources/js/layouts/auth-layout.tsx
 M resources/js/layouts/auth/auth-card-layout.tsx
 M resources/js/layouts/auth/auth-simple-layout.tsx
 M resources/js/layouts/auth/auth-split-layout.tsx
 M resources/js/layouts/settings/layout.tsx
 M resources/js/lib/utils.ts
 M resources/js/pages/auth/confirm-password.tsx
 M resources/js/pages/auth/forgot-password.tsx
 M resources/js/pages/auth/login.tsx
 M resources/js/pages/auth/register.tsx
 M resources/js/pages/auth/reset-password.tsx
 M resources/js/pages/auth/verify-email.tsx
 M resources/js/pages/dashboard.tsx
 M resources/js/pages/settings/appearance.tsx
 D resources/js/pages/settings/password.tsx
 M resources/js/pages/settings/profile.tsx
 M resources/js/pages/welcome.tsx
 D resources/js/ssr.jsx
 M resources/js/types/index.ts
 M resources/views/app.blade.php
 D routes/auth.php
 M routes/settings.php
 M routes/web.php
 D storage/framework/views/.gitignore
 M tests/Feature/Auth/AuthenticationTest.php
 M tests/Feature/Auth/EmailVerificationTest.php
 M tests/Feature/Auth/PasswordConfirmationTest.php
 M tests/Feature/Auth/PasswordResetTest.php
 M tests/Feature/Auth/RegistrationTest.php
 M tests/Feature/DashboardTest.php
 M tests/Feature/Settings/PasswordUpdateTest.php
 M tests/Feature/Settings/ProfileUpdateTest.php
 M tests/Pest.php
 M tests/TestCase.php
 M tests/Unit/ExampleTest.php
 M tsconfig.json
 D vite.config.js
?? .omo/
?? "Sehatly Logo Icon.png"
?? Sehatly.svg
?? "WhatsApp Image 2026-09-26 at 7.07.11 PM.jpeg"
?? app/Actions/
?? app/Concerns/
?? app/Http/Controllers/Settings/SecurityController.php
?? app/Http/Middleware/HandleAppearance.php
?? app/Http/Requests/Settings/PasswordUpdateRequest.php
?? app/Http/Requests/Settings/ProfileDeleteRequest.php
?? app/Http/Requests/Settings/TwoFactorAuthenticationRequest.php
?? app/Providers/FortifyServiceProvider.php
?? config/fortify.php
?? config/inertia.php
?? database/migrations/2024_01_01_000000_create_passkeys_table.php
?? database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php
?? resources/js/components/alert-error.tsx
?? resources/js/components/manage-passkeys.tsx
?? resources/js/components/manage-two-factor.tsx
?? resources/js/components/passkey-item.tsx
?? resources/js/components/passkey-register.tsx
?? resources/js/components/passkey-verify.tsx
?? resources/js/components/password-input.tsx
?? resources/js/components/two-factor-recovery-codes.tsx
?? resources/js/components/two-factor-setup-modal.tsx
?? resources/js/components/ui/input-otp.tsx
?? resources/js/components/ui/sonner.tsx
?? resources/js/components/ui/spinner.tsx
?? resources/js/hooks/use-clipboard.ts
?? resources/js/hooks/use-current-url.ts
?? resources/js/hooks/use-flash-toast.ts
?? resources/js/hooks/use-two-factor-auth.ts
?? resources/js/pages/auth/two-factor-challenge.tsx
?? resources/js/pages/settings/security.tsx
?? resources/js/types/auth.ts
?? resources/js/types/global.d.ts
?? resources/js/types/navigation.ts
?? resources/js/types/ui.ts
?? storage/framework/views/03cc807da253af92657ac893f02c75cd.php
?? storage/framework/views/1c750558978c1a04ecb9bc15ca3d8184.php
?? storage/framework/views/28e03be6747b8d65e739931bee420402.php
?? storage/framework/views/2a07b541d45c04639243c480f7ec1fa0.php
?? storage/framework/views/2df470a44d7d75c25b6f4ee3c18b9681.php
?? storage/framework/views/2e6cfc3b341f79735ec004f75f44e599.php
?? storage/framework/views/4f940d525b0f8823a04fd81a2c7e3f5c.php
?? storage/framework/views/58f6b5ecbad88fc4da15879dbc7f6e69.blade.php
?? storage/framework/views/70d214c895d2fadd40a8fe95f8a4bfe8.php
?? storage/framework/views/75a03e88b2fa6e0356c1f8560e763ffc.php
?? storage/framework/views/899b99377823e5e6635fd299b458197f.php
?? storage/framework/views/8aeb4f5ca1c72273d34abbc2cfd8afb4.php
?? storage/framework/views/8c1d4e3f01577a2a5db68c549a437dc0.php
?? storage/framework/views/946659c6c0ff34e2544fe7ad251d26cb.php
?? storage/framework/views/97528e45f02488deb39275600b6e1efa.php
?? storage/framework/views/97869ba6f5305e38b231e4d4720218c8.php
?? storage/framework/views/9ca82cd8d4a0be337b0a037f98ec3e6c.php
?? storage/framework/views/a1222ca1476faf7c04bc6f1077ff4d84.php
?? storage/framework/views/a6053ac552568690d62a23c5981d5d8e.php
?? storage/framework/views/ab8bfa0ea1e71c62e87481cf40856106.php
?? storage/framework/views/b88eda47346ea98a00224748a2e2c29f.php
?? storage/framework/views/c183d6883eec30ca0d02e801348829b3.php
?? storage/framework/views/dc8aa91d8f0d6223355f949054104e6e.php
?? storage/framework/views/dca1a29b69452d307c2c30a7b9cc0a6e.blade.php
?? storage/framework/views/e49fbd5933d5d97a1bc0f1fbff00d3dd.php
?? storage/framework/views/ead3153803c25e62363e631c561e647b.php
?? telemedicine_test.sql
?? vite.config.ts
```

### 1.1 The 26 compiled Blade views that were exposed by the deleted ignore stub

These are build artifacts, not user work. `storage/framework/views/.gitignore`
was tracked in `main` as `*` + `!.gitignore` and had been deleted in the
worktree, which is why they appeared. The stub was restored by todo 1, so
79 − 26 = **53** untracked remained (see §3.1).

```
storage/framework/views/03cc807da253af92657ac893f02c75cd.php
storage/framework/views/1c750558978c1a04ecb9bc15ca3d8184.php
storage/framework/views/28e03be6747b8d65e739931bee420402.php
storage/framework/views/2a07b541d45c04639243c480f7ec1fa0.php
storage/framework/views/2df470a44d7d75c25b6f4ee3c18b9681.php
storage/framework/views/2e6cfc3b341f79735ec004f75f44e599.php
storage/framework/views/4f940d525b0f8823a04fd81a2c7e3f5c.php
storage/framework/views/58f6b5ecbad88fc4da15879dbc7f6e69.blade.php
storage/framework/views/70d214c895d2fadd40a8fe95f8a4bfe8.php
storage/framework/views/75a03e88b2fa6e0356c1f8560e763ffc.php
storage/framework/views/899b99377823e5e6635fd299b458197f.php
storage/framework/views/8aeb4f5ca1c72273d34abbc2cfd8afb4.php
storage/framework/views/8c1d4e3f01577a2a5db68c549a437dc0.php
storage/framework/views/946659c6c0ff34e2544fe7ad251d26cb.php
storage/framework/views/97528e45f02488deb39275600b6e1efa.php
storage/framework/views/97869ba6f5305e38b231e4d4720218c8.php
storage/framework/views/9ca82cd8d4a0be337b0a037f98ec3e6c.php
storage/framework/views/a1222ca1476faf7c04bc6f1077ff4d84.php
storage/framework/views/a6053ac552568690d62a23c5981d5d8e.php
storage/framework/views/ab8bfa0ea1e71c62e87481cf40856106.php
storage/framework/views/b88eda47346ea98a00224748a2e2c29f.php
storage/framework/views/c183d6883eec30ca0d02e801348829b3.php
storage/framework/views/dc8aa91d8f0d6223355f949054104e6e.php
storage/framework/views/dca1a29b69452d307c2c30a7b9cc0a6e.blade.php
storage/framework/views/e49fbd5933d5d97a1bc0f1fbff00d3dd.php
storage/framework/views/ead3153803c25e62363e631c561e647b.php
```

### 1.2 The 53 untracked files that ARE real user work (pre-commit, post-repair)

These must all end up tracked. This is the list the baseline commit protects.

```
.omo/boulder.json
.omo/drafts/sehatly-telemedicine-platform.md
.omo/plans/sehatly-telemedicine-platform.md
.omo/run-continuation/ses_f212f5d4fffeZ7Us3YWqeirGqL.json
.omo/run-continuation/ses_f216e24b0ffeLifWhbvx6P4udZ.json
.omo/run-continuation/ses_f216e4be5ffeELt4HZophl1Np5.json
.omo/run-continuation/ses_f21949bd0ffe2vz1sjSOvM5krn.json
.omo/run-continuation/ses_f21b06965ffeTgw7hrVjFHTlAJ.json
.omo/run-continuation/ses_f21b09b68ffeEzhvT5c8RbM3PP.json
.omo/run-continuation/ses_f21b0c82cffeOP3GlFVbXulsFC.json
.omo/run-continuation/ses_f21b0fc80ffeRNICd5XPBXYQIJ.json
.omo/run-continuation/ses_f21b23a8effeQivqfjyg7e7tU6.json
Sehatly Logo Icon.png
Sehatly.svg
WhatsApp Image 2026-09-26 at 7.07.11 PM.jpeg
app/Actions/Fortify/CreateNewUser.php
app/Actions/Fortify/ResetUserPassword.php
app/Concerns/PasswordValidationRules.php
app/Concerns/ProfileValidationRules.php
app/Http/Controllers/Settings/SecurityController.php
app/Http/Middleware/HandleAppearance.php
app/Http/Requests/Settings/PasswordUpdateRequest.php
app/Http/Requests/Settings/ProfileDeleteRequest.php
app/Http/Requests/Settings/TwoFactorAuthenticationRequest.php
app/Providers/FortifyServiceProvider.php
config/fortify.php
config/inertia.php
database/migrations/2024_01_01_000000_create_passkeys_table.php
database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php
docs/pre-existing-defects.md
resources/js/components/alert-error.tsx
resources/js/components/manage-passkeys.tsx
resources/js/components/manage-two-factor.tsx
resources/js/components/passkey-item.tsx
resources/js/components/passkey-register.tsx
resources/js/components/passkey-verify.tsx
resources/js/components/password-input.tsx
resources/js/components/two-factor-recovery-codes.tsx
resources/js/components/two-factor-setup-modal.tsx
resources/js/components/ui/input-otp.tsx
resources/js/components/ui/sonner.tsx
resources/js/components/ui/spinner.tsx
resources/js/hooks/use-clipboard.ts
resources/js/hooks/use-current-url.ts
resources/js/hooks/use-flash-toast.ts
resources/js/hooks/use-two-factor-auth.ts
resources/js/pages/auth/two-factor-challenge.tsx
resources/js/pages/settings/security.tsx
resources/js/types/auth.ts
resources/js/types/global.d.ts
resources/js/types/navigation.ts
resources/js/types/ui.ts
telemedicine_test.sql
vite.config.ts
web/.gitignore
web/package-lock.json
web/package.json
web/playwright.config.ts
```

(`docs/` + `web/` x4 are the 5 files todo 1 created. 53 original user files + 5
created = **58** at commit time.)

---

## 2. TOOLCHAIN (M1–M4)

### 2.1 M1 — project-local PHP

`Get-Command php -All` showed the first `php` on `PATH` is the **wrong** one:

```
C:\php-8.2.29\php.exe
C:\laragon\bin\php\php-8.2.29-nts-Win32-vs16-x64\php.exe
```

`Get-ChildItem C:\laragon\bin\php -Directory`:

```
C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64
C:\laragon\bin\php\php-8.2.29-nts-Win32-vs16-x64
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64
```

**Exact path used for every PHP/composer/artisan call in this todo:**

```
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe
```

`php -v` (exit **0**):

```
PHP 8.4.17 (cli) (built: Jan 13 2026 17:46:46) (NTS Visual C++ 2022 x64)
Copyright (c) The PHP Group
Built by The PHP Group
Zend Engine v4.4.17, Copyright (c) Zend Technologies
```

`php artisan --version` (exit **0**):

```
Laravel Framework 13.33.0
```

### 2.2 M2 — Dart SDK was absent, now installed

`dart --version` at start:

```
& : The term 'dart' is not recognized as the name of a cmdlet, function,
script file, or operable program. ... FullyQualifiedErrorId : CommandNotFoundException
```

The id given in the task text (`Dart.DartSDK`) **does not exist** on the `winget`
source (`No package found matching input criteria.`, exit −1978335212).
`winget search dart` found the real id, `Google.DartSDK` 3.13.2.

Install command actually run (exit **0**, 600 s timeout):

```powershell
winget install --id Google.DartSDK --exact --source winget --accept-package-agreements --accept-source-agreements --silent --disable-interactivity
```

```
Found Dart SDK [Google.DartSDK] Version 3.13.2
Downloading https://storage.googleapis.com/dart-archive/channels/stable/release/3.13.2/sdk/dartsdk-windows-x64-release.zip
Successfully verified installer hash
Extracting archive...
Successfully extracted archive
Starting package install...
Path environment variable modified; restart your shell to use the new value.
Command line alias added: "dart"
Command line alias added: "dartaotruntime"
Successfully installed
```

`dart --version` (exit **0**), after refreshing `PATH` from the User environment
variable:

```
Dart SDK version: 3.13.2 (stable) (Tue Aug 25 01:01:12 2026 -0700) on "windows_x64"
```

Binary:
`C:\Users\axioo\AppData\Local\Microsoft\WinGet\Packages\Google.DartSDK_Microsoft.Winget.Source_8wekyb3d8bbwe\dart-sdk\bin\dart.exe`

Standalone Dart SDK — no Flutter SDK, no `pubspec.yaml`, no `mobile/`.

### 2.3 M4 — MySQL 8 observation (no reconfiguration)

`Get-NetTCPConnection -State Listen` -> port **3306** listening, PID 17484.
Client: `C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe`

```
PS> mysql -h 127.0.0.1 -P 3306 -u root --connect-timeout=10 -e "SELECT VERSION() AS server_version, CURRENT_USER() AS auth_user, @@port AS port;"
server_version  auth_user       port
8.0.30          root@localhost  3306
exit=0
```

`SHOW DATABASES;` (exit **0**) — `sehatly` already exists alongside unrelated
databases `db_simprapkl`, `gawaiseken`, `laravel`, `manajemen-surat`,
`trading_journal`, `ukk`, `ukk_pengaduan_sekolah`.

**MySQL 8 is reachable at `127.0.0.1:3306` with `root` / empty password**, which
matches `.env` and `.env.example` as found. Todo 2 is de-risked.

### 2.4 Other environment facts

`jq --version` -> `CommandNotFoundException` (not installed; substitution rule
recorded in `docs/pre-existing-defects.md` §1.3).
`node -v` -> `v24.13.1`; `npm -v` -> `11.17.0`.
`composer --version` -> `Composer version 2.9.4 2026-01-22 14:08:50`, invoked as
`C:\ProgramData\ComposerSetup\bin\composer.bat` with the 8.4.17 `php` first on
`PATH`.

---

## 3. PRE-COMMIT REPAIRS

### 3.1 M7 — compiled Blade cache excluded

`git show HEAD:storage/framework/views/.gitignore` -> `*` + `!.gitignore`.
That tracked stub had been deleted in the worktree; it was restored
**byte-identically** (verified: `git status --porcelain --
storage/framework/views/.gitignore` is empty, i.e. identical to `main`).

`git check-ignore -v storage/framework/views/03cc807da253af92657ac893f02c75cd.php`
(exit **0**):

```
storage/framework/views/.gitignore:1:*	storage/framework/views/03cc807da253af92657ac893f02c75cd.php
```

Untracked count 79 -> **53** immediately after the restore.

### 3.2 M5 — `web/` Playwright harness created

`Test-Path web` -> `False` before. Created `web/package.json`,
`web/package-lock.json`, `web/playwright.config.ts`, `web/.gitignore`.
`npm install --no-audit --no-fund` in `web/` (exit **0**, 900 s timeout):

```
added 3 packages in 3s
```

Run with `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` so the dependency resolves without
pulling browser binaries; whoever runs the first real spec must run
`npx playwright install`. `npx playwright --version` (exit **0**):

```
Version 1.63.0
```

`web/.gitignore` (a file todo 1 owns, so the user's already-modified root
`.gitignore` was left alone) keeps `web/node_modules` out of the commit —
verified: `git check-ignore -v web/node_modules/playwright` -> exit 0,
`web/.gitignore:4:/node_modules`.

### 3.3 M11/M12 — manifest repaired, lock refreshed

`composer.json` `require` gained `"laravel/passkeys": "^0.2.1"` — the version
already resolved in `composer.lock` (`v0.2.1`) and already installed at
`vendor/laravel/passkeys` (`installed.json` -> `v0.2.1`). `name` /
`description` changed from the starter-kit defaults to
`sehatly/telemedicine-api` + a telemedicine description.

`composer validate` **before** refreshing the lock (exit **2**):

```
./composer.json is valid but your composer.lock has some errors
# Lock file errors
- The lock file is not up to date with the latest changes in composer.json, it is recommended that you run `composer update` or `composer update <package name>`.
```

`composer update --lock --no-scripts` (exit **0**, 600 s timeout):

```
Updating dependencies
Nothing to modify in lock file
Writing lock file
Installing dependencies from lock file (including require-dev)
Nothing to install, update or remove
```

**Stale-state proof that no version was silently changed:** a 146-entry
name+version snapshot of `packages` + `packages-dev` taken immediately before
the command and again after was compared with `Compare-Object`:

```
after_hash=16c594d6deac6e4b3e26e9e52761a4ba
pkg_count_after=146
=== package-version diff (update --lock effect) ===
NO package version changes - update --lock only rewrote content-hash
```

`content-hash` moved `5c25a1b148b331626159dada7986c7d0` ->
`16c594d6deac6e4b3e26e9e52761a4ba`. 146 packages before and after.

`composer validate` **after** (exit **0**):

```
./composer.json is valid
```

### 3.4 M9 — Pint

`php vendor/bin/pint` (exit **0**), i.e. a real fix run, not `--test`:

```
{"tool":"pint","result":"passed"}
```

No files were reformatted.

---

## 4. NEGATIVE / FAILURE QA (section 8)

### 4.1 (a) `git commit -am` would NOT have protected the untracked user files

**A throwaway scratch branch was deliberately NOT created.** Reasoning: to
demonstrate this with a real commit you must (1) `git checkout -b` scratch,
(2) `git commit -am`, then (3) `git checkout` back to the original ref. Step 3
reverts the tracked files to `2d3b3b4`, i.e. it **removes the user's
modifications from the worktree**, and the subsequent `git branch -D` would make
the only copy of that content unreachable — real data loss, on the one worktree
every one of the 53 remaining todos depends on. The task explicitly permits
declining the scratch branch.

Instead the same assertion was proved **empirically and non-destructively** with
a throwaway `GIT_INDEX_FILE` (a temp index, never committed, no branch created,
real index untouched). `git commit -am` stages exactly `git add -u`, so seeding a
temp index from `HEAD` and running `git add -u` vs `git add -A` reproduces both
commit modes exactly.

| | `git commit -am` (simulated `git add -u`) | `git add -A` (simulated) |
|---|---|---|
| files staged | **134** | **192** |
| `telemedicine_test.sql` staged | **0 matches (NOT staged)** | **1 (staged)** |
| `Sehatly Logo Icon.png` / `Sehatly.svg` / `WhatsApp Image ...jpeg` | **0 matches (NOT staged)** | **3 (staged)** |
| `2024_01_01_000000_create_passkeys_table.php` | **0 matches (NOT staged)** | **staged** |
| `2025_08_14_170933_add_two_factor_columns_to_users_table.php` | **0 matches (NOT staged)** | **staged** |
| resulting untracked count | **58 — STILL NON-ZERO** | **0** |

Recorded pre-state numbers for the same comparison: pre-state untracked count
was **79**; after the M7 ignore-stub restore it was **53**; at commit time it was
**58** (53 user files + 5 created by todo 1).

`git status --porcelain | Measure-Object -Line` after both simulations:

```
real_status_entries = 176
```

— identical to the pre-state, confirming the real index and worktree were not
disturbed by the negative QA.

**Conclusion: the strengthened `git add -A` criterion is the one that actually
protects the untracked user files.** `git commit -am` would have left
`telemedicine_test.sql`, all three rebranding assets, and both untracked
migrations uncommitted — exactly the state that makes todo 30's deletion guard
fire spuriously and invites a future executor to delete uncommitted user work.

**Cleanup receipts:**

```
temp index (simulating git commit -am):  removed (%TEMP%\opencode\t1-neg-indexA)
temp index (simulating git add -A):       removed (%TEMP%\opencode\t1-neg-indexB)
scratch branch baseline-negative-test:    NOT CREATED (see reasoning above)
```

### 4.2 (b) removing `laravel/passkeys` DOES make `composer validate` report the mismatch

Done on an isolated **copy** of `composer.json` + `composer.lock` in
`%TEMP%\opencode\t1-negqa-lock`, with **only** the `laravel/passkeys` line
removed from `require` (`name`, `php: ^8.3` and the other 6 requires left
intact — verified after the edit: `php=^8.3`, `passkeys_present=False`,
`require_count=6`).

`composer validate` in the scratch dir (exit **2**):

```
./composer.json is valid but your composer.lock has some errors
# Lock file errors
- The lock file is not up to date with the latest changes in composer.json, it is recommended that you run `composer update` or `composer update <package name>`.
SCRATCH_VALIDATE_EXIT=2
```

So the `laravel/passkeys` require entry is precisely what keeps the manifest and
the lock in agreement — the drift recorded in `docs/pre-existing-defects.md` §3.1
was a real, `validate`-detectable defect, not a cosmetic inconsistency.

**Cleanup receipt:**

```
scratch composer copy: removed (Remove-Item -Recurse -Force %TEMP%\opencode\t1-negqa-lock; Test-Path -> False)
```

The real repository was never in the broken state: the negative test ran
entirely outside the repo, and `git status --porcelain` afterwards is unchanged
from the pre-commit state.

### 4.3 (c) the `php` constraint was never touched

Pre-state `composer.json:12` and post-repair `composer.json` both read
`"php": "^8.3"`. The `git diff HEAD~1 HEAD -- composer.json` hunk in §6 covers
only `name`, `description` and the added `laravel/passkeys` line — no `php`
line appears. The PHP gate did not silently edit the manifest.

---

## 5. THE BASELINE COMMIT

<!-- POST-COMMIT SECTION APPENDED BELOW -->
