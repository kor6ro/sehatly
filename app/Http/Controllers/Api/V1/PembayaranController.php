<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\BayarInvoiceRequest;
use App\Http\Requests\Payment\IndexRefundRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PembayaranResource;
use App\Http\Resources\RefundResource;
use App\Models\Invoice;
use App\Models\MasterMetodePembayaran;
use App\Models\Pasien;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Services\Pasien\PasienRecordAccess;
use App\Services\Payment\PaymentGatewayService;
use App\Services\Payment\PaymentService;
use App\Services\Payment\RefundService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Four routes, and the gap between them is the whole design of this module.
 *
 * | route | auth | who |
 * | --- | --- | --- |
 * | `GET /api/v1/invoice/{id}` | `auth:sanctum` + `permission:pembayaran.bayar` | the patient, reading their own invoice |
 * | `POST /api/v1/invoice/{id}/bayar` | `auth:sanctum` + `permission:pembayaran.bayar` | the patient, for their own invoice |
 * | `GET /api/v1/pasien/refund` | `auth:sanctum` + `permission:pembayaran.bayar` | the patient, reading their own refunds |
 * | `POST /api/v1/webhook/payment/{gateway}` | **none** - verified by HMAC instead | a payment provider |
 *
 * The refund read (F12) reuses `pembayaran.bayar` for the same reason the
 * invoice read does: the catalogue has no `pembayaran.lihat`, and
 * `EnsurePermission` treats an unknown code as a 500 rather than a 403, so
 * inventing one here would deny every caller until the catalogue moved with
 * it. The code already refuses `dokter`, `apoteker` and `admin` and admits
 * exactly the account type that can own a refund.
 *
 * ## The read path adds a Policy, and the ORDER of the two checks is the design
 *
 * `show()` is the one method here that also runs the new
 * {@see InvoicePolicy}. It still fetches through
 * {@see invoiceMilik()} FIRST, because that tenant-scoped lookup is what makes
 * another patient's invoice a 404 rather than a 403; the policy runs after it as
 * defence in depth. Its docblock carries the full argument.
 *
 * ## The webhook is UNAUTHENTICATED, so the signature is the authentication
 *
 * A payment provider is not a user of this system: it holds no Sanctum token
 * and cannot be given one. So this route carries no `auth:sanctum`, and the
 * only thing standing between the internet and "mark any invoice paid" is an
 * HMAC-SHA256 over the raw body keyed by that gateway's secret in
 * `config/services.php`.
 *
 * Which makes the ORDER the design, and it is the order in the method body
 * below:
 *
 * 1. `$request->route('gateway')` has already been narrowed to the four
 *    `pembayaran.gateway` ENUM members by a route constraint, so the code that
 *    reads a secret is unreachable for a segment outside them.
 * 2. `verifyWebhook()` runs, and it verifies BEFORE it parses. A forged request
 *    is refused here.
 * 3. `terimaWebhook()` runs, and it is the first thing in this controller that
 *    can issue a query against `pembayaran` or `invoice`.
 *
 * The test `a forged signature is rejected with NO state change AND no database
 * read` asserts step 2 by listening on `QueryExecuted` and requiring ZERO
 * statements naming those tables. A "the rows did not change" assertion could
 * not make that claim: a handler that read a row and then rejected the signature
 * would also leave the rows unchanged.
 *
 * ## A retry is a SUCCESS, not an error
 *
 * The second delivery of a reference that is already settled answers **200**,
 * with the same envelope, the same `data` keys and the same values as the first
 * - differing only in `data.duplicate`. A 4xx or a 5xx here is actively harmful:
 * a provider that is told its delivery failed will retry it, and one that gives
 * up has taken the patient's money with no record of it in our books. The test
 * diffs the two decoded bodies and requires the difference set to be exactly
 * `data.duplicate`.
 *
 * ## Ownership: 404 for another patient's row, 403 for a caller with none
 *
 * The same rule `PasienRecordAccess` states for every other patient surface, in
 * the same order: `ownPasien()` raises the 403 about the CALLER before any
 * invoice is read, and the invoice is then read as
 * `Invoice::whereBelongsTo($pasien)`, so another patient's row is not found
 * rather than refused. A 403 there would confirm the row exists.
 *
 * ## No `webhook_payload` is ever published
 *
 * `PembayaranResource` omits it, and this controller has no branch that adds
 * it back. The column is the operator's forensic record of what a third party
 * sent; reflecting it at a payer would hand a provider's document shape to a
 * client with no use for it.
 */
class PembayaranController extends Controller
{
    /**
     * `$this->authorize()`: the base controller is empty and this is the first
     * method in the application to need the Gate, so the trait is pulled in
     * HERE rather than on the shared base class. One controller that authorizes
     * should not make every other controller carry the trait.
     */
    use AuthorizesRequests;

    public function __construct(
        private readonly PaymentService $pembayaran,
        private readonly PaymentGatewayService $gateway,
        private readonly PasienRecordAccess $access,
        private readonly RefundService $refund,
    ) {}

    /**
     * `POST /api/v1/invoice/{id}/bayar` - 201, and a virtual account.
     *
     * The 404 is raised by the tenant-scoped lookup in {@see invoiceMilik()}
     * and the 403 by `ownPasien()` before it, so this method has no `if` about
     * ownership at all - the same discipline `RekamMedisController` and
     * `PromoController` follow, and the reason a future edit to this surface
     * cannot forget one of them.
     */
    public function bayar(BayarInvoiceRequest $request, int $id): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request));

        $invoice = $this->invoiceMilik((int) $pasien->getKey(), $id);

        $metode = MasterMetodePembayaran::query()->whereKey((int) $request->validated('metode_id'))->firstOrFail();

        [$pembayaran, $transaksi] = $this->pembayaran->mulai($invoice, $metode);

        return ApiResponse::success(
            [
                'invoice' => [
                    'id' => (int) $invoice->getKey(),
                    'nomor_invoice' => $invoice->nomor_invoice,
                    'status' => (string) $invoice->status,
                    'total' => (string) $invoice->total,
                ],
                'pembayaran' => new PembayaranResource($pembayaran),
                'gateway' => [
                    'nama' => (string) $transaksi['gateway'],
                    'nomor_referensi' => (string) $transaksi['nomor_referensi'],
                ],
                // The channel payload and the steps, exactly as the gateway
                // minted them. `toko` is the `va_number` OR the `qr_string` -
                // `pembayaran` has one `va_number VARCHAR(30)` column (:964)
                // and no `qr_string` column, so the QR string is returned to
                // the client and never stored. That is a schema limitation and
                // it is recorded rather than worked around with a column the
                // DDL does not have.
                'toko' => $transaksi['toko'],
                'instruksi' => $transaksi['instruksi'],
            ],
            'Pembayaran berhasil dimulai.',
            JsonResponse::HTTP_CREATED,
        );
    }

    /**
     * `GET /api/v1/invoice/{id}` - 200 with the caller's own invoice and its
     * payment history, or the same 404 a row that never existed produces.
     *
     * ## The FETCH precedes the AUTHORIZE, and that order is the rule
     *
     * `invoiceMilik()` runs first because it is where the 404 is decided: the
     * invoice is read as `Invoice::whereBelongsTo($pasien)` and a miss is a
     * `ModelNotFoundException`, which `bootstrap/app.php` renders as the
     * sanitised 'Resource not found.'. Authorizing first would answer 403 for a
     * row that exists, which is the cross-tenant existence oracle
     * `PasienRecordAccess` forbids and every other patient surface refuses to
     * emit.
     *
     * So `authorize('view', $invoice)` only ever runs on an invoice that the
     * caller's own patient row already matched, and {@see InvoicePolicy}
     * is defence in depth: the scoped query is the PRIMARY rule, and the policy
     * is the same rule stated where a unit test can assert it directly, so a
     * future edit that dropped the scope would still fail closed.
     *
     * ## `pembayaran` is loaded here so the resource cannot N+1
     *
     * One relation, one extra query, and `InvoiceResource` publishes it newest
     * first. Loading it in the resource would make the query count depend on
     * which relation the resource happened to touch.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request));

        $invoice = $this->invoiceMilik((int) $pasien->getKey(), $id);

        // Defence in depth. Unreachable through the scoped fetch above, and
        // deliberately kept so the explicit rule is enforced, not just stated.
        $this->authorize('view', $invoice);

        $invoice->load('pembayaran');

        return ApiResponse::success(
            ['invoice' => new InvoiceResource($invoice)],
            'Tagihan berhasil dimuat.',
        );
    }

    /**
     * `GET /api/v1/pasien/refund` - the caller's own refunds, newest first,
     * paginated with the project `meta` block.
     *
     * The path prefix names the CALLER (`pasien`) and this controller names
     * the RESOURCE (`refund` is a payment event, not a booking field), the
     * same split todo 34 and todo 40 used for `pasien/surat-keterangan` and
     * `pasien/resep`; putting a payment list on `PasienController` would make
     * that controller the owner of two modules.
     *
     * ## 403 for the caller, none of another patient's rows
     *
     * `ownPasien()` runs first and raises the 403 for an account that owns no
     * `pasien` row - including a doctor account, which the route's
     * `permission:pembayaran.bayar` gate already refuses one layer earlier.
     * The list is then scoped through `invoice.pasien_id` inside
     * `RefundService::daftarPasien()`, so another patient's refund is absent
     * rather than refused. `dibuat_at DESC, id DESC` is a total order, so
     * paging cannot repeat or skip a row.
     */
    public function refundIndex(IndexRefundRequest $request): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request));

        $rows = $this->refund->daftarPasien($pasien, $request->validated())->withQueryString();

        return ApiResponse::success(
            ['refund' => RefundResource::collection($rows->getCollection())],
            'Daftar refund berhasil dimuat.',
            JsonResponse::HTTP_OK,
            ApiResponse::pageMeta($rows),
        );
    }

    /**
     * `POST /api/v1/webhook/payment/{gateway}` - 200 for a delivery we accept
     * and for a delivery we have already applied.
     *
     * The only non-200 answers are the router's 404 for a `{gateway}` outside
     * the ENUM, the 401 from {@see PaymentGatewayService::verifyWebhook()} and
     * the 422 it raises on a correctly-signed but unusable body. The 404 for a
     * `(gateway, nomor_referensi)` pair that names no payment is raised from
     * the service as a `ModelNotFoundException` and rendered by the kernel.
     *
     * @param  string  $gateway  already narrowed to the four ENUM members by the
     *                           route's `whereIn`, so this method's first act is
     *                           the signature check
     */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        // (2) VERIFY FIRST. Nothing above this line has touched the database.
        $terverifikasi = $this->gateway->verifyWebhook($request);

        // (3) The only place in this method that can write.
        $hasil = $this->pembayaran->terimaWebhook($gateway, $terverifikasi);

        $pembayaran = $hasil['pembayaran'];
        $invoice = $hasil['invoice'];

        return ApiResponse::success(
            [
                // The ONE key that differs between a first delivery and a
                // retry. `false` on a delivery that applied the change, `true`
                // on one that found the reference already decided.
                'duplicate' => $hasil['duplicate'],
                'pembayaran' => new PembayaranResource($pembayaran),
                'invoice' => [
                    'id' => (int) $invoice->getKey(),
                    'nomor_invoice' => $invoice->nomor_invoice,
                    'status' => (string) $invoice->status,
                    'lunas_at' => $invoice->lunas_at?->toISOString(),
                ],
                // What happened to the record this invoice points at. `advanced`
                // is a fact about the ROW, not about this delivery, so a retry
                // reports the same value the first one did.
                'referensi' => $hasil['referensi'],
            ],
            $hasil['duplicate']
                ? 'Pembayaran ini sudah pernah diproses.'
                : 'Status pembayaran berhasil diperbarui.',
        );
    }

    /**
     * The caller's own invoice, or a 404.
     *
     * `whereBelongsTo($pasien)` rather than `findOrFail($id)`: the tenant filter
     * IS the query, so another patient's invoice is not found rather than
     * refused, and the caller cannot tell the two apart. `bootstrap/app.php`
     * replaces the message with 'Resource not found.', so the body discloses
     * nothing about the model layer.
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
