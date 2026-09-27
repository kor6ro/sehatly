<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 25 of 75 — `telemedicine_test.sql:312-330`.
 *
 * **`rekam_medis_id` is created as a column with NO foreign key, on purpose.**
 * The SQL's own `CREATE TABLE` body (`:312-330`) declares
 * `rekam_medis_id BIGINT UNSIGNED NULL` and only a `FOREIGN KEY (pasien_id)`, and
 * the real constraint arrives 800 lines later in the SQL's section `[14]`
 * (`:1161-1163`):
 *
 *     ALTER TABLE pasien_tanda_vital
 *       ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)
 *       REFERENCES rekam_medis(id) ON DELETE SET NULL;
 *
 * That is not a stylistic choice, it is a dependency ordering one: `rekam_medis` is
 * SQL table 42, authored in batch G (todo 13), and this migration runs at position
 * 25. Declaring the constraint here would make `migrate:fresh` fail with MySQL
 * 1824 ("Failed to open the referenced table 'rekam_medis'") and break the
 * acceptance criterion of this todo and of the eight todos that follow it in this
 * wave. Migration `2026_10_01_000076_add_deferred_foreign_keys_table.php` (todo 18)
 * adds it, with the SQL's own constraint name `fk_vital_rm` — the one name in the
 * whole schema the verifier compares foreign keys by name rather than by
 * semantics, precisely because `ALTER TABLE`-declared names survive.
 *
 * The same column therefore appears in the live schema with no matching row in
 * `information_schema.REFERENTIAL_CONSTRAINTS` until todo 18. That absence is the
 * correct state, not drift, and this todo's negative QA asserts it.
 *
 * The three DECIMALs keep their own scales: `suhu DECIMAL(4,1)` (`:319`),
 * `tinggi_cm DECIMAL(5,1)` (`:322`), `berat_kg DECIMAL(5,2)` (`:323`),
 * `glukosa_darah DECIMAL(6,1)` (`:324`). `spo2` is `TINYINT UNSIGNED` (`:321`) and
 * is deliberately **not** cast to boolean anywhere — `spo2 = 0` is a measurement,
 * not a false value.
 *
 * `INDEX idx_vital_pasien (pasien_id, diukur_at)` (`:329`) is declared with its
 * SQL name. Its leftmost column also satisfies InnoDB's implicit FK-support index
 * requirement for the `pasien_id` foreign key, so MySQL creates no second index.
 *
 * `dibuat_at` only, so no raw `ON UPDATE` `ALTER` is issued.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pasien_tanda_vital', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('pasien_id');

            // Column only. The FOREIGN KEY arrives in migration 76 per SQL
            // section [14] (:1161-1163), because rekam_medis is table 42.
            $table->unsignedBigInteger('rekam_medis_id')->nullable();

            $table->unsignedSmallInteger('sistolik')->nullable();
            $table->unsignedSmallInteger('diastolik')->nullable();
            $table->unsignedSmallInteger('nadi')->nullable();
            $table->decimal('suhu', 4, 1)->nullable();
            $table->unsignedSmallInteger('laju_pernapasan')->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->decimal('tinggi_cm', 5, 1)->nullable();
            $table->decimal('berat_kg', 5, 2)->nullable();
            $table->decimal('glukosa_darah', 6, 1)->nullable();
            $table->enum('sumber', ['mandiri', 'dokter', 'perawat', 'iot_device'])
                ->default('mandiri');
            $table->dateTime('diukur_at');
            $table->timestamp('dibuat_at')->useCurrent();
            $table->foreign('pasien_id')->references('id')->on('pasien')->cascadeOnDelete();
            $table->index(['pasien_id', 'diukur_at'], 'idx_vital_pasien');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pasien_tanda_vital');
    }
};
