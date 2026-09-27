<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 16 of 75 — `telemedicine_test.sql:171-177`.
 *
 * A pure composite-PK join table: **no `id` column**, `PRIMARY KEY
 * (user_id, role_id)` (`:174`), no `dibuat_at` / `diubah_at`. `user_id` is
 * `BIGINT UNSIGNED` (`users.id` is `BIGINT UNSIGNED`; see table 12) while
 * `role_id` is `SMALLINT UNSIGNED`. Both foreign keys are `ON DELETE CASCADE`
 * (`:175-176`). `user_id` is the leftmost column of the composite PK so
 * InnoDB covers that FK itself; the uncovered `role_id` FK gets its
 * engine-created support index, which the verifier's implied-index rule
 * forgives — do not add a covering index by hand.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedSmallInteger('role_id');
            $table->primary(['user_id', 'role_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
