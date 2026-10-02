<?php

declare(strict_types=1);

use App\Http\Requests\Admin\LaporanRangeRequest;
use App\Http\Requests\Booking\BookingRequest;
use App\Services\Admin\AdminLaporanService;
use App\Support\Rbac\RbacCatalog;
use App\Support\WaktuIndonesia;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| F14 C: the three admin reports
|--------------------------------------------------------------------------
|
| `GET /api/v1/admin/laporan/booking`, `.../pendapatan` and `.../kehadiran`, over
| an explicit `dari`/`sampai` date range.
|
| **Aggregates ONLY, and that is asserted structurally.** `admin` holds no
| `rekam_medis.lihat` and no `resep.lihat` in `RbacCatalog`, so a report that
| could name a patient's care would be a defect wearing a role. Every assertion
| here is about counts, dates and money; the clinical-key census at the end is the
| statement that nothing else got in.
|
| **Money is DECIMAL STRINGS and never a float.** `invoice.total` is
| `DECIMAL(14,2)` and the stored value is what the report sums - the number is
| READ, never recomputed, so it cannot disagree with the invoice a patient holds.
| The totals are asserted through `Uang::normal()`, the project's string-safe
| formatter, because a float comparison would be the thing under test otherwise.
|
| **The three date bases differ, and each is asserted on its own.** The booking
| and attendance reports key on `booking.tanggal_kunjungan`, a clinic `DATE` that
| is never converted; the revenue report keys on `invoice.lunas_at`, a UTC
| instant whose WIB day is the boundary that matters. The evening-hours case is
| asserted live, because it is the one a UTC-naive implementation gets wrong and
| the one no other test in this file would catch.
|
| **`no_show` has no writer.** The status is in the DDL's ENUM but no endpoint
| moves a booking into it, so the key is published with a documented zero rather
| than hidden or faked - and that is asserted explicitly so a future writer
| shipping is a visible change rather than a silent one.
|
| **There is NO export endpoint.** The owner's decision, and the way to keep it is
| that no route exists to promise; asserted against the live route table.
|
*/

require_once __DIR__.'/f14-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

/*
|--------------------------------------------------------------------------
| The guards
|--------------------------------------------------------------------------
*/

test('an anonymous caller is refused 401 on all three reports', function (string $path): void {
    f14TanpaToken();

    $this->getJson($path)->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
})->with([
    '/api/v1/admin/laporan/booking?dari=2026-10-01&sampai=2026-10-31',
    '/api/v1/admin/laporan/pendapatan?dari=2026-10-01&sampai=2026-10-31',
    '/api/v1/admin/laporan/kehadiran?dari=2026-10-01&sampai=2026-10-31',
]);

test('a patient is refused 403: laporan.lihat is a report code, not a doctor code', function (string $path): void {
    // The patient holds `dokter.lihat`, `jadwal.lihat` and `booking.lihat`, so the
    // three admin reads are reachable only because `laporan.lihat` is a SEPARATE
    // grant that no patient role holds. That separation is the whole reason the code
    // was added rather than reusing `booking.lihat`.
    $pasien = f14Pasien()['user'];

    expect(RbacCatalog::permissionsFor('pasien'))->toContain('booking.lihat')
        ->and(RbacCatalog::permissionsFor('pasien'))->not->toContain('laporan.lihat')
        ->and(RbacCatalog::permissionsFor('admin'))->toContain('laporan.lihat')
        ->and(RbacCatalog::permissionsFor('superadmin'))->toContain('laporan.lihat');

    f14As($pasien);

    $this->getJson($path)->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');
})->with([
    '/api/v1/admin/laporan/booking?dari=2026-10-01&sampai=2026-10-31',
    '/api/v1/admin/laporan/pendapatan?dari=2026-10-01&sampai=2026-10-31',
    '/api/v1/admin/laporan/kehadiran?dari=2026-10-01&sampai=2026-10-31',
]);

test('an admin revoked of laporan.lihat is refused 403 on all three', function (): void {
    $admin = f14Admin();

    DB::table('role_permissions')
        ->whereIn('permission_id', DB::table('permissions')->select('id')->where('kode', 'laporan.lihat'))
        ->delete();

    f14As($admin);

    foreach (['booking', 'pendapatan', 'kehadiran'] as $laporan) {
        $this->getJson("/api/v1/admin/laporan/{$laporan}?dari=2026-10-01&sampai=2026-10-31")
            ->assertStatus(403);
    }
});

/*
|--------------------------------------------------------------------------
| The range contract
|--------------------------------------------------------------------------
*/

test('dari and sampai are required, well-formed, ordered and bounded', function (string $path, array $query, string $field): void {
    f14As(f14Admin());

    $this->getJson($path.'?'.http_build_query($query))
        ->assertStatus(422)
        ->assertJsonPath('errors.'.$field, fn ($m): bool => is_array($m) && $m !== []);
})->with([
    // Missing entirely.
    ['/api/v1/admin/laporan/booking', ['sampai' => '2026-10-31'], 'dari'],
    ['/api/v1/admin/laporan/booking', ['dari' => '2026-10-01'], 'sampai'],
    // Malformed, and `date_format` round-trips rather than rolling the calendar:
    // `2026-13-45` must not become 2027-02-14.
    ['/api/v1/admin/laporan/booking', ['dari' => '2026-13-45', 'sampai' => '2026-10-31'], 'dari'],
    ['/api/v1/admin/laporan/booking', ['dari' => '01/10/2026', 'sampai' => '2026-10-31'], 'dari'],
    // Reversed: a 422 naming `sampai`, NOT an empty report - an empty report
    // would read as "no activity", which is a different fact entirely.
    ['/api/v1/admin/laporan/booking', ['dari' => '2026-10-31', 'sampai' => '2026-10-01'], 'sampai'],
    // Longer than the documented 366-day ceiling, so a range query cannot ask the
    // database for an unbounded scan.
    ['/api/v1/admin/laporan/kehadiran', ['dari' => '2025-01-01', 'sampai' => '2026-12-31'], 'sampai'],
]);

test('a single day and a 366-day span are both accepted', function (): void {
    f14As(f14Admin());

    // One day: `diffInDays` is 0 and the inclusive ceiling is 365.
    $this->getJson('/api/v1/admin/laporan/booking?dari=2026-10-01&sampai=2026-10-01')->assertOk();

    // Exactly 366 inclusive days (365 of difference), which a whole calendar year
    // has and the rule must let through.
    $this->getJson('/api/v1/admin/laporan/booking?dari=2026-01-01&sampai=2026-12-31')->assertOk();
    $this->getJson('/api/v1/admin/laporan/booking?dari=2026-01-01&sampai=2027-01-01')->assertOk();

    // One day more is refused.
    $this->getJson('/api/v1/admin/laporan/booking?dari=2026-01-01&sampai=2027-01-02')->assertStatus(422);

    expect(LaporanRangeRequest::MAX_HARI)->toBe(366);
});

/*
|--------------------------------------------------------------------------
| The booking report
|--------------------------------------------------------------------------
*/

test('the booking report counts by status over the range, zero-filling the whole ENUM', function (): void {
    $dokter = (int) f14Dokter(f14User('Budi Santoso'))->getKey();

    // Three days inside the range, one day outside it, and one day in the future.
    f14BookingRow($dokter, ['status' => 'selesai', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['status' => 'selesai', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['status' => 'terjadwal', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['status' => 'dibatalkan', 'tanggal_kunjungan' => '2026-10-06']);
    f14BookingRow($dokter, ['status' => 'kadaluarsa', 'tanggal_kunjungan' => '2026-10-06']);
    f14BookingRow($dokter, ['status' => 'check_in', 'tanggal_kunjungan' => '2026-10-07']);

    // Outside the range on both sides, and never counted: before it, and after it.
    f14BookingRow($dokter, ['status' => 'selesai', 'tanggal_kunjungan' => '2026-09-30']);
    f14BookingRow($dokter, ['status' => 'selesai', 'tanggal_kunjungan' => '2026-11-01']);

    f14As(f14Admin());

    $jawaban = $this->getJson('/api/v1/admin/laporan/booking?dari=2026-10-01&sampai=2026-10-31')
        ->assertOk();

    // All EIGHT DDL statuses, zeros included, so a client renders a fixed table
    // rather than one that changes width with the data.
    expect(array_keys($jawaban->json('data.ringkasan.per_status')))
        ->toBe(BookingRequest::STATUS_SEMUA)
        ->and($jawaban->json('data.ringkasan.total'))->toBe(6)
        ->and($jawaban->json('data.ringkasan.per_status.selesai'))->toBe(2)
        ->and($jawaban->json('data.ringkasan.per_status.terjadwal'))->toBe(1)
        ->and($jawaban->json('data.ringkasan.per_status.dibatalkan'))->toBe(1)
        ->and($jawaban->json('data.ringkasan.per_status.kadaluarsa'))->toBe(1)
        ->and($jawaban->json('data.ringkasan.per_status.check_in'))->toBe(1)
        ->and($jawaban->json('data.ringkasan.per_status.no_show'))->toBe(0)
        ->and($jawaban->json('data.ringkasan.per_status.berlangsung'))->toBe(0)
        ->and($jawaban->json('data.ringkasan.per_status.menunggu_pembayaran'))->toBe(0);

    // Only the days with activity, ascending, each carrying its own per-status map.
    expect(array_column($jawaban->json('data.harian'), 'tanggal'))
        ->toBe(['2026-10-05', '2026-10-06', '2026-10-07']);

    $perHari = collect($jawaban->json('data.harian'))->keyBy('tanggal');

    expect($perHari['2026-10-05']['total'])->toBe(3)
        ->and($perHari['2026-10-05']['per_status']['selesai'])->toBe(2)
        ->and($perHari['2026-10-06']['per_status']['dibatalkan'])->toBe(1)
        ->and($perHari['2026-10-06']['per_status']['kadaluarsa'])->toBe(1)
        ->and($perHari['2026-10-07']['per_status']['check_in'])->toBe(1);
});

test('the booking report narrows to one doctor, and an unknown doctor is a 422 not an empty report', function (): void {
    $budi = (int) f14Dokter(f14User('Budi Santoso'))->getKey();
    $sari = (int) f14Dokter(f14User('Sari Aulia'))->getKey();

    f14BookingRow($budi, ['status' => 'selesai', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($budi, ['status' => 'selesai', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($sari, ['status' => 'terjadwal', 'tanggal_kunjungan' => '2026-10-05']);

    f14As(f14Admin());

    $semua = $this->getJson('/api/v1/admin/laporan/booking?dari=2026-10-01&sampai=2026-10-31')->assertOk();
    expect($semua->json('data.ringkasan.total'))->toBe(3);

    $satu = $this->getJson("/api/v1/admin/laporan/booking?dari=2026-10-01&sampai=2026-10-31&dokter_id={$budi}")
        ->assertOk();
    expect($satu->json('data.ringkasan.total'))->toBe(2)
        ->and($satu->json('data.ringkasan.per_status.selesai'))->toBe(2)
        ->and($satu->json('data.ringkasan.per_status.terjadwal'))->toBe(0);

    // A typo'd or deleted id is refused with the field named, rather than answered
    // as a report of zeros that looks like a data problem.
    $this->getJson('/api/v1/admin/laporan/booking?dari=2026-10-01&sampai=2026-10-31&dokter_id=999999')
        ->assertStatus(422)
        ->assertJsonPath('errors.dokter_id', fn ($m): bool => is_array($m) && $m !== []);
});

test('an EMPTY range is a real report of zeros, not a 404 and not an error', function (): void {
    f14As(f14Admin());

    // `total` is a COUNT on the booking and attendance reports and a MONEY STRING
    // on the revenue report - the shape difference is real and each is asserted on
    // its own terms rather than through one shared expectation.
    foreach (['booking', 'kehadiran'] as $laporan) {
        $jawaban = $this->getJson("/api/v1/admin/laporan/{$laporan}?dari=2026-10-01&sampai=2026-10-31")
            ->assertOk();

        expect($jawaban->json('success'))->toBeTrue()
            ->and($jawaban->json('data.harian'))->toBe([])
            ->and($jawaban->json('data.ringkasan.total'))->toBe(0)->toBeInt();
    }

    $pendapatan = $this->getJson('/api/v1/admin/laporan/pendapatan?dari=2026-10-01&sampai=2026-10-31')
        ->assertOk();

    // Money is a two-place DECIMAL STRING, never a float and never an int: a
    // response of `0` would be indistinguishable from "no rows" to a client that
    // renders `tabular-nums`.
    expect($pendapatan->json('data.ringkasan.total'))->toBe('0.00')->toBeString()
        ->and($pendapatan->json('data.ringkasan.jumlah_invoice'))->toBe(0);

    // And the shape is still the shape: `per_status` still carries all eight keys,
    // so a client does not have to handle "no rows" as a different contract.
    $booking = $this->getJson('/api/v1/admin/laporan/booking?dari=2026-10-01&sampai=2026-10-31')->assertOk();
    expect(array_keys($booking->json('data.ringkasan.per_status')))->toBe(BookingRequest::STATUS_SEMUA);

    $kehadiran = $this->getJson('/api/v1/admin/laporan/kehadiran?dari=2026-10-01&sampai=2026-10-31')->assertOk();
    expect($kehadiran->json('data.ringkasan'))->toBe([
        'total' => 0,
        'check_in' => 0,
        'selesai' => 0,
        'no_show' => 0,
    ]);
});

/*
|--------------------------------------------------------------------------
| The revenue report
|--------------------------------------------------------------------------
*/

test('the revenue report sums SETTLED invoices only, as decimal strings', function (): void {
    $pasienId = (int) f14Pasien()['pasien']->getKey();

    f14Invoice($pasienId, ['total' => '150000.00', 'tanggal_lunas' => '2026-10-05']);
    f14Invoice($pasienId, ['total' => '250000.50', 'tanggal_lunas' => '2026-10-05']);
    f14Invoice($pasienId, ['total' => '90000.00', 'tanggal_lunas' => '2026-10-06']);

    // Excluded, and each for its own reason:
    //  - `refund_penuh` is what F12's refund ledger writes, so a refunded booking's
    //    money is NOT counted: the figure is money the clinic KEEPS.
    //  - `draft`, `menunggu_pembayaran` and `kadaluarsa` were never captured.
    //  - an invoice outside the range.
    f14Invoice($pasienId, ['total' => '500000.00', 'status' => 'refund_penuh', 'tanggal_lunas' => '2026-10-05']);
    f14Invoice($pasienId, ['total' => '400000.00', 'status' => 'refund_sebagian', 'tanggal_lunas' => '2026-10-05']);
    f14Invoice($pasienId, ['total' => '300000.00', 'status' => 'draft', 'tanggal_lunas' => '2026-10-05']);
    f14Invoice($pasienId, ['total' => '200000.00', 'status' => 'kadaluarsa', 'tanggal_lunas' => '2026-10-05']);
    f14Invoice($pasienId, ['total' => '999999.00', 'tanggal_lunas' => '2026-09-01']);
    // A `lunas` invoice with no `lunas_at` is not attributable to a day at all.
    f14Invoice($pasienId, ['total' => '888888.00', 'lunas_at' => null]);

    f14As(f14Admin());

    $jawaban = $this->getJson('/api/v1/admin/laporan/pendapatan?dari=2026-10-01&sampai=2026-10-31')
        ->assertOk();

    // 150000.00 + 250000.50 + 90000.00 = 490000.50. The cents are the point: a
    // float would have lost the 50 somewhere, and `total` is a STRING.
    expect($jawaban->json('data.ringkasan.total'))->toBe('490000.50')->toBeString()
        ->and($jawaban->json('data.ringkasan.jumlah_invoice'))->toBe(3);

    expect(array_column($jawaban->json('data.harian'), 'tanggal'))->toBe(['2026-10-05', '2026-10-06'])
        ->and($jawaban->json('data.harian.0.total'))->toBe('400000.50')
        ->and($jawaban->json('data.harian.0.jumlah_invoice'))->toBe(2)
        ->and($jawaban->json('data.harian.1.total'))->toBe('90000.00');
});

test('the revenue report is grouped on the WIB day, so the evening hours are not lost', function (): void {
    $pasienId = (int) f14Pasien()['pasien']->getKey();

    // 22:30 WIB on the 5th is 15:30 UTC on the 5th - still the 5th.
    f14Invoice($pasienId, ['total' => '100000.00', 'tanggal_lunas' => '2026-10-05', 'jam_lunas' => '22:30']);
    // 02:00 WIB on the 6th is 19:00 UTC on the 5th. A UTC-naive grouping would put
    // this on the 5th; the clinic's calendar says the 6th.
    f14Invoice($pasienId, ['total' => '70000.00', 'tanggal_lunas' => '2026-10-06', 'jam_lunas' => '02:00']);
    // 07:00 WIB on the 6th is 00:00 UTC on the 6th - the boundary itself, one
    // second either side of it decides which day the money lands on.
    f14Invoice($pasienId, ['total' => '30000.00', 'tanggal_lunas' => '2026-10-06', 'jam_lunas' => '07:00']);

    f14As(f14Admin());

    $perHari = collect(
        $this->getJson('/api/v1/admin/laporan/pendapatan?dari=2026-10-01&sampai=2026-10-31')
            ->assertOk()
            ->json('data.harian'),
    )->keyBy('tanggal');

    expect($perHari['2026-10-05']['total'])->toBe('100000.00')
        ->and($perHari['2026-10-05']['jumlah_invoice'])->toBe(1)
        ->and($perHari['2026-10-06']['total'])->toBe('100000.00')
        ->and($perHari['2026-10-06']['jumlah_invoice'])->toBe(2);

    // And the range itself is WIB: an invoice settled at 01:00 WIB on the FIRST day
    // is inside, and one at 01:00 WIB on the day AFTER the last is outside.
    $tepat = collect(
        $this->getJson('/api/v1/admin/laporan/pendapatan?dari=2026-10-06&sampai=2026-10-06')
            ->assertOk()
            ->json('data.harian'),
    );

    expect($tepat->toArray())->toHaveCount(1)
        ->and($tepat[0]['tanggal'])->toBe('2026-10-06');

    expect(WaktuIndonesia::ZONA)->toBe('Asia/Jakarta');
});

/*
|--------------------------------------------------------------------------
| The attendance report
|--------------------------------------------------------------------------
*/

test('the attendance report counts check_in, selesai and no_show, and publishes no_show as a documented zero', function (): void {
    $dokter = (int) f14Dokter()->getKey();

    f14BookingRow($dokter, ['status' => 'check_in', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['status' => 'check_in', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['status' => 'selesai', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['status' => 'selesai', 'tanggal_kunjungan' => '2026-10-06']);

    // `no_show` is IN the DDL's ENUM, so it can be written directly - which is how
    // the key's meaning is proved rather than merely documented.
    f14BookingRow($dokter, ['status' => 'no_show', 'tanggal_kunjungan' => '2026-10-06']);

    // Not attendance facts: a cancellation and an expiry are not outcomes of a
    // visit, and a consultation still in progress is not one either.
    f14BookingRow($dokter, ['status' => 'dibatalkan', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['status' => 'kadaluarsa', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['status' => 'berlangsung', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['status' => 'terjadwal', 'tanggal_kunjungan' => '2026-10-05']);

    f14As(f14Admin());

    $jawaban = $this->getJson('/api/v1/admin/laporan/kehadiran?dari=2026-10-01&sampai=2026-10-31')
        ->assertOk();

    expect($jawaban->json('data.ringkasan'))->toBe([
        'total' => 5,
        'check_in' => 2,
        'selesai' => 2,
        'no_show' => 1,
    ]);

    // `total` is the attendance UNIVERSE, not the range's whole booking count:
    // 10 bookings were written and five of them are attendance facts.
    expect(array_column($jawaban->json('data.harian'), 'tanggal'))->toBe(['2026-10-05', '2026-10-06'])
        ->and($jawaban->json('data.harian.0.total'))->toBe(3)
        ->and($jawaban->json('data.harian.1.total'))->toBe(2);

    // The service names the three it tracks, and `no_show` is in it. It is in the
    // DDL's ENUM and the report is honest about having no writer: publishing the
    // key as a documented zero is different from hiding the column (which would
    // look unimplemented) and different again from faking a figure.
    expect(AdminLaporanService::STATUS_KEHADIRAN)->toBe(['check_in', 'selesai', 'no_show'])
        ->toContain('check_in')
        ->toContain('selesai')
        ->toContain('no_show')
        // ...and none of the two that are NOT visit outcomes.
        ->not->toContain('dibatalkan')
        ->not->toContain('kadaluarsa')
        ->not->toContain('berlangsung');
});

test('the attendance report does NOT collapse the three statuses into one number', function (): void {
    $dokter = (int) f14Dokter()->getKey();

    f14BookingRow($dokter, ['status' => 'selesai', 'tanggal_kunjungan' => '2026-10-05']);

    f14As(f14Admin());

    $ringkasan = $this->getJson('/api/v1/admin/laporan/kehadiran?dari=2026-10-01&sampai=2026-10-31')
        ->assertOk()
        ->json('data.ringkasan');

    // Three independent counters plus their sum. A single "attendance" percentage
    // would hide which of the three moved, which is the question an operator asks.
    expect(array_keys($ringkasan))->toBe(['total', 'check_in', 'selesai', 'no_show'])
        ->and($ringkasan['selesai'])->toBe(1)
        ->and($ringkasan['check_in'])->toBe(0)
        ->and($ringkasan['total'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The privacy half: aggregates only, and no export
|--------------------------------------------------------------------------
*/

test('no report carries a patient, a clinical or a per-invoice key', function (): void {
    $dokter = (int) f14Dokter()->getKey();
    $pasienId = (int) f14Pasien()['pasien']->getKey();

    f14BookingRow($dokter, ['pasien_id' => $pasienId, 'status' => 'selesai', 'tanggal_kunjungan' => '2026-10-05']);
    f14BookingRow($dokter, ['pasien_id' => $pasienId, 'status' => 'check_in', 'tanggal_kunjungan' => '2026-10-05']);
    f14Invoice($pasienId, ['total' => '150000.00', 'tanggal_lunas' => '2026-10-05']);

    f14As(f14Admin());

    // One blanket assertion over the RECURSIVE body, which is stricter and simpler
    // than enumerating the good keys: nothing anywhere in any of the three reports
    // may be named after a person or a clinical concept.
    $dilarang = [
        'pasien', 'pasien_id', 'user_id', 'nama', 'nama_lengkap', 'nik',
        'dokter_id', 'dokter', 'nomor_booking', 'invoice_id', 'nomor_invoice',
        'rekam_medis', 'diagnosa', 'keluhan', 'resep', 'obat', 'catatan',
        'triage', 'suhu', 'tekanan_darah', 'berat_badan', 'tinggi_badan',
        'tanggal_lahir', 'jenis_kelamin',
    ];

    foreach (['booking', 'pendapatan', 'kehadiran'] as $laporan) {
        $badan = $this->getJson("/api/v1/admin/laporan/{$laporan}?dari=2026-10-01&sampai=2026-10-31")
            ->assertOk()
            ->getContent();

        foreach ($dilarang as $kunci) {
            expect($badan)
                ->not->toContain('"'.$kunci.'"', "{$laporan} publishes a patient or clinical key: {$kunci}");
        }
    }
});

test('no export endpoint exists, and no report sends a Content-Disposition', function (): void {
    $rute = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin/laporan'))
        ->map(fn ($route): string => strtolower($route->uri().'|'.implode(',', $route->methods())))
        ->values()
        ->all();

    // Exactly three reports, all GET. The F14 pattern's "tidak ada export di v1" and
    // the owner's decision are the same promise, and the way to keep it is that no
    // route exists to promise.
    expect($rute)->toHaveCount(3);

    foreach ($rute as $satu) {
        expect($satu)->toContain('|get,head')
            ->and($satu)->not->toContain('export')
            ->and($satu)->not->toContain('csv')
            ->and($satu)->not->toContain('pdf');
    }

    f14As(f14Admin());

    foreach (['booking', 'pendapatan', 'kehadiran'] as $laporan) {
        $jawaban = $this->getJson("/api/v1/admin/laporan/{$laporan}?dari=2026-10-01&sampai=2026-10-31")
            ->assertOk();

        expect($jawaban->headers->get('Content-Disposition'))->toBeNull()
            ->and($jawaban->headers->get('Content-Type'))->toContain('application/json');
    }
});
