<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaporanBookingRequest;
use App\Http\Requests\Admin\LaporanKehadiranRequest;
use App\Http\Requests\Admin\LaporanPendapatanRequest;
use App\Http\Requests\Admin\LaporanRangeRequest;
use App\Services\Admin\AdminLaporanService;
use App\Support\ApiResponse;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * F14's reports: `GET /admin/laporan/booking`, `.../pendapatan` and
 * `.../kehadiran`.
 *
 * ## There is NO export endpoint, and that is the owner's decision
 *
 * The F14 pattern's step 13 records it ("tidak ada export di v1"), and the
 * owner's F14 scope repeats it. No CSV, no PDF, no `Content-Disposition`
 * anywhere. The UI renders "Ekspor belum tersedia pada versi ini. Gunakan cetak
 * peramban bila perlu." and this controller deliberately has no route to
 * promise otherwise. If bulk egress is ever added it must be a new,
 * audit-logged `export` action (`audit_log.aksi` already carries the value), not
 * a quietly bolted-on parameter here.
 *
 * ## The responses are AGGREGATES, and clinical content is structurally absent
 *
 * `admin` holds no `rekam_medis.lihat` and no `resep.lihat`
 * ({@see RbacCatalog}), and none of these queries reads a
 * clinical table: a report row is a date, a count and (for revenue) a decimal
 * string. No patient id, no name, no diagnosis, no drug. See
 * {@see AdminLaporanService}'s docblock for the per-report basis and the
 * writer-less `no_show` caveat.
 *
 * ## The guards: `tipe:admin,superadmin` plus `permission:laporan.lihat`
 *
 * `laporan.lihat` is F14's one owner-approved addition to the catalogue: none of
 * the four previously approved codes names an aggregate over `booking`/`invoice`
 * (`dokter.lihat` names a doctor, `jadwal.lihat` a schedule, `audit.lihat` the
 * audit trail, `pdp.kelola` consent). It is granted to `admin` and `superadmin`
 * in {@see RbacCatalog::ROLE_PERMISSIONS}, following the
 * catalogue's own convention. The party gate matters even with the grant: a
 * future role granted `laporan.lihat` must still be an admin account to reach
 * these numbers.
 *
 * ## `dari`/`sampai` are required, WIB, and capped at 366 days
 *
 * See {@see LaporanRangeRequest}: a missing or
 * malformed range is a 422, a reversed one is a 422 on `sampai`, and a span
 * longer than 366 days is refused so the aggregate queries stay bounded. An
 * empty range answers zeros and an empty `harian` list - a quiet week is a real
 * report.
 *
 * @see AdminLaporanService for the query basis of each report
 */
class AdminLaporanController extends Controller
{
    public function __construct(
        private readonly AdminLaporanService $laporan,
    ) {}

    /**
     * `GET /api/v1/admin/laporan/booking?dari&sampai&dokter_id?`
     *
     * `data.ringkasan.per_status` carries all eight DDL statuses (zeros
     * included) so the client's table has a fixed shape; `data.harian[]` carries
     * only the days with activity, ordered ascending.
     */
    public function booking(LaporanBookingRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->laporan->booking($request->validated()),
            'Laporan booking berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `GET /api/v1/admin/laporan/pendapatan?dari&sampai`
     *
     * Settled revenue: `invoice.total` summed over `status = 'lunas'`, days
     * grouped on the WIB calendar day of `lunas_at`. A refunded invoice is
     * `refund_penuh` and is therefore excluded - the figure is money the clinic
     * keeps, not money it once captured. Every money field is a DECIMAL STRING
     * with two places.
     */
    public function pendapatan(LaporanPendapatanRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->laporan->pendapatan($request->validated()),
            'Laporan pendapatan berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `GET /api/v1/admin/laporan/kehadiran?dari&sampai`
     *
     * Counts of `check_in`, `selesai` and `no_show`. `no_show` has no writer in
     * the application today, so its count is always zero and the key is
     * published anyway with that meaning documented rather than hidden - the day
     * a writer ships, this endpoint reports it with no contract change.
     */
    public function kehadiran(LaporanKehadiranRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->laporan->kehadiran($request->validated()),
            'Laporan kehadiran berhasil dimuat.',
            Response::HTTP_OK,
        );
    }
}
