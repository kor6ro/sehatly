<?php

declare(strict_types=1);

use App\Enums\ResepStatus;
use App\Enums\ResepVerifikasiStatus;
use App\Models\Resep;
use App\Models\ResepItem;
use App\Models\ResepVerifikasi;
use App\Models\User;
use App\Services\Obat\ObatInteraksiService;
use App\Services\Resep\ResepStateMachine;
use App\Services\Resep\ResepVerifikasiService;
use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Todo 40 - prescription detail, pharmacist verification, interaction re-check
|            and patient history
|--------------------------------------------------------------------------
|
| ## The acceptance criterion this file is built around
|
| A verification is TERMINAL, and the mechanism the DDL gives for it is
| `resep_verifikasi.resep_id BIGINT UNSIGNED NOT NULL UNIQUE` at
| `telemedicine_test.sql:788` - a prescription can be verified exactly once,
| ever. A pharmacist who answers `ditolak` has therefore spent the one
| verification the row can hold: the prescription cannot be corrected and
| re-submitted, because there is no `resep` status meaning "returned for
| correction" either. `ResepVerifikasiService::verifikasi()` documents that and
| this file PROVES it six ways: the first `ditolak` succeeds, a second attempt
| from a different pharmacist with a different outcome is refused with a 422, a
| third from the same pharmacist is refused identically, `resep.status` is not
| walked backwards by any of it, exactly one row survives, and the read side
| publishes the rejection as terminal.
|
| ## The DDL citations here were read from the file, not from the plan
|
| The plan's todo 40 cites `:750` for the eight `resep.status` members. `:750`
| is `tipe ENUM('digital','manual')`. The status column is at `:751`-`:752` and
| it WRAPS onto a second line, so reading `:751` alone yields five members and
| makes the column look like a different one. The plan also cites `:789` for
| `resep_verifikasi.status`; `:789` is `apoteker_user_id`, and the ENUM is at
| `:790`. Every line number this file uses is asserted against the raw file by
| `rx40AssertLine()` and re-derived from the parsed DDL by `rx40Enum()`, so a
| citation that moves fails here rather than invalidating the reasoning
| quietly.
|
| ## Every status assertion reads the DATABASE back, never a model
|
| Every status assertion goes through `rx40StatusDiDb()` or the HTTP response.
| A test that asserted a property of an in-memory object would pass even if the
| transition had been rolled back.
|
| @see \App\Services\Resep\ResepVerifikasiService
| @see \App\Services\Resep\ResepStateMachine
| @see \App\Services\Resep\ResepAccess
*/

require_once __DIR__.'/resep40-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    Carbon::setTestNow(rx40Jam());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * The `resep.status` value as the DATABASE holds it, never as a model holds it.
 */
function rx40StatusDiDb(int $resepId): string
{
    return (string) DB::table('resep')->where('id', $resepId)->value('status');
}

// =============================================================
// 0. The DDL, read from the file
// =============================================================

test('the citations this file reasons from are the ones the DDL carries', function (): void {
    // :742 declares `resep`, and :750 is `tipe` - NOT `status`.
    rx40AssertLine(742, 'CREATE TABLE resep (');
    rx40AssertLine(750, "tipe ENUM('digital','manual')");
    expect((string) rx40DdlLine(750))->not->toContain('diverifikasi')
        ->and(substr_count((string) rx40DdlLine(750), 'ENUM('))->toBe(1);

    // The eight members, spanning TWO lines, in the DDL's own order.
    rx40AssertLine(751, "status ENUM('aktif','diproses','diverifikasi','dipenuhi','dikirim','selesai',");
    rx40AssertLine(752, "'kedaluwarsa','dibatalkan') NOT NULL DEFAULT 'aktif',");

    // :758 is `qr_token` and it is NOT unique, so the detail response publishes
    // it verbatim rather than implying an index backs it.
    rx40AssertLine(758, 'qr_token VARCHAR(100) NOT NULL');
    expect((string) rx40DdlLine(758))->not->toContain('UNIQUE');

    rx40AssertLine(786, 'CREATE TABLE resep_verifikasi (');
    rx40AssertLine(788, 'resep_id BIGINT UNSIGNED NOT NULL UNIQUE,');
    rx40AssertLine(789, 'apoteker_user_id BIGINT UNSIGNED NOT NULL,');
    rx40AssertLine(790, "status ENUM('sesuai','ada_koreksi','ditolak') NOT NULL,");
    rx40AssertLine(791, 'catatan TEXT NULL,');
    rx40AssertLine(792, 'diverifikasi_at DATETIME NOT NULL,');
});

test('the eight status members and the three outcomes come from the parsed DDL', function (): void {
    expect(rx40Enum('resep', 'tipe'))->toBe(['digital', 'manual']);

    expect(rx40Enum('resep', 'status'))->toBe([
        'aktif',
        'diproses',
        'diverifikasi',
        'dipenuhi',
        'dikirim',
        'selesai',
        'kedaluwarsa',
        'dibatalkan',
    ]);

    expect(rx40Enum('resep_verifikasi', 'status'))->toBe(['sesuai', 'ada_koreksi', 'ditolak']);

    // Eight, not the two the plan's own prose used to name, and the plan's
    // `IN ('aktif','diproses')` for "currently on" omits three of them.
    expect(ResepStatus::nilai())->toBe(rx40Enum('resep', 'status'))
        ->and(ResepStatus::nilai())->toHaveCount(8)
        ->and(ResepVerifikasiStatus::nilai())->toBe(rx40Enum('resep_verifikasi', 'status'))
        ->and(ObatInteraksiService::STATUS_BERLAKU)
        ->toBe(['aktif', 'diproses', 'diverifikasi', 'dipenuhi', 'dikirim'])
        ->and(ObatInteraksiService::STATUS_AKHIR)->toBe(['selesai', 'kedaluwarsa', 'dibatalkan']);

    // The two sets partition the enum exactly, so a ninth member fails here
    // rather than silently joining one of them.
    expect([...ObatInteraksiService::STATUS_BERLAKU, ...ObatInteraksiService::STATUS_AKHIR])
        ->toBe(ResepStatus::nilai());
});

test('resep_verifikasi is UNIQUE on resep_id alone and carries no timestamp column', function (): void {
    $tabel = rx40Spec()->table('resep_verifikasi');

    expect($tabel->columns['resep_id']->line)->toBe(788)
        ->and($tabel->columns['apoteker_user_id']->line)->toBe(789)
        ->and($tabel->columns['status']->line)->toBe(790)
        ->and($tabel->columns['catatan']->line)->toBe(791)
        ->and($tabel->columns['diverifikasi_at']->line)->toBe(792);

    // `resep_verifikasi` declares neither `dibuat_at` nor `diubah_at`, so the
    // model is not timestamped and `diverifikasi_at` is the ONLY record of
    // when the pharmacist acted.
    expect(array_keys($tabel->columns))->toBe([
        'id', 'resep_id', 'apoteker_user_id', 'status', 'catatan', 'diverifikasi_at',
    ]);

    // The uniqueness is over `resep_id` ALONE - not a composite - which is what
    // makes the second attempt impossible rather than merely discouraged.
    $unik = array_values(array_filter(
        $tabel->indexes,
        static fn ($index): bool => $index->type === 'UNIQUE' && $index->columns === ['resep_id'],
    ));

    expect($unik)->toHaveCount(1);
    expect((new ResepVerifikasi)->usesTimestamps())->toBeFalse();
});

// =============================================================
// 1. The four routes
// =============================================================

test('four routes ship, and the prefix filter the plan names answers three', function (): void {
    $routes = rx40Routes();

    expect(array_keys($routes))->toBe([
        'GET api/v1/pasien/resep',
        'GET api/v1/resep/{id}',
        'GET api/v1/resep/{id}/cek-interaksi',
        'POST api/v1/resep/{id}/verifikasi',
    ]);

    // The plan's acceptance criterion says `route:list --path=api/v1/resep`
    // "lists 4 routes". It answers 3, because `api/v1/pasien/resep` is a
    // `pasien` path and a prefix filter cannot see it. The same defect todo 32
    // found for `konsultasi`, todo 33 for `rekam-medis` and todo 34 for
    // `surat_keterangan`; the route ships and the count is recorded rather
    // than satisfied by deleting an endpoint.
    $terfilter = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1/resep'))
        ->count();

    expect($terfilter)->toBe(3);

    foreach (array_keys($routes) as $key) {
        // Pest's `toContain` is variadic, so a second argument is read as
        // another NEEDLE rather than as a message; both are asserted alone.
        expect(rx40Guards($routes, $key))->toContain('auth:sanctum');
    }

    // The verification write is pharmacist-only AND holds the catalogue code;
    // the three reads carry the read code and no `tipe:`.
    expect(rx40Guards($routes, 'POST api/v1/resep/{id}/verifikasi'))
        ->toContain('tipe:apoteker', 'permission:resep.verifikasi');

    foreach ([
        'GET api/v1/resep/{id}',
        'GET api/v1/resep/{id}/cek-interaksi',
        'GET api/v1/pasien/resep',
    ] as $baca) {
        expect(rx40Guards($routes, $baca))
            ->toContain('permission:resep.lihat')
            ->not->toContain('tipe:apoteker', 'tipe:dokter');
    }

    // Every gate is a REAL catalogue value. `EnsurePermission` answers an
    // unknown code with a 500, and `RbacCatalog` uses Indonesian verbs, so
    // `resep.verify` or `resep.read` would build-break rather than 403.
    foreach (array_keys($routes) as $key) {
        foreach (rx40Guards($routes, $key) as $middleware) {
            if (str_starts_with($middleware, 'permission:')) {
                expect(RbacCatalog::isPermission(substr($middleware, strlen('permission:'))))->toBeTrue();
            }

            if (str_starts_with($middleware, 'tipe:')) {
                foreach (explode(',', substr($middleware, strlen('tipe:'))) as $tipe) {
                    expect(RbacCatalog::isUserType($tipe))->toBeTrue();
                }
            }
        }
    }

    expect(RbacCatalog::permissionsFor('apoteker'))->toContain('resep.verifikasi')
        ->and(RbacCatalog::permissionsFor('apoteker'))->toContain('resep.lihat')
        ->and(RbacCatalog::permissionsFor('dokter'))->not->toContain('resep.verifikasi')
        ->and(RbacCatalog::permissionsFor('pasien'))->toContain('resep.lihat')
        ->and(RbacCatalog::permissionsFor('pasien'))->not->toContain('resep.verifikasi')
        ->and(RbacCatalog::permissionsFor('admin'))->not->toContain('resep.lihat');
});

test('a prescription nobody may read is a 404, and an ungranted caller is a 403', function (): void {
    $milik = rx40DoctorAccount();
    $obat = rx40Obat('Amoxicillin');
    $resep = rx40Resep($milik['pasien'], $milik['dokter'], [$obat]);
    $path = '/api/v1/resep/'.$resep->getKey();

    // The prescribing doctor, the patient, and a pharmacist all read it.
    $this->withHeaders(rx40As($milik['user']))->getJson($path)->assertOk();
    $this->withHeaders(rx40As($milik['pasienUser']))->getJson($path)->assertOk();

    $apoteker = rx40Pengguna('apoteker', 'apoteker');
    $this->withHeaders(rx40As($apoteker))->getJson($path)->assertOk();

    // `superadmin` holds `resep.lihat` and is an oversight account, so it reads
    // any prescription - the disjunction the plan names, which a route gate
    // can only express as a conjunction.
    $super = rx40Pengguna('superadmin', 'superadmin');
    $this->withHeaders(rx40As($super))->getJson($path)->assertOk();

    // Another PATIENT is 404, not 403: a 403 would confirm the row exists,
    // which is the cross-tenant existence oracle the whole project refuses.
    $pasienUserLain = rx40Pengguna('pasien', 'pasien');
    rx40Pasien($pasienUserLain->getKey());

    $this->withHeaders(rx40As($pasienUserLain))
        ->getJson($path)
        ->assertStatus(404)
        ->assertExactJson(['success' => false, 'message' => 'Resource not found.', 'errors' => []]);

    // Another prescribing doctor is 404 for the same reason. The fixture
    // already returns the `dokter` row, so the account is reused whole rather
    // than inserting a SECOND `dokter` row for a user who already has one -
    // `dokter.user_id` is `BIGINT UNSIGNED NOT NULL UNIQUE` (:411), and the
    // duplicate key the original spelling raised is how that was diagnosed.
    $akunLain = rx40DoctorAccount();
    $dokterLain = $akunLain['dokter'];
    DB::table('resep')->where('id', $resep->getKey())->update(['dokter_id' => $dokterLain]);

    $this->withHeaders(rx40As($milik['user']))->getJson($path)->assertStatus(404);

    // An `admin` holds NO `resep.lihat`, so it is refused in the middleware.
    $admin = rx40Pengguna('admin', 'admin');
    $this->withHeaders(rx40As($admin))->getJson($path)->assertStatus(403);

    // An id that does not exist is 404 for everyone, the pharmacist included.
    $this->withHeaders(rx40As($apoteker))->getJson('/api/v1/resep/999999')->assertStatus(404);

    // And the re-check exposes the same warning set as the detail, so it must
    // not become a way around the detail's ownership rule.
    $this->withHeaders(rx40As($pasienUserLain))
        ->getJson('/api/v1/resep/'.$resep->getKey().'/cek-interaksi')
        ->assertStatus(404);
});

// =============================================================
// 2. Detail, and the expiry flag
// =============================================================

test('detail publishes items, qr_token, berlaku_sampai and the verification', function (): void {
    $amox = rx40Obat('Amoxicillin', ['harga_jual' => '7500.00']);
    $metformin = rx40Obat('Metformin');
    $akun = rx40DoctorAccount();
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [$amox, $metformin], 'diproses');
    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$resep->getKey().'/verifikasi', [
            'status' => 'ada_koreksi',
            'catatan' => 'Dosis metformin diturunkan menjadi 500 mg.',
        ])
        ->assertCreated();

    $respons = $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep/'.$resep->getKey())
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resep.id', $resep->getKey())
        ->assertJsonPath('data.resep.status', 'diverifikasi')
        ->assertJsonPath('data.resep.is_kedaluwarsa', false)
        ->assertJsonPath('data.resep.berlaku_sampai', RX40_BERLAKU)
        ->assertJsonPath('data.verifikasi.status', 'ada_koreksi')
        ->assertJsonPath('data.verifikasi.catatan', 'Dosis metformin diturunkan menjadi 500 mg.')
        ->assertJsonPath('data.verifikasi.apoteker.nama_lengkap', $apoteker->nama_lengkap)
        ->assertJsonPath('data.verifikasi.terminal', true);

    expect($respons->json('data.resep.qr_token'))->toBe($resep->qr_token)
        ->and($respons->json('data.resep.items'))->toHaveCount(2)
        ->and($respons->json('data.resep.items.0.nama_obat'))->toBe('Amoxicillin')
        ->and($respons->json('data.resep.items.1.nama_obat'))->toBe('Metformin');

    // A list of the three warning sources, so a client renders one panel each.
    expect(array_keys($respons->json('data.warning_grup')))->toBe(ObatInteraksiService::SUMBER);

    // The token is a v4 UUID and is NOT unique in the schema, so the detail
    // response publishes it verbatim rather than implying an index backs it.
    expect($respons->json('data.resep.qr_token'))->toHaveLength(36)
        ->and(rx40StatusDiDb($resep->getKey()))->toBe('diverifikasi');
});

test('a prescription with no verification yet publishes null, not a zero', function (): void {
    $akun = rx40DoctorAccount();
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Amoxicillin')]);

    $this->withHeaders(rx40As($akun['user']))
        ->getJson('/api/v1/resep/'.$resep->getKey())
        ->assertOk()
        ->assertJsonPath('data.verifikasi', null)
        ->assertJsonPath('data.resep.status', 'aktif');
});

test('is_kedaluwarsa flips on the date alone, with no status change', function (): void {
    $akun = rx40DoctorAccount();
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Amoxicillin')]);
    $path = '/api/v1/resep/'.$resep->getKey();

    $this->withHeaders(rx40As($akun['user']))->getJson($path)
        ->assertOk()
        ->assertJsonPath('data.resep.is_kedaluwarsa', false)
        // The flag is a comparison AGAINST this value, so the comparison's
        // right-hand side is asserted rather than assumed.
        ->assertJsonPath('data.resep.berlaku_sampai', RX40_BERLAKU)
        ->assertJsonPath('data.resep.status', 'aktif');

    // `berlaku_sampai` is a DATE (`:755`) and NOTHING in the schema reacts to
    // it: no trigger, no generated column, no event. So the flag is a PHP
    // comparison against the clock, and the clock is what moves here.
    Carbon::setTestNow(Carbon::parse('2026-03-19 08:00:00'));

    $this->withHeaders(rx40As($akun['user']))->getJson($path)
        ->assertOk()
        ->assertJsonPath('data.resep.is_kedaluwarsa', true)
        // The STATUS is untouched. Nothing rewrites it, and this assertion is
        // what proves the flag is computed rather than read off a column
        // somebody changed.
        ->assertJsonPath('data.resep.status', 'aktif');

    expect(rx40StatusDiDb($resep->getKey()))->toBe('aktif');

    // The boundary is INCLUSIVE, matching `ObatInteraksiService`: valid
    // through today means still valid today.
    Carbon::setTestNow(Carbon::parse('2026-03-18 23:59:59'));

    $this->withHeaders(rx40As($akun['user']))->getJson($path)
        ->assertJsonPath('data.resep.is_kedaluwarsa', false);

    // And the stored `kedaluwarsa` status is honoured whatever the date says.
    DB::table('resep')->where('id', $resep->getKey())->update(['status' => 'kedaluwarsa']);
    Carbon::setTestNow(rx40Jam());

    $this->withHeaders(rx40As($akun['user']))->getJson($path)
        ->assertJsonPath('data.resep.is_kedaluwarsa', true)
        ->assertJsonPath('data.resep.status', 'kedaluwarsa');
});

// =============================================================
// 3. THE STATE MACHINE
// =============================================================

test('the transition map covers the eight states and refuses every illegal step', function (): void {
    $mesin = app(ResepStateMachine::class);

    // Every key is a real `resep.status` member and every value is one too, so
    // a typo is a failure here rather than a row MySQL would truncate.
    expect(array_keys(ResepStateMachine::TRANSISI))->toBe(ResepStatus::nilai())
        ->and(ResepStateMachine::TERMINAL)->toBe(['selesai', 'kedaluwarsa', 'dibatalkan']);

    foreach (ResepStateMachine::TRANSISI as $dari => $ke) {
        // The keys were already pinned positionally against the enum above, so
        // this loop only has to prove every TARGET is a real member too - a
        // typo in a value would otherwise be a row MySQL would truncate.
        foreach ($ke as $tujuan) {
            expect(in_array($tujuan, ResepStatus::nilai(), true))->toBeTrue();
            expect($mesin->boleh($dari, $tujuan))->toBeTrue();
        }
    }

    // The acceptance criterion the plan names: `selesai -> aktif` is illegal.
    expect($mesin->boleh('selesai', 'aktif'))->toBeFalse();

    // Every ordered pair not in the map is refused. The map is a LIFECYCLE, not
    // a symmetric relation, so `a -> b` legal does not make `b -> a` legal.
    $legal = [];
    foreach (ResepStateMachine::TRANSISI as $dari => $ke) {
        foreach ($ke as $tujuan) {
            $legal[$dari.'>'.$tujuan] = true;
        }
    }

    $ditolak = 0;
    foreach (ResepStatus::nilai() as $dari) {
        foreach (ResepStatus::nilai() as $ke) {
            if ($dari === $ke || isset($legal[$dari.'>'.$ke])) {
                continue;
            }

            expect($mesin->boleh($dari, $ke))->toBeFalse();
            $ditolak++;
        }
    }

    // 8 self-pairs and 8x8 = 64 ordered pairs. The map has 15 edges, so
    // 64 - 15 = 49 illegal ordered pairs, of which 8 are the self-pairs the
    // loop skips, leaving 41 to be refused.
    expect($ditolak)->toBe(41);
    expect(array_sum(array_map('count', ResepStateMachine::TRANSISI)))->toBe(15);

    foreach (['selesai', 'kedaluwarsa', 'dibatalkan'] as $akhir) {
        expect(ResepStateMachine::TRANSISI[$akhir])->toBe([]);
    }

    // And the path the lifecycle actually walks.
    expect($mesin->boleh('aktif', 'diproses'))->toBeTrue()
        ->and($mesin->boleh('diproses', 'diverifikasi'))->toBeTrue()
        ->and($mesin->boleh('diverifikasi', 'dipenuhi'))->toBeTrue()
        ->and($mesin->boleh('dipenuhi', 'dikirim'))->toBeTrue()
        ->and($mesin->boleh('dikirim', 'selesai'))->toBeTrue()
        // The SHORTCUT is the point: a prescription is verified off the
        // pharmacy pickup step, never straight from `aktif`.
        ->and($mesin->boleh('aktif', 'diverifikasi'))->toBeFalse()
        ->and($mesin->boleh('aktif', 'dipenuhi'))->toBeFalse()
        ->and($mesin->boleh('diproses', 'dipenuhi'))->toBeFalse();

    // A state the ENUM does not hold is refused rather than tolerated, so a
    // typo in a status string cannot walk a prescription into a new state.
    expect($mesin->boleh('aktif', 'AKTIF'))->toBeFalse()
        ->and($mesin->boleh('aktif', 'menunggu_pembayaran'))->toBeFalse();
});

test('an illegal transition is a 422 carrying BOTH reasons on one field', function (): void {
    $mesin = app(ResepStateMachine::class);

    // The refusal the plan's acceptance criterion names: `selesai -> aktif`.
    // It is asserted on the MACHINE rather than over HTTP, and that is the
    // honest place: a prescription in a state the pharmacy can no longer sign is
    // refused earlier by `pastikanBisaDiverifikasi()`, so the state machine's
    // own 422 is the BACKSTOP for a caller that walks a status directly - a
    // checkout in todo 46, an expiry sweep, a cancellation - rather than a path
    // this endpoint reaches. A guard that is unreachable from a route is a
    // guard, and pretending otherwise would be the real defect.
    try {
        $mesin->pastikan('selesai', 'aktif');
        $this->fail('the state machine allowed selesai -> aktif');
    } catch (ValidationException $e) {
        $pesan = $e->errors()['status'];

        expect($pesan)->toHaveCount(2)
            ->and($pesan[0])->toBe('Transisi resep tidak diperbolehkan.')
            ->and($pesan[1])->toContain('selesai')
            ->and($pesan[1])->toContain('aktif');

        // And the field it is filed on is configurable, so a caller whose input
        // is a different column can point the message at that column.
        expect(static fn () => $mesin->pastikan('dibatalkan', 'diproses', 'transisi'))
            ->toThrow(ValidationException::class);

        try {
            $mesin->pastikan('dibatalkan', 'diproses', 'transisi');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('transisi')
                ->and($e->errors())->not->toHaveKey('status');
        }
    }

    // Over HTTP the same prescription is refused, and refused EARLIER and with
    // the more useful reason: it has already passed the verification stage.
    $akun = rx40DoctorAccount();
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Amoxicillin')], 'selesai');
    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    $respons = $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$resep->getKey().'/verifikasi', ['status' => 'sesuai'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.');

    $pesan = $respons->json('errors.status');

    expect($pesan)->toBeArray()
        ->and($pesan)->toHaveCount(2)
        ->and($pesan[0])->toBe('Resep tidak dapat diverifikasi pada statusnya sekarang.')
        ->and($pesan[1])->toContain('selesai')
        ->and(array_keys($respons->json()))->toBe(['success', 'message', 'errors']);

    // A refused transition leaves NOTHING behind: no verification row, and the
    // status is exactly what it was.
    expect(rx40Verifikasi($resep->getKey()))->toBeNull();
    expect(rx40StatusDiDb($resep->getKey()))->toBe('selesai');
});

test('a verification off `aktif` walks diproses first, never the shortcut', function (): void {
    $akun = rx40DoctorAccount();
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Amoxicillin')], 'aktif');
    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    // The SQL is captured rather than inferred: the row must be UPDATEd to
    // `diproses` and then to `diverifikasi`, so the two-step is observable
    // rather than asserted.
    $dilihat = [];
    DB::listen(function ($query) use (&$dilihat): void {
        $sql = (string) $query->sql;

        if (str_starts_with(strtolower(trim($sql)), 'update `resep`') && str_contains($sql, '`status`')) {
            $dilihat[] = (string) ($query->bindings[0] ?? '');
        }
    });

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$resep->getKey().'/verifikasi', ['status' => 'sesuai'])
        ->assertCreated()
        ->assertJsonPath('data.resep.status', 'diverifikasi');

    expect($dilihat)->toBe(['diproses', 'diverifikasi'])
        ->and(rx40StatusDiDb($resep->getKey()))->toBe('diverifikasi');
});

test('only a diverifikasi (or later) prescription may be checked out', function (): void {
    $layanan = app(ResepVerifikasiService::class);
    $akun = rx40DoctorAccount();
    $obat = rx40Obat('Amoxicillin');

    foreach (['diverifikasi', 'dipenuhi', 'dikirim'] as $status) {
        $resep = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], $status);

        $layanan->siapDipenuhi(Resep::query()->findOrFail($resep->getKey()));
    }

    foreach (['aktif', 'diproses', 'selesai', 'kedaluwarsa', 'dibatalkan'] as $status) {
        $resep = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], $status);

        try {
            $layanan->siapDipenuhi(Resep::query()->findOrFail($resep->getKey()));
            $this->fail('a '.$status.' prescription reached checkout');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('status');
        }
    }

    // A prescription that was REJECTED can never be checked out, which is the
    // other half of the terminality the UNIQUE key creates.
    $ditolak = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'aktif');
    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$ditolak->getKey().'/verifikasi', ['status' => 'ditolak'])
        ->assertCreated();

    expect(rx40StatusDiDb($ditolak->getKey()))->toBe('dibatalkan');

    $this->expectException(ValidationException::class);
    $layanan->siapDipenuhi(Resep::query()->findOrFail($ditolak->getKey()));
});

// =============================================================
// 4. THE ACCEPTANCE CRITERION: terminal rejection
// =============================================================

test('a rejection is TERMINAL: the second attempt is refused and the status is not walked back', function (): void {
    $akun = rx40DoctorAccount();
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Amoxicillin')], 'diproses');
    $apoteker = rx40Pengguna('apoteker', 'apoteker');
    $path = '/api/v1/resep/'.$resep->getKey().'/verifikasi';

    // 1. The pharmacist REJECTS. The first verification succeeds.
    $this->withHeaders(rx40As($apoteker))
        ->postJson($path, ['status' => 'ditolak', 'catatan' => 'Dosis melebihi dosis harian maksimum.'])
        ->assertCreated()
        ->assertJsonPath('data.verifikasi.status', 'ditolak')
        ->assertJsonPath('data.terminal', true)
        ->assertJsonPath('data.resep.status', 'dibatalkan');

    $baris = rx40Verifikasi($resep->getKey());

    expect($baris)->not->toBeNull()
        ->and((string) $baris->apoteker_user_id)->toBe((string) $apoteker->getKey())
        ->and((string) $baris->catatan)->toBe('Dosis melebihi dosis harian maksimum.')
        ->and($baris->diverifikasi_at)->not->toBeNull();

    // 2. The SECOND attempt - same prescription, a DIFFERENT pharmacist, a
    // different outcome - is refused with a 422 that names the mechanism.
    $apotekerLain = rx40Pengguna('apoteker', 'apoteker');

    $kedua = $this->withHeaders(rx40As($apotekerLain))
        ->postJson($path, ['status' => 'sesuai'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.');

    $pesan = $kedua->json('errors.status');

    expect($pesan)->toBeArray()
        ->and($pesan)->toHaveCount(2)
        ->and($pesan[0])->toContain('sudah diverifikasi')
        ->and($pesan[1])->toContain('UNIQUE');

    // 3. `resep.status` is NOT walked backwards. It is the terminal state the
    // rejection moved it to, unchanged by the refusal, so the prescription can
    // never reach `diverifikasi` and so can never be dispensed.
    expect(rx40StatusDiDb($resep->getKey()))->toBe('dibatalkan');

    // 4. Exactly ONE verification row exists and the first pharmacist is still
    // the one on it: the refused attempt overwrote nothing.
    expect(DB::table('resep_verifikasi')->where('resep_id', $resep->getKey())->count())->toBe(1)
        ->and((string) rx40Verifikasi($resep->getKey())->apoteker_user_id)
        ->toBe((string) $apoteker->getKey())
        ->and((string) rx40Verifikasi($resep->getKey())->status)->toBe('ditolak');

    // 5. A third attempt from the SAME pharmacist is refused identically, and
    // so is one that tries to rewrite the note. Terminal means terminal.
    foreach (['sesuai', 'ada_koreksi', 'ditolak'] as $percobaan) {
        $this->withHeaders(rx40As($apoteker))
            ->postJson($path, ['status' => $percobaan, 'catatan' => 'Mencoba menimpa.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', $pesan[0]);
    }

    expect((string) rx40Verifikasi($resep->getKey())->catatan)
        ->toBe('Dosis melebihi dosis harian maksimum.')
        ->and(DB::table('resep_verifikasi')->where('resep_id', $resep->getKey())->count())->toBe(1)
        ->and(rx40StatusDiDb($resep->getKey()))->toBe('dibatalkan');

    // 6. And the read side agrees: the detail publishes the rejection as
    // terminal, so a client cannot render a "resubmit" affordance.
    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep/'.$resep->getKey())
        ->assertOk()
        ->assertJsonPath('data.resep.status', 'dibatalkan')
        ->assertJsonPath('data.resep.terminal', true)
        ->assertJsonPath('data.verifikasi.status', 'ditolak')
        ->assertJsonPath('data.verifikasi.terminal', true);
});

test('the UNIQUE on resep_verifikasi.resep_id is the mechanism, never a 500', function (): void {
    $akun = rx40DoctorAccount();
    $apoteker = rx40Pengguna('apoteker', 'apoteker');
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Amoxicillin')]);

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$resep->getKey().'/verifikasi', ['status' => 'sesuai'])
        ->assertCreated();

    // The constraint is the DDL's, not an application pre-check that could be
    // forgotten: this inserts the SECOND row through the query builder, which
    // is exactly what a race between two pharmacists would do, and asserts
    // MySQL itself refuses it.
    expect(static fn () => DB::table('resep_verifikasi')->insert([
        'resep_id' => $resep->getKey(),
        'apoteker_user_id' => $apoteker->getKey(),
        'status' => 'sesuai',
        'catatan' => null,
        'diverifikasi_at' => rx40Jam(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    // The service turns that SAME violation into a deliberate 422 rather than
    // letting it escape as the sanitised 500 the envelope would otherwise
    // render. To prove the catch is reached rather than merely written, the
    // racing row is planted in a `creating` hook: the pre-check has ALREADY
    // answered "no row", and the row appears between that answer and the
    // INSERT. That is the interleaving a concurrent writer produces, and the
    // only thing that can stop it is the unique key.
    $target = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Paracetamol')]);
    $peristiwa = 'eloquent.creating: '.ResepVerifikasi::class;
    $ditanam = false;

    Event::listen($peristiwa, function () use ($target, $apoteker, &$ditanam): void {
        if ($ditanam) {
            return;
        }

        $ditanam = true;

        DB::table('resep_verifikasi')->insert([
            'resep_id' => $target->getKey(),
            'apoteker_user_id' => $apoteker->getKey(),
            'status' => 'ditolak',
            'catatan' => 'Dimasukkan oleh proses lain.',
            'diverifikasi_at' => rx40Jam(),
        ]);
    });

    try {
        $racing = $this->withHeaders(rx40As($apoteker))
            ->postJson('/api/v1/resep/'.$target->getKey().'/verifikasi', ['status' => 'sesuai'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    } finally {
        Event::forget($peristiwa);
    }

    expect($ditanam)->toBeTrue('the racing hook never fired, so the catch was not exercised');
    expect($racing->json('errors.status'))->toHaveCount(2)
        ->and($racing->json('errors.status.1'))->toContain('UNIQUE');

    // The collision rolled the whole transaction back, so the prescription is
    // untouched and `resep.status` did not move.
    expect(rx40StatusDiDb($target->getKey()))->toBe('aktif')
        ->and(DB::table('resep_verifikasi')->where('resep_id', $target->getKey())->count())->toBe(0);

    // No index or constraint was added to make any of this work. The verifier
    // table declares its PRIMARY plus the one inline UNIQUE, and `resep`
    // declares its PRIMARY, the inline UNIQUE on `nomor_resep` and
    // `idx_resep_pasien` - the same three it declared before this todo.
    expect(rx40Spec()->table('resep_verifikasi')->indexes)->toHaveCount(2);
    expect(rx40Spec()->table('resep')->indexes)->toHaveCount(3);

    $kunci = array_map(
        static fn ($index): string => $index->type.' ('.implode(',', $index->columns).')',
        rx40Spec()->table('resep_verifikasi')->indexes,
    );

    expect($kunci)->toBe(['PRIMARY (id)', 'UNIQUE (resep_id)']);
});

// =============================================================
// 5. Who may verify
// =============================================================

test('a doctor may not verify, and neither may the prescribing doctor', function (): void {
    $akun = rx40DoctorAccount();
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Amoxicillin')]);
    $path = '/api/v1/resep/'.$resep->getKey().'/verifikasi';

    // `tipe:apoteker` is on the route, so the prescribing doctor is refused in
    // the MIDDLEWARE - before the controller, before the service.
    $this->withHeaders(rx40As($akun['user']))
        ->postJson($path, ['status' => 'sesuai'])
        ->assertStatus(403)
        ->assertJsonPath('success', false);

    // A patient, an `admin` and a `superadmin` are refused too. `superadmin`
    // holds `resep.verifikasi` in `RbacCatalog::ROLE_PERMISSIONS` and is still
    // refused, because `tipe:apoteker` is what excludes it - the same
    // asymmetry todo 34 applies to `surat_keterangan.buat`.
    foreach ([['pasien', 'pasien'], ['admin', 'admin'], ['superadmin', 'superadmin'], ['perawat', null]] as [$tipe, $role]) {
        $this->withHeaders(rx40As(rx40Pengguna($tipe, $role)))
            ->postJson($path, ['status' => 'sesuai'])
            ->assertStatus(403);
    }

    // The service refuses it too, so the rule holds for a non-HTTP caller: an
    // account that IS a pharmacist and IS also the prescribing doctor cannot
    // sign off their own prescription.
    $ganda = rx40Pengguna('apoteker', 'apoteker');
    $dokternya = rx40Dokter($ganda->getKey());
    $milik = rx40Resep($akun['pasien'], $dokternya, [rx40Obat('Amoxicillin')]);

    $this->withHeaders(rx40As($ganda))
        ->postJson('/api/v1/resep/'.$milik->getKey().'/verifikasi', ['status' => 'sesuai'])
        ->assertStatus(403);

    expect(rx40Verifikasi($milik->getKey()))->toBeNull()
        ->and(rx40StatusDiDb($milik->getKey()))->toBe('aktif');
});

test('a pharmacist verifies all three outcomes and only the first two advance', function (): void {
    $akun = rx40DoctorAccount();
    $apoteker = rx40Pengguna('apoteker', 'apoteker');
    $obat = rx40Obat('Amoxicillin');

    foreach ([
        'sesuai' => 'diverifikasi',
        'ada_koreksi' => 'diverifikasi',
        'ditolak' => 'dibatalkan',
    ] as $hasil => $harus) {
        $resep = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'diproses');

        // `data.terminal` is true ONLY for the rejection, and the distinction
        // is the whole point: `resep_verifikasi.resep_id` is UNIQUE so the ONE
        // verification is spent either way, but only `ditolak` CLOSES the
        // prescription. A `sesui` prescription is still dispensed.
        $terminal = $hasil === 'ditolak';

        $this->withHeaders(rx40As($apoteker))
            ->postJson('/api/v1/resep/'.$resep->getKey().'/verifikasi', [
                'status' => $hasil,
                'catatan' => 'Catatan apoteker untuk '.$hasil.'.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.verifikasi.status', $hasil)
            // Every verification row is terminal in the "spent" sense...
            ->assertJsonPath('data.verifikasi.terminal', true)
            ->assertJsonPath('data.verifikasi.ditolak', $terminal)
            // ...and only a rejection closes the prescription.
            ->assertJsonPath('data.resep.terminal', $terminal)
            ->assertJsonPath('data.terminal', $terminal)
            ->assertJsonPath('data.resep.status', $harus);

        expect(rx40StatusDiDb($resep->getKey()))->toBe($harus);
    }
});

test('an unknown outcome, a missing required field and a bad payload are 422 and write nothing', function (): void {
    $akun = rx40DoctorAccount();
    $apoteker = rx40Pengguna('apoteker', 'apoteker');
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Amoxicillin')]);
    $path = '/api/v1/resep/'.$resep->getKey().'/verifikasi';

    $this->withHeaders(rx40As($apoteker))
        ->postJson($path, ['status' => 'setuju'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'The selected status is invalid.');

    $this->withHeaders(rx40As($apoteker))
        ->postJson($path, [])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'The status field is required.');

    // A note that is too long for the column it goes into, refused by name.
    $this->withHeaders(rx40As($apoteker))
        ->postJson($path, ['status' => 'sesuai', 'catatan' => str_repeat('x', 20001)])
        ->assertStatus(422)
        ->assertJsonPath('errors.catatan.0', 'The catatan field must not be greater than 20000 characters.');

    // The machine-owned columns are prohibited rather than dropped: a caller
    // who sends one is told by name, and cannot forge the signing pharmacist.
    foreach (['apoteker_user_id', 'resep_id', 'diverifikasi_at'] as $kolom) {
        $this->withHeaders(rx40As($apoteker))
            ->postJson($path, ['status' => 'sesuai', $kolom => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.'.$kolom.'.0', 'The '.$kolom.' field is prohibited.');
    }

    expect(rx40Verifikasi($resep->getKey()))->toBeNull()
        ->and(rx40StatusDiDb($resep->getKey()))->toBe('aktif');
});

// =============================================================
// 6. The interaction re-check, and the decision it forces
// =============================================================

test('a kontraindikasi that appears only at verification time demands a pharmacist note', function (): void {
    // THE DECISION, stated and tested.
    //
    // `obat_interaksi` and `pasien_alergi` are LIVE tables. A `kontraindikasi`
    // that did not exist when the doctor signed - a new interaction row, a
    // newly recorded allergy, a second prescription the patient started since -
    // is a fact the doctor's `catatan_dokter` acknowledgement (`:753`) does not
    // cover, because the doctor never saw it.
    //
    // So a pharmacist answering `sesuai` or `ada_koreksi` must ACKNOWLEDGE it,
    // on `resep_verifikasi.catatan` (`:791`). Without a note the verification
    // is a 422 and nothing is written - the mirror of todo 39's
    // `catatan_dodio` rule, applied to the second reader. `ditolak` is always
    // accepted without one, because a rejection IS the safe direction.
    $obatA = rx40Obat('Amoxicillin', ['kelas_terapi' => 'Antibiotik']);
    $obatB = rx40Obat('Metformin', ['kelas_terapi' => 'Antidiabetik']);
    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    // The CONTROL, first and in the same test: the same prescription shape, the
    // same pharmacist and the same payload, with nothing changed, verifies
    // cleanly. A patient of their OWN, so the control's prescription
    // contributes no `riwayat_resep` warning to the scenario below - and the
    // scenario's patient holds exactly TWO prescriptions, so `riwayat_resep`
    // reports one warning per clashing prescription and the count is
    // unambiguous.
    $kontrolPasien = rx40Pasien(rx40Pengguna('pasien', 'pasien')->getKey());
    $kontrolDokter = rx40Dokter(rx40Pengguna('dokter', 'dokter')->getKey());
    $kontrol = rx40Resep($kontrolPasien, $kontrolDokter, [$obatA], 'diproses');

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$kontrol->getKey().'/verifikasi', ['status' => 'sesuai'])
        ->assertCreated()
        ->assertJsonPath('data.warning', [])
        ->assertJsonPath('data.verifikasi.catatan', null);

    // A patient with TWO prescriptions, identical except that neither has been
    // verified. The interaction becomes true after both were written: the
    // second one gives the patient the partner drug.
    $pasienUser = rx40Pengguna('pasien', 'pasien');
    $pasien = rx40Pasien($pasienUser->getKey());
    $dokter = rx40Dokter(rx40Pengguna('dokter', 'dokter')->getKey());

    $resep = rx40Resep($pasien, $dokter, [$obatA], 'diproses');
    $lain = rx40Resep($pasien, $dokter, [$obatB], 'aktif');
    rx40Interaksi($obatA, $obatB, 'kontraindikasi', 'Kombinasi tidak direkomendasikan.');

    $path = '/api/v1/resep/'.$resep->getKey().'/verifikasi';

    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep/'.$resep->getKey().'/cek-interaksi')
        ->assertOk()
        ->assertJsonCount(1, 'data.warning')
        ->assertJsonPath('data.warning.0.tingkat', 'kontraindikasi')
        ->assertJsonPath('data.wajib_catatan', true)
        ->assertJsonPath('data.status', 'diproses');

    $ditolak = $this->withHeaders(rx40As($apoteker))
        ->postJson($path, ['status' => 'sesuai'])
        ->assertStatus(422);

    $pesan = $ditolak->json('errors.catatan');

    expect($pesan)->toBeArray()
        ->and($pesan)->toHaveCount(2)
        ->and($pesan[1])->toContain('kontraindikasi');

    // Nothing was written: no verification, and the status did not move. The
    // pharmacist can therefore still come back and answer properly.
    expect(rx40Verifikasi($resep->getKey()))->toBeNull()
        ->and(rx40StatusDiDb($resep->getKey()))->toBe('diproses');

    // With a note it succeeds, and the acknowledgement and the evidence that
    // demanded it travel in the SAME response.
    $catatan = 'Interaksi baru diketahui saat verifikasi; pasien akan dimonitor.';

    $this->withHeaders(rx40As($apoteker))
        ->postJson($path, ['status' => 'sesuai', 'catatan' => $catatan])
        ->assertCreated()
        ->assertJsonPath('data.resep.status', 'diverifikasi')
        ->assertJsonCount(1, 'data.warning')
        ->assertJsonPath('data.warning.0.tingkat', 'kontraindikasi')
        ->assertJsonPath('data.warning.0.sumber', 'riwayat_resep')
        ->assertJsonPath('data.warning.0.wajib_catatan_dokter', true)
        ->assertJsonPath('data.verifikasi.catatan', $catatan);

    expect((string) rx40Verifikasi($resep->getKey())->catatan)->toBe($catatan)
        ->and($lain->getKey())->toBeInt();

    // `ditolak` needs no note at all - a rejection is the safe direction, and
    // demanding an acknowledgement before recording one would be perverse.
    $ketiga = rx40Resep($pasien, $dokter, [$obatA], 'diproses');

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$ketiga->getKey().'/verifikasi', ['status' => 'ditolak'])
        ->assertCreated()
        ->assertJsonPath('data.verifikasi.catatan', null)
        ->assertJsonPath('data.warning.0.tingkat', 'kontraindikasi')
        ->assertJsonPath('data.resep.status', 'dibatalkan');
});

test('a warning below kontraindikasi is reported but does not demand a note', function (): void {
    $obatA = rx40Obat('Amoxicillin', ['kelas_terapi' => 'Antibiotik']);
    $obatB = rx40Obat('Metformin', ['kelas_terapi' => 'Antidiabetik']);
    $akun = rx40DoctorAccount();
    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [$obatA], 'diproses');
    rx40Resep($akun['pasien'], $akun['dokter'], [$obatB], 'aktif');
    rx40Interaksi($obatA, $obatB, 'berat', 'Perlu pemantauan dosis.');

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$resep->getKey().'/verifikasi', ['status' => 'sesuai'])
        ->assertCreated()
        ->assertJsonPath('data.warning.0.tingkat', 'berat')
        ->assertJsonPath('data.warning.0.wajib_catatan_dokter', false)
        ->assertJsonPath('data.resep.status', 'diverifikasi');
});

test('a rejection removes the prescription from what the patient is on', function (): void {
    // The reason a rejection advances the prescription to `dibatalkan` rather
    // than leaving it where it was. `ObatInteraksiService::STATUS_BERLAKU` is
    // the five LIVE of the eight `resep.status` members, and a rejected
    // prescription is not one of the things a patient is taking. Leaving it at
    // `diproses` would make the engine tell the NEXT doctor that the patient is
    // on a drug the pharmacy refused to dispense - a safety-relevant false
    // statement made by omission.
    $obatA = rx40Obat('Amoxicillin', ['kelas_terapi' => 'Antibiotik']);
    $obatB = rx40Obat('Metformin', ['kelas_terapi' => 'Antidiabetik']);
    rx40Interaksi($obatA, $obatB, 'berat');

    $apoteker = rx40Pengguna('apoteker', 'apoteker');
    $pasienUser = rx40Pengguna('pasien', 'pasien');
    $pasien = rx40Pasien($pasienUser->getKey());
    $dokter = rx40Dokter(rx40Pengguna('dokter', 'dokter')->getKey());
    $disimpan = rx40Resep($pasien, $dokter, [$obatA], 'diproses');
    $belum = rx40Resep($pasien, $dokter, [$obatB], 'aktif');

    // Before: the patient's own second prescription warns about the pair.
    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep/'.$belum->getKey().'/cek-interaksi')
        ->assertOk()
        ->assertJsonCount(1, 'data.warning')
        ->assertJsonPath('data.warning.0.sumber', 'riwayat_resep');

    // Reject it, then ask again.
    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$disimpan->getKey().'/verifikasi', ['status' => 'ditolak'])
        ->assertCreated()
        ->assertJsonPath('data.resep.status', 'dibatalkan');

    expect(rx40StatusDiDb($disimpan->getKey()))->toBe('dibatalkan');

    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep/'.$belum->getKey().'/cek-interaksi')
        ->assertOk()
        ->assertJsonPath('data.warning', []);
});

test('the re-check runs the engine unchanged, on the STORED items', function (): void {
    // The service must not re-implement a rule. `ObatInteraksiService` is
    // injected and its own `peringatan()` is the only thing called; this test
    // pins the CALL by asserting the warning shape is the engine's own, so
    // bidirectionality, worst-severity de-duplication and allergy equality are
    // observed through this endpoint rather than re-derived by a second
    // implementation of any of them.
    $obatA = rx40Obat('Amoxicillin', ['nama_brand' => 'Amoxsan', 'kelas_terapi' => 'Antibiotik']);
    $obatB = rx40Obat('Metformin', ['kelas_terapi' => 'Antidiabetik']);
    $akun = rx40DoctorAccount();
    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    // Stored as the REVERSE of the canonical order, so only a bidirectional
    // lookup can find it at all.
    rx40Interaksi($obatB, $obatA, 'kontraindikasi', 'Kombinasi tidak direkomendasikan.');

    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [$obatA, $obatB], 'diproses');

    $respons = $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep/'.$resep->getKey().'/cek-interaksi')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.warning')
        ->assertJsonPath('data.warning.0.tingkat', 'kontraindikasi')
        ->assertJsonPath('data.warning.0.sumber', 'antar_item')
        ->assertJsonPath('data.warning.0.wajib_catatan_dokter', true)
        // Canonical orientation, lower id first, whatever order they were
        // stored in - `pasanganKanonik()`, the one place it is decided.
        ->assertJsonPath('data.warning.0.obat_a.id', min($obatA, $obatB))
        ->assertJsonPath('data.warning.0.obat_b.id', max($obatA, $obatB))
        ->assertJsonPath('data.warning.0.rincian.ganda', false)
        ->assertJsonPath('data.warning.0.rincian.arah_tersimpan', [$obatB, $obatA]);

    expect(array_keys($respons->json('data.warning_grup')))->toBe(ObatInteraksiService::SUMBER)
        ->and($respons->json('data.warning_grup.antar_item'))->toHaveCount(1)
        ->and($respons->json('data.warning_grup.riwayat_resep'))->toBe([])
        ->and($respons->json('data.warning_grup.alergi'))->toBe([]);

    // The re-check reads the STORED snapshot, so a racikan - which has
    // `obat_id` NULL (`:770`) and therefore never reaches a pair - is skipped
    // STRUCTURALLY rather than warned about.
    $racikan = new ResepItem;
    $racikan->resep_id = $resep->getKey();
    $racikan->obat_id = null;
    $racikan->nama_obat = 'Racikan Demam Herbal';
    $racikan->aturan_pakai = '1 x 1 sachet';
    $racikan->jumlah = 1;
    $racikan->satuan = 'sachet';
    $racikan->is_racikan = true;
    $racikan->racikan_nama = 'Racikan Demam Herbal';
    $racikan->harga_satuan = '0.00';
    $racikan->subtotal = '0.00';
    $racikan->save();

    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep/'.$resep->getKey().'/cek-interaksi')
        ->assertOk()
        ->assertJsonCount(1, 'data.warning');

    // A prescription with no catalogue item at all is an empty warning set, not
    // an error: the engine WARNS and never refuses.
    $hanyaRacikan = rx40Resep($akun['pasien'], $akun['dokter'], [], 'diproses');
    $racikan2 = new ResepItem;
    $racikan2->resep_id = $hanyaRacikan->getKey();
    $racikan2->obat_id = null;
    $racikan2->nama_obat = 'Racikan Sine';
    $racikan2->aturan_pakai = '1 x 1 sachet';
    $racikan2->jumlah = 1;
    $racikan2->satuan = 'sachet';
    $racikan2->is_racikan = true;
    $racikan2->racikan_nama = 'Racikan Sine';
    $racikan2->harga_satuan = '0.00';
    $racikan2->subtotal = '0.00';
    $racikan2->save();

    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep/'.$hanyaRacikan->getKey().'/cek-interaksi')
        ->assertOk()
        ->assertJsonPath('data.warning', []);
});

// =============================================================
// 7. Patient history
// =============================================================

test('the patient history lists only the caller own prescriptions, with a meta sibling', function (): void {
    $milik = rx40DoctorAccount();
    $asing = rx40DoctorAccount();
    $obat = rx40Obat('Amoxicillin');

    foreach (['aktif', 'diverifikasi', 'dibatalkan', 'selesai'] as $status) {
        rx40Resep($milik['pasien'], $milik['dokter'], [$obat], $status);
    }

    rx40Resep($asing['pasien'], $asing['dokter'], [$obat], 'aktif');

    $respons = $this->withHeaders(rx40As($milik['pasienUser']))
        ->getJson('/api/v1/pasien/resep')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(4, 'data.resep');

    // `meta` is a TOP-LEVEL SIBLING of `data`, never wrapped inside it.
    expect(array_keys($respons->json()))->toBe(['success', 'data', 'message', 'meta'])
        ->and($respons->json('meta'))->toHaveKeys(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])
        ->and($respons->json('meta.total'))->toBe(4)
        ->and($respons->json('meta.current_page'))->toBe(1)
        ->and($respons->json('data'))->not->toHaveKey('meta');

    // Every one of the eight states is publishable, and the flag rides along.
    $statuses = array_column($respons->json('data.resep'), 'status');
    sort($statuses);

    expect($statuses)->toBe(['aktif', 'dibatalkan', 'diverifikasi', 'selesai']);

    foreach ($respons->json('data.resep') as $baris) {
        expect($baris)->toHaveKey('is_kedaluwarsa')
            ->toHaveKey('terminal')
            ->toHaveKey('items')
            ->toHaveKey('qr_token');
        expect($baris['pasien_id'])->toBe($milik['pasien']);
    }
});

test('the history filter takes only the eight status members, and pages', function (): void {
    $akun = rx40DoctorAccount();
    $obat = rx40Obat('Amoxicillin');

    foreach (['aktif', 'aktif', 'diverifikasi', 'dibatalkan'] as $status) {
        rx40Resep($akun['pasien'], $akun['dokter'], [$obat], $status);
    }

    $this->withHeaders(rx40As($akun['pasienUser']))
        ->getJson('/api/v1/pasien/resep?status=aktif')
        ->assertOk()
        ->assertJsonCount(2, 'data.resep')
        ->assertJsonPath('meta.total', 2);

    $this->withHeaders(rx40As($akun['pasienUser']))
        ->getJson('/api/v1/pasien/resep?status=diverifikasi')
        ->assertOk()
        ->assertJsonCount(1, 'data.resep')
        ->assertJsonPath('data.resep.0.status', 'diverifikasi');

    // Every one of the eight is a legal filter, and anything else is a 422
    // naming the field - an unfiltered value would silently return the whole
    // list, which reads as "no filter applied" rather than "no such state".
    foreach (ResepStatus::nilai() as $status) {
        $this->withHeaders(rx40As($akun['pasienUser']))
            ->getJson('/api/v1/pasien/resep?status='.$status)
            ->assertOk();
    }

    $this->withHeaders(rx40As($akun['pasienUser']))
        ->getJson('/api/v1/pasien/resep?status=selesai')
        ->assertOk()
        ->assertJsonPath('data.resep', []);

    $this->withHeaders(rx40As($akun['pasienUser']))
        ->getJson('/api/v1/pasien/resep?status=menunggu')
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'The selected status is invalid.');

    $this->withHeaders(rx40As($akun['pasienUser']))
        ->getJson('/api/v1/pasien/resep?per_page=1&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data.resep')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.from', 2)
        ->assertJsonPath('meta.to', 2);

    // The 100 cap is the project-wide one and it is DECLARATIVE: an over-cap
    // `per_page` is a 422 a client can act on rather than a silently clamped
    // page, which is the convention `PasienProfileRequest` and the doctor
    // directory already follow. `PasienRecordAccess::perPage()` clamps again
    // inside the service, so a value arriving from anywhere else cannot
    // bypass the cap.
    $this->withHeaders(rx40As($akun['pasienUser']))
        ->getJson('/api/v1/pasien/resep?per_page=5000')
        ->assertStatus(422)
        ->assertJsonPath('errors.per_page.0', 'The per page field must not be greater than 100.');

    $this->withHeaders(rx40As($akun['pasienUser']))
        ->getJson('/api/v1/pasien/resep?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);

    // And `page` is bounded too, with a 422 rather than an empty page.
    $this->withHeaders(rx40As($akun['pasienUser']))
        ->getJson('/api/v1/pasien/resep?page=0')
        ->assertStatus(422)
        ->assertJsonPath('errors.page.0', 'The page field must be at least 1.');
});

test('a non-patient caller is 403 on the history, and an ungranted account is 403 too', function (): void {
    // `apoteker` holds `resep.lihat` and therefore reaches the service, which
    // refuses it with a 403 because it owns no `pasien` row. That is the
    // PasienRecordAccess split: 403 is about the CALLER.
    $this->withHeaders(rx40As(rx40Pengguna('apoteker', 'apoteker')))
        ->getJson('/api/v1/pasien/resep')
        ->assertStatus(403)
        ->assertJsonPath('success', false);

    // A prescribing doctor is refused for the same reason - there is no
    // doctor-side prescription list on this surface, and inventing one is
    // todo 41's decision rather than this todo's endpoint.
    $this->withHeaders(rx40As(rx40DoctorAccount()['user']))
        ->getJson('/api/v1/pasien/resep')
        ->assertStatus(403);

    // `admin` holds no `resep.lihat` at all, so the middleware answers first.
    $this->withHeaders(rx40As(rx40Pengguna('admin', 'admin')))
        ->getJson('/api/v1/pasien/resep')
        ->assertStatus(403);

    // A patient account with no `pasien` row is 403 as well, not an empty list.
    $tanpaProfil = User::query()->findOrFail(rx40User('Tanpa Profil', 'pasien'));
    $this->withHeaders(rx40As($tanpaProfil))
        ->getJson('/api/v1/pasien/resep')
        ->assertStatus(403);

    // An anonymous caller is 401, from the guard rather than from here. The
    // headers AND the resolved guard are cleared first: `withHeaders()`
    // accumulates on the test case for the rest of the test, and the Sanctum
    // guard caches the user it resolved for the previous request, so a stale
    // bearer produces a 403 that reads as a broken authorisation rule rather
    // than a fixture that forgot to log out.
    $this->flushHeaders();
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/pasien/resep')
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.', 'errors' => []]);
});

// =============================================================
// 8. Control
// =============================================================

test('CONTROL: a second verification only fails for a prescription that has one', function (): void {
    $akun = rx40DoctorAccount();
    $apoteker = rx40Pengguna('apoteker', 'apoteker');
    $obat = rx40Obat('Amoxicillin');

    // Two prescriptions in the same state, ONE verified. The refusal is about
    // the prescription that has a row, not about the endpoint being broken.
    $sudah = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'diproses');
    $segar = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'diproses');

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$sudah->getKey().'/verifikasi', ['status' => 'sesuai'])
        ->assertCreated();

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$sudah->getKey().'/verifikasi', ['status' => 'sesuai'])
        ->assertStatus(422);

    $this->withHeaders(rx40As($apoteker))
        ->postJson('/api/v1/resep/'.$segar->getKey().'/verifikasi', ['status' => 'sesuai'])
        ->assertCreated()
        ->assertJsonPath('data.verifikasi.status', 'sesuai')
        ->assertJsonPath('data.resep.status', 'diverifikasi');

    // The re-check really reads the database rather than a stale copy: a clean
    // prescription warns, and inserting the row makes the very next call warn.
    // A patient of their own, so the two prescriptions above contribute no
    // `riwayat_resep` warning to the counts this test pins.
    $sendiriPasien = rx40Pasien(rx40Pengguna('pasien', 'pasien')->getKey());
    $sendiri = rx40Resep($sendiriPasien, rx40Dokter(rx40Pengguna('dokter', 'dokter')->getKey()), [$obat], 'diproses');
    $path = '/api/v1/resep/'.$sendiri->getKey().'/cek-interaksi';

    $this->withHeaders(rx40As($apoteker))->getJson($path)->assertJsonPath('data.warning', []);

    $ibuprofen = rx40Obat('Ibuprofen', ['kelas_terapi' => 'Analgetik']);
    rx40Interaksi($obat, $ibuprofen, 'berat');

    // Only the new drug is in the prescription, so add it to the stored items -
    // the endpoint reads what is STORED, not what was sent.
    $tambahan = new ResepItem;
    $tambahan->resep_id = $sendiri->getKey();
    $tambahan->obat_id = $ibuprofen;
    $tambahan->nama_obat = 'Ibuprofen';
    $tambahan->aturan_pakai = '3 x 1 tablet';
    $tambahan->jumlah = 10;
    $tambahan->satuan = 'tablet';
    $tambahan->is_racikan = false;
    $tambahan->harga_satuan = '3000.00';
    $tambahan->subtotal = '30000.00';
    $tambahan->save();

    $this->withHeaders(rx40As($apoteker))->getJson($path)
        ->assertOk()
        ->assertJsonCount(1, 'data.warning')
        ->assertJsonPath('data.warning.0.tingkat', 'berat')
        ->assertJsonPath('data.warning.0.sumber', 'antar_item');
});
