<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `konsultasi_baca` table.
 *
 * Source: telemedicine_test.sql:1354.
 *
 * One row per participant per consultation: when that account last read the
 * transcript. `uq_baca (konsultasi_id, user_id)` makes the pair unique, so the
 * write path is an idempotent upsert rather than an append.
 *
 * `last_read_at` is a plain `DATETIME` and therefore needs the explicit
 * `datetime` cast, while `dibuat_at`/`diubah_at` are the lifecycle pair and are
 * converted by the constants. `konsultasi_baca` declares no `dihapus_at`, so
 * this model does not soft-delete.
 *
 * @property int|null $id
 * @property int|null $konsultasi_id
 * @property int|null $user_id
 * @property Carbon|null $last_read_at
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Konsultasi $konsultasi
 * @property-read User $user
 */
#[Fillable(['konsultasi_id', 'user_id', 'last_read_at'])]
class KonsultasiBaca extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'konsultasi_baca';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return BelongsTo<Konsultasi, $this>
     */
    public function konsultasi(): BelongsTo
    {
        return $this->belongsTo(Konsultasi::class, 'konsultasi_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
        ];
    }
}
