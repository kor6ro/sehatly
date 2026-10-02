<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Rbac\RbacCatalog;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RbacSeeder;
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
        $this->assertCount(75, $actual);
    }

    public function test_no_role_is_granted_to_an_account_outside_the_demo_set(): void
    {
        // CHANGED BY F3-02, and the change is stated rather than hidden.
        //
        // This test used to assert that `user_roles` is EMPTY after
        // `migrate:fresh --seed`, on the rule that the RBAC kernel assigns roles
        // at runtime through `RoleAssigner` and no seeder ever does it. **That
        // rule is no longer true and cannot be**: `DemoDataSeeder` grants `pasien`,
        // `dokter` and `apoteker` to three named demo accounts, because a doctor
        // and a pharmacist who hold no role are 403 on every route they exist to
        // use - which is exactly the BLOCKER (F3-02) the demo seeder removes.
        //
        // What SURVIVES, and is asserted below, is the part that was actually
        // load-bearing: the RBAC kernel itself grants nothing, and the only
        // grants in the tree belong to the accounts `DemoDataSeeder::namaAkun()`
        // names. A count of zero would have been the wrong assertion; a count of
        // exactly three, on exactly the right users, is a stronger one.
        $demo = [];

        foreach (DemoDataSeeder::namaAkun() as $nama) {
            $akun = DemoDataSeeder::akun($nama);

            $userId = (int) DB::table('users')->where('no_telepon', $akun['no_telepon'])->value('id');

            $this->assertGreaterThan(0, $userId, "The demo account [{$nama}] has no users row after migrate:fresh --seed.");

            $demo[] = $userId;
        }

        $this->assertSame(
            3,
            DB::table('user_roles')->count(),
            'Exactly the three demo accounts may hold a role after migrate:fresh --seed, and nobody else does.',
        );

        $penerima = DB::table('user_roles')->orderBy('user_id')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();

        sort($demo);
        sort($penerima);

        $this->assertSame($demo, $penerima, 'A role was granted to an account outside the demo set.');
    }

    public function test_the_rbac_kernel_alone_grants_no_role(): void
    {
        // The half of the changed test above that is about the KERNEL rather than
        // about the tree, and which no later seeder can take away from it:
        // `RbacSeeder` writes `roles`, `permissions` and `role_permissions` and
        // nothing else. Re-running it on the seeded database must not create a
        // single grant, which is the same property
        // `RbacSeederIdempotencyTest` measures through the query log.
        $sebelum = DB::table('user_roles')->count();

        $exit = Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);

        $this->assertSame(0, $exit, 'RbacSeeder exited non-zero: '.Artisan::output());
        $this->assertSame(
            $sebelum,
            DB::table('user_roles')->count(),
            'RbacSeeder created a user_roles row, which is RoleAssigner\'s job and never the kernel\'s.',
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
        // The 7 tables DevFixtureSeeder writes.
        //
        // **CHANGED BY F3-02.** This used to assert exact table COUNTS - 5 users,
        // 2 patients, 3 doctors, 2 facilities, 4 specialisation links - and those
        // counts are exactly what adding a second fixture seeder to the chain
        // changes. The test's own docblock says why it exists: *"adding two
        // seeders to a chain is exactly the change that silently drops somebody
        // else's rows"*. A COUNT cannot survive that, and weakening it to `>=`
        // would throw away the property it was written to protect.
        //
        // So the assertion is now by NATURAL KEY, which is strictly stronger:
        // `DevFixtureSeeder`'s own five accounts are pinned by their emails, its
        // two patients by their `nomor_rm`, its three doctors by their
        // `nomor_str` and its two facilities by their `kode_faskes`. A seeder that
        // dropped or renumbered one of them fails here, and adding a THIRD fixture
        // seeder later will not.
        $this->assertSame(
            ['doker1.dev@example.test', 'doker2.dev@example.test', 'doker3.dev@example.test', 'pasien1.dev@example.test', 'pasien2.dev@example.test'],
            DB::table('users')->where('email', 'like', '%.dev@example.test')->orderBy('email')->pluck('email')->all(),
            'DevFixtureSeeder\'s five accounts did not all land after migrate:fresh --seed.',
        );

        $this->assertSame(
            ['RM-202601-000001', 'RM-202601-000002'],
            DB::table('pasien')->whereIn('nomor_rm', ['RM-202601-000001', 'RM-202601-000002'])->orderBy('nomor_rm')->pluck('nomor_rm')->all(),
        );

        $this->assertSame(
            ['STR-DEV-0001', 'STR-DEV-0002', 'STR-DEV-0003'],
            DB::table('dokter')->whereIn('nomor_str', ['STR-DEV-0001', 'STR-DEV-0002', 'STR-DEV-0003'])->orderBy('nomor_str')->pluck('nomor_str')->all(),
        );

        $this->assertSame(
            ['FASKES-DEV-001', 'FASKES-DEV-002'],
            DB::table('faskes')->whereIn('kode_faskes', ['FASKES-DEV-001', 'FASKES-DEV-002'])->orderBy('kode_faskes')->pluck('kode_faskes')->all(),
        );

        // The two tables no other seeder writes, so the counts still hold as
        // counts.
        $this->assertSame(7, DB::table('lab_paket_item')->count());
        $this->assertSame(2, DB::table('obat_interaksi')->count());
    }

    public function test_the_f3c_demo_accounts_land_too(): void
    {
        // The other half of the change above: the chain now ALSO produces three
        // loginable demo accounts and the relationships the four unreachable
        // journeys need. Counting them is the assertion that the documented boot
        // path produces a usable system, which is the whole point of F3-02.
        $this->assertSame(3, DemoDataSeeder::namaAkun() === [] ? 0 : count(DemoDataSeeder::namaAkun()));

        foreach (DemoDataSeeder::namaAkun() as $nama) {
            $akun = DemoDataSeeder::akun($nama);

            $this->assertSame(1, DB::table('users')->where('no_telepon', $akun['no_telepon'])->count());
            $this->assertTrue(
                password_verify($akun['kata_sandi'], (string) DB::table('users')->where('no_telepon', $akun['no_telepon'])->value('kata_sandi_hash')),
                "The demo account [{$nama}] does not authenticate with its published password.",
            );
        }

        $dokterId = (int) DB::table('dokter')->where('nomor_str', 'STR-DEMO-0001')->value('id');

        $this->assertGreaterThan(0, $dokterId, 'The demo doctor did not land.');
        $this->assertSame(1, DB::table('pasien')->where('nomor_rm', 'RM-DEMO-000001')->count());
        $this->assertSame(1, DB::table('faskes')->where('kode_faskes', 'FASKES-DEMO-001')->count());
        $this->assertSame(7, DB::table('dokter_jadwal')->where('dokter_id', $dokterId)->count());
        $this->assertSame(2, DB::table('apotek_stok')->count());
        $this->assertSame(1, DB::table('pasien_alergi')->count());
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
