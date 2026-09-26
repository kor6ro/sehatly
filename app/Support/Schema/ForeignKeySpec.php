<?php

declare(strict_types=1);

namespace App\Support\Schema;

/**
 * One foreign key of a table, with its referential actions.
 */
final readonly class ForeignKeySpec
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     * @param  string  $onDelete  `RESTRICT` unless the DDL says otherwise
     * @param  string  $onUpdate  `RESTRICT` unless the DDL says otherwise
     */
    public function __construct(
        public ?string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public string $onDelete,
        public string $onUpdate,
        public bool $nameIsAuthoritative,
        public int $line,
    ) {}

    /**
     * What the key points at, without the referential actions. A changed
     * `ON DELETE` is a different kind of problem from a different target, so the
     * differ matches on this first and only then compares actions.
     */
    public function targetKey(): string
    {
        return 'FOREIGN KEY ('.implode(', ', $this->columns).')'
            .' -> '.$this->referencedTable.' ('.implode(', ', $this->referencedColumns).')';
    }

    /**
     * Name-independent identity. An inline `FOREIGN KEY` gets an engine-generated
     * `<table>_ibfk_<n>` name, so names are only compared when the DDL wrote one
     * (`CONSTRAINT fk_vital_rm FOREIGN KEY ...`).
     */
    public function semanticKey(): string
    {
        return $this->targetKey()
            .' ON DELETE '.$this->onDelete
            .' ON UPDATE '.$this->onUpdate;
    }

    public function label(): string
    {
        return $this->name === null
            ? $this->semanticKey()
            : $this->name.' '.$this->semanticKey();
    }
}
