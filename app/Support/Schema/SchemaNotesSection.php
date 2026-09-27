<?php

declare(strict_types=1);

namespace App\Support\Schema;

/**
 * One `## ` section of `docs/schema-notes.md`, as a list of lines.
 *
 * `docs/schema-notes.md` now holds **two** machine-read registries — the extra
 * tables and the deferred constraints — and both of them are markdown tables
 * keyed on a backticked identifier. Left unscoped, each parser would read the
 * other's rows and each would forgive an object that is really drift: the extras
 * parser would forgive `fk_vital_rm` as a table, and the deferrals parser would
 * forgive `cache` as a constraint.
 *
 * The notes file itself warned about exactly this before either registry existed
 * ("Do not add any other markdown table in this file whose first cell is a
 * backticked identifier — the registry parser would read it as a registered extra
 * table"). This class makes that warning obsolete by construction: a registry
 * only ever reads the rows beneath its own heading, so a new section can be added
 * anywhere else in the file without either registry noticing it.
 *
 * A heading that is absent yields no lines, and the calling registry turns that
 * into the same "mandatory but missing" error it already raises for an unreadable
 * path — a registry that was renamed away fails loudly instead of silently
 * becoming an empty allow-list.
 */
final class SchemaNotesSection
{
    /**
     * The lines between `## <heading>` and the next `## ` heading, or the end of
     * the file. Blank lines and the heading line itself are not included.
     *
     * The heading is matched case-insensitively and tolerates trailing
     * whitespace, a trailing `##` and any heading level above 1, because none of
     * those change what the section means to a human reader and a registry that
     * is fussy about them is a registry someone will work around.
     *
     * @return list<string>
     */
    public static function lines(string $markdown, string $heading): array
    {
        $wanted = self::normalise($heading);
        $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];

        $inside = false;
        $collected = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*$/m', $line, $m) === 1) {
                // Any heading opens or closes a section, so a registry cannot
                // accidentally keep reading past the next `### ` either. A level-1
                // heading closes it without opening anything, which is what makes
                // `# Schema notes` at the top of the file inert.
                $level = strlen($m[1]);
                $inside = $level > 1 && self::normalise($m[2]) === $wanted;

                continue;
            }

            if ($inside) {
                $collected[] = $line;
            }
        }

        return $collected;
    }

    /**
     * The heading text reduced to a comparable key: no surrounding whitespace, no
     * closing run of `#`, and case-folded.
     */
    private static function normalise(string $heading): string
    {
        return strtolower(trim(rtrim(trim($heading), '#')));
    }
}
