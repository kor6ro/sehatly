<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 48 of 75 — `telemedicine_test.sql:731-740`.
 *
 * Second table of batch H. **5 columns** (`:732`-`:736`), two foreign keys, one
 * named unique key, and **no timestamp column of any kind** — this table is in
 * rule 4's 39-table "neither `dibuat_at` nor `diubah_at`" group, so todo 19's
 * model needs `public $timestamps = false` and `$table->timestamps()` is
 * exactly the wrong call. The two foreign keys are the first of the batch's
 * four `ON DELETE CASCADE`s and both target `master_obat` (table 47), created
 * by the immediately preceding migration, so **nothing in this batch is
 * deferred** and the *Deferred constraints* registry in
 * `docs/schema-notes.md` gains no row and still holds exactly its one
 * `fk_vital_rm` entry.
 *
 * **TRAP 1 — `UNIQUE KEY uq_interaksi (obat_a_id, obat_b_id)` (`:739`) COVERS
 * ONE DIRECTION ONLY, AND NOTHING IN THE SCHEMA ENFORCES THAT.**
 *
 * The key is a composite unique on the ordered pair `(a, b)`. MySQL treats
 * `(1, 2)` and `(2, 1)` as **two different rows**, so the table can hold both
 * `obat_a_id = 1, obat_b_id = 2` and `obat_a_id = 2, obat_b_id = 1` at the
 * same time, each with its own `tingkat` — and a second insert of the *same*
 * ordered pair is the only thing that raises a duplicate-key error. There is
 * **no** `CHECK (obat_a_id < obat_b_id)`, **no** second unique key on the
 * reversed pair, and **no** trigger. Consequences, both of which a later todo
 * owns:
 *
 * 1. **The interaction service MUST query BOTH directions.** A lookup written
 *    as `WHERE obat_a_id = :x AND obat_b_id = :y` returns **half** of the
 *    interactions that exist, silently — no error, no warning, just a
 *    pharmacovigilance hole. Todo 38's `ObatInteraksiService` must issue
 *    `(obat_a_id = :x AND obat_b_id = :y) OR (obat_a_id = :y AND obat_b_id =
 *    :x)`, and when it reports a pair it must not care which column each drug
 *    landed in. **Todo 46's oversell and stock checks inherit the same
 *    requirement**, because an interaction missed at dispensing time is an
 *    interaction not warned about.
 * 2. **Any seeder that writes this table MUST insert canonical pairs with
 *    `obat_a_id < obat_b_id`.** That is the only thing that makes a pair
 *    findable by a single-direction query, and it is a **convention the
 *    database does not enforce**. The DDL seeds no rows at all here (SQL
 *    section `[16]` has no `INSERT INTO obat_interaksi`, which is why todo 18
 *    reclassifies this table's rows as unsourced `DevFixtureSeeder` data
 *    outside the 1:1 fidelity claim) — so whichever seeder todo 18 or todo 38
 *    writes is the *first* writer and is the only place the convention can be
 *    established. A mixed-direction table is representable and undetectable by
 *    any query this schema supports.
 *
 * **Do not "fix" the key** by adding a unique on `(obat_b_id, obat_a_id)` or
 * by reordering the columns. That is `extra_index` drift against a
 * read-only contract, and it still would not stop a row being inserted with
 * `a > b` — only the application convention does that.
 *
 * `tingkat ENUM('ringan','sedang','berat','kontraindikasi') NOT NULL` (`:735`)
 * is a **four**-value severity scale in that exact order, and it has **no
 * `DEFAULT`**, so it is required at insert. `kontraindikasi` is the last and is
 * the only member that should hard-block dispensing; the plan's schema-reality
 * list records that there is **no** `resep_interaksi` table and **no**
 * acknowledgement column, so a doctor's decision to prescribe despite a
 * `kontraindikasi` can only be captured as free text in
 * `resep.catatan_dokter` (`:753`). Treat that as a todo-39 concern; the
 * constraint this migration must reproduce is only the ordered list and the
 * absence of a default.
 *
 * `deskripsi TEXT NULL` (`:736`) is free text per pair. There is **no**
 * `dibuat_at`, no `suhu`/severity threshold and no evidence column, so nothing
 * about an interaction row records when it was recorded or who asserted it,
 * and `obat_interaksi` is one of the eight tables in SQL section `[16]`'s
 * absence of seed rows.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('obat_interaksi', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // Both columns are NOT NULL, UNSIGNED and the same BIGINT width -
            // the pair is symmetric in every respect but in the ORDER the
            // unique key below sees them.
            $table->unsignedBigInteger('obat_a_id');
            $table->unsignedBigInteger('obat_b_id');

            // FOUR values, in the DDL's exact order (:735), and NO default: the
            // DDL declares none, so `tingkat` is required at insert.
            // `kontraindikasi` is last and is the only member that should hard-
            // block dispensing; there is no acknowledgement column anywhere,
            // so an override is only ever free text in resep.catatan_dokter.
            $table->enum('tingkat', ['ringan', 'sedang', 'berat', 'kontraindikasi']);

            // Free text per pair. NOT a reference, NOT indexed, and there is no
            // column recording when or by whom the interaction was asserted.
            $table->text('deskripsi')->nullable();

            // master_obat is table 47, created by the immediately preceding
            // migration, so neither of these is deferred. Both CASCADE: an
            // interaction row is meaningless without the drug it describes, and
            // the drug row is only ever removed by a hard DELETE.
            $table->foreign('obat_a_id')->references('id')->on('master_obat')->cascadeOnDelete();
            $table->foreign('obat_b_id')->references('id')->on('master_obat')->cascadeOnDelete();

            // TRAP 1. Named in the DDL (:739), so compared BY NAME on
            // (TABLE_NAME, INDEX_NAME), and its column ORDER is part of the
            // contract: obat_a_id first, obat_b_id second.
            //
            // THIS KEY COVERS ONE DIRECTION ONLY. (1,2) and (2,1) are two
            // distinct rows to MySQL, both are insertable, and the only
            // duplicate this key rejects is a repeat of the SAME ordered pair.
            // The interaction service MUST therefore query BOTH
            // (a=X, b=Y) and (a=Y, b=X) or it silently misses half of every
            // interaction; and any seeder MUST write canonical pairs with
            // obat_a_id < obat_b_id, which is a convention the database does not
            // enforce. See the class docblock. Do not add a reversed key and do
            // not reorder these columns.
            $table->unique(['obat_a_id', 'obat_b_id'], 'uq_interaksi');

            // NO $table->timestamps() - this table has neither dibuat_at nor
            // diubah_at (rule 4's 39-table "neither" group). No column of any
            // kind records when the row was written. See the class docblock.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obat_interaksi');
    }
};
