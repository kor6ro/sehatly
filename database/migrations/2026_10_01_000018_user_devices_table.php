<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 18 of 75 — `telemedicine_test.sql:190-202`.
 *
 * `dibuat_at` only; there is **no `diubah_at`**. The uniqueness is an
 * explicitly **named** key — `UNIQUE KEY uq_device (user_id, device_id)`
 * (`:201`) — so it is declared `$table->unique([...], 'uq_device')`: named
 * keys are compared by name, unlike inline `UNIQUE`, and the name must round-
 * trip. The surrogate `id` stays the primary key; this is one of the seven
 * "UNIQUE but surrogate `id`" tables the plan expects.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('device_id', 255);
            $table->enum('platform', ['android', 'ios', 'web']);
            $table->string('fcm_token', 255)->nullable();
            $table->string('app_versi', 20)->nullable();
            $table->boolean('aktif')->default(true);
            $table->dateTime('last_active_at')->nullable();
            $table->timestamp('dibuat_at')->useCurrent();
            $table->unique(['user_id', 'device_id'], 'uq_device');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
