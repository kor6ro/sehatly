<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 61 of 75 — `telemedicine_test.sql:925-934`. **First table of batch J.**
 *
 * **8 columns** (`:926`-`:933`), **TWO indexes** (the primary key and the inline
 * `UNIQUE` on `kode`) and **NO foreign keys** — one of the **two** FK-free tables in
 * this batch, the other being `master_promo` (65). This one is referenced from
 * inside the batch by `pembayaran.metode_id` (`:971`).
 *
 * ## `id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` (`:926`) — NOT `BIGINT`
 *
 * This is one of the **18** contract tables whose primary key is not `BIGINT
 * UNSIGNED`, and it is the **only** one of the 18 that is a *payment* table rather
 * than a geographic or organisational master (`docs/migration-order.md` rule 1;
 * the plan's "Primary-key widths" note lists `master_metode_pembayaran` among the
 * eight `SMALLINT UNSIGNED` ids). `$table->id()` emits `BIGINT UNSIGNED` and is
 * forbidden project-wide. Note the knock-on: `pembayaran.metode_id` (`:961`) is
 * `SMALLINT UNSIGNED` to match, so `foreignId()` there would be wrong twice over.
 *
 * `SMALLINT UNSIGNED` tops out at 65 535, which is ample for payment methods and
 * is not something to "fix".
 *
 * ## `tipe` is a **NINE**-value ENUM, and the DDL writes it on ONE line (`:929`)
 *
 * `ENUM('va_bank','e_wallet','qris','kartu_kredit','gerai_retail','cod','tunai',
 * 'bpjs','asuransi')`. This is **not** one of the five contract ENUMs whose value
 * list wraps onto the following physical line (see `invoice.status` at `:947-948`
 * for the one in this batch that does), so the whole list is legible at `:929` and
 * the count is **9**, not the 7 or 8 a glance might give. ENUM order is the sort
 * index and is compared as an ordered sequence, so it is reproduced exactly.
 *
 * **The seed exercises only six of the nine.** SQL `:1264`-`:1278` inserts **14**
 * rows and supplies only `(kode, nama, tipe, penyedia)`. Counting the distinct
 * `tipe` values actually present: `va_bank` 5, `e_wallet` 5, `qris` 1, `cod` 1,
 * `tunai` 1, `bpjs` 1 = **6 of 9**. **`kartu_kredit`, `gerai_retail` and
 * `asuransi` have no seed row at all**, so a todo-18 seeder that only replays the
 * INSERT will leave three legal payment methods unrepresented, and `master_promo`
 * is not seeded either (see that migration for the companion fact).
 *
 * **`penyedia` is NULL for no seeded row but nullable in the DDL.** Every one of the
 * 14 seed rows supplies a fourth value — `'Internal'` for `COD` and `TUNAI`,
 * `'BPJS Kesehatan'` for `BPJS` (`:1276`-`:1278`) — so the NULL branch is
 * representable and untested by the reference data. It is the natural value for a
 * method the platform settles internally with no acquirer behind it.
 *
 * ## Both admin-fee columns default to **0**, and the seed leaves them at 0
 *
 * `biaya_admin_flat DECIMAL(12,2) NOT NULL DEFAULT 0` (`:931`) and
 * `biaya_admin_persen DECIMAL(5,2) NOT NULL DEFAULT 0` (`:932`). The two scales are
 * **different** — `(12,2)` and `(5,2)` — and the narrow one is the real constraint:
 * `biaya_admin_persen` is a percentage, so its largest expressible value is
 * `999.99`; a fee of 100 % of a transaction is the boundary and anything above it
 * is a MySQL 1264 out-of-range error, not a silent clamp.
 *
 * **The default is 0, i.e. NO fee** — not "unset" and not "unknown". Because the
 * columns are `NOT NULL` there is no third state: a method that genuinely has an
 * unconfigured fee is indistinguishable from one that is free. A checkout that
 * sums `biaya_admin_flat + total * biaya_admin_persen / 100` over a method row
 * added without a fee will charge nothing and say nothing, so todo 45's invoice
 * builder must read the fee from the row and must not treat 0 as "fall back to a
 * default".
 *
 * `status_aktif TINYINT(1) NOT NULL DEFAULT 1` (`:933`) — the default is **1**, i.e.
 * ACTIVE. This is the opposite polarity to `lab_hasil.is_abnormal` in batch I, which
 * defaults to 0; both defaults are written out here so neither has to be re-derived.
 * A method is offered unless someone switches it off, which is what the 14 seed rows
 * all rely on (none of them supplies the column).
 *
 * **There is no `dibuat_at` and no `diubah_at`**, so this is one of the 39 tables in
 * rule 4's "neither" group and **todo 19's `MasterMetodePembayaran` must set
 * `public $timestamps = false`**. It is named in the 11 tables A.8 found missing
 * from the plan's own list of 28. `nama` is not a substitute for a created-at: it
 * is a display label, so a renamed method keeps no history of what it used to be
 * called.
 *
 * **Nothing in this table is deferred and nothing is deferred TO it.** It is the
 * target of `pembayaran.metode_id` (`:971`, migration 63, later in this same
 * batch) and it declares no foreign key of its own, so this migration neither
 * satisfies nor owes a deferral. `fk_vital_rm` remains the only row in the
 * *Deferred constraints* registry in `docs/schema-notes.md`.
 *
 * Exposed: `docs/migration-order.md` row 61 — Module M5 payment, Resource 42,
 * Controller 42. It is **not** module-orphaned; unlike batch I's six tables it has a
 * read endpoint, because a checkout must be able to list payable methods.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_metode_pembayaran', function (Blueprint $table) {
            // SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:926). One of the 18
            // contract tables whose id is not BIGINT UNSIGNED, so $table->id() is
            // forbidden (docs/migration-order.md rule 1). AUTO_INCREMENT *is* present
            // here, unlike the five TINYINT master tables of batch A, because the
            // seed does not supply explicit ids for this table.
            $table->unsignedSmallInteger('id')->autoIncrement()->primary();

            // VARCHAR(30) NOT NULL UNIQUE (:927) - inline UNIQUE, so MySQL names the
            // index `kode` and the verifier compares it by semantics, not by Laravel's
            // `master_metode_pembayaran_kode_unique` (rule 10).
            $table->string('kode', 30)->unique();

            // VARCHAR(100) NOT NULL (:928) - the display label. A rename is
            // unrecorded: the table has no dibuat_at/diubah_at.
            $table->string('nama', 100);

            // ENUM(...9 values...) NOT NULL (:929) - one line, NOT wrapped, and NOT
            // one of the five contract ENUMs that span two physical lines. The seed
            // at :1264-:1278 exercises only 6 of the 9: `kartu_kredit`,
            // `gerai_retail` and `asuransi` have no seed row at all. The full
            // derivation is in the class docblock.
            $table->enum('tipe', ['va_bank', 'e_wallet', 'qris', 'kartu_kredit', 'gerai_retail', 'cod', 'tunai', 'bpjs', 'asuransi']);

            // VARCHAR(50) NULL (:930) - the acquiring provider. All 14 seed rows
            // supply one ('Internal' for cod/tunai, 'BPJS Kesehatan' for bpjs at
            // :1276-:1278), so the NULL branch is representable but untested by the
            // reference data.
            $table->string('penyedia', 50)->nullable();

            // DECIMAL(12,2) NOT NULL DEFAULT 0 (:931) - a FLAT fee in rupiah. The
            // default 0 means NO fee, and because the column is NOT NULL there is no
            // third "unconfigured" state to distinguish it from.
            $table->decimal('biaya_admin_flat', 12, 2)->default(0);

            // DECIMAL(5,2) NOT NULL DEFAULT 0 (:932) - a PERCENTAGE, and note the
            // scale is (5,2) here against (12,2) above, so the largest expressible
            // percentage is 999.99. Same default: 0, i.e. no fee.
            $table->decimal('biaya_admin_persen', 5, 2)->default(0);

            // TINYINT(1) NOT NULL DEFAULT 1 (:933) - the default is 1, i.e. ACTIVE.
            // Opposite polarity to lab_hasil.is_abnormal (:912, default 0): a method
            // is offered unless someone switches it off, and all 14 seed rows rely on
            // that by not supplying the column.
            $table->boolean('status_aktif')->default(true);

            // No `dibuat_at` / `diubah_at` - rule 4's 39-table "neither" group, so
            // todo 19's model needs $timestamps = false. `nama` is a label, not a
            // created-at.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_metode_pembayaran');
    }
};
