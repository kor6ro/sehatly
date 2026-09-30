<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PesananObat\CheckoutResepRequest;
use App\Http\Requests\PesananObat\StokObatRequest;
use App\Http\Resources\PesananObatResource;
use App\Http\Resources\StokObatResource;
use App\Models\MasterObat;
use App\Models\User;
use App\Services\Pasien\PasienRecordAccess;
use App\Services\PesananObat\PesananObatService;
use App\Services\PesananObat\StokTidakCukupException;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Three routes: the checkout, the stock read, and the order read.
 *
 * | route | verb | `data` | `meta` |
 * | --- | --- | --- | --- |
 * | `POST /api/v1/resep/{id}/checkout` | one | `{pesanan}` | no |
 * | `GET /api/v1/obat/{id}/stok` | one | `{stok}` | no |
 * | `GET /api/v1/pesanan-obat/{id}` | one | `{pesanan}` | no |
 *
 * `meta` is ABSENT on all three, for the reason `ApiResponse`'s docblock gives:
 * `"meta": null` would make every non-list endpoint carry a fourth key a client
 * must null-check, and omission is the only shape in which "this response is not
 * paginated" is unambiguous. None of these three is a list, so none carries one.
 *
 * ## 404 for another patient's row, 403 for an account with no patient row
 *
 * The checkout resolves the caller's own `pasien` through
 * `PasienRecordAccess::ownPasien()` and then the prescription is scoped to it, so
 * another patient's prescription is a 404 about the ROW while an account that
 * owns no profile at all is a 403 about the CALLER. A 403 on a row that exists
 * would confirm it exists, which over a sequential `BIGINT` key is a
 * cross-tenant existence oracle. The order read splits the same way, with the
 * pharmacy-or-oversight half answered in `PesananObatService::untukBaca()`.
 *
 * ## `StokTidakCukupException` is caught here and nowhere else
 *
 * A bare `RuntimeException` would render as the sanitised 500
 * `bootstrap/app.php` reserves for faults nobody planned for, so the domain
 * exception is converted to a 422 with its own `errors()` payload - the same
 * shape `BookingController` gives `SlotTakenException`. The exception is never
 * left to bubble.
 */
class PesananObatController extends Controller
{
    public function __construct(
        private readonly PesananObatService $service,
        private readonly PasienRecordAccess $access,
    ) {}

    /**
     * `POST /api/v1/resep/{id}/checkout` - 201 with the order.
     *
     * A prescription-only checkout: the order's products ARE the prescription's
     * `resep_item` rows, and `obat_bebas` / `produk_kesehatan` are refused AT
     * CREATION with a 422 naming the missing line-item table, so no order, no
     * invoice and no tracking row is written for either.
     *
     * The 422s this route can answer, all through the standard envelope:
     * a prescription that is not `diverifikasi` or later, one past
     * `berlaku_sampai`, a non-prescription `tipe`, a racikan line that cannot be
     * priced, a `faskes` that is not an active `apotek`, and a pharmacy that
     * does not have enough of a drug.
     */
    public function checkout(CheckoutResepRequest $request, int $id): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request));

        try {
            $pesanan = $this->service->buat($this->user($request), $pasien, $id, $request->validated());
        } catch (StokTidakCukupException $e) {
            return ApiResponse::error($e->getMessage(), $e->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ApiResponse::success(
            ['pesanan' => new PesananObatResource($pesanan)],
            'Pesanan obat berhasil dibuat.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `GET /api/v1/obat/{id}/stok` - 200, always, for a readable request.
     *
     * A drug that does not exist is a 404 rather than a 200 with an empty
     * alternatives list: "no such drug" and "no pharmacy carries it" are
     * different answers and collapsing them would send a client looking for a
     * drug that was never in the catalogue.
     *
     * A bad `apotek_id` is a 422 rather than a 404, and the distinction is the
     * same one the checkout makes: a caller who named a facility that is not a
     * pharmacy has made a different mistake from a caller who named one that
     * does not exist, and the two need different fixes.
     */
    public function stok(StokObatRequest $request, int $id): JsonResponse
    {
        $obat = MasterObat::query()->whereKey($id)->first();

        if ($obat === null) {
            throw (new ModelNotFoundException)->setModel(MasterObat::class, [$id]);
        }

        $valid = $request->validated();

        $jumlah = (int) ($valid['jumlah'] ?? 1);

        $apotekId = isset($valid['apotek_id']) ? (int) $valid['apotek_id'] : null;

        try {
            $hasil = $this->service->cekStok($id, $apotekId, $jumlah);
        } catch (StokTidakCukupException $e) {
            return ApiResponse::error($e->getMessage(), $e->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ApiResponse::success(
            ['stok' => new StokObatResource($hasil)],
            'Stok obat berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `GET /api/v1/pesanan-obat/{id}` - the order with its tracking trail.
     *
     * Readable by the patient who owns it, or by a pharmacist or an oversight
     * account. Another patient's order is a 404 and an account that owns no
     * patient row is a 403 - the split `PesananObatService::untukBaca()` decides
     * and this method has no `if` about it.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $pesanan = $this->service->untukBaca($this->user($request), $id);

        return ApiResponse::success(
            ['pesanan' => new PesananObatResource($pesanan)],
            'Pesanan obat berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * The authenticated `User`, narrowed for the static analyser.
     *
     * Every action here runs behind `auth:sanctum`, so `user()` is never null;
     * the cast is the framework's documented way to say so rather than an
     * unchecked null.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
