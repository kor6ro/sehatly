<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 27 of 75 — `telemedicine_test.sql:340-354`.
 *
 * The second **module-orphaned** table of batch C (`docs/migration-order.md` row
 * 27: `Module: ORPHAN`, `Resource: —`, `Controller: —`). Migration + Model only.
 *
 * `UNIQUE KEY uq_peserta (penjamin_id, nomor_peserta)` (`:353`) is an explicitly
 * **named** composite key, so it is declared as
 * `$table->unique(['penjamin_id', 'nomor_peserta'], 'uq_peserta')`. Named keys are
 * the only ones the verifier compares by name on `(TABLE_NAME, INDEX_NAME)`, so the
 * spelling has to round-trip exactly. Its leftmost column also covers InnoDB's
 * implicit FK-support index requirement for the `penjamin_id` foreign key, so MySQL
 * creates no second index on that column.
 *
 * **`faskes_rujukan_id` is a bare nullable `BIGINT UNSIGNED` with NO foreign
 * key, by design — and none is owed.** The column's own comment says "Faskes
 * tingkat 1 (untuk BPJS)" and it clearly points at `faskes`, but the SQL
 * declares no `FOREIGN KEY` for it (`telemedicine_test.sql:346`) and the plan's
 * authoritative no-foreign-key list records it as carrying none; the only
 * constraint this batch defers is `fk_vital_rm`. `faskes` is SQL table 28
 * (`:360`), authored in batch D (todo 10), so the ordering is **not** the
 * reason — the column is unconstrained because the contract says so. Do not
 * widen it to `foreignId()` semantics and do not register it as deferred:
 * registration would promise a constraint the DDL never declares.
 *
 * `dibuat_at` only — no `diubah_at`, so no raw `ON UPDATE` `ALTER` is issued.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pasien_penjamin', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('pasien_id');
            $table->unsignedSmallInteger('penjamin_id');
            $table->string('nomor_peserta', 30)->comment('13 digit untuk BPJS');
            $table->enum('kelas_rawat', ['kelas_1', 'kelas_2', 'kelas_3'])->nullable();

            // Bare by design: the SQL declares no FOREIGN KEY for this column
            // (`telemedicine_test.sql:346`), and `faskes` only arrives in
            // todo 10 — so nothing is deferred here and none is owed.
            $table->unsignedBigInteger('faskes_rujukan_id')->nullable()
                ->comment('Faskes tingkat 1 (untuk BPJS)');

            $table->date('masa_berlaku_akhir')->nullable();
            $table->boolean('status_aktif')->default(true);
            $table->string('file_kartu', 500)->nullable();
            $table->timestamp('dibuat_at')->useCurrent();
            $table->foreign('pasien_id')->references('id')->on('pasien')->cascadeOnDelete();
            $table->foreign('penjamin_id')->references('id')->on('master_penjamin');
            $table->unique(['penjamin_id', 'nomor_peserta'], 'uq_peserta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pasien_penjamin');
    }
};
