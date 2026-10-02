<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminJadwalService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /admin/dokter/{id}/libur` - one whole-day leave date.
 *
 * ## No hours, because the schema has none
 *
 * `dokter_libur` (`telemedicine_test.sql:490-496`) is `dokter_id`, `tanggal`,
 * `alasan` and an id. There is no start time and no end time, so a half-day
 * closure cannot be represented and this request offers no field for one. The
 * F14 pattern's rule is that the server must not accept a shape the database
 * cannot store honestly, and the UI's note ("Libur berlaku seharian") is
 * downstream of this rule.
 *
 * `alasan` is `VARCHAR(200) NULL` (`:494`), so the bound is the column's own
 * width. It is optional: a date with no annotation is a complete record.
 *
 * The duplicate `(dokter_id, tanggal)` guard is an application check in
 * {@see AdminJadwalService::storeLibur()}; the table has no
 * UNIQUE on the pair, and `docs/schema-notes.md` records that adding one would
 * be schema drift.
 */
class StoreLiburRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'alasan' => ['nullable', 'string', 'max:200'],
        ];
    }
}
