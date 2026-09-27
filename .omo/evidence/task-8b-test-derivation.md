# Task 8b — derive the schema-parity test expectations instead of hardcoding the batch-B state

Branch `feat/sehatly-telemedicine`. Follow-up to todo 8, mandated by plan **Appendix A.9**.

---

## 1. The defect

Todo 8 correctly regenerated `docs/schema-notes.md` and correctly repointed four assertions in
`tests/Unit/Console/VerifySchemaCommandTest.php`. It repointed them to **hardcoded batch-B values**
rather than deriving them. This is the identical defect class A.8 charged todo 7 with,
reintroduced 180 lines above the fix for that very class in the same file.

| site | hardcoded value | breaks when |
|---|---|---|
| L33 | `['pasien','booking','ulasan_dokter','persetujuan_pdp','audit_log']` | `pasien` is a **todo-9** table → the very next commit |
| L37, L68 | `56` | todo 9 (real value 48) |
| L82 | `--tables=booking` | `booking` is a **todo-11** table |
| L65 | literal `7` | any `docs/schema-notes.md` regeneration |
| L38-39 | `missing_view\s+v_dokter_katalog` / `v_pendapatan_bulanan` | todo 18 (the views land) |

The executor's own comments admitted it: *"Re-point this list again when todos 9-17 land their
tables"* and *"Re-point when booking lands."*

## 2. The sibling pattern lifted

`the extra-table registry is parsed from docs/schema-notes.md` (same file, ~180 lines below the
defect) already solves this. It globs `database/migrations/*.php`, extracts every
`Schema::create('<literal>')`, and — critically — **asserts the extracted count equals the
`Schema::create` call count** so an unresolvable `Schema::create($variable)` fails loudly instead of
silently under-counting. That guard is what makes the derivation trustworthy, so it was lifted
verbatim rather than reinvented. The sibling test's logic was **not touched**; only its pattern
reused.

## 3. Extraction source, and why

**Source of truth: the migration set, not the live database.**

The live schema is verified separately, by the command itself, against the reference DDL. Asserting
that its `missing_table` rows **equal the derived set** is what proves the two agree — that equality
*is* the parity property. Deriving from the migrations keeps the expectation independent of whether
a migration has been *run* yet, and stops a table that exists in the database but has no migration
from quietly satisfying a check that is really about the migration set.

## 4. The four assertions, before → after

### 4.1 Missing-table count (was L37 and L68)

```php
// BEFORE
expect(substr_count($output, 'missing_table'))->toBe(56); // 75 - 19 created
expect($missing)->toHaveCount(56);                         // 75 - 19 created (11 batch A + 8 batch B)
expect(array_column($missing, 'table'))
    ->toContain('pasien', 'booking', 'ulasan_dokter', 'persetujuan_pdp');
```

```php
// AFTER — text channel
expect(substr_count($output, 'missing_table'))->toBe(count($missingTables));
preg_match_all('/missing_table\s+([A-Za-z0-9_]+)/', $output, $named);
expect($named[1])->toEqualCanonicalizing($missingTables, '...');

// AFTER — JSON channel
expect(array_column($rows, 'table'))->toEqualCanonicalizing($missing['tables'], '...');
```

**Stronger, not weaker.** `toContain(4 names)` became *set equality against every name*. The count
check is retained as `substr_count(...) === count($derived)`.

### 4.2 Named-offender list (was L33)

```php
// BEFORE — 5 hand-picked names
foreach (['pasien', 'booking', 'ulasan_dokter', 'persetujuan_pdp', 'audit_log'] as $table) {
    expect($output)->toMatch('/missing_table\s+'.$table.'\b/', 'the report must name '.$table);
}
```

```php
// AFTER — every derived name, plus a spread sample kept for actionable failures
expect($named[1])->toEqualCanonicalizing($missingTables, '...');   // exhaustive: all 56
if ($missingTables !== []) {
    expect($output)->toContain('missing_table');
    foreach (array_values(array_unique([
        $missingTables[0],
        $missingTables[(int) floor(count($missingTables) / 2)],
        $missingTables[count($missingTables) - 1],
    ])) as $table) {
        expect($output)->toMatch('/missing_table\s+'.preg_quote($table, '/').'\b/', 'the report must name '.$table);
    }
}
```

Plan criterion 2 — *"every missing table is named, not merely counted"* — is now checked
**exhaustively** (all 56 today) instead of on 5 hand-picked names, while the per-name loop is kept so
a failure still names the offending table.

### 4.3 Narrow-scope anchor (was L82)

```php
// BEFORE
$exitCode = Artisan::call('sehatly:verify-schema', ['--tables' => 'booking']);
expect($exitCode)->toBe(1);
expect($output)->toContain('booking');
expect($output)->toContain('scope');
expect($output)->toContain('FAIL');
```

```php
// AFTER
$notYetCreated = $missingFromMigrations()['tables'];
// branch A: something is still unmigrated -> keep the exit-1-and-names-it teeth
$anchor = $notYetCreated[0];
expect($exitCode)->toBe(1);
expect($output)->toMatch('/missing_table\s+'.preg_quote($anchor, '/').'\b/', 'the narrow run must name '.$anchor);
expect($output)->toMatch('/scope\s+'.preg_quote($anchor, '/').'\b/');
expect($output)->toContain('FAIL');

// branch B: todo 17+ — every table exists, so the verdict inverts to exit 0
$anchor = $contract[0];
expect(Artisan::call('sehatly:verify-schema', ['--tables' => $anchor]))->toBe(0);
expect($output)->toMatch('/scope\s+'.preg_quote($anchor, '/').'\b/');
expect($output)->toMatch('/Discrepancies:\s+0\b/');
```

Note the old `toContain('booking')` was **already nearly vacuous** in branch B: `booking` is echoed on
the `scope` line, so a landed `booking` would have satisfied it with `exit 0` and no offender named
at all. The new branch-B assertions read the echoed `scope` and the `Discrepancies` line precisely
because of the two A.7 traps: the `PASS` banner is formatted from the **full** reference model
(prints "75 tables, 2 views verified" after checking one table), and a name the DDL does not define
also exits 0.

### 4.4 Drift decomposition (was L65)

```php
// BEFORE
expect($json['discrepancy_count'] - $json['drift_count'])->toBe(7);
```

```php
// AFTER
$registeredExtras = count(ExtraTableRegistry::fromMarkdown(base_path('docs/schema-notes.md')));
expect($json['discrepancy_count'] - $json['drift_count'])->toBe($registeredExtras);
expect($json['notes_registry']['registered_extra_tables'])->toBe($registeredExtras);
```

The class was already imported; the sibling test already used it.

### 4.5 Beyond the four (required by the durability mandate)

The mandate is *"correct today AND after todos 9, 10, … 18 land, without further edits."* Three
further pins were in the same blast radius and had to move with them:

- **The two `missing_view` regexes.** These are what todo 18's view migrations create. Left
  literal, they break the moment those migrations land. Now derived from the same walk
  (`CREATE OR REPLACE VIEW` extraction, with its own calls-vs-extracted guard).
- **Tests 1 and 2's `toBe(1)` / `ok => false` / `drift_count > 0`.** Keyed on the derived set.
- **The `missing_view` JSON rows**, added as the JSON-channel mirror of the derived view set.

## 5. Extracted vs actual `Schema::create` counts (adversarial: misleading_success_output)

The vacuity guard is asserted **twice**: inside the shared derivation (so all four consumers are
protected) and in a dedicated probe test that counts the two numbers independently and **prints them
on every run**.

```
[derive] migrations=22 Schema::create calls=25 extracted=25 | CREATE VIEW calls=0 extracted=0
         | contract tables=75 views=2 | derived-missing tables=56 views=2 | registry=7
```

| metric | value |
|---|---|
| migration files | 22 |
| **actual `Schema::create(` occurrences** | **25** |
| **extracted literal names** | **25** |
| **counts match** | **YES** |
| `CREATE VIEW` calls / extracted | 0 / 0 |
| distinct tables created (incl. `migrations` ledger) | 26 |
| contract tables / views | 75 / 2 |
| **derived missing tables today** | **56** |
| derived missing views today | 2 |
| registry extra tables | 7 |
| reported `missing_table` rows | 56 |
| **derived set == reported set** | **YES (exactly, no diff either way)** |
| reported `drift_count` / `discrepancy_count` | 58 / 65 (65 − 58 = 7 = registry) |

`25` decomposed: `cache_table` 2, `jobs_table` 3, `personal_access_tokens` 1, the 19
`2026_10_01_*` tables 19. A derived count of 0 next to 25 real calls is impossible — the guard fails
first, and the failure message names the offending files.

## 6. Durability proof

### 6.1 Throwaway migration A — non-contract table `zz_probe_table` (in-repo, task-sanctioned)

```
[derive] migrations=23 Schema::create calls=26 extracted=26 contract=75 derived-missing=56
result: 13 tests, 12 passed, 1 FAILED
```

All four converted assertions **held** (derived missing correctly still 56 — `zz_probe_table` is not
a contract table). The one failure was the **sibling registry test**, correctly, because a new
migration creates a table `docs/schema-notes.md` does not register — that is the sibling test doing
exactly its documented job ("the next scaffold add or removal fails HERE, naming the table"). This is
also positive proof the derivation is live, not cached: the guard tracked 25 → 26 calls.

### 6.2 Throwaway migration B — real contract table `booking` (the decisive proof)

```
[derive] migrations=23 Schema::create calls=26 extracted=26 contract=75 derived-missing=55
FAIL: "Failed asserting that 56 is identical to 55."                        (test 1)
FAIL: JSON set diff names exactly: + 'booking'                             (test 2)
PASS: narrow-scope test (anchor auto-moved off `booking`, teeth retained)
```

**This is the whole point.** The derived count *tracked the migration set* (56 → 55), and because the
migration was never *run* the live report still said 56 — a genuine parity gap, reported precisely
and self-explainingly. **The old hardcoded `toBe(56)` would have PASSED in this exact state.** A
pinned number cannot see a migration landing.

### 6.3 Cleanup receipt

```
Remove-Item database\migrations\2026_10_01_000099_zz_probe_table.php  -> REMOVED
glob zz_probe* -> (empty)
migration file count -> 22
git status --porcelain -- database/  -> (empty)
php artisan test tests/Unit -> 74 passed, exit 0, derived-missing=56
```

### 6.4 Full projected trajectory (scratch copy in `%TEMP%`, repo untouched)

`docs/migration-order.md` was parsed for the real batch→todo map and the **identical derivation code**
was run against a scratch copy of `database/migrations` with one fabricated migration added per
table, batch by batch.

```
batches parsed from docs/migration-order.md: 75 tables across todos 7,8,9,10,11,12,13,14,15,16,17

todo   files     calls     extract  tblMiss   viewMiss  plan      branch
8(now) 22        25        25       56        2         56        exit-1 (names offenders)
9      30        33        33       48        2         48        exit-1 (names offenders)
  -> at todo 9 the OLD list still asserts: pasien,booking,ulasan_dokter,persetujuan_pdp,audit_log
  -> of those, absent from the derived set: pasien  <= the old test breaks here
10     37        40        40       41        2         41        exit-1 (names offenders)
11     40        43        43       38        2         38        exit-1 (names offenders)
12     44        47        47       34        2         34        exit-1 (names offenders)
13     49        52        52       29        2         29        exit-1 (names offenders)
14     57        60        60       21        2         21        exit-1 (names offenders)
15     63        66        66       15        2         15        exit-1 (names offenders)
16     70        73        73       8         2         8         exit-1 (names offenders)
17     78        81        81       0         2         0         exit-1 (names offenders)
  -> todo 17: tables complete, but the 2 views are still unmigrated.
     A table-only derivation would invert HERE and go red. Derived view count holds it at exit-1: 2
18     80        81        81       0         0         0         EXIT-0 (todo 18 shape)

PROJECTION MATCHES docs/migration-order.md PLAN TABLE: YES
CLEANUP: removed 80 scratch migration files; scratch dir exists=false
repo migrations untouched: 22 files, zz_probe present=false
```

`calls == extract` held at **every** one of the eleven points, so the guard never went vacuous along
the way. The projected trajectory reproduces A.9's table exactly:
**56 → 48 → 41 → 38 → 34 → 29 → 21 → 15 → 8 → 0 → 0**.

### 6.5 The todo-18 inversion branch, and how it is handled

**Handled by explicit INVERSION, not by skipping.** `markTestSkipped` was available and would have
been weaker; inverting keeps a real check alive on the far side of the commit and is what A.9 asked
for ("the derived form also makes todo 18's exit-0 assertions fall out naturally").

| test | branch condition | branch A | branch B (todo 18) |
|---|---|---|---|
| 1 (text) | `tables === [] && views === []` | exit 1, `FAIL`, `read-only`, `substr_count` == derived, set equality, per-name sample, derived view regexes | exit 0, `PASS`, no `missing_table`, no `missing_view` |
| 2 (JSON) | `tables === [] && views === []` | exit 1, `ok === false`, `drift_count > 0`, derived table + view rows | exit 0, `ok === true`, `drift_count === 0`, both row sets `[]` |
| 3 (narrow) | `tables === []` | exit 1, `missing_table <anchor>`, echoed `scope`, `FAIL` | exit 0, echoed `scope`, `Discrepancies: 0` |

The exit-0 assertions are **not invented** — they were executed verbatim against a genuinely clean
run and every one matched:

```
  PASS  exit 0
  PASS  contains 'PASS'
  PASS  no 'missing_table'
  PASS  no 'missing_view'
  PASS  echoed scope lists anchor
  PASS  Discrepancies: 0
  PASS  narrow-scope anchor is a real contract table
EVERY TODO-18 EXIT-0 ASSERTION HOLDS AGAINST A REAL CLEAN RUN: YES
```

### 6.6 Why views had to be derived too (a gap found in my own first attempt)

My first pass derived **tables only** and keyed the inversion on `tables === []`. The projection
showed that is wrong: todo 17 completes the last *table* migration, but the two views are authored in
todo 18 (`2026_10_01_000077`/`_000078`, `CREATE OR REPLACE VIEW`, not `Schema::create`). A
table-only derivation reaches "nothing missing" at todo 17 and would have inverted to
`expect($exitCode)->toBe(0)` while the command still correctly exits 1 on two `missing_view` rows —
a self-inflicted red at todo 17. Deriving both is what makes the flip land exactly when the schema
is complete. The view derivation carries its own calls-vs-extracted guard.

## 7. The global-function fix

`tests/Feature/UsersTableSchemaTest.php:23` declared `function usersBatchBPayload(...)` at **file
scope**. PHP test files are `include`d, so a second file declaring that name would `Cannot redeclare`
and **fatal the entire suite at include time**, taking every unrelated test with it — the blast radius
of a name collision is the whole run, not one test.

Moved inside the two closures that use it, as a `$payload` arrow function each. No global function is
declared at file scope any more, and the blast radius of any future collision is one test. The
docblock records why, so it is not "tidied" back out.

## 8. Adversarial classes

| class | result |
|---|---|
| **misleading_success_output** | **Probed and guarded.** Extracted 25 == actual 25, printed on every run. The guard lives in the shared derivation *and* in a dedicated probe test that counts independently. A derived 0 next to 25 real calls fails first, naming the files. |
| **dirty_worktree** | Commit contains only `tests/Unit/Console/VerifySchemaCommandTest.php` and `tests/Feature/UsersTableSchemaTest.php` (plus the evidence file). `.omo/plans/...` (modified) and `.omo/evidence/task-3-sehatly.md`, `.omo/start-work/` (untracked) are the orchestrator's / a prior executor's and were **left alone** — no `git add -A`/`-u`/`commit -a`; explicit pathspecs only. |
| **stale_state** | `bootstrap/cache/config.php` absent (observed `False`) → `php artisan config:clear` → exit 0 → item 7 re-verified: exit 0, `Discrepancies: 0`, all 8 names echoed. Repeated against `telemedisin_db_test` too: exit 0, `Discrepancies: 0`. |
| **hung_or_long_commands** | Explicit timeouts (120–600 s) on every command; only observed exit codes reported. The pre-existing `mysqld` (17484/19796) and the user's `php artisan serve` (PID 22288) were never killed or signalled. |
| **repeated_interruptions** | Pure test change, no schema effect. Re-ran `tests/Unit` twice → 74 passed / exit 0 both times, identical `[derive]` line. Post-run census: `telemedisin_db` 26 tables / 0 domain rows, `telemedisin_db_test` 26 / 0, `sehatly` 10. |
| **malformed_input** | **N/A** — no new parser. The walk globs and greps this repo's own migration files; input is trusted repo content. |
| **prompt_injection** | **N/A** — nothing untrusted is consumed. No network, no model output, no user-supplied text. |
| **cancel_resume** | **N/A** — no resumable user flow (todos 20/45/46 untouched). |
| **flaky_tests** | **N/A** — deterministic: filesystem glob + regex + a read-only MySQL read. Zero-match risk measured, not assumed: `--filter=ThisTestNameCannotPossiblyExistZzz9` → **exit 1**, `No tests found.` |

### Self-inflicted incident worth recording

An early run produced 3 failures. Root cause: **I ran two `php artisan test` processes in
parallel.** `tests/Pest.php` binds `RefreshDatabase` to every Feature test, which runs
`migrate:fresh` on the shared `telemedisin_db_test`, so the concurrent Unit process read a
half-migrated schema (observed states of 0, 24 and 23 tables mid-run). Not a code defect and not a
schema defect — an operational one. Every command after that point was run **serially**, and the
database returned to 26/26/10 on its own once `migrate:fresh` completed. No direct
`migrate`/`migrate:fresh`/`migrate:rollback`/`db:seed`/`db:wipe` was ever invoked by me.

## 9. Verification (observed exit codes)

| # | command | exit | result |
|---|---|---|---|
| 1 | `php artisan test tests/Unit` | **0** | **74 tests** (>= 73; was 73, +1 probe test), 74 passed, 356 assertions |
| 2 | `php artisan test --filter=VerifySchemaCommandTest` | **0** | **13 tests** ran, 13 passed, 183 assertions (was 12) |
| 3 | `php artisan test --filter=UsersTableSchemaTest` | **0** | 3 tests, 3 passed, 8 assertions |
| 4 | `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` | **1** | 0 tests, `No tests found.` — a zero-match is **not** green |
| 5 | durability: throwaway A, throwaway B, `%TEMP%` trajectory, todo-18 branch | see §6 | 56 today, tracks the set, inverts at todo 18 |
| 6 | `vendor/bin/pint` (bare, **no path argument**) | **0** | `single_quote` on one file; no path passed, so `bootstrap/cache/packages.php` was not touched |
| 7 | `php artisan sehatly:verify-schema --tables=users,roles,permissions,role_permissions,user_roles,user_otp,user_devices,user_refresh_tokens` | **0** | `Discrepancies: 0 (0 drift, 0 informational)`; echoed `scope` lists **all 8 real names** (not the trap) |
| 8 | `php artisan config:clear` → item 7 re-run | **0** | `config.php` absent before and after; item 7 still exit 0 / `Discrepancies: 0` |

## 10. Cleanup receipts

- Throwaway `database/migrations/2026_10_01_000099_zz_probe_table.php` — **created, exercised twice, deleted**. Post-delete: glob `zz_probe*` empty, 22 migration files, `git status --porcelain -- database/` empty, `tests/Unit` 74 passed exit 0.
- `%TEMP%` scratch migration dir `sehatly-migrations-probe-<pid>` — **80 fabricated files removed, `rmdir` succeeded, `is_dir() === false`**.
- No `bootstrap/cache/config.php` created.
- No process started or killed; `mysqld` and the user's `php artisan serve` untouched.
- `telemedicine_test.sql` **byte-unchanged**: SHA-256 `aefe2247e00f09acb02235168ac289cdfa74f762d604ada71f68e328574b27f5` = the law.
- `telemedisin_db` 26 tables, `telemedisin_db_test` 26 tables, `sehatly` 10 tables; 19 domain tables each in the two telemedicine databases, **all empty**; no unrelated database touched.
- Nothing under `database/migrations/**`, `docs/**`, `app/**`, `bootstrap/**`, `config/**`, `routes/**`, `web/**` modified. `docs/schema-notes.md` and `docs/migration-order.md` were **read only** (the latter parsed for the batch→todo map). No `mobile/`, no `pubspec.yaml`, no `install:api`.

## 11. Paths committed

```
tests/Unit/Console/VerifySchemaCommandTest.php   (modified)
tests/Feature/UsersTableSchemaTest.php           (modified)
.omo/evidence/task-8b-test-derivation.md         (added)
```

Not committed, deliberately: `.omo/plans/sehatly-telemedicine-platform.md` (orchestrator's),
`.omo/evidence/task-3-sehatly.md`, `.omo/start-work/` (prior executor's / orchestrator's).

## 12. Residual risk

- The derived sets track the **migration set**. If a migration is authored and not run, the tests go
  red — by design, and with a message naming the exact table. That is a true parity gap.
- The narrow-scope test asserts `Discrepancies: 0` in its exit-0 branch. If a future batch adds a
  *new* non-contract table to the live schema without registering it in `docs/schema-notes.md`, that
  branch would go red — correctly, and the sibling registry test would say so by name first.
- The vacuity guard walks the migrations directory twice per test run (once in the shared
  derivation, once in the probe). Cheap (22 small files) and worth the redundancy: the probe is
  useless if it shares the code path it is auditing.
