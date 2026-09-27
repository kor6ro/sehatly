<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 19 of 75 — `telemedicine_test.sql:204-212`.
 *
 * Deliberately reproduced with **no UNIQUE on `token_hash`** (`:207`) and
 * **no `device_id` column**. Two load-bearing limitations follow, and both
 * are recorded in `docs/schema-notes.md` because they look like omissions a
 * later reader would "fix":
 *
 *  1. Every refresh is a full table scan — rotation must do an
 *     application-level `WHERE token_hash = ?` existence check inside the
 *     rotation transaction (the model for todo 45's idempotency work).
 *  2. Per-device token revocation is impossible — there is no column to
 *     scope a `dicabut` update to one device, so revocation is always per
 *     user (all tokens) or per token (one row).
 *
 * `dibuat_at` only; there is **no `diubah_at`**. Do **not** add either a
 * unique index or a `device_id` — the verifier would report both as drift.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_refresh_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 255);
            $table->dateTime('kedaluwarsa_at');
            $table->boolean('dicabut')->default(false);
            $table->timestamp('dibuat_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_refresh_tokens');
    }
};
