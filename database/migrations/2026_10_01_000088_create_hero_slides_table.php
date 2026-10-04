<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The landing carousel's own table — an EXTRA table, registered in `docs/schema-notes.md`.
 *
 * ## Why a table at all
 *
 * The owner's decision is that an `admin` republishes the hero on `/` without a
 * deploy: a banner is campaign content, and a developer build for a swapped image is
 * exactly the cost the module exists to avoid. `telemedicine_test.sql` is read-only
 * law and names no such table, so this is the case `docs/schema-notes.md` exists for:
 * add the table in a migration and record it there. `sehatly:verify-schema` enforces
 * the record — an extra table missing from the registry is drift and exits 1, a
 * registered one is reported as information and passes.
 *
 * ## What the schema does not enforce, and the request layer must
 *
 * - No `CHECK` couples `gambar` to `gambar_alt`, so a slide with an image and no
 *   alternative text is representable. `UnggahGambarHeroRequest` requires the alt
 *   text *with* the file: a hero is decorative only until it carries the one thing a
 *   screen reader can use, and the two are uploaded together or not at all.
 * - No `CHECK` orders `mulai_tayang` before `selesai_tayang`, and neither column
 *   requires the other. Three windows are therefore representable and all three are
 *   legal: both `NULL` (always on), only a start (open-ended) and only an end (until
 *   a date). A backwards window does not fail the write — it simply matches no
 *   instant, which is what "this campaign is over" should look like in the data.
 * - `status = 'tayang'` is a switch, not a permission: the window is checked on read,
 *   so scheduling is a data change and unscheduling is the same change in reverse.
 * - `urutan` is deliberately NOT unique. Two slides may share a position while an
 *   operator is rearranging them; ties break on `id`, so the carousel stays
 *   deterministic without a write ever being refused for ordering alone.
 * - `cta_target` is a path with no table to point at. The request rejects an
 *   absolute or scheme-bearing target (`regex` anchored on a single leading `/`),
 *   because a slide is a link every visitor clicks and an open redirect on the front
 *   page is not a content feature.
 * - `status` is VARCHAR rather than this reference DDL's usual ENUM, for a contract
 *   reason rather than a modelling one: `php artisan sehatly:enums --check` closes
 *   the set of ENUM columns over `telemedicine_test.sql` in both directions, and an
 *   extra table is by definition absent from that file — one ENUM column here is
 *   reported as `missing_in_reference_ddl` and stops the gate. `Rule::in` on both
 *   requests holds the two values over `HeroService::STATUS_*` instead.
 *
 * `diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER`
 * (`docs/migration-order.md` rule 5): Laravel's Blueprint has no helper for it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hero_slides', function (Blueprint $table) {
            // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT.
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // TINYINT UNSIGNED NOT NULL DEFAULT 0 — display order, ascending; ties
            // break on `id` so the strip never reorders itself on a tie.
            $table->unsignedTinyInteger('urutan')->default(0);

            // VARCHAR(60) NULL — the small label above the headline. Null means the
            // slide starts with its headline, which is how the fallback slides read.
            $table->string('eyebrow', 60)->nullable();

            // VARCHAR(160) NOT NULL — the headline.
            $table->string('judul', 160);

            // VARCHAR(400) NOT NULL — one or two sentences under it.
            $table->string('deskripsi', 400);

            // VARCHAR(60) NOT NULL — the call-to-action's own words.
            $table->string('cta_label', 60);

            // VARCHAR(120) NOT NULL — an INTERNAL path (`/dokter`). Validated as
            // single-slash-led, so `https://…` and `//host` are refused on write.
            $table->string('cta_target', 120);

            // VARCHAR(255) NULL — a path on the `public` disk, never a full URL:
            // the host belongs to the environment, not to the row.
            $table->string('gambar', 255)->nullable();

            // VARCHAR(160) NULL — alternative text, required by the upload request
            // whenever `gambar` is present.
            $table->string('gambar_alt', 160)->nullable();

            // VARCHAR(10) NOT NULL DEFAULT 'draf', draft first so a row is invisible
            // to visitors until somebody publishes it.
            //
            // NOT an `enum()` although `telemedicine_test.sql` spells every status
            // that way. `php artisan sehatly:enums --check` compares the ENUM columns
            // of the LIVE schema against that file as a closed set in BOTH
            // directions, and `hero_slides` is a table the file does not name - so a
            // single ENUM column here fails the contract gate as
            // `missing_in_reference_ddl`. The vocabulary is therefore enforced where
            // an extra table's vocabulary has to be: `Rule::in` on both requests,
            // over the two `HeroService::STATUS_*` constants the service compares
            // against. A wrong value cannot reach this column through the API, and a
            // wrong value in the database cannot be written by anything that does not
            // bypass validation.
            $table->string('status', 10)->default('draf');

            // DATETIME NULL / DATETIME NULL — the publication window; both null is
            // "always", and the read applies whichever bounds are present.
            $table->timestamp('mulai_tayang')->nullable();
            $table->timestamp('selesai_tayang')->nullable();

            // TIMESTAMP pair; `diubah_at` gains ON UPDATE by the raw ALTER below.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // The public read's only index: `status = 'tayang'` then `urutan`, which
            // is the whole predicate and the whole ordering of `GET /hero`.
            $table->index(['status', 'urutan'], 'idx_hero_status_urutan');
        });

        DB::statement('ALTER TABLE hero_slides MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_slides');
    }
};
