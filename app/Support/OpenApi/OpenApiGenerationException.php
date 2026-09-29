<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use RuntimeException;

/**
 * The generator could not read an input it needs.
 *
 * A separate type, and thrown rather than returned, for one reason: every
 * failure here is a state in which publishing a document would be a LIE.
 * `docs/enums.json` missing, malformed, or shaped unexpectedly -- each of those
 * would otherwise produce a complete-looking spec with no ENUM values in it,
 * and a client generated from that accepts a status the database rejects with a
 * 1264. The command catches this, reports it, and exits non-zero without
 * writing.
 *
 * Carries the path that caused it whenever one exists, because the actionable
 * answer to "the generator failed" is a filename.
 */
final class OpenApiGenerationException extends RuntimeException
{
    public static function unreadableInput(string $path, string $why): self
    {
        return new self($path.' could not be read: '.$why);
    }
}
