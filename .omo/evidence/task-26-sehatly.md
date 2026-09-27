# Task 26 - `SlotAvailabilityService`

Todo 26 of `.omo/plans/sehatly-telemedicine-platform.md`. Built test-first: the
spec was written and observed failing before a line of the service existed.

Branch `feat/sehatly-telemedicine`. PHP 8.4.17 (`C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe`,
`php` is not on PATH). **laravel/framework 13.33.0**, Pest 4.

## 1. Files

| file | state | purpose |
| --- | --- | --- |
| `app/Services/Booking/SlotAvailabilityService.php` | new, 32.6 kB | the four rules |
| `app/Support/Dokter/StrBerlaku.php` | new, 7.8 kB | todo 22's STR boundary, now one spelling for both services |
| `app/Services/Dokter/DokterDirectoryService.php` | modified | its private STR predicate now delegates to `StrBerlaku` |
| `tests/Feature/Dokter/SlotAvailabilityTest.php` | new, 55.2 kB | 37 tests, 285 assertions |

Nothing else. `routes/api.php`, `database/migrations/`, `database/seeders/`,
`web/`, `packages/`, `docs/`, `docs/schema-notes.md`, `docs/migration-order.md`
and `telemedicine_test.sql` are all untouched (section 8). No controller, route,
FormRequest or resource was written - see finding **F1**.

## 2. The four rules, the DDL behind each, and the boundary decision

Every line number is from `telemedicine_test.sql` as read in this session
(1349 lines, SHA-256 verified unchanged in section 8). The plan's own
`### Verified SQL line-number index` (`:62`-`:141`) agrees with the file on
every citation used here; the only disagreements found are in the todo's prose,
recorded in section 7.

### Rule 1 - working windows (`dokter_jadwal`, `:470`-`:488`)

| what | column | line |
| --- | --- | --- |
| weekday | `hari TINYINT UNSIGNED NOT NULL COMMENT '0=Minggu s.d. 6=Sabtu'` | `:475` |
| window start | `jam_mulai TIME NOT NULL` | `:476` |
| window end | `jam_selesai TIME NOT NULL` | `:477` |
| slot length | `durasi_slot_menit SMALLINT UNSIGNED NOT NULL DEFAULT 15` | `:478` |
| capacity | `kuota_per_sesi SMALLINT UNSIGNED NULL` | `:479` |
| valid from | `berlaku_mulai DATE NOT NULL` | `:480` |
| valid to | `berlaku_sampai DATE NULL` | `:481` |
| published | `status_aktif TINYINT(1) NOT NULL DEFAULT 1` | `:482` |
| index (not a constraint) | `INDEX idx_jadwal (dokter_id, hari, status_aktif)` | `:487` |

A row is used when `hari` equals the requested date's day of week AND
`status_aktif = 1` AND `berlaku_mulai <= $tanggal` AND (`berlaku_sampai IS NULL`
OR `berlaku_sampai >= $tanggal`).

The DDL comment at `:475` is PHP's own `date('w')` numbering, which is what
`Carbon::dayOfWeek` returns, so `hari` needs no translation table. It is also
the validation the missing `CHECK (hari BETWEEN 0 AND 6)` needs: a storable
`hari` of 9 can never equal a real weekday, so such a row is inert.

**Boundary decisions, each one stated and each one tested:**

- **`berlaku_sampai` is INCLUSIVE.** It is a `DATE`, so it has no time component
  and the last moment of validity is the *end* of the date it names; the column
  is literally named `berlaku sampai`, "valid through". A window whose
  `berlaku_sampai` is exactly the requested date runs on that date. This is the
  same reading, and the same reasoning, as `str_berlaku_sampai`; it is spelled
  separately here because the two columns are unrelated and no class owns both.
- **The window end is REACHABLE.** Step condition is
  `mulai + durasi <= jam_selesai`, so a `09:00`-`09:15` window with a 15-minute
  duration offers exactly one slot, `09:00`-`09:15`. A `<` here would publish a
  doctor with no availability at all.
- **A PARTIAL TRAILING SLOT is never offered.** `09:00`-`09:50` yields three
  slots ending at `09:45`; the leftover five minutes is not a bookable
  consultation, and emitting `09:45`-`10:00` would book a patient into ten
  minutes the doctor declared they do not work.
- **A WRAP (`jam_selesai <= jam_mulai`) and a ZERO-LENGTH window publish
  nothing.** `MySQL TIME` accepts `-100:00:00` through `+838:59:59`, which is the
  hazard the plan's constraint list names at `:208`. Naive `H:i:s` parsing of a
  wrap yields a negative or beyond-24:00 value that no string comparison can
  order.
- **`durasi_slot_menit = 0` publishes nothing.** Unsigned means `0` is a legal
  stored value, and `$mulaiSlot += 0` never terminates. Measured: removing this
  guard exhausts the 512 MB memory limit (mutation **M16**, section 5).
- **A slot ending at or after `24:00:00` is dropped individually.** `24:00:00` is
  storable in a `TIME` and is not a time of day the response can publish, so a
  `22:00`-`23:59` window with 60-minute slots still offers `22:00`-`23:00` and
  drops the rest. Refusing the whole row would discard good consultations over a
  typo in the closing time.
- **SELF-OVERLAPPING WINDOWS ARE BOTH HONOURED.** There is no unique on
  `(dokter_id, hari)` and `idx_jadwal` is an index, not a constraint. Both rows
  are real offerings, so both are published, each distinguished by `jadwal_id`,
  each judged against its own `kuota_per_sesi`.

**A doctor with no schedule rows has no bookable slots.** That is the answer,
not an error and not "open all day": `dokter_jadwal` is the only source of a
working window, so anything else would invent clinical availability. Decided and
tested. `getSlotTerbuka()` answers `[]`; `getJadwal()` answers seven empty days
rather than `[]`, because its seven-key shape is the contract and collapsing it
would leave a caller unable to tell "no rows" from "no such key".

### Rule 2 - LIBUR, a whole-day subtraction (`dokter_libur`, `:490`-`:496`)

| what | column | line |
| --- | --- | --- |
| owner | `dokter_id BIGINT UNSIGNED NOT NULL` | `:492` |
| the day | `tanggal DATE NOT NULL` | `:493` |
| note | `alasan VARCHAR(200) NULL` | `:494` |

There is no `status_aktif`, no unique on `(dokter_id, tanggal)` and no index
beyond the FK, so the DDL has no finer granularity than a day and a holiday
closes the day.

**Boundary decision - the holiday CLOSES the day's slots, it does not remove
them.** The plan contradicts itself here and the resolution is recorded as
finding **F2**: the prose says "subtract every date in `dokter_libur`" (which
would give an empty list) while the agent-executable acceptance criterion says
"a test with a `dokter_libur` row asserts every slot that day is `tersedia:
false`" (which means the slots are still published). The criterion is the one
that is checked, so the criterion wins: the day's candidates are still returned,
each with `tersedia = false` and `alasan = 'libur'`. An empty list could not
distinguish a holiday from a doctor who does not work that weekday, and `alasan`
exists to carry exactly that. Both readings agree that nothing is bookable.

**Boundary tests:** a `dokter_libur.tanggal` exactly equal to the requested date
closes every slot that day; one day before and one day after leave every slot
open. The comparison is per doctor, so a holiday cannot leak between doctors.

### Rule 3 - Collision, and the quota (`booking`, `:498`-`:530`)

| what | column | line |
| --- | --- | --- |
| doctor | `dokter_id BIGINT UNSIGNED NOT NULL` | `:503` |
| the day | `tanggal_kunjungan DATE NOT NULL` | `:507` |
| start | `slot_mulai TIME NOT NULL` | `:508` |
| end | `slot_selesai TIME NOT NULL` | `:509` |
| state | `status ENUM(8 values)` | `:515`-`:516` |
| window link | `jadwal_id BIGINT UNSIGNED NULL` | `:504` |
| index | `INDEX idx_booking_dokter (dokter_id, tanggal_kunjungan)` | `:528` |

- **Consuming statuses: six, not two.** `dibatalkan` and `kadaluarsa` release a
  slot - the patient is not coming and the payment window closed. The other six
  consume it. The test *derives* the six from the DDL ENUM minus the two rather
  than restating them, so a ninth status breaks the suite until the service has
  been asked the question.
- **Overlap is HALF-OPEN, strict at both ends:**
  `booking.slot_mulai < slot.slot_selesai AND booking.slot_selesai > slot.slot_mulai`.
  **Boundary decision:** a booking ending exactly when a slot begins does not
  collide, and a booking starting exactly when a slot ends does not collide.
  Adjacent consultations are legal; `<=`/`<=` would make a doctor with
  back-to-back bookings look over-committed. A one-minute overlap on either side
  does collide.
- **Quota, not a boolean.** Free means the overlapping consuming count is
  **strictly less than** `dokter_jadwal.kuota_per_sesi` (`:479`), read as 1 when
  NULL. **Boundary decision:** the k-th booking fills a quota of k, so with
  `kuota_per_sesi = 2` the slot is `tersedia: true` after one booking and
  `false` after the second. A `count === 0` check is the trap this schema sets -
  it makes every `kuota_per_sesi > 1` window permanently unbookable. Measured as
  mutation **M1**.
- **The count is per SLOT, not per day.** Three bookings against a quota of 2
  close only the slots they actually overlap.
- **No `TIME()` wrapper.** The overlap is a bare `where`, never `whereTime()`,
  because `TIME()` folds a beyond-24:00 value back into the 24-hour clock, which
  is the `:208` hazard. Asserted on the emitted SQL.

### Rule 4 - STR (`dokter.str_berlaku_sampai`, `:414`)

`str_berlaku_sampai DATE NOT NULL`. The column is on `dokter` (`:409`-`:435`).

**The boundary itself is not decided here.** Todo 22 established it and this
todo extracted it to `App\Support\Dokter\StrBerlaku` so the codebase has one
spelling of it. The operator, the inclusivity and the fail-closed NULL handling
all live in that one class now, and `DokterDirectoryService` calls it too. Its
docblock carries the original argument; `DokterDirectoryService`'s docblock now
says it is the *origin* of the decision rather than a second copy.

- **`>=`, INCLUSIVE.** A `DATE` cannot lapse at an instant during the day, so
  the last moment of validity is the end of the date it names. Expiring *on* the
  consultation date is still licensed. A `>` would hide a currently-licensed
  doctor for a whole day, which is a consultation denial rather than a safety
  win.
- **NULL is refused, fail-closed.** The DDL makes NULL impossible (1048), and
  the predicate is written to be *well-defined* rather than merely currently
  unreachable: an unknown expiry is not evidence of validity.
- **The reference day is the CONSULTATION DATE, not today.** This is the one
  deliberate difference from the directory and it is the most important decision
  in this todo. The directory asks "may this doctor be listed now?" and compares
  against today. This method asks "may a consultation proceed on date D?" and
  compares against D. Nothing in the schema invalidates a doctor the day the
  date passes - the plan's own constraint list names that gap at `:207` - so
  today-based filtering would let a patient book a visit months after the licence
  lapsed. Same operator, same inclusivity, same NULL handling, applied to the day
  the answer is about.
  **Boundary tests:** expiry on the requested date -> four slots; one day before
  -> `[]`; one day after -> four slots. And the pair
  (`2026-12-07` gives 4, `2026-12-14` gives `[]`) is asserted as a *pair* on
  purpose: the first can only pass while today is on or before the expiry, which
  is what stops the second passing vacuously once the licence has lapsed.
- **The refusal is an empty list, not an exception.** That mirrors
  `DokterDirectoryService::find()`, which returns `null` for STR-expired,
  unverified, absent and soft-deleted alike so an unauthenticated caller cannot
  enumerate the licence state of every account. A caller that needs to tell
  "licence lapsed" from "no schedule" needs a domain exception; that is the
  endpoint's todo (finding **F1**).

`getJadwal()` is deliberately **not** STR-gated: a weekly window template is a
profile attribute, not an offer to practise medicine, and a second gate would
mean two things to keep in step for no patient-safety gain. Both halves of that
asymmetry are asserted so it cannot become accidental.

### Rule 1b - an elapsed slot on today (the plan also names this)

When the requested date **is** today, a slot whose `jam_selesai` has already
passed is unavailable. **Boundary decision: `jam_selesai <= now`**, so a slot
ending at exactly this instant is already over. `now` is `Carbon::now()` (honours
`setTestNow()`), read once per call; "is this today" is compared against the
database's own `SELECT CURDATE()` because `config/app.php` is UTC while the schema
stores naive wall clock. A future date is untouched.

**`alasan` precedence, first match wins: `libur` > `lewat_waktu` > `penuh`.** The
first two are absolute - a day off and a finished slot cannot be booked whatever
the capacity - while capacity is a property of a slot that is otherwise real.
`null` means available.

## 3. RED then GREEN, verbatim

The spec was written first and run before `SlotAvailabilityService.php` existed.

### 3a. RED #1 - the class does not exist

```
PS> & 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe' artisan test --filter=SlotAvailabilityTest
{"tool":"pest","result":"failed","tests":34,"passed":2,"assertions":55,"duration_ms":25651,
 "errors":32,"error_details":[
  {"test":"...an_active_window_on_the_requested_weekday_becomes_evenly_spaced_slots",
   "file":"...Container.php","line":1147,
   "message":"Target class [App\\Services\\Booking\\SlotAvailabilityService] does not exist."},
  {"test":"...a_NULL_STR_expiry_is_refused__and_the_DDL_is_why_it_is_unreachable",
   "message":"Class \"App\\Support\\Dokter\\StrBerlaku\" not found"},
  ... 32 errors in total, every one of them one of those two messages ...
 ]}
```

34 tests, 2 passed, 32 errors. **Every one of the 32 is a missing-class error
and nothing else**, which is the "right reason": no assertion failed, so no
expectation was wrong, only unimplemented. The 2 that passed are the two that
read the DDL directly and never touch the service
(`the fixed dates land on the weekday the DDL comment at :475 names` and
`the DDL names every column and enum value the four rules read`) - correctly
independent, not vacuous.

### 3b. RED #2 - after the first implementation, six REAL defects

```
PS> & 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe' artisan test --filter=SlotAvailabilityTest
{"tool":"pest","result":"failed","tests":35,"passed":29,"assertions":259,"duration_ms":36697,"failed":6,
 "failures":[
  {"test":"...a_midnight-wrapping_or_zero_length_window_contributes_no_slot_at_all",
   "message":"Failed asserting that two arrays are identical. -Array &0 [] +Array &0 [0 => [...'jam_mulai' => '22:00:00' ...]"},
  {"test":"...BOUNDARY__a_booking_ending_exactly_when_a_slot_begins_does_not_collide",
   "message":"Failed asserting that false is true."},
  {"test":"...a_wide_quota_counts_only_the_bookings_that_actually_overlap_the_slot",
   "message":"... 3 => false, + 3 => true"},
  {"test":"...BOUNDARY__the_STR_boundary_is_inclusive_on_the_CONSULTATION_date",
   "message":"Failed asserting that actual size 0 matches expected size 4."},
  {"test":"...the_STR_is_compared_against_the_requested_date__not_against_today",
   "message":"Failed asserting that true is false."},
  {"test":"...BOUNDARY__a_slot_that_has_already_ended_today_is_unavailable...",
   "message":"Expecting null not to be null ."}],"errors":0}
```

This is the run worth having. It is not "class not found" - it is 29 green and
6 red with real messages, and it is what a suite that was only ever green cannot
tell you. What each one found:

1. **The STR comparison direction was INVERTED.** I had written
   `$hari->gte($kedaluwarsa)` - "has this day passed since the licence expired" -
   which is true for almost every historical date and therefore admits every
   lapsed doctor ever recorded, while refusing every far-future one. It made 20
   tests fail at once. `StrBerlaku` now spells it `str_berlaku_sampai >= $hari`,
   in SQL order, with a docblock paragraph naming the inversion as the reason
   the boundary tests are written on the exact date rather than on "some old
   date". This is the exact class of bug the boundary tests exist to catch, and
   it is why they are on the exact date.
2. A `22:00`-`25:00` window: the service offered the 7 slots that fit and dropped
   the 8th, my expectation was `[]`. The service was right (finding **F3**) and
   the test was changed to assert the 7 slots, the dropped `23:45` start, and
   that nothing starts at `23:45`.
3. The adjacency test had a second booking that legitimately filled the slot.
   Test fixture fixed to the minimal one-booking form.
4. The wide-quota test asserted a per-DAY count. Rewritten so three bookings
   overlap the window against a quota of two, which is what makes a per-day
   count fail and a per-slot count pass.
5. The elapsed-slot fixture used a 60-minute step from `08:00`, so no slot
   boundary landed on the frozen `10:07` and the assertion was about a slot that
   did not exist. The window now starts at `08:07`, and `hari` follows the
   reference day instead of being pinned to Monday, so the test holds on any
   weekday.
6. The STR reference-date test asserted a "lapsed today" that was not lapsed
   (`2026-12-06` is in the future relative to today) and would have become
   time-dependent. Rewritten as the self-guarding pair described in section 2.

### 3c. GREEN

```
PS> & 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe' artisan test --filter=SlotAvailabilityTest
{"tool":"pest","result":"passed","tests":36,"passed":36,"assertions":280,"duration_ms":36067}
```

```
PS> & 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe' artisan test --filter=SlotAvailabilityTest
{"tool":"pest","result":"passed","tests":37,"passed":37,"assertions":285,"duration_ms":36999}
```

(37 after the one test added in response to mutation **M18**, section 5.)

## 4. Boundary test list

All in `tests/Feature/Dokter/SlotAvailabilityTest.php`, Pest closure tests, so
`tests/Pest.php`'s `RefreshDatabase` (`->in('Feature')`) applies.

| # | boundary | test |
| --- | --- | --- |
| 1 | slot start: first slot starts exactly at `jam_mulai` | `BOUNDARY: the first slot starts exactly at jam_mulai and the last ends exactly at jam_selesai` |
| 2 | slot end: last slot ends exactly at `jam_selesai` (both on an exact-fit one-slot window and on a four-slot window) | same |
| 3 | partial trailing slot is never offered (`09:00`-`09:50`) | `BOUNDARY: a partial trailing slot is never offered, because it would overrun the window` |
| 4 | `berlaku_sampai` exactly on the requested date; `berlaku_sampai` one week later; `berlaku_mulai` one week earlier | `BOUNDARY: berlaku_sampai is inclusive and berlaku_mulai is exclusive-before` |
| 5 | a booking ending exactly when a slot begins does not collide | `BOUNDARY: a booking ending exactly when a slot begins does not collide` |
| 6 | a booking starting exactly when a slot ends does not collide | `BOUNDARY: a booking starting exactly when a slot ends does not collide` |
| 7 | a one-minute overlap on either side DOES collide | `a booking overlapping a slot by a single minute does collide` |
| 8 | holiday on the exact date; one day before; one day after | `BOUNDARY: a holiday on the exact date marks every slot unavailable, and one either side does not` |
| 9 | a holiday that closes a slot which is also full reports the holiday (precedence) | `BOUNDARY: a holiday closes a slot that is ALSO full, and reports the holiday` |
| 10 | STR expiry exactly on the consultation date; one day before; one day after | `BOUNDARY: the STR boundary is inclusive on the CONSULTATION date` |
| 11 | the reference day is the consultation date, not today (self-guarding pair) | `the STR is compared against the requested date, not against today` |
| 12 | the shared decider one day either side, and the NULL case | `the STR boundary has exactly one spelling, shared with the doctor directory` |
| 13 | quota 2: 0 bookings open, 1 open, 2 closed, 3 still closed | `kuota_per_sesi = 2 keeps a slot open after one booking and closes it after the second` |
| 14 | NULL quota read as 1 | `a null kuota_per_sesi is read as 1, the DDL default reading` |
| 15 | `jam_selesai == now` is over; a slot ending one minute later is not; a future date unaffected | `BOUNDARY: a slot that has already ended today is unavailable, and one ending a minute later is not` |
| 16 | a slot that would end at `24:00:00` is dropped | `BOUNDARY: a window past midnight offers the slots that fit and drops the one that would not` |
| 17 | a wrap and a zero-length window publish nothing | `a midnight-wrapping or zero-length window contributes no slot at all` |
| 18 | `durasi_slot_menit = 0` publishes nothing and does not loop | `durasi_slot_menit = 0 contributes no slot and does not loop forever` |
| 19 | an out-of-range `hari` matches no weekday | `an out-of-range hari can never match a date, which is how :205 is validated` |
| 20 | a malformed `tanggal` is refused, including the `2026-13-45` rollover | `a malformed tanggal is refused by the service, because the calendar rolls it over` |
| 21 | half-open overlap, asserted on the emitted SQL | `the emitted overlap predicate is the half-open one, on the emitted SQL` |
| 22 | the six consuming statuses, derived from the DDL ENUM | `each of the six remaining statuses does consume the slot, read from the DDL enum` |
| 23 | `23:30` is published unshifted and `ZONA_WAKTU` is not `app.timezone` | `the service publishes Asia/Jakarta wall clock and never a timestamp` |

## 5. Non-vacuity: 18 mutations, not just a green suite

A suite that was only ever green proves nothing about the tests' power, so each
rule was removed or reversed in the source, the suite was run, and the file was
restored from a pristine copy. A mutation that leaves the suite green means the
test does not pin that rule.

| id | mutation | result | killed by |
| --- | --- | --- | --- |
| M1 | quota: `count < kuota` becomes `count === 0` (the plan's named bug) | KILLED 34/36 | `kuota_per_sesi = 2 ...`, `a wide quota ...` |
| M2 | LIBUR: the whole-day subtraction is removed | KILLED 33/36 | the three holiday tests |
| M3 | `dibatalkan`/`kadaluarsa` stop being excluded | KILLED 34/36 | `dibatalkan and kadaluarsa ...`, the emitted-SQL test |
| M4 | `StrBerlaku::OPERATOR_BATAS` becomes `>` | KILLED 35/36 | `the STR boundary has exactly one spelling ...` |
| M5 | the in-memory STR boundary becomes `gt` | KILLED 33/36 | the two STR boundary tests + the one-spelling test |
| M6 | the slot step becomes exclusive (`<=` to `<`) | KILLED 18/36 | 16 tests |
| M7 | overlap start becomes closed (`<` to `<=`) | KILLED 35/36 | the emitted-SQL test |
| M8 | overlap end becomes closed (`>` to `>=`) | KILLED 35/36 | the emitted-SQL test |
| M9 | `berlaku_sampai` becomes exclusive | KILLED 35/36 | `BOUNDARY: berlaku_sampai is inclusive ...` |
| M10 | `status_aktif` dropped from the query | KILLED 35/36 | `a null berlaku_sampai ... status_aktif = 0 ...` |
| M11 | the doctor-level `durasi_default_menit` replaces the per-window value | KILLED 15/36 | 20 tests |
| M12 | the elapsed-slot `<=` becomes `<` | KILLED 35/36 | `BOUNDARY: a slot that has already ended today ...` |
| M13 | the `windowLayak` wrap guard is removed | **VACUOUS 36/36** | nothing - see below |
| M14 | the STR refusal is removed entirely | KILLED 33/36 | 3 tests |
| M15 | a malformed `tanggal` is accepted | KILLED 35/36 | `a malformed tanggal is refused ...` |
| M16 | the `durasi_slot_menit = 0` guard is removed | **FATAL** | memory exhausted, see below |
| M17 | the beyond-midnight per-slot drop is removed | KILLED 35/36 | `BOUNDARY: a window past midnight ...` |
| M18 | `getJadwal` stops filtering `status_aktif` | **VACUOUS 36/36** -> fixed | found a real gap, see below |

**M13 is vacuous and the code comment was wrong about it.** The docblock claimed
the `jam_selesai <= jam_mulai` check is what refuses a wrap. Removing it leaves
every test green, because the step condition `$mulaiSlot + $durasi <= $selesai`
already fails on its first iteration for a `22:00`-`02:00` wrap. The check is a
redundant fail-fast guard, and it is kept as a second line of defence against a
future change to the step loop - but `windowLayak()`'s docblock now says
explicitly which of the two is load-bearing and which is not, and says that this
was measured rather than assumed. Leaving the original wording would have been
the comment-accuracy defect class A.15 names.

**M16 is fatal rather than a clean failure, and that is the proof.** With the
`durasi_slot_menit = 0` guard removed, `$mulaiSlot += 0` never terminates:

```
M16 applied: the durasi_slot_menit = 0 guard is gone. Running SlotAvailabilityTest with a 100s budget.
RESULT: finished; raw tail:
Fatal error: Allowed memory size of 536870912 bytes exhausted (tried to allocate 20480 bytes) in
  ...\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Concerns\HasAttributes.php on line 1722
```

**M18 found a real gap in my own tests and it is now closed.** Nothing asserted
that `getJadwal()` filters `status_aktif`; only `getSlotTerbuka()`'s half of the
rule was covered. `getJadwal publishes only ACTIVE windows` was added and the
mutation now dies:

```
KILLED  M18 getJadwal stops filtering status_aktif
        35/37 passed, 2 failed
        a_null_berlaku__sampai_means_open_ended__and_status_aktif___0_removes_the_whole_window
        getJadwal_publishes_only_ACTIVE_windows
```

**Two of my mutations were themselves defective, and I am recording that rather
than the tidy version.** M10's first replacement was
`where('status_aktif', '!=', 'nope')`, which MySQL coerces to `<> 0` and so still
excludes the inactive row - very nearly a no-op, and it made a genuinely passing
run look like a kill. M18's first replacement appended `->whereRaw('1 = 1')` to
`$this->queryJadwal($dokter)` instead of removing the filter from
`queryJadwal()`, so it changed nothing. A mutation harness that is not itself
verified produces a table of lies, which is the same failure mode as a
rubber-stamped test.

**One more self-correction in the harness.** The first script compared
`$r.failed -eq 0`, but the pest JSON omits the `failed` key entirely on a passing
run, so `$null -eq 0` is false and two *passing* runs were labelled KILLED. The
corrected script treats an absent key as zero.

## 6. The A.26 gate: non-ASCII scan and token audit

Both halves are required. An encoding gate finds non-ASCII corruption; only the
token audit finds corrupted ASCII identifiers - the class that produced
`doker_umum` for `dokter_umum` inside an ENUM value list, which is MySQL 1264 at
insert time and invisible to any byte-value scan.

### 6a. Non-ASCII

Gate: `[^\x00-\x7F\u2013\u2014\u2022\u2026\u2192\u2212\u00A7\u2225]`, zero matches
required.

```
app\Services\Booking\SlotAvailabilityService.php
    bytes=32611 chars=32611  A.26-class violations=0  any non-ASCII=0
app\Support\Dokter\StrBerlaku.php
    bytes=7798 chars=7798  A.26-class violations=0  any non-ASCII=0
app\Services\Dokter\DokterDirectoryService.php
    bytes=23486 chars=23486  A.26-class violations=0  any non-ASCII=0
tests\Feature\Dokter\SlotAvailabilityTest.php
    bytes=55248 chars=55248  A.26-class violations=0  any non-ASCII=0

=== hard guarantee: byte check for the UTF-8 BOM and for U+200B ===
app\Services\Booking\SlotAvailabilityService.php: BOM=False contains0xE2=False
app\Support\Dokter\StrBerlaku.php:                    BOM=False contains0xE2=False
app\Services\Dokter\DokterDirectoryService.php:       BOM=False contains0xE2=False
tests\Feature\Dokter\SlotAvailabilityTest.php:         BOM=False contains0xE2=False
```

All four are pure ASCII, byte-count equals char-count, no BOM, and no `0xE2`
byte at all - so no U+2000-block character, including the U+200B ZERO WIDTH SPACE
that a previous executor's commit subject carried. That check is a raw byte scan
rather than a string comparison precisely because piping a PowerShell string
through `Out-File -Encoding utf8` once re-encoded a subject and silently dropped
the character.

### 6b. Token audit, using the project's own `SqlSchemaParser`

The DDL is parsed with `App\Support\Schema\SqlSchemaParser` - the same class the
test suite uses - and the complete set of contract identifiers is built from it.
Then every `snake_case` token is extracted from each authored file and
classified. The audit never guesses: an unmatched token is listed for judgement.

```
=== TOTAL ===
snake_case token occurrences audited: 604
distinct DDL identifiers available from the parser: 684
UNRESOLVED (candidate ASCII corruption, needs judgement): 0
```

**The first pass reported 36 unresolved, and three of them were real DDL objects
my audit had failed to collect:** `idx_jadwal` (`:487`), `uq_dokter_spes`
(`:444`) and `v_dokter_katalog` (`:1170`). They are in the contract; my known-set
held only table and column names, and index and view names are neither. Rather
than allow-list them as "prose", the audit was extended to include index/key
names from `TableSpec::$indexes` and the two `CREATE OR REPLACE VIEW` names,
and they now resolve from the DDL. The remaining 33 are PHP built-ins
(`array_fill`, `ctype_digit`, `is_numeric`, `mb_strtolower`, `random_bytes`, ...),
the `declare(strict_types=1)` directive, the `telemedicine_test.sql` filename,
the MySQL `sql_mode` variable, the `per_page` query-string parameter and the
`date_format` Laravel validation-rule name - each allow-listed with its
provenance printed, so the list is auditable rather than a dump.

## 7. Findings

**F1 - The brief and the plan disagree about who owns the endpoints, and the
brief's scope leaves two plan acceptance criteria unsatisfiable.**
The plan's todo 26 ends with "`Commit: Y | feat(api): add quota-aware slot
availability service and schedule endpoints`" and an acceptance criterion of
"`php artisan route:list --path=api/v1/dokter` includes the 2 new routes". This
brief instructs: "Do NOT write a controller, route, FormRequest, or resource for
this todo." The brief is followed, so those routes do not exist and that
criterion is **not met, by instruction**:

```
PS> & '...\php.exe' artisan route:list --path=api/v1/dokter
 GET|HEAD api/v1/dokter .. dokter.index > Api\V1\DokterController@index
 GET|HEAD api/v1/dokter/{dokter} .. dokter.show > Api\V1\DokterController@show
 Showing [2] routes
```

Verified absent from `routes/api.php` and `app/Http/`: `jadwal`, `slot`. This is
reported rather than quietly satisfied, and the plan's other two todo-26
acceptance criteria that require an endpoint are unsatisfiable for the same
reason (`?tanggal=2026-13-45` returning 422, and the HTTP-level `tersedia`
assertions). The service is endpoint-agnostic and ready for whoever owns it;
the one thing it cannot do alone is turn "licence lapsed" into a distinct
status, because it deliberately returns `[]` (section 2, rule 4).

**F2 - The plan's own todo 26 is self-contradictory about LIBUR.** Its prose
says "**subtract** every date in `dokter_libur` for that doctor", which yields an
empty list; its agent-executable criterion says "a test with a `dokter_libur` row
asserts every slot that day is `tersedia: false`", which requires the slots to
still be present. Resolved in favour of the criterion, because the criterion is
the checkable one and `alasan` is the field that exists to carry the reason. The
tension is documented in the class docblock rather than left for the next reader
to re-derive.

**F3 - The brief says the slot duration comes from `dokter.durasi_default_menit`;
the DDL makes that the wrong column.** Both columns exist and both are
`SMALLINT UNSIGNED NOT NULL DEFAULT 15`: `dokter.durasi_default_menit` at `:422`
and `dokter_jadwal.durasi_slot_menit` at `:478`. The per-window column is the
slot length for that window and varies per row; the doctor-level one is a
profile-wide default for a consultation length, i.e. what a *new* schedule row
is created with. At read time the schedule row's value is always present, so
reading the doctor-level value would ignore a per-row setting the DDL stores. The
test writes 20 into `dokter.durasi_default_menit` and 15 into the schedule row,
and the boundary assertions only pass when the schedule row wins - so the
distinction is pinned by a fixture that would have to change to lose it
(mutation M11 kills 20 tests). The plan's `:462` prose is right on this point
and the brief is the stale claim.

**F4 - The plan's QA line for todo 26 says "the 7-value status exclusion set".
`booking.status` has EIGHT values and the exclusion set has TWO.** The six that
consume a slot are derived from the DDL ENUM minus the two by the test itself, so
the number cannot drift silently. Source: `:515`-`:516`, verified by
`SqlSchemaParser` and by the test `each of the six remaining statuses does consume
the slot, read from the DDL enum`, which asserts `$dariDdl` has 8 members.

**F5 - `docs/timezone-policy.md`, cited by todo 26 as the authority for the
wall-clock rule, does not exist.** It is todo 51's deliverable. The rule is
nonetheless stated in `SlotAvailabilityService`'s class docblock with the DDL
citations it needs (`:476`-`:477`, `:507`-`:509`), and the test
`the service publishes Asia/Jakarta wall clock and never a timestamp` asserts a
`23:30` slot comes back as `23:30:00` and that `ZONA_WAKTU` is not
`config('app.timezone')`. Nothing depends on the missing file.

**F6 - `getJadwal()` returns seven empty days for a doctor with no schedule rows,
not an empty list.** A deliberate shape decision (section 2, rule 1): seven keys
always present is the contract, and collapsing it would leave a caller unable to
distinguish "no rows" from "no such key". Recorded because the brief's phrasing
("no bookable slots") is about `getSlotTerbuka()`, which does answer `[]`.

**F7 - The plan's two `getSlotTerbuka` parameters are `int`; this implementation
takes a `Dokter` model.** The names are kept, because the names are the contract.
The plan's form would add a second query and a second way to name a doctor, and
the controller that will call it route-model-binds a `Dokter` anyway. No union
type and no id-overload was added, so there is no untested branch.

**F8 - The service adds `jadwal_id` to each slot, which is not in the plan's
published slot shape.** The plan's shape is `{jam_mulai, jam_selesai,
tipe_layanan, faskes_id, tersedia, alasan}`. `jadwal_id` is carried because
`booking.jadwal_id` is nullable (`:504`) precisely so an instant booking can
exist without one, and because with two windows on the same weekday (which the
DDL permits - no unique on `(dokter_id, hari)`) a client otherwise cannot tell
which row a 09:00 slot belongs to. It is service-internal until a resource
decides otherwise; todo 27 needs it to write the booking's `jadwal_id`.

**F9 - `berlaku_mulai` and `berlaku_sampai` are compared as bare strings, not
through `whereDate()`.** Both are `DATE` columns and both comparisons are
`Y-m-d` strings, so the comparison is a date comparison either way. `whereDate()`
is used only for `dokter_libur.tanggal`, where the extra clarity is worth a
`date()` call on a column that has no secondary index to lose. Not a defect, but
an asymmetry a reader will notice.

## 8. Verification, verbatim

### 8a. Full suite, before any change

```
PS> & 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe' artisan test
{"tool":"pest","result":"failed","tests":391,"passed":380,"assertions":7950,"duration_ms":127411,"failed":11, ...}
```

### 8b. Full suite, after

```
PS> & 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe' artisan test
TESTS=428 PASSED=417 FAILED=11 ASSERTIONS=8235 MS=168978
```

**The delta: 391 -> 428 (+37), 380 -> 417 (+37), 11 -> 11 (unchanged).** Every
test added passes; the pre-existing red count is untouched. The 11 failures,
extracted and sorted, are byte-identical to the baseline set:

```
email_can_be_verified
email_verification_status_is_unchanged_when_the_email_address_is_unchanged
new_users_can_register
password_can_be_confirmed
password_can_be_reset_with_valid_token
password_can_be_updated
profile_information_can_be_updated
reset_password_link_can_be_requested
reset_password_screen_can_be_rendered
user_can_delete_their_account
users_can_authenticate_using_the_login_screen
```

All eleven are the Fortify/Inertia web scaffold owned by todo 30. Not fixed,
not skipped, not touched.

### 8c. The two affected suites together

```
PS> & 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe' artisan test --filter='SlotAvailabilityTest|DokterDirectoryTest'
passed: 64/64 passed, 0 failed
```

`DokterDirectoryTest` is in that pair on purpose. `StrBerlaku` replaced its
private STR predicate, including the exact emitted SQL that
`DokterDirectoryTest` asserts on (the `is not null` guard, the `>=`, and a
`Y-m-d` binding) - 28 tests that were closed and verified, still green.

### 8d. Schema verifier

```
PS> & 'C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe' artisan sehatly:verify-schema
 Live schema
 counts tables=82 views=2 columns=715 indexes=237 foreign_keys=105 checks=3
 information_schema columns=715 indexes=237 foreign_keys=105 checks=3

 Discrepancies: 7 (0 drift, 7 informational)
 documented_extra_table cache ... cache_locks ... failed_jobs ... job_batches
 documented_extra_table jobs ... migrations ... personal_access_tokens
 each: "expected: <registry note> | actual: present in the live schema"

 PASS - 75 tables, 2 views verified. Nothing was written.

VERIFY_EXIT=0
```

Exit 0, and the 7 informational rows are the registered extra tables reported by
design - the same 7 as the baseline, not drift.

### 8e. Untouched surfaces

```
=== SQL hash (must be AEFE2247E00F...) ===
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5

=== mobile/ must not exist ===
False

=== forbidden paths (routes/ database/ web/ docs/ telemedicine_test.sql) ===
(empty)
```

`telemedicine_test.sql` is byte-identical. `mobile/` was not created. No
migration, seeder, route, `web/` or `docs/` file was touched. No column was
added, renamed or dropped, and **no DB-level uniqueness was added to `booking`**
- the plan's todo 27 states that the double-booking guard is explicitly an
application-level concern, and `SHOW CREATE TABLE booking` is unchanged.

`packages/sehatly_api_client` shows modifications in `git status`
(`lib/sehatly_api_client.dart`, `lib/src/model/enums.dart`, and a new
`lib/src/realtime/`, `test/chat_message_test.dart`,
`test/push_registration_test.dart`, `test/realtime_client_test.dart`,
`test/support/fake_realtime_socket.dart`). **None of those are mine** - another
executor is working in that tree concurrently. This is exactly why the commit
uses an explicit pathspec and never `git add -A`: a bare `git commit --amend`
builds from the current index and would sweep that work in. `dart analyze` and
`dart test` were not run by this todo and `packages/` was not modified.

## 9. What is deliberately NOT here

- No controller, route, FormRequest or resource (finding F1).
- No write path for `dokter_jadwal`. The DDL offers none and the plan lists no
  write endpoint, so every window in the suite is authored by the test's own
  builder. Nothing in this todo creates or modifies a `dokter_jadwal` row outside
  a test transaction.
- No factory files. `database/factories/` holds only `UserFactory` and this todo
  adds none, so no count assertion anywhere can move. Rows are written through
  `DB::table()->insertGetId()` because none of `Dokter`, `Pasien`, `Faskes`,
  `DokterJadwal`, `DokterLibur` and `Booking` declares a `#[Fillable]`
  attribute, and Eloquent's default `$guarded = ['*']` makes `Model::create()`
  write nothing while reporting nothing.
- No change to `app/Models/`. `ModelFoundationTest` asserts exactly 75 model
  files and this todo does not move that count.
- No timezone conversion anywhere. Every value the service returns is an `H:i:s`
  or `Y-m-d` string.
