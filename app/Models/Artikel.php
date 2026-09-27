<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `artikel` table.
 *
 * Source: telemedicine_test.sql:1074.
 *
 * @property int|null $id
 * @property int|null $kategori_id
 * @property int|null $penulis_user_id
 * @property int|null $reviewer_user_id
 * @property string|null $judul
 * @property string|null $slug
 * @property string|null $ringkasan
 * @property string|null $konten
 * @property string|null $cover_url
 * @property string|null $status
 * @property int|null $jumlah_view
 * @property Carbon|null $published_at
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read ArtikelKategori $kategori
 * @property-read User $penulisUser
 */
class Artikel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'artikel';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return BelongsTo<ArtikelKategori, $this>
     */
    public function kategori(): BelongsTo
    {
        return $this->belongsTo(ArtikelKategori::class, 'kategori_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function penulisUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'penulis_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'konten' => 'string',
            'status' => 'string',
            'published_at' => 'datetime',
        ];
    }
}
