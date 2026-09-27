<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `artikel_kategori` table.
 *
 * Source: telemedicine_test.sql:1068.
 *
 * @property int|null $id
 * @property string|null $nama
 * @property string|null $slug
 * @property-read Collection<int, Artikel> $artikel
 */
class ArtikelKategori extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'artikel_kategori';

    /**
     * Indicates if the model should be timestamped.
     *
     * `artikel_kategori` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<Artikel, $this>
     */
    public function artikel(): HasMany
    {
        return $this->hasMany(Artikel::class, 'kategori_id');
    }
}
