<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 47 of 75 — `telemedicine_test.sql:708-729`.
 *
 * First table of batch H, and the only one in the whole 75 with **three** ENUM
 * columns. **18 columns** (`:709`-`:727`), one inline `UNIQUE` and one named
 * index, and **no foreign keys at all** — `master_obat` is a pure leaf
 * reference table, which is why nothing in this batch is deferred.
 *
 * **THE THREE ENUMs ARE NOT CONTIGUOUS, and reading them as one block is the
 * single easiest way to get this table wrong.** In declaration order they are
 * `bentuk_sediaan` (`:713-714`), `satuan` (`:716`) and `kelas_obat` (`:719`),
 * and **`kelas_terapi` (`:718`) sits between the second and the third** — a
 * plain nullable `VARCHAR(100)` that is a real column, not a continuation of
 * either list. The plan's todo-14 prose claimed all three ENUMs sat in
 * `:711-717`; `kelas_obat` is at `:719`, and an executor who trusted that
 * range would have shipped `missing_column` drift here. Read `:711` through
 * `:720`. The three lists are also semantically unrelated: `bentuk_sediaan` is
 * the **dosage form** (how it is made), `satuan` is the **dispensing unit**
 * (what the patient counts), and `kelas_obat` is the **legal control class**.
 * A capsule is `bentuk_sediaan = 'kapsul'` sold in a `satuan = 'strip'` and
 * being `kelas_obat = 'keras'`, and confusing the three would corrupt a
 * catalogue rather than merely mislabel a row.
 *
 * **The `kelas_obat` value list is reproduced verbatim from `:719` and must
 * stay that way.** The fifth member is the Indonesian word for narcotics; it
 * was CJK mojibake in an earlier draft of the plan (plan appendix A.17) and
 * hand-typing it is exactly how the corruption happened. The migration author
 * copied the six values from the SQL rather than from the plan, and the
 * parity verifier compares the whole canonical type string
 * `enum('bebas','bebas_terbatas','keras','fitofarmaka','narkotika','psikotropika')`
 * as a **sequence**, so both a wrong letter and a wrong position are reported
 * as `column_type`. All three lists have **no `DEFAULT`**, so all three are
 * required at insert.
 *
 * `requires_resep TINYINT(1) NOT NULL DEFAULT 1` (`:720`) and `status_aktif
 * TINYINT(1) NOT NULL DEFAULT 1` (`:725`) are both **`tinyint`, signed** — the
 * `(1)` is MySQL's display width and is not a type, so the verifier folds both
 * to `tinyint` and compares signedness separately. Both default to **1**, not
 * to 0, so an omitted column means "needs a prescription" and "is on sale"
 * respectively. `harga_jual DECIMAL(12,2) NOT NULL DEFAULT 0` (`:724`) is the
 * **unit** price; the per-apotek price is a different column on a different
 * table (`apotek_stok.harga_jual`, `:835`), which is the same type and the
 * same default but a separate fact. A zero default on both money columns means
 * "not priced", never "free" — nothing in the schema distinguishes them.
 *
 * `kode_obat VARCHAR(30) NOT NULL UNIQUE` (`:710`) writes the `UNIQUE` inline,
 * so importing the SQL directly makes MySQL name the index `kode_obat` while
 * Laravel's `->unique()` yields `master_obat_kode_obat_unique`. Rule 10
 * compares an inline `UNIQUE` by semantics, so `->unique()` is the correct
 * call and the two spellings are the same constraint.
 *
 * `INDEX idx_obat_nama (nama_generik)` (`:728`) is the one name-bearing key
 * besides the primary key and the inline `UNIQUE`, so it is compared **by
 * name** on `(TABLE_NAME, INDEX_NAME)`, and it indexes `nama_generik` alone —
 * **not** `nama_brand`, and not a prefix of it. It is the catalogue search
 * path (todo 38's `GET /api/v1/obat?search=`), and a brand-name search is a
 * full scan because `nama_brand` (`:712`) is unindexed. The plan's todo-14
 * prose cites this index at `:727`, which is `diubah_at`; the line-index table
 * in the same plan says `:728`, which is right. Nothing in the DDL covers
 * `nama_brand`, so do not "improve" this into a composite.
 *
 * `dibuat_at` and `diubah_at` are both present (`:726`-`:727`) and `diubah_at`
 * carries `ON UPDATE CURRENT_TIMESTAMP`, so this table is one of only 16 with a
 * `dibuat_at`/`diubah_at` pair and is one of the few that needs the raw
 * `ALTER` from `docs/migration-order.md` rule 5 — Laravel 13 has no Blueprint
 * helper for `ON UPDATE`. The pair is declared with the explicit
 * `timestamp(...)->useCurrent()` calls that batches B-G all use, **not**
 * `$table->timestamps()`: the DDL names the columns `dibuat_at`/`diubah_at`
 * and `timestamps()` would emit `created_at`/`updated_at`, which is a
 * `missing_column` plus an `extra_column` pair. There is **no** `dihapus_at`
 * here — only `users` (`:148`) and `pasien` (`:249`) get soft deletes — so a
 * drug is retired by flipping `status_aktif` to 0, and a deleted `master_obat`
 * row is blocked outright by the four `ON DELETE CASCADE` foreign keys that
 * point at it from `obat_interaksi` (`:737`-`:738`), `resep_item.obat_id`
 * (`:782`) and `apotek_stok.obat_id` (`:839`).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_obat', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // Inline UNIQUE in the DDL (:710) with no key name, so MySQL names
            // the index `kode_obat` and Laravel would name it
            // `master_obat_kode_obat_unique`. Rule 10 compares an inline UNIQUE
            // by semantics, so ->unique() is correct and the spellings agree.
            $table->string('kode_obat', 30)->unique();
            $table->string('nama_generik', 255);
            $table->string('nama_brand', 255)->nullable();

            // TWELVE values, in the DDL's exact order, spanning TWO lines
            // (:713-:714). The dosage FORM - how the drug is made. NO default,
            // so it is required at insert. `lainnya` is last and is a real
            // member, not a filler: the table has no other way to record a form
            // it does not enumerate.
            $table->enum('bentuk_sediaan', [
                'tablet',
                'kaplet',
                'kapsul',
                'sirup',
                'salep',
                'krim',
                'gel',
                'tetes',
                'injeksi',
                'inhaler',
                'suppositoria',
                'lainnya',
            ]);

            // DDL comment copied verbatim (rule 12). An example dose, free text.
            $table->string('kekuatan', 50)->nullable()->comment('500 mg');

            // EIGHT values, in the DDL's exact order (:716) - the dispensing
            // UNIT the patient counts, NOT the dosage form above. NO default.
            // Note the overlap: `tablet` and `kapsul` are members of BOTH this
            // list and `bentuk_sediaan`, which is precisely why the two
            // columns must not be conflated. `botol`/`tube`/`ampul`/`sachet`/
            // `strip`/`box` describe packaging that has no form equivalent.
            $table->enum('satuan', ['tablet', 'kapsul', 'botol', 'tube', 'ampul', 'sachet', 'strip', 'box']);

            $table->string('pabrikan', 150)->nullable();

            // THE COLUMN THAT SITS BETWEEN THE SECOND AND THIRD ENUM. :718 is a
            // plain nullable VARCHAR(100) and NOT part of any value list; the
            // three ENUMs are therefore not contiguous (:716, :718, :719) and
            // an implementation that reads them as one block mis-orders this
            // table. Free text - "Antibiotik, Analgetik, dll" is an example,
            // not an enumeration, and nothing validates it.
            $table->string('kelas_terapi', 100)->nullable()->comment('Antibiotik, Analgetik, dll');

            // SIX values, in the DDL's exact order (:719) - the legal control
            // class, a fourth axis distinct from form, unit and therapeutic
            // class. NO default. The FIFTH member is the Indonesian word for
            // narcotics; it was CJK mojibake in an earlier draft of the plan
            // (appendix A.17) and was copied here straight from :719 rather
            // than retyped. The verifier compares the canonical type string as
            // a SEQUENCE, so a wrong letter and a wrong position are both
            // `column_type` drift.
            $table->enum('kelas_obat', ['bebas', 'bebas_terbatas', 'keras', 'fitofarmaka', 'narkotika', 'psikotropika']);

            // TINYINT(1) -> boolean(). Both are `tinyint` and SIGNED; the (1)
            // is a display width MySQL 8 no longer emits and the verifier folds
            // it away. NOT NULL DEFAULT **1** - the default is 1, not 0, so an
            // omitted column means "dispensing requires a prescription".
            $table->boolean('requires_resep')->default(true);
            $table->string('aturan_pakai_umum', 255)->nullable()->comment('3 x 1 tablet sesudah makan');

            // Free-text clinical monographs, TEXT NULL. Neither is a reference
            // to anything, and `kontraindikasi` is the only place the schema
            // can hold a drug-level contraindication at all - there is no
            // interaction-acknowledgement column, so a doctor's decision to
            // override one is captured only as free text in
            // `resep.catatan_dokter` (`:753`).
            $table->text('indikasi')->nullable();
            $table->text('kontraindikasi')->nullable();

            // The UNIT price. DECIMAL(12,2) - the (12,2) is (precision, scale)
            // and Laravel's decimal() takes both, so writing
            // decimal('harga_jual', 12) or "harmonising" it to another scale is
            // `column_type` drift. DEFAULT 0 means "not priced", not "free":
            // the schema cannot tell the two apart. The PER-APOTEK price is a
            // different column on a different table (`apotek_stok.harga_jual`,
            // :835), same type and same default, and nothing keeps the two in
            // step.
            $table->decimal('harga_jual', 12, 2)->default(0);
            $table->boolean('status_aktif')->default(true);

            // Both timestamp columns exist (:726-:727), so this table IS one of
            // the 16 that needs rule 5's raw ON UPDATE ALTER below. Declared as
            // the explicit `dibuat_at`/`diubah_at` pair every batch B-G uses,
            // NOT $table->timestamps(), which would emit created_at/updated_at.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // Named in the DDL (:728), so compared BY NAME. It indexes
            // `nama_generik` ALONE - not nama_brand, not a composite, and in
            // that order. It is the catalogue search path; a brand search is a
            // full scan because nama_brand (:712) carries no index. The plan's
            // todo-14 prose cites this at :727, which is `diubah_at`; :728 is
            // the index. Do not widen it.
            $table->index(['nama_generik'], 'idx_obat_nama');
        });

        DB::statement('ALTER TABLE master_obat MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_obat');
    }
};
