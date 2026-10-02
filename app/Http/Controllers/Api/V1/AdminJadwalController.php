<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminJadwalRequest;
use App\Http\Requests\Admin\StoreJadwalRequest;
use App\Http\Requests\Admin\StoreLiburRequest;
use App\Http\Requests\Admin\UpdateJadwalRequest;
use App\Http\Resources\AdminJadwalResource;
use App\Http\Resources\AdminLiburResource;
use App\Services\Admin\AdminJadwalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * F14's schedule surface: `dokter_jadwal` CRUD and `dokter_libur` CRUD.
 *
 * ## Seven routes, and the paths follow the F14 contract
 *
 * | route | writes |
 * | --- | --- |
 * | `GET /admin/dokter/{id}/jadwal` | nothing - the weekly template, drafts included |
 * | `POST /admin/dokter/{id}/jadwal` | one row per requested weekday, atomically |
 * | `PUT /admin/jadwal/{id}` | one row, wholly or partially (publish/unpublish) |
 * | `DELETE /admin/jadwal/{id}` | one row, refused while any booking references it |
 * | `GET /admin/dokter/{id}/libur` | nothing - the leave dates |
 * | `POST /admin/dokter/{id}/libur` | one whole-day leave date |
 * | `DELETE /admin/libur/{id}` | one leave date |
 *
 * The two collection routes live under the DOCTOR they belong to
 * (`/admin/dokter/{id}/...`) because that is the resource tree the F14 contract
 * publishes and because the doctor id comes from the path, never the body; the
 * row-addressed routes live under the resource they address
 * (`/admin/jadwal/{id}`, `/admin/libur/{id}`). `dokter_jadwal` and `dokter_libur`
 * are two tables with two different lifecycles - a window is editable and
 * publishable, a leave date is only ever added or removed - so there is no
 * merged `/admin/jadwal` CRUD shape that would fit both.
 *
 * ## The guards: `tipe:admin,superadmin` everywhere, `jadwal.lihat` on the reads
 *
 * `jadwal.lihat` is the approved read code; it is also held by `pasien` and
 * `dokter`, so the party gate is what keeps the ADMIN view of drafts, validity
 * windows and leave dates off every non-admin account. The write routes carry
 * the party gate and NO `permission:` because the owner approved no write code -
 * see {@see AdminDokterController}'s docblock for the full argument, which is
 * the same decision on both doctor and schedule surfaces.
 *
 * ## What DELETE means, and what it refuses
 *
 * `booking.jadwal_id` is a nullable FK to `dokter_jadwal` with no delete rule,
 * so MySQL treats it as `RESTRICT`: a window ANY booking points at cannot be
 * deleted, even after every one of those bookings is cancelled or finished. The
 * delete action therefore answers **422** with the total referenced count and
 * the future-consuming subset, and the F14 UI turns that into the "Nonaktifkan
 * saja" path. The leave delete has no such constraint: nothing references
 * `dokter_libur`, so it is a hard delete.
 *
 * ## `{id}` on both resources is a number
 *
 * `dokter.id`, `dokter_jadwal.id` and `dokter_libur.id` are all
 * `BIGINT UNSIGNED AUTO_INCREMENT`, so `whereNumber` makes a non-numeric
 * segment a router 404 and a numeric-but-absent one the project's uniform 404
 * envelope. The controller methods take `int`, so no string ever reaches a
 * query as an identifier.
 */
class AdminJadwalController extends Controller
{
    public function __construct(
        private readonly AdminJadwalService $jadwal,
    ) {}

    /**
     * `GET /api/v1/admin/dokter/{id}/jadwal`
     *
     * The weekly template, drafts included unless `?status_aktif=` narrows it.
     * Each row carries `booking_aktif`, one grouped count for the whole list.
     * Deliberately unpaginated: a weekly template is at most a handful of rows
     * per day, and the F14 screen renders the whole week; the response uses the
     * project's single-page `meta` so a client parses one list shape.
     */
    public function index(IndexAdminJadwalRequest $request, int $id): JsonResponse
    {
        $baris = $this->jadwal->list($id, $request->validated());

        return ApiResponse::success(
            ['jadwal' => AdminJadwalResource::collection($baris)],
            'Jadwal dokter berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::singlePageMeta($baris->count()),
        );
    }

    /**
     * `POST /api/v1/admin/dokter/{id}/jadwal`
     *
     * Body: `{hari: int[] 0-6, tipe_layanan, faskes_id?, jam_mulai, jam_selesai,
     * durasi_slot_menit, kuota_per_sesi?, berlaku_mulai, berlaku_sampai?,
     * status_aktif}` - one row per `hari` entry, all inside one transaction. A
     * conflict on any day refuses the WHOLE request with a 422 on `hari`, before
     * anything is written, so the multi-day form keeps its contents.
     *
     * 201 with the created rows; `data.jadwal` is a list even for one day, so a
     * client has one shape to parse.
     */
    public function store(StoreJadwalRequest $request, int $id): JsonResponse
    {
        $baris = $this->jadwal->store($id, $request->validated());

        return ApiResponse::success(
            ['jadwal' => AdminJadwalResource::collection($baris)],
            'Jadwal disimpan.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `PUT /api/v1/admin/jadwal/{id}`
     *
     * A partial body is the publish/unpublish action as well as the edit form;
     * the service merges over the stored row and validates the result, so moving
     * a window onto an occupied slot is a 422 naming `hari` regardless of which
     * fields the request carried.
     */
    public function update(UpdateJadwalRequest $request, int $id): JsonResponse
    {
        $baris = $this->jadwal->update($id, $request->validated());

        return ApiResponse::success(
            ['jadwal' => new AdminJadwalResource($baris)],
            'Jadwal disimpan.',
        );
    }

    /**
     * `DELETE /api/v1/admin/jadwal/{id}`
     *
     * 200 with `{deleted: true, id}` when the row is gone; 422 with
     * `errors.jadwal` naming the referenced-booking count when it is not. The
     * schema's FK is why the refusal exists - see the class docblock.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->jadwal->destroy($id);

        return ApiResponse::success(
            ['deleted' => true, 'id' => $id],
            'Jadwal berhasil dihapus.',
        );
    }

    /**
     * `GET /api/v1/admin/dokter/{id}/libur`
     *
     * Every leave date on file, oldest first. Unpaginated for the same reason
     * the schedule list is: a doctor's leave calendar is short and the tab shows
     * it whole.
     */
    public function liburIndex(int $id): JsonResponse
    {
        $baris = $this->jadwal->listLibur($id);

        return ApiResponse::success(
            ['libur' => AdminLiburResource::collection($baris)],
            'Daftar libur dokter berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::singlePageMeta($baris->count()),
        );
    }

    /**
     * `POST /api/v1/admin/dokter/{id}/libur`
     *
     * Body: `{tanggal: Y-m-d, alasan?: string <= 200}` - whole-day only. A date
     * already on file is a 422 on `tanggal` naming the duplicate, which is the
     * application invariant the schema does not enforce.
     */
    public function liburStore(StoreLiburRequest $request, int $id): JsonResponse
    {
        $libur = $this->jadwal->storeLibur($id, $request->validated());

        return ApiResponse::success(
            ['libur' => new AdminLiburResource($libur)],
            'Libur ditambahkan.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `DELETE /api/v1/admin/libur/{id}`
     *
     * Nothing references a leave row, so this is a hard delete with no guard to
     * report.
     */
    public function liburDestroy(int $id): JsonResponse
    {
        $this->jadwal->destroyLibur($id);

        return ApiResponse::success(
            ['deleted' => true, 'id' => $id],
            'Libur berhasil dihapus.',
        );
    }
}
