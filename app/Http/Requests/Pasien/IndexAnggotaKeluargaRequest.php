<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

/**
 * Validates the query string of `GET /api/v1/pasien/anggota-keluarga`.
 *
 * The `?page=` / `?per_page=` contract, its 100 cap and the reason both fields are
 * validated rather than left to the paginator are documented on
 * {@see IndexPasienRequest}. This class exists so each list endpoint has a request class
 * of its own - a `FormRequest` per input, as the API contract requires - and so a rule
 * added to one list in a later todo is added to that list only.
 */
class IndexAnggotaKeluargaRequest extends IndexPasienRequest
{
    // Intentionally empty: the pagination contract is the whole of this input.
}
