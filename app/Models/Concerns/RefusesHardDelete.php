<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\RekamMedis;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Tests\Feature\Audit\HardDeleteTest;

/**
 * A medical record, and its four children, are never hard-deleted.
 *
 * ## Why a refusal rather than a flag
 *
 * `telemedicine_test.sql:1153` gives
 * `akses_rekam_medis_log.rekam_medis_id` an `ON DELETE CASCADE`, so a hard
 * delete of a `rekam_medis` row destroys the very evidence that the record was
 * accessed. `rekam_medis` declares `dibuat_at` (:648) and `diubah_at` (:649) and
 * no `dihapus_at`, so there is no soft-delete column to fall back to: `delete()`
 * on these models IS a hard delete. The guard has to be a throw, because a
 * `false` return from a `deleting` listener is indistinguishable at the call
 * site from a delete that worked.
 *
 * ## Why the listener is `deleting` and not `forceDeleting`
 *
 * `Illuminate\Database\Eloquent\Concerns\HasEvents` defines static
 * `deleting()`, `created()`, `updated()` and friends, but it does NOT define
 * `forceDeleting()`. That name exists only as a QUERY BUILDER macro contributed
 * by `SoftDeletes`. Writing `static::forceDeleting($callback)` therefore misses
 * every declared method, falls through `Model::__callStatic()`, and
 * `(new static)` inside `__callStatic` re-enters `bootIfNotBooted()` while
 * `static::$booting` is still set - a LogicException at boot for every
 * `artisan` command in the application, not just for a delete.
 *
 * `deleting` is the correct hook anyway: it fires for both the soft and the hard
 * path, so one listener covers `delete()` and, on a model that has no
 * `forceDelete()` of its own, the forwarded `forceDelete()` too.
 *
 * ## Why `forceDelete()` is DECLARED here rather than left to `__call`
 *
 * `SoftDeletes::bootSoftDeletes()` registers `forceDelete` as a GLOBAL builder
 * macro. Once any soft-deleting model in the process has booted - `Pasien` is
 * one - a call to `forceDelete()` on a model that does NOT use `SoftDeletes`
 * resolves through `Model::__call()` to the macro and performs a real DELETE.
 * A declared method wins over `__call` forwarding, which is what makes this
 * refusal unconditional for the record root and all four children.
 *
 * ## What this does NOT claim
 *
 * A query-builder delete (`RekamMedis::query()->where(...)->delete()`) fires no
 * model event, so no observer and no listener can see it. No code path in this
 * repository issues one; closing that hole properly means revoking the DELETE
 * privilege in the database, which is a migration and a privilege grant, not an
 * observer. It is recorded rather than left as an unstated gap.
 *
 * @see RekamMedis
 * @see HardDeleteTest
 */
trait RefusesHardDelete
{
    /**
     * Register the refusal when the model boots.
     *
     * `static::deleting()` is a declared static on `HasEvents`, so this
     * registers a listener without instantiating the model - which matters
     * because this runs during the boot that the instantiation would re-enter.
     */
    public static function bootRefusesHardDelete(): void
    {
        static::deleting(static function (Model $model): void {
            $model->refuseHardDelete('delete()');
        });
    }

    /**
     * A medical record is never hard-deleted. See the trait note.
     *
     * Declared rather than inherited from `__call` so the `SoftDeletes` builder
     * macro cannot be reached for these five models.
     */
    public function forceDelete(): never
    {
        $this->refuseHardDelete('forceDelete()');
    }

    /**
     * Throw, naming the class, the table and the operation that was refused, so
     * a caller who swallows the exception cannot mistake a refused delete for a
     * successful one.
     *
     * @throws LogicException
     */
    protected function refuseHardDelete(string $operation): never
    {
        throw new LogicException(sprintf(
            '%s::%s on %s is refused by %s: a hard delete cascades to '
            .'akses_rekam_medis_log and destroys the record\'s own access evidence.',
            static::class,
            $operation,
            $this->getTable(),
            RefusesHardDelete::class,
        ));
    }
}
