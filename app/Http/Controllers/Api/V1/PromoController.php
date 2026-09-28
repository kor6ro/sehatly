<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\ValidasiPromoRequest;
use App\Models\Invoice;
use App\Models\MasterPromo;
use App\Models\Pasien;
use App\Models\User;
use App\Services\Invoice\PromoHitungan;
use App\Services\Invoice\PromoService;
use App\Services\Pasien\PasienRecordAccess;
use App\Support\ApiResponse;
use App\Support\Uang\Uang;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One route: `POST /api/v1/promo/validasi`.
 *
 * ## It is a PURE CALCULATION, and the schema is what forces that
 *
 * The plan calls for a validation call that writes nothing, and the reason is
 * not caution - it is `promo_redemption.invoice_id BIGINT UNSIGNED NOT NULL`
 * (telemedicine_test.sql:1004) with `FOREIGN KEY (invoice_id) REFERENCES
 * invoice(id)` (:1009). A validation call has no invoice to attach a redemption
 * to, and minting an invoice to hold the row would be precisely the write the
 * endpoint is defined not to perform. So the redemption is written by
 * `InvoiceService` at the moment the promo is ACTUALLY applied, and this
 * controller reads.
 *
 * The test asserts zero `promo_redemption` rows and zero `invoice` rows after a
 * successful validation, which is the only way to prove the word "pure" rather
 * than to assert it in a docblock.
 *
 * ## 200 with `valid: false`, not 422
 *
 * A caller asking "would this code work for me?" is asking a QUESTION, and "no,
 * and here is exactly which of the five rules it breaks" is the answer. A 422
 * would be a refusal to answer, and the four-or-five reasons would have to be
 * smuggled into `errors` to survive at all. So the envelope is the SUCCESS one
 * with `valid: false` and the reasons in `data.alasan`, and the reason codes
 * are machine-readable so a client branches on `kode` rather than on prose.
 *
 * The apply path is different and deliberately so: `InvoiceService::buat()`
 * DOES answer 422 through `PromoHitungan::tolak()`, because there the caller
 * has asked for something to happen and the answer is no.
 *
 * ## 404 for another patient's invoice, 403 for no patient row
 *
 * The invoice is read as `Invoice::whereBelongsTo($pasien)` - the tenant filter
 * IS the query, so somebody else's invoice is simply not found and cannot be
 * told apart from one that never existed. `PasienRecordAccess::ownPasien()`
 * raises the 403 for an account that owns no `pasien` row, and it raises it
 * BEFORE this query, so the two answers stay in the order that does not leak.
 *
 * ## The numbers are recomputed from the invoice, never taken from the request
 *
 * The request carries `kode` and `invoice_id` and NOTHING else. `subtotal` comes
 * off the stored row, so a client cannot talk the server into a discount on a
 * purchase it did not make, and cannot replay a validation computed against a
 * different basket. The recomputation is read-only and lives in
 * `PromoService::hitung()`, the same method the apply path's rules come from
 * with only the locking difference between them.
 */
class PromoController extends Controller
{
    public function __construct(
        private readonly PromoService $promo,
        private readonly PasienRecordAccess $access,
    ) {}

    /**
     * `POST /api/v1/promo/validasi` - 200, always, for a readable request.
     *
     * The only non-200 answers are the 401 from `auth:sanctum`, the 422 from the
     * FormRequest, the 403 for an account with no `pasien` row and the 404 for
     * an invoice that is not the caller's. Every one of them comes from a layer
     * ABOVE this method, which is why the method itself has no `if` about
     * ownership - the same discipline `RekamMedisController` and
     * `KonsultasiController` follow.
     */
    public function validasi(ValidasiPromoRequest $request): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request));

        $invoice = $this->invoiceMilik($pasien->getKey(), (int) $request->validated('invoice_id'));

        $subtotal = (string) $invoice->subtotal;
        $pengiriman = (string) $invoice->biaya_pengiriman;

        $promo = MasterPromo::query()->where('kode', trim((string) $request->validated('kode')))->first();

        // An unknown code is NOT a 404 and NOT a 422. The caller asked whether
        // a code works; the answer is that it is not one of the codes, and the
        // envelope is the SUCCESS one so a client can render it in the same
        // place it renders any other refusal.
        $hitungan = $promo === null
            ? PromoHitungan::kodeTidakDitemukan($pengiriman)
            : $this->promo->hitung($promo, $subtotal, $pengiriman, (int) $pasien->getKey());

        $diskon = $hitungan->diskon;
        $total = Uang::jumlah(
            Uang::kurang($subtotal, $diskon),
            (string) $invoice->biaya_admin,
            $hitungan->biayaPengiriman
        );

        return ApiResponse::success([
            'promo' => [
                'kode' => $promo?->kode,
                'nama' => $promo?->nama,
                'tipe_diskon' => $promo?->tipe_diskon,
            ],
            'invoice' => [
                'id' => (int) $invoice->getKey(),
                'nomor_invoice' => $invoice->nomor_invoice,
            ],
            'valid' => ! $hitungan->ditolak(),
            'nilai_diskon' => $diskon,
            'total' => $total,
            'rincian' => [
                'subtotal' => $subtotal,
                'diskon' => $diskon,
                'biaya_admin' => (string) $invoice->biaya_admin,
                'biaya_pengiriman' => $hitungan->biayaPengiriman,
                'total' => $total,
            ],
            // The reasons as a plain LIST, in rule order, each with a stable
            // `kode` and the field it reports on - so one client map renders
            // both this 200 and the 422 the apply path raises.
            'alasan' => $hitungan->alasan,
        ], $hitungan->ditolak()
            ? 'Kode promo tidak dapat digunakan.'
            : 'Kode promo dapat digunakan.');
    }

    /**
     * The caller's own invoice, or a 404.
     *
     * `whereBelongsTo` rather than `findOrFail` on the id alone: the tenant
     * filter IS the query, so another patient's invoice is not found rather than
     * refused, and the caller cannot tell the two apart.
     *
     * `MasterPromo::query()->where('kode', ...)` is a `VARCHAR(30) UNIQUE` probe
     * (:987), and the shipping charge and admin fee are read back off the STORED
     * row rather than recomputed from the request, so a client cannot present a
     * subtotal the server never charged.
     */
    private function invoiceMilik(int $pasienId, int $invoiceId): Invoice
    {
        $invoice = Invoice::query()
            ->whereBelongsTo(Pasien::query()->findOrFail($pasienId))
            ->whereKey($invoiceId)
            ->first();

        if ($invoice === null) {
            throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoiceId]);
        }

        return $invoice;
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
