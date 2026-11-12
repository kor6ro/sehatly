<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faskes\IndexFaskesRequest;
use App\Http\Resources\FaskesResource;
use App\Services\Faskes\FaskesDirectoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /faskes` - the public venue list, read by the landing page's
 * "Apotek Terdekat" menu and by anything else that needs to show nearby clinics.
 *
 * ## Why this route carries neither `permission:` nor `tipe:`
 *
 * It answers the same audience question `GET /dokter`, `GET /master-spesialisasi` and
 * `GET /hero` answer: content a signed-out visitor needs before anybody has an
 * account. `permission:` resolves through `EnsurePermission`, which answers 401 for an
 * anonymous caller - the exact person the menu is for - and `tipe:` would put a
 * pharmacy directory behind a login. The audience decision that matters is on the
 * write side, and this module has no write side: venues are entered by an `admin`
 * through the admin surface, not by a `POST` here.
 *
 * ## What it answers, and what it deliberately does not
 *
 * Only `status_aktif = 1` rows of one `tipe`, ordered by distance when the caller
 * supplied a position and by name when they did not. It never answers a venue's
 * inventory, its verifications or anything from `apotek_stok` - those live behind
 * `GET /obat/{id}/stok` and the prescription flows, which are gated for reasons that
 * apply to a patient's own data rather than to a shopfront sign.
 *
 * An empty `data.faskes: []` is a valid answer: a schema-only database, or one where
 * every pharmacy has been switched off, has nothing to list, and the panel renders
 * its empty state rather than an error.
 */
class FaskesController extends Controller
{
    public function __construct(
        private readonly FaskesDirectoryService $faskes,
    ) {}

    /**
     * `GET /api/v1/faskes`
     *
     * Filters: `tipe` (5 values, defaults to `apotek`), `lat`/`lng` as one optional
     * pair that switches the order to nearest-first and adds `jarak_km` to every row.
     * Pagination: `page`, `per_page` capped at 100.
     */
    public function index(IndexFaskesRequest $request): JsonResponse
    {
        $paginator = $this->faskes->list($request->validated());

        return ApiResponse::success(
            ['faskes' => FaskesResource::collection($paginator->getCollection())],
            'Daftar faskes berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($paginator),
        );
    }
}
