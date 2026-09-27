<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 71 of 75 — `telemedicine_test.sql:1074-1091`. **Batch K.**
 *
 * **14 columns** (`:1075`-`:1088`), **2 indexes** (the primary key and the inline
 * `UNIQUE` on `slug`), **2 foreign keys** (`:1089`-`:1090`) and **no named
 * `INDEX` clause at all**.
 *
 * Module: **ORPHAN**. `docs/migration-order.md` row 71 records `Resource: —`,
 * `Controller: —`, so this todo authors the table and nothing else: no Model, no
 * Resource, no Controller, no seeder, no route. Model: todo 19.
 *
 * ## A BARE COLUMN: `reviewer_user_id` — NO FOREIGN KEY, and it IS in the plan's list
 *
 * `:1078` is `reviewer_user_id BIGINT UNSIGNED NULL COMMENT 'Reviewer medis
 * (revisi medis)'`. **`reviewer_user_id` is named in the plan's authoritative
 * bare-column list at line 181 and line 181's prose at `:1078` — but the plan's
 * own todo-17 prose never mentions it**, and two of the three bare columns in this
 * batch are not flagged in the dispatched brief as bare either. That combination
 * is exactly how an invented constraint gets written, and this project has already
 * produced that defect three times (`resep.konsultasi_id` in batch H,
 * `pasien_penjamin.faskes_rujukan_id` before that, `lab_hasil.diperiksa_oleh` in
 * batch I).
 *
 * **Do NOT write a foreign key on it.** `users` is table 12 and exists long before
 * this migration, so `->foreign()` would **succeed** at migration time and become
 * permanent `extra_foreign_key` drift while `migrate:fresh`, `php -l` and the whole
 * 93-test unit suite stayed green.
 *
 * **The DDL's own `COMMENT` states the reason**: *"Reviewer medis (revisi medis)"* —
 * a medical reviewer performing a medical revision. This is a **workflow role,
 * not an identity**: the person who medically signs off an article is not
 * necessarily an author or a member of staff, and the column is **nullable**
 * because the common case is an article that has never been medically reviewed —
 * the `status` value `'draft'` (`:1084`) is the unreviewed state and needs no
 * reviewer. A constraint would additionally assert that a reviewer must exist in
 * `users`, which the DDL does not say and which would make a review performed by
 * an external clinician unrepresentable — the same argument
 * `lab_hasil.diperiksa_oleh` (`:914`) makes for a pathologist at a partner `faskes`.
 *
 * Proven FK-free against `information_schema.REFERENTIAL_CONSTRAINTS` joined to
 * `KEY_COLUMN_USAGE`, **not** by reading `SHOW CREATE TABLE` (which only shows
 * constraints that exist, so it cannot distinguish "absent" from "not looked
 * for"), with the column separately confirmed to **exist** as `bigint unsigned`
 * nullable so "no row" cannot be confused with "no column".
 *
 * **Contrast `penulis_user_id` (`:1077`), which IS constrained** (`:1090`). One
 * `_user_id` column being constrained says nothing about the other: the author is
 * a platform account by definition, because they authored inside the platform,
 * while the medical reviewer is an external act. **Do not harmonise the two.**
 *
 * ## `konten` is `LONGTEXT NOT NULL` — `longText()`, never `text()`
 *
 * `:1082`. `$table->longText('konten')` emits `longtext` (4 GiB); `text()` emits
 * `text` (64 KiB) and is `column_type` drift. A long-form health article with
 * inline markup is exactly the case the wider type exists for, and nothing in the
 * schema compresses or chunks the body. This is the only `LONGTEXT` in batch K.
 *
 * ## `status` is a FOUR-value ENUM, single-line, defaulting to `'draft'`
 *
 * ```sql
 * status ENUM('draft','review','terbit','arsip') NOT NULL DEFAULT 'draft'
 * ```
 *
 * `ENUM(` opens **and** closes on `:1084`; `:1085` is a different column
 * (`jumlah_view`). So this declaration is **single-line** and is **not** one of
 * the five contract ENUMs whose value list continues onto the next physical line.
 * All four values are reproduced in order — `draft`, `review`, `terbit`, `arsip`.
 *
 * The default is `'draft'`, the **first** member, so an inserted article is
 * unpublished and unreviewed. Note that `arsip` (archived) sits **after** `terbit`
 * (published) and is reachable from it — an `UPDATE`, because there is no revision
 * history table. And `review` is the state that pairs with the nullable
 * `reviewer_user_id` above, so **`status = 'review'` with a NULL reviewer is
 * representable** and nothing in the DDL ties the two together.
 *
 * **`jumlah_view INT UNSIGNED NOT NULL DEFAULT 0` (`:1085`) is `unsignedInteger()`,
 * not `integer()`.** The DDL says `UNSIGNED`. A negative view count is not
 * representable, which is correct for a counter. MySQL 8 emits no display width,
 * so the live reading is `int unsigned` and **not** `int(10) unsigned`.
 *
 * ## `published_at DATETIME NULL` is NOT a substitute for `dibuat_at`
 *
 * `:1086`. It is `dateTime()->nullable()` — **not** `timestamp()` — and it is the
 * moment the article went live, which is normally **later** than `dibuat_at`
 * (`:1087`). A `NULL` `published_at` is therefore the normal state of a `draft`,
 * and it is independent of `status`: an article can be `terbit` with a NULL
 * `published_at`, or carry a `published_at` while still `draft`. Nothing in the
 * schema keeps them consistent, so the application must.
 *
 * ## `diubah_at` — one of the **16** contract tables that need the raw `ALTER`
 *
 * `:1087` and `:1088` are `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`, the
 * second with `ON UPDATE CURRENT_TIMESTAMP`. Both are declared by hand with
 * `->useCurrent()`; the raw `DB::statement` below supplies the `ON UPDATE`, which
 * **Laravel 13's Blueprint cannot express** and which the differ reports as
 * `column_on_update` drift without it. `$table->timestamps()` would emit
 * `created_at`/`updated_at` and is exactly wrong.
 *
 * `artikel` is one of the sixteen tables listed in `docs/migration-order.md`
 * rule 4's "both" row, which is the exhaustive list of the tables that need this
 * `ALTER`. Measured, not copied: this file issues it and the verifier confirms the
 * column's `EXTRA` is `on update CURRENT_TIMESTAMP`.
 *
 * **`diubah_at` is this table's ONLY record that a medical revision happened**,
 * because `reviewer_user_id` stores *who* and nothing stores *what changed* — no
 * revision table, no diff, no `riwayat_revisi`. A reviewer overwriting `konten`
 * in place is a complete loss of the previous text apart from its timestamp. That
 * is a product consequence of the DDL, not a defect in this migration, and it is
 * worth stating once so a later reader does not assume a content table has history.
 *
 * ## `cover_url` is a bare `VARCHAR(500) NULL` and `ringkasan` a `VARCHAR(500) NULL`
 *
 * `:1081` and `:1083`. Both are unconstrained: no `URL` type exists in MySQL, no
 * foreign key to a media table (there is no media table in the 75), and no
 * checksum. `cover_url` is the same bare-URL class as `lab_hasil.file_pdf_url`
 * (`:916`) and `klaim_bpjs.berkas_url` (`:1026`). The two columns share a width by
 * coincidence, not by relationship.
 *
 * Exposed: `docs/migration-order.md` row 71 — Module ORPHAN, Resource —,
 * Controller —. Model: todo 19.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('artikel', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT (:1075) - declared INLINE
            // on the column in the DDL.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // ## SMALLINT UNSIGNED NOT NULL (:1076), matching
            // `artikel_kategori.id` (:1069) - the parent is 16-bit, so
            // foreignId() would be wrong twice over (rule 1). FK at :1089.
            $table->unsignedSmallInteger('kategori_id');

            // BIGINT UNSIGNED NOT NULL (:1077) - the author, and **constrained**
            // (FOREIGN KEY ... REFERENCES users(id) at :1090). A platform account
            // by definition, because the article was authored inside the platform.
            $table->unsignedBigInteger('penulis_user_id');

            // ## BARE BY CONTRACT - `reviewer_user_id BIGINT UNSIGNED NULL`
            // (:1078), DDL COMMENT 'Reviewer medis (revisi medis)'.
            //
            // **NO FOREIGN KEY.** `users` is table 12 and exists long before this
            // migration, so ->foreign() would SUCCEED and become permanent
            // extra_foreign_key drift while migrate:fresh, php -l and the whole
            // 93-test unit suite stayed green.
            //
            // The DDL's own COMMENT gives the reason: this is a WORKFLOW ROLE, not
            // an identity. The clinician who medically signs off or revises an
            // article need not be an author or staff member, and the column is
            // NULLABLE because the common case is an article that has never been
            // medically reviewed - `status = 'draft'` is the unreviewed state and
            // needs no reviewer. A constraint would also make a review performed by
            // an external clinician unrepresentable, the same argument
            // lab_hasil.diperiksa_oleh (:914) makes for a partner-faskes
            // pathologist.
            //
            // Contrast penulis_user_id (:1077) directly above, which IS
            // constrained at :1090. One `_user_id` column being constrained says
            // nothing about the other. Do not harmonise them.
            //
            // Proven FK-free against information_schema.REFERENTIAL_CONSTRAINTS
            // joined to KEY_COLUMN_USAGE, NOT by reading SHOW CREATE TABLE, and
            // this column separately confirmed to EXIST as `bigint unsigned`
            // nullable so that "no row" cannot be confused with "no column".
            $table->unsignedBigInteger('reviewer_user_id')->nullable();

            // VARCHAR(255) NOT NULL (:1079) - the article headline. 255, and NOT
            // the same as `notifikasi.judul` VARCHAR(200) (:1039) or
            // `home_care_pesanan.nomor_pesanan` VARCHAR(30) (:1095) - three
            // different widths for three different things.
            $table->string('judul', 255);

            // VARCHAR(255) NOT NULL UNIQUE (:1080) - the public URL key, and the
            // table's ONLY uniqueness. Inline UNIQUE, so rule 10 compares it by
            // SEMANTICS (MySQL `slug`, Laravel `artikel_slug_unique` - one
            // constraint, two names). Note `artikel_kategori.slug` is
            // VARCHAR(100) (:1071) and this one is VARCHAR(255): they are
            // unrelated columns that happen to share a name.
            $table->string('slug', 255)->unique();

            // VARCHAR(500) NULL (:1081) - a short abstract. Nullable, so an
            // article with no summary is representable. Same width as cover_url
            // below and as notifikasi.isi (:1040) by coincidence only.
            $table->string('ringkasan', 500)->nullable();

            // ## LONGTEXT NOT NULL (:1082) - **longText(), NEVER text()**.
            // longtext is 4 GiB, text is 64 KiB, so text() is column_type drift.
            // A long-form health article with inline markup is exactly what the
            // wider type is for, and nothing in the schema chunks or compresses the
            // body. The only LONGTEXT in batch K.
            $table->longText('konten');

            // VARCHAR(500) NULL (:1083) - a bare image URL. No URL type exists in
            // MySQL, there is no media table among the 75 to reference, and there
            // is no checksum. Same class as lab_hasil.file_pdf_url (:916) and
            // klaim_bpjs.berkas_url (:1026).
            $table->string('cover_url', 500)->nullable();

            // ## ENUM(...4 values...) NOT NULL DEFAULT 'draft' (:1084) -
            // **single-line, NOT wrapped.** `ENUM(` opens AND closes on :1084;
            // :1085 is a different column (jumlah_view). This is NOT one of the
            // five contract ENUMs that span two physical lines.
            //
            // Four values in the SQL's exact order: draft, review, terbit, arsip.
            // The default is `draft`, the FIRST member, so an inserted article is
            // unpublished and unreviewed. `arsip` sits AFTER `terbit` and is
            // reachable from it by UPDATE - there is no revision-history table.
            // `status = 'review'` with a NULL reviewer_user_id is representable
            // and nothing in the DDL ties the two together.
            $table->enum('status', ['draft', 'review', 'terbit', 'arsip'])->default('draft');

            // ## INT UNSIGNED NOT NULL DEFAULT 0 (:1085) - **unsignedInteger()**,
            // NOT integer(). The DDL says UNSIGNED and a negative view count is
            // not representable, which is correct for a counter. MySQL 8 emits no
            // display width, so the live reading is `int unsigned` and NOT
            // `int(10) unsigned`.
            $table->unsignedInteger('jumlah_view')->default(0);

            // DATETIME NULL (:1086) - **dateTime(), NOT timestamp()**: the moment
            // the article went live, a wall-clock value, normally LATER than
            // dibuat_at (:1087). It is NOT a created-at substitute and it is not
            // consistent with `status` by anything in the DDL: `terbit` with a NULL
            // published_at, and a published_at on a `draft`, are both representable.
            $table->dateTime('published_at')->nullable();

            // TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (:1087) and (:1088) the
            // second with ON UPDATE. Declared by hand, NOT via
            // $table->timestamps(), which would emit created_at/updated_at. Both
            // carry ->useCurrent(); the raw ALTER below supplies the ON UPDATE
            // because Laravel 13 has no Blueprint helper for it. `artikel` is one of
            // the 16 contract tables listed in docs/migration-order.md rule 4 that
            // have BOTH dibuat_at and diubah_at, and that list is the exhaustive
            // set needing this ALTER.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // FOREIGN KEY (kategori_id) REFERENCES artikel_kategori(id) (:1089) -
            // the only constraint on this table that also makes another table's
            // bare-column reasoning necessary: artikel_kategori (70, the previous
            // migration) is the parent, created one migration earlier in this same
            // batch, so nothing here is deferred.
            //
            // FOREIGN KEY (penulis_user_id) REFERENCES users(id) (:1090) - **no
            // ON DELETE clause**, so MySQL's implicit NO ACTION (RESTRICT for DML)
            // applies: an author with an article cannot be deleted. Compare
            // notifikasi.user_id (:1046), which CASCADEs in this same batch.
            // Deliberately different, and not to be harmonised.
            $table->foreign('kategori_id')->references('id')->on('artikel_kategori');
            $table->foreign('penulis_user_id')->references('id')->on('users');
        });

        // ON UPDATE CURRENT_TIMESTAMP for diubah_at (:1088). Without this the
        // differ reports column_on_update drift with expected CURRENT_TIMESTAMP.
        DB::statement('ALTER TABLE artikel MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artikel');
    }
};
