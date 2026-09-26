<?php

declare(strict_types=1);

namespace App\Support\Schema;

/**
 * One difference between the reference DDL and the live schema.
 */
final readonly class Discrepancy
{
    /**
     * @param  string  $kind  stable machine-readable discriminator, e.g. `missing_column`
     * @param  bool  $drift  false for informational notes that must not fail the run
     */
    public function __construct(
        public string $kind,
        public ?string $table,
        public ?string $column,
        public ?string $expected,
        public ?string $actual,
        public bool $drift = true,
    ) {}

    public function isDrift(): bool
    {
        return $this->drift;
    }

    /**
     * Stable ordering so two runs over the same inputs print the same report.
     */
    public function sortKey(): string
    {
        return implode("\0", [$this->kind, (string) $this->table, (string) $this->column, (string) $this->expected, (string) $this->actual]);
    }

    /**
     * @return array<string, ?string|bool>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'table' => $this->table,
            'column' => $this->column,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'drift' => $this->drift,
        ];
    }
}
