<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_penjamin` table.
 *
 * Source: telemedicine_test.sql:333.
 *
 * @property int|null $id
 * @property string|null $nama
 * @property string|null $tipe
 * @property bool|null $status_aktif
 * @property-read Collection<int, PasienPenjamin> $pasienPenjamin
 */
class MasterPenjamin extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_penjamin';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_penjamin` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<PasienPenjamin, $this>
     */
    public function pasienPenjamin(): HasMany
    {
        return $this->hasMany(PasienPenjamin::class, 'penjamin_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe' => 'string',
            'status_aktif' => 'boolean',
        ];
    }
}
