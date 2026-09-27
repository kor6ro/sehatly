<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 34 of 75 — `telemedicine_test.sql:457-464`.
 *
 * Last table of batch D, and **in scope for M1 for a specific reason**: the
 * spec's `GET /api/v1/dokter/{id}` (todo 22) must return the doctor's education
 * history, ordered by graduation year descending. The DDL's column is
 * `tahun_lulus` (`:462`) — "lulus" as in *lulusan*, not `tahun_lulusan` and not
 * `tahun_kelulusan` — so the Resource and the model must use that exact name.
 *
 * `jenjang` is a seven-value ENUM in the DDL's order (`:460`):
 * `s1_kedokteran`, `profesi`, `sp1`, `sp2`, `s2`, `s3`, `lainnya`. ENUM member
 * order is the sort index and is compared, so it must be reproduced exactly —
 * `s1_kedokteran` (not `s1`), and `lainnya` (not `other`) last.
 *
 * `tahun_lulus SMALLINT UNSIGNED NULL` (`:462`) is unsigned and nullable: an
 * in-progress qualification has no graduation year yet. The unsigned flag keeps
 * a negative year out; Laravel's signed `smallInteger()` would admit one.
 *
 * `dokter_id BIGINT UNSIGNED NOT NULL` with `ON DELETE CASCADE` (`:463`), and
 * unlike `dokter_spesialisasi` (32) there is **no** unique constraint here at
 * all. A doctor may legitimately have several `sp1` rows from different
 * institutions or re-taken exams, so the duplicates are data, not drift, and no
 * application-level de-duplication rule is warranted.
 *
 * No `dibuat_at` / `diubah_at` at all — this table is in the 39-table "neither"
 * group of `docs/migration-order.md` rule 4, so todo 19's model needs
 * `$timestamps = false`. `dokter` (31) is the immediately preceding migration, so
 * **nothing here is deferred** and the *Deferred constraints* registry in
 * `docs/schema-notes.md` gains no row.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dokter_pendidikan', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('dokter_id');
            $table->enum('jenjang', ['s1_kedokteran', 'profesi', 'sp1', 'sp2', 's2', 's3', 'lainnya']);
            $table->string('institusi', 200);

            // UNSIGNED and NULL: a qualification in progress has no year yet.
            $table->unsignedSmallInteger('tahun_lulus')->nullable();
            $table->foreign('dokter_id')->references('id')->on('dokter')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dokter_pendidikan');
    }
};
