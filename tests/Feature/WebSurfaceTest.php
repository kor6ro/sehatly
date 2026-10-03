<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\FakeOtpSender;

/*
|--------------------------------------------------------------------------
| The web scaffold, and what replaced it
|--------------------------------------------------------------------------
|
| Todo 30 removed the last of the Laravel starter kit from this repository: the
| Inertia root template, the Fortify session-authentication surface, and the nine
| Feature test files that asserted them. This file is what is left of that work,
| and it exists because "deleted the tests" is only half of a removal.
|
| ## The tests that went, and why each went
|
| The 11 failures at the baseline were not 11 bugs. They were assertions about a
| surface that cannot exist against this contract, and the reason is a single
| fact read out of the DDL rather than inferred: **`users`
| (`telemedicine_test.sql:132-149`) has no `password`, no `name`, no
| `email_verified_at`, no `remember_token` and no `two_factor_*` column.** It has
| `nama_lengkap`, `kata_sandi_hash`, `telepon_terverifikasi` and
| `email_terverifikasi` in their place. `password_reset_tokens` - the table
| `config/auth.php:98` named as the password-reset broker - is not one of the 75
| contract tables and not one of the 7 registered extra tables either, so it does
| not exist at all.
|
| Each retired test was classified before it was deleted, as one of:
|
| - **(a) a feature this contract deliberately does not have**, so the test is
|   retired with a reason and a pointer to the API that replaced it;
| - **(b) real behaviour reached through the wrong layer**, so it is rewritten
|   against the correct surface;
| - **(c) a genuine bug**, so it is fixed.
|
| The per-test table is in `.omo/evidence/task-30-sehatly.md`. In summary: eleven
| were (a), two were (b) - "profile information can be updated" and the dashboard
| page - and **none was (c)**. That last part is the finding worth stating
| plainly: nothing in the baseline was broken, and nothing was quietly deleted to
| turn the suite green.
|
| ## The one baseline test that had to change rather than be deleted
|
| `ApiKernelTest::test_unauthenticated_request_renders_401_envelope_without_redirecting_to_login`
| asserted, as its *precondition*, that `Route::has('login')` was true - "Expected
| Fortify to have registered a login route, otherwise this test proves nothing."
| Deleting Fortify falsifies that precondition and would have turned a real
| regression into a red test. It was **rewritten**, not retired: the assertion
| that matters is that an `api/*` 401 carries no `Location` header, and that
| property is now provable a second way, because there is no longer a named route
| for a redirect to name.
|
| ## Why the gaps below are asserted against the DDL and not against the route table
|
| A capability the schema represents but the API does not expose is a **gap**,
| not a licence to pretend. The honest way to keep one visible is to assert that
| the DDL still makes it possible: `users.kata_sandi_hash` exists and
| `user_otp.tujuan` still names `reset_kata_sandi` and `verifikasi_email`, so a
| later todo can implement password reset or an email change without a schema
| change. Asserting instead that no such endpoint is registered would be a test
| that *fails* the day someone fixes the gap, which is a test that punishes the
| fix.
|
| ## `RefreshDatabase` is inherited, and several of these tests need no database
|
| `tests/Pest.php` binds the trait to everything under `tests/Feature`, so the
| route-table and filesystem assertions below pay for a transaction they do not
| use. They are kept in this file rather than moved to `tests/Unit` because
| `tests/Unit` does not boot the HTTP kernel, and "the middleware actually
| gathered on the route" is only answerable with the kernel running.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * The route names that existed at the baseline, and that must not exist now.
 *
 * The application and Fortify families were read off the deleted Wayfinder
 * modules - `resources/js/pages/settings/profile.tsx` imported
 * `{ edit } from '@/routes/profile'` and `{ send } from '@/routes/verification'`,
 * `resources/js/pages/auth/confirm-password.tsx` imported
 * `{ store } from '@/routes/password/confirm'`, `use-two-factor-auth.ts` imported
 * `{ qrCode, recoveryCodes, secretKey } from '@/routes/two-factor'`, and so on -
 * because that generated tree named every route this project actually had, where
 * the package's own `routes.php` would only have named the ones it registered.
 *
 * @return list<string>
 */
function scaffoldRouteNamesToAssert(): array
{
    return [
        // The three named by routes/web.php and routes/settings.php.
        'home',
        'dashboard',
        'profile.edit',
        'profile.update',
        'profile.destroy',
        'security.edit',
        'user-password.update',
        'appearance.edit',
        'well-known.passkeys',

        // Fortify's own names. `config/fortify.php` enabled registration,
        // resetPasswords, emailVerification, twoFactorAuthentication and
        // passkeys, so every one of these was a live named route.
        'login',
        'logout',
        'register',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'password.confirm',
        'password.confirm.store',
        'verification.notice',
        'verification.verify',
        'verification.send',
        'two-factor.login',
        'two-factor.login.store',
        'two-factor.challenge',
        'two-factor.enable',
        'two-factor.disable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.recovery-codes.generate',
        'user-password.confirm',
    ];
}

/**
 * The API route names that must still be registered.
 *
 * @return list<string>
 */
function scaffoldApiRouteNamesToAssert(): array
{
    return [
        // The SPA's own, from routes/api.php. Asserted as present, not absent: a
        // removal of the API surface has to fail here too, so this file does not
        // quietly become a statement only about deletions.
        'auth.register',
        'auth.login',
        'auth.otp.verify',
        'auth.refresh',
        'auth.logout',
        'auth.devices.index',
        'auth.devices.store',
        'auth.devices.destroy',
        'me',
        'dokter.index',
        'dokter.show',
        'pasien.profil.update',
    ];
}

/**
 * The literal paths the retired Feature test files addressed.
 *
 * Read out of the deleted files themselves. A path is a weaker assertion than a
 * route name, because the catch-all answers every one of them, so each entry is
 * paired with the assertion that the resolved route is the catch-all and not a
 * named one.
 *
 * @return array<string, list<string>>
 */
function scaffoldRetiredPaths(): array
{
    return [
        // AuthenticationTest: `$this->get('/login')`, `$this->post('/login', ...)`,
        // `$this->post('/logout')`.
        'AuthenticationTest' => ['/login', '/logout'],

        // RegistrationTest: `$this->get('/register')`, `$this->post('/register', ...)`.
        'RegistrationTest' => ['/register'],

        // PasswordResetTest: `/forgot-password` and `/reset-password/{token}`.
        'PasswordResetTest' => ['/forgot-password', '/reset-password/abc123'],

        // Settings\PasswordUpdateTest: `->put('/settings/password', ...)`.
        'PasswordUpdateTest' => ['/settings/password'],

        // Settings\ProfileUpdateTest: `->get('/settings/profile')`,
        // `->patch('/settings/profile', ...)`, `->delete('/settings/profile', ...)`.
        'ProfileUpdateTest' => ['/settings/profile'],

        // DashboardTest: `$this->get('/dashboard')`.
        'DashboardTest' => ['/dashboard'],
    ];
}

/**
 * The parsed reference DDL, through the project's own parser.
 *
 * `SqlSchemaParser` is the class `sehatly:verify-schema` uses, so an assertion
 * here cannot disagree with the parity verifier about what the schema says.
 */
function scaffoldDdl(): SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

/**
 * A valid `POST /api/v1/auth/register` body with overrides merged in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scaffoldRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'nama_lengkap' => 'Siti Rahayu',
        'no_telepon' => '081298765432',
        'email' => 'siti.rahayu@example.test',
        'password' => 'kata-sandi-yang-kuat-123',
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1994-11-02',
        'tempat_lahir' => 'Surabaya',
        'alamat_lengkap' => 'Jl. Pahlawan No. 9, Surabaya, Jawa Timur 60171',
        'bahasa' => 'id',
        'persetujuan_syarat_ketentuan' => true,
        'persetujuan_kebijakan_privasi' => true,
    ], $overrides);
}

/**
 * Register a patient, verify the OTP and return the issued access token.
 *
 * Deliberately the shortest real path through the API rather than a factory plus
 * a hand-made token: the point of the two tests below is that the behaviour the
 * scaffold asserted is still reachable, and reaching it the way a client does is
 * the only way to know that.
 */
function scaffoldRegisterAndVerify(): string
{
    test()->seed(RbacSeeder::class);

    // Required, not cosmetic: `register()` grants the `pasien` role through
    // `RoleAssigner`, which throws a LogicException when the catalogue names a role
    // row the `roles` table does not have.
    app()->instance(OtpSender::class, new FakeOtpSender);

    test()->postJson('/api/v1/auth/register', scaffoldRegisterPayload())->assertCreated();

    $kode = app(OtpSender::class)->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON);

    expect($kode)->toMatch('/^[0-9]{6}$/');

    $response = test()->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => '081298765432',
        'kode' => $kode,
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    $response->assertOk();

    $access = (string) $response->json('data.token.access_token');

    expect($access)->not->toBe('');

    return $access;
}

// =====================================================================
// The scaffold is gone
// =====================================================================

test('no scaffold route name survives, the login redirect target is gone, and the API names are untouched', function (): void {
    $survivors = array_values(array_filter(
        scaffoldRouteNamesToAssert(),
        static fn (string $name): bool => Route::has($name),
    ));

    expect($survivors)->toBe([]);

    // Spelled out because it is the precondition `ApiKernelTest` used to assert
    // in order to make its 401 assertion meaningful. It is now the thing itself.
    expect(Route::has('login'))->toBeFalse();

    // The other half of the same list: a removal that took the API surface with it
    // would satisfy every assertion above, so the replacement surface is pinned
    // here rather than assumed.
    $missing = array_values(array_filter(
        scaffoldApiRouteNamesToAssert(),
        static fn (string $name): bool => ! Route::has($name),
    ));

    expect($missing)->toBe([]);
});

test('no route action is an Inertia controller, and the only application web routes are the shell and its assets', function (): void {
    $inertiaActions = [];
    $nonApi = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $action = (string) $route->getActionName();

        if (str_contains(strtolower($action), 'inertia')) {
            $inertiaActions[] = $route->uri().' => '.$action;
        }

        $uri = $route->uri();

        // `sanctum/csrf-cookie` and `storage/{path}` are registered by the
        // framework and `up` by `bootstrap/app.php`'s `health:` argument, so none
        // of the three is an application web route.
        $isApplicationWebRoute = ! str_starts_with($uri, 'api/')
            && ! str_starts_with($uri, 'sanctum/')
            && ! str_starts_with($uri, 'storage/')
            && $uri !== 'up';

        if ($isApplicationWebRoute) {
            // A closure route reports the action name `Closure`, which proves
            // nothing about where it was declared, so the declaring file is read
            // off the closure itself and normalised, because PHP reports Windows
            // paths with backslashes and `base_path()` with forward ones.
            $declaredIn = 'unknown';

            if (($uses = $route->getAction('uses')) instanceof Closure) {
                $declaredIn = str_replace('\\', '/', (string) (new ReflectionFunction($uses))->getFileName());
            }

            $nonApi[$route->uri()] = $declaredIn;
        }
    }

    expect($inertiaActions)->toBe([]);

    // Two, and only two. The plan's todo 30 asks for "the SPA shell route plus
    // /up"; `/up` comes from `bootstrap/app.php` rather than from a line in
    // `routes/web.php`, so what is asserted here is the shell and the asset route
    // that lets the shell's absolute `/assets/...` URLs resolve. `base_path()` is
    // normalised because PHP reports a reflected file name with backslashes on
    // Windows and `base_path()` with forward ones.
    expect($nonApi)->toBe([
        'assets/{path}' => str_replace('\\', '/', base_path('routes/web.php')),
        '{any?}' => str_replace('\\', '/', base_path('routes/web.php')),
    ]);

    // The shell answers every verb, because a client-side router has to be able
    // to re-issue a deep link as a form POST or a mutation without the server
    // having to know which client route it maps to. `Route::any()` is what does
    // that, and the first method in the list is `GET`, so `methods()[0]` alone
    // would understate it.
    $shell = Route::getRoutes()->match(Request::create('/dashboard', 'GET'));

    expect($shell->methods())->toEqualCanonicalizing([
        'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS',
    ]);
});

test('no gathered middleware on any route is an Inertia or Fortify class', function (): void {
    $offenders = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_contains($middleware, 'Inertia') || str_contains($middleware, 'Fortify')) {
                $offenders[] = $route->uri().' => '.$middleware;
            }
        }
    }

    // This is the brief's "no Inertia middleware remains on an API route",
    // asserted over the whole route table rather than over `api/*` alone, so a
    // later route cannot reintroduce one.
    expect($offenders)->toBe([]);
});

test('the Inertia and Fortify classes and configs are gone from disk and from the autoloader', function (): void {
    $absent = [
        'app/Http/Middleware/HandleInertiaRequests.php',
        'app/Http/Middleware/HandleAppearance.php',
        'app/Providers/FortifyServiceProvider.php',
        'config/inertia.php',
        'config/fortify.php',
        'routes/settings.php',
        'app/Http/Controllers/Settings',
        'app/Http/Requests/Settings',
        'app/Actions',
        'app/Concerns',
        'resources/js',
    ];

    $survivors = array_values(array_filter(
        $absent,
        static fn (string $path): bool => file_exists(base_path($path)),
    ));

    expect($survivors)->toBe([]);

    // Through the autoloader rather than the filesystem, so a leftover file under
    // a different path still cannot hide a class that is still loadable.
    expect(class_exists('App\Http\Middleware\HandleInertiaRequests'))->toBeFalse();
    expect(class_exists('App\Http\Middleware\HandleAppearance'))->toBeFalse();
    expect(class_exists('Inertia\Middleware'))->toBeFalse();
    expect(class_exists('Laravel\Fortify\Fortify'))->toBeFalse();

    // The class that extended `Inertia\Middleware` is gone, and so is the package
    // manifest that used to register it. `package:discover` writes this file, and
    // a stale one names a provider that no longer exists, which is a hard failure
    // on every artisan command until it is regenerated.
    $discovered = (string) file_get_contents(base_path('bootstrap/cache/packages.php'));

    expect($discovered)->not->toMatch('/inertia|fortify|passkeys|wayfinder/i');
});

test('the Blade root template is no longer an Inertia template and emits no Vite tag', function (): void {
    $blade = (string) file_get_contents(base_path('resources/views/app.blade.php'));

    // Comments are stripped before the assertion, because the file explains in a
    // Blade comment exactly which tags used to be there, and asserting against the
    // raw text would fail on its own documentation.
    $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);

    // Every one of these was in the file before todo 30: the Inertia app and head
    // Blade components, `@viteReactRefresh`, and a `@vite()` call whose second
    // argument was the per-page dynamic import
    // `"resources/js/pages/{$page['component']}.tsx"`.
    expect($markup)->not->toContain('inertia')
        ->and($markup)->not->toContain('@vite')
        ->and($markup)->not->toContain('$page')
        ->and($markup)->not->toContain('x-');

    // The mount point matches the SPA's own `web/index.html`, which renders into
    // `#root` and not into `#app`.
    expect($markup)->toContain('id="root"');
});

test('neither manifest declares an Inertia, Fortify, passkey or Wayfinder dependency', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer)->toBeArray();

    $composerPackages = array_map(
        'strtolower',
        array_keys($composer['require'] ?? []),
    );

    expect($composerPackages)->each->not->toMatch('/inertia|fortify|passkeys|wayfinder/');

    $package = json_decode((string) file_get_contents(base_path('package.json')), true);

    expect($package)->toBeArray();

    $npmPackages = array_map(
        'strtolower',
        array_keys($package['dependencies'] ?? []) + array_keys($package['devDependencies'] ?? []),
    );

    expect($npmPackages)->each->not->toMatch('/inertia|fortify|passkeys|wayfinder/');
});

test('the SPA in web/ is independent of the removed root bundle', function (): void {
    // Todo 30 must not break the SPA, and the only way to keep proving that is to
    // assert the property rather than assume it: `web/` has its own Vite project,
    // its own entry and its own dependency list, and none of them named the root
    // Inertia bundle.
    $package = json_decode((string) file_get_contents(base_path('web/package.json')), true);

    expect($package)->toBeArray();

    $dependencies = array_map(
        'strtolower',
        array_keys($package['dependencies'] ?? []) + array_keys($package['devDependencies'] ?? []),
    );

    expect($dependencies)->each->not->toMatch('/inertia|fortify|passkeys|wayfinder/');

    // Its entry point and its dev proxy are the two things the removed root
    // project used to own, and both live in `web/`.
    $main = (string) file_get_contents(base_path('web/src/main.tsx'));
    $vite = (string) file_get_contents(base_path('web/vite.config.ts'));

    expect($main)->toContain("getElementById('root')")
        ->and($main)->toContain('createRoot(')
        ->and($vite)->toContain('proxy')
        ->and($vite)->toContain("'/api'");

    // And nothing under `web/src` imports Inertia, which is the concrete meaning
    // of "the removal did not reach into the client".
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('web/src'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), '@inertiajs')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

test('every path the retired test files addressed now resolves to the shell and to nothing else', function (): void {
    foreach (scaffoldRetiredPaths() as $file => $paths) {
        foreach ($paths as $path) {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));

            // A path is a weaker signal than a route name, because the catch-all
            // answers all of them. What matters is that the route answering is the
            // catch-all and not a named one, so the assertion is on the matched
            // URI rather than on the status.
            expect($route->uri())->toBe('{any?}', "{$file}'s {$path} is still answered by a named route.");
        }
    }
});

// =====================================================================
// The SPA shell, and the negative lookahead that protects the API
// =====================================================================

test('a deep link is answered with the built SPA shell, or a 503 naming the build command', function (): void {
    $index = base_path('web/dist/index.html');

    $response = $this->get('/dashboard/anything');

    if (is_file($index)) {
        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('text/html');

        // `BinaryFileResponse` does not buffer the file into the response body, so
        // `getContent()` is empty by design and the file the response will stream
        // is asserted instead. Reading that file and comparing it to the one on
        // disk is what makes this "the SPA" rather than "a page that mentions the
        // SPA".
        expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);

        $served = $response->baseResponse->getFile()->getPathname();

        expect(str_replace('\\', '/', $served))->toBe(str_replace('\\', '/', $index))
            ->and((string) file_get_contents($served))->toBe((string) file_get_contents($index))
            ->and((string) file_get_contents($served))->toContain('id="root"')
            ->and((string) file_get_contents($served))->toContain('/assets/index-');
    } else {
        $response->assertStatus(503);
        $response->assertSee('npm run build', false);
    }
});

test('the asset route streams the built file and declares a type the browser will accept', function (): void {
    // ## Why this test exists at all
    //
    // The asset route shipped with two faults and neither was caught, because
    // nothing ever requested it: the shell test above exercises the catch-all,
    // and every other assertion here reads the route *table*. Both faults are
    // invisible until a browser loads the shell, and both are silent in
    // production terms -- the page is served, the status is a clean 200 or a
    // 500 in a log nobody reads, and the only symptom is a white rectangle.
    //
    // 1. The closure declared `: Illuminate\Http\Response` while
    //    `response()->file()` returns a `BinaryFileResponse`, so every asset
    //    request was a `TypeError` and a 500. The shell therefore loaded and
    //    `#root` stayed empty.
    // 2. `BinaryFileResponse` guesses the type from the file's bytes via
    //    `finfo`, and libmagic on a stock Windows host reports a stylesheet as
    //    `text/plain`. Laravel sends `X-Content-Type-Options: nosniff`, and a
    //    browser told not to sniff refuses a stylesheet that is not `text/css`,
    //    so the CSS was fetched and then thrown away.
    //
    // The fixtures are created when they are missing rather than skipped when
    // they are, because a guard that only runs on a machine that happens to have
    // run `npm run build` is not a guard -- it is the same silent pass that let
    // both faults through.

    $assets = base_path('web/dist/assets');
    $fixtures = [
        'guard.css' => 'body{color:red}',
        'guard.js' => 'console.log(1)',
    ];

    $created = [];

    if (! is_dir($assets)) {
        mkdir($assets, 0777, true);
        $created[] = $assets;
    }

    foreach ($fixtures as $name => $body) {
        if (! is_file($assets.'/'.$name)) {
            file_put_contents($assets.'/'.$name, $body);
            $created[] = $assets.'/'.$name;
        }
    }

    try {
        foreach (['guard.css' => 'text/css', 'guard.js' => 'text/javascript'] as $name => $type) {
            $response = $this->get('/assets/'.$name);

            $response->assertOk();

            // `BinaryFileResponse` streams the file rather than buffering it into
            // the response body, so the bytes that will reach the client are read
            // off the file the response names, and the type is asserted from the
            // header because under `nosniff` that header *is* the decision. The
            // match is on the media type alone: Symfony appends `; charset=utf-8`
            // to every `text/*` response on the way out, and pinning the suffix
            // would make this a test of that behaviour rather than of the route.
            expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class)
                ->and($response->headers->get('Content-Type'))->toStartWith($type)
                ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
                ->and((string) file_get_contents($response->baseResponse->getFile()->getPathname()))
                ->toBe($fixtures[$name]);
        }
    } finally {
        // Only what this test brought into existence is taken away; a real build's
        // output is left alone.
        foreach ($created as $path) {
            is_file($path) ? unlink($path) : rmdir($path);
        }
    }
});

test('the asset route still refuses a traversal and an extension it does not name', function (): void {
    // The narrowness the route's own docblock claims, asserted rather than assumed:
    // one segment, no `..`, no separator, and a file that has to actually exist.
    foreach ([
        '/assets/../index.html',
        '/assets/nested/child.css',
        '/assets/guard-does-not-exist.css',
    ] as $path) {
        $this->get($path)->assertNotFound();
    }
});

test('the catch-all negative lookahead keeps an unknown API path on the JSON 404 envelope', function (): void {
    // The plan's mandated failure scenario for this todo: a catch-all is a
    // footgun that answers every path with HTML, and `api/*` is the one prefix
    // where an HTML body is a bug.
    $response = $this->get('/api/v1/nope');

    $response->assertStatus(404);
    $response->assertHeader('Content-Type', 'application/json');
    $response->assertDontSee('<!DOCTYPE html', false);
    $response->assertExactJson([
        'success' => false,
        'message' => 'Resource not found.',
        'errors' => [],
    ]);
});

test('the shell answers the paths it is supposed to answer and no others', function (): void {
    // The `/up` health check, `sanctum/csrf-cookie` and the published `storage/`
    // directory each have their own route and must not be shadowed.
    $expected = [
        '/up' => 'up',
        '/sanctum/csrf-cookie' => 'sanctum/csrf-cookie',
        '/storage/x' => 'storage/{path}',
    ];

    foreach ($expected as $path => $uri) {
        expect(Route::getRoutes()->match(Request::create($path, 'GET'))->uri())->toBe($uri);
    }

    // `build/` has no route at all, which is the point: the catch-all's lookahead
    // excludes it, so an unmatched asset URL is a 404 rather than the HTML shell.
    $unmatched = false;

    try {
        Route::getRoutes()->match(Request::create('/build/manifest.json', 'GET'));
    } catch (NotFoundHttpException) {
        $unmatched = true;
    }

    expect($unmatched)->toBeTrue();
});

// =====================================================================
// The behaviour the retired tests stood in for, on the correct layer
// =====================================================================

test('a new user can register and authenticate, which is what RegistrationTest and AuthenticationTest asserted', function (): void {
    $access = scaffoldRegisterAndVerify();

    $user = User::query()->where('no_telepon', '081298765432')->firstOrFail();

    // The DDL has no `password` column, so the digest lives in `kata_sandi_hash`
    // and the scaffold's `User::factory()->create(['password' => ...])` shape had
    // nothing to write to.
    expect(Hash::check('kata-sandi-yang-kuat-123', (string) $user->kata_sandi_hash))->toBeTrue()
        ->and($user->nama_lengkap)->toBe('Siti Rahayu')
        ->and($user->tipe)->toBe('pasien')
        ->and($user->status)->toBe('aktif');

    app('auth')->forgetGuards();

    // The authenticated "am I signed in" call that the scaffold's
    // `assertAuthenticated()` stood in for.
    $this->withToken($access)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.no_telepon', '081298765432');
});

test('an account holder can change their own display name, which is what ProfileUpdateTest asserted', function (): void {
    $access = scaffoldRegisterAndVerify();

    app('auth')->forgetGuards();

    $this->withToken($access)
        ->putJson('/api/v1/pasien/profil', [
            'nama_lengkap' => 'Siti Rahayu Pertiwi',
            'pekerjaan' => 'Guru',
        ])
        ->assertOk()
        ->assertJsonPath('data.profile.nama_lengkap', 'Siti Rahayu Pertiwi');

    expect(User::query()->where('no_telepon', '081298765432')->value('nama_lengkap'))
        ->toBe('Siti Rahayu Pertiwi');
});

// =====================================================================
// The gaps, asserted against the DDL that still makes them possible
// =====================================================================

test('the DDL still represents every capability the retired tests asserted and the API does not expose', function (): void {
    $ddl = scaffoldDdl();

    $users = $ddl->table('users');
    $otp = $ddl->table('user_otp');

    expect($users)->not->toBeNull()
        ->and($otp)->not->toBeNull();

    // 1. Password reset and password change. The scaffold could do both, through
    //    `password_reset_tokens` and `users.password`; neither column exists. What
    //    does exist is the column to write and the OTP purpose to verify with, so
    //    the capability was designed for and is not built, rather than impossible.
    expect($users->columns)->toHaveKey('kata_sandi_hash')
        ->and($users->columns)->not->toHaveKey('password')
        ->and($ddl->hasTable('password_reset_tokens'))->toBeFalse();

    // 2. Email change and email verification. `email` is `NULL UNIQUE` and mutable,
    //    and `user_otp.tujuan` names `verifikasi_email`, but no endpoint issues an
    //    email-purpose OTP, so `users.email_terverifikasi` can never be set true
    //    through the API. The scaffold's `MustVerifyEmail` contract is the same gap
    //    in a different spelling.
    expect((string) $otp->columns['tujuan']->type)->toContain('verifikasi_email')
        ->and((string) $otp->columns['tujuan']->type)->toContain('reset_kata_sandi')
        ->and($users->columns)->toHaveKey('email')
        ->and($users->columns)->toHaveKey('email_terverifikasi')
        ->and($users->columns)->not->toHaveKey('email_verified_at')
        ->and($users->columns)->not->toHaveKey('remember_token');

    // 3. Self-service account deletion. `users` is the only soft-deletable account
    //    table and it carries `dihapus_at`; no endpoint deletes it.
    expect($users->columns)->toHaveKey('dihapus_at')
        ->and((new User)->getDeletedAtColumn())->toBe('dihapus_at');

    // 4. Of the four `user_otp.tujuan` values, only the two Module 1 issues are
    //    reachable through any route. `TUJUAN_DI_TERBITKAN` is the assertion that
    //    keeps the other two from drifting into a purpose `otp/verify` accepts -
    //    `AuthFlowTest` pins the same list against the DDL.
    expect(OtpService::TUJUAN)->toHaveCount(4)
        ->and(OtpService::TUJUAN_DI_TERBITKAN)->toBe(['verifikasi_telepon', 'login']);

    // 5. The whole column list, in DDL order, so a schema change that added a
    //    `password` or an `email_verified_at` back would have to be a deliberate
    //    edit here rather than a silent pass.
    expect(array_keys($users->columns))->toBe([
        'id',
        'uuid',
        'nama_lengkap',
        'email',
        'no_telepon',
        'kata_sandi_hash',
        'tipe',
        'status',
        'foto_profil',
        'bahasa',
        'telepon_terverifikasi',
        'email_terverifikasi',
        'last_login_at',
        'dibuat_at',
        'diubah_at',
        'dihapus_at',
    ]);

    // 6. And the four families the scaffold's tests reached for are absent, which
    //    is the whole reason the suite was red and the reason removing the
    //    package rather than the columns was the only honest move.
    expect(array_values(array_filter(
        array_keys($users->columns),
        static fn (string $column): bool => Str::contains($column, ['password', 'verified_at', 'two_factor', 'remember']),
    )))->toBe([]);
});
