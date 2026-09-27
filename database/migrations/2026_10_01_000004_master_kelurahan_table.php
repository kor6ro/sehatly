<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 4 of 75 — `telemedicine_test.sql:80-86`.
 *
 * `id` is `MEDIUMINT UNSIGNED` (the only one in the schema) while
 * `kecamatan_id` stays `SMALLINT UNSIGNED` — the widths differ and neither is
 * the default. The SQL declares no index on `kecamatan_id`; InnoDB adds its own.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_kelurahan', function (Blueprint $table) {
            $table->unsignedMediumInteger('id')->autoIncrement()->primary();
            $table->unsignedSmallInteger('kecamatan_id');
            $table->char('kode', 10);
            $table->string('nama', 100);
            $table->unique('kode');
            $table->foreign('kecamatan_id')->references('id')->on('master_kecamatan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_kelurahan');
    }
};
