<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 38 of 75 — `telemedicine_test.sql:536-561`.
 *
 * First table of batch F. 19 columns, three foreign keys and one named index. The
 * body is `:537-560` (24 lines) and the two-line `status` ENUM at `:542-543` is a
 * single column, so 24 - 1 = 23 declarations covering 19 columns plus 3
 * `FOREIGN KEY` lines and 1 `INDEX` line.
 *
 * **(a) `booking_id` IS `NULL UNIQUE`, AND THE UNIQUE IS THE POINT.**
 * `:538` reads
 *
 *     booking_id BIGINT UNSIGNED NULL UNIQUE COMMENT 'NULL = fitur "Tanya Dokter" instan 24 jam'
 *
 * so the column is nullable *and* carries an inline `UNIQUE`. Both halves are
 * reproduced, and the combination is what makes the two flows on this table
 * coexist:
 *
 *   - a **booked** consultation names its `booking`, and the `UNIQUE` is what
 *     makes "one consultation per booking" a **database guarantee** rather than a
 *     service-layer convention — a second row for the same booking is refused by
 *     MySQL with error 1062, whatever the service code does;
 *   - the instant **"Tanya Dokter"** flow (24/7, no slot, no appointment) has no
 *     booking to point at, so it writes `booking_id = NULL` — and MySQL allows an
 *     unlimited number of `NULL`s in a `UNIQUE` index, because `NULL` is never
 *     equal to `NULL`. The constraint therefore constrains exactly the rows it
 *     should and none of the rows it must not.
 *
 * The asymmetry is not theoretical and is the acceptance test of this todo: two
 * rows sharing one non-null `booking_id` fail, three rows sharing
 * `booking_id = NULL` all succeed. **Do not "normalise" this column** — making it
 * `NOT NULL` would break the instant flow, and dropping the `UNIQUE` would let a
 * booking spawn two consultations. Per `docs/migration-order.md` rule 10 this is
 * an *inline* `UNIQUE`, so MySQL names the index after the column while
 * `$table->unique()` would name it `konsultasi_booking_id_unique`; the two are the
 * same constraint and the verifier compares it by semantics, so the idiomatic
 * `->unique()` is correct.
 *
 * **(b) `tipe` has NO DEFAULT (`:541`) and `status` has exactly one (`:542-543`).**
 * `tipe` is a three-value ENUM — `chat`, `video_call`, `telepon` — and
 * `:541` declares `NOT NULL` with no `DEFAULT` clause, so the column is genuinely
 * required at insert time and this migration adds no default; supplying one would
 * be drift. Note this is the **third** distinct service-type vocabulary in the
 * schema and it is not the same set as either of the other two: `booking.tipe_layanan`
 * (37, `:506`) is `('chat','video_call','kunjungan_klinik','home_visit')` and
 * `dokter_jadwal.tipe_layanan` (35, `:474`) is `('online','klinik','home_visit')`.
 * The only member shared with `booking` is `chat`, and `'telepon'` here has no
 * counterpart at all. There is no mapping table, so translating a booked service
 * type into a consultation type is hand-written service-layer code.
 *
 * `status` is a **six**-value ENUM, in the DDL's exact order — ENUM order is the
 * sort index and it is compared: `menunggu_dokter`, `berlangsung`,
 * `menunggu_resep`, `selesai`, `dibatalkan`, `gagal`, `NOT NULL DEFAULT
 * 'menunggu_dokter'`. The default is the queue state, so a consultation row is
 * created already waiting for a doctor and the service layer does not have to
 * write the column. `menunggu_resep` is a distinct state from `selesai`: a
 * consultation that has ended but is still waiting on a prescription must not be
 * read as complete.
 *
 * **(c) `room_id` is the video-SDK room and is nullable, and no provider is
 * integrated (`:544`).** The DDL's own comment is
 * `'ID room video SDK (Agora/Twilio/100ms)'`. The plan's guardrail forbids
 * adding a real video SDK, so the column is the integration point and its value
 * comes from a stub behind an interface: `chat` and `telepon` consultations leave
 * it `NULL`, which is why it is nullable and why `NULL` must not be read as
 * "missing data".
 *
 * **(d) THE FOUR SOAP COLUMNS ARE SPELLED DIFFERENTLY FROM `rekam_medis`' FOUR, AND
 * THE TWO SETS ARE INDEPENDENT COPIES.** Here they are `catatan_subjektif`,
 * `catatan_objektif`, **`catatan_asessment`** and `catatan_plan`
 * (`:548`-`:551`) — note `catatan_asessment` has **one** `s`, and all four carry a
 * `catatan_` prefix. `rekam_medis` (42, `:637`-`:640`) spells the same four
 * concepts `subjektif`, `objektif`, `asesmen` and `plan` with no prefix and a
 * different `a`-spelling. The two vocabularies are genuinely different and must
 * not be conflated. Nothing in the schema couples the two sets, so
 * `rekam_medis` is the authoritative copy and todo 32 must write these four as a
 * denormalised copy at completion time; there is no trigger and no constraint
 * that would keep them in step, so a partial write is representable.
 *
 * Immediately below the SOAP block, `diagnosis_kerja VARCHAR(255) NULL` (`:552`)
 * is a short working-diagnosis label and `saran_tindak_lanjut TEXT NULL`
 * (`:553`) is the free-text follow-up advice. Both are nullable and both are
 * frequently empty mid-consultation. `total_durasi_detik INT UNSIGNED NULL`
 * (`:547`) is **unsigned** and nullable: unsigned because a negative duration is
 * meaningless, nullable because the duration is only knowable once the
 * consultation has finished — it is derived from `mulai_at`/`selesai_at`
 * (`:545`-`:546`, both `DATETIME NULL`) and is not maintained by the database.
 *
 * **(e) The three foreign keys carry no `ON DELETE` clause (`:557`-`:559`).**
 * Each therefore materialises MySQL's implicit `NO ACTION` — `RESTRICT` for DML —
 * and is declared without a delete rule below. All three targets exist by the time
 * this migration runs: `booking` is table 37 (batch E, immediately before this
 * batch), `pasien` is 20 (batch C) and `dokter` is 31 (batch D). **Nothing here is
 * deferred**, so the *Deferred constraints* table in `docs/schema-notes.md` gains
 * no row and still holds exactly its one `fk_vital_rm` entry.
 *
 * `INDEX idx_konsultasi_pasien (pasien_id, status)` (`:560`) is the only key the
 * DDL names besides the primary key and the inline `UNIQUE`, so it is compared
 * **by name** on `(TABLE_NAME, INDEX_NAME)` and its column order is part of the
 * contract: `pasien_id` first, `status` second. It is the "this patient's
 * consultations, newest-status-first" access path, and its leftmost column is why
 * the per-patient list is an index range scan. Do not reorder it and do not add
 * anything to it.
 *
 * `diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER` that
 * `docs/migration-order.md` rule 5 mandates: Laravel 13 has no Blueprint helper for
 * it, and this table is one of only 16 with a `dibuat_at`/`diubah_at` pair. There
 * is **no** `dihapus_at` here — only `users` (`:148`) and `pasien` (`:249`) get
 * soft deletes — so cancellation is a `status` value, not a soft delete, and
 * `$table->softDeletes()` must not be used.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('konsultasi', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // NULL for the instant "Tanya Dokter" flow, UNIQUE so a booking can
            // never spawn two consultations. The DDL's own comment on :538,
            // copied verbatim (rule 12). MySQL allows unlimited NULLs here.
            $table->unsignedBigInteger('booking_id')->nullable()->unique()->comment('NULL = fitur "Tanya Dokter" instan 24 jam');
            $table->unsignedBigInteger('pasien_id');
            $table->unsignedBigInteger('dokter_id');

            // Three values, and NO default: :541 declares none.
            $table->enum('tipe', ['chat', 'video_call', 'telepon']);

            // SIX values, in the DDL's exact order (:542-543). The default is the
            // queue state; 'menunggu_resep' is not the same as 'selesai'.
            $table->enum('status', [
                'menunggu_dokter',
                'berlangsung',
                'menunggu_resep',
                'selesai',
                'dibatalkan',
                'gagal',
            ])->default('menunggu_dokter');

            // The DDL's own comment on :544, copied verbatim (rule 12). NULL for a
            // chat or telephone consultation; the video SDK is stubbed per spec.
            $table->string('room_id', 100)->nullable()->comment('ID room video SDK (Agora/Twilio/100ms)');

            // Both DATETIME NULL: an instant chat has no scheduled start.
            $table->dateTime('mulai_at')->nullable();
            $table->dateTime('selesai_at')->nullable();

            // UNSIGNED, so a negative duration is impossible. Nullable, because the
            // duration is only knowable at completion and is not derived here.
            $table->unsignedInteger('total_durasi_detik')->nullable();

            // The four SOAP columns. `catatan_asessment` has ONE 's', and this is a
            // different vocabulary from rekam_medis (:637-:640). See (d).
            $table->text('catatan_subjektif')->nullable();
            $table->text('catatan_objektif')->nullable();
            $table->text('catatan_asessment')->nullable();
            $table->text('catatan_plan')->nullable();

            // Short working-diagnosis label and free-text follow-up advice. Both
            // nullable, and both usually empty mid-consultation.
            $table->string('diagnosis_kerja', 255)->nullable();
            $table->text('saran_tindak_lanjut')->nullable();

            // DECIMAL(12,2) money, unsigned is meaningless here and not declared.
            $table->decimal('biaya_konsultasi', 12, 2)->default(0);

            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // No ON DELETE clause on any of the three (DDL :557-:559) -> implicit
            // RESTRICT. booking (37) is one row earlier in the previous batch.
            $table->foreign('booking_id')->references('id')->on('booking');
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('dokter_id')->references('id')->on('dokter');

            // Named in the DDL, so compared by name. Column order is the contract:
            // pasien_id first, status second. See the class docblock.
            $table->index(['pasien_id', 'status'], 'idx_konsultasi_pasien');
        });

        DB::statement('ALTER TABLE konsultasi MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('konsultasi');
    }
};
