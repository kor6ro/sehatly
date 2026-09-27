<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 51 of 75 — `telemedicine_test.sql:786-795`.
 *
 * Fifth table of batch H, and the smallest in the batch that has a foreign
 * key: **6 columns** (`:787`-`:792`), a three-value ENUM, two foreign keys, and
 * **no timestamp column of any kind** — this table is in rule 4's 39-table
 * "neither `dibuat_at` nor `diubah_at`" group, so todo 19's model needs
 * `public $timestamps = false` and `$table->timestamps()` is exactly the wrong
 * call. `diverifikasi_at` is a **`DATETIME`, not a `TIMESTAMP`** and it is the
 * *moment of verification*, **not** a row-creation time — it is the only
 * timestamp-like column on the table and it does not stand in for one.
 *
 * The DDL carries a domain comment on the line immediately above the
 * `CREATE TABLE` (`:785`): *"Wajib secara hukum: e-resep diverifikasi apoteker
 * sebelum dipenuhi"* — a pharmacist must verify an e-prescription before it is
 * dispensed. That is a legal requirement recorded as **prose only**: the
 * database enforces the existence of a row in this table, and nothing else.
 *
 * **TRAP 4 — `resep_id BIGINT UNSIGNED NOT NULL UNIQUE` (`:788`) MEANS EXACTLY
 * ONE VERIFICATION PER PRESCRIPTION, FOREVER, AND A RE-VERIFICATION MUST BE AN
 * `UPDATE`, NOT AN `INSERT`.**
 *
 * The `UNIQUE` is written **inline** on the column with no key name, so MySQL
 * names the index `resep_id` and Laravel's `->unique()` would yield
 * `resep_verifikasi_resep_id_unique`. Rule 10 compares an inline `UNIQUE` by
 * **semantics** — the `NON_UNIQUE` flag plus the ordered column list — and not
 * by name, so `->unique()` is the correct call and the two spellings are the
 * same constraint. What matters is the semantics: a second row for the same
 * `resep_id` cannot exist, and a second `INSERT` fails with a duplicate-key
 * error rather than creating a history.
 *
 * Consequences, and todo 40 owns all of them:
 *
 * 1. **Re-verification is an `UPDATE` of the existing row**, not an `INSERT`.
 *    An `INSERT`-then-catch-the-duplicate pattern loses the `catatan` the
 *    pharmacist just typed, and any implementation that treats this table as an
 *    append-only log is wrong about the schema.
 * 2. **A rejected prescription CANNOT be revised and re-submitted.** The DDL
 *    records exactly three outcomes — `sesuai`, `ada_koreksi`, `ditolak`
 *    (`:790`) — and `ditolak` is terminal: there is no second row to move it
 *    back to `ada_koreksi`, and `resep.status` (`:751`-`:752`) has **no**
 *    "returned for correction" member. **The rejection path is terminal and
 *    must be documented as such**, not worked around. The only way forward is
 *    a new prescription.
 * 3. **Nothing records the previous outcome.** There is no
 *    `diverifikasi_at`-history, no `status` transition log and no `diubah_at`,
 *    so overwriting `status` destroys the evidence that a prescription was ever
 *    rejected — which matters because `diverifikasi_at` is overwritten in the
 *    same `UPDATE`. A pharmacist who corrects a rejection erases when they
 *    rejected it. The only defensible mitigation is application-level (keep the
 *    prior value in an audit sink such as `audit_log`, table 73, written by an
 *    observer), because a history column would be `extra_column` drift.
 *
 * `apoteker_user_id BIGINT UNSIGNED NOT NULL` (`:789`) **does** carry a real
 * foreign key to `users(id)` (`:794`) — so unlike `resep.rekam_medis_id` and
 * `resep.konsultasi_id` (which are bare by contract), the verifying pharmacist
 * is a genuine, enforced reference, and a user id that does not exist is
 * rejected by the database. Note it references `users`, **not** `dokter` or a
 * pharmacist table: `users.tipe` includes `'apoteker'` (`:139`), so the role is
 * an attribute of the user row and nothing in this table checks it. A
 * verification row can name any user, of any `tipe`.
 *
 * `status ENUM('sesuai','ada_koreksi','ditolak') NOT NULL` (`:790`) is a
 * **three**-value list in that exact order with **no `DEFAULT`**, so it is
 * required at insert. It is not a lifecycle: the three are the outcome of the
 * single verification, not successive states, and because of TRAP 4 an
 * `ada_koreksi` row can later become `sesuai` with no trace that it was ever
 * anything else.
 *
 * `catatan TEXT NULL` (`:791`) is the pharmacist's free text — the **only**
 * place a correction instruction can be recorded, and it is overwritten along
 * with `status` on a re-verification.
 *
 * Both foreign keys carry **no `ON DELETE` clause** (`:793`-`:794`), so each
 * materialises MySQL's implicit `NO ACTION` (`RESTRICT` for DML). That is
 * deliberate and is the opposite of the cascade on `resep_item.resep_id`
 * (`:781`): deleting a prescription is *blocked* while its verification exists,
 * which preserves the legal evidence, whereas deleting the prescription's lines
 * is allowed. Both targets pre-date this batch (`resep` is 49, `users` is 12),
 * so **nothing here is deferred** and the *Deferred constraints* registry in
 * `docs/schema-notes.md` is unchanged.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('resep_verifikasi', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // TRAP 4. NOT NULL UNIQUE, written INLINE in the DDL (:788) with no
            // key name, so MySQL names the index `resep_id` and Laravel
            // `resep_verifikasi_resep_id_unique`. Rule 10 compares an inline
            // UNIQUE by SEMANTICS (NON_UNIQUE flag + ordered column list), not
            // by name, so ->unique() is correct and the two spellings are the
            // same constraint.
            //
            // The semantics are the point: exactly ONE verification per
            // prescription, ever. A re-verification is an UPDATE of THIS row -
            // an INSERT fails with a duplicate-key error and any
            // insert-then-catch pattern throws away the pharmacist's `catatan`.
            // A `ditolak` outcome is therefore TERMINAL: the three values at
            // :790 are the outcome of the one verification, not a lifecycle, and
            // there is no second row and no `resep.status` member to move a
            // rejection back. Nothing records the previous outcome, so an
            // UPDATE also erases the prior `diverifikasi_at`. Todo 40 owns all
            // of this. See the class docblock.
            $table->unsignedBigInteger('resep_id')->unique();

            // A REAL, ENFORCED reference to users(id) (:794) - contrast with
            // resep.konsultasi_id and resep.rekam_medis_id, which are bare by
            // contract. Note it points at `users`, not at a pharmacist table:
            // users.tipe includes the value 'apoteker' (:139) and nothing here
            // checks the role, so any user id is accepted.
            $table->unsignedBigInteger('apoteker_user_id');

            // THREE values, in the DDL's exact order (:790), and NO default:
            // the DDL declares none, so `status` is required at insert. NOT a
            // lifecycle - see TRAP 4 above.
            $table->enum('status', ['sesuai', 'ada_koreksi', 'ditolak']);

            // The pharmacist's free text, and the ONLY place a correction
            // instruction can live. Overwritten along with `status` on a
            // re-verification.
            $table->text('catatan')->nullable();

            // DATETIME NOT NULL, whole seconds, NOT a TIMESTAMP and NOT a DATE,
            // with no default and no trigger. It is the moment of VERIFICATION,
            // not the row's creation time, and it is the only timestamp-like
            // column on the table - so it cannot stand in for one. A wrong value
            // here is representable and reorders the audit trail.
            $table->dateTime('diverifikasi_at');

            // Neither carries an ON DELETE clause (:793-:794), so both
            // materialise MySQL's implicit NO ACTION (RESTRICT for DML) -
            // deliberately the opposite of resep_item.resep_id's CASCADE (:781),
            // so deleting a prescription is BLOCKED while its verification
            // exists and the legal evidence survives. Both targets pre-date
            // this batch; nothing here is deferred.
            $table->foreign('resep_id')->references('id')->on('resep');
            $table->foreign('apoteker_user_id')->references('id')->on('users');

            // NO $table->timestamps() - this table has neither dibuat_at nor
            // diubah_at (rule 4's 39-table "neither" group). `diverifikasi_at`
            // is the verification moment and NOT a creation time, so there is no
            // column anywhere on this table recording when the row was written.
            // See the class docblock.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resep_verifikasi');
    }
};
