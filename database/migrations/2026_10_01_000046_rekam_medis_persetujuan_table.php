<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 46 of 75 — `telemedicine_test.sql:692-702`.
 *
 * Fifth and last table of batch G. **8 columns** and one foreign key. The whole
 * statement is `:693-701` (9 lines), so 9 = 8 columns + 1 `FOREIGN KEY` line.
 * There is no ENUM, no wrapped value list, no `INDEX` line and no timestamp
 * column of any kind — the only index beyond the primary key is the one MySQL
 * creates itself for the unindexed `rekam_medis_id` foreign key, which
 * `SchemaDiffer::diffIndexes()` treats as implied rather than as `extra_index`
 * drift since commit `27c6ca8`.
 *
 * `rekam_medis_id BIGINT UNSIGNED NOT NULL` with `ON DELETE CASCADE` (`:694`,
 * `:701`) — the same reasoning as the other three sub-tables, and the last of the
 * batch's nine foreign keys to be declared. `rekam_medis` is table 42, created
 * four migrations before this one in this same batch, so **nothing in batch G is
 * deferred** and the *Deferred constraints* registry in `docs/schema-notes.md` is
 * unchanged and still holds exactly its one `fk_vital_rm` entry.
 *
 * **`persetujuan_pdp` IS A DIFFERENT TABLE AND IS NOT PART OF THIS BATCH.**
 * `persetujuan_pdp` (table 74, `:1134`) records platform-level data
 * protection consent keyed on `user_id`, and it has a named
 * `UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)` (`:1144`). This table
 * records **patient-level clinical consent attached to one medical record**. The
 * two are not substitutes, the two spellings are easy to confuse
 * (`persetujuan` here, `persetujuan_pdp` there), and no constraint links them.
 *
 * `tipe` is a THREE-value ENUM in the DDL's exact order (`:695`) —
 * `general_consent`, `persetujuan_tindakan`, `penolakan_tindakan` — and it has
 * **no** `DEFAULT`, so a consent row is not insertable without an explicit type.
 * The three are not three states of one thing and must not be treated as a
 * lifecycle: `general_consent` is broad treatment consent,
 * `persetujuan_tindakan` is consent for a specific procedure, and
 * `penolakan_tindakan` is a **refusal**. There is no `dicatat_oleh`, no
 * `dibatalkan_at` and no supersession column, so a refusal is recorded by
 * inserting a `penolakan_tindakan` row and nothing erases the earlier consent —
 * a patient can hold both an approval and a refusal for the same procedure and
 * nothing in the schema picks a winner. "The most recent row of this `tipe` for
 * this record governs" is therefore an **application-level rule owned by todo
 * 33**, and it is only safe if `ditandatangani_at` is written honestly, because
 * it is the only ordering column available (see below).
 *
 * `isi_persetujuan TEXT NOT NULL` (`:696`) is the consent text as presented, and
 * it is the one mandatory body column. It is `TEXT` — MySQL's 65 535-byte text
 * type — and not a `VARCHAR`, so it holds a full document rather than a label.
 * It is not a version pointer, so whatever wording a patient agreed to has to be
 * written into this column; the DDL offers nowhere else to keep it.
 *
 * `ditandatangani_oleh VARCHAR(150) NOT NULL` (`:697`) is a **name string, not a
 * foreign key and not a `users` id** — the statement's only `FOREIGN KEY` clause
 * is `:701`, on `rekam_medis_id`. A patient signing their own consent need not be
 * a platform user, so the signer is captured as free text. This is why
 * `hubungan_dengan_pasien` (`:698`, `VARCHAR(50) NULL`) exists beside it: the
 * relationship is free text too, for the same reason — `master_hubungan_keluarga`
 * is table 9 and is not referenced here — and `NULL` reads as "the patient signed
 * themselves" rather than "unknown".
 *
 * `tanda_tangan_url VARCHAR(500) NULL` (`:699`) is the signature image locator.
 * It is nullable, so a record with no uploaded image is representable, and the
 * DDL states no reason. It carries **no** `UNIQUE` and no index, the same shape as
 * `surat_keterangan.qr_token` (`:592`) in batch F: two consents sharing one
 * signature image are representable, and the mitigation has to be
 * application-level because a unique index would be `extra_index` drift.
 *
 * `ditandatangani_at DATETIME NOT NULL` (`:700`) is a **`DATETIME`, not a
 * `TIMESTAMP` and not a `DATE`**, declared with no fractional-seconds precision so
 * it stores whole seconds, and it is the only timestamp-like column on the whole
 * table. It is `NOT NULL` with **no** default and no trigger, so nothing in the
 * database supplies it — which is exactly why it is also the only thing that can
 * order consent rows into a sequence. A record written without it is impossible; a
 * record written with a *wrong* one is representable and would reorder the
 * patient's own consent history.
 *
 * **No `dibuat_at` and no `diubah_at` at all.** This table is in the 39-table
 * "neither" group of `docs/migration-order.md` rule 4, so todo 19's model needs
 * `public $timestamps = false` and `$table->timestamps()` is exactly the wrong
 * call (it would emit `created_at`/`updated_at` and produce six drift rows). The
 * consequence is sharp here: `ditandatangani_at` is **not** a row-creation time,
 * it is the moment of signature, so it cannot stand in for one, and there is no
 * column anywhere on this table that records when the row was written to the
 * database. Consent rows therefore have no insertion audit trail, which is worth
 * noting against UU PDP 27/2022 even though the schema cannot be changed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rekam_medis_persetujuan', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('rekam_medis_id');

            // THREE values, in the DDL's exact order (:695), and NO default: the
            // DDL declares none. NOT a lifecycle - 'penolakan_tindakan' is a
            // refusal, not a later state of the same consent, and nothing in the
            // schema supersedes an earlier row. See the class docblock.
            $table->enum('tipe', ['general_consent', 'persetujuan_tindakan', 'penolakan_tindakan']);

            // The consent text as presented. TEXT NOT NULL, so the wording has to
            // be written into this column - the table has nowhere else to keep it.
            $table->text('isi_persetujuan');

            // A NAME STRING, not a users id and not a foreign key: the statement's
            // only FOREIGN KEY clause is :701. A patient signing their own
            // consent need not be a platform user.
            $table->string('ditandatangani_oleh', 150);

            // Free text for the same reason, with no master_hubungan_keluarga
            // constraint. NULL means "the patient signed themselves", not
            // "unknown".
            $table->string('hubungan_dengan_pasien', 50)->nullable();

            // No UNIQUE and no index in the DDL, so none here - see the docblock.
            $table->string('tanda_tangan_url', 500)->nullable();

            // DATETIME NOT NULL, whole seconds, not a TIMESTAMP and not a DATE,
            // with no default and no trigger. It is the only ordering column on
            // this table, so a wrong value here reorders the patient's own
            // consent history.
            $table->dateTime('ditandatangani_at');

            // rekam_medis is table 42, created four migrations before this one in
            // the same batch, so nothing here is deferred.
            $table->foreign('rekam_medis_id')->references('id')->on('rekam_medis')->cascadeOnDelete();

            // NO $table->timestamps() - this table has neither dibuat_at nor
            // diubah_at (rule 4's 39-table "neither" group), and no column of any
            // kind records the row's insertion time. See the class docblock.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rekam_medis_persetujuan');
    }
};
