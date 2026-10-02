<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F11 contract table 78 of 80 — `telemedicine_test.sql:1384-1393`.
 *
 * Per-user, per-type push switches. **Absence of a row means `push_aktif = 1`**
 * — the effective default is "on" for the four produced types, and the endpoint
 * materialises rows lazily through an upsert rather than seeding four rows per
 * user. The `tipe` ENUM deliberately mirrors only the four produced values
 * (`booking`, `pembayaran`, `resep`, `chat`): `lab`, `promo` and `sistem` exist
 * on `notifikasi.tipe` but have no producer, so they are NOT offered as a
 * preference (F11 §3, §4.3; `NotifikasiTipe::nilaiYangDipakai()`).
 *
 * In-app delivery has no column here on purpose: it is unconditional.
 *
 * **`uq_preferensi_notifikasi_tipe` is `(user_id, tipe)`** — one row per pair —
 * and `user_id` is its leftmost column, so it doubles as the InnoDB support
 * index for the `user_id` foreign key; MySQL creates no implicit index.
 *
 * `diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER`
 * (`docs/migration-order.md` rule 5), and `$table->timestamps()` must NOT be
 * used — it would emit the wrong column names.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('preferensi_notifikasi_tipe', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1385).
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:1386).
            $table->unsignedBigInteger('user_id');

            // ENUM('booking','pembayaran','resep','chat') NOT NULL (:1387) —
            // the four produced types, in the DDL's order, and NO default:
            // omitting the column is an error, not a silent classification.
            $table->enum('tipe', ['booking', 'pembayaran', 'resep', 'chat']);

            // TINYINT(1) NOT NULL DEFAULT 1 (:1388) — an explicit row that says
            // "on" is the same as no row at all; the default is 1 to make that
            // agreement literal.
            $table->boolean('push_aktif')->default(true);

            // TIMESTAMP pair (:1389-:1390); ON UPDATE added by the raw ALTER.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // UNIQUE KEY uq_preferensi_notifikasi_tipe (user_id, tipe) (:1391).
            $table->unique(['user_id', 'tipe'], 'uq_preferensi_notifikasi_tipe');

            // FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE (:1392).
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE preferensi_notifikasi_tipe MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preferensi_notifikasi_tipe');
    }
};
