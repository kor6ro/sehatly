<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Invoice;
use App\Models\MasterMetodePembayaran;
use Illuminate\Http\Request;

/**
 * The seam between this application and whatever moves the money.
 *
 * ## Why an interface, and what it actually buys
 *
 * There is no real payment provider in this application: the plan puts real
 * Midtrans and Xendit out of scope, and `telemedicine_test.sql` has no
 * gateway table, no API key column and no callback table to write into. The
 * only shipped implementation is {@see MockPaymentGatewayService}. An interface
 * with one implementation is a smell in the abstract - so the reason it exists
 * is stated, because it is not "so we can swap providers someday":
 *
 * 1. **The two methods have genuinely different providers, and one of them
 *    cannot be faked at the call site.** `verifyWebhook()` is the security
 *    boundary of an UNAUTHENTICATED endpoint. If the controller talked to a
 *    concrete class, a test could not substitute a stub without touching the
 *    controller, and the code that decides "is this signature good" would be
 *    the same code that decides the HTTP envelope. Putting it behind a
 *    contract makes "the signature is checked" a property of the wiring, and
 *    the test asserts the container resolves the INTERFACE.
 * 2. **The provider's own vocabulary is not this application's.** A real
 *    Midtrans integration returns `transaction_id`, `bank_code` and
 *    `transaction_status`, and its notification carries `signature` in a
 *    header of its own naming. None of those names appear in
 *    `telemedicine_test.sql`, and inventing them in a controller would put a
 *    third party's schema into the middle of our Modules. The interface speaks
 *    in `nomor_referensi` (:963), `jumlah` (:962) and the three settlement
 *    outcomes of {@see \App\Enums\PembayaranStatus::SETTLE} - our names - and
 *    the adapter translates.
 *
 * ## `createTransaction()` returns an ARRAY and not a DTO, deliberately
 *
 * The plan specifies `array`, and the return is genuinely a heterogeneous
 * bag - a gateway may return a VA number, a QR string, both, or neither, plus
 * free-text instructions. The array's KEYS are the contract, so they are
 * documented per key below and asserted by
 * `the mock mints a reference, a VA number and a QR string, all inside their
 * columns`, which reads the keys off a real call rather than off a comment.
 *
 * ## Nothing here touches the database
 *
 * Both methods are pure with respect to `pembayaran`: the interface has no
 * model to save, no transaction and no query. Deciding what a delivery MEANS is
 * {@see PaymentService}'s job, and the separation is what lets the concurrency
 * argument live in one place: this contract mints and verifies,
 * {@see PaymentService} applies exactly once.
 *
 * @see \App\Services\Payment\MockPaymentGatewayService
 * @see \App\Services\Payment\PaymentService
 */
interface PaymentGatewayService
{
    /**
     * The `{gateway}` ENUM member this instance speaks for.
     *
     * `pembayaran.gateway` is `ENUM('midtrans','xendit','doku','flip') NULL`
     * (telemedicine_test.sql:965), and {@see PaymentService} writes this value
     * on every row it mints. A real Midtrans adapter returns
     * `PembayaranGateway::Midtrans->value`; the mock returns
     * `config('payment.gateway_pembayaran')` because the plan puts a real
     * provider out of scope and the mock has to pretend to be one.
     */
    public function nama(): string;

    /**
     * Can this implementation speak for `$kode`?
     *
     * A separate question from {@see nama()} on purpose. The mock speaks for
     * **all four** ENUM members, because it is a stand-in for whichever one a
     * deployment configured and a test needs to deliver a `doku` webhook at a
     * `midtrans` payment to prove the pair is the dedupe key. A real adapter
     * would return `true` for exactly one. The route constrains `{gateway}` to
     * the enum independently, so this is the second of two checks and the one
     * that lives in the service.
     */
    public function dapatkah(string $kode): bool;

    /**
     * Open a transaction with the provider and describe how to pay it.
     *
     * Called once per payment attempt, from `POST /api/v1/invoice/{id}/bayar`.
     * It writes NOTHING: the caller decides whether to persist the returned
     * reference, so a gateway failure cannot leave a half-created payment.
     *
     * The returned array, by key:
     *
     * - `gateway` - the `pembayaran.gateway` ENUM value (:965) to record;
     * - `nomor_referensi` - the provider's transaction id, `VARCHAR(100)` at
     *   (:963), and the dedupe key's right-hand half. It is NULLABLE in the
     *   schema and this implementation never returns null: a payment with no
     *   reference could not be settled by a webhook at all;
     * - `jumlah` - the amount being collected, a DECIMAL **string** (:962), and
     *   it is the value read off `invoice.total` rather than recomputed, so a
     *   patient is never asked for a number the invoice does not say;
     * - `instruksi` - `list<string>`, the steps to show a patient, in order;
     * - `toko` - the channel payload: `va_number` (at most 30 characters, the
     *   width of :964) for an account-style method, or `qr_string` for a
     *   scanned one. **Never both** - the schema has one `va_number` column and
     *   no `qr_string` column, so a gateway that returned both would have
     *   nowhere to put the second.
     *
     * @param  Invoice  $invoice  the invoice being paid. Its `total` is the
     *                            amount; the implementation MUST NOT recompute
     *                            it, because the admin fee in it was computed
     *                            once by `InvoiceService`.
     * @param  MasterMetodePembayaran  $metode  the method the patient chose.
     *                                           Its `tipe` (:929) is what
     *                                           decides `toko`'s shape, since
     *                                           the schema maps no method to a
     *                                           gateway.
     * @return array{gateway: string, nomor_referensi: string, jumlah: string, instruksi: list<string>, toko: array<string, string>}
     */
    public function createTransaction(Invoice $invoice, MasterMetodePembayaran $metode): array;

    /**
     * Verify a provider notification and return it in THIS application's
     * vocabulary.
     *
     * The ONLY method on an unauthenticated route that decides whether a
     * stranger's request is allowed to do anything at all, so its contract is
     * written as a refusal list:
     *
     * - it MUST verify a signature over the RAW body before parsing anything,
     *   because parsing first would let an unsigned request choose which error
     *   it gets and probe the endpoint;
     * - it MUST throw rather than return a "not valid" value, so a caller
     *   cannot accidentally treat a rejection as a delivery. A missed
     *   `try`/`catch` produces a 500, which is a safe failure;
     * - it MUST NOT touch the database, and the test proves that by asserting a
     *   forged request issues zero statements naming `pembayaran`, `invoice` or
     *   `booking` - a check no "the rows did not change" assertion could make.
     *
     * @param  Request  $request  the raw request. `getContent()` is the signed
     *                            bytes; the implementation must not re-encode
     *                            the decoded body.
     * @return array{nomor_referensi: string, status: string, jumlah: string, gateway: string|null, payload: array<string, mixed>}
     *         `status` is one of {@see \App\Enums\PembayaranStatus::SETTLE};
     *         `jumlah` is a DECIMAL string parsed through
     *         {@see \App\Support\Uang\Uang}, so a JSON number is a refusal and
     *         not a rounding; `payload` is the decoded body VERBATIM, for
     *         `pembayaran.webhook_payload` (:968).
     *
     * @throws TandaTanganWebhookTidakValid  on an absent, malformed or wrong
     *                                        signature - a 401
     * @throws \Illuminate\Validation\ValidationException  on a body that is
     *         correctly signed but unusable, each message filed on the field it
     *         came in on
     */
    public function verifyWebhook(Request $request): array;
}
