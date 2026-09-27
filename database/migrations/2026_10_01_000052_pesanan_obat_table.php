<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 52 of 75 — `telemedicine_test.sql:797-817`.
 *
 * Sixth table of batch H, and the first of the two M5-order tables.
 * **15 columns** (`:798`-`:813`), **three** ENUMs, an inline `UNIQUE`, three
 * foreign keys and one named index. `dibuat_at` and `diubah_at` are both
 * present (`:812`-`:813`) and `diubah_at` carries `ON UPDATE CURRENT_TIMESTAMP`,
 * so this is one of only 16 tables with a `dibuat_at`/`diubah_at` pair and
 * needs the raw `ALTER` from `docs/migration-order.md` rule 5. All three
 * targets — `resep` 49, `pasien` 20, `faskes` 28 — pre-date this batch, so
 * **nothing here is deferred**.
 *
 * **THE `status` ENUM IS A MULTI-LINE DECLARATION (`:810`-`:811`) AND BOTH
 * AUTHORITATIVE LISTS IN THIS REPOSITORY OMIT IT.** The six values fit on
 * `:810`; the `NOT NULL DEFAULT 'menunggu_pembayaran'` tail is on `:811`:
 *
 * ```
 *   status ENUM('menunggu_pembayaran','diproses','siap','sedang_dikirim','selesai','dibatalkan')
 *          NOT NULL DEFAULT 'menunggu_pembayaran',
 * ```
 *
 * The plan's "Multi-line ENUMs — treat as single units, not single lines" list
 * names six (`booking.status`, `konsultasi.status`, `konsultasi_chat.tipe_pesan`,
 * `master_obat.bentuk_sediaan`, `resep.status`, `persetujuan_pdp.jenis`) and
 * this is a **seventh**; `docs/migration-order.md` rule 6 carries the same six
 * and also omits it. Reading only `:810` yields the six values with an **empty
 * tail**, i.e. a nullable column with no default — `column_nullable` plus
 * `column_default` drift, and a NOT NULL column silently made nullable. Read
 * `:810` **and** `:811`. (Reported as a plan and contract-document defect; both
 * files are orchestrator- or contract-owned and were not edited.)
 *
 * `tipe ENUM('resep_dokter','obat_bebas','produk_kesehatan') NOT NULL DEFAULT
 * 'resep_dokter'` (`:803`) has the **first** member as its default, and
 * `kurir ENUM('internal','grab_express','gojek','jne','jnt','sicepat') NULL`
 * (`:805`) is the one ENUM in this batch that is **nullable with no default** —
 * so an order with no courier chosen is representable and the six courier names
 * are an enumeration of *carriers*, not a reference to any table.
 *
 * **The order table has NO line items, and that is a structural limitation, not
 * an omission.** `resep_id` is nullable (`:800`) and there is **no
 * `pesanan_obat_item` table anywhere in the 75**, so a `tipe = 'obat_bebas'`
 * or `'produk_kesehatan'` order has **nowhere to record which products were
 * bought**, and `subtotal` cannot be recomputed from lines. Todo 46 must
 * therefore restrict the order flow to `tipe = 'resep_dokter'` — where the
 * prescription's own `resep_item` rows (table 50) supply the products — and
 * record the limitation. Note the contrast that makes the trap sharp:
 * `resep_id` here **does** carry a real foreign key (`:814`), unlike
 * `resep.konsultasi_id` and `resep.rekam_medis_id` which are bare by contract.
 * Do not generalise from one to the other.
 *
 * `apotek_id BIGINT UNSIGNED NOT NULL` (`:802`) points at **`faskes(id)`**, and
 * nothing constrains `faskes.tipe` to `'apotek'` (`:365`), so a hospital is
 * representable as the fulfilling pharmacy. The application must validate it.
 * Unlike `resep.apotek_id` (`:749`) this one is **`NOT NULL`**: a prescription
 * may be written before a pharmacy is chosen, an order may not.
 *
 * The three money columns `subtotal` (`:807`), `biaya_kirim` (`:808`) and
 * `total` (`:809`) are all `DECIMAL(12,2) NOT NULL DEFAULT 0` and **nothing
 * checks `total = subtotal + biaya_kirim`** — no `CHECK`, no generated column,
 * no trigger. All three default to 0, which means "not yet calculated", and a
 * zero `total` on a placed order is representable and indistinguishable from a
 * genuinely free order.
 *
 * There is **no** `dihapus_at` here — only `users` (`:148`) and `pasien`
 * (`:249`) get soft deletes — so a cancelled order is a `dibatalkan` status,
 * not a deletion, and the three `RESTRICT` foreign keys mean an order cannot be
 * removed while it references a prescription.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pesanan_obat', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // Inline UNIQUE in the DDL (:799) with no key name, so MySQL names
            // the index `nomor_pesanan` and Laravel
            // `pesanan_obat_nomor_pesanan_unique`. Rule 10 compares an inline
            // UNIQUE by semantics, so ->unique() is correct.
            $table->string('nomor_pesanan', 30)->unique();

            // NULLABLE, and it DOES carry a real foreign key (:814) to
            // `resep` - contrast with `resep.konsultasi_id` and
            // `resep.rekam_medis_id`, which are bare by contract. Nullable
            // because there is no `pesanan_obat_item` table in the 75, so a
            // `tipe = 'obat_bebas'` order has nowhere to record its products and
            // `subtotal` cannot be recomputed from lines. Todo 46 must restrict
            // the flow to `tipe = 'resep_dokter'`. See the class docblock.
            $table->unsignedBigInteger('resep_id')->nullable();
            $table->unsignedBigInteger('pasien_id');

            // FK to `faskes(id)`, NOT to a pharmacy table, and nothing
            // constrains faskes.tipe to 'apotek' (:365) - a hospital is
            // representable here. NOT NULL, unlike resep.apotek_id (:749):
            // a prescription may be written before a pharmacy is chosen, an
            // order may not.
            $table->unsignedBigInteger('apotek_id');

            // THREE values, in the DDL's exact order (:803). The DEFAULT is the
            // FIRST member, `resep_dokter`.
            $table->enum('tipe', ['resep_dokter', 'obat_bebas', 'produk_kesehatan'])->default('resep_dokter');

            // The shipping address is stored ONCE, on the order, as free text.
            // There is no address table and no FK to a patient address, so it
            // is a snapshot in the same spirit as `resep_item.nama_obat` and is
            // NOT re-read from the patient record at dispatch.
            $table->text('alamat_kirim');

            // SIX values, in the DDL's exact order (:805). NULLABLE with no
            // default - the only nullable ENUM in this batch - so an order with
            // no courier chosen is representable. These are carrier NAMES, not
            // references: there is no courier table in the 75.
            $table->enum('kurir', ['internal', 'grab_express', 'gojek', 'jne', 'jnt', 'sicepat'])->nullable();

            // Free text, NOT UNIQUE, NOT indexed, and nullable while `kurir` is
            // nullable too - so a tracking number with no courier and a courier
            // with no tracking number are both representable.
            $table->string('no_resi', 50)->nullable();

            // All three money columns are DECIMAL(12,2) NOT NULL DEFAULT 0 and
            // NOTHING checks total = subtotal + biaya_kirim - no CHECK, no
            // generated column, no trigger. 0 means "not yet calculated" and a
            // zero total on a placed order is indistinguishable from a free one.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('biaya_kirim', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // SIX values, in the DDL's exact order, and the declaration WRAPS
            // ACROSS TWO LINES: the value list ends on :810 and the
            // `NOT NULL DEFAULT 'menunggu_pembayaran'` tail is on :811. Reading
            // only :810 yields an empty tail - a nullable column with no
            // default, i.e. column_nullable plus column_default drift and a
            // NOT NULL column silently made nullable. This is a SEVENTH
            // multi-line ENUM and the plan's own list of six omits it, as does
            // `docs/migration-order.md` rule 6. See the class docblock.
            $table->enum('status', [
                'menunggu_pembayaran',
                'diproses',
                'siap',
                'sedang_dikirim',
                'selesai',
                'dibatalkan',
            ])->default('menunggu_pembayaran');

            // Both timestamp columns exist (:812-:813), so this table IS one of
            // the 16 that needs rule 5's raw ON UPDATE ALTER below. The explicit
            // dibuat_at/diubah_at pair every batch B-G migration uses - NOT
            // $table->timestamps(), which would emit created_at/updated_at.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // Three foreign keys, none of which carries an ON DELETE clause, so
            // each materialises MySQL's implicit NO ACTION (RESTRICT for DML).
            // All three targets pre-date this batch; nothing is deferred.
            $table->foreign('resep_id')->references('id')->on('resep');
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('apotek_id')->references('id')->on('faskes');

            // NO named index on this table beyond the primary key and the
            // inline UNIQUE. The three foreign-key columns are therefore
            // covered only by indexes MySQL creates for the constraints
            // themselves, which `SchemaDiffer::diffIndexes()` treats as implied
            // rather than as `extra_index` drift (commit 27c6ca8). Do NOT add a
            // covering index "to fix" a slow query: it would be drift against a
            // read-only contract.
        });

        DB::statement('ALTER TABLE pesanan_obat MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pesanan_obat');
    }
};
