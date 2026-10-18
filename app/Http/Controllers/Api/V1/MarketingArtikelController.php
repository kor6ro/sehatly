<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreArtikelRequest;
use App\Http\Requests\Marketing\UpdateArtikelRequest;
use App\Http\Resources\Marketing\ArtikelResource;
use App\Models\Artikel;
use App\Services\Marketing\ArtikelService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD artikel untuk portal marketing: `GET/POST/PUT/DELETE
 * /api/v1/marketing/artikel`.
 *
 * ## Tiap route carries TWO gates
 *
 * `tipe:marketing` sits on the `marketing` group in `routes/api.php`, so only
 * a `marketing` account (plus `superadmin`, which holds every permission) can
 * reach these routes. The per-route `permission:artikel.kelola` is the
 * catalogue's own code and is granted to `marketing` and `superadmin` only.
 *
 * ## No hard delete
 *
 * `artikel` has no `deleted_at` column (telemedicine_test.sql:1074-1091) and
 * its `status` enum already carries `arsip`, so `DELETE` sets `status =
 * 'arsip'` via {@see ArtikelService::arsipkan()} and never removes the row.
 *
 * ## Thin controller
 *
 * Validation lives in the FormRequests, business rules (including filling
 * `penulis_user_id` from the authenticated account) live in
 * {@see ArtikelService}, and the controller's job is the envelope: request
 * shape in, resource out, Indonesian message.
 */
class MarketingArtikelController extends Controller
{
    public function __construct(private readonly ArtikelService $artikel) {}

    /**
     * `GET /api/v1/marketing/artikel` - daftar artikel, paling baru dulu,
     * paginated dengan `pageMeta()`.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min($perPage, 100);

        $rows = $this->artikel->index()->paginate($perPage)->withQueryString();

        return ApiResponse::success(
            ['artikel' => ArtikelResource::collection($rows->getCollection())],
            'Daftar artikel berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($rows),
        );
    }

    /**
     * `GET /api/v1/marketing/artikel/{id}` - satu artikel.
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            ['artikel' => new ArtikelResource($this->artikel->cari($id))],
            'Detail artikel berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `POST /api/v1/marketing/artikel` - buat artikel baru.
     *
     * `penulis_user_id` diisi dari akun yang terautentikasi, bukan dari body.
     */
    public function store(StoreArtikelRequest $request, Request $httpRequest): JsonResponse
    {
        $artikel = $this->artikel->buat(
            $request->isi(),
            (int) $httpRequest->user()->getKey(),
        );

        return ApiResponse::success(
            ['artikel' => new ArtikelResource($artikel)],
            'Artikel berhasil dibuat.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `PUT /api/v1/marketing/artikel/{id}` - perbarui artikel.
     */
    public function update(UpdateArtikelRequest $request, int $id): JsonResponse
    {
        $artikel = $this->artikel->ubah($this->artikel->cari($id), $request->isi());

        return ApiResponse::success(
            ['artikel' => new ArtikelResource($artikel)],
            'Artikel berhasil diperbarui.',
            Response::HTTP_OK,
        );
    }

    /**
     * `DELETE /api/v1/marketing/artikel/{id}` - arsipkan artikel
     * (`status = 'arsip'`), tidak hard delete.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->artikel->arsipkan($this->artikel->cari($id));

        return ApiResponse::success(
            null,
            'Artikel berhasil diarsipkan.',
            Response::HTTP_OK,
        );
    }
}
