<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 69 of 75 — `telemedicine_test.sql:1050-1066`. **Batch K.**
 *
 * **12 columns** (`:1051`-`:1062`), **2 indexes** (the primary key and
 * `idx_ulasan_dokter (dokter_id, rating)`), **2 foreign keys** (`:1063`-
 * `:1064`) and — uniquely in the entire 75-table contract —
 * **the SQL's ONLY `CHECK` constraints, all three of them on this one table.**
 *
 * Module: **ORPHAN**. `docs/migration-order.md` row 69 records `Resource: —`,
 * `Controller: —`, so this todo authors the table and nothing else: no Model, no
 * Resource, no Controller, no seeder, no route, no service. Model: todo 19.
 *
 * ## TRAP 1 — THE ONLY DDL-LEVEL VALUE VALIDATION IN THE WHOLE SCHEMA
 *
 * ```sql
 * rating            TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),             -- :1055
 * rating_komunikasi TINYINT UNSIGNED NULL CHECK (rating_komunikasi BETWEEN 1 AND 5),      -- :1056
 * rating_akurasi    TINYINT UNSIGNED NULL CHECK (rating_akurasi BETWEEN 1 AND 5),         -- :1057
 * ```
 *
 * **State this plainly, because it is the single most important fact about this
 * file: these three columns are the ONLY columns in the entire 75-table contract
 * whose value range the database polices.** Every other numeric or enumerated
 * value in the schema is validated by the application layer alone — there is no
 * other `CHECK` in `telemedicine_test.sql`, no trigger anywhere, and no generated
 * column. A service-layer check **cannot replace these constraints**, for two
 * reasons that are structural rather than stylistic: a second code path (an
 * observer, a console command, a second service, or raw SQL) bypasses it, and a
 * race between validation and insert is decided by the database or not at all.
 *
 * **MySQL 8.0.16+ actually enforces `CHECK`.** Before 8.0.16 the grammar parsed a
 * `CHECK` and then ignored it, which is why the constraint was historically
 * treated as documentation. This schema targets MySQL 8 (the running server is
 * 8.0.30, measured), so the three statements below are real integrity guarantees
 * and not decoration. That was verified by *executing* a violating insert, not by
 * reading the DDL — see `.omo/evidence/task-17-sehatly.md`.
 *
 * **Laravel 13's Blueprint has NO `CHECK` builder.** There is no
 * `$table->check()`, and no combination of `enum()`, `unsignedTinyInteger()` or
 * `->default()` expresses a range. The constraints are therefore issued as three
 * raw `DB::statement('ALTER TABLE ... ADD CHECK ...')` calls immediately after the
 * table is created, written **exactly as the DDL writes them**.
 *
 * (The method name is deliberately not spelled with its argument list in this
 * docblock. `tests/Unit/Console/VerifySchemaCommandTest.php` derives its
 * expectations by counting the raw text `Schema::create` in every migration file and
 * then re-counting only the occurrences followed by a quoted literal; a mention in a
 * comment raises the first count without raising the second and trips that test's
 * own "refuse rather than under-test" guard. It is a comment defect that no gate
 * except the unit suite could ever have found.)
 *
 * ### The constraints are added UNNAMED, and that is deliberate
 *
 * The DDL writes **no constraint name** — the three `CHECK`s are inline column
 * constraints, so there is no name in `telemedicine_test.sql` to copy. Asking for
 * "the exact name in the DDL, do not invent names" therefore resolves to
 * **supplying none**: MySQL then auto-generates `ulasan_dokter_chk_1`,
 * `_chk_2` and `_chk_3` in creation order, which is exactly what importing
 * `telemedicine_test.sql` produces. Naming them `chk_rating` and friends — as the
 * dispatched brief's illustrative snippet does — would be **inventing a name the
 * contract never had**, and would diverge from what a reference import yields.
 *
 * This is also parity-safe by construction rather than by luck:
 * `SchemaDiffer::diffChecks()` compares CHECKs **by normalised expression only**,
 * precisely because "CHECK names are engine-generated". The names therefore
 * cannot affect `verify-schema` either way, so the choice was made on fidelity
 * grounds alone.
 *
 * ### Why `down()` does not issue `DROP CHECK`
 *
 * A `CHECK` constraint is scoped to its table and **cannot outlive it** — there is
 * no `CHECK` in existence without the table it was declared on. So
 * `Schema::dropIfExists('ulasan_dokter')` *is* the matching drop for all three,
 * and it is the only form that cannot leave a stale constraint behind or throw on
 * a name the engine chose. An explicit `DROP CHECK chk_1` would hard-code an
 * engine-generated name into application code. This is recorded as a judgement
 * call rather than presented as the only correct answer: the three constraints
 * were measured to drop from 3 to 0 across a `migrate:rollback` cycle, so the
 * behaviour is proven and not assumed.
 *
 * ## TRAP 2 — `konsultasi_id` is `NOT NULL UNIQUE`: ONE consultation, ONE review
 *
 * `:1052` — `konsultasi_id BIGINT UNSIGNED NOT NULL UNIQUE COMMENT '1
 * konsultasi = 1 ulasan'`. The DDL states the invariant in its own `COMMENT`, and
 * this migration reproduces it as an **inline `->unique()`** (rule 10: an inline
 * `UNIQUE` is compared by *semantics*, since MySQL names it after the column and
 * Laravel after the table — the two are the same constraint).
 *
 * **The consequence is a hard database-level guarantee, and todo 41's review flow
 * must be built around it: a second review for the same consultation is
 * IMPOSSIBLE.** Not discouraged, not overwritten — impossible; the insert fails
 * with MySQL 1062. So the flow is **INSERT-then-UPDATE-or-409**: the first review
 * inserts, and any later edit to that same review is an `UPDATE` of the one row.
 * A submit handler that unconditionally `INSERT`s is wrong against this schema,
 * and no amount of application-level de-duplication changes that — the uniqueness
 * is in the index, not in the code.
 *
 * Note the asymmetry with the review *content*: `balasan_dokter` (`:1060`) and
 * `dibalas_at` (`:1061`) are the doctor's reply, and they are ordinary nullable
 * columns on the same row. There is no separate reply table and no reply
 * uniqueness, so a doctor's answer is an `UPDATE` of the review — which is also
 * why `dibuat_at` (`:1062`) is the review's creation time and not its last-write
 * time; there is no `diubah_at` on this table at all.
 *
 * ## THE TWO FOREIGN KEYS CARRY NO `ON DELETE` — they RESTRICT
 *
 * `:1063` `FOREIGN KEY (pasien_id) REFERENCES pasien(id)` and `:1064`
 * `FOREIGN KEY (dokter_id) REFERENCES dokter(id)`. **Neither writes an `ON DELETE`
 * clause**, so both materialise MySQL's implicit `NO ACTION`, which is `RESTRICT`
 * for DML. A patient or a doctor named by a review cannot be deleted, and neither
 * is soft-deletable through this path either — `pasien` has `dihapus_at` (`:249`)
 * and `dokter` has none, so the `RESTRICT` is the only thing standing between a
 * review and the loss of the clinician or patient it is about. That is the
 * correct polarity for a published review: it is evidence, so it restricts.
 *
 * **This is deliberately different from `notifikasi.user_id` (`:1046`), which
 * cascades in the same batch.** Do not harmonise the two.
 *
 * ## `is_anonim TINYINT(1) NOT NULL DEFAULT 1` — the default is TRUE
 *
 * `:1059`. Read this carefully, because it is the exact defect class plan
 * appendix A.15 records as a blocker in batch D: there, a docblock claimed a
 * column "defaults to 0" while the code and the SQL both said `1`. **Here the
 * default is `1` — a review is ANONYMOUS unless the author says otherwise.**
 * `$table->boolean('is_anonim')->default(true)` is the code; the live
 * `COLUMN_DEFAULT` is `'1'`. The `(1)` in `TINYINT(1)` is a display width that
 * MySQL 8 does not emit at all, so the correct live reading is
 * `tinyint` + `default '1'`.
 *
 * Anonymity is the default because the alternative — a named patient attached to
 * a public rating of a named doctor — is the harmful case, and the author of the
 * review must positively opt in to being identified. The column is a flag on the
 * review and there is no separate `nama_tampil`: with `is_anonim = 1` the display
 * layer simply omits the patient, and nothing in the schema stores a pseudonym.
 *
 * ## `idx_ulasan_dokter (dokter_id, rating)` — the doctor's average, cheaply
 *
 * `:1065`. `dokter_id` is leftmost and `rating` second, so the index serves
 * "this doctor's reviews, worst first" and "this doctor's average rating" —
 * `dokter.jumlah_ulasan` and the doctor's rating shown in the directory (todo 22)
 * are aggregates over exactly this index. It does **not** serve a lookup by
 * `pasien_id`, and no index for that exists; a patient's own review history is a
 * scan filtered on `pasien_id`, which is acceptable only because one patient
 * writes few reviews. **Do not add a `pasien_id` index** — an index the DDL does
 * not have is `extra_index` drift (rule 7).
 *
 * Note that the *other* FK column order matters for InnoDB: `pasien_id` (`:1053`)
 * is not a leftmost prefix of this index, so MySQL builds an implicit support
 * index for it that `SHOW CREATE TABLE` prints as
 * `KEY ulasan_dokter_pasien_id_foreign (pasien_id)`. That index is **not in the
 * DDL** and `SchemaDiffer::diffIndexes()` treats a leftover live index whose
 * ordered column list exactly equals a *matched* foreign key's local columns as
 * implied rather than as drift (commit `27c6ca8`, see `docs/migration-order.md`).
 * It must not be suppressed by adding a covering index of our own.
 *
 * ## `dibuat_at` only — no `diubah_at`, so no raw `ALTER`
 *
 * `:1062` is `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` with no sibling and no
 * `ON UPDATE`. `$table->timestamps()` must **not** be called — it would emit
 * `created_at`/`updated_at` and invent a `diubah_at` the DDL does not have.
 * `ulasan_dokter` is one of the **19** contract tables in the "`dibuat_at` only"
 * group; **todo 19's model needs `const CREATED_AT = 'dibuat_at'` and
 * `public $timestamps = false`.** The absent `diubah_at` is coherent: the review
 * body is not edited (only the doctor's `balasan_dokter` is), and the review's
 * age is evidence in itself.
 *
 * **`dibalas_at` (`:1061`) is `DATETIME NULL`, not `TIMESTAMP`.** It is the reply
 * time, a wall-clock value, and must be `dateTime()->nullable()`. It is **not** a
 * second created-at: it is later than `dibuat_at` by definition, is null on every
 * unanswered review, and nothing in the schema keeps the two consistent — a
 * `balasan_dokter` with a null `dibalas_at`, or the reverse, is perfectly
 * representable, and only the application can keep them in step.
 *
 * Exposed: `docs/migration-order.md` row 69 — Module ORPHAN, Resource —,
 * Controller —. Model: todo 19.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ulasan_dokter', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1051). The SQL declares
            // the primary key INLINE on the column.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // ## TRAP 2 - `konsultasi_id BIGINT UNSIGNED NOT NULL UNIQUE` (:1052),
            // and the DDL's own COMMENT says '1 konsultasi = 1 ulasan'.
            //
            // THIS MAKES A SECOND REVIEW FOR THE SAME CONSULTATION IMPOSSIBLE AT
            // THE DATABASE LEVEL, not merely discouraged: the insert fails with
            // MySQL 1062. **Todo 41's flow must therefore be
            // INSERT-then-UPDATE-or-409** - the first review inserts, and every
            // later edit to that same review is an UPDATE of the one row. A submit
            // handler that unconditionally INSERTs is wrong against this schema,
            // and no application-level de-duplication changes that, because the
            // uniqueness lives in the index and not in the code.
            //
            // Declared as an INLINE ->unique() because it is an inline UNIQUE in
            // the DDL, and rule 10 compares an inline UNIQUE by SEMANTICS (MySQL
            // names it `konsultasi_id`, Laravel `ulasan_dokter_konsultasi_id_unique`
            // - the same constraint under two names) rather than by name.
            $table->unsignedBigInteger('konsultasi_id')->unique();

            // BIGINT UNSIGNED NOT NULL (:1053) - the reviewer. FK at :1063, and
            // this column is NOT a leftmost prefix of idx_ulasan_dokter, so MySQL
            // builds an implicit `ulasan_dokter_pasien_id_foreign` support index
            // for it. That index is not in the DDL and is treated as implied by the
            // matched foreign key (commit 27c6ca8) - do not suppress it with a
            // covering index of our own, which would be real extra_index drift.
            $table->unsignedBigInteger('pasien_id');

            // BIGINT UNSIGNED NOT NULL (:1054) - the doctor being rated. FK at
            // :1064, and it is the LEFTMOST column of idx_ulasan_dokter below, so
            // no implicit support index is created for it.
            $table->unsignedBigInteger('dokter_id');

            // ## TRAP 1 - `rating TINYINT UNSIGNED NOT NULL` (:1055).
            //
            // The range 1..5 is a real, ENFORCED integrity guarantee, added as a
            // raw ALTER below because Laravel 13's Blueprint has no CHECK builder.
            // It cannot be replaced by a service-layer check: a second code path
            // (observer, console command, raw SQL) would bypass that, and the
            // race between validation and insert is decided by the database or not
            // at all. **This is the only DDL-level value validation in the entire
            // 75-table contract.**
            //
            // unsignedTinyInteger(), NOT ->tinyInteger(): the DDL says UNSIGNED.
            // MySQL 8 emits no display width, so the correct live reading is
            // `tinyint unsigned` - never `tinyint(3) unsigned`.
            $table->unsignedTinyInteger('rating');

            // TINYINT UNSIGNED NULL (:1056) - same 1..5 range, but OPTIONAL: a
            // review may score the doctor overall and not break the score down.
            // The CHECK below is identical in shape and enforces the same range
            // when the value is present; a NULL is not a violation, which is the
            // correct semantics for "not scored".
            $table->unsignedTinyInteger('rating_komunikasi')->nullable();

            // TINYINT UNSIGNED NULL (:1057) - the third and last of the three.
            $table->unsignedTinyInteger('rating_akurasi')->nullable();

            // TEXT NULL (:1058) - the review body. Nullable, so a rating-only
            // submission with no prose is representable. `text()`, not longText():
            // the DDL says TEXT and 64 KiB is the contract.
            $table->text('isi')->nullable();

            // ## `is_anonim TINYINT(1) NOT NULL DEFAULT 1` (:1059) - **the default
            // is TRUE**, so a review is ANONYMOUS unless its author opts in. This
            // is the exact defect class plan appendix A.15 records as a blocker in
            // batch D, where a docblock claimed "defaults to 0" while the code and
            // the SQL both said 1. Read it the right way round.
            //
            // The `(1)` is a display width MySQL 8 does not emit; the live column
            // is `tinyint` with COLUMN_DEFAULT '1'. Anonymity is the default
            // because a named patient attached to a public rating of a named doctor
            // is the harmful case.
            $table->boolean('is_anonim')->default(true);

            // TEXT NULL (:1060) - the doctor's reply. An ordinary nullable column
            // on the SAME row: there is no replies table and no reply uniqueness,
            // so answering is an UPDATE of the review, never a second INSERT.
            $table->text('balasan_dokter')->nullable();

            // DATETIME NULL (:1061) - **dateTime(), NOT timestamp().** The reply
            // time, a wall-clock value. It is NOT a second created-at: it is later
            // than dibuat_at by definition, is null on every unanswered review, and
            // nothing in the schema ties it to balasan_dokter - a reply with no
            // timestamp, or a timestamp with no reply, is both representable.
            $table->dateTime('dibalas_at')->nullable();

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1062) - the ONLY
            // timestamp column. **No `diubah_at` and no `ON UPDATE`**, so
            // $table->timestamps() must not be called and NO raw ALTER is needed
            // below. One of the 19 contract tables in the "dibuat_at only" group;
            // todo 19's model needs const CREATED_AT = 'dibuat_at' and
            // public $timestamps = false.
            $table->timestamp('dibuat_at')->useCurrent();

            // The one non-primary index (:1065). **COLUMN ORDER IS THE CONTRACT**:
            // dokter_id leftmost, rating second, so it serves "this doctor's
            // reviews, worst first" and the average rating shown in the directory
            // (todo 22). It does NOT serve a lookup by pasien_id, and no index for
            // that exists - do not add one, which would be extra_index drift.
            $table->index(['dokter_id', 'rating'], 'idx_ulasan_dokter');

            // FOREIGN KEY (pasien_id) REFERENCES pasien(id) (:1063) and
            // FOREIGN KEY (dokter_id) REFERENCES dokter(id) (:1064). **NEITHER
            // writes an ON DELETE clause**, so both materialise MySQL's implicit
            // NO ACTION = RESTRICT for DML: a patient or doctor named by a review
            // cannot be deleted. Correct for a published review - it is evidence,
            // so it restricts. This is deliberately DIFFERENT from
            // notifikasi.user_id, which cascades in this same batch. Do not
            // harmonise the two.
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('dokter_id')->references('id')->on('dokter');
        });

        // ## THE THREE CHECK CONSTRAINTS - THE SQL's ONLY CHECKs, AND THE ONLY
        // DDL-LEVEL VALUE VALIDATION IN THE WHOLE 75-TABLE CONTRACT.
        //
        // Laravel 13's Blueprint has no `check()` builder, so these are raw.
        // They are written EXACTLY as :1055-:1057 write them and are added
        // UNNAMED on purpose: the DDL supplies no constraint name (they are inline
        // column constraints), so MySQL auto-generates `ulasan_dokter_chk_1..3` in
        // creation order - exactly what importing telemedicine_test.sql produces.
        // Naming them `chk_rating` and siblings would invent a name the contract
        // never had. SchemaDiffer::diffChecks() compares by normalised EXPRESSION
        // only, precisely because these names are engine-generated, so the choice
        // cannot affect parity either way.
        //
        // MySQL 8.0.16+ ENFORCES these. (Before 8.0.16 the grammar parsed CHECK and
        // ignored it.) The running server is 8.0.30, measured. A violating insert
        // was executed to prove enforcement rather than assumed from the DDL.
        DB::statement('ALTER TABLE ulasan_dokter ADD CHECK (rating BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE ulasan_dokter ADD CHECK (rating_komunikasi BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE ulasan_dokter ADD CHECK (rating_akurasi BETWEEN 1 AND 5)');
    }

    /**
     * Reverse the migrations.
     *
     * Dropping the table drops all three CHECK constraints with it: a CHECK is
     * scoped to its table and cannot exist without it, so this IS the matching
     * drop for all three. An explicit `DROP CHECK chk_1` would instead hard-code
     * an engine-generated name into application code and could throw on a name the
     * engine chose differently. Measured: the CHECK count on `ulasan_dokter` goes
     * 3 -> 0 across a `migrate:rollback` cycle, recorded in the evidence file.
     */
    public function down(): void
    {
        Schema::dropIfExists('ulasan_dokter');
    }
};
