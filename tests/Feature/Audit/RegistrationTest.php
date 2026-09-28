<?php

declare(strict_types=1);

use App\Models\ApotekStok;
use App\Models\AuditLog;
use App\Models\Faskes;
use App\Models\MasterAgama;
use App\Models\ObatInteraksi;
use App\Models\Permission;
use App\Models\Role;
use App\Observers\AuditObserver;
use App\Services\Audit\AuditObserverRegistrar;
use App\Services\Audit\AuditScope;
use Illuminate\Database\Eloquent\Model;

require_once __DIR__.'/audit-helpers.php';

/*
|--------------------------------------------------------------------------
| Registration: is the MECHANISM installed, or only a list written down?
|--------------------------------------------------------------------------
|
| APPENDED by todo 43.
|
| The claim this file exists to falsify is not "the sensitive-model list names
| the right models". A list asserted against itself proves nothing. The claim
| is: "the mechanism by which those models are covered is installed, and
| installed only on them".
|
| So every test here reads Eloquent's OWN listener table through
| `getRawListeners()` and looks for `App\Observers\AuditObserver@...` on the
| `eloquent.created: <class>` key. Three directions are closed:
|
|   1. in the derived scope, not registered   -> fails
|   2. registered, not in the derived scope   -> fails
|   3. registered by a per-model attribute    -> fails
|
| Direction 3 matters because `#[ObservedBy]` on 44 model classes is 44 places
| to forget one and 44 edits for the next author, whereas one `registerAll()`
| in a provider boot is one place.
|
| @see \App\Services\Audit\AuditObserverRegistrar
| @see \App\Services\Audit\AuditScope
*/

test('every model in the derived person-scope carries the one central observer', function () {
    $dispatcher = Model::getEventDispatcher();
    $models = AuditObserverRegistrar::auditedModels();

    expect($models)->not->toBe([], 'the derived scope is empty, so nothing at all is covered');

    $unregistered = [];

    foreach ($models as $class) {
        foreach (['created', 'updated', 'deleted', 'forceDeleted'] as $event) {
            $listeners = $dispatcher->getRawListeners()['eloquent.'.$event.': '.$class] ?? [];

            $covered = collect($listeners)->contains(
                fn ($listener) => is_string($listener)
                    && str_starts_with($listener, AuditObserver::class.'@')
            );

            if (! $covered) {
                $unregistered[] = $class.'@'.$event;
            }
        }
    }

    expect($unregistered)->toBe([], 'models in the derived scope with no observer listener');
});

test('the observed set is exactly the DDL closure minus two declared credential tables', function () {
    // Re-derived INDEPENDENTLY of the class under test, straight from the
    // reference SQL, so a bug in AuditScope cannot agree with itself.
    $children = [];

    foreach (audSpec()->tables as $table) {
        foreach ($table->foreignKeys as $foreignKey) {
            $children[$foreignKey->referencedTable][] = $table->name;
        }
    }

    $closure = [];
    $stack = ['pasien', 'users'];

    while ($stack !== []) {
        $table = array_pop($stack);

        if (isset($closure[$table])) {
            continue;
        }

        $closure[$table] = true;

        foreach ($children[$table] ?? [] as $child) {
            $stack[] = $child;
        }
    }

    ksort($closure);
    $closure = array_keys($closure);

    expect($closure)->toHaveCount(46, 'the person-scope closure size');
    expect(AuditScope::personClosure())->toBe($closure, 'AuditScope must agree with the reference SQL');

    $excluded = ['user_otp', 'user_refresh_tokens'];

    foreach ($excluded as $table) {
        expect(in_array($table, $closure, true))->toBeTrue($table.' must really be in the closure');
        expect(AuditScope::NOT_AUDITED)->toHaveKey($table);
        expect(trim(AuditScope::NOT_AUDITED[$table]))->not->toBe('', $table.' exclusion needs a written reason');
    }

    expect(AuditScope::auditedTables())
        ->toBe(array_values(array_diff($closure, $excluded)))
        ->and(AuditScope::auditedTables())->toHaveCount(44);
});

test('the table-to-class map is total over every model on disk', function () {
    // The only hand-typed part of the design, and it is checked against the
    // FILESYSTEM rather than against a list. 75 models, and classFor() must
    // name the class of every one. A new model, or a renamed table, fails here
    // rather than silently escaping the scope at runtime.
    $files = glob(base_path('app/Models/*.php'));

    expect($files)->toHaveCount(75);

    foreach ($files as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        expect(AuditScope::classFor((new $class)->getTable()))
            ->toBe($class, (new $class)->getTable().' must resolve to '.basename($file, '.php'));
    }
});

test('a model outside the person-scope carries no observer at all', function () {
    // The control for the test above. `faskes` is a facility, `apotek_stok` is
    // stock, `roles` and `permissions` are catalogue rows: none has a foreign
    // key to a person, so the closure excludes them. A design that leaked
    // observers onto them would be logging non-personal rows and paying for it
    // on every write.
    $outside = [
        Faskes::class,
        ApotekStok::class,
        MasterAgama::class,
        ObatInteraksi::class,
        Role::class,
        Permission::class,
        AuditLog::class,
    ];

    foreach ($outside as $class) {
        foreach (['created', 'updated', 'deleted'] as $event) {
            $listeners = Model::getEventDispatcher()->getRawListeners()['eloquent.'.$event.': '.$class] ?? [];

            $covered = collect($listeners)->contains(
                fn ($listener) => is_string($listener)
                    && str_starts_with($listener, AuditObserver::class.'@')
            );

            expect($covered)->toBeFalse($class.'@'.$event.' must carry no AuditObserver');
        }
    }

    foreach (['faskes', 'apotek_stok', 'roles', 'permissions', 'audit_log'] as $table) {
        expect(in_array($table, AuditScope::personClosure(), true))->toBeFalse($table);
    }
});

test('audit_log never observes itself', function () {
    // Self-observation would be unbounded: the observer writes a row, which
    // fires `created` on AuditLog, which writes a row, forever. The closure
    // already excludes audit_log because it has no foreign key to anybody, and
    // this asserts the consequence is real rather than incidental.
    expect(in_array('audit_log', AuditScope::auditedTables(), true))->toBeFalse();

    $listeners = Model::getEventDispatcher()->getRawListeners()['eloquent.created: '.AuditLog::class] ?? [];

    expect(collect($listeners)->contains(
        fn ($listener) => is_string($listener) && str_starts_with($listener, AuditObserver::class.'@')
    ))->toBeFalse();
});

test('registration is global, so no model carries an ObservedBy attribute', function () {
    expect(audFilesMentioning('app/Models', 'ObservedBy'))->toBe([]);
    expect(audFilesMentioning('app/Models', 'AuditObserver'))->toBe([]);

    // And the only `::observe(` call in the whole application is the registrar's.
    expect(audFilesMentioning('app', '::observe('))
        ->toBe(['app/Services/Audit/AuditObserverRegistrar.php']);
});

test('the registrar registers whatever class it is handed, with no list involved', function () {
    // The mechanism, independent of the derived list: a class that is NOT in
    // the closure is still covered the moment it goes through the registrar.
    // This is what makes registerAll() a mechanism rather than a loop over a
    // constant.
    $probe = new class extends Model
    {
        protected $table = 'apotek_stok';
    };

    expect(AuditObserverRegistrar::isAudited($probe::class))->toBeFalse();

    AuditObserverRegistrar::observe($probe::class);

    expect(AuditObserverRegistrar::isAudited($probe::class))->toBeTrue();

    $listeners = Model::getEventDispatcher()->getRawListeners()['eloquent.created: '.$probe::class] ?? [];

    expect(collect($listeners)->contains(
        fn ($listener) => is_string($listener) && str_starts_with($listener, AuditObserver::class.'@')
    ))->toBeTrue();
});

test('registerAll is called from the provider boot', function () {
    $code = audCode(base_path('app/Providers/AppServiceProvider.php'));

    expect($code)->toContain('AuditObserverRegistrar');
    expect($code)->toContain('registerAll');
});
