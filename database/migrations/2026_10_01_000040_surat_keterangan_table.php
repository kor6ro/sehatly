<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 40 of 75 — `telemedicine_test.sql:581-597`.
 *
 * Third table of batch F. 13 columns and two foreign keys. The body is `:582-596`
 * (15 lines) and there is no wrapped ENUM and no `INDEX` line, so 15 = 13 columns
 * plus 2 `FOREIGN KEY` lines.
 *
 * **(a) `konsultasi_id` HAS **NO FOREIGN KEY**, AND THAT IS BY CONTRACT, NOT AN
 * OMISSION.** `:584` is
 *
 *     konsultasi_id BIGINT UNSIGNED NULL,
 *
 * a bare nullable unsigned `BIGINT` — and the statement that follows lists exactly
 * two `FOREIGN KEY` clauses, on `pasien_id` (`:595`) and `dokter_id` (`:596`).
 * There is no third. The column is on the plan's authoritative list of columns
 * that look like references and carry no foreign key, where it is the **second**
 * entry, and it is declared **bare** below as a plain
 * `->unsignedBigInteger('konsultasi_id')->nullable()`.
 *
 * The temptation is real and must be named: `konsultasi` is table 38, created
 * **one migration before this one**, so
 * `$table->foreign('konsultasi_id')->references('id')->on('konsultasi')` would
 * *work* — the target table exists, the types match, and `migrate:fresh` would
 * stay green. It would also be `extra_foreign_key` drift, permanently, because
 * the verifier compares the live foreign-key set against the DDL's. The ordering
 * argument that once justified adding it is dead: this is not a deferral, and
 * **nothing is owed** — no migration, including
 * `2026_10_01_000076`, may add a constraint here. Recorded in
 * `docs/schema-notes.md` so it is not rediscovered.
 *
 * Because there is no constraint, referential integrity for this column is a
 * service-layer invariant: a medical letter whose `konsultasi_id` points at a
 * deleted consultation is representable, and orphan letters are therefore
 * possible. Note that this cannot be silently repaired by cascading, because a
 * cascade needs a constraint in the first place.
 *
 * **(b) `qr_token` IS `NOT NULL` BUT **NOT UNIQUE** (`:592`) — A GENUINE GAP.**
 * `:592` reads
 *
 *     qr_token VARCHAR(100) NOT NULL COMMENT 'Token QR verifikasi keaslian'
 *
 * There is no `UNIQUE` token anywhere in the statement, so MySQL's implicit
 * unique index is **not** created and two letters can carry the same token. The
 * DDL's own comment says what the column is for — a QR verification token for
 * authenticity — and a verification token that two documents share is not a
 * verification token: scanning either QR resolves both letters. This is a real
 * weakness in the schema, not something this migration may fix, because a `UNIQUE`
 * index is `extra_index` drift. The mitigation is application-level and belongs to
 * todo 34: **generate the value with `Str::uuid()` at write time — never a
 * guessable or sequential stored value** — and add a duplicate check at that
 * point, accepting the race. The identical shape exists on `resep.qr_token`
 * (`:758`) in batch H.
 *
 * `nomor_surat VARCHAR(50) NOT NULL UNIQUE` (`:583`) is different and *is* unique:
 * the human-facing document number is constrained by the DDL. Per rule 10 this is
 * an *inline* `UNIQUE`, so MySQL names the index after the column while
 * `$table->unique()` would name it `surat_keterangan_nomor_surat_unique`; the two
 * are the same constraint and the verifier compares it by semantics.
 *
 * **(c) `tipe` is a FOUR-value ENUM in the DDL's exact order (`:585`)** — `surat_sakit`,
 * `surat_sehat`, `surat_rujukan`, `surat_kematian` — with `NOT NULL` and **no
 * `DEFAULT`**, so the column is genuinely required at insert time and this
 * migration adds no default; supplying one would be drift. The fourth value is a
 * death certificate and has no counterpart in the rest of the schema, so nothing
 * in the database restricts which patients a `surat_kematian` may be issued to.
 *
 * **(d) `jumlah_hari` IS `TINYINT UNSIGNED` (`:590`), NOT a signed `TINYINT`, and it
 * is nullable.** The unsigned flag means a negative day count is impossible, and
 * nullable means "the letter states no period" — which is the normal case for
 * `surat_keterangan` types that describe a moment rather than a window. So `NULL`
 * and `0` are different statements and must not be collapsed. The value is the
 * length of the `tanggal_mulai`-to-`tanggal_selesai` window (`:588`-`:589`, both
 * `DATE NULL`), and it is **not** generated or checked by the database: a
 * `jumlah_hari` that disagrees with the two dates is representable, and the
 * unsigned flag catches only negatives, not disagreement. `TINYINT UNSIGNED` also
 * caps the value at 255, so a period longer than 255 days cannot be recorded.
 * Both dates being nullable while `jumlah_hari` is nullable too means a
 * `surat_kematian` needs none of the three.
 *
 * `isi TEXT NULL` (`:591`) is the letter's body and `file_url VARCHAR(500) NULL`
 * (`:593`) is the rendered PDF or scan. They are independent: a letter may have
 * body text and no file, a file and no body text, both, or neither.
 *
 * **(e) `dibuat_at` ONLY — no `diubah_at`, so there is no raw `ALTER` here.** `:594`
 * is `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and the statement
 * ends at `:595`-`:596` with the two foreign keys. This table is therefore one of
 * the 19 "dibuat_at only" tables, which is why
 * `docs/migration-order.md` rule 5's raw
 * `ALTER TABLE … MODIFY diubah_at … ON UPDATE CURRENT_TIMESTAMP` does **not** apply:
 * there is no `diubah_at` column to modify, and adding one would be a
 * `missing_column` plus an `extra_column` pair. Declaring `$table->timestamps()`
 * would be exactly that mistake. There is also **no** `dihapus_at` — only `users`
 * (`:148`) and `pasien` (`:249`) get soft deletes — so revoking a letter is
 * handled by `rujukan.status` (`terpakai` / `kedaluwarsa`) on table 41, not by a
 * soft delete.
 *
 * Both foreign keys carry no `ON DELETE` clause (`:595`-`:596`), so each
 * materialises MySQL's implicit `NO ACTION` (`RESTRICT` for DML). Both targets —
 * `pasien` (20) and `dokter` (31) — exist by the time this migration runs.
 * **Nothing here is deferred**, so the *Deferred constraints* table in
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
        Schema::create('surat_keterangan', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // Inline UNIQUE in the DDL (:583) -> compared by semantics, rule 10.
            $table->string('nomor_surat', 50)->unique();

            // NO FOREIGN KEY, BY CONTRACT. The DDL (:584) declares a bare nullable
            // unsigned BIGINT and its only two FOREIGN KEY clauses (:595, :596)
            // are on pasien_id and dokter_id. `konsultasi` is table 38 and already
            // exists, so ->constrained() here would succeed and still be
            // extra_foreign_key drift. See the class docblock (a).
            $table->unsignedBigInteger('konsultasi_id')->nullable();

            // Four values, and NO default: :585 declares none.
            $table->enum('tipe', ['surat_sakit', 'surat_sehat', 'surat_rujukan', 'surat_kematian']);
            $table->unsignedBigInteger('pasien_id');
            $table->unsignedBigInteger('dokter_id');
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();

            // TINYINT UNSIGNED: no negative day count, capped at 255. Nullable, and
            // NULL ("no stated period") is not 0. See the class docblock (d).
            $table->unsignedTinyInteger('jumlah_hari')->nullable();
            $table->text('isi')->nullable();

            // NOT NULL but NOT UNIQUE - a real gap in the DDL (:592). The
            // application must generate this with Str::uuid() and duplicate-check
            // it; a UNIQUE index would be extra_index drift. See (b).
            $table->string('qr_token', 100)->comment('Token QR verifikasi keaslian');

            $table->string('file_url', 500)->nullable();

            // This table has NO diubah_at, so $table->timestamps() and the raw
            // ON UPDATE ALTER of rule 5 are both wrong here. See the class docblock (e).
            $table->timestamp('dibuat_at')->useCurrent();

            // No ON DELETE clause on either (DDL :595-:596) -> implicit RESTRICT.
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('dokter_id')->references('id')->on('dokter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('surat_keterangan');
    }
};
