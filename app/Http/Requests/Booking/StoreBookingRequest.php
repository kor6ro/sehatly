<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Models\DokterJadwal;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/v1/booking`.
 *
 * `pasien_id` is the tenant key, written from the caller's own row and never
 * read from a body. It is `prohibited` rather than merely absent from the
 * rules, so a client that tries is told so instead of being silently ignored.
 *
 * `anggota_keluarga_id` is `prohibited` for the opposite reason: the column is
 * real and nullable (`2026_10_01_000037_booking_table.php:178`) and
 * {@see \App\Models\Booking::anggotaKeluarga()} still points at it, but the
 * four routes that let a patient populate `pasien_anggota_keluarga` were
 * removed on 2026-10-15 (see `routes/api.php` for why). Accepting an id with
 * no way to have registered one would either fail the foreign key or point at
 * a row this caller cannot see, so the field is refused outright and
 * `BookingService` writes NULL. Removing the rule instead would silently drop
 * the key, which is the failure mode this class exists to avoid.
 *
 * `tanggal_kunjungan` and `slot_mulai` are `date_format` checks and not `date`
 * checks: `Carbon::createFromFormat('Y-m-d', '2026-13-45')` does NOT fail,
 * PHP overflows month 13 and day 45 into 2027-02-14, while the format rule
 * round-trips and refuses.
 */
class StoreBookingRequest extends BookingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dokter_id' => ['required', 'integer', 'exists:dokter,id'],
            'jadwal_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $dokterId = $this->input('dokter_id');

                    if (! is_numeric($dokterId)) {
                        return;
                    }

                    $milikDokter = DokterJadwal::query()
                        ->whereKey((int) $value)
                        ->where('dokter_id', (int) $dokterId)
                        ->exists();

                    if (! $milikDokter) {
                        $fail('Jadwal yang dipilih bukan milik dokter tersebut.');
                    }
                },
            ],
            'tipe_layanan' => ['required', 'string', Rule::in(self::TIPE_LAYANAN)],
            'tanggal_kunjungan' => ['required', 'date_format:Y-m-d'],
            'slot_mulai' => ['required', 'date_format:H:i:s'],
            'keluhan' => ['nullable', 'string'],
            'lampiran_keluhan' => ['nullable', 'array'],
            'lampiran_keluhan.*.nama' => ['required', 'string', 'max:150'],
            'lampiran_keluhan.*.url' => ['required', 'url', 'max:2048'],
            'pasien_id' => ['prohibited'],
            'anggota_keluarga_id' => ['prohibited'],
            'is_rujukan' => ['nullable', 'boolean'],
            'is_konsultasi_lanjutan' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Indonesian labels, per the project convention (`attributes()`, never
     * `messages()`).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'dokter_id' => 'dokter',
            'jadwal_id' => 'jadwal',
            'tipe_layanan' => 'tipe layanan',
            'tanggal_kunjungan' => 'tanggal kunjungan',
            'slot_mulai' => 'jam mulai',
            'keluhan' => 'keluhan',
            'lampiran_keluhan' => 'lampiran keluhan',
            'pasien_id' => 'pasien',
            'anggota_keluarga_id' => 'anggota keluarga',
            'is_rujukan' => 'rujukan',
            'is_konsultasi_lanjutan' => 'konsultasi lanjutan',
        ];
    }
}
