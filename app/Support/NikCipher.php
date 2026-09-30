<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Security\MissingNikCipherKeyException;
use App\Support\Security\NikDecryptionException;

/**
 * Protects a NIK with two columns of different widths, because the one column
 * the DDL declares cannot do both jobs.
 *
 * ## The problem, in two requirements
 *
 * `telemedicine_test.sql:222` is
 * `nik CHAR(16) NULL UNIQUE COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP'`
 * and `telemedicine_test.sql:263` is `nik CHAR(16) NULL` on
 * `pasien_anggota_keluarga`. A NIK has to satisfy two things at once:
 *
 *  1. **It must be reversible.** `PasienResource` publishes a masked NIK that
 *     keeps the first four and the last four characters, and a one-way function
 *     can never produce a trailing four digits. So the stored form has to be an
 *     ENCRYPTION, not a digest.
 *  2. **It must be unique-comparable.** The DDL puts `UNIQUE` on the column, and
 *     two patients sharing one national identity is a data-integrity defect the
 *     schema is required to reject. So the stored form has to be SEARCHABLE and
 *     DETERMINISTIC, which an encryption deliberately is not.
 *
 * A 16-character column satisfies neither. This class therefore produces two
 * values from one input:
 *
 * | method    | width | purpose                                                    |
 * |-----------|-------|------------------------------------------------------------|
 * | `encrypt` | 88    | the AES-256-CBC payload, for a `TEXT` column                |
 * | `index`   | 16    | the HMAC-SHA-256 blind index, for the `UNIQUE` lookup      |
 *
 * **The schema change that has to be authored is a separate concern and is not
 * part of this class.** See the evidence file for the exact DDL.
 *
 * ## Why the payload is authenticated and not just encrypted
 *
 * AES-CBC without a tag is malleable, and the only integrity signal it offers
 * is whether the PKCS#7 padding happens to be well formed - which succeeds on
 * wrong input once in 256. That is not a theoretical footnote: zeroing the IV of
 * a real payload produced 17 bytes of plausible-looking garbage that passed the
 * padding check, and a masked NIK built from those bytes would have been served
 * to a client as a real patient identifier. So the payload is
 * **encrypt-then-MAC**: a 12-byte HMAC-SHA-256 tag over the header, the IV and
 * the ciphertext, verified with `hash_equals` BEFORE `openssl_decrypt` is called.
 * The ordering is load-bearing; the other order is the padding oracle.
 *
 * ## Why the index is an HMAC of the PLAINTEXT, not a hash of the CIPHERTEXT
 *
 * The shortcut - `substr(base64_encode(hash($ciphertext)), 0, 16)` - cannot work,
 * and the reason is arithmetic rather than taste. {@see encrypt()} uses a random
 * IV, so one NIK encrypted twice yields two payloads; their digests differ; a
 * `UNIQUE` index over those digests does not fire; and the duplicate is admitted.
 * The only way to make a ciphertext-derived index deterministic is to fix the IV,
 * which turns the whole column into deterministic encryption and leaks equality
 * everywhere the ciphertext is read - the data file, the binlog, a backup, a
 * query log.
 *
 * Equality leakage is a cost this project has to pay ONCE. It is paid in the
 * index, which is a column built for lookup and nothing else; it is not also paid
 * in the ciphertext, which exists so a human-authorised read can recover the
 * value.
 *
 * ## Why an HMAC and not a bare SHA-256
 *
 * Because a bare digest of a 16-digit number is reproducible by brute force by
 * anyone holding the column, and truncating one to 16 characters does not change
 * that. The NIK space is 10^16, which is enumerable. A keyed MAC is not, so a
 * database-only leak - a backup, a replica, a binlog, a stolen dump - is not
 * enough to confirm a guessed identity. The key is what makes the index a blind
 * index rather than a slow-motion plaintext.
 *
 * ## What the blind index costs, stated honestly
 *
 * This is a genuine trade-off and not a free win. The index makes equal NIKs
 * **linkable**: an observer holding the column can group rows and learn which
 * patients share an identity, and an observer holding the column AND the key can
 * **confirm** a guessed NIK by recomputing an index and looking for a match.
 *
 * It is acceptable here for one reason and one reason only: the DDL *mandates*
 * `UNIQUE` on `pasien.nik`, so deduplication is a stated integrity requirement
 * that cannot simply be dropped in the name of a nicer privacy property. A
 * national registry that admits two rows for one person is a data-quality defect
 * that propagates into every clinical record attached to that person.
 *
 * It is NOT acceptable on `pasien_anggota_keluarga.nik`, which the DDL leaves
 * without a `UNIQUE` (`telemedicine_test.sql:263`). An index there would carry
 * the full linkage cost and buy no integrity guarantee, so `index()` is an
 * explicit call and that table's migration should carry `nik_cipher` alone.
 *
 * ## Key source, missing key, and rotation
 *
 * The key comes from `NIK_CIPHER_KEY` in the environment, never from a tracked
 * file - `config/nik.php` reads it and ships no value, and `.env` is untracked.
 * A key committed to git is not a key.
 *
 * A missing or malformed key raises {@see MissingNikCipherKeyException} on every
 * entry point. It does not fall back to `APP_KEY`, and it does not fall back to a
 * default, because `openssl_encrypt` accepts a short key without complaint and
 * would quietly encrypt every patient identity under a value nobody recorded.
 *
 * The root key is expanded into TWO independent subkeys with `hash_hkdf`, so the
 * cipher key and the index key are not the same secret: holding one does not give
 * you the other. Rotation is therefore a **re-encryption pass over the table**,
 * not a config edit, and this class is built to make that pass possible:
 *
 *  - every payload carries a two-byte id derived from the key that wrote it;
 *  - `NIK_CIPHER_PREVIOUS_KEYS` accepts older keys, and `decrypt()` will read a
 *    row written by any of them, so the new key can be deployed before the rows
 *    are rewritten;
 *  - `indexKeyFingerprint()` names the key currently in force, which is what a
 *    rotation runbook reads to know which pass is outstanding.
 *
 * ## What rotation DOES break, so nobody is surprised
 *
 * Rotating the key re-derives the index. A row re-encrypted under the new key
 * gets a new index, and until EVERY row has been rewritten the registration
 * uniqueness check reports a NIK as "not taken" when it is. That is why rotation
 * is a two-phase operation - deploy the new key with the old one listed, rewrite
 * the rows, then drop the old key - and why the index is NOT derived from
 * `APP_KEY`: `APP_KEY` rotates for reasons that have nothing to do with patient
 * identity (session encryption, a compromised cookie key), and coupling the two
 * would destroy clinical data as a side effect of an unrelated security action.
 *
 * @see NikMasker for the single masking rule every column shares.
 */
final class NikCipher
{
    /**
     * The four bytes every payload starts with, so a column holding something
     * else is detected rather than fed to openssl.
     */
    public const MAGIC = 'NKC1';

    /** AES-256-CBC: 32-byte key, 16-byte IV. */
    public const CIPHER = 'aes-256-cbc';

    /** Bytes of random IV stored beside every ciphertext. */
    public const IV_LENGTH = 16;

    /** Bytes of key id stored in the header: MAGIC plus this, then the IV. */
    public const KEY_ID_LENGTH = 2;

    /** The raw key length, in bytes. Not characters: base64 makes them differ. */
    public const KEY_LENGTH = 32;

    /** MAGIC (4) plus key id (2). */
    public const PAYLOAD_HEADER_LENGTH = 6;

    /**
     * Bytes of HMAC-SHA-256 tag appended to every payload.
     *
     * AES-CBC on its own is NOT authenticated: the only integrity signal is
     * whether the PKCS#7 padding happens to be well formed, and that succeeds on
     * wrong input once in 256. Measured, not assumed - zeroing the IV of a real
     * payload produced 17 bytes of plausible-looking garbage that passed the
     * padding check, which is a live version of the classic CBC padding-oracle
     * weakness. Encrypt-then-MAC removes the probability: the tag is verified
     * BEFORE openssl is called, so a tampered payload is refused every time.
     *
     * Twelve bytes is 96 bits, which is the same width as the blind index and
     * four times the birthday bound anyone needs to forge one by accident.
     */
    public const MAC_LENGTH = 12;

    /**
     * Ciphertext length for a 16-digit NIK under PKCS#7: 16 bytes of plaintext
     * always pad up to the next 16-byte block, and a full block of padding is
     * added when the plaintext is an exact multiple, so this is 32 either way.
     */
    public const CIPHERTEXT_LENGTH = 32;

    /**
     * A whole stored payload for a 16-digit NIK, before base64.
     *
     * 6 header + 16 IV + 32 ciphertext + 12 tag = 66, which is 22 base64 groups
     * exactly, so the stored form is 88 characters with no padding character.
     */
    public const PAYLOAD_LENGTH = self::PAYLOAD_HEADER_LENGTH + self::IV_LENGTH + self::CIPHERTEXT_LENGTH + self::MAC_LENGTH;

    /**
     * The blind index width, in characters.
     *
     * Sixteen base64 characters carry twelve bytes, so 96 of the 256 HMAC bits
     * survive the truncation. A birthday collision over the whole Indonesian
     * population of roughly 1.3e8 NIKs is expected about 1.3e8^2 / 2^97 times,
     * which is on the order of 1e-11. Halving this to 8 characters would leave
     * 48 bits and make collisions likely enough to matter, so the width is a
     * constant that is asserted rather than a number recomputed per call.
     */
    public const INDEX_LENGTH = 16;

    /** The MAC behind the index. */
    public const INDEX_ALGORITHM = 'sha256';

    /** HKDF `info` for the cipher subkey. Public; it is a domain label. */
    public const CIPHER_KEY_INFO = 'sehatly/nik/cipher/v1';

    /** HKDF `info` for the index subkey. */
    public const INDEX_KEY_INFO = 'sehatly/nik/index/v1';

    /** HKDF `info` for the payload authentication subkey. */
    public const MAC_KEY_INFO = 'sehatly/nik/mac/v1';

    /**
     * HKDF `salt`. Public by design: a salt is not a secret, and a fixed one
     * keeps the derivation reproducible across restarts and across the two
     * tables that store a NIK.
     */
    public const KEY_SALT = 'sehatly-nik-v1';

    /** The environment variable holding the current root key. */
    public const ENV_KEY = 'NIK_CIPHER_KEY';

    /** The environment variable holding keys that may still decrypt rows. */
    public const ENV_PREVIOUS_KEYS = 'NIK_CIPHER_PREVIOUS_KEYS';

    /**
     * Where `config/nik.php` puts the current key, and where the previous ones.
     *
     * Distinct from {@see ENV_KEY} on purpose and because the two are different
     * names for different things: `NIK_CIPHER_KEY` is what an operator writes in
     * the environment, `nik.key` is where this repository reads it. Reading
     * `config('NIK_CIPHER_KEY')` returns null forever and every call site raises,
     * which is exactly the failure this class refuses to make quietly.
     */
    public const CONFIG_KEY = 'nik.key';

    public const CONFIG_PREVIOUS_KEYS = 'nik.previous_keys';

    /**
     * The proposed column names. They are constants rather than string literals
     * at the call sites so the proposal, the test that proves the widths and the
     * migration somebody has to author cannot drift apart.
     */
    public const COL_PAYLOAD = 'nik_cipher';

    public const COL_INDEX = 'nik_index';

    /**
     * Encrypt `$plaintext` into a self-describing base64 payload.
     *
     * The input is TRIMMED first, and that is load-bearing rather than tidiness:
     * both NIK columns are `CHAR(16)`, which MySQL right-pads on retrieval, so an
     * untrimmed round trip would encrypt a 16-character value on the way in and
     * hand back four spaces on the way out. Trimming on the way in means the
     * stored payload and the blind index both describe the SAME 16 digits, and a
     * padded read still matches the index an unpadded write produced.
     */
    public static function encrypt(string $plaintext): string
    {
        $kunci = self::kunciCipher();

        $iv = random_bytes(self::IV_LENGTH);

        $ciphertext = openssl_encrypt(
            trim($plaintext),
            self::CIPHER,
            $kunci,
            OPENSSL_RAW_DATA,
            $iv,
        );

        if ($ciphertext === false) {
            // Unreachable with a validated 32-byte key and a known cipher, and
            // refused anyway: silently returning an empty string here would store
            // a value that decrypts to nothing.
            throw new NikDecryptionException('AES-256-CBC refused the payload, so no ciphertext was produced.');
        }

        // Encrypt-then-MAC: the tag covers the header as well as the IV and the
        // ciphertext, so a payload whose key id or IV has been edited is caught
        // by the same check.
        $tubuh = self::MAGIC.self::keyId($kunci).$iv.$ciphertext;

        return base64_encode($tubuh.self::mac($tubuh, self::rootKey()));
    }

    /**
     * The authentication tag over a payload body, under a named root key.
     *
     * The root key is a parameter rather than read from the current config
     * because {@see decrypt()} has to authenticate a payload that a PREVIOUS key
     * wrote: a tag checked against the key currently in force would reject every
     * row from before a rotation, which is the opposite of what the previous-key
     * list is for.
     *
     * The MAC subkey is derived separately from the cipher subkey, so a
     * compromise of one does not hand over the other - the same
     * domain-separation argument the index key makes.
     */
    private static function mac(string $body, string $rootKey): string
    {
        return substr(
            hash_hmac(self::INDEX_ALGORITHM, $body, self::expand($rootKey, self::MAC_KEY_INFO), true),
            0,
            self::MAC_LENGTH,
        );
    }

    /**
     * Read a payload back, or `null` when the column is empty.
     *
     * An EMPTY column is the absence of an identifier and answers `null`; a
     * present-but-unreadable one raises. The distinction matters because both
     * `nik` columns are `NULL`-able: a patient with no NIK on file must render
     * as no NIK, while a patient whose NIK cannot be read must not render as one.
     */
    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }

        $raw = base64_decode(trim($payload), true);

        if ($raw === false) {
            throw NikDecryptionException::notBase64();
        }

        if (strlen($raw) < self::PAYLOAD_HEADER_LENGTH + self::IV_LENGTH) {
            throw NikDecryptionException::tooShort(strlen($raw));
        }

        if (substr($raw, 0, 4) !== self::MAGIC) {
            throw NikDecryptionException::wrongMagic();
        }

        $wantedId = substr($raw, 4, self::KEY_ID_LENGTH);
        $iv = substr($raw, self::PAYLOAD_HEADER_LENGTH, self::IV_LENGTH);
        $ciphertext = substr($raw, self::PAYLOAD_HEADER_LENGTH + self::IV_LENGTH, self::CIPHERTEXT_LENGTH);
        $tag = substr($raw, self::PAYLOAD_LENGTH - self::MAC_LENGTH, self::MAC_LENGTH);
        $body = substr($raw, 0, self::PAYLOAD_LENGTH - self::MAC_LENGTH);

        foreach (self::keyRing() as $key) {
            $kunci = self::expand($key, self::CIPHER_KEY_INFO);

            if (! hash_equals(self::keyId($kunci), $wantedId)) {
                continue;
            }

            // Authenticate BEFORE decrypting, and under the key that WROTE the
            // payload rather than the one in force. This ordering is the whole
            // reason the tag exists: doing it in the other order would hand an
            // attacker the padding oracle the construction is famous for.
            if (! hash_equals(self::mac($body, $key), $tag)) {
                throw NikDecryptionException::corrupt();
            }

            $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $kunci, OPENSSL_RAW_DATA, $iv);

            if ($plaintext === false || $plaintext === '') {
                throw NikDecryptionException::corrupt();
            }

            return $plaintext;
        }

        // The header names a key that is not configured. This is checked BEFORE
        // openssl runs, so a wrong-key decryption is refused deterministically
        // rather than by leaning on a padding check that succeeds once in 256.
        throw NikDecryptionException::unknownKey();
    }

    /**
     * The deterministic half: 16 characters that identify this NIK across rows
     * without revealing it, and that a `UNIQUE` index can compare.
     *
     * Trimmed for the reason {@see encrypt()} is: an index that changed when
     * MySQL right-padded a `CHAR(16)` on retrieval would break the duplicate
     * check exactly on legacy rows.
     */
    public static function index(string $plaintext): string
    {
        return substr(
            base64_encode(hash_hmac(self::INDEX_ALGORITHM, trim($plaintext), self::kunciIndex(), true)),
            0,
            self::INDEX_LENGTH,
        );
    }

    /**
     * Is `$index` the index of `$plaintext`?
     *
     * `hash_equals` rather than `===`, and the stored value is length-checked
     * first so a truncated column cannot be compared byte by byte and leak its
     * length through timing.
     */
    public static function indexMatches(?string $index, string $plaintext): bool
    {
        if ($index === null || strlen($index) !== self::INDEX_LENGTH) {
            return false;
        }

        return hash_equals(self::index($plaintext), $index);
    }

    /**
     * The one masking rule, applied to whichever representation the column holds.
     *
     * `$payload` is the proposed `nik_cipher` column, which does not exist yet, so
     * today it is always `null` and this is a straight delegation. Once the
     * migration lands it becomes decrypt-then-mask, and both arguments continue
     * to work: `pasien.nik` is kept for legacy and imported plaintext rows, which
     * must still be maskable.
     *
     * The masking itself is NOT reimplemented here. {@see NikMasker} is the single
     * rule every NIK column shares, and a second implementation of "keep four,
     * bullet the rest, keep four" is a second place for the next edit to go wrong.
     */
    public static function mask(?string $payload, ?string $plaintext = null): ?string
    {
        if ($payload !== null && trim($payload) !== '') {
            return NikMasker::mask(self::decrypt($payload));
        }

        return NikMasker::mask($plaintext);
    }

    /**
     * Is a usable root key configured? For a boot-time health check and for
     * `artisan about`, never as a substitute for the guards themselves.
     */
    public static function hasKey(): bool
    {
        return self::rawKey(config(self::CONFIG_KEY)) !== null;
    }

    /**
     * Sixteen hex characters naming the cipher subkey currently in force.
     *
     * Derived from the SUBKEY rather than the root, so it names the key that
     * actually encrypts, and it is safe to log or print: it identifies a key
     * without being one.
     */
    public static function keyFingerprint(): string
    {
        return substr(hash(self::INDEX_ALGORITHM, self::kunciCipher()), 0, 16);
    }

    /**
     * Sixteen hex characters naming the index subkey currently in force.
     *
     * This is the value a rotation runbook reads: while it differs from the value
     * a row was written with, that row's index no longer matches its NIK.
     */
    public static function indexKeyFingerprint(): string
    {
        return substr(hash(self::INDEX_ALGORITHM, self::kunciIndex()), 0, 16);
    }

    // ------------------------------------------------------------- key material

    /**
     * The current root key as raw bytes, or `null` when it is unusable.
     *
     * A `base64:` prefix is accepted because that is Laravel's own convention for
     * `APP_KEY` and an operator who has generated one key should not have to
     * remember which of two shapes this one wants.
     */
    private static function rawKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'base64:')) {
            $value = substr($value, 7);
        }

        $raw = base64_decode($value, true);

        if ($raw === false || strlen($raw) !== self::KEY_LENGTH) {
            return null;
        }

        return $raw;
    }

    /** The current root key, or the refusal that says how to fix its absence. */
    private static function rootKey(): string
    {
        $raw = self::rawKey(config(self::CONFIG_KEY));

        if ($raw === null) {
            throw MissingNikCipherKeyException::forVariable(
                'is not set, is empty, or is not '.self::KEY_LENGTH.' bytes encoded as base64',
            );
        }

        return $raw;
    }

    /**
     * Every usable configured key, current first.
     *
     * Duplicates are dropped by id rather than by value so a key listed twice
     * costs one decryption attempt rather than two.
     *
     * @return list<array{0: string, 1: string}> raw root keys, current first
     */
    private static function keyRing(): array
    {
        $current = self::rawKey(config(self::CONFIG_KEY));

        if ($current === null) {
            throw MissingNikCipherKeyException::forVariable(
                'is not set, is empty, or is not '.self::KEY_LENGTH.' bytes encoded as base64',
            );
        }

        $ring = [$current];
        $seen = [self::keyId(self::expand($current, self::CIPHER_KEY_INFO)) => true];

        $previous = config(self::CONFIG_PREVIOUS_KEYS);

        foreach (is_array($previous) ? $previous : [] as $candidate) {
            $raw = self::rawKey($candidate);

            if ($raw === null) {
                continue;
            }

            $id = self::keyId(self::expand($raw, self::CIPHER_KEY_INFO));

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $ring[] = $raw;
        }

        return $ring;
    }

    /**
     * Expand a root key into a subkey for one purpose.
     *
     * HKDF rather than the root key used directly, so the cipher key and the
     * index key are independent secrets. Someone who learns the index key - it is
     * the value a `UNIQUE` index is built over, so it is the one that touches the
     * most infrastructure - still cannot decrypt a payload.
     */
    private static function expand(string $rootKey, string $info): string
    {
        return hash_hkdf(self::INDEX_ALGORITHM, $rootKey, self::KEY_LENGTH, $info, self::KEY_SALT);
    }

    private static function kunciCipher(): string
    {
        return self::expand(self::rootKey(), self::CIPHER_KEY_INFO);
    }

    private static function kunciIndex(): string
    {
        return self::expand(self::rootKey(), self::INDEX_KEY_INFO);
    }

    /**
     * The two bytes that name the key a payload was written with.
     *
     * Derived from the SUBKEY, not the root, so a key id identifies the key that
     * actually encrypted. Two bytes is a routing hint across a handful of keys,
     * not a security boundary: a collision costs one wasted `openssl_decrypt`,
     * and the padding check still has to pass.
     */
    private static function keyId(string $cipherKey): string
    {
        return (string) hex2bin(substr(hash(self::INDEX_ALGORITHM, $cipherKey), 0, 4));
    }
}
