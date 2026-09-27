<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `lab_paket_item` table.
 *
 * Source: telemedicine_test.sql:868.
 *
 * @property int|null $paket_id
 * @property int|null $tindakan_id
 * @property-read MasterLabPaket $paket
 * @property-read MasterLabTindakan $tindakan
 */
class LabPaketItem extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'lab_paket_item';

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
    protected $primaryKey = ['paket_id', 'tindakan_id'];

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
     * `lab_paket_item` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<MasterLabPaket, $this>
     */
    public function paket(): BelongsTo
    {
        return $this->belongsTo(MasterLabPaket::class, 'paket_id');
    }

    /**
     * @return BelongsTo<MasterLabTindakan, $this>
     */
    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(MasterLabTindakan::class, 'tindakan_id');
    }
}
