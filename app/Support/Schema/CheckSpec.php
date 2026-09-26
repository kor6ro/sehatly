<?php

declare(strict_types=1);

namespace App\Support\Schema;

/**
 * One `CHECK` constraint.
 *
 * MySQL generates the name (`ulasan_dokter_chk_1/_2/_3`) from the table name and
 * declaration order, so the expression is the only thing worth comparing.
 */
final readonly class CheckSpec
{
    public function __construct(
        public ?string $name,
        public string $expression,
        public int $line,
    ) {}

    public function label(): string
    {
        return $this->expression;
    }
}
