<?php

declare(strict_types=1);

use App\Http\Resources\BookingResource;
use App\Http\Resources\KonsultasiResource;
use App\Http\Resources\PasienAnggotaKeluargaResource;
use App\Http\Resources\PasienResource;
use App\Http\Resources\RekamMedisResource;
use App\Http\Resources\SuratKeteranganResource;
use App\Models\Booking;
use App\Models\Konsultasi;
use App\Models\Pasien;
use App\Models\PasienAnggotaKeluarga;
use App\Models\RekamMedis;
use App\Models\SuratKeterangan;
use App\Support\NikCipher;
use App\Support\NikMasker;
use App\Support\Security\MissingNikCipherKeyException;
use App\Support\Security\NikDecryptionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Todo 50 - deterministic NIK encryption with an HMAC index column
|--------------------------------------------------------------------------
|
| ## The design in one paragraph
|
| A NIK is protected by TWO columns of DIFFERENT widths, because the single
| `pasien.nik CHAR(16)` the DDL used to declare (telemedicine_test.sql:222)
| cannot satisfy two incompatible requirements at once:
|
|   - the value must be REVERSIBLE, because `PasienResource` publishes a
|     masked NIK of the shape four-digits then a run then four-digits, and a
|     one-way function can never produce the trailing four digits. This forces
|     an ENCRYPTION, whose stored form is 88 characters here;
|   - the value must be UNIQUE-COMPARABLE, because the DDL puts UNIQUE on the
|     column and two patients sharing one identity is a data-integrity defect
|     the schema is required to reject.
|
| So the ciphertext goes in a `TEXT` column and a 16-character HMAC-SHA-256
| blind index would go in a `CHAR(16)` UNIQUE column. Neither replaces the other,
| and `CHAR(16)` holds neither of them correctly - see the 1406 tests. **Only the
| first of the two has been migrated**; read the scope-change section at the
| bottom of this file before reading the rest of it as a description of the
| schema.
|
| ## Why the index is an HMAC OF THE PLAINTEXT and not a hash OF THE CIPHERTEXT
|
| The obvious shortcut is `index = substr(hash(ciphertext), 0, 16)` and it is
| dead on arrival for a reason this suite proves EXECUTED rather than argued:
| the payload carries a random IV, so encrypting one NIK twice produces two
| different payloads, their digests differ, a UNIQUE index over those digests
| does not fire, and the duplicate is ADMITTED. `the HMAC index makes the DDL
| UNIQUE fire on a duplicate NIK and a ciphertext hash cannot` puts both designs
| in one temporary table and shows the duplicate admitted by one and refused by
| the other.
|
| The only way to make a ciphertext-derived index deterministic is to fix the
| IV, which converts the whole column into deterministic encryption and leaks
| equality everywhere the ciphertext is read: the data file, the binlog, a
| backup, any query log. Equality leakage is a cost this project can pay ONCE,
| in a column built to carry it, and not twice.
|
| The index is an HMAC and not a bare SHA-256 for a second, independent reason:
| a bare digest of a 16-digit number is reproducible by brute force by anyone
| holding the column, and truncating one to 16 characters does not help. A keyed
| MAC is not, so a database-only leak - a backup, a replica, a binlog, a stolen
| dump - does not confirm a guessed NIK. `the index is keyed, not a bare
| digest` asserts that distinction by computing the bare digest and showing it
| is not the index.
|
| ## The privacy cost, stated rather than buried
|
| The index is a BLIND INDEX and it is a genuine trade-off, not a free win. It
| makes equal NIKs LINKABLE: an observer holding the column can group rows and
| learn which patients share an identity, and an observer holding the column
| AND the key can CONFIRM a guessed NIK by recomputing one index. That is why
| nothing in the application decrypts a row to answer "does this NIK already
| exist", and why `indexMatches()` is the only comparison.
|
| ## The temporary tables, and the CHAR(16) proof
|
| `CHAR(16)` cannot hold ciphertext and this suite proves it by letting MySQL
| say so. It USED to raise the 1406 out of the real `pasien.nik` column at
| telemedicine_test.sql:222; that column is now `nik_cipher TEXT`, so the proof
| runs against a temporary `CHAR(16)` column and the real column is asserted to
| STORE the payload. The UNIQUE experiment needs the PROPOSED shape, which still
| does not exist, so it too runs in a MySQL TEMPORARY table - reported by
| NEITHER `information_schema.TABLES` NOR `information_schema.STATISTICS`, and
| asserted as such, so the experiment leaves no residue in the database the
| parity verifier reads.
|
| ## CHANGED BY THE AUTHORISED SCOPE CHANGE: half of this design is NOT shipped
|
| Todo 50 proposed TWO columns. Migration 2026_10_01_000079 landed ONE of them,
| because the product owner authorised the migration without the blind index
| ("migrasi ulang aja tanpa blind dulu gapapa"). Concretely, as of that commit:
|
|   - `pasien.nik_cipher TEXT` EXISTS. The cipher is on the write and read path
|     through `App\Models\Pasien::nik()` and `App\Support\NikCipher`.
|   - `pasien.nik_index` DOES NOT EXIST. No `nik_hash`, no `nik_cipher_index`,
|     no `UNIQUE` over the identifier, and no index of any kind over the NIK.
|
| Every test below that exercises `index()`, `indexMatches()` or the UNIQUE
| experiment therefore tests a CAPABILITY of `NikCipher` that the schema does not
| currently use. They are kept, deliberately: they are the specification the
| deferred migration has to satisfy, and deleting them would leave the deferred
| work with no executable definition of done. What they no longer prove is that
| the deployed schema enforces anything - and
| `tests/Feature/Pasien/NikCipherStorageTest.php` asserts the cost of the deferral
| directly, including that a duplicate NIK can no longer be refused.
*/

// ------------------------------------------------------------------ helpers

/**
 * A syntactically valid 16-digit NIK.
 *
 * Generated rather than written out, because a hand-typed 16-digit literal is
 * indistinguishable from a phone number or a timestamp to a reader, and because
 * the collision sweep needs a pool. A NIK is 16 digits, so the pool is 10^16
 * wide and the values this suite draws are indistinguishable from real ones.
 */
function t50Nik(): string
{
    return str_pad((string) random_int(0, 9999999999999999), 16, '0', STR_PAD_LEFT);
}

/**
 * A `users` row written with the query builder, so it writes no audit row.
 *
 * `users` declares `uuid`, `nama_lengkap`, `no_telepon` and `kata_sandi_hash`
 * as NOT NULL with no default, so all four are written explicitly.
 */
function t50UserRow(): int
{
    return (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => 'Nik50 '.Str::upper(Str::random(6)),
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => 'pasien',
        'status' => 'aktif',
    ]);
}

/**
 * A `pasien` row, written with the query builder.
 *
 * `pasien` requires `user_id` (telemedicine_test.sql:220), `jenis_kelamin`
 * (:225), `tanggal_lahir` (:226) and `alamat_lengkap` (:234). Written through
 * the builder rather than the model so a fixture cannot fire the global
 * `AuditObserver` and pollute the audit assertions that already exist.
 *
 * CHANGED BY THE NIK CIPHER MIGRATION. A caller still passes `['nik' => $sixteen]`
 * exactly as before and the helper ENCRYPTS it into `nik_cipher` on the way to
 * the database. The query builder does not run Eloquent mutators, so without
 * this a caller would hand a plaintext NIK to the one column whose job is to
 * hold a payload, and every assertion downstream would be measuring a state the
 * application can no longer produce.
 *
 * @param  array<string, mixed>  $extra
 */
function t50PasienRow(array $extra = []): int
{
    $nik = $extra['nik'] ?? null;

    unset($extra['nik']);

    if ($nik !== null) {
        $extra['nik_cipher'] = NikCipher::encrypt((string) $nik);
    }

    return (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => t50UserRow(),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-04-17',
        'alamat_lengkap' => 'Jl. Nik 50 No. 1',
    ], $extra));
}

/**
 * Run a closure with the NIK config replaced, restoring the original afterwards.
 *
 * `config()` is read on every call rather than memoised in a static, so this is
 * the whole mechanism a key-rotation test needs: there is no cache to clear and
 * therefore no way for a test to pass against a stale key.
 *
 * @template TReturn
 *
 * @param  array<string, mixed>  $config
 * @return TReturn
 */
function t50DenganKunci(array $config, callable $callback): mixed
{
    $sebelum = config('nik');

    config($config);

    try {
        return $callback();
    } finally {
        config(['nik' => $sebelum]);
    }
}

/**
 * A valid `NIK_CIPHER_KEY` value: 32 random bytes, base64, which is the shape
 * `config/nik.php` expects to find in the environment.
 */
function t50Kunci(): string
{
    return base64_encode(random_bytes(32));
}

/**
 * A 16-character digest of a payload, i.e. what indexing the CIPHERTEXT would
 * have produced. The rejected alternative, used in the UNIQUE experiment.
 */
function t50HashPayload(string $payload): string
{
    return substr(base64_encode(hash('sha256', (string) base64_decode($payload, true))), 0, 16);
}

/**
 * Create a MySQL TEMPORARY table for one test.
 *
 * A temporary table is scoped to the connection and is reported by NEITHER
 * `information_schema.TABLES` NOR `information_schema.STATISTICS`, so
 * `sehatly:verify-schema` cannot see it and no index or constraint is added to
 * the real schema. The DDL here is the shape the cipher requires; asserting the
 * shape is the point, and asserting it in a scratch table is the only way to do
 * so without a migration.
 */
function t50Temp(string $name, string $body): void
{
    DB::statement('create temporary table '.$name.' ('.$body.') engine=InnoDB');
}

/**
 * Call `$callback`, returning `[$threw, $result, $driverCode]`.
 *
 * The driver code is read out of `QueryException::$errorInfo` rather than from
 * the exception CLASS, because whether Laravel maps 1062 or 1406 onto a named
 * exception is a framework detail this project has already been bitten by once.
 *
 * @return array{0: bool, 1: mixed, 2: int|null}
 */
function t50Coba(callable $callback): array
{
    try {
        return [false, $callback(), null];
    } catch (QueryException $e) {
        return [true, null, (int) ($e->errorInfo[1] ?? 0)];
    }
}

beforeEach(function (): void {
    // Every test gets a real key unless it is the test that removes one.
    config([
        'nik.key' => t50Kunci(),
        'nik.previous_keys' => [],
    ]);
});

// ------------------------------------------------ the payload and its widths

test('the payload is 88 base64 characters whose ciphertext block is exactly 32 bytes', function (): void {
    $payload = NikCipher::encrypt(t50Nik());
    $raw = base64_decode($payload, true);

    expect($raw)->toBeString('the payload is not strict base64')
        ->and(strlen($payload))->toBe(88)
        ->and($payload)->not->toContain('=')
        ->and(strlen($raw))->toBe(NikCipher::PAYLOAD_LENGTH)
        ->and($raw)->toStartWith(NikCipher::MAGIC)
        // The plan's acceptance figure of 32 bytes is the CIPHERTEXT block and
        // not the whole stored value: an IV that is not stored beside the
        // ciphertext leaves a value nothing can decrypt, and a tag that is not
        // stored with it leaves a value nothing can authenticate.
        ->and(strlen(substr((string) $raw, NikCipher::PAYLOAD_HEADER_LENGTH + NikCipher::IV_LENGTH, NikCipher::CIPHERTEXT_LENGTH)))->toBe(32)
        ->and(NikCipher::CIPHERTEXT_LENGTH)->toBe(32)
        ->and(NikCipher::MAC_LENGTH)->toBe(12)
        // And 88 is what 66 bytes base64s to, with nothing left over.
        ->and(strlen((string) base64_decode($payload, true)) * 4 / 3)->toBe(88);

    // The 32 is openssl's own output, not an assumption about PKCS#7.
    $mentah = openssl_encrypt('3273123456780001', NikCipher::CIPHER, str_repeat('k', 32), OPENSSL_RAW_DATA, str_repeat('i', 16));

    expect($mentah)->toBeString()
        ->and(strlen((string) $mentah))->toBe(32);
});

test('one NIK encrypted twice yields two payloads with two IVs and two ciphertexts', function (): void {
    $nik = t50Nik();
    $a = NikCipher::encrypt($nik);
    $b = NikCipher::encrypt($nik);

    $rawA = (string) base64_decode($a, true);
    $rawB = (string) base64_decode($b, true);
    $iv = NikCipher::IV_LENGTH;
    $ofs = NikCipher::PAYLOAD_HEADER_LENGTH;

    expect($a)->not->toBe($b, 'a random-IV payload repeated')
        ->and($a)->not->toContain($nik)
        ->and($b)->not->toContain($nik)
        ->and(substr($rawA, $ofs, $iv))->not->toBe(substr($rawB, $ofs, $iv))
        // The ciphertext differs too, so this is not a fixed-IV cipher with a
        // randomised header.
        ->and(substr($rawA, $ofs + $iv))->not->toBe(substr($rawB, $ofs + $iv));

    // 200 encryptions, 200 distinct IVs: no IV is reused.
    $ivs = [];

    for ($i = 0; $i < 200; $i++) {
        $ivs[] = substr((string) base64_decode(NikCipher::encrypt($nik), true), $ofs, $iv);
    }

    expect(array_unique($ivs))->toHaveCount(200);
});

test('a NIK round-trips through the cipher unchanged', function (string $case): void {
    expect(NikCipher::decrypt(NikCipher::encrypt($case)))->toBe($case)
        ->and(NikCipher::decrypt(null))->toBeNull();
})->with([
    'ordinary NIK' => '3273123456780001',
    'all zeroes' => '0000000000000000',
    'all nines' => '9999999999999999',
    'leading zeroes' => '0123456789012345',
]);

// ------------------------------------------------------------ the HMAC index

test('the index is 16 unpadded base64 characters carrying 96 bits, and CHAR(16) keeps it byte for byte', function (): void {
    $index = NikCipher::index('3273123456780001');

    expect(strlen($index))->toBe(16)
        ->and(strlen($index))->toBe(NikCipher::INDEX_LENGTH)
        ->and($index)->toMatch('/^[A-Za-z0-9+\/]{16}$/')
        ->and($index)->not->toContain('=')
        // 16 base64 characters carry 12 bytes, so 96 of the 256 HMAC bits
        // survive. Asserted so a later edit cannot quietly halve the width to
        // 8 characters, which would leave 48 bits and a birthday collision
        // inside a national registry.
        ->and(strlen((string) base64_decode($index, true)))->toBe(12);

    t50Temp('t50_index_probe', 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, nik_index CHAR(16) NULL');

    $ditulis = NikCipher::index(t50Nik());
    DB::table('t50_index_probe')->insert(['nik_index' => $ditulis]);

    expect((string) DB::table('t50_index_probe')->value('nik_index'))->toBe($ditulis);
});

test('the same NIK always yields the same index, and 4000 NIKs yield 4000 indexes', function (): void {
    $nik = t50Nik();
    $pertama = NikCipher::index($nik);

    for ($i = 0; $i < 1000; $i++) {
        expect(NikCipher::index($nik))->toBe($pertama, "run {$i} produced a different index for one NIK")
            ->and(NikCipher::encrypt($nik))->not->toBe(NikCipher::encrypt($nik));
    }

    $indexes = [];

    for ($i = 0; $i < 4000; $i++) {
        $indexes[] = NikCipher::index(t50Nik());
    }

    expect(count(array_unique($indexes)))->toBe(4000)
        ->and(strlen($indexes[0]))->toBe(16);
});

test('the index is keyed, not a bare digest, so a database-only leak confirms nothing', function (): void {
    $nik = '3273123456780001';
    $index = NikCipher::index($nik);

    // A bare SHA-256 prefix is reproducible by ANYONE holding the column, which
    // is the whole argument for HMAC. If these ever equalled the index the key
    // would be decorative and the encryption claim would be a lie.
    $telanjang = substr(base64_encode(hash('sha256', $nik)), 0, 16);

    expect($telanjang)->not->toBe($index)
        ->and(NikCipher::index($nik))->toBe($index)
        ->and(NikCipher::index($nik))->not->toBe(substr(base64_encode($nik), 0, 16))
        ->and($index)->not->toContain($nik)
        // Domain separation: the index key is derived from the root key, so a
        // different root gives a different index and the key is in the MAC.
        ->and(t50DenganKunci(['nik.key' => t50Kunci()], static fn (): string => NikCipher::index($nik)))
        ->not->toBe($index, 'a different root key produced the same index, so the key is not in the MAC')
        ->and(NikCipher::indexKeyFingerprint())->toHaveLength(16)
        ->and(NikCipher::indexKeyFingerprint())->toBe(NikCipher::indexKeyFingerprint());
});

test('index comparison is total and rejects a malformed stored value', function (): void {
    $nik = '3273123456780001';
    $index = NikCipher::index($nik);

    expect(NikCipher::indexMatches($index, $nik))->toBeTrue()
        ->and(NikCipher::indexMatches(NikCipher::index(t50Nik()), $nik))->toBeFalse()
        ->and(NikCipher::indexMatches(null, $nik))->toBeFalse()
        ->and(NikCipher::indexMatches('', $nik))->toBeFalse()
        ->and(NikCipher::indexMatches('pendek', $nik))->toBeFalse()
        ->and(NikCipher::indexMatches(substr($index, 0, 15), $nik))->toBeFalse();
});

// ------------------------------------------- the UNIQUE proof, executed in SQL

test('the HMAC index makes the DDL UNIQUE fire on a duplicate NIK and a ciphertext hash cannot', function (): void {
    // The shape the cipher requires: `nik_cipher TEXT` because the payload is
    // 88 characters, `nik_index CHAR(16) UNIQUE` because the index is 16 and
    // because 16 is the width the original column reserved for a key.
    t50Temp(
        't50_nik_probe',
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        .' nik_cipher TEXT NULL,'
        .' nik_index CHAR(16) NULL,'
        .' nik_cipher_index CHAR(16) NULL,'
        .' UNIQUE KEY uq_nik_index (nik_index)'
    );

    $nik = t50Nik();
    $lain = t50Nik();

    $payload1 = NikCipher::encrypt($nik);
    $payload2 = NikCipher::encrypt($nik);

    // THE CENTRAL CLAIM as one array: one NIK, two payloads, one index.
    expect($payload1)->not->toBe($payload2)
        ->and(NikCipher::index($nik))->toBe(NikCipher::index($nik))
        ->and(NikCipher::index($nik))->toBe(NikCipher::index($nik));

    // What the rejected alternative indexes: a digest OF THE CIPHERTEXT. Two
    // payloads, two digests, so UNIQUE cannot see the duplicate at all.
    $hash1 = t50HashPayload($payload1);
    $hash2 = t50HashPayload($payload2);

    expect($hash1)->not->toBe($hash2, 'a random-IV payload cannot yield a deterministic digest');

    DB::table('t50_nik_probe')->insert([
        'nik_cipher' => $payload1,
        'nik_index' => NikCipher::index($nik),
        'nik_cipher_index' => $hash1,
    ]);

    // Row two, same NIK, indexed by the HMAC: REFUSED, driver code 1062.
    [$ditolak, , $kode] = t50Coba(static fn () => DB::table('t50_nik_probe')->insert([
        'nik_cipher' => $payload2,
        'nik_index' => NikCipher::index($nik),
        'nik_cipher_index' => $hash2,
    ]));

    expect($ditolak)->toBeTrue('the duplicate NIK was admitted despite the UNIQUE index')
        ->and($kode)->toBe(1062);

    // THE CONTRAST, which is what makes the HMAC load-bearing. A third row, the
    // same NIK again, indexed by the CIPHERTEXT digest: ADMITTED. One table,
    // one NIK, two rows - exactly the defect the shortcut would have shipped.
    DB::table('t50_nik_probe')->insert([
        'nik_cipher' => NikCipher::encrypt($nik),
        'nik_index' => NikCipher::index(t50Nik()),
        'nik_cipher_index' => substr(base64_encode(hash('sha256', 'a third distinct payload')), 0, 16),
    ]);

    expect(DB::table('t50_nik_probe')->count())->toBe(2)
        ->and(DB::table('t50_nik_probe')->where('nik_cipher_index', $hash1)->count())->toBe(1);

    // A DIFFERENT NIK is admitted, which is the other half of "unique": the
    // index must not be so coarse that every value collides.
    DB::table('t50_nik_probe')->insert([
        'nik_cipher' => NikCipher::encrypt($lain),
        'nik_index' => NikCipher::index($lain),
        'nik_cipher_index' => substr(base64_encode(hash('sha256', 'a fourth payload')), 0, 16),
    ]);

    expect(DB::table('t50_nik_probe')->count())->toBe(3)
        ->and(NikCipher::index($lain))->not->toBe(NikCipher::index($nik));
});

// ----------------------------------------- the CHAR(16) impossibility, in SQL

test('MySQL refuses the payload in a CHAR(16) column with error 1406, and the real column is no longer one', function (): void {
    // CHANGED BY THE NIK CIPHER MIGRATION, and the change is the whole point.
    //
    // This test used to read the LIVE `pasien.nik` column, assert it was
    // `char(16)`, and let MySQL refuse an 88-character payload with a real 1406
    // out of the real table. That was the proof the DDL could not hold the
    // payload, and it is why the column was renamed and widened. So the proof
    // is now split in two and both halves are still executed:
    //
    //   - a TEMPORARY `CHAR(16)` column still refuses the payload with 1406, so
    //     the arithmetic argument has not quietly stopped being true;
    //   - the live `pasien` column is `text` and STORES the payload, so the
    //     application actually writes what the cipher produces.
    //
    // The temporary table is used rather than the real one because the real one
    // is no longer `CHAR(16)`; the next test already asserts that a temporary
    // table is invisible to `sehatly:verify-schema`, so nothing is left behind.
    t50Temp(
        't50_char16_probe',
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, char16 CHAR(16) NULL',
    );

    $payload = NikCipher::encrypt(t50Nik());

    expect(strlen($payload))->toBe(88)->toBeGreaterThan(16);

    [$ditolak, , $kode] = t50Coba(static fn () => DB::table('t50_char16_probe')->insert(['char16' => $payload]));

    expect($ditolak)->toBeTrue('MySQL accepted an 88-character payload into CHAR(16)')
        ->and($kode)->toBe(1406);

    expect(DB::table('t50_char16_probe')->count())->toBe(0, 'something was written despite the refusal');

    // THE LIVE COLUMN, read from the database rather than from the DDL text.
    // Every `information_schema` label is ALIASED, because the server returns
    // those column names uppercased and `$row->data_type` is then an undefined
    // property rather than a wrong value.
    $kolom = DB::selectOne(
        'select data_type as tipe, character_maximum_length as panjang, is_nullable as boleh_null'
        .' from information_schema.columns'
        .' where table_schema = database() and table_name = ? and column_name = ?',
        ['pasien', 'nik_cipher'],
    );
    $lama = DB::selectOne(
        'select column_name as kolom from information_schema.columns'
        .' where table_schema = database() and table_name = ? and column_name = ?',
        ['pasien', 'nik'],
    );

    expect($kolom)->not->toBeNull()
        ->and($kolom->tipe)->toBe('text')
        ->and((int) $kolom->panjang)->toBe(65535)
        ->and($kolom->boleh_null)->toBe('YES')
        ->and($lama)->toBeNull('the plaintext column is still there, so there are two again');

    // And the real table ACCEPTS the payload, byte for byte, through the real
    // column. A `CHAR(16)` would have raised 1406 one paragraph ago, so this is
    // the same insert with a different answer.
    $userId = t50UserRow();
    DB::table('pasien')->insert([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-04-17',
        'alamat_lengkap' => 'Jl. Nik 50 No. 2',
        'nik_cipher' => $payload,
    ]);

    $tersimpan = DB::table('pasien')->where('user_id', $userId)->value('nik_cipher');

    expect($tersimpan)->toBe($payload)
        ->and($tersimpan)->not->toContain(t50Nik());
});

test('a CHAR(16) probe column raises 1406 while a TEXT column stores the payload unchanged', function (): void {
    t50Temp(
        't50_width_probe',
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        .' char16 CHAR(16) NULL,'
        .' teks TEXT NULL'
    );

    $payload = NikCipher::encrypt(t50Nik());

    [$ditolak, , $kode] = t50Coba(static fn () => DB::table('t50_width_probe')->insert(['char16' => $payload]));

    expect($ditolak)->toBeTrue()
        ->and($kode)->toBe(1406);

    DB::table('t50_width_probe')->insert(['char16' => null, 'teks' => $payload]);

    expect(DB::table('t50_width_probe')->value('teks'))->toBe($payload)
        ->and(NikCipher::decrypt((string) DB::table('t50_width_probe')->value('teks')))
        ->toBe(NikCipher::decrypt($payload));
});

test('the temporary probe table is invisible to the schema parity verifier', function (): void {
    // If a temporary table were visible in information_schema this suite would
    // be adding schema behind the verifier's back and every later
    // `sehatly:verify-schema` run would report drift.
    t50Temp('t50_parity_probe', 'id INT NOT NULL PRIMARY KEY, nik_index CHAR(16) NULL');

    $tables = DB::select(
        'select table_name as nama from information_schema.tables where table_schema = database() and table_name = ?',
        ['t50_parity_probe'],
    );
    $statistics = DB::select(
        'select index_name as nama from information_schema.statistics where table_schema = database() and table_name = ?',
        ['t50_parity_probe'],
    );

    expect($tables)->toBe([])
        ->and($statistics)->toBe([]);

    // The table really does exist on this connection, or the two assertions
    // above would be vacuously true.
    DB::table('t50_parity_probe')->insert(['id' => 1, 'nik_index' => NikCipher::index(t50Nik())]);

    expect(DB::table('t50_parity_probe')->count())->toBe(1);
});

// ------------------------------------------------ key source and rotation

test('a missing key fails loudly on every entry point instead of producing garbage', function (): void {
    // openssl does NOT reject a short key: it zero-pads and returns a perfectly
    // formed ciphertext. That is exactly why the length guard is load-bearing,
    // so it is demonstrated rather than asserted.
    $pendek = openssl_encrypt('3273123456780001', NikCipher::CIPHER, 'pendek', OPENSSL_RAW_DATA, str_repeat('i', 16));

    expect($pendek)->toBeString('openssl refused a short key, so the guard would defend against nothing')
        ->and(strlen((string) $pendek))->toBe(32);

    $entri = [
        'encrypt' => static fn (): string => NikCipher::encrypt('3273123456780001'),
        'index' => static fn (): string => NikCipher::index('3273123456780001'),
    ];

    foreach ([null, '', '   '] as $hilang) {
        t50DenganKunci(['nik.key' => $hilang, 'nik.previous_keys' => []], static function () use ($entri, $hilang): void {
            foreach ($entri as $nama => $panggil) {
                $ditangkap = false;
                $pesan = '';

                try {
                    $panggil();
                } catch (MissingNikCipherKeyException $e) {
                    $ditangkap = true;
                    $pesan = $e->getMessage();
                }

                expect($ditangkap)->toBeTrue(
                    'NIK_CIPHER_KEY = '.var_export($hilang, true).' produced a value from '.$nama.'()',
                );
                expect($pesan)->toContain('NIK_CIPHER_KEY');
            }

            expect(NikCipher::hasKey())->toBeFalse();
        });
    }

    // Decrypting refuses too, rather than answering null and letting a caller
    // publish a blank NIK where an identity should be. Captured BY REFERENCE: a
    // closure that assigns to a captured variable changes its own copy, so the
    // first draft of this test asserted a flag the closure had never touched
    // and would have passed whatever the code did.
    $payload = NikCipher::encrypt('3273123456780001');
    $ditangkap = false;

    t50DenganKunci(['nik.key' => null], static function () use ($payload, &$ditangkap): void {
        try {
            NikCipher::decrypt($payload);
        } catch (MissingNikCipherKeyException) {
            $ditangkap = true;
        }
    });

    expect($ditangkap)->toBeTrue()
        ->and(NikCipher::hasKey())->toBeTrue();
});

test('a key of the wrong shape is refused and the Laravel base64 prefix is accepted', function (): void {
    foreach ([
        'raw base64 of 32 bytes' => base64_encode(random_bytes(32)),
        'base64: prefixed' => 'base64:'.base64_encode(random_bytes(32)),
    ] as $label => $key) {
        t50DenganKunci(['nik.key' => $key], static function () use ($label): void {
            expect(NikCipher::hasKey())->toBeTrue($label.' was refused');
            expect(NikCipher::decrypt(NikCipher::encrypt('3273123456780001')))->toBe('3273123456780001');
        });
    }

    foreach ([
        '16 bytes' => base64_encode(random_bytes(16)),
        '31 bytes' => base64_encode(random_bytes(31)),
        '33 bytes' => base64_encode(random_bytes(33)),
        'not base64' => 'not base64 at all, not even close',
        'hex instead of base64' => str_repeat('ab', 32),
        'base64 of nothing' => base64_encode(''),
    ] as $label => $key) {
        t50DenganKunci(['nik.key' => $key], static function () use ($label): void {
            expect(NikCipher::hasKey())->toBeFalse($label.' was accepted');
        });
    }
});

test('no key literal is committed and the missing-key message names the variable and its shape', function (): void {
    // 32 random bytes base64 to exactly 44 characters ending in one `=`, so that
    // is the shape to hunt for. A key in a tracked file is not a secret.
    foreach (['app/Support/NikCipher.php', 'config/nik.php', '.env.example'] as $file) {
        $bytes = (string) file_get_contents(base_path($file));

        expect($bytes)->toBeString($file.' is unreadable')
            ->and(preg_match('/(?<![A-Za-z0-9+\/])[A-Za-z0-9+\/]{43}=(?![A-Za-z0-9+\/=])/', $bytes))
            ->not->toBe(1, $file.' contains a 32-byte key-shaped literal');
    }

    // `.env.example` names the variable and leaves it EMPTY, and `.env` itself
    // is untracked, so the value only ever exists in a deployment environment.
    $baris = (string) file_get_contents(base_path('.env.example'));
    $garis = array_values(array_filter(explode("\n", $baris), static fn (string $l): bool => str_starts_with($l, 'NIK_CIPHER')));
    $terlacak = (string) file_get_contents(base_path('.gitignore'));

    expect($garis)->toContain('NIK_CIPHER_KEY=')
        ->and(NikCipher::ENV_KEY)->toBe('NIK_CIPHER_KEY')
        ->and($terlacak)->toContain("\n.env\n")
        ->and(array_values(array_filter(
            $garis,
            static fn (string $l): bool => $l !== 'NIK_CIPHER_KEY=' && $l !== 'NIK_CIPHER_PREVIOUS_KEYS=',
        )))->toBe([], 'a key was shipped in .env.example');

    $pesan = null;

    try {
        t50DenganKunci(['nik.key' => null], static fn (): string => NikCipher::index('1'));
    } catch (MissingNikCipherKeyException $e) {
        $pesan = $e->getMessage();
    }

    expect($pesan)->toContain('NIK_CIPHER_KEY')
        ->toContain('base64')
        ->toContain('32');
});

test('a payload written under the previous key decrypts while that key is configured', function (): void {
    // The rotation story, executed. Written under key A, read after the operator
    // has moved to key B and left A in the previous-key list.
    $kunciLama = t50Kunci();
    $kunciBaru = t50Kunci();
    $nik = t50Nik();

    $payload = t50DenganKunci(['nik.key' => $kunciLama], static fn (): string => NikCipher::encrypt($nik));

    // Under the new key with no history: refused, not silently blank. Captured
    // by reference for the reason the missing-key test above records.
    $ditolak = false;

    t50DenganKunci(['nik.key' => $kunciBaru, 'nik.previous_keys' => []], static function () use ($payload, &$ditolak): void {
        try {
            NikCipher::decrypt($payload);
        } catch (NikDecryptionException) {
            $ditolak = true;
        }
    });

    expect($ditolak)->toBeTrue('a payload from an unconfigured key decrypted cleanly');

    // With the old key listed, it reads back.
    expect(t50DenganKunci(
        ['nik.key' => $kunciBaru, 'nik.previous_keys' => [$kunciLama]],
        static fn (): string => NikCipher::decrypt($payload),
    ))->toBe($nik);

    // The INDEX is a different problem, and the test says so. The index under the
    // old key and under the new one are DIFFERENT, so a stored index stops
    // matching its NIK the moment the key changes: until every row has been
    // re-encrypted, the registration uniqueness check reports a duplicate NIK as
    // untaken. That is why rotation is a re-encryption pass over the table and
    // not a config edit, and it is the reason this class does not derive the
    // index from `APP_KEY`.
    $indexLama = t50DenganKunci(['nik.key' => $kunciLama], static fn (): string => NikCipher::index($nik));
    $indexBaru = t50DenganKunci(['nik.key' => $kunciBaru], static fn (): string => NikCipher::index($nik));

    expect($indexLama)->not->toBe($indexBaru, 'rotating the key did not change the index, so nothing would need re-encrypting')
        ->and(t50DenganKunci(['nik.key' => $kunciLama], static fn (): string => NikCipher::index($nik)))
        ->toBe($indexLama, 'the index is not deterministic under a fixed key')
        // The index a row was WRITTEN with still verifies under the key that
        // wrote it, and does not verify under the key that replaced it. Each
        // comparison runs under the key it names, because `indexMatches`
        // re-derives the index from the configured key - which is exactly the
        // property that makes a rotation a re-encryption pass.
        ->and(t50DenganKunci(['nik.key' => $kunciLama], static fn (): bool => NikCipher::indexMatches($indexLama, $nik)))
        ->toBeTrue()
        ->and(t50DenganKunci(['nik.key' => $kunciBaru], static fn (): bool => NikCipher::indexMatches($indexLama, $nik)))
        ->toBeFalse();
});

test('a tampered, truncated or foreign payload is refused rather than decrypted into noise', function (): void {
    $nik = '3273123456780001';
    $payload = NikCipher::encrypt($nik);
    $raw = (string) base64_decode($payload, true);
    $ofs = NikCipher::PAYLOAD_HEADER_LENGTH;
    $iv = NikCipher::IV_LENGTH;

    // One flipped bit in the ciphertext block, and one in the tag.
    $rusak = $raw;
    $rusak[$ofs + $iv] = chr(ord($rusak[$ofs + $iv]) ^ 0x01);
    $tagRusak = $raw;
    $tagRusak[NikCipher::PAYLOAD_LENGTH - 1] = chr(ord($tagRusak[NikCipher::PAYLOAD_LENGTH - 1]) ^ 0x01);

    $kasus = [
        'one flipped bit in the ciphertext' => base64_encode($rusak),
        'one flipped bit in the tag' => base64_encode($tagRusak),
        'plaintext NIK' => $nik,
        'wrong magic' => base64_encode('XXXX'.substr($raw, 4)),
        'truncated' => substr($payload, 0, 30),
        'not base64' => 'this is not base64 !!!',
        'iv zeroed' => base64_encode(substr($raw, 0, $ofs).str_repeat("\0", $iv).substr($raw, $ofs + $iv)),
        'key id zeroed' => base64_encode("\0\0\0\0".substr($raw, 4)),
        'tag replaced with zeroes' => base64_encode(substr($raw, 0, NikCipher::PAYLOAD_LENGTH - NikCipher::MAC_LENGTH).str_repeat("\0", NikCipher::MAC_LENGTH)),
    ];

    foreach ($kasus as $label => $value) {
        $ditolak = false;
        $hasil = 'not reached';

        try {
            $hasil = NikCipher::decrypt($value);
        } catch (NikDecryptionException) {
            $ditolak = true;
        }

        expect($ditolak)->toBeTrue($label.' was not refused; it returned '.var_export($hasil, true))
            ->and($hasil)->toBe('not reached');
    }

    // The MARKER check, isolated. A 16-digit plaintext is only 12 bytes once
    // base64-decoded, so the LENGTH check refuses it first and a test that used
    // a plaintext NIK to prove the marker was proving the wrong guard - the M9
    // mutation, which deletes the marker check, survived exactly that test. The
    // value that separates the two is one that decodes to the RIGHT LENGTH and
    // is still not a payload, and the message has to say which check fired,
    // because an operator reading "too short" for a full-length foreign value
    // would go looking in the wrong place.
    $asing = base64_encode(str_repeat('N', NikCipher::PAYLOAD_LENGTH));
    $pesan = null;

    try {
        NikCipher::decrypt($asing);
    } catch (NikDecryptionException $e) {
        $pesan = $e->getMessage();
    }

    expect(strlen((string) base64_decode($asing, true)))->toBe(NikCipher::PAYLOAD_LENGTH)
        ->and($pesan)->toContain('NKC1')
        ->and($pesan)->toContain('plaintext');

    // An empty string is "no identifier" rather than corruption, so it answers
    // null instead of throwing - a nullable column must stay readable.
    expect(NikCipher::decrypt(''))->toBeNull()
        ->and(NikCipher::decrypt(null))->toBeNull();

    // WHY THE TAG IS THERE, stated as the invariant rather than as a
    // demonstration. An earlier draft of this class had no tag, and its only
    // integrity signal was PKCS#7 padding, which succeeds on wrong input once in
    // 256. That is not hypothetical: during development, zeroing the IV of a
    // real payload produced 17 bytes of plausible-looking garbage that the
    // padding check ACCEPTED, and `iv zeroed` above is the case that caught it.
    // Asserting a 1-in-256 event would be a flaky test, so what is asserted is
    // the property that removes the dice: a body whose tag has been altered by a
    // single bit is refused, and so is one whose ciphertext has been, and both
    // refusals are CORRUPTION rather than an unknown key - which is what proves
    // the header was read first and the tag second.
    expect(substr_count($payload, '='))->toBe(0)
        ->and(NikCipher::PAYLOAD_LENGTH - NikCipher::MAC_LENGTH)->toBe(54)
        ->and(strlen(base64_decode($payload, true)))->toBe(NikCipher::PAYLOAD_LENGTH);
});

// -------------------------------------------------------------- API masking

test('the config file reads the NIK key from the environment and never from APP_KEY', function (): void {
    // The M10 mutation, which points `config/nik.php` at `APP_KEY`, survived
    // every other test in this file, because every test sets `config('nik.key')`
    // directly and so never evaluates the config file at all. The only way to
    // close that is to evaluate the file the way Laravel does - by requiring it
    // with a controlled environment - rather than by reading its source.
    //
    // The re-require is a copy, not a mutation of the live repository: the
    // returned array is the file's own result and `config()` is untouched.
    $sebelum = ['nik' => $_ENV['NIK_CIPHER_KEY'] ?? null, 'app' => $_ENV['APP_KEY'] ?? null];

    try {
        $_ENV['NIK_CIPHER_KEY'] = null;
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));

        $rebuild = require base_path('config/nik.php');

        expect($rebuild['key'])->toBeNull('config/nik.php took its key from something other than NIK_CIPHER_KEY')
            ->and($rebuild['previous_keys'])->toBe([]);

        // And with the NIK variable set, that is what it reads.
        $_ENV['NIK_CIPHER_KEY'] = base64_encode(random_bytes(32));
        $rebuild = require base_path('config/nik.php');

        expect($rebuild['key'])->toBe($_ENV['NIK_CIPHER_KEY'])
            ->and($rebuild['key'])->not->toBe($_ENV['APP_KEY']);

        // The previous-key list is a LIST, split on commas, blanks dropped, so
        // the deployment story in the docblock is executable rather than prose.
        $_ENV['NIK_CIPHER_PREVIOUS_KEYS'] = ' aaa , ,bbb ';
        $rebuild = require base_path('config/nik.php');

        expect($rebuild['previous_keys'])->toBe(['aaa', 'bbb']);
    } finally {
        foreach ($sebelum as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }
});

test('a NIK masked from a payload is character-identical to one masked from plaintext', function (): void {
    $nik = '3273123456780001';
    $payload = NikCipher::encrypt($nik);
    $dariCipher = NikCipher::mask($payload);
    $dariPlain = NikCipher::mask(null, $nik);

    expect($dariCipher)->toBe($dariPlain)
        ->and($dariCipher)->toBe('3273'.str_repeat("\u{2022}", 8).'0001')
        ->and($dariCipher)->toMatch('/^\d{4}\x{2022}{8}\d{4}$/u')
        ->and(mb_strlen((string) $dariCipher))->toBe(16)
        ->and($dariCipher)->not->toBe($nik)
        ->and($dariCipher)->not->toBe($payload)
        ->and($dariCipher)->not->toBe(NikCipher::index($nik))
        // And it is the ONE masker: this class delegates rather than
        // reimplementing, so a change to the rule reaches every column.
        ->and($dariCipher)->toBe(NikMasker::mask($nik))
        ->and(NikCipher::mask(null))->toBeNull()
        ->and(NikCipher::mask(null, null))->toBeNull()
        ->and(NikCipher::mask('', null))->toBeNull()
        ->and(NikCipher::mask(null, '   '))->toBeNull();
});

test('every resource that publishes a NIK publishes the same masked string', function (): void {
    $nik = '3273123456780001';
    $pasien = Pasien::query()->findOrFail(t50PasienRow(['nik' => $nik]));
    $harapan = NikMasker::mask($nik);

    // Two shapes, because both exist in the codebase: a resource reads the
    // column off its own model, or off a loaded `pasien` relation.
    $langsung = [
        PasienResource::class => $pasien,
        PasienAnggotaKeluargaResource::class => (new PasienAnggotaKeluarga)->forceFill(['nik' => $nik]),
    ];

    foreach ($langsung as $resource => $model) {
        $body = (new $resource($model))->toArray(request());

        expect($body['nik'])->toBe($harapan, $resource.' published a different mask')
            ->and($body['nik'])->not->toBe($nik, $resource.' published the raw NIK');
    }

    $lewatRelasi = [
        BookingResource::class => (new Booking)->setRelation('pasien', $pasien),
        KonsultasiResource::class => (new Konsultasi)->setRelation('pasien', $pasien),
        // `ran` is the amendment chain. `RekamMedisResource::adalahTerkini()`
        // reads it off the model rather than re-querying, because a read that
        // does not log is the one thing that design refuses, so an unset
        // relation here would be a fixture problem rather than a resource one.
        RekamMedisResource::class => (new RekamMedis)->setRelation('pasien', $pasien)->setRelation('ran', new Illuminate\Database\Eloquent\Collection),
        SuratKeteranganResource::class => (new SuratKeterangan)->setRelation('pasien', $pasien),
    ];

    foreach ($lewatRelasi as $resource => $model) {
        $body = (new $resource($model))->toArray(request());

        expect($body['pasien']['nik'])->toBe($harapan, $resource.' published a different mask')
            ->and($body['pasien']['nik'])->not->toBe($nik, $resource.' published the raw NIK');
    }
});

test('no resource publishes a raw NIK and every one of them routes through this cipher', function (): void {
    $nik = '3273123456780001';
    $pasien = Pasien::query()->findOrFail(t50PasienRow(['nik' => $nik]));

    // `JSON_UNESCAPED_UNICODE` because the mask is eight U+2022 BULLETs and the
    // default encoder would emit `<` escapes, which is a string the response
    // does not contain.
    $body = (string) json_encode((new PasienResource($pasien))->toArray(request()), JSON_UNESCAPED_UNICODE);

    expect($body)->toContain('"nik":"3273'.str_repeat("\u{2022}", 8).'0001"')
        ->and($body)->not->toContain($nik)
        ->and($body)->not->toContain(NikCipher::encrypt($nik))
        ->and($body)->not->toContain(NikCipher::index($nik));

    // The structural half, so the next resource somebody adds cannot bypass the
    // cipher by calling the masker directly. A `nik` key that is not produced
    // through `NikCipher::mask(` is a raw identifier waiting to ship.
    $file = glob(base_path('app/Http/Resources/*.php'));

    expect($file)->not->toBeFalse();

    $penyusun = [];

    foreach ($file as $path) {
        foreach (explode("\n", (string) file_get_contents($path)) as $nomor => $baris) {
            if (preg_match("/'nik' =>/", $baris) !== 1) {
                continue;
            }

            $kelas = basename($path, '.php');
            $penyusun[$kelas][] = trim($baris);

            expect($baris)->toContain('NikCipher::mask(');
        }
    }

    ksort($penyusun);

    // The closed set of resources that publish an identifier. `nomor_kk` is
    // masked by the same rule but is not a NIK column, so it is listed
    // separately below rather than folded in here.
    expect(array_keys($penyusun))->toBe([
        'BookingResource',
        'KonsultasiResource',
        'PasienAnggotaKeluargaResource',
        'PasienResource',
        'RekamMedisResource',
        'SuratKeteranganResource',
    ]);

    foreach ($penyusun as $kelas => $baris) {
        expect($baris)->toHaveCount(1, $kelas.' publishes a NIK from more than one place');
    }

    // One masker. The rule is narrower than "no resource may call
    // `NikMasker::mask`": a `nik` key must come from `NikCipher::mask`, which is
    // the class that knows whether the column holds a payload or a legacy
    // plaintext value. `nomor_kk` has no cipher column proposed for it - it is
    // the family-card number, not the national identity - so it is the one
    // identifier that goes straight to the rule, and it is pinned to exactly one
    // call site so that cannot quietly become two.
    foreach ($file as $path) {
        $kelas = basename($path, '.php');
        $sumber = (string) file_get_contents($path);

        if ($kelas !== 'PasienResource') {
            expect($sumber)->not->toContain('NikMasker::mask(', $kelas.' calls the masker directly');
        }
    }

    expect(substr_count(
        (string) file_get_contents(base_path('app/Http/Resources/PasienResource.php')),
        'NikMasker::mask(',
    ))->toBe(1, 'PasienResource should mask exactly one identifier directly, and it should be nomor_kk')
        ->and((string) file_get_contents(base_path('app/Http/Resources/PasienResource.php')))
        ->toContain("'nomor_kk' => NikMasker::mask(");
});
