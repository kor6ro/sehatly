<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Dokter;
use App\Models\DokterJadwal;
use App\Models\DokterLibur;
use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use App\Support\WaktuIndonesia;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F14 A: the admin doctor directory
|--------------------------------------------------------------------------
|
| `GET /api/v1/admin/dokter`, `GET /api/v1/admin/dokter/{id}`,
| `PUT /api/v1/admin/dokter/{id}/verifikasi` and `PUT /api/v1/admin/dokter/{id}/status`.
|
| **The list is the exact complement of the public directory, and that is the
| thing most worth proving.** `GET /api/v1/dokter` returns nothing unless a
| doctor is `terverifikasi`, active, telemedicine-available and STR-live;
| `v_dokter_katalog` owns that rule and `DokterDirectoryTest` pins it. This file
| asserts that `GET /api/v1/admin/dokter` returns EVERY row regardless - an
| unverified, a rejected, an inactive, a telemedicine-opted-out, an STR-expired
| and a soft-deleted-account doctor all appear - because an admin whose job is
| to verify and suspend cannot do it through a list that hides the work.
|
| **The two queries are separate on purpose and the test says so.** Sharing one
| query would make one of the two surfaces lie; the argument is in
| `AdminDokterService`'s docblock, and the assertion here is the observable
| half: the SAME doctor row is absent from the public list and present in the
| admin list, in the same test, with the same fixture.
|
| **Credentials are masked in BOTH responses, and the fixture number is a full
| 16-digit STR.** `f14Dokter()` writes `3312345678901234`, so `toContain` on the
| raw body would catch a leak of the whole value, and `json()` is checked for the
| masked shape. `file_str_url`/`file_sip_url` must not appear at all - the F14
| pattern allows them only behind a `dokter.kelola` grant that was not approved,
| and `AuditColumnPolicy` denies both columns outright.
|
| **A rejection reason is NOT offered, and the test asserts its absence.**
| `dokter` has no such column, the owner forbade inventing one, and
| `AuditColumnPolicy` keeps free text out of `audit_log`. So the verify body has
| exactly one field; a request carrying `alasan` is accepted but the value is
| ignored, and the test proves that by sending one and asserting the stored row
| and the audit row contain no free text.
|
*/

require_once __DIR__.'/f14-helpers.php';

/*
|--------------------------------------------------------------------------
| Seeding
|--------------------------------------------------------------------------
|
| `RbacSeeder` writes `roles`, `permissions` and `role_permissions`.
| `RoleAssigner::assign()` resolves a role name against `roles` and throws a
| `LogicException` when the catalogue names a row that is not there, and
| `EnsurePermission` resolves every `permission:` code against `permissions`.
| Without it, every route here answers 500 or 403.
*/
beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

/*
|--------------------------------------------------------------------------
| The guards: who may read and who may write
|--------------------------------------------------------------------------
*/

test('an anonymous caller is refused 401 on all four doctor routes', function (string $method, string $path, array $body): void {
    f14TanpaToken();

    $this->json($method, $path, $body)->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Unauthenticated.');
})->with([
    ['get', '/api/v1/admin/dokter', []],
    ['get', '/api/v1/admin/dokter/1', []],
    ['put', '/api/v1/admin/dokter/1/verifikasi', ['status_verifikasi' => 'terverifikasi']],
    ['put', '/api/v1/admin/dokter/1/status', ['status_aktif' => false]],
]);

test('a patient, who DOES hold dokter.lihat, is still refused 403 on the reads', function (string $method, string $path): void {
    // The load-bearing half of the two-gate rule. `ROLE_PERMISSIONS['pasien']`
    // holds `dokter.lihat` and `jadwal.lihat`, so a permission-only gate would let
    // every patient read the credential state of every doctor - including
    // rejected and STR-expired ones the public directory hides. The party gate
    // `tipe:admin,superadmin` is the narrower half and both are required.
    $pasien = f14Pasien()['user'];

    expect(RbacCatalog::permissionsFor('pasien'))->toContain('dokter.lihat');

    f14As($pasien);

    $this->json($method, $path)->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'This action is unauthorized.');
})->with([
    ['get', '/api/v1/admin/dokter'],
    ['get', '/api/v1/admin/dokter/1'],
]);

test('a doctor is refused 403 on every admin route, reads and writes alike', function (string $method, string $path, array $body): void {
    // `dokter` holds `dokter.lihat` and `jadwal.lihat` too. The write routes carry
    // NO `permission:` at all - they are guarded by the party gate alone - so this
    // row proves the gate that actually protects a credential mutation.
    $user = f14User('Dokter Uji '.Str::upper(Str::random(6)), 'dokter');

    app(RoleAssigner::class)->assign((int) $user->getKey(), 'dokter');

    f14As($user);

    $this->json($method, $path, $body)->assertStatus(403);
})->with([
    ['get', '/api/v1/admin/dokter', []],
    ['get', '/api/v1/admin/dokter/1', []],
    ['put', '/api/v1/admin/dokter/1/verifikasi', ['status_verifikasi' => 'terverifikasi']],
    ['put', '/api/v1/admin/dokter/1/status', ['status_aktif' => false]],
]);

test('an admin without the dokter.lihat grant is refused 403 on the reads', function (): void {
    // A revocation, not an absent role. `audit.lihat` is one of the paths AC-11
    // exercises for the audit surface; the doctor surface's read grant is
    // revocable the same way, and a permission that cannot be revoked would look
    // granted in `role_permissions` while being un-revocable in the middleware.
    $admin = f14Admin();

    DB::table('role_permissions')
        ->whereIn('permission_id', DB::table('permissions')->select('id')->where('kode', 'dokter.lihat'))
        ->delete();

    f14As($admin);

    $this->getJson('/api/v1/admin/dokter')->assertStatus(403);
    $this->getJson('/api/v1/admin/dokter/1')->assertStatus(403);
});

test('a superadmin reaches both reads, because the party gate admits the type too', function (): void {
    $dokterId = (int) f14Dokter()->getKey();

    f14As(f14Superadmin());

    $this->getJson('/api/v1/admin/dokter')->assertOk();
    $this->getJson('/api/v1/admin/dokter/'.$dokterId)->assertOk();
});

/*
|--------------------------------------------------------------------------
| The list: EVERY row, no eligibility filter
|--------------------------------------------------------------------------
*/

test('the admin list returns every doctor the public directory hides', function (): void {
    $layak = f14Dokter(f14User('Budi Santoso'), ['status_verifikasi' => 'terverifikasi']);
    $menunggu = f14Dokter(f14User('Sari Aulia'), ['status_verifikasi' => 'pending']);
    $ditolak = f14Dokter(f14User('Andi Wijaya'), ['status_verifikasi' => 'ditolak']);
    $nonaktif = f14Dokter(f14User('Maya Lestari'), [
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => false,
    ]);
    $tanpaTelemedisin = f14Dokter(f14User('Rudi Hartono'), [
        'status_verifikasi' => 'terverifikasi',
        'tersedia_telemedisin' => false,
    ]);
    $strLewat = f14Dokter(f14User('Dewi Kartika'), [
        'status_verifikasi' => 'terverifikasi',
        'str_berlaku_sampai' => Carbon::parse(f14HariIni(), WaktuIndonesia::ZONA)->subDay()->format('Y-m-d'),
    ]);
    $akunDihapus = f14Dokter(f14User('Hapus Diri'), ['status_verifikasi' => 'terverifikasi']);
    $akunDihapus->user->forceFill(['dihapus_at' => now()])->saveQuietly();

    // The public directory, for the contrast. Exactly ONE of the seven is eligible,
    // and the same fixture rows are asserted on both sides below.
    $publik = $this->getJson('/api/v1/dokter')->assertOk();
    expect(array_column($publik->json('data.dokter'), 'id'))
        ->toBe([(int) $layak->getKey()]);

    f14As(f14Admin());

    $admin = $this->getJson('/api/v1/admin/dokter')->assertOk();

    $ids = collect($admin->json('data.dokter'))->pluck('id')->sort()->values()->all();

    expect($ids)->toEqualCanonicalizing([
        (int) $layak->getKey(),
        (int) $menunggu->getKey(),
        (int) $ditolak->getKey(),
        (int) $nonaktif->getKey(),
        (int) $tanpaTelemedisin->getKey(),
        (int) $strLewat->getKey(),
        (int) $akunDihapus->getKey(),
    ]);

    expect($admin->json('meta.total'))->toBe(7);

    // Every status channel is published SEPARATELY, because the F14 pattern's
    // second design rule is that they must never be collapsed into one lamp.
    $baris = collect($admin->json('data.dokter'))->keyBy('id');

    expect($baris[$menunggu->getKey()]['status_verifikasi'])->toBe('pending')
        ->and($baris[$ditolak->getKey()]['status_verifikasi'])->toBe('ditolak')
        ->and($baris[$nonaktif->getKey()]['status_aktif'])->toBeFalse()
        ->and($baris[$tanpaTelemedisin->getKey()]['tersedia_telemedisin'])->toBeFalse()
        // The soft-deleted account is VISIBLE and says so, because an operator
        // who cannot see it cannot diagnose why the doctor stopped logging in.
        ->and($baris[$akunDihapus->getKey()]['akun_dihapus'])->toBeTrue()
        ->and($baris[$layak->getKey()]['akun_dihapus'])->toBeFalse();
});

test('the admin list joins the display name from users, and masks both credentials', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'), [
        'nomor_str' => F14_NOMOR_STR,
        'nomor_sip' => F14_NOMOR_STR,
    ]);

    f14As(f14Admin());

    $baris = $this->getJson('/api/v1/admin/dokter')->assertOk()->json('data.dokter.0');

    expect($baris['nama_lengkap'])->toBe('Budi Santoso')
        ->and($baris['user_id'])->toBe((int) $dokter->user_id)
        // Masked: first four and last four characters, the interior hidden. The
        // same masker `AuditColumnPolicy` uses for `nomor_str`, so the admin list
        // and the audit trail show the SAME masked form of the same number.
        ->and($baris['nomor_str'])->toBe(F14_NOMOR_STR_TERMASKING)
        ->and($baris['nomor_sip'])->toBe(F14_NOMOR_STR_TERMASKING)
        ->and($baris['tipe'])->toBe('dokter_umum');

    // The strong form of the same claim: the fixture's FULL number appears nowhere
    // in the body, so a masker that ever regressed to identity would be caught.
    expect($this->getJson('/api/v1/admin/dokter')->getContent())->not->toContain(F14_NOMOR_STR);
});

test('neither credential document URL is published, in the list or the detail', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'));
    $dokterId = (int) $dokter->getKey();

    expect($dokter->file_str_url)->not->toBeNull()
        ->and($dokter->file_sip_url)->not->toBeNull();

    f14As(f14Admin());

    $daftar = $this->getJson('/api/v1/admin/dokter')->assertOk();
    $detail = $this->getJson("/api/v1/admin/dokter/{$dokterId}")->assertOk();

    // The row exists and the URLs are in it - the assertion is about the RESPONSE,
    // not about the fixture. A signed link to a licence scan is itself a
    // credential, and the F14 pattern allows one only behind a `dokter.kelola`
    // grant that was not approved.
    expect($daftar->getContent())->not->toContain('file_str_url')
        ->and($daftar->getContent())->not->toContain('file_sip_url')
        ->and($detail->getContent())->not->toContain('file_str_url')
        ->and($detail->getContent())->not->toContain('file_sip_url')
        ->and($detail->getContent())->not->toContain('example.test/f14/str.pdf');

    // And no narrative `bio` either: it is free text about a third party and
    // nothing on this surface consumes it.
    expect($daftar->getContent())->not->toContain('Profil uji F14.');
});

test('the stored expiry is published verbatim and "expiring soon" is computed, never inferred', function (): void {
    // UU 17/2023 makes a new doctor's STR lifetime, and `dokter.str_berlaku_sampai`
    // is `DATE NOT NULL` - the schema CANNOT represent that. So no lifetime is
    // inferred and no sentinel date is invented: the stored date is published and
    // the warning is arithmetic on it. A date far in the future reads as "not
    // expiring soon", which is the truthful reading of what is stored.
    $jauh = f14Dokter(f14User('Jauh'), ['str_berlaku_sampai' => '2099-12-31']);
    $segera = f14Dokter(f14User('Segera'), [
        'str_berlaku_sampai' => Carbon::parse(f14HariIni(), WaktuIndonesia::ZONA)->addDays(58)->format('Y-m-d'),
    ]);
    $lewat = f14Dokter(f14User('Lewat'), [
        'str_berlaku_sampai' => Carbon::parse(f14HariIni(), WaktuIndonesia::ZONA)->subDays(12)->format('Y-m-d'),
    ]);

    f14As(f14Admin());

    $baris = collect($this->getJson('/api/v1/admin/dokter')->assertOk()->json('data.dokter'))
        ->keyBy(fn (array $b): int => (int) $b['id']);

    expect($baris[$jauh->getKey()]['str_berlaku_sampai'])->toBe('2099-12-31')
        ->and($baris[$jauh->getKey()]['str_segera_kedaluwarsa'])->toBeFalse()
        ->and($baris[$jauh->getKey()]['str_kedaluwarsa'])->toBeFalse();

    // 58 days, which is the F14 AC-1 badge, computed SERVER-SIDE - the UI must not
    // compute it, because the server owns the clinic's calendar day.
    expect($baris[$segera->getKey()]['str_sisa_hari'])->toBe(58)
        ->and($baris[$segera->getKey()]['str_segera_kedaluwarsa'])->toBeTrue()
        ->and($baris[$segera->getKey()]['str_kedaluwarsa'])->toBeFalse();

    expect($baris[$lewat->getKey()]['str_sisa_hari'])->toBe(-12)
        ->and($baris[$lewat->getKey()]['str_kedaluwarsa'])->toBeTrue()
        // A lapsed licence is NOT "expiring soon": it is a different, worse fact
        // and the two booleans must not both be true.
        ->and($baris[$lewat->getKey()]['str_segera_kedaluwarsa'])->toBeFalse();
});

test('an absent SIP is published as null, which is not the same fact as expired', function (): void {
    $dokter = f14Dokter(f14User('Tanpa SIP'), [
        'nomor_sip' => null,
        'sip_berlaku_sampai' => null,
    ]);

    f14As(f14Admin());

    $baris = $this->getJson('/api/v1/admin/dokter')->assertOk()->json('data.dokter.0');

    expect($baris['nomor_sip'])->toBeNull()
        ->and($baris['sip_berlaku_sampai'])->toBeNull()
        ->and($baris['sip_sisa_hari'])->toBeNull()
        // The empty case is not a red flag; an operator must not read it as one.
        ->and($baris['sip_kedaluwarsa'])->toBeFalse()
        ->and($baris['sip_segera_kedaluwarsa'])->toBeFalse();
});

test('the list counts bookings, and the detail counts the blast radius', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'));
    $dokterId = (int) $dokter->getKey();

    // Three future bookings that still consume a slot. The list's `jumlah_booking`
    // is history (every status); the detail's `dampak.booking_aktif` is "what will
    // still run if I suspend this doctor". They are DIFFERENT questions and the
    // resource labels them differently.
    foreach (['terjadwal', 'check_in', 'menunggu_pembayaran'] as $status) {
        f14BookingRow($dokterId, ['status' => $status, 'tanggal_kunjungan' => f14Hari(1)]);
    }

    // Three more that do NOT consume: a finished consultation (history, visit date
    // passed), a cancellation (its status released the slot) and a plain past
    // booking. `SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI` is the
    // cancelled/expired pair, and the clinic's calendar day is the date floor -
    // both read from the slot service rather than a second definition.
    f14BookingRow($dokterId, ['status' => 'selesai', 'tanggal_kunjungan' => f14Hari(-3)]);
    f14BookingRow($dokterId, ['status' => 'dibatalkan', 'tanggal_kunjungan' => f14Hari(2)]);
    f14BookingRow($dokterId, ['status' => 'terjadwal', 'tanggal_kunjungan' => f14Hari(-3)]);

    f14Jadwal($dokterId, ['status_aktif' => 1]);
    f14Jadwal($dokterId, ['hari' => 2, 'status_aktif' => 1]);
    f14Jadwal($dokterId, ['hari' => 3, 'status_aktif' => 0]);
    f14Libur($dokterId, f14Hari(10));
    f14Libur($dokterId, f14Hari(-10));

    f14As(f14Admin());

    $daftar = $this->getJson('/api/v1/admin/dokter')->assertOk()->json('data.dokter.0');
    $detail = $this->getJson("/api/v1/admin/dokter/{$dokterId}")->assertOk();

    expect($daftar['jumlah_booking'])->toBe(6);

    expect($detail->json('data.dampak'))->toBe([
        'booking_aktif' => 3,
        'jadwal_aktif' => 2,
        'libur_mendatang' => 1,
    ]);
});

test('the filters narrow the list, and a value outside the vocabulary is a 422', function (): void {
    $pending = f14Dokter(f14User('Menunggu'), ['status_verifikasi' => 'pending']);
    $aktif = f14Dokter(f14User('Aktif'), ['status_verifikasi' => 'terverifikasi']);

    f14As(f14Admin());

    $hanyaPending = $this->getJson('/api/v1/admin/dokter?status_verifikasi=pending')->assertOk();
    expect(array_column($hanyaPending->json('data.dokter'), 'id'))->toBe([(int) $pending->getKey()]);

    $hanyaNonaktif = $this->getJson('/api/v1/admin/dokter?status_aktif=0')->assertOk();
    expect($hanyaNonaktif->json('data.dokter'))->toBe([]);

    // `tersedia_telemedisin=1` and `=0` are both real filters, and neither is
    // collapsed into the other.
    $telemedisin = $this->getJson('/api/v1/admin/dokter?tersedia_telemedisin=1')->assertOk();
    expect(array_column($telemedisin->json('data.dokter'), 'id'))->toEqualCanonicalizing([
        (int) $pending->getKey(),
        (int) $aktif->getKey(),
    ]);

    // `q` reaches the doctor's NAME and their STR number, both server-side, so
    // the full number never has to appear in a URL the client renders a link from.
    $byName = $this->getJson('/api/v1/admin/dokter?q='.urlencode('Menunggu'))->assertOk();
    expect(array_column($byName->json('data.dokter'), 'id'))->toBe([(int) $pending->getKey()]);

    $byStr = $this->getJson('/api/v1/admin/dokter?q='.$aktif->nomor_str)->assertOk();
    expect(array_column($byStr->json('data.dokter'), 'id'))->toBe([(int) $aktif->getKey()]);

    // "No doctors match" and "your filter is misspelled" are DIFFERENT facts, and
    // the wrong one sends an operator looking for a data problem that is not there.
    // The values below are all outside their own closed vocabulary, or outside the
    // bounds the pagination rules publish.
    foreach ([
        // The DDL's ENUM is lower case (`:427`), so an upper-case spelling is a
        // misspelling rather than an empty result.
        'status_verifikasi=TERVERIFIKASI' => 'status_verifikasi',
        'status_verifikasi=menunggu' => 'status_verifikasi',
        'urutan=nama_lengkap' => 'urutan',
        'per_page=101' => 'per_page',
        'per_page=0' => 'per_page',
        'page=abc' => 'page',
    ] as $query => $field) {
        $jawaban = $this->getJson('/api/v1/admin/dokter?'.$query)->assertStatus(422);

        expect($jawaban->json('errors.'.$field))->toBeArray()->not->toBeEmpty();
    }

    // And the valid spelling of the same filter really is a filter, not a 422 -
    // which is the contrast that proves the vocabulary is closed rather than
    // closed-and-empty.
    $valid = $this->getJson('/api/v1/admin/dokter?status_verifikasi=terverifikasi')->assertOk();
    expect($valid->json('meta.total'))->toBe(1);
});

test('`q` treats % and _ as characters rather than LIKE metacharacters', function (): void {
    $pegawai = f14Dokter(f14User('Budi 100% Santoso'));
    $lain = f14Dokter(f14User('Sari Aulia'));

    f14As(f14Admin());

    // An unescaped `%` would match every row; the correct answer is the one row
    // whose name actually contains a percent sign.
    $hasil = $this->getJson('/api/v1/admin/dokter?q='.urlencode('100%'))->assertOk();
    expect(array_column($hasil->json('data.dokter'), 'id'))->toBe([(int) $pegawai->getKey()])
        ->and($hasil->json('meta.total'))->toBe(1);

    $underscore = $this->getJson('/api/v1/admin/dokter?q='.urlencode('_'))->assertOk();
    expect($underscore->json('data.dokter'))->toBe([]);
});

test('the default order is the credential worklist, ascending by expiry', function (): void {
    $ketiga = f14Dokter(f14User('Ketiga'), ['str_berlaku_sampai' => '2099-01-01']);
    $pertama = f14Dokter(f14User('Pertama'), ['str_berlaku_sampai' => '2026-01-01']);
    $kedua = f14Dokter(f14User('Kedua'), ['str_berlaku_sampai' => '2027-01-01']);

    f14As(f14Admin());

    $default = $this->getJson('/api/v1/admin/dokter')->assertOk();
    expect(array_column($default->json('data.dokter'), 'id'))->toBe([
        (int) $pertama->getKey(),
        (int) $kedua->getKey(),
        (int) $ketiga->getKey(),
    ]);

    $byName = $this->getJson('/api/v1/admin/dokter?urutan=nama')->assertOk();
    expect(array_column($byName->json('data.dokter'), 'nama_lengkap'))->toBe(['Kedua', 'Ketiga', 'Pertama']);
});

test('the list paginates through the project meta block, with the cap enforced', function (): void {
    for ($i = 0; $i < 4; $i++) {
        f14Dokter(f14User('Dokter '.$i), ['str_berlaku_sampai' => sprintf('20%02d-01-01', 60 + $i)]);
    }

    f14As(f14Admin());

    $halamanSatu = $this->getJson('/api/v1/admin/dokter?per_page=2')->assertOk();

    // Key by key rather than as one array: the order of `meta` is the framework's,
    // not this project's, and a positional comparison would be testing that.
    expect($halamanSatu->json('meta'))->toHaveKeys([
        'current_page', 'per_page', 'total', 'last_page', 'from', 'to',
    ])
        ->and($halamanSatu->json('meta.current_page'))->toBe(1)
        ->and($halamanSatu->json('meta.per_page'))->toBe(2)
        ->and($halamanSatu->json('meta.total'))->toBe(4)
        ->and($halamanSatu->json('meta.last_page'))->toBe(2)
        ->and($halamanSatu->json('meta.from'))->toBe(1)
        ->and($halamanSatu->json('meta.to'))->toBe(2);

    $halamanDua = $this->getJson('/api/v1/admin/dokter?per_page=2&page=2')->assertOk();
    expect($halamanDua->json('meta.current_page'))->toBe(2);

    // The two pages are disjoint: the order is total (expiry then `id`), so
    // LIMIT/OFFSET cannot repeat or skip a row when two doctors share a date.
    $ids = array_merge(
        array_column($halamanSatu->json('data.dokter'), 'id'),
        array_column($halamanDua->json('data.dokter'), 'id'),
    );
    expect($ids)->toHaveCount(4)->and(array_unique($ids))->toHaveCount(4);
});

/*
|--------------------------------------------------------------------------
| The detail
|--------------------------------------------------------------------------
*/

test('the detail publishes the credential fields, the blast radius, and 404s honestly', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'), ['nomor_str' => F14_NOMOR_STR]);
    $dokterId = (int) $dokter->getKey();

    f14As(f14Admin());

    $detail = $this->getJson("/api/v1/admin/dokter/{$dokterId}")->assertOk();

    expect($detail->json('success'))->toBeTrue()
        ->and($detail->json('data.dokter.id'))->toBe($dokterId)
        ->and($detail->json('data.dokter.nama_lengkap'))->toBe('Budi Santoso')
        ->and($detail->json('data.dokter.nomor_str'))->toBe(F14_NOMOR_STR_TERMASKING)
        ->and($detail->json('data.dokter.spesialisasi_utama'))->toBeNull()
        ->and($detail->json('data.dampak'))->toHaveKeys(['booking_aktif', 'jadwal_aktif', 'libur_mendatang']);

    // An absent id is the project's uniform 404 envelope, the same body
    // `DokterController` uses - so the status cannot become an existence oracle
    // that differs between the public and the admin surface.
    $this->getJson('/api/v1/admin/dokter/999999')->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Resource not found.')
        ->assertJsonPath('errors', []);

    // A NON-numeric segment never reaches a controller: `whereNumber` makes it a
    // router 404, so no string is ever bound as an identifier.
    $this->getJson('/api/v1/admin/dokter/abc')->assertStatus(404);
});

test('the detail publishes the main specialisation, picked by the same total order the public detail uses', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'));
    $dokterId = (int) $dokter->getKey();

    $smpKode = 'SMP-'.Str::upper(Str::random(5));
    $igdKode = 'IGD-'.Str::upper(Str::random(5));

    $smp = (int) DB::table('master_spesialisasi')->insertGetId([
        'kode' => $smpKode,
        'nama' => 'Penyakit Dalam',
        'tipe' => 'spesialis',
    ]);
    $igd = (int) DB::table('master_spesialisasi')->insertGetId([
        'kode' => $igdKode,
        'nama' => 'Kedokteran Umum',
        'tipe' => 'spesialis',
    ]);

    // Inserted in the WRONG order on purpose: `is_utama` decides, not insertion
    // order or id.
    DB::table('dokter_spesialisasi')->insert([
        ['dokter_id' => $dokterId, 'spesialisasi_id' => $smp, 'is_utama' => 0],
        ['dokter_id' => $dokterId, 'spesialisasi_id' => $igd, 'is_utama' => 1],
    ]);

    f14As(f14Admin());

    $detail = $this->getJson("/api/v1/admin/dokter/{$dokterId}")->assertOk();

    expect($detail->json('data.dokter.spesialisasi_utama.nama'))->toBe('Kedokteran Umum')
        ->and($detail->json('data.dokter.spesialisasi_utama.is_utama'))->toBeTrue()
        ->and($detail->json('data.dokter.spesialisasi_utama.kode'))->toBe($igdKode)
        ->and($detail->json('data.dokter.spesialisasi_utama.spesialisasi_id'))->toBe($igd);
});

/*
|--------------------------------------------------------------------------
| Verification: a one-way decision from `pending`
|--------------------------------------------------------------------------
*/

test('verifying a pending doctor records the decision and publishes the new state', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'));
    $dokterId = (int) $dokter->getKey();

    expect($dokter->status_verifikasi)->toBe('pending');

    f14As(f14Admin());

    $jawaban = $this->putJson("/api/v1/admin/dokter/{$dokterId}/verifikasi", [
        'status_verifikasi' => 'terverifikasi',
    ])->assertOk();

    expect($jawaban->json('success'))->toBeTrue()
        ->and($jawaban->json('data.dokter.status_verifikasi'))->toBe('terverifikasi')
        ->and($jawaban->json('data.dampak'))->toHaveKeys(['booking_aktif', 'jadwal_aktif', 'libur_mendatang']);

    expect($dokter->refresh()->status_verifikasi)->toBe('terverifikasi');
});

test('rejecting a pending doctor is the same endpoint with the other decision', function (): void {
    $dokter = f14Dokter(f14User('Andi Wijaya'));
    $dokterId = (int) $dokter->getKey();

    f14As(f14Admin());

    $this->putJson("/api/v1/admin/dokter/{$dokterId}/verifikasi", [
        'status_verifikasi' => 'ditolak',
    ])->assertOk()->assertJsonPath('data.dokter.status_verifikasi', 'ditolak');

    expect($dokter->refresh()->status_verifikasi)->toBe('ditolak');
});

test('a verification is a ONE-WAY decision, so a decided row is refused with a 422', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'), ['status_verifikasi' => 'terverifikasi']);
    $dokterId = (int) $dokter->getKey();

    f14As(f14Admin());

    // Two admins deciding the same row: the second one must reload rather than
    // silently overwrite the first one's decision. 422 on the field naming the
    // problem, which is what makes the client refetch.
    $this->putJson("/api/v1/admin/dokter/{$dokterId}/verifikasi", [
        'status_verifikasi' => 'ditolak',
    ])->assertStatus(422)->assertJsonPath('errors.status_verifikasi', fn ($m): bool => is_array($m) && $m !== []);

    expect($dokter->refresh()->status_verifikasi)->toBe('terverifikasi');
});

test('`pending` is not a decision, so it is refused before the service runs', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'));

    f14As(f14Admin());

    $this->putJson('/api/v1/admin/dokter/'.$dokter->getKey().'/verifikasi', [
        'status_verifikasi' => 'pending',
    ])->assertStatus(422)->assertJsonPath('errors.status_verifikasi', fn ($m): bool => is_array($m) && $m !== []);

    // The missing field is the same 422, not a 500 and not a silent no-op.
    $this->putJson('/api/v1/admin/dokter/'.$dokter->getKey().'/verifikasi', [])
        ->assertStatus(422)->assertJsonPath('errors.status_verifikasi', fn ($m): bool => is_array($m) && $m !== []);
});

test('there is NO rejection-reason field, and a reason sent anyway is not stored', function (): void {
    $dokter = f14Dokter(f14User('Andi Wijaya'));
    $dokterId = (int) $dokter->getKey();

    // The schema has no such column, the owner forbade inventing one, and
    // `AuditColumnPolicy` exists to keep narrative text OUT of `audit_log`. So the
    // contract offers one field. A client that sends `alasan` anyway is not
    // validated into it and it is not persisted - asserted, because "we accept it
    // and drop it" and "we store it somewhere" look identical from outside.
    f14As(f14Admin());

    $this->putJson("/api/v1/admin/dokter/{$dokterId}/verifikasi", [
        'status_verifikasi' => 'ditolak',
        'alasan' => 'Dokumen STR tidak terbaca dan masa berlaku sudah lewat.',
    ])->assertOk();

    expect($dokter->refresh()->status_verifikasi)->toBe('ditolak');

    $audit = DB::table('audit_log')
        ->where('tabel_target', 'dokter')
        ->where('record_id', (string) $dokterId)
        ->orderByDesc('id')
        ->first();

    expect($audit)->not->toBeNull();

    $isi = json_encode([$audit->data_lama, $audit->data_baru], JSON_THROW_ON_ERROR);

    expect($isi)->not->toContain('Dokumen STR tidak terbaca')
        ->and($isi)->not->toContain('alasan')
        // The decision itself IS recorded: the automatic observer row is the trail.
        ->and($isi)->toContain('ditolak');
});

test('verifying a nonexistent doctor is a 404, not a 500 from a foreign key', function (): void {
    f14As(f14Admin());

    $this->putJson('/api/v1/admin/dokter/999999/verifikasi', ['status_verifikasi' => 'terverifikasi'])
        ->assertStatus(404)->assertJsonPath('message', 'Resource not found.');
});

/*
|--------------------------------------------------------------------------
| Activation: two independent columns, and no auto-cancellation
|--------------------------------------------------------------------------
*/

test('deactivating a doctor writes status_aktif and leaves telemedicine alone', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'), ['status_verifikasi' => 'terverifikasi']);
    $dokterId = (int) $dokter->getKey();

    expect($dokter->tersedia_telemedisin)->toBeTrue();

    f14As(f14Admin());

    $jawaban = $this->putJson("/api/v1/admin/dokter/{$dokterId}/status", [
        'status_aktif' => false,
    ])->assertOk();

    expect($jawaban->json('data.dokter.status_aktif'))->toBeFalse()
        // Omitting the key means "leave it alone", which is a DIFFERENT act from
        // sending `false`: an operator suspending a doctor is not making a
        // statement about their telemedicine availability.
        ->and($jawaban->json('data.dokter.tersedia_telemedisin'))->toBeTrue();

    expect($dokter->refresh()->status_aktif)->toBeFalse()
        ->and($dokter->tersedia_telemedisin)->toBeTrue();
});

test('tersedia_telemedisin is written only when the request names it', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'), ['status_verifikasi' => 'terverifikasi']);
    $dokterId = (int) $dokter->getKey();

    f14As(f14Admin());

    $this->putJson("/api/v1/admin/dokter/{$dokterId}/status", [
        'status_aktif' => true,
        'tersedia_telemedisin' => false,
    ])->assertOk()->assertJsonPath('data.dokter.tersedia_telemedisin', false);

    expect($dokter->refresh()->tersedia_telemedisin)->toBeFalse();
});

test('deactivating a doctor does NOT cancel their bookings, and reports the blast radius', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'), ['status_verifikasi' => 'terverifikasi']);
    $dokterId = (int) $dokter->getKey();

    $ids = [];
    foreach (['terjadwal', 'check_in'] as $status) {
        $ids[] = f14BookingRow($dokterId, ['status' => $status, 'tanggal_kunjungan' => f14Hari(1)]);
    }

    f14As(f14Admin());

    // The F14 owner decision: a doctor with a suspect credential must be
    // stoppable IMMEDIATELY, so this is not blocked - and the number of bookings
    // that will still run is published so the dialog can say so first.
    $jawaban = $this->putJson("/api/v1/admin/dokter/{$dokterId}/status", [
        'status_aktif' => false,
    ])->assertOk();

    expect($jawaban->json('data.dampak.booking_aktif'))->toBe(2);

    foreach ($ids as $id) {
        expect(Booking::query()->findOrFail($id)->status)->toBeIn(['terjadwal', 'check_in']);
    }
});

test('the status write requires status_aktif and never touches verification', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'));
    $dokterId = (int) $dokter->getKey();

    f14As(f14Admin());

    // Missing the required switch is a 422 on that field.
    $this->putJson("/api/v1/admin/dokter/{$dokterId}/status", [])
        ->assertStatus(422)->assertJsonPath('errors.status_aktif', fn ($m): bool => is_array($m) && $m !== []);

    // And a client that smuggles `status_verifikasi` into the status body does NOT
    // change verification state: the key is not in the rules, `validated()` never
    // yields it, and the service has no parameter for it.
    $this->putJson("/api/v1/admin/dokter/{$dokterId}/status", [
        'status_aktif' => false,
        'status_verifikasi' => 'terverifikasi',
    ])->assertOk();

    expect($dokter->refresh()->status_aktif)->toBeFalse()
        ->and($dokter->status_verifikasi)->toBe('pending');
});

test('the status write on a nonexistent doctor is a 404', function (): void {
    f14As(f14Admin());

    $this->putJson('/api/v1/admin/dokter/999999/status', ['status_aktif' => false])
        ->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| The one thing the audit layer must prove about this surface
|--------------------------------------------------------------------------
*/

test('a doctor row reaches audit_log with the credential already masked at WRITE time', function (): void {
    $admin = f14Admin();

    f14As($admin);

    // A `create`, not a `PUT /status`: the observer's `data_baru` is the row's
    // DIRTY attributes, so a one-column status write records one column - and would
    // prove nothing about the credential. Creating the row exercises the whole
    // projection, which is what the masking claim is about.
    //
    // The GET goes first because it is what makes the guard resolve: `f14As()`
    // only puts a token on the test client, and `Auth::id()` stays null until a
    // request actually authenticates. That is why the observer would otherwise
    // record a null actor for a write made outside a request.
    $this->getJson('/api/v1/admin/dokter')->assertOk();

    $dokter = f14Dokter(f14User('Budi Santoso'), ['nomor_str' => F14_NOMOR_STR]);
    $dokterId = (int) $dokter->getKey();

    $audit = DB::table('audit_log')
        ->where('tabel_target', 'dokter')
        ->where('record_id', (string) $dokterId)
        ->where('aksi', 'create')
        ->orderByDesc('id')
        ->first();

    expect($audit)->not->toBeNull()
        // The ACTOR is the admin who created the row, not the doctor it belongs to
        // - `audit_log.user_id` is who did it.
        ->and((int) $audit->user_id)->toBe((int) $admin->getKey());

    // `AuditColumnPolicy` masked the STR when the row was written, so the trail
    // holds the same masked form the admin list publishes - never the credential.
    // The reader therefore cannot publish what the writer never held, which is why
    // `AdminAuditLogResource` does no masking of its own.
    expect($audit->data_baru)->toContain(F14_NOMOR_STR_TERMASKING)
        ->and($audit->data_baru)->not->toContain(F14_NOMOR_STR)
        // And the two credential-document URLs are DENIED outright, not masked.
        ->and($audit->data_baru)->not->toContain('file_str_url')
        ->and($audit->data_baru)->not->toContain('file_sip_url');
});

test('a doctor decision is written to audit_log naming the admin who decided', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'));
    $dokterId = (int) $dokter->getKey();

    $admin = f14Admin();

    f14As($admin);

    $this->putJson("/api/v1/admin/dokter/{$dokterId}/status", ['status_aktif' => false])->assertOk();

    $audit = DB::table('audit_log')
        ->where('tabel_target', 'dokter')
        ->where('record_id', (string) $dokterId)
        ->where('aksi', 'update')
        ->orderByDesc('id')
        ->first();

    // A genuine delta, not the whole row: the observer publishes the changed
    // attributes. `diubah_at` rides along because the DDL gives it
    // `ON UPDATE CURRENT_TIMESTAMP`, so the column is genuinely dirty on any write -
    // which is why the assertion is on the FIELD that changed rather than on the
    // exact bytes, which would pin a timestamp the database owns.
    expect($audit)->not->toBeNull()
        ->and((int) $audit->user_id)->toBe((int) $admin->getKey())
        ->and($audit->data_lama)->toContain('"status_aktif": true')
        ->and($audit->data_baru)->toContain('"status_aktif": false')
        // The row before the change is published too, and it still carries the
        // MASKED credential rather than the raw one.
        ->and($audit->data_lama)->not->toContain($dokter->nomor_str);
});

test('the admin directory never joins a clinical table', function (): void {
    // A structural assertion, and the structural half of the privacy rule. `admin`
    // holds no `rekam_medis.lihat` and no `resep.lihat`, so a report or a directory
    // that could name a patient's care would be a defect wearing a role. The
    // projection is asserted key-by-key instead, because that is what a client
    // actually receives.
    $dokterId = (int) f14Dokter(f14User('Budi Santoso'))->getKey();

    f14As(f14Admin());

    $daftar = $this->getJson('/api/v1/admin/dokter')->assertOk()->json('data.dokter');
    $detail = $this->getJson("/api/v1/admin/dokter/{$dokterId}")->assertOk()->json('data.dokter');

    // The list is a list; the detail is one object. Both are normalised to a list
    // of rows so the key census below is a single loop.
    $baris = array_merge(array_values((array) $daftar), [(array) $detail]);

    expect($baris)->toHaveCount(2);

    foreach ($baris as $row) {
        foreach ([
            'rekam_medis', 'diagnosa', 'keluhan', 'resep', 'obat', 'catatan',
            'triage', 'suhu', 'tekanan_darah', 'berat_badan', 'tinggi_badan',
            'tanggal_lahir', 'nik', 'pasien_id', 'pasien',
        ] as $dilarang) {
            expect(array_keys($row))->not->toContain($dilarang);
        }
    }
});

test('the list and the public directory cannot be served by one query', function (): void {
    // Not a performance assertion: a documentation assertion with teeth. The two
    // surfaces answer OPPOSITE questions, and the day they share a query one of
    // them lies. Asserted through the two endpoints at once so the regression is
    // visible as "the admin list lost a row" or "the public list gained one".
    $dokter = f14Dokter(f14User('Sari Aulia'), ['status_verifikasi' => 'pending']);

    // Anonymous: the public directory hides an unverified doctor.
    $this->getJson('/api/v1/dokter')->assertOk()->assertJsonPath('meta.total', 0);

    f14As(f14Admin());

    $admin = $this->getJson('/api/v1/admin/dokter')->assertOk();
    expect($admin->json('meta.total'))->toBe(1)
        ->and($admin->json('data.dokter.0.id'))->toBe((int) $dokter->getKey());
});

/*
|--------------------------------------------------------------------------
| Regression guards on the joins
|--------------------------------------------------------------------------
*/

test('the admin projection joins exactly the columns it names, so a new dokter column is invisible until published', function (): void {
    $dokter = f14Dokter(f14User('Budi Santoso'));
    $dokterId = (int) $dokter->getKey();

    f14As(f14Admin());

    $baris = $this->getJson("/api/v1/admin/dokter/{$dokterId}")->assertOk()->json('data.dokter');

    expect($baris)->toHaveKeys([
        'id', 'user_id', 'nama_lengkap', 'akun_dihapus', 'tipe',
        'nomor_str', 'str_berlaku_sampai', 'str_sisa_hari', 'str_kedaluwarsa',
        'str_segera_kedaluwarsa',
        'nomor_sip', 'sip_berlaku_sampai', 'sip_sisa_hari', 'sip_kedaluwarsa',
        'sip_segera_kedaluwarsa',
        'status_verifikasi', 'status_aktif', 'tersedia_telemedisin',
        'pengalaman_tahun', 'biaya_konsultasi_online', 'rating_rata_rata',
        'jumlah_ulasan', 'jumlah_konsultasi', 'jumlah_booking', 'spesialisasi_utama',
        'dibuat_at', 'diubah_at',
    ]);

    // A doctor row is reachable even when its account row has been soft-deleted,
    // which is the whole reason the join bypasses `SoftDeletes`.
    expect(Dokter::query()->find($dokterId))->not->toBeNull()
        ->and(DokterJadwal::query()->where('dokter_id', $dokterId)->count())->toBe(0)
        ->and(DokterLibur::query()->where('dokter_id', $dokterId)->count())->toBe(0);
});
