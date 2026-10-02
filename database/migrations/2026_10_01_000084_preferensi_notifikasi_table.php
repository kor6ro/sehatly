<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F11 contract table 77 of 80 — `telemedicine_test.sql:1370-1382`.
 *
 * `preferensi_notifikasi` holds ONE row per user: the quiet-hours half of the
 * F11 notification preferences. Permission approval is recorded in
 * `web/ux/patterns/F11.md` §12 and the DDL was APPENDED as section `[18]` at the
 * end of the reference file, so every pre-existing line citation is untouched.
 *
 * **What is deliberately NOT here.** There is no `in_app` column: in-app delivery
 * is unconditional and not a preference, so the matrix stores only
 * `preferensi_notifikasi_tipe.push_aktif`. There is no `bahasa`/`ringkasan`
 * column either — the "optional summary, default off" decision from the pattern
 * is a push-body policy, not a stored preference, and the approved schema does
 * not carry it.
 *
 * **`diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER`**
 * (`docs/migration-order.md` rule 5): Laravel 13 has no Blueprint helper for it.
 * Both timestamp columns are `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`, so
 * `$table->timestamps()` must NOT be used — it would emit
 * `created_at`/`updated_at` instead of `dibuat_at`/`diubah_at`.
 *
 * **`uq_preferensi_notifikasi_user` is the database's one-row-per-user
 * guarantee**, and it is also the InnoDB support index for the `user_id` foreign
 * key: `user_id` is its leftmost (and only) column, so MySQL creates no implicit
 * index for the FK.
 *
 * Exposed by `GET/PUT /api/v1/profil/notifikasi` (F11) — lazy-upsert, defaults
 * returned when the row is absent.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('preferensi_notifikasi', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1371).
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:1372) — one row per user.
            $table->unsignedBigInteger('user_id');

            // TINYINT(1) NOT NULL DEFAULT 0 (:1373) — the quiet-hours master
            // switch, default OFF (quiet hours are inactivity, so "off" is the
            // least surprising lazy default).
            $table->boolean('jam_tenang_aktif')->default(false);

            // ENUM(...3 values...) NOT NULL DEFAULT 'setiap_hari' (:1374) —
            // exact order: setiap_hari, hari_kerja, kustom. ENUM order is the
            // sort index and is compared by the verifier.
            $table->enum('jam_tenang_mode', ['setiap_hari', 'hari_kerja', 'kustom'])->default('setiap_hari');

            // TIME NOT NULL DEFAULT '21:00:00' (:1375) / '06:00:00' (:1376) —
            // wall-clock times, not instants: the window is interpreted in
            // `zona_waktu` when the scheduler evaluates it. A window that wraps
            // midnight (start > end) is the normal shape and is not an error.
            $table->time('jam_tenang_mulai')->default('21:00:00');
            $table->time('jam_tenang_selesai')->default('06:00:00');

            // VARCHAR(40) NOT NULL DEFAULT 'Asia/Jakarta' (:1377) — an IANA
            // zone name, validated in the application against an allowed set.
            $table->string('zona_waktu', 40)->default('Asia/Jakarta');

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1378-:1379) — the
            // dibuat_at/diubah_at pair; the raw ALTER below adds ON UPDATE.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // UNIQUE KEY uq_preferensi_notifikasi_user (user_id) (:1380) —
            // one row per user, named in the DDL so compared by name. Also the
            // FK support index (leftmost user_id).
            $table->unique(['user_id'], 'uq_preferensi_notifikasi_user');

            // FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            // (:1381) — a preference row is personal and must die with its user.
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE preferensi_notifikasi MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preferensi_notifikasi');
    }
};
