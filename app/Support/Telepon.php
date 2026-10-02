<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one canonical spelling of an Indonesian phone number, and the only place
 * that decides it.
 *
 * ## The defect this exists to close
 *
 * `users.no_telepon` is matched **exactly** (`AuthRequest::identifierRules()`
 * and `AuthController::resolveUser()`), and `RegisterRequest` accepts an
 * optional `+` (`/^\+?[0-9]{8,20}$/`). Before this class, `081234567890` and
 * `+6281234567890` were therefore two different accounts for one phone, and a
 * patient who registered with one spelling could not log in with the other.
 * That is a correctness defect (F01 P0), not a formatting preference.
 *
 * ## The canonical form is the LOCAL `08…`, and that is a data decision
 *
 * Both spellings are the same number; the question is which one the database
 * stores. The local form is chosen because it is what the existing data and the
 * whole test suite already use (`authTestPhone()` is `081234567890`), so
 * normalising to it changes no stored row that was already canonical and needs
 * no test fixture rewritten. The international form is a *presentation* of the
 * same number, applied by the client or by the message template, never by the
 * storage layer.
 *
 * ## What is normalised, and what is deliberately not
 *
 * Only the country-code spelling is folded: `+62…` and `62…` (the latter only
 * when the remainder does not itself start with `0`, so `6208…` - which is not
 * a valid Indonesian number - is left alone rather than turned into `008…`).
 * Whitespace, dashes and parentheses are NOT stripped, because the validation
 * regex this runs beside (`/^\+?[0-9]{8,20}$/`) does not accept them and
 * quietly widening the accepted set would be a contract change this fix does
 * not need.
 *
 * The method is idempotent: normalising an already-canonical number returns it
 * unchanged, so it is safe to call on the request, on a rate-limiter key and on
 * a row read back from the database.
 *
 * ## The unique index is NOT changed, and that is a recorded follow-up
 *
 * `users.no_telepon` keeps its `UNIQUE` index on the raw column. Normalisation
 * happens before write and before lookup, so the index still enforces one
 * account per canonical number for every request that comes through this API -
 * but a direct SQL insert of `+62812…` beside `0812…` would still be accepted.
 * A functional/normalised unique index would close that hole; it is a schema
 * change, and the owner's scope for F01 forbids one. Recorded here so the next
 * reader does not mistake the application-level rule for a database one.
 *
 * Legacy rows that predate this class can be folded with
 * `php artisan sehatly:normalisasi-telepon`, which is idempotent and refuses to
 * create a duplicate rather than crashing on the unique index.
 */
final class Telepon
{
    /** The trunk prefix every canonical local number starts with. */
    public const PREFIKS_LOKAL = '0';

    /** The Indonesian country calling code, with and without the `+`. */
    public const KODE_NEGARA = '62';

    /** The same code in the spelling a client sends. */
    public const KODE_NEGARA_BERPLUS = '+62';

    /**
     * Fold `+62…` / `62…` into the canonical local `08…`, and return anything
     * else unchanged.
     *
     * Null and the empty string pass through so a nullable request field does
     * not become `'0'`.
     */
    public static function normalisasi(?string $nomor): ?string
    {
        if ($nomor === null) {
            return null;
        }

        $nomor = trim($nomor);

        if ($nomor === '') {
            return $nomor;
        }

        if (str_starts_with($nomor, self::KODE_NEGARA_BERPLUS)) {
            $sisa = substr($nomor, strlen(self::KODE_NEGARA_BERPLUS));

            // `+6208…` is not a valid Indonesian number; leave it alone rather
            // than manufacture `008…`, which is just as invalid and less
            // recognisable to whoever sent it.
            return ($sisa !== '' && $sisa[0] !== self::PREFIKS_LOKAL)
                ? self::PREFIKS_LOKAL.$sisa
                : $nomor;
        }

        if (str_starts_with($nomor, self::KODE_NEGARA)) {
            $sisa = substr($nomor, strlen(self::KODE_NEGARA));

            if ($sisa !== '' && $sisa[0] !== self::PREFIKS_LOKAL) {
                return self::PREFIKS_LOKAL.$sisa;
            }
        }

        return $nomor;
    }

    /**
     * The international `+62…` presentation of a canonical local number.
     *
     * The inverse of {@see normalisasi()}, exposed because a delivery channel
     * (WhatsApp, an SMS gateway) generally wants the country-code form while
     * the database stores the local one. A number that is not canonical local
     * is returned unchanged: this is a presentation helper, not a validator.
     */
    public static function keInternasional(?string $nomor): ?string
    {
        if ($nomor === null || $nomor === '') {
            return $nomor;
        }

        if (str_starts_with($nomor, self::PREFIKS_LOKAL)) {
            return self::KODE_NEGARA_BERPLUS.substr($nomor, 1);
        }

        return $nomor;
    }
}
