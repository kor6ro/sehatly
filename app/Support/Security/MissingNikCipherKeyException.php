<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Support\NikCipher;
use RuntimeException;

/**
 * Raised when `NIK_CIPHER_KEY` is absent or is not exactly 32 base64-encoded bytes.
 *
 * ## Why this is an exception and not a fallback
 *
 * `openssl_encrypt` does NOT validate its key length. Handed a 5-character string
 * it zero-pads to the cipher's block requirement and returns a perfectly formed
 * ciphertext, so an application with a broken key looks healthy: writes succeed,
 * reads succeed, and every row is encrypted under a key nobody recorded. A
 * missing key is therefore the worst possible thing to handle quietly, which is
 * why every entry point on {@see NikCipher} refuses instead.
 *
 * The three shapes that are refused rather than absorbed:
 *
 *  - the variable is absent, empty, or whitespace;
 *  - it is present but is not base64;
 *  - it is base64 of something other than exactly 32 bytes.
 *
 * The message names the variable and the required shape, because the operator
 * who hits this has to be told how to fix it without reading this class.
 */
final class MissingNikCipherKeyException extends RuntimeException
{
    public static function forVariable(string $reason): self
    {
        return new self(sprintf(
            'NIK_CIPHER_KEY %s. It must be 32 random bytes encoded as base64, optionally with a'
            .' "base64:" prefix, e.g. the output of: php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"'
            .' Generate a different value per deployment - the key encrypts every patient NIK and a'
            .' key held in a tracked file is not a secret.',
            $reason,
        ));
    }
}
