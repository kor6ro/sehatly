<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

/**
 * Validates `POST /api/v1/pasien/anggota-keluarga`.
 *
 * Every one of the seven columns is required, because `pasien_anggota_keluarga` declares
 * `nama_lengkap`, `jenis_kelamin` and `tanggal_lahir` as `NOT NULL` with no default
 * (`telemedicine_test.sql:264`, `:266`, `:267`) and `hubungan_id` is `NOT NULL` with a
 * real foreign key (`:262`). A create that omitted one of them would be MySQL 1364 - a
 * 500 - so the rule collects it here and answers 422 instead.
 *
 * The rest of the reasoning, including why `pasien_id` is absent from the rules and why
 * `nik` is `digits:16` rather than `max:16`, is on {@see AnggotaKeluargaRequest}.
 */
class StoreAnggotaKeluargaRequest extends AnggotaKeluargaRequest
{
    /**
     * Every field is required on create; see the class docblock.
     */
    protected function isCreate(): bool
    {
        return true;
    }
}
