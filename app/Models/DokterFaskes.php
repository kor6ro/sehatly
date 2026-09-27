<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `dokter_faskes` table.
 *
 * Source: telemedicine_test.sql:447.
 *
 * @property int|null $dokter_id
 * @property int|null $faskes_id
 * @property bool|null $is_utama
 * @property bool|null $status_aktif
 * @property-read Dokter $dokter
 * @property-read Faskes $faskes
 */
class DokterFaskes extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dokter_faskes';

    /**
     * The model's primary key.
     *
     * Eloquent has no composite-key support, so this records the key the DDL
     * declares instead of letting the model assume a single `id`. `find()` and
     * `getKey()` are meaningless on a pivot; read it through the `belongsToMany`
     * on the owning model.
     *
     * @var array<int, string>
     */
    protected $primaryKey = ['dokter_id', 'faskes_id'];

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     *
     * `dokter_faskes` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<Dokter, $this>
     */
    public function dokter(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_id');
    }

    /**
     * @return BelongsTo<Faskes, $this>
     */
    public function faskes(): BelongsTo
    {
        return $this->belongsTo(Faskes::class, 'faskes_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_utama' => 'boolean',
            'status_aktif' => 'boolean',
        ];
    }
}
