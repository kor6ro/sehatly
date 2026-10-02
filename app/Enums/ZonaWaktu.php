<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The IANA zones the F11 reminder/preference surface accepts, WIB/WITA/WIT.
 *
 * ## Why three, when `docs/timezone-policy.md` declares the platform WIB-only
 *
 * F-010 ("Product decision: WIB only") was written when the schema carried **no
 * zone column** on `booking`, `dokter_jadwal` or `faskes`, so a second zone had
 * nowhere to live. The owner-approved F11 schema changes that: both
 * `preferensi_notifikasi.zona_waktu` and `pengingat.zona_waktu` are
 * `VARCHAR(40) NOT NULL DEFAULT 'Asia/Jakarta'` (`telemedicine_test.sql:1377`,
 * `:1408`), specifically so a user's reminder fires on their own wall clock.
 * Jakarta remains the default for every row that does not opt in, so F-010's
 * operative effect - "the clinic's own scheduling is WIB" - is unchanged; what
 * is new is that a user-authored reminder may name one of the three Indonesian
 * zones.
 *
 * The set is closed in the application (the column itself accepts any 40-char
 * string) and covers Indonesia exactly: WIB (+07:00), WITA (+08:00), WIT
 * (+09:00). None observes daylight saving, so each is a fixed offset and no
 * DST arithmetic exists anywhere.
 *
 * `label()` is the short form the UI writes beside a wall clock ("21.00 WIB").
 */
enum ZonaWaktu: string
{
    case Wib = 'Asia/Jakarta';
    case Wita = 'Asia/Makassar';
    case Wit = 'Asia/Jayapura';

    /**
     * Every value, in west-to-east order.
     *
     * @return list<string>
     */
    public static function nilai(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The default every nullable `zona_waktu` column falls back to.
     */
    public const DEFAULT = 'Asia/Jakarta';

    /**
     * The three-letter label Indonesian users read (`WIB`, `WITA`, `WIT`).
     */
    public function label(): string
    {
        return match ($this) {
            self::Wib => 'WIB',
            self::Wita => 'WITA',
            self::Wit => 'WIT',
        };
    }
}
