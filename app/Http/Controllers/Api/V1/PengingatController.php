<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pengingat\IndexPengingatRequest;
use App\Http\Requests\Pengingat\StorePengingatRequest;
use App\Http\Requests\Pengingat\UpdatePengingatRequest;
use App\Http\Resources\PengingatResource;
use App\Models\User;
use App\Services\Notifikasi\PengingatService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The F11 reminder surface: list, create, update and delete the caller's own
 * `pengingat` rows.
 *
 * | route | verb | `data` | guard |
 * | --- | --- | --- | --- |
 * | `/api/v1/pengingat` | GET | `{pengingat}` + `meta` | `notifikasi.lihat` |
 * | `/api/v1/pengingat` | POST | `{pengingat}` | `notifikasi.lihat` |
 * | `/api/v1/pengingat/{id}` | PUT | `{pengingat}` | `notifikasi.lihat` |
 * | `/api/v1/pengingat/{id}` | DELETE | `{dihapus}` | `notifikasi.lihat` |
 *
 * `permission:notifikasi.lihat` is the same guard the inbox uses, on purpose:
 * a reminder is a personal notification artefact, and the permission is
 * withheld from `perawat`/`kurir` (who hold no role at all) exactly as the
 * inbox is. A separate code would need a catalogue and re-seed change for no
 * behavioural difference.
 *
 * ## Foreign ids are 404, never 403
 *
 * Every lookup runs through `PengingatService::untukUser()`, which scopes on
 * `user_id` and raises `ModelNotFoundException`; `bootstrap/app.php` renders
 * that as the standard 404 envelope. A 403 would confirm that another account's
 * sequential id exists.
 *
 * ## DELETE has no body, so it takes `Request` rather than a FormRequest
 *
 * The Definition of Done requires a FormRequest for POST/PUT/PATCH; DELETE has
 * nothing to validate beyond the numeric route segment (`whereNumber`), and the
 * write is a row the caller's own `user_id` selected.
 */
class PengingatController extends Controller
{
    public function __construct(
        private readonly PengingatService $service,
    ) {}

    /**
     * `GET /api/v1/pengingat` - the caller's reminders, newest start first.
     */
    public function index(IndexPengingatRequest $request): JsonResponse
    {
        $paginator = $this->service->daftar($this->user($request), $request->validated());

        return ApiResponse::success(
            ['pengingat' => PengingatResource::collection($paginator->getCollection())],
            'Daftar pengingat berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($paginator),
        );
    }

    /**
     * `POST /api/v1/pengingat` - create one.
     */
    public function store(StorePengingatRequest $request): JsonResponse
    {
        $pengingat = $this->service->buat($this->user($request), $request->validated());

        return ApiResponse::success(
            ['pengingat' => new PengingatResource($pengingat)],
            'Pengingat berhasil dibuat.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `PUT /api/v1/pengingat/{id}` - partial update, 404 for another account's
     * row.
     */
    public function update(UpdatePengingatRequest $request, int $id): JsonResponse
    {
        $pengingat = $this->service->ubah($this->user($request), $id, $request->validated());

        return ApiResponse::success(
            ['pengingat' => new PengingatResource($pengingat)],
            'Pengingat berhasil diperbarui.',
            Response::HTTP_OK,
        );
    }

    /**
     * `DELETE /api/v1/pengingat/{id}` - hard delete, 404 for another account's
     * row.
     *
     * The schema gives `pengingat` no soft-delete column, so this is a real
     * delete; `pengingat_terkirim` cascades, and a `notifikasi` row the ledger
     * already wrote is kept (the ledger RESTRICTs on `notifikasi_id`), because
     * an inbox item that was delivered is a fact about the past.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->hapus($this->user($request), $id);

        return ApiResponse::success(
            ['dihapus' => true],
            'Pengingat berhasil dihapus.',
            Response::HTTP_OK,
        );
    }

    /**
     * The authenticated `User`, narrowed for the static analyser.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
