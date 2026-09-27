<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

/**
 * Validates `PUT /api/v1/pasien/alergi/{id}`.
 *
 * A **partial** update: every field is `sometimes`, so only the keys present in the body
 * are written. The reasoning is on {@see AlergiRequest::isCreate()} and
 * {@see AnggotaKeluargaRequest::isCreate()}.
 *
 * `{id}` is a route parameter and never reaches the validated data, so there is no `id`
 * rule here: the row is resolved by `PasienRecordAccess::alergiOrFail()`, which scopes by
 * the caller's own `pasien_id` and answers 404 for a row that is not the caller's.
 */
class UpdateAlergiRequest extends AlergiRequest
{
    /**
     * Nothing is mandatory on update; see the class docblock.
     */
    protected function isCreate(): bool
    {
        return false;
    }
}
