<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 45 of 75 — `telemedicine_test.sql:681-690`.
 *
 * Fourth table of batch G. **7 columns** and one foreign key. The whole statement
 * is `:682-689` (8 lines), so 8 = 7 columns + 1 `FOREIGN KEY` line. There is no
 * ENUM, no wrapped value list and no `INDEX` line — the only index beyond the
 * primary key is the one MySQL creates itself for the unindexed `rekam_medis_id`
 * foreign key, which `SchemaDiffer::diffIndexes()` treats as implied rather than
 * as `extra_index` drift since commit `27c6ca8`.
 *
 * `rekam_medis_id BIGINT UNSIGNED NOT NULL` with `ON DELETE CASCADE` (`:683`,
 * `:689`) — the same reasoning as the other sub-tables. `rekam_medis` is
 * table 42, created three migrations before this one in the same batch, so
 * nothing here is deferred and the *Deferred constraints* registry in
 * `docs/schema-notes.md` is unchanged.
 *
 * **(a) `diunggah_oleh BIGINT UNSIGNED NOT NULL` IS A BARE COLUMN WITH NO
 * FOREIGN KEY (`:687`), AND IT IS NOT NAMED IN THE PLAN'S TODO-13 PROSE AT ALL.**
 * `:687` is
 *
 *     diunggah_oleh BIGINT UNSIGNED NOT NULL,
 *
 * and the statement declares exactly one `FOREIGN KEY` clause (`:689`), on
 * `rekam_medis_id` — not this one. `users` is table 12 and has existed since
 * batch B, so `->foreign('diunggah_oleh')->references('id')->on('users')` would
 * **succeed** and stay green forever while being permanent
 * `extra_foreign_key` drift. The column is named on the plan's authoritative
 * no-foreign-key list and is recorded here because the plan's own batch text
 * omitted it. Declared bare below.
 *
 * The shape is odd enough to be worth naming: the column is `NOT NULL` yet
 * unconstrained, so an attachment naming a user this installation has never had
 * is representable, and there is no cascade to clean it up when that user is
 * removed. `users` does carry `dihapus_at` (`:148`), so a soft delete leaves the
 * id resolvable — which is the likely reason the constraint is absent rather than
 * merely deferred, though the DDL does not say so. The cost is that "who
 * uploaded this" is an unvalidated id, and this column is the *only* record of
 * authorship: `akses_rekam_medis_log` (table 75, `:1147`) carries
 * `pengakses_user_id` (`:1150`), which records **who read** a record, not who
 * attached a file to it.
 *
 * `file_url VARCHAR(500) NOT NULL` (`:685`) and `nama_file VARCHAR(255) NOT NULL`
 * (`:684`) are both `NOT NULL` with no default, so an attachment always carries
 * both a display name and a locator. There is no `storage_key`, no mime type, no
 * byte size and no checksum on this table, so **the attachment's integrity is not
 * recordable**: nothing in the schema can detect a replaced file at the same URL.
 * `nama_file` is a display label, not a constraint on the URL's basename — the
 * two columns are independent and may disagree.
 *
 * `tipe` is a FOUR-value ENUM in the DDL's exact order (`:686`) — `hasil_lab`,
 * `radiologi`, `foto_klinis`, `dokumen_lain` — and it has **no** `DEFAULT`, so it
 * is required at insert. `hasil_lab` sorts first and names the `master_lab_*` /
 * `lab_hasil` family (batch I, todo 15), which this schema declares but does not
 * expose through any endpoint: an attachment of `tipe = 'hasil_lab'` is therefore
 * a **file reference to a lab result the application cannot read through its own
 * API**, and `dokumen_lain` is the honest catch-all for everything the three named
 * categories do not cover. Note there is no `radiologi` table in the 75 either —
 * the type is a filing category, not a reference.
 *
 * **(b) `dibuat_at` ONLY — no `diubah_at`, so there is no raw `ALTER` here.**
 * `:688` is `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and the
 * statement ends at `:689`. This table is therefore one of the 19 "dibuat_at
 * only" tables in `docs/migration-order.md` rule 4's split, which is why rule 5's
 * raw `ALTER TABLE … MODIFY diubah_at … ON UPDATE CURRENT_TIMESTAMP` does **not**
 * apply: there is no `diubah_at` column to modify, and adding one would be a
 * `missing_column` plus an `extra_column` pair. Declaring `$table->timestamps()`
 * would be exactly that mistake. There is also **no** `dihapus_at` — only `users`
 * (`:148`) and `pasien` (`:249`) get soft deletes — so an attachment is removed by
 * a hard `DELETE` (which the parent record's `ON DELETE CASCADE` performs) and
 * nothing records *who* deleted it or *when* beyond `dibuat_at`, which is the
 * upload time and not the deletion time.
 *
 * Because `dibuat_at` is a `TIMESTAMP` and not a `DATETIME`, it is one of the
 * columns `docs/timezone-policy.md` will have to cover — a file that does not
 * exist in the repository yet, so this is a forward reference and not a claim
 * that the policy is written. MySQL converts `TIMESTAMP` on write and read using
 * the session time zone, while the API contract mandates UTC. That conversion is
 * the server's, not the application's, so it cannot be fixed in the service
 * layer.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rekam_medis_lampiran', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('rekam_medis_id');
            $table->string('nama_file', 255);
            $table->string('file_url', 500);

            // FOUR values, in the DDL's exact order (:686), and NO default: the
            // DDL declares none, so tipe is required at insert. These are filing
            // categories, not references to any table.
            $table->enum('tipe', ['hasil_lab', 'radiologi', 'foto_klinis', 'dokumen_lain']);

            // NO FOREIGN KEY, BY CONTRACT, and NOT NAMED IN THE PLAN'S TODO-13
            // PROSE AT ALL. :687 is a bare NOT NULL unsigned BIGINT; the
            // statement's only FOREIGN KEY clause is :689 on rekam_medis_id.
            // users (table 12) exists, so ->foreign() would work and would still
            // be drift. See (a).
            $table->unsignedBigInteger('diunggah_oleh');

            // This table has NO diubah_at, so $table->timestamps() and rule 5's
            // raw ON UPDATE ALTER are both wrong here. See (b).
            $table->timestamp('dibuat_at')->useCurrent();

            // rekam_medis is table 42, created three migrations before this one in
            // the same batch (000042), so nothing here is deferred.
            $table->foreign('rekam_medis_id')->references('id')->on('rekam_medis')->cascadeOnDelete();

            // NO explicit index: the DDL names none. MySQL's implicit FK-support
            // index on rekam_medis_id is treated as implied, not as drift.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rekam_medis_lampiran');
    }
};
