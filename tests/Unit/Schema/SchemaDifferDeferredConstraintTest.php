<?php

use App\Support\Schema\DeferredConstraintRegistry;
use App\Support\Schema\Discrepancy;
use App\Support\Schema\ExtraTableRegistry;
use App\Support\Schema\SchemaDiffer;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The deferred-constraint registry, which is the verifier's only way to say
 * "this constraint is intentionally not here yet".
 *
 * `telemedicine_test.sql` is written as if every table existed at once: section
 * `[14]` (`:1161-1163`) adds `CONSTRAINT fk_vital_rm` to `pasien_tanda_vital`
 * with an `ALTER TABLE`, and `SqlSchemaParser` folds that into the table it
 * targets — so the *expected* model demands the key from the first commit. The
 * live schema cannot hold it until `rekam_medis` exists in batch G, which makes
 * `missing_foreign_key` drift structurally unavoidable without a registry.
 *
 * These tests pin all three rules, and the boundaries that keep them honest:
 *
 *  1. a registered, missing key is **informational** and still named in the report;
 *  2. a registered key that the live schema **has** is **drift** — the loophole
 *     closer, and the reason a registry row cannot outlive its constraint;
 *  3. an unregistered missing key is **drift**, and the exemption is scoped to one
 *     *named* constraint, so it can never excuse a column or a table wholesale.
 *
 * Every fixture here is a **string**. Nothing in this file touches a database: the
 * whole point of rule 2 is a state the live schema cannot reach at this position
 * in the migration order, and building it in a fixture is what lets the rule be
 * tested at all. The mutation is here, in the DDL text, precisely because it must
 * not be in `telemedisin_db`.
 */

/**
 * The reference side, verbatim from `telemedicine_test.sql:312-330` plus the
 * section-`[14]` `ALTER`, with the numeric-vital columns trimmed to keep the
 * fixtures readable. Both sides of every comparison use this same shape, so the
 * column list is not what these tests are about.
 */
const DEFERRAL_EXPECTED_SQL = <<<'SQL'
    CREATE TABLE pasien_tanda_vital (
      id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      pasien_id BIGINT UNSIGNED NOT NULL,
      rekam_medis_id BIGINT UNSIGNED NULL,
      dokter_id BIGINT UNSIGNED NULL,
      diukur_at DATETIME NOT NULL,
      dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (pasien_id) REFERENCES pasien(id) ON DELETE CASCADE,
      INDEX idx_vital_pasien (pasien_id, diukur_at)
    ) ENGINE=InnoDB;

    ALTER TABLE pasien_tanda_vital
      ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)
      REFERENCES rekam_medis(id) ON DELETE SET NULL;
    SQL;

/**
 * The live side as MySQL prints it, from the real `SHOW CREATE TABLE` of
 * `telemedisin_db.pasien_tanda_vital`: server-generated constraint and index
 * names, `DEFAULT NULL` spelled out, and no `fk_vital_rm` — the state at todo 9.
 */
const DEFERRAL_LIVE_SQL = <<<'SQL'
    CREATE TABLE `pasien_tanda_vital` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `pasien_id` bigint unsigned NOT NULL,
      `rekam_medis_id` bigint unsigned DEFAULT NULL,
      `dokter_id` bigint unsigned DEFAULT NULL,
      `diukur_at` datetime NOT NULL,
      `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_vital_pasien` (`pasien_id`,`diukur_at`),
      CONSTRAINT `pasien_tanda_vital_pasien_id_foreign` FOREIGN KEY (`pasien_id`) REFERENCES `pasien` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL;

/**
 * The same live table once migration 76 has run: `fk_vital_rm` present, plus the
 * index InnoDB builds to back it (named after the column, because nothing else
 * covers `rekam_medis_id`). This is the fixture that makes rule 2 reachable.
 */
const DEFERRAL_LIVE_SQL_AFTER_MIGRATION_76 = <<<'SQL'
    CREATE TABLE `pasien_tanda_vital` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `pasien_id` bigint unsigned NOT NULL,
      `rekam_medis_id` bigint unsigned DEFAULT NULL,
      `dokter_id` bigint unsigned DEFAULT NULL,
      `diukur_at` datetime NOT NULL,
      `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_vital_pasien` (`pasien_id`,`diukur_at`),
      KEY `rekam_medis_id` (`rekam_medis_id`),
      CONSTRAINT `pasien_tanda_vital_pasien_id_foreign` FOREIGN KEY (`pasien_id`) REFERENCES `pasien` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_vital_rm` FOREIGN KEY (`rekam_medis_id`) REFERENCES `rekam_medis` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL;

/**
 * The same reference side with a **second** named constraint on the same table, so
 * a registry row can be shown to forgive one of two and not the other. This is the
 * adversarial case for the choice of key: a registry keyed on table + column would
 * excuse both.
 */
const DEFERRAL_EXPECTED_SQL_TWO_KEYS = <<<'SQL'
    CREATE TABLE pasien_tanda_vital (
      id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      pasien_id BIGINT UNSIGNED NOT NULL,
      rekam_medis_id BIGINT UNSIGNED NULL,
      dokter_id BIGINT UNSIGNED NULL,
      diukur_at DATETIME NOT NULL,
      dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (pasien_id) REFERENCES pasien(id) ON DELETE CASCADE,
      INDEX idx_vital_pasien (pasien_id, diukur_at)
    ) ENGINE=InnoDB;

    ALTER TABLE pasien_tanda_vital
      ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)
      REFERENCES rekam_medis(id) ON DELETE SET NULL;

    ALTER TABLE pasien_tanda_vital
      ADD CONSTRAINT fk_vital_dokter FOREIGN KEY (dokter_id)
      REFERENCES faskes(id) ON DELETE SET NULL;
    SQL;

/**
 * @param  array<string, string>  $deferred
 * @return list<Discrepancy>
 */
function deferralDiff(string $liveDdl, array $deferred = [], string $expectedSql = DEFERRAL_EXPECTED_SQL): array
{
    $parser = new SqlSchemaParser;

    // parse() is the whole-parse path, so the section-[14] ALTER is folded into
    // the table exactly as the real reference file folds it.
    $expected = $parser->parse($expectedSql);

    // Keyed by the parsed name, not by a hard-coded one: the fixtures below use
    // more than one table name and a mismatched key would turn a clean diff into
    // a `missing_table` plus an `undocumented_extra_table` for the wrong reason.
    $table = $parser->parseCreateTable($liveDdl, SqlSchemaParser::NAMES_ARE_SERVER_GENERATED);

    $live = new SchemaSpec([$table->name => $table], []);

    return (new SchemaDiffer)->diff($expected, $live, null, [], $deferred);
}

/**
 * @param  list<Discrepancy>  $discrepancies
 */
function deferralRowOfKind(array $discrepancies, string $kind): Discrepancy
{
    $found = array_values(array_filter($discrepancies, static fn (Discrepancy $d): bool => $d->kind === $kind));

    expect($found)->toHaveCount(1, 'expected exactly one '.$kind.' row, got: '
        .implode(', ', array_map(static fn (Discrepancy $d): string => $d->kind, $discrepancies)));

    return $found[0];
}

test('rule 1: a registered, missing constraint is informational and still named in the report', function () {
    $discrepancies = deferralDiff(DEFERRAL_LIVE_SQL, [
        'fk_vital_rm' => 'added by migration 76 per SQL section [14]',
    ]);

    $row = deferralRowOfKind($discrepancies, 'deferred_foreign_key');

    // Informational, not drift: this is the whole point of the registry.
    expect($row->isDrift())->toBeFalse();
    expect($row->table)->toBe('pasien_tanda_vital');

    // Named, so a reader can see the deferral is outstanding rather than
    // silently absent. The label carries the constraint name and the target.
    expect($row->expected)->toContain('fk_vital_rm');
    expect($row->expected)->toContain('rekam_medis');
    expect($row->actual)->toContain('added by migration 76 per SQL section [14]');

    // No `missing_foreign_key` row survives — that is the only thing the registry
    // forgives, and the run now has zero drift.
    expect(array_column($discrepancies, 'kind'))->not->toContain('missing_foreign_key');
    expect(array_values(array_filter($discrepancies, static fn (Discrepancy $d): bool => $d->isDrift())))->toBe([]);
});

test('rule 2: a registered constraint that the live schema HAS is drift — the loophole closer', function () {
    $discrepancies = deferralDiff(DEFERRAL_LIVE_SQL_AFTER_MIGRATION_76, [
        'fk_vital_rm' => 'added by migration 76 per SQL section [14]',
    ]);

    $row = deferralRowOfKind($discrepancies, 'fulfilled_deferred_foreign_key');

    // DRIFT. Without this, a registry row could excuse the constraint forever and
    // the "76 tables, 2 views verified" run would pass with the FK still absent.
    expect($row->isDrift())->toBeTrue();
    expect($row->table)->toBe('pasien_tanda_vital');
    expect($row->expected)->toContain('still registered as deferred');
    expect($row->actual)->toContain('fk_vital_rm');

    // Exactly one discrepancy: the satisfied deferral. In particular the
    // matched key still implies its InnoDB support index, so the registry does
    // not smuggle in a second, unrelated `extra_index`.
    expect($discrepancies)->toHaveCount(1);
    expect(array_column($discrepancies, 'kind'))->not->toContain('extra_index');
    expect(array_column($discrepancies, 'kind'))->not->toContain('missing_foreign_key');
});

test('rule 2 is not satisfiable by dropping the registry: an unregistered present key is clean', function () {
    // The counterpart to rule 2, and the reason rule 2 is a *drift* rather than
    // an informational note: once the row is gone the very same schema is
    // correct, so the row is what is wrong.
    $withRegistry = deferralDiff(DEFERRAL_LIVE_SQL_AFTER_MIGRATION_76, ['fk_vital_rm' => 'stale row']);
    $withoutRegistry = deferralDiff(DEFERRAL_LIVE_SQL_AFTER_MIGRATION_76);

    expect(array_column($withRegistry, 'kind'))->toBe(['fulfilled_deferred_foreign_key']);
    expect($withoutRegistry)->toBe([]);
});

test('rule 3: an unregistered missing constraint stays drift', function () {
    $discrepancies = deferralDiff(DEFERRAL_LIVE_SQL);

    $row = deferralRowOfKind($discrepancies, 'missing_foreign_key');

    expect($row->isDrift())->toBeTrue();
    expect($row->table)->toBe('pasien_tanda_vital');
    expect($row->expected)->toContain('fk_vital_rm');
    expect($row->actual)->toBeNull();
    expect(array_column($discrepancies, 'kind'))->not->toContain('deferred_foreign_key');
});

test('the exemption is scoped to one NAMED constraint: a different missing key on the same table is still drift', function () {
    // A registry keyed on table + column rather than on the constraint name would
    // swallow `fk_vital_dokter` too. This is the load-bearing adversarial case for
    // the key choice: two named constraints on the SAME table, one registered and
    // one not, in the SAME report — the first informational, the second drift. The
    // registry is a per-constraint exemption, never a per-table or per-column one.
    $registeredOnly = deferralDiff(
        DEFERRAL_LIVE_SQL,
        ['fk_vital_rm' => 'added by migration 76 per SQL section [14]'],
        DEFERRAL_EXPECTED_SQL_TWO_KEYS,
    );

    $excused = deferralRowOfKind($registeredOnly, 'deferred_foreign_key');
    $stillDrift = deferralRowOfKind($registeredOnly, 'missing_foreign_key');

    expect($excused->isDrift())->toBeFalse();
    expect($excused->expected)->toContain('fk_vital_rm');
    expect($stillDrift->isDrift())->toBeTrue();
    expect($stillDrift->table)->toBe('pasien_tanda_vital');
    expect($stillDrift->expected)->toContain('fk_vital_dokter');
    expect($stillDrift->expected)->toContain('FOREIGN KEY (dokter_id)');
    expect(array_column($registeredOnly, 'kind'))->toEqualCanonicalizing([
        'deferred_foreign_key',
        'missing_foreign_key',
    ]);

    // And a registry that names the OTHER constraint does not excuse this one —
    // the exemption follows the name, not the table.
    $otherName = deferralDiff(
        DEFERRAL_LIVE_SQL,
        ['fk_vital_dokter' => 'deferred'],
        DEFERRAL_EXPECTED_SQL_TWO_KEYS,
    );

    $excused = deferralRowOfKind($otherName, 'deferred_foreign_key');
    $stillDrift = deferralRowOfKind($otherName, 'missing_foreign_key');

    expect($excused->expected)->toContain('fk_vital_dokter');
    expect($stillDrift->expected)->toContain('fk_vital_rm');
    expect($stillDrift->isDrift())->toBeTrue();
});

test('an engine-named inline foreign key can never be forgiven, even when its generated name is registered', function () {
    // MySQL names an inline `FOREIGN KEY` `<table>_ibfk_<n>`, so the name is not a
    // stable handle and is refused. Only a name the DDL itself wrote is
    // registrable — in the whole 105-key schema that is `fk_vital_rm` alone.
    $expected = <<<'SQL'
        CREATE TABLE t (
          id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
          kode BIGINT UNSIGNED NULL,
          FOREIGN KEY (kode) REFERENCES u(id)
        ) ENGINE=InnoDB;
        SQL;

    $discrepancies = deferralDiff(
        'CREATE TABLE `t` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `kode` bigint unsigned DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB',
        ['t_ibfk_1' => 'guessing the engine-generated name'],
        $expected,
    );

    $row = deferralRowOfKind($discrepancies, 'missing_foreign_key');

    expect($row->isDrift())->toBeTrue();
    expect($row->expected)->toContain('FOREIGN KEY (kode)');

    // The reference side of an inline `FOREIGN KEY` carries no name at all — which
    // is exactly why it cannot be looked up in a registry keyed on name, and why
    // guessing `t_ibfk_1` in the registry forgives nothing.
    expect($row->expected)->not->toContain('t_ibfk_1');
    expect(array_column($discrepancies, 'kind'))->not->toContain('deferred_foreign_key');
});

test('a deferral is matched case-insensitively, like every other name the differ compares', function () {
    $discrepancies = deferralDiff(DEFERRAL_LIVE_SQL, ['FK_VITAL_RM' => 'deferred']);

    expect(array_column($discrepancies, 'kind'))->not->toContain('missing_foreign_key');
    expect(deferralRowOfKind($discrepancies, 'deferred_foreign_key')->isDrift())->toBeFalse();
});

test('the two registries in one notes file never read each other\'s rows', function () {
    $markdown = <<<'MD'
        # Schema notes

        ## Registered extra tables

        | Table | Source migration | Justification |
        | --- | --- | --- |
        | `cache` | `0001_…` | Laravel's cache store. |

        ## Deferred constraints

        | Constraint | Table | Justification |
        | --- | --- | --- |
        | `fk_vital_rm` | `pasien_tanda_vital` | added by migration 76. |

        ## A later section with a backticked first cell

        | Thing | Note |
        | --- | --- |
        | `not_a_registry_row` | this row belongs to neither registry. |
        MD;

    $path = sys_get_temp_dir().'/sehatly-two-registries-'.getmypid().'.md';
    file_put_contents($path, $markdown);

    try {
        $extras = ExtraTableRegistry::fromMarkdown($path);
        $deferred = DeferredConstraintRegistry::fromMarkdown($path);

        // The anti-footgun property: a backticked row in the deferred table is
        // NOT a registered extra table, so it can never forgive a real table
        // that is really drift.
        expect(array_keys($extras))->toBe(['cache']);
        expect($extras)->not->toHaveKey('fk_vital_rm');
        expect($extras)->not->toHaveKey('not_a_registry_row');

        expect(array_keys($deferred))->toBe(['fk_vital_rm']);
        expect($deferred)->not->toHaveKey('cache');
        expect($deferred)->not->toHaveKey('not_a_registry_row');

        expect($extras['cache'])->toBe("Laravel's cache store.");
        expect($deferred['fk_vital_rm'])->toBe('added by migration 76.');
    } finally {
        @unlink($path);
    }
});

test('a section heading is matched case-insensitively and stops at the next heading', function () {
    // Formatting a heading differently must not silently empty a mandatory
    // registry — it must keep working, or fail loudly.
    $markdown = <<<'MD'
        # Schema notes

        ## registered extra tables

        | Table | Justification |
        | --- | --- |
        | `cache` | Laravel's cache store. |

        ### Deferred constraints

        | Constraint | Justification |
        | --- | --- |
        | `fk_vital_rm` | added by migration 76. |

        ## Registered extra tables — retired

        | Table | Justification |
        | --- | --- |
        | `ghost_table` | belongs to a different section. |
        MD;

    $path = sys_get_temp_dir().'/sehatly-heading-tolerance-'.getmypid().'.md';
    file_put_contents($path, $markdown);

    try {
        expect(array_keys(ExtraTableRegistry::fromMarkdown($path)))->toBe(['cache']);
        expect(array_keys(DeferredConstraintRegistry::fromMarkdown($path)))->toBe(['fk_vital_rm']);
    } finally {
        @unlink($path);
    }
});

test('a deferred-constraint registry that is absent, empty or truncated raises rather than forgiving every constraint', function () {
    $missing = sys_get_temp_dir().'/sehatly-no-deferrals-'.getmypid().'.md';
    $noSection = sys_get_temp_dir().'/sehatly-deferrals-no-section-'.getmypid().'.md';
    $empty = sys_get_temp_dir().'/sehatly-empty-deferrals-'.getmypid().'.md';

    file_put_contents($noSection, "# Schema notes\n\n## Registered extra tables\n\n| Table | Justification |\n| --- | --- |\n| `cache` | x |\n");
    file_put_contents($empty, "# Schema notes\n\n## Deferred constraints\n\nNothing deferred yet.\n");

    try {
        // A registry that is not there at all, and one that parses to nothing, are
        // both hard errors. Neither may degrade into an allow-list that forgives
        // every missing constraint: a run that cannot understand its inputs must
        // never look green.
        $cases = [
            $missing => 'The deferred-constraint registry is mandatory but was not found',
            $noSection => 'No deferred constraints were found',
            $empty => 'No deferred constraints were found',
        ];

        foreach ($cases as $path => $fragment) {
            try {
                DeferredConstraintRegistry::fromMarkdown($path);
                $this->fail(basename($path).' must not be accepted as a registry.');
            } catch (RuntimeException $e) {
                expect($e->getMessage())->toContain($fragment);
                expect($e->getMessage())->toContain($path);
            }
        }

        // Actionable: the message names the heading the parser is looking for.
        try {
            DeferredConstraintRegistry::fromMarkdown($empty);
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('## '.DeferredConstraintRegistry::HEADING);
            expect($e->getMessage())->toContain('backticked constraint name');
        }

        // And the other direction: a notes file with deferrals but no extra-table
        // table is still an error, and the message says which registry it was.
        $noExtras = sys_get_temp_dir().'/sehatly-no-extras-'.getmypid().'.md';
        file_put_contents($noExtras, "# Schema notes\n\n## Deferred constraints\n\n| Constraint | Justification |\n| --- | --- |\n| `fk_vital_rm` | x |\n");

        try {
            ExtraTableRegistry::fromMarkdown($noExtras);
            $this->fail('A notes file with no extra-table table must not be accepted.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('No registered extra tables');
        } finally {
            @unlink($noExtras);
        }
    } finally {
        @unlink($missing);
        @unlink($noSection);
        @unlink($empty);
    }
});

test('every registered deferral carries a justification, and a blank one is called out', function () {
    $path = sys_get_temp_dir().'/sehatly-blank-deferral-'.getmypid().'.md';

    $written = file_put_contents(
        $path,
        "# Schema notes\n\n## Deferred constraints\n\n"
            ."| Constraint | Table | Justification |\n| --- | --- | --- |\n"
            .'| `fk_vital_rm` | `pasien_tanda_vital` |  |'."\n",
    );

    try {
        // Assert the fixture landed before asserting on the parse, so a truncated
        // or locked temp file can never be mistaken for a registry defect.
        expect($written)->toBeGreaterThan(0, 'could not write the fixture at '.$path);

        expect(DeferredConstraintRegistry::fromMarkdown($path))
            ->toBe(['fk_vital_rm' => 'registered without a justification']);
    } finally {
        @unlink($path);
    }
});
