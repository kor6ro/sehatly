<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three `preferensi_notifikasi.jam_tenang_mode` values, in DDL order.
 *
 * `telemedicine_test.sql:1374` (F11 section `[18]`), a single-line ENUM:
 * `ENUM('setiap_hari','hari_kerja','kustom') NOT NULL DEFAULT 'setiap_hari'`.
 *
 * ## `kustom` has NO day list to read
 *
 * The approved F11 schema carries a mode but **no custom-day column**, so
 * `kustom` cannot mean "a user-picked set of days" yet. The scheduler treats
 * both `setiap_hari` and `kustom` as "every day" at the configured times, and
 * `hari_kerja` as Monday-Friday only. The gap is recorded rather than
 * papered over: a per-day selector needs a schema change and is listed as an
 * open item in the F11 backend report.
 */
enum JamTenangMode: string
{
    case SetiapHari = 'setiap_hari';
    case HariKerja = 'hari_kerja';
    case Kustom = 'kustom';

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
