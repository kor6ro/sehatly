<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Obat\SearchObatRequest;
use App\Http\Requests\Resep\StoreResepRequest;
use App\Http\Resources\MasterObatResource;
use App\Http\Resources\ResepResource;
use App\Models\User;
use App\Services\Obat\ObatSearchService;
use App\Services\Resep\ResepService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two routes, and every rule lives in the service.
 */
class ResepController extends Controller
{
    public function __construct(
        private readonly ObatSearchService $cari,
        private readonly ResepService $service,
    ) {}

    /**
     * `GET /api/v1/obat` - 200 with the project `meta` block.
     *
     * `meta` is a TOP-LEVEL sibling of `data` from `ApiResponse::pageMeta()`,
     * never a wrapper around it, so a client parses one list envelope.
     */
    public function search(SearchObatRequest $request): JsonResponse
    {
        $valid = $request->validated();

        $hasil = $this->cari->cari([
            'search' => $valid['search'] ?? null,
            'kelas_obat' => $valid['kelas_obat'] ?? null,
            'requires_resep' => $valid['requires_resep'] ?? null,
            'page' => (int) ($valid['page'] ?? 1),
            'per_page' => (int) ($valid['per_page'] ?? 15),
        ]);

        return ApiResponse::success(
            ['obat' => MasterObatResource::collection($hasil)],
            'Daftar obat berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($hasil),
        );
    }

    /**
     * `POST /api/v1/konsultasi/{id}/resep` - 201 with the warning payload.
     *
     * The response carries `data.warning` (the whole set, worst first),
     * `data.warning_grup` (the same set keyed by every `sumber`, so a client
     * renders three panels rather than two when one is empty) and
     * `data.acknowledgement` (`diminta`, the stored `catatan_dodio`, and the
     * warning count). `meta` is ABSENT rather than null: this is not a list.
     */
    public function store(StoreResepRequest $request, int $id): JsonResponse
    {
        $hasil = $this->service->buat($this->user($request), $id, $request->validated());

        return ApiResponse::success(
            [
                'resep' => new ResepResource($hasil['resep']),
                'warning' => $hasil['warning'],
                'warning_grup' => $hasil['warning_grup'],
                'acknowledgement' => [
                    'diminta' => $hasil['diminta'],
                    'catatan_dodio' => $hasil['catatan'],
                    'jumlah_peringatan' => count($hasil['warning']),
                ],
            ],
            'Resep berhasil dibuat.',
            Response::HTTP_CREATED,
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
