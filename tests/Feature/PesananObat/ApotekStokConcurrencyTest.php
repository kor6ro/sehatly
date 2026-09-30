<?php

declare(strict_types=1);

use App\Models\ApotekStok;
use App\Models\Pasien;
use App\Models\User;
use App\Services\PesananObat\ApotekStokService;
use App\Services\PesananObat\PesananObatService;
use App\Services\PesananObat\StokTidakCukupException;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/pesanan46-helpers.php';

beforeEach(function (): void {
    // Declared in THIS file, not in `pesanan46-helpers.php`: that file is
    // `require_once`d by three test files, so a hook declared in it is
    // registered for the FIRST one only and the other two run with none. The
    // symptom is 35 errors of "RbacCatalog::ROLES names pasien but `roles`
    // holds no such row" in a full-suite run that passes file by file, because
    // `po46Selesai()` has to delete the RBAC catalogue DURABLY (the
    // `RefreshDatabase` wrapper was committed) and the next file has nothing to
    // re-seed it.
    po46Bersihkan();
    po46KunciJam();
    $this->seed(RbacSeeder::class);
});

afterEach(function (): void {
    po46LepasJam();
});

/*
|--------------------------------------------------------------------------
| Todo 46 - the stock guard, proved with two real transactions
|--------------------------------------------------------------------------
|
| ## What is proved here, and how
|
| `telemedicine_test.sql` cannot prevent oversell, and the proof of THAT is the
| first test in the file. `apotek_stok.jumlah_stok` is `INT NOT NULL DEFAULT 0`
| (`:833`) - **signed**, unlike `resep_item.jumlah` (`SMALLINT UNSIGNED`, `:774`)
| - and there is no `CHECK`, no trigger, no generated column and no stock
| movement ledger anywhere in its ten lines (`:829`-`:841`). So a naive
| `SET jumlah_stok = jumlah_stok - 1` twice drives the row to `-1` and MySQL
| stores it. The plan forbids adding the constraint that would refuse the write,
| so the guard has to be application code, and the question is what it is.
|
| ## The choreography, and why a mocked assertion cannot answer it
|
| ```
| connection "mysql"  (the default, connection A)
|   BEGIN
|   SELECT ... FROM `apotek_stok` WHERE apotek_id = ? AND obat_id = ? FOR UPDATE   <-- A takes the lock
|   *** the QueryExecuted listener fires HERE, synchronously, inside A ***
|         connection "po46_b"  (a second PDO, connection B)
|           BEGIN
|           SET innodb_lock_wait_timeout = 1
|           PesananObatService::buat(...)  -> a DIFFERENT patient, the SAME drug
|              SELECT ... FROM `apotek_stok` ... FOR UPDATE
|                 -> MySQL error 1205, lock wait timeout, because A holds it
|           ROLLBACK
|   UPDATE `apotek_stok` SET jumlah_stok = jumlah_stok - 1 WHERE ... >= 1
|   INSERT INTO pesanan_obat ... ; INSERT INTO invoice ... ; COMMIT
| ```
|
| The listener is a `DB::listen()` callback, which Laravel fires SYNCHRONOUSLY
| on the `QueryExecuted` event right after each statement returns. That is what
| makes the interleaving real rather than simulated: B genuinely runs while A's
| transaction is open and holding the row, on a DIFFERENT connection, and the
| only reason B cannot proceed is that lock.
|
| B runs the REAL, unmodified `PesananObatService`, with
| `config('database.default')` briefly pointed at the second connection so
| `DB::transaction()` and every Eloquent model resolve there. The config is
| restored in a `finally`, before the listener returns, so A continues on its
| own connection.
|
| ## The TWO mechanisms, and which test kills which
|
| 1. A LOCKING READ of the `apotek_stok` row - never an aggregate, because
|    `compileAggregate()` drops the lock, which is the same framework fact
|    `PromoService` refuses to build on. **The lock test kills this one.**
| 2. A CONDITIONAL WRITE that carries the predicate:
|    `UPDATE ... SET jumlah_stok = jumlah_stok - ? WHERE id = ? AND jumlah_stok
|    >= ?`, refusing when `affected() !== 1`. This is what makes a negative value
|    UNREPRESENTABLE IN THE WRITE rather than merely unlikely, and it is
|    independent of the lock. **The control test kills this one**, by running the
|    same race with neither mechanism and watching the row land on `-1`.
|
| `CheckoutTest` covers the third mechanism - the DDL facts - by asserting
| `jumlah_stok` is signed, carries no `CHECK`, and that `uq_stok (apotek_id,
| obat_id)` (`:840`) is the existing unique key that makes the lock addressable
| without a new index.
|
| ## Why this file leaves the `RefreshDatabase` wrapping transaction
|
| `RefreshDatabase` wraps each test in ONE transaction whose rows are invisible
| to any other connection, so two interleaved transactions have to run against
| COMMITTED data. {@see po46Mulai()} commits the wrapper first and
| {@see po46Selesai()} deletes the committed fixtures and re-opens a
| transaction, so `RefreshDatabase`'s teardown still finds an active one - it
| checks `! $pdo->inTransaction()` and, if that held, would set
| `RefreshDatabaseState::$migrated = false` and force a `migrate:fresh` for
| every remaining test in the process.
|
| `po46Selesai()` therefore ROLLS BACK first, deletes afterwards, and re-seeds
| the RBAC catalogue the committed wrapper left behind. A test that committed the
| wrapper and then failed still holds a transaction, and deleting inside it means
| `RefreshDatabase`'s rollback undoes the cleanup - which leaves the committed
| RBAC seed behind and fails the NEXT test's seed with duplicate `roles` rows.
*/

// =====================================================================
// The DDL cannot prevent oversell
// =====================================================================

test('the DDL cannot prevent an oversell: the column is signed, unchecked and unique only on the pair', function (): void {
    // Read the DOCUMENTED facts off the parsed schema rather than off this
    // file's own prose, because a docblock that agrees with itself proves
    // nothing.
    $stok = po46Spec()->table('apotek_stok')->columns['jumlah_stok'];

    expect($stok->unsigned)->toBeFalse()
        ->and($stok->type)->toBe('int')
        ->and($stok->nullable)->toBeFalse()
        ->and(po46Spec()->table('apotek_stok')->checks)->toBe([]);

    // The existing unique key is what makes the lock addressable. Its leftmost
    // column is `apotek_id` and there is no other unique key on the table, so
    // a `SELECT ... FOR UPDATE` on `(apotek_id, obat_id)` names exactly one row
    // and no new index is needed to make it so.
    $unik = array_values(array_filter(
        po46Spec()->table('apotek_stok')->indexes,
        static fn ($index): bool => $index->type === 'UNIQUE',
    ));

    expect($unik)->toHaveCount(1)
        ->and($unik[0]->columns)->toBe(['apotek_id', 'obat_id'])
        ->and($unik[0]->name)->toBe('uq_stok');

    // And the empirical half: the column really does accept a negative value,
    // which is why the guard is application code rather than a hope.
    $apotek = po46Faskes();
    $obat = po46Obat();
    $id = po46Stok($apotek, $obat, 1);

    DB::table('apotek_stok')->where('id', $id)->update(['jumlah_stok' => -1]);

    expect((int) DB::table('apotek_stok')->where('id', $id)->value('jumlah_stok'))->toBe(-1)
        ->and(ApotekStok::query()->findOrFail($id)->jumlah_stok)->toBe(-1);
});

// =====================================================================
// Mechanism 1: the locking read, under real contention
// =====================================================================

test('the decrement is a LOCKING READ of one row plus a CONDITIONAL write, never an aggregate', function (): void {
    // A sequential "stock went down" test cannot show either mechanism, so this
    // asserts the SHAPE of the emitted SQL: the read carries `for update` and
    // the write carries the predicate in its WHERE.
    $sql = [];
    $binding = [];

    DB::listen(function (QueryExecuted $p) use (&$sql, &$binding): void {
        if ($p->connectionName !== PO46_KONEKSI_UTAMA) {
            return;
        }

        $sql[] = mb_strtolower($p->sql);
        $binding[] = $p->sql;
    });

    $dunia = po46Dunia(100, 10);

    test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    $kunci = [];
    $kondisi = [];

    foreach ($sql as $satu) {
        if (str_contains($satu, 'from `apotek_stok`') && str_contains($satu, 'for update')) {
            $kunci[] = $satu;
        }

        if (str_contains($satu, 'update `apotek_stok`')) {
            $kondisi[] = $satu;
        }
    }

    expect($kunci)->toHaveCount(1)
        ->and($kunci[0])->toContain('for update')
        // The aggregate trap: `->count()` with a lock is a SILENT no-op because
        // `compileAggregate()` skips `compileLock()`. Asserted so a future
        // refactor to a count is caught here rather than under contention.
        ->and($kunci[0])->not->toContain('count(')
        ->and($kunci[0])->not->toContain('sum(')
        ->and($kunci[0])->not->toContain('avg(')
        // `limit 1` IS there and IS fine: it is what `first()` emits, so the
        // statement is "read exactly this one row, locked", which is the whole
        // intent. The `PromoService` shape - a `pluck('id')->count()` under a
        // `limit $kuota` - is the one that reads a RANGE, and a range read here
        // would be a different guard.
        ->and($kunci[0])->toContain('limit 1')
        ->and($kondisi)->toHaveCount(1)
        // The PREDICATE is bound; the decrement is INTERPOLATED as an int. That
        // asymmetry is deliberate and is asserted here so a refactor that binds
        // one and not the other is a visible diff: `resep_item.jumlah` is a
        // `SMALLINT UNSIGNED` column and is therefore already an integer, and
        // `(int)`-casting it before it reaches the SQL text makes it impossible
        // for a non-numeric value to get there at all.
        ->and($kondisi[0])->toContain('`jumlah_stok` >= ?')
        ->and($kondisi[0])->toContain('set `jumlah_stok` = jumlah_stok - 10')
        ->and($kondisi[0])->not->toContain('jumlah_stok - ?')
        // The prescription is locked too, so the status and expiry the order is
        // judged against are CURRENT reads rather than a REPEATABLE READ
        // snapshot.
        ->and(collect($sql)->filter(fn (string $s): bool => str_contains($s, 'from `resep`') && str_contains($s, 'for update'))->count())
        ->toBe(1);
});

test('TWO CONNECTIONS, one unit on the shelf: the second checkout collides on the row lock', function (): void {
    // The proof, step by step:
    //
    // 1. A begins a checkout and takes the `apotek_stok` row lock.
    // 2. The listener fires INSIDE A, and B runs the REAL service on a second
    //    connection, for a DIFFERENT patient and prescription and the SAME drug
    //    at the SAME pharmacy. A different prescription is what makes the
    //    `apotek_stok` row the ONLY contended row: two patients racing for one
    //    prescription would contend on `resep` first and prove something else.
    // 3. B cannot get past its own locking read, because A holds the row, so
    //    MySQL raises 1205 after the one second this test sets.
    // 4. A finishes and commits. B retries, gets past the lock, and is refused
    //    by the STOCK check - which is the whole point of the retry: a plain
    //    consistent read would still see the pre-transaction snapshot and answer
    //    "one left", so the refusal also proves the availability decision is a
    //    CURRENT read.
    po46Mulai();

    try {
        $apotek = po46Faskes();
        $obat = po46Obat();

        // EXACTLY ONE unit, so the two checkouts cannot both be right.
        po46Stok($apotek, $obat, 1);

        $akunA = po46AkunPasien('Pasien A Oversell');
        $dokterA = po46Dokter(po46User('Dokter A Oversell', 'dokter')->getKey());
        $resepA = po46Resep($akunA['pasien'], $dokterA, [$obat], 'diverifikasi', ['apotek_id' => $apotek], ['jumlah' => 1]);

        $akunB = po46AkunPasien('Pasien B Oversell');
        $dokterB = po46Dokter(po46User('Dokter B Oversell', 'dokter')->getKey());
        $resepB = po46Resep($akunB['pasien'], $dokterB, [$obat], 'diverifikasi', ['apotek_id' => $apotek], ['jumlah' => 1]);

        $layanan = app(PesananObatService::class);

        $keadaan = ['sudah' => false, 'jalan' => true, 'hasil' => null];

        DB::listen(function (QueryExecuted $peristiwa) use (&$keadaan, $apotek, $obat, $akunB, $resepB, $layanan): void {
            if ($keadaan['sudah'] === true || $keadaan['jalan'] !== true) {
                return;
            }

            if ($peristiwa->connectionName !== PO46_KONEKSI_UTAMA) {
                return;
            }

            $sql = mb_strtolower($peristiwa->sql);

            // `from `apotek_stok`` and not `from `apotek_stok` where ...` - the
            // listener must react to A's LOCKING READ, which is the serialisation
            // point, and to nothing else.
            if (! str_contains($sql, 'from `apotek_stok`') || ! str_contains($sql, 'for update')) {
                return;
            }

            // The same drug at the same pharmacy, or the lock is on a row B
            // never asks for and the whole choreography is theatre.
            if ((int) ($peristiwa->bindings[0] ?? 0) !== $apotek || (int) ($peristiwa->bindings[1] ?? 0) !== $obat) {
                return;
            }

            $keadaan['sudah'] = true;

            try {
                $keadaan['hasil'] = po46DiSisiLawan(static function () use ($layanan, $akunB, $resepB): mixed {
                    $pasienB = Pasien::query()->findOrFail($akunB['pasien']);

                    return $layanan->buat(
                        User::query()->findOrFail($akunB['user']->getKey()),
                        $pasienB,
                        (int) $resepB->getKey(),
                        [],
                    );
                });
            } catch (Throwable $e) {
                $keadaan['hasil'] = $e;
            }
        });

        // ---- connection A ----
        $pasienA = Pasien::query()->findOrFail($akunA['pasien']);
        $pesananA = $layanan->buat($akunA['user'], $pasienA, (int) $resepA->getKey(), []);

        expect($pesananA->exists)->toBeTrue()
            ->and((int) DB::table('apotek_stok')->where('apotek_id', $apotek)->where('obat_id', $obat)->value('jumlah_stok'))
            ->toBe(0);

        // ---- connection B, while A still held the lock ----
        expect($keadaan['sudah'])->toBeTrue('the listener never saw the apotek_stok row lock, so nothing was interleaved')
            ->and($keadaan['hasil'])->toBeInstanceOf(Throwable::class)
            ->and(po46AdalahLockWait($keadaan['hasil']))->toBeTrue(
                'the competitor was NOT blocked by the apotek_stok row lock. Got: '
                .($keadaan['hasil'] instanceof Throwable ? get_class($keadaan['hasil']).' - '.$keadaan['hasil']->getMessage() : 'no exception')
            );

        // B's transaction rolled back, so it wrote neither an order nor an invoice.
        expect(DB::table('pesanan_obat')->where('pasien_id', $akunB['pasien'])->count())->toBe(0)
            ->and(DB::table('invoice')->where('pasien_id', $akunB['pasien'])->count())->toBe(0);

        DB::connection(PO46_KONEKSI_UTAMA)->commit();

        $keadaan['jalan'] = false;

        // ---- connection B, retrying now that A has committed ----
        $ditolak = po46Tangkap(
            static fn () => po46DiSisiLawan(static function () use ($layanan, $akunB, $resepB): mixed {
                $pasienB = Pasien::query()->findOrFail($akunB['pasien']);

                return $layanan->buat(
                    User::query()->findOrFail($akunB['user']->getKey()),
                    $pasienB,
                    (int) $resepB->getKey(),
                    [],
                );
            }),
            StokTidakCukupException::class,
        );

        // The refusal names the drug and BOTH numbers, so a client can tell "ask
        // again tomorrow" from "this pharmacy does not carry it".
        expect($ditolak->errors()['apotek_id'][0])->toContain('tidak mencukupi')
            ->and($ditolak->errors()['apotek_id'][0])->toContain('tersedia 0, diminta 1')
            ->and($ditolak->errors()['apotek_id'][1])->toContain('apotek alternatif');

        // THE HEADLINE: exactly one order, and the shelf is EXACTLY ZERO.
        // Never negative - and this is the number a low-stock report would read.
        $sisa = (int) DB::table('apotek_stok')->where('apotek_id', $apotek)->where('obat_id', $obat)->value('jumlah_stok');

        expect($sisa)->toBe(0)
            ->and(DB::table('pesanan_obat')->whereIn('pasien_id', [$akunA['pasien'], $akunB['pasien']])->count())->toBe(1)
            ->and(DB::table('pesanan_obat')->where('pasien_id', $akunB['pasien'])->count())->toBe(0)
            ->and(DB::table('invoice')->whereIn('pasien_id', [$akunA['pasien'], $akunB['pasien']])->count())->toBe(1);
    } finally {
        po46Selesai();
    }
});

test('five SEQUENTIAL checkouts for one unit: one wins and four are refused, and the shelf is never negative', function (): void {
    // The plan's 1-of-N criterion, run over HTTP. These are SEQUENTIAL, not
    // OS-parallel, so what this measures is the CAPACITY guard rather than
    // contention: once one checkout holds the unit, the rest are refused. The
    // contention itself is what the test above measures with a second
    // connection, and this is its sequential twin - a guard that only worked
    // under contention would be a different, worse defect.
    $apotek = po46Faskes();
    $obat = po46Obat();
    po46Stok($apotek, $obat, 1);

    $sukses = 0;
    $ditolak = 0;

    for ($i = 0; $i < 5; $i++) {
        $akun = po46AkunPasien('Pasien Antrian '.$i);
        $dokter = po46Dokter(po46User('Dokter Antrian '.$i, 'dokter')->getKey());
        $resep = po46Resep($akun['pasien'], $dokter, [$obat], 'diverifikasi', ['apotek_id' => $apotek], ['jumlah' => 1]);

        $response = test()->withToken(po46As($akun['user'])['Authorization'])
            ->postJson('/api/v1/resep/'.$resep->getKey().'/checkout', []);

        if ($response->status() === 201) {
            $sukses++;

            continue;
        }

        $response->assertUnprocessable()
            ->assertJsonPath('errors.apotek_id.0', 'Stok "Amoxicillin" tidak mencukupi di apotek yang dipilih (tersedia 0, diminta 1).');

        $ditolak++;
    }

    $sisa = (int) DB::table('apotek_stok')->where('apotek_id', $apotek)->where('obat_id', $obat)->value('jumlah_stok');

    expect($sukses)->toBe(1)
        ->and($ditolak)->toBe(4)
        ->and($sisa)->toBe(0)
        ->and($sisa)->toBeGreaterThanOrEqual(0)
        ->and(DB::table('pesanan_obat')->count())->toBe(1);
});

// =====================================================================
// Mechanism 2: the conditional write, proved by the control
// =====================================================================

test('CONTROL: with NEITHER mechanism the same two transactions drive the shelf to -1', function (): void {
    // The control for the lock test, and the reason that one is worth reading.
    //
    // Here the choreography is the same and BOTH mechanisms are absent: no
    // locking read, and a plain `SET jumlah_stok = jumlah_stok - 1` with no
    // predicate. If the shipped guard could be removed without this test going
    // red, it would be proving nothing.
    //
    // The order is B-reads, A-decrements-and-commits, B-decrements. Under
    // REPEATABLE READ, B's read is a CONSISTENT read that takes NO locks: it
    // cannot block A, and A's commit cannot block it. So B's snapshot still
    // says one unit is available, and B's UNCONDITIONAL write is applied to the
    // latest committed row - which is now zero - leaving MINUS ONE.
    po46Mulai();

    try {
        $apotek = po46Faskes();
        $obat = po46Obat();
        $stokId = po46Stok($apotek, $obat, 1);

        $akunA = po46AkunPasien('Pasien A Kontrol');
        $akunB = po46AkunPasien('Pasien B Kontrol');

        $resepA = po46Resep($akunA['pasien'], po46Dokter(po46User('Dokter A Kontrol', 'dokter')->getKey()), [$obat], 'diverifikasi', ['apotek_id' => $apotek], ['jumlah' => 1]);
        $resepB = po46Resep($akunB['pasien'], po46Dokter(po46User('Dokter B Kontrol', 'dokter')->getKey()), [$obat], 'diverifikasi', ['apotek_id' => $apotek], ['jumlah' => 1]);

        // B opens its transaction and reads the shelf with a plain consistent
        // read, establishing its snapshot while the unit is still there.
        $terbacaB = po46DiSisiLawan(static function () use ($apotek, $obat): int {
            DB::connection(PO46_KONEKSI_LAWAN)->beginTransaction();

            return (int) ApotekStok::query()
                ->where('apotek_id', $apotek)
                ->where('obat_id', $obat)
                ->value('jumlah_stok');
        });

        expect($terbacaB)->toBe(1);

        // A now takes the unit with an UNGUARDED write, and commits.
        DB::connection(PO46_KONEKSI_UTAMA)->beginTransaction();

        ApotekStok::query()
            ->where('apotek_id', $apotek)
            ->where('obat_id', $obat)
            ->update(['jumlah_stok' => DB::raw('jumlah_stok - 1')]);

        DB::connection(PO46_KONEKSI_UTAMA)->commit();

        // B re-reads its OWN snapshot - a consistent read never sees a commit
        // that happened after the snapshot was taken - so it still concludes
        // there is room.
        $snapshotB = po46DiSisiLawan(static fn (): int => (int) ApotekStok::query()
            ->where('apotek_id', $apotek)
            ->where('obat_id', $obat)
            ->value('jumlah_stok'));

        expect($snapshotB)->toBe(1, 'a consistent read must NOT see A\'s commit; if it does, this control is not testing the race');

        // B's UNGUARDED write. Nothing refuses it, because there is no
        // predicate to refuse with - and a write is applied to the LATEST
        // committed row, so it lands on zero and produces MINUS ONE.
        po46DiSisiLawan(static function () use ($apotek, $obat): void {
            ApotekStok::query()
                ->where('apotek_id', $apotek)
                ->where('obat_id', $obat)
                ->update(['jumlah_stok' => DB::raw('jumlah_stok - 1')]);
        });

        po46DiSisiLawan(static function (): void {
            if (DB::connection(PO46_KONEKSI_LAWAN)->transactionLevel() > 0) {
                DB::connection(PO46_KONEKSI_LAWAN)->commit();
            }
        });

        // TWO units taken off a shelf that held one.
        $sisa = (int) DB::table('apotek_stok')->where('id', $stokId)->value('jumlah_stok');

        expect($sisa)->toBe(-1)
            ->and($sisa)->toBeLessThan(0);

        // Stated as the failure it is: the SCHEMA cannot prevent this, and the
        // ONLY things that do are the two mechanisms the shipped guard is made
        // of. `docs/schema-notes.md` records that this is precisely why
        // `jumlah_stok` is signed and unchecked - the negative value IS the
        // oversell report - so a database-level CHECK would have destroyed the
        // detection as well as the drift.
        expect(DB::table('apotek_stok')->where('jumlah_stok', '<', 0)->count())->toBe(1);
    } finally {
        po46Selesai();
    }
});

test('the guarded write refuses on its OWN, with no lock at all', function (): void {
    // The second mechanism, isolated. `ApotekStokService::kurangi()` is called
    // directly with the lock removed from the service, on a committed fixture,
    // and the conditional `WHERE jumlah_stok >= ?` is what refuses.
    //
    // The order is: A commits zero, then a SINGLE unguarded-by-lock call
    // arrives and must be refused rather than writing -1.
    po46Mulai();

    try {
        $apotek = po46Faskes();
        $obat = po46Obat();
        $stokId = po46Stok($apotek, $obat, 5);

        // Somebody else took all five while this transaction was not running.
        DB::connection(PO46_KONEKSI_UTAMA)->beginTransaction();
        DB::table('apotek_stok')->where('id', $stokId)->update(['jumlah_stok' => 0]);
        DB::connection(PO46_KONEKSI_UTAMA)->commit();

        $ditolak = po46Tangkap(
            static fn () => DB::connection(PO46_KONEKSI_UTAMA)->transaction(
                static fn () => app(ApotekStokService::class)
                    ->kurangi($apotek, $obat, 1, 'Amoxicillin')
            ),
            StokTidakCukupException::class,
        );

        expect($ditolak->errors()['apotek_id'][0])->toContain('tersedia 0, diminta 1')
            // The refusal is a refusal: the transaction rolled back and NOTHING
            // was written, so the shelf is still exactly zero.
            ->and((int) DB::table('apotek_stok')->where('id', $stokId)->value('jumlah_stok'))->toBe(0);
    } finally {
        po46Selesai();
    }
});
