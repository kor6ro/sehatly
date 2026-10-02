<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAuditLogRequest;
use App\Http\Resources\AdminAuditLogResource;
use App\Services\Admin\AdminAuditLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /admin/audit-log` - the read surface of the append-only audit trail.
 *
 * ## READ-ONLY, and the absence of writes is a schema fact
 *
 * `audit_log` has **no `diubah_at`** (telemedicine_test.sql:1129 is its only
 * timestamp) - the schema removes the possibility of an update timestamp because an
 * audit row is evidence and rewriting one destroys the thing the table exists for
 * (migration 73's docblock). There is therefore exactly ONE route on this resource,
 * a `GET`; no POST, PUT, PATCH or DELETE is registered, and no future one should be.
 * The F14 pattern states it as "0 tombol ubah/hapus/export di layar", and the route
 * table is where that promise is kept rather than in the UI.
 *
 * **No export route either.** The owner's F14 scope forbids one, and the same
 * argument applies with more force here: `audit_log` is the one table whose bulk
 * egress would itself need an audit row (`audit_log.aksi` already carries the
 * `export` value for whoever adds it deliberately).
 *
 * ## Why the query lives in a SERVICE, and that is load-bearing
 *
 * This controller does not name `AuditLog` or `audit_log`, and that is not
 * tidiness - it is an invariant two audit architecture tests assert over the whole
 * of `app/Http/Controllers`: before F14 `audit_log` was write-only, so no
 * controller had any reason to reach it, and the rules were written to say so.
 * {@see AdminAuditLogService} holds the read; it is the one documented exemption
 * in those tests, and the exemption is for `AuditLog::query()` alone - the five
 * WRITE forms remain impossible everywhere, including there. The writer is still
 * the observer, which is the claim those tests actually care about.
 *
 * ## The values are the STORED ones, already redacted at write time
 *
 * Nothing here masks anything, because nothing raw is stored:
 * `AuditObserver` -> `AuditLogWriter` -> `AuditColumnPolicy` ran when the row was
 * created, and the policy DROPS denied columns and MASKS `nomor_str`, `nik`, phones
 * and emails before the JSON reaches the database. A reader therefore cannot leak
 * what the writer never held; see {@see AdminAuditLogResource}. The F14 AC-10 check
 * ("a `nomor_str` in a summary reads masked") is proved against this endpoint in the
 * F14 feature tests, and it is a statement about the writer.
 *
 * ## Filters: actor, table/model, action, date range - all server-side
 *
 * | filter | basis |
 * | --- | --- |
 * | `aktor_user_id` | `audit_log.user_id`, the HISTORICAL id (bare column, no FK) |
 * | `tabel_target`, `record_id` | the record the event named, as strings (`record_id` is `VARCHAR(64)` because composite keys are stored `"12\|34"`) |
 * | `aksi` | the DDL's eight values |
 * | `dari`/`sampai` | WIB dates converted to UTC instants, because `dibuat_at` is an instant |
 *
 * ## The guards
 *
 * `tipe:admin,superadmin` plus `permission:audit.lihat`. The permission alone is
 * granted only to `admin` and `superadmin` today, and the party gate keeps it that
 * way even if the grant is widened to a role with another account type.
 * `audit.lihat` is deliberately separated from every clinical write in
 * `RbacCatalog`; this surface reads no clinical table at all.
 */
class AdminAuditLogController extends Controller
{
    public function __construct(
        private readonly AdminAuditLogService $audit,
    ) {}

    /**
     * `GET /api/v1/admin/audit-log`
     *
     * One page of the trail, newest first, with the project `meta` block.
     * `per_page` is capped at 100 by `IndexAuditLogRequest`.
     */
    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        $paginator = $this->audit->list($request->validated());

        return ApiResponse::success(
            ['audit' => AdminAuditLogResource::collection($paginator->getCollection())],
            'Jejak audit berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($paginator),
        );
    }
}
