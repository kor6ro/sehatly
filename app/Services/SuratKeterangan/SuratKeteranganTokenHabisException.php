<?php

declare(strict_types=1);

namespace App\Services\SuratKeterangan;

use RuntimeException;

/**
 * The retry budget for a letter's two collision-shaped identifiers ran out.
 *
 * There is no `App\Exceptions` namespace in this project; domain exceptions live beside
 * their service (`App\Services\Booking\SlotTakenException` is the template), so this
 * one lives beside `SuratKeteranganService`.
 *
 * ## Why it exists rather than the service throwing a ValidationException
 *
 * Two reasons, and the first is the load-bearing one:
 *
 * 1. **It cannot happen through the API in any realistic volume, and pretending
 *    otherwise would put an unreachable branch in the envelope contract.** A v4 UUID
 *    against a table of letters that a telemedicine platform issues in the hundreds is a
 *    collision probability around 2^-122 per write. The branch exists because a
 *    verification token that two documents share is a security defect - scanning either
 *    QR resolves both letters - and the plan requires the write to FAIL rather than
 *    publish a duplicate. It is a guard against a broken or replaced generator, not a
 *    condition a client should expect to handle.
 * 2. A dedicated type makes the ceiling LOUD. The test forces a collision by
 *    substituting a generator that always returns a taken token, and asserts the
 *    generator was called exactly `SuratKeteranganService::PERCOBAAN_TOKEN_MAKS` times.
 *    A bare `RuntimeException` rendering as a sanitised 500 would fail loudly, but it
 *    would answer the WRONG status: a spent budget is a request the caller can retry
 *    after re-issuing, and 422 with a field-keyed body is what a client already knows
 *    how to render.
 *
 * ## Why BOTH fields are reported
 *
 * The loop retries on two different collision sources and the caller cannot tell which
 * one spent the budget: the application-level `qr_token` check and the database's
 * `nomor_surat` unique key. Reporting both means a client is never told the token
 * collided when it was the number, and a 422 carrying one message per field is the
 * shape `ApiResponse::error()` already publishes.
 */
final class SuratKeteranganTokenHabisException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    private function __construct(
        string $message,
        private readonly array $errors,
    ) {
        parent::__construct($message);
    }

    /**
     * The field-keyed payload the 422 envelope carries.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Every attempt in {@see SuratKeteranganService::PERCOBAAN_TOKEN_MAKS} drew a
     * value that is already stored.
     *
     * Nothing is written when this is thrown, because the retry loop runs inside the
     * transaction: no `surat_keterangan` row, no `rujukan` row, and no half-created
     * referral hanging off a letter that does not exist.
     */
    public static function penuh(): self
    {
        $token = 'Token QR tidak dapat dibuat. Silakan coba lagi.';
        $nomor = 'Nomor surat tidak dapat dibuat. Silakan coba lagi.';

        return new self(
            'Nomor surat dan token QR tidak dapat dibuat setelah beberapa percobaan.',
            ['qr_token' => [$token], 'nomor_surat' => [$nomor]],
        );
    }
}
