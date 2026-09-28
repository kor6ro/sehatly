<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Observers\AuditObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * The single place that installs the audit observer on every sensitive model.
 *
 * There is exactly one call site: {@see registerAll()}, invoked once from
 * `AppServiceProvider::boot()`. No model carries an `#[ObservedBy]` attribute,
 * so coverage is a policy in one spot rather than a habit repeated on each of
 * the audited classes.
 *
 * ## Why this does not call `Model::observe()`
 *
 * `Model::observe()` is the obvious call and it is the wrong one here. It is a
 * STATIC method whose body is `(new static)->registerObserver(...)`, so asking
 * it to register an observer INSTANTIATES the model - which boots it, and does
 * so from inside the service provider that is itself booting. That is not
 * hypothetical: the first version of this class did exactly that, and
 * `RefusesHardDelete::bootRefusesHardDelete()` re-entered
 * `Model::bootIfNotBooted()` while `static::$booting` was still set, so every
 * `artisan` command in the application died at boot.
 *
 * So the listener is registered directly, on the class-scoped event name the
 * framework itself uses: `eloquent.created: App\Models\Pasien`. The listener is
 * the STRING `App\Observers\AuditObserver@created`, which is exactly what
 * `registerModelEvent()` would have registered, and which
 * `Dispatcher::makeListener()` resolves to the observer method at dispatch
 * time. Same effect, zero models booted.
 *
 * ## Why a class-scoped name and not a wildcard
 *
 * The wildcard form `Model@created` is one character shorter and registers a
 * listener for EVERY model event in the process. The version of this class that
 * used it also passed a fourth `$class` argument to `Event::listen()`, which
 * `Dispatcher::listen($events, $listener, $priority)` silently discards - so the
 * closure fired for every model in the application, `faskes` and
 * `apotek_stok` included, with nothing filtering it. A wildcard would have been
 * undetectable by reading the call, which is why the class name is in the event
 * name and the test asserts the wildcard set is empty.
 *
 * ## Coverage is DERIVED, so it cannot be forgotten
 *
 * The class list comes from {@see AuditScope}, which follows the foreign-key
 * closure outward from `pasien` and `users` in the reference SQL. A new
 * sensitive model is audited the day it is written, because a new table with a
 * foreign key to a person is in the closure with no edit to any list.
 *
 * @see \App\Services\Audit\AuditScope
 * @see \App\Observers\AuditObserver
 */
final class AuditObserverRegistrar
{
    /**
     * Events the observer subscribes to.
     *
     * `deleted` covers the force-delete path too: `Model::forceDelete()` on a
     * model using `SoftDeletes` fires `deleting`, `deleted` AND `forceDeleted`,
     * and the observer has no `forceDeleted` handler, so there is nothing extra
     * to learn from subscribing to it.
     *
     * @return list<string>
     */
    public static function events(): array
    {
        return array_keys(AuditLogWriter::EVENT_AKSI);
    }

    /**
     * The model classes in the person-scope closure, minus the two declared
     * credential-table exclusions and minus any closure table with no model.
     *
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public static function auditedModels(): array
    {
        return AuditScope::auditedModels();
    }

    /**
     * Whether `$class` carries an `AuditObserver` listener for `$event`.
     *
     * Reads Eloquent's OWN listener table rather than asking the observer, so
     * this answers "is the registration installed", not "is the class on a
     * list somewhere".
     */
    public static function isAudited(string $class, ?string $event = null): bool
    {
        $raw = Model::getEventDispatcher()->getRawListeners();

        foreach ($event === null ? self::events() : [$event] as $one) {
            foreach ($raw['eloquent.'.$one.': '.$class] ?? [] as $listener) {
                if ($listener === AuditObserver::class.'@'.$one) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Register the observer on one model class.
     *
     * Idempotent: the dispatcher appends, so calling this twice for the same
     * class and event would write TWO audit rows for one model write. The
     * check is {@see isAudited()}, not a private static flag, because the
     * flag cannot see a registration somebody else made.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     */
    public static function observe(string $class): void
    {
        foreach (self::events() as $event) {
            if (self::isAudited($class, $event)) {
                continue;
            }

            Event::listen(
                'eloquent.'.$event.': '.$class,
                AuditObserver::class.'@'.$event,
            );
        }
    }

    /**
     * Register the observer over the whole derived scope. Called once, from
     * `AppServiceProvider::boot()`.
     */
    public static function registerAll(): void
    {
        foreach (self::auditedModels() as $class) {
            self::observe($class);
        }
    }
}
