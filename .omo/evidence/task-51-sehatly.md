# Task 51 evidence — Enforce the timezone and serialisation policy end to end

**Executor:** Sisyphius-Junior · **Date:** 2026-09-29 · **Base commit:** `2d5cb28`
**Result:** `docs/timezone-policy.md` written, UTC storage and ISO-8601 output made true
end to end. Three real defects found and fixed; two pre-existing tests corrected.

---

## 1. The runtime probe (verbatim, re-run as instructed)

The previous executor was killed before capturing this, so it is the first thing
rebuilt. Command:

```
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe artisan tinker --execute="..."
```

Output, unedited:

```text
SESSION_TZ=SYSTEM
GLOBAL_TZ=SYSTEM
NOW=2026-09-29 20:41:26
UTC_TIMESTAMP=2026-09-29 13:41:26
APP_TZ=UTC
DB_TZ=NULL
NOW_CLASS=Carbon\CarbonImmutable
PHP_TZ=UTC
DB_DRIVER=mysql
DB_NAME=telemedisin_db
```

Three facts follow, none of them stylistic:

1. `NOW()` is **7 hours ahead** of `UTC_TIMESTAMP()`. The MySQL server's system zone
   is `Asia/Jakarta`, so every `DEFAULT CURRENT_TIMESTAMP` on all 55 `TIMESTAMP`
   columns evaluated in WIB.
2. `@@session.time_zone` was `SYSTEM`, and `config('database.connections.mysql.timezone)`
   was **`NULL`**. Laravel only issues `SET time_zone='...'` when that config key is
   present — `vendor/laravel/framework/src/Illuminate/Database/Connectors/MySqlConnector.php:110-111`
   — so nothing pinned it.
3. `get_class(now())` is `Carbon\CarbonImmutable`, a **sibling** of
   `Illuminate\Support\Carbon`, not a subclass. This bit me for real during
   implementation (see §6).

---

## 2. The DDL inventory, read with `SqlSchemaParser`

Not grepped. `App\Support\Schema\SqlSchemaParser::parseFile('telemedicine_test.sql')`,
then every column whose base type is temporal, with the raw DDL line re-read for
`COMMENT`:

| DDL type | count |
| --- | --- |
| `TIMESTAMP` | 55 |
| `DATETIME` | 26 |
| `DATE` | 19 |
| `TIME` | 4 |
| `YEAR` | 1 |
| **total** | **105** |

The only column-level `COMMENT` on any of the 105 is `resep.berlaku_sampai`
(`COMMENT 'E-resep berlaku 7 hari'`, `:755`), and it confirms the wall-clock reading:
seven days is a count of **calendar days on paper**, not 604800000 ms.

### Per-column classification

All 55 `TIMESTAMP` are **instants** without exception — every one is a
`DEFAULT CURRENT_TIMESTAMP` (most also `ON UPDATE CURRENT_TIMESTAMP`) audit column,
and MySQL's `TIMESTAMP` storage is UTC-native.

All 19 `DATE`, all 4 `TIME` and the 1 `YEAR` are **wall clocks** — no exceptions to
argue about.

The 26 `DATETIME` are the only ones needing judgement:

| column | line | treatment | why |
| --- | --- | --- | --- |
| `artikel.published_at` | 1086 | **instant** | the moment an article went live; a reader in Bali and one in London must see the same point |
| `home_care_pesanan.jadwal_kunjungan` | 1101 | **wall clock** | a nurse visiting a patient's house; the address pins the zone and there is no offset to store |
| `invoice.jatuh_tempo` | 949 | **instant** | a machine-issued deadline is an interval, not a date on a calendar |
| `invoice.lunas_at` | 950 | **instant** | settlement moment |
| `konsultasi.mulai_at` | 545 | **instant** | when the consultation started |
| `konsultasi.selesai_at` | 546 | **instant** | likewise; duration = the difference in real time |
| `konsultasi_chat.dibaca_at` | 574 | **instant** | read receipts compared across devices |
| `lab_hasil.tanggal_hasil` | 915 | **instant** | when the analyser produced the result |
| `master_promo.mulai_at` | 995 | **wall clock** | an operator types the window in Indonesian local time; treating it as UTC shifts activation and expiry 7 h |
| `master_promo.selesai_at` | 996 | **wall clock** | the other end of the same window |
| `notifikasi.dibaca_at` | 1044 | **instant** | per-device read state |
| `pasien_tanda_vital.diukur_at` | 326 | **instant** | a reading taken at a moment |
| `pembayaran.dibayar_at` | 967 | **instant** | settlement; feeds `v_pendapatan_bulanan` |
| `persetujuan_pdp.disetujui_at` | 1141 | **instant** | auditable consent act |
| `pesanan_obat_tracking.waktu` | 825 | **instant** | a courier scan event |
| `rekam_medis.tanggal_periksa` | 630 | **instant** | the examination happened at a moment, despite the word "date" |
| `rekam_medis.ditandatangani_at` | 647 | **instant** | a signature applied at a moment |
| `rekam_medis_persetujuan.ditandatangani_at` | 700 | **instant** | likewise |
| `rekam_medis_tindakan.tanggal_tindakan` | 675 | **instant** | the procedure was performed at a moment |
| `resep.tanggal_resep` | 754 | **wall clock** | an e-prescription is dispensed against a **local calendar date**; the 7-day `berlaku_sampai` counts paper days |
| `resep_verifikasi.diverifikasi_at` | 792 | **instant** | a pharmacist's action |
| `ulasan_dokter.dibalas_at` | 1061 | **instant** | when the reply was posted |
| `user_devices.last_active_at` | 198 | **instant** | recency across devices |
| `user_otp.kedaluwarsa_at` | 184 | **instant** | machine expiry; seven hours late is seven hours of extra validity |
| `user_refresh_tokens.kedaluwarsa_at` | 208 | **instant** | same, and a revocation boundary |
| `users.last_login_at` | 145 | **instant** | same |

**23 instants, 3 wall clocks.** The three are the reason the enforcement is
allow-list based and not suffix based: all three end in a way that reads like an
instant, and `master_promo.mulai_at` is the exact counter-example the plan names.

Full lists for all 105 columns are in `docs/timezone-policy.md`.

---

## 3. The explicit TIME-vs-instant rule

Stated in `docs/timezone-policy.md` under its own heading:

> **A MySQL `TIME` column is a wall clock, never an instant. `dokter_jadwal.jam_mulai`
> `17:00:00` means "17:00 at the clinic". It is not a moment. Append a `Z` to it, or
> treat it as `17:00 UTC`, and you have changed the clinic's opening hour by seven
> hours.**

The four `TIME` columns — `booking.slot_mulai`, `booking.slot_selesai`,
`dokter_jadwal.jam_mulai`, `dokter_jadwal.jam_selesai` — are wall clocks. The DDL is
**not** changed to "fix" it: a `TIME` is correct for a recurring weekly clinic window,
and widening it to `DATETIME` would force a fictitious date onto `dokter_jadwal`.
`WaktuIndonesia::toInstant()` is the only route from a `DATE` + `TIME` pair to an
instant, and it is the only place `Asia/Jakarta` is named for that purpose.

---

## 4. Runtime evidence, raw JSON, before and after

`php artisan serve` on **port 8751 / 8753** (not 8000), driven through the real auth
flow: `POST /auth/register` → `POST /auth/otp/verify` (`tujuan=verifikasi_telepon`) →
`POST /auth/login` → `POST /auth/otp/verify` (`tujuan=login`) → bearer token →
`GET /me`, `GET /pasien/profil`, `GET /dokter`, `GET /dokter/{id}/jadwal`,
`GET /dokter/{id}/slot`, `POST /booking`.

### The one response that carries all three column classes

`POST /api/v1/booking` → **HTTP 201**

```json
{"success":true,"data":{"booking":{"id":302,"nomor_booking":"BK20261214MKBPIA","pasien_id":9,
"anggota_keluarga_id":null,"dokter_id":2,"jadwal_id":null,"faskes_id":null,
"tipe_layanan":"video_call","tanggal_kunjungan":"2026-12-14","slot_mulai":"23:00:00",
"slot_selesai":"23:15:00","nomor_antrian":null,"keluhan":"Bukti zona waktu",
"lampiran_keluhan":null,"is_rujukan":false,"is_konsultasi_lanjutan":false,
"status":"menunggu_pembayaran","dibatalkan_oleh":null,"alasan_pembatalan":null,
"dibuat_oleh_user_id":12,"dibuat_at":"2026-09-29T14:40:48.000000Z"}},
"message":"Booking berhasil dibuat."}
```

Read column by column:

| field | DDL | class | emitted |
| --- | --- | --- | --- |
| `tanggal_kunjungan` | `DATE` (`:507`) | wall clock | `"2026-12-14"` — no time, no offset |
| `slot_mulai` | `TIME` (`:508`) | **wall clock** | `"23:00:00"` — no date, **no offset** |
| `slot_selesai` | `TIME` (`:509`) | **wall clock** | `"23:15:00"` |
| `dibuat_at` | `TIMESTAMP` (`:520`) | **instant** | `"2026-09-29T14:40:48.000000Z"` — `Z` suffix |

### The slot endpoint, `TIME` columns unshifted

`GET /api/v1/dokter/2/slot?tanggal=2026-12-14` → **HTTP 200**

```json
{"success":true,"data":{"tanggal":"2026-12-14","timezone":"Asia\/Jakarta",
"slots":[{"jadwal_id":126,"jam_mulai":"23:00:00","jam_selesai":"23:15:00","tipe_layanan":"online","faskes_id":null,"tersedia":true,"alasan":null},
{"jadwal_id":126,"jam_mulai":"23:15:00","jam_selesai":"23:30:00","tipe_layanan":"online","faskes_id":null,"tersedia":true,"alasan":null},
{"jadwal_id":126,"jam_mulai":"23:30:00","jam_selesai":"23:45:00","tipe_layanan":"online","faskes_id":null,"tersedia":true,"alasan":null}]},
"message":"Ketersediaan jam berhasil dimuat.","meta":{...}}
```

`23:30:00` is present and is **not** `16:30:00`. `23:45` is correctly absent — it
would end at `24:00:00`, which is not a time of day.

### The `DATE` column, no off-by-one-day

`GET /api/v1/pasien/profil` → **HTTP 200**, patient born on the 1st of a month:

```json
"tanggal_lahir":"1990-01-01"
```

### BEFORE / AFTER on the stored instant — the real defect

Reading the raw column back through a connection **explicitly** pinned to `+00:00`,
i.e. asking what bytes MySQL actually stored:

**BEFORE — `config` pin absent, `@@session.time_zone = SYSTEM`:**

```text
user_id                = 10
app session tz         = SYSTEM
users.dibuat_at RAW    = 2026-09-29 14:33:18
re-read under +00:00   = 2026-09-29 07:33:18
UTC_TIMESTAMP() now    = 2026-09-29 14:37:23
offset from now        = 25445 seconds (SEVEN HOURS - DEFECT)
```

**AFTER — `config` pin present, `@@session.time_zone = +00:00`:**

```text
user_id                = 12
app session tz         = +00:00
users.dibuat_at RAW    = 2026-09-29 14:40:44
re-read under +00:00   = 2026-09-29 14:40:44
UTC_TIMESTAMP() now    = 2026-09-29 14:40:56
offset from now        = 12 seconds (ok)
```

This is the finding that matters most, and it is **not** visible in the published
JSON. `users.dibuat_at` is `TIMESTAMP DEFAULT CURRENT_TIMESTAMP`, but Eloquent owns
that column (`CREATED_AT = 'dibuat_at'`), so it writes an **explicit literal** taken
from `Date::now()` — a UTC wall clock. With the session on WIB, MySQL reads that
literal *as a Jakarta wall clock* and stores it seven hours early. Reading it back
with the **same** session returns the identical string, so the round trip looks
lossless and the published `...Z` value looks right. The corruption only surfaces
when anything reads the stored bytes under a different session — a report, an export,
another tool, or `v_pendapatan_bulanan`.

Before / after in the API itself, same field:

```text
BEFORE  "dibuat_at":"2026-09-29T14:33:18.000000Z"   (stored as 07:33:18 UTC - wrong)
AFTER   "dibuat_at":"2026-09-29T14:40:48.000000Z"   (stored as 14:40:48 UTC - right)
```

The published string is nearly identical in both, which is precisely why the runtime
check had to read the storage rather than trust the response.

---

## 5. Failing test first, then green

`tests/Feature/TimezonePolicyTest.php`, 18 tests. Per-executor database
`telemedisin_db_t51` via a per-run `$env:DB_DATABASE` override; `phpunit.xml` untouched.

### RED (first run, before any fix)

```json
{"tool":"pest","result":"failed","tests":16,"passed":6,"assertions":75,"duration_ms":14932,"failed":4,
 "failures":[
  {"test":"...runs_the_application_in_UTC_and_pins_the_MySQL_session_to_+00:00","line":307,
   "message":"Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n-'+00:00'\n+'SYSTEM'"},
  {"test":"...exposes_the_pinned_zone_through_config_so_the_connector_sets_it","line":314,
   "message":"Failed asserting that null is identical to '+00:00'."},
  {"test":"...reads_a_freshly_written_timestamp_back_as_the_same_instant_MySQL_holds","line":386,
   "message":"Failed asserting that 25200.0 is less than 60."},
  {"test":"...returns_a_23_30_WIB_slot_as_23_30_00_and_never_shifts_it_to_16_30","line":502,
   "message":"Failed asserting that two arrays are identical..."}],
 "errors":6,
 "error_details":[{"test":"...serialises_master_promo.mulai_at_with_NO_offset...","message":"Class \"App\\Support\\WaktuIndonesia\" not found"}, ...]}
```

`25200.0` is exactly **7 hours in seconds**, measured, not inferred.

### GREEN

```json
{"tool":"pest","result":"passed","tests":18,"passed":18,"assertions":127,"duration_ms":24827}
```

### Mutation harness — the suite is not vacuous

The Pest JSON reporter **omits** `failed` and `errors` when their count is zero, so
the parser asserts key presence before reading any count.

| mutation | result |
| --- | --- |
| remove the `'timezone' => env('DB_TIMEZONE', '+00:00')` pin | `failed, 18 tests, 15 passed, 3 failed` |
| restore | `passed, 18/18` |
| revert slot fix: `WaktuIndonesia::now()` → `Carbon::now()` (UTC) | `failed, 17 tests, 16 passed, 1 failed` — the 09:30 WIB slot reports `tersedia: true` |
| revert `hariIni()` to `SELECT CURDATE()` | `failed, 17 tests, 15 passed, 2 failed` |
| restore | `passed, 17/17` |

---

## 6. The `CarbonImmutable` trap, hit for real

Not a warning I could leave abstract. `SlotAvailabilityService` declared
`private ?Carbon $hariIni`, and assigning `WaktuIndonesia::now()` produced:

```text
Cannot assign Carbon\CarbonImmutable to property
App\Services\Booking\SlotAvailabilityService::$hariIni of type ?Illuminate\Support\Carbon
```

Fixed by widening to `CarbonInterface` at the four signatures that touch these values
(`$hariIni`, `getSlotTerbuka(..., ?CarbonInterface $acuan)`, `hariIni()`,
`keputusan(..., ?CarbonInterface $sudahLewat, ...)`). Widening a parameter type is
backwards compatible.

`StrBerlaku::berlakuPada()` has the same narrow hint one layer out. I **reverted** my
first, over-broad attempt to widen the whole path rather than force a second
unrelated type change into a file another todo owns; `tanggalValid()` keeps
`Illuminate\Support\Carbon` and the immutable values live only where the policy needs
them.

---

## 7. Two pre-existing tests corrected

Both broke **because my fix was right**, not because it was wrong.

- `SlotAvailabilityTest.php` / `DokterJadwalSlotEndpointTest.php` built "today" from
  `SELECT CURDATE()` — a **server-local** wall clock, correct here only because the host
  is set to WIB. Pinning the connection silently turned "today" into the UTC day, so
  between 00:00 and 07:00 WIB the booking book would have opened on the wrong date.
  Both helpers now ask `WaktuIndonesia::tanggal()`.
- Both froze the clock with `$hariIni->copy()->setTime(10, 7, 0)` on a **UTC-labelled**
  Carbon, i.e. 10:07 UTC = 17:07 WIB, while the windows under test are written in WIB.
  They now freeze `WaktuIndonesia::toInstant($hari, '10:07:00')`.

---

## 8. Byte-level scan

Raw-byte reads, no transcoding. PowerShell 5.1 has no `-Encoding utf8NoBOM`, so every
write in this task used `New-Object Text.UTF8Encoding $false`.

```text
app/Support/WaktuIndonesia.php            bytes=8000    BOM=absent   unexpected-non-ASCII=0
app/Services/Booking/SlotAvailabilityService.php  bytes=34951  BOM=absent   unexpected-non-ASCII=0
config/database.php                       bytes=7704    BOM=absent   unexpected-non-ASCII=0
tests/Feature/TimezonePolicyTest.php      bytes=32130   BOM=absent   unexpected-non-ASCII=0
docs/timezone-policy.md                   bytes=15532   BOM=absent   unexpected-non-ASCII=0
```

The scanner strips house-style punctuation (em dash, en dash, bullet) and reports
anything else. Em-dash usage matches the existing docs (`schema-notes.md` has 225,
`migration-order.md` 68). Typo-token list (`referencia`, `referensia`, `konsultasia`,
`pasienna`, `WIB_`) — no hits.

**This scan caught a real defect in my own output**: the first draft of the test file
contained `$m的真实` — non-ASCII bytes in an identifier. Found at line 379 and removed
before the first run.

Token audit against the DDL: every `table.column` in both allow-lists is resolved
through `SqlSchemaParser` at test time, so a typo fails the suite instead of silently
shrinking coverage. Enum values written by the row builders are read from the DDL via
`zonawaktuEnum()` and the first declared value used, so an ENUM token cannot be
mistyped.

---

## 9. Full-suite result and what is NOT mine

`php artisan test` against `telemedisin_db_t51`, parsed with the key-presence-safe
parser:

```text
--- key presence ---
  OK    tests / passed / assertions / result
  OK    failed = 24
  OK    errors = <omitted by reporter, provably zero>
  tests = 1151  passed = 1127
VERDICT: RED
```

An earlier run, before the concurrent executor's work landed, was `1117 tests, 2
failed` — and both failures were **mine** (the two slot tests in §7), now fixed.

**All 24 current failures belong to the concurrent executor's in-flight todo 52**
(rate limiting / security headers), and none is in the timezone path:

- 12× rate-limit tests expecting 429, receiving 200 or 500
- 2× security-header tests (`SecurityHeadersTest`)
- 1× `TokenAuditPdpTest` reporting a **UTF-8 BOM** in `app/Providers/AppServiceProvider.php`

The timezone-path tests are green:

```text
php artisan test --filter=TimezonePolicy                                 -> 18 passed, 127 assertions
php artisan test --filter="SlotAvailabilityTest|DokterJadwalSlotEndpointTest|TimezonePolicy"
                                                                        -> 78 passed, 687 assertions
```

### Findings for the concurrent executor — reported, not edited

1. **`app/Providers/AppServiceProvider.php` currently has a syntax error at line 420** —
   `unexpected variable "$this", expecting ")"`. `php -l` fails, so `php artisan` does
   not run at all in the main working tree. All verification above had to run from a
   detached `git worktree` at my HEAD, where the committed file is valid.
2. **That same file starts with a UTF-8 BOM** (`EF BB BF` at offset 0), which their own
   `TokenAuditPdpTest` already flags. Rewritten 2026-09-29 with a UTF-8 encoder that
   does not emit one.

I did not modify `app/Providers/AppServiceProvider.php`, `app/Services/Auth/**`,
`routes/api.php`, or any controller.

---

## 10. What the policy records and does not fix

Recorded in `docs/timezone-policy.md` as escaping the policy, both because they live
in the byte-frozen `telemedicine_test.sql` (`SHA-256 AEFE2247E00F...`):

1. **`v_pendapatan_bulanan` groups by server-local wall clock.** `:1191` runs
   `DATE_FORMAT(p.dibayar_at, '%Y-%m')` on a `DATETIME` we store in UTC. Inside the
   database, so no PHP policy reaches it. Its months are UTC months; fixing it needs
   `CONVERT_TZ(..., '+00:00', '+07:00')`, which is a schema change and out of scope.
2. **`faskes.jam_operasional` (`:377`)** is unzoned JSON — a per-day `{buka, tutup}` map
   with no date and no column to hang a zone on. Same convention as
   `dokter_jadwal.jam_mulai`; the PHP layer cannot validate a zone that has nowhere to
   live.

No data repair was needed: MySQL stored every `TIMESTAMP` in UTC-native storage, so
pinning the session corrects the **read**, and the rows written before the fix will read
correctly once it is in place. The three wall-clock `DATETIME` columns are stored the
way they are meant to be and are untouched by either.

---

## 11. Files changed

| file | change |
| --- | --- |
| `docs/timezone-policy.md` | **new** — the policy, the TIME rule, the 105-column inventory |
| `app/Support/WaktuIndonesia.php` | **new** — `now()`, `tanggal()`, `toInstant()`, `kePasang()` |
| `config/database.php` | pin `timezone` on the `mysql` connection |
| `app/Services/Booking/SlotAvailabilityService.php` | elapsed-slot rule and "today" both read in `Asia/Jakarta`; `CarbonInterface` widening |
| `tests/Feature/TimezonePolicyTest.php` | **new** — 18 tests |
| `tests/Feature/Dokter/SlotAvailabilityTest.php` | test clock corrected to the Jakarta wall clock |
| `tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php` | same |

## 12. Not finished / explicitly out of scope

- `v_pendapatan_bulanan` still groups UTC months. Needs a schema change; recorded, not made.
- `faskes.jam_operasional` has no place to store a zone.
- The suite cannot be driven fully green from the main tree while
  `app/Providers/AppServiceProvider.php` does not parse. That file is not mine.