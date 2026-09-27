<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `akses_rekam_medis_log` table.
 *
 * Source: telemedicine_test.sql:1147.
 *
 * @property int|null $id
 * @property int|null $rekam_medis_id
 * @property int|null $pengakses_user_id
 * @property string|null $tujuan_akses
 * @property Carbon|null $dibuat_at
 * @property-read RekamMedis $rekamMedis
 * @property-read User $pengaksesUser
 */
class AksesRekamMedisLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'akses_rekam_medis_log';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `akses_rekam_medis_log` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<RekamMedis, $this>
     */
    public function rekamMedis(): BelongsTo
    {
        return $this->belongsTo(RekamMedis::class, 'rekam_medis_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pengaksesUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengakses_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tujuan_akses' => 'string',
        ];
    }
}
