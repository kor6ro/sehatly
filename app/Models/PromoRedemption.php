<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `promo_redemption` table.
 *
 * Source: telemedicine_test.sql:1000.
 *
 * @property int|null $id
 * @property int|null $promo_id
 * @property int|null $pasien_id
 * @property int|null $invoice_id
 * @property string|null $nilai_diskon
 * @property Carbon|null $dibuat_at
 * @property-read MasterPromo $promo
 * @property-read Pasien $pasien
 * @property-read Invoice $invoice
 */
class PromoRedemption extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'promo_redemption';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `promo_redemption` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<MasterPromo, $this>
     */
    public function promo(): BelongsTo
    {
        return $this->belongsTo(MasterPromo::class, 'promo_id');
    }

    /**
     * @return BelongsTo<Pasien, $this>
     */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nilai_diskon' => 'decimal:2',
        ];
    }
}
