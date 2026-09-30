<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\PesananObat\PesananObatService;

/**
 * The six `pesanan_obat.status` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:810-811`:
 *
 * ```
 * status ENUM('menunggu_pembayaran','diproses','siap','sedang_dikirim','selesai','dibatalkan')
 *        NOT NULL DEFAULT 'menunggu_pembayaran',
 * ```
 *
 * ## The declaration WRAPS, and reading `:810` alone is a trap
 *
 * The six values fit on `:810` and the `NOT NULL DEFAULT` tail is on `:811`.
 * A reader who takes `:810` alone sees six members with an **empty** tail,
 * which reads as a nullable column with no default: `column_nullable` plus
 * `column_default` drift, and a `NOT NULL` column silently made nullable. Read
 * both lines. This is the SEVENTH multi-line ENUM in the file; the plan's own
 * list of six omits it and so does `docs/migration-order.md` rule 6.
 *
 * `CheckoutTest` re-parses the DDL with the project's own
 * `App\Support\Schema\SqlSchemaParser` and asserts {@see nilai()} is identical
 * with `toBe`, which checks ORDER as well as membership, so a seventh value
 * added to the DDL without a decision here fails the suite.
 *
 * ## A real PHP enum, never an `enum:` cast
 *
 * laravel/framework 13.33 makes the `enum:` cast a **silent no-op**: it stores
 * and reads the raw string and validates nothing, so a typo passes through
 * unnoticed. Every other enum in this project is a real PHP enum for the same
 * reason, and the models keep their plain `'string'` cast per the project-wide
 * `ModelFoundationTest` invariant.
 */
enum PesananObatStatus: string
{
    case MenungguPembayaran = 'menunggu_pembayaran';
    case Diproses = 'diproses';
    case Siap = 'siap';
    case SedangDikirim = 'sedang_dikirim';
    case Selesai = 'selesai';
    case Dibatalkan = 'dibatalkan';

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
     * The DDL's own default: a new order is born unpaid.
     *
     * Written explicitly by {@see PesananObatService}
     * rather than relied upon, for the reason `InvoiceService`'s docblock gives
     * about `invoice.status`: relying on a column default would make a later
     * migration's change to that default silently change what an unpaid order
     * is.
     */
    public static function default(): string
    {
        return self::MenungguPembayaran->value;
    }
}
