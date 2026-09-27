<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 8 of 75 — `telemedicine_test.sql:104-107`.
 *
 * NON-AUTO-INCREMENT primary key (`:105`). The 4-value ENUM is transcribed in
 * the DDL's order, which is semantic: belum_menikah, menikah, cerai_hidup,
 * cerai_mati. No timestamps.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_status_pernikahan', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->enum('nama', ['belum_menikah', 'menikah', 'cerai_hidup', 'cerai_mati']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_status_pernikahan');
    }
};
