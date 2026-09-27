<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 39 of 75 — `telemedicine_test.sql:563-579`.
 *
 * Second table of batch F. 12 columns, two foreign keys and one named index. The
 * body is `:564-578` (15 lines) and the two-line `tipe_pesan` ENUM at `:568-569` is
 * a single column, so 15 - 1 = 14 declarations covering 12 columns plus 2
 * `FOREIGN KEY` lines and 1 `INDEX` line.
 *
 * **(a) THIS TABLE HAS NEITHER `dibuat_at` NOR `diubah_at`, AND `$table->timestamps()`
 * IS WRONG HERE.** Its created-at column is `terkirim_at TIMESTAMP NOT NULL
 * DEFAULT CURRENT_TIMESTAMP` (`:575`), which is a *renamed* created-at column,
 * not a `timestamps()` pair. Declaring `$table->timestamps()` would emit
 * `created_at`/`updated_at` and be reported as two `extra_column` rows plus four
 * `missing_column` rows; declaring a nullable `timestamp('terkirim_at')` would
 * drop the `NOT NULL` and the `DEFAULT CURRENT_TIMESTAMP`. So `terkirim_at` is
 * declared exactly as written, with `->useCurrent()`. This table is one of the 39
 * in the "neither" group that `docs/migration-order.md` rule 4 enumerates, and it
 * is listed there **by column name**: its created-at is `terkirim_at` (`:575`), so
 * todo 19's `KonsultasiChat` model needs `const CREATED_AT = 'terkirim_at';` and
 * no `UPDATED_AT` counterpart. `$timestamps` stays `true` with that constant —
 * it is a *different pair of names*, not the `$timestamps = false` case.
 *
 * `dibaca_at DATETIME NULL` (`:574`) is the read receipt and is a plain nullable
 * `DATETIME`, deliberately distinct in type from `terkirim_at` even though both
 * record a moment: a `DATETIME` has no automatic default and no `ON UPDATE`, so
 * "read" is an explicit service-layer write and "not yet read" is `NULL`.
 *
 * **(b) `tipe_pesan` is an EIGHT-value ENUM in the DDL's exact order (`:568-569`)** —
 * ENUM order is the sort index and it is compared: `teks`, `gambar`, `dokumen`,
 * `audio`, `video_note`, `resep`, `surat_keterangan`, `sistem`. The first value,
 * `teks`, is also the `DEFAULT`, so a plain text message needs no explicit type.
 * The last four are not message formats at all: `resep` and `surat_keterangan`
 * are *cards* in the transcript that link to a prescription or a medical letter
 * (tables 49 and 40), and `sistem` is a service-authored notice. `video_note` is a
 * short asynchronous clip, which is a different thing from a live video
 * consultation and shares no column with `konsultasi.room_id`.
 *
 * `file_url VARCHAR(500) NULL`, `file_nama VARCHAR(255) NULL` and
 * `file_ukuran_kb INT UNSIGNED NULL` (`:571`-`:573`) exist only for the
 * attachment-bearing values. **`file_ukuran_kb` is `INT UNSIGNED`**, so a negative
 * size is impossible, and it is nullable, so a `teks` message simply has no
 * size — `0` and `NULL` are different statements and must not be collapsed. The
 * size is a *declared* size in kilobytes; nothing in the schema checks it against
 * the object at `file_url`, and there is no `CHECK`, so it is metadata the service
 * layer is trusted to fill honestly.
 *
 * **(c) `pengirim_user_id` is `NOT NULL` WITH A REAL FOREIGN KEY, YET
 * `pengirim_tipe` INCLUDES `'sistem'` (`:566`, `:567`, `:577`).** The three values
 * are `pasien`, `dokter`, `sistem`, and the column is
 * `BIGINT UNSIGNED NOT NULL` with `FOREIGN KEY (pengirim_user_id) REFERENCES
 * users(id)`. There is no nullable sender and no "no sender" representation, so
 * **a `sistem` message has no sender of its own**: it must be attributed to a
 * designated service user account that is created and seeded, and the `sistem`
 * value of `pengirim_tipe` records the *role played*, not a different author. A
 * reader that trusted `pengirim_tipe = 'sistem'` to mean "no author" and then tried
 * to load `pengirim_user_id` as a profile would get the service account. This is
 * a real schema limitation, not an oversight, and it is recorded in
 * `docs/schema-notes.md` so todo 32 does not rediscover it.
 *
 * The FK is **not** deferred: `users` is table 12 (batch B) and `konsultasi` is
 * table 38, created one migration earlier in this same batch.
 *
 * **(d) `konsultasi_chat`'s foreign key CASCADES and the other does not.** `:576` is
 * `FOREIGN KEY (konsultasi_id) REFERENCES konsultasi(id) ON DELETE CASCADE` while
 * `:577`, on `pengirim_user_id`, carries no `ON DELETE` clause and so
 * materialises MySQL's implicit `NO ACTION` (`RESTRICT` for DML). The asymmetry
 * is deliberate and is the right way round: chat history is worthless without its
 * consultation and is deleted with it, whereas deleting a user account must be
 * blocked while their messages exist, so accounts are soft-deleted via
 * `users.dihapus_at` (`:148`) and never hard-deleted. Do not "harmonise" the two
 * delete rules.
 *
 * `INDEX idx_chat (konsultasi_id, terkirim_at)` (`:578`) is the only name-bearing
 * key beyond the primary key, so it is compared **by name** on
 * `(TABLE_NAME, INDEX_NAME)`, and its column order is part of the contract:
 * `konsultasi_id` first, `terkirim_at` second. That is exactly the transcript
 * read — one consultation's messages, oldest first — which is the single hottest
 * query in the module, and it is also what makes the `ON DELETE CASCADE` on
 * `konsultasi_id` cheap. Do not reorder it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('konsultasi_chat', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('konsultasi_id');
            $table->unsignedBigInteger('pengirim_user_id');

            // Three values. 'sistem' is a ROLE, not an absent author: the NOT NULL
            // FK above means a system message is attributed to a service user.
            $table->enum('pengirim_tipe', ['pasien', 'dokter', 'sistem']);

            // EIGHT values, in the DDL's exact order (:568-569). The default is
            // 'teks', so plain text needs no explicit type.
            $table->enum('tipe_pesan', [
                'teks',
                'gambar',
                'dokumen',
                'audio',
                'video_note',
                'resep',
                'surat_keterangan',
                'sistem',
            ])->default('teks');

            $table->text('isi')->nullable();

            // Attachments. NULL for a 'teks' message, and NULL size is not 0 KB.
            $table->string('file_url', 500)->nullable();
            $table->string('file_nama', 255)->nullable();
            $table->unsignedInteger('file_ukuran_kb')->nullable();

            // Read receipt: a plain nullable DATETIME, distinct in type from the
            // created-at column below.
            $table->dateTime('dibaca_at')->nullable();

            // The created-at column, renamed. This table has NO dibuat_at and NO
            // diubah_at, so $table->timestamps() must NOT be used - see (a).
            $table->timestamp('terkirim_at')->useCurrent();

            // CASCADE on the consultation (DDL :576); implicit RESTRICT on the
            // sender, which carries no ON DELETE clause (:577).
            $table->foreign('konsultasi_id')->references('id')->on('konsultasi')->cascadeOnDelete();
            $table->foreign('pengirim_user_id')->references('id')->on('users');

            // Named in the DDL, so compared by name. Column order is the contract:
            // konsultasi_id first, terkirim_at second. See the class docblock.
            $table->index(['konsultasi_id', 'terkirim_at'], 'idx_chat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('konsultasi_chat');
    }
};
