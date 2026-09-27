<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 9 of 75 — `telemedicine_test.sql:109-112`.
 *
 * NON-AUTO-INCREMENT primary key (`:110`); the seed supplies the 7 explicit ids.
 * The DDL's `COMMENT` on `nama` is documentation, not contract (the verifier
 * does not compare comment text), but it is copied so the migration reads
 * next to the SQL. No timestamps.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_hubungan_keluarga', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('nama', 50)->comment('Pasangan/Anak/Orang Tua/Saudara/Lainnya');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_hubungan_keluarga');
    }
};
