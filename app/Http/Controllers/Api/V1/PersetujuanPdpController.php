<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pdp\StorePersetujuanPdpRequest;
use App\Http\Resources\PersetujuanPdpResource;
use App\Models\User;
use App\Services\Pdp\PdpConsentService;
use App\Services\Pdp\PerubahanVersiException;
use App\Support\ApiResponse;
use App\Support\Pdp\PdpDokumen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Three routes: the document catalogue, the caller's checklist, and one decision.
 *
 * | route | verb | `data` | `meta` |
 * | --- | --- | --- | --- |
 * | `GET /api/v1/pdp/dokumen` | the five ACTIVE document versions | `{dokumen}` | `singlePageMeta(5)` |
 * | `GET /api/v1/pdp/persetujuan` | the five-slot checklist | `{persetujuan}` | `singlePageMeta(5)` |
 * | `POST /api/v1/pdp/persetujuan` | one decision | `{persetujuan}` | no |
 *
 * ## `GET /pdp/dokumen` is the server's version authority
 *
 * F02's owner decision: a client must not invent a `versi_dokumen`. This route
 * publishes the active version and `berlaku_sejak` of each of the five
 * documents, read from `config/pdp.php` through {@see PdpDokumen}, and the write
 * path refuses any other version with a 422 on `versi_dokumen`. The client
 * echoes back exactly what this route published.
 *
 * ## The guards: `auth:sanctum` and NOTHING else, and that is the answer
 *
 * `RbacCatalog::PERMISSIONS` holds two consent-relevant codes and NEITHER is used
 * here. Both omissions need a reason, and the reasons are different:
 *
 * - `pdp.kelola` is granted to `admin` and `superadmin` and to nobody else. Using
 *   it on a route about the caller's own consent would lock out every patient,
 *   every doctor, every pharmacist and both of the role-less account types - the
 *   only people whose consents these are. The routes instead carry no guard
 *   beyond authentication, because the query is scoped to
 *   `$request->user()->getKey()` in the service: a caller cannot name a
 *   `user_id`, so there is no cross-tenant question for a permission to answer.
 * - `notifikasi.lihat` names the notification centre, not this one, and the
 *   consent checklist is the more sensitive of the two: it is a record of what a
 *   person agreed to. A caller who may read their own consent does not thereby
 *   earn a grant over anybody else's.
 *
 * **`pdp.kelola` was therefore a catalogue code with no consumer after F02**, and
 * that was a deliberate outcome rather than an oversight - asserted by F02's test
 * so it could not rot into a false claim. The reason it had no consumer is the
 * important part: a route that let an `admin` record a data subject's consent
 * would be a compliance defect wearing a permission code. Consent is the data
 * subject's act (UU PDP asks the person, not their employer), and the admin
 * surface this code was reserved for is a READ of the compliance ledger, not a
 * WRITE of somebody else's decision. **F14 built exactly that read:**
 * `GET /admin/persetujuan-pdp` (see `routes/api.php`) now consumes `pdp.kelola`
 * as a read-only, paginated ledger with no write route. The three routes in
 * THIS class still carry no `permission:`, for the reason above.
 *
 * ## `perawat` and `kurir` are NOT locked out here
 *
 * They are real `users.tipe` values (`:139`) that hold no role, so any
 * `permission:` would lock them out of these routes permanently. They are not,
 * because consent belongs to a person whoever that person is at work -
 * `persetujuan_pdp.user_id` (`:1136`) is a `users` foreign key, not a `pasien`
 * one, so a nurse or a courier's consent record is a real row and the endpoint
 * would be lying about it if it 403'd them. The same two types ARE locked out of
 * the notification centre, which does carry `permission:notifikasi.lihat`; that
 * asymmetry is asserted, not described, and its fix is a data change in
 * `RbacCatalog` plus a re-seed.
 *
 * ## No `Route::resource`
 *
 * Two reads and one write, three distinct shapes, and the write answers three
 * status codes (201, 200, 422). A resource route would publish a destroy nobody
 * may perform and a "show" with no id in the path.
 *
 * ## What a client does with a 422 from the write
 *
 * This is the one place in the API where the correct client behaviour is genuinely
 * counter-intuitive, so it is written out rather than left to the status code:
 *
 * 1. A 422 on `versi_dokumen` is NOT retryable. A retry is byte-identical and is
 *    refused identically, so a retry loop turns a permanent refusal into a
 *    traffic problem.
 * 2. Call `GET /api/v1/pdp/dokumen` and read the active `versi_dokumen` for that
 *    `jenis`. The second message of `errors.versi_dokumen` names it too, so a
 *    client that cannot refetch still has the value.
 * 3. Resend the decision with the active version. Withdrawal is allowed at any
 *    time on the SAME version - `disetujui: false` appends a new row and the
 *    latest row wins - so changing one's mind never waits for the document to
 *    advance.
 * 4. `GET /api/v1/pdp/persetujuan` and read `data.persetujuan[jenis].efektif`.
 *    That value is already resolved through the ledger rule, so the client never
 *    re-implements "latest row" and cannot get it wrong on a stale copy.
 *
 * @see PdpConsentService for the ledger rule these four points are about
 * @see PerubahanVersiException for the one refusal message
 * @see PdpDokumen for the catalogue `GET /pdp/dokumen` publishes
 */
class PersetujuanPdpController extends Controller
{
    public function __construct(
        private readonly PdpConsentService $service,
        private readonly PdpDokumen $dokumen,
    ) {}

    /**
     * `GET /api/v1/pdp/dokumen` - the five active document versions.
     *
     * FIVE entries, always, in the DDL's own order, each carrying `jenis`,
     * `versi_dokumen` and `berlaku_sejak`. This is the server's version
     * authority: the write path accepts only the `versi_dokumen` published here,
     * so a client never invents one and a stale client is refused with a 422
     * that names the active version.
     *
     * `meta` is `ApiResponse::singlePageMeta(5)` for the same reason the
     * checklist uses it: the DDL's ENUM caps the list at five, so there is
     * nothing to page, and the project publishes one list envelope rather than
     * two. The key is a TOP-LEVEL SIBLING of `data`.
     */
    public function dokumen(): JsonResponse
    {
        $dokumen = $this->dokumen->semua();

        return ApiResponse::success(
            ['dokumen' => $dokumen],
            'Daftar dokumen PDP berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::singlePageMeta(count($dokumen)),
        );
    }

    /**
     * `GET /api/v1/pdp/persetujuan` - the five-slot checklist.
     *
     * FIVE entries, always, in the DDL's own order, whether or not a row exists -
     * so a client renders a checklist with no holes and never has to implement a
     * "has this person answered this yet" rule of its own. `efektif` is the
     * three-state answer; the other four keys are `null` for an unanswered slot.
     *
     * `meta` is `ApiResponse::singlePageMeta(5)` rather than a paginator's block:
     * the DDL's ENUM caps the list at five, so there is nothing to page, and
     * `ApiResponse` documents the degenerate single page as the truthful answer
     * for a list that is deliberately unpaginated. The key is a TOP-LEVEL SIBLING
     * of `data`, which is what `ApiResponse`'s docblock fixes for every list in
     * this project.
     */
    public function index(Request $request): JsonResponse
    {
        $ringkasan = $this->service->ringkasan($this->user($request));

        $isi = [];

        foreach ($ringkasan as $satu) {
            $isi[] = PersetujuanPdpResource::untuk($satu['jenis'], $satu['baris']);
        }

        return ApiResponse::success(
            ['persetujuan' => $isi],
            'Daftar persetujuan PDP berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::singlePageMeta(count($isi)),
        );
    }

    /**
     * `POST /api/v1/pdp/persetujuan` - 201, 200, or 422.
     *
     * | answer | when |
     * | --- | --- |
     * | 201 | a decision was appended: a first answer, a changed answer, or a withdrawal on the same version |
     * | 200 | the same answer as the latest recorded row: an idempotent re-send, nothing written |
     * | 422 | `versi_dokumen` is not the active version of that `jenis` |
     *
     * The 200 and the 201 are different on purpose, and the difference is
     * observable: a 201 wrote a row and therefore produced an `audit_log` row
     * through the global `AuditObserver`, while a 200 wrote nothing and produced
     * none. A client that retries after a lost response can therefore tell from
     * the status alone whether its first attempt landed.
     *
     * `ip_address` comes from the request and `disetujui_at` from the application
     * clock; both are `prohibited` in the request rather than ignored, so a
     * caller that tried to supply them is told.
     */
    public function store(StorePersetujuanPdpRequest $request): JsonResponse
    {
        $valid = $request->validated();

        try {
            $baris = $this->service->catat(
                $this->user($request),
                $valid['jenis'],
                $valid['versi_dokumen'],
                (bool) $valid['disetujui'],
                $request->ip(),
            );
        } catch (PerubahanVersiException $e) {
            return ApiResponse::error(
                'The given data was invalid.',
                $e->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // 201 or 200, decided by whether this call WROTE the row. The service
        // returns the latest row unchanged for an idempotent re-send, and an
        // existing row is by definition not a new one.
        $baru = $baris->wasRecentlyCreated;

        return ApiResponse::success(
            ['persetujuan' => new PersetujuanPdpResource($baris)],
            $baru
                ? 'Persetujuan PDP berhasil dicatat.'
                : 'Persetujuan untuk versi dokumen ini sudah tercatat dan tidak berubah.',
            $baru ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /**
     * The authenticated `User`, narrowed for the static analyser.
     *
     * Every action runs behind `auth:sanctum`, so `user()` is never null.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
