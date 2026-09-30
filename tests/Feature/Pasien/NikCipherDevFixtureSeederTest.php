<?php

declare(strict_types=1);

use App\Models\Pasien;
use App\Support\NikCipher;
use Database\Seeders\DevFixtureSeeder;
use Database\Seeders\LabSeeder;
use Database\Seeders\MasterUmumSeeder;
use Database\Seeders\ObatSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SpesialisasiSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../DemoData/demo3c-helpers.php';

/*
|--------------------------------------------------------------------------
| The dev fixture seeder, on the encrypted NIK column
|--------------------------------------------------------------------------
|
| ## What this file exists for
|
| Migration `2026_10_01_000079_move_pasien_nik_to_nik_cipher_table` renamed
| `pasien.nik` to `pasien.nik_cipher` and widened it to `TEXT`.
| `DevFixtureSeeder` was still writing the old name through the query builder:
|
| ```text
| SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nik' in 'field list'
| ... SQL: insert into `pasien` (`user_id`, `nomor_rm`, `nik`, ...) values
|     (4, RM-202601-000001, 3171010101900001, ...)
| ```
|
| So `php artisan migrate:fresh --seed` - the README's setup command and the
| first job of the `contract.yml` CI workflow - died outright. The PHP suite did
| not catch it because `RefreshDatabase` runs `migrate:fresh` WITHOUT `--seed`
| and the NIK fixtures in the suite are written by the tests themselves, not by
| this seeder. A seeder is not exercised by a suite that does not run it, which
| is exactly why this file runs it.
|
| ## Two claims, and they are different claims
|
|  1. The seeder writes a NIK through {@see NikCipher}, so the fixture rows obey
|     the same storage contract as every other row. Writing the payload by hand
|     (`'nik_cipher' => NikCipher::encrypt(...)`) would also store ciphertext and
|     would bypass the model, which is the thing under test elsewhere; the seeder
|     writes through {@see \App\Models\Pasien} so the production write path is
|     what a fixture exercises.
|  2. Every 16-digit literal in the seeder is OBVIOUSLY synthetic. The two it
|     used to hold - `3171010101900001` and `3174010202950002` - are
|     well-formed, checksum-plausible Jakarta NIKs, so a reader of a failing
|     assertion could not tell a fixture from a person. The replacement values
|     use the same unassigned `90` province prefix the rest of the suite's NIK
|     fixtures use, so they read as constructed at a glance.
|
| ## `master_provinsi` is written by hand, not by `MasterWilayahSeeder`
|
| For the reason `demo3cProvinsi()` gives at length: `master_provinsi.id` is
| `TINYINT UNSIGNED`, and every Feature test that seeds the 38-row province list
| inside `RefreshDatabase`'s wrapper burns 38 permanent auto-increment values,
| because InnoDB does not roll that counter back with a transaction. Seeding it
| here would contribute to saturating the key. The single row the seeder resolves
| is written at the explicit id the DDL's own tuple order gives it.
|
| The full `php artisan db:seed` chain is exercised TWICE against a private
| scratch database as well, because `DatabaseSeeder`'s TRUNCATE cannot run inside
| `RefreshDatabase`'s wrapper transaction. That transcript is in
| `.omo/evidence/NIK-cipher-migration.md`.
*/

// ------------------------------------------------------------------ fixtures

/**
 * Seed everything {@see DevFixtureSeeder} resolves a master reference against,
 * plus the single province row it looks up by `kode = '31'`, in the order
 * `DatabaseSeeder` calls them.
 *
 * The list is exactly the set the seeder reads - proved by
 * `the dev fixture seeder resolves every master reference it asks for`, which
 * fails with the seeder's own "has no row with" message if one is missing.
 */
function nfsSeedPraseyarat(): void
{
    demo3cProvinsi();

    test()->seed([
        MasterUmumSeeder::class,
        SpesialisasiSeeder::class,
        ObatSeeder::class,
        LabSeeder::class,
        RbacSeeder::class,
    ]);
}

/**
 * Every 16-digit run in the seeder's own source, as a list of
 * `[value => occurrences]` pairs.
 *
 * Read as BYTES rather than as text, because a fixture literal is the one thing
 * in this file where a homoglyph or a full-width digit would make a test assert
 * against a string the seeder can never match.
 *
 * A LIST of pairs rather than a `value => count` MAP, and that is not a style
 * preference: PHP silently converts a purely numeric array key to an int, and
 * `9019000100000001` fits in a 64-bit int, so a map hands `substr()` an integer
 * and the test dies on a type error instead of on the claim it was making.
 *
 * @return list<array{0: string, 1: int}>
 */
function nfsLiteralNik(string $sumber): array
{
    preg_match_all('/(?<![0-9])[0-9]{16}(?![0-9])/', $sumber, $cocok);

    $jumlah = [];

    foreach ($cocok[0] as $nilai) {
        $jumlah[$nilai] = ($jumlah[$nilai] ?? 0) + 1;
    }

    $hasil = [];

    foreach ($jumlah as $nilai => $kali) {
        $hasil[] = [(string) $nilai, $kali];
    }

    return $hasil;
}

// --------------------------------------------------------------------- tests

test('the dev fixture seeder writes a NIK through the cipher, not into a column it dropped', function (): void {
    config([
        'nik.key' => base64_encode(random_bytes(32)),
        'nik.previous_keys' => [],
    ]);

    nfsSeedPraseyarat();

    // The seeder run is the assertion. Before the fix it raised
    // `QueryException: Unknown column 'nik' in 'field list'`, which is the whole
    // point: the bug is a crash in the documented setup command, not a wrong
    // value nobody would have noticed.
    $this->seed(DevFixtureSeeder::class);

    $pasien = DB::table('pasien')->orderBy('id')->get();

    expect($pasien)->toHaveCount(2)
        ->and(DB::getSchemaBuilder()->hasColumn('pasien', 'nik'))->toBeFalse(
            'pasien.nik exists again, so the seeder was fixed by re-adding the plaintext column'
        );

    foreach ($pasien as $baris) {
        $payload = $baris->nik_cipher;

        expect($payload)->toBeString('a fixture patient was seeded with no NIK at all, so the write path was not exercised')
            ->and((string) $payload)->not->toMatch('/^[0-9]{16}$/')
            ->and((string) $payload)->toHaveLength(88)
            ->and((string) base64_decode((string) $payload, true))->toStartWith(NikCipher::MAGIC)
            ->and(NikCipher::decrypt((string) $payload))->toMatch('/^[0-9]{16}$/')
            ->and(NikCipher::decrypt((string) $payload))->not->toBe((string) $payload);
    }

    // And the read path the API uses resolves the same digits off the same row,
    // so a fixture account is as usable as a registered one.
    $dibaca = Pasien::query()->orderBy('id')->get();

    foreach ($dibaca as $model) {
        expect($model->nik)->toMatch('/^[0-9]{16}$/');
    }
});

test('every 16-digit literal in the dev fixture seeder is obviously synthetic', function (): void {
    $sumber = (string) file_get_contents(database_path('seeders/DevFixtureSeeder.php'));
    $literal = nfsLiteralNik($sumber);

    // Two patients, two NIKs. A third would mean a literal crept in somewhere
    // the assertions below do not look.
    expect($literal)->toHaveCount(2, 'the seeder holds a different number of 16-digit literals than the two patients it creates');

    foreach ($literal as [$nilai, $jumlah]) {
        // The `90` province prefix is not an assigned Indonesian province code,
        // and the `1900` birth block plus a `0001` serial make the construction
        // visible. A fixture that has to be explained is a fixture somebody will
        // eventually "fix" into something plausible.
        expect(substr($nilai, 0, 2))->toBe('90', "the fixture NIK {$nilai} uses a real province prefix, so it reads as a real person")
            ->and($nilai)->toStartWith('90190001000000')
            ->and($jumlah)->toBe(1, "the fixture NIK {$nilai} is written out more than once, so it is a literal rather than a constant");

        // A well-formed NIK is digits only, so this also rules out a full-width
        // or otherwise look-alike digit in the seeder's source bytes.
        expect(preg_match('/^[0-9]{16}$/', $nilai))->toBe(1);
    }
});

test('the dev fixture seeder survives being run twice', function (): void {
    config([
        'nik.key' => base64_encode(random_bytes(32)),
        'nik.previous_keys' => [],
    ]);

    nfsSeedPraseyarat();

    $this->seed(DevFixtureSeeder::class);

    $pertama = [
        'faskes' => DB::table('faskes')->count(),
        'users' => DB::table('users')->count(),
        'pasien' => DB::table('pasien')->count(),
        'dokter' => DB::table('dokter')->count(),
        'dokter_spesialisasi' => DB::table('dokter_spesialisasi')->count(),
        'lab_paket_item' => DB::table('lab_paket_item')->count(),
        'obat_interaksi' => DB::table('obat_interaksi')->count(),
    ];

    // The second run is the assertion. `users.email`, `users.no_telepon`,
    // `faskes.kode_faskes` and the `dokter`/`dokter_spesialisasi` natural keys are
    // all UNIQUE in the DDL, so a plain second `insert` collides - and F2 already
    // found exactly this class of defect in `RbacSeeder`, which is why it is
    // `upsert` on the natural keys today. `db:seed` is run twice by hand and by
    // every developer who re-seeds; a seeder that only survives once is a
    // seeder that reports a red build for a reason that has nothing to do with
    // the change under test.
    $this->seed(DevFixtureSeeder::class);

    $kedua = [
        'faskes' => DB::table('faskes')->count(),
        'users' => DB::table('users')->count(),
        'pasien' => DB::table('pasien')->count(),
        'dokter' => DB::table('dokter')->count(),
        'dokter_spesialisasi' => DB::table('dokter_spesialisasi')->count(),
        'lab_paket_item' => DB::table('lab_paket_item')->count(),
        'obat_interaksi' => DB::table('obat_interaksi')->count(),
    ];

    expect($kedua)->toBe($pertama, 'running the seeder twice changed the row counts, so it is not idempotent');

    // The payload is re-encrypted on the second run, because the IV is random.
    // Asserted so "idempotent" cannot be satisfied by the seeder skipping the
    // write entirely and leaving a stale ciphertext behind.
    $semua = DB::table('pasien')->pluck('nik_cipher')->all();

    expect($semua)->toHaveCount(2);

    foreach ($semua as $payload) {
        expect((string) $payload)->toHaveLength(88)
            ->and((string) base64_decode((string) $payload, true))->toStartWith(NikCipher::MAGIC)
            ->and(NikCipher::decrypt((string) $payload))->toStartWith('90190001000000');
    }
});

test('the dev fixture seeder resolves every master reference it asks for', function (): void {
    // `DevFixtureSeeder::masterId()` throws with the table and the key it could
    // not find, so a missing prerequisite surfaces as a named message rather
    // than as a foreign-key error or a silently wrong master row. This test is
    // what proves `nfsSeedPraseyarat()` seeds a sufficient set, rather than the
    // seeder happening to pass on a database that already had the rows.
    nfsSeedPraseyarat();

    $this->seed(DevFixtureSeeder::class);

    expect(DB::table('pasien')->whereNotNull('nik_cipher')->count())->toBe(2)
        ->and(DB::table('dokter')->count())->toBeGreaterThan(0)
        ->and(DB::table('faskes')->count())->toBe(2);
});
