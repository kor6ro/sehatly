<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Obat\SearchObatRequest;
use App\Http\Requests\Resep\RiwayatResepRequest;
use App\Http\Requests\Resep\StoreResepRequest;
use App\Http\Requests\Resep\VerifikasiResepRequest;
use App\Http\Resources\MasterObatResource;
use App\Http\Resources\ResepResource;
use App\Http\Resources\ResepVerifikasiResource;
use App\Models\Resep;
use App\Models\User;
use App\Services\Obat\ObatInteraksiService;
use App\Services\Obat\ObatSearchService;
use App\Services\Resep\ResepAccess;
use App\Services\Resep\ResepService;
use App\Services\Resep\ResepVerifikasiService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Four routes, and every rule lives in the services.
 *
 * ## The shapes
 *
 * | route | verb | `data` | `meta` |
 * | --- | --- | --- | --- |
 * | `GET /api/v1/obat` | list | `{obat: [...]}` | yes |
 * | `POST /api/v1/konsultasi/{id}/resep` | one | `{resep, warning, warning_grup, acknowledgement}` | no |
 * | `GET /api/v1/resep/{id}` | one | `{resep, verifikasi, warning, warning_grup}` | no |
 * | `POST /api/v1/resep/{id}/verifikasi` | one | `{resep, verifikasi, warning, warning_grup, terminal}` | no |
 * | `GET /api/v1/resep/{id}/cek-interaksi` | one | `{resep_id, warning, warning_grup}` | no |
 * | `GET /api/v1/pasien/resep` | list | `{resep: [...]}` | yes |
 *
 * `warning_grup` is the same `ObatInteraksiService::SUMBER`-keyed map on all
 * four surfaces that carry warnings, so a client renders one panel per source
 * whichever endpoint it called - todo 39 established the shape and the later
 * endpoints reuse it rather than inventing a per-controller variant.
 *
 * ## `meta` is ABSENT on the non-lists
 *
 * Omitted, not null, for the reason `ApiResponse`'s docblock gives: `"meta":
 * null` would make every non-list endpoint carry a fourth key a client must
 * null-check, and omission is the only shape in which "this response is not
 * paginated" is unambiguous.
 */
class ResepController extends Controller
{
    public function __construct(
        private readonly ObatSearchService $cari,
        private readonly ResepService $service,
        private readonly ResepVerifikasiService $verifikasiService,
        private readonly ResepAccess $akses,
        private readonly ObatInteraksiService $interaksi,
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
     * `GET /api/v1/resep/{id}` - the prescription, its items, its verification
     * and the CURRENT warning set.
     *
     * `is_kedaluwarsa` is a computed flag, not a status: `berlaku_sampai` is a
     * `DATE` (`:755`) and nothing in the schema reacts to it, so a prescription
     * whose validity lapsed can still read `aktif`. The flag is true when the
     * stored status is `kedaluwarsa` OR the date has passed, and the date
     * comparison is INCLUSIVE.
     *
     * `resep.terminal` is the counterpart: true when the prescription can never
     * be dispensed or re-verified, which is what a `ditolak` verification means
     * given `resep_verifikasi.resep_id` is `UNIQUE` (`:788`).
     *
     * Readable by the prescribing doctor, the patient, and a pharmacist or
     * oversight account; another patient's prescription is 404 and a caller
     * with no profile row is 403. `ResepAccess` owns that split and this
     * controller has no `if` about it.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $resep = $this->akses->untukBaca($this->user($request), $id);

        $peringatan = $this->verifikasiService->peringatan($resep);
        $verifikasi = $resep->relationLoaded('resepVerifikasi')
            ? $resep->getRelation('resepVerifikasi')
            : $this->verifikasiService->sudahDiverifikasi($id);

        return ApiResponse::success(
            [
                'resep' => new ResepResource($resep),
                'verifikasi' => $verifikasi === null
                    ? null
                    : new ResepVerifikasiResource($verifikasi),
                'warning' => $peringatan,
                'warning_grup' => $this->verifikasiService->peringatanGrup($peringatan),
            ],
            'Resep berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `POST /api/v1/resep/{id}/verifikasi` - the pharmacist's single answer.
     *
     * 201 on success, and a 422 for every refusal: an already-verified
     * prescription (the `UNIQUE` on `resep_verifikasi.resep_id`, `:788`), an
     * illegal status transition, a status the pharmacy can no longer sign, and
     * a `kontraindikasi` the re-check found that the pharmacist has not
     * acknowledged in `catatan`.
     *
     * A `ditolak` is FINAL. See `ResepVerifikasiService`'s docblock for why the
     * DDL makes it so and `data.terminal` for what a client should do about it.
     */
    public function verifikasi(VerifikasiResepRequest $request, int $id): JsonResponse
    {
        $hasil = $this->verifikasiService->verifikasi($this->user($request), $id, $request->validated());

        return ApiResponse::success(
            [
                'resep' => new ResepResource($hasil['resep']),
                'verifikasi' => new ResepVerifikasiResource($hasil['verifikasi']),
                'warning' => $hasil['warning'],
                'warning_grup' => $hasil['warning_grup'],
                'terminal' => $hasil['terminal'],
            ],
            'Resep berhasil diverifikasi.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `GET /api/v1/resep/{id}/cek-interaksi` - the CURRENT warning set for the
     * STORED items.
     *
     * This is what a client calls before checkout, and the reason it exists
     * separately from {@see show()} is that the answer CHANGES: `obat_interaksi`
     * and `pasien_alergi` are live tables, so a warning can appear after the
     * prescription was written and acknowledged. The engine is
     * `ObatInteraksiService` unchanged.
     */
    public function cekInteraksi(Request $request, int $id): JsonResponse
    {
        $resep = $this->akses->untukBaca($this->user($request), $id);

        $peringatan = $this->verifikasiService->peringatan($resep);

        return ApiResponse::success(
            [
                'resep_id' => (int) $resep->getKey(),
                'status' => (string) $resep->status,
                'warning' => $peringatan,
                'warning_grup' => $this->verifikasiService->peringatanGrup($peringatan),
                'wajib_catatan' => $this->interaksi->wajibCatatanDokter($peringatan),
            ],
            'Pemeriksaan interaksi obat selesai.',
            Response::HTTP_OK,
        );
    }

    /**
     * `GET /api/v1/pasien/resep` - the caller's own prescriptions, newest first.
     *
     * A `pasien`-path route serving a `resep` resource, exactly as todo 34
     * registered `GET /pasien/surat-keterangan` on `SuratKeteranganController`:
     * a path filter on `resep` cannot see it, and the test asserts the closed
     * set of all four todo-40 routes by URI rather than by prefix.
     *
     * `meta` is a TOP-LEVEL SIBLING from `ApiResponse::pageMeta()`, never
     * wrapped inside `data`.
     */
    public function riwayat(RiwayatResepRequest $request): JsonResponse
    {
        $valid = $request->validated();

        $hasil = $this->akses->riwayat(
            $this->user($request),
            $valid['status'] ?? null,
            (int) ($valid['per_page'] ?? 15),
            (int) ($valid['page'] ?? 1),
        );

        return ApiResponse::success(
            [
                'resep' => ResepResource::collection($hasil->getCollection()),
            ],
            'Riwayat resep berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($hasil),
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
