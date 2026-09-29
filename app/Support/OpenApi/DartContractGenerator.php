<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

/**
 * Renders the Dart half of the contract from the same inventory the YAML uses.
 *
 * ## Why this is PHP and not a Dart script
 *
 * The Dart side is generated from the SAME {@see RouteInventory} as
 * `docs/openapi.yaml`, in the same process. A second generator -- a Dart script
 * with its own YAML parser, reading the committed document -- would be a third
 * place the contract lives, and a third thing that can go stale: it could be run
 * against a document that has not been regenerated, and nothing would say so,
 * because the output would still parse. Generating both artefacts from one walk
 * makes "the document and the DTOs disagree" unrepresentable.
 *
 * It also keeps the drift check honest: `--check` compares the Dart files by
 * SHA-256 against a fresh render exactly as it does the YAML, so a hand-edit to
 * `enums.dart` is caught by the same command that catches a hand-edit to the
 * document.
 *
 * ## What is generated, and what is deliberately NOT
 *
 * Generated into `packages/sehatly_api_client/lib/src/generated/`:
 *
 * - `enums.dart` -- one Dart `enum` per MySQL ENUM column, values in the DDL's
 *   own order. This is the mobile team's compile-time-checked status vocabulary,
 *   read from `docs/enums.json`, which `sehatly:enums` cross-checks against
 *   `telemedicine_test.sql` on every run.
 * - `request_bodies.dart` -- one class per `FormRequest`, whose required fields
 *   are constructor parameters, so omitting one is a compile error rather than a
 *   422. A `Rule::in([...])` becomes a `static const Set<String>` of accepted
 *   values.
 * - `paths_table.dart` -- one top-level `const String` per path, with the
 *   `{placeholders}` intact, plus the methods each answers. A call site that
 *   hand-builds a URL can miss a segment, a slash or a leading `/`; one that
 *   interpolates a constant cannot.
 *
 * NOT generated, and this is the important half:
 *
 * - **Response payload DTOs.** `docs/openapi.yaml` types every response's `data`
 *   as an untyped `object`, and that is not a shortcut taken here. Deriving a
 *   response shape would mean RUNNING the application -- or, far worse,
 *   duplicating every `App\Http\Resources\*` class in a second place. The
 *   hand-written DTOs in `lib/src/model/dto.dart` are the response side, each
 *   naming the resource it was transcribed from, and the package's own tests
 *   cover them. Generating a competing set would recreate exactly the
 *   two-transcriptions problem this task exists to remove.
 * - **The envelope.** `lib/src/core/api_envelope.dart` already parses it, and
 *   the envelope is the one shape the contract fixes. A generated copy would be
 *   a second parser for the same body.
 *
 * ## The rendering is deterministic for the same reasons the YAML is
 *
 * Keys sorted with `strcmp`, no timestamps, no absolute paths, LF endings, one
 * trailing newline, and ENUM values in `docs/enums.json` order rather than
 * sorted -- MySQL's numeric index depends on that order. Two runs over an
 * unchanged route table produce identical bytes, which is what lets `--check`
 * be a byte comparison rather than a fuzzy one.
 */
final class DartContractGenerator
{
    /**
     * The generated file names, in the order they are written.
     */
    public const FILES = ['enums.dart', 'request_bodies.dart', 'paths_table.dart'];

    /**
     * @param  array<string, list<string>>  $enums
     * @return array<string, string> file name => contents
     */
    public function render(RouteInventory $inventory, array $enums): array
    {
        return [
            'enums.dart' => $this->enums($enums),
            'request_bodies.dart' => $this->requestBodies($inventory),
            'paths_table.dart' => $this->paths($inventory),
        ];
    }

    /**
     * Every MySQL ENUM column as a Dart `enum`.
     *
     * The Dart member names are the DDL values verbatim rather than camelCased:
     * a status the database spells `menunggu_pembayaran` must be spelled the same
     * way on the wire, and a renamed member would break every `==` against a
     * parsed string while still compiling. `wireValue` carries the same string,
     * so a client reading a value this build does not know can still render it.
     *
     * @param  array<string, list<string>>  $enums
     */
    private function enums(array $enums): string
    {
        $blocks = [];

        foreach ($enums as $column => $values) {
            [$table, $name] = explode('.', (string) $column, 2);
            $class = 'Enum'.$this->pascal($table).$this->pascal($name);
            $values = array_values($values);

            $members = [];
            $taken = [];

            foreach ($values as $value) {
                $member = $this->identifier($value, $taken);
                $taken[] = $member;
                $members[$value] = $member;
            }

            $body = [];
            $body[] = '/// The MySQL `ENUM '.$column.'` as declared in `telemedicine_test.sql`.';
            $body[] = '///';
            $body[] = '/// Members are in DECLARATION ORDER, because MySQL\'s numeric index depends on';
            $body[] = '/// it and a reordering here would be a silent type change.';
            $body[] = '///';
            $body[] = '/// The member NAME is lowerCamelCase for Dart, and [wireValue] is the DDL value';
            $body[] = '/// verbatim. A rename of the name alone would compile; a rename of [wireValue] would';
            $body[] = '/// stop parsing what the server sends, which is why the wire string is carried';
            $body[] = '/// separately rather than assumed from the name.';
            $body[] = 'enum '.$class.' {';

            $last = array_key_last($members);

            foreach ($members as $value => $member) {
                $body[] = '  /// The `'.$value.'` value.';
                // The LAST value is terminated with `;` rather than `,`. Dart's
                // enhanced-enum grammar only accepts a constructor after a
                // semicolon-terminated member list; with a trailing comma the
                // parser ends the enum body and every value above becomes an
                // `extra_positional_arguments` error -- 319 of them across this
                // file, all of them a consequence of one character. Verified
                // with a two-member enum: `,` produces two errors plus a cascade,
                // `;` produces none.
                $body[] = '  '.$member.'('.$this->dartString($value).')'.($value === $last ? ';' : ',');
            }

            $body[] = '';
            $body[] = '  const '.$class.'(this.wireValue);';
            $body[] = '';
            $body[] = '  /// The value exactly as it appears on the wire.';
            $body[] = '  final String wireValue;';
            $body[] = '';
            $body[] = '  /// The DDL key this enum was generated from.';
            $body[] = $this->declaration('  ', 'static const String', 'column', $this->dartString($column));
            $body[] = '';
            $body[] = '  /// Every value, in declaration order.';
            $body[] = '';
            $body[] = '  /// Not named `values`: Dart reserves that name inside an enum, and a member';
            $body[] = '  /// called `values` is a compile error rather than a shadow.';
            $body = array_merge(
                $body,
                $this->collection(
                    '  ',
                    'static const List<'.$class.'> members',
                    '<'.$class.'>[',
                    ']',
                    array_values($members),
                ),
            );
            $body[] = '';
            $body[] = '  /// The wire values as a set, for a `contains` check against a query parameter.';
            $body = array_merge(
                $body,
                $this->collection(
                    '  ',
                    'static const Set<String> wireValues',
                    '<String>{',
                    '}',
                    array_map(fn (string $v): string => $this->dartString($v), $values),
                ),
            );
            $body[] = '';
            $body[] = '  /// Parses a wire value, or returns `null` for one this enum does not declare.';
            $body[] = '  ///';
            $body[] = '  /// Returns `null` rather than throwing on purpose: a value outside the set is a';
            $body[] = '  /// real possibility while the database has gained a member the app has not been';
            $body[] = '  /// rebuilt for, and the caller usually wants to render the raw string rather than';
            $body[] = '  /// crash on a screen.';
            $body[] = '  static '.$class.'? tryParse(String? raw) {';
            $body[] = '    for (final '.$class.' candidate in members) {';
            $body[] = '      if (candidate.wireValue == raw) {';
            $body[] = '        return candidate;';
            $body[] = '      }';
            $body[] = '    }';
            $body[] = '';
            $body[] = '    return null;';
            $body[] = '  }';
            $body[] = '}';

            $blocks[] = implode("\n", $body);
        }

        return $this->file([
            '// One Dart enum per MySQL ENUM column, in the declaration order the DDL gives,',
            '// because the numeric index a migration relies on depends on that order. The values are',
            '// read from `docs/enums.json`, which `php artisan sehatly:enums` generates from the',
            '// live `information_schema` AND cross-checks against `telemedicine_test.sql` -- so a',
            '// value here that the database would reject with a 1264 cannot survive a full run.',
            '//',
            '// Nothing in this file imports Flutter, and nothing in this package does either.',
        ], implode("\n\n", $blocks));
    }

    /**
     * One class per `FormRequest`, holding its required and optional fields.
     */
    private function requestBodies(RouteInventory $inventory): string
    {
        $blocks = [];
        $seen = [];

        foreach ($inventory->operations() as $operation) {
            $class = $operation['form_request'];

            if ($class === null) {
                continue;
            }

            $name = $this->shortName((string) $class).'Body';

            // Three operations share a `FormRequest` through a base class, so one
            // class per CLASS rather than one per operation.
            if (in_array($name, $seen, true)) {
                continue;
            }

            $seen[] = $name;

            $rules = $operation['rules'];
            $details = $operation['rule_details'];

            $required = [];
            $optional = [];
            $sets = [];
            $prohibited = [];

            foreach ($rules as $field => $ruleSet) {
                $field = (string) $field;
                $enum = $this->closedSet($ruleSet, $details[$field] ?? []);

                if ($enum !== null) {
                    $sets[$field] = $enum;
                }

                if (in_array('prohibited', $ruleSet, true)) {
                    $prohibited[] = $field;
                } elseif ($this->isRequired($ruleSet)) {
                    $required[] = $field;
                } else {
                    $optional[] = $field;
                }
            }

            $blocks[] = $this->requestBodyClass($name, (string) $class, $required, $optional, $sets, $prohibited, $rules);
        }

        return $this->file([
            '// One class per `App\Http\Requests\*`, with the required fields as constructor',
            '// parameters. Read from each class\'s `rules()` at generation time, so a field added to a',
            '// `FormRequest` appears here on the next run and one removed disappears.',
            '//',
            '// ## What these classes are FOR, and what they are not',
            '//',
            '// They type the REQUEST side, which is the half of the contract the server fully',
            '// describes: a required field is required, a `Rule::in([...])` is a closed set, a',
            '// `prohibited` field is one that must never be sent.',
            '//',
            '// They do NOT type the RESPONSE side. `docs/openapi.yaml` declares every `data` as an',
            '// untyped object on purpose, and `lib/src/model/dto.dart` holds the response DTOs, each',
            '// naming the `App\Http\Resources\*` class it was transcribed from. Generating a competing',
            '// set here would recreate the two-transcriptions problem this whole task exists to',
            '// remove.',
        ], implode("\n\n", $blocks));
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @param  array<string, list<string>>  $sets
     * @param  list<string>  $prohibited
     */
    private function requestBodyClass(
        string $name,
        string $formRequest,
        array $required,
        array $optional,
        array $sets,
        array $prohibited,
        array $rules,
    ): string {
        $fields = array_merge($required, $optional);
        $identifiers = [];

        foreach ($fields as $field) {
            $identifiers[$field] = $this->identifier($field, array_values($identifiers));
        }

        $body = [];
        $body[] = '/// The request body `'.$formRequest.'` validates.';
        $body[] = '///';
        $body[] = '/// Every field below was read from that class\'s `rules()`. A `required` rule is a';
        $body[] = '/// constructor parameter, so omitting it is a compile error rather than a 422, and the';
        $body[] = '/// Dart type is derived from the type rules, so a `boolean` field cannot be handed a';
        $body[] = '/// `String`.';
        $body[] = '///';
        $body[] = '/// [toJson] emits the WIRE names, which for a dotted Laravel attribute path is the';
        $body[] = '/// dotted path itself -- the same string `ValidationException::errors()` uses, so a 422';
        $body[] = '/// message maps back to the field that caused it.';
        $body[] = 'class '.$name.' {';
        $body[] = '  /// The `FormRequest` class these rules were read from.';
        $body[] = $this->declaration('  ', 'static const String', 'formRequest', $this->dartString($formRequest));

        $body[] = '';
        $body[] = '  /// Creates the body.';
        $body[] = '  ///';
        $body[] = '  /// Required fields are `required this`, so omitting one is a compile error';
        $body[] = '  /// rather than a 422. Optional fields are plain named parameters defaulting to';
        $body[] = '  /// `null`, which is what keeps every `final` field initialised -- a body class with';
        $body[] = '  /// NO required field (the reference endpoints read their rules off the route name, so';
        $body[] = '  /// several are parameterless on some paths) would otherwise have uninitialised finals.';

        if ($fields === []) {
            $body[] = '  const '.$name.'();';
        } else {
            $parameters = array_merge(
                array_map(
                    static fn (string $f): string => 'required this.'.$identifiers[$f],
                    $required,
                ),
                array_map(
                    static fn (string $f): string => 'this.'.$identifiers[$f],
                    $optional,
                ),
            );

            // One parameter per line, for the same reason as {@see collection()}.
            $body[] = '  const '.$name.'({';

            foreach ($parameters as $parameter) {
                $body[] = '    '.$parameter.',';
            }

            $body[] = '  });';
        }

        foreach ($fields as $field) {
            $body[] = '';
            $body[] = '  /// `'.$field.'`'.(isset($sets[$field]) ? ', a closed set on the server.' : '.');
            $body[] = '  final '.$this->dartType($rules[$field] ?? []).' '.$identifiers[$field].';';
        }

        foreach ($sets as $field => $values) {
            $body[] = '';
            $body[] = '  /// The values the server accepts for `'.$field.'`, read from its `Rule::in`.';
            $body[] = '  ///';
            $body[] = '  /// A `Set<String>` rather than a Dart enum on purpose: the rule\'s values are';
            $body[] = '  /// data rather than a compile-time vocabulary, so the server may gain a member';
            $body[] = '  /// without this package being released, and a set keeps the check honest';
            $body[] = '  /// instead of failing to compile against a list it has never seen.';
            $body = array_merge(
                $body,
                $this->collection(
                    '  ',
                    'static const Set<String> '.$identifiers[$field].'Allowed',
                    '<String>{',
                    '}',
                    array_map(fn (string $v): string => $this->dartString($v), $values),
                ),
            );
        }

        $body[] = '';
        $body[] = '  /// The declared field names, required first, then optional.';
        $body = array_merge(
            $body,
            $this->collection(
                '  ',
                'static const List<String> fields',
                '<String>[',
                ']',
                array_map(fn (string $f): string => $this->dartString($f), $fields),
            ),
        );
        $body[] = '';
        $body[] = '  /// The fields the server REJECTS when sent. A tenant key, in this project, is';
        $body[] = '  /// written from the caller\'s own row, so sending one is a validation error.';
        $body = array_merge(
            $body,
            $this->collection(
                '  ',
                'static const List<String> prohibitedFields',
                '<String>[',
                ']',
                array_map(fn (string $f): string => $this->dartString($f), $prohibited),
            ),
        );
        $body[] = '';
        $body[] = '  /// The wire body, omitting every field left `null`.';
        $body[] = '  ///';
        $body[] = '  /// Omission rather than an explicit `null` is deliberate and matches the server:';
        $body[] = '  /// Laravel treats `sometimes|nullable` and `required` differently, and sending an';
        $body[] = '  /// explicit `null` for a `sometimes` field is not the same request as omitting it.';
        if ($fields === []) {
            $body[] = '  /// Always an empty map: this request declares no field on every path it';
            $body[] = '  /// serves, because its rules are derived from the route name.';
            $body[] = '  Map<String, Object?> toJson() => <String, Object?>{};';
            $body[] = '}';

            return implode("\n", $body);
        }

        $body[] = '  Map<String, Object?> toJson() {';
        $body[] = '    return <String, Object?>{';

        foreach ($fields as $field) {
            $body[] = '      if ('.$identifiers[$field].' != null) '.$this->dartString($field).': '.$identifiers[$field].',';
        }

        $body[] = '    };';
        $body[] = '  }';
        $body[] = '}';

        return implode("\n", $body);
    }

    /**
     * The Dart type for a field, from the Laravel type rules.
     *
     * Only the rules that ARE the type are consulted, in the narrowest-first
     * order, and anything unrecognised falls back to `Object?`. A fallback rather
     * than a guess: inventing `String?` for a field whose rules name something
     * this mapping does not know would be a type that rejects a legitimate
     * request, which is worse than one that accepts anything.
     *
     * @param  list<string>  $ruleSet
     */
    private function dartType(array $ruleSet): string
    {
        foreach (['boolean' => 'bool?', 'integer' => 'int?', 'array' => 'List<Object?>?'] as $rule => $type) {
            if (in_array($rule, $ruleSet, true)) {
                return $type;
            }
        }

        return 'Object?';
    }

    /**
     * The path templates the route table registered, with the methods each answers.
     */
    private function paths(RouteInventory $inventory): string
    {
        $byPath = [];

        foreach ($inventory->operations() as $operation) {
            $path = (string) $operation['path'];
            $byPath[$path] ??= [];
            $byPath[$path][strtoupper((string) $operation['method'])] = $operation['name'];
        }

        ksort($byPath, SORT_STRING);

        $blocks = [];

        foreach ($byPath as $path => $methods) {
            ksort($methods, SORT_STRING);

            $const = $this->pathConst((string) $path);

            $body = [];
            $body[] = '/// `'.$path.'`';
            $body[] = '///';
            $body[] = '/// Answers: '.implode(', ', array_map(
                static fn (string $method, ?string $name): string => $method.' (`'.((string) $name).'`)',
                array_keys($methods),
                array_values($methods),
            )).'.';

            $body[] = $this->declaration('', 'const String', $const, $this->dartString((string) $path));
            $body[] = '';
            $body[] = '/// The HTTP methods `'.$const.'` answers.';
            $body = array_merge(
                $body,
                $this->collection(
                    '',
                    'const List<String> '.$const.'Methods',
                    '<String>[',
                    ']',
                    array_map(fn (string $m): string => $this->dartString($m), array_keys($methods)),
                ),
            );

            $blocks[] = implode("\n", $body);
        }

        return $this->file([
            '// The path templates the route table registered, as top-level constants with the',
            '// `{placeholders}` intact. A call site that hand-builds a URL can miss a segment, a',
            '// slash, or a leading `/`; one that interpolates a constant cannot.',
            '//',
            '// Every path is the FULL `/api/v1/...` form, matching `docs/openapi.yaml` and',
            "// `php artisan route:list`. The base URL is the environment's, on `SehatlyEnvironment`.",
            '//',
            '// `lib/src/api/paths.dart` holds the request-level paths this package already calls;',
            '// this file is the COMPLETE table, including the endpoints no endpoint class wraps yet.',
        ], implode("\n\n", $blocks));
    }

    /**
     * @param  list<string>  $lines
     */
    private function file(array $lines, string $body): string
    {
        $header = [
            '// GENERATED FILE -- do not hand-edit.',
            '//',
            '// `php artisan sehatly:openapi` writes this file from the live route table and each',
            '// controller method\'s `FormRequest`. `php artisan sehatly:openapi --check` fails while',
            '// the committed copy differs from a fresh export, so a hand-edit here is caught the same',
            '// way a hand-edit to `docs/openapi.yaml` is.',
            '//',
            '// Generated by App\\Support\\OpenApi\\DartContractGenerator. Pure Dart, no Flutter.',
            '',
        ];

        return implode("\n", array_merge($header, $lines, [$body]))."\n";
    }

    /**
     * Dart's reserved words, which cannot be an identifier.
     *
     * A MySQL ENUM value of `class` or `new` is legal in the database and would
     * be a compile error here, so the member name is prefixed. `wireValue` still
     * carries the verbatim value, so the rename is cosmetic -- but it has to
     * happen, or the whole file fails to parse and every OTHER enum in it is
     * collateral damage.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'abstract', 'as', 'assert', 'async', 'await', 'break', 'case', 'catch',
        'class', 'const', 'continue', 'covariant', 'default', 'deferred', 'do',
        'dynamic', 'else', 'enum', 'export', 'extends', 'extension', 'external',
        'factory', 'false', 'final', 'finally', 'for', 'function', 'get', 'hide',
        'if', 'implements', 'import', 'in', 'interface', 'is', 'late', 'library',
        'mixin', 'new', 'null', 'on', 'operator', 'part', 'required', 'rethrow',
        'return', 'sealed', 'set', 'show', 'static', 'super', 'switch', 'sync',
        'this', 'throw', 'true', 'try', 'type', 'typedef', 'var', 'void', 'when',
        'while', 'with', 'yield',
    ];

    /**
     * A lowerCamelCase Dart identifier for an attribute path or ENUM value,
     * unique within its scope.
     *
     * `items.*.obat_id` cannot be an identifier and neither can a dotted path, so
     * the wildcard and the dots become word boundaries. Two distinct values CAN
     * collide after that (`items.0.x` and `items_0_x`), and the collision is
     * resolved by suffixing rather than left silent -- a generator that quietly
     * dropped one value would be worse than one that names it oddly.
     *
     * The WIRE value is never touched. Only the Dart name is camelCased, which is
     * why every generated member also carries the original string in a
     * `wireValue`-style field: renaming the Dart name is free, renaming the wire
     * string is a breaking change.
     *
     * @param  list<string>  $taken
     */
    private function identifier(string $value, array $taken): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $value) ?: [];
        $candidate = '';

        foreach ($words as $index => $word) {
            if ($word === '') {
                continue;
            }

            $candidate .= $index === 0
                ? strtolower(substr($word, 0, 1)).substr($word, 1)
                : strtoupper(substr($word, 0, 1)).substr($word, 1);
        }

        if ($candidate === '') {
            $candidate = 'value';
        }

        if (in_array($candidate, self::RESERVED, true)) {
            $candidate .= 'Value';
        }

        if (in_array($candidate, $taken, true)) {
            $suffix = 2;

            while (in_array($candidate.$suffix, $taken, true)) {
                $suffix++;
            }

            $candidate .= $suffix;
        }

        return $candidate;
    }

    /**
     * A Dart collection literal, formatted the way `dart format` formats it.
     *
     * The formatter's rule is one line when the whole literal fits inside 80
     * columns and one element per line otherwise, with the closing bracket on its
     * own line. Reproducing it here rather than shelling out to `dart format`
     * after writing is what keeps the GENERATED BYTES deterministic: a
     * post-format step would make the output depend on which formatter version
     * happens to be installed, and `--check` compares bytes.
     *
     * @param  list<string>  $items  already-encoded literals
     */
    /**
     * A collection literal, one element per line.
     *
     * ## Why not `dart format`'s own line-breaking
     *
     * `dart format` collapses a short list onto one line and breaks a long one
     * after the `=` in a way that depends on the formatter's page width and its
     * internal cost model. Reproducing that here was attempted and abandoned: the
     * rule is not "fits in 80 columns", and getting it wrong produces a file
     * that `dart format` then rewrites -- which is worse than a file that is
     * consistently multi-line.
     *
     * One element per line is always FORMATTING-STABLE (`dart format` leaves an
     * already-expanded collection alone) and, more importantly, always
     * DETERMINISTIC: the bytes depend only on the data, never on a formatter
     * version. `--check` compares bytes, so that is the property that matters.
     *
     * `dart format --set-exit-if-changed` is therefore NOT expected to pass on
     * these files. `dart analyze` is, and is the gate that is enforced.
     *
     * @param  list<string>  $items  already-encoded literals
     */
    private function collection(string $indent, string $prefix, string $open, string $close, array $items): array
    {
        if ($items === []) {
            return [$indent.$prefix.' = '.$open.$close.';'];
        }

        $lines = [$indent.$prefix.' = '.$open];

        foreach ($items as $item) {
            $lines[] = $indent.'  '.$item.',';
        }

        $lines[] = $indent.$close.';';

        return $lines;
    }

    /**
     * A `const`/`final` declaration, always on one line.
     */
    private function declaration(string $indent, string $keyword, string $name, string $value): string
    {
        return $indent.$keyword.' '.$name.' = '.$value.';';
    }

    /**
     * A Dart string literal for an arbitrary value.
     *
     * Backslashes are the reason this is a function and not `'$value'`: a
     * `FormRequest` class name is `App\Http\Requests\Auth\LoginRequest`, and a
     * single-quoted Dart literal would read the backslashes as escapes and
     * produce either a mangled string or a compile error. Backslash and single
     * quote are escaped; a newline would be escaped too, but none of the values
     * reaching here contains one -- a rule string is a single token.
     */
    private function dartString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * @param  list<string>  $ruleSet
     * @param  list<string>  $details
     * @return list<string>|null
     */
    private function closedSet(array $ruleSet, array $details): ?array
    {
        foreach (array_merge($ruleSet, $details) as $rule) {
            if (! str_starts_with($rule, 'in:')) {
                continue;
            }

            $decoded = json_decode(substr($rule, strlen('in:')), true);

            if (is_array($decoded) && $decoded !== [] && array_is_list($decoded)) {
                return array_values(array_map('strval', $decoded));
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $ruleSet
     */
    private function isRequired(array $ruleSet): bool
    {
        if (in_array('required', $ruleSet, true)) {
            return true;
        }

        foreach ($ruleSet as $rule) {
            if (str_starts_with($rule, 'required_if:') || str_starts_with($rule, 'required_with:')) {
                return true;
            }
        }

        return false;
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * `/api/v1/konsultasi/{id}/chat` -> `apiV1KonsultasiIdChat`.
     */
    private function pathConst(string $path): string
    {
        $parts = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            $parts[] = $this->camel(str_replace(['{', '}'], '', $segment));
        }

        return implode('', $parts);
    }

    /**
     * A path SEGMENT as a Dart identifier fragment.
     *
     * `/otp/verify` -> `OtpVerify`, `deviceId` -> `deviceId`. Existing inner
     * capitals are preserved rather than re-cased, so a segment already spelled
     * `deviceId` does not become `Deviceid` -- which would compile and then make
     * `apiV1AuthDevicesDeviceId` unreadable next to it. Only the boundary
     * between words and the first character of the fragment are adjusted.
     */
    private function camel(string $value): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $value) ?: [];
        $camel = '';

        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }

            $camel .= $index === 0
                ? strtolower(substr($part, 0, 1)).substr($part, 1)
                : strtoupper(substr($part, 0, 1)).substr($part, 1);
        }

        return $camel === '' ? 'root' : $camel;
    }

    private function pascal(string $value): string
    {
        $parts = preg_split('/[_\-\s]+/', $value) ?: [$value];
        $pascal = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $pascal .= strtoupper(substr($part, 0, 1)).substr($part, 1);
        }

        return $pascal;
    }
}
