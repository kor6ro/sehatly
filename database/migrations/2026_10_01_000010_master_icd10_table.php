<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 10 of 75 — `telemedicine_test.sql:115-120`.
 *
 * `id` is `INT UNSIGNED` (not `BIGINT`). The DDL carries BOTH an inline
 * `UNIQUE (kode)` (`:117`) and a redundant, separately named
 * `INDEX idx_icd10 (kode)` (`:119`); both are reproduced, because the named key
 * is compared by name and dropping it would be drift. The redundancy is legal in
 * MySQL and the UNIQUE is what the seeder relies on.
 *
 * `idx_icd10` is reused as an index name on `pasien_riwayat_penyakit:291`, which
 * is legal because index names are scoped per table. No timestamps.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_icd10', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement()->primary();
            $table->string('kode', 8);
            $table->string('deskripsi', 255);
            $table->unique('kode');
            $table->index('kode', 'idx_icd10');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_icd10');
    }
};
