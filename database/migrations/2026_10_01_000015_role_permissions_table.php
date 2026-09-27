<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 15 of 75 — `telemedicine_test.sql:163-169`.
 *
 * A pure composite-PK join table: **no `id` column**, `PRIMARY KEY
 * (role_id, permission_id)` (`:166`), no `dibuat_at` / `diubah_at`. Both
 * foreign keys are `ON DELETE CASCADE` (`:167-168`). `role_id` is the
 * leftmost column of the composite PK so InnoDB covers that FK itself; the
 * uncovered `permission_id` FK gets its engine-created support index, which
 * the verifier's implied-index rule forgives — do not add a covering index
 * by hand.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->unsignedSmallInteger('role_id');
            $table->unsignedSmallInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
