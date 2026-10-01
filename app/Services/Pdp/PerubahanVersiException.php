<?php

declare(strict_types=1);

namespace App\Services\Pdp;

use App\Http\Controllers\Api\V1\PersetujuanPdpController;
use App\Support\Pdp\PdpDokumen;
use RuntimeException;

/**
 * "That is not the version of the document that is currently in force."
 *
 * ## The one refusal the write path has left
 *
 * F02's owner decision replaced the old version rule with a server-side version
 * authority: `GET /api/v1/pdp/dokumen` publishes the ACTIVE version of each of
 * the five documents, and `POST /api/v1/pdp/persetujuan` accepts only that
 * version. A `versi_dokumen` that is not the active one is refused here, and
 * this is the only 422 the version rule produces - the old
 * revoked-same-version collision and the old out-of-order write are gone,
 * because `persetujuan_pdp` is now an append-only ledger (see
 * {@see PdpConsentService}).
 *
 * ## Why 422 and not 409
 *
 * The owner allowed either. 422 is chosen because the project's failure
 * envelope already carries a field map for it (`errors.versi_dokumen`), the
 * existing client handling reads the FIRST message of that field and refetches
 * the document list, and `bootstrap/app.php` pins the 422 `message` to the
 * constant "The given data was invalid." A 409 would need a new envelope shape
 * and a new client branch for a refusal that is, at bottom, a field that failed
 * a rule: the value the caller sent is not the value the server accepts.
 *
 * ## Why two messages on one field rather than one concatenated string
 *
 * The first says the state of the world ("what you sent is not the active
 * version") and the second says the remedy ("the active version is vNN; refetch
 * and resend"). A client shows the first to the person and acts on the second.
 * Concatenating them would force a client to substring-match to tell them
 * apart, and would make the boundary test unable to assert either half by
 * position. `BookingController` and `PesananObatController` make the same
 * choice for the same reason.
 *
 * ## What a client does with it
 *
 * Do not retry - a retry is byte-identical and is refused identically. Call
 * `GET /api/v1/pdp/dokumen`, read the active `versi_dokumen` for that `jenis`,
 * and resend the decision with it. The second message names the active version
 * so a client that cannot refetch still has the value it needs.
 *
 * @see PdpConsentService for the rule that raises it
 * @see PdpDokumen for the catalogue the active version comes from
 * @see PersetujuanPdpController for the client-facing instructions
 */
class PerubahanVersiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    private function __construct(string $message, private readonly array $errors)
    {
        parent::__construct($message);
    }

    /**
     * The incoming version is not the active version of its `jenis`.
     */
    public static function tidakAktif(string $versi, string $aktif): self
    {
        return new self(
            'Versi dokumen ['.$versi.'] bukan versi aktif.',
            [
                'versi_dokumen' => [
                    'Versi dokumen yang dikirim bukan versi aktif.',
                    'Versi aktif saat ini adalah '.$aktif.'. Muat ulang GET /api/v1/pdp/dokumen lalu kirim ulang.',
                ],
            ],
        );
    }

    /**
     * The field-level detail, shaped for `ApiResponse::error()`.
     *
     * `ValidationException::withMessages()` is deliberately NOT used: that would
     * make the controller's catch block a guess about which class raised the
     * refusal, and a 422 whose `message` is the first field error would publish
     * untranslatable, data-dependent text (the reason `bootstrap/app.php` pins
     * that message to a constant).
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
