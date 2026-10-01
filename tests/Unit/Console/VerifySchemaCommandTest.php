<?php

use App\Support\Schema\ExtraTableRegistry;
use App\Support\Schema\LiveSchemaReader;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/**
 * What the contract (`telemedicine_test.sql`) defines that no migration in
 * `database/migrations/` has created yet: the missing TABLES and the missing
 * VIEWS.
 *
 * DERIVED, never listed by hand. Walking the migration directory is the same
 * pattern `the extra-table registry is parsed from docs/schema-notes.md` below
 * already uses, and for the same reason: a hard-coded expectation is correct at
 * exactly one point in the project's history and goes stale the moment the next
 * batch lands its objects. Todo 8 pinned `56`, a five-name list and
 * `--tables=booking`, and the list named `pasien` - a todo-9 table - so it broke
 * on the very next commit.
 *
 * VIEWS are derived too, not left as two literal regexes. The two view
 * migrations (`2026_10_01_000077`/`_000078`) are authored in todo 18, *after*
 * the last table migration in todo 17, and they emit `CREATE OR REPLACE VIEW`
 * rather than `Schema::create` - so a table-only derivation reaches "nothing
 * missing" one commit before the verdict actually flips, and a test that
 * inverted on that signal alone would go red at todo 17. Deriving both means the
 * inversion happens exactly when the schema is complete.
 *
 * Source of truth: the **migration set**, not the live database. The live schema
 * is verified separately, by the command itself, against the reference DDL;
 * asserting that its `missing_table` / `missing_view` rows equal this derived
 * set is what proves the two agree. Deriving from the migrations keeps the
 * expectation independent of whether a migration has been *run* yet, and means a
 * table that exists in the database but has no migration cannot quietly satisfy a
 * check that is really about the migration set.
 *
 * Declared as a file-scope closure rather than a `function`, deliberately: a
 * test file that declares a global function fatals the entire suite at include
 * time the moment a second file declares the same name.
 *
 * @return array{tables: list<string>, views: list<string>} sorted, lower-cased
 */
$missingFromMigrations = function (): array {
    $files = glob(database_path('migrations').'/*.php');

    expect($files)->toBeArray()->not->toBeEmpty('database/migrations/ must not be empty');

    $literalTables = [];
    $createCalls = 0;
    $literalViews = [];
    $createViewCalls = 0;

    foreach ($files as $file) {
        $code = (string) file_get_contents($file);

        $createCalls += preg_match_all('/Schema::create\s*\(/', $code);
        preg_match_all("/Schema::create\s*\(\s*'([A-Za-z0-9_]+)'/", $code, $matches);
        array_push($literalTables, ...$matches[1]);

        $createViewCalls += preg_match_all('/\bCREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+/i', $code);
        preg_match_all('/\bCREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+`?([A-Za-z0-9_]+)`?/i', $code, $viewMatches);
        array_push($literalViews, ...$viewMatches[1]);
    }

    // A `Schema::create($variable)` - or a view name built at runtime - that this
    // walk cannot resolve would leave the derived set silently too small, and a
    // too-small set makes every assertion downstream pass for the wrong reason
    // (a derived missing count of 0 next to 25 real `Schema::create` calls).
    // Refuse rather than under-test, and name the files so it is actionable.
    $creators = implode(', ', array_map('basename', array_filter(
        $files,
        static fn (string $file): bool => str_contains((string) file_get_contents($file), 'Schema::create'),
    )));

    expect($createCalls)->toBeGreaterThan(0, 'no Schema::create call found in database/migrations/');
    expect($literalTables)->toHaveCount(
        $createCalls,
        $creators.' must each pass a literal table name to Schema::create(); '
            .($createCalls - count($literalTables)).' call(s) were not extractable, so the derived set is incomplete',
    );
    expect($literalViews)->toHaveCount(
        $createViewCalls,
        'every CREATE VIEW in database/migrations/ must name its view with a literal',
    );

    // `migrations` is Laravel's ledger, created by the migrator rather than by a
    // `Schema::create` call, but it does exist in the live schema.
    $created = array_values(array_unique([...array_map('strtolower', $literalTables), 'migrations']));

    $contract = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $missingTables = array_values(array_diff($contract->tableNames(), $created));
    sort($missingTables);

    $createdViews = array_values(array_unique(array_map('strtolower', $literalViews)));
    $missingViews = array_values(array_diff($contract->views, $createdViews));
    sort($missingViews);

    return ['tables' => $missingTables, 'views' => $missingViews];
};

/**
 * The command's contract: read-only, exit 1 on drift naming the offender, exit 0
 * only on an exact match, and never green when it could not understand its inputs.
 */
test('the verifier exits 1 and names every table the migrations have not created yet', function () use ($missingFromMigrations) {
    $missing = $missingFromMigrations();
    $missingTables = $missing['tables'];
    $missingViews = $missing['views'];

    $exitCode = Artisan::call('sehatly:verify-schema');
    $output = Artisan::output();

    // Todo 18 is the point at which the last object is migrated - the two views
    // and the deferred FK, `2026_10_01_000076`-`_000078` - and the verdict
    // inverts to exit 0 / PASS. Assert the inverted shape explicitly rather than
    // skipping, so the test is a real check on both sides of that commit instead
    // of going quiet. Nothing here needs editing when it happens.
    if ($missingTables === [] && $missingViews === []) {
        expect($exitCode)->toBe(0);
        expect($output)->toContain('PASS');
        expect($output)->not->toContain('missing_table');
        expect($output)->not->toContain('missing_view');

        return;
    }

    expect($exitCode)->toBe(1);
    expect($output)->toContain('FAIL');
    expect($output)->toContain('read-only');

    // Every missing table is named, not merely counted. This is the plan's
    // criterion 2: "it exits 1 and names the offending table/column".
    //
    // The names are derived from the live migration set, so this exhaustively
    // checks the report against every table still owed, at every batch boundary,
    // without a hand-maintained list to re-point.
    expect(substr_count($output, 'missing_table'))->toBe(count($missingTables));

    preg_match_all('/missing_table\s+([A-Za-z0-9_]+)/', $output, $named);
    expect($named[1])->toEqualCanonicalizing(
        $missingTables,
        'the report must name exactly the contract tables no migration creates yet',
    );

    if ($missingTables !== []) {
        expect($output)->toContain('missing_table');

        // The same check as a loop, so a failure names the offending table.
        foreach (array_values(array_unique([
            $missingTables[0],
            $missingTables[(int) floor(count($missingTables) / 2)],
            $missingTables[count($missingTables) - 1],
        ])) as $table) {
            expect($output)->toMatch('/missing_table\s+'.preg_quote($table, '/').'\b/', 'the report must name '.$table);
        }
    }

    // Same derivation for the views, for the same reason: they are authored in
    // todo 18, one commit after the last table migration.
    expect(substr_count($output, 'missing_view'))->toBe(count($missingViews));

    foreach ($missingViews as $view) {
        expect($output)->toMatch('/missing_view\s+'.preg_quote($view, '/').'\b/', 'the report must name '.$view);
    }
});

test('the JSON report is machine-readable, and its exit code matches its verdict', function () use ($missingFromMigrations) {
    $missing = $missingFromMigrations();
    $registeredExtras = count(ExtraTableRegistry::fromMarkdown(base_path('docs/schema-notes.md')));

    $exitCode = Artisan::call('sehatly:verify-schema', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($json)->toBeArray();
    expect($json['exit_code'])->toBe($exitCode);
    expect($json['read_only'])->toBeTrue();
    expect($json['expected']['tables'])->toBe(76);
    expect($json['expected']['views'])->toBe(2);
    expect($json['expected']['columns'])->toBeGreaterThan(600);
    // The CONFIGURED database name rather than a literal: a per-executor
    // $env:DB_DATABASE override is a supported way to run this suite, and pinning the
    // literal made the report test fail on any private database. config() is what the
    // command itself reads, so this asserts the report names the database it was run
    // against - which is the property that matters - rather than a spelling.
    expect($json['live']['database'])->toBe(config('database.connections.mysql.database'));
    expect($json['reference']['md5'])->toBe(md5_file(base_path('telemedicine_test.sql')));
    expect($json['notes_registry']['registered_extra_tables'])->toBe($registeredExtras);

    // The documented extra tables (`docs/schema-notes.md`) are present in
    // `telemedisin_db_test` and read as informational, so drift and discrepancy
    // totals legitimately differ - by exactly the registry size. On a 0-table
    // database both totals agreed and this line read
    // `toBe($json['discrepancy_count'])`; that form can never pass again while
    // the extras exist, so pin the decomposition instead of dropping the check.
    //
    // The size is DERIVED, not the literal 7 this line used to hold. The
    // registry is regenerated whenever database/migrations/ changes, so a pinned
    // number here went stale the moment todo 7 deleted three scaffold
    // migrations - the same defect todo 8 then reintroduced above.
    //
    // It used to be the sum of BOTH registries, because a `missing_foreign_key`
    // listed in the notes file's *Deferred constraints* table is informational
    // too (plan appendix A.10 rule 1), so the extras alone under-counted by the
    // number of outstanding deferrals. **There is now only one registry.** The
    // last deferral was resolved in todo 18 by migration
    // `2026_10_01_000076`, and retiring the registry took four edits in four
    // files; this line is the third. Adding the deferral term back would require
    // `DeferredConstraintRegistry::fromMarkdown()` in the command, which raises
    // on an empty registry, so the two would have to land together.
    //
    // NOTE on the expected total: the extras are reported as informational, not
    // suppressed, so `discrepancy_count` is **7, not 0**, and it always will be
    // while seven registered extra tables exist. Zero *drift* is the invariant;
    // zero *discrepancies* is not reachable without deleting either the registry
    // rows - which turns all seven into `undocumented_extra_table` drift - or the
    // tables, which the framework requires.
    expect($json['discrepancy_count'] - $json['drift_count'])->toBe($registeredExtras);

    $rows = array_values(array_filter($json['discrepancies'], fn ($d) => $d['kind'] === 'missing_table'));
    expect(array_column($rows, 'table'))->toEqualCanonicalizing(
        $missing['tables'],
        'the JSON report must name exactly the contract tables no migration creates yet',
    );

    $viewRows = array_values(array_filter($json['discrepancies'], fn ($d) => $d['kind'] === 'missing_view'));
    expect(array_column($viewRows, 'table'))->toEqualCanonicalizing(
        $missing['views'],
        'the JSON report must name exactly the contract views no migration creates yet',
    );

    // Proof the parser is not vacuous, carried in the machine-readable channel too.
    expect($json['multi_line_column_declarations'])->toHaveCount(11);
    expect($json['expected'])->toMatchArray(['foreign_keys' => 107, 'checks' => 3]);

    // Todo 18 flips the verdict, not the accounting: with nothing left to
    // migrate the report is ok, drift is zero, and the only rows that remain are
    // the informational extras. Same explicit branch as the text-channel test.
    if ($missing['tables'] === [] && $missing['views'] === []) {
        expect($exitCode)->toBe(0);
        expect($json['ok'])->toBeTrue();
        expect($json['drift_count'])->toBe(0);
        expect($rows)->toBe([]);
        expect($viewRows)->toBe([]);

        return;
    }

    expect($exitCode)->toBe(1);
    expect($json['ok'])->toBeFalse();
    expect($json['drift_count'])->toBeGreaterThan(0);
});

test('the scope can be narrowed to a single table, and the narrow run still names the offender', function () use ($missingFromMigrations) {
    $contract = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))->tableNames();
    $notYetCreated = $missingFromMigrations()['tables'];

    // Anchor the narrow run on a table no migration has created yet, so the run
    // really has an offender to name. DERIVED, not the literal `booking` this
    // test used to pass: `booking` is a todo-11 table, so the anchor broke the
    // day todo 11 landed it, and `master_provinsi` (the batch-A anchor before
    // it) broke the day todo 8 landed that one.
    if ($notYetCreated === []) {
        // Todo 17 onwards every table exists, so a table-scoped run has nothing
        // to name and the verdict inverts to exit 0. Two A.7 traps apply here and
        // are why the assertions read the output rather than trusting the code:
        // the PASS banner is formatted from the FULL reference model, so it
        // prints "76 tables, 2 views verified" after checking one table; and a
        // name the DDL does not define also exits 0. So check the echoed scope
        // line and the Discrepancies line.
        $anchor = $contract[0];

        expect(Artisan::call('sehatly:verify-schema', ['--tables' => $anchor]))->toBe(0);

        $output = Artisan::output();
        expect($output)->toMatch('/scope\s+'.preg_quote($anchor, '/').'\b/', 'the echoed scope must list '.$anchor);
        expect($output)->toMatch('/Discrepancies:\s+0\b/');

        return;
    }

    $anchor = $notYetCreated[0];

    $exitCode = Artisan::call('sehatly:verify-schema', ['--tables' => $anchor]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toMatch('/missing_table\s+'.preg_quote($anchor, '/').'\b/', 'the narrow run must name '.$anchor);
    expect($output)->toMatch('/scope\s+'.preg_quote($anchor, '/').'\b/');
    expect($output)->toContain('FAIL');
});

test('the derived expectations are not vacuous: the walk extracts every Schema::create call', function () use ($missingFromMigrations) {
    // The adversarial failure this guards is a derivation that silently matches
    // nothing and reports a missing count of 0 - which would make the three
    // tests above pass for the wrong reason and the verifier look green. So the
    // two numbers are counted independently and printed, every run: an extracted
    // count of 0 next to a non-zero call count is a failure, not a quiet pass.
    $files = glob(database_path('migrations').'/*.php');

    expect($files)->toBeArray()->not->toBeEmpty();

    $createCalls = 0;
    $extracted = 0;
    $viewCalls = 0;
    $viewsExtracted = 0;

    foreach ($files as $file) {
        $code = (string) file_get_contents($file);

        $createCalls += preg_match_all('/Schema::create\s*\(/', $code);
        $extracted += preg_match_all("/Schema::create\s*\(\s*'([A-Za-z0-9_]+)'/", $code);
        $viewCalls += preg_match_all('/\bCREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+/i', $code);
        $viewsExtracted += preg_match_all('/\bCREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+`?([A-Za-z0-9_]+)`?/i', $code);
    }

    $contract = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $missing = $missingFromMigrations();
    $registered = count(ExtraTableRegistry::fromMarkdown(base_path('docs/schema-notes.md')));

    fwrite(STDERR, sprintf(
        "\n  [derive] migrations=%d Schema::create calls=%d extracted=%d"
            .' | CREATE VIEW calls=%d extracted=%d | contract tables=%d views=%d'
            ." | derived-missing tables=%d views=%d | registry=%d\n",
        count($files),
        $createCalls,
        $extracted,
        $viewCalls,
        $viewsExtracted,
        count($contract->tableNames()),
        count($contract->views),
        count($missing['tables']),
        count($missing['views']),
        $registered,
    ));

    expect($createCalls)->toBeGreaterThan(0, 'database/migrations/ contains no Schema::create call at all');
    expect($extracted)->toBe(
        $createCalls,
        'extracted '.$extracted.' of '.$createCalls.' Schema::create calls; the derived set is incomplete',
    );
    expect($viewsExtracted)->toBe($viewCalls);

    // The missing set is the contract minus what the migrations create, so the
    // two must partition the contract exactly. Asserted as a relationship, never
    // as a pinned number, so this survives todos 9 through 18 landing objects.
    $contractTables = $contract->tableNames();
    $created = array_diff($contractTables, $missing['tables']);

    expect($missing['tables'])->toEqualCanonicalizing(array_values(array_diff($contractTables, $created)));
    expect(array_merge($missing['tables'], $created))->toEqualCanonicalizing($contractTables);
    expect(array_diff($missing['views'], $contract->views))->toBe([]);
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

    // The verdict is DERIVED, not pinned. This test is about two properties - the
    // run is read-only, and it is idempotent - and neither is about whether the
    // schema currently matches. It used to assert `toBe(1)` twice, which was a
    // fourth hard-coded expectation of the pre-todo-18 state in this file (after
    // the three in A.9) and inverted the moment migration 76 and the two views
    // landed. Asserting the two runs AGREE, and that nothing changed underneath
    // them, is the actual property and it holds at any migration position.
    $first = Artisan::call('sehatly:verify-schema');
    $second = Artisan::call('sehatly:verify-schema');

    expect($second)->toBe($first, 'two consecutive runs of a read-only verifier must reach the same verdict');

    expect($fingerprint())->toBe($before);
    expect($first)->toBeIn([0, 1], 'the verifier must reach a real verdict, never the "could not run" exit 2');
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

    // Everything the migrations create that is not one of the 76 tables.
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

    // `users` is one of the 76 tables, so registering it as an extra would be
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
