<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The seven `invoice.status` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:947-948`, a WRAPPED ENUM:
 *
 * ```
 * status ENUM('draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan',
 *             'refund_sebagian','refund_penuh') NOT NULL DEFAULT 'menunggu_pembayaran',
 * ```
 *
 * The declaration WRAPS onto a second line, so reading `:947` alone yields five
 * members and makes the column look like a different one. `PaymentWebhookTest`
 * re-parses the DDL with the project's own `App\Support\Schema\SqlSchemaParser`
 * and asserts {@see nilai()} is identical to it with `toBe`, which checks ORDER
 * as well as membership.
 *
 * ## Why an invoice status enum exists when todo 44 did not need one
 *
 * `InvoiceService` only ever WRITES one status - `menunggu_pembayaran` - and
 * names it as a private constant, because a writer of one value does not need a
 * vocabulary. This endpoint is the first thing in the application to MOVE an
 * invoice, and moving it means asking "which states may a payment settle?",
 * which is a question about the whole set. Two of the seven are reachable from
 * `POST /api/v1/invoice/{id}/bayar` and its webhook: {@see Lunas} on success,
 * and {@see Kadaluarsa} never - a payment attempt lapsing is not an invoice
 * lapsing, which is stated at the call site because it is the mistake a
 * symmetric-looking implementation makes.
 *
 * A real PHP enum (never an `enum:` cast, which is a silent no-op on
 * laravel/framework 13.33) and the model keeps its plain `'string'` cast per
 * the project-wide `ModelFoundationTest` invariant.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case MenungguPembayaran = 'menunggu_pembayaran';
    case Lunas = 'lunas';
    case Kadaluarsa = 'kadaluarsa';
    case Dibatalkan = 'dibatalkan';
    case RefundSebagian = 'refund_sebagian';
    case RefundPenuh = 'refund_penuh';

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
     * The one status a payment may be initiated against.
     *
     * `NOT NULL DEFAULT 'menunggu_pembayaran'` at `:948`. A `lunas` invoice is
     * not payable again and a `dibatalkan` one never will be, and the endpoint
     * names the state it actually found rather than saying "invalid".
     */
    public static function bisaDibayar(string $kandidat): bool
    {
        return $kandidat === self::MenungguPembayaran->value;
    }

    /**
     * The one status a successful settlement moves an invoice to.
     */
    public static function setelahLunas(): string
    {
        return self::Lunas->value;
    }

    /**
     * Is this an invoice the money has already settled?
     *
     * `lunas`, `refund_sebagian` and `refund_penuh` all mean money has moved
     * through the invoice, so a settlement arriving for one of them is a
     * duplicate or a late callback rather than news. `kadaluarsa` and
     * `dibatalkan` do NOT: an invoice that lapsed can be reissued, and this
     * schema has no column recording that it was.
     */
    public static function sudahTerselesaikan(string $kandidat): bool
    {
        return in_array($kandidat, [self::Lunas->value, self::RefundSebagian->value, self::RefundPenuh->value], true);
    }
}
