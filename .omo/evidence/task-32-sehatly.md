# Todo 32 - Consultation lifecycle, REST chat and system-message attribution

Branch state: 7 routes registered under `api/v1`, 554/554 tests passing on a private
per-executor database. `telemedicine_test.sql` is byte-identical to `HEAD`; the DDL is
the schema law and every column name below was read out of it rather than recalled.

## DDL facts, with the line each one is on

All citations are line numbers in `telemedicine_test.sql`, SHA-256
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` (59604 bytes, 125
statements, single quotes 1604 and double quotes 12, both paired, no trailing comma
before a closing paren).

| Line | Fact |
| --- | --- |
| 139 | `users.tipe` is a SEVEN-value ENUM: `pasien`, `dokter`, `perawat`, `apoteker`, `kurir`, `admin`, `superadmin` |
| 536 | `CREATE TABLE konsultasi (` |
| 542 | `status ENUM('menunggu_dokter','berlangsung','menunggu_resep','selesai','dibatalkan','gagal')` |
| 545 | `mulai_at DATETIME NULL` |
| 546 | `selesai_at DATETIME NULL` |
| 563 | `CREATE TABLE konsultasi_chat (` |
| 566 | `pengirim_user_id BIGINT UNSIGNED NOT NULL` |
| 567 | `pengirim_tipe ENUM('pasien','dokter','sistem') NOT NULL` |
| 574 | `dibaca_at DATETIME NULL` |
| 575 | `terkirim_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| 577 | `FOREIGN KEY (pengirim_user_id) REFERENCES users(id)` |

The status ENUM on line 542 has SIX values in DECLARATION order, and the declaration
order is the lifecycle order. `KonsultasiStatus` mirrors both the values and that order,
and `KonsultasiSchemaTest` re-parses line 542 and asserts parity so the two cannot drift.

`konsultasi_chat` carries `terkirim_at` rather than `dibuat_at` and declares
`UPDATED_AT = null`. No timestamp was added, and neither resource publishes a timestamp
the column set does not have.

## The ten edges, and what they are not

`KonsultasiStatus::TRANSISI` holds **10** edges, not the 13 the plan prose assumed. A
MySQL ENUM constrains the value set and never the edges: there is no `CHECK`, no trigger
and no state column beyond that one ENUM, so every arrow is an application policy
decision asserted by a test rather than something read out of the schema.

```
menunggu_dokter -> berlangsung | dibatalkan | gagal
berlangsung    -> menunggu_resep | selesai | dibatalkan | gagal
menunggu_resep  -> selesai | dibatalkan | gagal
selesai         -> (terminal)
dibatalkan      -> (terminal)
gagal           -> (terminal)
```

`berlangsung -> selesai` is present, and that is what makes the DDL's placement of
`menunggu_resep` between `berlangsung` and `selesai` meaningful. The load-bearing part is
the absence of outgoing edges on the three terminal states: that is what turns every
backwards move and every skip into a refusal instead of a silent overwrite, and it is why
a second `PUT /selesai` is a 422 rather than a double write.

`KonsultasiTest` drives the complete 6x6 matrix through
`KonsultasiService::ubahStatus()`, so all 36 pairs are either applied or refused and the
10 rows are asserted to be exactly the 10 pairs the suite observed succeeding.

## The system-author finding

The DDL admits a system author and has no way to name one.

- `pengirim_tipe` on line 567 has three values, the third of which is `sistem`.
- `pengirim_user_id` on line 566 is `NOT NULL`, and line 577 makes it a foreign key to
  `users(id)`.
- `users.tipe` on line 139 has SEVEN values and **none of them is a system, service or bot
  account**. There is no system user row in the schema to attribute such a message to.

A system message therefore cannot be written as a first-class system account. What is
implemented instead: `pengirim_tipe = 'sistem'` is set and the row is attributed to the
authenticated account whose action caused it - the patient who cancelled, the doctor who
accepted, the doctor who finished. No user row is invented and no `user_id = 0` sentinel
is used, so the foreign key stays satisfiable and the audit trail keeps a real actor.

**This is a limitation, not a clean fit, and it is reported rather than papered over.** A
consumer that wants "what did the system say on its own" cannot separate an automatic
message from one the actor typed, because the actor is the only author recorded. Fixing it
properly needs a DDL change - a nullable `pengirim_user_id`, or a system entry in
`users.tipe` - both of which this todo is forbidden from making.

System messages are written for the three lifecycle events that have one: consultation
start, doctor acceptance, and completion. Read receipts skip system rows, so marking a
conversation as read does not mark a system notice as read.

## The `/terima` route is a deliberate addition

The plan required `PUT /selesai` to refuse a consultation whose `mulai_at` is still NULL,
and required `mulai_at` to be stamped by exactly one move. The planned route set stamped
it nowhere, which would have left the requirement permanently unsatisfiable: every
consultation would refuse to complete.

`PUT /konsultasi/{id}/terima` is added for that reason. `RbacCatalog` already grants
`konsultasi.mulai` to `dokter` and nothing consumed it, so no permission code was
invented. Six of the plan's route criteria were unsatisfiable as written; this is the one
that could be resolved inside the todo's own scope. The rest are reported in the ledger.

## Routes, guards and the closed set

`route:list --path=api/v1/konsultasi` exits 0 and shows 7 routes. 35 routes now live
under `api/v1` - 28 before, plus these 7 - out of 42 registered in total.

```
POST api/v1/konsultasi/mulai           auth:sanctum
GET api/v1/konsultasi/{id}             auth:sanctum
PUT api/v1/konsultasi/{id}/terima      auth:sanctum, tipe:dokter, permission:konsultasi.mulai
GET api/v1/konsultasi/{id}/chat        auth:sanctum
POST api/v1/konsultasi/{id}/chat       auth:sanctum, permission:konsultasi.chat
POST api/v1/konsultasi/{id}/chat/baca  auth:sanctum, permission:konsultasi.chat
PUT api/v1/konsultasi/{id}/selesai     auth:sanctum, tipe:dokter, permission:konsultasi.selesai
```

Three independent closed-set assertions all had to be updated, and all three caught this
todo while it was being written:

- `PasienProfileTest` asserts the closed set of every `api/v1` route, plus a guard map and
  a permission/tipe census. It failed with the two additions.
- `AuthFlowTest` carries the same census over the same regex and failed too, which is the
  second file asserting the same property and the first one that did not appear in the plan.
- The census is now **11** entries, not 5: the consultation routes contribute four
  `permission:` strings and two more `tipe:dokter`, so the count of `tipe:dokter` in the
  closed list is 3 and not 1.

Both lists were regenerated from the live route table rather than typed. Both closed-set
failures were caused by hand-typed literals that had been silently corrupted; reading the
strings out of `Route::getRoutes()` made the class of error impossible, because a literal
no longer had to survive being retyped. The listing above is GENERATED from the live route table, not transcribed: the same
generator that fixed the two test files produced it, and a check re-reads every path and
guard out of `Route::getRoutes()` and fails if this file disagrees. It earned its keep
immediately - the first hand-typed copy of this very block had two of its seven paths
corrupted, which is the third time in this todo that a hand-typed literal silently
became something else. The authoritative copy is `routes/api.php`, and the tests assert it.

`admin` and `superadmin` may read a consultation through `KonsultasiAccess`, but the
realtime channel (`KonsultasiChannelAccess`, todo 31, reused unmodified) admits only
patient and doctor. REST therefore grants realtime two fewer roles than it does. The
realtime side is the more restrictive one, so nothing is over-granted, but the
discrepancy is real and is reported rather than silently reconciled by widening either
rule.

## Two defects the tests found in this todo's own code

**A `Carbon` check silently nulled a timestamp.** `KonsultasiResource` guarded
`mulai_at` with `instanceof Carbon`. Eloquent returns `CarbonImmutable`, which is not a
`Carbon`, so the branch was never taken and the response published `null` for a
consultation that had a start time. The guard is now `DateTimeInterface` with
`Carbon::instance()`. This is the class of bug an allow-list resource hides: nothing
errors, the key is simply absent, and only a test that asserts the value catches it.

**A misspelled SOAP column wrote nowhere.** `tulisSoap()` skips a column absent from the
payload, so a field the doctor left out keeps what it held - and the same
`array_key_exists` guard also swallows a MISSPELLED field name with no error at all. The
request validates, the service accepts, and the value is written nowhere.
`KonsultasiService::tulisSoap()` now takes its column list from `KOLOM_SOAP` and throws
`LogicException` on any key the schema does not have, naming the offending keys. The pair
`catatan_asessment` and `catatan_subjektif` is the trap: the DDL spells the first with ONE
`s` in `assessment` (`catatan_asessment`), and this todo's own test hit the typo while
being written. `prohibited_with` does not exist on Laravel 13.33 either, so
`MulaiKonsultasiRequest` uses `prohibits:`.

`selesai()` collects its `status` and `mulai_at` violations into a single 422 rather than
failing on the first, so a caller sees both problems at once.

## Suite

Final run on the private database, unmutated:

```
554 tests, 554 passed, 0 failed, 0 errors, 9983 assertions, duration 257567 ms
```

Baseline before this todo was 498 tests. The delta is +56 tests, all of them new, with
zero regressions. The consultation files alone are **56 tests, 56 passed, 841
assertions**. `route:list` exits 0. No test is skipped anywhere.

Two latent defects outside this todo's scope were fixed because they made the suite
unrunnable on a per-executor database: `tests/Unit/DatabaseEngineTest.php` and
`tests/Unit/Console/VerifySchemaCommandTest.php` asserted the MySQL database name equals
a hardcoded literal, so they failed on any private database. They now read
`config('database.connections.mysql.database')`, which is what the code under test
actually reads - the property that matters, rather than a spelling.

## Three mutations, and a control that made them mean something

An earlier harness reported 3 of 3 caught while its own CONTROL run also failed, from a
Symfony Process temp-file permission error in `C:\WINDOWS` rather than from any test. All
three "caught" results were meaningless. The harness was rebuilt to drive the suite from
the shell, and the control is now part of the run: **3 tests, 3 passed, exit 0** on the
unmutated enum. Without it, a harness that fails for any reason at all looks exactly like
a harness whose tests bite.

| Mutation | Result |
| --- | --- |
| drop an edge: `berlangsung` may no longer reach `selesai` | CAUGHT - `Failed asserting that 9 is identical to 10` |
| add a backwards edge: `selesai` may reopen to `berlangsung` | CAUGHT - 11 is not 10, plus the no-backwards test and the refused-transition-writes-nothing test |
| un-terminal a terminal state: `gagal` may resume | CAUGHT - 11 is not 10, plus the no-backwards test |

0 survived. The enum was restored and the restore was PROVEN, not assumed: the mutation
backup's SHA-256 is byte-identical to the restored file's, and the scratch backup was
removed from the repo root afterwards.

The two mutations that add an edge or remove a terminal are the two that a weaker
assertion would miss. Both were caught by three different tests each.

## Audits

**A.26 non-ASCII gate** over all 22 authored and modified files: this todo introduces
**zero** non-ASCII bytes. One pre-existing em dash (`E2 80 94`, U+2014) at
`tests/Unit/Console/VerifySchemaCommandTest.php:559` is reported to its owner and left
alone - it is present in `HEAD` and outside this todo's diff. The gate earned its keep
here: the first draft of the chat test carried a corrupted multi-byte sequence in a
fixture caption, and a later repair left the sentence mangled (`Ini fotoluka kaki kiri
sebelum perawatan.hers.`) before the third pass made it read properly. All three states
are recorded because the middle one passed a naive ASCII check on the *first* attempt and
would have shipped a nonsense assertion.

**DDL token and enum-bucket audit**: SHA-256 unchanged, 59604 bytes, 125 statements,
quotes paired on both kinds, no trailing comma before a closing paren, 64 ENUM buckets
and every value in every bucket is quote-paired. The consultation `status` bucket and the
`pengirim_tipe` bucket were extracted from the parsed file rather than retyped, and
`users.tipe` is reported at its real seven values rather than the five the plan assumed.

## Isolation and scope

The suite ran against a private database `sehatly_db_t32` via a per-run
`$env:DB_DATABASE` override. `phpunit.xml` was **not** modified: it is shared config, and
a permanent `DB_DATABASE` change there would hand this executor's private database to
every concurrent executor. Proof the override took effect rather than being assumed: the
database did not exist before the first run and now holds the migrated schema, which only
a migration run against it could have produced.

`web/`, `packages/` and `mobile/` were not touched; no mobile directory was created. No
migration, no seeder, no DDL edit, no `SlotAvailabilityService` change, no `StrBerlaku`
change. `.omo/plans/` is orchestrator-owned and its checkboxes were not marked.
`.omo/evidence/task-3-sehatly.md` and `.playwright-mcp/` are untracked, pre-existing, and
were never staged, edited or reverted. The commit used an explicit path list.
