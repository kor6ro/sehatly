<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Booking\SlotAvailabilityService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The one place a **wall clock** becomes an **instant**.
 *
 * `docs/timezone-policy.md` carries the policy. This class is the enforcement of
 * the single conversion it permits, and the reason the conversion is a class and
 * not an inline `Carbon::parse($x, 'Asia/Jakarta')` at each call site.
 *
 * ## What this is for
 *
 * Two questions in this schema are constantly confused, and both end in a call to
 * one of these three methods:
 *
 * - "**Is this slot still bookable?**" The slot is a `TIME` and the day is a
 *   `DATE`, both clinic-local. The answer needs an *instant*, so the pair is
 *   combined - {@see toInstant()} - and compared with {@see now()}.
 * - "**Is this promo running?**" The window is an operator's local wall clock.
 *   Same conversion, same requirement.
 *
 * ## What this is NOT for
 *
 * A `DATE` or a `TIME` is **never** converted for display. `pasien.tanggal_lahir`
 * stays `1990-01-01`; `booking.slot_mulai` stays `23:30:00`. Adding an offset to
 * either is a bug, and shifting a `TIME` by seven hours moves a clinic's opening
 * hour to the afternoon. Nothing here formats a slot.
 *
 * ## Why `Asia/Jakarta` is a constant and not a config value
 *
 * WIB is a fixed +07:00 with no daylight saving, so the zone is a fact about the
 * business rather than a deployment detail. A configurable zone is a knob nobody
 * turns and a value somebody eventually sets to UTC, which is the bug this class
 * exists to prevent.
 *
 * ## Why every signature is `CarbonInterface` and never `Carbon`
 *
 * laravel/framework 13.33 boots with `Date::use(CarbonImmutable::class)`, so
 * `now()` returns a `Carbon\CarbonImmutable` - a **sibling** of
 * `Illuminate\Support\Carbon`, not a subclass of it. A `?Carbon` return type does
 * not match it, and PHP raises a `TypeError` on a path that looks correct in every
 * editor. This project has already been bitten by exactly that, so the interface
 * is the only type that appears here.
 *
 * @see SlotAvailabilityService which asks "is it over?" in this zone.
 */
final class WaktuIndonesia
{
    /**
     * The clinic's zone. Fixed +07:00, no DST, so nothing here needs offset
     * arithmetic - the IANA name resolves it.
     */
    public const ZONA = 'Asia/Jakarta';

    /** `Y-m-d`, the only date format this API puts on the wire. */
    public const FORMAT_TANGGAL = 'Y-m-d';

    /** `H:i:s`, the only shape a `TIME` column is ever published in. */
    public const FORMAT_WAKTU = 'H:i:s';

    /** Latest hour a value of {@see toInstant()} may carry. */
    private const JAM_TERAKHIR = 23;

    /** Latest minute or second a value of {@see toInstant()} may carry. */
    private const SATUAN_TERAKHIR = 59;

    /**
     * "Now" as the clinic experiences it.
     *
     * The same *instant* as `now()`, expressed on the Jakarta wall clock. The
     * distinction matters because the instant is what a comparison needs and the
     * wall clock is what a human reads: comparing a Jakarta wall clock against
     * `now()` in UTC is the seven-hour bug this class prevents.
     *
     * Immutable, so a caller cannot mutate shared state, and
     * `Carbon::setTestNow()` is honoured - which is what makes the slot and promo
     * rules testable against a frozen clock.
     */
    public static function now(): CarbonInterface
    {
        return CarbonImmutable::now(self::ZONA);
    }

    /**
     * Today, on the clinic's calendar.
     *
     * Deliberately **not** `SELECT CURDATE()`. `CURDATE()` is a *server-local*
     * wall clock: it was right on the development host only because that host
     * happens to be set to WIB, and it silently became the UTC day the moment the
     * connection was pinned to `+00:00` - which is the first half of
     * `docs/timezone-policy.md` requiring. A clinic's "today" is a business fact,
     * not a property of whichever server is answering.
     */
    public static function tanggal(string $format = self::FORMAT_TANGGAL): string
    {
        return self::now()->format($format);
    }

    /**
     * The one supported route from a `DATE` + `TIME` pair to an instant.
     *
     * A `TIME` on its own is not convertible - `17:00:00` is a reading on a clock,
     * not a moment - so the date has to come from the separate `DATE` column that
     * holds the day. This method is where `Asia/Jakarta` is named for that
     * purpose, and it is the reason there is exactly one such naming in the
     * application.
     *
     * @param  string  $tanggal  `Y-m-d`, exactly as stored in the `DATE` column
     * @param  string  $jam  `H:i:s` as stored in the `TIME` column, or `H:i`
     *
     * @throws InvalidArgumentException when either argument is not a real time of day
     */
    public static function toInstant(string $tanggal, string $jam): CarbonInterface
    {
        [$jam, $menit, $detik] = self::pecahJam($jam);

        // `createFromFormat` accepts `2026-13-45` and returns `2027-02-14`, so the
        // round trip through `format()` is what actually validates the date. A
        // `DATE` column cannot hold an overflowing value, so arriving here with one
        // means a caller built the string by hand.
        $hari = CarbonImmutable::createFromFormat('!'.self::FORMAT_TANGGAL, $tanggal, self::ZONA);

        if ($hari === false || $hari->format(self::FORMAT_TANGGAL) !== $tanggal) {
            throw new InvalidArgumentException(
                'tanggal must be a real calendar date in '.self::FORMAT_TANGGAL.', got ['.$tanggal.']',
            );
        }

        return $hari->setTime($jam, $menit, $detik);
    }

    /**
     * The instant a wall-clock pair names, formatted back into the two stored
     * forms - the inverse of {@see toInstant()}, for a caller that has to write
     * the pair rather than read it.
     *
     * @return array{tanggal: string, jam: string}
     */
    public static function kePasang(string $tanggal, string $jam): array
    {
        $momen = self::toInstant($tanggal, $jam);

        return [
            'tanggal' => $momen->format(self::FORMAT_TANGGAL),
            'jam' => $momen->format(self::FORMAT_WAKTU),
        ];
    }

    /**
     * `H:i` or `H:i:s` into `[hour, minute, second]`.
     *
     * `H:i` is accepted because a hand-authored booking request may name a slot as
     * `17:00`, and refusing it over a missing `:00` would be pedantry about a
     * format the `TIME` column itself never stores.
     *
     * @return array{0: int, 1: int, 2: int}
     *
     * @throws InvalidArgumentException when `$jam` is not a time of day
     */
    private static function pecahJam(string $jam): array
    {
        $potongan = explode(':', trim($jam));

        if (count($potongan) < 2 || count($potongan) > 3) {
            throw new InvalidArgumentException('jam must be H:i or H:i:s, got ['.$jam.']');
        }

        foreach ($potongan as $bagian) {
            if (preg_match('/^\d{1,2}$/', $bagian) !== 1) {
                throw new InvalidArgumentException('jam must be H:i or H:i:s, got ['.$jam.']');
            }
        }

        $nilai = [(int) $potongan[0], (int) $potongan[1], (int) ($potongan[2] ?? 0)];

        // MySQL `TIME` permits `25:00:00`, so an hour above 23 is representable in
        // the column. It is NOT a time of day and cannot become an instant on this
        // date, so it is refused here rather than rolled over into the next day -
        // a caller that means "the small hours of tomorrow" has a `DATE` for that.
        if ($nilai[0] > self::JAM_TERAKHIR
            || $nilai[1] > self::SATUAN_TERAKHIR
            || $nilai[2] > self::SATUAN_TERAKHIR) {
            throw new InvalidArgumentException(
                'jam must be a time of day within 00:00:00-23:59:59, got ['.$jam.']',
            );
        }

        return $nilai;
    }
}
