<?php

declare(strict_types=1);

use App\Support\Rbac\RbacCatalog;
use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The Rbac vocabulary is the DDL's, not this todo's
|--------------------------------------------------------------------------
|
| `users.tipe` is re-parsed out of `telemedicine_test.sql` on every run, the
| catalogue is checked for internal consistency, and the seeded rows are compared
| to the catalogue.
|
| **Pest closure tests, not a PHPUnit class**, for the same reason as
| `RbacMiddlewareTest`: `tests/Pest.php` binds `RefreshDatabase` to the closure
| tests in this directory, and without the per-test rollback the first test's
| `seed()` is still committed when the second one runs.
|
| **Why this file parses the SQL instead of trusting a docblock.** The failure
| mode A.26 exists to catch is a *silent* divergence: an ENUM value transcribed
| with a typo, or a permission added to a list by someone who did not notice it
| was not the schema's. A docblock claiming "`users.tipe` has 7 values" is a
| claim, not a check. So the value list is read out of the reference file with the
| project's own `SqlSchemaParser` - the same parser `sehatly:verify-schema` uses -
| and compared with `toBe`, which checks order as well as membership. A changed
| DDL, or a catalogue that drifted from it, fails here.
|
| **`RbacCatalog::PERMISSIONS` cannot be derived, only constrained.** The DDL
| documents *no* permission values: it fixes the column, its length, its
| uniqueness and one example in a comment (`:159`), and nothing else. The 25 codes
| are the plan's list, so the strongest honest claim available is that each is
| well-formed under the DDL's own `<resource>.<aksi>` example, that each fits
| `VARCHAR(100)`, that each is granted to at least one role so no route guards a
| code nothing holds, and that `superadmin` holds the whole catalogue so no code is
| dead.
|
*/

/**
 * Read an ENUM column's value list out of the reference SQL, through the project's
 * own parser.
 *
 * `ColumnSpec::$type` is the canonical type string, and for an ENUM that is
 * `enum('a','b')` with the members verbatim and in source order. Going through
 * `SqlSchemaParser` rather than a regex matters: it is the same code path
 * `sehatly:verify-schema` uses, so this cannot disagree with the verifier about
 * what the DDL says.
 *
 * @return list<string>
 */
function rbacEnumValuesFromDdl(string $table, string $column): array
{
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    expect($spec->hasTable($table))->toBeTrue();

    $tableSpec = $spec->table($table);

    expect($tableSpec)->not->toBeNull();
    // `toHaveKey`'s second argument is the expected *value*, not a message, so the
    // key check is asserted bare: passing prose there compares a ColumnSpec against a
    // string and fails with a message about types instead of about the missing key.
    expect($tableSpec->columns)->toHaveKey($column);

    $parsed = $tableSpec->columns[$column];

    // `toMatch()` also takes no message argument in Pest 4; the reason lives here.
    expect($parsed->type)->toMatch("/^enum\((.*)\)$/");

    preg_match("/^enum\((.*)\)$/", $parsed->type, $m);

    $values = array_map(
        static fn (string $member): string => trim($member, "'"),
        explode(',', $m[1]),
    );

    expect($values)->not->toContain('', "{$table}.{$column} has an empty ENUM member.");

    return $values;
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

// -------------------------------------------------------- DDL-derived facts

test('the user type list is the DDL enum verbatim, in the DDL order', function (): void {
    expect(rbacEnumValuesFromDdl('users', 'tipe'))->toBe(RbacCatalog::USER_TYPES);
});

test('the user type list is seven values wide', function (): void {
    // Stated separately so a truncated DDL read cannot pass by agreeing with a
    // catalogue that is wrong in exactly the same way.
    expect(RbacCatalog::USER_TYPES)->toHaveCount(7);
});

test('every role name is a DDL user type', function (): void {
    $ddlTypes = rbacEnumValuesFromDdl('users', 'tipe');

    // A role name that is not a `tipe` value would make the role vocabulary and the
    // account-type vocabulary two conflicting descriptions of the same axis, and
    // `booking.dibatalkan_oleh` is populated from `users.tipe`.
    //
    // Note: Pest's `toContain()` is variadic - a second argument is another *needle*,
    // not a failure message - so the reason lives here rather than in the call.
    foreach (RbacCatalog::ROLES as $role) {
        expect($ddlTypes)->toContain($role);
    }
});

test('the DDL citation constant still points at the users tipe enum', function (): void {
    // Guards the citation itself. The plan's todo 4 cites `:137` for `users.tipe`
    // and that is wrong - `:137` is `no_telepon VARCHAR(20) NOT NULL UNIQUE`. If the
    // file is ever renumbered this fails, rather than leaving a wrong line number
    // standing in a docblock.
    [$file, $line] = explode(':', RbacCatalog::DDL_USER_TYPES_LINE, 2);

    expect($file)->toBe('telemedicine_test.sql');

    // The reference file is CRLF-terminated (1,348 CRLF against 1,349 lines), so a
    // plain explode("\n") leaves a trailing \r on every line and a `toContain` for
    // the declaration text would fail on invisible whitespace.
    $lines = preg_split("/\r\n|\n|\r/", (string) file_get_contents(base_path('telemedicine_test.sql')));

    expect($lines)->toHaveKey((int) $line - 1);

    // `toContain()` takes needles, not a message, so the "what this guards" sentence
    // is a comment: if this fails, the constant cites a line that is no longer the
    // users.tipe declaration - which is exactly the todo-4 `:137` mistake.
    expect($lines[(int) $line - 1])->toContain(
        "tipe ENUM('pasien','dokter','perawat','apoteker','kurir','admin','superadmin')"
    );
});

test('every permission code follows the DDL own naming convention', function (): void {
    // The convention is read out of the DDL's comment, not out of a constant in this
    // file: telemedicine_test.sql:159 is
    //   kode VARCHAR(100) NOT NULL UNIQUE COMMENT 'cth: rekam_medis.lihat, resep.raut'
    // (with `resep.buat`), so a change to the contract's own convention is what
    // breaks this test rather than a change to a constant written beside it.
    $sql = (string) file_get_contents(base_path('telemedicine_test.sql'));

    expect($sql)->toMatch("/COMMENT 'cth: ([a-z_]+\.[a-z_]+), ([a-z_]+\.[a-z_]+)'/");

    preg_match("/COMMENT 'cth: ([a-z_]+\.[a-z_]+), ([a-z_]+\.[a-z_]+)'/", $sql, $m);

    expect(RbacCatalog::PERMISSION_SEPARATOR)->toBe('.');

    // Both of the DDL's own examples must be real codes here, which is the whole
    // point of adopting the DDL's convention: the vocabulary is not an invention.
    expect(RbacCatalog::isPermission($m[1]))->toBeTrue()
        ->and(RbacCatalog::isPermission($m[2]))->toBeTrue();

    foreach (RbacCatalog::permissionCodes() as $kode) {
        expect($kode)->toMatch('/^[a-z_]+\.[a-z_]+$/');
        expect(strlen($kode))->toBeLessThanOrEqual(100);
    }
});

// -------------------------------------------------------- catalogue invariants

test('the catalogue holds at least the plan twenty two permissions', function (): void {
    // The plan's acceptance bar is ">= 22". 25 is what the catalogue carries after
    // F14's owner-approved `laporan.lihat`; the second assertion is written at the
    // actual number so a trim or an addition has to be a deliberate edit of this
    // line.
    expect(RbacCatalog::PERMISSIONS)->toHaveCount(25)
        ->and(count(RbacCatalog::PERMISSIONS))->toBeGreaterThanOrEqual(22);
});

test('every display name is derived from its own code', function (): void {
    foreach (RbacCatalog::PERMISSIONS as $kode => $nama) {
        expect($nama)->toBe(
            RbacCatalog::displayNameFor($kode),
            "permissions.nama for [{$kode}] does not follow RbacCatalog::displayNameFor(), so it was hand-written "
            .'and can drift.'
        )->and(strlen($nama))->toBeLessThanOrEqual(100);
    }
});

test('the seeder writes the derived display names', function (): void {
    $row = DB::table('permissions')->where('kode', 'rekam_medis.simpan')->first();

    expect($row)->not->toBeNull()
        ->and($row->nama)->toBe(RbacCatalog::PERMISSIONS['rekam_medis.simpan'])
        ->and($row->nama)->toBe('Simpan Rekam Medis');
});

test('every permission is granted to at least one role', function (): void {
    $granted = [];

    foreach (RbacCatalog::ROLE_PERMISSIONS as $permissions) {
        foreach ($permissions as $kode) {
            $granted[$kode] = true;
        }
    }

    $orphans = array_values(array_diff(RbacCatalog::permissionCodes(), array_keys($granted)));

    expect($orphans)->toBe([], 'These permissions are in the catalogue but held by no role, so a route guarding '
        .'one could never be reached: '.implode(', ', $orphans).'.');
});

test('every granted permission exists and every role is mapped', function (): void {
    foreach (RbacCatalog::ROLE_PERMISSIONS as $role => $permissions) {
        expect(RbacCatalog::isRole($role))->toBeTrue("[{$role}] is mapped but is not in RbacCatalog::ROLES.");

        foreach ($permissions as $kode) {
            expect(RbacCatalog::isPermission($kode))->toBeTrue(
                "Role [{$role}] is granted [{$kode}], which is not in RbacCatalog::PERMISSIONS."
            );
        }
    }

    expect(array_keys(RbacCatalog::ROLE_PERMISSIONS))->toBe(RbacCatalog::ROLES);
});

test('a role holds no duplicate grants', function (): void {
    foreach (RbacCatalog::ROLE_PERMISSIONS as $role => $permissions) {
        expect(array_values(array_unique($permissions)))->toBe(
            $permissions,
            "Role [{$role}] lists a permission twice, which would be a MySQL 1062 on role_permissions' composite "
            .'primary key.'
        );
    }
});

test('superadmin holds exactly the whole catalogue', function (): void {
    expect(RbacCatalog::permissionsFor('superadmin'))->toBe(
        RbacCatalog::permissionCodes(),
        'superadmin must hold every permission explicitly. There is no code-level bypass in the middleware, so a '
        .'missing grant here would be a real denial.'
    );
});

test('permissions for an unknown role throws rather than returning empty', function (): void {
    // Returning [] for an unknown role would make a typo indistinguishable from a
    // role that genuinely has no permissions - i.e. from a working authorisation
    // decision.
    expect(fn () => RbacCatalog::permissionsFor('doktor'))->toThrow(LogicException::class);
});

// -------------------------------------------------------------- seeded rows

test('the seeded roles are exactly the catalogue roles', function (): void {
    expect(DB::table('roles')->orderBy('id')->pluck('nama')->all())->toBe(RbacCatalog::ROLES);
});

test('every seeded role has a description that fits the column', function (): void {
    // `roles.deskripsi` is `VARCHAR(255) NULL`. Nullable, so an empty description
    // would be legal, but a seeded role with none is an admin screen with a blank
    // cell, so it is asserted rather than assumed.
    foreach (DB::table('roles')->orderBy('id')->get() as $row) {
        expect($row->deskripsi)->not->toBeNull("Role [{$row->nama}] has no deskripsi.")
            ->and(strlen((string) $row->deskripsi))->toBeLessThanOrEqual(255)
            ->and($row->deskripsi)->toBe(RbacCatalog::ROLE_DESCRIPTIONS[$row->nama]);
    }
});

test('the seeded permissions are exactly the catalogue codes', function (): void {
    expect(DB::table('permissions')->orderBy('id')->pluck('kode')->all())->toBe(RbacCatalog::permissionCodes());
});

test('the seeded role permission pairs are exactly the catalogue mapping', function (): void {
    $actual = DB::table('role_permissions')
        ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
        ->orderBy('roles.nama')
        ->orderBy('permissions.kode')
        ->get(['roles.nama', 'permissions.kode'])
        ->map(static fn ($row): array => ['nama' => $row->nama, 'kode' => $row->kode])
        ->all();

    $expected = [];

    foreach (RbacCatalog::ROLE_PERMISSIONS as $role => $permissions) {
        foreach ($permissions as $kode) {
            $expected[] = ['nama' => $role, 'kode' => $kode];
        }
    }

    usort($expected, static fn (array $a, array $b): int => [$a['nama'], $a['kode']] <=> [$b['nama'], $b['kode']]);

    expect($actual)->toBe($expected)
        // 13 + 16 + 6 + 11 + 25, stated so a trim of the mapping is a visible edit.
        // Admin's 11 includes F14's `laporan.lihat`; superadmin holds all 25.
        ->and($actual)->toHaveCount(71);
});

test('the seeder writes no user roles rows', function (): void {
    // `user_roles` is owned by the reset but written by nobody in the seeder tree:
    // assigning a role to a real account is an application action. If a fixture user
    // ever appeared here, the RBAC kernel and the dev fixtures would have been
    // conflated.
    expect(DB::table('user_roles')->count())->toBe(0);
});
