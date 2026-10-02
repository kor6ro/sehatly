<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Support\WaktuIndonesia;

/**
 * The one conversion between a report's WIB date range and the UTC instants the
 * `DATETIME`/`TIMESTAMP` columns are stored in.
 *
 * ## Why a conversion exists at all
 *
 * `invoice.lunas_at`, `pembayaran.dibayar_at` and `audit_log.dibuat_at` are
 * instants: `PaymentService` writes them from `Carbon::now()`, `config/app.php`
 * is `UTC`, and the MySQL session is pinned to `+00:00`, so the stored wall
 * clock is UTC. A report's `dari`/`sampai`, on the other hand, are the clinic's
 * dates - a WIB day, because the whole product is WIB (`docs/timezone-policy.md`,
 * the F14 pattern's step 16). A query that compared a WIB date against a UTC
 * column would drop the seven evening hours of every day and move 00:00-07:00
 * WIB into the previous day's report.
 *
 * So a range is converted ONCE, here, through the project's single wall-clock
 * converter {@see WaktuIndonesia::toInstant()}: `dari 00:00:00 WIB` and
 * `sampai 23:59:59 WIB` become the inclusive UTC bounds for a `whereBetween`.
 *
 * ## The reverse direction is `CONVERT_TZ`, and the offset is deliberate
 *
 * Grouping a report by DAY needs the instant rendered back on the clinic's
 * calendar, and that is a SQL expression: `DATE(CONVERT_TZ(column, '+00:00',
 * '+07:00'))`. Numeric offsets are used rather than the `'Asia/Jakarta'` name
 * because `CONVERT_TZ` with a named zone requires the `mysql.time_zone*` tables
 * to be loaded, which this project does not ship; the offset form needs nothing.
 * `Asia/Jakarta` is a fixed +07:00 with no DST - {@see WaktuIndonesia::ZONA}
 * says so and its test asserts that `config('app.timezone')` is NOT the clinic's
 * zone - so the constant is exact rather than an approximation.
 *
 * ## What this class is NOT for
 *
 * A `DATE`/`TIME` pair (`booking.tanggal_kunjungan`, `dokter_jadwal.jam_mulai`)
 * is naive wall clock in the DDL and is never converted; a report on those
 * columns filters the `Y-m-d` strings directly. Only true instants pass through
 * here.
 */
final class RentangHari
{
    /**
     * The clinic's fixed UTC offset, `+07:00`.
     *
     * The same fact as {@see WaktuIndonesia::ZONA}, expressed in the form
     * MySQL's `CONVERT_TZ` accepts without time-zone tables.
     */
    public const OFFSET_WIB = '+07:00';

    /**
     * No instances: every method is pure.
     */
    private function __construct() {}

    /**
     * A WIB date range as the inclusive UTC instant pair a `whereBetween` needs.
     *
     * @return array{mulai: string, akhir: string} each `Y-m-d H:i:s`, UTC
     */
    public static function keUtc(string $dari, string $sampai): array
    {
        return [
            'mulai' => self::mulaiUtc($dari),
            'akhir' => self::akhirUtc($sampai),
        ];
    }

    /**
     * The first instant of a WIB calendar day, as a UTC `Y-m-d H:i:s` string.
     *
     * The string form is what a `DATETIME`/`TIMESTAMP` comparison binds: the
     * MySQL session is pinned to `+00:00`, so a UTC wall clock compares
     * correctly against a stored instant without any driver conversion.
     */
    public static function mulaiUtc(string $tanggal): string
    {
        return WaktuIndonesia::toInstant($tanggal, '00:00:00')->utc()->format('Y-m-d H:i:s');
    }

    /**
     * The last second of a WIB calendar day, as a UTC `Y-m-d H:i:s` string.
     *
     * `23:59:59` and NOT `23:59:59.999999`: the columns are second-resolution
     * `DATETIME`/`TIMESTAMP`, so a fractional upper bound would compare equal to
     * the same second and gain nothing.
     */
    public static function akhirUtc(string $tanggal): string
    {
        return WaktuIndonesia::toInstant($tanggal, '23:59:59')->utc()->format('Y-m-d H:i:s');
    }

    /**
     * The WIB calendar day of an instant column, as a SQL expression.
     *
     * `$kolom` is always a literal column name chosen by this codebase - never a
     * request value - so the interpolation is not an injection surface. It is
     * kept a method so the plus-seven arithmetic appears exactly once.
     */
    public static function tanggalWib(string $kolom): string
    {
        return "DATE(CONVERT_TZ({$kolom}, '+00:00', '".self::OFFSET_WIB."'))";
    }
}
