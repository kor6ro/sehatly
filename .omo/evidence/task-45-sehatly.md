# Todo 45 - Payment gateway abstraction, payment initiation, and the idempotent webhook

Executed against HEAD `43407ed`. Per-run database `telemedisin_db_t45` (created,
migrated and seeded by this executor; `phpunit.xml` untouched).

**Suite: `php artisan test` = 1020 tests, 1020 PASSED, 0 failed, 0 errors, 16754
assertions.** `php artisan route:list --path=api/v1/invoice` and
`--path=api/v1/webhook` each exit 0 and list exactly one route. `php artisan
sehatly:verify-schema` exits 0: `PASS - 75 tables, 2 views verified. Nothing was
written.` `telemedicine_test.sql` SHA-256
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` - byte-identical
to the value the brief records, re-read after the run.

## The two acceptance criteria, and where they are proved

| criterion | test |
| --- | --- |
| a duplicate webhook produces **exactly one** state change | `a duplicate webhook produces EXACTLY ONE state change and an identical second response` |
| an invalid signature produces **no** state change at all | `a forged signature is rejected with NO state change AND no database read` |

Both are in `tests/Feature/Payment/PaymentWebhookTest.php`. The third file,
`PaymentConcurrencyTest.php`, exists because the first of those is only true
*sequentially*, and the brief asks what happens **concurrently**.

---

## 1. DDL citations, read from the file rather than quoted from the plan

The plan's `:NNN` citations are wrong in three places here, and the test asserts
the corrected ones against the raw line. `pay45AssertLine` / `pay45AssertLineLacks`
read `telemedicine_test.sql` by line number, so a citation is proved against the
file rather than trusted.

| what | plan says | the file says | how it is proved |
| --- | --- | --- | --- |
| `idx_bayar_status` | `:970` | **`:972`** | `pay45AssertLine(972, 'INDEX idx_bayar_status (status, dibayar_at)')` |
| `invoice`+`pembayaran`+`refund` range | `:936-982` | `refund` **ends at `:983`** | `pay45Blok()` reads each block to its own `ENGINE` line |
| `nomor_referensi` uniqueness | implied guardable | **no UNIQUE, no INDEX** | `pay45AssertLineLacks(963, 'UNIQUE' / 'INDEX' / 'KEY')` |

`pay45Blok()` asserts the three block lengths (21 / 16 / 9 lines) so a range that
runs past an `ENGINE` cannot be written without the test failing.

### `pembayaran` - lines 958-973, verified

| line | content | why it matters |
| --- | --- | --- |
| 960 | `invoice_id BIGINT UNSIGNED NOT NULL` | `FOREIGN KEY (invoice_id) REFERENCES invoice(id)` at :970 |
| 961 | `metode_id SMALLINT UNSIGNED NOT NULL` | FK to `master_metode_pembayaran` at :971 |
| 962 | `jumlah DECIMAL(14,2) NOT NULL` | **the amount the stored row carries** |
| **963** | `nomor_referensi VARCHAR(100) NULL COMMENT 'Transaction ID payment gateway'` | **the dedupe key's right half. Asserted to lack `UNIQUE`, `INDEX` and `KEY`.** |
| 964 | `va_number VARCHAR(30) NULL` | the width a mock generator gets wrong |
| **965** | `gateway ENUM('midtrans','xendit','doku','flip') NULL` | **the `{gateway}` path segment's value set** |
| 966 | `status ENUM('pending','berhasil','gagal','kedaluwarsa','refund') NOT NULL DEFAULT 'pending'` | five members; three are settled here |
| 967 | `dibayar_at DATETIME NULL` | counter 1 |
| 968 | `webhook_payload JSON NULL` | the forensic record; see the overwrite decision |
| 969 | `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` | asserted to **lack `diubah_at`** - a re-write leaves the row's own timestamps alone |
| **972** | `INDEX idx_bayar_status (status, dibayar_at)` | asserted to lack `nomor_referensi` and `UNIQUE` |

### Also read: `invoice` 936-956, `master_metode_pembayaran` 925-934, `refund` 975-983

`invoice.total DECIMAL(14,2) NOT NULL` at **:946 with no DEFAULT** (asserted with
`pay45AssertLineLacks(946, 'DEFAULT')`); `invoice.lunas_at DATETIME NULL` at
**:950**; `invoice.status` seven-value ENUM wrapping :947-948;
`invoice.referensi_tipe` six values at :940 and `referensi_id` **bare, no FK** at
:941. `master_metode_pembayaran.tipe` nine values at :929, `biaya_admin_flat`
:931, `biaya_admin_persen` :932, `status_aktif` :933. `refund.status`
four-value ENUM at :980. The three advanced entities: `booking.status`
:515-516, `resep.status` :751-752, `pesanan_obat.status` :810-811, and
`konsultasi.status` :542-543.

### And proved against the LIVE database, not only the file

`the three-table block ends where the DDL says, and the plan range is past it`
walks `information_schema.STATISTICS` for `pembayaran` and asserts that
`nomor_referensi` and `gateway` appear in **no** index at all, and that no
non-`PRIMARY` index is `UNIQUE`. A claim about a missing index is a claim about
the database, so it is read off the database.

---

## 2. The interface and the mock

```
App\Services\Payment\PaymentGatewayService      (interface)
  nama(): string
  dapatkah(string $kode): bool
  createTransaction(Invoice, MasterMetodePembayaran): array
  verifyWebhook(Request): array

App\Services\Payment\MockPaymentGatewayService  (implements it)
  HEADER_TANDATANGAN = 'X-Payment-Signature'
  PREFIX_REFERENSI  = 'MOCK'      PANJANG_REFERENSI = 100   (:963)
                                        PANJANG_VA = 30       (:964)
  createTransaction() -> ['gateway', 'nomor_referensi', 'jumlah', 'instruksi', 'toko']
  verifyWebhook()    -> ['nomor_referensi', 'status', 'jumlah', 'gateway', 'payload']
```

Bound in `AppServiceProvider::configurePaymentGateway()`, selected by
`config('payment.gateway')`. `the gateway is an interface with the two methods
the plan names, and the mock implements it` asserts the container resolves the
**interface**, that the resolved object is the mock, that
`PembayaranController.php` does **not** name a concrete gateway, and that the
provider file does.

**The two config keys are different things and the test says so apart:**

| key | what it is | value |
| --- | --- | --- |
| `payment.gateway` | the **IMPLEMENTATION** named in `implementasi` | `mock` |
| `payment.gateway_pembayaran` | the **ENUM value** written to `pembayaran.gateway` (:965) | `midtrans` |

`nama()` returns the second, and the test asserts
`nama() === config('payment.gateway_pembayaran')` **and**
`nama() !== config('payment.gateway')` - because conflating them is the single
most confusing fact about the file, and the first version of this test did
conflate them and failed.

### The signature scheme, stated so it can be checked

```
header   X-Payment-Signature
message  the raw request body, byte for byte  (Request::getContent())
key      config('services.payment.gateways.{gateway}.webhook_secret')
digest   hash_hmac('sha256', <bytes>, <key>)      lowercase hex
compare  hash_equals(<expected>, <received>)
```

Verified **before** the body is parsed, so an unsigned request cannot choose its
own error. A test signs a body with one trailing space added and asserts a 401 -
the failure that proves the HMAC is over the wire bytes and not over a
re-encoding of a decoded array.

`each of the four gateways verifies against its own secret` asserts the four
secrets are **pairwise different** and each is at least 32 characters, and
`an ABSENT signature, an empty one and a wrong-length one are all rejected`
asserts that a body signed with `doku`'s key is refused on `midtrans`, that an
empty header is refused, and that a 16-character truncation is refused.

---

## 3. The duplicate-webhook test: exactly one state change, identical second response

`a duplicate webhook produces EXACTLY ONE state change and an identical second
response`.

**Why a timestamp alone cannot prove it.** `pembayaran` declares **no
`diubah_at`** (:958-973 - `dibuat_at` at :969 is its only stamp), so two writes
inside the same second are indistinguishable from one. The clock is therefore
frozen at `2026-03-11 10:00:00`, the first delivery is made, **the clock is
advanced one hour**, and the second delivery is made. Any re-application now
writes different values, which turns the criterion into a measurement.

**Three independent counters:**

| # | counter | after the 1st delivery | after the 2nd |
| --- | --- | --- | --- |
| 1 | `pembayaran.dibayar_at` (:967) | `2026-03-11 10:00:00` | **unchanged** |
| 2 | `invoice.lunas_at` (:950) | `2026-03-11 10:00:00` | **unchanged** |
| 3 | `audit_log` `update` rows per table | 1 / 1 / 1 for `pembayaran` / `invoice` / `booking` | **1 / 1 / 1** |

Counter 3 needs no column of its own: `Pembayaran`, `Invoice` and `Booking` are
all inside the derived audit scope (`AuditScope::auditedModels()`), and
`AuditLogWriter` writes `aksi='update'` (:1121), `tabel_target` (:1122),
`record_id` (:1123) for every Eloquent update. It is the only one of the three
that is not a timestamp, and it is the one that catches a re-write that happens
to land on the same instant.

**The second response.** Rather than asserting a list of equalities that could
miss a difference nobody thought to check, the test **diffs the two decoded
bodies recursively** and requires the difference set to be exactly:

```php
expect($beda)->toBe(['data.duplicate', 'message']);
```

with the envelope key set, the key ORDER, the HTTP status (200 both times) and
the content-type all asserted identical, and `success` true on both. `message`
is the second legitimate difference and it is a **decision with a cost stated in
the test**: a body saying "payment updated" about a delivery that changed nothing
is a lie a provider's log would preserve. The cost is that a client keying logic
off `message` rather than off `data.duplicate` will misread a retry - and the
envelope documents `message` as human-readable and `data` as machine-readable.

The retry is deliberately a **different byte sequence** (`percobaan: 2` plus a
`catatan` key) so the payload assertions are measurements, not accidents of
formatting.

## 4. The invalid-signature test: zero state change

`a forged signature is rejected with NO state change AND no database read`.

The body is valid and the signature is computed with **a key the application does
not hold**. The load-bearing assertion is not about rows:

```php
DB::listen(function (QueryExecuted $q) use (&$pernyataan): void { $pernyataan[] = $q->sql; });
$res = pay45Kirim(PAY45_GATEWAY, $raw, $server);
expect($sentuh)->toBe([]);   // no statement naming pembayaran / invoice / booking
```

**Why rows would not do.** A handler that read a payment row and *then* rejected
the signature leaves the rows unchanged too, so "no state change" would pass it.
Requiring **zero statements** cannot. This is the test the brief calls the most
important one, and it is the one that orders the controller: `verifyWebhook()`
runs before `terimaWebhook()` and before any query.

Row-level assertions follow for the reader who does not believe the statement log:
`status` still `pending`, `dibayar_at` NULL, `webhook_payload` NULL, invoice
still `menunggu_pembayaran`, `lunas_at` NULL, booking still `menunggu_pembayaran`,
and the `audit_log` update count unchanged from its baseline of 0.

**This test caught a bug in the test.** The first version let `pay45Webhook()`
sign for it and then called the result "forged". It **failed**, because the
request was not forged at all - it settled the invoice. The failure output is in
the transcript below and is the sharpest single piece of evidence that the
assertion has teeth.

## 5. The `webhook_payload` overwrite decision

**DECISION: a re-delivery does NOT overwrite it.** The column (:968) is the
forensic record of the delivery that **caused** the state change, and there is
exactly one of those. A retry carries no new information, and letting it write
would replace the evidence with a near-copy.

It is a *structural* consequence rather than a policy: the duplicate branch
returns before any assignment to `$pembayaran->webhook_payload`, so there is no
line to get wrong. `a re-delivery does NOT overwrite webhook_payload: first body
wins` makes it falsifiable - the retry carries `percobaan: 2` and its own
`catatan` key, and the stored JSON is asserted to still read `percobaan: 1` with
**no** `catatan` key.

**One honest caveat, stated rather than hidden.** `webhook_payload` is MySQL's
native `JSON` type and MySQL **normalises on store** - it reorders object members
and drops insignificant whitespace. So the round-tripped value is semantically
identical and positionally different, and the test compares with
`toEqualCanonicalizing` rather than `toBe`. Asserting the order would be
asserting MySQL's storage internals; asserting that every key survived with its
value is asserting the property the forensic record depends on.

## 6. Concurrency: the race is real, and what closes it

**Sequential delivery is exact** - that is the acceptance criterion above.

**Concurrent delivery is a genuine race.** Two identical deliveries arriving at
the same instant CAN both read `pending` before either writes, and both write.
The schema cannot prevent it: `nomor_referensi` (:963) and `gateway` (:965) are
in **no index and under no unique constraint** (proved above), and the plan
forbids adding one. **No index and no constraint was added by this todo.**

**What closes it: the row lock.** `PaymentService::huntap()` is the only place in
the class that looks a payment up, and it does so with
`SELECT ... FROM pembayaran WHERE gateway = ? AND nomor_referensi = ? FOR UPDATE`
**inside** the transaction. A locking read resolves against the **LATEST
COMMITTED version**, not the transaction's own snapshot, so:

1. two concurrent deliveries both reach that line;
2. the first takes the row lock and applies;
3. the second **blocks there**, not at its own `save()`;
4. when it unblocks, the first has committed, and the re-read returns the now
   **terminal** status, so the duplicate branch runs and writes nothing.

`PaymentConcurrencyTest.php` proves all three legs with **two genuine, interleaved
MySQL transactions on two separate connections**, both at `REPEATABLE READ` with
`innodb_lock_wait_timeout = 1`:

1. `the real settlement path BLOCKS on the pembayaran row lock a concurrent
   delivery holds` - connection A holds the lock, connection B runs the **actual
   `PaymentService::terimaWebhook()`** and raises MySQL **1205**, writing nothing
   to any of the three rows.
2. `a NON-locking read of the same row does NOT wait, which is why the FOR UPDATE
   is the defence` - holding the same lock, a plain consistent read returns
   **instantly** with `pending`. Under REPEATABLE READ a consistent read takes no
   locks at all. So a `first()` instead of a `lockForUpdate()` would let the
   second delivery read `pending` and write - the exact race.
3. `the row lock is a CURRENT read: a blocked delivery wakes up seeing the settled
   status` - a locking read issued after A commits returns `berhasil`, not `pending`.

**The test had a gap and the mutation harness found it.** The first version
asserted only that B raised 1205, and it **survived deleting `lockForUpdate()`
entirely** - because without the locking read B blocks on the `UPDATE` instead and
raises the *same* 1205 with the same "B wrote nothing" outcome. The test now
records B's statements through `beforeExecuting` (not `DB::listen`, which fires
only **after** a statement returns and therefore never sees the blocking one) and
asserts both that B reached the locking `SELECT` and that B issued **no** `update
pembayaran` at all. The negative assertion is the one with teeth; see the harness
section.

**The cost that is NOT hidden.** A `SELECT ... FOR UPDATE` whose predicate matches
no index takes **gap locks** on the range it scans. The key's two columns are in
no index, so the statement is a full scan of `pembayaran` and the lock covers
every row it examines - two unrelated payments can briefly serialise behind each
other. That is a throughput cost, not a correctness one, and it is the price of
the DDL being read-only. **The one change that would matter is
`INDEX (gateway, nomor_referensi)`**, which would turn the scan into a point
probe. It is NOT made here, and is recorded here instead.

---

## 7. What the webhook decides

| webhook `status` (:966) | `pembayaran.status` | `invoice` | referenced entity | `dibayar_at` |
| --- | --- | --- | --- | --- |
| `berhasil` | `berhasil` | `lunas` + `lunas_at` | advanced | stamped |
| `gagal` | `gagal` | **untouched** | **untouched** | **NULL** |
| `kedaluwarsa` | `kedaluwarsa` | **untouched** | **untouched** | **NULL** |
| `refund` | **422** | - | - | - |
| `pending` | **422** | - | - | - |

`gagal` and `kedaluwarsa` are symmetric, and the reason is stated: they differ to
the **patient** (a retryable lapse versus a declined card) and not to anything
this table can act on. `invoice.kadaluarsa` (:947-948) is an expiry of the
**invoice**, which a lapsed payment attempt is not - and nothing in the schema
reacts to `jatuh_tempo` (:949) either, which is todo 51's clock.

`refund` and `pending` are refused by `PembayaranStatus::bisaDisettle()`, which
is the whole gate - so the decision exists in exactly one place. Settling a
provider's "refunded" callback here would mark an invoice `lunas` with **no
`refund` row at all** (`refund.status` at :980 is a separate four-value ENUM
belonging to a later todo), and `pending` over `pending` would produce an
`updated` event and an audit row for no change.

### The referenced entity, and why `konsultasi` is absent

`invoice.referensi_id` is a **bare column with no foreign key** (:941) and none
of the three targets has a trigger, a generated column or an event, so nothing
but this code moves them. `PaymentService::LANJUT` is a `public const` map, and a
test asserts the closed set:

| `referensi_tipe` | to | from | line |
| --- | --- | --- | --- |
| `booking` | `terjadwal` | `menunggu_pembayaran` | :515-516 |
| `resep` | `diproses` | `aktif` | :751-752 |
| `pesanan_obat` | `diproses` | `menunggu_pembayaran` | :810-811 |

**`konsultasi` is deliberately absent, and that is a DDL fact rather than an
omission**: `konsultasi.status` is a six-value ENUM at :542-543
(`menunggu_dokter, berlangsung, menunggu_resep, selesai, dibatalkan, gagal`) and
**none of the six means "paid"**. Advancing one would be inventing a state the
schema does not have, so a consultation invoice settles with the invoice `lunas`
and the consultation untouched, reported as `advanced: false`.

The advance is **guarded on the source state**, so a late delivery for a
`dibatalkan` booking records the money and does **not** resurrect the appointment
- the invoice still goes `lunas`, because the money is real.

`pesanan_obat`'s **stock decrement is deliberately NOT here**: it reads
`resep_item` and `apotek_stok` and owns the guarded
`UPDATE ... WHERE jumlah_stok >= ?`, and `apotek_stok.jumlah_stok` is a **signed**
`INT` with no `CHECK`. This todo advances the status and nothing else, and says
so rather than half-doing a decrement it cannot guard.

## 8. Initiation

`POST /api/v1/invoice/{id}/bayar` -> **201**. `auth:sanctum` **and**
`permission:pembayaran.bayar`, which `RbacCatalog` grants to `pasien` and
`superadmin` and to nobody else - so the gate refuses `dokter`, `apoteker` and
`admin` without locking out the one account type that owns the invoice. `tipe:`
is refused for the same reason as everywhere else: it cannot express "is this
invoice yours", which `PasienRecordAccess::ownPasien()` plus the tenant-scoped
lookup answer.

**Ownership**: another patient's invoice is a **404** (the tenant filter IS the
query, so it is not found rather than refused); an account with no `pasien` row
is a **403**, raised before the lookup so the two answers stay in the order that
does not leak; an anonymous caller gets the guard's **401**.

**The admin fee is applied ONCE, at mint time.** `invoice.total` (:946) already
contains `biaya_admin` (:944), computed by `InvoiceService` from
`biaya_admin_flat` (:931) and `biaya_admin_persen` (:932). The test asserts
`biaya_admin = '2500.00'` and `total = '152500.00'`, then asserts the stored
`pembayaran.jumlah` is **byte-identical** to the stored total. Re-applying the fee
at payment time is the specific way this endpoint would charge twice.

A second `pending` row on one invoice is a **422** naming the existing
reference - two virtual accounts the patient could pay, and `pembayaran` has no
uniqueness that would stop it.

**Money is a JSON string at the boundary**, asserted on the raw body:
`expect($res->getContent())->toContain('"jumlah":"152500.00"')`.

## 9. Money at the API boundary

Every amount is a JSON **string**. `Uang::parse` is the gate, so:

| body | answer | asserted |
| --- | --- | --- |
| `"jumlah": "150000.00"` | settles | yes |
| `"jumlah": 150000.55` (fractional number) | **422** `Nilai uang harus berupa string desimal, bukan float.` | yes |
| `"jumlah": 150000` (whole number) | **settles** - asserted, not left to a reader's guess | yes |
| `jumlah` disagreeing with the stored total | **422** `Jumlah pembayaran tidak sesuai dengan total invoice.`, nothing written | yes, and the correction then settles the still-`pending` row |

The whole-number case is asserted **on purpose**. `Uang` has always converted an
`int`, and an integer literal carries no precision to lose; refusing it would
contradict that class's documented behaviour for no gain. The rule the endpoint
serves - money is transported so that nothing rounds - is satisfied by an
integer. Both cases are in the test so neither is left ambiguous.

## 10. The route table

| method | uri | name | middleware |
| --- | --- | --- | --- |
| POST | `api/v1/invoice/{id}/bayar` | `invoice.bayar` | `auth:sanctum`, `permission:pembayaran.bayar` |
| POST | `api/v1/webhook/payment/{gateway}` | `webhook.pembayaran` | **none** - HMAC over the raw body |

`{id}` is `whereNumber` (:937 is a `BIGINT UNSIGNED` primary key).
`{gateway}` is `->whereIn('gateway', PembayaranGateway::untukRute())` - read from
the **enum**, not typed, so the constraint and the schema cannot drift, and
compiled by the router into a regex the test reads off the route.

**`{gateway}` is client-supplied, which is why the constraint is on the ROUTE.**
A segment outside the four is a **router** 404 and the controller is never
reached - so the code that reads a secret is unreachable for one. The test names
`midtrans2`, `MIDTRANS`, `bank_bjb` and `""` (all 404) and each of the four legal
values (none 404).

## 11. Envelope

`{success, data, message}` with `meta` a top-level sibling and **absent** on both
payment routes. A 422 carries multiple messages per field through
`ApiResponse::error()`'s `(object)` cast, exactly as the project contract
requires. A `422` can carry more than one message on one field - the malformed-body
test asserts `errors.nomor_referensi.0`, and the `status` refusal names all three
legal values in one message.

## 12. Unknown permission codes

`EnsurePermission` turns an unknown code into a **500**. The tripwire is
duplicated in `AuthFlowTest` and `PasienProfileTest` over the same regex, and this
todo adds `permission:pembayaran.bayar` to both closed sets. The webhook
contributes **no string at all** to either census - it carries neither a
`permission:` nor a `tipe:`, which is the point.

---

## 13. TDD: red, then green

**RED** (`git 4c7b3ac`, before any implementation file existed):

```
{"tool":"pest","result":"failed","tests":22,"passed":2,"assertions":124,
 "duration_ms":17130,"failed":15,"errors":5}
```

Every one of the 15 failures and 5 errors is a **missing class or a missing
route**, not a broken assertion:

- `Class "App\Enums\PembayaranGateway" not found` (3 tests)
- `Class "App\Services\Payment\PaymentGatewayService" does not exist` (1)
- `Target class [App\Services\Payment\PaymentGatewayService] does not exist` (1)
- `Failed asserting that an array has the key 'POST api/v1/invoice/{id}/bayar'`
- `Expected response status code [200] but received 404` on twelve endpoint tests
  - with `{"success":false,"message":"Resource not found.","errors":[]}`

The **two that passed** are the DDL citation tests, and that is the point of them:
they read `telemedicine_test.sql` and confirm the plan's `:970` is really `:972`,
that `nomor_referensi` carries no UNIQUE and no index, and that `pembayaran` has no
`diubah_at`. A DDL assertion cannot fail before the code exists, because the
claim is about a file.

Two test bugs were found and fixed **before** the implementation, so that the
RED was clean: `pay45As()` returned a header array where a `TestCase` was needed
(`postJson() on array`), and the unique-index probe counted `PRIMARY` as a
dedupe key.

**GREEN**: `22/22, 371 assertions`; with the concurrency file, `25/25, 395`.

### Bugs the tests found in MY OWN code, after the implementation

| # | what the test caught | fix |
| --- | --- | --- |
| 1 | `bacaEntitas(): ?App\Models\Model` - **no such class exists**; every entity read was a 500 | `Illuminate\Database\Eloquent\Model` |
| 2 | the forged-signature test signed with the **real** secret and settled a live invoice | sign with a key the app does not hold |
| 3 | teardown never deleted `pembayaran` (the service writes it, the fixture collector cannot see it) -> 1451 on the `invoice` delete | delete by `invoice_id`, then `refund` by `pembayaran_id` |
| 4 | the "anonymous" probe rode a leftover bearer token **and** a cached guard, so it answered 403 instead of 401 | `pay45TanpaAuth()`: `forgetGuards()` + `flushHeaders()` |
| 5 | `DB::listen()` returns **void** on laravel/framework 13.33 - calling its return value is a `TypeError` | register without capturing |
| 6 | `(string) null === ''`, so `->toBeNull()` on a cast could never pass | drop the cast |
| 7 | the concurrency teardown re-opened the wrapper **before** deleting the RBAC seed, so the delete was rolled back and the next test failed on a 1062 | delete, then re-open - the order is written down |

---

## 14. Mutation harness, control first

`C:\Users\axioo\AppData\Local\Temp\opencode\mutation45.php`.

**The control runs first and the harness refuses to continue without it**, because
a mutation that silently changes nothing leaves the suite GREEN and a green suite
is indistinguishable from a mutation the tests did not catch. Every mutation
asserts its replacement landed **exactly once** (verified by re-reading the file
after the write) and reports a failed application as `MUTATION-ERROR`, never as a
pass. It also reports `result`, `failed`, `errors` **and** the lengths of the
`failures` / `error_details` arrays, because the pest JSON reporter **omits**
`failed` and `errors` when both are zero.

```
=== CONTROL (unmutated) ===
PaymentWebhookTest          PASSED tests=22 passed=22 failed=0 errors=0 (lists: 0/0)
PaymentConcurrencyTest      PASSED tests=3  passed=3  failed=0 errors=0 (lists: 0/0)
control green: YES

=== MUTATIONS ===
M1 drop lockForUpdate() from huntap()          FAILED tests=3  passed=2 failed=1 errors=0
M2 signature check becomes a no-op             FAILED tests=22 passed=19 failed=3 errors=0
M3 remove the terminal-status dedupe branch    FAILED tests=22 passed=19 failed=3 errors=0
M4 re-delivery DOES overwrite webhook_payload  FAILED tests=22 passed=19 failed=3 errors=0
M5 drop the stored-amount comparison           FAILED tests=22 passed=21 failed=1 errors=0
M6 drop the referenced-entity advance          FAILED tests=22 passed=20 failed=2 errors=0
M7 gateway route constraint accepts anything   FAILED tests=22 passed=20 failed=2 errors=0
M8 initiation writes gateway from the request  FAILED tests=22 passed=13 failed=9 errors=0

mutations caught: 8/8

=== RE-VERIFICATION (all reverted) ===
PaymentWebhookTest          PASSED tests=22 passed=22 failed=0 errors=0
PaymentConcurrencyTest      PASSED tests=3  passed=3  failed=0 errors=0
restored green: YES
```

**The first harness run found a real gap.** M1 **survived**: removing
`lockForUpdate()` left all three concurrency tests green, because B then blocks
on the `UPDATE` and raises the same 1205. That is the harness doing exactly what
it is for - the exception assertion was not distinguishing the two
implementations. The test was rewritten to assert **where** the collision happens
(via `beforeExecuting`, not `DB::listen`) and M1 now dies. Without the
control-first harness this gap would have shipped.

Two harness bugs found and fixed: `$root` is a top-level variable and PHP function
scope does not inherit it, so all eight mutations reported `MUTATION-ERROR` (the
guard working, the harness useless - now a `define()`); and `.bak` is read before
it is written.

## 15. Byte-level scans and the token audit

**Byte-level non-ASCII gate**, raw-byte reads, no `mb_*` and no encoding
normalisation: every byte checked individually against printable ASCII plus
tab/LF/CR, plus a BOM check. Over the **17 files this todo authored or edited,
271,560 bytes**:

```
scanned files=17 bytes=271560
non-ascii/control violations=0
```

**The count is 17 and the first report of it said 16, and the correction is
recorded here rather than quietly applied.** The earlier run of this gate listed
16 files and 260,844 bytes because it ran before `PaymentConcurrencyTest.php`
existed (commit `5cd0b12`) and because `AppServiceProvider.php` was omitted from
the file list by an oversight. A byte gate that silently covers fewer files than
the commit does is worse than no gate, because it reports "0 violations" for a
subset and reads as a whole-tree result. The 17-file figure is the one that
matches the diff, and it is the figure above.

No BOM, no control bytes, no non-ASCII. This is the gate that matters here
because of the failure mode this project has already been bitten by - `referencia`
written for `referensi` is pure ASCII and an encoding scan cannot see it, which is
why the token audit below exists at all.

**Token audit** against `telemedicine_test.sql` through the project's own
`App\Support\Schema\SqlSchemaParser`, over the same 17 files, with **comments
stripped** so a citation in prose is not counted as an identifier. Two buckets:
exact resolution, and a **NEAR** bucket at edit distance 1-2 from a DDL table or
column name that is not equal to any of them - which is the bucket that catches a
dropped or doubled character.

```
files=17
exact DDL identifiers resolved=101
near-miss DDL identifiers=2
  NEAR  kadaluwarsa_at -> kedaluwarsa_at in app/Http/Resources/PembayaranResource.php
  NEAR  nama_bank      -> nama_brand      in app/Services/Payment/MockPaymentGatewayService.php
near-miss ENUM values=1
  ENUM  midtrans2      -> midtrans       in tests/Feature/Payment/PaymentWebhookTest.php
```

All three are **explained, and none is a corruption**:

- `kadaluwarsa_at` - a key this todo **invents** on `PembayaranResource`. It is
  DERIVED: `pembayaran` has no expiry column (the five statuses at :966 are the
  whole vocabulary and a stale `pending` row is not one of them) and nothing in
  the schema reacts to time, so "stop showing this virtual account" is computed
  from `dibuat_at` (:969) plus `config('payment.kedaluwarsa_detik')` and published
  so a client cannot invent a different one.
- `nama_bank` - a key the mock **invents** inside the virtual-account payload. The
  nearest DDL name is `artikel.nama_brand`, an unrelated column.
- `midtrans2` - **deliberately**, the test's illegal gateway name that must 404.

**The enum-value bucket** is asserted in the suite as well, not only here:
`the three payment enums are exactly what the DDL declares, in its order` compares
`PembayaranGateway::nilai()`, `PembayaranStatus::nilai()` and
`InvoiceStatus::nilai()` against `pay45Enum(...)` with `toBe`, which checks
**order as well as membership**, so a reordered enum fails.

## 16. Test output

```
php artisan test tests/Feature/Payment
{"tool":"pest","result":"passed","tests":25,"passed":25,"assertions":395,"duration_ms":25318}

php artisan test --filter=PaymentWebhookTest
{"tool":"pest","result":"passed","tests":22,"passed":22,"assertions":371,"duration_ms":20577}

php artisan test --filter=PaymentConcurrencyTest
{"tool":"pest","result":"passed","tests":3,"passed":3,"assertions":24,"duration_ms":11698}

php artisan test
 [derive] migrations=81 Schema::create calls=81 extracted=81 | CREATE VIEW calls=4
          extracted=4 | contract tables=75 views=2 | derived-missing tables=0 views=0 | registry=7
{"tool":"pest","result":"passed","tests":1020,"passed":1020,"assertions":16754,"duration_ms":550507}

php artisan route:list --path=api/v1/invoice     exit 0, 1 route
php artisan route:list --path=api/v1/webhook     exit 0, 1 route
php artisan route:list                          70 api/v1 rows
php artisan sehatly:verify-schema               exit 0, PASS - 75 tables, 2 views verified
```

**1020 is not 945.** The 945 was the count at HEAD `1af5df5`. This todo adds 25
(22 + 3), and todo 46 (prescription checkout, stock, tracking) landed 50
concurrently in the same tree. The baseline was re-measured on this executor's
private database at the start of the task: **945/945 PASSED, 15519 assertions**,
0 drift - so the 975 non-45 tests here are the same ones that were green then.

**A transient 35-error run is recorded because it happened.** One full run
reported 35 errors, all `RoleAssigner: RbacCatalog::ROLES names pasien but
'roles' holds no such row` in `CheckoutTest`. They were **not reproducible**: the
file passed 35/35 alone, 38/38 next to `PaymentConcurrencyTest`, and the very next
full run was 1020/1020. The cause was the concurrent executor's in-flight state -
`app/Services/Pasien/PasienRecordAccess.php` was, at one point during this task,
**syntactically broken** (an orphaned `return $anggota; }` at :239-240) and
flip-flopped between broken and clean across several runs, which surfaced as a
`ParseError` inside a 500 on my own endpoints. It is their file and was not
edited; the flip-flop is the whole explanation and it is recorded rather than
smoothed over.

## 17. `git show --stat`

```
4c7b3ac  test(api): failing spec for the payment gateway and idempotent webhook
 tests/Feature/Payment/PaymentWebhookTest.php | 1157 +++++++++
 tests/Feature/Payment/payment-helpers.php    |  666 +++++

a0be435  feat(api): add payment gateway abstraction and idempotent webhook
 app/Enums/InvoiceStatus.php                        |   92 +
 app/Enums/PembayaranGateway.php                    |   90 +
 app/Enums/PembayaranStatus.php                     |  138 +
 app/Http/Controllers/.../PembayaranController.php  |  217 +
 app/Http/Requests/Payment/BayarInvoiceRequest.php |   93 +
 app/Http/Resources/PembayaranResource.php          |   86 +
 app/Providers/AppServiceProvider.php               |   63 +
 app/Services/Payment/MockPaymentGatewayService.php |  436 +
 app/Services/Payment/PaymentGatewayService.php     |  160 +
 app/Services/Payment/PaymentService.php            |  584 +
 app/Services/Payment/TandaTanganWebhookTidakValid  |  101 +
 config/payment.php                                 |   76 +
 config/services.php                                |   82 +
 tests/Feature/Pasien/PasienProfileTest.php         |   59 +

5cd0b12  test(api): prove the webhook dedupe race with two real transactions
 tests/Feature/Payment/PaymentConcurrencyTest.php | 437 +

43407ed  test(api): extend the four route censuses for todo 45 and todo 46
 tests/Feature/Auth/AuthFlowTest.php        |  13 +
 tests/Feature/Pasien/PasienProfileTest.php |  11 +
 tests/Feature/Resep/ResepTodo39Test.php    |   6 +
 tests/Feature/Resep/ResepTodo40Test.php    |  19 +-
```

**`routes/api.php` carries todo 45's two routes and is NOT in the list above.**
That is deliberate and it is worth recording. Todo 46 was mid-edit of the same
file when this todo appended its block, and staging it alone would have put
todo 46's in-flight `PesananObatController` references into HEAD **without their
classes** - a broken checkout. So the routes were left unstaged while every other
file was committed, and todo 46's own commit `5dd5b56` then carried the block in
with their classes, leaving HEAD consistent. Verified:
`git show HEAD:routes/api.php` contains `invoice/{id}/bayar`,
`webhook/payment/{gateway}` and `PembayaranGateway::untukRute()`.

## 18. Deviations, stated rather than buried

1. **`App\Http\Requests\Payment\BayarInvoiceRequest`**, not the plan's flat
   `App\Http\Requests\BayarInvoiceRequest`. All 46 request classes in this
   project live in a per-module subdirectory and this would have been the only
   one at the root. `App\Http\Resources\PembayaranResource` matches the plan's
   path exactly, because resources are flat here.
2. **`PesananObatResource`, `PesananObatTrackingResource` and `StokObatResource`**
   are todo 46's and this todo neither authored nor touched them.
3. **The plan's QA scenario (c)** - "send `gateway=doku` when the payment was
   created for `midtrans` and assert 404" - is implemented as
   `a gateway that does not match the payment is a 404, and an unknown reference
   is a 404`. The body is signed **correctly for `doku`**, which is the only
   interesting version: an unsigned one would 404 on the router and prove nothing
   about the dedupe key.
4. **`pesanan_obat` status advance added beyond the plan's two examples.** The
   plan says "e.g. a `booking` to `terjadwal` and a `resep` to `diproses`". The
   order has the same `menunggu_pembayaran` -> `diproses` edge at :810-811, and
   leaving it out would mean a paid medicine order sits in
   `menunggu_pembayaran` forever. **The stock decrement is NOT included** - see
   section 7.

## 19. Things NOT done, on purpose

- **No index and no constraint was added.** No migration, no column, no
  `database/migrations/` edit, no `database/seeders/` edit.
- `telemedicine_test.sql` untouched - SHA-256 re-read and byte-identical.
- `mobile/` was not created; `web/` and `packages/` untouched.
- `php artisan serve` was never run.
- `migrate:rollback` was never run. The private database was built with
  `migrate --force` + `db:seed --force`; `db:seed` is the documented entry point
  and no `TRUNCATE` was issued by hand.
- `phpunit.xml` untouched - the per-run database is a `$env:DB_DATABASE` override
  on the command line.
- `markTestSkipped` is not used anywhere; **zero skipped tests**.
- No file this todo did not create was deleted.
- `bootstrap/app.php` was **not** edited: the 401 for a bad signature comes from
  the framework's own `AuthenticationException` render path, which is already
  there for every `api/*` request. Adding a render branch would have put error
  shape in a fifth place.

## 20. What a later todo should know

- **`INDEX (gateway, nomor_referensi)` on `pembayaran` would be the single highest-value
  change** for this endpoint: it turns the settlement's locking read from a full
  scan with gap locks into a point probe. Forbidden here, recorded here.
- **`config/services.php` ships development webhook secrets**, each prefixed
  `UBAH` so a grep for a hard-coded secret finds the comment that names them. A
  production deployment must override all four. There is deliberately **no
  startup check** that refuses a default in place: such a check would only fire
  outside `local`, which is exactly where it would be untested.
- **`webhook_payload` has no index and no query surface.** Every dispute about a
  payment is a `SELECT ... WHERE nomor_referensi = ?` full scan today.
- **The `qr_string` a `qris` method gets has no column to live in.** It is
  returned to the client and not persisted. That is a schema limitation, not an
  oversight, and it is the one thing about the mock's output a real provider
  integration would immediately need a column for.
- **A patient cannot pay with `cod` or `tunai`.** `metode_tipe_qr` covers
  `qris` and `gerai_retail`; everything else gets a virtual account, and the
  method-to-channel mapping is config because the schema provides none.
