<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 11 of 75 — `telemedicine_test.sql:122-126`.
 *
 * `id` is `INT UNSIGNED`, matching `master_icd10`. Unlike `master_icd10` this
 * table has no secondary `INDEX` at all in the DDL, only the inline
 * `UNIQUE (kode)` — do not add one. No timestamps.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_icd9cm', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement()->primary();
            $table->string('kode', 8);
            $table->string('deskripsi', 255);
            $table->unique('kode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_icd9cm');
    }
};
