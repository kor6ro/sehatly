<?php

declare(strict_types=1);

namespace App\Support\Schema;

use LogicException;

/**
 * One column of a table, normalised so the reference SQL and the live
 * `SHOW CREATE TABLE` output are directly comparable.
 */
final readonly class ColumnSpec
{
    /**
     * @param  string  $type  canonical base type, e.g. `varchar(255)`, `enum('a','b')`, `int`
     * @param  string|null  $default  canonical default; `null` means "no DEFAULT clause", the string `NULL` means `DEFAULT NULL`
     * @param  int  $line  first source line of the declaration
     * @param  int  $endLine  last source line of the declaration; greater than `$line` when the DDL wrapped it
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $unsigned,
        public bool $nullable,
        public ?string $default,
        public bool $autoIncrement,
        public ?string $onUpdate,
        public int $line,
        public int $endLine,
    ) {
        if ($this->line < 1 || $this->endLine < $this->line) {
            throw new LogicException('Inconsistent ColumnSpec source lines: '.$this->line.'-'.$this->endLine);
        }
    }

    /**
     * True when the DDL spread one declaration over several lines. Seven
     * `ENUM`s and three `NOT NULL` tails in `telemedicine_test.sql` do this; each
     * must still be read as a single unit.
     */
    public function wrapped(): bool
    {
        return $this->endLine > $this->line;
    }

    /**
     * Copy with nullability forced — used for the implicit NOT NULL that MySQL
     * gives every PRIMARY KEY column.
     */
    public function withNullable(bool $nullable): self
    {
        return new self(
            $this->name,
            $this->type,
            $this->unsigned,
            $nullable,
            $this->default,
            $this->autoIncrement,
            $this->onUpdate,
            $this->line,
            $this->endLine,
        );
    }

    /**
     * Human-readable rendering used by the discrepancy table.
     */
    public function describe(): string
    {
        $out = $this->type.($this->unsigned ? ' unsigned' : '');

        $out .= $this->nullable ? ' NULL' : ' NOT NULL';
        $out .= ' DEFAULT '.($this->default ?? '<none>');

        if ($this->autoIncrement) {
            $out .= ' AUTO_INCREMENT';
        }

        if ($this->onUpdate !== null) {
            $out .= ' ON UPDATE '.$this->onUpdate;
        }

        return $out;
    }
}
