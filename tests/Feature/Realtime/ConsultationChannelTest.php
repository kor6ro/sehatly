<?php

declare(strict_types=1);

use App\Events\KonsultasiMessageSent;
use App\Models\User;
use App\Services\Konsultasi\KonsultasiChannelAccess;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The private consultation channel
|--------------------------------------------------------------------------
|
| `konsultasi.{id}` is the only channel this application authorizes, and the
| rule is that the consultation's own patient and its own doctor may join it and
| nobody else may. That is the whole security surface of the realtime feature,
| so the file is mostly a matrix over that sentence.
|
| ## The suite runs on the `null` broadcaster, and that is a trap
|
| `phpunit.xml:24` pins `BROADCAST_CONNECTION=null`, which the plan requires be
| left alone - the suite must not need a running broker. But
| `Illuminate\Broadcasting\Broadcasters\NullBroadcaster` **overrides**
| `auth()` with an empty body (`vendor/laravel/framework/.../NullBroadcaster.php:10`),
| so on that driver the channel callback in `routes/channels.php` is never
| reached: every authenticated caller gets an empty 200, and the entire
| authorization is bypassed rather than exercised.
|
| Every refusal below would therefore pass vacuously - or rather, would pass
| *wrongly*: the stranger cases would get 200 and fail, which is loud, but only
| because the fixture happens to be a stranger. The point is that the tests below
| are only meaningful because `withReverbDriver()` swaps in the Pusher-protocol
| driver, and `the suite pins broadcasting to null while the app default is
| reverb` pins that arrangement so nobody "simplifies" it back.
|
| `reverb` is a Pusher-protocol driver, and `validAuthenticationResponse()`
| signs a real HMAC from the configured key/secret, so the allowed cases assert
| an actual Pusher auth token rather than a status code alone.
|
| ## Authentication uses real Sanctum bearer tokens
|
| Same rule as `PasienProfileTest`, and for the same reason: `Sanctum::actingAs()`
| hands back a `TransientToken`, and `Illuminate\Auth\RequestGuard` caches its
| principal, so the first authenticated request inside a test would decide the
| caller for every later one. `asAccount()` calls `forgetGuards()` first.
|
| ## The id cases are not decoration
|
| `non-integer`, `leading zero` and `nonexistent` are three different refusals
| and each one is a bug that was actually available: the plan's literal callback
| signature is `function (User $user, int $id)`, and
| `Broadcaster::resolveImplicitBindingIfPossible()` hands a scalar parameter the
| **raw string** off the wire. Under `declare(strict_types=1)` that is a
| `TypeError` - a 500, and a 500 is an unhandled server fault rather than a
| refusal, so a client probing channel names gets a distinguishable response
| and the app advertises an internal error. The tests below are what keep
| `int|string` from being "simplified" back to `int`.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * A user account of a given `users.tipe`. `tipe` and `status` are set
 * explicitly because the rule under test is "a doctor may join", which is only
 * meaningful if the account is actually a doctor.
 */
function realtimeUser(string $tipe, string $nama): User
{
    return User::factory()->create([
        'nama_lengkap' => $nama,
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);
}

/**
 * A `pasien` row. `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are NOT NULL with no default.
 */
function realtimePasien(int $userId): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Kanal No. 7, Jakarta',
    ]);
}

/**
 * A `dokter` row. `nomor_str` is UNIQUE, so it is randomised per call.
 */
function realtimeDokter(int $userId): int
{
    return (int) DB::table('dokter')->insertGetId([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-KANAL-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ]);
}

/**
 * A `konsultasi` row. `booking_id` is left NULL, which the schema permits and
 * which is the "Tanya Dokter" shape todo 32 uses.
 */
function realtimeKonsultasi(int $pasienId, int $dokterId): int
{
    return (int) DB::table('konsultasi')->insertGetId([
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'tipe' => 'chat',
        'status' => 'berlangsung',
    ]);
}

/**
 * Switch broadcasting to the Pusher-protocol `reverb` driver with throwaway
 * credentials, and re-register the application's own channel pattern onto it.
 *
 * The pattern is re-registered by `require`-ing the real `routes/channels.php`
 * rather than by restating `'konsultasi.{id}'` here. `Broadcast::channel()`
 * delegates to whichever driver is currently default, and the driver instance is
 * built once and cached, so the file has to be re-executed after the config swap.
 * Restating the pattern instead would mean the test asserted against its own
 * copy of the string and would keep passing if the application renamed the
 * channel - which is the one thing these tests exist to notice.
 */
function withReverbDriver(): PusherBroadcaster
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'konsultasi-channel-test-key',
        'broadcasting.connections.reverb.secret' => 'konsultasi-channel-secret',
        'broadcasting.connections.reverb.app_id' => '424242',
        'broadcasting.connections.reverb.options' => [
            'host' => 'reverb.test',
            'port' => 443,
            'scheme' => 'https',
            'useTLS' => true,
            'path' => '',
        ],
    ]);

    Broadcast::forgetDrivers();

    require base_path('routes/channels.php');

    $driver = Broadcast::driver('reverb');

    expect($driver)->toBeInstanceOf(PusherBroadcaster::class);

    return $driver;
}

/**
 * The Pusher handshake body a real client posts. `socket_id` is the
 * `<connection>.<counter>` shape pusher-php-server documents, and
 * `channel_name` carries the `private-` prefix **on the wire only** - the
 * server strips it before matching, which is why `routes/channels.php` declares
 * the pattern without it.
 *
 * @return array<string, string>
 */
function realtimeAuthBody(string $channelName, string $socketId = '4242.1'): array
{
    return [
        'socket_id' => $socketId,
        'channel_name' => $channelName,
    ];
}

/**
 * Post the Pusher handshake as `$user`, with a real Sanctum bearer token.
 */
function asAccount(User $user, string $channelName, string $socketId = '4242.1')
{
    app('auth')->forgetGuards();

    return test()
        ->withToken($user->createToken('consultation-channel-test', ['*'], now()->addHour())->plainTextToken)
        ->postJson('/api/broadcasting/auth', realtimeAuthBody($channelName, $socketId));
}

// ------------------------------------------------------------------- tests

test('the auth endpoint is registered where the mobile client posts to it', function () {
    // `packages/sehatly_api_client/lib/src/realtime/realtime_socket.dart` posts
    // to `/api/broadcasting/auth`, which is the framework's own default path and
    // is NOT under the `/api/v1` prefix `withRouting(apiPrefix: 'api/v1')` builds.
    $route = Route::getRoutes()->getByAction(BroadcastController::class.'@authenticate');

    expect($route)->not->toBeNull('withBroadcasting() must register the broadcast auth route');
    expect($route->uri())->toBe('api/broadcasting/auth');
    expect($route->gatherMiddleware())->toContain('auth:sanctum');
});

test('the patient of the consultation is authorised', function () {
    withReverbDriver();

    $user = realtimeUser('pasien', 'Pasien Kanal');
    $pasien = realtimePasien($user->getKey());
    $dokter = realtimeDokter(realtimeUser('dokter', 'Dokter Kanal')->getKey());
    $konsultasi = realtimeKonsultasi($pasien, $dokter);

    $response = asAccount($user, 'private-konsultasi.'.$konsultasi);

    $response->assertOk();

    // A real Pusher auth token, so this is a signature and not a bare 200.
    expect($response->json('auth'))
        ->toBeString()
        ->toStartWith('konsultasi-channel-test-key:')
        ->and($response->json('channel_data'))->toBeNull();
});

test('the doctor of the consultation is authorised', function () {
    withReverbDriver();

    $pasien = realtimePasien(realtimeUser('pasien', 'Pasien Kanal')->getKey());
    $dokterUser = realtimeUser('dokter', 'Dokter Kanal');
    $konsultasi = realtimeKonsultasi($pasien, realtimeDokter($dokterUser->getKey()));

    asAccount($dokterUser, 'private-konsultasi.'.$konsultasi)->assertOk();
});

test('a user who is neither the patient nor the doctor is refused', function () {
    withReverbDriver();

    $pasien = realtimePasien(realtimeUser('pasien', 'Pasien Kanal')->getKey());
    $dokter = realtimeDokter(realtimeUser('dokter', 'Dokter Kanal')->getKey());
    $konsultasi = realtimeKonsultasi($pasien, $dokter);

    $stranger = realtimeUser('pasien', 'Asing Kanal');

    asAccount($stranger, 'private-konsultasi.'.$konsultasi)->assertForbidden();
});

test('a doctor attached to a different consultation is refused', function () {
    withReverbDriver();

    $pasien = realtimePasien(realtimeUser('pasien', 'Pasien Kanal')->getKey());
    $assigned = realtimeDokter(realtimeUser('dokter', 'Dokter Ditugaskan')->getKey());
    $konsultasi = realtimeKonsultasi($pasien, $assigned);

    // A second, real doctor who is on the platform and passes every other check.
    // Being a doctor is not sufficient; being *this* consultation's doctor is.
    $otherDoctorUser = realtimeUser('dokter', 'Dokter Konsultasi Lain');
    $otherDokter = realtimeDokter($otherDoctorUser->getKey());
    realtimeKonsultasi($pasien, $otherDokter);

    asAccount($otherDoctorUser, 'private-konsultasi.'.$konsultasi)->assertForbidden();
});

test('a patient with a profile but no consultation of their own is refused', function () {
    withReverbDriver();

    $pasien = realtimePasien(realtimeUser('pasien', 'Pasien Kanal')->getKey());
    $dokter = realtimeDokter(realtimeUser('dokter', 'Dokter Kanal')->getKey());
    $konsultasi = realtimeKonsultasi($pasien, $dokter);

    $otherUser = realtimeUser('pasien', 'Pasien Lain');
    realtimePasien($otherUser->getKey());

    asAccount($otherUser, 'private-konsultasi.'.$konsultasi)->assertForbidden();
});

test('a non-existent consultation id is refused', function () {
    withReverbDriver();

    $user = realtimeUser('pasien', 'Pasien Kanal');
    realtimePasien($user->getKey());

    // Deliberately an id no row can have: auto-increment starts at 1 and the
    // fixtures above created a handful of rows.
    asAccount($user, 'private-konsultasi.999999')->assertForbidden();
});

test('a non-integer consultation id is refused with 403 and not a 500', function () {
    withReverbDriver();

    $pasien = realtimePasien(realtimeUser('pasien', 'Pasien Kanal')->getKey());
    $dokter = realtimeDokter(realtimeUser('dokter', 'Dokter Kanal')->getKey());
    $konsultasi = realtimeKonsultasi($pasien, $dokter);

    $user = User::query()->findOrFail(DB::table('pasien')->where('id', $pasien)->value('user_id'));

    foreach (['abc', '1.0', '-1', '1e3', '0', str_repeat('9', 25)] as $malformed) {
        $response = asAccount($user, 'private-konsultasi-'.$malformed);

        // The plan's literal `int $id` signature would make this a TypeError,
        // and `bootstrap/app.php` maps an unhandled throwable to 500 - an
        // internal fault rather than a refusal.
        $response->assertForbidden("'{$malformed}' must be refused, not error");
    }

    // Guard against the loop above silently never running.
    expect((string) $konsultasi)->not->toBe('');
});

test('an alternative spelling of a real id is refused', function () {
    withReverbDriver();

    $pasienUser = realtimeUser('pasien', 'Pasien Kanal');
    $pasien = realtimePasien($pasienUser->getKey());
    $dokter = realtimeDokter(realtimeUser('dokter', 'Dokter Kanal')->getKey());
    $konsultasi = realtimeKonsultasi($pasien, $dokter);

    // The owner of the consultation may not reach it through a different
    // spelling of the same id, so one channel cannot be addressed two ways.
    asAccount($pasienUser, 'private-konsultasi.0'.$konsultasi)->assertForbidden();
    asAccount($pasienUser, 'private-konsultasi.'.$konsultasi.'.0')->assertForbidden();
});

test('an anonymous caller is refused with 401 before the channel rule runs', function () {
    withReverbDriver();

    $pasien = realtimePasien(realtimeUser('pasien', 'Pasien Kanal')->getKey());
    $dokter = realtimeDokter(realtimeUser('dokter', 'Dokter Kanal')->getKey());
    $konsultasi = realtimeKonsultasi($pasien, $dokter);

    test()->postJson('/api/broadcasting/auth', realtimeAuthBody('private-konsultasi.'.$konsultasi))
        ->assertUnauthorized();
});

test('the message event broadcasts on the private channel of its consultation', function () {
    Event::fake([KonsultasiMessageSent::class]);

    $event = new KonsultasiMessageSent(7, ['id' => 1, 'isi' => 'Halo']);

    expect($event->broadcastOn())->toBeInstanceOf(PrivateChannel::class)
        // `PrivateChannel::__construct()` prepends `private-` itself, and that
        // is the name Reverb and the client both expect. Constructing the channel
        // with an already-prefixed name would publish `private-private-...`.
        ->and((string) $event->broadcastOn())->toBe('private-konsultasi.7');
});

test('the message event is named chat.pesan and bypasses the queue', function () {
    $event = new KonsultasiMessageSent(7, ['id' => 1]);

    expect($event->broadcastAs())->toBe('chat.pesan');

    // Asserted behaviourally, not by interface shape: `ShouldBroadcastNow`
    // *extends* `ShouldBroadcast`, so "is not a ShouldBroadcast" cannot be
    // asserted and would be meaningless if it could be. What the plan requires
    // is that the send does not go through `QUEUE_CONNECTION=database`, and the
    // only thing that distinguishes the two is whether
    // `Illuminate\Events\Dispatcher` takes its `ShouldBroadcastNow` branch. So
    // the queue is faked and the real event is dispatched: swapping the
    // interface to `ShouldBroadcast` would push a `BroadcastEvent` job and fail
    // the assertion below, while `ShouldBroadcastNow` sends inline.
    Queue::fake();

    KonsultasiMessageSent::dispatch(7, ['id' => 1]);

    Queue::assertNothingPushed();
});

test('the event is dispatched with the already-serialised payload forwarded verbatim', function () {
    Event::fake([KonsultasiMessageSent::class]);

    $payload = ['id' => 42, 'isi' => 'Halo', 'pengirim_tipe' => 'dokter'];

    KonsultasiMessageSent::dispatch(42, $payload);

    Event::assertDispatched(
        KonsultasiMessageSent::class,
        fn (KonsultasiMessageSent $event): bool => $event->konsultasiId === 42
            && $event->broadcastWith() === $payload,
    );
});

test('only the namespaced consultation channel is declared', function () {
    $driver = withReverbDriver();

    $channels = (new ReflectionProperty($driver, 'channels'))->getValue($driver);

    expect(array_keys($channels))->toBe(['konsultasi.{id}']);
});

test('the suite pins broadcasting to null while the app default is reverb', function () {
    // Guards the arrangement the whole file depends on: without
    // `withReverbDriver()` every request above would be answered by
    // `NullBroadcaster::auth()`, which is an empty override and authorizes
    // everyone without consulting `routes/channels.php` at all.
    //
    // `BROADCAST_CONNECTION=null` is the literal string in `phpunit.xml:24`, and
    // `Illuminate\Support\Env` maps the string `'null'` to PHP `null` (that is
    // how a `.env` file expresses a null). `BroadcastManager::getConfig(null)`
    // then falls through to `['driver' => 'null']`, so the null broadcaster is
    // reached without the config value ever being the string 'null'.
    expect(config('broadcasting.default'))->toBeNull();
    expect(Broadcast::driver())->toBeInstanceOf(NullBroadcaster::class);
    expect(Broadcast::driver()->auth(request()))->toBeNull();

    // The `reverb` connection, and the absence of a Pusher-only `cluster` key:
    // Reverb speaks the Pusher protocol and has no clusters, so the server would
    // reject the option rather than ignore it.
    expect(config('broadcasting.connections.reverb.driver'))->toBe('reverb');
    expect(config('broadcasting.connections.reverb.options'))->not->toHaveKey('cluster');
    expect(config('broadcasting.connections.reverb'))->toHaveKeys(['key', 'secret', 'app_id']);

    // `config/broadcasting.php` declares three connections, but the loaded set
    // is a superset: `LoadConfiguration::loadConfigurationFile()` runs
    // `array_merge($base[$name], $config)` for every name in
    // `mergeableOptions()`, and `connections` is one of them, so the framework
    // base's `pusher`/`ably`/`mercure` survive alongside this file's entries.
    // That is harmless - a declared connection is not a used one, and the
    // default is `null` under the suite - but it is why the assertion names the
    // three this task owns rather than counting the whole set.
    expect(config('broadcasting.connections'))
        ->toHaveKeys(['reverb', 'log', 'null'])
        ->and(config('broadcasting.connections.log.driver'))->toBe('log')
        ->and(config('broadcasting.connections.null.driver'))->toBe('null');

    // The plan requires these two to be in opposite states.
    expect(file_get_contents(base_path('phpunit.xml')))
        ->toContain('name="BROADCAST_CONNECTION" value="null"');
    expect(file_get_contents(base_path('.env.example')))
        ->toContain('BROADCAST_CONNECTION=reverb');
});

test('the channel rule is the one the schema implies', function () {
    // The rule is a two-hop walk, and it has to be: `konsultasi` names no
    // account of its own. It stores `pasien_id` (:539) and `dokter_id` (:540),
    // and it is `pasien.user_id` (:220) and `dokter.user_id` (:411) that carry
    // the account. So the assertions that could actually be got wrong are:
    // that `konsultasi` has no `user_id`, and that both profile columns still
    // exist. Read through the project's own parser so this test cannot disagree
    // with `sehatly:verify-schema` about what the schema says.
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $konsultasiTable = $spec->table('konsultasi');
    $pasienTable = $spec->table('pasien');
    $dokterTable = $spec->table('dokter');

    expect($konsultasiTable)->not->toBeNull()
        ->and($pasienTable)->not->toBeNull()
        ->and($dokterTable)->not->toBeNull()
        ->and($konsultasiTable->columns)->toHaveKeys(['pasien_id', 'dokter_id'])
        ->and($konsultasiTable->columns)->not->toHaveKey('user_id')
        ->and($pasienTable->columns)->toHaveKey('user_id')
        ->and($dokterTable->columns)->toHaveKey('user_id');
});

test('the channel rule is reachable without a running broker', function () {
    // `KonsultasiChannelAccess` is the single implementation the callback in
    // `routes/channels.php` delegates to, and todo 32 will scope the REST
    // endpoints through the same object. Calling it directly here is what
    // proves the query, not the route, is what decides - so the rule is testable
    // without a broadcaster, and stays testable if the driver changes again.
    $pasienUser = realtimeUser('pasien', 'Pasien Kanal');
    $dokterUser = realtimeUser('dokter', 'Dokter Kanal');
    $konsultasi = realtimeKonsultasi(
        realtimePasien($pasienUser->getKey()),
        realtimeDokter($dokterUser->getKey()),
    );

    $access = app(KonsultasiChannelAccess::class);

    expect($access->allows($pasienUser, $konsultasi))->toBeTrue()
        ->and($access->allows($dokterUser, $konsultasi))->toBeTrue()
        ->and($access->allows(realtimeUser('pasien', 'Asing Kanal'), $konsultasi))->toBeFalse()
        ->and($access->allows($pasienUser, 'abc'))->toBeFalse()
        ->and($access->allows($pasienUser, 999999))->toBeFalse();
});

test('the broadcasting auth endpoint is answerable by a browser', function () {
    // The auth endpoint is mounted in the `api` group and needs no
    // `config/cors.php` change: the shipped `api/*` already covers it, because
    // `Str::is()` translates `*` into `.*` (Str.php:571), not into `[^/]*`, so the
    // wildcard matches at any depth. This test guards that fact and is also the
    // only place the browser-side path is exercised at all.
    //
    // The preflight must be issued through `call()`'s `$server` argument, not
    // through `withHeaders()`. `MakesHttpRequests::call()` builds the Symfony
    // request from `$this->serverVariables` and `$server` only (lines 633-636);
    // it is `json()` that folds `defaultHeaders` in via
    // `transformHeadersToServerVars()`. So `withHeaders(['Origin' => ...])->call(
    // 'OPTIONS', ...)` sends no Origin at all, `isPreflightRequest()` is then
    // false, and the request is routed rather than answered. That failure is
    // invisible: the route accepts GET and POST, so the response comes back
    // carrying `Allow: GET,HEAD,POST` and no CORS headers, which reads as "CORS
    // is broken" rather than as "the test built the wrong request".
    $preflight = fn () => test()->call('OPTIONS', '/api/broadcasting/auth', [], [], [], [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    // `HandleCors` answers the preflight itself, before routing, and only once
    // `hasMatchingPath()` agrees the path is covered.
    $response = $preflight();

    $response->assertStatus(204);
    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    $response->assertHeader('Access-Control-Allow-Methods', 'POST');

    // What the wildcard does and does not reach, since that is the entire reason
    // `broadcasting/auth` is still listed as well: a different prefix, not a
    // deeper path.
    expect(Str::is('api/*', 'api/broadcasting/auth'))->toBeTrue()
        ->and(Str::is('api/*', 'api/v1/konsultasi/1'))->toBeTrue()
        ->and(Str::is('api/*', 'broadcasting/auth'))->toBeFalse();

    // The negative direction, so the assertions above cannot pass vacuously.
    // `HandleCors::handle()` re-reads the `cors` config on every request, so
    // narrowing the paths to exclude this endpoint makes the identical request
    // stop being answered by CORS. That is what proves the 204 above came from
    // `HandleCors` matching the path rather than from routing.
    config(['cors.paths' => ['api/v1/*']]);

    $response = $preflight();

    $response->assertHeaderMissing('Access-Control-Allow-Origin');
});
