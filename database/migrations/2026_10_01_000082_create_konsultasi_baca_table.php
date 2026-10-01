<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F08 — `konsultasi_baca`, appended to `telemedicine_test.sql` as section `[17]`
 * (`:1350-1364`). It is table 76 of 76, and the owner's decision that created it
 * is recorded in `docs/schema-notes.md`.
 *
 * ## Why a table and not a per-message column
 *
 * `konsultasi_chat.dibaca_at` (`:574`) records that ONE message was read. The
 * product question "Dibaca" answers - "how far has the other party read?" - is
 * per PARTICIPANT, not per message: a participant who has read a transcript has
 * read everything up to a moment, and stamping 500 historic rows on every open
 * is both a write storm and a lie about rows that arrived mid-write. This table
 * stores that moment once per `(konsultasi_id, user_id)`.
 *
 * **Two columns of `last_read_at` state, not one reading side.** The table is
 * per PARTICIPANT, so a consultation has at most one row per party and the
 * question "what has the other party read?" is a lookup by the other party's
 * `user_id`. There is deliberately no `pengirim_tipe` here: `konsultasi` already
 * names the two profile rows, and duplicating the side would be a second copy of
 * a fact one hop away.
 *
 * `dibaca_at` on `konsultasi_chat` is NOT replaced. The per-message stamp is
 * what a message bubble renders, and F08 keeps it working; the upsert of this
 * row is an ADDITION to the same endpoint. See `KonsultasiService::tandaiDibaca()`.
 *
 * ## The UNIQUE key is the whole integrity story
 *
 * `uq_baca (konsultasi_id, user_id)` (`:1361`) is what makes "at most one read
 * marker per participant" a database guarantee rather than a service-layer
 * convention, so the write can be an idempotent upsert. Its column order is the
 * contract and is also why `konsultasi_id` needs no implicit FK-support index:
 * it is the leftmost column. `user_id` is NOT a leftmost prefix, so InnoDB
 * creates the implicit `konsultasi_baca_user_id_foreign` key that
 * `SchemaDiffer::diffIndexes()` treats as implied by the matched foreign key.
 *
 * ## Both foreign keys CASCADE, and that is the opposite of `konsultasi_chat`
 *
 * `konsultasi_chat` cascades from the consultation and RESTRICTs the sender
 * (`:576`, `:577`), because a transcript is clinical evidence whose author must
 * survive. A read marker is neither: it notes that somebody looked, it carries
 * no clinical content, and it is meaningless without its consultation. So both
 * `konsultasi_id` and `user_id` are `ON DELETE CASCADE` (`:1362-1363`). A user
 * account is soft-deleted in this schema anyway (`users.dihapus_at`, `:148`), so
 * the user cascade is a safety net rather than a delete path.
 *
 * ## `diubah_at` needs the raw `ON UPDATE` ALTER
 *
 * Laravel 13 has no Blueprint helper for `ON UPDATE CURRENT_TIMESTAMP`, so the
 * column is declared `->useCurrent()` and then repaired with `DB::statement()`,
 * exactly as `2026_10_01_000038_konsultasi_table.php` does. Without it,
 * `verify-schema` reports `column_extra` drift on `diubah_at`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('konsultasi_baca', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            $table->unsignedBigInteger('konsultasi_id');
            $table->unsignedBigInteger('user_id');

            // Not nullable: "never read" is the ABSENCE of a row, so a row that
            // exists always names the moment its participant last read.
            $table->dateTime('last_read_at');

            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // Named in the DDL, so compared by name. Column order is the contract:
            // konsultasi_id first, user_id second - the same order the lookup uses.
            $table->unique(['konsultasi_id', 'user_id'], 'uq_baca');

            // Both cascade (DDL :1362-1363), unlike konsultasi_chat's asymmetric pair:
            // a read marker has no clinical value of its own.
            $table->foreign('konsultasi_id')->references('id')->on('konsultasi')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE konsultasi_baca MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('konsultasi_baca');
    }
};
