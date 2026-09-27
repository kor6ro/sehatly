<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_metode_pembayaran` table.
 *
 * Source: telemedicine_test.sql:925.
 *
 * @property int|null $id
 * @property string|null $kode
 * @property string|null $nama
 * @property string|null $tipe
 * @property string|null $penyedia
 * @property string|null $biaya_admin_flat
 * @property string|null $biaya_admin_persen
 * @property bool|null $status_aktif
 * @property-read Collection<int, Pembayaran> $pembayaran
 */
class MasterMetodePembayaran extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_metode_pembayaran';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_metode_pembayaran` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<Pembayaran, $this>
     */
    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class, 'metode_id');
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
            'biaya_admin_flat' => 'decimal:2',
            'biaya_admin_persen' => 'decimal:2',
            'status_aktif' => 'boolean',
        ];
    }
}
