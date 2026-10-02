<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexPersetujuanPdpRequest;
use App\Http\Resources\AdminPersetujuanPdpResource;
use App\Models\PersetujuanPdp;
use App\Services\Pdp\PdpConsentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /admin/persetujuan-pdp` - the READ-ONLY admin ledger of PDP consent.
 *
 * ## This is the surface `pdp.kelola` was reserved for, and it is still read-only
 *
 * `routes/api.php`'s PDP block records that `pdp.kelola` had no consumer after
 * F02 and that the reason was compliance: a route letting an admin RECORD a data
 * subject's consent would be a defect wearing a permission code, because UU PDP
 * asks the person and not their employer. The code was kept "for the future
 * admin READ surface". F14 is that surface. There is exactly ONE route here, a
 * `GET`; the write routes (`POST /pdp/persetujuan`, `PUT`-shaped updates) do not
 * exist and are not owed. An admin reads the ledger; the subject decides.
 *
 * ## The guard, and what the party gate adds
 *
 * `tipe:admin,superadmin` plus `permission:pdp.kelola`. `pdp.kelola` is granted
 * to `admin` and `superadmin` and to nobody else, so the permission is already
 * narrow; `tipe:` is kept because the exemption in `RbacCatalog` proposes data
 * changes without code changes, and a future grant to a non-admin role must not
 * silently open a compliance surface.
 *
 * ## What the response is, and is not
 *
 * The RAW ledger, newest append first (`id DESC`), filterable by subject
 * (`user_id`), document kind (`jenis`) and decision (`disetujui`). It is not a
 * per-user checklist and it publishes no IP, no name and no contact detail -
 * {@see AdminPersetujuanPdpResource} records exactly which columns were withheld
 * and why. Aggregation ("who currently consents to X") is the subject-facing
 * rule in {@see PdpConsentService}; this surface shows the
 * evidence, not the conclusion.
 *
 * The ledger is append-only by construction (`persetujuan_pdp` has no timestamps
 * and F02 dropped its unique key), so no DELETE or UPDATE route exists: a
 * withdrawal is a NEW row with `disetujui = false`, written by the subject
 * through their own endpoint.
 */
class AdminPersetujuanPdpController extends Controller
{
    /** Rows per page when `per_page` is absent; the project-wide default. */
    private const PER_PAGE_DEFAULT = 15;

    /**
     * `GET /api/v1/admin/persetujuan-pdp?user_id&jenis&disetujui&page&per_page`
     *
     * One page of the ledger with the project `meta` block. Ordered by `id
     * DESC` - append order, which is the ledger's own chronology; `disetujui_at`
     * is a fact about the act and may legitimately differ (an imported paper
     * consent), so it cannot be the ordering key.
     */
    public function index(IndexPersetujuanPdpRequest $request): JsonResponse
    {
        $filter = $request->validated();

        $query = PersetujuanPdp::query();

        if (isset($filter['user_id'])) {
            $query->where('user_id', (int) $filter['user_id']);
        }

        if (isset($filter['jenis'])) {
            $query->where('jenis', (string) $filter['jenis']);
        }

        if (array_key_exists('disetujui', $filter)) {
            $query->where(
                'disetujui',
                filter_var($filter['disetujui'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
            );
        }

        $paginator = $query
            ->orderByDesc('id')
            ->paginate((int) ($filter['per_page'] ?? self::PER_PAGE_DEFAULT))
            ->withQueryString();

        return ApiResponse::success(
            ['persetujuan_pdp' => AdminPersetujuanPdpResource::collection($paginator->getCollection())],
            'Ledger persetujuan PDP berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($paginator),
        );
    }
}
