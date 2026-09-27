<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 23 of 75 — `telemedicine_test.sql:286-298`.
 *
 * **`icd10_kode` is a bare indexed column with NO foreign key** (`:291`), and that
 * is the single most "fixable-looking" defect in this batch:
 * `master_icd10` already exists (batch A, migration 10), the column is exactly the
 * master's `kode` type, so `->constrained('master_icd10', 'kode')` would *work* —
 * and it would be drift. The SQL declares `INDEX idx_icd10 (icd10_kode)` (`:297`)
 * and no `FOREIGN KEY` line for it, so no constraint is added. Adding one makes
 * `verify-schema` exit 1 with `extra_foreign_key`; the same reasoning is why the
 * index is declared explicitly instead of relying on a `constrained()` shorthand.
 *
 * `idx_icd10` is the same *name* the SQL reuses on `master_icd10` (`:119`), which
 * is legal in MySQL because index names are scoped per table. The verifier keys
 * named-key comparison on `(TABLE_NAME, INDEX_NAME)`, so both are checked
 * independently and neither shadows the other.
 *
 * `tahun_terdiagnosis` is `YEAR NULL` (`:292`) — `$table->year()`, not
 * `smallInteger`, and not `date()`.
 *
 * `dibuat_at` only, so no raw `ON UPDATE` `ALTER` is issued.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pasien_riwayat_penyakit', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('pasien_id');
            $table->enum('tipe', ['pribadi', 'keluarga']);
            $table->string('nama_penyakit', 150);

            // Indexed, never constrained. telemedicine_test.sql:291 declares the
            // column and :297 the index; there is no FOREIGN KEY for it.
            $table->string('icd10_kode', 8)->nullable();

            $table->year('tahun_terdiagnosis')->nullable();
            $table->enum('status', ['aktif', 'kronis', 'sembuh'])->default('aktif');
            $table->text('keterangan')->nullable();
            $table->timestamp('dibuat_at')->useCurrent();
            $table->foreign('pasien_id')->references('id')->on('pasien')->cascadeOnDelete();
            $table->index('icd10_kode', 'idx_icd10');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pasien_riwayat_penyakit');
    }
};
