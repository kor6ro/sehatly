<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 62 of 75 — `telemedicine_test.sql:936-956`. **The polymorphic hub of
 * batch J**, and the only table in the whole contract that stores a foreign key as
 * a bare integer plus a discriminator.
 *
 * **16 columns** (`:937`-`:952`), **THREE named indexes plus the primary key and
 * the inline `UNIQUE`** — the only table in this batch with more than one named
 * index — and **exactly ONE foreign key**.
 *
 * ## TRAP 1 — `referensi_id` IS **BARE**, AND NO FOREIGN KEY IS POSSIBLE
 *
 * `referensi_tipe ENUM('booking','konsultasi','resep','pesanan_obat',
 * 'lab_permintaan','home_care') NOT NULL` (`:940`) and `referensi_id BIGINT UNSIGNED
 * NOT NULL COMMENT 'Polimorfik'` (`:941`) are a **discriminated union**: the
 * discriminator says which of six tables `referensi_id` points at.
 *
 * **Do NOT write `->foreign('referensi_id')->references('id')->on(...)`.** Four
 * reasons, all pointing the same way:
 *
 *  1. **It is not expressible.** A foreign key names exactly one parent table, and
 *     this column's parent is a function of `referensi_tipe`. There is no DDL that
 *     constrains a value against a table chosen at insert time.
 *  2. **The DDL declares no `FOREIGN KEY` clause for it.** The only clause in this
 *     `CREATE TABLE` is `:953`, on `pasien_id`. The plan's own generated
 *     reference-shaped-but-no-FK list names `invoice.referensi_id` explicitly.
 *  3. **It would be a parity break, not just a design error.** Every target exists
 *     by the time this migration runs — `booking` 37, `konsultasi` 38, `resep` 49,
 *     `pesanan_obat` 52 are all earlier batches and `lab_permintaan` 58 /
 *     `home_care_pesanan` 72 are later — so a constraint would *succeed* and become
 *     permanent `extra_foreign_key` drift while `migrate:fresh`, `php -l` and the
 *     entire 93-test unit suite stayed green.
 *  4. **Ownership is a service-layer concern and the schema says so.** The `Polimorfik`
 *     `COMMENT` at `:941` is the DDL's own acknowledgement that the pairing is not
 *     a join. A service that writes an invoice must derive `referensi_tipe` from the
 *     record it is billing and must verify the `referensi_id` exists *in that table*
 *     before inserting; nothing in the database will do either.
 *
 * **The width is nevertheless correct for all six targets, and that is not
 * coincidence.** All six parent tables declare `id BIGINT UNSIGNED PRIMARY KEY
 * AUTO_INCREMENT` — `booking` (`:499`), `konsultasi` (`:537`), `resep` (`:743`),
 * `pesanan_obat` (`:798`), `lab_permintaan` (`:877`) and `home_care_pesanan`
 * (`:1094`) — which is what lets one column hold any of them.
 *
 * ## TRAP 1b — `INDEX idx_ref (referensi_tipe, referensi_id)` (`:955`) IS THE ONLY
 * ## INDEX THAT MAKES THE POLYMORPHISM AFFORDABLE
 *
 * Reproduced by name **and column order**: `referensi_tipe` first, `referensi_id`
 * second. The order is not cosmetic — the lookup is `WHERE referensi_tipe = ? AND
 * referensi_id = ?` and both predicates are equalities, so a reversed
 * `(referensi_id, referensi_tipe)` would be equally usable, but a *prefix-only* use
 * such as "every invoice for this patient" or "every pending invoice" cannot use it
 * at all, and neither can a range on `referensi_id`. Reverse the pair and a query
 * the contract intends to be cheap becomes a full scan. The verifier compares this
 * index's ordered column list, so a reversal is reported as `index_columns` drift —
 * but the negative QA in this todo's evidence shows what a **deletion** does.
 *
 * `INDEX idx_invoice (pasien_id, status)` (`:954`) is the other one, and it is the
 * invoice-list index: it is covered by the single foreign key on `pasien_id` only
 * for its first column, so the two-column form is genuine DDL and not an InnoDB
 * artefact. Neither index may be "tidied up": the SQL is read-only law and
 * `docs/migration-order.md` rule 7 forbids adding an index the DDL lacks.
 *
 * ## `status` is a **WRAPPED** ENUM — `:947`-`:948`, seven values, and reading
 * ## `:947` alone is a real defect
 *
 * ```sql
 * status ENUM('draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan',
 *             'refund_sebagian','refund_penuh') NOT NULL DEFAULT 'menunggu_pembayaran',
 * ```
 *
 * The value list **continues onto `:948`**. This is one of exactly **five** ENUMs
 * in the whole contract that span two physical lines (the other four are
 * `booking.status` `:515-516`, `master_obat.bentuk_sediaan` `:713-714`,
 * `resep.status` `:751-752` and `artikel_kategori.jenis` `:1137-1138`), derived by
 * locating every `ENUM(` whose own line lacks its closing paren. Reading `:947` alone
 * yields a **five**-value list (`draft`, `menunggu_pembayaran`, `lunas`,
 * `kadaluarsa`, `dibatalkan`) and, because the `)` and the `NOT NULL DEFAULT` live
 * on the next line, it also hides the `NOT NULL DEFAULT 'menunggu_pembayaran'`
 * clause entirely, so a reader of `:947` alone would conclude the column is
 * nullable and carries no default value.
 * Both are wrong, and the negative QA in this todo's evidence demonstrates the
 * verifier catching exactly that truncation.
 *
 * The order is semantic — it is the sort index — and all **seven** values are
 * reproduced: `draft`, `menunggu_pembayaran`, `lunas`, `kadaluarsa`, `dibatalkan`,
 * `refund_sebagian`, `refund_penuh`.
 *
 * **The default is `'menunggu_pembayaran'`, which is a surprising default and is
 * stated here so no later reader has to guess.** An invoice row is created *already
 * awaiting payment*, never as a `draft`. `draft` is the first ENUM member and is
 * reachable only by an explicit write. A service that omits `status` and believes it
 * is building a draft will publish a payable invoice.
 *
 * `dibatalkan` sits at position 5, **before** both refund states, so a plain
 * `ORDER BY status` or a "first non-terminal status wins" scan orders a
 * `refund_sebagian` invoice after a cancelled one. Do not read position as a
 * lifecycle sequence.
 *
 * ## `total` is the ONLY money column with **NO DEFAULT**
 *
 * `subtotal`, `diskon`, `biaya_admin` and `biaya_pengiriman` are all
 * `DECIMAL(14,2) NOT NULL DEFAULT 0` (`:942`-`:945`). `total DECIMAL(14,2) NOT
 * NULL` (`:946`) has **no `DEFAULT`**, so it is required at insert and the database
 * will not compute it. This is the one deliberate asymmetry in the money block, and
 * it is the right way round: a zero-defaulted total would silently accept an
 * unpriced invoice. Todo 45 must write it explicitly as
 * `subtotal - diskon + biaya_admin + biaya_pengiriman` and must not rely on a
 * default. **Do not "fix" `:946` by adding `->default(0)`** — that is `column_default`
 * drift.
 *
 * All five money columns are `DECIMAL(14,2)`. Do not confuse them with
 * `master_metode_pembayaran`'s `(12,2)`/`(5,2)` or `master_promo`'s `(12,2)`; three
 * different scales appear in this batch and the verifier compares them.
 *
 * ## `jatuh_tempo` and `lunas_at` are `DATETIME`, nullable, and unrelated
 *
 * Neither has a default and neither is a timestamp column.
 * `jatuh_tempo DATETIME NULL` (`:949`) is the payment deadline the application sets
 * when it issues the invoice — the schema does not derive it from anything, and
 * there is no expiry job in the DDL, so the transition of a past-due invoice to
 * `kadaluarsa` is a scheduled command the application must write.
 * `lunas_at DATETIME NULL` (`:950`) is set when the invoice reaches `lunas`. Both
 * are caller-supplied unzoned moments: `dateTime()`, never `timestamp()`, because
 * MySQL's `TIMESTAMP` is stored as UTC and converted on read and emitting one here
 * would be `column_type` drift. This is the same duality
 * `v_pendapatan_bulanan` already sits on the other side of: that view declares
 * itself at `:1190`-`:1196` and groups on `DATE_FORMAT(p.dibayar_at, '%Y-%m')` at
 * `:1191`, i.e. in server-local wall clock, with no timezone in the expression.
 *
 * ## `dibuat_at` / `diubah_at` — one of the **16** tables that carry the raw `ALTER`
 *
 * `:951` and `:952` are `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`, the second
 * with `ON UPDATE CURRENT_TIMESTAMP`. Laravel 13 has no Blueprint helper for
 * `ON UPDATE`, so both are declared by hand with `->useCurrent()` and the raw
 * `DB::statement` below follows. `$table->timestamps()` is exactly wrong here: it
 * would emit `created_at`/`updated_at`.
 *
 * `PRIMARY KEY (id)` (`:937`) does not cover `pasien_id`, so InnoDB builds one
 * implicit support index that is **absent from the DDL** and is treated as implied
 * by the matched foreign key (commit `27c6ca8`). It must not be suppressed by
 * declaring a covering index of our own.
 *
 * **This batch owes no deferred constraint.** `fk_vital_rm`
 * (`pasien_tanda_vital.rekam_medis_id`, SQL section `[14]` `:1161`-`:1163`) remains
 * the only row in the *Deferred constraints* registry in `docs/schema-notes.md` and
 * the only constraint migration `2026_10_01_000076` adds. Nothing here is deferred
 * and nothing here defers: the single foreign key points at `pasien` (table 20,
 * batch C), which already exists.
 *
 * **Only four of the six `referensi_tipe` values are reachable in Modules 1-5** —
 * `booking` (M2), `konsualitas` (M3), `resep` (M4) and `pesanan_obat` (M5). The
 * other two, `lab_permintaan` and `home_care_pesanan`, are `Module: ORPHAN`,
 * `Resource: —`, `Controller: —` in `docs/migration-order.md` (rows 58 and 72), so
 * no endpoint in the plan's scope creates an invoice of either type. That is a fact
 * about the **application**, not about the DDL, and it is recorded in
 * `docs/schema-notes.md` rather than here — a comment implying the SQL says it
 * would be a false claim.
 *
 * Exposed: `docs/migration-order.md` row 62 — Module M5 payment, Resource 45,
 * Controller 45.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:937). Width is correct for
            // all six referensi_tipe targets, which all declare BIGINT UNSIGNED ids.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // VARCHAR(30) NOT NULL UNIQUE (:938) - the human-facing document number.
            // Inline UNIQUE, so MySQL names the index `nomor_invoice` and it is
            // compared by semantics rather than by Laravel's generated name (rule 10).
            $table->string('nomor_invoice', 30)->unique();

            // BIGINT UNSIGNED NOT NULL (:939) - the ONLY constrained reference on this
            // table. `pasien` is table 20 (batch C), long before this migration.
            $table->unsignedBigInteger('pasien_id');

            // ENUM(...6 values...) NOT NULL (:940) - the polymorphic discriminator,
            // NOT NULL, no default. Six values on ONE line; this is not a wrapped
            // ENUM. Two of the six (lab_permintaan, home_care_pesanan) are
            // module-orphaned, so no endpoint in Modules 1-5 can produce them.
            $table->enum('referensi_tipe', ['booking', 'konsultasi', 'resep', 'pesanan_obat', 'lab_permintaan', 'home_care']);

            // ## TRAP 1 - `referensi_id BIGINT UNSIGNED NOT NULL` (:941) IS **BARE**,
            // AND NO FOREIGN KEY IS POSSIBLE.
            //
            // The DDL's own COMMENT on this line is `Polimorfik`. This column is a
            // discriminated union with `referensi_tipe` (:940): the parent table is
            // chosen at insert time from six candidates, and a foreign key can name
            // only one parent. DO NOT write
            // `->foreign('referensi_id')->references('id')->on('...')` - it is not
            // expressible, the DDL declares no FOREIGN KEY clause for it, and every
            // one of the six targets already exists by the time this migration runs,
            // so a constraint would SUCCEED and become permanent
            // `extra_foreign_key` drift while `migrate:fresh`, `php -l` and the whole
            // 93-test unit suite all stayed green.
            //
            // Ownership is a SERVICE-LAYER responsibility: the writer must derive
            // `referensi_tipe` from the record being billed and must confirm the
            // `referensi_id` exists in THAT table. Nothing in the database checks
            // either, and a mismatched pair is a dangling reference that every
            // downstream read will treat as a real row.
            //
            // Proven FK-free against information_schema.REFERENTIAL_CONSTRAINTS
            // joined to KEY_COLUMN_USAGE - not by reading SHOW CREATE TABLE, which
            // only shows constraints that exist and cannot distinguish "absent"
            // from "not looked for". The column was separately confirmed to EXIST as
            // `bigint unsigned` NOT NULL, so "no row" cannot be read as "no column".
            $table->unsignedBigInteger('referensi_id');

            // DECIMAL(14,2) NOT NULL DEFAULT 0 (:942) - gross amount before discount
            // and fees. Default 0 is a real zero, not "unset".
            $table->decimal('subtotal', 14, 2)->default(0);

            // DECIMAL(14,2) NOT NULL DEFAULT 0 (:943) - the discount. Stored as a
            // POSITIVE amount, not a negative one; the sign convention is the
            // service's, and nothing in the schema subtracts it for you.
            $table->decimal('diskon', 14, 2)->default(0);

            // DECIMAL(14,2) NOT NULL DEFAULT 0 (:944) - the admin fee. Note this is
            // the AMOUNT already computed; the per-method percentage and flat fee
            // live on master_metode_pembayaran (:931-:932) and are resolved by the
            // service, not by a trigger or a generated column.
            $table->decimal('biaya_admin', 14, 2)->default(0);

            // DECIMAL(14,2) NOT NULL DEFAULT 0 (:945) - shipping. The plan's todo-16
            // prose misspells this identifier as `biaya_pengirpikan`; the DDL at
            // :945 and the live schema both say `biaya_pengiriman`, and that is what
            // is declared here.
            $table->decimal('biaya_pengiriman', 14, 2)->default(0);

            // DECIMAL(14,2) NOT NULL (:946) - **NO DEFAULT**, the one deliberate
            // asymmetry in this money block. It is required at insert and nothing
            // computes it. Do NOT add ->default(0): an unpriced invoice would then be
            // silently acceptable, and this is `column_default` drift against the DDL.
            $table->decimal('total', 14, 2);

            // ## WRAPPED ENUM - the value list spans `:947` AND `:948`. Seven values,
            // NOT five, and NOT NULL DEFAULT 'menunggu_pembayaran'.
            //
            // Reading :947 alone truncates the list after `dibatalkan` and hides both
            // the `)` and the `NOT NULL DEFAULT 'menunggu_pembayaran'` clause, so a
            // reader of that one line would conclude the column is nullable and
            // carries no default value. This is one of exactly five such ENUMs in the
            // contract. Order is the sort index and is reproduced exactly.
            //
            // The DEFAULT is `menunggu_pembayaran`, NOT `draft`: an invoice row is
            // created already awaiting payment. `draft` is reachable only by an
            // explicit write. `dibatalkan` (position 5) sorts BEFORE both refund
            // states, so position is not a lifecycle order.
            $table->enum('status', ['draft', 'menunggu_pembayaran', 'lunas', 'kadaluarsa', 'dibatalkan', 'refund_sebagian', 'refund_penuh'])->default('menunggu_pembayaran');

            // DATETIME NULL (:949) - the payment deadline, caller-supplied, no
            // default, not a timestamp column and not derived from anything. Nothing
            // in the DDL expires an invoice; `kadaluarsa` is a status the application
            // must set from a scheduled job. `dateTime()`, never `timestamp()`.
            $table->dateTime('jatuh_tempo')->nullable();

            // DATETIME NULL (:950) - set when the invoice reaches `lunas`. No
            // default, nullable, and NOT maintained by any trigger. `dateTime()`,
            // never `timestamp()`.
            $table->dateTime('lunas_at')->nullable();

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:951) and (:952) with
            // ON UPDATE. Declared by hand, NOT via $table->timestamps(), which would
            // emit created_at/updated_at. Both carry ->useCurrent(), and the raw
            // ALTER below supplies ON UPDATE because Laravel 13 has no Blueprint
            // helper for it. invoice is one of the 16 contract tables that have both.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // ## TRAP 1b - `INDEX idx_ref` (:955) is the only index that makes the
            // polymorphic lookup affordable, and its COLUMN ORDER is part of the
            // contract: `referensi_tipe` FIRST, `referensi_id` SECOND. Reproduce
            // both the name and the order. See the class docblock for why a
            // reversal degrades to a scan and a deletion is invisible to every other
            // check in this project.
            $table->index(['referensi_tipe', 'referensi_id'], 'idx_ref');

            // The invoice-list index (:954) - `(pasien_id, status)`, a genuine
            // two-column key, not the single-column FK-support index InnoDB would
            // create for the foreign key below. Reproduce verbatim.
            $table->index(['pasien_id', 'status'], 'idx_invoice');

            // The ONLY foreign key in this CREATE TABLE (:953), and it carries **NO
            // ON DELETE clause**, so it materialises MySQL's implicit NO ACTION: a
            // patient still named by an invoice cannot be deleted. Writing
            // ->cascadeOnDelete() here would be foreign_key_action drift.
            $table->foreign('pasien_id')->references('id')->on('pasien');
        });

        // ON UPDATE CURRENT_TIMESTAMP for diubah_at (:952). Laravel 13 has no
        // Blueprint helper, and without this the differ reports column_on_update
        // drift with expected CURRENT_TIMESTAMP.
        DB::statement('ALTER TABLE invoice MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice');
    }
};
