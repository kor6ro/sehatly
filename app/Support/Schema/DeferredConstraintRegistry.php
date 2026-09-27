<?php

declare(strict_types=1);

namespace App\Support\Schema;

use RuntimeException;

/**
 * The deferred-constraint registry, read from `docs/schema-notes.md`.
 *
 * The reference DDL is written as if every table existed at once, but the
 * migrations do not. `telemedicine_test.sql:1161-1163` adds
 * `CONSTRAINT fk_vital_rm` to `pasien_tanda_vital` from section `[14]`, and
 * `SqlSchemaParser` folds that `ALTER TABLE … ADD CONSTRAINT` into the table it
 * targets — so the **expected** model demands `fk_vital_rm` from the first commit,
 * while the live schema *cannot* hold it until `rekam_medis` exists in batch G.
 * Without a way to say "intentionally not here yet", that is `missing_foreign_key`
 * drift and the verifier is structurally unable to pass at this position.
 *
 * This registry is the way to say it, and it is exactly parallel to
 * {@see ExtraTableRegistry}: documented in the notes file, enforced by the
 * differ, a missing or unusable registry is a hard error (exit 2) rather than a
 * silent pass, and an undocumented missing constraint stays drift.
 *
 * **Keyed on the constraint NAME, not on table + column.** `fk_vital_rm` is the
 * only name the DDL itself writes for a foreign key, and it is therefore the only
 * stable handle on "this specific constraint is deferred". A table + column key
 * would forgive *any* foreign key on `pasien_tanda_vital.rekam_medis_id` — a
 * different target table, a different `ON DELETE`, or an entirely unrelated
 * constraint a future migration adds there would all be silently excused by one
 * row. One named constraint, one row, and the blast radius is a single named
 * object.
 *
 * **Three rules, all of them load-bearing** (see {@see SchemaDiffer::diff()}):
 *
 *  1. a `missing_foreign_key` that is registered here is **informational**;
 *  2. a registered constraint that is **present** in the live schema is **drift**,
 *     because the registry has gone stale and must be updated in the same commit
 *     that lands the constraint — without this, a row here could excuse the
 *     constraint forever and the "75 tables, 2 views verified" run would pass with
 *     the foreign key still absent;
 *  3. a `missing_foreign_key` that is **not** registered is **drift**, exactly as
 *     before, so the failure direction is always the safe one.
 *
 * Rule 2 is why the registry is *mandatory* rather than optional: an absent or
 * empty registry raises, and a run that cannot understand its inputs must never
 * look green (plan Appendix A.10). The consequence, worth stating plainly: once
 * the last deferral is resolved, the section cannot be left behind empty. The
 * correct end state is to remove the section **and** its
 * `DeferredConstraintRegistry::fromMarkdown()` call together, which is why the
 * rule-2 message tells the reader exactly that.
 */
final class DeferredConstraintRegistry
{
    /**
     * The heading the rows must sit under. Named here rather than inlined so the
     * notes file and this parser cannot drift apart silently: renaming the heading
     * empties the registry, and an empty registry is a hard error, not a pass.
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
