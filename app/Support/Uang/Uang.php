<?php

declare(strict_types=1);

namespace App\Support\Uang;

use InvalidArgumentException;

/**
 * Decimal money, as strings, through bcmath.
 *
 * ## Why this exists rather than a `$harga * $jumlah`
 *
 * Every money column in this schema is `DECIMAL` and every money value on the
 * wire is a JSON **string**: `"150000.00"`, not `150000.0`. A JSON number has
 * already lost precision by the time PHP's parser hands it over, and a float
 * then loses more on every multiplication, so `0.1 + 0.2` is the wrong answer
 * to a question about Indonesian rupiah. bcmath carries an arbitrary number of
 * decimal places exactly, which is the only representation that can be
 * round-tripped into a `DECIMAL(12,2)` and back out of it unchanged.
 *
 * `ResepService::subtotal()` is the precedent - `bcmul($harga, $jumlah, 2)` -
 * and this class is the same decision with the parsing and the rounding made
 * explicit instead of incidental.
 *
 * ## Rounding is HALF UP, and `bcdiv` alone does not do it
 *
 * bcmath's `bcdiv($a, $b, $scale)` TRUNCATES toward zero. For money that is the
 * wrong rule: a third of a cent is half a cent, and a patient is owed the
 * cent. So every value that can acquire a third decimal digit - a percentage of
 * a price, a percentage admin fee - goes through {@see setengahNaik()}, which
 * scales to hundredths, adds half, truncates, and scales back. The scale it
 * scales at is 2, not 0, so `110998.89 + 0.5 = 110999.39` truncates to 110999
 * and not to 110998: a scale-0 addition would drop the `.89` first and round
 * the wrong way.
 *
 * ## What counts as a money string
 *
 * {@see parse()} is the gate, and it is deliberately strict. Money arrives as a
 * string and only as a string: a float has already lost precision, `true` is
 * not an amount, `null` is an amount nobody stated, and an array is an amount
 * from a different document. `'1e3'`, `'100,00'`, `' 100.00 '` and `'10.005'`
 * are all refusals rather than normalisations, because each of them is a
 * different currency or a different document's convention wearing the same
 * field name. The regex is anchored on both ends, so a permissive-looking
 * substring cannot slip through.
 */
final class Uang
{
    /**
     * Working precision for an intermediate product, in decimal places.
     *
     * Six is chosen because the widest percentage in this schema is applied to
     * the widest price: a 100.00% `nilai` on a 999999999999.99 `subtotal` is
     * eleven integer digits, so six fractional places leave the cent fully
     * determined and the intermediate product exact.
     */
    public const SKALA_KERJA = 6;

    /**
     * The largest value `DECIMAL(14,2)` can hold, which is what every money
     * column on `invoice` is.
     *
     * A value above this is not rounded into the column - it is REFUSED, because
     * a silently truncated total is a patient charged the wrong amount.
     */
    public const BATAS_DECIMAL_14_2 = '999999999999.99';

    /**
     * The largest value `DECIMAL(12,2)` can hold, which is what `nilai`,
     * `min_transaksi`, `maks_diskon` and `nilai_diskon` are.
     */
    public const BATAS_DECIMAL_12_2 = '9999999999.99';

    /**
     * Accept a money value from the API boundary and return it normalised to
     * two decimal places.
     *
     * @param  bool  $bolehNol  whether `0` / `0.00` is a legal amount. A line
     *                           price of zero mints a zero-value invoice, so it
     *                           is not; a shipping charge of zero is an order
     *                           with nothing to ship for, so it is.
     * @param  int  $batas  the column the value will be stored in
     *
     * @throws InvalidArgumentException naming what was wrong, for the caller to
     *                               file on the field the value came in on
     */
    public static function parse(mixed $masuk, bool $bolehNol = false, string $batas = self::BATAS_DECIMAL_14_2): string
    {
        if (is_bool($masuk) || is_float($masuk) || is_array($masuk) || $masuk === null) {
            throw new InvalidArgumentException(
                'Nilai uang harus berupa string desimal, bukan '.get_debug_type($masuk).'.'
            );
        }

        if (is_int($masuk)) {
            $masuk = (string) $masuk;
        }

        if (! is_string($masuk)) {
            throw new InvalidArgumentException('Nilai uang harus berupa string desimal.');
        }

        if (preg_match('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/', $masuk) !== 1) {
            throw new InvalidArgumentException(
                "Nilai uang [{$masuk}] bukan format desimal dengan maksimal dua angka di belakang koma."
            );
        }

        $normal = self::normal($masuk);

        if (! $bolehNol && self::nol($normal)) {
            throw new InvalidArgumentException('Nilai uang harus lebih besar dari nol.');
        }

        if (self::gt($normal, $batas)) {
            throw new InvalidArgumentException(
                "Nilai uang [{$normal}] melebihi kapasitas kolom DECIMAL(14,2) sebesar [{$batas}]."
            );
        }

        return $normal;
    }

    /**
     * Normalise a decimal string to exactly two places, without going through a
     * float: `'150000'` becomes `'150000.00'` and `'1000.5'` becomes
     * `'1000.50'`.
     */
    public static function normal(string $nilai): string
    {
        $tanda = str_starts_with($nilai, '-') ? '-' : '';

        $nilai = ltrim($nilai, '-');

        [$bulat, $pecahan] = array_pad(explode('.', $nilai, 2), 2, '');

        return $tanda.$bulat.'.'.str_pad(substr($pecahan, 0, 2), 2, '0');
    }

    public static function nol(string $a): bool
    {
        return bccomp(self::normal($a), '0.00', 2) === 0;
    }

    public static function lt(string $a, string $b): bool
    {
        return bccomp(self::normal($a), self::normal($b), 2) < 0;
    }

    public static function gte(string $a, string $b): bool
    {
        return bccomp(self::normal($a), self::normal($b), 2) >= 0;
    }

    public static function gt(string $a, string $b): bool
    {
        return bccomp(self::normal($a), self::normal($b), 2) > 0;
    }

    public static function min(string $a, string $b): string
    {
        return self::lt($a, $b) ? self::normal($a) : self::normal($b);
    }

    public static function max(string $a, string $b): string
    {
        return self::lt($a, $b) ? self::normal($b) : self::normal($a);
    }

    public static function jumlah(string ...$nilai): string
    {
        $total = '0.00';

        foreach ($nilai as $satu) {
            $total = bcadd($total, self::normal($satu), 2);
        }

        return self::normal($total);
    }

    public static function kurang(string $a, string $b): string
    {
        return self::normal(bcsub(self::normal($a), self::normal($b), 2));
    }

    /**
     * `$nilai * $pengali`, at two places. `$pengali` is an int, which is the
     * only multiplier a caller has: a line quantity, never a price.
     */
    public static function kali(string $nilai, int $pengali): string
    {
        return self::normal(bcmul(self::normal($nilai), (string) $pengali, 2));
    }

    /**
     * `$persen` percent of `$dasar`, rounded HALF UP to the cent.
     *
     * `bcmul` then `bcdiv` at {@see SKALA_KERJA}, then
     * {@see setengahNaik()}: the division cannot be done at two places
     * directly because the third digit is the one being decided.
     */
    public static function persenDari(string $dasar, string $persen): string
    {
        $mentah = bcdiv(
            bcmul(self::normal($dasar), self::normal($persen), self::SKALA_KERJA),
            '100',
            self::SKALA_KERJA
        );

        return self::setengahNaik($mentah);
    }

    /**
     * Round a decimal string HALF UP to two places.
     *
     * The scale-2 multiply before the half is load-bearing: `bcmul` at scale 0
     * would truncate `110998.89` to `110998` BEFORE the half was added, and the
     * answer would come out a cent low. Every intermediate therefore keeps its
     * cents.
     */
    public static function setengahNaik(string $nilai): string
    {
        $seratus = bcadd(bcmul($nilai, '100', 2), '0.5', 0);

        return self::normal(bcdiv($seratus, '100', 2));
    }
}
