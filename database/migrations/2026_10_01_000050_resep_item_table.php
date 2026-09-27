<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 50 of 75 — `telemedicine_test.sql:767-783`.
 *
 * Fourth table of batch H. **13 columns** (`:768`-`:780`), two foreign keys
 * with **mismatched** `ON DELETE` rules, and **no timestamp column of any
 * kind** — this table is in rule 4's 39-table "neither `dibuat_at` nor
 * `diubah_at`" group, so todo 19's model needs `public $timestamps = false`
 * and `$table->timestamps()` is exactly the wrong call. `resep` is table 49,
 * one row earlier in this same batch, and `master_obat` is 47, so **nothing
 * here is deferred**.
 *
 * The two cascades are deliberately different and must not be harmonised:
 * `resep_id` cascades (`:781`) because a prescription line is meaningless
 * without its prescription and `ON DELETE CASCADE` is the only way a deleted
 * prescription cleans up after itself, while `obat_id` carries **no** `ON
 * DELETE` clause (`:782`) and so materialises MySQL's implicit `NO ACTION`
 * (`RESTRICT` for DML) — deleting a drug must be blocked while prescription
 * lines reference it. Same pattern as batch G: the record link cascades, the
 * catalogue link restricts.
 *
 * **TRAP 3 — `obat_id` IS NULLABLE AND `nama_obat` IS A SNAPSHOT. Together they
 * mean a prescription line is NOT a join to the catalogue.**
 *
 * `obat_id BIGINT UNSIGNED NULL` (`:770`) carries the DDL comment
 * `'NULL = racikan / obat non-katalog'`, and **nullable here is the design**,
 * not an oversight. `is_racikan TINYINT(1) NOT NULL DEFAULT 0` (`:776`) and
 * `racikan_nama VARCHAR(100) NULL` (`:777`) exist precisely to describe a
 * prescribed preparation that has **no** `master_obat` row: a compounded
 * ("racikan") recipe, or a drug dispensed before it was catalogued. The
 * comment is the contract and the column is `NULL` for that case; a
 * `NOT NULL` here would make racikan literally unrepresentable.
 *
 * `nama_obat VARCHAR(255) NOT NULL COMMENT 'Snapshot nama saat diresepkan'`
 * (`:771`) is an **explicit snapshot of the drug's name at prescribing time**,
 * not a redundant copy and not a denormalisation to be cleaned up. It is
 * `NOT NULL` even when `obat_id IS NULL`, which is the whole point: the line
 * must be renderable years later, after the catalogue row has been renamed,
 * deactivated or (had there been no cascade) deleted. Three consequences, and
 * each of them is a defect waiting to happen if the snapshot is ignored:
 *
 * 1. **The Resource (todo 39) MUST return `nama_obat` and MUST NOT substitute a
 *    live join to `master_obat`.** A live join silently rewrites historical
 *    prescriptions when the catalogue is edited, and returns `NULL` for a
 *    racikan line. `obat_id` may be exposed alongside it as an id, never as a
 *    name source.
 * 2. **Todo 38's interaction engine MUST skip rows where `obat_id IS NULL`,
 *    and MUST document that racikan are structurally uncheckable.** There is
 *    nothing to join on, so no amount of care in the engine can produce a
 *    result for those lines — they are not "low risk", they are **unexamined**.
 *    The plan's own schema-reality list says the same about allergy checking,
 *    for the same structural reason: `pasien_alergi.nama_alergen` (`:278`) is
 *    free text and not a foreign key to `master_obat`, so both checks are
 *    best-effort and both fail open on a racikan.
 * 3. **Nothing keeps `nama_obat` and `master_obat.nama_generik` in step**, and
 *    the two are not even the same column: `nama_obat` is free text while the
 *    catalogue has `nama_generik` (`:711`) plus an optional `nama_brand`
 *    (`:712`). Which one was snapshotted is a decision the schema does not
 *    record, so a line's displayed name is whatever the prescriber's client
 *    sent.
 *
 * `jumlah SMALLINT UNSIGNED NOT NULL` (`:774`) is **UNSIGNED** — an unsigned
 * `smallint`, not a signed one and not an `int` — so a negative quantity is
 * impossible, unlike the signed `INT` stock columns on `apotek_stok` (`:833`-
 * `:834`). `satuan VARCHAR(30) NULL` (`:775`) is **free text and nullable**,
 * deliberately NOT the eight-value `master_obat.satuan` ENUM (`:716`) and not
 * constrained to it: a line may be counted in a unit the catalogue has never
 * heard of, which is the same best-effort posture as `nama_obat`. It is also
 * nullable while `jumlah` is not, so a quantity with no unit is representable.
 *
 * `harga_satuan DECIMAL(12,2) NOT NULL DEFAULT 0` (`:778`) and `subtotal
 * DECIMAL(12,2) NOT NULL DEFAULT 0` (`:779`) are the same type and the same
 * default, and **nothing in the schema checks that
 * `subtotal = harga_satuan * jumlah`** — no `CHECK`, no generated column, no
 * trigger. `subtotal` is a stored fact that a caller can write inconsistently,
 * so todo 39's service must compute it and nothing downstream may trust it
 * without recomputing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('resep_item', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('resep_id');

            // TRAP 3a. NULLABLE BY DESIGN, and the DDL comment says why: 'NULL =
            // racikan / obat non-katalog'. A compounded preparation, or a drug
            // dispensed before it was catalogued, has no master_obat row, and
            // is_racikan/racikan_nama below are what describe it. Making this
            // NOT NULL would make racikan unrepresentable.
            //
            // Todo 38's interaction engine MUST skip every row where this is
            // NULL and MUST document that racikan are STRUCTURALLY
            // UNCHECKABLE - there is nothing to join on, so such a line is
            // unexamined, not low-risk. See the class docblock.
            $table->unsignedBigInteger('obat_id')->nullable()->comment('NULL = racikan / obat non-katalog');

            // TRAP 3b. NOT NULL even when obat_id IS NULL - that is what makes it
            // a snapshot rather than a mirror. The Resource (todo 39) MUST
            // return this column and MUST NOT substitute a live join to
            // master_obat: a join silently rewrites historical prescriptions
            // when the catalogue is renamed and returns NULL for a racikan.
            // `obat_id` may be exposed as an id, never as a name source.
            $table->string('nama_obat', 255)->comment('Snapshot nama saat diresepkan');

            $table->string('kekuatan', 50)->nullable();
            $table->string('aturan_pakai', 255);

            // SMALLINT UNSIGNED - unsigned, and a different width from every
            // other integer in this batch. A negative quantity is impossible.
            $table->unsignedSmallInteger('jumlah');

            // Free text and NULLABLE, deliberately NOT the eight-value
            // master_obat.satuan ENUM (:716) and not constrained to it: a line
            // may be counted in a unit the catalogue does not know. Nullable
            // while `jumlah` is NOT NULL, so a quantity with no unit is
            // representable.
            $table->string('satuan', 30)->nullable();

            // TINYINT(1) -> boolean(), signed `tinyint`; the (1) is a display
            // width MySQL 8 no longer emits. DEFAULT 0. Nothing couples this to
            // obat_id being NULL, so is_racikan = 0 with a NULL obat_id and
            // is_racikan = 1 with a valid one are both representable.
            $table->boolean('is_racikan')->default(false);
            $table->string('racikan_nama', 100)->nullable();

            // Both DECIMAL(12,2) NOT NULL DEFAULT 0, and nothing checks that
            // subtotal = harga_satuan * jumlah - no CHECK, no generated column,
            // no trigger. `subtotal` is a stored fact a caller can write
            // inconsistently, so todo 39 must compute it and todo 46 must not
            // trust it without recomputing.
            $table->decimal('harga_satuan', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->text('catatan_apoteker')->nullable();

            // MISMATCHED ON DELETE RULES, deliberately. The record link
            // CASCADES (a line is meaningless without its prescription); the
            // catalogue link carries no ON DELETE clause and so materialises
            // MySQL's implicit NO ACTION (RESTRICT for DML), so deleting a drug
            // is blocked while lines reference it. Harmonising them would be
            // drift AND would silently destroy prescription history.
            $table->foreign('resep_id')->references('id')->on('resep')->cascadeOnDelete();
            $table->foreign('obat_id')->references('id')->on('master_obat');

            // NO $table->timestamps() - this table has neither dibuat_at nor
            // diubah_at (rule 4's 39-table "neither" group), so no column
            // records when a line was added to or removed from a prescription.
            // See the class docblock.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resep_item');
    }
};
