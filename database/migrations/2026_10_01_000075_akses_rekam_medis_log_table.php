<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 75 of 75 — `telemedicine_test.sql:1147-1155`. **The LAST table of the
 * 75-table contract, and the last migration of batch K.**
 *
 * **5 columns** (`:1148`-`:1152`), **one index** (the primary key) and **2 foreign
 * keys** (`:1153`-`:1154`). It is the narrowest table in the contract, and every one
 * of its five columns is load-bearing.
 *
 * Module: M5 compliance. `docs/migration-order.md` row 75 records `Resource: —`,
 * `Controller: —`: it is written by the medical-record service (todo 33) and has no
 * read endpoint in the plan's scope. That is a deliberate scope decision, not an
 * omission. Model: todo 19.
 *
 * ## TRAP 5 — THERE IS NO `updated_at`. Only `dibuat_at`.
 *
 * ```sql
 * dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 * ```
 *
 * That is `:1152`, and it is the **only** timestamp column. **There is no
 * `diubah_at`.** So:
 *
 * - **Do NOT call `$table->timestamps()`.** It would emit `created_at` and
 *   `updated_at` — two wrong names *and* a column the DDL does not have. It is
 *   exactly the blanket application `docs/migration-order.md` rule 4 exists to ban.
 * - **Todo 19's model needs `public $timestamps = false`** (and no
 *   `const CREATED_AT` / `const UPDATED_AT` is required beyond
 *   `const CREATED_AT = 'dibuat_at'` if a created-at accessor is wanted).
 *   `akses_rekam_medis_log` is one of the **19** contract tables in the
 *   "`dibuat_at` only" group.
 * - **No raw `ALTER` is needed.** `ON UPDATE CURRENT_TIMESTAMP` appears only in
 *   the sixteen tables that carry *both* `dibuat_at` and `diubah_at`, and this is
 *   not one of them.
 *
 * **This is an append-only access log, and the absence of `diubah_at` is the DDL's
 * way of saying so.** A row records that *someone read a medical record, for a
 * stated purpose, at a stated time*. There is nothing about that fact to amend: an
 * "edit" to an access-log row is a falsification of the evidence, so the schema
 * removes the possibility of an update timestamp entirely rather than merely
 * discouraging it. Compare `audit_log` (73), which is append-only for the same
 * reason and carries the same single `dibuat_at` — two append-only logs, one for
 * every sensitive action and one for medical-record access specifically.
 *
 * The absence is also what makes the table *safe to cascade*, which is the next
 * point, and those two facts are connected.
 *
 * ## `rekam_medis_id ON DELETE CASCADE` IS A KNOWN RETENTION DEFECT — record it, do not fix it
 *
 * `:1153` — `FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE
 * CASCADE`. **This is a genuine schema defect, and it is a defect in the
 * read-only contract, so it is documented here and NOT altered.** Adding a
 * `RESTRICT` would be `missing_foreign_key`/`extra_foreign_key` drift against a
 * table the verifier compares by `ON DELETE` behaviour.
 *
 * The defect: deleting a medical record deletes the evidence that it was accessed.
 * That is contrary to UU PDP No. 27/2022 and Permenkes 24/2022 retention duties —
 * the access trail is precisely the artefact a regulator asks for after the record
 * itself is gone. It is the same defect class the project has already recorded for
 * `lab_hasil.lab_permintaan_id ON DELETE CASCADE` (`:917`) and
 * `pesanan_obat_tracking.pesanan_obat_id` (`:826`).
 *
 * **The mitigation is operational, and it is total: medical records must never be
 * hard-deleted.** Only `dihapus_at` soft deletion is permitted. `rekam_medis` has no
 * `dihapus_at` of its own (its 28 columns are listed at `:622`-`:654` and it is in
 * neither the "both" nor the "dibuat_at only" timestamp group), so in practice
 * `rekam_medis` is **never deletable at all** — which is what makes the cascade
 * unreachable today. **That is a coincidence of the current column set, not a
 * guarantee**: if a future migration added `dihapus_at` *and* a hard-delete path,
 * the cascade would silently become a compliance hole. Any such change must
 * re-examine this constraint first.
 *
 * `pengakses_user_id BIGINT UNSIGNED NOT NULL` (`:1150`, FK `:1154` REFERENCES
 * `users(id)`) carries **no `ON DELETE` clause**, so it materialises MySQL's
 * implicit `NO ACTION`, i.e. `RESTRICT` for DML: **a user who has accessed a
 * medical record cannot be deleted.** That is the opposite polarity to the cascade
 * on the line above, and the asymmetry is deliberate and worth stating: *the
 * record* link cascades, *the person* link restricts. Deleting a patient is blocked
 * while an access log names them — which is right, because the log is the evidence
 * that the patient was treated.
 *
 * Contrast `audit_log.user_id` (`:1120`), which is **bare** — no constraint at all
 * — precisely so the log survives user deletion. Here the access log is a
 * **clinical** record rather than a general audit trail, so it is allowed to block
 * the deletion instead. Three different treatments of a `user_id` across one batch
 * (cascade, restrict, bare), each for a stated reason. **Do not harmonise them.**
 *
 * ## `tujuan_akses` is a FIVE-value ENUM, and it is the closest thing the schema
 * has to an access-control policy
 *
 * `:1151` — `tujuan_akses ENUM('perawatan','klaim','audit','pasien_sendiri',
 * 'kepentingan_hukum') NOT NULL`. Single-line: `ENUM(` opens **and** closes on
 * `:1151`, and `:1152` is a different column (`dibuat_at`). So it is **not** one of
 * the five wrapped ENUMs. All five values in the SQL's exact order.
 *
 * There is **no `DEFAULT`**, so omitting the purpose is MySQL 1364 rather than a
 * silent "unclassified access" — correct, because an access with no stated purpose
 * is exactly what this table exists to rule out. Note the list mixes four distinct
 * kinds of justification: `perawatan` (care) and `klaim` (billing) are routine,
 * `audit` is the compliance case, `pasien_sendiri` is the patient exercising their
 * own access right under UU PDP, and `kepentingan_hukum` is a legal-compulsion
 * disclosure. **They are not interchangeable and none is a superset of another**, so
 * a service that treats them as one "allowed" bucket has thrown away the only
 * purpose signal the table holds.
 *
 * `pengakses_user_id` (`:1150`) is `NOT NULL`, unlike `audit_log.user_id`
 * (`:1120`) — so **every** access to a medical record has a named platform account
 * behind it, and an unauthenticated or system-initiated read is not representable.
 * That is a stronger guarantee than the general audit log gives, and it is a direct
 * consequence of the column's nullability rather than of its constraint.
 *
 * Exposed: `docs/migration-order.md` row 75 — Module M5 compliance, Resource —,
 * Controller —. Model: todo 19.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('akses_rekam_medis_log', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1148). On a five-column
            // table the surrogate id is the ONLY index besides the primary-key
            // alias, and `PRIMARY KEY (id)` covers neither foreign key, so InnoDB
            // builds TWO implicit support indexes below that the DDL does not name.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:1149) - the record that was accessed. FK at
            // :1153, ON DELETE CASCADE - see the retention-defect note below. The
            // column is NOT a leftmost prefix of anything, so MySQL creates an
            // implicit `akses_rekam_medis_log_rekam_medis_id_foreign` support index
            // for it; that index is not in the DDL and `SchemaDiffer::diffIndexes()`
            // treats a leftover live index whose ordered column list exactly equals
            // a *matched* foreign key's local columns as implied rather than as
            // drift (commit `27c6ca8`). Do not suppress it with a covering index of
            // our own, which would be real extra_index drift.
            $table->unsignedBigInteger('rekam_medis_id');

            // ## BIGINT UNSIGNED NOT NULL (:1150) - the platform account that
            // performed the access, FK at :1154. **NOT NULL, unlike
            // `audit_log.user_id` (:1120)**, so every access to a medical record has
            // a named account behind it and an unauthenticated or system-initiated
            // read is NOT representable. That is a stronger guarantee than the
            // general audit log gives, and it comes from the nullability rather than
            // from the constraint.
            $table->unsignedBigInteger('pengakses_user_id');

            // ## ENUM(...5 values...) NOT NULL (:1151) - **single-line, NOT
            // wrapped.** `ENUM(` opens AND closes on :1151; :1152 is a different
            // column (dibuat_at). Not one of the five contract ENUMs that span two
            // physical lines.
            //
            // Five values in the SQL's exact order: perawatan, klaim, audit,
            // pasien_sendiri, kepentingan_hukum. **NO DEFAULT**, so omitting the
            // purpose is MySQL 1364 rather than a silent "unclassified access" -
            // correct, because an access with no stated purpose is exactly what this
            // table exists to rule out.
            //
            // The five are FOUR kinds of justification, not five shades of one:
            // perawatan and klaim are routine, audit is the compliance case,
            // pasien_sendiri is the patient exercising their own UU PDP access
            // right, and kepentingan_hukum is a legal-compulsion disclosure. **None
            // is a superset of another**, so a service that buckets them together
            // as "allowed" has discarded the only purpose signal the table holds.
            $table->enum('tujuan_akses', ['perawatan', 'klaim', 'audit', 'pasien_sendiri', 'kepentingan_hukum']);

            // ## TRAP 5 - TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1152) -
            // the ONLY timestamp column. **THERE IS NO `diubah_at`.**
            //
            // This is an APPEND-ONLY access log and the absent `diubah_at` is the
            // DDL's way of saying so: a row records that someone read a record, for
            // a stated purpose, at a stated time, and there is nothing about that
            // fact to amend. An "edit" to an access-log row is a falsification of
            // evidence, so the schema removes the possibility of an update timestamp
            // entirely rather than merely discouraging it.
            //
            // CONSEQUENCES, both load-bearing:
            //  - **$table->timestamps() must NOT be called.** It would emit
            //    created_at/updated_at - two wrong names AND a column the DDL does
            //    not have. It is exactly the blanket application
            //    `docs/migration-order.md` rule 4 exists to ban.
            //  - **Todo 19's model needs `public $timestamps = false`.** This table
            //    is one of the 19 contract tables in the "`dibuat_at` only" group.
            //  - **No raw `ALTER` is needed.** `ON UPDATE CURRENT_TIMESTAMP` appears
            //    only in the sixteen tables that carry BOTH dibuat_at and diubah_at,
            //    and this is not one of them.
            $table->timestamp('dibuat_at')->useCurrent();

            // FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id)
            // ON DELETE CASCADE (:1153)
            //
            // **THIS CASCADE IS A KNOWN RETENTION DEFECT, DOCUMENTED AND NOT
            // ALTERED.** Deleting a medical record deletes the evidence that it was
            // accessed, contrary to UU PDP No. 27/2022 and Permenkes 24/2022
            // retention duties - the access trail is exactly the artefact a
            // regulator asks for after the record itself is gone. Changing it to
            // RESTRICT would be foreign-key drift against a read-only contract, so
            // the correct action is to record it, which is what this comment is.
            //
            // The mitigation is operational and currently total: **medical records
            // must never be hard-deleted**; only soft deletion is permitted.
            // `rekam_medis` has no `dihapus_at` of its own (its 28 columns are
            // :622-:654 and it is in neither timestamp group), so it is in practice
            // **never deletable at all** - which is what makes the cascade
            // unreachable today. **That is a coincidence of the current column set,
            // not a guarantee**: any future migration adding `dihapus_at` plus a
            // hard-delete path would silently turn this into a compliance hole and
            // must re-examine this constraint first.
            //
            // Note the connection to Trap 5: the absence of `diubah_at` is what
            // makes an append-only log append-only, and that is what makes the
            // cascade tolerable.
            $table->foreign('rekam_medis_id')->references('id')->on('rekam_medis')->cascadeOnDelete();

            // FOREIGN KEY (pengakses_user_id) REFERENCES users(id) (:1154) -
            // **NO `ON DELETE` clause**, so MySQL's implicit NO ACTION applies, i.e.
            // RESTRICT for DML: **a user who has accessed a medical record cannot be
            // deleted.** Opposite polarity to the cascade on the line above, and
            // deliberately so: THE RECORD link cascades, THE PERSON link restricts.
            // Deleting a patient is blocked while an access log names them, which is
            // right, because the log is the evidence that they were treated.
            //
            // Contrast `audit_log.user_id` (:1120), which is BARE - no constraint at
            // all - so the general audit log survives user deletion. Here the access
            // log is a CLINICAL record rather than a general trail, so it is allowed
            // to block the deletion instead. Three different treatments of a
            // `user_id` across one batch - cascade, restrict, bare - each for a
            // stated reason. **Do not harmonise them.**
            $table->foreign('pengakses_user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('akses_rekam_medis_log');
    }
};
