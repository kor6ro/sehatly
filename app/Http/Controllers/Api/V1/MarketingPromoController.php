<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StorePromoRequest;
use App\Http\Requests\Marketing\UpdatePromoRequest;
use App\Http\Resources\Marketing\PromoResource;
use App\Services\Marketing\PromoService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD promo untuk portal marketing: `GET/POST/PUT/DELETE
 * /api/v1/marketing/promo`.
 *
 * ## Tiap route carries TWO gates
 *
 * `tipe:marketing` sits on the `marketing` group in `routes/api.php`, so only
 * a `marketing` account (plus `superadmin`, which holds every permission) can
 * reach these routes. The per-route `permission:promo.kelola` is the
 * catalogue's own code and is granted to `marketing` and `superadmin` only.
 *
 * ## No hard delete
 *
 * `master_promo` has no `deleted_at` column and no `status` column; its only
 * switch is `status_aktif` TINYINT(1) (telemedicine_test.sql:985-998). So
 * `DELETE` sets `status_aktif = 0` via {@see PromoService::nonaktifkan()} and
 * never removes the row.
 *
 * ## Thin controller
 *
 * Validation lives in the FormRequests, business rules live in
 * {@see PromoService}, and the controller's job is the envelope: request
 * shape in, resource out, Indonesian message.
 */
class MarketingPromoController extends Controller
{
    public function __construct(private readonly PromoService $promo) {}

    /**
     * `GET /api/v1/marketing/promo` - daftar promo, paling baru dulu,
     * paginated dengan `pageMeta()`.
     */
    public function index(): JsonResponse
    {
        $rows = $this->promo->index()->paginate(15)->withQueryString();

        return ApiResponse::success(
            ['promo' => PromoResource::collection($rows->getCollection())],
            'Daftar promo berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($rows),
        );
    }

    /**
     * `GET /api/v1/marketing/promo/{id}` - satu promo.
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            ['promo' => new PromoResource($this->promo->cari($id))],
            'Detail promo berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `POST /api/v1/marketing/promo` - buat promo baru.
     */
    public function store(StorePromoRequest $request): JsonResponse
    {
        $promo = $this->promo->buat($request->isi());

        return ApiResponse::success(
            ['promo' => new PromoResource($promo)],
            'Promo berhasil dibuat.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `PUT /api/v1/marketing/promo/{id}` - perbarui promo.
     */
    public function update(UpdatePromoRequest $request, int $id): JsonResponse
    {
        $promo = $this->promo->ubah($this->promo->cari($id), $request->isi());

        return ApiResponse::success(
            ['promo' => new PromoResource($promo)],
            'Promo berhasil diperbarui.',
            Response::HTTP_OK,
        );
    }

    /**
     * `DELETE /api/v1/marketing/promo/{id}` - nonaktifkan promo
     * (`status_aktif = 0`), tidak hard delete.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->promo->nonaktifkan($this->promo->cari($id));

        return ApiResponse::success(
            null,
            'Promo berhasil dinonaktifkan.',
            Response::HTTP_OK,
        );
    }
}
