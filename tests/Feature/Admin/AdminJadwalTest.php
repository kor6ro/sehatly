<?php

declare(strict_types=1);

use App\Models\DokterJadwal;
use App\Models\DokterLibur;
use App\Services\Admin\AdminJadwalService;
use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use App\Support\WaktuIndonesia;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| F14 B: the admin schedule surface
|--------------------------------------------------------------------------
|
| `dokter_jadwal` (weekly recurring windows) and `dokter_libur` (whole-day leave),
| through `GET|POST /admin/dokter/{id}/jadwal`, `PUT|DELETE /admin/jadwal/{id}`,
| `GET|POST /admin/dokter/{id}/libur` and `DELETE /admin/libur/{id}`.
|
| **The schema validates NOTHING, so every rule in this file is an application
| rule and is asserted as one.** `dokter_jadwal` (:470-:488) has no UNIQUE key, no
| CHECK constraint, and an UNSIGNED `hari` that rejects a negative day but not
| `7`. Overlap, `jam_selesai > jam_mulai`, `berlaku_sampai >= berlaku_mulai` and
| `durasi_slot_menit > 0` are all refusals this API invents, and each one is
| asserted here with the FIELD it names - a 422 that names no field is a 422 a
| form cannot point at.
|
| **Overlap is half-open and only between ACTIVE windows.** `08:00-09:00` and
| `09:00-10:00` may coexist (a doctor needs no gap between consultations), and two
| DRAFTS may overlap because the F14 flow stores a window unpublished and
| publishes it with a separate action. Publishing a draft is the moment the rule
| fires, which is asserted as its own case rather than assumed.
|
| **The overlap convention is the slot service's, not a second one.**
| `AdminJadwalService` normalises both times through
| `SlotAvailabilityService::detik()` - the same method the slot service orders by -
| and this file pins the agreement from both ends: a window the admin API accepts
| is a window the PUBLIC slot endpoint reads back. A drifted second copy of the
| normalisation would fail exactly here and nowhere else.
|
| **Deleting a window is refused while ANY booking references it**, because
| `booking.jadwal_id` is a nullable FK with no delete rule and MySQL therefore
| materialises `RESTRICT`. Deactivating is the supported remedy and is NOT
| refused - that asymmetry is the F14 pattern's "Nonaktifkan saja" path, and
| asserting it stops a future change from blocking the only escape hatch.
|
| **Leave is whole-day only.** `dokter_libur` (:490-:496) is `dokter_id`,
| `tanggal`, `alasan` and an id: there is no start time, no end time, no slot
| reference. The request therefore offers no hour field at all, and this file
| asserts that nothing time-shaped reaches the response or the row - the honest
| reading of "the schema cannot represent it", without inventing a refusal for
| undeclared body keys that the rest of this project does not make either.
|
*/

require_once __DIR__.'/f14-helpers.php';

beforeEach(function (): void {
    // `RbacSeeder` writes `roles`, `permissions` and `role_permissions`.
    // `RoleAssigner::assign()` resolves a role name against `roles` and throws a
    // `LogicException` when the catalogue names a row that is not there, and
    // `EnsurePermission` resolves every `permission:` code against `permissions`.
    $this->seed(RbacSeeder::class);
});

/**
 * A minimal valid `POST /admin/dokter/{id}/jadwal` body, with overrides merged.
 *
 * A draft (`status_aktif: false`) is the DEFAULT here, because that is the state
 * the F14 form creates in and it is the state that must be exempt from the
 * overlap rule. A test about publishing says `status_aktif: true` itself.
 *
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function f14JadwalBody(array $ubah = []): array
{
    return array_merge([
        'hari' => [1],
        'tipe_layanan' => 'online',
        'jam_mulai' => '08:00',
        'jam_selesai' => '12:00',
        'durasi_slot_menit' => 15,
        'kuota_per_sesi' => 12,
        'berlaku_mulai' => '2026-01-01',
        'berlaku_sampai' => null,
        'status_aktif' => false,
    ], $ubah);
}

/**
 * Assert a 422 carries at least one message for `$field`, and return those messages.
 *
 * Two conventions collide here and the helper has to know about both, because the
 * shape is the CONTRACT and not an accident:
 *
 * 1. `errors` maps each field to an ARRAY of messages, so the value under a key is
 *    always a list.
 * 2. The KEY is the dotted attribute path AS SUBMITTED, stated outright in the
 *    published contract ("`items.0.obat_id` for an array element"). A rule on
 *    `hari.*` therefore produces the literal key `hari.0`, while the service's own
 *    conflict refusal produces the literal key `hari`. `json('errors.hari.0')`
 *    would walk a NESTED path and find nothing, so the whole `errors` map is read
 *    and its literal keys are matched - which is what a form does too.
 *
 * @return list<string>
 */
function f14Pesan422(TestResponse $response, string $field): array
{
    $response->assertStatus(422);

    /** @var array<string, array<int, string>> $errors */
    $errors = (array) $response->json('errors');

    // Exact key first, then the `hari.N` shape - the first element is the one a
    // form points at when the request has exactly one bad element.
    $kunci = array_key_exists($field, $errors)
        ? $field
        : (array_key_exists($field.'.0', $errors) ? $field.'.0' : null);

    expect($kunci)->not->toBeNull(
        'the 422 carries no message for '.$field.'; it carries: '.implode(', ', array_keys($errors))
    );

    return array_values(array_filter((array) $errors[$kunci], 'is_string'));
}

/*
|--------------------------------------------------------------------------
| The guards
|--------------------------------------------------------------------------
*/

test('an anonymous caller is refused 401 on all seven schedule routes', function (string $method, string $path, array $body): void {
    f14TanpaToken();

    $this->json($method, $path, $body)->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
})->with([
    ['get', '/api/v1/admin/dokter/1/jadwal', []],
    ['post', '/api/v1/admin/dokter/1/jadwal', []],
    ['put', '/api/v1/admin/jadwal/1', []],
    ['delete', '/api/v1/admin/jadwal/1', []],
    ['get', '/api/v1/admin/dokter/1/libur', []],
    ['post', '/api/v1/admin/dokter/1/libur', []],
    ['delete', '/api/v1/admin/libur/1', []],
]);

test('a doctor, who holds jadwal.lihat, is refused 403 on every schedule route', function (string $method, string $path, array $body): void {
    // The load-bearing half of the two-gate rule, and the one that matters most
    // here: the public `GET /dokter/{id}/jadwal` shows only PUBLISHED windows, so an
    // admin view reachable by a doctor account would leak the draft pipeline.
    $user = f14User('Dokter Uji', 'dokter');

    app(RoleAssigner::class)->assign((int) $user->getKey(), 'dokter');

    expect(RbacCatalog::permissionsFor('dokter'))->toContain('jadwal.lihat');

    f14As($user);

    $this->json($method, $path, $body)->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');
})->with([
    ['get', '/api/v1/admin/dokter/1/jadwal', []],
    ['post', '/api/v1/admin/dokter/1/jadwal', []],
    ['put', '/api/v1/admin/jadwal/1', []],
    ['delete', '/api/v1/admin/jadwal/1', []],
    ['get', '/api/v1/admin/dokter/1/libur', []],
    ['post', '/api/v1/admin/dokter/1/libur', []],
    ['delete', '/api/v1/admin/libur/1', []],
]);

test('a patient is refused 403 too, because patients hold jadwal.lihat', function (): void {
    $pasien = f14Pasien()['user'];

    expect(RbacCatalog::permissionsFor('pasien'))->toContain('jadwal.lihat');

    f14As($pasien);

    $this->getJson('/api/v1/admin/dokter/1/jadwal')->assertStatus(403);
    $this->getJson('/api/v1/admin/dokter/1/libur')->assertStatus(403);
});

test('an admin revoked of jadwal.lihat is refused 403 on the two reads', function (): void {
    $admin = f14Admin();

    DB::table('role_permissions')
        ->whereIn('permission_id', DB::table('permissions')->select('id')->where('kode', 'jadwal.lihat'))
        ->delete();

    f14As($admin);

    $this->getJson('/api/v1/admin/dokter/1/jadwal')->assertStatus(403);
    $this->getJson('/api/v1/admin/dokter/1/libur')->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| The list
|--------------------------------------------------------------------------
*/

test('the list returns drafts AND published windows, annotated with booking_aktif', function (): void {
    $dokterId = (int) f14Dokter(f14User('Budi Santoso'), ['status_verifikasi' => 'terverifikasi'])->getKey();

    $terbit = f14Jadwal($dokterId, ['hari' => 1, 'status_aktif' => 1]);
    $draf = f14Jadwal($dokterId, ['hari' => 3, 'status_aktif' => 0, 'jam_mulai' => '14:00:00', 'jam_selesai' => '16:00:00']);

    // Two future bookings on the published window, one cancelled (released), one on
    // the past (history). Only the two future live ones count - the same six-status
    // set the slot service treats as occupying, read from
    // `SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI` rather than restated.
    foreach (['terjadwal', 'check_in'] as $status) {
        f14BookingRow($dokterId, ['jadwal_id' => $terbit, 'status' => $status, 'tanggal_kunjungan' => f14Hari(2)]);
    }
    f14BookingRow($dokterId, ['jadwal_id' => $terbit, 'status' => 'dibatalkan', 'tanggal_kunjungan' => f14Hari(2)]);
    f14BookingRow($dokterId, ['jadwal_id' => $terbit, 'status' => 'selesai', 'tanggal_kunjungan' => f14Hari(-2)]);

    f14As(f14Admin());

    $jawaban = $this->getJson("/api/v1/admin/dokter/{$dokterId}/jadwal")->assertOk();

    // Ordered by weekday then start time, and the whole week is returned: a weekly
    // template is seven days' worth of rows and the F14 screen renders it whole, so
    // the list is deliberately unpaginated and uses the single-page `meta`.
    expect(array_column($jawaban->json('data.jadwal'), 'hari'))->toBe([1, 3])
        ->and($jawaban->json('meta.total'))->toBe(2)
        ->and($jawaban->json('meta.per_page'))->toBe(2)
        ->and($jawaban->json('meta.last_page'))->toBe(1);

    $baris = collect($jawaban->json('data.jadwal'))->keyBy('id');

    expect($baris[$terbit]['booking_aktif'])->toBe(2)
        ->and($baris[$terbit]['status_aktif'])->toBeTrue()
        ->and($baris[$terbit]['hari_label'])->toBe('Senin')
        // Times are published as STORED (`H:i:s`), one spelling on the wire for one
        // spelling in the column; the request accepts `H:i` on the way in and the
        // service re-reads the row so the answer carries the column's spelling.
        ->and($baris[$terbit]['jam_mulai'])->toBe('08:00:00')
        ->and($baris[$terbit]['jam_selesai'])->toBe('10:00:00')
        ->and($baris[$terbit]['durasi_slot_menit'])->toBe(15)
        // NULL is the DDL's own "no quota set", published as null rather than
        // substituted.
        ->and($baris[$terbit]['kuota_per_sesi'])->toBeNull()
        ->and($baris[$terbit]['berlaku_sampai'])->toBeNull()
        ->and($baris[$draf]['booking_aktif'])->toBe(0)
        ->and($baris[$draf]['status_aktif'])->toBeFalse()
        ->and($baris[$draf]['hari_label'])->toBe('Rabu');
});

test('the status_aktif filter narrows the list to exactly one side', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    $terbit = f14Jadwal($dokterId, ['hari' => 1, 'status_aktif' => 1]);
    f14Jadwal($dokterId, ['hari' => 2, 'status_aktif' => 0]);

    f14As(f14Admin());

    $semua = $this->getJson("/api/v1/admin/dokter/{$dokterId}/jadwal")->assertOk();
    $hanyaTerbit = $this->getJson("/api/v1/admin/dokter/{$dokterId}/jadwal?status_aktif=1")->assertOk();
    $hanyaDraf = $this->getJson("/api/v1/admin/dokter/{$dokterId}/jadwal?status_aktif=0")->assertOk();

    expect($semua->json('meta.total'))->toBe(2)
        ->and(array_column($hanyaTerbit->json('data.jadwal'), 'id'))->toBe([$terbit])
        ->and($hanyaDraf->json('meta.total'))->toBe(1)
        ->and($hanyaDraf->json('data.jadwal.0.status_aktif'))->toBeFalse();

    // And the same closed-vocabulary refusal as the doctor list, on the same field.
    f14Pesan422($this->getJson("/api/v1/admin/dokter/{$dokterId}/jadwal?status_aktif=ya"), 'status_aktif');
});

test('the list 404s for a doctor that does not exist, and 404s a non-numeric id', function (): void {
    f14As(f14Admin());

    $this->getJson('/api/v1/admin/dokter/999999/jadwal')->assertStatus(404)
        ->assertJsonPath('message', 'Resource not found.');

    $this->getJson('/api/v1/admin/dokter/abc/jadwal')->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| Create: one row per weekday, atomically
|--------------------------------------------------------------------------
*/

test('a multi-day create answers one row per day, 201, with the full projection', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    $jawaban = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1, 2, 4],
        'tipe_layanan' => 'klinik',
        'status_aktif' => true,
    ]))->assertCreated();

    expect($jawaban->json('success'))->toBeTrue()
        ->and($jawaban->json('data.jadwal'))->toHaveCount(3)
        ->and(array_column($jawaban->json('data.jadwal'), 'hari'))->toBe([1, 2, 4])
        ->and(array_column($jawaban->json('data.jadwal'), 'hari_label'))->toBe(['Senin', 'Selasa', 'Kamis']);

    // `data.jadwal` is a LIST even for one day, so a client has one shape to parse.
    $satu = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [5],
        'jam_mulai' => '14:00',
        'jam_selesai' => '16:00',
    ]))->assertCreated();
    expect($satu->json('data.jadwal'))->toHaveCount(1);

    // Every writable column round-trips, because this is a write surface's own
    // shape: hiding one would make an edit lose data.
    $baris = $jawaban->json('data.jadwal.0');
    expect($baris)->toHaveKeys([
        'id', 'dokter_id', 'faskes_id', 'tipe_layanan', 'hari', 'hari_label',
        'jam_mulai', 'jam_selesai', 'durasi_slot_menit', 'kuota_per_sesi',
        'berlaku_mulai', 'berlaku_sampai', 'status_aktif', 'booking_aktif',
        'dibuat_at', 'diubah_at',
    ])
        ->and($baris['dokter_id'])->toBe($dokterId)
        ->and($baris['tipe_layanan'])->toBe('klinik')
        // `H:i` went in and `H:i:s` comes back, because the answer is the STORED
        // value: the service re-reads the row rather than echoing the request.
        ->and($baris['jam_mulai'])->toBe('08:00:00')
        ->and($baris['jam_selesai'])->toBe('12:00:00')
        ->and($baris['durasi_slot_menit'])->toBe(15)
        ->and($baris['kuota_per_sesi'])->toBe(12)
        ->and($baris['berlaku_mulai'])->toBe('2026-01-01')
        ->and($baris['berlaku_sampai'])->toBeNull()
        // A freshly written row has no occupancy annotation, and `0` is truthful
        // for it: nothing can hold a slot in a window that did not exist a moment
        // ago.
        ->and($baris['booking_aktif'])->toBe(0);

    expect(DokterJadwal::query()->where('dokter_id', $dokterId)->count())->toBe(4);
});

test('kuota NULL and kuota 0 stay two different values, because the schema says they are', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    $tanpaKuota = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1],
        'kuota_per_sesi' => null,
    ]))->assertCreated();
    $nol = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [2],
        'kuota_per_sesi' => 0,
    ]))->assertCreated();

    // NULL is "no quota set"; 0 is "no slots". Substituting 1 for NULL - or
    // dropping a 0 - would turn one of them into a legal-looking lie.
    expect($tanpaKuota->json('data.jadwal.0.kuota_per_sesi'))->toBeNull()
        ->and($nol->json('data.jadwal.0.kuota_per_sesi'))->toBe(0)
        ->and(DokterJadwal::query()->findOrFail($nol->json('data.jadwal.0.id'))->kuota_per_sesi)->toBe(0);
});

test('a multi-day create is atomic: a conflict on the LAST day writes nothing', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    // An ACTIVE window on Thursday only. Days 1 and 2 are free.
    f14Jadwal($dokterId, [
        'hari' => 4,
        'jam_mulai' => '10:00:00',
        'jam_selesai' => '12:00:00',
        'status_aktif' => 1,
    ]);

    f14As(f14Admin());

    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1, 2, 4],
        'jam_mulai' => '11:00',
        'jam_selesai' => '13:00',
        'status_aktif' => true,
    ])), 'hari');

    // The whole request is refused, not the first two days: a half-written weekly
    // template is exactly the "jadwal setengah jadi" the F14 publish flow exists to
    // prevent, and the client keeps its form contents either way.
    expect(DokterJadwal::query()->where('dokter_id', $dokterId)->count())->toBe(1);
});

test('the create takes dokter_id from the path, never from the body', function (): void {
    $dokterA = (int) f14Dokter(f14User('Budi Santoso'))->getKey();
    $dokterB = (int) f14Dokter(f14User('Sari Aulia'))->getKey();

    f14As(f14Admin());

    // A body naming another doctor is not validated into anything, so the window
    // is created for the doctor the path addressed.
    $this->postJson("/api/v1/admin/dokter/{$dokterA}/jadwal", f14JadwalBody([
        'hari' => [1],
        'dokter_id' => $dokterB,
    ]))->assertCreated()->assertJsonPath('data.jadwal.0.dokter_id', $dokterA);

    expect(DokterJadwal::query()->where('dokter_id', $dokterB)->count())->toBe(0);
});

test('a create for a doctor that does not exist is a 404, not a foreign-key 500', function (): void {
    f14As(f14Admin());

    $this->postJson('/api/v1/admin/dokter/999999/jadwal', f14JadwalBody())
        ->assertStatus(404)->assertJsonPath('message', 'Resource not found.');

    expect(DokterJadwal::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The three geometry rules, which the schema does not have
|--------------------------------------------------------------------------
*/

test('jam_selesai must be strictly greater than jam_mulai, named on that field', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    // Reversed.
    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'jam_mulai' => '12:00',
        'jam_selesai' => '08:00',
    ])), 'jam_selesai');

    // Zero-length: `08:00`-`08:00` is not a window either.
    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'jam_mulai' => '08:00',
        'jam_selesai' => '08:00',
    ])), 'jam_selesai');

    expect(DokterJadwal::query()->count())->toBe(0);
});

test('durasi_slot_menit must be above zero, named on that field', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    // `0` fails at the request's `min:1`; a NEGATIVE value cannot even reach the
    // service, because the column is SMALLINT UNSIGNED (:478).
    f14Pesan422(
        $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['durasi_slot_menit' => 0])),
        'durasi_slot_menit',
    );

    // The same rule on the update path, where only ONE field arrives and the
    // request rule and the cross-field guard both have to agree.
    $id = f14Jadwal($dokterId);

    f14Pesan422($this->putJson("/api/v1/admin/jadwal/{$id}", ['durasi_slot_menit' => 0]), 'durasi_slot_menit');

    // The schema's real ceiling is accepted: a 65535-minute slot is legal, and a
    // server that refused a schema-legal value would be inventing policy.
    $this->putJson("/api/v1/admin/jadwal/{$id}", ['durasi_slot_menit' => 65535])->assertOk();

    f14Pesan422($this->putJson("/api/v1/admin/jadwal/{$id}", ['durasi_slot_menit' => 65536]), 'durasi_slot_menit');
});

test('hari is 0..6, and the DDL-permitted value 7 is refused', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    // `7` is representable in the column - `TINYINT UNSIGNED` rejects a negative
    // day but not an eighth one - so this refusal is the application's, not the
    // database's. `docs/schema-notes.md` records it as a missing CHECK.
    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['hari' => [7]])), 'hari');
    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['hari' => [-1]])), 'hari');

    // Both ends of the range are legal.
    $minggu = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['hari' => [0]]))
        ->assertCreated();
    $sabtu = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['hari' => [6]]))
        ->assertCreated();

    expect($minggu->json('data.jadwal.0.hari_label'))->toBe('Minggu')
        ->and($sabtu->json('data.jadwal.0.hari_label'))->toBe('Sabtu');
});

test('hari must be a non-empty array of DISTINCT days', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['hari' => []])), 'hari');

    // A repeated day is an overlap by construction, so refusing it as a FIELD error
    // is cheaper and clearer than letting the conflict check report the request
    // against itself.
    f14Pesan422(
        $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['hari' => [1, 2, 3, 4, 5, 6, 0, 1]])),
        'hari',
    );

    expect(DokterJadwal::query()->count())->toBe(0);
});

test('berlaku_sampai must not precede berlaku_mulai, named on that field', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'berlaku_mulai' => '2026-06-01',
        'berlaku_sampai' => '2026-05-31',
    ])), 'berlaku_sampai');

    // The same day is legal: a one-day window is exactly `berlaku_mulai = berlaku_sampai`.
    $sehari = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'berlaku_mulai' => '2026-06-01',
        'berlaku_sampai' => '2026-06-01',
    ]))->assertCreated();
    expect($sehari->json('data.jadwal.0.berlaku_sampai'))->toBe('2026-06-01');

    // A reversed pair on the UPDATE path, where the stored row supplies
    // `berlaku_mulai` and only one field arrives - the cross-field rule cannot be a
    // stateless rule there, which is why it lives in the service over the MERGED row.
    $id = f14Jadwal($dokterId, ['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => null]);

    f14Pesan422($this->putJson("/api/v1/admin/jadwal/{$id}", ['berlaku_sampai' => '2025-12-31']), 'berlaku_sampai');

    // And a CORRUPTED stored row is refused rather than published: an admin who only
    // wants to unpublish a window should not be able to publish a broken one.
    $rusak = f14Jadwal($dokterId, [
        'hari' => 2,
        'jam_mulai' => '12:00:00',
        'jam_selesai' => '08:00:00',
        'status_aktif' => 0,
    ]);
    f14Pesan422($this->putJson("/api/v1/admin/jadwal/{$rusak}", ['status_aktif' => true]), 'jam_selesai');
});

test('a malformed date or time is refused rather than rolled into another one', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    // `date_format:Y-m-d` round-trips, so `2026-13-45` cannot silently become
    // 2027-02-14 the way PHP's own parser would allow.
    f14Pesan422(
        $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['berlaku_mulai' => '2026-13-45'])),
        'berlaku_mulai',
    );

    f14Pesan422(
        $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['jam_mulai' => '8am'])),
        'jam_mulai',
    );

    expect(DokterJadwal::query()->count())->toBe(0);
});

test('tipe_layanan is the schedule ENUM, not the booking ENUM, and faskes_id must exist', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    // `dokter_jadwal.tipe_layanan` is `('online','klinik','home_visit')` (:474) and
    // shares only `home_visit` with `booking.tipe_layanan`'s four values. Sending
    // the booking spelling `kunjungan_klinik` here would be a value this column
    // cannot store.
    f14Pesan422(
        $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['tipe_layanan' => 'kunjungan_klinik'])),
        'tipe_layanan',
    );

    // `faskes_id` is checked against the real table, so a typo is a 422 naming the
    // field rather than a MySQL 1452 on save.
    f14Pesan422(
        $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody(['faskes_id' => 999999])),
        'faskes_id',
    );
});

/*
|--------------------------------------------------------------------------
| Overlap: the rule the schema cannot express
|--------------------------------------------------------------------------
*/

test('two ACTIVE windows that overlap in time on one weekday are refused with errors.hari', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14Jadwal($dokterId, [
        'hari' => 1,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '11:00:00',
        'status_aktif' => 1,
    ]);

    f14As(f14Admin());

    $pesan = f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1],
        'jam_mulai' => '10:00',
        'jam_selesai' => '12:00',
        'status_aktif' => true,
    ])), 'hari');

    // The message names the weekday and BOTH windows, which is what the F14 inline
    // error renders: "Jadwal Senin 10:00-12:00 bertumpuk dengan jadwal 09:00-11:00".
    expect($pesan[0])->toContain('Senin')
        ->and($pesan[0])->toContain('10:00-12:00')
        ->and($pesan[0])->toContain('09:00-11:00')
        ->and($pesan[0])->toContain('bertumpuk');
});

test('touching endpoints do NOT overlap, so a doctor needs no gap between windows', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14Jadwal($dokterId, [
        'hari' => 1,
        'jam_mulai' => '08:00:00',
        'jam_selesai' => '09:00:00',
        'status_aktif' => 1,
    ]);

    f14As(f14Admin());

    // Half-open on both ends: `08:00-09:00` and `09:00-10:00` share no minute.
    $ini = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1],
        'jam_mulai' => '09:00',
        'jam_selesai' => '10:00',
        'status_aktif' => true,
    ]))->assertCreated();

    expect($ini->json('data.jadwal.0.jam_mulai'))->toBe('09:00:00');
});

test('a different weekday never conflicts, and neither does a different doctor', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14Jadwal($dokterId, ['hari' => 1, 'status_aktif' => 1]);

    f14As(f14Admin());

    // Identical hours, different weekday: the rule is per `(dokter_id, hari)`.
    $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [2],
        'status_aktif' => true,
    ]))->assertCreated();

    // Identical weekday AND hours for a DIFFERENT doctor.
    $lain = (int) f14Dokter(f14User('Sari Aulia'))->getKey();
    $this->postJson("/api/v1/admin/dokter/{$lain}/jadwal", f14JadwalBody([
        'hari' => [1],
        'status_aktif' => true,
    ]))->assertCreated();

    expect(DokterJadwal::query()->where('status_aktif', true)->count())->toBe(3);
});

test('two DRAFTS may overlap, and PUBLISHING one of them is what is refused', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14Jadwal($dokterId, [
        'hari' => 1,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '11:00:00',
        'status_aktif' => 1,
    ]);

    f14As(f14Admin());

    // The F14 publish flow stores a window as a draft and publishes it with a
    // separate action. A conflict check that refused two overlapping DRAFTS would
    // make that flow impossible; one that ignored conflicts on publish would leak
    // them to patients. So the rule is exactly: ACTIVE rows only.
    $draf = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1],
        'jam_mulai' => '10:00',
        'jam_selesai' => '12:00',
        'status_aktif' => false,
    ]))->assertCreated();

    $drafId = (int) $draf->json('data.jadwal.0.id');
    expect($drafId)->toBeInt()->toBeGreaterThan(0);

    // And the publish action - `{status_aktif: true}` ALONE, which is why the
    // update route is partial - is where the conflict is caught.
    f14Pesan422($this->putJson("/api/v1/admin/jadwal/{$drafId}", ['status_aktif' => true]), 'hari');

    // A refused request leaves the draft exactly as it was.
    expect(DokterJadwal::query()->findOrFail($drafId)->status_aktif)->toBeFalse();
});

test('windows whose VALIDITY ranges do not overlap never conflict', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14Jadwal($dokterId, [
        'hari' => 1,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '11:00:00',
        'berlaku_mulai' => '2026-01-01',
        'berlaku_sampai' => '2026-06-30',
        'status_aktif' => 1,
    ]);

    f14As(f14Admin());

    // Identical wall-clock hours, but they never run on the same day.
    $kedepan = $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1],
        'status_aktif' => true,
        'berlaku_mulai' => '2026-07-01',
        'berlaku_sampai' => '2026-12-31',
    ]))->assertCreated();

    expect($kedepan->json('data.jadwal.0.berlaku_mulai'))->toBe('2026-07-01');

    // ...while a range that DOES intersect it is refused.
    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1],
        'status_aktif' => true,
        'berlaku_mulai' => '2026-06-15',
        'berlaku_sampai' => '2026-08-15',
    ])), 'hari');
});

test('an open-ended window conflicts with every range that begins inside it', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    // `berlaku_sampai = NULL` means open-ended, so it overlaps any window that
    // begins while it is still running.
    f14Jadwal($dokterId, [
        'hari' => 1,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '11:00:00',
        'berlaku_mulai' => '2026-01-01',
        'berlaku_sampai' => null,
        'status_aktif' => 1,
    ]);

    f14As(f14Admin());

    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1],
        'jam_mulai' => '10:00',
        'jam_selesai' => '12:00',
        'berlaku_mulai' => '2026-01-01',
        'berlaku_sampai' => null,
        'status_aktif' => true,
    ])), 'hari');

    // A range that ENDS before the open-ended one began does not.
    $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1],
        'jam_mulai' => '10:00',
        'jam_selesai' => '12:00',
        'berlaku_mulai' => '2025-01-01',
        'berlaku_sampai' => '2025-12-31',
        'status_aktif' => true,
    ]))->assertCreated();
});

test('a window never conflicts with ITSELF on update', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    $id = f14Jadwal($dokterId, [
        'hari' => 1,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '11:00:00',
        'status_aktif' => 1,
    ]);

    f14As(f14Admin());

    // Without the exclusion, every update of a live window would collide with
    // itself and the endpoint would be unusable.
    $this->putJson("/api/v1/admin/jadwal/{$id}", [
        'jam_mulai' => '09:30',
        'jam_selesai' => '11:30',
    ])->assertOk()->assertJsonPath('data.jadwal.jam_mulai', '09:30:00');

    // An unchanged publish of the same row is also fine.
    $this->putJson("/api/v1/admin/jadwal/{$id}", ['status_aktif' => true])->assertOk();
});

test('moving a window ONTO an occupied window is refused, naming the day', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    // Same weekday, so the two can genuinely collide.
    $sibuk = f14Jadwal($dokterId, [
        'hari' => 1,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '11:00:00',
        'status_aktif' => 1,
    ]);
    $boleh = f14Jadwal($dokterId, [
        'hari' => 1,
        'jam_mulai' => '08:00:00',
        'jam_selesai' => '09:00:00',
        'status_aktif' => 1,
    ]);

    f14As(f14Admin());

    // Whole-window move onto it.
    f14Pesan422($this->putJson("/api/v1/admin/jadwal/{$boleh}", [
        'jam_mulai' => '10:00',
        'jam_selesai' => '12:00',
    ]), 'hari');

    // A ONE-FIELD extension that swallows it - the case a stateless rule set cannot
    // see, because the conflict only exists once the stored row is merged.
    f14Pesan422($this->putJson("/api/v1/admin/jadwal/{$boleh}", ['jam_selesai' => '12:00']), 'hari');

    // Moving it to a free weekday is fine, which is the difference between "the
    // rule refuses" and "the rule is broken".
    $this->putJson("/api/v1/admin/jadwal/{$boleh}", ['hari' => 4])->assertOk();

    // A refused request leaves both rows untouched.
    expect(DokterJadwal::query()->findOrFail($boleh)->jam_selesai)->toBe('09:00:00')
        ->and(DokterJadwal::query()->findOrFail($sibuk)->jam_mulai)->toBe('09:00:00');
});

/*
|--------------------------------------------------------------------------
| Update: partial by design, because it is also the publish action
|--------------------------------------------------------------------------
*/

test('the update is partial and writes only what the request names', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    $id = f14Jadwal($dokterId, [
        'hari' => 1,
        'jam_mulai' => '08:00:00',
        'jam_selesai' => '10:00:00',
        'durasi_slot_menit' => 15,
        'kuota_per_sesi' => 12,
        'berlaku_mulai' => '2026-01-01',
        'berlaku_sampai' => '2026-12-31',
        'status_aktif' => 0,
    ]);

    f14As(f14Admin());

    $jawaban = $this->putJson("/api/v1/admin/jadwal/{$id}", ['durasi_slot_menit' => 20])->assertOk();

    expect($jawaban->json('data.jadwal.durasi_slot_menit'))->toBe(20)
        ->and($jawaban->json('data.jadwal.jam_mulai'))->toBe('08:00:00')
        ->and($jawaban->json('data.jadwal.kuota_per_sesi'))->toBe(12)
        ->and($jawaban->json('data.jadwal.berlaku_sampai'))->toBe('2026-12-31');
});

test('the three nullable columns can be CLEARED by an explicit null', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    $id = f14Jadwal($dokterId, ['kuota_per_sesi' => 12, 'berlaku_sampai' => '2026-12-31']);

    f14As(f14Admin());

    // "No quota set" is different from "a quota of twelve", and an open-ended
    // window is different from one that stops in December, so a partial update has
    // to be able to CLEAR rather than only to set. The service distinguishes
    // "absent" from "present and null" with `array_key_exists`.
    $jawaban = $this->putJson("/api/v1/admin/jadwal/{$id}", [
        'kuota_per_sesi' => null,
        'berlaku_sampai' => null,
        'faskes_id' => null,
    ])->assertOk();

    expect($jawaban->json('data.jadwal.kuota_per_sesi'))->toBeNull()
        ->and($jawaban->json('data.jadwal.berlaku_sampai'))->toBeNull()
        ->and($jawaban->json('data.jadwal.faskes_id'))->toBeNull();
});

test('the update cannot move a window to another doctor, and 404s honestly', function (): void {
    $dokterA = (int) f14Dokter(f14User('Budi Santoso'))->getKey();
    $dokterB = (int) f14Dokter(f14User('Sari Aulia'))->getKey();

    $id = f14Jadwal($dokterA);

    f14As(f14Admin());

    // `dokter_id` is not a field at all: a window does not move between doctors.
    $this->putJson("/api/v1/admin/jadwal/{$id}", ['dokter_id' => $dokterB])
        ->assertOk()
        ->assertJsonPath('data.jadwal.dokter_id', $dokterA);

    $this->putJson('/api/v1/admin/jadwal/999999', ['status_aktif' => true])->assertStatus(404);
    $this->putJson('/api/v1/admin/jadwal/abc', ['status_aktif' => true])->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| Delete: refused while a booking points at the row
|--------------------------------------------------------------------------
*/

test('a window nothing references is deleted, and the answer names the id', function (): void {
    $id = f14Jadwal((int) f14Dokter()->getKey());

    f14As(f14Admin());

    $this->deleteJson("/api/v1/admin/jadwal/{$id}")->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.id', $id);

    expect(DokterJadwal::query()->find($id))->toBeNull();
});

test('a window with bookings is refused with a 422 that names how many', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    $id = f14Jadwal($dokterId);

    // Three referencing bookings: two still to come, one cancelled. The refusal is
    // about REFERENTIAL INTEGRITY, so the cancelled one counts - `booking.jadwal_id`
    // is a nullable FK with no delete rule and MySQL materialises RESTRICT, which
    // would raise a hard SQL error the client cannot act on.
    f14BookingRow($dokterId, ['jadwal_id' => $id, 'status' => 'terjadwal', 'tanggal_kunjungan' => f14Hari(2)]);
    f14BookingRow($dokterId, ['jadwal_id' => $id, 'status' => 'check_in', 'tanggal_kunjungan' => f14Hari(2)]);
    f14BookingRow($dokterId, ['jadwal_id' => $id, 'status' => 'dibatalkan', 'tanggal_kunjungan' => f14Hari(2)]);

    f14As(f14Admin());

    $pesan = f14Pesan422($this->deleteJson("/api/v1/admin/jadwal/{$id}"), 'jadwal');

    // Both numbers, because they answer different questions: how many rows point
    // here at all (3), and how many will still RUN (2 - the cancelled one released
    // its slot). The F14 UI turns this into the "Nonaktifkan saja" button.
    expect($pesan[0])->toContain('3 booking')
        ->and($pesan[0])->toContain('2 aktif/akan datang')
        ->and($pesan[0])->toContain('Nonaktifkan')
        ->and($pesan[0])->toContain('booking yang ada tetap berjalan');

    expect(DokterJadwal::query()->find($id))->not->toBeNull();
});

test('deactivating IS the supported remedy, and is never refused for bookings', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    $id = f14Jadwal($dokterId, ['status_aktif' => 1]);
    f14BookingRow($dokterId, ['jadwal_id' => $id, 'status' => 'terjadwal', 'tanggal_kunjungan' => f14Hari(2)]);

    f14As(f14Admin());

    // The asymmetry is the F14 pattern's design: delete is refused, deactivate is
    // not. Blocking BOTH would leave an admin with no way to stop new bookings on a
    // window that has history, which is the safety problem the action exists for.
    $jawaban = $this->putJson("/api/v1/admin/jadwal/{$id}", ['status_aktif' => false])->assertOk();

    expect($jawaban->json('data.jadwal.status_aktif'))->toBeFalse()
        ->and(DokterJadwal::query()->findOrFail($id)->status_aktif)->toBeFalse();

    // And the bookings are untouched - deactivating stops NEW bookings, it does not
    // cancel what patients already hold.
    expect(DB::table('booking')->where('jadwal_id', $id)->where('status', 'terjadwal')->count())->toBe(1);
});

test('the delete 404s for an absent row and for a non-numeric one', function (): void {
    f14As(f14Admin());

    $this->deleteJson('/api/v1/admin/jadwal/999999')->assertStatus(404)
        ->assertJsonPath('message', 'Resource not found.');

    $this->deleteJson('/api/v1/admin/jadwal/abc')->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| Leave: whole-day only, because the schema is
|--------------------------------------------------------------------------
*/

test('a leave date is stored whole, and the list reads it back', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    $tambah = $this->postJson("/api/v1/admin/dokter/{$dokterId}/libur", [
        'tanggal' => '2026-12-25',
        'alasan' => 'Hari Raya Natal',
    ])->assertCreated();

    $id = (int) $tambah->json('data.libur.id');

    // Exactly four keys: the table has no hours and no timestamps, so a resource
    // publishing a `dibuat_at` or a `jam_mulai` would be inventing one. A client
    // that wants to render "sehari penuh" reads that from the absence.
    expect($tambah->json('data.libur'))->toBe([
        'id' => $id,
        'dokter_id' => $dokterId,
        'tanggal' => '2026-12-25',
        'alasan' => 'Hari Raya Natal',
    ]);

    // A date with no annotation is a complete record, not a partial one.
    $tanpaAlasan = $this->postJson("/api/v1/admin/dokter/{$dokterId}/libur", [
        'tanggal' => '2026-12-26',
    ])->assertCreated();
    expect($tanpaAlasan->json('data.libur.alasan'))->toBeNull();

    $daftar = $this->getJson("/api/v1/admin/dokter/{$dokterId}/libur")->assertOk();

    // Oldest first, and the whole calendar in one page - a doctor's leave list is
    // short and the F14 tab shows it whole.
    expect(array_column($daftar->json('data.libur'), 'tanggal'))->toBe(['2026-12-25', '2026-12-26'])
        ->and($daftar->json('meta.total'))->toBe(2);

    $this->deleteJson("/api/v1/admin/libur/{$id}")->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.id', $id);

    expect(DokterLibur::query()->find($id))->toBeNull();
});

test('a leave row is WHOLE-DAY: no hour is published, and the table has no hour column', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    // `dokter_libur` has no start time, no end time and no slot reference, so a
    // half-day closure cannot be stored. The request offers NO hour field, so
    // `validated()` never yields one; a client that sends hours anyway gets a
    // whole-day row back, which is the truthful answer about what the schema holds.
    $denganJam = $this->postJson("/api/v1/admin/dokter/{$dokterId}/libur", [
        'tanggal' => '2026-12-25',
        'alasan' => 'Setengah hari',
        'jam_mulai' => '08:00',
        'jam_selesai' => '12:00',
    ])->assertCreated();

    $id = (int) $denganJam->json('data.libur.id');

    // Nothing time-shaped is published...
    expect(array_keys($denganJam->json('data.libur')))
        ->toBe(['id', 'dokter_id', 'tanggal', 'alasan']);

    // ...and nothing time-shaped exists in the row. Asserted against the TABLE
    // rather than against the model, because "the column does not exist" is the
    // actual reason and a model accessor would hide that.
    $kolom = array_keys((array) DB::table('dokter_libur')->where('id', $id)->first());

    expect($kolom)->toBe(['id', 'dokter_id', 'tanggal', 'alasan']);
});

test('a leave date already on file is refused, naming the field', function (): void {
    $dokterId = (int) f14Dokter()->getKey();
    $lain = (int) f14Dokter(f14User('Sari Aulia'))->getKey();

    f14Libur($dokterId, '2026-12-25');

    f14As(f14Admin());

    // The table has no UNIQUE on `(dokter_id, tanggal)`, so this is an application
    // invariant. The date is already blocked for this doctor...
    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/libur", ['tanggal' => '2026-12-25']), 'tanggal');

    // ...but another doctor's leave is a different day off for a different person.
    $this->postJson("/api/v1/admin/dokter/{$lain}/libur", ['tanggal' => '2026-12-25'])->assertCreated();
});

test('the leave list 404s for an absent doctor, and the delete 404s for an absent row', function (): void {
    f14As(f14Admin());

    $this->getJson('/api/v1/admin/dokter/999999/libur')->assertStatus(404);
    $this->postJson('/api/v1/admin/dokter/999999/libur', ['tanggal' => '2026-12-25'])->assertStatus(404);
    $this->deleteJson('/api/v1/admin/libur/999999')->assertStatus(404);
    $this->deleteJson('/api/v1/admin/libur/abc')->assertStatus(404);

    // And `alasan` is bounded by its own column width, `VARCHAR(200)` (:494).
    $dokterId = (int) f14Dokter()->getKey();
    f14Pesan422($this->postJson("/api/v1/admin/dokter/{$dokterId}/libur", [
        'tanggal' => '2026-12-25',
        'alasan' => str_repeat('a', 201),
    ]), 'alasan');
});

/*
|--------------------------------------------------------------------------
| The one cross-file invariant: no second overlap convention
|--------------------------------------------------------------------------
*/

test('a window the admin API accepts is a window the PUBLIC slot endpoint reads', function (): void {
    // The strongest available statement about the de-duplication. Both the admin
    // conflict check and the slot enumeration normalise their two times through
    // `SlotAvailabilityService::detik()`; if a second copy of that conversion ever
    // drifted, the admin API would accept a window whose slot loop the public
    // endpoint reads differently - and nothing else in the suite would notice.
    $dokterId = (int) f14Dokter(f14User('Budi Santoso'), [
        'status_verifikasi' => 'terverifikasi',
        'str_berlaku_sampai' => '2099-12-31',
    ])->getKey();

    f14As(f14Admin());

    $this->postJson("/api/v1/admin/dokter/{$dokterId}/jadwal", f14JadwalBody([
        'hari' => [1],
        'jam_mulai' => '08:00',
        'jam_selesai' => '09:00',
        'durasi_slot_menit' => 20,
        'status_aktif' => true,
    ]))->assertCreated();

    // The next Monday from the clinic's own calendar, so the window really is the
    // one the slot service will find.
    $senin = Carbon::parse(f14Hari(1), WaktuIndonesia::ZONA)
        ->next(Carbon::MONDAY)
        ->format('Y-m-d');

    f14TanpaToken();

    $slot = $this->getJson("/api/v1/dokter/{$dokterId}/slot?tanggal={$senin}")->assertOk();

    // 08:00-09:00 at 20 minutes is exactly three slots, and the admin body said 20 -
    // the same number, read through the same normalisation.
    expect($slot->json('data.slots'))->toHaveCount(3)
        ->and(array_column($slot->json('data.slots'), 'jam_mulai'))->toBe([
            '08:00:00', '08:20:00', '08:40:00',
        ])
        ->and(array_column($slot->json('data.slots'), 'jam_selesai'))->toBe([
            '08:20:00', '08:40:00', '09:00:00',
        ]);
});

test('the service exposes the DDL-derived vocabularies the requests validate against', function (): void {
    // `dokter_jadwal.tipe_layanan` is NOT `booking.tipe_layanan`: they share only
    // `home_visit`, and a request that accepted the booking spelling would promise
    // a value the column cannot store.
    expect(AdminJadwalService::TIPE_LAYANAN)->toBe(['online', 'klinik', 'home_visit'])
        ->toHaveCount(3)
        // `hari` is `0=Minggu .. 6=Sabtu` (:475), seven labels for seven days.
        ->and(AdminJadwalService::HARI_LABEL)->toHaveCount(7)
        ->and(AdminJadwalService::HARI_LABEL[0])->toBe('Minggu')
        ->and(AdminJadwalService::HARI_LABEL[6])->toBe('Sabtu');
});

/*
|--------------------------------------------------------------------------
| No export route, anywhere under /admin
|--------------------------------------------------------------------------
*/

test('no admin route offers an export, and none sends a Content-Disposition', function (): void {
    // The owner's F14 decision is "no export endpoint in v1", and the F14 pattern
    // states the same. The honest way to keep that promise is that no route exists
    // to promise - a URL a client can build is a feature whether or not the UI
    // renders a button for it.
    $rute = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin'))
        ->map(fn ($route): string => strtolower($route->uri()))
        ->values()
        ->all();

    expect($rute)->toHaveCount(16);

    foreach ($rute as $satu) {
        expect($satu)->not->toContain('export')
            ->and($satu)->not->toContain('csv')
            ->and($satu)->not->toContain('download')
            ->and($satu)->not->toContain('excel')
            ->and($satu)->not->toContain('pdf');
    }

    // And a live read answers JSON, not a spreadsheet.
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Admin());

    foreach ([
        "/api/v1/admin/dokter/{$dokterId}/jadwal",
        "/api/v1/admin/dokter/{$dokterId}/libur",
    ] as $path) {
        $jawaban = $this->getJson($path)->assertOk();

        expect($jawaban->headers->get('Content-Disposition'))->toBeNull()
            ->and($jawaban->headers->get('Content-Type'))->toContain('application/json');
    }
});
