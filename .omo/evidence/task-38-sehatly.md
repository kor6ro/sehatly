# Task 38 - `ObatInteraksiService`: bidirectional interactions, cross-prescription history, normalised allergy matching

Evidence for plan todo 38. Written by the todo-38 executor, developed test-first.
`.omo/plans/` is orchestrator-owned and **no checkbox was marked**.

## 1. Scope

| | |
| --- | --- |
| New `app/` files | 2 - `app/Services/Obat/ObatInteraksiService.php` (834 lines incl. docblock), `app/Services/Obat/NamaObat.php` (the name normaliser) |
| New test files | 1 - `tests/Feature/Obat/ObatInteraksiServiceTest.php`, 39 tests, 314 assertions |
| Modified `app/` files | 0 |
| Modified test files | 0 |
| Routes | 0 - `route:list --path=api/v1` still reports **57**. Todo 39 owns the endpoint and the brief forbids a controller here. |
| Not touched | `database/migrations/`, `database/seeders/`, `telemedicine_test.sql`, `phpunit.xml`, `web/`, `packages/`, `mobile/` (absent), `.omo/plans/` |
| Not mine, seen and left alone | `web/**` (modified + untracked), `.omo/evidence/task-3-sehatly.md`, `.playwright-mcp/` |

`telemedicine_test.sql` SHA-256 is
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, byte-identical to
the recorded value, and `git status --porcelain -- telemedicine_test.sql phpunit.xml
database/ packages/ mobile/ .omo/plans/` is **empty**.

### Version facts, read rather than inherited

- `laravel/framework` **v13.33.0**, read from `composer.lock` (the brief's "not 12"
  is correct). `composer.json` requires `^13.17`.
- PHP **8.4.17** at `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe`.
  Bare `php` is not on `PATH`; every command prefixed it or set `$env:PATH`.
- PHPUnit via **Pest 4** (`pestphp/pest ^4.0`, `pestphp/pest-plugin-laravel ^4.0`).
- `Builder::whereRowValues` exists in this version but **cannot** express a
  row-value `IN` list - see section 5.

### The per-executor database, and a finding about it

Every run used `$env:DB_DATABASE = "telemedisin_db_test_38"`, created out of band
(PDO on `mysql:host=127.0.0.1;port=3306` with no `dbname`, because connecting
through the app config to a database that does not exist yet is a 1049).
`phpunit.xml` was **not** edited; the override works because
`VerifySchemaCommandTest:186-190` already documents `$env:DB_DATABASE` as a
supported way to run the suite. Proof it took:

```
php artisan tinker --execute="echo config('database.connections.mysql.database');"
-> telemedisin_db_test_38
```

**Finding: a per-executor database is NOT self-sufficient, and the first run of
the session proved it.** The `Unit` testsuite does **not** use
`RefreshDatabase` - `tests/Pest.php:19` binds it `->in('Feature')` only - so
`VerifySchemaCommandTest` and `VerifySchemaDeferredConstraintTest` read whatever
is LIVE in the configured database. On a freshly created private DB the first
`php artisan test` gave **719 tests, 711 passed, 8 failed**, and the failure was
`Array &0 []` where 82 table names were expected: the private database was
empty. `Feature` then ran `RefreshDatabase` -> `migrate:fresh` and populated it,
and every run after that is green. A future executor that adopts the
per-executor pattern must migrate the private database before trusting the
`Unit` suite. Recording it because the symptom is "the suite is red" with no
hint that the database is the cause.

Also: `php artisan db:create` ignores the `$env:DB_DATABASE` override. It
printed `INFO Database [telemedisin_db_test] already exists` while `tinker`
reported `telemedisin_db_test_38` in the same shell. The command is named
"Create the MySQL **test** database" and appears to take the name from
`phpunit.xml` rather than from the resolved config. Nothing in this todo
depends on it, but the next executor that does will be misled by it.

## 2. DDL citations, read from the file

Every line below was read out of `telemedicine_test.sql` in this session. The
test asserts them, so a future edit that moves one fails the suite rather than
silently invalidating a docblock.

### `obat_interaksi` - `CREATE TABLE` at **731**, closing `ENGINE=InnoDB;` at **740**

| line | content |
| --- | --- |
| 732 | `id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` |
| **733** | `obat_a_id BIGINT UNSIGNED NOT NULL` |
| **734** | `obat_b_id BIGINT UNSIGNED NOT NULL` |
| **735** | `tingkat ENUM('ringan','sedang','berat','kontraindikasi') NOT NULL` - four members, in ASCENDING seriousness |
| 736 | `deskripsi TEXT NULL` |
| 737 | `FOREIGN KEY (obat_a_id) REFERENCES master_obat(id) ON DELETE CASCADE` |
| 738 | `FOREIGN KEY (obat_b_id) REFERENCES master_obat(id) ON DELETE CASCADE` |
| **739** | `UNIQUE KEY uq_interaksi (obat_a_id, obat_b_id)` - over the **ORDERED** pair |

### `resep` - `CREATE TABLE` at **742**, closing at **765**

| line | content |
| --- | --- |
| 744 | `nomor_resep VARCHAR(30) NOT NULL UNIQUE` |
| 747 | `pasien_id BIGINT UNSIGNED NOT NULL` |
| 748 | `dokter_id BIGINT UNSIGNED NOT NULL` |
| **751-752** | `status ENUM('aktif','diproses','diverifikasi','dipenuhi',` / `'dikirim','selesai','kedaluwarsa','dibatalkan') NOT NULL DEFAULT 'aktif'` - **eight** members, and the declaration **WRAPS** onto a second line |
| 753 | `catatan_dokter TEXT NULL` - the only column a `kontraindikasi` note could go in |
| 754 | `tanggal_resep DATETIME NOT NULL` |
| **755** | `berlaku_sampai DATE NOT NULL COMMENT 'E-resep berlaku 7 hari'` |
| 758 | `qr_token VARCHAR(100) NOT NULL` |
| 761-762 | FKs to `pasien(id)` and `dokter(id)` |
| 764 | `INDEX idx_resep_pasien (pasien_id, status)` |

(The table above renders the wrapped `status` declaration across two rows. The
real text is `:751` = `status ENUM('aktif','diproses','diverifikasi','dipenuhi',`
and `:752` = `'dikirim','selesai','kedaluwarsa','dibatalkan') NOT NULL DEFAULT
'aktif',`, asserted verbatim in the test.)

### `resep_item` - `CREATE TABLE` at **767**, closing at **783**

| line | content |
| --- | --- |
| 769 | `resep_id BIGINT UNSIGNED NOT NULL` |
| **770** | `obat_id BIGINT UNSIGNED NULL COMMENT 'NULL = racikan / obat non-katalog'` |
| **771** | `nama_obat VARCHAR(255) NOT NULL COMMENT 'Snapshot nama saat diresepkan'` |
| 776 | `is_racikan TINYINT(1) NOT NULL DEFAULT 0` |
| 782 | `FOREIGN KEY (obat_id) REFERENCES master_obat(id)` |

### `pasien_alergi` - `CREATE TABLE` at **274**, closing at **284**

| line | content |
| --- | --- |
| 276 | `pasien_id BIGINT UNSIGNED NOT NULL` |
| **277** | `tipe_alergen ENUM('obat','makanan','lingkungan','lainnya') NOT NULL` |
| **278** | `nama_alergen VARCHAR(150) NOT NULL` - free text |
| 279 | `reaksi VARCHAR(255) NULL` |
| **280** | `keparahan ENUM('ringan','sedang','berat','anafilaksis') NOT NULL DEFAULT 'ringan'` - four members, and `anafilaksis` is **not** a `tingkat` member |
| **283** | `FOREIGN KEY (pasien_id) REFERENCES patients(id) ON DELETE CASCADE` - the table's **only** FK; nothing joins an allergen to a drug |

### `master_obat` - `CREATE TABLE` at **708**, closing at **729**

| line | content |
| --- | --- |
| 710 | `kode_obat VARCHAR(30) NOT NULL UNIQUE` |
| **711** | `nama_generik VARCHAR(255) NOT NULL` |
| **712** | `nama_brand VARCHAR(255) NULL` |
| 713-714 | `bentuk_sediaan ENUM(...)` - a **wrapped** declaration, twelve members |
| **715** | `kekuatan VARCHAR(50) NULL COMMENT '500 mg'` |
| 716 | `satuan ENUM('tablet','kapsul','botol','tube','ampul','sachet','strip','box') NOT NULL` |
| **718** | `kelas_terapi VARCHAR(100) NULL COMMENT 'Antibiotik, Analgetik, dll'` |
| 719 | `kelas_obat ENUM('bebas','bebas_terbatas','keras','fitofarmaka','<9 ascii letters>','psikotropika') NOT NULL` - six members, and the fifth is byte-verified in section 3.12 |
| 728 | `INDEX idx_obat_nama (nama_generik)` |

### `pasien` - `CREATE TABLE` at **218**, closing at **256**

| line | content |
| --- | --- |
| **242** | `catatan_alergi TEXT NULL` - free prose, not a record, and unsynchronised with `pasien_alergi` |

## 3. Findings: every plan error, with evidence

### 3.1 The plan's `:738` for `uq_interaksi` is off by one

The plan (`:559`) writes `UNIQUE KEY uq_interaksi (obat_a_id, obat_b_id)` (`:738`).
`:738` is the **second FOREIGN KEY**. The key is at **`:739`**. One line out, in
a section whose whole argument is about that key.

### 3.2 The plan's `:277` for `nama_alergen` names the wrong column

The plan writes `pasien_alergi.nama_alergen` is free text (`:277`)`. `:277` is
`tipe_alergen`. `nama_alergen` is **`:278`**.

### 3.3 Two of the plan's table ranges run one line past the closing `ENGINE`

- plan `:708-730` for `master_obat`: `:729` is `) ENGINE=InnoDB;` and `:730` is
  blank.
- plan `:274-285` for `pasien_alergi`: `:284` is `) ENGINE=InnoDB;` and `:285` is
  blank.

The plan's `:742-783` for `resep` + `resep_item` is **correct** (`:765` and `:783`
are the two `ENGINE` lines), as are `:770`, `:771` and `:242`. So the plan is
right about the columns that matter most and wrong about the surrounding frame -
which is why the citations are re-derived here rather than trusted.

Asserted in the test as a receipt: `at(739)` contains the key, `at(738)` contains
`FOREIGN KEY (obat_b_id)`, `at(278)` contains `nama_alergen`, `at(277)` contains
`tipe_alergen`, `at(729)`/`at(284)` are `) ENGINE=InnoDB;`, and `at(730)`/`at(285)`
are empty strings.

### 3.4 Todo 40's `:750` for the eight `resep.status` members is off by one

Todo 40 (`:575`) cites `:750` for "the 8 legal states are `aktif, diproses,
diverifikasi, dipenuhi, dikirim, selesai, kedaluwarsa, dibatalkan` (`:750`)".
`:750` is `tipe ENUM('digital','manual') NOT NULL DEFAULT 'digital'`. The status
ENUM is at **`:751`-`:752`**, and reading `:751` alone yields **five** members, not
eight. Recorded because todo 40 is the next executor to build a state machine on
this column.

### 3.5 The plan's `resep.status IN ('aktif','diproses')` for the clash check is
too narrow - three states are missing

The plan (`:559`) says `cekRiwayatPasien` should join with `resep.status IN
('aktif','diproses')`. That omits `diverifikasi`, `dipenuhi` and `dikirim`, which
are the states in which the patient is receiving or **has received** the drug.
See section 6 for the derived set and why three is the right number.

### 3.6 An impossible acceptance criterion: the 422 belongs to todo 39

Todo 38's acceptance criteria (`:562`) end with "a test asserts adding a
`kontraindikasi` item without `catatan_dokter` returns **422**", while the same
line says todo 39 is where the rule is enforced, and the brief says "Do NOT write
a controller - todo 39 owns the endpoint". A 422 is an HTTP response; this todo
has no request, no response and no route, and adding a route would break todo 39's
route count.

**Resolution:** the RULE is implemented as a domain predicate,
`ObatInteraksiService::wajibCatatanDokter(array $peringatan): bool`, tested
directly; the 422 is todo 39's, where `StoreResepRequest` and the only writer of
`resep.catatan_dokter` (`:753`) live. The criterion is deferred, not met, and this
is the one acceptance item in this todo that is **not** satisfied as literally
written.

### 3.7 The plan asks for `cekAntarItem(array $resepIds)`, but todo 39 needs a
check that runs before the save

`cekAntarItem` reads stored `resep_item` rows, so it needs a prescription id - and
a prescription has an id only after it is saved. Todo 39 composes a prescription
that is not yet written, and a safety check that cannot run before the thing it is
checking runs after it.

**Resolution:** the plan's method exists unchanged, and the engine underneath it
is exposed as `cekAntarObat(array $obatIds)`. Both go through the same
`pasangan()`; the tests cover both. Adding a fourth public method is a small,
documented extension rather than a different design.

### 3.8 The brief's "`+` on strings keeps the left operand" is FALSE on this PHP

Measured, not assumed, on PHP 8.4.17:

| expression | result |
| --- | --- |
| `'amoxi' + 'cillin'` | **THROWS** `TypeError: Unsupported operand types: string + string` |
| `'10' + '20'` | `integer 30` |
| `[1,2] + [3]` | `array [0 => 1, 1 => 2]` - the left operand wins, silently |
| `'amoxi' .= 'cillin'` | `string 'amoxicillin'` |

Keeping the left operand on two strings was PHP 7 behaviour and was **removed in
PHP 8.0**. The silent-no-op failure mode the brief describes is real for
`array + array`, not for `string + string`. The M2 mutation below applies the `+`
anyway and the harness kills it - as 15 **errors**, not failures, which is the
signature of a fatal rather than a silent wrong answer.

### 3.9 `App\Models\ObatInteraksi` casts `tingkat` to `'string'`, not to an enum

The brief warns that an `enum:` cast is a silent no-op without a real PHP enum
class. `app/Models/ObatInteraksi.php:63-69` correctly uses `'tingkat' => 'string'`,
so the no-op trap is not present. The service therefore does **not** depend on the
model at all - it reads `obat_interaksi` through the query builder and compares
`tingkat` against `PERINGKAT`, so there is no cast to be wrong about.

### 3.10 A `Collection::get()` keyed by row position, not by id - a silent zero

Found by this session's own tests, and it is the failure mode this engine must not
have. `DB::table(...)->get()` returns a collection whose keys are row positions
`0, 1, 2`, so `$katalog->get($obatId)` answered `null` for every drug whose id was
not also its position. The engine then returned an **empty warning list** and
looked like it had checked and found nothing - for a safety check, the one outcome
that must never be quiet. Ten of the allergy tests returned 0 warnings and the
service was silently wrong. Fixed with `->keyBy('id')` in `katalog()`, and the
reason is in that method's docblock so it is not "tidied" away.

### 3.11 `laravel/framework` 13.33 cannot express a row-value `IN` list

`(obat_a_id, obat_b_id) IN ((?,?),(?,?))` is the right statement and Laravel 13
does not build it. `Builder::whereRowValues()` compiles
`parameterize($where['values'])` - a **flat** binding list - and guards with
`count($columns) !== count($values)` (`Builder.php:2281-2293`), so `$values` is
ONE row. It was tried and the suite caught it two ways:

```
SQLSTATE[21000]: Cardinality violation: 1241 Operand should contain 2 column(s)
  SQL: select * from `obat_interaksi` where (`obat_a_id`, `obat_b_id`) in (1, 2) order by `id` asc
The number of columns must match the number of values
```

The first is a 1241. The second is worse: when the tuple count happens to equal
the column count, `whereRowValues` silently emits `(a, b) in (1, 2)` - a statement
that returns the wrong rows with no error at all. Replaced with an explicit
disjunction of equality pairs; `barisInteraksi()`'s docblock records why.

### 3.12 `master_obat.kelas_obat` at `:719` - byte-verified, and a docblock that is wrong about it

The brief warns that this project's plan has carried corrupted token examples,
so `:719` was dumped byte-wise rather than read. `telemedicine_test.sql` is
**59604 bytes with exactly 3 non-ASCII bytes**, and all three are `E2 80 94`
(U+2014 EM DASH) on **line 1199**, inside a `[16] SEED DATA` comment banner.
Line 719 itself has **0** non-ASCII codepoints.

`kelas_obat` has **six** members, and the fifth is nine lower-case ASCII letters
with the byte sequence:

```
6E 61 72 6B 6F 74 69 6B 61
```

`database/seeders/ObatSeeder.php:22-40` documents this exact line at length and
resolves its fifth member to `'n<CJK>iktropika'`, then states "The fifth member
is `'n|caption'`". **That account is wrong on both counts:** the member contains
**no CJK codepoint at all** and **no non-ASCII byte**, and the byte sequence above
is nine lower-case ASCII letters. The seeder's own warning - "Reading `:719` in a
console produced a plausible-looking but wrong rendering, so I dumped the member's
bytes instead" - is correct advice that was then not followed, and the
substitution it recorded is itself a corrupted token.

**I nearly filed this the other way.** My first pass compared the member against
`narcotica` (a `c` where the file has a `k`), reported "2 differences", and I was
about to call it a schema corruption. The bytes do not support that: the file is
self-consistent and pure ASCII, and whether the Indonesian clinical term is
spelled with one letter or the other is a language question that a byte dump
cannot answer. **The claim is therefore not made.** The DDL is authoritative, the
service never reads `kelas_obat`, and nothing in this todo depends on it - but a
later todo that seeds or validates a `n`-controlled drug will need the bytes, not
this docblock and not the seeder's.

**The generalisable point:** a non-ASCII byte scan cannot see a wrong *letter*,
only a wrong *codepoint*. The scan in section 10 catches a mangled token that is
still valid UTF-8 in an unexpected place; it cannot catch an ASCII typo. That is
why the strict half of the token audit checks every backticked identifier against
the parsed DDL rather than only checking for stray bytes.

### 3.13 `telemedicine_test.sql` line endings are LF, and the plan's ranges assume a different frame

The repository's SQL file is **LF-only** (`CRLF count: 0`, `LF-only count: 1093`).
That is not a defect - `git status` shows the file unmodified and its SHA-256
matches - but it broke this session's mutation harness twice: two multi-line
search strings written with `\r\n` matched **0 times**, and the mutations silently
did not apply. The harness now reads the separator out of the pristine bytes, and
a mutation that does not apply is a `HARNESS ERROR` rather than a result (section
8). Any future executor writing a multi-line `find`/`replace` for this repository
should do the same.

## 4. The canonicalisation decision, and its cost

### The problem

`obat_interaksi` stores an **ordered** pair (`:733`, `:734`) under a UNIQUE key
over the **ordered** pair (`:739`). The clinical fact - "these two drugs interact"
- is **unordered**. A lookup of `(B, A)` against a row stored as `(A, B)` finds
nothing.

### The decision: canonicalise at READ time, in ONE function

`ObatInteraksiService::pasangan()` is the single function that turns a set of drug
ids into the ordered tuples to look up, and it emits **both** orderings of every
unordered pair. `cekAntarObat()`, `cekRiwayatPasien()` and `cekAntarItem()` all go
through it, so there is exactly one place where bidirectionality could be got
wrong and exactly one mutation (M1) to catch it.

**Why read time and not write time**, on three facts about this repository:

1. **The schema does not enforce an order.** No `CHECK`, and the unique key is
   over the ordered pair - so `(A, B)` and `(B, A)` are two different legal rows.
   A write-time convention is a convention.
2. **This service owns no write path.** The only writer of `obat_interaksi` in the
   repository is `DevFixtureSeeder::seedObatInteraksi()`, which adopts
   `obat_a_id < obat_b_id` and **throws** if it is violated
   (`database/seeders/DevFixtureSeeder.php:66-88, 580-601`). That is a good
   convention, enforced at one call site, and worth nothing to a row inserted by
   any other client, import, migration or future endpoint.
3. **Existing rows cannot be rewritten safely from here.** A pair stored the wrong
   way round is not wrong data; it is a correct fact in a spelling the reader has
   to understand.

### The cost, stated

| cost | size | why it is worth paying |
| --- | --- | --- |
| twice the index probes | 2 per unordered pair | both tuples pin `obat_a_id` by equality, and `obat_a_id` is the **leading** column of `uq_interaksi` (`:739`), so both are index lookups rather than a scan |
| statement width | `2 * C(n, 2)` disjuncts: 90 at ten drugs, 380 at twenty | a prescription is a clinician's list; a hundred-item list is a data error, and a caller with a larger set should chunk its own input |
| the unique key permits a DUPLICATE row | unbounded | `(A, B)` and `(B, A)` are both legal, so read-time canonicalisation cannot **prevent** the duplicate - it can only de-duplicate the consequence |
| no write-time guard for a future writer | one function | `pasanganKanonik()` is public and is the spelling a writer should use; nothing forces its use |

### The duplicate-row case, which is not hypothetical

Two rows, one unordered pair, opposite stored order. The engine emits **one**
warning - because there is one clinical fact - at the **worst** of the two
severities, and sets `rincian.ganda = true` and lists both row ids in
`rincian.baris_tertemu`. Reporting it twice would tell the doctor there are two
interactions where there is one; reporting it once silently would hide that the
table disagrees with itself.

### Both-ordering tests, for SEVERAL pairs

| test | what it proves |
| --- | --- |
| `an interaction row is reported for the pair in BOTH orderings` | stored `(a, b)`; asking `[a, b]` and asking `[b, a]` each produce one warning, and the two are **byte-identical** because the output is canonical |
| `the reverse direction is found for SEVERAL pairs, not one` | five drugs, three rows written in ascending, descending and interleaved stored order; asked for in **both** directions; all three pairs survive both. A hard-coded pair, or a fixture that always wrote ascending ids, cannot pass this. |
| `a stored row is found whichever way round it was written` | the duplicate case above: two rows, one warning, `ganda = true` |
| `the same unordered pair stored twice is reported at its WORST severity` | `ringan` + `kontraindikasi` in opposite orders -> one `kontraindikasi` |
| `every ordered lookup the engine issues is derived from the canonical pair` | asserts the **emitted SQL** contains both directions of both pairs, and that there are exactly **12** `obat_a_id =` lookups for four drugs - a single-direction engine emits 6 |
| `a prescription reports its own interactions in both item orderings` | end-to-end through `cekAntarItem` on stored `resep_item` rows |
| `a racikan item with obat_id NULL yields no interaction warning` | the racikan is dropped by `whereNotNull('obat_id')`, so it never reaches the pair construction at all |
| `a pair of drugs is reported at its worst severity in the aggregate too` | the same de-duplication through `peringatan()` |

`rincian.arah_tersimpan` vs `rincian.arah_diminta` makes the reverse lookup
**visible in the output**, not only in the test: when they differ, the reverse
direction is what found the row.

## 5. The normalisation pipeline, and the adversarial inputs

### The pipeline, in order

`NamaObat::inti(?string): string`

| # | step | why here |
| --- | --- | --- |
| 1 | `trim()` | the commonest typo and the cheapest to remove |
| 2 | `Str::ascii()` | transliterates accented Latin so two spellings fold; runs **before** case-folding because the table it consults is case-aware |
| 3 | `strtolower()` | case-fold; safe as a plain byte operation only because step 2 guarantees ASCII |
| 4 | `preg_replace('/\s+/', ' ')` | collapse every whitespace **run** - tabs, newlines, doubled spaces |
| 5 | `preg_replace('/[^a-z0-9]+/', ' ')` | **every separator becomes a SPACE, never a deletion** |
| 6 | explode, drop empties | tokenise |
| 7 | drop DOSE tokens | all-digit, a bare unit, or `^[0-9]+([.,][0-9]+)?(unit)$` |
| 8 | `implode('', ...)` | the **core**: separator-insensitive, still not a substring |

**Why step 5 replaces and step 8 re-joins.** Deleting the separator is shorter
and wrong the other way: `Analgetik-Antipiretik` - the DDL's own `kelas_terapi`
for `OBT-0001` (`telemedicine_test.sql:1311`) - would become
`analgetikantipiretik` as one opaque token, indistinguishable from a drug
genuinely called that. Replacing with a space keeps two tokens; step 8 rejoins
them, so hyphen / space / dot / double-space are all invisible while
`amoxi-cillin` and `amoxi cillin` and `amoxicillin` all reach `amoxicillin`.

**Why a `LIKE` is the wrong instrument, against the DDL's own data.** Three
traps, all grounded in seeded values:

| trap | `LIKE` | this pipeline |
| --- | --- | --- |
| `amoxicillin` is a **prefix** of `amoxicillin-clavulanate` | fires, warning a patient off a drug containing none of the allergen | cores `amoxicillin` vs `amoxicillinclavulanate` - no match |
| `Antibiotik`, `Antihistamin`, `Antidiabetik`, `Antihipertensi` all contain `Anti` | fires on all four for one allergy | exact cores, one match |
| `ORS` / `Oralit` are two names for one row | concatenated into the invented core `orsoralit` | two SEPARATE cores, so `ORS` matches and `orsoralit` never exists |

**A warning about the wrong drug is worse than no warning.** A doctor trained to
trust the panel stops reading it the first time it lies about something they know.

### The adversarial test cases, and what each one asserts

| input | expected | asserted by |
| --- | --- | --- |
| `'  Amoxicillin  '` (leading/trailing) | match | `the normaliser survives whitespace, case, doubles, hyphens and dots` |
| `'amoxicillin'` (mixed case) | match | same |
| `'AMOXICILLIN'` | match | same |
| `'Amoxi  cillin'` (double space) | match | same |
| `'Amoxi-cillin'` (**hyphen vs space**) | match | same |
| `'Amoxi.Cillin'` (**dot**) | match | same |
| `'Amoxicillin 500 mg'`, `'amoxicillin-500mg'` (dose) | match | same |
| `"\tAmoxicillin\t"` (tab) | match | same |
| `'Amoxicilline'`, `'Amoxycillin'`, `'Amoxicilin'`, `'Amoxilin'`, `'Amoxicill'`, `'Paracetamoll'`, `'Metforimn'` | **no match** | `a genuine near-miss does NOT match` - each checked against three drugs |
| `'Amoxicillin'` vs `Amoxicillin-Clavulanate` | **no match** | `a substring of a DIFFERENT drug name does NOT match` |
| `'Amoxicillin-Clavulanate'` vs `Amoxicillin` | **no match** | same, other direction |
| `'Antibiotik'` vs `Antidiabetik` / `Antihipertensi` / `Antihistamin` | **no match** | `the shared "Anti" prefix ... does not cross-fire` |
| `''`, `' '`, `'   '`, `'---'`, `'...'`, `'- . -'` | **no match** | `an empty, whitespace-only or punctuation-only allergy name matches nothing` |
| brand `'Panadol'` vs `Paracetamol` row | match | `the normaliser matches the brand name ...` |
| `'Analgetik-Antipiretik'` (the DDL's hyphenated class) | works | same |
| `'tipe_alergen' = 'makanan'` naming a drug | **no match** | `only tipe_alergen = obat is read, and only the four DDL types exist` |
| a drug name in `pasien.catatan_alergi` | **no match** | `pasien.catatan_alergi is NOT a second allergy source` |
| generic == brand (two names, one core) | **one** warning | `an allergy fires ONCE per allergen and drug, however many names match` |
| a racikan's free-text name | **no match** | `a snapshot prescription name is matched, and a racikan name is not silently matched` |

### The `kelas_terapi` fallback, with its two gates

The plan asks for a fallback to `kelas_terapi` (`:718`) for `ringan`/`sedang`
severity only. Implemented as **equality on the normalised core**, with the
severity gate intact:

| `keparahan` | class-level match fires? | asserted |
| --- | --- | --- |
| `ringan` | yes (2 drugs in the class) | `the therapy-class fallback is gated to the two mild severities the plan names` |
| `sedang` | yes | same |
| `berat` | no | same |
| `anafilaksis` | no | same |
| any | a **name** match always fires | same |

`rincian` reports `inti_alergi`, `inti_kandidat` and `inti_cocok` separately, plus
`jalur` (`nama` | `kelas_terapi`). Reporting the core that actually **met** the
record is what stops a reader debugging a class-level panel from looking at the
drug's name core instead of its class.

### `anafilaksis` is a MAPPING, not a cast

`pasien_alergi.keparahan` is a FOUR-value ENUM including `anafilaksis` (`:280`);
`obat_interaksi.tingkat` is a FOUR-value ENUM that does **not** (`:735`). A direct
assignment produces a severity the column cannot hold. `KEPARAHAN_TINGKAT` maps
`anafilaksis -> kontraindikasi`, and the test asserts the mapping's **keys** equal
the parsed `keparahan` ENUM, its **values** are all real `tingkat` members, and
every `tingkat` member is reachable. This is exactly the "two same-named ENUMs
spelled differently" case the enum-value bucket exists for, and the audit prints
it: `ringan` and `berat` live on **both** `obat_interaksi.tingkat` and
`pasien_alergi.keparahan`.

## 6. The derived `resep.status` set

`resep.status` is **eight** members at **`:751`-`:752`** (`DEFAULT 'aktif'`), and
the declaration wraps onto two lines.

| state | meaning | currently on? |
| --- | --- | --- |
| `aktif` | written, not yet processed | **yes** - and it is the DDL's own `DEFAULT`, so a prescription is born live |
| `diproses` | being handled by the pharmacy | **yes** |
| `diverifikasi` | pharmacist-verified, not yet handed over | **yes** - the patient is about to be on it |
| `dipenuhi` | dispensed | **yes** |
| `dikirim` | in transit | **yes** - the patient has it, or will have it today |
| `selesai` | course COMPLETED | **no** - the plan's own acceptance criterion singles this one out |
| `kedaluwarsa` | EXPIRED | **no** |
| `dibatalkan` | CANCELLED | **no** |

**`STATUS_BERLAKU` = `['aktif','diproses','diverifikasi','dipenuhi','dikirim']`**
(5 inclusions). **`STATUS_AKHIR` = `['selesai','kedaluwarsa','dibatalkan']`**
(3 exclusions).

### How it is DERIVED, not assumed

`the live prescription status set is DERIVED from the DDL, not guessed` asserts,
against the **parsed** DDL rather than a transcription:

1. the DDL's eight members;
2. `count(STATUS_BERLAKU) + count(STATUS_AKHIR) === 8` - no losses;
3. `array_intersect()` empty - no overlap;
4. `STATUS_BERLAKU === array_values(array_diff($dariDdl, STATUS_AKHIR))` -
   **positionally**, so a reordering fails too;
5. `STATUS_AKHIR === ['selesai','kedaluwarsa','dibatalkan']` - the three whose
   names say the course ended;
6. the DDL's `DEFAULT 'aktif'` is in `STATUS_BERLAKU` and not in `STATUS_AKHIR`.

A fourth terminal state added to the schema, or a typo in a hand-typed member,
fails here rather than silently changing which prescriptions clash.

### Isolation tests, so the filter is doing the work

- `a clash is reported against every LIVE prescription status` - one prescription
  per live status, each holding Metformin; the count equals `count(STATUS_BERLAKU)`.
- `a clash is NOT reported against a terminal prescription status` - one per
  terminal status, expecting `[]`; then **swapping in one `aktif` prescription
  and expecting exactly 1**, so the empty result cannot be the fixture writing
  nothing.
- `a prescription whose berlaku_sampai has elapsed is not a clash even while
  aktif` - and the date is **inclusive** (valid through today is still taken
  today).

### The date guard, and why the status set alone is not enough

`berlaku_sampai` is `DATE NOT NULL COMMENT 'E-resep berlaku 7 hari'` (`:755`) and
**nothing in the schema reacts to it** - no trigger, no generated column, no event.
A prescription whose validity lapsed three days ago can therefore read `aktif` and
be "currently on" at the same time, and the status column cannot be the whole
answer.

The date is compared in **PHP**, not in SQL, because a `CURDATE()` in the query
and a `Carbon::today()` in the test are two different clocks whenever the
application and the database disagree about a timezone - and the resulting silent
mismatch is a warning that appears and disappears.

**This is beyond the plan.** The plan does not mention the date. It is added
because the brief says "a cancelled or **expired** prescription is not a clash"
and because false positives are explicitly the worse failure. M5 removes the guard
and the suite goes red.

## 7. TDD: red, then green

### RED - the test file first, failing for the right reason

```
php artisan test --filter=ObatInteraksiServiceTest
{"tool":"pest","result":"failed","tests":35,"passed":1,"assertions":49,
 "duration_ms":21021,"errors":34,
 "error_details":[
  {"message":"Target class [App\Services\Obat\ObatInteraksiService] does not exist.",
   "trace":["...Container.php:1147","...ObatInteraksiServiceTest.php:273"]},
  ... 33 more, all the same ]}
EXITCODE=2
```

**34 errors, every one of them `Target class [App\Services\Obat\ObatInteraksiService]
does not exist`, exit 2.** The 1 pass is the DDL-citation receipt, which reads the
SQL file and needs no class. That is the correct RED: the suite is red because
the thing under test is absent, not because an assertion is wrong.

The first attempt also surfaced **three failures that were bugs in my test file**,
not in a missing class, and they were fixed before the implementation existed -
`TableSpec::$indexes` is a **list**, not keyed by name (so the key must be found
with `firstWhere('name', ...)`), Pest's `toContain('')` on a list is not a
reliable way to assert "no empty member", and a stray `substr_ini('')` call.

### The first GREEN attempt and what it caught

```
tests=38, passed=7, failed=10, errors=21
```

Two real implementation bugs, both found by the suite:

1. `whereRowValues` with a list of tuples (finding 3.11) - a 1241, and worse, a
   silent `(a, b) in (1, 2)`.
2. `Undefined array key "inti"` in `susunAlergi` - the candidate core entry was
   keyed `tujuan` and read as `inti`, so the name path never matched.

```
tests=39, passed=23, failed=14, errors=1
```

Then **finding 3.10** - `katalog()` not keyed by id - which is the one that
matters: ten allergy tests returned 0 warnings and the service was silently
wrong, returning an empty list where a safety check had found nothing.

```
tests=39, passed=37, failed=2
```

Both remaining failures were **my test expectations**, not the service: a class
allergy legitimately fires on *every* drug in the class (two, not one), and one
test used `keparahan = 'berat'` for a case that is gated to `ringan`/`sedang`.

### GREEN

```
php artisan test --filter=ObatInteraksiServiceTest
{"tool":"pest","result":"passed","tests":39,"passed":39,"assertions":314,"duration_ms":31990}
EXITCODE=0
```

## 8. Mutation testing, with the control

The harness is `C:\Users\axioo\AppData\Local\Temp\opencode\mutate38.ps1`
(pastor's run) / `mutate38.ps1`. Two properties had to be proven before any of its
failures could be believed.

### Control 1: green is read as green

The pest JSON reporter **OMITS** `failed` and `errors` when both are zero. The
control run's own line is the proof:

```
result=passed tests=39 passed=39 failed=0 errors=0 red=False 'failed'-key-present=False exit=0
```

`'failed'-key-present=False` - the key is absent on a clean run, so
`$j.failed` is `$null`. The harness reads an **absent** key as zero, and a
present one as its value. A naive parser reads `$null` as a failure and calls this
green suite red, which would "prove" every mutation kills the suite without
mutating anything. The harness `throw`s if the control is red.

### Control 2: every mutation is actually applied

A literal search/replace that misses leaves the file untouched, the suite stays
green, and the harness would record a vacuous "mutation survived". The harness
counts matches, requires exactly 1, and then re-reads the file to confirm it is
**not byte-identical**. A mutation that did not apply is reported as
`HARNESS ERROR - not applied` and the run throws.

**This control fired for real, twice.** The repository files are **LF-only**, and
the harness had assumed CRLF in its two multi-line search strings, so M1 and M5
reported `matched 0 times`:

```
M1  HARNESS ERROR - the search string matched 0 times, so the mutation was NOT applied
M5  HARNESS ERROR - the search string matched 0 times, so the mutation was NOT applied
```

Without the control those two would have read as "the mutation SURVIVED" - the
exact false negative the control exists to prevent, pointing the wrong way. The
harness now reads the separator out of the pristine bytes.

### Results

```
line ending in the pristine service file: LF

=== CONTROL (no mutation applied) ===
  result=passed tests=39 passed=39 failed=0 errors=0 red=False 'failed'-key-present=False exit=0
  OK. Green is read as green (the absent "failed"/"errors" keys are treated as zero).

=== MUTATIONS ===
  M1  applied=yes  tests=39 passed=33 failed=6  errors=0 -> KILLED (suite went red)
  M2  applied=yes  tests=39 passed=24 failed=0  errors=15 -> KILLED (suite went red)
  M3  applied=yes  tests=39 passed=37 failed=2  errors=0 -> KILLED (suite went red)
  M4  applied=yes  tests=39 passed=37 failed=2  errors=0 -> KILLED (suite went red)
  M5  applied=yes  tests=39 passed=38 failed=1  errors=0 -> KILLED (suite went red)
  M6  applied=yes  tests=39 passed=36 failed=3  errors=0 -> KILLED (suite went red)

post-revert: result=passed tests=39 passed=39 failed=0 errors=0
ALL MUTATIONS KILLED, AND THE SUITE IS GREEN AFTER THE REVERT.
EXITCODE=0
```

| id | site | mutation | why it must be fatal |
| --- | --- | --- | --- |
| **M1** | `pasangan()` | drop `$keluar[] = [$b, $a];` | makes the pair lookup single-direction - **the acceptance criterion** |
| **M2** | `NamaObat::inti()` | `$inti .= $token;` -> `$inti = $inti + $token;` | kills the multi-token core. Killed as **15 errors**, not failures: on PHP 8.4 `string + string` is a `TypeError` (finding 3.8), so this is a fatal, not a silent wrong answer. The signature is the opposite of the "silently does nothing" case the brief describes, and the difference is a version fact, not an opinion. |
| **M3** | `cekAlergi()` | `===` -> `str_contains($inti['inti'], $cocok['inti'])` | turns name equality into a substring test - the `LIKE` failure mode |
| **M4** | `STATUS_BERLAKU` | widen to all eight states | a cancelled or finished course counts as a clash |
| **M5** | `cekRiwayatPasien()` | remove the `berlaku_sampai` guard | a prescription that lapsed three days ago still reads as a clash |
| **M6** | `pasanganKanonik()` | `return [$a, $b];` | makes canonicalisation identity, so the output orientation follows the caller instead of the pair |

M1 is the QA scenario the plan asks for ("delete the reverse-direction query and
assert the both-direction test fails") and it kills **six** tests.

## 9. Warnings do not block, and the list is ordered and de-duplicated

No method refuses anything. `wajibCatatanDokter()` is a predicate, not a gate.

Every warning carries one shape, so todo 39/40 can group by `sumber` and render
one panel:

```
sumber                one of SUMBER = [antar_item, riwayat_resep, alergi]
kunci                 stable identity, unique within a result set
tingkat               one of TINGKAT, worst first
deskripsi             the row's own TEXT, or a built sentence for an allergy
obat_a, obat_b        {id, nama}; canonical, lower id first; obat_b null for an allergy
rincian               per-source detail
wajib_catatan_dokter  bool, true only for a kontraindikasi
```

**The order is a TOTAL order** - severity (the reverse of the DDL's ascending
ENUM), then the declared source order, then `kunci` byte order - and the third
term is unique within a set. That is what makes "the same inputs produce
byte-identical output" a property rather than a hope, whatever order the caller
listed its drugs in. Asserted: consecutive elements are non-decreasing on the
triple, the exact 4-element output is pinned, and three different input
permutations produce `toEqual` output.

**De-duplication is on `kunci`**, which carries the source - so one clinical fact
from two database rows collapses to one, while one fact from two different
*sources* stays two panels.

## 10. A.26: non-ASCII scan and token audit

### Part 1 - byte-level non-ASCII scan

Raw-byte reads (`strlen` / `ord` per byte), not a decoded regex, so an encoding
mistake cannot hide. Allowance per the brief:
`[^\x00-\x7F \u2013 \u2014 \u2022 \u2026 \u2192 \u2212 \u00A7 \u2225]`.

```
=== A.26 part 1: byte-level non-ASCII scan ===
  app/Services/Obat/ObatInteraksiService.php           bytes=44014   non-ascii=0   violations=0
  app/Services/Obat/NamaObat.php                       bytes=8496    non-ascii=0   violations=0
  tests/Feature/Obat/ObatInteraksiServiceTest.php      bytes=57752   non-ascii=0   violations=0
  TOTAL authored bytes=110262, non-ascii bytes=0, violations=0
```

**110262 bytes, 0 non-ASCII bytes, 0 violations.** The comparison operators in
`NamaObat` are the ASCII spellings `>=` and `<=`, not the mathematical
"greater-than-or-equal" and "less-than-or-equal" glyphs; the tables use the
ASCII pipe `|` and not a box-drawing character; every arrow is the ASCII `->`.

**This evidence file failed its own scan first, three times, and the failures
are worth recording.** A byte-level scan of the four files this todo authored
found **0** non-ASCII bytes in the three source files and **9** in this one:
`E2 89 A5`, `E2 89 A4` and `E2 94 82` - in the sentence quoted above, which
claimed those glyphs were not used. The scan was right and the sentence was
wrong, in the same commit, about itself. It is the same failure class as the two
corrupted tokens this ledger already carries, and it is why the scan is
byte-level and measured on the artefact rather than reasoned about.

### Part 2 - token audit against the DDL, via `SqlSchemaParser`

```
DDL buckets: tables=75 columns=351 enum-values=243 index-names=30

app/Services/Obat/ObatInteraksiService.php    candidates=80  unknown=0  unknown-and-backticked=0
    strict backtick check: all 47 backticked identifiers resolve to the DDL
app/Services/Obat/NamaObat.php                candidates=22  unknown=0  unknown-and-backticked=0
    strict backtick check: all 8 backticked identifiers resolve to the DDL
tests/Feature/Obat/ObatInteraksiServiceTest.php candidates=104 unknown=0 unknown-and-backticked=0
    strict backtick check: all 60 backticked identifiers resolve to the DDL

bucket hits: {"table":14,"column":73,"enum-value":32,"index-name":2,
              "service":37,"unit":14,"normalised-example":7,"php":21,"fixture":6}

non-ascii violations: 0
token audit problems: 0
STRICT backtick violations: 0
```

The **strict backtick check** is the one with teeth: a backtick is SQL quoting,
so each of the 115 backticked identifiers is an unambiguous claim about a schema
object. All 115 resolve to a real table, column, index name or enum value.

**The audit script itself had a bug, and it is the exact class this bucket
exists for.** The first version used `$spec->tableNames()` as an array - a LIST
of names at indices `0..74` - so `isset($tables['master_obat'])` was false for
every table and **80 tokens were reported unknown**, five of which
(`master_obat`, `obat_interaksi`, `resep_item`, `pasien_alergi`, `users`) really
are tables. The audit was wrong, not the code. Recorded rather than quietly fixed.

### The enum-value bucket, per value

Every value the authored files quote, and every column declaring it. This is the
bucket that catches two same-named ENUMs spelled differently:

```
ringan     <- obat_interaksi.tingkat, pasien_alergi.keparahan
sedang     <- obat_interaksi.tingkat, pasien_alergi.keparahan
berat      <- obat_interaksi.tingkat, pasien_alergi.keparahan
anafilaksis<- pasien_alergi.keparahan          <- the 4th member, and NOT a `tingkat` member
kontraindikasi <- obat_interaksi.tingkat
kedaluwarsa <- pembayaran.status, resep.status, rujukan.status
aktif      <- pasien_riwayat_penyakit.status, resep.status, rujukan.status, users.status
dibatalkan <- booking.status, home_care_pesanan.status, invoice.status,
              konsultasi.status, lab_permintaan.status, pesanan_obat.status, resep.status
selesai    <- booking.status, home_care_pesanan.status, konsultasi.status,
              pesanan_obat.status, resep.status
obat/makanan/lingkungan/lainnya <- pasien_alergi.tipe_alergen
```

Three cross-checks the audit runs explicitly:

- `ringan` / `berat` live on **both** `obat_interaksi.tingkat` and
  `pasien_alergi.keparahan` - a direct assignment between them is a **type
  change**, not a copy, which is why `KEPARAHAN_TINGKAT` is a mapping.
- `master_obat.kekuatan` read from the **raw DDL line 715**:
  `kekuatan VARCHAR(50) NULL COMMENT '500 mg',` - `ColumnSpec::$default` is the
  DEFAULT clause and the parser does not extract COMMENTs, so the raw line is
  read. `mg` is present in `NamaObat::SATUAN` (15 units, all lower-case, all
  ASCII).
- `master_obat.bentuk_sediaan` and `master_obat.satuan` are **different
  eight-and-twelve-value vocabularies** that overlap on `tablet` and `kapsul`;
  `OBT-0007` (`:1317`) is `sirup` by `bentuk_sediaan` and `sachet` by `satuan`.
  The pipeline never reads either, so it cannot confuse them.

### Part 3 - this evidence file, audited by the same three checks

```
non-ascii violations      : 0
token audit problems      : 0
out-of-range citations    : 0
spot-check mismatches     : 0
```

**The self-measuring totals are deliberately NOT recorded here.** An earlier
draft of this block did record them - `evidence bytes=48270`,
`snake_case candidates=56`, `DDL citations=51` - and all three were wrong by the
time the file was committed, because writing the block that reports the file's
own size changes the file's size. A self-referential measurement is only
consistent if it is not stored in the thing it measures, so the four **invariant**
results above are what this file claims and the sizes live in the harness output
alone. The three source files' totals *are* recorded in Part 1, because editing
this file does not change them.

Three sub-checks beyond the source scan, and **all three caught a defect in the
audit or the file on the first pass**:

1. **Every DDL `:NNN` citation is in range.** The first pass matched a bare
   `:(\d+)` across the whole file and reported **8 out-of-range** - every one a
   false positive: `"duration_ms":390431` is a duration, and `:2281-2293` is a
   `vendor/laravel/framework` path. The regex now only matches a citation written
   the way this project writes one (backticked, or attributed to the SQL file).
   **0 out of range**, and the one foreign citation is verified against the vendor
   source it names (`:2281` `whereRowValues`, `:2284` the count guard, `:2291`
   `cleanBindings`) rather than skipped.
2. **Every cited line still says what this file claims** - 47 spot-checks with
   `str_contains` against the line read from the file. The first pass used `===`
   and reported **18 mismatches**; all 18 were the *audit's own* expectation
   strings missing a trailing comma, and the evidence file was right on every
   one. A 19th was a token **I typed into the audit** that did not match the file
   and which no citation in this file relies on; it was removed rather than
   "fixed", because an expectation string written by the same hand as the claim
   cannot independently check the claim. That is the whole reason the other
   checks read from the file.
3. **Every snake_case token resolves** - 56 candidates, 8 tables, 22 columns, 1
   enum value, 25 listed prose words, **0 unclassified**.

The `:719` row in section 2 was itself written with a corrupted token twice
before it was replaced by `<9 ascii letters>` and a byte dump, and
`telemedicine_test.sql:719` is spot-checked here like every other citation.

## 11. Suite output, verbatim

Baseline, before anything in this todo, on `telemedisin_db_test_38`:

```
{"tool":"pest","result":"passed","tests":719,"passed":719,"assertions":11513,"duration_ms":312557}
```

After:

```
{"tool":"pest","result":"passed","tests":758,"passed":758,"assertions":11827,"duration_ms":390431}
EXITCODE=0
```

| | before | after | delta |
| --- | --- | --- | --- |
| tests | 719 | 758 | **+39** (all mine, in one new file) |
| passed | 719 | 758 | **+39** |
| failed | 0 | 0 | **0** |
| errors | 0 | 0 | **0** |
| assertions | 11513 | 11827 | **+314** |
| skipped | 0 | 0 | **0** - no `markTestSkipped`, and `tests/TestCase.php` has no skip helper left |

`route:list --path=api/v1` -> `Showing [57] routes`, exit 0, **unchanged**.

## 12. Acceptance criteria, one by one

| plan criterion (todo 38, `:562`/`:563`) | status |
| --- | --- |
| `php artisan test --filter=ObatInteraksiServiceTest` exits 0 | **met** - exit 0, 39/39 |
| a test inserts `obat_interaksi` with `obat_a_id = 5, obat_b_id = 9` and asserts the service reports it for a prescription containing drugs 9 and 5 - the both-direction test | **met, and strengthened** - ids are looked up, not hard-coded to 5/9, and the test asserts it in both orderings across **three** pairs and five drugs, plus the exact emitted SQL |
| a test asserts a `riwayat_resep` warning fires against an `aktif` prescription but not a `selesai` one | **met, and extended** - the whole five-member live set and the whole three-member terminal set are exercised, with a swap-in isolation so the empty result is not vacuous |
| a test asserts an allergy warning fires on a normalised match (`"Amoxicillin 500mg"` vs `"amoxicillin"`) | **met, and extended** - nine adversarial spellings, seven near-misses that must not match, and two cross-drug substrings |
| a test asserts a racikan item with `obat_id = NULL` yields **no** interaction warning | **met** |
| a test asserts adding a `kontraindikasi` item without `catatan_dokter` returns 422 | **NOT MET, deferred to todo 39** - see finding 3.6. The rule is implemented and tested as `wajibCatatanDokter()`; a 422 needs a controller this todo must not write |
| failure QA (a) delete the reverse-direction query and assert the test fails | **met** - M1, kills 6 tests |
| failure QA (b) a racikan item yields zero interaction warnings | **met** |
| failure QA (c) a `kontraindikasi` item without a doctor note is rejected | **partial** - the predicate is tested; the rejection is todo 39's |
| evidence at `.omo/evidence/task-38-sehatly.md` | **met** - this file |

## 13. What is deliberately NOT here

- **No controller, no route, no FormRequest, no Resource.** Todo 39 owns the
  endpoint; adding a route would break its count. 57 routes before and after.
- **No write path for `obat_interaksi`.** The service is read-only over it, and
  `pasanganKanonik()` is published as the spelling a future writer should use.
- **`pasien.catatan_alergi` is not read** (`:242`) - free prose, unsynchronised,
  and matching it would fire on any drug name a doctor wrote in a note. Tested as
  a documented non-goal.
- **A racikan's free-text name is not matched as a substance.** A mixture has no
  single substance to compare.
- **No migration, no seeder, no SQL edit, no `web/`, no `packages/`, no
  `mobile/`,** and no plan checkbox.
- **The `QueryException` branch is not exercised.** The service does not catch
  database errors, by choice (the class docblock gives the reason: an empty list
  is a positive claim, and returning one when the check never ran is a false
  record). The "never throws" contract is therefore tested where it is
  meaningful - degenerate input produces an empty list, not an exception - and
  the infrastructure branch is documented as deliberately unexercised rather
  than covered by a test that would have to break the connection to reach it.
