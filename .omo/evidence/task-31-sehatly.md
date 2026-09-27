# Task 31 evidence - install Reverb, configure broadcasting, define the private consultation channel

Plan: `.omo/plans/sehatly-telemedicine-platform.md` todo 31
Branch: `feat/sehatly-telemedicine`
PHP used for every command: `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17)
Laravel: 13.33.0
Test database: `telemedisin_db_test_t31` (private, created + migrated + seeded for this todo)

---

## 0. Headline

`474 tests, 472 passed, 2 failed, 8822 assertions`. Both failures are the
pre-existing database-NAME pins described in section 7; they were failing at
baseline before any change in this todo. Baseline was
`456 tests, 454 passed, 2 failed, 8745 assertions`, so this todo adds
**18 tests and zero regressions**.

`telemedicine_test.sql` SHA-256
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` - identical
to the value recorded in the todo 30 ledger, and `git status`/`git diff` report
no modification, so the DDL is byte-identical to HEAD.

---

## 1. Files owned by this todo

| File | State | Purpose |
|---|---|---|
| `config/broadcasting.php` | new | `reverb` / `log` / `null` connections, default from `BROADCAST_CONNECTION` |
| `config/reverb.php` | new | Reverb server block, 156 lines, authored rather than accepted from the installer |
| `routes/channels.php` | new | the single `konsultasi.{id}` authorization rule |
| `app/Events/KonsultasiMessageSent.php` | new | `KonsultasiMessageSent`, `ShouldBroadcastNow`, `chat.pesan` |
| `app/Services/Konsultasi/KonsultasiChannelAccess.php` | new | the two-hop ownership decision, extracted so it is testable without a broadcaster |
| `bootstrap/app.php` | modified | `withBroadcasting(prefix: 'api', middleware: ['api', 'auth:sanctum'])` |
| `.env.example` | modified | `BROADCAST_CONNECTION=reverb` plus Reverb placeholders |
| `tests/Feature/Realtime/ConsultationChannelTest.php` | new | 18 tests, 75 assertions |
| `tests/Feature/ApiKernelTest.php` | modified | one documented exception to the `api/v1` invariant |
| `composer.json` / `composer.lock` | modified | `laravel/reverb` |

`config/cors.php` was modified and then **reverted**; see finding F5.

---

## 2. Acceptance criteria, each with the command that proves it

### AC1 - `php artisan reverb:install` exits 0 and `config/reverb.php` exists

```
> php artisan reverb:install --no-interaction
  INFO Reverb installed successfully.
exit=0
config/reverb.php exists: True  lines: 156
```

The installer ran **after** `config/reverb.php` was authored and did not
overwrite it. It also did not create `public/vendor`, so no built JS is
committed. It did write generated credentials into the gitignored `.env`; those
are not committed, and `.env.example` carries placeholders only.

### AC2 - `config('broadcasting.default')` resolves to `reverb` in local

Booted outside the test environment, so `phpunit.xml` cannot mask it:

```
default      = 'reverb'
driver class = Illuminate\Broadcasting\Broadcasters\PusherBroadcaster
reverb keys  = ["driver","key","secret","app_id","options","client_options"]
has cluster  = false
env value    = 'reverb'
```

`PusherBroadcaster` is the expected class and is the plan's own verified fact:
`BroadcastManager::createReverbDriver()` delegates to `createPusherDriver()`
because Reverb speaks the Pusher protocol. No `cluster` key, as required.

### AC3 - `php artisan route:list --path=broadcasting` shows the auth endpoint

```
 GET|POST|HEAD api/broadcasting/auth .. Illuminate\Broadcasting > BroadcastController@authenticate
 Showing [1] routes
```

`prefix: 'api'` and not `api/v1` is a contract fact, not a preference: todo 35
pins the client to `authEndpoint: '/api/broadcasting/auth'` and lists
"must NOT connect to `/broadcasting/auth`".

### AC4 - `grep -q "ShouldBroadcastNow" app/Events/KonsultasiMessageSent.php` succeeds

```
9:  use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
64: final class KonsultasiMessageSent implements ShouldBroadcastNow
```

`ShouldBroadcast` appears in the file only inside explanatory comments. The
interface is not implemented and is not implementable-as-avoided by a second
interface; see finding F4.

### AC5 - a test proves a third-party user is denied channel access

`the suite pins broadcasting to null while the app default is reverb` and the
stranger/other-doctor/other-patient cases. See section 4.

---

## 3. The suite runs on the `null` broadcaster, and that is a trap

`phpunit.xml:24` pins `BROADCAST_CONNECTION=null`, which the plan requires be
left alone. But `NullBroadcaster` **overrides** `auth()` with an empty body, so
on that driver the callback in `routes/channels.php` is never reached - every
authenticated caller gets an empty 200 and the entire authorization is bypassed
rather than exercised.

Every refusal test would therefore pass for the wrong reason. `withReverbDriver()`
swaps in the Pusher-protocol driver, and
`the suite pins broadcasting to null while the app default is reverb` pins that
arrangement so nobody "simplifies" it back. The allowed cases assert a real
Pusher HMAC `auth` token, not merely a status code.

This is also why `reverb` is resolved as a Pusher driver in tests at all: the
`null` driver cannot answer `auth()`.

---

## 4. QA scenarios

### happy - patient and doctor authorised, stranger refused

```
> php artisan test --filter=ConsultationChannelTest
{"tool":"pest","result":"passed","tests":18,"passed":18,"assertions":75}
```

### failure - neither patient nor doctor, and a non-existent id

Covered in the same file by: a third-party patient, a third-party doctor, a
second consultation's doctor asking for the first consultation, a consultation
the caller is not party to, id `999999` (absent), and the malformed id `'abc'`.
The last two are also asserted directly against
`KonsultasiChannelAccess::allows()`.

### The rule the DDL implies

`konsultasi` names no account of its own. It stores `pasien_id`
(`telemedicine_test.sql:539`) and `dokter_id` (`:540`); it is
`pasien.user_id` (`:220`) and `dokter.user_id` (`:411`) that carry the account.
The ownership check is therefore necessarily a two-hop walk, and the test reads
those columns through the project's own `SqlSchemaParser` so it cannot disagree
with `sehatly:verify-schema` about what the schema says.

---

## 5. What I got wrong first, and the measurements that corrected it

Recording these because each one was a wrong belief that a test disproved, not
a typo.

**F1 - `env('BROADCAST_CONNECTION')` is PHP `null`, not the string `'null'`.**
My first assertion expected `'null'` and got a mismatch. `Illuminate\Support\Env`
maps the literal string `'null'` to PHP `null` (that is how a `.env` expresses
a null), and `BroadcastManager::getConfig(null)` then falls back to
`['driver' => 'null']`. The null broadcaster is reached without the config value
ever being a string. Assertion corrected to `toBeNull()`.

**F2 - `config/broadcasting.php` IS loaded, and `connections` is merged, not
replaced.** I read the effective connection set and saw
`reverb,pusher,ably,mercure,log,null` and concluded my file had not been loaded.
`LoadConfiguration::loadConfigurationFile()` runs
`array_merge($base[$name], $config)` for every name in `mergeableOptions()`, and
`connections` is one of them, so the framework base's `pusher`/`ably`/`mercure`
survive alongside this file's `reverb`/`log`/`null`, which win on collision. A
declared connection is not a used one, and the local default is `reverb`. The
test now asserts the three connections this task owns by name and documents why
it does not count the whole set.

**F3 - `PrivateChannel` prepends `private-` itself.** My first test asserted
`(string) $event->broadcastOn()` was `konsultasi.7` and that it did *not* start
with `private-`, on the reasoning that the client owns the prefix. Wrong: the
constructor adds it, so `broadcastOn()` correctly returns `private-konsultasi.7`
and that is the string Reverb and the client both expect. Constructing the
channel with an already-prefixed name would publish `private-private-...`. The
event docblock previously asserted the opposite and was corrected.

**F4 - `ShouldBroadcastNow` extends `ShouldBroadcast`.** The plan's "not
`ShouldBroadcast`" cannot be asserted as interface shape. Asserted
behaviourally instead: with the queue faked, dispatching the event pushes
nothing, and swapping the interface to `ShouldBroadcast` would push a
`BroadcastEvent` job and fail the assertion.

**F5 - `Str::is()` translates `*` into `.*`, not `[^/]*`.** I added
`api/broadcasting/auth` to `config/cors.php` and wrote a comment claiming
`api/*` "matches a single path segment" and could not cover a two-segment path.
Measured directly:

```
Str::is('api/*', 'api/broadcasting/auth')  => true
Str::is('api/*', 'api/v1/konsultasi/1')       => true
Str::is('api/*', 'api')                    => false
```

The shipped `api/*` already covers the auth endpoint, so the entry and the false
comment were **reverted** and `config/cors.php` is unmodified in the commit. The
prefix, not the depth, is what distinguishes the paths - which is why
`broadcasting/auth` is still listed separately. A preflight test now guards the
fact instead of a config change encoding a falsehood.

**F6 - `MakesHttpRequests::call()` ignores `defaultHeaders`.** My first preflight
was `withHeaders([...])->call('OPTIONS', ...)`, which sent no `Origin` at all, so
`isPreflightRequest()` was false and the request was *routed* rather than
answered. The failure is invisible: the route accepts GET and POST, so the
response came back `200` with `Allow: GET,HEAD,POST` and no CORS headers, which
reads as "CORS is broken" rather than "the test built the wrong request". Only
`json()` folds `defaultHeaders` in, via `transformHeadersToServerVars()`. A
preflight must be issued through `call()`'s `$server` argument. The test now
does that, and asserts the negative direction too (narrowing `cors.paths` makes
the header disappear), so it cannot pass vacuously.

---

## 6. Other findings

**F7 - the plan's claim that `reverb:install` broadcasts `ReverbConnected` is
false.** The vendor installer creates no event. No unused `ReverbConnected` was
added, because a class nothing dispatches is not evidence of a working
connection.

**F8 - plan typo.** Draft F20 contains `kons Boscoultasi.$id`. The correct
contract is `konsultasi.{id}`; shipped as such.

**F9 - the dependency resolution downgraded Guzzle.** `laravel/reverb` needs
`guzzlehttp/psr7 ^2.6`, which the locked Guzzle 8.2.0 (`psr7 ^3.1`) could not
satisfy. Resolved with `-W`:

- `guzzlehttp/guzzle` 8.2.0 -> **7.15.5**
- `guzzlehttp/psr7` 3.1.0 -> 2.13.1
- `guzzlehttp/promises` 3.0.2 -> 2.5.3
- `nesbot/carbon` 3.14.0 -> 3.14.1
- `symfony/polyfill-php82` removed
- added `pusher/pusher-php-server` 7.3.0, `cboden/ratchet`, `react/*`, `clue/*`

A major-version downgrade of the HTTP client is a real consequence of choosing
Reverb and is called out rather than buried. `composer validate` exits 0 and the
full suite is green, so nothing in the application currently depends on the
Guzzle 8 API. `composer require` ran with `--no-scripts`, then
`package:discover` ran separately.

**F10 - `web/src/lib/echo.ts` is not wired yet, and that is todo 35's job, not
a defect here.** It currently omits `authEndpoint`, so Echo would default to
`/broadcasting/auth` while the server serves `/api/broadcasting/auth`. Todo 35
mandates `authEndpoint: '/api/broadcasting/auth'` and "must NOT connect to
`/broadcasting/auth`", so this file is simply mid-task. Recorded as a dependency
for todo 35, not a finding against this todo. `web/` was not modified.

**F11 - Pint run repo-wide reformatted three files belonging to other todos.**
`vendor\bin\pint` with no path argument rewrote
`app/Services/Booking/SlotAvailabilityService.php`,
`app/Support/Dokter/StrBerlaku.php` and
`tests/Feature/Dokter/SlotAvailabilityTest.php`, none of which are in this todo
and none of which had been modified before I ran it. All three were reverted with
`git checkout --`. Noteworthy: Pint's `fully_qualified_strict_types` fixer added
`use Tests\Feature\Dokter\SlotAvailabilityTest;` to a **production** class, which
is a production-to-test import, and `no_superfluous_phpdoc_tags` deleted a
`@param` block documenting a union type. Those files remain Pint-dirty at HEAD;
they are not this todo's to change. `pint --test` scoped to the eight owned PHP
files passes.

**F12 - one `/api` route is deliberately not under `api/v1`.**
`ApiKernelTest::test_api_routes_are_mounted_under_the_v1_prefix` asserts that
every `api/` route carries the `v1` segment, because `apiPrefix: 'api/v1'` is
what todo 53 and the mobile client key off. The broadcasting auth endpoint is
intentionally outside `v1` per todo 35. The test now compares offenders as a set
against an explicit one-element allowlist and additionally asserts each allowlist
entry is really registered, so the carve-out can neither widen silently nor rot
into a no-op.

---

## 7. The 2 failures are isolation artifacts, not regressions

```
VerifySchemaCommandTest.php:185  expected 'telemedisin_db_test'
                                actual   'telemedisin_db_test_t31'
DatabaseEngineTest.php:23       expected 'telemedisin_db_test'
                                actual   'telemedisin_db_test_t31'
```

Both hard-code the shared database *name*. They fail on any per-executor
database, and both were failing in the baseline measurement taken before this
todo changed anything (456/454/2). The todo 27 and todo 30 ledger entries record
the same two, and todo 30 records that they pass on the standard database. The
shared database was deliberately not used: the todo 27 executor independently hit
concurrent `migrate:fresh` collisions on it and adopted per-executor databases
for the same reason.

---

## 8. Audits

- **Pint**: `pint --test` on the eight owned PHP files, exit 0.
- **Non-ASCII gate**: 0 bytes above 127 across all ten authored/modified files,
  including `.env.example`. All pure ASCII.
- **Token audit**: `php storage/audit/token-audit.php` over the six new PHP
  files, exit 0. Every `snake_case` token resolved against the DDL through
  `SqlSchemaParser`, a non-DDL vocabulary entry, or a `function_exists()` PHP
  builtin. No unresolved real identifier.
- **`composer validate`**: exit 0.
- **Full suite**: 474 / 472 / 2, 8822 assertions.
- **Focused suite**: `ConsultationChannelTest` 18/18 with 75 assertions;
  `ConsultationChannelTest|ApiKernelTest` 34/34 with 147 assertions.
- **DDL**: SHA-256 unchanged, `git diff` empty.
- **Foreign work**: `web/`, `.omo/evidence/task-3-sehatly.md` and
  `.playwright-mcp/` are a concurrent executor's and were never staged, edited or
  reverted. The commit uses an explicit path list.
