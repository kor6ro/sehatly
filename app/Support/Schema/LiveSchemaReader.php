<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads the live schema of the *configured* database and reduces it to the same
 * {@see SchemaSpec} the reference SQL produces.
 *
 * Read-only by construction: the only thing that ever reaches the server is
 * `DB::select()`, and every statement issued is a `SELECT` against
 * `information_schema` or a `SHOW`. Nothing here can create, alter or drop
 * anything, and the database name always comes from the connection — never from a
 * hard-coded string.
 */
final class LiveSchemaReader
{
    public function __construct(private readonly SqlSchemaParser $parser = new SqlSchemaParser) {}

    /**
     * @return array{spec: SchemaSpec, sourceCounts: array<string, int>, warnings: list<string>}
     */
    public function read(string $database): array
    {
        $tables = $this->tableNames($database);
        $views = $this->viewNames($database);

        $specs = [];

        foreach ($tables as $table) {
            $specs[$table] = $this->parser->parseCreateTable(
                $this->showCreateTable($database, $table),
                SqlSchemaParser::NAMES_ARE_SERVER_GENERATED,
                1,
            );
        }

        ksort($specs);

        return [
            'spec' => new SchemaSpec($specs, $views),
            'sourceCounts' => $this->informationSchemaCounts($database, array_keys($specs)),
            'warnings' => [],
        ];
    }

    /**
     * @return list<string>
     */
    public function tableNames(string $database): array
    {
        $rows = DB::select(
            'select TABLE_NAME from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_TYPE = ? order by TABLE_NAME',
            [$database, 'BASE TABLE'],
        );

        return array_map(static fn (object $row): string => strtolower((string) $row->TABLE_NAME), $rows);
    }

    /**
     * @return list<string>
     */
    public function viewNames(string $database): array
    {
        $rows = DB::select(
            'select TABLE_NAME from information_schema.VIEWS where TABLE_SCHEMA = ? order by TABLE_NAME',
            [$database],
        );

        return array_map(static fn (object $row): string => strtolower((string) $row->TABLE_NAME), $rows);
    }

    /**
     * The live definition of one table, as MySQL itself would print it.
     */
    public function showCreateTable(string $database, string $table): string
    {
        $quoted = '`'.str_replace('`', '``', $table).'`';
        $rows = DB::select('show create table '.$quoted);

        if ($rows === []) {
            throw new RuntimeException('SHOW CREATE TABLE returned nothing for '.$database.'.'.$table);
        }

        $row = (array) $rows[0];

        foreach ($row as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'CREATE TABLE')) {
                return $value;
            }
        }

        throw new RuntimeException(
            'SHOW CREATE TABLE for '.$database.'.'.$table.' returned no CREATE TABLE payload (columns: '
                .implode(', ', array_keys($row)).')',
        );
    }

    /**
     * Independent counts straight from `information_schema`. The `SHOW CREATE
     * TABLE` route is authoritative, so these are a cross-check: a disagreement
     * means one of the two live sources is lying and the verifier says so instead
     * of trusting whichever it read first.
     *
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    public function informationSchemaCounts(string $database, array $tables): array
    {
        if ($tables === []) {
            return ['columns' => 0, 'indexes' => 0, 'foreign_keys' => 0, 'checks' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($tables), '?'));

        $columnRows = DB::select(
            'select count(*) as c from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME in ('
                .$placeholders.')',
            array_merge([$database], $tables),
        );

        $indexRows = DB::select(
            'select count(distinct concat(TABLE_NAME, 0x3a, INDEX_NAME)) as c from information_schema.STATISTICS'
                .' where TABLE_SCHEMA = ? and TABLE_NAME in ('.$placeholders.')',
            array_merge([$database], $tables),
        );

        $fkRows = DB::select(
            'select count(*) as c from information_schema.REFERENTIAL_CONSTRAINTS where CONSTRAINT_SCHEMA = ?'
                .' and TABLE_NAME in ('.$placeholders.')',
            array_merge([$database], $tables),
        );

        $checkRows = DB::select(
            'select count(*) as c from information_schema.TABLE_CONSTRAINTS where CONSTRAINT_SCHEMA = ?'
                ." and CONSTRAINT_TYPE = 'CHECK' and TABLE_NAME in (".$placeholders.')',
            array_merge([$database], $tables),
        );

        return [
            'columns' => (int) $columnRows[0]->c,
            'indexes' => (int) $indexRows[0]->c,
            'foreign_keys' => (int) $fkRows[0]->c,
            'checks' => (int) $checkRows[0]->c,
        ];
    }
}
