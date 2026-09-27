<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 64 of 75 — `telemedicine_test.sql:975-983`. One of the two smallest
 * tables in batch J, the other being `promo_redemption` (66): **6 columns**
 * (`:976`-`:981`), **ONE index** (the primary key) and **exactly ONE foreign key**,
 * pointing at `pembayaran` (table 63) — the immediately preceding migration in this
 * same batch.
 *
 * ## This is the only table in the contract that a table of the same name shadows
 *
 * The table is called `refund`, and so is the ENUM member on `pembayaran.status`
 * (`:966`) that marks a payment as refunded. They are different things and both
 * spellings are correct where they appear: the ENUM member is a **state of a
 * payment**, this table is the **money movement** that caused it. There is no
 * foreign key between them — `refund` joins to `pembayaran` only — so nothing in
 * the schema makes the two agree. A service that sets
 * `pembayaran.status = 'refund'` without writing a `refund` row, or writes a
 * `refund` row without flipping the status, produces a state the schema permits
 * and the ledger cannot explain. That is a service-layer invariant, and it is
 * unenforceable here.
 *
 * ## `status` is a **four**-value ENUM whose default is the START of the workflow
 *
 * `ENUM('diajukan','diproses','berhasil','ditolak') NOT NULL DEFAULT 'diajukan'`
 * (`:980`). Four values, closing on its own line — **not** a wrapped ENUM. Unlike
 * `pembayaran.status` (`:966`) this one is a genuine ordered workflow, and here the
 * ENUM order *is* the lifecycle: `diajukan` -> `diproses` -> `berhasil`, with
 * `ditolak` as the terminal branch. The **default is `diajukan`**, so a refund row
 * is created already in the requested state — the same pattern as
 * `invoice.status`, whose default is `menunggu_pembayaran` rather than `draft`.
 *
 * The default is not a claim that a refund may be requested without an approval
 * step; it is a claim that the row is born at the beginning of the workflow. Who
 * may move it out of `diajukan` is the service's business.
 *
 * ## `jumlah` is the amount REFUNDED, and nothing checks it against the payment
 *
 * `DECIMAL(14,2) NOT NULL` (`:978`), no default, so it is required at insert. **It
 * is not a `DECIMAL` fraction of `pembayaran.jumlah` and there is no `CHECK`**: a
 * `refund.jumlah` larger than the payment it references is representable, as is a
 * second `refund` row for the same payment. A `pembayaran` with `status = 'refund'`
 * may have no `refund` row, three of them, or one for more money than was ever
 * taken. Nothing in the DDL prevents any of it. `partial refunds` are the point of
 * the table, so the *absence* of a uniqueness constraint is a deliberate gap, not an
 * oversight — but the absence of any total-vs-refunded reconciliation is a real
 * limitation and belongs in `docs/schema-notes.md`.
 *
 * Note also that the scale matches `pembayaran.jumlah` exactly — `(14,2)`, the same
 * as `invoice`'s five money columns. This is **not** `master_promo`'s `(12,2)`.
 *
 * ## `alasan` is free text and nullable
 *
 * `VARCHAR(255) NULL` (`:979`). The only free-text field in the table, and
 * nullable: a rejection or a partial refund with no recorded reason is
 * representable. There is no `enum` of reason codes and no structured column, so
 * anything that needs to count rejection causes has to parse this string.
 *
 * ## `dibuat_at` only — no `diubah_at`, so a refund transition is UNTIMESTAMPED
 *
 * `:981` is `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and the
 * statement ends at `:983`. `refund` is one of the 19 "dibuat_at only" tables, so
 * `docs/migration-order.md` rule 5's `dibuat_at`/`diubah_at` pair does **not** apply
 * and there is **no raw `ALTER` here**. Declaring `$table->timestamps()` would emit
 * `created_at`/`updated_at`, the second of which does not exist in the DDL and would
 * be both `missing_column` and `extra_column` drift.
 *
 * The consequence is worth stating because it is easy to mistake for a defect: **a
 * four-state workflow with a single timestamp.** When a refund was requested is
 * recorded; when it was processed, succeeded or rejected is not, and cannot be
 * reconstructed from this table. `ditolak` in particular leaves no record of who
 * rejected it or when. Todo 19's model must set
 * `const CREATED_AT = 'dibuat_at'` and `const UPDATED_AT = null` (or
 * `$timestamps = false`), and any audit requirement on the transition has to be met
 * by `audit_log` (table 73) instead.
 *
 * `PRIMARY KEY (id)` (`:976`) does not cover `pembayaran_id`, so InnoDB builds one
 * implicit support index absent from the DDL; it is treated as implied by the
 * matched foreign key (commit `27c6ca8`) and must not be suppressed with an index
 * of our own.
 *
 * ## The foreign key carries **NO ON DELETE clause**
 *
 * `FOREIGN KEY (pembayaran_id) REFERENCES pembayaran(id)` (`:982`) and nothing
 * else, so it materialises MySQL's implicit `NO ACTION`: a payment still named by a
 * refund cannot be deleted. Writing `->cascadeOnDelete()` would be
 * `foreign_key_action` drift.
 *
 * This is the **only** `ON DELETE` clause in the whole of section `[11]` — the other
 * six tables of this batch declare no `ON DELETE` at all — so **batch J contains no
 * cascade anywhere.** Every link in the invoicing/payment/promo graph is a RESTRICT.
 * That is a coherent reading (financial records should not vanish with their
 * parents) and it is the DDL's, not a choice made here.
 *
 * **This batch owes no deferred constraint.** `fk_vital_rm`
 * (`pasien_tanda_vital.rekam_medis_id`, SQL section `[14]` `:1161`-`:1163`) is the
 * only row in the *Deferred constraints* registry in `docs/schema-notes.md` and the
 * only constraint migration `2026_10_01_000076` adds. `pembayaran` is table 63, one
 * migration before this one, so the target already exists.
 *
 * Exposed: `docs/migration-order.md` row 64 — Module M5 payment, Resource 45,
 * Controller 45.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('refund', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:976). Does not cover the
            // one foreign key below, so InnoDB adds an implicit support index.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:977) - a refund is always against a payment.
            // `pembayaran` is table 63, created by the immediately preceding
            // migration in this same batch, so nothing is deferred here.
            $table->unsignedBigInteger('pembayaran_id');

            // DECIMAL(14,2) NOT NULL (:978) - the amount REFUNDED, required at
            // insert, NO DEFAULT (so do not add ->default(0): column_default drift).
            // Same (14,2) scale as pembayaran.jumlah (:962) and invoice's five money
            // columns - NOT master_promo's (12,2).
            //
            // Nothing reconciles it: a refund larger than the payment, a second
            // refund row for the same payment, and a payment with status 'refund'
            // and no refund row are ALL representable. There is no CHECK and no
            // uniqueness. Partial refunds are the point of the table, so the missing
            // uniqueness is a deliberate gap; the missing reconciliation is a real
            // limitation and is recorded in docs/schema-notes.md.
            $table->decimal('jumlah', 14, 2);

            // VARCHAR(255) NULL (:979) - free text, the only narrative field here.
            // Nullable, so a rejection with no recorded reason is representable, and
            // unstructured, so counting rejection causes means parsing this string.
            $table->string('alasan', 255)->nullable();

            // ENUM(...4 values...) NOT NULL DEFAULT 'diajukan' (:980) - four values
            // closing on its own line, so NOT wrapped. Here the ENUM ORDER IS the
            // lifecycle: diajukan -> diproses -> berhasil, with ditolak as the
            // terminal branch. The default is the START of that workflow, the same
            // shape as invoice.status defaulting to 'menunggu_pembayaran' rather
            // than 'draft'.
            $table->enum('status', ['diajukan', 'diproses', 'berhasil', 'ditolak'])->default('diajukan');

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:981) - `dibuat_at`
            // ONLY; the statement ends at :983, so there is no `diubah_at` and no
            // raw ALTER (rule 5 covers a PAIR only).
            //
            // Consequence: a FOUR-STATE WORKFLOW WITH A SINGLE TIMESTAMP. When the
            // refund was requested is recorded; when it was processed, succeeded or
            // rejected is not, and cannot be reconstructed from this table - not even
            // who rejected it. Todo 19's model needs const CREATED_AT = 'dibuat_at'
            // and const UPDATED_AT = null; any audit requirement on the transition
            // has to be met by audit_log (table 73) instead.
            $table->timestamp('dibuat_at')->useCurrent();

            // The ONE foreign key (:982), carrying **NO ON DELETE clause**, so it
            // materialises MySQL's implicit NO ACTION: a payment still named by a
            // refund cannot be deleted. Writing ->cascadeOnDelete() would be
            // foreign_key_action drift.
            //
            // This is the only `ON DELETE` clause in all of SQL section [11], which
            // means BATCH J HAS NO CASCADE ANYWHERE - every link in the
            // invoicing/payment/promo graph is a RESTRICT.
            $table->foreign('pembayaran_id')->references('id')->on('pembayaran');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refund');
    }
};
