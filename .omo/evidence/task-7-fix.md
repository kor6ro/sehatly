# Task 7 post-verification fixes — evidence

Plan: `.omo/plans/sehatly-telemedicine-platform.md`, todo 7, as corrected by
**Appendix A.8** (which names both defects below). Branch: `feat/sehatly-telemedicine`.
Under review: `c6d0beb` (todo 7 itself), with `27c6ca8` on top of it.

PHP: `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` = **8.4.17** (project-local).
Every command below was run with that first on `PATH`:

```
$env:PATH = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64;$env:PATH"
```

MySQL **8.0.30**. No `migrate`, `migrate:fresh`, `migrate:rollback`, `db:seed` or `db:wipe`
was run against any database at any point in this task. Every database interaction was a
`SELECT` against `information_schema`.

---

## 0. Headline

| Item | Result |
| --- | --- |
| Defect 1 — red Unit suite from a hard-coded 10-entry registry pin | **FIXED**, and the expectation is now derived from the live migration set. `php artisan test tests/Unit` **exit 0, 73/73** (was 72/73, exit 1) |
| Defect 1 — does the strengthened assertion actually bite? | **YES.** Proven in both directions (§3). A derived expectation that cannot detect a new migration would be worthless |
| Defect 2 — `docs/migration-order.md` contradicts the tree | **FIXED.** 6/6 rows now match disk + `git ls-tree HEAD` + the `migrations` ledger; 0 failures (§4) |
| Also-fix items 3-7 | 4 applied, 1 **ruled out with evidence** (item 3's premise is factually wrong), 2 applied to arithmetic (§5) |
| All 10 VERIFY items | **PASS**, every exit code observed and quoted (§6) |
| Databases | `telemedisin_db` 18, `telemedisin_db_test` 0, `sehatly` 10 — unchanged; `telemedicine_test.sql` byte-unchanged |

---

## 1. Defect 1 — root cause and causality proof

### Root cause

`c6d0beb` did two things that cannot be done independently:

1. **Correctly** regenerated `docs/schema-notes.md` to a **seven**-entry registry, as plan
   Appendix A.4 step 3 mandates, because it had just deleted three scaffold migrations
   (`passkeys`, `password_reset_tokens`, `sessions`).
2. **Did not touch `tests/` at all.**

`tests/Unit/Console/VerifySchemaCommandTest.php:204-207` therefore still pinned the
**pre-disposition** list of **ten** literals, read from the **real** `docs/schema-notes.md`
through `ExtraTableRegistry::fromMarkdown(base_path('docs/schema-notes.md'))`. A test that
derives its *input* from a live file but pins its *expectation* to a hard-coded array is
only green while both files agree — and `c6d0beb` deliberately broke that agreement. Plan
A.8's own conclusion: *"Every executor must run the affected suite before committing, not
only its own new tests."*

### Pre-fix baseline, observed

```
$ php artisan test tests/Unit
{"tool":"pest","result":"failed","tests":73,"passed":72,"assertions":304,"duration_ms":3181,
 "failed":1,"failures":[{"test":"P\Tests\Unit\Console\VerifySchemaCommandTest::
 __pest_evaluable_the_extra_table_registry_is_parsed_from_docs_schema_notes_md",
 "file":"\Users\axioo\Desktop\sehatly\tests\Unit\Console\VerifySchemaCommandTest.php",
 "line":204,"message":"Failed asserting that two arrays are equal.
--- Expected
+++ Actual
@@ @@
 'job_batches'
 'jobs'
 'migrations'
- 'passkeys'
- 'password_reset_tokens'
 'personal_access_tokens'
- 'sessions'
 }", ...}]}
EXITCODE=1
```

73 tests, 72 passed, **1 failed**. The only difference is the three deleted tables.

### The fix — derive, do not pin

The test keeps its name, its intent and its other assertions. Only the *source of the
expected set* changed:

```php
$contractTables = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))->tableNames();

$created = ['migrations'];   // Laravel's migration ledger, created by the migrator itself
$literalNames = [];
$files = glob(database_path('migrations').'/*.php');
$createCalls = 0;

expect($files)->toBeArray()->not->toBeEmpty();

foreach ($files as $file) {
    $code = (string) file_get_contents($file);

    $createCalls += preg_match_all('/Schema::create\s*\(/', $code);
    preg_match_all("/Schema::create\s*\(\s*'([A-Za-z0-9_]+)'/", $code, $matches);
    array_push($literalNames, ...$matches[1]);
}

// A `Schema::create($variable)` this walk cannot resolve would make the
// expectation silently incomplete, so refuse to pass rather than under-test.
$creators = array_values(array_filter(
    $files,
    fn (string $file): bool => str_contains((string) file_get_contents($file), 'Schema::create')
));
$creators = implode(', ', array_map('basename', $creators));

expect($literalNames)->toHaveCount(
    $createCalls,
    $creators.' must each pass a literal table name to Schema::create(), or this test is blind to them'
);

$created = array_values(array_unique([...$created, ...array_map('strtolower', $literalNames)]));

// Everything the migrations create that is not one of the 75 tables.
$expectedExtras = array_values(array_diff($created, $contractTables));
$registered = array_keys($entries);

expect($registered)->toEqualCanonicalizing($expectedExtras);

// Same assertion, but naming the offender so a failure is actionable.
expect(array_values(array_diff($expectedExtras, $registered)))->toBe(
    [], 'database/migrations/ creates tables docs/schema-notes.md does not register');
expect(array_values(array_diff($registered, $expectedExtras)))->toBe(
    [], 'docs/schema-notes.md registers tables no migration in database/migrations/ creates');
```

Three design points, each load-bearing:

- **Subtract the 75 contract tables.** Without this, todo 8's
  `2026_10_01_000012_users_table.php` would make the test demand a registry row for
  `users` — a false failure. The subtraction source is the reference model itself, not a
  second hard-coded list, so it cannot itself go stale.
- **The `migrations` ledger is added explicitly.** The migrator creates it; no migration
  file does. Without it the registry would appear one entry short.
- **The `toHaveCount($createCalls, …)` guard.** A migration that wrote
  `Schema::create($table)` would be *silently invisible* to the walk, and the test would
  pass while under-testing. The guard converts that blind spot into a loud failure that
  names the offending file.

Everything else in the test is preserved: the `toEqualCanonicalizing` comparison, the
per-entry justification loop, and the assertion that `users` is absent — the last now with
its reason spelled out (`expect($contractTables)->toContain('users')` proves the
subtraction, not just the absence).

### Post-fix

```
$ php artisan test tests/Unit
{"tool":"pest","result":"passed","tests":73,"passed":73,"assertions":325,"duration_ms":2592}
EXITCODE=0
```

**73 tests — identical to the pre-fix count.** That is the point: a path or filter mistake
that silently ran fewer tests would report a lower number and would look identical to a
green. Assertions rose 304 → 325, i.e. the test got *stronger*, not shorter.

---

## 2. Why not simply swap the array to seven literals

Because it fixes this instance and re-arms the trap. Plan A.8's requirement is that *"a
future migration add/remove is caught by the test rather than by a verifier"*, and A.7
records the consequence of getting this wrong: **a registered-but-absent extra table is
reported as `informational`, not drift** — so a registry row for a table whose migration was
deleted is never flagged by `verify-schema` at all. Seven literals would leave exactly that
hole open for todo 30 and every batch author after it.

---

## 3. Adversarial: `misleading_success_output` — the bite proof

The whole point of Defect 1 is that a green-*looking* test was not green. So the derived
assertion was attacked three ways, each with a throwaway artefact removed immediately.

### 3a. Adding a migration that creates a table → the test FAILS

`database/migrations/2026_10_02_999999_bite_probe_scaffold_table.php`, containing
`Schema::create('bite_probe_scaffold', …)`:

```
$ php artisan test --filter=VerifySchemaCommandTest
{"tool":"pest","result":"failed","tests":12,"passed":11,...,"failed":1,"failures":[{
 "test":"...the_extra_table_registry_is_parsed_from_docs_schema_notes_md",
 "line":245,"message":"Failed asserting that two arrays are equal.
--- Expected
+++ Actual
@@ @@
 Array (
- 'bite_probe_scaffold'
 'cache'
 'cache_locks'
 'failed_jobs'
 ... }]}
EXITCODE=1
```

The new table is named. Same 12 tests ran, so the failure is the assertion, not a filter.

### 3b. A migration whose table name is not a literal → the guard FIRES

`database/migrations/2026_10_02_999999_bite_probe_dynamic_table.php`, containing
`$table_name = 'bite_probe_dynamic'; Schema::create($table_name, …)`:

```
$ php artisan test --filter=VerifySchemaCommandTest
{"tool":"pest","result":"failed","tests":12,"passed":11,...,"failed":1,"failures":[{
 "line":234,
 "message":"0001_01_01_000001_create_cache_table.php, …, 2026_10_02_999999_bite_probe_dynamic_table.php
 must each pass a literal table name to Schema::create(), or this test is blind to them
Failed asserting that actual size 17 matches expected size 18."}]}
EXITCODE=1
```

Without this guard the test would have gone **green while blind**. It does not.

### 3c. A stale registry row → the test FAILS (the exact Defect-1 shape, inverted)

A `| \`passkeys\` | … | THROWAWAY PROBE ROW … |` line added to `docs/schema-notes.md`:

```
$ php artisan test --filter=VerifySchemaCommandTest
{"tool":"pest","result":"failed","tests":12,"passed":11,...,"failed":1,"failures":[{
 "line":245,"message":"Failed asserting that two arrays are equal.
--- Expected
+++ Actual
@@ @@
 'migrations'
+ 'passkeys'
 'personal_access_tokens'
 }]}
EXITCODE=1
```

This is the mirror image of the original defect — a registry entry no migration backs — and
it is now caught. Under the old 10-literal test it would also have been caught, but a
7-literal version would **not** have.

### 3d. All three throwaways removed, green restored

```
$ Get-ChildItem database\migrations -Filter "*bite_probe*" | Measure-Object | Select -Expand Count
0
$ git diff --stat -- docs/schema-notes.md database/
(no output — both files byte-identical to HEAD)
$ php artisan test tests/Unit
{"tool":"pest","result":"passed","tests":73,"passed":73,"assertions":325,"duration_ms":2592}
EXITCODE=0
```

---

## 4. Defect 2 — the self-contradicting contract file

### Root cause

`docs/migration-order.md` is the file the plan designates *"the single source of truth for
the whole schema effort"* for todos 8-17, and its pre-existing-migrations table is exactly
what a batch author consults to decide what exists. `c6d0beb` rewrote that table to record
two deletions and then **deleted a third migration without updating the row for it**:

- `:137` listed `2024_01_01_000000_create_passkeys_table.php` with Fate "Kept and
  registered, per the todo-7 decision recorded in `docs/schema-notes.md`" — while
  `docs/schema-notes.md:77-91` recorded the very same migration as **DELETED**. The file
  pointed at the note that contradicted it.
- `:142` said "All **four** surviving scaffolds sort before `2026_10_01_*`" when only
  **three** survive.

The cost is not cosmetic. A batch author trusting `:137` would re-add a migration whose
`foreignId('user_id')->constrained()` sorts at `2024_01_01_000000` — before every
`2026_10_01_*` — while `users` is not created until todo 8. That hard-fails `migrate:fresh`
with `SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'users'`, and
it would look like a todo-8 schema bug rather than a sequencing bug. Ten todos (8-17) carry
their own `migrate:fresh` acceptance criterion.

### Verified before editing — disk, `HEAD`, and the ledger

```
== telemedisin_db.migrations ledger ==
  0001_01_01_000001_create_cache_table
  0001_01_01_000002_create_jobs_table
  2026_09_26_222801_create_personal_access_tokens_table
  2026_10_01_000001_master_provinsi_table
  … (11 batch-A rows)
  — no passkeys row, no two_factor row, no create_users row —

== pre-todo-7 scaffolds, on disk vs HEAD ==
  0001_01_01_000000_create_users_table.php                    on-disk=NO   in-HEAD=NO   => DELETED
  0001_01_01_000001_create_cache_table.php                    on-disk=yes  in-HEAD=yes  => KEPT
  0001_01_01_000002_create_jobs_table.php                     on-disk=yes  in-HEAD=yes  => KEPT
  2024_01_01_000000_create_passkeys_table.php                 on-disk=NO   in-HEAD=NO   => DELETED
  2025_08_14_170933_add_two_factor_columns_to_users_table.php on-disk=NO   in-HEAD=NO   => DELETED
  2026_09_26_222801_create_personal_access_tokens_table.php   on-disk=yes  in-HEAD=yes  => KEPT
```

### The corrected table, as it now reads in `docs/migration-order.md`

| Migration | Tables | Fate |
| --- | --- | --- |
| `0001_01_01_000001_create_cache_table.php` | `cache`, `cache_locks` | **KEPT.** Legitimate extra, registered in `docs/schema-notes.md`. |
| `0001_01_01_000002_create_jobs_table.php` | `jobs`, `job_batches`, `failed_jobs` | **KEPT.** Legitimate extra, registered. |
| `2026_09_26_222801_create_personal_access_tokens_table.php` | `personal_access_tokens` | **KEPT.** Sanctum, published in todo 3, registered. |
| `0001_01_01_000000_create_users_table.php` | `users`, `password_reset_tokens`, `sessions` | **DELETED in todo 7** (plan Appendix A.4): it collides with row 12 `users`, and `password_reset_tokens` / `sessions` are not among the 75. |
| `2025_08_14_170933_add_two_factor_columns_to_users_table.php` | (adds `users.two_factor_*`) | **DELETED in todo 7** (plan Appendix A.4): `->after('password')` cannot resolve because the SQL's `users` has `kata_sandi_hash` and no `password`. |
| `2024_01_01_000000_create_passkeys_table.php` | `passkeys` | **DELETED in todo 7** — the decision plan Appendix A.4 explicitly left to todo 7, and the full reasoning is in `docs/schema-notes.md` ("Scaffold-migration disposition"). **Do not restore it.** It declares `foreignId('user_id')->constrained()->cascadeOnDelete()`, and it sorts at `2024_01_01_000000`, i.e. **before every `2026_10_01_*` row**, while `users` is not created until todo 8 (row 12). Re-adding the file therefore hard-fails `migrate:fresh` with `SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'users'` before a single batch-A table is created, breaking the `migrate:fresh` acceptance criterion of all ten of todos 8-17. `laravel/passkeys` stays in `composer.json` and `config/fortify.php` / `app/Providers/FortifyServiceProvider.php` keep their `passkeys` feature block until todo 30 removes the whole web-auth surface; nothing queries the table at boot, so its absence cannot affect `/api/v1`. |

The `KEPT` rows were also reordered so the three survivors precede the three deletions; the
count line now reads:

```
All **three** surviving scaffolds sort **before** `2026_10_01_000001`, so the order above is
unaffected: `0001_01_01_000001` < `0001_01_01_000002` < `2026_09_26_222801` < `2026_10_01_000001`.
```

The section gained a three-way re-check instruction (disk, `git ls-tree HEAD`, and the
`migrations` ledger) and a cross-reference to the derived registry, so the next scaffold
change has somewhere to look.

### VERIFY 10 — every row re-checked mechanically against disk, HEAD and the ledger

```
rows parsed from the contract table: 6

PASS  0001_01_01_000001_create_cache_table.php                 fate=KEPT     disk=yes  HEAD=yes  ledger=yes   -> actual KEPT
PASS  0001_01_01_000002_create_jobs_table.php                  fate=KEPT     disk=yes  HEAD=yes  ledger=yes   -> actual KEPT
PASS  2026_09_26_222801_create_personal_access_tokens_table.php fate=KEPT     disk=yes  HEAD=yes  ledger=yes   -> actual KEPT
PASS  0001_01_01_000000_create_users_table.php                 fate=DELETED  disk=no   HEAD=no   ledger=no    -> actual DELETED
PASS  2025_08_14_170933_add_two_factor_columns_to_users_table.php fate=DELETED  disk=no   HEAD=no   ledger=no    -> actual DELETED
PASS  2024_01_01_000000_create_passkeys_table.php              fate=DELETED  disk=no   HEAD=no   ledger=no    -> actual DELETED

KEPT rows: 3   DELETED rows: 3   total: 6
every pre-existing (non-2026_10_01_*) migration on disk is in the table: yes
(the 11 batch-A 2026_10_01_* files belong to the 75-table data table, not this section: 11 files)
FAILURES: 0

surviving scaffolds that sort before 2026_10_01_000001: 3
  -> 0001_01_01_000001_create_cache_table.php < 0001_01_01_000002_create_jobs_table.php < 2026_09_26_222801_create_personal_access_tokens_table.php
count claimed in the doc: three (3)   measured on disk: 3   MATCH

-- the doc must NOT still claim the passkeys migration is kept --
passkeys row correctly reads DELETED
```

**6/6 PASS, 0 failures.** The whole section was re-read against disk and git, not just the
two lines the brief named; the two rows that were already correct were left intact and the
count claim was re-derived from disk rather than from the prose.

---

## 5. The "also fix" items

### 5a. Item 3 — `idx_icd10` line number: RULED OUT, the brief's premise is wrong

The brief instructed: *"`docs/schema-notes.md:177` and `:178` … correct `:297` to `:291`"*,
asserting the plan's authoritative index records `:291`. **It does not**, and the SQL does
not either. Three independent reads:

```
$ Select-String -LiteralPath telemedicine_test.sql -Pattern "idx_icd10"
119:   INDEX idx_icd10 (kode)
297:   INDEX idx_icd10 (icd10_kode)

$ php -r '… file("telemedicine_test.sql") …'      # PHP's own line split
IDX_ICD10 occurrences in telemedicine_test.sql
  :119   INDEX idx_icd10 (kode)
  :297   INDEX idx_icd10 (icd10_kode)
  line 119 is:   INDEX idx_icd10 (kode)
  line 291 is:   icd10_kode VARCHAR(8) NULL,
  line 297 is:   INDEX idx_icd10 (icd10_kode)

$ Select-String -LiteralPath .omo/plans/sehatly-telemedicine-platform.md -Pattern "idx_icd10"
93:  | **`INDEX idx_icd10` (riwayat_penyakit)** | **297** | …      <-- the AUTHORITATIVE index
171: …reused as a name on two different tables (`:119` and `:291`)…  <-- stale inline citation
315: …`pasien_riwayat_penyakit` has `INDEX idx_icd10 (icd10_kode)` (:291)…  <-- stale inline citation
```

`:291` is where the **`icd10_kode VARCHAR(8) NULL` column** is declared. The
`INDEX idx_icd10 (icd10_kode)` that consumes it is at `:297`, the last line of that table's
body. The plan's own line 64 says *"Where this table disagrees with an inline `:NNN` reference
elsewhere in the plan, this table wins"*, and its authoritative table says `297`. The plan
simply contradicts itself at plan:171 and plan:315.

**`docs/schema-notes.md:177-178` was therefore already correct and was left unchanged** —
applying the brief would have replaced a correct citation with a wrong one. The sibling
citation in `docs/migration-order.md:229` (`pasien_riwayat_penyakit:291`) **was** stale and
**was** corrected to `:297`. A guard note was added to `docs/schema-notes.md` recording the
discrepancy and telling the next editor not to "fix" `:297` back to `:291`.

### 5b. Item 4 — the FK-support-index defect is already fixed

`27c6ca8` (`fix(dev): treat InnoDB FK-support indexes as implied rather than drift`) landed
it. Both docs said "unfixed, todo 18's to make". Both now record it as fixed, cite the
commit, warn todo 18 not to re-apply it, and keep the migration-author guidance:

- `docs/schema-notes.md` — section retitled *"Known verifier defect: InnoDB's implicit
  FK-support index — ALREADY FIXED in `27c6ca8`"*, with a STATUS block.
- `docs/migration-order.md` — new subsection *"### ALREADY FIXED — commit `27c6ca8`. Do not
  re-apply it."* under the existing section, plus a correction to the now-false sentence
  "any table with an uncovered FK cannot reach `Discrepancies: 0`" (retimed as historical).

Both retain the still-valid rule that a batch author must **not** "fix" an `extra_index` by
hand, and both retain the measurement with its calibration warning.

**The measurement was re-derived, not copied.** With
`App\Support\Schema\SqlSchemaParser` and leftmost-prefix coverage:

```
reference summary: {"tables":75,"views":2,"columns":672,"indexes":142,"foreign_keys":105,"checks":3}
FK MEASUREMENT (reference DDL, leftmost-prefix coverage)
  total FKs : 105
  uncovered : 80
  batch A share: master_kabupaten_kota(1), master_kecamatan(1), master_kelurahan(1)
```

**105 / 80, exactly as A.8 states**, and batch A's share is 3 — the three `extra_index`
entries todo 7 recorded as drift.

**Independent corroboration that the fix is real, from this task's own run.** Under
`c6d0beb` the 11-table run reported `Discrepancies: 3 (3 drift)` — all three `extra_index`.
At `HEAD` the same command reports **`Discrepancies: 0`** (§6 item 7). The three entries are
gone without any migration changing, which is the fix landing.

### 5c. Item 5 — the stray `(39)` in `docs/migration-order.md:175-176`

```
   `konsultasi_chat` (39) is in the "neither" group by column name, …
```

The `(39)` **is** the contract row number (row 39 of the table above is `konsultasi_chat`),
but sitting inside a paragraph enumerating the counts 39 / 19 / 1 / 16 it reads as a count —
and "39 is in the neither group" is a true-looking sentence that means the wrong thing. The
number was not removed, it was disambiguated:

```
   `konsultasi_chat` (contract row 39, not a count) is in the "neither" group by column name,
   but its created-at column is `terkirim_at` (`:575`), so its model needs
   `const CREATED_AT = 'terkirim_at'`.
```

### 5d. Item 6 — `task-7-sehatly.md` arithmetic

`Created (14)` listed 13 files, and the headline said `14 A + 3 D` while the body said
`Modified (1)`. The commit itself settles it:

```
$ git show --name-status --format="" c6d0beb | ForEach-Object { ($_ -split "`t")[0] } | Group-Object
A = 13
D = 3
M = 1
```

**13 A + 3 D + 1 M = 17 files changed.** Corrected to `Created (13)`, the headline row now
reads `13 `A` + 3 `D` + 1 `M` = 17 files changed`, and the closing paragraph's
`"11 A + 2 D" → "11 A + 3 D"` note became `"13 A + 3 D + 1 M"` with the arithmetic spelled
out. Arithmetic only; no evidence was rewritten.

### 5e. Item 7 — timestamp figures, re-measured

Measured off the reference DDL with `SqlSchemaParser`, not copied from the plan or from
todo 7's evidence:

```
reference summary: {"tables":75,"views":2,"columns":672,"indexes":142,"foreign_keys":105,"checks":3}

TIMESTAMP SPLIT over 75 tables
  neither    : 39
  dibuat only: 19
  diubah only:  1     (apotek_stok)
  both       : 16
  sum        : 75

PLAN 'neither' list: 29 entries
  wrongly included (has dibuat_at and/or diubah_at): 1 -> apotek_stok
  omitted from the plan's list                     : 11 -> artikel_kategori, konsultasi_chat,
      master_kabupaten_kota, master_kecamatan, master_kelurahan, master_metode_pembayaran,
      master_penjamin, master_promo, master_provinsi, master_spesialisasi, persetujuan_pdp
  DERIVATION: 29 - 1 + 11 = 39   (measured: 39)   MATCH

PLAN 'dibuat only' list: 18 entries; omitted: 1 -> audit_log
  DERIVATION: 18 + 1 = 19   (measured: 19)   MATCH
```

39 + 19 + 1 + 16 = 75, so the split is exhaustive. Note the plan's own prose says "Ten
tables are missing from the plan's list" while listing **eleven**; the list and the
arithmetic agree at 11, and that is what both docs now record.

**One defect found in todo 7's own correction:** `docs/schema-notes.md` listed only **10**
omitted tables, omitting `konsultasi_chat` — so it would have propagated a 38 under the same
`− 1 + N` arithmetic. Both docs now carry all 11 and the derivation `29 − 1 + 11 = 39`
explicitly, plus `18 + 1 = 19`.

---

## 6. VERIFY — every command, with the exit code actually observed

Nothing below is inferred. Each was run with an explicit timeout.

| # | Command | Exit | Observed |
| --- | --- | --- | --- |
| 1 | `php artisan test tests/Unit` | **0** | `passed, tests:73, passed:73, assertions:325` |
| 2 | `php artisan test --filter=VerifySchemaCommandTest` | **0** | `passed, tests:12, passed:12, assertions:152` |
| 3 | `php artisan test --filter=SchemaDifferTest` | **0** | `passed, tests:30, passed:30, assertions:69` |
| 4 | `php artisan test --filter=SchemaDifferFkImpliedIndexTest` | **0** | `passed, tests:7, passed:7, assertions:18` |
| 5 | `php artisan test --filter=SqlSchemaParserTest` | **0** | `passed, tests:22, passed:22, assertions:82` |
| 6 | `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` | **1** | `failed, tests:0, passed:0, raw:["No tests found."]` |
| 7 | `php artisan sehatly:verify-schema --tables=<all 11>` | **0** | `Discrepancies: 0 (0 drift, 0 informational)`, exit 0 |
| 8 | `vendor\bin\pint` (bare, no path argument) | **0** | `{"tool":"pint","result":"passed"}` |
| 9 | `Get-FileHash -Algorithm SHA256 telemedicine_test.sql` | — | `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` |
| 10 | contract table vs disk / HEAD / ledger | — | `6 PASS, FAILURES: 0` (§4) |

### Item 6 — a zero-match is not green

```
$ php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9
{"tool":"pest","result":"failed","tests":0,"passed":0,"assertions":0,"raw":["No tests found."]}
EXITCODE=1
```

`result: "failed"` with `tests: 0`. A green-looking `result` field is not sufficient on its
own; the test **count** and the **exit code** are the load-bearing signals. That is exactly
the trap behind item 1's `tests:73` cross-check.

### Item 7 — full output, and the two `verify-schema` traps

```
 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables)
 scope master_provinsi, master_kabupaten_kota, master_kecamatan, master_kelurahan, master_agama,
        master_golongan_darah, master_pendidikan, master_status_pernikahan, master_hubungan_keluarga,
        master_icd10, master_icd9cm

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 …

 Live schema
 counts tables=18 views=0 columns=74 indexes=36 foreign_keys=3 checks=0
 information_schema columns=74 indexes=36 foreign_keys=3 checks=0

 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.

 PASS — 75 tables, 2 views verified. Nothing was written.

EXITCODE=0
```

**Trap 1 (A.7) — the banner lies about scope.** It says "75 tables, 2 views verified" while
the `scope` line names 11. Formatted from the full reference model. Ignored, per A.7/A.8.

**Trap 2 (A.8) — a typo'd name exits 0.** Reproduced as a control, so that "the scope line
lists 11 real tables" is a claim with evidence behind it rather than an assumption:

```
$ php artisan sehatly:verify-schema --tables=master_provinsi,master_provincsi
 scope master_provinsi, master_provincsi
 Discrepancies: 1 (0 drift, 1 informational)
 unknown_requested_table master_provincsi expected: - | actual: -
EXITCODE=0
```

The real run has **no** `unknown_requested_table` row and `Discrepancies: 0`, and its
`scope` line lists all 11 real names. That is the only combination that means "eleven tables
were really checked".

### Item 9 — read-only law intact

```
$ (Get-FileHash -LiteralPath telemedicine_test.sql -Algorithm SHA256).Hash
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
$ git diff --exit-code telemedicine_test.sql ; $LASTEXITCODE
0
bytes: 59604
```

Matches the declared SHA-256 exactly, and the file is byte-identical to `HEAD`.

---

## 7. Remaining adversarial classes

- **`misleading_success_output` — APPLIED, fired twice, handled.** Pre-fix the Unit suite was
  red and the registry was *correct*, i.e. the artefact a reviewer would trust was right and
  the test was wrong. Post-fix the count is cross-checked against the pre-fix 73 (§1), and
  the derived assertion was attacked in three directions and **failed in all three** (§3).
  The zero-match filter is measured to exit 1 (§6 item 6), so `result:"passed"` is never
  accepted on its own.
- **`dirty_worktree` — handled precisely.** Only the five in-scope paths were staged, by
  explicit pathspec, with no `git add -A` / `.` / `-u` / `stash` / `checkout .` / `clean` /
  `reset` / `--amend` / `push`. `.omo/plans/sehatly-telemedicine-platform.md` (modified by the
  orchestrator) and `.omo/evidence/task-3-sehatly.md` + `.omo/start-work/` (untracked, a prior
  executor) were left exactly as found. `git show --name-only --format="" HEAD` lists only the
  five in-scope paths. `database/migrations/` was written only by the two throwaways of §3,
  both deleted, with `git status --porcelain -- database/` empty.
- **`hung_or_long_commands` — no hangs, no kills.** Every command carried an explicit
  120-600 s timeout. The longest was the Unit suite at ~3.2 s. No exit code was inferred and
  no command timed out. The pre-existing `mysqld` and the user's `php artisan serve` were
  never signalled — no `Stop-Process`, no `taskkill`, no service control at any point.
- **`stale_state` — ruled out.** `bootstrap/cache/config.php` did not exist before or after
  (`Test-Path` → `False` both times); `php artisan config:clear` → exit 0,
  `INFO Configuration cache cleared successfully.`; `Test-Path` → `False` again. VERIFY item 7
  was re-run *after* the clear and still reports exit 0 / `Discrepancies: 0` against
  `live database mysql / telemedisin_db`, so the proof is not against a stale target.
  `$env:GIT_INDEX_FILE` is unset. The three verification scripts all boot the framework and
  resolve the connection through `DB::`, so they read the same config the verifier does.
- **`repeated_interruptions` — probed, idempotent.** This task's changes are documentation
  plus one test and have **no schema effect**, so re-running must be a no-op. Confirmed:
  `php artisan test tests/Unit` run twice consecutively → `73/73, assertions 325, exit 0`
  both times, identical. `telemedisin_db` 18, `telemedisin_db_test` 0, `sehatly` 10 after
  both runs. The `verify-schema` command issues no DDL and no DML (asserted by the existing
  read-only test, which is part of the 73), so nothing here can drift a schema even if
  interrupted and repeated.
- **`malformed_input` — N/A, with a reason.** No parser was authored. This task edits one
  existing Pest test and three markdown files. The only hand-written input remains
  `docs/migration-order.md`, validated mechanically in §4.
- **`prompt_injection` — N/A, checked.** `telemedicine_test.sql` is first-party local data read
  as a specification, never as instructions. I re-read its header, all 40 `COMMENT` clauses
  and the section banners while re-measuring the figures: the only prose is the Indonesian
  section banner (`[1] MASTER DATA`, "aman untuk re-import berkali-kali") and column
  documentation such as `E-resep berlaku 7 hari`. **Nothing in it is addressed to an agent and
  nothing was acted on as an instruction.** No instruction-shaped text was encountered in any
  file I read. The one textual near-miss in the whole task was the *brief's* own claim about
  `idx_icd10` (5a), which is a factual error rather than an injection, and it was resolved by
  grepping the SQL rather than by obeying it.
- **`cancel_resume` — N/A, with a reason.** No resumable user flow exists yet; that is todos
  20/45/46. There is no partially-applied state to resume: the commit is the only artefact, and
  the pre-commit tree is fully green.
- **`flaky_tests` — N/A, with a reason.** Deterministic by construction: the registry test now
  reads only the worktree's markdown, the worktree's migration files and the read-only
  reference SQL; it touches no database, so no shared-state ordering can affect it. The
  remaining Unit tests are the pre-existing ones and were green before and after. The
  misleading-output risks that *are* real are the `--tables` PASS banner and the zero-match
  filter, both measured above rather than assumed.

---

## 8. Data safety and cleanup receipts

```
telemedisin_db         tables=18   (11 batch-A + cache, cache_locks, jobs, job_batches,
                                     failed_jobs, migrations, personal_access_tokens)
telemedisin_db_test    tables=0
sehatly                tables=10   (untouched: no migrate/DROP/TRUNCATE/ALTER ever issued)
telemedicine_test.sql  SHA256 AEFE2247…27F5, 59604 bytes, git diff --exit-code = 0
```

Every schema on the server, read-only:

```
db_simprapkl=26  gawaiseken=18  information_schema=79  laravel=5  manajemen-surat=0
mysql=37  performance_schema=111  sehatly=10  sys=101  telemedisin_db=18
telemedisin_db_test=0  trading_journal=12  ukk=12  ukk_pengaduan_sekolah=15
```

- **No** `migrate`, `migrate:fresh`, `migrate:rollback`, `db:seed` or `db:wipe` was run
  against any database. `telemedisin_db` was **read** (two `SELECT`s against
  `information_schema`) and never changed.
- `php artisan install:api` was **never** invoked in any form (A.5).
- No `mobile/` directory and no `pubspec.yaml` was created.
- Pint was run **bare**, with no path argument, per A.7. `git status --porcelain -- bootstrap/`
  → 0 entries, so `bootstrap/cache/packages.php` and `services.php` were not touched.
- No file under `database/migrations/`, `app/`, `bootstrap/`, `config/`, `routes/` or `web/`
  is modified. `app/Support/Schema/` is untouched — `27c6ca8` still owns it.
- **Throwaway cleanup receipt:** two throwaway migrations were created and both deleted —
  `database/migrations/2026_10_02_999999_bite_probe_scaffold_table.php` (literal-name probe)
  and `database/migrations/2026_10_02_999999_bite_probe_dynamic_table.php` (non-literal
  probe) — plus one throwaway registry row in `docs/schema-notes.md`. Verified removed:
  `Get-ChildItem database\migrations -Filter "*bite_probe*" | Measure-Object` → `0`, and
  `git diff --stat -- docs/schema-notes.md database/` → no output. No passkeys migration or
  any other deleted file was restored.
- Scratch scripts lived in `C:\Users\axioo\AppData\Local\Temp\opencode\`
  (`sn-fix-01-ledger.php`, `sn-fix-02-measure.php`, `sn-fix-03-contract.php`), outside the
  repository, and were deleted after this evidence was assembled. Nothing was left inside the
  repo, and no process was left running.

---

## 9. Files committed

```
docs/migration-order.md                                  (modified)
docs/schema-notes.md                                     (modified)
tests/Unit/Console/VerifySchemaCommandTest.php           (modified)
.omo/evidence/task-7-sehatly.md                          (modified)
.omo/evidence/task-7-fix.md                              (created)
```

Nothing else. `.omo/plans/sehatly-telemedicine-platform.md`,
`.omo/evidence/task-3-sehatly.md` and `.omo/start-work/` remain exactly as the orchestrator
and a prior executor left them.
