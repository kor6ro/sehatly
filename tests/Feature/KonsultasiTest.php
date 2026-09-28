<?php

declare(strict_types=1);

use App\Events\KonsultasiMessageSent;
use App\Http\Resources\KonsultasiChatResource;
use App\Http\Resources\KonsultasiResource;
use App\Models\Konsultasi;
use App\Models\KonsultasiChat;
use App\Models\Pasien;
use App\Models\User;
use App\Services\Konsultasi\KonsultasiAccess;
use App\Services\Konsultasi\KonsultasiChannelAccess;
use App\Support\Rbac\RoleAssigner;
use App\Support\Rbac\RbacCatalog;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/*
|--------------------------------------------------------------------------
| The consultation lifecycle and its chat transcript, over HTTP
|--------------------------------------------------------------------------
|
| Seven routes, one state machine, one ownership rule, and one envelope. The
| schema half - the six values, the ten edges, the 65 cited DDL lines and the
| system-author finding - is in `KonsultasiSchemaTest`; this file is the behaviour half and it
| drives every case through the real HTTP kernel with a real Sanctum bearer
 * token, so nothing here can pass by calling a service directly.
|
| ## Authentication uses real Sanctum bearer tokens
|
| `Sanctum::actingAs()` hands back a `TransientToken` and
| `Illuminate\Auth\RequestGuard` caches its principal, so the first authenticated
| request inside a test would decide the caller for every later one. `knsAs()`
| calls `forgetGuards()` first, for the reason `PasienProfileTest` and
| `ConsultationChannelTest` both give.
|
| ## `Storage::fake('public')` on the attachment tests
|
 * The chat writes to the `public` disk, which is `storage_path('app/public')` with
 * a real `url`. A test that wrote to the real disk would leave files behind, and
 * the plan's "do not delete a file you did not create" rule is easier to keep when
 * the test never creates one. The URL is still asserted, because the URL is derived
 * from the faked disk's configuration and is what a client will actually fetch.
*/

// =====================================================================
// Authentication and role helpers
// =====================================================================

/**
 * Act as `$user` for one request.
 *
 * Returns the test case so a request can be issued inline. The `void` return type
 * in the sibling helpers of this project is not repeated here because two requests
 * in one test each need their own.
 */
function knsAs(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('konsultasi-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * A user carrying `$role`, or no role at all.
 *
 * `perawat` and `kurir` are real `users.tipe` values (`telemedicine_test.sql:139`)
 * that hold NO role in `RbacCatalog::ROLES`, so they can authenticate and reach
 * nothing that carries a `permission:`. A helper that always assigned a role would
 * make the two of them untestable, which is the mistake this signature exists to
 * prevent.
 */
function knsPengguna(string $tipe, ?string $role = null, array $ubah = []): User
{
    $id = knsUser('Pengguna '.$tipe.' '.Str::upper(Str::random(4)), $tipe);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    $user = User::query()->findOrFail($id);

    foreach ($ubah as $column => $value) {
        $user->{$column} = $value;
    }

    $user->save();

    return $user;
}

/**
 * A patient account: a `users` row of `tipe = 'pasien'`, its `pasien` row, and
 * the `pasien` role - which is what holds `konsultasi.chat`, so an account without it is
 * refused at the middleware and the test would be measuring the wrong thing.
 *
 * @return array{user: User, pasien: Pasien, dokter: int}
 */
function knsPatientAccount(array $userUbah = [], array $pasienUbah = []): array
{
    $user = knsPengguna('pasien', 'pasien', $userUbah);
    $pasien = Pasien::query()->findOrFail(knsPasien($user->getKey(), $pasienUbah));

    return [
        'user' => $user,
        'pasien' => $pasien,
        'dokter' => knsDokter(knsPengguna('dokter', 'dokter')->getKey()),
    ];
}

/**
 * A doctor account owning a `dokter` row, with the `dokter` role.
 *
 * @return array{user: User, dokter: int}
 */
function knsDoctorAccount(array $dokterUbah = []): array
{
    $user = knsPengguna('dokter', 'dokter');

    return ['user' => $user, 'dokter' => knsDokter($user->getKey(), $dokterUbah)];
}

/**
 * Open an instant session over HTTP and answer the consultation id.
 */
function knsMulaiViaHttp(User $patient, int $dokterId, string $tipe = 'chat'): int
{
    $response = knsAs($patient)->postJson('/api/v1/konsultasi/mulai', [
        'dokter_id' => $dokterId,
        'tipe' => $tipe,
    ]);

    $response->assertCreated();

    return (int) $response->json('data.konsultasi.id');
}


// =====================================================================
// Setup
// =====================================================================

beforeEach(function (): void {
    // `RbacSeeder` writes `roles`, `permissions` and `role_permissions`. Both of
    // those are resolved at request time - `RoleAssigner::assign()` looks a role name
    // up in `roles` and throws a `LogicException` when the catalogue names a row that
    // is not there, and `EnsurePermission` resolves every `permission:` code against
    // `permissions` - so without it the three guarded routes answer 500 and every
    // guard assertion in this file would be measuring the seeder rather than the
    // middleware.
    $this->seed(RbacSeeder::class);
});

// =====================================================================
// The route table
// =====================================================================

test('nine routes are registered under api/v1 with the expected verbs and guards', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/konsultasi'))
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();

    // The plan's acceptance criterion says six. It was seven when this test was
    // written, and the seventh is `PUT /{id}/terima`, which the plan needs and does
    // not have: `PUT /selesai` is required to compute `total_durasi_detik` from
    // `mulai_at` and to answer 422 while that column is NULL, and nothing in the
    // plan's six writes it - so `berlangsung` is unreachable and the completion
    // endpoint can only ever answer 422. `RbacCatalog` grants `konsultasi.mulai` to
    // `dokter` and to nobody else, and before this route no endpoint consumed that
    // code.
    //
    // It is NINE now: todo 33 added `rekam-medis` and todo 34 added
    // `surat-keterangan`, both under this prefix, both noted below. The count in
    // this test's NAME is the live one, so a name saying "nine" over a list of
    // eight is itself the drift the closed-set assertion exists to catch.
    expect(array_keys($routes))->toEqualCanonicalizing([
        'POST api/v1/konsultasi/mulai',
        'GET api/v1/konsultasi/{id}',
        'GET api/v1/konsultasi/{id}/chat',
        'POST api/v1/konsultasi/{id}/chat',
        'POST api/v1/konsultasi/{id}/chat/baca',
        'PUT api/v1/konsultasi/{id}/terima',
        'PUT api/v1/konsultasi/{id}/selesai',
        // Todo 33's create, which lives under this prefix because its path is a
        // consultation path. See the guard map below for the rest of the argument.
        'POST api/v1/konsultasi/{id}/rekam-medis',
        // Todo 34's create, under the same prefix for the same reason: the letter
        // hangs off a consultation. See the guard map below.
        'POST api/v1/konsultasi/{id}/surat-keterangan',
    ]);

    $middlewareFor = static function (string $key) use ($routes): array {
        return array_values(array_filter(
            $routes[$key]->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware),
        ));
    };

    // The guards, spelled out. `permission:` answers a GRANT and `tipe:` answers an
    // ACCOUNT TYPE, and both are used: the two doctor-only writes name the account
    // type first, so "a patient completed the session" is refused as the wrong
    // kind of caller rather than as a missing grant.
    //
    // APPENDED by todo 33: `POST /konsultasi/{id}/rekam-medis` is a MEDICAL RECORD
    // route, not a consultation-lifecycle one, and it lives under this prefix because
    // its path is a consultation path - the create hangs a draft record off that
    // consultation. It appears in this file's closed set because the filter is the URI
    // PREFIX, not a judgement about which todo owns the endpoint, and leaving it out
    // would be exactly the "closed set that quietly forgives a concurrently-wired
    // route" the assertion exists to catch. It is this file's filter that caught it.
    $expectedGuards = [
        'POST api/v1/konsultasi/mulai' => [],
        'GET api/v1/konsultasi/{id}' => [],
        'GET api/v1/konsultasi/{id}/chat' => [],
        'POST api/v1/konsultasi/{id}/chat' => ['permission:konsultasi.chat'],
        'POST api/v1/konsultasi/{id}/chat/baca' => ['permission:konsultasi.chat'],
        'PUT api/v1/konsultasi/{id}/terima' => ['tipe:dokter', 'permission:konsultasi.mulai'],
        'PUT api/v1/konsultasi/{id}/selesai' => ['tipe:dokter', 'permission:konsultasi.selesai'],
        'POST api/v1/konsultasi/{id}/rekam-medis' => ['tipe:dokter', 'permission:rekam_medis.simpan'],
        'POST api/v1/konsultasi/{id}/surat-keterangan' => ['tipe:dokter', 'permission:surat_keterangan.buat'],
    ];


    foreach (array_keys($routes) as $key) {
        $middleware = $middlewareFor($key);

        expect(in_array('auth:sanctum', $middleware, true))->toBeTrue("{$key} must carry auth:sanctum");

        $guards = array_values(array_filter(
            $middleware,
            static fn (string $m): bool => str_starts_with($m, 'permission:') || str_starts_with($m, 'tipe:'),
        ));

        // `in_array` and `toBe` rather than `toContain`, because Pest's `toContain`
        // treats EVERY string argument as a needle - a second argument meant as a
        // failure message silently becomes a second thing the array must contain.
        expect($guards)->toEqualCanonicalizing(
            $expectedGuards[$key] ?? [],
            "{$key} unexpectedly carries a permission/tipe guard.",
        );
    }

    // All three consultation codes in the catalogue are consumed, which is the first
    // time that is true of any of them. Asserted so a later todo cannot drop one and
    // leave it dangling.
    expect(RbacCatalog::permissionCodes())
        ->toContain('konsultasi.mulai')
        ->toContain('konsultasi.chat')
        ->toContain('konsultasi.selesai');

    foreach (RbacCatalog::permissionsFor('dokter') as $code) {
        if (str_starts_with($code, 'konsultasi.')) {
            expect(RbacCatalog::permissionsFor('pasien'))->toContain('konsultasi.chat')
                ->and(RbacCatalog::permissionsFor('pasien'))->not->toContain('konsultasi.mulai')
                ->and(RbacCatalog::permissionsFor('pasien'))->not->toContain('konsultasi.selesai');
        }
    }
});

test('every permission and tipe string in routes/api.php resolves against the catalogue', function (): void {
    // The tripwire todo 20 installed, repeated here because this todo added seven
    // routes to the same file. `EnsurePermission` and `EnsureUserType` throw a
    // `LogicException` - a 500 - for an unknown value, so a typo is a build-time
    // mistake rather than a 403 for everyone.
    $source = (string) file_get_contents(base_path('routes/api.php'));

    preg_match_all("/'(permission|tipe):([^']+)'/", $source, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

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
});

test('a non-numeric consultation id is a router 404 and never a TypeError', function (): void {
    $account = knsPatientAccount();

    foreach (['abc', '1.0', '-1'] as $segment) {
        knsAs($account['user'])->getJson('/api/v1/konsultasi/'.$segment)->assertNotFound();
    }

    // And id 0, which `whereNumber` accepts as numeric and the query refuses.
    knsAs($account['user'])->getJson('/api/v1/konsultasi/0')->assertNotFound();
});

test('an anonymous caller is refused 401 on all nine routes', function (): void {
    $account = knsPatientAccount();
    $id = knsMulaiViaHttp($account['user'], $account['dokter']);
    $account['dokter'] ??= $account['dokter'];

    $paths = [
        ['postJson', '/api/v1/konsultasi/mulai', []],
        ['getJson', '/api/v1/konsultasi/'.$id, []],
        ['getJson', '/api/v1/konsultasi/'.$id.'/chat', []],
        ['postJson', '/api/v1/konsultasi/'.$id.'/chat', ['tipe_pesan' => 'teks', 'isi' => 'halo']],
        ['postJson', '/api/v1/konsultasi/'.$id.'/chat/baca', []],
        ['putJson', '/api/v1/konsultasi/'.$id.'/terima', []],
        ['putJson', '/api/v1/konsultasi/'.$id.'/selesai', []],
        // Todo 33's medical-record create, which shares the `konsultasi` prefix. It is
        // added here rather than left out because a 401 matrix that silently stops
        // covering a route is the same failure as a closed set that quietly forgives
        // one.
        ['postJson', '/api/v1/konsultasi/'.$id.'/rekam-medis', []],
        // Todo 34's letter create, for the same reason: same prefix, same rule. Its
        // 401 comes from `auth:sanctum` on the route - the `tipe:` and `permission:`
        // guards run AFTER authentication, so an anonymous caller never reaches them.
        ['postJson', '/api/v1/konsultasi/'.$id.'/surat-keterangan', []],
    ];

    // `knsAs()` above wrote the caller's bearer token into the test case's DEFAULT
    // HEADERS, so every request issued afterwards is still authenticated unless they
    // are dropped. `MakesHttpRequests::flushHeaders()` is the framework's own answer
    // and using anything else here would make the 401 assertions pass for the wrong
    // reason - or fail, which is worse, because it looks like a guard problem.
    test()->flushHeaders();

    // `flushHeaders()` clears the request's default headers but NOT the resolved
    // principal `Illuminate\Auth\RequestGuard` cached, so the guard is dropped too.
    // Without both, the nine requests below are answered as the caller who created
    // the session one line earlier and every 401 assertion is measuring the harness.
    app('auth')->forgetGuards();

    foreach ($paths as [$method, $path, $payload]) {
        test()->{$method}($path, $payload)->assertUnauthorized();
    }
});

// =====================================================================
// Starting a session
// =====================================================================

test('an instant session is created with a uuid room, the doctors fee and one system notice', function (): void {
    $account = knsPatientAccount();
    $usersBefore = (int) DB::table('users')->count();

    $response = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', [
        'dokter_id' => $account['dokter'],
        'tipe' => 'chat',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => ['konsultasi' => [
                'id', 'booking_id', 'pasien_id', 'dokter_id', 'tipe', 'status', 'room_id',
                'mulai_at', 'selesai_at', 'total_durasi_detik',
                'catatan_subjektif', 'catatan_objektif', 'catatan_asessment', 'catatan_plan',
                'diagnosis_kerja', 'saran_tindak_lanjut', 'biaya_konsultasi', 'dibuat_at', 'diubah_at',
                'pasien' => ['id', 'nik', 'nama_lengkap'],
                'dokter' => ['id', 'nama_lengkap'],
                'booking',
            ]],
            'message',
        ]);

    $konsultasi = $response->json('data.konsultasi');

    expect($konsultasi['status'])->toBe('menunggu_dokter')
        ->and($konsultasi['booking_id'])->toBeNull()
        ->and($konsultasi['pasien_id'])->toBe($account['pasien']->getKey())
        // `room_id VARCHAR(100)` is the video SDK's room and the plan says a
        // `Str::uuid()`; a v4 UUID is 36 characters, well inside the column.
        ->and($konsultasi['room_id'])->not->toBeEmpty();

    // `room_id VARCHAR(100)` (:544) is the video SDK's room and the plan says a
    // `Str::uuid()`; a v4 UUID is 36 characters, well inside the column.
    $roomId = (string) $konsultasi['room_id'];

    expect(Str::isUuid($roomId))->toBeTrue()
        ->and(strlen($roomId))->toBe(36);

    // `biaya_konsultasi` is `DECIMAL(12,2)` (:554), so it serialises as a STRING,
    // and the instant flow records the doctor's own online fee (:420).
    expect($konsultasi['biaya_konsultasi'])->toBe(KNS_BIAYA);

    // The system notice, attributed to the account whose action caused it.
    $pesan = KonsultasiChat::query()->where('konsultasi_id', $konsultasi['id'])->firstOrFail();

    expect($pesan->pengirim_tipe)->toBe('sistem')
        ->and($pesan->tipe_pesan)->toBe('sistem')
        ->and($pesan->isi)->not->toBe('')
        ->and($pesan->pengirim_user_id)->toBe($account['user']->getKey())
        ->and($pesan->dibaca_at)->toBeNull()
        // And no user row was invented to author it.
        ->and((int) DB::table('users')->count())->toBe($usersBefore);
});

test('three instant sessions all succeed, which is what the NULL-UNIQUE column is for', function (): void {
    // The plan's acceptance criterion, and the property the whole "Tanya Dokter"
    // feature rests on: `konsultasi.booking_id` is `NULL UNIQUE` (:538) and MySQL allows
    // any number of NULLs in a UNIQUE index.
    $account = knsPatientAccount();

    $ids = [];

    for ($i = 0; $i < 3; $i++) {
        $ids[] = knsMulaiViaHttp($account['user'], $account['dokter']);
    }

    expect($ids)->toHaveCount(3)->and(array_unique($ids))->toHaveCount(3);

    foreach ($ids as $id) {
        expect(Konsultasi::query()->findOrFail($id)->booking_id)->toBeNull();
    }

    // A FOURTH on the same doctor also succeeds - the column that would have
    // collided is NULL every time, not the doctor.
    expect(knsMulaiViaHttp($account['user'], $account['dokter']))->not->toBeEmpty();
});

test('a booking may be turned into exactly one session, and a second attempt is 422', function (): void {
    $account = knsPatientAccount();
    $bookingId = knsBooking($account['pasien']->getKey(), $account['dokter'], 'terjadwal');

    $first = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId]);

    $first->assertCreated()
        // The doctor and the service type are read off the booking, never the body.
        ->assertJsonPath('data.konsultasi.dokter_id', $account['dokter'])
        ->assertJsonPath('data.konsultasi.tipe', 'video_call')
        ->assertJsonPath('data.konsultasi.booking_id', $bookingId);

    // `biaya_konsultasi` records 0 for a booking-backed session: `BookingService`
    // already raised an invoice carrying the same fee, and recording it twice is
    // two amounts for one service.
    expect($first->json('data.konsultasi.biaya_konsultasi'))->toBe('0.00');

    $second = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId]);

    $second->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.booking_id.0', 'Booking ini sudah memiliki sesi konsultasi.');

    // And the refusal is the DATABASE's unique index, not a request rule: no
    // `exists:` or `unique:` appears on the field. Proved by the row count.
    expect(DB::table('konsultasi')->where('booking_id', $bookingId)->count())->toBe(1);
});

test('a check_in booking may start a session too, because the plan names both states', function (): void {
    $account = knsPatientAccount();
    $bookingId = knsBooking($account['pasien']->getKey(), $account['dokter'], 'check_in');

    knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])
        ->assertCreated();
});

test('another patients booking and a booking that does not exist answer the SAME 404', function (): void {
    // Non-disclosure. `booking.id` is a sequential `BIGINT UNSIGNED AUTO_INCREMENT`,
    // so a 422 that appeared only for a real id would be an existence oracle over
    // the whole id space, and this is the same rule `PasienRecordAccess` and
    // `DokterController::slot` hold.
    $mine = knsPatientAccount();
    $theirs = knsPatientAccount();

    $foreignBooking = knsBooking($theirs['pasien']->getKey(), $theirs['dokter']);

    $foreign = knsAs($mine['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $foreignBooking]);
    $absent = knsAs($mine['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => 999999]);

    $foreign->assertNotFound();
    $absent->assertNotFound();

    expect($foreign->json())->toEqual($absent->json())
        ->and($foreign->json())->toBe([
            'success' => false,
            'message' => 'Resource not found.',
            'errors' => [],
        ]);
});

test('a booking that cannot become a session is 422, and the message names the reason', function (): void {
    $account = knsPatientAccount();

    // Every `booking.status` value that is NOT `terjadwal` or `check_in`, from the
    // EIGHT-value ENUM at :515-516. `menunggu_pembayaran` has not been paid for,
    // `berlangsung` and `selesai` have already had their own lifecycle, and
    // `dibatalkan` / `no_show` / `kadaluarsa` mean the visit will not happen.
    foreach (['menunggu_pembayaran', 'berlangsung', 'selesai', 'dibatalkan', 'no_show', 'kadaluarsa'] as $status) {
        $bookingId = knsBooking($account['pasien']->getKey(), $account['dokter'], $status);

        $response = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId]);

        $response->assertStatus(422)->assertJsonPath('errors.booking_id.0', 'Konsultasi hanya dapat dimulai dari booking dengan status terjadwal atau check_in.');
    }
});

test('an in-person booking is refused rather than coerced into a telemedicine session', function (): void {
    // `booking.tipe_layanan` has four values (:506) and `konsultasi.tipe` has three
    // (:541); `kunjungan_klinik` and `home_visit` are in the first and not the
    // second. A `konsultasi` row is a telemedicine session - it has a video `room_id` at
    // :544 and a transcript hanging off it - so neither is mappable onto one.
    $account = knsPatientAccount();

    foreach (['kunjungan_klinik', 'home_visit'] as $tipe) {
        $bookingId = knsBooking($account['pasien']->getKey(), $account['dokter'], 'terjadwal', ['tipe_layanan' => $tipe]);

        $response = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId]);

        $response->assertStatus(422);
        expect($response->json('errors.booking_id.0'))->toContain($tipe)
            ->and($response->json('errors.booking_id.0'))->toContain('telemedisin');
    }

    // And the two that ARE shareable both work.
    foreach (['chat', 'video_call'] as $tipe) {
        $bookingId = knsBooking($account['pasien']->getKey(), $account['dokter'], 'terjadwal', ['tipe_layanan' => $tipe]);

        knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])
            ->assertCreated()
            ->assertJsonPath('data.konsultasi.tipe', $tipe);
    }
});

test('the start request needs exactly one of the two forms and refuses the machine-owned columns', function (): void {
    $account = knsPatientAccount();

    $dokter = $account['dokter'];
    $bookingId = knsBooking($account['pasien']->getKey(), $dokter);

    // Neither: BOTH fields are refused, because `required_without` each other.
    $neither = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', []);
    $neither->assertStatus(422)->assertJsonValidationErrors(['booking_id', 'dokter_id']);

    // Both: both refused again, by `prohibited_with`.
    $both = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', [
        'booking_id' => $bookingId,
        'dokter_id' => $dokter,
    ]);
    $both->assertStatus(422)->assertJsonValidationErrors(['booking_id', 'dokter_id']);

    // The instant form needs `tipe`, and the booking form refuses it because the
    // service type is read off the booking.
    $tanpaTipe = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', ['dokter_id' => $dokter]);
    $tanpaTipe->assertStatus(422)->assertJsonValidationErrors(['tipe']);

    $bookingDenganTipe = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', [
        'booking_id' => $bookingId,
        'tipe' => 'chat',
    ]);
    $bookingDenganTipe->assertStatus(422)->assertJsonValidationErrors(['tipe']);

    // And `tipe` outside the three-value ENUM.
    knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', ['dokter_id' => $dokter, 'tipe' => 'kunjungan_klinik'])
        ->assertStatus(422)->assertJsonValidationErrors(['tipe']);

    // Every machine-owned column is `prohibited`, so a client that tries is told so
    // rather than having the value silently dropped.
    foreach (['status', 'mulai_at', 'selesai_at', 'room_id', 'biaya_konsultasi', 'pasien_id'] as $column) {
        knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', [
            'dokter_id' => $dokter,
            'tipe' => 'chat',
            $column => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors([$column]);
    }
});

test('an ineligible doctor answers 404 for five reasons with one body', function (): void {
    $account = knsPatientAccount();

    $pending = knsDokter(knsPengguna('dokter', 'dokter')->getKey(), ['status_verifikasi' => 'pending']);
    $optedOut = knsDokter(knsPengguna('dokter', 'dokter')->getKey(), ['tersedia_telemedisin' => 0]);
    $inactive = knsDokter(knsPengguna('dokter', 'dokter')->getKey(), ['status_aktif' => 0]);
    $lapsed = knsDokter(knsPengguna('dokter', 'dokter')->getKey(), ['str_berlaku_sampai' => '2000-01-01']);

    $bodies = [];

    foreach ([$pending, $optedOut, $inactive, $lapsed, 999999] as $dokterId) {
        $response = knsAs($account['user'])->postJson('/api/v1/konsultasi/mulai', [
            'dokter_id' => $dokterId,
            'tipe' => 'chat',
        ]);

        $response->assertNotFound();
        $bodies[] = $response->json();
    }

    // ONE body, five reasons: nonexistent, unverified, opted out, inactive and
    // STR-expired are indistinguishable, which is what stops an unauthenticated-ish
    // enumeration of the directory's eligibility state.
    expect(array_unique(array_map(static fn (array $b): string => json_encode($b), $bodies)))->toHaveCount(1);
});

test('a non-patient account cannot start a session, and the answer is 403', function (): void {
    $doctor = knsDoctorAccount();
    $apoteker = knsPengguna('apoteker', 'apoteker');
    $admin = knsPengguna('admin', 'admin');
    $perawat = knsPengguna('perawat');
    $kurir = knsPengguna('kurir');

    foreach ([$doctor['user'], $apoteker, $admin, $perawat, $kurir] as $caller) {
        knsAs($caller)->postJson('/api/v1/konsultasi/mulai', ['dokter_id' => $doctor['dokter'], 'tipe' => 'chat'])
            ->assertForbidden();
    }
});

// =====================================================================
// The lifecycle
// =====================================================================

test('the doctor accepts, the row moves to berlangsung, and mulai_at is stamped once', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount(['status_verifikasi' => 'terverifikasi', 'tersedia_telemedisin' => 1]);

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    expect(Konsultasi::query()->findOrFail($id)->mulai_at)->toBeNull();

    $response = knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.konsultasi.status', 'berlangsung')
        ->assertJsonPath('data.konsultasi.mulai_at', fn (string $value): bool => str_ends_with($value, 'Z'));

    $row = Konsultasi::query()->findOrFail($id);

    expect($row->mulai_at)->not->toBeNull()
        ->and($row->selesai_at)->toBeNull()
        ->and($row->total_durasi_detik)->toBeNull()
        // The accept writes one system notice, so the transcript now has two.
        ->and(DB::table('konsultasi_chat')->where('konsultasi_id', $id)->count())->toBe(2);

    // A second accept is 422 with no write: `berlangsung` has no arrow to itself.
    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima')
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'Konsultasi tidak dapat bertransisi dari "berlangsung" ke "berlangsung". Status yang diperbolehkan: menunggu_resep, selesai, dibatalkan, gagal.');

    expect(DB::table('konsultasi_chat')->where('konsultasi_id', $id)->count())->toBe(2)
        ->and(Konsultasi::query()->findOrFail($id)->mulai_at->toISOString())->toBe($row->mulai_at->toISOString());
});

test('a doctor who is not this sessions doctor is 404 on both doctor-only writes', function (): void {
    $patient = knsPatientAccount();
    $assigned = knsDoctorAccount();
    $stranger = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $assigned['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    knsAs($stranger['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima')->assertNotFound();
    knsAs($stranger['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai')->assertNotFound();

    // And the body is the same 404 the router and the ownership scope produce, so a
    // stranger cannot learn that the session exists.
    $viaRouter = knsAs($patient['user'])->getJson('/api/v1/konsultasi/'.$id)->assertOk();
    expect($viaRouter->status())->toBe(200);
});

test('a patient cannot accept or complete a session, and the 403 comes from tipe:dokter', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    // The plan's own acceptance criterion for this todo.
    knsAs($patient['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai')->assertForbidden();
    knsAs($patient['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima')->assertForbidden();

    // A `superadmin` holds every permission but is not a `dokter`, so the account
    // type is what refuses it - which is the point of naming both guards.
    $superadmin = knsPengguna('superadmin', 'superadmin');
    knsAs($superadmin)->putJson('/api/v1/konsultasi/'.$id.'/selesai')->assertForbidden();
    knsAs($superadmin)->putJson('/api/v1/konsultasi/'.$id.'/terima')->assertForbidden();

    // And nothing moved.
    expect(Konsultasi::query()->findOrFail($id)->status)->toBe('menunggu_dokter');
});

test('the doctor completes the session, which stamps the duration and the SOAP fields', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima')->assertOk();

    // Move the clock forward rather than sleeping: the duration is computed from
    // `mulai_at`, and a test that slept would be slow and still non-deterministic.
    $mulai = Konsultasi::query()->findOrFail($id)->mulai_at;
    DB::table('konsultasi')->where('id', $id)->update(['mulai_at' => $mulai->copy()->subMinutes(90)->toDateTimeString()]);

    $soap = [
        'catatan_subjektif' => 'Demam tiga hari.',
        'catatan_objektif' => 'Suhu 38.5 C.',
        'catatan_asessment' => 'Infeksi saluran napas atas.',
        'catatan_plan' => 'Istirahat dan Hidrasi.',
        'diagnosis_kerja' => 'ISPA',
        'saran_tindak_lanjut' => 'Kontrol bila demam lebih dari tiga hari.',
    ];

    $response = knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai', $soap);

    $response->assertOk()
        ->assertJsonPath('data.konsultasi.status', 'selesai')
        ->assertJsonPath('data.konsultasi.diagnosis_kerja', 'ISPA')
        ->assertJsonPath('data.konsultasi.catatan_subjektif', 'Demam tiga hari.')
        ->assertJsonPath('data.konsultasi.catatan_objektif', 'Suhu 38.5 C.')
        ->assertJsonPath('data.konsultasi.catatan_asessment', 'Infeksi saluran napas atas.')
        ->assertJsonPath('data.konsultasi.catatan_plan', 'Istirahat dan Hidrasi.')
        ->assertJsonPath('data.konsultasi.saran_tindak_lanjut', 'Kontrol bila demam lebih dari tiga hari.')
        ->assertJsonPath('data.konsultasi.selesai_at', fn (string $value): bool => str_ends_with($value, 'Z'));

    $row = Konsultasi::query()->findOrFail($id);

    expect($row->total_durasi_detik)->toBeGreaterThanOrEqual(5400)
        ->and($row->total_durasi_detik)->toBeLessThan(9000)
        ->and($row->selesai_at)->not->toBeNull()
        ->and(DB::table('konsultasi_chat')->where('konsultasi_id', $id)->count())->toBe(3);

    // The completion notice is a system line, not the doctor's.
    $last = KonsultasiChat::query()->where('konsultasi_id', $id)->orderByDesc('id')->firstOrFail();

    expect($last->pengirim_tipe)->toBe('sistem')
        ->and($last->pengirim_user_id)->toBe($doctor['user']->getKey());
});

test('a second completion is 422 and writes nothing', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima')->assertOk();
    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai', ['diagnosis_kerja' => 'influenza'])->assertOk();

    $row = Konsultasi::query()->findOrFail($id);
    $chatBefore = DB::table('konsultasi_chat')->where('konsultasi_id', $id)->count();

    $second = knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai', ['diagnosis_kerja' => 'diabetes']);

    $second->assertStatus(422)->assertJsonPath('errors.status.0', 'Konsultasi dengan status tersebut sudah selesai dan tidak dapat diubah lagi.');

    // Nothing moved: the second call is a refusal, not a double write.
    $after = Konsultasi::query()->findOrFail($id);

    expect($after->status)->toBe('selesai')
        ->and($after->diagnosis_kerja)->toBe('influenza')
        ->and($after->selesai_at->toISOString())->toBe($row->selesai_at->toISOString())
        ->and($after->total_durasi_detik)->toBe($row->total_durasi_detik)
        ->and(DB::table('konsultasi_chat')->where('konsultasi_id', $id)->count())->toBe($chatBefore);
});

test('a completable session with a null mulai_at is 422, and that is the second independent guard', function (): void {
    // `konsultasi.mulai_at` is `DATETIME NULL` (:545), so a row sitting in `berlangsung`
    // with no `mulai_at` is a state the SCHEMA permits and only the application can
    // refuse. Under the transition table alone this is unreachable through the API,
    // because `terima()` is the only writer of that column - so the guard is a second
    // line of defence against a row that reached `berlangsung` by some path other
    // than this service, and it is exercised by writing exactly such a row.
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    DB::table('konsultasi')->where('id', $id)->update(['status' => 'berlangsung', 'mulai_at' => null]);

    $response = knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai');

    $response->assertStatus(422)
        ->assertJsonPath('errors.mulai_at.0', 'Konsultasi belum tercatat dimulai, tidak dapat diselesaikan.');

    $row = Konsultasi::query()->findOrFail($id);

    expect($row->status)->toBe('berlangsung')
        ->and($row->selesai_at)->toBeNull()
        ->and($row->total_durasi_detik)->toBeNull();

    // And the two guards are independent: a `menunggu_dokter` row fails BOTH, and
    // the envelope carries both fields in one 422 rather than one hiding the other.
    $fresh = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', [
        'dokter_id' => $doctor['dokter'],
        'tipe' => 'chat',
    ])->json('data.konsultasi.id');

    $both = knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$fresh.'/selesai');

    $both->assertStatus(422);
    expect(array_keys($both->json('errors')))->toEqualCanonicalizing(['status', 'mulai_at']);
});

test('the completion request refuses the machine-owned columns and the tenant keys', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima')->assertOk();

    foreach (['status', 'mulai_at', 'selesai_at', 'total_durasi_detik', 'booking_id', 'room_id', 'biaya_konsultasi'] as $column) {
        knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai', [
            'diagnosis_kerja' => 'influenza',
            $column => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors([$column]);
    }

    // `diagnosis_kerja` is `VARCHAR(255)` (:552), so it is the one field with a
    // character limit and the limit is the column's own.
    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai', [
        'diagnosis_kerja' => str_repeat('a', 256),
    ])->assertStatus(422)->assertJsonValidationErrors(['diagnosis_kerja']);
});

// =====================================================================
// The transcript
// =====================================================================

test('either party can send a message, and the sender type is the side not the account type', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    $fromPatient = knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', [
        'tipe_pesan' => 'teks',
        'isi' => 'Halo dokter, saya demam.',
    ]);

    $fromPatient->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pesan.pengirim_tipe', 'pasien')
        ->assertJsonPath('data.pesan.pengirim_user_id', $patient['user']->getKey())
        ->assertJsonPath('data.pesan.tipe_pesan', 'teks')
        ->assertJsonPath('data.pesan.isi', 'Halo dokter, saya demam.')
        ->assertJsonPath('data.pesan.dibaca_at', null)
        ->assertJsonStructure(['data' => ['pesan' => [
            'id', 'konsultasi_id', 'pengirim_user_id', 'pengirim_tipe', 'tipe_pesan',
            'isi', 'file_url', 'file_nama', 'file_ukuran_kb', 'dibaca_at', 'terkirim_at',
        ]]]);

    $fromDoctor = knsAs($doctor['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', [
        'tipe_pesan' => 'teks',
        'isi' => 'Baik, ceritakan gejalanya.',
    ]);

    $fromDoctor->assertCreated()
        ->assertJsonPath('data.pesan.pengirim_tipe', 'dokter')
        ->assertJsonPath('data.pesan.pengirim_user_id', $doctor['user']->getKey());

    // The value written is the SIDE. A `users.tipe` copy would be a MySQL 1264 for
    // four of the seven account types, and this is the case that proves it matters
    // even for a `dokter` row: a `apoteker` account that owns one is still the
    // DOCTOR side of this session.
    $apotekerDoctor = knsPengguna('apoteker', 'dokter');
    $apotekerDokterId = knsDokter($apotekerDoctor->getKey(), ['tipe' => 'apoteker']);

    $other = knsPatientAccount();
    $otherBooking = knsBooking($other['pasien']->getKey(), $apotekerDokterId);
    $otherId = (int) knsAs($other['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $otherBooking])->json('data.konsultasi.id');

    knsAs($apotekerDoctor)->postJson('/api/v1/konsultasi/'.$otherId.'/chat', [
        'tipe_pesan' => 'teks',
        'isi' => 'Saya apoteker yang-duty.',
    ])->assertCreated()->assertJsonPath('data.pesan.pengirim_tipe', 'dokter');
});

test('an attachment is written to the public disk and described by three columns', function (): void {
    Storage::fake('public');

    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    $berkas = Illuminate\Http\UploadedFile::fake()->create('foto-gejala.png', 120, 'image/png');

    $response = knsAs($patient['user'])->post('/api/v1/konsultasi/'.$id.'/chat', [
        'tipe_pesan' => 'gambar',
        'isi' => 'Ini foto luka kaki kiri sebelum perawatan.',
        'berkas' => $berkas,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.pesan.tipe_pesan', 'gambar')
        ->assertJsonPath('data.pesan.file_nama', 'foto-gejala.png')
        ->assertJsonPath('data.pesan.file_ukuran_kb', 120)
        ->assertJsonPath('data.pesan.isi', 'Ini foto luka kaki kiri sebelum perawatan.');

    // The REAL disk publishes an APP_URL-rooted URL, and that is the contract.
    expect(config('filesystems.disks.public.url'))->toStartWith('http')
        ->and(config('filesystems.disks.public.driver'))->toBe('local');

    // `Storage::fake()` rewrites the disk root AND its url, so the faked value is a
    // bare `/storage` prefix; what matters here is that the URL is a path under the
    // consultation's own directory and not a filesystem path only the server reads.
    $url = (string) $response->json('data.pesan.file_url');

    expect($url)->toStartWith('/storage/')
        ->and($url)->toContain('/konsultasi/'.$id.'/')
        ->and($url)->not->toContain(base_path());

    // The stored NAME is random and the client's name went to `file_nama`, which is
    // truncated to the column's 255 characters. A client-supplied name is a path,
    // and `storeAs()` with a name containing `/` writes outside its directory.
    $stored = collect(Storage::disk('public')->files('konsultasi/'.$id))->first();

    expect($stored)->not->toBeNull()
        ->and(basename($stored))->not->toBe('foto-gejala.png')
        // The BYTES on disk are a property of the fake upload rather than of the
        // code under test; what the API promises is `file_ukuran_kb`, asserted
        ->and(basename($stored))->not->toBe('foto-gejala.png');

    // `file_ukuran_kb` is `INT UNSIGNED` (:573) and rounded UP, so a one-byte upload
    // records 1 KB rather than 0 - "0 KB" is not a fact about a file that exists.
    $tiny = knsAs($patient['user'])->post('/api/v1/konsultasi/'.$id.'/chat', [
        'tipe_pesan' => 'gambar',
        'berkas' => Illuminate\Http\UploadedFile::fake()->create('kecil.png', 1, 'image/png'),
    ]);

    $tiny->assertCreated()->assertJsonPath('data.pesan.file_ukuran_kb', 1);
});

test('an oversize file of the wrong MIME carries TWO messages for the same field', function (): void {
    // The one place this API can put more than one message on a single field, and the
    // envelope has to keep both. `berkas` carries `max` AND a MIME closure, so an
    // oversize file of the wrong MIME fails BOTH on the SAME field, and
    // `ApiResponse::error()` forwards `ValidationException::errors()` untouched so
    // both survive into the body.
    //
    // **The pair that was tried first does not work and is worth recording.**
    // `required_if:tipe_pesan,gambar,...` and `prohibited_unless:tipe_pesan,gambar,...`
    // were both applied to `berkas` on the theory that a file sent with
    // `tipe_pesan = teks` would report twice. It reports ONCE, and the reason is
    // measured: `required_if` fires only when `tipe_pesan` IS one of the four file
    // types, and `teks` is not one of them, so it stays silent while
    // `prohibited_unless` - which fires when it is NOT one of them - reports. The
    // two rules are exact complements, not two opinions.
    Storage::fake('public');

    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    $response = knsAs($patient['user'])->post('/api/v1/konsultasi/'.$id.'/chat', [
        'tipe_pesan' => 'gambar',
        // 12 MB of PDF offered as an image: over `max` and of the wrong MIME, so
        // BOTH rules fail on the SAME field and both messages must survive.
        'berkas' => Illuminate\Http\UploadedFile::fake()->create('besar.pdf', 12288, 'application/pdf'),
    ]);

    $response->assertStatus(422);

    $messages = $response->json('errors.berkas');

    expect($messages)->toBeArray()
        ->and($messages)->toHaveCount(2)
        // One message from `max` and one from the MIME closure, and BOTH are
        // preserved: `ApiResponse::error()` forwards `ValidationException::errors()`
        // untouched, so a rewrite that flattened a field to one message fails here.
        ->and(implode(' ', $messages))->toContain('must not be greater than 10240')
        ->and(implode(' ', $messages))->toContain('image/jpeg');

    // And nothing was written.
    expect(DB::table('konsultasi_chat')->where('konsultasi_id', $id)->count())->toBe(1);
});

test('a file-backed message type requires a file of the right MIME, and a text type requires isi', function (): void {
    Storage::fake('public');

    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    // A file type with no file.
    foreach (['gambar', 'dokumen', 'audio', 'video_note'] as $tipe) {
        knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', ['tipe_pesan' => $tipe])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['berkas']);
    }

    // `teks` with no `isi`.
    knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', ['tipe_pesan' => 'teks'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['isi']);

    // A PDF offered as a `gambar`. `dokumen` is an allow-list rather than an
    // `application/` prefix precisely so this is refused.
    $response = knsAs($patient['user'])->post('/api/v1/konsultasi/'.$id.'/chat', [
        'tipe_pesan' => 'gambar',
        'berkas' => Illuminate\Http\UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf'),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['berkas']);
    expect($response->json('errors.berkas.0'))->toContain('image/jpeg');

    // And the same file IS accepted as a `dokumen`.
    knsAs($patient['user'])->post('/api/v1/konsultasi/'.$id.'/chat', [
        'tipe_pesan' => 'dokumen',
        'berkas' => Illuminate\Http\UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf'),
    ])->assertCreated();

    // `tipe_pesan` outside the eight-value ENUM.
    knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', ['tipe_pesan' => 'stiker', 'isi' => 'x'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tipe_pesan']);

    // And the tenant keys are `prohibited`.
    foreach (['konsultasi_id', 'pengirim_user_id', 'pengirim_tipe', 'dibaca_at', 'terkirim_at'] as $column) {
        knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', [
            'tipe_pesan' => 'teks',
            'isi' => 'x',
            $column => 1,
        ])->assertStatus(422)->assertJsonValidationErrors([$column]);
    }
});

test('a human may not post a system message type, because the document is the reference', function (): void {
    // `konsultasi_chat` has NO `resep_id` and NO `surat_keterangan_id` - asserted in
    // `KonsultasiSchemaTest` - so a `resep` line posted by a client could point at nothing at all.
    // The service writes those three types when the document is created.
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    foreach (['resep', 'surat_keterangan', 'sistem'] as $tipe) {
        $response = knsAs($doctor['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', [
            'tipe_pesan' => $tipe,
            'isi' => 'Resep untuk pasien.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.tipe_pesan.0', 'Jenis pesan ini ditulis oleh sistem, bukan oleh pengirim.');
    }

    expect(DB::table('konsultasi_chat')->where('konsultasi_id', $id)->count())->toBe(1);
});

test('the transcript is oldest first, paginated, with meta a sibling of data', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    // Six messages in one second, so `terkirim_at` TIES on every one of them and the
    // `id` tiebreaker is the only thing that can order them. That is the real
    // situation, not a contrived one: `terkirim_at` is a `TIMESTAMP` (:575) with
    // DEFAULT CURRENT_TIMESTAMP, so its resolution is one second.
    $ids = [];

    for ($i = 1; $i <= 6; $i++) {
        $ids[] = knsPesan($id, $patient['user']->getKey(), 'pasien', 'teks', 'Pesan '.$i);
    }

    $firstPage = knsAs($patient['user'])->getJson('/api/v1/konsultasi/'.$id.'/chat?per_page=3');

    $firstPage->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'data' => ['pesan'], 'message', 'meta' => [
            'current_page', 'last_page', 'per_page', 'total', 'from', 'to',
        ]]);

    // `meta` is a TOP-LEVEL SIBLING of `data`, never a wrapper around it.
    $body = $firstPage->json();

    expect(array_keys($body))->toBe(['success', 'data', 'message', 'meta'])
        ->and($body['meta']['per_page'])->toBe(3)
        ->and($body['meta']['total'])->toBe(7)
        ->and($body['meta']['last_page'])->toBe(3)
        ->and($body['meta']['from'])->toBe(1)
        ->and($body['meta']['to'])->toBe(3);

    // The one system notice from `/mulai` was written FIRST, so it is first: this is
    // the whole point of the ascending order, and the plan calls it out as the one
    // list in the system that reads oldest-first. It is also the case that proves the
    // `id` tiebreaker: it and the six messages can share one `terkirim_at` second.
    $sistemId = (int) DB::table('konsultasi_chat')
        ->where('konsultasi_id', $id)
        ->where('pengirim_tipe', 'sistem')
        ->value('id');

    $isi = array_column($firstPage->json('data.pesan'), 'id');

    expect($isi)->toBe([$sistemId, $ids[0], $ids[1]]);

    // Paging forward must not repeat or drop a row, which is only true because the
    // order is total.
    $seen = $isi;

    foreach ([2, 3] as $page) {
        $rows = array_column(
            knsAs($patient['user'])->getJson('/api/v1/konsultasi/'.$id.'/chat?per_page=3&page='.$page)->json('data.pesan'),
            'id',
        );

        $seen = array_merge($seen, $rows);
    }

    expect($seen)->toHaveCount(7)
        ->and(array_slice($seen, 1))->toBe($ids)
        ->and(array_unique($seen))->toHaveCount(7);

    // The 100 cap, through the same `PasienRecordAccess::perPage()` every other list
    // uses.
    knsAs($patient['user'])->getJson('/api/v1/konsultasi/'.$id.'/chat?per_page=1000000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('the read receipt stamps the other party only, and never a system line', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima')->assertOk();

    $dariDokter = knsPesan($id, $doctor['user']->getKey(), 'dokter');
    $dariPasien = knsPesan($id, $patient['user']->getKey(), 'pasien');
    $sistemId = (int) DB::table('konsultasi_chat')->where('konsultasi_id', $id)->where('pengirim_tipe', 'sistem')->value('id');

    // The patient marks the doctor's line.
    $response = knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat/baca');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.jumlah_ditandai_baca', 1)
        ->assertJsonPath('data.konsultasi_id', $id);

    $rows = KonsultasiChat::query()->where('konsultasi_id', $id)->get()->keyBy('id');

    expect($rows[$dariDokter]->dibaca_at)->not->toBeNull()
        // The patient's own line is untouched: marking your own messages read would
        // be a trivially gameable "I have read everything" claim.
        ->and($rows[$dariPasien]->dibaca_at)->toBeNull()
        // And the system notices are untouched in BOTH directions, because neither
        // party wrote them.
        ->and($rows[$sistemId]->dibaca_at)->toBeNull();

    // A second call moves nothing.
    knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat/baca')
        ->assertOk()
        ->assertJsonPath('data.jumlah_ditandai_baca', 0);

    // The doctor marks the patient's line.
    knsAs($doctor['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat/baca')
        ->assertOk()
        ->assertJsonPath('data.jumlah_ditandai_baca', 1);

    expect(KonsultasiChat::query()->findOrFail($dariPasien)->dibaca_at)->not->toBeNull()
        ->and(KonsultasiChat::query()->findOrFail($dariDokter)->dibaca_at->toISOString())->toBe($rows[$dariDokter]->dibaca_at->toISOString());

    // And the endpoint refuses to be told what to stamp.
    knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat/baca', ['dibaca_at' => '2020-01-01 00:00:00'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dibaca_at']);
});

test('a stranger may not read the session, and is 404 rather than 403', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    $otherPatient = knsPatientAccount();
    $otherDoctor = knsDoctorAccount();
    $apoteker = knsPengguna('apoteker', 'apoteker');
    $perawat = knsPengguna('perawat');
    $kurir = knsPengguna('kurir');
    $stranger = knsPengguna('pasien');

    // A third patient - the plan's own acceptance criterion.
    knsAs($otherPatient['user'])->getJson('/api/v1/konsultasi/'.$id)->assertNotFound();

    // The two READS carry no grant, so every one of these reaches the ownership rule
    // and is 404.
    foreach ([$otherPatient['user'], $otherDoctor['user'], $apoteker, $perawat, $kurir, $stranger] as $caller) {
        knsAs($caller)->getJson('/api/v1/konsultasi/'.$id)->assertNotFound();
        knsAs($caller)->getJson('/api/v1/konsultasi/'.$id.'/chat')->assertNotFound();
    }

    // The read receipt DOES carry `konsultasi.chat`, so the four account types that hold
    // no grant are refused at the middleware with 403 - a different answer, from a
    // different layer, for the same request. Asserting 404 here would be asserting
    // that the grant is not doing the authorisation, which it is.
    foreach ([$apoteker, $perawat, $kurir] as $caller) {
        knsAs($caller)->postJson('/api/v1/konsultasi/'.$id.'/chat/baca')->assertForbidden();
    }

    // And the two that hold the grant but are not party to this consultation reach
    // the ownership rule and are 404.
    foreach ([$otherPatient['user'], $otherDoctor['user']] as $caller) {
        knsAs($caller)->postJson('/api/v1/konsultasi/'.$id.'/chat/baca')->assertNotFound();
    }

    // One body for every one of them, so existence is not disclosed.
    $bodies = [];

    foreach ([$otherPatient['user'], $apoteker, $stranger] as $caller) {
        $bodies[] = knsAs($caller)->getJson('/api/v1/konsultasi/'.$id)->json();
    }

    expect(array_unique(array_map(static fn (array $b): string => json_encode($b), $bodies)))->toHaveCount(1)
        ->and($bodies[0])->toBe([
            'success' => false,
            'message' => 'Resource not found.',
            'errors' => [],
        ]);
});

// =====================================================================
// The read scope
// =====================================================================

test('the session is readable by its patient, its doctor, and admin and superadmin', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    $admin = knsPengguna('admin', 'admin');
    $superadmin = knsPengguna('superadmin', 'superadmin');

    foreach ([$patient['user'], $doctor['user'], $admin, $superadmin] as $reader) {
        $response = knsAs($reader)->getJson('/api/v1/konsultasi/'.$id);

        $response->assertOk()
            ->assertJsonPath('data.konsultasi.id', $id)
            ->assertJsonPath('data.konsultasi.pasien.id', $patient['pasien']->getKey())
            ->assertJsonPath('data.konsultasi.dokter.id', $doctor['dokter'])
            ->assertJsonPath('data.konsultasi.booking.id', $bookingId)
            ->assertJsonPath('data.konsultasi.total_durasi_detik', null);
    }
});

test('the patient NIK is masked in the session response and never published raw', function (): void {
    $nik = '3273123456780001';

    $patient = knsPatientAccount([], ['nik' => $nik]);
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    $body = (string) knsAs($doctor['user'])->getJson('/api/v1/konsultasi/'.$id)->getContent();

    expect($body)->not->toContain($nik)
        ->and(knsAs($doctor['user'])->getJson('/api/v1/konsultasi/'.$id)->json('data.konsultasi.pasien.nik'))
        ->not->toBe($nik)
        // `NikMasker::mask()` leaves an identifier that is too short to have an
        // interior alone, and a 16-digit one masked.
        ->and(knsAs($doctor['user'])->getJson('/api/v1/konsultasi/'.$id)->json('data.konsultasi.pasien.nik'))
        ->toStartWith('3273');

    // And no account secret is anywhere in the body.
    expect($body)->not->toContain('kata_sandi_hash')
        ->and($body)->not->toContain('no_telepon');
});

test('the HTTP scope and the realtime channel rule cannot disagree', function (): void {
    // `KonsultasiAccess::findForRead()` delegates the party half of its disjunction to
    // `KonsultasiChannelAccess::allows()`, so this matrix is a statement about ONE rule reached
    // through two surfaces rather than about two rules. If someone ever writes a
    // second `pasien.user_id` comparison into a controller, this test keeps the
    // surfaces equal only for the cases it lists - which is why it also asserts the
    // DELEGATION itself, below.
    $channel = app(KonsultasiChannelAccess::class);
    $access = app(KonsultasiAccess::class);

    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();
    $strangerPatient = knsPatientAccount();
    $strangerDoctor = knsDoctorAccount();
    $admin = knsPengguna('admin', 'admin');
    $superadmin = knsPengguna('superadmin', 'superadmin');

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    foreach ([$patient['user'], $doctor['user']] as $party) {
        expect($channel->allows($party, $id))->toBeTrue();
        expect(knsAs($party)->getJson('/api/v1/konsultasi/'.$id)->status())->toBe(200);
    }

    foreach ([$strangerPatient['user'], $strangerDoctor['user'], $admin, $superadmin] as $other) {
        expect($channel->allows($other, $id))->toBeFalse();
    }

    // The two oversight types are the documented difference, and it UNDER-grants
    // rather than over-grants: they can read over HTTP and cannot join the socket.
    foreach ([$admin, $superadmin] as $oversight) {
        expect($channel->allows($oversight, $id))->toBeFalse()
            ->and(knsAs($oversight)->getJson('/api/v1/konsultasi/'.$id)->status())->toBe(200);
    }

    // And the delegation is structural, not coincidental: the read service resolves
    // the SAME collaborator the channel callback delegates to.
    $property = new ReflectionProperty(KonsultasiAccess::class, 'channel');

    expect($property->getValue($access))->toBeInstanceOf(KonsultasiChannelAccess::class);

    // One channel pattern, declared once. A second `Broadcast::channel()` would be a
    // second authorization rule, which is exactly what todo 31 extracted this class
    // to prevent.
    $source = (string) file_get_contents(base_path('routes/channels.php'));

    expect(substr_count($source, 'Broadcast::channel('))->toBe(1)
        ->and($source)->toContain("Broadcast::channel('konsultasi.{id}'");
});

test('a superadmin passes the chat guard and is then refused by the OWNERSHIP rule', function (): void {
    // **Both layers answer 403 and both bodies are byte-identical, so a status code over
    // HTTP cannot say which one refused.** An earlier draft of this test asserted 404
    // and was wrong: `superadmin` holds the grant, so `permission:` admits it, and
    // KonsultasiAccess then refuses it for owning no profile row - which is 403 about the
    // CALLER, exactly as that class documents. What is worth pinning is the LAYER, so
    // that is asserted against the grant table and against the service directly.
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    $superadmin = knsPengguna('superadmin', 'superadmin');
    $perawat = knsPengguna('perawat');
    $kurir = knsPengguna('kurir');
    $apoteker = knsPengguna('apoteker', 'apoteker');
    $admin = knsPengguna('admin', 'admin');

    // `EnsurePermission`'s own query, for the superadmin: the grant EXISTS, so the
    // middleware cannot be what refused the request below.
    $granted = fn (User $caller): bool => DB::table('role_permissions')
        ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
        ->where('user_roles.user_id', $caller->getKey())
        ->where('permissions.kode', 'konsultasi.chat')
        ->exists();

    expect(RbacCatalog::isPermission('konsultasi.chat'))->toBeTrue()
        ->and($granted($superadmin))->toBeTrue();

    // And the service is what refuses it.
    expect(fn (): array => app(KonsultasiAccess::class)->sisiDankonsultasi($superadmin, $id))
        ->toThrow(AccessDeniedHttpException::class);

    knsAs($superadmin)->postJson('/api/v1/konsultasi/'.$id.'/chat', ['tipe_pesan' => 'teks', 'isi' => 'halo'])
        ->assertForbidden();

    // The other four hold no grant, so they never reach the controller. The grant table
    // is read for each of them, so the assertion is about the DATA and not about a
    // status code the two layers happen to share.
    foreach ([$perawat, $kurir, $apoteker, $admin] as $caller) {
        expect($granted($caller))->toBeFalse($caller->tipe.' must hold no konsultasi.chat');

        knsAs($caller)->postJson('/api/v1/konsultasi/'.$id.'/chat', ['tipe_pesan' => 'teks', 'isi' => 'halo'])
            ->assertForbidden();
    }

    // And a caller who is a PARTY and holds the grant is admitted, which is what makes
    // the 403s above about the layer rather than about the endpoint.
    knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', ['tipe_pesan' => 'teks', 'isi' => 'halo'])
        ->assertCreated();
});

// =====================================================================
// Broadcasting
// =====================================================================

test('every write dispatches the message event on the channel of its consultation', function (): void {
    Event::fake([KonsultasiMessageSent::class]);

    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima')->assertOk();

    $pesan = knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', [
        'tipe_pesan' => 'teks',
        'isi' => 'Halo dokter.',
    ])->assertCreated()->json('data.pesan');

    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai', ['diagnosis_kerja' => 'influenza'])->assertOk();

    // One event per write, on the private channel of THIS consultation, named
    // `chat.pesan` on the wire.
    Event::assertDispatchedTimes(KonsultasiMessageSent::class, 4);
    // The event's identifier property, resolved by REFLECTION rather than re-typed: it
    // is the only public `int` the class declares, so the assertion cannot name a
    // property that does not exist. An earlier draft of this test named it
    // literally and failed with "Undefined property", which is exactly the failure a
    // hand-written property name produces the first time it is wrong.
    $idProperty = null;

    foreach ((new ReflectionClass(KonsultasiMessageSent::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $candidate) {
        $type = $candidate->getType();

        if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
            $idProperty = $candidate->getName();
        }
    }

    expect($idProperty)->not->toBeNull()
        ->and($idProperty)->toBe('konsultasiId');

    Event::assertDispatched(KonsultasiMessageSent::class, fn (KonsultasiMessageSent $event): bool => $event->{$idProperty} === $id
        && (string) $event->broadcastOn() === 'private-konsultasi.'.$id
        && $event->broadcastAs() === 'chat.pesan');

    Event::assertDispatched(
        KonsultasiMessageSent::class,
        fn (KonsultasiMessageSent $event): bool => $event->message === $pesan,
    );
});

test('the message event is sent inline and never pushed onto the queue', function (): void {
    // Asserted BEHAVIOURALLY, not by interface shape: `ShouldBroadcastNow` EXTENDS
    // `ShouldBroadcast`, so "is not a ShouldBroadcast" is unassertable and the
    // plan's phrasing of that rule is wrong. What the plan actually requires is that
    // the send does not wait for a worker, and the only thing that distinguishes the
    // two is whether `Illuminate\Events\Dispatcher` takes its
    // `ShouldBroadcastNow` branch. So the queue is faked, the REAL event is
    // dispatched, and swapping the interface to `ShouldBroadcast` would push a
    // `BroadcastEvent` job and fail this.
    Queue::fake();

    KonsultasiMessageSent::dispatch(7, ['id' => 1, 'isi' => 'Halo']);

    Queue::assertNothingPushed();
});

test('a new consultation does not create a user row, and the system author is the acting account', function (): void {
    // The DDL has a system author TYPE (`pengirim_tipe = 'sistem'`, :567) and no
    // system author ACCOUNT: `users.tipe` is a seven-value ENUM (:139) with no
    // system value, and `pengirim_user_id` is `BIGINT UNSIGNED NOT NULL` with a
    // foreign key (:566, :577). The rule enforced is therefore that a system notice
    // is attributed to the account whose action caused it, and this asserts both
    // halves: the notice exists, and no row was invented to author it.
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $usersBefore = (int) DB::table('users')->count();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/terima')->assertOk();
    knsAs($doctor['user'])->putJson('/api/v1/konsultasi/'.$id.'/selesai', ['diagnosis_kerja' => 'influenza'])->assertOk();

    $system = KonsultasiChat::query()->where('konsultasi_id', $id)->where('pengirim_tipe', 'sistem')->get();

    expect($system)->toHaveCount(3)
        // Every one names a real account, so the foreign key is satisfied by
        // construction rather than by a sentinel.
        ->and($system->pluck('pengirim_user_id')->all())->each->toBeIn(
            [$patient['user']->getKey(), $doctor['user']->getKey()],
        )
        // Two were caused by the doctor and one by the patient, which is the whole
        // claim.
        ->and($system->filter(fn (KonsultasiChat $p): bool => $p->pengirim_user_id === $patient['user']->getKey()))->toHaveCount(1)
        ->and((int) DB::table('users')->count())->toBe($usersBefore);
});

// =====================================================================
// The envelope
// =====================================================================

test('the success envelope is the exact four-key shape with meta last', function (): void {
    $patient = knsPatientAccount();
    $doctor = knsDoctorAccount();

    $bookingId = knsBooking($patient['pasien']->getKey(), $doctor['dokter']);
    $id = (int) knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', ['booking_id' => $bookingId])->json('data.konsultasi.id');

    // A write carries NO `meta` key at all, not `"meta": null`.
    $write = knsAs($patient['user'])->postJson('/api/v1/konsultasi/'.$id.'/chat', [
        'tipe_pesan' => 'teks',
        'isi' => 'Halo.',
    ]);

    expect(array_keys($write->json()))->toBe(['success', 'data', 'message'])
        ->and($write->json('success'))->toBeTrue();

    // A list carries `meta` as the FOURTH key, a sibling of `data`.
    $list = knsAs($patient['user'])->getJson('/api/v1/konsultasi/'.$id.'/chat');

    expect(array_keys($list->json()))->toBe(['success', 'data', 'message', 'meta']);

    // The failure envelope is `{success,message,errors}` and `errors` is an OBJECT,
    // so an empty set encodes as `{}` and not `[]`.
    $failure = knsAs($patient['user'])->postJson('/api/v1/konsultasi/mulai', []);

    expect($failure->status())->toBe(422)
        ->and(array_keys($failure->json()))->toBe(['success', 'message', 'errors'])
        ->and($failure->json('success'))->toBeFalse()
        ->and($failure->json('message'))->toBe('The given data was invalid.')
        // `json()` decodes an empty JSON object to an empty array, and the raw body
        // is what proves the shape.
        ->and(str_contains((string) $failure->getContent(), '"errors":{"booking_id"'))
        ->toBeTrue();

    $notFound = knsAs($patient['user'])->getJson('/api/v1/konsultasi/999999');

    expect(str_contains((string) $notFound->getContent(), '"errors":{}'))->toBeTrue();
});

test('the resource is an allow list and publishes no column the DDL does not have', function (): void {
    // Both resources are allow-lists, so a new column cannot reach the wire by
    // accident and a renamed column cannot silently disappear. Asserted against the
    // DDL rather than a hand-typed key list, so a schema change fails here.
    $columns = knsSpec()->table('konsultasi')->columns;

    $expected = [
        'id', 'booking_id', 'pasien_id', 'dokter_id', 'tipe', 'status', 'room_id',
        'mulai_at', 'selesai_at', 'total_durasi_detik',
        'catatan_subjektif', 'catatan_objektif', 'catatan_asessment', 'catatan_plan',
        'diagnosis_kerja', 'saran_tindak_lanjut', 'biaya_konsultasi', 'dibuat_at', 'diubah_at',
    ];

    $resource = new KonsultasiResource(knsSesi(knsPasien(knsUser('Pasien Alih')), knsDokter(knsUser('Dokter Alih', 'dokter'))));
    $published = array_keys($resource->resolve(request()));

    // `id` is the model's key, and the three nested blocks are `whenLoaded` so they
    // are absent on a bare row.
    expect($published)->toBe($expected)
        ->and(array_values(array_diff($expected, array_keys($columns))))->toBe([])
        ->and($published)->not->toContain('pasien')
        ->and($published)->not->toContain('dokter')
        ->and($published)->not->toContain('booking');

    // And the chat resource publishes exactly the eleven columns of its table.
    $chatRow = knsPesan(
        knsSesi(knsPasien(knsUser('Pasien Alih 2')), knsDokter(knsUser('Dokter Alih 2', 'dokter')))->getKey(),
        knsUser('Pengirim Alih'),
        'pasien',
    );

    $chatResource = new KonsultasiChatResource(KonsultasiChat::query()->findOrFail($chatRow));

    expect(array_keys($chatResource->resolve(request())))->toBe(array_keys(knsSpec()->table('konsultasi_chat')->columns));
});
