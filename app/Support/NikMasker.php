<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Resources\PasienAnggotaKeluargaResource;

/**
 * Masks a 16-character national identifier for publication.
 *
 * ## Why this is a class and not a line inside a resource
 *
 * Three resources publish a masked identifier - {@see \App\Http\Resources\PasienResource}
 * for `pasien.nik` and `pasien.nomor_kk`, and
 * {@see PasienAnggotaKeluargaResource} for
 * `pasien_anggota_keluarga.nik` - and the masking rule is a security control, not a
 * formatting preference. Three copies of it would be three places for the next edit to
 * get wrong, and a wrong one is a raw NIK in a response body.
 *
 * ## The rule
 *
 * Keep the first {@see VISIBLE_AWAL} characters and the last {@see VISIBLE_AKHIR},
 * replace every character in between with {@see PENGGANTI}, and return `null` for a null
 * input. The masked string is therefore always exactly as long as the input, so the
 * response leaks nothing beyond the fact of a length the `CHAR(16)` column already fixes.
 *
 * `telemedicine_test.sql:222` is `nik CHAR(16) NULL UNIQUE`, so the intended input is 16
 * characters and the plan's example is `3273` + eight masks + `0021`. The implementation
 * does not hard-code 16: a value shorter than {@see VISIBLE_AWAL} plus
 * {@see VISIBLE_AKHIR} is returned untouched, because there is no interior to hide and a
 * "masked" 3-character value that reveals all 3 would be worse than saying so. That case
 * cannot arise for a `CHAR(16)` written by this API, and it is handled rather than
 * assumed away so a future writer of a different width cannot turn it into a leak.
 *
 * `pasien.nomor_kk` (`:223`) is also `CHAR(16)` and goes through the same function;
 * {@see PasienResource} explains why it is masked even though the spec's DoD names only
 * `nik`.
 *
 * ## The mask character is U+2022 BULLET
 *
 * The plan's example uses it, and it is one of the seven typographic codepoints the
 * project's A.26 non-ASCII gate permits. A plain asterisk or `#` was rejected because
 * the plan's own documented output format is the bullet run and a client that renders
 * the field verbatim will show whatever character this returns.
 */
final class NikMasker
{
    /**
     * Characters kept from the start.
     */
    public const VISIBLE_AWAL = 4;

    /**
     * Characters kept from the end.
     */
    public const VISIBLE_AKHIR = 4;

    /**
     * U+2022 BULLET. The plan's example mask character.
     */
    public const PENGGANTI = "\u{2022}";

    /**
     * The masked form of `$identifier`, or `null` when there is nothing to mask.
     *
     * Whitespace is trimmed first because MySQL `CHAR` right-pads on retrieval: a
     * `CHAR(16)` holding 12 characters comes back as 12 characters plus four spaces, and
     * an untrimmed mask would turn those spaces into four bullets and produce a string
     * that looks 16 characters long while revealing the padding.
     */
    public static function mask(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        $trimmed = trim($identifier);

        if ($trimmed === '') {
            // An empty identifier is the absence of one, not a value to mask. Returning
            // the bullet run would make "no NIK" look like "a NIK exists".
            return null;
        }

        $length = strlen($trimmed);

        if ($length <= self::VISIBLE_AWAL + self::VISIBLE_AKHIR) {
            return $trimmed;
        }

        return substr($trimmed, 0, self::VISIBLE_AWAL)
            .str_repeat(self::PENGGANTI, $length - self::VISIBLE_AWAL - self::VISIBLE_AKHIR)
            .substr($trimmed, -self::VISIBLE_AKHIR);
    }
}
