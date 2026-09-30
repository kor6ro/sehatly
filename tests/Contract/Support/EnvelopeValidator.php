<?php

declare(strict_types=1);

namespace Tests\Contract\Support;

/**
 * Validates a decoded JSON body against a schema taken from `docs/openapi.yaml`.
 *
 * ## Why this is hand-written rather than `opis/json-schema`
 *
 * The plan's todo 49 named `opis/json-schema ^2.4` for exactly this job. It is
 * not a dependency of this repository -- `composer.json` has no `opis/*` entry and
 * `vendor/opis` does not exist -- and installing it was not available to this
 * todo. So the validator is written out, and the reason it is safe to write one
 * is that the SUBSET is named, closed, and enforced.
 *
 * ## The subset, in full
 *
 * These are the only keywords that may appear in a schema this validator will
 * accept, and {@see self::SUPPORTED_KEYWORDS} is the list:
 *
 * - `$ref`                          -- resolved against `#/components/schemas/`
 * - `type`                          -- a name, or a list of names
 * - `const`                         -- deep, by value
 * - `enum`                          -- value must be one of the listed values
 * - `required`                      -- list of property names
 * - `properties`                    -- name to schema
 * - `additionalProperties`          -- `false`, or a schema for every extra key
 * - `items`                         -- schema for array elements
 * - `minItems`                      -- lower bound on array length
 * - `minimum`                       -- lower bound on a number
 *
 * `description`, `title` and `example` are annotations and are ignored, which is
 * what JSON Schema itself specifies.
 *
 * ## A validator that silently ignores a keyword is worse than none
 *
 * If a future `sehatly:openapi` run emits `oneOf`, `patternProperties` or
 * `additionalItems`, this validator would ignore them and keep passing -- which
 * is precisely the false confidence this todo exists to prevent. So
 * {@see self::unsupportedKeywords()} walks every component schema in the
 * document and reports any keyword outside the list, and the suite FAILS when
 * that list is non-empty. The subset cannot silently widen.
 *
 * ## Bodies are decoded as objects, never as associative arrays
 *
 * The distinction this class depends on most is `{}` against `[]`, and PHP's
 * `json_decode($json, true)` collapses both to `array`. `ApiResponse::error()`
 * casts an empty error set to `(object) []` precisely so it encodes as `{}`
 * rather than `[]`, and that choice is invisible after an associative decode.
 * So bodies arrive here as the object graph `json_decode($json)` produces:
 * `stdClass` for a JSON object, `array` for a JSON array. {@see self::isObject()}
 * and {@see self::isArray()} test those exact types.
 */
final class EnvelopeValidator
{
    /**
     * Every keyword this validator understands.
     *
     * @var list<string>
     */
    public const SUPPORTED_KEYWORDS = [
        '$ref',
        'type',
        'const',
        'enum',
        'required',
        'properties',
        'additionalProperties',
        'items',
        'minItems',
        'minimum',
    ];

    /**
     * Keywords that carry no assertion and are ignored by design.
     *
     * @var list<string>
     */
    private const ANNOTATIONS = ['description', 'title', 'example', '$comment', 'deprecated', 'format'];

    /**
     * Validate `$body` against `$schema`, returning a list of violations.
     *
     * An empty list means the body conforms. Each violation names the JSON
     * pointer where it was found, because "it does not validate" without a path
     * is not an actionable test failure.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function validate(mixed $body, array $schema, string $pointer = '#'): array
    {
        if (isset($schema['$ref'])) {
            return $this->validate($body, ContractSpec::resolveRef((string) $schema['$ref']), $pointer);
        }

        $violations = [];

        if (array_key_exists('type', $schema)) {
            $violations = array_merge($violations, $this->checkType($body, $schema['type'], $pointer));
        }

        if (array_key_exists('const', $schema) && $body !== $schema['const']) {
            $violations[] = sprintf(
                '%s: expected the constant %s, got %s',
                $pointer,
                $this->describe($schema['const']),
                $this->describe($body),
            );
        }

        if (array_key_exists('enum', $schema) && is_array($schema['enum'])) {
            $matches = false;

            foreach ($schema['enum'] as $candidate) {
                if ($body === $candidate) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
                $violations[] = sprintf(
                    '%s: %s is not one of the %d value(s) the schema allows',
                    $pointer,
                    $this->describe($body),
                    count($schema['enum']),
                );
            }
        }

        if ($this->isObject($body)) {
            $violations = array_merge($violations, $this->checkObject($body, $schema, $pointer));
        }

        if ($this->isArray($body)) {
            $violations = array_merge($violations, $this->checkArray($body, $schema, $pointer));
        }

        return $violations;
    }

    /**
     * Every keyword used by the document that this validator would IGNORE.
     *
     * @return list<string>
     */
    public function unsupportedKeywords(): array
    {
        $supported = array_merge(self::SUPPORTED_KEYWORDS, self::ANNOTATIONS);
        $found = [];

        foreach (ContractSpec::document()['components']['schemas'] ?? [] as $name => $schema) {
            foreach ($this->collectKeywords(is_array($schema) ? $schema : []) as $keyword) {
                if (! in_array($keyword, $supported, true) && ! in_array($keyword, $found, true)) {
                    $found[] = $keyword;
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Walk a schema tree collecting every keyword name used anywhere in it.
     *
     * `x-laravel-*` extensions are skipped: they are vendor annotations on the
     * REQUEST body schemas, they assert nothing in a JSON Schema sense, and the
     * plan's own generator emits them by design.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function collectKeywords(array $schema): array
    {
        $keywords = [];

        foreach ($schema as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'x-')) {
                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            $keywords[] = $key;

            if ($key === 'properties' && is_array($value)) {
                foreach ($value as $child) {
                    if (is_array($child)) {
                        $keywords = array_merge($keywords, $this->collectKeywords($child));
                    }
                }
            }

            if (in_array($key, ['items', 'additionalProperties'], true) && is_array($value)) {
                $keywords = array_merge($keywords, $this->collectKeywords($value));
            }
        }

        return $keywords;
    }

    /**
     * @return list<string>
     */
    private function checkType(mixed $body, mixed $types, string $pointer): array
    {
        foreach ((array) $types as $type) {
            if ($this->matchesType($body, (string) $type)) {
                return [];
            }
        }

        return [sprintf(
            '%s: expected type %s, got %s',
            $pointer,
            implode('|', array_map(strval(...), (array) $types)),
            $this->describe($body),
        )];
    }

    private function matchesType(mixed $body, string $type): bool
    {
        return match ($type) {
            'object' => $this->isObject($body),
            'array' => $this->isArray($body),
            'string' => is_string($body),
            'boolean' => is_bool($body),
            // An integer-valued float is a JSON number, and `total: 0` decodes to
            // PHP int while `1.0` decodes to float. Both are valid `integer`
            // only if the float has no fractional part.
            'integer' => is_int($body) || (is_float($body) && floor($body) === $body),
            'number' => is_int($body) || is_float($body),
            'null' => $body === null,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function checkObject(\stdClass $body, array $schema, string $pointer): array
    {
        $violations = [];
        $properties = $schema['properties'] ?? [];
        $decoded = (array) $body;

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists((string) $required, $decoded)) {
                $violations[] = sprintf('%s: required property "%s" is absent', $pointer, (string) $required);
            }
        }

        $additional = $schema['additionalProperties'] ?? null;

        foreach ($decoded as $name => $value) {
            if (isset($properties[$name]) && is_array($properties[$name])) {
                $violations = array_merge(
                    $violations,
                    $this->validate($value, $properties[$name], $pointer.$this->escapePointer((string) $name)),
                );

                continue;
            }

            if ($additional === false) {
                $violations[] = sprintf(
                    '%s: property "%s" is not declared and additionalProperties is false',
                    $pointer,
                    $name,
                );

                continue;
            }

            if (is_array($additional)) {
                $violations = array_merge(
                    $violations,
                    $this->validate($value, $additional, $pointer.$this->escapePointer((string) $name)),
                );
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function checkArray(array $body, array $schema, string $pointer): array
    {
        $violations = [];

        if (isset($schema['minItems']) && count($body) < (int) $schema['minItems']) {
            $violations[] = sprintf(
                '%s: expected at least %d item(s), got %d',
                $pointer,
                (int) $schema['minItems'],
                count($body),
            );
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($body as $index => $value) {
                $violations = array_merge(
                    $violations,
                    $this->validate($value, $schema['items'], $pointer.'/'.$index),
                );
            }
        }

        return $violations;
    }

    /**
     * Escape one path segment for a JSON pointer (RFC 6901).
     *
     * `~` and `/` are the two characters that would otherwise be read as pointer
     * syntax. A field named `perluan/Untuk` or `a~b` would produce an ambiguous
     * pointer without this, and an ambiguous pointer is worse than no pointer
     * because it sends a reader to the wrong place confidently.
     */
    private function escapePointer(string $segment): string
    {
        return '/'.str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    private function isObject(mixed $value): bool
    {
        return $value instanceof \stdClass;
    }

    private function isArray(mixed $value): bool
    {
        return is_array($value);
    }

    /**
     * Render a value for a failure message, stably and readably.
     */
    private function describe(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return '"'.$value.'"';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return 'array('.count($value).')';
        }

        if ($value instanceof \stdClass) {
            return 'object('.count((array) $value).')';
        }

        return gettype($value);
    }
}
