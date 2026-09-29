<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NIK encryption
    |--------------------------------------------------------------------------
    |
    | Two keys and a salt, and NOT ONE of them is a secret in this file. The key
    | arrives from the deployment environment; this file only says where to read
    | it from and refuses to invent one.
    |
    | ## Why not `APP_KEY`
    |
    | The obvious shortcut is to derive the NIK key from `config('app.key')`, and
    | it is the wrong answer for a reason that shows up on the worst possible day.
    | `APP_KEY` rotates for reasons that have nothing to do with patient identity:
    | a suspected session-cookie compromise, a new deployment, a framework
    | upgrade. Because the NIK INDEX is derived from the same key, rotating
    | `APP_KEY` would re-derive every NIK index, the duplicate-registration check
    | would start reporting every NIK as untaken, and clinical records would be
    | unreadable until someone noticed and re-encrypted them. Coupling the two
    | makes an unrelated security action destructive.
    |
    | ## `previous_keys` is what makes rotation possible in two phases
    |
    | Every payload carries a two-byte id naming the key that wrote it, so
    | `App\Support\NikCipher::decrypt()` can read a row written by any key in the
    | ring. The order to deploy a rotation in is therefore:
    |
    |   1. set the NEW `NIK_CIPHER_KEY` and move the OLD one into
    |      `NIK_CIPHER_PREVIOUS_KEYS`, then deploy. Reads keep working;
    |   2. re-encrypt every `nik_cipher` row, in the background, comparing
    |      `NikCipher::keyFingerprint()` against the value each row was written
    |      with;
    |   3. once no row references the old key, empty `NIK_CIPHER_PREVIOUS_KEYS`.
    |
    | Skipping step 2 does not fail loudly - it makes the registration uniqueness
    | check report duplicates that are not duplicates, because the index for a
    | re-encrypted row is a different 16 characters from the index of the row it
    | replaced. That is stated in the cipher's docblock and asserted by its suite.
    |
    | ## Generate one per environment
    |
    |     php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
    |
    | The same value must be used by every process that reads or writes NIK
    | columns, which in practice means every node of a deployment. `.env` is
    | untracked; a key committed to git is not a key.
    */

    'key' => env('NIK_CIPHER_KEY'),

    /*
     * Keys that may still DECRYPT, most recently rotated out first. Comma
     * separated in the environment; a list here. This is read only by
     * `decrypt()`, never by `encrypt()`, so a key left in the list keeps old
     * rows readable and writes nothing new under it.
     */
    'previous_keys' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('NIK_CIPHER_PREVIOUS_KEYS', ''))),
        static fn (string $value): bool => $value !== '',
    )),

];
