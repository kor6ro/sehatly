<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 74 of 75 — `telemedicine_test.sql:1134-1145`. **Batch K.**
 *
 * **7 columns** (`:1135`-`:1142`), **1 index** (the primary key) and **1 foreign
 * key** (`:1143`).
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
 * ## F02: `uq_consent` WAS DROPPED — the table is an APPEND-ONLY LEDGER
 *
 * This migration originally declared
 * `UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)` (`:1144`), which made a
 * decision about a given document version immutable: a second row for the same
 * `(user, jenis, versi)` collided, so a withdrawal could only be recorded as a
 * NEW version. **The owner's F02 decision replaced that rule.** The unique key is
 * gone from this migration and from `telemedicine_test.sql:1144` (which now
 * carries a comment in its place, so every line-number citation after it stays
 * valid), and migration `2026_10_01_000081` drops it from databases that were
 * migrated before the change.
 *
 * The new rule, owned by `App\Services\Pdp\PdpConsentService`:
 *
 * - **Current status = the latest recorded row per `(user_id, jenis)`**, in
 *   append order (`id`). The active version is enforced on every write, so `id`
 *   order is the order.
 * - **Withdrawal is allowed anytime, instantly, on the SAME version** — it is a
 *   new row carrying `disetujui = 0`, not a version bump.
 * - **The same consecutive decision is idempotent** — no second row.
 * - **The active version is the server's**, published by
 *   `GET /api/v1/pdp/dokumen` from `config/pdp.php`; a `versi_dokumen` that is
 *   not the active one is refused with a 422.
 *
 * The old key's three columns are still load-bearing, just differently: a
 * re-consent after a policy version bump is still a NEW ROW (the old consent
 * stays on record against the document it was actually given for), and the
 * ledger still never edits a row. What changed is that a same-version second row
 * is now the mechanism for changing one's mind rather than a collision.
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

            // ## `versi_dokumen VARCHAR(20) NOT NULL` (:1139).
            //
            // F02: the ACTIVE version is the server's, published by
            // `GET /api/v1/pdp/dokumen` from `config/pdp.php`, and the write path
            // refuses any other value with a 422. The ledger orders by `id`, not by
            // this string, so the old "highest version wins" convention - and its
            // `v2.0` > `v10.0` trap - no longer decides anything. The zero-padded
            // `v01`..`v99` shape is kept because it is what a client displays and
            // echoes back.
            $table->string('versi_dokumen', 20);

            // TINYINT(1) NOT NULL (:1140) - the decision, yes or no. **No default**,
            // so a row must state it. F02: this column is what makes a withdrawal
            // representable as a NEW ROW at the SAME version - the ledger's current
            // status is the latest row's value, so `disetujui = 0` appended after a
            // `1` is a withdrawal, and a `1` appended after a `0` is a re-approval.
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

            // ## F02: NO `uq_consent` HERE ANY MORE.
            //
            // This migration used to declare
            // `$table->unique(['user_id', 'jenis', 'versi_dokumen'], 'uq_consent')`
            // to match `telemedicine_test.sql:1144`. The owner's F02 decision made
            // `persetujuan_pdp` an append-only ledger, so the unique key is gone
            // from both sides and `:1144` now carries a comment instead. Migration
            // `2026_10_01_000081` drops the index from databases migrated before
            // this change; a fresh `migrate:fresh` never creates it.
            //
            // Do NOT re-add it. A unique key here would make a same-version
            // withdrawal impossible again, which is the exact behaviour F02
            // removed, and it would be `extra_index` drift against the reference
            // DDL.

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
