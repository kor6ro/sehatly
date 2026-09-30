<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Support\NikCipher;
use RuntimeException;

/**
 * Raised when a stored value is not a payload this cipher can read.
 *
 * ## Every failure is a refusal, never a guess
 *
 * {@see NikCipher} decrypts into {@see mask} on a hot path, so the
 * tempting behaviour for a bad value is to answer `null` and publish a blank
 * NIK. That is the worst outcome available: a masked identity silently becomes
 * four bullets and a client renders it as a real person with no identifier. A
 * refusal is visible; a blank is not.
 *
 * The four causes, all of which are distinguishable from the payload alone:
 *
 *  - not base64, or base64 of the wrong length (truncation, a wrong column);
 *  - the four-byte MAGIC is not `NKC1`, so the column holds something else -
 *    including a LEGACY PLAINTEXT NIK, which is exactly what a half-finished
 *    migration leaves behind and exactly what must not be decrypted;
 *  - the two-byte key id in the header matches none of the configured keys, so
 *    the row was written under a key that has been rotated out;
 *  - the PKCS#7 padding check failed, which is what a tampered or truncated
 *    ciphertext produces.
 */
final class NikDecryptionException extends RuntimeException
{
    public static function notBase64(): self
    {
        return new self(
            'Stored NIK payload is not strict base64, so it is truncated, corrupted, or not a payload at all.'
        );
    }

    public static function tooShort(int $length): self
    {
        return new self(sprintf(
            'Stored NIK payload is %d bytes, shorter than the %d-byte minimum a payload can be.'
            .' A ciphertext that was truncated on write cannot be decrypted.',
            $length,
            NikCipher::PAYLOAD_HEADER_LENGTH + NikCipher::IV_LENGTH,
        ));
    }

    public static function wrongMagic(): self
    {
        return new self(
            'Stored value does not begin with the NKC1 payload marker, so this column holds something other'
            .' than a NIK ciphertext - most likely a legacy plaintext NIK written before the cipher column'
            .' existed. Read it as plaintext and mask it; do not try to decrypt it.'
        );
    }

    public static function unknownKey(): self
    {
        return new self(
            'Stored NIK payload names a key that is not configured. Either NIK_CIPHER_KEY has been rotated'
            .' without the previous key being kept in NIK_CIPHER_PREVIOUS_KEYS, or the row was written by a'
            .' different deployment. Restore the key, or re-encrypt the row.'
        );
    }

    public static function corrupt(): self
    {
        return new self(
            'Stored NIK payload failed to decrypt, which is what a tampered or truncated ciphertext produces.'
            .' The row is not readable and must not be reported as a blank identifier.'
        );
    }
}
