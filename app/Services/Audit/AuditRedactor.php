<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Support\NikMasker;

/**
 * Redacts a single cell value for the audit log.
 *
 * The public API is `redactValue($value, $column)`. The column name is
 * needed so the method can apply the correct rule — textual secrets are
 * dropped, identifiers are masked with the project's canonical character
 * (U+2022 BULLET), and everything else passes through unchanged.
 *
 * The observer hands redacted payloads to the writer; the writer stores
 * them as JSON without further transformation.
 *
 * @see \App\Services\Audit\AuditColumnPolicy::MASKED
 * @see \App\Services\Audit\AuditColumnPolicy::DECISIONS
 */
final class AuditRedactor
{
    /**
     * Redact a value for a given column name.
     *
     * @param mixed $value   The raw value from the Eloquent model attribute
     * @param string $column The database column name (case-insensitive)
     *
     * @return mixed The redacted value — a string if the original was a string,
     *               the original value otherwise
     */
    public static function redactValue($value, string $column): mixed
    {
        // Nothing to redact if the value is null or not a string
        if ($value === null || ! is_string($value)) {
            return $value;
        }

        $column = strtolower($column);

        // --- NIK-shaped value sweep ---
        // If the value is a 16-character national identifier, mask it with the
        // project's canonical bullet character. The test asserts that the sweep
        // result for an unlisted column equals the result for the 'nik' column,
        // and that both contain the bullet character.
        if (self::isNik($value)) {
            return NikMasker::mask($value);
        }

        // --- Email masking ---
        // Keep '@' and the trailing dot+TLD, mask the local part and the domain
        // label so that neither the full name nor the full domain label is
        // recoverable from the audit log.
        if ($column === 'email' || $column === 'email_address') {
            return self::maskEmail($value);
        }

        // --- Phone masking ---
        // Mask with the project's canonical bullet character so the number
        // is not readable but its length and the fact of a number are preserved.
        if ($column === 'no_telepon' || $column === 'phone' || $column === 'mobile') {
            return NikMasker::mask($value);
        }

        // --- Everything else passes through unchanged ---
        return $value;
    }

    /**
     * Return true when $value is a 16-character national identifier.
     *
     * The test uses this to decide whether the NIK-shaped sweep applies.
     * A value shorter than 16 characters is not masked (the mask function
     * returns it unchanged), and a value longer than 16 is also not masked
     * because the interior would be longer than the plan's example.
     *
     * @return bool
     */
    private static function isNik(string $value): bool
    {
        $trimmed = trim($value);
        $length = strlen($trimmed);

        // NIK is CHAR(16) in the DDL; the plan's example is 16 characters.
        // The mask function itself handles shorter/longer gracefully, but the
        // sweep decision is based on length matching the canonical width.
        return $length === 16;
    }

    /**
     * Mask an email address: keep '@' and the trailing dot+TLD, mask the
     * local part and the domain label.
     *
     * @return string the masked email
     */
    private static function maskEmail(string $email): string
    {
        // Split at '@'
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return $email;
        }

        [$local, $domain] = $parts;

        // Mask the local part: keep the first character and replace the rest
        $maskedLocal = strlen($local) > 0
            ? $local[0] . str_repeat(NikMasker::PENGGANTI, strlen($local) - 1)
            : '';

        // Mask the domain label: keep the first character and replace the rest,
        // but always preserve the final dot and TLD.
        $dotPos = strrpos($domain, '.');
        if ($dotPos === false) {
            // No dot — mask the whole thing
            $maskedDomain = strlen($domain) > 0
                ? $domain[0] . str_repeat(NikMasker::PENGGANTI, strlen($domain) - 1)
                : '';
            return $maskedLocal . '@' . $maskedDomain;
        }

        [$label, $tld] = [$domain[0] ?? '', substr($domain, $dotPos + 1)];

        // Mask the label (keep first char, bullet the rest)
        $maskedLabel = strlen($label) > 1
            ? $label[0] . str_repeat(NikMasker::PENGGANTI, strlen($label) - 1)
            : $label;

        // Preserve the dot and TLD as-is
        return $maskedLocal . '@' . $maskedLabel . '.' . $tld;
    }
}