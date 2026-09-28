<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait that refuses any hard delete on a medical record.
 *
 * Medical records and their four children must never be hard deleted. Both
 * `delete()` and `forceDelete()` throw a `LogicException` so that a caller
 * cannot silently remove a record that must remain auditable.
 *
 * The exception message names the class and the reason so that a caller who
 * ignores the exception cannot mistake a refused delete for a successful one.
 *
 * @see \App\Models\RekamMedis
 * @see \Tests\Feature\Audit\HardDeleteTest
 */
trait RefusesHardDelete
{
    /**
     * Register the refusal handlers when the model boots.
     *
     * @return void
     */
    public static function bootRefusesHardDelete(): void
    {
        static::deleting(function (Model $model): void {
            $model->stopHardDelete();
        });

        static::forceDeleting(function (Model $model): void {
            $model->stopHardDelete();
        });
    }

/**
 * Called by the boot listeners above; throws so the delete/force-delete
 * never reaches the database.
 *
 * @return void
 * @throws LogicException
 */
protected function stopHardDelete(): void
{
    $class = static::class;
    $table = $this->getTable();

    throw new LogicException(
        "{$class}::delete() on {$table} is refused by RefusesHardDelete; medical records must never be hard deleted."
    );
}
}