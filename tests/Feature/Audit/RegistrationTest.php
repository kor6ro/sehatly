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
    $events = AuditObserverRegistrar::events();

    // The event list is DERIVED from the writer's own event=>aksi map, so this
    // cannot assert a listener the design deliberately does not install.
    // `forceDeleted` was in the inherited list: `AuditObserver` has no
    // `forceDeleted` method, and `deleted` already fires on the force-delete
    // path, so requiring it asserted a registration that should not exist.
    foreach ($models as $class) {
        foreach ($events as $event) {
            foreach ($dispatcher->getRawListeners()['eloquent.'.$event.': '.$class] ?? [] as $listener) {
                if ($listener === AuditObserver::class.'@'.$event) {
                    continue 2;
                }
            }

            $unregistered[] = $class.'@'.$event;
        }
    }

    expect($unregistered)->toBe([]);
});

test('the observed set is exactly the DDL closure minus the declared exclusions', function () {
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

    // The closure size is a FACT about the DDL, so it is computed here from
    // the parse above and compared, rather than asserted as a literal. A
    // hand-typed 46 keeps passing when a table is added and a hard-coded
    // expectation is exactly how a scope silently goes stale.
    expect($closure)->toBe(AuditScope::personClosure(), 'AuditScope must agree with the reference SQL');
    expect($closure)->not->toBeEmpty();

    // The exclusions are derived from the constant that declares them, so
    // adding one is a single edit in ONE place. The inherited version typed
    // `['user_otp', 'user_refresh_tokens']` here, which meant a third exclusion
    // had to be remembered in the test as well as in the policy - and when
    // `akses_rekam_medis_log` was added the test failed on the diff without
    // saying which side was right.
    $excluded = array_keys(AuditScope::NOT_AUDITED);
    expect($excluded)->not->toBeEmpty();

    foreach ($excluded as $table) {
        expect(in_array($table, $closure, true))->toBeTrue($table.' must really be in the closure');
        expect(trim(AuditScope::NOT_AUDITED[$table]))->not->toBe('', $table.' exclusion needs a written reason');
    }

    expect(AuditScope::auditedTables())->toBe(array_values(array_diff($closure, $excluded)));
    expect(AuditScope::auditedTables())->toHaveCount(count($closure) - count($excluded));

    // The two log tables are never observed, but for DIFFERENT reasons, and
    // conflating them is how an exclusion quietly becomes a coverage hole.
    //
    // `audit_log` is absent from the closure BY CONSTRUCTION: it declares no
    // foreign key at all (:1118-1132), which is what stops the observer
    // observing its own writes.
    //
    // `akses_rekam_medis_log` IS in the closure - it references `rekam_medis` -
    // and is absent by the DECLARED EXCLUSION above. Asserting it were absent
    // by construction would assert something false about the schema and would
    // pass for the wrong reason if the exclusion were ever dropped.
    expect(in_array('audit_log', $closure, true))->toBeFalse('audit_log declares no foreign key');
    expect(in_array('akses_rekam_medis_log', $closure, true))->toBeTrue('it references rekam_medis');
    expect(in_array('akses_rekam_medis_log', AuditScope::auditedTables(), true))->toBeFalse();
});

test('the table-to-class map is total over every model on disk', function () {
    // The only hand-typed part of the design, and it is checked against the
    // FILESYSTEM rather than against a list. 80 models, and classFor() must
    // name the class of every one. A new model, or a renamed table, fails here
    // rather than silently escaping the scope at runtime.
    $files = glob(base_path('app/Models/*.php'));

    expect($files)->toHaveCount(80);

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

    // The needle is `Model::observe(`, not a bare `::observe(`: the registrar
    // legitimately calls its OWN `self::observe($class)` from `registerAll()`,
    // and a looser needle flagged that instead of the thing being banned.
    //
    // The inherited version asserted the only `::observe(` call in the
    // application was the registrar's. The provider DID call `$model::observe()`
    // over a hand-typed list, which is a per-model registration wearing a
    // central-registration costume: twenty models in the list, twenty boot-time
    // instantiations, and no way to see the omission when a model was added.
    // The framework entry point is now called from nowhere at all.
    expect(audFilesMentioning('app', 'Model::observe('))->toBe([]);

    // `registerAll` appears in exactly two places, and both are required: the
    // registrar that DEFINES it, and the provider that CALLS it. A single-file
    // expectation was the inherited mistake - it would have passed for a
    // registrar nobody invoked, which is a mechanism that is defined and never
    // installed. The existence of the second file is the point.
    expect(audFilesMentioning('app', 'registerAll'))->toBe([
        'app/Providers/AppServiceProvider.php',
        'app/Services/Audit/AuditObserverRegistrar.php',
    ]);
});

test('no model is instantiated at boot, so registration cannot re-enter model boot', function () {
    // The mechanism that broke the application once: `Model::observe()` is
    // `(new static)->registerObserver(...)`, so using it from a provider BOOTS
    // every audited model from inside the boot. The registrar therefore
    // registers on the class-scoped event name directly.
    //
    // This is asserted rather than described because the failure it prevents is
    // total: with the inherited code every `artisan` command in the project
    // died with "bootIfNotBooted() may not be called ... while it is being
    // booted", so nothing at all could be run to observe it.
    $code = audCode(base_path('app/Services/Audit/AuditObserverRegistrar.php'));

    // The framework entry point is the thing that instantiates; the registrar's
    // OWN `self::observe(` is the mechanism and is expected here.
    expect($code)->not->toContain('Model::observe(');
    expect($code)->toContain("'eloquent.'");

    // The model class map is read by reflection for the same reason, so the
    // provider never constructs a model to ask it for its table.
    expect(audCode(base_path('app/Services/Audit/AuditScope.php')))
        ->not->toContain('(new $class)');
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

test('the registrar is idempotent, so a second boot cannot double-log a write', function () {
    // `Dispatcher::listen()` APPENDS. registerAll() runs in a provider boot,
    // and providers are booted more than once across a test process that
    // rebuilds the application, so an appending registration writes TWO audit
    // rows for one model write. The count of raw listeners is the observable
    // that would betray it.
    $probe = new class extends Model
    {
        protected $table = 'apotek_stok';
    };

    AuditObserverRegistrar::observe($probe::class);
    AuditObserverRegistrar::observe($probe::class);

    $raw = Model::getEventDispatcher()->getRawListeners();

    foreach (AuditObserverRegistrar::events() as $event) {
        $listeners = $raw['eloquent.'.$event.': '.$probe::class] ?? [];

        expect($listeners)->toHaveCount(1, $probe::class.'@'.$event.' must not accumulate listeners');
    }
});

test('registerAll is called from the provider boot', function () {
    $code = audCode(base_path('app/Providers/AppServiceProvider.php'));

    expect($code)->toContain('AuditObserverRegistrar');
    expect($code)->toContain('registerAll');
});
