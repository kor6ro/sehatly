<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 59 of 75 — `telemedicine_test.sql:894-903`. Fifth table of batch I.
 *
 * **5 columns** (`:895`-`:899`), **ONE index (the primary key) and THREE foreign
 * keys** — the densest table in the batch, since every column except the surrogate
 * `id` and the `prioritas` flag participates in a constraint.
 *
 * ## THE THREE NULLABLE-OR-NOT COLUMNS ARE **NOT** ALTERNATIVE CONSTRAINTS
 *
 * `tindakan_id` (`:897`) and `paket_id` (`:898`) are **both** `BIGINT UNSIGNED
 * NULL`, and both **do** carry a real foreign key (`:901`, `:902`). This is the
 * mirror image of trap 3 in `lab_permintaan`, and the two must not be confused:
 *
 * | Column | Nullable? | Foreign key? | Why |
 * | --- | --- | --- | --- |
 * | `lab_permintaan_id` (`:896`) | **NOT NULL** | **yes, `ON DELETE CASCADE`** (`:900`) | a line always belongs to a request |
 * | `tindakan_id` (`:897`) | **NULL** | **yes, no action** (`:901`) | a line may be one individual test … |
 * | `paket_id` (`:898`) | **NULL** | **yes, no action** (`:902`) | … **or** one whole package |
 *
 * **Nothing enforces that exactly one of them is set, and nothing forbids both.**
 * There is no `CHECK ((tindakan_id IS NULL) <> (paket_id IS NULL))`, no trigger and
 * no default for either. So all three of the following are perfectly valid rows and
 * all three are representable:
 *
 *  1. **Both `tindakan_id` and `paket_id` NULL** — a line naming neither a test
 *     nor a bundle. Whether that is a "panel to be decided later" or plain garbage
 *     is **not** something this schema can say.
 *  2. **Both** set — a line that is simultaneously an individual test and a whole
 *     package. A service reading `tindakan_id` first and `paket_id` second will
 *     silently ignore the package it just found.
 *  3. `prioritas` at `'cito'` (`:899`) for a line that is a *package* rather than a
 *     test, which changes the package's urgency wholesale.
 *
 * **The application layer is the only validator of that invariant, and it must be
 * written before any service depends on this table.** A `CHECK` cannot be added
 * without being `missing_check` drift against a read-only contract.
 *
 * ## THE CASCADE IS ON THE *RECORD* LINK ONLY, AND THAT IS THE POINT
 *
 * `FOREIGN KEY (lab_permintaan_id) REFERENCES lab_permintaan(id) ON DELETE CASCADE`
 * (`:900`) — deleting a request removes its lines, which is the *record* link and is
 * worthless without its parent. The other two carry **no `ON DELETE` clause**
 * (`:901`, `:902`) and therefore materialise MySQL's implicit `NO ACTION`, i.e.
 * `RESTRICT` for DML: a test or a package that is still listed by any line cannot be
 * deleted. Those are the *catalogue* links, and restricting them is what stops a
 * bundle from losing a member that its price was derived from. Writing
 * `cascadeOnDelete()` on `:901` or `:902` would be `foreign_key_action` drift.
 *
 * All three targets pre-date this migration inside this same commit —
 * `lab_permintaan` is table 58, one file earlier, and `master_lab_tindakan` 55 and
 * `master_lab_paket` 56 are two and three — so **nothing is deferred** and this
 * batch adds no row to the *Deferred constraints* registry in
 * `docs/schema-notes.md`.
 *
 * ## `PRIMARY KEY (id)` COVERS **NONE** OF THE THREE FOREIGN KEYS
 *
 * The only declared index is the surrogate primary key on `id` (`:895`), so InnoDB
 * builds an implicit support index for each of `lab_permintaan_id`, `tindakan_id`
 * and `paket_id` and `SHOW CREATE TABLE` prints all three. **None of those three
 * indexes is in the DDL**, and `SchemaDiffer::diffIndexes()` treats a leftover live
 * index whose ordered column list exactly equals a *matched* foreign key's local
 * columns as implied rather than as `extra_index` drift (commit `27c6ca8`). So this
 * table reaches `Discrepancies: 0` with all three present, and they must **not** be
 * "removed" by adding covering indexes of our own — doing so would create a genuine
 * extra index. This is the same situation `apotek_stok` is in, where
 * `uq_stok (apotek_id, obat_id)` (`:840`) covers one of its two foreign-key columns
 * and not the other.
 *
 * ## `prioritas` IS A THREE-VALUE ENUM **ON ONE LINE**, AND ITS DEFAULT IS `'rutin'`
 *
 * `prioritas ENUM('rutin','cepat','cito') NOT NULL DEFAULT 'rutin'` (`:899`) — a
 * single-line declaration, so unlike `lab_permintaan.status` (`:884`-`:885`) it has
 * no wrap hazard. Three values, in that order, because the order is the sort index.
 * **There is no `NULL` member and no fourth value for "no priority"**, so a line
 * cannot opt out of triage: the cheapest possible urgency is `rutin` and it is what
 * an omitted value becomes. `cito` (critical) has no accompanying SLA column, no
 * escalation flag and no index — nothing in the schema acts on it.
 *
 * No `dibuat_at` and no `diubah_at`, so this table is in rule 4's 39-table "neither"
 * group and **todo 19's `LabPermintaanDetail` needs `public $timestamps = false`**.
 * `$table->timestamps()` would emit `created_at`/`updated_at` and produce a
 * `missing_column` plus an `extra_column` pair. It also means **a line has no record
 * of when it was added**, so the request's own `dibuat_at` (`:887`) is the only
 * timestamp anywhere in the laboratory request subtree for a line.
 *
 * This table is **module-orphaned** (`docs/migration-order.md` row 59: `Module:
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
        Schema::create('lab_permintaan_detail', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:895). The only declared
            // index, and it covers none of the three foreign keys below.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:896) - a line always belongs to a request,
            // so this is the one non-nullable foreign-key column. ON DELETE CASCADE
            // (:900): the *record* link, worthless without its parent.
            $table->unsignedBigInteger('lab_permintaan_id');

            // BIGINT UNSIGNED NULL (:897) - nullable AND constrained (:901). A line
            // may name one individual test ...
            $table->unsignedBigInteger('tindakan_id')->nullable();

            // BIGINT UNSIGNED NULL (:898) - nullable AND constrained (:902). ... OR
            // one whole package. Nothing in the schema enforces that EXACTLY ONE of
            // these two is set: there is no CHECK and no trigger, so a line naming
            // both, or neither, is perfectly valid and representable. The
            // application layer is the only validator of that invariant.
            $table->unsignedBigInteger('paket_id')->nullable();

            // Three values in the DDL's exact order (:899), on ONE line - no wrap
            // hazard, unlike lab_permintaan.status (:884-:885). There is no NULL
            // member and no "no priority" value, so an omitted priority becomes
            // 'rutin' and a line cannot opt out of triage. `cito` has no SLA column
            // and no index; nothing in the schema acts on it.
            $table->enum('prioritas', [
                'rutin',
                'cepat',
                'cito',
            ])->default('rutin');

            // Deliberately mismatched, and the mismatch is the design: the RECORD
            // link cascades, the two CATALOGUE links restrict. Writing
            // `cascadeOnDelete()` on tindakan_id or paket_id would be
            // foreign_key_action drift and would let a package lose a member its
            // price was derived from. All three targets pre-date this migration
            // inside the same commit, so nothing is deferred.
            $table->foreign('lab_permintaan_id')->references('id')->on('lab_permintaan')->cascadeOnDelete();
            $table->foreign('tindakan_id')->references('id')->on('master_lab_tindakan');
            $table->foreign('paket_id')->references('id')->on('master_lab_paket');

            // No `dibuat_at` / `diubah_at` - rule 4's 39-table "neither" group, so
            // todo 19's model needs $timestamps = false. A line therefore has no
            // record of when it was added, and lab_permintaan.dibuat_at (:887) is
            // the only timestamp in the whole request subtree.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_permintaan_detail');
    }
};
