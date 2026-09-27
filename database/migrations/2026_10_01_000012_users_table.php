<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 12 of 75 — `telemedicine_test.sql:132-149`.
 *
 * This replaces the deleted scaffold migration
 * `0001_01_01_000000_create_users_table.php` (plan Appendix A.4, executed in
 * todo 7) and is NOT its column list. The scaffold's `name`, `email`,
 * `email_verified_at`, `password` and `remember_token` are all **absent**; the
 * SQL's own column names replace them (`nama_lengkap`, `email`,
 * `kata_sandi_hash`). `app/Models/User.php` still expects the scaffold shape —
 * that mismatch is todo 19's to resolve and is deliberately not "fixed" here.
 *
 * Three parity-critical decisions in this file:
 *
 *  1. **`email` is `NULL`-able *and* UNIQUE, and that is load-bearing.**
 *     `telemedicine_test.sql:136` writes `email VARCHAR(255) NULL UNIQUE`.
 *     MySQL permits many `NULL`s in a UNIQUE index (a `NULL` never compares
 *     equal to another `NULL`), which is precisely what lets a patient
 *     register with a phone number only. `->nullable()->unique()` reproduces
 *     it; dropping either half would break registration *or* parity.
 *  2. **`dihapus_at` is declared with `$table->softDeletes('dihapus_at')`.**
 *     `Blueprint::softDeletes()` is `timestamp($column, $precision)->nullable()`
 *     in this framework version, so it emits exactly `timestamp NULL`, which
 *     is what `:148` says. A hand-rolled `$table->dateTime('dihapus_at')`
 *     emits `datetime` and is *the* parity break this call exists to prevent.
 *  3. **`diubah_at` needs a raw `ALTER` for `ON UPDATE CURRENT_TIMESTAMP`.**
 *     `docs/migration-order.md` rule 5 mandates the `->useCurrent()` +
 *     follow-up `DB::statement()` pair. This is the first batch in the project
 *     that needs it: batch A declared no timestamps at all.
 *
 * `id` is `BIGINT UNSIGNED`, so the width is spelled out with
 * `unsignedBigInteger()` rather than relying on `$table->id()`; the emitted
 * type is identical, and the explicit form keeps every `id` in the project
 * uniform with the 18 tables whose width differs.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->char('uuid', 36)->unique();
            $table->string('nama_lengkap', 150);
            $table->string('email', 255)->nullable()->unique();
            $table->string('no_telepon', 20)->unique();
            $table->string('kata_sandi_hash', 255)->comment('bcrypt/argon2');
            $table->enum('tipe', ['pasien', 'dokter', 'perawat', 'apoteker', 'kurir', 'admin', 'superadmin'])
                ->default('pasien');
            $table->enum('status', ['pending_verifikasi', 'aktif', 'nonaktif', 'ditangguhkan'])
                ->default('pending_verifikasi');
            $table->string('foto_profil', 500)->nullable();
            $table->enum('bahasa', ['id', 'en'])->default('id');
            $table->boolean('telepon_terverifikasi')->default(false);
            $table->boolean('email_terverifikasi')->default(false);
            $table->dateTime('last_login_at')->nullable();
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();
            $table->softDeletes('dihapus_at');
        });

        DB::statement('ALTER TABLE users MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
