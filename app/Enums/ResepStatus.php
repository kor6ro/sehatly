<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The eight `resep.status` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:751-752`:
 *
 * ```
 * status ENUM('aktif','diproses','diverifikasi','dipenuhi','dikirim','selesai',
 *             'kedaluwarsa','dibatalkan') NOT NULL DEFAULT 'aktif',
 * ```
 *
 * The declaration WRAPS onto a second line, so reading `:751` alone yields five
 * members and makes the column look like a different one. `ResepTodo39Test`
 * re-parses the DDL with the project's own `App\Support\Schema\SqlSchemaParser`
 * and asserts `nilai()` is identical with `toBe`, which checks order as well as
 * membership.
 *
 * A real PHP enum (never an `enum:` cast, which is a silent no-op on
 * laravel/framework 13.33) and the model keeps its plain `'string'` cast per
 * the project-wide `ModelFoundationTest` invariant.
 */
enum ResepStatus: string
{
    case Aktif = 'aktif';
    case Diproses = 'diproses';
    case Diverifikasi = 'diverifikasi';
    case Dipenuhi = 'dipenuhi';
    case Dikirim = 'dikirim';
    case Selesai = 'selesai';
    case Kedaluwarsa = 'kedaluwarsa';
    case Dibatalkan = 'dibatalkan';

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
     * The DDL's own default: a prescription is born live.
     */
    public static function default(): string
    {
        return self::Aktif->value;
    }
}
