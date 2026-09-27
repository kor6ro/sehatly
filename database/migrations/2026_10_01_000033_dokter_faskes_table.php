<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 33 of 75 — `telemedicine_test.sql:447-455`.
 *
 * A **composite-PK join table with no `id` column at all** (`:447-452`). This is
 * one of exactly four such tables in the contract — `role_permissions` (15),
 * `user_roles` (16), `dokter_faskes` (33) and `lab_paket_item` (57) — and one of
 * the plan's explicit acceptance criteria for todo 10 is that
 * `SHOW CREATE TABLE dokter_faskes` has no `id`. Do not add one, and do not
 * reach for `$table->id()`: it would emit `BIGINT UNSIGNED` *and* create the
 * column the DDL does not have.
 *
 * `PRIMARY KEY (dokter_id, faskes_id)` (`:452`) is declared as
 * `$table->primary(['dokter_id', 'faskes_id'])` in the DDL's own order, which
 * matters: the leftmost column is `dokter_id`, so InnoDB's implicit FK-support
 * index for that FK is the primary key itself and no second index is created. The
 * reversed order would still be a legal primary key but would move the implicit
 * index, so the column order is reproduced rather than normalised.
 *
 * Both foreign keys are `ON DELETE CASCADE` (`:453-454`): deleting a doctor or
 * deleting a facility removes the affiliation row. That is the only reason
 * `faskes_layanan` (29) and this table can be trusted to leave no orphans, and
 * it is why the migration order matters only in that `dokter` (31) and `faskes`
 * (28) must both exist first — which they do, in the same batch, so **nothing
 * here is deferred** and the *Deferred constraints* registry in
 * `docs/schema-notes.md` gains no row.
 *
 * Both `is_utama` and `status_aktif` default to `0`, so an inserted affiliation
 * is inactive and not primary. There is no unique beyond the PK, so the same
 * doctor-facility pair cannot be duplicated, but a doctor may have several
 * active facilities and only "one primary" is an application-level invariant.
 *
 * This table is **module-orphaned** (`docs/migration-order.md` row 33:
 * `Module: ORPHAN`, `Resource: —`, `Controller: —`): nothing in Modules 1-5 reads
 * it directly, but `GET /dokter/{id}` returns the doctor's facilities with their
 * `faskes` (todo 22), so it must exist and must be modelled. Migration + Model
 * only.
 *
 * No `dibuat_at` / `diubah_at` at all — this table is in the 39-table "neither"
 * group of rule 4, so todo 19's `DokterFaskes` needs `$incrementing = false`, a
 * two-element `$primaryKey` and `$timestamps = false`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No `id`: PRIMARY KEY (dokter_id, faskes_id) only. See the class docblock.
        Schema::create('dokter_faskes', function (Blueprint $table) {
            $table->unsignedBigInteger('dokter_id');
            $table->unsignedBigInteger('faskes_id');
            $table->boolean('is_utama')->default(false);
            $table->boolean('status_aktif')->default(true);
            $table->primary(['dokter_id', 'faskes_id']);
            $table->foreign('dokter_id')->references('id')->on('dokter')->cascadeOnDelete();
            $table->foreign('faskes_id')->references('id')->on('faskes')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dokter_faskes');
    }
};
