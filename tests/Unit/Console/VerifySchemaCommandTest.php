<?php

use App\Support\Schema\ExtraTableRegistry;
use App\Support\Schema\LiveSchemaReader;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The command's contract: read-only, exit 1 on drift naming the offender, exit 0
 * only on an exact match, and never green when it could not understand its inputs.
 */
test('the verifier exits 1 and names every table the migrations have not created yet', function () {
    $exitCode = Artisan::call('sehatly:verify-schema');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('missing_table');
    expect($output)->toContain('FAIL');
    expect($output)->toContain('read-only');

    // Every missing table is named, not merely counted. This is the plan's
    // criterion 2: "it exits 1 and names the offending table/column".
    //
    // Batch-B state (todo 8): 19 of the 75 tables now exist in
    // `telemedisin_db_test` (11 batch-A masters + 8 batch-B tables), so the
    // named tables must all be ones later batches still own — `master_provinsi`
    // and `users` are present and must NOT appear here. Re-point this list
    // again when todos 9-17 land their tables.
    foreach (['pasien', 'booking', 'ulasan_dokter', 'persetujuan_pdp', 'audit_log'] as $table) {
        expect($output)->toMatch('/missing_table\s+'.$table.'\b/', 'the report must name '.$table);
    }

    expect(substr_count($output, 'missing_table'))->toBe(56); // 75 - 19 created
    expect($output)->toMatch('/missing_view\s+v_dokter_katalog\b/');
    expect($output)->toMatch('/missing_view\s+v_pendapatan_bulanan\b/');
});

test('the JSON report is machine-readable, and its exit code matches its verdict', function () {
    $exitCode = Artisan::call('sehatly:verify-schema', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1);
    expect($json)->toBeArray();
    expect($json['ok'])->toBeFalse();
    expect($json['exit_code'])->toBe($exitCode);
    expect($json['read_only'])->toBeTrue();
    expect($json['expected']['tables'])->toBe(75);
    expect($json['expected']['views'])->toBe(2);
    expect($json['expected']['columns'])->toBeGreaterThan(600);
    expect($json['live']['database'])->toBe('telemedisin_db_test');
    expect($json['reference']['md5'])->toBe(md5_file(base_path('telemedicine_test.sql')));
    expect($json['drift_count'])->toBeGreaterThan(0);

    // Batch-B state (todo 8): the seven documented extra tables
    // (`docs/schema-notes.md`) are present in `telemedisin_db_test` and read as
    // informational, so drift and discrepancy totals legitimately differ — by
    // exactly the registry size. On a 0-table database both totals agreed and
    // this line read `toBe($json['discrepancy_count'])`; that form can never
    // pass again while the extras exist, so pin the decomposition instead of
    // dropping the check.
    expect($json['discrepancy_count'] - $json['drift_count'])->toBe(7);

    $missing = array_values(array_filter($json['discrepancies'], fn ($d) => $d['kind'] === 'missing_table'));
    expect($missing)->toHaveCount(56); // 75 - 19 created (11 batch A + 8 batch B)
    expect(array_column($missing, 'table'))
        ->toContain('pasien', 'booking', 'ulasan_dokter', 'persetujuan_pdp');

    // Proof the parser is not vacuous, carried in the machine-readable channel too.
    expect($json['multi_line_column_declarations'])->toHaveCount(11);
    expect($json['expected'])->toMatchArray(['foreign_keys' => 105, 'checks' => 3]);
});

test('the scope can be narrowed to a single table, and the narrow run still names the offender', function () {
    // Batch-B state (todo 8): `master_provinsi` exists in `telemedisin_db_test`
    // now, so scoping to it exits 0 and cannot prove the narrow run names an
    // offender. Scope to `booking` — still uncreated until a later batch — to
    // keep the exit-1-and-names-it teeth. Re-point when booking lands.
    $exitCode = Artisan::call('sehatly:verify-schema', ['--tables' => 'booking']);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('booking');
    expect($output)->toContain('scope');
    expect($output)->toContain('FAIL');
});

test('an unparseable reference exits 2 with a clear message, never 0', function () {
    $broken = sys_get_temp_dir().'/sehatly-broken-'.getmypid().'.sql';
    file_put_contents($broken, 'CREATE TABLE broken ( id INT NOT NULL');

    try {
        $exitCode = Artisan::call('sehatly:verify-schema', ['--sql' => $broken]);

        expect($exitCode)->toBe(2);
        expect(Artisan::output())->toContain('could not run');
    } finally {
        @unlink($broken);
    }
});

test('a missing extra-table registry exits 2 rather than forgiving every extra table', function () {
    $missing = sys_get_temp_dir().'/sehatly-no-notes-'.getmypid().'.md';

    try {
        $exitCode = Artisan::call('sehatly:verify-schema', ['--notes' => $missing]);

        expect($exitCode)->toBe(2);
        expect(Artisan::output())->toContain('could not run');
    } finally {
        @unlink($missing);
    }
});

test('the verifier is read-only: it issues no DDL and no DML', function () {
    // Source-level proof, independent of what the current database happens to
    // contain. A future edit that swaps DB::select() for DB::statement() fails here.
    $sources = [
        app_path('Support/Schema/LiveSchemaReader.php'),
        app_path('Support/Schema/SqlSchemaParser.php'),
        app_path('Support/Schema/SchemaDiffer.php'),
        app_path('Console/Commands/VerifySchemaParity.php'),
    ];

    $forbidden = [
        'DB::statement', 'DB::unprepared', 'DB::insert', 'DB::update', 'DB::delete',
        '->insert(', '->update(', '->delete(', '->truncate(', '->drop(',
        'Schema::create', 'Schema::table', 'Schema::drop', 'Artisan::call',
    ];

    foreach ($sources as $source) {
        $code = (string) file_get_contents($source);

        foreach ($forbidden as $needle) {
            expect($code)->not->toContain($needle, basename($source).' must not contain '.$needle);
        }

        // Every query the reader builds must be a SELECT or a SHOW.
        preg_match_all('/DB::select\(\s*[\'"]([a-z]+)/i', $code, $matches);

        foreach ($matches[1] as $verb) {
            expect(in_array(strtolower($verb), ['select', 'show'], true))
                ->toBeTrue(basename($source).' must only SELECT or SHOW, found: '.$verb);
        }
    }
});

test('running the verifier changes nothing in the database, and is idempotent', function () {
    $fingerprint = fn (): string => (string) json_encode(DB::select(
        'select TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE from information_schema.TABLES'
        .' where TABLE_SCHEMA in (?, ?, ?) order by TABLE_SCHEMA, TABLE_NAME',
        ['telemedisin_db', 'telemedisin_db_test', 'sehatly'],
    ));

    $before = $fingerprint();
    expect($before)->not->toBe('[]');

    expect(Artisan::call('sehatly:verify-schema'))->toBe(1);
    expect(Artisan::call('sehatly:verify-schema'))->toBe(1);

    expect($fingerprint())->toBe($before);
});

test('the verifier reads the configured database, not a hard-coded one', function () {
    $reader = new LiveSchemaReader;

    // The test connection is telemedisin_db_test; ask for it explicitly and for a
    // database that does not exist. Neither may fall back to the other.
    expect($reader->tableNames(DB::connection()->getDatabaseName()))
        ->toBe($reader->tableNames('telemedisin_db_test'));

    expect($reader->tableNames('sehatly_definitely_not_a_database'))->toBe([]);

    // And it never hard-codes the dev database either.
    $source = (string) file_get_contents(app_path('Console/Commands/VerifySchemaParity.php'));
    expect($source)->not->toContain('telemedisin_db');
    expect($source)->toContain('getDatabaseName()');
});

test('SHOW CREATE TABLE output parses through the same grammar as the reference DDL', function () {
    $table = (new SqlSchemaParser)->parseCreateTable(<<<'DDL'
        CREATE TABLE `demo` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `email` varchar(255) DEFAULT NULL,
          `kode` char(2) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `deleted_at` timestamp NULL DEFAULT NULL,
          `flag` tinyint(1) NOT NULL DEFAULT '0',
          PRIMARY KEY (`id`),
          UNIQUE KEY `email` (`email`),
          KEY `idx_kode` (`kode`),
          CONSTRAINT `demo_ibfk_1` FOREIGN KEY (`kode`) REFERENCES `other` (`kode`) ON DELETE CASCADE,
          CONSTRAINT `demo_chk_1` CHECK ((`flag` between 0 and 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        DDL, SqlSchemaParser::NAMES_ARE_SERVER_GENERATED);

    expect($table->name)->toBe('demo');
    expect($table->engine)->toBe('innodb');
    expect($table->columns['id']->unsigned)->toBeTrue();
    expect($table->columns['id']->nullable)->toBeFalse();
    expect($table->columns['id']->autoIncrement)->toBeTrue();
    expect($table->columns['email']->nullable)->toBeTrue();
    expect($table->columns['email']->default)->toBe('NULL');
    expect($table->columns['flag']->default)->toBe('0');
    expect($table->columns['flag']->type)->toBe('tinyint');
    expect($table->columns['created_at']->default)->toBe('CURRENT_TIMESTAMP');
    expect($table->columns['updated_at']->onUpdate)->toBe('CURRENT_TIMESTAMP');
    expect($table->columns['deleted_at']->default)->toBe('NULL');
    expect($table->indexes)->toHaveCount(3);
    expect($table->foreignKeys[0]->onDelete)->toBe('CASCADE');
    expect($table->checks[0]->expression)->toBe('flag between 0 and 1');
});

test('the extra-table registry is parsed from docs/schema-notes.md', function () {
    $entries = ExtraTableRegistry::fromMarkdown(base_path('docs/schema-notes.md'));

    // The expectation is DERIVED from the live migration set, not hard-coded. A
    // hard-coded list is what made this test red after todo 7 deleted three
    // scaffold migrations: the registry was correctly regenerated and the test
    // silently went stale. Enumerating database/migrations/ means the next
    // scaffold add or removal fails HERE, naming the table, instead of surfacing
    // as `undocumented_extra_table` drift ten todos later.
    $contractTables = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))->tableNames();

    $created = ['migrations'];   // Laravel's migration ledger, created by the migrator itself
    $literalNames = [];
    $files = glob(database_path('migrations').'/*.php');
    $createCalls = 0;

    expect($files)->toBeArray()->not->toBeEmpty();

    foreach ($files as $file) {
        $code = (string) file_get_contents($file);

        $createCalls += preg_match_all('/Schema::create\s*\(/', $code);
        preg_match_all("/Schema::create\s*\(\s*'([A-Za-z0-9_]+)'/", $code, $matches);
        array_push($literalNames, ...$matches[1]);
    }

    // A `Schema::create($variable)` this walk cannot resolve would make the
    // expectation silently incomplete, so refuse to pass rather than under-test.
    $creators = array_values(array_filter(
        $files,
        fn (string $file): bool => str_contains((string) file_get_contents($file), 'Schema::create')
    ));
    $creators = implode(', ', array_map('basename', $creators));

    expect($literalNames)->toHaveCount(
        $createCalls,
        $creators.' must each pass a literal table name to Schema::create(), or this test is blind to them'
    );

    $created = array_values(array_unique([...$created, ...array_map('strtolower', $literalNames)]));

    // Everything the migrations create that is not one of the 75 tables.
    $expectedExtras = array_values(array_diff($created, $contractTables));
    $registered = array_keys($entries);

    expect($registered)->toEqualCanonicalizing($expectedExtras);

    // Same assertion, but naming the offender so a failure is actionable.
    expect(array_values(array_diff($expectedExtras, $registered)))->toBe(
        [],
        'database/migrations/ creates tables docs/schema-notes.md does not register',
    );
    expect(array_values(array_diff($registered, $expectedExtras)))->toBe(
        [],
        'docs/schema-notes.md registers tables no migration in database/migrations/ creates',
    );

    foreach ($entries as $table => $justification) {
        expect($justification)->not->toBe('', $table.' needs a justification');
        expect($justification)->not->toBe('registered without a justification');
    }

    // `users` is one of the 75 tables, so registering it as an extra would be
    // wrong — and the subtraction above is exactly what keeps it out once todo 8
    // authors `2026_10_01_000012_users_table.php`.
    expect($contractTables)->toContain('users');
    expect(array_key_exists('users', $entries))->toBeFalse();
});

test('a registry with no usable rows is an error, not an empty allow-list', function () {
    $empty = sys_get_temp_dir().'/sehatly-empty-registry-'.getmypid().'.md';
    file_put_contents($empty, "# Schema notes\n\nNothing registered yet.\n");

    try {
        ExtraTableRegistry::fromMarkdown($empty);
        $this->fail('An empty registry must not be accepted.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('No registered extra tables');
    } finally {
        @unlink($empty);
    }
});

test('telemedicine_test.sql is byte-unchanged by running the verifier', function () {
    $before = md5_file(base_path('telemedicine_test.sql'));

    Artisan::call('sehatly:verify-schema');
    Artisan::call('sehatly:verify-schema', ['--json' => true]);

    expect(md5_file(base_path('telemedicine_test.sql')))->toBe($before);
});
