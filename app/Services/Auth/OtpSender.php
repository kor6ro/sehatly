<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Delivers a one-time code to the account holder.
 *
 * ## Why this is an interface and not a call to a provider
 *
 * The plan forbids adding a real SMS provider: the schema has no provider table and
 * no credentials column, and the spec's guardrail list says "NEVER add an SMS
 * provider". So the delivery channel is an interface with one shipped
 * implementation, {@see LogOtpSender}, and the boundary exists so that swapping in a
 * real gateway later is a container binding rather than an edit to
 * {@see OtpService}.
 *
 * ## The contract is deliberately dumb
 *
 * `send()` receives everything it needs and returns nothing. It is not told whether
 * the environment is local, because that decision belongs to the caller -- see
 * {@see OtpService::issue()}, which is the single place that decides whether the
 * plaintext code may appear in an API response. A sender that also decided that
 * would mean two places could leak the code, and a review could not enumerate them.
 *
 * The recipient is passed as the destination string rather than as a model, so an
 * implementation never has to load a row to send a message: `no_telepon` for a
 * `tujuan` of `verifikasi_telepon` or `login`, and `email` for `verifikasi_email`.
 */
interface OtpSender
{
    /**
     * Deliver `$kode` to `$tujuan`, which is a phone number or an email address.
     *
     * `$purpose` is the `user_otp.tujuan` ENUM value the code was minted for, so an
     * implementation can label a log line or a template without re-deriving it.
     *
     * Implementations must not throw for a delivery failure the caller can still
     * recover from; they should log it instead. An OTP that was minted but not
     * delivered stays visible as an unused `user_otp` row, and the account holder can
     * always request a fresh code, which supersedes it.
     */
    public function send(string $tujuan, string $kode, string $purpose): void;
}
