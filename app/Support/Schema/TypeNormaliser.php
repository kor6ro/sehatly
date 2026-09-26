<?php

declare(strict_types=1);

namespace App\Support\Schema;

/**
 * Canonicalises MySQL type strings, default values and expressions.
 *
 * The reference SQL is hand-written and the live schema is machine-generated, so
 * the two disagree cosmetically all the time: `INT` vs `int`, `int(11)` vs `int`,
 * backticks vs none, `KEY` vs `INDEX`, `DEFAULT 0` vs `DEFAULT '0'`. Everything
 * cosmetic is folded away here; everything semantic (base type, unsigned,
 * nullability, default, referential action) survives so the differ can still
 * report it.
 *
 * Case is folded only *outside* quoted literals. An `ENUM('L','P')` value is data,
 * not spelling, so `enum('l','p')` is a real difference and must survive.
 */
final class TypeNormaliser
{
    /**
     * Integer types whose display width MySQL 8.0.19 deprecated. It no longer
     * affects storage or comparison, so `int(11)` and `int` really are the same
     * type and folding them is correct rather than convenient.
     *
     * @var list<string>
     */
    private const INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    /**
     * Spellings that are not type names on their own.
     *
     * @var array<string, string>
     */
    private const TYPE_ALIASES = [
        'integer' => 'int',
        'dec' => 'decimal',
        'fixed' => 'decimal',
        'numeric' => 'decimal',
        'bool' => 'tinyint',
        'boolean' => 'tinyint',
        'real' => 'double',
        'double precision' => 'double',
        'character varying' => 'varchar',
        'character' => 'char',
        'national varchar' => 'varchar',
        'national char' => 'char',
        'long varchar' => 'mediumtext',
        'long' => 'mediumtext',
    ];

    public function __construct(private readonly string $file = '(string)') {}

    /**
     * Split a raw type string into base type, arguments and the unsigned flag.
     *
     * @return array{base: string, args: string|null, unsigned: bool}
     */
    public function decompose(string $raw): array
    {
        $s = trim($raw);
        $n = strlen($s);
        $i = 0;

        while ($i < $n && ($s[$i] === '`' || $s[$i] === ' ' || $s[$i] === '"' || $s[$i] === "'")) {
            $i++;
        }

        $first = strtolower($this->readWord($s, $i));

        if ($first === '') {
            throw SchemaParseException::at('Empty type string', $this->file, $this->lineAt($s, $i));
        }

        // Two-word spellings such as `double precision` and `character varying`.
        $checkpoint = $i;
        $this->skipSpaces($s, $i);
        $second = strtolower($this->readWord($s, $i));
        $word = $first.' '.$second;

        if ($second !== '' && isset(self::TYPE_ALIASES[$word])) {
            $word = self::TYPE_ALIASES[$word];
        } elseif (isset(self::TYPE_ALIASES[$first])) {
            $word = self::TYPE_ALIASES[$first];
            $i = $checkpoint;
        } else {
            $word = $first;
            $i = $checkpoint;
        }

        $this->skipSpaces($s, $i);

        $args = null;

        if ($i < $n && $s[$i] === '(') {
            // Read verbatim: ENUM/SET members are data and keep their case.
            $args = trim($this->readGroup($s, $i));
        }

        if ($args !== null) {
            $args = in_array($word, self::INTEGER_TYPES, true)
                ? null
                : $this->canonicalArguments($word, $args);
        }

        $trailing = strtolower(substr($s, $i));

        return [
            'base' => $word,
            'args' => $args,
            // ZEROFILL implies UNSIGNED in MySQL.
            'unsigned' => str_contains($trailing, 'unsigned') || str_contains($trailing, 'zerofill'),
        ];
    }

    /**
     * Canonical type string, e.g. `varchar(255)`, `enum('L','P')`, `decimal(12,2)`.
     *
     * `unsigned` is deliberately kept out of the string: it is compared as its own
     * attribute so the report can say exactly which one flipped.
     */
    public function type(string $raw): string
    {
        $parts = $this->decompose($raw);

        return $parts['args'] === null ? $parts['base'] : $parts['base'].'('.$parts['args'].')';
    }

    public function isUnsigned(string $raw): bool
    {
        return $this->decompose($raw)['unsigned'];
    }

    /**
     * Canonicalise a `DEFAULT` value.
     *
     * `null` means "no DEFAULT clause at all"; the string `NULL` means
     * `DEFAULT NULL`. The distinction is load-bearing: `telemedicine_test.sql:148`
     * declares `dihapus_at TIMESTAMP NULL DEFAULT NULL` and MySQL echoes
     * `DEFAULT NULL`, whereas a column with no default clause echoes nothing.
     *
     * Quoting is normalised because MySQL 8 quotes numeric defaults in
     * `SHOW CREATE TABLE` (`DEFAULT '0'`) while the DDL writes `DEFAULT 0`.
     */
    public function defaultValue(string $raw): string
    {
        $value = trim($raw);

        if ($value === '') {
            throw SchemaParseException::at('Empty DEFAULT value', $this->file, 0);
        }

        if (strcasecmp($value, 'NULL') === 0) {
            return 'NULL';
        }

        if (self::isQuoted($value)) {
            $value = $this->unquote($value);

            return is_numeric($value) ? $this->canonicalNumber($value) : $this->quote($value);
        }

        if (is_numeric($value)) {
            return $this->canonicalNumber($value);
        }

        if (preg_match('/^current_timestamp(\s*\(\s*\d*\s*\))?$/i', $value, $m) === 1) {
            $precision = (int) trim($m[1] ?? '', ' ()');

            return 'CURRENT_TIMESTAMP'.($precision > 0 ? '('.$precision.')' : '');
        }

        if (preg_match('/^(now|localtime|localtimestamp|current_date|current_time)(\s*\(\s*\d*\s*\))?$/i', $value, $m) === 1) {
            return strtoupper($m[1]).'()';
        }

        return strtoupper($value);
    }

    /**
     * Canonicalise a `CHECK` expression so MySQL's re-rendered form
     * (`` CHECK ((`rating` between 1 and 5)) ``) matches the DDL's
     * `rating BETWEEN 1 AND 5`. Constraint *names* are engine-generated
     * (`ulasan_dokter_chk_1`), so the expression is the only thing worth comparing.
     *
     * Backticks are pure quoting and are dropped; case is folded outside string
     * literals.
     */
    public function expression(string $raw): string
    {
        $s = $raw;
        $n = strlen($s);
        $out = '';

        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];

            if ($c === "'" || $c === '"' || $c === '`') {
                $out .= $this->readQuoted($s, $i);
                $i--;

                continue;
            }

            $out .= strtolower($c);
        }

        $out = $this->stripWrappingParens($out);

        return trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $out)));
    }

    /**
     * Canonicalise an identifier: unquote and lower-case.
     */
    public function identifier(string $raw): string
    {
        $value = trim($raw);

        if (self::isQuoted($value)) {
            $value = $this->unquote($value);
        }

        return strtolower(str_replace('`', '', $value));
    }

    /**
     * @return list<string>
     */
    public function identifierList(string $raw): array
    {
        $inner = trim($raw);

        if (str_starts_with($inner, '(')) {
            $i = 0;
            $inner = trim($this->readGroup($inner, $i));
        }

        if ($inner === '') {
            return [];
        }

        $out = [];

        foreach ($this->splitTopLevel($inner, ',') as $part) {
            $identifier = $this->identifier($part);

            if ($identifier !== '') {
                $out[] = $identifier;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function splitTopLevel(string $subject, string $separators): array
    {
        return array_map(
            static fn (array $part): string => $part['text'],
            $this->splitTopLevelWithOffsets($subject, $separators),
        );
    }

    /**
     * Split on separators that sit outside quotes and outside parentheses, keeping
     * each part's byte offset so the caller can recover source line numbers.
     *
     * @return list<array{text: string, offset: int}>
     */
    public function splitTopLevelWithOffsets(string $subject, string $separators): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $n = strlen($subject);

        for ($i = 0; $i < $n; $i++) {
            $c = $subject[$i];

            if ($c === "'" || $c === '"' || $c === '`') {
                $this->readQuoted($subject, $i);
                // readQuoted leaves $i *past* the closing delimiter; a `for` loop
                // increments again, so step back or the character right after the
                // literal is never examined.
                $i--;

                continue;
            }

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }

            if ($depth === 0 && str_contains($separators, $c)) {
                $parts[] = ['text' => substr($subject, $start, $i - $start), 'offset' => $start];
                $start = $i + 1;
            }
        }

        $parts[] = ['text' => substr($subject, $start), 'offset' => $start];

        return $parts;
    }

    /**
     * Read a parenthesised group starting at `$s[$i] === '('` and return its
     * contents verbatim, advancing `$i` past the closing parenthesis.
     */
    public function readGroup(string $s, int &$i): string
    {
        return $this->readGroupWithOffset($s, $i)[0];
    }

    /**
     * As {@see readGroup()}, but also reports the offset of the first character
     * inside the parentheses so callers can map a sub-string back to a line.
     *
     * @return array{0: string, 1: int}
     */
    public function readGroupWithOffset(string $s, int &$i): array
    {
        $n = strlen($s);
        $depth = 0;
        $start = $i + 1;
        $line = $this->lineAt($s, $i);

        while ($i < $n) {
            $c = $s[$i];

            if ($c === "'" || $c === '"' || $c === '`') {
                $this->readQuoted($s, $i);

                continue;
            }

            if ($c === '(') {
                $depth++;

                if ($depth === 1) {
                    $start = $i + 1;
                }

                $i++;

                continue;
            }

            if ($c === ')') {
                $depth--;

                if ($depth === 0) {
                    $contents = substr($s, $start, $i - $start);
                    $i++;

                    return [$contents, $start];
                }

                $i++;

                continue;
            }

            $i++;
        }

        throw SchemaParseException::at('Unbalanced parentheses', $this->file, $line);
    }

    /**
     * Copy one quoted run verbatim (delimiters included) and advance `$i` past it.
     *
     * `$i` is by reference on purpose: forgetting to store the returned offset is
     * how a scanner ends up spinning on the opening quote forever.
     */
    public function readQuoted(string $s, int &$i): string
    {
        $quote = $s[$i];
        $n = strlen($s);
        $value = '';
        $i++;

        while ($i < $n) {
            $c = $s[$i];

            if ($c === '\\' && $i + 1 < $n) {
                $value .= $s[$i].$s[$i + 1];
                $i += 2;

                continue;
            }

            if ($c === $quote) {
                if ($i + 1 < $n && $s[$i + 1] === $quote) {
                    $value .= $quote.$quote;
                    $i += 2;

                    continue;
                }

                $i++;

                return $quote.$value.$quote;
            }

            $value .= $c;
            $i++;
        }

        throw SchemaParseException::at('Unterminated quoted literal', $this->file, $this->lineAt($s, $i));
    }

    public function lineAt(string $subject, int $offset): int
    {
        return substr_count($subject, "\n", 0, max(0, min($offset, strlen($subject)))) + 1;
    }

    private function skipSpaces(string $s, int &$i): void
    {
        $n = strlen($s);

        while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t" || $s[$i] === "\n" || $s[$i] === "\r")) {
            $i++;
        }
    }

    private function readWord(string $s, int &$i): string
    {
        $n = strlen($s);
        $start = $i;

        while ($i < $n && (ctype_alnum($s[$i]) || $s[$i] === '_')) {
            $i++;
        }

        return substr($s, $start, $i - $start);
    }

    /**
     * Normalise a type's parenthesised argument list. Quoted members keep their
     * case; numeric widths and precisions fold to lower case without spaces.
     */
    private function canonicalArguments(string $base, string $args): string
    {
        $out = [];

        foreach ($this->splitTopLevel($args, ',') as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $out[] = self::isQuoted($part)
                ? $part
                : strtolower((string) preg_replace('/\s+/', '', $part));
        }

        if ($out === [] && in_array($base, ['enum', 'set'], true)) {
            throw SchemaParseException::at('Empty '.strtoupper($base).' value list', $this->file, 0);
        }

        return implode(',', $out);
    }

    private function stripWrappingParens(string $subject): string
    {
        $s = trim($subject);

        while (str_starts_with($s, '(') && str_ends_with($s, ')') && $this->wrapsWholeString($s)) {
            $s = trim(substr($s, 1, -1));
        }

        return $s;
    }

    private function wrapsWholeString(string $s): bool
    {
        $depth = 0;
        $n = strlen($s);

        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];

            if ($c === "'" || $c === '"' || $c === '`') {
                $this->readQuoted($s, $i);
                $i--;

                continue;
            }

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;

                if ($depth === 0 && $i !== $n - 1) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    private static function isQuoted(string $value): bool
    {
        if (strlen($value) < 2) {
            return false;
        }

        $first = $value[0];

        return ($first === "'" || $first === '"' || $first === '`') && $value[strlen($value) - 1] === $first;
    }

    private function unquote(string $value): string
    {
        return str_replace('\\', '', substr($value, 1, -1));
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * `4500.00`, `4500` and `'4500.00'` describe the same stored DECIMAL, so they
     * fold to one spelling. Trailing-zero noise goes; precision never does.
     */
    private function canonicalNumber(string $value): string
    {
        if (preg_match('/^[+-]?\d+$/', $value) === 1) {
            return (string) (int) $value;
        }

        if (is_numeric($value)) {
            $normalised = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');

            return $normalised === '' || $normalised === '-' ? '0' : $normalised;
        }

        return $value;
    }
}
