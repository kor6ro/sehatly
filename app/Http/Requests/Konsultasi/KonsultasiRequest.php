<?php

declare(strict_types=1);

namespace App\Http\Requests\Konsultasi;

use App\Services\Konsultasi\KonsultasiService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The shared base for the five write endpoints under `/api/v1/konsultasi`.
 *
 * ## Why a sub-namespace, and why a base class at all
 *
 * The repository's convention is one sub-namespace per module
 * (`Requests\Auth\`, `Requests\Pasien\`, `Requests\Dokter\`, `Requests\Booking\`),
 * so these live in `Requests\Konsultasi\` rather than directly under
 * `Requests\` as the plan sketches. Reported rather than silently deviated from.
 *
 * A base class exists for the same reason `BookingRequest` has one: the DDL
 * vocabularies are `public const` arrays or service constants, so the suite can
 * assert them against `telemedicine_test.sql` with `SqlSchemaParser` instead of
 * trusting a transcription. `tipe_pesan` is EIGHT values and is very easy to
 * drop one of.
 *
 * ## `authorize()` is ALWAYS `true`
 *
 * The tenant question ("whose row is this") is answered by
 * `App\Services\Konsultasi\KonsultasiAccess`, the permission question by the
 * `permission:` middleware, and neither is a validation rule. `StoreBookingRequest`
 * gives the same reasoning.
 */
abstract class KonsultasiRequest extends FormRequest
{
    /**
     * `konsultasi.tipe_pesan` ENUM at `telemedicine_test.sql:568-569`,
     * in the DDL's own order. `Rule::in` and not `Rule::enum`, because a MySQL
     * ENUM has no PHP enum class of its own; the three values that must not be
     * posted by a human are re-used from
     * {@see KonsultasiService::TIPE_PESAN_SISTEM} rather than restated.
     *
     * @var list<string>
     */
    public const TIPE_PESAN = [
        'teks',
        'gambar',
        'dokumen',
        'audio',
        'video_note',
        ...self::TIPE_PESAN_SISTEM,
    ];

    /**
     * The message types whose payload is a file rather than text.
     *
     * @var list<string>
     */
    public const TIPE_PESAN_BERKAS = ['gambar', 'dokumen', 'audio', 'video_note'];

    /**
     * The three values {@see KonsultasiService::TIPE_PESAN_SISTEM} owns.
     *
     * @var list<string>
     */
    public const TIPE_PESAN_SISTEM = KonsultasiService::TIPE_PESAN_SISTEM;

    /**
     * `KonsultasiChat.pengirim_tipe` ENUM at `telemedicine_test.sql:567`, in the DDL's
     * own order.
     *
     * **This three-value column is not `users.tipe`, which is a seven-value ENUM at
     * `:139`.** Four of those seven - `perawat`, `apoteker`, `kurir`, `admin` - have
     * no name in this list, and MySQL answers an unstoreable value in strict mode
     * with 1264, so copying `users.tipe` into this column is a 500 on a legitimate
     * write rather than a wrong label. The value written is therefore the SIDE the
     * caller is party to, derived from which profile row it owns; the reasoning is
     * in `KonsultasiAccess::sisiDanKonsultasi()`.
     *
     * @var list<string>
     */
    public const PENGIRIM_TIPE = ['pasien', 'dokter', 'sistem'];
    /**
     * `authorize()` is ALWAYS `true`, for the reason in the class docblock.
     */
    public function authorize(): bool
    {
        return true;
    }
}
