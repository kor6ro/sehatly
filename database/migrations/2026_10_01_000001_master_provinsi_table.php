<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 1 of 75 — `telemedicine_test.sql:58-62`.
 *
 * `id` is `TINYINT UNSIGNED`, NOT `$table->id()` (which emits `BIGINT UNSIGNED`).
 * No `dibuat_at` / `diubah_at`: the SQL declares none, so `$table->timestamps()`
 * must not be applied.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_provinsi', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->autoIncrement()->primary();
            $table->char('kode', 2)->comment('Kode Kemendagri/BPS');
            $table->string('nama', 100);
            $table->unique('kode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_provinsi');
    }
};
