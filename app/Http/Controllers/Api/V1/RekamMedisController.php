<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RekamMedis\AmandemenRekamMedisRequest;
use App\Http\Requests\RekamMedis\FinalisasiRekamMedisRequest;
use App\Http\Requests\RekamMedis\SimpanRekamMedisRequest;
use App\Http\Requests\RekamMedis\UbahRekamMedisRequest;
use App\Http\Resources\RekamMedisResource;
use App\Models\User;
use App\Services\RekamMedis\RekamMedisService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Five routes, and every rule lives in the service.
 *
 * ## This controller is thin on purpose
 *
 * Each method is: take the validated payload, hand it to `RekamMedisService` with the
 * authenticated account, wrap the returned row in a Resource, and call
 * `ApiResponse::success()`. There is no `if` about ownership, no `if` about the
 * draft/final gate, and no call to the access logger - because each of those would be
 * a step a future edit could forget, and the access log in particular is the one thing
 * in this application that must not be optional.
 *
 * `RekamMedisService` is the only entry point, it takes a `User` and never a model,
 * and it is the only class that hydrates a `rekam_medis` row. `RekamMedis` and its four
 * child models throw if anything else tries, so a sixth endpoint added here could not
 * read a medical record without a log row even if it were written carelessly.
 *
 * ## The guards, one at a time
 *
 * | route | `permission:` | `tipe:` | who is refused, and why |
 * | --- | --- | --- | --- |
 * | `POST /konsultasi/{id}/rekam-medis` | `rekam_medis.simpan` | `dokter` | patient, apoteker, admin, perawat, kurir, superadmin |
 * | `PUT /rekam-medis/{id}` | `rekam_medis.simpan` | `dokter` | same |
 * | `PUT /rekam-medis/{id}/final` | `rekam_medis.final` | `dokter` | same |
 * | `POST /rekam-medis/{id}/amandemen` | `rekam_medis.final` | `dokter` | same |
 * | `GET /rekam-medis/{id}` | - | - | a non-party or a non-party's record, 404; an account with no profile row, 403 |
 *
 * All three `rekam_medis` codes in `RbacCatalog::PERMISSIONS` are consumed, and
 * `rekam_medis.lihat` is the only one deliberately not: it is granted to `pasien`,
 * `dokter` and `superadmin` but NOT to `admin` (`RbacCatalog::ROLE_PERMISSIONS`), so
 * using it on the read would 403 the `admin` the plan names as the `audit` reader. The
 * plan's read audience is a DISJUNCTION - the patient themselves OR their doctor OR an
 * oversight account - and a route gate can only express a conjunction. This is the same
 * argument `KonsultasiController` makes for `GET /konsulto/{id}`, and
 * `RekamMedisAccess::sisiUntukBaca()` is where the disjunction lives.
 *
 * An amendment is gated on `rekam_medis.final` and not on `rekam_medis.simpan`, because
 * an amendment carries the same clinical authority as the signed record it supersedes:
 * gating it on the draft-write code would let a doctor who cannot sign authorise an
 * amendment to a document somebody else signed. Both are held by the same two roles, so
 * the choice is semantic rather than behavioural.
 *
 * `perawat` and `kurir` are real `users.tipe` values (`telemedicine_test.sql:139`)
 * that hold NO role in `RbacCatalog::ROLES`, so any `permission:` locks them out of
 * every one of the four writes permanently. That is a real gap and it is reported: the
 * DDL gives a nurse a clinical role of its own - `pasien_tanda_vital.sumber` is
 * `ENUM('mandiri','dokter','perawat','iot_device')` at :325 - so a nurse writes
 * clinical data in this schema while holding no grant that could let her write a
 * medical record. The fix is a data change in `app/Support/Rbac/` plus a re-seed, and
 * that is not this todo's to make. On the read they are refused with a 403 from
 * `RekamMedisAccess` - a fact about rows they do not own rather than a role they lack.
 *
 * ## `whereNumber` on every `{id}`
 *
 * `rekam_medis.id` and `konsultasi.id` are `BIGINT UNSIGNED AUTO_INCREMENT` primary
 * keys (:622, :537), so `whereNumber` makes a non-numeric segment a router 404 and no
 * request can arrive with `abc` in a position the API treats as an identifier.
 *
 * ## No `Route::resource`
 *
 * The five operations have five distinct verbs and two distinct shapes
 * (`{id}/final` is a PUT with no body, `{id}/amandemen` is a POST with a nested one),
 * so a resource route would publish methods this surface does not have.
 */
class RekamMedisController extends Controller
{
    public function __construct(
        private readonly RekamMedisService $service,
    ) {}

    /**
     * `POST /api/v1/konsultasi/{id}/rekam-medis` - 201.
     *
     * A DRAFT. `status_dokumen` and `versi` are written explicitly by the service
     * because the DDL's default for `status_dokumen` is `'final'` (:645), and a record
     * born final is immutable from the moment it exists.
     */
    public function simpan(SimpanRekamMedisRequest $request, int $id): JsonResponse
    {
        $baris = $this->service->simpan($id, $request->validated(), $this->user($request));

        return ApiResponse::success(
            ['rekam_medis' => new RekamMedisResource($baris)],
            'Rekam medis berhasil disimpan sebagai draft.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `PUT /api/v1/rekam-medis/{id}` - 200, or 422 on a signed record.
     *
     * In-place editing is permitted while `status_dokumen === 'draft'` and nowhere
     * else. A signed record answers 422 pointing at `/amandemen`, and the row is left
     * byte identical - the test compares every column including `diubah_at`, which is
     * `ON UPDATE CURRENT_TIMESTAMP` (:649) and would therefore move on a mutation even
     * if every clinical column matched.
     */
    public function ubah(UbahRekamMedisRequest $request, int $id): JsonResponse
    {
        $baris = $this->service->ubah($id, $request->validated(), $this->user($request));

        return ApiResponse::success(
            ['rekam_medis' => new RekamMedisResource($baris)],
            'Rekam medis berhasil diperbarui.',
        );
    }

    /**
     * `PUT /api/v1/rekam-medis/{id}/final` - 200, or 422 on an already-signed record.
     */
    public function finalisasi(FinalisasiRekamMedisRequest $request, int $id): JsonResponse
    {
        $baris = $this->service->finalisasi($id, $this->user($request));

        return ApiResponse::success(
            ['rekam_medis' => new RekamMedisResource($baris)],
            'Rekam medis berhasil difinalisasi.',
        );
    }

    /**
     * `POST /api/v1/rekam-medis/{id}/amandemen` - 201, and TWO rows afterwards.
     *
     * The original is never touched: the service inserts a new row at
     * `MAX(versi) + 1` with a fresh `uuid` and `status_dokumen = 'diamendemen'`, so the
     * chain keeps every revision and the test compares the superseded row byte for
     * byte.
     */
    public function amandemen(AmandemenRekamMedisRequest $request, int $id): JsonResponse
    {
        // `perubahan()` and not `validated('perubahan')`: the change set is handed over
        // AS SENT so the service can reject a key the schema does not have, which a
        // validated sub-array would have silently dropped. See
        // `AmandemenRekamMedisRequest::perubahan()`.
        $baris = $this->service->amandemen($id, $request->perubahan(), $this->user($request));

        return ApiResponse::success(
            ['rekam_medis' => new RekamMedisResource($baris)],
            'Amandemen rekam medis berhasil dibuat.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `GET /api/v1/rekam-medis/{id}` - 200, and exactly ONE access-log row.
     *
     * Every case, including the patient's own record. A stranger gets 404 and zero log
     * rows; an account with no profile row gets 403 and zero log rows; an id that does
     * not exist gets the router's 404 and zero log rows. A success writes one
     * `akses_rekam_medis_log` row naming the record and the caller's `tujuan_akses`.
     *
     * `meta` is ABSENT rather than null: this is not a list, and `ApiResponse` omits the
     * key entirely when `$meta === null` so that "not paginated" is unambiguous.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $baris = $this->service->findForAccess($id, $this->user($request));

        return ApiResponse::success(
            ['rekam_medis' => new RekamMedisResource($baris)],
            'Detail rekam medis berhasil dimuat.',
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
