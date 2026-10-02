<?php

declare(strict_types=1);

use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\StoreDeviceRequest;
use App\Models\Pasien;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserOtp;
use App\Models\UserRefreshToken;
use App\Services\Auth\IssuedOtp;
use App\Services\Auth\LogOtpSender;
use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use App\Services\Auth\TokenService;
use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeOtpSender;

/*
|--------------------------------------------------------------------------
| Module 1 auth endpoints
|--------------------------------------------------------------------------
|
| Ten routes under `/api/v1/auth`, and every one of them is covered in both
| directions: a happy path, and the failure the endpoint exists to refuse.
|
| **These are Pest closure tests, not a PHPUnit class, and that is
| load-bearing.** `tests/Pest.php` binds `RefreshDatabase` to the tests in
| `tests/Feature` -- for Pest, its closure tests, and *not* a plain
| `class FooTest extends TestCase` sitting in the same directory. Without the
| trait there is no per-test rollback, the `RbacSeeder` in `beforeEach` is still
| committed when the second test runs, and every test after the first fails with
| `1062 Duplicate entry 'pasien' for key 'roles.roles_nama_unique'`. See
| `RbacMiddlewareTest` for the same note.
|
| **The real routes are driven, not probes.** `RbacMiddlewareTest` and
| `ApiKernelTest` register their routes at runtime so they cannot contribute a
| path to the OpenAPI document todo 53 reconciles against the live route table.
| This file is the opposite case: it is the surface todo 53 has to document, so
| it goes through `routes/api.php` over real HTTP. A probe would prove the
| controller works while proving nothing about the wiring, and the wiring is
| where the middleware, the rate limiters and the ownership scoping live.
|
| **The OTP is observed through the sender, never through the response.**
| `phpunit.xml` sets `APP_ENV=testing`, and `IssuedOtp::plainTextForClient()`
| returns `null` outside `local` -- so the response genuinely cannot be the
| source, and a test that used it would be testing a path no production request
| takes. `beforeEach` swaps the `OtpSender` binding for a `FakeOtpSender`, which
| is the channel the code really travels through; the "local only" rule is
| asserted directly on `IssuedOtp`.
|
| **Authentication uses real Sanctum bearer tokens** minted by
| `$user->createToken()`, never `Sanctum::actingAs()`. `actingAs` installs a
| `TransientToken`, which has no `delete()`, so a test using it could not observe
| the access-token revocation that `POST /auth/logout` performs.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * A complete, valid `POST /auth/register` payload, with overrides merged in.
 *
 * All eleven keys are supplied, not just the required ones, so a test that changes
 * one field knows the other ten still validate and the failure it observes is
 * the one it meant to cause.
 *
 * The two consent flags are the owner's mandatory consents (F01 decision #1),
 * both `accepted`; a test that wants the refusal removes or falsifies one.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function authRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'nama_lengkap' => 'Budi Santoso',
        'no_telepon' => authTestPhone(),
        'email' => 'budi.santoso@example.test',
        'password' => authTestPassword(),
        'jenis_kelamin' => 'L',
        'tanggal_lahir' => '1990-05-17',
        'tempat_lahir' => 'Bandung',
        'alamat_lengkap' => 'Jl. Merdeka No. 1, Bandung, Jawa Barat 40115',
        'bahasa' => 'id',
        'persetujuan_syarat_ketentuan' => true,
        'persetujuan_kebijakan_privasi' => true,
    ], $overrides);
}

/**
 * The phone number the shared payload uses, so no test has to restate it and a
 * change to the payload cannot desynchronise the two.
 */
function authTestPhone(): string
{
    return '081234567890';
}

/**
 * The password the shared payload uses, for the same reason.
 */
function authTestPassword(): string
{
    return 'kata-sandi-yang-kuat-123';
}

/**
 * The `FakeOtpSender` bound in `beforeEach`, read back out of the container.
 *
 * Read from the container rather than captured in a variable so a test cannot
 * observe a sender that is not the one the controller was actually handed -- which
 * would be a test that passes while the binding is wrong.
 */
function authSender(): FakeOtpSender
{
    $sender = app(OtpSender::class);

    expect($sender)->toBeInstanceOf(FakeOtpSender::class);

    return $sender;
}

/**
 * Register a patient and verify the registration OTP, returning both tokens.
 *
 * This is the shortest path to "a usable authenticated account", and it is a real
 * HTTP walk of the two endpoints rather than a factory plus a hand-made token:
 * the point of these tests is the flow, not the fixture.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{user: User, access: string, refresh: string}
 */
function authRegisterVerified(array $overrides = []): array
{
    $payload = authRegisterPayload($overrides);

    test()->postJson('/api/v1/auth/register', $payload)->assertCreated();

    $response = test()->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => $payload['no_telepon'],
        'kode' => authSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    $response->assertOk();

    return [
        'user' => User::query()->where('no_telepon', $payload['no_telepon'])->firstOrFail(),
        'access' => (string) $response->json('data.token.access_token'),
        'refresh' => (string) $response->json('data.token.refresh_token'),
    ];
}

/**
 * Log an existing account in through the password + OTP pair, returning both tokens.
 *
 * @return array{access: string, refresh: string}
 */
function authLoginVerified(User $user): array
{
    $identifier = (string) $user->no_telepon;

    test()->postJson('/api/v1/auth/login', [
        'no_telepon' => $identifier,
        'password' => authTestPassword(),
    ])->assertOk();

    $response = test()->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => $identifier,
        'kode' => authSender()->lastKodeFor(OtpService::TUJUAN_LOGIN),
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ]);

    $response->assertOk();

    return [
        'access' => (string) $response->json('data.token.access_token'),
        'refresh' => (string) $response->json('data.token.refresh_token'),
    ];
}

/**
 * Register a device for `$user`; the caller asserts on the table.
 */
function authRegisterDevice(User $user, string $deviceId, string $platform = 'web'): void
{
    authAsUser($user)
        ->postJson('/api/v1/auth/devices', [
            'device_id' => $deviceId,
            'platform' => $platform,
        ])
        ->assertCreated();
}

/**
 * Make the next request carry a real Sanctum bearer token for `$user`.
 *
 * ## Why this calls `forgetGuards()` and a bare `withToken()` is not enough
 *
 * `Illuminate\Auth\RequestGuard` caches the resolved principal in `$this->user` and its
 * `setRequest()` **does not clear that cache** -- the source is
 * `vendor/laravel/framework/src/Illuminate/Auth/RequestGuard.php:80`, which assigns
 * `$this->request` and nothing else. In production every request gets a fresh container
 * and therefore a fresh guard, so the cache cannot outlive a request. Inside one test
 * method the application, the `AuthManager` and the guard instance are shared by every
 * request, so the **first** authenticated request decides the principal for all the
 * rest.
 *
 * That is not hypothetical. It was observed as: a request authenticated as user A
 * created a `user_devices` row for user A even though the header carried user B's token,
 * and a logout deleted the *first* token minted in the test rather than the one it was
 * sent with. Both looked like missing ownership scoping in the controller and were
 * neither. Every helper that changes identity goes through here so the trap is handled
 * once, in one place, with the reason written down.
 *
 * @return TestCase the running test case
 */
function authAsUser(User $user): mixed
{
    return authAsToken(authAccessTokenFor($user));
}

/**
 * Make the next request carry an already-minted access token.
 *
 * Split from {@see authAsUser()} because most assertions are about a *specific* token --
 * the one the verify response returned, the one that has just been revoked -- and
 * minting a second token would test a different thing.
 *
 * @return TestCase the running test case
 */
function authAsToken(string $plainTextToken): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($plainTextToken);
}

/**
 * Make the next request carry no credentials at all.
 *
 * `withoutHeader()` rather than `flushHeaders()`: the second would also drop the
 * `Accept` and `X-Requested-With` headers `postJson()` sets, which is a different test
 * from the one being written.
 *
 * @return TestCase the running test case
 */
function authAsAnonymous(): mixed
{
    app('auth')->forgetGuards();

    return test()->withoutHeader('Authorization');
}

/**
 * Mint a real Sanctum access token and return its plaintext.
 *
 * Deliberately not `Sanctum::actingAs()`: see the file docblock. The expiry is one
 * hour, long enough for a test that issues a handful of requests against it.
 */
function authAccessTokenFor(User $user): string
{
    return $user->createToken('auth-flow-test', ['*'], now()->addHour())->plainTextToken;
}

/**
 * Read an ENUM column's value list out of the reference SQL, through the project's
 * own parser.
 *
 * A local copy of `RbacCatalogTest`'s `rbacEnumValuesFromDdl()` rather than a reuse
 * of it. A top-level function in one Pest file is defined when that file is included,
 * so calling the other file's copy would make this file's behaviour depend on Pest's
 * include order. The duplication is fifteen lines and it is the whole point: the
 * reference file is re-parsed with the same `SqlSchemaParser`
 * `sehatly:verify-schema` uses, so these assertions cannot disagree with the
 * verifier about what the DDL says.
 *
 * @return list<string>
 */
function authEnumValuesFromDdl(string $table, string $column): array
{
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    expect($spec->hasTable($table))->toBeTrue();

    $tableSpec = $spec->table($table);

    expect($tableSpec)->not->toBeNull();
    expect($tableSpec->columns)->toHaveKey($column);

    expect($tableSpec->columns[$column]->type)->toMatch("/^enum\((.*)\)$/");

    preg_match("/^enum\((.*)\)$/", $tableSpec->columns[$column]->type, $matches);

    $values = array_map(
        static fn (string $member): string => trim($member, "'"),
        explode(',', $matches[1]),
    );

    expect($values)->not->toContain('', "{$table}.{$column} has an empty ENUM member.");

    return $values;
}

beforeEach(function (): void {
    // Required, not cosmetic: `register()` grants the `pasien` role through
    // `RoleAssigner`, which resolves role names against the `roles` table and throws a
    // LogicException when the catalogue names a row that is not there. Without this
    // every register test would be a 500.
    $this->seed(RbacSeeder::class);

    $this->app->instance(OtpSender::class, new FakeOtpSender);
});

// =====================================================================
// POST /api/v1/auth/register
// =====================================================================

test('register creates the users row, the pasien row and the role grant, and sends an OTP', function (): void {
    $response = $this->postJson('/api/v1/auth/register', authRegisterPayload());

    $response->assertCreated();
    $response->assertJsonPath('success', true);
    $response->assertJsonStructure([
        'success',
        'data' => [
            'user' => ['id', 'uuid', 'nama_lengkap', 'no_telepon', 'tipe', 'status'],
            'otp' => ['tujuan', 'kedaluwarsa_at', 'ttl_detik', 'kode'],
        ],
        'message',
    ]);

    $user = User::query()->where('no_telepon', authTestPhone())->firstOrFail();

    expect($user->nama_lengkap)->toBe('Budi Santoso')
        ->and($user->tipe)->toBe('pasien')
        ->and($user->status)->toBe('pending_verifikasi')
        ->and((bool) $user->telepon_terverifikasi)->toBeFalse()
        ->and($user->email)->toBe('budi.santoso@example.test')
        // `users.uuid CHAR(36) NOT NULL UNIQUE`, minted by the model's HasUuid trait
        // because the column has no default.
        ->and(strlen((string) $user->uuid))->toBe(36);

    // The password is stored as a `password_hash` digest, never as the plaintext.
    expect($user->kata_sandi_hash)->not->toBe(authTestPassword())
        ->and(Hash::check(authTestPassword(), (string) $user->kata_sandi_hash))->toBeTrue();

    $pasien = Pasien::query()->where('user_id', $user->getKey())->firstOrFail();

    expect($pasien->jenis_kelamin)->toBe('L')
        ->and($pasien->tanggal_lahir->toDateString())->toBe('1990-05-17')
        ->and($pasien->alamat_lengkap)->toContain('Merdeka')
        ->and($pasien->tempat_lahir)->toBe('Bandung');

    // `pasien.nomor_rm VARCHAR(20) NULL UNIQUE`, format `RM-YYYYMM-XXXXXX` per :221.
    expect($pasien->nomor_rm)->toMatch('/^RM-[0-9]{6}-[0-9]{6}$/')
        ->and(strlen((string) $pasien->nomor_rm))->toBeLessThanOrEqual(20);

    // `RbacSeeder` writes no `user_roles` row on purpose and names todo 20 as the place
    // that assigns roles to real accounts.
    expect(app(RoleAssigner::class)->rolesFor((int) $user->getKey()))->toBe(['pasien']);

    $otp = UserOtp::query()->where('user_id', $user->getKey())->firstOrFail();

    expect($otp->tujuan)->toBe(OtpService::TUJUAN_VERIFIKASI_TELEPON)
        ->and((bool) $otp->sudah_dipakai)->toBeFalse()
        ->and($otp->kedaluwarsa_at->isFuture())->toBeTrue();

    expect(authSender()->countFor(OtpService::TUJUAN_VERIFIKASI_TELEPON))->toBe(1)
        ->and(authSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON))
        ->toMatch('/^[0-9]{6}$/');
});

test('register writes the two mandatory consent rows in the same transaction as the account', function (): void {
    // F01 decision #1, option (a): consent is taken in the registration request
    // and the ledger rows are written atomically with the account, so a `users`
    // row cannot exist without the consents that authorise it.
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $user = User::query()->where('no_telepon', authTestPhone())->firstOrFail();

    $rows = DB::table('persetujuan_pdp')
        ->where('user_id', $user->getKey())
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2);

    $jenis = $rows->pluck('jenis')->all();
    sort($jenis);

    expect($jenis)->toBe(['kebijakan_privasi', 'syarat_ketentuan']);

    foreach ($rows as $row) {
        // The active version comes from `config/pdp.php` through `PdpDokumen`,
        // never from the client, and `disetujui_at` is the server clock.
        expect((bool) $row->disetujui)->toBeTrue()
            ->and($row->versi_dokumen)->toBe(config('pdp.dokumen.'.$row->jenis.'.versi'))
            ->and($row->disetujui_at)->not->toBeNull()
            ->and($row->ip_address)->toBe('127.0.0.1');
    }
});

test('register refuses missing or declined consent and writes nothing at all', function (string $field, mixed $value): void {
    $payload = authRegisterPayload();

    if ($value === null) {
        unset($payload[$field]);
    } else {
        $payload[$field] = $value;
    }

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    expect($response->json('errors'))->toHaveKey($field);

    // The transaction never opened: no account, no patient row, no consent row,
    // no role grant and no OTP. The consent ledger is the point of the refusal.
    expect(User::query()->count())->toBe(0)
        ->and(Pasien::query()->count())->toBe(0)
        ->and(DB::table('persetujuan_pdp')->count())->toBe(0)
        ->and(DB::table('user_otp')->count())->toBe(0);
})->with([
    'syarat ketentuan absent' => ['persetujuan_syarat_ketentuan', null],
    'kebijakan privasi absent' => ['persetujuan_kebijakan_privasi', null],
    'syarat ketentuan false' => ['persetujuan_syarat_ketentuan', false],
    'kebijakan privasi false' => ['persetujuan_kebijakan_privasi', false],
]);

test('register never returns the plaintext OTP outside the local environment', function (): void {
    // `phpunit.xml` sets APP_ENV=testing, which is the point: the suite runs the
    // refusing branch, so a leak could not hide behind a local-only assertion.
    expect(app()->environment('local'))->toBeFalse();

    $response = $this->postJson('/api/v1/auth/register', authRegisterPayload());

    $kode = authSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON);

    expect($kode)->not->toBeNull();

    $response->assertCreated();
    expect($response->json('data.otp.kode'))->toBeNull()
        ->and($response->getContent())->not->toContain((string) $kode);
});

test('the plaintext OTP is returned only when the environment is local', function (): void {
    $issued = new IssuedOtp(1, OtpService::TUJUAN_LOGIN, '012345', now());

    expect($issued->plainTextForClient())->toBeNull();

    app()->detectEnvironment(fn (): string => 'local');

    try {
        expect($issued->plainTextForClient())->toBe('012345');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect($issued->plainTextForClient())->toBeNull();
});

test('register stores only the SHA-256 hash of the code, never the plaintext', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $kode = authSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON);
    $hash = UserOtp::query()->value('kode_hash');

    expect($kode)->not->toBeNull()
        ->and($hash)->not->toBe($kode)
        ->and($hash)->toBe(hash('sha256', (string) $kode))
        ->and(strlen((string) $hash))->toBe(64);
});

test('register issues no token, so nothing can be used before the OTP is verified', function (): void {
    $response = $this->postJson('/api/v1/auth/register', authRegisterPayload());

    $response->assertCreated();

    expect($response->json('data.token'))->toBeNull()
        ->and($response->getContent())->not->toContain('access_token')
        ->and($response->getContent())->not->toContain('refresh_token')
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(DB::table('user_refresh_tokens')->count())->toBe(0);
});

test('register ignores a client supplied tipe, status and verification flag', function (): void {
    // None of these is in `RegisterRequest`'s rules, so `validated()` drops them before
    // the controller sees them. A public registration endpoint that accepted `tipe`
    // would hand out `superadmin` accounts.
    $this->postJson('/api/v1/auth/register', authRegisterPayload([
        'tipe' => 'superadmin',
        'status' => 'aktif',
        'telepon_terverifikasi' => true,
        'email_terverifikasi' => true,
    ]))->assertCreated();

    $user = User::query()->where('no_telepon', authTestPhone())->firstOrFail();

    expect($user->tipe)->toBe('pasien')
        ->and($user->status)->toBe('pending_verifikasi')
        ->and((bool) $user->telepon_terverifikasi)->toBeFalse()
        ->and((bool) $user->email_terverifikasi)->toBeFalse()
        ->and(app(RoleAssigner::class)->rolesFor((int) $user->getKey()))->toBe(['pasien']);
});

test('the user resource never publishes the password hash', function (): void {
    $response = $this->postJson('/api/v1/auth/register', authRegisterPayload());

    $body = (string) $response->getContent();
    $hash = (string) User::query()->where('no_telepon', authTestPhone())->value('kata_sandi_hash');

    $response->assertCreated();
    expect($body)->not->toContain('kata_sandi')
        ->and($body)->not->toContain($hash);
});

test('register refuses each of the three NOT NULL pasien columns it has to collect', function (string $field): void {
    // `jenis_kelamin` (:225), `tanggal_lahir` (:226) and `alamat_lengkap` (:234) are
    // NOT NULL with no default, so a `pasien` row cannot be created without them and the
    // register form has to collect them. Under STRICT_TRANS_TABLES an insert that
    // omitted one is MySQL 1364, which is a 500 -- so collecting them is a 422 instead.
    $payload = authRegisterPayload();
    unset($payload[$field]);

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    expect($response->json('errors'))->toHaveKey($field);

    expect(User::query()->count())->toBe(0)
        ->and(Pasien::query()->count())->toBe(0);
})->with(['jenis_kelamin', 'tanggal_lahir', 'alamat_lengkap']);

test('register refuses a jenis kelamin outside the DDL enum', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload(['jenis_kelamin' => 'X']))
        ->assertStatus(422)
        ->assertJsonPath('errors.jenis_kelamin.0', 'The selected jenis kelamin is invalid.');

    expect(User::query()->count())->toBe(0);
});

test('register refuses a birth date in the future and a malformed one', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload([
        'tanggal_lahir' => now()->addDay()->toDateString(),
    ]))->assertStatus(422)->assertJsonPath(
        'errors.tanggal_lahir.0',
        'The tanggal lahir field must be a date before or equal to today.'
    );

    $this->postJson('/api/v1/auth/register', authRegisterPayload(['tanggal_lahir' => '17-05-1990']))
        ->assertStatus(422)->assertJsonPath(
            'errors.tanggal_lahir.0',
            'The tanggal lahir field must match the format Y-m-d.'
        );

    expect(User::query()->count())->toBe(0);
});

test('register refuses a duplicate phone number as a field error, not a 500', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $second = $this->postJson('/api/v1/auth/register', authRegisterPayload([
        'email' => 'orang.lain@example.test',
    ]));

    $second->assertStatus(422);
    $second->assertJsonPath('success', false);
    expect($second->json('errors'))->toHaveKey('no_telepon');

    expect(User::query()->count())->toBe(1);
});

test('register refuses a duplicate email address', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $this->postJson('/api/v1/auth/register', authRegisterPayload(['no_telepon' => '081299999999']))
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'The email has already been taken.');
});

test('register is rate limited to ten a minute per identifier', function (): void {
    // `throttle:auth-otp-send` is 10/min. The route middleware runs before the
    // controller, which is why the first attempt succeeds and the next nine are refused
    // by validation rather than by the limiter.
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    for ($attempt = 1; $attempt <= 9; $attempt++) {
        $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertStatus(422);
    }

    $throttled = $this->postJson('/api/v1/auth/register', authRegisterPayload());

    $throttled->assertStatus(429);
    $throttled->assertJsonPath('success', false);
    expect($throttled->getContent())->not->toContain('no_telepon');

    // A different identifier has its own budget, so one client cannot lock every
    // account out of registering by spending a shared one.
    $this->postJson('/api/v1/auth/register', authRegisterPayload([
        'no_telepon' => '081200000000',
        'email' => 'orang.lain@example.test',
    ]))->assertCreated();
});

// =====================================================================
// POST /api/v1/auth/login
// =====================================================================

test('login accepts a correct password by phone number and returns no token', function (): void {
    $verified = authRegisterVerified();

    $response = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => authTestPhone(),
        'password' => authTestPassword(),
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.otp.tujuan', OtpService::TUJUAN_LOGIN);
    $response->assertJsonPath('data.otp.ttl_detik', 300);

    $body = (string) $response->getContent();

    // The plan's own acceptance criterion: login alone returns no `data.token`.
    expect($response->json('data.token'))->toBeNull()
        ->and($response->json('data.access_token'))->toBeNull()
        ->and($body)->not->toContain('access_token')
        ->and($body)->not->toContain('refresh_token');

    expect(authSender()->countFor(OtpService::TUJUAN_LOGIN))->toBe(1)
        // Still exactly the one pair the verification issued: login minted an OTP and
        // nothing else.
        ->and(UserRefreshToken::query()->count())->toBe(1)
        ->and($verified['refresh'])->toHaveLength(TokenService::REFRESH_TOKEN_PANJANG);
});

test('login accepts a correct password by email address', function (): void {
    $verified = authRegisterVerified();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'budi.santoso@example.test',
        'password' => authTestPassword(),
    ])->assertOk()->assertJsonPath('data.otp.tujuan', OtpService::TUJUAN_LOGIN);

    $this->postJson('/api/v1/auth/otp/verify', [
        'email' => 'budi.santoso@example.test',
        'kode' => authSender()->lastKodeFor(OtpService::TUJUAN_LOGIN),
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ])->assertOk();

    expect($verified['access'])->not->toBe('');
});

test('login with a wrong password is refused and mints no OTP', function (): void {
    authRegisterVerified();

    $response = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => authTestPhone(),
        'password' => 'kata-sandi-yang-salah',
    ]);

    $response->assertStatus(401);
    $response->assertJsonPath('success', false);
    expect($response->json('errors'))->toBe([]);

    expect(authSender()->countFor(OtpService::TUJUAN_LOGIN))->toBe(0)
        ->and(UserOtp::query()->where('tujuan', OtpService::TUJUAN_LOGIN)->count())->toBe(0);
});

test('an unknown identifier and a wrong password are answered identically', function (): void {
    authRegisterVerified();

    $unknown = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => '089999999999',
        'password' => authTestPassword(),
    ]);
    $wrong = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => authTestPhone(),
        'password' => 'kata-sandi-yang-salah',
    ]);

    $unknown->assertStatus(401);
    $wrong->assertStatus(401);

    // Byte-identical, so the endpoint cannot be used to enumerate registered numbers.
    // `passwordMatches()` also spends a real bcrypt verification on the unknown branch,
    // so the two are not merely equal in body but equal in cost.
    expect($unknown->getContent())->toBe($wrong->getContent());
});

test('login refuses a nonaktif or ditangguhan account before minting an OTP', function (string $status): void {
    authRegisterVerified();

    $user = User::query()->where('no_telepon', authTestPhone())->firstOrFail();
    $user->status = $status;
    $user->save();

    $response = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => authTestPhone(),
        'password' => authTestPassword(),
    ]);

    $response->assertStatus(403);
    expect(authSender()->countFor(OtpService::TUJUAN_LOGIN))->toBe(0);
})->with(['nonaktif', 'ditangguhkan']);

test('login requires a password and at least one identifier', function (): void {
    $this->postJson('/api/v1/auth/login', ['no_telepon' => authTestPhone()])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'The kata sandi field is required.');

    $neither = $this->postJson('/api/v1/auth/login', ['password' => 'apa-saja-123']);

    $neither->assertStatus(422);
    expect($neither->json('errors'))->toHaveKeys(['no_telepon', 'email'])
        ->and($neither->json('errors.no_telepon.0'))->toBe('Isi no_telepon atau email.');
});

test('login is rate limited to five a minute per identifier', function (): void {
    authRegisterVerified();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'no_telepon' => authTestPhone(),
            'password' => 'kata-sandi-yang-salah',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'no_telepon' => authTestPhone(),
        'password' => authTestPassword(),
    ])->assertStatus(429);
});

// =====================================================================
// POST /api/v1/auth/otp/verify
// =====================================================================

test('a verified OTP flips the account to aktif, marks the code used and issues a token pair', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => authSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
        'device_id' => 'hp-andi-2026',
    ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('data.user.telepon_terverifikasi', true);
    $response->assertJsonPath('data.user.status', 'aktif');
    $response->assertJsonPath('data.token.token_type', 'Bearer');

    $user = User::query()->where('no_telepon', authTestPhone())->firstOrFail();

    expect($user->status)->toBe('aktif')
        ->and((bool) $user->telepon_terverifikasi)->toBeTrue()
        ->and($user->last_login_at)->not->toBeNull();

    expect((bool) UserOtp::query()->value('sudah_dipakai'))->toBeTrue()
        ->and(app(OtpService::class)->liveCodeCount($user, OtpService::TUJUAN_VERIFIKASI_TELEPON))->toBe(0);

    $refresh = (string) $response->json('data.token.refresh_token');

    expect($refresh)->toHaveLength(TokenService::REFRESH_TOKEN_PANJANG)
        ->and(UserRefreshToken::query()->count())->toBe(1)
        ->and((bool) UserRefreshToken::query()->value('dicabut'))->toBeFalse()
        ->and(UserRefreshToken::query()->value('token_hash'))->not->toBe($refresh)
        ->and(UserRefreshToken::query()->value('token_hash'))->toBe(hash('sha256', $refresh));

    // `personal_access_tokens.name` carries the device, so an administrator reading a
    // token list can tell a phone session from a web one.
    expect(DB::table('personal_access_tokens')->value('name'))->toBe('api:hp-andi-2026');

    // The issued access token really authenticates.
    authAsToken((string) $response->json('data.token.access_token'))
        ->getJson('/api/v1/auth/devices')
        ->assertOk();
});

test('a code with a leading zero verifies, because it is never parsed as an integer', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $user = User::query()->where('no_telepon', authTestPhone())->firstOrFail();

    // Replaces the register code with one starting `0`, which is the case a client
    // breaks by casting the response value to a number.
    UserOtp::query()->where('user_id', $user->getKey())->update([
        'kode_hash' => hash('sha256', '000123'),
        'kedaluwarsa_at' => now()->addMinutes(5),
        'sudah_dipakai' => false,
    ]);

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => '000123',
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk();
});

test('a wrong code is refused, is not marked used, and issues nothing', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => '999999',
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    $response->assertJsonPath('message', 'The given data was invalid.');
    $response->assertJsonPath('errors.kode.0', 'Kode OTP tidak valid.');

    expect((bool) UserOtp::query()->value('sudah_dipakai'))->toBeFalse()
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(DB::table('user_refresh_tokens')->count())->toBe(0)
        ->and(User::query()->value('status'))->toBe('pending_verifikasi');
});

test('an expired code is refused with its own message', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    UserOtp::query()->update(['kedaluwarsa_at' => now()->subMinute()]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => authSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.kode.0', 'Kode OTP sudah kedaluwarsa. Silakan minta kode baru.');
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

test('a code that was already used is refused as a replay, not as a wrong code', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $kode = (string) authSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON);

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => $kode,
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk();

    $replay = $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => $kode,
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    $replay->assertStatus(422);
    $replay->assertJsonPath('errors.kode.0', 'Kode OTP sudah pernah dipakai.');

    // One verification, one token. The replay issued nothing.
    expect(UserRefreshToken::query()->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->count())->toBe(1);
});

test('a superseded code is refused as expired and cannot be used after a resend', function (): void {
    $verified = authRegisterVerified();

    // Two logins without verifying in between. The second `issue()` supersedes the
    // first, which is the situation the supersession rule exists for: a user who asked
    // for a code twice must not be able to spend the older one.
    $this->postJson('/api/v1/auth/login', [
        'no_telepon' => authTestPhone(),
        'password' => authTestPassword(),
    ])->assertOk();

    $firstKode = (string) authSender()->lastKodeFor(OtpService::TUJUAN_LOGIN);

    $this->postJson('/api/v1/auth/login', [
        'no_telepon' => authTestPhone(),
        'password' => authTestPassword(),
    ])->assertOk();

    $secondKode = (string) authSender()->lastKodeFor(OtpService::TUJUAN_LOGIN);

    expect($firstKode)->toMatch('/^[0-9]{6}$/')
        ->and($secondKode)->not->toBe($firstKode)
        // Supersession closes the first code's validity window rather than setting a
        // column the DDL does not have, so exactly one live code survives per purpose.
        ->and(app(OtpService::class)->liveCodeCount($verified['user'], OtpService::TUJUAN_LOGIN))->toBe(1);

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => $firstKode,
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ])->assertStatus(422)->assertJsonPath('errors.kode.0', 'Kode OTP sudah kedaluwarsa. Silakan minta kode baru.');

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => $secondKode,
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ])->assertOk();
});

test('verify refuses a code that is not six digits and a purpose Module 1 does not issue', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => '12345',
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertStatus(422)->assertJsonPath('errors.kode.0', 'The kode OTP field format is invalid.');

    // `reset_kata_sandi` and `verifikasi_email` are in the DDL enum but this endpoint's
    // effect is "issue a session", which is not a correct outcome for either of them.
    foreach ([OtpService::TUJUAN_RESET_KATA_SANDI, OtpService::TUJUAN_VERIFIKASI_EMAIL] as $purpose) {
        $this->postJson('/api/v1/auth/otp/verify', [
            'no_telepon' => authTestPhone(),
            'kode' => '123456',
            'tujuan' => $purpose,
        ])->assertStatus(422)->assertJsonPath('errors.tujuan.0', 'The selected tujuan OTP is invalid.');
    }

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

test('verify does not report an unknown account differently from an unknown code', function (): void {
    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => '089999999999',
        'kode' => '123456',
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ])->assertStatus(422)->assertJsonPath('errors.kode.0', 'Kode OTP tidak valid.');
});

test('verify is rate limited to five a minute per identifier', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/v1/auth/otp/verify', [
            'no_telepon' => authTestPhone(),
            'kode' => '999999',
            'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => '999999',
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertStatus(429);
});

test('verify never moves a suspended account back to aktif', function (): void {
    $this->postJson('/api/v1/auth/register', authRegisterPayload())->assertCreated();

    $user = User::query()->where('no_telepon', authTestPhone())->firstOrFail();
    $user->status = 'ditangguhkan';
    $user->save();

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => authTestPhone(),
        'kode' => authSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk();

    expect($user->fresh()->status)->toBe('ditangguhkan');
});

// =====================================================================
// POST /api/v1/auth/refresh
// =====================================================================

test('refresh rotates the pair and marks the presented row revoked', function (): void {
    $verified = authRegisterVerified();

    $oldRowId = UserRefreshToken::query()->value('id');

    $response = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $verified['refresh']]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('data.token.token_type', 'Bearer');

    $rotated = (string) $response->json('data.token.refresh_token');

    expect($rotated)->not->toBe($verified['refresh'])
        ->and(UserRefreshToken::query()->count())->toBe(2)
        ->and((bool) UserRefreshToken::query()->where('id', $oldRowId)->value('dicabut'))->toBeTrue()
        ->and((bool) UserRefreshToken::query()->where('token_hash', hash('sha256', $rotated))->value('dicabut'))
        ->toBeFalse();

    authAsToken((string) $response->json('data.token.access_token'))
        ->getJson('/api/v1/auth/devices')
        ->assertOk();
});

test('replaying a spent refresh token is refused and revokes every live token for the account', function (): void {
    // `user_refresh_tokens` has no `device_id` column, so the server cannot tell which
    // device a spent token belonged to and has no narrower action available than this.
    $verified = authRegisterVerified();
    $first = $verified['refresh'];

    $second = (string) $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])
        ->assertOk()
        ->json('data.token.refresh_token');

    expect((bool) UserRefreshToken::query()->where('token_hash', hash('sha256', $first))->value('dicabut'))
        ->toBeTrue()
        ->and((bool) UserRefreshToken::query()->where('token_hash', hash('sha256', $second))->value('dicabut'))
        ->toBeFalse();

    $replay = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first]);

    $replay->assertStatus(401);
    $replay->assertJsonPath('success', false);
    $replay->assertJsonPath('message', 'Sesi tidak valid. Silakan masuk kembali.');

    // Every live refresh token for the account is now revoked, including the one a thief
    // would be holding.
    expect(UserRefreshToken::query()->where('dicabut', false)->count())->toBe(0);
});

test('an unknown and an expired refresh token are refused with the same body', function (): void {
    $verified = authRegisterVerified();

    $unknown = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => str_repeat('z', TokenService::REFRESH_TOKEN_PANJANG),
    ]);

    UserRefreshToken::query()->update(['kedaluwarsa_at' => now()->subMinute()]);

    $expired = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $verified['refresh']]);

    $unknown->assertStatus(401);
    $expired->assertStatus(401);

    // One string for all three reasons, so the response cannot be used as an oracle for
    // which condition applied.
    expect($unknown->getContent())->toBe($expired->getContent());
});

test('a refresh token of the wrong length is a validation error, not a session failure', function (): void {
    authRegisterVerified();

    $response = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'terlalu-pendek']);

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    expect($response->json('errors'))->toHaveKey('refresh_token');
});

// =====================================================================
// POST /api/v1/auth/logout
// =====================================================================

test('logout revokes only the presented refresh token and leaves every device active', function (): void {
    // F01 decision #3: logout is PER-DEVICE. It revokes the presented refresh
    // token and the access token it arrived on, and touches nothing else -
    // `user_refresh_tokens` has no `device_id`, so "the current device row" is
    // not expressible, and deactivating all rows is `logout-all`'s job.
    $verified = authRegisterVerified();
    $user = $verified['user'];

    $otherSession = authLoginVerified($user);

    authRegisterDevice($user, 'hp-1');
    authRegisterDevice($user, 'web-1');
    authRegisterDevice($user, 'hp-2');

    $accessToken = authAccessTokenFor($user);
    $accessTokenRowId = (int) DB::table('personal_access_tokens')->max('id');

    $response = authAsToken($accessToken)
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $verified['refresh']]);

    $response->assertOk();
    $response->assertJsonPath('data.refresh_token.dicabut', true);
    $response->assertJsonPath('data.access_token.dihapus', true);
    // The key is kept for contract compatibility and reports a truthful zero:
    // a per-device logout cannot identify the device row to deactivate.
    $response->assertJsonPath('data.perangkat.dimatikan', 0);

    expect((bool) UserRefreshToken::query()
        ->where('token_hash', hash('sha256', $verified['refresh']))
        ->value('dicabut'))->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('id', $accessTokenRowId)->count())->toBe(0)
        // The OTHER session is untouched, and every device row is still active.
        ->and((bool) UserRefreshToken::query()
            ->where('token_hash', hash('sha256', $otherSession['refresh']))
            ->value('dicabut'))->toBeFalse()
        ->and(UserDevice::query()->where('aktif', true)->count())->toBe(3)
        ->and(UserDevice::query()->count())->toBe(3);

    // And the other session really still works: its refresh token rotates.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $otherSession['refresh']])
        ->assertOk();
});

test('logout-all revokes every refresh token and deactivates every device', function (): void {
    // F01 decision #3's second half: the broad action the old logout performed
    // is now its own deliberate endpoint.
    $verified = authRegisterVerified();
    $user = $verified['user'];

    $otherSession = authLoginVerified($user);

    authRegisterDevice($user, 'hp-1');
    authRegisterDevice($user, 'web-1');
    authRegisterDevice($user, 'hp-2');

    $accessToken = authAccessTokenFor($user);
    $accessTokenRowId = (int) DB::table('personal_access_tokens')->max('id');

    $response = authAsToken($accessToken)->postJson('/api/v1/auth/logout-all');

    $response->assertOk();
    $response->assertJsonPath('data.refresh_token.dicabut', true);
    $response->assertJsonPath('data.refresh_token.jumlah', 2);
    $response->assertJsonPath('data.access_token.dihapus', true);
    $response->assertJsonPath('data.perangkat.dimatikan', 3);

    // Every live refresh token is revoked, including the other session's.
    expect(UserRefreshToken::query()->where('dicabut', false)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('id', $accessTokenRowId)->count())->toBe(0)
        // Deactivated, never deleted: `user_devices` is the only record of which
        // installations hold a push registration.
        ->and(UserDevice::query()->where('aktif', true)->count())->toBe(0)
        ->and(UserDevice::query()->count())->toBe(3);

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $otherSession['refresh']])
        ->assertStatus(401);
});

test('logout-all requires an authenticated caller', function (): void {
    authRegisterVerified();

    authAsAnonymous()
        ->postJson('/api/v1/auth/logout-all')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('the access token stops working after logout', function (): void {
    $verified = authRegisterVerified();
    $accessToken = authAccessTokenFor($verified['user']);

    authAsToken($accessToken)->getJson('/api/v1/auth/devices')->assertOk();

    authAsToken($accessToken)
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $verified['refresh']])
        ->assertOk();

    authAsToken($accessToken)
        ->getJson('/api/v1/auth/devices')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('logout requires a refresh token and an authenticated caller', function (): void {
    $verified = authRegisterVerified();

    authAsUser($verified['user'])
        ->postJson('/api/v1/auth/logout', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.refresh_token.0', 'The refresh token field is required.');

    authAsAnonymous()
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $verified['refresh']])
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});

// =====================================================================
// Devices
// =====================================================================

test('registering a device upserts on the unique pair and sets it active', function (): void {
    $verified = authRegisterVerified();

    $created = authAsUser($verified['user'])->postJson('/api/v1/auth/devices', [
        'device_id' => 'hp-andi-2026',
        'platform' => 'android',
        'fcm_token' => 'fcm-token-awal',
        'app_versi' => '1.4.2',
    ]);

    $created->assertCreated();
    $created->assertJsonPath('data.device.device_id', 'hp-andi-2026');
    $created->assertJsonPath('data.device.aktif', true);
    $created->assertJsonPath('data.device.platform', 'android');

    $device = UserDevice::query()->where('user_id', $verified['user']->getKey())->firstOrFail();

    expect($device->fcm_token)->toBe('fcm-token-awal')
        ->and($device->app_versi)->toBe('1.4.2')
        ->and($device->last_active_at)->not->toBeNull()
        ->and((bool) $device->aktif)->toBeTrue();
});

test('re-registering a device updates it in place and reactivates it after a logout-all', function (): void {
    $verified = authRegisterVerified();
    $user = $verified['user'];

    authAsUser($user)->postJson('/api/v1/auth/devices', [
        'device_id' => 'hp-andi-2026',
        'platform' => 'android',
        'fcm_token' => 'fcm-token-lama',
        'app_versi' => '1.0.0',
    ])->assertCreated();

    // `logout-all` is the action that deactivates every device row for the
    // account; a per-device logout deliberately leaves them active.
    authAsUser($user)
        ->postJson('/api/v1/auth/logout-all')
        ->assertOk();

    expect((bool) UserDevice::query()->value('aktif'))->toBeFalse();

    $again = authAsUser($user)->postJson('/api/v1/auth/devices', [
        'device_id' => 'hp-andi-2026',
        'platform' => 'android',
        'fcm_token' => 'fcm-token-baru',
        'app_versi' => '1.4.2',
    ]);

    $again->assertOk();
    $again->assertJsonPath('data.device.aktif', true);
    $again->assertJsonPath('data.device.fcm_token', 'fcm-token-baru');

    // Still one row: `uq_device (user_id, device_id)` was the key, not an insert.
    expect(UserDevice::query()->count())->toBe(1)
        ->and(UserDevice::query()->value('fcm_token'))->toBe('fcm-token-baru');
});

test('the device list contains only the caller devices', function (): void {
    $verified = authRegisterVerified();

    $other = User::factory()->create(['tipe' => 'dokter', 'status' => 'aktif']);

    authRegisterDevice($verified['user'], 'hp-1');
    authRegisterDevice($verified['user'], 'web-1');
    authRegisterDevice($other, 'hp-orang-lain');

    $response = authAsUser($verified['user'])->getJson('/api/v1/auth/devices');

    $response->assertOk();
    $response->assertJsonStructure([
        'success',
        'data' => ['devices'],
        'message',
        'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
    ]);
    // The count moved from `data.total` to the project-wide `meta` block in todo 21, which
    // widened `ApiResponse` to have one. The list is still a single unpaginated page, so
    // `current_page` and `last_page` are both 1 and `per_page` is the row count.
    $response->assertJsonPath('meta.total', 2);
    $response->assertJsonPath('meta.current_page', 1);
    $response->assertJsonPath('meta.last_page', 1);
    expect($response->json('data'))->not->toHaveKey('total');
    $response->assertJsonCount(2, 'data.devices');

    $ids = collect($response->json('data.devices'))->pluck('device_id')->all();
    sort($ids);

    // The surrogate key and the owning user are not published: the pair is the identity
    // the API addresses a device by, and the list is always the caller's own.
    expect($ids)->toBe(['hp-1', 'web-1'])
        ->and($response->getContent())->not->toContain('"user_id"')
        ->and($response->getContent())->not->toContain('"id":');
});

test('revoking a device deactivates it and refuses to touch another account device', function (): void {
    $verified = authRegisterVerified();
    $user = $verified['user'];

    $other = User::factory()->create(['tipe' => 'dokter', 'status' => 'aktif']);

    authRegisterDevice($user, 'hp-1');
    authRegisterDevice($other, 'hp-orang-lain');

    $token = authAccessTokenFor($user);

    $revoked = authAsToken($token)->deleteJson('/api/v1/auth/devices/hp-1');

    $revoked->assertOk();
    $revoked->assertJsonPath('data.device.aktif', false);

    // 404, not 403: a 403 would confirm the device exists.
    $crossUser = authAsToken($token)->deleteJson('/api/v1/auth/devices/hp-orang-lain');
    $crossUser->assertStatus(404);
    $crossUser->assertJsonPath('success', false);
    $crossUser->assertJsonPath('message', 'Resource not found.');

    authAsToken($token)->deleteJson('/api/v1/auth/devices/tidak-ada')->assertStatus(404);

    // The row still exists -- deactivated, never deleted -- and the other account's is
    // untouched.
    expect(UserDevice::query()->where('device_id', 'hp-1')->count())->toBe(1)
        ->and((bool) UserDevice::query()->where('device_id', 'hp-1')->value('aktif'))->toBeFalse()
        ->and((bool) UserDevice::query()->where('device_id', 'hp-orang-lain')->value('aktif'))->toBeTrue();
});

test('device registration refuses a platform outside the DDL enum and an oversized version', function (): void {
    $verified = authRegisterVerified();
    $token = authAccessTokenFor($verified['user']);

    authAsToken($token)->postJson('/api/v1/auth/devices', [
        'device_id' => 'hp-1',
        'platform' => 'webview',
    ])->assertStatus(422)->assertJsonPath('errors.platform.0', 'The selected platform is invalid.');

    authAsToken($token)->postJson('/api/v1/auth/devices', [
        'device_id' => 'hp-1',
        'platform' => 'web',
        'app_versi' => str_repeat('9', 21),
    ])->assertStatus(422)->assertJsonPath(
        'errors.app_versi.0',
        'The versi aplikasi field must not be greater than 20 characters.'
    );

    expect(UserDevice::query()->count())->toBe(0);
});

test('every device endpoint refuses an unauthenticated caller with the 401 envelope', function (string $method, string $uri): void {
    $response = $this->json($method, $uri, [
        'device_id' => 'hp-1',
        'platform' => 'web',
    ]);

    $response->assertStatus(401);
    $response->assertJsonPath('success', false);
    expect($response->getContent())
        ->toBe('{"success":false,"message":"Unauthenticated.","errors":{}}')
        ->and($response->headers->get('Location'))->toBeNull();
})->with([
    ['GET', '/api/v1/auth/devices'],
    ['POST', '/api/v1/auth/devices'],
    ['DELETE', '/api/v1/auth/devices/hp-1'],
]);

// =====================================================================
// Contracts the endpoints depend on
// =====================================================================

test('the route table exposes exactly the ten module 1 auth routes with the expected middleware', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/auth'))
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();

    expect(array_keys($routes))->toEqualCanonicalizing([
        'POST api/v1/auth/register',
        'POST api/v1/auth/login',
        'POST api/v1/auth/otp/verify',
        'POST api/v1/auth/otp/resend',
        'POST api/v1/auth/refresh',
        'POST api/v1/auth/logout',
        'POST api/v1/auth/logout-all',
        'GET api/v1/auth/devices',
        'POST api/v1/auth/devices',
        'DELETE api/v1/auth/devices/{deviceId}',
    ]);

    $middlewareFor = static function (string $key) use ($routes): array {
        return array_values(array_filter(
            $routes[$key]->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware),
        ));
    };

    expect($middlewareFor('POST api/v1/auth/register'))->toContain('throttle:auth-otp-send')
        ->and($middlewareFor('POST api/v1/auth/login'))->toContain('throttle:auth-login')
        ->and($middlewareFor('POST api/v1/auth/otp/verify'))->toContain('throttle:auth-otp-verify')
        ->and($middlewareFor('POST api/v1/auth/otp/resend'))->toContain('throttle:auth-otp-resend')
        ->and($middlewareFor('POST api/v1/auth/logout'))->toContain('auth:sanctum')
        ->and($middlewareFor('POST api/v1/auth/logout-all'))->toContain('auth:sanctum')
        ->and($middlewareFor('GET api/v1/auth/devices'))->toContain('auth:sanctum')
        ->and($middlewareFor('POST api/v1/auth/devices'))->toContain('auth:sanctum')
        ->and($middlewareFor('DELETE api/v1/auth/devices/{deviceId}'))->toContain('auth:sanctum');
});

test('every permission and tipe string in routes/api.php resolves against the RbacCatalog', function (): void {
    // `EnsurePermission` and `EnsureUserType` throw a LogicException -- a 500 -- for an
    // unknown code, so a typo in a route is a build-time mistake rather than a 403 for
    // everyone. Module 1 currently uses **no** code, because the 24-code catalogue has
    // none for an auth, session, token or device action; this test is the tripwire that
    // makes the next todo's first code impossible to introduce by accident.
    $source = (string) file_get_contents(base_path('routes/api.php'));

    preg_match_all("/'(permission|tipe):([^']+)'/", $source, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        foreach (explode(',', $match[2]) as $value) {
            $known = $match[1] === 'permission'
                ? RbacCatalog::isPermission($value)
                : RbacCatalog::isUserType($value);

            expect($known)->toBeTrue(
                "routes/api.php uses {$match[1]}:{$value}, which is not in RbacCatalog. An unknown code is a 500, "
                .'not a 403, so this must fail here rather than at runtime.'
            );
        }
    }

    // Module 2 (booking) is the first consumer and Module 3 (consultation, medical
    // record) added seven more, for NINETEEN strings in total; todo 34's letter
    // create adds two more, for TWENTY-ONE; todo 39's two routes add four, for
    // TWENTY-FIVE; todo 40's four routes add five, for THIRTY. Each is proven
    // to resolve against `RbacCatalog` by the loop above, and a new entry must
    // arrive with its catalogue entry in the same commit.
    //
    // This census is deliberately DUPLICATED over the same regex in
    // `PasienProfileTest`, which asserts the same list. Two files asserting one
    // property is the point: a closed set that only one file watches is a closed set
    // that one later refactor can quietly reopen. Both lists were regenerated from
    // the live `routes/api.php` rather than typed - and typing is how three previous
    // batches shipped a literal that had silently become a different string, once
    // inside a permission name.
    expect(array_map(static fn (array $m): string => $m[0], $matches))->toEqualCanonicalizing([
        "'permission:booking.lihat'",
        "'permission:booking.buat'",
        "'permission:booking.batal'",
        // F12's two booking routes. The policy read takes `booking.lihat`
        // (already "read a booking you are a party to"), and the reschedule
        // takes `booking.batal` - the only modification code held by exactly
        // the two parties who may cancel, which is the audience a schedule
        // move has. No code in the catalogue names schedule movement, and
        // adding a 25th is a policy change this task does not make; see
        // `web/ux/patterns/F12.md` section 12.
        "'permission:booking.lihat'",
        "'permission:booking.batal'",
        "'permission:booking.lihat'",
        "'tipe:dokter'",
        "'tipe:dokter'",
        // F13's doctor list, `GET /api/v1/konsultasi`, registered first inside
        // the konsultasi group and before its `{id}` wildcard. It carries
        // `tipe:dokter` and NO `permission:`, because the catalogue's three
        // consultation codes name no read action - a `permission:` here would
        // have to be invented, and `EnsurePermission` turns an unknown code
        // into a 500, not a 403. The account-type gate is the strongest guard
        // the catalogue can actually resolve, and the ownership half is the
        // `where('dokter_id', ...)` scope in `KonsultasiService::daftar()`.
        "'tipe:dokter'",
        "'permission:konsultasi.mulai'",
        "'permission:konsultasi.chat'",
        "'permission:konsultasi.chat'",
        "'tipe:dokter'",
        "'permission:konsultasi.selesai'",
        "'tipe:dokter'",
        "'permission:rekam_medis.simpan'",
        "'tipe:dokter'",
        "'permission:rekam_medis.simpan'",
        "'tipe:dokter'",
        "'permission:rekam_medis.final'",
        "'tipe:dokter'",
        "'permission:rekam_medis.final'",
        // Todo 34's letter create: `surat_keterangan.buat` is granted to `dokter`
        // and `superadmin`, and `tipe:dokter` is what excludes the oversight account.
        "'permission:surat_keterangan.buat'",
        "'tipe:dokter'",
        // Todo 39's two routes, in the order `routes/api.php` wires them. The
        // catalogue search and the prescription create are the first two
        // routes whose guard is a READ-side `obat.cari` rather than a
        // lifecycle code, which is why the two new permissions sit here at all:
        // `obat.cari` is what a doctor needs to look a drug up, and
        // `resep.buat` is what they need to prescribe it. Both are granted to
        // `dokter` and `superadmin` and to nobody else - `apoteker` holds
        // `resep.verifikasi`, which is deliberately NOT `resep.buat`, so
        // verifying a prescription and writing one stay separate grants.
        //
        // The list above is regenerated from the live `routes/api.php` with the
        // same `preg_match_all` the assertion uses, never typed, so this
        // paragraph records intent while the array records fact.
        //
        // FOUR strings for TWO routes, because the regex captures the
        // `permission:` and the `tipe:` as separate hits: each route wires
        // both, so two routes are four entries, and writing three would leave
        // the census one short - which is the failure the run reported.
        "'permission:obat.cari'",
        "'tipe:dokter'",
        "'permission:resep.buat'",
        "'tipe:dokter'",
        // Todo 40's four routes, in the order `routes/api.php` wires them. The
        // patient history, the detail and the interaction re-check carry only
        // `resep.lihat` - a READ code, and no `tipe:`, because the read
        // audience is a disjunction (the prescriber OR the patient OR a
        // pharmacist) that a route gate can only express as a conjunction, so
        // the per-row half of the rule lives in `ResepAccess`. The verify
        // write is the only route here with BOTH halves, because
        // `resep.verifikasi` is granted to `apoteker` AND `superadmin` and
        // `tipe:apoteker` is what refuses the oversight account from signing a
        // clinical prescription.
        //
        // FIVE strings for FOUR routes: three carry only `permission:`, so the
        // regex counts one hit each, and the verify route wires both.
        "'permission:resep.lihat'",
        "'permission:resep.lihat'",
        "'permission:resep.lihat'",
        "'permission:resep.verifikasi'",
        "'tipe:apoteker'",
        // Todo 46's checkout routes, appended after todo 40's block: the
        // checkout writes an order and the order read is a `pesanan.lihat`
        // READ that a pharmacist and the patient both need.
        "'permission:pesanan.buat'",
        "'permission:pesanan.lihat'",
        // Todo 45's payment initiation. `pembayaran.bayar` is granted to
        // `pasien` and `superadmin` and to nobody else, so the gate refuses
        // `dokter`, `apoteker` and `admin` WITHOUT locking out the one account
        // type that owns the invoice being paid - which is why a `tipe:` is not
        // needed here and would in fact be the wrong gate. F06's read route
        // REUSES the same code - the catalogue has no `pembayaran.lihat`, and
        // inventing one would make `EnsurePermission` throw a 500 until the
        // catalogue moved with it - so the read contributes a SECOND identical
        // string (`GET /api/v1/invoice/{id}`, registered after the
        // initiation). The webhook takes NEITHER, and so contributes no string
        // to this census at all: it is authenticated by an HMAC over the raw
        // body rather than by a session.
        "'permission:pembayaran.bayar'",
        "'permission:pembayaran.bayar'",
        // F12's patient refund list reuses the same code for the same reason:
        // the catalogue has no `pembayaran.lihat`, so the patient read takes
        // the payment grant, which already refuses `dokter`, `apoteker` and
        // `admin` and admits exactly the account type that can own a refund.
        "'permission:pembayaran.bayar'",
        // Todo 47's notification centre contributes THREE more, for THIRTY-FOUR
        // in total before F09. All three carry the same code and no `tipe:`:
        // `notifikasi.lihat` is granted to `pasien` and `superadmin`, so the
        // permission alone already refuses `perawat` and `kurir` - which are
        // real `users.tipe` values that hold no role, and so hold no grant.
        // Adding a `tipe:pasien` on top would be the wrong gate here, for the
        // same reason `pembayaran.bayar` above carries no `tipe:`: the
        // permission already narrows the audience, and a second gate would
        // lock out the one account type that legitimately holds it.
        //
        // The three PDP routes contribute NOTHING to this census. They are
        // guarded by `auth:sanctum` alone, deliberately: a consent record is the
        // caller's own, so the audience is "any authenticated caller" and a
        // permission code would add a grantable role for something that is not
        // role-scoped. `PdpNotificationTest` asserts all three guards directly.
        "'permission:notifikasi.lihat'",
        "'permission:notifikasi.lihat'",
        "'permission:notifikasi.lihat'",
        // F09's pharmacist queue contributes TWO more; F12's three strings
        // above bring the census to THIRTY-NINE in total.
        // `GET /api/v1/resep` carries the SAME pair as the verify write it
        // feeds - `permission:resep.verifikasi` is the grant (held by
        // `apoteker` and `superadmin`) and `tipe:apoteker` is the account type
        // (which is what refuses the oversight account). It deliberately does
        // NOT carry `resep.lihat`: that code is held by the patient and the
        // doctor too, and the queue is a cross-patient worklist, so a
        // `resep.lihat` gate would have made it a second, wider read.
        "'permission:resep.verifikasi'",
        "'tipe:apoteker'",
        // F14's admin clinic block contributes TEN strings, which is the whole of
        // the 43 -> 53 movement. It is the first `/admin` prefix in this file, and
        // its shape is unlike every block above it: the party gate
        // `tipe:admin,superadmin` is declared ONCE on the wrapping group rather
        // than repeated per route, so sixteen routes contribute ONE `tipe:` hit.
        //
        // The nine `permission:` hits are all READ grants. `dokter.lihat` and
        // `jadwal.lihat` each appear twice because each guards two reads; the
        // three report reads share the single catalogue code F14 added,
        // `laporan.lihat`; and `audit.lihat`/`pdp.kelola` each take the one
        // endpoint that finally consumes the code they were reserved for.
        //
        // The eight F14 WRITES contribute NOTHING to this census, and that is a
        // decision rather than an omission: no code naming a doctor or schedule
        // MUTATION was approved (it is open question 1 in
        // `web/ux/patterns/F14.md`), so the writes are guarded by the party gate
        // alone rather than by a read code reused as a write grant. `pasien`,
        // `dokter` and `apoteker` all hold `dokter.lihat`, so reusing it on a
        // write would have widened the audience of a credential mutation.
        "'tipe:admin,superadmin'",
        "'permission:dokter.lihat'",
        "'permission:dokter.lihat'",
        "'permission:jadwal.lihat'",
        "'permission:jadwal.lihat'",
        "'permission:laporan.lihat'",
        "'permission:laporan.lihat'",
        "'permission:laporan.lihat'",
        "'permission:audit.lihat'",
        "'permission:pdp.kelola'",
    ]);
});

test('the OTP purpose list is the DDL enum, verbatim and in the DDL order', function (): void {
    expect(authEnumValuesFromDdl('user_otp', 'tujuan'))->toBe(OtpService::TUJUAN)
        ->and(OtpService::TUJUAN)->toHaveCount(4)
        // The two Module 1 issues, in the DDL's own order.
        ->and(OtpService::TUJUAN_DI_TERBITKAN)->toBe(['verifikasi_telepon', 'login']);
});

test('the validated enum lists are the DDL enums, not transcriptions', function (): void {
    // The failure A.26 exists to catch is a value transcribed with a typo: an encoding
    // scan cannot see it, and the parity verifier only compares columns it can see. So
    // each list is compared with `toBe` against the reference file, which checks order
    // as well as membership.
    expect(RegisterRequest::JENIS_KELAMIN)->toBe(authEnumValuesFromDdl('pasien', 'jenis_kelamin'))
        ->and(RegisterRequest::BAHASA)->toBe(authEnumValuesFromDdl('users', 'bahasa'))
        ->and(StoreDeviceRequest::PLATFORM)->toBe(authEnumValuesFromDdl('user_devices', 'platform'))
        ->and(RbacCatalog::USER_TYPES)->toBe(authEnumValuesFromDdl('users', 'tipe'));
});

test('the log OTP sender writes the code and masks the recipient', function (): void {
    $path = storage_path('framework/testing/log-otp-sender-'.getmypid().'-'.uniqid().'.log');

    if (! is_dir($directory = dirname($path))) {
        mkdir($directory, 0777, true);
    }

    config([
        'logging.channels.sehatly_otp_test' => ['driver' => 'single', 'path' => $path, 'level' => 'notice'],
        'logging.default' => 'sehatly_otp_test',
    ]);

    app(LogOtpSender::class)->send('081234567890', '123456', OtpService::TUJUAN_LOGIN);
    app(LogOtpSender::class)->send('budi@example.test', '654321', OtpService::TUJUAN_VERIFIKASI_EMAIL);

    $body = (string) file_get_contents($path);

    @unlink($path);

    expect($body)->toContain('sehatly.otp')
        ->and($body)->toContain('123456')
        ->and($body)->toContain('654321')
        // The point of masking: the log is shipped off-box by every log shipper in the
        // stack, and neither a phone number nor an address belongs in it in full.
        ->and($body)->not->toContain('081234567890')
        ->and($body)->not->toContain('budi@example.test')
        ->and($body)->toContain('b***@example.test');
});

test('a freshly registered patient holds the role that grants the patient permissions', function (): void {
    // The wiring that makes todo 21 and todo 27 reachable: `RbacSeeder` writes no
    // `user_roles` row, so if `register()` did not grant one, a registered patient would
    // authenticate and then be refused by every `permission:`-gated route.
    $verified = authRegisterVerified();
    $loggedIn = authLoginVerified($verified['user']);

    // Asserted against the table, not against a probe route, so this exercises the real
    // grant rather than a fixture.
    $granted = DB::table('role_permissions')
        ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
        ->where('user_roles.user_id', $verified['user']->getKey())
        ->where('permissions.kode', 'notifikasi.lihat')
        ->exists();

    expect($granted)->toBeTrue()
        ->and(RbacCatalog::permissionsFor('pasien'))->toContain('notifikasi.lihat')
        ->and($loggedIn['access'])->not->toBe('')
        // And the second login minted a second refresh row, both live: rotation is what
        // keeps exactly one chain per session, not a single row per account.
        ->and(UserRefreshToken::query()->where('dicabut', false)->count())->toBe(2);
});
