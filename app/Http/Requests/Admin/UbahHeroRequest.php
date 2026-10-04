<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Landing\HeroService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /admin/hero/{id}` - a partial update of one slide.
 *
 * ## Everything is `sometimes`, and that is the point
 *
 * Every rule is `sometimes`, so a body of `{"status":"tayang"}` validates against
 * exactly one field and `HeroService::ubah()` writes exactly one column. That is what
 * makes this route the publish switch as well as the edit form: the admin screen's
 * "Tayang / Draf" toggle sends the switch alone, and the copy, the image and the
 * window stay untouched because they were never in the payload. F14's
 * `PUT /admin/dokter/{id}/status` does the same thing for the same reason.
 *
 * ## Why there is no cross-field date rule
 *
 * `mulai_tayang <= selesai_tayang` is not asserted, in either direction. The
 * migration records why: a backwards window is a representable state that matches no
 * instant, which is what "this campaign is already over" looks like in the data, and
 * refusing it here would mean an operator cannot fix a bad row by moving its start
 * date before its end in the same request. The read applies whichever bounds exist.
 *
 * ## The image is not editable here either
 *
 * Same split as the create: multipart upload through `POST /admin/hero/{id}/gambar`,
 * removal through `DELETE /admin/hero/{id}/gambar`. A JSON `PUT` cannot carry a file,
 * and `prohibited` keeps a client from believing it can.
 */
class UbahHeroRequest extends FormRequest
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
            'urutan' => ['sometimes', 'integer', 'between:0,255'],
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:60'],
            'judul' => ['sometimes', 'string', 'max:160'],
            'deskripsi' => ['sometimes', 'string', 'max:400'],
            'cta_label' => ['sometimes', 'string', 'max:60'],
            'cta_target' => ['sometimes', 'string', 'max:120', 'regex:/^\/(?!\/)/'],
            'status' => ['sometimes', Rule::in([HeroService::STATUS_DRAF, HeroService::STATUS_TAYANG])],
            'mulai_tayang' => ['sometimes', 'nullable', 'date'],
            'selesai_tayang' => ['sometimes', 'nullable', 'date'],
            'gambar' => ['prohibited'],
            'gambar_alt' => ['prohibited'],
        ];
    }

    /**
     * Indonesian labels, per the project convention.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'urutan' => 'urutan tampil',
            'eyebrow' => 'label kecil',
            'judul' => 'judul',
            'deskripsi' => 'deskripsi',
            'cta_label' => 'tombol',
            'cta_target' => 'tautan tombol',
            'status' => 'status',
            'mulai_tayang' => 'mulai tayang',
            'selesai_tayang' => 'selesai tayang',
            'gambar' => 'gambar',
            'gambar_alt' => 'teks alternatif gambar',
        ];
    }
}
