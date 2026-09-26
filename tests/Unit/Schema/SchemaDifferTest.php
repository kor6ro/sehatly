<?php

use App\Support\Schema\SchemaDiffer;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use App\Support\Schema\TableSpec;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The differ is the half of the verifier that decides pass/fail, so it is tested
 * in both directions: every real class of drift must be reported, and every
 * cosmetic difference must NOT be. A differ that over-normalises is as broken as
 * one that under-normalises - both make the command lie.
 */
function parseTable(string $ddl): TableSpec
{
    return (new SqlSchemaParser)->parseCreateTable($ddl);
}

function specOf(TableSpec ...$tables): SchemaSpec
{
    $keyed = [];

    foreach ($tables as $table) {
        $keyed[$table->name] = $table;
    }

    return new SchemaSpec($keyed, []);
}

function diffTables(string $expectedDdl, string $actualDdl, array $extras = []): array
{
    $expected = parseTable($expectedDdl);
    $actual = (new SqlSchemaParser)->parseCreateTable($actualDdl, SqlSchemaParser::NAMES_ARE_SERVER_GENERATED);

    return (new SchemaDiffer)->diff(
        specOf($expected),
        specOf($actual),
        null,
        $extras,
    );
}

function driftKinds(array $discrepancies): array
{
    return array_values(array_unique(array_map(fn ($d) => $d->kind, $discrepancies)));
}

test('C4: a deliberate unsigned mismatch is reported, naming the column', function () {
    // The reference DDL (telemedicine_test.sql:59) says TINYINT UNSIGNED; the
    // "live" schema drifted to a signed TINYINT, which is exactly the failure the
    // plan asks the verifier to catch.
    $discrepancies = diffTables(
        'CREATE TABLE master_provinsi (id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT) ENGINE=InnoDB',
        'CREATE TABLE `master_provinsi` (`id` tinyint NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toContain('column_unsigned');

    $unsigned = array_values(array_filter($discrepancies, fn ($d) => $d->kind === 'column_unsigned'));
    expect($unsigned)->toHaveCount(1);
    expect($unsigned[0]->table)->toBe('master_provinsi');
    expect($unsigned[0]->column)->toBe('id');
    expect($unsigned[0]->expected)->toBe('unsigned');
    expect($unsigned[0]->actual)->toBe('signed');
    expect($unsigned[0]->isDrift())->toBeTrue();
});

test('an unsigned TINYINT still differs from an unsigned SMALLINT', function () {
    $discrepancies = diffTables(
        'CREATE TABLE t (id TINYINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE `t` (`id` smallint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toContain('column_type');
});

test('a type change is reported', function () {
    $discrepancies = diffTables(
        'CREATE TABLE master_provinsi (id TINYINT UNSIGNED PRIMARY KEY, kode CHAR(2) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE `master_provinsi` (`id` tinyint unsigned NOT NULL, `kode` char(3) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toBe(['column_type']);

    $type = $discrepancies[0];
    expect($type->column)->toBe('kode');
    expect($type->expected)->toBe('char(2)');
    expect($type->actual)->toBe('char(3)');
});

test('a nullability change is reported', function () {
    $discrepancies = diffTables(
        'CREATE TABLE t (catatan TEXT NULL) ENGINE=InnoDB',
        'CREATE TABLE `t` (`catatan` text NOT NULL) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toBe(['column_nullable']);
    expect($discrepancies[0]->column)->toBe('catatan');
    expect($discrepancies[0]->expected)->toBe('NULL');
    expect($discrepancies[0]->actual)->toBe('NOT NULL');
});

test('a default change is reported', function () {
    $discrepancies = diffTables(
        "CREATE TABLE t (a VARCHAR(10) NOT NULL DEFAULT 'x') ENGINE=InnoDB",
        "CREATE TABLE `t` (`a` varchar(10) NOT NULL DEFAULT 'y') ENGINE=InnoDB",
    );

    expect(driftKinds($discrepancies))->toBe(['column_default']);
    expect($discrepancies[0]->column)->toBe('a');
    expect($discrepancies[0]->expected)->toBe("'x'");
    expect($discrepancies[0]->actual)->toBe("'y'");
});

test('dropping a DEFAULT from a NOT NULL column is reported', function () {
    $discrepancies = diffTables(
        "CREATE TABLE t (a VARCHAR(10) NOT NULL DEFAULT 'x') ENGINE=InnoDB",
        'CREATE TABLE `t` (`a` varchar(10) NOT NULL) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toBe(['column_default']);
    expect($discrepancies[0]->expected)->toBe("'x'");
    expect($discrepancies[0]->actual)->toBe('<none>');
});

test('a nullable column with no DEFAULT equals one declared DEFAULT NULL', function () {
    // Laravel's $table->x()->nullable() emits `DEFAULT NULL` while
    // telemedicine_test.sql usually writes a bare `NULL`. MySQL's implicit default
    // for a nullable column is NULL, so reporting this would flag every nullable
    // column in the schema.
    $discrepancies = diffTables(
        'CREATE TABLE t (keluhan TEXT NULL, foto VARCHAR(500) NULL) ENGINE=InnoDB',
        'CREATE TABLE `t` (`keluhan` text NULL DEFAULT NULL, `foto` varchar(500) NULL DEFAULT NULL) ENGINE=InnoDB',
    );

    expect($discrepancies)->toBe([]);
});

test('an explicit DEFAULT NULL in the DDL is still parsed as a default, not as absence', function () {
    // The fold above is a comparison rule, not a parser rule: the model still
    // distinguishes the two, which is why users.dihapus_at reads as 'NULL'.
    $discrepancies = diffTables(
        'CREATE TABLE t (dihapus_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB',
        'CREATE TABLE `t` (`dihapus_at` timestamp NULL) ENGINE=InnoDB',
    );

    expect($discrepancies)->toBe([]);

    $parsed = (new SqlSchemaParser)->parseCreateTable('CREATE TABLE t (dihapus_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB');
    expect($parsed->columns['dihapus_at']->default)->toBe('NULL');
});

test('a missing index is reported, by name when the DDL named it', function () {
    $discrepancies = diffTables(
        'CREATE TABLE booking (id BIGINT UNSIGNED PRIMARY KEY, dokter_id BIGINT UNSIGNED NOT NULL, INDEX idx_booking_dokter (dokter_id)) ENGINE=InnoDB',
        'CREATE TABLE `booking` (`id` bigint unsigned NOT NULL, `dokter_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `idx_booking` (`dokter_id`)) ENGINE=InnoDB',
    );

    // The DDL wrote `idx_booking_dokter`, so the name is part of the contract and
    // the rename IS the drift - reported as a name difference, not waved through.
    expect(driftKinds($discrepancies))->toBe(['index_name']);
    expect($discrepancies[0]->expected)->toContain('idx_booking_dokter');
    expect($discrepancies[0]->actual)->toContain('idx_booking');
});

test('an index that simply is not there is reported as missing, plus the extra one', function () {
    $discrepancies = diffTables(
        'CREATE TABLE booking (id BIGINT UNSIGNED PRIMARY KEY, dokter_id BIGINT UNSIGNED NOT NULL, INDEX idx_booking_dokter (dokter_id)) ENGINE=InnoDB',
        'CREATE TABLE `booking` (`id` bigint unsigned NOT NULL, `dokter_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toBe(['missing_index']);
    expect($discrepancies[0]->expected)->toContain('idx_booking_dokter');
});

test('a named index whose columns changed is reported', function () {
    $discrepancies = diffTables(
        'CREATE TABLE t (id BIGINT UNSIGNED PRIMARY KEY, a BIGINT UNSIGNED NOT NULL, b BIGINT UNSIGNED NOT NULL, INDEX idx_pair (a, b)) ENGINE=InnoDB',
        'CREATE TABLE `t` (`id` bigint unsigned NOT NULL, `a` bigint unsigned NOT NULL, `b` bigint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `idx_pair` (`b`, `a`)) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toBe(['index_columns']);
    expect($discrepancies[0]->expected)->toContain('(a, b)');
    expect($discrepancies[0]->actual)->toContain('(b, a)');
});

test('an extra column is reported', function () {
    $discrepancies = diffTables(
        'CREATE TABLE t (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE `t` (`id` bigint unsigned NOT NULL, `sneaky` varchar(10) NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toBe(['extra_column']);
    expect($discrepancies[0]->column)->toBe('sneaky');
});

test('a missing table is reported, and so is the table that replaced it', function () {
    $expected = specOf(parseTable('CREATE TABLE a (id INT NOT NULL) ENGINE=InnoDB'));
    $actual = specOf(parseTable('CREATE TABLE b (id INT NOT NULL) ENGINE=InnoDB'));

    $discrepancies = (new SchemaDiffer)->diff($expected, $actual);

    expect(driftKinds($discrepancies))->toEqualCanonicalizing(['missing_table', 'undocumented_extra_table']);
    expect(array_values(array_filter($discrepancies, fn ($d) => $d->kind === 'missing_table'))[0]->table)->toBe('a');
});

test('a missing CHECK and a changed ON DELETE are reported', function () {
    $discrepancies = diffTables(
        'CREATE TABLE t (
            id BIGINT UNSIGNED PRIMARY KEY,
            other_id BIGINT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
            FOREIGN KEY (other_id) REFERENCES o(id) ON DELETE CASCADE
        ) ENGINE=InnoDB',
        'CREATE TABLE `t` (
            `id` bigint unsigned NOT NULL,
            `other_id` bigint unsigned NOT NULL,
            `rating` tinyint unsigned NOT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `t_ibfk_1` FOREIGN KEY (`other_id`) REFERENCES `o` (`id`)
        ) ENGINE=InnoDB',
    );

    // The FK is matched on its target, so losing ON DELETE CASCADE reads as an
    // action drift rather than as a delete plus an unrelated create.
    expect(driftKinds($discrepancies))->toBe(['foreign_key_action', 'missing_check']);
});

test('a foreign key pointing somewhere else entirely is missing plus extra', function () {
    $discrepancies = diffTables(
        'CREATE TABLE t (id BIGINT UNSIGNED PRIMARY KEY, other_id BIGINT UNSIGNED NOT NULL, FOREIGN KEY (other_id) REFERENCES a(id)) ENGINE=InnoDB',
        'CREATE TABLE `t` (`id` bigint unsigned NOT NULL, `other_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), CONSTRAINT `t_ibfk_1` FOREIGN KEY (`other_id`) REFERENCES `b` (`id`)) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toEqualCanonicalizing(['missing_foreign_key', 'extra_foreign_key']);
});

test('a changed referential action is reported rather than swallowed', function () {
    $discrepancies = diffTables(
        'CREATE TABLE t (id BIGINT UNSIGNED PRIMARY KEY, other_id BIGINT UNSIGNED NOT NULL, FOREIGN KEY (other_id) REFERENCES o(id) ON DELETE CASCADE) ENGINE=InnoDB',
        'CREATE TABLE `t` (`id` bigint unsigned NOT NULL, `other_id` bigint unsigned NOT NULL, PRIMARY KEY (`id`), CONSTRAINT `t_ibfk_1` FOREIGN KEY (`other_id`) REFERENCES `o` (`id`) ON DELETE SET NULL) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toBe(['foreign_key_action']);
    expect($discrepancies[0]->expected)->toContain('ON DELETE CASCADE');
    expect($discrepancies[0]->actual)->toContain('ON DELETE SET NULL');
});

test('cosmetic differences are NOT reported as drift', function (string $label, string $actualDdl) {
    $discrepancies = diffTables(
        // Reference side, written the way telemedicine_test.sql writes it.
        'CREATE TABLE users (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            email VARCHAR(255) NULL UNIQUE,
            telepon_terverifikasi TINYINT(1) NOT NULL DEFAULT 0,
            dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            dihapus_at TIMESTAMP NULL DEFAULT NULL,
            rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
            INDEX idx_rating (rating)
        ) ENGINE=InnoDB',
        $actualDdl,
    );

    expect($discrepancies)->toBe([], $label);
})->with([
    // Backticks, KEY vs INDEX, lower case, MySQL's own re-rendering.
    [
        'label' => 'backticks, KEY for INDEX, lower case',
        'actualDdl' => 'CREATE TABLE `users` (
        `id` bigint unsigned not null auto_increment,
        `email` varchar(255) null default null,
        `telepon_terverifikasi` tinyint(1) not null default 0,
        `dibuat_at` timestamp not null default current_timestamp(),
        `diubah_at` timestamp not null default current_timestamp() on update current_timestamp(),
        `dihapus_at` timestamp null default null,
        `rating` tinyint unsigned not null,
        primary key (`id`),
        unique key `email` (`email`),
        key `idx_rating` (`rating`),
        constraint `users_chk_1` check ((`rating` between 1 and 5))
    ) engine=InnoDB default charset=utf8mb4',
    ],

    // The engine named the inline UNIQUE `users_email_unique`, which is what
    // Laravel's $table->unique('email') produces. Same constraint.
    [
        'label' => 'Laravel-style users_email_unique index name',
        'actualDdl' => 'CREATE TABLE `users` (
        `id` bigint unsigned not null auto_increment,
        `email` varchar(255) null default null,
        `telepon_terverifikasi` tinyint(1) not null default 0,
        `dibuat_at` timestamp not null default current_timestamp(),
        `diubah_at` timestamp not null default current_timestamp() on update current_timestamp(),
        `dihapus_at` timestamp null default null,
        `rating` tinyint unsigned not null,
        primary key (`id`),
        unique key `users_email_unique` (`email`),
        key `idx_rating` (`rating`),
        constraint `users_chk_1` check ((`rating` between 1 and 5))
    ) engine=InnoDB',
    ],

    // int(20) vs bigint, and a quoted numeric default vs a bare one.
    [
        'label' => 'bigint(20) display width and quoted numeric default',
        'actualDdl' => 'CREATE TABLE `users` (
        `id` bigint(20) unsigned not null auto_increment,
        `email` varchar(255) null default null,
        `telepon_terverifikasi` tinyint(1) not null default 0,
        `dibuat_at` timestamp not null default current_timestamp(),
        `diubah_at` timestamp not null default current_timestamp() on update current_timestamp(),
        `dihapus_at` timestamp null default null,
        `rating` tinyint unsigned not null,
        primary key (`id`),
        unique key `email` (`email`),
        key `idx_rating` (`rating`),
        constraint `users_chk_1` check ((`rating` between 1 and 5))
    ) engine=InnoDB',
    ],
]);

test('a foreign key the DDL wrote without a name is matched through its engine-generated name', function () {
    $discrepancies = diffTables(
        'CREATE TABLE user_roles (
            user_id BIGINT UNSIGNED NOT NULL,
            role_id SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, role_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB',
        'CREATE TABLE `user_roles` (
            `user_id` bigint unsigned NOT NULL,
            `role_id` smallint unsigned NOT NULL,
            PRIMARY KEY (`user_id`,`role_id`),
            CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB',
    );

    expect($discrepancies)->toBe([]);
});

test('an index the DDL did not name is matched even when the engine gave it a Laravel-style name', function () {
    $expected = parseTable('CREATE TABLE users (email VARCHAR(255) NULL UNIQUE) ENGINE=InnoDB');

    $actual = (new SqlSchemaParser)->parseCreateTable(
        'CREATE TABLE `users` (`email` varchar(255) null, UNIQUE KEY `users_email_unique` (`email`)) ENGINE=InnoDB',
        SqlSchemaParser::NAMES_ARE_SERVER_GENERATED,
    );

    expect((new SchemaDiffer)->diff(specOf($expected), specOf($actual)))->toBe([]);
});

test('an inline UNIQUE compared only by semantics ignores the index name', function () {
    $expected = parseTable('CREATE TABLE users (email VARCHAR(255) NULL UNIQUE) ENGINE=InnoDB');

    foreach (['email', 'users_email_unique', 'anything_at_all'] as $name) {
        $actual = (new SqlSchemaParser)->parseCreateTable(
            "CREATE TABLE `users` (`email` varchar(255) null, UNIQUE KEY `{$name}` (`email`)) ENGINE=InnoDB",
            SqlSchemaParser::NAMES_ARE_SERVER_GENERATED,
        );

        expect((new SchemaDiffer)->diff(specOf($expected), specOf($actual)))->toBe([], 'index name: '.$name);
    }
});

test('a missing PRIMARY KEY is reported as such', function () {
    $discrepancies = diffTables(
        'CREATE TABLE t (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE `t` (`id` bigint unsigned NOT NULL) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toBe(['missing_primary_key']);
});

test('a CHECK whose expression drifted is reported, whatever it is called', function () {
    $discrepancies = diffTables(
        'CREATE TABLE t (rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5)) ENGINE=InnoDB',
        'CREATE TABLE `t` (`rating` tinyint unsigned NOT NULL, CONSTRAINT `some_other_name` CHECK ((`rating` between 0 and 5))) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toEqualCanonicalizing(['missing_check', 'extra_check']);
    expect(array_values(array_filter($discrepancies, fn ($d) => $d->kind === 'missing_check'))[0]->expected)
        ->toBe('rating between 1 and 5');
});

test('an undocumented extra table is drift; a documented one is informational', function () {
    $expected = specOf(parseTable('CREATE TABLE a (id INT NOT NULL) ENGINE=InnoDB'));
    $actual = specOf(
        parseTable('CREATE TABLE a (id INT NOT NULL) ENGINE=InnoDB'),
        parseTable('CREATE TABLE cache (`key` VARCHAR(255) NOT NULL) ENGINE=InnoDB'),
    );

    $undocumented = (new SchemaDiffer)->diff($expected, $actual);
    expect(driftKinds($undocumented))->toBe(['undocumented_extra_table']);
    expect($undocumented[0]->isDrift())->toBeTrue();
    expect($undocumented[0]->table)->toBe('cache');

    $documented = (new SchemaDiffer)->diff($expected, $actual, null, ['cache' => "Laravel's cache store."]);
    expect(driftKinds($documented))->toBe(['documented_extra_table']);
    expect($documented[0]->isDrift())->toBeFalse();
    expect($documented[0]->expected)->toBe("Laravel's cache store.");
});

test('a view present in one schema and not the other is reported', function () {
    $expected = new SchemaSpec([], ['v_one', 'v_two']);
    $actual = new SchemaSpec([], ['v_two', 'v_three']);

    $discrepancies = (new SchemaDiffer)->diff($expected, $actual);

    expect(driftKinds($discrepancies))->toBe(['extra_view', 'missing_view']);
    // An extra view is not a parity break: only the two the contract names matter.
    expect(array_values(array_filter($discrepancies, fn ($d) => $d->kind === 'missing_view'))[0]->table)->toBe('v_one');
    expect(array_values(array_filter($discrepancies, fn ($d) => $d->kind === 'extra_view'))[0]->isDrift())->toBeFalse();
});

test('restricting the scope with onlyTables ignores everything else', function () {
    $expected = specOf(
        parseTable('CREATE TABLE a (id TINYINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB'),
        parseTable('CREATE TABLE b (id INT NOT NULL) ENGINE=InnoDB'),
    );
    $actual = specOf(
        (new SqlSchemaParser)->parseCreateTable(
            'CREATE TABLE `a` (`id` tinyint NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB',
            SqlSchemaParser::NAMES_ARE_SERVER_GENERATED,
        ),
    );

    $discrepancies = (new SchemaDiffer)->diff($expected, $actual, ['a']);

    expect(driftKinds($discrepancies))->toBe(['column_unsigned']);
    expect($discrepancies[0]->table)->toBe('a');
});

test('a table the DDL never defined is reported as a caller mistake, not drift', function () {
    $expected = specOf(parseTable('CREATE TABLE a (id INT NOT NULL) ENGINE=InnoDB'));
    $actual = specOf(parseTable('CREATE TABLE a (id INT NOT NULL) ENGINE=InnoDB'));

    $discrepancies = (new SchemaDiffer)->diff($expected, $actual, ['a', 'not_in_the_ddl']);

    expect(driftKinds($discrepancies))->toBe(['unknown_requested_table']);
    expect($discrepancies[0]->isDrift())->toBeFalse();
});

test('the differ reports nothing for two identical schemas', function () {
    $ddl = 'CREATE TABLE t (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        kode CHAR(2) NOT NULL UNIQUE,
        dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_kode (kode)
    ) ENGINE=InnoDB';

    $discrepancies = diffTables($ddl, $ddl);

    expect($discrepancies)->toBe([]);
});

test('an AUTO_INCREMENT drift is reported', function () {
    $discrepancies = diffTables(
        'CREATE TABLE master_agama (id TINYINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE `master_agama` (`id` tinyint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB',
    );

    expect(driftKinds($discrepancies))->toBe(['column_auto_increment']);
});
