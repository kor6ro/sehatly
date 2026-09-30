# F2B -- `RbacSeeder` idempotency (the F2 code-quality BLOCKER)

**Status: FIXED and independently re-verified.** One BLOCKER, one seeder, one guard
file. No migration, no DDL change, no RBAC *data* change, `phpunit.xml` untouched.

- The fix and its guard were committed by an earlier executor as `c8bbcf5` (guard,
  red) and `8a6fd17` (fix, green). This executor did not build them and did not
  re-derive them from the gate's word: every number below was re-measured from
  scratch, and **two claims in the earlier executor's draft of this file were false
  and are corrected in sections 5 and 6.**
- Base: `ae08229`. `ae08229..HEAD` touches exactly three files, none under
  `database/migrations/`.
- Scratch databases, both created by this run and both named `sehatly_scratch_*`:
  **`sehatly_scratch_f2bv`** (the guard runs) and **`sehatly_scratch_f2bv2`**
  (clean-database seed sequence, and every full-suite run). `telemedisin_db` and
  `telemedisin_db_test` were never written to by any command in this file.
  `migrate:rollback` was never run.

---

## 1. The defect, confirmed in the mechanism

`database/seeders/RbacSeeder.php` at `ae08229` performed three **plain**
`DB::table()->insert()` calls -- `role_permissions` at `:154`, `roles` at `:176`,
`permissions` at `:198` -- with no `insertOrIgnore`, no `upsert` and no
delete-first. The keys that make a second run fatal are the DDL's own:

| Table | DDL | Key |
| --- | --- | --- |
| `roles` | `telemedicine_test.sql:153` | `nama VARCHAR(50) NOT NULL UNIQUE` |
| `permissions` | `:159` | `kode VARCHAR(100) NOT NULL UNIQUE` |
| `role_permissions` | `:166` | `PRIMARY KEY (role_id, permission_id)` |

The BLOCKER classification rests on the whole green result being conditional on
`RefreshDatabase`, and that was checked rather than assumed: `tests/Pest.php`
binds `RefreshDatabase` to the `Feature` directory, and 28 Feature files call
`$this->seed(RbacSeeder::class)` (the F2 report said eleven; it undercounted, and
F1's audit independently counted the same 28). Every one of those inserts is
rolled back with the test's transaction. Outside that wrapper, seeding twice is a
hard failure, and a deliverable whose acceptance includes `migrate --seed` cannot
ship that.

---

## 2. The technique, per table, and why each one

The brief asked for a deliberate choice per table rather than one uniform habit,
because **"already seeded" means different things on each**:

| Table | Statement | Why that one and not another |
| --- | --- | --- |
| `roles` | `upsert($rows, ['nama'], ['deskripsi'])` | A duplicate `nama` is **not** proof of a correct row: the `deskripsi` beside it can be stale, and `insertOrIgnore` would keep the stale copy forever while reporting success. The re-run therefore **repairs** the row. |
| `permissions` | `upsert($rows, ['kode'], ['nama'])` | Same, and stronger: `permissions.nama` is *derived* from `kode` by `RbacCatalog::displayNameFor()`, so a mismatch is drift by definition rather than an intentional override. |
| `role_permissions` | `insertOrIgnore($rows)` | The row is **nothing but its own composite primary key**. A duplicate pair therefore means "already granted", and there is no third column that *could* be updated. Writing the two key columns back to the values they already hold would be pure churn across 69 rows. This is the brief's "an existing correct row must not be needlessly churned" requirement, and it is enforced by a query-log assertion rather than by prose. |

Three further decisions, each of which could have gone wrong:

**Neither upsert touches `id`.** `role_permissions` and `user_roles` both reference
`roles.id`, and `user_roles` is the one table this seeder tree never writes, so
renumbering a role would silently re-point a real application's grants at a
different role. The `role_permissions` ids are still read back by natural key
after the write, exactly as before, so a pre-existing row keeps the id its grants
already point at. Pinned by `a drifted description is repaired without
renumbering the row`.

**`insertOrIgnore` and not a no-op `upsert` on the join table.**
`Illuminate\Database\Query\Builder::upsert()` cannot express one, and both obvious
spellings are traps:

- `upsert($rows, $uniqueBy, [])` degrades to a plain `insert()` -- the original
  bug wearing a different hat.
- `upsert($rows, $uniqueBy)` with `$update` omitted derives it from the inserted
  columns and then binds the **column names as values**, which would emit
  `` `role_id` = 'role_id' `` and set the key to 0.

`insertOrIgnore` is the only form here that is a no-op on a duplicate key without
either footgun.

**Nothing here empties a table.** The obvious alternative fix -- a scoped
delete-then-insert -- would also satisfy "runs twice", and that is why it was
rejected: `php artisan db:seed --class=RbacSeeder` reads like a read, and a seeder
that silently drops every grant in the system as a side effect of a direct
invocation is strictly more dangerous than one that raises. This is a **checked**
property: `every statement a re-run issues is a read or an insert` reads the query
log and asserts that every statement the re-run emits is a `select` or an
`insert`, which a delete-then-insert implementation fails.

**Residual, stated rather than hidden.** `INSERT IGNORE` is a broad ignore, and that
is bounded here only because both values are `(int)` casts of ids read back from
the two parent tables this same run wrote, so a dangling foreign key or a
truncation is not expressible. It also means a grant **removed** from
`RbacCatalog::ROLE_PERMISSIONS` is not retro-removed by a re-run; that still needs
`db:seed` or `migrate:fresh --seed`, which truncate first.

`DatabaseSeeder.php`'s "Consequence for running an individual seeder" section
called the 1062 "intentional". It is now wrong for `RbacSeeder` and right for the
nine DDL seeders, so that section was corrected in place rather than left to
mislead the next reader. Nothing else in the file changed.

---

## 3. RED then GREEN -- the guard, same database, only the seeder changed

Scratch database `sehatly_scratch_f2bv`, migrated by `RefreshDatabase`. The same
command both times, `php artisan test tests/Feature/RbacSeederIdempotencyTest.php`,
and the only difference is `database/seeders/RbacSeeder.php`: for RED it was
checked out byte-exactly from `ae08229` (`git checkout ae08229 -- <path>`, which
restores the three plain `insert()` calls at `:154`, `:176`, `:198`), for GREEN it
was restored with `git checkout HEAD -- <path>`.

**RED** -- `tests:9 passed:1 assertions:10 errors:8`, exit **2**. Every one of the
8 errors is the same MySQL 1062:

```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'pasien'
for key 'roles.roles_nama_unique' (Connection: mysql, ... Database: sehatly_scratch_f2bv,
SQL: insert into `roles` (`deskripsi`, `nama`) values (..., pasien), (..., dokter), ...)

  MySqlConnection.php:53
  database/seeders/RbacSeeder.php:176      <- the plain insert, the defect
  database/seeders/RbacSeeder.php:146      <- run()
  tests/Feature/RbacSeederIdempotencyTest.php:248   <- the second seed
```

The 8 failing tests are the second-seed call in each of the eight non-control
tests (`:248`, `:257`, `:272`, `:300`, `:311`, `:327`, `:192`+`:344`,
`:192`+`:386`). The **one** test that passed is the permanent control `the defect
is real: a plain re-insert of a seeded role is MySQL 1062`, which asserts that the
duplicate *is* an error -- so a green suite afterwards cannot be explained by the
duplicate key having quietly stopped mattering.

**GREEN** -- `{"tool":"pest","result":"passed","tests":9,"passed":9,
"assertions":31,"duration_ms":8402}`, exit **0**.

The guard is 9 tests and it deliberately refuses the two weak versions of itself:

- **"a second run does not throw" is not the test.** A seeder that truncates and
  re-inserts passes it. `every statement a re-run issues is a read or an insert`
  rules that out, asserting the exact statement list -- two `select`s and one
  `insert ignore into `role_permissions`` -- and exactly **one** join write, so a
  per-row loop is excluded too.
- **"does not throw" does not require `upsert` over `insertOrIgnore` either.** Two
  tests mutate a seeded row (`roles.deskripsi`, `permissions.nama`) and require the
  next run to **repair** it. `insertOrIgnore` would keep the stale copy and report
  success, so those two tests are the specification for the two parents.
- **The no-duplicates test is keyed by natural key, not by id**, and checks the
  distinct composite-pair count, so "no duplicates" cannot be satisfied by a
  doubled table that happens to hold every right pair twice.

### Reporter control -- applied, and demonstrated in both directions

The Pest JSON reporter **omits** the `failed`, `errors` and `skipped` keys when the
count is zero, so a parser that reads an absent key as `0` reports a red run as
green, and one that reads it as non-zero reports a green run as red. The omission
was measured from both transcripts, not assumed:

```
RED          result=failed tests=9  passed=1 assertions=10 | errors=8    failed=ABSENT skipped=ABSENT
GREEN        result=passed tests=9  passed=9 assertions=31 | errors=ABSENT failed=ABSENT skipped=ABSENT
```

A key reported `ABSENT` is therefore a *measured* zero, and the zero counts are read
from **PHPUnit's own JUnit attributes**, which it writes whether or not they are
zero:

```xml
<testsuite name="C:\Users\axioo\Desktop\sehatly\phpunit.xml"
           tests="1162" assertions="22541" errors="0" failures="0" skipped="0" time="485.733476">
```

---

## 4. The real-world sequence, twice in a row, on a clean database

Database **`sehatly_scratch_f2bv2`**, created empty and proven empty immediately
before the first run: `{"database":"sehatly_scratch_f2bv2","tables":0}`.

| # | Command | State entering it | Exit |
| --- | --- | --- | --- |
| 1 | `php artisan migrate:fresh --seed` | **clean, 0 tables** | **0** |
| 2 | `php artisan migrate:fresh --seed` | fully seeded | **0** |
| 3 | `php artisan db:seed --class=RbacSeeder` | fully seeded (run 2) | **0** |
| 4 | `php artisan db:seed --class=RbacSeeder` | fully seeded (run 3) | **0** |
| 5 | `php artisan db:seed` (the whole tree) | fully seeded (run 4) | **0** |

Runs 1 and 2 are the literal "full documented seed path twice". Runs 3 and 4 are
the case the defect was actually about, and the RED half was reproduced on this
same database, with this same command, by putting the pre-fix seeder back:

```
PRE-FIX   php artisan db:seed --class=RbacSeeder   ->  exit 1
          SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
          'pasien' for key 'roles.roles_nama_unique'  (Database: sehatly_scratch_f2bv2)
POST-FIX  php artisan db:seed --class=RbacSeeder   ->  exit 0
```

The RBAC state was read back with a **read-only PDO script** (three `SELECT`s, no
Laravel boot, no writes) after runs 1-4 and again after run 5. It is byte-identical,
hashed over a canonical rendering of all three tables on natural keys only -- no
ids, sorted, so neither ordering nor `AUTO_INCREMENT` can hide a difference:

```
after runs 1-4 : "rbac_state_sha256": "28b6d58a157d1aea887a7d378c1615afba76bc9a27a5053bc923ae0d97ab220a"  (3368 bytes)
after run 5     : "rbac_state_sha256": "28b6d58a157d1aea887a7d378c1615afba76bc9a27a5053bc923ae0d97ab220a"  (3368 bytes)
```

with, in both reads:

```
counts: roles 5, permissions 24, role_permissions 69
distinct_grant_pairs: 69     duplicates: 0     user_roles_rows: 0
role_ids: pasien=1, dokter=2, apoteker=3, admin=4, superadmin=5   (unchanged by any re-run)
permission ids: booking.buat=1 ... dokter.profil=24
```

`69` rows and `69` distinct pairs together are the machine-checked form of "no
duplicates"; the identical `role_ids` are the machine-checked form of "a re-run
does not renumber anything a foreign key points at". The failed PRE-FIX attempt
also left the state hash unchanged, which is the expected result: its first
statement is the one that aborts.

---

## 5. The full suite -- real numbers, and the count movement explained exactly

`phpunit.xml` **not touched** (`git diff --exit-code HEAD -- phpunit.xml` -> exit
0). Every run used a per-run `$env:DB_DATABASE` override onto a migrated **and
seeded** scratch database, which is the state the 22,510 baseline was measured in
and the state `migrate:fresh --seed` leaves a database in.

| Run | Tests | Assertions | errors | failures | skipped | Exit |
| --- | --- | --- | --- | --- | --- | --- |
| baseline at `ae08229` (given) | 1153 | 22510 | 0 | 0 | 0 | 0 |
| run 1 | 1162 | 22542 | 0 | 0 | 0 | 0 |
| run 2 (`junit_A`) | 1162 | 22541 | 0 | 0 | 0 | 0 |
| run 3 (`junit_B`) | 1162 | 22541 | 0 | 0 | 0 | 0 |
| **run 4 (final)** | **1162** | **22541** | **0** | **0** | **0** | **0** |

**The movement is accounted for exactly.** 1,153 -> 1,162 is the 9 tests the guard
file adds, and 22,510 -> 22,541 is +31, which is precisely the assertion count of
`RbacSeederIdempotencyTest.php` measured in isolation (section 3). Unit 146 tests /
7,532 assertions plus Feature 1,016 tests / 15,009 assertions is 22,541 with no
residual, and no pre-existing test's assertion count moved.

### The residual +/-1 is real, it is not this change, and it is now localised

The earlier executor reported this +/-1 and attributed it to whether the database
carried seed rows. **That attribution is wrong**, and this executor measured it
instead: on one identical database, with an identical tree, three of four full runs
reported 22,541 and one reported 22,542.

It was localised by per-test diffing, because every `<testcase>` in the JUnit log
carries its own `assertions` attribute. Diffing the 22,541 profile against the
22,542 profile gives **exactly one** differing test out of 1,162:

```
DIFFERS: Tests\Feature\Audit\RedactionAbsenceTest ::
         the password hash is absent from an audit row in every form     A=13  22542-run=14
per-test differences: 1
```

and reproduced in isolation, running that one test 8 times with `--filter`:

```
run 1 assertions=14   run 2=13  run 3=13  run 4=13  run 5=13  run 6=13  run 7=13  run 8=14
```

**It is independent of this change, and the isolation run is the proof:** with
`--filter` on that single test, `RefreshDatabase` migrates and no seeder executes
at all, yet the count still flips. A seeder cannot add an assertion to a test that
never calls it.

**The mechanism inside that test is not established, and is reported as open rather
than guessed.** A throwaway probe (created, run ten times, and deleted -- the tree
is byte-identical to HEAD) mirrored the test's body and printed its cardinalities:
the audit-row count is always 2 and the fingerprint array always has 3 entries, so
the body's own expectations are a constant 13. The 14th assertion therefore comes
from outside the visible body, and I did not find it. This is a pre-existing flake
in an unrelated audit-redaction test, outside this executor's scope.

### An artifact this executor created, and removed

`php artisan test --log-junit` **with no path** does not fail: `artisan test`
appends its own `--no-progress`, PHPUnit consumes it as the *value* of
`--log-junit`, and writes a 441 KB JUnit log to a file literally named
`--no-progress` in the repository root. That file was untracked, was created by
this executor, and has been deleted. It is mentioned because it is also how run
1's per-test profile survived and made the localisation above possible; the
correct spelling is `--log-junit=<path>`, which is what runs 2-4 used.

### Other gates, re-measured on the final state

| Check | Result |
| --- | --- |
| `php artisan test` (final) | 1162 / 1162 passed, 22541 assertions, 0 errors, 0 failures, 0 skipped, exit **0** |
| `php artisan test tests/Contract` (not in `phpunit.xml`) | 201 / 201, 2669 assertions, exit **0** |
| `php artisan sehatly:verify-schema` | exit **0** -- `PASS -- 75 tables, 2 views verified. Nothing was written.`, `Discrepancies: 7 (0 drift, 7 informational)`; the 7 are the framework-registered tables, **not** drift |
| `php artisan route:list --path=api/v1 --json` | **74** operations |
| `telemedicine_test.sql` | SHA-256 `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, 59,604 B, `git diff --exit-code` exit **0** -- byte-identical |
| `phpunit.xml` | `git diff --exit-code HEAD -- phpunit.xml` exit **0** |
| `database/migrations/` | **0** files changed since `ae08229` |
| `vendor/bin/pint --test` on the 3 changed files | `{"tool":"pint","result":"passed"}`, exit **0** |
| `git diff --exit-code HEAD` over the whole tree | exit **0** -- every tracked file byte-identical to HEAD |
| `mobile/` | absent, unchanged |
| `.omo/plans/` | not touched; no plan checkbox marked |

---

## 6. Byte-level scan

Raw-byte reads with no encoding layer, over all three files this change owns. A
mojibake is invisible to a grep that decodes as UTF-8, and a BOM is invisible to a
byte grep for the text that follows it, so both are checked explicitly, along with
16 homoglyph / mojibake families and the `referencia` / `referensi` typo class that
once cost this project 48 failures.

**The scanner is itself proven, not trusted.** Its first version passed its
patterns as PowerShell double-quoted `"\xE2\x80\x94"` strings, which PowerShell
does not unescape, so every family silently matched nothing and the family half of
the scan was vacuous. It was rewritten to match real byte arrays and now carries a
self-test that plants all 16 families in a synthetic buffer:

```
families declared=16  families detected in probe=16  scanner_is_live=True
```

Result:

```
CLEAN database/seeders/RbacSeeder.php
  bytes=14749  BOM=no  non_ascii_bytes=0  CR=0  LF=290  families=[]  referencia=0 referensi=0
CLEAN database/seeders/DatabaseSeeder.php
  bytes=14211  BOM=no  non_ascii_bytes=0  CR=0  LF=277  families=[]  referencia=0 referensi=0
CLEAN tests/Feature/RbacSeederIdempotencyTest.php
  bytes=15172  BOM=no  non_ascii_bytes=0  CR=0  LF=389  families=[]  referencia=0 referensi=0
```

Every `.php` this change owns is **0 non-ASCII bytes, no BOM, LF only**, which is
the rule F1's audit applied across all 831 tracked files. The `referencia` /
`referensi` class is also clean over whole directories, not just the three files:
`database/seeders` 0 / 0, `app/Support/Rbac` 0 / 0, `tests/Feature/Audit` 0 / 1
(the 1 being the correct spelling).

**This evidence file is pure ASCII too**, which is a change from the earlier
executor's draft. That draft claimed "104 non-ASCII bytes, all of them U+2014 EM
DASH and U+2019". Measured, it was **110** bytes, and there is **no U+2019 in it at
all**: U+00A7 section sign x4, U+00B1 plus-minus x3, U+2014 em dash x29, U+2192
rightwards arrow x2, U+21D2 rightwards double arrow x1 -- which sums to exactly
110 and reconciles with the raw byte count, so the file was valid UTF-8 and the
claim about *which* characters they were was simply wrong.

That composition is not asserted from memory either. The codepoint walker that
produced it had two real bugs of its own -- it matched the family patterns as
unescaped PowerShell strings, and it advanced its *string* index by the *UTF-8
byte* width, so a 2-byte U+00A7 skipped the U+00A7 standing beside it. Both were
found by running the tool against a synthetic file built to that exact
composition, and it now reconciles:

```
U+00A7 occurrences=4 bytes=8     U+00B1 occurrences=3 bytes=6
U+2014 occurrences=29 bytes=87   U+2192 occurrences=2 bytes=6
U+21D2 occurrences=1 bytes=3
sum of non-ascii bytes from the codepoint walk: 110  (must equal non_ascii_bytes: 110  -> True)
```

Two measuring tools were wrong before the measurement they were used for was
right, which is worth stating plainly: a clean result from a tool that cannot fail
is not a result.

---

## 7. What was NOT done, stated plainly

- **The mechanism behind the +/-1 in `RedactionAbsenceTest` is not found.** It is
  localised to one named test, reproduced in isolation, and shown to be independent
  of this change; the assertion that varies is outside that test's visible body.
  Fixing it is a different executor's job and a different todo.
- A grant **removed** from `RbacCatalog::ROLE_PERMISSIONS` is still not
  retro-removed by a re-run; that needs `db:seed` or `migrate:fresh --seed`.
  Stated in the class docblock rather than fixed, because fixing it means a delete.
- The **other eight F2 MAJOR findings and ten MINOR findings were not touched** --
  out of scope. The unmounted rate limiters, the OpenAPI envelope
  `additionalProperties` defect, the ungated `tests/Contract` suite, the
  hand-copied `KOLOM` constant in `RekamMedisService.php` and the
  `refresh_coordinator.dart` `Completer` hang are all still open and are F2's to
  re-gate.
- `web/**`, `packages/**`, `docs/**` untouched. `mobile/` still absent.
- `.playwright-mcp/` is untracked in the working tree and was left exactly as
  found. The two scratch databases were dropped at the end of this run.
- The commit message of `8a6fd17` has two mangled words where PowerShell ate
  `$update` inside the message body ("an empty \ short-circuits", "omitting \ binds
  the column NAMES"). The correction is written out in full in the `RbacSeeder`
  docblock and in section 2 above. The commit was not amended.

## 8. Files changed by this fix

```
 database/seeders/DatabaseSeeder.php              |  11 +-
 database/seeders/RbacSeeder.php                  |  91 ++++++--
 tests/Feature/RbacSeederIdempotencyTest.php      | 389 +++++++++++++++++++
 3 files changed, 438 insertions(+), 23 deletions(-)
```

Committed as `c8bbcf5` (the guard, proven red) and `8a6fd17` (the fix, proven
green). The two scratch databases used for the destructive runs,
`sehatly_scratch_f2bv` and `sehatly_scratch_f2bv2`, were created by this run and
dropped by it; `telemedisin_db` and `telemedisin_db_test` were never written to.
