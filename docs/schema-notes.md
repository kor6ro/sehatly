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

**There was a second registry here until todo 18, and it is gone.** A
*Deferred constraints* section, exactly parallel to the one above, existed to
register `fk_vital_rm` as a `missing_foreign_key` that was intentionally absent
until `rekam_medis` could exist. Its three rules were: a registered
`missing_foreign_key` is informational; a registered constraint that is **present**
is **drift**, so a stale row forces its own removal; and an unregistered
`missing_foreign_key` stays drift. That is what made the "75 tables, 2 views
verified" green at todo 18 achievable without touching the SQL file, together with
the framework's own tables being present, declared, and therefore forgiven.

**The deferral it existed for is resolved**: migration
`2026_10_01_000076_add_deferred_foreign_keys_table.php` adds `fk_vital_rm` from SQL
section `[14]` (`:1161-1163`), so rule 2 reported the row as
`fulfilled_deferred_foreign_key` **drift** and the section had to go. The whole
section was removed in the same commit as the constraint, together with
`VerifySchemaParity`'s `DeferredConstraintRegistry::fromMarkdown()` call — because
that call is **unconditional** and the class **raises on an empty registry**, so
deleting the section alone would have made the verifier exit `2` rather than `0`.
Do not reintroduce either half.

`users` is deliberately **not** in the registry — it is one of the 75 tables
(`telemedicine_test.sql:132`). The scaffold migration that also created it was a
collision, not an extra, and is deleted in todo 7 (see the disposition below).

**There is no column-level forgiveness.** The differ has no parsed column registry,
so an extra *column* is always `extra_column` **drift**, however well it is justified
here. The three `users.two_factor_*` columns were exactly that case: documented in
prose here, unforgivable in code. They are gone because their migration is gone.

## Registered extra tables

The verifier parses this table. Keep the first column a single backticked table name
and every cell on one line. The parser reads **only the rows under its own heading**
(`ExtraTableRegistry::HEADING`, resolved by `SchemaNotesSection`), so a markdown
table added anywhere else in this file cannot be mistaken for a registry row.

**This used to be two registries, not one.** A `DeferredConstraintRegistry` parsed a
sibling `## Deferred constraints` section through the same helper, which is why
`SchemaNotesSection` exists at all: left unscoped, each parser would have read the
other's rows, and the extras parser would have forgiven `fk_vital_rm` as a *table*.
That registry was removed in todo 18 with its last deferral resolved, so
`DeferredConstraintRegistry` is now an unused class kept only for the tests that
pin the retired behaviour. If a new deferred constraint ever appears, it needs a
registry row **and** the `fromMarkdown()` call reinstated in the same commit —
neither alone is sufficient, and the second without the first exits `2`.

| Table | Source migration | Justification |
| --- | --- | --- |
| `migrations` | created by the migrator itself | Laravel's migration ledger; the framework refuses to run without it and it holds no domain data. |
| `cache` | `0001_01_01_000001_create_cache_table.php` | Laravel's database cache store; required by the framework's cache contract, carries no domain data. |
| `cache_locks` | `0001_01_01_000001_create_cache_table.php` | Laravel's cache lock table, written atomically alongside `cache`; cannot be deployed without it. |
| `jobs` | `0001_01_01_000002_create_jobs_table.php` | Laravel's queue payload table; the Reverb/broadcast and queued-mail waves need a durable queue. |
| `job_batches` | `0001_01_01_000002_create_jobs_table.php` | Laravel's `Bus::batch()` bookkeeping; ships with the same migration as `jobs` and cannot run without it. |
| `failed_jobs` | `0001_01_01_000002_create_jobs_table.php` | Laravel's dead-letter table for failed queue jobs; part of the same migration as `jobs`. |
| `personal_access_tokens` | `2026_09_26_222801_create_personal_access_tokens_table.php` | Sanctum's bearer-token table, published by `install:api` in todo 3. The `/api/v1` surface is bearer-token authenticated, so this table must exist. |
| `sessions` | `2026_10_01_000080_create_sessions_table.php` | Laravel's `database` session store. `config/session.php` defaults to that driver, but the contract migrations only cover the 75 contract tables plus `cache` and `jobs`, so nothing created it: every server-side web route returned HTTP 500 until this migration. No test caught it because all tests exercise the stateless API and none boots `php artisan serve`. |

Eight registered extras, verified against the live `telemedisin_db` after todo 7's
`migrate:fresh`: all eight present, `0` `undocumented_extra_table`.

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
  `telemedicine_test.sql:1162`, added by `ALTER TABLE` — is compared by name, and is
  the one name the deferred-constraint registry can key on.
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

## Batch-C deferred and deliberately unconstrained columns (todo 9)

Batch C (`pasien`, `pasien_anggota_keluarga`, `pasien_alergi`,
`pasien_riwayat_penyakit`, `pasien_imunisasi`, `pasien_tanda_vital`,
`master_penjamin`, `pasien_penjamin` — SQL tables 20–27) is at parity, but three
columns push work onto later todos. **None of them is an extra table**, so the
extra-table registry is the wrong instrument for all three, and each is recorded
in the place that fits it.

- `pasien_tanda_vital.rekam_medis_id` (`:315`) — **column present, foreign key
  RESOLVED in todo 18.** It was deferred until migration `2026_10_01_000076` per the
  SQL's section `[14]` (`:1161-1163`), which adds
  `CONSTRAINT fk_vital_rm — ON DELETE SET NULL`; that migration has now run and
  the constraint exists in the live schema. The deferral was a dependency ordering,
  not a choice: `rekam_medis` is SQL table 42 (batch G, todo 13) and this column is
  created at position 25, so declaring the constraint inline at `:312` would fail
  `migrate:fresh` with MySQL 1824. Between todo 9 and todo 18 the column had **no** row
  in `information_schema.REFERENTIAL_CONSTRAINTS` and that absence was the correct
  state; **it is no longer the correct state**. Adding the constraint *earlier* than
  position 76 would still have been `extra_foreign_key` drift, which is what the
  retired registry row existed to express. Note the delete rule is `ON DELETE SET NULL`
  and deliberately **not** `CASCADE`, which is the opposite polarity to `pasien_id`'s
  `ON DELETE CASCADE` (`:328`) on the same table: a reading is meaningless without its
  patient, but the measurement outlives the encounter record it was filed under.
- `pasien_penjamin.faskes_rujukan_id` (`:346`) — **column present as a nullable
  unsigned `BIGINT` with NO foreign key, and none is owed.** The plan's
  authoritative no-foreign-key list (line 152) records `:346` as carrying none, and
  live measurement agrees: the only constraint this batch ever deferred was
  `fk_vital_rm`, and that deferral is now resolved. An earlier revision of this file
  called it an "FK to `faskes` deferred to migration 76" — **that was wrong**; the prose
  lost to the authoritative list. So leave it a bare unsigned `BIGINT`, do not widen
  it to `foreignId()` semantics, and do **not** register it anywhere: registration
  would be a promise to create a constraint the DDL never declares.
  **Migration `2026_10_01_000076` now exists and deliberately does not touch this
  column** — see the "DO NOT add a foreign key to `pasien_penjamin.faskes_rujukan_id`"
  section of that migration's own docblock, which records the prohibition so that a
  later reader cannot quietly "complete" it.
- `pasien_riwayat_penyakit.icd10_kode` (`:291`) — a **bare indexed column with no
  foreign key, by design**: the SQL declares `INDEX idx_icd10 (icd10_kode)` (`:297`)
  and no `FOREIGN KEY` line, even though `master_icd10` already exists from batch A
  and the column is exactly the master's `kode` type, so
  `->constrained('master_icd10', 'kode')` would *work* and still be drift. The same
  reasoning applies to `pasien_alergi.dicatat_oleh_user_id` (`:281`, no FK) and to
  `pasien.nomor_rm` / `pasien.nik` semantics. `idx_icd10` is also the **same index
  name** the SQL reuses on `master_icd10` (`:119`); index names are scoped per table,
  so both exist and the verifier keys them on `(TABLE_NAME, INDEX_NAME)`. Nothing to
  defer — the DDL declares no constraint, so there is nothing for a registry to
  excuse.

Recorded in batch A and B this file's registry has seven entries; it still has
exactly seven after todo 9, because no batch-C migration creates a table outside the
75-table contract. `tests/Unit/Console/VerifySchemaCommandTest.php` derives that
seven from the live migration set, so the count is re-proved on every run rather
than pinned. The deferred-constraint registry was derived the same way and
re-proved on every run by `tests/Unit/Schema/SchemaDifferDeferredConstraintTest.php`
and `tests/Unit/Console/VerifySchemaDeferredConstraintTest.php`; both files are still
present but now pin the **retired** behaviour, because with the last deferral
resolved there is no registry row left for either of them to derive.

## Batch-D schema facts the database cannot enforce (todo 10)

Batch D (`faskes`, `faskes_layanan`, `master_spesialisasi`, `dokter`,
`dokter_spesialisasi`, `dokter_faskes`, `dokter_pendidikan` — SQL tables 28–34) is
at parity and **owes no deferred constraint**: all ten of its foreign keys point at
a table that already exists by the time its own migration runs
(`master_provinsi` / `master_kabupaten_kota` / `master_kecamatan` from batch A,
`users` from batch B, and `faskes` / `dokter` / `master_spesialisasi` from inside
the batch), so this batch deferred nothing of its own. Four facts are still worth carrying forward, because each looks
like something the schema guarantees and is not.

- **`pasien_penjamin.faskes_rujukan_id` remains unconstrained now that `faskes`
  exists.** Its bareness was never an ordering artefact, and after this batch that
  argument is gone entirely: `faskes` is table 28, immediately after the batch-C
  migration that created the column. Appendix A.10 / A.11 settled it and the DDL
  declares no `FOREIGN KEY` for it (`:346`); adding one now is
  `extra_foreign_key` drift. **Migration `2026_10_01_000076` exists and must not add it either.**
- **A `dokter` row may be `status_aktif = 1` *and* `status_verifikasi = 'pending'`.**
  `:427` defaults the verification status to `'pending'` and `:430` defaults
  `status_aktif` to `1`, and nothing couples them, so a freshly inserted doctor is
  active-but-unverified and "only verified doctors are listed" is an application
  rule rather than a constraint. Todo 22 encodes it in `v_dokter_katalog`
  (`:1170-1187`); any query against `dokter` directly must reproduce **both**
  predicates or it leaks unverified doctors into the public directory.
- **`master_spesialisasi.tipe` and `dokter.tipe` are different vocabularies.** The
  first is `('dokter_umum','spesialis','subspesialis')` (`:406`), the second
  `('dokter_umum','dokter_spesialis','dokter_gigi','psikolog','bidan','perawat',
  'apoteker')` (`:412`). They share exactly one member, so `'spesialis'` is **not**
  `'dokter_spesialis'` and a string comparison between the two matches nothing.
  There is no mapping table, so any translation is hand-written service-layer
  code — relevant to todo 22, which filters the directory by both `spesialisasi`
  and `tipe`.
- **`is_utama` is a flag the schema does not police.** `dokter_spesialisasi` (`:441`)
  and `dokter_faskes` (`:450`) both have `is_utama TINYINT(1) NOT NULL DEFAULT 0`,
  and the `(dokter_id, spesialisasi_id)` uniqueness on the former stops duplicate
  *rows* but nothing stops two rows of the same doctor both having `is_utama = 1`
  (or none at all). "At most one primary specialisation / facility" is therefore an
  application-level invariant, and `GET /dokter/{id}` (todo 22) has to break the
  tie itself.

The extra-table registry still has exactly seven entries after todo 10, for the
same reason as after todo 9: no batch-D migration creates a table outside the
75-table contract. The unit suite re-derives that seven on every run.

## Batch-E schema facts the database cannot enforce (todo 11)

Batch E (`dokter_jadwal`, `dokter_libur`, `booking` — SQL tables 35–37) is at
parity and **owes no deferred constraint**: all nine of its foreign keys point at a
table that already exists by the time its own migration runs (`dokter` 31 and
`faskes` 28 from batch D, `pasien` 20 and `pasien_anggota_keluarga` 21 from batch
C, `users` 12 from batch B, and `dokter_jadwal` 35 from inside this batch, one row
earlier in the same commit), so this batch deferred nothing of its own. Four facts
are still worth carrying forward,
because each looks like something the schema guarantees and is not. The two marked
**absence is load-bearing** are the ones a later reader is most likely to
"repair", and each repair would be reported as `extra_index` drift.

- **Double-booking prevention is an application lock, not a unique index.**
  `booking` (`:498-530`) has **no** unique index on `(dokter_id,
  tanggal_kunjungan, slot_mulai)`. Its only name-bearing keys are
  `idx_booking_dokter (dokter_id, tanggal_kunjungan)` (`:528`) and
  `idx_booking_pasien (pasien_id, status)` (`:529`), both plain non-unique
  `INDEX` lines, plus the inline `UNIQUE` on `nomor_booking` (`:500`) and the
  primary key. **Absence is load-bearing.** A unique index is the wrong tool
  twice over: cancellation is a `status` change and the row stays, so an
  unconditional unique would make a legitimate re-booking of a cancelled slot
  collide with the patient's own dead row; and the uniqueness that is actually
  wanted is *state-dependent* — it applies only outside the exclusion set
  `('dibatalkan','kadaluarsa')` — which MySQL cannot express, because a `UNIQUE`
  covers all rows unconditionally and MySQL has no partial index. Todo 27 must
  therefore run a `DB::transaction` that takes `lockForUpdate()` on the
  **`dokter`** row before the overlap query. The lock target is `dokter` and not
  `dokter_jadwal` because `jadwal_id` is nullable (`:504`) while `slot_mulai` and
  `slot_selesai` are `NOT NULL` (`:508-509`): an instant `chat` / `video_call`
  booking has no `dokter_jadwal` row to lock, and the `dokter` row exists for
  every doctor and is the same row for every competing booking at that instant.
- **`dokter_libur` has no unique on `(dokter_id, tanggal)`, and none is owed.**
  **Absence is load-bearing.** The whole statement is `:490-496` — seven lines
  containing one `id` column, three more columns, a single `FOREIGN KEY` line
  and the closing `) ENGINE=InnoDB;`. There is no `UNIQUE` token and no `INDEX`
  token in it, so the only index beyond the primary key is the one **MySQL
  creates itself** for the `dokter_id` foreign key. The duplicate guard is
  consequently an application-level invariant owned by `SlotAvailabilityService`
  (todo 26): a `SELECT 1 … WHERE dokter_id = ? AND tanggal = ?` existence check
  inside the writing transaction. That check-then-act is race-prone under
  concurrency, and the race is accepted rather than papered over, because a
  duplicate holiday row is **harmless to every consumer** — availability is a set
  membership test over blocked dates, which is idempotent over duplicates, and
  nothing here sums, counts or orders rows.
- **A schedule row is not self-validating.** `dokter_jadwal.hari` is
  `TINYINT UNSIGNED` (`:475`) with **no** `CHECK (hari BETWEEN 0 AND 6)`, so the
  unsigned flag rejects only negatives and `hari = 7` is representable; and
  nothing prevents two active rows for the same `(dokter_id, hari)` with
  overlapping `jam_mulai`/`jam_selesai`, or `berlaku_sampai` earlier than
  `berlaku_mulai`. `MySQL TIME` also admits values past `24:00:00`, so a slot
  wrapping midnight is representable and breaks naive `H:i:s` parsing.
  `SlotAvailabilityService` (todo 26) owns all of it. Note that `hari` maps 1:1
  onto PHP's `date('w')` (`0` Minggu = Sunday … `6` Sabtu = Saturday), so the
  named constant the plan asks for and the `docs/timezone-policy.md` entry are
  **todo 19's** work — that file does not exist in the repository yet and todo 11
  did not create it.
- **`booking`'s eight `status` values split into a two-value exclusion set and
  six live states** (`:515-516`), and the split is what every availability and
  cancellation query must use. `('dibatalkan','kadaluarsa')` release the slot; the
  other six — `menunggu_pembayaran`, `terjadwal`, `check_in`, `berlangsung`,
  `selesai`, `no_show` — occupy it. Testing `status != 'dibatalkan'` instead of
  the six-value membership test would keep `kadaluarsa` rows blocking a slot
  forever. Related and unenforced: `dibatalkan_oleh` (`:517`) and
  `alasan_pembatalan` (`:518`) are not coupled to `status = 'dibatalkan'` by any
  constraint, `nomor_antrian` (`:510`) has no per-doctor/per-day counter and no
  uniqueness, and `kuota_per_sesi` on `dokter_jadwal` (`:479`) is nullable where
  `NULL` means "no quota set" — a different statement from `0`, which means
  "block the session", and the two must not be collapsed.

The extra-table registry still has exactly seven entries after todo 11, for the
same reason as after todos 9 and 10: no batch-E migration creates a table outside
the 75-table contract. The unit suite re-derives that seven on every run.

## Batch-F schema facts the database cannot enforce (todo 12)

Batch F (`konsultasi`, `konsultasi_chat`, `surat_keterangan`, `rujukan` — SQL
tables 38–41) is at parity and **owes no deferred constraint**: all ten of its
foreign keys point at a table that already exists by the time its own migration
runs (`booking` 37 from batch E, `konsultasi` 38 from inside this batch one
migration earlier, `pasien` 20 from batch C, `dokter` 31 and `faskes` 28 from
batch D, `users` 12 from batch B, and `surat_keterangan` 40 from inside this
batch), so this batch deferred nothing of its own. Five facts are still
worth carrying forward, because each looks like something the schema guarantees
and is not. The three marked **absence is load-bearing** are the ones a later
reader is most likely to "repair", and every such repair would be reported as
`extra_foreign_key` drift while `migrate:fresh` stayed green.

- **`surat_keterangan.konsultasi_id` (`:584`) is a bare nullable unsigned
  `BIGINT` with NO foreign key, and none is owed.** **Absence is
  load-bearing.** The statement declares exactly two `FOREIGN KEY` clauses
  (`:595` on `pasien_id`, `:596` on `dokter_id`) and this column is the second
  entry of the plan's authoritative no-foreign-key list. The ordering argument
  that once justified adding it is dead: `konsultasi` is table 38, created one
  migration **before** this one, so `->foreign('konsultasi_id')->references('id')->on('konsultasi')`
  would succeed and stay green forever while being permanent
  `extra_foreign_key` drift. **Migration `2026_10_01_000076` exists and must not add it either.**
  The cost is real and is why this is written down rather than left implicit: a
  medical letter whose `konsultasi_id` points at a deleted consultation is
  representable, and — because a cascade needs a constraint — it cannot be
  repaired by cascading either. Referential integrity here is a service-layer
  invariant owned by todo 34.
- **`rujukan.faskes_asal_id` (`:602`) is a bare nullable unsigned `BIGINT` with
  NO foreign key, while `faskes_tujuan_id` on the very next line (`:603`) has
  one (`:613`).** **Absence is load-bearing, and the asymmetry is the point, not
  an oversight.** The referring facility is deliberately unconstrained; the
  receiving one is not. `faskes` is table 28 and exists, so adding a constraint
  here would be trivially possible and would be drift. A referral that originates
  outside the platform's own facility directory is a legitimate case, which is
  the likely reason the DDL leaves the *source* open while constraining the
  *destination*. The consequence is that a `faskes_asal_id` naming a facility
  this installation has never heard of is representable, so any consumer that
  wants the referring facility's name must treat the join as optional. Migration
  **Migration `2026_10_01_000076` exists** and must not add it either.
- **`konsultasi.booking_id` is `NULL UNIQUE` (`:538`), and the `NULL` half is
  load-bearing.** The `UNIQUE` is what makes "one consultation per booking" a
  database guarantee (MySQL 1062 on the second row for one booking) rather than
  a service-layer convention. The nullability is what lets the instant
  **"Tanya Dokter"** flow (24/7, no slot, no appointment) exist at all: MySQL
  permits an unlimited number of `NULL`s in a `UNIQUE` index, so the constraint
  binds exactly the rows it should and none of the rows it must not. Both halves
  are therefore required — `NOT NULL` would break the instant flow, and dropping
  the `UNIQUE` would let one booking spawn two consultations. Todo 12 proves the
  asymmetry live: two rows sharing one non-null `booking_id` raise a
  `QueryException`, three rows sharing `booking_id = NULL` all insert. The DDL's
  own comment on the column is `NULL = fitur "Tanya Dokter" instan 24 jam`.
- **`surat_keterangan.qr_token` (`:592`) is `NOT NULL` but NOT `UNIQUE`.** A QR
  verification token that two documents share is not a verification token:
  scanning either QR resolves both letters. The DDL declares no `UNIQUE` and
  adds no index, so MySQL creates none either. A unique index cannot be added
  here — that is `extra_index` drift — so the mitigation is application-level and
  belongs to todo 34: **generate the value with `Str::uuid()` at write time,
  never a guessable or sequential stored value**, and duplicate-check it at that
  point, accepting the race. The identical shape exists on `resep.qr_token`
  (`:758`) in batch H, so the same rule applies there.
- **A `sistem` chat message has no sender.** `konsultasi_chat.pengirim_user_id`
  (`:566`) is `BIGINT UNSIGNED NOT NULL` with a real foreign key to `users(id)`
  (`:577`), yet `pengirim_tipe` (`:567`) includes `'sistem'`. There is no
  nullable sender and no "no sender" representation, so a `sistem` message must
  be attributed to a designated service-user account that is created and seeded;
  the `'sistem'` value records the **role played**, not a different author. A
  reader that treats `pengirim_tipe = 'sistem'` as "no author" and then loads
  `pengirim_user_id` as a profile will get the service account. Todo 32 owns the
  service user; nothing in the schema can supply it.

Two shapes that are correct but that a later reader is likely to mistake for
mistakes, recorded so they are not "harmonised":

- **`konsultasi_chat` has neither `dibuat_at` nor `diubah_at`; its created-at
  column is `terkirim_at` (`:575`).** It is one of the 39 tables in the "neither"
  group of `docs/migration-order.md` rule 4, listed there by column name, so
  `$table->timestamps()` is wrong (it would emit `created_at`/`updated_at` and
  produce six drift rows) and so is a nullable `timestamp('terkirim_at')` (it
  would drop the `NOT NULL` and the `DEFAULT CURRENT_TIMESTAMP`). Todo 19's
  `KonsultasiChat` model needs `const CREATED_AT = 'terkirim_at';` and **no**
  `UPDATED_AT` — `$timestamps` stays `true`. That is a different case from
  `$timestamps = false`, which is what the other 38 tables in the group need.
- **`konsultasi_chat`'s foreign keys have deliberately mismatched delete rules:**
  `ON DELETE CASCADE` on `konsultasi_id` (`:576`) and nothing on
  `pengirim_user_id` (`:577`), which materialises MySQL's implicit `NO ACTION`
  (`RESTRICT` for DML). The asymmetry is the right way round — chat history is
  worthless without its consultation and goes with it, while deleting a user
  account must be blocked while their messages exist, so accounts are soft-deleted
  via `users.dihapus_at` (`:148`) and never hard-deleted. Do not give both the
  same rule.

The extra-table registry still has exactly seven entries after todo 12, for the
same reason as after todos 9, 10 and 11: no batch-F migration creates a table
outside the 75-table contract. The unit suite re-derives that seven on every run.

## Batch-G schema limitations the database cannot enforce (todo 13)

Batch G (`rekam_medis`, `rekam_medis_diagnosa`, `rekam_medis_tindakan`,
`rekam_medis_lampiran`, `rekam_medis_persetujuan` — SQL tables 42–46) is at
parity and **owes no deferred constraint**: all nine of its foreign keys point at
a table that already exists by the time its own migration runs (`pasien` 20 from
batch C, `faskes` 28 and `dokter` 31 from batch D, `konsultasi` 38 from batch F,
and `rekam_medis` 42 from inside this batch, one row earlier in the same commit),
so this batch deferred nothing of its own.
**Batch G defers nothing and registers nothing.**

Four facts are recorded because each looks like something the schema guarantees
and is not. The two marked **absence is load-bearing** are the ones a later
reader is most likely to "repair", and every such repair would be reported as
`extra_foreign_key` drift while `migrate:fresh` stayed green.

- **Amendment linkage is a convention, not a constraint — the chain is
  reconstructed by grouping, and the grouping key is itself only a convention.**
  `rekam_medis.versi` (`:646`) is `TINYINT UNSIGNED NOT NULL DEFAULT 1`, the
  amendment counter. The statement declares four `FOREIGN KEY` clauses
  (`:650`–`:653`, on `pasien_id`, `faskes_id`, `dokter_id`, `konsultasi_id`) and
  **none of them is a self-reference**: there is no `parent_id`, no
  `rekam_medis_id`, no `amends_id`, and no self-referencing `FOREIGN KEY` on
  `id`. Nothing therefore enforces, in either direction, that

  - two rows are not both `versi = 1`;
  - two rows are not both `versi = 1, status_dokumen = 'final'`;
  - a `versi = 5` has a `versi = 4`;
  - a chain is written in order.

  The chain must be reconstructed by grouping on
  `(pasien_id, dokter_id, tanggal_periksa)` — the only triple the schema holds
  constant across versions — and that grouping is **itself** unenforced: a
  doctor who examines the same patient twice on the same `tanggal_periksa` is
  representable and would be misread as a second version of the first.
  `konsultasi_id` (`:627`) is a better thread key when present because a
  consultation is one encounter, but it is nullable, so it cannot be the only
  key. **This is a limitation the database cannot enforce in any form**, and it
  is todo 33's write path that has to own the invariant; todo 19's model
  docblock repeats it. No index or constraint can be added to fix it, because
  `telemedicine_test.sql` is read-only law.
- **`status_dokumen` DEFAULTS TO `'final'` (`:645`), so a create that omits the
  column lands immutable.** `status_dokumen ENUM('draft','final','diamendemen')
  NOT NULL DEFAULT 'final'` — three values in that order, and the default is the
  **second** member, not the first. `draft` sorts first because ENUM order is the
  sort index, which is what makes the default easy to mis-transcribe.
  **Todo 33's `RekamMedisService` MUST pass `status_dokumen` explicitly on
  create.** There is no trigger, no `updated_at`-gated permission and no
  intermediate state, so a record created without the column is not recoverable
  by omitting it again — the only way forward is writing `diamendemen`. Anything
  that bulk-creates records (seeders, importers, todo 32's
  consultation-completion path) inherits the same trap. Recorded because every
  automated check in this project is green on a migration that gets this wrong:
  `migrate:fresh`, `php -l` and `verify-schema` all pass either way.
- **Four reference-shaped columns are bare by contract, and two of them are not
  named in the plan's own todo-13 prose.** **Absence is load-bearing.** All four
  targets exist, so `->foreign()` on any of them would succeed and become
  permanent `extra_foreign_key` drift:

  | Column | SQL line | Target that exists and would work | Why it is bare |
  | --- | --- | --- | --- |
  | `rekam_medis.satusehat_encounter_id` | `:628` | none | SATUSEHAT encounter id — a string minted by a national health system outside this schema. A foreign key would be meaningless even if a lookup table existed. **Not named in the plan's todo-13 text at all.** |
  | `rekam_medis_diagnosa.icd10_kode` | `:660` | `master_icd10.kode` (table 10) | Bare indexed string, `NOT NULL` with `INDEX idx_diag_icd10 (icd10_kode)` (`:666`) so the index makes lookup fast and validates nothing. An `icd10_kode` absent from `master_icd10` is representable. |
  | `rekam_medis_tindakan.icd9cm_kode` | `:672` | `master_icd9cm.kode` (table 11) | Same shape, but **nullable** (a procedure may be described in words only via `nama_tindakan`) and with **no index at all**, so a code lookup is a full scan — the one place in this batch where the rule costs a query plan as well as integrity. |
  | `rekam_medis_lampiran.diunggah_oleh` | `:687` | `users.id` (table 12) | `NOT NULL` yet unconstrained, so an attachment naming a user this installation never had is representable. `users` carries `dihapus_at` (`:148`), so the intended lifecycle is a soft delete that leaves the id resolvable — which is why the constraint is absent rather than deferred. **Not named in the plan's todo-13 text at all.** |

  **Migration `2026_10_01_000076` exists and must not add a constraint to any of the four.**
  None of them may ever be registered as deferred, because registration would
  promise a constraint the DDL never declares.
- **"Exactly one primary diagnosis per record" is unenforced, and no index can
  enforce it.** `rekam_medis_diagnosa.jenis` (`:662`) is a four-value ENUM
  (`utama`, `sekunder`, `diferensial`, `komplikasi`) with **no default**, so
  the type is required at insert, and there is **no unique index on
  `(rekam_medis_id, jenis)`** — the only name-bearing key is `idx_diag_icd10`
  (`:666`). A record may therefore carry zero, one or several `utama` rows, and
  nothing couples `tipe_kasus` (`:663`, `DEFAULT 'baru'`) to `jenis`, so a
  `diferensial` diagnosis carrying a "new case" flag is representable. Both
  invariants belong to todo 33. Related and equally unenforced: the sub-tables
  have **no `dibuat_at`/`diubah_at`** at all (they are in rule 4's 39-table
  "neither" group), so "who confirmed this diagnosis and when" is not
  recordable on this table.
- **Consent order is carried by one column, and the three `tipe` values are not
  a lifecycle.** `rekam_medis_persetujuan.tipe` (`:695`) is
  `('general_consent','persetujuan_tindakan','penolakan_tindakan')` — three
  values, `NOT NULL`, no default — and the third member is a **refusal**, not a
  later state of the same consent. There is no
  `dibatalkan_at`, no supersession column and no unique on
  `(rekam_medis_id, tipe)`, so a patient can hold both an approval and a refusal
  for the same procedure and nothing picks a winner. "The most recent row of this
  `tipe` governs" is therefore a todo-33 application rule, and it is only as
  trustworthy as `ditandatangani_at` (`:700`, `DATETIME NOT NULL`, no default,
  no trigger) — which is the **only** ordering column on the table, because
  `rekam_medis_persetujuan` has no `dibuat_at` either. A wrong value there
  reorders the patient's own consent history, and nothing anywhere records when
  the consent row was written. `ditandatangani_oleh` (`:697`) is a
  `VARCHAR(150)` **name string, not a `users` id**: a patient, guardian or carer
  may have no `users` row at all, which is also why `hubungan_dengan_pasien`
  (`:698`) is free text where `NULL` means "the patient signed themselves".
  This is separate from `persetujuan_pdp` (table 74, `:1134`), which is
  platform-level PDP consent keyed on `users` with `uq_consent` (`:1144`); the
  two spellings are easy to confuse and no constraint links them.

Two shapes that are correct but that a later reader is likely to mistake for
mistakes, recorded so they are not "harmonised":

- **The four sub-table cascades and the five `RESTRICT`s are deliberately
  mismatched.** `rekam_medis_diagnosa` (`:665`), `rekam_medis_tindakan` (`:677`),
  `rekam_medis_lampiran` (`:689`) and `rekam_medis_persetujuan` (`:701`) each
  cascade from `rekam_medis`. Every other foreign key in the batch carries **no**
  `ON DELETE` clause and so materialises MySQL's implicit `NO ACTION` (`RESTRICT`
  for DML): all four of `rekam_medis`'s own FKs (`:650`–`:653`) and
  `rekam_medis_tindakan.dokter_pelaksana_id` (`:678`). The pattern is the same as
  `konsultasi_chat` in batch F: the record link cascades, the person link
  restricts, because clinical history is worthless without its record while
  deleting a doctor must be blocked while their procedure lines exist. Giving
  them the same rule would be `extra_foreign_key` drift **and** would silently
  destroy clinical history.
- **`dibuat_at` is a `TIMESTAMP` on `rekam_medis` (`:648`) and on
  `rekam_medis_lampiran` (`:688`), while every clinical event time in this batch
  is a `DATETIME`.** `tanggal_periksa` (`:630`), `ditandatangani_at` (`:647`),
  `tanggal_tindakan` (`:675`) and `rekam_medis_persetujuan.ditandatangani_at`
  (`:700`) are all `DATETIME`, and `jadwal_kontrol` (`:644`) is a `DATE`. The
  `TIMESTAMP` columns are converted by the **server** on write and read using the
  session time zone, so this duality is `docs/timezone-policy.md`'s to cover and
  cannot be fixed in the service layer. `rekam_medis` is also one of only 16
  tables with a `dibuat_at`/`diubah_at` pair, so it is one of the few that needs
  the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER` from
  `docs/migration-order.md` rule 5.

The extra-table registry still has exactly seven entries after todo 13, for the
same reason as after todos 9, 10, 11 and 12: no batch-G migration creates a
table outside the 75-table contract. The unit suite re-derives that seven on every
run.

## Batch-H schema limitations the database cannot enforce (todo 14)

Batch H (`master_obat`, `obat_interaksi`, `resep`, `resep_item`,
`resep_verifikasi`, `pesanan_obat`, `pesanan_obat_tracking`, `apotek_stok` — SQL
tables 47–54) is at parity and **owes no deferred constraint**: all **15** of its
foreign keys point at a table that already exists by the time its own migration
runs (`pasien` 20 from batch C, `faskes` 28 and `dokter` 31 from batch D,
`users` 12 from batch B, `konsultasi` 38 and `rekam_medis` 42 from batches F
and G, and `master_obat` 47, `resep` 49 and `pesanan_obat` 52 from inside this
batch, one row earlier in the same commit), so this batch deferred nothing of its
own. **Batch H defers nothing and registers nothing.**
registers nothing.** The extra-table registry therefore still has exactly seven
entries, and the unit suite re-derives that seven on every run.

Five facts are recorded because each looks like something the schema guarantees and
is not. Every one of them is invisible to `migrate:fresh`, to `php -l` and to a
green test suite: all three of those were observed green over a mutation of the
first one during this todo's own negative QA.

- **`obat_interaksi.uq_interaksi` covers ONE DIRECTION ONLY, and the schema cannot
  stop a row being written in either direction.** `UNIQUE KEY uq_interaksi
  (obat_a_id, obat_b_id)` (`:739`) is a composite unique on an **ordered** pair, so
  MySQL treats `(1, 2)` and `(2, 1)` as two distinct rows and the only duplicate
  it rejects is a repeat of the *same* ordered pair. There is no
  `CHECK (obat_a_id < obat_b_id)`, no unique on the reversed pair and no trigger.
  **Todo 38's `ObatInteraksiService` MUST query both `(obat_a_id = :x AND
  obat_b_id = :y)` and `(obat_a_id = :y AND obat_b_id = :x)`** — a single-direction
  query returns half of the interactions that exist, with no error and no warning,
  which is a pharmacovigilance hole rather than a bug report. **Todo 46 inherits
  the same requirement**, because an interaction missed at dispensing time is an
  interaction never warned about. And **any seeder MUST write canonical pairs with
  `obat_a_id < obat_b_id`**: that is the only thing that makes a pair findable by a
  one-direction query, and it is a convention with no enforcement behind it. SQL
  section `[16]` inserts **no** `obat_interaksi` rows at all, so the first writer is
  todo 18's `DevFixtureSeeder` or todo 38's own — the convention has to be
  established there, and a mixed-direction table is undetectable by any query this
  schema supports. Do **not** "fix" this by adding a unique on the reversed pair or
  by reordering the columns: that is `extra_index` drift, and it still would not
  prevent a row with `a > b`.
- **`resep.berlaku_sampai` carries a seven-day validity as PROSE ONLY.** `berlaku_sampai
  DATE NOT NULL` (`:755`) has the DDL comment `'E-resep berlaku 7 hari'` and
  **nothing else**: no `DEFAULT`, no trigger, no generated column, no `CHECK`. MySQL
  cannot express `DEFAULT (tanggal_resep + INTERVAL 7 DAY)` at all — a column
  default must be a constant, and the expression form it does accept rejects
  another column — so the interval exists in exactly two places and this file plus
  the migration's own comment are them. **Todo 39's `ResepService` MUST write
  `berlaku_sampai` explicitly as `tanggal_resep + 7 days`**, and **todo 46's
  checkout MUST enforce it**, because nothing re-derives or re-checks it. The column
  being `NOT NULL` with no default means an omitted value is impossible and a
  *wrong* one is representable and silently changes what a pharmacist may legally
  dispense. The interval runs from **`tanggal_resep`** (`:754`, a `DATETIME` the
  prescriber supplies, which may legitimately differ from the row's insertion
  time) and **not** from `dibuat_at`.
- **`resep_item.obat_id` is NULLABLE BY DESIGN and `nama_obat` is a SNAPSHOT, so a
  prescription line is not a join to the catalogue.** `obat_id BIGINT UNSIGNED NULL`
  (`:770`) carries the comment `'NULL = racikan / obat non-katalog'`, and
  `is_racikan` (`:776`) plus `racikan_nama` (`:777`) exist precisely to describe a
  compounded preparation or a drug dispensed before it was catalogued — a
  `NOT NULL` here would make racikan literally unrepresentable.
  `nama_obat VARCHAR(255) NOT NULL` (`:771`) is an explicit snapshot of the name at
  prescribing time, `NOT NULL` **even when `obat_id IS NULL`**, which is what makes
  it a snapshot rather than a mirror. Three consequences:
  1. **Todo 39's Resource MUST return `nama_obat` and MUST NOT substitute a live
     join to `master_obat`.** A join silently rewrites historical prescriptions
     when the catalogue is renamed and returns nothing for a racikan. `obat_id` may
     be exposed as an id, never as a name source.
  2. **Todo 38's interaction engine MUST skip every row where `obat_id IS NULL` and
     MUST document that racikan are STRUCTURALLY UNCHECKABLE** — there is nothing to
     join on, so such a line is *unexamined*, not low-risk. The same structural
     reason makes allergy checking best-effort and failing open: `pasien_alergi.nama_alergen`
     (`:278`) is free text and not a foreign key to `master_obat`.
  3. Nothing keeps `nama_obat` and `master_obat.nama_generik` (`:711`) in step, and
     they are not even the same column — the catalogue also has an optional
     `nama_brand` (`:712`) — so the schema does not record which one was
     snapshotted.
- **`resep_verifikasi.resep_id` is `NOT NULL UNIQUE`, so a prescription is verified
  exactly once, ever, and a rejection is TERMINAL.** `resep_id BIGINT UNSIGNED NOT
  NULL UNIQUE` (`:788`, written inline so MySQL names the index `resep_id` and
  `SchemaDiffer` compares it by semantics rather than by name) means a second row
  for the same prescription cannot exist. **A re-verification is an `UPDATE` of the
  existing row, not an `INSERT`** — an insert-then-catch-duplicate pattern throws
  away the `catatan` the pharmacist just typed. And because the DDL records exactly
  three outcomes (`:790`, `sesuai` / `ada_koreksi` / `ditolak`) with no second row
  to move between them and no "returned for correction" member in `resep.status`
  (`:751`–`:752`), **a rejected prescription cannot be revised and re-submitted**:
  the rejection path is terminal and must be documented as such, not worked around.
  Nothing records the prior outcome and there is no `dibuat_at`/`diubah_at` on the
  table, so the same `UPDATE` also destroys the previous `diverifikasi_at` — a
  pharmacist who corrects a rejection erases when they rejected it. The only
  defensible mitigation is application-level (an audit sink such as `audit_log`,
  table 73); a history column would be `extra_column` drift.
- **`apotek_stok.jumlah_stok` and `apotek_stok.stok_minimum` are SIGNED `INT`, and
  that is what makes oversell detectable.** Both are `INT NOT NULL DEFAULT 0`
  (`:833`, `:834`) — **not** `INT UNSIGNED`, unlike `resep_item.jumlah`
  (`SMALLINT UNSIGNED`, `:774`) and `resep.jumlah_iter` (`TINYINT UNSIGNED`,
  `:757`), which are quantities that cannot be negative. There is no
  `CHECK (jumlah_stok >= 0)` and no movement ledger, so **the service layer is the
  guard** and the signedness is the audit trail for the one condition the database
  is forbidden from preventing. **Todo 46's oversell detection depends on it**: with
  `INT UNSIGNED`, MySQL 8 raises `ERROR 1690 (22003)` and **rejects** a decrement
  below zero instead of storing it, so the oversell surfaces as a driver error,
  never reaches a row, and never appears in a low-stock report or an audit. With a
  signed `INT` the decrement succeeds, the row goes negative, and
  `WHERE jumlah_stok < 0` is a complete oversell report. **Writing
  `unsignedInteger()` here is a parity break that silently destroys that
  detection** — and it is invisible to every other check in this project, which is
  exactly what todo 14's negative QA demonstrated (below).

Two reference-shaped columns are bare by contract, and **both are absent from the
plan's own todo-14 prose**, which is how an invented constraint gets written.
**Absence is load-bearing.** `konsultasi` is table 38 and `rekam_medis` is table 42,
so `->foreign()` on either would succeed and become permanent
`extra_foreign_key` drift — and `migrate:fresh` stays green while it does.

| Column | SQL line | Target that exists and would work | Why it is bare |
| --- | --- | --- | --- |
| `resep.konsultasi_id` | `:745` | `konsultasi.id` (table 38) | Provenance, not a lookup: a `tipe = 'manual'` or walk-in prescription has no consultation, which is why the column is nullable. **Not named in the plan's todo-14 text at all.** |
| `resep.rekam_medis_id` | `:746` | `rekam_medis.id` (table 42) | Same reason: the prescription may be issued without a medical record. **Not named in the plan's todo-14 text at all.** |

**The asymmetry inside one table is deliberate and must not be "harmonised".**
`resep` declares exactly three `FOREIGN KEY` clauses — on `pasien_id` (`:761`),
`dokter_id` (`:762`) and `apotek_id` (`:763`) — and those three are the columns the
prescription's *validity* depends on: a prescription must name a patient, a
prescriber and (optionally) a dispensing pharmacy. The two bare columns are
**provenance**: which consultation and which record it came from, both optional.
Verified by grepping every `FOREIGN KEY` line in the file and, at runtime, by
`information_schema.REFERENTIAL_CONSTRAINTS` — `resep` has foreign keys on
`apotek_id`, `dokter_id` and `pasien_id` and **zero** on `konsultasi_id` and
`rekam_medis_id`. Contrast `pesanan_obat.resep_id` (`:800`), which **does** carry a
real foreign key (`:814`): one `resep_id` column being constrained says nothing
about the other. **Migration `2026_10_01_000076` exists and must not add a
constraint to either bare column**, and neither may ever be registered as deferred,
because registration would promise a constraint the DDL never declares.

Three shapes that are correct but that a later reader is likely to mistake for
mistakes, recorded so they are not "harmonised":

- **The four cascades and the eleven `RESTRICT`s are deliberately mismatched.** The
  cascades are `obat_interaksi.obat_a_id` (`:737`), `obat_interaksi.obat_b_id`
  (`:738`), `resep_item.resep_id` (`:781`) and `pesanan_obat_tracking.pesanan_obat_id`
  (`:826`); the other eleven carry **no** `ON DELETE` clause and so materialise
  MySQL's implicit `NO ACTION` (`RESTRICT` for DML). The pattern is the one batches
  F and G already established: the *record* link cascades, the *person* or
  *catalogue* link restricts. An interaction row and a parcel trail are worthless
  without their parent, while deleting a drug must be blocked while prescription
  lines reference it and deleting a prescription must be blocked while a
  pharmacist's verification exists, because that verification is the legal evidence
  required by `:785` (*"Wajib secara hukum: e-resep diverifikasi apoteker sebelum
  dipenuhi"*).
- **`master_obat`'s three ENUMs are NOT contiguous, and the middle one is a plain
  `VARCHAR`.** In declaration order they are `bentuk_sediaan` (`:713`–`:714`, twelve
  values), `satuan` (`:716`, eight values) and `kelas_obat` (`:719`, six values) —
  and **`kelas_terapi VARCHAR(100) NULL` sits at `:718`, between the second and the
  third**. It is a real column and not part of any value list. The three lists are
  also semantically unrelated: dosage **form**, dispensing **unit** and legal
  **control class**, and `tablet`/`kapsul` are members of *both* the first and the
  second, which is precisely why conflating them corrupts a catalogue rather than
  merely mislabelling a row. `kelas_terapi` is free text — the DDL comment
  `'Antibiotik, Analgetik, dll'` is an example, not an enumeration, and the seed at
  `:1311`–`:1313` duly stores `Analgetik-Antipiretik`, `Antibiotik` and
  `Antihistamin`. The plan's todo-14 prose previously claimed all three ENUMs sat
  in `:711-717`; an executor trusting that range would have shipped
  `missing_column` drift here.
- **`pesanan_obat_tracking.status` is a `VARCHAR(100)` and shares a NAME with an
  ENUM.** `pesanan_obat.status` (`:810`–`:811`) is a six-value ENUM;
  `pesanan_obat_tracking.status` (`:822`) is unconstrained free text, so the order's
  state machine is closed and the parcel trail's is **open**. Any string is
  insertable into the tracking table — a typo, a courier's own vocabulary such as
  `in_transit`, or an order-state name carrying a different meaning — and a
  `dibatalkan` order beside a `selesai` tracking row is perfectly representable.
  **Todo 46 must validate this column in the application and must read the order's
  state from `pesanan_obat.status`, never from here.** Converting it to an enum
  would be `column_type` drift against a read-only contract. Related: that table has
  no `dibuat_at`, and `waktu DATETIME NOT NULL` (`:825`) is its de-facto created-at,
  so todo 19's model needs `const CREATED_AT = 'waktu'` — the same shape as
  `konsultasi_chat`'s `terkirim_at` (`:575`).

Two more facts about this batch that no index or constraint can fix:

- **`pesanan_obat` has NO line items, so an over-the-counter order has nowhere to
  record what was bought.** `resep_id` is nullable (`:800`) and there is **no
  `pesanan_obat_item` table anywhere in the 75**, so a `tipe = 'obat_bebas'` or
  `'produk_kesehatan'` order has no product lines and `subtotal` cannot be
  recomputed from them. **Todo 46 must restrict the order flow to `tipe =
  'resep_dokter'`**, where the prescription's own `resep_item` rows supply the
  products, and record the limitation. Related and unenforced: nothing checks
  `total = subtotal + biaya_kirim` (`:807`–`:809`), nor
  `resep_item.subtotal = harga_satuan * jumlah` (`:778`–`:779`) — no `CHECK`, no
  generated column, no trigger — and all five money columns default to `0`, which
  means "not priced" or "not yet calculated" and is indistinguishable from free.
- **Pharmacy identity is unconstrained, and lot identity is unrepresentable.** All
  three pharmacy columns point at `faskes(id)` — `resep.apotek_id` (`:749`),
  `apotek_stok.apotek_id` (`:831`) and `pesanan_obat.apotek_id` (`:802`) — and
  nothing constrains `faskes.tipe` to `'apotek'` (`:365`), so a hospital is
  representable as the dispensing or fulfilling pharmacy and the application must
  validate it. And `UNIQUE KEY uq_stok (apotek_id, obat_id)` (`:840`) is the pair
  alone, so **a second batch of the same drug with a different `kedaluwarsa` cannot
  be represented at all**: one pharmacy has exactly one row per drug, re-stocking
  overwrites quantity and date in place, and with no movement ledger the lot history
  is not recoverable from this table. Note also that this unique covers only **one**
  of the two foreign-key columns — its leftmost is `apotek_id`, so `obat_id` is not
  a leftmost prefix of anything and MySQL builds an implicit support index that
  `SHOW CREATE TABLE` prints as `KEY apotek_stok_obat_id_foreign (obat_id)`. That
  index is not in the DDL and `SchemaDiffer::diffIndexes()` treats it as implied by
  the matched foreign key (commit `27c6ca8`) rather than as `extra_index` drift, so
  the table reaches `Discrepancies: 0` with it present and it must not be "removed"
  by adding a covering index of one's own.

### What the negative QA in todo 14 measured, and what it means for later batches

Two mutations were applied, verified and reverted byte-identically. Both are
recorded because each is a defect that **no other check in this project would
catch**, and both confirm the verifier is stricter than it looks:

| Mutation | `php -l` | `migrate:fresh` | `verify-schema` | Verdict |
| --- | --- | --- | --- | --- |
| `apotek_stok.jumlah_stok` → `unsignedInteger()` | exit 0 | exit 0 | **exit 1**, `column_unsigned apotek_stok.jumlah_stok expected: signed \| actual: unsigned` | **caught** |
| `master_obat.kelas_obat` members 3 and 4 swapped, **membership unchanged** | exit 0 | exit 0 | **exit 1**, `column_type master_obat.kelas_obat` with both full value lists printed | **caught** |

The second row answers a question future batches depend on: **the verifier compares
an `ENUM` as a SEQUENCE, not as a set.** `TypeNormaliser::type()` builds the
canonical string `enum('a','b','c')` by reading the member list verbatim and never
sorts it, so both a wrong letter and a wrong position are reported as `column_type`.
Reordering an ENUM is therefore not a cosmetic change and not a safe
"harmonisation" — it changes the sort index, which is what `ORDER BY` and
`MIN()`/`MAX()` on that column mean.

The first row is the sharper lesson. A signedness inversion on a stock column still
builds, still lints, still migrates and still leaves the whole unit suite green;
only `column_unsigned` sees it. That is the same failure mode as todo 10's five
hidden comment defects and todo 13's self-inflicted corruptions, and it is why the
signedness is written into migration `2026_10_01_000054`'s own comment as well as
here.

### Two defects in this project's own documents, found by reading rather than by trusting

- **`pesanan_obat.status` is a SEVENTH multi-line ENUM, and the plan's list of six
  omits it.** The six values fit on `:810` and the `NOT NULL DEFAULT
  'menunggu_pembayaran'` tail is on `:811`. The plan's "Multi-line ENUMs — treat as
  single units" list names six (`booking.status` 515-516, `konsultasi.status`
  542-543, `konsultasi_chat.tipe_pesan` 568-569, `master_obat.bentuk_sediaan`
  713-714, `resep.status` 751-752, `persetujuan_pdp.jenis` 1137-1138) and
  `docs/migration-order.md` rule 6 carries the same six. Reading only `:810` yields
  the values with an **empty tail** — a nullable column with no default, i.e.
  `column_nullable` plus `column_default` drift and a `NOT NULL` column silently
  made nullable. The plan's *parser* is right, incidentally: the verifier's derived
  "wrapped decls 11" list **does** include `pesanan_obat.status (810-811)`, so the
  defect is in the prose list and in the hand-written rule, not in the tooling. Both
  documents are contract- or orchestrator-owned and were **not** edited by todo 14.
- **Five of the plan's todo-14 inline `:NNN` citations are wrong, and its own
  line-index table has all five right** — the A.16 pattern for the fifth
  consecutive batch. `INDEX idx_obat_nama` is at `:728`, not `:727` (which is
  `diubah_at`); `UNIQUE KEY uq_interaksi` at `:739`, not `:738` (which is the
  `obat_b_id` foreign key); `resep.berlaku_sampai` at `:755`, not `:761` (which is
  the `pasien_id` foreign key); `INDEX idx_resep_pasien` at `:764`, not `:765`
  (which is the statement's closing `) ENGINE=InnoDB;`); and `UNIQUE KEY uq_stok` at
  `:840`, not `:845` (which is the section banner between `[9] RESEP & FARMASI` and
  `[10] LABORATORIUM` — `master_lab_tindakan` has no `harga_jual` column at all; its
  money column is `harga DECIMAL(12,2)` at `:856`). Every citation in the eight
  migrations was therefore resolved by searching `telemedicine_test.sql` for the
  column, index or constraint **name**, and 234 citations across 119 distinct lines
  were printed and checked against the claims they support.


## Batch-I schema limitations the database cannot enforce (todo 15)

Batch I (`master_lab_tindakan`, `master_lab_paket`, `lab_paket_item`,
`lab_permintaan`, `lab_permintaan_detail`, `lab_hasil` — SQL tables 55-60) is at
parity and **owes no deferred constraint**. All **ten** of its foreign keys point at
a table that already exists when the migration declaring them runs: `pasien` 20
(batch C), `faskes` 28 and `dokter` 31 (batch D), and `master_lab_tindakan` 55,
`master_lab_paket` 56 and `lab_permintaan` 58 — **three of them from inside this same
batch**, earlier in the same commit, so this batch deferred nothing of its own.
**Batch I defers nothing and registers nothing.**
registers nothing.** The extra-table registry still has exactly seven entries.

**All six tables are module-orphaned — migrated and modelled for referential
completeness, never exercised by Modules 1-5.** `docs/migration-order.md` rows
55-60 all read `Module: ORPHAN`, `Resource: —`, `Controller: —`, and no endpoint in
the plan's scope selects from any of them. They are nonetheless *referenced*, which
is the whole reason they must exist rather than be omitted:

| Referencing site | SQL line | How it points at this batch |
| --- | --- | --- |
| `invoice.referensi_tipe` includes `'lab_permintaan'` | `:940` | A **polymorphic string**, not a join — `invoice.referensi_id` has no foreign key by design, so nothing validates the pairing |
| `notifikasi.tipe` includes `'lab'` | `:1041` | A category label on a notification row |
| `rekam_medis_lampiran.tipe` includes `'hasil_lab'` | `:686` | A document category; **nothing joins it to `lab_hasil`**, and `lab_hasil.file_pdf_url` (`:916`) is an independent bare URL |

### Three reference-shaped columns that are **BARE by contract**, and two of them are absent from the plan's own todo-15 text

`lab_permintaan` declares **exactly three** `FOREIGN KEY` clauses (`:889`-`:891`) and
`lab_hasil` **exactly two** (`:917`-`:918`). Three further columns read exactly like
references and have **none**:

| Column | SQL line | A constraint here would | Why it is bare |
| --- | --- | --- | --- |
| `lab_permintaan.rekam_medis_id` | **`:879`** | **succeed** (`rekam_medis` is table 42) | Provenance, not a lookup. Nullable because a request may have no medical record at all — a walk-in patient at the laboratory. **Not named in the plan's todo-15 prose at all.** |
| `lab_permintaan.konsultasi_id` | **`:880`** | **succeed** (`konsultasi` is table 38) | Same reason: the ordering doctor may never have held a teleconsultation. **Not named in the plan's todo-15 prose at all.** |
| `lab_hasil.diperiksa_oleh` | **`:914`** | **succeed** (`users` is table 12) | A verifying pathologist at the `faskes` running the test need not have a platform account, so a foreign key would make a legitimate external verifier unrepresentable. Same class as `audit_log.user_id` (`:1120`) and `artikel.reviewer_user_id` (`:1078`). **In the plan's generated no-FK list, absent from its todo-15 prose.** |

**All three were proven FK-free against `information_schema.REFERENTIAL_CONSTRAINTS`,
not by reading `SHOW CREATE TABLE`** — `SHOW CREATE TABLE` only shows the
constraints that exist, so it cannot distinguish "absent" from "not looked for". A
column with zero foreign keys **does not appear in that result set at all**, so
"no row returned" is the expected evidence and a query returning nothing is a pass,
not a failed lookup. Each column was separately confirmed to *exist* (as
`bigint unsigned`, nullable) so that "no row" cannot be confused with "no column".

**The asymmetry inside `lab_permintaan` is deliberate and must not be
"harmonised".** The three constrained columns — `pasien_id` (`:881`), `dokter_id`
(`:882`) and `faskes_lab_id` (`:883`) — are the ones the request's *validity*
depends on: a request must name a patient and an ordering doctor, and optionally the
facility that will run the test. The two bare columns are **provenance**, and both
the facility that will run the test. The two bare columns are **provenance**, and
both are nullable precisely because a request can have neither. **Migration
`2026_10_01_000076` exists and must not add a constraint to any of the three**, and
none of them may ever be registered as deferred, because a registry row would
promise a constraint the DDL never declares.

### `lab_paket_item` has **NO `id` COLUMN** — it is a composite-PK join table

`paket_id` and `tindakan_id` (`:869`-`:870`) with `PRIMARY KEY (paket_id,
tindakan_id)` (`:871`) and **nothing else**: no surrogate key, no timestamps, no
price, no ordering column. This is one of exactly four such tables in the contract
(`role_permissions` 15, `user_roles` 16, `dokter_faskes` 33, `lab_paket_item` 57) and
rule 3 of `docs/migration-order.md` names all four. **`$table->id()` here would be
wrong twice over** — it emits `BIGINT UNSIGNED` *and* creates a column the DDL does
not have — and todo 19's model needs `public $incrementing = false`, a two-element
`protected $primaryKey` and `public $timestamps = false`.

**The primary key's COLUMN ORDER is load-bearing.** `paket_id` is the leftmost
prefix, so the PK doubles as InnoDB's support index for that foreign key and MySQL
creates no second index for it; `tindakan_id` is not a leftmost prefix of anything,
so InnoDB builds an implicit one that `SHOW CREATE TABLE` prints as
``KEY `lab_paket_item_tindakan_id_foreign` (`tindakan_id`)``. No index beyond
the PK is in the DDL, and `SchemaDiffer::diffIndexes()` treats a leftover live index
whose ordered column list exactly equals a *matched* foreign key's local columns as
implied rather than as `extra_index` drift (commit `27c6ca8`). **Do not suppress
them by adding covering indexes of our own** — that creates a real extra index. The
same applies to `lab_permintaan` (3 implicit), `lab_permintaan_detail` (3) and
`lab_hasil` (2), because `PRIMARY KEY (id)` covers none of their foreign keys.

### `lab_paket_item` has **NO SEED ROWS**, and that is not a defect to repair

`telemedicine_test.sql` contains **no `INSERT INTO lab_paket_item` anywhere in the
file** — verified by enumerating every `INSERT INTO <table>` target in the whole
file, which yields exactly 15 and includes `master_lab_tindakan` (`:1319`) and
`master_lab_paket` (`:1332`) but not this one. So the three seeded packages
(`:1333`-`:1335`) have **no** line items. Consequences, all unenforced:

- **No seeded package has any contents**, so `master_lab_paket.harga` (`:864`) is a
  price with nothing to be checked against and every bundle reads as opaque.
- **Nothing derives a package price from its contents.** `lab_paket_item` has no
  price column at all, so `('Medical Check Up Dasar', …, 120000.00)` (`:1333`) is an
  independent fact, not a sum — `LAB-001` Hemoglobin is `35000.00` (`:1321`) and
  `LAB-009` Urinalisa is `50000.00` (`:1329`).

**A migration must not insert bridge rows**: that would invent data the SQL does not
contain and break the 1:1 fidelity claim. **Todo 18 owns this** — it authors a
`DevFixtureSeeder`, explicitly labelled as new data with no source in the SQL and
held outside that claim, exactly as it must for `obat_interaksi`.

### Five facts recorded because each looks like something the schema guarantees and is not

Each is invisible to `migrate:fresh`, to `php -l` and to a green test suite.

- **`lab_permintaan_detail` cannot say whether a line is a test, a package, both, or
  neither.** `tindakan_id` (`:897`) and `paket_id` (`:898`) are **both** nullable
  **and both** carry a real foreign key (`:901`, `:902`). There is **no** `CHECK
  ((tindakan_id IS NULL) <> (paket_id IS NULL))`, no trigger and no default for
  either, so three shapes are all perfectly valid and all representable: a line
  naming **both** a single test and a whole package (a service reading
  `tindakan_id` first and `paket_id` second silently discards the package it just
  found), a line naming **neither**, and a `prioritas = 'cito'` (`:899`) line that
  escalates a whole package at once. **The application layer is the only validator
  of that invariant**, and `lab_paket_item` does not help: it describes packages, not
  request lines. A `CHECK` cannot be added without being `missing_check` drift.
- **`lab_hasil.nilai VARCHAR(100) NOT NULL` (`:909`) is a STRING, and three separate
  things depend on it.** `master_lab_tindakan.nilai_rujukan_laki` (`:853`) is itself
  `VARCHAR(100)` free text — the seed stores `13.0-17.0` (`:1321`), `<200` (`:1324`),
  `negatif` (`:1330`) and `NULL` (`:1329`, `LAB-009`, which has no sex-specific
  interval) — and a purely qualitative result such as `positive` or `not detected`
  has no numeric representation at all, so one column holds both kinds of result.
  And
  **`is_abnormal` (`:912`) is a STORED flag with no trigger and no generated
  column**: nothing in the database compares `nilai` with `nilai_rujukan`, so the
  writer's assertion *is* the fact. Emitting a numeric type here is `column_type`
  drift and is the plan's own acceptance criterion for this todo.
- **Two `DEFAULT`s in this batch have OPPOSITE polarity, and both are easy to
  invert.** `master_lab_tindakan.status_aktif` (`:857`) and
  `master_lab_paket.status_aktif` (`:865`) are `TINYINT(1) NOT NULL DEFAULT **1**` —
  an omitted value is **ACTIVE**, and both seeds omit the column (`:1319`-`:1320`,
  `:1332`), so all 13 seeded rows are active. `lab_hasil.is_abnormal` (`:912`) is
  `TINYINT(1) NOT NULL DEFAULT **0**` — an omitted value is **NORMAL**. A result is
  normal unless someone says otherwise; a test is active unless someone says
  otherwise. This is the same defect class as todo 10's `dokter_faskes` blocker,
  where a docblock claimed `0` while the code and the SQL both said `1`.
- **`lab_permintaan.faskes_lab_id` points at `faskes(id)` and nothing constrains the
  facility type.** `FOREIGN KEY (faskes_lab_id) REFERENCES faskes(id)` (`:891`) and
  `faskes.tipe` is an unconstrained five-value ENUM (`:365`:
  `rumah_sakit, klinik, puskesmas, apotek, laboratorium`). **A hospital or a
  pharmacy is therefore representable as the facility running a laboratory test,**
  and the application must validate the type. This is the **fourth** unconstrained
  facility-identity column in the schema, after the three pharmacy ones batch H
  recorded (`resep.apotek_id` `:749`, `pesanan_obat.apotek_id` `:802`,
  `apotek_stok.apotek_id` `:831`) — and the first on the laboratory side.
- **A laboratory result is clinical evidence that is destroyed with its request.**
  `lab_hasil.lab_permintaan_id` is `ON DELETE CASCADE` (`:917`), so deleting a
  `lab_permintaan` deletes its results and takes `file_pdf_url` (`:916`) with them.
  The same defect class the plan already records for
  `akses_rekam_medis_log.rekam_medis_id ON DELETE CASCADE` (`:1153`), where
  "deleting a medical record deletes the evidence that it was accessed". Weaker
  here — a result is not a log — and **not** a reason to alter the schema, but a
  service that must retain released results has to copy them out first and nothing
  here prevents or warns about it.

### The cascades and the restricts are deliberately mismatched, in a consistent pattern

**Four cascades, six restricts, across ten foreign keys.** The cascades are
`lab_paket_item.paket_id` (`:872`), `lab_permintaan_detail.lab_permintaan_id`
(`:900`) and `lab_hasil.lab_permintaan_id` (`:917`) — plus
`master_lab_paket`-side parentage, i.e. every link from a *bundle or a request* to
what it contains. The other six carry **no** `ON DELETE` clause and so materialise
MySQL's implicit `NO ACTION` (`RESTRICT` for DML). The reading is the one batches F,
G and H already established: **the *record* link cascades, the *catalogue* or
*person* link restricts.** A line without its request is worthless; but deleting a
test must be blocked while any package or result still names it, because a bundle
whose price was derived from that test would silently change composition.

### Two things a later reader is likely to mistake for mistakes

- **`lab_permintaan.status` is a FIVE-value ENUM that WRAPS ACROSS TWO LINES**
  (`:884`-`:885`). Read `:884` alone and the value list is complete but the column
  looks **nullable with no default** — `column_nullable` plus `column_default`
  drift, and a `NOT NULL` column silently made nullable. The plan's "Multi-line
  ENUMs — treat as single units" list names six and **omits this one**, as it also
  omits four others. Measured by walking the file for every declaration spanning
  more than one physical line, there are **eleven**: `booking.status` (515-516),
  `konsultasi.status` (542-543), `konsultasi_chat.tipe_pesan` (568-569),
  `master_obat.bentuk_sediaan` (713-714), `resep.status` (751-752),
  `pesanan_obat.status` (810-811, found by todo 14), **`lab_permintaan.status`
  (884-885)**, `invoice.status` (947-948), `klaim_bpjs.status` (1022-1023),
  `home_care_pesanan.status` (1104-1105) and `persetujuan_pdp.jenis` (1137-1138).
  The verifier's own derived "wrapped decls 11" list agrees with the measurement and
  is right, so **the defect is in the plan's prose list and in rule 6 of
  `docs/migration-order.md`, not in the tooling.** Both are orchestrator- or
  contract-owned and were **not** edited by todo 15.
- **Every index in this batch is compared BY SEMANTICS, never by name.** The DDL
  names **no** index and **no** constraint anywhere in these six tables — there is
  not one `idx_*` or `uq_*` between `:847` and `:919`. The only uniques are the two
  *inline* `UNIQUE`s on `master_lab_tindakan.kode` (`:849`) and
  `lab_permintaan.nomor_permintaan` (`:878`), so rule 10's name comparison never
  fires and MySQL's `kode` / Laravel's `master_lab_tindakan_kode_unique` are the
  same constraint. **Do not rename either to `uq_kode`** to imitate `apotek_stok`'s
  `uq_stok` (`:840`): a name the DDL never wrote is not part of the contract.
  `lab_paket_item`'s composite PK is the one name-authoritative index in the batch,
  and its column order is the contract.

### What todo 15's negative QA measured, and the one check this project's safety rests on

Three mutations were applied, verified and reverted byte-identically (SHA-256 proved
equal before and after in every case). They are recorded because the first two are
defects **no other check in this project would catch**.

| Mutation | `php -l` | `migrate:fresh` | unit suite | `verify-schema` | Verdict |
| --- | --- | --- | --- | --- | --- |
| `lab_hasil.satuan` → `NOT NULL` (dropped `->nullable()`) | exit 0 | exit 0 | **93/93 green** | **exit 1**, `column_nullable lab_hasil.satuan expected: NULL \| actual: NOT NULL` | **caught** |
| **`lab_hasil.file_pdf_url`'s declaration swallowed by a `//` comment** | exit 0 | exit 0 | **93/93 green** | **exit 1**, `missing_column lab_hasil.file_pdf_url expected: varchar(500) NULL DEFAULT <none> \| actual: -` | **caught** |

The second row is the important one, and it is the defect class todo 14 disclosed
about itself. **A column declaration sitting inside a comment is not a syntax error
and not a migration failure** — the table is created, smaller, and entirely valid, so
`php -l` exits 0, `migrate:fresh` exits 0, and **all 93 unit tests stay green**,
because the suite derives its expectations from the migration files rather than
diffing the schema. **Only `sehatly:verify-schema` sees it**, and it saw it in
0.3 s. A static check also catches it without a database round-trip: stripping
comments with PHP's own `token_get_all` and counting `$table->…('col')` calls gives
**10** executable declarations against the DDL's **11**.

**The generalisation, and it is the most important sentence in this section: in this
project a green build, a green `migrate:fresh` and a green test suite together prove
almost nothing about schema correctness.** All three were observed green over a wrong
`DEFAULT`, a wrong unsigned flag, a duplicated column, a **swallowed column
declaration**, a wrong ENUM value, four corruptions of a single ENUM value, a
fabricated table name and a fabricated finding. The parity verifier is the only check
in the loop that sees a column that is *absent*, and the comment audit is the only
one that sees a comment that is *wrong*.

### Findings about this project's own documents, found by reading rather than by trusting

- **The plan's todo-15 prose cites `invoice.referensi_tipe` at `:941`; it is at
  `:940`.** `:941` is `referensi_id BIGINT UNSIGNED NOT NULL COMMENT 'Polimorfik'`.
  Seventh consecutive batch to find a wrong inline `:NNN` in this plan's prose, and
  — as in todos 10 through 14 — the *line-index table* in `docs/migration-order.md`
  rows 55-60 carries the **correct** line for all six of this batch's tables. The
  migration cites `:940`.
- **The plan's "Multi-line ENUMs" list is five entries short** (eleven exist, listed
  above). The list in the plan and rule 6 of `docs/migration-order.md` both carry
  the same six, and todo 14's entry in this file already recorded a seventh while
  asserting the list was otherwise complete. It was not. This is A.19's lesson
  again: a count in this plan is not evidence, and so is a claim that a list is
  complete.
- **The plan's todo-15 line-index table has no row for any of the six laboratory
  tables at all** — it jumps from `apotek_stok` (`:829`/`:833`/`:834`) to
  `master_metode_pembayaran` (`:925`). Every citation in these six migrations was
  therefore resolved by searching `telemedicine_test.sql` for the column, index or
  constraint **name**, and **257 citation tokens across 95 distinct SQL lines** were
  printed and read against the claims they support.
- **One defect in this project's own documents was left in place deliberately.** The
  brief for this todo spelled the sixth table `master_lab_tindres` and the
  `verify-schema` scope argument `lab_permVirus_detail`. Both are transcription
  errors, and **the misspelled scope argument was run deliberately as a control**:
  `--tables=master_lab_tindres` exits **0** with a single
  `unknown_requested_table` informational row and a **green "PASS — 75 tables, 2
  views verified" banner**, having verified nothing at all. That is A.8's second
  false-green trap demonstrated end-to-end, and it is why the real run's `scope` line
  was read rather than its exit code.

## Batch-J schema limitations the database cannot enforce (todo 16)

Batch J (`master_metode_pembayaran`, `invoice`, `pembayaran`, `refund`,
`master_promo`, `promo_redemption`, `klaim_bpjs` — SQL tables 61-67) is at parity
and **owes no deferred constraint**. All **seven** of its foreign keys point at a
table that already exists when the migration declaring them runs: `pasien` 20
(batch C), and `master_metode_pembayaran` 61, `invoice` 62, `pembayaran` 63 and
`master_promo` 65 — **four of them from inside this same batch**, earlier in the
same commit. This batch therefore deferred nothing of its own. **Batch J defers nothing and registers nothing.** The
extra-table registry still has exactly seven entries, so the derived
`schema-notes.md` contract in the unit suite is unchanged.

**Batch J contains no `ON DELETE` clause at all.** Measured by scanning
`telemedicine_test.sql:921-1034` (SQL section `[11]`, the invoicing/payment/promo
section in full): **zero** `ON DELETE` and **zero** `ON UPDATE` inside a
`FOREIGN KEY` clause. The only two `ON UPDATE` occurrences in the range are the
`diubah_at` column definitions at `:952` and `:1028`. All seven constraints
therefore materialise MySQL's implicit `NO ACTION`, which `information_schema`
reports verbatim — a payment still named by a refund cannot be deleted, an
invoice still named by a payment cannot be deleted, and a patient still named by
an invoice **or a promo redemption** cannot be deleted. That is coherent for
financial records and it is the DDL's choice, not one made here. It is also the
reason `master_promo` and `master_metode_pembayaran` must be retired via
`status_aktif` rather than deleted, and the plan should read it that way: a
"cleanup" DELETE on either master row is blocked by these seven constraints.

### `invoice` is a polymorphic hub and `referensi_id` is **BARE by contract**

`referensi_tipe` (`:940`, six values) and `referensi_id BIGINT UNSIGNED NOT NULL
COMMENT 'Polimorfik'` (`:941`) are a **discriminated union**, and
`invoice.referensi_id` is the twenty-fifth entry in the plan's generated
reference-shaped-but-no-FK list. **No foreign key is possible and none exists.** A
foreign key names exactly one parent table; this column's parent is a *function*
of `referensi_tipe`, so the constraint is not expressible at all. All six targets
declare `id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` — `booking` (`:499`),
`konsultasi` (`:537`), `resep` (`:743`), `pesanan_obat` (`:798`),
`lab_permintaan` (`:877`), `home_care_pesanan` (`:1094`) — which is exactly what
lets one column hold any of them, and a matching width is **not** evidence that a
constraint belongs here.

**Ownership is a service-layer responsibility and the database checks nothing.**
Nothing verifies that `referensi_id` exists *in the table named by
`referensi_tipe`*, and nothing verifies that `promo_redemption.pasien_id` equals
`invoice.pasien_id` for the invoice that row names. Both are application
invariants. Proved FK-free against `information_schema.REFERENTIAL_CONSTRAINTS`
joined to `KEY_COLUMN_USAGE` — **not** by reading `SHOW CREATE TABLE`, which only
shows constraints that exist and so cannot distinguish "absent" from "not looked
for" — with the column separately confirmed to exist as `bigint unsigned NOT NULL`.

`INDEX idx_ref (referensi_tipe, referensi_id)` (`:955`) is the only index that
makes the polymorphism affordable, and **its column order is part of the contract**
(`referensi_tipe` first). The negative QA in this todo's evidence file measured
what happens when it is removed: `migrate:fresh` exits 0, the 93-test unit suite
stays 93/93, and **only** `verify-schema` fails, naming `missing_index invoice
expected: idx_ref`. An index removal reduces query performance and never
correctness, so no functional test in this project can catch it.

### Only **4 of the 6** `referensi_tipe` values are reachable in Modules 1-5

This is a fact about the **application**, not about the DDL, which is why it
belongs here and not in a migration comment (a comment implying the SQL says it
would be a false claim). `docs/migration-order.md` gives the derivation:

| `referensi_tipe` value | Parent table | Contract row | Module | Reachable in M1-M5 |
| --- | --- | --- | --- | --- |
| `booking` | `booking` | 37 | M2 booking | **yes** |
| `konsultasi` | `konsultasi` | 38 | M3 consultation | **yes** |
| `resep` | `resep` | 49 | M4 pharmacy | **yes** |
| `pesanan_obat` | `pesanan_obat` | 52 | M5 order | **yes** |
| `lab_permintaan` | `lab_permintaan` | 58 | **ORPHAN**, Resource —, Controller — | **no** |
| `home_care` | `home_care_pesanan` | 72 | **ORPHAN**, Resource —, Controller — | **no** |

The two unreachable values are unreachable for the same reason batch I's six
tables are: they are migrated for referential completeness and no endpoint in the
plan's scope creates them. **The ENUM still permits both**, so a row naming
`referensi_tipe = 'lab_permintaan'` is representable and would point at a table
nothing else can reach — another reason ownership is the service's job. Todo 45
must not treat the six-value list as six usable options.

### Webhook idempotency has no dedupe key, and this batch does not add one

`pembayaran.nomor_referensi` is `VARCHAR(100) NULL COMMENT 'Transaction ID
payment gateway'` (`:963`) with **no `UNIQUE` and no index of any kind**. Measured
live: **0** rows in `information_schema.STATISTICS` name this column on either
database. The only non-primary index in the DDL is
`idx_bayar_status (status, dibayar_at)` (`:972`), which cannot serve a lookup that
knows only the transaction id, and there is no `webhook_event_id` column to index
instead.

**No index was added, because `telemedicine_test.sql` is read-only law** and an
added index is `extra_index` drift (`docs/migration-order.md` rule 7). The
trade-off, stated so it is not rediscovered as a defect:

- **Correctness** is unaffected. The idempotency check is a
  `where nomor_referensi = ?` existence query (with the `gateway` predicate) run
  inside the update transaction. A missing index changes how long the answer
  takes, not what it is.
- **Cost.** Every gateway callback is a full table scan of `pembayaran`, growing
  linearly with lifetime order volume, and under `REPEATABLE READ` the next-key
  locks a full scan takes widen the callback's write contention.
- **Uniqueness is NOT database-enforced.** A duplicate `nomor_referensi` is
  representable and two concurrent callbacks for one transaction can both pass the
  check before either commits. **Todo 45's webhook handler must be idempotent in
  its side effects**, not merely guarded by the check.
- **NULL is the normal case** for `cod` and `tunai`, which have no gateway at all,
  so a lookup must never assume the column is populated and must not treat a NULL
  match as a replay of another NULL.

`va_number` (`:964`) has the same shape and is likewise neither unique nor
indexed.

### `promo_redemption.invoice_id NOT NULL` decides where a redemption row is written

`invoice_id BIGINT UNSIGNED NOT NULL` (`:1004`) resolves an ambiguity in the spec,
and the resolution is forced by the DDL rather than chosen: **a redemption row is
written when a promo is applied to an invoice, not by a pure
`POST /promo/validasi` check.** A validation that has not yet touched an invoice
has nothing to point at — the column is `NOT NULL`, has no `DEFAULT`, and no
`CHECK` forgives it. Emitting a row at validation time would need a nullable
column (`column_nullable` drift), a placeholder invoice, or a second table for
un-committed validations, and none exists in the contract.

**Todos 44 and 45 must honour this**: `POST /promo/validasi` is a pure read and
writes nothing; the row is written when the invoice is created with the discount
applied, in the same transaction; and `nilai_diskon` (`:1005`) is the discount **as
applied** — a snapshot after `maks_diskon` capping and after `gratis_ongkir`
resolves to a shipping amount, not a live read of `master_promo.nilai`.

**Three consequences the schema cannot repair:**

- An invoice created with a promo and then abandoned **consumes a redemption
  permanently**: there is no `status`, no `voided_at` and no unique key.
- Re-applying the promo to a *second* invoice for the same cart consumes a second
  redemption.
- `nilai_diskon` is a snapshot, so editing `master_promo.nilai` afterwards does not
  rewrite what a past invoice was discounted by, and nothing keeps the two in step.

### **No quota and no redemption-uniqueness is enforceable anywhere in this batch**

`promo_redemption` has **no unique key of any kind** — only the primary key. The
DDL declares none and one cannot be added (rule 7). Therefore:

- `master_promo.kuota_per_user` (`:994`, `TINYINT UNSIGNED NOT NULL DEFAULT 1`)
  **cannot be enforced by this schema.** Nothing stops one patient redeeming one
  promo on ten invoices. The quota is a convention the service honours by
  counting rows, and the count is a race under concurrency.
- `master_promo.kuota_total` (`:993`, `INT UNSIGNED NULL`, NULL = unlimited) is
  unenforced for the same reason.
- A **double-submission race is unguarded**: two concurrent redemptions for the
  same promo on the same invoice both insert, and any report that sums
  `nilai_diskon` counts the discount twice. The only available mitigations are a
  transaction plus `lockForUpdate()` on the `invoice` row, or an
  `INSERT … SELECT … WHERE NOT EXISTS`. There is no unique key to fall back on.

What the three foreign keys *do* guarantee is that a redemption's `promo_id`,
`pasien_id` and `invoice_id` each point at a real row, so the ledger cannot hold
a dangling half. They do **not** guarantee the three agree with each other.

### Two bare columns in `klaim_bpjs`, plus two bare code columns

`klaim_bpjs` declares **no `FOREIGN KEY` clause at all**, and **neither
`booking_id` (`:1014`) nor `rekam_medis_id` (`:1015`) is mentioned anywhere in the
plan's todo-16 prose.** That omission is exactly how an invented constraint gets
written, and this project has already produced that defect three times
(`resep.konsultasi_id` in batch H,
`pasien_penjamin.faskes_rujukan_id` before that, `lab_hasil.diperiksa_oleh` in
batch I). Both targets exist many batches earlier — `booking` is table 37 and
`rekam_medis` is table 42 — so `->foreign()` would **succeed** and become permanent
`extra_foreign_key` drift while `migrate:fresh`, `php -l` and the whole 93-test
unit suite stayed green.

The substantive reading, and it is coherent: a BPJS claim is a **regulatory
submission about a patient's eligibility** and it legitimately outlives the
clinical episode. `booking_id` is nullable because a claim may cover an encounter
never booked through the platform (a walk-in at a partner `faskes`, or an episode
predating it). `rekam_medis_id` is nullable because a `rawat_inap` claim
(`tipe_layanan`, `:1018`) can be filed before the inpatient record is finalised,
or for care delivered outside this system. A `CASCADE` on either would destroy a
filed claim when the clinical record is edited — the same retention defect the
project already records for `akses_rekam_medis_log.rekam_medis_id ON DELETE
CASCADE` (`:1153`).

`diagnosa_icd10 VARCHAR(8) NULL` (`:1019`) and `tindakan_icd9cm VARCHAR(8) NULL`
(`:1020`) are also unconstrained, the same class as
`rekam_medis_diagnosa.icd10_kode` (`:660`) and `rekam_medis_tindakan.icd9cm_kode`
(`:672`). Here the **widths are deliberately compatible** with the catalogue —
`master_icd10.kode` is `VARCHAR(8) NOT NULL UNIQUE` (`:117`) and
`master_icd9cm.kode` is `VARCHAR(8) NOT NULL UNIQUE` (`:124`), the **same 8** — so
a foreign key would be *mechanically* possible (the parent key is unique) and
still wrong: a claim is filed against what the clinician wrote, and a diagnosis
not yet in the platform's catalogue must not block a regulatory submission.
Reconciling the two is a later data-quality task, not a write-time constraint.

All four columns were proven FK-free against
`information_schema.REFERENTIAL_CONSTRAINTS` joined to `KEY_COLUMN_USAGE`, and
each was separately confirmed to **exist** as `bigint unsigned` / `varchar(8)`
nullable, so "no row returned" cannot be confused with "no column".

### Six facts recorded because each looks like something the schema guarantees

1. **`invoice.total` has no `DEFAULT`, and four of the five money columns do.**
   `subtotal`, `diskon`, `biaya_admin` and `biaya_pengiriman` are all
   `DECIMAL(14,2) NOT NULL DEFAULT 0` (`:942`-`:945`); `total DECIMAL(14,2) NOT
   NULL` (`:946`) is required at insert and nothing computes it. This is the one
   deliberate asymmetry in the block and it is the right way round — a
   zero-defaulted total would silently accept an unpriced invoice. Four more money
   columns are in the same "no default" group: `pembayaran.jumlah` (`:962`),
   `refund.jumlah` (`:978`), `master_promo.nilai` (`:990`) and
   `promo_redemption.nilai_diskon` (`:1005`), so a zero-value payment, refund,
   promo or redemption row is not reachable by omission.
2. **`biaya_admin_flat` and `biaya_admin_persen` both default to 0, i.e. NO fee**
   (`:931`-`:932`), and because both are `NOT NULL` there is no third "unconfigured"
   state. A method added without a fee is indistinguishable from a free one, so a
   checkout must read the fee from the row and must **not** treat 0 as "fall back
   to a default". The scales differ and both matter: `(12,2)` flat and `(5,2)`
   percent, so the largest expressible percentage is `999.99` and a 100 % fee is
   the boundary.
3. **`invoice.status` defaults to `'menunggu_pembayaran'`, not `'draft'`**
   (`:947`-`:948`), so an invoice row is created *already awaiting payment* and
   `draft` is reachable only by an explicit write. `dibatalkan` sits at position 5,
   **before** both refund states, so ENUM position is not a lifecycle order.
4. **The contract spells "expired" two ways and both are reproduced.** As an ENUM
   member, `kedaluwarsa` appears **3** times (`rujukan.status` `:610`, `resep.status`
   `:752`, `pembayaran.status` `:966`) and `kadaluarsa` **2** times
   (`booking.status` `:516`, `invoice.status` `:947`). The `d` form is also the
   only one used for expiry **column** names (`user_otp.kedaluwarsa_at` `:184`,
   `user_refresh_tokens.kedaluwarsa_at` `:208`, `apotek_stok.kedaluwarsa` `:836`).
   `invoice.status` is therefore in the minority spelling, and a "consistency
   cleanup" across the two tables would break one of them. Do not perform it.
5. **Two status ENUMs default to their *first* member and a third has no default
   at all:** `refund.status` defaults to `diajukan` (`:980`, the first of four) and
   `pembayaran.status` to `pending` (`:966`, the first of five), while
   `master_promo.tipe_diskon` (`:989`) has **no** `DEFAULT`, so omitting it is
   MySQL 1364 rather than a silent default. That asymmetry is correct: the
   meaning of `nilai` (`:990`) depends entirely on `tipe_diskon`, so a defaulted
   type would make `nilai` ambiguous. A reader who assumes "ENUMs here default to
   their first value" is right twice and wrong once.
6. **`refund.jumlah` is never reconciled against `pembayaran.jumlah`.** A refund
   larger than the payment, a second refund row for one payment, and a payment with
   `status = 'refund'` and no refund row are **all representable** — there is no
   `CHECK` and no uniqueness. Partial refunds are the point of the table, so the
   *absence* of a uniqueness constraint is a deliberate gap; the *absence* of any
   total-vs-refunded reconciliation is a real limitation. Compounding it,
   `refund` and `pembayaran.status = 'refund'` are not linked by any foreign key,
   so the two can disagree in both directions; only `dibuat_at` exists, so a
   four-state workflow carries a single timestamp and a rejection leaves no record
   of when or by whom.

### `klaim_bpjs` is migrated for referential completeness and never exercised

`docs/migration-order.md` row 67 records `Module: ORPHAN`, `Resource: —`,
`Controller: —`; the spec allows V-Claim to be stubbed, so **no service, client,
endpoint or seeder may be built against it.** It must still exist in full for two
concrete reasons: `master_metode_pembayaran.tipe` (`:929`) carries a `'bpjs'`
member and the seed at `:1278` inserts a `BPJS` method with
`penyedia = 'BPJS Kesehatan'`, so a payment **can** be routed to BPJS even though
the claim submission is stubbed; and the `nomor_sep` / `nomor_kartu` pair is the
eligibility data a stubbed claim row would carry.

`nomor_sep` is the table's only uniqueness (`:1016`, inline `UNIQUE`) and is what
makes a claim traceable — a resubmission after `perlu_perbaikan` must reuse the
same SEP. `nomor_kartu` is **`CHAR(13)`** (`:1017`), fixed-width, and must stay
`char()`: storing 13 characters is identical under `CHAR(13)` and `VARCHAR(13)`,
but *reading* is not — `CHAR` is blank-padded on retrieval and strips trailing
spaces, so a hand-typed 10-digit number silently becomes 13 characters and any
comparison against a 13-digit value behaves differently without erroring.

`perlu_perbaikan` is a **return for correction and the schema makes it a dead
end**: no revision column, no `versi`, no draft/body split, no submissions table.
The only way to resubmit is to `UPDATE` the same row, overwriting the rejected
submission, and `diubah_at` (`:1028`) is then the only record it happened. Treat
both `ditolak` and `perlu_perbaikan` as states that cannot be appended to — the
same terminality the project already records for `resep_verifikasi`, whose
`resep_id` is `UNIQUE` (`:788`), so a prescription can be verified once, ever.

### Seed coverage: one table seeded, one not, and three ENUM values unexercised

`master_metode_pembayaran` has **14** seed rows at `:1264`-`:1278`, supplying only
`(kode, nama, tipe, penyedia)`, so all 14 inherit `biaya_admin_flat = 0`,
`biaya_admin_persen = 0` and `status_aktif = 1` from their defaults. Counting the
distinct `tipe` values actually present gives **6 of the 9**:
`va_bank` 5, `e_wallet` 5, `qris` 1, `cod` 1, `tunai` 1, `bpjs` 1.
**`kartu_kredit`, `gerai_retail` and `asuransi` have no seed row at all**, so a
todo-18 seeder replaying only the INSERT leaves three legal payment methods
unrepresented.

`master_promo` has **no `INSERT INTO` anywhere in the file** — confirmed by
scanning all 15 `INSERT INTO` statements, none of which names it. Whatever promos
exist are therefore unsourced fixture data, in the same position as
`obat_interaksi` and `lab_paket_item`. There is also no index on `mulai_at` or
`selesai_at` and no `CHECK (selesai_at > mulai_at)`, so "which promos are live
right now" is a scan of the whole catalogue and an inverted window is
representable; the intended expiry mechanism is an application job flipping
`status_aktif` to 0, because the table has no `status` column to record *why* a
promo is off.

### Findings about this project's own documents, found by reading rather than by trusting

- **The plan's todo-16 prose misspells a column name: `biaya_pengirpikan`.** The
  DDL at `:945` and the live schema both say **`biaya_pengiriman`**. This is a
  transposed identifier in a list an executor copies from, and it is the exact
  defect class A.17 describes; the migration declares the DDL's spelling and says
  so in place.
- **The plan's todo-16 prose cites both `invoice` indexes at `:953-954`.** They are
  at **`:954`** (`idx_invoice`) and **`:955`** (`idx_ref`); `:953` is the
  `FOREIGN KEY (pasien_id)` clause. The order is right — `idx_invoice` before
  `idx_ref` — so only the range is off by one.
- **The plan's "Webhook idempotency has no dedupe key" bullet cites
  `idx_bayar_status (status, dibayar_at)` at `:970`.** It is at **`:972`**; `:970`
  is `FOREIGN KEY (invoice_id) REFERENCES invoice(id)`. This is the **eighth**
  consecutive batch to find a wrong inline `:NNN` in this plan's prose, and again
  the line-index table in this file's `docs/migration-order.md` rows 61-67 carries
  the correct lines for all seven tables.
- **`invoice.referensi_tipe` is at `:940`, not `:941`.** `:941` is
  `referensi_id`. This is the citation todo 15 already found in **its own** prose
  (plan line 374); it is repeated here for completeness because todo 16 owns the
  column.
- **`docs/migration-order.md` rule 6's multi-line-ENUM list is still stale.** It
  names six entries and two of them — `konsultasi.status` (`:542`) and
  `konsultasi_chat.tipe_pesan` (`:568`) — are **not** wrapped: both close their
  own `ENUM(...)` on the same line and only their `NOT NULL DEFAULT` continues on
  the next. The wrapped list is **five**, and `invoice.status` (`:947`-`:948`) is
  one of them. The rule was not corrected in place, so anyone re-deriving ENUM
  handling from it will over-count.
- **The verifier prints `wrapped decls 11`, and that number is not a wrapped-ENUM
  count.** `SchemaSpec::multiLineColumns()` reports declarations whose `endLine`
  exceeds `line`, which is true for all six of `konsultasi.status`,
  `konsultasi_chat.tipe_pesan`, `lab_permintaan.status`, `pesanan_obat.status`,
  `home_care_pesanan.status` and `klaim_bpjs.status` even though each of those
  closes its own `ENUM(...)` on its own line. Asking it a different question —
  "does any line open an `ENUM(` that its own line does not close?" — gives
  **5**, at `:515`, `:713`, `:751`, `:947` and `:1137`. Recorded here because
  A.20's whole lesson is that naming a second source is not corroboration unless
  it was asked the same question, and this is a live instance inside this
  project's own tooling output.
- **A defect in this project's own documents was left in place deliberately.**
  `docs/migration-order.md` rule 6 is the stale list described above. Fixing it
  would widen this commit beyond my own paths, and the same reasoning that left
  `000041_rujukan_table.php:93-96`'s stale `diagnosis_kerja` claim in place
  (A.19) applies: the correction is dispatched separately rather than smuggled
  into a migration batch. **It is a real, live, wrong instruction**, and rule 6 is
  the text an executor will actually follow.

## Batch-K schema limitations the database cannot enforce (todo 17)

Batch K (`notifikasi`, `ulasan_dokter`, `artikel_kategori`, `artikel`,
`home_care_pesanan`, `audit_log`, `persetujuan_pdp`, `akses_rekam_medis_log` —
SQL tables 68-75) is at parity and **owes no deferred constraint**. All **eleven**
of its foreign keys point at a table that already exists when the migration
declaring them runs: `users` 12 (batch B), `pasien` 20 and
`pasien_anggota_keluarga` 21 (batch C), `dokter` 31 (batch D), `rekam_medis` 42
(batch G) and `artikel_kategori` 70 — **one from inside this same batch**, earlier
in the same commit. This batch therefore deferred nothing of its own.
**Batch K defers nothing and registers nothing.** The extra-table registry still
has exactly seven entries.

**`ulasan_dokter` holds the ONLY `CHECK` constraints in the entire 75-table
contract** — three of them, at `:1055`-`:1057`, on `rating`, `rating_komunikasi` and
`rating_akurasi`, all of the form `<col> BETWEEN 1 AND 5`. This is the single
most important fact about the batch and it is worth stating as a schema-wide
conclusion rather than a per-table note: **these three columns are the only
columns in the whole contract whose value range the database polices.** There is
no other `CHECK` in `telemedicine_test.sql`, no trigger anywhere and no generated
column, so every other numeric and enumerated value in the schema is validated by
the application layer alone.

MySQL 8.0.16+ **enforces** `CHECK`; before 8.0.16 the grammar parsed one and
ignored it, which is why it was historically treated as documentation. The
running server is 8.0.30, and enforcement was proven by *executing* violating
inserts rather than read out of the DDL: `rating = 6` and `rating = 0` are both
rejected with **MySQL 3819** naming `ulasan_dokter_chk_1`, `rating_komunikasi = 9`
with 3819 naming `chk_2`, `rating_akurasi = 7` with 3819 naming `chk_3`, while
`rating = 5`, `rating = 1` and `rating_komunikasi = NULL` all succeed. A NULL is
correctly not a violation, which is what makes the two nullable sub-scores
optional rather than required.

Laravel 13's Blueprint has **no** `CHECK` builder, so the three constraints are
issued as raw `DB::statement('ALTER TABLE ... ADD CHECK ...')` calls after the
table is created. They are added **unnamed**, on purpose: the DDL writes no
constraint name (they are inline column constraints), so MySQL auto-generates
`ulasan_dokter_chk_1`/`_2`/`_3` in creation order, which is exactly what importing
`telemedicine_test.sql` produces. Naming them `chk_rating` and siblings — as the
dispatched brief's illustrative snippet does — would invent a name the contract
never had. This is parity-safe by construction rather than by luck:
`SchemaDiffer::diffChecks()` compares CHECKs **by normalised expression only**,
precisely because the names are engine-generated.

**A negative-QA measurement that generalises A.21.** Removing one of the three
`CHECK` statements leaves `php -l` at **exit 0** and `migrate:fresh` at **exit 0**,
and drops the live CHECK count from 3 to 2 **silently**. The unit suite is
**completely blind to it**: 93 tests, 92 passed, 1 failed — byte-identical to the
unmutated run, same single failure, same 465 assertions. Only
`verify-schema` sees it (exit 1, `missing_check ulasan_dokter expected:
rating_akurasi between 1 and 5`). So the contract's only DDL-level value-range
guarantee can be deleted with **three of the four gates green**, and — as in todo
16's `idx_ref` — no functional test ever could catch it, because a constraint
affects only what the database rejects, never what the application can do.

### `ulasan_dokter.konsultasi_id` is `NOT NULL UNIQUE`, so a second review is impossible

`:1052` — `konsultasi_id BIGINT UNSIGNED NOT NULL UNIQUE COMMENT '1 konsultasi = 1
ulasan'`, declared as an inline `->unique()` (rule 10: an inline `UNIQUE` is
compared by *semantics*). **One consultation yields exactly one review, and that
is enforced by the database, not by convention.** A second insert fails with MySQL
1062 — proven live during this todo's own probe run.

The consequence for todo 41 is structural and not advisory: the review flow must
be **INSERT-then-UPDATE-or-409**, never a second `INSERT`. A submit handler that
unconditionally inserts is wrong against this schema, and no application-level
de-duplication changes it, because the uniqueness lives in the index.

The **reply** is on the same row — `balasan_dokter` (`:1060`) and `dibalas_at`
(`:1061`) are ordinary nullable columns, there is no replies table and no reply
uniqueness, so answering a review is an `UPDATE`. That is also why `dibuat_at`
(`:1062`) is the review's creation time and not a last-write time, and why the
table has no `diubah_at` at all.

### Three bare columns in batch K, and three different `ON DELETE` polarities for a `user_id`

`artikel.reviewer_user_id` (`:1078`), `audit_log.user_id` (`:1120`) and
`audit_log.record_id` (`:1123`) all carry a reference-shaped name and **no foreign
key**. All three were proven FK-free against
`information_schema.REFERENTIAL_CONSTRAINTS` joined to `KEY_COLUMN_USAGE` — **not**
by reading `SHOW CREATE TABLE`, which shows only constraints that exist and so
cannot distinguish "absent" from "not looked for" — and each was separately
confirmed to **exist**, so that "no row" cannot be confused with "no column":

| Column | SQL line | Live type | Why it is bare |
| --- | --- | --- | --- |
| `artikel.reviewer_user_id` | `:1078` | `bigint unsigned` NULL | The DDL's own COMMENT says *"Reviewer medis (revisi medis)"* — a **workflow role, not an identity**. The clinician who medically signs off need not be an author or staff member, and the column is nullable because the common case is an article never medically reviewed (`status = 'draft'`). Contrast `artikel.penulis_user_id` (`:1077`), which **is** constrained at `:1090`: one `_user_id` column being constrained says nothing about the other. |
| `audit_log.user_id` | `:1120` | `bigint unsigned` NULL | The plan's bare-column list states the reason: unconstrained **so the log survives user deletion**. **ATTRIBUTION: that rationale is the plan's, not the SQL's — `:1120` carries no `COMMENT` text.** Nullable separately, because a `login`/`logout` action has no actor. |
| `audit_log.record_id` | `:1123` | `varchar(64)` NULL | **A string, and it must be.** See below. |

**`audit_log.record_id VARCHAR(64)` is why the audit log cannot key on a numeric
id, and therefore why `idx_audit_tabel` is a string index.** `audit_log` is the
project's generic, table-agnostic trail, so (`tabel_target`, `record_id`) must be
able to name the primary key of *every* model todo 43's observers watch — and not
all of them are numeric. **Four** contract tables have a composite primary key and
**no `id` at all** (rule 3): `role_permissions` 15, `user_roles` 16,
`dokter_faskes` 33 and `lab_paket_item` 57. A single `BIGINT` cannot name any of
them; `VARCHAR(64)` can, by storing e.g. `"12|34"`.

Both leading columns of `INDEX idx_audit_tabel (tabel_target, record_id,
dibuat_at)` (`:1131`) are therefore `VARCHAR(64)`, so **the index is collated, not
numeric**: a scan for `record_id = '9'` also visits `'10'` and `'100'`, which is
correct and unavoidable, and a `WHERE` that drops the quotes matches nothing. An
`unsignedBigInteger()` here would be a parity break that makes an audit row for a
composite-keyed or UUID-keyed target **unrepresentable**.

**Three different treatments of a `user_id` across one batch, each deliberate:**

| Column | SQL line | Treatment | Why |
| --- | --- | --- | --- |
| `notifikasi.user_id` | `:1046` | `ON DELETE CASCADE` | A notification is a personal, non-clinical artefact addressed to one user; a notification addressed to a deleted account is unreachable and a standing privacy liability. |
| `persetujuan_pdp.user_id` | `:1143` | `ON DELETE CASCADE` | A consent record is personal too — a consent row outliving its subject would assert an agreement by someone who no longer exists. |
| `audit_log.user_id` | `:1120` | **no constraint** | An audit row is **evidence**, and a `CASCADE` would destroy the record of what a user did at the moment their account was removed. The cost is the well-known one: an audit row can outlive its subject and then dangle, so `user_id` must be read as a *historical* identifier and never joined as a live one. |

**Do not "harmonise" these.** The same split appears in
`akses_rekam_medis_log` (75), where `rekam_medis_id` **cascades** (`:1153`) and
`pengakses_user_id` **restricts** (`:1154`, no `ON DELETE` written): *the record*
link cascades, *the person* link restricts.

### `akses_rekam_medis_log.rekam_medis_id ON DELETE CASCADE` is a retention defect — recorded, not fixed

`:1153` is a genuine schema defect: deleting a medical record deletes the evidence
that it was accessed, contrary to UU PDP No. 27/2022 and Permenkes 24/2022
retention duties. The plan already records this at line 192 and the plan's own
`migration-order.md` row 75 contract repeats it. It is **documented and not
altered**, because changing it would be foreign-key drift against a read-only
contract.

**The mitigation is operational and currently total: medical records must never be
hard-deleted; only `dihapus_at` soft deletion is permitted.** `rekam_medis` has no
`dihapus_at` of its own (its 28 columns are `:622`-`:654`, and it is in neither the
"both" nor the "`dibuat_at` only" timestamp group), so it is in practice **never
deletable at all** — which is what makes the cascade unreachable today. **That is a
coincidence of the current column set, not a guarantee.** Any future migration that
adds `dihapus_at` to `rekam_medis` *and* introduces a hard-delete path would
silently turn this into a compliance hole, and must re-examine the constraint
first. Recorded here because nothing else in the repository would surface it.

### `persetujuan_pdp` is append-only, immutable, and keyed per document VERSION

`persetujuan_pdp` has **no `dibuat_at` and no `diubah_at`** — one of the **39**
contract tables with neither, and one of the eleven the plan's own (wrong) list of
29 omitted. `$table->timestamps()` must not be called, and **todo 19's model needs
`public $timestamps = false`**. The absence is load-bearing: a consent record is
evidence and must be immutable, and a table with an `updated_at` invites an
`UPDATE` that rewrites the past. The row's only chronology is `disetujui_at`
(`:1141`), which is a fact about the act and not bookkeeping — it is caller-supplied
and is `NOT NULL` **even when `disetujui = 0`**, because a refusal is dated too.

**`UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)` (`:1144`) makes
uniqueness per VERSION, and all three columns are load-bearing:**

- A re-consent after a policy version bump is a **new row**, not an update. The old
  consent stays on record against the document it was actually given for, and an
  `UPDATE` would destroy exactly the evidence that matters. A user may hold any
  number of consent rows across versions.
- A same-version duplicate is **rejected by the database** (MySQL 1062), with no
  application logic involved and a retry that keeps failing — which is correct.
- **A revocation at the same version cannot be a new row**, because the key would
  collide. There is no `dicabut_at`, no `status` and no partial unique index in
  MySQL, so the only representable options are to `UPDATE` the existing row's
  `disetujui` flag (losing the fact that consent was once given) or to `DELETE` it
  (losing the row). `disetujui TINYINT(1) NOT NULL` (`:1140`) is the lever that
  makes the first option possible and is the **only** reason a revocation is
  representable at all.

**`versi_dokumen` is a `VARCHAR(20)`, so "the latest version" cannot be computed
with `MAX(versi_dokumen)`** — string collation puts `"10.0"` **before** `"9.0"`.
Any "highest version wins" lookup must parse the version or order by
`disetujui_at`. **Todo 47 owns that rule and the revoked-same-version collision,
and the DDL decides the shape of both.**

### `artikel_kategori` is three columns wide, and `jenis` is not one of them

`artikel_kategori` (`CREATE TABLE` at `:1068`, spanning `:1068`-`:1072`) has
**exactly three columns** — `id` (`:1069`, `SMALLINT UNSIGNED`),
`nama` (`:1070`) and `slug` (`:1071`) — with **no ENUM of any kind** and **no
timestamps**. Plan appendix **A.23** exists because the plan itself attributed the
wrapped `jenis` declaration at `:1137`-`:1138` to `artikel_kategori.jenis`, a
column this table does not have, while getting the line numbers right. `:1137` is
inside `persetujuan_pdp` (`CREATE TABLE` at `:1134`).

`persetujuan_pdp.jenis` is the wrapped ENUM and it has **five** values, counted
from **both** `:1137` and `:1138`:
`syarat_ketentuan`, `kebijakan_privasi`, `berbagi_data_medis`, `pemasaran`,
`komunikasi_tindak_lanjut`. Reading `:1137` alone yields **four** and makes the
column look nullable with no default — three separate discrepancies
(`column_type`, `column_nullable`, `column_default`) from one misread line. The
verifier reports a truncation of exactly this kind as `column_type` with **both
full value lists printed**, proven by this todo's negative QA: truncating the
migration's list to the four values on `:1137` leaves `php -l` at exit 0,
`migrate:fresh` at exit 0 and the 93-test unit suite at exactly 92/93 (same single
pre-existing failure, same 465 assertions), while `verify-schema` exits 1 with
`column_type persetujuan_pdp.jenis` and both lists. **The A.20 claim is therefore
demonstrated directly on the column this batch owns**, and it confirms that the
verifier compares an ENUM as a **sequence**, not a set.

`artikel_kategori` is also the only table in batch K with **no foreign key and no
FK-less reference-shaped column either** — nothing about a category points
anywhere — and, having no FK, it has no InnoDB implicit support index, so
`PRIMARY KEY (id)` is the sole index the engine creates on its own. It is
nonetheless genuinely referenced: `artikel.kategori_id` carries a real
`FOREIGN KEY (kategori_id) REFERENCES artikel_kategori(id)` at `:1089`, and
`artikel.kategori_id` is `SMALLINT UNSIGNED` to match this table's 16-bit `id`, so
`foreignId()` would be wrong there too.

### `dibaca_at DATETIME` is the unread flag, and `idx_notif`'s column order is the contract

`notifikasi.dibaca_at` is `DATETIME NULL` (`:1044`) and must be `dateTime()`, never
`timestamp()` — the two are not interchangeable in MySQL, so the wrong builder is
both `column_type` drift and a silent reinterpretation of the value. **A `NULL`
here *is* the unread state**: there is no `terbaca` boolean and no `status` column,
so the unread count is literally `WHERE user_id = ? AND dibaca_at IS NULL`. That is
what makes `INDEX idx_notif (user_id, dibaca_at)` (`:1047`) load-bearing, and
**`user_id` is the leftmost column** — a reversed `(dibaca_at, user_id)` would be a
different index and would be reported as `missing_index` plus `extra_index` drift,
because rule 10 compares a DDL-written name by name *and* column list.

`notifikasi.tipe` is a **seven**-value ENUM with **no `DEFAULT`**
(`booking`, `pembayaran`, `resep`, `chat`, `lab`, `promo`, `sistem`), so omitting
it is MySQL 1364 rather than a silent classification. The list mixes transaction
classes with marketing (`promo`) and a catch-all (`sistem`), and nothing in the
DDL distinguishes a transactional notification from an advertisement — so "unread
count by category" is a product decision for todo 47, not a schema fact.

`notifikasi.payload` is `JSON NULL` (`:1043`) and must be `json()`, never `text()`.
**Nothing validates its shape and there is no redaction**, so a payload holding
clinical data is stored exactly as the observer wrote it; that is a service-layer
obligation the schema neither helps nor hinders.

### `audit_log` is append-only by construction, and its `data_lama`/`data_baru` pair is why

`audit_log` has `dibuat_at` only (`:1129`) and **no `diubah_at`** — it is the one
table the plan's own "`dibuat_at` only" list omitted (the plan says 18; the
measured figure is **19**), so migration `2026_10_01_000073` is the correction.
**Todo 19's model needs `const CREATED_AT = 'dibuat_at'` and
`public $timestamps = false`.** The absence is the point of the table: an audit
log is append-only, an `UPDATE` to a past row would destroy the evidence it holds,
so the schema removes the possibility of an update timestamp entirely rather than
merely discouraging it. `akses_rekam_medis_log` is append-only for the same reason
and carries the same single `dibuat_at`.

`data_lama` and `data_baru` are **both** `JSON NULL` (`:1124`-`:1125`), which is
the right shape for a before/after pair and the reason the pair cannot be
collapsed: a `create` has no `data_lama`, a `delete` has no `data_baru`, and a
`read` or `login` has neither. `aksi` (`:1121`) is an **eight**-value ENUM with no
default whose members are **three different vocabularies** — CRUD
(`create`/`read`/`update`/`delete`), authentication events with no record at all
(`login`/`logout`), and bulk egress (`download`/`export`) — which is why
`tabel_target` and `record_id` are both nullable. `read` being a member is what
makes reading a medical record an auditable event, and it is the mechanism behind
`akses_rekam_medis_log` existing as a separate, typed, constrained table.

### Two `DEFAULT`s in this batch have opposite polarity, and one `TINYINT(1)` reads backwards

`ulasan_dokter.is_anonim TINYINT(1) NOT NULL DEFAULT **1**` (`:1059`) — **the
default is TRUE**, so a review is **anonymous** unless its author opts in. This is
the exact defect class plan appendix A.15 records as a blocker in batch D, where a
docblock claimed "defaults to 0" while the code and the SQL both said `1`; read it
the right way round here. The `(1)` is a display width MySQL 8 does not emit, so
the live column is `tinyint` with `COLUMN_DEFAULT` `'1'`.

By contrast every money and counter default in this batch is **0** and therefore
means "not supplied" rather than a real value: `artikel.jumlah_view`
(`INT UNSIGNED NOT NULL DEFAULT 0`, `:1085`), `home_care_pesanan.durasi_jam`
(`TINYINT UNSIGNED NOT NULL DEFAULT **1**`, `:1102` — one hour, a real figure) and
`home_care_pesanan.biaya` (`DECIMAL(12,2) NOT NULL DEFAULT 0`, `:1106`). Because
`biaya` is `NOT NULL`, an unpriced order is representable and indistinguishable
from a genuinely free one, and **nothing computes it** from `durasi_jam` and
`tipe_layanan` — no `CHECK`, no generated column, no trigger.

### Two status ENUMs default to their first member; one does not default at all

`artikel.status` defaults to `'draft'` (`:1084`, the first of four) and
`home_care_pesanan.status` defaults to `'menunggu_pembayaran'` (`:1104`-`:1105`,
the first of six). `notifikasi.tipe` (`:1041`), `audit_log.aksi` (`:1121`),
`home_care_pesanan.tipe_layanan` (`:1098`), `persetujuan_pdp.jenis`
(`:1137`-`:1138`) and `akses_rekam_medis_log.tujuan_akses` (`:1151`) have **no
`DEFAULT`**, so omitting any of them is MySQL 1364 rather than a silent
classification. A reader who assumes "ENUMs here default to their first value" is
right twice and wrong three times in this batch alone.

**`home_care_pesanan.status` is NOT wrapped.** `ENUM(` opens **and** closes on
`:1104`; `:1105` carries only `NOT NULL DEFAULT 'menunggu_pembayaran',`. A prior
report in this project claimed `:1104`-`:1105` was a sixth wrapped ENUM, and it is
not. The wrapped count was re-derived from scratch with the predicate "does any
line open an `ENUM(` that its own line does not close?" and gives exactly **five**:
`booking.status` (`:515-516`), `master_obat.bentuk_sediaan` (`:713-714`),
`resep.status` (`:751-752`), `invoice.status` (`:947-948`) and
`persetujuan_pdp.jenis` (`:1137-`). The other single-line declarations whose
*declaration* merely spans two lines are `konsultasi.status` (`:542`-`:543`),
`konsultasi_chat.tipe_pesan` (`:568`-`:569`),
`lab_permintaan.status` (`:884`-`:885`), `pesanan_obat.status` (`:810`-`:811`),
`klaim_bpjs.status` (`:1022`-`:1023`) and `home_care_pesanan.status`
(`:1104`-`:1105`). That is a **different question** from the `wrapped decls 11` the
verifier prints on every run, which counts declarations whose end line exceeds
their start line; both numbers are right and only the five answer the parity
question (A.20/A.22). `docs/migration-order.md` rule 6 now carries the correct
five-entry list, and the `konsultasi.status` / `konsultasi_chat.tipe_pesan` /
`lab_permintaan.status` / `pesanan_obat.status` / `klaim_bpjs.status` entries that
earlier batches reported as wrapped are gone.

### Findings about this project's own documents, found by reading rather than by trusting

- **The plan's todo-17 prose cites `INDEX idx_notif (user_id, dibaca_at)` at
  `:1045`.** It is at **`:1047`**; `:1045` is `dibuat_at`. Ninth consecutive batch
  to find a wrong inline `:NNN` in this plan's prose, and again the line-index
  table at line 134 of the plan — and `docs/migration-order.md` rows 68-75 — carry
  the **correct** lines for all eight tables.
- **The plan's todo-17 prose cites the three `CHECK` constraints at `:1052-1054`.**
  They are at **`:1055`-`:1057`**. `:1052` is `konsultasi_id` and `:1053`/`:1054`
  are `pasien_id` / `dokter_id` — so the citation names a range that does not
  contain a single `CHECK`, and an executor trusting it would have looked for
  constraints on the wrong columns. The plan's own `konsultasi_id` citation at
  `:1052` in the same sentence is correct, which is what makes the range error
  easy to miss.
- **The plan's todo-17 prose does not mention `artikel.reviewer_user_id` as a bare
  column at all**, although the authoritative bare-column list at plan line 181
  names it with the correct line `:1078`. That omission is exactly how an invented
  constraint gets written, and this project has produced that defect three times
  (`resep.konsultasi_id` in batch H, `pasien_penjamin.faskes_rujukan_id` before
  that, `lab_hasil.diperiksa_oleh` in batch I). Migration
  `2026_10_01_000071` says so in place.
- **`tests/Unit/Console/VerifySchemaDeferredConstraintTest.php:249` asserted
  `missing_table > 0` on the full run, and that became false at todo 17 —
  correctly. RESOLVED in todo 18.**
  Plan appendix A.9's own table predicts `missing_table` = **0** at todo 17
  (48/41/38/34/29/21/15/8/**0**/0 for todos 9-18), and that batch created the last
  eight tables, so the global "schema still incomplete" signal was carried by
  **two `missing_view` rows** (migrations 77 and 78, todo 18) instead. The test's
  sibling in `VerifySchemaCommandTest.php` was already fixed for exactly this
  boundary — its docblock at lines 26-32 explains that views must be derived too
  "or a test that inverted on that signal alone would go red at todo 17" — and this
  second file was not. **This was a pre-existing latent defect that todo 17 was the
  first commit to expose, not a migration defect**, and todo 17 deliberately did
  **not** edit it: the test file was outside that commit's authorised paths, and
  editing a test to make a suite green is the failure mode this project has spent
  ten batches fighting. Todo 18 found it, and **re-scoped the whole file** rather
  than deleting the one assertion: six of its eight tests were premised on a
  pending deferral keeping the full run failing, and with the last deferral resolved
  the correct end state is exit 0 and zero drift. The reasoning, the table of which
  assertions were void and why, and the coverage that was preserved instead are in
  that file's own docblock.
- **A comment can break the derived unit suite, and only the unit suite can see
  it.** This batch's own `000069` docblock originally spelled the method name with
  its argument list in prose. `VerifySchemaCommandTest.php` derives its
  expectations by counting the raw text `Schema::create` across every migration
  file and then re-counting only the occurrences followed by a quoted literal; a
  mention in a comment raises the first count without raising the second and trips
  that test's own "refuse rather than under-test" guard, failing **five** tests at
  once. `php -l`, `migrate:fresh` and `verify-schema` were all green throughout.
  It is the purest instance yet of A.21's lesson — a defect in a *comment* that no
  gate except the test suite could find — and it is why the file now says why it
  avoids the spelling.

## Seed data: what is faithful and what is not (todo 18)

**The project's 1:1 fidelity claim covers SCHEMA, not DATA.** It means
`database/migrations/**` reproduces `telemedicine_test.sql` exactly — 75 tables,
2 views, every column, index, foreign key and CHECK — and
`php artisan sehatly:verify-schema` is the instrument that proves it. **It says
nothing about row counts**, and todo 18 added seeders without weakening it. The
distinction matters because a reader who assumes "fidelity" covers the seed rows
will reasonably expect `lab_paket_item` and `obat_interaksi` to be populated, and
they are populated by data that is in no SQL file.

### The nine section-`[16]` seeders ARE faithful

`database/seeders/{MasterWilayah,MasterUmum,Spesialisasi,Penjamin,
MetodePembayaran,Icd,Obat,Lab,ArtikelKategori}Seeder.php` port
`telemedicine_test.sql:1203-1344` statement for statement. **15 tables, 151 rows**,
and every count was derived by parsing the DDL's own `INSERT` tuples rather than
read from a comment or a plan:

| Table | Rows | Table | Rows |
| --- | --- | --- | --- |
| `master_provinsi` | 38 | `master_metode_pembayaran` | 14 |
| `master_agama` | 7 | `master_icd10` | 15 |
| `master_golongan_darah` | 4 | `master_icd9cm` | 6 |
| `master_pendidikan` | 8 | `master_obat` | 7 |
| `master_status_pernikahan` | 4 | `master_lab_tindakan` | 10 |
| `master_hubungan_keluarga` | 7 | `master_lab_paket` | 3 |
| `master_spesialisasi` | 16 | `artikel_kategori` | 6 |
| `master_penjamin` | 6 | **total** | **151** |

`verify-schema` **cannot check any of this** — it compares schema, not data
— so the row counts are checked by reading `COUNT(*)` back after seeding, and
those measurements are in `.omo/evidence/task-18-sehatly.md`.

### `DevFixtureSeeder` is NOT faithful, and here is exactly what it adds

`database/seeders/DevFixtureSeeder.php` creates **7 tables, 21 rows** that have **no
source in `telemedicine_test.sql`**: 5 `users`, 2 `pasien`, 2 `faskes`, 3 `dokter`,
4 `dokter_spesialisasi`, 3 `lab_paket_item` and 2 `obat_interaksi`.

**`telemedicine_test.sql` contains no `INSERT` for `lab_paket_item` or
`obat_interaksi` anywhere.** I enumerated every `INSERT INTO <target>` in all 1,349
lines — case-insensitive — and got exactly **15** distinct targets, all in
section `[16]`, and neither table is among them. Each appears exactly twice in the
file and both occurrences are accounted for:

| Line | Statement | Kind |
| --- | --- | --- |
| `:31` | `DROP TABLE IF EXISTS lab_paket_item, master_lab_paket, master_lab_tindakan;` | reset |
| `:868` | `CREATE TABLE lab_paket_item (` | DDL |
| `:33` | `DROP TABLE IF EXISTS ... , obat_interaksi, master_obat;` | reset |
| `:731` | `CREATE TABLE obat_interaksi (` | DDL |

The file's **last** statement is `artikel_kategori` at `:1338`-`:1344`; `:1346`-`:1348`
are the `SELESAI` banner and `:1349` is a bare `SELECT ... AS status`. So there is
nothing after it, and the "3 `lab_paket_item` rows" and the `obat_interaksi` pairs
are **new data**.

**A correction to the plan's own wording, recorded because the number is wrong in a
way that matters.** The plan calls them "**3** `lab_paket_item` rows". The *three* is
the number of **package mappings**; the number of **rows** is **7**, because
`lab_paket_item` is a composite-PK join table and each mapping is one row per action:

| Package | Actions | Rows |
| --- | --- | --- |
| `Medical Check Up Dasar` | `LAB-001`, `LAB-002`, `LAB-009` | 3 |
| `Cek Gula & Kolesterol` | `LAB-003`, `LAB-004` | 2 |
| `Fungsi Hati Lengkap` | `LAB-005`, `LAB-006` | 2 |
| | | **7** |

All six `LAB-*` codes were verified against `:1321-1330` before use.

**These two tables are not the only unsourced ones.** `master_promo` also has **no**
`INSERT` anywhere in the file, so any promo is fixture data in the same position; the
seeder does not create one. And `v_pendapatan_bulanan` is **empty by parity**, not by
omission: the DDL seeds no `invoice` and no `pembayaran` rows, so importing
`telemedicine_test.sql` itself yields zero rows in that view.

### The doctor fixture is load-bearing, and the reason is `verify-schema`'s blind spot

**`verify-schema` compares views by NAME AND EXISTENCE ONLY.** `SchemaDiffer` emits
`missing_view` and `extra_view` and nothing else for views, because MySQL re-renders
`VIEW_DEFINITION` server-side. **A view that exists and is completely wrong is still
`Discrepancies: 0`.** So the parity verdict says nothing about whether
`v_dokter_katalog` returns anything, and the doctor fixture is what makes it
non-empty.

`v_dokter_katalog` filters on **three** predicates — `status_verifikasi =
'terverifikasi'`, `status_aktif = 1` and `tersedia_telemedisin = 1` — and
`status_verifikasi` **defaults to `'pending'`** (`:427`). A doctor inserted with
defaults is therefore invisible to the view. The fixture creates three doctors, of
which **two** are visible and one is deliberately left `pending` **and**
`status_aktif = 1` — the exact active-but-unverified combination the batch-D note
above warns about — so that the view's filtering is *observable* rather than
assumed. A view that returned all three would be wrong, and the measured count of
**2** is what proves it is not.

### Both view definitions were verified by hand, because nothing automated can

`telemedicine_test.sql:1170-1187` and `:1190-1196` were copied **verbatim** into
migrations 77 and 78 as PHP nowdocs, and the copies were compared to the DDL
**byte for byte** (whitespace-normalised, case-sensitive) — both are identical.
`GROUP_CONCAT(s.nama SEPARATOR ', ')` and `DATE_FORMAT(p.dibayar_at, '%Y-%m')` are
preserved exactly, because both are MySQL-only syntax that no fluent builder
reproduces and both are the kind of token a re-quoting pass mangles.

The two `SHOW CREATE VIEW` outputs, the seven-column and three-column lists, their
types, and both `COUNT(*)` values are in `.omo/evidence/task-18-sehatly.md`.

## Known schema limitations, todo 33 (the medical record)

**These are ABSENCES, not extra tables.** The registry above is for tables this project
added; this section is the opposite record - places where `telemedicine_test.sql`
cannot express something the plan wants, recorded so a later reader does not
rediscover it from a symptom. Nothing here is drift, and `sehatly:verify-schema` does
not read this section: `ExtraTableRegistry::HEADING` is resolved by
`SchemaNotesSection`, so a heading other than the registry's own cannot be mistaken for
a registry row. (The "Deferred constraints" section that used to live here had to be
REMOVED rather than left in place, because `DeferredConstraintRegistry::fromMarkdown()`
is unconditional and raises on an empty registry - do not reintroduce a second parsed
section without reading that class first.)

### The amendment chain has no linkage column

`rekam_medis` is `telemedicine_test.sql:621-655`. It carries `versi TINYINT UNSIGNED
NOT NULL DEFAULT 1` at `:646` and `status_dokumen ENUM('draft','final','diamendemen')
NOT NULL DEFAULT 'final'` at `:645`, and **nothing else** that relates one version of
a document to another. There is no `parent_id`, no self-referencing `rekam_medis_id`,
no `parent_uuid`, no `versi_induk`, no `is_current`, no `superseded_by`, no
`dimodifikasi_at`, no `alasan_amandemen` and no `diamendemen_oleh`; the only declared
index is `idx_rm_pasien (pasien_id, tanggal_periksa)` at `:654`. The suite asserts the
absence of all twelve names against the parsed DDL on every run, so a future migration
adding one cannot pass unnoticed.

Consequences, all of which `App\Services\RekamMedis\RekamMedisService` documents and
tests rather than papers over:

- **The chain is RECONSTRUCTED, not stored.** It is grouped on
  `(pasien_id, dokter_id, tanggal_periksa)` and ordered by `versi`. `tanggal_periksa`
  is a `DATETIME` (`:630`), not a DATE, so the key is a full timestamp: two visits on
  the same day are two chains unless their seconds match.
- **"Current" is DEFINED, not recorded.** The current version is the highest `versi`
  in the group. Nothing in the schema can say so, and a row that is a draft while a
  sibling in its group says `final` is representable - only `RekamMedisService`'s own
  write path prevents it.
- **Two rows may share a version.** There is no unique constraint over
  `(pasien_id, dokter_id, tanggal_periksa, versi)`, so a duplicate is a MySQL-legal row.
  The service's only defence is a `FOR UPDATE` current read of `MAX(versi)` over the
  group inside the amendment transaction; a direct `DB::table()` write bypasses it.
- **The chain is capped at 255.** `versi` is a TINYINT UNSIGNED, so the 256th amendment
  would be a MySQL 1264. `amandemen()` refuses it with a 422 naming the limit.
- **The plan's `old.versi + 1` is not always right.** For a SUPERSEDED row that number
  is already in the group, and two rows at one version cannot be ordered, so the
  service takes `MAX(versi) + 1` instead. The deviation is deliberate and is asserted
  by a test.

### `akses_rekam_medis_log` has no index, no ordering column and a cascading delete

The table is `telemedicine_test.sql:1147-1155`. `dibuat_at` is a `TIMESTAMP` at
`:1152`, which is ONE SECOND of resolution, so two reads of one record inside the same
second are indistinguishable by time and an auditor must order by the auto-increment
`id`. `rekam_medis_id` is `ON DELETE CASCADE` at `:1153`, so deleting a record erases
the evidence of every access to it - nothing in this application deletes a
`rekam_medis` row, but the trail is not tamper-evident against a privileged `DELETE`,
and only `audit_log` (`:1118`, whose `aksi` ENUM carries `read` and `delete`) is
append-only by construction. `pengakses_user_id` has NO `ON DELETE` clause at `:1154`,
so the row is RESTRICTed while the account exists - which means an account cannot be
deleted while anything it read is still in the log. (InnoDB creates an implicit index
for each foreign key, so the two `*_id` columns are indexed even though the DDL names
none; that is engine behaviour, not schema design, and it is not something a migration
should rely on.)

### Two of the five `tujuan_akses` values have no producer

`tujuan_akses` is `enum('perawatan','klaim','audit','pasien_sendiri','kepentingan_hukum')`
at `:1151`. `RekamMedisService` can produce three of them: `pasien_sendiri` for the
patient reading their own record, `perawatan` for the record's own doctor, and `audit`
for an `admin` or `superadmin`. `klaim` and `kepentingan_hukum` have **no producer in
this application**, and that is a `users.tipe` fact rather than an omission:
`RbacCatalog::USER_TYPES` holds the seven values of `:139` and not one of them is a
claims officer or a legal officer, so there is no account this application can
authenticate that would deserve either value. Adding one is a DDL change.

### `pasien_tanda_vital` is clinical and is NOT covered by the read guard

`pasien_tanda_vital` (`:312-330`) hangs off `rekam_medis` through
`fk_vital_rm` (`rekam_medis_id BIGINT UNSIGNED NULL`, added at `:1161-1163`), and its
`sumber` ENUM at `:325` is `('mandiri','dokter','perawat','iot_device')` - so the schema
gives a NURSE a clinical role here. `App\Models\PasienTandaVital` does **not** use
`GuardsMedicalRecordRead`, so a read of it is not gated by this todo's access log. That
is a scope decision, not an oversight: vital signs have their own access surface and
no endpoint in this plan reads them. It is recorded because the two facts together -
"a nurse writes clinical data" and "a nurse holds no role at all, so no `permission:`
can admit her" - are the sharpest available argument that `RbacCatalog` needs a
`perawat` role, and that is a data change in `app/Support/Rbac/` plus a re-seed rather
than a code change.

### `rekam_medis_lampiran.diunggah_oleh` has no foreign key

`diunggah_oleh BIGINT UNSIGNED NOT NULL` at `:687` and `:689` declares only the
`rekam_medis` foreign key, so the column is an unverified `users.id`. The service
writes it from the authenticated account for the reason
`booking.dibuat_oleh_user_id` is written the same way (todo 27), and the suite asserts
the absence of the foreign key so the choice is visible rather than assumed.

