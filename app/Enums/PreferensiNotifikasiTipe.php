<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four `preferensi_notifikasi_tipe.tipe` values, in DDL order.
 *
 * `telemedicine_test.sql:1387` (F11 section `[18]`):
 * `ENUM('booking','pembayaran','resep','chat') NOT NULL`, with no DEFAULT.
 *
 * **A strict SUBSET of `notifikasi.tipe`**: `lab`, `promo` and `sistem` are NOT
 * offered as preferences. `lab` and `promo` have no producer in this
 * application, and `sistem` is the catch-all the scheduler itself writes for
 * reminder notices; surfacing a switch a user cannot observe is exactly the
 * "invented control" the F11 pattern forbids. In-app delivery is not a
 * preference at all and has no column on `preferensi_notifikasi_tipe`.
 */
enum PreferensiNotifikasiTipe: string
{
    case Booking = 'booking';
    case Pembayaran = 'pembayaran';
    case Resep = 'resep';
    case Chat = 'chat';

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
     * Is this value a member of the `notifikasi.tipe` ENUM as well?
     *
     * Used by the scheduler to decide whether a produced notification type has a
     * preference row at all: `sistem` returns false, and the absence of a row
     * means "default on" by the schema's own convention.
     */
    public function dipakaiNotifikasi(): bool
    {
        return in_array($this->value, NotifikasiTipe::nilaiYangDipakai(), true);
    }
}
