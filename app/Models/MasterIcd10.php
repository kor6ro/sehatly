<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the `master_icd10` table.
 *
 * Source: telemedicine_test.sql:115.
 *
 * @property int|null $id
 * @property string|null $kode
 * @property string|null $deskripsi
 */
class MasterIcd10 extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_icd10';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_icd10` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;
}
