<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 30 of 75 — `telemedicine_test.sql:402-407`.
 *
 * A reference table with a **SMALLINT** primary key, so `$table->id()` (which
 * emits `BIGINT UNSIGNED`) is forbidden here by
 * `docs/migration-order.md` rule 1 — and so is `foreignId()` on the two columns
 * that point back at it in `dokter_spesialisasi` (table 32). AUTO_INCREMENT **is**
 * present (`:403`), so this is not one of the five non-incrementing master
 * tables in rule 2 and its model does not need `$incrementing = false`.
 *
 * No `dibuat_at` / `diubah_at` at all: this is one of the 11 tables the plan's
 * own tally omitted and the corrected 39-table "neither" group (rule 4), so todo
 * 19's `MasterSpesialisasi` needs `$timestamps = false`. Todo 18's
 * `SpesialisasiSeeder` ports the 16 `:1234-1240` rows.
 *
 * **`tipe`'s value list is a different vocabulary from `dokter.tipe`'s, and the
 * two overlap in exactly one member.** `:406` is
 * `ENUM('dokter_umum','spesialis','subspesialis')` whereas `dokter.tipe` (`:412`)
 * is `ENUM('dokter_umum','dokter_spesialis','dokter_gigi','psikolog','bidan',
 * 'perawat','apoteker')` — so `'spesialis'` here is **not** `'dokter_spesialis'`
 * there, and a service that maps one to the other by string comparison silently
 * finds nothing. Recorded in `docs/schema-notes.md` for todo 22, whose
 * `GET /dokter` filters by `spesialisasi` and `tipe`. No mapping table exists in
 * the schema, so the bridge has to be written by hand in the service layer.
 *
 * `kode VARCHAR(10) NOT NULL UNIQUE` is an **inline** `UNIQUE` (`:404`), so per
 * rule 10 it is compared by semantics and ordered column list, not by name —
 * MySQL names the index `kode` and Laravel names it
 * `master_spesialisasi_kode_unique`; both denote the same constraint. The seed
 * codes are the Kemenkes `SP.*` scheme (`SP.PD`, `SP.A`, `SP.OG`, …), which is
 * why the column is `VARCHAR(10)` and not `VARCHAR(8)` like the ICD code columns
 * in batch A.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_spesialisasi', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->autoIncrement()->primary();
            $table->string('kode', 10)->unique()
                ->comment('SP.PD, SP.A, SP.OG, dst');
            $table->string('nama', 100);
            $table->enum('tipe', ['dokter_umum', 'spesialis', 'subspesialis']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_spesialisasi');
    }
};
