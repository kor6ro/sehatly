<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

/**
 * Validates `POST /api/v1/pasien/alergi`.
 *
 * `tipe_alergen` and `nama_alergen` are required, because
 * `telemedicine_test.sql:277` and `:278` declare both `NOT NULL` with no default and a
 * create that omitted one would be MySQL 1364 - a 500. `reaksi` and `keparahan` are
 * nullable columns (`:279`) and a `NOT NULL DEFAULT 'ringan'` column (`:280`).
 *
 * The rest of the reasoning, including why the two ENUM lists are `Rule::in` rather than
 * `Rule::enum` and why `pasien_id` and `dicatat_oleh_user_id` are absent from the rules,
 * is on {@see AlergiRequest}.
 */
class StoreAlergiRequest extends AlergiRequest
{
    /**
     * `tipe_alergen` and `nama_alergen` must be present on create.
     */
    protected function isCreate(): bool
    {
        return true;
    }
}
