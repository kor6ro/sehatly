<?php

declare(strict_types=1);

use App\Models\AksesRekamMedisLog;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogWriter;
use App\Services\Audit\AuditObserverRegistrar;
use App\Services\Audit\AuditScope;
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

    // The list is DERIVED, from the foreign-key closure outward from `pasien`
    // and `users` in the reference SQL. The inherited version hand-typed twenty
    // class names here and pinned `toHaveCount(20)`, which is precisely the
    // "list asserted against itself" the brief rules out: it could only ever
    // prove the list and the registry agreed, never that coverage was a
    // mechanism. `AuditScope` derives the set, and the assertions below read
    // Eloquent's own listener table.
    $classes = AuditScope::auditedModels();

    expect($classes)->not->toBe([], 'the derived scope is empty, so nothing is covered');

    // Every plan-named sensitive model is in the DERIVED set. Generated from
    // the DDL rather than typed as a count, so a model that lost its foreign
    // key fails here by name.
    foreach (['users', 'pasien', 'pasien_alergi', 'pasien_anggota_keluarga'] as $table) {
        expect(AuditScope::personClosure())->toContain($table);
    }

    foreach ($classes as $class) {
        expect(class_exists($class))->toBeTrue($class.' does not exist');
        expect(is_subclass_of($class, Model::class))->toBeTrue($class.' is not an Eloquent model');

        foreach (AuditObserverRegistrar::events() as $event) {
            expect($dispatcher->hasListeners("eloquent.{$event}: {$class}"))
                ->toBeTrue("no {$event} listener registered for {$class}");
        }
    }
});

test('the log tables themselves are never observed: no recursion, no log-of-log', function (): void {
    $dispatcher = Model::getEventDispatcher();

    foreach ([AuditLog::class, AksesRekamMedisLog::class] as $class) {
        expect(AuditScope::auditedModels())->not->toContain($class);

        foreach (AuditObserverRegistrar::events() as $event) {
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

test('exactly one file in app/ names the audit table, apart from the model that declares it', function (): void {
    // This is a full sweep of app/, not a hand-typed list of "owned" files.
    //
    // The inherited version swept nothing: it named six paths by hand, and one
    // of them (`AuditedModels.php`) has since been deleted as part of merging
    // the two parallel audit stacks, so the scan was reading a file that did
    // not exist and asserting against a list that had stopped describing the
    // tree. Its own comment explained why - a second todo-43 attempt was
    // writing a parallel stack - which is no longer true, so the exemption has
    // no reason to exist.
    $naming = [];

    foreach (array_merge(
        al43rPhpFiles('Http/Controllers'),
        al43rPhpFiles('Services'),
        al43rPhpFiles('Observers'),
        al43rPhpFiles('Models'),
        al43rPhpFiles('Providers'),
        al43rPhpFiles('Jobs'),
        al43rPhpFiles('Console'),
    ) as $relative) {
        $code = al43rCode(base_path($relative));

        if (str_contains($code, "'audit_log'") || str_contains($code, '"audit_log"')) {
            $naming[] = $relative;
        }
    }

    // Sorted: each `al43rPhpFiles()` call sorts within its own directory, but
    // `array_merge` concatenates six already-sorted lists and the result is not
    // globally ordered. Without this the expectation depended on the order the
    // directories happened to be listed above.
    sort($naming);

    // `app/Models/AuditLog.php` names it in `protected $table`; the writer names
    // it in the single insert. Nothing else - not the observer, not the
    // registrar, not the policy, not the provider.
    expect($naming)->toBe([
        'app/Models/AuditLog.php',
        'app/Services/Audit/AuditLogWriter.php',
    ]);
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
