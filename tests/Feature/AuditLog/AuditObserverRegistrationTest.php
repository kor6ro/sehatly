<?php

declare(strict_types=1);

use App\Models\AksesRekamMedisLog;
use App\Models\AuditLog;
use App\Services\Audit\AuditedModels;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Global observer registration is the policy, not a per-model attribute
|--------------------------------------------------------------------------
|
| APPENDED by todo 43. Helper prefix `al43r`: Pest shares ONE process across
| every file under tests/Feature, so a second file reusing `al43` helper
| names would fatal on redeclaration.
|
| The property under test is the REGISTRATION MECHANISM: each sensitive
| model must have a listener on the shared event dispatcher because the
| provider put it there, so a new sensitive model is covered by policy
| rather than by remembering an attribute. Asserting the list against
| itself would prove nothing; asserting the dispatcher does.
*/

/**
 * The CODE of a PHP file with every comment and docblock removed.
 *
 * `audit_log` and `AuditLog` are named in prose in docblocks across app/
 * (migration notes, controller rationale), so a raw str_contains() over the
 * source would report a CITATION and not a write. Comments carry no runtime
 * meaning; dropping them is what makes "this code writes audit_log" true
 * when it is true.
 */
function al43rCode(string $path): string
{
    $tokens = token_get_all((string) file_get_contents($path));
    $out = '';

    foreach ($tokens as $token) {
        if (! is_array($token)) {
            $out .= $token;

            continue;
        }

        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= "\n";

            continue;
        }

        $out .= $token[1];
    }

    return $out;
}

/**
 * Every PHP file under app/$sub, recursively, as paths relative to the
 * project root. Generated from the filesystem, never hand-typed: a literal
 * list silently goes stale the moment a controller is added.
 *
 * @return list<string>
 */
function al43rPhpFiles(string $sub): array
{
    $base = base_path('app/'.$sub);

    // A missing directory contributes nothing: the scan must not fatal on
    // it, or adding the directory later silently changes what is scanned.
    if (! is_dir($base)) {
        return [];
    }

    $out = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = 'app/'.$sub.'/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($base))), '/');
        }
    }

    sort($out);

    return $out;
}

test('every sensitive model carries created, updated and deleted listeners from the central registration', function (): void {
    $dispatcher = Model::getEventDispatcher();
    expect($dispatcher)->not->toBeNull();

    $classes = AuditedModels::classes();

    // Pinned count, deliberately: adding or dropping a sensitive model must
    // be a conscious edit of the registry AND of this number, not a silent
    // drift of one from the other.
    expect($classes)->toHaveCount(20);

    foreach ($classes as $class) {
        expect(class_exists($class))->toBeTrue($class.' does not exist');
        expect(is_subclass_of($class, Model::class))->toBeTrue($class.' is not an Eloquent model');

        foreach (['created', 'updated', 'deleted'] as $event) {
            expect($dispatcher->hasListeners("eloquent.{$event}: {$class}"))
                ->toBeTrue("no {$event} listener registered for {$class}");
        }
    }
});

test('the log tables themselves are never observed: no recursion, no log-of-log', function (): void {
    $dispatcher = Model::getEventDispatcher();

    foreach ([AuditLog::class, AksesRekamMedisLog::class] as $class) {
        expect(AuditedModels::classes())->not->toContain($class);

        foreach (['created', 'updated', 'deleted'] as $event) {
            expect($dispatcher->hasListeners("eloquent.{$event}: {$class}"))
                ->toBeFalse("{$class} must not have an {$event} listener");
        }
    }
});

test('no controller writes an audit row: the observer is the only producer', function (): void {
    $offenders = [];

    foreach (al43rPhpFiles('Http/Controllers') as $relative) {
        $code = al43rCode(base_path($relative));

        // `AuditLogWriter` (the service) contains the substring `AuditLog`
        // but is a call, not a write; the needles below are the writes.
        if (str_contains($code, 'audit_log') || str_contains($code, 'AuditLog::') || str_contains($code, 'Models\\AuditLog')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

test('this task owns exactly one table write: the writer, not the observer or the models', function (): void {
    // Explicit owned paths, not a directory sweep: a second todo-43 attempt
    // is writing a parallel stack into app/Services/Audit/ and
    // tests/Feature/Audit/ in the same tree at the same time (its files are
    // untracked, not mine, and must be left alone). A sweep would assert on
    // files outside this task's jurisdiction; this pins what this task owns
    // and guarantees. The controllers sweep above stays a filesystem
    // generation, and the no-direct-writes test below still scans broadly.
    $owned = [
        'app/Observers/AuditObserver.php',
        'app/Services/Audit/AuditedModels.php',
        'app/Services/Audit/AuditLogWriter.php',
        'app/Providers/AppServiceProvider.php',
        'app/Models/RekamMedis.php',
        'app/Http/Controllers/Api/V1/AuthController.php',
    ];

    $naming = [];

    foreach ($owned as $relative) {
        if (str_contains(al43rCode(base_path($relative)), 'audit_log')) {
            $naming[] = $relative;
        }
    }

    expect($naming)->toBe(['app/Services/Audit/AuditLogWriter.php']);
});

test('no direct AuditLog model writes exist anywhere under app/', function (): void {
    $offenders = [];

    foreach (array_merge(al43rPhpFiles('Http/Controllers'), al43rPhpFiles('Services'), al43rPhpFiles('Observers'), al43rPhpFiles('Models')) as $relative) {
        $code = al43rCode(base_path($relative));

        if (
            str_contains($code, 'AuditLog::create')
            || str_contains($code, 'AuditLog::updateOrCreate')
            || str_contains($code, 'AuditLog::firstOrCreate')
            || str_contains($code, 'AuditLog::forceCreate')
            || str_contains($code, 'AuditLog::update')
            || str_contains($code, 'AuditLog::query')
        ) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

test('the writer emits only aksi values the DDL allows', function (): void {
    $spec = (new \App\Support\Schema\SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $type = $spec->table('audit_log')->columns['aksi']->type;

    preg_match("/enum\((.*)\)/i", $type, $matches);
    $allowed = array_map(
        static fn (string $member): string => trim($member, "' "),
        str_getcsv($matches[1] ?? '', ',', "'"),
    );

    // The closed set comes from the DDL parse above, not from a literal:
    // both the writer's constants and this expectation answer to the file.
    foreach (AuditLogWriter::AKSI as $aksi) {
        expect($allowed)->toContain($aksi);
    }
});
