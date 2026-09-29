# Todo 47 - Sehatly Evidence

Todo 47. Implement PDP consent endpoints and the notification centre.
Plan line 630. Left UNCHECKED: the checkbox is orchestrator-owned and `.omo/plans/` was not touched.

Commit `060d063 feat(api): add PDP consent versioning and notification centre`.

## 1. The version rule, and what "highest" means operationally

`telemedicine_test.sql:1134`-`:1145` gives `persetsu[j]uan_pdp` three columns that decide everything:

```
:1139  versi_dokumen VARCHAR(20) NOT NULL,
:1140  disetujui      TINYINT(1) NOT NULL,
:1144  UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)
```

The unique key spans THREE columns and `disetujui` is `NOT NULL` with no nullable twin, so **a decision about a given document version is immutable**. A second row for the same `(user_id, jenis, versi_dokumen)` collides with `uq_consent`, and editing `disetujui` in place would be a rewrite of the record of what the person agreed to.

The only representable revocation is therefore a NEW `versi_dokumen` carrying `disetujui = 0`. The effective consent is read off the row with the MAXIMUM `versi_dokumen`, and its own `disetujui` is honoured.

**Answering the plan's question directly: is a revocation at version N undone by a consent at N-1?** No, and the answer holds on two independent grounds:

1. A `versi_dokumen` below the maximum is REFUSED at the door (422), so N-1 can never be written after N exists.
2. Even if it were written, the read side takes the maximum, so a resurrected N-1 row is invisible. A client would be told "withdrawn" while its re-consent never lands. That is the defect the write-side guard exists to prevent.

**"Highest" is a STRING order, and that is stated rather than hidden.** `versi_dokumen` is `VARCHAR(20)`, and the database is created `COLLATE utf8mb4_unicode_ci` at `telemedicine_test.sql:11`-`:13` with no per-table override, so the order is the column's own collation order. There is no numeric column to cast, so `'v10' < 'v9'` is TRUE (`'1'` is 0x31, `'9'` is 0x39, at the second position). The mitigation is a fixed-width / zero-padded writer convention, documented in `PdpConsent`'s class docblock, and this code does not paper over it.

**Every comparison is done in SQL, and that is load-bearing.** Because the collation is case- and accent-insensitive, `'v1.0'` and `'V1.0'` are the same value to `uq_consent` but different strings to PHP's `strcmp()`. A PHP pre-check would DISAGREE with the unique key, and a client holding `V1.0` against a stored `v1.0` would pass a `strcmp` check and then be killed by a raw 1062. So equality is `where('versi_dokumen', $versi)` and ordering is `where('versi_dokumen', '>', $versi)`, both evaluated by MySQL in the same collation the constraint uses. `'V1.0'` is consequently a COLLISION, which is exactly what the DDL would do to the INSERT.

The three-state answer is `true` / `false` / `null`, and `null` (no row ever recorded) is deliberately distinct from `false` (a row says no). A consent checklist whose "never asked" and "asked and declined" boxes both render as "no" cannot show a person what is on record about them.

## 2. Boundary outcomes, all three proven

| # | boundary | outcome |
| --- | --- | --- |
| 1 | higher version after lower | 201, supersedes immediately, effective answer moves at once |
| 2 | lower version after higher | 422, nothing written, effective answer does not move |
| 3 | same version twice, same answer | 200, idempotent, existing row returned UNCHANGED, no second `audit_log` row |

Boundary 1 alone does not distinguish a correct implementation from one that just takes the newest row by insertion order, which is why boundary 2 exists: there the lower version arrives LAST, so `id` order and version order disagree. See the mutation harness in section 6, mutation 1.

## 3. The revoked-same-version collision - the acceptance criterion

A row arriving at the same `versi_dokumen` with a different `disetujui` is refused:

1. **422 with TWO messages on the single field `versi_dokumen`**, order preserved:
   - `Persetujuan untuk versi dokumen ini sudah tercatat dan tidak dapat diubah.`
   - `Tarik persetujuan dengan mengirim versi_dokumen yang lebih tinggi.`
   The response is the standard `{success, message, errors}` envelope, and a concatenated single string could not assert per position.
2. **Nothing is written.** No UPDATE, no second row. The test compares the surviving row's `id`, `disetujui_at` and `ip_address` byte-for-byte before and after.
3. **The effective answer does not move.** Row count and effective value are re-asserted after the refusal, so an upsert that silently overwrote would fail.
4. **No index and no column was added** to make the collision representable. The DDL is read-only law. The collision is handled as a documented outcome.
5. **The client is given the way forward**: send a higher `versi_dokumen`. That is the only representable revocation, and the message says so.

**The plan's "upserting against `uq_consent`" was NOT implemented, and this is a deliberate refusal.** An upsert here would `UPDATE` the existing row's `disetujui`, `disetujui_at` and `ip_address` - the collision performed silently - destroying the record of the original agreement while answering 201. Mutation 3 in section 6 is that upsert, run to prove the suite detects it.

**The unique violation is caught anyway.** The pre-check is application logic and can be raced by a second writer between its SELECT and its INSERT. `uq_consent` fires regardless, the exception is caught, and it is mapped to the SAME refusal, because a race is a normal outcome of a concurrent write and answering it 500 would mean the collision is handled only when nobody else is looking.

## 4. Notification centre

`notifikasi` is read-only from a client's perspective:

- `GET /api/v1/notifikasi` - paged list, optional unread filter, `meta.unread` is the account-wide unread total, independent of page and filter
- `PUT /api/v1/notifikasi/{id}/baca` - idempotent mark-one-read
- `PUT /api/v1/notifikasi/baca-semua` - bulk, stamps only the caller's unread rows, idempotent, reports the count

`GET` answers 404 for another account's notification id rather than 403, because a 403 would confirm the id exists. That is the same non-disclosure rule the medical-record surface uses, and the test asserts the id is not disclosed.

The client cannot write any column. The table is `id, user_id, judul, isi, tipe, tautan, payload, dibaca_at, dibuat_at` (`:1037`-`:1045`) and a test asserts a TOTAL classification of those nine into "the six the service assigns", "the one the read stamps assign", and "the two the database assigns", so a column added later fails until this todo decides what it means.

`dibaca_at` is the only client-writable field and it is client-writable only through those two stamp actions. Both go through the MODEL (`$baris->save()`), never a builder `update()`, because a builder write fires no Eloquent event and would leave the only write in this table with no `audit_log` row. The test strips comments with the tokenizer before scanning for `->update(`, because the controller's own docblock names `->update([...])` while explaining why it is avoided - a raw substring scan would fail on the explanation and "fixing" it would mean deleting the reasoning.

**Delivery state is not representable, and that is proved rather than assumed.** `notifikasi` has no `dikirim_at`, no `status_kirim`, no `channel` and no attempt counter, asserted against the parsed column list. "queued / sent / failed" therefore cannot be stored, and push delivery is logged. See section 9.

## 5. Guards, and why each one is what it is

- **PDP consent routes: `auth:sanctum` and nothing else.** A consent record is the CALLER's own, so the audience is exactly "a caller who holds a token" and every account type may read and answer for itself. A `permission:` would add a grantable role to something that is not role-scoped, and `tipe:pasien` would refuse a `dokter` filling a form in on a patient's behalf. The ownership half - nobody else's record - is answered by the controller's own `user_id` scoping.
- **Notification routes: `auth:sanctum` + `permission:notifikasi.lihat`.** `notifikasi.lihat` is granted to `pasien` and `superadmin` and to nobody else, so the permission alone already refuses `dokter`, `apotiker`, `admin`, and `perawat` and `kurir` - the last two hold no role at all in `RbacCatalog::ROLES` and therefore no grant. No `tipe:` is added, for the same reason `pembayaran.bayar` carries none: the permission already narrows the audience and a second gate could only narrow further.
- Non-patients are 403 on the notification centre, and another patient's notification is 404. Both are asserted, with the justification above.

A referral is 403 without consent and 201 once the consent endpoint has recorded it, proving the guard is wired to the same rule the write path enforces.

## 6. Audit - global observer, once per DECISION

`AuditScope::auditedTables()` already contained `persetujuan_pdp` and `notifikasi`, both hanging off `users` (`:1143` and `:1046`), and `AuditObserverRegistrar::auditedModels()` resolves both model classes. Nothing in the audit layer needed changing, and `app/Services/Audit/` and `app/Observers/AuditObserver.php` are untouched by this todo.

**No controller writes an `audit_log` row.** Both controllers write through the model so the existing global observer fires. The assertion is a count, not a shape: three decisions produce exactly three `audit_log` rows, and the fourth request - the idempotent re-send - produces none, because the audit records DECISIONS and not HTTP calls. Marking a notification read writes exactly one row, and a second call writes none.

`app/Http/Controllers/Api/V1/RekamMedisController.php` was read before the observer work to confirm the ten existing audited actions are unaffected: it is unmodified, and `tests/Feature/Audit/*` and `tests/Feature/RekamMedis/*` pass unchanged.

## 7. TDD, and the red that could not be recovered

**The honest position: the chronological red for the core is not recoverable.** This todo's implementation arrived as inherited uncommitted work that was already green, committed as `060d063` by an executor that was then lost. Claiming a red-then-green I did not run would be a fabrication, so it is not claimed. What IS claimed is below, and it is evidence of the same property obtained honestly.

**The permanent token audit was written this session, and its first run was RED for the right reason: 3 of 6 failing.** All three failures were wrong EXPECTATIONS in the new test, and the parsed DDL corrected each one:

1. The audit asserted `booking` is a member of `booking.status`. It is not. The parse shows `booking` lives in `notifikasi.tipe` and `invoice.referensi_tipe`. This is the exact failure mode a "declared vocabulary" audit exists to prevent - the list had been written from memory rather than read off the schema.
2. The audit asserted the refused columns are ABSENT from the request's `rules()`. They are present as `['prohibited']`, which is the correct and stronger form - a key deleted outright would be ACCEPTED. The test now asserts the rule is exactly `prohibited` and that the accepted three and refused four partition the seven keys.
3. The audit asserted the controller contains no `->update(`. It does - in the docblock that explains why it is avoided. The instrument is now the tokenizer, not a text match.

**A collection defect the run exposed, and it mattered.** The audit file was first written as `TokenAuditTest47.php`. A directory run reported 20 tests, not 26: Pest's suffix rule is `*Test.php`, so a file ending `47.php` is silently SKIPPED during collection and the permanent gate was not in the suite at all. It was run by explicit path, which is why it looked green. Renamed to `TokenAuditPdpTest.php`; the same directory run now reports 26. A gate that is not collected is not a gate.

**Reconstructed red by controlled mutation, control FIRST.** The baseline was run before any source change, in the repository's own private database, and every mutation was reverted and the suite re-run green.

- **CONTROL** (no mutation): 26 tests, 26 passed, 665 assertions, 0 failed. A harness that never ran green cannot attribute a later failure to a mutation.
- **M1** `PdpConsent::versiTerbaru()` ordering `versi_dokumen DESC` -> `id DESC`: **26 tests, 1 failed.** `BOUNDARY 2` expected `'v09'`, got `'v05'` - the older row returned because insertion order and version order disagree once a lower version arrives late. `BOUNDARY 1` correctly still PASSED, because there a higher version is also inserted last, so the mutation is only observable in the boundary that separates the two orderings. That is the whole reason boundary 2 exists as a separate case.
- **M2** the R1c ordering guard short-circuited to `if (false)`: **26 tests, 3 failed.** Both boundary tests reported "expected PerubahanVersiException but nothing was thrown", with the row written, and the HTTP-level test reported `expected 422, received 201`. Caught at the service level AND at the response level.
- **M3** the collision turned into the upsert the plan's wording invites - in-place update of `disetujui`, `disetujui_at`, `ip_address`: **26 tests, 3 failed.** The collision test reported nothing thrown, and the dumped model shows the defect concretely: `previous => disetujui: 1` mutated to `false` with `wasRecentlyCreated => false`. The two-refusals test received 200 rather than 422, because the controller treats a returned existing model as the idempotent path - the upsert is not merely a wrong write, it is a wrong STATUS.
- **RESTORED**: 26 tests, 26 passed, 665 assertions, and `git diff` on both mutated services is EMPTY, so no mutation residue remains.

## 8. Audits, as permanent tests

`tests/Feature/Pdp/TokenAuditPdpTest.php`, 6 tests / 85 assertions, collected by the suite:

1. **Byte gate.** The 18 authored or modified files are read as RAW BYTES, walked with `ord()` and never decoded, 0 bytes above 0x7F, no BOM. `268676` bytes total, with a byte floor and a file count so a broken harness cannot pass by reading nothing. A missing file is asserted per file, because `file_get_contents` returns `false` and `strlen(false)` is 0 - a renamed file would otherwise pass as an empty one. The reason for raw bytes is that a Cyrillic U+0430 is valid UTF-8, survives `mb_strtolower()`, and matches `/^[a-z]/`; only a byte comparison catches it.
2. **Schema identity.** The audit parses with `SqlSchemaParser`, the same parser `sehatly:verify-schema` uses, and asserts 75 tables - so "0 unknown tokens" is a statement about the file the parity verifier reads.
3. **Per-table token audit.** Every column this todo names is resolved against the table that OWNS it, with COMPLETENESS asserted for `persetujuan_pdp` and `notifikasi`, and a total classification of `persetujuan_pdp` into accepted versus refused against `StorePersetujuanPdpRequest::KOLOM_MILIK_SISTEM`. A flat name set would prove nothing: `disetujui` existing somewhere in the schema says nothing about `persetujuan_pdp` having it.
4. **ENUM bucket, per column, in DDL order.** `toBe` rather than `toContain`, because a value or an ORDER differing by one character still looks right in a diff and answers a 500 at the INSERT, since MySQL rejects an ENUM value outside its list. The wrapped `jenis` ENUM at `:1137`-`:1138` is the case a naive single-line read truncates. Ambiguity is REPORTED rather than resolved, from the parse: `chat` is a member of `booking.tipe_layanan`, `konsultasi.tipe` AND `notifikasi.tipe`, and `sistem` spans four columns, so a bare value cannot decide its column; `promo` is unique to `notifikasi.tipe`. `uq_consent` is asserted to be exactly `(user_id, jenis, versi_dokumen)`.
5. **Citation gate.** Every `:NNN` DDL citation in every authored file is range-checked against a 1349-line file, >150 citations, with a negative lookbehind so the time portion of a frozen clock is not read as a citation. This found the plan's own todo-47 citation wrong: it names `notifikasi.idx_notif` at `:1045`, and `:1045` is `dibuat_at`; the index is on `:1047`.
6. **Notification column classification** and the tokenizer-based no-builder-write check.

## 9. Unfinished, stated plainly

1. **No module event producer is wired to `NotificationService`.** The service exposes five event methods and the notification centre is complete, but nothing in the booking, payment, prescription or chat flows CALLS it. The plan asks for rows to be written for the events the modules actually produce; the producers are not connected.
2. **There is no real FCM transport.** `LogPushDispatcher` logs one line per active device. The plan asks for pushes dispatched through `user_devices.fcm_token`; that transport does not exist, and section 4 records that `notifikasi` has no delivery-state column to record an outcome in. `PushDispatcher` is the seam; the implementation behind it is a later todo.
3. **`vendor/bin/pint --test` fails on 110 files repository-wide, and this todo did not fix it.** There is no `pint.json`, so Pint applies the default Laravel preset to a codebase that has never been formatted with it. The baseline is PRE-EXISTING and was proved rather than asserted: `app/Services/Konsultasi/KonsultasiService.php` and `app/Services/Resep/ResepService.php` - both untouched by this todo, both from earlier todos - fail on their own. Running Pint in write mode would rewrite ~110 files across other todos' work, which is out of scope for this todo. The one file authored here, `TokenAuditPdpTest.php`, is Pint-clean.
4. `docs/schema-notes.md` was not edited. No schema note is needed: this todo adds no table, column, index or constraint.
5. Three route-inventory tests in OTHER todos were updated, not weakened - see section 10.

## 10. Verification

All on the private database `telemedisin_db_x47`, created, migrated and seeded for this todo.

| check | result |
| --- | --- |
| `artisan test` (full suite) | **1080 tests, 1080 passed, 19794 assertions, 0 failed** |
| `tests/Feature/Pdp/` | 26 tests, 26 passed, 665 assertions (20 behavioural + 6 audit) |
| `artisan sehatly:verify-schema` | PASS - 75 tables, 2 views, 0 drift, 7 informational, nothing written |
| `telemedicine_test.sql` SHA-256 | `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` - byte-identical |
| `api/v1` route+verb pairs | 74 = 69 baseline + 5 from this todo |
| `php -l` on authored PHP | clean |
| raw-byte scan, 18 files | 268676 bytes, 0 above 0x7F, 0 BOM |

The five routes, with the guards asserted in `PdpNotificationTest` and in `PasienProfileTest`:

```
GET  api/v1/pdp/persetujuan      auth:sanctum
POST api/v1/pdp/persetujuan      auth:sanctum
GET  api/v1/notifikasi           auth:sanctum + permission:notifikasi.lihat
PUT  api/v1/notifikasi/{id}/baca auth:sanctum + permission:notifikasi.lihat
PUT  api/v1/notifikasi/baca-semua auth:sanctum + permission:notifikasi.lihat
```

**Three tests in other todos failed on the full run, and were fixed by EXTENDING their closed sets, never by widening a filter.** `AuthFlowTest` and `PasienProfileTest` each assert a closed census of every `permission:`/`tipe:` string in `routes/api.php`, and `PasienProfileTest` asserts a closed set of every `api/v1` route plus a per-route guard map. Five new routes and three new permission strings must appear in all of them, or the censuses stop being closed. Each edit adds only this todo's entries and records why the consent routes contribute no permission string and the notification routes contribute no `tipe:` string. The comments in those files explain that a closed set only one file watches is a closed set one later refactor can quietly reopen - which is why the two permission censuses are deliberately duplicated.

## 11. Files

Created:

- `app/Enums/NotifikasiTipe.php` - the seven-value DDL ENUM
- `app/Services/Pdp/PerubahanVersiException.php` - the two refusals, 422, two messages
- `app/Services/Pdp/PdpConsentService.php` - the write path, the only place the version rule is enforced on the way in
- `app/Services/Notifikasi/PushDispatcher.php` - the push transport contract
- `app/Services/Notifikasi/LogPushDispatcher.php` - the log-only transport actually in use
- `app/Http/Requests/Pdp/StorePersetujuanPdpRequest.php` - accepts three columns, `prohibited` on four
- `app/Http/Requests/Notifikasi/IndexNotifikasiRequest.php` - pagination and the unread filter
- `app/Http/Resources/PersetujuanPdpResource.php`
- `app/Http/Resources/NotifikasiResource.php`
- `app/Http/Controllers/Api/V1/PersetujuanPdpController.php`
- `app/Http/Controllers/Api/V1/NotifikasiController.php`
- `tests/Feature/Pdp/pdp47-helpers.php` - fixtures, a recursive log recorder, throwable and duplicate-key helpers
- `tests/Feature/Pdp/PdpNotificationTest.php` - 20 behavioural tests
- `tests/Feature/Pdp/TokenAuditPdpTest.php` - the 6 permanent audit tests

Modified:

- `app/Services/Pdp/PdpConsent.php` - highest-version read, `effective()`/`disetujui()`/`require()`
- `app/Services/Notifikasi/NotificationService.php` - five event methods, one per active device
- `app/Providers/AppServiceProvider.php` - the push dispatcher binding only
- `routes/api.php` - the five routes
- `tests/Feature/Auth/AuthFlowTest.php` - census extended with three entries
- `tests/Feature/Pasien/PasienProfileTest.php` - closed set, guard map and census extended

Not touched: `database/migrations/`, `database/seeders/`, `telemedicine_test.sql`, `.omo/plans/`, `app/Services/Audit/`, `app/Observers/`, `web/`, `packages/`, `.playwright-mcp/`. No `migrate:rollback`, no `artisan serve`, no test skipped, no `git add -A`.
