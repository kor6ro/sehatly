<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 7 of 75 — `telemedicine_test.sql:99-102`.
 *
 * NON-AUTO-INCREMENT primary key (`:100`); the seed supplies the 8 explicit ids.
 * No timestamps. `nama` is free text, not an ENUM, in the DDL.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_pendidikan', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('nama', 50);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_pendidikan');
    }
};
