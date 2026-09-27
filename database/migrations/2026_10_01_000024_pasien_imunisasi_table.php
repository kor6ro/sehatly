<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 24 of 75 — `telemedicine_test.sql:300-310`.
 *
 * The simplest table in batch C: one `CASCADE` FK to `pasien`, one `dibuat_at`,
 * no indexes of its own and no `diubah_at`, therefore no raw `ON UPDATE` `ALTER`.
 *
 * `dosis_ke` is `TINYINT UNSIGNED NULL` (`:305`) — unsigned, and nullable, because
 * a vaccination recorded from a paper card often has no dose number on it.
 * `no_batch` is the manufacturer's lot number and is free text, not a reference.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pasien_imunisasi', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('pasien_id');
            $table->string('nama_vaksin', 150);
            $table->date('tanggal');
            $table->unsignedTinyInteger('dosis_ke')->nullable();
            $table->string('no_batch', 50)->nullable();
            $table->string('pemberi', 150)->nullable();
            $table->timestamp('dibuat_at')->useCurrent();
            $table->foreign('pasien_id')->references('id')->on('pasien')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pasien_imunisasi');
    }
};
