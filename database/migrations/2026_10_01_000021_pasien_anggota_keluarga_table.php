<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 21 of 75 — `telemedicine_test.sql:259-272`.
 *
 * Dependents of a patient who have no account of their own: the SQL's own comment
 * at `:258` is "Anggota keluarga (didaftarkan oleh pasien, TANPA akun sendiri)",
 * which is why this table has a `pasien_id` FK but no `user_id`.
 *
 * `dibuat_at` only — this is one of the 19 "dibuat_at only" tables, so there is
 * no `diubah_at` and therefore no `ON UPDATE CURRENT_TIMESTAMP` raw `ALTER` here
 * (`docs/migration-order.md` rule 5 applies only to the 16 tables that have the
 * pair). `nik` is `CHAR(16) NULL` and carries **no** UNIQUE: a family member may
 * be a minor with no NIK of their own, and the same person may legitimately
 * appear on more than one patient's record, so the SQL declares it bare.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pasien_anggota_keluarga', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('pasien_id')->comment('Pemegang akun');
            $table->unsignedTinyInteger('hubungan_id');
            $table->char('nik', 16)->nullable();
            $table->string('nama_lengkap', 150);
            $table->enum('jenis_kelamin', ['L', 'P']);
            $table->date('tanggal_lahir');
            $table->string('no_telepon', 20)->nullable();
            $table->text('catatan_alergi')->nullable();
            $table->timestamp('dibuat_at')->useCurrent();
            $table->foreign('pasien_id')->references('id')->on('pasien')->cascadeOnDelete();
            $table->foreign('hubungan_id')->references('id')->on('master_hubungan_keluarga');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pasien_anggota_keluarga');
    }
};
