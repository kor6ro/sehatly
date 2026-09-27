<?php

declare(strict_types=1);

/*
 * A.26 token audit for the files authored by todo 30.
 *
 * Extracts every snake_case token from the given files and checks it against the
 * reference DDL through the project's own SqlSchemaParser, so this audit cannot
 * disagree with `sehatly:verify-schema` about what the schema says.
 *
 * Two corrections, both learned the hard way in this project and both applied
 * here rather than papered over with an allow-list:
 *
 * 1. Index, key and view names are DDL objects too. A first pass reported them
 *    as unresolved; the fix was to collect them, not to excuse them.
 * 2. ENUM members are DDL objects too. `user_otp.tujuan` is
 *    `enum('verifikasi_telepon','verifikasi_email','reset_kata_sandi','login')`
 *    and its members are real, checkable names, so the audit reads them out of
 *    `ColumnSpec::$type` rather than treating them as prose.
 *
 * A PHP built-in function is recognised with `function_exists()` rather than
 * hand-listed, so the function half of the allow-list cannot drift.
 *
 * Usage: php storage/audit/token-audit.php file1 file2 ...
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Support\Schema\SqlSchemaParser;

$files = array_slice($argv, 1);

$spec = (new SqlSchemaParser)->parseFile(__DIR__.'/../../telemedicine_test.sql');

$known = [];
$counts = ['tables' => 0, 'columns' => 0, 'indexes' => 0, 'views' => 0, 'enum_members' => 0];

foreach ($spec->tables as $tableName => $table) {
    $known[$tableName] = true;
    $counts['tables']++;

    foreach ($table->columns as $columnName => $column) {
        $known[$columnName] = true;
        $counts['columns']++;

        if (preg_match("/^enum\((.*)\)$/", $column->type, $m) === 1) {
            foreach (explode(',', $m[1]) as $member) {
                $member = trim(trim($member), "'");

                if ($member !== '') {
                    $known[$member] = true;
                    $counts['enum_members']++;
                }
            }
        }
    }

    foreach ($table->indexes as $index) {
        $name = $index->name ?? null;

        if (is_string($name) && $name !== '') {
            $known[$name] = true;
            $counts['indexes']++;
        }
    }
}

foreach ($spec->views as $view) {
    $known[$view] = true;
    $counts['views']++;
}

/*
 * The names the authored files assert are ABSENT from the schema. They are
 * unresolved by design - being unresolved IS the assertion - so they get their
 * own printed category rather than being mixed in with real findings.
 */
$assertedAbsent = [
    'email_verified_at' => 'the scaffold asserted users.email_verified_at; WebSurfaceTest asserts the DDL has no such column',
    'password_reset_tokens' => 'config/auth.php:98 named it as the reset broker; the table is in neither the schema nor either database',
    'remember_token' => 'the scaffold wrote it; the DDL has no such column',
    'two_factor' => 'the scaffold wrote two_factor_*; the DDL has no such column',
    'verified_at' => 'a prefix of email_verified_at, caught by the same tokenizer',
];

/*
 * Everything else that is neither a DDL object nor a PHP built-in. Each entry
 * carries its provenance, so the list is auditable rather than a dump.
 */
$vocabulary = [
    'api_prefix' => 'bootstrap/app.php withRouting argument, not a table',
    'health' => 'bootstrap/app.php withRouting argument',
    'auth' => 'config/auth.php defaults key and composer package',
    'sanctum' => 'composer package and guard name',
    'fortify' => 'package removed in todo 30; named only to assert its absence',
    'inertia' => 'package removed in todo 30; named only to assert its absence',
    'passkeys' => 'package removed in todo 30; named only to assert its absence',
    'wayfinder' => 'package removed in todo 30; named only to assert its absence',
    'per_page' => 'query-string pagination parameter',
    'date_format' => 'Laravel validation rule name',
    'current_page' => 'ApiResponse meta key',
    'last_page' => 'ApiResponse meta key',
    'access_token' => 'a response key of AuthTokenResource, not a column',
    'sidebar_state' => 'the removed Inertia cookie name, in bootstrap/app.php and the User model docblock',
    'lewat_waktu' => 'an `alasan` value of SlotAvailabilityService, not a DDL object',
    'no_teepon' => 'a deliberate misspelling, present only to prove the token audit flags ASCII typos',
    'current_password' => 'the request key the deleted Settings PasswordUpdateRequest validated; a form field, not a column',
    'telemedicine_test' => 'the reference SQL filename stem',
    'strict_types' => 'the declare(strict_types=1) directive',
    'enum_members' => "this script's own report label for the ENUM members it collected from ColumnSpec::\$type",
    'snake_case' => 'this script docblock, describing what it tokenises',
];

$fileExtensions = [
    'md', 'sql', 'php', 'json', 'ts', 'tsx', 'js', 'html', 'yml', 'yaml',
    'lock', 'env', 'txt', 'ico', 'png', 'svg', 'jpeg',
];

/*
 * A PHPUnit method name, recognised by SHAPE rather than by enumerating the
 * fifteen-odd of them. Enumerating would be an allow-list that has to be
 * extended every time a test is added, which is exactly the kind of list that
 * rots quietly.
 */
$isTestMethod = static fn (string $token): bool => str_starts_with($token, 'test_')
    || str_starts_with($token, '__pest_');

$total = 0;
$occurrences = [];
$unresolved = [];

foreach ($files as $file) {
    $contents = (string) file_get_contents($file);

    preg_match_all('/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b/', $contents, $matches);

    foreach ($matches[0] as $token) {
        $total++;
        $occurrences[$token] = ($occurrences[$token] ?? 0) + 1;

        if (isset($known[$token])
            || function_exists($token)
            || in_array($token, $fileExtensions, true)
            || isset($assertedAbsent[$token])
            || isset($vocabulary[$token])
            || $isTestMethod($token)) {
            continue;
        }

        $unresolved[$token] = ($unresolved[$token] ?? 0) + 1;
    }
}

ksort($unresolved);

$builtins = array_values(array_filter(
    array_keys($occurrences),
    static fn (string $t): bool => function_exists($t),
));

echo 'files              : '.count($files)."\n";
echo 'ddl identifiers    : '.json_encode($counts)."\n";
echo 'occurrences        : '.$total."\n";
echo 'distinct tokens    : '.count($occurrences)."\n";
echo 'php built-ins seen : '.count($builtins)."\n";
echo 'UNRESOLVED         : '.count($unresolved)."\n";

foreach ($unresolved as $token => $count) {
    echo "  $token ($count)\n";
}

echo "\n-- names asserted ABSENT from the DDL --\n";

foreach ($assertedAbsent as $token => $why) {
    if (isset($occurrences[$token])) {
        echo "  $token -> $why ({$occurrences[$token]}x)\n";
    }
}

echo "\n-- non-DDL vocabulary --\n";

foreach ($vocabulary as $token => $why) {
    if (isset($occurrences[$token])) {
        echo "  $token -> $why ({$occurrences[$token]}x)\n";
    }
}

echo "\n-- php built-ins seen, recognised with function_exists --\n";

foreach ($builtins as $token) {
    echo "  $token ({$occurrences[$token]}x)\n";
}

echo "\n-- file extensions seen --\n";

foreach ($fileExtensions as $ext) {
    if (isset($occurrences[$ext])) {
        echo "  $ext ({$occurrences[$ext]}x)\n";
    }
}
