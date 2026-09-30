<?php

declare(strict_types=1);

use App\Models\Pasien;
use App\Support\NikCipher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// This file is in the global namespace, so `RuntimeException` needs no `use`
// statement - and on PHP 8.4 an explicit one is a diagnostic, not a nicety.

/**
 * Moves the patient NIK out of a plaintext `CHAR(16)` and into `nik_cipher TEXT`.
 *
 * ## What was wrong
 *
 * `telemedicine_test.sql:222` declared
 * `nik CHAR(16) NULL UNIQUE COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP'`
 * and no migration ever changed it, so the comment was the only thing about the
 * column that was encrypted: the column itself held PLAINTEXT national identity
 * numbers. `App\Support\NikCipher` had been written to fix that and was called by
 * nothing on the write path. This migration is the DDL half of the fix.
 *
 * ## An AUTHORISED scope change, narrower than todo 50 asked for
 *
 * Todo 50 specified TWO columns: `nik_cipher TEXT` for the reversible payload and
 * an HMAC `nik_hash` blind index so a NIK could be looked up and a duplicate
 * refused. The product owner authorised the migration WITHOUT the blind index
 * ("migrasi ulang aja tanpa blind dulu gapapa"), so this file adds one column and
 * no index, and `down()` restores the shape it found.
 *
 * What that costs is real and is asserted in
 * `tests/Feature/Pasien/NikCipherStorageTest.php`:
 *
 *  - a NIK can no longer be FOUND by equality, because {@see NikCipher}
 *    uses a random IV, so one NIK is two payloads and the column holds nothing
 *    deterministic to compare;
 *  - a duplicate NIK can no longer be REFUSED, because the DDL's `UNIQUE` had to
 *    go with the column (a `TEXT` payload is unique per row by construction, and
 *    MySQL will not put a `UNIQUE` on a `TEXT` without a prefix length, which
 *    would be a uniqueness check over the first N characters of base64 and so
 *    meaningless);
 *  - looking a patient up by NIK therefore means decrypting every row and
 *    comparing, which is O(rows) and cannot use any index.
 *
 * The `UNIQUE` that comes off here is a genuine loss of a data-integrity
 * guarantee, and it is recorded rather than papered over. Restoring it is a
 * follow-up that needs the blind index back.
 *
 * ## Why the order is drop-index, rename, widen
 *
 * Three statements, not one. `renameColumn` compiles to `RENAME COLUMN`, which
 * carries the existing `UNIQUE` across to the new name, so the index has to come
 * off FIRST or it silently follows the column and then cannot be dropped by a
 * name the migration knows. Widening the type is last because `RENAME COLUMN`
 * preserves the declared type, and a separate `MODIFY` then states `TEXT` in full
 * - including `NULL` and the column `COMMENT`, which a bare `MODIFY` would drop
 * and which `sehatly:verify-schema` compares against the DDL.
 *
 * ## No backfill, and that is the owner's call
 *
 * The owner asked to re-migrate, so this file does not read the plaintext out of
 * the old column and write a payload into the new one. **Any `pasien` row that
 * existed before this migration loses its NIK**: the column is renamed, so the
 * 16 plaintext digits are reinterpreted as a `TEXT` payload, and
 * `NikCipher::decrypt()` refuses them on the authentication tag rather than
 * returning them. That is the intended failure - a silent "decryption" of
 * plaintext would publish a mask built from bytes nobody chose. A deployment that
 * holds real patients re-migrates and re-collects; a deployment that must keep
 * them needs a backfill pass, which is out of scope here.
 *
 * @see NikCipher for the payload format and the key it needs
 * @see Pasien for the encrypt-on-write, decrypt-on-read accessors
 */
return new class extends Migration
{
    /**
     * The column comment the DDL declares, restated because a `MODIFY` replaces
     * the whole column definition and an omitted `COMMENT` would leave the live
     * schema disagreeing with `telemedicine_test.sql:222`.
     */
    private const COMMENT = 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP';

    public function up(): void
    {
        // 1. The DDL's UNIQUE. Dropped before the rename so it cannot follow the
        //    column to a name this migration does not know. The DDL declares it
        //    inline (`nik CHAR(16) NULL UNIQUE`) and MySQL would name that index
        //    `nik`, but this schema was built by the migrations rather than by
        //    importing the reference SQL, and the migration that created the
        //    column named it `pasien_nik_unique`. Passing the COLUMN rather than
        //    the index name is what makes that difference irrelevant - Laravel
        //    derives the conventional name - and the parity verifier compares the
        //    two declarations semantically, so the name never had to match.
        Schema::table('pasien', function (Blueprint $table): void {
            $table->dropUnique(['nik']);
        });

        // 2. The name, carrying `CHAR(16) NULL` across unchanged.
        Schema::table('pasien', function (Blueprint $table): void {
            $table->renameColumn('nik', 'nik_cipher');
        });

        // 3. The width, which is the whole reason this migration exists: the
        //    payload is 88 characters and a `CHAR(16)` refuses it with 1406.
        Schema::table('pasien', function (Blueprint $table): void {
            $table->text('nik_cipher')->nullable()->comment(self::COMMENT)->change();
        });
    }

    /**
     * Reverses the shape, and REFUSES to reverse it onto a stored NIK.
     *
     * A `CHAR(16)` cannot hold an 88-character payload. Rolling this migration
     * back against a populated table would either truncate every ciphertext in
     * non-strict SQL mode or raise 1406 in strict mode, and both destroy a
     * patient's identity number, so the rollback is refused with a message that
     * says how many rows are at stake and what to do instead.
     *
     * The reversal IS performed when the table holds no payload, so the shape is
     * genuinely reversible and not merely declined: an empty table goes back to
     * `nik CHAR(16) NULL UNIQUE` and `up()` puts it forward again.
     */
    public function down(): void
    {
        $tersimpan = DB::table('pasien')->whereNotNull('nik_cipher')->where('nik_cipher', '<>', '')->count();

        if ($tersimpan > 0) {
            throw new RuntimeException(
                'Refusing to roll back the NIK cipher migration: '.$tersimpan.' `pasien` row(s) hold a'
                .' ciphertext in `nik_cipher`, and `CHAR(16)` cannot hold an 88-character payload, so'
                .' rolling back would destroy every one of those NIKs (truncated in non-strict mode,'
                .' error 1406 in strict mode). Take a mysqldump of `pasien`.`nik_cipher` first if the'
                .' rows are needed, then re-run `php artisan migrate` to go forward again. The owner'
                .' asked for a re-migration rather than a backfill, so no plaintext exists to'
                .' restore.',
            );
        }

        // The shape, in the mirror image of `up()`: rename FIRST, because a
        // `MODIFY` names the column it restates and the column does not exist
        // under the target name until the rename has run. Widening the name
        // first and narrowing the type second is the order that works, and it is
        // the order the first draft of this method had backwards - the test
        // `test_down_reverses_the_shape_on_an_empty_table_and_up_puts_it_forward`
        // failed with MySQL 1054 `Unknown column 'nik'` before it was fixed.
        Schema::table('pasien', function (Blueprint $table): void {
            $table->renameColumn('nik_cipher', 'nik');
        });

        Schema::table('pasien', function (Blueprint $table): void {
            $table->char('nik', 16)->nullable()->comment(self::COMMENT)->change();
        });

        // The index the DDL declared, restored after the rename so it is created
        // on the name the DDL names.
        Schema::table('pasien', function (Blueprint $table): void {
            $table->unique('nik');
        });
    }
};
