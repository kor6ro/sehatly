<?php

use App\Support\Schema\Discrepancy;
use App\Support\Schema\SchemaDiffer;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Tests\TestCase;

uses(TestCase::class);

/**
 * InnoDB creates an index to back every foreign key whose local columns are not
 * already covered by an existing index's leftmost prefix. `telemedicine_test.sql`
 * declares 81 of its 107 foreign keys with no covering index, relying on exactly
 * that behaviour, so a schema faithfully built from the reference legitimately
 * carries indexes the reference model does not name.
 *
 * An index whose ordered column list is exactly equal to the local column list of
 * a *matched* foreign key on the same table is therefore implied by that FK, not
 * drift. These tests pin that exemption AND its boundaries: an overlapping index,
 * an index on unrelated columns, an extra index on a table with no FK, and a
 * reversed multi-column index must all still be reported. A differ that forgives
 * too much here would make todo 18's green unfalsifiable.
 *
 * The reference DDL below is copied verbatim from telemedicine_test.sql:64-70; the
 * "live" DDL is the real `SHOW CREATE TABLE` of `telemedisin_db.master_kabupaten_kota`.
 */

/**
 * @return list<Discrepancy>
 */
function impliedIndexDiff(string $expectedDdl, string $liveDdl): array
{
    $parser = new SqlSchemaParser;
    $expected = new SchemaSpec(['t' => $parser->parseCreateTable($expectedDdl)], []);

    // Live DDL carries server-generated names, exactly as LiveSchemaReader reads it.
    $live = new SchemaSpec(
        ['t' => $parser->parseCreateTable($liveDdl, SqlSchemaParser::NAMES_ARE_SERVER_GENERATED)],
        [],
    );

    return (new SchemaDiffer)->diff($expected, $live);
}

/**
 * @param  list<Discrepancy>  $discrepancies
 * @return list<string>
 */
function impliedIndexKinds(array $discrepancies): array
{
    return array_values(array_unique(array_map(static fn ($d): string => $d->kind, $discrepancies)));
}

/**
 * @param  list<Discrepancy>  $discrepancies
 */
function impliedIndexOfKind(array $discrepancies, string $kind): Discrepancy
{
    $found = array_values(array_filter($discrepancies, static fn ($d): bool => $d->kind === $kind));

    expect($found)->toHaveCount(1);

    return $found[0];
}

// telemedicine_test.sql:64-70 — `FOREIGN KEY (provinsi_id)` is declared with NO
// covering index, so importing this DDL makes InnoDB create one.
const IMPLIED_FK_EXPECTED_DDL = <<<'SQL'
CREATE TABLE t (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  provinsi_id TINYINT UNSIGNED NOT NULL,
  kode CHAR(4) NOT NULL UNIQUE,
  nama VARCHAR(100) NOT NULL,
  FOREIGN KEY (provinsi_id) REFERENCES master_provinsi(id)
) ENGINE=InnoDB
SQL;

test('1: an index on exactly a matched foreign key\'s columns is implied, not extra_index', function () {
    $discrepancies = impliedIndexDiff(IMPLIED_FK_EXPECTED_DDL, <<<'SQL'
        CREATE TABLE `t` (
          `id` smallint unsigned NOT NULL AUTO_INCREMENT,
          `provinsi_id` tinyint unsigned NOT NULL,
          `kode` char(4) NOT NULL,
          `nama` varchar(100) NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `t_kode_unique` (`kode`),
          KEY `t_provinsi_id_foreign` (`provinsi_id`),
          CONSTRAINT `t_provinsi_id_foreign` FOREIGN KEY (`provinsi_id`) REFERENCES `master_provinsi` (`id`)
        ) ENGINE=InnoDB
        SQL);

    expect(impliedIndexKinds($discrepancies))->not->toContain('extra_index');
    // Nothing at all: the whole table diff is clean, not merely this one kind.
    expect($discrepancies)->toBe([]);
});

test('2: an extra index on columns no foreign key uses is still reported', function () {
    $discrepancies = impliedIndexDiff(IMPLIED_FK_EXPECTED_DDL, <<<'SQL'
        CREATE TABLE `t` (
          `id` smallint unsigned NOT NULL AUTO_INCREMENT,
          `provinsi_id` tinyint unsigned NOT NULL,
          `kode` char(4) NOT NULL,
          `nama` varchar(100) NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `t_kode_unique` (`kode`),
          KEY `t_provinsi_id_foreign` (`provinsi_id`),
          KEY `idx_nama` (`nama`),
          CONSTRAINT `t_provinsi_id_foreign` FOREIGN KEY (`provinsi_id`) REFERENCES `master_provinsi` (`id`)
        ) ENGINE=InnoDB
        SQL);

    $extra = impliedIndexOfKind($discrepancies, 'extra_index');
    expect($extra->actual)->toContain('nama');
    // The implied one is forgiven; the genuine one is not.
    expect($discrepancies)->toHaveCount(1);
});

test('3: an index that only OVERLAPS a foreign key\'s columns is still reported', function () {
    // A prefix is not an exact ordered column list, so InnoDB would never have
    // created this index on its own: it is a deliberate, unreferenced index.
    $discrepancies = impliedIndexDiff(IMPLIED_FK_EXPECTED_DDL, <<<'SQL'
        CREATE TABLE `t` (
          `id` smallint unsigned NOT NULL AUTO_INCREMENT,
          `provinsi_id` tinyint unsigned NOT NULL,
          `kode` char(4) NOT NULL,
          `nama` varchar(100) NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `t_kode_unique` (`kode`),
          KEY `t_provinsi_id_foreign` (`provinsi_id`),
          KEY `idx_provinsi_nama` (`provinsi_id`, `nama`),
          CONSTRAINT `t_provinsi_id_foreign` FOREIGN KEY (`provinsi_id`) REFERENCES `master_provinsi` (`id`)
        ) ENGINE=InnoDB
        SQL);

    $extra = impliedIndexOfKind($discrepancies, 'extra_index');
    expect($extra->actual)->toContain('(provinsi_id, nama)');
});

test('4: an extra index on a table with NO foreign key is still reported', function () {
    $discrepancies = impliedIndexDiff(
        'CREATE TABLE t (
            id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            kode CHAR(2) NOT NULL UNIQUE,
            nama VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE `t` (
            `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
            `kode` char(2) NOT NULL,
            `nama` varchar(100) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `t_kode_unique` (`kode`),
            KEY `idx_nama` (`nama`)
        ) ENGINE=InnoDB',
    );

    $extra = impliedIndexOfKind($discrepancies, 'extra_index');
    expect($extra->actual)->toContain('nama');
});

test('5: an index the DDL named explicitly is matched by name, never swallowed as implied', function () {
    // The expected side declares the same columns under a name the DDL wrote, so
    // the name is part of the contract. The index is consumed by the expected
    // loop — reported as a rename — and must NOT also appear as extra_index.
    $discrepancies = impliedIndexDiff(
        'CREATE TABLE t (
            id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            provinsi_id TINYINT UNSIGNED NOT NULL,
            kode CHAR(4) NOT NULL UNIQUE,
            nama VARCHAR(100) NOT NULL,
            INDEX idx_kabupaten_provinsi (provinsi_id),
            FOREIGN KEY (provinsi_id) REFERENCES master_provinsi(id)
        ) ENGINE=InnoDB',
        'CREATE TABLE `t` (
            `id` smallint unsigned NOT NULL AUTO_INCREMENT,
            `provinsi_id` tinyint unsigned NOT NULL,
            `kode` char(4) NOT NULL,
            `nama` varchar(100) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `t_kode_unique` (`kode`),
            KEY `t_provinsi_id_foreign` (`provinsi_id`),
            CONSTRAINT `t_provinsi_id_foreign` FOREIGN KEY (`provinsi_id`) REFERENCES `master_provinsi` (`id`)
        ) ENGINE=InnoDB',
    );

    expect(impliedIndexKinds($discrepancies))->toBe(['index_name']);
    expect($discrepancies[0]->expected)->toContain('idx_kabupaten_provinsi');
    expect($discrepancies[0]->actual)->toContain('t_provinsi_id_foreign');
});

test('6: a multi-column foreign key implies only the exact ordered list, so (b, a) is still reported', function () {
    $expected = <<<'SQL'
        CREATE TABLE t (
          a BIGINT UNSIGNED NOT NULL,
          b BIGINT UNSIGNED NOT NULL,
          c BIGINT UNSIGNED NOT NULL,
          PRIMARY KEY (c),
          FOREIGN KEY (a, b) REFERENCES u(x, y)
        ) ENGINE=InnoDB
        SQL;

    $inOrder = impliedIndexDiff($expected, <<<'SQL'
        CREATE TABLE `t` (
          `a` bigint unsigned NOT NULL,
          `b` bigint unsigned NOT NULL,
          `c` bigint unsigned NOT NULL,
          PRIMARY KEY (`c`),
          KEY `t_a_b` (`a`, `b`),
          CONSTRAINT `t_ibfk_1` FOREIGN KEY (`a`, `b`) REFERENCES `u` (`x`, `y`)
        ) ENGINE=InnoDB
        SQL);

    expect(impliedIndexKinds($inOrder))->not->toContain('extra_index');
    expect($inOrder)->toBe([]);

    // Same columns, reversed: an index in this order cannot serve the FK as
    // declared, so InnoDB never makes it and it stays a genuine extra.
    $reversed = impliedIndexDiff($expected, <<<'SQL'
        CREATE TABLE `t` (
          `a` bigint unsigned NOT NULL,
          `b` bigint unsigned NOT NULL,
          `c` bigint unsigned NOT NULL,
          PRIMARY KEY (`c`),
          KEY `t_b_a` (`b`, `a`),
          CONSTRAINT `t_ibfk_1` FOREIGN KEY (`a`, `b`) REFERENCES `u` (`x`, `y`)
        ) ENGINE=InnoDB
        SQL);

    $extra = impliedIndexOfKind($reversed, 'extra_index');
    expect($extra->actual)->toContain('(b, a)');
});

test('an unmatched foreign key does not imply anything: its index is still extra', function () {
    // The FK itself is missing, so the index that would have supported it has no
    // explanation either. Both are reported; the index is not silently forgiven.
    $discrepancies = impliedIndexDiff(
        'CREATE TABLE t (
            id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            provinsi_id TINYINT UNSIGNED NOT NULL,
            kode CHAR(4) NOT NULL UNIQUE,
            nama VARCHAR(100) NOT NULL,
            FOREIGN KEY (provinsi_id) REFERENCES master_provinsi(id)
        ) ENGINE=InnoDB',
        'CREATE TABLE `t` (
            `id` smallint unsigned NOT NULL AUTO_INCREMENT,
            `provinsi_id` tinyint unsigned NOT NULL,
            `kode` char(4) NOT NULL,
            `nama` varchar(100) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `t_kode_unique` (`kode`),
            KEY `t_provinsi_id_foreign` (`provinsi_id`)
        ) ENGINE=InnoDB',
    );

    expect(impliedIndexKinds($discrepancies))->toContain('missing_foreign_key');
    expect(impliedIndexKinds($discrepancies))->toContain('extra_index');
});
