<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Post-table migration 78 of 78 - the view `v_pendapatan_bulanan`, emitted as a
 * raw `CREATE OR REPLACE VIEW` copied **verbatim** from `telemedicine_test.sql`.
 *
 * ## The source statement
 *
 * `telemedicine_test.sql:1189-1196` is the second and last statement of section
 * `[15] VIEW`, preceded by the comment `-- Rekap pendapatan bulanan` at `:1189`:
 *
 * ```sql
 * -- :1190-1196
 * CREATE OR REPLACE VIEW v_pendapatan_bulanan AS
 * SELECT DATE_FORMAT(p.dibayar_at, '%Y-%m') AS bulan,
 *        COUNT(*) AS jumlah_transaksi,
 *        SUM(p.jumlah) AS total_pendapatan
 * FROM pembayaran p
 * WHERE p.status = 'berhasil'
 * GROUP BY bulan;
 * ```
 *
 * I resolved the range by searching the file for the view's name rather than
 * trusting the plan's `:NNN` citation, and confirmed the enclosing statement:
 * `:1190` opens the `CREATE OR REPLACE VIEW` and `:1196` closes it. The plan's
 * citation `:1190-1196` is correct.
 *
 * It is passed to the server as one `DB::statement()` written as a PHP
 * **nowdoc** (`<<<'SQL' ... SQL;`), so the bytes reaching MySQL are the bytes
 * above with no escape processing in between. The `DATE_FORMAT` format string
 * `'%Y-%m'` is a **literal percent-Y-percent-m inside a single-quoted SQL
 * string**, which is exactly the token an over-eager escaping or re-quoting pass
 * would mangle, so it must not be rebuilt from a PHP format string.
 *
 * ## Do NOT rewrite `GROUP BY bulan` as `GROUP BY DATE_FORMAT(p.dibayar_at, '%Y-%m')`
 *
 * The statement groups by the **output alias** `bulan`, not by the underlying
 * expression. The two are equivalent for this query - MySQL resolves the alias,
 * and the `SELECT` projection is the same expression - so "fixing" it would
 * change nothing observable. It is called out because the alias form is the one
 * thing a reviewer is likely to "correct", and a correction here is a change to
 * a read-only contract with zero behavioural benefit. The alias form is also
 * what keeps the view working under `ONLY_FULL_GROUP_BY`: the grouped expression
 * is identical to the projected one, so no row is aggregated over a value that
 * was not grouped.
 *
 * ## `p.dibayar_at` is NULLABLE, so a `NULL` month group is representable
 *
 * `pembayaran.dibayar_at` is `DATETIME NULL` (`:967`), while the view filters on
 * `p.status = 'berhasil'` (`:966`, `ENUM('pending','berhasil','gagal',
 * 'kedaluwarsa','refund') NOT NULL DEFAULT 'pending'`). **Nothing in the schema
 * couples the two**: a row can carry `status = 'berhasil'` with `dibayar_at IS
 * NULL`, because a payment can be marked successful by a gateway callback that
 * carried no settlement timestamp.
 *
 * `DATE_FORMAT(NULL, '%Y-%m')` is `NULL`, not an error and not an empty string,
 * so such rows aggregate into a single group whose `bulan` is `NULL` - and
 * because `GROUP BY` treats `NULL` as one value rather than as "no value", those
 * rows are **summed together** rather than being excluded. A monthly revenue
 * report that does not exclude them will therefore show a `NULL`-monthed row
 * whose `total_pendapatan` mixes in payments that were never dated. **The view
 * is reproduced exactly as the DDL writes it, so this is documented, not fixed**;
 * the fix belongs in the consuming service (todo 45), which owns the payment
 * write path and is the only place that can decide what a successful payment
 * without a settlement timestamp should display as.
 *
 * This is the reason the migration does not add a `HAVING bulan IS NOT NULL`.
 * Adding one would change the view's result set, which is drift the verifier
 * cannot even see (see the note on view comparison below) and a behaviour change
 * the read-only contract does not ask for.
 *
 * ## `pembayaran`'s index covers the filter but not the grouping
 *
 * `INDEX idx_bayar_status (status, dibayar_at)` (`:972`) is a composite whose
 * leftmost column is `status`, so the `WHERE p.status = 'berhasil'` predicate is
 * index-satisfiable. The `GROUP BY` is on `DATE_FORMAT(p.dibayar_at, '%Y-%m')` -
 * a **function** of `dibayar_at` - and MySQL cannot use an index to satisfy a
 * grouping on a function of a column, so the `dibayar_at` half of that composite
 * is not usable for ordering the grouping even though it is in the index. The
 * view is therefore correct but not free. Recorded as a fact about the contract,
 * not a migration concern: adding a generated column or a functional index would
 * be schema drift, and `telemedicine_test.sql` is read-only law.
 *
 * ## Column list - three columns, and the verifier does NOT check them
 *
 * In order: `bulan`, `jumlah_transaksi`, `total_pendapatan`. All three are
 * explicitly aliased. `bulan` is the `DATE_FORMAT` result and is therefore a
 * **string**, not a date and not a `YEAR-MONTH` numeric; `jumlah_transaksi` is
 * `COUNT(*)`; `total_pendapatan` is `SUM` over `pembayaran.jumlah`, which is
 * `DECIMAL(14,2)` (`:962`), so the aggregate is wider than any single row's
 * value. The exact types MySQL materialised are in
 * `.omo/evidence/task-18-sehatly.md`.
 *
 * **`verify-schema` compares views by name and existence only.**
 * `SchemaDiffer` reports `missing_view` and `extra_view` and nothing else for
 * views, because MySQL re-renders `information_schema.VIEWS.VIEW_DEFINITION`
 * server-side (`docs/schema-notes.md`, "Deliberately not compared"). **A view
 * that exists and is completely wrong is still `Discrepancies: 0`.** The
 * definition was verified by hand against the lines above and by
 * `SHOW CREATE VIEW`, and that evidence is in `.omo/evidence/task-18-sehatly.md`.
 *
 * ## `public $withinTransaction = false`
 *
 * `CREATE OR REPLACE VIEW` is DDL, and on MySQL configurations where the
 * statement takes an implicit commit, wrapping it can commit or roll back work
 * the caller did not ask to commit or roll back. Laravel only wraps a migration
 * in a transaction if nothing opts out, so this property is set explicitly. It is
 * the same reason migrations 76 and 77 set it.
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
            CREATE OR REPLACE VIEW v_pendapatan_bulanan AS
            SELECT DATE_FORMAT(p.dibayar_at, '%Y-%m') AS bulan,
                   COUNT(*) AS jumlah_transaksi,
                   SUM(p.jumlah) AS total_pendapatan
            FROM pembayaran p
            WHERE p.status = 'berhasil'
            GROUP BY bulan;
            SQL);
    }

    /**
     * Reverse the migrations.
     *
     * `DROP VIEW IF EXISTS` runs **before any table drop**, matching the DDL's
     * own reset order at `:22-23` (views first, then tables). Nothing here drops
     * a table: `pembayaran` belongs to migration 63 and is dropped by its own
     * `down()`, which runs later in a reverse-order rollback.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_pendapatan_bulanan');
    }
};
