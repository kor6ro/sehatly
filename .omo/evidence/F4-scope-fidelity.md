# F4 — Scope-fidelity audit

**Gate:** F4, scope fidelity.
**Auditor:** independent. I did not build this work. No product file was created, modified or deleted by this audit. `.omo/plans/` was read only; no checkbox was marked. `phpunit.xml` was not touched. `migrate:fresh` was not run against `telemedicine_db` or `telemedisin_db_test`; `migrate:rollback` was not run. No test was skipped and no `markTestSkipped` was introduced. `git add -A` was never run; only this one evidence file is staged.
**PHP binary:** `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17). Bare `php` is not on `PATH`.
**Audited:** `HEAD = 44af342d62ecbcc8ca2c16efd111f1f248c9a28b` (`ledger: record the F2 BLOCKER fix (RbacSeeder idempotency)`, 2026-09-30 10:45:18 +0700).

---

## VERDICT: **OUT OF SCOPE**

Seven of the eight guardrails hold, several of them under adversarial conditions I constructed myself rather than accepting the project's own claim. One guardrail is violated, and it is a **Must-NOT item in the plan's own guardrail list** — not a style nit:

> **Plan line 58 — "NEVER encrypt a value into a column narrower than its ciphertext … Ciphertext goes in a `TEXT` column; a fixed-width 16-char HMAC carries the unique index. See todo 50."** is un-implemented, **and the delivered API writes a full unmasked 16-digit Indonesian national ID in plaintext** (`app/Http/Controllers/Api/V1/PasienController.php:213-217` → `pasien_anggota_keluarga.nik CHAR(16)`), in a schema whose own DDL comment reads *"WAJIB dienkripsi (application-level/TDE) sesuai UU PDP"*. The cipher that was built to prevent this is never invoked on a write path, and the column it needs does not exist.

Compounding it: **todo 50's checkbox was flipped to `[x]` over text that mandates a schema change nobody made** (`nik_cipher TEXT`, `nik_hash CHAR(16)`, `App\Casts\NikEncrypted`, `UNIQUE KEY uq_pasien_nik_hash`). There is no `app/Casts/` directory in the repository at all.

Mitigating, and stated plainly because it changes the *character* of the finding and not its existence: the breach is **disclosed, not concealed**. `.omo/start-work/ledger.jsonl:75` records the decision, `docs/verification-report.md` §7.2 says *"The acceptance criterion is literally unmet… **Open.**"*, and F1 classified it as a scope violation rather than a type error. The honesty is real. The violation is real. A project that is 54/54 and *says out loud* that one of its own Must-NOTs is unmet is not the same as a project that hides it — but a gate that certifies scope fidelity cannot pass while a live plaintext-PII write path exists, regardless of how well documented the gap is.

I did not rubber-stamp, and I did not fail the project for trivia. The reasons are named below with commands.

---

## 1. Merge base — how I determined it

```
> git rev-parse --abbrev-ref HEAD
HEAD
```

The work is on a **detached HEAD**. `main` exists, so the prescribed first command works:

```
> git merge-base HEAD main
2d3b3b458977c7f071e1882fd53d63f96486d97e
EXIT=0
```

Corroboration that this is the right base:

```
> git branch -a
* (HEAD detached from 42b001e)
  feat/sehatly-telemedicine
  main
  todo-39-43
  remotes/origin/main
```

```
> git log -1 --format="%H %ad %s" 2d3b3b458977c7f071e1882fd53d63f96486d97e
2d3b3b458977c7f071e1882fd53d63f96486d97e Fri Sep 25 23:09:54 2026 +0700
first commit
```

```
> git log -1 --format="%H %s" main
2d3b3b458977c7f071e1882fd53d63f96486d97e first commit
> git rev-list --count 2d3b3b458977c7f071e1882fd53d63f96486d97e..HEAD
180
```

**Base used: `2d3b3b4`** — it *is* `main`, it is the repository's first commit, and the branch is 180 commits ahead of it. `master` and `develop` do not exist, so no fallback was needed. `todo-39-43` and `feat/sehatly-telemedicine` also point into this history; the merge base with `main` is the ancestor that contains all of it.

## 2. The complete diff surface

```
> git diff --stat 2d3b3b458977c7f071e1882fd53d63f96486d97e..HEAD
...
 914 files changed, 263043 insertions(+), 14597 deletions(-)
```

By change type:

```
M = 35
A = 776
D = 99
R079 = 1     components.json            -> web/components.json
R084 = 1     resources/js/components/ui/icon.tsx -> web/src/components/ui/icon.tsx
R070 = 1     resources/js/components/ui/placeholder-pattern.tsx -> web/src/components/ui/placeholder-pattern.tsx
R064 = 1     resources/js/lib/utils.ts  -> web/src/lib/utils.ts
```

By top-level directory (914 paths, `mobile/` absent from the list — see G1):

```
app                    302
web                    151
database                95
tests                   93
.omo                    89
resources               71
packages                45
(repo root)             18
docs                    16
config                  16
routes                   6
tools                    5
.github                  2
bootstrap                2
storage                  2
public                   1
```

## 3. Guardrail-by-guardrail

| # | Guardrail | Verdict | Command | Verbatim result |
|---|---|---|---|---|
| 1 | No `mobile/`, no Flutter | **PASS** | `git diff --name-only base..HEAD \| Select-String '(^\|/)mobile(/\|$)'` | `NONE`; working tree `Test-Path mobile -> False`; only one `pubspec.yaml` on disk and it declares `sdk: '>=3.13.0 <4.0.0'` with no `flutter:` key |
| 2 | `telemedicine_test.sql` byte-identical | **PASS** | `git log --follow -- telemedicine_test.sql` | Exactly one commit, `b443f3b`; blob `0b7b1d91…` at `b443f3b` **and** at `HEAD`; SHA-256 `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` |
| 3 | No schema drift | **PASS** | `php artisan sehatly:verify-schema` | `counts tables=82 views=2 columns=715 indexes=237`; `Discrepancies: 7 (0 drift, 7 informational)`; `PASS — 75 tables, 2 views verified. Nothing was written.` exit 0 |
| 4 | Zero skipped tests | **PASS** | `php vendor/bin/pest --log-junit … --log-otr …` | JUnit `tests=1162 assertions=22541 errors=0 failures=0 skipped=0`; 0 `<skipped>` nodes; OTR 1162/1162 `result@status=SUCCESSFUL` |
| 5 | No forbidden edits / no secrets | **FAIL** | see §5 | Plaintext 16-digit NIK written by `PasienController.php:213-217`; Must-NOT line 58 un-implemented. **No** committed credential, no `.env`, no live secret. |
| 6 | Nothing deleted that should exist | **PASS** | `git diff --diff-filter=D --name-only` | 99 deletions, every one traced to a named, pre-authorised plan clause or to the user's own pre-baseline removal — §6 |
| 7 | Plan followed, not rewritten | **PARTIAL** | `git log -S … -- .omo/plans/…` | Guardrails and success criteria **byte-intact** (nothing retro-fitted), but a `[x]` was set over unmet todo text — §7 |

---

### G1 — No `mobile/`, no Flutter anywhere. **PASS**

I did not take the test's name as proof. I read what it asserts and then tried to break its parser.

**The diff surface.**

```
> git diff --name-only 2d3b3b4..HEAD | Select-String -Pattern "(^|/)mobile(/|$)"
NONE

> git diff --name-only 2d3b3b4..HEAD | Select-String -Pattern "pubspec"
packages/sehatly_api_client/pubspec.lock
packages/sehatly_api_client/pubspec.yaml

> git diff --name-only 2d3b3b4..HEAD | Select-String -Pattern "flutter" -CaseSensitive:$false
tests/Unit/NoFlutterMobileTest.php

> Test-Path mobile
False
```

**The only `pubspec.yaml` in the whole repository**, `packages/sehatly_api_client/pubspec.yaml`, lines 14-22:

```yaml
environment:
  sdk: '>=3.13.0 <4.0.0'

dependencies:
  dio: ^5.11.1

dev_dependencies:
  lints: ^6.1.0
  test: ^1.26.2
```

No `flutter:` SDK constraint. No `flutter` dependency. Only `dio`.

**No `flutter:` SDK constraint was ever added anywhere in the diff:**

```
> git diff 2d3b3b4..HEAD -U0 | Select-String -Pattern "^\+\s*flutter\s*:|^\+\s*flutter_test\s*:|^\+\s*flutter_web_plugins\s*:"
NONE
```

**Every `package:flutter` occurrence in the repository, with `file:line`**, so nothing is hidden behind a summary count:

| file:line | what it is |
|---|---|
| `.omo/drafts/sehatly-telemedicine-platform.md:249` | planning prose about a future Flutter 3.50 |
| `.omo/evidence/task-24-sehatly.md:31` | evidence prose: "no `import 'package:flutter/...'` anywhere in `lib/`" |
| `.omo/start-work/ledger.jsonl:33` | ledger prose |
| `docs/mobile-integration.md:13` | prose: no `package:flutter` import |
| `docs/mobile-integration.md:87` | a table row: "put your adapter in your app" |
| `docs/mobile-integration.md:497` | **documentation code block** — `import 'package:flutter_secure_storage/…'` for the consuming app |
| `packages/sehatly_api_client/README.md:9` | prose asserting its absence |
| `packages/sehatly_api_client/README.md:43` | **documentation code block** — same adapter |
| `packages/sehatly_api_client/lib/src/realtime/push_registration.dart:51` | `///` docblock |
| `packages/sehatly_api_client/test/push_registration_test.dart:22` | `///` docblock |
| `tests/Unit/Console/OpenApiCommandTest.php:824` | `expect($source)->not->toContain('package:flutter')` — a **negative** assertion |

The two adapter code blocks are `flutter_secure_storage` and `firebase_messaging` sample code for the *mobile team's* app, which the plan's own design requires (guardrail line 46, and Must-have line 36: "a pure-Dart package (no Flutter dependency)"). They are documentation, not dependencies. **No `package:flutter` dependency was added.**

**What `tests/Unit/NoFlutterMobileTest.php` actually asserts** (read in full, 291 lines). Four tests, and the fourth is the one that stops the guard from being satisfied by vandalism:

1. `no mobile/ directory at its root` — `file_exists`, `is_dir` **and** `is_file` on `base_path('mobile')`, i.e. the literal `test ! -e mobile`.
2. `every pubspec.yaml … declares no flutter SDK constraint` — it **walks** the tree (not `git ls-files`, deliberately, so an *untracked* `mobile/` is caught), skips only `vendor/node_modules/.git/build/.dart_tool`, and asserts **non-vacuity**: `$specs` is not empty and `packages/sehatly_api_client/pubspec.yaml` is among them, "otherwise this guard is vacuous".
3. `no pubspec.yaml … depends on Flutter` — checks `dependencies` **and** `dev_dependencies` for `flutter`, `flutter_test`, `flutter_web_plugins`.
4. `the pure-Dart API client is still a pure-Dart package with its Dio transport` — asserts `dio` is **still** a dependency, that `lib/src/client.dart` exists, and that it still contains `package:dio/dio.dart`. The comment is explicit: *"A 'no Flutter' test that is satisfied by an empty package — or by deleting the transport — is not a guard, it is vandalism."*

**Live run:**

```
> php artisan test tests/Unit/NoFlutterMobileTest.php
{"tool":"pest","result":"passed","tests":4,"passed":4,"assertions":19,"duration_ms":598}
EXIT=0
```

**My own negative control**, because a guard that cannot fail is a guard that proves nothing. I transcribed the test's own parser and ran it over three fixtures written to `%TEMP%`, never inside the repository:

```
A. the real committed pubspec.yaml             env=sdk                    deps=[dio]                dev=[lints,test]  => CLEAN (guard passes)
B. probe with a `flutter:` SDK key             env=sdk,flutter            deps=[dio]                dev=[]            => VIOLATION -> environment.flutter (guard FAILS, as intended)
C. probe with a `flutter` dependency           env=sdk                    deps=[dio,flutter,sdk]    dev=[]            => VIOLATION -> dependencies.flutter (guard FAILS, as intended)
probes written to and removed from: C:\Users\axioo\AppData\Local\Temp/opencode (never inside the repository)
```

The parser discriminates. The guard is real. **G1 holds.**

---

### G2 — `telemedicine_test.sql` is byte-identical and read-only. **PASS**

```
> git log --follow --format="%h %ad %an %s" --date=short -- telemedicine_test.sql
b443f3b 2026-09-27 Ahmadz chore(api): baseline worktree and sync composer manifest with lock
```

**Exactly one commit has ever touched the file** — the todo-1 baseline, which is where it stopped being untracked and started being committed. There is no second commit, so there is no modification to find.

```
> git rev-parse b443f3b:telemedicine_test.sql
0b7b1d91837ffef967c499afd4bb0f1fbce43c6d
> git rev-parse HEAD:telemedicine_test.sql
0b7b1d91837ffef967c499afd4bb0f1fbce43c6d

> git log --format="%h" --diff-filter=M 2d3b3b4..HEAD -- telemedicine_test.sql
NONE - zero modifications since baseline
```

The git **blob** is byte-identical at the baseline and at `HEAD`. Current on-disk hash:

```
> (Get-FileHash telemedicine_test.sql -Algorithm SHA256).Hash
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
size: 59604
```

`AEFE2247E00F…` — the stated project-wide hash, unchanged. The verifier independently agrees: `reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)`.

**One honest clarification, because `git diff` alone would mislead here.** The file shows as `A` (added) in `base..HEAD`, not as unchanged:

```
> git diff --stat 2d3b3b4..HEAD -- telemedicine_test.sql
 telemedicine_test.sql | 1349 +++++++++++++++++++++++++++++++++++++++++++++++++
 1 file changed, 1349 insertions(+)
```

That is **1349 insertions and 0 deletions** — the file was *untracked* at the merge base (it was part of the user's dirty worktree) and the baseline commit added it to version control. It has not been edited since. The blob-identity check above, not the diffstat, is what proves read-only, and the blob hash is the same at both ends. **G2 holds.**

---

### G3 — No schema drift. **PASS**

Run against the real dev database, which is read-only by construction:

```
> php artisan sehatly:verify-schema
Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables; no deferred-constraint registry, retired in todo 18 when its last row resolved)

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: booking.status (515-516), home_care_pesanan.status (1104-1105), …
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on pasien_tanda_vital (line 1161)

 Live schema
 counts tables=82 views=2 columns=715 indexes=237 foreign_keys=105 checks=3
 information_schema columns=715 indexes=237 foreign_keys=105 checks=3

 Discrepancies: 7 (0 drift, 7 informational)
 documented_extra_table cache … | actual: present in the live schema
 documented_extra_table cache_locks … | actual: present in the live schema
 documented_extra_table failed_jobs … | actual: present in the live schema
 documented_extra_table job_batches … | actual: present in the live schema
 documented_extra_table jobs … | actual: present in the live schema
 documented_extra_table migrations … | actual: present in the live schema
 documented_extra_table personal_access_tokens … | actual: present in the live schema

 PASS — 75 tables, 2 views verified. Nothing was written.
EXITCODE=0
```

**Real numbers: 82 live tables, 75 expected, 2 views, 0 drift, 7 informational.** The 7 informational entries are exactly the framework-registered tables named in the brief — `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `personal_access_tokens` — and **per the brief I do not report these as a violation.** They are registered in `docs/schema-notes.md` with a written justification each, which is the plan's sanctioned mechanism (spec §4.4).

I reproduced the result independently on a private database so the number is not a single-run artefact:

```
 live database mysql / f4_scope_audit
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 counts tables=82 views=2 columns=715 indexes=237 foreign_keys=105 checks=3
 Discrepancies: 7 (0 drift, 7 informational)
 PASS — 75 tables, 2 views verified. Nothing was written.
EXIT=0
```

**And I did not take `0 drift` at face value.** See G5, where I chase the 142-vs-237 index gap down to the last index.

---

### G4 — Zero skipped tests. **PASS**

**Reporter control first, as instructed.** The reporter's own output is a *synthetic JSON envelope* in this environment, not Pest's — `ledger.jsonl` records this as a known tooling hazard ("vendor/bin/pest … have their stdout replaced … by a synthetic JSON envelope that is not the tool output"). The line it emitted:

```
{"tool":"pest","result":"passed","tests":1162,"passed":1162,"assertions":22541,"duration_ms":677266}
```

Key-presence assertion, *before* any count is read:

```
  json_decode error      : No error
  key present result     : YES   value='passed'
  key present tests      : YES   value=1162
  key present passed     : YES   value=1162
  key present assertions : YES   value=22541
  key present failed     : NO     <-- OMITTED BY REPORTER, not necessarily 0
  key present errors     : NO     <-- OMITTED BY REPORTER, not necessarily 0
  key present skipped    : NO     <-- OMITTED BY REPORTER, not necessarily 0
  key present incomplete : NO     <-- OMITTED BY REPORTER, not necessarily 0
  key present risky      : NO     <-- OMITTED BY REPORTER, not necessarily 0
  key present warnings   : NO     <-- OMITTED BY REPORTER, not necessarily 0
```

**The trap is real and I hit it: `failed`, `errors` and `skipped` are all absent.** A parser that does `(d.failed ?? 0)` would print a clean run; a parser that reads `d.failed.length` would throw. Neither one is entitled to a number. So the verdict does **not** come from this line.

**The verdict comes from two XML logs PHPUnit/Pest wrote itself**, which an interceptor cannot fake:

JUnit (attributes are always emitted; the root is `<testsuites>`, counters live on the child `<testsuite>`):

```
  testsuite @tests       = 1162
  testsuite @assertions  = 22541
  testsuite @errors      = 0
  testsuite @failures    = 0
  testsuite @skipped     = 0
  <skipped> nodes = 0 | <failure> nodes = 0 | <error> nodes = 0
```

Open Test Reporting (an independent second log; `--log-otr` emits XML despite the `.json` filename):

```
  <result> elements = 1162
    result@status=SUCCESSFUL : 1162
  event <e:failure    > = 0
  event <e:error      > = 0
  event <e:skipped    > = 0
  event <e:incomplete > = 0
  event <e:warning    > = 0
```

```
=== VERDICT ===
  tests=1162 failures=0 errors=0 skipped=0 assertions=22541 (JUnit attributes)
  baseline = 1162 tests / 22541 assertions
  PASS: zero skipped, zero failed, zero errored, and the run matches the 1162/22541 baseline.
EXIT=0
```

**1162 tests / 1162 passed / 0 failed / 0 errors / 0 skipped / 22541 assertions** — exactly the stated baseline, reproduced from scratch on a database I created myself.

**Database hygiene.** `phpunit.xml` untouched (`git status --porcelain phpunit.xml` clean). The run used a per-run override, gated before anything could touch the wrong database:

```
effective DB_DATABASE = f4_scope_audit
env(DB_DATABASE)     = f4_scope_audit
table count          = 84
GATE OK - private auditor DB
```

That gate script exits non-zero unless the effective database is `f4_scope_audit`, so a mis-set override would have aborted rather than migrated `telemedisin_db`. The private DB was built with `artisan migrate --seed --force` — **not** `migrate:fresh`, and never against `telemedisin_db` or the shared `telemedisin_db_test`. `migrate:rollback` was never run.

**No skip was manufactured.** `markTestSkipped` / `->skip(` / `#[Skip]` over the whole `tests/` tree returns only two hits, both inside a `tests/TestCase.php` docblock *quoting* the helper; there is no live call anywhere. And the suite's own skip count is 0, which is the proof that matters — a skip that exists would have to have run to be counted.

---

### G5 — Forbidden edits: index/constraint, destructive ops, legacy DB, secrets, NIK. **FAIL**

#### 5a. Indexes and constraints beyond what the plan authorised: **none.**

`verify-schema` reports reference `indexes=142` against live `indexes=237` while claiming 0 drift. 237 minus the 7 framework tables is not obviously 142, and I would not certify that on the strength of a "0 drift" line. I measured it.

```
live distinct indexes across all f4_scope_audit tables: 237
live tables: 82

== index count contributed by the 7 framework-registered extra tables ==
  cache                    2 indexes
  cache_locks              2 indexes
  failed_jobs              3 indexes
  job_batches              1 indexes
  jobs                     2 indexes
  migrations               1 indexes
  personal_access_tokens   4 indexes
  TOTAL from extras: 15
  => 237 - 15 = 222 indexes sit on the other 75 tables
```

222 on the 75 DDL tables against a 142-index reference model — an 80-index gap. Chased to the bottom, using the project's own `SqlSchemaParser` / `LiveSchemaReader` model classes:

```
reference model IndexSpec objects over 75 tables : 142
live model IndexSpec objects over 82 tables      : 237
live model IndexSpec objects over the 75 SQL tbls : 222
difference on the SQL tables                      : 80
reference: PRIMARY=75  named=30  engine/inline-named=37  total=142

=== per-table: indexes live but not present in the reference model ===
live indexes with no semantic match in the reference: 80 across 80 tables
  akses_rekam_medis_log      INDEX    akses_rekam_medis_log_rekam_medis_id_foreign (rekam_medis_id)
  apotek_stok                INDEX    apotek_stok_obat_id_foreign (obat_id)
  booking                    INDEX    booking_anggota_keluarga_id_foreign (anggota_keluarga_id)
  faskes                     INDEX    faskes_provinsi_id_foreign (provinsi_id)
  …
```

**All 80 are MySQL/InnoDB auto-created foreign-key-supporting indexes**, named `<table>_<column>_foreign`. The DDL declares those `FOREIGN KEY`s with no accompanying `KEY`, so the engine creates the index itself. Proof that none was invented by a migration:

```
reference FK columns: 105
live `*_foreign` indexes on a column the DDL declares an FK for: 79
live `*_foreign` indexes with NO corresponding DDL foreign key : 0

== total FK constraints, reference vs live ==
  reference model: 105   live information_schema: 105
```

**Zero** `_foreign` indexes lack a DDL-declared foreign key, and the foreign-key count is 105 on both sides. The `UNIQUE` keys that look like additions (`artikel_slug_unique`, `dokter_user_id_unique`, `booking_nomor_booking_unique`, …) are Laravel's auto-names for constraints the DDL declares **inline** on the column — the reference model's own accounting says so: `engine/inline-named=37`. The project has a dedicated regression test for exactly this, `tests/Unit/Schema/SchemaDifferFkImpliedIndexTest.php`. **No migration added an index or a constraint that the DDL does not authorise.**

#### 5b. Destructive operations: **none.**

Over every file in `database/migrations/`:

```
> Select-String -Path "database\migrations\*.php" -Pattern "dropColumn|dropTable|->drop\(|dropForeign|truncate|renameColumn|DB::statement\('ALTER TABLE.*DROP"
2026_10_01_000062_invoice_table.php:253: // Reading :947 alone truncates the list after `dibatalkan` and hides both
2026_10_01_000076_add_deferred_foreign_keys_table.php:213: DB::statement('ALTER TABLE pasien_tanda_vital DROP FOREIGN KEY fk_vital_rm');
```

One hit is the word "truncates" in a comment. The other is a single `DROP FOREIGN KEY` inside a `down()` method. No `dropColumn`, no `dropTable`, no `truncate()`, no `renameColumn` anywhere.

`dihapus_at` — the plan forbids declaring it as anything but `TIMESTAMP`:

```
> Select-String -Path "database\migrations\*.php" -Pattern "dihapus_at"   (declarations only)
2026_10_01_000012_users_table.php:67: $table->softDeletes('dihapus_at');
2026_10_01_000020_pasien_table.php:111: $table->softDeletes('dihapus_at');
```

Two declarations, both `$table->softDeletes('dihapus_at')`, which emits exactly `timestamp NULL`. Every other hit is a comment explaining why the column is *absent* elsewhere. Compliant.

#### 5c. The legacy `sehatly` database: **untouched.**

```
== legacy schema 'sehatly' : tables with MySQL CREATE_TIME (read-only) ==
TABLE_NAME                         ENGINE   ROWS     CREATE_TIME           UPDATE_TIME
cache                              InnoDB   0        2026-09-26 13:50:18   (never)
cache_locks                        InnoDB   0        2026-09-26 13:50:18   (never)
failed_jobs                        InnoDB   0        2026-09-26 13:50:18   (never)
job_batches                        InnoDB   0        2026-09-26 13:50:18   (never)
jobs                               InnoDB   0        2026-09-26 13:50:18   (never)
migrations                         InnoDB   4        2026-09-26 13:50:18   (never)
passkeys                           InnoDB   0        2026-09-26 14:27:07   (never)
password_reset_tokens              InnoDB   0        2026-09-26 13:50:18   (never)
sessions                           InnoDB   1        2026-09-26 13:50:18   (never)
users                              InnoDB   0        2026-09-26 14:27:07   (never)
row count: 10
```

Every table's `CREATE_TIME` is 2026-09-26 — the user's own scaffold period, before the branch's first commit on 2026-09-27. Nothing here has been written since. And the `migrations` ledger proves no project migration ever ran against it:

```
== legacy 'sehatly'.migrations contents (read-only) ==
  batch=1  0001_01_01_000000_create_users_table
  batch=1  0001_01_01_000001_create_cache_table
  batch=1  0001_01_01_000002_create_jobs_table
  batch=2  2024_01_01_000000_create_passkeys_table
  batch=2  2025_08_14_170933_add_two_factor_columns_to_users_table
count: 5
```

Five rows, all Laravel scaffold, **none of this project's 75+ migrations**. And no connection anywhere points at it:

```
== does this project define a 'sehatly' connection? ==
  connection sqlite               database=telemedisin_db
  connection mysql                database=telemedisin_db
  connection mariadb              database=telemedisin_db
  connection pgsql                database=telemedisin_db
  connection sqlsrv               database=telemedisin_db
```

#### 5d. Committed secrets: **none.** But one finding.

`.env` is not tracked and not in the diff:

```
> git ls-files --error-unmatch .env
error: pathspec '.env' did not match any file(s) known to git
```

`.env.example` is the only environment file changed, and **every** secret variable in it ships empty:

```
3:  APP_KEY=
26: NIK_CIPHER_KEY=
30: NIK_CIPHER_PREVIOUS_KEYS=
45: DB_PASSWORD=
72: REVERB_APP_KEY=
73: REVERB_APP_SECRET=
97: AWS_ACCESS_KEY_ID=
98: AWS_SECRET_ACCESS_KEY=
```

A regex sweep for secret-shaped assignments across all 914 changed paths returned 8 hits, **all false positives** — Indonesian words used as identifiers, not credentials:

| file:line | what it actually is |
|---|---|
| `app/Services/SuratKeterangan/SuratKeteranganTokenHabisException.php:76` | `token = 'Token QR tidak dapat dibuat…'` — an error message |
| `packages/sehatly_api_client/test/push_registration_test.dart:28` | `token = 'fcm-token-1'` — a test fixture |
| `packages/sehatly_api_client/test/support/fake_realtime_socket.dart:190` | `token = 'fcm-token-1'` — a test fixture |
| `tests/Feature/Audit/RedactionAbsenceTest.php:176,225` | `secret = 'Batuk productive…'` / `'Pasien mengeluh kembung…'` — *secret* as in a confidential symptom |
| `tests/Feature/Pasien/NikCipherTest.php:572,648` | the test setting/unsetting `NIK_CIPHER_KEY` |
| `tests/Feature/SuratKeterangan/SuratKeteranganTest.php:1941` | `token='.$token` — a JSON assertion |

**FINDING (low severity, disclosed by the project itself):** `config/services.php:94-115` ships four committed webhook HMAC secret defaults:

```
94:  'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET_MIDTRANS', 'UBAH-SEKRET-WEBHOOK-MIDTRANS-0000000000000001')
100: 'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET_XENDIT',  'UBAH-SEKRET-WEBHOOK-XENDIT-00000000000000002')
106: 'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET_DOKU',    'UBAH-SEKRET-WEBHOOK-DOKU-0000000000000000003')
112: 'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET_FLIP',    'UBAH-SEKRET-WEBHOOK-FLIP-00000000000000000004')
```

Each **has** an `env()` override, and each default is prefixed `UBAH` ("change") specifically so `grep` finds it — the file says so at line 66. No real provider key exists anywhere, which is what the plan's guardrail (line 52, "NEVER add a real payment-gateway settlement") requires. `docs/verification-report.md` §7.1 flags it independently. Not a scope breach; a deployment hazard the project already named.

#### 5e. Unmasked NIK values. **This is the failure.**

A regex sweep for raw 16-digit NIK-shaped literals across all 914 paths found 60 hits in 19 distinct values. Every one, with `file:line` (representative; full list below the fold):

| file:line | value | judgement |
|---|---|---|
| **`docs/mobile-integration.md:895`** | `3273010108900021` | **FINDING.** An unmasked 16-digit NIK in a documentation sample, in the section that teaches the mask. See below. |
| `database/seeders/DevFixtureSeeder.php:352` | `3171010101900001` | synthetic dev fixture; the plan explicitly authorised `DevFixtureSeeder` for data with no source in the SQL |
| `database/seeders/DevFixtureSeeder.php:369` | `3174010202950002` | same |
| `tests/Feature/Pasien/NikCipherTest.php:105,289-291` | `9999999999999999`, `0000000000000000`, `0123456789012345` | sentinels — impossible months/dates, sequential |
| `tests/Feature/Audit/RedactionAbsenceTest.php:39` | `3201234567890123` | DOB field `234567` = month 23 — **syntactically impossible**, so provably synthetic |
| `tests/Feature/Pasien/PasienProfileTest.php:555,613` and 7 more files | `1234567890123456` | the canonical placeholder; not a valid NIK structure |
| `tests/Feature/Pasien/NikCipherTest.php`, `PasienProfileTest.php`, `BookingTest.php`, `KonsultasiTest.php`, `AuditLoggingTest.php`, `NikCipherAuditTest.php`, `ArchitectureTest.php` | `3273123456780001` (×29), `3273010101900001` (×3), `3273014507910001/2`, `3201113001990003`, `3273010101900016`, `3273020202800002`, `3273010101900003`, `3273000000000000`, `3273123456780002` (×4) | test fixtures; several are *structurally plausible* (valid province/regency codes, plausible DOB) so they cannot be proven synthetic by inspection alone |
| `config/services.php:96` | `0000000000000001` | all zeros |
| `.omo/start-work/ledger.jsonl:36` | `1234567890123456` | ledger prose |

**`docs/mobile-integration.md:895` in full context:**

```
| stored | published |
| --- | --- |
| `3273010108900021` | `3273••••••••0021` |
```

A structurally valid Indonesian NIK (32.73 = Kabupaten Bandung, DOB field `010890`, serial `0021`) printed in full in a documentation table, where a masked example would teach the same lesson. It is **not** the project's canonical test NIK (`3273123456780001`), so it was hand-written for the doc. It is almost certainly synthetic and I cannot prove a real Dukcapil number is not involved offline — which is exactly why it should not be there. For an Indonesian health system this is a real PDP-law hygiene defect in the one artefact the mobile team reads. **Reported rather than waved through.**

**Phone numbers.** 80 hits, all obviously synthetic: `081234567890`, `089999999999`, `08111222333`, `081288888888`, `0123456789`, `0812000000NN`. No `+62` international form, no real subscriber pattern. **Clean.**

**The actual violation — the Must-NOT at plan line 58, and a live plaintext-PII write path.**

The plan's guardrail:

> **NEVER** encrypt a value into a column narrower than its ciphertext. `pasien.nik` is `CHAR(16)`; AES-256-CBC ciphertext of a 16-byte plaintext is 32 bytes (44 chars base64 / 64 hex), which does not fit and raises MySQL error 1406 in strict mode. **Ciphertext goes in a `TEXT` column; a fixed-width 16-char HMAC carries the unique index.** See todo 50.

The DDL it refers to, `telemedicine_test.sql:222`:

```
222:  nik CHAR(16) NULL UNIQUE COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP',
263:  nik CHAR(16) NULL,
```

What was delivered:

```
=== live schema on 'pasien' for NIK-ish columns ===
  nik                    char(16)         null=YES maxlen=16
  nomor_kk               char(16)         null=YES maxlen=16

=== indexes on 'pasien' that carry uniqueness ===
  pasien_nik_unique                (nik) unique=yes
```

**No `TEXT` ciphertext column. No 16-char HMAC blind-index column. The `UNIQUE` index is on the plaintext column itself.**

```
> Select-String -Path "database\migrations\*.php" -Pattern "nik_cipher|nik_index"
NONE - no migration creates the columns the cipher needs
```

The cipher was built; the columns it needs were not:

```
> ls app/Casts/
  (no app/Casts directory at all)
> git grep -l 'uq_pasien_nik_hash'
.omo/plans/sehatly-telemedicine-platform.md:655   <- the PLAN says it, and only the plan
```

`app/Support/NikCipher.php` is candid about it, which is why I could find this quickly:

- line 38-39: *"**The schema change that has to be authored is a separate concern and is not part of this class.**"*
- lines 406-410: *"`$payload` is the proposed `nik_cipher` column, **which does not exist yet**, so today it is always `null` … `pasien.nik` is kept for legacy and imported **plaintext** rows."*
- `NikCipher::PAYLOAD_LENGTH` = 6 header + 16 IV + 32 ciphertext + 12 MAC = 66 bytes = **88 base64 characters**, against a 16-character column.

And the consequence is not theoretical. The family-member endpoint writes a full national ID straight to a `CHAR(16)` column:

```
app/Http/Requests/Pasien/AnggotaKeluargaRequest.php:128
    'nik' => ['nullable', 'string', 'digits:16'],
   (docblock, lines 28-33: "`pasien_anggota_keluarga.nik` is `CHAR(16) NULL` (:263) - the same
    national identifier as `pasien.nik` (:222)… `digits:16` is used.")

app/Http/Controllers/Api/V1/PasienController.php:205-217   (anggotaKeluargaStore)
    $validated = $request->validated();
    $anggota = new PasienAnggotaKeluarga;
    $anggota->pasien_id = $pasien->getKey();
    foreach ($request->anggotaKeys() as $key) {
        $anggota->{$key} = $validated[$key];      // <-- $validated['nik'] assigned verbatim
    }
    $anggota->save();                              // <-- plaintext into CHAR(16)
```

No cast, no cipher, no hash. `PasienResource` masks on **read** (`NikCipher::mask($this->resource->nik_cipher, $this->resource->nik)`, which today always takes the plaintext branch), so the exposure is at rest — a database dump, a replica or a binlog carries every family member's national ID in the clear, in a system whose own DDL says encryption is mandatory under Indonesian law.

**This is the guardrail breach that decides the verdict.** It is a **Must-NOT** item, it is in the plan's own guardrail list, it was never met, and it produces live plaintext PII.

---

### G6 — Every deletion, and my judgement on each. **PASS**

```
> git diff --diff-filter=D --name-only 2d3b3b4..HEAD
```
99 paths. I attributed each to the commit that removed it, because "99 deletions" without attribution is not an audit:

| commit | n | what | pre-authorised by | judgement |
|---|---|---|---|---|
| `b443f3b` baseline | 20 | 8 `Auth` controllers, `Settings/PasswordController.php`, `Auth/LoginRequest.php`, `routes/auth.php`, `resources/js/ssr.jsx`, `appearance-dropdown.tsx`, `heading-small.tsx`, `icon.tsx`, `settings/password.tsx`, `.prettierrc`, `.prettierignore`, `eslint.config.js`, `vite.config.js` | **The user had already deleted them.** `.omo/evidence/task-1-sehatly.md:792-820` transcribes the pre-baseline `git status --porcelain` dump showing 21 ` D` paths and accounts for each: *"each one was already ` D` in the pre-state porcelain dump transcribed in §1, and the commit only records that existing state. The baseline commit is a **record** of the worktree, not a set of edits to it."* Counted independently: 21 pre-state deletions, 20 in the commit, **0 introduced by todo 1**; the 21st (`storage/framework/views/.gitignore`) was deliberately *restored*. | **Legitimate.** Not the executor's deletions. |
| `60cc707` (todo 5) | 29 | the 26 shadcn/Radix components + `components.json` + `resources/js/lib/utils.ts` + `resources/css/app.css` | Todo 5, verbatim: *"**Move** (git `mv`, do not copy-and-leave-duplicates) the 26 shadcn/Radix components … plus `resources/js/lib/utils.ts` and `resources/css/app.css`"* | **Legitimate — a move, not a deletion.** 4 are recorded by git as renames (R064/R070/R079/R084); the rest show as A+D only because rename detection is per-file here. |
| `c6d0beb` (todo 7) | 3 | `0001_01_01_000000_create_users_table.php`, `2024_01_01_000000_create_passkeys_table.php`, `2025_08_14_170933_add_two_factor_columns_to_users_table.php` | Appendix **A.4**, which *mandates* it: *"**Delete** `0001_01_01_000000_create_users_table.php` and `2025_08_14_170933_…`. Deleting a *migration file* is not a schema change."* `passkeys` is mandated in todo 7's own text. | **Legitimate.** Explicitly authorised, with a stated reason per file. |
| `3546282` | 4 | `packages/sehatly_api_client/.dart_tool/*` (incl. a 30 MB SDK-pinned snapshot) | Untracking generated build artefacts via `.gitignore` | **Legitimate.** A follow-on fix the ledger records honestly. |
| `028e489` | 1 | `web/src/lib/api.ts` | Superseded by the per-domain `web/src/lib/api/*` modules in the same todo | **Legitimate** (refactor within the same commit). |
| `1d2f9a7` (todo 30) | 89 | `app/Actions/Fortify/`, `app/Concerns/`, `app/Http/Controllers/Settings/`, `app/Http/Requests/Settings/`, `app/Http/Middleware/HandleAppearance.php`, `HandleInertiaRequests.php`, `app/Providers/FortifyServiceProvider.php`, `config/fortify.php`, `config/inertia.php`, `routes/settings.php`, 34 `resources/js/**` files, 9 scaffold Feature tests, `DashboardTest.php`, `ExampleTest.php`, `tsconfig.json`, `vite.config.ts`, `resources/js/app.tsx` | Todo 30, which **enumerates the manifest path by path**: `HandleInertiaRequests.php`, `HandleAppearance.php`, `FortifyServiceProvider.php`, `app/Actions/Fortify/`, `app/Concerns/`, `routes/settings.php`, `app/Http/Controllers/Settings/`, `app/Http/Requests/Settings/`, "the 12 files under `resources/js/pages/`", "the 8 under `resources/js/hooks/`", `resources/js/layouts/`, `resources/js/types/`, "the remaining `resources/js/components/*` that were **not** relocated", and *"the 9 scaffold Feature tests under `tests/Feature/{Auth,Settings}/` and `DashboardTest.php`/`ExampleTest.php`"*. | **Legitimate** — the single largest deletion in the project, and every path is named in the plan. Guardrail line 49 compliance is the clause *"Every deletion must target a path that task 1's baseline commit recorded — if a file is not tracked in that commit and is not one this plan created, STOP and report rather than deleting it."* All 89 were tracked at `b443f3b`. |
| `42b001e` | 3 | `app/Services/Audit/AuditRedactor.php`, `AuditWriter.php`, `AuditedModels.php` | A `wip:` commit preserving todo-39 progress. | **Reviewed and legitimate**: the Audit *service* layer was relocated; `RekamMedisService` remains the only writer of `audit_log` and the observers live in `app/Observers/`. No access-log capability was removed. |

**Nothing was deleted that should exist.** All 99 map to a named plan clause, a recorded move, or a removal the user had already made. **Guardrail line 49 holds** — and specifically, no untracked file was deleted, no `git checkout .` / `git restore .` / `git clean` / `git stash` was run, and the user's untracked rebranding assets were committed, not destroyed: `Sehatly Logo Icon.png`, `Sehatly.svg` and `WhatsApp Image 2026-09-26 at 7.07.11 PM.jpeg` all appear as `A` in the diff.

---

### G7 — Was the plan rewritten to make the work look compliant? **NO.**

This is the finding with the most leverage available to an auditor, so I attacked it from three directions.

**First: the plan file is new on this branch, so the obvious diff proves nothing.**

```
> git diff --name-status 2d3b3b4..HEAD -- .omo/plans/
A	.omo/plans/sehatly-telemedicine-platform.md
```

The plan did not exist at the merge base. I therefore audited its **27-commit edit history**, classifying every changed line.

**Second: did any *criterion* get altered?**

```
########## 5. Success criteria block: was ANY line ever deleted? ##########
(no output above = the 15 Success criteria were never edited)

########## 6. The '### Must NOT have' block: was ANY line ever deleted? ##########
(no output above = none of the 15 Must-NOT guardrails was ever deleted)
```

**All 15 numbered Success criteria and all 15 Must-NOT guardrails are byte-intact.** Not one was deleted, reworded or softened. In particular the NIK guardrail survives verbatim:

```
########## 4. The NIK-related Must-NOT guardrail, and whether it was ever touched ##########
b443f3b chore(api): baseline worktree and sync composer manifest with lock
(commits above touched that exact sentence)
```

`b443f3b` is the commit that **first added** the file, so `git log -S` reports it as "touching" the sentence. There is no later commit. The guardrail was never revised.

**Third: which commits changed body text at all?**

```
########## 2. Every commit that changed a NON-CHECKBOX line of the plan ##########
b443f3b  2026-09-27 chore(api): baseline worktree and sync composer manifest with lock   [216 paragraph lines changed]
d19418a  2026-09-27 plan: close todo 18, record A.25 (stale-file-state finding) and A.26 (A.17 regex gap)   [44 paragraph lines changed]
```

`b443f3b`'s 216 "changes" are simply the file's initial content. **Exactly one** post-baseline commit edited plan body text: `d19418a`. I read what it changed, because the direction of the change is the whole question:

> *"**`fk_vital_rm` is the ONLY constraint migration 76 adds. It must NOT add a foreign key on `pasien_penjamin.faskes_rujukan_id`.** The earlier wording of this todo instructed exactly that and was wrong: the SQL declares no `FOREIGN KEY` for that column, todo 9's verifier measured it live as a bare `unsignedBigInteger` with no constraint, and plan appendix A.10/A.11 settled it. **Adding one would be reported as `extra_foreign_key` drift and would fail success criterion 1** — the very checkpoint this todo exists to satisfy."*

That rewrite **removed** an instruction that would have added an unauthorised foreign key. It made the plan **more** compliant, not less.

**And the retro-fit I most suspected did not happen.** I expected todo 50's "REVISED here" text to have been written after the work, to excuse a failure. It was not:

```
########## 1. When did todo 50's 'REVISED here' text first appear? ##########
b443f3b chore(api): baseline worktree and sync composer manifest with lock
```

Present in the plan as **first committed**, before todo 50 ran. Comparing the paragraph across the commit that closed it:

```
=== BEFORE (todo 50 body, commit 68cec44^) ===
- [ ] 50. Implement deterministic NIK encryption and API masking
  What to do / Must NOT do: … **Two reviewers independently established … is therefore REVISED here.** …
=== AFTER (todo 50 body, commit 68cec44) ===
- [x] 50. Implement deterministic NIK encryption and API masking
  What to do / Must NOT do: … **Two reviewers independently established … is therefore REVISED here.** …
```

Byte-identical instruction paragraph; the only delta is the checkbox. So the plan's own heading — *"APPENDIX A — Plan corrections log (appended by start-work; additive, nothing above was rewritten)"* — is **accurate as written**, with the single exception of `d19418a`, which rewrote body text in order to *remove* a defect.

**The plan was not rewritten to make the work look compliant. That specific accusation does not stick.**

**But a checkbox was still set to `[x]` over unmet text**, and that is a real finding:

```
> grep -c '^- \[ \] [0-9]' .omo/plans/sehatly-telemedicine-platform.md
open   [ ]: 0
closed [x]: 54
(no numbered todo is open)
```

Todo 50's own text, which the checkbox now claims is done, mandates: `nik_cipher TEXT NULL`; `nik_hash CHAR(16) NULL`; `App\Casts\NikEncrypted`; *"a **replacing index migration** that drops the UNIQUE on `pasien.nik` and adds `UNIQUE KEY uq_pasien_nik_hash (nik_hash)`"*. And its acceptance criteria mandate *"a test asserts `strlen(base64_decode($pasien->nik_cipher)) === 32`"*, *"a test asserts inserting a second patient with the same NIK raises a `QueryException` on `uq_pasien_nik_hash`"*, *"a test asserts the raw `pasien.nik` column is `NULL` for every application-created row"*.

**None of it exists.** No `app/Casts/`. No `uq_pasien_nik_hash` outside the plan text. `pasien.nik` is not NULL for application-created rows. `[x]` says otherwise.

**The two known scope violations are both still open, and honestly recorded.** This I verified rather than assumed:

**`CHAR(16)` NIK vs required `TEXT`** — `docs/verification-report.md` §7.2, verbatim:

> "### 7.2 Todo 50 gap — the NIK column type is still `CHAR(16)`
> The NIK cipher and the HMAC blind index exist. The DDL still declares `nik CHAR(16)` where the plan requires ciphertext in **TEXT**, and **no migration was written**. The acceptance criterion is literally unmet. … **Open.**"

and `ledger.jsonl:75`: *"The DDL declares nik CHAR(16) while the acceptance criterion requires ciphertext in TEXT. A 16-character column cannot hold ciphertext, so the schema change is unavoidable. I told the executor NOT to write a migration - migrations are schema authority - and to report the exact DDL someone must author instead."* F1 classified it as a **scope violation**, not a type error. **Confirmed still open.**

**`FINDING-48-race`** — `ledger.jsonl:94`, event `open-finding-for-F1`, severity `needs-a-decision`: *"PaymentService::huntap() resolves a duplicate webhook by SELECT … FOR UPDATE on the pair (gateway, nomor_referensi). A row-level lock on a query that matches NO row locks nothing, so two CONCURRENT first deliveries of the same event can both find no row and both proceed to insert. There is no unique index on (gateway, nomor_referensi) to make the second one fail."* And `docs/verification-report.md` §7.1: *"the concurrent case is unproven and unfixed … The finding stands exactly as stated."* F1 refuted the *mechanism attribution* while disclosing a genuinely unguarded race of its own finding (`F1-plan-compliance-audit.md:301`: "One genuinely unguarded race, disclosed as an observation and NOT counted against criterion 13"). **Confirmed still open.**

Neither was quietly closed. The Final-verification-wave checkboxes are all still `[ ]` — F1 marked none, and neither did I.

---

## 4. Every secret and unmasked NIK found, with `file:line`

**No secret.** `.env` untracked; `.env.example` ships every key empty; 8 regex hits all false positives (table in §5d); no API key, token or OTP value anywhere in the diff. One disclosed hazard: four `UBAH-`-prefixed webhook HMAC defaults at `config/services.php:96,102,108,114`, each with an `env()` override.

**NIK-shaped values:** 60 occurrences, 19 distinct, listed with `file:line` in §5e. One naming it as a finding:

- **`docs/mobile-integration.md:895`** — `3273010108900021`, an unmasked structurally valid Indonesian NIK printed in a documentation table. Low severity, real PDP hygiene defect, should be replaced with a `3173••••••••0001`-style illustrative value.
- The rest are test fixtures and a dev-fixture seeder. Several are structurally plausible rather than provably fake; I report that limitation rather than asserting they are safe. `database/seeders/DevFixtureSeeder.php:352,369` writes two of them as **plaintext** — the direct consequence of §5e.

**Live plaintext NIK write path (the actual violation):**
- `app/Http/Requests/Pasien/AnggotaKeluargaRequest.php:128` — `'nik' => ['nullable','string','digits:16']`
- `app/Http/Controllers/Api/V1/PasienController.php:213-217` — assigned verbatim and saved to `pasien_anggota_keluarga.nik CHAR(16)`
- `database/migrations/2026_10_01_000020_pasien_table.php:72` — `$table->char('nik', 16)->nullable()->unique()`, against `app/Support/NikCipher.php` line 406 stating the column *"does not exist yet"*

**Phone numbers:** 80 hits, all synthetic. No real subscriber.

## 5. Whether the plan file was altered to make the work look compliant

**No.** All 15 Must-NOT guardrails and all 15 Success criteria are byte-intact; no numbered criterion was ever edited. One post-baseline commit (`d19418a`) rewrote body text, and it **removed** an instruction that would have added an unauthorised foreign key — a strengthening. Todo 50's contested paragraph was present in the plan's first commit, not written afterwards. The "additive, nothing above was rewritten" claim in Appendix A's own heading holds except for that one commit, in the compliant direction.

**One checkbox, however, is a false completion:** todo 50 is `[x]` over text that mandates a schema change, a cast class and a replacing index migration that do not exist. That is not plan retro-fitting; it is a completion signal that overstates the work.

## 6. Overall verdict

# OUT OF SCOPE

**Specific reasons, no others:**

1. **A Must-NOT guardrail in the plan's own list is un-implemented and produces live plaintext PII.** Plan line 58 requires ciphertext in `TEXT` with a 16-char HMAC carrying the unique index. The delivered schema is `pasien.nik CHAR(16) UNIQUE` with no ciphertext column, no blind-index column, and no `app/Casts/` at all; `PasienController.php:213-217` writes a full 16-digit Indonesian national ID into `pasien_anggota_keluarga.nik` in plaintext, against a DDL comment that reads *"WAJIB dienkripsi … sesuai UU PDP"*. A scope-fidelity gate cannot certify IN SCOPE while a documented Must-NOT is unmet and a privacy-law-mandated control is absent.

**Guardrails that hold, with the specific reason each is not a violation:**

2. No `mobile/`, no Flutter dependency, no `flutter:` constraint — G1, with a negative control proving the guard discriminates.
3. `telemedicine_test.sql` byte-identical, one commit ever, SHA-256 `AEFE2247E00F…` unchanged, identical git blob at baseline and HEAD — G2.
4. Zero schema drift: 82 live tables, 75 expected, 2 views, **0 drift**, 7 informational framework tables which the brief directs me not to report — G3.
5. Zero skipped tests: 1162/1162, **0 skipped**, 22541 assertions, read from two PHPUnit-written XML logs after proving the JSON reporter omits the key — G4.
6. No unauthorised index or constraint: 105 FKs on both sides, 0 `_foreign` indexes without a DDL-declared FK, 0 drift against a 142-index model — G5a.
7. No destructive migration operation; `dihapus_at` `TIMESTAMP` via `softDeletes()` in both places — G5b.
8. The legacy `sehatly` database untouched — 10 tables all created 2026-09-26, 5 scaffold migrations and none of this project's, no connection configured for it — G5c.
9. All 99 deletions legitimate and traceable to a named plan clause, a recorded move, or the user's own pre-baseline removals — G6.
10. The plan was not retro-fitted — G7.

**What would move this to IN SCOPE:** author the migration todo 50's own text specifies (`nik_cipher TEXT`, `nik_hash CHAR(16)` with `uq_pasien_nik_hash`, `App\Casts\NikEncrypted`), route both NIK write paths through it, and replace the unmasked `3273010108900021` in `docs/mobile-integration.md:895`. `FINDING-48-race` is a correctness finding for F1/F2 rather than a scope breach — the plan forbids adding the index, and the plan was obeyed.

---

## Appendix — commands, and the constraints I held

Every command was run from `C:\Users\axioo\Desktop\sehatly`. Every PHP invocation used the absolute 8.4.17 binary. `laravel/framework` 13.33 boots with `CarbonImmutable`, a sibling of `Carbon` — noted because it is a real trap in this codebase (`ledger.jsonl` records 26 type-widening sites created by it), though it did not affect this gate.

**Constraints honoured, each verified:**

| constraint | how |
|---|---|
| `phpunit.xml` untouched | `git status --porcelain phpunit.xml` clean; override via `$env:DB_DATABASE` only |
| no `migrate:fresh` against `telemedisin_db` or `telemedisin_db_test` | private `f4_scope_audit`, built with `migrate --seed --force`; a gate script refuses to act unless the effective DB is `f4_scope_audit` |
| no `migrate:rollback` | never invoked |
| no `markTestSkipped`, nothing skipped to make a run look clean | 0 skipped; `markTestSkipped` appears only inside a `tests/TestCase.php` docblock |
| no product file modified | `git status --porcelain` shows only `?? .playwright-mcp/` (not mine) plus this evidence file |
| `.omo/plans/` untouched, no checkbox marked | read-only; the four F1-F4 checkboxes remain `[ ]` |
| ledger appended, never rewritten, never a redirect target | one `node -e "JSON.stringify(...)"` append, validated as parsing, and the pre-existing line count and content hash re-checked after |
| `git add -A` never used | single explicit pathspec for this file |
| `.playwright-mcp/` left alone | untracked, never staged |
| auditor scratch files outside the repository | `%TEMP%\opencode\*.php` and `*.sh`, including the negative-control fixtures |

**Scratch files written by this audit** (all outside the repo, none product code): `f4-dblist.php`, `f4-legacy-sehatly.php`, `f4-setup.php`, `f4-gate.php`, `f4-reporter-control.php`, `f4-flutter-negative-control.php`, `f4-secrets-scan.php`, `f4-index-audit.php`, `f4-index-exact.php`, `f4-index-model.php`, `f4-fk-implied.php`, `f4-nik.php`, `f4-nik-plaintext.php`, `f4-orphans.php`, `f4-plan-integrity.sh`, `f4-plan-integrity2.sh`, plus the suite logs `f4-junit.xml` and `f4-otr.json`.

**Two places where I corrected myself mid-audit, recorded because a gate that hides its own errors is not a gate.** My first hand-rolled SQL parser read only **1** `CREATE TABLE` block and reported "NONE" for missing and extra indexes — a vacuous pass; I discarded it and redid the comparison with the project's own `SqlSchemaParser`. And I initially read commit `68cec44` as having rewritten todo 50's instruction paragraph; diffing the paragraph across that commit showed it byte-identical, and `git log -S` moved the rewrite to the baseline commit. Both corrections are in the findings above as stated.
