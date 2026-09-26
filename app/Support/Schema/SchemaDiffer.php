<?php

declare(strict_types=1);

namespace App\Support\Schema;

/**
 * Diffs a reference {@see SchemaSpec} against a live one.
 *
 * The differ never normalises anything itself — both sides arrive already
 * canonicalised by {@see TypeNormaliser} — so anything it reports is a real
 * difference rather than a spelling difference.
 *
 * Two rules carry most of the weight:
 *
 *  1. **Uniqueness is compared semantically, not by name.** An inline `UNIQUE`
 *     becomes index `email` in MySQL but `users_email_unique` in Laravel; both are
 *     the same constraint. Only a name the DDL actually wrote is compared by name.
 *  2. **CHECK constraints are compared by expression.** MySQL generates the names
 *     (`ulasan_dokter_chk_1`), so only the expression is stable.
 */
final class SchemaDiffer
{
    /**
     * @param  list<string>|null  $onlyTables  restrict the comparison to these expected tables
     * @param  array<string, string>  $documentedExtras  extra tables registered in `docs/schema-notes.md`
     * @return list<Discrepancy>
     */
    public function diff(
        SchemaSpec $expected,
        SchemaSpec $live,
        ?array $onlyTables = null,
        array $documentedExtras = [],
    ): array {
        $out = [];

        if ($onlyTables !== null) {
            foreach ($onlyTables as $name) {
                if (! $expected->hasTable($name)) {
                    // Asked for a table the reference DDL never defines: a caller
                    // mistake, not schema drift.
                    $out[] = new Discrepancy('unknown_requested_table', $name, null, null, null, false);
                }
            }
        }

        $scope = $onlyTables === null
            ? $expected->tableNames()
            : array_values(array_filter($expected->tableNames(), static fn (string $t): bool => in_array($t, $onlyTables, true)));

        foreach ($scope as $name) {
            $want = $expected->table($name);
            $have = $live->table($name);

            if ($have === null) {
                $out[] = new Discrepancy('missing_table', $name, null, $this->expectedSummary($want), null);

                continue;
            }

            $this->diffTable($want, $have, $out);
        }

        if ($onlyTables === null) {
            foreach ($live->tableNames() as $name) {
                if ($expected->hasTable($name)) {
                    continue;
                }

                $justification = $documentedExtras[$name] ?? null;

                $out[] = $justification === null
                    ? new Discrepancy(
                        'undocumented_extra_table',
                        $name,
                        null,
                        'no entry in docs/schema-notes.md',
                        'present in the live schema',
                    )
                    : new Discrepancy('documented_extra_table', $name, null, $justification, 'present in the live schema', false);
            }

            foreach ($expected->views as $view) {
                if (! in_array($view, $live->views, true)) {
                    $out[] = new Discrepancy('missing_view', $view, null, 'view', null);
                }
            }

            foreach ($live->views as $view) {
                if (! in_array($view, $expected->views, true)) {
                    $out[] = new Discrepancy('extra_view', $view, null, null, 'view', false);
                }
            }
        }

        usort($out, static fn (Discrepancy $a, Discrepancy $b): int => $a->sortKey() <=> $b->sortKey());

        return array_values($out);
    }

    /**
     * @param  list<Discrepancy>  $out
     */
    private function diffTable(TableSpec $want, TableSpec $have, array &$out): void
    {
        if ($want->engine !== null && $have->engine !== null && $want->engine !== $have->engine) {
            $out[] = new Discrepancy('table_engine', $want->name, null, $want->engine, $have->engine);
        }

        foreach ($want->columns as $name => $column) {
            $actual = $have->columns[$name] ?? null;

            if ($actual === null) {
                $out[] = new Discrepancy('missing_column', $want->name, $name, $column->describe(), null);

                continue;
            }

            $this->diffColumn($want->name, $column, $actual, $out);
        }

        foreach ($have->columns as $name => $column) {
            if (! isset($want->columns[$name])) {
                $out[] = new Discrepancy('extra_column', $want->name, $name, null, $column->describe());
            }
        }

        $this->diffIndexes($want, $have, $out);
        $this->diffForeignKeys($want, $have, $out);
        $this->diffChecks($want, $have, $out);
    }

    /**
     * @param  list<Discrepancy>  $out
     */
    private function diffColumn(string $table, ColumnSpec $want, ColumnSpec $have, array &$out): void
    {
        if ($want->type !== $have->type) {
            $out[] = new Discrepancy('column_type', $table, $want->name, $want->type, $have->type);
        }

        if ($want->unsigned !== $have->unsigned) {
            $out[] = new Discrepancy('column_unsigned', $table, $want->name, $want->unsigned ? 'unsigned' : 'signed', $have->unsigned ? 'unsigned' : 'signed');
        }

        if ($want->nullable !== $have->nullable) {
            $out[] = new Discrepancy('column_nullable', $table, $want->name, $want->nullable ? 'NULL' : 'NOT NULL', $have->nullable ? 'NULL' : 'NOT NULL');

            // Nullability is the root cause here; comparing the default on top of
            // it would report one change twice and bury the real one.
            return;
        }

        // A nullable column with no DEFAULT clause and a nullable column with
        // `DEFAULT NULL` behave identically: MySQL's implicit default for a
        // nullable column is NULL, and information_schema cannot tell them apart.
        // Laravel's `$table->x()->nullable()` emits `DEFAULT NULL` while
        // telemedicine_test.sql usually writes a bare `NULL`, so failing to fold
        // these would report every nullable column in the schema as drift.
        $wantDefault = $this->effectiveDefault($want);
        $haveDefault = $this->effectiveDefault($have);

        if ($wantDefault !== $haveDefault) {
            $out[] = new Discrepancy('column_default', $table, $want->name, $wantDefault ?? '<none>', $haveDefault ?? '<none>');
        }

        if ($want->autoIncrement !== $have->autoIncrement) {
            $out[] = new Discrepancy('column_auto_increment', $table, $want->name, $want->autoIncrement ? 'AUTO_INCREMENT' : 'no AUTO_INCREMENT', $have->autoIncrement ? 'AUTO_INCREMENT' : 'no AUTO_INCREMENT');
        }

        if ($want->onUpdate !== $have->onUpdate) {
            $out[] = new Discrepancy('column_on_update', $table, $want->name, $want->onUpdate ?? '<none>', $have->onUpdate ?? '<none>');
        }
    }

    /**
     * The default as it will be compared: a nullable column with no DEFAULT clause
     * is equivalent to one declared `DEFAULT NULL`, so both normalise to `NULL`.
     */
    private function effectiveDefault(ColumnSpec $column): ?string
    {
        if ($column->nullable) {
            return $column->default ?? 'NULL';
        }

        return $column->default;
    }

    /**
     * A DDL-written index name is part of the contract, so a rename is drift. But
     * an inline `UNIQUE` is matched on semantics only, because MySQL and Laravel
     * disagree about its name by design.
     *
     * @param  list<Discrepancy>  $out
     */
    private function diffIndexes(TableSpec $want, TableSpec $have, array &$out): void
    {
        $remaining = $have->indexes;
        $consumed = [];

        foreach ($want->indexes as $index) {
            $match = $index->nameIsAuthoritative && $index->name !== null
                ? $this->takeByName($remaining, $index->name, $consumed)
                : $this->takeBySemantics($remaining, $index, $consumed);

            if ($match === null && $index->nameIsAuthoritative && $index->name !== null) {
                // Nothing carries that name. If an index with the same shape does,
                // this is a rename rather than a disappearance.
                $match = $this->takeBySemantics($remaining, $index, $consumed);

                if ($match !== null) {
                    $out[] = new Discrepancy('index_name', $want->name, null, $index->label(), $match->label());

                    continue;
                }
            }

            if ($match === null) {
                $out[] = new Discrepancy(
                    $index->type === 'PRIMARY' ? 'missing_primary_key' : 'missing_index',
                    $want->name,
                    null,
                    $index->label(),
                    null,
                );

                continue;
            }

            if ($match->semanticKey() !== $index->semanticKey()) {
                $out[] = new Discrepancy('index_columns', $want->name, null, $index->label(), $match->label());
            }
        }

        foreach ($remaining as $key => $index) {
            if (in_array($key, $consumed, true)) {
                continue;
            }

            $out[] = new Discrepancy('extra_index', $want->name, null, null, $index->label());
        }
    }

    /**
     * @param  list<IndexSpec>  $pool
     * @param  list<int>  $consumed
     */
    private function takeByName(array $pool, string $name, array &$consumed): ?IndexSpec
    {
        foreach ($pool as $key => $index) {
            if ($index->name !== null && strcasecmp($index->name, $name) === 0) {
                $consumed[] = $key;

                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<IndexSpec>  $pool
     * @param  list<int>  $consumed
     */
    private function takeBySemantics(array $pool, IndexSpec $index, array &$consumed): ?IndexSpec
    {
        foreach ($pool as $key => $candidate) {
            if ($candidate->semanticKey() === $index->semanticKey()) {
                $consumed[] = $key;

                return $candidate;
            }
        }

        return null;
    }

    /**
     * Foreign keys match on their *target* first (local columns, referenced table
     * and columns) so that a changed `ON DELETE` reads as an action drift rather
     * than as a delete plus an unrelated create. Names are only consulted when the
     * DDL wrote one, because an inline `FOREIGN KEY` is named `<table>_ibfk_<n>` by
     * the engine.
     *
     * @param  list<Discrepancy>  $out
     */
    private function diffForeignKeys(TableSpec $want, TableSpec $have, array &$out): void
    {
        $remaining = $have->foreignKeys;
        $consumed = [];

        foreach ($want->foreignKeys as $key) {
            $match = $key->nameIsAuthoritative && $key->name !== null
                ? $this->takeForeignKeyByName($remaining, $key->name, $consumed)
                : $this->takeForeignKeyByTarget($remaining, $key, $consumed);

            if ($match === null) {
                $out[] = new Discrepancy('missing_foreign_key', $want->name, null, $key->label(), null);

                continue;
            }

            if ($match->semanticKey() !== $key->semanticKey()) {
                $out[] = new Discrepancy('foreign_key_action', $want->name, null, $key->label(), $match->label());
            }
        }

        foreach ($remaining as $key => $foreignKey) {
            if (in_array($key, $consumed, true)) {
                continue;
            }

            $out[] = new Discrepancy('extra_foreign_key', $want->name, null, null, $foreignKey->label());
        }
    }

    /**
     * @param  list<ForeignKeySpec>  $pool
     * @param  list<int>  $consumed
     */
    private function takeForeignKeyByName(array $pool, string $name, array &$consumed): ?ForeignKeySpec
    {
        foreach ($pool as $key => $foreignKey) {
            if ($foreignKey->name !== null && strcasecmp($foreignKey->name, $name) === 0) {
                $consumed[] = $key;

                return $foreignKey;
            }
        }

        return null;
    }

    /**
     * @param  list<ForeignKeySpec>  $pool
     * @param  list<int>  $consumed
     */
    private function takeForeignKeyByTarget(array $pool, ForeignKeySpec $want, array &$consumed): ?ForeignKeySpec
    {
        foreach ($pool as $key => $candidate) {
            if ($candidate->targetKey() === $want->targetKey()) {
                $consumed[] = $key;

                return $candidate;
            }
        }

        return null;
    }

    /**
     * CHECK names are engine-generated (`ulasan_dokter_chk_1`), so only the
     * expression is compared.
     *
     * @param  list<Discrepancy>  $out
     */
    private function diffChecks(TableSpec $want, TableSpec $have, array &$out): void
    {
        $haveExpressions = array_map(static fn (CheckSpec $c): string => $c->expression, $have->checks);
        $matched = [];

        foreach ($want->checks as $check) {
            $index = array_search($check->expression, $haveExpressions, true);

            if ($index === false) {
                $out[] = new Discrepancy('missing_check', $want->name, null, $check->label(), null);

                continue;
            }

            $matched[] = $index;
        }

        foreach ($have->checks as $index => $check) {
            if (! in_array($index, $matched, true)) {
                $out[] = new Discrepancy('extra_check', $want->name, null, null, $check->label());
            }
        }
    }

    private function expectedSummary(TableSpec $table): string
    {
        return sprintf(
            '%d columns, %d indexes, %d foreign keys, %d checks',
            count($table->columns),
            count($table->indexes),
            count($table->foreignKeys),
            count($table->checks),
        );
    }
}
