<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `invoice` table.
 *
 * Source: telemedicine_test.sql:936.
 *
 * @property int|null $id
 * @property string|null $nomor_invoice
 * @property int|null $pasien_id
 * @property string|null $referensi_tipe
 * @property int|null $referensi_id
 * @property string|null $subtotal
 * @property string|null $diskon
 * @property string|null $biaya_admin
 * @property string|null $biaya_pengiriman
 * @property string|null $total
 * @property string|null $status
 * @property Carbon|null $jatuh_tempo
 * @property Carbon|null $lunas_at
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Pasien $pasien
 * @property-read Collection<int, Pembayaran> $pembayaran
 * @property-read Collection<int, PromoRedemption> $promoRedemption
 */
class Invoice extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'invoice';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return BelongsTo<Pasien, $this>
     */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /**
     * @return HasMany<Pembayaran, $this>
     */
    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class, 'invoice_id');
    }

    /**
     * @return HasMany<PromoRedemption, $this>
     */
    public function promoRedemption(): HasMany
    {
        return $this->hasMany(PromoRedemption::class, 'invoice_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'referensi_tipe' => 'string',
            'subtotal' => 'decimal:2',
            'diskon' => 'decimal:2',
            'biaya_admin' => 'decimal:2',
            'biaya_pengiriman' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => 'string',
            'jatuh_tempo' => 'datetime',
            'lunas_at' => 'datetime',
        ];
    }
}
