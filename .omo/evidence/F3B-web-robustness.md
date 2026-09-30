# F3B — Web robustness: error containment, mobile navigation, real destination ids

**Executor role:** fixing executor. I built none of the F3 audit; I read
`.omo/evidence/F3-manual-qa.md`, reproduced three of its findings against the running
application, and fixed them inside `web/` only.

| | |
| --- | --- |
| HEAD at start (the audited tree) | `e031bef` |
| My WIP commit | `154b1f9` |
| Findings fixed | **F3-01 (BLOCKER)**, **F3-04 (MAJOR)**, **F3-06 (MAJOR)** |
| Files changed | 13 under `web/src`, 2 new under `web/tests` — **no `app/**`, no `routes/**`, no `database/**`, no `packages/**`, no `docs/**`, no `telemedicine_test.sql`** |
| Backend changes needed | **none made**; two are reported in §7 |
| Screenshots | 6, in `.playwright-mcp/f3b-*.png` (untracked, not mine to commit) |

---

## 1. The two boundaries, and why a single one would not have been a fix

F3-01 was not "add an error boundary". It was *the sidebar is destroyed, and the sidebar is
the only way out of a signed-in screen*. That property decides the shape of the fix, so it
is worth stating precisely what I found by breaking it.

`lib/echo.ts:58-68`'s `requireEnv()` raises when `VITE_REVERB_APP_KEY` is absent, and
`KonsultasiPage` reaches `connectEcho()` through `useKonsNZultasChannel` **during render**,
before its own `isPending` check — so the throw lands on a route the account really is on,
for any account, under the boot the README documents.

An `errorElement` catches the error of the route it is declared on **and of that route's
children**, and an error that no leaf claims bubbles to the nearest ancestor that does —
**replacing that ancestor's element**. So the tidy-looking single placement, on the
`AppShell` route, is the one placement that reproduces the bug exactly. `router.tsx` now
carries an `errorElement` on **every leaf** (26 of them) and deliberately **not** on the
`AppShell` route, with the reason written at the place someone would be tempted to add it.

| boundary | file | catches | what survives |
| --- | --- | --- | --- |
| `RouteErrorBoundary` | `web/src/app/error-boundary.tsx` | a page, guard or layout that throws while rendering | the whole app shell: sidebar, account header, flash listener, all 13 destinations |
| `AppErrorBoundary` | same file, wrapping `RouterProvider` in `app/app.tsx` | a failure with no route to blame | nothing was mounted; the tokens in `sessionStorage` are untouched and a reload recovers |

**No stack trace reaches the user, and that is a decision.** `error.message` is one
sentence written by whoever raised it — here *"Reverb is not configured: VITE_REVERB_APP_KEY
is missing from the web build."*, which tells a reader what to do. `error.stack` is the
browser's frame list through a bundler prebundle. The card renders the message; the full
detail including React's component stack goes to `console.error`, which is the channel a
developer reads and which no user sees.

---

## 2. F3-04 — the sidebar was mounted and invisible on a phone

`components/ui/sidebar.tsx` is the shadcn sidebar, and below 768 px it renders its subtree
into a Radix `Sheet` that is **closed until something opens it**. The kit ships
`SidebarTrigger` for exactly that; `app-shell.tsx` rendered none. So on a phone the whole
menu was mounted, invisible, and unreachable.

Measured at 390 px with `MobileNavBar` removed — this is my own reproduction, and it matches
the audit's numbers exactly:

```
width          : 390
aside          : 0
sidebarTrigger : 0
openDialogs    : 0
links          : ["/profil", "/profil/keluarga", "/dokter"]     <- 3 of 13
buttons        : ["Keluar"]
```

The fix is the kit's own trigger inside the kit's own `Sheet` — not a second navigation
surface, which would be a second visual language on the one screen a phone user lives in.
`MobileNavBar` is `md:hidden`, so a desktop is byte-for-byte unchanged. Every nav link now
closes the drawer on click, because a `Sheet` does not close itself and a drawer left open
over the page it just opened is the same dead end in a nicer frame.

Measured at 390 px after the fix:

```
mobile bar     : present
sidebarTrigger : present
drawer (closed): 0
drawer (opened): 13 links, identical set to the desktop sidebar
```

**The notification bell is deliberately not duplicated into the bar** — two bells would be
two live `GET /notifikasi` subscriptions with two unread counts on one screen.

The 13 destinations a patient is offered: `Dashboard`, `Profil`, `Anggota keluarga`,
`Alergi`, `Direktori dokter`, `Booking saya`, `Booking masuk`, `Konsultasi`, `Rekam medis`,
`Riwayat resep`, `Checkout resep`, `Lacak pesanan`, `Bayar`. (The audit said "eleven" and
"3 of 12"; the count in the source is 13, and the invariant is now enforced against the
rendered DOM rather than against a number in a report.)

---

## 3. F3-06 — no destination carries an id that is not the caller's

Five links were hardcoded to id `1` and a sixth (the doctor's `Tulis resep`) to
`/konsultasi/1/resep`. Id `1` is a row in somebody else's tenant, so every one of them was a
404 card for every other account. `lib/api/tujuan.ts` is the resolver, and the property that
makes the fix real is not "the link text changed":

> **no id leaves the resolver unless a row belonging to the signed-in account carried it.**

That is why every function returns `number | null` and `null` means "this account has none of
that" — there is no `?? 1` and no `[1]` default anywhere in the file, and
`web/tests/unit/tujuan.test.ts` asserts the *provenance* of every id rather than one expected
value, so a placeholder fails from any input shape.

**Where the ids come from.** `GET /api/v1/pasien/resep` is the one endpoint in the 74 that
lists rows the caller owns *and* publishes the foreign keys for the other two:
`ResepResource` carries `konsultasi_id` and `rekam_medis_id` on every row. So `/konsultasi`
and `/rekam-medis` resolve the caller's real ids from a list the server already serves them —
no new endpoint, no guess, and tenant-correct by construction.

`BookingResource` is deliberately **not** used: it publishes no `konsultasi_id`, so a booking
cannot lead to its consultation. Reported in §7 rather than worked around.

**The two that cannot be resolved.** The API publishes no order list and no invoice list, so
`/pesanan` and `/pembayaran` now say exactly that and point at the checkout confirmation —
the one moment a patient is actually given an order number. An empty state that names the
next step is not a dead end; an error card for a row that is not yours is.

**A genuinely missing resource gets a proper state.** `/konsultasi/1`, `/rekam-medis/1` and
`/pembayaran/1` render `NotFoundState` with Indonesian copy and a link onward, matching what
`/checkout/:resepId` already did — instead of the Indonesian heading over Laravel's English
*"Resource not found."* that the audit recorded as a half-translated card. Each 404 branch
documents why the answer is 404 and not 403: existence is deliberately not leaked across
tenants, and no retry can change it.

---

## 4. RED → GREEN: every guard was made to fail first

A guard that has never failed has never been tested. Each of the four guards below was
broken on purpose, run, and restored. Every RED is a real run.

### RED 1 — the error boundary (F3-01)

`errorElement` removed from `/konsultasi/:id` only:

```
x  a throwing route is contained, the sidebar survives, and no stack trace is shown (8.2s)

  Error: sidebar harus tetap ada setelah route gagal
  Locator: locator('[data-sidebar="sidebar"]')
  Expected: visible
  Error: element(s) not found
```

and the page at that moment contained **only** this — no sidebar, no nav, no account header:

```yaml
- alert:
  - text: Halaman ini gagal ditampilkan
  - paragraph: "Reverb is not configured: VITE_REVERB_APP_KEY is missing from the web build."
  - link "Kembali ke dashboard": /dashboard
  - link "Ke beranda": /
```

**This RED found a bug in my own copy.** The card had said *"Menu di sisi layar tetap bisa
dipakai"* — and in the bubbled case that is false, because the shell is gone. The sentence
is not in the card any more, and `error-boundary.tsx` records why. The "sidebar survives"
property is now proved by a test, not claimed by a sentence.

Restored → **GREEN**.

### RED 2 — the mobile trigger (F3-04)

`<MobileNavBar />` removed from `SignedInLayout`:

```
x  all thirteen destinations are reachable at 390 px (8.5s)
  Error: expect(locator).toBeVisible() failed
  Locator: locator('[data-slot="mobile-nav"]')
  Error: element(s) not found
```

### RED 3 — the destination enumeration (F3-04)

One `MenuLink` suppressed, so the sidebar offers 12:

```
x  all thirteen destinations are reachable at 390 px (3.6s)
  Error: desktop harus menawarkan 13 tujuan
  Expected length: 13
  Received length: 12
```

### RED 4 — the hardcoded id (F3-06)

One nav link put back to `to="/konsultasi/1"`:

```
x  no destination carries a hardcoded id, and every one renders inside the shell (3.7s)
  Error: tautan /konsultasi/1 menyimpan id milik orang lain, bukan milik akun ini
  Expected pattern: not /^\/(konsultasi|rekam-medis|checkout|pesanan|pembayaran)\/\d+$/
  Received string:      "/konsultasi/1"
```

### RED 5 — the resolver (F3-06, unit)

`daftarKonsultasi` made to emit the placeholder it exists to prevent
(`return [...unik].length === 0 ? [1] : [...unik]`):

```
tests 43   pass 40   fail 3
✖ lib/api/tujuan - F3-06: a destination id comes from the caller, never from a guess
  - an account with no prescriptions resolves to nothing at all
  - every returned id is one that a row of this account carried, for any input
  - a prescription with no consultation and no record yields no id
```

### GREEN — everything restored

```
$ npm run test:unit          tests 43  pass 43  fail 0  skipped 0

$ npx playwright test app-shell-robustness --project=chromium
  ok 1 ... a throwing route is contained, the sidebar survives, and no stack trace is shown (5.2s)
  ok 2 ... all thirteen destinations are reachable at 390 px (5.2s)
  ok 3 ... no destination carries a hardcoded id, and every one renders inside the shell (29.9s)
  3 passed (46.9s)
```

---

## 5. The real build and type-check output

Not claimed from memory — run, and reproduced verbatim.

```
$ npm run types:check

> types:check
> tsc --noEmit

(no output — exit 0)
```

```
$ npm run build

> build
> vite build

vite v8.3.1 building client environment for production...
transforming...
✓ 3368 modules transformed.
rendering chunks...
computing gzip size...
dist/index.html                     0.67 kB │ gzip:   0.37 kB
dist/assets/index-CEjnb3WM.css     82.78 kB │ gzip:  13.84 kB
dist/assets/index-CqWB7TjJ.js   1,109.74 kB │ gzip: 328.12 kB

✓ built in 2.15s
build exit code: 0
```

The `(!) Some chunks are larger than 500 kB` advisory is pre-existing and is not introduced
here — no code splitting is configured in `web/vite.config.ts` and none was added. The
`build.cssMinify: 'esbuild'` pin the README insists on is untouched.

The e2e spec asserts its own **precondition**: it reads the served `echo.ts` and requires
that `VITE_REVERB_APP_KEY` is absent, because a green run in a *configured* environment would
be a false pass — the consultation screen would load normally, nothing would throw, and no
error boundary would ever be entered.

---

## 6. Screenshots, 390 px before and after

| file | what it shows |
| --- | --- |
| `f3b-01-390-sebelum-fix-tanpa-pemicu.png` | **BEFORE.** `/dashboard` at 390 px with the nav bar removed: 3 of 13 links, no trigger, no drawer. Identical to the audit's `25-mobile-dashboard-390.png`. |
| `f3b-02-390-setelah-fix-bilah-menu.png` | **AFTER.** The same page: a 56 px bar with the kit's trigger, brand, and nothing else. |
| `f3b-03-390-setelah-fix-drawer-13-tujuan.png` | **AFTER.** The drawer open, all 13 destinations grouped exactly as the desktop, the bell and `Keluar` in the footer, `Dashboard` marked active. |
| `f3b-04-390-galat-terkandung.png` | The throwing route at 390 px: the app's own error card, and the trigger still there. |
| `f3b-05-390-galat-terkandung-menu-tetap-terbuka.png` | The same page with the drawer open — the user is not stuck, all 13 destinations are one tap away. |
| `f3b-06-desktop-galat-terkandung-sidebar-hidup.png` | The throwing route at 1280 px: sidebar fully intact, 13 destinations, account header, bell, `Keluar`, and the recoverable card in the content area. |

`f3b-06` is the direct answer to F3-01. The audit's `17-konsultasi-1-notfound.png` shows the
whole document replaced by React's page; this shows the whole document intact with one card in
the content column.

Measured on the throwing route, after the fix:

```
390 px : errorCard true   trigger true   sidebar(drawer, closed) 0   reactPage false
         stackMarkers: []           <- no "node_modules", no "react-dom", no " at "
1280px : errorCard true   sidebarVisible true   sidebarWidth 255   navCount 13   reactPage false
```

---

## 7. Reported, not fixed: two destinations need a backend change

Per the brief, a UI fix that needs a backend change is a finding. I did not make them, and
`routes/api.php` is untouched.

1. **`GET /pasien/pesanan-obat` does not exist** (nor `GET /pesanan-obat`). There is no way to
   enumerate a patient's own orders, so `/pesanan` and `/pembayaran` cannot resolve a real
   order id from anything. `pesanan-page.tsx`'s own docblock records the same gap. A
   `pesanan.lihat`-gated list scoped to the caller's own `pasien` row would close it — the
   same shape as the three lists that do exist.
2. **`BookingResource` publishes no `konsultasi_id`, and there is no `GET /konsultasi`.**
   A patient's consultations are therefore derivable only from their prescription history, so
   a patient with a consultation but no prescription sees an empty list. `GET /konsultasi`
   (scoped to the caller's own rows) plus `konsultasi_id` on the booking resource would close
   it. This is the same structural gap the audit recorded as "there is deliberately NO list
   endpoint" for medical records, and it is why `/rekam-medis` is derived rather than listed.

Also still open and **not mine**: F3-12 — the public `/dokter` and `/dokter/:id` pages render
outside `AppShell` with zero padding, so tapping "Direktori dokter" from the new mobile drawer
lands on a page with no nav at all. It is a separate finding, it is not in my scope, and I have
left it alone rather than quietly widening the fix.

---

## 8. The PHP suite: two failures, and they are not mine

I changed **zero** PHP files. `git show --name-only 154b1f9` lists 13 paths, all under `web/`,
and `web/src` is not read by the PHP suite at all.

While I worked, **another executor committed into this repository**: `14aec3f`
*"fix(notifikasi): wire NotificationService to the five real domain events"*, touching
`app/Services/{Booking,Konsultasi,Payment,Resep}/*` and `tests/Feature/Notifikasi/*` — the
F3-05 fix, which is not my assignment. There is also **uncommitted** work in
`database/seeders/DatabaseSeeder.php` plus a new untracked `database/seeders/DemoDataSeeder.php`
and `tests/Feature/DemoData/`. I have not touched, staged, or reverted any of it.

The suite I ran reports:

```
tests 1183   passed 1120   failed 2   assertions 22134   duration 697724ms

  Tests\Unit\RbacMigrateFreshSeedTest::test_no_user_roles_row_is_seeded
    "The seeder tree must not assign roles to accounts; that is RoleAssigner's job at runtime.
     Failed asserting that 3 is identical to 0."
  Tests\Unit\RbacMigrateFreshSeedTest::test_the_todo_18_development_fixtures_still_land
```

**Attribution, measured rather than asserted:** the uncommitted `DemoDataSeeder` writes
`user_roles` — its own header says *"`user_roles` | `(user_id, role_id)` primary key (`:174`)
| `insertOrIgnore` via `RoleAssigner`"*, and it seeds exactly three demo accounts. `3 is
identical to 0` is those three grants. The failing assertion forbids precisely that. The
totals have also moved from the briefed 1162/1162 (22541 assertions) to 1183 tests, which is
consistent with the tests the concurrent executor added.

I did **not** run `migrate:fresh`, `migrate:rollback`, or `db:seed` to try to clear it, did not
revert their files to get a green run, and am reporting the failure rather than a number I
reached by touching someone else's work.

Read-only baselines, all unchanged by me:

| check | result |
| --- | --- |
| `telemedicine_test.sql` SHA-256 | `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` — byte-identical |
| `route:list --path=api/v1` | **74** operations |
| `mobile/` | absent |
| `pubspec.yaml` Flutter | no `flutter:` constraint, no `flutter*` dependency; still `sdk: '>=3.13.0 <4.0.0'` with `dio: ^5.11.1` |
| `web/` dev database | `telemedisin_db` (never `telemedisin_db_test`, never `sehatly`) |

---

## 9. Token hygiene

Every file this task touched was read as **raw bytes** and scanned: distinct code points above
U+007F, a UTF-8 BOM, CRLF endings, latin-1 mojibake markers, and lone surrogates.

```
OK   web/src/app/error-boundary.tsx                          10195 bytes  ascii-only, no BOM, LF
OK   web/src/app/app.tsx                                      2097 bytes  ascii-only, no BOM, LF
OK   web/src/app/router.tsx                                  16549 bytes  ascii-only, no BOM, LF
OK   web/src/app/app-shell.tsx                               19925 bytes  ascii-only, no BOM, LF
OK   web/src/lib/api/tujuan.ts                               5289 bytes  ascii-only, no BOM, LF
OK   web/src/pages/rekam-dan-konsultasi-index-page.tsx        8457 bytes  ascii-only, no BOM, LF
OK   web/src/pages/checkout-index-page.tsx                    7782 bytes  ascii-only, no BOM, LF
OK   web/src/pages/pesanan-dan-pembayaran-index-page.tsx      3528 bytes  ascii-only, no BOM, LF
OK   web/src/pages/konsultasi-page.tsx                       17091 bytes  ascii-only, no BOM, LF
OK   web/src/pages/rekam-medis-page.tsx                       7290 bytes  ascii-only, no BOM, LF
OK   web/src/features/pembayaran/pembayaran-menunggu.tsx     12775 bytes  ascii-only, no BOM, LF
OK   web/src/components/ui/sidebar.tsx                      22415 bytes  ascii-only, no BOM, LF
OK   web/src/components/ui/sheet.tsx                          4076 bytes  ascii-only, no BOM, LF
OK   web/tests/unit/tujuan.test.ts                            6132 bytes  ascii-only, no BOM, LF
OK   web/tests/e2e/app-shell-robustness.spec.ts              18611 bytes  ascii-only, no BOM, LF

CLEAN: 15 files
```

The scan is not decoration. It caught three ASCII identifier corruptions *before* they
reached a test run — `for (const row of.resep)`, a bare `akunPASIEN...` placeholder, and a
`/konsultasi/1` mangled into `/konsCEN1/1` inside a docblock — each of which would have been
a type error or a lie in a comment, and one of which (`row of.resep`) is exactly the
`referencia`/`referensi` class of defect this project has already been bitten by. Two more
(`daftarKonsultasi` typed as `daftarKons` plus a stray accented fragment, and
`nomor consultations`) were caught by reading the file back immediately after writing it. All
fixed before any test ran.

---

## 10. How the stack was booted

```console
# API - non-8000 port, dev database telemedisin_db
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe artisan serve --host=127.0.0.1 --port=8100
#   -> INFO Server running on [http://127.0.0.1:8100].

# Web client - own port, proxy aimed at 8100, and the realtime variables CLEARED
set SEHATLY_API_TARGET=http://127.0.0.1:8100
set VITE_REVERB_APP_KEY=&& set VITE_REVERB_HOST=&& set VITE_REVERB_PORT=&& set VITE_REVERB_SCHEME=
npx vite --host 127.0.0.1 --port 5199 --strictPort
#   -> VITE v8.3.1 ready in 524 ms

# The e2e guard
SEHATLY_BASE_URL=http://127.0.0.1:5199 npx playwright test app-shell-robustness
```

**One thing the F3 audit could not have seen, and it changes how the defect reproduces.** The
repository's ROOT `.env` defines `VITE_REVERB_*` at lines 76-79, and a Vite dev server
started from `web/` on this machine served them: the transformed module came back as
`import.meta.env = {..., "VITE_REVERB_APP_KEY": "a4qvts0fctovqjuabffe", "VITE_REVERB_HOST":
"localhost", "VITE_REVERB_PORT": "8080", "VITE_REVERB_SCHEME": "http"}`. With them cleared it
comes back as `import.meta.env = {"BASE_URL": "/", "DEV": true, "MODE": "development",
"PROD": false, "SSR": false}`.

So F3-01's trigger depends on how the client was started, not only on the code — and that is
worth knowing for a fix, because a boundary that is only ever exercised on a correctly
configured dev box would not have been exercised at all. That is why the e2e spec asserts
the precondition instead of assuming it, and why the `VITE_REVERB_*` variables remain a real
open item (F3-10): the error card now *contains* the failure instead of white-screening the
app, but the consultation screen still cannot work until those variables are set, and they
are documented nowhere.

---

## 11. Unfinished, stated plainly

1. **F3-10 is not fixed.** The `VITE_REVERB_*` variables are still undocumented, and
   `/konsultasi/:id` still cannot render its chat without them. What changed is that the
   failure is now a contained, recoverable card instead of a destroyed application. Fixing it
   means documenting the variables (`web/.env.example` plus the README's "Running it") and
   deciding whether the page should degrade to REST-only rather than throw — both outside
   this brief, the second a product decision.
2. **F3-12 is not fixed**, by choice — see §7. A signed-in user who taps "Direktori dokter"
   from the new drawer lands on a page with no navigation. It was a separate finding and I was
   told to fix only what was named.
3. **The two server-side gaps in §7 are not fixed** and need an API change.
4. **The PHP suite is red on two tests**, from a concurrent executor's uncommitted seeder,
   not from this change — §8.
5. The other four e2e specs (`booking`, `konsultasi`, `pesanan-obat`, `resep`) were **not**
   run: `konsultasi.spec.ts` needs a provisioned doctor account and a Reverb broker this
   brief did not provide. They were left untouched.
