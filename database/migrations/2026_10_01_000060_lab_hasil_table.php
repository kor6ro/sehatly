<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 60 of 75 — `telemedicine_test.sql:905-919`. **Last table of batch I.**
 *
 * **11 columns** (`:906`-`:916`), **ONE index (the primary key) and TWO foreign
 * keys** — the last table of the 60 contract tables to be migrated by this todo.
 *
 * ## TRAP 1 — `diperiksa_oleh` HAS **NO FOREIGN KEY**. IT IS BARE **BY CONTRACT**.
 *
 * `diperiksa_oleh BIGINT UNSIGNED NULL` (`:914`) reads exactly like a reference to
 * `users(id)`, it is named exactly like one, and it is the **only** column in this
 * table that is a plausible user reference. **The DDL declares no `FOREIGN KEY` for
 * it.** The table's foreign keys are on `lab_permintaan_id` (`:917`) and
 * `tindakan_id` (`:918`) and on nothing else.
 *
 * **Do NOT write `->foreign('diperiksa_oleh')->references('id')->on('users')`.** It
 * is the single most likely invented constraint in this batch, for three reasons
 * that all point the same way:
 *
 *  1. **`users` is table 12 and exists many batches earlier**, so the constraint
 *     would **succeed** at migration time and become permanent
 *     `extra_foreign_key` drift. `migrate:fresh` exits 0, `php -l` exits 0 and the
 *     whole unit suite stays green while it is there.
 *  2. **The plan's own generated no-FK list names this column explicitly** — it is
 *     the 28th entry in the "reference-shaped name but no foreign key" table, cited
 *     at `:914`, which is the correct line. But the plan's **todo-15 prose does not
 *     mention it at all**, and *absence from a brief is exactly how an invented
 *     constraint gets written*. That omission has already produced this defect class
 *     twice in this project (`resep.konsultasi_id` in batch H, and
 *     `pasien_penjamin.faskes_rujukan_id` before that).
 *  3. **A verifying laboratory can have no `users` row.** The column is nullable and
 *     the person who reads the result is plausibly an external pathologist at the
 *     `faskes` running the test, not a platform account. A foreign key would make a
 *     legitimate external verifier unrepresentable — the same reasoning that leaves
 *     `audit_log.user_id` (`:1120`) bare on purpose "so the log survives user
 *     deletion", and `artikel.reviewer_user_id` (`:1078`) bare.
 *
 * **It is also not a deferred constraint.** `fk_vital_rm`
 * (`pasien_tanda_vital.rekam_medis_id`, SQL section `[14]` `:1161`-`:1163`) is the
 * **only** row in the *Deferred constraints* registry in `docs/schema-notes.md` and
 * the only constraint migration `2026_10_01_000076` adds. **This batch owes no
 * deferred constraint at all** — every foreign key in batch I points at a table that
 * already exists when its own migration runs, four of them from inside this same
 * batch. Do not register `diperiksa_oleh` anywhere: a registry row would promise a
 * constraint the DDL never declares.
 *
 * **Proven, not assumed.** All three bare columns of batch I were checked against
 * `information_schema.REFERENTIAL_CONSTRAINTS` — **not** by reading `SHOW CREATE
 * TABLE`, which only shows the constraints that exist and so cannot distinguish
 * "absent" from "not looked for". A column with zero foreign keys **does not appear
 * in that result set at all**, so "no row returned" is the expected evidence for a
 * bare column and a query returning nothing is a pass, not a failed lookup.
 *
 * ## `nilai VARCHAR(100) NOT NULL` (`:909`) IS A **STRING**, AND THAT IS THE POINT
 *
 * The result is **not** a `DECIMAL`, not a `FLOAT` and not an `INT`, and the plan's
 * own acceptance criterion for this todo is that the verifier flags `lab_hasil.nilai`
 * if it is ever emitted as a numeric type. Three separate things in this table
 * depend on it being text:
 *
 *  - **`master_lab_tindakan.nilai_rujukan_laki` (`:853`) is itself `VARCHAR(100)`
 *    free text** — the seed stores `13.0-17.0` (`:1321`), `<200` (`:1324`) and
 *    `negatif` (`:1330`). A qualitative result like `negatif`, `Reaktif`, `positif`
 *    or `Tidak Cedera` has **no numeric representation whatsoever**, and one column
 *    holds both kinds of result.
 *  - **`is_abnormal` (`:912`) is a STORED FLAG, not a computed one.** There is no
 *    trigger and no generated column, so nothing in the database compares `nilai`
 *    with the reference interval — the flag is whatever the writer asserted. A
 *    `DECIMAL` column would not have fixed that; the absence of a comparison is
 *    independent of the column's type.
 *  - **`satuan` (`:910`) is separately nullable** while `nilai` is `NOT NULL`, so a
 *    result with no unit is representable even for a test the catalogue gives a unit
 *    to — the unit on the *result* is not forced to agree with the unit on the
 *    *test*, and nothing checks it.
 *
 * `is_abnormal TINYINT(1) NOT NULL DEFAULT 0` (`:912`) is therefore the **default is
 * 0, i.e. NORMAL** — the opposite polarity to `status_aktif` on the two master
 * tables in this same batch, which both default to `1` (`:857`, `:865`). That
 * asymmetry is deliberate and is the reason both defaults are written out in full
 * here: a result is normal unless someone says otherwise, while a test is active
 * unless someone says otherwise.
 *
 * ## `tanggal_hasil DATETIME NOT NULL` (`:915`) IS A `DATETIME`, NOT A `TIMESTAMP`
 *
 * It is the only `DATETIME` in batch I, and it is a bare caller-supplied moment with
 * no default — **not** `created_at`/`dibuat_at` and not auto-populated. A result
 * read on paper can be entered with an earlier `tanggal_hasil` than the request's
 * `dibuat_at` (`:887`), and the schema does not object. `dateTime()`, never
 * `timestamp()`: MySQL's `TIMESTAMP` is stored as UTC and converted on read, and
 * emitting one here would be `column_type` drift.
 *
 * ## `file_pdf_url VARCHAR(500) NULL` (`:916`) IS A BARE URL STRING
 *
 * No foreign key, no length-uniqueness, no checksum column and no
 * `rekam_medis_lampiran` linkage — `rekam_medis_lampiran.tipe` does carry a
 * `'hasil_lab'` member (`:686`), but `file_pdf_url` does not point at that table and
 * nothing joins the two. The document itself lives wherever the URL says; a row may
 * carry a result and no file at all, and a file may be reachable for a result whose
 * request has been cascade-deleted (see below).
 *
 * ## THE ONE CASCADE IS ON THE PARENT REQUEST, AND IT DESTROYS THE RESULT
 *
 * `FOREIGN KEY (lab_permintaan_id) REFERENCES lab_permintaan(id) ON DELETE CASCADE`
 * (`:917`) — deleting a request deletes its results, which keeps no orphan behind.
 * `tindakan_id` (`:918`) carries **no `ON DELETE` clause** and so materialises
 * MySQL's implicit `NO ACTION` (`RESTRICT` for DML): a catalogue test still named by
 * a result cannot be deleted. Record link cascades, catalogue link restricts — the
 * same reading as `lab_permintaan_detail` (`:900` cascade, `:901`-`:902` restrict)
 * and as batch H.
 *
 * Worth recording because it is a real retention consequence: a result is
 * **clinically meaningful evidence that disappears with its request**, and the
 * `file_pdf_url` pointing at it dies with it. The same defect class the plan
 * already records for `akses_rekam_medis_log.rekam_medis_id` `ON DELETE CASCADE`
 * (`:1153`), where "deleting a medical record deletes the evidence that it was
 * accessed". `lab_hasil` is not a log, so this is a weaker instance and **not** a
 * reason to alter the schema — but a service that must retain released results has
 * to copy them out first, and nothing here prevents or warns about it.
 *
 * `PRIMARY KEY (id)` (`:906`) covers neither foreign key, so InnoDB builds two
 * implicit support indexes that are **absent from the DDL** and are treated as
 * implied by their matched foreign keys (commit `27c6ca8`). They must **not** be
 * suppressed by adding covering indexes of our own.
 *
 * No `dibuat_at` and no `diubah_at`, so this table is in rule 4's 39-table "neither"
 * group and **todo 19's `LabHasil` needs `public $timestamps = false`**. Note the
 * consequence: `tanggal_hasil` (`:915`) is *not* a created-at substitute that
 * `created_at` would be wrong for — it is a separate clinical fact (when the result
 * was produced) and the row's own insertion time is simply not recorded.
 *
 * This table is **module-orphaned** (`docs/migration-order.md` row 60: `Module:
 * ORPHAN`, `Resource: —`, `Controller: —`): migrated and modelled for referential
 * completeness, never exposed. `notifikasi.tipe` carries a `'lab'` member (`:1041`),
 * so the module is referenced — but nothing in Modules 1-5 selects from here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lab_hasil', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:906). The only declared
            // index, and it covers neither of the two foreign keys below.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:907) - a result always belongs to a request.
            $table->unsignedBigInteger('lab_permintaan_id');

            // BIGINT UNSIGNED NOT NULL (:908) - a result always names the test it
            // measures, so this one is NOT NULL where
            // lab_permintaan_detail.tindakan_id (:897) is nullable.
            $table->unsignedBigInteger('tindakan_id');

            // VARCHAR(100) NOT NULL (:909) - A STRING, DELIBERATELY, and the plan's
            // own acceptance criterion for this todo is that the verifier flags this
            // column if it is ever emitted as a numeric type. The seed's own
            // reference intervals are free text too (master_lab_tindakan:853 stores
            // '13.0-17.0' at :1321, '<200' at :1324, 'negatif' at :1330), and a
            // qualitative result such as 'Reaktif' or 'Tidak Cedera' has no numeric
            // representation at all. One column holds both kinds of result.
            $table->string('nilai', 100);

            // VARCHAR(50) NULL (:910) - separately nullable from `nilai`, so a result
            // may carry no unit even for a test the catalogue gives one to, and
            // nothing checks the two units for agreement.
            $table->string('satuan', 50)->nullable();

            // VARCHAR(100) NULL (:911) - the reference interval AS APPLIED to this
            // particular result, and it is a SNAPSHOT, not a join: nothing keeps it in
            // step with master_lab_tindakan.nilai_rujukan_laki/perempuan (:853-854).
            // A later edit to the catalogue does not rewrite results already issued.
            $table->string('nilai_rujukan', 100)->nullable();

            // TINYINT(1) NOT NULL DEFAULT 0 (:912) - the default is 0, i.e. NORMAL.
            // Note the polarity is the OPPOSITE of master_lab_tindakan.status_aktif
            // (:857) and master_lab_paket.status_aktif (:865), which both default to
            // 1: a result is normal unless someone says otherwise, a test is active
            // unless someone says otherwise. This is a STORED flag with no trigger and
            // no generated column - nothing in the database derives it by comparing
            // `nilai` with `nilai_rujukan`, so the writer's assertion is the fact.
            $table->boolean('is_abnormal')->default(false);

            // TEXT NULL (:913) - free-text interpretation. `text()`, never `json()`.
            $table->text('keterangan')->nullable();

            // ## TRAP 1 - `diperiksa_oleh BIGINT UNSIGNED NULL` (:914) HAS **NO
            // FOREIGN KEY**. IT IS BARE BY CONTRACT.
            //
            // DO NOT write `->foreign('diperiksa_oleh')->references('id')->on('users')`
            // here. `users` is table 12 and exists many batches earlier, so the
            // constraint would SUCCEED at migration time and become permanent
            // `extra_foreign_key` drift while `migrate:fresh`, `php -l` and the whole
            // unit suite all stayed green. The plan's generated no-FK list names this
            // column at :914, but the plan's todo-15 PROSE DOES NOT MENTION IT AT
            // ALL, and that omission is exactly how an invented constraint gets
            // written. The substantive reason: a verifying pathologist at the
            // `faskes` running the test need not have a platform account, so a
            // foreign key would make a legitimate external verifier
            // unrepresentable - the same reasoning that leaves
            // `audit_log.user_id` (`:1120`, a bare `user_id BIGINT UNSIGNED NULL`
            // with no FOREIGN KEY) and `artikel.reviewer_user_id` (`:1078`) bare.
            // The plan gives the rationale for `audit_log.user_id` as "deliberately
            // unconstrained so the log survives user deletion"; that sentence is the
            // PLAN's, not a `COMMENT` in the DDL - `:1120` itself carries no comment
            // text, so do not read the justification as if the SQL stated it.
            //
            // It is NOT deferred either. `fk_vital_rm` (section [14], :1161-:1163) is
            // the only row in the Deferred-constraints registry and the only
            // constraint migration 2026_10_01_000076 adds; this batch owes no
            // deferred constraint. Do not register this column.
            $table->unsignedBigInteger('diperiksa_oleh')->nullable();

            // DATETIME NOT NULL (:915) - the only DATETIME in batch I, and a bare
            // caller-supplied moment with NO default: not auto-populated, and NOT a
            // created_at substitute. A paper result may predate its request's
            // dibuat_at (:887) and the schema does not object. `dateTime()`, never
            // `timestamp()` - MySQL TIMESTAMP is stored as UTC and converted on
            // read, and emitting one here would be column_type drift.
            $table->dateTime('tanggal_hasil');

            // VARCHAR(500) NULL (:916) - a bare URL string: no foreign key, no
            // checksum, no linkage to rekam_medis_lampiran (whose `tipe` does carry a
            // 'hasil_lab' member at :686, but nothing joins the two), and a result may
            // carry no file at all.
            $table->string('file_pdf_url', 500)->nullable();

            // Two foreign keys, DELIBERATELY MISMATCHED. ON DELETE CASCADE (:917) on
            // the RECORD link: deleting a request deletes its results, which is what
            // keeps an orphan behind. NO ON DELETE on the CATALOGUE link (:918): a
            // test still named by a result cannot be deleted. Writing
            // `cascadeOnDelete()` on tindakan_id would be foreign_key_action drift.
            //
            // Retention consequence worth knowing: a result is clinical evidence that
            // disappears with its request, taking its file_pdf_url with it - the same
            // defect class the plan records for
            // akses_rekam_medis_log.rekam_medis_id ON DELETE CASCADE (:1153). Not a
            // reason to alter the schema; a service that must retain released results
            // has to copy them out first.
            $table->foreign('lab_permintaan_id')->references('id')->on('lab_permintaan')->cascadeOnDelete();
            $table->foreign('tindakan_id')->references('id')->on('master_lab_tindakan');

            // No `dibuat_at` / `diubah_at` - rule 4's 39-table "neither" group, so
            // todo 19's model needs $timestamps = false. tanggal_hasil (:915) is a
            // separate clinical fact and does not stand in for a created-at; the
            // row's insertion time is simply not recorded.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_hasil');
    }
};
