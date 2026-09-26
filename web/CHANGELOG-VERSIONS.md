# web/ — resolved dependency versions

Every direct dependency of the Sehatly web client, with the **exact version
that was resolved and installed** (major, minor and patch). Each was read with
`npm view <package> version` immediately before installing.

- Lockfile: `web/package-lock.json` (`lockfileVersion` 3)
- Node: `v24.13.1`, npm `11.17.0`
- Verified with `npm ls --depth=0`: no `invalid`, no `missing`, no `UNMET`
- Both `@daypicker/react` and `input-otp` are **actually installed**, not merely
  listed as available. `@daypicker/react@10.0.1` is installed directly and
  pulls in `react-day-picker@10.0.1` as a transitive dependency; `input-otp@1.5.0`
  is installed directly and is consumed by the relocated
  `web/src/components/ui/input-otp.tsx`.

## Pinned majors (each with a written reason)

| Package | Range | Resolved | Reason for the pin |
| --- | --- | --- | --- |
| `vite` | `^8.3.1` | `8.3.1` | **Kept as the plan requires. See the measured finding below — the reason written in the plan does not hold.** Vite 8 did change the `build.cssMinify` default from `esbuild` to `lightningcss`. Measured on Vite 8.3.1 + Tailwind 4.3.3: **both** minifiers emit `-webkit-backdrop-filter`; the prefix is produced by Tailwind's own LightningCSS step inside `@tailwindcss/vite`, which runs *before* `build.cssMinify`. The pin is kept because the plan mandates it, not because the prefix claim is proven. The real, reproducible consequence of the pin is that **`esbuild` must be an explicit dependency**: Vite 8 no longer ships it, and `cssMinify: 'esbuild'` fails the build with `Cannot find package 'esbuild'` otherwise. |
| `react-router` | `^8.4.0` | `8.4.0` | **Verified.** v8 removed the `react-router-dom` package: `Test-Path web/node_modules/react-router-dom` is `False`, and `createBrowserRouter`, `RouterProvider` and `Outlet` are all exported from `react-router` itself. |
| `@daypicker/react` | `^10.0.1` | `10.0.1` | **Verified.** v10 renamed the package; `react-day-picker@10.0.1` is now only a transitive dependency of `@daypicker/react`, so the direct import must be `@daypicker/react`. |
| `typescript` | `^7.0.2` | `7.0.2` | **Verified and load-bearing.** The repo root pins `^5.7.2` (`package.json:46`), two majors behind. TS 7 rejects config the older compiler accepts: a `baseUrl` entry now fails with `error TS5102: Option 'baseUrl' has been removed`, which is exactly the class of drift the todo-53 DTOs would otherwise inherit. |
| `laravel-echo` | `^2.5.0` | `2.5.0` | **Kept as the plan requires. See the measured finding below — the reason written in the plan does not hold.** The plan states that only v2 exposes `broadcaster: 'reverb'` as a first-class value and a `bearerToken` auth option. Measured against `laravel-echo@1.19.0`: `Broadcaster` already has a `reverb` key, and the v1 runtime reads `this.options.bearerToken` (`dist/echo.js:1031`). Neither property can be used to fail a type check either, because v1's `EchoOptions` carries `[key: string]: any`. The floor is therefore a runtime/bundle-management decision, not a type-level one. |

### Two plan premises measured to be false

Both negative-QA probes prescribed by the plan were run and neither reproduced
the predicted failure. Full verbatim output is in
`.omo/evidence/task-5-sehatly.md`. Neither finding was worked around: the pins
are retained exactly as the plan specifies, and the discrepancy is recorded
here so the plan text can be corrected.

1. **`cssMinify` does not control the vendor prefix in Vite 8.3.1.** Building
   the identical source with `esbuild` and with `lightningcss` produced the same
   number of `-webkit-backdrop-filter` occurrences and byte-identical rules.
   The prefix is emitted by Tailwind's internal LightningCSS step inside
   `@tailwindcss/vite`, which runs before `build.cssMinify` is consulted.
   Independently measured on the real app bundle with a clean tree and no
   probe: `-webkit-backdrop-filter` **1** under both minifiers, `backdrop-filter`
   **2** under both. `lightningcss` is a strict *superset* of prefixes — it
   additionally emits `-moz-text-size-adjust`. The only other measured
   difference is that `lightningcss` rewrites `color-mix(in oklab, red, blue)`
   into a resolved `oklab(53.9985% .0962031 -.0928409)`, whereas `esbuild`
   keeps `color-mix(in oklab,red,blue)` verbatim (`oklab(` count 0 vs 2,
   `color-mix(` count 54 vs 56). An earlier revision of this file had that
   direction **inverted** and quoted a hex value that appears in neither
   minifier's output; the correction is recorded in
   `.omo/evidence/task-5-sehatly.md` §13.2.
2. **`laravel-echo@1.19.0` type-checks cleanly** against the same
   `web/src/lib/echo.ts` that uses `broadcaster: 'reverb'` and `bearerToken`.

## Everything else — current stable

No upper bound was invented for these. Each version below is the registry's
current stable at install time.

### Runtime dependencies

| Package | Range | Resolved |
| --- | --- | --- |
| `@daypicker/react` | `^10.0.1` | `10.0.1` |
| `@hookform/resolvers` | `^5.9.1` | `5.9.1` |
| `@radix-ui/react-avatar` | `^1.2.6` | `1.2.6` |
| `@radix-ui/react-checkbox` | `^1.3.11` | `1.3.11` |
| `@radix-ui/react-collapsible` | `^1.1.20` | `1.1.20` |
| `@radix-ui/react-dialog` | `^1.1.23` | `1.1.23` |
| `@radix-ui/react-dropdown-menu` | `^2.1.24` | `2.1.24` |
| `@radix-ui/react-label` | `^2.1.15` | `2.1.15` |
| `@radix-ui/react-navigation-menu` | `^1.2.22` | `1.2.22` |
| `@radix-ui/react-select` | `^2.3.7` | `2.3.7` |
| `@radix-ui/react-separator` | `^1.1.15` | `1.1.15` |
| `@radix-ui/react-slot` | `^1.3.3` | `1.3.3` |
| `@radix-ui/react-toggle` | `^1.1.18` | `1.1.18` |
| `@radix-ui/react-toggle-group` | `^1.1.19` | `1.1.19` |
| `@radix-ui/react-tooltip` | `^1.2.16` | `1.2.16` |
| `@tanstack/react-query` | `^5.104.0` | `5.104.0` |
| `class-variance-authority` | `^0.7.1` | `0.7.1` |
| `clsx` | `^2.1.1` | `2.1.1` |
| `input-otp` | `^1.5.0` | `1.5.0` |
| `ky` | `^2.1.0` | `2.1.0` |
| `laravel-echo` | `^2.5.0` | `2.5.0` |
| `lucide-react` | `^1.48.0` | `1.48.0` |
| `pusher-js` | `^8.6.0` | `8.6.0` |
| `react` | `^19.3.0` | `19.3.0` |
| `react-dom` | `^19.3.0` | `19.3.0` |
| `react-hook-form` | `^7.89.0` | `7.89.0` |
| `react-router` | `^8.4.0` | `8.4.0` |
| `sonner` | `^2.0.8` | `2.0.8` |
| `tailwind-merge` | `^3.7.0` | `3.7.0` |
| `tw-animate-css` | `^1.4.0` | `1.4.0` |
| `zod` | `^4.6.5` | `4.6.5` |

### Dev dependencies

| Package | Range | Resolved |
| --- | --- | --- |
| `@playwright/test` | `^1.56.0` | `1.63.0` |
| `@tailwindcss/vite` | `^4.3.3` | `4.3.3` |
| `@types/node` | `^26.6.3` | `26.6.3` |
| `@types/react` | `^19.3.0` | `19.3.0` |
| `@types/react-dom` | `^19.3.0` | `19.3.0` |
| `@vitejs/plugin-react` | `^6.1.1` | `6.1.1` |
| `esbuild` | `^0.28.2` | `0.28.2` |
| `playwright` | `^1.56.0` | `1.63.0` |
| `tailwindcss` | `^4.3.3` | `4.3.3` |
| `typescript` | `^7.0.2` | `7.0.2` |
| `vite` | `^8.3.1` | `8.3.1` |

`esbuild` is not in the plan's dependency list. It was added because
`web/vite.config.ts` sets `build.cssMinify: 'esbuild'` and Vite 8.3.1 declares
only `lightningcss`, `picomatch`, `postcss`, `rolldown` and `tinyglobby` as
dependencies — the build fails outright without it:

```
[plugin vite:css-post]
Error: Cannot find package 'esbuild' imported from
C:\Users\axioo\Desktop\sehatly\web\node_modules\vite\dist\node\chunks\node.js
```

## Deliberate non-additions

- **`vite-plus` was not installed.** It is the root's toolchain, but it aliases
  `vite` to `@voidzero-dev/vite-plus-core`, which would replace the upstream
  Vite 8 that the `cssMinify` decision above depends on. `web/` therefore builds
  with upstream `vite`, and the `lint` / `fmt` blocks carried over from the root
  `vite.config.ts:42-76` are declared against a local `ToolchainConfig` type in
  `web/vite.config.ts`. They are read when `vp` runs from `web/` and ignored by
  `npm run build`.
- **Playwright browser binaries were not downloaded.** Every install ran with
  `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`, and the existing
  `web/playwright.config.ts` (owned by todo 1) was left untouched.
