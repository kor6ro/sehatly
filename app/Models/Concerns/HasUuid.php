<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The `uuid` column contract for `users` and `rekam_medis`.
 *
 * Both tables declare `uuid CHAR(36) NOT NULL UNIQUE` with no default, so the value
 * has to be minted before the insert. `Str::uuid()` returns a hyphenated UUID v4,
 * which is exactly the 36 characters `CHAR(36)` holds.
 */
trait HasUuid
{
    /**
     * Mint the `uuid` column on insert, leaving an explicitly supplied value alone.
     */
    public static function bootHasUuid(): void
    {
        static::creating(function (self $model): void {
            $column = $model->uuidColumn();

            if ($model->getAttribute($column) === null) {
                $model->setAttribute($column, (string) Str::uuid());
            }
        });
    }

    /**
     * The `uuid` column's name, so a table that spells it differently changes one line.
     */
    protected function uuidColumn(): string
    {
        return 'uuid';
    }

    /**
     * Look a record up by its public `uuid` rather than by its auto-increment id.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereUuid(Builder $query, string $uuid): Builder
    {
        return $query->where($this->uuidColumn(), $uuid);
    }
}
