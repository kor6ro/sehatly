<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Pasien;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\SlotTakenException;
use App\Support\Rbac\RoleAssigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Booking concurrency: the `dokter` row lock, proved by two real transactions
|--------------------------------------------------------------------------
|
| ## What is actually proved here, and how
|
| `telemedicine_test.sql` has **no** unique index on
| `(dokter_id, tanggal_kunjungan, slot_mulai)` -- the only UNIQUE on `booking` is
| `nomor_booking` (:500), and the only indexes on the slot triple's leading
| columns are the two non-unique `INDEX` statements at :528 and :529. The
| database therefore cannot reject a double booking, and the guard has to be
| `SELECT ... FROM dokter WHERE id = ? FOR UPDATE` taken as the FIRST statement of
| the booking transaction.
|
| This file proves that with **two genuine, interleaved MySQL transactions on two
| separate connections**, not with a mocked assertion that a lock method was
| called. The choreography is:
|
| ```
| connection "mysql"  (the default, connection A)
|   BEGIN
|   SELECT ... FROM `dokter` WHERE id = ? FOR UPDATE      <-- A takes the lock
|   *** the QueryExecuted listener fires HERE, synchronously, inside A ***
|         connection "bkc_b"  (a second PDO, connection B)
|           BEGIN
|           SET innodb_lock_wait_timeout = 1
|           BookingService::create(...)  -> same doctor, same slot
|              SELECT ... FROM `dokter` ... FOR UPDATE
|                 -> MySQL error 1205, lock wait timeout, because A holds it
|           ROLLBACK
|   INSERT INTO booking ... ; COMMIT
| ```
|
| The listener is a `DB::listen()` callback, which Laravel fires SYNCHRONOUSLY on
| the `QueryExecuted` event right after each statement returns. That is what
| makes the interleaving real rather than simulated: B genuinely runs while A's
| transaction is open and holding the row, on a different connection, and the
| only reason B cannot proceed is the row lock A took.
|
| B is running the REAL `BookingService::create()`, with `config('database.default')`
| briefly pointed at the second connection so `DB::transaction()` and every
| Eloquent model resolve there. The config is restored in a `finally`, before the
| listener returns, so A continues on its own connection.
|
| `tests/Feature/Booking/BookingTest.php` asserts the emitted SQL contains `from
| \`dokter\`` and `for update`; it does not, and cannot, assert that the lock is
| CONTENDED. Only a second connection can show that, which is why this file
| exists separately.
|
| ## Why this file leaves the `RefreshDatabase` wrapping transaction
|
| `RefreshDatabase` wraps every test in one transaction, whose rows are invisible
| to any other connection. Two interleaved transactions therefore have to run
| against COMMITTED data, so {@see bkcMulai()} commits the wrapper first and
| {@see bkcSelesai()} deletes the committed fixtures and re-opens a transaction so
| `RefreshDatabase`'s teardown still finds an active one. `RefreshDatabase` checks
| `! $pdo->inTransaction()` at teardown and, if that holds, sets
| `RefreshDatabaseState::$migrated = false` -- which would force a full
| `migrate:fresh` for every remaining test in the process. Re-opening the
| transaction avoids that.
|
| The helper prefix is `bkc`, for the reason `BookingTest` gives at length: Pest
| loads every test file into one process and `bku*` (todo 27) and `slot*`
| (todo 26) are already taken.
|
*/

// =====================================================================
// Fixtures
// =====================================================================

const BKC_TANGGAL = '2026-12-07';
const BKC_HARI = 1;
const BKC_KONEKSI_UTAMA = 'mysql';
const BKC_KONEKSI_LAWAN = 'bkc_b';

/** MySQL's lock-wait timeout as a driver error code. */
const BKC_KODE_LOCK_WAIT = 1205;

/**
 * A `users` row; `uuid` (:133), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are the NOT NULL columns with no default.
 */
function bkcUser(string $nama, string $tipe = 'pasien'): int
{
    return (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);
}

/**
 * A `pasien` row. `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are NOT NULL with no default.
 */
function bkcPasienRow(int $userId): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Konkurensi No. 5, Jakarta',
    ]);
}

/**
 * A `dokter` row. `durasi_default_menit` is 20 so an INSTANT booking is twenty
 * minutes long, which is distinguishable from the 15-minute schedule window.
 *
 * @param  array<string, mixed>  $ubah
 */
function bkcDokterRow(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-BKC-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'pengalaman_tahun' => 5,
        'biaya_konsultasi_online' => '150000.00',
        'durasi_default_menit' => 20,
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `dokter_jadwal` row: a Monday 09:00-10:00 window of 15-minute slots.
 *
 * @param  array<string, mixed>  $ubah
 */
function bkcJadwalRow(int $dokterId, array $ubah = []): int
{
    return (int) DB::table('dokter_jadwal')->insertGetId(array_merge([
        'dokter_id' => $dokterId,
        'faskes_id' => null,
        'tipe_layanan' => 'online',
        'hari' => BKC_HARI,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '10:00:00',
        'durasi_slot_menit' => 15,
        'kuota_per_sesi' => null,
        'berlaku_mulai' => '2020-01-01',
        'berlaku_sampai' => null,
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A minimal valid create payload.
 *
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function bkcPayload(int $dokterId, array $ubah = []): array
{
    return array_merge([
        'dokter_id' => $dokterId,
        'jadwal_id' => null,
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => BKC_TANGGAL,
        'slot_mulai' => '09:00:00',
        'keluhan' => 'Uji konkurensi.',
    ], $ubah);
}

/**
 * The service payload a real request would have validated.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function bkcTerValidasi(array $payload): array
{
    return array_filter(
        $payload,
        static fn (mixed $nilai): bool => $nilai !== null,
        ARRAY_FILTER_USE_BOTH,
    );
}

// =====================================================================
// The committed-fixture dance
// =====================================================================

/**
 * Leave the `RefreshDatabase` wrapping transaction and open a SECOND connection.
 *
 * The commit is what makes the fixtures visible to the other connection; without
 * it the second transaction would see an empty `booking` table and every
 * assertion below would be meaningless. The second connection is a copy of the
 * `mysql` config under a new name, so `DatabaseManager` builds a genuinely
 * separate `Connection` with its own PDO -- `ConnectionFactory` caches nothing.
 */
function bkcMulai(): void
{
    if (DB::connection(BKC_KONEKSI_UTAMA)->transactionLevel() > 0) {
        DB::commit();
    }

    config([
        'database.connections.'.BKC_KONEKSI_LAWAN => config('database.connections.'.BKC_KONEKSI_UTAMA),
    ]);

    DB::purge(BKC_KONEKSI_LAWAN);

    $lawan = DB::connection(BKC_KONEKSI_LAWAN);

    // One second, not MySQL's 50. A `SELECT ... FOR UPDATE` that has to wait
    // therefore fails fast and deterministically instead of stalling the suite.
    $lawan->statement('SET SESSION innodb_lock_wait_timeout = 1');

    // MySQL's own default, stated explicitly on BOTH connections so the test
    // proves the current-read hardening under REPEATABLE READ rather than
    // inheriting whatever the server was configured with.
    $lawan->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::connection(BKC_KONEKSI_UTAMA)->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    expect($lawan->selectOne('SELECT @@transaction_isolation AS v')->v)->toBe('REPEATABLE-READ')
        ->and(DB::connection(BKC_KONEKSI_UTAMA)->selectOne('SELECT @@transaction_isolation AS v')->v)
        ->toBe('REPEATABLE-READ');
}

/**
 * Delete the committed fixtures, then re-open a transaction so
 * `RefreshDatabase`'s teardown still finds an active one.
 *
 * Children before parents, in the order the foreign keys demand: `invoice` has no
 * FK to `booking` (`:941` is a bare polymorphic column) so it is deleted by
 * `pasien_id`; `booking` references `pasien`, `dokter_jadwal` and `users`; and
 * `dokter_jadwal` and `user_roles` CASCADE from their parents, so deleting the
 * `dokter` and `users` rows is enough for them.
 *
 * The committed RBAC seed goes too: `bkcMulai()` committed the wrapper
 * transaction, so `RbacSeeder`'s plain non-idempotent inserts are now durable,
 * and the next test's `beforeEach` would collide on them. `roles`,
 * `permissions` and `role_permissions` are only ever written by that seeder,
 * so emptying all three is what lets the next seed run cleanly. Children
 * first, in FK order.
 *
 * @param  list<int>  $pasienIds
 * @param  list<int>  $dokterIds
 * @param  list<int>  $userIds
 */
function bkcSelesai(array $pasienIds, array $dokterIds, array $userIds): void
{
    DB::table('invoice')->whereIn('pasien_id', $pasienIds)->delete();
    DB::table('booking')->whereIn('pasien_id', $pasienIds)->delete();
    DB::table('booking')->whereIn('dibuat_oleh_user_id', $userIds)->delete();
    DB::table('pasien')->whereIn('id', $pasienIds)->delete();
    DB::table('dokter')->whereIn('id', $dokterIds)->delete();
    DB::table('users')->whereIn('id', $userIds)->delete();
    DB::table('role_permissions')->delete();
    DB::table('permissions')->delete();
    DB::table('roles')->delete();

    // Anything the two connections left open, so the teardown's `rollBack()` is
    // not the first thing that sees them.
    if (DB::connection(BKC_KONEKSI_LAWAN)->transactionLevel() > 0) {
        DB::connection(BKC_KONEKSI_LAWAN)->rollBack();
    }

    DB::purge(BKC_KONEKSI_LAWAN);

    if (DB::connection(BKC_KONEKSI_UTAMA)->transactionLevel() === 0) {
        DB::beginTransaction();
    }
}

/**
 * Run `$aksi` on the SECOND connection with the default connection pointed at
 * it, then put the default back.
 *
 * `config('database.default')` is the single switch both `DB::transaction()` and
 * every Eloquent model read, so pointing it at the second connection is enough to
 * run the unmodified production service there.
 *
 * @template T
 *
 * @param  callable(): T  $aksi
 * @return T
 */
function bkcDiSisiLawan(callable $aksi): mixed
{
    config(['database.default' => BKC_KONEKSI_LAWAN]);

    try {
        return $aksi();
    } finally {
        config(['database.default' => BKC_KONEKSI_UTAMA]);
    }
}

/**
 * Is `$e` a MySQL lock-wait timeout, whatever Laravel wrapped it in?
 *
 * The driver code is read out of `QueryException::$errorInfo` rather than matched
 * against a class, because whether 1205 becomes a `DeadlockException` or a plain
 * `QueryException` is a framework detail and the property under test is the
 * server's answer, not Laravel's mapping of it.
 */
function bkcAdalahLockWait(Throwable $e): bool
{
    for ($tipe = $e; $tipe !== null; $tipe = $tipe->getPrevious()) {
        if ($tipe instanceof QueryException && (int) ($tipe->errorInfo[1] ?? 0) === BKC_KODE_LOCK_WAIT) {
            return true;
        }
    }

    return false;
}

/**
 * Fire `$aksi` on the second connection the first time the default connection
 * runs a `SELECT ... FROM dokter ... FOR UPDATE`.
 *
 * The listener is registered on the shared query event dispatcher and filters on
 * `QueryExecuted::$connectionName`, so it fires for the default connection's
 * statements and not for the second connection's -- and it fires ONCE, guarded by
 * a flag, because the second connection's own statements would otherwise re-enter
 * it.
 *
 * @param  callable(): mixed  $aksi
 * @param  array{sudah: bool, hasil: mixed}  $keadaan
 */
function bkcSaatDokterTerkunci(array &$keadaan, callable $aksi): void
{
    DB::listen(function (QueryExecuted $peristiwa) use (&$keadaan, $aksi): void {
        if ($keadaan['sudah'] === true) {
            return;
        }

        if ($peristiwa->connectionName !== BKC_KONEKSI_UTAMA) {
            return;
        }

        $sql = mb_strtolower($peristiwa->sql);

        // ``from `dokter``` and not ``from `dokter_jadwal```: the trailing
        // backtick is what separates the two, and the doctor lock has to be the
        // one this listener reacts to.
        if (! str_contains($sql, 'from `dokter`') || ! str_contains($sql, 'for update')) {
            return;
        }

        $keadaan['sudah'] = true;

        try {
            $keadaan['hasil'] = bkcDiSisiLawan($aksi);
        } catch (Throwable $e) {
            $keadaan['hasil'] = $e;
        }
    });
}

// =====================================================================
// Tests that do NOT need the committed-fixture dance
// =====================================================================

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});
test('the fixture dates land on the weekday the DDL comment at :475 names', function (): void {
    expect(Carbon::parse(BKC_TANGGAL)->dayOfWeek)->toBe(BKC_HARI);
});

test('over HTTP, five requests for one slot produce exactly one booking and four slot errors', function (): void {
    // The plan's criterion, measured over the real route table with the real
    // middleware, the real FormRequest, the real service and the real 422
    // envelope. **These are five SEQUENTIAL requests, not five OS-parallel
    // ones.** PHP-FPM on this target is single-threaded and there is no parallel
    // HTTP client in the framework, so what this test measures is the CAPACITY
    // guard: once one booking holds the slot, the quota of one is spent and the
    // other four are refused. The lock is what stops two of those five from
    // passing the check at the same moment, and that is what the two tests below
    // measure with a second connection.
    $userId = bkcUser('Pasien Konkurensi');
    $pasienId = bkcPasienRow($userId);
    app(RoleAssigner::class)->assign($userId, 'pasien');

    $dokterUserId = bkcUser('Dokter Konkurensi', 'dokter');
    $dokterId = bkcDokterRow($dokterUserId);
    bkcJadwalRow($dokterId);

    $user = User::query()->findOrFail($userId);
    app('auth')->forgetGuards();
    $token = $user->createToken('bkc', ['*'], now()->addHour())->plainTextToken;

    $sukses = 0;
    $ditolak = 0;

    for ($i = 0; $i < 5; $i++) {
        $response = test()->withToken($token)->postJson('/api/v1/booking', bkcPayload($dokterId));

        if ($response->status() === 201) {
            $sukses++;

            continue;
        }

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.slot.0', 'Slot sudah penuh untuk waktu ini.');

        $ditolak++;
    }

    expect($sukses)->toBe(1)
        ->and($ditolak)->toBe(4)
        ->and(DB::table('booking')->where('dokter_id', $dokterId)->count())->toBe(1);
});

// =====================================================================
// The interleaving proofs
// =====================================================================

test('a second connection CANNOT pass the check while the first holds the dokter row', function (): void {
    // The proof, step by step:
    //
    // 1. A begins a booking transaction and its FIRST statement is
    //    `SELECT ... FROM dokter WHERE id = ? FOR UPDATE`.
    // 2. The listener fires inside A, and B runs the REAL service on a second
    //    connection for the SAME doctor, the SAME date and the SAME slot.
    // 3. B cannot get past its own `SELECT ... FROM dokter ... FOR UPDATE`,
    //    because A holds that row, so MySQL raises 1205.
    // 4. A finishes and commits. B retries, and this time it gets past the lock
    //    and is refused by the CAPACITY check instead -- which is the whole
    //    reason the check is a current read rather than a snapshot read.
    //
    // Step 3 is what a mocked "the lock method was called" assertion cannot show.
    bkcMulai();

    $userId = bkcUser('Pasien Konkurensi');
    $pasienId = bkcPasienRow($userId);
    $dokterUserId = bkcUser('Dokter Konkurensi', 'dokter');
    $dokterId = bkcDokterRow($dokterUserId);

    // An INSTANT booking: no `dokter_jadwal` row at all, so the only row this
    // transaction can possibly contend for is `dokter` itself. This is the case
    // the plan says has "no lockable row" in an earlier draft.
    bkcJadwalRow($dokterId);

    $layanan = app(BookingService::class);
    $payload = bkcTerValidasi(bkcPayload($dokterId));
    $pasien = Pasien::query()->findOrFail($pasienId);
    $user = User::query()->findOrFail($userId);

    $urutan = [];
    DB::listen(function (QueryExecuted $p) use (&$urutan): void {
        if ($p->connectionName === BKC_KONEKSI_UTAMA) {
            $urutan[] = mb_strtolower($p->sql);
        }
    });

    $keadaan = ['sudah' => false, 'hasil' => null];

    bkcSaatDokterTerkunci($keadaan, static fn (): Booking => $layanan->create(
        $pasien,
        $user,
        $payload,
    ));

    try {
        // ---- connection A ----
        $booking = $layanan->create($pasien, $user, $payload);

        expect($booking)->toBeInstanceOf(Booking::class)
            // The first statement of the transaction is the doctor lock. This is
            // the ordering the plan insists on, and the reason a `dokter_jadwal`
            // lock could never come first: it would give the two paths different
            // lock targets.
            ->and($urutan[0])->toContain('from `dokter`')
            ->and($urutan[0])->toContain('for update')
            // A committed exactly one row.
            ->and(DB::table('booking')->where('dokter_id', $dokterId)->count())->toBe(1);

        // ---- connection B, while A was still holding the lock ----
        expect($keadaan['sudah'])->toBeTrue('The listener never fired, so nothing was interleaved.');
        expect($keadaan['hasil'])->toBeInstanceOf(Throwable::class);
        expect(bkcAdalahLockWait($keadaan['hasil']))->toBeTrue(
            'The competitor was not blocked by the dokter row lock. Message: '
            .($keadaan['hasil'] instanceof Throwable ? $keadaan['hasil']->getMessage() : 'no exception'),
        );

        // B wrote nothing, because its transaction rolled back.
        expect(DB::table('booking')->where('dokter_id', $dokterId)->count())->toBe(1);

        // ---- connection B, retrying now that A has committed ----
        // Past the lock, and refused by the capacity check. A plain consistent
        // read would still see the pre-transaction snapshot and answer zero, so
        // this is also the assertion that the count is a CURRENT read.
        $retry = null;
        try {
            bkcDiSisiLawan(static fn (): Booking => $layanan->create($pasien, $user, $payload));
        } catch (SlotTakenException $e) {
            $retry = $e;
        }

        expect($retry)->toBeInstanceOf(SlotTakenException::class)
            ->and($retry->errors())->toHaveKey('slot')
            ->and(DB::table('booking')->where('dokter_id', $dokterId)->count())->toBe(1);
    } finally {
        bkcSelesai([$pasienId], [$dokterId], [$userId, $dokterUserId]);
    }
});

test('a scheduled request and an instant request for the same doctor and time serialise on ONE row', function (): void {
    // The test that distinguishes the shipped design from the rejected one.
    //
    // An earlier draft locked `dokter_jadwal` when `jadwal_id` was present and
    // `dokter` otherwise. Under that design a SCHEDULED request and an INSTANT
    // request for the same doctor and overlapping time lock DIFFERENT rows, run
    // concurrently, and both observe a count below quota -- a double-booking
    // hole. Here A is scheduled and B is instant; if the design were the rejected
    // one, B would not block and this test would fail.
    bkcMulai();

    $userId = bkcUser('Pasien Konkurensi');
    $pasienId = bkcPasienRow($userId);
    $dokterUserId = bkcUser('Dokter Konkurensi', 'dokter');
    $dokterId = bkcDokterRow($dokterUserId);
    $jadwalId = bkcJadwalRow($dokterId);

    $layanan = app(BookingService::class);
    $pasien = Pasien::query()->findOrFail($pasienId);
    $user = User::query()->findOrFail($userId);

    // A: scheduled, so it takes the `dokter` row first and the `dokter_jadwal`
    // row second.
    $terjadwal = bkcTerValidasi(bkcPayload($dokterId, ['jadwal_id' => $jadwalId]));

    // B: instant, no `jadwal_id`, same doctor, same date, same slot.
    $instan = bkcTerValidasi(bkcPayload($dokterId, [
        'jadwal_id' => null,
        'tipe_layanan' => 'chat',
        'slot_mulai' => '09:00:00',
    ]));

    $urutan = [];
    DB::listen(function (QueryExecuted $p) use (&$urutan): void {
        if ($p->connectionName === BKC_KONEKSI_UTAMA) {
            $urutan[] = mb_strtolower($p->sql);
        }
    });

    $keadaan = ['sudah' => false, 'hasil' => null];

    bkcSaatDokterTerkunci($keadaan, static fn (): Booking => $layanan->create($pasien, $user, $instan));

    try {
        $booking = $layanan->create($pasien, $user, $terjadwal);

        expect($booking->jadwal_id)->toBe($jadwalId)
            ->and($booking->slot_selesai)->toBe('09:15:00')
            ->and(DB::table('booking')->where('dokter_id', $dokterId)->count())->toBe(1);

        // The lock ORDER, which is the second half of the claim. The `dokter` row
        // is taken before the `dokter_jadwal` row, and the schedule row is locked
        // at all only because A supplied a `jadwal_id`.
        $lockDokter = null;
        $lockJadwal = null;

        foreach ($urutan as $satu => $sql) {
            if ($lockDokter === null && str_contains($sql, 'from `dokter`') && str_contains($sql, 'for update')) {
                $lockDokter = $satu;
            }

            if ($lockJadwal === null && str_contains($sql, 'from `dokter_jadwal`') && str_contains($sql, 'for update')) {
                $lockJadwal = $satu;
            }
        }

        expect($lockDokter)->not->toBeNull()
            ->and($lockJadwal)->not->toBeNull()
            ->and($lockDokter)->toBeLessThan($lockJadwal);

        // B blocked on the very same row A is holding.
        expect($keadaan['sudah'])->toBeTrue('The listener never fired, so nothing was interleaved.')
            ->and($keadaan['hasil'])->toBeInstanceOf(Throwable::class)
            ->and(bkcAdalahLockWait($keadaan['hasil']))->toBeTrue(
                'An instant request did not serialise against a scheduled one for the same doctor and time.',
            );

        // And once A has committed, the instant request is refused by capacity
        // rather than creating a second booking on the same slot.
        $ditolak = null;
        try {
            bkcDiSisiLawan(static fn (): Booking => $layanan->create($pasien, $user, $instan));
        } catch (SlotTakenException $e) {
            $ditolak = $e;
        }

        expect($ditolak)->toBeInstanceOf(SlotTakenException::class)
            ->and(DB::table('booking')->where('dokter_id', $dokterId)->count())->toBe(1);
    } finally {
        bkcSelesai([$pasienId], [$dokterId], [$userId, $dokterUserId]);
    }
});

test('two connections, five creates for one slot: one wins and four are refused by capacity', function (): void {
    // The plan's 1-of-5 criterion, run so that every attempt is its own
    // transaction on its own connection, alternating between them. Sequential
    // rather than OS-parallel -- the lock contention is proved by the two tests
    // above, and what this adds is that the CAPACITY answer does not depend on
    // which connection asks, and that REPEATABLE READ (stated explicitly on both
    // connections by `bkcMulai()`) does not change it.
    bkcMulai();

    $userId = bkcUser('Pasien Konkurensi');
    $pasienId = bkcPasienRow($userId);
    $dokterUserId = bkcUser('Dokter Konkurensi', 'dokter');
    $dokterId = bkcDokterRow($dokterUserId);

    $layanan = app(BookingService::class);
    $pasien = Pasien::query()->findOrFail($pasienId);
    $user = User::query()->findOrFail($userId);
    $payload = bkcTerValidasi(bkcPayload($dokterId));

    try {
        $sukses = 0;
        $ditolak = 0;

        for ($i = 0; $i < 5; $i++) {
            $sisiLawan = $i % 2 === 1;

            // A refused attempt THROWS `SlotTakenException` (the same class the
            // retry test catches above), so it is counted here rather than
            // left to error the test: five attempts, one row, four refusals.
            try {
                $hasil = $sisiLawan
                    ? bkcDiSisiLawan(static fn (): Booking => $layanan->create($pasien, $user, $payload))
                    : $layanan->create($pasien, $user, $payload);
            } catch (SlotTakenException) {
                $hasil = null;
            }

            if ($hasil instanceof Booking) {
                $sukses++;

                continue;
            }

            $ditolak++;
        }

        expect($sukses)->toBe(1)
            ->and($ditolak)->toBe(4)
            ->and(DB::table('booking')->where('dokter_id', $dokterId)->count())->toBe(1);
    } finally {
        bkcSelesai([$pasienId], [$dokterId], [$userId, $dokterUserId]);
    }
});
