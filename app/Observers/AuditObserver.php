<?php

declare(strict_types=1);

namespace App\Observers;

use App\Services\Audit\AuditLogWriter;
use Illuminate\Database\Eloquent\Model;

/**
 * The global Eloquent observer behind every `audit_log` row.
 *
 * NOTE ON OWNERSHIP: this path is shared with a concurrent todo-43 attempt
 * whose files (`AuditWriter`, `AuditRedactor`, `AuditColumnPolicy`,
 * `AuditScope`, `AuditObserverRegistrar`, `tests/Feature/Audit/*`) are
 * NOT this task's. This observer plus `AuditLogWriter` plus
 * `AuditedModels` are one self-consistent triple committed under
 * `feat(audit)`; see `.omo/evidence/task-43-sehatly.md` for the collision
 * record. Do not mix the two stacks: this observer delegates ONLY to
 * `AuditLogWriter`, never to `AuditWriter`.
 *
 * ONE class observes every sensitive model: `AppServiceProvider` registers
 * it centrally over `AuditedModels::classes()`, so coverage is a policy in
 * one place rather than an attribute to remember on each model. The three
 * handlers map Eloquent events to the DDL `aksi` values (`created` to
 * `create`, `updated` to `update`, `deleted` to `delete`); the remaining
 * DDL values are explicit service writes (`login`/`logout` via the writer,
 * called by the auth controller) or deliberately unused (`read` is the
 * job of `akses_rekam_medis_log` with its own writer - routing reads here
 * would double-log every record access; `download`/`export` have no
 * producer yet and must not be invented by this observer).
 *
 * The handlers NEVER touch the subject beyond the in-memory attribute
 * arrays the event already carries: no query back, no relation load, no
 * model write. That is load-bearing twice over. First, hydrating a
 * `RekamMedis` outside a `RekamMedisReadScope` throws by design, so an
 * observer that re-read its subject would break the write it audits.
 * Second, the writer inserts through the query builder, so no event can
 * recurse back into this observer - and the log tables themselves are not
 * in the audited set, so there is no log-of-log either.
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
