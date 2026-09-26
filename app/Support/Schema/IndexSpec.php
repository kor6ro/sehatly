<?php

declare(strict_types=1);

namespace App\Support\Schema;

/**
 * One index (primary key, unique key or plain index) of a table.
 *
 * Index *names* are only trustworthy when the source wrote them. An inline
 * `UNIQUE` gets an engine-generated name (`users.email` -> `email`), while
 * Laravel's `$table->unique('email')` emits `users_email_unique`; the two are the
 * same constraint with different spellings, so only an explicitly written name is
 * worth comparing by name.
 */
final readonly class IndexSpec
{
    /**
     * @param  'PRIMARY'|'UNIQUE'|'INDEX'  $type
     * @param  list<string>  $columns  ordered column list
     */
    public function __construct(
        public string $type,
        public ?string $name,
        public array $columns,
        public bool $nameIsAuthoritative,
        public int $line,
    ) {}

    /**
     * Name-independent identity: what the constraint actually does.
     */
    public function semanticKey(): string
    {
        return $this->type.' ('.implode(', ', $this->columns).')';
    }

    public function label(): string
    {
        // `PRIMARY PRIMARY (id)` says nothing twice.
        if ($this->name === null || strcasecmp($this->name, 'primary') === 0) {
            return $this->semanticKey();
        }

        return $this->name.' '.$this->semanticKey();
    }
}
