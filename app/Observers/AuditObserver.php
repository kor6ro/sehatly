<?php

declare(strict_types=1);

namespace App\Observers;

use App\Services\Audit\AuditLogWriter;
use Illuminate\Database\Eloquent\Model;

/**
 * The global Eloquent observer behind every `audit_log` row.
 *
 * ONE class observes every sensitive model. `AppServiceProvider` calls
 * {@see \App\Services\Audit\AuditObserverRegistrar::registerAll()} at boot, and
 * the registrar walks the foreign-key closure outward from `pasien` and
 * `users` in the reference SQL, so coverage is a POLICY in one place rather
 * than an attribute to remember on each model. Nothing in a controller writes
 * an audit row; an architecture test proves it by tokenising every file under
 * `app/Http/Controllers` with comments stripped and asserting the table name
 * appears zero times.
 *
 * The three handlers map Eloquent events to the DDL `aksi` values
 * (`created` to `create`, `updated` to `update`, `deleted` to `delete`). The
 * remaining DDL values are either written explicitly by a service call
 * (`login`/`logout`, which the token API has no event to hook) or deliberately
 * unused: `read` is the job of `akses_rekam_medis_log` with its own writer, and
 * routing reads here would double-log every record access; `download` and
 * `export` have no producer yet and this observer must not invent one.
 *
 * The handlers NEVER touch the subject beyond the in-memory attribute arrays
 * the event already carries: no query back, no relation load, no model write.
 * That is load-bearing twice over. First, hydrating a `RekamMedis` outside a
 * `RekamMedisReadScope` throws by design, so an observer that re-read its
 * subject would break the write it audits. Second, the writer inserts through
 * the query builder, so no event can recurse back into this observer - and the
 * log tables are not in the audited set, so there is no log-of-log either.
 *
 * @see \App\Services\Audit\AuditLogWriter
 * @see \App\Services\Audit\AuditScope
 * @see \App\Services\Audit\AuditColumnPolicy
 */
final class AuditObserver
{
    public function created(Model $model): void
    {
        app(AuditLogWriter::class)->recordCreate($model);
    }

    public function updated(Model $model): void
    {
        app(AuditLogWriter::class)->recordUpdate($model);
    }

    public function deleted(Model $model): void
    {
        app(AuditLogWriter::class)->recordDelete($model);
    }
}
