<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\InvoiceStatus;
use App\Enums\PembayaranStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Pasien;
use App\Models\Pembayaran;
use App\Models\Refund;
use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The refund ledger: the one writer of `refund` rows, the cancellation policy,
 * and the patient's own refund list.
 *
 * ## The invariant this class exists to keep
 *
 * `database/migrations/2026_10_01_000064_refund_table.php:21-25` states it in
 * the migration itself: `pembayaran.status` and the `refund` table are two
 * halves of one fact, they have no foreign key between them, and a service
 * that writes one without the other *"produces a state the schema permits and
 * the ledger cannot explain"*. Every path in {@see catatPembatalan()} writes
 * the pair together, inside the caller's transaction:
 *
 * ```
 * paid booking cancelled -> 1 refund row + pembayaran.status = 'refund'
 *                                                + invoice.status = 'refund_penuh'
 * unpaid booking         -> 0 refund rows + invoice.status = 'dibatalkan'
 * ```
 *
 * The previous behaviour in `BookingService::batalkan()` set the invoice to
 * `dibatalkan` unconditionally, leaving a `berhasil` payment with no refund row
 * and no money record at all - the P0 F12 identifies. That path is gone.
 *
 * ## Full refund, always - the owner's decision, not an inference
 *
 * Cancellation is free at any time and the refund is the full captured amount:
 * `refund.jumlah = pembayaran.jumlah`, never a fraction. The schema supports
 * partial refunds (`refund.jumlah` has no CHECK against the payment, and its
 * missing uniqueness is a deliberate gap) and the UI has a partial-refund
 * state (`invoice.refund_sebagian`), but no product decision names a fee, so
 * none is invented here. That is recorded in `web/ux/patterns/F12.md` section
 * 12 as DIPUTUSKAN.
 *
 * ## The hybrid execution rule, keyed on `master_metode_pembayaran.tipe`
 *
 * | `tipe` | who moves the money | row's first status |
 * | --- | --- | --- |
 * | `e_wallet`, `qris`, `kartu_kredit` | this class, through {@see PaymentGatewayService::refund()} | `berhasil` on success, `diproses` on any failure |
 * | the other six (`va_bank`, `gerai_retail`, `cod`, `tunai`, `bpjs`, `asuransi`) | an admin, later (F14) | `diajukan` |
 *
 * The list is `config('payment.metode_tipe_refund_otomatis')`, never a literal
 * here, because the schema maps no method to any refund capability - the same
 * reason `metode_tipe_qr` lives in config. A `va_bank` is deliberately manual:
 * closing a virtual account does not transfer money to a destination we hold.
 *
 * ## A transport failure is NOT a rejection
 *
 * `ditolak` is a policy outcome - a human or a provider decided this refund
 * will not be paid. A gateway timeout or a refused wallet is neither: it is an
 * unresolved refund, so the row is written `diproses` and the failure is
 * LOGGED, which is exactly the state an admin picks up. Writing `ditolak`
 * there would make a transient network fault indistinguishable from a refused
 * claim and would stop a legitimate refund dead. This choice is asserted by a
 * test that substitutes a throwing gateway and reads the row.
 *
 * ## The gateway call runs inside the caller's transaction, and that trade is
 * ## stated
 *
 * {@see catatPembatalan()} is called from inside `BookingService::batalkan()`'s
 * transaction, so the provider call happens while the booking, invoice and
 * payment row locks are held. With the shipped mock that is an in-process
 * function call and costs nothing. **A real gateway adapter should not be
 * called there**: a provider that times out would hold the locks for the whole
 * timeout, and a commit failure after a successful provider call would reverse
 * the ledger while the money stays moved. The two-phase shape that fixes it
 * (write `diproses`, commit, call the provider, update the row) is a decision
 * for the deployment that ships a real adapter; it is recorded here rather
 * than silently deferred, and the row statuses produced by this class are
 * identical either way.
 *
 * ## Nothing here re-derives an amount
 *
 * `refund.jumlah` is read off `pembayaran.jumlah` and `jumlah_refund` is the
 * same stored value, never a recomputation from `invoice.total`. An invoice's
 * total can legitimately differ from what was captured after a later
 * correction, and a recomputed refund would return money the patient never
 * paid - or less than they did.
 */
final class RefundService
{
    /**
     * The `refund.status` a row is born in when a human must process it.
     *
     * The DDL default (`:980`) is the same value; it is written explicitly
     * anyway, for the reason `InvoiceService` writes `status` explicitly: a
     * column default should not be what decides a row's state.
     */
    public const STATUS_DIAJUKAN = 'diajukan';

    /**
     * The `refund.status` that means the money is on its way.
     *
     * Two producers: a manual refund an admin started, and an automatic refund
     * whose gateway call failed or was refused. The second is the interesting
     * one - see the class docblock on why it is not `ditolak`.
     */
    public const STATUS_DIPROSES = 'diproses';

    /** The `refund.status` that means the provider confirmed the return. */
    public const STATUS_BERHASIL = 'berhasil';

    /**
     * The terminal status a refund may be re-requested after.
     *
     * The idempotency guard below skips a payment that already has any OTHER
     * status, so `ditolak` is the one state a fresh attempt may follow - which
     * is the only reading under which "ditolak" is not permanent by accident.
     */
    public const STATUS_DITOLAK = 'ditolak';

    public function __construct(
        private readonly PaymentGatewayService $gateway,
        private readonly PasienRecordAccess $access,
    ) {}

    // =================================================================
    // The ledger write, called from inside BookingService::batalkan()
    // =================================================================

    /**
     * Settle the money side of one cancelled booking.
     *
     * MUST be called inside the cancellation transaction: the invoice and
     * payment rows are locked with `lockForUpdate()`, and a caller that ran
     * this outside a transaction would hold each lock only for its own
     * statement, which is exactly the race the locks exist to close (a
     * concurrent webhook settlement and this refund could otherwise interleave).
     *
     * Three outcomes, in this order:
     *
     * 1. **No invoice** - nothing to account for. A booking's invoice is
     *    written by `BookingService::create()` in the same transaction, so this
     *    is a corrupt row rather than an error a caller can act on, and
     *    returning silently leaves the cancellation itself valid.
     * 2. **No successful payment** - the booking was never paid: the invoice
     *    goes `dibatalkan` and NO refund row is written. This is the old
     *    behaviour, now correct because it applies only here.
     * 3. **A successful payment** - exactly one refund row for that payment
     *    (unless a non-`ditolak` one already exists), the payment flips to
     *    `refund` and the invoice to `refund_penuh`. All three carry the full
     *    captured amount.
     *
     * ## Why the LATEST successful payment is chosen
     *
     * `pembayaran` has no uniqueness on `(invoice_id, status)` and a failed or
     * lapsed attempt can be followed by a new one, so the schema permits more
     * than one `berhasil` row in principle. The ledger invariant is one refund
     * row per REFUNDED PAYMENT, so this class refunds the newest successful
     * row and flips only that row's status; refunding several would need
     * several rows and a partial-refund policy nobody has decided. Recorded as
     * a schema-permitted edge rather than hidden.
     */
    public function catatPembatalan(Booking $booking): void
    {
        $invoice = Invoice::query()
            ->where('referensi_tipe', 'booking')
            ->where('referensi_id', $booking->getKey())
            ->lockForUpdate()
            ->first();

        if ($invoice === null) {
            return;
        }

        $pembayaran = Pembayaran::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', PembayaranStatus::Berhasil->value)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($pembayaran === null) {
            $invoice->status = InvoiceStatus::Dibatalkan->value;
            $invoice->save();

            return;
        }

        // The idempotency guard, INDEPENDENT of the booking status guard that
        // already refuses a second cancellation. A payment can be named by a
        // refund row from an earlier attempt that was rolled back outside this
        // service, or re-cancelled after a manual repair; the guard makes the
        // pair (row, statuses) agree no matter how it got here.
        $sudahAda = Refund::query()
            ->where('pembayaran_id', $pembayaran->getKey())
            ->where('status', '!=', self::STATUS_DITOLAK)
            ->lockForUpdate()
            ->first();

        if ($sudahAda === null) {
            $this->tulisRefund($booking, $pembayaran);
        }

        $pembayaran->status = PembayaranStatus::Refund->value;
        $pembayaran->save();

        $invoice->status = InvoiceStatus::RefundPenuh->value;
        $invoice->save();
    }

    /**
     * Write the one refund row, deciding its first status by the method's
     * technical capability.
     *
     * The amount is `pembayaran.jumlah` (a DECIMAL string) with no arithmetic
     * of any kind - see the class docblock. The reason is a short generic
     * string naming the booking number and nothing else: `refund.alasan` is
     * `VARCHAR(255)` and is visible to an admin processing the row, and
     * `booking.alasan_pembatalan` is free text a patient may have filled with
     * clinical detail (the UU PDP concern F11/F12 record). Copying it here
     * would move that detail from the clinical record into the payment ledger.
     */
    private function tulisRefund(Booking $booking, Pembayaran $pembayaran): Refund
    {
        $metode = $pembayaran->metode;

        $otomatis = $metode !== null && in_array(
            (string) $metode->tipe,
            array_map('strval', (array) config('payment.metode_tipe_refund_otomatis')),
            true,
        );

        $status = $otomatis
            ? $this->statusRefundOtomatis($pembayaran)
            : self::STATUS_DIAJUKAN;

        $refund = new Refund;
        $refund->pembayaran_id = $pembayaran->getKey();
        $refund->jumlah = (string) $pembayaran->jumlah;
        $refund->alasan = 'Pembatalan booking '.$booking->nomor_booking.'.';
        $refund->status = $status;
        $refund->save();

        return $refund;
    }

    /**
     * The gateway path: `berhasil` on success, `diproses` on ANY failure.
     *
     * The catch is deliberately `Throwable` rather than an exception class of
     * ours: a real adapter can fail with a transport exception, a DNS failure,
     * a JSON decode error or a `TypeError` from its own client library, and
     * every one of those is "we do not know whether the money moved" - the
     * state `diproses` exists for. Letting any of them bubble would roll back
     * the cancellation transaction (the booking would stay `terjadwal`) even
     * though the cancellation itself is valid; catching and logging keeps the
     * ledger and the booking consistent and hands the refund to a human.
     *
     * The log line carries the payment id and the provider's refund reference
     * or message, never a patient identifier - the delivery log is an operator
     * surface, and this codebase's notification service already holds the line
     * that a durable log must not become a second clinical record.
     */
    private function statusRefundOtomatis(Pembayaran $pembayaran): string
    {
        try {
            $hasil = $this->gateway->refund($pembayaran);

            if (($hasil['berhasil'] ?? false) === true) {
                Log::info('refund.gateway.berhasil', [
                    'pembayaran_id' => (int) $pembayaran->getKey(),
                    'referensi' => $hasil['referensi'] ?? null,
                ]);

                return self::STATUS_BERHASIL;
            }

            Log::warning('refund.gateway.ditolak-provider', [
                'pembayaran_id' => (int) $pembayaran->getKey(),
                'referensi' => $hasil['referensi'] ?? null,
                'pesan' => $hasil['pesan'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::warning('refund.gateway.gagal', [
                'pembayaran_id' => (int) $pembayaran->getKey(),
                'error' => $e::class,
                'pesan' => $e->getMessage(),
            ]);
        }

        return self::STATUS_DIPROSES;
    }

    // =================================================================
    // The cancellation policy surface
    // =================================================================

    /**
     * The uniform cancellation policy for one booking, server-computed.
     *
     * The client MUST NOT compute any of this - that is the decision recorded
     * in `web/ux/patterns/F12.md` section 12. The response is:
     *
     * ```
     * {
     *   gratis: true,
     *   biaya: "0.00",
     *   jumlah_refund: <paid amount, or "0.00">,
     *   tujuan: {metode_id, label, tipe} | null,
     *   sla: null
     * }
     * ```
     *
     * `gratis`/`biaya` are constants today because the owner decided
     * cancellation is free at any time with a full refund. `jumlah_refund` is
     * the captured amount when there is a successful payment and `"0.00"`
     * otherwise, and `tujuan` is the method the money will return to - `null`
     * when there is nothing to return, which is exactly the case where the UI
     * shows "Tidak ada dana yang perlu dikembalikan." and never renders a
     * destination.
     *
     * `sla` is deliberately `null`: no promised turnaround exists. The UI shows
     * `refund.status` (which is honest - `diajukan`/`diproses`/`berhasil`) and
     * must not invent a date. Reschedule limits are likewise absent here
     * because none is decided; see the pattern's section 12.
     *
     * @return array{gratis: bool, biaya: string, jumlah_refund: string, tujuan: array{metode_id: int, label: string, tipe: string}|null, sla: null}
     */
    public function kebijakan(Booking $booking): array
    {
        $pembayaran = $this->pembayaranBerhasil($booking);

        return [
            'gratis' => true,
            'biaya' => '0.00',
            'jumlah_refund' => $pembayaran === null ? '0.00' : (string) $pembayaran->jumlah,
            'tujuan' => $this->tujuan($pembayaran),
            'sla' => null,
        ];
    }

    /**
     * The method a refund would travel back through, or `null`.
     *
     * `metode_id` is `SMALLINT UNSIGNED` (telemedicine_test.sql:961) and the
     * relation always resolves because the column has a real foreign key
     * (:971); the null branch is for a payment row whose method row was
     * removed outside the FK's reach (`master_metode_pembayaran` cannot be
     * deleted while a payment names it, but a hand-repaired row can).
     *
     * @return array{metode_id: int, label: string, tipe: string}|null
     */
    private function tujuan(?Pembayaran $pembayaran): ?array
    {
        $metode = $pembayaran?->metode;

        if ($metode === null) {
            return null;
        }

        return [
            'metode_id' => (int) $metode->getKey(),
            'label' => (string) $metode->nama,
            'tipe' => (string) $metode->tipe,
        ];
    }

    /**
     * The newest successful payment for a booking, or null.
     *
     * The read half of the same selection {@see catatPembatalan()} locks: the
     * policy must report the money that would actually be refunded, not a
     * pending or failed attempt, and the newest row is the one that captured.
     */
    private function pembayaranBerhasil(Booking $booking): ?Pembayaran
    {
        $invoice = Invoice::query()
            ->where('referensi_tipe', 'booking')
            ->where('referensi_id', $booking->getKey())
            ->first();

        if ($invoice === null) {
            return null;
        }

        return Pembayaran::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', PembayaranStatus::Berhasil->value)
            ->orderByDesc('id')
            ->first();
    }

    // =================================================================
    // The patient's own refund list
    // =================================================================

    /**
     * One page of the patient's own refunds, newest first, with the project
     * meta block's page size.
     *
     * ## `booking_id` is resolved through `invoice`, not stored on `refund`
     *
     * `refund` names only `pembayaran_id` (:977). The booking is three joins
     * away (`refund` -> `pembayaran` -> `invoice` -> `booking`), and this
     * method walks them through `invoice.referensi_tipe = 'booking'` so a
     * refund against a prescription or a medicine order can never appear on
     * the booking-refund list (Modules 4-5 do not write refunds today, but the
     * filter is what keeps that true when they do).
     *
     * ## The tenant scope IS the query
     *
     * `invoice.pasien_id` is compared against the caller's own patient row, so
     * another patient's refund is simply absent rather than refused - the
     * `PasienRecordAccess` rule every patient surface follows. The
     * page size is clamped through the same helper, so a `per_page` arriving
     * from anywhere cannot exceed the project ceiling.
     *
     * ## Newest first is a TOTAL order
     *
     * `dibuat_at` is a `TIMESTAMP` whose resolution is one second, so two rows
     * created in the same second tie; `refund.id` breaks the tie. Without the
     * second key, paging could repeat or skip a row.
     *
     * @param  array<string, mixed>  $filter
     */
    public function daftarPasien(Pasien $pasien, array $filter): LengthAwarePaginator
    {
        return Refund::query()
            ->select('refund.*')
            ->join('pembayaran', 'pembayaran.id', '=', 'refund.pembayaran_id')
            ->join('invoice', 'invoice.id', '=', 'pembayaran.invoice_id')
            ->where('invoice.pasien_id', $pasien->getKey())
            ->where('invoice.referensi_tipe', 'booking')
            ->with(['pembayaran.invoice', 'pembayaran.metode'])
            ->orderByDesc('refund.dibuat_at')
            ->orderByDesc('refund.id')
            ->paginate($this->access->perPage((int) ($filter['per_page'] ?? PasienRecordAccess::PER_PAGE_DEFAULT)))
            ->withQueryString();
    }
}
