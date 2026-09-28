# Task 3 Evidence — `/api/v1` API kernel (Sanctum, response envelope, CORS)

- **Task:** todo 3 — stand up the `/api/v1` API kernel
- **Branch:** `feat/sehatly-telemedicine`
- **Commit:** `59f476cfb56bd316d700ff79f77e07b29313a93f`
- **Message:** `feat(api): add api/v1 kernel with Sanctum, response envelope and CORS`
- **PHP:** `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17). Bare `php` on PATH is 8.2.29 and fails Laravel's `^8.3` platform check.
- **Framework:** Laravel 13.33.0

---

## 0. Environment baseline (before any change)

```
$ php -v
PHP 8.4.17 (cli) (NTS Visual C++ 2022 x64)

$ php artisan --version
Laravel Framework 13.33.0

$ git rev-parse --abbrev-ref HEAD
feat/sehatly-telemedicine

$ git log --oneline -3
1447536 docs(web): correct cssMinify measurement and satisfy the pint gate
71d0c28 docs(evidence): record todo 5 commit sha, protocol deviations and process cleanup
60cc707 feat(web): scaffold React SPA and relocate shadcn UI kit

$ git status --porcelain          # exit=0
 M .omo/plans/sehatly-telemedicine-platform.md
?? .omo/start-work/
```

Both non-`exit`-0 entries above are the **orchestrator's** (Appendix A edit + `.omo/start-work/`). Left untouched, never staged, never committed.

### Pre-existing uncommitted state I found (dirty_worktree probe)

- ` M .omo/plans/sehatly-telemedicine-platform.md` — orchestrator-owned Appendix A edit.
- `?? .omo/start-work/` — orchestrator-owned, untracked.

Nothing else was dirty. I did not add, commit, stash, or restore either.

### Database baseline (read-only probe via `php artisan tinker`)

```
SCHEMA sehatly            charset=utf8mb4 collation=utf8mb4_unicode_ci
SCHEMA telemedisin_db     charset=utf8mb4 collation=utf8mb4_unicode_ci
SCHEMA telemedisin_db_test charset=utf8mb4 collation=utf8mb4_unicode_ci
schemas NOT present: (none)
TABLES sehatly = 10
TABLES telemedisin_db = 0
TABLES telemedisin_db_test = 0
app.debug = true
```

> Note on method: `information_schema.tables` omits schemas that have zero tables, so the first
> probe appeared to show the databases as "absent". They are not absent — `information_schema.schemata`
> returns all three. The counts above are from the corrected probe. This matters because the whole
> point of M2 is protecting `sehatly`'s 10 tables.

### `bootstrap/cache` baseline — no config/route cache

```
Name          Length
----          ------
.gitignore    14
packages.php  1628
services.php  23363

config.php exists?     False
routes cache exists?  False
```

---

## 1. M2 — flip the dev database in `.env` (ONE line)

```
$ (Get-Content .env) ... replace exactly 'DB_DATABASE=sehatly' -> 'DB_DATABASE=telemedisin_db'
```

Verification:

```
$ php artisan tinker --execute="dump(config('database.connections.mysql.database'));"
"telemedisin_db"
exit=0

$ git status --porcelain
 M .omo/plans/sehatly-telemedicine-platform.md
?? .omo/start-work/
exit=0                       # <- no .env, it is gitignored

$ git check-ignore -v .env
.gitignore:2:.env	.env
exit=0

$ Select-String -Path .env -Pattern '^DB_'
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=telemedisin_db
DB_USERNAME=root
DB_PASSWORD=
```

**Exactly one line changed.** Siblings untouched. `.env` is gitignored so the tree is not dirtied.

---

## 2. M1 — `php artisan install:api`

### 2.1 Why `--without-migration-prompt`

`ApiInstallCommand::handle()` (Laravel 13.33.0, `vendor/laravel/framework/src/Illuminate/Foundation/Console/ApiInstallCommand.php:79`) is:

```php
if (! $this->option('without-migration-prompt')) {
    if ($this->confirm('One new database migration has been published. Would you like to run all pending database migrations?', true)) {
        $this->call('migrate');
    }
}
```

`confirm(..., default: true)` in a **non-interactive** shell returns the default, i.e. `true`. Piping `no` is not reliable under `-NonInteractive`. So I passed the command's own `--without-migration-prompt` flag. This is strictly stronger than "answering NO": no `migrate`/`migrate:fresh` was executed at any point in this todo.

```
$ php artisan install:api --without-migration-prompt
./composer.json has been updated
Running composer update laravel/sanctum --with-all-dependencies
Lock file operations: 1 install, 0 updates, 0 removals
 - Locking laravel/sanctum (v4.3.3)
Writing lock file
Installing dependencies from lock file (including require-dev)
 - Downloading laravel/sanctum (v4.3.3)
 - Installing laravel/sanctum (v4.3.3): Extracting archive
Generating optimized autoload files
> Illuminate\Foundation\ComposerScripts::postAutoloadDump
> @php artisan package:discover --ansi
 INFO Discovering packages.
 ...
 laravel/sanctum .. DONE
 ...
No security vulnerability advisories found.
 INFO Published API routes file.
 INFO API scaffolding installed. Please add the [Laravel\Sanctum\HasApiTokens] trait to your User model.

install:api exit=0 elapsed=186.1s
```

### 2.2 EXACT file list created / changed by `install:api`

Before/after manifests were taken of every repo file (excluding `vendor`, `node_modules`, `.git`, `web/node_modules`): **532 → 575** files.

**Created (3):**

| path | note |
|---|---|
| `config/sanctum.php` | published by the `vendor:publish --provider Laravel\Sanctum\SanctumServiceProvider` step; shipped with `'expiration' => null` |
| `database/migrations/2026_09_26_222801_create_personal_access_tokens_table.php` | the Sanctum `personal_access_tokens` migration |
| `routes/api.php` | copied from the `api-routes.stub` |

**Modified (3):**

| path | note |
|---|---|
| `bootstrap/app.php` | `uncommentApiRoutesFile()` inserted `api: __DIR__.'/../routes/api.php',` after the `web:` line. It does **not** add `apiPrefix` — I added that myself. |
| `composer.json` | added `"laravel/sanctum": "^4.0"`; composer also fixed the pre-existing missing trailing newline (`-}\ No newline at end of file` → `+}`) |
| `composer.lock` | locked `laravel/sanctum v4.3.3` |

**Also changed on disk, deliberately NOT part of the change set:**

- `bootstrap/cache/packages.php` (1628 → 1761 B) and `bootstrap/cache/services.php` (23363 → 23471 B) grew by 133 B / 108 B because Sanctum joined the discovered-package manifest. Both are gitignored via `bootstrap/cache/.gitignore` and appear in no `git status`.
- `storage/framework/views/*.php` — 41 compiled Blade cache files (gitignored).

**Removed:** none.

**Not done:** no migration was run. The database is untouched — see §11.

### 2.3 `install:api` scaffold routes

The stub registered `GET api/v1/user` behind `auth:sanctum`. I removed it. Rationale (documented in `routes/api.php`):

- it is an **auth endpoint owned by todo 20** (explicitly out of my scope);
- it needs `HasApiTokens` on `User`, which **todo 19** adds, so today it is a route that cannot succeed;
- every route registered here must be reconciled against the generated OpenAPI document by **todo 53**, and a scaffold path is drift by construction.

`routes/api.php` is kept on disk (rather than deleted) so that re-running `install:api` detects an existing API routes file and leaves it alone.

---

## 3. M3 — `api:` entry + `apiPrefix: 'api/v1'`

`ApplicationBuilder::withRouting()` applies the prefix as `Route::middleware('api')->prefix($apiPrefix)->group($api)` (L151-219). Effective prefix confirmed by **reading `route:list`, not assuming**:

```
$ php artisan route:list --path=api/v1
 GET|HEAD api/v1/user .. routes/api.php:6
 Showing [1] routes
exit=0
```

The `/api` URI segment is applied by the framework and customised through `apiPrefix`; `api/v1/user` proves both segments are live.

---

## 4. M4 — `App\Support\ApiResponse` and the global `Response` macro

`app/Support/ApiResponse.php` — `final`-style, two static factories, no Resources (todo 19+ owns those):

```php
public static function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
{
    return new JsonResponse([
        'success' => true,
        'data' => $data,
        'message' => $message,
    ], $status);
}

public static function error(string $message, array $errors = [], int $status = 400): JsonResponse
{
    return new JsonResponse([
        'success' => false,
        'message' => $message,
        'errors' => (object) $errors,
    ], $status);
}
```

**Choices, documented in-code:**

- **Key order is fixed and load-bearing** (`success,data,message` / `success,message,errors`) — the mobile client (todo 45) and the generated OpenAPI schema (todo 53) both read the envelope positionally.
- **`errors` is cast to `(object)`.** Without the cast a field-keyed map flips between `[]` and `{}` depending only on whether it happened to be empty, forcing a second shape check on every client. 401/403/404/500 all pass an empty map and must still emit `{}`.
- **Macro choice (M4's "or a macro-free helper").** I did *both*: `ApiResponse` is the single source of truth and `Response::macro('apiSuccess'|'apiError')` delegates to it, so a controller can pick either call style and cannot drift. They are registered in `bootstrap/app.php` rather than a service provider because the kernel is that file and a provider would be a fifth moving part for two aliases. The `api` prefix keeps them clear of future framework helpers. I did **not** register them on `ResponseFactory`, because `ResponseFactory::__call` invokes macros on a *new* `Response` and returns that instance — a macro there cannot replace the instance with a `JsonResponse`, so `response()->apiSuccess()` would be a lie.

---

## 5. M5 / M6 — exception rendering and the Fortify no-leak guarantee

### 5.1 Why this needed a `render()` callback and not just `shouldRenderJsonWhen`

`Illuminate\Foundation\Exceptions\Handler::render()` (L705) in this version:

```php
$e = $this->prepareException($e);

if ($response = $this->renderViaCallbacks($request, $e)) {   // <- my callback runs HERE
    return $this->finalizeRenderedResponse($request, $response, $e);
}

return $this->finalizeRenderedResponse($request, match (true) {
    $e instanceof HttpResponseException     => $e->getResponse(),
    $e instanceof AuthenticationException   => $this->unauthenticated($request, $e),   // <- 302 to /login
    $e instanceof ValidationException       => $this->convertValidationExceptionToResponse($e, $request),
    default                                 => $this->renderExceptionResponse($request, $e),
}, $e);
```

`renderViaCallbacks()` runs **before** the `unauthenticated()` fallback. That is the only place a 401 can be stopped from becoming `redirect()->guest(route('login'))`, and the only place the default 500 can be stopped from serialising the throwable.

### 5.2 Why the default 500 would have leaked (this app's `APP_DEBUG=true`)

`Handler::convertExceptionToArray()`:

```php
return config('app.debug') ? [
    'message'   => $e->getMessage(),
    'exception' => get_class($e),
    'file'      => $e->getFile(),
    'line'      => $e->getLine(),
    'trace'     => (new Collection($e->getTrace()))->map(...)->all(),
] : [
    'message' => $this->isHttpException($e) ? $e->getMessage() : 'Server Error',
];
```

`.env` has `APP_DEBUG=true` and `phpunit.xml` does **not** override it, so the test environment runs in debug mode too. Deferring to the default 500 would have shipped message + class + file + line + full trace to the client. The sanitized branch is therefore mandatory, and the test asserts the leak check *while asserting `app.debug === true`* — i.e. it proves the sanitizer holds in the worst case rather than passing because debug happened to be off.

Note this is a deliberate strengthening: the API 500 is sanitised **regardless of `app.debug`**, not only in production. The non-API path still returns `null` and keeps Laravel's debug renderer, so the Inertia surface (until todo 30) is unaffected.

### 5.3 The 422 message — a real defect found and fixed

`ValidationException::summarize()` promotes the **first field error** to the exception message and appends `(and N more errors)`. First live run returned:

```json
{"success":false,"message":"wajib","errors":{"no_telepon":["wajib"]}}
```

That is wrong for a stable contract: `message` becomes data-dependent, untranslatable, English-only, and duplicates `errors`. Fixed to a fixed, translatable summary `'The given data was invalid.'`; field detail stays in `errors`. Re-curl in §7 confirms the corrected body. The reason is documented in-code so no later todo "helpfully" restores `$e->getMessage()`.

### 5.4 The 404 message is replaced, not forwarded

`prepareException()` turns `ModelNotFoundException` into `NotFoundHttpException` carrying `"No query results for model [App\Models\User] 1."`, which would disclose the model layer. Fixed string `'Resource not found.'`.

### 5.5 `shouldRenderJsonWhen` and the no-`Accept` guarantee

```php
$exceptions->shouldRenderJsonWhen(
    fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson(),
);
```

`Request::is()` compiles `api/*` to `^api/.*$` and `*` spans slashes, so it covers the whole `/api/v1/...` tree. The path test is the **first** disjunct and never consults `Accept`, which is precisely what makes the plan's mandated no-header scenario return JSON. `expectsJson()` is retained as the second disjunct so XHR callers outside the prefix are unaffected until todo 30.

### 5.6 Status map (one callback, one shape)

| condition | status | message |
|---|---|---|
| `ValidationException` | 422 | `The given data was invalid.` + `errors` |
| `AuthenticationException` | 401 | `Unauthenticated.` |
| `AuthorizationException` / `AccessDeniedHttpException` | 403 | `This action is unauthorized.` |
| `ModelNotFoundException` / `NotFoundHttpException` | 404 | `Resource not found.` |
| any other `HttpExceptionInterface` | its own status (419/429/…) | its framework message |
| any other `Throwable` | 500 | `Internal server error.` — **fixed, nothing else reaches the client** |

Both `AuthorizationException` and `AccessDeniedHttpException` are matched because `prepareException()` rewrites one into the other depending on `hasStatus()`; the generic `HttpExceptionInterface` branch then preserves the status when `prepareException()` produced a plain `HttpException` (the `hasStatus()` path). The test suite exercises the `AccessDeniedHttpException` path, which is the one `throw new AuthorizationException` produces.

**Nothing is lost by the fixed 500:** the 500 is *logged* before rendering, so `storage/logs/laravel.log` retains everything the client did not get.

---

## 6. M7 — `config/cors.php`

`config/cors.php` was **absent** from this repository (see §0), so `HandleCors` was running on framework defaults. I created it.

**Origin determination was not a guess.** `web/vite.config.ts` declares `server.proxy` for `/api` (target `http://localhost:8000`, `changeOrigin: true`) but sets **neither `server.port` nor `server.origin`**, and leaves `strictPort` off. Therefore Vite serves the SPA on its default **5173**, and can walk forward to **5174** if 5173 is taken. `server.host` defaults to `localhost`, which Chrome resolves to the `127.0.0.1` origin, so both spellings are listed. Empirically, nothing was listening on 5173/5174 at baseline.

```php
'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', implode(',', [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
]))),
```

Runtime proof:

```
$ php artisan tinker --execute="dump(config('cors.paths')); dump(config('cors.allowed_origins'));"
array:3 [
  0 => "api/*"
  1 => "broadcasting/auth"
  2 => "sanctum/csrf-cookie"
]
array:4 [
  0 => "http://localhost:5173"
  1 => "http://127.0.0.1:5173"
  2 => "http://localhost:5174"
  3 => "http://127.0.0.1:5174"
]
exit=0
```

An explicit origin list (not a wildcard) is deliberate: a wildcard would let any site read authenticated responses out of the mobile client's browser session, and cookie-based Sanctum auth is the reason this file exists. `CORS_ALLOWED_ORIGINS` lets production be configured with no code change. `supports_credentials => true` is required for the cookie-based Sanctum SPA flow.

**`HandleCors` really is in the global stack** (M5 of the verification list says verify, don't assume — and `config/cors.php` being absent proved the default assumption was unsafe). Source:

```php
// vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php
public function getGlobalMiddleware()
{
    $middleware = $this->global ?: array_values(array_filter([
        \Illuminate\Http\Middleware\ValidatePathEncoding::class,
        \Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class,
        $this->trustHosts ? \Illuminate\Http\Middleware\TrustHosts::class : null,
        \Illuminate\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        ...
```

and asserted at runtime by `ApiKernelTest::test_handle_cors_is_in_the_global_middleware_stack`, which reads `app(Kernel::class)->getGlobalMiddleware()` and asserts `HandleCors::class` is in it.

---

## 7. M8 — `config/sanctum.php` explicit expiration

The published default was `'expiration' => null`. Verified what that actually means in Sanctum 4.3.3:

```php
// vendor/laravel/sanctum/src/Guard.php:128
(! $this->expiration || $accessToken->created_at->gt(now()->subMinutes($this->expiration)))
    && (! $accessToken->expires_at || ! $accessToken->expires_at->isPast())
    && $this->hasValidProvider($accessToken->tokenable);
```

With `$this->expiration === null`, `! null === true` and the first conjunct is unconditionally satisfied — **tokens never expire**. The plan's refresh flow would have been decorative. Set to a documented literal:

```php
'expiration' => 1440,
```

Why 1440 / why a literal (documented in-file): 24 h bounds the blast radius of a leaked token while outlasting a clinical shift, so a doctor is not logged out mid-consultation. A literal rather than `env()` so that a missing or blank env var can never silently resolve back to `null` and re-open the never-expire hole, and so the value is `config:cache`-safe.

```
$ php artisan tinker --execute="echo 'sanctum.expiration = '.config('sanctum.expiration').PHP_EOL; echo 'is_int = '.(is_int(config('sanctum.expiration')) ? 'true' : 'false').PHP_EOL; echo '>0 = '.(config('sanctum.expiration') > 0 ? 'true' : 'false').PHP_EOL;"
sanctum.expiration = 1440
is_int = true
>0 = true
exit=0
```

---

## 8. M9 / M11 — throwaway probe surface, then its removal

Probes registered in `routes/api.php` **only for the live-server QA in §9**, then deleted before commit:

```php
Route::get('/_ping', fn () => ApiResponse::success(['pong' => true], 'Sehatly API v1 is up.'));
Route::post('/_ping', fn () => ApiResponse::success([...]))->middleware('auth:sanctum');
Route::post('/_probe/validation', fn () => throw ValidationException::withMessages(['no_telepon' => ['wajib']]));
Route::get('/_probe/boom', fn () => throw new RuntimeException('PROBE-SECRET-MARKER-DO-NOT-LEAK-9f3a'));
Route::get('/_probe/forbidden', fn () => throw new AuthorizationException);
Route::get('/_probe/me', ...)->middleware('auth:sanctum');
```

`POST /_ping` was put behind `auth:sanctum` **specifically** so the plan's mandated `curl -X POST /api/v1/_ping` would land on the 401 branch of the mandated scenario rather than a 405.

```
$ php artisan route:list --path=api/v1          # with probes live
 GET|HEAD api/v1/_ping .. routes/api.php:30
 POST     api/v1/_ping .. routes/api.php:32
 GET|HEAD api/v1/_probe/boom .. routes/api.php:39
 GET|HEAD api/v1/_probe/forbidden .. routes/api.php:41
 GET|HEAD api/v1/_probe/me .. routes/api.php:43
 POST     api/v1/_probe/validation .. routes/api.php:35
 Showing [6] routes
exit=0

$ php artisan route:list --path=api/v1          # after M11 removal
 ERROR Your application doesn't have any routes matching the given criteria.
exit=0
```

`route:list --path=api/v1` runs without error and now shows **no static routes**, which is the correct kernel-only state. No `_ping` or `_probe` string survives anywhere in `routes/*.php`, `app/Support/*.php`, `bootstrap/app.php` or the test.

**The test does not depend on the probes.** `ApiKernelTest::defineApiRoutes()` registers its own `_test/*` routes at runtime inside the same `api/v1` + `api` middleware-group mount, so the kernel stays covered after probe removal and the test contributes **no** path to the OpenAPI document todo 53 reconciles. Proved by re-running the suite with probes gone:

```
$ php artisan test --filter=ApiKernelTest
{"tool":"pest","result":"passed","tests":16,"passed":16,"assertions":70,"duration_ms":442}
TEST exit=0
```

---

## 9. MANUAL QA — live server, raw curl, headers + body verbatim

Server started by me (**not** PID 22288, which was already on `127.0.0.1:8000` and was left alone):

```
$ php artisan serve --host=127.0.0.1 --port=8123
 WARN Unable to respect the `PHP_CLI_SERVER_WORKERS` environment variable without the `--no-reload` flag. Only creating a single server.
 INFO Server running on [http://127.0.0.1:8123].
```

- parent PID **3876** (`php artisan serve`), listener PID **27352** (`127.0.0.1:8123`)
- pre-existing, untouched: PID **22288** on `127.0.0.1:8000`; mysqld **17484** / **19796**

### 9.1 THE MANDATED SCENARIO — no `Accept` header at all

```
$ curl.exe -s -i -X POST http://127.0.0.1:8123/api/v1/_ping
HTTP/1.1 401 Unauthorized
Host: 127.0.0.1:8123
Connection: close
X-Powered-By: PHP/8.4.17
Cache-Control: no-cache, private
Date: Sat, 26 Sep 2026 22:34:13 GMT
Content-Type: application/json
Vary: Origin

{"success":false,"message":"Unauthenticated.","errors":{}}
exit=0
```

**Binary observable verdict: PASS.**

- `Content-Type: application/json` ✓
- **not** an HTML error page ✓
- **not** a 302 to `/login` ✓ (status is 401; no `Location` header present)
- body is the exact error envelope ✓

### 9.2 Happy path, for contrast

```
$ curl.exe -s -i -H "Accept: application/json" http://127.0.0.1:8123/api/v1/_ping
HTTP/1.1 200 OK
Host: 127.0.0.1:8123
Connection: close
X-Powered-By: PHP/8.4.17
Cache-Control: no-cache, private
Date: Sat, 26 Sep 2026 22:34:13 GMT
Content-Type: application/json
Vary: Origin

{"success":true,"data":{"pong":true},"message":"Sehatly API v1 is up."}
exit=0
```

### 9.3 422 (after the §5.3 message fix)

```
$ curl.exe -s -i -X POST http://127.0.0.1:8123/api/v1/_probe/validation
HTTP/1.1 422 Unprocessable Content
Host: 127.0.0.1:8123
Connection: close
X-Powered-By: PHP/8.4.17
Cache-Control: no-cache, private
Date: Sat, 26 Sep 2026 22:34:47 GMT
Content-Type: application/json
Vary: Origin

{"success":false,"message":"The given data was invalid.","errors":{"no_telepon":["wajib"]}}
exit=0
```

### 9.4 404 — unmatched `api/v1` path, the envelope not Laravel's default HTML 404

```
$ curl.exe -s -i http://127.0.0.1:8123/api/v1/no-such-path
HTTP/1.0 404 Not Found
Host: 127.0.0.1:8123
Connection: close
X-Powered-By: PHP/8.4.17
Cache-Control: no-cache, private
Date: Sat, 26 Sep 2026 22:34:20 GMT
Content-Type: application/json
Vary: Origin

{"success":false,"message":"Resource not found.","errors":{}}
exit=0
```

(`HTTP/1.0` here is a PHP built-in-server quirk on the not-found path — the body and content type are what matter.)

### 9.5 500 — sanitized, no internal detail

```
$ curl.exe -s -i http://127.0.0.1:8123/api/v1/_probe/boom
HTTP/1.1 500 Internal Server Error
Host: 127.0.0.1:8123
Connection: close
X-Powered-By: PHP/8.4.17
Cache-Control: no-cache, private
Date: Sat, 26 Sep 2026 22:34:20 GMT
Content-Type: application/json
Vary: Origin

{"success":false,"message":"Internal server error.","errors":{}}
exit=0
```

The client got 11 keys' worth of nothing: no `RuntimeException`, no `routes/api.php`, no `vendor/laravel`, no `.php`, no `Stack trace`, and none of the literal string `PROBE-SECRET-MARKER-DO-NOT-LEAK-9f3a`.

### 9.6 403

```
$ curl.exe -s -i http://127.0.0.1:8123/api/v1/_probe/forbidden
HTTP/1.1 403 Forbidden
Host: 127.0.0.1:8123
Connection: close
X-Powered-By: PHP/8.4.17
Cache-Control: no-cache, private
Date: Sat, 26 Sep 2026 22:34:20 GMT
Content-Type: application/json
Vary: Origin

{"success":false,"message":"This action is unauthorized.","errors":{}}
exit=0
```

### 9.7 Stronger than required — an explicit `Accept: text/html`

```
$ curl.exe -s -i -H "Accept: text/html,application/xhtml+xml" http://127.0.0.1:8123/api/v1/_probe/boom
HTTP/1.1 500 Internal Server Error
...
Content-Type: application/json

{"success":false,"message":"Internal server error.","errors":{}}

$ curl.exe -s -i -H "Accept: text/html" -X POST http://127.0.0.1:8123/api/v1/_ping
HTTP/1.1 401 Unauthorized
...
Content-Type: application/json

{"success":false,"message":"Unauthenticated.","errors":{}}
```

So an `api/*` caller that explicitly *asks* for HTML still gets JSON. The plan only required the no-header case.

### 9.8 CORS preflight

```
$ curl.exe -s -i -X OPTIONS http://127.0.0.1:8123/api/v1/_ping \
    -H "Origin: http://localhost:5173" \
    -H "Access-Control-Request-Method: POST" \
    -H "Access-Control-Request-Headers: content-type,authorization"
HTTP/1.0 204 No Content
Host: 127.0.0.1:8123
Connection: close
X-Powered-By: PHP/8.4.17
Cache-Control: no-cache, private
Date: Sat, 26 Sep 2026 22:34:58 GMT
Access-Control-Allow-Origin: http://localhost:5173
Vary: Origin, Access-Control-Request-Method, Access-Control-Request-Headers
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: POST
Access-Control-Allow-Headers: content-type,authorization
Access-Control-Max-Age: 0
Content-type: text/html; charset=UTF-8
exit=0
```

```
$ curl.exe -s -i -X OPTIONS http://127.0.0.1:8123/api/v1/_ping \
    -H "Origin: http://evil.example" \
    -H "Access-Control-Request-Method: POST"
HTTP/1.0 204 No Content
Host: 127.0.0.1:8123
Connection: close
X-Powered-By: PHP/8.4.17
Cache-Control: no-cache, private
Date: Sat, 26 Sep 2026 22:34:58 GMT
Vary: Origin, Access-Control-Request-Method
Content-type: text/html; charset=UTF-8
exit=0
```

An unlisted origin receives **no** `Access-Control-Allow-Origin` at all — correctly refused. (`Content-type: text/html; charset=UTF-8` on a bodyless 204 is `HandleCors`'s own framework response and is not an error page; the mandated observable does not apply to a CORS preflight, which never reaches routing or exception rendering.)

### 9.9 Fortify is intact and was NOT touched

```
$ curl.exe -s -i http://127.0.0.1:8123/dashboard
HTTP/1.1 500 Internal Server Error
...
Content-Type: text/html; charset=utf-8

<!DOCTYPE html>
```

This is the **web** path, and it is *not* a regression I introduced:

- my `render` callback returns `null` for non-`api/*` requests, so the web/Inertia surface keeps Laravel's default handling — which is exactly what "left working until todo 30" requires;
- the 500 is environmental: `.env` has `SESSION_DRIVER=database` and `CACHE_STORE=database` while the dev DB `telemedisin_db` has **0 tables**, so the session table does not exist. Todos 7-18 own the schema.

The redirect vector that actually matters for M6 was measured directly instead (§9.1): the `auth:sanctum` 401 on `api/*` is a 401 JSON envelope with no `Location` header, and `ApiKernelTest` additionally asserts `Route::has('login')` is `true` so the test proves the redirect target *exists* and is still being avoided — a redirect target that does not exist would make the assertion vacuous.

---

## 10. The 500 was *logged*, not swallowed (M5's "log it instead")

`storage/logs/laravel.log`, same request that produced §9.5's sanitised body:

```
[2026-09-26 22:34:20] local.ERROR: PROBE-SECRET-MARKER-DO-NOT-LEAK-9f3a {"exception":"[object] (RuntimeException(code: 0): PROBE-SECRET-MARKER-DO-NOT-LEAK-9f3a at C:/Users/axioo/Desktop/sehatly/routes/api.php:39)
[stacktrace]
#0 C:/Users/axioo/Desktop/sehatly/vendor/laravel/framework/src/Illuminate/Routing/CallableDispatcher.php(39): Illuminate/Routing/RouteFileRegistrar->{closure:C:/Users/axioo/Desktop/sehatly/routes/api.php:39}()
#1 C:/Users/axioo/Desktop/sehatly/vendor/laravel/framework/src/Illuminate/Routing/Route.php(254): Illuminate/Routing/Route->dispatch(...)
...
```

`PROBE-SECRET-MARKER` occurrence count in the log: **2** — exactly the two `_probe/boom` requests (curl 9.5 and 9.7). The message, the class, the file:line and the full stack trace are all retained server-side. The count of 2 also proves I did **not** double-report: the render callback never calls `report()`, because `Kernel::handleException()` already reports before rendering.

---

## 11. M10 — `ApiKernelTest`

`tests/Feature/ApiKernelTest.php` — a plain PHPUnit class (not Pest closures), 16 tests, 70 assertions.

| # | test | what it pins |
|---|---|---|
| 1 | `test_success_response_uses_the_exact_success_envelope` | 200, `application/json`, exact raw body string, decoded equality |
| 2 | `test_response_macro_delegates_to_the_same_envelope` | `Response::apiSuccess()` macro emits the identical shape |
| 3 | `test_error_macro_produces_the_error_envelope` | `Response::apiError()` macro, 3-key order |
| 4 | `test_validation_exception_renders_422_with_the_field_errors` | 422 + `errors.no_telepon` |
| 5 | `test_unauthenticated_request_renders_401_envelope_without_redirecting_to_login` | 401, no `Location`, **and** `Route::has('login') === true` so it is not vacuous |
| 6 | `test_authorization_exception_renders_403_envelope` | 403 |
| 7 | `test_unmatched_api_path_renders_the_404_envelope_not_an_html_page` | 404 envelope, `assertDontSee('<!DOCTYPE html')` |
| 8 | `test_server_error_never_leaks_internals_even_with_app_debug_enabled` | asserts `app.debug === true` **first**, then 500 body, then a 9-item forbidden-substring sweep, then a header sweep |
| 9 | `test_api_requests_render_json_even_without_an_accept_header` | the mandated scenario, in-process |
| 10 | `test_api_requests_still_render_json_when_accept_explicitly_asks_for_html` | stronger than mandated |
| 11 | `test_cors_preflight_is_answered_for_the_vite_dev_origin` | 204 + `Allow-Origin`/`Allow-Methods`/`Allow-Credentials` |
| 12 | `test_cors_preflight_is_refused_for_an_unknown_origin` | 204 with **no** `Allow-Origin` |
| 13 | `test_cors_configuration_covers_the_api_surface_and_the_vite_dev_origins` | exact `paths`, origin list contains 5173 both hosts |
| 14 | `test_sanctum_expiration_is_an_explicit_positive_integer` | `is_int`, `> 0`, `=== 1440` |
| 15 | `test_handle_cors_is_in_the_global_middleware_stack` | reads `getGlobalMiddleware()` at runtime |
| 16 | `test_api_routes_are_mounted_under_the_v1_prefix` | no `api/*` route exists outside `api/v1/*` |

### The leak assertions (test 8), verbatim from source

```php
$this->assertTrue(config('app.debug'), 'This test is only meaningful while app.debug is true; ...');

$body = $response->getContent();
$this->assertSame('{"success":false,"message":"Internal server error.","errors":{}}', $body);

foreach ([
    RuntimeException::class, 'RuntimeException', 'KERNEL-TEST-SECRET-MARKER',
    'routes/api.php', 'vendor/laravel', '.php', 'Stack trace', 'stacktrace', '#0 ',
] as $forbidden) {
    $this->assertStringNotContainsStringIgnoringCase($forbidden, $body, "The 500 body leaked [{$forbidden}].");
}

// The whole response, not just the body, must be free of internals.
$this->assertStringNotContainsStringIgnoringCase(
    RuntimeException::class,
    $response->headers->all() === [] ? '' : json_encode($response->headers->all())
);
```

### 11.1 Suite result

```
$ php artisan test --filter=ApiKernelTest
{"tool":"pest","result":"passed","tests":16,"passed":16,"assertions":70,"duration_ms":452}
TEST exit=0
```

### 11.2 JUnit proof of the test count (mandated, because a zero-match filter can look green)

```
$ php artisan test --filter=ApiKernelTest --log-junit="C:\Users\axioo\AppData\Local\Temp\opencode\apikernel.xml"
{"tool":"pest","result":"passed","tests":16,"passed":16,"assertions":70,"duration_ms":407}
exit=0
```

From the JUnit XML — three nested suites, the innermost being this class:

```xml
<testsuites>
  <testsuite name="C:\Users\axioo\Desktop\sehatly\phpunit.xml" tests="16" assertions="70" errors="0" failures="0" skipped="0" time="0.400067">
    <testsuite name="Feature" tests="16" assertions="70" errors="0" failures="0" skipped="0" time="0.400067">
      <testsuite name="Tests\Feature\ApiKernelTest" file="Api Kernel (Tests\Feature\ApiKernel)" tests="16" assertions="70" errors="0" failures="0" skipped="0" time="0.400067">
        <testcase name="Success response uses the exact success envelope" ... assertions="5" time="0.164676"/>
        <testcase name="Response macro delegates to the same envelope" ... assertions="2" time="0.012816"/>
        ... 16 testcase elements total ...
```

`<testcase>` element count parsed from the file: **16**.

### 11.3 `RefreshDatabase` did **not** fire — proof

`tests/Pest.php` binds `RefreshDatabase` to the `Feature` directory:

```php
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
```

`RefreshDatabase` performs `migrate:fresh`, which is forbidden against `telemedisin_db_test` in this todo. The binding applies to Pest closure tests; the pre-existing `tests/Feature/*` files *are* Pest closures (`test('...', function () {...})`) and **would** migrate. `ApiKernelTest` is a plain PHPUnit class, which does not receive Pest's trait injection — and that was verified rather than assumed, by counting tables in all three schemas before and after the run:

```
BEFORE:  sehatly=10  telemedisin_db=0  telemedisin_db_test=0
AFTER :  sehatly=10  telemedisin_db=0  telemedisin_db_test=0
```

`telemedisin_db_test` is still empty, so no migration ran, and `sehatly`'s 10 tables are untouched.

**Consequence, recorded as a deliberate decision:** the **full** suite (`php artisan test`) was *not* run in this todo, because it executes the pre-existing Pest Feature tests and would therefore `migrate:fresh` `telemedisin_db_test` — exactly what M-forbidden-constraints prohibits, and pointless before todos 7-18 create the schema (`DashboardTest` needs a `users` table). `--filter=ApiKernelTest` is the only sanctioned scope here.

---

## 12. ADVERSARIAL CLASSES

### 12.1 `misleading_success_output` — **APPLIES**, probed both directions

Highest risk: `--filter=ApiKernelTest` matching **zero** tests and still exiting 0. Probed with a filter that cannot possibly match:

```
$ php artisan test --filter=ThisNameCannotPossiblyMatchAnyTest9999 --log-junit="...\zero.xml"
{"tool":"pest","result":"failed","tests":0,"passed":0,"assertions":0,"duration_ms":3,"raw":["No tests found."]}
ZERO-MATCH exit=1

$ cat zero.xml
<?xml version="1.0" encoding="UTF-8"?>
<testsuites/>
```

**Finding:** on this setup the failure mode does **not** present — a zero-match filter exits **1** and writes an empty `<testsuites/>` with no `tests=` attribute, so it cannot be mistaken for a pass. I still kept the independent JUnit proof (16) rather than relying on the exit code, because that is the property that survives a future Pest/PHPUnit change.

I also caught a real instance of this class during the run: the first `php artisan test` execution printed **`tests:16, passed:13, failed:3` and exit 1**. All three failures were bugs in my own test file (I asserted `assertInstanceOf(ApiResponse::class)` on a value that is a `JsonResponse`, and I `POST`ed a route I had registered `GET`-only, yielding a 405). Had I trusted a green first run, I would have committed three broken tests.

### 12.2 `stale_state` — **APPLIES**, probed

`bootstrap/cache/config.php` would have pinned `sanctum.expiration` and `cors` to old values and made every assertion pass against stale config.

- Before the test run: `config.php exists? False`, `routes cache exists? False`.
- `php artisan config:clear` → `INFO Configuration cache cleared successfully.` exit 0.
- `php artisan route:clear` → `INFO Route cache cleared successfully.` exit 0.
- After both: `config.php exists? False`; dir is `.gitignore`, `packages.php`, `services.php` only.
- **Live-read proof.** I mutated the file on disk and re-read through a fresh process:

```
read current                -> 1440
set 'expiration' => 999     -> 999      # proves it is NOT served from a cached blob
restore 'expiration' => 1440-> 1440
$ Select-String config/sanctum.php  -> L65: 'expiration' => 1440,
```

A cached blob would have kept returning 1440. It did not. Left at 1440, verified on disk.

### 12.3 `dirty_worktree` — **APPLIES**, probed

Pre-existing uncommitted state found (both **mine/ours**, i.e. the orchestrator's, left exactly as found):
- ` M .omo/plans/sehatly-telemedicine-platform.md` — Appendix A edit
- `?? .omo/start-work/` — untracked

My commit contains **only** my 9 paths. Verified before and after committing:

```
$ git diff --cached --name-only
(empty)                      exit=0

$ git show --name-only --format="" HEAD
app/Support/ApiResponse.php
bootstrap/app.php
composer.json
composer.lock
config/cors.php
config/sanctum.php
database/migrations/2026_09_26_222801_create_personal_access_tokens_table.php
routes/api.php
tests/Feature/ApiKernelTest.php

$ git status --porcelain
 M .omo/plans/sehatly-telemedicine-platform.md
?? .omo/start-work/
```

> The `git status` snapshot above is from **commit time**, before this evidence file existed.
> The final post-cleanup status is in §15, and the untracked-evidence deviation is in §16.

The two orchestrator entries are still the *only* non-clean entries. Protected files untouched:

```
$ git diff --name-only HEAD~1 HEAD -- telemedicine_test.sql config/database.php phpunit.xml .env.example
(empty)                      exit=0
```

### 12.4 `hung_or_long_commands` — **APPLIES**, probed

Every long command had an explicit timeout and an observed exit code; nothing is reported without one.

| command | timeout | observed |
|---|---|---|
| `php artisan install:api --without-migration-prompt` | 600 s | exit 0 in **186.1 s** (composer download of sanctum dominates) |
| `php artisan tinker --execute=...` (x8) | 180 s | exit 0 each |
| `php artisan test --filter=ApiKernelTest` (x5 green, +1 red first run) | 600 s | exit 0 each, ~0.4–0.5 s each |
| `vendor/bin/pint` | 180 s | exit 0 |
| `php artisan serve --port=8123` | backgrounded, PID recorded | 3876 parent / 27352 listener |

A `Start-Process` call returned the harness error `Unknown: ChildProcess.kill` **after** the server had already started. Rather than assume, I checked `Get-NetTCPConnection -State Listen -LocalPort 8123` and the process table, which showed the listener on 27352 and PHP processes 3876 / 22288 / 27352. So the server was up and both of its PIDs were known for cleanup. **Only PIDs 3876 and 27352 — the ones I started — were stopped.** PID **22288** (the user's own `php artisan serve` on 8000) and mysqld **17484** / **19796** were never signalled.

### 12.5 `repeated_interruptions` — **APPLIES**, assessed for idempotency

Not triggered, but the half-kerneled state was reasoned through and the files were designed to make recovery cheap.

- **Re-running `php artisan install:api` is safe.** `ApiInstallCommand::handle()` (L50-52) checks `file_exists(routes/api.php) && ! --force` and then reports `API routes file already exists.` and **skips** the copy. Because I deliberately kept `routes/api.php` on disk (instead of deleting it), a second run will not clobber it. The Sanctum step is also self-guarded (L129-131): it only re-publishes when no `create_personal_access_tokens_table` migration is present.
- **Recovery checks if interrupted** between `install:api` and the config edits:
  1. `composer validate` — `composer.json` is only edited by composer itself;
  2. `php artisan route:list --path=api/v1` — runs without error in every state, because it exits 0 whether or not routes match;
  3. `Test-Path config/cors.php`, `Test-Path config/sanctum.php` — the only two files whose absence leaves a half-configured kernel, and both are created by me in a single deterministic step.
- The genuinely non-idempotent step is `pint` (it rewrites formatting). Re-running it is safe; it converges.

### 12.6 Ruled out

| class | reason |
|---|---|
| `malformed_input` | No input parser is authored. Everything consumed here is a Laravel route/exception/config object supplied by the framework; there is no user-supplied text to malform. |
| `prompt_injection` | No untrusted external text enters this todo. `telemedicine_test.sql` is first-party, byte-untouched (`git diff` empty per §12.3), and was not read as instruction. All inputs are this repo's own source. |
| `cancel_resume` | No resumable user flow exists yet. Auth and the booking/consultation flows are todos 20/45/46; this todo adds a kernel with zero business routes, so there is no partial state for a user to resume. |
| `flaky_tests` | One deterministic class, 16 tests, no time/randomness/network/DB. `DB_CONNECTION` is only read from config; no query is issued. `php artisan test --filter=ApiKernelTest` was run 5 times → `16/16, 70 assertions, exit 0` every time. The zero-match risk, the actual flake-shaped risk here, is probed in §12.1 instead. |

---

## 13. `vendor/bin/pint` (mandated before every commit)

```
$ & "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe" "vendor\bin\pint"
{"tool":"pint","result":"fixed","files":[{"path":"tests\\Feature\\ApiKernelTest.php","fixers":["fully_qualified_strict_types","unary_operator_spaces","not_operator_with_successor_space","ordered_imports"]}]}
exit=0
```

Four style fixers on my test file only (leading-`\` on global classes, `!` spacing, import order). Re-ran the suite after formatting: `16/16, 70 assertions, exit 0`. No other file was touched by pint.

---

## 14. Cleanup receipts

| receipt | evidence |
|---|---|
| probe routes/controllers deleted | `route:list --path=api/v1` → "no routes matching"; no `_ping`/`_probe` string in `routes/*.php`, `app/Support/*.php`, `bootstrap/app.php`, test file |
| `php artisan serve` I started stopped | started on **port 8123**; parent PID **3876**, listener PID **27352**; both stopped (§15) |
| PID 22288 untouched | still the owner of `127.0.0.1:8000` after cleanup |
| mysqld 17484 / 19796 untouched | never signalled |
| `bootstrap/cache/config.php` | never created; `config:clear` + `route:clear` both exit 0, dir re-verified `config.php exists? False` |
| temp JUnit + probe scripts outside repo | deleted from `C:\Users\axioo\AppData\Local\Temp\opencode` (§15) |
| no `mobile/` or `pubspec.yaml` | `git show --name-only HEAD` lists 9 files, none of them that |
| no migration run | all three schema table counts identical before and after (§11.3) |
| no forbidden git command | only `git status`, `git add -- <paths>`, `git commit -m ... -- <paths>`, `git diff`, `git show`, `git log`, `git rev-parse`, `git check-ignore` |

---

## 15. Final state (post-cleanup, re-verified)

The `git status` below is the **actual** output of the last run, executed *after* cleanup finished.
It is not the pre-cleanup snapshot.

```
$ php artisan test --filter=ApiKernelTest
{"tool":"pest","result":"passed","tests":16,"passed":16,"assertions":70,"duration_ms":460}
TEST exit=0

$ composer validate --no-check-publish
./composer.json is valid
exit=0

$ git diff --cached --name-only
(empty)                                                # nothing left staged

$ git status --porcelain
 M .omo/plans/sehatly-telemedicine-platform.md      <- orchestrator's, untouched
?? .omo/evidence/task-3-sehatly.md                  <- MINE, see §16
?? .omo/start-work/                                 <- orchestrator's, untouched

$ Test-Path bootstrap\cache\config.php
False
$ Test-Path mobile  /  Test-Path pubspec.yaml
False / False
```

**All 16 code paths are committed** in `59f476cfb56bd316d700ff79f77e07b29313a93f` on
`feat/sehatly-telemedicine`. Exactly one of my files is **not** in that commit: this evidence
file, which is untracked. See §16 — this is a deviation I am reporting rather than papering over.

### 15.1 Cleanup was executed, not just asserted

Every §14 receipt that involves live process state was re-checked with an explicit command and
observed exit code *after* the work finished:

```
$ Get-NetTCPConnection -State Listen -LocalPort 8123,8000
 8123 -> 27352      <- mine
 8000 -> 22288      <- the user's, pre-existing

$ Stop-Process -Id 27352 -Force ; Stop-Process -Id 3876 -Force
stop issued

$ # after:
 8123 listeners remaining: 0            <- my server is gone
 8000 listener: 22288                   <- still the user's PID, untouched
 mysqld alive: 17484, 19796            <- never signalled
 3876 stopped / 27352 stopped
```

Temp files under `C:\Users\axioo\AppData\Local\Temp\opencode` — **25** files I created, in two passes
(`apikernel.xml`, `zero.xml`, `check_sanctum.php`, `db_counts.php`, `db_counts2.php`,
`db_counts_final.php`, `curl_A..K_*.txt` (11), `serve8123.{err,log,pid}`, `t3_before.txt`,
`t3_after.txt`, and the Sanctum research copies `sanctum-config.php`, `sanctum-guard.php`,
`sanctum-main.php`, `sanctum.txt`):

```
pass 1: removed 21 of 21 of my temp files
        verify: all of my temp files are gone          <- WRONG, see below

pass 2: removed 4 of 4 stragglers
        verify: none - clean
        total temp files now: 377 (was 402)
```

**The first pass was incomplete and its "all gone" check was false.** I built the removal list from
recall rather than from a directory diff, so 4 files were missed. I only caught them because a later
sweep used a *pattern* (`^(t3_|db_counts|check_sanctum|serve8123|curl_|apikernel|sanctum-|...)`)
instead of a hand-typed list. Ownership was then established from evidence, not assumption:

| file | what it is | timestamp (local) | verdict |
|---|---|---|---|
| `sanctum-config.php` | published Sanctum config, `'expiration' => null` | 9/26 22:33:04 | mine — the pre-1440 default that §7 cites |
| `sanctum-guard.php` | `Laravel\Sanctum\Guard` | 9/26 22:33:05 | mine — the exact class §7 quotes at L128 |
| `sanctum-main.php` | that class's test file (`use Mockery`) | 9/26 22:33:13 | mine |
| `sanctum.txt` | the Laravel Sanctum 13.x docs page | 9/26 22:24:59 | mine — the `expiration` reference |

All four bracket this todo's research window: created 22:24–22:33, one minute before the manual-QA
curls (22:34 UTC) and ~7 min before the commit (22:40 UTC). Had the timestamp/content check not been
run, I would have reported a clean temp dir that was not clean — the same
`misleading_success_output` class as §12.1, caught in a different place.

Deletion was done **by explicit name**, never by a wildcard: an attempt at
`Remove-Item "$tmp\*"` was rejected by PowerShell non-interactive mode, and even had it run it
would have swept 381 pre-existing files belonging to unrelated work. Spot-checked as preserved:
`plan_full.txt`, `repomix.md.bak`, `simfresh2.ps1`, `qa32.ps1`.

The same care applied outside the repo. I had accidentally created `C:\Users\axioo\.omo\evidence`
(an empty dir) while checking a path. I removed **only that empty subdir**; the parent
`C:\Users\axioo\.omo` holds 988 pre-existing tooling files (codegraph, lsp-daemon, node_modules,
runtime/ast-grep) and was left completely untouched — verified still present afterwards.

---

## 16. Protocol deviation — one commit per todo (reported, not concealed)

The todo's commit protocol is **one commit, exact message, exact scoped paths**. That part held:
commit `59f476cfb56bd316d700ff79f77e07b29313a93f` has message
`feat(api): add api/v1 kernel with Sanctum, response envelope and CORS` and contains only the 9
code paths.

**The deviation:** I wrote this evidence file *after* that commit was created. So
`.omo/evidence/task-3-sehatly.md` is untracked, and the work currently spans a commit plus an
uncommitted artifact rather than one self-contained commit.

Folding it in would require either `git commit --amend` or a `git reset`, and **both are
explicitly forbidden for this todo**. A second commit was also rejected, because it would break
the same one-commit rule while additionally leaving the final commit not being the code change.
So the deviation is left visible and documented here instead of being engineered away.

Exactly the two orchestrator-owned entries remain alongside it, still untouched and never staged:
`.omo/plans/sehatly-telemedicine-platform.md` (modified) and `.omo/start-work/` (untracked).
