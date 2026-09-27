<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `master_kelurahan` table.
 *
 * Source: telemedicine_test.sql:80.
 *
 * @property int|null $id
 * @property int|null $kecamatan_id
 * @property string|null $kode
 * @property string|null $nama
 * @property-read MasterKecamatan $kecamatan
 */
class MasterKelurahan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_kelurahan';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_kelurahan` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<MasterKecamatan, $this>
     */
    public function kecamatan(): BelongsTo
    {
        return $this->belongsTo(MasterKecamatan::class, 'kecamatan_id');
    }
}
