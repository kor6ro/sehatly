<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SimpanHeroRequest;
use App\Http\Requests\Admin\UbahHeroRequest;
use App\Http\Requests\Admin\UnggahGambarHeroRequest;
use App\Services\Landing\HeroService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The landing carousel, admin surface: four JSON routes and two image routes.
 *
 * ## The guard, in two layers, for the reason F14's block gives
 *
 * `tipe:admin,superadmin` (the group middleware in `routes/api.php`) plus
 * `permission:hero.kelola` on each route. The permission alone would also admit any
 * future role the catalogue were granted to; the party gate alone would leave the
 * front page editable by whoever held a data-only grant. Both are what every other
 * `/admin/*` route does, and `hero.kelola` is granted to exactly these two types.
 *
 * ## Why there is no pagination and no bulk route
 *
 * `index` answers the whole strip: an operator reordering slides has to see every
 * row, and the set is bounded by {@see HeroService::MAKS_TAYANG} published rows plus
 * drafts. A reorder is an `urutan` on each row through `PUT`, not an endpoint of its
 * own - ten moves are ten requests, which is also how F14 declined the bulk
 * endpoint: the per-row answer is what a failure can be attributed to.
 *
 * ## No audit row is written, and that is recorded rather than assumed
 *
 * `HeroService` explains it: `audit_log` is written by an observer on Eloquent
 * events, and the one-model-per-contract-table rule keeps this table out of
 * `app/Models`. Publishing a banner therefore leaves no trail. If the owner wants
 * one, the fix is the reference DDL, not a second writer.
 */
class AdminHeroController extends Controller
{
    public function __construct(private readonly HeroService $hero) {}

    /**
     * `GET /api/v1/admin/hero` - every slide, drafts first-class.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ['hero' => $this->hero->daftarSemua()],
            'Slide hero berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `POST /api/v1/admin/hero` - create one slide.
     */
    public function store(SimpanHeroRequest $request): JsonResponse
    {
        return ApiResponse::success(
            ['hero' => $this->hero->simpan($request->validated())],
            'Slide hero berhasil ditambahkan.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `PUT /api/v1/admin/hero/{id}` - partial update; also the publish switch.
     */
    public function update(UbahHeroRequest $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            ['hero' => $this->hero->ubah($id, $request->validated())],
            'Slide hero berhasil diperbarui.',
            Response::HTTP_OK,
        );
    }

    /**
     * `DELETE /api/v1/admin/hero/{id}` - the row and the file it owns.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->hero->hapus($id);

        return ApiResponse::success(null, 'Slide hero berhasil dihapus.', Response::HTTP_OK);
    }

    /**
     * `POST /api/v1/admin/hero/{id}/gambar` - multipart, replaces any previous file.
     */
    public function gambar(UnggahGambarHeroRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        return ApiResponse::success(
            ['hero' => $this->hero->pasangGambar($id, $data['gambar'], (string) $data['gambar_alt'])],
            'Gambar slide hero berhasil diunggah.',
            Response::HTTP_OK,
        );
    }

    /**
     * `DELETE /api/v1/admin/hero/{id}/gambar` - image and alt text go together.
     */
    public function lepasGambar(int $id): JsonResponse
    {
        return ApiResponse::success(
            ['hero' => $this->hero->lepasGambar($id)],
            'Gambar slide hero berhasil dilepas.',
            Response::HTTP_OK,
        );
    }
}
