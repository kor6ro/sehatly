<?php

declare(strict_types=1);

namespace App\Support\Schema;

use RuntimeException;

/**
 * The extra-table registry, read from `docs/schema-notes.md`.
 *
 * `telemedicine_test.sql` is read-only law, so infrastructure tables (`cache`,
 * `jobs`, `personal_access_tokens`, …) cannot be added to it. The plan's sanctioned
 * mechanism is spec §4.4: add them in a migration and record them in the notes
 * file. That only works if something checks the notes file, which is this class's
 * job — an unrecorded extra table is drift, a recorded one is not.
 *
 * The registry is the markdown table under the "Registered extra tables" heading:
 *
 *     | Table | Source | Justification |
 *     | --- | --- | --- |
 *     | `cache` | `database/migrations/...` | Laravel's cache store. |
 *
 * Only the rows beneath that heading are read, via {@see SchemaNotesSection}, so
 * the sibling deferred-constraint table in the same file — which is also keyed on
 * a backticked identifier — can never be mistaken for a registered extra table.
 */
final class ExtraTableRegistry
{
    /**
     * The heading the rows must sit under. Named here rather than inlined so the
     * notes file and this parser cannot drift apart silently: renaming the heading
     * empties the registry, and an empty registry is a hard error, not a pass.
     */
    public const HEADING = 'Registered extra tables';

    /**
     * @return array<string, string> lower-cased table name => justification
     */
    public static function fromMarkdown(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                'The extra-table registry is mandatory but was not found at '.$path
                .'. Every table that exists in the migrations but not in telemedicine_test.sql must be listed there.',
            );
        }

        $markdown = (string) file_get_contents($path);
        $entries = [];

        foreach (SchemaNotesSection::lines($markdown, self::HEADING) as $line) {
            if (preg_match('/^\|\s*`([A-Za-z0-9_]+)`\s*\|(.*)\|\s*$/m', $line, $m) !== 1) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($m[2], '|')));

            if ($cells === ['']) {
                continue;
            }

            $justification = trim((string) end($cells));
            $entries[strtolower($m[1])] = $justification === '' ? 'registered without a justification' : $justification;
        }

        if ($entries === []) {
            throw new RuntimeException(
                'No registered extra tables were found in '.$path
                .'. The registry must be a markdown table under "## '.self::HEADING.'"'
                .' whose first column is a backticked table name.',
            );
        }

        return $entries;
    }
}
