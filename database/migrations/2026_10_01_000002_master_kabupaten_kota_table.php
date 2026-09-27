<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 2 of 75 — `telemedicine_test.sql:64-70`.
 *
 * `id` is `SMALLINT UNSIGNED` and `provinsi_id` is `TINYINT UNSIGNED` (the
 * parent's width), so neither `$table->id()` nor `$table->foreignId()` is
 * correct. The SQL declares no index on `provinsi_id`; InnoDB creates one
 * itself when the foreign key is added, and so does the reference import.
 * Declaring an extra index here would diverge from the DDL.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_kabupaten_kota', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->autoIncrement()->primary();
            $table->unsignedTinyInteger('provinsi_id');
            $table->char('kode', 4);
            $table->string('nama', 100);
            $table->unique('kode');
            $table->foreign('provinsi_id')->references('id')->on('master_provinsi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_kabupaten_kota');
    }
};
