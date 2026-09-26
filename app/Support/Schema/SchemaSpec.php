<?php

declare(strict_types=1);

namespace App\Support\Schema;

use InvalidArgumentException;

/**
 * A whole schema (or view set) reduced to the facts the parity verifier compares.
 *
 * The *same* class describes both sides of the diff: the reference SQL and the
 * live database. That is deliberate — running both through one parser is what
 * makes cosmetic differences impossible to mistake for real drift.
 */
final readonly class SchemaSpec
{
    /**
     * @param  array<string, TableSpec>  $tables  keyed by table name
     * @param  list<string>  $views
     */
    public function __construct(
        public array $tables,
        public array $views,
    ) {
        foreach (array_keys($tables) as $name) {
            if (! is_string($name) || $name === '' || strtolower($name) !== $name) {
                throw new InvalidArgumentException('Table keys must be lower-cased non-empty strings.');
            }
        }
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function table(string $name): ?TableSpec
    {
        return $this->tables[strtolower($name)] ?? null;
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[strtolower($name)]);
    }

    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    public function columnCount(): int
    {
        return array_sum(array_map(static fn (TableSpec $t): int => count($t->columns), $this->tables));
    }

    public function indexCount(): int
    {
        return array_sum(array_map(static fn (TableSpec $t): int => count($t->indexes), $this->tables));
    }

    public function foreignKeyCount(): int
    {
        return array_sum(array_map(static fn (TableSpec $t): int => count($t->foreignKeys), $this->tables));
    }

    public function checkCount(): int
    {
        return array_sum(array_map(static fn (TableSpec $t): int => count($t->checks), $this->tables));
    }

    /**
     * Columns whose declaration spanned more than one source line. Proves the
     * parser treated wrapped definitions as single units.
     *
     * @return list<array{table: string, column: string, line: int, end_line: int}>
     */
    public function multiLineColumns(): array
    {
        $out = [];

        foreach ($this->tables as $table) {
            foreach ($table->columns as $column) {
                if ($column->wrapped()) {
                    $out[] = [
                        'table' => $table->name,
                        'column' => $column->name,
                        'line' => $column->line,
                        'end_line' => $column->endLine,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @return array{tables: int, views: int, columns: int, indexes: int, foreign_keys: int, checks: int}
     */
    public function summary(): array
    {
        return [
            'tables' => count($this->tables),
            'views' => count($this->views),
            'columns' => $this->columnCount(),
            'indexes' => $this->indexCount(),
            'foreign_keys' => $this->foreignKeyCount(),
            'checks' => $this->checkCount(),
        ];
    }
}
