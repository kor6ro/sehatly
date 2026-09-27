<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 55 of 75 — `telemedicine_test.sql:847-858`. First table of batch I.
 *
 * **10 columns** (`:848`-`:857`), **two indexes and ZERO foreign keys** — the only
 * two tables in this batch with no FK at all, and this one is the parent of three
 * others (`lab_paket_item.tindakan_id` `:873`, `lab_permintaan_detail.tindakan_id`
 * `:901`, `lab_hasil.tindakan_id` `:918`), so it is created first in the batch and
 * nothing has to be deferred.
 *
 * `id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` (`:848`) is an ordinary surrogate
 * key, so `unsignedBigInteger(...)->autoIncrement()->primary()` is correct here. It is
 * **not** one of the 18 narrow-primary tables the plan lists, and it **is** one of
 * the tables whose `kode` the SQL's own seed omits from its column list
 * (`:1319`-`:1320` names only `kode, nama, kelompok, satuan, nilai_rujukan_laki,
 * nilai_rujukan_perempuan, harga`), which is how the seed relies on
 * AUTO_INCREMENT.
 *
 * **`kode VARCHAR(20) NOT NULL UNIQUE` (`:849`) is an INLINE `UNIQUE`.** It carries
 * no name in the DDL, so `SchemaDiffer` compares it by SEMANTICS and never by name
 * (rule 10 of `docs/migration-order.md`): MySQL would call the resulting index
 * `kode` and Laravel calls it `master_lab_tindakan_kode_unique`, and both are the
 * same single-column unique constraint. Do **not** "correct" this to a named key
 * such as `uq_kode` to match the style of `apotek_stok`'s `uq_stok` (`:840`) — a
 * name the DDL never wrote is not compared, and renaming nothing is the faithful
 * reproduction. The seed uses `LAB-001` … `LAB-010` (`:1321`-`:1330`), seven
 * characters, comfortably inside the 20.
 *
 * **`kelompok` is a SEVEN-value ENUM and the ORDER IS THE SORT INDEX.** `:851` is
 * `darah, urine, hormon, kimia_darah, serologi, mikrobiologi, lainnya` — reproduced
 * verbatim and in that order, because `TypeNormaliser::type()` builds
 * `enum('a','b','c')` by reading the member list literally and never sorts it, so
 * both a wrong member and a wrong position are reported as `column_type`. Only four
 * of the seven values appear in the SQL's own seed — `darah` twice (`:1321`-`:1322`),
 * `kimia_darah` six times (`:1323`-`:1328`), `urine` once (`:1329`) and `serologi`
 * once (`:1330`) — so `hormon`, `mikrobiologi` and `lainnya` are **reachable but
 * unseeded**, which is not a defect and must not be "fixed" here: there is no
 * `DEFAULT` on this column, so all three are perfectly insertable.
 *
 * **`nilai_rujukan_laki` (`:853`) and `nilai_rujukan_perempuan` (`:854`) are
 * `VARCHAR(100) NULL` and store FREE TEXT, not numbers and not ranges.** There is no
 * `CHECK`, no generated column and no second table: the seed stores `13.0-17.0`
 * (`:1321`), `<200` (`:1324`), `negatif` (`:1330`) and `NULL` (`:1329`, `LAB-009`
 * Urinalisa Lengkap, which has no sex-specific reference interval at all). A
 * `VARCHAR` is therefore the only faithful type, and **any service that wants to
 * compare a result against the reference interval has to parse that string in the
 * application layer** — `is_abnormal` on `lab_hasil` (`:912`) is a stored flag, not
 * a computed one, so nothing in this schema derives it either.
 *
 * **`kode_loinc VARCHAR(20) NULL` (`:855`) has NO foreign key and no index, and it
 * is not even in the seed.** LOINC is an external terminology, not a table in this
 * schema, so a constraint would have nothing to point at; and unlike the three
 * external-identifier columns the plan classifies separately, it is a *string* here.
 * All ten seeded rows leave it NULL, so it is pure optional metadata.
 *
 * **`harga DECIMAL(12,2) NOT NULL DEFAULT 0` (`:856`) — the column is `harga`, not
 * `harga_jual`.** The pharmacy batch deliberately uses `harga_jual` for a
 * *per-pharmacy* price (`apotek_stok.harga_jual` `:835`, `master_obat.harga_jual`
 * `:724`) and the two are different facts that nothing keeps in step. Here it is
 * the catalogue list price of the test, shared by every facility, and the DDL's
 * money column for this table is at `:856`. `DEFAULT 0` means "not priced", which is
 * indistinguishable from genuinely free — the same ambiguity batch H recorded for
 * the five pharmacy money columns.
 *
 * **`status_aktif TINYINT(1) NOT NULL DEFAULT 1` (`:857`) — the default is 1, so an
 * omitted value is ACTIVE.** `boolean()->default(true)`, not `default(false)`. The
 * seed's column list (`:1319`-`:1320`) omits `status_aktif` entirely, so all ten
 * seeded tests are active. This is the shape batch C already used for
 * `master_penjamin.status_aktif` and `pasien_penjamin.status_aktif`; the mirror
 * image of todo 10's `dokter_faskes` blocker, where a docblock claimed `0` while
 * the code and the SQL both said `1`.
 *
 * **No `dibuat_at` and no `diubah_at` at all**, so this table is in rule 4's
 * 39-table "neither" group and `$table->timestamps()` is exactly the wrong call. It
 * would emit `created_at`/`updated_at` and produce a `missing_column` plus an
 * `extra_column` pair. **Todo 19's `MasterLabTindakan` needs `public $timestamps =
 * false`.** No raw `ON UPDATE` `ALTER` is owed either, since there is no
 * `diubah_at` to carry it — `lab_permintaan` (`:887`-`:888`) is the only table in
 * this batch that has both.
 *
 * This table is **module-orphaned** (`docs/migration-order.md` row 55: `Module:
 * ORPHAN`, `Resource: —`, `Controller: —`): migrated and modelled for referential
 * completeness, never exposed. `notifikasi.tipe` does carry a `'lab'` value
 * (`:1041`) and `invoice.referensi_tipe` carries `'lab_permintaan'` (`:940`), so
 * the module is not invisible — but nothing in Modules 1-5 selects from this table.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_lab_tindakan', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:848) - an ordinary
            // surrogate key, and the seed omits `id` from its column list
            // (:1319-:1320), which is what relies on AUTO_INCREMENT.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // VARCHAR(20) NOT NULL UNIQUE, written INLINE in the DDL (:849). No name
            // in the DDL means the verifier compares this by SEMANTICS, not by name
            // (rule 10), so the Laravel-generated name is correct as-is. Do not
            // rename it `uq_kode` to imitate apotek_stok.uq_stok (:840): a name the
            // DDL never wrote is not part of the contract.
            $table->string('kode', 20)->unique();

            $table->string('nama', 200);

            // Seven values, in the DDL's exact order (:851) - ENUM order is the
            // sort index, and TypeNormaliser never sorts the member list, so a
            // transposed pair is column_type drift. `hormon`, `mikrobiologi` and
            // `lainnya` are reachable but unseeded (:1321-:1330 uses only darah,
            // kimia_darah, urine and serologi); that is not a defect and there is no
            // DEFAULT to repair.
            $table->enum('kelompok', [
                'darah',
                'urine',
                'hormon',
                'kimia_darah',
                'serologi',
                'mikrobiologi',
                'lainnya',
            ]);

            // VARCHAR(50) NULL. The seed stores '-' rather than NULL for a test with
            // no unit (:1329), so an application cannot treat '' and '-' as equal.
            $table->string('satuan', 50)->nullable();

            // VARCHAR(100) NULL, and it stores FREE TEXT: the seed holds '13.0-17.0'
            // (:1321), '<200' (:1324), 'negatif' (:1330) and NULL (:1329). No CHECK,
            // no generated column, no companion table. Any range comparison against
            // it is application-layer parsing, and nothing in this schema derives
            // lab_hasil.is_abnormal (:912) from it.
            $table->string('nilai_rujukan_laki', 100)->nullable();

            // Same as above (:854). A sex-specific interval, and it is a DIFFERENT
            // fact from nilai_rujukan_laki - the two are not kept in step and either
            // may be NULL independently (:1329 sets both to NULL).
            $table->string('nilai_rujukan_perempuan', 100)->nullable();

            // VARCHAR(20) NULL with NO foreign key and NO index: LOINC is an
            // external terminology with no table in this schema to reference, and it
            // is not in the seed's column list (:1319-:1320), so all ten seeded rows
            // leave it NULL. Optional metadata only.
            $table->string('kode_loinc', 20)->nullable();

            // DECIMAL(12,2) NOT NULL DEFAULT 0 (:856). The column is `harga`, NOT
            // `harga_jual` - this is the catalogue list price, whereas
            // master_obat.harga_jual (:724) and apotek_stok.harga_jual (:835) are
            // different facts and nothing keeps the three in step. 0 means "not
            // priced" and is indistinguishable from free.
            $table->decimal('harga', 12, 2)->default(0);

            // TINYINT(1) NOT NULL DEFAULT 1 (:857) - the default is 1, so an
            // omitted value is ACTIVE. `default(false)` here would be a
            // column_default drift AND would invert the meaning of every seeded
            // test, all ten of which omit this column (:1319-:1320).
            $table->boolean('status_aktif')->default(true);

            // No `dibuat_at` / `diubah_at` - rule 4's 39-table "neither" group, so
            // todo 19's model needs $timestamps = false and no created-at mapping,
            // and no raw ON UPDATE ALTER is owed. Nothing is deferred: this table
            // has no foreign key at all.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_lab_tindakan');
    }
};
