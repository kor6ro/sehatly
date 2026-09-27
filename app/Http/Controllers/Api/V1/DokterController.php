<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dokter\IndexDokterRequest;
use App\Http\Resources\DokterDetailResource;
use App\Http\Resources\DokterResource;
use App\Http\Resources\MasterSpesialisasiResource;
use App\Services\Dokter\DokterDirectoryService;
use App\Support\ApiResponse;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public doctor directory: `GET /dokter`, `GET /dokter/{dokter}` and
 * `GET /master-spesialisasi`.
 *
 * ## Why these routes are PUBLIC, and why no `permission:` appears on them
 *
 * The plan's todo 22 states it in one line - "`GET /api/v1/dokter` is **public** (no
 * auth)" - and `RbacCatalog` is what makes that the only workable answer rather than
 * a merely stated one.
 *
 * `RbacCatalog::PERMISSIONS` does hold a code that looks like it fits:
 * `dokter.lihat` ("Lihat Dokter", `:206`). Writing `permission:dokter.lihat` would be
 * wrong twice over, for reasons the catalogue itself records.
 *
 * 1. **It is not an authenticated surface.** A directory is a pre-authentication
 *    page: a visitor has to be able to browse doctors *before* they have an account.
 *    `permission:` resolves through `EnsurePermission`, which answers 401 when there
 *    is no authenticated principal at all - so the gate would make the endpoint
 *    answer 401 to every anonymous caller, which is the opposite of what the plan
 *    asked for. This is the same reasoning `AuthController`'s docblock gives for
 *    omitting `permission:` and `tipe:` from all eight of its routes.
 * 2. **It would lock out two real account types.** `perawat` and `kurir` are
 *    `users.tipe` ENUM values (`:139`) that hold **no role** in
 *    `RbacCatalog::ROLES` (`:152`-`:158`) and therefore no grant through
 *    `role_permissions` at all. `RbacCatalog`'s own docblock says so and points at
 *    todos 20/21/22 as the place that has to decide whether they need permissions.
 *    A `permission:dokter.lihat` gate answers 403 for a `perawat` who is trying to
 *    read the public directory, and no data change can fix that while the gate is
 *    written on the route.
 *
 * The permission is therefore **deliberately unused here, and this is where
 * `dokter.lihat` actually belongs**: an administrative directory that is *supposed*
 * to list unverified, inactive and STR-expired doctors so an operator can renew or
 * suspend them. That endpoint needs the code, must be authenticated, and must not
 * share {@see DokterDirectoryService}'s query, because its whole purpose is to
 * bypass the two eligibility rules that service exists to enforce.
 *
 * A third option was considered and rejected: gating only the *detail* endpoint.
 * `dokter.lihat` and `dokter.profil` (`:207`) are both held by all five roles, so
 * gating the detail endpoint would still exclude `perawat` and `kurir`, and it would
 * break the plan's own todo 23 requirement that both clients render a doctor's
 * profile.
 *
 * ## Why every ineligibility answers 404 with the same body
 *
 * {@see DokterDirectoryService::find()} returns `null` for a doctor that does not
 * exist, is unverified, is inactive, has opted out of telemedicine, has an expired
 * STR, or whose account is soft-deleted. None of those is distinguished here, because
 * a caller who could tell "unverified" from "absent" could enumerate the verification
 * state of every doctor account from an unauthenticated endpoint - which is precisely
 * what the `status_verifikasi` rule protects. A 403 would be worse still; the
 * bootstrap `withExceptions` callback renders a 404 `ModelNotFoundException` and a
 * 404 `NotFoundHttpException` identically, so the two code paths cannot diverge
 * either.
 *
 * ## Pagination shape: the project-wide `meta` block
 *
 * {@see ApiResponse} grew a fourth key for exactly this: `success($data, $message,
 * $status, $meta)` plus `ApiResponse::pageMeta(LengthAwarePaginator)`, which derives
 * `{current_page, last_page, per_page, total, from, to}` from the paginator so no two
 * controllers can spell it differently. This controller therefore uses it, and
 * `DokterDirectoryTest` reads `meta.*` rather than any local spelling.
 *
 * It was written against the two-key envelope first and switched when todo 21's
 * `ApiResponse` landed; the *field names* never changed, because the names were taken
 * from the plan's todo 22 criterion and from todo 24's `Paginated<T>` in the first
 * pass. The `data.dokter` rows are unaffected either way - `meta` is a sibling of
 * `data`, never a wrapper around it, so no client has to read two shapes.
 *
 * `per_page` in the block is the page size **actually applied**, so it stays correct
 * after the 100 cap has clamped the request.
 */
class DokterController extends Controller
{
    public function __construct(
        private readonly DokterDirectoryService $directory,
    ) {}

    /**
     * `GET /api/v1/dokter`
     *
     * Filters: `spesialisasi` (code or id), `tipe`, `search`, `tersedia_telemedisin`.
     * Ordering: `rating_rata_rata DESC`, then `jumlah_konsultasi DESC`, then
     * `dokter_id ASC` as the unique tiebreaker the plan's two keys need in order to
     * make the order total. Pagination: `page`, `per_page` capped at 100.
     */
    public function index(IndexDokterRequest $request): JsonResponse
    {
        $paginator = $this->directory->list($request->validated());

        return ApiResponse::success(
            ['dokter' => DokterResource::collection($paginator->getCollection())],
            'Daftar dokter berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($paginator),
        );
    }

    /**
     * `GET /api/v1/dokter/{dokter}`
     *
     * The route segment is `dokter`, and the parameter is typed `string` rather than
     * `int` or `Dokter` on purpose:
     *
     * - `dokter` matches todo 26's `GET /dokter/{dokter}/jadwal` and
     *   `GET /dokter/{dokter}/slot`, so all three controllers address the same
     *   resource by the same name and a client can compose the paths.
     * - A `Dokter $dokter` type-hint would make Laravel's implicit route-model
     *   binding resolve the segment against the `Dokter` model and **bypass the
     *   eligibility rules entirely**, answering 200 with an unverified doctor's
     *   profile. The eligibility decision belongs to the service, so nothing on this
     *   route may resolve the model implicitly.
     * - `string` rather than `int` because a non-numeric segment must produce the
     *   404 envelope, not a `TypeError`. `(int) "abc"` is `0`, which matches no row,
     *   so the conversion is total and needs no guard of its own.
     */
    public function show(string $dokter): JsonResponse
    {
        $detail = $this->directory->find((int) $dokter);

        if ($detail === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        return ApiResponse::success(
            ['dokter' => new DokterDetailResource($detail)],
            'Detail dokter berhasil dimuat.',
        );
    }

    /**
     * `GET /api/v1/master-spesialisasi`
     *
     * The reference list both clients need to populate the `?spesialisasi=` filter.
     * Named by the plan's todo 22 and not by this round's brief; it is a pure read of
     * a 16-row master table (`telemedicine_test.sql:1236`-`:1252`), and adding it here
     * costs one method and keeps the specialisation vocabulary next to the filter
     * that consumes it.
     *
     * `total` is the row count of the table, not a constant. The 16 rows are the
     * DDL's seed data; an empty `master_spesialisasi` - a schema-only database -
     * answers `0` here, and a client that hard-coded 16 would render a dropdown the
     * server cannot filter by.
     *
     * It answers `ApiResponse::singlePageMeta()` rather than `pageMeta()` because a
     * 16-row reference table has nothing to page, and giving it the degenerate
     * `current_page = 1, last_page = 1` block is what lets a client parse one list
     * envelope rather than two. That is the same decision
     * `AuthController::devicesIndex()` records for the same reason.
     */
    public function spesialisasiIndex(): JsonResponse
    {
        $spesialisasi = $this->directory->spesialisasi();
        $total = $spesialisasi->count();

        return ApiResponse::success(
            ['spesialisasi' => MasterSpesialisasiResource::collection($spesialisasi)],
            'Daftar spesialisasi berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::singlePageMeta($total),
        );
    }
}
