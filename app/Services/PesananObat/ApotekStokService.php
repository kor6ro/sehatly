<?php

declare(strict_types=1);

namespace App\Services\PesananObat;

use App\Models\ApotekStok;
use App\Models\Faskes;
use App\Models\MasterObat;
use App\Support\Uang\Uang;
use Illuminate\Support\Facades\DB;

/**
 * The pharmacy's shelf: what a drug costs there, how many are left, and the
 * ONE guarded write that may take a unit off the shelf.
 *
 * ## `apotek_stok` is SIGNED, and that is the whole reason this class exists
 *
 * `jumlah_stok INT NOT NULL DEFAULT 0` (`telemedicine_test.sql:833`) - **not**
 * `INT UNSIGNED`, unlike `resep_item.jumlah` (`SMALLINT UNSIGNED`, `:774`) and
 * `resep.jumlah_iter` (`TINYINT UNSIGNED`, `:757`), which are quantities that
 * cannot be negative. There is no `CHECK (jumlah_stok >= 0)`, no trigger and no
 * generated column anywhere in its ten lines (`:829`-`:841`), and **no stock
 * movement ledger table among the 75**, so the service layer is the guard and
 * the signedness is the audit trail for the one condition the database is
 * forbidden from preventing.
 *
 * ## THE GUARD, and it lives HERE rather than at a call site
 *
 * {@see kurangi()} is the only writer of `jumlah_stok` in the whole application,
 * and it is TWO independent mechanisms, either of which alone is sufficient and
 * both of which are needed because they fail differently:
 *
 * 1. **A locking read of the row, never an aggregate.**
 *    `SELECT ... FROM apotek_stok WHERE apotek_id = ? AND obat_id = ? FOR
 *    UPDATE`. This is the `BookingService` idiom verbatim and for the same
 *    reason: under `REPEATABLE READ` a plain consistent read sees the
 *    transaction's own snapshot, so two checkouts that both read "one left"
 *    would both conclude there is room. A **locking** read always observes the
 *    LATEST committed version, so the second transaction to arrive waits and
 *    then reads the first one's decrement. An `AVG`/`SUM`/`COUNT` would be a
 *    silent no-op here - `compileAggregate()` skips `compileLock()`, which is
 *    the same framework fact `PromoService` refuses to build on.
 * 2. **A conditional write that carries the predicate.**
 *    `UPDATE apotek_stok SET jumlah_stok = jumlah_stok - ? WHERE id = ? AND
 *    jumlah_stok >= ?`, and a refusal when `affected() !== 1`. This is the
 *    mechanism that makes a negative value *unrepresentable in the write
 *    itself*: even if the lock were somehow absent, the row cannot be driven
 *    below zero, because the statement matches no row and nothing is written.
 *
 * ## How this prevents oversell with NO unique index and NO new column
 *
 * The question deserves a direct answer, because "add a constraint" is the
 * reflex and it is forbidden here (and would be wrong anyway):
 *
 * - **Oversell is not a duplicate-row problem.** A unique index forbids two
 *   rows with the same key; oversell is a *read-modify-write* race on ONE row.
 *   There is no index shape that can express "this row must retain at least N
 *   units", because that is a property of a COLUMN'S VALUE AT WRITE TIME, not
 *   of a key's uniqueness. A `CHECK` constraint would say it and MySQL would
 *   reject the write - but a `CHECK` is a constraint, and adding one is the
 *   same class of change the plan forbids.
 * - **The serialisation point already exists and is already unique.**
 *   `UNIQUE KEY uq_stok (apotek_id, obat_id)` (`:840`) means there is exactly
 *   ONE row per (pharmacy, drug) pair. That existing key is what makes the
 *   pessimistic lock addressable: every competing checkout of the same drug at
 *   the same pharmacy passes through the same row, and `lockForUpdate()` on it
 *   serialises them. **No new index is needed, and none is added.**
 * - **What a new index could not do anyway.** Even a hypothetical
 *   `UNIQUE (apotek_id, obat_id, jumlah_stok)` would forbid two pharmacies from
 *   holding the same count rather than forbid overselling, and the second is the
 *   one that matters.
 *
 * So: the existing `uq_stok` supplies the single addressable row, the locking
 * read supplies the serialisation, and the conditional write supplies the
 * invariant. `ApotekStokConcurrencyTest` proves the first two with two genuine
 * interleaved transactions on two connections, and proves the third by running
 * the same choreography with the guard removed and watching the row go to `-1`.
 *
 * ## The decrement happens ONCE, at reservation
 *
 * `PesananObatService::buat()` calls {@see kurangi()} inside the checkout
 * transaction, so the unit is reserved when the order is placed. It is NOT
 * called again at payment: a second call at settlement would take the unit off
 * the shelf twice, and nothing in the schema could detect that. A later payment
 * todo advances `pesanan_obat.status` and does not touch stock.
 *
 * The cost, stated rather than hidden: there is **no stock movement ledger**,
 * so a cancelled or unpaid order does not return its unit to the shelf, and
 * every change to `jumlah_stok` is unauditable beyond `audit_log`. Both are
 * recorded in `docs/schema-notes.md` (the `apotek_stok.jumlah_stok` entry and
 * the "no line items" entry), which is where the plan requires the limitation
 * to live.
 */
final class ApotekStokService
{
    /**
     * `faskes.tipe` member that names a pharmacy (`:365`).
     *
     * Nothing in the schema constrains `resep.apotek_id` (`:749`),
     * `apotek_stok.apotek_id` (`:831`) or `pesanan_obat.apotek_id` (`:802`) to
     * this value - all three are bare `faskes(id)` references - so a hospital
     * is representable as the dispensing pharmacy and the application has to
     * refuse it.
     */
    public const TIPE_APOTEK = 'apotek';

    /**
     * One pharmacy's row for one drug, or `null`.
     *
     * A NON-locking read: this is the answer for a shelf the caller is only
     * LOOKING at, and taking a row lock on a read that may block a concurrent
     * checkout would be a cost with no benefit. The authoritative answer is
     * re-taken under the lock by {@see kurangi()} at the moment of use.
     */
    public function stok(int $apotekId, int $obatId): ?ApotekStok
    {
        return ApotekStok::query()
            ->where('apotek_id', $apotekId)
            ->where('obat_id', $obatId)
            ->first();
    }

    /**
     * The pharmacy, or the 422 that says why not.
     *
     * Existence and type are TWO separate failures with two different fixes, so
     * they are two messages on one field rather than one vague refusal: a
     * caller who typed a wrong id and a caller who typed a hospital both need to
     * know which.
     *
     * @throws StokTidakCukupException
     */
    public function apotek(int $apotekId): Faskes
    {
        $apotek = Faskes::query()->whereKey($apotekId)->first();

        if ($apotek === null) {
            throw StokTidakCukupException::apotekTidakAda($apotekId);
        }

        if ((string) $apotek->tipe !== self::TIPE_APOTEK) {
            throw StokTidakCukupException::bukanApotek((string) $apotek->tipe);
        }

        if (! $apotek->status_aktif) {
            throw StokTidakCukupException::apotekTidakAktif();
        }

        return $apotek;
    }

    /**
     * Every OTHER pharmacy that can supply `$obatId` in at least `$jumlah`
     * units, most plentiful first.
     *
     * The exclusion of `$apotekId` is deliberate: the caller already has that
     * pharmacy's own answer from {@see stok()}, and repeating it in a list
     * called "alternatives" is a list that answers no question. A pharmacy with
     * no row at all in `apotek_stok` is absent rather than zero - it does not
     * carry the drug, which is a different fact from carrying none of it.
     *
     * `qd_stok` is absent; the filter is on the two foreign-key columns, whose
     * only index MySQL synthesises is the unique `uq_stok (apotek_id, obat_id)`
     * (`:840`) with `apotek_id` as the LEADING column. So this is an index
     * range scan over `apotek_id = ?`, not a table scan, and the `jumlah_stok`
     * predicate is a filter on the rows that range returns.
     *
     * @return list<array{apotek_id: int, nama: string, jumlah_stok: int, harga_jual: string}>
     */
    public function alternatif(int $obatId, int $jumlah, ?int $kecualiApotekId = null): array
    {
        $baris = ApotekStok::query()
            ->where('obat_id', $obatId)
            ->where('jumlah_stok', '>=', $jumlah)
            ->where('apotek_id', '<>', $kecualiApotekId ?? 0)
            ->join('faskes', 'faskes.id', '=', 'apotek_stok.apotek_id')
            ->where('faskes.tipe', self::TIPE_APOTEK)
            ->where('faskes.status_aktif', 1)
            ->orderByDesc('apotek_stok.jumlah_stok')
            ->orderBy('apotek_stok.apotek_id')
            ->get([
                'apotek_stok.apotek_id',
                'apotek_stok.jumlah_stok',
                'apotek_stok.harga_jual',
                'faskes.nama',
            ]);

        return $baris->map(static fn ($row): array => [
            'apotek_id' => (int) $row->apotek_id,
            'nama' => (string) $row->nama,
            'jumlah_stok' => (int) $row->jumlah_stok,
            // A money STRING at the API boundary, for the same reason every
            // other amount in this application is one: a `float` has already
            // lost precision by the time PHP's parser hands it over.
            // `Uang::normal()` rather than `number_format((float) ...)`, so the
            // value never becomes a float even for an instant.
            'harga_jual' => Uang::normal((string) $row->harga_jual),
        ])->all();
    }

    /**
     * Take `$jumlah` units of `$obatId` off `$apotekId`'s shelf, or refuse.
     *
     * MUST be called inside a transaction. See the class docblock for why the
     * two mechanisms are both here and why no unique index is involved.
     *
     * @param  int  $jumlah  how many units; `resep_item.jumlah` is
     *                       `SMALLINT UNSIGNED` (`:774`) so this is 1-65535
     *
     * @throws StokTidakCukupException  when the pharmacy has no row for the
     *                                   drug, or fewer than `$jumlah` units
     */
    public function kurangi(int $apotekId, int $obatId, int $jumlah, string $namaObat): void
    {
        // 1. THE LOCKING READ. Never an aggregate - see the class docblock.
        //    Also the first thing this method does, so the window between
        //    "read the count" and "write the count" is closed by construction
        //    rather than by convention at a call site.
        $baris = ApotekStok::query()
            ->where('apotek_id', $apotekId)
            ->where('obat_id', $obatId)
            ->lockForUpdate()
            ->first();

        if ($baris === null) {
            throw StokTidakCukupException::tidakDicatat($apotekId, $obatId, $namaObat, 0, $jumlah);
        }

        $tersedia = (int) $baris->jumlah_stok;

        // A read-time refusal is the FRIENDLY half: the message can name the
        // drug and say how many are left. The write-time refusal below is the
        // one that matters for correctness, because it is the only one that can
        // fire when another transaction committed in between.
        if ($tersedia < $jumlah) {
            throw StokTidakCukupException::tidakCukup($apotekId, $obatId, $namaObat, $tersedia, $jumlah);
        }

        // 2. THE CONDITIONAL WRITE. `(int)` on both operands before the
        //    interpolation: `jumlah` comes from a `SMALLINT UNSIGNED` column and
        //    is therefore an integer already, and this makes it impossible for
        //    a non-numeric value to reach the SQL text at all.
        $terkunci = ApotekStok::query()
            ->whereKey((int) $baris->getKey())
            ->where('jumlah_stok', '>=', $jumlah)
            ->update([
                'jumlah_stok' => DB::raw('jumlah_stok - '.(int) $jumlah),
            ]);

        if ($terkunci !== 1) {
            // Unreachable while the lock above is held, and that is the point:
            // this branch is the SECOND mechanism, and it is the one that makes
            // a negative value unrepresentable rather than merely unlikely. If a
            // future refactor drops the lock, this still refuses instead of
            // writing - it just refuses a moment later than it should.
            throw StokTidakCukupException::tidakCukup($apotekId, $obatId, $namaObat, $tersedia, $jumlah);
        }
    }

    /**
     * The catalogue row a stock row points at, or `null`.
     *
     * Exposed so {@see PesananObatService} can name the drug in its refusal
     * messages from ONE place - the stored snapshot on `resep_item.nama_obat`
     * (`:771`) is what the PATIENT saw, and a live join is what the pharmacy
     * calls it today. The snapshot is the patient-facing truth, so the service
     * uses it; this is here for the case where a test or a caller needs the
     * live row and the join is the point.
     */
    public function obat(int $obatId): ?MasterObat
    {
        return MasterObat::query()->whereKey($obatId)->first();
    }
}
