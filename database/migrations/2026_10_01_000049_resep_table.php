<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 49 of 75 — `telemedicine_test.sql:742-765`.
 *
 * Third table of batch H and the pivot of the whole pharmacy module.
 * **17 columns** (`:743`-`:760`), two ENUMs, one named index, an inline
 * `UNIQUE`, and **three** foreign keys (`:761`-`:763`) — all three of which
 * point at a table created in an earlier batch, so **nothing here is
 * deferred**. All three carry **no `ON DELETE` clause**, so each materialises
 * MySQL's implicit `NO ACTION` (`RESTRICT` for DML).
 *
 * **TWO REFERENCE-SHAPED COLUMNS ARE BARE, AND `constrained()` OR `->foreign()`
 * ON EITHER IS A PARITY BREAK.** This is the trap in this table, and it is
 * asymmetric within the table itself:
 *
 * | Column | SQL line | FK in the DDL? |
 * | --- | --- | --- |
 * | `konsultasi_id` | `:745` | **none** |
 * | `rekam_medis_id` | `:746` | **none** |
 * | `pasien_id` | `:747` | yes, `:761` |
 * | `dokter_id` | `:748` | yes, `:762` |
 * | `apotek_id` | `:749` | yes, `:763` |
 *
 * The statement declares exactly three `FOREIGN KEY` clauses and **not one of
 * them names `konsultasi_id` or `rekam_medis_id`** — verified by grepping every
 * `FOREIGN KEY` line in the file, not by reading `SHOW CREATE TABLE`, and
 * confirmed at runtime through
 * `information_schema.REFERENTIAL_CONSTRAINTS` (see the evidence file). Both
 * targets exist by the time this migration runs: `konsultasi` is table 38
 * (batch F) and `rekam_medis` is table 42 (batch G), so `->foreign()` would
 * **succeed** and become permanent `extra_foreign_key` drift. That success is
 * what makes it dangerous: `migrate:fresh`, `php -l` and a green suite are all
 * silent, and only `verify-schema` sees it.
 *
 * Note the asymmetry is deliberate and worth preserving rather than
 * "harmonising": `pasien_id`, `dokter_id` and `apotek_id` are the columns the
 * prescription's *validity* depends on — a prescription must name a patient, a
 * prescriber and (optionally) a dispensing pharmacy — while `konsultasi_id` and
 * `rekam_medis_id` are **provenance**: which consultation and which medical
 * record this prescription came from, both optional, since a paper prescription
 * or a walk-in has neither. Neither bare column is a lookup the database
 * needs, so neither is constrained.
 *
 * `apotek_id BIGINT UNSIGNED NULL` (`:749`) points at **`faskes(id)`**, not at a
 * pharmacy table, and nothing constrains `faskes.tipe` to `'apotek'` (`:365`).
 * The application must validate it; the schema will happily accept a hospital
 * as the dispensing site.
 *
 * **TRAP 2 — THE SEVEN-DAY VALIDITY IS A SERVICE-LAYER RULE, NOT A COLUMN
 * DEFAULT.** `berlaku_sampai DATE NOT NULL` (`:755`) carries the DDL comment
 * `'E-resep berlaku 7 hari'`, and that comment is the **entire** contract:
 * the column has **no `DEFAULT`** and there is **no** trigger, no generated
 * column and no `CHECK`. MySQL cannot express `DEFAULT (tanggal_resep + INTERVAL
 * 7 DAY)` — a `DEFAULT` must be a constant, and even the expression form it
 * does allow rejects another column — so the interval lives in exactly two
 * places, and this comment is one of them:
 *
 * 1. **Todo 39's `ResepService` MUST write `berlaku_sampai` explicitly as
 *    `tanggal_resep + 7 days`.** A prescription inserted without it is
 *    impossible (the column is `NOT NULL` with no default), and one inserted
 *    with a wrong value is representable and silently changes what a
 *    pharmacist may legally dispense. Nothing anywhere re-derives it.
 * 2. **Todo 46's checkout must enforce it in the application**, because there
 *    is no `kedaluwarsa`-style trigger and no scheduled job in the schema. The
 *    plan's own schema-reality list says the same about the rest of this
 *    module: a warning must be *surfaced*, not merely stored.
 *
 * So: do **not** add a default to `berlaku_sampai`, do not add a trigger, and
 * do not derive it from `dibuat_at`. It is 7 days from **`tanggal_resep`**
 * (`:754`), which is a `DATETIME` the prescriber supplies and which may
 * legitimately differ from the row's insertion time.
 *
 * `tipe ENUM('digital','manual') NOT NULL DEFAULT 'digital'` (`:750`) and the
 * **eight**-value `status ENUM('aktif','diproses','diverifikasi','dipenuhi',
 * 'dikirim','selesai','kedaluwarsa','dibatalkan') NOT NULL DEFAULT 'aktif'`
 * (`:751`-`:752`, wrapped across two lines) are both in the DDL's exact order,
 * and **both defaults are the FIRST member** of their list. `status` is a
 * seven-step lifecycle plus a terminal `dibatalkan`, and there is **no** column
 * anywhere recording who moved it or when — `diverifikasi` has a row in
 * `resep_verifikasi` (table 51) and `dikirim` has rows in
 * `pesanan_obat_tracking` (table 53), but the transitions themselves are
 * inferred. `kedaluwarsa` is a value a scheduled command must write, because
 * the schema has no trigger that can.
 *
 * `qr_token VARCHAR(100) NOT NULL` (`:758`) is a **security token with no
 * `UNIQUE` and no index** — the same shape as
 * `surat_keterangan.qr_token` (`:592`) in batch F. Two prescriptions sharing a
 * token are representable and a unique index would be `extra_index` drift, so
 * the duplicate check is an application concern (generate with `Str::uuid()`).
 *
 * `is_iter TINYINT(1) NOT NULL DEFAULT 0` (`:756`) and `jumlah_iter TINYINT
 * UNSIGNED NOT NULL DEFAULT 0` (`:757`) are the repeat-prescription pair, and
 * the two are **different types**: the flag is signed `tinyint` (the `(1)` is
 * a display width MySQL 8 no longer emits) and the counter is `TINYINT
 * UNSIGNED`, so a negative iteration count is impossible. Nothing checks
 * `jumlah_iter > 0` when `is_iter = 1`, nor the reverse, so a zero-iteration
 * "repeat" prescription is representable.
 *
 * `INDEX idx_resep_pasien (pasien_id, status)` (`:764`) is named in the DDL, so
 * it is compared **by name**, and its column order is part of the contract:
 * `pasien_id` first, `status` second. That leftmost `pasien_id` is what makes
 * "this patient's prescriptions, filtered by state" an index range scan. The
 * plan's todo-14 prose cites it at `:765`, which is the statement's closing
 * `) ENGINE=InnoDB;`; `:764` is the index. `status` as the second column is
 * also what lets a scheduled expiry command find every `aktif` prescription for
 * a patient without scanning.
 *
 * `dibuat_at` and `diubah_at` are both present (`:759`-`:760`) and `diubah_at`
 * carries `ON UPDATE CURRENT_TIMESTAMP`, so this is one of only 16 tables with a
 * `dibuat_at`/`diubah_at` pair and needs the raw `ALTER` from
 * `docs/migration-order.md` rule 5. Declared as the explicit
 * `timestamp(...)->useCurrent()` pair batches B-G all use, **not**
 * `$table->timestamps()`, which would emit `created_at`/`updated_at` and produce
 * a `missing_column` plus an `extra_column` pair. There is **no** `dihapus_at`
 * here — only `users` (`:148`) and `pasien` (`:249`) get soft deletes — so a
 * prescription is never soft-deletable and the `dibatalkan` status plus the
 * three `RESTRICT` foreign keys are the only protections against losing one.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('resep', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // Inline UNIQUE in the DDL (:744) with no key name, so MySQL names
            // the index `nomor_resep` and Laravel `resep_nomor_resep_unique`.
            // Rule 10 compares an inline UNIQUE by semantics, so ->unique() is
            // correct and the two spellings are the same constraint.
            $table->string('nomor_resep', 30)->unique();

            // BARE, BY CONTRACT - NO FOREIGN KEY. :745 is a bare nullable
            // unsigned BIGINT and the statement's three FOREIGN KEY clauses
            // (:761, :762, :763) name pasien_id/dokter_id/apotek_id only.
            // `konsultasi` is table 38 and exists, so ->foreign() would succeed
            // and be permanent extra_foreign_key drift. It is provenance, not a
            // lookup: a paper or walk-in prescription has no consultation.
            // NOT named in the plan's todo-14 prose at all.
            $table->unsignedBigInteger('konsultasi_id')->nullable();

            // BARE, BY CONTRACT - NO FOREIGN KEY, for the same reason as
            // konsultasi_id above. :746; `rekam_medis` is table 42 and exists.
            // Both bare columns sit BESIDE three genuinely constrained ones
            // (pasien_id :747/:761, dokter_id :748/:762, apotek_id :749/:763) -
            // that asymmetry is deliberate and must not be "harmonised". See
            // the class docblock table.
            $table->unsignedBigInteger('rekam_medis_id')->nullable();

            $table->unsignedBigInteger('pasien_id');
            $table->unsignedBigInteger('dokter_id');

            // FK to `faskes(id)` - NOT to a pharmacy table, and nothing
            // constrains faskes.tipe to 'apotek' (:365). The application must
            // validate the type; the schema accepts a hospital here.
            // DDL comment copied verbatim (rule 12).
            $table->unsignedBigInteger('apotek_id')->nullable()->comment('Apotek penuh (faskes tipe apotek)');

            // TWO values, in the DDL's exact order (:750). The DEFAULT is the
            // FIRST member. `manual` is a paper prescription and is the reason
            // datetime_resep-like provenance columns are optional above.
            $table->enum('tipe', ['digital', 'manual'])->default('digital');

            // EIGHT values, in the DDL's exact order, spanning TWO lines
            // (:751-:752). The DEFAULT is the FIRST member, `aktif`.
            // `dibatalkan` is terminal; `kedaluwarsa` must be written by a
            // scheduled command because no trigger can. Nothing records who
            // moved the status or when.
            $table->enum('status', [
                'aktif',
                'diproses',
                'diverifikasi',
                'dipenuhi',
                'dikirim',
                'selesai',
                'kedaluwarsa',
                'dibatalkan',
            ])->default('aktif');

            // The ONLY place a prescriber's decision to override a
            // kontraindikasi can be recorded: there is no resep_interaksi table
            // and no acknowledgement column anywhere in the 75.
            $table->text('catatan_dokter')->nullable();

            // DATETIME, NOT TIMESTAMP and not DATE. The prescriber supplies it
            // and it may legitimately differ from this row's insertion time -
            // which is why `berlaku_sampai` is derived from THIS column, not
            // from dibuat_at. No default, no trigger.
            $table->dateTime('tanggal_resep');

            // TRAP 2. DATE NOT NULL with **NO DEFAULT** and **no trigger**, and
            // the DDL comment below is the ENTIRE seven-day contract. MySQL
            // cannot express `DEFAULT (tanggal_resep + INTERVAL 7 DAY)` - a
            // column DEFAULT must be a constant - so the interval is a
            // SERVICE-LAYER RULE: todo 39's ResepService MUST write this column
            // explicitly as `tanggal_resep + 7 days`, and todo 46's checkout
            // MUST enforce it. Do not add a default, a trigger, or a derivation
            // from dibuat_at. This comment is the only other carrier of the
            // rule; `telemedicine_test.sql` is read-only law.
            $table->date('berlaku_sampai')->comment('E-resep berlaku 7 hari');

            // TINYINT(1) -> boolean(), signed `tinyint`; the (1) is a display
            // width MySQL 8 no longer emits. DEFAULT 0.
            $table->boolean('is_iter')->default(false);

            // TINYINT UNSIGNED - a DIFFERENT type from the flag above and from
            // a signed `tinyint`, so a negative iteration count is impossible.
            // DEFAULT 0. Nothing checks the pairing, so is_iter = 1 with
            // jumlah_iter = 0 is representable.
            $table->unsignedTinyInteger('jumlah_iter')->default(0);

            // A SECURITY TOKEN with NO UNIQUE and NO index - the same shape as
            // surat_keterangan.qr_token (:592) in batch F. Two prescriptions
            // sharing a token are representable, and a unique index would be
            // extra_index drift, so the duplicate check is the application's
            // (generate with Str::uuid()).
            $table->string('qr_token', 100)->comment('Verifikasi keaslian e-resep');

            // Both timestamp columns exist (:759-:760), so this table IS one of
            // the 16 that needs rule 5's raw ON UPDATE ALTER below. The explicit
            // dibuat_at/diubah_at pair every batch B-G migration uses - NOT
            // $table->timestamps(), which would emit created_at/updated_at.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // Three foreign keys, and NOT ONE of them is on konsultasi_id or
            // rekam_medis_id. All three carry no ON DELETE clause, so each
            // materialises MySQL's implicit NO ACTION (RESTRICT for DML). All
            // three targets pre-date this batch: pasien is table 20, dokter is
            // 31, faskes is 28. Nothing here is deferred.
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('dokter_id')->references('id')->on('dokter');
            $table->foreign('apotek_id')->references('id')->on('faskes');

            // Named in the DDL (:764), so compared BY NAME. Column order is the
            // contract: pasien_id FIRST, status SECOND. The leftmost pasien_id
            // is what makes "this patient's prescriptions filtered by state" a
            // range scan, and the trailing status is what lets a scheduled
            // expiry command find every `aktif` row cheaply. Do not reorder and
            // do not widen it. The plan's todo-14 prose cites :765, which is
            // the statement's closing `) ENGINE=InnoDB;`.
            $table->index(['pasien_id', 'status'], 'idx_resep_pasien');
        });

        DB::statement('ALTER TABLE resep MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resep');
    }
};
