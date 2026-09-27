<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 70 of 75 — `telemedicine_test.sql:1068-1072`. **Batch K.**
 *
 * **THREE columns. Three.** `id` (`:1069`), `nama` (`:1070`), `slug` (`:1071`).
 * That is the whole table, and the shape is load-bearing enough to be worth
 * stating at the top of the file:
 *
 * - **NO `ENUM` of any kind.** This is the table plan appendix **A.23** exists
 *   about. While correcting an earlier wrapped-ENUM list, the plan itself
 *   attributed the wrapped `jenis` declaration at `:1137`-`:1138` to
 *   `artikel_kategori.jenis` — a column **this table does not have** — while
 *   getting the line numbers right. `:1137` belongs to `persetujuan_pdp`
 *   (`CREATE TABLE` at `:1134`), and this table's own `CREATE TABLE` at `:1068`
 *   spans only `:1068`-`:1072`. **The correct number of columns is 3 and the
 *   correct number of ENUMs is 0.** Do not let a prose description of a
 *   "content taxonomy" inflate this into something with a `jenis` or a
 *   `deskripsi`.
 * - **NO `dibuat_at` and NO `diubah_at`.** `$table->timestamps()` must **not** be
 *   called: it would emit `created_at`/`updated_at` and invent two columns the DDL
 *   does not have. `artikel_kategori` is one of the **39** contract tables with
 *   **neither** timestamp — the group `docs/migration-order.md` rule 4 measures
 *   directly and lists `artikel_kategori` among its eleven names omitted from the
 *   plan's own (wrong) list of 29. **Todo 19's model needs
 *   `public $timestamps = false`, and its `$timestamps === false` assertion must
 *   count 39, not the plan's stale 28.**
 * - **NO foreign key, and no FK-less reference-shaped column either.** This is
 *   worth contrasting with the rest of the batch, which carries three bare
 *   reference columns (`artikel.reviewer_user_id`, `audit_log.user_id`,
 *   `audit_log.record_id`). This table has **no** such column: nothing about a
 *   category points anywhere. It is the only table in batch K with no foreign key
 *   of any kind *and* no candidate for one.
 * - **NO named `INDEX` or `UNIQUE KEY` clause.** The only non-primary index is the
 *   inline `UNIQUE` on `slug`, and — because there is no foreign key on the table
 *   — there is no InnoDB implicit support index either. Together with
 *   `artikel_kategori`'s sibling orphans, this makes it one of the few tables in
 *   the contract where `PRIMARY KEY (id)` is the sole index the engine creates on
 *   its own.
 *
 * ## `id` is `SMALLINT UNSIGNED`, not `BIGINT`
 *
 * `:1069` — `id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT`. Rule 1 of
 * `docs/migration-order.md` forbids `$table->id()` because it emits
 * `BIGINT UNSIGNED`; here the correct width is 16-bit, so
 * `$table->unsignedSmallInteger('id')->autoIncrement()->primary()` is not a
 * stylistic choice but the only parity-correct builder. The ceiling is 65 535
 * categories, which is not a real constraint on a health-education taxonomy.
 *
 * **`artikel.kategori_id` (`:1076`) is `SMALLINT UNSIGNED` to match** — the two
 * widths are deliberately identical, which is why `foreignId('kategori_id')` would
 * be wrong there too even though the referenced table has an `id`.
 *
 * Unlike `master_agama` and the other four non-auto-increment master tables
 * (rule 2), this `id` **does** declare `AUTO_INCREMENT`, so todo 19's model needs
 * the default `$incrementing = true` and todo 18's seeder may use Eloquent
 * `create()` rather than explicit ids.
 *
 * ## `slug` is `UNIQUE`, and `nama` is NOT
 *
 * `:1071` is `slug VARCHAR(100) NOT NULL UNIQUE`; `:1070` is
 * `nama VARCHAR(100) NOT NULL` with no uniqueness. So the *display* name may
 * repeat while the *URL key* may not — the correct direction, since two categories
 * may share an Indonesian label ("Kesehatan Jiwa") as long as they have distinct
 * slugs. Declaring `nama` unique "to be safe" would be `extra_index` drift and
 * would reject a legitimate taxonomy.
 *
 * Both are `VARCHAR(100)`, i.e. `string('nama', 100)` and
 * `string('slug', 100)` — 100 **characters**, not bytes, under `utf8mb4`.
 *
 * Module: **ORPHAN**. `docs/migration-order.md` row 70 records `Resource: —`,
 * `Controller: —`, so this todo authors the table and nothing else: no Model, no
 * Resource, no Controller, no seeder, no route. Model: todo 19.
 *
 * **The table must still exist in full, and is genuinely referenced:**
 * `artikel.kategori_id` (`artikel` 71, next migration, `:1076`) carries a real
 * `FOREIGN KEY (kategori_id) REFERENCES artikel_kategori(id)` at `:1089`, and
 * `artikel_kategori` is the parent of that constraint. So the three columns below
 * are load-bearing: a missing `slug` would break `artikel` at migration time, and
 * todo 18's seeder is expected to insert **6** categories (the plan's own
 * acceptance criterion for todo 18), so the table is both written to and read from
 * even though no endpoint in Modules 1-5 selects from it directly.
 *
 * Exposed: `docs/migration-order.md` row 70 — Module ORPHAN, Resource —,
 * Controller —. Model: todo 19.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('artikel_kategori', function (Blueprint $table) {
            // ## THREE COLUMNS TOTAL. No ENUM. No timestamps. That is the shape,
            // and A.23 exists because a plan once described this table as owning
            // the wrapped `jenis` ENUM at :1137-:1138 - which is
            // `persetujuan_pdp`'s column, not this table's. This CREATE TABLE is
            // :1068-:1072 and has three columns; inflating it is a documented
            // failure mode, not a hypothetical.
            //
            // SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1069) - 16-bit, so
            // unsignedSmallInteger(), never $table->id() (rule 1) and never
            // foreignId() anywhere pointing at it. It DOES declare AUTO_INCREMENT,
            // unlike the five master reference tables of rule 2, so todo 19's model
            // keeps $incrementing = true and todo 18's seeder may use create().
            // MySQL 8 emits no display width, so the live reading is
            // `smallint unsigned`.
            $table->unsignedSmallInteger('id')->autoIncrement()->primary();

            // VARCHAR(100) NOT NULL (:1070) - the display label. **Deliberately
            // NOT unique**: two categories may share an Indonesian label as long as
            // their slugs differ. Making this unique "to be safe" would be
            // extra_index drift and would reject a legitimate taxonomy.
            $table->string('nama', 100);

            // VARCHAR(100) NOT NULL UNIQUE (:1071) - the URL key, and the table's
            // ONLY uniqueness. Declared as an INLINE ->unique() because it is an
            // inline UNIQUE in the DDL, and rule 10 compares an inline UNIQUE by
            // SEMANTICS rather than by name: MySQL would call it `slug` and Laravel
            // `artikel_kategori_slug_unique`, and the verifier knows they are the
            // same constraint. Do not rename it to `uq_slug` to imitate
            // apotek_stok's `uq_stok` (:840) - a name the DDL never wrote is not
            // part of the contract.
            $table->string('slug', 100)->unique();

            // ## NO TIMESTAMPS. No $table->timestamps() - it would emit
            // created_at/updated_at and invent two columns the DDL does not have.
            // `artikel_kategori` is one of the 39 contract tables with NEITHER
            // dibuat_at NOR diubah_at (docs/migration-order.md rule 4, which
            // measures 39 and names this table among the eleven the plan's own
            // stale list of 29 omitted). Todo 19's model needs
            // public $timestamps = false.
            //
            // ## NO FOREIGN KEY, and no FK-less reference-shaped column either.
            // Nothing about a category points anywhere, so there is nothing to
            // constrain and nothing to leave bare - unlike the rest of this batch.
            // Being the parent of `artikel.kategori_id`'s constraint at :1089 is
            // not a constraint ON this table, and none may be added here.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artikel_kategori');
    }
};
