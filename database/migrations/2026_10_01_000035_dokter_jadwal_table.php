<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 35 of 75 — `telemedicine_test.sql:470-488`.
 *
 * First table of batch E: 14 columns (`:471`-`:484`), two foreign keys and one
 * explicitly named index. Its primary key is a plain surrogate `id` while the key
 * that actually identifies a schedule row — `(dokter_id, hari, jam_mulai)` — is
 * not enforced by anything, which is the origin of every availability caveat
 * below. Four things here are not expressible by reaching for the obvious helper:
 *
 *  1. **`hari` is `TINYINT UNSIGNED`, and the unsigned flag is load-bearing.**
 *     `:475` reads `hari TINYINT UNSIGNED NOT NULL COMMENT '0=Minggu s.d. 6=Sabtu'`.
 *     The UNSIGNED is not decoration: signed, MySQL would accept `hari = -1`, and
 *     `date('w')` never returns a negative, so a negative value can only be a bug
 *     — precisely the drift the plan's todo-11 failure QA probes. Laravel's
 *     `tinyInteger()` is signed; `unsignedTinyInteger()` is the only correct call.
 *  2. **The `0=Minggu s.d. 6=Sabtu` comment is a contract, not decoration, and it
 *     maps 1:1 onto PHP's `date('w')`.** `date('w')` returns `0` for Sunday
 *     through `6` for Saturday, so the DDL's own Indonesian labels
 *     (`0` Minggu = Sunday, `6` Sabtu = Saturday) are the identical range in the
 *     identical order — no translation table and no off-by-one between the
 *     database and the date library. `SlotAvailabilityService` and todo 26's
 *     Resource therefore read a weekday with `date('w', $ts)` and write it
 *     straight back, and the label for a stored `hari` is a `match` over
 *     `['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu']`, **not** a
 *     1-based list — a 1-based array is the one way to get this silently wrong.
 *     **The named constant and the `docs/timezone-policy.md` entry that the plan
 *     asks for are todo 19's and todo 19's, not this migration's**: that file does
 *     not exist in the repository yet, it is not created here, and neither the
 *     constant nor the policy note is owed by batch E.
 *  3. **Two more UNSIGNED integer columns, each with a different nullability.**
 *     `durasi_slot_menit SMALLINT UNSIGNED NOT NULL DEFAULT 15` (`:478`) and
 *     `kuota_per_sesi SMALLINT UNSIGNED NULL` (`:479`). `kuota_per_sesi` is
 *     nullable on purpose — a NULL quota means *unlimited* / *not yet set*, which
 *     is a different statement from `0` (block the session) and must not be
 *     collapsed into it. Both are `unsignedSmallInteger()`; the signed
 *     `smallInteger()` would admit `durasi_slot_menit = -1`.
 *  4. **Nothing in the schema keeps a schedule row legal.** `hari` has no
 *     `CHECK (hari BETWEEN 0 AND 6)` — UNSIGNED only rejects negatives, so `7` is
 *     representable — and no constraint prevents two active rows for the same
 *     `(dokter_id, hari)` with overlapping `jam_mulai`/`jam_selesai`, nor
 *     `berlaku_sampai >= berlaku_mulai`. `MySQL TIME` also admits values past
 *     `24:00:00`, so a slot wrapping midnight is representable and breaks naive
 *     `H:i:s` parsing. `SlotAvailabilityService` (todo 26) owns all of that
 *     validation, and the same shape is recorded in `docs/schema-notes.md`.
 *
 * `INDEX idx_jadwal (dokter_id, hari, status_aktif)` (`:487`) is explicitly named
 * in the DDL, so it is compared by name on `(TABLE_NAME, INDEX_NAME)` and the
 * column order is part of the contract: `dokter_id` first because the query is
 * always per-doctor, then `hari`, then the `status_aktif` filter. Its leftmost
 * column also satisfies InnoDB's implicit FK-support requirement for the
 * `dokter_id` foreign key, so MySQL creates no second index on that column. Do
 * not reorder it, do not add it a second time under a generated name, and do not
 * "help" it into a UNIQUE — there is no unique on this table at all, by design
 * (a doctor may legitimately have two `klinik` rows on the same weekday at
 * different facilities).
 *
 * The two foreign keys differ in their delete rule and both are reproduced as
 * written: `dokter_id` is `ON DELETE CASCADE` (`:485`), so removing a doctor
 * removes their schedule; `faskes_id` declares **no** `ON DELETE` clause
 * (`:486`), so the live `DELETE_RULE` is MySQL's implicit `NO ACTION` — which is
 * `RESTRICT` for DML — and a facility that still has `klinik` schedule rows
 * cannot be deleted. `faskes_id` is nullable with the DDL's
 * own comment `NULL = layanan online murni` (`:473`) — a pure online schedule has
 * no facility, and `tipe_layanan = 'online'` is the default (`:474`) that
 * normally accompanies it, though nothing couples the two.
 *
 * `diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER` that
 * `docs/migration-order.md` rule 5 mandates: Laravel 13 has no Blueprint helper
 * for it, and this table is one of only 16 with a `dibuat_at`/`diubah_at` pair.
 * There is **no** `dihapus_at` here — only `users` (`:148`) and `pasien` (`:249`)
 * get soft deletes — so `$table->softDeletes()` must not be used.
 *
 * Both foreign keys resolve before this migration runs: `dokter` is table 31 and
 * `faskes` is table 28, both from batch D. **Nothing here is deferred**, so the
 * *Deferred constraints* registry in `docs/schema-notes.md` gains no row from this
 * table.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dokter_jadwal', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('dokter_id');

            // The DDL's own comment on :473, copied verbatim (rule 12).
            $table->unsignedBigInteger('faskes_id')->nullable()->comment('NULL = layanan online murni');
            $table->enum('tipe_layanan', ['online', 'klinik', 'home_visit'])->default('online');

            // UNSIGNED, and the flag is load-bearing: signed would admit hari = -1.
            // 0 = Minggu .. 6 = Sabtu is exactly PHP's date('w') range and order.
            $table->unsignedTinyInteger('hari')->comment('0=Minggu s.d. 6=Sabtu');
            $table->time('jam_mulai');
            $table->time('jam_selesai');

            // Both UNSIGNED. NULL kuota_per_sesi means "no quota set", NOT zero.
            $table->unsignedSmallInteger('durasi_slot_menit')->default(15);
            $table->unsignedSmallInteger('kuota_per_sesi')->nullable();
            $table->date('berlaku_mulai');
            $table->date('berlaku_sampai')->nullable();
            $table->boolean('status_aktif')->default(true);
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            $table->foreign('dokter_id')->references('id')->on('dokter')->cascadeOnDelete();

            // No ON DELETE clause in the DDL (:486) -> MySQL's implicit RESTRICT.
            $table->foreign('faskes_id')->references('id')->on('faskes');

            // Named in the DDL, so compared by name. Do not reorder or re-add.
            $table->index(['dokter_id', 'hari', 'status_aktif'], 'idx_jadwal');
        });

        DB::statement('ALTER TABLE dokter_jadwal MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dokter_jadwal');
    }
};
