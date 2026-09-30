<?php

declare(strict_types=1);

use App\Models\MasterPromo;
use App\Models\PromoRedemption;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\PromoService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/invoice-helpers.php';

/*
|--------------------------------------------------------------------------
| Todo 44 - quota atomicity, proved with two real transactions
|--------------------------------------------------------------------------
|
| ## The defect this file exists to rule out
|
| `promo_redemption` (telemedicine_test.sql:1000-1010) declares **no UNIQUE key
| and no INDEX statement anywhere in its eleven lines**. The only indexes MySQL
| creates on it are the three single-column ones it synthesises for its three
| foreign keys (:1007-:1009), and `InvoicePromoTest` proves that against the live
| table - every non-primary index there has `Non_unique = 1` and there are
| exactly three of them.
|
| So the schema cannot stop two transactions from both counting N redemptions,
| both concluding there is room, and both inserting N+1. The plan forbids adding
| a unique index or a column, and this file is the answer to what is left.
|
| ## THE DECISION: quota consumption is atomic, enforced by a row lock
|
| 1. `SELECT ... FROM master_promo WHERE id = ? FOR UPDATE` is the FIRST
|    statement of the apply transaction. The promo row always exists, so it is a
|    single row every consumer of that promo passes through, and it is the
|    serialisation point.
| 2. The two counting reads are LOCKING reads as well, because the isolation
|    level is REPEATABLE READ and a non-locking read after the lock would still
|    resolve against the transaction's own snapshot. A second transaction that
|    blocked on the promo lock would wake up counting a snapshot from BEFORE the
|    first committed, and would overspend. `SELECT ... FOR UPDATE` reads the
|    LATEST committed version.
|
| ## What is proved, and how
|
| Not "a lock method was called" - a mocked assertion about `lockForUpdate()`
| proves nothing about contention. This file uses **two genuine, interleaved
| MySQL transactions on two separate connections**, the same choreography
| `BookingConcurrencyTest` uses, and it proves BOTH halves:
|
| - **the lock is real**: a second connection running the REAL apply path
|   collides on it, with MySQL error 1205 (lock wait timeout), and writes
|   neither an invoice nor a redemption;
| - **nothing else prevents the overspend**: with the lock removed, the SAME
|   two transactions each read a count of zero and each insert, leaving TWO
|   redemptions against a quota of one.
|
| The second half is what makes the first worth reading, and it came out
| sharper than expected while it was being written. A plain CONSISTENT read
| under REPEATABLE READ takes **no** locks at all, so B's count neither waits for
| A nor blocks A, and once A has committed B's insert is not even refused - it
| is simply the second one. The `master_promo` lock is therefore the only thing
| standing between two redemptions and one quota, which is a much stronger claim
| than "the lock helps" and is the reason it is taken first.
|
| One thing this file could NOT do, recorded because the attempt is instructive:
| releasing B from its INSERT the moment A commits, via a `DB::listen` on B's
| own statement. `QueryExecuted` fires only AFTER a statement RETURNS, and B's
| INSERT is what is blocking, so the listener can never run and the choreography
| deadlocks on itself. The order used instead - B counts, A counts and inserts,
| A commits, B inserts - is the same race with the commit placed where a real
| one would land.
|
| ## Why this file leaves the `RefreshDatabase` wrapping transaction
|
| `RefreshDatabase` wraps each test in ONE transaction whose rows are invisible
| to any other connection, so two interleaved transactions have to run against
| COMMITTED data. {@see inv44Mulai()} commits the wrapper first and
| {@see inv44Selesai()} deletes the committed fixtures and re-opens a
| transaction, so `RefreshDatabase`'s teardown still finds an active one - it
| checks `! $pdo->inTransaction()` and, if that held, would set
| `RefreshDatabaseState::$migrated = false` and force a `migrate:fresh` for every
| remaining test in the process.
|
| `inv44Selesai()` therefore ROLLS BACK first and deletes afterwards. A test that
| committed the wrapper and then failed still holds a transaction, and deleting
| inside it means `RefreshDatabase`'s rollback undoes the cleanup - which leaves
| the committed RBAC seed behind and fails the next 31 tests with duplicate
| `roles` rows. That is exactly what happened, and it is the reason the teardown
| order is written down here.
|
*/

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    inv44KunciJam();
});

afterEach(function (): void {
    inv44LepasJam();
});

/**
 * Run `$aksi` on the second connection and capture whatever it throws rather
 * than letting it escape, so the choreography can assert on the ANSWER.
 *
 * @param  array<string, mixed>  $keadaan
 */
function inv44JalankanLawan(array &$keadaan, callable $aksi): void
{
    try {
        $keadaan['hasil'] = inv44DiSisiLawan($aksi);
    } catch (Throwable $e) {
        $keadaan['hasil'] = $e;
    }
}

/**
 * Fire `$aksi` on the second connection the first time the default connection
 * runs the `master_promo` row lock.
 *
 * `DB::listen()` fires SYNCHRONOUSLY on the `QueryExecuted` event right after
 * each statement returns, which is what makes the interleaving real rather than
 * simulated: B genuinely runs while A's transaction is open and holding the
 * lock, on a different connection, and the only reason B cannot proceed is that
 * lock.
 *
 * @param  array<string, mixed>  $keadaan
 */
function inv44SaatPromoTerkunci(array &$keadaan, callable $aksi): void
{
    DB::listen(function (QueryExecuted $peristiwa) use (&$keadaan, $aksi): void {
        if ($keadaan['sudah'] === true || $keadaan['jalan'] !== true) {
            return;
        }

        if ($peristiwa->connectionName !== INV44_KONEKSI_UTAMA) {
            return;
        }

        $sql = mb_strtolower($peristiwa->sql);

        if (! str_contains($sql, 'from `master_promo`') || ! str_contains($sql, 'for update')) {
            return;
        }

        $keadaan['sudah'] = true;

        inv44JalankanLawan($keadaan, $aksi);
    });
}

test('the apply path locks the master_promo row FIRST, and the counting reads lock too', function (): void {
    [$user, $pasienId] = inv44AkunPasien('Pasien Kunci');
    $promoId = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_total' => 5, 'kuota_per_user' => 5]);
    $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

    $sql = [];

    DB::listen(function (QueryExecuted $p) use (&$sql): void {
        $sql[] = mb_strtolower($p->sql);
    });

    app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode);

    $terkunci = [];

    foreach ($sql as $satu) {
        if (str_contains($satu, 'for update')) {
            $terkunci[] = $satu;
        }
    }

    // EXACTLY three locking reads: the promo row, the total-quota counter and
    // the per-user counter. `kuota_total` is set to 5 here precisely so all
    // three fire - with it NULL only two would, and an assertion of
    // "three or more" would be satisfied by a counter that had stopped
    // running entirely.
    expect(count($terkunci))->toBe(3)
        ->and($terkunci[0])->toContain('from `master_promo`')
        ->and($terkunci[0])->toContain('for update');

    $promoHits = 0;
    $redemptionHits = 0;

    foreach ($terkunci as $satu) {
        if (str_contains($satu, 'from `master_promo`')) {
            $promoHits++;
        }

        if (str_contains($satu, 'from `promo_redemption`')) {
            $redemptionHits++;

            // The count is a `pluck` with a LIMIT, never a bare aggregate: the
            // limit is what bounds the work to O(kuota), and the pluck is what
            // keeps the lock ON - `compileAggregate()` drops it.
            expect($satu)->toContain('limit')
                ->and($satu)->not->toContain('count(');
        }
    }

    expect($promoHits)->toBe(1)
        ->and($redemptionHits)->toBeGreaterThanOrEqual(1);

    // The promo lock is the FIRST locking statement, and it precedes every
    // rule evaluation. That ordering IS the correctness argument.
    $pertamaTerkunci = null;

    foreach ($sql as $satu) {
        if (str_contains($satu, 'for update')) {
            $pertamaTerkunci = $satu;
            break;
        }
    }

    expect($pertamaTerkunci)->toContain('from `master_promo`');
});

test('a second transaction really collides on the promo lock, so the quota cannot be double-spent', function (): void {
    inv44Mulai();

    try {
        // Connection A's fixtures are COMMITTED so connection B can see them.
        $userA = inv44User('Pasien A Kunci');
        $pasienA = inv44Pasien((int) $userA->getKey());
        inv44Booking($pasienA);

        $userB = inv44User('Pasien B Kunci');
        $pasienB = inv44Pasien((int) $userB->getKey());
        inv44Booking($pasienB);

        // A quota of ONE, so the two redemptions cannot both be right.
        $promoId = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_total' => 1, 'kuota_per_user' => 5]);

        $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

        DB::connection(INV44_KONEKSI_UTAMA)->beginTransaction();

        $keadaan = ['sudah' => false, 'jalan' => true, 'hasil' => null];

        // While A is inside its own apply, B runs the REAL apply for a
        // DIFFERENT patient and the same promo. B must collide on the
        // `master_promo` row A holds.
        inv44SaatPromoTerkunci($keadaan, function () use ($pasienB, $kode): void {
            $bookingB = inv44Booking($pasienB);

            inv44DiSisiLawan(function () use ($bookingB, $pasienB, $kode): void {
                app(InvoiceService::class)->buat('booking', $bookingB, $pasienB, [inv44Baris()], null, $kode);
            });
        });

        $layanan = app(InvoiceService::class);

        // A completes its own apply, inside the transaction it opened, so the
        // lock is genuinely held while B ran.
        $bookingA = inv44Booking($pasienA);
        $invoiceA = $layanan->buat('booking', $bookingA, $pasienA, [inv44Baris()], null, $kode);

        expect($keadaan['sudah'])->toBeTrue('the listener never saw the master_promo row lock')
            ->and($keadaan['hasil'])->toBeInstanceOf(Throwable::class)
            ->and($keadaan['hasil'])->toBeInstanceOf(QueryException::class)
            ->and(inv44AdalahLockWait($keadaan['hasil']))->toBeTrue(
                'the second transaction did not collide on the lock: '.get_class($keadaan['hasil']).' - '
                .($keadaan['hasil'] instanceof Throwable ? $keadaan['hasil']->getMessage() : '')
            )
            ->and($invoiceA->diskon)->toBe('5000.00');

        DB::connection(INV44_KONEKSI_UTAMA)->commit();

        $keadaan['jalan'] = false;

        // Exactly ONE redemption exists, and B wrote neither an invoice nor a
        // redemption: its transaction died on the lock, so the quota of one is
        // intact and available for the next caller.
        expect(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(1)
            ->and(DB::table('invoice')->whereIn('pasien_id', [$pasienA, $pasienB])->count())->toBe(1);

        // And the quota really is spent, so the next caller is refused on the
        // quota rather than on a lock.
        $tolak = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienB), $pasienB, [inv44Baris()], null, $kode),
            ValidationException::class
        );

        expect($tolak->errors()['kuota'][0])->toBe('Kuota promo telah habis (1/1).');
    } finally {
        inv44Selesai();
    }
});

test('without the lock the same choreography overspends, which is what makes the lock load-bearing', function (): void {
    // The control for the test above, and the reason that one is worth reading.
    //
    // Here the promo row lock is NOT taken, so both transactions count the same
    // zero and both insert. The end state is TWO redemptions against a quota of
    // one - the overspend. If the previous test could pass with the lock
    // removed, it would be proving nothing.
    inv44Mulai();

    try {
        $userA = inv44User('Pasien A Tanpa Kunci');
        $pasienA = inv44Pasien((int) $userA->getKey());

        $userB = inv44User('Pasien B Tanpa Kunci');
        $pasienB = inv44Pasien((int) $userB->getKey());

        $promoId = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_total' => 1, 'kuota_per_user' => 5]);

        $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

        $promo = MasterPromo::query()->findOrFail($promoId);

        // Each patient gets a REAL invoice, because
        // `promo_redemption.invoice_id` is NOT NULL (:1004) and foreign-keyed to
        // `invoice(id)` (:1009). Written with no promo so the quota starts
        // untouched.
        $layanan = app(InvoiceService::class);

        $invoiceA = $layanan->buat('booking', inv44Booking($pasienA), $pasienA, [inv44Baris('150000.00')]);
        $invoiceB = $layanan->buat('booking', inv44Booking($pasienB), $pasienB, [inv44Baris('150000.00')]);

        expect(DB::table('promo_redemption')->count())->toBe(0);

        // B goes FIRST, and its count is a plain CONSISTENT read - the same
        // query `PromoService::pemakaian()` builds with the `for update` and
        // `limit` removed. This establishes B's REPEATABLE READ snapshot while
        // the redemption table is still empty.
        //
        // A consistent read takes NO locks, which is the fact this test turns
        // on: B's count cannot block A, and A's insert cannot block B's count.
        $snapshotB = inv44DiSisiLawan(function () use ($promoId, $pasienB): array {
            DB::connection(INV44_KONEKSI_LAWAN)->beginTransaction();

            $terpakai = PromoRedemption::query()
                ->where('promo_id', $promoId)
                ->select('id')
                ->orderBy('id')
                ->limit(1)
                ->pluck('id')
                ->count();

            $terpakaiUser = PromoRedemption::query()
                ->where('promo_id', $promoId)
                ->where('pasien_id', $pasienB)
                ->select('id')
                ->orderBy('id')
                ->limit(5)
                ->pluck('id')
                ->count();

            return ['terpakai' => $terpakai, 'terpakaiUser' => $terpakaiUser];
        });

        // A now takes the REAL lock, counts, inserts, and does not commit.
        DB::connection(INV44_KONEKSI_UTAMA)->beginTransaction();

        $serviceA = app(PromoService::class);
        $hitunganA = $serviceA->hitung($promo, '150000.00', '0.00', $pasienA);

        expect($hitunganA->valid)->toBeTrue();

        $serviceA->terapkan($promo, '150000.00', '0.00', $pasienA, (int) $invoiceA->getKey());

        expect(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(1);

        // A commits. B's transaction has been open since its count, so its
        // REPEATABLE READ snapshot still shows the table as it was THEN.
        DB::connection(INV44_KONEKSI_UTAMA)->commit();

        $insertB = inv44DiSisiLawan(function () use ($promoId, $pasienB, $invoiceB): void {
            // B re-reads its own snapshot: still zero, because a consistent
            // read never sees a commit that happened after the snapshot.
            $dibaca = PromoRedemption::query()
                ->where('promo_id', $promoId)
                ->select('id')
                ->orderBy('id')
                ->limit(1)
                ->pluck('id')
                ->count();

            expect($dibaca)->toBe(0);

            $baris = new PromoRedemption;
            $baris->promo_id = $promoId;
            $baris->pasien_id = $pasienB;
            $baris->invoice_id = (int) $invoiceB->getKey();
            $baris->nilai_diskon = '5000.00';
            $baris->save();
        });

        inv44DiSisiLawan(function (): void {
            if (DB::connection(INV44_KONEKSI_LAWAN)->transactionLevel() > 0) {
                DB::connection(INV44_KONEKSI_LAWAN)->commit();
            }
        });

        // B's count was ZERO - taken before A committed, and a snapshot read
        // under REPEATABLE READ never sees a later commit. So B concluded there
        // was room, and NOTHING stopped it: the insert is not even refused, it
        // is simply the second one. Two redemptions, quota of one.
        expect($insertB)->toBeNull()
            ->and($snapshotB['terpakai'])->toBe(0)
            ->and($snapshotB['terpakaiUser'])->toBe(0)
            ->and(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(2);

        // TWO redemptions against `kuota_total = 1`. Stated as the failure it
        // is: the schema cannot prevent it, and the ONLY thing that does is the
        // `master_promo` row lock the previous test proves is real.
        $kuota = (int) DB::table('master_promo')->where('id', $promoId)->value('kuota_total');

        expect($kuota)->toBe(1)
            ->and(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBeGreaterThan($kuota);
    } finally {
        inv44Selesai();
    }
});

test('the per-user limit is a COUNT, not a per-invoice rule, and a second invoice for one patient is refused', function (): void {
    $pasienId = inv44Pasien(inv44User('Pasien Satu')->getKey());
    $pasienLain = inv44Pasien(inv44User('Pasien Dua')->getKey());

    $promoId = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_total' => null, 'kuota_per_user' => 1]);
    $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

    $layanan = app(InvoiceService::class);

    $pertama = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode);
    $kedua = inv44Tangkap(fn () => $layanan->buat('konsultasi', inv44Konsultasi($pasienId), $pasienId, [inv44Baris()], null, $kode), ValidationException::class);

    // The same patient, a DIFFERENT reference type and a different invoice: the
    // limit is per patient across the whole `referensi_tipe` space, because
    // `promo_redemption` has no unique key per (promo, reference) and the
    // counter is `(promo_id, pasien_id)`.
    expect($pertama->diskon)->toBe('5000.00')
        ->and($kedua->errors()['kuota'][0])->toBe('Kuota promo untuk pengguna ini telah habis (1/1).');

    // The other patient is untouched by that refusal.
    $lain = $layanan->buat('booking', inv44Booking($pasienLain), $pasienLain, [inv44Baris()], null, $kode);

    expect($lain->diskon)->toBe('5000.00')
        ->and(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(2);
});

test('a redemption is permanent: the schema offers no way to release a quota', function (): void {
    // Recorded as a test because it is a LIMITATION, and a limitation that only
    // lives in a docblock rots. `promo_redemption` has neither a `dihapus_at`
    // nor a `dibatalkan` column, so a hard delete is the ONLY release and
    // nothing in this codebase performs one - which means a cancelled order
    // whose promo was applied does NOT give the quota back.
    inv44AssertLine(1000, 'CREATE TABLE promo_redemption');

    foreach (['dihapus_at', 'dibatalkan', 'status', 'alasan'] as $tidakAda) {
        expect(DB::select("SHOW COLUMNS FROM promo_redemption LIKE '{$tidakAda}'"))->toBeEmpty();
    }

    expect(DB::select("SHOW COLUMNS FROM promo_redemption WHERE Field = 'dibuat_at'"))->not->toBeEmpty();

    $pasienId = inv44Pasien(inv44User('Pasien Permanen')->getKey());
    $promoId = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_total' => 1, 'kuota_per_user' => 5]);
    $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

    app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode);

    // Cancelling the INVOICE changes nothing about the redemption, because
    // nothing links the two beyond the id and nothing reclaims on either side.
    $invoice = DB::table('invoice')->where('pasien_id', $pasienId)->first();
    DB::table('invoice')->where('id', $invoice->id)->update(['status' => 'dibatalkan']);

    expect(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(1);

    $tolak = inv44Tangkap(
        fn () => app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode),
        ValidationException::class
    );

    expect($tolak->errors()['kuota'][0])->toBe('Kuota promo telah habis (1/1).');
});
