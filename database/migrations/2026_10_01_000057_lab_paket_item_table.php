<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 57 of 75 — `telemedicine_test.sql:868-874`. Third table of batch I.
 *
 * ## TRAP 2 — THIS TABLE HAS **NO `id` COLUMN AT ALL**. IT IS A COMPOSITE-PRIMARY-KEY JOIN TABLE.
 *
 * The DDL is four declarations long and **all four** of them are visible in
 * `:869`-`:873`:
 *
 * ```
 *   paket_id BIGINT UNSIGNED NOT NULL,
 *   tindakan_id BIGINT UNSIGNED NOT NULL,
 *   PRIMARY KEY (paket_id, tindakan_id),
 *   FOREIGN KEY (paket_id) REFERENCES master_lab_paket(id) ON DELETE CASCADE,
 * ```
 *
 * **2 columns, 1 index, 2 foreign keys, 0 checks.** There is no `id`, no surrogate
 * key, no `created_at`/`updated_at`, no `harga`, no `urutan`, no `qty` and no
 * `status_aktif` — a package's line items are exactly which test is in which
 * bundle, and nothing else.
 *
 * **DO NOT ADD AN `id`.** This is one of exactly four composite-PK join tables in
 * the contract — `role_permissions` (15), `user_roles` (16), `dokter_faskes` (33)
 * and this one — and rule 3 of `docs/migration-order.md` names all four. Reaching
 * for `$table->id()` here would be wrong twice over: it emits `BIGINT UNSIGNED`
 * *and* creates a column the DDL does not have, so the table would report
 * `extra_column` drift forever. `$table->unsignedBigInteger('id')` would be no
 * better. If a future todo needs a row address, the answer is the pair
 * `(paket_id, tindakan_id)`, not a new surrogate column.
 *
 * **`$table->primary(['paket_id', 'tindakan_id'])` (`:871`) reproduces the DDL's own
 * COLUMN ORDER, and that order is not cosmetic.** The leftmost column is
 * `paket_id`, so the primary key *is* InnoDB's support index for the `paket_id`
 * foreign key and MySQL creates no second index for it. `tindakan_id` is **not** a
 * leftmost prefix of anything here, so InnoDB builds an implicit support index for
 * that constraint and `SHOW CREATE TABLE` prints `KEY tindakan_id (tindakan_id)`.
 * That index is **not in the DDL**, and `SchemaDiffer::diffIndexes()` treats a
 * leftover live index whose ordered column list exactly equals a *matched* foreign
 * key's local columns as implied rather than as `extra_index` drift (commit
 * `27c6ca8`). So this table reaches `Discrepancies: 0` with that index present, and
 * it must **not** be "removed" by adding a covering index of one's own — doing so
 * would create a real extra index instead.
 *
 * **The two cascades are DELIBERATELY MISMATCHED, and the mismatch is the point.**
 * `paket_id` is `ON DELETE CASCADE` (`:872`) while `tindakan_id` carries **no
 * `ON DELETE` clause at all** (`:873`) and therefore materialises MySQL's implicit
 * `NO ACTION` — which is `RESTRICT` for DML. The reading is the same one batches F,
 * G and H already established: **the *bundle* link cascades and the *catalogue* link
 * restricts.** Deleting a package must remove its lines, or the bundle would keep
 * referring to nothing; but deleting a test must be **blocked** while any bundle
 * still lists it, because a bundle whose price was computed from that test would
 * silently change composition. Reproduce both exactly: `cascadeOnDelete()` on the
 * first and nothing on the second.
 *
 * **Both parent tables pre-date this migration inside the same commit**
 * (`master_lab_tindakan` 55 and `master_lab_paket` 56, both two files earlier), so
 * **nothing here is deferred** and this batch adds no row to the *Deferred
 * constraints* registry in `docs/schema-notes.md`.
 *
 * ## NO SEED DATA EXISTS FOR THIS TABLE, AND THAT IS NOT A DEFECT
 *
 * `telemedicine_test.sql` section `[16]` contains **no `INSERT INTO
 * lab_paket_item` anywhere in the file** — verified by enumerating every
 * `INSERT INTO <table>` target in the whole file, which yields exactly 15 targets
 * and includes `master_lab_tindakan` (`:1319`) and `master_lab_paket` (`:1332`) but
 * not this one. The table is therefore created and left **empty**, and the three
 * seeded packages (`:1333`-`:1335`) have **no** rows in it.
 *
 * The consequence is real and is recorded in `docs/schema-notes.md`: because
 * `lab_paket_item` is empty, **no seeded package has any contents**, so a bundle
 * price (`:864`) has no lines to be checked against and every bundle reads as an
 * opaque, unexpandable price. **Do not "fix" this in a migration** — a migration
 * that inserted bridge rows would be inventing data the SQL does not contain and
 * would break the 1:1 fidelity claim. Todo 18 owns this: it authors a
 * `DevFixtureSeeder`, explicitly labelled as new data with no source in the SQL and
 * held outside that claim.
 *
 * No `dibuat_at` / `diubah_at`, so rule 4's 39-table "neither" group.
 * **Todo 19's `LabPaketItem` needs `public $incrementing = false`, a two-element
 * `protected $primaryKey = ['paket_id', 'tindakan_id']`, and `public $timestamps =
 * false`** — the same triple `DokterFaskes` needs after migration 33.
 *
 * This table is **module-orphaned** (`docs/migration-order.md` row 57: `Module:
 * ORPHAN`, `Resource: —`, `Controller: —`): migrated and modelled for referential
 * completeness, never exposed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // NO `id` COLUMN. This is a composite-PK join table - one of exactly four in
        // the contract (role_permissions 15, user_roles 16, dokter_faskes 33,
        // lab_paket_item 57). `$table->id()` would emit BIGINT UNSIGNED *and* create
        // a column the DDL does not have. See the class docblock; do not add one.
        Schema::create('lab_paket_item', function (Blueprint $table) {
            // BIGINT UNSIGNED NOT NULL (:869). NOT NULL is written in the DDL
            // explicitly, and it is also implied by the primary key below, so it is
            // declared rather than left to the implication.
            $table->unsignedBigInteger('paket_id');

            $table->unsignedBigInteger('tindakan_id');

            // PRIMARY KEY (paket_id, tindakan_id) (:871) - the DDL's own order, and
            // it is load-bearing rather than cosmetic: paket_id is the leftmost
            // prefix, so the PK doubles as InnoDB's support index for that FK, while
            // InnoDB builds an implicit `KEY tindakan_id` for the other one. That
            // implicit index is absent from the DDL and is treated as implied by the
            // matched foreign key (commit 27c6ca8), so it must NOT be suppressed by
            // adding a covering index of our own.
            $table->primary(['paket_id', 'tindakan_id']);

            // Two foreign keys, DELIBERATELY MISMATCHED - the same bundle-cascades /
            // catalogue-restricts reading batches F, G and H established.
            //
            // ON DELETE CASCADE (:872): deleting a package removes its lines.
            $table->foreign('paket_id')->references('id')->on('master_lab_paket')->cascadeOnDelete();

            // NO `ON DELETE` clause in the DDL (:873), so this materialises MySQL's
            // implicit NO ACTION (RESTRICT for DML): deleting a test is BLOCKED
            // while any package still lists it. Writing `cascadeOnDelete()` here
            // would be a foreign_key_action drift, and it would let a bundle lose a
            // line that its price was derived from.
            $table->foreign('tindakan_id')->references('id')->on('master_lab_tindakan');

            // No `dibuat_at` / `diubah_at` - rule 4's 39-table "neither" group. Todo
            // 19 needs $incrementing = false, a two-element $primaryKey and
            // $timestamps = false.
            //
            // NO SEED ROWS: the SQL contains no `INSERT INTO lab_paket_item`
            // anywhere, so this table is created empty. That is correct and is not to
            // be repaired here - todo 18's DevFixtureSeeder owns it, outside the 1:1
            // fidelity claim. Nothing is deferred; both parents are earlier in this
            // same batch.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_paket_item');
    }
};
