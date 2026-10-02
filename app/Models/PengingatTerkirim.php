<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pengingat_terkirim` table.
 *
 * Source: `telemedicine_test.sql:1419` (F11 section `[18]`, appended 2026-10-03).
 *
 * The dispatch ledger. `uq_pengingat_terkirim (pengingat_id, tanggal, waktu)`
 * is what makes the scheduler idempotent: the row is created FIRST, and a
 * second run for the same three-part key finds it and does nothing. The three
 * columns are `NOT NULL` by design - MySQL permits unlimited `NULL`s in a
 * UNIQUE index, which would have made the same key insertable twice through a
 * missing `tanggal`.
 *
 * @property int|null $id
 * @property int|null $pengingat_id
 * @property Carbon|null $tanggal
 * @property Carbon|null $waktu
 * @property int|null $notifikasi_id
 * @property Carbon|null $dibuat_at
 * @property-read Pengingat $pengingat
 * @property-read Notifikasi|null $notifikasi
 */
class PengingatTerkirim extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pengingat_terkirim';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `pengingat_terkirim` declares no `diubah_at` - the ledger is append-only -
     * and Eloquent writes this constant on every save, so it is nulled rather
     * than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Pengingat, $this>
     */
    public function pengingat(): BelongsTo
    {
        return $this->belongsTo(Pengingat::class, 'pengingat_id');
    }

    /**
     * @return BelongsTo<Notifikasi, $this>
     */
    public function notifikasi(): BelongsTo
    {
        return $this->belongsTo(Notifikasi::class, 'notifikasi_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
        ];
    }
}
