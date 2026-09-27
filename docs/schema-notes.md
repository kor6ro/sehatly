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
  as informational, so this file can be trimmed when a migration is removed;
- a **deliberately deferred constraint** listed in the *Deferred constraints* table
  below is informational, the same three rules apply to it in reverse, and a
  constraint that is listed there but has since been created is **drift** — the
  deferral is over and the row has to go.

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
and every cell on one line. Each registry parses **only the rows under its own
heading** (`ExtraTableRegistry::HEADING` and
`DeferredConstraintRegistry::HEADING`, both resolved by `SchemaNotesSection`), so
the two tables below can never read each other's rows — but keeping each table's
rows inside its own section is still what makes that true.

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

## Deferred constraints

A **deliberately not-yet-created** constraint, registered so the verifier reports it
as informational instead of drift. The verifier parses this table exactly as it
parses the extra tables above, and enforces three rules:

- a `missing_foreign_key` listed here is **informational**, not drift — but it is
  still printed by name, so an outstanding deferral is always visible in the report;
- a constraint listed here that is **present in the live schema is `DRIFT`**. The
  deferral has been fulfilled, so the row is stale and has to go in the same commit
  that adds the constraint. Without this rule a row here could excuse the
  constraint forever, and the "75 tables, 2 views verified" run would pass with the
  foreign key still absent;
- a `missing_foreign_key` **not** listed here stays **drift**, exactly as before. A
  row forgives one named constraint and nothing else.

Keyed on the **constraint name**, not on table + column: `fk_vital_rm` is the only
name the DDL itself writes for a foreign key, so it is the only stable handle on
"this specific constraint is deferred", and a table + column key would forgive
*any* foreign key on that column. An inline `FOREIGN KEY` is named
`<table>_ibfk_<n>` by the engine, so an engine-named key can never be registered.

The registry is **mandatory** — a missing or empty one makes the verifier exit `2`
rather than pass — and `--notes=<path>` points both registries at a different file
for testing. When the last deferral is resolved, the correct end state is to delete
this section **and** its `DeferredConstraintRegistry::fromMarkdown()` call
together; leaving an empty section behind would exit `2` forever.

| Constraint | Table | Added by | Justification |
| --- | --- | --- | --- |
| `fk_vital_rm` | `pasien_tanda_vital` | `2026_10_01_000076` | Column `rekam_medis_id` is present; the FK is added by migration 76 per SQL section `[14]` (`:1161-1163`) because `rekam_medis` does not exist until batch G (todo 13). Full reasoning in *Batch-C deferred and deliberately unconstrained columns* below. |

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
  deferred to migration `2026_10_01_000076` per the SQL's section `[14]`
  (`:1161-1163`)**, which adds `CONSTRAINT fk_vital_rm … ON DELETE SET NULL`. The
  deferral is a dependency ordering, not a choice: `rekam_medis` is SQL table 42
  (batch G, todo 13) and this column is created at position 25, so declaring the
  constraint here fails `migrate:fresh` with MySQL 1824. Until todo 18 the column
  has **no** row in `information_schema.REFERENTIAL_CONSTRAINTS`; that absence is
  the correct state, and adding the constraint early is drift
  (`extra_foreign_key`). **This one is machine-readable**: it is the single row of
  the *Deferred constraints* table above, which is what makes the verifier report
  it as informational instead of failing the run.
- `pasien_penjamin.faskes_rujukan_id` (`:346`) — **column present as a nullable
  unsigned `BIGINT` with NO foreign key, and none is owed.** The plan's
  authoritative no-foreign-key list (line 152) records `:346` as carrying none, and
  live measurement agrees: the only constraint this batch defers is `fk_vital_rm`.
  An earlier revision of this file called it an "FK to `faskes` deferred to
  migration 76" — **that was wrong**; the prose lost to the authoritative list.
  So leave it a bare unsigned `BIGINT`, do not widen it to `foreignId()` semantics,
  and do **not** register it as deferred: registration would be a promise to create
  a constraint the DDL never declares, and migration 76 must not add one.
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
than pinned. The deferred-constraint registry is derived the same way and re-proved
on every run by `tests/Unit/Schema/SchemaDifferDeferredConstraintTest.php` and
`tests/Unit/Console/VerifySchemaDeferredConstraintTest.php`.

## Batch-D schema facts the database cannot enforce (todo 10)

Batch D (`faskes`, `faskes_layanan`, `master_spesialisasi`, `dokter`,
`dokter_spesialisasi`, `dokter_faskes`, `dokter_pendidikan` — SQL tables 28–34) is
at parity and **owes no deferred constraint**: all ten of its foreign keys point at
a table that already exists by the time its own migration runs
(`master_provinsi` / `master_kabupaten_kota` / `master_kecamatan` from batch A,
`users` from batch B, and `faskes` / `dokter` / `master_spesialisasi` from inside
the batch), so the *Deferred constraints* table above is unchanged and still holds
exactly one row. Four facts are still worth carrying forward, because each looks
like something the schema guarantees and is not.

- **`pasien_penjamin.faskes_rujukan_id` remains unconstrained now that `faskes`
  exists.** Its bareness was never an ordering artefact, and after this batch that
  argument is gone entirely: `faskes` is table 28, immediately after the batch-C
  migration that created the column. Appendix A.10 / A.11 settled it and the DDL
  declares no `FOREIGN KEY` for it (`:346`); adding one now is
  `extra_foreign_key` drift. **Migration `2026_10_01_000076` must not add it.**
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
earlier in the same commit), so the *Deferred constraints* table above is unchanged
and still holds exactly one row. Four facts are still worth carrying forward,
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
batch), so the *Deferred constraints* table above is unchanged and still holds
exactly one row (`fk_vital_rm`, added by migration 76). Five facts are still
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
  `extra_foreign_key` drift. **Migration `2026_10_01_000076` must not add it.**
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
  `2026_10_01_000076` must not add it either.
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
so the *Deferred constraints* table above is unchanged and still holds exactly
one row (`fk_vital_rm`, added by migration 76 per SQL section `[14]`, `:1161-1163`).
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

  **Migration `2026_10_01_000076` must not add a constraint to any of the four.**
  None of them is in the *Deferred constraints* registry and none should be:
  registration would promise a constraint the DDL never declares.
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
