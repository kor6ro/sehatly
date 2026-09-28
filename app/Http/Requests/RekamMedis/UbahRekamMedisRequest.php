<?php

declare(strict_types=1);

namespace App\Http\Requests\RekamMedis;

/**
 * `PUT /api/v1/rekam-medis/{id}` - edit a DRAFT in place.
 *
 * Every key is `nullable` and none is `required`, because this is a PARTIAL update: a
 * doctor correcting one word of a long SOAP note should not have to resend the note,
 * and a key that is absent keeps whatever it held. That is the behaviour
 * `RekamMedisService::tulisIsi()` implements with `array_key_exists` - and it is only
 * safe because an UNKNOWN key is refused, which is the half
 * `KonsultasiService::tulisSoap()` was missing.
 *
 * An empty body is accepted and is a no-op that still logs: the write opened the
 * record to decide whether it was a draft, so the access log has a row for it, and
 * answering 422 for a request that changed nothing would be inventing a rule the
 * schema does not have.
 *
 * `tanggal_periksa` is offered here and only here among the write paths. It is part
 * of the chain group, so moving it on a signed record would file the revision in a
 * different chain from the one it supersedes - which is why
 * `AmandemenRekamMedisRequest` refuses it outright.
 */
class UbahRekamMedisRequest extends RekamMedisRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            self::kolomIsi(),
            [
                'tanggal_periksa' => ['nullable', 'date_format:Y-m-d H:i:s'],
            ],
            self::kolomMilikSistem(),
        );
    }
}
