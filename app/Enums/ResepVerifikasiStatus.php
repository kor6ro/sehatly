<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three `resep_verifikasi.status` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:790`:
 *
 * ```
 * status ENUM('sesuai','ada_koreksi','ditolak') NOT NULL,
 * ```
 *
 * ## Why this is a real PHP enum and not an `enum:` cast
 *
 * `ModelFoundationTest` already asserts that no model in `app/Models` uses an
 * `enum:` cast, because on laravel/framework 13.33
 * `HasAttributes::isEnumCastable()` requires `enum_exists($castType)` and an
 * `enum:sesuai,ada_koreksi,ditolak` list is not a class - it is a SILENT NO-OP.
 * It reads like validation and validates nothing, which is worse than no cast
 * at all. So the column keeps the plain `'string'` cast that
 * `ModelFoundationTest::test('every ENUM column is a plain string cast')`
 * requires, and this class is the vocabulary the SERVICE validates against.
 * `KonsultasiStatus` and `SuratKeteranganTipe` are the same pattern.
 *
 * ## `ditolak` is the member that makes this enum interesting
 *
 * `sesuai` and `ada_koreksi` both advance the prescription to `diverifikasi`.
 * `ditolak` does not, and it CANNOT: there is no `resep.status` member meaning
 * "returned for correction" - the eight members at `telemedicine_test.sql:751`
 * -`:752` are all accounted for. A rejection therefore lands on `dibatalkan`,
 * the only terminal negative state, and it is FINAL - see
 * {@see \App\Services\Resep\ResepVerifikasiService::verifikasi()}.
 */
enum ResepVerifikasiStatus: string
{
    /** The prescription is dispensed as written. */
    case Sesuai = 'sesuai';

    /** Dispensed, with a correction the pharmacist recorded in `catatan`. */
    case AdaKoreksi = 'ada_koreksi';

    /** Refused. TERMINAL - the one verification the row can hold is spent. */
    case Ditolak = 'ditolak';

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
     * The three the DDL's own COMMENT at `:785` - "e-resep diverifikasi apoteker
     * sebelum dipenuhi" - reads as an outcome, for `VerifikasiResepRequest`.
     *
     * @return list<string>
     */
    public static function aturan(): array
    {
        return self::nilai();
    }

    /**
     * Does this outcome advance the prescription to `diverifikasi`?
     *
     * Two of three do. The third is the terminal one.
     */
    public function maju(): bool
    {
        return $this !== self::Ditolak;
    }

    /**
     * Does this outcome CLOSE the prescription for good?
     *
     * True only for `ditolak`, and it is true for a reason the DDL forces: the
     * `resep_verifikasi` row is UNIQUE on `resep_id` (`:788`), so a rejection
     * consumes the single verification and no second outcome is expressible.
     */
    public function terminal(): bool
    {
        return $this === self::Ditolak;
    }
}
