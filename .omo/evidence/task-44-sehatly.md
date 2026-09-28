# Todo 44 - Sehatly Telemedicine Invoice and Promo Validation

Resumed from checkpoint `8ad15b1`, which preserved 16 files of todo 44 work with no
evidence file. This session read all of it, verified it, fixed the one inherited
regression, and produced the evidence. The code itself was already in place and is
credited to the previous executor; the mutation harness, the gates and the fix below
are this session's.

## Acceptance criterion

- An **invalid promo is rejected** - proved five ways, one rule per refusal, so the
  four reportable keys are distinguishable in a single 422.
- An **expired window is rejected** - proved at the **second**: the last instant
  inside the window is accepted and mints a redemption, and one second earlier is
  refused naming `jendela_waktu`.

## DDL citations, read from the file and asserted line by line

All read from `telemedicine_test.sql` directly, SHA-256
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` - **byte-identical
to the value the brief records, verified after the run.** The plan's `:NNN` citations
were not trusted; `InvoicePromoTest::every_ddl_citation_this_todo_argues_from_is_at_the_line_the_file_says`
resolves each one through `SqlSchemaParser` and each is asserted against the raw line
with `inv44AssertLine` / `inv44AssertLineLacks`. The negative half is the load-bearing
half for a claim about something **missing**.

### `invoice` (lines 936-956)

| line | content | why it matters |
| --- | --- | --- |
| 936 | `CREATE TABLE invoice (` | the table this todo mints rows in |
| 938 | `nomor_invoice VARCHAR(30) NOT NULL UNIQUE` | the only unique key besides the PK; drives the 3-attempt retry |
| 939 | `pasien_id BIGINT UNSIGNED NOT NULL` | the ONLY foreign-keyed column |
| 940 | `referensi_tipe ENUM('booking','konsultasi','resep','pesanan_obat','lab_permintaan','home_care')` | six members, four reachable |
| 941 | `referensi_id BIGINT UNSIGNED NOT NULL COMMENT 'Polimorfik'` | **BARE - no FOREIGN KEY. This is the whole polymorphic story.** |
| 942-945 | `subtotal` / `diskon` / `biaya_admin` / `biaya_pengiriman` all `NOT NULL DEFAULT 0` | four columns that DO default |
| 946 | `total DECIMAL(14,2) NOT NULL` | **`NOT NULL` with NO DEFAULT** - asserted with `assertStringNotContainsString('DEFAULT', ...)` |
| 947-948 | `status ENUM(...) NOT NULL DEFAULT 'menunggu_pembayaran'` | seven members, and it DOES default |
| 953 | `FOREIGN KEY (pasien_id) REFERENCES pasien(id)` | the only FK on the table |
| 954 | `INDEX idx_invoice (pasien_id, status)` | not unique |
| 955 | `INDEX idx_ref (referensi_tipe, referensi_id)` | **not unique** - asserted to lack `UNIQUE`; the duplicate guard is therefore application-level with a known race |

### `master_promo` (lines 985-998) - the promo is a RULE SET, not a lookup

| line | column | the rule it carries |
| --- | --- | --- |
| 987 | `kode VARCHAR(30) NOT NULL UNIQUE` | the code exists at all; also the `max:30` field rule |
| 989 | `tipe_diskon ENUM('persen','nominal','gratis_ongkir')` | what the discount is worth |
| 990 | `nilai DECIMAL(12,2) NOT NULL` | the rate or the amount |
| 991 | `min_transaksi DECIMAL(12,2) NOT NULL DEFAULT 0` | the purchase must be big enough |
| 992 | `maks_diskon DECIMAL(12,2) NULL` | **NULL is UNCAPPED**, and 0.00 is a cap of nothing |
| 993 | `kuota_total INT UNSIGNED NULL` | **NULL is UNLIMITED**, and 0 is EXHAUSTED - three different meanings |
| 994 | `kuota_per_user TINYINT UNSIGNED NOT NULL DEFAULT 1` | NOT NULL, so 0 is a real ceiling nobody meets |
| 995-996 | `mulai_at` / `selesai_at DATETIME NOT NULL` | the window; DATETIME with no timezone, so operator-authored WIB |
| 997 | `status_aktif TINYINT(1) NOT NULL DEFAULT 1` | the switch |

### `promo_redemption` (lines 1000-1010) - why the quota is a race

| line | content |
| --- | --- |
| 1004 | `invoice_id BIGINT UNSIGNED NOT NULL` - so a validation call has nothing to attach a row to |
| 1007-1009 | the three foreign keys |
| - | **eleven lines, no UNIQUE key and no `INDEX` statement anywhere.** `InvoicePromoTest::promo_redemption_declares_no_UNIQUE_and_no_INDEX_on_any_of_its_eleven_lines` walks the raw DDL for the range and asserts it. The only indexes are the three single-column ones MySQL synthesises for the three FKs. |

Also read: `master_metode_pembayaran` 926-934 (`biaya_admin_flat` :931,
`biaya_admin_persen` :932, `status_aktif` :933), `pembayaran` 958-973,
`refund` 975-983, `users.tipe` :139, and the four source tables'
`pasien_id` at :501 (booking), :539 (konsultasi), :747 (resep), :801 (pesanan_obat).

## The polymorphic reference is BARE - there is no Eloquent relation

`invoice.referensi_id` (line 941) carries `COMMENT 'Polimorfik'` and **no FOREIGN KEY**;
the only FK on `invoice` is `pasien_id` (line 953). Checked before assuming, as the brief
requires:

- `app/Models/Invoice.php` declares exactly three relations - `pasien()`,
  `pembayaran()`, `promoRedemption()`. There is **no `morphTo`**, and one could not
  work: Eloquent's convention wants a `referensi_type` column and this schema names it
  `referensi_tipe` with six values, and wants a real FK to resolve the id.
- `InvoicePromoTest::the_polymorphic_reference_has_no_Eloquent_relation_and_the_service_does_not_invent_one`
  walks the declared relations **reflectively off the class** and asserts the closed set
  of three, so a fourth would fail rather than pass unnoticed.

So `InvoiceService` resolves the reference through
`InvoiceService::SUMBER_REFERENSI` - a `public const` whitelist of the four reachable
types, each naming the model whose table the id points into. `lab_permintaan` and
`home_care` are legal ENUM members naming tables this module does not reach; they are
refused with a message that says they are **out of scope**, which is a different problem
from an invalid value and needs a different fix. A seventh, invalid value is refused as
invalid and the six legal ones are named. Three outcomes, three messages.

## Endpoints

One route, and `route:list --path=api/v1/promo` answers exactly 1:

| method | uri | name | middleware | answers |
| --- | --- | --- | --- | --- |
| POST | `api/v1/promo/validasi` | `promo.validasi` | `auth:sanctum` only | 200 always for a readable request |

The write that applies a promo is `InvoiceService::buat()`, reached from todo 45's
payment and todo 46's checkout, because a promo cannot be applied to an invoice that
does not exist yet and no endpoint in Modules 1-5 creates one on demand. Inventing a
second route to reach it would be inventing an invoice-creation surface the plan does
not describe.

`route:list` exits 0. `sehatly:verify-schema` exits 0 with **0 drift** -
`PASS - 75 tables, 2 views verified. Nothing was written.`

## The invalid-promo test, with the boundary asserted

`PromoRuleSetTest::the_total_quota_boundary_the_last_use_is_accepted_and_the_next_one_is_refused`

```php
'kuota_total' => 2, 'kuota_per_user' => 5, 'tipe_diskon' => 'nominal', 'nilai' => '5000.00'

// uses one and two of two
$pertama = $layanan->buat(...);   // diskon '5000.00'
$kedua  = $layanan->buat(...);   // diskon '5000.00'
expect(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(2);

// the THIRD is the first invalid one, and the refusal names the counts
$ketiga = inv44Tangkap(fn () => $layanan->buat(...), ValidationException::class);
expect($ketiga->errors())->toHaveKey('kuota')
    ->and($ketiga->errors()['kuota'][0])->toBe('Kuota promo telah habis (2/2).')
    ->and(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(2);
```

The last valid use is asserted to **write a redemption and grant the discount** - the
criterion is not "no error". The first invalid use is asserted to **write nothing** and
to name the counter `(2/2)`, not a bare "expired". A quota of 0 is also probed
(`Kuota promo telah habis (0/0).`) because 0 and NULL are different things on line 993.
`kuota_per_user` is probed the same way and additionally across three patients to show
the counter does not leak between them.

## The expired-window test, with the boundary asserted

`PromoRuleSetTest::the_window_boundary_to_the_second_the_last_instant_inside_is_accepted_and_the_next_one_is_refused`

The clock is frozen at `2026-03-11 10:00:00` **UTC** and the fixtures are
**Asia/Jakarta** wall-clock, with the relationship asserted rather than commented
(`expect($sekarang->format('Y-m-d H:i:s'))->toBe('2026-03-11 17:00:00')`).

**Closing edge** - `selesai_at = now` (WIB):
- last valid use: `diskon === '15000.00'` **and** exactly 1 redemption row
- first invalid use: `selesai_at = now - 1 second` gives a 422 whose `errors()` has
  `jendela_waktu`, whose message is `Promo sudah berakhir.`, whose other keys do
  **not** include `kuota`, and which leaves 0 redemption rows

**Opening edge** - `mulai_at = now` accepted (`diskon === '15000.00'`);
`mulai_at = now + 1 second` gives `Promo belum dimulai.` and again no `kuota` key.

**Both edges at once** - `mulai_at = selesai_at = now` is accepted, so the inclusive
comparison is proved on a one-second window rather than asserted.

`PromoRuleSetTest::the_window_is_read_as_Asia_Jakarta_so_seven_hours_of_offset_is_the_whole_difference`
is the regression: compared against `now()` in UTC, a promo going live at 09:00 WIB
would read as live from 02:00 WIB. `PromoService::sebagaiWallClock()` re-parses the
stored literal in the named zone with `format()` + `Carbon::parse()` - `setTimezone()`
would SHIFT the instant, which is the bug the method exists to avoid.

## All four rejection cases distinguishable in the 422

`PromoRuleSetTest::each_rule_fires_alone_and_all_four_reportable_keys_are_distinguishable_in_one_422`

Each fixture breaks exactly one rule, so each refusal can only be about that rule:

```php
expect(array_keys($hanyaKode->errors()))->toBe(['kode'])
    ->and(array_keys($hanyaAktif->errors()))->toBe(['status_aktif'])
    ->and(array_keys($hanyaJendela->errors()))->toBe(['jendela_waktu'])
    ->and(array_keys($hanyaMinimum->errors()))->toBe(['min_transaksi'])
    ->and(array_keys($hanyaKuota->errors()))->toBe(['kuota']);
```

None of the five wrote anything (`invoice` and `promo_redemption` both 0), so no 422 is
masking a partial write. Then the two orderings the brief names, which is what proves
the rule set is not short-circuiting on a lucky first hit:

| case | fixture | 422 keys |
| --- | --- | --- |
| **in-window but over-quota** | whole month, `kuota_total => 0` | `['kuota']` only |
| **in-quota but out-of-window** | expired, `kuota_total => 10`, `kuota_per_user => 10` | `['jendela_waktu']` only |
| **all four at once** | inactive + expired + over minimum + over quota | `['status_aktif', 'jendela_waktu', 'min_transaksi', 'kuota']` |

**Multiple messages per field are preserved.** `errors()` is
`array<string, list<string>>`, and two real cases put two messages on one key:

- An **inverted window** - there is no CHECK on `mulai_at <= selesai_at` anywhere in
  the DDL, so `mulai_at > selesai_at` is legal storage and at any instant between the
  two it satisfies "not started" AND "already expired" at once:
  `errors()['jendela_waktu']` has count 2, `Promo belum dimulai.` then
  `Promo sudah berakhir.`
- A patient who has spent **both** the total allowance and their own - the counters are
  independent, since `promo_redemption` has no unique key on `(promo_id, pasien_id)`:
  `errors()['kuota']` has count 2, `Kuota promo telah habis (2/2).` then
  `Kuota promo untuk pengguna ini telah habis (1/1).`

## Quota atomicity: the decision

**Decision: consumption is atomic, enforced by `SELECT ... FOR UPDATE` on the
`master_promo` row, taken as the FIRST statement of the apply transaction. The counting
reads are locking reads too.**

`promo_redemption` has no unique key and no index statement in its eleven lines, and the
brief forbids adding one. Nothing in the schema can stop two transactions from both
counting and both inserting, so the guard has to be the lock:

1. `MasterPromo::query()->whereKey(...)->lockForUpdate()->firstOrFail()` is first. The
   row always exists, so there is a single row to serialise on, and every consumer -
   any patient, any service - passes through it. The row is re-read under the lock
   rather than the caller's instance trusted, because an instance hydrated before the
   transaction opened may be several redemptions out of date and `kuota_per_user` is
   the one value whose whole purpose is to change.
2. The counters are locking reads too, and that is not decoration. The isolation level
   is REPEATABLE READ, where a non-locking read resolves against the transaction's own
   snapshot: a second transaction that blocked on the promo lock would wake up counting
   a snapshot taken **before** the first committed, and would happily overspend.
   `FOR UPDATE` reads the latest committed version. The test sets and asserts
   `REPEATABLE-READ` on **both** connections so this is proved rather than inherited.
3. The counters are `pluck('id')->count()`, **never** `->count()->lockForUpdate()`:
   `compileAggregate()` skips `compileLock()`, so that call is a **silent no-op**, and a
   silent no-op on a quota check is the worst possible failure for one. The test asserts
   the emitted SQL carries `for update`, carries `limit`, and does **not** carry
   `count(`.
4. The counters are bounded by the quota (`limit($kuota)`), so the query is a range probe
   over the index MySQL synthesises on `promo_id` and the answer is exact - "have I
   reached the ceiling" is the same question as "are there at least N of these". Work is
   O(quota), not O(all redemptions).

**What this does NOT give: idempotency.** A redelivered request consumes a second quota,
because `promo_redemption` has no `dihapus_at`, no `dibatalkan` and no unique key to key
a token on. Stated rather than glossed; `PromoQuotaConcurrencyTest` asserts both columns
are absent from the live table, and it is the same limitation the plan records for
`pembayaran.nomor_referensi` in todo 45.

### The tests that prove it

`PromoQuotaConcurrencyTest::the_apply_path_locks_the_master_promo_row_FIRST_and_the_counting_reads_lock_too`
- asserts **exactly three** locking reads (the promo row, the total counter, the per-user
  counter) - and sets `kuota_total => 5` precisely so all three fire, because with it NULL
  only two would and "three or more" would be satisfied by a counter that had stopped
  running entirely
- asserts the FIRST locking statement is the `master_promo` read
- asserts the counter SQL is a `pluck` with a `LIMIT` and no `count(`

`PromoQuotaConcurrencyTest::a_second_transaction_really_collides_on_the_promo_lock_so_the_quota_cannot_be_double_spent`
- two real connections; while A holds the lock mid-apply, B runs the **real**
  `InvoiceService::buat()` for a **different** patient and the same promo with
  `kuota_total => 1`
- asserts B dies on a MySQL lock-wait timeout (driver code 1205) and that A completes
- asserts exactly ONE redemption and ONE invoice afterwards - B wrote neither
- asserts the next caller is refused on `Kuota promo telah habis (1/1).`, i.e. on the
  **quota** and not on a lock

`PromoQuotaConcurrencyTest::without_the_lock_the_same_choreography_overspends_which_is_what_makes_the_lock_load_bearing`
- **the control.** Identical choreography with the lock not taken: both transactions
  count the same zero and both insert, ending at TWO redemptions against a quota of one.
  Without this, the previous test could pass with the lock removed and would be proving
  nothing.

Mutation `M5-promo-lock-dropped` (**KILLED**) and `M6-counting-read-locks-dropped`
(**KILLED**) are the same claim reached from the other direction.

## The null-total decision

`subtotal`, `diskon`, `biaya_admin` and `biaya_pengiriman` declare `NOT NULL DEFAULT 0`
(lines 942-945). **`total` declares `NOT NULL` with no default (line 946)**, while
`status` does default (line 948). Both are asserted against the raw lines, the negative
one with `inv44AssertLineLacks`.

**Decision: all five money columns are written explicitly on the FIRST insert, and
`status` is written explicitly too even though the DDL defaults it.**

- Trusting any default for `total` produces a row MySQL refuses outright with error
  **1364** under `STRICT_TRANS_TABLES`. The suite proves this by issuing exactly that
  INSERT and asserting the driver code.
- `status` is written anyway. The DDL default is real and `BookingService` relies on it,
  but relying on it here would mean a **column default decides an unpaid invoice's
  state**, and a later migration changing that default would silently change what an
  unpaid invoice is. Mutation `M10-status-left-to-ddl-default` is **KILLED**.
- Because the promo is applied **after** the invoice row is written - it must be, since
  `promo_redemption.invoice_id` is `NOT NULL` and needs a real id - the first insert
  carries a **provisional** total and a second update corrects it, all inside one
  transaction. The provisional values are computed, not left to defaults, so a first
  attempt that omitted `total` would fail with 1364 rather than with the unique-key
  collision the retry exists for, and the retry would be testing the wrong thing.
- An **empty `lines` array is REFUSED** rather than producing a `0.00` invoice: the
  column would accept it, and a patient handed a zero-value invoice is a defect no
  constraint catches. Mutation `M12-empty-lines-accepted` is **KILLED**.
- A total below zero is refused, and a discount is clamped to the purchase so it cannot
  get there. `DECIMAL(14,2)` is signed, so MySQL would store a negative total happily.

## Money as JSON strings

Every amount is a decimal **string** at the API boundary (`"150000.00"`), because a
JSON number has already lost precision by the time PHP parses it and a `float` loses
more on every multiplication. `Uang` carries them through bcmath. `parse()` is
deliberately strict: bool, float, array and null are refused, and `'1e3'`, `'100,00'`,
`' 100.00 '` and `'10.005'` are refusals rather than normalisations - each is a different
currency or a different document's convention wearing the same field name. The regex is
anchored at both ends; mutation `P5-money-anchor-unanchored` is **KILLED**.

Rounding is **half up**, because `bcdiv` truncates toward zero and for money a third of
a cent is half a cent the patient is owed. `setengahNaik()` scales at 2, adds half,
truncates and scales back - scale 0 would drop the cents first and round the wrong way.
Mutation `M14-percents-truncates` is **KILLED**.

`biaya_admin_persen` is charged on **`subtotal - diskon`**, not on `subtotal`: an admin
fee is a charge on what the patient actually pays, so charging it pre-discount makes
"2.5% admin fee" quietly mean 2.5% of more whenever a promo is in play. The test asserts
both the discounted and the undiscounted figure so the movement is visible; mutation
`P6-admin-fee-charged-on-pre-discount` is **KILLED**.

## Envelope and ownership

`ApiResponse::success($data, $message, $status, $meta)` with `meta` a **top-level
sibling**. The promo endpoint carries **no** `meta` - a calculation is not a list, and
`PromoValidasiTest::the_success_envelope_carries_no_meta_because_a_calculation_is_not_a_list`
asserts its absence.

- **Another patient's invoice is 404**, and it is the *same* 404 as one that never
  existed: the tenant filter **is** the query (`Invoice::whereBelongsTo($pasien)`), so
  the row is not found rather than refused. A 403 would confirm it exists - a
  cross-tenant existence oracle.
- **An account with no `pasien` row is 403**, raised by `PasienRecordAccess::ownPasien()`
  **before** the invoice query, so the two answers stay in the order that does not leak.
- **An unauthenticated caller is 401** from `auth:sanctum`, not a 422 on `kode`.

### The guards, justified

`auth:sanctum` and **nothing else**, which is a decision with three parts:

1. `permission:promo.validasi` is **not** used even though the code is a real entry in
   `RbacCatalog::PERMISSIONS`. `ROLE_PERMISSIONS` grants it to `admin` and `superadmin`
   and to **nobody else** - the `pasien` list does not contain it. A
   `permission:promo.validasi` gate would therefore 403 the **one account type that
   owns a `pasien` row to validate against**, and the endpoint would be reachable by
   exactly the callers who cannot use it. Stated from the catalogue, not assumed:
   `PromoValidasiTest::the_permission_code_exists_but_is_NOT_granted_to_a_patient_which_is_why_it_is_not_a_gate`
   reads `ROLE_PERMISSIONS` directly and prints the grant list.
2. `tipe:pasien` is refused for the reason `PasienRecordAccess` argues at length: it
   answers "which account type is this", which cannot express "is this invoice yours",
   and a `pasien`-typed account with no `pasien` row passes it and is refused by the
   service anyway - a second, strictly weaker gate answering one question.
3. **`perawat` and `kurir` hold no role at all** in `RbacCatalog::ROLES`, so *any*
   `permission:` would lock those two out permanently - which is precisely why none is
   used here. They are refused with the 403 from `ownPasien()`: a fact about rows they
   do not own rather than a role they lack, which is the honest shape of the refusal.
   Reported as a data change in `app/Support/Rbac/` plus a re-seed, and not this todo's
   to make.

`RbacCatalog` uses **Indonesian verbs** (`Lihat Resep`, `Verifikasi Resep`), and
`EnsurePermission` answers an **unknown code with a 500**, not a 403. Every
`permission:` and `tipe:` string in `routes/api.php` is resolved against `RbacCatalog`
by the census test in `PasienProfileTest`, duplicated in `AuthFlowTest` on purpose - a
closed set only one file watches is one a later refactor can quietly reopen.

## The endpoint is a PURE CALCULATION, and the schema is what forces that

The plan calls for a validation call that writes nothing, and the reason is not caution -
it is `promo_redemption.invoice_id BIGINT UNSIGNED NOT NULL` (line 1004) with
`FOREIGN KEY (invoice_id) REFERENCES invoice(id)` (line 1009). A validation call has no
invoice to attach a redemption to, and minting one to hold the row is precisely the write
the endpoint is defined not to make. `PromoValidasiTest` asserts **zero**
`promo_redemption` rows **and** zero `invoice` rows after a successful validation and
again after four repeated calls - the only way to prove the word "pure" rather than
assert it in a docblock. Mutation `P11-validasi-endpoint-applies-the-promo` is
**KILLED**.

It answers **200 with `valid: false`**, not 422. A caller asking "would this code work
for me?" is asking a question, and "no, and here is exactly which rule it breaks" is the
answer; a 422 would be a refusal to answer. The reasons arrive as `data.alasan` with
machine-readable `kode` values. The apply path is deliberately the opposite: there the
caller asked for something to happen, so `PromoHitungan::tolak()` raises a 422.

The numbers are **recomputed from the stored invoice**, never taken from the request: it
carries `kode` and `invoice_id` and nothing else, so a client cannot talk the server into
a discount on a purchase it did not make.

## The inherited regression this session fixed

`8ad15b1` added `'POST api/v1/promo/validasi'` to the `api/v1` route-table census in
`PasienProfileTest` but placed todo 40's `'POST api/v1/resep/{id}/verifikasi'` beside it,
so the closed set carried one URI **twice** while todo 40's own four-route block carried
only three. `toEqualCanonicalizing` catches a duplicate as loudly as a missing entry.

**RED** (full suite, inherited from `8ad15b1`, `tests=945 passed=943 failed=2`):

```
'POST api/v1/pasien/anggota-keluarga'
+'POST api/v1/promo/validasi'
 'POST api/v1/rekam-medis/{id}/amandemen'
```

then, after the first fix, a second RED exposing that todo 40's four **middleware**
expectations had never been registered at all:

```
GET api/v1/pasien/resep unexpectedly carries a permission/tipe guard.
```

**GREEN**: `tests/Feature/Pasien/PasienProfileTest.php` **102/102 passed, 1172
assertions**; full suite **945/945 PASSED, 15519 assertions, exit 0**.

A concurrent session then committed `11c3da8` on top of this working tree, and its own
edit re-added `'GET api/v1/pasien/resep' => ['permission:resep.lihat']` at the end of the
guard map where the entry already sits at the head of todo 40's four. **A duplicate key
in a PHP array literal is not an error and not a warning - the last one silently wins** -
so it changed nothing at runtime while making the closed set unreadable. Removed in
`38a2e77`.

## TDD transcript

One inherited RED (above) and its GREEN. No new behaviour was written in this session,
so there is no second red-green cycle to record; every other property in this file was
delivered by the previous executor and is verified here by mutation rather than by a new
failing test. That is stated rather than dressed up.

## Mutation harness, control first

The pest JSON reporter **omits** `failed` and `errors` when the count is zero, so the
parser reads `(int) ($json['failed'] ?? 0)` - absent means zero - and the **control is
run first and asserted green before any mutation is attempted**, because if the control
ever read red the harness is broken and every result after it is void. On the two
parses that go wrong in opposite directions: `?? 999` would call every green run red and
turn all 28 mutations into false kills, while reading `result` alone would call a red run
green and hide a survivor. The `+` trap is avoided with `array_merge` throughout: PHP
8.4 throws `TypeError` on `'a' + 'b'` but `'a' + 'b'`-shaped *arrays* silently keep the
left operand, so `+` would have dropped half of every record.

`runSuite` passes `proc_open` an argv **array** plus `cwd` plus an env map rather than
building a `cmd /c "cd /d ..."` string - on Windows the string form makes cmd.exe apply
its own quote-stripping to a command that already contains quotes. The env map replaces
the child's environment wholesale, so it carries `getenv()` plus the override; omitting
`TEMP`/`TMP` makes proc_open's own pipe redirection fail with `sf_proc_00.out.lock`.

### Pass 1 - 16 mutations, control green, 14 killed

```
== CONTROL (no mutation) ==  result=passed tests=48 passed=48 failed=0 assertions=619
M1-window-end-inverted                   KILLED    failed=12
M2-window-start-inverted                 KILLED    failed=11
M3-total-quota-off-by-one                KILLED    failed=6
M4-per-user-quota-off-by-one             KILLED    failed=3
M5-promo-lock-dropped                    KILLED    failed=2
M6-counting-read-locks-dropped           KILLED    failed=1
M7-null-quota-read-as-zero               KILLED    failed=6
M8-min-transaction-read-from-discounted  SURVIVED  failed=0
M9-gratis-ongkir-credited-to-diskon      KILLED    failed=2
M10-status-left-to-ddl-default           KILLED    failed=1
M11-negative-total-guard-dropped         SURVIVED  failed=0
M12-empty-lines-accepted                 KILLED    failed=1
M13-missing-source-is-404-not-422        KILLED    failed=1
M14-percents-truncates                   KILLED    failed=2
M15-duplicate-invoice-guard-dropped      KILLED    failed=1
M16-maks-diskon-null-read-as-zero        KILLED    failed=14
RESTORE CHECK: all three files RESTORED
```

### Pass 2 - the two survivors, re-probed: 9 mutations, control green, 6 killed

Both survivors were mistakes in the **harness**, not gaps in the tests, and both are
worth stating rather than hand-waving.

- **M8** subtracted a flat `1000.00` from the subtotal, which makes `min_transaksi`
  **stricter**, not looser, so it cannot produce the defect its own description claims.
  The real question - "can a discount buy the minimum it should not unlock?" - is
  structurally impossible here, because `min_transaksi` is evaluated before any discount
  exists. Re-probed as `P1` (invert the comparison): **KILLED, 10 failures**.
- **M11** removed the negative-total guard alone and nothing changed, because
  `Uang::min($diskon, $subtotal)` already clamps a discount to the purchase, so
  `subtotal - diskon` can never be negative and the guard is **unreachable**.
  `P3` removes the clamp **and** the guard together: **KILLED, 2 failures** - so the
  *pair* is what keeps the total non-negative, and that is now proved rather than
  asserted.

```
P1-min-transaksi-comparison-inverted          KILLED    failed=10
P2-negative-total-guard-dropped               SURVIVED  failed=0   (== M11; unreachable)
P3-discount-clamp-and-negative-guard-dropped  KILLED    failed=2
P4-money-float-accepted                       SURVIVED  failed=0
P5-money-anchor-unanchored                    KILLED    failed=2
P6-admin-fee-charged-on-pre-discount          KILLED    failed=1
P7-errors-collapse-to-one-message-per-field   KILLED    failed=1
P8-kode-length-uncapped                       KILLED    failed=1
P9-validasi-endpoint-writes-a-redemption      SURVIVED  failed=0
```

`P4` removed the `is_float` refusal and nothing changed, because the **next** line -
`if (! is_string($masuk))` - refuses a float too, with a different message. Two layers
exist and the outer one is redundant. `P9` flipped `hitung()`'s `$kunci` to true, which
adds a **row lock, not a write** - only `terapkan()` writes, and `hitung()` never calls
it - so it could not test what it claimed.

### Pass 3 - the three survivors, re-probed: 3 mutations, control green, 3 killed

```
P10-money-type-gate-removed                  KILLED    failed=2
P11-validasi-endpoint-applies-the-promo      KILLED    failed=6
P12-apply-path-stops-writing-the-redemption  KILLED    failed=13
RESTORE CHECK: all three files RESTORED
```

`P10` removes the `! is_string` gate that is actually load-bearing. `P11` makes the
controller call `terapkan()` - the real "the pure endpoint writes" defect. `P12` removes
the `promo_redemption` insert so the apply path grants a promo it never records.

**Totals: 28 mutation runs, 26 KILLED, 2 SURVIVED, and both survivors diagnosed and then
killed by the corrected probe. 0 unaccounted.** `git status` is clean after every pass.

## Gates

**Byte-level non-ASCII**, raw-byte reads (not a decoded string, so a lone `0x80-0x9F`
continuation byte cannot hide), every byte `>= 0x80` reported with offset and hex, in
two phases - `raw` over whole files and `exe` over a copy with comments and string
literals blanked via `token_get_all`:

```
gate=byte-level-non-ascii phase=raw files=18 offending=0 verdict=CLEAN
gate=byte-level-non-ascii phase=exe files=18 offending=0 verdict=CLEAN
```

No BOM. Executors here have shipped corrupted identifiers that read as correct -
`referencia` for `referensi` alone caused 48 test failures - so this gate is a hard
byte test, not an eyeball.

**Token audit via `SqlSchemaParser`**, every snake_case token in the todo-44 executable
code (comments and strings excluded) resolved against the parsed DDL:

```
=== BUCKET 1: tokens that RESOLVE against telemedicine_test.sql ===  count=19
  biaya_admin  biaya_admin_flat  biaya_admin_persen  biaya_pengiriman  invoice_id
  kuota_per_user  kuota_total  maks_diskon  min_transaksi  mulai_at
  nilai_diskon  nomor_invoice  pasien_id  promo_id  referensi_id
  referensi_tipe  selesai_at  status_aktif  tipe_diskon

=== BUCKET 2: snake_case tokens NOT in the DDL ===  count=29
  array_column array_filter array_key_exists array_keys array_merge array_pad
  array_unique array_values base_path get_class get_debug_type in_array is_array
  is_bool is_float is_int is_string mb_strlower password_hash preg_match
  preg_match_all random_bytes random_int str_contains str_pad str_repeat
  str_starts_with strict_types var_export
```

All 29 are PHP built-ins or `strict_types`. **Zero corrupted schema identifiers**, and
both traps the brief names are in bucket 1 resolving correctly: `referensi_id` and
`referensi_tipe` (:941, :940), never `referencia` or `referensi`.

**The enum-value bucket**, listed for 17 `(table, column)` pairs across the whole
surface and then hard-gated in both directions for the three columns the code writes:

```
invoice.referensi_tipe         line 940  6 members, 6 present in code
invoice.status                 line 947  7 members, 7 present in code
master_promo.tipe_diskon       line 989  3 members, 3 present in code

persen / nominal / gratis_ongkir           in-code=yes  declared-in-ddl=yes  OK
booking / konsultasi / resep                in-code=yes  declared-in-ddl=yes  OK
pesanan_obat / lab_permintaan / home_care   in-code=yes  declared-in-ddl=yes  OK

gate=token-audit ddl_tables=75 ddl_columns=351 enum_pairs_listed=89
              correct=19 unknown=29 hard_failures=0 verdict=CLEAN
```

A declared member the code never names is rot waiting to happen, and a literal the code
uses that the DDL does not declare is exactly the corruption class this gate exists for.
Both are hard failures; `hard_failures=0`.

## Test output

```
# Invoice suite, private DB telemedisin_db_t44
{"tool":"pest","result":"passed","tests":48,"passed":48,"assertions":619,"duration_ms":74311}

# Full suite, default DB from phpunit.xml (DB_DATABASE untouched)
{"tool":"pest","result":"passed","tests":945,"passed":945,"assertions":15519,"duration_ms":406779}
TEST_EXIT=0
```

**945 = 872 baseline + todo 40's 25 + todo 44's 48.** No regression. `php artisan test`
and `php artisan route:list` both exit 0; `php artisan sehatly:verify-schema` exits 0
with 0 drift.

### The per-executor database, and the 2 Unit failures it causes

Run on a private `telemedisin_db_t44` the full suite reports 2 failures, both in
`tests/Unit/Console/VerifySchemaCommandTest.php`, and **both are the documented
environment trap, not a defect**:

`VerifySchemaCommandTest.php:445` is named *"the verifier reads the configured database,
not a hard-coded one"* and asserts
`tableNames(DB::connection()->getDatabaseName()) === tableNames('telemedisin_db_test')`,
with the comment *"The test connection is telemedisin_db_test"*. Any per-run
`$env:DB_DATABASE` override breaks it by construction. The Unit suite does not use
`RefreshDatabase`, so it reads whatever shape the database happens to be in. Both
`telemedisin_db_t44` and `telemedisin_db_test` were confirmed to hold the **identical 82
tables**, which is what pins this on the hard-coded name rather than on the schema.

The full-suite claim is therefore made on the **default database from `phpunit.xml`**,
which is how the 872/872 baseline was measured. `phpunit.xml` is untouched. The
mutation harness runs on `telemedisin_db_t44` because it only exercises
`tests/Feature/Invoice`, which does use `RefreshDatabase`.

## Files

Created by todo 44 (all lint clean under `php -l`, PHP 8.4.17):

- `app/Services/Invoice/InvoiceService.php` - the one minting path; bare-reference
  resolution, the null-total decision, the 404-for-another-patient rule, the
  number-collision retry
- `app/Services/Invoice/PromoService.php` - the rule set; `terapkan()` locked and
  writing, `hitung()` non-locking and pure, one shared `nilai()` so preview and apply
  cannot drift
- `app/Services/Invoice/PromoHitungan.php` - the value object; `errors()` **derived** from
  `alasan` so a reason cannot exist in one and not the other
- `app/Support/Uang/Uang.php` - bcmath money, strict parsing, half-up rounding
- `app/Http/Requests/Promo/ValidasiPromoRequest.php` - `kode` required, `max:30` from
  :987, **not** `exists`
- `app/Http/Controllers/Api/V1/PromoController.php` - the one route
- `routes/api.php` - one appended route block, last in the file
- `tests/Feature/Invoice/InvoicePromoTest.php` (15), `PromoRuleSetTest.php` (15),
  `PromoValidasiTest.php` (13), `PromoQuotaConcurrencyTest.php` (5),
  `invoice-helpers.php`

Touched for todo 44: `tests/Feature/Pasien/PasienProfileTest.php` (route and guard
censuses), `tests/Feature/Auth/AuthFlowTest.php` and
`tests/Feature/Resep/ResepTodo39Test.php` (in the `8ad15b1` checkpoint).

## Constraints observed

- `database/migrations/`, `database/seeders/` and `telemedicine_test.sql` **untouched** -
  SHA-256 re-verified as `AEFE2247E00F...` after the run. `migrate:rollback` never run.
- **No index and no constraint added.** The quota is serialised with a row lock precisely
  because the schema may not carry a unique key, and the duplicate-invoice guard is
  documented as TOCTOU-guarded with a known race rather than dressed up as a guarantee.
- No `mobile/`, no Flutter, `web/` and `packages/` untouched, `sehatly` untouched, no
  `php artisan serve`, no 8000-class port.
- **No `markTestSkipped` and no skip** - 945 passed, 0 skipped.
- No file deleted that this todo did not create.
- `.omo/plans/` is orchestrator-owned; no checkbox marked.
- Committed with an explicit pathspec naming only this todo's files. `git add -A` never
  used. `.playwright-mcp/` is untracked and not this todo's.

## Unfinished / deliberately out of scope

1. **No idempotency key on promo consumption.** A redelivered request consumes a second
   quota. The schema offers nothing to key one on and adding a column is forbidden.
   Stated in `PromoService`'s docblock and asserted against the live table.
2. **The duplicate-invoice guard is TOCTOU-guarded, not a guarantee.** `INDEX idx_ref`
   (:955) is not unique and may not be made so. Two simultaneous `buat()` calls for the
   same reference can both insert. Documented, not hidden.
3. **`perawat` and `kurir` hold no role in `RbacCatalog::ROLES`**, so any `permission:`
   locks them out of the whole API. This is why the promo route carries none. Fixing it
   is a data change in `app/Support/Rbac/` plus a re-seed - **reported, not made**, and
   it affects every other route, not only this one.
4. **The negative-total guard is unreachable** while the discount clamp is in place.
   Both are kept: the clamp is the invariant, the guard is the tripwire if the clamp is
   ever weakened. Proven unreachable by `P2` and covered by `P3`.
5. **`Uang::parse`'s `is_float` branch is redundant** with the `! is_string` branch that
   follows it. Harmless, and both were removed together in `P10` to show the inner one is
   the one that matters.
6. **No OpenAPI or `mobile/` client work** - todos 45 and 53.
