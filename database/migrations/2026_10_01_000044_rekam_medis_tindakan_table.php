<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 44 of 75 — `telemedicine_test.sql:669-679`.
 *
 * Third table of batch G. **7 columns** and two foreign keys. The whole statement
 * is `:670-678` (9 lines), so 9 = 7 columns + 2 `FOREIGN KEY` lines. There is no
 * ENUM and no `INDEX` line on this table at all — the only index beyond the
 * primary key is the one MySQL creates itself for each unindexed foreign key
 * (`rekam_medis_id` and `dokter_pelaksana_id`), which
 * `SchemaDiffer::diffIndexes()` treats as implied rather than as `extra_index`
 * drift since commit `27c6ca8`.
 *
 * `rekam_medis_id BIGINT UNSIGNED NOT NULL` with `ON DELETE CASCADE` (`:671`,
 * `:677`) — same reasoning as `rekam_medis_diagnosa`: a procedure line is
 * meaningless without its record. `rekam_medis` is table 42, the immediately
 * preceding migration in this batch.
 *
 * The performing-doctor column is `dokter_pelaksana_id BIGINT UNSIGNED NULL`
 * (`:676`) with
 * `FOREIGN KEY (dokter_pelaksana_id) REFERENCES dokter(id)` (`:678`) and
 * **no** `ON DELETE` clause, so it materialises MySQL's implicit `NO ACTION`
 * (`RESTRICT` for DML). It is the **only nullable foreign key on this table** —
 * `rekam_medis_id` beside it is `NOT NULL` — and the asymmetry is load-bearing:
 * a procedure line must be attachable to a record, but the DDL does not insist
 * on naming a doctor, so a procedure performed by an assistant or delegated to a
 * facility is representable. (The DDL states no reason for the nullability, so
 * treat "assistant or delegated facility" as the reading of the shape, not as a
 * documented rule.) `dokter` is table 31 (batch D), so nothing is deferred.
 *
 * The two delete rules are therefore mismatched on purpose, exactly as they are on
 * `konsultasi_chat` (table 39): the record link cascades, the person link
 * restricts. Deleting the medical record takes its procedures with it; deleting a
 * doctor is blocked while their procedure lines exist, and since `dokter` has no
 * `dihapus_at` either — the only two `dihapus_at` columns in the whole DDL are
 * `users` (`:148`) and `pasien` (`:249`) — there is no soft-delete path for a
 * doctor, so refusing the hard delete is the only option the schema allows. Do
 * not "harmonise" the two rules into the same one: that is `extra_foreign_key`
 * drift, and the cascading direction would also destroy clinical history.
 *
 * **(a) `icd9cm_kode VARCHAR(8) NULL` IS A BARE STRING WITH NO FOREIGN KEY
 * (`:672`), AND `constrained()` ON IT IS A PARITY BREAK.** `:672` is
 *
 *     icd9cm_kode VARCHAR(8) NULL,
 *
 * and the statement's two `FOREIGN KEY` clauses (`:677`, `:678`) are on
 * `rekam_medis_id` and `dokter_pelaksana_id` — neither on this column.
 * `master_icd9cm` is table 11 and has existed since batch A with
 * `kode VARCHAR(8) NOT NULL UNIQUE` (`:124`) — the same type and width — so a
 * constraint here would **succeed** and stay green forever while being permanent
 * `extra_foreign_key` drift. Declared bare below.
 *
 * It is also **nullable**, where its sibling `rekam_medis_diagnosa.icd10_kode`
 * (`:660`) is `NOT NULL`. The difference is the point: a diagnosis always carries
 * a code, while a procedure may be described entirely in words
 * (`nama_tindakan`), so an uncoded procedure is representable. The DDL declares
 * **no index at all** on `icd9cm_kode`, so a code lookup is a full table scan.
 * Two columns in this batch are unindexed for exactly this reason —
 * `icd9cm_kode` here and `rekam_medis_lampiran.diunggah_oleh` (`:687`) — so the
 * "no foreign key" rule costs a query plan as well as integrity, and neither
 * column may be given an index to fix it (`extra_index` drift).
 *
 * `nama_tindakan VARCHAR(255) NOT NULL` (`:673`) is the human-readable procedure
 * name and is the one mandatory descriptive column, which is what makes an
 * uncoded procedure representable at all. `keterangan TEXT NULL` (`:674`) is
 * free-text detail.
 *
 * `tanggal_tindakan DATETIME NOT NULL` (`:675`) is a **`DATETIME`**, the same type
 * as `rekam_medis.tanggal_periksa` (`:630`) and not a `TIMESTAMP`. It is the
 * only mandatory date on the table and the DDL declares no default for it, so a
 * procedure line must state when it happened.
 *
 * **No `dibuat_at` and no `diubah_at` at all.** This table is in the 39-table
 * "neither" group of `docs/migration-order.md` rule 4, so todo 19's model needs
 * `public $timestamps = false` and `$table->timestamps()` is exactly the wrong
 * call. Note the consequence: `tanggal_tindakan` is the only time this row
 * carries at all, and it is the time of the *procedure*, so it must not be read
 * as the row's creation time.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rekam_medis_tindakan', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('rekam_medis_id');

            // NO FOREIGN KEY, BY CONTRACT. :672 is a bare NULLABLE VARCHAR(8) and
            // the statement's two FOREIGN KEY clauses (:677-:678) are on
            // rekam_medis_id and dokter_pelaksana_id. master_icd9cm (table 11)
            // exists and matches exactly, so ->foreign() would work and would
            // still be drift. See (a).
            $table->string('icd9cm_kode', 8)->nullable();
            $table->string('nama_tindakan', 255);
            $table->text('keterangan')->nullable();

            // DATETIME, NOT TIMESTAMP - a clinical event time, and the DDL
            // declares no default, so a procedure line must state when it
            // happened. Not the row's creation time; see the docblock.
            $table->dateTime('tanggal_tindakan');

            // The only NULLABLE foreign key on this table, and deliberately so: a
            // procedure may be delegated to a facility, with no dokter row here.
            $table->unsignedBigInteger('dokter_pelaksana_id')->nullable();

            // Mismatched on purpose: the record link cascades, the person link
            // carries no ON DELETE clause (:678) -> implicit RESTRICT. rekam_medis
            // is table 42, the migration immediately before this one; dokter is
            // table 31 (batch D). Nothing is deferred.
            $table->foreign('rekam_medis_id')->references('id')->on('rekam_medis')->cascadeOnDelete();
            $table->foreign('dokter_pelaksana_id')->references('id')->on('dokter');

            // NO $table->timestamps() and NO explicit index: this table has
            // neither dibuat_at nor diubah_at (rule 4's 39-table "neither" group),
            // and the DDL names no index. MySQL's implicit FK-support indexes are
            // treated as implied, not as extra_index drift.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rekam_medis_tindakan');
    }
};
