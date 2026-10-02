<?php

declare(strict_types=1);

use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| RbacSeeder is idempotent
|--------------------------------------------------------------------------
|
| ## The defect this file exists to pin
|
| The F2 code-quality gate raised a BLOCKER on `database/seeders/RbacSeeder.php`:
| `run()` performed three **plain** `DB::table()->insert()` calls - `roles`,
| `permissions` and `role_permissions` - with no `insertOrIgnore`, no `upsert`
| and no delete-first, so the seeder was fatal on any second run against a
| populated database. `roles.nama` and `permissions.kode` are `UNIQUE`
| (`telemedicine_test.sql:153`, `:159`) and `role_permissions` has
| `PRIMARY KEY (role_id, permission_id)` (`:166`), so a second run is MySQL
| **1062**.
|
| **The whole 1,153-test green result was conditional on `RefreshDatabase`.**
| Twenty-eight Feature files call `$this->seed(RbacSeeder::class)`, and those
| calls are safe only because `tests/Pest.php` binds `RefreshDatabase` to this
| directory, so every test runs inside a transaction that is rolled back.
| Outside that wrapper, seeding twice is a hard failure - so a deliverable whose
| acceptance includes `migrate --seed` could not ship.
|
| ## No `beforeEach` seed on purpose
|
| A `beforeEach` that seeds once, plus a test that seeds again, would still be a
| double-seed - but it would hide which call blew up. Each test here performs
| every seed it needs, so a failure names the second seed itself.
|
| ## The three weaker tests that were available and were not written
|
| 1. **"a second run does not throw"** is weak alone: a seeder that silently
|    `TRUNCATE`s and re-inserts also satisfies it, and would delete every grant
|    in the system as a side effect of a command that reads like a read.
|    `every statement a re-run issues is an insert` below is the assertion that
|    rules that implementation out, and it is why the fix chose
|    `upsert`/`insertOrIgnore` over a scoped delete-then-insert.
| 2. **A re-run against `roles` and `permissions` must *repair* a drifted row**,
|    not merely tolerate it. That is the reason the two parent tables are
|    upserted and not `insertOrIgnore`d: a duplicate `nama`/`kode` is not proof
|    of a correct row, so a stale `deskripsi`/`nama` is repaired rather than
|    kept and reported as success.
| 3. **The 1062 itself is asserted as a permanent control**, so if a future
|    driver ever stopped raising it this file would notice rather than quietly
|    pass a seeder that had stopped being non-idempotent.
|
| ## The "an existing grant is not churned" claim is checked, not asserted
|
| `every statement a re-run issues is an insert` reads the query log, so the
| no-churn property is a machine-checked fact about the statements emitted
| rather than a claim in a docblock.
|
*/

/**
 * Every row of the RBAC kernel, every column, in a deterministic order.
 *
 * `id` is included deliberately: a re-seed that renumbered `roles` or
 * `permissions` would break every `role_permissions` and `user_roles` foreign
 * key pointing at the old value, and a name-only comparison would not see it.
 *
 * @return array{roles: list<array{id: int, nama: string, deskripsi: ?string}>, permissions: list<array{id: int, kode: string, nama: string}>, grants: list<array{role_id: int, permission_id: int}>}
 */
function rbacKernelSnapshot(): array
{
    return [
        'roles' => DB::table('roles')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'nama' => (string) $row->nama,
                'deskripsi' => $row->deskripsi,
            ])
            ->all(),
        'permissions' => DB::table('permissions')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'kode' => (string) $row->kode,
                'nama' => (string) $row->nama,
            ])
            ->all(),
        'grants' => DB::table('role_permissions')
            ->orderBy('role_id')
            ->orderBy('permission_id')
            ->get()
            ->map(static fn ($row): array => [
                'role_id' => (int) $row->role_id,
                'permission_id' => (int) $row->permission_id,
            ])
            ->all(),
    ];
}

/**
 * The distinct `role_permissions` primary keys, read as pairs.
 *
 * `count()` takes one column, so a composite key is projected first. Counting
 * rows instead would pass on a table holding every grant twice.
 *
 * @return list<array{int, int}>
 */
function rbacDistinctGrantPairs(): array
{
    return DB::table('role_permissions')
        ->selectRaw('DISTINCT role_id, permission_id')
        ->get()
        ->map(static fn ($row): array => [(int) $row->role_id, (int) $row->permission_id])
        ->all();
}

/**
 * The grants currently stored, as "role|permission" strings ordered naturally.
 *
 * @return list<string>
 */
function rbacGrantedPairs(): array
{
    $pairs = DB::table('role_permissions')
        ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
        ->get(['roles.nama', 'permissions.kode'])
        ->map(static fn ($row): string => $row->nama.'|'.$row->kode)
        ->all();

    sort($pairs);

    return $pairs;
}

/**
 * The catalogue's own grants, in the same "role|permission" shape.
 *
 * @return list<string>
 */
function rbacCatalogPairs(): array
{
    $pairs = [];

    foreach (RbacCatalog::ROLE_PERMISSIONS as $role => $permissions) {
        foreach ($permissions as $kode) {
            $pairs[] = $role.'|'.$kode;
        }
    }

    sort($pairs);

    return $pairs;
}

/**
 * The `role_permissions` writes a re-run emitted, from the query log.
 *
 * @param  list<string>  $sql
 * @return list<string>
 */
function rbacJoinWrites(array $sql): array
{
    return array_values(array_filter(
        $sql,
        static fn (string $statement): bool => str_contains($statement, '`role_permissions`')
            && str_starts_with(strtolower(ltrim($statement)), 'insert')
    ));
}

/**
 * Run {@see RbacSeeder} with the query log recording, and return the SQL emitted.
 *
 * The test case is a parameter because a plain function has no `$this`; the
 * seeder is reached through `seed()`, not through a hand-rolled `call()`.
 *
 * @return list<string>
 */
function seedRbacAndCaptureSql(TestCase $test): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $test->seed(RbacSeeder::class);
    } finally {
        DB::disableQueryLog();
    }

    return array_map(
        static fn (array $entry): string => (string) $entry['query'],
        DB::getQueryLog()
    );
}

// ------------------------------------------------------------ the defect itself

test('the defect is real: a plain re-insert of a seeded role is MySQL 1062', function (): void {
    // The control. If this stops raising, "the second run succeeded" below would
    // prove nothing: the duplicate key would no longer be an error and the seeder
    // would be idempotent for a reason that has nothing to do with the fix.
    $this->seed(RbacSeeder::class);

    expect(DB::table('roles')->where('nama', 'admin')->count())->toBe(1);

    $reinsert = fn (): int => DB::table('roles')->insert([
        'nama' => 'admin',
        'deskripsi' => 'a second attempt',
    ]);

    expect($reinsert)->toThrow(QueryException::class, '1062');

    // And the join table, whose duplicate is a composite primary key rather than
    // a unique index. Both are 1062; the pre-fix seeder hit the first one.
    $pair = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->first();

    expect($pair)->not->toBeNull();

    $regrant = fn (): int => DB::table('role_permissions')->insert([
        'role_id' => (int) $pair->role_id,
        'permission_id' => (int) $pair->permission_id,
    ]);

    expect($regrant)->toThrow(QueryException::class, '1062');

    // The control leaves every table as it found it, so the tests below are not
    // order-dependent on it.
    expect(DB::table('roles')->count())->toBe(5)
        ->and(DB::table('permissions')->count())->toBe(25)
        ->and(DB::table('role_permissions')->count())->toBe(71);
});

// ------------------------------------------------------------- the idempotency

test('seeding RbacSeeder a second time against the same populated tables succeeds', function (): void {
    $this->seed(RbacSeeder::class);

    $afterFirst = rbacKernelSnapshot();

    // The call that was fatal before the fix.
    $this->seed(RbacSeeder::class);

    expect(rbacKernelSnapshot())->toBe($afterFirst);
});

test('seeding RbacSeeder repeatedly is stable, and never grows a table', function (): void {
    $this->seed(RbacSeeder::class);
    $afterFirst = rbacKernelSnapshot();

    $this->seed(RbacSeeder::class);
    $this->seed(RbacSeeder::class);
    $this->seed(RbacSeeder::class);

    expect(rbacKernelSnapshot())->toBe($afterFirst);

    // Absolute numbers, so a trim of the catalogue is a visible edit here rather
    // than a seeder still idempotent over quietly fewer rows.
    expect(DB::table('roles')->count())->toBe(5)
        ->and(DB::table('permissions')->count())->toBe(25)
        ->and(DB::table('role_permissions')->count())->toBe(71);
});

test('a second run leaves no duplicate role, permission or grant', function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(RbacSeeder::class);

    expect(DB::table('roles')->count())->toBe(DB::table('roles')->distinct()->count('nama'))
        ->and(DB::table('permissions')->count())->toBe(DB::table('permissions')->distinct()->count('kode'))
        ->and(DB::table('role_permissions')->count())->toBe(count(rbacDistinctGrantPairs()));

    // The mapping is still exactly the catalogue's, keyed by natural key rather
    // than by id, so "no duplicates" cannot be satisfied by a doubled table that
    // happens to hold every right pair twice.
    $actual = rbacGrantedPairs();

    expect($actual)->toBe(rbacCatalogPairs())
        ->and($actual)->toHaveCount(71);
});

// ------------------------------------------------------ why upsert, not ignore

test('a drifted role description is repaired by the next run', function (): void {
    // The reason `roles` is upserted and not `insertOrIgnore`d. A duplicate
    // `nama` is not proof that the row is correct: the `deskripsi` beside it can
    // be stale, and `insertOrIgnore` would keep the stale copy forever while
    // reporting success.
    $this->seed(RbacSeeder::class);

    DB::table('roles')->where('nama', 'admin')->update(['deskripsi' => 'a description nobody wrote']);

    expect(DB::table('roles')->where('nama', 'admin')->value('deskripsi'))->toBe('a description nobody wrote');

    $this->seed(RbacSeeder::class);

    expect(DB::table('roles')->where('nama', 'admin')->value('deskripsi'))
        ->toBe(RbacCatalog::ROLE_DESCRIPTIONS['admin']);
});

test('a drifted permission display name is repaired by the next run', function (): void {
    $this->seed(RbacSeeder::class);

    DB::table('permissions')->where('kode', 'rekam_medis.simpan')->update(['nama' => 'Stale Name']);

    $this->seed(RbacSeeder::class);

    expect(DB::table('permissions')->where('kode', 'rekam_medis.simpan')->value('nama'))
        ->toBe(RbacCatalog::PERMISSIONS['rekam_medis.simpan']);
});

test('a drifted description is repaired without renumbering the row', function (): void {
    // The repair must not churn the primary key. `role_permissions` and
    // `user_roles` both reference `roles.id`, and `user_roles` is the table this
    // seeder tree never writes, so renumbering it would silently re-point a real
    // application's grants at a different role.
    $this->seed(RbacSeeder::class);

    $adminId = DB::table('roles')->where('nama', 'admin')->value('id');

    DB::table('roles')->where('nama', 'admin')->update(['deskripsi' => 'a description nobody wrote']);
    $this->seed(RbacSeeder::class);

    expect(DB::table('roles')->where('nama', 'admin')->value('id'))->toBe($adminId);

    $grants = DB::table('role_permissions')->where('role_id', $adminId)->count();

    // 11 since F14 added `laporan.lihat` to the admin role; the number is stated
    // so a catalogue change is a deliberate edit here too.
    expect($grants)->toBe(11)
        ->and(DB::table('role_permissions')->where('role_id', $adminId)->distinct()->count('permission_id'))
        ->toBe($grants);
});

// ------------------------------------------------- why insert-ignore, not delete

test('every statement a re-run issues is a read or an insert', function (): void {
    $this->seed(RbacSeeder::class);
    $before = rbacKernelSnapshot();

    $sql = seedRbacAndCaptureSql($this);

    // `select` is expected and required: the two `pluck()` calls are how the
    // seeder resolves `roles.id` and `permissions.id` by natural key, which is
    // what lets it keep a pre-existing row's id. The property under test is that
    // nothing is rewritten or emptied.
    $neitherReadNorInsert = array_values(array_filter(
        $sql,
        static fn (string $statement): bool => ! str_starts_with(strtolower(ltrim($statement)), 'insert')
            && ! str_starts_with(strtolower(ltrim($statement)), 'select')
    ));

    // Stronger than "no DELETE and no UPDATE", and it is the property that
    // matters: a re-run adds nothing, removes nothing and changes no id. It also
    // rules out the delete-then-insert shape, which would satisfy every other
    // test in this file.
    expect($neitherReadNorInsert)->toBe([], 'A re-run must not rewrite or empty a table it already seeded: '.implode(' | ', $neitherReadNorInsert));

    // The two reads are the natural-key lookups, and they are what makes the id
    // stability above work rather than an accident.
    expect(array_values(array_filter(
        $sql,
        static fn (string $statement): bool => str_starts_with(strtolower(ltrim($statement)), 'select')
    )))->toBe(['select `id`, `nama` from `roles`', 'select `id`, `kode` from `permissions`']);

    // The join write is an insert-ignore. It is the only form that is a no-op on
    // a duplicate composite primary key without putting a broad `IGNORE` in front
    // of every other class of error on the table.
    $joinWrites = rbacJoinWrites($sql);

    expect($joinWrites)->toHaveCount(1)
        ->and($joinWrites[0])->toContain('insert ignore into `role_permissions`');

    expect(rbacKernelSnapshot())->toBe($before);
});

test('a re-run writes all 71 grants in one statement, not one per row', function (): void {
    // Structural rather than behavioural: a per-row loop would also be idempotent,
    // but it would be 71 round-trips re-checking the same composite key 71 times.
    // The batch form is what the pre-fix code did and what the fix keeps.
    $this->seed(RbacSeeder::class);

    $sql = seedRbacAndCaptureSql($this);

    expect(rbacJoinWrites($sql))->toHaveCount(1);
});
