<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

/**
 * Validates `PUT /api/v1/pasien/anggota-keluarga/{id}`.
 *
 * A **partial** update: every field is `sometimes`, so only the keys present in the body
 * are written and an absent key leaves the stored value alone. The reasoning is on
 * {@see AnggotaKeluargaRequest::isCreate()}.
 *
 * ## `{id}` is a path segment, not a body field, and it is not validated here
 *
 * `pasien_anggota_keluarga.id` is a `BIGINT UNSIGNED AUTO_INCREMENT` primary key, so the
 * route declares it as `{id}` with a numeric constraint. Nothing in the body can change
 * it and nothing in the body can change which row is addressed - the row is resolved by
 * `PasienRecordAccess::anggotaKeluargaOrFail()`, which scopes by the caller's own
 * `pasien_id` and therefore answers 404 for a row that is not the caller's.
 *
 * That is why this request has no `id` rule: the id never reaches the validated data, and
 * adding one would be a second, weaker statement of a check the query already made.
 */
class UpdateAnggotaKeluargaRequest extends AnggotaKeluargaRequest
{
    /**
     * Every field is optional on update; see the class docblock.
     */
    protected function isCreate(): bool
    {
        return false;
    }
}
