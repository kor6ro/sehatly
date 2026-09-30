<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\InvoiceStatus;
use App\Enums\PembayaranStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\MasterMetodePembayaran;
use App\Models\Pembayaran;
use App\Models\PesananObat;
use App\Models\Resep;
use App\Services\Notifikasi\NotificationService;
use App\Support\Uang\Uang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment initiation and the settlement of a provider's notification, and the
 * idempotency argument that lives in one place.
 *
 * ## The dedupe key is `(gateway, nomor_referensi)`, and it is
 * APPLICATION-LEVEL
 *
 * `pembayaran.gateway` is `ENUM('midtrans','xendit','doku','flip') NULL`
 * (telemedicine_test.sql:965) and `pembayaran.nomor_referensi` is
 * `VARCHAR(100) NULL COMMENT 'Transaction ID payment gateway'` (:963).
 *
 * **Neither is unique and neither has an index.** The only index on the table is
 * `INDEX idx_bayar_status (status, dibayar_at)` (:972) - over two columns that
 * are in neither half of the key - plus the three single-column indexes MySQL
 * synthesises for the two foreign keys. The plan's todo 45 forbids adding a
 * unique index or a column, and the DDL is read-only law, so the guard below is
 * an APPLICATION-level one with a known race. It is stated in the class
 * docblock, in {@see huntap()} and in the tests rather than left as a comment
 * somebody has to remember to read.
 *
 * ### Where the key comes from
 *
 * - `gateway` is the `{gateway}` PATH SEGMENT, narrowed to the four ENUM
 *   members by a route constraint before this class runs. A caller cannot make
 *   up a value the schema would refuse.
 * - `nomor_referensi` is the value THIS APPLICATION minted at initiation and
 *   the provider echoed back in a body it SIGNED. It is not free-form input: a
 *   stranger who guesses one still cannot produce a valid signature.
 *
 * That is what makes an application-level key safe to use here. The usual
 * reason not to trust an application-level dedupe key is that a client can
 * choose it, and here the key is chosen by us and attested by the provider.
 *
 * ### Sequential delivery: exact
 *
 * The first delivery takes the row out of `pending`; the second sees a terminal
 * status and returns 200 with `duplicate: true` and writes nothing. That is the
 * acceptance criterion, and it is measured with three independent counters -
 * `pembayaran.dibayar_at`, `invoice.lunas_at`, and the `audit_log` `update` row
 * count - against a clock that is ADVANCED between the two deliveries so a
 * re-application cannot be a no-op that looks like one.
 *
 * ### CONCURRENT delivery: a real race, closed by a row lock
 *
 * Two identical deliveries arriving at the same instant CAN both read `pending`
 * before either writes. The schema cannot prevent that and no index may be
 * added. What closes it is {@see huntap()}'s `lockForUpdate()`:
 *
 * 1. The lookup is `SELECT ... FROM pembayaran WHERE gateway = ? AND
 *    nomor_referensi = ? FOR UPDATE`, taken INSIDE the transaction. It is a
 *    LOCKING read, so InnoDB resolves it against the LATEST committed version
 *    rather than the transaction's own snapshot - which is the whole reason it
 *    is a locking read and not a plain one. A plain read would let the second
 *    transaction resolve against a snapshot taken before the first committed,
 *    see `pending`, and write anyway.
 * 2. The second transaction BLOCKS on that row. When it unblocks, the first has
 *    committed, and its `FOR UPDATE` re-reads the current row - now terminal -
 *    and takes the duplicate branch.
 * 3. `PaymentConcurrencyTest` proves the lock is real by making a second
 *    connection collide on it and observing MySQL error 1205, and proves the
 *    converse by removing the lock and watching two real transactions each
 *    write.
 *
 * ### What is NOT closed, and it is stated rather than hidden
 *
 * A `SELECT ... FOR UPDATE` whose predicate matches no index takes **gap
 * locks** on the range it scans. The key's two columns are in no index, so the
 * predicate is a full scan of `pembayaran` and a settlement therefore locks
 * every row it examines, not just the one it wants. Two unrelated payments can
 * briefly serialise behind each other. That is a throughput cost, not a
 * correctness one, and it is the price of the DDL being read-only: the only
 * index available is `idx_bayar_status`, which does not contain the key. A
 * future migration that adds `INDEX (gateway, nomor_referencia)` would turn the
 * scan into a point probe and is recorded in the evidence file as the one
 * change that would matter - it is NOT made here, because the plan forbids it.
 *
 * ## The `webhook_payload` overwrite decision: a re-delivery does NOT write
 *
 * `pembayaran.webhook_payload` is `JSON NULL` (:968) and it is the FORENSIC
 * RECORD of the delivery that caused the state change. There is exactly one of
 * those. A retry carries no new information, and letting it write would replace
 * the evidence with a near-copy - so the duplicate branch returns before any
 * assignment, which makes the decision a structural consequence of the
 * idempotency guard rather than a policy somebody has to remember. The test
 * re-delivers a body with an extra key and asserts the stored JSON does not
 * gain it.
 *
 * ## The referenced entity is advanced here because the SCHEMA does nothing
 *
 * `invoice.referensi_tipe` is a six-value ENUM (:940) pointing at
 * `referensi_id`, which is a BARE column with no foreign key (:941). `booking`
 * (`:515-516`), `resep` (`:751-752`) and `pesanan_obat` (`:810-811`) each have a
 * paid-adjacent state, and NONE of them has a trigger, a generated column or an
 * event - so a payment that settles would leave its booking sitting in
 * `menunggu_pembayaran` forever with a `lunas` invoice pointing at it.
 *
 * {@see LANJUT} is the map, and it is `public const` so a test asserts the
 * closed set: booking -> `terjadwal`, resep -> `diproses`, pesanan_obat ->
 * `diproses`. **`konsultasi` is deliberately absent** and that is a DDL fact,
 * not an omission: `konsultasi.status` is a six-value ENUM at `:542-543`
 * (`menunggu_dokter, berlangsung, menunggu_resep, selesai, dibatalkan, gagal`)
 * and **none of the six means "paid"**. Advancing one would be inventing a
 * state the schema does not have, so a consultation invoice settles with the
 * invoice `lunas` and the consultation untouched, which is recorded rather
 * than worked around.
 *
 * The advance is guarded on the SOURCE state, so a late delivery for a
 * `dibatalkan` booking records the money and does NOT resurrect the booking.
 * The invoice still goes `lunas` - the money is real - and the response says
 * what it did, so the discrepancy is visible rather than silent.
 *
 * @see \App\Services\Payment\PaymentGatewayService the provider contract
 * @see \App\Services\Payment\MockPaymentGatewayService the shipped gateway
 */
final class PaymentService
{
    /**
     * The four `referensi_tipe` values (telemedicine_test.sql:940) that payment
     * can settle, each naming the model and the states the settlement moves
     * between.
     *
     * PUBLIC and asserted as a closed set, because a fifth entry added without a
     * DDL decision would be a state machine nobody reviewed. The three keys are
     * the reachable members of `InvoiceService::SUMBER_REFERENSI`; `konsultasi`
     * has no paid-adjacent state at `:542-543` and `lab_permintaan` /
     * `home_care` are out of scope for the whole of Modules 1-5.
     *
     * `dari` is the state the entity must currently be in for the advance to
     * apply. It is not decoration: without it, a delivery for a `dibatalkan`
     * booking would move it to `terjadwal`, which is a resurrection.
     *
     * @var array<string, array{model: class-string<Model>, ke: string, dari: list<string>}>
     */
    public const LANJUT = [
        'booking' => [
            'model' => Booking::class,
            'ke' => 'terjadwal',
            // `booking.status` is an eight-value ENUM at :515-516 whose first
            // member is `menunggu_pembayaran`, the state a booking is born in.
            'dari' => ['menunggu_pembayaran'],
        ],
        'resep' => [
            'model' => Resep::class,
            'ke' => 'diproses',
            // `resep.status` is an eight-value ENUM at :751-752 whose first
            // member is `aktif`. `aktif -> diproses` is also the first legal
            // edge of `ResepStateMachine::TRANSISI`, so the advance agrees with
            // the prescription state machine rather than bypassing it.
            'dari' => ['aktif'],
        ],
        'pesanan_obat' => [
            'model' => PesananObat::class,
            'ke' => 'diproses',
            // `pesanan_obat.status` is a six-value ENUM at :810-811, first
            // member `menunggu_pembayaran`. The STOCK decrement for this row is
            // deliberately NOT here: it belongs to the checkout module, which
            // reads `resep_item` and `apotek_stok` and owns the guarded
            // `UPDATE ... WHERE jumlah_stok >= ?`. This todo advances the
            // STATUS and nothing else, and says so rather than half-doing a
            // decrement it cannot guard.
            'dari' => ['menunggu_pembayaran'],
        ],
    ];

    public function __construct(
        private readonly PaymentGatewayService $gateway,
        private readonly NotificationService $notifikasi,
    ) {}

    // =================================================================
    // Initiation
    // =================================================================

    /**
     * Open a payment on `$invoice` with `$metode` and describe how to pay it.
     *
     * ## Ownership is a 404 and the tenant filter IS the query
     *
     * The invoice is read as `Invoice::whereBelongsTo($pasien)->whereKey($id)`,
     * so another patient's invoice is simply not found and cannot be told apart
     * from one that never existed. A 403 would confirm the row exists, which is
     * the cross-tenant existence oracle `PasienRecordAccess` refuses to create.
     * The 403 for an account that owns no `pasien` row is raised ABOVE this
     * method, in the controller, so the two answers stay in the order that does
     * not leak.
     *
     * ## The amount is `invoice.total`, full stop
     *
     * `total` is `DECIMAL(14,2) NOT NULL` with NO DEFAULT (:946) and already
     * contains `biaya_admin` (:944), which `InvoiceService` computed once from
     * `biaya_admin_flat` (:931) and `biaya_admin_persen` (:932). The method
     * chosen HERE is not necessarily the one the invoice was priced with - the
     * schema has no column recording that - so the fee is NOT recomputed and NOT
     * re-applied. Re-applying it is the specific way this endpoint would charge
     * a patient twice, and the test asserts the stored amount is byte-identical
     * to the stored total.
     *
     * ## A second attempt on the same invoice is a 422, not a second row
     *
     * Two `pending` rows on one invoice means two virtual accounts the patient
     * could pay, and `pembayaran` has no uniqueness that would stop it
     * (:958-973). The refusal names the existing reference so the client can
     * show the right one.
     *
     * @throws ValidationException  on a non-payable invoice status, an unknown
     *                              or inactive method, or an existing pending
     *                              payment
     * @throws ModelNotFoundException  when the invoice is not the caller's
     */
    public function mulai(Invoice $invoice, MasterMetodePembayaran $metode): array
    {
        if (! InvoiceStatus::bisaDibayar((string) $invoice->status)) {
            throw ValidationException::withMessages([
                'metode_id' => ['Invoice dengan status ini tidak dapat dibayar.'],
            ]);
        }

        if (! (bool) $metode->status_aktif) {
            throw ValidationException::withMessages([
                'metode_id' => ['Metode pembayaran tidak ditemukan atau tidak aktif.'],
            ]);
        }

        $jumlah = (string) $invoice->total;

        if (! Preg_match('/^[1-9][0-9]{0,11}(\.[0-9]{1,2})?$/', $jumlah)) {
            throw ValidationException::withMessages([
                'metode_id' => ['Total invoice tidak dapat dibayar.'],
            ]);
        }

        $transaksi = $this->gateway->createTransaction($invoice, $metode);

        return DB::transaction(function () use ($invoice, $metode, $transaksi, $jumlah): array {
            $ada = Pembayaran::query()
                ->where('invoice_id', $invoice->getKey())
                ->where('status', PembayaranStatus::Pending->value)
                ->first();

            if ($ada !== null) {
                throw ValidationException::withMessages([
                    'metode_id' => ['Pembayaran untuk invoice ini sudah dibuat dengan nomor referensi '.$ada->nomor_referensi.'.'],
                ]);
            }

            $pembayaran = new Pembayaran;
            $pembayaran->invoice_id = $invoice->getKey();
            $pembayaran->metode_id = $metode->getKey();
            $pembayaran->jumlah = $jumlah;
            $pembayaran->nomor_referensi = (string) $transaksi['nomor_referensi'];
            $pembayaran->gateway = (string) $transaksi['gateway'];
            // `va_number VARCHAR(30) NULL` (:964) is stored when the method's
            // channel is a virtual account, because the column is exactly what
            // it is for and a support agent reading the row needs the number the
            // patient was given. `AuditColumnPolicy::EXPLICIT_DENY` already
            // holds `['pembayaran', 'va_number']`, so the value never reaches
            // `audit_log`. The `qr_string` a `qris` method gets has NO column
            // to live in - the schema has no `qr_string` anywhere - so it is
            // returned to the client and not persisted. That is a schema
            // limitation, recorded rather than worked around with a column the
            // DDL does not have.
            $toko = (array) $transaksi['toko'];
            $pembayaran->va_number = isset($toko['va_number']) ? (string) $toko['va_number'] : null;
            // Written, not defaulted - see `PembayaranStatus::default()`.
            $pembayaran->status = PembayaranStatus::default();
            $pembayaran->dibayar_at = null;
            $pembayaran->webhook_payload = null;

            $pembayaran->save();

            return [$pembayaran->fresh(), $transaksi];
        });
    }

    // =================================================================
    // Settlement
    // =================================================================

    /**
     * Apply a VERIFIED provider notification, exactly once.
     *
     * ## What the caller has already done, and why the order matters
     *
     * `verifyWebhook()` has run. That is the contract: this method takes a
     * `$terverifikasi` array that {@see PaymentGatewayService::verifyWebhook()}
     * produced, and there is no path into this class that reaches the database
     * without one. The controller calls the gateway first and this second, and
     * the test proves the ordering by asserting a FORGED delivery issues zero
     * statements naming `pembayaran`, `invoice` or `booking` - a handler that
     * looked a row up before checking the signature could not pass it.
     *
     * ## Inside the transaction, in this order
     *
     * 1. {@see huntap()} - the `(gateway, nomor_referensi)` probe, FOR UPDATE.
     * 2. terminal check - return the duplicate envelope and write nothing.
     * 3. amount check - the stored `pembayaran.jumlah` against the signed
     *    `jumlah`. This is the "must NOT trust the payload" rule: a stranger
     *    who somehow held the secret must not be able to settle a Rp 150.00
     *    invoice by announcing Rp 150.01.
     * 4. the payment row, the invoice, and the referenced entity.
     *
     * ## A `gagal` or `kedaluwarsa` outcome writes the PAYMENT and nothing else
     *
     * The invoice stays `menunggu_pembayaran` and the entity is not advanced.
     * Symmetry is the point: a failed payment is not a paid invoice, and
     * `invoice.kadaluarsa` (:947-948) is an EXPIRY of the invoice, which a
     * lapsed payment attempt is not - nothing in the schema reacts to
     * `jatuh_tempo` (:949) either, and that is todo 51's clock.
     *
     * @param  array{nomor_referensi: string, status: string, jumlah: string, gateway: string|null, payload: array<string, mixed>}  $terverifikasi
     * @return array{pembayaran: Pembayaran, invoice: Invoice, duplicate: bool, referensi: array<string, mixed>}
     *
     * @throws ModelNotFoundException  when the `(gateway, nomor_referensi)`
     *                                 pair names no payment - a 404, because a
     *                                 settlement for an unknown reference is not
     *                                 an error the provider can act on
     * @throws ValidationException  when the signed `jumlah` disagrees with the
     *                              stored one - a 422, and nothing is written
     */
    public function terimaWebhook(string $gateway, array $terverifikasi): array
    {
        $referensi = (string) $terverifikasi['nomor_referensi'];
        $status = (string) $terverifikasi['status'];
        $jumlah = (string) $terverifikasi['jumlah'];
        $payload = (array) $terverifikasi['payload'];

        return DB::transaction(function () use ($gateway, $referensi, $status, $jumlah, $payload): array {
            $pembayaran = $this->huntap($gateway, $referensi);

            // (2) THE DEDUPE. A terminal status means a decision has already
            // been made about this reference, so there is nothing left to trust
            // and nothing left to write - including `webhook_payload`, which is
            // why the FIRST delivery's body survives a retry.
            if (PembayaranStatus::adalahAkhir((string) $pembayaran->status)) {
                return [
                    'pembayaran' => $pembayaran,
                    'invoice' => $this->invoiceUntuk($pembayaran),
                    'duplicate' => true,
                    'referensi' => $this->terangkanReferensi($pembayaran),
                ];
            }

            // (3) The amount. Compared against the STORED `jumlah`, never
            // against anything the request supplied, and through
            // `Uang::normal()` on both sides rather than with `===` so
            // `'150000.0'` and `'150000.00'` are the same amount - two decimal
            // strings can denote one figure, and a provider that omits a
            // trailing zero is not attempting fraud.
            if (Uang::normal((string) $pembayaran->jumlah) !== Uang::normal($jumlah)) {
                throw ValidationException::withMessages([
                    'jumlah' => ['Jumlah pembayaran tidak sesuai dengan total invoice.'],
                ]);
            }

            $lunas = PembayaranStatus::menambahLunas($status);
            $sekarang = Carbon::now();

            // (4a) The payment row. Three columns move together, which is what
            // makes one `updated` event and therefore one `audit_log` row - the
            // counter the duplicate test measures.
            $pembayaran->status = $status;
            $pembayaran->dibayar_at = $lunas ? $sekarang : null;
            $pembayaran->webhook_payload = $payload;

            $pembayaran->save();

            $invoice = $this->invoiceUntuk($pembayaran);

            if ($lunas) {
                $invoice->status = InvoiceStatus::setelahLunas();
                $invoice->lunas_at = $sekarang;
                $invoice->save();

                // F3-05. Written here, INSIDE the transaction, and ONLY on the
                // `$lunas` branch - so a `gagal` or `kedaluwarsa` outcome tells
                // the patient nothing, and the duplicate branch above has
                // already returned before this line is reachable, so a retried
                // delivery cannot notify twice.
                $penerima = $invoice->pasien?->user;

                if ($penerima !== null) {
                    $this->notifikasi->pembayaranSelesai($penerima, (int) $invoice->getKey());
                }
            }

            return [
                'pembayaran' => $pembayaran,
                'invoice' => $invoice,
                'duplicate' => false,
                'referensi' => $lunas
                    ? $this->majukanEntitas($invoice)
                    : $this->terangkanEntitas(
                        (string) $invoice->referensi_tipe,
                        (int) $invoice->referensi_id,
                        self::LANJUT[(string) $invoice->referensi_tipe] ?? null,
                        $this->bacaEntitas((string) $invoice->referensi_tipe, (int) $invoice->referensi_id),
                    ),
            ];
        });
    }

    /**
     * The `pembayaran` row this notification settles, locked.
     *
     * ## `lockForUpdate()` is the concurrency argument, and it is load-bearing
     *
     * The predicate matches no index - `nomor_referensi` (:963) and `gateway`
     * (:965) are in none, and the only index is `idx_bayar_status` (:972) - so
     * the statement is a scan, and `FOR UPDATE` turns it into a locking read
     * that InnoDB resolves against the LATEST COMMITTED version rather than the
     * transaction's snapshot. That difference is the entire mechanism:
     *
     * - two concurrent identical deliveries both reach this line;
     * - the first takes the row lock and applies;
     * - the second BLOCKS here, not at its own `save()`;
     * - when it unblocks, the first has committed, and this re-read returns the
     *   now-TERMINAL status, so the duplicate branch runs and writes nothing.
     *
     * A plain `first()` would instead resolve against a snapshot taken before
     * the first committed, read `pending` again, and write a second time. That
     * is the race the schema cannot close, and the reason this method is the
     * only place in the class that looks a payment up.
     *
     * ## A miss is a 404, and it is a 404 rather than a 422
     *
     * The pair names no payment. The provider will not act on either status, and
     * a 422 would suggest the request was understood and rejected on its
     * content, which invites a retry of a request that can never succeed. A 404
     * also discloses nothing: the reference is ours to guess and a stranger
     * cannot produce a valid signature for one they did not receive.
     */
    private function huntap(string $gateway, string $referensi): Pembayaran
    {
        $pembayaran = Pembayaran::query()
            ->where('gateway', $gateway)
            ->where('nomor_referensi', $referensi)
            ->lockForUpdate()
            ->first();

        if ($pembayaran === null) {
            throw (new ModelNotFoundException)->setModel(Pembayaran::class, [$referensi]);
        }

        return $pembayaran;
    }

    /**
     * The invoice a payment settles, loaded.
     *
     * `pembayaran.invoice_id` is `BIGINT UNSIGNED NOT NULL` with a real foreign
     * key to `invoice(id)` (:960, :970), so the row always exists and this is
     * `findOrFail` rather than a silent null.
     */
    private function invoiceUntuk(Pembayaran $pembayaran): Invoice
    {
        return Invoice::query()->findOrFail((int) $pembayaran->invoice_id);
    }

    /**
     * Move the referenced entity to its paid-adjacent state, if it may be moved,
     * then describe it.
     *
     * ## Guarded on the SOURCE state, and that is not decoration
     *
     * A delivery that arrives after the patient cancelled their booking still
     * records real money, so the invoice DOES go `lunas` - but moving a
     * `dibatalkan` booking to `terjadwal` would resurrect a cancelled
     * appointment. So the advance applies only from a state in `dari`.
     *
     * ## Why this is here and not in a database trigger
     *
     * There is none to put it in: `referensi_id` is a BARE column (:941), the
     * three targets have no trigger and no generated column, and MySQL cannot
     * write a `booking` row from an `invoice` update without a trigger the DDL
     * does not declare. So the schema has nothing, and the plan's "also advance
     * the referenced entity's state - since the schema has no trigger for it" is
     * this method.
     *
     * @return array{tipe: string, id: int, status: string|null, advanced: bool}
     */
    private function majukanEntitas(Invoice $invoice): array
    {
        $tipe = (string) $invoice->referensi_tipe;
        $id = (int) $invoice->referensi_id;

        $aturan = self::LANJUT[$tipe] ?? null;
        $baris = $this->bacaEntitas($tipe, $id);

        if ($aturan !== null && $baris !== null && in_array((string) $baris->status, $aturan['dari'], true)) {
            $baris->status = $aturan['ke'];
            $baris->save();
        }

        return $this->terangkanEntitas($tipe, $id, $aturan, $baris);
    }

    /**
     * Describe the referenced entity WITHOUT touching it, for the duplicate
     * response.
     *
     * ## Why the duplicate response re-reads instead of replaying
     *
     * A duplicate must produce the SAME `data` as the first delivery, so it
     * cannot echo a stored summary: the first response said what THAT call did,
     * and this call did nothing. It reads the CURRENT state instead, and
     * {@see terangkanEntitas()} derives `advanced` from the row rather than
     * from the call - so a settlement that advanced a booking reports
     * `advanced: true` on BOTH deliveries, and one that advanced nothing
     * reports `false` on both. `duplicate` is then the ONLY key that differs
     * between the two bodies, which is what makes "same shape, still a success"
     * a checkable claim rather than a description.
     *
     * @return array{tipe: string, id: int, status: string|null, advanced: bool}
     */
    private function terangkanReferensi(Pembayaran $pembayaran): array
    {
        $invoice = $this->invoiceUntuk($pembayaran);

        $tipe = (string) $invoice->referensi_tipe;
        $id = (int) $invoice->referensi_id;

        return $this->terangkanEntitas($tipe, $id, self::LANJUT[$tipe] ?? null, $this->bacaEntitas($tipe, $id));
    }

    /**
     * The referenced row, or null.
     *
     * `invoice.referensi_id` is a BARE column (:941) with no foreign key, so a
     * `booking` can be deleted while an invoice still names it. That is why this
     * returns null rather than throwing, and why a missing row reports
     * `advanced: false` on a settlement that otherwise succeeded - the money
     * arrived, and a webhook that 500s would make the provider retry a payment
     * that has already been taken.
     *
     * @param  class-string<Model>|null  $model
     */
    private function bacaEntitas(string $tipe, int $id): ?Model
    {
        $aturan = self::LANJUT[$tipe] ?? null;

        if ($aturan === null) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = $aturan['model'];

        return $model::query()->whereKey($id)->first();
    }

    /**
     * The four-key description both response paths publish.
     *
     * `advanced` is a fact about the ROW, never about the call: it is true when
     * the entity currently holds the state a settlement moves it to. That single
     * choice is what keeps the two deliveries' bodies identical apart from
     * `duplicate`, and it is also the honest answer - "this delivery advanced
     * the booking" is false on a retry that advanced nothing, but "the booking
     * is paid for" is true on both.
     *
     * @param  array{model: class-string<Model>, ke: string, dari: list<string>}|null  $aturan
     * @return array{tipe: string, id: int, status: string|null, advanced: bool}
     */
    private function terangkanEntitas(string $tipe, int $id, ?array $aturan, ?Model $baris): array
    {
        $status = $baris === null ? null : (string) $baris->status;

        return [
            'tipe' => $tipe,
            'id' => $id,
            'status' => $status,
            'advanced' => $aturan !== null && $status !== null && $status === $aturan['ke'],
        ];
    }
}
