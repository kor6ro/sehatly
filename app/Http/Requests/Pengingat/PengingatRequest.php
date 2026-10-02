<?php

declare(strict_types=1);

namespace App\Http\Requests\Pengingat;

use App\Enums\PengingatJenis;
use App\Enums\PengingatStatus;
use App\Enums\ZonaWaktu;
use App\Models\Booking;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and updating a `pengingat` row.
 *
 * ## `waktu` is a NON-EMPTY list of `HH:MM`
 *
 * A reminder with no time never fires, so `min:1` is a real requirement rather
 * than a cosmetic one. The regex is strict: `9:00` is refused and `09:00` is
 * required, because the column stores the `HH:MM` form and the scheduler
 * compares it with `format('H:i')` - a single-digit hour would never match.
 * The list is SORTED and DEDUPED by `PengingatService`, not here: validation
 * decides what is legal, the service decides what is canonical.
 *
 * ## `jenis`, `zona_waktu`, `status` are ENUM-checked
 *
 * `Rule::enum()` is bound to the app's enum classes, which are themselves
 * asserted against the DDL by the enum tests, so a value that could only be a
 * typo is a 422 rather than a MySQL 1265 at insert.
 *
 * ## `booking_id` must be a booking the CALLER is a party to
 *
 * `Rule::exists()` proves the row exists; the `after` hook proves ownership -
 * the caller is the patient or the doctor of the booking - because "someone
 * else's appointment reminder" is a data leak a plain `exists` cannot see, and
 * the schema cannot either (a foreign key does not carry an owner).
 *
 * ## A drug reminder does not need `obat_id`
 *
 * `resep_item.aturan_pakai` is free text and F11 §7.11 forbids auto-parsing it,
 * so a manual reminder may be written by hand with no catalogue link. `obat_id`
 * is therefore nullable and, when present, must name a real catalogue row.
 */
abstract class PengingatRequest extends FormRequest
{
    /**
     * `HH:MM`, 24-hour, two digits each, minute 00-59.
     *
     * The scheduler compares the literal string, so this is the one accepted
     * spelling of a wall clock on this endpoint.
     */
    public const FORMAT_WAKTU = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    /**
     * The most times one reminder may carry, one per hour.
     */
    public const WAKTU_MAKS = 24;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The rules both writes share; `$membuat` flips the required fields and
     * makes `status` system-owned on create.
     *
     * @return array<string, mixed>
     */
    protected function aturan(bool $membuat): array
    {
        return [
            'jenis' => [$membuat ? 'required' : 'sometimes', Rule::enum(PengingatJenis::class)],
            'judul' => [$membuat ? 'required' : 'sometimes', 'string', 'max:200'],
            'keterangan' => ['sometimes', 'nullable', 'string', 'max:255'],
            'obat_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_obat', 'id')],
            'booking_id' => ['sometimes', 'nullable', 'integer', Rule::exists('booking', 'id')],
            'dosis' => ['sometimes', 'nullable', 'string', 'max:50'],
            'jumlah_per_hari' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'tanggal_mulai' => [$membuat ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'lama_hari' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'waktu' => [$membuat ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.self::WAKTU_MAKS],
            'waktu.*' => ['required', 'string', 'regex:'.self::FORMAT_WAKTU],
            'zona_waktu' => ['sometimes', Rule::enum(ZonaWaktu::class)],
            'status' => $membuat
                ? ['prohibited']
                : ['sometimes', Rule::enum(PengingatStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jenis.required' => 'Jenis pengingat wajib diisi.',
            'jenis.*' => 'Jenis pengingat harus obat atau janji temu.',
            'judul.required' => 'Judul pengingat wajib diisi.',
            'judul.max' => 'Judul pengingat maksimal 200 karakter.',
            'keterangan.max' => 'Keterangan maksimal 255 karakter.',
            'obat_id.integer' => 'Obat tidak valid.',
            'obat_id.exists' => 'Obat tidak ditemukan.',
            'booking_id.integer' => 'Booking tidak valid.',
            'booking_id.exists' => 'Booking tidak ditemukan.',
            'dosis.max' => 'Dosis maksimal 50 karakter.',
            'jumlah_per_hari.integer' => 'Jumlah per hari harus berupa angka.',
            'jumlah_per_hari.min' => 'Jumlah per hari minimal 1.',
            'jumlah_per_hari.max' => 'Jumlah per hari maksimal 24.',
            'tanggal_mulai.required' => 'Tanggal mulai wajib diisi.',
            'tanggal_mulai.date_format' => 'Tanggal mulai harus berformat YYYY-MM-DD.',
            'lama_hari.integer' => 'Lama hari harus berupa angka.',
            'lama_hari.min' => 'Lama hari minimal 1.',
            'lama_hari.max' => 'Lama hari maksimal 3650.',
            'waktu.required' => 'Waktu pengingat wajib diisi.',
            'waktu.array' => 'Waktu pengingat harus berupa daftar.',
            'waktu.min' => 'Minimal satu waktu pengingat.',
            'waktu.max' => 'Maksimal '.self::WAKTU_MAKS.' waktu pengingat.',
            'waktu.*.required' => 'Waktu pengingat tidak boleh kosong.',
            'waktu.*.regex' => 'Setiap waktu harus berformat HH:MM (00:00-23:59).',
            'zona_waktu.*' => 'Zona waktu tidak dikenal.',
            'status.*' => 'Status pengingat tidak dikenal.',
            'status.prohibited' => 'Status pengingat tidak dapat diisi saat membuat.',
        ];
    }

    /**
     * Ownership, after the field rules: a `booking_id` that exists but belongs
     * to somebody else is exactly as unacceptable as one that does not exist,
     * and gets a field-level 422 rather than a 403 that would confirm the row.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bookingId = $this->input('booking_id');

            if ($bookingId === null || $bookingId === '' || $validator->errors()->has('booking_id')) {
                return;
            }

            $user = $this->user();

            if ($user === null) {
                return;
            }

            $boleh = Booking::query()
                ->whereKey($bookingId)
                ->where(function ($query) use ($user): void {
                    $query->whereHas('pasien', fn ($pasien) => $pasien->where('user_id', (int) $user->getKey()))
                        ->orWhereHas('dokter', fn ($dokter) => $dokter->where('user_id', (int) $user->getKey()));
                })
                ->exists();

            if (! $boleh) {
                $validator->errors()->add('booking_id', 'Booking tidak ditemukan untuk akun Anda.');
            }
        });
    }
}
