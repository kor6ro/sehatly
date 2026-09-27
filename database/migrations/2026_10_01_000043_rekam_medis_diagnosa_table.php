<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 43 of 75 — `telemedicine_test.sql:657-667`.
 *
 * Second table of batch G. **7 columns**, one foreign key and one named index.
 * The whole statement is `:658-666` (9 lines) and there is no wrapped ENUM, so
 * 9 = 7 columns + 1 `FOREIGN KEY` line + 1 `INDEX` line.
 *
 * `rekam_medis_id BIGINT UNSIGNED NOT NULL` with
 * `FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE`
 * (`:659`, `:665`). The cascade is the point: a diagnosis line has no meaning
 * without its record, so deleting the record must take its diagnoses with it.
 * `rekam_medis` is table 42, created by the **immediately preceding** migration in
 * this same batch, so nothing here is deferred and the *Deferred constraints*
 * registry in `docs/schema-notes.md` is unchanged.
 *
 * **Note what is NOT here: `rekam_medis` has no `dihapus_at` and nothing in this
 * statement cascades a *soft* delete.** The cascade fires only on a hard
 * `DELETE` from `rekam_medis`, which the schema permits but the application
 * should treat as a last resort — see `2026_10_01_000042`'s docblock. The
 * cascade is therefore the database's safety net rather than the intended
 * removal path, and it is the reason the same `ON DELETE CASCADE` appears
 * identically on all four sub-tables.
 *
 * **(a) `icd10_kode VARCHAR(8) NOT NULL` IS A BARE INDEXED STRING WITH NO
 * FOREIGN KEY (`:660`), AND `constrained()` ON IT IS A PARITY BREAK.**
 * `:660` is
 *
 *     icd10_kode VARCHAR(8) NOT NULL,
 *
 * and the statement declares exactly one `FOREIGN KEY` clause (`:665`), on
 * `rekam_medis_id`. `master_icd10` is table 10 and has existed since batch A, and
 * its `kode` is `VARCHAR(8) NOT NULL UNIQUE` (`:117`) — the same type and width —
 * so `->foreign('icd10_kode')->references('kode')->on('master_icd10')` would
 * **succeed** and stay green forever while being permanent
 * `extra_foreign_key` drift. It is on the plan's authoritative no-foreign-key
 * list and the plan's own todo-13 prose names it. Declared bare below.
 *
 * What replaces the constraint is `INDEX idx_diag_icd10 (icd10_kode)` (`:666`) —
 * the one name-bearing key besides the primary key, so it is compared **by name**
 * on `(TABLE_NAME, INDEX_NAME)`, and its single-column order is the contract. The
 * index makes code lookup fast; it validates nothing. So an `icd10_kode` that
 * names a code absent from `master_icd10` is representable, and `NOT NULL` here
 * only means "a diagnosis line always carries a code" — never "a code that
 * exists". Todo 33 must validate against `master_icd10` in the service layer.
 *
 * The width is **8 characters, `VARCHAR` and not `CHAR`**, the same as
 * `master_icd10.kode` (`:117`), `master_icd9cm.kode` (`:124`) and
 * `pasien_riwayat_penyakit.icd10_kode` (`:291`). The DDL states no reason for
 * the width, so treat 8 as the contract rather than as a claim about how long
 * an ICD-10 code can be: the column will accept any 8-character string, and
 * deciding whether a value is a real code is the service's job.
 *
 * **(b) `jenis` is a FOUR-value ENUM in the DDL's exact order (`:662`) —
 * `utama`, `sekunder`, `diferensial`, `komplikasi` — and it has NO `DEFAULT`.**
 * So a diagnosis row is not insertable without an explicit type. `utama` sorts
 * first and is the one member with a natural "exactly one per record" reading,
 * but **nothing in the schema enforces that**: there is no unique index on
 * `(rekam_medis_id, jenis)`, so a record may carry zero, one or several `utama`
 * rows, and the same for every other member. "Exactly one primary diagnosis per
 * record" is an application-level invariant owned by todo 33 and cannot be
 * repaired with an index here — adding one would be `extra_index` drift.
 *
 * `tipe_kasus` is a TWO-value ENUM (`:663`) — `baru`, `lama` — with
 * `NOT NULL DEFAULT 'baru'`, so a diagnosis defaults to a new case without the
 * service writing the column. `diferensial` diagnoses in a `baru` case are the
 * obvious mismatch, and nothing in the schema objects: a differential
 * diagnosis carries a "new case" flag with no coupling to the record's other
 * rows.
 *
 * `is_terkonfirmasi TINYINT(1) NOT NULL DEFAULT 0` (`:664`) is the
 * confirmation flag, so a diagnosis is **unconfirmed by default** — the DDL
 * states nothing further, so "a `diferensial` row is the expected starting
 * state" is a reading of the vocabulary and not a rule the schema states.
 * `NOT NULL` is declared in the DDL and reproduced by omitting `->nullable()`.
 *
 * `deskripsi VARCHAR(255) NULL` (`:661`) is a free-text label. It is nullable and
 * independent of `icd10_kode`, so a line may carry a code, a description, both, or
 * a code with no description — the code is the only mandatory one.
 *
 * **No `dibuat_at` and no `diubah_at` at all.** This table is in the 39-table
 * "neither" group of `docs/migration-order.md` rule 4, so todo 19's model needs
 * `public $timestamps = false` and `$table->timestamps()` would be exactly the
 * wrong call: it emits `created_at` and `updated_at`, two columns the DDL does
 * not declare, so each is `extra_column` drift. There is no creation or
 * modification time on a diagnosis line, so "who confirmed this and when" is not
 * recordable — another application-level obligation, and the reason todo 33's
 * audit story cannot lean on this table.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rekam_medis_diagnosa', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('rekam_medis_id');

            // NO FOREIGN KEY, BY CONTRACT. :660 is a bare NOT NULL VARCHAR(8);
            // the statement's only FOREIGN KEY clause is :665 on rekam_medis_id.
            // master_icd10 (table 10) exists and matches type and width exactly,
            // so ->foreign() would work and would still be drift. See (a).
            $table->string('icd10_kode', 8);

            $table->string('deskripsi', 255)->nullable();

            // FOUR values, in the DDL's exact order (:662), and NO default: the
            // DDL declares none, so jenis is required at insert. "Exactly one
            // 'utama' per record" is NOT enforced - there is no unique on
            // (rekam_medis_id, jenis). See (b).
            $table->enum('jenis', ['utama', 'sekunder', 'diferensial', 'komplikasi']);

            // TWO values (:663). The default is 'baru', so a new diagnosis needs
            // no explicit case type.
            $table->enum('tipe_kasus', ['baru', 'lama'])->default('baru');

            // TINYINT(1) -> boolean(). NOT NULL in the DDL, so no ->nullable().
            // DEFAULT 0, so a diagnosis is unconfirmed unless the service says
            // otherwise.
            $table->boolean('is_terkonfirmasi')->default(false);

            // rekam_medis is table 42, the migration immediately before this one.
            $table->foreign('rekam_medis_id')->references('id')->on('rekam_medis')->cascadeOnDelete();

            // Named in the DDL, so compared by name. Single column, so the order
            // is the contract. Indexes the code; validates nothing. See (a).
            $table->index(['icd10_kode'], 'idx_diag_icd10');

            // NO $table->timestamps() - this table has neither dibuat_at nor
            // diubah_at (rule 4's 39-table "neither" group). See the docblock.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rekam_medis_diagnosa');
    }
};
