<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the `master_icd9cm` table.
 *
 * Source: telemedicine_test.sql:122.
 *
 * @property int|null $id
 * @property string|null $kode
 * @property string|null $deskripsi
 */
class MasterIcd9cm extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_icd9cm';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_icd9cm` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;
}
