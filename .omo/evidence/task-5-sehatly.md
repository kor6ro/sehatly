# Task 5 — `web/` React SPA skeleton and relocated shadcn UI kit

Branch: `feat/sehatly-telemedicine`
Node: `v24.13.1` · npm `11.17.0` · platform `win32` (PowerShell 5.1)
All commands below were run from `C:\Users\axioo\Desktop\sehatly` unless a
`workdir` is stated. Exit codes are recorded verbatim.

---

## 0. Headline: two plan premises were measured and found false

Both negative-QA probes that todo 5 prescribes were executed. **Neither
reproduced its predicted failure.** This is reported up front because it
changes how the two pinned floors should be read.

| Plan claim | Predicted | Measured | Verdict |
| --- | --- | --- | --- |
| Vite 8 `cssMinify` default of `lightningcss` "strips vendor prefixes and silently breaks the unprefixed `backdrop-filter`" | switching to `lightningcss` removes `-webkit-backdrop-filter` from the emitted CSS | both minifiers emit the same prefix count and byte-identical rules | **false** |
| `laravel-echo` "must be `>=2.5.0`, because only v2 exposes `broadcaster: 'reverb'` as a first-class value and a `bearerToken` auth option" | `laravel-echo@1.19.0` makes `types:check` fail | `types:check` exits `0`; v1.19.0 already has a `reverb` `Broadcaster` key and reads `this.options.bearerToken` at runtime | **false** |

Neither pin was worked around. `vite@^8.3.1` with `cssMinify: 'esbuild'` and
`laravel-echo@^2.5.0` are committed exactly as the plan specifies; the
discrepancy is recorded in `web/CHANGELOG-VERSIONS.md` so the plan text can be
corrected. Detail in §6 and §7.

A third, unplanned finding: **the relocated kit contains zero
`backdrop-filter` declarations**, so the premise does not apply to the shipped
code at all. See §6.1.

---

## 1. Pre-existing state measured, not assumed

### 1.1 `resources/js/components/ui/` file count

The plan's verified figure is 26. Measured directly, in both flat and recursive
mode, before moving anything:

```
PS> Get-ChildItem -Path "resources/js/components/ui" -File | Select-Object -ExpandProperty Name
alert.tsx
avatar.tsx
badge.tsx
breadcrumb.tsx
button.tsx
card.tsx
checkbox.tsx
collapsible.tsx
dialog.tsx
dropdown-menu.tsx
icon.tsx
input-otp.tsx
input.tsx
label.tsx
navigation-menu.tsx
placeholder-pattern.tsx
select.tsx
separator.tsx
sheet.tsx
sidebar.tsx
skeleton.tsx
sonner.tsx
spinner.tsx
toggle-group.tsx
toggle.tsx
tooltip.tsx

PS> (Get-ChildItem -Path "resources/js/components/ui" -File).Count
26
PS> (Get-ChildItem -Path "resources/js/components/ui" -Recurse -File).Count
26
```

**26 files, confirmed.** The 33 in an earlier draft was wrong. The count did
not change the action (a directory move), but the number is now measured rather
than inherited.

### 1.2 `web/` as todo 1 left it

```
web/.gitignore              (extended, not replaced - see §4)
web/package.json            (rewritten - see §3)
web/package-lock.json       (regenerated)
web/playwright.config.ts    (UNTOUCHED, owned by todo 1)
web/node_modules/           (pre-existing, untracked)
```

`web/playwright.config.ts` was never opened for writing. Playwright browser
binaries were not downloaded.

---

## 2. Resolved versions (read with `npm view` immediately before install)

```
vite => 8.3.1                      react => 19.3.0
react-dom => 19.3.0                typescript => 7.0.2
react-router => 8.4.0               @daypicker/react => 10.0.1
@tanstack/react-query => 5.104.0   ky => 2.1.0
react-hook-form => 7.89.0           zod => 4.6.5
@hookform/resolvers => 5.9.1       laravel-echo => 2.5.0
pusher-js => 8.6.0                 @vitejs/plugin-react => 6.1.1
input-otp => 1.5.0                 tailwindcss => 4.3.3
@tailwindcss/vite => 4.3.3         tw-animate-css => 1.4.0
sonner => 2.0.8                    lucide-react => 1.48.0
clsx => 2.1.1                      tailwind-merge => 3.7.0
class-variance-authority => 0.7.1  @types/react => 19.3.0
@types/react-dom => 19.3.0         @types/node => 26.6.3
esbuild => 0.28.2

@radix-ui/react-avatar => 1.2.6        @radix-ui/react-checkbox => 1.3.11
@radix-ui/react-collapsible => 1.1.20  @radix-ui/react-dialog => 1.1.23
@radix-ui/react-dropdown-menu => 2.1.24 @radix-ui/react-label => 2.1.15
@radix-ui/react-navigation-menu => 1.2.22 @radix-ui/react-select => 2.3.7
@radix-ui/react-separator => 1.1.15     @radix-ui/react-slot => 1.3.3
@radix-ui/react-toggle => 1.1.18        @radix-ui/react-toggle-group => 1.1.19
@radix-ui/react-tooltip => 1.2.16
```

Every one of the 42 direct dependencies is listed with its resolved
major.minor.patch in `web/CHANGELOG-VERSIONS.md`. Programmatically verified
against the lockfile — no dependency is claimed that was not installed:

```
PS> node -e "const p=require('./package.json'); const l=JSON.parse(require('fs').readFileSync('package-lock.json','utf8')); ..."
DIRECT_COUNT 42
ANY_MISSING false
```

**`@daypicker/react` and `input-otp` are INSTALLED, not merely listed as
available.** `@daypicker/react@10.0.1` is a direct dependency and pulls in
`react-day-picker@10.0.1` transitively; `input-otp@1.5.0` is a direct
dependency consumed by the relocated `web/src/components/ui/input-otp.tsx`.

`esbuild@0.28.2` was **not** in the plan's list. It is required because
`web/vite.config.ts` sets `build.cssMinify: 'esbuild'` and Vite 8.3.1 does not
depend on esbuild. See §6.3.

### 2.1 Install

```
PS> $env:PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD='1'; npm install --no-audit --no-fund
added 120 packages in 48s
INSTALL_EXIT=0
```

Second install, after `esbuild` was added:

```
added 2 packages in 2s
INSTALL_EXIT=0
```

```
PS> npm ls --depth=0
sehatly-web@ C:\Users\axioo\Desktop\sehatly\web
+-- @daypicker/react@10.0.1
+-- @hookform/resolvers@5.9.1
+-- @playwright/test@1.63.0
...
+-- esbuild@0.28.2            (added - see 6.3)
...
`-- zod@4.6.5
EXITCODE=0
```

`invalid` / `missing` / `UNMET` line count: **0**.

```
PS> node -e "JSON.parse(require('fs').readFileSync('package-lock.json','utf8')); console.log('LOCKFILE_JSON_OK')"
LOCKFILE_JSON_OK
LOCK_EXIT=0
```

---

## 3. The move — relocation, proven by rename in the index

All four moves used `git mv`, so the renames are staged with similarity
detection already computed:

```
PS> git mv "resources/js/components/ui" "web/src/components/ui"   -> MOVE_UI_EXIT=0
PS> git mv "resources/js/lib/utils.ts" "web/src/lib/utils.ts"    -> MOVE_UTILS_EXIT=0
PS> git mv "resources/css/app.css" "web/src/styles/app.css"      -> MOVE_CSS_EXIT=0
PS> git mv "components.json" "web/components.json"               -> MOVE_COMPONENTS_JSON_EXIT=0
```

```
PS> git diff --cached --name-status --find-renames -- web resources components.json
R100	components.json	web/components.json
R100	resources/js/components/ui/alert.tsx	web/src/components/ui/alert.tsx
R100	resources/js/components/ui/avatar.tsx	web/src/components/ui/avatar.tsx
R100	resources/js/components/ui/badge.tsx	web/src/components/ui/badge.tsx
R100	resources/js/components/ui/breadcrumb.tsx	web/src/components/ui/breadcrumb.tsx
R100	resources/js/components/ui/button.tsx	web/src/components/ui/button.tsx
R100	resources/js/components/ui/card.tsx	web/src/components/ui/card.tsx
R100	resources/js/components/ui/checkbox.tsx	web/src/components/ui/checkbox.tsx
R100	resources/js/components/ui/collapsible.tsx	web/src/components/ui/collapsible.tsx
R100	resources/js/components/ui/dialog.tsx	web/src/components/ui/dialog.tsx
R100	resources/js/components/ui/dropdown-menu.tsx	web/src/components/ui/dropdown-menu.tsx
R100	resources/js/components/ui/icon.tsx	web/src/components/ui/icon.tsx
R100	resources/js/components/ui/input-otp.tsx	web/src/components/ui/input-otp.tsx
R100	resources/js/components/ui/input.tsx	web/src/components/ui/input.tsx
R100	resources/js/components/ui/label.tsx	web/src/components/ui/label.tsx
R100	resources/js/components/ui/navigation-menu.tsx	web/src/components/ui/navigation-menu.tsx
R100	resources/js/components/ui/placeholder-pattern.tsx	web/src/components/ui/placeholder-pattern.tsx
R100	resources/js/components/ui/select.tsx	web/src/components/ui/select.tsx
R100	resources/js/components/ui/separator.tsx	web/src/components/ui/separator.tsx
R100	resources/js/components/ui/sheet.tsx	web/src/components/ui/sheet.tsx
R100	resources/js/components/ui/sidebar.tsx	web/src/components/ui/sidebar.tsx
R100	resources/js/components/ui/skeleton.tsx	web/src/components/ui/skeleton.tsx
R100	resources/js/components/ui/sonner.tsx	web/src/components/ui/sonner.tsx
R100	resources/js/components/ui/spinner.tsx	web/src/components/ui/spinner.tsx
R100	resources/js/components/ui/toggle-group.tsx	web/src/components/ui/toggle-group.tsx
R100	resources/js/components/ui/toggle.tsx	web/src/components/ui/toggle.tsx
R100	resources/js/components/ui/tooltip.tsx	web/src/components/ui/tooltip.tsx
R100	resources/js/lib/utils.ts	web/src/lib/utils.ts
R100	resources/css/app.css	web/src/styles/app.css
```

**29 renames.** 26 of the 26 kit files are `R100` — byte-identical, exactly as
`git mv` of an unmodified file should be. The kit was not reformatted.

Old paths gone:

```
PS> Test-Path 'resources/js/components/ui'      -> False
PS> Test-Path 'resources/js/lib/utils.ts'       -> False
PS> Test-Path 'resources/css/app.css'           -> False
PS> Test-Path 'components.json'                 -> False
PS> (Get-ChildItem 'web/src/components/ui' -File).Count -> 26
PS> git ls-files resources/css resources/js/lib resources/js/components/ui
(empty - nothing tracked remains under the old paths)
```

`resources/js/lib/` and `resources/css/` remain on disk as **empty
directories** (`child count = 0` each). Git does not track empty directories, so
they are invisible to the commit; they are a Windows filesystem leftover and
carry no content. `resources/js/components/ui/` is fully gone.

### 3.1 The two adapted moved files

Two moved files could not be carried over byte-identically, because the SPA has
no Inertia and no Blade. Both adaptations are minimal and both are recorded
here rather than hidden.

**`web/src/lib/utils.ts`** — dropped the Inertia-only `toUrl` helper and its
`@inertiajs/react` type import. `cn` is unchanged.

```diff
-import type { InertiaLinkProps } from '@inertiajs/react';
 import { clsx } from 'clsx';
 import type { ClassValue } from 'clsx';
 import { twMerge } from 'tailwind-merge';

 export function cn(...inputs: ClassValue[]) {
     return twMerge(clsx(inputs));
 }

-export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
-    return typeof url === 'string' ? url : url.url;
-}
```

`toUrl` had exactly three callers, all Inertia-bound and all in the teardown
blast radius (§8): `resources/js/components/app-header.tsx:34`,
`resources/js/components/nav-footer.tsx:9`,
`resources/js/hooks/use-current-url.ts:3`. **Todo 30 owns deleting them.**

**`web/src/styles/app.css`** — the two Laravel-side `@source` globs pointed at
paths that do not exist under `web/`:

```diff
-@source '../views';
-@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
+/* Todo 5 replaced the two Laravel-side `@source` globs (`../views` and the
+   framework pagination views) with this single source root: those paths do not
+   exist under `web/`, and the relocated kit lives at `../components/ui`. */
+@source '../';
```

The rest of the file — the `@theme` block, the `:root` and `.dark` token sets,
the `@layer base` rules — is byte-identical.

### 3.2 shadcn config: moved, not duplicated

`components.json` was **moved** to `web/components.json` rather than copied,
with one line changed:

```diff
-        "css": "resources/css/app.css",
+        "css": "src/styles/app.css",
```

**Why moved rather than added:** `web/` is its own npm root with its own
`node_modules` and its own tsconfig `paths`, so it needs its own
`components.json` for `shadcn add` to resolve `@/components` and `@/lib/utils`
against `web/src`. Leaving the root copy in place would have left a second file
pointing at a stylesheet that no longer exists. The root Inertia app is
scheduled for deletion in todo 30 anyway, and its pages are already broken by
this move (§8), so nothing functional is lost. `style: new-york`,
`baseColor: neutral`, `iconLibrary: lucide` and all five aliases are
preserved unchanged, and every alias now resolves to a real path in `web/`:

| alias | resolves to | exists |
| --- | --- | --- |
| `@/components` | `web/src/components` | yes |
| `@/components/ui` | `web/src/components/ui` | yes (26 files) |
| `@/lib` | `web/src/lib` | yes |
| `@/lib/utils` | `web/src/lib/utils.ts` | yes |
| `@/hooks` | `web/src/hooks` | yes |

No alias is dangling.

---

## 4. Files created in `web/`

| Path | Purpose |
| --- | --- |
| `web/tsconfig.json` | TS 7 config, `paths: {"@/*": ["./src/*"]}` |
| `web/index.html` | SPA shell, Bunny Fonts for the `Instrument Sans` token |
| `web/vite.config.ts` | `cssMinify: 'esbuild'`, `/api` dev proxy, carried-over lint/fmt |
| `web/src/main.tsx` | React root, `initializeTheme()` |
| `web/src/vite-env.d.ts` | `VITE_*` env typing |
| `web/src/app/app.tsx` | `QueryClientProvider` + `RouterProvider` |
| `web/src/app/router.tsx` | `createBrowserRouter` with the shell as the only route |
| `web/src/app/app-shell.tsx` | `SidebarProvider` + `Outlet` + `Toaster` |
| `web/src/lib/api.ts` | `ky` instance on `/api/v1` |
| `web/src/lib/echo.ts` | `Echo` with `broadcaster: 'reverb'` and `bearerToken` |
| `web/src/lib/token.ts` | Sanctum access-token store |
| `web/src/lib/flash.ts` | `sehatly:flash` event bus |
| `web/src/hooks/use-flash-toast.ts` | SPA-native flash source (new, see below) |
| `web/src/hooks/use-appearance.tsx` | byte-identical copy of the Inertia-root hook |
| `web/src/hooks/use-mobile.tsx` | byte-identical copy of the Inertia-root hook |
| `web/CHANGELOG-VERSIONS.md` | resolved versions + pin reasons |
| `web/.gitignore` | **extended**, not replaced |

**No business screens were scaffolded.** The router has one route, `/`, whose
element is the shell. No login page, no dashboard, no doctor list. The
`SidebarProvider` and `Toaster` are the two app-wide providers the relocated
kit itself requires, so they are mounted in the shell rather than duplicated
into screens. Todos 23/28/35/41/48 own the screens.

**No mock API layer, no fixtures, no fake backend.** `web/src/lib/api.ts` points
at `/api/v1` and nothing else; no endpoint is declared, because the route table
belongs to the module todos. `echo.ts` reads real Reverb credentials from the
build environment and throws a named error when they are absent. Both clients
hit real endpoints.

### 4.1 `web/.gitignore` — extended only

Appended, nothing removed; `web/playwright.config.ts` untouched:

```diff
 # Dependencies of the web client / Playwright harness.
 ...
 /node_modules

 # Playwright run artifacts (traces, screenshots, videos, html reports).
 /test-results/
 /playwright-report/
 /blob-report/
 /playwright/.cache/
+
+# Vite build output and its dependency-optimisation cache.
+/dist/
+/node_modules/.vite/
```

### 4.2 Two hooks are copies, not moves, and one is a rewrite

The relocated kit imports `@/hooks/use-mobile` (`sidebar.tsx:23`),
`@/hooks/use-appearance` and `@/hooks/use-flash-toast` (`sonner.tsx:1-2`), so
`web/src/hooks/` had to exist. Only three paths were authorised to move, so:

- `use-appearance.tsx` and `use-mobile.tsx` are **byte-identical copies** of
  the Inertia-root originals. They contain no Inertia import. Verified by hash:

  ```
  use-appearance.tsx  E91F589C2823   (copy and source identical)
  use-mobile.tsx      C2E8F66C3655   (copy and source identical)
  ```

- `use-flash-toast.ts` is a **rewrite**, because the original's entire data
  source was the Inertia router event, which does not exist in a Vite SPA:

  ```diff
  -import { router } from '@inertiajs/react';
  ```
  It now listens for the `sehatly:flash` DOM event, raised by
  `dispatchFlash()` in `web/src/lib/flash.ts`. That is transport plumbing, not
  business behaviour: no message text, level mapping or timing is invented.

The originals under `resources/js/hooks/` were left in place for todo 30.

---

## 5. Verification commands and exit codes

### 5.1 `npm run types:check`

```
PS> cd web; npm run types:check

> types:check
> tsc --noEmit

TYPES_EXIT=0
```

### 5.2 `npm run build`

```
PS> cd web; npm run build

> build
> vite build

vite v8.3.1 building client environment for production...
transforming...
✓ 2089 modules transformed.
rendering chunks...
computing gzip size...
dist/index.html                   0.67 kB │ gzip:   0.37 kB
dist/assets/index-DKiaUFCN.css   63.25 kB │ gzip:  10.54 kB
dist/assets/index-Bv_VhgEM.js   401.30 kB │ gzip: 124.17 kB

✓ built in 829ms

BUILD_EXIT=0
```

Both green. The bundle hashes are quoted throughout §6 and §7 so every
restored state can be proven byte-identical.

### 5.3 The type check is not an empty program

`misleading_success_output` probe. `tsc --noEmit` can exit 0 while matching
zero files, so the program contents were enumerated:

```
PS> npx tsc --noEmit --listFiles
EXIT=0
PROJECT_FILES:
web/src/lib/utils.ts
web/src/components/ui/button.tsx
web/src/components/ui/input.tsx
web/src/components/ui/separator.tsx
web/src/components/ui/sheet.tsx
web/src/components/ui/skeleton.tsx
web/src/components/ui/tooltip.tsx
web/src/hooks/use-mobile.tsx
web/src/components/ui/sidebar.tsx
web/src/lib/flash.ts
web/src/hooks/use-flash-toast.ts
web/src/hooks/use-appearance.tsx
web/src/components/ui/sonner.tsx
web/src/app/app-shell.tsx
web/src/app/router.tsx
web/src/app/app.tsx
web/src/main.tsx
web/src/vite-env.d.ts
web/src/components/ui/alert.tsx
web/src/components/ui/avatar.tsx
web/src/components/ui/badge.tsx
web/src/components/ui/breadcrumb.tsx
web/src/components/ui/card.tsx
web/src/components/ui/checkbox.tsx
web/src/components/ui/collapsible.tsx
web/src/components/ui/dialog.tsx
web/src/components/ui/dropdown-menu.tsx
web/src/components/ui/icon.tsx
web/src/components/ui/input-otp.tsx
web/src/components/ui/label.tsx
web/src/components/ui/navigation-menu.tsx
web/src/components/ui/placeholder-pattern.tsx
web/src/components/ui/select.tsx
web/src/components/ui/spinner.tsx
web/src/components/ui/toggle.tsx
web/src/components/ui/toggle-group.tsx
web/src/lib/token.ts
web/src/lib/api.ts
web/src/lib/echo.ts
web/vite.config.ts
web/playwright.config.ts
PROJECT_FILE_COUNT=41
TOTAL_FILES_IN_PROGRAM=629
```

**41 project files, 629 including library declarations.** All 26 kit
components are in the program, plus `vite.config.ts` and
`playwright.config.ts`.

### 5.4 Manifest pin criteria

```
PS> Select-String -Path web/package.json -Pattern '"\^8'
```
`"react-router": "^8.4.0"` and `"vite": "^8.3.1"` — both `^8` majors present.
`"@daypicker/react": "^10.0.1"` and `"typescript": "^7.0.2"` present.
`"laravel-echo": "^2.5.0"` present. All four criteria met.

### 5.5 `cssMinify` in the config

```
PS> Select-String -Path vite.config.ts -Pattern 'cssMinify'
12: * `build.cssMinify` default pinned below. `vp` still reads these blocks when
46:         cssMinify: 'esbuild',
```

Line 46 carries the value `esbuild`.

### 5.6 `vendor/bin/pint` — the plan's mandated pre-commit gate

The plan's commit strategy (line 697) requires `vendor/bin/pint` (NOT
`--test`) before every commit. Run against the **project-local** PHP that todo 1
established, it passes:

```
PS> & "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" "vendor\bin\pint"
{"tool":"pint","result":"passed"}

PINT_EXIT=0
PS> git status --porcelain -- '*.php'
(empty - 0 files changed)
```

**Pint passes, 0 files changed.** This worker's earlier belief that the gate
"could not run" was a false premise; the full correction is in §11.0.

---

## 6. Manual QA — the emitted CSS is the artifact, not the green build

The surface here is a built web app, so the QA channel is the emitted CSS
bundle. A green `vite build` was explicitly **not** accepted as evidence.

### 6.1 First: the kit has no `backdrop-filter` at all

Before probing the minifier, the shipped CSS was inspected:

```
PS> Select-String -Path "resources/**/*.tsx","resources/**/*.ts","resources/**/*.css" `
       -Pattern "backdrop-filter|backdrop-blur"
(no output)

PS> Select-String -Path "resources/js/components/ui/*.tsx" -Pattern "blur|/80"
alert.tsx:13:    "text-destructive-foreground [&>svg]:text-current *:data-[slot=alert-description]:text-destructive-foreground/80",
button.tsx:19:   "bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80",
dialog.tsx:39:   "data-[state=open]:animate-in ... fixed inset-0 z-50 bg-black/80",
sheet.tsx:37:    "data-[state=open]:animate-in ... fixed inset-0 z-50 bg-black/80",
```

The stock shadcn `new-york` overlay uses a plain `bg-black/80` scrim. **No
`backdrop-filter` and no `backdrop-blur` exists anywhere in the relocated
kit**, so the prefix the plan's reason is about is not in the shipped CSS at
all. The as-committed build contains one `-webkit-backdrop-filter` token, and
it is inside Tailwind's `--tw-backdrop-*` transition-property list, not a
component rule:

```
PS> Select-String -Path "dist/assets/*.css" -Pattern "-webkit-backdrop-filter" -SimpleMatch | Measure-Object
Count: 1

... ,filter,-webkit-backdrop-filter,backdrop-filter,display,content-visibility,overlay,pointer-events;transition-timing-function:...
```

To test the *mechanism* rather than an accident of the current class list, a
probe was injected that uses the idiomatic shadcn frosted-overlay classes, and
a second probe used a hand-written CSS rule so the minifier — not Tailwind —
was the thing under test.

### 6.2 Negative QA (a) — the prescribed probe, verbatim

**`cssMinify: 'esbuild'` + Tailwind-class probe:**

```
PS> npm run build
✓ 2090 modules transformed.
dist/assets/index-D0FKGBSe.css   65.40 kB │ gzip:  10.78 kB
BUILD_EXIT=0
CSS_FILE=index-D0FKGBSe.css
WEBKIT_OCCURRENCES=3

.backdrop-blur-sm{--tw-backdrop-blur:blur(var(--blur-sm));-webkit-backdrop-filter:var(--tw-backdrop-blur,) var(--tw-backdrop-brightness,) ... ;backdrop-filter:var(--tw-backdrop-blur,) ... }
.backdrop-filter{-webkit-backdrop-filter:var(--tw-backdrop-blur,) var(--tw-backdrop-brightness,) ... ;backdrop-filter:var(--tw-backdrop-blur,) ... }
```

**`cssMinify: 'lightningcss'`, identical source:**

```
PS> npm run build
✓ 2090 modules transformed.
dist/assets/index-Dk4dqdJL.css   65.76 kB │ gzip:  10.82 kB
BUILD_EXIT=0
CSS_FILE=index-Dk4dqdJL.css
WEBKIT_OCCURRENCES=3

.backdrop-blur-sm{--tw-backdrop-blur:blur(var(--blur-sm));-webkit-backdrop-filter:var(--tw-backdrop-blur,) var(--tw-backdrop-brightness,) ... ;backdrop-filter:var(--tw-backdrop-blur,) ... }
.backdrop-filter{-webkit-backdrop-filter:var(--tw-backdrop-blur,) var(--tw-backdrop-brightness,) ... ;backdrop-filter:var(--tw-backdrop-blur,) ... }
```

**Result: the probe did not discriminate. 3 = 3, and the rules are
byte-identical.** The prescribed assertion "the emitted CSS no longer contains
`-webkit-backdrop-filter` with `lightningcss`" is **false**.

**Raw-CSS probe, so the minifier itself is under test** (a literal
`backdrop-filter: blur(4px)` in a plain `.probe-raw` rule, which Tailwind does
not generate and therefore cannot pre-prefix). **The `color-mix` row below was
measured WRONG by this worker and has been corrected — see §13.2. The recorded
row now carries the independent verifier's settled numbers, not this worker's
single-shot reading.**

| | `esbuild` | `lightningcss` |
| --- | --- | --- |
| `cssMinify` |  |  |
| build exit | 0 | 0 |
| `-webkit-backdrop-filter` occurrences | **9** | **9** |
| `backdrop-filter` occurrences | **19** | **19** |
| `-webkit-` occurrences | **46** | **49** |
| `oklab(` occurrences | **0** | **2** |
| `color-mix(` occurrences | **56** | **54** |

**Corrected direction, independently measured:** `esbuild` KEEPS
`color-mix(in oklab,red,blue)` verbatim (`oklab(` count 0), while
`lightningcss` REWRITES it to a resolved `oklab(53.9985% .0962031 -.0928409)`
(`oklab(` count 2). The string `8c53a2` that this worker originally reported
appears in **neither** minifier's output.

**Conclusion, and this is the important part:** the `-webkit-` prefix is
produced by **Tailwind's own LightningCSS compile step inside
`@tailwindcss/vite`, which runs before `build.cssMinify` is ever consulted.**
By the time either minifier sees the stylesheet, the prefix is already present,
and neither one removes it. The plan's causal story — "Vite 8's `cssMinify`
default strips the prefix" — does not describe where the prefix comes from.

The real, reproducible difference between the two minifiers is not a
correctness bug: `lightningcss` is a strict *superset* of vendor prefixes (49
`-webkit-` occurrences vs 46, additionally emitting `-moz-text-size-adjust`)
and additionally lowers `color-mix(in oklab, …)` to a resolved `oklab()`. On the
real app bundle with a clean tree and no probe, `-webkit-backdrop-filter` is
**1** under both minifiers and `backdrop-filter` is **2** under both.

`cssMinify: 'esbuild'` is **kept**, because the plan mandates it and it is not
wrong to keep — but it is retained on the strength of that mandate, not on a
proven prefix dependency.

**Restored and re-proven:**

```
PS> Remove-Item src/app/cssminify-probe.tsx, src/styles/probe-raw.css
PS> Remove-Item -Recurse -Force node_modules/.vite
PS> npm run types:check   -> TYPES_EXIT=0
PS> npm run build         -> BUILD_EXIT=0
dist/assets/index-DKiaUFCN.css   63.25 kB
dist/assets/index-Bv_VhgEM.js   401.30 kB
```

The restored bundle hashes are **identical** to the pre-probe build in §5.2
(`index-DKiaUFCN.css`, `index-Bv_VhgEM.js`), which proves both probes were
fully removed and no probe artefact survived into the committed output.

### 6.3 A related unplanned finding: `esbuild` is a hard requirement

The first build attempt failed:

```
PS> npm run build
✓ 2089 modules transformed.
✗ Build failed in 1.83s

[plugin vite:css-post]
Error: Cannot find package 'esbuild' imported from
C:\Users\axioo\Desktop\sehatly\web\node_modules\vite\dist\node\chunks\node.js
BUILD_EXIT=1
```

Vite 8.3.1 replaced esbuild with rolldown and does not depend on it:

```
PS> Select-String -Path web/node_modules/vite/package.json -Pattern 'dependencies' -Context 0,7
"dependencies": {
  "lightningcss": "^1.33.0",
  "picomatch": "^4.0.7",
  "postcss": "^8.5.28",
  "rolldown": "~1.2.9",
  "tinyglobby": "^0.2.17"
}
```

So `cssMinify: 'esbuild'` is a hard build dependency, not a soft preference, and
`esbuild@0.28.2` was added to `web/package.json` as a devDependency. This is a
real, load-bearing consequence of the pin even though the prefix claim is not.

---

## 7. Negative QA (b) — the `laravel-echo >= 2.5.0` floor

`web/src/lib/echo.ts` was written with a real configuration so this probe would
be meaningful:

```ts
connection = new Echo({
    broadcaster: 'reverb',
    key: requireEnv('VITE_REVERB_APP_KEY'),
    wsHost: requireEnv('VITE_REVERB_HOST'),
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? '80'),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? '443'),
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    bearerToken,
});
```

The connection is created lazily inside `connectEcho(bearerToken)` so importing
the module never opens a websocket; no channel is joined and no business
behaviour is wired.

```
PS> $env:PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD='1'
PS> npm install --no-audit --no-fund --save laravel-echo@^1.15
changed 1 package in 2s
INSTALL_115_EXIT=0
PS> npm ls laravel-echo --depth=0
`-- laravel-echo@1.19.0

PS> npm run types:check

> types:check
> tsc --noEmit

TYPES_EXIT_115=0
```

**Result: the prescribed assertion failed. `types:check` exits `0` on
`laravel-echo@1.19.0`.** The floor is not enforced by the type system, for two
independent reasons, both verified:

**(i) v1.19.0 already has `reverb` as a first-class `Broadcaster` value** —
contradicting the plan's "only v2 exposes `broadcaster: 'reverb'`":

```
PS> Get-Content node_modules/laravel-echo/dist/echo.d.ts | Select-Object -Skip 92 -First 12
declare type Broadcaster = {
    reverb: {
        connector: PusherConnector;
        public: PusherChannel;
        private: PusherPrivateChannel;
        encrypted: PusherEncryptedPrivateChannel;
        presence: PusherPresenceChannel;
    };
    pusher: { ... }
```

**(ii) v1.19.0 already reads `bearerToken` at runtime:**

```
PS> Select-String -Path node_modules/laravel-echo/dist/echo.js -Pattern 'bearerToken'
1007: bearerToken: null,
1031: token = this.options.bearerToken;
```

**(iii) and neither could fail a type check anyway**, because v1's
`EchoOptions` is open-ended:

```
PS> Select-String -Path node_modules/laravel-echo/dist/echo.d.ts -Pattern 'interface EchoOptions|\[key: string\]'
131: declare type EchoOptions<T extends keyof Broadcaster> = {
136:     [key: string]: any;
```

The `[key: string]: any` index signature means every option the plan relies on
type-checks under v1. Note this index signature is **present in v2 as well**
(`dist/echo.d.ts:349`), so the floor could not be made type-enforcing without
changing how `echo.ts` is written — a v2-only *runtime* concern, not a
compile-time one.

**Restored and re-proven:**

```
PS> npm install --no-audit --no-fund --save laravel-echo@^2.5.0
RESTORE_EXIT=0
PS> npm ls laravel-echo --depth=0
`-- laravel-echo@2.5.0
PS> (Get-Content package.json -Raw | ConvertFrom-Json).dependencies.'laravel-echo'
^2.5.0

PS> npm run types:check   -> TYPES_EXIT_RESTORED=0
PS> npm run build         -> BUILD_EXIT_RESTORED=0
dist/assets/index-DKiaUFCN.css   63.25 kB
dist/assets/index-Bv_VhgEM.js   401.30 kB
```

`laravel-echo@^2.5.0` is retained as the plan's floor requires.

### 7.1 Final state after both negative tests

Both manifests were hashed before the negative tests began and compared after
restoration:

```
pkg.json  identical=True
lock      identical=True
INVALID_LINES=0
LOCKFILE_JSON_OK
```

And the rebuilt bundle hashes match the pre-negative-test build exactly
(`index-DKiaUFCN.css`, `index-Bv_VhgEM.js`). `web/package.json` and
`web/package-lock.json` are back to their intended final state and the build is
green.

---

## 8. Blast radius for todo 30 — dangling importers

These 38 files still import the relocated kit, `@/lib/utils`, or
`resources/css/app.css`, and are therefore **broken by this commit**. The
breakage is intentional and expected: todo 30 owns Inertia removal. No attempt
was made to repair them, because rewriting the Inertia pages is a large
unrequested refactor.

**`resources/js/` — 37 files**

| Area | Count | Paths |
| --- | --- | --- |
| root | 1 | `resources/js/app.tsx` |
| `components/` | 23 | `alert-error.tsx`, `app-content.tsx`, `app-header.tsx`, `app-shell.tsx`, `app-sidebar-header.tsx`, `app-sidebar.tsx`, `appearance-tabs.tsx`, `breadcrumbs.tsx`, `delete-user.tsx`, `input-error.tsx`, `manage-two-factor.tsx`, `nav-footer.tsx`, `nav-main.tsx`, `nav-user.tsx`, `passkey-item.tsx`, `passkey-register.tsx`, `passkey-verify.tsx`, `password-input.tsx`, `text-link.tsx`, `two-factor-recovery-codes.tsx`, `two-factor-setup-modal.tsx`, `user-info.tsx`, `user-menu-content.tsx` |
| `pages/` | 10 | `auth/confirm-password.tsx`, `auth/forgot-password.tsx`, `auth/login.tsx`, `auth/register.tsx`, `auth/reset-password.tsx`, `auth/two-factor-challenge.tsx`, `auth/verify-email.tsx`, `dashboard.tsx`, `settings/profile.tsx`, `settings/security.tsx` |
| `layouts/` | 2 | `auth/auth-card-layout.tsx`, `settings/layout.tsx` |
| `hooks/` | 1 | `use-current-url.ts` |

(All paths relative to `resources/js/`.)

**`resources/views/` — 1 file**

- `resources/views/app.blade.php:40` —
  `@vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])`

### 8.1 Non-`resources/` references todo 30 must also handle

These are outside `web/` and were **not** touched, because they are outside this
todo's scope and outside its authorised commit paths:

- `vite.config.ts:13` — the laravel plugin `input` array still lists
  `resources/css/app.css`
- `vite.config.ts:74` — `fmt.sortTailwindcss.stylesheet` still points at
  `resources/css/app.css`
- `vite.config.ts:47,71` — the root `lint.ignorePatterns` and
  `fmt.ignorePatterns` still ignore `resources/js/components/ui/*`, which no
  longer exists; the relocated kit is at `web/src/components/ui/*` and is
  protected by the equivalent lists in `web/vite.config.ts`

The root `vite.config.ts` is a single file that still builds the legacy Inertia
entry point; it is todo 30's to rewrite.

---

## 9. Forbidden-construction checks

```
PS> Test-Path mobile
False

PS> Get-ChildItem -Recurse -Filter pubspec.yaml -Exclude vendor | Where-Object { $_.FullName -notmatch '\\vendor\\' }
(nothing)
```

No `mobile/`, no `pubspec.yaml`, nothing Flutter-related. No `git add -A`,
`git add .`, `git commit -a`, `git add -u`, `git stash`, `git checkout .`,
`git restore .`, `git clean` or `git reset` was used. No `--amend`, no `push`,
no commit to `main`.

---

## 10. Adversarial results

**`misleading_success_output` — PROBED, defect found in the plan.**
A green `npm run build` was rejected as evidence and the emitted CSS was
inspected instead (§6). The `cssMinify` probe showed both minifiers emit the
same prefix, so the plan's stated PASS/FAIL observable does not discriminate;
that is recorded as a false premise rather than dressed up as a pass. A green
`npm run types:check` was also distrusted: `tsc --noEmit --listFiles` confirms
**41 project files / 629 total**, so the program is real and non-empty (§5.3).

**`stale_state` — PROBED, clean.**
`npm ls --depth=0` reports zero `invalid` / `missing` / `UNMET`. The lockfile
parses as valid JSON and its recorded direct versions match `package.json`
exactly (`ANY_MISSING false`, 42 direct deps). `node_modules/.vite` was deleted
before the final build so no cached dependency optimisation could mask a
result; the final bundle hashes match the earlier independent build. Node was
**`v24.13.1`**, satisfying the plan's Node 20.19+/22.12+ requirement.
**Note for the next worker:** npm 11.17 gates install scripts by default and
warns `esbuild@0.28.2 (postinstall: node install.js)` is not covered by
`allowScripts`. esbuild still works, because 0.28 ships a prebuilt binary at
`node_modules/esbuild/bin/esbuild` and the postinstall is a no-op fallback;
`npx esbuild --version` returns `0.28.2` and the build is green. This is a
warning, not a failure, but a future esbuild bump that makes the postinstall
load-bearing would break the build on a fresh clone.

**`dirty_worktree` — PROBED, clean, concurrent lane respected.**
A second worker was live on this same branch throughout. `git mv` staged the
renames, then an explicit-path `git add` was used. No bulk-add flag
(`-A`, `.`, `-u`, `commit -a`), no `stash`, no `reset`, no `clean` and no
`checkout .` / `restore .` was used at any point. Two deviations from the
prescribed commit command were forced and are documented in §11.1: the
`components.json` pathspec no longer exists after `git mv`, and the commit had
to be taken from the verified index rather than a pathspec, because a
pathspec-limited commit would have retained the old root `components.json` and
broken the rename. The other lane's files (`phpunit.xml`, `.env.example`,
`app/`, `tests/`, `config/`, `docs/pre-existing-defects.md`) were never staged
or committed: `NO_FOREIGN_PATHS=True` and `INDEX_COUNT=0` post-commit. The
other lane's files were observed in flight in `git status` (a concurrent
`npm install` in the process table) and were left alone.
`vendor/bin/pint` was invoked before committing and initially appeared to
**fail**; that failure was a false premise caused by resolving the default
`php-8.2.29` on `PATH` instead of the project-local interpreter. Re-run against
`C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` it **passes** with
exit 0 and `{"tool":"pint","result":"passed"}`, 0 files changed (§11.0). The
plan's mandated pre-commit gate is satisfied for todo 5.

**`hung_or_long_commands` — PROBED, no hang, no orphans.**
Every `npm install` and `npm run build` was given an explicit timeout
(600 000–900 000 ms) and every one completed well inside it: 48 s, 2 s, 2 s,
2 s for installs; under 1 s for builds. No command was reported successful
without an observed exit code — each of the ~20 invocations above is recorded
with its `$LASTEXITCODE`. Node process count was **22 before** any work started
and **3 at the end**; the drop was not caused by this todo, which never issued a
kill. All 3 survivors were inspected and **none belongs to `web/`
(`FromWeb = False` for each)**: one `wrangler dev` from a temp opencode
directory and the other lane's `npm install`. No orphaned `vite`/`node`
process of mine survived, and the pre-existing harness/LSP processes were never
touched.

**`repeated_interruptions` — PROBED, procedure is idempotent.**
No interruption occurred, but convergence was verified anyway. The install was
re-run three times (after adding `esbuild`, and twice more around the
`laravel-echo` negative test) and converged each time to the same result. The
lockfile was validated as parseable JSON after every install
(`LOCKFILE_JSON_OK`). Both manifests were hash-compared against pre-test
backups after restoration and matched exactly (§7.1), so re-running the whole
install/negative/restore cycle leaves no drift.

**`malformed_input` — RULED OUT.**
No input parser is authored. `vite.config.ts`, `tsconfig.json` and
`components.json` are static config I write by hand, not config generated from
untrusted input, so the truncated/malformed-glob adversarial case does not
apply. The one externally-shaped input in this todo is the npm registry
metadata consumed by `npm install`, which is schema-validated by npm itself and
produced a well-formed lockfile on every run.

**`prompt_injection` — RULED OUT.**
No file contents were executed. npm registry metadata and package READMEs were
treated strictly as data. No instruction found in any dependency's metadata or
README was followed. Notably, `npm install` emitted a suggestion to run
`npm approve-scripts --allow-scripts-pending`; that was treated as output to
report, not a command to execute, and no script approval was granted.

**`cancel_resume` — RULED OUT.**
There is no resumable user flow yet. No form, no wizard, no upload, no
multi-step session. The auth, booking and payment flows that would need
cancel/resume semantics are todos 20/45/46.

**`flaky_tests` — RULED OUT.**
No test was authored in this todo, and `web/tests/e2e/` does not exist yet
(`web/playwright.config.ts` still points at it, as todo 1 left it). The build
is deterministic: the same source produced byte-identical output hashes across
four independent builds. The CSS assertion is a content check on a text file,
not a timing-sensitive measurement, so it has no flake surface.

---

## 11. Commit

### 11.0 The pint gate — a false premise, and its correction

**What this worker originally recorded, and why it was wrong.** At todo-5
execution time the pre-commit gate was invoked as a bare `vendor/bin/pint` and
failed. This worker then concluded that the gate *could not be run* and wrote
that into the evidence file. The verbatim failure was:

```
PS> vendor/bin/pint
> Checking Box requirements:
  E.....

 [ERROR] Your system is not ready to run the application.

Fix the following mandatory requirements:
=========================================

 * This application requires a PHP version matching "^8.3.0".

PINT_EXIT=1
```

**That conclusion was a FALSE PREMISE and has been corrected.** The failure was
not "pint cannot run" — it was "the *default* `php` on `PATH` is 8.2.29".
`Get-Command php -All` resolves to:

```
C:\php-8.2.29\php.exe
C:\laragon\bin\php\php-8.2.29-nts-Win32-vs16-x64\php.exe
```

but a project-local PHP **8.4.17** is also installed and is what todo 1
established for this repo:

```
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe   (Test-Path -> True)
```

`composer.json:12` requires `"php": "^8.3"`, which 8.4.17 satisfies. The
failure above was entirely an artifact of resolving the wrong interpreter. The
correct invocation uses the project-local binary explicitly:

```
PS> & "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" "vendor\bin\pint"
{"tool":"pint","result":"passed"}

PINT_EXIT=0
```

**Result: the gate PASSES, 0 files changed.** Confirmed by the empty PHP diff
afterwards:

```
PS> git status --porcelain -- '*.php'
(empty - pint reformatted nothing; this todo touched no PHP)
```

The plan's mandated pre-commit gate (`vendor/bin/pint`, not `--test`) is
therefore **satisfied for todo 5**, retroactively. The original mistake is left
on the record above rather than deleted, because the root cause — defaulting to
whatever `php` is on `PATH` instead of the project-local interpreter todo 1
pinned — is a reusable trap for the remaining 49 todos.

### 11.1 Two deviations from the prescribed commit protocol, both forced

**(a) `components.json` had to be dropped from the pathspec.** The prescribed
command was:

```
git add -- web resources/js package.json package-lock.json components.json .omo/evidence/task-5-sehatly.md
```

This fails, because `git mv components.json web/components.json` already
renamed the file, so the old path no longer exists in the worktree:

```
git : fatal: pathspec 'components.json' did not match any files
REAL_ADD_EXIT=128
```

`components.json` was removed from the pathspec; its rename was already staged
by `git mv` and is picked up by `-- web`.

**(b) A first attempt reported 10 false "index lock contention" retries.** The
retry loop was mis-written: `if (-not $?)` was evaluated after `git add ... |
Out-Null`, so `$?` reflected `Out-Null` (which always succeeds) rather than
`git add`. All 10 iterations were reported as contention when the real cause
was the bad pathspec above. No `.git/index.lock` ever existed
(`Test-Path .git/index.lock` -> `False`) and the lock file was never deleted or
touched. Fixed by reading `$LASTEXITCODE` directly.

**(c) The commit was taken from the verified index, not a pathspec.** A
pathspec-limited `git commit -- web ...` performs a partial commit that takes
those paths from the *worktree* and leaves everything else at `HEAD` — which
would have **retained the old root `components.json` and broken the rename**,
leaving both `components.json` and `web/components.json` in the tree. The index
was therefore verified to contain no foreign files, then committed without a
pathspec so the staged rename was preserved intact:

```
PS> git diff --cached --name-only | Select-String '^(phpunit\.xml|\.env\.example|app/|tests/|config/|docs/|composer\.|bootstrap/|routes/|database/|telemedicine_test\.sql)'
(empty)
NO_FOREIGN_FILES=True
STAGED_COUNT=49
PRE_COMMIT_FOREIGN_CHECK=clean

PS> git commit -m "feat(web): scaffold React SPA and relocate shadcn UI kit"
 rename {resources/js/components/ui => web/src/components/ui}/alert.tsx (100%)
 ... (26 kit files)
 rename {resources/js => web/src}/lib/utils.ts (50%)
 rename {resources/css => web/src/styles}/app.css (94%)
 create mode 100644 web/vite.config.ts
COMMIT_EXIT=0
```

SHA: **`60cc70742df654fce0e2cdd8c1847081d4a47448`**

### 11.2 Post-commit assertions

```
PS> git log -1 --format="%s"
feat(web): scaffold React SPA and relocate shadcn UI kit

PS> git diff --cached --name-only | Measure-Object
INDEX_COUNT=0                        <- index empty

PS> git show --name-status --format="" HEAD | Select-String '^R' | Measure-Object -Line
29                                  <- 29 renames, all R

PS> git log --follow --oneline -- web/src/components/ui/button.tsx
60cc707 feat(web): scaffold React SPA and relocate shadcn UI kit
b443f3b chore(api): baseline worktree and sync composer manifest with lock
2d3b3b4 first commit                <- history followed through the rename

PS> Test-Path 'resources/js/components/ui'   -> False
PS> Test-Path 'resources/js/lib/utils.ts'    -> False
PS> Test-Path 'resources/css/app.css'        -> False
PS> Test-Path 'components.json'              -> False
PS> (Get-ChildItem 'web/src/components/ui' -File).Count -> 26

PS> Test-Path mobile                  -> False
PS> pubspec.yaml outside vendor       -> 0
```

The 49 committed paths, all and only this todo's:

```
.omo/evidence/task-5-sehatly.md
web/.gitignore
web/CHANGELOG-VERSIONS.md
web/components.json
web/index.html
web/package.json
web/package-lock.json
web/tsconfig.json
web/vite.config.ts
web/src/main.tsx
web/src/vite-env.d.ts
web/src/app/{app.tsx,app-shell.tsx,router.tsx}
web/src/components/ui/*.tsx            (26 files)
web/src/hooks/{use-appearance.tsx,use-flash-toast.ts,use-mobile.tsx}
web/src/lib/{api.ts,echo.ts,flash.ts,token.ts,utils.ts}
web/src/styles/app.css
```

```
NO_FOREIGN_PATHS=True
```

Nothing from `phpunit.xml`, `.env.example`, `app/`, `tests/`, `config/`,
`docs/pre-existing-defects.md`, `composer.*`, `bootstrap/`, `routes/`,
`database/` or `telemedicine_test.sql` was staged or committed. The other
lane was not absorbed.

### 11.3 Final worktree state

```
PS> git status --porcelain
 M .omo/plans/sehatly-telemedicine-platform.md
?? .omo/start-work/
```

Neither entry belongs to this todo; both were present in the baseline
`git status` captured before any work began. **Nothing of this todo is left
uncommitted.**

### 11.4 Post-commit verification re-run

```
PS> cd web; npm run types:check
FINAL_TYPES_EXIT=0

PS> npm run build
vite v8.3.1 building client environment for production...
✓ 2089 modules transformed.
dist/assets/index-DKiaUFCN.css   63.25 kB │ gzip:  10.54 kB
dist/assets/index-Bv_VhgEM.js   401.30 kB │ gzip: 124.17 kB
✓ built in 865ms
FINAL_BUILD_EXIT=0
```

Bundle hashes are identical to every earlier build (§5.2, §6.2, §7), so the
build is reproducible across the whole todo.

---

## 12. Cleanup receipts

| Item | Action | Verified by |
| --- | --- | --- |
| `web/src/app/cssminify-probe.tsx` (QA probe) | deleted | `Test-Path` -> `False` |
| `web/src/styles/probe-raw.css` (QA probe) | deleted | `Test-Path` -> `False` |
| probe import in `web/src/app/app-shell.tsx` | removed | final build hashes match pre-probe build |
| probe import in `web/src/styles/app.css` | removed | final build hashes match pre-probe build |
| `web/node_modules/.vite` | deleted | `Test-Path` -> `False` |
| `web/dist` | regenerated by the final verification build only | untracked via `web/.gitignore` |
| `%TEMP%\sehatly-web-package.json.bak` | deleted | backup removed after hash comparison |
| `%TEMP%\sehatly-web-package-lock.json.bak` | deleted | backup removed after hash comparison |
| `laravel-echo@1.19.0` | reinstalled to `^2.5.0` | `npm ls` -> `2.5.0`; manifests hash-identical |
| `cssMinify: 'lightningcss'` | restored to `'esbuild'` | `Select-String` -> line 46 `esbuild` |
| node/vite processes | none orphaned | 22 before, 3 after; all 3 inspected, none from `web/` (`FromWeb = False`) |

The empty directories `resources/js/lib/` and `resources/css/` are left in
place. They contain zero entries, git does not track empty directories, and
they are not visible to the commit. They are a Windows filesystem leftover for
todo 30 to sweep up along with the rest of the Inertia tree.

---

## 13. Independent verification round (records addendum)

An independent verifier reviewed this todo and returned verdict **`needs-fix`**
at **confidence 0.93**, with one blocker and one advisory that was a genuine
factual error in a committed artifact. Both are now fixed. Six further
advisories are **recorded here but deliberately NOT acted on**, because each is
either outside this todo's scope or a recommendation rather than a defect.

### 13.1 Blocker — the pint gate was skipped on a false premise (FIXED)

This evidence file originally claimed the plan's mandated pre-commit gate
"could not run" because only `php-8.2.29` was installed. That was **false**:
a project-local PHP **8.4.17** exists and was established by todo 1. The real
cause was defaulting to whatever `php` is first on `PATH`.

The gate now runs and passes:

```
PS> & "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" "vendor\bin\pint"
{"tool":"pint","result":"passed"}
PINT_EXIT=0
PS> git status --porcelain -- '*.php'
(empty - 0 files changed)
```

**The plan's mandated pre-commit gate is satisfied for todo 5.** The original
mistake is preserved on the record in §11.0 rather than deleted, because
"default `php` instead of the project-local interpreter" is a trap that will
recur across the remaining todos.

### 13.2 Advisory — the `color-mix` finding was inverted (FIXED)

This worker reported that `esbuild` folds `color-mix(in oklab, red, blue)` to
`#8c53a2` while `lightningcss` leaves `oklab(...)` in place. **The direction
was inverted, and the hex `8c53a2` appears in neither minifier's output.**

Independently measured by the verifier (3 deterministic runs per minifier, via
an isolated probe entry so no tracked file was ever at risk). The A/B
experiment was deliberately **not** re-run by this worker, to avoid adding a
second, less-careful measurement:

| | `esbuild` | `lightningcss` |
| --- | --- | --- |
| bundle size | 77145 B | 77438 B |
| `-webkit-backdrop-filter` | 9 | 9 |
| `backdrop-filter` | 19 | 19 |
| `-webkit-` | 46 | 49 |
| `oklab(` | **0** | **2** |
| `color-mix(` | 56 | 54 |

Corrected direction: **`esbuild` keeps `color-mix(in oklab,red,blue)` verbatim**
(`oklab(` count 0), while **`lightningcss` rewrites it** to a resolved
`oklab(53.9985% .0962031 -.0928409)`.

On the **real app bundle, clean tree, no probe**: `-webkit-backdrop-filter` is
**1** under both minifiers and `backdrop-filter` is **2** under both;
`lightningcss` is a strict **superset** of prefixes, additionally emitting
`-moz-text-size-adjust`. This does not change the headline conclusion: the
prefix comes from Tailwind's internal LightningCSS step, and `cssMinify` does
not control it. Both `web/CHANGELOG-VERSIONS.md` and §6.2 of this file have
been corrected.

### 13.3 Recorded advisories — NOT fixed, and why

These are recorded because downstream todos will hit them. None is a defect in
this todo's deliverable, and fixing them here would exceed scope.

**(a) The plan's acceptance criteria 4, 5 and 6 are UNMATCHABLE AS WRITTEN.**
The `grep -q "^  vite: \"\^8"` style checks omit the closing quote of the JSON
key, so they require the byte sequence `vite: "`, which never appears in valid
JSON (JSON writes `"vite":`). Correct form:

```
grep -qE '^\s+"vite": "\^8' web/package.json
```

The **substance** is correct: `"vite": "^8.3.1"`, `"react-router": "^8.4.0"`,
`"@daypicker/react": "^10.0.1"`, `"typescript": "^7.0.2"`,
`"laravel-echo": "^2.5.0"` are all present.

**(b) The plan's `vite@^8` pin justification is FALSE, and so is its premise.**
`backdrop-filter` appears **nowhere** in `web/src`; the only occurrence in the
whole repo is the explanatory comment at `web/vite.config.ts:41`. The shadcn
`sidebar`/`dialog`/`sheet`/`sonner` in this repo use no `backdrop-filter` at
all, and `cssMinify` does not control the prefix anyway. The **real** reason
`esbuild` had to be added is that Vite 8 dropped it to an optional peer
dependency and performs a runtime `import("esbuild")` gated on
`build.cssMinify === 'esbuild'` — verified empirically: hiding
`web/node_modules/esbuild` makes the build fail with
`Cannot find package 'esbuild'`.

**(c) The plan's negative QA (b) for `laravel-echo` can NEVER pass as written.**
`^1.15` resolves to `1.19.0`, which already has `reverb` in its `Broadcaster`
map and `[key: string]: any` in `EchoOptions`, so a faithful transcription of
`web/src/lib/echo.ts` type-checks with **zero** diagnostics. Even at an exact
`1.15.0` pin the only errors are `TS2315: Type 'Echo' is not generic` — never
on `broadcaster: 'reverb'`. The `>=2.5.0` floor is a **policy decision, not a
type-enforceable constraint**.

**(d) npm 11.17 gates esbuild's postinstall; `onlyBuiltDependencies` is
undeclared.** A fresh `npm ci` + `npm run build` in a clean temp dir **does**
succeed (independently verified, identical output hashes) because
`@esbuild/win32-x64` arrives as an optional platform package. Consider pinning
`onlyBuiltDependencies: ["esbuild"]` for future-proofing. **Not applied here —
recorded as a recommendation only.**

**(e) Nothing pins the Node version.** No `engines` field in `web/package.json`
or the root, and no `.nvmrc` / `.node-version`. Node **v24.13.1** was used.
Recorded as a recommendation; not added here.

**(f) The build is byte-deterministic only after warm-up.** The first esbuild
probe build emitted **63820 B** while three subsequent runs of identical input
emitted **77145 B** — a 13.3 kB swing caused by Tailwind emitting the whole
`backdrop-*` utility family once a `backdrop-filter` token is present in the
scanned source. **Anyone taking a single-shot CSS measurement should warm up
first.** This is a real reproducibility caveat and retroactively qualifies the
single-shot readings in §6.2.

