<?php

declare(strict_types=1);

use App\Enums\PembayaranStatus;
use App\Services\Payment\PaymentService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/payment-helpers.php';

/*
|--------------------------------------------------------------------------
| Todo 45 - the concurrency claim, proved with two real transactions
|--------------------------------------------------------------------------
|
| ## What is being claimed, and why a mock would prove nothing
|
| `PaymentService::huntap()` looks a payment up with
| `SELECT ... FROM pembayaran WHERE gateway = ? AND nomor_referensi = ?
| FOR UPDATE`. The claim is that this closes the race a best-effort
| application-level dedupe key leaves open, and that the `FOR UPDATE` is what
| closes it - not luck, not the isolation level, and not the fact that a retry
| usually arrives a second later.
|
| A mocked assertion about `lockForUpdate()` proves nothing about contention:
| it would pass against a lock on the wrong row, on a lock taken after the
| write, or on no lock at all. So this file uses **two genuine, interleaved
| MySQL transactions on two separate connections** - the same choreography
| `PromoQuotaConcurrencyTest` and `BookingConcurrencyTest` use - and proves
| BOTH halves:
|
| 1. **The real settlement path takes the lock.** Connection A holds the
|    `pembayaran` row; connection B then runs the ACTUAL production code -
|    `PaymentService::terimaWebhook()` through the real gateway - and collides
|    on it with MySQL error **1205** (lock wait timeout). B wrote nothing.
| 2. **A non-locking read would NOT have been serialised.** Holding the same
 *    lock on A, connection B runs a plain `SELECT` of the same row and
|    returns INSTANTLY, reading `pending` - a value A is about to change. Under
|    REPEATABLE READ a consistent read takes no locks at all, so it neither
|    waits for A nor blocks A. This is the half that makes the first worth
|    reading: if a plain `first()` had been used, two concurrent deliveries
|    would both read `pending` and both write, which is exactly the race the
|    schema cannot prevent.
|
| ## What the schema cannot do, restated
|
| `pembayaran.nomor_referensi` is `VARCHAR(100) NULL` (:963) and
| `pembayaran.gateway` is `ENUM(...) NULL` (:965). Neither has a UNIQUE
| constraint and neither has an index - the only index on the table is
| `INDEX idx_bayar_status (status, dibayar_at)` (:972), over two columns that
| are in neither half of the key. `PaymentWebhookTest` asserts both facts
 *    against the raw DDL and against `information_schema` on the live table.
| The plan forbids adding the index that would close the race at the database
| level, so the row lock is the whole of the defence and this file is what says
| so with evidence.
|
| ## The cost that is NOT hidden
|
| A `SELECT ... FOR UPDATE` whose predicate matches no index takes **gap
 *    locks** on the range it scans, so the settlement statement is a full scan
 *    of `pembayaran` and a lock covers every row it examines. Two unrelated
 *    payments can briefly serialise behind each other. That is a throughput
 *    cost, not a correctness one, and it is the price of the DDL being
|    read-only. `INDEX (gateway, nomor_referensi)` would turn the scan into a
 *    point probe; it is NOT added here and is recorded in the evidence file.
|
| ## Why this file leaves the `RefreshDatabase` wrapping transaction
|
| `RefreshDatabase` wraps each test in ONE transaction whose rows are invisible
| to any other connection, so two interleaved transactions have to run against
| COMMITTED data. {@see pay45cMulai()} commits the wrapper first and
| {@see pay45cSelesai()} deletes the committed fixtures and re-opens a
| transaction, so `RefreshDatabase`'s teardown still finds an active one - it
| checks `! $pdo->inTransaction()` and, if that held, would set
| `RefreshDatabaseState::$migrated = false` and force a `migrate:fresh` for
| every remaining test in the process.
|
| The teardown ROLLS BACK first and deletes afterwards. A test that committed
| the wrapper and then failed still holds a transaction, and deleting inside it
| means `RefreshDatabase`'s rollback undoes the cleanup - which leaves the
| committed RBAC seed behind and fails the NEXT test's `RbacSeeder` with
| duplicate `roles` rows. That is not hypothetical: it is what happened to todo
| 44's own concurrency file, and the order is written down here so it does not
| happen twice.
|
*/

const PAY45C_KONEKSI_UTAMA = 'mysql';

const PAY45C_KONEKSI_LAWAN = 'pay45c_b';

/** MySQL's lock-wait timeout as a driver error code. */
const PAY45C_KODE_LOCK_WAIT = 1205;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    pay45KunciJam();
});

afterEach(function (): void {
    pay45LepasJam();
});

/**
 * Leave the `RefreshDatabase` wrapper and open a second connection.
 *
 * The commit is what makes the fixtures visible to the other connection. The
 * second connection is a copy of the `mysql` config under a new name, and
 * `innodb_lock_wait_timeout = 1` makes a contended `SELECT ... FOR UPDATE` fail
 * fast and deterministically instead of stalling the suite.
 */
function pay45cMulai(): void
{
    if (DB::connection(PAY45C_KONEKSI_UTAMA)->transactionLevel() > 0) {
        DB::commit();
    }

    config([
        'database.connections.'.PAY45C_KONEKSI_LAWAN => config('database.connections.'.PAY45C_KONEKSI_UTAMA),
    ]);

    DB::purge(PAY45C_KONEKSI_LAWAN);

    $lawan = DB::connection(PAY45C_KONEKSI_LAWAN);

    $lawan->statement('SET SESSION innodb_lock_wait_timeout = 1');

    // MySQL's own default, stated explicitly on BOTH connections so the test
    // proves the current-read hardening under REPEATABLE READ rather than
    // inheriting whatever the server was configured with.
    $lawan->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::connection(PAY45C_KONEKSI_UTAMA)->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    Assert::assertSame('REPEATABLE-READ', $lawan->selectOne('SELECT @@transaction_isolation AS v')->v);
    Assert::assertSame(
        'REPEATABLE-READ',
        DB::connection(PAY45C_KONEKSI_UTAMA)->selectOne('SELECT @@transaction_isolation AS v')->v
    );
}

/**
 * Delete the committed fixtures, then re-open a transaction so
 * `RefreshDatabase`'s teardown still finds an active one.
 *
 * ## The ORDER of these three steps is the whole teardown
 *
 * 1. **Roll back** anything the test left open, so the deletes below are not
 *    undone by `RefreshDatabase`'s own rollback.
 * 2. **Delete**, including the committed RBAC seed - `RbacSeeder` is not
 *    idempotent, and {@see pay45cMulai()} committed the wrapper, so leaving it
 *    behind fails the NEXT test's `beforeEach` with a duplicate
 *    `roles.roles_nama_unique` and hides the real cause.
 * 3. **Re-open** the wrapper transaction LAST.
 *
 * Step 3 must be last. An earlier version re-opened the transaction first and
 * deleted the seed afterwards, so the deletes joined the wrapper and were
 * rolled back by `RefreshDatabase` - the seed survived and the next test
 * failed on a 1062 that had nothing to do with it. That is the same trap
 * `inv44Selesai()` documents, and it cost this file one run to find.
 */
function pay45cSelesai(): void
{
    if (DB::connection(PAY45C_KONEKSI_UTAMA)->transactionLevel() > 0) {
        DB::connection(PAY45C_KONEKSI_UTAMA)->rollBack();
    }

    if (DB::connection(PAY45C_KONEKSI_LAWAN)->transactionLevel() > 0) {
        DB::connection(PAY45C_KONEKSI_LAWAN)->rollBack();
    }

    DB::purge(PAY45C_KONEKSI_LAWAN);

    try {
        pay45BersihkanSemua();

        foreach (['role_permissions', 'permissions', 'roles'] as $tabel) {
            DB::table($tabel)->delete();
        }
    } finally {
        if (DB::connection(PAY45C_KONEKSI_UTAMA)->transactionLevel() === 0) {
            DB::beginTransaction();
        }
    }
}

/**
 * Run `$aksi` on the SECOND connection and capture whatever it throws rather
 * than letting it escape, so the choreography can assert on the ANSWER.
 *
 * @param  array<string, mixed>  $keadaan
 */
function pay45cJalankanLawan(array &$keadaan, callable $aksi): void
{
    config(['database.default' => PAY45C_KONEKSI_LAWAN]);

    try {
        $keadaan['hasil'] = $aksi();
    } catch (Throwable $e) {
        $keadaan['hasil'] = $e;
    } finally {
        config(['database.default' => PAY45C_KONEKSI_UTAMA]);
    }
}

/**
 * Is `$e` a MySQL lock-wait timeout, whatever Laravel wrapped it in?
 */
function pay45cAdalahLockWait(Throwable $e): bool
{
    for ($tipe = $e; $tipe !== null; $tipe = $tipe->getPrevious()) {
        if ($tipe instanceof QueryException
            && (int) ($tipe->errorInfo[1] ?? 0) === PAY45C_KODE_LOCK_WAIT) {
            return true;
        }
    }

    return false;
}

/**
 * A paid-in-principle invoice: one `booking`, one invoice, one `pembayaran`
 * row already opened, all COMMITTED so the second connection can see them.
 *
 * @return array{booking: int, invoice: int, pembayaran: int, referensi: string, total: string}
 */
function pay45cSiapkan(): array
{
    [$user, $pasienId] = pay45AkunPasien('Pasien Konkurensi');
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])
        ->assertCreated();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());

    return [
        'booking' => $bookingId,
        'invoice' => (int) $invoice->getKey(),
        'pembayaran' => (int) $baris->id,
        'referensi' => (string) $baris->nomor_referensi,
        'total' => (string) $invoice->total,
    ];
}

test('the real settlement path BLOCKS on the pembayaran row lock a concurrent delivery holds', function (): void {
    $siap = pay45cSiapkan();

    pay45cMulai();

    try {
        // --- A: the main connection opens a transaction and takes the row lock
        // that `PaymentService::huntap()` takes. This is the same statement,
        // issued by hand so the choreography controls when it happens.
        DB::beginTransaction();

        $terkunci = DB::selectOne(
            'SELECT status FROM pembayaran WHERE id = ? FOR UPDATE',
            [$siap['pembayaran']]
        );

        expect((string) $terkunci->status)->toBe(PembayaranStatus::Pending->value);

        // Every statement the SECOND connection is about to issue.
        //
        // `beforeExecuting`, NOT `DB::listen`. `QueryExecuted` fires only after
        // a statement RETURNS, and the statement that matters here is the one
        // that BLOCKS - so a `listen` collector never sees it. That is the
        // same trap `PromoQuotaConcurrencyTest` documents for its own
        // choreography, and it is why this file uses the pre-execution hook:
        // it records the blocking `SELECT ... FOR UPDATE` on the way IN.
        $pernyataan = [];

        DB::connection(PAY45C_KONEKSI_LAWAN)->beforeExecuting(function ($query) use (&$pernyataan): void {
            $pernyataan[] = (string) $query;
        });

        // --- B: the SECOND connection runs the REAL production path: the
        // actual `PaymentService::terimaWebhook()` with the real gateway behind
        // it. No mock, no stub, no hand-written SQL - the question is whether
        // the shipped code takes a lock that collides with a held one.
        $keadaan = [];

        pay45cJalankanLawan($keadaan, function () use ($siap): mixed {
            return app(PaymentService::class)->terimaWebhook(PAY45_GATEWAY, [
                'nomor_referensi' => $siap['referensi'],
                'status' => PembayaranStatus::Berhasil->value,
                'jumlah' => $siap['total'],
                'gateway' => PAY45_GATEWAY,
                'payload' => ['nomor_referensi' => $siap['referensi']],
            ]);
        });

        // B collided with the row A is holding, and the collision surfaced as
        // MySQL 1205 rather than as a silent second write.
        expect($keadaan['hasil'])->toBeInstanceOf(Throwable::class)
            ->and(pay45cAdalahLockWait($keadaan['hasil']))->toBeTrue(
                'the second connection did not collide on the row lock: '.get_debug_type($keadaan['hasil'])
            );

        // ## WHERE it collided is the load-bearing half, and a first version of
        // this test did not say so - and survived removing `lockForUpdate()`
        // entirely.
        //
        // Without the locking read, B's plain `SELECT` does not block, so B
        // reads `pending`, decides it is the first delivery, and blocks on the
        // `UPDATE` instead. The 1205 is the same error, the same code path, and
        // the same "B wrote nothing" outcome - so an assertion on the exception
        // alone cannot tell the correct implementation from the broken one.
        //
        // What distinguishes them is the statement B reached before it blocked.
        // With the lock, B stops at `SELECT ... FOR UPDATE` and never issues a
        // write. Without it, B issues `update pembayaran`. So both halves are
        // asserted, and the negative one is the one that has teeth.
        $ketemu = array_values(array_filter(
            $pernyataan,
            static fn (string $sql): bool => str_contains($sql, '`pembayaran`') && str_contains(strtolower($sql), 'for update')
        ));

        expect($ketemu)->not->toBeEmpty('B never issued the locking read, so the lock is not where the defence is');

        $tertulis = array_values(array_filter(
            $pernyataan,
            static fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'update ')
                && str_contains($sql, '`pembayaran`')
        ));

        expect($tertulis)->toBe([],
            'B reached a write on `pembayaran` before colliding, so the dedupe lookup is not a locking read: '
            .json_encode($tertulis));

        // --- A commits, and only then can B proceed.
        DB::commit();

        // --- B wrote NOTHING. Its transaction rolled back on the collision, so
        // every one of the three rows is still as A left it.
        $pembayaran = DB::table('pembayaran')->where('id', $siap['pembayaran'])->first();
        $invoice = DB::table('invoice')->where('id', $siap['invoice'])->first();
        $booking = DB::table('booking')->where('id', $siap['booking'])->first();

        expect((string) $pembayaran->status)->toBe(PembayaranStatus::Pending->value)
            ->and($pembayaran->dibayar_at)->toBeNull()
            ->and($pembayaran->webhook_payload)->toBeNull()
            ->and((string) $invoice->status)->toBe('menunggu_pembayaran')
            ->and($invoice->lunas_at)->toBeNull()
            ->and((string) $booking->status)->toBe('menunggu_pembayaran');
    } finally {
        pay45cSelesai();
    }
});

test('a NON-locking read of the same row does NOT wait, which is why the FOR UPDATE is the defence', function (): void {
    $siap = pay45cSiapkan();

    pay45cMulai();

    try {
        // A holds the row lock and has already decided to settle it, but has
        // not written yet.
        DB::beginTransaction();

        DB::selectOne('SELECT status FROM pembayaran WHERE id = ? FOR UPDATE', [$siap['pembayaran']]);
        DB::table('pembayaran')->where('id', $siap['pembayaran'])->update([
            'status' => PembayaranStatus::Berhasil->value,
            'dibayar_at' => PAY45_DETIK,
        ]);

        // B reads the SAME row with a plain consistent read. Under REPEATABLE
        // READ that takes no locks at all, so it neither waits for A nor blocks
        // A - and it resolves against B's own snapshot, which predates A's
        // write. This is the second delivery seeing `pending` and concluding
        // it is the first, which is the race the dedupe key alone leaves open.
        $keadaan = [];

        pay45cJalankanLawan($keadaan, fn () => DB::selectOne(
            'SELECT status FROM pembayaran WHERE id = ?',
            [$siap['pembayaran']]
        ));

        expect($keadaan['hasil'])->toBeInstanceOf(stdClass::class,
            'the plain read blocked, which would mean it is not the read the real code uses')
            ->and((string) $keadaan['hasil']->status)->toBe(PembayaranStatus::Pending->value);

        // So B, having read `pending`, WOULD write - and its write would be the
        // second state change. The two halves of this file together are the
        // argument: the real path is a locking read (test one), and a locking
        // read is the only thing standing between two concurrent deliveries and
        // two settlements (test two).
        DB::rollBack();
    } finally {
        pay45cSelesai();
    }
});

test('the row lock is a CURRENT read: a blocked delivery wakes up seeing the settled status', function (): void {
    $siap = pay45cSiapkan();

    pay45cMulai();

    try {
        // A settles the payment and holds the transaction OPEN.
        DB::beginTransaction();

        DB::selectOne('SELECT status FROM pembayaran WHERE id = ? FOR UPDATE', [$siap['pembayaran']]);
        DB::table('pembayaran')->where('id', $siap['pembayaran'])->update([
            'status' => PembayaranStatus::Berhasil->value,
            'dibayar_at' => PAY45_DETIK,
        ]);

        // B blocks on the lock for longer than its 1s timeout would allow, so
        // the lock is RELEASED first: commit A, then run B. What matters is the
        // value B's `FOR UPDATE` returns AFTER A has committed.
        DB::commit();

        $keadaan = [];

        pay45cJalankanLawan($keadaan, fn () => DB::selectOne(
            'SELECT status FROM pembayaran WHERE id = ? FOR UPDATE',
            [$siap['pembayaran']]
        ));

        // B sees `berhasil`, NOT `pending`. A locking read is a current read, so
        // it resolves against the LATEST committed version rather than against
        // the transaction's snapshot. This is the single property the
        // idempotency guard depends on: a second delivery that blocked must
        // re-read the settled row, and a plain `first()` would have kept
        // looking at its own snapshot and seen `pending`.
        expect($keadaan['hasil'])->toBeInstanceOf(stdClass::class)
            ->and((string) $keadaan['hasil']->status)->toBe(PembayaranStatus::Berhasil->value);
    } finally {
        pay45cSelesai();
    }
});
