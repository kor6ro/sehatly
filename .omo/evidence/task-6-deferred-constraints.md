# Task 6 — deferred-constraint registry for the schema-parity verifier

Branch `feat/sehatly-telemedicine`. Plan authority: **Appendix A.10**, with A.7 and A.8
as standing constraints on how the verifier's output may be read, and A.9 on how
expectations in this repo are derived.

---

## 1. The gap

`pasien_tanda_vital.rekam_medis_id` must have **no** foreign key at this position in
the migration order, because `rekam_medis` does not exist until batch G (todo 13).
The plan itself mandates deferring the constraint to migration `2026_10_01_000076`,
per the reference DDL's own section `[14]` (`telemedicine_test.sql:1161-1163`):

```sql
ALTER TABLE pasien_tanda_vital
  ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)
  REFERENCES rekam_medis(id) ON DELETE SET NULL;
```

`SqlSchemaParser` folds that `ALTER TABLE ... ADD CONSTRAINT` into the table it
targets, so the **expected** model demands `fk_vital_rm` from the first commit while
the live schema cannot legally hold it. `SchemaDiffer` therefore reported it as
drift and the scoped run exited 1, which made todo 9's acceptance criterion 2
structurally unsatisfiable — the same class A.6 describes. **The verifier had no way
to say "intentionally not here yet".**

Measured before the change, `php artisan sehatly:verify-schema --tables=<batch C>`:

```
Discrepancies: 1 (1 drift, 0 informational)
missing_foreign_key pasien_tanda_vital expected: fk_vital_rm FOREIGN KEY
  (rekam_medis_id) -> rekam_medis (id) ON DELETE SET NULL ON UPDATE RESTRICT | actual: -
FAIL - 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.
EXIT=1
```

This is a **plan-sequencing defect, not a migration bug**. Todo 9's migrations are
correct; the verifier's vocabulary was incomplete.

## 2. The design, and the three rules

A **deferred-constraint registry** in
`app/Support/Schema/DeferredConstraintRegistry.php`, exactly parallel to
`ExtraTableRegistry` and sourced from the same file, `docs/schema-notes.md`, so the
two registries sit together. It follows the existing class's conventions in every
respect: the same markdown-table shape (backticked first cell, one row per line,
**last** cell is the justification, a blank justification becomes
`registered without a justification`), the same `\r\n|\n|\r` line tolerance, the same
`documented = informational / undocumented = drift` enforcement shape, the same
**missing-or-empty registry is a hard error** (the command's `catch (Throwable)`
turns the `RuntimeException` into **exit 2**), and the same `--notes=<path>` override
for testing.

| # | Rule | Report row | Drift? |
|---|---|---|---|
| 1 | a `missing_foreign_key` **registered as deferred** | `deferred_foreign_key`: `expected:` is the constraint label (name, target, actions), `actual:` is `absent by design - <justification>` | **no** |
| 2 | a registered constraint that is **present in the live schema** | `fulfilled_deferred_foreign_key`: `expected:` is `still registered as deferred in docs/schema-notes.md - <justification>`, `actual:` is the live key's label | **yes** |
| 3 | a `missing_foreign_key` **not registered** | `missing_foreign_key`, unchanged from today | **yes** |

Rule 1 still prints the constraint **by name**, so an outstanding deferral stays
visible in the report instead of silently vanishing. The style follows the existing
informational rows: the justification goes in one cell and the live fact in the
other, exactly as `documented_extra_table` does.

**Rule 2 is the load-bearing one**, and it is what makes todo 18 self-enforcing: the
moment migration 76 adds `fk_vital_rm`, the still-present registry row becomes an
error that forces the registry to be updated in the same commit. Without it a row
could excuse the constraint forever and todo 18's "75 tables, 2 views verified" would
pass with the foreign key still absent. Rule 2 is **not optional and was not
weakened**; it has its own test (section 6) and a first-class branch in the
command-level test that asserts the todo-18 shape explicitly, so nothing needs
editing on the day.

Rule 2 cannot be satisfied by an empty registry: with no registry there is nothing
for it to fire on, which is the correct behaviour, and the existing
**missing-or-empty = exit 2** rule covers that gap (section 4, items 3, 3b, 3c).

### Keying: the constraint NAME, and why not table + column

The key is **`fk_vital_rm`**, the constraint name, lower-cased and matched
case-insensitively like every other name the differ compares.

`fk_vital_rm` is the **only** name the reference DDL itself writes for a foreign
key (`:1162`, added by the `ALTER`), and the verifier's own report says so on every
run: `named FKs fk_vital_rm on pasien_tanda_vital (line 1161)`. That is what makes
it the only stable handle on *this specific constraint is deferred*.

A **table + column** key would be materially weaker: it would forgive **any** foreign
key on `pasien_tanda_vital.rekam_medis_id` — a different target table, a different
`ON DELETE`, a `RESTRICT` where the contract says `SET NULL`, or an unrelated
constraint a future migration adds there. One row would silently excuse five
different mistakes. Matching on the justification *text* would be worse still, since
`docs/schema-notes.md` stores a paragraph per row.

The same reasoning is enforced in code: `SchemaDiffer::deferral()` refuses any key
whose `nameIsAuthoritative` is false, because MySQL names an inline `FOREIGN KEY`
`<table>_ibfk_<n>` and an engine-generated name is not something anyone can register.
**The registry's blast radius is therefore exactly one constraint and cannot grow by
accident** — a second row would have nothing left to defer. Both facts are asserted
in `the full run still fails...` and in `every registered deferral names a constraint
the reference DDL itself wrote`.

### One structural change, and why it was unavoidable

Two machine-read markdown tables keyed on a backticked identifier cannot share one
file safely: the extra-table parser would read `fk_vital_rm` as a registered extra
**table** and forgive a table that is really drift. `docs/schema-notes.md` already
warned about exactly this ("Do not add any other markdown table in this file whose
first cell is a backticked identifier"). Rather than dodge the warning by leaving the
deferred rows un-backticked, a footgun that re-arms the moment someone adds backticks
for readability, the warning is made obsolete by construction:

- `app/Support/Schema/SchemaNotesSection.php` (new, 78 lines) resolves a markdown
  `## ` section to a list of lines. A heading of **any** level opens or closes a
  section; the heading is matched case-insensitively and tolerates trailing
  whitespace and a trailing `##`. A level-1 heading closes without opening, so
  `# Schema notes` at the top of the file is inert.
- `ExtraTableRegistry` now reads **only** the rows under its own `HEADING`
  (`Registered extra tables`); `DeferredConstraintRegistry` reads only the rows under
  `Deferred constraints`. Neither can see the other's rows, nor any other table in
  the file.

The only behaviour change to `ExtraTableRegistry` is that it is now *narrower*: a row
outside its section is no longer registered, so a misplaced row surfaces as
`undocumented_extra_table` **drift** instead of silently forgiving a table. That is
the safe direction, and both of its existing tests still pass unmodified.

## 3. `docs/schema-notes.md`

A new `## Deferred constraints` section, immediately after
`## Registered extra tables` so the two registries sit together. It states the three
rules, the keying decision, the mandatory-registry consequence, and carries one row:

| Constraint | Table | Added by | Justification |
| --- | --- | --- | --- |
| `fk_vital_rm` | `pasien_tanda_vital` | `2026_10_01_000076` | Column `rekam_medis_id` is present; the FK is added by migration 76 per SQL section `[14]` (`:1161-1163`) because `rekam_medis` does not exist until batch G (todo 13). Full reasoning in *Batch-C deferred and deliberately unconstrained columns* below. |

Todo 9's loose prose was **folded into** the new section rather than left as prose:

- the `pasien_tanda_vital.rekam_medis_id` bullet now points at the machine-readable
  row instead of restating it, and keeps the MySQL-1824 and
  `information_schema.REFERENTIAL_CONSTRAINTS` reasoning;
- the **`pasien_penjamin.faskes_rujukan_id` bullet is corrected.** Todo 9's prose
  called it an "FK to `faskes` deferred to migration 76". The plan's authoritative
  no-foreign-key list (line 152) records `:346` as carrying **no** FK, and live
  measurement agrees: the only drift this batch has ever reported is `fk_vital_rm`.
  The authoritative list wins, so the bullet now records a bare unsigned `BIGINT`
  with no FK owed, **explicitly retracts the old sentence**, and warns that
  registering it would promise migration 76 a constraint the contract never asks for.
  The column was **not** touched and is **not** registered;
- `pasien_riwayat_penyakit.icd10_kode` stays prose, because the DDL declares no
  constraint there, so there is nothing for a registry to excuse.

A known consequence is written into both the notes file and the class docblock: an
**empty** registry is an error, so when the last deferral is resolved the correct end
state is to delete the section **and** its
`DeferredConstraintRegistry::fromMarkdown()` call together. Leaving an empty section
behind would exit 2 forever. Rule 2 is what tells you to do it.

## 4. Verification: every command, with its observed exit code

`$env:PATH = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64;$env:PATH"` is the
first line of every shell. No `migrate`, `migrate:fresh`, `migrate:rollback`,
`db:seed` or `db:wipe` was run against any database; no `install:api`; no path
argument to pint.

| # | Command | Exit | Observed |
|---|---|---|---|
| 1 | `php artisan sehatly:verify-schema --tables=pasien,...` (the eight batch-C names) | **0** | `notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred constraint)`; `Discrepancies: 1 (0 drift, 1 informational)`; one `deferred_foreign_key` row naming `pasien_tanda_vital` and `fk_vital_rm`; `PASS` |
| 2 | same, plus `--notes=<temp copy whose deferral row is renamed>` | **1** | `Discrepancies: 1 (1 drift, 0 informational)`; `missing_foreign_key ... fk_vital_rm`; `FAIL` — the falsification, full output in section 5 |
| 3 | same, plus `--notes=<temp copy with the whole "## Deferred constraints" section removed>` | **2** | `ERROR verify-schema could not run: No deferred constraints were found in ... The registry must be a markdown table under "## Deferred constraints" whose first column is a backticked constraint name.` |
| 3b | same, plus `--notes=<temp copy whose deferred section is present but has no rows>` | **2** | the same error class: a present-but-empty section is not an allow-list |
| 3c | same, plus `--notes=<nonexistent path>` | **2** | `ERROR ... The extra-table registry is mandatory but was not found at ...` |
| 4 | `php artisan test tests/Unit` | **0** | **93 tests, 93 passed, 473 assertions** (baseline before this change: 74 tests). 19 new: 11 in `SchemaDifferDeferredConstraintTest`, 8 in `VerifySchemaDeferredConstraintTest` |
| 5 | `php artisan test --filter=SchemaDifferTest` | **0** | 30 tests, 30 passed |
| 6 | `php artisan test --filter=VerifySchemaCommandTest` | **0** | 13 tests, 13 passed |
| 7 | `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` | **1** | `No tests found.` — the zero-match tripwire still trips |
| 8 | `vendor/bin/pint` (**bare, no path argument**) | **0** | `{"tool":"pint","result":"passed"}` |
| 9 | `Test-Path bootstrap/cache/config.php` gives `False`; `php artisan config:clear`; item 1 re-run | **0** | no `config.php` before or after; item 1 re-verified green after the clear |
| 10 | `Get-FileHash -Algorithm SHA256 telemedicine_test.sql` | n/a | `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` — unchanged |
| 11 | `php artisan sehatly:verify-schema` (full scope, no `--tables`) | **1** | `Discrepancies: 58 (50 drift, 8 informational)`; `FAIL - 50 discrepancies` — section 7 |

The differ-level file was additionally run three times in a row while debugging:
11/11, 11/11, 11/11, with an identical assertion count each time.

### The A.7 traps, respected in every assertion

- **The PASS banner lies about scope.** In `--tables=` mode it is formatted from the
  **full** reference model, so item 1 legitimately prints
  `PASS - 75 tables, 2 views verified` after checking **eight** tables. The verdict
  was taken from the `Discrepancies:` line and the exit code, never the banner.
- **A typo'd table name exits 0.** The echoed `scope` line was read for all eight
  names. Verbatim: `scope  pasien, pasien_anggota_keluarga, pasien_alergi,
  pasien_riwayat_penyakit, pasien_imunisasi, pasien_tanda_vital,
  master_penjamin, pasien_penjamin` — exactly the eight requested names, and
  `unknown_requested_table` appears for none of them. (The correction pass below
  replaces the corruption this summary originally contained; the command output
  itself always read `pasien_*`.)

## 5. Rule 3: the falsification output

The probe is a copy of `docs/schema-notes.md` **outside the repository**, in
`%TEMP%\opencode`, differing in exactly one respect: the deferral row's first cell
names a different constraint. The registry therefore stays present and parseable, so
the only variable is *which name it names*, and the drift's return is attributable
to the registry and to nothing else.

**Before — the real registry (item 1, exit 0):**

```
 notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred constraint)
 scope  pasien, pasien_anggota_keluarga, pasien_alergi, pasien_riwayat_penyakit,
        pasien_imunisasi, pasien_tanda_vital, master_penjamin, pasien_penjamin

 Discrepancies: 1 (0 drift, 1 informational)
 deferred_foreign_key pasien_tanda_vital expected: fk_vital_rm FOREIGN KEY
   (rekam_medis_id) -> rekam_medis (id) ON DELETE SET NULL ON UPDATE RESTRICT
   | actual: absent by design - Column `rekam_medis_id` is present; the FK is added by
     migration 76 per SQL section `[14]` (`:1161-1163`) because `rekam_medis` does not
     exist until batch G (todo 13). Full reasoning in *Batch-C deferred and
     deliberately unconstrained columns* below.

 PASS - 75 tables, 2 views verified. Nothing was written.
```

**After — the same command with the deferral renamed (item 2, exit 1):**

```
 notes registry C:\Users\axioo\AppData\Local\Temp\opencode\deferrals-probe.md
   (7 registered extra tables, 1 deferred constraint)
 scope  pasien, pasien_anggota_keluarga, pasien_alergi, pasien_riwayat_penyakit,
        pasien_imunisasi, pasien_tanda_vital, master_penjamin, pasien_penjamin

 Discrepancies: 1 (1 drift, 0 informational)
 missing_foreign_key pasien_tanda_vital expected: fk_vital_rm FOREIGN KEY
   (rekam_medis_id) -> rekam_medis (id) ON DELETE SET NULL ON UPDATE RESTRICT
   | actual: -

 FAIL - 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.
```

(The `pasien_` spelling above is this file's own transcription artefact; the live
command output read `pasien` throughout. Nothing else differs between the two runs.)

The single row moves from `deferred_foreign_key` (yellow, informational) to
`missing_foreign_key` (red, drift), and the exit code moves from 0 to 1. **The
registry is what was excusing it, and the default is still the safe direction.**

A *different* missing constraint on the *same table* staying drift while
`fk_vital_rm` is excused is pinned by
`the exemption is scoped to one NAMED constraint: a different missing key on the same
table is still drift`: two named constraints (`fk_vital_rm` and `fk_vital_dokter`) on
one table, one registered and one not, in one report, informational and drift
respectively. The same test then re-runs it with the registry naming the *other*
constraint and asserts the exemption follows the **name**, not the table. So the
registry cannot be used to excuse a column, a table, or a whole batch.

## 6. Rule 2: the loophole closer, and how it was proven

**Route taken: a unit test whose mutation lives in a DDL fixture, not in the
database.**

The `--sql=` route with a crafted reference was considered and rejected: rule 2 is
about the constraint being present in the **live** schema while the **real** reference
still demands it, and no crafted reference file can change what `information_schema`
says about `telemedisin_db`. The state rule 2 describes is not reachable in this
database without running migration 76, which is forbidden here and premature anyway
(`rekam_medis` does not exist yet). So the mutation lives in the **live-side DDL
string** the test feeds `SqlSchemaParser` and `SchemaDiffer`, which is exactly where
it belongs. `SchemaDiffer` is directly constructible from a test (`new SchemaDiffer`
over two `SchemaSpec`s, as the existing `SchemaDifferFkImpliedIndexTest` does), so no
`--sql=` scaffolding was needed at all.

- `rule 2: a registered constraint that the live schema HAS is drift - the loophole closer`
  feeds the real `CREATE TABLE` from `telemedicine_test.sql:312-330` **plus** the
  section-`[14]` `ALTER` as the **expected** side, so the `ALTER`-folding path is
  exercised rather than bypassed, and as the **live** side the verbatim MySQL
  `SHOW CREATE TABLE` of `telemedisin_db.pasien_tanda_vital` **with**
  `CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis (id)
  ON DELETE SET NULL` plus the `KEY rekam_medis_id (rekam_medis_id)` that InnoDB
  builds to back it. With the registry still listing `fk_vital_rm`, the result is
  **exactly one** discrepancy, `fulfilled_deferred_foreign_key`, with
  **`drift = true`**, naming the constraint. No `extra_index` appears (the matched
  key still implies its support index) and no `missing_foreign_key` either.
- `rule 2 is not satisfiable by dropping the registry: an unregistered present key is
  clean` proves the pair. The *same* live schema yields one drift row with the stale
  registry and **zero** rows without it. The schema did not change, only the registry,
  so the registry row is what is wrong, which is precisely what rule 2 says.
- `the JSON report counts the registry and marks the deferral informational` and
  `rule 1: the batch-C scope exits 0...` both carry an explicit todo-18 branch: when
  `fulfilled_deferred_foreign_key` appears in the report they assert exit 1 and
  `Discrepancies: 1 (1 drift, 0 informational)` instead. The suite therefore keeps
  testing both sides of todo 18 with no test edited on the day.

`deferralDiff()` keys its live `SchemaSpec` by the **parsed** table name rather than a
hard-coded one. That is not cosmetic: a mismatched key turns a clean diff into a
`missing_table` plus an `undocumented_extra_table` for entirely the wrong reason, and
it is what made the first draft of the engine-named test fail for a reason that had
nothing to do with the registry.

## 7. Full scope, before and after: the global signal is unchanged

| | before | after |
|---|---|---|
| exit code | 1 | **1** |
| `discrepancy_count` | 58 | 58 |
| `drift_count` | **51** | **50** |
| informational | 7 | 8 |
| `missing_table` (drift) | **48** | **48** |
| `missing_view` (drift) | **2** | **2** |
| `missing_foreign_key` (drift) | **1** (`fk_vital_rm`) | **0** |
| `deferred_foreign_key` (informational) | 0 | **1** (`fk_vital_rm`) |
| `documented_extra_table` (informational) | 7 | 7 |

Exactly one row changed class, and it is the one row this task is about. All 48
genuinely-missing tables and both missing views are still drift and the run is still
red, so the registry did **not** weaken the global signal: it removed precisely the
one false positive and left the other 50 alone. This is asserted, not merely observed,
in `the full run still fails, and the deferral is the only thing that left the drift
set`: `drift_count > 0`, `missing_table > 0`, no `missing_foreign_key` at all, and
`discrepancy_count - drift_count` equal to the **sum of both registries**, so an
informational row can never appear unaccounted for and a stale row cannot hide as one.

## 8. One pre-existing test line had to change: reported, not hidden

`php artisan test tests/Unit` went red on exactly one assertion after this change,
and it is a **real consequence of the feature**, not a stale pin:

```
VerifySchemaCommandTest.php:200
  Failed asserting that 8 is identical to 7.
  (expect($json['discrepancy_count'] - $json['drift_count'])->toBe($registeredExtras);)
```

That assertion states the informational rows equal the **extra-table registry** size. A
registered deferral is informational too, so the correct total is extras **plus**
deferrals. Per A.9 the expectation was **not** relaxed to make anything pass, and no
number was pinned either; the fix keeps it derived:

```php
$registeredDeferrals = count(DeferredConstraintRegistry::fromMarkdown(base_path('docs/schema-notes.md')));
expect($json['notes_registry']['registered_deferred_constraints'])->toBe($registeredDeferrals);
expect($json['discrepancy_count'] - $json['drift_count'])->toBe($registeredExtras + $registeredDeferrals);
```

This **strengthens** the check. At todo 18, once migration 76 lands `fk_vital_rm`, rule
2 reclassifies the row as **drift**; if the registry row were still listed then,
`registered_deferred_constraints` (1) would disagree with the informational total (0)
and this assertion would fail, naming the stale registry. The registry forces itself
to be updated with no test edited. The rest of that 554-line file is byte-unchanged,
including the derived missing-table count, the named-offender list, both
`missing_view` regexes and the narrow-scope anchor.

Every other new test lives in a **new file**, so no expectation authored by a previous
executor was touched.

## 9. Adversarial classes

- **misleading_success_output: APPLIES, falsified.** The whole risk of this feature is
  that it silences real drift. Probed three ways. (a) The deferral renamed in a temp
  registry brings `missing_foreign_key` drift and exit 1 straight back (section 5).
  (b) A second, unregistered named constraint on the *same table* stays drift while
  `fk_vital_rm` is informational, and the exemption follows the name rather than the
  table. (c) A present-but-empty section, a removed section and a missing file all
  exit **2** with the heading named in the message, never a silent pass. In addition
  the full-scope run is still exit 1 with 50 drift, and the informational total is
  pinned to the sum of both registries so no informational row can appear
  unaccounted for.
- **dirty_worktree: APPLIES, respected.** `git status --porcelain` already showed
  ` M .omo/plans/sehatly-telemedicine-platform.md` (the orchestrator's),
  `?? .omo/evidence/task-3-sehatly.md` and `?? .omo/start-work/` (a prior executor's)
  before this work began. All three were left untouched, and the commit lists only the
  eight paths in section 11. A read-only verifier was running concurrently on todo 9;
  nothing outside this scope was changed, and no file under
  `database/migrations/`, `app/Models/`, `app/Http/`, `bootstrap/`, `config/`,
  `routes/` or `web/` was opened for writing.
- **stale_state: APPLIES, cleared.** `bootstrap/cache/config.php` did not exist before
  this change and does not exist after; `php artisan config:clear` exited 0 and item 1
  was re-verified green afterwards. Bare pint reported `passed` and did not touch
  `bootstrap/cache/packages.php` or `services.php`, which is A.7's trap and only
  fires when a path argument is passed.
- **hung_or_long_commands: APPLIES.** Every command ran with an explicit timeout
  (120 s to 900 s) and completed; only **observed** exit codes are reported. The
  pre-existing `mysqld` (17484, 19796) and the user's `php artisan serve` (22288)
  were confirmed still running afterwards and were never signalled.
  `php artisan db:show --json` was run once; it is read-only, enumerates
  `information_schema` across all schemas and changed nothing.
- **repeated_interruptions: APPLIES, idempotent.** This is a pure code and
  documentation change: no migration, no DDL, no DML. Re-running the verifier
  repeatedly is already covered by the pre-existing `running the verifier changes
  nothing in the database, and is idempotent` test, which still passes.
  `telemedisin_db` holds 34 tables and `telemedisin_db_test` holds 34 tables after
  every run, with `pasien`, `pasien_tanda_vital` and `pasien_penjamin` still at 0
  domain rows, and `information_schema` confirming **no** foreign key on any
  `rekam_medis_id`. The live schema is exactly what it was.
- **malformed_input: RULED OUT.** No new parser was written. The registry consumes the
  differ's own `ForeignKeySpec` objects and a markdown table, and `SchemaNotesSection`
  only slices lines. Unusable registry input **raises**, and the command turns that
  into exit 2, which is A.7's "a run that cannot understand its inputs must never look
  green" shape. Measured, not assumed.
- **prompt_injection: RULED OUT.** Nothing untrusted is read. The notes file is a
  tracked repository document, the SQL is read-only law, and every input the verifier
  parses was already being parsed by todo 6's code.
- **cancel_resume: RULED OUT.** No resumable user flow, no background job, no queue.
  The command is a single synchronous read-only pass.
- **flaky_tests: RULED OUT.** Deterministic: no clock, no randomness, no ordering
  dependence, no network. The new differ-level file ran three times in a row with
  identical results. One genuine flake **was** observed and fixed during development: a
  temp-fixture read on Windows returned a partially written file once, so the
  fixture-writing tests now assert the write succeeded
  (`expect($written)->toBeGreaterThan(0)`) before asserting on the parse, and the
  falsification probe asserts that its own rewrite changed the file, so a silent no-op
  can never make a falsification pass for the wrong reason.

## 10. Cleanup receipts

- Temp registries and probes, all outside the repository in
  `C:\Users\axioo\AppData\Local\Temp\opencode\`, all removed with
  `Remove-Item -Force`: `deferrals-probe.md`, `deferrals-absent.md`,
  `schema-notes-no-deferrals.md`, the five throwaway probe scripts
  (`probe-registry.php`, `probe-showcreate.php`, `probe-blank.php`,
  `probe-variants.php`, `probe-dbs.php`) and the three captured report files
  (`baseline-full.json`, `after-full.json`, `after-full.txt`). Receipt:
  `Get-ChildItem` over that directory lists none of them; the remaining entries are
  pre-existing files from other sessions.
- No stray `sehatly-*` fixture in `%TEMP%`: every test cleans up in a `finally`.
  Receipt: `Get-ChildItem $env:TEMP -Filter 'sehatly-*'` returns nothing.
- No leftover process: `Get-Process php` lists only PID 22288, the user's pre-existing
  `artisan serve`. `mysqld` 17484 and 19796 are untouched.
- `telemedicine_test.sql` is **byte-unchanged**:
  `Get-FileHash -Algorithm SHA256` gives
  `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, matching the
  law. It was never opened for writing.
- Database state re-measured after everything: `telemedisin_db` = 34 tables,
  `telemedisin_db_test` = 34 tables, `sehatly` = 10 tables, all as found. No unrelated
  database was touched; the only statements issued were `SELECT`s against
  `information_schema` and `SHOW CREATE TABLE`.
- No `migrate`, `migrate:fresh`, `migrate:rollback`, `db:seed` or `db:wipe` was run
  against any database. No `install:api` in any form. No `mobile/` directory and no
  `pubspec.yaml` created.

## 11. Exact paths committed

```
app/Console/Commands/VerifySchemaParity.php                    (M)  loads the second registry, passes it to the differ, reports its size
app/Support/Schema/DeferredConstraintRegistry.php              (A)  the registry, keyed on constraint name
app/Support/Schema/SchemaNotesSection.php                      (A)  section scoping, so the two registries cannot read each other
app/Support/Schema/ExtraTableRegistry.php                      (M)  reads only its own heading
app/Support/Schema/SchemaDiffer.php                            (M)  rules 1, 2 and 3 in diffForeignKeys() and deferral()
docs/schema-notes.md                                           (M)  new Deferred constraints section; todo 9's prose folded in and corrected
tests/Unit/Schema/SchemaDifferDeferredConstraintTest.php       (A)  11 tests: rules 1/2/3, both key choices, both registries' parsing
tests/Unit/Console/VerifySchemaDeferredConstraintTest.php      (A)  8 tests: rule 1 end to end, the falsification, exit 2, the todo-18 branch
tests/Unit/Console/VerifySchemaCommandTest.php                 (M)  ONE derived assertion, section 8: a real finding, reported
.omo/evidence/task-6-deferred-constraints.md                   (A)  this file
```

Commit message: `feat(dev): add a deferred-constraint registry to the schema parity
verifier`

Left alone on purpose: `.omo/plans/sehatly-telemedicine-platform.md` (modified, the
orchestrator's), `.omo/evidence/task-3-sehatly.md` and `.omo/start-work/` (untracked, a
prior executor's).

## 12. What todo 18 has to do

1. Write `2026_10_01_000076` adding `CONSTRAINT fk_vital_rm`. The moment it is
   migrated, the still-present registry row becomes `fulfilled_deferred_foreign_key`
   **drift**, and both `VerifySchemaDeferredConstraintTest` and the
   `VerifySchemaCommandTest` decomposition fail until the registry is updated.
2. **Delete the `fk_vital_rm` row from the `## Deferred constraints` table in the same
   commit.** Do not add a foreign key for `pasien_penjamin.faskes_rujukan_id`: the
   contract declares none, so adding one would be `extra_foreign_key` drift.
3. If that was the last deferral, delete the **whole section** *and* the
   `DeferredConstraintRegistry::fromMarkdown()` call together, because an empty section
   is exit 2 by design.
