<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Observers\AuditObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * The central registrar for the audit observer.
 *
 * There is exactly one place that calls `observe()` for every person-scoped
 * model: this class's `registerAll()` method, invoked from
 * `AppServiceProvider::boot()`. No per-model `#[ObservedBy]` attribute exists
 * anywhere in the codebase — all coverage is policy-driven from one spot.
 *
 * The derived scope (44 tables, 44 model classes) is computed by
 * `AuditScope` from the foreign-key closure in `telemedicine_test.sql`; the
 * registrar iterates that list and registers one observer per class.
 *
 * Direction 3 from the test suite is closed by this mechanism: a new model
 * added to the closure is automatically covered the day it is written, without
 * any edit to the model itself.
 *
 * @see \App\Services\Audit\AuditScope
 * @see \App\Observers\AuditObserver
 */
final class AuditObserverRegistrar
{
    /**
     * The list of model classes that fall within the person-scope closure.
     *
     * @return list<string>
     */
    public static function auditedModels(): array
    {
        return AuditScope::auditedModels();
    }

    /**
     * Query the event dispatcher to discover whether a given model class has
     * an `eloquent.created/updated/deleted/forceDeleted: <class>` listener
     * that points at `App\Observers\AuditObserver@`.
     *
     * @return bool true when the class is audited
     */
    public static function isAudited(string $class): bool
    {
        $dispatcher = Model::getEventDispatcher();

        foreach (['created', 'updated', 'deleted', 'forceDeleted'] as $event) {
            $listeners = $dispatcher->getRawListeners()['eloquent.'.$event.': '.$class] ?? [];

            foreach ($listeners as $listener) {
                if (is_string($listener) && str_starts_with($listener, AuditObserver::class.'@')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Register the observer for a single model class by wiring an Eloquent
     * event listener.
     *
     * @param string $class Fully-qualified model class name
     */
    public static function observe(string $class): void
    {
        $dispatcher = Model::getEventDispatcher();

        foreach (['created', 'updated', 'deleted', 'forceDeleted'] as $event) {
            Event::listen(
                Model::class.'@'.$event,
                fn ($model) => app(AuditObserver::class)->{$event}($model),
                0,
                $class
            );
        }
    }

    /**
     * Register the observer for every model in the derived person-scope.
     * This is called once from `AppServiceProvider::boot()`.
     *
     * @see auditedModels()
     * @see observe()
     * @see isAudited()
     */
    public static function registerAll(): void
    {
        foreach (self::auditedModels() as $class) {
            self::observe($class);
        }
    }
}