<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 41 of 75 — `telemedicine_test.sql:599-615`.
 *
 * Last table of batch F. 12 columns and three foreign keys. The body is `:600-614`
 * (15 lines) and there is no wrapped ENUM and no `INDEX` line, so 15 = 12 columns
 * plus 3 `FOREIGN KEY` lines.
 *
 * **(a) `faskes_asal_id` HAS **NO FOREIGN KEY**, AND THAT IS BY CONTRACT, NOT AN
 * OMISSION.** `:602` is
 *
 *     faskes_asal_id BIGINT UNSIGNED NULL,
 *
 * a bare nullable unsigned `BIGINT`, and the statement that follows declares
 * exactly three `FOREIGN KEY` clauses — on `surat_keterangan_id` (`:612`),
 * `faskes_tujuan_id` (`:613`) and `dokter_perujuk_id` (`:614`) — none of them on
 * `faskes_asal_id`. The column is on the plan's authoritative list of columns
 * that look like references and carry no foreign key, and it is declared **bare**
 * below as a plain `->unsignedBigInteger('faskes_asal_id')->nullable()`.
 *
 * The asymmetry with `faskes_tujuan_id` one line below is the whole point and is
 * easy to misread as an oversight: the referring facility is deliberately
 * unconstrained, the receiving one is not. Both targets would work —
 * `faskes` is table 28 and exists — so adding a foreign key here would keep
 * `migrate:fresh` green while becoming permanent `extra_foreign_key` drift. There
 * is no deferral and nothing is owed: **no migration, including
 * `2026_10_01_000076`, may add a constraint on this column.** Recorded in
 * `docs/schema-notes.md` so it is not rediscovered.
 *
 * Because there is no constraint, the referring facility is unvalidated by the
 * database. That is a deliberate-looking shape for a referral: a referral may
 * originate outside the platform's own facility directory, so requiring a
 * `faskes` row would reject legitimate external referrers. The consequence is that
 * a `faskes_asal_id` naming a facility this installation has never heard of is
 * representable, and any consumer that wants the referring facility's name must
 * treat the join as optional.
 *
 * **(b) `status` is a THREE-value ENUM in the DDL's exact order (`:610`)** —
 * `aktif`, `terpakai`, `kedaluwarsa` — with `NOT NULL DEFAULT 'aktif'`, so a new
 * referral starts live without the service layer writing the column. The three
 * values are mutually exclusive and representable in any order: nothing in the
 * schema stops a `terpakai` referral from also being past `berlaku_sampai`, and
 * nothing couples the status to the date. A scheduled command must therefore
 * derive "expired" from `berlaku_sampai` on its own and must not assume the
 * status is kept in step — the same pattern as `booking.status`'s `kadaluarsa`
 * value, which also has no trigger and no column recording when the window
 * closes.
 *
 * **`berlaku_sampai DATE NOT NULL` (`:608`) is the one date on this table that is
 * mandatory**, and it is the referral's own expiry — the date after which the
 * receiving facility will no longer honour it. That is a *different* date from the
 * medical window on `surat_keterangan` (`tanggal_mulai` / `tanggal_selesai`,
 * `:588`-`:589`), which is the period the letter covers, and the two are
 * independent: a letter may describe a three-day rest period on a referral that is
 * valid for six months. Nothing in the schema ties them together, so nothing will
 * derive one from the other.
 *
 * `nomor_sep VARCHAR(30) NULL` (`:609`) is the BPJS V-Claim social-security
 * number. It is nullable and carries the DDL's own comment, copied verbatim:
 * `'Diisi jika klaim BPJS (V-Claim)'` — filled in only for a BPJS claim. So for
 * every non-BPJS referral it is `NULL`, and `NULL` means "not a BPJS claim" rather
 * than "missing data". The plan's guardrail forbids integrating a real V-Claim
 * client, so this column is the integration point and the number is stored
 * unvalidated; the 30-character width is not verified against any external
 * specification by the DDL.
 *
 * `icd10_kode VARCHAR(8) NULL` (`:606`) is a **bare indexed-by-nothing string with
 * no foreign key**, matching `master_icd10.kode`. `master_icd10` is table 10 and
 * has existed since batch A, so `->constrained('master_icd10', 'kode')` would work
 * and would still be drift. The same reasoning applies to
 * `rekam_medis_diagnosa.icd10_kode` (`:660`) in batch G, which is the one place
 * that *does* declare `INDEX idx_diag_icd10` on it. Validation here is
 * application-layer only, and the column is nullable, so a referral with no
 * diagnosis code is perfectly normal.
 *
 * **(c) `surat_keterangan_id` is `NOT NULL` WITH A REAL FOREIGN KEY, AND IT IS THE
 * ONLY LINK TO THE LETTER.** `:601` is `BIGINT UNSIGNED NOT NULL` with
 * `FOREIGN KEY (surat_keterangan_id) REFERENCES surat_keterangan(id)` (`:612`).
 * That FK is not deferred: `surat_keterangan` is table 40, created one migration
 * earlier in this same batch, and the reference is declared with no `ON DELETE`
 * clause, so it materialises MySQL's implicit `NO ACTION` (`RESTRICT` for DML).
 * A letter that is referenced by a referral therefore cannot be hard-deleted —
 * which is consistent with `surat_keterangan` having no `dihapus_at` of its own.
 * None of this makes the *consultation* reachable: `surat_keterangan.konsultasi_id`
 * is unconstrained (table 40, docblock (a)), so a referral resolves to a letter but
 * the letter's own link back to a consultation is unvalidated.
 *
 * `diagnosis_kerja VARCHAR(255) NULL` (`:605`) repeats the short working-diagnosis
 * label that `konsultasi.diagnosis_kerja` (`:552`) and
 * `rekam_medis` also carry — it is a third copy of the same fact, unconstrained
 * and unsynchronised with the other two. `alasan_rujukan TEXT NULL` (`:607`) is the
 * free-text referral reason and is the column a referring clinician actually writes.
 *
 * **(d) `dibuat_at` ONLY — no `diubah_at`, so there is no raw `ALTER` here.** `:611`
 * is `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and the statement
 * ends at `:612`-`:614` with the three foreign keys. This table is therefore one of
 * the 19 "dibuat_at only" tables, which is why `docs/migration-order.md` rule 5's
 * raw `ALTER TABLE … MODIFY diubah_at … ON UPDATE CURRENT_TIMESTAMP` does **not**
 * apply: there is no `diubah_at` column to modify, and adding one would be a
 * `missing_column` plus an `extra_column` pair. Declaring `$table->timestamps()`
 * would be exactly that mistake. There is also **no** `dihapus_at` — only `users`
 * (`:148`) and `pasien` (`:249`) get soft deletes — so a referral is retired by
 * moving `status` to `terpakai` or `kedaluwarsa`, not by deleting it.
 *
 * All three foreign keys are un-deferred: `surat_keterangan` (40) is one migration
 * earlier in this same batch, `faskes` is 28 (batch D) and `dokter` is 31 (batch
 * D). **Nothing here is deferred**, so the *Deferred constraints* table in
 * `docs/schema-notes.md` gains no row and still holds exactly its one
 * `fk_vital_rm` entry.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rujukan', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('surat_keterangan_id');

            // NO FOREIGN KEY, BY CONTRACT. The DDL (:602) declares a bare nullable
            // unsigned BIGINT; its only three FOREIGN KEY clauses (:612-:614) are
            // on surat_keterangan_id, faskes_tujuan_id and dokter_perujuk_id. Note
            // the asymmetry with faskes_tujuan_id immediately below - the
            // referring facility is deliberately unconstrained. See docblock (a).
            $table->unsignedBigInteger('faskes_asal_id')->nullable();

            $table->unsignedBigInteger('faskes_tujuan_id');
            $table->unsignedBigInteger('dokter_perujuk_id');
            $table->string('diagnosis_kerja', 255)->nullable();

            // A bare code string with NO foreign key, validated in the application
            // only. master_icd10 exists (table 10), so ->constrained() would work
            // and would still be drift. See the class docblock (b).
            $table->string('icd10_kode', 8)->nullable();
            $table->text('alasan_rujukan')->nullable();

            // The referral's own expiry, and the only NOT NULL date here. A
            // different date from the medical window on surat_keterangan.
            $table->date('berlaku_sampai');

            // The DDL's own comment on :609, copied verbatim (rule 12). NULL means
            // "not a BPJS claim", not "missing data".
            $table->string('nomor_sep', 30)->nullable()->comment('Diisi jika klaim BPJS (V-Claim)');

            // Three values, in the DDL's exact order (:610). The default is 'aktif'.
            $table->enum('status', ['aktif', 'terpakai', 'kedaluwarsa'])->default('aktif');

            // This table has NO diubah_at, so $table->timestamps() and the raw
            // ON UPDATE ALTER of rule 5 are both wrong here. See the class docblock (d).
            $table->timestamp('dibuat_at')->useCurrent();

            // No ON DELETE clause on any of the three (DDL :612-:614) -> implicit
            // RESTRICT. surat_keterangan (40) is one row earlier in this batch.
            $table->foreign('surat_keterangan_id')->references('id')->on('surat_keterangan');
            $table->foreign('faskes_tujuan_id')->references('id')->on('faskes');
            $table->foreign('dokter_perujuk_id')->references('id')->on('dokter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rujukan');
    }
};
