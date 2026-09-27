<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 73 of 75 — `telemedicine_test.sql:1118-1132`. **Batch K.**
 *
 * **11 columns** (`:1119`-`:1129`), **3 indexes** (the primary key,
 * `idx_audit_user (user_id, dibuat_at)` and `idx_audit_tabel (tabel_target,
 * record_id, dibuat_at)`) and **ZERO foreign keys** — despite carrying two columns
 * that read exactly like references and one that looks even more like one.
 *
 * Module: M5 compliance. `docs/migration-order.md` row 73 records `Resource: —`,
 * `Controller: —`: the table is written by observers and services (todo 43) and has
 * **no read endpoint in the plan's scope**. That is a deliberate scope decision,
 * not an omission. Model: todo 19.
 *
 * ## TRAP 3 — `record_id` is `VARCHAR(64)`, A STRING, NOT A BIGINT
 *
 * `:1123` is `record_id VARCHAR(64) NULL`. **It must be `string('record_id', 64)`,
 * never `unsignedBigInteger('record_id')`.** This is the single most consequential
 * type decision in the batch, and getting it wrong is not a cosmetic drift — it
 * makes certain audit rows **unrepresentable**.
 *
 * `audit_log` is the project's **generic, table-agnostic** audit trail: the pair
 * (`tabel_target` `:1122`, `record_id` `:1123`) names *some* row in *some* table,
 * and the same columns must therefore carry the primary key of **every** model the
 * observers in todo 43 watch. Most are `BIGINT UNSIGNED`, but **not all**, and an
 * audit row for a non-numeric or composite key is simply not expressible in a
 * bigint column. The clearest case is in this very contract: `role_permissions`
 * (15), `user_roles` (16), `dokter_faskes` (33) and `lab_paket_item` (57) all have
 * **composite primary keys and no `id` at all** (rule 3 of
 * `docs/migration-order.md`), and `dokter_faskes`'s PK is the pair
 * `(dokter_id, faskes_id)`. A single `BIGINT` cannot name any of them. `VARCHAR(64)`
 * can, by storing `"12|34"`, and that is the trade the DDL makes.
 *
 * **This is also why `audit_log` cannot key on a numeric id, and therefore why
 * `INDEX idx_audit_tabel (tabel_target, record_id, dibuat_at)` (`:1131`) is a
 * STRING index.** Both of its first two columns are `VARCHAR(64)`, so the index
 * stores them as character data and sorts them **collated**, not numerically: an
 * index scan for `record_id = '9'` will also visit `'10'` and `'100'`, which is
 * correct and unavoidable, and a `WHERE` that forgets the quotes around the value
 * will not match at all. A reviewer optimising this index for integers would be
 * optimising a column that does not exist.
 *
 * Note that `tabel_target` is likewise `VARCHAR(64) NULL` and **nullable** — an
 * audit row may record an action that is not about a specific record at all (a
 * failed login, a rejected authorisation), which is why `aksi` includes `login`
 * and `logout`.
 *
 * ## `user_id` is BARE **DELIBERATELY, SO THE LOG SURVIVES USER DELETION**
 *
 * `:1120` is `user_id BIGINT UNSIGNED NULL`. It is a real reference shape and it
 * carries **no `FOREIGN KEY`**, and unlike `artikel.reviewer_user_id` (`:1078`) and
 * `lab_hasil.diperiksa_oleh` (`:914`) the plan's generated bare-column list names
 * it **explicitly as unconstrained so the log survives user deletion** (plan line
 * 181). That rationale is the **plan's**, not the SQL's: **`:1120` carries no
 * `COMMENT` text in the DDL**, so a migration comment implying the SQL states the
 * reason would be a false claim, and this file does not make one. What the DDL
 * *does* say is only that the column is nullable and unconstrained.
 *
 * The reasoning is sound and worth preserving: a `CASCADE` here would delete the
 * evidence of what a user did at the moment their account was deleted, which is
 * precisely the record a compliance audit exists to preserve. So the column is
 * nullable (a system action has no actor) and bare (deleting the user leaves the
 * trail). The cost is the well-known one: **an audit row can outlive its subject
 * and then dangle**, so `user_id` must be treated as a historical identifier, never
 * joined as a live one.
 *
 * `user_id` is also the **leftmost column of `idx_audit_user (user_id, dibuat_at)`**
 * (`:1130`), which serves "everything this user did, newest first" and is the query
 * a subject-access request is answered from.
 *
 * **Contrast `notifikasi.user_id` (`:1046`) and `persetujuan_pdp.user_id`
 * (`:1143`), which both CASCADE in this same batch.** The difference is not an
 * inconsistency to be tidied up: a notification and a consent record are personal
 * artefacts that must die with the person, while an audit row is evidence that must
 * outlive them. **Do not harmonise.**
 *
 * Proven FK-free against `information_schema.REFERENTIAL_CONSTRAINTS` joined to
 * `KEY_COLUMN_USAGE`, **not** by reading `SHOW CREATE TABLE` (which shows only
 * constraints that exist, so it cannot distinguish "absent" from "not looked for"),
 * and each of `user_id` and `record_id` was separately confirmed to **exist** so
 * that "no row returned" cannot be confused with "no column". `artikel`'s
 * `reviewer_user_id` was proven the same way.
 *
 * ## `aksi` is an EIGHT-value ENUM, single-line, and it mixes two vocabularies
 *
 * ```sql
 * aksi ENUM('create','read','update','delete','login','logout','download','export') NOT NULL
 * ```
 *
 * `ENUM(` opens **and** closes on `:1121`; `:1122` is a different column
 * (`tabel_target`). Single-line, **not** one of the five wrapped ENUMs. All eight
 * values in the SQL's exact order.
 *
 * The first four (`create`, `read`, `update`, `delete`) are **CRUD on a record**;
 * the next two (`login`, `logout`) are **authentication events with no record at
 * all**; the last two (`download`, `export`) are **bulk egress**, which is neither
 * CRUD nor an authentication event. That is why `tabel_target` and `record_id` are
 * both nullable: the ENUM permits actions that have no target. There is no
 * `DEFAULT`, so omitting `aksi` is MySQL 1364 — correct, since an action with no
 * classification is not an audit record.
 *
 * **`read` is in this list, which makes a read of a medical record an auditable
 * event.** That is the mechanism behind `akses_rekam_medis_log` (75, the last
 * migration) existing separately: that table is a *typed, constrained* access log
 * on medical records specifically, while this one is the general trail.
 *
 * ## `data_lama` and `data_baru` are `JSON NULL` — `json()`, never `text()`
 *
 * `:1124` and `:1125`. `$table->json()` emits the `json` type; `text()` emits
 * `longtext` and is `column_type` drift. Both are nullable and have **no default**,
 * which is the right shape for a before/after pair: a `create` has no `data_lama`,
 * a `delete` has no `data_baru`, and a `read` or `login` has neither — so all three
 * are legitimately `NULL` and the pair cannot be collapsed into one column.
 *
 * **Nothing validates the contents, and there is no redaction.** A `JSON` column is
 * opaque to the database, so a `data_lama` holding a patient's diagnosis or an NIK
 * is stored exactly as the observer wrote it. UU PDP No. 27/2022 exposure here is a
 * **service-layer** obligation (what to capture, and what to mask) and the schema
 * neither helps nor hinders it. Todo 43's observers own that decision, and todo 50
 * (deterministic NIK encryption and API masking) owns masking.
 *
 * `ip_address VARCHAR(45) NULL` (`:1126`) — 45 is the maximum length of an IPv6
 * address **with** its prefix/scope id and port in full dotted form, so the width is
 * deliberate and not a round number. `user_agent VARCHAR(255) NULL` (`:1127`) and
 * `endpoint VARCHAR(200) NULL` (`:1128`) are unconstrained free strings, exactly as
 * `audit_log`'s sibling `persetujuan_pdp.ip_address` (`VARCHAR(45) NULL`, `:1142`)
 * is — the same width, the same absence of a type for the concept.
 *
 * ## `dibuat_at` only — no `diubah_at`, so no raw `ALTER`
 *
 * `:1129` is `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` with no sibling and no
 * `ON UPDATE`. `$table->timestamps()` must **not** be called — it would emit
 * `created_at`/`updated_at` and invent a `diubah_at` the DDL does not have.
 * **`audit_log` is the one table the plan's own "`dibuat_at` only" list omitted**
 * (plan appendix A.8/A.9: the plan says 18, the measured figure is **19**, and
 * `audit_log` at `:1129` is the omission), so this file is the correction.
 * **Todo 19's model needs `const CREATED_AT = 'dibuat_at'` and
 * `public $timestamps = false`.**
 *
 * The absence is not an oversight — it is **the point of the table**. An audit log
 * is append-only: an `UPDATE` to a past row would destroy the very evidence it
 * holds, so the schema removes the possibility of an update timestamp entirely.
 * `diubah_at` is the DDL's way of saying "this row is never rewritten".
 *
 * Exposed: `docs/migration-order.md` row 73 — Module M5 compliance, Resource —,
 * Controller —. Model: todo 19.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1119).
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // ## BARE BY CONTRACT - `user_id BIGINT UNSIGNED NULL` (:1120).
            //
            // **NO FOREIGN KEY**, and this is the batch's clearest case of a
            // deliberately unconstrained column. The plan's own bare-column list
            // states the reason: unconstrained **so the log survives user
            // deletion**. A CASCADE here would delete the evidence of what a user
            // did at the moment their account was removed, which is exactly the
            // record a compliance audit exists to preserve.
            //
            // ATTRIBUTION: that rationale is **the plan's, not the SQL's** -
            // `:1120` carries NO COMMENT text in the DDL. This comment therefore
            // does not claim the SQL states it, because it does not. What the DDL
            // says is only: nullable, and unconstrained.
            //
            // Nullable for a different reason: a system action has no actor, which
            // is why `aksi` also carries `login` and `logout` with no target. The
            // cost of the bare column is the well-known one - an audit row can
            // outlive its subject and then dangle - so `user_id` must be read as a
            // HISTORICAL identifier and never joined as a live one.
            //
            // Contrast `notifikasi.user_id` (:1046) and `persetujuan_pdp.user_id`
            // (:1143), which both CASCADE in this same batch. Not an
            // inconsistency to tidy: a notification and a consent record are
            // personal artefacts that must die with the person; an audit row is
            // evidence that must outlive them.
            //
            // LEFTMOST column of idx_audit_user below, so "everything this user
            // did, newest first" - the query a subject-access request is answered
            // from - is a seek rather than a scan.
            $table->unsignedBigInteger('user_id')->nullable();

            // ## ENUM(...8 values...) NOT NULL (:1121) - **single-line, NOT
            // wrapped.** `ENUM(` opens AND closes on :1121; :1122 is a different
            // column (tabel_target). Not one of the five contract ENUMs that span
            // two physical lines.
            //
            // Eight values in the SQL's exact order: create, read, update, delete,
            // login, logout, download, export. There is no DEFAULT, so omitting
            // `aksi` is MySQL 1364 - correct, since an action with no
            // classification is not an audit record.
            //
            // The eight are NOT one vocabulary: create/read/update/delete are CRUD
            // on a record, login/logout are authentication events with NO record at
            // all, and download/export are bulk egress. That is why `tabel_target`
            // and `record_id` are both nullable below - the ENUM permits actions
            // that have no target. `read` being a member is what makes reading a
            // medical record an auditable event, which is the mechanism behind
            // `akses_rekam_medis_log` existing as a separate typed table.
            $table->enum('aksi', ['create', 'read', 'update', 'delete', 'login', 'logout', 'download', 'export']);

            // VARCHAR(64) NULL (:1122) - the name of the table the action targeted.
            // VARCHAR, not an ENUM and not a foreign key, because it must be able
            // to name any of the 75 tables plus `migrations` itself. Nullable
            // because `login`/`logout` have no target.
            $table->string('tabel_target', 64)->nullable();

            // ## TRAP 3 - `record_id VARCHAR(64) NULL` (:1123) - **A STRING, NOT
            // A BIGINT.** Use `string('record_id', 64)`; an
            // unsignedBigInteger() here is a parity break that makes certain audit
            // rows UNREPRESENTABLE.
            //
            // This is the project's generic, table-agnostic audit trail, so
            // (tabel_target, record_id) must be able to name the primary key of
            // EVERY model todo 43's observers watch - and not all of them are
            // numeric. Four contract tables have a COMPOSITE primary key and no
            // `id` at all (rule 3): role_permissions (15), user_roles (16),
            // dokter_faskes (33) and lab_paket_item (57). A single BIGINT cannot
            // name any of them; VARCHAR(64) can, by storing e.g. "12|34".
            //
            // THIS IS WHY THIS TABLE CANNOT KEY ON A NUMERIC ID, and therefore why
            // `INDEX idx_audit_tabel (tabel_target, record_id, dibuat_at)` below
            // is a **STRING index**: both leading columns are VARCHAR(64), so the
            // index stores and sorts them COLLATED, not numerically. A scan for
            // record_id = '9' also visits '10' and '100', which is correct and
            // unavoidable, and a WHERE that drops the quotes matches nothing.
            $table->string('record_id', 64)->nullable();

            // ## `data_lama JSON NULL` (:1124) and `data_baru JSON NULL` (:1125) -
            // **json(), NEVER text()**, which emits longtext and is column_type
            // drift.
            //
            // Both nullable with NO default, which is the right shape for a
            // before/after pair: a `create` has no data_lama, a `delete` has no
            // data_baru, and a `read` or `login` has neither. So the pair cannot be
            // collapsed into one column.
            //
            // NOTHING validates the contents and there is NO redaction: a JSON
            // column is opaque to the database, so a data_lama holding a diagnosis
            // or an NIK is stored exactly as written. UU PDP exposure here is a
            // service-layer obligation (what to capture, what to mask) that the
            // schema neither helps nor hinders; todo 43's observers and todo 50
            // (NIK encryption and API masking) own it.
            $table->json('data_lama')->nullable();
            $table->json('data_baru')->nullable();

            // VARCHAR(45) NULL (:1126) - 45 is the maximum length of an IPv6
            // address with prefix/scope id in full dotted form, so the width is
            // deliberate rather than a round number. Unconstrained: MySQL has no IP
            // type. `persetujuan_pdp.ip_address` (:1142) is the same width for the
            // same reason.
            $table->string('ip_address', 45)->nullable();

            // VARCHAR(255) NULL (:1127) - the raw client user agent, an
            // unconstrained free string with no normalisation.
            $table->string('user_agent', 255)->nullable();

            // VARCHAR(200) NULL (:1128) - the request path, likewise free text. Not
            // a route name and not an enum: nothing constrains it to a real
            // endpoint.
            $table->string('endpoint', 200)->nullable();

            // ## TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1129) - the ONLY
            // timestamp column, and **THIS FILE IS THE CORRECTION to the plan's
            // "`dibuat_at` only" list, which says 18 and omits this table; the
            // measured figure is 19.** No `diubah_at` and no `ON UPDATE`, so
            // $table->timestamps() must not be called and NO raw ALTER is needed
            // below. Todo 19's model needs const CREATED_AT = 'dibuat_at' and
            // public $timestamps = false.
            //
            // The absence is the POINT of the table: an audit log is append-only,
            // an UPDATE to a past row would destroy the evidence it holds, so the
            // schema removes the possibility of an update timestamp entirely.
            $table->timestamp('dibuat_at')->useCurrent();

            // `idx_audit_user` (:1130) - **COLUMN ORDER IS THE CONTRACT**:
            // user_id leftmost, dibuat_at second, so "everything this user did,
            // newest first" is a seek. A reversed (dibuat_at, user_id) would be a
            // different index and would be reported as missing_index plus
            // extra_index drift, because rule 10 compares a DDL-written name by
            // name AND column list.
            $table->index(['user_id', 'dibuat_at'], 'idx_audit_user');

            // ## `idx_audit_tabel` (:1131) - **A STRING INDEX, and its COLUMN
            // ORDER IS THE CONTRACT**: tabel_target, record_id, dibuat_at, in that
            // order. `tabel_target` is leftmost so the index partitions by table
            // before descending into the row identifier, and `dibuat_at` last so a
            // table's rows come out newest-first.
            //
            // **Both leading columns are VARCHAR(64)** - see the `record_id` comment
            // above - so this index is collated, not numeric. That is a direct
            // consequence of Trap 3 and must not be "optimised" as if the columns
            // were integers.
            $table->index(['tabel_target', 'record_id', 'dibuat_at'], 'idx_audit_tabel');

            // ## NO FOREIGN KEY IS DECLARED IN THIS CREATE TABLE, and none may be
            // invented. `user_id` (:1120) and `record_id` (:1123) are the two
            // reference-shaped columns and both are BARE BY CONTRACT, NOT
            // DEFERRED - `users` has existed since batch B, so a constraint on
            // either would succeed and become extra_foreign_key drift. Being
            // append-only, this table is also the only table in batch K with no
            // foreign key *and* no index whose leading column is a foreign key, so
            // InnoDB creates no implicit support index anywhere on it.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
