# Todo 6 — information_schema parity verifier and schema-notes log

Branch: `feat/sehatly-telemedicine` · Base commit: `59f476c` (todo 3)
PHP used: **project-local** `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17),
prepended to `$env:PATH` for every command below. Bare `php` on PATH is 8.2.29 and fails
Laravel's `^8.3` platform check.
MySQL: 8.0.30 @ 127.0.0.1:3306.

---

## 0. Environment baseline (before any change)

```
$ php -v
PHP 8.4.17 (cli) (NTS x64) ... Zend Engine v4.4.17
$ php artisan --version
Laravel Framework 13.33.0
```

```
$ php <probe>   # read-only information_schema query
connection=mysql db=telemedisin_db
version=8.0.30
telemedisin_db => 0 objects:
telemedisin_db_test => 0 objects:
sehatly => 10 objects: cache(BASE TABLE), cache_locks(BASE TABLE), failed_jobs(BASE TABLE),
  job_batches(BASE TABLE), jobs(BASE TABLE), migrations(BASE TABLE), passkeys(BASE TABLE),
  password_reset_tokens(BASE TABLE), sessions(BASE TABLE), users(BASE TABLE)
sqlmd5=c76fafa884be523aa5d76b62b9a23377 bytes=59604
```

`telemedicine_test.sql`: 59 604 B, md5 `c76fafa884be523aa5d76b62b9a23377`, 1 349 lines, CRLF.
Per plan Appendix A.3 the committed blob is LF-normalised, so the only valid
"unchanged" check is `git diff --exit-code telemedicine_test.sql` (used throughout below).

`bootstrap/cache/` contained only `.gitignore`, `packages.php`, `services.php` — **no
`config.php`**, before and after.

`git status --porcelain` on arrival (all pre-existing, none mine):

```
 M .omo/plans/sehatly-telemedicine-platform.md      <- ORCHESTRATOR's
?? .omo/evidence/task-3-sehatly.md                   <- todo-3 executor's, untracked
?? .omo/start-work/                                  <- ORCHESTRATOR's
```

---

## 1. What was built

| Path | Role |
| --- | --- |
| `app/Console/Commands/VerifySchemaParity.php` | `sehatly:verify-schema` — options `--tables=`, `--sql=`, `--notes=`, `--json`; exit 0 / 1 / 2 |
| `app/Support/Schema/SqlSchemaParser.php` | One grammar for **both** sides: the hand-written reference DDL and live `SHOW CREATE TABLE` output |
| `app/Support/Schema/TypeNormaliser.php` | Canonicalises types, defaults and expressions |
| `app/Support/Schema/LiveSchemaReader.php` | Reads `information_schema.{TABLES,VIEWS,COLUMNS,STATISTICS,REFERENTIAL_CONSTRAINTS,TABLE_CONSTRAINTS}` + `SHOW CREATE TABLE` |
| `app/Support/Schema/SchemaDiffer.php` | The diff and the pass/fail decision |
| `app/Support/Schema/ExtraTableRegistry.php` | Reads the extra-table registry out of `docs/schema-notes.md` |
| `app/Support/Schema/{SchemaSpec,TableSpec,ColumnSpec,IndexSpec,ForeignKeySpec,CheckSpec,Discrepancy,SchemaParseException}.php` | Immutable model + typed parse error |
| `docs/schema-notes.md` | The running log of every table the migrations have that the SQL does not |
| `tests/Unit/Schema/SqlSchemaParserTest.php` | Parser coverage + malformed input |
| `tests/Unit/Schema/SchemaDifferTest.php` | Drift detection **and** no-false-positive normalisation |
| `tests/Unit/Console/VerifySchemaCommandTest.php` | Command contract, read-only proof, idempotency |

Supporting classes live in `app/Support/Schema/` (an existing project convention — todo 3
put `app/Support/ApiResponse.php` there) so the command file stays a command. Nothing in the
task's OUT OF SCOPE list was touched.

**Read-only, by construction.** The only thing that reaches the server is `DB::select()`
against `information_schema` or `SHOW CREATE TABLE`, against `DB::connection()->getDatabaseName()`
— never a hard-coded name. No `migrate`, no DDL, no DML, no import of the SQL file.
`VerifySchemaCommandTest::the verifier is read-only` asserts this at source level (forbidden
tokens absent, every `DB::select(` verb is `select`/`show`) and again at runtime (a fingerprint
of `telemedisin_db` + `telemedisin_db_test` + `sehatly` is byte-identical across two runs).

---

## 2. DEFERRED — recorded as deferred, not failed

Per plan **Appendix A.6** (and the task's rescoping), the following are **not achievable at
todo 6's position** and are owned by **todo 18**, the last todo to create schema:

| Deferred item | Owner | Why |
| --- | --- | --- |
| Criterion 1 — `php artisan sehatly:verify-schema` exits 0 printing **"75 tables, 2 views verified"** on a fully-migrated database | todo 18 | No schema exists yet. Todos 7–18 author the 75 migrations; nothing is migrated. |
| The plan's happy-path QA — `php artisan migrate:fresh --seed && php artisan sehatly:verify-schema` exits 0 | todo 18 | Same. Also the manual checkpoint the plan mandates after Wave 2. |
| The plan's literal failure QA — "hand-edit one generated migration to drop `->unsigned()` from a `TINYINT` column" | **substituted, see §6** | At todo 6 time **no such migration exists** (todos 7–12 have not run, and none of the five scaffold migrations has a TINYINT column). Appendix A.4 mandates the substitution; A.4 also forbids fabricating the edit on a nonexistent file. |

**This is a plan-sequencing defect, not an executor defect.** A.6 says so explicitly:
*"Do not let a verifier fail either todo for a criterion that its position makes impossible."*

---

## 3. C2 — the verifier exits 1 and names every offending table

```
$ php artisan sehatly:verify-schema
EXITCODE=1

 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (10 registered extra tables)
 scope all expected tables

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: booking.status (515-516),
   home_care_pesanan.status (1104-1105), invoice.status (947-948), klaim_bpjs.status (1022-1023),
   konsultasi.status (542-543), konsultasi_chat.tipe_pesan (568-569), lab_permintaan.status (884-885),
   master_obat.bentuk_sediaan (713-714), persetujuan_pdp.jenis (1137-1138),
   pesanan_obat.status (810-811), resep.status (751-752)
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on pasien_tanda_vital (line 1161)

 Live schema
 counts tables=0 views=0 columns=0 indexes=0 foreign_keys=0 checks=0
 information_schema columns=0 indexes=0 foreign_keys=0 checks=0

 Discrepancies: 77 (77 drift, 0 informational)
 missing_table akses_rekam_medis_log expected: 5 columns, 1 indexes, 2 foreign keys, 0 checks | actual: -
 missing_table apotek_stok expected: 8 columns, 2 indexes, 2 foreign keys, 0 checks | actual: -
 missing_table artikel expected: 14 columns, 2 indexes, 2 foreign keys, 0 checks | actual: -
 missing_table artikel_kategori expected: 3 columns, 2 indexes, 0 foreign keys, 0 checks | actual: -
 missing_table audit_log expected: 11 columns, 3 indexes, 0 foreign keys, 0 checks | actual: -
 missing_table booking expected: 22 columns, 4 indexes, 6 foreign keys, 0 checks | actual: -
 missing_table dokter expected: 23 columns, 4 indexes, 1 foreign keys, 0 checks | actual: -
 missing_table dokter_faskes expected: 4 columns, 1 indexes, 2 foreign keys, 0 checks | actual: -
 missing_table dokter_jadwal expected: 14 columns, 2 indexes, 2 foreign keys, 0 checks | actual: -
 missing_table dokter_libur expected: 4 columns, 1 indexes, 1 foreign keys, 0 checks | actual: -
 missing_table dokter_pendidikan expected: 5 columns, 1 indexes, 1 foreign keys, 0 checks | actual: -
 missing_table dokter_spesialisasi expected: 4 columns, 2 indexes, 2 foreign keys, 0 checks | actual: -
 missing_table faskes expected: 20 columns, 4 indexes, 3 foreign keys, 0 checks | actual: -
 missing_table faskes_layanan expected: 6 columns, 1 indexes, 1 foreign keys, 0 checks | actual: -
 missing_table home_care_pesanan expected: 14 columns, 2 indexes, 3 foreign keys, 0 checks | actual: -
 missing_table invoice expected: 15 columns, 4 indexes, 1 foreign keys, 0 checks | actual: -
 missing_table klaim_bpjs expected: 15 columns, 3 indexes, 0 foreign keys, 0 checks | actual: -
 missing_table konsultasi expected: 19 columns, 3 indexes, 3 foreign keys, 0 checks | actual: -
 missing_table konsultasi_chat expected: 11 columns, 2 indexes, 2 foreign keys, 0 checks | actual: -
 missing_table lab_hasil expected: 11 columns, 1 indexes, 2 foreign keys, 0 checks | actual: -
 missing_table lab_paket_item expected: 2 columns, 1 indexes, 2 foreign keys, 0 checks | actual: -
 ... (53 more named tables) ...
 missing_table users expected: 16 columns, 4 indexes, 0 foreign keys, 0 checks | actual: -
 missing_view v_dokter_katalog expected: view | actual: -
 missing_view v_pendapatan_bulanan expected: view | actual: -

 FAIL — 77 discrepancies. The live schema does not match telemedicine_test.sql. Nothing was written.
```

**77 = 75 named missing tables + 2 named missing views.** Not a count: every one is named.
`VerifySchemaCommandTest` asserts `substr_count($output, 'missing_table') === 75` and
`toMatch('/missing_table\s+<name>\b/')` for five representative tables.

### Machine-readable channel

```
$ php artisan sehatly:verify-schema --json
EXITCODE=1
ok=False exit_code=1 drift=77 total=78 db=telemedisin_db        # 78 against the throwaway DB; 77 here
{
 "ok": false,
 "exit_code": 1,
 "read_only": true,
 "reference": { "path": "telemedicine_test.sql", "bytes": 59604, "md5": "c76fafa884be523aa5d76b62b9a23377" },
 "live": { "driver": "mysql", "database": "telemedisin_db",
           "information_schema_counts": { "columns": 0, "indexes": 0, "foreign_keys": 0, "checks": 0 } },
 "notes_registry": { "path": "docs/schema-notes.md", "registered_extra_tables": 10 },
 "scope": "all expected tables",
 "expected": { "tables": 75, "views": 2, "columns": 672, "indexes": 142, "foreign_keys": 105, "checks": 3 },
 "live_model": { "tables": 0, "views": 0, "columns": 0, "indexes": 0, "foreign_keys": 0, "checks": 0 },
 "multi_line_column_declarations": [ { "table": "booking", "column": "status", "line": 515, "end_line": 516 }, ... ],
 "discrepancy_count": 77,
 "drift_count": 77,
 "discrepancies": [ { "kind": "missing_table", "table": "akses_rekam_medis_log", ... }, ... ]
}
```

### Narrowed scope (the option todos 7–17 need)

```
$ php artisan sehatly:verify-schema --tables=master_provinsi
EXITCODE=1
 scope master_provinsi
 Discrepancies: 1 (1 drift, 0 informational)
 missing_table master_provinsi expected: 3 columns, 2 indexes, 0 foreign keys, 0 checks | actual: -
```

---

## 4. C3 — `docs/schema-notes.md`

Full contents are in §9. The verifier **enforces** it rather than merely hosting it:

- an extra table listed in the registry → reported as `documented_extra_table`, **informational**,
  does not fail the run;
- an extra table **missing** from the registry → `undocumented_extra_table`, **drift**, exit 1;
- a registry entry for a table that no longer exists → informational, so todo 7 can regenerate
  the file after A.4's deletions without the verifier complaining;
- a missing or unparseable registry → **exit 2**, not a silent green.

10 registered extra tables, each with a one-line justification: `migrations`, `cache`,
`cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `passkeys`, `personal_access_tokens`,
`password_reset_tokens`, `sessions`. Plus three registered extra **columns**
(`users.two_factor_*`). `users` itself is deliberately **not** registered — it is one of the
75 tables, so the scaffold migration that also creates it is a *collision*, tracked in a
separate "Pending removal in todo 7" section per Appendix A.4.

`VerifySchemaCommandTest::the extra-table registry is parsed from docs/schema-notes.md` pins the
exact set of 10 and asserts `users` is absent.

---

## 5. C4 — the `unsigned`-mismatch unit test, and every other drift class

```
$ php artisan test --filter="SqlSchemaParserTest|SchemaDifferTest|VerifySchemaCommandTest"
EXITCODE=0
{"tool":"pest","result":"passed","tests":64,"passed":64,"assertions":303,"duration_ms":2681}
```

### C4 itself

`SchemaDifferTest::C4: a deliberate unsigned mismatch is reported, naming the column` parses two
DDL snippets — `TINYINT UNSIGNED` vs MySQL-rendered `tinyint NOT NULL` — and asserts:

```
kind     = column_unsigned
table    = master_provinsi
column   = id
expected = unsigned
actual   = signed
drift    = true
```

### All seven required detections

| Class | Test | Reported as |
| --- | --- | --- |
| type change | `a type change is reported` (`char(2)` → `char(3)`) | `column_type` |
| unsigned flip | `C4: a deliberate unsigned mismatch is reported` | `column_unsigned` |
| nullability change | `a nullability change is reported` | `column_nullable` |
| default change | `a default change is reported` / `dropping a DEFAULT from a NOT NULL column` | `column_default` |
| missing index | `an index that simply is not there is reported` | `missing_index` |
| extra column | `an extra column is reported` | `extra_column` |
| missing table | `a missing table is reported` | `missing_table` |

Plus: `missing_primary_key`, `extra_index`, `index_columns`, `index_name`, `missing_foreign_key`,
`extra_foreign_key`, `foreign_key_action`, `missing_check`, `extra_check`,
`column_auto_increment`, `table_engine`, `undocumented_extra_table`, `missing_view`, `extra_view`,
`unknown_requested_table`, `live_source_mismatch`.

### The normalisation trap — over-normalising would be equally broken

`SchemaDifferTest::cosmetic differences are NOT reported as drift` is a **4-case dataset that
must produce an empty discrepancy list** against a real MySQL-style `SHOW CREATE TABLE` body:
backticks + `KEY` for `INDEX` + lower case + `current_timestamp()` + `COLLATE`; a
`users_email_unique` index name; `bigint(20)` display width; an engine-generated
`users_ibfk_1` foreign-key name. Plus a fifth case proving `ON DELETE SET NULL` does not trip
the swallowed-body guard, and `an explicit DEFAULT NULL in the DDL is still parsed as a
default, not as absence`.

And the E2E run in §6 proves the same thing against a table **MySQL actually built**, not a
fixture: the throwaway migration's `$table->char('kode', 2)->unique()` produced
``UNIQUE KEY `master_provinsi_kode_unique` (`kode`)`` and ``COLLATE utf8mb4_unicode_ci`` on both
columns — **zero** false positives.

---

## 6. E2E drift proof — the plan's failure QA, done the only honest way

The plan says: *"hand-edit one generated migration to drop `->unsigned()` from a `TINYINT`
column, re-run the verifier, assert it exits 1 naming that exact column, then restore."*
At todo 6 **no such migration exists**. Appendix A.4 redirects: *"add a **throwaway** migration
containing a `TINYINT` column, running the verifier, confirming it exits 1 naming that exact
column, then removing the throwaway."*

The task adds a hard constraint: **do not run it against `telemedisin_db` or
`telemedisin_db_test`; they must stay at 0 tables.** Both were honoured by putting the probe in a
**third, throwaway database** — neither of the two protected databases nor `sehatly` nor any of
the ~10 unrelated application databases was written to at any point.

### Step 1 — the throwaway migration (exactly the drift the plan names)

`database/migrations/2026_09_27_000000_throwaway_drift_probe.php`:

```php
Schema::create('master_provinsi', function (Blueprint $table) {
    $table->tinyInteger('id')->primary()->autoIncrement();   // SIGNED — the SQL says UNSIGNED
    $table->char('kode', 2)->unique();
    $table->string('nama', 100);
});
```

`telemedicine_test.sql:58-62` declares `id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT`.

### Step 2 — create the throwaway database

```
$ php throwaway_db.php create
created sehatly_todo6_driftprobe
EXIT=0
```

### Step 3 — run ONLY the throwaway migration, into the throwaway database

```
$env:DB_DATABASE = "sehatly_todo6_driftprobe"
$ php artisan migrate --path=database/migrations/2026_09_27_000000_throwaway_drift_probe.php --force
EXIT=0
 INFO Preparing database.
 Creating migration table .. 126.59ms DONE
 INFO Running migrations.
 2026_09_27_000000_throwaway_drift_probe .. 79.46ms DONE
```

`telemedisin_db` and `telemedisin_db_test` were **not** the `DB_DATABASE` for this command and
were not touched.

### Step 4 — what MySQL actually built

```
sehatly_todo6_driftprobe => 2 objects: master_provinsi(BASE TABLE) migrations(BASE TABLE)
--- master_provinsi ---
CREATE TABLE `master_provinsi` (
  `id` tinyint NOT NULL AUTO_INCREMENT,
  `kode` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `master_provinsi_kode_unique` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### Step 5 — the proof (narrow scope, so the signal is unambiguous)

```
$env:DB_DATABASE = "sehatly_todo6_driftprobe"
$ php artisan sehatly:verify-schema --tables=master_provinsi
EXITCODE=1

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / sehatly_todo6_driftprobe
 scope master_provinsi

 Live schema
 counts tables=2 views=0 columns=6 indexes=3 foreign_keys=0 checks=0
 information_schema columns=6 indexes=3 foreign_keys=0 checks=0

 Discrepancies: 1 (1 drift, 0 informational)
 column_unsigned master_provinsi.id expected: unsigned | actual: signed

 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.
```

**Exit 1, naming the exact TINYINT column (`master_provinsi.id`), with `unsigned` vs `signed`.**
This is the plan's failure QA satisfied end-to-end through the real live path — real
`information_schema`, real `SHOW CREATE TABLE`, real differ.

### Step 6 — full scope, machine-readable

```
$ php artisan sehatly:verify-schema            # against the throwaway database
EXITCODE=1
 Discrepancies: 78 (77 drift, 1 informational)
 column_unsigned      master_provinsi.id  expected: unsigned | actual: signed
 documented_extra_table migrations         expected: Laravel's migration ledger; ... | actual: present in the live schema
```

```
$ php artisan sehatly:verify-schema --json     # against the throwaway database
EXITCODE=1
ok=False exit_code=1 drift=77 total=78 db=sehatly_todo6_driftprobe
[ { "kind": "column_unsigned", "table": "master_provinsi", "column": "id",
    "expected": "unsigned", "actual": "signed", "drift": true },
  { "kind": "documented_extra_table", "table": "migrations", "column": null,
    "expected": "Laravel's migration ledger; the framework refuses to run without it and it holds no domain data.",
    "actual": "present in the live schema", "drift": false } ]
```

Note the shape of the result: the drift is 1, the Laravel-style index name is **not** a false
positive, `migrations` is forgiven because `docs/schema-notes.md` registers it, and the
`information_schema` cross-check agrees with `SHOW CREATE TABLE` (6 columns, 3 indexes).

### Step 7 — both artefacts removed (see §8 for receipts)

**No substitution was needed.** The E2E proof is real: the verifier read a genuinely drifted
MySQL table and named the column.

---

## 7. Adversarial results

### `misleading_success_output` — APPLIES, probed three ways

1. **Zero-match filter.** `php artisan test --filter="ThisTestNameCannotPossiblyExistZzz9"`
   → `No tests found.`, `tests=0`, **EXITCODE=1**. A zero-match is *not* green on this harness.
2. **Real test count, from a JUnit file outside the repo:**
   `php artisan test --filter="..." --log-junit=C:\Users\axioo\AppData\Local\Temp\opencode\todo6-junit.xml`
   → `tests=60 assertions=299 failures=0 errors=0` (64 after two adversarial-driven additions).
   The JUnit file was deleted afterwards; it was never inside the repository.
3. **The parser cannot be vacuous.**
   - `SqlSchemaParser::parse()` **throws** if it finds 0 `CREATE TABLE` statements
     (`'Parsed 0 CREATE TABLE statements from the reference DDL; refusing to report parity.'`),
     so "parsed nothing, therefore no drift" is unreachable.
   - A test asserts the exact expected model: **75 tables, 2 views, 672 columns, 142 indexes,
     105 foreign keys, 3 checks**, all 75 `ENGINE=InnoDB`, plus per-table spot checks
     (`booking` = 22 columns / 4 indexes / 6 FKs) so a table shell cannot pass.
   - A test asserts the whole report names 75 missing tables.

### `misleading_success_output`, second axis — the normalisation trap: APPLIES, probed

Seven detection tests (§5) plus a 4-case no-false-positive dataset (§5) plus the E2E run (§6)
against a MySQL-built table. The differ is proven to be strict and forgiving in the right places.

Two real normalisation rules were discovered by these tests and are now load-bearing:

- **Laravel's `nullable()` emits `DEFAULT NULL`.** `ColumnDefinition::getAttributes()` adds
  `default => null` for a nullable column, so every `$table->x()->nullable()` renders
  `DEFAULT NULL` while `telemedicine_test.sql` usually writes a bare `NULL`. Failing to fold
  these would have reported **every nullable column in the schema** as drift and made todo 18's
  green impossible. Rule: *on a nullable column, "no DEFAULT clause" ≡ `DEFAULT NULL`.* The
  **model still distinguishes them** (a test asserts `users.dihapus_at->default === 'NULL'`
  while `users.nama_lengkap->default === null`); the fold is a comparison rule, not a parser one.
  `information_schema` cannot tell them apart either, which is why folding is correct rather
  than convenient.
- **A `PRIMARY KEY` column is implicitly `NOT NULL`.** `telemedicine_test.sql:59` writes no
  `NOT NULL` on `master_provinsi.id`; MySQL prints one. Without the implication, every
  single-column primary key in the schema would read as drift.

### `stale_state` — APPLIES, probed

```
$ Test-Path bootstrap\cache\config.php
False                                  # before
$ php artisan config:clear
 INFO Configuration cache cleared successfully.      EXIT=0
$ Test-Path bootstrap\cache\config.php
False                                  # after
$ php artisan sehatly:verify-schema --tables=master_provinsi
EXITCODE=1                             # re-verified after the clear
```

`bootstrap/cache/` holds only `.gitignore`, `packages.php`, `services.php` — **no `config.php`
is left behind.** The command resolves the database via `DB::connection()->getDatabaseName()`;
a test asserts the command source contains neither the literal `telemedisin_db` nor
`telemedisin_db_test`, and that `LiveSchemaReader` asked for a nonexistent database returns `[]`
rather than falling back. The E2E run proved the opposite direction too: setting
`$env:DB_DATABASE` genuinely moved the target, so the name really is read from configuration.

### `dirty_worktree` — APPLIES, probed

Pre-existing state found and left alone (see §0). Every commit used explicit pathspecs;
`git diff --cached --name-only` was empty afterwards and `git show --name-only --format="" HEAD`
lists only todo-6 paths. No `git add -A`, no `git commit -a`, no stash, no reset, no push, no amend.

### `hung_or_long_commands` — APPLIES, **a real hang was found and fixed**

The first end-to-end run **hung indefinitely** (killed at 120 s) and the bug was isolated to
`master_provinsi`, the very first table. Root cause: `TypeNormaliser::readQuoted()` returned the
new offset but every scanner passed `$i` **by value**, so `$i` never advanced past an opening
quote and the loop spun forever. Two follow-on variants of the same class were found and fixed
in the same pass — a `for` loop that consumed the character *after* a literal (which is why the
comma following `COMMENT 'bcrypt/argon2'` was skipped and `users` parsed as 4 columns), and a
`tokenise()` word loop that could not advance on a stray `)`. `readQuoted()` now takes `&$i` and
the scanners were made uniform.

After the fix, every long command was run inside a `Start-Job` with an **explicit timeout**
(45–200 s). Observed wall times: `verify-schema` well under 2 s, the 64-test suite ~2.7 s.
`mysqld` (17484/19796) and the user's `php artisan serve` (22288) were never signalled.

### `repeated_interruptions` — APPLIES, probed

The command is read-only, so an interrupt cannot corrupt data. Two properties verified:
`running the verifier changes nothing in the database, and is idempotent` runs the command twice
and asserts a fingerprint of all three protected databases is unchanged; and
`telemedicine_test.sql is byte-unchanged by running the verifier` compares md5 across a
human-mode and a `--json` run. `docs/schema-notes.md` is written once, in a single `write`, and
its content is reproduced verbatim in §9 so truncation would be visible.

**The procedure is idempotent.** Re-running the verifier cannot change the database: its only
statements are `SELECT` and `SHOW`.

### `malformed_input` — DO NOT rule out. `telemedicine_test.sql` IS the untrusted input here

**Awkward constructs survived** (each asserted by a test):

| Construct | Count | Evidence |
| --- | --- | --- |
| multi-line `ENUM`s | **11**, not 6 | see §8's correction below |
| inline `UNIQUE` (engine-named) | 37 | `users.email` → unnamed `UNIQUE (email)` |
| explicitly named keys | 30 | incl. all 11 the plan names |
| inline `CHECK`s | 3 | `rating/rating_komunikasi/rating_akurasi between 1 and 5`, in declaration order |
| reused index name `idx_icd10` | 2 tables | keyed on `(TABLE_NAME, INDEX_NAME)`; `master_icd10` → `INDEX (kode)`, `pasien_riwayat_penyakit` → `INDEX (icd10_kode)` |
| `ENUM` member case | — | `enum('A','B','AB','O')`, `enum('L','P')` preserved |
| comments, backticks, section markers | — | `--` with `;` and a fake `CREATE TABLE` inside, `#`, `/* … */`, backticked identifiers: all survive |
| `ALTER TABLE ADD CONSTRAINT` | 1 | `fk_vital_rm … ON DELETE SET NULL` folded into `pasien_tanda_vital` |
| `DEFAULT '%Y-%m'` inside a view body | 1 | `v_pendapatan_bulanan` — `%` and `,` inside a string do not break statement or definition splitting |

**A real gap was found by the malformed probe and fixed.** Removing one trailing comma from
`telemedicine_test.sql:133` did *not* error: `id` and `uuid` merged into one definition, `users`
silently lost a column, and the parser returned a **plausible, wrong model** — 75 tables,
671 columns, exit 1 with a misleading report. Two guards were added, both verified against the
real file (75 tables, 672 columns, zero false positives):

- `assertSingleDeclaration()` — a column definition may contain exactly **one** bare type name;
  a second means two declarations merged.
- `assertBodyIsOneTable()` — a table body may not contain an unquoted statement keyword
  (`CREATE`/`INSERT`/`DROP`/`ALTER`/`TRUNCATE`/`REPLACE`/`RENAME`). Quote-aware, because
  `telemedicine_test.sql:1121` legitimately contains `ENUM('create', …)`. `SET` is deliberately
  excluded because `ON DELETE SET NULL` is legitimate.

After the fix the same malformed file errors **clearly**:

```
$ php artisan sehatly:verify-schema --sql=<malformed copy>
EXITCODE=2
 ERROR verify-schema could not run: Column id in table users contains a second type name, so
 two declarations were merged into one. A trailing comma is almost certainly missing. (line 133).

$ php artisan sehatly:verify-schema --json --sql=<malformed copy>   # --json form
EXITCODE=2
{ "ok": false,
  "error": "Column id in table users contains a second type name, so two declarations were merged into one. A trailing comma is almost certainly missing. (line 133)",
  "error_type": "App\\Support\\Schema\\SchemaParseException" }
```

Other malformed inputs asserted to throw, never to pass: no `CREATE TABLE` at all;
unterminated table body; column with no type; unterminated string literal; `ALTER TABLE` against
an unknown table; an `ALTER` clause the parser does not understand (`DROP COLUMN`); a duplicate
`CREATE TABLE`; an empty `ENUM()`; a missing/empty reference file; a missing/empty registry.

### `prompt_injection` — N/A, ruled out with a reason

`telemedicine_test.sql` is first-party local data, not instructions. A scan of all 40 `COMMENT`
literals, 15 `INSERT` seed blocks and 2 view bodies found **zero** strings that read as
instructions to an agent, and **zero** occurrences of `--`, `#`, `/*`, `*/`, `\` or `;` inside
any quoted literal (so comment stripping cannot be subverted by the data). Nothing in the file
was acted on as an instruction; it was parsed as SQL and nothing else.

### `cancel_resume` — N/A: no resumable user flow at this todo (todos 20/45/46 own those).

### `flaky_tests` — N/A: fully deterministic, no clock, no randomness, no network, no ordering
dependence. The only environment coupling is the MySQL 8 test database, and every test that
touches it only reads. The zero-match-filter risk is probed and ruled out above.

### Plan correction found: there are **11** multi-line `ENUM`s, not 6

The task brief lists 6 (`booking` :515, `konsultasi` :542, `konsultasi_chat` :568,
`master_obat` :713, `resep` :751, `persetujuan_pdp` :1137). The parser's own line-span accounting
finds **11**, and every one is handled as a single unit:

```
booking.status 515-516 · home_care_pesanan.status 1104-1105 · invoice.status 947-948
klaim_bpjs.status 1022-1023 · konsultasi.status 542-543 · konsultasi_chat.tipe_pesan 568-569
lab_permintaan.status 884-885 · master_obat.bentuk_sediaan 713-714
persetujuan_pdp.jenis 1137-1138 · pesanan_obat.status 810-811 · resep.status 751-752
```

Five were missed because their continuation line does not begin with `NOT NULL` — `pesanan_obat`
:810, `lab_permintaan` :884, `klaim_bpjs` :1022, `home_care_pesanan` :1104 continue with
`NOT NULL` on the next line but a `^\s+NOT NULL` grep finds only 6 of them because the *first*
line of each also ends without a comma, and `invoice.status` :947-948 continues with a quoted
value (`'kedaluwarsa','dibatalkan'`). **Correction for whoever wrote the brief: 11, not 6.**

---

## 8. Cleanup receipts

| Artefact | Action | Receipt |
| --- | --- | --- |
| `database/migrations/2026_09_27_000000_throwaway_drift_probe.php` | deleted | `Test-Path` → `False`; `database/migrations/` back to the original 6 files |
| database `sehatly_todo6_driftprobe` | **dropped** | `about to drop: sehatly_todo6_driftprobe (2 objects)` / `- master_provinsi` / `- migrations` / `after drop: 0 objects` |
| `telemedicine_test_CORRUPT.sql` (temp, outside repo) | deleted | `removed …` |
| `telemedicine_test_MALFORMED.sql` (temp, outside repo) | deleted | `removed …` |
| `todo6-junit.xml` (temp, outside repo) | deleted | `removed …` |
| 6 probe scripts (temp, outside repo) | deleted | `removed …` ×6 |
| `drop_throwaway.php` (temp, outside repo) | deleted | `removed …` |
| `bootstrap\cache\config.php` | never created | `Test-Path` → `False` before and after `config:clear` |

**Post-cleanup database state** (read-only query):

```
protected telemedisin_db            => 0 objects      <- still 0 tables
protected telemedisin_db_test       => 0 objects      <- still 0 tables
protected sehatly                   => 10 objects     <- untouched
protected db_simprapkl              => 26 objects
protected gawaiseken               => 18 objects
protected manajemen-surat           => 0 objects
protected trading_journal           => 12 objects
protected ukk                       => 12 objects
protected ukk_pengaduan_sekolah     => 15 objects
protected laravel                   => 5 objects
protected information_schema        => 79 objects
protected mysql                     => 37 objects
protected performance_schema        => 111 objects
protected sys                       => 101 objects
```

`telemedicine_test.sql` integrity, the only valid check per Appendix A.3:

```
$ git diff --exit-code telemedicine_test.sql ; echo EXITCODE=$LASTEXITCODE
EXITCODE=0
$ (Get-FileHash telemedicine_test.sql -Algorithm MD5).Hash
C76FAFA884BE523AA5D76B62B9A23377     # identical before and after every probe
```

---

## 9. Full contents of `docs/schema-notes.md`

````markdown
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
  default. `DEFAULT NULL` and "no DEFAULT clause" stay distinct in the model, but on
  a **nullable** column the two are equivalent for comparison — MySQL's implicit
  default for a nullable column is NULL and `information_schema` cannot tell them
  apart, and Laravel's `$table->x()->nullable()` emits `DEFAULT NULL` where
  `telemedicine_test.sql` usually writes a bare `NULL`. Without that fold every
  nullable column in the schema would read as drift.
- **Index names are compared only when the DDL wrote one.** An inline `UNIQUE`
  becomes index `email` in MySQL but `users_email_unique` in Laravel — the same
  constraint, two spellings, so uniqueness is compared as `NON_UNIQUE` semantics plus
  the ordered column list. The 30 explicitly named keys (`idx_jadwal`,
  `idx_booking_dokter`, `uq_interaksi`, `uq_stok`, `uq_consent`, `idx_faskes_geo`,
  `idx_icd10`, `idx_diag_icd10`, `idx_vital_pasien`, `idx_pasien_lahir`,
  `idx_spesialisasi`, …) *are* compared by name, keyed on
  `(TABLE_NAME, INDEX_NAME)` — `idx_icd10` is reused on two different tables
  (`telemedicine_test.sql:119` and `:297`), which is legal in MySQL.
- **Foreign keys are compared on their target first** (local columns, referenced
  table and columns) so a changed `ON DELETE` reads as `foreign_key_action` rather
  than as a delete plus an unrelated create. Names are only compared when the DDL
  wrote one, because an inline `FOREIGN KEY` is named `<table>_ibfk_<n>` by the
  engine. The one name the DDL writes — `fk_vital_rm` at
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

- **Storage engine, charset and collation.** The SQL sets `utf8mb4` /
  `utf8mb4_unicode_ci` once at database level (`telemedicine_test.sql:11-13`), not per
  table. The per-table `ENGINE=InnoDB` clause *is* compared, because every one of the
  75 statements carries it.
- **Column `COMMENT` text.** The 40 `COMMENT` clauses are documentation, not
  contract, and `telemedicine_test.sql:538` even stores the literal string
  `'NULL = ...'` inside one. The text is tokenised and removed before keywords are
  scanned, so it can never be mistaken for a clause.
- **View definitions.** The two views are compared by name and existence. MySQL
  rewrites `VIEW_DEFINITION` server-side (`information_schema.VIEWS`), so comparing
  the SQL text would be comparing against the server's own re-rendering rather than
  against the DDL.
- **Generated-column expressions and `SRID`.** `telemedicine_test.sql` declares
  neither; the parser tokenises them without comparing them.
````

---

## 10. Commands run, with exit codes

| # | Command | Exit |
| --- | --- | --- |
| 1 | `php artisan sehatly:verify-schema` | **1** |
| 2 | `php artisan sehatly:verify-schema --json` | **1** |
| 3 | `php artisan sehatly:verify-schema --tables=master_provinsi` | **1** |
| 4 | `php artisan sehatly:verify-schema --sql=<malformed copy>` | **2** |
| 5 | `php artisan sehatly:verify-schema --json --sql=<malformed copy>` | **2** |
| 6 | `php artisan config:clear` | 0 |
| 7 | `php artisan test --filter="SqlSchemaParserTest\|SchemaDifferTest\|VerifySchemaCommandTest"` | 0 |
| 8 | same + `--log-junit=<temp outside repo>` | 0 (`tests=60 assertions=299 failures=0 errors=0`) |
| 9 | `php artisan test --filter="ThisTestNameCannotPossiblyExistZzz9"` | 1 (`No tests found.`, `tests=0`) |
| 10 | `vendor\bin\pint` | 0 (`fixed`, 13 files) |
| 11 | `vendor\bin\pint --test` | 0 (`passed`) |
| 12 | suite re-run after pint | 0 (`tests=64 assertions=303`) |
| 13 | `git diff --exit-code telemedicine_test.sql` | 0 |
| 14 | `DB_DATABASE=sehatly_todo6_driftprobe php artisan migrate --path=… --force` | 0 |
| 15 | `DB_DATABASE=sehatly_todo6_driftprobe php artisan sehatly:verify-schema --tables=master_provinsi` | **1** |
| 16 | `DB_DATABASE=sehatly_todo6_driftprobe php artisan sehatly:verify-schema` | **1** |
| 17 | `DB_DATABASE=sehatly_todo6_driftprobe php artisan sehatly:verify-schema --json` | **1** |

## 11. Committed paths

```
app/Console/Commands/VerifySchemaParity.php
app/Support/Schema/CheckSpec.php
app/Support/Schema/ColumnSpec.php
app/Support/Schema/Discrepancy.php
app/Support/Schema/ExtraTableRegistry.php
app/Support/Schema/ForeignKeySpec.php
app/Support/Schema/IndexSpec.php
app/Support/Schema/LiveSchemaReader.php
app/Support/Schema/SchemaDiffer.php
app/Support/Schema/SchemaParseException.php
app/Support/Schema/SchemaSpec.php
app/Support/Schema/SqlSchemaParser.php
app/Support/Schema/TableSpec.php
app/Support/Schema/TypeNormaliser.php
docs/schema-notes.md
tests/Unit/Console/VerifySchemaCommandTest.php
tests/Unit/Schema/SchemaDifferTest.php
tests/Unit/Schema/SqlSchemaParserTest.php
.omo/evidence/task-6-sehatly.md
```

Commit message: `feat(dev): add information_schema parity verifier and schema-notes log`
