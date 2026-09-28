<?php

declare(strict_types=1);

namespace App\Http\Requests\Konsultasi;

/**
 * Validates `POST /api/v1/konsulfasasi/{id}/chat/baca` - the read receipt.
 *
 * The endpoint writes one column on somebody else's rows, and the whole request
 * is "stamp everything of the other party's that is still NULL". There is nothing
 * to ask, so every rule here is `prohibited`, and that is the point:
 *
 * - `dibaca_at` is the column being written, from the server's clock. A client
 *   that supplies it is claiming its own device stamped it, which would make the
 *   read receipt an assertion rather than a record.
 * - `konsultasi_id` and `pengirim_user_id` are the tenant keys, derived from the
 *   route and from the caller's own profile rows by
 *   `KonsultasiAccess::sisiDanKonsultasi()`. Naming either in a body
 *   would be a second way to address the same rows.
 *
 * `per_page` is NOT accepted, deliberately: the receipt is not a list, it is a
 * count, and a client that wants the messages asks `GET .../chat` for them. The
 * response carries `jumlah_ditandai_baca` and no `meta`, because a write is not a
 * paginated read.
 */
class TandaiDibacaRequest extends KonsultasiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dibaca_at' => ['prohibited'],
            'konsultasi_id' => ['prohibited'],
            'pengirim_user_id' => ['prohibited'],
            'pengirim_tipe' => ['prohibited'],
            'per_page' => ['prohibited'],
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
            'dibaca_at' => 'waktu dibaca',
            'konsultasi_id' => 'konsultasi',
            'pengirim_user_id' => 'pengirim',
            'pengirim_tipe' => 'tipe pengirim',
            'per_page' => 'jumlah per halaman',
        ];
    }
}
