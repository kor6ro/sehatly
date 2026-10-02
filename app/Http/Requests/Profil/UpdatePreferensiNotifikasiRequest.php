<?php

declare(strict_types=1);

namespace App\Http\Requests\Profil;

use App\Enums\JamTenangMode;
use App\Enums\PreferensiNotifikasiTipe;
use App\Enums\ZonaWaktu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/v1/profil/notifikasi` - the caller's own matrix and quiet hours.
 *
 * ## Everything is optional, because this is a form PUT
 *
 * Each field the caller sends is validated and merged; each field it omits is
 * left alone. A client that flips one checkbox does not have to echo the quiet
 * hours back to keep them, and a client that sets the window does not have to
 * name all four types.
 *
 * ## The matrix is CLOSED: extra keys are refused, not ignored
 *
 * `array:booking,pembayaran,resep,chat` is a key WHITELIST - `push.lab = true`
 * is a 422 rather than a silent no-op, because `lab`/`promo`/`sistem` are not
 * offered and a client that sends one believes it changed something. The four
 * names come from the DDL ENUM (impossible to accept a fifth) and are asserted
 * against it by the test.
 *
 * ## `HH:MM`, and the seconds are not accepted
 *
 * The columns are `TIME`, but the API publishes and accepts `HH:MM`: a time
 * input sends what a human reads, and `21:00:00` and `21:00` naming the same
 * value would be two spellings of one field. The services layer stores the
 * column's own `HH:MM:SS`.
 */
class UpdatePreferensiNotifikasiRequest extends FormRequest
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
            'jam_tenang_aktif' => ['sometimes', 'boolean'],
            'jam_tenang_mode' => ['sometimes', Rule::enum(JamTenangMode::class)],
            'jam_tenang_mulai' => ['sometimes', 'date_format:H:i'],
            'jam_tenang_selesai' => ['sometimes', 'date_format:H:i'],
            'zona_waktu' => ['sometimes', Rule::enum(ZonaWaktu::class)],
            'push' => ['sometimes', 'array:'.implode(',', PreferensiNotifikasiTipe::nilai())],
            'push.*' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jam_tenang_aktif.boolean' => 'Nilai jam tenang aktif harus benar atau salah.',
            'jam_tenang_mode.*' => 'Mode jam tenang tidak dikenal.',
            'jam_tenang_mulai.date_format' => 'Jam mulai harus berformat HH:MM.',
            'jam_tenang_selesai.date_format' => 'Jam selesai harus berformat HH:MM.',
            'zona_waktu.*' => 'Zona waktu tidak dikenal.',
            'push.array' => 'Preferensi push hanya menerima tipe booking, pembayaran, resep, dan chat.',
            'push.*.boolean' => 'Nilai push harus benar atau salah.',
        ];
    }
}
