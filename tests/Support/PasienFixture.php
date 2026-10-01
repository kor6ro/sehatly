<?php

namespace Tests\Support;

use App\Support\NikCipher;

/**
 * Turns a fixture's `nik` key into the `nik_cipher` payload the column expects.
 *
 * ## Why this exists
 *
 * Migration `2026_10_01_000079` renamed `pasien.nik` to `pasien.nik_cipher`
 * and widened it to `TEXT`, so a fixture that still names `nik` in an INSERT
 * dies on `Unknown column 'nik' in 'field list'` rather than quietly storing a
 * plaintext identifier. The fix is a rename; the trap is that the value also
 * has to be ENCRYPTED, because the renamed column now holds a `NikCipher`
 * payload and not the 16 characters it used to hold.
 *
 * ## Why the ciphertext is written directly instead of going through the model
 *
 * `Pasien` has a `nik` mutator that calls {@see NikCipher::encrypt()}, so
 * `Pasien::save()` is the real write path and it is exercised on purpose
 * elsewhere: `DevFixtureSeeder` writes its rows through the model, and
 * `NikCipherStorageTest` asserts the model's own round trip. The fixture helpers
 * in the test suite exist to land a row in one query so a test that is not about
 * NIK encryption does not pay for a model event and a second round trip, and
 * they are used by well over a hundred tests that each need exactly one row.
 *
 * So the rule this class encodes is: a fixture that names `nik` gets a REAL
 * `NikCipher` payload, produced by the same `encrypt()` the model calls. It is
 * never a hand-rolled string, a base64 of the raw NIK, or the NIK left in
 * plaintext under the new column name - each of those would pass the column
 * check and then fail to decrypt at the first read.
 *
 * Because the payload embeds a random IV, calling this twice with the same NIK
 * yields two different strings. That is the cipher working as designed, and it
 * is why no fixture may assert on a `nik_cipher` value it did not just read back
 * out of the database.
 */
final class PasienFixture
{
    /**
     * Move a `nik` key out of a fixture row and onto `nik_cipher` as a payload.
     *
     * A `null` NIK stays absent rather than becoming an encrypted empty string:
     * the column is nullable, and `NikCipher::decrypt('')` answers `null`, so
     * either shape reads back the same - but a fixture that means "no NIK" should
     * say so with a NULL rather than with a ciphertext of nothing.
     *
     * A row with no `nik` key is returned untouched, which is what lets a helper
     * accept an `$ubah` array where the caller may or may not supply one.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function withNik(array $row): array
    {
        if (! array_key_exists('nik', $row)) {
            return $row;
        }

        $nik = $row['nik'];

        unset($row['nik']);

        if ($nik !== null) {
            $row['nik_cipher'] = NikCipher::encrypt((string) $nik);
        }

        return $row;
    }
}
