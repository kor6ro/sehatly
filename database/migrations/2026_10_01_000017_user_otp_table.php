<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 17 of 75 — `telemedicine_test.sql:179-188`.
 *
 * `kode_hash` (`:182`) stores a **hash of the OTP, never the plaintext OTP**
 * — the column name and the DDL's own comment say hash, so any service must
 * hash-then-compare (todo 20), never store or read the code itself.
 *
 * `dibuat_at` only; there is **no `diubah_at`** (`:186` ends the DDL's column
 * list) and **no attempt-counter column anywhere in the table**. OTP
 * brute-force protection is therefore application-only and lives entirely in
 * todo 20's rate limiter — recorded in `docs/schema-notes.md` so that no
 * later reader assumes the database enforces it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_otp', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('kode_hash', 255)->comment('Simpan hash, bukan OTP asli');
            $table->enum('tujuan', ['verifikasi_telepon', 'verifikasi_email', 'reset_kata_sandi', 'login']);
            $table->dateTime('kedaluwarsa_at');
            $table->boolean('sudah_dipakai')->default(false);
            $table->timestamp('dibuat_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_otp');
    }
};
