<?php

declare(strict_types=1);

use App\Support\WaktuIndonesia;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/f11-helpers.php';

/*
|--------------------------------------------------------------------------
| F11 - the reminder scheduler (`pengingat:kirim`)
|--------------------------------------------------------------------------
|
| The contract under test, one rule per test:
|
| - the in-app row is ALWAYS written; push is the only thing that can be
|   suppressed (F11: push is not the source of truth);
| - `pengingat_terkirim` makes a re-run a no-op - once per
|   `(pengingat, tanggal, waktu)`, never twice;
| - push is suppressed by a missing/withdrawn consent, by a per-type
|   `push_aktif = false`, and by quiet hours, independently;
| - the window and the minute are evaluated on the REMINDER's own zone.
|
*/

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A user with a device and the F11 consent the push gate requires, plus a
 * reminder due at `$waktu` on the frozen day.
 *
 * @param  array<string, mixed>  $ubah
 */
function f11Skenario(array $ubah = [], bool $setuju = true, bool $perangkat = true): array
{
    $user = f11User('Pasien Penjadwal');

    if ($perangkat) {
        f11Perangkat((int) $user->id, 'f11-token-'.random_int(1000, 9999));
    }

    if ($setuju) {
        f11Setuju((int) $user->id, 'komunikasi_tindak_lanjut');
    }

    $payload = f11PayloadObat(array_merge([
        'tanggal_mulai' => '2026-10-05',
        'lama_hari' => null,
        'waktu' => ['09:00'],
    ], $ubah));

    $dibuat = f11As($user)->postJson('/api/v1/pengingat', $payload)->assertCreated();

    return [$user, (int) $dibuat->json('data.pengingat.id')];
}

test('the scheduler writes the in-app row once and pushes once, and a re-run changes nothing', function (): void {
    [$user, $id] = f11Skenario();
    $push = f11Push();

    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '09:00:00'));

    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(f11Notifikasi((int) $user->id, 'sistem'))->toBe(1)
        ->and(f11Terkirim($id))->toBe(1)
        ->and($push->terkirim)->toHaveCount(1);

    // The push body is generic - the drug name lives on `pengingat`, never in a
    // body that renders on a lock screen (F11 §9).
    $notifikasi = DB::table('notifikasi')->where('user_id', $user->id)->first();
    expect((string) $notifikasi->judul)->not->toContain('Amoxicillin')
        ->and((string) $notifikasi->isi)->not->toContain('Amoxicillin');

    // Re-run inside the same minute: the unique key makes it a no-op.
    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(f11Notifikasi((int) $user->id))->toBe(1)
        ->and(f11Terkirim($id))->toBe(1)
        ->and($push->terkirim)->toHaveCount(1);
});

test('quiet hours suppress the push but NOT the in-app row', function (): void {
    [$user, $id] = f11Skenario(['waktu' => ['22:00']]);
    $push = f11Push();

    f11As($user)->putJson('/api/v1/profil/notifikasi', [
        'jam_tenang_aktif' => true,
        'jam_tenang_mode' => 'setiap_hari',
        'jam_tenang_mulai' => '21:00',
        'jam_tenang_selesai' => '06:00',
    ])->assertOk();

    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '22:00:00'));

    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(f11Notifikasi((int) $user->id, 'sistem'))->toBe(1)
        ->and(f11Terkirim($id))->toBe(1)
        ->and($push->terkirim)->toBe([]);
});

test('a missing consent suppresses the push but NOT the in-app row', function (): void {
    [$user, $id] = f11Skenario([], setuju: false);
    $push = f11Push();

    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '09:00:00'));

    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(f11Notifikasi((int) $user->id, 'sistem'))->toBe(1)
        ->and(f11Terkirim($id))->toBe(1)
        ->and($push->terkirim)->toBe([]);
});

test('a WITHDRAWN consent suppresses the push, because the latest ledger row wins', function (): void {
    [$user] = f11Skenario([], setuju: false);
    $push = f11Push();

    // Approve, then withdraw: append-only, so the newest row is the decision.
    f11Setuju((int) $user->id, 'komunikasi_tindak_lanjut', true);
    f11Setuju((int) $user->id, 'komunikasi_tindak_lanjut', false);

    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '09:00:00'));

    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(f11Notifikasi((int) $user->id, 'sistem'))->toBe(1)
        ->and($push->terkirim)->toBe([]);
});

test('a per-type push_aktif = false suppresses the push of an appointment reminder', function (): void {
    $pasien = f11User('Pasien Janji Temu Terjadwal');
    $pasienId = f11Pasien((int) $pasien->id);
    $dokter = f11User('Dokter Janji Temu Terjadwal', 'dokter', 'dokter');
    $dokterId = f11Dokter((int) $dokter->id);
    $bookingId = f11Booking($pasienId, $dokterId, (int) $pasien->id);

    f11Perangkat((int) $pasien->id);
    f11Setuju((int) $pasien->id, 'komunikasi_tindak_lanjut');

    f11As($pasien)->postJson('/api/v1/pengingat', [
        'jenis' => 'janji_temu',
        'judul' => 'Kontrol dr. Rina',
        'booking_id' => $bookingId,
        'tanggal_mulai' => '2026-10-05',
        'waktu' => ['09:00'],
    ])->assertCreated();

    f11As($pasien)->putJson('/api/v1/profil/notifikasi', [
        'push' => ['booking' => false],
    ])->assertOk();

    $push = f11Push();
    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '09:00:00'));

    $this->artisan('pengingat:kirim')->assertSuccessful();

    // In-app still lands, and it is a `booking` row because the appointment
    // reminder maps onto the produced type; only the push is held back.
    expect(f11Notifikasi((int) $pasien->id, 'booking'))->toBe(1)
        ->and($push->terkirim)->toBe([]);
});

test('an appointment reminder pushes when the booking type is ON and quiet hours are off', function (): void {
    $pasien = f11User('Pasien Janji Temu Aktif');
    $pasienId = f11Pasien((int) $pasien->id);
    $dokter = f11User('Dokter Janji Temu Aktif', 'dokter', 'dokter');
    $dokterId = f11Dokter((int) $dokter->id);
    $bookingId = f11Booking($pasienId, $dokterId, (int) $pasien->id);

    f11Perangkat((int) $pasien->id);
    f11Setuju((int) $pasien->id, 'komunikasi_tindak_lanjut');

    f11As($pasien)->postJson('/api/v1/pengingat', [
        'jenis' => 'janji_temu',
        'judul' => 'Kontrol dr. Rina',
        'booking_id' => $bookingId,
        'tanggal_mulai' => '2026-10-05',
        'waktu' => ['09:00'],
    ])->assertCreated();

    $push = f11Push();
    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '09:00:00'));

    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect($push->terkirim)->toHaveCount(1);

    // The deep link is the booking path the SPA whitelist already maps.
    $notifikasi = DB::table('notifikasi')->where('user_id', $pasien->id)->first();
    expect((string) $notifikasi->tipe)->toBe('booking')
        ->and((string) $notifikasi->tautan)->toBe('/api/v1/booking/'.$bookingId);
});

test('the wrong minute, a future start and an ended window all write nothing', function (): void {
    $user = f11User('Pasien Jendela');

    $push = f11Push();

    // Due at 10:00; the tick is at 09:00.
    f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat([
        'tanggal_mulai' => '2026-10-05', 'lama_hari' => null, 'waktu' => ['10:00'],
    ]))->assertCreated();

    // Starts tomorrow.
    f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat([
        'judul' => 'Besok', 'tanggal_mulai' => '2026-10-06', 'lama_hari' => null, 'waktu' => ['09:00'],
    ]))->assertCreated();

    // Started yesterday and ran for one day only.
    f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat([
        'judul' => 'Sudah lewat', 'tanggal_mulai' => '2026-10-04', 'lama_hari' => 1, 'waktu' => ['09:00'],
    ]))->assertCreated();

    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '09:00:00'));

    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(f11Notifikasi((int) $user->id))->toBe(0)
        ->and(DB::table('pengingat_terkirim')->count())->toBe(0)
        ->and($push->terkirim)->toBe([]);
});

test('the window and the minute are evaluated on the reminder\'s OWN zona_waktu', function (): void {
    $user = f11User('Pasien Zona');

    // 09:00 WIT = 07:00 WIB; 09:00 WIB = 11:00 WIT.
    f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat([
        'judul' => 'WIT 09:00',
        'zona_waktu' => 'Asia/Jayapura',
        'tanggal_mulai' => '2026-10-05',
        'lama_hari' => null,
        'waktu' => ['09:00'],
    ]))->assertCreated();

    f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat([
        'judul' => 'WIB 09:00',
        'zona_waktu' => 'Asia/Jakarta',
        'tanggal_mulai' => '2026-10-05',
        'lama_hari' => null,
        'waktu' => ['09:00'],
    ]))->assertCreated();

    // 07:00 WIB is 09:00 in Jayapura: only the WIT reminder is due.
    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '07:00:00'));
    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(DB::table('notifikasi')->where('user_id', $user->id)->count())->toBe(1);

    // 09:00 WIB: only the Jakarta reminder is due now.
    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '09:00:00'));
    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(DB::table('notifikasi')->where('user_id', $user->id)->count())->toBe(2);
});

test('hari_kerja quiet hours apply Monday-Friday and not on the weekend', function (): void {
    [$user, $id] = f11Skenario(['waktu' => ['22:00']]);

    f11As($user)->putJson('/api/v1/profil/notifikasi', [
        'jam_tenang_aktif' => true,
        'jam_tenang_mode' => 'hari_kerja',
        'jam_tenang_mulai' => '21:00',
        'jam_tenang_selesai' => '06:00',
    ])->assertOk();

    $push = f11Push();

    // Friday 2026-10-09 22:00 WIB: inside the workday window, push held.
    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-09', '22:00:00'));
    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(f11Notifikasi((int) $user->id))->toBe(1)
        ->and($push->terkirim)->toBe([]);

    // Retire the Friday reminder so the Saturday tick only sees the second one.
    f11As($user)->putJson('/api/v1/pengingat/'.$id, ['status' => 'selesai'])->assertOk();

    // Saturday 2026-10-10 22:00 WIB: the mode does not apply, push goes out.
    [$user2] = f11Skenario(['waktu' => ['22:00']]);

    f11As($user2)->putJson('/api/v1/profil/notifikasi', [
        'jam_tenang_aktif' => true,
        'jam_tenang_mode' => 'hari_kerja',
        'jam_tenang_mulai' => '21:00',
        'jam_tenang_selesai' => '06:00',
    ])->assertOk();

    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-10', '22:00:00'));
    $this->artisan('pengingat:kirim')->assertSuccessful();

    expect(f11Notifikasi((int) $user2->id))->toBe(1)
        ->and($push->terkirim)->toHaveCount(1);
});
