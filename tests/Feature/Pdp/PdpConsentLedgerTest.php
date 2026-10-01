<?php

declare(strict_types=1);

use App\Enums\PersetujuanPdpJenis;
use App\Services\Pdp\PdpConsent;
use App\Services\Pdp\PdpConsentService;
use App\Support\Pdp\PdpDokumen;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/pdp47-helpers.php';

/*
|--------------------------------------------------------------------------
| F02 - the consent ledger, the version authority, and ownership
|--------------------------------------------------------------------------
|
| The owner's decision of 2026-10-01 replaced the old version rule with two
| facts this file pins:
|
| 1. `GET /api/v1/pdp/dokumen` is the SERVER's version authority. It publishes
|    the active version and `berlaku_sejak` of each of the five documents, read
|    from `config/pdp.php` through `App\Support\Pdp\PdpDokumen`.
| 2. `persetujuan_pdp` is an APPEND-ONLY LEDGER. `uq_consent` was dropped, the
|    current status is the latest recorded row per `(user, jenis)` in append
|    (`id`) order, a withdrawal is allowed anytime on the SAME version, and the
|    same consecutive decision is idempotent.
|
| The six tests below are the acceptance criteria the owner named, in order:
| a non-active version is rejected; withdraw then re-approve on the same version
| both succeed; a patient cannot read or write another patient's consent; the
| same consecutive decision is idempotent; the checklist is exactly five slots
| in DDL order; and the document catalogue is the five active versions.
|
| The fixtures come from `pdp47-helpers.php` (`require_once`d, so this file is
| runnable on its own). The clock hooks are declared HERE rather than in the
| helper, because a hook declared in a `require_once`d file is registered for
| the FIRST requirer only - the trap todo 46 recorded as 35 errors.
*/

beforeEach(function (): void {
    pd47KunciJam();
    $this->seed(RbacSeeder::class);
});

afterEach(function (): void {
    pd47LepasJam();
});

// =====================================================================
// 1. The version authority
// =====================================================================

test('GET /pdp/dokumen publishes the five ACTIVE versions in DDL order', function (): void {
    $akun = pd47AkunPasien();
    $dokumen = app(PdpDokumen::class);

    $response = pd47As($akun['user'])->getJson('/api/v1/pdp/dokumen');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Daftar dokumen PDP berhasil dimuat.')
        ->assertJsonStructure(['success', 'data', 'message', 'meta' => [
            'current_page', 'last_page', 'per_page', 'total', 'from', 'to',
        ]])
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 5);

    $isi = $response->json('data.dokumen');

    // FIVE entries, in the DDL's own order, so a client can index the catalogue
    // by position against a list it read at build time.
    expect($isi)->toHaveCount(5)
        ->and(array_column($isi, 'jenis'))->toBe(PersetujuanPdpJenis::nilai());

    foreach ($isi as $satu) {
        // EXACTLY these three keys - a client that reads a key this response
        // does not publish is reading a hallucination.
        expect(array_keys($satu))->toBe(['jenis', 'versi_dokumen', 'berlaku_sejak'])
            // The published version IS the one the write path enforces, read
            // from the same support class rather than restated here.
            ->and($satu['versi_dokumen'])->toBe($dokumen->versiAktif($satu['jenis']))
            // Zero-padded `vNN`, the owner's convention.
            ->and($satu['versi_dokumen'])->toMatch(PdpDokumen::POLA_VERSI)
            ->and($satu['berlaku_sejak'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    }

    // The catalogue is bearer-only, like the two routes beside it. Both the
    // default headers and the memoised guard have to be undone, for the reason
    // `PdpNotificationTest` spells out.
    app('auth')->forgetGuards();
    test()->flushHeaders();

    test()->getJson('/api/v1/pdp/dokumen')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});

// =====================================================================
// 2. A non-active version is rejected
// =====================================================================

test('a versi_dokumen that is not the active version is rejected with 422 and writes nothing', function (): void {
    $akun = pd47AkunPasien();
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);
    $userId = (int) $akun['user']->getKey();

    $ditolak = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v99',
        'disetujui' => true,
    ]);

    $ditolak->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.')
        // TWO messages on ONE field, position-asserted: the first says what
        // happened, the second names the active version so a client that cannot
        // refetch still has the value it needs.
        ->assertJsonPath('errors.versi_dokumen', [
            'Versi dokumen yang dikirim bukan versi aktif.',
            'Versi aktif saat ini adalah '.$aktif.'. Muat ulang GET /api/v1/pdp/dokumen lalu kirim ulang.',
        ]);

    // The failure envelope has EXACTLY three keys - no `data`, no `meta`.
    expect(array_keys($ditolak->json()))->toBe(['success', 'message', 'errors'])
        ->and(array_keys($ditolak->json('errors')))->toBe(['versi_dokumen'])
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(0);

    // A LOWER version is refused the same way: the check is equality with the
    // active version, not an ordering, so there is no "stale but acceptable"
    // value.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => 'v00', 'disetujui' => true,
    ])->assertStatus(422);

    expect(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(0);

    // The active version is accepted, and only then is a row written - so the
    // refusals above were a real gate and not a blanket denial.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertCreated()
        ->assertJsonPath('data.persetujuan.versi_dokumen', $aktif);

    expect(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(1);
});

// =====================================================================
// 3. Withdrawal and re-approval on the SAME version
// =====================================================================

test('withdraw and re-approve on the SAME active version both succeed and append rows', function (): void {
    $akun = pd47AkunPasien();
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);
    $userId = (int) $akun['user']->getKey();

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertCreated()->assertJsonPath('data.persetujuan.efektif', true);

    // THE WITHDRAWAL IS THE SAME VERSION. Under the old `uq_consent` rule this
    // was a 422 collision; under the ledger it is a new row and the effective
    // answer flips on the same request.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => false,
    ])->assertCreated()->assertJsonPath('data.persetujuan.efektif', false);

    // And re-approving is the same version again.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertCreated()->assertJsonPath('data.persetujuan.efektif', true);

    $baris = DB::table('persetujuan_pdp')->where('user_id', $userId)->orderBy('id')->get();

    // THREE rows, all at the active version, in append order: approve, withdraw,
    // re-approve. The table is a HISTORY and nothing was edited.
    expect($baris)->toHaveCount(3)
        ->and($baris->pluck('versi_dokumen')->unique()->values()->all())->toBe([$aktif])
        ->and($baris->pluck('disetujui')->all())->toBe([1, 0, 1]);

    // The gate follows the latest row, and so does the checklist.
    expect(app(PdpConsent::class)->disetujui($akun['user'], $jenis))->toBeTrue();

    pd47As($akun['user'])->getJson('/api/v1/pdp/persetujuan')
        ->assertOk()
        // `berbagi_data_medis` is slot 3 of 5 in DDL order (0-based index 2).
        ->assertJsonPath('data.persetujuan.2.jenis', $jenis)
        ->assertJsonPath('data.persetujuan.2.efektif', true)
        ->assertJsonPath('data.persetujuan.2.versi_dokumen', $aktif);
});

// =====================================================================
// 4. Idempotency
// =====================================================================

test('the same consecutive decision is idempotent and writes no second row', function (): void {
    $akun = pd47AkunPasien();
    $jenis = 'syarat_ketentuan';
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);
    $userId = (int) $akun['user']->getKey();

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertCreated();

    // The client's request never got its response and it retries. Byte-identical
    // body, so the server CAN tell this from a changed decision - and must,
    // because refusing it would make every retry a hard failure while accepting
    // it as a new row would make the ledger grow on every retry.
    $sebelum = (array) DB::table('persetujuan_pdp')->where('user_id', $userId)->sole();

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertOk()
        ->assertJsonPath('data.persetujuan.efektif', true);

    // Every column is byte-identical, `id` and `disetujui_at` and `ip_address`
    // included: the idempotent path returns the recorded row, it does not
    // re-stamp it.
    expect((array) DB::table('persetujuan_pdp')->where('user_id', $userId)->sole())->toBe($sebelum)
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(1);

    // The same for a withdrawal: the second identical call writes nothing.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => false,
    ])->assertCreated();

    $sebelumTarik = (array) DB::table('persetujuan_pdp')->where('user_id', $userId)->orderByDesc('id')->first();

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => false,
    ])->assertOk();

    expect(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(2)
        ->and((array) DB::table('persetujuan_pdp')->where('user_id', $userId)->orderByDesc('id')->first())->toBe($sebelumTarik);
});

// =====================================================================
// 5. Ownership - another patient's consent is never readable or writable
// =====================================================================

test('a patient cannot read or write another patient consent', function (): void {
    $a = pd47AkunPasien('Pasien A PDP');
    $b = pd47AkunPasien('Pasien B PDP');
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);

    pd47As($a['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertCreated();

    // B's checklist is B's own: A's row is invisible, and every slot is the
    // three-state `null` rather than A's answer.
    $bacaB = pd47As($b['user'])->getJson('/api/v1/pdp/persetujuan');

    $bacaB->assertOk();

    foreach ($bacaB->json('data.persetujuan') as $slot) {
        expect($slot['efektif'])->toBeNull()
            ->and($slot['versi_dokumen'])->toBeNull()
            ->and($slot['disetujui_at'])->toBeNull()
            ->and($slot['ip_address'])->toBeNull();
    }

    // B's write is B's own row and does not touch A's.
    pd47As($b['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => false,
    ])->assertCreated();

    expect(DB::table('persetujuan_pdp')->where('user_id', $a['user']->getKey())->count())->toBe(1)
        ->and(DB::table('persetujuan_pdp')->where('user_id', $b['user']->getKey())->count())->toBe(1)
        ->and(app(PdpConsent::class)->effective($a['user'], $jenis))->toBeTrue()
        ->and(app(PdpConsent::class)->effective($b['user'], $jenis))->toBeFalse();

    // A cannot name B in the body: `user_id` is `prohibited`, so the attempt is
    // a 422 and writes nothing.
    pd47As($a['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => $aktif,
        'disetujui' => false,
        'user_id' => (int) $b['user']->getKey(),
    ])->assertStatus(422)
        ->assertJsonPath('errors.user_id.0', 'Persetujuan hanya dapat dicatat atas nama akun yang sedang masuk.');

    // A's row is untouched by B's decision and by the refused write.
    expect(app(PdpConsent::class)->effective($a['user'], $jenis))->toBeTrue()
        ->and(DB::table('persetujuan_pdp')->where('user_id', $a['user']->getKey())->count())->toBe(1)
        ->and(DB::table('persetujuan_pdp')->where('user_id', $a['user']->getKey())->value('disetujui'))->toBe(1);
});

// =====================================================================
// 6. The checklist is five slots, in DDL order
// =====================================================================

test('GET /pdp/persetujuan returns exactly five slots in DDL order with the top-level meta', function (): void {
    $akun = pd47AkunPasien();

    $response = pd47As($akun['user'])->getJson('/api/v1/pdp/persetujuan');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Daftar persetujuan PDP berhasil dimuat.')
        ->assertJsonStructure(['success', 'data', 'message', 'meta' => [
            'current_page', 'last_page', 'per_page', 'total', 'from', 'to',
        ]])
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 5);

    $isi = $response->json('data.persetujuan');

    expect($isi)->toHaveCount(5)
        ->and(array_column($isi, 'jenis'))->toBe(PersetujuanPdpJenis::nilai());

    foreach ($isi as $slot) {
        expect(array_keys($slot))->toBe(['jenis', 'efektif', 'versi_dokumen', 'disetujui_at', 'ip_address'])
            ->and($slot['efektif'])->toBeNull();
    }
});

// =====================================================================
// The ledger reads append order, not the version string
// =====================================================================

test('the effective answer is the LATEST recorded row, not the highest version string', function (): void {
    $akun = pd47AkunPasien();
    $jenis = 'kebijakan_privasi';
    $userId = (int) $akun['user']->getKey();

    // A HIGHER version recorded FIRST, a LOWER one recorded LAST. The old
    // "highest versi_dokumen wins" rule would read `v02` and report a refusal;
    // the ledger reads the last row and reports the approval.
    pd47ConsentRaw($userId, $jenis, 'v02', false);
    pd47ConsentRaw($userId, $jenis, 'v01', true);

    expect(app(PdpConsent::class)->effective($akun['user'], $jenis))->toBeTrue()
        ->and(app(PdpConsent::class)->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe('v01')
        ->and(app(PdpConsentService::class)->ringkasan($akun['user'])[1]['baris']?->versi_dokumen)->toBe('v01');
});
