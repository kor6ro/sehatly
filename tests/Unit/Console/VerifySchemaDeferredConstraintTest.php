<?php

use App\Support\Schema\DeferredConstraintRegistry;
use App\Support\Schema\ExtraTableRegistry;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The deferred-constraint registry as the **command** exercises it, end to end,
 * against the live schema.
 *
 * `SchemaDifferDeferredConstraintTest` pins the three rules on fixtures. This file
 * pins the two things only a real run can show: that the registry is what is
 * excusing `fk_vital_rm` (remove it and the drift must come back), and that a
 * registry the verifier cannot use fails the run loudly instead of passing.
 *
 * A note on the A.7 traps, because every assertion here is shaped around them:
 * in `--tables=` mode the PASS banner is formatted from the **full** reference
 * model, so it prints "75 tables, 2 views verified" after checking eight; and a
 * table name the DDL does not define exits 0 as `unknown_requested_table`. So the
 * echoed `scope` line and the `Discrepancies:` line are asserted, never the
 * banner and never the exit code on its own.
 */

/**
 * Batch C — the eight tables todo 9 owns, all of which exist in the live schema.
 * Spelled out rather than derived, because the point of the assertions is that
 * this exact scope reaches the deferred row and nothing else.
 */
const DEFERRED_BATCH_C_SCOPE = [
    'pasien',
    'pasien_anggota_keluarga',
    'pasien_alergi',
    'pasien_riwayat_penyakit',
    'pasien_imunisasi',
    'pasien_tanda_vital',
    'master_penjamin',
    'pasien_penjamin',
];

/**
 * A copy of `docs/schema-notes.md` outside the repository, with every registered
 * deferral renamed. The registry stays present and parseable, so the ONLY thing
 * that differs from a passing run is which constraint name it names — which is
 * what makes the drift's return attributable to the registry rather than to any
 * other edit.
 *
 * @return string the path
 */
function deferralProbeNotes(string $markdown, string $from, string $to): string
{
    $rewritten = preg_replace('/^\|\s*`'.preg_quote($from, '/').'`\s*\|/m', '| `'.$to.'` |', $markdown, -1, $count);

    // A silent no-op here would make every falsification below pass for the wrong
    // reason — the run would be using the real registry. Refuse instead.
    expect($count)->toBeGreaterThan(0, 'no row for '.$from.' was found in the notes file, so the probe would prove nothing');
    expect($rewritten)->not->toBe($markdown);

    $path = sys_get_temp_dir().'/sehatly-deferral-probe-'.getmypid().'.md';
    file_put_contents($path, (string) $rewritten);

    return $path;
}

test('rule 1: the batch-C scope exits 0 with the deferral informational and named', function () {
    $exitCode = Artisan::call('sehatly:verify-schema', ['--tables' => implode(',', DEFERRED_BATCH_C_SCOPE)]);
    $output = Artisan::output();

    // A.7: the banner is formatted from the full reference model, so it is NOT
    // evidence of scope. The echoed scope line and the Discrepancies line are.
    expect($output)->toMatch('/scope\s+'.preg_quote(implode(', ', DEFERRED_BATCH_C_SCOPE), '/').'\b/');

    foreach (DEFERRED_BATCH_C_SCOPE as $table) {
        expect($output)->not->toContain('unknown_requested_table '.$table);
    }

    // The deferral is reported, by name, as informational — never as
    // `missing_foreign_key`, and never silently dropped.
    expect($output)->toContain('deferred_foreign_key');
    expect($output)->toMatch('/deferred_foreign_key\s+pasien_tanda_vital\b/');
    expect($output)->toContain('fk_vital_rm');
    expect($output)->not->toContain('missing_foreign_key');
    expect($output)->toMatch('/Discrepancies:\s+1 \(0 drift, 1 informational\)/');

    // Todo 18 is the point at which migration 76 adds the constraint, and rule 2
    // then turns the still-registered row into drift. Both shapes are asserted
    // explicitly so neither needs editing when it happens.
    if (str_contains($output, 'fulfilled_deferred_foreign_key')) {
        expect($exitCode)->toBe(1);
        expect($output)->toMatch('/Discrepancies:\s+1 \(1 drift, 0 informational\)/');
        expect($output)->toContain('still registered as deferred');

        return;
    }

    expect($exitCode)->toBe(0);
    expect($output)->toContain('PASS');
});

test('rule 3: with the deferral renamed the drift comes straight back — the registry is what was excusing it', function () {
    $registry = DeferredConstraintRegistry::fromMarkdown(base_path('docs/schema-notes.md'));
    $real = (string) array_key_first($registry);

    $path = deferralProbeNotes(
        (string) file_get_contents(base_path('docs/schema-notes.md')),
        $real,
        'fk_probe_not_the_deferred_one',
    );

    try {
        $exitCode = Artisan::call('sehatly:verify-schema', [
            '--tables' => implode(',', DEFERRED_BATCH_C_SCOPE),
            '--notes' => $path,
        ]);
        $output = Artisan::output();

        // Back to drift, naming the constraint the registry no longer covers.
        expect($exitCode)->toBe(1);
        expect($output)->toContain('missing_foreign_key');
        expect($output)->toContain($real);
        expect($output)->not->toContain('deferred_foreign_key');
        expect($output)->toMatch('/Discrepancies:\s+1 \(1 drift, 0 informational\)/');
    } finally {
        @unlink($path);
    }
});

test('a notes file whose deferred section is gone exits 2, never a silent pass', function () {
    $markdown = (string) file_get_contents(base_path('docs/schema-notes.md'));

    // Drop the heading and everything under it, keeping the extra-table table so
    // the failure can only be about the deferred registry.
    $kept = [];
    $skip = false;

    foreach (preg_split("/\r\n|\n|\r/", $markdown) ?: [] as $line) {
        if (preg_match('/^\s{0,3}#{1,6}\s+/', $line) === 1) {
            $skip = str_contains($line, DeferredConstraintRegistry::HEADING);
        }

        if (! $skip) {
            $kept[] = $line;
        }
    }

    $path = sys_get_temp_dir().'/sehatly-deferrals-absent-'.getmypid().'.md';
    file_put_contents($path, implode("\n", $kept));

    try {
        // The heading and its row must be gone. The *phrase* survives elsewhere in
        // the prose (other sections cross-reference it by name), so this asserts
        // the heading line and the table row specifically.
        expect((string) file_get_contents($path))
            ->not->toMatch('/^#{1,6}\s+'.preg_quote(DeferredConstraintRegistry::HEADING, '/').'\s*$/m');
        expect((string) file_get_contents($path))->not->toMatch('/^\|\s*`fk_vital_rm`\s*\|/m');
        expect(ExtraTableRegistry::fromMarkdown($path))->not->toBe([]);

        $exitCode = Artisan::call('sehatly:verify-schema', [
            '--tables' => implode(',', DEFERRED_BATCH_C_SCOPE),
            '--notes' => $path,
        ]);

        // Read the buffer once: a second Artisan::output() returns ''.
        $output = Artisan::output();

        expect($exitCode)->toBe(2);
        expect($output)->toContain('could not run');
        expect($output)->toContain('No deferred constraints were found');
    } finally {
        @unlink($path);
    }
});

test('a deferred section with no usable rows exits 2 too', function () {
    // A section that is present but empty is the shape a careless "trim the
    // registry" leaves behind, and it must not read as "nothing is deferred".
    $markdown = (string) file_get_contents(base_path('docs/schema-notes.md'));
    $stripped = preg_replace('/^## '.preg_quote(DeferredConstraintRegistry::HEADING, '/').'\b.*?(?=^## )/ms', '', $markdown);

    $path = sys_get_temp_dir().'/sehatly-deferrals-trimmed-'.getmypid().'.md';
    file_put_contents($path, (string) $stripped);

    try {
        $exitCode = Artisan::call('sehatly:verify-schema', [
            '--tables' => implode(',', DEFERRED_BATCH_C_SCOPE),
            '--notes' => $path,
        ]);

        expect($exitCode)->toBe(2);
        expect(Artisan::output())->toContain('could not run');
    } finally {
        @unlink($path);
    }
});

test('the JSON report counts the registry and marks the deferral informational', function () {
    $registry = ExtraTableRegistry::fromMarkdown(base_path('docs/schema-notes.md'));
    $deferrals = DeferredConstraintRegistry::fromMarkdown(base_path('docs/schema-notes.md'));

    $exitCode = Artisan::call('sehatly:verify-schema', [
        '--tables' => implode(',', DEFERRED_BATCH_C_SCOPE),
        '--json' => true,
    ]);
    $json = json_decode(Artisan::output(), true);

    expect($json['notes_registry']['registered_extra_tables'])->toBe(count($registry));
    expect($json['notes_registry']['registered_deferred_constraints'])->toBe(count($deferrals));

    $rows = array_values(array_filter($json['discrepancies'], fn (array $d): bool => $d['kind'] === 'deferred_foreign_key'));

    if ($rows === []) {
        // Todo 18: the constraint exists, so rule 2 owns the row instead.
        $rows = array_values(array_filter($json['discrepancies'], fn (array $d): bool => $d['kind'] === 'fulfilled_deferred_foreign_key'));

        expect($rows)->not->toBe([]);
        expect($exitCode)->toBe(1);

        return;
    }

    foreach ($rows as $row) {
        expect($row['drift'])->toBeFalse();
        expect($row['table'])->toBe('pasien_tanda_vital');
        expect($row['expected'])->toContain('fk_vital_rm');
    }

    expect($exitCode)->toBe(0);
    expect($json['ok'])->toBeTrue();
    expect($json['drift_count'])->toBe(0);
});

test('the full run still fails, and the deferral is the only thing that left the drift set', function () {
    $exitCode = Artisan::call('sehatly:verify-schema', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    // The global signal is untouched: the schema is still incomplete.
    expect($exitCode)->toBe(1);
    expect($json['ok'])->toBeFalse();
    expect($json['drift_count'])->toBeGreaterThan(0);

    $byKind = array_count_values(array_column($json['discrepancies'], 'kind'));

    // Every genuinely-missing object is still drift. The registry forgives a
    // missing FOREIGN KEY and nothing else.
    expect($byKind['missing_table'] ?? 0)->toBeGreaterThan(0);

    foreach ($json['discrepancies'] as $row) {
        if ($row['kind'] === 'deferred_foreign_key') {
            expect($row['drift'])->toBeFalse();
        }
    }

    // And no constraint the DDL declares is quietly missing while the registry is
    // in place: the only missing_foreign_key that may appear is one the registry
    // does not cover, and there must be none of those.
    expect($byKind['missing_foreign_key'] ?? 0)->toBe(0);

    // Informational rows are the two registries, and nothing else.
    expect($json['discrepancy_count'] - $json['drift_count'])
        ->toBe($json['notes_registry']['registered_extra_tables'] + $json['notes_registry']['registered_deferred_constraints']);
});

test('every registered deferral names a constraint the reference DDL itself wrote', function () {
    // DERIVED from `telemedicine_test.sql`, not hard-coded: a registry row for a
    // name the DDL never wrote forgives nothing and is a lie in the notes file.
    $deferrals = DeferredConstraintRegistry::fromMarkdown(base_path('docs/schema-notes.md'));
    $contract = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $authored = [];

    foreach ($contract->tables as $table) {
        foreach ($table->foreignKeys as $key) {
            if ($key->nameIsAuthoritative && $key->name !== null) {
                $authored[strtolower($key->name)] = $table->name;
            }
        }
    }

    // The whole schema has exactly one DDL-authored foreign-key name, so the
    // registry's blast radius is exactly one constraint and cannot grow by
    // accident: a second row would have nothing to defer.
    expect(array_keys($authored))->toBe(['fk_vital_rm']);
    expect(array_keys($deferrals))->toBe(array_keys($authored));

    foreach ($deferrals as $name => $justification) {
        expect($justification)->not->toBe('');
        expect($justification)->not->toBe('registered without a justification');
        expect($authored[$name] ?? null)->toBe('pasien_tanda_vital');
    }
});

test('pasien_penjamin.faskes_rujukan_id is NOT deferred and has no live foreign key', function () {
    // The DDL declares no FK on this column, so there is nothing to defer. An
    // earlier revision of docs/schema-notes.md called it a deferred "FK to
    // `faskes`"; that prose was wrong, and registering it would promise migration
    // 76 a constraint the contract never asks for. Asserted here so nobody
    // re-adds it on the strength of the old sentence.
    $deferrals = DeferredConstraintRegistry::fromMarkdown(base_path('docs/schema-notes.md'));

    expect(array_keys($deferrals))->not->toContain('pasien_penjamin_faskes_rujukan_id_foreign');

    $contract = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $expected = $contract->table('pasien_penjamin');

    $onRujukan = array_values(array_filter(
        $expected->foreignKeys,
        static fn ($key): bool => in_array('faskes_rujukan_id', $key->columns, true),
    ));

    expect($onRujukan)->toBe([]);

    // And the live table agrees: no row in information_schema either.
    $rows = DB::select(
        'select CONSTRAINT_NAME from information_schema.KEY_COLUMN_USAGE'
        .' where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ? and REFERENCED_TABLE_NAME is not null',
        [DB::connection()->getDatabaseName(), 'pasien_penjamin', 'faskes_rujukan_id'],
    );

    expect($rows)->toBe([]);
});
