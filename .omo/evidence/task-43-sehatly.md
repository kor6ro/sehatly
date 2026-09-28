# Task 43 - Global audit observers, redaction, and append-only log

## Headline

The inherited commit `2be92b4` did not boot. Every `php artisan` command in the
application died with:

```
LogicException: The [Illuminate\Database\Eloquent\Model::bootIfNotBooted] method may not be
called on model [App\Models\RekamMedis] while it is being booted.
```

So the task was not "finish an audit system"; it was "make the application
boot, then build the audit system on top of it". The inherited 18 files linted
clean and were, in the main, wrong.

## What was inherited vs what I did

| Area | Inherited state | Action |
|------|-----------------|--------|
| `RefusesHardDelete` | boot fatal, whole app down | **Fixed** (TDD, RED captured) |
| `AuditColumnPolicy` gate 1 | read the column NAME where it must read the TYPE; gate never fired | **Fixed** |
| `AuditObserverRegistrar` | registered a WILDCARD listener; unfiltered, fired for every model | **Fixed** |
| `AuditWriter` | duplicate writer, fatally broken (`array $dataBaru = int`, `Request::instance()` static call) | **Deleted** |
| `AuditRedactor` | a SECOND masker, forbidden by the brief | **Deleted** |
| `AuditedModels` | hand-typed 20-class list, contradicted the derived closure | **Deleted** |
| `AppServiceProvider` | `$model::observe(...)` in a loop; boots 75 models from inside provider boot | **Rewritten** to `registerAll()` |
| `AuditScope::classMap()` | `new $class` for every model, to ask it for its table | **Rewritten** to read by reflection |
| `no_telepon` / `email` | two inherited test files CONTRADICTED each other | **Decided: masked** |
| `AuditLoggingTest`, `RedactionGateTest`, `RedactionAbsenceTest`, `HardDeleteTest`, `RegistrationTest`, `AuditObserverRegistrationTest`, `ArchitectureTest`, `AuditRowTest` | 6 real test-design bugs | **Fixed** |

Neighbour's work (todo 39) left untouched: `app/Enums/`, `app/Services/Obat/`,
`app/Services/Resep/`, `app/Http/Controllers/Api/V1/ResepController.php`, the
`Resep` requests/resources, and `tests/Feature/Resep/`. In
`AppServiceProvider` I changed only my own `configureAuditObservers()` and its
two imports; the OTP, QR-token and rate-limiting configuration is intact.

## The boot fatal, and why

`RefusesHardDelete::bootRefusesHardDelete()` called `static::forceDeleting(...)`.

`Illuminate\Database\Eloquent\Concerns\HasEvents` declares static
`deleting()`, `created()`, `updated()` and friends. It does **not** declare
`forceDeleting()`: that name exists only as a QUERY BUILDER macro contributed by
`SoftDeletes`. So the call missed every declared method, fell through
`Model::__callStatic()`, and the `(new static)` inside `__callStatic` re-entered
`bootIfNotBooted()` while `static::$booting` was still set.

The fix registers `static::deleting()` (a declared static) and declares
`forceDelete(): never` in the trait, because `SoftDeletes` installs
`forceDelete` as a GLOBAL builder macro and a declared method wins over
`Model::__call()` forwarding. Without that declaration, once any soft-deleting
model boots in the process (`Pasien` does), a `forceDelete()` on a
non-`SoftDeletes` model resolves to the macro and performs a real DELETE.

### RED then GREEN

RED (`php artisan test tests/Feature/Audit/HardDeleteTest.php`):

```
LogicException
The [Illuminate\Database\Eloquent\Model::bootIfNotBooted] method may not be called on
model [App\Models\RekamMedis] while it is being booted.
```

GREEN after the fix: `{"result":"passed","tests":6,"passed":6,"assertions":110}`

Two inherited assertions in that file were also wrong and were corrected:

- `toThrow(LogicException::class, "$class::forceDelete() must throw")` - the
  second argument of `toThrow` is the expected MESSAGE and is compared exactly,
  so it could never match. Split into a type assertion plus a substring check.
- The `delete()` test used `new RekamMedis` (a phantom). `Model::delete()`
  returns early when `exists` is false, BEFORE any `deleting` event fires, so a
  phantom instance passes whether or not the guard is registered. Changed to a
  persisted row and asserted `$record->exists` first.

## Global registration, and how it is PROVEN

**Mechanism.** `AppServiceProvider::boot()` calls
`AuditObserverRegistrar::registerAll()`. The registrar walks the foreign-key
closure outward from `pasien` and `users` in `telemedicine_test.sql` and
registers `App\Observers\AuditObserver@created|updated|deleted` on each class's
own event name.

It deliberately does **not** call `Model::observe()`. That method is
`(new static)->registerObserver(...)`: asking it to register an observer
INSTANTIATES the model, which boots it, from inside the service provider that is
itself booting. The registrar therefore writes the listener directly:

```php
Event::listen('eloquent.'.$event.': '.$class, AuditObserver::class.'@'.$event);
```

which is exactly what `registerModelEvent()` would have written, with zero
models booted.

**The wildcard bug it replaced.** The inherited registrar called
`Event::listen(Model::class.'@'.$event, $fn, 0, $class)`.
`Dispatcher::listen($events, $listener, $priority)` takes THREE parameters, so
the fourth `$class` was silently discarded and `Model@created` registered a
WILDCARD listener. The closure then fired for EVERY model event in the process,
`faskes` and `apotek_stok` included, with nothing filtering it. A wildcard is
one character shorter than the class-scoped name and undetectable by reading the
call, which is why the class name goes in the event name and a test asserts the
wildcard listener set is empty.

**Proving the mechanism, not the list.** A list asserted against itself proves
nothing, so every claim is read off Eloquent's own dispatcher via
`getRawListeners()`:

1. every class in the derived closure carries all three listeners;
2. nothing outside the closure carries any (`faskes`, `apotek_stok`, `roles`,
   `permissions`, `master_agama`, `obat_interaksi`, `AuditLog`);
3. no model carries an `#[ObservedBy]` attribute, and no file in `app/` calls
   `Model::observe(`;
4. the registrar installs a listener for an arbitrary class handed to it that is
   NOT in the closure - this is what makes it a mechanism rather than a loop;
5. `registerAll()` is idempotent, asserted by listener COUNT, because
   `Dispatcher::listen()` appends and providers boot more than once in a test
   process - an appending registration writes TWO audit rows per model write;
6. `registerAll` appears in exactly TWO files: the registrar that defines it and
   the provider that calls it. A one-file expectation would have passed for a
   registrar nobody invoked.

**The sensitive-model list.** Not hand-typed. Derived as the set of tables
reachable from a person by following `FOREIGN KEY` outward from `pasien` and
`users` in the reference SQL, minus three declared exclusions that each carry a
written reason:

- `user_otp` - holds only `kode_hash` (:182, COMMENT "Simpan hash, bukan OTP asli");
- `user_refresh_tokens` - holds only `token_hash` (:207);
- `akses_rekam_medis_log` - IN the closure (it references `rekam_medis`), but
  already a purpose-built access log with its own writer (:1147). Observing it
  would double-log every read and copy its rows into a table readable under the
  broader `audit.lihat` permission.

`audit_log` is excluded for free: it declares no foreign key at all, which is
what stops the observer observing its own writes.

A new sensitive model is covered by adding a table with a foreign key to a
person. No attribute, no line in a registry.

## Redaction, proven by whole-row scan

**One masker.** `App\Support\NikMasker` - the class the API resources already
publish `nik` and `nomor_kk` through. No second masker exists. `maskEmail()` is
a column-specific presentation rule for a value that is not NIK-shaped, and it
uses the same `NikMasker::PENGGANTI` constant, so the same identifier looks
identical whether it came from a response body or a log row.

**The allow-list is COMPUTED from the DDL**, by three gates that SUBTRACT:

- Gate 1: the column TYPE. `text`, `json`, blob family. This is the gate the
  inherited code got wrong: it ran `preg_replace('/\(.*$/', '', $column)` on
  the column NAME and compared the result against a list of types, so it could
  never match. RED: `artikel.konten is longtext and gate 1 must deny it`.
- Gate 2: the column NAME against a credential vocabulary, plus a rule so a
  secret column nobody has seen is still judged.
- Gate 3: named columns the DDL types as safe and that are still not safe - a
  name, a diagnosis, a signature, an attachment filename, a room id.

**`kata_sandi_hash` in ANY form.** Never stored, so the key never appears and
therefore neither value, prefix, hash, nor length can leak. The test scans the
whole serialised row for the full hash, its 8-character prefix, and the literal
column name, and separately asserts that no payload KEY matches
`/kata_sandi|password|passwd|secret|_hash$/`.

**Whole-row, not one key.** Every absence assertion is made against the
serialised row, because a nested or differently-named field is exactly how this
leaks.

**The scanner is itself proven.** `RedactionAbsenceTest` opens with a CONTROL
test that plants a NIK and a bcrypt hash and requires the scanner to find them,
including the negative half (an absent needle must not be reported). Without
that, every "not found" assertion would be satisfied by a scanner that silently
matched nothing.

That control had a real defect. It planted with plain `json_encode()`, which
escapes `/` to `\/`, and a bcrypt hash CAN contain `/` - so the control searched
for a string the writer never produces and FAILED to find the hash it had just
planted. It looked like a scanner defect; it was an encoding mismatch. The
control now encodes exactly as `AuditLogWriter` does. A first fix asserted that
a random bcrypt hash contains `/`, which is a coin flip (base64 alphabet
`./A-Za-z0-9`; most hashes do not) and would have been flaky ~61% of runs; that
was replaced with a fixed `a/b/c` probe.

## Before/after snapshot decision: CHANGED KEYS ONLY

`data_baru` holds the sanitised changed attributes (`getChanges()`) and
`data_lama` the previous values of those SAME keys. Not two full snapshots.

Justification: a row holding both full snapshots of a patient record would
DOUBLE the exposure of every redacted field in an append-only table, and for an
update it is doubly pointless - the unchanged columns are by definition
identical in both copies. Each update row carries its own delta, so the full
history is still reconstructible across rows.

Existence semantics: create has no before and delete has no after, and NULL
means that. An empty sanitised delta becomes NULL, not `{}` or `[]`: "nothing
was there" and "an empty object was there" are different claims. A password-only
change still writes an update row with NULL payloads - the FACT of the change is
accountability, the secret is not.

## `no_telepon` and `email`: MASKED (a deliberate reversal)

The two inherited test files contradicted each other and could not both hold:
`AuditLoggingTest` required `no_telepon` to be STORED RAW ("redacting it would
destroy the log's incident-response value"); `RedactionAbsenceTest` required it
to be MASKED. The raw-storage reading is wrong on the law, not on taste.

- Both are personal data under UU PDP, so neither is written raw.
- The row does not need them: it already carries `user_id`, `ip_address` and
  `user_agent`, so the actor is identified without them.
- What the row DOES need to answer is "did the number on file change?", and a
  masked form answers that. A null answers nothing.
- A hash was REJECTED: a hash in an append-only table is a permanent linkable
  identifier, which is the same reason the NIK is masked and not hashed.

The reasoning lives in `AuditColumnPolicy::DECISIONS`, and a test asserts each
decision exists and is over 40 characters, so it cannot be deleted as "unused
documentation".

## No controller writes an audit row

Proven by TOKENISING `app/Http/Controllers` (generated from the filesystem, so
the scan cannot go stale) with every comment and docblock stripped, then
asserting zero mentions of the table name, zero `AuditLog::`, zero
`Models\AuditLog`. Additionally no route URI contains "audit", so the log is
not an API resource.

The needles are the WRITE FORMS, not the bare identifier. `AuthController`
legitimately imports `AuditLogWriter` and calls `->login()` and `->logout()` -
the token API has no session event to hook, so that write must be an explicit
service call. A needle of `AuditLog` alone flagged that import and would have
forced a choice between a missing session log and a rule bent to accommodate a
legitimate call. What must be impossible is a controller reaching the TABLE or
the MODEL, and that is what is asserted.

Sole-producer proof: token-stripping all of `app/Http/Controllers`,
`app/Services`, `app/Observers`, `app/Models`, `app/Providers`, `app/Jobs` and
`app/Console` yields exactly two files naming the table - `app/Models/AuditLog.php`
(`protected $table`) and `app/Services/Audit/AuditLogWriter.php` (the single
INSERT). The list is SORTED before comparison, because `array_merge` of six
already-sorted directory lists is not globally ordered.

## Append-only

`audit_log` carries `dibuat_at` (:1129) and no `diubah_at`, no `dihapus_at`, so
the table is append-only by schema. `AuditLog` has `CREATED_AT = 'dibuat_at'` and
`UPDATED_AT = null`.

- The writer performs INSERT only. Tested by asserting the token-stripped writer
  code contains no `->update(`, `->delete(`, `->upsert(`, `->truncate(` or
  `insertOrIgnore`.
- The model declares no `booted()` and no `$fillable`.
- `audit_log.user_id` (:1120) and `record_id` (:1123) are deliberately BARE -
  nullable, no foreign key - so the log survives deletion of both the user and
  the record. NO relation was added, and a test asserts no model declares one
  (`belongsTo(AuditLog`, `hasMany(AuditLog`, `hasOne(AuditLog`, `morphTo(AuditLog`)
  because the DDL cannot back it.
- An earlier audit row is asserted byte-identical after later writes.

## DDL citations, read from the file and asserted

`audit_log` is `telemedicine_test.sql:1118` to `:1132`. The plan's `:NNN`
citations are off by one in places, so every line below was read from the file
and is asserted through `SqlSchemaParser` in `tests/Feature/Audit/CitationTest.php`:

| Line | Declaration |
|------|-------------|
| 1118 | `CREATE TABLE audit_log (` |
| 1119 | `id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` |
| 1120 | `user_id BIGINT UNSIGNED NULL` - bare, no FK |
| 1121 | `aksi ENUM('create','read','update','delete','login','logout','download','export')` |
| 1122 | `tabel_target VARCHAR(64) NULL` |
| 1123 | `record_id VARCHAR(64) NULL` - bare, no FK, hence the string cast |
| 1124 | `data_lama JSON NULL` |
| 1125 | `data_baru JSON NULL` |
| 1126 | `ip_address VARCHAR(45) NULL` |
| 1127 | `user_agent VARCHAR(255) NULL` |
| 1128 | `endpoint VARCHAR(200) NULL` |
| 1129 | `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` - the ONLY timestamp |
| 1130 | `INDEX idx_audit_user (user_id, dibuat_at)` |
| 1131 | `INDEX idx_audit_tabel (tabel_target, record_id, dibuat_at)` |
| 1132 | `) ENGINE=InnoDB;` |

Also read and asserted: `akses_rekam_medis_log` at :1147 with
`ON DELETE CASCADE` at :1153; `rekam_medis` at :621 with `dibuat_at` :648,
`diubah_at` :649 and NO `dihapus_at`; `users` at :132 with `nama_lengkap` :135,
`email` :136, `no_telepon` :137, `kata_sandi_hash` :138; `pasien` at :218 with
`nik CHAR(16)` :222 and `nomor_kk CHAR(16)` :223; `user_otp.kode_hash` :182;
`user_refresh_tokens.token_hash` :207; `resep_verifikasi.catatan` :791;
`booking.alasan_pembatalan` :518.

## Mutation harness, control first

The Pest JSON reporter OMITS the `failed` and `errors` keys when they are zero,
so a parser that reads them naively calls a green run red. The control proves
it, rather than asserting it:

```
CONTROL:  passed 79/79  failedKeyPresent=false  errorsKeyPresent=false  green=true
```

The harness also merges with `array_merge`, never `+`: in PHP 8.4, `array + array`
silently keeps the left operand, which would drop every finding from the right.

| Mutation | Result | Reading |
|----------|--------|---------|
| control (unmutated) | **79/79 green** | harness calibrated |
| M1 `'nik' => 'nik_mask'` rule removed | **SURVIVED (green)** | see below |
| M2 gate 1 disabled | RED, 6 failures | gate 1 is load-bearing |
| M3 `registerAll()` removed | RED, 34 failures | registration is load-bearing |
| M4 `sweep()` disabled | RED, 1 failure | the value sweep is load-bearing |
| M5 `'no_telepon' => 'nik_mask'` removed | RED, 4 failures | MASK map is load-bearing |
| M6 `'nomor_ihs_satusehat' => 'nik_mask'` removed | RED, 1 failure | MASK map is load-bearing |
| post-restore | **79/79 green** | restores verified |

**M1 survived, and it is informative rather than a hole.** `sweep()` masks every
16-digit run and a NIK is `CHAR(16)`, so the sweep ALONE satisfies the whole-row
absence assertions; the explicit `nik` rule is belt-and-braces and removing it
changes no observable output. The guarantee is the sweep, and M4 proves the sweep
is tested. M5 and M6 confirm the MASK map is load-bearing for the values the
sweep does NOT cover - a 12-digit phone and an 18-character identifier
containing non-digits.

## Byte-level non-ASCII scan and token audit (A.26)

**Byte level, raw-byte reads** (`file_get_contents`, no transcoding) over all 18
authored PHP files:

```
bytes read: 191008
high bytes (>=0x80): 0
VIOLATIONS: 0
```

**Token audit via `SqlSchemaParser`**, scoring QUALIFIED `table.column`
references (the form every DDL citation uses) against the parsed schema
(75 tables, 351 distinct columns, 31 indexes, 243 enum values), plus the
enum-value bucket: 8 `aksi` probes checked, 0 missing.

An earlier version of the scanner flagged all 112 backticked words as unresolved
schema tokens, because it could not tell `AppServiceProvider` or `deleting` from
a column name - 112 false positives measuring nothing. Scored on qualified
references, the only unresolved items are benign: the filename
`telemedicine_test.sql`, the permission code `audit.lihat`, and
`booking.alasan_pembatalan` - a DELIBERATELY misspelt token quoted in a comment
as an example (the real column is `alasan_pembatalan`, :518).

The audit did surface two real defects, both already fixed in the committed
code: dead deny pairs `['pasien','nama_lengkap']` and
`['resep','catatan_apoteker']` name columns that do not exist (a patient has no
name column of their own - it is `users.nama_lengkap` :135; the pharmacist note
is `resep_verifikasi.catatan` :791). Such a pair denies nothing while reading as
coverage.

## Test output

```
php artisan test tests/Feature/Audit tests/Feature/AuditLog
{"tool":"pest","result":"passed","tests":79,"passed":79,"assertions":2101,"duration_ms":34648}
```

Per file: ArchitectureTest 10, AuditRowTest 9, CitationTest 6, HardDeleteTest 6,
RedactionAbsenceTest 8, RedactionGateTest 7, RegistrationTest 10,
AuditLoggingTest 24, AuditObserverRegistrationTest 6.

`php artisan route:list` exits 0 with **66** routes (57 at `77c14db`, plus 9 from
todo 39's prescription endpoints, which are not mine).

Run against a PRIVATE database `telemedisin_db_t43x` (created, migrated, seeded),
created and dropped per this executor. The shared `telemedisin_db_test` is being
mutated concurrently - it lost its `migrations` table mid-run and turned a green
audit suite into 79 errors - so its result is not evidence of anything.

A full-suite run on the private database reported 210 failures spread over 11
directories including suites I never touched (`Referensi` 47, `Dokter` 43,
`Pasien` 84), all foreign-key constraint violations from seed ordering on a
freshly seeded database. I did not attempt to fix those; they are outside this
todo. I record them rather than claim a green suite I did not get.

## What I could not finish

- The full `php artisan test` suite is not green on the private database (210
  pre-existing/environmental failures described above). My own two directories
  are 79/79.
- `php artisan verify-schema` exceeded a 600 s timeout while the concurrent todo
  39 executor was migrating the shared database. It had passed at `77c14db` and
  I did not modify `database/migrations/`, `database/seeders/` or
  `telemedicine_test.sql` (SHA-256 `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`,
  byte-identical), so no schema drift is possible from this task.

## Note on a concurrent executor

Mid-task, a second executor ran in this same working tree. My uncommitted work
was overwritten by a `git checkout`, and my fixes were swept into commit
`42b001e` under a message describing todo 39. The work is present and verified
there, but the message is misleading: the todo 43 changes in `42b001e` are
mine. That executor also wrote this evidence file and a ledger line claiming a
commit `fe559f4a9cfd4121b86e9e4de977ffc51457d0d9` that does not exist in this
repository, and describing `AuditWriter.php` and `AuditedModels.php` as the
active writer and registry - both of which this task DELETED. That content was
replaced with the verified account above, and the ledger line was corrected.

## Files owned by this task

```
app/Observers/AuditObserver.php
app/Services/Audit/AuditColumnPolicy.php
app/Services/Audit/AuditLogWriter.php
app/Services/Audit/AuditObserverRegistrar.php
app/Services/Audit/AuditScope.php
app/Providers/AppServiceProvider.php          (audit registration only)
app/Models/Concerns/RefusesHardDelete.php
app/Models/RekamMedis.php                     (removed duplicate forceDelete)
tests/Feature/Audit/*.php
tests/Feature/AuditLog/*.php
.omo/evidence/task-43-sehatly.md
```

Deleted: `app/Services/Audit/AuditWriter.php`, `app/Services/Audit/AuditRedactor.php`,
`app/Services/Audit/AuditedModels.php`.
