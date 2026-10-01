<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F02 — drop `uq_consent` from `persetujuan_pdp`, making the table an
 * append-only ledger.
 *
 * ## Why this migration exists at all
 *
 * Migration `2026_10_01_000074` no longer declares the unique key, so a fresh
 * `migrate:fresh` never creates it. But a database that was migrated BEFORE the
 * F02 change still carries it, and `php artisan migrate` would otherwise leave
 * the live schema one index ahead of `telemedicine_test.sql:1144` — which
 * `sehatly:verify-schema` reports as `extra_index` drift, and which would make a
 * same-version withdrawal fail with MySQL 1062. This migration closes that gap.
 *
 * ## The guard is load-bearing
 *
 * `Schema::hasIndex()` is checked first because on a fresh database the index
 * does not exist and an unguarded `dropUnique()` would fail the whole
 * `migrate:fresh`. The guard makes the migration idempotent in both directions:
 * it drops the index exactly once, on the databases that still have it.
 *
 * ## The foreign key is dropped and re-added, and that is not ceremony
 *
 * `uq_consent (user_id, jenis, versi_dokumen)` is the leftmost-prefix index
 * InnoDB uses to support the `user_id` foreign key, so a bare
 * `DROP INDEX uq_consent` fails with MySQL 1553 ("Cannot drop index ... needed
 * in a foreign key constraint"). The migration therefore drops the foreign key,
 * drops the unique key, and re-adds the foreign key - at which point InnoDB
 * creates its own implicit support index on `user_id`, exactly as it does on a
 * fresh `migrate:fresh` where the unique key never existed. The constraint is
 * re-added with the same `ON DELETE CASCADE` and no `ON UPDATE`, so the
 * semantics are unchanged; only the index that supports it is now engine-named
 * rather than the unique key. `SchemaDiffer` treats an engine-created
 * FK-support index as implied, so `sehatly:verify-schema` stays green.
 *
 * ## `down()` re-adds the key, and may legitimately fail
 *
 * Rolling back restores the old schema, so `down()` re-declares the unique key.
 * It will fail with MySQL 1062 if the ledger already holds two rows for the same
 * `(user_id, jenis, versi_dokumen)` — which is exactly the data the new rule
 * exists to allow. That failure is correct rather than a defect: a schema that
 * cannot represent the rows already recorded must not be restored silently. The
 * operator's choice is to delete the duplicate decisions or to stay on the new
 * schema.
 *
 * ## What the new rule is
 *
 * `App\Services\Pdp\PdpConsentService` owns it: current status = the latest
 * recorded row per `(user_id, jenis)` in append (`id`) order; withdrawal is
 * allowed anytime on the SAME version; the same consecutive decision is
 * idempotent; and the active version is the server's, published by
 * `GET /api/v1/pdp/dokumen` from `config/pdp.php`. `docs/schema-notes.md` records
 * the change and why `telemedicine_test.sql:1144` is a comment rather than a
 * deleted line.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasIndex('persetujuan_pdp', 'uq_consent')) {
            return;
        }

        // The unique key is the FK's support index, so it cannot be dropped
        // while the constraint exists. Drop the constraint, drop the key, and
        // re-add the constraint - InnoDB then creates its own implicit index on
        // `user_id`, which is the state a fresh `migrate:fresh` produces.
        Schema::table('persetujuan_pdp', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('persetujuan_pdp', function (Blueprint $table): void {
            $table->dropUnique('uq_consent');
        });

        Schema::table('persetujuan_pdp', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Re-declares the unique key. Fails loudly if the ledger already holds
     * duplicate `(user_id, jenis, versi_dokumen)` rows — see the class docblock.
     */
    public function down(): void
    {
        Schema::table('persetujuan_pdp', function (Blueprint $table): void {
            $table->unique(['user_id', 'jenis', 'versi_dokumen'], 'uq_consent');
        });
    }
};
