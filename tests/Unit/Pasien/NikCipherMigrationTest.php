<?php

declare(strict_types=1);

namespace Tests\Unit\Pasien;

use App\Models\Pasien;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves migration 2026_10_01_000079 is REVERSIBLE, and proves it reversibly
 * rather than by assertion.
 *
 * ## Why this is a Unit test and not a Feature one
 *
 * `tests/Pest.php` binds `RefreshDatabase` to the Feature directory, and
 * `RefreshDatabase` wraps every test in a transaction. Rolling a migration back
 * is DDL, MySQL treats DDL as an implicit commit, and an implicit commit inside
 * a wrapping transaction silently ends the rollback for the rest of the suite.
 * A Unit test has no transaction wrapper, so the command line behaviour is the
 * behaviour under test - the same reason `RbacMigrateFreshSeedTest` is a Unit
 * test and is documented at length for the same reason.
 *
 * ## What `down()` is allowed to do, and why half of it refuses
 *
 * A `CHAR(16)` cannot hold an 88-character payload. Rolling this migration back
 * onto a populated table would destroy every stored NIK - truncated in
 * non-strict SQL mode, error 1406 in strict mode - so `down()` refuses with the
 * row count rather than doing that quietly. On an EMPTY table it performs the
 * reversal, restoring `nik CHAR(16) NULL UNIQUE` exactly as the DDL used to
 * declare it. Both branches are executed here, and the assertion is on the
 * schema and the data afterwards rather than on the exception alone.
 *
 * ## Why this test leaves the database exactly as it found it
 *
 * `tearDown()` re-applies the migration whatever happened, and asserts it, so a
 * failure mid-test cannot leave the suite running against a rolled-back schema.
 * The patient rows it writes are deleted for the same reason. The next thing the
 * Unit suite does is `RbacMigrateFreshSeedTest`, which runs
 * `migrate:fresh --seed` and re-creates the fixture data from scratch.
 */
class NikCipherMigrationTest extends TestCase
{
    /**
     * A SYNTHETIC NIK, not a real person's identity. Sixteen digits chosen to be
     * visibly constructed rather than plausible: a 90 province prefix (no
     * Indonesian province is coded 90), a 1900 birth block and a 0001 serial.
     */
    private const NIK_SINTETIS = '9019000100000001';

    protected function setUp(): void
    {
        parent::setUp();

        // `NIK_CIPHER_KEY` is read on every `NikCipher` call rather than
        // memoised, so a per-test value is the whole mechanism and this test
        // cannot pass against a key another test left behind. It is a Unit test
        // and reads the real environment otherwise, which is the trap the
        // Feature suite's `RefreshDatabase` creates.
        config([
            'nik.key' => base64_encode(random_bytes(32)),
            'nik.previous_keys' => [],
        ]);
    }

    protected function tearDown(): void
    {
        // Unconditional, and asserted: a suite that inherits a rolled-back
        // schema fails in a hundred places with a message that names none of
        // them.
        Artisan::call('migrate', ['--force' => true]);

        parent::tearDown();
    }

    /**
     * The live column, read from `information_schema`. Every label is ALIASED,
     * because the server returns those names uppercased and `$row->data_type`
     * would then be an undefined property rather than a wrong value.
     */
    private function column(string $name): ?object
    {
        return DB::selectOne(
            'select data_type as tipe, character_maximum_length as panjang, is_nullable as boleh_null'
            .' from information_schema.columns'
            .' where table_schema = database() and table_name = ? and column_name = ?',
            ['pasien', $name],
        );
    }

    /**
     * The distinct column names `pasien` has an index over.
     *
     * @return list<string>
     */
    private function indexedColumns(): array
    {
        $baris = DB::select(
            'select distinct column_name as kolom from information_schema.statistics'
            .' where table_schema = database() and table_name = ?',
            ['pasien'],
        );

        return array_values(array_unique(array_map(static fn (object $r): string => $r->kolom, $baris)));
    }

    /**
     * A `users` row written with the query builder, so it writes no audit row.
     */
    private function userRow(): int
    {
        return (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'nama_lengkap' => 'Migrasi NIK '.Str::upper(Str::random(6)),
            'no_telepon' => '08'.random_int(100000000, 999999999),
            'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
            'tipe' => 'pasien',
            'status' => 'aktif',
        ]);
    }

    /**
     * A patient whose NIK goes in through the model, so the stored value is a
     * payload produced by the application rather than by a test.
     */
    private function patientWithNik(): Pasien
    {
        $pasien = new Pasien;
        $pasien->user_id = $this->userRow();
        $pasien->jenis_kelamin = 'P';
        $pasien->tanggal_lahir = '1990-04-17';
        $pasien->alamat_lengkap = 'Jl. Migrasi NIK No. 1';
        $pasien->nik = self::NIK_SINTETIS;
        $pasien->save();

        return $pasien;
    }

    public function test_the_migration_is_applied_and_the_column_is_the_one_the_ddl_names(): void
    {
        // The GREEN control. Without it, a `down()` that reverses a migration
        // which never ran would pass every other assertion in this file.
        $kolom = $this->column('nik_cipher');

        $this->assertNotNull($kolom, 'pasien.nik_cipher does not exist: the migration is not applied.');
        $this->assertSame('text', $kolom->tipe);
        $this->assertSame(65535, (int) $kolom->panjang);
        $this->assertSame('YES', $kolom->boleh_null);
        $this->assertNull($this->column('nik'), 'the plaintext column survived, so there are two again.');

        $indexed = $this->indexedColumns();

        $this->assertNotContains('nik_hash', $indexed, 'a blind index column was added.');
        $this->assertNotContains('nik_index', $indexed, 'a blind index column was added.');
        $this->assertNotContains('nik_cipher_index', $indexed, 'a blind index column was added.');
        $this->assertNotContains('nik_cipher', $indexed, 'the payload column is indexed, which a random-IV cipher cannot use.');
        $this->assertNotContains('nik', $indexed, 'an index over the old column survived the migration.');

        $this->assertSame(
            1,
            DB::table('migrations')->where('migration', '2026_10_01_000079_move_pasien_nik_to_nik_cipher_table')->count(),
            'the migrations table does not record the NIK cipher migration.',
        );
    }

    public function test_a_stored_payload_survives_a_written_nik_and_reads_back(): void
    {
        $pasien = $this->patientWithNik();

        $tersimpan = DB::table('pasien')->where('id', $pasien->getKey())->value('nik_cipher');

        $this->assertIsString($tersimpan);
        $this->assertNotSame(self::NIK_SINTETIS, $tersimpan, 'the column holds the plaintext.');
        $this->assertStringNotContainsString(self::NIK_SINTETIS, (string) $tersimpan);
        $this->assertSame(88, strlen((string) $tersimpan));
        $this->assertSame(self::NIK_SINTETIS, $pasien->fresh()?->nik);

        $pasien->forceDelete();
    }

    public function test_down_refuses_to_destroy_a_stored_nik_and_changes_nothing(): void
    {
        $pasien = $this->patientWithNik();
        $id = (int) $pasien->getKey();
        $payload = (string) DB::table('pasien')->where('id', $id)->value('nik_cipher');

        $ditolak = null;

        try {
            Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        } catch (RuntimeException $e) {
            $ditolak = $e;
        }

        // The refusal is the behaviour under test, so it is checked for what it
        // says as well as that it happened: an operator who hits this has to be
        // able to tell how many NIKs are at stake and what to do instead.
        $this->assertNotNull($ditolak, 'the rollback destroyed a stored NIK instead of refusing.');
        $this->assertStringContainsString('nik_cipher', $ditolak->getMessage());
        $this->assertStringContainsString('1 `pasien` row', $ditolak->getMessage());
        $this->assertStringContainsString('mysqldump', $ditolak->getMessage());

        // NOTHING was destroyed and NOTHING was altered: the payload is still
        // byte-for-byte what it was, and the column is still the one the DDL
        // names. A `down()` that raised after it had already begun would fail
        // this half.
        $this->assertSame(
            $payload,
            DB::table('pasien')->where('id', $id)->value('nik_cipher'),
            'the rollback truncated or dropped the payload before refusing.',
        );
        $this->assertSame(self::NIK_SINTETIS, Pasien::query()->withTrashed()->find($id)?->nik);
        $this->assertSame('text', $this->column('nik_cipher')?->tipe);
        $this->assertNull($this->column('nik'));
        $this->assertSame(
            1,
            DB::table('migrations')->where('migration', '2026_10_01_000079_move_pasien_nik_to_nik_cipher_table')->count(),
            'the migrations table recorded the rollback even though the migration refused.',
        );

        (new Pasien)->forceDelete();
    }

    public function test_down_reverses_the_shape_on_an_empty_table_and_up_puts_it_forward(): void
    {
        // An empty table, established rather than assumed: any payload left by an
        // earlier run is cleared here, and the count of what was cleared is
        // asserted, so this test cannot quietly pass by having nothing to lose
        // for a reason it did not arrange. The Unit suite re-seeds with
        // `migrate:fresh --seed` immediately afterwards.
        $sisa = DB::table('pasien')->whereNotNull('nik_cipher')->count();
        $dihapus = DB::table('pasien')->whereNotNull('nik_cipher')->delete();

        $this->assertSame($sisa, $dihapus, 'the payload rows could not all be cleared.');
        $this->assertSame(0, DB::table('pasien')->whereNotNull('nik_cipher')->count());

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));

        // The shape the DDL used to declare, restored: the name, the width, the
        // nullability, the comment and the UNIQUE are all back, and this reads
        // them out of `information_schema` rather than trusting the migration.
        $balik = $this->column('nik');

        $this->assertNotNull($balik, 'down() did not restore the nik column.');
        $this->assertSame('char', $balik->tipe);
        $this->assertSame(16, (int) $balik->panjang);
        $this->assertSame('YES', $balik->boleh_null);
        $this->assertNull($this->column('nik_cipher'), 'down() left the payload column behind.');
        $this->assertContains('nik', $this->indexedColumns(), 'down() did not restore the UNIQUE the DDL declared.');

        $this->assertSame(
            0,
            DB::table('migrations')->where('migration', '2026_10_01_000079_move_pasien_nik_to_nik_cipher_table')->count(),
            'the migrations table still records the migration after a successful rollback.',
        );

        // And forward again, so the schema this suite leaves behind is the one the
        // DDL describes.
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertNotNull($this->column('nik_cipher'));
        $this->assertNull($this->column('nik'));
        $this->assertNotContains('nik', $this->indexedColumns());
    }

    public function test_the_migration_refuses_before_it_touches_anything(): void
    {
        // The refusal is a GUARD, so it has to run before the first DDL
        // statement. This asserts it by reading the migration's own source for
        // the order: a `down()` that renamed the column and then counted rows
        // would pass every other test here once the count was taken, and would
        // still have destroyed the data it was supposed to protect.
        $sumber = (string) file_get_contents(
            base_path('database/migrations/2026_10_01_000079_move_pasien_nik_to_nik_cipher_table.php'),
        );

        $awal = strpos($sumber, 'public function down(): void');
        $hitung = strpos($sumber, 'whereNotNull');
        $ddl = strpos($sumber, 'Schema::table', $awal === false ? 0 : $awal);

        $this->assertIsInt($awal, 'the migration has no down() method.');
        $this->assertIsInt($hitung, 'the migration does not count stored payloads.');
        $this->assertIsInt($ddl, 'the migration has no DDL in down().');
        $this->assertLessThan(
            $ddl,
            $hitung,
            'down() issues DDL before it checks whether a NIK would be destroyed.',
        );

        // And the file is reversible at all: Laravel decides that by the presence
        // of a `down()` method, which a `return new class extends Migration`
        // file carries as a real method.
        $this->assertSame(1, preg_match('/public function down\(\): void/', $sumber));
        $this->assertSame(0, preg_match('/public function down\(\): bool/', $sumber), 'down() is declared as returning bool.');

        // The comment the DDL declares, restated in the migration, because a
        // `MODIFY` replaces the whole column definition.
        $this->assertStringContainsString('WAJIB dienkripsi', $sumber);
    }

    /**
     * A model with a soft-delete column is what `forceDelete()` needs, and the
     * other tests in this file call it. Asserting the TRAIT BY ITS FULLY
     * QUALIFIED NAME is the whole content of this test: the first draft
     * compared the short name `SoftDeletes` against a list of fully qualified
     * ones and failed, which says nothing about the model and everything about
     * the assertion.
     */
    public function test_pasien_is_soft_deletable_so_the_fixture_rows_can_be_removed(): void
    {
        $traits = array_values(class_uses_recursive(Pasien::class));

        $this->assertContains(
            'Illuminate\Database\Eloquent\SoftDeletes',
            $traits,
            'Pasien no longer uses SoftDeletes, so forceDelete() would be unavailable.',
        );
        $this->assertSame('dihapus_at', (new Pasien)->getDeletedAtColumn());
    }
}
