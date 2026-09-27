<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 56 of 75 — `telemedicine_test.sql:860-866`. Second table of batch I.
 *
 * **5 columns** (`:861`-`:865`), **ONE index (the primary key) and ZERO foreign
 * keys** — the second of the batch's two FK-free tables, and the parent of
 * `lab_paket_item.paket_id` (`:872`) and `lab_permintaan_detail.paket_id` (`:902`).
 * It is created before them, so **nothing in this batch is deferred** and the
 * *Deferred constraints* registry in `docs/schema-notes.md` gains no row.
 *
 * `id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` (`:861`) is an ordinary surrogate
 * key — `unsignedBigInteger(...)->autoIncrement()->primary()`. The seed's column
 * list is only `nama, deskripsi, harga` (`:1332`), so it omits both `id` (relying
 * on AUTO_INCREMENT) and `status_aktif` (relying on `DEFAULT 1`, `:865`).
 *
 * **`nama VARCHAR(200) NOT NULL` (`:862`) has NO UNIQUE — deliberately
 * unreproduced here, because the DDL has none.** This is the sharp contrast with
 * its sibling `master_lab_tindakan.kode` (`:849`), which IS unique, and it is worth
 * stating so a reader does not "harmonise" them: **two packages may carry the same
 * name**, and nothing here stops a duplicate. The three seeded packages
 * (`:1333`-`:1335`) happen to have distinct names — `Medical Check Up Dasar`,
 * `Cek Gula & Kolesterol`, `Fungsi Hati Lengkap` — but that is the seed's
 * discretion, not a constraint. A package is addressed by its `id`, never by its
 * name.
 *
 * **`deskripsi TEXT NULL` (`:863`) — `text()`, never `json()` and never a
 * `VARCHAR`.** The seed stores a plain comma-separated human string,
 * `'Hb, LED, Golongan Darah, Urinalisa'` (`:1333`), which is prose, not structure:
 * there is no `JSON` column anywhere in this batch and converting this one would be
 * `column_type` drift against a read-only contract. It is nullable with no default,
 * so a package may have no description at all — and the seed itself omits nothing
 * here only because all three rows happen to supply one.
 *
 * **`harga DECIMAL(12,2) NOT NULL DEFAULT 0` (`:864`) is the PACKAGE price, and it
 * is a third distinct fact.** Batch H already recorded that
 * `master_obat.harga_jual` (`:724`) and `apotek_stok.harga_jual` (`:835`) are
 * different prices that nothing keeps in step. This is a third one again: the price
 * of the bundle. **Nothing in the schema derives it from its contents** — the
 * bundle's line items live in `lab_paket_item`, which has no price column at all
 * (`:869`-`:870` are the only two columns), so there is no arithmetic in the
 * database that could check the bundle price against the sum of its tests. A
 * package whose contents are edited does not re-price itself, and the seed proves
 * the two are independent: `('Medical Check Up Dasar','Hb, LED, Golongan Darah,
 * Urinalisa',120000.00)` (`:1333`) while `LAB-001` Hemoglobin is `35000.00`
 * (`:1321`) and `LAB-009` Urinalisa is `50000.00` (`:1329`). `DEFAULT 0` again
 * means "not priced" and is indistinguishable from free.
 *
 * **`status_aktif TINYINT(1) NOT NULL DEFAULT 1` (`:865`) — DEFAULT 1, so an
 * omitted value is ACTIVE.** Identical to `master_lab_tindakan.status_aktif`
 * (`:857`) and to the batch-C `master_penjamin.status_aktif`. The seed omits the
 * column (`:1332`), so all three seeded packages are active. `default(false)` would
 * be `column_default` drift and would flip the meaning of every seeded row.
 *
 * **No `dibuat_at` and no `diubah_at`**, so rule 4's 39-table "neither" group and
 * **todo 19's `MasterLabPaket` needs `public $timestamps = false`**.
 * `$table->timestamps()` would emit `created_at`/`updated_at` and produce a
 * `missing_column` plus an `extra_column` pair.
 *
 * This table is **module-orphaned** (`docs/migration-order.md` row 56: `Module:
 * ORPHAN`, `Resource: —`, `Controller: —`): migrated and modelled for referential
 * completeness, never exposed by any module in scope.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_lab_paket', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:861); the seed's column
            // list is only nama, deskripsi, harga (:1332), so it relies on this.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // VARCHAR(200) NOT NULL, and NOT UNIQUE - the DDL declares no unique
            // here. Contrast master_lab_tindakan.kode (:849), which is. Two
            // packages may share a name; a package is addressed by its id. Do not
            // "harmonise" the two tables.
            $table->string('nama', 200);

            // TEXT NULL (:863) - prose, not structure. The seed stores 'Hb, LED,
            // Golongan Darah, Urinalisa' (:1333), a comma-separated human string.
            // `text()` and never `json()`: there is no JSON column in this batch and
            // converting this one would be column_type drift.
            $table->text('deskripsi')->nullable();

            // DECIMAL(12,2) NOT NULL DEFAULT 0 (:864) - the BUNDLE price, a third
            // fact distinct from master_obat.harga_jual (:724) and
            // apotek_stok.harga_jual (:835). Nothing derives it from the bundle's
            // contents: lab_paket_item has no price column at all (:869-:870), so
            // 120000.00 (:1333) is an independent fact, not a sum. 0 means "not
            // priced" and is indistinguishable from free.
            $table->decimal('harga', 12, 2)->default(0);

            // TINYINT(1) NOT NULL DEFAULT 1 (:865) - the default is 1, so an omitted
            // value is ACTIVE. The seed omits the column (:1332), so all three
            // seeded packages are active; `default(false)` would be column_default
            // drift and would invert every one of them.
            $table->boolean('status_aktif')->default(true);

            // No `dibuat_at` / `diubah_at` - rule 4's 39-table "neither" group, so
            // todo 19's model needs $timestamps = false. No raw ON UPDATE ALTER is
            // owed, and nothing is deferred: this table has no foreign key.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_lab_paket');
    }
};
