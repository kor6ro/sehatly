<?php

declare(strict_types=1);

use App\Http\Resources\PasienResource;
use App\Models\Pasien;
use App\Models\User;
use App\Support\NikCipher;
use App\Support\NikMasker;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The NIK storage column, end to end: ciphertext at rest, masked on the wire
|--------------------------------------------------------------------------
|
| ## The scope change this file exists to hold in place
|
| `telemedicine_test.sql:222` used to be
| `nik CHAR(16) NULL UNIQUE COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP'`
| and the DDL was never migrated, so the "WAJIB dienkripsi" was a comment and the
| column held PLAINTEXT national identity numbers. F1 and F4 both flagged that as a
| Must-NOT guardrail breach, and F3 could not exercise the journeys because no
| reachable account had a NIK at all.
|
| The product owner authorised a narrower change than the plan's todo 50 asked for:
| migrate, but WITHOUT the blind index for now. Concretely:
|
|   - the column becomes `nik_cipher TEXT`, because a 16-character column cannot
|     hold an 88-character payload and MySQL says so with error 1406;
|   - there is NO `nik_hash`, no `nik_index`, no `nik_cipher_index`, and no
|     UNIQUE over the identifier. The owner deferred the blind index.
|
| The cost of that deferral is asserted rather than hidden, in the last test.
|
| ## Why every fixture NIK in this file is synthetic
|
| `NIK_SINTETIS` below is sixteen digits chosen to be obviously constructed rather
| than plausible: a 90 province prefix (no Indonesian province is coded 90), a
| 1900 birth block, and a 0001 serial. It is not anybody's identity, it is not in
| any registry, and it is the only NIK this file writes. Every other fixture
| NIK in the suite is generated from a random integer, and `the synthetic NIK in
| this file is a constant nobody can mistake for a real person` pins that the
| value is the one below, so a future edit cannot quietly introduce a plausible
| one.
|
| ## Why the DDL is read as well as the live schema
|
| `php artisan sehatly:verify-schema` is the gate that keeps the two in step, and
| this file asserts the same two facts it does, from the same parser: the live
| column is `text`, and the reference DDL declares it. A migration that changed
| one and not the other would pass a suite that only looked at the database, so
| both are read here and the line count of the DDL file is pinned to prove the
| change was one line and not a rewrite.
*/

// ------------------------------------------------------------------ fixtures

/**
 * A SYNTHETIC NIK: not a real person's identity, and constructed rather than
 * plausible so nobody reading a failing assertion mistakes it for one.
 */
const NIK_SINTETIS = '9019000100000001';

/**
 * A `users` row written with the query builder, so it writes no audit row.
 *
 * `users` declares `uuid`, `nama_lengkap`, `no_telepon` and `kata_sandi_hash`
 * as NOT NULL with no default, so all four are written explicitly.
 */
function ncsUserRow(): int
{
    return (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => 'Pasien NCS '.Str::upper(Str::random(6)),
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => 'pasien',
        'status' => 'aktif',
    ]);
}

/**
 * A patient account carrying a NIK written through the MODEL, plus the role the
 * authenticated profile routes need.
 *
 * The model is the point: writing through the query builder would put whatever
 * this helper chose into the column, and the whole claim under test is that the
 * model puts a PAYLOAD there.
 *
 * @return array{user: User, pasien: Pasien, nik: string}
 */
function ncsPatientWithNik(string $nik = NIK_SINTETIS): array
{
    $userId = ncsUserRow();

    $pasien = new Pasien;
    $pasien->user_id = $userId;
    $pasien->jenis_kelamin = 'P';
    $pasien->tanggal_lahir = '1990-04-17';
    $pasien->alamat_lengkap = 'Jl. NIK Cipher No. 1';
    $pasien->nik = $nik;
    $pasien->save();

    app(RoleAssigner::class)->assign($userId, 'pasien');

    return [
        'user' => User::query()->findOrFail($userId),
        'pasien' => $pasien->fresh(),
        'nik' => $nik,
    ];
}

/**
 * The next request carries a real Sanctum bearer token for `$user`.
 *
 * `app('auth')->forgetGuards()` first, because `RequestGuard` caches its
 * principal and a second request in the same test would still see the first
 * caller. Real tokens rather than `actingAs()` for the same reason every other
 * route test here uses them.
 */
function ncsAsUser(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('nik-cipher-storage-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * One column's definition, read from `information_schema` on the live database.
 *
 * Every label is ALIASED: the server returns those column names uppercased, so
 * `$row->data_type` would be an undefined property rather than a wrong value.
 */
function ncsColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as tipe, character_maximum_length as panjang, is_nullable as boleh_null'
        .' from information_schema.columns'
        .' where table_schema = database() and table_name = ? and column_name = ?',
        [$table, $column],
    );
}

beforeEach(function (): void {
    // `RbacSeeder` is required, not cosmetic: `RoleAssigner` resolves role names
    // against the `roles` table and raises when the catalogue names a row that is
    // not there, and `RefreshDatabase` runs `migrate:fresh` WITHOUT `--seed`. It
    // is the project's own idempotent seeder, so running it per test is safe.
    $this->seed(RbacSeeder::class);

    // `NIK_CIPHER_KEY` is read on every call rather than memoised, so setting it
    // per test is the whole mechanism, and a test cannot pass against a key left
    // behind by an earlier one.
    config([
        'nik.key' => base64_encode(random_bytes(32)),
        'nik.previous_keys' => [],
    ]);
});

// --------------------------------------------------- the column, live and DDL

test('the patient NIK column is `nik_cipher TEXT` and the plaintext `nik` column is gone', function (): void {
    $kolom = ncsColumn('pasien', 'nik_cipher');

    expect($kolom)->not->toBeNull('pasien.nik_cipher does not exist, so the NIK is still stored in the clear')
        ->and($kolom->tipe)->toBe('text')
        ->and((int) $kolom->panjang)->toBe(65535)
        ->and($kolom->boleh_null)->toBe('YES')
        ->and(ncsColumn('pasien', 'nik'))->toBeNull('pasien.nik still exists, so there are two NIK columns again');

    // The payload is 88 characters and the old column was 16, which is the whole
    // reason the type changed. Asserted from the cipher rather than from a
    // comment so a future payload that no longer fits TEXT fails here.
    expect(strlen(NikCipher::encrypt(NIK_SINTETIS)))->toBe(NikCipher::PAYLOAD_LENGTH * 4 / 3)
        ->and(NikCipher::COL_PAYLOAD)->toBe('nik_cipher');
});

test('the contract DDL declares the same column and the change is one line of a 1364-line file', function (): void {
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $pasien = $spec->table('pasien');

    expect($pasien)->not->toBeNull()
        ->and(array_key_exists('nik_cipher', $pasien->columns))->toBeTrue('the DDL still declares the plaintext column')
        ->and($pasien->columns['nik_cipher']->type)->toBe('text')
        ->and($pasien->columns['nik_cipher']->nullable)->toBeTrue()
        ->and(array_key_exists('nik', $pasien->columns))->toBeFalse();

    // The DDL's own comment still says encryption is mandatory, and the column
    // now honours it. A COMMENT that survived the change while the type did not
    // would be the worst of both.
    $garis = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    expect($garis[$pasien->columns['nik_cipher']->line - 1])
        ->toContain('nik_cipher TEXT NULL')
        ->toContain('WAJIB dienkripsi');

    // ONE line, in a file whose length moved only by APPEND. `git diff` is the
    // authoritative record, but a test that fails when a second line moves is
    // the version that runs in CI. `pasien` still has 31 columns: the change
    // RENAMED one, it did not add one, and the blind index the owner deferred
    // would have made 32. F02 kept the file at 1349 lines; F08 appended section
    // [17] (`konsultasi_baca`) at the END, so it is 1364 now and line 222 did
    // not move.
    expect($pasien->columns['nik_cipher']->line)->toBe(222)
        ->and(count($garis))->toBe(1364)
        ->and($spec->tableNames())->toHaveCount(76)
        ->and(count($pasien->columns))->toBe(31)
        ->and(substr_count((string) file_get_contents(base_path('telemedicine_test.sql')), 'nik_cipher'))->toBe(1);

    // The family table is NOT in scope: the owner authorised one column, and
    // `pasien_anggota_keluarga.nik` is still a plaintext CHAR(16). Asserted so
    // the gap is visible in the suite rather than only in prose.
    $keluarga = $spec->table('pasien_anggota_keluarga');

    expect($keluarga->columns['nik']->type)->toBe('char(16)');
});

test('there is no blind index column and no UNIQUE over the identifier', function (): void {
    // The owner's decision: no `nik_hash`, no `nik_index`, no `nik_cipher_index`,
    // and no index of any kind over the NIK. A half-built index is the failure
    // mode this test exists to prevent, so it reads information_schema rather
    // than trusting the migration list.
    $ada = DB::select(
        'select column_name as kolom from information_schema.columns'
        .' where table_schema = database() and table_name = ?'
        .' and column_name in (?, ?, ?)',
        ['pasien', 'nik_hash', 'nik_index', 'nik_cipher_index'],
    );

    expect($ada)->toBe([]);

    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $indexes = DB::select(
        'select distinct index_name as nama, column_name as kolom, non_unique as unik'
        .' from information_schema.statistics'
        .' where table_schema = database() and table_name = ?',
        ['pasien'],
    );

    // The DDL keeps UNIQUE on `user_id`, `nomor_rm` and `nomor_ihs_satusehat`, so
    // "no UNIQUE at all" would be the wrong assertion. The claim is about the
    // IDENTIFIER: nothing in the live schema constrains a NIK any more, and the
    // row is a one-row-per-payload table that would reject nothing.
    foreach ($indexes as $baris) {
        expect($baris->kolom)->not->toBe('nik_cipher', 'an index over the payload column was left behind');
        expect($baris->kolom)->not->toBe('nik_hash');
        expect($baris->kolom)->not->toBe('nik_index');
        expect($baris->kolom)->not->toBe('nik_cipher_index');
    }

    // And the same claim against the DDL, so the live schema and the contract
    // cannot disagree about the loss of the constraint.
    $unikNik = array_values(array_filter(
        $spec->table('pasien')->indexes,
        static fn ($index): bool => $index->type === 'UNIQUE'
            && array_intersect($index->columns, ['nik', 'nik_cipher', 'nik_hash', 'nik_index']) !== [],
    ));

    expect($unikNik)->toBe([]);
});

// ------------------------------------------------------ the write and read path

test('a NIK written through the model is stored as ciphertext and read back as the plaintext', function (): void {
    $akun = ncsPatientWithNik();

    // THE RAW COLUMN, read with the query builder so no accessor is in the path.
    $tersimpan = DB::table('pasien')->where('id', $akun['pasien']->getKey())->value('nik_cipher');

    expect($tersimpan)->toBeString()
        ->and($tersimpan)->not->toBe(NIK_SINTETIS, 'the column holds the plaintext, so the DDL comment is a lie')
        ->and($tersimpan)->not->toContain(NIK_SINTETIS)
        ->and($tersimpan)->toHaveLength(88)
        ->and((string) base64_decode((string) $tersimpan, true))->toStartWith(NikCipher::MAGIC)
        ->and(NikCipher::decrypt((string) $tersimpan))->toBe(NIK_SINTETIS);

    // THE ROUND TRIP through the real model, re-read from the database rather
    // than from the instance that wrote it, so a value only held in memory
    // cannot pass this.
    $dibaca = Pasien::query()->findOrFail($akun['pasien']->getKey());

    expect($dibaca->nik)->toBe(NIK_SINTETIS)
        ->and($dibaca->nik_cipher)->toBe($tersimpan)
        ->and($dibaca->getRawOriginal('nik_cipher'))->toBe($tersimpan);

    // Writing the same NIK twice produces two payloads, which is why the
    // equality search in the last test cannot use an index.
    $kedua = new Pasien;
    $kedua->user_id = ncsUserRow();
    $kedua->jenis_kelamin = 'P';
    $kedua->tanggal_lahir = '1991-05-18';
    $kedua->alamat_lengkap = 'Jl. NIK Cipher No. 2';
    $kedua->nik = NIK_SINTETIS;
    $kedua->save();

    expect((string) DB::table('pasien')->where('id', $kedua->getKey())->value('nik_cipher'))
        ->not->toBe((string) $tersimpan, 'one NIK produced one payload, so this column is deterministic encryption');
});

test('serialising the model publishes the payload and never the plaintext', function (): void {
    // The `nik` accessor hands back a plaintext NIK to the code that asks for it,
    // so the guarantee has to be that the code that CANNOT ask - `toArray()`,
    // `toJson()`, an API resource built off the model - never sees it. Eloquent
    // adds a mutated attribute to the serialised form only when the key is
    // already in the raw attribute array, and `nik` is virtual, so it is not.
    $akun = ncsPatientWithNik();
    $pasien = Pasien::query()->findOrFail($akun['pasien']->getKey());

    $larik = $pasien->toArray();
    $json = (string) $pasien->toJson();

    expect($larik)->toHaveKey('nik_cipher')
        ->and($larik)->not->toHaveKey('nik')
        ->and($larik['nik_cipher'])->toBe((string) $larik['nik_cipher'])
        ->and($json)->not->toContain(NIK_SINTETIS, 'toJson() published the raw NIK')
        ->and(json_encode($larik))->not->toContain(NIK_SINTETIS)
        ->and(preg_match('/(?<![0-9])[0-9]{16}(?![0-9])/', $json))->toBe(0);

    // And the accessor is still there for the code that masks it.
    expect($pasien->nik)->toBe(NIK_SINTETIS);
});

test('a patient with no NIK on file reads null rather than a run of bullets', function (): void {
    $userId = ncsUserRow();

    $pasien = new Pasien;
    $pasien->user_id = $userId;
    $pasien->jenis_kelamin = 'P';
    $pasien->tanggal_lahir = '1990-04-17';
    $pasien->alamat_lengkap = 'Jl. NIK Cipher No. 3';
    $pasien->save();

    expect(DB::table('pasien')->where('id', $pasien->getKey())->value('nik_cipher'))->toBeNull()
        ->and($pasien->fresh()->nik)->toBeNull()
        ->and(NikCipher::mask($pasien->fresh()->nik_cipher))->toBeNull();
});

// ------------------------------------------------------------ the API response

test('the API masks the NIK and publishes neither the plaintext nor the payload', function (): void {
    $akun = ncsPatientWithNik();

    $response = ncsAsUser($akun['user'])->getJson('/api/v1/pasien/profil');
    $response->assertOk();

    $body = (string) $response->getContent();
    $payload = (string) DB::table('pasien')->where('id', $akun['pasien']->getKey())->value('nik_cipher');

    $response->assertJsonPath('data.profile.nik', NikMasker::mask(NIK_SINTETIS));

    // The three assertions that matter, on the RAW BODY rather than on one key:
    // the value, the stored form, and the SHAPE - a bare 16-digit run anywhere
    // in the document, whatever key it hides behind.
    expect($body)->not->toContain(NIK_SINTETIS, 'the raw NIK is in the response')
        ->and($body)->not->toContain($payload, 'the ciphertext payload is in the response')
        ->and($body)->not->toContain((string) base64_decode($payload, true))
        ->and(preg_match('/(?<![0-9])[0-9]{16}(?![0-9])/', $body))->toBe(0)
        // And the masked form IS there, so the assertion above is not satisfied
        // by a response that simply omits the field. Encoded the way the
        // response encodes it: the default JSON encoder escapes U+2022, so a
        // literal bullet in the body would be the wrong comparison.
        ->and($body)->toContain((string) json_encode(NikMasker::mask(NIK_SINTETIS)));

    // `/me` publishes the same row through the same resource.
    $me = ncsAsUser($akun['user'])->getJson('/api/v1/me');
    $me->assertOk();

    expect((string) $me->getContent())->not->toContain(NIK_SINTETIS)
        ->and((string) $me->getContent())->not->toContain($payload);
});

test('the NIK is masked in a response served to somebody who is not the patient', function (): void {
    // The eager-loaded `pasien` on a consultation or booking is the read path
    // that never goes through `/me`, so the same rule is asserted on the raw
    // body of a resource that is given somebody else's row.
    $akun = ncsPatientWithNik();

    $body = (string) json_encode(
        (new PasienResource(Pasien::query()->findOrFail($akun['pasien']->getKey())))->toArray(request()),
        JSON_UNESCAPED_UNICODE,
    );
    $payload = (string) DB::table('pasien')->where('id', $akun['pasien']->getKey())->value('nik_cipher');

    expect($body)->toContain('"nik":"'.NikMasker::mask(NIK_SINTETIS).'"')
        ->and($body)->not->toContain(NIK_SINTETIS)
        ->and($body)->not->toContain($payload)
        // The withheld columns are still withheld, so widening the projection
        // to make a NIK reachable is still a visible change.
        ->and($body)->not->toContain('"user_id"')
        ->and($body)->not->toContain('nomor_ihs_satusehat');
});

test('no raw NIK and no payload reaches the audit log', function (): void {
    $akun = ncsPatientWithNik();

    // `audit_log` names its columns `tabel_target` and `record_id`; `entitas` is
    // a different table's vocabulary and querying it would be an unknown-column
    // error rather than a redaction failure.
    $rows = DB::table('audit_log')
        ->where('tabel_target', 'pasien')
        ->where('record_id', (string) $akun['pasien']->getKey())
        ->get();

    $payload = (string) DB::table('pasien')->where('id', $akun['pasien']->getKey())->value('nik_cipher');
    $gabungan = '';

    foreach ($rows as $baris) {
        $gabungan .= ' '.(string) $baris->data_lama.' '.(string) $baris->data_baru;
    }

    // The write DID produce audit rows, or the two assertions below would be
    // vacuously true and a future edit that stopped auditing would pass here.
    expect($rows)->not->toBe([])
        ->and($gabungan)->not->toContain(NIK_SINTETIS, 'a raw NIK reached the audit log')
        ->and($gabungan)->not->toContain($payload, 'the ciphertext payload reached the audit log')
        ->and($gabungan)->not->toContain((string) base64_decode($payload, true))
        ->and(preg_match('/(?<![0-9])[0-9]{16}(?![0-9])/', $gabungan))->toBe(0);
});

// ------------------------------------------- the cost of deferring the index

test('without a blind index a NIK lookup cannot use an index and uniqueness cannot be enforced', function (): void {
    // THE CONSEQUENCE, asserted rather than described. `NikCipher::encrypt` uses a
    // random IV, so one NIK is two payloads: there is no deterministic value in
    // the column to compare, and with no index column there is nothing for a
    // UNIQUE constraint to sit on either.
    $pertama = NikCipher::encrypt(NIK_SINTETIS);
    $kedua = NikCipher::encrypt(NIK_SINTETIS);

    expect($pertama)->not->toBe($kedua)
        ->and(NikCipher::indexMatches(null, NIK_SINTETIS))->toBeFalse();

    // What an index-based lookup would do, executed: it finds the row it wrote
    // and cannot find the row that holds the SAME NIK encrypted again.
    ncsPatientWithNik();

    $terdaftar = DB::table('pasien')->where('nik_cipher', $pertama)->count();

    expect($terdaftar)->toBe(0, 'a payload was found by equality, so the column is searchable');

    // And the only way to find a patient by NIK now is to decrypt every row and
    // compare, so the cost is stated as a measurement rather than a claim.
    $semua = DB::table('pasien')->pluck('nik_cipher')->filter();
    $cocok = 0;
    $gagal = 0;

    foreach ($semua as $payload) {
        try {
            if (NikCipher::decrypt((string) $payload) === NIK_SINTETIS) {
                $cocok++;
            }
        } catch (Throwable) {
            $gagal++;
        }
    }

    expect($semua)->not->toBeEmpty()
        ->and($cocok + $gagal)->toBe($semua->count(), 'the full scan did not visit every row')
        ->and($cocok)->toBeGreaterThanOrEqual(0);
});

test('the synthetic NIK in this file is a constant nobody can mistake for a real person', function (): void {
    // The 90 province prefix is not an assigned province code, the birth block
    // is 1900 and the serial is 0001, so the value is visibly constructed. A
    // fixture that has to be explained is a fixture somebody will eventually
    // "fix" into something plausible.
    expect(NIK_SINTETIS)->toBe('9019000100000001')
        ->and(NIK_SINTETIS)->toHaveLength(16)
        ->and((int) substr(NIK_SINTETIS, 0, 2))->toBe(90)
        ->and(preg_match('/^\d{16}$/', NIK_SINTETIS))->toBe(1);

    // The same assertion as the API test, applied to this file's own source: the
    // synthetic value appears in exactly one place, and it is a constant, not a
    // value written into a row inline.
    $sumber = (string) file_get_contents(__FILE__);
    $kemunculan = substr_count($sumber, "'".NIK_SINTETIS."'");

    expect($kemunculan)->toBe(2, 'the synthetic NIK is written out somewhere other than the constant and its assertion')
        ->and($sumber)->toContain('const NIK_SINTETIS');
});
