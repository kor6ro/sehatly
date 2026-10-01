<?php

use App\Support\Schema\DeferredConstraintRegistry;
use App\Support\Schema\ExtraTableRegistry;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The deferred-constraint registry, as the **command** exercised it, end to end,
 * against the live schema.
 *
 * ## RE-SCOPED in todo 18, deliberately. Read this before "simplifying" it.
 *
 * This file previously held eight tests whose shared premise was that **a
 * pending deferral keeps the full run failing**. Todo 18 resolved the last
 * deferral - migration `2026_10_01_000076` adds `fk_vital_rm` from SQL section
 * `[14]` - and the full run is now expected to be completely clean. That makes
 * six of the eight tests **void rather than redundant**: they asserted
 * `Discrepancies: 1 (0 drift, 1 informational)`, `exit 2` on an absent
 * registry, or `exit 1` on the full run, and every one of those was a statement
 * about a world that no longer exists. Deleting the assertions to make them pass
 * would have hidden the transition; leaving them would have shipped a red suite.
 *
 * **So the file was re-scoped, not deleted and not gutted.** The coverage that
 * was real and still applies is kept, and the single most valuable property in
 * the original file is now asserted *more* strongly than before.
 *
 * ### What was void, and why
 *
 * | Original test | Premise | Disposition |
 * | --- | --- | --- |
 * | `rule 1: the batch-C scope exits 0 with the deferral informational and named` | the deferral is reported by name as informational | **void** - nothing is deferred, so there is no `deferred_foreign_key` row to name |
 * | `rule 3: with the deferral renamed the drift comes straight back` | renaming the registry row restores drift | **void** - the row no longer exists to rename, and `fromMarkdown()` now throws |
 * | `a notes file whose deferred section is gone exits 2` | an absent section is a hard error | **inverted** - the real notes file HAS no such section and the command no longer reads one; this shape is now the normal state |
 * | `a deferred section with no usable rows exits 2 too` | an empty section is a hard error | **inverted**, same reason |
 * | `the JSON report counts the registry and marks the deferral informational` | the report counts registered deferrals | **void** - the report has no such field any more |
 * | `the full run still fails, and the deferral is the only thing that left the drift set` | `exit 1` with a non-empty drift set | **void** - the full run is exit 0 with zero drift. This is the assertion plan appendix A.24 specifically flagged |
 *
 * ### What survived, and why
 *
 * Three things were real and are still real, so they are asserted here in
 * re-scoped form:
 *
 *  1. **a registry row naming a constraint the reference DDL never wrote must
 *     forgive nothing** - this was the original file's best property (its
 *     rule-3 test proved the registry was the *only* thing excusing the drift)
 *     and it survives the deferral being resolved, because a name the DDL never
 *     wrote matches no missing constraint. It is now the load-bearing test.
 *  2. **the DDL authors exactly one foreign-key name**, `fk_vital_rm`, and it is
 *     live. That is the fact the retirement turned on.
 *  3. **`pasien_penjamin.faskes_rujukan_id` has no foreign key in the DDL and none
 *     live.** Still the standing prohibition on migration 76, and still
 *     unconstrained now that `faskes` exists.
 *
 * `SchemaDifferDeferredConstraintTest` (fixtures, no database) was NOT touched:
 * it pins the three rules of the differ itself, and the differ still accepts a
 * `$deferredConstraints` array, so those rules are still live code.
 *
 * ### A note on the A.7 traps, because every assertion here is shaped around them
 *
 * In `--tables=` mode the PASS banner is formatted from the **full** reference
 * model, so it prints "76 tables, 2 views verified" after checking eight; and a
 * table name the DDL does not define exits 0 as `unknown_requested_table`. So the
 * echoed `scope` line and the `Discrepancies:` line are asserted, never the
 * banner and never the exit code on its own.
 */

/**
 * Batch C - the eight tables todo 9 owns, all of which exist in the live schema.
 * Spelled out rather than derived, because the point of the assertions is that
 * this exact scope reaches the constraint in question and nothing else.
 *
 * Renamed from `DEFERRED_BATCH_C_SCOPE` in todo 18: nothing in this scope is
 * deferred any more.
 */
const BATCH_C_SCOPE = [
    'pasien',
    'pasien_anggota_keluarga',
    'pasien_alergi',
    'pasien_riwayat_penyakit',
    'pasien_imunisasi',
    'pasien_tanda_vital',
    'master_penjamin',
    'pasien_penjamin',
];

test('fk_vital_rm is the only foreign-key name the DDL authors, and it is now live', function () {
    // DERIVED from `telemedicine_test.sql`, not hard-coded. The whole schema has
    // exactly one DDL-authored foreign-key name, because every other FK is inline
    // and therefore engine-named `<table>_ibfk_<n>`. That is why the retired
    // registry could key on a name at all, and why its blast radius was a single
    // constraint.
    $contract = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $authored = [];

    foreach ($contract->tables as $table) {
        foreach ($table->foreignKeys as $key) {
            if ($key->nameIsAuthoritative && $key->name !== null) {
                $authored[strtolower($key->name)] = $table->name;
            }
        }
    }

    expect(array_keys($authored))->toBe(['fk_vital_rm']);
    expect($authored['fk_vital_rm'])->toBe('pasien_tanda_vital');

    // The deferral is resolved: the constraint is in the live schema, on the
    // column the DDL declares it on, with the delete rule the DDL writes.
    $rows = DB::select(
        'select rc.DELETE_RULE as delete_rule, kcu.COLUMN_NAME as column_name,'
        .' kcu.REFERENCED_TABLE_NAME as referenced_table, kcu.REFERENCED_COLUMN_NAME as referenced_column'
        .' from information_schema.REFERENTIAL_CONSTRAINTS rc'
        .' join information_schema.KEY_COLUMN_USAGE kcu'
        .'   on kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA'
        .'  and kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME'
        .' where rc.CONSTRAINT_SCHEMA = ? and rc.CONSTRAINT_NAME = ?',
        [DB::connection()->getDatabaseName(), 'fk_vital_rm'],
    );

    expect($rows)->toHaveCount(1);
    expect($rows[0]->column_name)->toBe('rekam_medis_id');
    expect($rows[0]->referenced_table)->toBe('rekam_medis');
    expect($rows[0]->referenced_column)->toBe('id');

    // `ON DELETE SET NULL`, read from the DDL at :1163 and not from any prose
    // about it. `RESTRICT` here would be drift: the reference model compares
    // `ON DELETE` behaviour.
    expect($rows[0]->delete_rule)->toBe('SET NULL');
});

test('a registry row naming a constraint the DDL never wrote forgives nothing', function () {
    // THE replacement for the three void registry tests, and the assertion that
    // carries the coverage the original file existed to provide.
    //
    // The property is stronger now than it was. While the deferral was pending,
    // the proof was "rename the row and the drift comes back", which needed a
    // real missing constraint to be hiding behind. With the deferral resolved
    // there is nothing left to hide, so the proof is inverted: plant a row naming
    // a constraint the DDL never wrote and show the run is UNCHANGED - still
    // exit 0, still zero drift, still exactly the registered extras. A registry
    // that forgives a name the contract does not contain would be an
    // allow-list for invented constraints; this shows it forgives nothing at all.
    $real = (string) file_get_contents(base_path('docs/schema-notes.md'));

    $probe = $real."\n## Deferred constraints\n\n"
        ."| Constraint | Table | Added by | Justification |\n"
        ."| --- | --- | --- | --- |\n"
        ."| `fk_this_constraint_does_not_exist` | `pasien_tanda_vital` | `none` | A name the reference DDL never writes, registered to see whether it forgives anything. |\n";

    // The extra-table registry must be untouched, so a change in the result can
    // only be attributable to the planted row.
    $realPath = sys_get_temp_dir().'/sehatly-lying-registry-baseline-'.getmypid().'.md';
    file_put_contents($realPath, $real);

    $path = sys_get_temp_dir().'/sehatly-lying-registry-'.getmypid().'.md';
    file_put_contents($path, $probe);

    try {
        expect(ExtraTableRegistry::fromMarkdown($realPath))->not->toBe([]);
        expect(ExtraTableRegistry::fromMarkdown($path))
            ->toEqual(ExtraTableRegistry::fromMarkdown($realPath));

        $baselineExit = Artisan::call('sehatly:verify-schema', ['--json' => true]);
        $baseline = json_decode(Artisan::output(), true);

        $exitCode = Artisan::call('sehatly:verify-schema', ['--json' => true, '--notes' => $path]);
        $json = json_decode(Artisan::output(), true);

        // Identical verdict, identical accounting. Nothing was forgiven, and
        // nothing was manufactured.
        expect($exitCode)->toBe($baselineExit);
        expect($exitCode)->toBe(0);
        expect($json['ok'])->toBeTrue();
        expect($json['drift_count'])->toBe(0);
        expect($json['discrepancy_count'])->toBe($baseline['discrepancy_count']);
        expect($json['notes_registry']['registered_extra_tables'])
            ->toBe($baseline['notes_registry']['registered_extra_tables']);

        // And specifically: no row of either deferral kind appeared.
        $kinds = array_column($json['discrepancies'], 'kind');

        expect($kinds)->not->toContain('deferred_foreign_key');
        expect($kinds)->not->toContain('fulfilled_deferred_foreign_key');
        expect($kinds)->not->toContain('missing_foreign_key');
        expect($kinds)->toContain('documented_extra_table');
    } finally {
        @unlink($path);
        @unlink($realPath);
    }
});

test('the deferred-constraint registry is retired in every place it was wired in', function () {
    // The retirement was FOUR edits in four files, and three of the four are
    // individually sufficient to break the run in a different way. This test is
    // the tripwire for the fourth, which no automated check would otherwise
    // catch: a half-finished retirement that silently weakened the class into
    // returning an empty array instead of raising.
    $notes = (string) file_get_contents(base_path('docs/schema-notes.md'));

    // (a) the section is gone from the notes file...
    expect($notes)->not->toMatch('/^#{1,6}\s+'.preg_quote(DeferredConstraintRegistry::HEADING, '/').'\s*$/m');
    expect($notes)->not->toMatch('/^\|\s*`fk_vital_rm`\s*\|/m');

    // ...and the extra-table registry still parses, so the file is not merely
    // empty and the assertion above is not passing for a trivial reason.
    // (The argument is a PATH, not the markdown - `fromMarkdown()` reads the file.)
    expect(ExtraTableRegistry::fromMarkdown(base_path('docs/schema-notes.md')))->not->toBe([]);

    // (b) the command no longer CALLS it. This is checked against the source with
    // its comments stripped, by `token_get_all` - the same technique
    // `docs/schema-notes.md` records for counting executable statements, and for
    // the same reason: `VerifySchemaParity` explains in a comment exactly which
    // call was removed and why, so a naive `str_contains` over the raw file would
    // match that prose and fail here forever. A source-level tripwire that cannot
    // distinguish a call from a sentence about a call is worse than none.
    $commandPath = app_path('Console/Commands/VerifySchemaParity.php');
    $executable = '';

    foreach (token_get_all((string) file_get_contents($commandPath)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $executable .= $token[1];

            continue;
        }

        $executable .= $token;
    }

    expect($executable)->not->toContain('DeferredConstraintRegistry::fromMarkdown');
    expect($executable)->not->toContain('registered_deferred_constraints');
    expect($executable)->not->toContain('$deferrals');

    // The import is gone too, so the class is unreachable rather than merely
    // uncalled.
    expect($executable)->not->toContain('use App\Support\Schema\DeferredConstraintRegistry');

    // And the class still RAISES on the real notes file, rather than having been
    // softened into a silent empty array. A class that returned [] would let a
    // reinstated call pass unnoticed, which is the failure mode this assertion
    // exists to prevent.
    try {
        DeferredConstraintRegistry::fromMarkdown(base_path('docs/schema-notes.md'));
        $this->fail('The retired registry must still raise on a notes file with no deferred section.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('No deferred constraints were found');
    }
});

test('the full run is clean, and every informational row is a registered extra', function () {
    // THE replacement for `the full run still fails`. Todo 18 resolves the last
    // deferral and adds both views, so the correct end state is exit 0 and zero
    // drift. The original assertion - non-empty drift set, no row from either
    // registry - was true only while the schema was incomplete.
    $exitCode = Artisan::call('sehatly:verify-schema', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0);
    expect($json['ok'])->toBeTrue();
    expect($json['drift_count'])->toBe(0);

    // `discrepancy_count` is **not** 0 and never will be while any registered
    // extra table exists: the extras are reported as informational rather than
    // suppressed, which is the whole point of the registry. The invariant is
    // that every informational row IS a registered extra - no deferrals, and
    // nothing else slipping through as "informational".
    //
    // The COUNT is deliberately not a literal. It was `toHaveCount(7)` and went
    // red when `sessions` was registered as the eighth framework extra - a red
    // suite for a change that is exactly what the registry exists to absorb.
    // What matters is the agreement asserted below: the registry, the
    // informational row count and the discrepancy count name the same set.
    $registered = array_keys(ExtraTableRegistry::fromMarkdown(base_path('docs/schema-notes.md')));

    expect($registered)->not->toBeEmpty();
    expect($json['notes_registry']['registered_extra_tables'])->toBe(count($registered));
    expect($json['discrepancy_count'])->toBe(count($registered));
    expect($json['discrepancy_count'] - $json['drift_count'])->toBe(count($registered));

    foreach ($json['discrepancies'] as $row) {
        expect($row['kind'])->toBe('documented_extra_table');
        expect($row['drift'])->toBeFalse();
        expect($registered)->toContain($row['table']);
    }

    // And no contract object is missing at all: this is todo 18's actual
    // deliverable, stated positively rather than as the absence of a failure.
    $kinds = array_column($json['discrepancies'], 'kind');

    expect($kinds)->not->toContain('missing_table');
    expect($kinds)->not->toContain('missing_view');
    expect($kinds)->not->toContain('missing_column');
    expect($kinds)->not->toContain('missing_foreign_key');
    expect($kinds)->not->toContain('missing_index');
    expect($kinds)->not->toContain('missing_check');

    expect($json['expected']['tables'])->toBe(76);
    expect($json['expected']['views'])->toBe(2);
    expect($json['live_model']['views'])->toBe(2);
});

test('pasien_penjamin.faskes_rujukan_id has no foreign key in the DDL and none live', function () {
    // The DDL declares no FK on this column, so there was nothing to defer and
    // nothing for migration 76 to add. An earlier revision of
    // docs/schema-notes.md called it a deferred "FK to `faskes`"; that prose was
    // wrong (plan appendices A.10 / A.11) and the claim survived in three
    // separate files before it was killed. Asserted here so nobody re-adds it on
    // the strength of the old sentence.
    //
    // NOTE: this test no longer calls `DeferredConstraintRegistry::fromMarkdown()`.
    // It used to, to assert the column was not registered as deferred - which
    // would now THROW, because the registry raises on the retired notes file.
    // That assertion is replaced by the retirement test above, which proves the
    // registry is not consulted at all; a registry that is never consulted cannot
    // be promising this constraint.
    $contract = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $expected = $contract->table('pasien_penjamin');

    $onRujukan = array_values(array_filter(
        $expected->foreignKeys,
        static fn ($key): bool => in_array('faskes_rujukan_id', $key->columns, true),
    ));

    expect($onRujukan)->toBe([]);

    // The two foreign keys the statement *does* declare, so the assertion above
    // is not passing because the parser found none at all.
    expect($expected->foreignKeys)->toHaveCount(2);
    expect($expected->foreignKeys[0]->columns)->toBe(['pasien_id']);
    expect($expected->foreignKeys[1]->columns)->toBe(['penjamin_id']);

    // And the live table agrees: no row in information_schema either.
    $rows = DB::select(
        'select CONSTRAINT_NAME from information_schema.KEY_COLUMN_USAGE'
        .' where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ? and REFERENCED_TABLE_NAME is not null',
        [DB::connection()->getDatabaseName(), 'pasien_penjamin', 'faskes_rujukan_id'],
    );

    expect($rows)->toBe([]);

    // `faskes` exists, so a constraint here would be trivially addable - which is
    // exactly why the prohibition needs to be stated rather than assumed.
    expect(DB::select(
        'select TABLE_NAME from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_NAME = ?',
        [DB::connection()->getDatabaseName(), 'faskes'],
    ))->not->toBe([]);
});
