<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dokter\IndexDokterRequest;
use App\Http\Requests\Dokter\IndexSlotDokterRequest;
use App\Http\Resources\DokterDetailResource;
use App\Http\Resources\DokterJadwalResource;
use App\Http\Resources\DokterResource;
use App\Http\Resources\DokterSlotResource;
use App\Http\Resources\MasterSpesialisasiResource;
use App\Services\Booking\SlotAvailabilityService;
use App\Services\Dokter\DokterDirectoryService;
use App\Support\ApiResponse;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public doctor directory: `GET /dokter`, `GET /dokter/{dokter}`,
 * `GET /dokter/{dokter}/jadwal`, `GET /dokter/{dokter}/slot` and
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
 *
 * ## The two schedule routes are on the same `meta` decision, and neither pages
 *
 * `jadwal()` and `slot()` answer {@see ApiResponse::singlePageMeta()} rather than
 * `pageMeta()`: a week is seven keys that must all be present, and a day's slot
 * list is bounded by that weekday's `dokter_jadwal` rows, so there is nothing to
 * page in either case. Paging the slot list would not merely truncate it, it would
 * publish a lie -- a day with forty candidates rendered as a day with fifteen,
 * where the missing twenty-five read as nonexistent rather than unavailable. The
 * reason is spelled on {@see IndexSlotDokterRequest}.
 */
class DokterController extends Controller
{
    public function __construct(
        private readonly DokterDirectoryService $directory,
        private readonly SlotAvailabilityService $slot,
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
     * `GET /api/v1/dokter/{dokter}/jadwal`
     *
     * The weekly window template, from
     * {@see SlotAvailabilityService::getJadwal()}.
     *
     * ## This action computes NOTHING
     *
     * All four availability rules live in the service, and this method is the
     * three lines that publish them: resolve the doctor, ask, hand the answer to
     * a resource. A second spelling of the window subtraction, the holiday
     * closure, the quota or the STR boundary here would be a second source of
     * truth that could disagree with the booking path, and `BookingService`
     * already resolves slots through the same service.
     *
     * ## The eligibility gate is `DokterDirectoryService::find()`, deliberately
     *
     * The four reasons this endpoint must not serve an ineligible doctor --
     * absent, unverified, inactive, opted out of telemedicine, STR-expired,
     * soft-deleted account -- are the directory's rules, and they are asked of
     * the directory's own service rather than of a query written here. That is
     * the reuse that matters: a locally-written `where('status_verifikasi', ...)`
     * would be a second, separately-driftable copy of `v_dokter_katalog`'s `WHERE`
     * plus the STR boundary plus the `dihapus_at` guard.
     *
     * `find()` also eager-loads five relations this endpoint never reads. That is
     * a deliberate trade rather than an oversight: `DokterController::show()`
     * already pays the identical cost on the sibling route, and a cheaper
     * `exists()`-shaped variant of the same service would be a second entry point
     * through which the eligibility rule could be read. Seven queries for a
     * calendar render is a far smaller cost than two spellings of "may this
     * doctor be shown to a patient".
     *
     * ## The 404 is the directory's 404, body for body
     *
     * `ApiResponse::error('Resource not found.', ...)` is the same call
     * {@see show()} makes, and deliberately the same string the kernel's
     * exception handler publishes for an unmatched path
     * (`bootstrap/app.php`). The reason is not tidiness: publishing a *different*
     * message for "no such doctor" than for a malformed id segment would let an
     * unauthenticated caller distinguish the two, which is a small enumeration
     * channel, and `DokterDirectoryTest` pins the uniformity of the sibling
     * route for the same reason. One body, six reasons, one status.
     */
    public function jadwal(string $dokter): JsonResponse
    {
        $detail = $this->directory->find((int) $dokter);

        if ($detail === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        $jadwal = $this->slot->getJadwal($detail);

        return ApiResponse::success(
            new DokterJadwalResource($jadwal),
            'Jadwal dokter berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::singlePageMeta($this->jumlahBaris($jadwal)),
        );
    }

    /**
     * `GET /api/v1/dokter/{dokter}/slot?tanggal=YYYY-MM-DD`
     *
     * The bookable slots on ONE date, from
     * {@see SlotAvailabilityService::getSlotTerbuka()}.
     *
     * ## `tanggal` is the CONSULTATION date, and the envelope says so
     *
     * The response publishes `tanggal` back verbatim next to
     * `timezone: Asia/Jakarta`, and it is the date the whole answer is about --
     * the date the STR is compared against, the date `dokter_libur` is matched
     * on, and the date the elapsed-slot rule keys on. A caller that caches this
     * response under anything other than the date it asked about is caching an
     * answer about a different day, which is why both are on the wire.
     *
     * `timezone` is {@see SlotAvailabilityService::ZONA_WAKTU}, deliberately not
     * `config('app.timezone')`, which is UTC. `jam_mulai` and `jam_selesai` are
     * unzoned `H:i:s` wall clock exactly as `dokter_jadwal.jam_mulai` and
     * `jam_selesai` (`:476`-`:477`) store them; labelling the zone lets a client
     * render `23:30` as 23:30 rather than shifting it seven hours, and nothing on
     * this path ever constructs a `Y-m-d H:i:s` local datetime to convert.
     *
     * ## `tanggal` is validated at the edge, and the service's throw is the second layer
     *
     * {@see IndexSlotDokterRequest} applies `date_format:Y-m-d`, whose round trip
     * through `format()` is what rejects the calendar rollover PHP would
     * otherwise silently accept (`2026-13-45` becomes 2027-02-14). The service
     * keeps its own `InvalidArgumentException` and its own round trip, and this
     * method deliberately does **not** wrap the call in a catch: with the rule in
     * front of it no input reaches the service that it would refuse, so a catch
     * here would be unreachable code. The service's own test file pins that
     * throw, and this endpoint's test file pins the 422.
     *
     * ## An empty list is an answer, not an absence
     *
     * `slots: []` is returned for a weekday with no window, a doctor with no
     * `dokter_jadwal` row at all, and a licence that does not cover the requested
     * date. Those are three different facts and the endpoint deliberately
     * collapses them, for the reason
     * {@see SlotAvailabilityService::getSlotTerbuka()} gives: telling them apart
     * would let an unauthenticated caller read a doctor's licence state. A
     * doctor-level ineligibility is a different thing and is NOT collapsed --
     * that is a 404, answered before this method is reached.
     */
    public function slot(IndexSlotDokterRequest $request, string $dokter): JsonResponse
    {
        $detail = $this->directory->find((int) $dokter);

        if ($detail === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        /** @var string $tanggal */
        $tanggal = $request->validated()['tanggal'];

        $slot = $this->slot->getSlotTerbuka($detail, $tanggal);

        return ApiResponse::success(
            [
                'tanggal' => $tanggal,
                'timezone' => SlotAvailabilityService::ZONA_WAKTU,
                'slots' => DokterSlotResource::collection($slot),
            ],
            'Ketersediaan jam berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::singlePageMeta(count($slot)),
        );
    }

    /**
     * How many window rows a week map holds, for the `meta` block.
     *
     * The rows, not the seven day keys: `meta.total` means "how many windows are
     * published", which is what a client rendering a weekly grid counts, and
     * counting the days would report `7` for a doctor with no schedule at all.
     *
     * @param  array<int, list<array<string, mixed>>>  $jadwal
     */
    private function jumlahBaris(array $jadwal): int
    {
        $jumlah = 0;

        foreach ($jadwal as $baris) {
            $jumlah += count($baris);
        }

        return $jumlah;
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
