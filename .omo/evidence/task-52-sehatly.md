# task-52-sehatly.md -- todo 52 evidence: rate limiting, OTP burn, security headers

## Scope
Named per-surface rate limiters, OTP six-attempt burn in the database, security
headers on every response (HSTS only over TLS), and indistinguishable login
failures (same status, bytes, headers, wall time). Only
`app/Providers/AppServiceProvider.php` is owned in `app/`. No migrations, no
indexes, no constraint changes. `phpunit.xml` untouched. Fenced areas
(`app/Services/Auth/**`, `routes/api.php`, controllers, `mobile/`,
`packages/sehatly_api_client`, `telemedisin_db_test.sql`, `docs/schema-notes.md`,
`docs/migration-order.md`) untouched.

## Database
`telemedisin_db_test_52`, migrated then `php artisan db:seed --force` (all
seeders). `phpunit.xml` pins `CACHE_STORE=array`, `SESSION_DRIVER=array`; no
cache flush in app or tests. App/store IDs observed stable across requests (no
rebuild masking state).

## Bugs found and fixed (all real, none cosmetic)
1. `RateLimiter::remaining($key, $maxAttempts)` was called with one argument;
   every throttled response was a 500. Fixed at the call site.
2. `guard()` signature misuse for `auth-refresh`, `chat`, `webhook-payment`:
   limiter name must be first, raw key second. Fixed all three.
3. Key derivation must match `ThrottleRequests` (`$shouldHashKeys = true`):
   cache entry is `md5($limiterName.$limit->key)`, not the raw `Limit::by()`
   key. Provider and tests now derive identically.
4. The security test probe lived at `/__rate-limiter-probe`, which the SPA
   catch-all `Route::any('/{any?}')` in `routes/web.php:93` shadows at boot
   (returns `BinaryFileResponse` 200 before any runtime route). Probe moved to
   `/api/v1/__rate-limiter-probe/{limiter}`; routes untouched.
5. Two tests read `rateLimitFor(...)->key` AFTER exhausting the bucket, so they
   got the 429 JsonResponse instead of the Limit. Keys are now captured first.
6. `withHeader()` persists across requests in one test; the "anonymous" call
   still carried the bearer token (200, not 401). Fixed with `flushHeaders()`
   PLUS `$this->app['auth']->forgetGuards()` (the Sanctum guard is a container
   singleton and keeps the resolved user).
7. `$this->serverVariables['HTTPS'] = 'on'` can never mark TLS arrival:
   Symfony `Request::create()` derives `HTTPS` from the URI scheme and
   `unset()`s the server var for non-https URIs (verified: test case held
   `{"HTTPS":"on"}` while the request arrived with `HTTPS: null`). The HSTS
   branch now requests a full `https://localhost/...` URL.

## RED transcripts (env-gated probes, all removed afterwards, residue grepped)
- Headers disabled (`TODO52_RED_PROBE_HEADERS`): SecurityHeaders 10/10 fail,
  first failure `Response is missing the [x-content-type-options] header`.
- `burnOtp` disabled (`TODO52_RED_PROBE_BURN`): RateLimiting 21/24, the 3 burn
  tests fail (`sudah_dipakai = 1` never set; keyed-burn; new-code-new-budget).
- `identifierKey` collapsed to IP (`TODO52_RED_PROBE_KEY`): RateLimiting 21/24;
  unknown-vs-wrong headers diverge (`x-ratelimit-remaining` 4 vs 3), the timing
  test 429s early, and the key-format test shows `auth-login|anon|<ip>` for a
  named request.

## GREEN
- Focused: `tests/Feature/Security` 34/34, 732 assertions
  (`{"tool":"pest","result":"passed","tests":34,"passed":34,"assertions":732}`).
  Native Pest JSON omits `failed`/`errors` at zero; green was accepted only
  after key-presence-then-zero checks on red runs.
- Full suite: 1152/1152, 22489 assertions, exit 0.
- `php -l` clean on provider and both test files.

## Runtime proof (`php artisan serve --port=8123`, curl.exe, server stopped after)
- `GET /api/v1/referensi/provinsi` 200 with `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`,
  `X-Permitted-Cross-Domain-Policies: none`,
  `Cross-Origin-Opener-Policy: same-origin`, the API-only
  `Content-Security-Policy`, `Cache-Control: no-store, private`,
  `Pragma: no-cache`. No HSTS over plain HTTP (correct).
- Six `POST /api/v1/auth/login` (same phone): 401 x5 then 429. The 429 body is
  the exact envelope:
  `{"success":false,"message":"Terlalu banyak permintaan. Silakan coba lagi nanti.","errors":{}}`.
- Two different unknown phones: byte-identical 401 bodies
  (`Nomor telepon, email, atau kata sandi salah.`), `identical:True`.

## Static audits
- BOM absent and zero non-ASCII bytes in all three owned files (raw-byte scan).
- `route:list --path=api/v1` count: 74.
- No new routes, no schema diff, no migration.

## Residual risk (stated, not hidden)
- The login-IP ceiling (60/min) is intentionally generous for CGNAT and shared
  hospital NAT; many patients behind one egress share that bucket. Per-identifier
  keying (5/min) is the real account defence; the IP ceiling is abuse friction.
- HSTS presence over TLS is proven in-suite (https-scheme request); `artisan
  serve` cannot terminate TLS so no live-TLS capture exists.
