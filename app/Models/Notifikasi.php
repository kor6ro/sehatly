<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `notifikasi` table.
 *
 * Source: telemedicine_test.sql:1036.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $judul
 * @property string|null $isi
 * @property string|null $tipe
 * @property string|null $tautan
 * @property array|null $payload
 * @property Carbon|null $dibaca_at
 * @property Carbon|null $dibuat_at
 * @property-read User $user
 * @property-read Collection<int, PengingatTerkirim> $pengingatTerkirim
 */
class Notifikasi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'notifikasi';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `notifikasi` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The dispatch-ledger rows that point at this notification. An in-app row
     * produced by a reminder carries exactly one, and a RESTRICT stands in the
     * way of deleting a notification a `pengingat_terkirim` row still names.
     *
     * @return HasMany<PengingatTerkirim, $this>
     */
    public function pengingatTerkirim(): HasMany
    {
        return $this->hasMany(PengingatTerkirim::class, 'notifikasi_id');
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
            'payload' => 'array',
            'dibaca_at' => 'datetime',
        ];
    }
}
