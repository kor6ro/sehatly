<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Resep\ResepStateMachine;

/**
 * The five `pembayaran.status` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:966`:
 *
 * ```
 * status ENUM('pending','berhasil','gagal','kedaluwarsa','refund') NOT NULL DEFAULT 'pending',
 * ```
 *
 * The declaration does NOT wrap - all five members are on one line - so this is
 * the one payment ENUM that can be read from `:966` alone without ambiguity.
 *
 * ## `SETTLE` is three of the five, and the two it excludes are the interesting part
 *
 * This endpoint decides three outcomes, not five:
 *
 * - `berhasil` - the money arrived. `invoice.status` becomes `lunas`,
 *   `lunas_at` is stamped, and the referenced entity advances.
 * - `gagal` - the attempt failed. The payment row is terminal, and the invoice
 *   and the entity are left exactly as they were.
 * - `kedaluwarsa` - the attempt lapsed. Identical handling to `gagal`, because
 *   the two differ to the PATIENT (a retryable lapse versus a declined card) and
 *   not to anything this table can act on.
 *
 * `refund` is excluded and that exclusion is a decision, not an oversight. A
 * refund is a separate lifecycle: `refund.status` is a four-value ENUM at
 * `:980` with its own table, and settling a provider's "refunded" callback here
 * would mark an invoice `lunas` with **no `refund` row at all** - money
 * returned, books closed, no record of why. So an incoming `refund` is a 422.
 *
 * `pending` is excluded because it is the row's own current state: a provider
 * telling us nothing has happened yet is not a settlement, and writing
 * `pending` over `pending` would produce an `updated` event and an audit row
 * for no change at all.
 *
 * A real PHP enum (never an `enum:` cast, which is a silent no-op on
 * laravel/framework 13.33) and the model keeps its plain `'string'` cast per
 * the project-wide `ModelFoundationTest` invariant.
 */
enum PembayaranStatus: string
{
    case Pending = 'pending';
    case Berhasil = 'berhasil';
    case Gagal = 'gagal';
    case Kedaluwarsa = 'kedaluwarsa';
    case Refund = 'refund';

    /**
     * The three outcomes a provider webhook may report, in the DDL's order.
     *
     * The gate in {@see terimaWebhook()} is `in_array($status, SETTLE, true)`,
     * so this list IS the accepted set and anything outside it is a 422 naming
     * the field.
     *
     * @var list<string>
     */
    public const SETTLE = ['berhasil', 'gagal', 'kedaluwarsa'];

    /**
     * Every status nothing leads out of, keyed by nothing.
     *
     * The same rule as {@see ResepStateMachine::TERMINAL}:
     * a state with no legal successor. It is PUBLIC because the idempotency
     * guard reads it, and a private list would be a second definition of
     * "settled" that could drift from this one.
     *
     * **`pending` is the only non-terminal member**, which is what makes the
     * dedupe total: a `pembayaran` row is either still awaiting a decision or
     * already has one, and "has one" is exactly "is terminal".
     *
     * @var list<string>
     */
    public const KEADAAN_AKHIR = ['berhasil', 'gagal', 'kedaluwarsa', 'refund'];

    /**
     * Every value, in the DDL's declaration order.
     *
     * @return list<string>
     */
    public static function nilai(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Is this status one no further delivery changes?
     *
     * A lookup in a map of real members, so a status string that is not one of
     * the five answers `false` - which is the correct answer for a malformed
     * webhook, and the reason the guard is written as a lookup rather than as
     * `!== 'pending'`: the latter would treat a typo as "not yet settled" and
     * let it overwrite a real outcome.
     */
    public static function adalahAkhir(string $kandidat): bool
    {
        return in_array($kandidat, self::KEADAAN_AKHIR, true);
    }

    /**
     * Is `$kandidat` one of the three outcomes a webhook may report?
     */
    public static function bisaDisettle(string $kandidat): bool
    {
        return in_array($kandidat, self::SETTLE, true);
    }

    /**
     * The one status a new `pembayaran` row is born in.
     *
     * `NOT NULL DEFAULT 'pending'` at `:966` - written explicitly anyway, for
     * the reason `InvoiceService` writes `status` explicitly rather than
     * relying on the DDL default: a column's default should not be what decides
     * a payment's state.
     */
    public static function default(): string
    {
        return self::Pending->value;
    }

    /**
     * Does this outcome mean the money arrived?
     *
     * The single branch point in the settlement: it decides whether the invoice
     * goes `lunas` and whether the referenced entity advances. `gagal` and
     * `kedaluwarsa` are symmetric here, which is the whole reason they are
     * handled by one code path.
     */
    public static function menambahLunas(string $kandidat): bool
    {
        return $kandidat === self::Berhasil->value;
    }
}
