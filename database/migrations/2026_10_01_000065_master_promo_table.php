<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 65 of 75 — `telemedicine_test.sql:985-998`. The promotion catalogue.
 *
 * **12 columns** (`:986`-`:997`), **TWO indexes** (the primary key and the inline
 * `UNIQUE` on `kode`) and **NO foreign keys** — like `master_metode_pembayaran`
 * (61) it is a pure lookup, and the only table that points at it is
 * `promo_redemption.promo_id` (66), one migration later in this same batch.
 *
 * ## `kuota_total` is `INT UNSIGNED`, and `kuota_per_user` is `TINYINT UNSIGNED`
 *
 * These are the only two non-`BIGINT` non-key columns in batch J, and both widths
 * are load-bearing:
 *
 *  - `kuota_total INT UNSIGNED NULL` (`:993`) — `unsignedInteger()`, **nullable**,
 *    and `INT`, not `SMALLINT`. Its largest value is 4 294 967 295 and it has no
 *    `DEFAULT`, so an explicit NULL is how "unlimited" is expressed. **`$table->id()`
 *    is not involved here, but note the helper-shape trap: the bare `integer()`
 *    helper emits a **non-unsigned** `int`, and `unsignedInteger()` is the one the
 *    DDL's `INT UNSIGNED` requires.** Writing `->integer()` here
 *    would emit `int` against the DDL's `int unsigned` and the verifier reports it.
 *  - `kuota_per_user TINYINT UNSIGNED NOT NULL DEFAULT 1` (`:994`) —
 *    `unsignedTinyInteger()`, which tops out at **255**. A per-user cap above 255 is
 *    a MySQL 1264 out-of-range error, not a silent clamp. **The default is 1**, i.e.
 *    a promo is single-use per patient unless someone raises it — the opposite of
 *    the `0` default on the sibling quota-shaped columns elsewhere in this project
 *    (`biaya_admin_flat`/`persen` at `:931`-`:932`), and stated here so the two are
 *    not conflated.
 *
 * **Neither quota is enforceable.** `kuota_total` has no `CHECK`, no trigger and no
 * counter column; `kuota_per_user` is not referenced by any constraint, and
 * `promo_redemption` (66) has **no unique key at all** — so the same patient can
 * redeem the same promo any number of times. Every quota in this table is a
 * convention the service must honour by counting rows, and the counting is a race
 * under concurrency. `kuota_per_user` being `NOT NULL DEFAULT 1` records a
 * *default policy*, not a guarantee.
 *
 * ## `tipe_diskon` has **NO DEFAULT**, so it is required at insert
 *
 * `ENUM('persen','nominal','gratis_ongkir') NOT NULL` (`:989`). Three values, one
 * line, not wrapped. Unlike every other ENUM in batch J this one has **no `DEFAULT`**
 * clause, so omitting it is a MySQL 1364 error rather than a silent default. That is
 * correct — the meaning of `nilai` (`:990`) depends entirely on the type, and a
 * defaulted type would make `nilai` ambiguous.
 *
 * **The three types read `nilai` in three different units, and nothing records
 * which.** `persen` means `nilai` is a percentage (0-100), `nominal` means it is a
 * rupiah amount, and `gratis_ongkir` means the value is **ignored entirely** because
 * the benefit is free shipping — which `invoice.biaya_pengiriman` (`:945`) is the
 * column that would otherwise have to be zeroed. Nothing in the DDL distinguishes a
 * `persen` of 10 from a `nominal` of 10, and a `gratis_ongkir` row may carry any
 * `nilai` at all, since the column is `NOT NULL` with no `CHECK`. Todo 45's discount
 * engine must branch on `tipe_diskon` and must not infer the unit from the value.
 *
 * `maks_diskon DECIMAL(12,2) NULL` (`:992`) is a **cap** and is nullable, so "no
 * cap" is representable. It is meaningful only for `persen` (and is how a
 * percentage promo avoids being unbounded) and is meaningless for `gratis_ongkir`;
 * nothing enforces that. Its scale is `(12,2)` — **not** `(14,2)` like the
 * `invoice` money columns, and not `(5,2)` like `biaya_admin_persen`.
 *
 * ## `nilai` and `min_transaksi` share the `(12,2)` scale; neither has a value check
 *
 * `nilai DECIMAL(12,2) NOT NULL` (`:990`) and `min_transaksi DECIMAL(12,2) NOT NULL
 * DEFAULT 0` (`:991`). The minimum spend defaults to **0**, i.e. **no minimum at
 * all**, which means a promo row created without setting it applies to every
 * transaction — including a zero-value one. There is no `CHECK (nilai > 0)`, no
 * `CHECK (min_transaksi >= 0)` and no `CHECK` that `maks_diskon <= nilai`, so a promo
 * that discounts more than the cart is free to be written.
 *
 * ## `mulai_at` / `selesai_at` are `DATETIME NOT NULL` and are NOT timestamps
 *
 * `:995` and `:996`. Both are required, neither has a `DEFAULT`, and **neither is a
 * `dibuat_at`** — they are the promotion's validity window, a business fact
 * independent of when the row was written. `dateTime()`, never `timestamp()`:
 * MySQL's `TIMESTAMP` is stored as UTC and converted on read, and emitting one here
 * would be `column_type` drift. This matters more than usual because a promo window
 * is compared against transaction time in two timezones otherwise.
 *
 * **There is no `CHECK (selesai_at > mulai_at)`, no exclusion constraint, and no
 * unique key of any kind beyond `kode`** (`:987`). A promo whose window ends before
 * it starts is representable, and two promos may have identical windows. There is
 * also **no index on `mulai_at` or `selesai_at`**, so "which promos are live right
 * now" is a scan of the whole catalogue, and no index cannot be added — the DDL is
 * read-only law (`docs/migration-order.md` rule 7). An expiry job that flips
 * `status_aktif` to 0 is the intended mechanism, and it is an application
 * responsibility, because the ENUM `status` that would express it does not exist on
 * this table: `status_aktif TINYINT(1) NOT NULL DEFAULT 1` (`:997`) is a
 * **boolean, not a status enum** — the only column of its name in batch J, and the
 * only boolean in this table.
 *
 * ## `kode VARCHAR(30) NOT NULL UNIQUE` is the table's ONLY uniqueness
 *
 * `:987`. Inline `UNIQUE`, so MySQL names the index `kode` and the verifier compares
 * it by semantics rather than by Laravel's generated name (rule 10). The seed codes
 * in section `[16]` for the other master tables are upper-case with underscores
 * (`VA_BCA`, `GOPAY`), so whatever todo 18 writes should match that convention —
 * but the DDL has no `CHECK` on the character set, so nothing enforces it.
 *
 * ## `master_promo` has **NO SEED ROWS AT ALL**
 *
 * The plan's verified seed list (its "Verified seed row counts" table) names
 * `master_metode_pembayaran` 14, `master_icd10` 15, `master_obat` 7 and eight other
 * master tables, and **does not name `master_promo`**. Confirmed by reading section
 * `[16]`: there is **no** `INSERT INTO master_promo` anywhere in
 * `telemedicine_test.sql`. So a todo-18 seeder has no reference rows to replay and
 * whatever promos exist are unsourced fixture data, in the same position as
 * `obat_interaksi` and `lab_paket_item`.
 *
 * **There is no `dibuat_at` and no `diubah_at`**, so this is one of the 39 tables in
 * rule 4's "neither" group and **todo 19's `MasterPromo` must set `public
 * $timestamps = false`**. `mulai_at` is *not* a created-at substitute: a promo can
 * be created long before its window opens, and the row's insertion time is simply
 * not recorded.
 *
 * **This batch owes no deferred constraint.** This table declares no foreign key
 * and is named by exactly one, `promo_redemption.promo_id` (`:1007`, table 66,
 * later in this same batch). `fk_vital_rm` remains the only row in the *Deferred
 * constraints* registry in `docs/schema-notes.md`.
 *
 * Exposed: `docs/migration-order.md` row 65 — Module M5 payment, Resource 45,
 * Controller 45.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_promo', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:986).
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // VARCHAR(30) NOT NULL UNIQUE (:987) - the ONLY uniqueness on this table.
            // Inline UNIQUE, so MySQL names the index `kode` and it is compared by
            // semantics, not by Laravel's generated name (rule 10). The DDL imposes no
            // character-set CHECK on it.
            $table->string('kode', 30)->unique();

            // VARCHAR(150) NOT NULL (:988) - the display label. A rename is
            // unrecorded: this table has no dibuat_at/diubah_at.
            $table->string('nama', 150);

            // ENUM(...3 values...) NOT NULL (:989) - **NO DEFAULT**, unlike every
            // other ENUM in batch J, so omitting it is MySQL 1364 rather than a
            // silent default. Three values on one line, so NOT wrapped.
            //
            // The three types read `nilai` in three different UNITS and nothing
            // records which: `persen` = a percentage, `nominal` = rupiah,
            // `gratis_ongkir` = `nilai` is IGNORED and the benefit is zeroing
            // invoice.biaya_pengiriman (:945). Nothing distinguishes a persen of 10
            // from a nominal of 10. Branch on this column; do not infer from nilai.
            $table->enum('tipe_diskon', ['persen', 'nominal', 'gratis_ongkir']);

            // DECIMAL(12,2) NOT NULL (:990) - the discount, in the unit named by
            // `tipe_diskon` above. NO DEFAULT, so required at insert. No
            // CHECK (nilai > 0): a zero or negative discount is representable.
            $table->decimal('nilai', 12, 2);

            // DECIMAL(12,2) NOT NULL DEFAULT 0 (:991) - the minimum spend, and the
            // default 0 means **NO MINIMUM AT ALL**, so a promo row created without
            // it applies to every transaction including a zero-value one. No
            // CHECK (min_transaksi >= 0).
            $table->decimal('min_transaksi', 12, 2)->default(0);

            // DECIMAL(12,2) NULL (:992) - the CAP on the discount, and nullable, so
            // "no cap" is representable. Meaningful for `persen`; meaningless for
            // `gratis_ongkir`; nothing enforces that. Note the scale is (12,2), not
            // invoice's (14,2) nor biaya_admin_persen's (5,2). No CHECK that
            // maks_diskon <= nilai.
            $table->decimal('maks_diskon', 12, 2)->nullable();

            // INT UNSIGNED NULL (:993) - the total redemption quota, and NULL means
            // UNLIMITED. `unsignedInteger()` is required here; the bare `integer()`
            // helper emits a non-unsigned `int` and the verifier reports the
            // difference as `column_unsigned` drift. No DEFAULT, so an explicit NULL
            // is the only way to say "unlimited" and an omitted column is an error.
            //
            // Not enforceable: no CHECK, no counter column, no trigger. Every quota
            // in this table is a convention the service honours by counting
            // promo_redemption rows, and that count is a race under concurrency.
            $table->unsignedInteger('kuota_total')->nullable();

            // TINYINT UNSIGNED NOT NULL DEFAULT 1 (:994) - the per-patient cap, and
            // `unsignedTinyInteger()` tops out at 255, so a cap above that is MySQL
            // 1264 rather than a silent clamp. The DEFAULT is 1: single-use per
            // patient unless someone raises it. NOT NULL, so "unlimited per user" is
            // NOT representable here.
            //
            // Not enforceable either: promo_redemption (66) has no unique key at all,
            // so nothing stops the same patient redeeming the same promo repeatedly.
            $table->unsignedTinyInteger('kuota_per_user')->default(1);

            // DATETIME NOT NULL (:995) and (:996) - the promotion's validity window.
            // Both required, neither defaulted, and NEITHER is a created_at: a promo
            // may be created long before its window opens. `dateTime()`, never
            // `timestamp()` - MySQL TIMESTAMP is UTC-normalised on read and
            // emitting one here would be column_type drift, which matters more than
            // usual because a window is compared against transaction time.
            //
            // No CHECK (selesai_at > mulai_at), no index on either column, and no
            // unique key beyond `kode`: an inverted window is representable and
            // "which promos are live now" is a full scan that cannot be indexed
            // without violating docs/migration-order.md rule 7.
            $table->dateTime('mulai_at');
            $table->dateTime('selesai_at');

            // TINYINT(1) NOT NULL DEFAULT 1 (:997) - a BOOLEAN named `status_aktif`,
            // NOT a status enum. The only boolean in this table, and the default 1
            // means the promo is live. An application job flipping it to 0 at
            // `selesai_at` is the intended expiry mechanism, because the DDL has no
            // `status` column to record *why* a promo is off.
            $table->boolean('status_aktif')->default(true);

            // No `dibuat_at` / `diubah_at` - rule 4's 39-table "neither" group, so
            // todo 19's model needs $timestamps = false. `mulai_at` is a business
            // window, not a created-at, and does not stand in for one.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_promo');
    }
};
