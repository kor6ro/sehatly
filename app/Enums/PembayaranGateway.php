<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four `pembayaran.gateway` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:965`:
 *
 * ```
 * gateway ENUM('midtrans','xendit','doku','flip') NULL,
 * ```
 *
 * ## The column is NULLABLE, and that is a fact the endpoint leans on
 *
 * `:965` declares `NULL`, not `NOT NULL`, so a `pembayaran` row is legal with no
 * gateway at all - a cash payment at a counter has no provider to call back.
 * `pembayaran.gateway` is therefore a value THIS application writes rather than
 * one it can assume: {@see PaymentService} writes
 * {@see config('payment.gateway_pembayaran')} on every row it mints, and the
 * webhook's `{gateway}` path segment is checked against this enum before
 * anything else happens.
 *
 * ## This is the reason the path segment is validated
 *
 * `{gateway}` is CLIENT-SUPPLIED. Without this enum the endpoint would look a
 * payment up by whatever string arrived in the URL, and a signature minted for
 * `midtrans` would be checked against a secret chosen by a caller. The route
 * constrains the segment to these four values, so a request outside them is a
 * router 404 that never reaches the code that reads a secret - and the test
 * asserts the 404 comes from the ROUTER, not from the controller, by naming a
 * segment the controller would have accepted.
 *
 * A real PHP enum (never an `enum:` cast, which is a silent no-op on
 * laravel/framework 13.33) and the model keeps its plain `'string'` cast per
 * the project-wide `ModelFoundationTest` invariant.
 *
 * ## The schema maps no payment method to a gateway
 *
 * `master_metode_pembayaran` has a `penyedia VARCHAR(50) NULL` free-text column
 * (:930) and no foreign key to anything resembling a gateway table, so there is
 * NO declared relationship between a method and a provider. The gateway is a
 * deployment choice - `config('payment.gateway_pembayaran')` - and it is stated
 * here rather than inferred from a method's `tipe`.
 */
enum PembayaranGateway: string
{
    case Midtrans = 'midtrans';
    case Xendit = 'xendit';
    case Doku = 'doku';
    case Flip = 'flip';

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
     * Is this one of the four `pembayaran.gateway` members at all?
     *
     * A total, throw-free predicate so a caller can turn an unknown segment
     * into a 404 without an exception escaping into a 500.
     */
    public static function adalah(string $kandidat): bool
    {
        return self::tryFrom($kandidat) !== null;
    }

    /**
     * The same four values as a router `whereIn` list.
     *
     * Returned rather than written at the route so the constraint and the enum
     * cannot disagree: a fifth ENUM member added to the DDL but not to the
     * route would be an endpoint that 404s a legal value, and a test asserts
     * the route's compiled pattern contains all four.
     *
     * @return list<string>
     */
    public static function untukRute(): array
    {
        return self::nilai();
    }
}
