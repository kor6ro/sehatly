<?php

declare(strict_types=1);

namespace App\Http\Requests\Obat;

use App\Enums\MasterObatKelas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/obat` - search the active medicine catalogue.
 *
 * `kelas_obat` is validated against {@see MasterObatKelas}, which is asserted
 * against `telemedicine_test.sql:719` on every test run: an unknown value is
 * a 422 naming the field, never an empty list that reads as "no such drug".
 */
class SearchObatRequest extends FormRequest
{
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
            'search' => ['nullable', 'string', 'max:255'],
            'kelas_obat' => ['nullable', 'string', Rule::in(MasterObatKelas::nilai())],
            'requires_resep' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kelas_obat.in' => 'Kelas obat tidak dikenal.',
            'per_page.max' => 'Per halaman maksimal 100.',
        ];
    }
}
