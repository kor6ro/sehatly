<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 29 of 75 — `telemedicine_test.sql:388-396`.
 *
 * A narrow child of `faskes` (table 28, the immediately preceding migration, so
 * the ordering here is not a constraint). Six columns, one foreign key, and
 * **no `dibuat_at` / `diubah_at` pair at all** — this table belongs to the
 * 39-table "neither" group in `docs/migration-order.md` rule 4, so
 * `$table->timestamps()` must never be applied here and todo 19's `DokterFaskes`
 * sibling models need `$timestamps = false`.
 *
 * `faskes_id` is `BIGINT UNSIGNED NOT NULL` with `ON DELETE CASCADE` (`:395`),
 * matching `faskes.id`'s own width. The DDL gives this FK **no** covering index,
 * so InnoDB creates one named after the column; that is the engine's doing, not
 * this migration's, and the verifier's implied-index rule forgives it. Do not
 * add a covering index by hand — the verifier reports a real `extra_index` as
 * drift.
 *
 * `harga DECIMAL(12,2) NULL` is nullable: a facility's service catalogue may
 * carry services whose price is "ask at the counter". `DECIMAL(12,2)` is the same
 * money type as `dokter.biaya_konsultasi_online` in the next-but-two migration,
 * deliberately, so a price and a fee sort and add identically.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('faskes_layanan', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('faskes_id');
            $table->string('nama_layanan', 150);
            $table->text('deskripsi')->nullable();
            $table->decimal('harga', 12, 2)->nullable();
            $table->boolean('status_aktif')->default(true);
            $table->foreign('faskes_id')->references('id')->on('faskes')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faskes_layanan');
    }
};
