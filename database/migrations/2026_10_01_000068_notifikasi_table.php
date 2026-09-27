<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 68 of 75 — `telemedicine_test.sql:1036-1048`. **First table of batch K.**
 *
 * **9 columns** (`:1037`-`:1045`), **2 indexes** (the primary key and
 * `idx_notif (user_id, dibaca_at)`) and **exactly ONE foreign key**
 * (`:1046`, `user_id` -> `users(id) ON DELETE CASCADE`).
 *
 * Module: M5 notification. `docs/migration-order.md` row 68 records
 * `Resource: 47`, `Controller: 47`; Model: todo 19. This todo authors the table
 * and nothing else — no Model, no Resource, no Controller, no seeder, no route.
 *
 * ## `dibaca_at` is `DATETIME`, not `TIMESTAMP` — `dateTime()`, never `timestamp()`
 *
 * `:1044` is `dibaca_at DATETIME NULL`, the moment a notification was read. It is
 * `$table->dateTime('dibaca_at')->nullable()` and **must not** be `timestamp()`.
 * The distinction is not cosmetic: the two types are not interchangeable in MySQL
 * (`timestamp` is stored as UTC seconds since the epoch with a 2038 upper bound,
 * `datetime` as a wall-clock value with a 9999 range), so a `timestamp` here would
 * be `column_type` drift *and* would silently reinterpret the value.
 *
 * A `NULL` `dibaca_at` is the **unread** state and is the only representation of
 * it — there is no `terbaca` boolean and no `status` column.
 * The unread count is therefore literally `WHERE user_id = ? AND dibaca_at IS NULL`,
 * which is what makes `idx_notif` load-bearing (see below). Reading time is a
 * single nullable `DATETIME` because a notification is read at most once: there is
 * no re-read state, and no column records how many times it was opened.
 *
 * ## `payload` is `JSON NULL` — `$table->json()`, never `text()`
 *
 * `:1043` is `payload JSON NULL`. `$table->json()` emits the `json` type;
 * `text()` emits `longtext` and is `column_type` drift. The same builder is used
 * for `audit_log.data_lama` / `data_baru` (`:1124`-`:1125`) later in this batch.
 *
 * It is **nullable and has no `DEFAULT`**, so a notification may be raised with no
 * structured body at all — the `isi` column (`:1040`) is the human-readable text
 * and is `NOT NULL`, so `payload` is strictly the optional machine-readable
 * companion. Nothing validates its shape: there is no `CHECK` and no generated
 * column, so the keys inside are an application contract, not a database one.
 *
 * ## `tautan` is a deep-link target and is a bare `VARCHAR(500) NULL`
 *
 * `:1042`. No `URL` type is used anywhere in this schema and none is available in
 * MySQL, so a deep link into the SPA or the app is stored as a plain string and
 * validated by the client. It is nullable because a system message (`tipe` =
 * `'sistem'`, the seventh ENUM member) has nowhere to send the user.
 *
 * ## `tipe` is a SEVEN-value ENUM, single-line, with NO default
 *
 * ```sql
 * tipe ENUM('booking','pembayaran','resep','chat','lab','promo','sistem') NOT NULL
 * ```
 *
 * `ENUM(` opens **and** closes on `:1041`; `:1042` is a different column
 * (`tautan`). This declaration is therefore **single-line** and is **not** one of
 * the five contract ENUMs whose value list continues onto the next physical line.
 * All seven values are reproduced in order — `booking`, `pembayaran`, `resep`,
 * `chat`, `lab`, `promo`, `sistem` — and there is **no `DEFAULT`**, so omitting
 * the column is MySQL 1364 rather than a silent classification. That is correct:
 * a notification with no category could not be routed to the right inbox tab.
 *
 * Note that the list mixes two kinds of thing. `booking`, `pembayaran`, `resep`,
 * `chat` and `lab` name **transaction classes**; `promo` is **marketing**; and
 * `sistem` is a **catch-all**. `promo` being a member of the same ENUM as a
 * clinical result is why an "unread count" grouped by category is a matter of
 * product policy and not of the schema: nothing in the DDL distinguishes a
 * transactional notification from an advertisement, and todo 47 owns that decision.
 *
 * ## `INDEX idx_notif (user_id, dibaca_at)` — and its COLUMN ORDER is the contract
 *
 * `:1047`. This is the one index besides the primary key, and its **leftmost
 * column is `user_id`, not `dibaca_at`**, which is what makes it serve the unread
 * query above (`WHERE user_id = ? AND dibaca_at IS NULL`) as a selective seek
 * rather than a scan of one user's notifications followed by a filter. A reversed
 * `(dibaca_at, user_id)` would be a different index and would be reported as
 * `missing_index` plus `extra_index` drift, because rule 10 of
 * `docs/migration-order.md` compares a name the DDL wrote **by name and column
 * list**.
 *
 * ## `dibuat_at` only — this table has NO `diubah_at`
 *
 * `:1045` is `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and there is no
 * sibling. `$table->timestamps()` must **not** be called: it would emit
 * `created_at`/`updated_at` (wrong names) *and* invent a `diubah_at` column the
 * DDL does not have. `notifikasi` is therefore one of the **19** contract tables
 * in the "`dibuat_at` only" group, and **todo 19's model needs
 * `const CREATED_AT = 'dibuat_at'` and `public $timestamps = false`.**
 *
 * The absence is coherent rather than an oversight: a notification is an
 * immutable event ("a booking was confirmed"), and editing one is not a thing the
 * product does. There is no `ON UPDATE CURRENT_TIMESTAMP`, so **no raw `ALTER` is
 * needed in this migration** and none is issued.
 *
 * ## `ON DELETE CASCADE` on `user_id` — the one destructive constraint in the batch
 *
 * `:1046`. This is the **only** `ON DELETE` clause in all eight `CREATE TABLE`
 * statements of batch K besides `persetujuan_pdp.user_id` and
 * `akses_rekam_medis_log.rekam_medis_id`, and it is worth stating why it is
 * correct here: a notification is a **personal, non-clinical artefact addressed
 * to one user**. Deleting the user must delete it, because a notification
 * addressed to an account that no longer exists is unreachable, undeletable
 * through the API and a standing privacy liability. Contrast the two append-only
 * logs in this same batch, `audit_log` and `akses_rekam_medis_log`, which are
 * evidence and therefore must outlive their subject — `audit_log` has no foreign
 * key at all and `akses_rekam_medis_log.rekam_medis_id` cascades for a different
 * reason entirely. **Do not "harmonise" the three.**
 *
 * Exposed: `docs/migration-order.md` row 68 — Module M5 notification,
 * Resource 47, Controller 47. Model: todo 19.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifikasi', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1037). The SQL declares
            // the primary key INLINE on the column, so ->primary() is what
            // reproduces it; $table->id() is forbidden by rule 1 for a different
            // reason but happens to emit the same width here.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // BIGINT UNSIGNED NOT NULL (:1038) - the recipient. This is the table's
            // ONLY foreign key (:1046) and the only CASCADE in the table.
            $table->unsignedBigInteger('user_id');

            // VARCHAR(200) NOT NULL (:1039) - the notification's headline. 200,
            // half of `isi` below, and the widest title-like column in the batch.
            $table->string('judul', 200);

            // VARCHAR(500) NOT NULL (:1040) - the body. NOT NULL with no DEFAULT,
            // so a notification always carries human-readable text; the optional
            // machine-readable half is `payload` below.
            $table->string('isi', 500);

            // ENUM(...7 values...) NOT NULL (:1041) - **single-line, NOT wrapped.**
            // `ENUM(` opens AND closes on :1041; :1042 is a different column
            // (`tautan`). This is NOT one of the five contract ENUMs that span two
            // physical lines - an earlier report named :1041-:1042 as wrapped and
            // it is not, which is why the wrapped count is five.
            //
            // Seven values in the SQL's exact order: booking, pembayaran, resep,
            // chat, lab, promo, sistem. **No DEFAULT**, so omitting the column is
            // MySQL 1364 rather than a silent classification - correct, because an
            // uncategorised notification could not be routed to an inbox tab.
            $table->enum('tipe', ['booking', 'pembayaran', 'resep', 'chat', 'lab', 'promo', 'sistem']);

            // VARCHAR(500) NULL (:1042) - a deep-link target. There is no URL type
            // in MySQL and none is used anywhere in this schema, so it is a bare
            // string validated by the client. Nullable because a `sistem` message
            // has nowhere to send the user. Same width as `isi` (:1040), which is
            // a coincidence and not a relationship.
            $table->string('tautan', 500)->nullable();

            // JSON NULL (:1043) - **$table->json(), NEVER text()**, which emits
            // longtext and is column_type drift. Nullable with no DEFAULT: a
            // notification may be raised with no structured body, because `isi`
            // above is the required human-readable text and this is only the
            // optional machine-readable companion. Nothing validates its shape -
            // no CHECK, no generated column - so the keys are an application
            // contract.
            $table->json('payload')->nullable();

            // DATETIME NULL (:1044) - **dateTime(), NOT timestamp()**: `timestamp`
            // is UTC-seconds-since-epoch with a 2038 bound, `datetime` is a
            // wall-clock value to 9999, so the wrong builder is both column_type
            // drift and a silent reinterpretation of the value.
            //
            // A NULL here IS the unread state - there is no `terbaca` boolean and
            // no `status` column - so the unread count is literally
            // `WHERE user_id = ? AND dibaca_at IS NULL`, which is exactly what
            // makes idx_notif below load-bearing. A notification is read at most
            // once, so there is no re-read state and no read counter.
            $table->dateTime('dibaca_at')->nullable();

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1045) - the ONLY
            // timestamp column. **There is no `diubah_at`** and no
            // `ON UPDATE`, so $table->timestamps() must not be called (it would
            // emit created_at/updated_at AND invent a sibling column) and NO raw
            // ALTER is required below. `notifikasi` is one of the 19 contract
            // tables in the "dibuat_at only" group; todo 19's model needs
            // const CREATED_AT = 'dibuat_at' and public $timestamps = false.
            $table->timestamp('dibuat_at')->useCurrent();

            // The one non-primary index (:1047). **COLUMN ORDER IS THE CONTRACT**:
            // `user_id` is leftmost, which is what turns the unread query
            // (`WHERE user_id = ? AND dibaca_at IS NULL`) into a selective seek
            // instead of a scan-then-filter. A reversed (dibaca_at, user_id) is a
            // different index and would be reported as missing_index plus
            // extra_index drift, because rule 10 compares a DDL-written name by
            // name AND column list.
            $table->index(['user_id', 'dibaca_at'], 'idx_notif');

            // FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE (:1046)
            // - the table's only foreign key. CASCADE is correct here and is
            // deliberately NOT the pattern of the two append-only logs in this
            // same batch: a notification is a personal, non-clinical artefact
            // addressed to one user, so deleting the user must delete it. Do not
            // harmonise this with audit_log (no FK at all) or
            // akses_rekam_medis_log (cascades on a *record*, not a person).
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // No raw ALTER is needed: this table has no `ON UPDATE CURRENT_TIMESTAMP`
        // anywhere, so unlike the 16 contract tables that carry a
        // dibuat_at/diubah_at pair, there is nothing Blueprint cannot express.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifikasi');
    }
};
