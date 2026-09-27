<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `master_promo` table.
 *
 * Source: telemedicine_test.sql:985.
 *
 * @property int|null $id
 * @property string|null $kode
 * @property string|null $nama
 * @property string|null $tipe_diskon
 * @property string|null $nilai
 * @property string|null $min_transaksi
 * @property string|null $maks_diskon
 * @property int|null $kuota_total
 * @property int|null $kuota_per_user
 * @property Carbon|null $mulai_at
 * @property Carbon|null $selesai_at
 * @property bool|null $status_aktif
 * @property-read Collection<int, PromoRedemption> $promoRedemption
 */
class MasterPromo extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_promo';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_promo` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<PromoRedemption, $this>
     */
    public function promoRedemption(): HasMany
    {
        return $this->hasMany(PromoRedemption::class, 'promo_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe_diskon' => 'string',
            'nilai' => 'decimal:2',
            'min_transaksi' => 'decimal:2',
            'maks_diskon' => 'decimal:2',
            'mulai_at' => 'datetime',
            'selesai_at' => 'datetime',
            'status_aktif' => 'boolean',
        ];
    }
}
