<?php

declare(strict_types=1);

namespace App\Support\Schema;

use RuntimeException;

/**
 * Raised whenever the reference SQL (or a `SHOW CREATE TABLE` transcript) cannot
 * be understood.
 *
 * The verifier must never turn a parse failure into a green run: a parser that
 * silently skips what it does not understand reports "no drift" for a schema it
 * never looked at. Every ambiguity therefore throws.
 */
class SchemaParseException extends RuntimeException
{
    public static function at(string $message, ?string $file, int $line): self
    {
        $where = $file === null ? 'line '.$line : $file.':'.$line;

        return new self($message.' ('.$where.')');
    }
}
