<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kurir\AmbilPesananRequest;
use App\Http\Requests\Kurir\AntarPesananRequest;
use App\Http\Resources\Kurir\KurirPesananResource;
use App\Services\Kurir\KurirPesananService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The courier portal's four delivery routes - the surface plan section 5.2
 * asked for, and the one F46's order read deliberately refuses to be.
 *
 * ## Both gates on every route, and neither is the other's substitute
 *
 * The `tipe:kurir` party gate sits on the `kurir` group in `routes/api.php`
 * (superadmin is NOT in it, unlike the admin and marketing groups: a delivery
 * confirmation is an OPERATIONAL act performed by the party that carried the
 * parcel, and an oversight account confirming deliveries it never carried would
 * be writing a fact about the world it has no means to know - the F14 group
 * includes superadmin because editing a banner is a content decision, which is
 * a different kind of act). The per-route `permission:` is the catalogue's own:
 * the reads and the pickup carry `pesanan.update` (the courier's workflow-state
 * code) and the delivery confirmation carries `pesanan.antar` (named after the
 * act). Both codes were added to `RbacCatalog` by update 1 for exactly these
 * routes; `pesanan.lihat` remains withheld, which is why this module exists
 * instead of a widened `GET /pesanan-obat/{id}`.
 *
 * ## Thin controller, on purpose
 *
 * Every refusal, every default and the transition itself live in
 * {@see KurirPesananService} and - above it - `PesananObatService::ubahStatus()`
 * with the ONE state machine. The controller's whole job is the envelope:
 * request shape in, resource out, the plan's Indonesian message texts.
 */
class KurirController extends Controller
{
    public function __construct(private readonly KurirPesananService $kurir) {}

    /**
     * `GET /api/v1/kurir/pesanan` - the delivery queue.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ['pesanan' => KurirPesananResource::collection($this->kurir->antrean())],
            'Antrean pengantaran berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `GET /api/v1/kurir/pesanan/{id}` - one order and its tracking trail.
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            ['pesanan' => new KurirPesananResource($this->kurir->cari($id))],
            'Detail pesanan berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `PUT /api/v1/kurir/pesanan/{id}/ambil` - claim: `siap -> sedang_dikirim`.
     */
    public function ambil(AmbilPesananRequest $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            ['pesanan' => new KurirPesananResource($this->kurir->ambil($id, $request->isi()))],
            'Pesanan berhasil diambil dan sedang dikirim.',
            Response::HTTP_OK,
        );
    }

    /**
     * `PUT /api/v1/kurir/pesanan/{id}/antar` - confirm: `sedang_dikirim -> selesai`.
     */
    public function antar(AntarPesananRequest $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            ['pesanan' => new KurirPesananResource($this->kurir->antar($id, $request->isi()))],
            'Pesanan berhasil ditandai selesai.',
            Response::HTTP_OK,
        );
    }
}
