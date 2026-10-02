<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F11 contract table 79 of 80 — `telemedicine_test.sql:1395-1417`.
 *
 * A user-owned scheduled reminder: `jenis = 'obat'` (with optional catalogue
 * link `obat_id`, dose text and times per day) or `jenis = 'janji_temu'` (with
 * optional `booking_id`). `waktu` is a JSON list of `"HH:MM"` wall-clock
 * strings, interpreted in `zona_waktu` by the `pengingat:kirim` scheduler.
 *
 * **What the schema does not enforce, and the service must:**
 * - No `CHECK` ties `jenis` to `obat_id`/`booking_id`/`dosis`/`jumlah_per_hari`,
 *   or `obat_id` to `booking_id` being mutually exclusive. Ownership of
 *   `obat_id` (catalogue row, no owner) and `booking_id` (must belong to the
 *   caller) is a FormRequest rule.
 * - `waktu`'s JSON shape is not validated by MySQL; the endpoint validates it
 *   as a non-empty list of `HH:MM`, sorted and deduplicated, and rejects
 *   free-text prescription rules rather than parsing them (F11 §7.11).
 * - `lama_hari` is nullable: `NULL` means "no end date", and `0` is rejected by
 *   validation (a reminder that never fires is not a reminder).
 *
 * **Both nullable foreign keys are `ON DELETE RESTRICT`** as approved: deleting
 * a catalogue drug or a booking that a reminder still names must be blocked, not
 * silently dissolve the reminder. `user_id` cascades, because a reminder is
 * personal and unreachable once its owner is gone.
 *
 * InnoDB creates implicit support indexes for `obat_id` and `booking_id` (they
 * are not leftmost in any declared index); `SchemaDiffer` treats those as
 * implied by the matched foreign keys, so they must not be "covered" by an
 * invented index.
 *
 * `diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER`
 * (`docs/migration-order.md` rule 5).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pengingat', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1396).
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:1397) — the owner.
            $table->unsignedBigInteger('user_id');

            // ENUM('obat','janji_temu') NOT NULL (:1398), no default.
            $table->enum('jenis', ['obat', 'janji_temu']);

            // VARCHAR(200) NOT NULL (:1399) — the row's headline (drug name or
            // appointment title). Medical text; never copied into a push body.
            $table->string('judul', 200);

            // VARCHAR(255) NULL (:1400) — free-text note / prescription rule.
            // Stored verbatim, never parsed into a schedule.
            $table->string('keterangan', 255)->nullable();

            // BIGINT UNSIGNED NULL (:1401) with RESTRICT (:1413) — optional
            // catalogue link; a manual reminder may name a drug not in the
            // catalogue, which is why it is nullable.
            $table->unsignedBigInteger('obat_id')->nullable();

            // BIGINT UNSIGNED NULL (:1402) with RESTRICT (:1414) — optional
            // appointment link; ownership is validated by the request.
            $table->unsignedBigInteger('booking_id')->nullable();

            // VARCHAR(50) NULL (:1403) / TINYINT UNSIGNED NULL (:1404).
            $table->string('dosis', 50)->nullable();
            $table->unsignedTinyInteger('jumlah_per_hari')->nullable();

            // DATE NOT NULL (:1405) — the first day the reminder can fire.
            $table->date('tanggal_mulai');

            // SMALLINT UNSIGNED NULL (:1406) — NULL = open-ended.
            $table->unsignedSmallInteger('lama_hari')->nullable();

            // JSON NOT NULL (:1407) — a list of "HH:MM" strings. MySQL 8 does
            // not allow a DEFAULT on JSON, and the shape is application-owned.
            $table->json('waktu');

            // VARCHAR(40) NOT NULL DEFAULT 'Asia/Jakarta' (:1408).
            $table->string('zona_waktu', 40)->default('Asia/Jakarta');

            // ENUM('aktif','nonaktif','selesai') NOT NULL DEFAULT 'aktif'
            // (:1409), in the DDL's exact order.
            $table->enum('status', ['aktif', 'nonaktif', 'selesai'])->default('aktif');

            // TIMESTAMP pair (:1410-:1411); ON UPDATE added by the raw ALTER.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE (:1412).
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // FOREIGN KEY (obat_id) REFERENCES master_obat(id) ON DELETE RESTRICT (:1413).
            $table->foreign('obat_id')->references('id')->on('master_obat')->restrictOnDelete();

            // FOREIGN KEY (booking_id) REFERENCES booking(id) ON DELETE RESTRICT (:1414).
            $table->foreign('booking_id')->references('id')->on('booking')->restrictOnDelete();

            // Named indexes, exact column order (:1415-:1416): both are
            // caller-scoped lookups (list by status; the scheduler's active
            // window scan).
            $table->index(['user_id', 'status'], 'idx_pengingat_user_status');
            $table->index(['user_id', 'tanggal_mulai'], 'idx_pengingat_user_tanggal');
        });

        DB::statement('ALTER TABLE pengingat MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengingat');
    }
};
