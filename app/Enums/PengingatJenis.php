<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two `pengingat.jenis` values, in DDL order.
 *
 * `telemedicine_test.sql:1398` (F11 section `[18]`):
 * `ENUM('obat','janji_temu') NOT NULL`, with no DEFAULT.
 *
 * The two kinds carry different optional fields and different notification
 * types:
 *
 * | `jenis` | optional links | in-app `notifikasi.tipe` |
 * | --- | --- | --- |
 * | `obat` | `obat_id` (catalogue), `dosis`, `jumlah_per_hari` | `sistem` |
 * | `janji_temu` | `booking_id` | `booking` |
 *
 * A medicine reminder has no produced notification type to map onto - the four
 * produced types are `booking`, `pembayaran`, `resep` and `chat` - so it uses
 * the schema's own catch-all `sistem` value. An appointment reminder IS a
 * booking event and maps to `booking`, which also gives it a row in the
 * per-type push preference matrix.
 */
enum PengingatJenis: string
{
    case Obat = 'obat';
    case JanjiTemu = 'janji_temu';

    /**
     * Every value, in the DDL's declaration order.
     *
     * @return list<string>
     */
    public static function nilai(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
