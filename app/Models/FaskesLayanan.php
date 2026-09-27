<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `faskes_layanan` table.
 *
 * Source: telemedicine_test.sql:388.
 *
 * @property int|null $id
 * @property int|null $faskes_id
 * @property string|null $nama_layanan
 * @property string|null $deskripsi
 * @property string|null $harga
 * @property bool|null $status_aktif
 * @property-read Faskes $faskes
 */
class FaskesLayanan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'faskes_layanan';

    /**
     * Indicates if the model should be timestamped.
     *
     * `faskes_layanan` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

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
            'deskripsi' => 'string',
            'harga' => 'decimal:2',
            'status_aktif' => 'boolean',
        ];
    }
}
