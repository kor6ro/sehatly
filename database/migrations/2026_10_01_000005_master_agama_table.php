<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 5 of 75 — `telemedicine_test.sql:89-92`.
 *
 * NON-AUTO-INCREMENT primary key: the DDL writes `id TINYINT UNSIGNED PRIMARY KEY`
 * with no `AUTO_INCREMENT` (`:90`) and the seed inserts explicit ids, so
 * `->autoIncrement()` is deliberately absent. The eventual model needs
 * `public $incrementing = false` (todo 19) and the seeder must use
 * `DB::table()->insert()` with an explicit id (todo 18) — Eloquent `create()`
 * would send a NULL id and fail with MySQL 1366.
 *
 * No `dibuat_at` / `diubah_at`; the SQL declares none.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_agama', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('nama', 50);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_agama');
    }
};
