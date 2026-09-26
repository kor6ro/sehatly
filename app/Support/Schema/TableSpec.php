<?php

declare(strict_types=1);

namespace App\Support\Schema;

/**
 * One table, fully normalised.
 */
final readonly class TableSpec
{
    /**
     * @param  array<string, ColumnSpec>  $columns  keyed by lower-cased column name
     * @param  list<IndexSpec>  $indexes
     * @param  list<ForeignKeySpec>  $foreignKeys
     * @param  list<CheckSpec>  $checks
     */
    public function __construct(
        public string $name,
        public ?string $engine,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
        public array $checks,
        public int $line,
    ) {}

    /**
     * Copy with some constraint lists replaced — used when a later
     * `ALTER TABLE ... ADD CONSTRAINT` folds into this table.
     *
     * @param  array<string, ColumnSpec>|null  $columns
     * @param  list<IndexSpec>|null  $indexes
     * @param  list<ForeignKeySpec>|null  $foreignKeys
     * @param  list<CheckSpec>|null  $checks
     */
    public function with(
        ?array $columns = null,
        ?array $indexes = null,
        ?array $foreignKeys = null,
        ?array $checks = null,
    ): self {
        return new self(
            $this->name,
            $this->engine,
            $columns ?? $this->columns,
            $indexes ?? $this->indexes,
            $foreignKeys ?? $this->foreignKeys,
            $checks ?? $this->checks,
            $this->line,
        );
    }
}
