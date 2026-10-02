<?php

declare(strict_types=1);

namespace App\Http\Requests\Pengingat;

use App\Enums\PengingatStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/pengingat` - the caller's own reminders.
 *
 * `status` is an optional exact filter over the DDL's three values; page and
 * per_page follow the project-wide pagination contract (`PER_PAGE_MAKS = 100`,
 * the same cap `IndexNotifikasiRequest` declares).
 */
class IndexPengingatRequest extends FormRequest
{
    public const PER_PAGE_MAKS = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(PengingatStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAKS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.*' => 'Status pengingat tidak dikenal.',
            'page.integer' => 'Nilai page tidak valid.',
            'page.min' => 'Nilai page tidak valid.',
            'per_page.integer' => 'Nilai per_page tidak valid.',
            'per_page.min' => 'Nilai per_page tidak valid.',
            'per_page.max' => 'Nilai per_page maksimal '.self::PER_PAGE_MAKS.'.',
        ];
    }
}
