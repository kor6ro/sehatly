# Task 10 follow-up 1 — six comment-accuracy defects in batch D (todo 10 FU1)

Branch `feat/sehatly-telemedicine`. Base commit `35cdeb5` (verifier `needs-fix`, confidence 0.9).
**Comments and markdown only — zero executable bytes changed.** Proven below by token-level
comparison, not by a green suite.

---

## 1. The six defects, before → after (verbatim)

### D1 — BLOCKER — `database/migrations/2026_10_01_000033_dokter_faskes_table.php:33`

The comment claimed both flags default to `0`, inverting live behaviour: the SQL at `:450-451`
is `is_utama TINYINT(1) NOT NULL DEFAULT 0` and `status_aktif TINYINT(1) NOT NULL DEFAULT 1`,
and the migration code is `$table->boolean('is_utama')->default(false);` (L59) /
`$table->boolean('status_aktif')->default(true);` (L60). The default row is **active**, not
inactive.

BEFORE (L33-36):
```php
 * Both `is_utama` and `status_aktif` default to `0`, so an inserted affiliation
 * is inactive and not primary. There is no unique beyond the PK, so the same
 * doctor-facility pair cannot be duplicated, but a doctor may have several
 * active facilities and only "one primary" is an application-level invariant.
```

AFTER (L33-38):
```php
 * `is_utama` defaults to `0` and `status_aktif` defaults to `1` (`:450-451`), so an
 * inserted affiliation is **active but not primary**: it is inactive only if the
 * caller says so, and the "primary" flag is always the caller's to set. There is
 * no unique beyond the PK, so the same doctor-facility pair cannot be duplicated,
 * but a doctor may have several active facilities and only "one primary" is an
 * application-level invariant.
```

The "no unique beyond the PK" point and the application-level-invariant sentence are preserved
verbatim. The paragraph above it (`:25-31`, the `ON DELETE CASCADE` / "nothing here is
deferred" paragraph) is untouched and still consistent.

### D2 — `database/migrations/2026_10_01_000031_dokter_table.php:11`

`dokter` has 23 columns, but is **third**-widest: `pasien` 31, `rekam_medis` 28, `dokter` 23.

BEFORE (L11-15):
```
 * The second-widest table in the contract (23 columns) and the hub every later
 * clinical table hangs off (`dokter_spesialisasi`, `dokter_faskes`,
 * `dokter_pendidikan` in this batch; `dokter_jadwal`, `dokter_libur`,
 * `konsultasi`, `resep`, `ulasan_dokter` in later ones). Four things here are
 * not expressible by reaching for the obvious helper:
```

AFTER (L11-16):
```
 * The third-widest table in the contract (23 columns, behind `pasien` at 31 and
 * `rekam_medis` at 28) and the hub every later clinical table hangs off
 * (`dokter_spesialisasi`, `dokter_faskes`, `dokter_pendidikan` in this batch;
 * `dokter_jadwal`, `dokter_libur`, `konsultasi`, `resep`, `ulasan_dokter` in
 * later ones). Four things here are not expressible by reaching for the
 * obvious helper:
```

`(23 columns)` kept — it was correct. The hub list is unchanged.

### D3 — `database/migrations/2026_10_01_000032_dokter_spesialisasi_table.php:43`

Line 10 of the same file already states it correctly ("A many-to-many bridge from `dokter` (31)
to `master_spesialisasi` (30)"), then L43-44 transposed the ordinals — the file contradicted
itself. `master_spesialisasi` is migration **30**, `dokter` is **31**.

BEFORE (L43-45):
```php
 * `$timestamps = false`. Both foreign keys resolve inside this batch (`dokter`
 * and `master_spesialisasi` are 30 and 31), so **nothing here is deferred** and
 * the *Deferred constraints* registry in `docs/schema-notes.md` gains no row.
```

AFTER (L43-46):
```php
 * `$timestamps = false`. Both foreign keys resolve inside this batch — `dokter`
 * (31) and `master_spesialisasi` (30) are both created earlier inside batch D, as
 * stated at the top of this docblock — so **nothing here is deferred** and the
 * *Deferred constraints* registry in `docs/schema-notes.md` gains no row.
```

The redundant restatement is replaced by a back-reference to L10, so the two statements can no
longer drift apart. "Nothing here is deferred" survives intact.

### D4 — `database/migrations/2026_10_01_000034_dokter_pendidikan_table.php:25`

The sentence claimed this FK is "one of the single-column FKs that the composite-PK table
beside it (`dokter_faskes`, 33) does not have". **False** — `dokter_faskes` has two
single-column FKs (`dokter_id` `:453`, `faskes_id` `:454`, both `ON DELETE CASCADE`). Its real
point, already in the same sentence, is the contrast with `dokter_spesialisasi` (32): there is
no unique key here, so duplicate education rows are legal.

BEFORE (L25-30):
```php
 * `dokter_id BIGINT UNSIGNED NOT NULL` with `ON DELETE CASCADE` (`:463`) — one of
 * the single-column FKs that the composite-PK table beside it (`dokter_faskes`,
 * 33) does not have, and unlike `dokter_spesialisasi` (32) there is **no** unique
 * constraint here at all. A doctor may legitimately have several `sp1` rows from
 * different institutions or re-taken exams, so the duplicates are data, not
 * drift, and no application-level de-duplication rule is warranted.
```

AFTER (L25-29):
```php
 * `dokter_id BIGINT UNSIGNED NOT NULL` with `ON DELETE CASCADE` (`:463`), and
 * unlike `dokter_spesialisasi` (32) there is **no** unique constraint here at
 * all. A doctor may legitimately have several `sp1` rows from different
 * institutions or re-taken exams, so the duplicates are data, not drift, and no
 * application-level de-duplication rule is warranted.
```

The false `dokter_faskes` claim is dropped and **no replacement claim was invented**. The
`:463` citation, the `ON DELETE CASCADE` fact, the `dokter_spesialisasi` (32) contrast and the
whole "duplicates are data, not drift" argument are preserved.

### D5 — `database/migrations/2026_10_01_000030_master_spesialisasi_table.php:20`

`:1234-1240` is the tail of the *previous* seed (`master_hubungan_keluarga`, `:1231-1233`).
The `master_spesialisasi` INSERT is at `:1236-1252` and its 16 value tuples at `:1237-1252`.
Verified by reading `telemedicine_test.sql:1230-1252` (16 tuples counted: `UMUM`, `SP.PD`,
`SP.A`, `SP.OG`, `SP.M`, `SP.THT`, `SP.KJ`, `SP.B`, `SP.BP`, `SP.JP`, `SP.P`, `SP.KK`, `SP.S`,
`SP.N`, `SP.U`, `GIGI`). The count 16 was correct; only the citation was wrong.

BEFORE (L19-20):
```php
 * 19's `MasterSpesialisasi` needs `$timestamps = false`. Todo 18's
 * `SpesialisasiSeeder` ports the 16 `:1234-1240` rows.
```

AFTER (L19-21):
```php
 * 19's `MasterSpesialisasi` needs `$timestamps = false`. Todo 18's
 * `SpesialisasiSeeder` ports the 16 value tuples at `:1237-1252` of the
 * `master_spesialisasi` INSERT statement at `:1236-1252`.
```

### D6 — `docs/migration-order.md:221` (rule 9) — stale, load-bearing

`pasien_penjamin.faskes_rujukan_id` (`:346`) declares **no** `FOREIGN KEY` in the SQL. The
"deferred to migration 76" claim is the stale one already corrected in `docs/schema-notes.md`
(L362-370, L403-408) and in the `2026_10_01_000027` docblock (L21-30) by commit `36cfa77`. It
survived in rule 9 and was the more dangerous of the two copies: it instructs migration 76 to
create a constraint the contract never declares.

BEFORE (L221-223):
```markdown
9. **Never add a `FOREIGN KEY` to a column the SQL leaves bare.** Eight columns look like
   references and have none (see the plan's list), and `pasien_penjamin.faskes_rujukan_id`
   (`:346`) is deferred to migration 76.
```

AFTER (L221-231):
```markdown
9. **Never add a `FOREIGN KEY` to a column the SQL leaves bare.** Eight columns look like
   references and have none (see the plan's list). `pasien_penjamin.faskes_rujukan_id`
   (`:346`) is the sharpest case: the DDL declares no `FOREIGN KEY` for it, so the column
   is bare **by contract** — plan appendix A.10 / A.11 settled that, and the old ordering
   argument is dead now that `faskes` exists (batch D, migration 28). Nothing about it is
   deferred and no constraint is owed, so migration `2026_10_01_000076` must **not** add
   one; adding it would be `extra_foreign_key` drift. The only column in the contract with
   a genuinely deferred FK is `pasien_tanda_vital.rekam_medis_id` (`:315`), which the SQL's
   own section `[14]` (`:1161-1163`) really does add — that one is the single row of the
   *Deferred constraints* registry in `docs/schema-notes.md`, and it is not a licence to
   constrain anything else.
```

The rule's actual point — never add a FK to a column the SQL leaves bare — is untouched in its
bolded lead. Voice matches rules 1-10 (bolded lead, backticked identifiers, `:` line cites).
The closing sentence names the one FK that genuinely is deferred, so the corrected rule cannot
be misread as "nothing is ever deferred"; that fact is grounded in `docs/schema-notes.md:351-361`,
the contract row 76's own `ALTER` at `:1161-1163`, and the verifier's own output
(`notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred constraint)`).

---

## 2. Step 3 — the comments-only proof (the load-bearing evidence)

A green suite proves nothing here: comments are not executed. The proof is a **token-level**
comparison, because a line-based filter is fragile (a docblock opener `/**` and a `//` comment
can sit on lines that also carry code, and a naive "starts with `*`" filter silently drops or
keeps the wrong things).

**Method.** For each of the five migration files:

1. Read the HEAD blob as **raw bytes** directly out of `git show HEAD:<path>` via `proc_open`
   (no PowerShell text pipeline in between — an earlier attempt piped through
   `Out-File -Encoding UTF8`, which re-encoded the em-dashes and produced 5 false mismatches;
   that was a measurement artefact, not a code change, and is recorded here rather than hidden).
2. Read the working copy from disk as raw bytes.
3. `token_get_all()` both, drop every `T_COMMENT` and `T_DOC_COMMENT` token, and drop
   `T_INLINE_HTML` tokens that are whitespace-only (BOM remnant / trailing newline).
4. Join the surviving tokens with `\n` and SHA-256 the result. Equal hash + equal token count
   ⇒ executable content is byte-identical. Also print the whole-file hash of each side, so a
   real edit is proven to have occurred (the hashes *do* differ — that is the comment change).

Script: `%TEMP%\opencode\sehatly-fu1-proof.php` (scratch, outside the repo, deleted after).

**Per-file result — 5 files, 0 mismatches, exit 0:**

| File | non-comment tokens HEAD / work | non-comment bytes | non-comment SHA-256 | whole-file SHA-256 differs? | Verdict |
| --- | --- | --- | --- | --- | --- |
| `..._000030_master_spesialisasi_table.php` | 161 / 161 | 876 / 876 | `c66a9162ad1466a6f85f2400ce9af76c19bd9a0e7376f4d148126e73d64fd6aa` | yes | IDENTICAL |
| `..._000031_dokter_table.php` | 502 / 502 | 3031 / 3031 | `028ec6e34388a9339ffe6ffebc7999aefd95e771f30c8bb54e6582110773faa3` | yes | IDENTICAL |
| `..._000032_dokter_spesialisasi_table.php` | 196 / 196 | 1136 / 1136 | `34343df9cdeb0f3e4ecd507799d003cd2d1b13002525b782b02e768e4db02aab` | yes | IDENTICAL |
| `..._000033_dokter_faskes_table.php` | 194 / 194 | 1072 / 1072 | `4a198f6bf0ddbc183617a56733b8335ba5312a19ed37cf7c69ad075f8d731841` | yes | IDENTICAL |
| `..._000034_dokter_pendidikan_table.php` | 195 / 195 | 1059 / 1059 | `96d34b079ab1e42b1b243a64352e852742a2910a5ba6c9aa8bbd2a3b09651cae` | yes | IDENTICAL |

`MISMATCHES=0`, `FILES_COMPARED=5`, exit code 0.

**Negative control (the proof is not vacuous).** Same code path, mutated in memory, nothing on
disk touched — `%TEMP%\opencode\sehatly-fu1-control.php`:

| Control | Expected | Result |
| --- | --- | --- |
| A: edit a docblock sentence only | undetected (hashes equal) | `undetected (hashes equal) - CORRECT` |
| B: flip `status_aktif` default `true`→`false` in real code | detected | `detected (hashes differ) - CORRECT` |
| C: delete the `$table->boolean('is_utama')…` line | detected | `detected (hashes differ) - CORRECT` (tokens 194 → 181) |

So the comparison ignores comment edits and still catches a flipped default and a dropped column.

**Step 1 — `git diff --numstat` (per file, added/deleted):**

```
2       1       database/migrations/2026_10_01_000030_master_spesialisasi_table.php
6       5       database/migrations/2026_10_01_000031_dokter_table.php
4       3       database/migrations/2026_10_01_000032_dokter_spesialisasi_table.php
6       4       database/migrations/2026_10_01_000033_dokter_faskes_table.php
5       6       database/migrations/2026_10_01_000034_dokter_pendidikan_table.php
10      2       docs/migration-order.md
```

A handful of lines per file, additions ≈ deletions (the only asymmetry is reflow: D3/D4/D6
replace a 3-line claim with a 4-5 line one). No file shows suspicious churn.

**Step 2 — every hunk read.** All 6 hunks inspected line by line. Every changed line in the five
migration files begins with ` * ` — i.e. it is inside a `/** … */` docblock. Every changed line
in `docs/migration-order.md` is markdown prose inside a numbered rule. **No** `Schema::create`
call, column, type, default, index, foreign key, `$timestamps`, enum value, class name, `use`
statement or executable expression appears on any `+` or `-` line.

---

## 3. Verify commands and exit codes

| # | Command | Exit | Result |
| --- | --- | --- | --- |
| 1 | `git diff --numstat` | 0 | 6 files, 2-10 changed lines each (above) |
| 2 | `git diff` + read every hunk | 0 | all docblock / prose only |
| 3 | `php %TEMP%\opencode\sehatly-fu1-proof.php <repo>` | **0** | `MISMATCHES=0`, 5/5 IDENTICAL |
| 3b | `php %TEMP%\opencode\sehatly-fu1-control.php` | 0 | negative controls A/B/C all CORRECT |
| 4 | `php -l` × 6 files | **0 each** | `No syntax errors detected` ×5 migrations; markdown file trivially 0 |
| 5 | `php artisan config:clear` (before) | 0 | `Configuration cache cleared successfully`; `bootstrap/cache/config.php` absent before and after |
| 5 | `php artisan sehatly:verify-schema --tables=faskes,faskes_layanan,master_spesialisasi,dokter,dokter_spesialisasi,dokter_faskes,dokter_pendidikan` | **0** | `Discrepancies: 0 (0 drift, 0 informational)`, `PASS — 75 tables, 2 views verified. Nothing was written.` |
| 6 | `php artisan config:clear` (after) | 0 | cache still absent |
| 7 | `php artisan test tests/Unit` | **0** | `{"tool":"pest","result":"passed","tests":93,"passed":93,"assertions":473,"duration_ms":5192}` |
| 8 | `Select-String -Path docs\migration-order.md -Pattern "deferred to migration 76"` | 0 | **0 matches** |
| 9 | `Get-FileHash -Algorithm SHA256 telemedicine_test.sql` | 0 | `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` — matches the read-only-law hash |
| 10 | `vendor/bin/pint` (bare, no path argument) | 0 | `{"tool":"pint","result":"passed"}` — zero files reformatted; numstat unchanged after pint |
| 11 | `php %TEMP%\opencode\sehatly-fu1-proof.php <repo>` (re-run after pint) | **0** | `MISMATCHES=0` again |

### Step 5 — scope line, read explicitly

The command exits 0 with `unknown_requested_table` on a typo'd table name, so a green exit is
not sufficient. The echoed `scope` line was read directly:

```
 scope faskes, faskes_layanan, master_spesialisasi, dokter, dokter_spesialisasi, dokter_faskes, dokter_pendidikan
```

All **7** real names are present, none is unknown. Supporting lines from the same run:

```
 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred constraint)

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 Live schema
 counts tables=41 views=0 columns=283 indexes=102 foreign_keys=33 checks=0

 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.

 PASS — 75 tables, 2 views verified. Nothing was written.
```

### Step 7 — unit suite summary

```
[derive] migrations=37 Schema::create calls=40 extracted=40 | CREATE VIEW calls=0 extracted=0
         | contract tables=75 views=2 | derived-missing tables=41 views=2 | registry=7
{"tool":"pest","result":"passed","tests":93,"passed":93,"assertions":473,"duration_ms":5192}
```

93 tests / 93 passed / 473 assertions — the fixed baseline, unchanged.
`tests/Unit/Console/VerifySchemaCommandTest.php` was **not** touched (it is not in the commit;
`git show --name-only` lists exactly 7 paths and it is not among them).

### Step 9 — data safety (read-only PDO census over `.env`, `%TEMP%\opencode\sehatly-fu1-census.php`)

```
telemedisin_db       base_tables=41   migrations_rows=37
telemedisin_db_test  base_tables=41   migrations_rows=37
sehatly              base_tables=10   migrations_rows=5
```

`telemedisin_db` 41 tables, `telemedisin_db_test` 41 tables, `sehatly` 10 tables with its
original 5 migration rows. No `migrate`, `migrate:fresh`, `migrate:rollback`, `db:seed` or
`db:wipe` was run at any point. `php artisan db:table` was not run.

---

## 4. Adversarial classes

| Class | Outcome |
| --- | --- |
| `misleading_success_output` | **Probed, and it is the reason step 3 exists.** A green verifier, a green 93/93 suite and a clean `php -l` are all satisfied by completely wrong comments — which is exactly how `35cdeb5` passed. So success is claimed on the token-level non-comment comparison (5/5 identical) *plus* its negative control (B and C detected). Steps 5-7 are reported as supporting evidence only, never as the basis of the claim. |
| `stale_state` | **Cleared.** `bootstrap/cache/config.php` does not exist (`Test-Path` → `False`) before the first artisan run and after the last. `php artisan config:clear` run before **and** after, both exit 0. The verifier reads live `information_schema` and reports `Discrepancies: 0`, so a stale config could not have hidden drift. |
| `dirty_worktree` | **Asserted.** Pre-existing, orchestrator-owned, left untouched: `M .omo/plans/sehatly-telemedicine-platform.md`, `?? .omo/evidence/task-3-sehatly.md`, `?? .omo/start-work/`. Staged with 7 explicit pathspecs — never `-A`, `.`, `-a`, `-u`; no `stash`, `checkout .`, `restore .`, `clean` or `reset`. Post-commit `git show --name-only --format="" HEAD` lists exactly the 7 intended paths. Note: the plan file's own numstat moved from 541/9 to 600/9 **during** this session — that is concurrent orchestrator writing, not an edit of mine; it is excluded from my commit and appears in no diff I authored. |
| `hung_or_long_commands` | All long commands ran with explicit timeouts (verify 240 s, unit suite 300 s, census 120 s) and I report only observed exit codes. The pre-existing `mysqld` and the user's `php artisan serve` (PID 22288) were never touched; no process was started or killed by me, and none is left running. `php artisan tinker --execute` exited 1 and was abandoned for a direct read-only PDO census rather than retried indefinitely. |
| `repeated_interruptions` | The fix is idempotent. Each edit replaced an exact multi-line string with a corrected one; re-applying any of them finds no `oldString` and is a no-op rather than doubled text. Pint was re-run after the edits and changed nothing. The negative control mutates only in-memory strings, and the first (discarded) baseline attempt wrote only into `%TEMP%`, so a repeat run cannot corrupt the repo. |
| `malformed_input` | N/A — no parser was authored or invoked against untrusted input. The DDL was read as a specification for line citations, not executed. |
| `prompt_injection` | N/A — `telemedicine_test.sql` is first-party DDL, read only to confirm line numbers `:450-451`, `:453-454`, `:1236-1252`, `:1237-1252`. Nothing in it reads like an instruction; had anything done so it would have been reported here, not acted on. It is unmodified (SHA-256 re-verified). |
| `cancel_resume` | N/A — no resumable user flow; the six edits are independent and each completed. |
| `flaky_tests` | N/A — deterministic. 93/93/473 exactly matches the fixed baseline on a single run; a different count would have been reported as a finding, not re-run. |

---

## 5. Residual defect found but NOT fixed (outside the six, needs an orchestrator decision)

While fixing D6 I found the **same stale claim a second time, in the same file, in a place that
is arguably more dangerous** — the contract table row that tells the implementer of migration 76
what to build. It is phrased differently from D6, so the step-8 grep (`"deferred to migration
76"`) does not catch it.

`docs/migration-order.md:127`:

```
| 76 | 1161 | `fk_vital_rm` (deferred FK) | `2026_10_01_000076_add_deferred_foreign_keys_table.php` | 18 |
`ALTER TABLE pasien_tanda_vital ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)
REFERENCES rekam_medis(id) ON DELETE SET NULL`, plus the deferred
`pasien_penjamin.faskes_rujukan_id -> faskes(id)` FK recorded in todo 9. …
```

The clause "plus the deferred `pasien_penjamin.faskes_rujukan_id -> faskes(id)` FK recorded in
todo 9" is exactly the claim D6 removes, and it is false for the reason `docs/schema-notes.md`
and the `000027` docblock already record. It survived `36cfa77`.

**I did not edit it.** The task scoped six defects at named line numbers and said to fix *only*
those, so widening scope was the orchestrator's call to make, not mine. Proposed replacement for
that clause, should the orchestrator authorise it:

> `ALTER TABLE … fk_vital_rm … ON DELETE SET NULL`. `pasien_penjamin.faskes_rujukan_id` is
> **not** part of this migration — it is bare by contract (see rule 9). `down()` drops
> constraints before any table.

---

## 6. Scratch files and cleanup

All scratch lived in `%TEMP%\opencode\` (never in the repo) and was deleted after the run:
`sehatly-fu1-proof.php`, `sehatly-fu1-control.php`, `sehatly-fu1-census.php`,
`sehatly-fu1-strip.php`, and the discarded `sehatly-fu1-base\` directory. No process was left
running. `bootstrap/cache/config.php` does not exist.
