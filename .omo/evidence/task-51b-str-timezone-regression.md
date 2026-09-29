# task 51b - the STR boundary must be read on the clinic's calendar day

Base: `7d44bdf` (HEAD + 4 unmerged todo-49 commits). Fix commits: `3b007c6` (RED
test), `9fe92a3` (fix), `016064e` (pint baseline restored).

---

## 1. The exact code location

**`app/Services/Dokter/DokterDirectoryService.php`, `today()`, line 360 before the
fix:**

```php
$row = DB::selectOne('SELECT CURDATE() AS hari');

$this->today = Carbon::parse((string) $row->hari)->startOfDay();
```

That value is what `strBelumKedaluwarsa()` hands to
`StrBerlaku::terapkan($query, 'd.str_berlaku_sampai', $this->today($asOf))`, and
`StrBerlaku::terapkan()` emits `whereNotNull(...) + whereDate('d.str_berlaku_sampai', '>=', $hari)`.
So the right-hand side of the eligibility comparison was a **`SELECT CURDATE()`**.

`CURDATE()` is a **server-local wall clock read**. Two independent facts made it
the UTC day rather than the clinic's:

- `config/database.php` line 70: `'timezone' => env('DB_TIMEZONE', '+00:00')`,
  and `DB_TIMEZONE` is **not** in `.env`, so the Laravel connector issues
  `SET time_zone='+00:00'` on every MySQL connection. `CURDATE()` is therefore UTC.
- `config/app.php` / `APP_TIMEZONE` is `UTC`.

Asia/Jakarta is a fixed `+07:00` with no DST, so for **00:00-07:00 WIB the UTC
calendar day and the Jakarta calendar day are different dates.**

### Measured, on this host, at 2026-09-29 18:25 UTC (= 2026-09-30 01:25 WIB)

```console
$ php artisan tinker --execute="echo config('database.connections.mysql.database'),
  '|', DB::selectOne('SELECT CURDATE() AS h, @@session.time_zone AS tz')->tz,
  '|', DB::selectOne('SELECT CURDATE() AS h')->h,
  '|', App\Support\WaktuIndonesia::tanggal();"
telemedisin_db_test_51b|+00:00|2026-09-29|2026-09-30
```

The two columns that matter are the last two: the database's `CURDATE()` says
`2026-09-29` and the clinic's calendar says `2026-09-30`. `@@session.time_zone` is
`+00:00`, so the UTC value is the real one, not a coincidence of host settings.

The todo-51 executor had already closed this exact hole in
`SlotAvailabilityService::hariIni()` (see `docs/timezone-policy.md` line 111 and
that class's `hariIni()` docblock). `DokterDirectoryService` was the sibling it
was not applied to, and the test that read `jslHariIni()` was moved onto
`WaktuIndonesia` in `b2c2988` without the service moving with it - which is why
the suite only fails between midnight and seven in the morning WIB.

---

## 2. The corrected comparison, and why

```php
private function today(?CarbonInterface $asOf): CarbonInterface
{
    if ($asOf !== null) {
        return $asOf->copy()->startOfDay();
    }

    if ($this->today === null) {
        $this->today = WaktuIndonesia::now()->startOfDay();
    }

    return $this->today->copy();
}
```

`WaktuIndonesia::now()` is `CarbonImmutable::now('Asia/Jakarta')`: **the same
instant** as `now()`, expressed on the clinic's wall clock. The instant is what
makes "now" mean now; the wall clock is what makes the derived `Y-m-d` the day
the licence is written in. Its own docblock names this failure - *"comparing a
Jakarta wall clock against `now()` in UTC is the seven-hour bug this class
prevents"*.

**The docblock's `<today>` is now explicit.** The class docblock read

```
 *     str_berlaku_sampai >= <today>
```

with `<today>` unspecified, and that ambiguity was the defect. It now reads

```
 *     d.str_berlaku_sampai >= (today in Asia/Jakarta)
```

and carries a table naming the date basis of **every** predicate in the query, so
a future reader can tell a deliberate basis from an inherited one:

| predicate | basis | source |
| --- | --- | --- |
| rule 1 `status_verifikasi = 'terverifikasi'` | **none** - a state, not a date | `v_dokter_katalog`'s own `WHERE`, `:1183` |
| `u.dihapus_at IS NULL` | **none** - a state; a `TIMESTAMP` tested only for NULLity, never compared | `:148` |
| rule 2 `str_berlaku_sampai >= <today>` | **Asia/Jakarta wall clock** | `today()` -> `WaktuIndonesia::now()` |

Only the third row carries a date basis at all, and it is now the clinic's.

### The type had to be widened too, or the fix would have been a `TypeError`

`laravel/framework` 13.33 boots with `Date::use(CarbonImmutable::class)`, so
`WaktuIndonesia::now()` returns a `Carbon\CarbonImmutable` - a **sibling** of
`Illuminate\Support\Carbon`, not a subclass. Every signature that would receive
it was widened from `Carbon` to `CarbonInterface`:

- `DokterDirectoryService`: the `$today` property, `list()`, `find()`, `query()`,
  `strBelumKedaluwarsa()`, `today()`.
- `StrBerlaku::berlakuPada()` and `StrBerlaku::terapkan()`.

`StrBerlaku::berlakuPada()` also now builds its left operand with
`CarbonImmutable::instance()` instead of `Carbon::instance()`, because a `date`
cast on a model that hydrates immutably already hands back a `CarbonImmutable`
and the concrete-class version was the bug todo 40 recorded.

---

## 3. Audit: every DATE (and TIME) compared to a clock

Method: the 19 `DATE` columns and 4 `TIME` columns were taken from the
`SqlSchemaParser` inventory in `docs/timezone-policy.md` (lines 201-216, 29-36),
then every occurrence of each column name and of `now()` / `today()` /
`toDateString()` / `startOfDay()` / `whereDate(` / `CURDATE` was read in `app/`.

**Verdicts. `WRONG` means a UTC-derived day or time was compared against a clinic
wall clock, which is the defect this task is about.**

| # | site | column | comparison | date basis it used | verdict |
| --- | --- | --- | --- | --- | --- |
| 1 | `DokterDirectoryService::today()` | `dokter.str_berlaku_sampai` (DATE) | `whereDate(col, '>=', $today)` | **`CURDATE()` = UTC** | **WRONG - the named defect. FIXED** |
| 2 | `ResepStateMachine::kedaluwarsa()` | `resep.berlaku_sampai` (DATE) | `$sampai->format('Y-m-d') < $today` | **`Carbon::today()` = UTC** | **WRONG. FIXED** |
| 3 | `ObatInteraksiService::cekRiwayatPasien()` | `resep.berlaku_sampai` (DATE) | `(string) $row->berlaku_sampai < $today` | **`Carbon::today()` = UTC** | **WRONG. FIXED** |
| 4 | `BookingService::kadaluarsa()` | `booking.tanggal_kunjungan` (DATE) | `tanggal_kunjungan < $hariIni` | **`CURDATE()` = UTC** | **WRONG. FIXED** |
| 5 | `BookingService::kadaluarsa()` | `booking.slot_mulai` (TIME) | `slot_mulai <= $jamIni` | **`Carbon::now()->format('H:i:s')` = UTC clock** | **WRONG. FIXED** |
| 6 | `BookingService` rule 1b (instant booking) | `booking.tanggal_kunjungan` (DATE) | `$tanggal === $hariIni` | **`CURDATE()` = UTC** | **WRONG. FIXED** |
| 7 | `BookingService` rule 1b (instant booking) | `slot_selesai` (TIME) | `$slotSelesai <= Carbon::now()->format('H:i:s')` | **UTC clock** | **WRONG. FIXED** |
| 8 | `ResepService::tulisDenganNomorUnik()` | `resep.berlaku_sampai` (DATE) | **written** as `now()->addDays(7)->toDateString()` | **UTC day**, so a day short for 7 h/day | **WRONG. FIXED** |
| 9 | `ResepService::tulisDenganNomorUnik()` | `resep.tanggal_resep` (DATETIME, wall clock per the policy) | **written** as `now()` | **UTC wall clock** | **WRONG. FIXED** |
| 10 | `ResepService::tulisDenganNomorUnik()` | `resep.nomor_resep` (document number) | **written** from `now()->toDateString()` | **UTC day** | **WRONG. FIXED** |
| 11 | `PesananObatService::tulisDenganNomorUnik()` | `pesanan_obat.nomor_pesanan` (document number) | **written** from `now()->toDateString()` | **UTC day** | **WRONG. FIXED** |
| 12 | `SuratKeteranganService` rujukan default | `rujukan.berlaku_sampai` (DATE) | **written** as `now()->addDays(14)->toDateString()` | **UTC day**, so a referral expires a day early | **WRONG. FIXED** |
| 13 | `SuratKeteranganService::tulisDenganNomorUnik()` | `surat_keterangan.nomor_surat` (document number) | **written** from `now()->toDateString()` | **UTC day** | **WRONG. FIXED** |
| 14 | `SuratKeteranganService` QR payload | `surat_keterangan.dibuat_at` (TIMESTAMP) | `Carbon::instance($dibuat_at)->toDateString()` published as `tanggal` | instant read in **UTC**, then reduced to a **day** | **WRONG (the day, not the instant). FIXED** |
| 15 | `AuthController::nomorRekamMedis()` | `pasien.nomor_rm` (`RM-YYYYMM-XXXXXX`) | **written** from `now()->format('Ym')` | **UTC month** | **WRONG. FIXED** |
| 16 | `SlotAvailabilityService::hariIni()` + rule 1b | `d.str_berlaku_sampai`, `dokter_jadwal.jam_*` | `>=` and `<= now` | already `WaktuIndonesia` (todo 51) | **CORRECT** |
| 17 | `SlotAvailabilityService` rule 2/3 | `dokter_libur.tanggal`, `dokter_jadwal.berlaku_mulai/sampai` (DATE) | `whereDate(..., $tanggal)` | **caller-supplied `Y-m-d`** - no clock involved | **CORRECT** |
| 18 | `BookingService` instant booking | `d.str_berlaku_sampai` (DATE) | `StrBerlaku::berlakuPada(..., Carbon::parse($tanggal))` | **caller-supplied `Y-m-d`**; both operands reduced to `startOfDay()`, so the parse's own zone is irrelevant | **CORRECT** |
| 19 | `BookingService::list()` | `booking.tanggal_kunjungan` (DATE) | `whereDate('tanggal_kunjungan', $filter['tanggal'])` | **caller-supplied `Y-m-d`** | **CORRECT** |
| 20 | `PromoService::nilai()` | `master_promo.mulai_at` / `selesai_at` (DATETIME, wall clock) | window test | already converted to `InvoiceService::ZONA_WALL_CLOCK` before comparing | **CORRECT** |
| 21 | `SuratKeteranganService::tanggal()` | `surat_keterangan.tanggal_mulai` / `tanggal_selesai` (DATE) | `mulai <= selesai`, 30-day span | **two caller-supplied `Y-m-d` values** - no clock involved | **CORRECT** |
| 22 | `RekamMedisResource`, `PasienResource`, `RujukanResource`, `SuratKeteranganResource`, `ResepResource`, `BookingResource`, `KonsultasiResource` | `pasien.tanggal_lahir/meninggal`, `pasien_anggota_keluarga.tanggal_lahir`, `rekam_medis.jadwal_kontrol`, `rujukan.berlaku_sampai`, `booking.tanggal_kunjungan` | `?->toDateString()` | **publication only** - a `DATE` is never converted | **CORRECT** |
| 23 | `apotek_stok.kedaluwarsa`, `pasien_penjamin.masa_berlaku_akhir`, `dokter.sip_berlaku_sampai`, `klaim_bpjs.tanggal_sep/pulang`, `pasien_imunisasi.tanggal`, `surat_keterangan.tanggal_mulai/selesai` | the 12 remaining `DATE` columns | **no comparison with any clock exists** - model casts, `$fillable` lists, request `date_format` rules, or display only | n/a | **CORRECT (no predicate)** |
| 24 | `PesananObatService` line 830 | `pesanan_obat_tracking.waktu` (DATETIME) | `= Carbon::now()` | **genuinely an instant** per the policy's allow-list | **CORRECT** |
| 25 | `OtpService`, `TokenService` | `user_otp.kedaluwarsa_at`, `user_refresh_tokens.kedaluwarsa_at`, `users.last_login_at` | `now()` | **instants** | **CORRECT** |
| 26 | `KonsultasiService`, `RekamMedisService`, `PdpConsentService`, `PaymentService`, `ResepVerifikasiService`, `AuditLogWriter`, `NotifikasiController` | `mulai_at`, `selesai_at`, `tanggal_periksa`, `ditandatangani_at`, `disetujui_at`, `dibayar_at`, `diverifikasi_at`, `dibaca_at`, `last_active_at`, `dibuat_at` | `Carbon::now()` | **instants** | **CORRECT** |

**Fifteen sites wrong, all fixed; eleven groups correct, ten of them for a stated
reason and one because no predicate exists.**

The consequential ones are 1-7. Sites 8-15 are the same defect one step earlier in
the pipeline - a `DATE` or a document number *derived* from a UTC "today" rather
than compared against one - and were fixed for the same reason: leaving them would
be a partial fix by the brief's own measure. Row 14 is the subtle one: the
`TIMESTAMP` instant is read correctly in UTC, and it is the *day* pulled out of it
that was wrong.

### Two findings deliberately NOT fixed, reported instead

1. **`v_pendapatan_bulanan` groups by UTC month.** `telemedicine_test.sql:1191`
   runs `DATE_FORMAT(p.dibayar_at, '%Y-%m')` inside the database. Recorded in
   `docs/timezone-policy.md` line 260; fixing it means `CONVERT_TZ`, which is a
   schema change and the SQL file is byte-frozen.
2. **`BookingService` instant-booking slot arithmetic can wrap midnight.** Line
   ~372: `Carbon::parse($slotMulai)->addMinutes($durasi)->format('H:i:s')` on a
   wall-clock `TIME`. `23:50` + 20 minutes formats as `00:10:00` rather than
   crossing the day boundary. This is arithmetic on a `TIME`, not an
   instant-vs-wall-clock-vs-`now` error, and it is a different defect class from
   the one this task is about. `SlotAvailabilityService::detik()` normalises to
   seconds-since-midnight for exactly this reason and does not have the bug.
   Recorded here rather than silently expanded into.

---

## 4. The regression test, and its red-then-green transcript

New file: **`tests/Feature/Dokter/DokterStrZonaWaktuTest.php`**, 21 assertions,
one test. Helper prefix `str51b*` (`direktori*` and `jsl*` are taken).

The clock is pinned twice, **in the same test**:

| constant | UTC | Asia/Jakarta | the two bases |
| --- | --- | --- | --- |
| `STR51B_JAM_MALAM` = `2026-09-29T18:30:00Z` | 2026-09-29 18:30 | **2026-09-30 01:30** | **a day apart** |
| `STR51B_JAM_TENGAH` = `2026-09-30T06:30:00Z` | 2026-09-30 06:30 | 2026-09-30 13:30 | the same day |

Block A asserts the premise before using it (the two candidate days really are
different), then asserts three licences one calendar day apart: the one that
expired **yesterday WIB** is not listed and 404s; the ones expiring today and
tomorrow are listed and served. It then asserts the value the query **binds** -
`2026-09-30` present, `2026-09-29` absent - so the basis is pinned in the emitted
SQL and not only in the row set.

Block B pins the mid-day instant, asserts the two bases coincide there, and
re-asserts the same three-day fixture. A fix that merely shifted the basis fails
B while still passing A.

`Carbon::setTestNow()` is released in a `finally` around each block, and the last
assertion reads the clock back and requires `Carbon::getTestNow()` and
`CarbonImmutable::getTestNow()` to both be `null`. (Carbon 3 routes
`setTestNow()` through one shared `FactoryImmutable`, so both names see it.)

### RED - before the fix, at commit `7d44bdf`

The two named tests, each run in isolation on a private
`telemedisin_db_test_51b`, inside the seven-hour window:

```console
$ pest tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php \
    --filter="the four ineligible-doctor cases answer ONE 404 envelope on BOTH routes"
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":25,
 "failed":1,"failures":[{"file":".../DokterJadwalSlotEndpointTest.php","line":786,
 "message":"Expected response status code [404] but received 200.
 Failed asserting that 200 is identical to 404."}]}

$ pest tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php \
    --filter="even on a date the licence"
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,
 "failed":1,"failures":[{"file":".../DokterJadwalSlotEndpointTest.php","line":817,
 "message":"Expected response status code [404] but received 200.
 Failed asserting that 200 is identical to 404."}]}
```

The new test, added first and committed on its own at `3b007c6`:

```console
$ pest tests/Feature/Dokter/DokterStrZonaWaktuTest.php
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":4,"failed":1,
 "failures":[{"file":".../DokterStrZonaWaktuTest.php","line":195,
 "message":"Expecting [...] not to contain 1."}]}
```

`Expecting [...] not to contain 1` is doctor id 1 - the licence that expired
yesterday in Jakarta and is still in the directory. Assertion 4 is where the run
stopped, so the binding assertion was never reached.

### GREEN - after the fix

```console
$ pest tests/Feature/Dokter/DokterStrZonaWaktuTest.php
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":21}
```

Note the reporter **omits the `failed` key entirely** at zero, exactly as the
ledger warns. The parser used for every number in this file asserts key presence
before reading a count and reports `failed_key_absent: true` rather than
inventing a value.

### Non-vacuity: two mutations, each caught, then restored

| mutation | result |
| --- | --- |
| `today()` back to `SELECT CURDATE()` (the original defect) | **3 failed** of 25 - the new test at line 195 **and both** named tests at `:786` and `:817` |
| `WaktuIndonesia::now()->subDay()` (shift the basis by a day) | **1 failed** - the new test at line 195 |

So block A catches the original defect and block B catches a shifted basis; a
test that could be satisfied by either a UTC day or a day early does not exist
here. `DokterDirectoryService.php` was restored byte-identical afterwards
(`git diff --stat` empty).

---

## 5. Proof the two named tests now pass

```console
$ pest tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php \
    --filter="the four ineligible-doctor cases answer ONE 404 envelope on BOTH routes"
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":73}

$ pest tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php \
    --filter="even on a date the licence"
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":1}
```

Whole file:

```console
$ pest tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php
{"tool":"pest","result":"passed","tests":24,"passed":24,"assertions":276}
```

**Neither test was edited, skipped or weakened.** `git diff HEAD~2 --
tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php` is **empty**.

---

## 6. Test fixtures that had to move with the fix

A fixture built from `CURDATE()` or `Carbon::today()` is a test of a *different*
predicate than the one that runs, so four fixtures were moved onto
`WaktuIndonesia`. **No assertion was weakened, removed or relaxed**; each of these
tests went red on the fixed code precisely because its fixture was on the wrong
calendar, and green once both were on the same one.

| file | what moved | the failure it caused on the fixed code |
| --- | --- | --- |
| `DokterDirectoryTest::direktoriHariIni()` | `CURDATE()` -> `WaktuIndonesia::tanggal()` | 2: "Failed asserting that an array contains 8" (the inclusive-today doctor vanished) and "Failed asserting that an array contains '2026-09-29'" |
| `BookingTest::bkuHariIni()` | `CURDATE()` -> `WaktuIndonesia::tanggal()` in `Asia/Jakarta` | 1: 422 where 201 was expected - `setTime(12,0,0)` on a UTC value is 19:00 at the clinic, so the "13:00 is still ahead" control became a 422 |
| `ObatInteraksiServiceTest` line 690/696 | `Carbon::now()->toDateString()` -> `WaktuIndonesia::tanggal()` | the "today is still a clash" fixture was a day behind the guard |
| `ResepTodo40Test` boundary instant | `Carbon::parse('2026-03-18 23:59:59')` -> `..., WaktuIndonesia::ZONA)` | 1: "Failed asserting that true is identical to false" - see below |

The last one deserves naming. That test's comment says *"the boundary is
INCLUSIVE: valid through today means still valid today"*, and it pinned
`2026-03-18 23:59:59`. Read as UTC, that instant is `2026-03-19 06:59:59` **at the
pharmacy** - the day *after* the boundary - so the old pin could not express its
own comment and was only satisfiable because the production code was also on a UTC
day. Zone-qualified, it is the last minute of the expiry day, and the same
calendar day under the UTC reading too, so the assertion now cannot be satisfied
by picking a convenient clock. This is a **stronger** test than the one it
replaces.

---

## 7. Final numbers

```console
$ pest                      # private telemedisin_db_test_51b
{"tool":"pest","result":"passed","tests":1153,"passed":1153,"assertions":22510,
 "failed_key_absent":true,"errors_key_absent":true}
exit 0

# the JSON reporter does not report skips, so the JUnit log is the authority:
JUNIT -> tests=1153 failures=0 errors=0 skipped=0

$ pest tests/Contract
{"tool":"pest","result":"passed","tests":201,"passed":201,"assertions":2669}
```

**1153 tests, 1153 passed, 0 failed, 0 errors, 0 skipped.** 1152 at `7d44bdf` plus
the one new regression test. The contract suite is a separate run because
`phpunit.xml` declares only `Unit` and `Feature`, and it was left that way.

| check | result |
| --- | --- |
| `sehatly:verify-schema` | exit 0, "PASS - 75 tables, 2 views verified. Nothing was written." 0 drift, 7 informational (the documented Laravel/Sanctum tables) |
| `sehatly:enums --check` | exit 0, 69 ENUM columns, 319 values, 0 divergences |
| `sehatly:openapi --check` | exit 0 - `docs/openapi.yaml` **not** regenerated, not edited |
| `route:list --path=api/v1` | 74 |
| `telemedicine_test.sql` SHA-256 | `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` - **unchanged**; `git diff HEAD~2 -- telemedicine_test.sql` empty. No migration, index or constraint added. |
| `phpunit.xml` | untouched |
| `mobile/` | absent |
| `docs/`, `web/`, `packages/`, `README.md` | untouched |
| `pint` | the set of flagged files and the set of fixers per file is **identical** to the `HEAD~2` baseline across all 14 touched files; the five files that were clean are clean. `pint --test` still fails repo-wide on ~100 pre-existing files (`config/payment.php`, `app/Enums/*`, `tests/Contract/*`), which is pre-existing debt and was not taken on here. |

---

## 8. Byte-level scan

Every one of the 14 touched files, read as **raw bytes** (`ReadAllBytes`), not
through an encoding-aware reader:

| check | result |
| --- | --- |
| UTF-8 BOM (`EF BB BF`) at offset 0 | **0 files** |
| valid UTF-8 round trip (strict decoder, throws on error) | **14 / 14** |
| `CR` bytes (`.gitattributes` is `* text=auto eol=lf`) | **0 in all 14** |
| non-ASCII bytes **added by this change** (`git diff` added lines byte-scanned) | **0** |
| non-ASCII bytes present in the touched set | 12, all 4 x U+2014 em dash, all in `BookingService.php` prose docblocks at lines 48, 49, 317, 327, and all present at `HEAD~2` |

The new test file is **pure ASCII, 10500 bytes, 261 LF, no BOM, no CR** - the
`referencia`/`referensi` class of defect cannot hide in it. House style is em
dashes, and the four in `BookingService.php` are pre-existing; none was introduced.

PowerShell 5.1 has no `-Encoding utf8NoBOM`, so the two files edited by
byte-level script (`ResepService.php`, and the two mutation restorations) were
written with `New-Object System.Text.UTF8Encoding($false)` and re-verified above.

### Parser safety

`temp/opencode/parse-pest.js` reads the last line of the reporter's output, parses
it, and for `failed` and `errors` either reads the count or records
`<key>_key_absent: true` and asserts it means zero. It exits non-zero on any
failure and never substitutes a value it did not read. Used for every number in
this file.

---

## 9. Test database

`phpunit.xml` names `telemedisin_db_test` and was **not** edited. Every run in this
task used a per-run override:

```powershell
$env:DB_DATABASE="telemedisin_db_test_51b"
```

`telemedisin_db_test_51b` was created with `CREATE DATABASE IF NOT EXISTS ...
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` and populated by
`mysqldump --routines --triggers --events --single-transaction telemedisin_db_test |
mysql telemedisin_db_test_51b` (124815 bytes, 84 tables) so the `Unit` suite, which
does **not** use `RefreshDatabase`, finds the schema already migrated - a fresh
empty database gives 8 unrelated failures. `migrate:fresh`, `migrate:rollback`,
the `sehatly` database and `markTestSkipped` were not used at any point.

---

## 10. Commits

| commit | what |
| --- | --- |
| `3b007c6` | `test(dokter)`: the new regression test, committed **RED** on its own before any production change |
| `9fe92a3` | `fix(dokter)`: `WaktuIndonesia` on the STR boundary, the 14 sibling sites, the `CarbonInterface` widening, and the four fixture moves |
| `016064e` | `style`: the two pint-baseline regressions reverted, with the baseline comparison as the justification |

```
 app/Http/Controllers/Api/V1/AuthController.php     |  10 +-
 app/Services/Booking/BookingService.php            |  69 +++++++++---
 app/Services/Dokter/DokterDirectoryService.php     | 117 ++++++++++++++++-----
 app/Services/Obat/ObatInteraksiService.php         |  15 ++-
 app/Services/PesananObat/PesananObatService.php    |  11 +-
 app/Services/Resep/ResepService.php                |  16 +-
 app/Services/Resep/ResepStateMachine.php           |  14 +-
 .../SuratKeterangan/SuratKeteranganService.php     |  30 ++++--
 app/Support/Dokter/StrBerlaku.php                  |  44 ++++++--
 tests/Feature/Booking/BookingTest.php              |  36 ++++---
 tests/Feature/Dokter/DokterDirectoryTest.php       |  34 +++--
 tests/Feature/Obat/ObatInteraksiServiceTest.php    |  11 +-
 tests/Feature/Resep/ResepTodo40Test.php            |  17 ++-
 13 files changed, 337 insertions(+), 87 deletions(-)
```

(`tests/Feature/Dokter/DokterStrZonaWaktuTest.php`, 259 lines, is the fourteenth
file, added by `3b007c6`.)

## 11. Unfinished

Nothing in scope. Two items are recorded in section 3 rather than fixed, and both
are outside this task's authority rather than outside its attention: the
`v_pendapatan_bulanan` UTC-month grouping needs `CONVERT_TZ` in a byte-frozen DDL,
and the `BookingService` midnight-wrap in instant-booking slot arithmetic is a
different defect class that belongs to whoever owns that arithmetic.
`docs/timezone-policy.md` was **not** updated with this task's audit table; the
policy document is not on this task's writable list, so the table lives here.
