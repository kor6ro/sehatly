<?php

declare(strict_types=1);

use App\Enums\ResepStatus;
use App\Services\Resep\ResepStateMachine;
use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| F09 -- the pharmacist verification queue: `GET /api/v1/resep`
|--------------------------------------------------------------------------
|
| The gap this file closes is recorded in `web/ux/flows.md` F09: the
| `ApotekerQueuePage` had no list endpoint, so a pharmacist typed a
| prescription id by hand. `GET /api/v1/resep` is that list, and this file
| proves the four properties a queue has to have:
|
| 1. **The audience.** `auth:sanctum` + `tipe:apoteker` +
|    `permission:resep.verifikasi` -- the SAME pair the verify write carries.
|    A patient or a doctor already has `GET /pasien/resep` and
|    `GET /resep/{id}`; the queue is the one cross-patient read in the module
|    and must not be a second, wider door for them. `superadmin` holds
|    `resep.verifikasi` and is refused anyway by `tipe:apoteker`, exactly as the
|    verify write refuses it.
| 2. **The contents.** The queue holds the two states a prescription can still
|    be signed from -- `ResepStateMachine::BISA_DIVERIFIKASI`, read from the
|    state machine and asserted here to be a subset of the DDL's `resep.status`
|    enum. `?status=` accepts those two and 422s everything else, because
|    `[]` for `diverifikasi` would claim "nothing to verify" about a state the
|    queue can never hold.
| 3. **The disclosure surface.** `ResepAntreanResource` publishes row identity,
|    timing, the two computed flags and an item COUNT. No `catatan_dokter`, no
|    items/drug names, no `qr_token`, no surrogate foreign keys -- and
|    therefore no NIK, contact detail or medical history. This file asserts the
|    EXACT key set and byte-scans the body for the planted values.
| 4. **The order and the envelope.** `tanggal_resep DESC, id DESC`, with the
|    project `meta` block, exactly like `GET /pasien/resep`.
|
| The fixtures are `resep40-helpers.php`'s (`rx40*`): a doctor, their patient
| and a prescription written directly, because no endpoint in the suite moves a
| prescription into `diproses` except the verify write that is the queue's
| downstream.
|
| @see \App\Http\Controllers\Api\V1\ResepController::antrean()
| @see \App\Http\Resources\ResepAntreanResource
| @see \App\Services\Resep\ResepAccess::antrean()
*/

require_once __DIR__.'/resep40-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    Carbon::setTestNow(rx40Jam());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// =============================================================
// 1. The route and its guards
// =============================================================

test('the queue is a one-segment route registered before the id wildcard, with the write pair', function (): void {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(static fn ($route): string => $route->uri())
        ->values()
        ->all();

    $literal = array_search('api/v1/resep', $uris, true);
    $wildcard = array_search('api/v1/resep/{id}', $uris, true);

    // The literal must be registered FIRST. The two shapes cannot actually
    // collide (one segment versus two), but the registration order is part of
    // the contract this route was added under, and a later reorder that put
    // the wildcard first would still leave `GET /resep` reachable - so the
    // assertion is deliberately stricter than reachability.
    expect($literal)->toBeInt()
        ->and($wildcard)->toBeInt()
        ->and($literal)->toBeLessThan($wildcard);

    $route = collect(Route::getRoutes()->getRoutes())
        ->first(static fn ($r): bool => $r->uri() === 'api/v1/resep' && in_array('GET', $r->methods(), true));

    expect($route)->not->toBeNull()
        ->and($route->getName())->toBe('resep.antrean');

    $middleware = array_values(array_filter(
        $route->gatherMiddleware(),
        static fn ($m): bool => is_string($m),
    ));

    expect($middleware)->toContain('auth:sanctum', 'tipe:apoteker', 'permission:resep.verifikasi');

    // The pair is REAL catalogue vocabulary, not invented strings: an unknown
    // `permission:` is a 500 through `EnsurePermission`, not a 403.
    expect(RbacCatalog::isUserType('apoteker'))->toBeTrue()
        ->and(RbacCatalog::isPermission('resep.verifikasi'))->toBeTrue()
        ->and(RbacCatalog::permissionsFor('apoteker'))->toContain('resep.verifikasi')
        ->and(RbacCatalog::permissionsFor('superadmin'))->toContain('resep.verifikasi')
        // ...and the roles that are refused hold NEITHER the grant nor, for
        // the doctor, the accepted account type.
        ->and(RbacCatalog::permissionsFor('pasien'))->not->toContain('resep.verifikasi')
        ->and(RbacCatalog::permissionsFor('dokter'))->not->toContain('resep.verifikasi')
        ->and(RbacCatalog::permissionsFor('admin'))->not->toContain('resep.verifikasi');
});

test('an anonymous caller is 401 and every non-pharmacist account is 403', function (): void {
    $akun = rx40DoctorAccount();
    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [rx40Obat('Amoxicillin')], 'aktif');

    $this->getJson('/api/v1/resep')
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.', 'errors' => []]);

    // The patient and the prescribing doctor own this prescription and are
    // still refused: the queue lists EVERY patient's pending prescriptions and
    // their own reads already exist.
    $terlarang = [
        'pasien' => $akun['pasienUser'],
        'dokter' => $akun['user'],
        'admin' => rx40Pengguna('admin', 'admin'),
        // Holds `resep.verifikasi` and is refused by `tipe:apoteker` - the
        // same asymmetry the verify write applies to oversight accounts.
        'superadmin' => rx40Pengguna('superadmin', 'superadmin'),
        'perawat' => rx40Pengguna('perawat'),
        'kurir' => rx40Pengguna('kurir'),
    ];

    foreach ($terlarang as $nama => $user) {
        $respons = $this->withHeaders(rx40As($user))
            ->getJson('/api/v1/resep')
            ->assertStatus(403, "{$nama} must be refused the queue");

        // A refusal discloses nothing about any prescription: the row exists and
        // must not be named in the body.
        expect(str_contains((string) $respons->getContent(), (string) $resep->nomor_resep))->toBeFalse(
            "the 403 for {$nama} leaked the prescription number",
        );
    }
});

// =============================================================
// 2. The queue contents and the status filter
// =============================================================

test('the queue holds exactly the two verifiable statuses, newest first', function (): void {
    // The closed set is the state machine's, and it really is a subset of the
    // DDL's eight-member enum - re-parsed from `telemedicine_test.sql`, not
    // restated.
    expect(ResepStateMachine::BISA_DIVERIFIKASI)->toBe(['aktif', 'diproses'])
        ->and(array_diff(ResepStateMachine::BISA_DIVERIFIKASI, rx40Enum('resep', 'status')))->toBe([])
        ->and(array_diff(ResepStateMachine::BISA_DIVERIFIKASI, ResepStatus::nilai()))->toBe([]);

    $akun = rx40DoctorAccount();
    $obat = rx40Obat('Amoxicillin');

    // One prescription per ENUM member: the default queue is then visibly a
    // filter rather than a coincidence. Each is a minute older than the last,
    // so the order below is `tanggal_resep DESC` and not insertion order.
    $dibuat = [];

    foreach (ResepStatus::nilai() as $menit => $status) {
        $dibuat[$status] = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], $status, [
            'tanggal_resep' => rx40Jam()->copy()->subMinutes($menit),
        ]);
    }

    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    $respons = $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Antrean verifikasi resep berhasil dimuat.')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.current_page', 1);

    expect($respons->json('data.resep'))->toHaveCount(2)
        ->and(array_column($respons->json('data.resep'), 'status'))->toBe(['aktif', 'diproses'])
        ->and(array_column($respons->json('data.resep'), 'id'))->toBe([
            $dibuat['aktif']->getKey(),
            $dibuat['diproses']->getKey(),
        ])
        // A stored item exists for each row and the count is published without
        // any drug name.
        ->and($respons->json('data.resep.0.jumlah_item'))->toBe(1);
});

test('the status filter accepts the two verifiable states and 422s every other ENUM member', function (): void {
    $akun = rx40DoctorAccount();
    $obat = rx40Obat('Amoxicillin');
    $aktif = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'aktif');
    $diproses = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'diproses');
    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep?status=aktif')
        ->assertOk()
        ->assertJsonCount(1, 'data.resep')
        ->assertJsonPath('data.resep.0.id', $aktif->getKey())
        ->assertJsonPath('data.resep.0.status', 'aktif');

    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep?status=diproses')
        ->assertOk()
        ->assertJsonCount(1, 'data.resep')
        ->assertJsonPath('data.resep.0.id', $diproses->getKey());

    // Every other member of the ENUM is outside the queue's semantics, so the
    // answer is a named validation failure rather than an empty page that
    // would read as "nothing to verify".
    foreach (['diverifikasi', 'dipenuhi', 'dikirim', 'selesai', 'kedaluwarsa', 'dibatalkan'] as $tidak) {
        $this->withHeaders(rx40As($apoteker))
            ->getJson('/api/v1/resep?status='.$tidak)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.status.0', 'The selected status is invalid.');
    }

    // A value that is not a status at all fails the same way.
    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep?status=setuju')
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'The selected status is invalid.');
});

// =============================================================
// 3. Pagination, ordering and the meta block
// =============================================================

test('the queue paginates with the project meta block and caps per_page at 100', function (): void {
    $akun = rx40DoctorAccount();
    $obat = rx40Obat('Amoxicillin');

    $ids = [];

    foreach (range(0, 2) as $menit) {
        $ids[] = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'aktif', [
            'tanggal_resep' => rx40Jam()->copy()->subMinutes($menit),
        ])->getKey();
    }

    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    $halaman2 = $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep?per_page=2&page=2')
        ->assertOk();

    expect($halaman2->json('data.resep'))->toHaveCount(1)
        ->and($halaman2->json('data.resep.0.id'))->toBe($ids[2])
        ->and($halaman2->json('meta'))->toBe([
            'current_page' => 2,
            'last_page' => 2,
            'per_page' => 2,
            'total' => 3,
            'from' => 3,
            'to' => 3,
        ]);

    // `per_page` is capped like every other list: 100 is the ceiling and 101
    // is refused by name rather than silently clamped.
    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep?per_page=101')
        ->assertStatus(422)
        ->assertJsonPath('errors.per_page.0', 'The per page field must not be greater than 100.');

    $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('the order is newest-first with the id as the tie-breaker', function (): void {
    $akun = rx40DoctorAccount();
    $obat = rx40Obat('Amoxicillin');
    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    $lama = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'aktif', [
        'tanggal_resep' => rx40Jam()->copy()->subDay(),
    ]);
    $pertama = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'aktif', [
        'tanggal_resep' => rx40Jam(),
    ]);
    $kedua = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'diproses', [
        'tanggal_resep' => rx40Jam(),
    ]);
    $baru = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'aktif', [
        'tanggal_resep' => rx40Jam()->copy()->addHour(),
    ]);

    $respons = $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep')
        ->assertOk();

    // `pertama` and `kedua` share an instant, so only the `id DESC` tie-break
    // can order them - and it must order them stably, or a burst would repeat
    // or drop a row between two pages.
    expect(array_column($respons->json('data.resep'), 'id'))->toBe([
        $baru->getKey(),
        $kedua->getKey(),
        $pertama->getKey(),
        $lama->getKey(),
    ]);
});

// =============================================================
// 4. The disclosure surface
// =============================================================

test('the queue publishes no NIK, no contact detail and no medical history', function (): void {
    $akun = rx40DoctorAccount();

    // Sentinel values that exist only server-side. If any of them reaches the
    // response body, the queue published a field it must not.
    $rahasiaObat = 'RAHASIA-F09-NAMA-OBAT';
    $rahasiaCatatan = 'RAHASIA-F09-CATATAN-DOKTER';
    $rahasiaAlamat = 'Jl. RAHASIA-F09 No. 99, Jakarta';

    $obat = rx40Obat($rahasiaObat);

    $resep = rx40Resep($akun['pasien'], $akun['dokter'], [$obat], 'aktif', [
        'catatan_dokter' => $rahasiaCatatan,
    ]);

    DB::table('pasien')->where('id', $akun['pasien'])->update(['alamat_lengkap' => $rahasiaAlamat]);

    $telepon = (string) DB::table('users')->where('id', $akun['pasienUser']->getKey())->value('no_telepon');
    $namaPasien = (string) $akun['pasienUser']->nama_lengkap;

    $apoteker = rx40Pengguna('apoteker', 'apoteker');

    $respons = $this->withHeaders(rx40As($apoteker))
        ->getJson('/api/v1/resep')
        ->assertOk();

    // 1. The EXACT key set of a queue row. Anything added here is a conscious
    //    widening of the surface and fails this assertion by name.
    expect(array_keys($respons->json('data.resep.0')))->toBe([
        'id',
        'nomor_resep',
        'tipe',
        'status',
        'tanggal_resep',
        'berlaku_sampai',
        'is_kedaluwarsa',
        'terminal',
        'jumlah_item',
    ]);

    // 2. And the raw body, byte-scanned. The item's drug name, the doctor's
    //    note, the address, the phone, the patient's name and the QR token are
    //    all on the server and NONE of them may appear.
    $mentah = (string) $respons->getContent();

    $larangan = [
        $rahasiaObat,
        $rahasiaCatatan,
        $rahasiaAlamat,
        $telepon,
        $namaPasien,
        (string) $resep->qr_token,
        // Key-shaped prohibitions, so a renamed field cannot slip through.
        'nik',
        'no_telepon',
        'alamat_lengkap',
        'catatan_dokter',
        'qr_token',
        'items',
        'aturan_pakai',
    ];

    foreach ($larangan as $dilarang) {
        expect(str_contains($mentah, $dilarang))->toBeFalse(
            "the queue body leaked [{$dilarang}]",
        );
    }

    // 3. The queue is still an INDEX: the id it publishes is the id the detail
    //    endpoint takes, and the detail is a separate, separately gated read.
    expect($respons->json('data.resep.0.id'))->toBe($resep->getKey());
});
