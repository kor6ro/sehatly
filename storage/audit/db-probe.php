<?php

declare(strict_types=1);

/*
 * Read-only probe of the shared phpunit database, used by todo 30 to attribute
 * a test failure to either this todo or to a concurrent executor. Writes
 * nothing.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach (['telemedisin_db', 'telemedisin_db_test'] as $schema) {
    $fk = DB::table('information_schema.TABLE_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', $schema)
        ->where('CONSTRAINT_NAME', 'fk_vital_rm')
        ->count();

    $tables = DB::table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', $schema)
        ->where('TABLE_TYPE', 'BASE TABLE')
        ->count();

    $migrations = DB::table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', $schema)
        ->where('TABLE_NAME', 'migrations')
        ->count();

    $applied = $migrations === 1
        ? DB::table($schema.'.migrations')->count()
        : 'no migrations table';

    $batch = $migrations === 1
        ? DB::table($schema.'.migrations')->max('batch')
        : '-';

    echo sprintf(
        "%-20s tables=%-4d migrations_rows=%-5s max_batch=%-5s fk_vital_rm=%d\n",
        $schema,
        $tables,
        (string) $applied,
        (string) $batch,
        $fk,
    );
}
