<?php

declare(strict_types=1);

namespace App\Http\Requests\Resep;

use App\Services\Resep\ResepService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/konsultasi/{id}/resep` - write one e-prescription.
 *
 * Machine-owned columns are `prohibited`, not merely absent: the patient, the
 * doctor, the number, the QR token, the dates and the money are all derived
 * by `ResepService` from the consultation, the caller, the clock, the
 * catalogue and a generator. `prohibited` answers 422 naming the field rather
 * than dropping it silently.
 *
 * `catatan_dokter` is `prohibited` while `catatan_dodio` is allowed, and that
 * asymmetry is the acknowledgement: the note is only recordable AS an
 * acknowledgement, never as a direct write, so a caller cannot fake a note
 * that was never given as one.
 */
class StoreResepRequest extends FormRequest
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
        $prohibited = [];

        foreach (ResepService::KOLOM_MILIK_SISTEM as $kolom) {
            if ($kolom !== 'konsultasi_id') {
                $prohibited[$kolom] = ['prohibited'];
            }
        }

        foreach (ResepService::KOLOM_ITEM_MILIK_SISTEM as $kolom) {
            $prohibited['items.*.'.$kolom] = ['prohibited'];
        }

        // `konsultasi_id` is the path segment, so it is prohibited under its
        // own name rather than skipped: it IS a real `resep` column.
        $prohibited['konsultasi_id'] = ['prohibited'];

        // `catatan_dokter` is the acknowledgement's TARGET column: writable
        // only through `catatan_dodio`, never directly.
        $prohibited['catatan_dokter'] = ['prohibited'];

        return array_merge([
            'catatan_dodio' => ['nullable', 'string', 'max:16000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.obat_id' => ['nullable', 'integer', 'min:1'],
            'items.*.nama_obat' => ['nullable', 'string', 'max:255'],
            'items.*.kekuatan' => ['nullable', 'string', 'max:50'],
            'items.*.aturan_pakai' => ['required', 'string', 'max:255'],
            'items.*.jumlah' => ['required', 'integer', 'min:1', 'max:65535'],
            'items.*.satuan' => ['nullable', 'string', 'max:30'],
            'items.*.is_racikan' => ['nullable', 'boolean'],
            'items.*.racikan_nama' => ['nullable', 'string', 'max:100'],
        ], $prohibited);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Prescription must contain at least one item.',
            'items.min' => 'Prescription must contain at least one item.',
        ];
    }
}
