<?php

declare(strict_types=1);

namespace App\Support\Schema;

/**
 * Turns MySQL DDL into a {@see SchemaSpec}.
 *
 * The same parser reads both sides of the parity diff:
 *
 *  - the hand-written reference (`telemedicine_test.sql`), where an index or
 *    foreign key has a *meaningful* name only if the DDL wrote one;
 *  - `SHOW CREATE TABLE` output, where every name is engine-generated.
 *
 * That symmetry is the point: running both sides through one grammar is what
 * stops cosmetic differences from being reported as drift, and it means a
 * construct the parser cannot understand fails loudly on the live side too
 * instead of quietly disappearing.
 *
 * Strictly read-only. The parser never connects to anything and never writes.
 */
final class SqlSchemaParser
{
    /** Index/constraint names written in the DDL are the ones worth comparing. */
    public const NAMES_ARE_AUTHORITATIVE = true;

    /** Every name came from the engine, so only semantics may be compared. */
    public const NAMES_ARE_SERVER_GENERATED = false;

    /**
     * Words that end a `DEFAULT` / `ON UPDATE` expression.
     *
     * @var list<string>
     */
    private const CLAUSE_KEYWORDS = [
        'NOT', 'NULL', 'DEFAULT', 'AUTO_INCREMENT', 'UNIQUE', 'PRIMARY', 'KEY', 'INDEX',
        'COMMENT', 'CHECK', 'COLLATE', 'CHARACTER', 'CHARSET', 'REFERENCES', 'CONSTRAINT',
        'ON', 'GENERATED', 'STORED', 'VIRTUAL', 'SRID', 'INVISIBLE', 'VISIBLE', 'ENFORCED',
        'UNSIGNED', 'SIGNED', 'ZEROFILL',
    ];

    /**
     * Type-modifier words that belong to the type, not to the column's attributes.
     *
     * @var list<string>
     */
    private const TYPE_MODIFIERS = ['UNSIGNED', 'SIGNED', 'ZEROFILL'];

    /**
     * Words that can only start a statement, so finding one inside a table body
     * means the body ran past the end of its table. `SET` is deliberately absent:
     * `ON DELETE SET NULL` is legitimate column-adjacent text.
     *
     * @var list<string>
     */
    private const STATEMENT_KEYWORDS = ['CREATE', 'INSERT', 'DROP', 'ALTER', 'TRUNCATE', 'REPLACE', 'RENAME'];

    /**
     * Bare type names. A column definition contains exactly one of them, right
     * after the column name. A *second* one means two declarations were merged
     * into one - almost always a dropped trailing comma.
     *
     * @var list<string>
     */
    private const BARE_TYPE_KEYWORDS = [
        'TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'INTEGER', 'BIGINT',
        'DECIMAL', 'NUMERIC', 'FIXED', 'FLOAT', 'DOUBLE', 'REAL', 'BIT',
        'CHAR', 'VARCHAR', 'BINARY', 'VARBINARY',
        'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT',
        'ENUM', 'SET', 'DATE', 'TIME', 'DATETIME', 'TIMESTAMP', 'YEAR',
        'JSON', 'BOOL', 'BOOLEAN',
    ];

    private TypeNormaliser $normaliser;

    public function __construct(?TypeNormaliser $normaliser = null)
    {
        $this->normaliser = $normaliser ?? new TypeNormaliser;
    }

    public function normaliser(): TypeNormaliser
    {
        return $this->normaliser;
    }

    public function parseFile(string $path): SchemaSpec
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SchemaParseException('Reference SQL file is missing or unreadable: '.$path);
        }

        $sql = file_get_contents($path);

        if ($sql === false || trim($sql) === '') {
            throw new SchemaParseException('Reference SQL file is empty: '.$path);
        }

        $this->normaliser = new TypeNormaliser(basename($path));

        return $this->parse($sql);
    }

    /**
     * Parse a whole script. Statements are split on top-level semicolons, so the
     * 27 `DROP` lines, the 15 `INSERT` seed blocks, the `SET FOREIGN_KEY_CHECKS`
     * toggles and the two view bodies are recognised and skipped rather than
     * mistaken for table definitions.
     */
    public function parse(string $sql): SchemaSpec
    {
        $text = $this->stripComments($sql);
        $tables = [];
        $views = [];
        $createTableStatements = 0;

        foreach ($this->statements($text) as $statement) {
            $trimmed = trim($statement['sql']);
            $lead = strlen($statement['sql']) - strlen(ltrim($statement['sql']));
            $line = $this->normaliser->lineAt($text, $statement['offset'] + $lead);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^create\s+table\s+(?:if\s+not\s+exists\s+)?(`?[A-Za-z0-9_$]+`?)\s*\(/i', $trimmed, $m) === 1) {
                $createTableStatements++;
                $table = $this->parseCreateTable($trimmed, self::NAMES_ARE_AUTHORITATIVE, $line);

                if (isset($tables[$table->name])) {
                    throw SchemaParseException::at(
                        'Table '.$table->name.' is defined twice (first at line '.$tables[$table->name]->line.')',
                        null,
                        $line,
                    );
                }

                $tables[$table->name] = $table;

                continue;
            }

            if ($this->isCreateView($trimmed, $m)) {
                $views[] = $this->normaliser->identifier($m[1]);

                continue;
            }

            if (preg_match('/^alter\s+table\s+/i', $trimmed) === 1) {
                $this->applyAlter($trimmed, $tables, $line);

                continue;
            }
        }

        // A parser that quietly understood nothing would report "no drift" for a
        // schema it never read. Refuse rather than pass vacuously.
        if ($createTableStatements === 0 || $tables === []) {
            throw new SchemaParseException(
                'Parsed 0 CREATE TABLE statements from the reference DDL; refusing to report parity.',
            );
        }

        ksort($tables);
        $views = array_values(array_unique($views));
        sort($views);

        return new SchemaSpec($tables, $views);
    }

    /**
     * Parse one `CREATE TABLE` statement — also the entry point for live
     * `SHOW CREATE TABLE` output.
     *
     * @param  bool  $namesAreAuthoritative  false when the source is engine-generated
     * @param  int  $baseLine  line of `$ddl`'s first character in its own file
     */
    public function parseCreateTable(
        string $ddl,
        bool $namesAreAuthoritative = self::NAMES_ARE_AUTHORITATIVE,
        int $baseLine = 1,
    ): TableSpec {
        $ddl = trim($ddl);

        if (preg_match('/^create\s+table\s+(`?[A-Za-z0-9_$]+`?)\s*\(/i', $ddl, $m) !== 1) {
            throw SchemaParseException::at('Not a CREATE TABLE statement: '.self::excerpt($ddl), null, $baseLine);
        }

        $name = $this->normaliser->identifier($m[1]);
        $open = (int) strpos($ddl, '(', strlen($m[0]) - 1);
        $cursor = $open;
        [$body, $bodyStart] = $this->normaliser->readGroupWithOffset($ddl, $cursor);
        $trailer = substr($ddl, $cursor);

        $this->assertBodyIsOneTable($body, $name, $baseLine);

        $columns = [];
        $indexes = [];
        $foreignKeys = [];
        $checks = [];

        foreach ($this->normaliser->splitTopLevelWithOffsets($body, ',') as $part) {
            $definition = trim($part['text']);

            if ($definition === '') {
                continue;
            }

            // Byte offset of the first non-space character of this definition.
            $lead = strlen($part['text']) - strlen(ltrim($part['text']));
            $at = $bodyStart + $part['offset'] + $lead;
            $line = $baseLine + substr_count($ddl, "\n", 0, $at);
            $endLine = $baseLine + substr_count($ddl, "\n", 0, $at + strlen($definition));

            if ($this->parseTableConstraint($definition, $line, $namesAreAuthoritative, $indexes, $foreignKeys, $checks)) {
                continue;
            }

            $column = $this->parseColumn($definition, $name, $line, $endLine);

            if (isset($columns[$column->name])) {
                throw SchemaParseException::at(
                    'Column '.$column->name.' is defined twice in table '.$name,
                    null,
                    $line,
                );
            }

            $columns[$column->name] = $column;
            $this->collectInlineConstraints($column, $definition, $indexes, $checks);
        }

        if ($columns === []) {
            throw SchemaParseException::at('Table '.$name.' has no column definitions', null, $baseLine);
        }

        // A PRIMARY KEY column is implicitly NOT NULL in MySQL, and `SHOW CREATE
        // TABLE` prints it that way. The DDL relies on the implication (e.g.
        // `telemedicine_test.sql:59` writes no NOT NULL), so fold it in or every
        // such column reads as drift.
        foreach ($indexes as $index) {
            if ($index->type !== 'PRIMARY') {
                continue;
            }

            foreach ($index->columns as $keyColumn) {
                if (isset($columns[$keyColumn])) {
                    $columns[$keyColumn] = $columns[$keyColumn]->withNullable(false);
                }
            }
        }

        $engine = preg_match('/\bengine\s*=\s*([A-Za-z0-9_]+)/i', $trailer, $em) === 1
            ? strtolower($em[1])
            : null;

        return new TableSpec($name, $engine, $columns, $indexes, $foreignKeys, $checks, $baseLine);
    }

    /**
     * A table body must contain exactly one table's worth of definitions.
     *
     * The failure this catches is real: dropping one comma from a `CREATE TABLE`
     * makes the body swallow the rest of the file up to the next balanced `)`, and
     * the parser then reports a *different but plausible* model — 75 tables, 671
     * columns — instead of an error. Quoted literals are exempt, because
     * `telemedicine_test.sql:1121` legitimately contains `ENUM('create', ...)`.
     */
    private function assertBodyIsOneTable(string $body, string $table, int $line): void
    {
        foreach ($this->tokenise($body) as $token) {
            if (in_array(strtoupper($token), self::STATEMENT_KEYWORDS, true)) {
                throw SchemaParseException::at(
                    'Table '.$table.' has an unterminated body: it swallowed a '.strtoupper($token)
                    .' statement. A definition is almost certainly missing its trailing comma.',
                    null,
                    $line,
                );
            }
        }
    }

    /**
     * `ALTER TABLE ... ADD CONSTRAINT` carries the deferred foreign key
     * (`telemedicine_test.sql:1161-1163`), so it folds into the table it targets
     * rather than being ignored.
     *
     * @param  array<string, TableSpec>  $tables
     */
    private function applyAlter(string $statement, array &$tables, int $line): void
    {
        if (preg_match('/^alter\s+table\s+(`?[A-Za-z0-9_$]+`?)\s+(.*)$/is', $statement, $m) !== 1) {
            throw SchemaParseException::at('Unreadable ALTER TABLE: '.self::excerpt($statement), null, $line);
        }

        $tableName = $this->normaliser->identifier($m[1]);
        $table = $tables[$tableName] ?? null;

        if ($table === null) {
            throw SchemaParseException::at('ALTER TABLE targets unknown table '.$tableName, null, $line);
        }

        $body = trim($m[2], " \t\n\r;");
        $name = null;

        if (preg_match('/^add\s+constraint\s+(`?[A-Za-z0-9_$]+`?)\s+(.*)$/is', $body, $cm) === 1) {
            $name = $this->normaliser->identifier($cm[1]);
            $body = trim($cm[2]);
        } elseif (preg_match('/^add\s+(.*)$/is', $body, $am) === 1) {
            $body = trim($am[1]);
        } else {
            throw SchemaParseException::at('Unreadable ALTER TABLE clause: '.self::excerpt($body), null, $line);
        }

        if (preg_match('/^foreign\s+key\s*(\(.*?\))\s*references\s+(`?[A-Za-z0-9_$]+`?)\s*(\(.*?\))\s*(.*)$/is', $body, $fk) === 1) {
            [$onDelete, $onUpdate] = $this->referentialActions($fk[4] ?? '');

            $tables[$tableName] = $table->with(
                foreignKeys: [...$table->foreignKeys, new ForeignKeySpec(
                    $name,
                    $this->normaliser->identifierList($fk[1]),
                    $this->normaliser->identifier($fk[2]),
                    $this->normaliser->identifierList($fk[3]),
                    $onDelete,
                    $onUpdate,
                    $name !== null,
                    $line,
                )],
            );

            return;
        }

        if (preg_match('/^check\s*(\(.*)$/is', $body, $ck) === 1) {
            $cursor = 0;
            $expression = $this->normaliser->readGroup($ck[1], $cursor);

            $tables[$tableName] = $table->with(
                checks: [...$table->checks, new CheckSpec($name, $this->normaliser->expression($expression), $line)],
            );

            return;
        }

        if (preg_match('/^(unique\s*(?:key|index)?|index|key)\s*(?:(`?[A-Za-z0-9_$]+`?)\s*)?\((.*?)\)\s*$/is', $body, $ix) === 1) {
            $indexName = isset($ix[2]) && trim($ix[2]) !== '' ? $this->normaliser->identifier($ix[2]) : null;

            $tables[$tableName] = $table->with(
                indexes: [...$table->indexes, new IndexSpec(
                    str_starts_with(strtolower($ix[1]), 'unique') ? 'UNIQUE' : 'INDEX',
                    $indexName,
                    $this->normaliser->identifierList($ix[3]),
                    $indexName !== null,
                    $line,
                )],
            );

            return;
        }

        throw SchemaParseException::at(
            'Unsupported ALTER TABLE clause. Only ADD CONSTRAINT / FOREIGN KEY / CHECK / UNIQUE / INDEX / KEY are understood: '
                .self::excerpt($body),
            null,
            $line,
        );
    }

    /**
     * Table-level constraints: `PRIMARY KEY`, `UNIQUE [KEY] name`, `KEY`/`INDEX
     * name`, `FOREIGN KEY`, `CHECK`, optionally behind `CONSTRAINT name`.
     *
     * @param  list<IndexSpec>  $indexes
     * @param  list<ForeignKeySpec>  $foreignKeys
     * @param  list<CheckSpec>  $checks
     */
    private function parseTableConstraint(
        string $definition,
        int $line,
        bool $namesAreAuthoritative,
        array &$indexes,
        array &$foreignKeys,
        array &$checks,
    ): bool {
        $name = null;
        $body = $definition;

        if (preg_match('/^constraint\s+(`?[A-Za-z0-9_$]+`?)\s+(.*)$/is', $definition, $cm) === 1) {
            $name = $this->normaliser->identifier($cm[1]);
            $body = trim($cm[2]);
        }

        if (preg_match('/^primary\s+key\s*(\(.*\))\s*$/is', $body, $m) === 1) {
            $indexes[] = new IndexSpec('PRIMARY', 'PRIMARY', $this->normaliser->identifierList($m[1]), true, $line);

            return true;
        }

        if (preg_match('/^foreign\s+key\s*(\(.*?\))\s*references\s+(`?[A-Za-z0-9_$]+`?)\s*(\(.*?\))\s*(.*)$/is', $body, $m) === 1) {
            [$onDelete, $onUpdate] = $this->referentialActions($m[4] ?? '');

            $foreignKeys[] = new ForeignKeySpec(
                $name,
                $this->normaliser->identifierList($m[1]),
                $this->normaliser->identifier($m[2]),
                $this->normaliser->identifierList($m[3]),
                $onDelete,
                $onUpdate,
                $name !== null && $namesAreAuthoritative,
                $line,
            );

            return true;
        }

        if (preg_match('/^check\s*(\(.*\))\s*$/is', $body, $m) === 1) {
            $cursor = 0;
            $checks[] = new CheckSpec(
                $name,
                $this->normaliser->expression($this->normaliser->readGroup($m[1], $cursor)),
                $line,
            );

            return true;
        }

        if (preg_match('/^(unique\s*(?:key|index)?|index|key)\s*(?:(`?[A-Za-z0-9_$]+`?)\s*)?(\(.*\))\s*$/is', $body, $m) === 1) {
            $indexName = isset($m[2]) && trim($m[2]) !== '' ? $this->normaliser->identifier($m[2]) : null;

            $indexes[] = new IndexSpec(
                str_starts_with(strtolower($m[1]), 'unique') ? 'UNIQUE' : 'INDEX',
                $indexName,
                $this->normaliser->identifierList($m[3]),
                $indexName !== null && $namesAreAuthoritative,
                $line,
            );

            return true;
        }

        return false;
    }

    /**
     * `<name> <type> [UNSIGNED] [NOT NULL|NULL] [DEFAULT ...] [AUTO_INCREMENT]
     * [UNIQUE] [PRIMARY KEY] [COMMENT '...'] [CHECK (...)] [ON UPDATE ...]`.
     */
    private function parseColumn(string $definition, string $table, int $line, int $endLine): ColumnSpec
    {
        $i = 0;

        if ($definition[0] === '`' || $definition[0] === '"') {
            $quote = $definition[0];
            $close = strpos($definition, $quote, 1);

            if ($close === false) {
                throw SchemaParseException::at(
                    'Unterminated quoted column name in table '.$table.': '.self::excerpt($definition),
                    null,
                    $line,
                );
            }

            $name = substr($definition, 1, $close - 1);
            $i = $close + 1;
        } else {
            if (preg_match('/^\s*([A-Za-z0-9_$]+)/', $definition, $m) !== 1) {
                throw SchemaParseException::at(
                    'Unreadable column definition in table '.$table.': '.self::excerpt($definition),
                    null,
                    $line,
                );
            }

            $name = $m[1];
            $i = strlen($m[0]);
        }

        $name = strtolower($name);
        $typeStart = $i;
        $i = $this->skipType($definition, $i);
        $rawType = trim(substr($definition, $typeStart, $i - $typeStart));

        if ($rawType === '') {
            throw SchemaParseException::at('Column '.$name.' in table '.$table.' has no type', null, $line);
        }

        $type = $this->normaliser->decompose($rawType);
        $tokens = $this->tokenise(substr($definition, $i));

        $this->assertSingleDeclaration($tokens, $name, $table, $line);

        $nullable = true;
        $default = null;
        $autoIncrement = false;
        $onUpdate = null;

        for ($t = 0; $t < count($tokens); $t++) {
            $upper = strtoupper($tokens[$t]);

            if ($upper === 'NOT' && strtoupper($tokens[$t + 1] ?? '') === 'NULL') {
                $nullable = false;
                $t++;

                continue;
            }

            if ($upper === 'NULL') {
                $nullable = true;

                continue;
            }

            if ($upper === 'DEFAULT') {
                [$expression, $t] = $this->readValueExpression($tokens, $t + 1);
                $default = $this->normaliser->defaultValue($expression);

                continue;
            }

            if ($upper === 'ON' && strtoupper($tokens[$t + 1] ?? '') === 'UPDATE') {
                [$expression, $t] = $this->readValueExpression($tokens, $t + 2);
                $onUpdate = $this->normaliser->defaultValue($expression);

                continue;
            }

            if ($upper === 'AUTO_INCREMENT') {
                $autoIncrement = true;
            }
        }

        return new ColumnSpec(
            $name,
            $type['base'].($type['args'] === null ? '' : '('.$type['args'].')'),
            $type['unsigned'],
            $nullable,
            $default,
            $autoIncrement,
            $onUpdate,
            $line,
            max($line, $endLine),
        );
    }

    /**
     * A column definition carries exactly one bare type name. Finding a second one
     * means two declarations were merged - in practice a dropped trailing comma:
     *
     *     id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
     *     uuid CHAR(36) NOT NULL UNIQUE          <- no comma between them
     *
     * Without this the parser happily returns a plausible, wrong model (75 tables,
     * 671 columns) instead of an error, and the column silently disappears.
     *
     * @param  list<string>  $tokens  the definition tail, i.e. everything after the type
     */
    private function assertSingleDeclaration(array $tokens, string $column, string $table, int $line): void
    {
        $previousWasBareWord = false;

        foreach ($tokens as $token) {
            $isBareWord = $token !== '' && ctype_alpha($token[0]) && $token[0] !== "'" && $token[0] !== '"' && $token[0] !== '`';

            if ($isBareWord && $previousWasBareWord && in_array(strtoupper($token), self::BARE_TYPE_KEYWORDS, true)) {
                throw SchemaParseException::at(
                    'Column '.$column.' in table '.$table.' contains a second type name, so two declarations'
                    .' were merged into one. A trailing comma is almost certainly missing.',
                    null,
                    $line,
                );
            }

            $previousWasBareWord = $isBareWord;
        }
    }

    /**
     * Consume a column's type (including trailing `UNSIGNED` / `ZEROFILL`) and
     * return the offset just past it.
     */
    private function skipType(string $definition, int $i): int
    {
        $n = strlen($definition);
        $this->skipInlineSpaces($definition, $i);
        $start = $i;

        while ($i < $n) {
            $c = $definition[$i];

            if (ctype_alnum($c) || $c === '_') {
                $i++;

                continue;
            }

            if ($c === ' ') {
                $lookahead = $i;
                $this->skipInlineSpaces($definition, $lookahead);
                $next = $this->peekWord($definition, $lookahead);

                if ($next === '' || in_array($next, self::CLAUSE_KEYWORDS, true)) {
                    break;
                }

                // `double precision`, `character varying` — a word that is not a
                // clause keyword continues the type name.
                $i = $lookahead;

                continue;
            }

            break;
        }

        if ($i === $start) {
            return $start;
        }

        $this->skipInlineSpaces($definition, $i);

        if ($i < $n && $definition[$i] === '(') {
            $cursor = $i;
            $this->normaliser->readGroup($definition, $cursor);
            $i = $cursor;
        }

        // `TINYINT UNSIGNED` is part of the type: the unsigned flag is compared as
        // its own attribute, so it has to travel with the type string.
        while (true) {
            $lookahead = $i;
            $this->skipInlineSpaces($definition, $lookahead);
            $word = $this->peekWord($definition, $lookahead);

            if (! in_array($word, self::TYPE_MODIFIERS, true)) {
                break;
            }

            $i = $lookahead + strlen($word);
        }

        return $i;
    }

    private function skipInlineSpaces(string $s, int &$i): void
    {
        $n = strlen($s);

        while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t" || $s[$i] === "\n" || $s[$i] === "\r")) {
            $i++;
        }
    }

    private function peekWord(string $s, int $i): string
    {
        $n = strlen($s);
        $word = '';

        while ($i < $n && (ctype_alnum($s[$i]) || $s[$i] === '_')) {
            $word .= $s[$i];
            $i++;
        }

        return strtoupper($word);
    }

    /**
     * Split a definition tail into tokens: quoted literals and parenthesised
     * groups stay whole.
     *
     * @return list<string>
     */
    private function tokenise(string $subject): array
    {
        $tokens = [];
        $n = strlen($subject);
        $i = 0;

        while ($i < $n) {
            $c = $subject[$i];

            if (ctype_space($c)) {
                $i++;

                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $tokens[] = $this->normaliser->readQuoted($subject, $i);

                continue;
            }

            if ($c === '(') {
                $cursor = $i;
                $tokens[] = '('.$this->normaliser->readGroup($subject, $cursor).')';
                $i = $cursor;

                continue;
            }

            $word = '';

            while ($i < $n && ! ctype_space($subject[$i]) && ! in_array($subject[$i], ['(', ')', "'", '"', '`'], true)) {
                $word .= $subject[$i];
                $i++;
            }

            if ($word !== '') {
                $tokens[] = $word;

                continue;
            }

            // A stray `)` or similar that starts no token: consume it so the scan
            // always makes progress.
            $i++;
        }

        return $tokens;
    }

    /**
     * Read a `DEFAULT` / `ON UPDATE` value out of the token list.
     *
     * @param  list<string>  $tokens
     * @return array{0: string, 1: int} the value and the index of its last token
     */
    private function readValueExpression(array $tokens, int $start): array
    {
        $out = '';
        $end = $start - 1;

        for ($i = $start; $i < count($tokens); $i++) {
            if ($out !== '' && in_array(strtoupper($tokens[$i]), self::CLAUSE_KEYWORDS, true)) {
                break;
            }

            $out .= ($out === '' ? '' : ' ').$tokens[$i];
            $end = $i;
        }

        if ($out === '') {
            throw SchemaParseException::at('DEFAULT / ON UPDATE with no value', null, 0);
        }

        return [$out, $end];
    }

    /**
     * `ON DELETE CASCADE` / `ON UPDATE SET NULL`; MySQL's implicit default is
     * `RESTRICT` for both.
     *
     * @return array{0: string, 1: string}
     */
    private function referentialActions(string $tail): array
    {
        return [
            $this->actionFrom($tail, 'delete'),
            $this->actionFrom($tail, 'update'),
        ];
    }

    private function actionFrom(string $tail, string $event): string
    {
        if (preg_match('/\bon\s+'.$event.'\s+(cascade|set\s+null|no\s+action|restrict|set\s+default)/i', $tail, $m) !== 1) {
            return 'RESTRICT';
        }

        return strtoupper((string) preg_replace('/\s+/', ' ', trim($m[1])));
    }

    /**
     * Inline `UNIQUE`, inline `PRIMARY KEY` and inline `CHECK` on a column.
     *
     * @param  list<IndexSpec>  $indexes
     * @param  list<CheckSpec>  $checks
     */
    private function collectInlineConstraints(ColumnSpec $column, string $definition, array &$indexes, array &$checks): void
    {
        // The `COMMENT '...'` text is data, so it must not be scanned for keywords
        // (`telemedicine_test.sql:538` carries `COMMENT 'NULL = ...'`).
        $tokens = $this->tokenise($definition);

        for ($t = 0; $t < count($tokens); $t++) {
            $upper = strtoupper($tokens[$t]);

            if ($upper === 'COMMENT') {
                array_splice($tokens, $t, 2);
                $t--;

                continue;
            }

            if ($upper === 'UNIQUE') {
                $indexes[] = new IndexSpec('UNIQUE', null, [$column->name], false, $column->line);

                continue;
            }

            if ($upper === 'PRIMARY' && strtoupper($tokens[$t + 1] ?? '') === 'KEY') {
                $indexes[] = new IndexSpec('PRIMARY', 'PRIMARY', [$column->name], true, $column->line);
                $t++;

                continue;
            }

            if ($upper === 'CHECK' && isset($tokens[$t + 1])) {
                $cursor = 0;
                $expression = $this->normaliser->readGroup($tokens[$t + 1], $cursor);

                // Declaration order is the contract: MySQL names these
                // `<table>_chk_1..n` from it.
                $checks[] = new CheckSpec(null, $this->normaliser->expression($expression), $column->line);
                $t++;
            }
        }
    }

    /**
     * Remove comments while preserving byte offsets and line numbers: comment
     * characters become spaces and newlines are kept, so an offset found in the
     * cleaned text still points at the right line in the original file.
     */
    private function stripComments(string $sql): string
    {
        $n = strlen($sql);
        $out = $sql;
        $i = 0;

        while ($i < $n) {
            $c = $sql[$i];

            if ($c === "'" || $c === '"' || $c === '`') {
                $this->normaliser->readQuoted($sql, $i);

                continue;
            }

            if (($c === '-' && $sql[$i + 1] === '-') || $c === '#') {
                $end = $i;
                while ($end < $n && $sql[$end] !== "\n") {
                    $end++;
                }
                $out = substr_replace($out, str_repeat(' ', $end - $i), $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '/' && $sql[$i + 1] === '*') {
                $close = strpos($sql, '*/', $i + 2);
                $end = $close === false ? $n : $close + 2;
                $blanked = preg_replace('/[^\n]/', ' ', substr($sql, $i, $end - $i)) ?? '';
                $out = substr_replace($out, $blanked, $i, $end - $i);
                $i = $end;

                continue;
            }

            $i++;
        }

        return $out;
    }

    /**
     * @return list<array{sql: string, offset: int}>
     */
    private function statements(string $text): array
    {
        $statements = [];
        $n = strlen($text);
        $depth = 0;
        $start = 0;
        $i = 0;

        while ($i < $n) {
            $c = $text[$i];

            if ($c === "'" || $c === '"' || $c === '`') {
                $this->normaliser->readQuoted($text, $i);

                continue;
            }

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === ';' && $depth === 0) {
                $statements[] = ['sql' => substr($text, $start, $i - $start), 'offset' => $start];
                $start = $i + 1;
            }

            $i++;
        }

        if (trim(substr($text, $start)) !== '') {
            $statements[] = ['sql' => substr($text, $start), 'offset' => $start];
        }

        return $statements;
    }

    /**
     * @param  array<int, string>  $match
     */
    private function isCreateView(string $statement, array &$match): bool
    {
        $pattern = '/^create\s+(?:or\s+replace\s+)?(?:algorithm\s*=\s*\w+\s*)?'
            .'(?:definer\s*=\s*\S+\s*)?(?:sql\s+security\s+\w+\s+)?view\s+(`?[A-Za-z0-9_$]+`?)/i';

        return preg_match($pattern, $statement, $match) === 1;
    }

    private static function excerpt(string $subject, int $limit = 90): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $subject));

        return strlen($flat) <= $limit ? $flat : substr($flat, 0, $limit).'...';
    }
}
