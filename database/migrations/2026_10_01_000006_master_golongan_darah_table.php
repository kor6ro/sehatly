<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 6 of 75 — `telemedicine_test.sql:94-97`.
 *
 * NON-AUTO-INCREMENT primary key (`:95`), same as `master_agama`. `kode` is a
 * 4-value ENUM whose member order is semantic — it is the sort index — so the
 * values are transcribed in the DDL's order: A, B, AB, O. No timestamps.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_golongan_darah', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->enum('kode', ['A', 'B', 'AB', 'O']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_golongan_darah');
    }
};
