<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Post-table migration 76 of 78 — the single **deferred foreign key**, added as a
 * raw `ALTER TABLE` from SQL section `[14]`.
 *
 * `telemedicine_test.sql:1157-1163` is a whole section of its own, headed
 * `-- [14] FOREIGN KEY TAMBAHAN (hindari ketergantungan silang saat CREATE)` —
 * "additional foreign keys, avoiding cross-dependency at CREATE time". It
 * contains exactly one statement, and this migration is that statement:
 *
 * ```sql
 * -- :1161-1163
 * ALTER TABLE pasien_tanda_vital
 *   ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)
 *   REFERENCES rekam_medis(id) ON DELETE SET NULL;
 * ```
 *
 * ## Why the DDL defers it, and why that is a dependency fact rather than a choice
 *
 * The DDL is written as if all 75 tables existed at once, so it can put every
 * constraint on the `CREATE TABLE` line. The migrations cannot. `rekam_medis` is
 * SQL table **42** (`CREATE TABLE` at `:621`, batch G, todo 13, migration
 * `2026_10_01_000042`), while `pasien_tanda_vital` is SQL table **25**
 * (`CREATE TABLE` at `:312`, batch C, todo 9, migration
 * `2026_10_01_000025`). Declaring the constraint inline at `:312` would make
 * `migrate:fresh` die with
 * `SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table
 * 'rekam_medis'` — the referencing table exists seventeen migrations before the
 * referenced one.
 *
 * **This migration runs at position 76, after all 75 tables, so the ordering
 * hazard is already gone.** `rekam_medis` has existed since migration 42, and
 * nothing about this `ALTER` can fail with MySQL 1824.
 *
 * The column being constrained is `pasien_tanda_vital.rekam_medis_id` at `:315` —
 * `rekam_medis_id BIGINT UNSIGNED NULL`, **nullable**, and carrying **no** index
 * and **no** foreign key in the `CREATE TABLE` body (`:312-330` declares exactly
 * one `FOREIGN KEY`, on `pasien_id` at `:328`, plus `INDEX idx_vital_pasien
 * (pasien_id, diukur_at)` at `:329`). I confirmed the owning statement by
 * searching for the column name rather than trusting the plan's `:NNN` citation.
 *
 * ## `ON DELETE SET NULL` is load-bearing, and it is the opposite polarity to the sibling constraint
 *
 * `:1163` ends `ON DELETE SET NULL`, so deleting a medical record **detaches**
 * the vital-signs row instead of deleting it. That is the correct semantics and
 * the reason is worth recording, because the table has a constraint with the
 * *opposite* polarity one line away in the same batch: `pasien_id` carries
 * `ON DELETE CASCADE` (`:328`), so deleting a *patient* takes their vitals with
 * them. The two are not inconsistent, and neither should be "harmonised":
 *
 * - **`pasien_id ON DELETE CASCADE`** — a vital-signs reading has no meaning
 *   without the patient it was measured on. It is a *dependent fact* of the
 *   patient row, so it goes when the patient goes.
 * - **`rekam_medis_id ON DELETE SET NULL`** — a reading is a *clinical
 *   observation in time*, and it retains its evidentiary value after the
 *   encounter record it was filed under is removed. Cascading it would destroy
 *   the measurement along with the paperwork; `SET NULL` keeps the number and
 *   records that its filing context is gone.
 *
 * The asymmetry is deliberate in the read-only contract. Per
 * `docs/schema-notes.md` (batch G) medical records are never hard-deleted in
 * practice, so `SET NULL` is a **correctness statement about the data model**
 * rather than a live operational path — but it is the statement the DDL makes,
 * and changing it would be `missing_foreign_key` / `extra_foreign_key` drift on
 * a table the verifier compares by `ON DELETE` behaviour. **Do not change it.**
 *
 * ## This is the ONLY foreign key in this migration, and the registry row it retires
 *
 * `fk_vital_rm` is the **only constraint name the DDL itself ever writes** for a
 * foreign key; every other FK in the schema is inline and therefore
 * engine-named `<table>_ibfk_<n>`. The parser's own output confirms it —
 * `named FKs  fk_vital_rm on pasien_tanda_vital (line 1161)` — which is why the
 * deferred-constraint registry could key on a name at all. The single row of that
 * registry is now retired: `docs/schema-notes.md` no longer carries a
 * `## Deferred constraints` section, and `VerifySchemaParity` no longer calls
 * `DeferredConstraintRegistry::fromMarkdown()`.
 *
 * ## DO NOT add a foreign key to `pasien_penjamin.faskes_rujukan_id`
 *
 * **This is a standing prohibition, recorded here because migration 76 is
 * exactly the migration a future reader would extend to "finish" it.**
 *
 * `pasien_penjamin` (`CREATE TABLE` at `:340`) declares `faskes_rujukan_id
 * BIGINT UNSIGNED NULL` at `:346` and **two** `FOREIGN KEY` clauses, at `:351`
 * (`pasien_id`) and `:352` (`penjamin_id`). There is **no** `FOREIGN KEY` for
 * `faskes_rujukan_id`, so the column is bare **by contract**. `faskes` is SQL
 * table 28 (`:360`, batch D, todo 10) and has existed since migration 28, so
 * `->foreign('faskes_rujukan_id')->references('id')->on('faskes')` would
 * *succeed*, stay green forever, and be permanent `extra_foreign_key` drift.
 *
 * An earlier revision of the plan's todo-9 prose, and then of this migration
 * batch's own docblock, called it a "FK to `faskes` deferred to migration 76".
 * That claim was **false** and is settled by plan appendices A.10 / A.11 and by
 * live measurement: the DDL declares none, so nothing is deferred and nothing is
 * owed. The `faskes_rujukan_id` column stays a bare unsigned `BIGINT`.
 *
 * The same prohibition applies to every other bare column in the schema, and
 * `docs/schema-notes.md` enumerates them per batch. The short list a reader of
 * *this* file is most likely to reach for:
 * `pasien_penjamin.faskes_rujukan_id` (`:346`),
 * `surat_keterangan.konsultasi_id` (`:584`),
 * `rujukan.faskes_asal_id` (`:602` — while `faskes_tujuan_id` at `:603` *is*
 * constrained, and that asymmetry is the point, not an oversight),
 * `pasien_riwayat_penyakit.icd10_kode` (`:291`),
 * and the four batch-G columns `rekam_medis.satusehat_encounter_id` (`:628`),
 * `rekam_medis_diagnosa.icd10_kode` (`:660`),
 * `rekam_medis_tindakan.icd9cm_kode` (`:672`) and
 * `rekam_medis_lampiran.diunggah_oleh` (`:687`).
 *
 * **Absence is load-bearing in every one of those cases.** A `Schema::table()`
 * "repair" would be invisible to `php -l` and to `migrate:fresh`, and
 * `verify-schema` would report it — but only after it had been committed.
 */
return new class extends Migration
{
    /**
     * `ALTER TABLE` is DDL: MySQL performs an **implicit commit** before and
     * after it, so wrapping this in a transaction cannot make it atomic and the
     * wrapper's own trailing `COMMIT` is a no-op. The same reasoning as the two
     * view migrations, and set here for the same reason rather than by analogy
     * — Laravel will only wrap a migration if nothing says otherwise.
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ALTER TABLE pasien_tanda_vital
        //   ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)
        //   REFERENCES rekam_medis(id) ON DELETE SET NULL;
        //
        // (:1161-1163) - section [14] in full. `ON DELETE SET NULL` is required
        // and must not be replaced with CASCADE or RESTRICT: see the class
        // docblock for why the polarity is opposite to `pasien_id`'s.
        //
        // No `ON UPDATE` clause, so MySQL materialises its implicit
        // `ON UPDATE RESTRICT` — which is what the reference model expects, and
        // naming it explicitly would produce the identical object.
        //
        // InnoDB will also create an implicit support index on
        // `pasien_tanda_vital.rekam_medis_id`, because `idx_vital_pasien
        // (pasien_id, diukur_at)` does not have this column as a leftmost
        // prefix. That index is absent from the DDL and is NOT drift:
        // `SchemaDiffer::diffIndexes()` treats a leftover live index whose
        // ordered column list exactly equals a *matched* foreign key's local
        // columns as implied rather than as drift (commit `27c6ca8`). Do not
        // "fix" it by adding a covering index of our own — that would be real
        // `extra_index` drift.
        DB::statement(
            'ALTER TABLE pasien_tanda_vital'
            .' ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)'
            .' REFERENCES rekam_medis(id) ON DELETE SET NULL',
        );
    }

    /**
     * Reverse the migrations.
     *
     * Drops the constraint and nothing else. `pasien_tanda_vital` is created by
     * migration 25 and dropped by that migration's own `down()`, so this method
     * must NOT drop the table: rolling back the whole schema runs every `down()`
     * in reverse order, and dropping the table here would make migration 25's
     * `Schema::dropIfExists` a no-op on an already-gone table (harmless) while
     * taking the table out from under migrations 25-24 for no reason.
     *
     * ## A rollback leaves an ORPHANED INDEX behind, and the verifier reports it
     *
     * Measured, not assumed. `ALTER TABLE ... DROP FOREIGN KEY fk_vital_rm` removes
     * the constraint but **InnoDB does not remove the implicit support index it
     * created for it**, so `pasien_tanda_vital` is left holding
     * `KEY fk_vital_rm (rekam_medis_id)` with no constraint behind it. The
     * verifier then reports **two** rows, not one:
     *
     * ```text
     * extra_index           pasien_tanda_vital  actual: fk_vital_rm INDEX (rekam_medis_id)
     * missing_foreign_key   pasien_tanda_vital  expected: fk_vital_rm ...
     * ```
     *
     * This is the `27c6ca8` implied-index logic working correctly rather than a
     * defect: it treats a leftover index as implied only when its column list
     * matches a foreign key that **matched**. With the constraint rolled back
     * there is no matched FK, so the index is correctly reported as extra. The
     * alternative - suppressing the index unconditionally on its column list -
     * would hide a genuinely invented index.
     *
     * **Consequence: `migrate:rollback` is not a clean inverse of `migrate` for
     * this one migration, and it does not need to be.** Re-running `migrate`
     * re-adds the constraint, the index becomes implied again, and the verifier
     * returns to `Discrepancies: 7 (0 drift, 7 informational)` / exit 0. Verified
     * in both directions. Do not "fix" the orphaned index here by issuing a
     * `DROP INDEX` in this `down()`: the index does not exist before `up()` runs,
     * so that statement would fail on a first-time rollback and add nothing on a
     * second one.
     */
    public function down(): void
    {
        // `ALTER TABLE ... DROP FOREIGN KEY <name>`. MySQL 8 also accepts
        // `DROP CONSTRAINT`, but `DROP FOREIGN KEY` is the spelling the DDL's
        // own `ADD CONSTRAINT` pairs with and the one every MySQL 8 tool
        // round-trips. Naming the constraint rather than the column is required
        // for correctness: a column may carry several foreign keys, and this one
        // must go by the name the DDL gave it.
        //
        // `IF EXISTS` is deliberately absent. It is not valid on this statement
        // form, and swallowing an absent constraint would make a partial rollback
        // look like a clean one.
        DB::statement('ALTER TABLE pasien_tanda_vital DROP FOREIGN KEY fk_vital_rm');
    }
};
