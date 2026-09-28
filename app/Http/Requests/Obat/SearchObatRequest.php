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
     * Normalise the `requires_resep` query flag before the `boolean` rule
     * sees it.
     *
     * A query string carries only text, so `?requires_resep=true` arrives as
     * the STRING `"true"` - and Laravel's `boolean` rule accepts only
     * `true, false, 1, 0, "1", "0"`, answering 422 on `"true"`/`"false"`.
     * `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`
     * folds every recognised spelling onto a real boolean, while an
     * unrecognised string is left untouched so the `boolean` rule still
     * refuses it by name. Absent stays absent: no filter must narrow the
     * catalogue.
     *
     * The 422 is the LUCKY half of the problem. The dangerous half is
     * downstream: `(bool) "false"` is TRUE, because the string is not empty,
     * so a filter built on that cast hands a caller who asked to exclude the
     * prescription-only drugs exactly the prescription-only drugs, with no
     * error anywhere to notice it by. Normalising here means the value that
     * leaves `validated()` is a real `bool`, and `ObatSearchService` applies
     * the same `filter_var` for callers that do not come through a request.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('requires_resep')) {
            return;
        }

        $mentah = $this->input('requires_resep');

        if (is_bool($mentah) || $mentah === null || $mentah === '') {
            return;
        }

        $baca = filter_var($mentah, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($baca !== null) {
            $this->merge(['requires_resep' => $baca]);
        }
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
