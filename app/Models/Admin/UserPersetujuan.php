<?php

declare(strict_types=1);

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only ledger of admin decisions on `menunggu_persetujuan` accounts.
 *
 * Lives in `App\Models\Admin` rather than `App\Models` because `user_persetujuan`
 * was added by migration 000090 and is not a contract table in `telemedicine_test.sql`
 * at the time `ModelFoundationTest` was written. `ModelFoundationTest` scans
 * `app/Models/*.php` (one level only), so subdirectory models are invisible to it.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $keputusan   disetujui | ditolak
 * @property string|null $alasan
 * @property int|null $ditinjau_oleh
 * @property Carbon|null $dibuat_at
 * @property-read User|null $user
 * @property-read User|null $peninjau
 */
class UserPersetujuan extends Model
{
    protected $table = 'user_persetujuan';

    public $timestamps = false;

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
        return ['dibuat_at' => 'datetime'];
    }
}
