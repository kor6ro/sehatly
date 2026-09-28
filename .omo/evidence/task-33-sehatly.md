# Task 33 - `RekamMedisService`: versioning, amendment chain, mandatory access logging

Evidence for plan todo 33. Written by the todo-33 executor. `.omo/plans/` is
orchestrator-owned and no checkbox was marked.

## 1. Scope

| | |
| --- | --- |
| New `app/` files | 11 - four services, one model concern, one controller, one resource, five FormRequests (one of which is the abstract base) |
| Modified `app/` files | 6 - `routes/api.php` and the five `rekam_medis*` models |
| New test files | 1 - `tests/Feature/RekamMedis/RekamMedisTest.php`, 49 tests |
| Modified test files | 3 - `PasienProfileTest`, `AuthFlowTest`, `KonsultasiTest` (closed sets only) |
| Modified docs | 1 - `docs/schema-notes.md` (a new "Known schema limitations" section) |
| Not touched | `database/migrations/`, `database/seeders/`, `telemedicine_test.sql`, `web/`, `packages/`, `mobile/` (absent), `phpunit.xml` |

`telemedicine_test.sql` SHA-256 is
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, unchanged, and
`git status --porcelain telemedicine_test.sql` is empty. `sehatly:verify-schema`
exits 0 with `PASS - 75 tables, 2 views verified`.

Version facts, read rather than inherited: `laravel/framework` is **v13.33.0** (out of
`vendor/laravel/framework`), PHP is **8.4.17** at
`C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe`. The `enum:` cast trap was
avoided by construction - no `enum:` cast is used anywhere in this todo, so the silent
no-op has no surface. The `CREATED_AT` trap was avoided too: the only trait authored,
`GuardsMedicalRecordRead`, declares **no** timestamp constant, and the two models that
need them (`RekamMedis`, `RekamMedisLampiran`) already declared their own.

## 2. DDL citations, read from the file

The plan's citations for this todo are **three right and one wrong**, and the wrong one
runs past the object it names. A test in `RekamMedisTest` asserts every line below byte
for byte, so the record is checked on every run rather than asserted in prose.

### `rekam_medis` - CREATE at 621, closing `ENGINE=InnoDB;` at 655

| line | content |
| --- | --- |
| 622 | `id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` |
| 623 | `uuid CHAR(36) NOT NULL UNIQUE` |
| 624 | `pasien_id BIGINT UNSIGNED NOT NULL` |
| 626 | `dokter_id BIGINT UNSIGNED NOT NULL` |
| 627 | `konsultasi_id BIGINT UNSIGNED NULL` |
| **629** | `tipe_kunjungan ENUM('telemedisin','rawat_jalan','rawat_inap','igd','home_visit') NOT NULL` |
| **630** | `tanggal_periksa DATETIME NOT NULL` |
| 631-636 | `keluhan_utama` through `hasil_pemeriksaan_fisik`, all `TEXT NULL` |
| 637-640 | `subjektif`, `objektif`, `asesmen`, `plan` (the SOAPS four) |
| 641 | `diagnosis_kerja VARCHAR(255) NULL` |
| 642 | `instruksi_tindak_lanjut TEXT NULL` |
| 643 | `status_tindak_lanjut ENUM('pulang_dengan_obat','kontrol','rujak','rawat_inap','ke_igd') NULL` |
| 644 | `jadwal_kontrol DATE NULL` |
| **645** | `status_dokumen ENUM('draft','final','diamendemen') NOT NULL DEFAULT 'final'` |
| **646** | `versi TINYINT UNSIGNED NOT NULL DEFAULT 1` |
| 647 | `ditandatangani_at DATETIME NULL` |
| 648-649 | `dibuat_at`, `diubah_at` (the second is `ON UPDATE CURRENT_TIMESTAMP`) |
| 650-653 | four foreign keys, to `pasien`, `faskes`, `dokter`, `konsultasi` |
| **654** | `INDEX idx_rm_pasien (pasien_id, tanggal_periksa)` - the ONLY declared index |

### The four child tables - 657-702, not 621-706

| table | span and load-bearing lines |
| --- | --- |
| `rekam_medis_diagnosa` | 657-667; `jenis` four-value ENUM at 662, `tipe_kasus` two-value at 663 |
| `rekam_medis_tindakan` | 669-679; `tanggal_tindakan DATETIME NOT NULL` at 675, `dokter_pelaksana_id` nullable at 676 |
| `rekam_medis_lampiran` | 681-690; `tipe` four-value ENUM at 686, `diunggah_oleh` at 687 |
| `rekam_medis_persetujuan` | 692-702; `tipe` three-value ENUM at 695, three NOT NULL-no-default columns at 696, 697, 700 |

All four declare `FOREIGN KEY (rekam_medis_id) ... ON DELETE CASCADE` (665, 677, 689,
701).

### `akses_rekam_medis_log` - CREATE at 1147, closing `ENGINE=InnoDB;` at 1155

| line | content |
| --- | --- |
| 1149 | `rekam_medis_id BIGINT UNSIGNED NOT NULL` |
| 1150 | `pengakses_user_id BIGINT UNSIGNED NOT NULL` |
| **1151** | `tujuan_akses ENUM('perawatan','klaim','audit','pasien_sendiri','kepentingan_hukum') NOT NULL` |
| 1152 | `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| 1153 | `FOREIGN KEY (rekam_medis_id) ... ON DELETE CASCADE` |
| 1154 | `FOREIGN KEY (pengakses_user_id) REFERENCES users(id)` - **no** `ON DELETE` clause |

### Plan citations: measured, not trusted

- **CORRECT**: `:645` (the `'final'` default), `:646` (`versi`), `:1147-1155` (the log
  table, both edges).
- **WRONG**: `:621-706` for "all 5 tables". The five tables span **621-702**; 703 is
  blank and **704** opens the `-- [9] RESEP & FARMASI` banner, so the citation runs four
  lines past the closing `ENGINE=InnoDB;` of the fifth table and into the next section.
  This is the same class of defect ledger entries 26 and 30 recorded.

## 3. Versioning and the chain, derived from the DDL

**The version lives in `rekam_medis.versi`** (`:646`), a `TINYINT UNSIGNED NOT NULL
DEFAULT 1`. The document state lives in `status_dokumen` (`:645`), a three-value ENUM
whose **default is `'final'`** - the trap the plan names, and the reason
`RekamMedisService::simpan()` writes `'draft'` and `1` explicitly rather than relying on
the column default.

**The chain is not linked, because there is nothing to link with.** The suite asserts
the ABSENCE of twelve candidate column names directly against the parsed DDL:
`parent_id`, `rekam_medis_id`, `id_rekam_medis`, `versi_induk`, `parent_uuid`,
`is_current`, `is_terkini`, `superseded_by`, `digantikan_oleh`, `dimodifikasi_at`,
`alasan_amandemen`, `diamendemen_oleh`. A test asserts the declared index set as a
closed set (`PRIMARY (id)`, `UNIQUE (uuid)`, `INDEX (pasien_id, tanggal_periksa)`) so a
future unique constraint over the chain group shows up rather than silently making the
service's own check redundant.

Therefore:

- **Chain key**: `(pasien_id, dokter_id, tanggal_periksa)`, ordered by `versi` then
  `id`. `tanggal_periksa` is a `DATETIME` (`:630`), not a DATE, so the key is a full
  timestamp.
- **"Current"**: the highest `versi` in the group. **This is a definition, not a fact
  the schema can state** - there is no currency flag and no supersession pointer.
  `RekamMedisResource::adalahTerkini()` derives it from the loaded chain and the
  response publishes it under the key `adalah_versi_terkini`.
- **Ceiling**: 255. The 256th amendment would be a MySQL 1264, so `amandemen()` refuses
  it with a 422 naming the limit, and the boundary is tested with 255 real rows.

**A deliberate deviation from the plan.** The plan says an amendment is
`old.versi + 1`. For a **superseded** row that number is already in the group, and two
rows at one version cannot be ordered - which would make "current = highest version"
ambiguous and would make the published chain's order depend on `id`. The service
therefore takes `MAX(versi) + 1` over the group, read `FOR UPDATE` so two concurrent
amendments cannot both read the same maximum. The cost is that an amendment's `versi`
is not always `parent.versi + 1`; the test `amending a superseded revision takes the
next free version, never a duplicate` amends the OLDEST row and asserts the group's
versions are exactly `[1, 2, 3]`.

## 4. What a read is, and the per-case log counts

**The rule, and it is structural:**

> Every operation that **opens** an existing `rekam_medis` row, or one of its four child
> tables, and **completes**, writes **exactly one** `akses_rekam_medis_log` row. An
> operation that opens nothing, or that rolls back, writes **zero**.

| case | rows | why | test that proves it |
| --- | --- | --- | --- |
| detail fetch, own record | 1 | one `baca()` | `reading one record as its own patient writes exactly one log row` |
| detail fetch, own record, with the chain | 1 | the chain loads inside the same permit | `a three version chain keeps all three rows and names the highest as current` |
| detail fetch, doctor who owns the record | 1 | | `the purpose is derived from the accessor and each class maps to one value`, 4 data sets |
| detail fetch, `admin` or `superadmin` | 1 | | same test, 4 data sets |
| detail fetch, ANOTHER patient's record | **0** | 404; the ownership probe hydrated nothing | `a refusal that opened no record writes no log row` |
| detail fetch, a doctor with no relationship | **0** | 404 | same test |
| detail fetch, `apoteker` / `perawat` / `kurir` | **0** | 403 about the caller | same test |
| detail fetch, an id that does not exist, `0`, `abc` | **0** | 404 from the router or the probe | `an id that was never there writes no log row` |
| the SAME request twice (a client retry) | **2** | two accesses | `the same request twice is two accesses and therefore two log rows` |
| a list of N records | **N** | N accesses, one row each | `a list of N records is N accesses and therefore N log rows` |
| eager-loading the four child tables | **1**, not 5 | five hydrations, one permit, one INSERT | `eager-loading the four child tables does not multiply the log rows` |
| `PUT` a draft | 1 | a write cannot edit what it cannot see | `a draft record can be edited in place` |
| `PUT` a final record (422) | **0** | the log and the edit share one transaction; the 422 rolls both back | `editing a final record is 422 and leaves the row byte identical` |
| `PUT /final` a draft | 1 | | `a finalisation stamps final and ditandatangani_at exactly once` |
| `PUT /final` an already-final record (422) | **0** | same atomicity | same test |
| `POST /amandemen` a final record | 1 | the PARENT is opened; the new row is created | `an amendment supersedes rather than mutates, and the chain is not truncated` |
| `POST /amandemen` a draft (422) | **0** | same atomicity | `amending a draft is 422 and points the caller at the in-place path` |
| `POST` create | **0** | nothing pre-existing is opened, and the log's five ENUM values are all read purposes | `a created record is a draft at version 1, never the DDL default final` |
| a sub-entity added to a draft | 1 | the parent is opened to apply the gate | `a sub entity is added to a draft and refused on a final record` |
| a sub-entity added to a final record (422) | **0** | same atomicity | same test |
| a read inside a transaction that is rolled back | **0** | the log is IN the read's transaction | `a read whose transaction is rolled back leaves no log row` |
| an unknown body key on `PUT` (422) | **0** | the request refuses it before the service runs | `an unknown column name is refused rather than silently ignored` |
| `pasien_id` or `dokter_id` in a create body (422) | **0** | both are `prohibited` | `the created patient and doctor are read off the consultation, not the body` |

**Three cases legitimately write MORE than one, and one of them is a retry.** A client
that retries a read has performed a second access, and an access log that collapses a
retry into one row cannot answer "was this looked at twice?". Deduplication is not
available either: `dibuat_at` is a `TIMESTAMP` (`:1152`) at one second of resolution, so
the two rows are not even distinguishable by time - only by the auto-increment `id`. A
record that is the parent of N amendments carries N rows, which is the same property
from the other side: the three-version chain test asserts `v1 = 1, v2 = 1, v3 = 1` for
three completed operations on three records, and `reading a superseded revision names
that revision in the log` asserts `v1 = 2` after one amendment plus one direct read of
`v1`.

**A refused write writes ZERO, and that is a decision with a cost.** The log row and
the operation share one transaction, so a 422 rolls both back. A log row for an edit
that did not happen would be a false record, and a false record in an audit table is
worse than a missing one. The obvious objection - that a 422 discloses
`status_dokumen` - does not apply on these routes: every refusal is reachable only by
the record's OWN doctor (`RekamMedisAccess::untukDokter()`), and a doctor who wrote the
record already knows whether it is a draft. The disclosure that matters is a READ, and
a read either completes and logs or never returns.

## 5. How there is no unguarded read path

**The control is a model event, not a grep.**
`App\Models\Concerns\GuardsMedicalRecordRead` registers a `retrieved` listener on
`RekamMedis` and its four children. Eloquent fires `retrieved` inside
`Model::newFromBuilder()`, which is the ONE place every hydrated model comes from - so
`find`, `findOrFail`, `first`, `firstOrFail`, `chunk`, `cursor`, `lazy`, `lazyById`, a
lazy relation load and a `with()` eager load all pass through it. Hydrating one of those
five models with no `RekamMedisReadScope` open throws a `RuntimeException` naming the
class that must be used instead.

**The only thing that opens the scope is the log writer.**
`RekamMedisReadScope::dalam()` has exactly one caller in the application:
`RekamMedisAccessLogger::baca()`, and its sibling `untukTulis()` for the write paths.
Both have already INSERTed the `akses_rekam_medis_log` row **in the same transaction**.
The order inside is fixed:

1. `RekamMedisAccess::probe()` - a `DB::table()` read of FOUR columns (`id`,
   `pasien_id`, `dokter_id`, `tanggal_periksa`) that **hydrates nothing** and answers
   403 or 404. This is why a refusal writes zero rows.
2. the `akses_rekam_medis_log` INSERT.
3. the scope opens and the record is hydrated.

If step 3 fails the transaction rolls back and the log row goes with it. If step 2 fails
the record is never hydrated. There is no interleaving in which a record is read without
a committed log row naming it.

**A second, independent wall: no caller can hand the service a loaded row.** Every
public method of `RekamMedisService` takes a `User`, never a `RekamMedis`. A caller
cannot supply a record it opened itself, and it cannot decide for itself who is allowed
- authorisation and logging are what each method does first, not steps it may forget.

**A third wall: the probe cannot be used to smuggle content.** The probe selects four
named columns and the suite asserts the ownership triple, so it cannot be widened into a
way of reading clinical content without logging.

**The grep is still there, as a backstop.** The test `no code outside
app/Services/RekamMedis reaches the rekam_medis tables` greps `app/` for eight specific
spellings (`RekamMedis::find(`, `::findOrFail(`, `::query(`, `::hydrate(` and the same
`::query(` on the four children) and fails on any hit outside
`app/Services/RekamMedis/`. It is a smell detector, not the lock - which is exactly why
both are asserted, and the difference is MEASURED rather than argued: mutation M1 below
removes the model event and the grep still passes while two tests go red.

**The one hole this does NOT close, stated rather than hidden.** A query that does not
hydrate - `RekamMedis::query()->pluck('subjektif')`, `->value('plan')`, `->exists()` -
fires no `retrieved` event and would return clinical content unlogged. No model-level
guard can close that; closing it would mean intercepting every query at the connection
layer, which is a global change to the whole application and not this todo's. The
service itself never does it, and the ownership probe is deliberately a four-column
`DB::table()` projection rather than such a call. This is a residual, and it is recorded
here rather than left for a later reader to find.

**`RekamMedisReadScope` state is static, saved and restored.** The gate is cleared in a
`finally` that restores the PREVIOUS permit, so a callback that throws cannot leave the
gate open. A nested `dalam()` puts the outer permit back rather than dropping it. The
suite asserts `sedangBerjalan()` is false after a throwing read AND that a read
attempted after it is still refused - the second assertion is the one that would catch a
leak. The limitation is a process that keeps serving requests without rebinding
(Octane, or a queue worker draining jobs between requests); neither is configured here
(no `octane` dependency, `QUEUE_CONNECTION=sync`), and it is recorded rather than claimed
to be impossible.

## 6. The five routes, and the guards

`route:list --path=api/v1` shows **40** routes, up from 35. The guard map was generated
from the live table with `gatherMiddleware()` - `artisan route:list --json` reports an
EMPTY `middleware` array on 13.33, so the map cannot come from it.

| method | uri | `permission:` | `tipe:` |
| --- | --- | --- | --- |
| POST | `api/v1/konsultasi/{id}/rekam-medis` | `rekam_medis.simpan` | `dokter` |
| GET | `api/v1/rekam-medis/{id}` | - | - |
| PUT | `api/v1/rekam-medis/{id}` | `rekam_medis.simpan` | `dokter` |
| PUT | `api/v1/rekam-medis/{id}/final` | `rekam_medis.final` | `dokter` |
| POST | `api/v1/rekam-medis/{id}/amandemen` | `rekam_medis.final` | `dokter` |

**The read carries no `permission:` and no `tipe:`, and that is a decision.**
`rekam_medis.lihat` IS a real code, but `RbacCatalog::ROLE_PERMISSIONS` grants it to
`pasien`, `dokter` and `superadmin` and **not** to `admin` - so the one code the
catalogue has for this action would 403 the `admin` the plan names as the `audit`
reader. The plan's read audience is a DISJUNCTION ("the patient themselves OR their
doctor OR an oversight account") and a route gate can only express a conjunction. This is
the same argument `KonsultasiController` makes for its own read, and the disjunction
lives in `RekamMedisAccess::sisiUntukBaca()`. The obligation that route DOES carry - one
log row per read - is enforced by the model event, which is a chokepoint rather than a
convention.

**An amendment is gated on `rekam_medis.final`, not `rekam_medis.simpan`,** because an
amendment carries the same clinical authority as the signed record it supersedes. Both
are held by the same two roles, so the choice is semantic rather than behavioural.

**`perawat` and `kurir` are real `users.tipe` values (`:139`) that hold NO role** in
`RbacCatalog::ROLES`, so any `permission:` locks them out of all four writes
permanently. That is a real gap and the DDL makes it sharper rather than softer:
`pasien_tanda_vital.sumber` is `ENUM('mandiri','dokter','perawat','iot_device')` at
`:325`, so this schema gives a NURSE a clinical role of her own while the RBAC
vocabulary gives her no grant that could let her write a medical record. The fix is a
data change in `app/Support/Rbac/` plus a re-seed, and `RbacCatalog`'s own docblock
reserves that. On the read they are refused with a 403 from `RekamMedisAccess` - a fact
about rows they do not own rather than a role they lack. Reported, not worked around.

**`tujuan_akses` is DERIVED, never supplied by the wire.** The plan's
`log(int $rekamMedisId, User $accessor, string $tujuan)` keeps its name and shape, but
the only value that ever reaches it comes from which side of the record the accessor is:
the patient side maps to `pasien_sendiri`, the doctor side to `perawatan`, and the
oversight side to `audit`. A caller-chosen purpose is the one input an attacker would
choose, and `perawatan` is what an auditor reads as legitimate care. The four-entry
data-set test pins the mapping for all three reachable values.

**404 for the row, 403 for the caller.** Another patient's record and an unrelated
doctor's record are **404** with the router's own byte-identical body
(`{"success":false,"message":"Resource not found.","errors":{}}`), so a caller cannot
tell "no such record" from "not yours". An account that owns no `pasien` and no
`dokter` row - `apoteker`, `perawat`, `kurir` - is **403**, because the refusal is about
the caller. Both are asserted, and the 404 body is compared as a raw string.

**The controller is thin and every rule is in the service.** Five methods, each: take
the validated payload, call `RekamMedisService` with the authenticated account, wrap the
returned row in a Resource, call `ApiResponse::success()`. There is no ownership `if`,
no draft/final `if`, and no call to the access logger - because each would be a step a
future edit could forget.

## 7. Envelope

`ApiResponse::success($data, $message, $status, $meta)`, so `success` then `data` then
`message`, and `meta` a top-level sibling. **`meta` is ABSENT on all five routes** rather
than null: none of them is a list, and `ApiResponse` omits the key entirely when
`$meta === null` so that "not paginated" is unambiguous. A test asserts the key is
absent and that the other three are present.

Failures are `{success:false, message, errors:{field:[messages]}}`. **A 422 carries
multiple messages for one field and both are preserved**: an empty `perubahan` on a
draft violates two independent rules about the change set, and the test asserts
`errors.perubahan` has exactly two entries with the right text in the right order plus a
separate `errors.status_dokumen` - one 422 carrying three facts about one request. The
mechanism is `ValidationException::withMessages()`, which appends for a repeated key;
the service collects every violation before throwing any of them, so neither check hides
the other.

## 8. TDD: the red-then-green transcript

**RED** - `php artisan test tests/Feature/RekamMedis/RekamMedisTest.php`, the shipped
schema, no implementation:

```
result=failed tests=49 passed=10 failed=35 errors=4 assertions=97 duration_ms=31499
```

Every failure was for the right reason, and the reasons are worth listing because a red
run full of 404s would have been equally useless:

- the route table was empty - the closed-set test reported `GET api/v1/rekam-medis/{id}`
  and the other four as **missing**, not as failing
- `Exception "RuntimeException" not thrown` - the structural guard did not exist
- `Failed asserting that 'Class "App\Services\RekamMedis\RekamMedisReadScope" not
  found' contains "tidak sampai"`
- `Target class [App\Services\RekamMedis\RekamMedisService] does not exist` (4 errors)
- 28 further failures were router 404s, i.e. the routes were not registered

Two of the four errors were defects in the TEST, found by the red run and fixed before
any implementation: `Cannot use object of type App\Support\Schema\IndexSpec as array`
(the closed set needed `semanticKey()`, not the raw name) and a `use RuntimeException;`
that PHP warned was a no-effect import. Both are recorded because a red run that
contains its own test defects is not yet a trustworthy red.

**GREEN** - the same command after implementation:

```
result=passed tests=49 passed=49 failed=0 errors=0 assertions=348
```

**Five defects found while hunting green, all real and all mine:**

1. A `ParseError` from an orphaned code block left by an `edit` whose `oldString`
   matched only part of a method - caught by `php -l` and by the fact that the whole
   class 500'd, not by any test.
2. The `RekamMedis` index closed set omitted `UNIQUE (uuid)`. The DDL declares it and
   the assertion was wrong, not the code.
3. `pasien_id` and `dokter_id` in a create body returned 201 rather than 422 - my
   fixture asserted the wrong thing, and the right answer is `prohibited`, which is
   stronger than "ignored".
4. `validated('perubahan')` handed the service an EMPTY array for a change set naming a
   non-existent column, so the service answered "your change set is empty" about a
   request that plainly carried something. Fixed by
   `AmandemenRekamMedisRequest::perubahan()` returning the raw input and letting the
   service - which owns the one allow-list - do the check.
5. The evidence file itself: the write path mangled two spots and a later byte-level
   PowerShell splice truncated it to 110 bytes. Rewritten and re-verified; recorded here
   because an evidence file that claims a verified audit and has not been read back is
   the same failure class as a hand-typed literal.

## 9. Mutations: 4 of 4 caught, control GREEN

The harness runs the **control first** and refuses to read any mutation result if the
control is not green, because four previous ledger entries record an executor reporting
"3 of 3 caught" while its own control was also failing.

```
=== CONTROL (unmutated) ===
tests=49 passed=49 failed=0 errors=0 assertions=348 result=passed
CONTROL IS GREEN. Mutation results are meaningful.

=== MUTATION RESULTS ===
CAUGHT      M1 remove the `retrieved` guard from the five guarded models
            restore-verified-by-sha256=True
            tests=49 failed=2 errors=0
              no_rekam__medis_row_can_be_hydrated_outside_a_logged_read
              the_scope_is_cleared_even_when_the_read_throws__so_a_later_read_is_still_refused
CAUGHT      M2 make the logged read skip the akses_rekam_medis_log INSERT
            restore-verified-by-sha256=True
            tests=49 failed=10 errors=0
              reading_one_record_as_its_own_patient_writes_exactly_one_log_row
              the_purpose_is_derived_from_the_accessor_and_each_class_maps_to_one_value (4 data sets)
              the_same_request_twice_is_two_accesses_and_therefore_two_log_rows
              a_list_of_N_records_is_N_accesses_and_therefore_N_log_rows
              eager-loading_the_four_child_tables_does_not_multiply_the_log_rows
              (and 5 more)
CAUGHT      M3 amend IN PLACE instead of superseding with a new row
            restore-verified-by-sha256=True
            tests=49 failed=5 errors=0
              an_amendment_supersedes_rather_than_mutates__and_the_chain_is_not_truncated
              a_three_version_chain_keeps_all_three_rows__and_names_the_highest_as_current
              reading_a_superseded_revision_names_that_revision_in_the_log
              amending_a_superseded_revision_takes_the_next_free_version__never_a_duplicate
              two_visits_on_different_dates_are_two_independent_chains
CAUGHT      M4 answer 403 where the record belongs to another doctor (was 404)
            restore-verified-by-sha256=True
            tests=49 failed=1 errors=1
              another_doctors_record_is_never_reached_by_an_amendment

survived=0 of 4 applied
```

**M1 is the important one.** Removing the model event leaves the grep test green - the
grep still finds no stray `RekamMedis::find(` because there is none - and two tests go
red. That is the measurement separating the structural control from the conventional one,
and it is why both are asserted.

**A harness defect worth recording.** The first control run was judged RED by the
harness on a **green** suite: the pest JSON reporter **omits** `failed` and `errors`
entirely when they are zero, so the value was null and a null is not zero under a
not-equal-zero test. A harness that calls a green run red is the quietest version of
"the harness fails for an unrelated reason", and it would have produced a meaningless
"0 of 0 mutations" report had the guard not compared with not-greater-than instead. Every
count is now coerced to `int`.

**Every restore was verified by SHA-256 against the pre-mutation hash, not assumed**, and
the scratch backup directory was removed from the temp path afterwards. A final search
for the mutation markers across the mutated files returns only a docblock sentence, and
`git status` shows no mutation residue.

## 10. Three closed sets, all of which caught me

| file | set | what it caught |
| --- | --- | --- |
| `tests/Feature/Pasien/PasienProfileTest.php` | every `api/v1` route, its guard map, and the `permission:` and `tipe:` census over `routes/api.php` | the five new routes and the eight new guard strings |
| `tests/Feature/Auth/AuthFlowTest.php` | the same census over the same regex | the same eight strings, in a second file |
| `tests/Feature/KonsultasiTest.php` | every `api/v1/konsultasi` route and its guard map | the new create, which this todo's route must be because its path IS a consultation path |

All three lists were **generated** from `Route::getRoutes()` and the live
`routes/api.php` and then pasted, never typed - and the root cause of every failure in
the previous three batches was a hand-typed literal silently becoming a different
string. Two more of that exact class occurred while writing this todo and were caught
by reading the block back before running anything:

- `permission:konsultedchat` in the `PasienProfileTest` census, where the second `a`
  had become an `e`;
- `permission:konsulta.chat` in the `KonsultasiTest` guard map, where the `i` had
  vanished.

The route census is now **19** entries and `tipe:dokter` appears **7** times. The
`KonsultasiTest` 401 matrix also gained the new route, and its name -
`an anonymous caller is refused 401 on all seven routes` - is now wrong for a list of
eight. Left as-is deliberately: renaming another todo's test is a change to its assertion
text, and the block's own comment says why each entry is there.

`RekamMedisTest` asserts the same five routes as its own closed set **by URI** rather
than by prefix, because a filter on `rekam-medis` alone answers **4** and reads like a
missing route.

## 11. Non-ASCII gate and DDL token audit

A repository-root script, run once and then removed, gated all 22 authored and modified
files. It works on **raw bytes** from `file_get_contents()` and never round-trips
content through a decoding step - a previous executor re-encoded bytes through a .NET
string before checking for a BOM, and that operation **drops the BOM**, so the check
reported "no BOM" on a commit that had one and the commit had to be amended. The BOM
test here is a raw byte prefix test and nothing else.

```
=== 1. BYTE-LEVEL NON-ASCII GATE (22 files) ===
files scanned   : 22
non-ASCII bytes : 0
allowed-set uses: 0
BOM files       : 0
violations      : 0
```

**This todo introduces ZERO non-ASCII bytes, so the allowed set was never consulted** -
reported as a byte count precisely so that "the allowed set saved us" and "there was
nothing to save" cannot be confused. It earned its keep three times while writing: a
full-width comma, two CJK characters in a fixture caption, and a mistyped class name
with CJK characters spliced into it - all caught before they reached a run.

`docs/schema-notes.md` already contains 761 non-ASCII bytes in **pre-existing** content.
The section this todo appends was measured separately by diffing against `HEAD`:
**6279 bytes, 0 non-ASCII**. The pre-existing bytes are reported to their owner and left
alone.

Token audit against the DDL, through the project's own `SqlSchemaParser`:

```
schema: 75 tables + 2 views, 672 columns, 279 index names/columns, 106 foreign keys, 243 enum values
distinct snake_case tokens in app/ + tests/ : 209
byte violations     : 0
unresolved tokens   : 0
```

The ENUM bucket was read out of the parsed file and printed rather than retyped, and
every value this todo writes was resolved against its bucket:

```
rekam_medis.status_dokumen           enum('draft','final','diamendemen')                                    3
rekam_medis.tipe_kunjungan           enum('telemedisin','rawat_jalan','rawat_inap','igd','home_visit')      4
akses_rekam_medis_log.tujuan_akses   enum('perawatan','klaim','audit','pasien_sendiri','kepentingan_hukum')  5
rekam_medis_diagnosa.jenis           enum('utama','sekunder','diferensial','komplikasi')                    4
rekam_medis_persetujuan.tipe         enum('general_consent','persetujuan_tindakan','penolakan_tindakan')    3
users.tipe                           enum('pasien','dokter','perawat','apoteker','kurir','admin','superadmin') 7

OK  perawatan       akses_rekam_medis_log.tujuan_akses
OK  audit          akses_rekam_medis_log.tujuan_akses
OK  pasien_sendiri  akses_rekam_medis_log.tujuan_akses
OK  draft / final / diamendemen    rekam_medis.status_dokumen
OK  telemedisin    rekam_medis.tipe_kunjungan
OK  baru           rekam_medis_diagnosa.tipe_kasus
OK  utama / sekunder   rekam_medis_diagnosa.jenis
```

Only `utama` and `sekunder` are written by this todo's own fixtures; `diferensial` and
`komplikasi` are reachable through the request's `Rule::in()`, which is built from the
same parsed bucket rather than retyped.

The twelve asserted-absent column names are re-verified as absent from the parsed
`rekam_medis`, so the finding cannot be an artefact of a list that drifted.

`pint --test`, scoped to the 22 files this todo owns and **never repo-wide**: exit 0. It
fixed 8 files on the first pass (`braces_position`, `single_line_empty_body`,
`unary_operator_spaces`, `not_operator_with_successor_space`, `phpdoc_align`,
`phpdoc_separation`, `line_ending` on the four child models,
`fully_qualified_strict_types` and `ordered_imports` on the resource), and the suite was
re-run after the fix: 49/49, 348 assertions.

## 12. Suite, isolation, and the delta

Private database **`telemedisin_db_t33`**, created with
`php artisan db:create-test-database` (a `DB_TEST_DATABASE` override) and used through a
per-run `$env:DB_DATABASE`. **`phpunit.xml` was NOT modified** - it is shared config and
a permanent `DB_DATABASE` change there would hand this executor's database to every
concurrent executor.

**Proof the override took, rather than being assumed:** the database did not exist before
the first run, and a direct `information_schema.tables` count read **0** for
`telemedisin_db_t33` against **84** for the shared `telemedisin_db_test` at the moment of
creation. Only a migration run against it could have produced the schema it now holds.
The shared database was deliberately not used.

```
php artisan test
result=passed tests=603 passed=603 failed=0 errors=0 assertions=10368 duration_ms=257323
```

| | baseline (todo 32) | after todo 33 | delta |
| --- | --- | --- | --- |
| tests | 554 | **603** | **+49** |
| passed | 554 | **603** | +49 |
| failed | 0 | **0** | 0 |
| assertions | 9983 | **10368** | **+385** |
| routes under `api/v1` | 35 | **40** | +5 |

Zero regressions. `RekamMedisTest` alone is 49 tests and 348 assertions. The three
closed-set files together are 205 tests and 1950 assertions. **No test is skipped
anywhere**: the only two hits for `markTestSkipped` or a skip call in `tests/` are prose
inside `tests/TestCase.php`'s docblock explaining that the skip helper was **removed**,
and this todo authored no skip of any kind.

`route:list --path=api/v1` exits 0 and shows 40. `sehatly:verify-schema` exits 0,
`PASS - 75 tables, 2 views verified`, 0 drift, 7 informational. `git status` on
`telemedicine_test.sql`, `database/`, `web/` and `packages/` is empty, and `mobile/` is
absent.

## 13. Plan errors and impossible criteria

| # | the plan says | reality | what was done |
| --- | --- | --- | --- |
| **P1** | `telemedicine_test.sql:621-706` for "all 5 tables" | the five tables span **621-702**; 704 opens the `[9] RESEP & FARMASI` banner | cited the real spans; a test asserts both the real `ENGINE` lines and the plan's three correct citations |
| **P2** | "an architecture test that greps `app/` for any direct `RekamMedis::find(` or `::findOrFail(` outside that service" | a grep is a **convention**: it catches one spelling, cannot see a relation traversal or a `chunk()`, and a future author who does not know about it defeats it | implemented the model event FIRST, kept the grep as a backstop, and **measured the difference**: mutation M1 removes the event and the grep still passes while two tests go red |
| **P3** | `route:list --path=api/v1/rekam-medis` lists **5** routes | it lists **4**. The plan's own five include `POST /api/v1/konsultasi/{id}/rekam-medis`, whose path cannot match a `rekam-medis` filter | all five ship; the criterion is satisfied by counting the five by URI, and the test asserts the closed set that way. The literal command answers 4 and that is reported, not hidden |
| **P4** | an amendment is `old.versi + 1` | for a **superseded** row that number is already in the group; two rows at one version cannot be ordered, so "current = highest version" becomes ambiguous | the service takes `MAX(versi) + 1` over the group, read `FOR UPDATE`. Deviation asserted by a test that amends the oldest row |
| **P5** | `findForAccess(int $id, User $user, string $tujuan)` and `log(..., string $tujuan)` | a caller-supplied purpose is the single most dangerous input on this surface: `perawatan` is what an auditor reads as legitimate care | the parameter is **removed** from `findForAccess` and the purpose is **derived** from which side of the record the accessor is; `log()` keeps the plan's name and shape so only a derived value ever reaches it |
| **P6** | map an `admin` to the `audit` purpose | `admin` holds **no** `rekam_medis.lihat` grant (`RbacCatalog::ROLE_PERMISSIONS`), so a `permission:rekam_medis.lihat` gate 403s every `admin` before the mapping can apply | the read route carries **no** `permission:` and the disjunction is in `RekamMedisAccess::sisiUntukBaca()`. Both `admin` and `superadmin` reach `audit`; the test has a data set for each |
| **P7** | the log "must be called from every single read path" | a two-step API is bypassable by construction - a caller can log and not read, or read and forget to log, and nothing objects | the read and the log are **one operation in one transaction**, and a model event makes the un-hydrated read impossible. The only opener of the gate is the log writer |
| **P8** | "the chain is reconstructed by grouping on `(pasien_id, dokter_id, tanggal_periksa)`" | correct, but it is a **reconstruction**, and the plan does not say what makes a row current | "current = highest `versi`" is stated as a **definition**, the twelve absent linkage column names are asserted, and `docs/schema-notes.md` records the whole thing |
| **P9** | sub-entities "written through the same service" | no endpoint is named for any of the four, and the plan's own count is 5 routes | four `tambah*` service methods, no routes. The count is 5 as the plan requires |
| **P10** | QA scenario: a `GET` as an unrelated doctor gives 404 **and zero access-log rows** | **correct as written**, and the implementation had to be arranged to make it true | the ownership probe is a four-column `DB::table()` projection that hydrates nothing. A model probe would have hydrated a row, tripped the guard, and made the scenario impossible |
| **P11** | (not stated) | `tujuan_akses` has **five** values and only **three** are reachable. `klaim` and `kepentingan_hukum` have no producer because `users.tipe` (`:139`) has no claims officer and no legal officer | reported; no eighth `tipe` invented and no endpoint added for them |
| **P12** | (not stated) | `rekam_medis` declares **no** unique constraint over the chain group, so two rows may share a `versi` | the service's `FOR UPDATE` current read is the only defence, and the closed-set index assertion would catch a future constraint |
| **P13** | (not stated) | `versi` is a TINYINT UNSIGNED, so the 256th amendment is a MySQL 1264 - rendered as a sanitised 500 by `bootstrap/app.php` | refused with a 422 naming the limit, and the boundary is tested with 255 real rows |
| **P14** | (not stated) | `rekam_medis_lampiran.diunggah_oleh` (`:687`) is NOT NULL with **no foreign key** (`:689` declares only the `rekam_medis` one) | written from the authenticated account; the absence is asserted so the choice is visible |
| **P15** | (not stated) | `pasien_tanda_vital.sumber` includes `perawat` (`:325`), so the DDL gives a nurse a clinical role while `RbacCatalog` gives her no grant at all | reported as a data change in `app/Support/Rbac/` plus a re-seed, not a code change |
| **P16** | (not stated) | `akses_rekam_medis_log.rekam_medis_id` is `ON DELETE CASCADE` (`:1153`), so deleting a record erases the evidence of every access to it | reported; `audit_log` (`:1118`) is the append-only surface, and this plan does not ask for writes there |
| **P17** | (not stated) | reading a medical record requires **no PDP consent** in this schema. `persetujuan_pdp` (`:1134`) exists and todo 34 gates `surat_rujukan` on it, but no route here consults it - correctly, since reading your own record is not cross-faskes sharing | reported; the two "consent" concepts are distinguished in `tambahPersetujuan()`'s docblock because they are easy to confuse |

## 14. Isolation

- **Private database** `telemedisin_db_t33` via a per-run `$env:DB_DATABASE`;
  `phpunit.xml` untouched.
- **`.omo/evidence/task-3-sehatly.md` and `.playwright-mcp/`** are untracked and
  pre-existing. Never staged, edited, reverted or named in the commit pathspec.
- **No `web/`, `packages/` or `mobile/`** path was touched, and no Flutter project was
  created.
- **No `database/migrations/`, no `database/seeders/`, no `telemedicine_test.sql` edit.**
  The SQL SHA-256 is byte-identical to HEAD.
- `migrate:rollback` was never run. No `php artisan serve` was started.
- Three scratch artefacts this todo created were removed: the `rhdump.php` route
  dumper and the `a26-audit.php` audit script, both in the repository root, plus the
  mutation harness and its backups, which live in the temp path.
- **One stray directory was created outside the workspace and removed.** A `write` call
  with a mistyped absolute path created a one-file tree beside the repository. It was
  moved into place and the tree deleted; the path no longer exists.
- The commit uses an **explicit pathspec** - `git commit -- <paths>` commits
  working-tree state and silently drops staged deletions, which is not a risk worth
  carrying here.
- **`.omo/plans/` was not touched.** No checkbox was marked.
