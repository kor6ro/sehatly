<?php

declare(strict_types=1);

use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use App\Support\WaktuIndonesia;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| F14 D + E: the audit trail read, and the PDP ledger read
|--------------------------------------------------------------------------
|
| `GET /api/v1/admin/audit-log` and `GET /api/v1/admin/persetujuan-pdp` - the two
| surfaces whose permission codes (`audit.lihat`, `pdp.kelola`) had NO consumer
| before F14, and the reason the routes file reserved them.
|
| **The audit trail is append-only and stays that way.** `audit_log` has no
| `diubah_at` (:1129 is its only timestamp) because an audit row is evidence and
| rewriting one destroys the thing the table exists for. So there is exactly ONE
| route, a `GET`; the absence of POST/PUT/PATCH/DELETE is asserted against the live
| route table, because "we did not add one" is a claim that rots silently.
|
| **The values are the STORED ones, redacted at WRITE time.** Nothing in the reader
| masks anything, because `AuditObserver` -> `AuditLogWriter` -> `AuditColumnPolicy`
| already dropped denied columns and masked `nomor_str` when the row was created. A
| reader therefore cannot publish what the writer never held, and re-running the
 * policy on the way out would be a second, separately-driftable copy of a decision
| that was made once. The masking assertion below is a statement about the WRITER,
| proven through this reader.
|
| **The PDP ledger is READ-ONLY and that is a compliance decision, not an omission.**
| UU PDP asks the data subject, not their employer, so there is no admin route that
| WRITES a consent; `pdp.kelola`'s management verb describes the surface's role in
| the compliance picture, and its only route is a `GET`. Asserted, so a future
| "helpful" POST cannot be added without failing here.
|
| **`user_id` on both is a HISTORICAL identifier** - a bare column with no foreign
| key, specifically so the row survives the user's deletion - so neither surface
| joins a name onto it. A name would be a second copy of personal data in a
| compliance surface, and it would vanish at exactly the moment the trail matters.
|
*/

require_once __DIR__.'/f14-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

/**
 * Assert a 422 carries at least one message for `$field`, and return those messages.
 *
 * `errors` maps each field to an ARRAY of messages under the dotted attribute path
 * as submitted, so both `field` and `field.0` spellings occur in this codebase's
 * requests. See `AdminJadwalTest::f14Pesan422()` for the full argument.
 *
 * @return list<string>
 */
function f14Pesan(TestResponse $response, string $field): array
{
    $response->assertStatus(422);

    /** @var array<string, array<int, string>> $errors */
    $errors = (array) $response->json('errors');

    $kunci = array_key_exists($field, $errors) ? $field : null;

    expect($kunci)->not->toBeNull(
        'the 422 carries no message for '.$field.'; it carries: '.implode(', ', array_keys($errors))
    );

    return array_values(array_filter((array) $errors[$kunci], 'is_string'));
}

/**
 * Write one `audit_log` row directly, for the filter and pagination cases.
 *
 * Written through the query builder rather than the observer because the point is
 * to place rows with known `dibuat_at` values - the date-range filters need events
 * on both sides of a boundary, and the observer always stamps "now".
 *
 * @param  array<string, mixed>  $ubah
 */
function f14Audit(array $ubah = []): int
{
    return (int) DB::table('audit_log')->insertGetId(array_merge([
        'user_id' => null,
        'aksi' => 'update',
        'tabel_target' => 'dokter',
        'record_id' => '1',
        'data_lama' => null,
        'data_baru' => null,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Symfony',
        'endpoint' => '/api/v1/admin/dokter/1/status',
        'dibuat_at' => now()->format('Y-m-d H:i:s'),
    ], $ubah));
}

/**
 * A `persetujuan_pdp` row. `disetujui_at` is `DATETIME NOT NULL` and the table has
 * no timestamps of its own, so it is the only chronology the ledger has.
 *
 * @param  array<string, mixed>  $ubah
 */
function f14Pdp(int $userId, array $ubah = []): int
{
    return (int) DB::table('persetujuan_pdp')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis' => 'kebijakan_privasi',
        'versi_dokumen' => '1.0',
        'disetujui' => true,
        'disetujui_at' => now()->format('Y-m-d H:i:s'),
        'ip_address' => '10.0.0.1',
    ], $ubah));
}

/*
|--------------------------------------------------------------------------
| The audit trail: read-only, and only for admins
|--------------------------------------------------------------------------
*/

test('an anonymous caller is refused 401, and a patient is refused 403', function (string $path): void {
    f14TanpaToken();
    $this->getJson($path)->assertStatus(401);

    $pasien = f14Pasien()['user'];
    f14As($pasien);

    $this->getJson($path)->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');
})->with([
    '/api/v1/admin/audit-log',
    '/api/v1/admin/persetujuan-pdp',
]);

test('a doctor is refused 403 on the audit trail, because doctors hold no audit code', function (): void {
    $user = f14User('Dokter Uji', 'dokter');

    app(RoleAssigner::class)->assign((int) $user->getKey(), 'dokter');

    expect(RbacCatalog::permissionsFor('dokter'))->not->toContain('audit.lihat')
        ->and(RbacCatalog::permissionsFor('dokter'))->not->toContain('pdp.kelola')
        ->and(RbacCatalog::permissionsFor('admin'))->toContain('audit.lihat')
        ->and(RbacCatalog::permissionsFor('admin'))->toContain('pdp.kelola');

    f14As($user);

    $this->getJson('/api/v1/admin/audit-log')->assertStatus(403);
    $this->getJson('/api/v1/admin/persetujuan-pdp')->assertStatus(403);
});

test('an admin revoked of audit.lihat is refused 403 - the F14 AC-11 shape', function (): void {
    $admin = f14Admin();

    DB::table('role_permissions')
        ->whereIn('permission_id', DB::table('permissions')->select('id')->where('kode', 'audit.lihat'))
        ->delete();

    f14As($admin);

    $this->getJson('/api/v1/admin/audit-log')->assertStatus(403);
});

test('the audit trail publishes the stored row, newest first, with the project meta', function (): void {
    $admin = f14Admin();
    $aktor = (int) $admin->getKey();

    $lama = f14Audit([
        'user_id' => $aktor,
        'aksi' => 'update',
        'dibuat_at' => Carbon::parse(f14Hari(-2), WaktuIndonesia::ZONA)->setTime(9, 0)->utc()->format('Y-m-d H:i:s'),
    ]);
    $baru = f14Audit([
        'user_id' => $aktor,
        'aksi' => 'create',
        'tabel_target' => 'dokter_jadwal',
        'record_id' => '35',
        'dibuat_at' => Carbon::parse(f14Hari(0), WaktuIndonesia::ZONA)->setTime(14, 0)->utc()->format('Y-m-d H:i:s'),
    ]);

    // Every `User` fixture above wrote its own `create` row; those are the observer
    // working, and they are not what this test is about.
    f14BersihkanAuditPengguna();

    f14As($admin);

    $jawaban = $this->getJson('/api/v1/admin/audit-log')->assertOk();

    expect($jawaban->json('success'))->toBeTrue()
        // Newest first, with the id as the tiebreaker: `dibuat_at` has ONE SECOND of
        // resolution, so without it two events in the same second could make
        // LIMIT/OFFSET repeat or skip a row.
        ->and(array_column($jawaban->json('data.audit'), 'id'))->toBe([$baru, $lama])
        ->and($jawaban->json('meta'))->toHaveKeys(['current_page', 'per_page', 'total', 'last_page', 'from', 'to'])
        ->and($jawaban->json('meta.per_page'))->toBe(15);

    // Exactly the eleven stored columns, published as they are. `user_id` is the
    // HISTORICAL actor id and no name is joined onto it - see the file header.
    expect(array_keys($jawaban->json('data.audit.0')))->toBe([
        'id', 'user_id', 'aksi', 'tabel_target', 'record_id', 'data_lama', 'data_baru',
        'ip_address', 'user_agent', 'endpoint', 'dibuat_at',
    ])
        ->and($jawaban->json('data.audit.0.tabel_target'))->toBe('dokter_jadwal')
        ->and($jawaban->json('data.audit.0.record_id'))->toBe('35')
        ->and($jawaban->json('data.audit.0.aksi'))->toBe('create')
        ->and($jawaban->json('data.audit.0.user_id'))->toBe($aktor)
        // A `create`/`update` carries no prior state; publishing `null` is truthful
        // rather than an empty object a client would have to special-case.
        ->and($jawaban->json('data.audit.0.data_lama'))->toBeNull()
        ->and($jawaban->json('data.audit.0.dibuat_at'))->toBeString();
});

test('the audit trail filters on actor, table, record, action and date range', function (): void {
    $admin = f14Admin();
    $aktor = (int) $admin->getKey();
    $lain = (int) f14User('Aktor Lain', 'admin')->getKey();

    $kemarin = Carbon::parse(f14Hari(-1), WaktuIndonesia::ZONA)->setTime(10, 0)->utc()->format('Y-m-d H:i:s');
    $nanti = Carbon::parse(f14Hari(1), WaktuIndonesia::ZONA)->setTime(10, 0)->utc()->format('Y-m-d H:i:s');

    $jadwalLama = f14Audit(['user_id' => $aktor, 'tabel_target' => 'dokter_jadwal', 'record_id' => '35', 'dibuat_at' => $kemarin]);
    f14Audit(['user_id' => $aktor, 'tabel_target' => 'dokter', 'record_id' => '1', 'dibuat_at' => $kemarin]);
    $jadwalNanti = f14Audit(['user_id' => $lain, 'tabel_target' => 'dokter_jadwal', 'record_id' => '35', 'dibuat_at' => $nanti]);
    f14Audit(['user_id' => null, 'tabel_target' => null, 'record_id' => null, 'dibuat_at' => $nanti]);

    // The `User` rows above each wrote their own `create` event.
    f14BersihkanAuditPengguna();

    f14As($admin);

    $ids = static fn (array $rows): array => array_column($rows, 'id');

    // By table - the F14 AC-10 filter.
    expect($ids($this->getJson('/api/v1/admin/audit-log?tabel_target=dokter_jadwal')->assertOk()->json('data.audit')))
        ->toEqualCanonicalizing([$jadwalLama, $jadwalNanti]);

    // By actor.
    expect($ids($this->getJson("/api/v1/admin/audit-log?aktor_user_id={$aktor}")->assertOk()->json('data.audit')))
        ->toHaveCount(2);

    // By record, by action.
    expect($ids($this->getJson('/api/v1/admin/audit-log?record_id=1')->assertOk()->json('data.audit')))
        ->toHaveCount(1);
    expect($this->getJson('/api/v1/admin/audit-log?aksi=create')->assertOk()->json('meta.total'))->toBe(0);

    // And by range, where the boundary is the CLINIC's day: an event at 23:30 WIB is
    // inside that day and an event at 01:00 WIB on the next one is not.
    $dalam = $this->getJson('/api/v1/admin/audit-log?dari='.f14Hari(-1).'&sampai='.f14Hari(-1))->assertOk();
    expect($dalam->json('meta.total'))->toBe(2)
        ->and($ids($dalam->json('data.audit')))->toHaveCount(2);

    // Either bound may stand alone.
    expect($this->getJson('/api/v1/admin/audit-log?dari='.f14Hari(1))->assertOk()->json('meta.total'))->toBe(2)
        ->and($this->getJson('/api/v1/admin/audit-log?sampai='.f14Hari(-1))->assertOk()->json('meta.total'))->toBe(2);

    // Filters compose.
    expect($this->getJson('/api/v1/admin/audit-log?tabel_target=dokter_jadwal&aktor_user_id='.$aktor)
        ->assertOk()
        ->json('meta.total'))->toBe(1);

    // The 422s: the action is a closed eight-value vocabulary (the DDL's own ENUM),
    // and a reversed range is refused rather than answered as "nothing happened".
    f14Pesan($this->getJson('/api/v1/admin/audit-log?aksi=dihapus'), 'aksi');
    f14Pesan($this->getJson('/api/v1/admin/audit-log?dari=2026-10-31&sampai=2026-10-01'), 'sampai');
    f14Pesan($this->getJson('/api/v1/admin/audit-log?per_page=101'), 'per_page');
    f14Pesan($this->getJson('/api/v1/admin/audit-log?page=abc'), 'page');

    // An absent filter is NOT a filter: the whole trail is the default answer.
    expect($this->getJson('/api/v1/admin/audit-log')->assertOk()->json('meta.total'))->toBe(4);
});

test('the audit trail paginates, and two pages are disjoint', function (): void {
    $admin = f14Admin();

    for ($i = 1; $i <= 5; $i++) {
        f14Audit(['record_id' => (string) $i]);
    }

    f14BersihkanAuditPengguna();

    f14As($admin);

    $satu = $this->getJson('/api/v1/admin/audit-log?per_page=2')->assertOk();
    $dua = $this->getJson('/api/v1/admin/audit-log?per_page=2&page=2')->assertOk();
    $tiga = $this->getJson('/api/v1/admin/audit-log?per_page=2&page=3')->assertOk();

    expect($satu->json('meta.total'))->toBe(5)
        ->and($satu->json('meta.last_page'))->toBe(3)
        ->and($tiga->json('meta.from'))->toBe(5)
        ->and($tiga->json('meta.to'))->toBe(5);

    $ids = array_merge(
        array_column($satu->json('data.audit'), 'id'),
        array_column($dua->json('data.audit'), 'id'),
        array_column($tiga->json('data.audit'), 'id'),
    );

    expect($ids)->toHaveCount(5)
        ->and(array_unique($ids))->toHaveCount(5);
});

test('a credential in the trail is read back MASKED, because the writer masked it', function (): void {
    // This is the F14 AC-10 assertion, and it is a statement about the WRITER rather
    // than the reader. `AdminAuditLogResource` publishes `data_baru` verbatim; the
    // redaction happened in `AuditColumnPolicy` when the row was created, so the
    // reader never had the raw value to leak.
    $admin = f14Admin();

    f14As($admin);

    // One request, so the guard resolves and the observer records a real actor.
    $this->getJson('/api/v1/admin/audit-log')->assertOk();

    f14Dokter(f14User('Budi Santoso'), ['nomor_str' => F14_NOMOR_STR]);

    $audit = DB::table('audit_log')
        ->where('tabel_target', 'dokter')
        ->where('record_id', (string) DB::table('dokter')->max('id'))
        ->first();

    expect($audit)->not->toBeNull();

    $baris = collect($this->getJson('/api/v1/admin/audit-log')->assertOk()->json('data.audit'))
        ->firstWhere('id', (int) $audit->id);

    expect($baris)->not->toBeNull();

    // The full number is nowhere in the published row...
    expect(json_encode($baris, JSON_THROW_ON_ERROR))->not->toContain(F14_NOMOR_STR)
        // ...the masked form is, exactly as the admin directory publishes it...
        ->and($baris['data_baru'])->toContain(F14_NOMOR_STR_TERMASKING)
        // ...and the two credential-document URLs are DENIED outright rather than
        // masked, so the row cannot even carry a link to a scan.
        ->and($baris['data_baru'])->not->toContain('file_str_url')
        ->and($baris['data_baru'])->not->toContain('file_sip_url');
});

test('the audit trail has NO write route, and offers no export', function (): void {
    // "We did not add one" is a claim that rots silently. `audit_log` has no
    // `diubah_at`, so an update timestamp does not even exist; and the F14 owner
    // decision forbids an export, whose bulk egress would itself need an
    // `audit_log.aksi = 'export'` row the day it were ever added deliberately.
    $rute = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin/audit-log'))
        ->values()
        ->all();

    expect($rute)->toHaveCount(1)
        ->and($rute[0]->methods())->toBe(['GET', 'HEAD']);

    // And the same for the PDP ledger: an admin may READ a consent record, never
    // write one, because UU PDP asks the subject.
    $pdp = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin/persetujuan-pdp'))
        ->values()
        ->all();

    expect($pdp)->toHaveCount(1)
        ->and($pdp[0]->methods())->toBe(['GET', 'HEAD']);

    // Nothing anywhere under `/admin` writes an audit row or a consent.
    $tulisan = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin'))
        ->reject(fn ($route): bool => in_array('GET', $route->methods(), true))
        ->map(fn ($route): string => strtolower($route->uri().'|'.implode(',', $route->methods())))
        ->values()
        ->all();

    foreach ($tulisan as $satu) {
        expect($satu)->not->toContain('audit-log')
            ->and($satu)->not->toContain('persetujuan-pdp');
    }
});

/*
|--------------------------------------------------------------------------
| The PDP ledger: the read surface `pdp.kelola` was reserved for
|--------------------------------------------------------------------------
*/

test('the PDP ledger publishes the raw rows in append order, and withholds the personal data', function (): void {
    $userId = (int) f14User('Pasien Uji', 'pasien')->getKey();

    $pertama = f14Pdp($userId, ['jenis' => 'kebijakan_privasi', 'versi_dokumen' => '1.0', 'disetujui' => true]);
    // A withdrawal is a NEW row with `disetujui = false`, written by the subject -
    // which is why the table has no unique key and why this is a ledger.
    $kedua = f14Pdp($userId, [
        'jenis' => 'kebijakan_privasi',
        'versi_dokumen' => '1.1',
        'disetujui' => false,
    ]);

    f14As(f14Admin());

    $jawaban = $this->getJson('/api/v1/admin/persetujuan-pdp')->assertOk();

    // Append order, which is the ledger's own chronology: `disetujui_at` is a fact
    // about the act (an imported paper consent may predate the row) and so cannot
    // order the ledger.
    expect(array_column($jawaban->json('data.persetujuan_pdp'), 'id'))->toBe([$kedua, $pertama])
        ->and($jawaban->json('meta.total'))->toBe(2);

    // Six keys: `ip_address` is the subject's own personal data and no name is
    // joined on. An audit of the RECORD is not a reading of the person.
    expect(array_keys($jawaban->json('data.persetujuan_pdp.0')))->toBe([
        'id', 'user_id', 'jenis', 'versi_dokumen', 'disetujui', 'disetujui_at',
    ])
        ->and($jawaban->json('data.persetujuan_pdp.0.disetujui'))->toBeFalse()
        ->and($jawaban->json('data.persetujuan_pdp.0.versi_dokumen'))->toBe('1.1')
        ->and($jawaban->json('data.persetujuan_pdp.0.user_id'))->toBe($userId);

    $badan = $jawaban->getContent();
    expect($badan)->not->toContain('ip_address')
        ->and($badan)->not->toContain('10.0.0.1')
        ->and($badan)->not->toContain('nama_lengkap')
        ->and($badan)->not->toContain('no_telepon')
        ->and($badan)->not->toContain('@example.test');
});

test('the PDP ledger filters on subject, document kind and decision', function (): void {
    $satu = (int) f14User('Pasien Satu', 'pasien')->getKey();
    $dua = (int) f14User('Pasien Dua', 'pasien')->getKey();

    // `persetujuan_pdp.jenis` is the DDL's five-value ENUM (:1137-1138):
    // `syarat_ketentuan`, `kebijakan_privasi`, `berbagi_data_medis`, `pemasaran`
    // and `komunikasi_tindak_lanjut`. A value outside it is refused above, which is
    // asserted here - MySQL would otherwise truncate it to the empty string and
    // answer 200 with a lie.
    f14Pdp($satu, ['jenis' => 'kebijakan_privasi', 'disetujui' => true]);
    $retensi = f14Pdp($satu, ['jenis' => 'komunikasi_tindak_lanjut', 'disetujui' => false]);
    f14Pdp($dua, ['jenis' => 'kebijakan_privasi', 'disetujui' => true]);

    f14BersihkanAuditPengguna();

    f14As(f14Admin());

    $ids = static fn (array $rows): array => array_column($rows, 'id');

    expect($ids($this->getJson("/api/v1/admin/persetujuan-pdp?user_id={$satu}")->assertOk()->json('data.persetujuan_pdp')))
        ->toHaveCount(2)
        ->and($ids($this->getJson('/api/v1/admin/persetujuan-pdp?jenis=komunikasi_tindak_lanjut')->assertOk()->json('data.persetujuan_pdp')))
        ->toBe([$retensi])
        ->and($this->getJson('/api/v1/admin/persetujuan-pdp?disetujui=0')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/admin/persetujuan-pdp?disetujui=1')->assertOk()->json('meta.total'))->toBe(2)
        // Filters compose.
        ->and($this->getJson("/api/v1/admin/persetujuan-pdp?user_id={$satu}&disetujui=1")
            ->assertOk()
            ->json('meta.total'))->toBe(1);

    // `jenis` is the closed five-value vocabulary and `per_page` obeys the cap.
    f14Pesan($this->getJson('/api/v1/admin/persetujuan-pdp?jenis=keamanan'), 'jenis');
    f14Pesan($this->getJson('/api/v1/admin/persetujuan-pdp?jenis=retensi_data'), 'jenis');
    f14Pesan($this->getJson('/api/v1/admin/persetujuan-pdp?per_page=101'), 'per_page');
});

test('the PDP ledger paginates, and an empty ledger is an empty page', function (): void {
    f14As(f14Admin());

    $kosong = $this->getJson('/api/v1/admin/persetujuan-pdp')->assertOk();
    expect($kosong->json('data.persetujuan_pdp'))->toBe([])
        ->and($kosong->json('meta.total'))->toBe(0);

    for ($i = 1; $i <= 3; $i++) {
        f14Pdp((int) f14User('Pasien '.$i, 'pasien')->getKey());
    }

    f14BersihkanAuditPengguna();

    $satu = $this->getJson('/api/v1/admin/persetujuan-pdp?per_page=2')->assertOk();
    $dua = $this->getJson('/api/v1/admin/persetujuan-pdp?per_page=2&page=2')->assertOk();

    $ids = array_merge(
        array_column($satu->json('data.persetujuan_pdp'), 'id'),
        array_column($dua->json('data.persetujuan_pdp'), 'id'),
    );

    expect($ids)->toHaveCount(3)->and(array_unique($ids))->toHaveCount(3);
});
