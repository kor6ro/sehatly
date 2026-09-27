<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 66 of 75 — `telemedicine_test.sql:1000-1010`. The promo-usage ledger:
 * **6 columns** (`:1001`-`:1006`), **ONE index** (the primary key) and **exactly
 * THREE foreign keys** — the most of any table in batch J, and all three pointing
 * at tables created earlier in this same batch or in batch C.
 *
 * ## TRAP 3 — `invoice_id` is `NOT NULL`, and that RESOLVES the spec's ambiguity
 *
 * `invoice_id BIGINT UNSIGNED NOT NULL` (`:1004`). **Decision, recorded here so todo
 * 44 and todo 45 do not re-derive it differently:**
 *
 * **A `promo_redemption` row is written when a promo is APPLIED TO AN INVOICE — it
 * is NOT written by a pure `POST /promo/validasi` check.**
 *
 * The reasoning is forced by the DDL, not chosen. A validation that has not yet
 * touched an invoice has nothing to point at: the column is `NOT NULL`, there is no
 * `DEFAULT`, and no `CHECK` relaxes it. Emitting a redemption row at validation time
 * would require either a nullable column (which the DDL forbids — and which would
 * then be `column_nullable` drift), a placeholder invoice, or a second table for
 * un-committed validations. None of those exists in the contract.
 *
 * Therefore:
 *
 *  - **`POST /promo/validasi` must be a pure read.** It evaluates `master_promo`'s
 *    window, `status_aktif`, `min_transaksi`, `nilai`/`maks_diskon` and the
 *    caller's prior usage, and **writes nothing**. A validation that "uses up" a
 *    quota would consume a redemption for an invoice that may never be created.
 *  - **The redemption row is written when the invoice is created with the discount
 *    applied**, inside the same transaction as the invoice and the payment attempt.
 *    That is the moment `referensi_id` exists and the moment the quota is genuinely
 *    consumed.
 *  - **`nilai_diskon` (`:1005`) is the discount AS APPLIED**, i.e. after
 *    `maks_diskon` capping and after `gratis_ongkir` resolves to a shipping amount.
 *    It is a snapshot: editing `master_promo.nilai` afterwards must not retroactively
 *    change what a past invoice was discounted by, and nothing keeps them in step.
 *  - **Two failed paths follow, and both are real.** An invoice created with a promo
 *    and then abandoned consumes a redemption with no way to release it, and a
 *    re-applied promo on a *new* invoice for the same cart consumes a second one.
 *    There is no `status`, no `voided_at` and no unique key on this table to make
 *    either recoverable, so the quota is consumed permanently. If that matters to
 *    the business, the release has to be a service-level compensating delete.
 *
 * **Do NOT make `invoice_id` nullable to accommodate the validation call.** That is
 * `column_nullable` drift, it is exactly the mistake this comment exists to prevent,
 * and it would make the plan's own failure-QA assertion — that the verifier flags
 * `promo_redemption.invoice_id` if emitted as nullable — pass for the wrong reason.
 *
 * ## The three foreign keys, and what is NOT constrained
 *
 * `promo_id` -> `master_promo(id)` (`:1007`, table 65 — the immediately preceding
 * migration in this batch), `pasien_id` -> `pasien(id)` (`:1008`, table 20, batch C)
 * and `invoice_id` -> `invoice(id)` (`:1009`, table 62, this batch). **All three
 * carry no `ON DELETE` clause**, so all three materialise MySQL's implicit
 * `NO ACTION`: a promo, a patient and an invoice each still named by a redemption
 * cannot be deleted. `->cascadeOnDelete()` on any of them would be
 * `foreign_key_action` drift.
 *
 * That is the right shape for a ledger — deleting the promotion that was used must
 * not erase the record that it was used — and it is why `master_promo` must be
 * retired via `status_aktif` (`:997`) rather than deleted. Compare batch I, whose
 * `lab_hasil` rows **do** cascade from their parent: a lab result is transient
 * clinical evidence, a redemption is a financial fact. The difference is deliberate
 * in the DDL and must not be flattened.
 *
 * **This table has NO unique key of any kind — only the primary key.** That is the
 * single most consequential fact about it:
 *
 *  - **`master_promo.kuota_per_user` (`:994`, default 1) cannot be enforced by this
 *    schema.** Nothing stops the same patient redeeming the same promo on ten
 *    invoices. The quota is a convention the service enforces by counting.
 *  - **The same holds for `kuota_total` (`:993`).**
 *  - **A double-submission race is unguarded**: two concurrent redemptions of the
 *    same promo against the same invoice both insert, and the discount is counted
 *    twice by any report that sums `nilai_diskon`. There is no
 *    `UNIQUE (promo_id, invoice_id)` and none can be added — the DDL is read-only
 *    law (`docs/migration-order.md` rule 7). `DB::transaction()` plus a
 *    `lockForUpdate()` on the `invoice` row, or an `INSERT … SELECT … WHERE NOT
 *    EXISTS`, is the only available mitigation.
 *
 * **What IS enforced, and is worth knowing:** the foreign keys do guarantee that a
 * redemption's `promo_id`, `pasien_id` and `invoice_id` all point at real rows, so
 * the ledger cannot contain a dangling half. They do **not** guarantee the three
 * agree *with each other* — nothing requires
 * `promo_redemption.pasien_id = invoice.pasien_id`. A redemption naming patient A
 * and an invoice belonging to patient B is representable, and it is exactly the
 * check a service must perform, because nothing else will.
 *
 * ## `nilai_diskon` is `DECIMAL(12,2) NOT NULL` and `dibuat_at` is the ONLY timestamp
 *
 * `:1005` is `DECIMAL(12,2) NOT NULL` with no default, so it is required at insert
 * and do not add `->default(0)`. Its scale is `(12,2)`, matching `master_promo`'s
 * `nilai`/`min_transaksi`/`maks_diskon` (`:990`-`:992`) and **not** `invoice`'s
 * `(14,2)` or `pembayaran`'s `(14,2)` — the scale differs by design because the two
 * sides of the discount are different quantities.
 *
 * `:1006` is `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and the
 * statement ends at `:1010`. There is **no `diubah_at`**, so this is one of the 19
 * "dibuat_at only" tables: rule 5's `dibuat_at`/`diubah_at` pair does not apply and
 * there is **no raw `ALTER` here**. `$table->timestamps()` would emit
 * `created_at`/`updated_at` and produce both `missing_column` and `extra_column`
 * drift. **Todo 19's `PromoRedemption` needs `const CREATED_AT = 'dibuat_at'` and
 * `const UPDATED_AT = null`.**
 *
 * `PRIMARY KEY (id)` (`:1001`) covers **none** of the three foreign keys, so InnoDB
 * builds **three** implicit support indexes absent from the DDL; they are treated as
 * implied by the matched foreign keys (commit `27c6ca8`) and must not be suppressed
 * by declaring indexes of our own.
 *
 * **This batch owes no deferred constraint.** All three targets already exist when
 * this migration runs — `master_promo` one migration earlier in this same batch,
 * `pasien` in batch C, `invoice` four migrations earlier in this batch.
 * `fk_vital_rm` (`pasien_tanda_vital.rekam_medis_id`, SQL section `[14]` `:1161`-
 * `:1163`) remains the only row in the *Deferred constraints* registry in
 * `docs/schema-notes.md` and the only constraint migration `2026_10_01_000076` adds.
 *
 * Exposed: `docs/migration-order.md` row 66 — Module M5 payment, Resource 45,
 * Controller 45.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('promo_redemption', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1001). Covers none of the
            // three foreign keys below, so InnoDB adds three implicit support indexes.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:1002) -> master_promo.id, table 65, the
            // immediately preceding migration in this same batch.
            $table->unsignedBigInteger('promo_id');

            // BIGINT UNSIGNED NOT NULL (:1003) -> pasien.id, table 20, batch C.
            //
            // Nothing requires this to equal invoice.pasien_id for the invoice named
            // below: a redemption pairing patient A with patient B's invoice is
            // representable, and the foreign keys do not catch it. The service must.
            $table->unsignedBigInteger('pasien_id');

            // ## TRAP 3 - `invoice_id BIGINT UNSIGNED NOT NULL` (:1004) IS THE
            // COLUMN THAT RESOLVES THE SPEC'S AMBIGUITY. **NOT NULL, NO DEFAULT,
            // NO `CHECK` THAT RELAXES IT.**
            //
            // DECISION: a promo_redemption row is written when a promo is APPLIED TO
            // AN INVOICE. It is **NOT** written by a pure `POST /promo/validasi`
            // check. A validation that has not yet touched an invoice has nothing to
            // point at, and the DDL gives it no way to say so: the column is NOT
            // NULL, has no DEFAULT, and no CHECK forgives it. Emitting a row at
            // validation time would require a nullable column (column_nullable
            // drift), a placeholder invoice, or a second table for un-committed
            // validations - none of which exists in the contract.
            //
            // CONSEQUENCES todo 44 and todo 45 must honour:
            //  - `POST /promo/validasi` is a PURE READ and writes nothing; a
            //    validation that consumed a quota would spend a redemption on an
            //    invoice that may never exist.
            //  - The row is written when the invoice is created with the discount
            //    applied, in the same transaction as the invoice.
            //  - `nilai_diskon` below is the discount AS APPLIED, a snapshot after
            //    capping and after gratis_ongkir resolution; editing
            //    master_promo.nilai must not rewrite it retroactively.
            //  - An abandoned invoice, or re-applying the promo to a second invoice
            //    for the same cart, consumes a redemption permanently: this table
            //    has no status, no voided_at and no unique key, so there is no
            //    recoverable release. Any quota release must be a service-level
            //    compensating delete.
            //
            // DO NOT make this nullable to accommodate the validation call. That is
            // the exact mistake this comment exists to prevent, and it would make
            // the verifier flag the column for the wrong reason.
            $table->unsignedBigInteger('invoice_id');

            // DECIMAL(12,2) NOT NULL (:1005) - the discount AS APPLIED, a snapshot
            // after maks_diskon capping and after gratis_ongkir resolves to a
            // shipping amount. NO DEFAULT, so required at insert; do not add
            // ->default(0). Scale (12,2) matches master_promo's three money columns
            // and deliberately does NOT match invoice's or pembayaran's (14,2) - the
            // two sides of a discount are different quantities.
            $table->decimal('nilai_diskon', 12, 2);

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1006) - `dibuat_at`
            // ONLY; the statement ends at :1010, so no `diubah_at` and NO raw ALTER
            // (rule 5 covers a PAIR only). $table->timestamps() would be doubly
            // wrong: it emits created_at/updated_at and the second does not exist.
            $table->timestamp('dibuat_at')->useCurrent();

            // THREE foreign keys, **none with an ON DELETE clause**, so all three
            // materialise MySQL's implicit NO ACTION. ->cascadeOnDelete() on any of
            // them would be foreign_key_action drift. This is the right shape for a
            // financial ledger - deleting the promotion that was used must not erase
            // the record that it was used - and it is why master_promo must be
            // retired via status_aktif (:997) rather than deleted. Contrast batch I,
            // where lab_hasil rows DO cascade from lab_permintaan: a lab result is
            // transient evidence, a redemption is a financial fact.
            $table->foreign('promo_id')->references('id')->on('master_promo');
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('invoice_id')->references('id')->on('invoice');

            // ## NO UNIQUE KEY IS DECLARED, AND THAT IS LOAD-BEARING.
            //
            // There is nothing to add here: the DDL declares no unique key, and one
            // cannot be invented (docs/migration-order.md rule 7 - an added index is
            // `extra_index` drift). The consequences, stated so they are treated as
            // the schema's answer rather than as an oversight:
            //
            //  - `master_promo.kuota_per_user` (:994, default 1) CANNOT be enforced
            //    by this schema. Nothing stops one patient redeeming one promo on
            //    ten invoices. The quota is a convention the service counts.
            //  - `master_promo.kuota_total` (:993) is unenforced for the same reason.
            //  - A double-submission race is unguarded: two concurrent redemptions
            //    for the same promo on the same invoice both insert, and any report
            //    that sums nilai_diskon counts the discount twice. Mitigation is a
            //    transaction plus lockForUpdate() on the invoice row, or an
            //    INSERT ... SELECT ... WHERE NOT EXISTS - not a unique key.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_redemption');
    }
};
