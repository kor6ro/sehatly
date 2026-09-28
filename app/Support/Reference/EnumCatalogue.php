<?php

declare(strict_types=1);

namespace App\Support\Reference;

use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use App\Support\Schema\TypeNormaliser;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Reads every ENUM column in a database and reduces it to a sorted
 * `table.column => list<value>` map, from TWO independent sources.
 *
 * ## Why two sources when the plan only asks for one
 *
 * The plan's todo 42 asks for a generator over `information_schema`. It asks for
 * nothing else, and on its own that is a one-way mirror: it reports what the
 * database currently says and can say nothing about `telemedicine_test.sql`,
 * which is the read-only contract every other todo cites line numbers from. A
 * generator reading one source cannot detect the other drifting, so this class
 * reads both and {@see compare()} is the whole point of it:
 *
 * - {@see fromInformationSchema()} is the LIVE schema. It is what the file is
 *   generated from, so the file cannot go stale relative to the running code.
 * - {@see fromReferenceDdl()} is the parsed contract. `SqlSchemaParser` is
 *   reused rather than a second regex written here, so the DDL side is read with
 *   the same quote-aware grammar that `sehatly:verify-schema` already proves
     *    non-vacuous, and `master_obat.bentuk_sediaan`'s two-line ENUM (`:713`-`:714`)
 *   is one unit for free.
 *
 * ## The measured result, and why it is worth the second source
 *
 * On the dev database: 69 base-table ENUM columns carrying 319 values in both
 * sources, and **0 divergences**. That is a real measurement, not an assumption -
 * `.omo/evidence/task-42-sehatly.md` carries the run.
 *
 * ## Base tables only, and the one view column that is deliberately excluded
 *
 * `information_schema.COLUMNS` also describes VIEW columns, and this schema has
 * one: `v_dokter_katalog.tipe`, which MySQL reports as a 7-value ENUM. It is
 * **excluded**, and the exclusion is reported rather than silent
 * ({@see excludedViewEnumColumns()}), for two reasons:
 *
 * 1. It is a projection. `v_dokter_katalog` is `CREATE OR REPLACE VIEW ... AS
 *    SELECT` (`telemedicine_test.sql:1170`), so its `tipe` IS `dokter.tipe` at
 *    `:412` under a second name. `SqlSchemaParser` records view NAMES only
 *    (`SchemaSpec::$views`), so the parsed contract has no view column to
 *    compare against and including the live one would make the two sources
 *    disagree forever on a structural difference that is not drift.
 * 2. A client generating Dart and TypeScript enums from this file would emit a
 *    `VDokterKatalogTipe` class duplicating `DokterTipe` under a name the schema
 *    contract does not contain.
 *
 * ## Strictly read-only
 *
 * The only thing that reaches the server is `DB::select()` against
 * `information_schema`. The database name always comes from the caller's
 * connection, never from a literal, and {@see write()} is the only method in the
 * class that writes anything - and it writes a file, not a row.
 */
final class EnumCatalogue
{
    /**
     * The JSON encoding flags, named so the choice is auditable rather than
     * implicit in a call site.
     *
     * `JSON_PRETTY_PRINT` because a 69-key file is read by humans as often as by
     * generators. `JSON_UNESCAPED_SLASHES` because an enum value containing `/`
     * should read as itself. `JSON_UNESCAPED_UNICODE` because it is the only
     * setting that is honest about encoding: the file declares nothing, so the
     * bytes are UTF-8, and escaping a value to `\u00e9` would be a second, less
     * legible spelling of the same string. All 319 values in the current contract
     * are ASCII, so this flag changes nothing today and everything the day one is
     * not.
     *
     * There is deliberately **no** `JSON_THROW_ON_ERROR`: an encoding failure must
     * be reported as a failure of this class, with the json_last_error_msg(), not
     * as a `false` return the caller has to remember to test.
     */
    public const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** The line ending the file is guaranteed to use. */
    public const LINE_ENDING = "\n";

    public function __construct(private readonly TypeNormaliser $normaliser = new TypeNormaliser) {}

    /**
     * Every ENUM column of every BASE TABLE in `$database`, read from
     * `information_schema`.
     *
     * @return array<string, list<string>> `table.column` => values, declaration order preserved
     */
    public function fromInformationSchema(string $database): array
    {
        if ($database === '') {
            throw new RuntimeException('Cannot read ENUM columns without a database name.');
        }

        $rows = DB::select(
            'select c.TABLE_NAME as t, c.COLUMN_NAME as c, c.COLUMN_TYPE as y'
            .' from information_schema.COLUMNS c'
            .' join information_schema.TABLES t'
            .'   on t.TABLE_SCHEMA = c.TABLE_SCHEMA and t.TABLE_NAME = c.TABLE_NAME'
            .' where c.TABLE_SCHEMA = ? and c.DATA_TYPE = ? and t.TABLE_TYPE = ?'
            .' order by c.TABLE_NAME, c.COLUMN_NAME',
            [$database, 'enum', 'BASE TABLE'],
        );

        $out = [];

        foreach ($rows as $row) {
            $key = strtolower((string) $row->t).'.'.strtolower((string) $row->c);
            $out[$key] = $this->enumValues((string) $row->y);
        }

        uksort($out, 'strcmp');

        return $out;
    }

    /**
     * The ENUM columns of a parsed reference DDL.
     *
     * @return array<string, list<string>>
     */
    public function fromReferenceDdl(string $path): array
    {
        return $this->fromSpec((new SqlSchemaParser)->parseFile($path));
    }

    /**
     * @return array<string, list<string>>
     */
    public function fromSpec(SchemaSpec $spec): array
    {
        $out = [];

        foreach ($spec->tables as $table) {
            foreach ($table->columns as $column) {
                if (! str_starts_with($column->type, 'enum(')) {
                    continue;
                }

                $out[$table->name.'.'.$column->name] = $this->enumValues($column->type);
            }
        }

        uksort($out, 'strcmp');

        return $out;
    }

    /**
     * The ENUM columns that belong to VIEWs and are therefore NOT in the
     * catalogue. Reported rather than dropped, so the exclusion of
     * `v_dokter_katalog.tipe` is visible in the command's output instead of
     * looking like a reader that missed a column.
     *
     * @return array<string, list<string>>
     */
    public function excludedViewEnumColumns(string $database): array
    {
        $rows = DB::select(
            'select c.TABLE_NAME as t, c.COLUMN_NAME as c, c.COLUMN_TYPE as y'
            .' from information_schema.COLUMNS c'
            .' join information_schema.TABLES t'
            .'   on t.TABLE_SCHEMA = c.TABLE_SCHEMA and t.TABLE_NAME = c.TABLE_NAME'
            .' where c.TABLE_SCHEMA = ? and c.DATA_TYPE = ? and t.TABLE_TYPE = ?'
            .' order by c.TABLE_NAME, c.COLUMN_NAME',
            [$database, 'enum', 'VIEW'],
        );

        $out = [];

        foreach ($rows as $row) {
            $key = strtolower((string) $row->t).'.'.strtolower((string) $row->c);
            $out[$key] = $this->enumValues((string) $row->y);
        }

        uksort($out, 'strcmp');

        return $out;
    }

    /**
     * The catalogue as the exact bytes `docs/enums.json` holds.
     *
     * ## Determinism, one decision at a time
     *
     * "Byte-identical to a fresh export" is only meaningful if the bytes are a
     * function of the schema and nothing else, so every degree of freedom is
     * closed here rather than left to a call site:
     *
     * - **Key order** is `uksort(..., 'strcmp')`. A byte comparison, not a locale
     *   collation and not insertion order, so the result does not depend on the
     *   `information_schema` query plan, on `sort()`'s default flags, or on the
     *   machine's locale.
     * - **Value order** is MySQL's declaration order and is NOT sorted. It is
     *   load-bearing: a column's numeric index is its position in the list, and
     *   `FIND_IN_INDEX()` on it would silently change if the file sorted the
     *   values.
     * - **Line endings** are LF, asserted rather than hoped for. `json_encode`
     *   hard-codes `\n` in its pretty printer, so the `str_replace` below is a
     *   no-op today; it is here so that a future flag or a platform change
     *   cannot introduce a CRLF that Git then normalises on one machine and not
     *     another. The test asserts the file contains zero `\r` bytes.
     * - **No BOM.** {@see write()} puts the payload at byte 0 and nothing else,
     *     and a test reads the first three bytes raw. This is not paranoia: an
     *     earlier executor in this project reported "no BOM" for a commit that
     *     had one, because the check round-tripped the bytes through a .NET
     *     string and that step drops the BOM.
     * - **A trailing newline**, so the file ends the way every other text file in
     *     the repository does and `diff` does not report "\ No newline at end of
     *     file".
     * - **No timestamp, no database name, no host.** A generated artefact that
     *     records when it was generated cannot be byte-compared to a fresh
     *     export; the provenance is the command, in version control.
     *
     * @param  array<string, list<string>>  $catalogue
     */
    public function render(array $catalogue): string
    {
        uksort($catalogue, 'strcmp');

        try {
            $json = json_encode($catalogue, self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'The ENUM catalogue could not be encoded as JSON: '.$e->getMessage(),
                previous: $e,
            );
        }

        if ($json === false) {
            throw new RuntimeException(
                'The ENUM catalogue could not be encoded as JSON: '.json_last_error_msg(),
            );
        }

        return str_replace("\r\n", "\n", $json).self::LINE_ENDING;
    }

    /**
     * Write `$contents` to `$path` as raw bytes.
     *
     * `file_put_contents()` is byte-exact on Windows: PHP opens the handle in
     * binary mode, so no `\n` is ever rewritten to `\r\n` behind the caller's
     * back. That is the reason this is not a `Storage`/`Filesystem` call - the
     * `Illuminate\Filesystem\Filesystem` API is the same underneath, and going
     * through it would add a `config('filesystems.default')` dependency to a
     * generated artefact whose whole property is that it is the same bytes
     * everywhere.
     */
    public function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new RuntimeException('Cannot write '.$path.': '.$directory.' is not a directory.');
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Failed to write '.$path.'.');
        }
    }

    /**
     * Closed-set comparison of the live catalogue against the parsed contract.
     *
     * A closed set, in both directions: a column only `information_schema` knows
     * about is a divergence, and so is one only the DDL declares. A one-directional
     * `array_intersect_key` would report "no drift" for a live schema with an
     * extra ENUM column, which is exactly the drift a reader is supposed to catch.
     *
     * Value lists are compared as ORDERED lists, never as sets. MySQL gives an
     * ENUM member its numeric index from its position, so a reordered list is a
     * different type, not a cosmetic one.
     *
     * @param  array<string, list<string>>  $live
     * @param  array<string, list<string>>  $contract
     * @return list<array{kind: string, column: string, expected: list<string>, actual: list<string>}>
     */
    public function compare(array $live, array $contract): array
    {
        $out = [];

        foreach (array_diff_key($live, $contract) as $column => $values) {
            $out[] = [
                'kind' => 'missing_in_reference_ddl',
                'column' => $column,
                'expected' => [],
                'actual' => $values,
            ];
        }

        foreach (array_diff_key($contract, $live) as $column => $values) {
            $out[] = [
                'kind' => 'missing_in_live_schema',
                'column' => $column,
                'expected' => $values,
                'actual' => [],
            ];
        }

        $shared = array_intersect_key($live, $contract);

        foreach ($shared as $column => $values) {
            if ($values !== $contract[$column]) {
                $out[] = [
                    'kind' => 'value_mismatch',
                    'column' => $column,
                    'expected' => $contract[$column],
                    'actual' => $values,
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['column'], (string) $b['column']));

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $catalogue
     */
    public function totalValues(array $catalogue): int
    {
        $total = 0;

        foreach ($catalogue as $values) {
            $total += count($values);
        }

        return $total;
    }

    /**
     * Split a MySQL `enum(...)` / `ENUM(...)` type string into its members.
     *
     * The three awkward cases, and how each is handled:
     *
     * 1. **The list can span physical lines.** `master_obat.bentuk_sediaan` is
     *    written across `:713`-`:714` with the continuation indented, and
     *    `master_golongan_darah.kode` is a 4-value ENUM on one line
     *    (`:96`). `readGroup()` skips whitespace including newlines, so both
     *    arrive whole. Reading only the first physical line - the mistake the
     *    plan's own appendix A.20 warns about for column declarations - would
     *    truncate this one to 7 values.
     * 2. **A member can contain a comma or a quote.** `TypeNormaliser::splitTopLevel()`
     *    is quote- and paren-aware, and `readQuoted()` handles the `''` and
     *    `\'` escapes MySQL uses. No member in the current 319 contains either,
     *    and the code is exercised against a synthetic case in the test so that
     *    "no member needs it" is a measurement rather than a hope.
     * 3. **A member can be an unquoted word** in a hand-written DDL.
     *    `TypeNormaliser::canonicalArguments()` keeps it verbatim, and so does
     *    this, so the two sources compare like with like instead of one side
     *    silently lower-casing it.
     *
     * @return list<string>
     */
    public function enumValues(string $type): array
    {
        $open = strpos($type, '(');

        if ($open === false || ! str_ends_with(rtrim($type), ')')) {
            throw new RuntimeException('Not an ENUM type string: '.self::excerpt($type));
        }

        $cursor = $open;
        $inner = $this->normaliser->readGroup($type, $cursor);
        $values = [];

        foreach ($this->normaliser->splitTopLevel($inner, ',') as $member) {
            $member = trim($member);

            if ($member === '') {
                continue;
            }

            $values[] = self::unquote($member);
        }

        if ($values === []) {
            throw new RuntimeException('ENUM with an empty value list: '.self::excerpt($type));
        }

        return $values;
    }

    /**
     * Strip the delimiters from one already-split ENUM member and undo MySQL's
     * escaping.
     *
     * `TypeNormaliser::readQuoted()` returns the token WITH its delimiters and
     * with a `\'` escape left as the two characters `\` and `'`, which is exactly
     * what has to be undone here. An unquoted member is returned trimmed and
     * otherwise untouched.
     */
    private static function unquote(string $member): string
    {
        $first = $member[0];

        if ($first !== "'" && $first !== '"') {
            return $member;
        }

        $cursor = 0;
        $token = (new TypeNormaliser)->readQuoted($member, $cursor);
        $value = substr($token, 1, -1);

        return str_replace(
            ['\\\\', "\\'", "''"],
            ['\\', "'", "'"],
            $value,
        );
    }

    private static function excerpt(string $subject, int $limit = 90): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $subject));

        return strlen($flat) <= $limit ? $flat : substr($flat, 0, $limit).'...';
    }
}
