<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 63 of 75 — `telemedicine_test.sql:958-973`. The gateway-transaction
 * table: **11 columns** (`:959`-`:969`), **TWO indexes** (the primary key and
 * `idx_bayar_status`) and **exactly TWO foreign keys** — one of them pointing at
 * `master_metode_pembayaran` (`:971`, table 61, this same batch) and one at
 * `invoice` (`:970`, table 62, this same batch).
 *
 * Column-count position in this batch, so no reader has to guess: with 11 columns it
 * sits fourth of the seven — `invoice` and `klaim_bpjs` have 15 each, `master_promo`
 * 12, then this table, then `master_metode_pembayaran` 8, and `refund` and
 * `promo_redemption` 6 each.
 *
 * ## TRAP 2 — `nomor_referensi` HAS **NO INDEX AND NO UNIQUE**, and that is the
 * ## contract. The webhook-idempotency check is a full scan.
 *
 * `nomor_referensi VARCHAR(100) NULL COMMENT 'Transaction ID payment gateway'`
 * (`:963`). It is the column a payment-gateway callback is matched on, and the DDL
 * gives it **no `UNIQUE` and no index of any kind**. The statement ends at `:973`;
 * the table's only non-primary index is `INDEX idx_bayar_status (status, dibayar_at)`
 * (`:972`), which cannot help because a webhook arrives knowing the transaction id
 * and nothing about `status` or `dibayar_at`.
 *
 * **Do NOT add an index on `nomor_referensi`.** `telemedicine_test.sql` is
 * read-only law, an added index is `extra_index` drift, and
 * `docs/migration-order.md` rule 7 says so explicitly. There is no
 * `webhook_event_id` column to index instead — the DDL has none.
 *
 * **The performance trade-off, stated so it is not rediscovered as a bug.**
 *
 *  - **Correctness** is unaffected. The idempotency check is a plain
 *    `where nomor_referensi = ?` existence query — written together with the
 *    `gateway` predicate — executed *inside* the update transaction. Nothing about a
 *    missing index changes the answer, only how long it takes to get it.
 *  - **Cost.** Every webhook callback is a full table scan of `pembayaran`. The
 *    table is expected to hold one row per payment attempt, so the scan grows
 *    linearly with lifetime order volume. At low volume this is invisible; it is
 *    also a lock-acquisition cost, because a full scan under InnoDB's default
 *    `REPEATABLE READ` takes next-key locks that widen the webhook's write
 *    contention. This is a real operational cost and the DDL chose it.
 *  - **Uniqueness is NOT guaranteed by the database.** A duplicate `nomor_referensi`
 *    is representable, and two concurrent callbacks for the same transaction can
 *    both pass the existence check before either commits — the check-then-act race
 *    is accepted, exactly as the plan's own schema-reality list records. Todo 45's
 *    webhook handler must therefore be **idempotent in its side effects** (setting
 *    `status` and `dibayar_at` twice is harmless; crediting a wallet twice is not)
 *    rather than relying on the check to make the operation unique.
 *  - **NULL is the majority case for non-gateway methods.** `COD` and `tunai` are
 *    settled inside the platform and have no gateway transaction id, so a
 *    `nomor_referensi IS NULL` row is normal. A lookup must therefore never assume
 *    the column is populated, and must not treat a NULL match as an idempotent
 *    replay of some other NULL row.
 *
 * ## `metode_id` is `SMALLINT UNSIGNED`, not BIGINT
 *
 * `:961`. It points at `master_metode_pembayaran.id`, which is
 * `SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` (`:926`) — one of the 18 contract
 * tables with a non-`BIGINT` id. `foreignId('metode_id')` would emit
 * `BIGINT UNSIGNED` and be wrong on both the column and the constraint, which is why
 * this table has a hand-written `->foreign()` like every other table in the project
 * and not `->constrained()`.
 *
 * ## `webhook_payload JSON NULL` (`:968`) is `json()`, never `text()`
 *
 * `->json()` emits the `json` type; `->text()` emits `longtext` and is
 * `column_type` drift. The column is nullable, so a payment created by the platform
 * itself carries no payload. **Nothing in the DDL validates its shape and nothing
 * queries into it** — there is no generated column and no index, so it is an opaque
 * archive of whatever the gateway sent, useful for post-hoc dispute handling and
 * useless for querying. It is the correct place to keep the raw body; a service that
 * wants a specific gateway field must read and validate it in PHP.
 *
 * ## `gateway` is a **four**-value ENUM and it is **nullable**
 *
 * `ENUM('midtrans','xendit','doku','flip') NULL` (`:965`). Four values, all four on
 * one line, so this is **not** a wrapped ENUM. NULL is the normal state for
 * `cod`/`tunai`, which have no acquirer. The value is a **label, not a foreign key**:
 * there is no gateway table in the contract, so the integration is configuration in
 * the application.
 *
 * ## `status` is a **five**-value ENUM, and `kedaluwarsa` is spelled with a `d`
 *
 * `ENUM('pending','berhasil','gagal','kedaluwarsa','refund') NOT NULL DEFAULT
 * 'pending'` (`:966`). Five values, closing on its own line, so **not** wrapped. The
 * fourth value is **`kedaluwarsa`** — with a `d` — whereas `invoice.status`'s fourth
 * value at `:947` is **`kadaluarsa`**, with no `d`. **The contract uses both
 * spellings and both are reproduced exactly; a "consistency cleanup" across the two
 * tables would break one of them.**
 *
 * Measured over the whole file, so the asymmetry is stated rather than guessed: as
 * an ENUM member, `kedaluwarsa` appears **3** times (`rujukan.status` `:610`,
 * `resep.status` `:752`, `pembayaran.status` `:966`) and `kadaluarsa` **2** times
 * (`booking.status` `:516`, `invoice.status` `:947`). The `d` form is also the only
 * one used for expiry **column** names: `user_otp.kedaluwarsa_at` (`:184`),
 * `user_refresh_tokens.kedaluwarsa_at` (`:208`) and `apotek_stok.kedaluwarsa` (`:836`).
 * So `invoice.status` is in the minority spelling and the majority form is the
 * better guess for any *new* name — which is precisely why the existing one must
 * not be changed. Note also that `refund` here is the *payment*'s state after a
 * refund, distinct from the `refund` table (64) that records the money movement.
 *
 * The default is `pending`, which is the only sensible default here and is written
 * out so it is not re-derived.
 *
 * ## `jumlah` is `DECIMAL(14,2) NOT NULL` with **no default**
 *
 * `:962`. The amount actually attempted or captured, required at insert. As on
 * `invoice.total` (`:946`), a `->default(0)` here would be `column_default` drift and
 * would let a zero-value payment row exist.
 *
 * `dibayar_at DATETIME NULL` (`:967`) is set when the payment succeeds and is
 * **half of `idx_bayar_status (status, dibayar_at)`** (`:972`) — the reconciliation
 * and revenue queries read it. `dateTime()`, never `timestamp()`: it is an unzoned
 * caller-supplied moment, and `v_pendapatan_bulanan` (declared at `:1190`-`:1196`)
 * groups on `DATE_FORMAT(p.dibayar_at, '%Y-%m')` at `:1191`, i.e. server-local
 * wall clock with no timezone in the expression, which is a third instance of the
 * timezone duality the project already records.
 *
 * `va_number VARCHAR(30) NULL` (`:964`) is the Virtual Account number a `va_bank`
 * method issues. It is **not** unique and **not** indexed: two payments may quote the
 * same VA number, and looking one up is a scan. A unique index cannot be added
 * (`docs/migration-order.md` rule 7).
 *
 * ## `dibuat_at` only — no `diubah_at`, so no raw `ALTER` here
 *
 * `:969` is `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and the
 * statement ends at `:973`. `pembayaran` is one of the 19 "dibuat_at only" tables,
 * so the pair from `docs/migration-order.md` rule 5 does **not** apply and there is
 * no `DB::statement`. **Todo 19's model needs `const CREATED_AT = 'dibuat_at'` and
 * `const UPDATED_AT = null`** — or, more honestly, `$timestamps = false` plus an
 * explicit `dibuat_at` default, because a `pembayaran` row records when the attempt
 * was made and **never** when it last changed: a payment that moves `pending` ->
 * `berhasil` leaves no trace of when. `dibayar_at` is the only transition timestamp
 * that exists, and it exists only for the success path.
 *
 * `PRIMARY KEY (id)` (`:959`) covers **neither** foreign key, so InnoDB builds two
 * implicit support indexes absent from the DDL; they are treated as implied by the
 * matched foreign keys (commit `27c6ca8`) and must not be suppressed with indexes of
 * our own.
 *
 * **This batch owes no deferred constraint.** Both foreign keys point at tables
 * created by migrations 61 and 62 in this same commit, earlier in the same batch.
 * `fk_vital_rm` remains the only registered deferral.
 *
 * Exposed: `docs/migration-order.md` row 63 — Module M5 payment, Resource 45,
 * Controller 45.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pembayaran', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:959). Covers neither
            // foreign key below, so InnoDB adds two implicit support indexes.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:960) - an attempt is always against an
            // invoice. `invoice` is table 62, created by the immediately preceding
            // migration in this same batch.
            $table->unsignedBigInteger('invoice_id');

            // SMALLINT UNSIGNED NOT NULL (:961) - NOT BIGINT: the target
            // `master_metode_pembayaran`.id is SMALLINT UNSIGNED (:926), one of the
            // 18 contract tables with a non-BIGINT id, so `foreignId()` would be
            // wrong on the column and on the constraint.
            $table->unsignedSmallInteger('metode_id');

            // DECIMAL(14,2) NOT NULL (:962) - the attempted/captured amount.
            // NO DEFAULT, same deliberate asymmetry as invoice.total (:946): do not
            // add ->default(0), that is column_default drift.
            $table->decimal('jumlah', 14, 2);

            // ## TRAP 2 - `nomor_referensi` (`:963`) has **NO UNIQUE and NO INDEX**.
            //
            // DO NOT add an index here. telemedicine_test.sql is read-only law, an
            // added index is `extra_index` drift, and docs/migration-order.md rule 7
            // forbids it. The only non-primary index in the DDL is
            // idx_bayar_status (status, dibayar_at) at :972, which cannot serve a
            // lookup that knows only the transaction id.
            //
            // THE TRADE-OFF, recorded so it is not rediscovered as a defect: the
            // webhook idempotency check is a `where nomor_referensi = ?` existence
            // query (with the `gateway` predicate) run inside the update
            // transaction. Correctness is unaffected - a missing index changes how
            // long the answer takes, not what it is. The cost is a full table scan
            // of `pembayaran` on every gateway callback, growing linearly with
            // lifetime order volume, and under REPEATABLE READ the next-key locks a
            // full scan takes widen the callback's write contention.
            //
            // Uniqueness is NOT database-enforced and is not added: a duplicate
            // nomor_referensi is representable, and two concurrent callbacks for the
            // same transaction can both pass the check before either commits. Todo
            // 45's webhook handler must be idempotent in its SIDE EFFECTS rather
            // than trusting the check to make the operation unique.
            //
            // NULL is the normal case for `cod` and `tunai`, which have no gateway
            // transaction id at all, so a lookup must never assume the column is
            // populated and must not treat a NULL match as a replay of another NULL.
            $table->string('nomor_referensi', 100)->nullable();

            // VARCHAR(30) NULL (:964) - the Virtual Account number a `va_bank`
            // method issues. NOT unique and NOT indexed: two payments may quote the
            // same number and a lookup is a scan. A unique index cannot be added
            // (docs/migration-order.md rule 7).
            $table->string('va_number', 30)->nullable();

            // ENUM(...4 values...) NULL (:965) - a LABEL, not a foreign key: the
            // contract has no gateway table. Four values on one line, so NOT a
            // wrapped ENUM. NULL is normal for cod/tunai.
            $table->enum('gateway', ['midtrans', 'xendit', 'doku', 'flip'])->nullable();

            // ENUM(...5 values...) NOT NULL DEFAULT 'pending' (:966) - five values
            // closing on its own line, so NOT wrapped. The fourth value is
            // `kedaluwarsa` (WITH a d), whereas invoice.status at :947 spells the
            // same concept `kadaluarsa` (WITHOUT a d). Both spellings are
            // reproduced exactly; do not "harmonise" them. The verifier has no
            // dedicated enum-value code: SchemaDiffer::diffColumn() compares
            // $want->type against $have->type and an ENUM's canonical type IS the
            // whole ordered value list (`enum('a','b')`), so a changed member or a
            // changed order is reported as `column_type` drift.
            $table->enum('status', ['pending', 'berhasil', 'gagal', 'kedaluwarsa', 'refund'])->default('pending');

            // DATETIME NULL (:967) - set when the payment succeeds, and the second
            // column of idx_bayar_status below. `dateTime()`, never `timestamp()`:
            // unzoned caller-supplied moment, and v_pendapatan_bulanan (declared at
            // :1190-:1196) groups on DATE_FORMAT(p.dibayar_at, '%Y-%m') at :1191 -
            // server-local wall clock, with no timezone in the expression.
            $table->dateTime('dibayar_at')->nullable();

            // JSON NULL (:968) - `json()`, NEVER `text()`. Nullable, unvalidated by
            // the DDL, not indexed and not generated into: an opaque archive of the
            // raw gateway body, useful for disputes and useless for querying.
            $table->json('webhook_payload')->nullable();

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:969) - `dibuat_at` ONLY.
            // The statement ends at :973, so there is no `diubah_at` and therefore no
            // raw ALTER (docs/migration-order.md rule 5 applies only to a PAIR).
            //
            // Consequence to carry into todo 19: a payment that moves pending ->
            // berhasil leaves NO trace of when, and $table->timestamps() would be
            // wrong twice over (it would emit created_at/updated_at, the second of
            // which does not exist here). `dibayar_at` is the only transition
            // timestamp and it exists only on the success path.
            $table->timestamp('dibuat_at')->useCurrent();

            // The ONLY non-primary index in the DDL (:972) - a composite
            // (status, dibayar_at) for the reconciliation/revenue read. It is NOT a
            // covering index for either foreign key and must not be repurposed as
            // one. It is also NOT an index on nomor_referensi: see TRAP 2 above.
            $table->index(['status', 'dibayar_at'], 'idx_bayar_status');

            // TWO foreign keys, both carrying **NO ON DELETE clause**, so both
            // materialise MySQL's implicit NO ACTION: an invoice still named by a
            // payment cannot be deleted, and a payment method still in use cannot be
            // deleted. Writing ->cascadeOnDelete() on either would be
            // foreign_key_action drift. Note the asymmetry this creates: a payment
            // method is a master row the application must retire via
            // status_aktif (not by deleting it) precisely because this FK restricts.
            $table->foreign('invoice_id')->references('id')->on('invoice');
            $table->foreign('metode_id')->references('id')->on('master_metode_pembayaran');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pembayaran');
    }
};
