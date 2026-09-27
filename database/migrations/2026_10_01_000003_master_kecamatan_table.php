<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 3 of 75 — `telemedicine_test.sql:72-78`.
 *
 * `id` is `SMALLINT UNSIGNED`; `kabupaten_kota_id` is `SMALLINT UNSIGNED`
 * because its parent is, so `$table->foreignId()` would emit the wrong width.
 * The SQL declares no index on `kabupaten_kota_id`; InnoDB adds its own.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_kecamatan', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->autoIncrement()->primary();
            $table->unsignedSmallInteger('kabupaten_kota_id');
            $table->char('kode', 7);
            $table->string('nama', 100);
            $table->unique('kode');
            $table->foreign('kabupaten_kota_id')->references('id')->on('master_kabupaten_kota');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_kecamatan');
    }
};
