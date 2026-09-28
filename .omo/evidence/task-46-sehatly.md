# Task 46 - prescription checkout, stock guard and order tracking

Executor evidence for todo 46 of `.omo/plans/sehatly-telemedicine-platform.md`.
Every line number below was read out of `telemedicine_test.sql` on this machine
and is asserted against the file by `CheckoutTest`, not quoted from the plan.

## Scope delivered

| file | lines | what it is |
| --- | --- | --- |
| `app/Enums/PesananObatStatus.php` | 71 | the six `pesanan_obat.status` members, a real PHP enum |
| `app/Services/PesananObat/PesananObatStateMachine.php` | 200 | the 6x6 transition map and the tracking-vocabulary gate |
| `app/Services/PesananObat/ApotekStokService.php` | 275 | the shelf read, the alternatives, and the ONE guarded writer of `jumlah_stok` |
| `app/Services/PesananObat/StokTidakCukupException.php` | 160 | the 422s, two messages per field |
| `app/Services/PesananObat/PesananObatService.php` | 896 | checkout, the transitions, the stock read, the order read |
| `app/Http/Requests/PesananObat/CheckoutResepRequest.php` | 125 | the request, and the `prohibited` complement |
| `app/Http/Requests/PesananObat/StokObatRequest.php` | 63 | the stock read's query rules |
| `app/Http/Resources/{PesananObat,PesananObatTracking,StokObat}Resource.php` | 73 / 64 / 40 | the three resources |
| `app/Http/Controllers/Api/V1/PesananObatController.php` | 167 | three actions, no `if` about ownership |
| `routes/api.php` | +85 | the block, appended last |
| `tests/Feature/PesananObat/pesanan46-helpers.php` | 735 | fixtures, DDL assertions, the two-connection dance |
| `tests/Feature/PesananObat/CheckoutTest.php` | 1109 lines, 35 tests | the surface |
| `tests/Feature/PesananObat/ApotekStokConcurrencyTest.php` | 534 lines, 6 tests | the guard, under real contention |
| `tests/Feature/PesananObat/PesananObatTrackingTest.php` | 502 lines, 9 tests | the vocabulary and every transition |
| `tests/Feature/PesananObat/TokenAuditTest.php` | 307 lines, 5 tests | the byte gate and the DDL token audit |

`app/Services/Pasien/PasienRecordAccess.php` (+18) and
`app/Support/Dokumen/NomorDokumen.php` (+9) are touched additively.

## DDL citations, read from the file

Five tables, CREATE through the closing `ENGINE`:

| table | CREATE | ENGINE | notes |
| --- | --- | --- | --- |
| `resep` | 742 | 765 | `status` is an 8-member ENUM that WRAPS to `:752` |
| `resep_item` | 767 | 783 | `obat_id` NULL means racikan (`:770`) |
| `resep_verifikasi` | 786 | 795 | `resep_id NOT NULL UNIQUE` (`:788`) |
| `pesanan_obat` | 797 | 817 | `status` is a SEVENTH multi-line ENUM |
| `pesanan_obat_tracking` | 819 | 827 | `VARCHAR(100)`, no timestamps at all |
| `apotek_stok` | 829 | 841 | `jumlah_stok` SIGNED, no `CHECK` |

The columns this todo's correctness rests on:

```
:799   nomor_pesanan VARCHAR(30) NOT NULL UNIQUE
:800   resep_id BIGINT UNSIGNED NULL            <- the prescription requirement
:801   pasien_id BIGINT UNSIGNED NOT NULL
:802   apotek_id BIGINT UNSIGNED NOT NULL      <- nothing constrains faskes.tipe
:803   tipe ENUM('resep_dokter','obat_bebas','produk_kesehatan') NOT NULL DEFAULT 'resep_dokter'
:807-809  subtotal / biaya_kirim / total  DECIMAL(12,2) NOT NULL DEFAULT 0
:810   status ENUM('menunggu_pembayaran','diproses','siap','sedang_dikirim','selesai','dibatalkan')
:811          NOT NULL DEFAULT 'menunggu_pembayaran'      <- the wrap
:822   status VARCHAR(100) NOT NULL            <- free text, the trap
:825   waktu DATETIME NOT NULL                <- the de-facto created-at
:833   jumlah_stok INT NOT NULL DEFAULT 0     <- SIGNED, no UNSIGNED, no CHECK
:840   UNIQUE KEY uq_stok (apotek_id, obat_id) <- the serialisation point
:365   faskes.tipe ENUM('rumah_sakit','klinik','puskesmas','apotek','laboratorium')
:749   resep.apotek_id BIGINT UNSIGNED NULL
:778-779  resep_item.harga_satuan / subtotal  DECIMAL(12,2) NOT NULL DEFAULT 0
```

**Plan citations corrected against the file.** The plan's todo 46 prose cites
`:797-817` for the order table, which is right, and `:829-845` for
`apotek_stok`, which **runs 4 lines past the closing `ENGINE` at `:841`**. Its
`:833` for `jumlah_stok` is right. Its reference to "`:360-386` (`faskes.tipe`)"
is right for the table but `tipe` itself is at `:365`.

**There is no `pesanan_obat_item` table among the 75**, and no stock-movement
ledger (`stok_mutasi`, `stock_movement`, `apotek_stok_mutasi`, `stok_ledger` are
all asserted absent by `CheckoutTest`). Both absences are the reason for the
design, not decoration.

## Prescription-only: how the DDL expresses the requirement, and where it is refused

The requirement is expressed **twice**, and the two are different columns for
different questions:

1. `master_obat.requires_resep TINYINT(1) NOT NULL DEFAULT 1` (`:720`) is the
   CATALOGUE rule todo 39 enforces at prescribing time.
2. `pesanan_obat.tipe` (`:803`) plus a **NOT NULL in practice** `resep_id` (`:800`,
   nullable) is the ORDER rule.

Reading (1) as the order rule would be reading a different column for a
different question, and `CheckoutTest` proves the two are independent: a drug
with `requires_resep = 0` and `kelas_obat = 'bebas'` still cannot be bought
`obat_bebas`, and the same prescription with `tipe = 'resep_dokter'` checks out
normally.

**Why the refusal is at creation and not a flag.** `obat_bebas` and
`produk_kesehatan` are legal `tipe` members, so MySQL would accept the row. What
the schema cannot accept is the CONSEQUENCE: no `resep_id`, no line rows, and a
`subtotal` nothing can recompute. So the field is ACCEPTED, validated against all
three DDL members (a value outside the ENUM gets the ordinary invalid-value 422),
and then refused by the service with a message naming the missing table.

```
POST /api/v1/resep/1/checkout  {"tipe":"obat_bebas"}   -> 422
"errors": {"tipe": [
  "Hanya pesanan dengan resep dokter yang dapat dibuat lewat endpoint ini.",
  "Tipe \"obat_bebas\" tidak dapat diimplementasikan: pesanan_obat tidak memiliki
   tabel item (telemedicine_test.sql:797-817), sehingga tidak ada tempat untuk
   mencatat produk yang dibeli dan subtotal tidak dapat dihitung ulang dari baris."
]}
```

Asserted by count and by position (`errors.tipe.0`, `errors.tipe.1`), then the
row counts: `pesanan_obat` 0, `pesanan_obat_tracking` 0, `invoice` 0 for
`referensi_tipe = 'pesanan_obat'`, and `apotek_stok.jumlah_stok` unchanged at 100.
A refusal that reserved a unit would be a different defect, so the shelf is
asserted too.

A fourth value (`obat`, not an ENUM member) is a **different** 422 -
`errors.tipe.0 = "Tipe pesanan tidak dikenal."` - because a broken caller and a
structurally unimplementable request are different problems.

## Partial baskets: not allowed, and not a policy choice

There is no basket to be partial. A checkout is ONE prescription and its EVERY
`resep_item` row, because `pesanan_obat` has no column and no table in which a
selection would be recorded, and a partial fill would leave `subtotal`
uncomputable from stored lines. A **mixed** basket (prescription items plus an
over-the-counter product) is not merely unsupported, it is unrepresentable: the
OTC product has no row anywhere in this schema to live in.

So `items` is `prohibited` on the request rather than ignored, and the message
says why: `"Pesanan obat diturunkan dari item resep dan tidak menerima item."`
A caller who sent it believes the order takes a basket, and silently dropping it
would hand back an order for the WHOLE prescription and look like success.

**A racikan makes the order unpriceable, and that is a refusal.** `resep_item.obat_id`
NULL (`:770`) means a mixture, `harga_satian` is `DECIMAL(12,2) NOT NULL DEFAULT 0`
(`:778`) and `ResepService` writes exactly `0.00` for it. `InvoiceService` refuses
a zero-priced line by design, so an order containing one cannot be invoiced. The
422 names the mixture (`Item racikan (Racikan Uji PO46) tidak memiliki obat_id
maupun harga...`) and removes two special cases at once: a racikan has no
`obat_id`, so it also has no `apotek_stok` row and could never be stock-checked.

## How oversell is prevented with NO unique index and NO new column

`apotek_stok.jumlah_stok INT NOT NULL DEFAULT 0` (`:833`) is **signed** - unlike
`resep_item.jumlah SMALLINT UNSIGNED` (`:774`) - with no `CHECK`, no trigger, no
generated column and no ledger. The service layer is therefore the guard, and the
signedness is the audit trail for the one condition the database is forbidden from
preventing. `docs/schema-notes.md` records that a `CHECK` would also destroy the
detection, which is why the value is storable.

The direct answer to "why no unique index":

- **Oversell is not a duplicate-row problem.** A unique index forbids two rows
  with the same key; oversell is a read-modify-write race on ONE row. No index
  shape expresses "this row must retain at least N units", because that is a
  property of a column's VALUE AT WRITE TIME, not of a key's uniqueness.
- **The serialisation point already exists and is already unique.**
  `UNIQUE KEY uq_stok (apotek_id, obat_id)` (`:840`) means exactly ONE row per
  (pharmacy, drug). That existing key is what makes the pessimistic lock
  addressable: every competing checkout of the same drug at the same pharmacy
  passes through the same row. **No new index is needed and none is added.**
- **A hypothetical `UNIQUE (apotek_id, obat_id, jumlah_stok)` would forbid two
  pharmacies holding the same count** rather than forbid overselling.

The guard is TWO mechanisms that fail differently:

1. `SELECT ... FROM apotek_stok WHERE apotek_id = ? AND obat_id = ? FOR UPDATE`
   - a locking READ of one row, never an aggregate. `compileAggregate()` drops
   the lock, so `->count()->lockForUpdate()` is a silent no-op, and under
   `REPEATABLE READ` a plain consistent read sees the transaction's own snapshot
   while a locking read always observes the latest committed version. This is
   the `BookingService` idiom verbatim.
2. `UPDATE apotek_stok SET jumlah_stok = jumlah_stok - ? WHERE id = ? AND
   jumlah_stok >= ?`, refusing when `affected() !== 1`. This makes a negative
   value UNREPRESENTABLE IN THE WRITE rather than merely unlikely, and it does
   not depend on the lock.

Plus a **deterministic lock order**: `apotek_stok` rows are taken in ascending
`obat_id`, so two checkouts of the same two drugs whose `resep_item` rows are
stored in opposite order cannot make InnoDB detect a cycle and roll one back with
error 1213. The test asserts the emitted bindings are ascending from a fixture
whose `resep_item` rows are in DESCENDING id order, so it measures the service
and not the fixture.

## The concurrent oversell test, with its real second connection

`ApotekStokConcurrencyTest::TWO CONNECTIONS, one unit on the shelf`. The
choreography, from the test's own docblock:

```
connection "mysql"  (connection A)
  BEGIN
  SELECT ... FROM `apotek_stok` ... FOR UPDATE      <-- A takes the lock
  *** the QueryExecuted listener fires HERE, synchronously, inside A ***
        connection "po46_b"  (a second PDO, connection B)
          BEGIN
          SET innodb_lock_wait_timeout = 1
          PesananObatService::buat(...)  -> a DIFFERENT patient and prescription,
                                               the SAME drug at the SAME pharmacy
             SELECT ... FROM `apotek_stok` ... FOR UPDATE
                -> MySQL error 1205, because A holds the row
          ROLLBACK
  UPDATE `apotek_stok` SET `jumlah_stok` = jumlah_stok - 1 WHERE ... >= 1
  INSERT INTO pesanan_obat ...; INSERT INTO invoice ...; COMMIT
```

`DB::listen()` fires SYNCHRONOUSLY on `QueryExecuted` right after each statement
returns, so B genuinely runs while A's transaction is open and holding the row,
on a different connection, and the only reason B cannot proceed is that lock. B
runs the REAL, unmodified service with `config('database.default')` repointed.

The listener additionally filters on `QueryExecuted::$bindings` for the exact
`(apotek_id, obat_id)` pair, so it cannot fire on a row B never asks for - the
choreography would otherwise be theatre.

Two different patients and two different prescriptions on purpose: two patients
racing for one prescription would contend on `resep` first and prove something
else.

**What is asserted, in order:**

1. A committed an order, and the shelf is `0`.
2. `keadaan.sudah` is true - the listener fired, so something was interleaved.
3. B's result is a `Throwable` whose driver code is **1205**, via
   `po46AdalahLockWait()` which walks `getPrevious()` and reads
   `QueryException::$errorInfo[1]`, because whether Laravel maps 1205 to
   `DeadlockException` is a framework detail and the property under test is the
   server's answer.
4. B wrote nothing: `pesanan_obat` 0 and `invoice` 0 for its patient.
5. After A commits, B retries: refused with
   `StokTidakCukupException` whose message is
   `Stok "Amoxicillin" tidak mencukupi di apotek yang dipilih (tersedia 0, diminta 1).`
   and whose second message names the stock endpoint where the alternatives are.
   This retry is what proves the availability decision is a CURRENT read - a
   plain consistent read would still see the pre-transaction snapshot.
6. **The headline: `jumlah_stok === 0` exactly. Never negative.** One order
   exists, B's does not.

A sequential twin runs the plan's 1-of-N criterion over HTTP: five sequential
checkouts for one unit give `sukses = 1`, `ditolak = 4`, shelf `0`.

**The control, and it is the reason the test above is worth reading.**
`CONTROL: with NEITHER mechanism the same two transactions drive the shelf to -1`.
Same choreography, both mechanisms absent. B's read is a plain consistent read
which takes NO locks, so it neither blocks A nor is blocked by A; the test
asserts B's snapshot still reads `1` **after** A committed, which is the fact
that makes this a race rather than a sequence. B's UNGUARDED write is applied to
the latest committed row and lands on zero, producing **-1**, and
`WHERE jumlah_stok < 0` then finds exactly one row - the oversell report
`docs/schema-notes.md` says the signed column exists to make possible.

`the guarded write refuses on its OWN` isolates the second mechanism: the service
is called with the row already at zero and the conditional `WHERE jumlah_stok >=
?` refuses, the transaction rolls back, and the shelf is still exactly 0.

## The tracking vocabulary, derived from the DDL

`pesanan_obat_tracking.status` is `VARCHAR(100) NOT NULL` (`:822`) with no ENUM,
no CHECK and no index, while sharing a NAME with `pesanan_obat.status`'s
six-value ENUM (`:810`-`:811`). The DDL therefore contains two notions called
`status` and only one is constrained. `docs/schema-notes.md` requires this todo
to validate that column in the application and to read the order's state from
`pesanan_obat.status`, never from the trail, and that is the design:

- the trail is APPEND-ONLY and every row it holds carries a member of
  `PesananObatStatus::nilai()`;
- the order's current state is ALWAYS read from `pesanan_obat.status`.

A courier's own vocabulary (`in_transit`, `kirim`) has nowhere to live: no
`pesanan_obat` status means it, so accepting it would record a state no UI has a
badge for and no state machine can advance from.
`PesananObatTrackingTest` asserts both directions: the six members are writable
and `in_transit`, `kirim`, `DIBATALKAN`, `"selesai "`, `selesaii`, `diterima` and
`""` are all refused.

### The transitions, and every one of them tested

The map has **8 edges**: four along the happy path plus `dibatalkan` from each of
the four live states.

```
menunggu_pembayaran -> diproses, dibatalkan
diproses            -> siap, dibatalkan
siap                -> sedang_dikirim, dibatalkan
sedang_dikirim      -> selesai, dibatalkan
selesai             -> (terminal)
dibatalkan          -> (terminal)
```

- `EVERY legal edge` drives all 8, walking the order onto each FROM state through
  the real service first, and asserts the order moved, the trail grew by exactly
  ONE row, the new row's `status`/`keterangan`/`lokasi` are what was passed, and
  no earlier row was rewritten.
- `EVERY illegal pair` walks the full **6x6 = 36 matrix**, not a sample: 8 legal,
  **28 refused**, each with two messages asserted by position, and each leaving
  both `pesanan_obat.status` and the trail count untouched.
- Both terminals refuse all six targets.
- An unknown status is a **different** 422 naming the six values, raised before
  the map is consulted.

`no_resi VARCHAR(50) NULL` (`:806`) is free text, NOT unique, NOT indexed. It is
accepted on the `sedang_dikirim` edge only; on `menunggu_pembayaran -> diproses`
it is a 422 with two messages, because a courier reference on a parcel that has
not left is a fact about a shipment that has not happened.

`the order state is read from pesanan_obat.status, NEVER from the last trail row`
writes `sedang_dikirim` into the trail behind the service's back - which the DDL
permits, `VARCHAR(100)` accepts anything - and asserts the API reports
`menunggu_pembayaran` while publishing the trail as stored. The trail is a
record, not a filter: a client that disagrees with itself is shown the
disagreement rather than a sanitised version of it.

**No timestamps.** `SHOW COLUMNS FROM pesanan_obat_tracking` is asserted to be
exactly `id, pesanan_obat_id, status, keterangan, lokasi, waktu`. The model is
`$timestamps = false` and the service writes `waktu` from the application clock -
a test moves the clock and asserts `2026-03-12 08:30:00` rather than a
`CURRENT_TIMESTAMP` default. The resource publishes `waktu` and **no**
`created_at`/`updated_at`, and a test asserts both paths are absent.

## Ownership and the guards, justified

| route | `permission:` | `tipe:` | who is refused, and why |
| --- | --- | --- | --- |
| `POST /resep/{id}/checkout` | `pesanan.buat` | - | `dokter`, `apoteker`, `admin`, `perawat`, `kurir` |
| `GET /obat/{id}/stok` | - | - | nothing is role-refused; anonymous is 401 |
| `GET /pesanan-obat/{id}` | `pesanan.lihat` | - | `dokter` (holds neither), `perawat`, `kurir`; another patient's order 404 |

- **`pesanan.buat` and `pesanan.lihat` are real codes**, verified against
  `RbacCatalog` on every run, because `EnsurePermission` answers an unknown code
  with a 500 and not a 403. They are granted to `pasien` (plus `superadmin` for
  the first), which is exactly the set that may place an order.
- **No `tipe:` on the checkout.** `tipe:pasien` would exclude `superadmin`, which
  HOLDS `pesanan.buat`, and it answers "which account type is this" rather than
  "is this order yours" - the argument `PasienRecordAccess` makes at length. The
  service resolves the caller's own `pasien` through `ownPasien()`, so a
  `pasien`-typed account with no profile row is 403 from the service, which is a
  fact about the caller.
- **`superadmin` holds `pesanan.buat` and is still refused**, with a 403 from
  `ownPasien()`: an oversight account does not check out on a patient's behalf,
  because `pesanan_obat.pasien_id` is the CALLER's own patient row and the
  endpoint takes no other. Tested explicitly.
- **`perawat` and `kurir` hold no role in `RbacCatalog::ROLES` at all**, so
  `RoleAssigner` refuses the role name and the `permission:` gate refuses the
  request. That is the correct answer here, not a gap: a nurse and a courier have
  no business placing a medicine order. The test creates them with no role for
  exactly this reason.
- **The stock read is UNGATED, and that is a decision.** A shelf is a
  catalogue-adjacent fact about a drug at a facility and no code names reading
  one. `obat.cari` IS real but is granted to `dokter` alone, so gating on it
  would 403 the PATIENT - the one account type that has to ask "is my
  prescription in stock before I pay for it", which is the whole point of the
  endpoint and of the spec's `cek stok` rule. `perawat` and `kurir` are allowed
  to read it because the data is not theirs. It is **not anonymous**: tested in
  its own test, because `withToken()` sets a default header for the rest of a
  test and an "anonymous" request made after one is not anonymous.
- **The order read is a DISJUNCTION** - the owning patient OR a pharmacist OR an
  oversight account - and a route gate can only express a conjunction, so the
  per-row half lives in `PesananObatService::untukBaca()`. `apoteker`, `admin`
  and `superadmin` all read any order; another patient's is 404; an account with
  no `pasien` row and no pharmacy type is 403.

Money is a JSON string at every boundary: `subtotal`, `biaya_kirim`, `total`,
`harga_jual` and every `resep_item` price. A JSON number on `biaya_kirim` is a
422 naming the field, and a negative one is refused.

## The `prohibited` map, and the trap it hides

`CheckoutResepRequest` merges `PesananObatService::KOLOM_MILIK_SISTEM` LAST, so
`prohibited` **beats** a field's own rule. Three columns had to be moved out of
that map, and all three were found by running the tests rather than by reading the
code:

| column | what `prohibited` would have answered | why it is in the complement |
| --- | --- | --- |
| `tipe` | `"The tipe field is prohibited."` for a perfectly legal `obat_bebas` | so the service can refuse it WITH a reason |
| `apotek_id` | `"The apotek_id field is prohibited."` | the spec's alternatives rule is USELESS if the caller cannot pick another pharmacy |
| `biaya_kirim` | `"The biaya kirim field is prohibited."` | there is **no** shipping-rate table among the 75 - the only `ongkir` in the file is the `gratis_ongkir` promo type at `:989` - so the charge is an INPUT |

`TokenAuditTest` asserts the classification is **total** over the fifteen
`pesanan_obat` columns: every one is either derived-and-prohibited or
accepted-from-the-request, with `id` the only column in neither, so a column
added to the DDL later has to be classified on purpose.

## Red, then green

**RED** (guard removed, suite run): 11 failed / 2 errors of 34.
`an obat_bebas checkout is a 422 and writes NOTHING`:
`Expected response status code [422] but received 201.` The refusal never
happened, the order was created - the right reason.

The same run exposed **nine further defects**, all real and all fixed:

1. `tipe` was in the prohibited map, so the accept-then-refuse design was dead.
2. `apotek_id` likewise - the spec's alternatives rule could not be used.
3. `biaya_kirim` likewise - every order would have been pinned at `0.00`.
4. `ApotekStokService::kurangi` was declared `kurang` while every call site and
   docblock said `kurangi` - a `referencia`-class naming slip of my own, which
   shipped as `Call to undefined method` and a 500. `method_exists()` returned
   false while reflection listed the method, which is what identified it.
5. `po46Resep()` did not record its `resep` row, so the teardown's `faskes` delete
   failed with a real MySQL 1451.
6. The `beforeEach` sat in the `require_once`d helpers, so only the FIRST
   requiring file got it: 35 errors of "RbacCatalog::ROLES names pasien but
   `roles` holds no such row" in a full-suite run that passed file by file.
7. The `?` binding was read out of SQL text where the value is a placeholder, so
   the lock-order assertion was vacuous; it now reads `QueryExecuted::$bindings`.
8. The alternatives test expected a zero-stock pharmacy in the "where can I get
   this" list; it correctly is not, because the default demand is one.
9. The RbacCatalog tripwire treated `auth:sanctum` as a permission code.

**GREEN**: `CheckoutTest` 35/35, `ApotekStokConcurrencyTest` 6/6,
`PesananObatTrackingTest` 9/9, `TokenAuditTest` 5/5 - **55 tests, 814
assertions**.

## Mutation harness, control first

**1. A parser self-test, on synthetic reports.** The reporter is injected by the
orchestrator and is **not** owned by this repository, and it is **not stable**
about the `failed` key: across green runs in this session it was observed both
absent and present as `"failed":0`. So key presence cannot certify the parser,
and the harness feeds the parser six synthetic shapes and requires the right
verdict from each - green with `failed` absent, green with `failed: 0`, red with
`failed: 1`, red with `errors: 2` and `failed` absent, red with a lost run, red
with `tests` absent. All six correct. The verdict is a conjunction - `result`,
`failed`, `errors`, `tests === passed`, `tests > 0` - so each conjunct closes a
different hole.

**2. The control ran first and was green**: 50 tests, 768 assertions,
`failedKeyPresent: false` on that run. `failedKeyPresent` is **recorded, not
asserted**, because of the instability above.

**3. Apply-check.** Every mutation reports whether its search string was found;
a mutation that does not apply leaves the code untouched, the suite stays green,
and the naive reading is "SURVIVED" - the wrong direction.

**4. A parse-error guard.** A mutation that leaves the file unparseable errors
every test, which looks like the strongest possible signal and measures nothing.
The first draft of M6 did exactly that (32 failed / 6 errors from an unbalanced
brace) and was rewritten as a valid reversed sort. `php -l` runs on the mutated
file before the suite and an unparseable mutation is reported as INVALID.

All **8 mutations KILLED**, each reverted with `git checkout -- <one file>` in a
`finally`:

| id | mutation | failed | errors |
| --- | --- | --- | --- |
| M1 | remove the prescription-only guard | 3 | 0 |
| M2 | drop the `jumlah_stok >= ?` predicate from the decrement | 1 | 0 |
| M3 | drop the pessimistic lock from the stock read | 3 | 0 |
| M4 | widen the status vocabulary to "any string" | 2 | 0 |
| M5 | write `obat_bebas` on every order | 2 | 0 |
| M6 | sort the stock locks descending | 1 | 0 |
| M7 | read the stored `resep_item.subtotal` nothing maintains | 1 | 0 |
| M8 | skip the transition map | 2 | 0 |

The suite was re-run after the harness finished: 50/50, 768 assertions, working
tree clean apart from the untracked `.playwright-mcp/`.

**PHP 8.4 note.** `string + string` throws a `TypeError` on this runtime, so an
arithmetic mutation written as `+` on a string would crash the file rather than
mutate it. M2 and M7 are therefore written as a removed predicate and a replaced
method call - the mutations that actually target these guards.

## Byte-level scan and DDL token audit, as PERMANENT tests

`TokenAuditTest` gates three invariants rather than reporting them once, because
an audit nobody runs is a report, not a gate.

**1. Raw bytes.** Every authored file is read as a byte string and walked with
`ord()`, never decoded, because an identifier whose first letter is a Cyrillic
`U+0430` is valid UTF-8, survives `mb_strtolower()` and matches `/^[a-z]/`. Only
a byte comparison against `0x7F` catches it. 19 files, 0 bytes above `0x7F`, 0
BOMs, and a `total > 50000` floor so a broken harness cannot pass itself by
reading nothing. An out-of-band scan over 19 files reported the same: **0
non-ASCII code points, 0 homoglyph hits** across the Cyrillic, Greek, dash-like,
quote-like, ellipsis, nbsp, minus-sign and multiplication-sign ranges.

**2. The token audit, per table.** The first draft scraped every backticked
token out of every authored file; **140 of the candidates were not schema tokens**
at all, because `routes/api.php` backticks class names, permission codes and
route names and the test files backtick PHP methods. A gate that mostly measures
its own noise is a gate nobody reads.

So the audit is a DECLARED VOCABULARY checked against the project's own
`SqlSchemaParser` - the same parser `sehatly:verify-schema` uses - **per table**,
which is strictly stronger than a flat name set because `jumlah_stok` existing
somewhere says nothing about `apotek_stok` having it. 71 declared columns across
11 tables, 0 unresolvable, and **completeness** asserted for the three tables this
todo writes: every column of `pesanan_obat`, `pesanan_obat_tracking` and
`apotek_stok` is declared, so a column added to the DDL later fails until this
todo decides what it means.

**The enum-value bucket**, per column with `toBe` so ORDER is checked too:
`pesanan_obat.status` (6), `.tipe` (3), `.kurir` (6), `faskes.tipe` (5). The
bucket is separate because a value is **ambiguous on its own** - `ringan` is a
member of both `obat_interaksi.tingkat` and `pasien_alergi.keparahan`, and
`berat` of both - so the audit REPORTS the ambiguity rather than resolving it.
Asserted explicitly, because an earlier audit in this project read that same
ambiguity the wrong way round and concluded the values needed a cast.

**3. Citation bounds.** Every `:NNN` in the authored files is checked against the
DDL's 1349 lines. The negative lookbehind `(?<!\d)` is load-bearing: without it
the time portion of a frozen clock is a citation, and the first run reported ten
of those. The second reported one more - the literal in this test's own comment,
which is why the explanation spells it out: the gate scans itself.

**The audit caught its own author's typo**: `biaya_pengirmen` in the declared
list, which does not exist.

## Test output

```
php artisan test   (per-run DB_DATABASE=telemedisin_db_test_t46)
  1025 tests, 1025 PASSED, 0 failed, 0 errors, 16800 assertions

todo 46's own files, run alone
  55 tests, 55 PASSED, 814 assertions

php artisan route:list --path=api/v1   exit 0
  69 routes (64 before this todo and todo 45's, plus this todo's 3 and todo 45's 2)

php artisan sehatly:verify-schema   exit 0
  PASS - 75 tables, 2 views verified. Nothing was written.

telemedicine_test.sql SHA-256
  AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5   (unchanged)

mobile/   absent
```

**Per-executor test database.** `$env:DB_DATABASE=telemedisin_db_test_t46`,
`phpunit.xml` untouched. The database was created empty, `migrate`d and
`db:seed`ed, because the Unit suite does **not** use `RefreshDatabase` and a
freshly migrated private database gives 8 failures for want of the seed.

## `git show --stat`

```
5b356d7 test(api): gate todo 46 on a raw-byte ASCII scan and a per-table DDL token audit
7131908 test(api): prove the stock guard and every order status transition
5dd5b56 feat(api): add prescription checkout, stock guard and order tracking
```

Each commit used an explicit pathspec naming only this todo's files. A
concurrent executor (todo 45) was committing into the same working tree
throughout; its four test files were left untouched and unstaged, and its own
commits (`a0be435`, `5cd0b12`, `8e53df8`, `1110afb`, `217c26f`) already wire
this todo's three routes into the closed route set and the guard map in
`PasienProfileTest`, `ResepTodo39Test` and `ResepTodo40Test`.

## Deliberate deviations, stated

- **The stock decrement happens at CHECKOUT, not at payment.** The plan puts it
  in the payment transaction (todo 45), and todo 45 was not in this tree when
  this was written. `ApotekStokService::kurangi()` is therefore called from the
  checkout, inside the same transaction that writes the order, so the unit is
  RESERVED when the order is placed. It is the single writer of
  `jumlah_stok` in the whole application, which is the property that matters:
  when todo 45's webhook lands it must call this same method and must NOT
  decrement a second time. Recorded in the service's class docblock.
- **No route drives a status transition.** The plan names three endpoints for
  this todo and none of them is a status write: the payment webhook is the caller
  that moves an order off `menunggu_pembayaran`. `ubahStatus()` is a public
  service method and every transition is driven through it against the real
  database, because inventing a fourth endpoint would be inventing a write
  surface the plan does not describe.
- **A second order on one prescription is allowed.** `pesanan_obat.resep_id`
  carries a foreign key (`:814`) and no UNIQUE, and refusing it would be policy
  this todo has no basis to invent. The stock is reserved once per order, which
  is asserted.
- **A cancelled order does not return its unit.** There is no stock-movement
  ledger among the 75, so there is nowhere to record a release. Recorded in the
  service docblock and in `docs/schema-notes.md`, which already carried this
  from todo 14.
- **`docs/schema-notes.md` was not edited.** Both limitations it was asked to
  carry - the missing line-item table and the signed unchecked stock - are
  already recorded there by todos 14 and 19, at `:916`-`:926` and `:828`-`:843`.
  Editing a shared document mid-flight would risk a conflict with a concurrent
  executor for no new content.

## Nothing unfinished

All four priorities are delivered: the `obat_bebas` rejection, the concurrent
oversell test with a real second connection, the tracking transitions, and this
evidence. No index and no constraint was added; `verify-schema` reports 0 drift
and the SQL is byte-identical.
