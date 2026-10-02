<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Services\Audit\AuditColumnPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * F14's audit-trail READ, and the only code outside `app/Models/AuditLog.php`
 * that is allowed to name the `AuditLog` model.
 *
 * ## Why this is a service and not a controller query
 *
 * `tests/Feature/Audit/AuditObserverRegistrationTest.php` and
 * `tests/Feature/Audit/ArchitectureTest.php` both assert that NO controller reaches
 * the `AuditLog` MODEL or the `audit_log` table, and the reason they can assert it
 * so bluntly is that before F14 nothing needed to: `audit_log` was write-only, so
 * every access was an `AuditObserver` event. F14 adds the first read surface, and a
 * controller doing the query would have bent both rules for a convenience that
 * nothing else in this codebase enjoys.
 *
 * Putting the read here keeps both properties exactly as they were:
 *
 * - no controller names `AuditLog` or `audit_log`;
 * - the only WRITER is still `AuditLogWriter`, reached through the observer.
 *
 * The audit architecture tests carry ONE documented exemption for this file, and
 * the exemption is for `AuditLog::query()` ONLY - the five write forms
 * (`create`, `updateOrCreate`, `firstOrCreate`, `forceCreate`, `update`) are still
 * refused here exactly as they are everywhere else. "The observer is the only
 * producer" is a stronger claim than "only one file can read the table", and
 * narrowing the rule to the stronger claim is what keeps it true.
 *
 * ## Everything here is a READ
 *
 * There is no `save()`, no `delete()` and no query-builder write in this class. The
 * values are already redacted: `AuditObserver` -> `AuditLogWriter` ->
 * {@see AuditColumnPolicy} dropped denied columns and masked
 * `nomor_str`, `nik`, phones and emails when each row was created, so there is
 * nothing raw here to mask and re-running the policy on the way out would be a
 * second, separately-driftable copy of a decision already made once.
 *
 * `user_id` is a HISTORICAL identifier - a bare column with no foreign key,
 * specifically so a row survives the user's deletion - so no name is joined onto it
 * here or in the resource.
 */
final class AdminAuditLogService
{
    /** Rows per page when `per_page` is absent; the project-wide default. */
    public const PER_PAGE_DEFAULT = 15;

    /**
     * `GET /admin/audit-log` - one page of the trail, newest first.
     *
     * Ordering is `dibuat_at DESC, id DESC`: newest first, and the id tiebreaker
     * makes the order TOTAL. `dibuat_at` is a `TIMESTAMP`, so its resolution is one
     * second; without the tiebreaker two events in the same second could make
     * `LIMIT/OFFSET` repeat or skip a row across a page boundary.
     *
     * The date filters go through {@see RentangHari} because `dibuat_at` is an
     * INSTANT: a WIB day converted to its first and last UTC seconds is what keeps
     * the seven evening hours of the clinic's day inside that day.
     *
     * Every filter is optional and an absent filter is NOT a filter - the whole
     * trail is the default answer, because "no rows" and "you did not ask" are
     * different facts.
     *
     * @param  array<string, mixed>  $filter  the FormRequest's `validated()` output
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function list(array $filter): LengthAwarePaginator
    {
        $query = AuditLog::query();

        if (isset($filter['aksi'])) {
            $query->where('aksi', (string) $filter['aksi']);
        }

        if (isset($filter['tabel_target'])) {
            $query->where('tabel_target', (string) $filter['tabel_target']);
        }

        if (isset($filter['record_id'])) {
            // `record_id` is `VARCHAR(64)` because a composite primary key is
            // stored as `"12|34"` (migration 73), so it is compared as a string
            // and the UI shows it as written rather than parsing the pair.
            $query->where('record_id', (string) $filter['record_id']);
        }

        if (isset($filter['aktor_user_id'])) {
            $query->where('user_id', (int) $filter['aktor_user_id']);
        }

        // Either bound may stand alone; each is that WIB day's own first or last
        // instant, converted once through the project's single wall-clock converter.
        if (isset($filter['dari'])) {
            $query->where('dibuat_at', '>=', RentangHari::mulaiUtc((string) $filter['dari']));
        }

        if (isset($filter['sampai'])) {
            $query->where('dibuat_at', '<=', RentangHari::akhirUtc((string) $filter['sampai']));
        }

        return $query
            ->orderByDesc('dibuat_at')
            ->orderByDesc('id')
            ->paginate((int) ($filter['per_page'] ?? self::PER_PAGE_DEFAULT))
            ->withQueryString();
    }
}
