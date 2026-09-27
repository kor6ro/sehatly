<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Post-table migration 77 of 78 - the view `v_dokter_katalog`, emitted as a raw
 * `CREATE OR REPLACE VIEW` copied **verbatim** from `telemedicine_test.sql`.
 *
 * ## Why raw DDL and not the query builder
 *
 * `telemedicine_test.sql:1166-1196` is section `[15] VIEW`, and the first
 * statement in it (`:1170-1187`) is reproduced below. I resolved the range by
 * searching the file for the view's name rather than trusting the plan's `:NNN`
 * citation, and confirmed the enclosing statement: `:1169` is a comment
 * (`-- Katalog dokter untuk halaman pencarian`), `:1170` opens
 * `CREATE OR REPLACE VIEW v_dokter_katalog AS` and `:1187` closes the `GROUP BY`
 * with the statement's terminating semicolon.
 *
 * This **cannot** be expressed fluently, and the reason is the `GROUP_CONCAT`:
 * `GROUP_CONCAT(s.nama SEPARATOR ', ')` is MySQL-only syntax with no
 * first-class builder equivalent in any Laravel version. A `selectRaw()` would
 * reproduce it, but at that point the "fluent" builder is carrying a raw string
 * anyway, and every other clause would still have to be hand-transcribed, which
 * is where a paraphrase would creep in. **Any re-implementation of this
 * statement is a paraphrase, and a paraphrase here is a defect.** So the whole
 * statement is passed to the server as one `DB::statement()`, and it is written
 * as a PHP **nowdoc** (`<<<'SQL' ... SQL;`) rather than a quoted string, so the
 * bytes reaching MySQL are the bytes below with no escape processing in between.
 *
 * The `SEPARATOR ', '` is load-bearing, not decoration: the default separator is
 * a bare comma with no space, so dropping it changes the rendered value of every
 * multi-specialisation doctor from `A, B` to `A,B`. It is also a **literal
 * comma-space inside a single-quoted SQL string**, which is exactly the token an
 * over-eager escaping or re-quoting pass would mangle. Hence the nowdoc.
 *
 * One further note on `GROUP_CONCAT`: `telemedicine_test.sql` sets no
 * `group_concat_max_len` anywhere, so the length of the aggregated string is
 * bounded by the server's configured session value rather than by the schema.
 * That is a property of the contract as written and is recorded here rather than
 * "fixed", because widening it would change behaviour the DDL does not ask for.
 *
 * ## The three `WHERE` predicates are all load-bearing - do not reduce them to one
 *
 * `d.status_verifikasi = 'terverifikasi'` (`:427`, `ENUM('pending',
 * 'terverifikasi', 'ditolak') NOT NULL DEFAULT 'pending'`), `d.status_aktif = 1`
 * (`:430`, `NOT NULL DEFAULT 1`) and `d.tersedia_telemedisin = 1` (`:426`,
 * `NOT NULL DEFAULT 1`).
 *
 * `:427` and `:430` together are the trap recorded in `docs/schema-notes.md`
 * (batch D): **a `dokter` row may be `status_aktif = 1` *and*
 * `status_verifikasi = 'pending'`**, because nothing couples the two columns and
 * the defaults are "active" and "pending" respectively. A freshly inserted
 * doctor is therefore active-but-unverified, so a query that filters on
 * `status_aktif` alone leaks unverified doctors into the public directory. Todo
 * 22 is the consumer and it reads this view rather than `dokter` directly, so
 * **all three predicates must survive**: drop the verification one and the
 * directory leaks, drop `status_aktif` and deactivated doctors keep appearing,
 * drop `tersedia_telemedisin` and doctors who have opted out of telemedicine are
 * listed as bookable.
 *
 * The two `LEFT JOIN`s are also load-bearing. `dokter_spesialisasi` is optional
 * for a doctor (a general practitioner may hold no `dokter_spesialisasi` row at
 * all), so an inner join would silently drop those doctors from the catalogue
 * entirely; and the second `LEFT JOIN` is what makes a `dokter_spesialisasi` row
 * pointing at a deleted `master_spesialisasi` row render as `NULL` instead of
 * removing the doctor. `GROUP_CONCAT` over a `NULL` yields `NULL`, so
 * `spesialisasi` is nullable by construction.
 *
 * ## Column list - seven columns, and the verifier does NOT check them
 *
 * In order: `dokter_id`, `nama_lengkap`, `tipe`, `biaya_konsultasi_online`,
 * `rating_rata_rata`, `jumlah_konsultasi`, `spesialisasi`.
 *
 * Two of the seven are fixed by an explicit alias (`d.id AS dokter_id` and
 * `GROUP_CONCAT(...) AS spesialisasi`); the other five already carry their own
 * column names. `nama_lengkap` and `tipe` arrive unaliased and inherit their
 * names from `users.nama_lengkap` (`:135`) and `dokter.tipe` (`:412`)
 * respectively, which is why the view exposes a column called `tipe` holding
 * `dokter.tipe`'s seven-value ENUM - a vocabulary that shares exactly one member
 * with `master_spesialisasi.tipe`'s three-value ENUM (`:406`) and must not be
 * compared against it.
 *
 * **`verify-schema` compares views by name and existence only.**
 * `SchemaDiffer` reports `missing_view` and `extra_view` and nothing else for
 * views, because MySQL re-renders `information_schema.VIEWS.VIEW_DEFINITION`
 * server-side, so comparing the SQL text would compare against the server's own
 * re-rendering rather than against the DDL (`docs/schema-notes.md`, "Deliberately
 * not compared"). **A view that exists and is completely wrong is still
 * `Discrepancies: 0`.** The definition was therefore verified by hand against
 * the lines above and by `SHOW CREATE VIEW`, and that evidence is in
 * `.omo/evidence/task-18-sehatly.md`; the automated check cannot stand in for it.
 *
 * ## `public $withinTransaction = false`
 *
 * `CREATE OR REPLACE VIEW` is DDL. On MySQL configurations where the statement
 * takes an implicit commit, wrapping it can commit or roll back work the caller
 * did not ask to commit or roll back. Laravel only wraps a migration in a
 * transaction if nothing opts out, so this property is set explicitly. It is the
 * same reason migration 76 sets it for its `ALTER TABLE`.
 *
 * The DDL's own reset section drops views **before** tables (`:22-23`), and this
 * migration's `down()` follows the same rule.
 */
return new class extends Migration
{
    /**
     * Set for the reason given in the class docblock: `CREATE OR REPLACE VIEW`
     * is DDL and must not be wrapped in a transaction.
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_dokter_katalog AS
            SELECT
              d.id AS dokter_id,
              u.nama_lengkap,
              d.tipe,
              d.biaya_konsultasi_online,
              d.rating_rata_rata,
              d.jumlah_konsultasi,
              GROUP_CONCAT(s.nama SEPARATOR ', ') AS spesialisasi
            FROM dokter d
            JOIN users u ON u.id = d.user_id
            LEFT JOIN dokter_spesialisasi ds ON ds.dokter_id = d.id
            LEFT JOIN master_spesialisasi s ON s.id = ds.spesialisasi_id
            WHERE d.status_verifikasi = 'terverifikasi'
              AND d.status_aktif = 1
              AND d.tersedia_telemedisin = 1
            GROUP BY d.id, u.nama_lengkap, d.tipe, d.biaya_konsultasi_online,
                     d.rating_rata_rata, d.jumlah_konsultasi;
            SQL);
    }

    /**
     * Reverse the migrations.
     *
     * `DROP VIEW IF EXISTS` runs **before any table drop**, matching the DDL's
     * own reset order at `:22-23` (views first, then tables). Nothing here drops
     * a table: `dokter`, `users`, `dokter_spesialisasi` and `master_spesialisasi`
     * belong to migrations 31, 12, 32 and 30 and are dropped by their own
     * `down()` methods, which run later in a reverse-order rollback.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_dokter_katalog');
    }
};
