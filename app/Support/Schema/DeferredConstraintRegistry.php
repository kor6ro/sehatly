<?php

declare(strict_types=1);

namespace App\Support\Schema;

use RuntimeException;

/**
 * The deferred-constraint registry, read from `docs/schema-notes.md`.
 *
 * ## RETIRED in todo 18. This class is no longer called by the verifier.
 *
 * `VerifySchemaParity` used to call `DeferredConstraintRegistry::fromMarkdown()`
 * unconditionally. That call and the `## Deferred constraints` section it read
 * were removed together, and **the class is kept only so
 * `tests/Unit/Schema/SchemaDifferDeferredConstraintTest.php` can keep pinning the
 * three rules below against fixtures.** Do not re-add the call on its own: it is
 * unconditional and this class raises on an empty registry, so restoring the call
 * while the section is gone turns a clean run into **exit 2**, not exit 1. Any
 * future deferred constraint needs the section, the row, and this call reinstated
 * in the same commit.
 *
 * ## Why it existed
 *
 * The reference DDL is written as if every table existed at once, but the
 * migrations do not. `telemedicine_test.sql:1161-1163` adds
 * `CONSTRAINT fk_vital_rm` to `pasien_tanda_vital` from section `[14]`, and
 * `SqlSchemaParser` folds that `ALTER TABLE ... ADD CONSTRAINT` into the table it
 * targets - so the **expected** model demands `fk_vital_rm` from the first commit,
 * while the live schema *cannot* hold it until `rekam_medis` exists in batch G.
 * Without a way to say "intentionally not here yet", that is `missing_foreign_key`
 * drift and the verifier is structurally unable to pass at that position.
 *
 * This registry was the way to say it, and it was exactly parallel to
 * {@see ExtraTableRegistry}: documented in the notes file, enforced by the
 * differ, a missing or unusable registry a hard error (exit 2) rather than a
 * silent pass, and an undocumented missing constraint stayed drift.
 *
 * **Keyed on the constraint NAME, not on table + column.** `fk_vital_rm` was the
 * only name the DDL itself wrote for a foreign key, and it was therefore the only
 * stable handle on "this specific constraint is deferred". A table + column key
 * would have forgiven *any* foreign key on `pasien_tanda_vital.rekam_medis_id` - a
 * different target table, a different `ON DELETE`, or an entirely unrelated
 * constraint a future migration adds there would all have been silently excused
 * by one row. One named constraint, one row, and the blast radius was a single
 * named object.
 *
 * **Three rules, all of them load-bearing** (see {@see SchemaDiffer::diff()}):
 *
 *  1. a `missing_foreign_key` that is registered here is **informational**;
 *  2. a registered constraint that is **present** in the live schema is **drift**,
 *     because the registry has gone stale and must be updated in the same commit
 *     that lands the constraint - without this, a row here could have excused the
 *     constraint forever and the "75 tables, 2 views verified" run would have
 *     passed with the foreign key still absent;
 *  3. a `missing_foreign_key` that is **not** registered is **drift**, exactly as
 *     before, so the failure direction was always the safe one.
 *
 * ## How it actually ended
 *
 * Rule 2 is why the registry was *mandatory* rather than optional: an absent or
 * empty registry raised, and a run that cannot understand its inputs must never
 * look green (plan appendix A.10).
 *
 * **An earlier version of this docblock claimed that "the rule-2 message tells the
 * reader exactly that"** - i.e. that the `fulfilled_deferred_foreign_key`
 * discrepancy told an executor to delete both the section and the
 * `fromMarkdown()` call. **That was false and the sentence has been removed
 * rather than left in place.** The rule-2 message names the stale row and quotes
 * its justification; it says nothing about code, about this class, or about the
 * notes file's structure. It was the *registry's own docblock* and
 * `docs/schema-notes.md` that recorded the two-edit requirement, not the tool
 * output. A reader who trusted the old sentence would have deleted the section,
 * watched the run go from exit 1 to exit 2, and had no warning that a second edit
 * was required. The corrected statement of the requirement is in the comment at
 * the call site in `VerifySchemaParity` and in the "Why this file exists" section
 * of `docs/schema-notes.md`.
 *
 * `SchemaDiffer::diff()` still takes `$deferredConstraints` and defaults it to
 * `[]`, so the differ's behaviour is unchanged by the retirement.
 */
final class DeferredConstraintRegistry
{
    /**
     * The heading the rows must sit under. Named here rather than inlined so the
     * notes file and this parser cannot drift apart silently: renaming the heading
     * empties the registry, and an empty registry is a hard error, not a pass.
     *
     * The heading no longer exists in `docs/schema-notes.md`, so calling
     * {@see fromMarkdown()} against the real notes file **throws**. That is the
     * intended state while the registry is retired, and it is the reason the
     * verifier no longer calls it.
     */
    public const HEADING = 'Deferred constraints';

    /**
     * @return array<string, string> lower-cased constraint name => justification
     */
    public static function fromMarkdown(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                'The deferred-constraint registry is mandatory but was not found at '.$path
                .'. Every constraint the DDL declares before the table it references exists must be listed there.',
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
                'No deferred constraints were found in '.$path
                .'. The registry must be a markdown table under "## '.self::HEADING.'"'
                .' whose first column is a backticked constraint name.',
            );
        }

        return $entries;
    }
}
