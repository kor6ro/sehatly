<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `audit_log` table.
 *
 * Source: telemedicine_test.sql:1118.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $aksi
 * @property string|null $tabel_target
 * @property string|null $record_id
 * @property array|null $data_lama
 * @property array|null $data_baru
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $endpoint
 * @property Carbon|null $dibuat_at
 */
class AuditLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_log';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `audit_log` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aksi' => 'string',
            'data_lama' => 'array',
            'data_baru' => 'array',
        ];
    }
}
