<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RekamMedis\AmandemenRekamMedisRequest;
use App\Http\Requests\RekamMedis\FinalisasiRekamMedisRequest;
use App\Http\Requests\RekamMedis\IndexAksesRekamMedisRequest;
use App\Http\Requests\RekamMedis\IndexRekamMedisRequest;
use App\Http\Requests\RekamMedis\SimpanRekamMedisRequest;
use App\Http\Requests\RekamMedis\UbahRekamMedisRequest;
use App\Http\Resources\AksesRekamMedisResource;
use App\Http\Resources\RekamMedisDaftarResource;
use App\Http\Resources\RekamMedisResource;
use App\Models\User;
use App\Services\RekamMedis\RekamMedisService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seven routes, and every rule lives in the service.
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
 * | `GET /rekam-medis` | - | - | the same refusal shape; another patient's records are absent from the query itself |
 * | `GET /rekam-medis/{id}/akses` | - | - | the same resolver as the detail read: a non-party 404, an account with no profile row 403 |
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
 * ## The two new reads and the access log
 *
 * `GET /rekam-medis` is an INDEX, not the record: it publishes the visit instant,
 * the presenting complaint and the working-diagnosis label - the minimum needed to
 * choose a record - and it writes ZERO `akses_rekam_medis_log` rows, because the
 * table names ONE record by a `NOT NULL` foreign key and a page has no honest row it
 * could write. `GET /rekam-medis/{id}/akses` reads the log ABOUT a record rather
 * than the record itself, and writes nothing either; its response publishes `waktu`,
 * `peran` (the actor's `users.tipe`) and `tujuan_akses`, never the actor's name.
 * Both rules live in `RekamMedisService::daftar()` and `::daftarAkses()`, and the
 * test counts the log table before and after each request.
 *
 * ## `whereNumber` on every `{id}`
 *
 * `rekam_medis.id` and `konsultasi.id` are `BIGINT UNSIGNED AUTO_INCREMENT` primary
 * keys (:622, :537), so `whereNumber` makes a non-numeric segment a router 404 and no
 * request can arrive with `abc` in a position the API treats as an identifier.
 *
 * ## No `Route::resource`
 *
 * The seven operations have five distinct verbs and several distinct shapes
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
     * `GET /api/v1/rekam-medis` - 200, paginated, and ZERO access-log rows.
     *
     * The caller's own `pasien` row is resolved by the service (403 when none);
     * another patient's records are absent because the tenant filter IS the query.
     * Rows are `RekamMedisDaftarResource` - a minimal index with no NIK, no contact
     * detail, no SOAP note and no child collections. `meta` is the top-level
     * `pageMeta()` block every list carries.
     *
     * This method deliberately does NOT call `findForAccess()`: that would write one
     * `akses_rekam_medis_log` row per listed record, which is the false trail the
     * owner ruled out. See `RekamMedisService::daftar()`.
     */
    public function index(IndexRekamMedisRequest $request): JsonResponse
    {
        $baris = $this->service->daftar(
            $this->user($request),
            $request->validated(),
            $request->perPage(),
        );

        return ApiResponse::success(
            ['rekam_medis' => RekamMedisDaftarResource::collection($baris->getCollection())],
            'Daftar rekam medis berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($baris),
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
     * `GET /api/v1/rekam-medis/{id}/akses` - 200, paginated, ZERO access-log rows.
     *
     * The record is resolved by the SAME rule as the detail read
     * (`RekamMedisAccess::sisiUntukBaca()`): a non-party gets 404, an account owning
     * no profile row gets 403, and no `RekamMedis` model is hydrated, so neither
     * refusal writes a log row. Rows are `AksesRekamMedisResource` - `waktu`,
     * `peran` and `tujuan_akses`, never the actor's name (UU PDP minimisation; see
     * the resource docblock).
     *
     * Fetching the log is not reading the record, so this does not log: an
     * `akses_rekam_medis_log` row with a `tujuan_akses` from the five-value ENUM would
     * claim a clinical read that did not happen.
     */
    public function akses(IndexAksesRekamMedisRequest $request, int $id): JsonResponse
    {
        $baris = $this->service->daftarAkses(
            $this->user($request),
            $id,
            $request->perPage(),
        );

        return ApiResponse::success(
            ['akses' => AksesRekamMedisResource::collection($baris->getCollection())],
            'Riwayat akses rekam medis berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($baris),
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
