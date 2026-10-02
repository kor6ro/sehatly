<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /admin/dokter/{id}/jadwal` - the admin schedule list's one filter.
 *
 * ## `status_aktif` is optional and both directions are real
 *
 * An absent filter returns drafts AND published windows, because the admin tab
 * shows both (the F14 wireframe marks each row "Terbit" or "Draf"). `true` is
 * the published set and `false` is the draft set; neither is collapsed into the
 * other, and the service applies the filter to the real column.
 *
 * The list is deliberately NOT paginated: a doctor's weekly template is seven
 * days' worth of rows, and the F14 pattern's screen renders the whole week. The
 * response therefore carries `ApiResponse::singlePageMeta()` rather than a
 * paginator block, and this request declares no `page`/`per_page`; those keys
 * are neither validated nor read.
 */
class IndexAdminJadwalRequest extends FormRequest
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
            'status_aktif' => ['nullable', 'boolean'],
        ];
    }
}
