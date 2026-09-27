<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Rbac\RbacCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the RBAC kernel survives `php artisan migrate:fresh --seed`, which is the
 * whole reason todo 4 was deferred until after todo 18.
 *
 * ## Why this is a Unit test and not a Feature one
 *
 * `tests/Pest.php` applies `RefreshDatabase` to the Feature directory, and
 * `RefreshDatabase` runs `migrate:fresh` **without** `--seed` before wrapping each
 * test in a transaction. A Feature test therefore cannot observe a seeded database at
 * all - it would wipe the seed on entry. This test needs the opposite: it *is* the
 * `migrate:fresh --seed` run.
 *
 * It also has to avoid `TRUNCATE` inside a wrapping transaction, because MySQL
 * treats `TRUNCATE` as DDL and implicitly commits, which would break the enclosing
 * rollback. A Unit test has no transaction wrapper, so the seeder tree's
 * `FOREIGN_KEY_CHECKS`-disabled reset behaves exactly as it does on the command
 * line.
 *
 * The cost is that this test owns `telemedisin_db_test` for its duration. It runs
 * first because `phpunit.xml` lists the Unit suite before Feature, and the Feature
 * suite's own `RefreshDatabase` re-migrates afterwards, so no later test can observe
 * this one's leftovers. That ordering is a property of `phpunit.xml` rather than of
 * this file, which is worth knowing before anyone reorders the two suites.
 *
 * ## What it asserts, and why the todo-18 rows are in here too
 *
 * The obvious assertion is "the RBAC rows exist". The load-bearing one is
 * {@see test_the_todo_18_section_16_rows_survive_the_rbac_inserts}, because adding
 * two seeders to a chain is exactly the change that silently drops somebody else's
 * rows: a truncate list that forgot a table, an ordering mistake, a foreign key
 * error part-way through. If todo 4 had broken todo 18, the RBAC assertions here
 * would still pass.
 */
class RbacMigrateFreshSeedTest extends TestCase
{
    /**
     * Todo 18's per-table row counts, section `[16]`, **measured** by reading
     * `COUNT(*)` back after a real `migrate:fresh --seed` rather than written from a
     * docblock. They total 151, which is the figure todo 18 reports, so the set is
     * self-checking: if any one of these is wrong the total no longer reconciles.
     */
    private const SECTION_16_TABLES = [
        'artikel_kategori' => 6,
        'master_agama' => 7,
        'master_golongan_darah' => 4,
        'master_hubungan_keluarga' => 7,
        'master_icd10' => 15,
        'master_icd9cm' => 6,
        'master_lab_paket' => 3,
        'master_lab_tindakan' => 10,
        'master_metode_pembayaran' => 14,
        'master_obat' => 7,
        'master_pendidikan' => 8,
        'master_penjamin' => 6,
        'master_provinsi' => 38,
        'master_spesialisasi' => 16,
        'master_status_pernikahan' => 4,
    ];

    /**
     * Run the real command. Recorded as a string so a failure message names the
     * command a developer can paste.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $exit = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

        if ($exit !== 0) {
            throw new RuntimeException(
                'php artisan migrate:fresh --seed exited '.$exit.":\n".Artisan::output()
            );
        }
    }

    public function test_migrate_fresh_seed_exits_zero(): void
    {
        // The assertion lives in setUp() so every test in this file is downstream of
        // it. This test exists so the file reports the fact on its own.
        $this->assertTrue(true, 'migrate:fresh --seed ran in setUp() without a non-zero exit.');
    }

    public function test_the_rbac_rows_exist_after_migrate_fresh_seed(): void
    {
        $roles = DB::table('roles')->orderBy('id')->pluck('nama')->all();
        $permissions = DB::table('permissions')->orderBy('id')->pluck('kode')->all();

        $this->assertSame(RbacCatalog::ROLES, $roles, 'roles does not hold the catalogue roles.');
        $this->assertSame(
            RbacCatalog::permissionCodes(),
            $permissions,
            'permissions does not hold the catalogue codes.'
        );

        $this->assertGreaterThanOrEqual(
            22,
            count($permissions),
            'The plan sets >= 22 permissions as the floor for this todo.'
        );
    }

    public function test_the_role_permission_grants_exist_after_migrate_fresh_seed(): void
    {
        $actual = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->orderBy('roles.nama')
            ->orderBy('permissions.kode')
            ->get(['roles.nama', 'permissions.kode'])
            ->map(static fn ($row): string => $row->nama.'|'.$row->kode)
            ->all();

        $expected = [];

        foreach (RbacCatalog::ROLE_PERMISSIONS as $role => $permissions) {
            foreach ($permissions as $kode) {
                $expected[] = $role.'|'.$kode;
            }
        }

        sort($expected);
        $sorted = $actual;
        sort($sorted);

        $this->assertSame($expected, $sorted);
        $this->assertCount(69, $actual);
    }

    public function test_no_user_roles_row_is_seeded(): void
    {
        $this->assertSame(
            0,
            DB::table('user_roles')->count(),
            'The seeder tree must not assign roles to accounts; that is RoleAssigner\'s job at runtime.'
        );
    }

    public function test_the_todo_18_section_16_rows_survive_the_rbac_inserts(): void
    {
        foreach (self::SECTION_16_TABLES as $table => $expected) {
            $this->assertSame(
                $expected,
                DB::table($table)->count(),
                "{$table} holds the wrong number of rows after migrate:fresh --seed, so adding RbacSeeder to the "
                .'chain disturbed todo 18.'
            );
        }

        $this->assertSame(
            151,
            array_sum(self::SECTION_16_TABLES),
            'The per-table counts in this file no longer total todo 18\'s 151.'
        );
    }

    public function test_the_todo_18_development_fixtures_still_land(): void
    {
        // The 7 tables DevFixtureSeeder writes, at the counts its own code produces.
        //
        // **`lab_paket_item` is 7, not 3.** Its `$map` links three kode to
        // `Medical Check Up Dasar`, two to `Cek Gula & Kolesterol` and two to
        // `Fungsi Hati Lengkap`; the `lab_paket_item` docblock in
        // `database/seeders/LabSeeder.php` and the row-count table in
        // `database/seeders/DatabaseSeeder.php` both said 3 before todo 4 corrected
        // the latter, so the number is stated here from the measured insert and not
        // inherited from either prose comment. See
        // `.omo/evidence/task-4-sehatly.md`, finding 2.
        $this->assertSame(5, DB::table('users')->count());
        $this->assertSame(2, DB::table('pasien')->count());
        $this->assertSame(3, DB::table('dokter')->count());
        $this->assertSame(2, DB::table('faskes')->count());
        $this->assertSame(4, DB::table('dokter_spesialisasi')->count());
        $this->assertSame(7, DB::table('lab_paket_item')->count());
        $this->assertSame(2, DB::table('obat_interaksi')->count());
    }

    public function test_the_seeded_superadmin_holds_every_permission_live(): void
    {
        // Read through the same join the middleware uses, so this proves the *data*
        // the middleware reads rather than the catalogue it was built from.
        //
        // The join starts at `roles`, NOT at `user_roles`: no seeder creates a
        // `user_roles` row - that is RoleAssigner's job at runtime - so routing this
        // query through `user_roles` returns nothing and would "pass" an empty
        // comparison only if the expectation were empty too.
        $granted = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.nama', 'superadmin')
            ->pluck('permissions.kode')
            ->all();

        // Canonicalising, not an ordered comparison: the query returns the codes in
        // `permissions.kode` order while `permissionCodes()` returns catalogue order,
        // and the claim under test is set equality, not an ordering contract.
        $expected = RbacCatalog::permissionCodes();
        sort($granted);
        sort($expected);

        $this->assertSame($expected, $granted);
    }
}
