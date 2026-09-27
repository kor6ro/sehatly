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
 *  3. **A foreign key may be deferred, but only by its name, and only while it is
 *     genuinely absent.** See {@see diffForeignKeys()} and
 *     {@see DeferredConstraintRegistry}.
 *
 * Deliberately not compared:
 *
 *  - **Storage engine, charset and collation.** The reference sets `utf8mb4` once at
 *    database level, not per table, so there is nothing per-table to diff.
 *  - **Column order, and generated-column expressions / `SRID`.** Neither is in the
 *    contract; both are tokenised without being compared.
 *  - **The names of inline `UNIQUE` indexes.** Engine-generated on one side,
 *    Laravel-generated on the other; only the shape is stable (rule 1).
 *  - **Indexes implicitly created by InnoDB to support a foreign key are implied by
 *    that FK and are not compared.** See {@see diffIndexes()}.
 */
final class SchemaDiffer
{
    /**
     * @param  list<string>|null  $onlyTables  restrict the comparison to these expected tables
     * @param  array<string, string>  $documentedExtras  extra tables registered in `docs/schema-notes.md`
     * @param  array<string, string>  $deferredConstraints  constraint name => justification, from `docs/schema-notes.md`
     * @return list<Discrepancy>
     */
    public function diff(
        SchemaSpec $expected,
        SchemaSpec $live,
        ?array $onlyTables = null,
        array $documentedExtras = [],
        array $deferredConstraints = [],
    ): array {
        $out = [];

        // The registry arrives lower-cased from `DeferredConstraintRegistry`, but
        // folding again here means a caller that built the array by hand cannot
        // accidentally make a named constraint unforgivable by capitalising it.
        $deferrals = [];

        foreach ($deferredConstraints as $constraint => $justification) {
            $deferrals[strtolower($constraint)] = $justification;
        }

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

            $this->diffTable($want, $have, $deferrals, $out);
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
     * @param  array<string, string>  $deferrals
     * @param  list<Discrepancy>  $out
     */
    private function diffTable(TableSpec $want, TableSpec $have, array $deferrals, array &$out): void
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

        $this->diffChecks($want, $have, $out);

        // Foreign keys before indexes: an index that InnoDB created to back a
        // foreign key is only recognisable as such once the key has been matched.
        // The report order does not depend on this — diff() re-sorts by kind.
        $matchedForeignKeys = $this->diffForeignKeys($want, $have, $deferrals, $out);
        $this->diffIndexes($want, $have, $matchedForeignKeys, $out);
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
     * The one index this forgives is the one InnoDB builds to back a foreign key.
     * It reuses an existing index whose leftmost prefix covers the key's columns
     * and otherwise creates an index on exactly those columns, in order — so a
     * leftover index whose ordered column list is exactly a matched foreign key's
     * local column list is *implied* by that key and is not drift.
     * {@see telemedicine_test.sql} relies on this: 80 of its 105 foreign keys are
     * declared with no covering index at all.
     *
     * The comparison is deliberately exact ordered equality rather than a prefix.
     * A prefix match would also forgive a deliberate composite index such as
     * `(provinsi_id, nama)`, which no engine would ever create on its own, and a
     * differently ordered list such as `(b, a)` for a key on `(a, b)`. Neither is
     * implied, so both are still reported. An index the DDL named is consumed by
     * the loop above and never reaches this pool.
     *
     * @param  list<ForeignKeySpec>  $matchedForeignKeys  expected keys that found a live counterpart
     * @param  list<Discrepancy>  $out
     */
    private function diffIndexes(TableSpec $want, TableSpec $have, array $matchedForeignKeys, array &$out): void
    {
        $implied = [];

        foreach ($matchedForeignKeys as $foreignKey) {
            $implied[$this->columnListKey($foreignKey->columns)] = true;
        }

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

            if (isset($implied[$this->columnListKey($index->columns)])) {
                continue;
            }

            $out[] = new Discrepancy('extra_index', $want->name, null, null, $index->label());
        }
    }

    /**
     * An ordered column list reduced to a comparable key. Case is folded because
     * both sides arrive from the parser, which lower-cases identifiers, but the
     * comparison must not depend on that.
     *
     * @param  list<string>  $columns
     */
    private function columnListKey(array $columns): string
    {
        return strtolower(implode(',', $columns));
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
     * A **missing** key that {@see DeferredConstraintRegistry} registers by that
     * name is reported as `deferred_foreign_key` **informational** instead of as
     * `missing_foreign_key` drift (rule 1). A registered key that **matched** is
     * reported as `fulfilled_deferred_foreign_key` **drift** (rule 2) — the
     * constraint has landed, so the registry row is stale and has to be removed in
     * the same commit. An unregistered missing key stays drift (rule 3), which is
     * the only direction that can fail a build, so a registry row can never widen
     * into a general exemption: it names exactly one constraint.
     *
     * @param  array<string, string>  $deferrals  lower-cased constraint name => justification
     * @param  list<Discrepancy>  $out
     * @return list<ForeignKeySpec> the expected keys that matched a live key
     */
    private function diffForeignKeys(TableSpec $want, TableSpec $have, array $deferrals, array &$out): array
    {
        $remaining = $have->foreignKeys;
        $consumed = [];
        $matched = [];

        foreach ($want->foreignKeys as $key) {
            $match = $key->nameIsAuthoritative && $key->name !== null
                ? $this->takeForeignKeyByName($remaining, $key->name, $consumed)
                : $this->takeForeignKeyByTarget($remaining, $key, $consumed);

            $deferral = $this->deferral($key, $deferrals);

            if ($match === null) {
                $out[] = $deferral === null
                    ? new Discrepancy('missing_foreign_key', $want->name, null, $key->label(), null)
                    : new Discrepancy('deferred_foreign_key', $want->name, null, $key->label(), 'absent by design — '.$deferral, false);

                continue;
            }

            $matched[] = $key;

            if ($deferral !== null) {
                $out[] = new Discrepancy(
                    'fulfilled_deferred_foreign_key',
                    $want->name,
                    null,
                    'still registered as deferred in docs/schema-notes.md — '.$deferral,
                    $match->label(),
                );
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

        return $matched;
    }

    /**
     * The justification this constraint is registered under, or null when it is not
     * registered.
     *
     * Only a key the DDL **itself named** is ever registrable. An inline
     * `FOREIGN KEY` is named `<table>_ibfk_<n>` by the engine, so its name is not
     * a stable thing to key a registry on and is refused here — the registry can
     * forgive `fk_vital_rm` and nothing else in the whole schema, which is what
     * keeps the exemption to a single, named, intended object.
     *
     * @param  array<string, string>  $deferrals  lower-cased constraint name => justification
     */
    private function deferral(ForeignKeySpec $key, array $deferrals): ?string
    {
        if (! $key->nameIsAuthoritative || $key->name === null) {
            return null;
        }

        return $deferrals[strtolower($key->name)] ?? null;
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
