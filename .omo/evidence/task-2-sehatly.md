# Task 2 evidence — switch development and test databases to MySQL 8

Plan: `.omo/plans/sehatly-telemedicine-platform.md` todo 2
Branch: `feat/sehatly-telemedicine`
PHP used for every command: `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17), prepended to `$env:PATH` per `docs/pre-existing-defects.md` 1.1
Laravel: 13.33.0

---

## 0. M1 — Baseline characterization (BEFORE any change)

Rule: pin the current observable behavior with a test that passes on the
**unchanged** `phpunit.xml`, so the later change is provably the only delta.

`phpunit.xml` as found pinned `DB_CONNECTION=sqlite` (line 26) and
`DB_DATABASE=:memory:` (line 27).

`tests/Unit/DatabaseEngineTest.php` as created for the baseline:

```php
<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

test('test suite runs on the sqlite engine pinned by phpunit.xml', function () {
    $driver = DB::connection()->getDriverName();

    expect($driver)->toBe('sqlite');
});
```

Command and verbatim output:

```
PS> php artisan test --filter=DatabaseEngineTest --log-junit=...\junit-baseline.xml
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":1,"duration_ms":247}
EXITCODE=0
```

JUnit detail (the stdout of this environment is reduced to a JSON summary by the
harness, so the JUnit XML is recorded as the unabridged artifact):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="C:\Users\axioo\Desktop\sehatly\phpunit.xml" tests="1" assertions="1" errors="0" failures="0" skipped="0" time="0.244267">
    <testsuite name="Unit" tests="1" assertions="1" errors="0" failures="0" skipped="0" time="0.244267">
      <testsuite name="Tests\Unit\DatabaseEngineTest" file="tests\Unit\DatabaseEngineTest.php" tests="1" assertions="1" errors="0" failures="0" skipped="0" time="0.244267">
        <testcase name="test suite runs on the sqlite engine pinned by phpunit.xml" file="tests\Unit\DatabaseEngineTest.php::test suite runs on the sqlite engine pinned by phpunit.xml" class="Tests\Unit\DatabaseEngineTest" classname="Tests.Unit.DatabaseEngineTest" assertions="1" time="0.244267"/>
      </testsuite>
    </testsuite>
  </testsuite>
</testsuites>
```

**Baseline asserted:** `DB::connection()->getDriverName() === 'sqlite'` — 1 test,
1 assertion, 0 failures, exit 0, on the untouched codebase.

---

## 1. Section 6 / 7 — Automated verification

### 1.1 `php artisan db:create-test-database` — first run (creates)

```
PS> php artisan db:create-test-database

 INFO Database [telemedisin_db_test] created with utf8mb4 / utf8mb4_unicode_ci.

EXITCODE=0
```

### 1.2 `php artisan db:create-test-database` — second run (idempotency)

```
PS> php artisan db:create-test-database

 INFO Database [telemedisin_db_test] already exists, nothing to create.

EXITCODE=0
```

```
PS> php artisan db:create-test-database

 INFO Database [telemedisin_db_test] already exists, nothing to create.

EXITCODE=0
```

Run three times total: **exit 0 every time, no exception, no throw.** The
command branches on an `information_schema.schemata` lookup before issuing DDL,
so the re-run is a genuine no-op rather than a swallowed `SQLSTATE[HY000]
[42S04]` from a bare `CREATE DATABASE` error path.

Command is registered and discoverable:

```
PS> php artisan list | Select-String "db:"
  db:create-test-database   Create the MySQL test database with utf8mb4 / utf8mb4_unicode_ci if it is missing
  db:monitor                Monitor the number of connections on the specified database
  db:seed                   Seed the database with records
  db:show                   Display information about the given database
  db:table                  Display information about the given database table
  db:wipe                   Drop all tables, views, and types
```

### 1.3 MySQL version string

```
PS> php artisan tinker --execute="dump(DB::selectOne('select version() as v')->v);"
"8.0.30" // vendor\psy\psysh\src\ExecutionClosure.php(41) : eval()'d code:1
EXITCODE=0
```

### 1.4 Test database exists with the right charset / collation

The task's literal `information_schema.SCHEMATA` form (single quoted schema name)
cannot be passed through PowerShell 5.1 + `tinker --execute` without mangling the
quotes (two attempts failed: `InvalidArgumentException Unexpected end of input.`
and `PHP Parse error: Syntax error, unexpected T_STRING`). The identical query was
therefore run through a temporary bootstrap script **outside the repo** at
`C:\Users\axioo\AppData\Local\Temp\opencode\probe-schemata.php` (deleted in
section 8). Verbatim output:

```
--- version() ---
8.0.30
--- information_schema.SCHEMATA (single test db, task section 7 form) ---
array (
  'DEFAULT_CHARACTER_SET_NAME' => 'utf8mb4',
  'DEFAULT_COLLATION_NAME' => 'utf8mb4_unicode_ci',
)
--- information_schema.SCHEMATA (all databases) ---
db_simprapkl             | utf8mb4 | utf8mb4_0900_ai_ci
gawaiseken              | utf8mb4 | utf8mb4_0900_ai_ci
information_schema       | utf8mb3 | utf8mb3_general_ci
laravel                  | utf8mb4 | utf8mb4_0900_ai_ci
manajemen-surat          | utf8mb4 | utf8mb4_0900_ai_ci
mysql                    | utf8mb4 | utf8mb4_0900_ai_ci
performance_schema       | utf8mb4 | utf8mb4_0900_ai_ci
sehatly                  | utf8mb4 | utf8mb4_unicode_ci
sys                      | utf8mb4 | utf8mb4_0900_ai_ci
telemedisin_db_test      | utf8mb4 | utf8mb4_unicode_ci
trading_journal          | utf8mb4 | utf8mb4_0900_ai_ci
ukk                      | utf8mb4 | utf8mb4_0900_ai_ci
ukk_pengaduan_sekolah    | utf8mb4 | utf8mb4_0900_ai_ci
--- table counts per database (sehatly data safety) ---
sehatly                  tables=10
telemedisin_db           tables=0
telemedisin_db_test      tables=0
--- sehatly inventory (must be unchanged by this task) ---
cache                          rows=0        collation=utf8mb4_unicode_ci
cache_locks                    rows=0        collation=utf8mb4_unicode_ci
failed_jobs                    rows=0        collation=utf8mb4_unicode_ci
job_batches                    rows=0        collation=utf8mb4_unicode_ci
jobs                           rows=0        collation=utf8mb4_unicode_ci
migrations                     rows=5        collation=utf8mb4_unicode_ci
passkeys                       rows=0        collation=utf8mb4_unicode_ci
password_reset_tokens          rows=0        collation=utf8mb4_unicode_ci
sessions                       rows=1        collation=utf8mb4_unicode_ci
users                          rows=0        collation=utf8mb4_unicode_ci
sehatly total (approximate, from information_schema): 6
EXITCODE=0
```

`telemedisin_db_test` -> `utf8mb4` / `utf8mb4_unicode_ci`. Confirmed.

### 1.5 `php artisan test --filter=DatabaseEngineTest` — post-change

Full verbatim stdout of this environment (the harness reduces Pest output to a
JSON summary; unabridged detail is in the JUnit XML shown next):

```
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":3,"duration_ms":238}

EXITCODE=0
```

```xml
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="C:\Users\axioo\Desktop\sehatly\phpunit.xml" tests="1" assertions="3" errors="0" failures="0" skipped="0" time="0.227857">
    <testsuite name="Unit" tests="1" assertions="3" errors="0" failures="0" skipped="0" time="0.227857">
      <testsuite name="Tests\Unit\DatabaseEngineTest" file="tests\Unit\DatabaseEngineTest.php" tests="1" assertions="3" errors="0" failures="0" skipped="0" time="0.227857">
        <testcase name="the test suite runs on the MySQL 8 test database" file="tests\Unit\DatabaseEngineTest.php::the test suite runs on the MySQL 8 test database" class="Tests\Unit\DatabaseEngineTest" classname="Tests.Unit.DatabaseEngineTest" assertions="3" time="0.227857"/>
      </testsuite>
    </testsuite>
  </testsuite>
</testsuites>
```

**`tests="1"`, `failures="0"`, `errors="0"` — the filter matched exactly one
test, not zero.** Post-pint re-run identical: `tests:1, passed:1, assertions:3`,
exit 0.

### 1.6 `config/database.php` untouched

```
PS> git diff --name-only HEAD~1 HEAD -- config/database.php
(empty)
```

Recorded again post-commit in section 5.

---

## 2. M4 / M7 — data-safety decision

`.env` **was not edited.** Creating the databases did not require it, and the
task's rule is to report rather than silently repoint the developer's live
connection away from a database that holds their data.

`telemedisin_db` (the new dev database) **did not exist** and was created with
the same idempotent provisioning path, using the `DB_TEST_DATABASE` override so
no code change was needed:

```
PS> $env:DB_TEST_DATABASE='telemedisin_db'; php artisan db:create-test-database

 INFO Database [telemedisin_db] created with utf8mb4 / utf8mb4_unicode_ci.

EXITCODE=0

PS> php artisan db:create-test-database

 INFO Database [telemedisin_db] already exists, nothing to create.

EXITCODE=0
```

`sehatly` — **not dropped, not truncated, not deleted, not migrated, not
renamed.** Only `SELECT` against `information_schema` was ever issued against
it. Its inventory is recorded in 1.4 and was re-read after every subsequent
command; it is byte-identical (10 tables, same collations, `migrations` 5 rows,
`sessions` 1 row).

**Reported, not fixed — `.env` still reads `DB_DATABASE=sehatly`.** The exact
one-line change needed is:

```
DB_DATABASE=sehatly   ->   DB_DATABASE=telemedisin_db
```

This is escalated rather than applied because it is the precise moment artisan
stops writing to the legacy schema, and that should be an owner decision. It is
also recorded in `docs/pre-existing-defects.md` section 5.4.

---

## 3. M5 — assertion delta, pre-change vs post-change

Pre-change (`tests/Unit/DatabaseEngineTest.php`):

```php
expect($driver)->toBe('sqlite');
```

Post-change:

```php
expect($connection->getDriverName())->toBe('mysql');
expect($connection->getDatabaseName())->toBe('telemedisin_db_test');
expect($connection->getServerVersion())->toStartWith('8.');
```

The diff is **only** the driver name `sqlite` -> `mysql`, plus the new `8.`
version assertion required by M5. The `getDatabaseName()` assertion is an
addition, not a modification: it exists so that a run which accidentally lands
on the dev database (same driver, same server, real data) fails instead of
passing — see 5.2 where forcing the dev database name makes exactly that
assertion fail.

---

## 4. Section 9 — Negative / failure QA (does the gate actually bite?)

Run in a child PowerShell job so the invoking shell's environment was never
mutated.

### 4.1 Force `DB_CONNECTION=sqlite`

```
ENV SEEN BY PHP: DB_CONNECTION=sqlite
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":294,"failed":1,"failures":[{"test":"P\\Tests\\Unit\\DatabaseEngineTest::__pest_evaluable_the_test_suite_runs_on_the_MySQL_8_test_database","file":"\\Users\\axioo\\Desktop\\sehatly\\tests\\Unit\\DatabaseEngineTest.php","line":19,"message":"Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'mysql'\n+'sqlite'","trace":["C:\\Users\\axioo\\Desktop\\sehatly\\tests\\Unit\\DatabaseEngineTest.php:19"]}]}

EXITCODE=1
```

Junit: `tests="1" assertions="1" errors="0" failures="1"`, and

```xml
<failure type="PHPUnit\Framework\ExpectationFailedException">the test suite runs on the MySQL 8 test databaseFailed asserting that two strings are identical.&#13;
at tests\Unit\DatabaseEngineTest.php:19</failure>
```

**Exactly the "expected mysql, got sqlite" assertion the plan demands.** Exit 1.

Because the driver assertion is the *first* statement, the failure happens
before any query is issued — verified by 5.3: no `telemedisin_db_test` file was
left in the repo root by the sqlite run.

### 4.2 Restore proof

```
DB_CONNECTION=[]
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":3,"duration_ms":337}

EXITCODE=0
```

JUnit: `tests="1" assertions="3" errors="0" failures="0"`.

### 4.3 Environment and file integrity after the experiment

```
PS> "DB_CONNECTION in this shell: [$env:DB_CONNECTION]"
DB_CONNECTION in this shell: []
PS> "DB_TEST_DATABASE in this shell: [$env:DB_TEST_DATABASE]"
DB_TEST_DATABASE in this shell: []
PS> "DB_DATABASE in this shell: [$env:DB_DATABASE]"
DB_DATABASE in this shell: []

PS> git diff -- phpunit.xml
 phpunit.xml | 4 ++--
 1 file changed, 2 insertions(+), 2 deletions(-)
-        <env name="DB_CONNECTION" value="sqlite"/>
-        <env name="DB_DATABASE" value=":memory:"/>
+        <env name="DB_CONNECTION" value="mysql"/>
+        <env name="DB_DATABASE" value="telemedisin_db_test"/>
```

Shell env is clean (the negative run used `Start-Job`, i.e. a separate process,
and unset the variable itself). `phpunit.xml` still shows only the two intended
line changes — the experiment did not modify it.

### 4.4 Second negative probe — force the dev database name

```
ENV SEEN BY PHP: DB_DATABASE=telemedisin_db
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":2,"duration_ms":275,"failed":1,"failures":[{"test":"P\\Tests\\Unit\\DatabaseEngineTest::__pest_evaluable_the_test_suite_runs_on_the_MySQL_8_test_database","file":"\\Users\\axioo\\Desktop\\sehatly\\tests\\Unit\\DatabaseEngineTest.php","line":23,"message":"Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'telemedisin_db_test'\n+'telemedisin_db'","trace":["C:\\Users\\axioo\\Desktop\\sehatly\\tests\\Unit\\DatabaseEngineTest.php:23"]}]}

EXITCODE=1
```

`assertions="2"`, not `1` — the driver assertion **passed** (so `phpunit.xml`'s
`mysql` really is what feeds the run) and the database-name assertion failed.
This is the `stale_state` proof in 5.1: the test cannot be pointed at the dev
database, or at any other MySQL schema, and still go green.

---

## 5. Section 8 — Adversarial classes

### 5.1 `stale_state` — APPLIES, probed

- **Cached config cannot mask `phpunit.xml`.** `bootstrap/cache/` was inspected
  and contained only `.gitignore`, `packages.php`, `services.php` — **no
  `config.php`, no `routes-v7.php`**. `php artisan config:clear` was run before
  the baseline and before the post-change run; both exited 0 with
  `Configuration cache cleared successfully.`
- **`phpunit.xml` is provably the live source, not a coincidence.** Two
  independent force-env probes (4.1, 4.4) each broke a different assertion
  while leaving the *other* phpunit-sourced value intact, which is only
  possible if phpunit.xml's `<env>` values are being read and are being
  overridden per-key by the process environment. PHPUnit's `<env>` has no
  `force="true"`, which is precisely why an unset variable falls through to
  `phpunit.xml` and a preset one wins.
- **The test really connected to MySQL, not a mock.** `getServerVersion()`
  cannot return `8.x` without a live PDO handshake; the assertion passed only
  because a real 8.0.30 server answered. Independently cross-checked by
  `select version()` in 1.3 and 1.4.
- **The idempotent re-run did not silently no-op against the wrong database.**
  The "already exists" message prints the name it looked up, and 1.4 confirms
  `telemedisin_db_test` really is the schema carrying `utf8mb4` /
  `utf8mb4_unicode_ci`. The command also nulls `database` from the connection
  config before connecting, so it reaches the server regardless of whether the
  *default* database exists — and the pre-existing `sehatly` schema is never
  the target.

### 5.2 `misleading_success_output` — APPLIES, probed

`--filter=DatabaseEngineTest` matching zero tests is a real risk, because a
zero-test run can exit 0. Probed three ways:

- The JSON summary carries an explicit `tests` field: `tests:1` on every green
  run. A zero-match run would report `tests:0`.
- The JUnit XML carries `tests="1"` on both the root suite and the
  `Tests\Unit\DatabaseEngineTest` suite, and names the single executed testcase.
- The negative runs prove the filter is not matching a *different, always-green*
  test: with `DB_CONNECTION=sqlite` the same filter produced `failures="1"` at
  `tests/Unit/DatabaseEngineTest.php:19`, and with `DB_DATABASE=telemedisin_db`
  it produced `failures="1"` at line 23. A filter that matched nothing could not
  emit either.

**Confirmed `tests >= 1` on every reported run.**

### 5.3 `dirty_worktree` — APPLIES, probed

A concurrent worker (todo 5) was actively moving `resources/js/**` and
`resources/css/**` into `web/` and had **already staged its renames in the
shared index** while this task ran. Observed index state at commit time
included `R components.json -> web/components.json`, `R resources/js/lib/utils.ts
-> web/src/lib/utils.ts`, `R resources/css/app.css -> web/src/styles/app.css`,
and 30 `R resources/js/components/ui/* -> web/src/components/ui/*` entries.

Handling: `git add` and `git commit` were both scoped to explicit pathspecs
only. `git add -A`, `git add .`, `git commit -a`, `git add -u`, `git stash`,
`git checkout .`, `git restore .`, `git clean` and `git reset` were **not used**
at any point. `vendor/bin/pint` was additionally scoped to this task's two PHP
files rather than run bare, so it could not reformat the other lane's in-flight
work. Post-commit assertions are in section 6 of this file and in the DoneClaim.

### 5.4 `hung_or_long_commands` — APPLIES, probed

Every `php artisan` and `php` invocation ran inside a `Start-Job` guarded by
`Wait-Job -Timeout`. Test runs were capped at 300 s and the direct command runs
at the tool's 120 s default. Every run returned an observed exit code; none
reached its timeout and none were left running. **No `php artisan migrate` was
run at all** — see 5.6 — so no metadata lock could be taken on
`telemedisin_db_test`.

### 5.5 `repeated_interruptions` — APPLIES, probed

The command is written to converge: it probes `information_schema.schemata`
first and only issues `CREATE DATABASE` when the schema is absent, so it is
idempotent by construction rather than by catching an exception. It was run
**three** times against `telemedisin_db_test` and **two** times against
`telemedisin_db`; runs 2 and 3 and run 2 respectively reported "already exists"
and exited 0. Its config mutation is scoped to a single `purge`/`restore` pair
in a `finally` block, so an interruption mid-run cannot leave the mysql
connection pinned to a null database for the rest of the process. Post-run,
`DB::selectOne('select version()')` still works and the test still passes (1.5),
which proves the connection config was restored.

### 5.6 Deliberate scope decision — the full suite was NOT run

`php artisan test` (no filter) was intentionally not executed. `tests/Pest.php`
applies `RefreshDatabase` to the whole `Feature` directory, so running the full
suite against the now-MySQL `phpunit.xml` would have run the migration set
against the brand-new empty `telemedisin_db_test` and **created tables there**.
Per the task's constraint that this todo must not create any table, and per
`docs/pre-existing-defects.md` 3.4 (which already records that the auth tests
reference deleted classes and are expected to fail until their todos land), the
full suite belongs to the migration todos. Only `--filter=DatabaseEngineTest`
was run, and that test does not touch the schema.

### 5.7 Ruled out

| Class | One-line reason |
|---|---|
| `malformed_input` | No input parser was authored; the only parsing is MySQL's, and the one identifier this task controls (`DB_TEST_DATABASE`) is whitelist-validated against `/^[A-Za-z0-9_]+$/` before being interpolated into DDL. |
| `prompt_injection` | No untrusted external text is consumed; inputs are `phpunit.xml`, `.env`, and the local MySQL server. |
| `cancel_resume` | No resumable user flow exists yet; those are todos 20/45/46. |
| `flaky_tests` | One deterministic assertion triple against a single local server. The one flake-shaped risk that does apply — `--filter` matching zero tests — is probed and reported under `misleading_success_output` (5.2). |

---

## 6. Section 5 / 10 — Scope of the commit and cleanup

Committed paths (the only paths this task staged or committed):

```
phpunit.xml
.env.example
app/Console/Commands/CreateTestDatabaseCommand.php
tests/Unit/DatabaseEngineTest.php
docs/pre-existing-defects.md
.omo/evidence/task-2-sehatly.md
```

Deliberately **not** touched, confirmed by `git show --name-only --format="" HEAD`
in the DoneClaim:

- `config/database.php` — its `mysql` connection at lines 47-65 already carried
  `charset` `utf8mb4` and `collation` `utf8mb4_unicode_ci`, so it needed nothing
- `telemedicine_test.sql` — read at lines 1-20 for the `CREATE DATABASE`
  reference only; never edited, re-encoded, reordered, or executed
- `bootstrap/app.php`, `routes/`, `app/Http/`, `resources/`, `web/`,
  `database/migrations/`, `composer.json`, `composer.lock` — other lanes own
  these; the plan's own `withRouting(commands:)` was left alone
- No `mobile/` directory and no `pubspec.yaml` was created

Cleanup receipts:

- `C:\Users\axioo\AppData\Local\Temp\opencode\probe-schemata.php` — temporary
  bootstrap probe created outside the repo, **deleted**.
- `C:\Users\axioo\AppData\Local\Temp\opencode\junit-baseline.xml`,
  `junit-post.xml`, `junit-restore.xml`, `junit-neg.xml`, `junit-neg2.xml` —
  JUnit artifacts outside the repo, **deleted**.
- **No scratch database was ever created.** The only databases this task
  provisioned are `telemedisin_db` and `telemedisin_db_test`, both of which are
  deliverables and must be kept. There is therefore nothing to drop, and no
  drop was performed. `db:wipe` / `DROP DATABASE` were never invoked.
- No `php`, `tinker`, or queue process was left running. The pre-existing
  `mysqld` and the owner's pre-existing `php artisan serve` (PID 22288) were not
  touched — no `Stop-Process`, no `taskkill` was issued by this task.
- `database/database.sqlite` (0 bytes, gitignored via `database/.gitignore:1`,
  created `9/25/2026 10:43:10 PM`) is **pre-existing** and was left in place.
