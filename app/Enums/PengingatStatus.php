<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three `pengingat.status` values, in DDL order.
 *
 * `telemedicine_test.sql:1409` (F11 section `[18]`):
 * `ENUM('aktif','nonaktif','selesai') NOT NULL DEFAULT 'aktif'`.
 *
 * Only `aktif` is evaluated by the `pengingat:kirim` scheduler. `nonaktif` is
 * the user's own pause ("menonaktifkan obat menghentikan pengingat
 * berikutnya", F11 AC-R3) and `selesai` is a terminal state; neither is ever
 * written automatically, because a reminder whose window has run out and one
 * the user closed look the same to a naive expiry job and the schema has no
 * column that distinguishes them.
 */
enum PengingatStatus: string
{
    case Aktif = 'aktif';
    case Nonaktif = 'nonaktif';
    case Selesai = 'selesai';

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
