# Task 22 — the public doctor directory

Plan todo 22: `GET /api/v1/dokter` and `GET /api/v1/dokter/{dokter}`.

Eight files authored, all new. Nothing existing was modified: `routes/api.php` and
`app/Support/ApiResponse.php` were **read** (todo 21 owned both this round) and the
route wiring is handed over in section 12 below rather than applied.

---

## 1. The two eligibility rules, read from the DDL

Per A.16 every `:NNN` below was resolved by searching `telemedicine_test.sql` for the
column name and confirming the enclosing `CREATE TABLE`, not by trusting a citation.

### Rule 1 — the verification state

| | |
| --- | --- |
| column | `dokter.status_verifikasi` |
| DDL | `telemedicine_test.sql:427` — `status_verifikasi ENUM('pending','terverifikasi','ditolak') NOT NULL DEFAULT 'pending'` |
| owner | **the view**, not the service |

`:427` is inside the `dokter` statement, which opens at `:409` (`CREATE TABLE dokter (`)
and closes at `:435`. Two facts follow and both are load-bearing:

- the column **defaults to `'pending'`**, so a doctor row inserted with defaults is
  `status_aktif = 1` *and* unverified at the same time. `docs/schema-notes.md`
  (batch D) records this as a trap; it is why a directory filtering on
  `status_aktif` alone leaks.
- there are exactly three states, and `'terverifikasi'` is the only admitting one.

`v_dokter_katalog` already encodes it: `telemedicine_test.sql:1183` is
`WHERE d.status_verifikasi = 'terverifikasi'`. **The service therefore does not restate
this predicate.** Re-writing it in PHP would be a second source of truth that could
disagree with the DDL's own the moment either changed. The same reasoning covers
`d.status_aktif = 1` (`:1184`, column at `:430`) and `d.tersedia_telemedisin = 1`
(`:1185`, column at `:426`).

`DokterDirectoryTest` asserts the live `information_schema.VIEWS.VIEW_DEFINITION`
contains all three and does **not** contain `str_berlaku_sampai`, so the asymmetry in
section 2 is a measured property of the deployed view rather than a claim about the
file.

### Rule 2 — the STR expiry

| | |
| --- | --- |
| column | `dokter.str_berlaku_sampai` |
| DDL | `telemedicine_test.sql:414` — `str_berlaku_sampai DATE NOT NULL` |
| owner | **the service**, because the view does not contain it |

`telemedicine_test.sql:1179-1187` is the view's entire `FROM`/`JOIN`/`WHERE`. The
joins are `dokter`, `users`, `dokter_spesialisasi`, `master_spesialisasi`; the `WHERE`
is exactly the three predicates above. **`str_berlaku_sampai` appears nowhere in it.**

A directory built on the view alone would therefore keep listing a doctor whose STR
lapsed last week. That is the patient-safety defect the brief names, so the service
adds exactly one predicate and restates none of the view's three.

#### The boundary: INCLUSIVE, `>=`

```
str_berlaku_sampai >= <today>
```

`str_berlaku_sampai` is a `DATE`, not a `DATETIME` (`:414`). It has no time-of-day
component, so it cannot lapse at some instant during the day: the last moment of
validity is the **end** of the date it names. `berlaku sampai 2026-09-27` means
"valid through 2026-09-27". So a doctor whose STR expires **today** is still licensed
today and is listed; a doctor whose STR expired **yesterday** is not.

The off-by-one in the other direction (`>`) is not a safety win either. It would hide
a currently-licensed doctor for a whole day — a bookable-consultation denial — while
`>=` admits only doctors whose licence has demonstrably not lapsed. The boundary is
therefore inclusive, and it is asserted on **both sides of the exact date**, four times:

| `asOf` | doctors with STR 2026-09-26 / -27 / -28 that are listed |
| --- | --- |
| 2026-09-26 | all three — nothing has lapsed yet |
| 2026-09-27 | the 27th and the 28th — **the 27th is still listed on the day it expires** |
| 2026-09-28 | the 28th only — the 27th is gone the day *after* |
| 2026-09-29 | none — expiry removes rows over time, it does not merely reorder them |

#### NULL expiry: EXCLUDED, fail-closed

`str_berlaku_sampai` is `DATE NOT NULL` (`:414`), so the DDL makes a NULL impossible.
MySQL rejects one with **1048** regardless of `sql_mode`, because `NOT NULL` is a hard
constraint and not a strict-mode warning. `DokterDirectoryTest` re-parses `:414` with
the project's own `App\Support\Schema\SqlSchemaParser` and asserts
`$column->nullable === false`, so the claim rests on the DDL and not on this
paragraph.

The query still carries an explicit `whereNotNull`, because the predicate has to be
*well-defined* rather than merely *currently unreachable*. "We do not know when this
licence ends" is not evidence that the licence is valid, so the reading is fail-closed:
if the column were ever relaxed to nullable, this predicate already excludes the
unknown rather than admitting it. It is nested inside a `where(function ...)` group so
a future `or` cannot split `A1 AND A2` into `A1 OR A2` and admit the NULL through `A2`.

#### Why "today" comes from MySQL, not from PHP

`config/app.php` sets the application timezone to `UTC`, while the schema stores naive
wall-clock in `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` columns (`:431`-`:432`). Reading the
date from PHP would compare a Jakarta calendar day against a UTC one and, for seven
hours every evening, put the STR boundary a day out. The service reads
`SELECT CURDATE()` — the same clock MySQL used when it wrote the column — and caches it
per instance so the list and a detail fetch in one request cannot straddle midnight.
`{DokterDirectoryService::list()}` and `find()` take an optional explicit `$asOf`,
which is how the four-point boundary table above is made exact rather than approximate.

### A third predicate the plan does not name: `users.dihapus_at IS NULL`

`users.dihapus_at TIMESTAMP NULL` is the soft-delete marker (`:148`) and `User` carries
`SoftDeletes` with `const DELETED_AT = 'dihapus_at'`. The view's join is a plain
`JOIN users u ON u.id = d.user_id` (`:1180`) with **no** `dihapus_at` term, so a
soft-deleted account's doctor stays in the public directory and its owner's name keeps
being served to anonymous callers. `Dokter::user()` is declared
`hasOne(User::class, 'user_id')`, which does not inherit `User`'s `SoftDeletes` global
scope either, so the scope cannot be relied on to hide it.

The predicate is therefore written explicitly, with the same fail-closed reading as
rule 2. This is an **addition** to the plan's two stated rules and is reported as
finding 4.1.

---

## 2. View, table, or both? **Both, deliberately.**

- **The view supplies the row set and the `GROUP_CONCAT` specialisation list.**
  `telemedicine_test.sql:1170-1187` is the DDL's own expression of the visibility rule
  and migration `2026_10_01_000077` copies it byte for byte, including
  `GROUP_CONCAT(s.nama SEPARATOR ', ')`. That aggregate is MySQL-only syntax with no
  builder equivalent, so re-deriving it in PHP would be a paraphrase — and a paraphrase
  of the visibility rule is the one class of divergence this design exists to prevent.
- **The `dokter` table supplies rule 2 and the detail payload.** The view selects seven
  columns (`:1172`-`:1178`) and carries no `str_berlaku_sampai`, no `foto_profil`
  (it is `users.foto_profil`, `:141`), no `jumlah_ulasan` (`:424`) and no `status_aktif`
  (`:430` — the view *filters* on it at `:1184` without selecting it). So the detail
  endpoint re-reads `Dokter` with its `user` relation and three eager-loaded children.

Both joins added for rule 2 are 1:1 (`d.id` is `dokter`'s PK at `:410`; `u.id` is
`users`'s PK at `:133`), so `paginate()`'s `count(*)` stays correct. Every column of the
view and of `dokter` is table-qualified from the first line of the query, because the
two share `tipe`, `rating_rata_rata`, `jumlah_konsultasi` and
`biaya_konsultasi_online` and an unqualified reference is MySQL 1052.

**Duplicating the verification predicate in the service would have been the defect to
avoid**, and it is not there. `grep` for `status_verifikasi` in the service finds it only
in docblocks and in a test.

### `DokterKatalog` is in `App\Services\Dokter`, not `App\Models`

The plan says "wrap it in a `DokterKatalog` model" and names no namespace. `App\Models`
is **not available**: `tests/Unit/Models/ModelFoundationTest.php:184-189` asserts
`glob(app/Models/*.php)` yields **exactly 75** classes, one per contract table, and the
plan's own todo 19 acceptance criterion is `ls app/Models/*.php | wc -l` equal to 75. A
76th file there breaks both, todo 19 is closed, and this brief forbids modifying
`app/Models/`. A view is not one of the DDL's 75 tables, so the count must not move. The
class therefore lives at `app/Services/Dokter/DokterKatalog.php`, namespaced
`App\Services\Dokter`, next to its only consumer. **Finding 4.2.**

`protected $primaryKey = 'dokter_id'` is load-bearing: `:1172` is `d.id AS dokter_id`,
so the view has **no column called `id`** and Eloquent's default would make
`find()`/`whereKey()` return nothing *without erroring*. `DokterKatalog::find($id)` is
asserted non-null in the suite. `$timestamps = false` (the view has no `dibuat_at`) and
`$incrementing = false` (a view cannot allocate a key) are both asserted too.

`DokterKatalog::KOLOM` names the seven view columns, and a test reads the live column
list out of `information_schema` and asserts it equals that array, so a future edit to
the view that adds, drops or renames a column fails the suite.

---

## 3. Public or gated? **Public, and `dokter.lihat` is deliberately unused.**

The plan's todo 22 states it: "`GET /api/v1/dokter` is **public** (no auth)". `RbacCatalog`
is what makes that the only workable answer rather than a merely stated one.

`RbacCatalog::PERMISSIONS` **does** hold `dokter.lihat` ("Lihat Dokter", `:206`), and it
is held by all five roles. Writing `permission:dokter.lihat` would be wrong twice:

1. **It is not an authenticated surface.** A directory is a pre-authentication page: a
   visitor must be able to browse doctors *before* they have an account.
   `permission:` resolves through `EnsurePermission`, which answers 401 when there is
   no authenticated principal — so the gate would 401 every anonymous caller, the
   opposite of what the plan asked for. `AuthController`'s class docblock gives the same
   reasoning for omitting `permission:` and `tipe:` from all eight of its routes.
2. **It would lock out two real account types.** `perawat` and `kurir` are `users.tipe`
   ENUM values (`:139`) that hold **no role** in `RbacCatalog::ROLES` (`:152`-`:158`),
   therefore no grant through `role_permissions` at all. `RbacCatalog`'s own docblock
   says so and points at todos 20/21/22 as the place that has to decide. A
   `permission:dokter.lihat` gate answers 403 for a `perawat` reading a public page, and
   no data change in `RbacCatalog` can fix that while the gate is on the route.

Gating only the **detail** endpoint was considered and rejected: `dokter.lihat` and
`dokter.profil` (`:207`) are both held by all five roles, so it would still exclude
`perawat` and `kurir`, and it would break the plan's own todo 23 requirement that both
clients render a doctor's profile.

**Where `dokter.lihat` actually belongs:** an administrative directory that is *supposed*
to list unverified, inactive and STR-expired doctors so an operator can renew or suspend
them. That endpoint needs the code, must be authenticated, and must not share this
service's query, because its purpose is to bypass both eligibility rules.

Both facts are asserted in the suite (`RbacCatalog::isUserType('perawat') === true`,
`isRole('perawat') === false`, `permissionsFor('pasien')` contains `dokter.lihat`) and
the route test asserts that none of the three routes' `gatherMiddleware()` starts with
`auth`, `permission` or `tipe`, and that a `POST` to the list is 405.

---

## 4. Endpoints

| method | path | auth | gate | response |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/dokter` | public | none | `{success, data:{dokter:[DokterResource]}, message, meta:{...}}` |
| GET | `/api/v1/dokter/{dokter}` | public | none | `{success, data:{dokter:DokterDetailResource}, message}` |
| GET | `/api/v1/master-spesialisasi` | public | none | `{success, data:{spesialisasi:[...]}, message, meta:{single-page}}` |

The third is named by the plan's todo 22 and not by this round's brief; it is a pure
read of the 16-row `master_spesialisasi` table and is reported as finding 4.3.

The detail parameter is typed `string`, not `int` and **not** `Dokter`. A
`Dokter $dokter` type-hint would make Laravel's implicit route-model binding resolve the
segment against the `Dokter` model and **bypass both eligibility rules**, answering 200
with an unverified doctor's profile. `string` also means a non-numeric segment produces
the 404 envelope instead of a `TypeError`. The segment is named `{dokter}` to match
todo 26's `{dokter}/jadwal` and `{dokter}/slot`.

### 404 is one body for five reasons

`find()` returns `null` for absent, unverified, inactive, telemedicine-opted-out,
STR-expired and soft-deleted-account alike, and the controller renders one envelope for
all of them. A caller able to tell "unverified" from "absent" could enumerate the
verification state of every doctor account from an unauthenticated endpoint — which is
exactly what rule 1 protects. A test asserts the five responses are byte-identical
(`array_unique(array_map('json_encode', ...))` has one element).

---

## 5. Filter and ordering contract

| parameter | accepted | rejects | notes |
| --- | --- | --- | --- |
| `spesialisasi` | a `master_spesialisasi.kode` (`SP.PD`) **or** its `id` | unknown code, unknown id, >10 chars | `Rule::exists` on `id` or `kode`, chosen by the value's shape |
| `tipe` | the seven `dokter.tipe` values (`:412`) | anything else, 422 with `errors.tipe` | `'spesialis'` is **rejected**: it belongs to the three-value `master_spesialisasi.tipe` (`:406`) and the two ENUMs share only `dokter_umum` |
| `search` | free text over `users.nama_lengkap` (`:135`) | >150 chars, 422 | `%` and `_` are escaped with `addcslashes`, so they match literally |
| `tersedia_telemedisin` | boolean | non-boolean, 422 | see below |
| `page` | integer >= 1 | 0, non-integer, 422 | |
| `per_page` | 1..100 | 0, 101, non-integer, 422 | default 15 |

**`tersedia_telemedisin=0` returns an empty page, and that is the honest answer.** The
view already requires `d.tersedia_telemedisin = 1` (`:1185`), so the filter is applied
in both directions: `= 1` restates the view and `= 0` asks for rows the view already
removed. Leaving `= 0` unfiltered would return every eligible doctor, answering a
different question than the one asked. Dropping the view's predicate to give the filter
something to do would re-derive the whole visibility rule and list a doctor who opted
out — which migration 77's docblock names as one of three predicates that must survive.

**Ordering is `rating_rata_rata DESC`, `jumlah_konsultasi DESC`, `dokter_id ASC`.** The
plan names the first two. On a fresh database every doctor carries the DDL defaults
`0.00` and `0` (`:423`, `:425`) and `rating_rata_rata` is `DECIMAL(3,2)`, so ties are
the normal case; MySQL leaves the relative order of tied rows unspecified, and
`LIMIT/OFFSET` over an unspecified order repeats and skips rows. `dokter_id` is `d.id`
and unique, so the order becomes total and the pages disjoint. The plan's two keys keep
their order; the tiebreaker is appended.

**`?spesialisasi` uses `EXISTS`, not a join.** `uq_dokter_spes` (`:444`) stops one
doctor appearing twice per specialisation, but a doctor with three would still fan out
to three rows under a join, inflating `count(*)` and letting one doctor occupy three
slots on one page. A test seeds exactly that case and asserts `meta.total === 1`.

---

## 6. Pagination shape: the project-wide `meta` block

`ApiResponse` was **re-read after todo 21 landed** and now carries
`success($data, $message, $status, $meta)` plus `pageMeta(LengthAwarePaginator)` and
`singlePageMeta(int)`. Per the brief's rule — use `meta` if it exists when you read it —
this controller uses it, and the tests read `meta.*`.

The list was written against the two-key envelope first and switched when todo 21's
`ApiResponse` arrived. The *field names* never changed, because they were taken from
the plan's todo 22 criterion and from todo 24's `Paginated<T>` in the first pass:
`{current_page, last_page, per_page, total, from, to}`. `meta` is a **sibling** of
`data`, never a wrapper around it, so `data.dokter` is unaffected and no client has to
read two shapes. `per_page` is the size actually applied, so it stays correct after the
100 cap has clamped the request.

---

## 7. A.26 gate: encoding scan + token audit

Both run over all eight authored files. Instrument lives outside the repository
(`%TEMP%\opencode\task22-audit.php`) because it is an instrument, not a deliverable.

### 7.1 Encoding — 0 non-ASCII bytes emitted

```
== 1. non-ASCII gate (A.26 set, applied per codepoint) ==
CLEAN   app/Services/Dokter/DokterKatalog.php                    bytes=8062   CRLF=0 BOM=no nonascii=0
CLEAN   app/Services/Dokter/DokterDirectoryService.php           bytes=22532  CRLF=0 BOM=no nonascii=0
CLEAN   app/Http/Requests/Dokter/IndexDokterRequest.php          bytes=4478   CRLF=0 BOM=no nonascii=0
CLEAN   app/Http/Resources/DokterResource.php                    bytes=4081   CRLF=0 BOM=no nonascii=0
CLEAN   app/Http/Resources/DokterDetailResource.php              bytes=8701   CRLF=0 BOM=no nonascii=0
CLEAN   app/Http/Resources/MasterSpesialisasiResource.php        bytes=1988   CRLF=0 BOM=no nonascii=0
CLEAN   app/Http/Controllers/Api/V1/DokterController.php         bytes=8758   CRLF=0 BOM=no nonascii=0
CLEAN   tests/Feature/Dokter/DokterDirectoryTest.php             bytes=48915  CRLF=0 BOM=no nonascii=0
codepoints outside the A.26 set: 0
```

**A.26's own regex does not compile on this platform.** PHP's PCRE2 build has no `\u`
escape, so `[^\x00-\x7F\u2013\u2014...]` fails with *"PCRE2 does not support \F, \L, \l,
\N{name}, \U, or \u"* and `preg_match_all` returns `false` — which a naive
`if (preg_match_all(...))` reads as **clean**. My first run reported all eight files
CLEAN on that false negative. The same character set is therefore applied by iterating
codepoints with `mb_ord`, which cannot fail to compile. **Finding 4.6: the gate
appended to A.26 is unsound as written and should be corrected before the next batch.**

### 7.2 Token audit — 798 DDL identifiers, 41 residue tokens, all explained

The audit loads `telemedicine_test.sql` through the project's own `SqlSchemaParser` and
builds its vocabulary from **75 tables, 2 views, 672 columns, 142 indexes, 105 foreign
keys, 3 checks**, expanded to 798 distinct identifiers. Two strengthening steps, both
of which turned allow-listed tokens into DDL-*checked* ones:

- **view names** come from `$spec->views`, so `v_dokter_katalog` is verified rather than
  excused;
- **ENUM/SET values** are extracted out of the `enum('a','b')` type string, so
  `dokter_umum`, `dokter_spesialis`, `dokter_gigi`, `s1_kedokteran`, `terverifikasi`,
  `klinik` and the rest are verified. This is the check that catches the A.26 defect
  class — `doker_umum` for `dokter_umum` — and without it the instrument would have
  reported three misspelled-looking ENUM values as "not in the DDL" and taught the next
  executor to allow-list them.

Residue, per file, all reviewed individually:

| token | disposition |
| --- | --- |
| `array_map`, `array_key_exists`, `array_column`, `array_intersect`, `array_merge`, `array_unique`, `ctype_digit`, `filter_var`, `in_array`, `is_numeric`, `is_string`, `json_encode`, `mb_strtolower`, `password_hash`, `random_bytes`, `random_int`, `str_repeat`, `base_path` | PHP builtins |
| `strict_types` | PHP directive |
| `telemedicine_test` | the reference filename inside `:NNN` citations |
| `information_schema`, `sql_mode`, `utf8mb4_unicode_ci`, `group_concat` | MySQL server nouns, not contract identifiers |
| `non_numeric` | one of this suite's own test-case labels |

**Zero DDL identifier misspellings. Zero ENUM value misspellings.**

### 7.3 Negative control — the audit is not vacuous

A gate that cannot fail is not a gate, so the instrument was checked against ten
deliberate cases:

```
CONTROL-OK doker_umum               accepted=no    expected=no
CONTROL-OK docker_gigi              accepted=no    expected=no
CONTROL-OK str_berlaku_sampai      accepted=yes   expected=yes
CONTROL-OK status_verifikas         accepted=no    expected=no
CONTROL-OK nama_lengkap             accepted=yes   expected=yes
CONTROL-OK spesialisasi_id          accepted=yes   expected=yes
CONTROL-OK dihapus_at               accepted=yes   expected=yes
CONTROL-OK v_dokter_katalog         accepted=yes   expected=yes
CONTROL-OK s1_kedokteran            accepted=yes   expected=yes
CONTROL-OK thenederived             accepted=no    expected=no

10 controls, 0 failures
```

`doker_umum` is A.26's actual recorded defect, reproduced here against the instrument
and caught. `status_verifikas` is the same class one character off. Both are rejected;
every real identifier is accepted.

---

## 8. Mutation testing — do the tests actually pin the rules?

Four mutations of `DokterDirectoryService`, each preceded by a **green control run**
(28/28) in the same loop, each restored byte-identically afterwards:

| mutation | result | caught by |
| --- | --- | --- |
| `>=` becomes `>` | **CAUGHT** (3) | the inclusive-boundary test, the four-point `$asOf` test, the emitted-SQL predicate test |
| drop `whereNotNull('d.str_berlaku_sampai')` | **CAUGHT** (1) | the fail-closed predicate test |
| drop `whereNull('u.dihapus_at')` | **CAUGHT** (1) | the inactive/opt-out/soft-delete exclusion test |
| drop `->orderBy('v_dokter_katalog.dokter_id')` | **CAUGHT** (1) | the emitted-ORDER-BY test |

The fourth row is the interesting one, and it is a correction to my own first draft.
Deleting the tiebreaker initially left **all 27 tests green**. The reason is a physical
coincidence, not a weak test: `v_dokter_katalog` groups on `d.id`, InnoDB answers in
clustered-index order, and the untied rows come back ascending by `d.id` anyway —
indistinguishable from the tiebreaker having done its job. A behavioural assertion cannot
separate "the ORDER BY says `dokter_id`" from "MySQL happened to agree", and a future
index change would flip the pages with nothing red.

So a **28th test was added that asserts the emitted SQL string** carries
`` `v_dokter_katalog`.`rating_rata_rata` desc ``, then
`` `jumlah_konsultasi` desc ``, then `` `dokter_id` asc ``, and that the three appear in
that order. The behavioural pagination test keeps the half of the claim it can actually
prove — that the pages partition the eligible set — and both files say so rather than
over-claiming. An earlier draft of that test's comment claimed it would catch the missing
tiebreaker; that claim was false and is corrected in place.

**Finding 4.5: three framework-API assumptions in the plan's shape of this todo are
wrong for this version, and each produced a 500 before it was found.** They are listed
in full in section 9.

---

## 9. Framework facts that cost a 500 each

This is **laravel/framework 13.33.0** (`artisan --version`), and three of the
assumptions that read as natural were wrong:

1. **An eager-load closure is handed the `Relation`, not a `Builder`,** and its return
   value is discarded. `fn (Builder $q): Builder => ...` is a fatal `TypeError` on every
   detail request — *"Argument #1 ($q) must be of type
   Illuminate\Database\Eloquent\Builder, Illuminate\Database\Eloquent\Relations\HasMany
   given"*. Neither the parameter nor the return may be type-hinted to `Builder`.
2. **`whereExists()` hands its closure a QUERY builder**, not the Eloquent one. The same
   `TypeError` appears with `Illuminate\Database\Query\Builder` for `?spesialisasi=`.
3. **A `date`/`datetime` cast returns `Carbon\CarbonImmutable`, which is not
   `Illuminate\Support\Carbon`.** A `waktu(?Carbon $waktu)` helper is a `TypeError` on
   every detail response. The parameter is now `mixed` with an `instanceof
   DateTimeInterface` check.

Two more, both found by the tests rather than by review:

4. `Builder::paginate()` **short-circuits to `newCollection()` when the count is zero**,
   so the page `SELECT` — and therefore the `ORDER BY` — is never issued. The two
   SQL-assertion tests each insert one eligible row, and say why.
5. `Illuminate\Routing\Route` has **no `method()`**; it has `methods()`. And
   `getKeyName()`, `getTable()`, `getIncrementing()`, `getKeyType()` and
   `usesTimestamps()` are all **instance** methods on `Model` in this version, so
   `DokterKatalog::getKeyName()` is a fatal "cannot be called statically".

---

## 10. Test output

`vendor\bin\pest --filter=DokterDirectoryTest` — **28 tests, 274 assertions, all green**:

```
  [ok] a verified doctor with a live STR is listed and is fetchable by id
  [ok] an UNVERIFIED doctor is excluded from the list and 404s on detail
  [ok] a doctor whose STR has EXPIRED is excluded from the list and 404s on detail
  [ok] the STR boundary is inclusive: expiring TODAY is listed, expiring YESTERDAY is not
  [ok] the boundary is exactly the asOf date, one day either side
  [ok] the DDL forbids a NULL STR expiry and the emitted predicate is still fail-closed
  [ok] an inactive doctor, a telemedicine opt-out and a soft-deleted account are all excluded
  [ok] absent, unverified, expired, zero and non-numeric ids all answer one 404 envelope
  [ok] ?tipe= filters on the seven-value dokter.tipe ENUM
  [ok] ?spesialisasi= filters by master_spesialisasi kode and by id alike
  [ok] a doctor with three specialisations is listed once, not three times
  [ok] ?search= matches users.nama_lengkap and treats % and _ literally
  [ok] ?tersedia_telemedisin=1 restates the view predicate and =0 is unsatisfiable
  [ok] every closed-vocabulary filter is a 422 with its own errors key
  [ok] the order is rating DESC, then consultations DESC, then dokter_id ASC
  [ok] the emitted ORDER BY really carries the unique tiebreaker, in the plan order
  [ok] pagination partitions the eligible set: no overlap, nothing lost, nothing repeated
  [ok] a page past the end is an empty page, not an error and not a repeat
  [ok] per_page defaults to 15 and 100 is accepted
  [ok] the detail carries the child tables, ordered as the plan asks
  [ok] the detail publishes no licence number, document URL, contact detail or STR date
  [ok] GET /master-spesialisasi lists the master rows by kode with a real count
  [ok] TIPE_DOKTER is byte-identical to the DDL seven-value ENUM, in the DDL order
  [ok] the DDL names every column the two eligibility rules and the projection read
  [ok] the live view really carries the three predicates and exactly the seven columns
  [ok] DokterKatalog reads the view through dokter_id, not a non-existent id column
  [ok] the three directory routes exist and carry no auth, permission or tipe gate
  [ok] RbacCatalog is why a permission gate here would be wrong

  OK (28 tests, 274 assertions)
```

### 10.1 The delta against the baseline, measured both ways

The brief's baseline (250 passed / 11 failing over 261 tests) predates todo 21, which
has since landed and added tests, so the delta was measured **by running the full suite
twice — once with my eight files removed from the working tree and once with them
restored** — rather than against a stale number.

```
CONTROL (my 8 files removed)
  result=failed  tests=363  passed=352  failed=11  errors=0  assertions=7664  ms=146570

WITH MY FILES
  result=failed  tests=391  passed=380  failed=11  errors=0  assertions=7938  ms=176310
```

| | control | with my files | delta |
| --- | --- | --- | --- |
| tests | 363 | 391 | **+28** |
| passed | 352 | 380 | **+28** |
| **failed** | **11** | **11** | **0** |
| **errors** | **0** | **0** | **0** |
| assertions | 7664 | 7938 | **+274** |

The 11 failures are **byte-identical in both runs** and are exactly the pre-existing
todo-30 Fortify/Inertia web scaffold owned by another todo:

```
  users_can_authenticate_using_the_login_screen
  email_can_be_verified
  password_can_be_confirmed
  reset_password_link_can_be_requested
  reset_password_screen_can_be_rendered
  password_can_be_reset_with_valid_token
  new_users_can_register
  password_can_be_updated
  profile_information_can_be_updated
  email_verification_status_is_unchanged_when_the_email_address_is_unchanged
  user_can_delete_their_account
```

### 10.2 Contention, disclosed, and how the real runs were isolated

Several runs during this task were corrupted by a **concurrently running executor**:
`1050 Table 'jobs' already exists`, `1146 Table 'telemedisin_db_test.migrations' doesn't
exist`, `1824 Failed to open the referenced table 'master_kabupaten_kota'`, and once a
`1452` on `dokter_spesialisasi.spesialisasi_id` in `PasienProfileTest` that the control
run did not produce. That test reads its two `master_spesialisasi` ids from the table
rather than hard-coding them precisely because `RefreshDatabase` does not seed, and it
seeds them itself; a committed `migrate:fresh` landing between its `pluck` and its
`insert` produces exactly that failure.

Every run that produced a reported number was therefore gated on a **quiet window** - no
other `php` process for 165 consecutive seconds - and the reported result is the
**third of three consecutive runs inside one quiet window**, all three byte-identical:

```
RUN 1 : tests=391 passed=380 assertions=7938 failed=11 error_details=0 ms=154949
RUN 2 : tests=391 passed=380 assertions=7938 failed=11 error_details=0 ms=179783
RUN 3 : tests=391 passed=380 assertions=7938 failed=11 error_details=0 ms=141385
```

with the same 11 todo-30 test names in all three. The `1452` did not reproduce in any
of them. Per the brief's instruction it is recorded as contention and not as a real
failure: a `1452` on a row the test itself had just read, with a clean control and three
clean re-runs, is not a code defect and is not attributed to one.

The four mutation runs in section 8 were gated the same way — a green 28/28 control
immediately before each mutated run — because two earlier mutation attempts were
invalidated by the same contention and would otherwise have been recorded as
"NOT CAUGHT".

### 10.3 The routes are registered in-process, and why

`beforeEach` registers the three routes **only when they are absent**. Before the
orchestrator pastes section 12, the tests drive an in-process registration of exactly
the URI, controller and route name the block declares; afterwards `routes/api.php` is
loaded during bootstrap, registers first, and the very same tests drive the real route
table. No alternative route file was created and no probe route was used: every test
goes through real HTTP, the real middleware stack, the real `FormRequest`, the real
resources and the real service.

---

## 11. Verifier output — `php artisan sehatly:verify-schema`

```
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: booking.status (515-516), home_care_pesanan.status (1104-1105),
   invoice.status (947-948), klaim_bpjs.status (1022-1023), konsultasi.status (542-543),
   konsultasi_chat.tipe_pesan (568-569), lab_permintaan.status (884-885), master_obat.bentuk_sediaan (713-714),
   persetujuan_pdp.jenis (1137-1138), pesanan_obat.status (810-811), resep.status (751-752)
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on pasien_tanda_vital (line 1161)

 Live schema
 counts tables=82 views=2 columns=715 indexes=237 foreign_keys=105 checks=3
 information_schema columns=715 indexes=237 foreign_keys=105 checks=3

 Discrepancies: 7 (0 drift, 7 informational)
 ... cache, cache_locks, failed_jobs, job_batches, jobs, migrations, personal_access_tokens
     (all documented_extra_table, registered in docs/schema-notes.md, informational by design)

 PASS — 75 tables, 2 views verified. Nothing was written.

VERIFY_EXIT=0
```

`php artisan route:list` also exits **0** (`Showing [66] routes`).

**`Discrepancies: 7 (0 drift, 7 informational)` is unchanged and must stay there.** Per
A.25, driving that number to 0 is not achievable and is not the goal: the seven are
registered extra tables reported as informational *by design*, and suppressing them means
deleting the registry rows, which turns all seven into `undocumented_extra_table` **drift**
and breaks exit 0 outright. Zero drift is the invariant and it holds. This todo touched
no migration, no seeder and no factory, so the 82/715/237 live counts are expected to be
identical to the pre-todo-22 baseline.

**`route:list --path=api/v1/dokter` currently lists 0 routes**, because `routes/api.php`
is todo 21's this round and the wiring is section 12. The plan's todo 22 criterion of
"lists 2 routes" is therefore **not yet satisfiable from this working tree** and becomes
satisfiable the moment section 12 is pasted. The three routes exist and pass 27/28 of
the suite either way.

---

## 12. ROUTE BLOCK FOR routes/api.php

**Insertion point: end of file, immediately after the closing `});` of the outermost
`Route::middleware('auth:sanctum')->group(...)` — i.e. after `routes/api.php:207`, which
is the last line of the file in the state I read it. Nothing goes inside any existing
group: these three routes are unauthenticated, and inside the `auth:sanctum` group they
would answer 401.**

> **The line number is stated so the block can be sanity-checked, not so it can be
> applied.** Per A.25 a `:NNN` in a brief is a claim to verify: this file was 100 lines
> when I first read it and todo 21 grew it to 207 in the same round. Use **"after the
> last `});` in the file"**, and re-read the file before pasting.

Two edits, both required:

**(a) add the import beside the existing ones at the top of the file**, after
`use App\Http\Controllers\Api\V1\AuthController;`:

```php
use App\Http\Controllers\Api\V1\DokterController;
```

**(b) append the block below, after the `auth` group's closing `});`:**

```php
/*
|--------------------------------------------------------------------------
| Module 1 -- the public doctor directory. Three routes, and NONE of them is
| gated.
|--------------------------------------------------------------------------
|
| `GET /dokter` and `GET /dokter/{dokter}` are unauthenticated on purpose. The
| plan's todo 22 says so explicitly, and `RbacCatalog` is what makes it the only
| workable answer: `dokter.lihat` **is** a real permission code, but
| `permission:` resolves through `EnsurePermission`, which answers 401 for an
| anonymous caller - so the gate would 401 every visitor who has not registered
| yet, which is the opposite of a directory. And it would 403 `perawat` and
| `kurir`, which are real `users.tipe` ENUM values (telemedicine_test.sql:139)
| that hold no role in `RbacCatalog::ROLES` and therefore no grant at all.
|
| `dokter.lihat` is not dead vocabulary, it is the wrong vocabulary *here*: it
| belongs on an administrative directory that is supposed to list unverified,
| inactive and STR-expired doctors so an operator can renew or suspend them.
| That endpoint must be authenticated and must NOT reuse this controller's
| query, because its purpose is to bypass the two eligibility rules
| `DokterDirectoryService` exists to enforce.
|
| `{dokter}`, not `{id}` and not `{id}` bound to a model: todo 26 adds
| `{dokter}/jadwal` and `{dokter}/slot`, and a `Dokter $dokter` type-hint would
| make implicit route-model binding resolve the segment and **bypass both
| eligibility rules**, answering 200 with an unverified doctor's profile.
|
| `DokterDirectoryTest` registers these three routes in-process and only when
| they are ABSENT, so it passes both before and after this block is pasted, and
| after pasting it drives this real route table.
|
*/

Route::get('dokter', [DokterController::class, 'index'])
    ->name('dokter.index');

Route::get('dokter/{dokter}', [DokterController::class, 'show'])
    ->whereNumber('dokter')
    ->name('dokter.show');

Route::get('master-spesialisasi', [DokterController::class, 'spesialisasiIndex'])
    ->name('master-spesialisasi.index');
```

No `Route::prefix(...)` wrapper is needed: the file is already mounted under `api/v1`
by `apiPrefix` in `bootstrap/app.php`, and the file's own header says so. No middleware
is attached to any of the three.

`->whereNumber('dokter')` matches the convention the `pasien` groups in this file
already use for `{id}`. It is the **first** of two defences, not the only one: the
constraint makes a non-numeric segment a router 404, and the controller's `string`
parameter plus `(int)` cast is the second, so the route still cannot be coerced into
resolving a model. `dokter.id` is `BIGINT UNSIGNED` (`:410`), so `whereNumber` is the
right constraint; a `VARCHAR` key would have needed `->where('[a-zA-Z0-9-]+')` instead.

**Verify after pasting:**

```
php artisan route:list --path=api/v1/dokter      # expect 2 routes
php artisan route:list --path=api/v1/master-spesialisasi   # expect 1 route
php artisan test --filter=DokterDirectoryTest    # expect OK (28 tests, 274 assertions)
```

---

## 13. Findings

**4.1 — the view leaks soft-deleted accounts, and the plan does not mention it.**
`v_dokter_katalog` joins `users` at `:1180` with no `dihapus_at` term, so a doctor whose
account is soft-deleted stays in the public directory and its owner's name keeps being
served to anonymous callers. `Dokter::user()` is a `hasOne`, so `User`'s `SoftDeletes`
global scope does not hide it either. I added `users.dihapus_at IS NULL` as a third
eligibility predicate, fail-closed, in the same single place, with a test. The plan names
two rules; the DDL supports a third. A todo that widens the view would want this moved
into the view itself.

**4.2 — the plan's `DokterKatalog` location is unavailable.**
"Wrap it in a `DokterKatalog` model" names no namespace, and `App\Models` is closed at
exactly 75 classes by `ModelFoundationTest.php:184-189` and by todo 19's own acceptance
criterion. It is at `App\Services\Dokter\DokterKatalog` instead. The brief's
"Do NOT modify anything under `app/Models/`" and the plan's instruction are in direct
conflict; I satisfied the brief and the closed invariant, and the plan's *intent* (a
read model for the view) is met.

**4.3 — todo 22's acceptance criterion "lists 2 routes" is not satisfiable from a
single executor.** The routes live in `routes/api.php`, which this round's brief assigns
to todo 21 exclusively. Three routes are delivered (two from the criterion plus
`master-spesialisasi`, which the plan's prose names and this brief does not), and the
wiring is section 12. `route:list --path=api/v1/dokter` lists 0 until it is pasted. This
is an ordering constraint in the plan, not a defect in the code.

**4.4 — the plan's two sort keys are not a total order.**
`rating_rata_rata DESC` then `jumlah_konsultasi`, exactly as written, leaves tied
rows in MySQL's unspecified relative order, and `LIMIT/OFFSET` over an unspecified order
repeats and skips rows. Since the DDL defaults are `0.00` and `0` (`:423`, `:425`), ties
are the *normal* case on a fresh database, not a corner case. I appended
`dokter_id ASC`. This is an addition to the plan's stated contract and section 8 records
the measurement that forced the SQL-level assertion.

**4.5 — three framework-API assumptions in the plan's shape of this todo are wrong for
laravel/framework 13.33.** Full list in section 9; the eager-load closure and the
`whereExists` closure type-hints and the `?Carbon` cast helper each produced a 500 on
every affected request. The plan's own warning about the `enum:` cast being a silent
no-op is one instance of this class; the others are undocumented.

**4.6 — A.26's appended corruption regex is unsound on this platform.**
`[^\x00-\x7F\u2013\u2014\u2022…]` cannot compile under PCRE2 (`\u` is unsupported), and
`preg_match_all` returns `false`, which a naive truthiness check reads as **clean**. My
first gate run reported all eight files CLEAN on that false negative. The character set
is right; the instrument needs to be rewritten to iterate codepoints. This should be
corrected in the appendix before the next batch relies on it.

**4.7 — `?tersedia_telemedisin` is redundant against the view, in a way that can only
ever return an empty page.** The view already requires `= 1` (`:1185`), so `= 0` is
unsatisfiable. The filter is applied in both directions rather than ignored, because
ignoring `= 0` would return every eligible doctor and answer a different question than
the one asked. Recorded and tested rather than hidden.

**4.8 — the plan's timestamp table is wrong, as the brief already records.** Not
re-measured here; no timestamp column was read or written by this todo. `dibuat_at` is
emitted on the detail payload via `toISOString()`, consistent with `UserResource`, and
the end-to-end UTC policy remains todo 51's. `diubah_at` is deliberately **not** emitted:
it is `ON UPDATE CURRENT_TIMESTAMP` (`:432`), so it moves whenever an admin edits the row
for any reason and would tell a patient when the doctor's profile last changed.

**4.9 — stale-claim check (A.25).** Every claim in the brief about a file's current state
was verified by reading it, not inherited. Two of them had already gone stale by the
time this todo ran:

- "`ApiResponse` has no `meta` key … pick `data.total`" — **no longer true.** Todo 21's
  `ApiResponse` landed mid-task with `success(..., ?array $meta)` and
  `pageMeta()`/`singlePageMeta()`. Re-read, then switched to `meta` as the brief's own
  rule directs.
- "the `auth` group is the last thing in `routes/api.php`" — todo 21 had appended `me`,
  `pasien/profil`, `anggota-keluarga` and `alergi` groups, 105 lines of new content. The
  insertion point in section 12 is stated relative to the **end of file**, not to a line
  number, for exactly this reason.

One plan citation was also found wrong and is reported rather than silently corrected:
todo 22's `dokter_spesialisasi` reference is `:437-445`, which is right, but its
`dokter_pendidikan` reference says `tahun_lullah` and then corrects itself in the same
sentence to `tahun_lulus` — the DDL's spelling, at `:462`, is `tahun_lulus`, and that is
what the code uses. The plan's own parenthetical is the correct one.

---

## 14. Committed paths

```
app/Services/Dokter/DokterKatalog.php
app/Services/Dokter/DokterDirectoryService.php
app/Http/Requests/Dokter/IndexDokterRequest.php
app/Http/Resources/DokterResource.php
app/Http/Resources/DokterDetailResource.php
app/Http/Resources/MasterSpesialisasiResource.php
app/Http/Controllers/Api/V1/DokterController.php
tests/Feature/Dokter/DokterDirectoryTest.php
.omo/evidence/task-22-sehatly.md
```

`routes/api.php` and `app/Support/ApiResponse.php` are **not** in this commit. Neither
`app/Models/`, `app/Support/Rbac/`, `app/Services/Auth/`, `AuthController.php`,
`database/migrations/`, `database/seeders/` nor `database/factories/` was touched.
`telemedicine_test.sql` is byte-identical at SHA-256
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`.
