<?php

declare(strict_types=1);

use App\Enums\PengingatJenis;
use App\Enums\PengingatStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/f11-helpers.php';

/*
|--------------------------------------------------------------------------
| F11 - reminder CRUD
|--------------------------------------------------------------------------
|
| The contract under test:
|
| - a create writes exactly the seven meaningful fields and normalises `waktu`
|   (sorted, deduped) rather than trusting the form;
| - `booking_id` ownership is a 422 on the field, not a 403 (which would
|   confirm the row exists);
| - every lookup is scoped to the caller: another account's id is a 404;
| - `status` is system-owned on create and the only field that pauses a
|   reminder on update.
|
*/

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

test('POST creates an obat reminder with the seven fields and normalises waktu', function (): void {
    $user = f11User('Pasien Pengingat Obat');

    $response = f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat([
        // Unsorted and duplicated on purpose: the service sorts and dedupes.
        'waktu' => ['19:00', '07:00', '19:00', '12:00'],
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.pengingat.jenis', PengingatJenis::Obat->value);
    $response->assertJsonPath('data.pengingat.judul', 'Amoxicillin 500 mg');
    $response->assertJsonPath('data.pengingat.status', PengingatStatus::Aktif->value);
    $response->assertJsonPath('data.pengingat.zona_waktu', 'Asia/Jakarta');
    $response->assertJsonPath('data.pengingat.zona_label', 'WIB');
    $response->assertJsonPath('data.pengingat.waktu', ['07:00', '12:00', '19:00']);

    $baris = DB::table('pengingat')->where('user_id', $user->id)->first();

    expect($baris)->not->toBeNull()
        ->and((string) $baris->status)->toBe('aktif')
        ->and(json_decode((string) $baris->waktu, true))->toBe(['07:00', '12:00', '19:00'])
        ->and((string) $baris->zona_waktu)->toBe('Asia/Jakarta');
});

test('POST accepts a janji_temu reminder for a booking the caller is a party to', function (): void {
    $pasien = f11User('Pasien Janji Temu');
    $pasienId = f11Pasien((int) $pasien->id);
    $dokter = f11User('Dokter Janji Temu', 'dokter', 'dokter');
    $dokterId = f11Dokter((int) $dokter->id);
    $bookingId = f11Booking($pasienId, $dokterId, (int) $pasien->id);

    $response = f11As($pasien)->postJson('/api/v1/pengingat', [
        'jenis' => 'janji_temu',
        'judul' => 'Kontrol dr. Rina',
        'booking_id' => $bookingId,
        'tanggal_mulai' => '2026-10-04',
        'waktu' => ['19:00'],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.pengingat.jenis', 'janji_temu');
    $response->assertJsonPath('data.pengingat.booking_id', $bookingId);

    // The doctor of the booking is a party too, so their own reminder is legal.
    f11As($dokter)->postJson('/api/v1/pengingat', [
        'jenis' => 'janji_temu',
        'judul' => 'Kontrol pasien',
        'booking_id' => $bookingId,
        'tanggal_mulai' => '2026-10-04',
        'waktu' => ['18:00'],
    ])->assertCreated();
});

test('POST refuses a booking_id that belongs to somebody else, on the field', function (): void {
    $pemilik = f11User('Pasien Pemilik Booking');
    $pasienId = f11Pasien((int) $pemilik->id);
    $dokter = f11User('Dokter Pemilik Booking', 'dokter', 'dokter');
    $dokterId = f11Dokter((int) $dokter->id);
    $bookingId = f11Booking($pasienId, $dokterId, (int) $pemilik->id);

    $lain = f11User('Pasien Lain');

    $response = f11As($lain)->postJson('/api/v1/pengingat', [
        'jenis' => 'janji_temu',
        'judul' => 'Booking orang lain',
        'booking_id' => $bookingId,
        'tanggal_mulai' => '2026-10-04',
        'waktu' => ['19:00'],
    ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('booking_id');
    expect(DB::table('pengingat')->count())->toBe(0);
});

test('POST validates waktu, jenis, dates and ids, and status is system-owned', function (array $payload, string $field): void {
    $user = f11User('Pasien Validasi Pengingat');

    $response = f11As($user)->postJson('/api/v1/pengingat', $payload);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey($field);
})->with([
    'empty waktu' => [f11PayloadObat(['waktu' => []]), 'waktu'],
    'hour 25' => [f11PayloadObat(['waktu' => ['25:00']]), 'waktu.0'],
    'single-digit hour' => [f11PayloadObat(['waktu' => ['9:00']]), 'waktu.0'],
    'not a time' => [f11PayloadObat(['waktu' => ['pagi']]), 'waktu.0'],
    'unknown jenis' => [f11PayloadObat(['jenis' => 'vitamin']), 'jenis'],
    'missing jenis' => [f11PayloadObat(['jenis' => null]), 'jenis'],
    'lama_hari zero' => [f11PayloadObat(['lama_hari' => 0]), 'lama_hari'],
    'bad date' => [f11PayloadObat(['tanggal_mulai' => '04-10-2026']), 'tanggal_mulai'],
    'unknown zone' => [f11PayloadObat(['zona_waktu' => 'Asia/Tokyo']), 'zona_waktu'],
    'foreign obat' => [f11PayloadObat(['obat_id' => 999999]), 'obat_id'],
    'client status' => [f11PayloadObat(['status' => 'nonaktif']), 'status'],
]);

test('GET lists only the caller\'s reminders, filters by status and paginates', function (): void {
    $user = f11User('Pasien Daftar Pengingat');
    $lain = f11User('Pasien Lain Daftar');

    f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat(['judul' => 'Obat A']))->assertCreated();
    $kedua = f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat(['judul' => 'Obat B']))->assertCreated();

    // The other account's reminder must not appear.
    f11As($lain)->postJson('/api/v1/pengingat', f11PayloadObat(['judul' => 'Obat Orang Lain']))->assertCreated();

    $semua = f11As($user)->getJson('/api/v1/pengingat');
    $semua->assertOk();
    expect($semua->json('data.pengingat'))->toHaveCount(2)
        ->and($semua->json('meta.total'))->toBe(2);

    // Pause one, then filter.
    f11As($user)->putJson('/api/v1/pengingat/'.$kedua->json('data.pengingat.id'), [
        'status' => 'nonaktif',
    ])->assertOk();

    $aktif = f11As($user)->getJson('/api/v1/pengingat?status=aktif');
    expect($aktif->json('data.pengingat'))->toHaveCount(1)
        ->and($aktif->json('data.pengingat.0.judul'))->toBe('Obat A');

    $nonaktif = f11As($user)->getJson('/api/v1/pengingat?status=nonaktif');
    expect($nonaktif->json('data.pengingat'))->toHaveCount(1)
        ->and($nonaktif->json('data.pengingat.0.judul'))->toBe('Obat B');

    $paginasi = f11As($user)->getJson('/api/v1/pengingat?per_page=1&page=1');
    expect($paginasi->json('data.pengingat'))->toHaveCount(1)
        ->and($paginasi->json('meta.per_page'))->toBe(1)
        ->and($paginasi->json('meta.last_page'))->toBe(2);
});

test('PUT updates a reminder, re-normalises waktu, and 404s another account\'s row', function (): void {
    $user = f11User('Pasien Ubah Pengingat');
    $lain = f11User('Pasien Lain Ubah');

    $dibuat = f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat())->assertCreated();
    $id = (int) $dibuat->json('data.pengingat.id');

    $response = f11As($user)->putJson('/api/v1/pengingat/'.$id, [
        'judul' => 'Amoxicillin 500 mg (lanjutan)',
        'waktu' => ['20:00', '08:00'],
        'status' => 'selesai',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.pengingat.judul', 'Amoxicillin 500 mg (lanjutan)');
    $response->assertJsonPath('data.pengingat.waktu', ['08:00', '20:00']);
    $response->assertJsonPath('data.pengingat.status', 'selesai');

    // The untouched fields survived the partial update.
    $response->assertJsonPath('data.pengingat.dosis', '1 kapsul');
    $response->assertJsonPath('data.pengingat.lama_hari', 5);

    f11As($lain)->putJson('/api/v1/pengingat/'.$id, ['judul' => 'Bukan milikku'])->assertNotFound();
});

test('DELETE removes the caller\'s reminder and cascades its dispatch ledger', function (): void {
    $user = f11User('Pasien Hapus Pengingat');
    $lain = f11User('Pasien Lain Hapus');

    $dibuat = f11As($user)->postJson('/api/v1/pengingat', f11PayloadObat())->assertCreated();
    $id = (int) $dibuat->json('data.pengingat.id');

    DB::table('pengingat_terkirim')->insert([
        'pengingat_id' => $id,
        'tanggal' => '2026-10-05',
        'waktu' => '07:00:00',
    ]);

    f11As($lain)->deleteJson('/api/v1/pengingat/'.$id)->assertNotFound();
    f11As($user)->deleteJson('/api/v1/pengingat/'.$id)->assertOk();

    expect(DB::table('pengingat')->where('id', $id)->count())->toBe(0)
        ->and(DB::table('pengingat_terkirim')->where('pengingat_id', $id)->count())->toBe(0);
});
