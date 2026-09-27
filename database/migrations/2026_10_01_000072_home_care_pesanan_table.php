<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 72 of 75 — `telemedicine_test.sql:1093-1112`. **Batch K.**
 *
 * **14 columns** (`:1094`-`:1108`), **2 indexes** (the primary key and the inline
 * `UNIQUE` on `nomor_pesanan`), **3 foreign keys** (`:1109`-`:1111`) and **no
 * named `INDEX` clause at all**.
 *
 * Module: **ORPHAN**. `docs/migration-order.md` row 72 records `Resource: —`,
 * `Controller: —`, so this todo authors the table and nothing else: no Model, no
 * Resource, no Controller, no seeder, no route. Model: todo 19.
 *
 * ## A WRAPPED ENUM YOU MUST NOT MIS-READ — and this one is **NOT** wrapped
 *
 * ```sql
 * status ENUM('menunggu_pembayaran','terjadwal','perjalanan','berlangsung','selesai','dibatalkan')
 *        NOT NULL DEFAULT 'menunggu_pembayaran',
 * ```
 *
 * `ENUM(` opens on `:1104` and **closes on `:1104`**. `:1105` carries only
 * `NOT NULL DEFAULT 'menunggu_pembayaran',`. So the **value list is complete on
 * `:1104`** and this declaration is **single-line** in the sense that matters for
 * parity.
 *
 * **A prior report in this project claimed `:1104`-`:1105` was wrapped, and it is
 * not.** I re-derived the wrapped-ENUM census from scratch rather than accepting
 * either that claim or the plan's: the predicate is "does any line open an `ENUM(`
 * that its own line does not close?", and walking the whole file with it yields
 * exactly **five** declarations — `booking.status` (`:515-516`),
 * `master_obat.bentuk_sediaan` (`:713-714`), `resep.status` (`:751-752`),
 * `invoice.status` (`:947-948`) and `persetujuan_pdp.jenis` (`:1137-1138`).
 * `home_care_pesanan.status` is not among them, and neither are
 * `konsultasi.status` (`:542`), `konsultasi_chat.tipe_pesan` (`:568`),
 * `lab_permintaan.status` (`:884`), `pesanan_obat.status` (`:810`) or
 * `klaim_bpjs.status` (`:1022`) — each of those closes its own `ENUM(...)` and
 * only its trailing `NOT NULL DEFAULT` continues.
 *
 * **This is a different question from the `wrapped decls 11` figure the schema
 * verifier prints on every run.** That number counts declarations whose *end line*
 * exceeds their *start line*, which is true for eleven columns, and it reads like
 * it answers the ENUM question. It does not. Both numbers are correct answers to
 * their own question; only the five answer the parity question. (Plan appendix
 * A.20/A.22.)
 *
 * **All six values** are reproduced in order: `menunggu_pembayaran`, `terjadwal`,
 * `perjalanan`, `berlangsung`, `selesai`, `dibatalkan`. The default is
 * `menunggu_pembayaran` — the **first** member and the real start of the
 * workflow, so an order row is created already awaiting payment and `terjadwal` is
 * reachable only by an explicit write.
 *
 * Note the value names are **semantic stages, not a monotone clock**: `perjalanan`
 * ("in transit") is a home-care logistics state, so a nurse is on the way, and
 * `berlangsung` ("ongoing") is care in progress. ENUM position is the sort index,
 * so `ORDER BY status` yields the states in that order, which happens to be the
 * lifecycle order here — but that is a property of this list, not a guarantee, and
 * it is why the order is reproduced rather than sorted.
 *
 * ## `tipe_layanan` is a FIVE-value ENUM with NO default
 *
 * `:1098` — `tipe_layanan ENUM('perawat','fisioterapi','dokter','bidan',
 * 'vaksinasi_rumah') NOT NULL`, **single-line and with no `DEFAULT`**, so omitting
 * it is MySQL 1364 rather than a silent classification. All five values in order.
 * Note that `'dokter'` is a member: the plan's `dokter` table is the *staff*
 * record, and this column names a *service type*, not a doctor — the person is
 * `tenaga_medis_id` below, which points at `dokter(id)` (`:1111`). The two are
 * different things that share a word.
 *
 * ## `nomor_pesanan VARCHAR(30) NOT NULL UNIQUE` — the human order reference
 *
 * `:1095`. Inline `UNIQUE`, so rule 10 compares it by **semantics**, not by name.
 * `VARCHAR(30)`, not `CHAR(30)`: like `klaim_bpjs.nomor_sep` (`:1016`) this is an
 * alphanumeric variable-length reference, so a fixed-width type would be wrong in
 * the other direction. It is the table's only uniqueness and it is what makes an
 * order traceable to a human — `invoice.referensi_tipe` carries a `'home_care'`
 * member (`:940`) and an order can therefore be invoiced.
 *
 * ## THREE NULLABLE "who" COLUMNS, ALL CONSTRAINED, AND THEY ARE NOT INTERCHANGEABLE
 *
 * `pasien_id BIGINT UNSIGNED NOT NULL` (`:1096`, FK `:1109`),
 * `anggota_keluarga_id BIGINT UNSIGNED NULL` (`:1097`, FK `:1110`) and
 * `tenaga_medis_id BIGINT UNSIGNED NULL` (`:1099`, FK `:1111`) — all three point
 * at a real parent and all three are constrained. `pasien_anggota_keluarga` is
 * table 21 and `dokter` is table 31, both many batches earlier, so **nothing in
 * this table is deferred.**
 *
 * The substantive reading: `pasien_id` is the **account holder who pays** and is
 * `NOT NULL`; `anggota_keluarga_id` is **whose** body is being visited, nullable
 * because the patient may be visiting for themselves; and `tenaga_medis_id` is the
 * assigned clinician, nullable because an order exists before staffing is
 * confirmed — which is exactly the `menunggu_pembayaran` state above. **All three
 * nullable combinations are representable and nothing ties them together**: a
 * family-member visit with no assigned clinician, a self-visit with a family
 * member also named, and an order for a patient with no clinician are all legal
 * rows, and only the application can keep them coherent.
 *
 * ## `alamat_kunjungan TEXT NOT NULL` is the visit address, NOT a delivery address
 *
 * `:1100`. `NOT NULL` with no default, so an order cannot be placed without a
 * physical address — correct, because a home-care visit has nowhere else to happen.
 * It is `text()` (64 KiB), not `longText()`: the DDL says `TEXT`. Do not confuse
 * it with `artikel.konten` (`:1082`), which **is** `LONGTEXT` in this same batch.
 *
 * `jadwal_kunjungan DATETIME NOT NULL` (`:1101`) is `dateTime()`, **not**
 * `timestamp()`. `keluhan TEXT NULL` (`:1103`) is the patient's complaint and is
 * optional.
 *
 * ## `durasi_jam TINYINT UNSIGNED NOT NULL DEFAULT 1` and `biaya DECIMAL(12,2) NOT NULL DEFAULT 0`
 *
 * `:1102` and `:1106`.
 *
 * - `durasi_jam` is **`unsignedTinyInteger()`** — `TINYINT UNSIGNED` is 8-bit, range
 *   0-255, so the largest single booking is 255 hours. MySQL 8 emits no display
 *   width, so the live reading is `tinyint unsigned` and **never**
 *   `tinyint(3) unsigned`. The default is **1 hour**.
 * - `biaya` is `DECIMAL(12,2)`, so `decimal('biaya', 12, 2)` — the same scale as
 *   `master_promo.nilai` (`:990`), and **NOT** the `(14,2)` of `invoice`'s money
 *   columns or `pembayaran.jumlah`. The default is `0`, and because the column is
 *   `NOT NULL` an unpriced order is representable and indistinguishable from a
 *   genuinely free one — the same unenforced-default pattern batch J recorded.
 *
 * **Nothing computes `biaya` from `durasi_jam` and `tipe_layanan`.** There is no
 * `CHECK`, no generated column and no trigger, so the price is a free figure the
 * caller supplies. A one-hour `vaksinasi_rumah` and a one-hour `dokter` visit may
 * carry the same number.
 *
 * ## `diubah_at` — one of the **16** contract tables that need the raw `ALTER`
 *
 * `:1107` and `:1108` are `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`, the
 * second with `ON UPDATE CURRENT_TIMESTAMP`. Both are declared by hand with
 * `->useCurrent()`; the raw `DB::statement` below supplies the `ON UPDATE`, which
 * **Laravel 13's Blueprint cannot express**. `$table->timestamps()` would emit
 * `created_at`/`updated_at` and is exactly wrong. `home_care_pesanan` is one of the
 * sixteen tables in `docs/migration-order.md` rule 4's "both" row.
 *
 * Exposed: `docs/migration-order.md` row 72 — Module ORPHAN, Resource —,
 * Controller —. Model: todo 19.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('home_care_pesanan', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1094).
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // VARCHAR(30) NOT NULL UNIQUE (:1095) - the human order reference and
            // the table's ONLY uniqueness. **VARCHAR, not CHAR**: like
            // klaim_bpjs.nomor_sep (:1016) it is alphanumeric and variable-length,
            // so a fixed width would be wrong in the other direction. Inline
            // UNIQUE, so rule 10 compares it by SEMANTICS, not by name.
            $table->string('nomor_pesanan', 30)->unique();

            // BIGINT UNSIGNED NOT NULL (:1096) - the account holder who PAYS. FK at
            // :1109 with no ON DELETE, so implicit NO ACTION (RESTRICT).
            $table->unsignedBigInteger('pasien_id');

            // BIGINT UNSIGNED NULL (:1097) - whose body is being visited. FK at
            // :1110, and `pasien_anggota_keluarga` is table 21, so nothing here is
            // deferred. Nullable because the patient may be visiting for
            // themselves. NOT interchangeable with pasien_id above: one is who
            // pays, the other who is treated, and nothing in the DDL requires them
            // to be consistent.
            $table->unsignedBigInteger('anggota_keluarga_id')->nullable();

            // ## ENUM(...5 values...) NOT NULL (:1098) - **single-line, and NO
            // DEFAULT**, so omitting it is MySQL 1364 rather than a silent
            // classification. Five values in the SQL's exact order: perawat,
            // fisioterapi, dokter, bidan, vaksinasi_rumah.
            //
            // Note `'dokter'` names a SERVICE TYPE, not a doctor - the person is
            // tenaga_medis_id below, which points at `dokter(id)` at :1111. The
            // shared word is not a shared concept.
            $table->enum('tipe_layanan', ['perawat', 'fisioterapi', 'dokter', 'bidan', 'vaksinasi_rumah']);

            // BIGINT UNSIGNED NULL (:1099) - the assigned clinician, pointing at
            // `dokter(id)` (FK :1111). Nullable because an order exists before
            // staffing is confirmed, which is exactly the `menunggu_pembayaran`
            // state below. `dokter` is table 31, many batches earlier: **nothing in
            // this table is deferred.**
            $table->unsignedBigInteger('tenaga_medis_id')->nullable();

            // TEXT NOT NULL (:1100) - the visit address, `text()` (64 KiB), NOT
            // longText(): the DDL says TEXT. Do not confuse it with
            // `artikel.konten` (:1082), which IS LONGTEXT in this same batch.
            // NOT NULL with no default, which is correct: a home-care visit has
            // nowhere else to happen.
            $table->text('alamat_kunjungan');

            // DATETIME NOT NULL (:1101) - the scheduled visit time. **dateTime(),
            // NOT timestamp()**: a wall-clock appointment value, and `timestamp`
            // would be both column_type drift and a UTC reinterpretation.
            $table->dateTime('jadwal_kunjungan');

            // ## TINYINT UNSIGNED NOT NULL DEFAULT 1 (:1102) -
            // **unsignedTinyInteger()**, 8-bit, range 0-255, so the largest single
            // booking is 255 hours. MySQL 8 emits no display width, so the live
            // reading is `tinyint unsigned` and NEVER `tinyint(3) unsigned`.
            // The default is 1 hour.
            $table->unsignedTinyInteger('durasi_jam')->default(1);

            // TEXT NULL (:1103) - the patient's complaint, optional.
            $table->text('keluhan')->nullable();

            // ## ENUM(...6 values...) NOT NULL DEFAULT 'menunggu_pembayaran'
            // (:1104) - **THE VALUE LIST IS COMPLETE ON :1104 AND THE DECLARATION
            // IS NOT WRAPPED.** `ENUM(` opens AND closes on :1104; :1105 carries
            // only `NOT NULL DEFAULT 'menunggu_pembayaran',`.
            //
            // A prior report claimed :1104-:1105 was a wrapped ENUM. It is not,
            // and the wrapped count was re-derived from scratch rather than
            // accepted from either that report or the plan: the predicate is "does
            // any line open an `ENUM(` that its own line does not close?", and it
            // yields exactly FIVE declarations in the whole file - booking.status
            // (:515-516), master_obat.bentuk_sediaan (:713-714), resep.status
            // (:751-752), invoice.status (:947-948), persetujuan_pdp.jenis
            // (:1137-1138). This is NOT one of them, and neither are
            // konsultasi.status (:542), konsultasi_chat.tipe_pesan (:568),
            // lab_permintaan.status (:884), pesanan_obat.status (:810) or
            // klaim_bpjs.status (:1022) - each closes its own ENUM(...) and only
            // its trailing NOT NULL DEFAULT continues.
            //
            // That is a DIFFERENT question from the `wrapped decls 11` the schema
            // verifier prints on every run, which counts declarations whose end
            // line exceeds their start line. Both numbers are right; only the five
            // answer the parity question. (Plan appendix A.20/A.22.)
            //
            // All SIX values in the SQL's exact order: menunggu_pembayaran,
            // terjadwal, perjalanan, berlangsung, selesai, dibatalkan. The default
            // is the FIRST member and the real start of the workflow, so an order
            // is created already awaiting payment. `perjalanan` is a logistics
            // state (a nurse is on the way) and `berlangsung` is care in progress,
            // so position happens to be lifecycle order here - a property of this
            // list, which is why it is reproduced rather than sorted.
            $table->enum('status', ['menunggu_pembayaran', 'terjadwal', 'perjalanan', 'berlangsung', 'selesai', 'dibatalkan'])->default('menunggu_pembayaran');

            // ## DECIMAL(12,2) NOT NULL DEFAULT 0 (:1106) - the same scale as
            // master_promo.nilai (:990), and NOT the (14,2) of invoice's money
            // columns or pembayaran.jumlah. The default 0 is a real zero, and
            // because the column is NOT NULL an unpriced order is
            // indistinguishable from a genuinely free one.
            //
            // Nothing computes this from durasi_jam and tipe_layanan: no CHECK, no
            // generated column, no trigger. The price is a free figure the caller
            // supplies, so a one-hour vaksinasi_rumah and a one-hour dokter visit
            // may carry the same number.
            $table->decimal('biaya', 12, 2)->default(0);

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1107) and (:1108) the
            // second with ON UPDATE. Declared by hand, NOT via
            // $table->timestamps(), which would emit created_at/updated_at. Both
            // carry ->useCurrent(); the raw ALTER below supplies the ON UPDATE
            // because Laravel 13 has no Blueprint helper for it.
            // `home_care_pesanan` is one of the 16 contract tables in
            // docs/migration-order.md rule 4's "both" row.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // FOREIGN KEY (pasien_id) REFERENCES pasien(id) (:1109)
            // FOREIGN KEY (anggota_keluarga_id) REFERENCES pasien_anggota_keluarga(id) (:1110)
            // FOREIGN KEY (tenaga_medis_id) REFERENCES dokter(id) (:1111)
            //
            // All three targets - patients (20, batch C), patient family members
            // (21, batch C) and doctor (31, batch D) - exist many batches before
            // this migration, so **this batch's share of these constraints defers
            // nothing**. None writes an ON DELETE clause, so all three materialise
            // MySQL's implicit NO ACTION (RESTRICT for DML). The polarity is
            // deliberate and differs from `notifikasi.user_id` (:1046), which
            // CASCADEs in this same batch: an order, a family member and a
            // clinician are all records the platform must not lose. Do not
            // harmonise.
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('anggota_keluarga_id')->references('id')->on('pasien_anggota_keluarga');
            $table->foreign('tenaga_medis_id')->references('id')->on('dokter');
        });

        // ON UPDATE CURRENT_TIMESTAMP for diubah_at (:1108). Without this the
        // differ reports column_on_update drift with expected CURRENT_TIMESTAMP.
        DB::statement('ALTER TABLE home_care_pesanan MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('home_care_pesanan');
    }
};
