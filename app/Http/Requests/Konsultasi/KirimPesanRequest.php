<?php

declare(strict_types=1);

namespace App\Http\Requests\Konsultasi;

use App\Services\Konsultasi\KonsultasiService;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/v1/konsultasi/{id}/chat`.
 *
 * ## The body and the file are exclusive per message type, and BOTH rules fire
 *
 * `required_if:tipe_pesan,gambar,dokumen,audio,video_note` and
 * `prohibited_unless:tipe_pesan,gambar,dokumen,audio,video_note` are applied to
 * `berkas` together, which is deliberate. A client that sends a text message with
 * a file attached gets TWO messages on the SAME field - "the tipe_pesan field
 * must be one of ..." is not the useful half, "the berkas field is prohibited
 * when tipe_pesan is teks" is - and the envelope keeps both because
 * `ApiResponse::error()` forwards `$e->errors()` untouched.
 *
 * That is the one place this project can produce a multi-message field, and
 * `konsultasi` asserts the array has BOTH entries so a future
 * `ValidationException` rewrite that flattens to a single message fails the suite.
 *
 * ## The field is `berkas`, and that is the only non-column name in the request
 *
 * Every other field in this project is a DDL column name (`booking_id`,
 * `tipe_layanan`, `tipe_pesan`, `isi`, `jadwal_id`), because the request mirrors
 * the columns it writes. There is no column for the upload itself - the three it
 * fills are `file_url`, `file_nama` and `file_ukuran_kb` (`:571`-`:573`) - so the
 * field is named in Indonesian to match the user-facing copy, and
 * {@see KonsultasiService::simpanBerkas()} documents what it writes.
 *
 * ## `isi` is byte-bounded, not character-bounded by guesswork
 *
 * `konsultasi.isi` is `TEXT` (`telemedicine_test.sql:570`), which MySQL
 * defines as 65535 BYTES. The table's collation is utf8mb4, where one character
 * can be four bytes, so a 16 384-character string is already the ceiling and
 * anything above it fails the write with 1406 rather than the validation layer.
 * The rule is `max:16000` - under the worst-case bound, with room for the fact
 * that most of the text is ASCII - so the failure is a 422 the client can read
 * instead of a 500 it cannot.
 */
class KirimPesanRequest extends KonsultasiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $perluBerkas = 'required_if:tipe_pesan,'.implode(',', self::TIPE_PESAN_BERKAS);
        $hanyaBerkas = 'prohibited_unless:tipe_pesan,'.implode(',', self::TIPE_PESAN_BERKAS);

        return [
            'tipe_pesan' => ['required', 'string', Rule::in(self::TIPE_PESAN)],
            'isi' => ['required_if:tipe_pesan,teks', 'nullable', 'string', 'max:16000'],
            'berkas' => [
                $perluBerkas,
                $hanyaBerkas,
                'file',
                'max:'.KonsultasiService::BERKAS_MAKS_KB,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }

                    $tipe = (string) $this->input('tipe_pesan');
                    $mime = (string) $value->getMimeType();
                    $boleh = KonsultasiService::MIME_BERKAS[$tipe] ?? null;

                    if ($boleh === null || in_array($mime, $boleh, true)) {
                        return;
                    }

                    $fail('Berkas untuk tipe pesan "'.$tipe.'" harus berformat: '.implode(', ', $boleh).'.');
                },
            ],
            'konsultasi_id' => ['prohibited'],
            'pengirim_user_id' => ['prohibited'],
            'pengirim_tipe' => ['prohibited'],
            'dibaca_at' => ['prohibited'],
            'terkirim_at' => ['prohibited'],
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
            'tipe_pesan' => 'tipe pesan',
            'isi' => 'isi pesan',
            'berkas' => 'berkas',
            'konsultasi_id' => 'konsultasi',
            'pengirim_user_id' => 'pengirim',
            'pengirim_tipe' => 'tipe pengirim',
            'dibaca_at' => 'waktu dibaca',
            'terkirim_at' => 'waktu terkirim',
        ];
    }
}
