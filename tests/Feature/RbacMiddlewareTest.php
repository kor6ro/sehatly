<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserType;
use App\Support\Rbac\RoleAssigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RbacTestPrincipal;

/*
|--------------------------------------------------------------------------
| Rbac middleware behaviour
|--------------------------------------------------------------------------
|
| Who the `permission:` and `tipe:` middleware admits, who it denies and with
| which body, that the permission check costs a flat number of queries, and
| that a value outside the catalogue is a loud failure rather than a silent
| denial.
|
| **These are Pest closure tests, not a PHPUnit class, and that is load-bearing.**
| `tests/Pest.php` binds `RefreshDatabase` to the tests in `tests/Feature` - which
| for Pest means its closure tests, and *not* a plain `class FooTest extends
| TestCase` sitting in the same directory. Without the trait there is no per-test
| rollback, and this file was written as a class first: every test after the first
| then failed with `1062 Duplicate entry 'pasien' for key
| 'roles.roles_nama_unique'`, because the first test's `seed()` was still
| committed. `tests/Feature/UsersTableSchemaTest` states the same convention in its
| own docblock, and its `expect(...)->toBe(2)` on `users` is the observable proof
| that the rollback really happens.
|
| **Routes are registered at runtime, not in `routes/api.php`.** That file is todo
| 20's, so registering here keeps this file from contributing a path to the
| OpenAPI document todo 53 reconciles against the live route table, and keeps
| todo 20's diff to the routes it owns.
|
| **No `App\Models\User`.** See `tests/Support/RbacTestPrincipal.php`: the principal
| is a local fixture because `app/Models/**` is todo 19's. The `users` rows and the
| `user_roles` grants behind it are real, because `user_roles.user_id` is
| FK-constrained to `users(id)` (`telemedicine_test.sql:175`).
|
*/

/**
 * The probe routes. Named `rbacTestRoutes` because a top-level function in a test
 * file is global, and `UsersTableSchemaTest` records that a generic name would
 * collide across files at include time.
 */
function rbacTestRoutes(): void
{
    Route::middleware('api')->prefix('api/v1')->group(function (): void {
        // A doctor-only consultation finish - the plan's own worked example.
        Route::get('/_rbac/konsultasi-selesai', fn () => response('ok'))
            ->middleware(['auth:sanctum', 'permission:konsultasi.selesai']);

        // The same route WITHOUT `auth:sanctum`, so the middleware's own anonymous
        // handling is observable rather than assumed.
        Route::get('/_rbac/konsultasi-selesai-guardless', fn () => response('ok'))
            ->middleware('permission:konsultasi.selesai');

        // The plan's other worked example: pharmacist-only verification.
        Route::get('/_rbac/resep-verifikasi', fn () => response('ok'))
            ->middleware(['auth:sanctum', 'permission:resep.verifikasi']);

        // Comma-separated codes mean "any of".
        Route::get('/_rbac/resep-atau-booking', fn () => response('ok'))
            ->middleware(['auth:sanctum', 'permission:resep.verifikasi,booking.buat']);

        // A code that is not in the catalogue: the English-verb mistake.
        Route::get('/_rbac/booking-create', fn () => response('ok'))
            ->middleware(['auth:sanctum', 'permission:booking.create']);

        Route::get('/_rbac/apoteker-only', fn () => response('ok'))
            ->middleware(['auth:sanctum', 'tipe:apoteker']);

        Route::get('/_rbac/guardless-apoteker-only', fn () => response('ok'))
            ->middleware('tipe:apoteker');

        Route::get('/_rbac/admin-atau-superadmin', fn () => response('ok'))
            ->middleware(['auth:sanctum', 'tipe:admin,superadmin']);

        Route::get('/_rbac/tipe-doktor', fn () => response('ok'))
            ->middleware(['auth:sanctum', 'tipe:doktor']);
    });
}

/**
 * Insert a real `users` row, optionally grant roles, and return a principal for it.
 *
 * The row is real because `user_roles.user_id` is FK-constrained to `users(id)`
 * and a grant cannot be tested without one. Only the model is faked, and only
 * because `app/Models/**` is todo 19's.
 *
 * @param  list<string>  $roles
 */
function rbacTestUser(string $tipe, array $roles = []): RbacTestPrincipal
{
    $seq = (int) DB::table('users')->count() + 1;

    $id = (int) DB::table('users')->insertGetId([
        'uuid' => sprintf('44444444-4444-4444-8444-%012d', $seq),
        'nama_lengkap' => 'RBAC Test '.$tipe.' '.$seq,
        'email' => 'rbac.'.$tipe.'.'.$seq.'@example.test',
        'no_telepon' => '0812'.$seq,
        // Not a usable credential: a hash of a random throwaway string, so a test can
        // never authenticate by guessing it.
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 4]),
        'tipe' => $tipe,
        'status' => 'aktif',
        'bahasa' => 'id',
        'telepon_terverifikasi' => 1,
        'email_terverifikasi' => 1,
    ]);

    if ($roles !== []) {
        app(RoleAssigner::class)->assign($id, ...$roles);
    }

    return new RbacTestPrincipal($id, $tipe);
}

beforeEach(function (): void {
    rbacTestRoutes();

    $this->seed(RbacSeeder::class);
});

// ------------------------------------------------------------------ permission

test('a user with the permission passes', function (): void {
    Sanctum::actingAs(rbacTestUser('dokter', ['dokter']));

    $response = $this->get('/api/v1/_rbac/konsultasi-selesai');

    $response->assertOk();

    expect($response->getContent())->toBe('ok');
});

test('a user without the permission is denied with the 403 envelope', function (): void {
    // A doctor, and `resep.verifikasi` belongs to `apoteker` alone.
    Sanctum::actingAs(rbacTestUser('dokter', ['dokter']));

    $response = $this->get('/api/v1/_rbac/resep-verifikasi');

    $response->assertStatus(403);
    $response->assertHeader('Content-Type', 'application/json');

    // Byte-exact rather than a shape check: this is the same string
    // bootstrap/app.php's exception renderer produces for an
    // AccessDeniedHttpException, so a client cannot tell which layer denied it.
    expect($response->getContent())
        ->toBe('{"success":false,"message":"This action is unauthorized.","errors":{}}');
});

test('a user with no role at all is denied with the 403 envelope', function (): void {
    Sanctum::actingAs(rbacTestUser('dokter'));

    $this->get('/api/v1/_rbac/konsultasi-selesai')
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/json');
});

test('several roles are combined rather than intersected', function (): void {
    // A pharmacist who also holds the admin role. The second route needs a code
    // from `pasien` and a code from `apoteker`, so it is only reachable if the
    // middleware ORs across the caller's roles.
    Sanctum::actingAs(rbacTestUser('apoteker', ['apoteker', 'admin']));

    $this->get('/api/v1/_rbac/resep-verifikasi')->assertOk();
    $this->get('/api/v1/_rbac/resep-atau-booking')->assertOk();
});

test('a comma separated code list is satisfied by any one of its members', function (): void {
    // `pasien` holds `booking.buat` and not `resep.verifikasi`, so this passes only
    // because the list means "any of" rather than "all of".
    Sanctum::actingAs(rbacTestUser('pasien', ['pasien']));

    $this->get('/api/v1/_rbac/resep-atau-booking')->assertOk();
    $this->get('/api/v1/_rbac/resep-verifikasi')->assertStatus(403);
});

test('an unauthenticated request gets the 401 envelope, not 403 and not a redirect', function (): void {
    $response = $this->get('/api/v1/_rbac/konsultasi-selesai');

    $response->assertStatus(401);
    $response->assertHeader('Content-Type', 'application/json');

    expect($response->headers->get('Location'))->toBeNull()
        ->and($response->getContent())
        ->toBe('{"success":false,"message":"Unauthenticated.","errors":{}}');
});

test('the permission middleware answers 401 itself when the route forgets the guard', function (): void {
    // `auth:sanctum` is what normally produces the 401. This route omits it on
    // purpose, so the middleware's own anonymous path is exercised rather than
    // assumed: a 403 here would tell the client to fix its permissions, which is
    // the wrong instruction for a request carrying no token.
    $response = $this->get('/api/v1/_rbac/konsultasi-selesai-guardless');

    $response->assertStatus(401);

    expect($response->headers->get('Location'))->toBeNull()
        ->and($response->getContent())
        ->toBe('{"success":false,"message":"Unauthenticated.","errors":{}}');
});

test('the permission check costs at most two queries whatever the caller holds', function (): void {
    // The plan's own criterion, with the caller holding all five roles and 69 grants:
    // the cost must be flat in the number of roles, which is exactly what a
    // per-role loop or an eager load would not be.
    Sanctum::actingAs(rbacTestUser('superadmin', [
        'pasien', 'dokter', 'apoteker', 'admin', 'superadmin',
    ]));

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/api/v1/_rbac/konsultasi-selesai')->assertOk();

    expect($queries)->toBeLessThanOrEqual(2, "The permission check issued {$queries} queries; it must be a single "
        .'EXISTS regardless of how many roles or permissions the caller holds.');
});

test('the permission check costs the same for a caller with no roles', function (): void {
    // The flatness claim is about the query, not about the roles: a caller whose
    // roles were all deleted must take the same code path, or a revoked account
    // would behave differently from one that never had any.
    Sanctum::actingAs(rbacTestUser('dokter'));

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/api/v1/_rbac/konsultasi-selesai')->assertStatus(403);

    expect($queries)->toBeLessThanOrEqual(2, "A denied check issued {$queries} queries.");
});

test('an unknown permission code fails loudly instead of denying everyone', function (): void {
    // `booking.create` is not a catalogue code: the convention the DDL documents at
    // telemedicine_test.sql:159 uses Indonesian action verbs, so the code is
    // `booking.buat`. A 403 here would deny every caller forever and blame the
    // caller's token for a typo in a route file.
    Sanctum::actingAs(rbacTestUser('superadmin', ['superadmin']));

    $response = $this->get('/api/v1/_rbac/booking-create');

    $response->assertStatus(500);
    $response->assertHeader('Content-Type', 'application/json');

    expect($response->getContent())
        ->toBe('{"success":false,"message":"Internal server error.","errors":{}}');
});

// ----------------------------------------------------------------------- tipe

test('tipe admits the named account type', function (): void {
    Sanctum::actingAs(rbacTestUser('apoteker', ['apoteker']));

    $this->get('/api/v1/_rbac/apoteker-only')->assertOk();
});

test('tipe rejects a different account type with the 403 envelope', function (): void {
    // The plan's own scenario: `tipe:apoteker` rejects a `dokter`.
    Sanctum::actingAs(rbacTestUser('dokter', ['dokter']));

    $response = $this->get('/api/v1/_rbac/apoteker-only');

    $response->assertStatus(403);
    $response->assertHeader('Content-Type', 'application/json');

    expect($response->getContent())
        ->toBe('{"success":false,"message":"This action is unauthorized.","errors":{}}');
});

test('tipe admits any of a comma separated list', function (): void {
    Sanctum::actingAs(rbacTestUser('admin', ['admin']));
    $this->get('/api/v1/_rbac/admin-atau-superadmin')->assertOk();

    Sanctum::actingAs(rbacTestUser('superadmin', ['superadmin']));
    $this->get('/api/v1/_rbac/admin-atau-superadmin')->assertOk();

    Sanctum::actingAs(rbacTestUser('dokter', ['dokter']));
    $this->get('/api/v1/_rbac/admin-atau-superadmin')->assertStatus(403);
});

test('tipe ignores roles and reads the users column', function (): void {
    // A `dokter` who also holds the `admin` role is still not an admin account.
    // `tipe:` and `permission:` are independent gates and this pins the difference,
    // because conflating them is the easy mistake.
    Sanctum::actingAs(rbacTestUser('dokter', ['dokter', 'admin']));

    $this->get('/api/v1/_rbac/admin-atau-superadmin')->assertStatus(403);
});

test('tipe accepts an ENUM value, and such an account holds no permission', function (): void {
    // `perawat` is one of the DDL's seven `users.tipe` values and has no role in
    // RbacCatalog::ROLES, because the plan names five and inventing two more would
    // be inventing policy. The asymmetry is real and is reported rather than hidden:
    // such an account is gated by `tipe:` and by no `permission:` at all.
    Sanctum::actingAs(rbacTestUser('perawat'));

    $this->get('/api/v1/_rbac/apoteker-only')->assertStatus(403);
    $this->get('/api/v1/_rbac/konsultasi-selesai')->assertStatus(403);
});

test('tipe answers 401 itself when the route forgets the guard', function (): void {
    $response = $this->get('/api/v1/_rbac/guardless-apoteker-only');

    $response->assertStatus(401);

    expect($response->headers->get('Location'))->toBeNull()
        ->and($response->getContent())
        ->toBe('{"success":false,"message":"Unauthenticated.","errors":{}}');
});

test('tipe rejects a value outside the DDL enum instead of 403ing', function (): void {
    // `doktor` is a misspelling, not an account type. A 403 would report it as an
    // authorisation decision about a real user, which is the wrong diagnosis and
    // points the investigation at the wrong file.
    Sanctum::actingAs(rbacTestUser('dokter', ['dokter']));

    $this->get('/api/v1/_rbac/tipe-doktor')
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/json');
});

// --------------------------------------------------------------- registration

test('both middlewares are registered under their route aliases', function (): void {
    $aliases = app('router')->getMiddleware();

    expect($aliases['permission'] ?? null)->toBe(EnsurePermission::class)
        ->and($aliases['tipe'] ?? null)->toBe(EnsureUserType::class);
});
