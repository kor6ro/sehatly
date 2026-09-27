# Task 6 follow-up — InnoDB FK-support indexes reported as `extra_index` drift

Branch `feat/sehatly-telemedicine`. Read-only w.r.t. every database: no `migrate`,
`migrate:fresh`, `db:seed`, `install:api`, DDL or DML of any kind was issued. The
verifier itself only ever issues `SELECT` / `SHOW`.

---

## 1. The defect

`SchemaDiffer::diffIndexes()` matched every expected index against the live pool
and then reported **every live index it did not consume** as `extra_index` drift.

That is wrong for one class of live index. InnoDB always backs a foreign key with
an index: it reuses an existing index whose leftmost prefix covers the key's
columns, and otherwise *creates* an index on exactly the key's local columns, in
order. `telemedicine_test.sql` (read-only law) declares **80 of its 105 foreign
keys with no covering index at all**, precisely because importing the file lets
MySQL build them — the same thing Laravel's `foreignId()->constrained()` does
explicitly.

So on any table whose FK columns have no declared index, a faithfully built live
schema legitimately carries an index the reference model never names, and the
differ called it drift. Three Batch A tables hit it:

| table | live index the reference does not name |
|---|---|
| `master_kabupaten_kota` | `master_kabupaten_kota_provinsi_id_foreign INDEX (provinsi_id)` |
| `master_kecamatan` | `master_kecamatan_kabupaten_kota_id_foreign INDEX (kabupaten_kota_id)` |
| `master_kelurahan` | `master_kelurahan_kecamatan_id_foreign INDEX (kecamatan_id)` |

Reference side, `telemedicine_test.sql:64-70` — note the FK with no covering index:

```sql
CREATE TABLE master_kabupaten_kota (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  provinsi_id TINYINT UNSIGNED NOT NULL,
  kode CHAR(4) NOT NULL UNIQUE,
  nama VARCHAR(100) NOT NULL,
  FOREIGN KEY (provinsi_id) REFERENCES master_provinsi(id)
) ENGINE=InnoDB;
```

Live side, the real `SHOW CREATE TABLE telemedisin_db.master_kabupaten_kota`:

```sql
CREATE TABLE `master_kabupaten_kota` (
  ...
  KEY `master_kabupaten_kota_provinsi_id_foreign` (`provinsi_id`),
  CONSTRAINT `master_kabupaten_kota_provinsi_id_foreign` FOREIGN KEY (`provinsi_id`) REFERENCES `master_provinsi` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

**Consequence:** the plan's ultimate success criterion was unreachable. Todo 18
requires `verify-schema` to exit 0 printing "75 tables, 2 views verified", and
todos 8-17 each require `verify-schema --tables=…` to exit 0. All were blocked on
this one method.

## 2. The semantics chosen, and why exact ordered equality rather than a prefix

A live index left unconsumed in the leftover pool is **not** reported as
`extra_index` when its ordered column list is **exactly equal** to the local
column list of a foreign key that was **successfully matched** on that same table.

```php
foreach ($remaining as $key => $index) {
    if (in_array($key, $consumed, true)) {
        continue;
    }

    if (isset($implied[$this->columnListKey($index->columns)])) {
        continue;
    }

    $out[] = new Discrepancy('extra_index', $want->name, null, null, $index->label());
}
```

with

```php
private function columnListKey(array $columns): string
{
    return strtolower(implode(',', $columns));
}
```

The implied set is built from the foreign keys `diffForeignKeys()` actually
matched, so the two passes were reordered: `diffForeignKeys()` now runs before
`diffIndexes()` and returns the matched keys. `diff()` ends in
`usort($out, sortKey())` and `sortKey()` begins with the discrepancy `kind`, so
**the report order is unchanged** by the reordering.

**Why exact ordered equality and not a prefix.** InnoDB's two behaviours are: reuse
an index whose *leftmost prefix* covers the FK columns, or create one on *exactly*
the FK columns in order. Only the second *creates* an index. A prefix rule would be
strictly wider than the engine's behaviour and would forgive two classes of index
that no engine would ever produce on its own:

- a deliberate composite index such as `(provinsi_id, nama)` against an FK on
  `provinsi_id` — a query-optimising choice, not an FK artefact;
- a differently ordered list such as `(b, a)` against an FK on `(a, b)` — which
  cannot serve the key as declared, so InnoDB would have created `(a, b)` instead.

Both are real drift, so exact equality is the widest rule that is still precise.

**Two further deliberate narrowings**, both pinned by tests:

- Only a **matched** FK implies anything. An FK that is itself missing leaves the
  index unexplained, so `missing_foreign_key` **and** `extra_index` are both
  reported — a vanished FK never silently forgives its index.
- An index the DDL **named** is consumed by the expected-index loop before the
  leftover pool is reached, so a rename still reads as `index_name` and never as a
  swallowed extra.

### Documentation

Added to the class docblock of `app/Support/Schema/SchemaDiffer.php` as a
`Deliberately not compared:` list next to charset/collation, column order and
inline `UNIQUE` index names:

> **Indexes implicitly created by InnoDB to support a foreign key are implied by
> that FK and are not compared.**

Transparency over silence: the exemption is stated in the code, with the count
(80 of 105) that motivates it.

**Scope conflict, flagged rather than resolved unilaterally.** The verifier's
pre-existing "Deliberately not compared" *prose* list lives in
`docs/schema-notes.md:193`, which the task guardrails put off limits
("MUST NOT edit anything under … `docs/`"), and the allowed-modify list names only
`app/Support/Schema/SchemaDiffer.php`, the command's help text, and my own tests.
The command's help text does not list the comparison set, so the documented
exemption was placed in the differ's own class docblock — an in-scope file. A
follow-up should mirror the bullet into `docs/schema-notes.md` when that path is
writable.

## 3. Tests added

`tests/Unit/Schema/SchemaDifferFkImpliedIndexTest.php` — 7 tests, self-contained
(the reference DDL is copied verbatim from `telemedicine_test.sql:64-70`; the
"live" DDL is the real `SHOW CREATE TABLE` output, parsed with
`NAMES_ARE_SERVER_GENERATED` exactly as `LiveSchemaReader` does).

| # | test | what it proves |
|---|---|---|
| 1 | an index on exactly a matched foreign key's columns is implied, not `extra_index` | the fix. Asserts the **whole** diff is `[]`, not merely that one kind is absent |
| 2 | an extra index on columns no foreign key uses is still reported | a genuine extra survives; total is exactly 1, so the implied one is the only one forgiven |
| 3 | an index that only OVERLAPS a foreign key's columns is still reported | `(provinsi_id, nama)` vs an FK on `provinsi_id` is still `extra_index` — the prefix is not enough |
| 4 | an extra index on a table with NO foreign key is still reported | the exemption cannot fire without a matched FK |
| 5 | an index the DDL named explicitly is matched by name, never swallowed as implied | kinds are exactly `['index_name']` — proof the index was *consumed by the expected loop*, not hidden by the new `continue` |
| 6 | a multi-column foreign key implies only the exact ordered list, so `(b, a)` is still reported | order matters both ways: `(a, b)` forgiven, `(b, a)` reported |
| 7 | an unmatched foreign key does not imply anything: its index is still extra | a missing FK leaves the index unexplained; both `missing_foreign_key` and `extra_index` are reported |

**TDD was observed, not claimed.** Before the production change:
`php artisan test --filter=SchemaDifferFkImpliedIndexTest` → `tests: 7, passed: 3,
failed: 4`, exit 1, with failures exactly where expected — tests 1, 2, 3 and 6
each failing on `extra_index` being present (`line 92`, `line 61` twice, `line 212`).
Tests 4, 5 and 7 passed pre-fix by design: they are the "must not weaken" guards.
After the change: `tests: 7, passed: 7, assertions: 18`, exit 0.

## 4. Commands and exit codes

Project-local PHP 8.4.17 prepended to `$env:PATH` on every invocation
(`C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64`).

| # | command | exit | result |
|---|---|---|---|
| 0 | `Test-Path bootstrap\cache\config.php` | — | `False` (before **and** after `config:clear`) |
| 0 | `php artisan config:clear` | 0 | `INFO Configuration cache cleared successfully.` |
| 1 | `php artisan test --filter=SqlSchemaParserTest` | **0** | 22 tests, 22 passed, 82 assertions |
| 2 | `php artisan test --filter=SchemaDifferTest` | **0** | 30 tests, 30 passed, 69 assertions |
| 3 | `php artisan test --filter=VerifySchemaCommandTest` | **1** | 12 tests, 11 passed — **pre-existing failure, see §6** |
| 3b | `php artisan test --filter=SchemaDifferFkImpliedIndexTest` | **0** | 7 tests, 7 passed, 18 assertions |
| 3c | `php artisan test --testsuite=Unit` (whole suite) | **1** | 73 tests, 72 passed, 1 failed (the same §6 test) |
| 4 | `php artisan sehatly:verify-schema --tables=<11 Batch A tables>` | **0** | `Discrepancies: 0 (0 drift, 0 informational)` — see §5 |
| 5 | `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` | **1** | `tests: 0 … "No tests found."` — a zero-match is not green |
| 6 | `vendor/bin/pint` (bare, **no path argument**) | **0** | first run `fixed` 2 files (both mine); second run `passed` — idempotent |
| 7 | `php artisan sehatly:verify-schema --sql=<mutated copy>` | **1** | see §7 |
| 8 | `php artisan test --filter=SchemaDifferFkImpliedIndexTest --log-junit=<temp>` | **0** | JUnit: `tests=7 failures=0 errors=0`, 7 `<testcase>` elements |

### Existing drift classes (requirement 7)

`--testsuite=Unit` runs all 73 unit tests. Every drift class the pre-existing
`SchemaDifferTest` asserts still behaves identically — its 30 tests pass unchanged
and were not edited. The classes exercised there: `column_type`,
`column_unsigned`, `column_nullable`, `column_default`, `column_auto_increment`,
`missing_column`/`extra_column`, `missing_table`, `missing_index`,
`missing_primary_key`, `index_name`, `index_columns`, `missing_foreign_key`,
`extra_foreign_key`, `foreign_key_action`, `missing_check`, `extra_check`,
`undocumented_extra_table`, `documented_extra_table`, `extra_view`/`missing_view`,
`unknown_requested_table`. The 4 cosmetic no-false-positive classes (backticks /
`KEY`-for-`INDEX` / lower case, the Laravel-style `users_email_unique` name, the
`bigint(20)` display width + quoted numeric default, and nullable-`DEFAULT NULL`
folding) all still report nothing.

## 5. Before / after — the decisive end-to-end proof

Command (identical in both runs, `live database mysql / telemedisin_db`, no cached
config, same 59,604-byte reference, md5 `c76fafa884be`):

```
php artisan sehatly:verify-schema --tables=master_provinsi,master_kabupaten_kota,master_kecamatan,master_kelurahan,master_agama,master_golongan_darah,master_pendidikan,master_status_pernikahan,master_hubungan_keluarga,master_icd10,master_icd9cm
```

### BEFORE — exit 1

```
 Discrepancies: 3 (3 drift, 0 informational)
 extra_index   master_kabupaten_kota    expected: - | actual: master_kabupaten_kota_provinsi_id_foreign INDEX (provinsi_id)
 extra_index   master_kecamatan         expected: - | actual: master_kecamatan_kabupaten_kota_id_foreign INDEX (kabupaten_kota_id)
 extra_index   master_kelurahan         expected: - | actual: master_kelurahan_kecamatan_id_foreign INDEX (kecamatan_id)

 FAIL — 3 discrepancies. The live schema does not match telemedicine_test.sql. Nothing was written.
```

### AFTER — exit 0

```
 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.

 PASS — 75 tables, 2 views verified. Nothing was written.
```

The three `extra_index` lines are gone and nothing replaced them. Re-run after
`vendor/bin/pint` to prove the result is not a formatting artefact: still
`Discrepancies: 0`, exit 0.

**Judged by the discrepancy count and the exit code, never the banner** (plan
Appendix A.7: in `--tables` mode the PASS banner is formatted from the *full*
reference model, so it reads "75 tables, 2 views verified" even for an 11-table
scope). The banner is reproduced here verbatim and is not evidence of anything.

## 6. The one red test, and why it is not mine

`VerifySchemaCommandTest` → "the extra table registry is parsed from
docs/schema-notes.md" fails at `tests/Unit/Console/VerifySchemaCommandTest.php:204`:

```
- 'passkeys'  - 'password_reset_tokens'  - 'sessions'
```

**Proven pre-existing, not caused by this change.** The unmodified HEAD
`SchemaDiffer.php` was written over the working copy and the suite re-run:

```
"head" copy first bytes: 60,63,112          # no BOM, php -l clean
php artisan test --filter=VerifySchemaCommandTest
  -> failed, tests 12, passed 11, failed 1, SAME assertion
BASELINE_EXITCODE=1
restored md5 matches backup: True
php -l app\Support\Schema/SchemaDiffer.php -> No syntax errors
```

The test never calls `SchemaDiffer`; it parses `docs/schema-notes.md` through
`ExtraTableRegistry::fromMarkdown()`. Root cause: commit `c6d0beb`
("feat(db): add migration order contract and master data migrations") removed the
`passkeys`, `password_reset_tokens` and `sessions` rows from that registry, and did
not update the test that asserts them. The registry is now **correct** — those
three tables are genuinely absent from `telemedisin_db`:

```
cache, cache_locks, failed_jobs, job_batches, jobs, migrations,
personal_access_tokens, master_agama, master_golongan_darah,
master_hubungan_keluarga, master_icd10, master_icd9cm, master_kabupaten_kota,
master_kecamatan, master_kelurahan, master_pendidikan, master_provinsi,
master_status_pernikahan        (18 tables)
```

The test is the stale party. Fixing it means editing
`tests/Unit/Console/VerifySchemaCommandTest.php` — a prior executor's file, not
"my own tests" — so it is reported here for the owning lane instead of being
quietly rewritten. Making it green by editing it would have been exactly the
misleading-green failure mode this task warns about.

**This does not block todo 18's `--tables=…` exit-0 criteria**: they invoke the
command, not the test suite. It does mean the Unit suite is not fully green, and
that is a real, named debt.

## 7. Mutated-SQL regression probe

`telemedicine_test.sql` is read-only law. The probe used a copy **outside the
repo**, in `%TEMP%\opencode\fkfix\`, with the `master_agama` block (lines 89-92)
deleted: 1349 → 1345 lines, 59,604 → 59,494 bytes.

**7a — the verifier really reads the mutated file** (full diff, no `--tables`):

```
 counts tables=74 views=2 columns=670 indexes=141 foreign_keys=105 checks=3   # was 75/672/142
 Discrepancies: 74 (67 drift, 7 informational)
 FAIL — 67 discrepancies.
FULL_EXITCODE=1
```

**7b — table-level drift is still caught** (scope to a reference-only table):

```
php artisan sehatly:verify-schema --sql=<mutated copy> --tables=booking
 Discrepancies: 1 (1 drift, 0 informational)
 missing_table booking expected: 22 columns, 4 indexes, 6 foreign keys, 0 checks | actual: -
 FAIL — 1 discrepancy.
MISSING_EXITCODE=1
```

**7c — control against the unmutated original**, same scope, identical output apart
from the table count (`75/672/142` instead of `74/670/141`):

```
 Discrepancies: 1 (1 drift, 0 informational)
 missing_table booking expected: 22 columns, 4 indexes, 6 foreign keys, 0 checks | actual: -
CONTROL_EXITCODE=1
```

**7d — the deleted table is visibly gone from the model.** Scoping to
`master_agama` against the mutated copy yields
`unknown_requested_table` (informational, exit 0) — the documented design
(`docs/schema-notes.md:218`: "Asking for a table the DDL does not define yields
`unknown_requested_table`"). It is not a `missing_table` because the table was
removed from the *reference*, not from the live schema; 7b is the probe that
produces a `missing_table` drift and a non-zero exit.

**7e — the law is byte-unchanged**, before and after all of the above:

```
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
```

which matches the mandated SHA-256 exactly.

## 8. Adversarial classes

**APPLIES — `misleading_success_output`.** The green had to be earned, so the
exemption is pinned from the *other* direction as well as the intended one. After
the fix, with fixtures: a genuinely extra index is caught (test 2, and
`SchemaDifferTest`'s existing coverage); an overlapping index is caught (test 3);
a missing index is caught (existing `missing_index` / `missing_primary_key` tests,
`SchemaDifferTest:169` and `:369`); a wrong column order is caught (existing
`index_columns` test, `SchemaDifferTest:179`; plus test 6's reversed `(b, a)`);
a PRIMARY/UNIQUE/NORMAL type mismatch is caught (`SchemaDifferTest:156`'s
`takeBySemantics` path, which requires identical `type`); an unmatched FK is
caught (test 7). End-to-end, the `booking` probe (§7b) shows the command still
exits 1 on real drift after the change. No blanket suppression exists: the
`continue` is guarded by one `isset()` against a set derived only from *matched*
FKs, and `diff()` still exits 1 on 67 real drifts in §7a.
Honest limit: the live database holds only Batch A, whose 3 FK tables are exactly
the ones now forgiven, so the e2e green is only earned for the 11 in-scope tables.
The unit fixtures are what carry the "still catches extras" burden.

**APPLIES — `stale_state`.** `bootstrap\cache\config.php` did not exist before any
run and `php artisan config:clear` was run anyway (exit 0); it still does not
exist afterwards. `GIT_INDEX_FILE` was `[]` (unset). Every run printed
`live database mysql / telemedisin_db` and `reference SQL telemedicine_test.sql
(59,604 bytes, md5 c76fafa884be)`, so no run was pointed at a stale or wrong
database or reference. The one failure found (§6) was investigated to its owning
commit rather than dismissed as flaky.

**APPLIES — `dirty_worktree`.** `.omo/plans/sehatly-telemedicine-platform.md`
(modified — the orchestrator's) and `.omo/evidence/task-3-sehatly.md` +
`.omo/start-work/` (untracked — a prior executor's) were left untouched and
uncommitted. `git add`/`git commit` were given explicit pathspecs only; the
post-commit assertions are in §10.

**APPLIES — `hung_or_long_commands`.** Every command had an explicit timeout
(120 s-600 s) and reported an observed exit code. The pre-existing `mysqld`
(17484/19796) and the user's `php artisan serve` (PID 22288) were never signalled;
`mysql` was used in read-only `-t`/`-N` mode for the table listings.

**APPLINES — `repeated_interruptions`.** A pure code change with no schema effect,
so re-running must be idempotent. Proved three ways: `vendor/bin/pint` run twice
(`fixed` then `passed`); the 11-table verifier run before and after pint
(identical `Discrepancies: 0`, exit 0 both times); `telemedisin_db` still holds
**18** tables and `sehatly` still **10**, unchanged from the pre-work baseline
recorded before any edit.

**RULED OUT — `malformed_input`.** No new parser: the change consumes the
already-parsed `IndexSpec::$columns` and `ForeignKeySpec::$columns` from the
existing spec objects. The mutated-SQL probe (§7) exercises malformed/degraded
input through the existing `SchemaParseException` path, which still yields exit 1,
not a silent pass.

**RULED OUT — `prompt_injection`.** No untrusted text is read or executed. The
only string processed is SQL identifier casing, folded with `strtolower`, and it
only ever becomes an array key.

**RULED OUT — `cancel_resume`.** No resumable user flow. One synchronous,
idempotent code change; re-running it is a no-op, as §8 shows.

**RULED OUT — `flaky_tests`.** Deterministic: pure functions over parsed
`SchemaSpec` objects, no clocks, no randomness, no ordering dependence (`diff()`
re-sorts by `sortKey()`, which is why the FK/index reordering is invisible in the
report). The one measured flake risk is the zero-match class — item 5 of §4 shows
a filter matching nothing exits **1**, so a vacuous pass cannot be mistaken for
green.

## 9. Cleanup receipts

All temp artefacts were created **outside** the repository, under
`C:\Users\axioo\AppData\Local\Temp\opencode\`, and deleted afterwards:

| artefact | purpose | receipt |
|---|---|---|
| `temp\opencode\verify-before.txt` | BEFORE verifier transcript | deleted |
| `temp\opencode\verify-after.txt` | AFTER verifier transcript | deleted |
| `temp\opencode\SchemaDiffer.mine.bak` | backup used for the §6 A/B swap | deleted |
| `temp\opencode\SchemaDiffer.head.php` | HEAD copy used for the §6 A/B swap | deleted |
| `temp\opencode\fkfix\telemedicine_test_mutated.sql` | mutated-SQL probe copy | deleted |
| `temp\opencode\fkfix\junit-fkfix.xml` | `--log-junit` output | deleted |

`bootstrap/cache/packages.php` / `services.php` were not touched — `vendor/bin/pint`
was run **bare**, never with `bootstrap` or `.` as a path argument (plan
Appendix A.7), and its `passed` result on the second run confirms the cache exclude
is intact.

Final state:

```
telemedicine_test.sql SHA-256  AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5  (unchanged)
telemedisin_db                 18 tables  (unchanged)
sehatly                        10 tables  (unchanged)
db_simprapkl, gawaiseken, manajemen-surat, trading_journal, ukk,
ukk_pengaduan_sekolah, laravel  not connected to at any point
```

## 10. Paths committed

```
app/Support/Schema/SchemaDiffer.php                    (modified)
tests/Unit/Schema/SchemaDifferFkImpliedIndexTest.php   (created)
.omo/evidence/task-6-fk-index-fix.md                   (created)
```

`app/Console/Commands/VerifySchemaParity.php` was **not** modified: its signature,
description and help text do not enumerate the comparison set, so there was nothing
to document there.

Command: `fix(dev): treat InnoDB FK-support indexes as implied rather than drift`
