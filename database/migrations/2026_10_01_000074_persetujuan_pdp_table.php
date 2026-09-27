<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 74 of 75 — `telemedicine_test.sql:1134-1145`. **Batch K.**
 *
 * **7 columns** (`:1135`-`:1142`), **2 indexes** (the primary key and
 * `UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)`) and **1 foreign key**
 * (`:1143`).
 *
 * Module: M5 compliance. `docs/migration-order.md` row 74 records `Resource: 47`,
 * `Controller: 47`; Model: todo 19. This todo authors the table and nothing else —
 * no Model, no Resource, no Controller, no seeder, no route.
 *
 * ## NO TIMESTAMPS AT ALL — and this is one of the 39, not an oversight
 *
 * There is **no `dibuat_at` and no `diubah_at`**. `$table->timestamps()` must
 * **not** be called: it would emit `created_at`/`updated_at` and invent two columns
 * the DDL does not have. `persetujuan_pdp` is one of the **39** contract tables
 * with **neither** timestamp — the group `docs/migration-order.md` rule 4 measures
 * directly off the reference DDL and names `persetujuan_pdp` among the eleven the
 * plan's own (wrong) list of 29 omitted. **Todo 19's model needs
 * `public $timestamps = false`.**
 *
 * The absence is coherent and load-bearing rather than an omission. A consent
 * record must be **immutable** — it is evidence of what a user agreed to, at a
 * moment in time, under a specific document version — and a table with an
 * `updated_at` invites an `UPDATE` that rewrites the past. So the row's only
 * chronology is `disetujui_at` below, which is a **fact about the act**, not a
 * bookkeeping column: it is supplied by the caller, it is `NOT NULL`, and it can
 * legitimately differ from the insert time (a consent recorded on paper and
 * imported later).
 *
 * ## TRAP 4 — `uq_consent` (user_id, jenis, versi_dokumen) — UNIQUENESS IS PER VERSION
 *
 * `:1144` — `UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)`. This is a
 * **named** key, so rule 10 compares it **by name**: it is declared
 * `$table->unique(['user_id', 'jenis', 'versi_dokumen'], 'uq_consent')`. All three
 * columns are load-bearing and **must not be collapsed into fewer**:
 *
 * - **A re-consent after a policy version bump is a NEW ROW, not an update.**
 *   `versi_dokumen` is part of the key, so a user who accepted version `1.0` and
 *   later accepts `2.0` gets two rows. That is the design: the old consent remains
 *   on record against the document it was actually given for, and an `UPDATE` would
 *   destroy exactly the evidence that matters. A user may therefore hold **any
 *   number** of consent rows across versions.
 * - **A same-version duplicate is rejected by the database** — MySQL 1062. Nothing
 *   in the application is needed to prevent it, and a submit handler that retries
 *   an `INSERT` after a duplicate will keep failing, which is the correct
 *   behaviour.
 * - **The same user may consent to the same `jenis` twice at the same version
 *   only if the row is deleted first**, and nothing in the schema cascades or
 *   records a revocation. See the collision note below.
 *
 * **Todo 47 owns both of the rules this key's shape decides**, and the DDL — not
 * the service — is what makes them necessary:
 *
 * 1. **"Highest `versi_dokumen` wins."** Nothing in the database orders versions or
 *    picks a winner: `versi_dokumen` is `VARCHAR(20)`, a **string**, so a `MAX()`
 *    over it sorts lexicographically and `"10.0"` compares **less than** `"9.0"`.
 *    Any "latest consent" lookup must therefore be done on a parsed version — or on
 *    `disetujui_at` — and never on a bare `MAX(versi_dokumen)`. This is a real trap
 *    and it is created by the DDL's choice of `VARCHAR`.
 * 2. **The revoked-same-version collision.** There is no `dicabut_at`, no
 *    `status`, and no partial unique index (MySQL has none), so a withdrawal of
 *    the consent for `(user, jenis, versi)` **cannot be recorded as a new row at
 *    that same version** — the key would collide. The only representable
 *    representations are to `UPDATE` the existing row's `disetujui` flag (losing
 *    the fact that consent was once given) or to `DELETE` it (losing the row
 *    entirely). `disetujui TINYINT(1) NOT NULL` (`:1140`) is the lever that makes
 *    the first option possible, and **its existence is the only reason a revocation
 *    is representable at all.**
 *
 * `disetujui_at DATETIME NOT NULL` (`:1141`) is therefore **`NOT NULL` even when
 * `disetujui = 0`** — a refusal is dated too, and the DDL requires it. That is a
 * deliberate asymmetry: `disetujui` says *whether*, `disetujui_at` says *when the
 * decision was made*, and a decision without a time is not a record.
 *
 * ## THE WRAPPED ENUM — `jenis` spans `:1137`-`:1138` and is **FIVE** values
 *
 * ```sql
 * jenis ENUM('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis',
 *            'pemasaran','komunikasi_tindak_lanjut') NOT NULL,
 * ```
 *
 * `ENUM(` opens on `:1137` and **closes on `:1138`**. `:1138` carries
 * `'pemasaran','komunikasi_tindak_lanjut') NOT NULL,` — two of the five values plus
 * the closing paren and the nullability.
 *
 * **Reading `:1137` alone yields FOUR values, not five, and makes the column look
 * NULLABLE WITH NO DEFAULT.** That is the whole hazard: a four-value list is
 * `column_type` drift, and a column read as nullable is `column_nullable` drift,
 * and one read as having no default is `column_default` drift — three separate
 * discrepancies from one misread line.
 *
 * **All five values, in the SQL's order, counted directly from both lines:**
 * `syarat_ketentuan`, `kebijakan_privasi`, `berbagi_data_medis`, `pemasaran`,
 * `komunikasi_tindak_lanjut`. **The true count is 5.** There is **no `DEFAULT**,
 * which is correct: a consent row with no document type could not be shown to the
 * user, and the whole point of the table is that the user agreed to a *named*
 * thing.
 *
 * This is one of exactly **five** ENUM declarations in the whole contract whose
 * value list continues onto the next physical line. The other four are
 * `booking.status` (`:515-516`), `master_obat.bentuk_sediaan` (`:713-714`),
 * `resep.status` (`:751-752`) and `invoice.status` (`:947-948`). I re-derived that
 * count from scratch with the predicate "does any line open an `ENUM(` that its own
 * line does not close?" rather than accepting it from the plan or from the
 * verifier's `wrapped decls 11` field, which answers a different question (a
 * declaration whose *end line* exceeds its start line) and is true for eleven
 * columns. See plan appendix A.20/A.22.
 *
 * **ATTRIBUTION, because this is exactly the error plan appendix A.23 records:**
 * `:1137` is inside `persetujuan_pdp`, whose `CREATE TABLE` is at `:1134`. It is
 * **not** `artikel_kategori.jenis` — `artikel_kategori` is `CREATE TABLE` at
 * `:1068`, spans only `:1068`-`:1072`, and has **three columns and no ENUM of any
 * kind**. The plan itself made that misattribution while correcting an earlier
 * list, with the line numbers right and the table name guessed, which is why the
 * owning statement is confirmed here rather than assumed.
 *
 * ## `ip_address VARCHAR(45) NULL` and `ON DELETE CASCADE` on `user_id`
 *
 * `:1142` — 45 characters, the maximum length of an IPv6 address with its
 * prefix/scope id in full dotted form, so the width is deliberate. Unconstrained:
 * MySQL has no IP type, and the value is evidence of where consent was given, so it
 * is recorded rather than validated. The same width and the same absence appear on
 * `audit_log.ip_address` (`:1126`).
 *
 * `:1143` — `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`.
 * **CASCADE is correct here and differs from the audit trail deliberately**: a
 * consent record is a personal artefact of the data subject, so it must be deleted
 * with the account — a consent row that outlived its subject would assert an
 * agreement by someone who no longer exists. Contrast `audit_log.user_id` (`:1120`),
 * which is **bare precisely so the evidence outlives the user**. The two are
 * opposite requirements and **must not be harmonised.**
 *
 * Exposed: `docs/migration-order.md` row 74 — Module M5 compliance,
 * Resource 47, Controller 47. Model: todo 19.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('persetujuan_pdp', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1135).
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:1136) - the data subject. FK at :1143,
            // ON DELETE CASCADE.
            $table->unsignedBigInteger('user_id');

            // ## THE WRAPPED ENUM - `jenis` spans :1137-:1138 and has FIVE values.
            //
            // `ENUM(` opens on :1137 and CLOSES on :1138, where the remaining two
            // values plus `) NOT NULL,` appear. **Reading :1137 alone yields FOUR
            // values and makes this column look NULLABLE WITH NO DEFAULT** - which
            // would be three separate discrepancies (column_type, column_nullable,
            // column_default) from one misread line.
            //
            // All FIVE in the SQL's order, counted from BOTH lines: syarat_ketentuan,
            // kebijakan_privasi, berbagi_data_medis, pemasaran,
            // komunikasi_tindak_lanjut. NO DEFAULT, which is correct - a consent row
            // with no document type could not be shown to the user.
            //
            // One of exactly FIVE wrapped ENUM declarations in the whole contract;
            // the others are booking.status (:515-516), master_obat.bentuk_sediaan
            // (:713-714), resep.status (:751-752) and invoice.status (:947-948).
            // That count was re-derived with the predicate "does any line open an
            // ENUM( that its own line does not close?", NOT taken from the plan and
            // NOT from the verifier's `wrapped decls 11` field, which answers a
            // different question (end line > start line) and is true for eleven
            // columns. Plan appendix A.20/A.22.
            //
            // ATTRIBUTION: :1137 is inside `persetujuan_pdp`, CREATE TABLE at
            // :1134. It is NOT `artikel_kategori.jenis` - that table is :1068-:1072,
            // three columns, no ENUM (plan appendix A.23 exists because the plan
            // itself misattributed this line with the number right and the name
            // guessed).
            $table->enum('jenis', ['syarat_ketentuan', 'kebijakan_privasi', 'berbagi_data_medis', 'pemasaran', 'komunikasi_tindak_lanjut']);

            // ## TRAP 4 - `versi_dokumen VARCHAR(20) NOT NULL` (:1139). This is
            // the third column of `uq_consent` and it is what makes the uniqueness
            // PER VERSION.
            //
            // **It is a VARCHAR, so "the latest version" CANNOT be computed with
            // MAX(versi_dokumen)**: string collation puts "10.0" BEFORE "9.0". Any
            // "highest version wins" lookup must parse the version or order by
            // disetujui_at. Todo 47 owns that rule; the DDL is what forces it.
            $table->string('versi_dokumen', 20);

            // TINYINT(1) NOT NULL (:1140) - the decision, yes or no. **No default**,
            // so a row must state it. This column is also the ONLY thing that makes
            // a revocation representable: there is no `dicabut_at`, no `status` and
            // no partial unique index in MySQL, so a withdrawal at the SAME
            // (user, jenis, versi) cannot be a new row - the key would collide -
            // and flipping this flag in place is the only remaining option. Reading
            // it as `disetujui = 0` loses the fact that consent was once given,
            // which is a real cost and the DDL's, not this migration's.
            $table->boolean('disetujui');

            // ## DATETIME NOT NULL (:1141) - **dateTime(), NOT timestamp()** - and
            // NOT NULL **even when disetujui = 0**, because a refusal is dated too.
            // `disetujui` says WHETHER, `disetujui_at` says WHEN the decision was
            // made, and a decision without a time is not a record. It is also not a
            // created-at substitute: this table has no created_at at all, and an
            // imported paper consent is legitimately dated before it was inserted.
            $table->dateTime('disetujui_at');

            // VARCHAR(45) NULL (:1142) - 45 is the maximum length of an IPv6
            // address with prefix/scope id, so the width is deliberate. Evidence of
            // where consent was given, recorded rather than validated: MySQL has no
            // IP type. Same width and same absence as audit_log.ip_address (:1126).
            $table->string('ip_address', 45)->nullable();

            // ## TRAP 4 - `UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)`
            // (:1144). **A NAMED key, so rule 10 compares it BY NAME**, and all
            // THREE columns are load-bearing - do not collapse them into fewer.
            //
            //  - A re-consent after a policy version bump is a **NEW ROW**, not an
            //    update: the old consent stays on record against the document it was
            //    actually given for, and an UPDATE would destroy the evidence.
            //  - A same-version duplicate is **rejected by the database** (MySQL
            //    1062); no application logic is needed and a retry keeps failing,
            //    which is correct.
            //  - A revocation at the same version **cannot be a new row** (the key
            //    collides), so it must be an UPDATE of `disetujui` or a DELETE.
            //
            // Todo 47 owns both rules this shape decides - "highest versi_dokumen
            // wins" and the revoked-same-version collision - and the DDL decides the
            // shape of both. **COLUMN ORDER IS ALSO THE CONTRACT**:
            // (user_id, jenis, versi_dokumen) is not interchangeable with any
            // permutation, and because user_id is the leftmost prefix the key also
            // serves InnoDB's support requirement for the foreign key at :1143, so
            // MySQL creates no extra implicit index on this column.
            $table->unique(['user_id', 'jenis', 'versi_dokumen'], 'uq_consent');

            // FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE (:1143)
            // - correct here and DELIBERATELY OPPOSITE to audit_log.user_id (:1120),
            // which is bare precisely so the evidence outlives the user. A consent
            // record is a personal artefact of the data subject and must be deleted
            // with the account; a consent row that outlived its subject would assert
            // an agreement by someone who no longer exists. **Do not harmonise the
            // two.**
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // ## NO TIMESTAMPS. No $table->timestamps() - it would emit
            // created_at/updated_at and invent two columns the DDL does not have.
            // `persetujuan_pdp` is one of the 39 contract tables with NEITHER
            // dibuat_at NOR diubah_at (docs/migration-order.md rule 4, which
            // measures 39 and names this table among the eleven the plan's own
            // stale list of 29 omitted). Todo 19's model needs
            // public $timestamps = false.
            //
            // The absence is load-bearing: a consent record is evidence and must be
            // immutable, and a table with an `updated_at` invites an UPDATE that
            // rewrites the past. The row's only chronology is `disetujui_at` above,
            // which is a fact about the act and not bookkeeping.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('persetujuan_pdp');
    }
};
