<?php

declare(strict_types=1);

use App\Enums\JamTenangMode;
use App\Enums\PreferensiNotifikasiTipe;
use App\Enums\ZonaWaktu;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/f11-helpers.php';

/*
|--------------------------------------------------------------------------
| F11 - preferences and quiet hours
|--------------------------------------------------------------------------
|
| The contract under test:
|
| - `GET` answers the EFFECTIVE state and writes nothing; a user with no row
|   gets the defaults, not a 404 and not an empty object.
| - `PUT` lazy-upserts only what it was sent: a partial write does not reset
|   the fields it did not mention.
| - the matrix is the four PRODUCED types; `lab`, `promo` and `sistem` are
|   refused rather than silently ignored.
| - in-app delivery has no switch, because it is unconditional.
|
*/

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('GET returns the effective defaults and writes nothing when no row exists', function (): void {
    $user = f11User('Pasien Preferensi');

    $response = f11As($user)->getJson('/api/v1/profil/notifikasi');

    $response->assertOk();
    $response->assertJsonPath('data.preferensi.jam_tenang_aktif', false);
    $response->assertJsonPath('data.preferensi.jam_tenang_mode', JamTenangMode::SetiapHari->value);
    $response->assertJsonPath('data.preferensi.jam_tenang_mulai', '21:00');
    $response->assertJsonPath('data.preferensi.jam_tenang_selesai', '06:00');
    $response->assertJsonPath('data.preferensi.zona_waktu', ZonaWaktu::DEFAULT);

    foreach (PreferensiNotifikasiTipe::nilai() as $tipe) {
        $response->assertJsonPath('data.preferensi.push.'.$tipe, true);
    }

    // The read is lazy: no row was materialised by reading.
    expect(DB::table('preferensi_notifikasi')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('preferensi_notifikasi_tipe')->where('user_id', $user->id)->count())->toBe(0);
});

test('PUT lazy-upserts quiet hours and the per-type matrix, and GET returns them', function (): void {
    $user = f11User('Pasien Simpan Preferensi');

    $response = f11As($user)->putJson('/api/v1/profil/notifikasi', [
        'jam_tenang_aktif' => true,
        'jam_tenang_mode' => JamTenangMode::HariKerja->value,
        'jam_tenang_mulai' => '22:00',
        'jam_tenang_selesai' => '05:30',
        'zona_waktu' => ZonaWaktu::Wita->value,
        'push' => [
            'booking' => false,
            'pembayaran' => true,
            'resep' => false,
            'chat' => true,
        ],
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.preferensi.jam_tenang_aktif', true);
    $response->assertJsonPath('data.preferensi.jam_tenang_mode', 'hari_kerja');
    $response->assertJsonPath('data.preferensi.jam_tenang_mulai', '22:00');
    $response->assertJsonPath('data.preferensi.zona_waktu', 'Asia/Makassar');
    $response->assertJsonPath('data.preferensi.push.booking', false);
    $response->assertJsonPath('data.preferensi.push.resep', false);

    // Exactly one quiet-hours row and one row per type the caller named.
    expect(DB::table('preferensi_notifikasi')->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('preferensi_notifikasi_tipe')->where('user_id', $user->id)->count())->toBe(4);

    // A reload answers the stored values, not the defaults.
    $lagi = f11As($user)->getJson('/api/v1/profil/notifikasi');

    $lagi->assertOk();
    $lagi->assertJsonPath('data.preferensi.jam_tenang_mulai', '22:00');
    $lagi->assertJsonPath('data.preferensi.push.booking', false);

    // The stored row is what the column holds, seconds included.
    expect(DB::table('preferensi_notifikasi')->where('user_id', $user->id)->value('jam_tenang_mulai'))
        ->toBe('22:00:00');
});

test('a partial PUT does not reset the fields it did not send', function (): void {
    $user = f11User('Pasien Parsial');

    f11As($user)->putJson('/api/v1/profil/notifikasi', [
        'jam_tenang_aktif' => true,
        'jam_tenang_mulai' => '23:00',
        'jam_tenang_selesai' => '04:00',
        'zona_waktu' => ZonaWaktu::Wit->value,
    ])->assertOk();

    // Only the matrix is touched this time.
    $response = f11As($user)->putJson('/api/v1/profil/notifikasi', [
        'push' => ['chat' => false],
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.preferensi.jam_tenang_aktif', true);
    $response->assertJsonPath('data.preferensi.jam_tenang_mulai', '23:00');
    $response->assertJsonPath('data.preferensi.zona_waktu', 'Asia/Jayapura');
    $response->assertJsonPath('data.preferensi.push.chat', false);

    // Only the named type got a row; the other three stay absent (= on).
    expect(DB::table('preferensi_notifikasi_tipe')->where('user_id', $user->id)->count())->toBe(1);
});

test('a type without a row reads as ON, and turning one off does not affect the others', function (): void {
    $user = f11User('Pasien Default Tipe');

    DB::table('preferensi_notifikasi_tipe')->insert([
        'user_id' => $user->id,
        'tipe' => 'pembayaran',
        'push_aktif' => 0,
    ]);

    $response = f11As($user)->getJson('/api/v1/profil/notifikasi');

    $response->assertOk();
    $response->assertJsonPath('data.preferensi.push.pembayaran', false);
    $response->assertJsonPath('data.preferensi.push.booking', true);
    $response->assertJsonPath('data.preferensi.push.resep', true);
    $response->assertJsonPath('data.preferensi.push.chat', true);
});

test('the write is validated: bad mode, bad time, bad zone and unproduced types are 422', function (array $payload, string $field): void {
    $user = f11User('Pasien Validasi Preferensi');

    $response = f11As($user)->putJson('/api/v1/profil/notifikasi', $payload);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey($field);
})->with([
    'unknown mode' => [['jam_tenang_mode' => 'kadang_kadang'], 'jam_tenang_mode'],
    'time with seconds' => [['jam_tenang_mulai' => '21:00:00'], 'jam_tenang_mulai'],
    'unknown zone' => [['zona_waktu' => 'Asia/Tokyo'], 'zona_waktu'],
    'lab is not offered' => [['push' => ['lab' => false]], 'push'],
    'promo is not offered' => [['push' => ['promo' => false]], 'push'],
    'non-boolean switch' => [['push' => ['chat' => 'mungkin']], 'push.chat'],
]);

test('the preference routes are refused for an account with no role, like the inbox', function (): void {
    $perawat = f11User('Perawat F11', 'perawat', null);

    f11As($perawat)->getJson('/api/v1/profil/notifikasi')->assertForbidden();
    f11As($perawat)->putJson('/api/v1/profil/notifikasi', ['jam_tenang_aktif' => true])->assertForbidden();
});
