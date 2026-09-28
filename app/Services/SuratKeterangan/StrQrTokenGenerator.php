<?php

declare(strict_types=1);

namespace App\Services\SuratKeterangan;

use Illuminate\Support\Str;

/**
 * The production {@see QrTokenGenerator}: a v4 UUID string.
 *
 * ## Why `Str::uuid()` and not a random string, a hash, or a composed value
 *
 * - A composed value (`nomor_surat` plus a counter, a signature over the doctor's id)
 *   is GUESSABLE, and a verification token has to be unguessable by a stranger holding
 *   a photograph of the letter.
 * - A hash of something is only as unguessable as its input, and every input here is
 *   public: the document number, the doctor, the date.
 * - `Str::random()` is cryptographically strong in this framework, but it uses the
 *   ambiguous alphabet, so `0`/`O` and `1`/`l` cannot be told apart by a human reading
 *   a printed letter off a fax. A UUID4 is base-16-ish, unambiguous, and is what the
 *   plan names.
 *
 * The interface exists so the retry can be tested; this class exists so the default is
 * not `Str::uuid()` inlined in a service, which would make the injected source and the
 * production source two different code paths.
 */
final class StrQrTokenGenerator implements QrTokenGenerator
{
    /**
     * A fresh v4 UUID as a 36-character string.
     *
     * `Str::uuid()` is a v4 UUID generated from the CSPRNG, so the version nibble at
     * index 14 is `4` and the 122 bits after it are random. The test asserts both the
     * shape and the length, because a token that had to be truncated to fit
     * `VARCHAR(100)` would be a different value from the one the QR carries.
     */
    public function next(): string
    {
        return (string) Str::uuid();
    }
}
