<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 32 of 75 — `telemedicine_test.sql:437-445`.
 *
 * A many-to-many bridge from `dokter` (31) to `master_spesialisasi` (30), with
 * an `is_utama` flag so a doctor's "primary" specialisation is a row property
 * rather than a derived ordering. It has an `id` of its own (`:438`) — unlike the
 * composite-PK join tables `role_permissions`, `user_roles`, `dokter_faskes`
 * (33) and `lab_paket_item` (57) — so `$table->unsignedBigInteger('id')` is
 * correct here and must not be replaced with a composite primary key.
 *
 * **`UNIQUE KEY uq_dokter_spes (dokter_id, spesialisasi_id)` (`:444`) is an
 * explicitly named composite key**, so it is declared as
 * `$table->unique(['dokter_id', 'spesialisasi_id'], 'uq_dokter_spes')` — named keys
 * are the only ones the verifier compares by name on `(TABLE_NAME, INDEX_NAME)`,
 * so the spelling has to round-trip exactly. Note the trailing `es`: the DDL
 * writes `uq_dokter_spes`, not the `uq_dokter_ses` that the plan's todo-10 prose
 * and several secondary transcriptions use. The verifier caught the difference on
 * the first run of `sehatly:verify-schema --tables=dokter_spesialisasi`, and the
 * DDL wins. Its leftmost column also covers InnoDB's implicit FK-support index
 * requirement for the `dokter_id` foreign key, so MySQL creates no second index
 * on that column.
 *
 * `spesialisasi_id` is `SMALLINT UNSIGNED` (`:440`) to match
 * `master_spesialisasi.id`'s own SMALLINT width. `foreignId()` here would emit
 * `BIGINT UNSIGNED` and InnoDB would reject the constraint outright — the same
 * trap `pasien`'s four `TINYINT UNSIGNED` master FKs carry in batch C.
 *
 * The uniqueness is what makes `is_utama` sound: a doctor may hold many
 * specialisations but the `(dokter_id, spesialisasi_id)` pair may appear once, so
 * "the primary specialisation" is at most one row per doctor. Nothing in the
 * schema constrains it to being *at least* one, or stops two rows per doctor
 * from both having `is_utama = 1`; both are application-level invariants for
 * todo 22's `GET /dokter/{id}` (`is_utama` first, then the rest).
 *
 * No `dibuat_at` / `diubah_at` at all — this table is in the 39-table "neither"
 * group of `docs/migration-order.md` rule 4, so todo 19's model needs
 * `$timestamps = false`. Both foreign keys resolve inside this batch — `dokter`
 * (31) and `master_spesialisasi` (30) are both created earlier inside batch D, as
 * stated at the top of this docblock — so **nothing here is deferred** and the
 * *Deferred constraints* registry in `docs/schema-notes.md` gains no row.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dokter_spesialisasi', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('dokter_id');

            // SMALLINT UNSIGNED, matching master_spesialisasi.id exactly.
            $table->unsignedSmallInteger('spesialisasi_id');
            $table->boolean('is_utama')->default(false);
            $table->foreign('dokter_id')->references('id')->on('dokter')->cascadeOnDelete();
            $table->foreign('spesialisasi_id')->references('id')->on('master_spesialisasi');
            $table->unique(['dokter_id', 'spesialisasi_id'], 'uq_dokter_spes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dokter_spesialisasi');
    }
};
