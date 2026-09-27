<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 36 of 75 — `telemedicine_test.sql:490-496`.
 *
 * The whole-day holiday block for a doctor: four columns and one foreign key. It
 * is the narrowest table in batch E and the one where the **absence** of a
 * constraint is the interesting part, so this docblock is mostly about what is
 * deliberately not here.
 *
 * **THERE IS NO UNIQUE INDEX ON `(dokter_id, tanggal)`, and none is owed.** This
 * was confirmed by reading the full statement — `:490` to `:496` inclusive, seven
 * lines — which contains one `id` column, three more columns, a single
 * `FOREIGN KEY` line and the closing `) ENGINE=InnoDB;`. There is no `UNIQUE`
 * token anywhere in those seven lines, and no `INDEX` token either, so the only
 * index this table has beyond the primary key is the one **MySQL creates itself**
 * for the `dokter_id` foreign key (InnoDB requires an index on the referencing
 * columns; see the FK-support-index section of `docs/schema-notes.md`). Adding
 * `$table->unique(['dokter_id', 'tanggal'])` "to be safe" would therefore be
 * `extra_index` **drift**, and the parity verifier would exit `1` naming it.
 *
 * **The duplicate guard is consequently an application-level invariant, owned by
 * `SlotAvailabilityService` (todo 26), not by the schema.** Before inserting a
 * holiday row the service performs an existence check on
 * `(dokter_id, tanggal)` — `SELECT 1 FROM dokter_libur WHERE dokter_id = ? AND
 * tanggal = ?` — inside the same transaction that writes the row. This is a
 * check-then-act, so it is race-prone under concurrency, and that race is
 * accepted rather than papered over, for two reasons that are properties of this
 * schema and not of the implementation:
 *
 *  1. **A duplicate row is harmless to every consumer.** Both rows block the same
 *     date, and the availability query that reads this table is a set membership
 *     test ("is `tanggal_kunjungan` in this doctor's blocked dates?"), which is
 *     idempotent over duplicates. Nothing sums, counts or orders these rows, and
 *     `alasan` (`:494`) is a free-text annotation on each row rather than an
 *     aggregate, so no total can be corrupted by the second copy — which is the
 *     difference between a duplicate that is merely untidy here and one that would
 *     be destructive on a table that is summed.
 *  2. **A unique index would be the wrong tool even if it were permitted.** The
 *     SQL is read-only law, so this is academic — but it is the reason the gap is
 *     documented rather than left as a bare omission, and it is the same argument
 *     that makes `booking` (37) deliberately unique-free on its slot triple.
 *
 * **Holiday blocking is whole-day only.** There is no start time, no end time and
 * no slot identifier, so a single blocked slot on an otherwise working date
 * cannot be represented at all — a half-day closure is inexpressible, and the
 * application-level answer is a `dokter_jadwal` row (35) that is absent or
 * `status_aktif = 0` for that `hari`, not a partial `dokter_libur` row. Holiday
 * lookups also have no index to help them: the FK-support index covers
 * `dokter_id` alone, so a "which dates is this doctor blocked" scan filters
 * `tanggal` in the service layer. Both facts are recorded in
 * `docs/schema-notes.md`.
 *
 * `dokter_id BIGINT UNSIGNED NOT NULL` with `ON DELETE CASCADE` (`:492`, `:495`),
 * so deleting a doctor removes their holidays and no orphan can survive. `dokter`
 * is table 31, already migrated by batch D, so **nothing here is deferred** and
 * the *Deferred constraints* registry in `docs/schema-notes.md` gains no row.
 *
 * **No `dibuat_at` and no `diubah_at` at all** — `:490-496` declares neither, which
 * puts this table in the 39-table "neither" group of
 * `docs/migration-order.md` rule 4. So `$table->timestamps()` is not called below,
 * and todo 19's `DokterLibur` model needs `$timestamps = false`; a model that
 * defaults to `$timestamps = true` would target two columns that do not exist.
 * There is no `dihapus_at` here either, so no soft deletes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Four columns only. No timestamps (rule 4), no soft deletes, and
        // deliberately NO unique on (dokter_id, tanggal) - see the class docblock.
        Schema::create('dokter_libur', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('dokter_id');
            $table->date('tanggal');
            $table->string('alasan', 200)->nullable();
            $table->foreign('dokter_id')->references('id')->on('dokter')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dokter_libur');
    }
};
