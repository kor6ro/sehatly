<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F11 contract table 80 of 80 — `telemedicine_test.sql:1419-1429`.
 *
 * The dispatch ledger that makes `pengingat:kirim` idempotent. One row per
 * `(pengingat_id, tanggal, waktu)` that has been processed, written BEFORE the
 * notification it accompanies, so a crash between the two loses one dispatch
 * instead of double-sending one.
 *
 * **`uq_pengingat_terkirim` is the idempotency guarantee, not a convention.**
 * `tanggal` and `waktu` are `NOT NULL` deliberately: MySQL permits unlimited
 * `NULL`s inside a UNIQUE index, so a nullable `tanggal` would have let the
 * same key insert twice through a missing value.
 *
 * **`notifikasi_id` is `ON DELETE RESTRICT`** (approved): a delivered inbox row
 * is a fact about the past, so deleting a `notifikasi` row that the ledger
 * still names is blocked. An empty (`NULL`) link is normal for a dispatch that
 * wrote no push, and is still a delivered in-app notice.
 *
 * **`pengingat_id` cascades**: the ledger is meaningless without its reminder,
 * and the approved schema chooses CASCADE over RESTRICT here.
 *
 * `dibuat_at` only — the table is append-only, so `$table->timestamps()` must
 * NOT be called (it would emit `created_at`/`updated_at` and invent a
 * `diubah_at` the DDL does not have), and no raw `ON UPDATE` ALTER is needed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pengingat_terkirim', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1420).
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:1421) — the reminder this dispatch is
            // for. CASCADE: the ledger goes with its reminder.
            $table->unsignedBigInteger('pengingat_id');

            // DATE NOT NULL (:1422) — the LOCAL date the dose was due, in the
            // reminder's zone. NOT NULL because a NULL in a UNIQUE index does
            // not collide.
            $table->date('tanggal');

            // TIME NOT NULL (:1423) — the "HH:MM" entry that matched, stored
            // as the TIME column's own "HH:MM:SS".
            $table->time('waktu');

            // BIGINT UNSIGNED NULL (:1424) — the in-app row this dispatch
            // wrote. Nullable: a dispatch may legitimately have no push target
            // and still be recorded. RESTRICT on delete (:1428).
            $table->unsignedBigInteger('notifikasi_id')->nullable();

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1425) — the ONLY
            // timestamp, and there is no `diubah_at`, so no raw ALTER below and
            // the model needs UPDATED_AT = null.
            $table->timestamp('dibuat_at')->useCurrent();

            // UNIQUE KEY uq_pengingat_terkirim (pengingat_id, tanggal, waktu)
            // (:1426) — THE idempotency key, named in the DDL so compared by
            // name. `pengingat_id` is leftmost, so it is also the InnoDB
            // support index for the foreign key below.
            $table->unique(['pengingat_id', 'tanggal', 'waktu'], 'uq_pengingat_terkirim');

            // FOREIGN KEY (pengingat_id) REFERENCES pengingat(id) ON DELETE CASCADE (:1427).
            $table->foreign('pengingat_id')->references('id')->on('pengingat')->cascadeOnDelete();

            // FOREIGN KEY (notifikasi_id) REFERENCES notifikasi(id) ON DELETE RESTRICT (:1428).
            $table->foreign('notifikasi_id')->references('id')->on('notifikasi')->restrictOnDelete();
        });

        // No raw ALTER: this table carries no `ON UPDATE CURRENT_TIMESTAMP`.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengingat_terkirim');
    }
};
