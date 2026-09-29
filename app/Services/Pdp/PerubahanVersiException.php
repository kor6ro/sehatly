<?php

declare(strict_types=1);

namespace App\Services\Pdp;

use RuntimeException;

/**
 * "You cannot change your mind about THAT version of THAT document."
 *
 * ## Why this exception exists at all
 *
 * `telemedicine_test.sql:1144` is `UNIQUE KEY uq_consent (user_id, jenis,
 * versi_dokumen)` and `disetujui TINYINT(1) NOT NULL` is `:1140`. Together those
 * two mean a decision about a given document version is **immutable and
 * unrevisable**: there is nowhere to record that the person changed their mind
 * about `v1` while `v1` is the version on record, and no nullable twin of
 * `disetujui` to null out.
 *
 * A client asking to do that is not making a mistake the API can correct, so
 * this is a domain refusal with a 422, not a `LogicException` and not a 500. It
 * is caught by the controller and turned into the standard failure envelope with
 * `errors.versi_dokumen` carrying **two** messages, because the caller needs both
 * halves of the answer: what happened, and what to do instead.
 *
 * ## Why two messages on one field rather than one concatenated string
 *
 * The first says the state of the world ("already recorded, cannot be changed")
 * and the second says the remedy ("send a higher version"). A client shows the
 * first to the person and acts on the second. Concatenating them into one string
 * would force a client to substring-match to tell them apart, and would make the
 * boundary test unable to assert either half by position. `BookingController` and
 * `PesananObatController` make the same choice for the same reason.
 *
 * ## The two shapes
 *
 * - {@see tabrakan()} - the version is ALREADY recorded and the incoming answer
 *   DIFFERS. This is the revoked-same-version collision, and it is the case this
 *   todo's acceptance criterion is about.
 * - {@see lebihLama()} - the version is already recorded AND something higher is
 *   too, so the incoming row could never be the effective one. Accepting it would
 *   be a silent no-op reported as a success, which is the same defect as a
 *   resurrected consent wearing a 201.
 *
 * ## What a client does with it
 *
 * Do not retry - a retry is byte-identical and is refused identically. Read
 * `GET /api/v1/pdp/persetujuan` and use the `efektif` value on the entry for
 * that `jenis`; see the docblock of
 * {@see \App\Http\Controllers\Api\V1\PersetujuanPdpController}, which is the
 * one place a client is told, and assert it here rather than repeating it.
 *
 * @see \App\Services\Pdp\PdpConsentService for the rule that raises it
 * @see \App\Services\Pdp\PdpConsent for the version rule it protects
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
     * The revoked-same-version collision: this version is on record and the
     * incoming answer contradicts it.
     */
    public static function tabrakan(string $versi, bool $diminta): self
    {
        return new self(
            'Persetujuan untuk versi dokumen ['.$versi.'] sudah tercatat dengan jawaban yang berbeda.',
            [
                'versi_dokumen' => [
                    'Persetujuan untuk versi dokumen ini sudah tercatat dan tidak dapat diubah.',
                    'Tarik persetujuan dengan mengirim versi_dokumen yang lebih tinggi.',
                ],
            ],
        );
    }

    /**
     * An out-of-order write: a HIGHER version is already on record, so this row
     * could never be read by the version rule.
     */
    public static function lebihLama(string $versi): self
    {
        return new self(
            'Versi dokumen ['.$versi.'] lebih lama dari versi yang sudah tercatat.',
            [
                'versi_dokumen' => [
                    'Versi dokumen ini lebih lama dari versi yang sudah tercatat.',
                    'Kirim versi_dokumen yang lebih tinggi agar persetujuan yang baru berlaku.',
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
