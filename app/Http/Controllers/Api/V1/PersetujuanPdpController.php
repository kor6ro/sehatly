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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two routes, and both are about the CALLER's own consent record.
 *
 * | route | verb | `data` | `meta` |
 * | --- | --- | --- | --- |
 * | `GET /api/v1/pdp/persetujuan` | the five-slot checklist | `{persetujuan}` | `singlePageMeta(5)` |
 * | `POST /api/v1/pdp/persetujuan` | one decision | `{persetujuan}` | no |
 *
 * ## The guards: `auth:sanctum` and NOTHING else, and that is the answer
 *
 * `RbacCatalog::PERMISSIONS` holds two consent-relevant codes and NEITHER is used
 * here. Both omissions need a reason, and the reasons are different:
 *
 * - `pdp.kelola` is granted to `admin` and `superadmin` and to nobody else. Using
 *   it on a route about the caller's own consent would lock out every patient,
 *   every doctor, every pharmacist and both of the role-less account types - the
 *   only people whose consents these are. The route instead carries no guard
 *   beyond authentication, because the query is scoped to
 *   `$request->user()->getKey()` in the service: a caller cannot name a
 *   `user_id`, so there is no cross-tenant question for a permission to answer.
 * - `notifikasi.lihat` names the notification centre, not this one, and the
 *   consent checklist is the more sensitive of the two: it is a record of what a
 *   person agreed to. A caller who may read their own consent does not thereby
 *   earn a grant over anybody else's.
 *
 * **`pdp.kelola` is therefore a catalogue code with no consumer**, and that is a
 * deliberate outcome rather than an oversight - asserted by this todo's test so it
 * cannot rot into a false claim. The reason it has no consumer is the important
 * part: a route that let an `admin` record a data subject's consent would be a
 * compliance defect wearing a permission code. Consent is the data subject's act
 * (UU PDP asks the person, not their employer), and the future admin surface this
 * code is presumably for is a READ of the compliance view, not a WRITE of somebody
 * else's decision. Inventing that route is not this todo's to do.
 *
 * ## `perawat` and `kurir` are NOT locked out here
 *
 * They are real `users.tipe` values (`:139`) that hold no role, so any
 * `permission:` would lock them out of these two routes permanently. They are not,
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
 * One read and one write, two distinct shapes, and the write is an UPSERT-SHAPED
 * operation with three different status codes (201, 200, 422). A resource route
 * would publish a destroy nobody may perform and a "show" with no id in the path.
 *
 * ## What a client does with a 422 from the write
 *
 * This is the one place in the API where the correct client behaviour is genuinely
 * counter-intuitive, so it is written out rather than left to the status code:
 *
 * 1. A 422 on `versi_dokumen` is NOT retryable. A retry is byte-identical and is
 *    refused identically, so a retry loop turns a permanent refusal into a
 *    traffic problem.
 * 2. `GET /api/v1/pdp/persetujuan` and read `data.persetujuan[jenis].efektif`.
 *    That value is already resolved through the version rule, so the client never
 *    re-implements "highest version" and cannot get it wrong on a stale copy.
 * 3. The recorded `versi_dokumen` and `disetujui_at` on the same entry are what
 *    the person is entitled to see: "you agreed to version X at time Y" is the
 *    only truthful answer to "did I agree to this?".
 * 4. Actually withdrawing means the DOCUMENT must advance - a strictly higher
 *    `versi_dokumen`. The client cannot invent one: the version is a property of
 *    the document being consented to, not of the account, so a withdrawal is
 *    driven by the consent flow advancing to a newer version and asking again.
 *
 * @see PdpConsentService for the rule these four points are about
 * @see PerubahanVersiException for the two refusal messages
 */
class PersetujuanPdpController extends Controller
{
    public function __construct(
        private readonly PdpConsentService $service,
    ) {}

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
     * | 201 | a strictly higher `versi_dokumen` was recorded; it supersedes every lower one |
     * | 200 | the same version and the same answer: an idempotent re-send, nothing written |
     * | 422 R1b | the same version with a DIFFERENT answer - the revoked-same-version collision |
     * | 422 R1c | a lower `versi_dokumen`: it could never be the effective row |
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
        // returns the existing row unchanged for an idempotent re-send, and an
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
     * Both actions run behind `auth:sanctum`, so `user()` is never null.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
