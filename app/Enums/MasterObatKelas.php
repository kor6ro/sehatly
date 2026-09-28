<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The six `master_obat.kelas_obat` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:719`:
 *
 * ```
 * kelas_obat ENUM('bebas','bebas_terbatas','keras','fitofarmaka','narkotika','psikotropika') NOT NULL,
 * ```
 *
 * Read from the file byte by byte: the fifth member is nine lower-case ASCII
 * letters, `narkotika`. `ResepTodo39Test` re-parses the DDL with the project's
 * own `App\Support\Schema\SqlSchemaParser` and asserts `nilai()` is identical
 * with `toBe`, so no transcription can drift.
 *
 * A real PHP enum (never an `enum:` cast, which is a silent no-op on
 * laravel/framework 13.33) and the model keeps its plain `'string'` cast per
 * the project-wide `ModelFoundationTest` invariant.
 */
enum MasterObatKelas: string
{
    case Bebas = 'bebas';
    case BebasTerbatas = 'bebas_terbatas';
    case Keras = 'keras';
    case Fitofarmaka = 'fitofarmaka';
    case Narkotika = 'narkotika';
    case Psikotropika = 'psikotropika';

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
