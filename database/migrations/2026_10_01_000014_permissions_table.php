<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 14 of 75 — `telemedicine_test.sql:157-161`.
 *
 * `id` is `SMALLINT UNSIGNED` (`:158`), so `unsignedSmallInteger()` plus
 * explicit `->autoIncrement()->primary()` — never `$table->id()`. The table
 * carries neither `dibuat_at` nor `diubah_at`. The `kode` column's `UNIQUE`
 * (`:159`) is inline in the DDL; the verifier compares it by uniqueness
 * semantics, not by name. Row content lives in todo 4's `RbacSeeder`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->autoIncrement()->primary();
            $table->string('kode', 100)->unique()->comment('cth: rekam_medis.lihat, resep.buat');
            $table->string('nama', 100);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
