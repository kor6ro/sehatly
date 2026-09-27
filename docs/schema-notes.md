# Schema notes

Running log of every table or column that exists in `database/migrations/**` but
**not** in `telemedicine_test.sql`, each with a one-line justification.

**Regenerated in todo 7** (plan Appendix A.4 step 3) immediately after the scaffold
disposition below, so the registry reflects post-disposition reality rather than
arriving stale. Supersedes todo 6's first version, which still listed
`password_reset_tokens`, `sessions` and `passkeys`.

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
(`telemedicine_test.sql:132`). The scaffold migration that also created it was a
collision, not an extra, and is deleted in todo 7 (see the disposition below).

**There is no column-level forgiveness.** The differ has no parsed column registry,
so an extra *column* is always `extra_column` **drift**, however well it is justified
here. The three `users.two_factor_*` columns were exactly that case: documented in
prose here, unforgivable in code. They are gone because their migration is gone.

## Registered extra tables

The verifier parses this table. Keep the first column a single backticked table name
and every cell on one line. Do not add any other markdown table in this file whose
first cell is a backticked identifier — the registry parser would read it as a
registered extra table and forgive a table that is really drift.

| Table | Source migration | Justification |
| --- | --- | --- |
| `migrations` | created by the migrator itself | Laravel's migration ledger; the framework refuses to run without it and it holds no domain data. |
| `cache` | `0001_01_01_000001_create_cache_table.php` | Laravel's database cache store; required by the framework's cache contract, carries no domain data. |
| `cache_locks` | `0001_01_01_000001_create_cache_table.php` | Laravel's cache lock table, written atomically alongside `cache`; cannot be deployed without it. |
| `jobs` | `0001_01_01_000002_create_jobs_table.php` | Laravel's queue payload table; the Reverb/broadcast and queued-mail waves need a durable queue. |
| `job_batches` | `0001_01_01_000002_create_jobs_table.php` | Laravel's `Bus::batch()` bookkeeping; ships with the same migration as `jobs` and cannot run without it. |
| `failed_jobs` | `0001_01_01_000002_create_jobs_table.php` | Laravel's dead-letter table for failed queue jobs; part of the same migration as `jobs`. |
| `personal_access_tokens` | `2026_09_26_222801_create_personal_access_tokens_table.php` | Sanctum's bearer-token table, published by `install:api` in todo 3. The `/api/v1` surface is bearer-token authenticated, so this table must exist. |

Seven registered extras, verified against the live `telemedisin_db` after todo 7's
`migrate:fresh`: all seven present, `0` `undocumented_extra_table`.

**The seven are derived, not asserted.**
`tests/Unit/Console/VerifySchemaCommandTest.php` reads this file, enumerates every table
`database/migrations/*.php` actually creates with `Schema::create('<literal>')`, adds
Laravel's own `migrations` ledger, subtracts the 75 contract tables
(`SqlSchemaParser` → `telemedicine_test.sql`), and requires the registry to cover exactly
what is left. It previously pinned a literal list of **ten**, which went stale the moment
todo 7 deleted three scaffold migrations and made `php artisan test tests/Unit` red
(73 tests, 72 passed) even though this file was correct. **Adding, removing or renaming a
migration now fails that test, naming the table, instead of silently going stale** — and a
`Schema::create($variable)` the walk cannot read fails it loudly rather than passing while
under-testing. If you add a scaffold table, add its row here in the same commit.

## Scaffold-migration disposition (decided and executed in todo 7)

Plan Appendix A.4 assigned this decision to todo 7 rather than todo 18, because
todos 8-17 all have their own `php artisan migrate:fresh` acceptance criterion and
each would have failed for a sequencing reason that looks like a schema bug. Three
scaffold migrations were deleted; all three are `laravel/…` scaffolding, none of them
is among the 75 tables, and nothing in Modules 1-5 reads any of them.

- **`0001_01_01_000000_create_users_table.php` — DELETED.** It creates `users`,
  `password_reset_tokens` and `sessions`. `users` **collides** with SQL table 12
  (`:132`): Laravel orders by filename, so `0001_…` runs first and todo 8's
  `2026_10_01_000012_users_table.php` would fail with
  `SQLSTATE 42S01 Table 'users' already exists`. Todo 8 authors the real `users`.
- **`2025_08_14_170933_add_two_factor_columns_to_users_table.php` — DELETED.** It is
  **unrepresentable**: it calls `->after('password')`, but `telemedicine_test.sql:138`
  has `kata_sandi_hash` and no `password` column, so `->after()` cannot resolve. TOTP
  is also not in the 75-table schema — the plan's auth is OTP (`user_otp`, `:179`)
  plus Sanctum.
- **`2024_01_01_000000_create_passkeys_table.php` — DELETED** (the decision A.4 left
  explicitly to todo 7). A.4 offered "keep and document as an extra, or drop together
  with Fortify in todo 30". Neither is executable: the migration declares
  `foreignId('user_id')->constrained()->cascadeOnDelete()`, and after the scaffold
  `users` migration is gone, `users` does not exist until todo 8 — so
  `migrate:fresh` dies with
  `SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'users'`
  before a single one of the 11 batch-A tables is created. Ten todos need a green
  `migrate:fresh` before todo 8 is even scheduled, so the choice was forced. The
  package stays in `composer.json` (removing it is not todo 7's business) and
  `config/fortify.php` / `app/Providers/FortifyServiceProvider.php` keep their
  `passkeys` feature block until todo 30 removes the whole web-auth surface; nothing
  queries the table at boot, so its absence cannot affect `/api/v1`. Todo 30 may
  re-add the migration if the surface is ever restored; that is recorded there, not
  here.

**`password_reset_tokens` and `sessions` are gone** with the scaffold `users`
migration. They are not among the 75 tables and the plan's auth is bearer-token
based. The sanctioned transient that follows: `config/session.php` and
`app/Providers/FortifyServiceProvider.php` still reference sessions until todo 30
removes the Inertia/Fortify surface. Recorded, not worked around — the plan already
accepts transients of exactly this kind (A.3 on the 44 deliberately broken
`resources/` files).

## Known verifier defect: InnoDB's implicit FK-support index — ALREADY FIXED in `27c6ca8`

Found and measured in todo 7. It was a defect in `App\Support\Schema\SchemaDiffer`
(todo 6's code), **not** in any migration, and it is recorded here because it was the
thing standing between todo 18 and its "75 tables, 2 views verified" exit 0.

> **STATUS: FIXED. Commit `27c6ca8` (`fix(dev): treat InnoDB FK-support indexes as implied
> rather than drift`), one commit after `c6d0beb`.** This section previously ended "It is
> todo 18's to make" and the plan's Appendix A.7 assigned the fix to todo 18. **Todo 18 must
> not re-apply it.** Re-deriving an already-landed fix either duplicates the behaviour or
> "simplifies" it back into a prefix match and reintroduces the drift.

80 of the 105 foreign keys in `telemedicine_test.sql` reference columns that **no
index in the DDL covers**. InnoDB requires an index on the referencing columns, so
MySQL creates one itself, named after the column, and prints it in
`SHOW CREATE TABLE` — so does the reference import, and so does a faithful migration.
`SqlSchemaParser` only records a `PRIMARY KEY`, a name-bearing `INDEX`/`UNIQUE KEY`,
an inline `UNIQUE` and an inline `PRIMARY KEY`; it never synthesises InnoDB's implicit
index. `SchemaDiffer::diffIndexes()` therefore reported every such index as
`extra_index` **drift**, and no migration could make it go away:

    master_kabupaten_kota  KEY `master_kabupaten_kota_provinsi_id_foreign` (provinsi_id)  -> extra_index drift

Three batch-A tables were affected — `master_kabupaten_kota`, `master_kecamatan`,
`master_kelurahan` — one each. The other eight have no foreign key, which is why
`verify-schema --tables=master_provinsi,master_agama,master_icd10` is green and why the
plan's own criterion picked those three.

The fix is the minimal correct one: `diffIndexes()` now builds the set of *implied*
indexes from the local-column lists of the foreign keys that matched, and skips any
leftover live index whose ordered column list is exactly one of them. It is stricter
than a prefix match on purpose — a deliberate composite index such as
`(provinsi_id, nama)`, or a reordered `(b, a)` for a key on `(a, b)`, is still reported,
because no engine would ever create either on its own. The rationale is recorded on the
method itself (`app/Support/Schema/SchemaDiffer.php`, `diffIndexes()`).

Two "fixes" available to a **migration** author are both still wrong, and the guidance is
unchanged: omitting the foreign key loses a real constraint and yields
`missing_foreign_key` instead, and naming the index explicitly only changes which name
appears. **So a batch author must still not "fix" an `extra_index` by hand** — if you see
one naming a bare FK-support index, compare its ordered column list against the foreign
key's local columns first; if they match exactly and it is still reported, that is a bug to
report, not a migration to edit.

**The measurement is retained as the calibration reference: 105 foreign keys, 80 with no
covering index.** Re-measure it with `App\Support\Schema\SqlSchemaParser`, which returns
exactly 105/80. A naive index regex reports **0 uncovered**, because it matches the
`KEY (...)` tail of `FOREIGN KEY (...) REFERENCES ...`.

`docs/migration-order.md` carries the same warning for the batch authors of todos 8-17.

## Corrections to the plan's schema-reality list (measured, not copied)

Todo 7 parsed the reference DDL with the plan's own `SqlSchemaParser` and counted the
timestamp columns directly. The plan's tally is wrong twice, and todo 19 has
acceptance criteria that depend on the right numbers:

- Tables with **neither** `dibuat_at` nor `diubah_at`: **39**, not 28. The plan's list
  has 29 entries, wrongly includes `apotek_stok` (which has `diubah_at`), and omits
  **11**: `master_provinsi`, `master_kabupaten_kota`, `master_kecamatan`,
  `master_kelurahan`, `master_penjamin`, `master_spesialisasi`, `konsultasi_chat`,
  `master_metode_pembayaran`, `master_promo`, `artikel_kategori`, `persetujuan_pdp`.
  **Derivation: 29 − 1 + 11 = 39.**
- Tables with `dibuat_at` only: **19**, not 18 — the plan omits `audit_log` (`:1129`).
  **Derivation: 18 + 1 = 19.**
- `diubah_at` only: **1** (`apotek_stok`). Both columns: **16** — and those 16 are the
  only tables that need the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER`, since Laravel 13
  has no Blueprint helper for it.

39 + 19 + 1 + 16 = 75, so the split is exhaustive. Todo 19 must **not** assert
`$timestamps === false` for "all 28": that assertion passes while silently under-testing
11 tables. Use the per-table facts in `docs/migration-order.md` instead.

The full per-table split is in `docs/migration-order.md`.

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
- **A nullable column with no `DEFAULT` clause equals one declared `DEFAULT NULL`.**
  MySQL's implicit default for a nullable column is NULL and `information_schema`
  cannot tell the two apart; without this fold every nullable column in the schema
  would read as drift.
- **Index names are compared only when the DDL wrote one.** An inline `UNIQUE`
  becomes index `email` in MySQL but `users_email_unique` in Laravel — the same
  constraint, two spellings, so uniqueness is compared as `NON_UNIQUE` semantics plus
  the ordered column list. The 30 explicitly named keys (`idx_jadwal`,
  `idx_booking_dokter`, `uq_interaksi`, `uq_stok`, `uq_consent`, `idx_faskes_geo`,
  `idx_icd10`, `idx_diag_icd10`, `idx_vital_pasien`, `idx_pasien_lahir`,
  `idx_spesialisasi`, …) *are* compared by name, keyed on
  `(TABLE_NAME, INDEX_NAME)` — `idx_icd10` is reused on two different tables
  (`telemedicine_test.sql:119` and `:297`), which is legal in MySQL.
  **Do not "correct" `:297` to `:291`.** `:291` is where `pasien_riwayat_penyakit`'s
  `icd10_kode VARCHAR(8) NULL` *column* is declared; the `INDEX idx_icd10 (icd10_kode)`
  that uses it is at `:297`, the last line of that table's body. The plan contradicts
  itself on this point — its authoritative line-number index says `297`, while two
  inline citations in the plan's prose say `291`. The authoritative index wins (the plan
  says so explicitly), and the file confirms it:
  `Select-String -Path telemedicine_test.sql -Pattern idx_icd10` returns exactly
  `:119 INDEX idx_icd10 (kode)` and `:297 INDEX idx_icd10 (icd10_kode)`.
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

## Verifier usage traps for todos 8-17

- **In `--tables=` mode the PASS banner lies about coverage.** It is formatted from
  the *full* reference model, so `--tables=master_provinsi` prints
  "PASS — 75 tables, 2 views verified" after verifying one table. Judge by the exit
  code and the `Discrepancies: N` line; those are truthful.
- **`--tables=` restricts both sides.** Asking for a table the DDL does not define
  yields `unknown_requested_table`, and a requested table that is not in the live
  schema yields `missing_table` — that is how a filtered run proves it is really
  looking.
- **Never run `php artisan install:api` again** (A.5): bare, it silently runs
  `migrate` against `telemedisin_db` because `confirm(default: true)` resolves TRUE
  non-interactively, and `--force` duplicates the `api:` line in
  `bootstrap/app.php`. Sanctum is already installed; the command has no remaining
  work.
- **Never pass `bootstrap` or `.` to pint.** Naming that path overrides pint's cache
  exclude and it reformats `bootstrap/cache/*.php`. Bare `vendor/bin/pint` is safe.

## Batch-B schema limitations the database cannot enforce (todos 8, 20, 45)

Batch B (`users`, `roles`, `permissions`, `role_permissions`, `user_roles`,
`user_otp`, `user_devices`, `user_refresh_tokens` — SQL tables 12–19) is at
parity, but four of its shapes push work into the application layer. Each is
recorded here with a one-line justification, because each looks like an
omission a later reader would "fix" — and the verifier would report every such
fix as drift:

- `user_refresh_tokens.token_hash` (`:207`) carries **no UNIQUE**: rotation
  cannot look a token up by index, so every refresh is a full table scan and
  the rotation flow must do an application-level
  `WHERE token_hash = ?` existence check inside the rotation transaction —
  this is the model todo 45's idempotency work builds on.
- `user_refresh_tokens` has **no `device_id` column**: there is no column to
  scope a `dicabut` update to one device, so per-device token revocation is
  impossible — revocation is always per user (all tokens) or per token (one
  row). Adding the column would be drift.
- `user_otp` has **no attempt-counter column**: the schema records
  `sudah_dipakai` and `kedaluwarsa_at` but nothing counts guesses, so OTP
  brute-force protection is application-only and lives entirely in todo 20's
  rate limiter.
- `users.kata_sandi_hash` (`:138`) is `NOT NULL` with **no nullable or
  OTP-only representation**: an OTP-only signup (phone number, no password)
  must still generate a random unusable hash, because the column cannot hold
  "no password yet".
