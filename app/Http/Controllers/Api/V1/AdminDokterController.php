<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminDokterRequest;
use App\Http\Requests\Admin\StatusDokterRequest;
use App\Http\Requests\Admin\VerifikasiDokterRequest;
use App\Http\Resources\AdminDokterResource;
use App\Services\Admin\AdminDokterService;
use App\Support\ApiResponse;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * F14's doctor-management surface: `GET /admin/dokter`,
 * `GET /admin/dokter/{id}`, `PUT /admin/dokter/{id}/verifikasi` and
 * `PUT /admin/dokter/{id}/status`.
 *
 * ## This is the admin directory the public `DokterController` is NOT
 *
 * `DokterController` serves the patient-facing directory and deliberately hides
 * every doctor who is unverified, inactive, opted out of telemedicine, STR-
 * expired or whose account is soft-deleted. That is the opposite of this
 * controller's job. The two therefore share NO query: the reads here go through
 * {@see AdminDokterService}, which reads `dokter` directly, and the class
 * docblock there explains why reusing `DokterDirectoryService` would make one of
 * the two surfaces lie.
 *
 * ## The guards: `tipe:admin,superadmin` on every route, plus the read grant where it exists
 *
 * | route | `tipe:` | `permission:` |
 * | --- | --- | --- |
 * | `GET /admin/dokter` | `admin,superadmin` | `dokter.lihat` |
 * | `GET /admin/dokter/{id}` | `admin,superadmin` | `dokter.lihat` |
 * | `PUT /admin/dokter/{id}/verifikasi` | `admin,superadmin` | none |
 * | `PUT /admin/dokter/{id}/status` | `admin,superadmin` | none |
 *
 * The READ half reuses `dokter.lihat`, which is exactly the code the catalogue
 * reserved for "an administrative directory that is supposed to list unverified,
 * inactive and STR-expired doctors" - `DokterController`'s docblock names this
 * endpoint as that code's intended consumer, and F14 is where that reservation
 * is honoured. The party gate is not redundant with it: `dokter.lihat` is also
 * held by `pasien`, `dokter` and `apoteker`, so without `tipe:admin,superadmin`
 * every patient could read the credential state of every doctor. Both gates are
 * required, and the admin-only party is the narrower one.
 *
 * The WRITE half carries the party gate and NO `permission:`. That is the
 * deliberate outcome of the owner-approved F14 scope: the approved codes are
 * `dokter.lihat`, `jadwal.lihat`, `audit.lihat` and `pdp.kelola` - all READ
 * codes plus the PDP management code - and the F14 pattern's proposed
 * `dokter.kelola` was NOT approved. Reusing `dokter.lihat` on a mutation would
 * contradict the `<resource>.<aksi>` naming the owner applied when deciding
 * `laporan.lihat` (a read code names a read), so the write is authorised by the
 * account type the owner named as the audience - `admin` and `superadmin` -
 * rather than by a code that would have to mean something it does not say.
 * If a finer grant is ever wanted, adding `dokter.kelola` to
 * {@see RbacCatalog} and this route is a data change plus one
 * middleware string; it is recorded as open in the F14 report rather than
 * smuggled in here.
 *
 * ## What a write answers
 *
 * Both writes answer `data.dokter` (the SAME projection a GET produces) plus
 * `data.dampak`, because the UI's next question after a decision is always "and
 * what does this leave behind?" - the F14 blast-radius rule. The row is re-read
 * through the admin projection rather than returned from the write, so a
 * response is never a differently-shaped model than the one the list publishes.
 *
 * ## `{id}` is a number, and an absent one is the uniform 404
 *
 * `dokter.id` is `BIGINT UNSIGNED AUTO_INCREMENT` (`:410`), so `whereNumber`
 * makes a non-numeric segment a router 404 before this class runs, and a numeric
 * id that names no row answers the project's standard
 * `{"success":false,"message":"Resource not found.","errors":{}}` body - the
 * same body `DokterController` uses, so the status cannot become an existence
 * oracle here either.
 */
class AdminDokterController extends Controller
{
    public function __construct(
        private readonly AdminDokterService $dokter,
    ) {}

    /**
     * `GET /api/v1/admin/dokter`
     *
     * Filters `q`, `status_verifikasi`, `status_aktif`, `tersedia_telemedisin`
     * and `urutan`, paged with the project `meta` block. The default order is
     * `str_berlaku_sampai ASC` - the credential worklist - and every branch
     * appends `id` so the order is total and pages are disjoint.
     */
    public function index(IndexAdminDokterRequest $request): JsonResponse
    {
        $paginator = $this->dokter->list($request->validated());

        return ApiResponse::success(
            ['dokter' => AdminDokterResource::collection($paginator->getCollection())],
            'Daftar dokter berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($paginator),
        );
    }

    /**
     * `GET /api/v1/admin/dokter/{id}`
     *
     * The full credential projection plus `dampak`. `dampak` is ALWAYS present
     * rather than gated behind the pattern's `?include=dampak`: it is three
     * aggregate counts, the detail surface has exactly one audience (an admin
     * about to decide something), and a query parameter that changes only
     * whether a cheap field is present is a shape a client can get wrong for no
     * benefit. The F14 UI's `?include=dampak` call therefore receives it too.
     */
    public function show(int $id): JsonResponse
    {
        $dokter = $this->dokter->find($id);

        if ($dokter === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        return ApiResponse::success([
            'dokter' => new AdminDokterResource($dokter),
            'dampak' => $this->dokter->dampak($dokter),
        ], 'Detail dokter berhasil dimuat.');
    }

    /**
     * `PUT /api/v1/admin/dokter/{id}/verifikasi`
     *
     * Body: `{status_verifikasi: 'terverifikasi'|'ditolak'}`. A row that is no
     * longer `pending` is refused with a **422** on `status_verifikasi` - the
     * F14 pattern's rule for two admins deciding the same doctor - so the losing
     * client reloads instead of overwriting the winner's decision. The audit row
     * is written by the global observer inside the service's transaction.
     */
    public function verifikasi(VerifikasiDokterRequest $request, int $id): JsonResponse
    {
        /** @var string $status */
        $status = $request->validated()['status_verifikasi'];

        $this->dokter->verifikasi($id, $status);

        $dokter = $this->dokter->find($id);

        // The service re-reads under a lock, so the row cannot have vanished
        // between the write and this read in any realistic case; a null here is
        // still answered as the uniform 404 rather than as a resource over null.
        if ($dokter === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        return ApiResponse::success([
            'dokter' => new AdminDokterResource($dokter),
            'dampak' => $this->dokter->dampak($dokter),
        ], 'Status dokter diperbarui.');
    }

    /**
     * `PUT /api/v1/admin/dokter/{id}/status`
     *
     * Body: `{status_aktif: bool, tersedia_telemedisin?: bool}`. Suspending a
     * doctor is NOT blocked when bookings exist, and no booking is cancelled:
     * the F14 owner decision is that a suspect credential must be stoppable
     * immediately and that the blast radius (`data.dampak.booking_aktif`) is
     * shown rather than acted on. `tersedia_telemedisin` is written only when
     * supplied, so the two flags stay independent.
     */
    public function status(StatusDokterRequest $request, int $id): JsonResponse
    {
        $valid = $request->validated();

        $this->dokter->ubahStatus(
            $id,
            (bool) $valid['status_aktif'],
            array_key_exists('tersedia_telemedisin', $valid)
                ? (bool) $valid['tersedia_telemedisin']
                : null,
        );

        $dokter = $this->dokter->find($id);

        if ($dokter === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        return ApiResponse::success([
            'dokter' => new AdminDokterResource($dokter),
            'dampak' => $this->dokter->dampak($dokter),
        ], 'Status dokter diperbarui.');
    }
}
