<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 13 of 75 — `telemedicine_test.sql:151-155`.
 *
 * `id` is `SMALLINT UNSIGNED` (`:152`), so `unsignedSmallInteger()` plus
 * explicit `->autoIncrement()->primary()` — never `$table->id()`. The table
 * carries neither `dibuat_at` nor `diubah_at`. Row content lives in todo 4's
 * `RbacSeeder`; no seeder is shipped here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->autoIncrement()->primary();
            $table->string('nama', 50)->unique();
            $table->string('deskripsi', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
