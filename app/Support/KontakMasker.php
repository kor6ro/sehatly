<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one masking rule for a delivery destination, shared by every sender.
 *
 * `LogOtpSender` masked a phone number (first three, last two) and an email (first
 * character plus domain) inline. F-005 adds a second sender that logs the same
 * masked destination, and two copies of a masking rule is two places for the next
 * edit to get wrong - which in this project means a phone number or an address in
 * a log line, which is what the mask exists to prevent. The rule therefore lives
 * here, and both senders call it.
 *
 * This is presentation, not security: it is not reversible and it is not the
 * protection. The protection is that the destination is never written raw.
 */
final class KontakMasker
{
    public static function mask(string $tujuan): string
    {
        if (str_contains($tujuan, '@')) {
            [$local, $domain] = explode('@', $tujuan, 2);

            return $local[0].'***@'.$domain;
        }

        $length = strlen($tujuan);

        if ($length <= 5) {
            return str_repeat('*', $length);
        }

        return substr($tujuan, 0, 3).str_repeat('*', $length - 5).substr($tujuan, -2);
    }
}
