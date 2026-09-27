<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 58 of 75 — `telemedicine_test.sql:876-892`. Fourth table of batch I.
 *
 * **11 columns** (`:877`-`:888`), **2 indexes, 3 foreign keys** — the busiest table
 * in the batch, and the only one of the six that carries a `dibuat_at`/`diubah_at`
 * pair and therefore the only one that needs a raw `ON UPDATE` `ALTER`.
 *
 * ## TRAP 3 — TWO REFERENCE-SHAPED COLUMNS ARE **BARE**, AND THE ASYMMETRY IS DELIBERATE
 *
 * The DDL declares **exactly three** `FOREIGN KEY` clauses for this table
 * (`:889`-`:891`) — on `pasien_id`, `dokter_id` and `faskes_lab_id` — and **none** on
 * the two columns that look exactly as much like a reference:
 *
 * | Column | SQL line | Declared type | Foreign keys on this table? |
 * | --- | --- | --- | --- |
 * | `rekam_medis_id` | **`:879`** | `BIGINT UNSIGNED NULL` | **none — bare** |
 * | `konsultasi_id` | **`:880`** | `BIGINT UNSIGNED NULL` | **none — bare** |
 *
 * Both are bare **by contract**. `rekam_medis` is table 42 and `konsultasi` is table
 * 38, so both targets exist long before this migration runs and `->foreign()` on
 * either would **succeed** and become permanent `extra_foreign_key` drift —
 * `migrate:fresh` stays green the whole time it does. This is the same omission that
 * produced `resep.konsultasi_id` and `resep.rekam_medis_id` in batch H, and it is
 * the reason this paragraph exists: **the plan's own todo-15 prose does not mention
 * either column, and absence from a brief is exactly how an invented constraint gets
 * written.** It has already happened twice in this project.
 *
 * **The reason is provenance, not a lookup.** The three constrained columns are the
 * ones the request's *validity* depends on — a laboratory request must name a
 * patient, an ordering doctor and the facility that will run the test, and all
 * three are `NOT NULL` except the facility. `rekam_medis_id` and `konsultasi_id` are
 * **where the request came from**, and **both are nullable precisely because a
 * request can have neither**: a walk-in patient at a laboratory, or a request raised
 * by a doctor who never held a teleconsultation. A `NOT NULL` on either would make
 * the standalone request literally unrepresentable.
 *
 * **Do NOT add a foreign key to either column, and do NOT register either in the
 * *Deferred constraints* registry in `docs/schema-notes.md`.** Migration
 * `2026_10_01_000076` adds `fk_vital_rm` and **nothing else**; a registry row would
 * promise a constraint the DDL never declares. The absence of those rows is the
 * contract, and it was verified for this batch against
 * `information_schema.REFERENTIAL_CONSTRAINTS` rather than by reading
 * `SHOW CREATE TABLE` — note that a column with zero foreign keys does not appear in
 * that result set **at all**, so "no row" is the expected evidence, and a query that
 * returned nothing is a pass rather than a failed lookup.
 *
 * ## `status` IS A **FIVE-VALUE ENUM THAT WRAPS ACROSS TWO LINES** (`:884`-`:885`)
 *
 * ```
 *   status ENUM('diminta','sampel_diangkat','diproses','hasil_terbit','dibatalkan')
 *          NOT NULL DEFAULT 'diminta',
 * ```
 *
 * Read `:884` alone and the five values are complete but the column looks
 * **nullable with no default** — which is `column_nullable` plus
 * `column_default` drift and a `NOT NULL` column silently made nullable. The plan's
 * "Multi-line ENUMs — treat as single units" list names six ENUMs and **omits this
 * one**, as it also omits `pesanan_obat.status` (`:810`-`:811`, found by todo 14),
 * `invoice.status` (`:947`-`:948`), `klaim_bpjs.status` (`:1022`-`:1023`) and
 * `home_care_pesanan.status` (`:1104`-`:1105`). Measured by walking the file for
 * every declaration that spans more than one physical line, there are **eleven**, and
 * this is one of them. The plan is orchestrator-owned and was **not** edited here;
 * the finding is reported instead.
 *
 * `DEFAULT 'diminta'` is the request's initial state, so an inserted row is
 * *requested and not yet sampled*. The five values are the whole state machine, and
 * **nothing enforces the order**: `diminta -> sampel_diangkat -> diproses ->
 * hasil_terbit` with `dibatalkan` reachable from anywhere is a convention only —
 * there is no trigger and no `CHECK`.
 *
 * ## `faskes_lab_id` POINTS AT `faskes(id)` AND NOTHING CONSTRAINS THE FACILITY TYPE
 *
 * `FOREIGN KEY (faskes_lab_id) REFERENCES faskes(id)` (`:891`) targets the general
 * facility table, and **`faskes.tipe` is not constrained to `'laboratorium'`**
 * (`:365`, whose values are `rumah_sakit, klinik, puskesmas, apotek, laboratorium`).
 * A hospital or a pharmacy is therefore perfectly representable as the facility
 * running a laboratory test, and **the application must validate the type**. This is
 * the same unconstrained-facility-identity pattern batch H recorded for the three
 * pharmacy columns (`resep.apotek_id` `:749`, `pesanan_obat.apotek_id` `:802`,
 * `apotek_stok.apotek_id` `:831`), and it is the **fourth** such column in the schema
 * — this is the first one on the laboratory side.
 *
 * ## ALL THREE FOREIGN KEYS CARRY **NO** `ON DELETE` CLAUSE
 *
 * `:889`, `:890` and `:891` are bare `FOREIGN KEY (...) REFERENCES ...` lines, so
 * each materialises MySQL's implicit `NO ACTION`, which is `RESTRICT` for DML.
 * **Every one of them must be written with no referential action at all** — adding
 * `cascadeOnDelete()` or `nullOnDelete()` anywhere in this table is
 * `foreign_key_action` drift. Contrast this table's two children:
 * `lab_permintaan_detail` (`:900`) and `lab_hasil` (`:917`) both cascade from their
 * parent request, which is the record link; this table's three links are the
 * *person* and *facility* links, and those restrict.
 *
 * All three targets pre-date this migration by several batches — `pasien` 20,
 * `dokter` 31, `faskes` 28 — so **nothing is deferred**.
 *
 * ## `nomor_permintaan VARCHAR(30) NOT NULL UNIQUE` (`:878`) IS AN INLINE `UNIQUE`
 *
 * Written inline, so the DDL gives it no name and `SchemaDiffer` compares it by
 * **semantics** (rule 10), never by name: MySQL would call the index
 * `nomor_permintaan` and Laravel calls it `lab_permintaan_nomor_permintaan_unique`.
 * Both are the same single-column unique and neither name is drift. The 30
 * characters are the request's human-facing number and nothing in the schema
 * generates it — there is no sequence and no trigger, so **the service must supply
 * it and a collision is a `QueryException` (MySQL 1062), not a retryable default.**
 * Unlike `resep.qr_token` (`:758`), which is `NOT NULL` and *not* unique, this one
 * is properly unique.
 *
 * ## `dibuat_at` / `diubah_at` — THE ONLY `ON UPDATE` IN THE BATCH
 *
 * `:887` is `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and `:888` is
 * `diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
 * This is one of the **16** tables in rule 4's "both" group, and `lab_permintaan` is
 * the **only** member of batch I in it. So:
 *
 *  - `$table->timestamps()` is exactly the wrong call — it would emit
 *    `created_at`/`updated_at` and produce a `missing_column` plus an
 *    `extra_column` pair. The two columns are declared by hand instead.
 *  - `->useCurrent()` on both, **plus** the raw `ALTER` below, because Laravel 13
 *    has no Blueprint helper for `ON UPDATE CURRENT_TIMESTAMP`. Without the `ALTER`
 *    the column exists, is `NOT NULL` and defaults correctly, and is **still
 *    drift** — reported as `column_on_update` with expected `CURRENT_TIMESTAMP` and
 *    actual `<none>`.
 *  - **Todo 19's model needs `const CREATED_AT = 'dibuat_at'` and `const UPDATED_AT
 *    = 'diubah_at'`.** With `$timestamps = true` and the default names, every
 *    `Model::save()` on this table targets non-existent columns.
 *
 * This table is **module-orphaned** (`docs/migration-order.md` row 58: `Module:
 * ORPHAN`, `Resource: —`, `Controller: —`). It is nonetheless *referenced*: the
 * polymorphic hub `invoice.referensi_tipe` carries a `'lab_permintaan'` member
 * (`:940`, and the plan's todo-15 text cites that as `:941`, which is `referensi_id`
 * — the line is off by one) while `invoice.referensi_id` has **no** foreign key by
 * design, so the reference is a string, not a join. Migrated and modelled for
 * referential completeness; never exposed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lab_permintaan', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:877).
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // VARCHAR(30) NOT NULL UNIQUE, INLINE in the DDL (:878). No name in the
            // DDL means the verifier compares this by SEMANTICS, not by name (rule
            // 10), so the Laravel-generated name is correct as-is. Nothing generates
            // this number: no sequence, no trigger, so the service supplies it and a
            // collision is a hard MySQL 1062 rather than a defaulted value.
            $table->string('nomor_permintaan', 30)->unique();

            // ## TRAP 3a - `rekam_medis_id BIGINT UNSIGNED NULL` (:879) is BARE.
            // There is NO `FOREIGN KEY` for this column in the DDL, even though
            // `rekam_medis` (table 42) already exists by this point, so adding
            // `->foreign('rekam_medis_id')->references('id')->on('rekam_medis')`
            // would SUCCEED and become permanent `extra_foreign_key` drift while
            // `migrate:fresh` and the whole unit suite stayed green. It is nullable
            // because a request may have no medical record at all - a walk-in
            // patient at the laboratory. Provenance, not a lookup. Do NOT constrain
            // it and do NOT register it as deferred.
            $table->unsignedBigInteger('rekam_medis_id')->nullable();

            // ## TRAP 3b - `konsultasi_id BIGINT UNSIGNED NULL` (:880) is BARE too.
            // Same reasoning: `konsultasi` (table 38) exists, and a request raised
            // by a doctor who never held a teleconsultation has no consultation id.
            // NEITHER bare column is mentioned in the plan's own todo-15 text, and
            // that omission is precisely how an invented constraint gets written.
            $table->unsignedBigInteger('konsultasi_id')->nullable();

            // BIGINT UNSIGNED NOT NULL (:881) - the three constrained columns below
            // are the ones the request's validity depends on: a patient, an
            // ordering doctor, and the facility that runs the test.
            $table->unsignedBigInteger('pasien_id');
            $table->unsignedBigInteger('dokter_id');
            $table->unsignedBigInteger('faskes_lab_id')->nullable();

            // Five values in the DDL's exact order (:884-:885). The declaration WRAPS
            // ACROSS TWO LINES: reading :884 alone yields the complete value list
            // but no `NOT NULL DEFAULT`, i.e. a silently nullable column with no
            // default. ENUM order is the sort index and TypeNormaliser never sorts
            // the member list, so a transposed pair is column_type drift.
            // DEFAULT 'diminta' means an inserted row is requested and not yet
            // sampled. Nothing enforces the state order: no trigger, no CHECK.
            $table->enum('status', [
                'diminta',
                'sampel_diangkat',
                'diproses',
                'hasil_terbit',
                'dibatalkan',
            ])->default('diminta');

            // TEXT NULL (:886) - free-text clinical notes. `text()`, never `json()`.
            $table->text('catatan_klinis')->nullable();

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:887) and (:888) with
            // ON UPDATE. Declared by hand, NOT via $table->timestamps(), which would
            // emit created_at/updated_at. This is one of the 16 tables in rule 4's
            // "both" group and the ONLY one in batch I that is: the other five have
            // neither dibuat_at nor diubah_at and need $timestamps = false.
            // The ON UPDATE clause needs the raw ALTER after the closure, because
            // Laravel 13 has no Blueprint helper for it - without it the column
            // exists and is still reported as column_on_update drift.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // Exactly THREE foreign keys (:889-:891), and NOT ONE of them carries an
            // `ON DELETE` clause, so each materialises MySQL's implicit NO ACTION
            // (RESTRICT for DML). Writing `cascadeOnDelete()` or `nullOnDelete()`
            // here would be foreign_key_action drift. This is the *person* and
            // *facility* link and therefore restricts - contrast this table's two
            // children, which cascade from their parent request (:900, :917).
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('dokter_id')->references('id')->on('dokter');

            // FK to `faskes(id)`, not to a laboratory table, and nothing constrains
            // `faskes.tipe` to 'laboratorium' (:365, whose values are rumah_sakit,
            // klinik, puskesmas, apotek, laboratorium). A hospital or a pharmacy is
            // representable as the facility running a test; the application must
            // validate the type. Same pattern as the three pharmacy columns in
            // batch H.
            $table->foreign('faskes_lab_id')->references('id')->on('faskes');
        });

        DB::statement('ALTER TABLE lab_permintaan MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_permintaan');
    }
};
