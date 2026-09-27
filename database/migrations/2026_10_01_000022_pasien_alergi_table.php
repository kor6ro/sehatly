<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 22 of 75 — `telemedicine_test.sql:274-284`.
 *
 * Two things here look like omissions and are not:
 *
 *  - **`dicatat_oleh_user_id` has NO foreign key** (`:281`). The column exists and
 *    is a nullable `BIGINT UNSIGNED`, but the SQL declares no `FOREIGN KEY` line
 *    for it, so none is added. `->constrained('users')` here would be a parity
 *    break the verifier reports as `extra_foreign_key`; deleting the column
 *    because it "looks unused" would be `missing_column`. Reproduced as declared.
 *  - **`nama_alergen` is free text, not a reference to `master_obat`.** It is
 *    `VARCHAR(150) NOT NULL` and deliberately so: a patient's allergen is often
 *    something the pharmacy catalogue has never heard of (a cleaning product, a
 *    food additive, a branded supplement), and the `tipe_alergen` enum already
 *    includes `'lainnya'`. Constraining it to `master_obat.id` would lose data.
 *    Drug-interaction checking in todo 38 therefore matches on the text, not on
 *    an id.
 *
 * `dibuat_at` only — one of the 19 tables with no `diubah_at`, so no raw
 * `ON UPDATE` `ALTER` is issued.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pasien_alergi', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('pasien_id');
            $table->enum('tipe_alergen', ['obat', 'makanan', 'lingkungan', 'lainnya']);
            $table->string('nama_alergen', 150);
            $table->string('reaksi', 255)->nullable();
            $table->enum('keparahan', ['ringan', 'sedang', 'berat', 'anafilaksis'])
                ->default('ringan');

            // Bare by design: telemedicine_test.sql:281 declares no FOREIGN KEY.
            $table->unsignedBigInteger('dicatat_oleh_user_id')->nullable();

            $table->timestamp('dibuat_at')->useCurrent();
            $table->foreign('pasien_id')->references('id')->on('pasien')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pasien_alergi');
    }
};
