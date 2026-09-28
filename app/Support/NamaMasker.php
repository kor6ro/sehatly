<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Masks a person's display name for publication on a PUBLIC endpoint.
 *
 * ## Why a second masker rather than reusing {@see NikMasker}
 *
 * `NikMasker` keeps the FIRST FOUR and LAST FOUR characters and replaces the interior
 * with bullets, which is right for a fixed-width 16-character identifier and wrong for
 * a name: applied to `Siti Aminah` it would publish `Siti` and `minah`, which is most
 * of the name. The two fields have different widths, different privacy properties and
 * different acceptable losses, so they have different rules and different classes.
 *
 * Its own docblock records the third place a name is published -
 * `RekamMedisResource` - and that one is deliberately UNMASKED, because the medical
 * record is read by the record's own patient and their own doctor and a name that
 * reads `S*** A*****` in a clinical note is worse than useless. This class exists for
 * the opposite case: a response any stranger may obtain.
 *
 * ## The rule
 *
 * Split on whitespace, and for EACH word keep the first character and replace every
 * remaining character with {@see PENGGANTI}. `Siti Aminah` becomes
 * `S... A.....` (with the bullet as the replacement). The shape of a name survives -
 * a reader can tell a two-word name from a three-word one and can recognise a
 * familiar initial - and the name does not.
 *
 * **Every** character after the first is replaced, including in a one-character word.
 * That is the deliberate opposite of `NikMasker`, which returns a value shorter than
 * its visible ends UNMASKED because there is no interior to hide; here a one-character
 * name is a plausible pseudonym in its own right, and a rule that leaked short names
 * would leak exactly the names most likely to be real people.
 *
 * ## The mask character is U+2022 BULLET
 *
 * The same character {@see NikMasker} uses, so one client renders both the same way.
 * It is one of the seven typographic codepoints the project's A.26 non-ASCII gate
 * permits, and it is written here as an escape rather than as a literal byte so this
 * file stays pure ASCII.
 */
final class NamaMasker
{
    /**
     * U+2022 BULLET. The same replacement `NikMasker` uses.
     */
    public const PENGGANTI = "\u{2022}";

    /**
     * The masked form of `$nama`, or `null` when there is nothing to mask.
     *
     * Whitespace is collapsed first, so a padded `users.nama_lengkap` cannot become a
     * bullet run and a value stored with internal double spaces cannot leak word
     * boundaries the caller did not intend. An empty or all-whitespace name is the
     * absence of one, not a value to mask, so it returns `null` rather than a bullet.
     */
    public static function mask(?string $nama): ?string
    {
        if ($nama === null) {
            return null;
        }

        $kata = preg_split('/\s+/u', trim($nama), -1, PREG_SPLIT_NO_EMPTY);

        if ($kata === false || $kata === []) {
            return null;
        }

        return implode(' ', array_map(
            static function (string $satu): string {
                if ($satu === '') {
                    return '';
                }

                return mb_substr($satu, 0, 1).str_repeat(self::PENGGANTI, mb_strlen($satu) - 1);
            },
            $kata,
        ));
    }
}
