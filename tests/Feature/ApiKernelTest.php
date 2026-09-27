<?php

namespace Tests\Feature;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Kernel-level contract for `/api/v1`: the `{success,data,message}` /
 * `{success,message,errors}` envelope, the status-to-envelope mapping for every
 * failure mode, the guarantee that a 500 never leaks internals, and CORS.
 *
 * Every route used here is registered at runtime rather than living in
 * `routes/api.php`, so this file keeps testing the kernel after todo 3's probe
 * routes are deleted and never contributes a path to the OpenAPI document that
 * todo 53 reconciles against the live route table.
 *
 * No database is touched: the kernel is reached before any model or query, so the
 * test is safe to run against an empty `telemedisin_db_test`.
 *
 * **Todo 30 rewrote one precondition in this file rather than deleting the test.**
 * `test_unauthenticated_request_renders_401_envelope_without_redirecting_to_login`
 * used to assert `Route::has('login')` was **true** as the reason its 401
 * assertion meant anything - "otherwise this test proves nothing", because
 * Fortify had registered the route the framework would otherwise redirect to.
 * Deleting Fortify falsifies that, so the precondition is now the opposite claim
 * (no named authentication route exists) and the `Location` assertion is
 * unchanged.
 */
class ApiKernelTest extends TestCase
{
    /**
     * Mirrors the `api/v1` mount that `bootstrap/app.php` builds via
     * `apiPrefix`, so the routes land on the same prefix and the same stateless
     * `api` middleware group as real endpoints.
     */
    protected function defineApiRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(function (): void {
            Route::get('/_test/ping', fn () => ApiResponse::success(['pong' => true], 'pong'));
            Route::post('/_test/validation', fn () => throw ValidationException::withMessages([
                'no_telepon' => ['wajib'],
            ]));
            Route::get('/_test/boom', fn () => throw new RuntimeException('KERNEL-TEST-SECRET-MARKER'));
            Route::get('/_test/forbidden', fn () => throw new AuthorizationException);
            Route::match(['get', 'post'], '/_test/me', fn () => ApiResponse::success(['ok' => true], 'ok'))
                ->middleware('auth:sanctum');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->defineApiRoutes();
    }

    public function test_success_response_uses_the_exact_success_envelope(): void
    {
        $response = $this->get('/api/v1/_test/ping');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');

        // The raw body is asserted as well as the decoded structure because the
        // mobile client and the generated OpenAPI schema both read the envelope
        // positionally: success, then data, then message.
        $this->assertSame(
            '{"success":true,"data":{"pong":true},"message":"pong"}',
            $response->getContent()
        );

        $this->assertSame(
            ['success' => true, 'data' => ['pong' => true], 'message' => 'pong'],
            $response->json()
        );
    }

    public function test_response_macro_delegates_to_the_same_envelope(): void
    {
        $response = (new Response)->apiSuccess(['pong' => true], 'macro');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(
            '{"success":true,"data":{"pong":true},"message":"macro"}',
            $response->getContent()
        );
    }

    public function test_error_macro_produces_the_error_envelope(): void
    {
        $response = (new Response)->apiError('nope', ['a' => ['b']], 422);

        $this->assertSame(
            '{"success":false,"message":"nope","errors":{"a":["b"]}}',
            $response->getContent()
        );
    }

    public function test_validation_exception_renders_422_with_the_field_errors(): void
    {
        $response = $this->post('/api/v1/_test/validation');

        $response->assertStatus(422);
        $response->assertHeader('Content-Type', 'application/json');

        $this->assertSame(
            '{"success":false,"message":"The given data was invalid.","errors":{"no_telepon":["wajib"]}}',
            $response->getContent()
        );

        $this->assertSame([
            'success' => false,
            'message' => 'The given data was invalid.',
            'errors' => ['no_telepon' => ['wajib']],
        ], $response->json());
    }

    public function test_unauthenticated_request_renders_401_envelope_without_redirecting_to_login(): void
    {
        $response = $this->post('/api/v1/_test/me');

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');

        // Todo 30 removed Fortify, so there is no longer a `login` route for the
        // framework's `unauthenticated()` fallback to name. The precondition this
        // assertion used to carry - "the redirect target really does exist, so a
        // 302 here would be reachable" - was true at the time it was written and
        // would have become false silently, which is the shape of a test that
        // stops testing anything. It is restated in the only direction that stays
        // true as the application grows: **no** named authentication route exists
        // that a browser could be sent to, so a 302 could not be produced even in
        // principle. `WebSurfaceTest` asserts the same absence across the whole
        // route table and the deleted files' paths.
        $this->assertFalse(
            Route::has('login'),
            'A named login route exists again, so the 401 must be re-checked for a Location header.'
        );
        $this->assertFalse(Route::has('password.confirm'), 'An authentication route reappeared.');

        $this->assertNull(
            $response->headers->get('Location'),
            'An api/* 401 must not carry a Location header.'
        );

        $this->assertSame(
            '{"success":false,"message":"Unauthenticated.","errors":{}}',
            $response->getContent()
        );
    }

    public function test_authorization_exception_renders_403_envelope(): void
    {
        $response = $this->get('/api/v1/_test/forbidden');

        $response->assertStatus(403);
        $response->assertHeader('Content-Type', 'application/json');

        $this->assertSame(
            '{"success":false,"message":"This action is unauthorized.","errors":{}}',
            $response->getContent()
        );
    }

    public function test_unmatched_api_path_renders_the_404_envelope_not_an_html_page(): void
    {
        $response = $this->get('/api/v1/there-is-no-such-endpoint');

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDontSee('<!DOCTYPE html', false);

        $this->assertSame(
            '{"success":false,"message":"Resource not found.","errors":{}}',
            $response->getContent()
        );
    }

    public function test_server_error_never_leaks_internals_even_with_app_debug_enabled(): void
    {
        // This is the property that makes the explicit branch in
        // `bootstrap/app.php` necessary: `Handler::convertExceptionToArray()`
        // returns message, class, file, line and trace whenever app.debug is on,
        // and this repository ships APP_DEBUG=true with no override in
        // phpunit.xml, so the test environment runs in debug mode.
        $this->assertTrue(
            config('app.debug'),
            'This test is only meaningful while app.debug is true; it proves the '
            .'sanitizer holds in the worst case rather than relying on debug being off.'
        );

        $response = $this->get('/api/v1/_test/boom');

        $response->assertStatus(500);
        $response->assertHeader('Content-Type', 'application/json');

        $body = $response->getContent();

        $this->assertSame('{"success":false,"message":"Internal server error.","errors":{}}', $body);

        foreach ([
            RuntimeException::class,
            'RuntimeException',
            'KERNEL-TEST-SECRET-MARKER',
            'routes/api.php',
            'vendor/laravel',
            '.php',
            'Stack trace',
            'stacktrace',
            '#0 ',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $body,
                "The 500 body leaked [{$forbidden}]."
            );
        }

        // The whole response, not just the body, must be free of internals.
        $this->assertStringNotContainsStringIgnoringCase(
            RuntimeException::class,
            $response->headers->all() === [] ? '' : json_encode($response->headers->all())
        );
    }

    public function test_api_requests_render_json_even_without_an_accept_header(): void
    {
        // The plan's mandated failure scenario: an api/* caller that does not
        // negotiate JSON must still receive the envelope rather than an HTML error
        // page or a 302 to /login.
        $response = $this->call('POST', '/api/v1/_test/me', [], [], [], [
            'HTTP_ACCEPT' => null,
        ]);

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDontSee('<!DOCTYPE html', false);
        $this->assertNull($response->headers->get('Location'));
    }

    public function test_api_requests_still_render_json_when_accept_explicitly_asks_for_html(): void
    {
        $response = $this->call('GET', '/api/v1/there-is-no-such-endpoint', [], [], [], [
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
        ]);

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDontSee('<!DOCTYPE html', false);
    }

    public function test_cors_preflight_is_answered_for_the_vite_dev_origin(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/_test/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization',
        ]);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $response->assertHeader('Access-Control-Allow-Methods', 'POST');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_cors_preflight_is_refused_for_an_unknown_origin(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/_test/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertStatus(204);
        $this->assertNull(
            $response->headers->get('Access-Control-Allow-Origin'),
            'An unlisted origin must not be echoed back.'
        );
    }

    public function test_cors_configuration_covers_the_api_surface_and_the_vite_dev_origins(): void
    {
        $this->assertSame(
            ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],
            config('cors.paths')
        );

        $origins = config('cors.allowed_origins');

        $this->assertContains('http://localhost:5173', $origins);
        $this->assertContains('http://127.0.0.1:5173', $origins);
    }

    public function test_sanctum_expiration_is_an_explicit_positive_integer(): void
    {
        $expiration = config('sanctum.expiration');

        $this->assertIsInt($expiration, 'sanctum.expiration must resolve to an int.');
        $this->assertGreaterThan(0, $expiration, 'A null/zero expiration means tokens never expire.');
        $this->assertSame(1440, $expiration);
    }

    public function test_handle_cors_is_in_the_global_middleware_stack(): void
    {
        $global = app(Kernel::class)->getGlobalMiddleware();

        $this->assertContains(
            HandleCors::class,
            $global,
            'HandleCors is absent from the global stack, so config/cors.php would never run.'
        );
    }

    public function test_api_routes_are_mounted_under_the_v1_prefix(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn ($uri) => str_starts_with($uri, 'api/'))
            ->values()
            ->all();

        // Everything under /api must carry the v1 segment, because
        // `apiPrefix: 'api/v1'` is what todo 53 and the mobile client key off.
        $this->assertNotEmpty($uris);
        $this->assertSame([], array_values(array_filter(
            $uris,
            fn ($uri) => ! str_starts_with($uri, 'api/v1/')
        )));
    }
}
