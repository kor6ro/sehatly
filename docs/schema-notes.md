# Schema notes

Running log of every table or column that exists in `database/migrations/**` but
**not** in `telemedicine_test.sql`, each with a one-line justification.

## Why this file exists

`telemedicine_test.sql` is read-only law for this project: it must never be edited,
reformatted, re-encoded or reordered. Spec §4.4 therefore provides exactly one
sanctioned way to add infrastructure the 75-table contract does not mention — add it
in a migration and record it here. This file *is* that record, and
`php artisan sehatly:verify-schema` enforces it:

- an extra table listed in the table below is reported as **informational** and does
  not fail the run;
- an extra table that is **missing from this table is drift** and the verifier exits
  `1` naming it;
- a registry entry for a table that no longer exists in the live schema is reported
  as informational, so this file can be trimmed when a migration is removed.

That is what makes the "75 tables, 2 views verified" green at todo 18 possible
without touching the SQL file: the framework's own tables are present, declared, and
therefore forgiven.

`users` is deliberately **not** in the registry — it is one of the 75 tables
(`telemedicine_test.sql:132`). The scaffold migration that also creates `users` is a
collision, not an extra, and is tracked below.

## Registered extra tables

The verifier parses this table. Keep the first column a single backticked table name
and every cell on one line.

| Table | Source migration | Justification |
| --- | --- | --- |
| `migrations` | created by the migrator itself | Laravel's migration ledger; the framework refuses to run without it and it holds no domain data. |
| `cache` | `0001_01_01_000001_create_cache_table.php` | Laravel's database cache store; required by the framework's cache contract, carries no domain data. |
| `cache_locks` | `0001_01_01_000001_create_cache_table.php` | Laravel's cache lock table, written atomically alongside `cache`; cannot be deployed without it. |
| `jobs` | `0001_01_01_000002_create_jobs_table.php` | Laravel's queue payload table; the Reverb/broadcast and queued-mail waves need a durable queue. |
| `job_batches` | `0001_01_01_000002_create_jobs_table.php` | Laravel's `Bus::batch()` bookkeeping; ships with the same migration as `jobs` and cannot run without it. |
| `failed_jobs` | `0001_01_01_000002_create_jobs_table.php` | Laravel's dead-letter table for failed queue jobs; part of the same migration as `jobs`. |
| `passkeys` | `2024_01_01_000000_create_passkeys_table.php` | `laravel/passkeys` scaffold table. Not among the 75 tables and not among the spec's features; its fate is decided explicitly in todo 7 (plan Appendix A.4), not left implicit. |
| `personal_access_tokens` | `2026_09_26_222801_create_personal_access_tokens_table.php` | Sanctum's bearer-token table, published by `install:api` in todo 3. The `/api/v1` surface is bearer-token authenticated, so this table must exist. |
| `password_reset_tokens` | `0001_01_01_000000_create_users_table.php` | Scaffold web-auth table. Pending removal in todo 7 — see below. |
| `sessions` | `0001_01_01_000000_create_users_table.php` | Scaffold web-session table. Pending removal in todo 7 — see below. |

### Registered extra columns

`users` gains three columns from
`2025_08_14_170933_add_two_factor_columns_to_users_table.php` that
`telemedicine_test.sql:132-149` does not have: `two_factor_secret`,
`two_factor_recovery_codes`, `two_factor_confirmed_at`. The verifier reports them as
`extra_column` drift on `users`, which is the correct signal — plan Appendix A.4
mandates deleting that migration in todo 7, after which the drift disappears on its
own. They are listed here so the cause is on record rather than looking like a
mystery.

## Pending removal in todo 7 (plan Appendix A.4)

These are **not** long-term extras. They exist only because the Laravel scaffold
migrations are still on disk, and todo 7 must delete them in the same commit as
`docs/migration-order.md`:

- `database/migrations/0001_01_01_000000_create_users_table.php` creates `users`,
  `password_reset_tokens` and `sessions`. `users` **collides** with SQL table 12:
  Laravel orders by filename, so `0001_…` runs first and todo 8's
  `2026_10_01_…_users_table.php` then fails with
  `SQLSTATE 42S01 Table 'users' already exists`. Todo 8 authors the real `users`.
- `database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php`
  is **unrepresentable**: it calls `->after('password')`, but
  `telemedicine_test.sql:138` has `kata_sandi_hash` and no `password` column, so
  `->after()` cannot resolve. TOTP is also not in the 75-table schema — the plan's
  auth is OTP (`user_otp`, `telemedicine_test.sql:179`) plus Sanctum.
- `password_reset_tokens` and `sessions` are dropped together with the scaffold
  `users` migration. The sanctioned transient that follows is that
  `config/session.php` and `app/Providers/FortifyServiceProvider.php` still reference
  sessions until todo 30 removes the Inertia/Fortify surface. Recorded, not worked
  around.
- `2024_01_01_000000_create_passkeys_table.php` is a real extra with a real decision
  owed: keep and document it, or drop it together with Fortify in todo 30. Todo 7
  records that decision.

After todo 7, this file must be regenerated so the registry reflects the
post-disposition reality rather than arriving stale.

## Verifier normalisation rules (why cosmetic differences are not drift)

`php artisan sehatly:verify-schema` parses both `telemedicine_test.sql` and the live
`SHOW CREATE TABLE` output through the *same* grammar
(`App\Support\Schema\SqlSchemaParser`), so cosmetic differences cannot be reported
as drift. The deliberate folds are:

- **Integer display widths are dropped.** MySQL 8.0.19 deprecated them; `int(11)` and
  `int` are the same type with no effect on storage or comparison, so `TINYINT(1)` and
  a bare `tinyint` both normalise to `tinyint`. Real type changes (`char(2)` vs
  `char(3)`, `timestamp` vs `datetime`) are still reported.
- **Backticks, case and whitespace** are stripped; `KEY` and `INDEX` are the same
  thing.
- **Default quoting is normalised.** MySQL 8 prints numeric defaults quoted
  (`DEFAULT '0'`) while the DDL writes `DEFAULT 0`; `0` and `'0'` are the same
  default. `DEFAULT NULL` and "no DEFAULT clause" stay distinct, because
  `telemedicine_test.sql:148` relies on the difference.
- **Index names are compared only when the DDL wrote one.** An inline `UNIQUE`
  becomes index `email` in MySQL but `users_email_unique` in Laravel — the same
  constraint, two spellings, so uniqueness is compared as `NON_UNIQUE` semantics plus
  the ordered column list. The 30 explicitly named keys (`idx_jadwal`,
  `idx_booking_dokter`, `uq_interaksi`, `uq_stok`, `uq_consent`, `idx_faskes_geo`,
  `idx_icd10`, `idx_diag_icd10`, `idx_vital_pasien`, `idx_pasien_lahir`,
  `idx_spesialisasi`, …) *are* compared by name, keyed on
  `(TABLE_NAME, INDEX_NAME)` — `idx_icd10` is reused on two different tables
  (`telemedicine_test.sql:119` and `:297`), which is legal in MySQL.
- **Foreign keys are compared semantically** (local columns, referenced table and
  columns, `ON DELETE`, `ON UPDATE`), because an inline `FOREIGN KEY` is named
  `<table>_ibfk_<n>` by the engine. The one name the DDL writes — `fk_vital_rm` at
  `telemedicine_test.sql:1162`, added by `ALTER TABLE` — is compared by name.
  MySQL's implicit `RESTRICT` is materialised on both sides.
- **CHECK constraints are compared by expression, never by name.** MySQL generates
  `ulasan_dokter_chk_1/_2/_3` from the table name and declaration order
  (`telemedicine_test.sql:1055-1057`), so the expression is the only stable thing.
- **`PRIMARY KEY` columns are implicitly `NOT NULL`.** `telemedicine_test.sql:59`
  writes no `NOT NULL` on `id`; MySQL prints one. Both sides are folded to `NOT
  NULL` or every such column would read as drift.
- **`ENUM` and `SET` member case is preserved.** `ENUM('L','P')` is data, not
  spelling, so `enum('l','p')` is a real difference and is reported.

### Deliberately not compared

- **Storage engine, charset and collation.** Recorded on the model but not diffed:
  the plan's comparison list is types, unsigned, nullability, defaults, extra,
  indexes, FKs and CHECKs. The SQL sets `utf8mb4` / `utf8mb4_unicode_ci` once at
  database level (`telemedicine_test.sql:11-13`), not per table.
- **Column `COMMENT` text.** The 40 `COMMENT` clauses are documentation, not
  contract, and `telemedicine_test.sql:538` even stores the literal string
  `'NULL = ...'` inside one. The text is still scanned for keywords (and removed
  first) so it can never be mistaken for a clause.
- **View definitions.** The two views are compared by name and existence. MySQL
  rewrites `VIEW_DEFINITION` server-side (`information_schema.VIEWS`), so comparing
  the SQL text would be comparing against the server's own re-rendering rather than
  against the DDL.
- **Generated-column expressions, `SRID` and column order.** `telemedicine_test.sql`
  declares no generated columns and no `SRID`; the parser tokenises them without
  comparing them.
