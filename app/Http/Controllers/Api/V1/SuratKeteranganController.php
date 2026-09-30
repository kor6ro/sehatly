<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuratKeterangan\BuatSuratKeteranganRequest;
use App\Http\Resources\RujukanResource;
use App\Http\Resources\SuratKeteranganResource;
use App\Models\SuratKeterangan;
use App\Models\User;
use App\Services\Pasien\PasienRecordAccess;
use App\Services\SuratKeterangan\SuratKeteranganService;
use App\Services\SuratKeterangan\SuratKeteranganTokenHabisException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Three routes, and every rule lives in the service.
 *
 * ## This controller is thin on purpose
 *
 * Each method is: take the validated payload, hand it to `SuratKeteranganService` with
 * the authenticated account, wrap the result in a Resource, and call
 * `ApiResponse::success()`. There is no `if` about ownership, none about the
 * period/refit eligibility, and none about the consent - because each of those would be
 * a step a future edit could forget, and the PDP consent is the one thing on this
 * surface that must not be optional.
 *
 * The ONE exception is the spent retry budget, which is caught here and turned into the
 * 422 envelope. A `RuntimeException` would render as a sanitised 500, which is the
 * wrong answer for a condition the caller can act on by re-issuing, and the exception
 * carries a field-keyed body precisely so this conversion is mechanical rather than
 * re-written.
 *
 * ## The guards, one at a time
 *
 * | route | `permission:` | `tipe:` | who is refused, and why |
 * | --- | --- | --- | --- |
 * | `POST /konsultasi/{id}/surat-keterangan` | `surat_keterangan.buat` | `dokter` | patient, apoteker, admin, perawat, kurir, superadmin; another doctor 404 |
 * | `GET /pasien/surat-keterangan` | - | - | an account with no `pasien` row, 403; another patient's letters absent |
 * | `GET /surat-keterangan/{nomor_surat}/verify` | - | - | NOTHING - it is public |
 *
 * `surat_keterangan.buat` is a real code in `RbacCatalog::PERMISSIONS` and is granted to
 * `dokter` and to `superadmin`; `tipe:dokter` is what excludes `superadmin`, and it is
 * present because issuing a clinical letter is not a thing an oversight account does.
 * `perawat` and `kurir` are real `users.tipe` values (`telemedicine_test.sql:139`) that
 * hold NO role, so any `permission:` locks them out permanently - a real gap, reported
 * rather than worked around, because the DDL gives a nurse a clinical role of her own
 * (`pasien_tanda_vital.sumber` at `:325`).
 *
 * The patient's list carries NO `permission:` and NO `tipe:`: no code in the catalogue
 * names reading a letter, and a disjunction cannot be a route gate. The ownership rule
 * is `PasienRecordAccess::ownPasien()`, which is the same rule the eleven patient routes
 * from todo 21 use.
 *
 * ## The verifier is public, and this controller is where the decision is visible
 *
 * `verifikasi()` takes no account at all. A QR code is a physical artifact that a
 * receptionist with no login scans, so a `permission:` here would answer 401 for the
 * only caller the endpoint exists for. What it may publish is bounded by the service,
 * which is where the six allowed fields and the "no NIK at all" decision are stated.
 */
class SuratKeteranganController extends Controller
{
    public function __construct(
        private readonly SuratKeteranganService $service,
    ) {}

    /**
     * `POST /api/v1/konsultasi/{id}/surat-keterangan` - 201.
     *
     * A `surat_rujukan` also writes its `rujukan` row in the same transaction and only
     * after an approved `berbagi_data_medis` consent; a refused consent is a 403 and
     * leaves neither row behind.
     */
    public function buat(BuatSuratKeteranganRequest $request, int $id): JsonResponse
    {
        try {
            $baris = $this->service->buat($this->user($request), $id, $request->validated());
        } catch (SuratKeteranganTokenHabisException $e) {
            throw ValidationException::withMessages($e->errors());
        }

        $baris->loadMissing(['pasien.user', 'dokter.user', 'rujukan']);

        return ApiResponse::success([
            'surat_keterangan' => new SuratKeteranganResource($baris),
            // The referral as a SINGLE object, not the resource's list: the issuing
            // response is about one letter, and a client that just created a referral
            // reads its fields off `data.rujukan` directly. `null` when the letter is
            // not a referral, which is the honest answer for a letter with no referral.
            // `RujukanResource` is the same formatter the letter resource's list uses,
            // so the two shapes cannot drift.
            'rujukan' => $baris->rujukan->first() === null
                ? null
                : new RujukanResource($baris->rujukan->first()),
        ], $this->pesan($baris), Response::HTTP_CREATED);
    }

    /**
     * `GET /api/v1/pasien/surat-keterangan` - 200, paginated.
     *
     * `meta` is a TOP-LEVEL SIBLING from `ApiResponse::pageMeta()`, exactly as on every
     * other list, so a client parses one list envelope rather than two.
     */
    public function daftar(Request $request): JsonResponse
    {
        $halaman = $this->service->daftarUntukPasien(
            $this->user($request),
            (int) $request->integer('per_page', PasienRecordAccess::PER_PAGE_DEFAULT),
        );

        return ApiResponse::success(
            ['surat_keterangan' => SuratKeteranganResource::collection($halaman->getCollection())],
            'Daftar surat keterangan berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($halaman),
        );
    }

    /**
     * `GET /api/v1/surat-keterangan/{nomor_surat}/verify?token=...` - 200, ALWAYS 200.
     *
     * A well-formed verification question gets a well-formed answer, and the answer
     * carries the verdict: `valid: false` for a wrong token AND for a document number
     * that does not exist, byte for byte. A 404 or a 403 would make the endpoint's
     * behaviour depend on whether the number happens to be real, which is an existence
     * oracle over a document-number space a stranger can partly guess.
     *
     * A MISSING `token` is the one case that is not a verification, and it is a 422
     * from the FormRequest's `required` rule: the scanner did not finish its job, and
     * answering `valid: false` would tell it the letter is forged, which is a different
     * and wrong fact.
     */
    public function verifikasi(Request $request, string $nomorSurat): JsonResponse
    {
        $token = (string) $request->query('token', '');

        if (trim($token) === '') {
            throw ValidationException::withMessages([
                'token' => ['Token QR wajib diisi.'],
            ]);
        }

        $hasil = $this->service->verifikasi($nomorSurat, $token);

        return ApiResponse::success(
            $hasil,
            $hasil['valid']
                ? 'Surat keterangan terverifikasi.'
                : 'Token QR tidak cocok dengan surat keterangan tersebut.',
        );
    }

    /**
     * The authenticated `User`, narrowed for the static analyser.
     *
     * The two authenticated actions run behind `auth:sanctum`, so `user()` is never
     * null. `verifikasi()` does NOT use it, which is the point of that method.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * The Indonesian confirmation for a created letter.
     *
     * Two sentences rather than one because a referral is a materially different act
     * from a sickness certificate - it hands clinical content to a named facility - and
     * a caller that cannot tell the two apart from the response would be misled about
     * what it just did.
     */
    private function pesan(SuratKeterangan $baris): string
    {
        return $baris->rujukan->isEmpty()
            ? 'Surat keterangan berhasil dibuat.'
            : 'Surat rujukan berhasil dibuat beserta rujukan ke faskes tujuan.';
    }
}
