<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pembayaran` table.
 *
 * Source: telemedicine_test.sql:958.
 *
 * @property int|null $id
 * @property int|null $invoice_id
 * @property int|null $metode_id
 * @property string|null $jumlah
 * @property string|null $nomor_referensi
 * @property string|null $va_number
 * @property string|null $gateway
 * @property string|null $status
 * @property Carbon|null $dibayar_at
 * @property array|null $webhook_payload
 * @property Carbon|null $dibuat_at
 * @property-read Invoice $invoice
 * @property-read MasterMetodePembayaran $metode
 * @property-read Collection<int, Refund> $refund
 */
class Pembayaran extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pembayaran';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `pembayaran` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * @return BelongsTo<MasterMetodePembayaran, $this>
     */
    public function metode(): BelongsTo
    {
        return $this->belongsTo(MasterMetodePembayaran::class, 'metode_id');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refund(): HasMany
    {
        return $this->hasMany(Refund::class, 'pembayaran_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:2',
            'gateway' => 'string',
            'status' => 'string',
            'dibayar_at' => 'datetime',
            'webhook_payload' => 'array',
        ];
    }
}
