<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `konsultasi_chat` table.
 *
 * Source: telemedicine_test.sql:563.
 *
 * @property int|null $id
 * @property int|null $konsultasi_id
 * @property int|null $pengirim_user_id
 * @property string|null $pengirim_tipe
 * @property string|null $tipe_pesan
 * @property string|null $isi
 * @property string|null $file_url
 * @property string|null $file_nama
 * @property int|null $file_ukuran_kb
 * @property Carbon|null $dibaca_at
 * @property Carbon|null $terkirim_at
 * @property-read Konsultasi $konsultasi
 * @property-read User $pengirimUser
 */
class KonsultasiChat extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'konsultasi_chat';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'terkirim_at';

    /**
     * The name of the "updated at" column.
     *
     * `konsultasi_chat` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

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
    public function pengirimUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengirim_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pengirim_tipe' => 'string',
            'tipe_pesan' => 'string',
            'isi' => 'string',
            'dibaca_at' => 'datetime',
        ];
    }
}
