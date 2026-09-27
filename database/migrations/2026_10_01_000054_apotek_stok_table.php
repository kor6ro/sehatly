<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 54 of 75 — `telemedicine_test.sql:829-841`. Last table of batch H.
 *
 * **8 columns** (`:830`-`:837`), two foreign keys, one named unique key, and
 * **this is the ONLY table of the 75 that has `diubah_at` and NO `dibuat_at`**
 * (`:837`) — rule 4's "diubah_at only" group, which has exactly one member.
 * So `$table->timestamps()` is exactly the wrong call (it would emit
 * `created_at`/`updated_at` and produce a `missing_column` plus an
 * `extra_column` pair), and **todo 19's model needs `public $timestamps =
 * false`** with no created-at mapping at all. `diubah_at` still carries `ON
 * UPDATE CURRENT_TIMESTAMP`, so the **database** maintains it on every write and
 * the application does not have to — which is precisely why a model with
 * `$timestamps = false` loses nothing here that it was maintaining elsewhere.
 *
 * **TRAP 5 — `jumlah_stok INT NOT NULL DEFAULT 0` (`:833`) and `stok_minimum
 * INT NOT NULL DEFAULT 0` (`:834`) ARE `INT` — SIGNED, NOT `INT UNSIGNED`. THIS
 * IS DELIBERATE, AND `unsignedInteger()` HERE IS A PARITY BREAK THAT SILENTLY
 * DESTROYS TODO 46's OVERSELL DETECTION.**
 *
 * Every other integer in this batch that could plausibly have been written
 * unsigned *is* unsigned: `resep_item.jumlah` is `SMALLINT UNSIGNED` (`:774`)
 * and `resep.jumlah_iter` is `TINYINT UNSIGNED` (`:757`). These two are not,
 * and the asymmetry is the design. The signedness exists so that **a negative
 * stock quantity is representable**, and that is not an oversight to be tidied
 * up — it is the mechanism:
 *
 * - **Todo 46's oversell detection depends on it.** When a decrement would take
 *   `jumlah_stok` below zero, an `INT UNSIGNED` column in MySQL 8 raises
 *   `ERROR 1690 (22003): BIGINT UNSIGNED value is out of range` — the value is
 *   **rejected**, not clamped and not stored. An oversell then looks like a
 *   driver error instead of like a stock deficit, so the oversell is never
 *   *recorded*, never surfaces in a low-stock report, and never reaches an
 *   audit. With a signed `INT` the decrement succeeds, the row goes negative,
 *   and the deficit is a queryable fact: `WHERE jumlah_stok < 0` is a complete
 *   oversell report.
 * - **There is no `CHECK (jumlah_stok >= 0)`.** The plan's own schema-reality
 *   list records this: stock is mutated in place with no movement ledger, the
 *   columns are signed, and nothing constrains them, so **the service layer is
 *   the guard**. `stok_minimum` is signed for the same reason — it is the
 *   reorder threshold compared against a signed quantity, and a threshold that
 *   must never itself go negative gains nothing from unsignedness.
 * - **A negative value is not an error state the schema can reject, so nothing
 *   rejects it.** That is the point: the column is the audit trail for a
 *   condition the database is forbidden from preventing.
 *
 * If a future reader "fixes" this to `unsignedInteger()` because it looks
 * inconsistent with `resep_item.jumlah`, the migration still builds, still
 * lints, still migrates cleanly and the whole unit suite stays green — **only
 * `sehatly:verify-schema` sees it**, as a `column_unsigned` discrepancy naming
 * `jumlah_stok`. That is why the rule is written here in the migration and
 * recorded in `docs/schema-notes.md`.
 *
 * `UNIQUE KEY uq_stok (apotek_id, obat_id)` (`:840`) is named in the DDL, so it
 * is compared **by name** on `(TABLE_NAME, INDEX_NAME)`, and its column order is
 * part of the contract: `apotek_id` first, `obat_id` second. One stock row per
 * (pharmacy, drug) pair, and the pair is the natural key — which is also why
 * this table needs no `kode` column. **The unique covers only ONE of the two
 * foreign-key columns**: its leftmost column is `apotek_id`, so it does satisfy
 * InnoDB for the `apotek_id` constraint, but `obat_id` is not a leftmost prefix
 * of anything here, so MySQL builds an implicit support index for that
 * constraint and `SHOW CREATE TABLE` prints
 * `KEY apotek_stok_obat_id_foreign (obat_id)`. That index is **not in the DDL**
 * and `SchemaDiffer::diffIndexes()` treats it as implied by the matched foreign
 * key rather than as `extra_index` drift (commit `27c6ca8`) — so the table
 * reaches `Discrepancies: 0` with it present, and it must not be "removed" by
 * adding a covering index of one's own. (The plan's todo-14 prose cites this key
 * at `:845`, which is neither this key nor anything inside
 * `master_lab_tindakan` — `:845` is the section banner that closes `[9] RESEP &
 * FARMASI` and opens `[10] LABORATORIUM`, and that table's money column is
 * `harga DECIMAL(12,2)` at `:856`, not `harga_jual`. The key is at `:840`.)
 *
 * `apotek_id BIGINT UNSIGNED NOT NULL` (`:831`) points at **`faskes(id)`**, and
 * nothing constrains `faskes.tipe` to `'apotek'` (`:365`) — so a hospital row
 * can hold stock, and the application must validate the type. Same unconstrained
 * pharmacy-identity pattern as `resep.apotek_id` (`:749`) and
 * `pesanan_obat.apotek_id` (`:802`).
 *
 * `harga_jual DECIMAL(12,2) NOT NULL DEFAULT 0` (`:835`) is the **per-pharmacy**
 * price, and it is the same type and the same default as the catalogue's
 * `master_obat.harga_jual` (`:724`) while being a **different fact**. Nothing
 * keeps the two in step: a pharmacy may sell above or below the catalogue
 * price, `0` means "not priced" in both, and neither can be told from a
 * genuinely free item. Do not collapse them into one column.
 *
 * `kedaluwarsa DATE NULL` (`:836`) is nullable with **no** default and no
 * trigger, so expiry is a stored date a caller writes or omits. It is the only
 * expiry signal in the schema — there is no batch-expiry model — and **nothing
 * filters expired stock out of a listing**. Worse, the uniqueness key is
 * `(apotek_id, obat_id)` alone, so **a second batch with a different
 * `kedaluwarsa` cannot be represented at all**: one pharmacy has exactly one
 * row per drug, and re-stocking an expired drug must overwrite that row's
 * quantity and date in place. With `jumlah_stok` mutated in place and no
 * movement ledger, the history of a lot is not recoverable from this table.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('apotek_stok', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('apotek_id');

            // FK to `faskes(id)`, not to a pharmacy table, and nothing
            // constrains faskes.tipe to 'apotek' (:365) - a hospital row can
            // hold stock. The application must validate the type.
            $table->unsignedBigInteger('obat_id');

            // TRAP 5. `integer()` - SIGNED INT, and that is DELIBERATE.
            // `unsignedInteger()` here is a parity break AND silently destroys
            // todo 46's oversell detection: MySQL 8 would reject a decrement
            // below zero with ERROR 1690 instead of storing the deficit, so the
            // oversell would never be recorded, never appear in a low-stock
            // report and never reach an audit. With a signed INT the decrement
            // succeeds, the row goes negative, and `WHERE jumlah_stok < 0` is a
            // complete oversell report. There is no `CHECK (jumlah_stok >= 0)`
            // anywhere, by design - the service layer is the guard. DO NOT
            // "harmonise" this with `resep_item.jumlah` (SMALLINT UNSIGNED,
            // :774) or `resep.jumlah_iter` (TINYINT UNSIGNED, :757): both of
            // those are quantities that cannot be negative, and these two are
            // not. See the class docblock.
            $table->integer('jumlah_stok')->default(0);

            // SIGNED for the same reason as jumlah_stok: it is the reorder
            // threshold compared against a signed quantity, and a threshold that
            // must never itself go negative gains nothing from unsignedness.
            $table->integer('stok_minimum')->default(0);

            // The PER-PHARMACY price - a different fact from the catalogue's
            // master_obat.harga_jual (:724), same type, same default, and
            // nothing keeps the two in step. 0 means "not priced" in both and
            // is indistinguishable from free. Do not collapse them.
            $table->decimal('harga_jual', 12, 2)->default(0);

            // DATE NULL, no default, no trigger. The only expiry signal in the
            // schema, and nothing filters expired stock out of a listing. The
            // unique key below is (apotek_id, obat_id) ALONE, so a second batch
            // with a different expiry date is not representable at all:
            // re-stocking overwrites quantity and date in place, and with no
            // movement ledger the lot history is not recoverable from here.
            $table->date('kedaluwarsa')->nullable();

            // `diubah_at` AND NOT `dibuat_at` - the only such table of the 75.
            // $table->timestamps() is exactly the wrong call; todo 19's model
            // needs $timestamps = false and no created-at mapping. The ON UPDATE
            // clause still has to be reproduced, and Laravel 13 has no Blueprint
            // helper for it, hence the raw ALTER below.
            $table->timestamp('diubah_at')->useCurrent();

            // Two foreign keys, neither carrying an ON DELETE clause, so each
            // materialises MySQL's implicit NO ACTION (RESTRICT for DML). Both
            // targets pre-date this batch: faskes is 28, master_obat is 47.
            // Nothing here is deferred.
            $table->foreign('apotek_id')->references('id')->on('faskes');
            $table->foreign('obat_id')->references('id')->on('master_obat');

            // Named in the DDL (:840), so compared BY NAME on
            // (TABLE_NAME, INDEX_NAME), and the column ORDER is part of the
            // contract: apotek_id first, obat_id second. One row per
            // (pharmacy, drug) - which is also why this table has no `kode`
            // column. Because this unique covers both foreign-key columns
            // exactly, MySQL creates NO implicit FK-support index here, so this
            // table's index set is fully explicit. Do not add a second unique
            // for a lot number: a second batch of the same drug is not
            // representable in this schema.
            $table->unique(['apotek_id', 'obat_id'], 'uq_stok');
        });

        DB::statement('ALTER TABLE apotek_stok MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apotek_stok');
    }
};
