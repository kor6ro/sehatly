<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

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
        | as the second disjunct so XHR callers outside the prefix are unaffected
        | until todo 30 removes the Inertia surface.
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
        | default handler, leaving the Inertia/HTML surface working.
        |
        | This is registered on `render()` rather than on `shouldRenderJsonWhen()`
        | because of ordering inside `Handler::render()`: `renderViaCallbacks()`
        | runs *before* the `unauthenticated()` fallback that 302s a browser to
        | `/login`, and before `renderExceptionResponse()`. Owning the response
        | here is therefore the only way to stop a Fortify-style redirect from
        | ever reaching an `api/*` client, and the only way to stop the default
        | 500 from serialising the throwable.
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
