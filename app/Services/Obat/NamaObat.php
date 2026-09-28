<?php

declare(strict_types=1);

namespace App\Services\Obat;

use Illuminate\Support\Str;

/**
 * Turns a drug NAME into a comparable CORE, so two spellings of one substance
 * meet and two substances never do.
 *
 * ## Why this is not a `LIKE`
 *
 * `pasien_alergi.nama_alergen` is `VARCHAR(150) NOT NULL` free text
 * (`telemedicine_test.sql:278`) typed by a human, and it carries no foreign key
 * to `master_obat` - the table's only foreign key is `pasien_id` (`:283`). The
 * other side of the comparison is equally loose: `master_obat.nama_generik` and
 * `nama_brand` (`:711`-`:712`) are free text, and `resep_item.nama_obat` (`:771`)
 * is a SNAPSHOT of a name taken at prescribing time. So the join key a matcher
 * needs does not exist anywhere in the schema, and the only thing available is
 * the spelling.
 *
 * A `LIKE '%amoxicillin%'` is the obvious thing to reach for and it is wrong in
 * the one direction that matters. It is a SUBSTRING test, so it fires whenever
 * one name is contained in another:
 *
 * - `amoxicillin` is contained in `amoxicillin-clavulanate`, so a patient
 *   allergic to plain amoxicillin would be warned away from a combination
 *   product - a warning about a drug that contains none of the allergen;
 * - `amoxicillin-clavulanate` does NOT contain a near-miss, but a matcher that
 *   trims the trailing dose off the wrong end (`amoxicillin-clavulanate 875mg`
 *   read as `875mg` because of a greedy `.*`) silently compares nothing;
 * - the DDL's own seven `kelas_terapi` values include `Antibiotik`,
 *   `Antihistamin`, `Antidiabetik` and `Antihipertensi` - all four contain
 *   `Anti`, so a `LIKE` on the class warns about all four for one allergy.
 *
 * **A warning about the wrong drug is worse than no warning.** A doctor who is
 * trained to trust this panel stops reading it the first time it lies about
 * something they know. The matcher below is therefore EQUALITY on a normalised
 * core, never containment.
 *
 * ## The pipeline, in order, and why each step is where it is
 *
 * | # | step | why here |
 * | --- | --- | --- |
 * | 1 | `trim()` | leading and trailing whitespace is the commonest typo and the cheapest to remove |
 * | 2 | `Str::ascii()` | transliterates accented Latin to ASCII, so two spellings of one word fold. It runs BEFORE case-folding because the table it consults is case-aware |
 * | 3 | `strtolower()` | case-fold. Safe as a plain byte operation only because step 2 guarantees ASCII |
 * | 4 | `preg_replace('/\s+/', ' ')` | collapse every whitespace RUN. Tabs, newlines and doubled spaces all become one space - `Amoxi  cillin` and `Amoxi\tcillin` are the same string after this |
 * | 5 | `preg_replace('/[^a-z0-9]+/', ' ')` | **every separator becomes a SPACE, never a deletion.** This is the step the whole design turns on, and the reason is written below |
 * | 6 | explode, drop empties | tokenise; the empty strings step 5 leaves behind are not tokens |
 * | 7 | drop DOSE tokens | `500 mg` and `500mg` are not part of the substance's name, and the strength lives in its own column (`master_obat.kekuatan`, `:715`) |
 * | 8 | `implode('', ...)` | the CORE: separator-insensitive, still not a substring |
 *
 * ## Step 5 replaces rather than deletes, and step 8 then re-joins
 *
 * Deleting the separator would be shorter and is subtly wrong in the other
 * direction: `Analgetik-Antipiretik` - the DDL's own `kelas_terapi` value for
 * `OBT-0001` (`telemedicine_test.sql:1311`) - would become
 * `analgetikantipiretik` as one opaque token, indistinguishable from a drug
 * genuinely called that. Replacing it with a space keeps it two tokens, and
 * step 8 re-joins them anyway, so:
 *
 * - `Amoxi-cillin`, `Amoxi.Cillin`, `Amoxi cillin` and `Amoxi  cillin` all reach
 *   the core `amoxicillin` - separator, spacing and case are all invisible;
 * - `amoxi-cillin` is NOT `amoxicillin` by virtue of a `LIKE` - it is the same
 *   core because the hyphen was a separator, not because one string contains
 *   the other.
 *
 * The cost of the join is that two DIFFERENT multi-token names whose tokens
 * concatenate to the same string are indistinguishable. That is accepted
 * deliberately: it cannot produce a false positive against any real substance
 * in the catalogue, whereas a `LIKE` produces four.
 *
 * ## A name is matched in FULL, never per token
 *
 * The core of `nama_generik` and the core of `nama_brand` are computed
 * SEPARATELY and offered as two alternatives. Concatenating them into one core
 * would invent strings no drug has (`orsoralit` from `ORS` / `Oralit`,
 * `telemedicine_test.sql:1317`) and would let a single allergy match a name the
 * catalogue does not contain. A consequence worth stating: a free-text
 * snapshot of `ORS Oralit` does not match an allergy to `ORS`. That is the
 * price of never warning about the wrong drug, and todo 39 writes the snapshot
 * from `nama_generik` (`:711`), which does match.
 */
final class NamaObat
{
    /**
     * The strength units recognised in step 7, all lower-case and all ASCII.
     *
     * A closed list rather than a pattern, because an unrecognised unit would
     * survive into the core and stop a legitimate match. A unit missing from
     * this list costs a missed warning; a unit wrongly IN it would cost a false
     * one, so only unambiguous ones belong.
     *
     * @var list<string>
     */
    public const SATUAN = [
        'mg', 'g', 'kg', 'mcg', 'ug', 'ng', 'pg',
        'ml', 'dl', 'mmol', 'mol', 'ui', 'iu', 'meq', '%',
    ];

    /**
     * A token that is a NUMBER followed immediately by a unit, e.g. `500mg`,
     * `0,5%`, `10iu`. The number may carry a decimal comma, which is the
     * Indonesian spelling.
     *
     * Case-sensitive on purpose: {@see inti()} case-folds in step 3, before
     * this pattern is ever reached, so a mixed-case token is impossible here and
     * a case-insensitive pattern would only suggest a guarantee that is not
     * there.
     */
    private const POLA_DOSIS = '/^[0-9]+(?:[.,][0-9]+)?(?:mcg|ug|ng|pg|kg|mg|ml|dl|mmol|mol|meq|iu|ui|g|%)$/';

    /**
     * The normalised CORE of a drug name, or `''` when there is no substance
     * left to name.
     *
     * An empty core is a real answer, not an error: an allergy recorded as
     * `'   '` or `'---'` names nothing, and matching it against everything
     * would be exactly the false positive this class exists to prevent.
     */
    public static function inti(?string $nama): string
    {
        if ($nama === null) {
            return '';
        }

        // 1, 2, 3, 4 - trim, transliterate, case-fold, collapse whitespace runs.
        $teks = trim(Str::ascii($nama));
        $teks = strtolower($teks);
        $teks = preg_replace('/\s+/', ' ', $teks) ?? '';

        // 5 - every non-alphanumeric run becomes ONE space. Never a deletion.
        $teks = preg_replace('/[^a-z0-9]+/', ' ', $teks) ?? '';
        $teks = trim($teks);

        if ($teks === '') {
            return '';
        }

        // 6, 7 - tokenise, then drop the strength.
        $inti = '';

        foreach (explode(' ', $teks) as $token) {
            if ($token === '' || self::adalahDosis($token)) {
                continue;
            }

            $inti .= $token;
        }

        // 8 - the core. `implode('')` is the correct operator here, and a
        // mutation that swaps it for `+` is invisible on PHP 8 because `+` on
        // two strings keeps the LEFT operand and returns it unchanged - so the
        // core would silently become the first token only. The suite asserts
        // that `Amoxi-cillin` matches `Amoxicillin`, which `+` cannot do.
        return $inti;
    }

    /**
     * Is this token a strength rather than part of the name?
     *
     * Two shapes, both derived from how the DDL itself writes strengths:
     * `master_obat.kekuatan` is `VARCHAR(50) NULL COMMENT '500 mg'` (`:715`) and
     * the seeder's values are `'500 mg'`, `'10 mg'`, `'20 mg'` - a number and a
     * unit, sometimes with a space and sometimes without.
     */
    private static function adalahDosis(string $token): bool
    {
        // A bare number: the second half of a split `500 mg`.
        if (ctype_digit($token)) {
            return true;
        }

        // A bare unit: the first half of a split `500 mg`.
        if (in_array($token, self::SATUAN, true)) {
            return true;
        }

        return preg_match(self::POLA_DOSIS, $token) === 1;
    }
}
