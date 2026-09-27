<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 53 of 75 — `telemedicine_test.sql:819-827`.
 *
 * Seventh table of batch H. **6 columns** (`:820`-`:825`), one foreign key
 * with `ON DELETE CASCADE`, and **no timestamp column of any kind** — this
 * table is in rule 4's 39-table "neither `dibuat_at` nor `diubah_at`" group.
 * The nearest thing to a creation time is `waktu DATETIME NOT NULL` (`:825`),
 * a `DATETIME` the writer supplies; like `konsultasi_chat`'s `terkirim_at`
 * (`:575`), it is the de-facto created-at, so **todo 19's model needs
 * `const CREATED_AT = 'waktu'`** (or `$timestamps = false` plus an explicit
 * ordering on `waktu`) rather than a `created_at` that does not exist. Ordering
 * a parcel's history means sorting on `waktu`, and nothing in the schema
 * prevents two rows sharing the same instant.
 *
 * **`status` HERE IS A `VARCHAR(100)`, NOT AN ENUM — and that is the single
 * most important fact about this table.** `pesanan_obat.status` (`:810`-`:811`)
 * is a six-value ENUM, and the two columns share a name and nothing else:
 *
 * | Table | Column | Type | Values |
 * | --- | --- | --- | --- |
 * | `pesanan_obat` | `status` | `ENUM(6)` | `menunggu_pembayaran`, `diproses`, `siap`, `sedang_dikirim`, `selesai`, `dibatalkan` |
 * | `pesanan_obat_tracking` | `status` | `VARCHAR(100)` | **unconstrained free text** |
 *
 * So the order's state machine is closed and this table's is **open**: any
 * string is insertable here, including a typo, a courier's own vocabulary
 * (`in_transit`, `delivered`), or an order-state name that means something
 * different. A parcel row with `status = 'selesai'` and an order row with
 * `status = 'dibatalkan'` are both perfectly representable and nothing
 * reconciles them. **Todo 46 must validate this column in the application and
 * must never infer the order's state from it** — `pesanan_obat.status` is the
 * only authority. Do not "helpfully" convert this column to an enum: the
 * verifier would report `column_type` drift and the DDL is read-only law.
 *
 * `keterangan VARCHAR(255) NULL` (`:823`) and `lokasi VARCHAR(255) NULL` (`:824`)
 * are both free text and both nullable, and neither is normalised: a location
 * is a string a human or a courier SDK wrote, so it is not comparable, not
 * geocodable and not aggregatable. There is no latitude/longitude here, unlike
 * `faskes.latitude`/`faskes.longitude` (`:372`-`:373`), so no "where is my
 * parcel" map is derivable from this table.
 *
 * The single foreign key `pesanan_obat_id` carries `ON DELETE CASCADE`
 * (`:826`), so deleting an order takes its tracking history with it. That is
 * the third cascade in this batch (`obat_interaksi`'s two are `:737`-`:738`,
 * `resep_item.resep_id` is `:781`) and the opposite of the `RESTRICT` that
 * `resep_verifikasi`'s two keys impose (`:793`-`:794`): a parcel's delivery
 * trail is worthless without the order, while a pharmacist's verification is
 * legal evidence and must block the deletion that would erase it.
 * `pesanan_obat` is table 52, one row earlier in this same batch, so
 * **nothing here is deferred**.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pesanan_obat_tracking', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('pesanan_obat_id');

            // A VARCHAR(100), NOT AN ENUM. The column shares a NAME with
            // pesanan_obat.status (:810-:811), which IS a six-value ENUM, and
            // the two are otherwise unrelated: the order's state machine is
            // closed, this one is OPEN, and any string is insertable here -
            // a typo, a courier's own vocabulary, or an order-state name with a
            // different meaning. An order row reading `dibatalkan` next to a
            // tracking row reading `selesai` is representable and nothing
            // reconciles the two. Todo 46 must validate this in the
            // application and must read the order's state from
            // `pesanan_obat.status`, never from here. Converting this to an
            // enum would be `column_type` drift against a read-only contract.
            $table->string('status', 100);

            // Free text, nullable, and NOT normalised: a location is a string a
            // human or a courier SDK wrote, so it is not comparable, not
            // geocodable and not aggregatable. There are no lat/long columns
            // here, unlike faskes (:372-:373), so no map is derivable.
            $table->string('keterangan', 255)->nullable();
            $table->string('lokasi', 255)->nullable();

            // DATETIME NOT NULL, whole seconds, NOT a TIMESTAMP and not a DATE,
            // with no default and no trigger. It is the de-facto created-at for
            // this table - which has neither dibuat_at nor diubah_at - so todo
            // 19's model needs `const CREATED_AT = 'waktu'`, and ordering a
            // parcel's history means sorting on it. Nothing prevents two rows
            // sharing the same instant.
            $table->dateTime('waktu');

            // CASCADE (:826): a parcel's delivery trail is worthless without the
            // order, so deleting the order takes it with them. Deliberately the
            // opposite of the RESTRICT on resep_verifikasi's two keys
            // (:793-:794), where the verification is legal evidence and must
            // block the deletion that would erase it. pesanan_obat is table 52,
            // one row earlier in this batch, so nothing here is deferred.
            $table->foreign('pesanan_obat_id')->references('id')->on('pesanan_obat')->cascadeOnDelete();

            // NO $table->timestamps() - this table has neither dibuat_at nor
            // diubah_at (rule 4's 39-table "neither" group). `waktu` is not a
            // `timestamps()` pair and must not be replaced by one. See the
            // class docblock.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pesanan_obat_tracking');
    }
};
