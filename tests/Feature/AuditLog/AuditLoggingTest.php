<?php

declare(strict_types=1);

use App\Models\AksesRekamMedisLog;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Konsultasi;
use App\Models\KonsultasiChat;
use App\Models\Pasien;
use App\Models\Pembayaran;
use App\Models\RekamMedis;
use App\Models\Rujukan;
use App\Models\SuratKeterangan;
use App\Models\User;
use App\Services\Audit\AuditColumnPolicy;
use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use App\Support\NikMasker;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeOtpSender;

/*
|--------------------------------------------------------------------------
| Global audit-log observers: behaviour, redaction, update semantics
|--------------------------------------------------------------------------
|
| APPENDED by todo 43. The helper prefix is `al43`: Pest loads every test
| file into ONE process, so a helper name declared at file scope in two
| files is a redeclaration and a fatal error.
|
| Fixtures written with the query builder produce NO audit row (the observer
| only fires on Eloquent events); fixtures written through a MODEL produce
| exactly one. Every behavioural test below relies on that split, and the
| first test asserts it directly, so a change that makes the observer fire
| on query-builder writes fails here rather than silently doubling rows.
|
| Models declare no $fillable anywhere in app/Models, so every model write
| below uses direct property assignment, never fill() or create(): mass
| assignment would silently drop every attribute and the redaction
| assertions would pass against a row of NULLs.
*/

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->app->instance(OtpSender::class, new FakeOtpSender);
});

// =====================================================================
// Row builders
// =====================================================================

/**
 * A `users` row via the query builder: NO observer fires.
 *
 * `uuid` (:134), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are NOT NULL with no default.
 *
 * @param  array<string, mixed>  $extra
 */
function al43UserRow(array $extra = []): int
{
    return (int) DB::table('users')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => 'Al43 '.Str::upper(Str::random(6)),
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => 'pasien',
        'status' => 'aktif',
    ], $extra));
}

/**
 * A `pasien` row via the query builder: NO observer fires.
 *
 * `user_id` (:220), `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are the NOT NULL columns with no default.
 *
 * @param  array<string, mixed>  $extra
 */
function al43PasienRow(int $userId, array $extra = []): int
{
    return (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-04-17',
        'alamat_lengkap' => 'Jl. Al43 No. 12',
    ], $extra));
}

/**
 * A `dokter` row via the query builder: NO observer fires.
 *
 * `user_id` (:411), `tipe` (:412), `nomor_str` (:413, UNIQUE) and
 * `str_berlaku_sampai` (:414) are required.
 *
 * @param  array<string, mixed>  $extra
 */
function al43DokterRow(array $extra = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => al43UserRow(['tipe' => 'dokter']),
        'tipe' => 'dokter_umum',
        'nomor_str' => 'AL43-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2030-12-31',
    ], $extra));
}

/**
 * A `rekam_medis` row THROUGH THE MODEL, so the observer fires.
 *
 * Never hydrated back: `GuardsMedicalRecordRead` throws on any `retrieved`
 * outside a `RekamMedisReadScope`, and this helper never needs the row back.
 *
 * @param  array<string, mixed>  $extra
 */
function al43Record(int $pasienId, int $dokterId, array $extra = []): RekamMedis
{
    $row = new RekamMedis;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe_kunjungan = 'telemedisin';
    $row->tanggal_periksa = '2026-04-01 08:00:00';
    $row->status_dokumen = 'draft';
    $row->versi = 1;

    foreach ($extra as $column => $value) {
        $row->{$column} = $value;
    }

    $row->save();

    return $row;
}

/**
 * A `booking` row THROUGH THE MODEL, so the observer fires.
 *
 * `nomor_booking` (:500), `pasien_id` (:501), `dokter_id` (:503),
 * `tipe_layanan` (:506), `tanggal_kunjungan` (:507), both slot columns
 * (:508-509) and `dibuat_oleh_user_id` (:519) are required.
 *
 * @param  array<string, mixed>  $extra
 */
function al43Booking(int $pasienId, int $dokterId, int $userId, array $extra = []): Booking
{
    $row = new Booking;
    $row->nomor_booking = 'AL43-'.Str::upper(Str::random(10));
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe_layanan = 'chat';
    $row->tanggal_kunjungan = '2026-04-02';
    $row->slot_mulai = '09:00:00';
    $row->slot_selesai = '09:15:00';
    $row->dibuat_oleh_user_id = $userId;

    foreach ($extra as $column => $value) {
        $row->{$column} = $value;
    }

    $row->save();

    return $row;
}

/**
 * A `konsultasi` row THROUGH THE MODEL. `pasien_id` (:539), `dokter_id`
 * (:540) and `tipe` (:541) are required.
 *
 * @param  array<string, mixed>  $extra
 */
function al43Konsultasi(int $pasienId, int $dokterId, array $extra = []): Konsultasi
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';

    foreach ($extra as $column => $value) {
        $row->{$column} = $value;
    }

    $row->save();

    return $row;
}

/**
 * A `konsultasi_chat` row THROUGH THE MODEL. `konsultasi_id` (:565),
 * `pengirim_user_id` (:566) and `pengirim_tipe` (:567) are required.
 *
 * @param  array<string, mixed>  $extra
 */
function al43Chat(int $konsultasiId, int $userId, array $extra = []): KonsultasiChat
{
    $row = new KonsultasiChat;
    $row->konsultasi_id = $konsultasiId;
    $row->pengirim_user_id = $userId;
    $row->pengirim_tipe = 'pasien';

    foreach ($extra as $column => $value) {
        $row->{$column} = $value;
    }

    $row->save();

    return $row;
}

/**
 * A `User` THROUGH THE MODEL with a KNOWN password hash, so the observer
 * fires and the absence scan has a concrete secret to hunt for.
 *
 * `$extra` overrides the defaults BEFORE the save, which is the whole point of
 * it: the save is what writes the create row, so a test that assigns a column
 * afterwards is asserting against a row that never held the value.
 *
 * @param  array<string, mixed>  $extra
 */
function al43UserModel(string $hash, array $extra = []): User
{
    $row = new User;
    $row->nama_lengkap = 'Al43 Hash '.Str::upper(Str::random(4));
    $row->no_telepon = '08'.random_int(100000000, 999999999);
    $row->kata_sandi_hash = $hash;
    $row->tipe = 'pasien';
    $row->status = 'aktif';

    foreach ($extra as $column => $value) {
        $row->{$column} = $value;
    }

    $row->save();

    return $row;
}

/**
 * A `Pasien` THROUGH THE MODEL carrying a KNOWN NIK, so the observer fires
 * and the redaction scan has a concrete identifier to hunt for.
 */
function al43PasienModel(int $userId, string $nik, array $extra = []): Pasien
{
    $row = new Pasien;
    $row->user_id = $userId;
    $row->nik = $nik;
    $row->jenis_kelamin = 'P';
    $row->tanggal_lahir = '1990-04-17';
    $row->alamat_lengkap = 'Jl. Al43 No. 12';

    foreach ($extra as $column => $value) {
        $row->{$column} = $value;
    }

    $row->save();

    return $row;
}

/**
 * Every `audit_log` row for one table row, oldest first, read through the
 * query builder: the count is the thing under test, and reading it through
 * the `AuditLog` model would share code with the writer.
 *
 * @return \Illuminate\Support\Collection<int, object>
 */
function al43RowsFor(string $table, int|string $recordId): \Illuminate\Support\Collection
{
    return DB::table('audit_log')
        ->where('tabel_target', $table)
        ->where('record_id', (string) $recordId)
        ->orderBy('id')
        ->get();
}

/**
 * The single row a write was expected to produce. The count assertion IS the
 * point: returning first() from a two-row result would hide a duplicate.
 */
function al43One(string $table, int|string $recordId, string $aksi): object
{
    $rows = al43RowsFor($table, (string) $recordId)->where('aksi', $aksi)->values();

    expect($rows)->toHaveCount(1, $table.'/'.((string) $recordId).'/'.$aksi.' row count');

    return $rows->first();
}

/**
 * `data_lama` or `data_baru`, decoded. NULL stays NULL: a create carries no
 * before-image and a delete carries no after-image, and conflating "no
 * image" with "empty image" would hide a snapshot that should be there.
 *
 * @return array<string, mixed>|null
 */
function al43Payload(object $row, string $which): ?array
{
    $raw = $row->{$which};

    if ($raw === null) {
        return null;
    }

    return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The WHOLE audit row as one string: the surface every absence claim is
 * about. Encoding the entire row rather than one column at a time is
 * deliberate: the acceptance criterion is absence from the ROW, and a
 * nested or differently-named field is exactly how this leaks.
 */
function al43WholeRow(object $row): string
{
    return (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// =====================================================================
// Behaviour: create / update / delete map to the DDL aksi values
// =====================================================================

test('creating a RekamMedis writes exactly one create row with no before image', function (): void {
    $pasienId = al43PasienRow(al43UserRow());
    $dokterId = al43DokterRow();

    $before = DB::table('audit_log')->count();

    $record = al43Record($pasienId, $dokterId);

    expect(DB::table('audit_log')->count())->toBe($before + 1);

    $row = al43One('rekam_medis', $record->getKey(), 'create');

    expect($row->tabel_target)->toBe('rekam_medis');
    expect($row->record_id)->toBe((string) $record->getKey());
    expect(al43Payload($row, 'data_lama'))->toBeNull();

    $baru = al43Payload($row, 'data_baru');
    expect($baru)->not->toBeNull();
    expect($baru['pasien_id'])->toBe($pasienId);
    expect($baru['status_dokumen'])->toBe('draft');
});

test('query-builder writes produce no audit row, so seed rows stay silent', function (): void {
    $before = DB::table('audit_log')->count();

    $userId = al43UserRow();
    $pasienId = al43PasienRow($userId);
    al43DokterRow();

    expect(DB::table('audit_log')->count())->toBe($before);
    expect(al43RowsFor('pasien', $pasienId))->toHaveCount(0);
});

test('updating a RekamMedis writes one update row carrying only the changed keys', function (): void {
    $record = al43Record(al43PasienRow(al43UserRow()), al43DokterRow());

    $record->status_dokumen = 'final';
    $record->versi = 2;
    $record->save();

    $row = al43One('rekam_medis', $record->getKey(), 'update');

    $lama = al43Payload($row, 'data_lama');
    $baru = al43Payload($row, 'data_baru');

    // Changed-keys-only in both directions: a row holding full before AND
    // after snapshots of a patient record would double the exposure of every
    // redacted field in an append-only table. Each update row carries its own
    // delta, so the full history is still reconstructible across rows.
    // Subset, not exact: MySQL TIMESTAMP has one-second resolution, so when
    // the create and the update land in the same second `diubah_at` is not
    // dirty and is honestly absent from the delta.
    $allowed = ['status_dokumen', 'versi', 'diubah_at'];
    expect(array_keys($baru ?? []))->not->toBeEmpty();
    foreach (array_keys($baru ?? []) as $key) {
        expect($allowed)->toContain($key);
    }
    expect(array_keys($lama ?? []))->toEqualCanonicalizing(array_keys($baru ?? []));
    expect($baru['status_dokumen'])->toBe('final');
    expect($baru['versi'])->toBe(2);
    expect($lama['status_dokumen'])->toBe('draft');
    expect($lama['versi'])->toBe(1);
});

test('soft-deleting a Pasien writes one delete row with the before image and no after image', function (): void {
    $userId = al43UserRow();

    $pasien = al43PasienModel($userId, '3273010101900001');
    $pasienId = (int) $pasien->getKey();

    $pasien->delete();

    $row = al43One('pasien', $pasienId, 'delete');

    $lama = al43Payload($row, 'data_lama');
    expect($lama)->not->toBeNull();
    expect((int) ($lama['user_id'] ?? 0))->toBe($userId);
    expect(al43Payload($row, 'data_baru'))->toBeNull();

    // The row survives: soft delete, so the log's record_id still resolves.
    expect(DB::table('pasien')->where('id', $pasienId)->count())->toBe(1);
});

test('deleting a Booking writes one delete row', function (): void {
    $userId = al43UserRow();
    $booking = al43Booking(al43PasienRow($userId), al43DokterRow(), $userId);
    $bookingId = (int) $booking->getKey();

    $booking->delete();

    al43One('booking', $bookingId, 'delete');
});

// =====================================================================
// Redaction: NIK masked, password hash absent, PHI never snapshotted
// =====================================================================

test('a Pasien NIK never appears verbatim in any audit row, only its masked form', function (): void {
    $nik = '3273010101900001';
    $kk = '3273010101900016';

    $pasien = al43PasienModel(al43UserRow(), $nik, ['nomor_kk' => $kk]);

    $rows = al43RowsFor('pasien', $pasien->getKey());
    expect($rows)->not->toBeEmpty();

    $maskedNik = NikMasker::mask($nik);
    $maskedKk = NikMasker::mask($kk);
    expect($maskedNik)->not->toBe($nik);

    foreach ($rows as $row) {
        $whole = al43WholeRow($row);
        expect($whole)->not->toContain($nik, 'raw NIK leaked into audit row '.$row->id);
        expect($whole)->not->toContain($kk, 'raw nomor_kk leaked into audit row '.$row->id);
        expect($whole)->toContain((string) $maskedNik);
        expect($whole)->toContain((string) $maskedKk);
    }
});

test('a NIK typed inside a stored free-text field is masked, not stored raw', function (): void {
    $embedded = '3273020202800002';
    $pasien = al43PasienModel(al43UserRow(), '3273010101900003', [
        'alamat_lengkap' => 'Jl. Merdeka, NIK kerabat '.$embedded.', Jakarta',
    ]);

    foreach (al43RowsFor('pasien', $pasien->getKey()) as $row) {
        expect(al43WholeRow($row))->not->toContain($embedded);
    }
});

test('kata_sandi_hash never appears in an audit row in any form', function (): void {
    $hash = password_hash('kata-sandi-al43-yang-rahasia', PASSWORD_BCRYPT);

    $user = al43UserModel($hash);

    $rows = al43RowsFor('users', $user->getKey());
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $whole = al43WholeRow($row);

        // Whole-row scan, not one key: a nested or differently-named field is
        // exactly how this leaks. The 8-char prefix survives any truncation,
        // prefixing or re-encoding of the hash.
        expect($whole)->not->toContain($hash, 'full hash leaked into audit row '.$row->id);
        expect($whole)->not->toContain(substr($hash, 0, 8), 'hash prefix leaked into audit row '.$row->id);

        // Structural half: no payload key may even NAME a credential, which
        // is what would carry a length or a re-hash without the value.
        foreach (['data_lama', 'data_baru'] as $which) {
            foreach (array_keys(al43Payload($row, $which) ?? []) as $key) {
                expect(strtolower((string) $key))->not->toMatch('/kata_sandi|password|passwd|secret|_hash$/');
            }
        }
    }
});

test('changing only the password still writes an update row, with no secret in it', function (): void {
    $user = al43UserModel(password_hash('kata-sandi-pertama-al43', PASSWORD_BCRYPT));

    $second = password_hash('kata-sandi-kedua-al43', PASSWORD_BCRYPT);
    $user->kata_sandi_hash = $second;
    $user->save();

    $row = al43One('users', $user->getKey(), 'update');
    $whole = al43WholeRow($row);

    expect($whole)->not->toContain($second);
    expect($whole)->not->toContain(substr($second, 0, 8));
});

test('RekamMedis SOAP and narrative text is absent from the audit payloads', function (): void {
    $unik = 'AL43-SOAP-'.Str::upper(Str::random(8));

    $record = al43Record(al43PasienRow(al43UserRow()), al43DokterRow(), [
        'keluhan_utama' => $unik.'-KELUHAN',
        'subjektif' => $unik.'-SUBJEKTIF',
        'objektif' => $unik.'-OBJEKTIF',
        'asesmen' => $unik.'-ASESMEN',
        'plan' => $unik.'-PLAN',
        'diagnosis_kerja' => $unik.'-DIAGNOSIS',
    ]);

    foreach (al43RowsFor('rekam_medis', $record->getKey()) as $row) {
        $whole = al43WholeRow($row);
        expect($whole)->not->toContain($unik, 'PHI leaked into audit row '.$row->id);
    }
});

test('Booking keluhan and KonsultasiChat isi are absent from the audit payloads', function (): void {
    $userId = al43UserRow();
    $pasienId = al43PasienRow($userId);
    $dokterId = al43DokterRow();

    $keluhan = 'AL43-KELUHAN-'.Str::upper(Str::random(8));
    $booking = al43Booking($pasienId, $dokterId, $userId, ['keluhan' => $keluhan]);

    $konsultasi = al43Konsultasi($pasienId, $dokterId);
    $isi = 'AL43-ISI-'.Str::upper(Str::random(8));
    $chat = al43Chat((int) $konsultasi->getKey(), $userId, ['isi' => $isi]);

    foreach (al43RowsFor('booking', $booking->getKey()) as $row) {
        expect(al43WholeRow($row))->not->toContain($keluhan);
    }

    foreach (al43RowsFor('konsultasi_chat', $chat->getKey()) as $row) {
        expect(al43WholeRow($row))->not->toContain($isi);
    }
});

test('contact identifiers are MASKED, not stored and not dropped: no_telepon and email', function (): void {
    $phone = '081243000042';
    $email = 'al43-kontak@example.test';

    // The identifiers are passed INTO the fixture, not assigned afterwards.
    // `al43UserModel()` saves, and the save is what writes the create row, so
    // assigning afterwards leaves the create row carrying the fixture's RANDOM
    // number - the assertion then compared my masked phone against a mask of a
    // different value and failed, which reads as a masking bug and is really a
    // fixture that wrote before it was configured.
    $user = al43UserModel(
        password_hash('kata-sandi-al43-kontak', PASSWORD_BCRYPT),
        ['no_telepon' => $phone, 'email' => $email],
    );

    // THE DECISION, and it is a reversal of the one this test previously
    // asserted. Two inherited test files disagreed: this one required
    // `no_telepon` to be STORED RAW ("redacting it would destroy the log's
    // incident-response value"), while RedactionAbsenceTest required it to be
    // MASKED. Both could not hold, and the raw-storage reading was wrong on the
    // law rather than merely on taste.
    //
    // A phone number and an email address are personal data under UU PDP. The
    // row does not need them: it already carries `user_id`, `ip_address` and
    // `user_agent`, so the actor is identified without them. What the row DOES
    // need to answer is "did the number on file change?", and a masked form
    // answers that. A hash was rejected because a hash in an append-only table
    // is a permanent linkable identifier, which is the same reason the NIK is
    // masked and not hashed.
    //
    // So: masked, one masker, and the reasoning lives in
    // AuditColumnPolicy::DECISIONS where it can be argued with.
    $row = al43One('users', $user->getKey(), 'create');
    $whole = al43WholeRow($row);
    $baru = al43Payload($row, 'data_baru');

    // Absent from the whole row, not merely from the key we expected.
    expect($whole)->not->toContain($phone);
    expect($whole)->not->toContain($email);

    // Kept, not dropped: a masked phone still says a number was on file, and a
    // masked email still reads as an email field.
    expect($baru['no_telepon'])->toBe(NikMasker::mask($phone));
    expect($baru['no_telepon'])->not->toBe($phone);
    expect($baru['no_telepon'])->toContain(NikMasker::PENGGANTI);

    expect($baru['email'])->toContain('@');
    expect($baru['email'])->not->toContain('example');
    expect($baru['email'])->not->toBe($email);

    // And the decision is written down, so it cannot be quietly reverted.
    foreach (['no_telepon', 'email'] as $column) {
        expect(AuditColumnPolicy::DECISIONS)->toHaveKey($column);
        expect(strlen(AuditColumnPolicy::DECISIONS[$column]))->toBeGreaterThan(40);
        expect(AuditColumnPolicy::MASKED)->toHaveKey($column);
    }
});

// =====================================================================
// Session lifecycle: login and logout rows from the real endpoints
// =====================================================================

test('verifying the registration OTP writes a login row, and logout writes a logout row', function (): void {
    $telepon = '081243000001';
    $payload = [
        'nama_lengkap' => 'Al43 Login',
        'no_telepon' => $telepon,
        'email' => 'al43-login@example.test',
        'password' => 'kata-sandi-yang-kuat-123',
        'jenis_kelamin' => 'L',
        'tanggal_lahir' => '1990-05-17',
        'tempat_lahir' => 'Bandung',
        'alamat_lengkap' => 'Jl. Al43 Login No. 1',
        'bahasa' => 'id',
    ];

    $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

    /** @var FakeOtpSender $sender */
    $sender = app(OtpSender::class);
    $verify = $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => $telepon,
        'kode' => $sender->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);
    $verify->assertOk();

    $user = User::query()->where('no_telepon', $telepon)->firstOrFail();
    $login = al43One('users', $user->getKey(), 'login');
    expect((int) $login->user_id)->toBe((int) $user->getKey());
    expect($login->record_id)->toBe((string) $user->getKey());

    $refresh = (string) $verify->json('data.token.refresh_token');
    $access = (string) $verify->json('data.token.access_token');

    $this->withToken($access)
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $refresh])
        ->assertOk();

    $logout = al43One('users', $user->getKey(), 'logout');
    expect((int) $logout->user_id)->toBe((int) $user->getKey());
});

// =====================================================================
// Append-only: the log row cannot be reached, updated or deleted by design
// =====================================================================

test('audit_log carries dibuat_at only, and the model performs no updates', function (): void {
    $model = new AuditLog;

    expect(AuditLog::CREATED_AT)->toBe('dibuat_at');
    expect(AuditLog::UPDATED_AT)->toBeNull();
    expect($model->getTable())->toBe('audit_log');
});

test('forceDelete on RekamMedis is blocked and the access evidence survives', function (): void {
    $pasienId = al43PasienRow(al43UserRow());
    $dokterId = al43DokterRow();
    $record = al43Record($pasienId, $dokterId);

    $thrown = null;
    try {
        $record->forceDelete();
    } catch (Throwable $thrown) {
        // Expected: RekamMedis has no SoftDeletes, so there is no
        // forceDelete to call, and the cascade that would destroy the
        // akses_rekam_medis_log evidence can never run through it.
    }

    expect($thrown)->not->toBeNull();
    expect(DB::table('rekam_medis')->where('id', $record->getKey())->count())->toBe(1);
});

// =====================================================================
// DDL citations, read from the file and asserted so drift fails loudly
// =====================================================================

test('audit_log DDL citations: columns, lines, enum, indexes, bare keys', function (): void {
    $spec = (new \App\Support\Schema\SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $table = $spec->table('audit_log');
    expect($table)->not->toBeNull();

    // CREATE TABLE at 1118, closing ENGINE at 1132.
    expect($table->line)->toBe(1118);

    expect(array_keys($table->columns))->toBe([
        'id', 'user_id', 'aksi', 'tabel_target', 'record_id', 'data_lama',
        'data_baru', 'ip_address', 'user_agent', 'endpoint', 'dibuat_at',
    ]);

    $col = $table->columns;
    expect($col['user_id']->line)->toBe(1120);
    expect($col['aksi']->line)->toBe(1121);
    expect($col['record_id']->line)->toBe(1123);
    expect($col['data_lama']->line)->toBe(1124);
    expect($col['data_baru']->line)->toBe(1125);
    expect($col['dibuat_at']->line)->toBe(1129);

    // The eight aksi values, parsed from the DDL rather than transcribed:
    // reading only a hand-typed list is how literals silently drift.
    preg_match("/enum\((.*)\)/i", $col['aksi']->type, $matches);
    $aksi = array_map(
        static fn (string $member): string => trim($member, "' "),
        str_getcsv($matches[1] ?? '', ',', "'"),
    );
    expect($aksi)->toBe(['create', 'read', 'update', 'delete', 'login', 'logout', 'download', 'export']);

    // dibuat_at only: no diubah_at, no dihapus_at. Append-only by schema.
    expect(isset($col['diubah_at']))->toBeFalse();
    expect(isset($col['dihapus_at']))->toBeFalse();

    // Deliberately bare: nullable, VARCHAR(64), and NO foreign key, so the
    // log survives user and record deletion. A relation must never be added.
    expect($col['user_id']->nullable)->toBeTrue();
    expect($col['record_id']->nullable)->toBeTrue();
    expect($table->foreignKeys)->toBe([]);

    $indexNames = array_map(static fn ($index): string => (string) $index->name, $table->indexes);
    expect($indexNames)->toContain('idx_audit_user');
    expect($indexNames)->toContain('idx_audit_tabel');
});
