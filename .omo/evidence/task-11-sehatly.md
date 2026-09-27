# Task 11 evidence — migration batch E (schedule, holiday and booking)

Todo 11 of `.omo/plans/sehatly-telemedicine-platform.md`, branch
`feat/sehatly-telemedicine`. Every command below was run with the project-local
PHP 8.4.17 first on `PATH`:

```powershell
$env:PATH = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64;$env:PATH"
```

`php -v` → `PHP 8.4.17 (cli) (NTS Visual C++ 2022 x64)`. Bare `php` on `PATH` is
8.2.29 and fails Laravel's `^8.3`, so the prefix is on every invocation.

---

## 0. Read-only law: `telemedicine_test.sql` is byte-unchanged

| when | SHA-256 | verdict |
|---|---|---|
| before any edit | `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` | matches the declared value |
| after all work | `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` | **unchanged** |

```powershell
Get-FileHash -Algorithm SHA256 -LiteralPath "telemedicine_test.sql"
# Hash : AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
```

The file was opened read-only throughout. No reformat, no reorder, no edit.

---

## 1. Brief errors found (RULE 0: every literal re-checked against the SQL)

The brief instructed that every quoted literal is a claim to be checked, not a
fact to copy. Four discrepancies were found. **In all four the SQL won**, and the
plan is the source of the wrong value in three of them.

| # | what the brief said | what `telemedicine_test.sql` actually says | SQL line | resolution |
|---|---|---|---|---|
| 1 | `INDEX idx_jadwal (dokter_id, hari, status_aktif)` **cited at `:479`** | `:479` is `kuota_per_sesi SMALLINT UNSIGNED NULL`. The index is at **`:487`** | 487 | migration cites `:487`. The plan's own authoritative line-number index (line 79) also says **487**, so the plan's *inline* citation in todo 11's prose is the error. |
| 2 | booking index lines **`:527-528`** | `:527` is `FOREIGN KEY (dibuat_oleh_user_id) REFERENCES users(id)`. `idx_booking_dokter` is at **`:528`**, `idx_booking_pasien` at **`:529`** | 528, 529 | migration cites `:528`-`:529`. Same class as #1: the plan's authoritative index (lines 91-92) says 528/529 and supersedes its own prose. |
| 3 | `durasi_slot_menit SMALLINT UNSIGNED DEFAULT 15` | `durasi_slot_menit SMALLINT UNSIGNED **NOT NULL** DEFAULT 15` | 478 | brief omitted `NOT NULL`. Laravel's `enum`/column builders are not-null by default, so the emitted DDL is correct either way, but the docblock states the full declaration. |
| 4 | `tipe_layanan ENUM('online','klinik','home_visit') DEFAULT 'online'` | `tipe_layanan ENUM('online','klinik','home_visit') **NOT NULL** DEFAULT 'online'` | 474 | same omission as #3; harmless, documented in full. |

Two further brief items were **confirmed correct** and are therefore *not*
errors, recorded so the next executor does not re-litigate them:

- `hari`'s comment string is exactly `0=Minggu s.d. 6=Sabtu` (`:475`) — character
  for character, including the `s.d.`.
- `booking.status` really has **eight** values (`:515`-`:516`), not seven. Counted
  from the DDL and independently from live `COLUMN_TYPE`; both give 8.

Per A.15, **the plan text was not edited** — `.omo/plans/` is orchestrator-owned
and under concurrent edit. These are reported, not patched.

---

## 2. Contract rows honoured

`docs/migration-order.md` rows 35-37, read before authoring and not renumbered:

| # | SQL line | Table | Migration filename | Batch |
|---|---|---|---|---|
| 35 | 470 | `dokter_jadwal` | `2026_10_01_000035_dokter_jadwal_table.php` | E (11) |
| 36 | 490 | `dokter_libur` | `2026_10_01_000036_dokter_libur_table.php` | E (11) |
| 37 | 498 | `booking` | `2026_10_01_000037_booking_table.php` | E (11) |

The plan's stated batch range `:470-534` and closing lines were verified:
`:488`, `:496` and `:530` are each the `) ENGINE=InnoDB;` of the three tables, so
the docblock headers `:470-488`, `:490-496` and `:498-530` are exact.

---

## 3. Acceptance criteria

### 3.1 `migrate:fresh` on BOTH databases — exit 0

The previous executor ran this on the dev database only, which turned the Unit
suite red. Both were run.

```powershell
php artisan config:clear                                   # exit 0
php artisan migrate:fresh --no-interaction                 # exit 0  (telemedisin_db, from .env)
```

```powershell
$env:DB_DATABASE='telemedisin_db_test'                     # fresh shell; real env var beats .env
php artisan migrate:fresh --no-interaction                 # exit 0
php artisan config:clear                                   # exit 0
```

Both runs listed all three batch-E migrations as `DONE`:

```
 2026_10_01_000035_dokter_jadwal_table .. 132.45ms DONE
 2026_10_01_000036_dokter_libur_table .. 47.97ms DONE
 2026_10_01_000037_booking_table .. 361.98ms DONE
```

`.env` → `DB_DATABASE=telemedisin_db`; `phpunit.xml` →
`DB_DATABASE=telemedisin_db_test`. Neither file was edited.

### 3.2 `verify-schema` — exit 0, `Discrepancies: 0`, scope confirms 3 real names

Both A.7/A.8 traps were checked rather than assumed:

- **The PASS banner is meaningless in `--tables` mode.** It is formatted from the
  full reference model and prints "75 tables, 2 views verified" after verifying
  three. Judged instead on the exit code and the `Discrepancies:` line.
- **A typo'd table name exits 0** with `unknown_requested_table`. Guarded by
  reading the echoed `scope` line: it lists `dokter_jadwal, dokter_libur, booking`
  — three real names, all of which exist in both the DDL and the live schema.

```
 scope dokter_jadwal, dokter_libur, booking
 Discrepancies: 0 (0 drift, 0 informational)
 PASS — 75 tables, 2 views verified. Nothing was written.
EXITCODE=0
```

`notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred
constraint)` — unchanged from before this batch.

The parser independently confirms the one multi-line declaration in this batch is
read as a single unit: `wrapped decls 11 — … booking.status (515-516) …`.

### 3.3 Both forbidden unique indexes are absent

Enumerated every `NON_UNIQUE = 0` index on both tables from
`information_schema.STATISTICS` and compared the **ordered column list**:

```
booking unique 'booking_nomor_booking_unique' => (nomor_booking)
booking unique 'PRIMARY' => (id)
  OK   booking has NO unique index on (dokter_id, tanggal_kunjungan, slot_mulai)
dokter_libur unique 'PRIMARY' => (id)
  OK   dokter_libur has NO unique index on (dokter_id, tanggal)
```

`booking`'s complete index set, with uniqueness and column order:

```
  booking_anggota_keluarga_id_foreign non-unique (anggota_keluarga_id)
  booking_dibuat_oleh_user_id_foreign non-unique (dibuat_oleh_user_id)
  booking_faskes_id_foreign          non-unique (faskes_id)
  booking_jadwal_id_foreign          non-unique (jadwal_id)
  booking_nomor_booking_unique       UNIQUE   (nomor_booking)
  idx_booking_dokter                 non-unique (dokter_id, tanggal_kunjungan)
  idx_booking_pasien                 non-unique (pasien_id, status)
  PRIMARY                            UNIQUE   (id)
```

`dokter_jadwal`: `dokter_jadwal_faskes_id_foreign (faskes_id)`,
`idx_jadwal (dokter_id, hari, status_aktif)`, `PRIMARY (id)`.
`dokter_libur`: `dokter_libur_dokter_id_foreign (dokter_id)`, `PRIMARY (id)`.

**How the `dokter_libur` absence was confirmed.** The whole statement was read
line by line — `:490` to `:496` inclusive, seven lines — and tokenised:

```
  CREATE TABLE dokter_libur (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    dokter_id BIGINT UNSIGNED NOT NULL,
    tanggal DATE NOT NULL,
    alasan VARCHAR(200) NULL,
    FOREIGN KEY (dokter_id) REFERENCES dokter(id) ON DELETE CASCADE
  ) ENGINE=InnoDB;
  UNIQUE occurrences: 0
  INDEX occurrences  : 0
  KEY occurrences    : 2      <- both are PRIMARY KEY / FOREIGN KEY, not index declarations
```

So the absence is established twice over: no `UNIQUE` token in the DDL, and only
one live index beyond the primary key, which is the one **MySQL creates itself**
for the FK. The live check agrees (`information_schema`, not just
`SHOW CREATE TABLE`).

### 3.4 The `lockForUpdate` comment — quoted verbatim

`database/migrations/2026_10_01_000037_booking_table.php`, class docblock:

```
 * So the strategy todo 27 must implement, and the one this migration is written to
 * hand forward, is a **`DB::transaction` closure that takes a pessimistic row
 * lock on the `dokter` row with `lockForUpdate()`** before the overlap query runs:
 *
 *     DB::transaction(function () use ($booking) {
 *         DB::table('dokter')->where('id', $booking->dokter_id)->lockForUpdate()->first();
 *         // ... then re-read this doctor's bookings for $tanggal_kunjungan and
 *         // reject any overlap that is not in ('dibatalkan', 'kadaluarsa').
 *     });
```

and, at the index declarations in `up()`:

```
            // Both named in the DDL, so compared by name. Do not reorder, and do
            // NOT add a unique on (dokter_id, tanggal_kunjungan, slot_mulai) -
            // see the class docblock for the lockForUpdate strategy instead.
            $table->index(['dokter_id', 'tanggal_kunjungan'], 'idx_booking_dokter');
            $table->index(['pasien_id', 'status'], 'idx_booking_pasien');
```

The docblock also records *why* a unique index is the wrong tool, as required:
(1) cancellation is a `status` change and the row stays, so an unconditional
unique would make a legitimate re-booking collide with the patient's own dead
row; (2) the uniqueness wanted is state-dependent — it applies only outside the
exclusion set `('dibatalkan','kadaluarsa')` — and MySQL has no partial index. The
lock target is `dokter` and **not** `dokter_jadwal` because `jadwal_id` is
nullable (`:504`) while `slot_mulai`/`slot_selesai` are `NOT NULL` (`:508`-`:509`),
so an instant `chat`/`video_call` booking has no schedule row to lock.

### 3.5 Unit suite — exit 0, 93 tests, 473 assertions, test file **unedited**

```powershell
git status --porcelain -- tests/          # (empty — unedited)
php artisan test tests/Unit
```

```
 [derive] migrations=40 Schema::create calls=43 extracted=43 | CREATE VIEW calls=0 extracted=0 | contract tables=75 views=2 | derived-missing tables=38 views=2 | registry=7
{"tool":"pest","result":"passed","tests":93,"passed":93,"assertions":473,"duration_ms":7203}
EXITCODE=0
```

`tests/Unit/Console/VerifySchemaCommandTest.php` was **not** edited. The derived
missing count moved **41 → 38**, exactly the value A.9's projection table
predicts for todo 11, and the test re-derives it from the live migration set
rather than pinning a literal — which is why it stayed green with no edit.

### 3.6 Zero-match control — exit 1

```powershell
php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9
{"tool":"pest","result":"failed","tests":0,"passed":0,"assertions":0,"duration_ms":3,"raw":["No tests found."]}
EXITCODE=1
```

---

## 4. `information_schema` parity dump — all three tables, both databases

Re-derived independently of the project's own parser and differ, via raw PDO
(`SELECT` / `SHOW` only). Full output: 640 lines, **0 assertion failures** on both
`telemedisin_db` and `telemedisin_db_test`.

### 4.1 Columns — 40 compared, 0 mismatches

`dokter_jadwal` — 14 columns:

```
#   COLUMN                     COLUMN_TYPE                        NULLABLE  DEFAULT        EXTRA                        COMMENT
1   id                         bigint unsigned                    NO        <NULL>         auto_increment               -
2   dokter_id                  bigint unsigned                    NO        <NULL>         -                            -
3   faskes_id                  bigint unsigned                    YES       <NULL>         -                            NULL = layanan online murni
4   tipe_layanan               enum('online','klinik','home_visit') NO        'online'       -                            -
5   hari                       tinyint unsigned                   NO        <NULL>         -                            0=Minggu s.d. 6=Sabtu
6   jam_mulai                  time                               NO        <NULL>         -                            -
7   jam_selesai                time                               NO        <NULL>         -                            -
8   durasi_slot_menit          smallint unsigned                  NO        '15'           -                            -
9   kuota_per_sesi             smallint unsigned                  YES       <NULL>         -                            -
10  berlaku_mulai              date                               NO        <NULL>         -                            -
11  berlaku_sampai             date                               YES       <NULL>         -                            -
12  status_aktif               tinyint(1)                         NO        '1'            -                            -
13  dibuat_at                  timestamp                          NO        'CURRENT_TIMESTAMP' DEFAULT_GENERATED            -
14  diubah_at                  timestamp                          NO        'CURRENT_TIMESTAMP' DEFAULT_GENERATED on update CURRENT_TIMESTAMP -
```

`dokter_libur` — 4 columns: `id bigint unsigned` (auto_increment),
`dokter_id bigint unsigned` NO, `tanggal date` NO, `alasan varchar(200)` YES.

`booking` — **22** columns:

```
#   COLUMN                     COLUMN_TYPE                        NULLABLE  DEFAULT        EXTRA                        COMMENT
1   id                         bigint unsigned                    NO        <NULL>         auto_increment               -
2   nomor_booking              varchar(30)                        NO        <NULL>         -                            -
3   pasien_id                  bigint unsigned                    NO        <NULL>         -                            -
4   anggota_keluarga_id        bigint unsigned                    YES       <NULL>         -                            NULL = untuk pasien sendiri
5   dokter_id                  bigint unsigned                    NO        <NULL>         -                            -
6   jadwal_id                  bigint unsigned                    YES       <NULL>         -                            -
7   faskes_id                  bigint unsigned                    YES       <NULL>         -                            -
8   tipe_layanan               enum('chat','video_call','kunjungan_klinik','home_visit') NO        <NULL>         -                            -
9   tanggal_kunjungan          date                               NO        <NULL>         -                            -
10  slot_mulai                 time                               NO        <NULL>         -                            -
11  slot_selesai               time                               NO        <NULL>         -                            -
12  nomor_antrian              smallint unsigned                  YES       <NULL>         -                            -
13  keluhan                    text                               YES       <NULL>         -                            -
14  lampiran_keluhan           json                               YES       <NULL>         -                            -
15  is_rujukan                 tinyint(1)                         NO        '0'            -                            -
16  is_konsultasi_lanjutan     tinyint(1)                         NO        '0'            -                            -
17  status                     enum('menunggu_pembayaran','terjadwal','check_in','berlangsung','selesai','dibatalkan','no_show','kadaluarsa') NO        'menunggu_pembayaran' -                            -
18  dibatalkan_oleh            enum('pasien','dokter','sistem')   YES       <NULL>         -                            -
19  alasan_pembatalan          varchar(255)                       YES       <NULL>         -                            -
20  dibuat_oleh_user_id        bigint unsigned                    NO        <NULL>         -                            -
21  dibuat_at                  timestamp                          NO        'CURRENT_TIMESTAMP' DEFAULT_GENERATED            -
22  diubah_at                  timestamp                          NO        'CURRENT_TIMESTAMP' DEFAULT_GENERATED on update CURRENT_TIMESTAMP -
```

`lampiran_keluhan` is `json`, never `text`. `tipe_layanan` has **no** default,
matching `:506`. `diubah_at` carries `on update CURRENT_TIMESTAMP` on both tables
that have it, from the raw `ALTER` of rule 5.

### 4.2 ENUM-order audit — all four lists, exact order

Compared against the DDL lists, not against the plan's prose:

```
  dokter_jadwal.tipe_layanan
    live   : [online, klinik, home_visit]  (count=3)
    OK   value list AND order match the DDL exactly
  booking.tipe_layanan
    live   : [chat, video_call, kunjungan_klinik, home_visit]  (count=4)
    OK   value list AND order match the DDL exactly
  booking.status
    live   : [menunggu_pembayaran, terjadwal, check_in, berlangsung, selesai, dibatalkan, no_show, kadaluarsa]  (count=8)
    OK   value list AND order match the DDL exactly
  booking.dibatalkan_oleh
    live   : [pasien, dokter, sistem]  (count=3)
    OK   value list AND order match the DDL exactly

  booking.status value COUNT = 8 (the DDL has eight, not seven)
    OK   booking.status really has 8 values
  exclusion set (release the slot): [dibatalkan, kadaluarsa]
  live states (occupy the slot)    : [menunggu_pembayaran, terjadwal, check_in, berlangsung, selesai, no_show] = 6 values
    OK   the six live states are the complement of the two exclusion values
```

### 4.3 Unsigned-flag audit — 19 integer columns, every flag checked

```
  dokter_jadwal    id                       unsigned=yes   expected=yes
  dokter_jadwal    dokter_id                unsigned=yes   expected=yes
  dokter_jadwal    faskes_id                unsigned=yes   expected=yes
  dokter_jadwal    hari                     unsigned=yes   expected=yes
  dokter_jadwal    durasi_slot_menit        unsigned=yes   expected=yes
  dokter_jadwal    kuota_per_sesi           unsigned=yes   expected=yes
  dokter_jadwal    status_aktif             unsigned=NO    expected=no
  dokter_libur     id                       unsigned=yes   expected=yes
  dokter_libur     dokter_id                unsigned=yes   expected=yes
  booking          id                       unsigned=yes   expected=yes
  booking          pasien_id                unsigned=yes   expected=yes
  booking          anggota_keluarga_id      unsigned=yes   expected=yes
  booking          dokter_id                unsigned=yes   expected=yes
  booking          jadwal_id                unsigned=yes   expected=yes
  booking          faskes_id                unsigned=yes   expected=yes
  booking          nomor_antrian            unsigned=yes   expected=yes
  booking          is_rujukan               unsigned=NO    expected=no
  booking          is_konsultasi_lanjutan   unsigned=NO    expected=no
  booking          dibuat_oleh_user_id      unsigned=yes   expected=yes
  integer columns audited: 19
```

`TINYINT(1)` vs a real number, separated by the display width:

```
  dokter_jadwal    hari                     tinyint unsigned     boolean=NO    nullable=NO   default=''
  dokter_jadwal    status_aktif             tinyint(1)           boolean=yes   nullable=NO   default='1'
  booking          is_rujukan               tinyint(1)           boolean=yes   nullable=NO   default='0'
  booking          is_konsultasi_lanjutan   tinyint(1)           boolean=yes   nullable=NO   default='0'
```

`hari` is deliberately **not** a boolean — it is a weekday number — while the
three genuine `TINYINT(1)` columns all use `boolean()`.

### 4.4 FK reconciliation — 9 FKs, none invented, none missing

```
  dokter_jadwal  dokter_id    -> dokter                   (id)  ON DELETE CASCADE      ON UPDATE NO ACTION
  dokter_jadwal  faskes_id    -> faskes                   (id)  ON DELETE NO ACTION    ON UPDATE NO ACTION
  dokter_libur   dokter_id    -> dokter                   (id)  ON DELETE CASCADE      ON UPDATE NO ACTION
  booking        anggota_keluarga_id -> pasien_anggota_keluarga (id)  ON DELETE NO ACTION  ON UPDATE NO ACTION
  booking        dibuat_oleh_user_id  -> users            (id)  ON DELETE NO ACTION    ON UPDATE NO ACTION
  booking        dokter_id           -> dokter            (id)  ON DELETE NO ACTION    ON UPDATE NO ACTION
  booking        faskes_id           -> faskes            (id)  ON DELETE NO ACTION    ON UPDATE NO ACTION
  booking        jadwal_id           -> dokter_jadwal     (id)  ON DELETE NO ACTION    ON UPDATE NO ACTION
  booking        pasien_id           -> pasien            (id)  ON DELETE NO ACTION    ON UPDATE NO ACTION

  dokter_jadwal: DDL declares 2 FOREIGN KEY clause(s); live has 2
  dokter_libur : DDL declares 1 FOREIGN KEY clause(s); live has 1
  booking      : DDL declares 6 FOREIGN KEY clause(s); live has 6
  batch E total FKs: 9 (2 + 1 + 6 = 9)
```

Columns that must carry **no** FK, checked via
`information_schema.REFERENTIAL_CONSTRAINTS` rather than `SHOW CREATE TABLE`:

```
  dokter_libur.tanggal: 0 FK(s)
  booking.keluhan: 0 FK(s)
  booking.lampiran_keluhan: 0 FK(s)
  dokter_jadwal.hari: 0 FK(s)
  pasien_penjamin.faskes_rujukan_id: 0 FK(s)  (A.10/A.11: must stay 0)
```

**No FK was invented, and the A.10/A.11 invariant still holds**: this batch did
not touch `pasien_penjamin.faskes_rujukan_id`, and migration `2026_10_01_000076`
still must not add one.

### 4.5 `SHOW CREATE TABLE` for all three

`dokter_jadwal`:

```sql
CREATE TABLE `dokter_jadwal` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dokter_id` bigint unsigned NOT NULL,
  `faskes_id` bigint unsigned DEFAULT NULL COMMENT 'NULL = layanan online murni',
  `tipe_layanan` enum('online','klinik','home_visit') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'online',
  `hari` tinyint unsigned NOT NULL COMMENT '0=Minggu s.d. 6=Sabtu',
  `jam_mulai` time NOT NULL,
  `jam_selesai` time NOT NULL,
  `durasi_slot_menit` smallint unsigned NOT NULL DEFAULT '15',
  `kuota_per_sesi` smallint unsigned DEFAULT NULL,
  `berlaku_mulai` date NOT NULL,
  `berlaku_sampai` date DEFAULT NULL,
  `status_aktif` tinyint(1) NOT NULL DEFAULT '1',
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diubah_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `dokter_jadwal_faskes_id_foreign` (`faskes_id`),
  KEY `idx_jadwal` (`dokter_id`,`hari`,`status_aktif`),
  CONSTRAINT `dokter_jadwal_dokter_id_foreign` FOREIGN KEY (`dokter_id`) REFERENCES `dokter` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dokter_jadwal_faskes_id_foreign` FOREIGN KEY (`faskes_id`) REFERENCES `faskes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

`dokter_libur`:

```sql
CREATE TABLE `dokter_libur` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dokter_id` bigint unsigned NOT NULL,
  `tanggal` date NOT NULL,
  `alasan` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dokter_libur_dokter_id_foreign` (`dokter_id`),
  CONSTRAINT `dokter_libur_dokter_id_foreign` FOREIGN KEY (`dokter_id`) REFERENCES `dokter` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

`booking`:

```sql
CREATE TABLE `booking` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nomor_booking` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pasien_id` bigint unsigned NOT NULL,
  `anggota_keluarga_id` bigint unsigned DEFAULT NULL COMMENT 'NULL = untuk pasien sendiri',
  `dokter_id` bigint unsigned NOT NULL,
  `jadwal_id` bigint unsigned DEFAULT NULL,
  `faskes_id` bigint unsigned DEFAULT NULL,
  `tipe_layanan` enum('chat','video_call','kunjungan_klinik','home_visit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `tanggal_kunjungan` date NOT NULL,
  `slot_mulai` time NOT NULL,
  `slot_selesai` time NOT NULL,
  `nomor_antrian` smallint unsigned DEFAULT NULL,
  `keluhan` text COLLATE utf8mb4_unicode_ci,
  `lampiran_keluhan` json DEFAULT NULL,
  `is_rujukan` tinyint(1) NOT NULL DEFAULT '0',
  `is_konsultasi_lanjutan` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('menunggu_pembayaran','terjadwal','check_in','berlangsung','selesai','dibatalkan','no_show','kadaluarsa') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'menunggu_pembayaran',
  `dibatalkan_oleh` enum('pasien','dokter','sistem') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan_pembatalan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dibuat_oleh_user_id` bigint unsigned NOT NULL,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diubah_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_nomor_booking_unique` (`nomor_booking`),
  KEY `booking_anggota_keluarga_id_foreign` (`anggota_keluarga_id`),
  KEY `booking_dibuat_oleh_user_id_foreign` (`dibuat_oleh_user_id`),
  KEY `booking_faskes_id_foreign` (`faskes_id`),
  KEY `booking_jadwal_id_foreign` (`jadwal_id`),
  KEY `idx_booking_dokter` (`dokter_id`,`tanggal_kunjungan`),
  KEY `idx_booking_pasien` (`pasien_id`,`status`),
  CONSTRAINT `booking_anggota_keluarga_id_foreign` FOREIGN KEY (`anggota_keluarga_id`) REFERENCES `pasien_anggota_keluarga` (`id`),
  CONSTRAINT `booking_dibuat_oleh_user_id_foreign` FOREIGN KEY (`dibuat_oleh_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `booking_dokter_id_foreign` FOREIGN KEY (`dokter_id`) REFERENCES `dokter` (`id`),
  CONSTRAINT `booking_faskes_id_foreign` FOREIGN KEY (`faskes_id`) REFERENCES `faskes` (`id`),
  CONSTRAINT `booking_jadwal_id_foreign` FOREIGN KEY (`jadwal_id`) REFERENCES `dokter_jadwal` (`id`),
  CONSTRAINT `booking_pasien_id_foreign` FOREIGN KEY (`pasien_id`) REFERENCES `pasien` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

`booking`'s only `UNIQUE KEY` is `booking_nomor_booking_unique (nomor_booking)`,
the inline `UNIQUE` of `:500` that rule 10 compares by semantics rather than by
name. The three-column unique is absent.

### 4.6 Zero domain rows

```
  dokter_jadwal: 0
  dokter_libur: 0
  booking: 0
```

---

## 5. Negative / failure QA (plan-mandated)

The probe: emit `dokter_jadwal.hari` as a **signed** `TINYINT` instead of
`TINYINT UNSIGNED` — which would silently permit `hari = -1` — and assert the
verifier catches it.

File hash before the probe, so the restore could be proved rather than asserted:

```
PRE-PROBE SHA256: F3FE10035617551F4D8745C4F35A20354287E8D582C85C7E38BC101C500F2BE5
```

Single-token edit, `unsignedTinyInteger('hari')` → `tinyInteger('hari')`, then a
real `migrate:fresh` so the live schema genuinely came from the broken migration.

### 5.1 Defect present — raw output

```
$ php artisan migrate:fresh --no-interaction
 2026_10_01_000035_dokter_jadwal_table .. 130.44ms DONE
 2026_10_01_000036_dokter_libur_table .. 56.46ms DONE
 2026_10_01_000037_booking_table .. 441.20ms DONE
MIGRATE_EXIT=0

$ php artisan sehatly:verify-schema --tables=dokter_jadwal

 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred constraint)
 scope dokter_jadwal

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: booking.status (515-516), home_care_pesanan.status (1104-1105), invoice.status (947-948), klaim_bpjs.status (1022-1023), konsultasi.status (542-543), konsultasi_chat.tipe_pesan (568-569), lab_permintaan.status (884-885), master_obat.bentuk_sediaan (713-714), persetujuan_pdp.jenis (1137-1138), pesanan_obat.status (810-811), resep.status (751-752)
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on patient_tanda_vital (line 1161)

 Live schema
 counts tables=44 views=0 columns=323 indexes=115 foreign_keys=42 checks=0
 information_schema columns=323 indexes=115 foreign_keys=42 checks=0

 Discrepancies: 1 (1 drift, 0 informational)
 column_unsigned dokter_jadwal.hari expected: unsigned | actual: signed

 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.

VERIFY_EXIT=1
```

`exit 1`, and it names the exact column `dokter_jadwal.hari`. Note the exit code
alone would not have been sufficient evidence and the `Discrepancies:` line is
what was read (A.8 trap 2).

### 5.2 Restored — raw output

```
POST-RESTORE SHA256: F3FE10035617551F4D8745C4F35A20354287E8D582C85C7E38BC101C500F2BE5
BYTE-IDENTICAL RESTORE: True

$ php artisan migrate:fresh --no-interaction
MIGRATE_EXIT=0

$ php artisan sehatly:verify-schema --tables=dokter_jadwal
 scope dokter_jadwal
 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.
 PASS — 75 tables, 2 views verified. Nothing was written.
VERIFY_EXIT=0
```

The file was restored to a **byte-identical** state, proved by SHA-256, not by
eyeball.

### 5.3 The sibling unsigned columns were NOT touched by the probe

This is the part that makes the probe meaningful. Stripping `unsigned` from all
three columns would have produced the *same single discrepancy* and would have
been wrong, so the siblings are asserted independently:

```
=== 1. the three integer columns of dokter_jadwal, live right now ===
  hari                 tinyint unsigned       nullable=NO   default=''       unsigned=YES
  durasi_slot_menit    smallint unsigned      nullable=NO   default='15'     unsigned=YES
  kuota_per_sesi       smallint unsigned      nullable=YES  default=''       unsigned=YES

=== 2. the unsigned flags are load-bearing: out-of-range inserts ===
  dokter_jadwal.hari = -1  -> REJECTED   MySQL 1264  (signed TINYINT would accept -1; UNSIGNED rejects it)
  dokter_jadwal.durasi_slot_menit = -1  -> REJECTED   MySQL 1264  (same for the SMALLINT sibling)
  dokter_jadwal.kuota_per_sesi = -1  -> REJECTED   MySQL 1264  (same for the nullable SMALLINT sibling)

  dokter_jadwal row count after the rolled-back probe: 0
ASSERTION FAILURES: 0
```

All three still `unsigned`, and all three reject `-1` with MySQL 1264. The probe
wrote inside a transaction that was rolled back, so the table is back at 0 rows.

---

## 6. Comment-accuracy audit (A.15 — the rule this todo's predecessor failed)

### 6.1 Mechanical citation check

A script extracted every `:NNN` from the three new docblocks and printed the
actual text of `telemedicine_test.sql` at each line. **43 distinct citations
checked; 0 wrong.** All 39 in-batch-E citations resolve to exactly what the prose
claims, e.g.:

```
  :475      [in batch E]   hari TINYINT UNSIGNED NOT NULL COMMENT '0=Minggu s.d. 6=Sabtu',
  :478      [in batch E]   durasi_slot_menit SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  :487      [in batch E]   INDEX idx_jadwal (dokter_id, hari, status_aktif)
  :496      [in batch E] ) ENGINE=InnoDB;
  :506      [in batch E]   tipe_layanan ENUM('chat','video_call','kunjungan_klinik','home_visit') NOT NULL,
  :515-516  [in batch E]   status ENUM('menunggu_pembayaran','terjadwal','check_in','berlangsung','selesai',
  :528      [in batch E]   INDEX idx_booking_dokter (dokter_id, tanggal_kunjungan),
  :529      [in batch E]   INDEX idx_booking_pasien (pasien_id, status)
  :530      [in batch E] ) ENGINE=InnoDB;
```

Four citations fell outside the batch-E range and all four are accounted for:

- `:148` and `:249` — deliberate cross-references to the two `dihapus_at`
  columns, both verified to be `dihapus_at TIMESTAMP NULL DEFAULT NULL`.
- two regex artefacts from the literal `24:00:00` in prose, not citations.

### 6.2 Read-back of every comment against the code beneath it

Each of the three files was re-read in full after the schema was live, comparing
every factual claim with `information_schema` and the SQL. **Five defects were
found in my own prose and corrected before commit.** All are recorded here
because A.15's standing rule is that the disclosure is worth more than a clean
report:

1. **`is_konsULTASIA_lanjutan`** — a corrupted identifier in the `booking`
   docblock heading (d). Caught by a non-ASCII/identifier scan, fixed to
   `is_konsultasi_lanjutan`. This is the same defect class the previous executor
   hit.
2. **"21 columns"** for `booking` — wrong; it is **22**. `:499`-`:521` is 23
   lines and the two-line `status` ENUM is one column, so 23 - 1 = 22. The live
   `information_schema` count of 22 is what caught it.
3. **"the first one in the contract whose primary key is a plain surrogate `id`
   yet whose meaningful key is a natural one the schema does not enforce"** — a
   false superlative on `dokter_jadwal`. `dokter` (31) already has NOT NULL UNIQUE
   `nomor_str` and `user_id`, so `dokter_jadwal` is not the first. Replaced with a
   countable claim (14 columns, `:471`-`:484`).
4. **"It is the only FK on this table whose column is `NOT NULL` among the
   attribution-style columns"** — garbled and false. Three FK columns are NOT
   NULL: `pasien_id` `:501`, `dokter_id` `:503`, `dibuat_oleh_user_id` `:519`.
   Rewritten to state that three of six are NOT NULL and three nullable, with all
   six line numbers.
5. **"the cast is batch F-model's"** — mis-attributed. The `array` cast is
   **todo 19's**, not batch F's (batch F is migrations 38-41, todo 12).

Two further claims were tightened for precision rather than correctness:

- "MySQL's implicit `RESTRICT`" → the live `DELETE_RULE` is literally `NO ACTION`,
  which is `RESTRICT` for DML. Both tables now say so.
- "the only two named keys" → "the only two keys **the DDL names**", because MySQL
  creates four more of its own (the FK-support indexes), now enumerated in the
  comment so the count reconciles.

### 6.3 Non-ASCII audit (the corruption class, measured not grepped)

Every non-ASCII code point enumerated per file, not just searched for by name:

```
2026_10_01_000035_dokter_jadwal_table.php: U+2014 x15   (em dash only)
2026_10_01_000036_dokter_libur_table.php: U+2014 x9     (em dash only)
2026_10_01_000037_booking_table.php:     U+2014 x23    (em dash only)
```

A U+2212 (minus sign) introduced in the column arithmetic was replaced with ASCII
so that the em dash is the only non-ASCII character in the batch, matching house
style. The Indonesian strings that remain (`Minggu`, `Sabtu`, `NULL = layanan
online murni`, `NULL = untuk pasien sendiri`) are pure ASCII and are the DDL's own
comment text, copied verbatim per rule 12.

`php -l` exit 0 on all three files.

---

## 7. Deferred-constraint position

**This batch owes no deferral, and that is stated explicitly rather than left
implicit.** All nine foreign keys point at a table that already exists when the
owning migration runs: `dokter` (31) and `faskes` (28) from batch D, `pasien` (20)
and `pasien_anggota_keluarga` (21) from batch C, `users` (12) from batch B, and
`dokter_jadwal` (35) from this same batch one row earlier. The *Deferred
constraints* table in `docs/schema-notes.md` therefore gains **no row** and still
holds exactly one entry, `fk_vital_rm` on `pasien_tanda_vital.rekam_medis_id`,
which is migration `2026_10_01_000076`'s work and not this batch's. The verifier
confirms `1 deferred constraint` before and after.

No FK was added to `pasien_penjamin.faskes_rujukan_id` (A.10/A.11) — verified
still 0 FKs.

---

## 8. Rollback cycle

```powershell
php artisan migrate:rollback --step=3
```

```
 2026_10_01_000037_booking_table .. 35.25ms DONE
 2026_10_01_000036_dokter_libur_table .. 8.38ms DONE
 2026_10_01_000035_dokter_jadwal_table .. 13.31ms DONE
ROLLBACK_EXIT=0
```

Reverse order — `booking` (child, holds an FK to `dokter_jadwal`) before
`dokter_libur` before `dokter_jadwal` (parent) — so children roll back before
parents and no drop is attempted out of dependency order. Then:

```powershell
php artisan migrate        # exit 0
```

`down()` is therefore correct as well as `up()`.

---

## 9. Convergence (repeated_interruptions)

Fingerprint = SHA-256 over every table name, column name/type/nullability/default/
extra, index name/uniqueness/ordinal/column, and foreign key.

```
--- BEFORE ---
telemedisin_db #1        tables=44  columns=323  index_cols=133  fk_cols=42  sha256=2c100058d9940fdeb7cffb63a2d1334e257ec6d46edcbc1310462f9618bb6534
telemedisin_db_test #1   tables=44  columns=323  index_cols=133  fk_cols=42  sha256=2c100058d9940fdeb7cffb63a2d1334e257ec6d46edcbc1310462f9618bb6534

$ php artisan migrate:fresh        # exit 0

--- AFTER ---
telemedisin_db #2        tables=44  columns=323  index_cols=133  fk_cols=42  sha256=2c100058d9940fdeb7cffb63a2d1334e257ec6d46edcbc1310462f9618bb6534
telemedisin_db_test #2   tables=44  columns=323  index_cols=133  fk_cols=42  sha256=2c100058d9940fdeb7cffb63a2d1334e257ec6d46edcbc1310462f9618bb6534
```

All four digests identical, and the two databases are fingerprint-identical to
each other. Re-running the migration converges — it is not order- or
state-dependent.

---

## 10. Batches A-D regression spot-checks

```powershell
php artisan migrate:fresh    # after this batch
```

```
  OK   batch A: master_agama.id is tinyint unsigned
  OK   batch A: master_agama.id has NO auto_increment (EXTRA='')
  OK   batch B: users.dihapus_at is timestamp
  OK   batch C: pasien.tinggi_badan_cm is decimal(5,1)
  OK   batch C/D: dokter.idx_dokter_tipe still present by name
  OK   batch C/D: pasien.idx_pasien_lahir still present by name
  OK   batch C/D: dokter_spesialisasi.uq_dokter_spes still present by name
  OK   batch C/D: faskes.idx_faskes_geo still present by name
  OK   batch D: dokter.durasi_default_menit is smallint unsigned
  OK   batch D: dokter_faskes still has no `id` column
  OK   booking.nomor_booking is UNIQUE (NON_UNIQUE=0)
```

No batch A-D regression.

---

## 11. Data safety census (read-only PDO; no `db:wipe`, nothing destructive)

Every database on the server, before and after — the numbers are identical:

```
  db_simprapkl             tables=26  total_rows=111    migrations_ledger=22
  gawaiseken              tables=18  total_rows=81     migrations_ledger=18
  laravel                  tables=5   total_rows=4      migrations_ledger=4
  manajemen-surat          tables=0   total_rows=0      migrations_ledger=n/a
  sehatly                  tables=10  total_rows=6      migrations_ledger=5
  telemedisin_db           tables=44  total_rows=40     migrations_ledger=40
  telemedisin_db_test      tables=44  total_rows=40     migrations_ledger=40
  trading_journal          tables=12  total_rows=22     migrations_ledger=6
  ukk                      tables=12  total_rows=17     migrations_ledger=6
  ukk_pengaduan_sekolah    tables=15  total_rows=41     migrations_ledger=9
```

Row breakdown — where the non-ledger rows live:

```
  telemedisin_db:      {"migrations":40}
  telemedisin_db_test: {"migrations":40}
  sehatly:             {"migrations":5,"sessions":1}
```

- `telemedisin_db` and `telemedisin_db_test`: **44 tables each, zero domain
  rows**. The only rows in either database are the 40 `migrations` ledger rows
  (3 surviving scaffolds + 37 contract migrations), which the migrator owns.
- `sehatly`: **still 10 tables with its original 5 migration rows**, untouched:
  `0001_01_01_000000_create_users_table`,
  `0001_01_01_000001_create_cache_table`,
  `0001_01_01_000002_create_jobs_table`,
  `2024_01_01_000000_create_passkeys_table`,
  `2025_08_14_170933_add_two_factor_columns_to_users_table`.
  Its 6th row is a pre-existing `sessions` row that this batch did not create —
  recorded rather than assumed away.
- All seven unrelated application databases (`db_simprapkl`, `gawaiseken`,
  `laravel`, `manajemen-surat`, `trading_journal`, `ukk`,
  `ukk_pengaduan_sekolah`) are byte-for-byte untouched: no statement in this todo
  named any of them.

Ledger detail for the two contract databases, reported and **not** asserted — the
batch number is migrator bookkeeping, not a parity fact:

```
  telemedisin_db:       3 batch-E migrations in order, ledger batch=1, max=1, 40 ledger rows
  telemedisin_db_test:  3 batch-E migrations in order, ledger batch=1, max=1, 40 ledger rows
```

---

## 12. Stale-state checks

```
  OK   no bootstrap/cache/config.php on disk
  OK   $env:GIT_INDEX_FILE is unset (this repo also has a linked worktree)
```

`php artisan config:clear` was run before the first `migrate:fresh` and again
after the last one, both exit 0. Per A.5's note about this repo, `.git/index` was
checked specifically rather than by counting `.git/index` files.

Batch-E files sort strictly after batch D's `000034`, and `000035` is the lowest
of mine:

```
  2026_10_01_000034_dokter_pendidikan_table.php
  2026_10_01_000035_dokter_jadwal_table.php     <- lowest of mine
  2026_10_01_000036_dokter_libur_table.php
  2026_10_01_000037_booking_table.php           <- highest of mine
```

---

## 13. Adversarial classes

| class | result |
|---|---|
| **misleading_success_output** | Probed. `migrate:fresh` exit 0 was treated as proving nothing about parity, so all 40 columns were re-derived from `information_schema.COLUMNS` and compared field by field (`COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`, `EXTRA`, plus `COLUMN_COMMENT`): 0 mismatches. All four ENUM lists audited in the SQL's exact order. All 19 integer unsigned flags audited. All index names and column orders verified. Both forbidden unique indexes confirmed absent by ordered-column comparison. FK set reconciles exactly (2/1/6 = 9) with none invented. Batches A-D spot-checked (section 10). **And the A.15 class was probed on the deliverable itself: the read-back found 5 defects in my own comments, listed in section 6.2 — a green build, a green suite and `php -l` were all satisfied while those were wrong.** |
| **stale_state** | No `bootstrap/cache/config.php`; `config:clear` before and after, both exit 0. `GIT_INDEX_FILE` unset. My three files sort after `000034`, `000035` lowest. **The test database was migrated** (criterion 1) — the specific omission that made the previous batch's Unit suite red. |
| **dirty_worktree** | Commit contains only my five paths. `.omo/plans/` (modified) and `.omo/start-work/` + `.omo/evidence/task-3-sehatly.md` (untracked) are orchestrator-owned and were left untouched. |
| **hung_or_long_commands** | Every `migrate:fresh` given an explicit timeout (600 s) and completed in 4-5 s wall clock, so no metadata-lock stall occurred. Exit codes observed, never inferred. **The pre-existing `mysqld` and the user's `php artisan serve` (PID 22288) were not killed** — no `Stop-Process`, no service restart was issued at any point. |
| **repeated_interruptions** | Convergence proven: fingerprint → `migrate:fresh` → fingerprint, all four digests identical (section 9). Rollback cycle `migrate:rollback --step=3` then `migrate`, both exit 0, children before parents (section 8). |
| malformed_input | **Ruled out.** No parser was authored. The only input parsing is the project's existing `SqlSchemaParser`, and A.7's malformed-input behaviour (truncated DDL, empty file, duplicate table, views-only file → exit 2) was neither triggered nor relied upon. |
| prompt_injection | **Ruled out.** `telemedicine_test.sql` is first-party DDL and was read strictly as a specification, never as instructions. It contains 75 `CREATE TABLE` statements, 2 views and 15 `INSERT INTO` seed rows of master data; **no imperative, instruction-like or agent-directed text was found anywhere in it**, and nothing in it was acted on as a command. Its `COMMENT` clauses (`NULL = layanan online murni`, `0=Minggu s.d. 6=Sabtu`, `NULL = untuk pasien sendiri`) are schema documentation and were copied as column comments, which is the intended use. |
| cancel_resume | **Ruled out.** No resumable user flow was authored. Todo 11 creates three tables and nothing else; cancellation *semantics* are recorded in comments for todos 26/27, which are not this todo's. |
| flaky_tests | **Ruled out.** Deterministic. The suite was run three times across the todo (before the negative probe, after the restore, and as the final gate) and returned `93/93, 473 assertions` every time. The zero-match control was measured at exit 1, so a green suite is not vacuous. |

---

## 14. Scope discipline — what was deliberately NOT done

- No Model, Resource, Controller, seeder, factory or route. Todo 19 owns models;
  todo 27 owns the booking transaction.
- No table outside 35-37. Todos 12-17 own the rest.
- No unique index on `(dokter_id, tanggal)` in `dokter_libur`, nor on
  `(dokter_id, tanggal_kunjungan, slot_mulai)` in `booking`. Both absences are
  load-bearing and both are proven above.
- No FK the SQL does not have, and specifically **no** FK on
  `pasien_penjamin.faskes_rujukan_id` (A.10/A.11).
- No deferred constraint registered — none is genuinely deferred here, stated
  explicitly in section 7.
- **`php artisan install:api` was not run in any form** (A.5).
- `telemedicine_test.sql`, `phpunit.xml`, `config/database.php`, `.env`,
  `.env.example`, `composer.*`, `bootstrap/`, `routes/`, `web/`, `app/` and
  `.omo/plans/` were not edited.
- `tests/Unit/Console/VerifySchemaCommandTest.php` was not edited, and the suite
  stayed green without editing it.
- **Pint was run bare**, with no path argument, so its `bootstrap/cache` exclude
  was never overridden.
- No `git add -A` / `.` / `-u`, no `git stash`, no `git checkout .`, no
  `git restore .`, no `git clean`, no `git reset`, no `--amend`, no `push`, and
  nothing committed to `main`.

`docs/timezone-policy.md` **does not exist** and was **not created**. The named
`hari` constant and that policy entry are **todo 19's** work; batch E documents
the `date('w')` mapping in the `dokter_jadwal` docblock instead. This is flagged
so it is not lost.

---

## 15. Cleanup receipts

All scratch work lived in `%TEMP%` (`C:\Users\axioo\AppData\Local\Temp\opencode`),
never in the repository. Deleted after use:

```
  t11-parity-audit.php        (parity audit; report t11-parity-audit.txt)
  t11-citation-check.php      (citation verifier)
  t11-fingerprint.php         (convergence fingerprint)
  t11-adversarial.php         (adversarial receipts)
  t11-negative-companion.php  (unsigned sibling + out-of-range probe)
  t11-siblings.php, t11-negprobe.php   (superseded early drafts)
  t11-parity-audit.txt, t11-adversarial.txt
```

No scratch file was created inside the repository. No process was left running:
no background job, no `Start-Process`, no leftover `php` or `mysqld` beyond the
pre-existing ones, and the user's `php artisan serve` (PID 22288) was left alone.

---

## 16. Paths committed

```
database/migrations/2026_10_01_000035_dokter_jadwal_table.php   (new)
database/migrations/2026_10_01_000036_dokter_libur_table.php    (new)
database/migrations/2026_10_01_000037_booking_table.php         (new)
docs/schema-notes.md                                           (modified — batch-E section)
.omo/evidence/task-11-sehatly.md                               (new — this file)
```

`docs/schema-notes.md` was modified for one reason: the plan asks for
`dokter_libur`'s duplicate guard to be **documented**, and batches B, C and D each
carry such a section in that file. The batch-E section adds **no** row to either
registry — the extra-table registry stays at 7 and the deferred-constraint
registry stays at 1, both confirmed by the verifier and by the unit suite's
re-derivation.
