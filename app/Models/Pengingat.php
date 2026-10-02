<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pengingat` table.
 *
 * Source: `telemedicine_test.sql:1395` (F11 section `[18]`, appended 2026-10-03).
 *
 * A user-owned scheduled reminder. `waktu` is a JSON list of `"HH:MM"`
 * wall-clock strings interpreted in `zona_waktu`; the model casts it to an
 * array, and the `PengingatService` is what normalises it (sorted, deduped) on
 * write.
 *
 * ## What the schema does not enforce
 *
 * `jenis` is not coupled to `obat_id`/`booking_id`/`dosis`/`jumlah_per_hari` by
 * any constraint, and `booking_id` ownership is not a foreign-key property -
 * both are `PengingatRequest` rules. `Pengingat` therefore has no relation on a
 * bare column, and every relation below stands on a real foreign key from the
 * DDL: `user_id` (cascade), `obat_id` and `booking_id` (both RESTRICT).
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $jenis
 * @property string|null $judul
 * @property string|null $keterangan
 * @property int|null $obat_id
 * @property int|null $booking_id
 * @property string|null $dosis
 * @property int|null $jumlah_per_hari
 * @property Carbon|null $tanggal_mulai
 * @property int|null $lama_hari
 * @property array|null $waktu
 * @property string|null $zona_waktu
 * @property string|null $status
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read User $user
 * @property-read MasterObat|null $obat
 * @property-read Booking|null $booking
 * @property-read Collection<int, PengingatTerkirim> $terkirim
 */
class Pengingat extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pengingat';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The optional catalogue drug. Nullable: a manual reminder may name a drug
     * the catalogue does not carry.
     *
     * @return BelongsTo<MasterObat, $this>
     */
    public function obat(): BelongsTo
    {
        return $this->belongsTo(MasterObat::class, 'obat_id');
    }

    /**
     * The optional appointment. Ownership (the caller must be the patient or
     * the doctor of the booking) is a request rule, not a schema constraint.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * @return HasMany<PengingatTerkirim, $this>
     */
    public function terkirim(): HasMany
    {
        return $this->hasMany(PengingatTerkirim::class, 'pengingat_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis' => 'string',
            'waktu' => 'array',
            'tanggal_mulai' => 'date',
            'status' => 'string',
        ];
    }
}
