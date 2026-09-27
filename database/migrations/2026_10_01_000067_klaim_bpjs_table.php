<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 67 of 75 — `telemedicine_test.sql:1012-1030`. **Last table of batch J.**
 *
 * **15 columns** (`:1013`-`:1028`), **TWO indexes** (the primary key and
 * `idx_klaim_status`) plus the inline `UNIQUE` on `nomor_sep`, and **NO foreign
 * keys at all** — despite carrying two columns that read exactly like references.
 *
 * ## TRAP 4 — THIS TABLE GETS A MIGRATION ONLY. The spec allows V-Claim to be
 * ## stubbed, and no service, client or endpoint may be built against it.
 *
 * `docs/migration-order.md` row 67 records `Module: ORPHAN`, `Resource: —`,
 * `Controller: —`. That is the plan guardrail, not an omission: the BPJS V-Claim
 * integration is an external government system reached over a network API, and the
 * spec's Q7=A answer lets it be stubbed. **Todos 19, 44, 45 and 46 own everything
 * else; this todo authors the table and nothing else.** No Model, no Resource, no
 * Controller, no seeder, no route, no HTTP client.
 *
 * The table must still exist in full, and for two concrete reasons beyond
 * referential tidiness: `master_metode_pembayaran.tipe` (`:929`) carries a `'bpjs'`
 * member and the seed at `:1278` inserts a `BPJS` method with
 * `penyedia` = `'BPJS Kesehatan'`, so a payment *can* be routed to BPJS even though
 * the claim submission is stubbed; and the `nomor_sep`/`nomor_kartu` pair is the
 * eligibility data a stubbed claim row would carry.
 *
 * ## `nomor_kartu` is `CHAR(13)` — FIXED-WIDTH, and `varchar(13)` is a parity break
 *
 * `CHAR(13) NOT NULL` (`:1017`) is the BPJS participant card number: thirteen digits,
 * **fixed width**. It must be `char()`, never `string('nomor_kartu', 13)`. The two
 * are not cosmetic near-equivalents:
 *
 *  - **Storing is the same width either way** — 13 characters fit in both.
 *  - **Reading is not.** `CHAR` is blank-padded to its full width on retrieval, so
 *    the value a `SELECT` returns is always 13 characters; `VARCHAR(13)` returns
 *    whatever was written, including a 10-digit string. Any comparison against a
 *    13-digit value — the SATUSEHAT eligibility call, a `WHERE nomor_kartu = ?`
 *    lookup, an equality assertion in a test — behaves differently under the two
 *    types, silently.
 *  - `CHAR` in MySQL also strips **trailing spaces** on retrieval, which a
 *    hand-typed card number with a stray space will therefore lose without anyone
 *    noticing.
 *
 * Use `char('nomor_kartu', 13)`. This is the only `CHAR` in batch J besides
 * `users.uuid` (`:134`) and `rekam_medis.uuid` (`:623`) earlier in the contract, all
 * of which are `CHAR(36)`.
 *
 * `nomor_sep VARCHAR(30) NOT NULL UNIQUE` (`:1016`) — SEP = Surat Eligibilitas
 * Peserta, and the DDL's own `COMMENT` says so. It is **`VARCHAR(30)`, not
 * `CHAR`**: a SEP reference is alphanumeric and variable-length, so a fixed-width
 * type would be wrong in the other direction. It is also the table's **only**
 * uniqueness, and it is what makes a claim traceable — a resubmission after
 * `perlu_perbaikan` must reuse the same SEP rather than mint a new one, and the
 * unique key is what stops a duplicate claim being filed under a fresh number.
 *
 * ## `status` is a **six**-value ENUM and it is **NOT WRAPPED**
 *
 * ```sql
 * status ENUM('draft','diajukan','terkirim','disetujui','ditolak','perlu_perbaikan')
 *        NOT NULL DEFAULT 'draft',
 * ```
 *
 * `ENUM(` opens on `:1022` and **closes on `:1022`**; `:1023` carries only
 * `NOT NULL DEFAULT 'draft',`. This declaration is therefore **single-line** and is
 * **not** one of the five contract ENUMs whose value list continues onto the next
 * physical line. That is worth stating because it is a documented trap in this
 * project: an earlier report named `:1022`-`:1023` as a sixth wrapped ENUM, and it
 * is not one — the count is five, and `invoice.status` (`:947`-`:948`, this same
 * batch) is the only wrapped ENUM batch J owns.
 *
 * All **six** values are reproduced in order: `draft`, `diajukan`, `terkirim`,
 * `disetujui`, `ditolak`, `perlu_perbaikan`. The **default is `draft`**, and unlike
 * `invoice.status` (which defaults to `menunggu_pembayaran`) that IS the beginning
 * of the workflow, so the default is unsurprising here.
 *
 * **`perlu_perbaikan` is a RETURN FOR CORRECTION, and the schema makes it a dead
 * end.** There is no revision column, no `versi`, no draft/body split and no
 * separate submissions table. The only way to submit a corrected claim is to
 * `UPDATE` the same row back to `terkirim` or `diajukan` — overwriting the rejected
 * submission rather than superseding it — and `diubah_at` (`:1028`) is then the only
 * record that it happened. Exactly as `resep_verifikasi` makes rejection terminal
 * (its `resep_id` is `UNIQUE`, so a prescription can be verified once, ever), a
 * stubbed V-Claim client must treat `ditolak` and `perlu_perbaikan` as states that
 * cannot be appended to.
 *
 * ## TWO FK-LESS COLUMNS, BOTH BARE BY CONTRACT — `booking_id` and `rekam_medis_id`
 *
 * `booking_id BIGINT UNSIGNED NULL` (`:1014`) and `rekam_medis_id BIGINT UNSIGNED
 * NULL` (`:1015`). This `CREATE TABLE` declares **no `FOREIGN KEY` clause at all**,
 * and both columns are named in the plan's generated reference-shaped-but-no-FK
 * list.
 *
 * **Do NOT write a foreign key on either.** Both targets exist well before this
 * migration runs — `booking` is table 37 (batch E) and `rekam_medis` is table 42
 * (batch G) — so a constraint would **succeed** at migration time and become
 * permanent `extra_foreign_key` drift while `migrate:fresh`, `php -l` and the entire
 * 93-test unit suite stayed green.
 *
 * **Both are absent from the plan's own todo-16 prose**, which names only
 * `booking_id`-shaped traps indirectly and never mentions either column. That
 * omission is exactly how an invented constraint gets written, and this defect class
 * has already occurred three times in this project (`resep.konsultasi_id` in batch H,
 * `pasien_penjamin.faskes_rujukan_id` before that, `lab_hasil.diperiksa_oleh` in
 * batch I). Hence the explicit warning.
 *
 * The substantive reading, and it is coherent rather than accidental: a BPJS claim
 * is a **regulatory submission about a patient's eligibility**, and it legitimately
 * outlives the clinical episode. `booking_id` is nullable because a claim may cover
 * an encounter that was never booked through the platform (a walk-in at a partner
 * `faskes`, or a claim raised for an episode predating the platform). `rekam_medis_id`
 * is nullable because a `rawat_inap` claim (`:1018`) can be filed before the
 * inpatient record is finalised, or for care delivered outside this system entirely.
 * A `CASCADE` on either would destroy a filed claim when the clinical record is
 * edited or removed — the same retention defect the plan already records for
 * `akses_rekam_medis_log.rekam_medis_id ON DELETE CASCADE` (`:1153`).
 *
 * **Both were proven FK-free against `information_schema.REFERENTIAL_CONSTRAINTS`
 * joined to `KEY_COLUMN_USAGE`, not by reading `SHOW CREATE TABLE`** — the latter
 * only shows constraints that exist, so it cannot distinguish "absent" from "not
 * looked for". A column with zero foreign keys does not appear in that result set
 * at all, so "no row returned" is the expected evidence. Each column was
 * separately confirmed to **exist** as `bigint unsigned` nullable, so "no row"
 * cannot be confused with "no column".
 *
 * ## `diagnosa_icd10` and `tindakan_icd9cm` are ALSO bare — and their widths match
 *
 * `diagnosa_icd10 VARCHAR(8) NULL` (`:1019`) and `tindakan_icd9cm VARCHAR(8) NULL`
 * (`:1020`) are free-text codes with no `FOREIGN KEY`. Do not add one: it is the
 * same class as `rekam_medis_diagnosa.icd10_kode` (`:660`) and
 * `rekam_medis_tindakan.icd9cm_kode` (`:672`), both bare indexed strings validated
 * only in the application layer.
 *
 * The **widths are deliberately compatible**, which is the substantive reason the
 * bare form is reasonable: `master_icd10.kode` is `VARCHAR(8) NOT NULL UNIQUE`
 * (`:117`) and `master_icd9cm.kode` is `VARCHAR(8) NOT NULL UNIQUE` (`:124`) — the
 * **same 8** — so a code that validates against the master table also fits here. A
 * `FOREIGN KEY` would additionally need a unique index on the parent, which `kode`
 * has, so the constraint is *mechanically* possible and still wrong: the claim must
 * remain insertable for a diagnosis that is not yet in the platform's catalogue.
 * A claim is filed against what the clinician wrote, and reconciling the two is a
 * later data-quality task, not a write-time constraint.
 *
 * ## `tanggal_sep` / `tanggal_pulang` are `DATE`, not `DATETIME`
 *
 * `:1024` and `:1025`, both nullable, neither defaulted. They are the SEP's issue
 * and return dates — calendar days, with no time component, so `->date()` is correct
 * and `->dateTime()` would be `column_type` drift. They are **not** created-at
 * substitutes: `tanggal_sep` is when the paper was issued, which can precede this
 * row's `dibuat_at` (`:1027`) by days, and `tanggal_pulang` is in the future for an
 * in-progress admission. A null `tanggal_pulang` on a `rawat_inap` claim is the
 * normal open state.
 *
 * `tipe_layanan ENUM('rawat_jalan','rawat_inap') NOT NULL DEFAULT 'rawat_jalan'`
 * (`:1018`) — two values, one line, not wrapped. The default is the outpatient case.
 * `berkas_url VARCHAR(500) NULL` (`:1026`) is a bare URL string, like
 * `lab_hasil.file_pdf_url` (`:916`): no checksum, no foreign key, and no linkage to
 * `rekam_medis_lampiran` even though that table's `tipe` carries a `'hasil_lab'`
 * member (`:686`).
 *
 * `biaya_klaim DECIMAL(14,2) NOT NULL DEFAULT 0` (`:1021`) — `(14,2)`, the same scale
 * as `invoice`'s five money columns and `pembayaran.jumlah` and `refund.jumlah`, and
 * **not** `master_promo`'s `(12,2)`. The default is 0, i.e. a claim of no cost, and
 * because the column is `NOT NULL` a claim row with no figure is representable and
 * indistinguishable from a genuinely free claim.
 *
 * ## `dibuat_at` / `diubah_at` — one of the **16** tables that carry the raw `ALTER`
 *
 * `:1027` and `:1028` are `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`, the second
 * with `ON UPDATE CURRENT_TIMESTAMP`. Both are declared by hand with
 * `->useCurrent()` and the raw `DB::statement` below supplies the `ON UPDATE`, which
 * Laravel 13's Blueprint cannot express. `$table->timestamps()` would emit
 * `created_at`/`updated_at` and is exactly wrong.
 *
 * `diubah_at` is the **only** record that a `perlu_perbaikan` row was revised,
 * because nothing else records the transition — worth stating once here rather than
 * leaving it to a reader who assumes a status ENUM implies a status history.
 *
 * `INDEX idx_klaim_status (status)` (`:1029`) is a **single-column** index and is
 * the only non-primary index. It is genuinely required: a queue of claims waiting on
 * BPJS is `WHERE status IN ('diajukan','terkirim','perlu_perbaikan')`, and
 * `PRIMARY KEY (id)` does not serve it. Because this table has **no foreign
 * keys**, there is no InnoDB implicit support index anywhere on it — as is also true
 * of the batch's other two FK-free tables, `master_metode_pembayaran` (61) and
 * `master_promo` (65), so those three are the only tables in batch J where the
 * primary key is the sole index the engine creates on its own.
 *
 * **This batch owes no deferred constraint.** `fk_vital_rm`
 * (`pasien_tanda_vital.rekam_medis_id`, SQL section `[14]` `:1161`-`:1163`) remains
 * the only row in the *Deferred constraints* registry in `docs/schema-notes.md` and
 * the only constraint migration `2026_10_01_000076` adds. Nothing here is deferred
 * and nothing here defers: this table has no foreign key of its own, and its two
 * FK-less reference columns are bare by contract, not deferred.
 *
 * Exposed: `docs/migration-order.md` row 67 — Module ORPHAN, Resource —,
 * Controller —. Model: todo 19.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('klaim_bpjs', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1013). The only declared
            // index besides idx_klaim_status, and the only AUTO_INCREMENT id in
            // batch J apart from master_metode_pembayaran's SMALLINT one.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // ## BARE BY CONTRACT #1 - `booking_id BIGINT UNSIGNED NULL` (:1014).
            // **NO FOREIGN KEY.** `booking` is table 37 (batch E) and exists long
            // before this migration, so ->foreign() would SUCCEED and become
            // permanent extra_foreign_key drift while migrate:fresh, php -l and the
            // whole 93-test unit suite stayed green.
            //
            // This column is NOT NAMED in the plan's todo-16 prose at all, and that
            // omission is exactly how an invented constraint gets written - the
            // defect class this project has already produced three times. Nullable
            // because a claim may cover an encounter never booked through the
            // platform: a walk-in at a partner faskes, or an episode predating it.
            $table->unsignedBigInteger('booking_id')->nullable();

            // ## BARE BY CONTRACT #2 - `rekam_medis_id BIGINT UNSIGNED NULL` (:1015).
            // **NO FOREIGN KEY**, same reasons and same omission from the plan's
            // todo-16 prose. Nullable because a `rawat_inap` claim may be filed
            // before the inpatient record is finalised, or for care delivered
            // outside this system. A CASCADE here would destroy a filed regulatory
            // claim when the clinical record is edited - the same retention defect
            // the plan records for akses_rekam_medis_log.rekam_medis_id ON DELETE
            // CASCADE (:1153).
            //
            // Proven FK-free against information_schema.REFERENTIAL_CONSTRAINTS
            // joined to KEY_COLUMN_USAGE, NOT by reading SHOW CREATE TABLE, and each
            // column separately confirmed to EXIST as `bigint unsigned` nullable so
            // that "no row" cannot be confused with "no column".
            $table->unsignedBigInteger('rekam_medis_id')->nullable();

            // VARCHAR(30) NOT NULL UNIQUE (:1016) - Surat Eligibilitas Peserta (the
            // DDL's own COMMENT), and the table's ONLY uniqueness. It is VARCHAR,
            // not CHAR: a SEP reference is alphanumeric and variable-length, the
            // opposite of nomor_kartu below. It is what makes a claim traceable -
            // a resubmission after `perlu_perbaikan` must reuse the same SEP, and
            // the unique key is what prevents a duplicate claim under a fresh number.
            $table->string('nomor_sep', 30)->unique();

            // CHAR(13) NOT NULL (:1017) - the BPJS participant card number:
            // **FIXED WIDTH, so `char()`, never `string(..., 13)`.** Storing is the
            // same 13 characters either way; READING is not. CHAR is blank-padded to
            // its full width on retrieval and strips trailing spaces, so a
            // hand-typed 10-digit number silently becomes 13 characters - which
            // makes a comparison against a 13-digit value (the SATUSEHAT eligibility
            // call, a WHERE nomor_kartu = ? lookup, a test assertion) behave
            // differently under the two types without erroring. varchar(13) here
            // would be a parity break.
            $table->char('nomor_kartu', 13);

            // ENUM(...2 values...) NOT NULL DEFAULT 'rawat_jalan' (:1018) - two
            // values on one line, so NOT wrapped. The default is the outpatient case.
            $table->enum('tipe_layanan', ['rawat_jalan', 'rawat_inap'])->default('rawat_jalan');

            // VARCHAR(8) NULL (:1019) - a bare ICD-10 code, **no FOREIGN KEY**, the
            // same class as rekam_medis_diagnosa.icd10_kode (:660) and
            // rekam_medis_tindakan.icd9cm_kode (:672). The width is deliberately
            // compatible with the catalogue: master_icd10.kode is VARCHAR(8) NOT
            // NULL UNIQUE (:117), the SAME 8. A constraint is mechanically possible
            // (the parent key is unique) and still wrong: a claim is filed against
            // what the clinician wrote, and a diagnosis not yet in the platform's
            // catalogue must not block a regulatory submission. Reconciling the two
            // is a later data-quality task, not a write-time constraint.
            $table->string('diagnosa_icd10', 8)->nullable();

            // VARCHAR(8) NULL (:1020) - the bare ICD-9-CM equivalent, matching
            // master_icd9cm.kode VARCHAR(8) (:124). Also unconstrained.
            $table->string('tindakan_icd9cm', 8)->nullable();

            // DECIMAL(14,2) NOT NULL DEFAULT 0 (:1021) - the claimed amount, at the
            // (14,2) scale shared with invoice, pembayaran and refund - NOT
            // master_promo's (12,2). The default 0 is a real zero and, the column
            // being NOT NULL, a claim with no figure is indistinguishable from a
            // genuinely free one.
            $table->decimal('biaya_klaim', 14, 2)->default(0);

            // ## ENUM(...6 values...) NOT NULL DEFAULT 'draft' (:1022) -
            // **NOT WRAPPED.** `ENUM(` opens AND closes on :1022; :1023 carries only
            // `NOT NULL DEFAULT 'draft',`. This is NOT one of the five contract
            // ENUMs that span two physical lines - an earlier report named
            // :1022-:1023 as a sixth one and it is not, which is why the count is
            // five. `invoice.status` (:947-:948) is the only wrapped ENUM in batch J.
            //
            // Order is the sort index and is reproduced exactly: draft, diajukan,
            // terkirim, disetujui, ditolak, perlu_perbaikan. The default is `draft`
            // and, unlike invoice.status, that IS the start of the workflow.
            //
            // `perlu_perbaikan` is a RETURN FOR CORRECTION and the schema makes it
            // a dead end: no revision column, no versi, no draft/body split, no
            // submissions table. The only way to resubmit is to UPDATE the same row,
            // overwriting the rejected submission, and diubah_at (:1028) is then the
            // sole record it happened. Treat both ditolak and perlu_perbaikan as
            // states that cannot be appended to - the same terminality the plan
            // records for resep_verifikasi, whose resep_id is UNIQUE.
            $table->enum('status', ['draft', 'diajukan', 'terkirim', 'disetujui', 'ditolak', 'perlu_perbaikan'])->default('draft');

            // DATE NULL (:1024) - the SEP's ISSUE date. `->date()`, never
            // `->dateTime()`: a calendar day with no time component, so dateTime
            // would be column_type drift. NOT a created-at substitute - a paper SEP
            // can be issued days before this row's dibuat_at (:1027).
            $table->date('tanggal_sep')->nullable();

            // DATE NULL (:1025) - the RETURN date, and a NULL is the normal open
            // state for an in-progress rawat_inap admission.
            $table->date('tanggal_pulang')->nullable();

            // VARCHAR(500) NULL (:1026) - a bare URL string, like
            // lab_hasil.file_pdf_url (:916): no checksum, no foreign key, and no
            // linkage to rekam_medis_lampiran even though that table's `tipe`
            // carries a 'hasil_lab' member (:686).
            $table->string('berkas_url', 500)->nullable();

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1027) and (:1028) with
            // ON UPDATE. Declared by hand, NOT via $table->timestamps(), which would
            // emit created_at/updated_at. Both carry ->useCurrent(); the raw ALTER
            // below supplies ON UPDATE because Laravel 13 has no Blueprint helper for
            // it. klaim_bpjs is one of the 16 contract tables that have BOTH.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // The only non-primary index (:1029) - a SINGLE-column index on `status`,
            // and a genuine one: a claim queue is
            // WHERE status IN ('diajukan','terkirim','perlu_perbaikan') and
            // PRIMARY KEY (id) does not serve it. Because this table has NO foreign
            // keys there is no InnoDB implicit support index anywhere on it - as is
            // also true of the batch's two other FK-free tables, master_metode_
            // pembayaran (61) and master_promo (65), so those three are the only
            // tables in batch J where `PRIMARY KEY (id)` is the sole index MySQL
            // creates on its own.
            $table->index(['status'], 'idx_klaim_status');

            // ## NO FOREIGN KEY IS DECLARED IN THIS CREATE TABLE, and none may be
            // invented. The two FK-less reference columns above (booking_id :1014,
            // rekam_medis_id :1015) and the two bare code columns (diagnosa_icd10
            // :1019, tindakan_icd9cm :1020) are **BARE BY CONTRACT, NOT DEFERRED** -
            // all four of their target tables already exist, so a constraint would
            // succeed and become extra_foreign_key drift. `fk_vital_rm` (section
            // [14], :1161-:1163) remains the only registered deferral, and nothing
            // in this batch defers anything.
        });

        // ON UPDATE CURRENT_TIMESTAMP for diubah_at (:1028). Without this the
        // differ reports column_on_update drift with expected CURRENT_TIMESTAMP.
        DB::statement('ALTER TABLE klaim_bpjs MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('klaim_bpjs');
    }
};
