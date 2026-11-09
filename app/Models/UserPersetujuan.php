<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `user_persetujuan` table.
 *
 * Source: telemedicine_test.sql:1438.
 *
 * Append-only ledger of admin decisions on `menunggu_persetujuan` accounts.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $keputusan   disetujui | ditolak
 * @property string|null $alasan
 * @property int         $ditinjau_oleh
 * @property Carbon      $dibuat_at
 * @property-read User   $user
 * @property-read User   $peninjau
 */
class UserPersetujuan extends Model
{
    protected $table = 'user_persetujuan';

    public const CREATED_AT = 'dibuat_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'keputusan',
        'alasan',
        'ditinjau_oleh',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function peninjau(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditinjau_oleh');
    }

    protected function casts(): array
    {
        return [
            'keputusan' => 'string',
        ];
    }
}
