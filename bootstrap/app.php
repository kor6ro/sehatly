<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserType;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| API Response Macros
|--------------------------------------------------------------------------
|
| `ApiResponse` is the single source of truth for the `{success,data,message}` and
| `{success,message,errors}` envelopes, so these two macros delegate to it instead
| of re-deriving the shape. A controller may therefore use the static call or the
| macro call and still cannot drift from the contract. They are registered here,
| not in a service provider, because the kernel is this file and a provider would
| be a fifth moving part for two aliases. The `api` prefix keeps them clear of
| framework response helpers.
|
*/

Response::macro('apiSuccess', function (mixed $data = null, string $message = '', int $status = 200): JsonResponse {
    return ApiResponse::success($data, $message, $status);
});

Response::macro('apiError', function (string $message, array $errors = [], int $status = 400): JsonResponse {
    return ApiResponse::error($message, $errors, $status);
});

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withBroadcasting(
        channels: __DIR__.'/../routes/channels.php',
        attributes: [
            /*
            |--------------------------------------------------------------------
            | The broadcast auth endpoint
            |--------------------------------------------------------------------
            |
            | `withBroadcasting()` expands to exactly two things
            | (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php`):
            | `Broadcast::routes($attributes)`, which registers
            | `GET|POST /broadcasting/auth`, and a `require` of the channels file.
            | The first needs a prefix and a middleware stack, which is what
            | `$attributes` is for.
            |
            | `prefix: api` and NOT `api/v1` is a contract fact, not a preference.
            | The Dart client posts to `/api/broadcasting/auth`
            | (`packages/sehatly_api_client/lib/src/realtime/realtime_socket.dart`),
            | which is the framework's own default path, so moving the endpoint
            | under `/api/v1` would 404 the only client this application has.
            |
            | `auth:sanctum` is what turns the endpoint from "anyone can ask"
            | into "a caller proves who they are first". The route is registered
            | without it by default (`BroadcastManager::routes()` falls back to
            | `['middleware' => ['web']]`), and on this application the `web`
            | group has no session to authenticate, so every request would fail
            | the user lookup further down rather than for the stated reason.
            | Naming `auth:sanctum` moves the 401 to the guard, where the rest of
            | `/api/*` puts it.
            |
            | `api` before `auth:sanctum` is ordering, not decoration: it is the
            | same stateless group `withRouting(apiPrefix: 'api/v1')` builds, and
            | it is what the CORS entry in `config/cors.php` matches.
            |
            */

            'prefix' => 'api',
            'middleware' => ['api', 'auth:sanctum'],
        ],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        |--------------------------------------------------------------------
        | Guests are never redirected
        |--------------------------------------------------------------------
        |
        | **This line is load-bearing and its removal is a 500 on every
        | unauthenticated API request.**
        |
        | `ApplicationBuilder::withMiddleware()` registers a default guest
        | redirect of `fn () => route('login')` before this callback runs -
        | `vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:291`.
        | Fortify was the package that made `route('login')` resolve, and todo 30
        | removed it. The failure was found by three **green** baseline tests, not
        | by the eleven red ones: an unauthenticated `POST /api/v1/...` behind
        | `auth:sanctum` reached
        | `Illuminate\Auth\Middleware\Authenticate::redirectTo()`
        | (`.../Authenticate.php:104`), which called that closure, which threw
        | `RouteNotFoundException: Route [login] not defined`. The middleware's own
        | `AuthenticationException` never got thrown, so the render callback below
        | never saw it, and the client received a sanitised **500** where the whole
        | contract promises a 401.
        |
        | `redirectGuestsTo(null)` is the framework's own opt-out
        | (`.../Configuration/Middleware.php:539`): it installs `fn () => null`, so
        | `redirectTo()` returns null, `Authenticate::unauthenticated()` throws the
        | `AuthenticationException` it always meant to throw, and the renderer
        | turns it into `{"success":false,"message":"Unauthenticated.","errors":{}}`.
        |
        | There is deliberately no replacement URL. A bearer-token API has no page
        | to send a caller to, and inventing one - `/login` on this application is
        | now the SPA's client-side route, which a 302 to it would defeat - would
        | reintroduce exactly the redirect `ApiKernelTest` asserts is absent.
        |
        */

        $middleware->redirectGuestsTo(null);

        /*
        |--------------------------------------------------------------------
        | The web group appends nothing
        |--------------------------------------------------------------------
        |
        | Todo 30 removed the three entries that used to be here, and each was
        | removed for a reason that is checkable rather than a matter of taste:
        |
        | - `HandleInertiaRequests` and `HandleAppearance` extended or read the
        |   Inertia root template that `resources/views/app.blade.php` no longer
        |   is. Inertia itself is uninstalled, so the second class no longer had
        |   a parent to extend.
        | - `Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets` is not
        |   an Inertia class, and is kept in stock Laravel apps for the HTML
        |   surface. It was dropped here because it is now provably inert:
        |   `AddLinkHeadersForPreloadedAssets::handle()`
        |   (`vendor/laravel/framework/src/Illuminate/Http/Middleware/AddLinkHeadersForPreloadedAssets.php:33`)
        |   only writes a `Link` header when `Vite::preloadedAssets()` is
        |   non-empty, and that list is filled solely by the `@vite` and
        |   `@preload` Blade directives. The SPA shell in `routes/web.php`
        |   serves a prebuilt `index.html` and emits no Vite tag, so the
        |   condition can never be true and the middleware could never act.
        |
        | `encryptCookies()` is no longer called with an exception list either.
        | Its two names, `appearance` and `sidebar_state`, were the only cookies
        | the Inertia scaffold read: `HandleAppearance` shared `appearance` into
        | the view, and `HandleInertiaRequests::share()` read `sidebar_state`.
        | Both classes are gone and nothing else in the application reads either
        | cookie, so leaving them unencrypted would be an exemption with no reader
        | behind it. Laravel's default exception list is empty, which is the
        | behaviour wanted here.
        |
        | The group is left at the framework default. The `web` group still
        | starts a session and shares validation errors, and the SPA shell
        | still runs through it, but nothing in this application reads session
        | state any more: `/api/v1` is bearer-token authenticated and lives in the
        | stateless `api` group.
        |
        */

        /*
        |--------------------------------------------------------------------
        | RBAC aliases
        |--------------------------------------------------------------------
        |
        | `permission:<kode>` gates on a grant held through
        | `user_roles -> role_permissions -> permissions`, and `tipe:<values>`
        | gates on the `users.tipe` ENUM at telemedicine_test.sql:139. The two
        | answer different questions - a grant versus an account type - and the
        | plan's module todos use both, so both are registered here.
        |
        | Registered as aliases rather than appended to a group because neither
        | belongs in a group: they are opt-in per route, and `auth:sanctum` must
        | be named explicitly on the route so that "unauthenticated" is answered
        | by the guard (401) rather than by these two. Both still return 401 on
        | their own if a route forgets it, so the ordering mistake is safe.
        |
        | Both middlewares build their own failure bodies from `ApiResponse`,
        | which is why `bootstrap/app.php` is the only place in the kernel that
        | needs to know the error shape.
        |
        */

        $middleware->alias([
            'permission' => EnsurePermission::class,
            'tipe' => EnsureUserType::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        |--------------------------------------------------------------------
        | JSON negotiation
        |--------------------------------------------------------------------
        |
        | `api/*` matches the whole `/api/v1/...` tree because `Request::is()`
        | compiles `api/*` to `^api/.*$` and `*` spans slashes. The path test is
        | evaluated first and does not consult the `Accept` header at all, which is
        | what guarantees an `api/*` caller that sends no `Accept: application/json`
        | still gets JSON rather than an HTML error page. `expectsJson()` is kept
        | as the second disjunct so an XHR caller outside the prefix still gets
        | JSON: the SPA is served from this same origin in the same-origin
        | deployment `routes/web.php` supports, so its `fetch` calls are XHR and
        | may legitimately be addressed without the `api/` prefix.
        |
        */

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
        |--------------------------------------------------------------------
        | Exception rendering
        |--------------------------------------------------------------------
        |
        | One callback renders every `api/*` failure so a client never has to
        | parse a second body shape depending on which layer raised the error.
        | Returning `null` for non-API paths hands the exception back to Laravel's
        | default handler, which now only ever sees the SPA shell in
        | `routes/web.php` and its health check.
        |
        | This is registered on `render()` rather than on `shouldRenderJsonWhen()`
        | because of ordering inside `Handler::render()`: `renderViaCallbacks()`
        | runs *before* the `unauthenticated()` fallback that 302s a browser to
        | a login page, and before `renderExceptionResponse()`. Owning the response
        | here is therefore the only way to stop a redirect from ever reaching an
        | `api/*` client, and the only way to stop the default 500 from
        | serialising the throwable. Since todo 30 removed Fortify there is no
        | named `login` route for that fallback to name, so a browser hitting an
        | `api/*` path without a token gets the 401 envelope below and not a
        | redirect. `ApiKernelTest` asserts the absence of the route as well as
        | the absence of the header, so the property cannot rot back.
        |
        | Status mapping: 422 validation, 401 unauthenticated, 403 unauthorized,
        | 404 not found, and a sanitized 500 for everything else.
        |
        */

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                // The summary is fixed rather than `$e->getMessage()`. Laravel
                // builds that message with `ValidationException::summarize()`,
                // which promotes the *first field error* and appends
                // "(and N more errors)", so `withMessages(['no_telepon' =>
                // ['wajib']])` would publish `"message":"wajib"`. That makes the
                // envelope's message data-dependent, untranslatable and English
                // only, and it duplicates detail that `errors` already carries.
                // Field-level text belongs in `errors`; `message` stays a stable
                // string clients can key off.
                $e instanceof ValidationException => ApiResponse::error(
                    'The given data was invalid.',
                    $e->errors(),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                ),

                $e instanceof AuthenticationException => ApiResponse::error(
                    'Unauthenticated.',
                    [],
                    Response::HTTP_UNAUTHORIZED,
                ),

                // `Handler::prepareException()` rewrites a status-less
                // AuthorizationException into an AccessDeniedHttpException, and
                // both classes are matched here so the 403 survives either path.
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => ApiResponse::error(
                    'This action is unauthorized.',
                    [],
                    Response::HTTP_FORBIDDEN,
                ),

                // The message is replaced rather than forwarded: a
                // ModelNotFoundException arrives here already rewritten to
                // NotFoundHttpException carrying text like
                // "No query results for model [App\Models\User] 1.", which would
                // disclose the model layer to any caller.
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    'Resource not found.',
                    [],
                    Response::HTTP_NOT_FOUND,
                ),

                // Other HTTP exceptions (419 CSRF mismatch, 429 throttle, ...)
                // keep their own status and framework-authored message. A
                // ValidationException or NotFoundHttpException never lands here,
                // so no field-level detail is lost.
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() ?: 'Request rejected.',
                    [],
                    $e->getStatusCode(),
                ),

                // Unhandled server fault. Only this fixed string reaches the
                // client: `Handler::convertExceptionToArray()` returns the
                // message, exception class, file, line and full trace whenever
                // `app.debug` is true, and this app runs with APP_DEBUG=true, so
                // deferring to the default 500 would hand out the stack trace.
                // `Kernel::handleException()` reports the throwable to the log
                // before rendering, so the diagnostic detail is kept server-side.
                default => ApiResponse::error(
                    'Internal server error.',
                    [],
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                ),
            };
        });
    })->create();
