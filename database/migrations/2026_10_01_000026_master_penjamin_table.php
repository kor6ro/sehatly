<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 26 of 75 — `telemedicine_test.sql:333-338`.
 *
 * One of the **14 module-orphaned tables** (plan guardrail, "Must NOT have"): the
 * migration and the Model exist, and no Resource, Controller or route ever will.
 * `docs/migration-order.md` row 26 records `Module: ORPHAN`, `Resource: —`,
 * `Controller: —`. The table still needs a full migration because
 * `pasien_penjamin.penjamin_id` has a foreign key to it and todo 18's
 * `PenjaminSeeder` inserts 6 rows into it.
 *
 * Two parity notes:
 *
 *  - `id` is `SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` — **not** `TINYINT` and
 *    not `BIGINT`. `$table->id()` emits `BIGINT UNSIGNED` and is forbidden
 *    project-wide (`docs/migration-order.md` rule 1); `unsignedSmallInteger()` with
 *    `->autoIncrement()->primary()` is the exact reproduction.
 *  - It has **no `dibuat_at` and no `diubah_at`** at all (`:333-338`), so this is
 *    one of the 39 tables whose model must set `public $timestamps = false` in
 *    todo 19. The plan's own "28" figure is wrong — see Appendix A.8 and
 *    `master_penjamin` is one of the 11 tables it omits from that list.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_penjamin', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->autoIncrement()->primary();
            $table->string('nama', 150);
            $table->enum('tipe', ['bpjs', 'asuransi_swasta', 'perusahaan', 'tunai']);
            $table->boolean('status_aktif')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_penjamin');
    }
};
