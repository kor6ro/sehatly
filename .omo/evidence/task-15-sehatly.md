# Task 15 — Migration batch I (laboratory, SQL tables 55-60)

Branch `feat/sehatly-telemedicine`. Commit message: `feat(db): migrate laboratory tables`.

**Read-only law:** `telemedicine_test.sql`, SHA-256
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, 59,604 bytes,
1,349 lines — **byte-unchanged at the start and byte-unchanged at the end** of this
todo (re-verified post-commit).

**PHP:** every command ran with
`$env:PATH = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64;$env:PATH"` as the first
statement of its shell. PHP 8.4.17 (NTS, x64). The bare `php` on PATH is 8.2.29 and
fails Laravel's `^8.3`; it was never used.

---

## 0. Deliverable

Six migrations, named per `docs/migration-order.md` rows 55-60:

| # | SQL line | Table | Migration file |
| --- | --- | --- | --- |
| 55 | 847 | `master_lab_tindakan` | `database/migrations/2026_10_01_000055_master_lab_tindakan_table.php` |
| 56 | 860 | `master_lab_paket` | `database/migrations/2026_10_01_000056_master_lab_paket_table.php` |
| 57 | 868 | `lab_paket_item` | `database/migrations/2026_10_01_000057_lab_paket_item_table.php` |
| 58 | 876 | `lab_permintaan` | `database/migrations/2026_10_01_000058_lab_permintaan_table.php` |
| 59 | 894 | `lab_permintaan_detail` | `database/migrations/2026_10_01_000059_lab_permintaan_detail_table.php` |
| 60 | 905 | `lab_hasil` | `database/migrations/2026_10_01_000060_lab_hasil_table.php` |

Modified: `docs/schema-notes.md` (one appended `## Batch-I` section; 254 insertions,
0 deletions — a pure append, A.17). Created: this file.

**No Model, Resource, Controller, seeder, factory or route was created.** No table
outside 55-60. No seed data in any migration. `php artisan install:api` was never
run in any form (A.5).

### Sort order (stale_state)

All six sort after `2026_10_01_000054_apotek_stok_table.php`, and `2026_10_01_000055`
is the lowest of the six. The full chain is
`0001_01_01_000001` < `0001_01_01_000002` < `2026_09_26_222801` < `2026_10_01_000001`
< … < `2026_10_01_000054` < **`2026_10_01_000055` … `2026_10_01_000060`**.

---

## 1. Every command with its exit code

| # | Command | Exit | Result |
| --- | --- | --- | --- |
| 1 | `Get-FileHash telemedicine_test.sql -Algorithm SHA256` | 0 | `AEFE2247…27F5` — matches the brief exactly |
| 2 | `php artisan config:clear` | 0 | `Configuration cache cleared successfully.`; `bootstrap/cache/config.php` absent before and after |
| 3 | census script (read-only PDO over `information_schema`) | 0 | baseline captured, below |
| 4 | `php -l` × 6 (all six new files) | **0** ×6 | `No syntax errors detected` |
| 5 | `php artisan migrate:fresh --no-interaction` (dev, `telemedisin_db`) | **0** | all 63 migrations `DONE` |
| 6 | `php artisan migrate:fresh --no-interaction` (`$env:DB_DATABASE='telemedisin_db_test'`) | **0** | all 63 migrations `DONE` |
| 7 | `php artisan sehatly:verify-schema --tables=master_lab_tindres` (A.8 trap control) | **0** | `unknown_requested_table` — see §7 |
| 8 | `php artisan sehatly:verify-schema --tables=<the 6 real names>` | **0** | `Discrepancies: 0 (0 drift, 0 informational)` |
| 9 | `php artisan sehatly:verify-schema` (unfiltered) | **1** | `Discrepancies: 25 (17 drift, 8 informational)` |
| 10 | `php artisan test tests/Unit` | **0** | `{"tests":93,"passed":93,"assertions":473}` |
| 11 | `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` | **1** | `{"tests":0,…,"raw":["No tests found."]}` |
| 12 | RULE 1b executable-statement audit | **0** | 44 declarations vs 44 SQL columns, order identical |
| 13 | RULE 2 corruption scan (3 passes) | **0** (3rd) | CJK 0, U+FFFD 0, BOM 0 |
| 14 | citation extraction + print | 0 | 257 tokens / 95 distinct lines |
| 15 | `php artisan migrate:fresh` under the nullability mutation | **0** | as predicted |
| 16 | `php artisan test tests/Unit` under the nullability mutation | **0** | 93/93 — as predicted |
| 17 | `verify-schema` under the nullability mutation | **1** | `column_nullable lab_hasil.satuan` |
| 18 | `php artisan migrate:fresh` after restore | **0** | 567 columns again |
| 19 | `verify-schema` after restore | **0** | `Discrepancies: 0` |
| 20 | `php -l` under the swallowed-statement probe | **0** | as predicted |
| 21 | `php artisan migrate:fresh` under the swallowed-statement probe | **0** | as predicted, `000060` `DONE` |
| 22 | `php artisan test tests/Unit` under the swallowed-statement probe | **0** | 93/93 — as predicted |
| 23 | `verify-schema` under the swallowed-statement probe | **1** | `missing_column lab_hasil.file_pdf_url` |
| 24 | `php artisan migrate:rollback --step=6` | **0** | children before parents |
| 25 | `php artisan migrate --no-interaction` | **0** | 6 re-applied |
| 26 | `migrate:fresh` (2nd convergence pass) | **0** | fingerprint identical |
| 27 | `migrate:fresh` + scoped verify on `telemedisin_db_test` | **0**, **0** | `Discrepancies: 0` |
| 28 | `php artisan test tests/Unit` after the `schema-notes.md` append | **0** | 93/93, registries still parse (7 / 1) |
| 29 | `vendor/bin/pint` (bare, no path argument) | **0** | see §11 |
| 30 | `git add -- database/migrations docs/schema-notes.md .omo/evidence/task-15-sehatly.md` | 0 | 8 paths |
| 31 | `git commit -m "feat(db): migrate laboratory tables" -- <same paths>` | **0** | sha in §12 |

---

## 2. Parity: the six expected shapes, derived not typed

The brief supplied six shapes. Per **RULE 3** they were **not** taken on trust: the
project's own `App\Support\Schema\SqlSchemaParser` was run over
`telemedicine_test.sql` and its per-table model dumped. **All six match.**

| Table | columns | indexes | FKs | checks | engine | matches brief |
| --- | --- | --- | --- | --- | --- | --- |
| `lab_permintaan` | **11** | **2** | **3** | 0 | innodb | yes (`11/2/3`) |
| `lab_permintaan_detail` | **5** | **1** | **3** | 0 | innodb | yes (`5/1/3`) |
| `lab_paket_item` | **2** | **1** | **2** | 0 | innodb | yes (`2/1/2`) |
| `master_lab_paket` | **5** | **1** | **0** | 0 | innodb | yes (`5/1/0`) |
| `master_lab_tindakan` | **10** | **2** | **0** | 0 | innodb | yes (`10/2/0`) |
| `lab_hasil` | **11** | **1** | **2** | 0 | innodb | yes (`11/1/2`) |

**The brief's sixth table name was a transcription error.** It read
`master_lab_tindres`; the DDL at `:847` reads **`master_lab_tindakan`**. Not
propagated. The brief's own `verify-schema` scope argument was also corrupted
(`lab_permVirus_detail` for `lab_permintaan_detail`) — see §7, where running that
corrupted name deliberately is what proved the false-green trap is live.

Calibration re-run with the same parser as a self-check: **105 foreign keys, 80 with
no covering index** — exactly the figure A.8 and A.11 record, so the parser instance
used for this todo behaves like the one they measured.

### `information_schema.COLUMNS` parity dump, all six tables (live `telemedisin_db`)

```
-- master_lab_tindakan : 10 columns
 1 id                       bigint unsigned    null=NO  default=<NULL>      extra=auto_increment   key=PRI
 2 kode                     varchar(20)        null=NO  default=<NULL>      extra=-                key=UNI
 3 nama                     varchar(200)       null=NO  default=<NULL>      extra=-                key=
 4 kelompok                 enum('darah','urine','hormon','kimia_darah','serologi','mikrobiologi','lainnya') null=NO default=<NULL> extra=-
 5 satuan                   varchar(50)        null=YES default=<NULL>      extra=-                key=
 6 nilai_rujukan_laki       varchar(100)       null=YES default=<NULL>      extra=-                key=
 7 nilai_rujukan_perempuan  varchar(100)       null=YES default=<NULL>      extra=-                key=
 8 kode_loinc               varchar(20)        null=YES default=<NULL>      extra=-                key=
 9 harga                    decimal(12,2)      null=NO  default='0.00'      extra=-                key=
10 status_aktif             tinyint(1)         null=NO  default='1'         extra=-                key=

-- master_lab_paket : 5 columns
 1 id                       bigint unsigned    null=NO  default=<NULL>      extra=auto_increment   key=PRI
 2 nama                     varchar(200)       null=NO  default=<NULL>      extra=-                key=
 3 deskripsi                text               null=YES default=<NULL>      extra=-                key=
 4 harga                    decimal(12,2)      null=NO  default='0.00'      extra=-                key=
 5 status_aktif             tinyint(1)         null=NO  default='1'         extra=-                key=

-- lab_paket_item : 2 columns          <-- NO `id`
 1 paket_id                 bigint unsigned    null=NO  default=<NULL>      extra=-                key=PRI
 2 tindakan_id              bigint unsigned    null=NO  default=<NULL>      extra=-                key=PRI

-- lab_permintaan : 11 columns
 1 id                       bigint unsigned    null=NO  default=<NULL>      extra=auto_increment   key=PRI
 2 nomor_permintaan         varchar(30)        null=NO  default=<NULL>      extra=-                key=UNI
 3 rekam_medis_id           bigint unsigned    null=YES default=<NULL>      extra=-                key=      <-- BARE
 4 konsultasi_id            bigint unsigned    null=YES default=<NULL>      extra=-                key=      <-- BARE
 5 pasien_id                bigint unsigned    null=NO  default=<NULL>      extra=-                key=MUL
 6 dokter_id                bigint unsigned    null=NO  default=<NULL>      extra=-                key=MUL
 7 faskes_lab_id            bigint unsigned    null=YES default=<NULL>      extra=-                key=MUL
 8 status                   enum('diminta','sampel_diangkat','diproses','hasil_terbit','dibatalkan') null=NO default='diminta' extra=-
 9 catatan_klinis           text               null=YES default=<NULL>      extra=-                key=
10 dibuat_at                timestamp          null=NO  default='CURRENT_TIMESTAMP' extra=DEFAULT_GENERATED key=
11 diubah_at                timestamp          null=NO  default='CURRENT_TIMESTAMP' extra=DEFAULT_GENERATED on update CURRENT_TIMESTAMP key=

-- lab_permintaan_detail : 5 columns
 1 id                       bigint unsigned    null=NO  default=<NULL>      extra=auto_increment   key=PRI
 2 lab_permintaan_id        bigint unsigned    null=NO  default=<NULL>      extra=-                key=MUL
 3 tindakan_id              bigint unsigned    null=YES default=<NULL>      extra=-                key=MUL
 4 paket_id                 bigint unsigned    null=YES default=<NULL>      extra=-                key=MUL
 5 prioritas                enum('rutin','cepat','cito') null=NO default='rutin' extra=-               key=

-- lab_hasil : 11 columns
 1 id                       bigint unsigned    null=NO  default=<NULL>      extra=auto_increment   key=PRI
 2 lab_permintaan_id        bigint unsigned    null=NO  default=<NULL>      extra=-                key=MUL
 3 tindakan_id              bigint unsigned    null=NO  default=<NULL>      extra=-                key=MUL
 4 nilai                    varchar(100)       null=NO  default=<NULL>      extra=-                key=
 5 satuan                   varchar(50)        null=YES default=<NULL>      extra=-                key=
 6 nilai_rujukan            varchar(100)       null=YES default=<NULL>      extra=-                key=
 7 is_abnormal              tinyint(1)         null=NO  default='0'         extra=-                key=
 8 keterangan               text               null=YES default=<NULL>      extra=-                key=
 9 diperiksa_oleh           bigint unsigned    null=YES default=<NULL>      extra=-                key=      <-- BARE
10 tanggal_hasil            datetime           null=NO  default=<NULL>      extra=-                key=
11 file_pdf_url             varchar(500)       null=YES default=<NULL>      extra=-                key=
```

**44 columns compared across the six tables, 0 mismatches.** Every value above was
read from `information_schema`, and every one was cross-checked against the parser's
model of the DDL — not against a remembered value.

Note `dibuat_at` / `diubah_at`: `EXTRA` is `DEFAULT_GENERATED` and `diubah_at` also
carries `on update CURRENT_TIMESTAMP`. This is the MySQL-8 form A.18 lists as a
required fold, and it is present and correct here — the raw
`ALTER TABLE lab_permintaan MODIFY diubah_at …` landed.

### `SHOW CREATE TABLE` — all six, verbatim

```sql
CREATE TABLE `master_lab_tindakan` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kelompok` enum('darah','urine','hormon','kimia_darah','serologi','mikrobiologi','lainnya') COLLATE utf8mb4_unicode_ci NOT NULL,
  `satuan` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nilai_rujukan_laki` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nilai_rujukan_perempuan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kode_loinc` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `harga` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status_aktif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `master_lab_tindakan_kode_unique` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `master_lab_paket` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deskripsi` text COLLATE utf8mb4_unicode_ci,
  `harga` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status_aktif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lab_paket_item` (
  `paket_id` bigint unsigned NOT NULL,
  `tindakan_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`paket_id`,`tindakan_id`),
  KEY `lab_paket_item_tindakan_id_foreign` (`tindakan_id`),
  CONSTRAINT `lab_paket_item_paket_id_foreign` FOREIGN KEY (`paket_id`) REFERENCES `master_lab_paket` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lab_paket_item_tindakan_id_foreign` FOREIGN KEY (`tindakan_id`) REFERENCES `master_lab_tindakan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lab_permintaan` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nomor_permintaan` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rekam_medis_id` bigint unsigned DEFAULT NULL,
  `konsultasi_id` bigint unsigned DEFAULT NULL,
  `pasien_id` bigint unsigned NOT NULL,
  `dokter_id` bigint unsigned NOT NULL,
  `faskes_lab_id` bigint unsigned DEFAULT NULL,
  `status` enum('diminta','sampel_diangkat','diproses','hasil_terbit','dibatalkan') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'diminta',
  `catatan_klinis` text COLLATE utf8mb4_unicode_ci,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diubah_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lab_permintaan_nomor_permintaan_unique` (`nomor_permintaan`),
  KEY `lab_permintaan_pasien_id_foreign` (`pasien_id`),
  KEY `lab_permintaan_dokter_id_foreign` (`dokter_id`),
  KEY `lab_permintaan_faskes_lab_id_foreign` (`faskes_lab_id`),
  CONSTRAINT `lab_permintaan_dokter_id_foreign` FOREIGN KEY (`dokter_id`) REFERENCES `dokter` (`id`),
  CONSTRAINT `lab_permintaan_faskes_lab_id_foreign` FOREIGN KEY (`faskes_lab_id`) REFERENCES `faskes` (`id`),
  CONSTRAINT `lab_permintaan_pasien_id_foreign` FOREIGN KEY (`pasien_id`) REFERENCES `pasien` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lab_permintaan_detail` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lab_permintaan_id` bigint unsigned NOT NULL,
  `tindakan_id` bigint unsigned DEFAULT NULL,
  `paket_id` bigint unsigned DEFAULT NULL,
  `prioritas` enum('rutin','cepat','cito') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'rutin',
  PRIMARY KEY (`id`),
  KEY `lab_permintaan_detail_lab_permintaan_id_foreign` (`lab_permintaan_id`),
  KEY `lab_permintaan_detail_tindakan_id_foreign` (`tindakan_id`),
  KEY `lab_permintaan_detail_paket_id_foreign` (`paket_id`),
  CONSTRAINT `lab_permintaan_detail_lab_permintaan_id_foreign` FOREIGN KEY (`lab_permintaan_id`) REFERENCES `lab_permintaan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lab_permintaan_detail_paket_id_foreign` FOREIGN KEY (`paket_id`) REFERENCES `master_lab_paket` (`id`),
  CONSTRAINT `lab_permintaan_detail_tindakan_id_foreign` FOREIGN KEY (`tindakan_id`) REFERENCES `master_lab_tindakan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lab_hasil` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lab_permintaan_id` bigint unsigned NOT NULL,
  `tindakan_id` bigint unsigned NOT NULL,
  `nilai` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `satuan` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nilai_rujukan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_abnormal` tinyint(1) NOT NULL DEFAULT '0',
  `keterangan` text COLLATE utf8mb4_unicode_ci,
  `diperiksa_oleh` bigint unsigned DEFAULT NULL,
  `tanggal_hasil` datetime NOT NULL,
  `file_pdf_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `lab_hasil_lab_permintaan_id_foreign` (`lab_permintaan_id`),
  KEY `lab_hasil_tindakan_id_foreign` (`tindakan_id`),
  CONSTRAINT `lab_hasil_lab_permintaan_id_foreign` FOREIGN KEY (`lab_permintaan_id`) REFERENCES `lab_permintaan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lab_hasil_tindakan_id_foreign` FOREIGN KEY (`tindakan_id`) REFERENCES `master_lab_tindakan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### ENUM-order audit — byte comparison, reference vs live

```
master_lab_tindakan.kelompok
   reference : enum('darah','urine','hormon','kimia_darah','serologi','mikrobiologi','lainnya')
   live      : enum('darah','urine','hormon','kimia_darah','serologi','mikrobiologi','lainnya')
   ORDERED byte-identical = YES
lab_permintaan.status
   reference : enum('diminta','sampel_diangkat','diproses','hasil_terbit','dibatalkan')
   live      : enum('diminta','sampel_diangkat','diproses','hasil_terbit','dibatalkan')
   ORDERED byte-identical = YES
lab_permintaan_detail.prioritas
   reference : enum('rutin','cepat','cito')
   live      : enum('rutin','cepat','cito')
   ORDERED byte-identical = YES
```

Compared as **ordered strings**, never sorted — A.18's fold, and the direct answer to
todo 14's finding that a bare `sort()` on an array-of-arrays is not a total order.
All three lists are identical in value **and** position. The three ENUMs in the batch
are the only ENUM columns it has; there is no `JSON` column anywhere in batch I, so
the "`json()` never `text()`" rule had no application here.

### Signedness audit — 23 numeric columns, every one compared to the DDL

```
lab_hasil                id                       bigint unsigned      live=unsigned ref=unsigned MATCH
lab_hasil                lab_permintaan_id        bigint unsigned      live=unsigned ref=unsigned MATCH
lab_hasil                tindakan_id              bigint unsigned      live=unsigned ref=unsigned MATCH
lab_hasil                is_abnormal              tinyint(1)           live=signed   ref=signed   MATCH
lab_hasil                diperiksa_oleh           bigint unsigned      live=unsigned ref=unsigned MATCH
lab_paket_item           paket_id                 bigint unsigned      live=unsigned ref=unsigned MATCH
lab_paket_item           tindakan_id              bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan           id                       bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan           rekam_medis_id           bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan           konsultasi_id            bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan           pasien_id                bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan           dokter_id                bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan           faskes_lab_id            bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan_detail    id                       bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan_detail    lab_permintaan_id        bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan_detail    tindakan_id              bigint unsigned      live=unsigned ref=unsigned MATCH
lab_permintaan_detail    paket_id                 bigint unsigned      live=unsigned ref=unsigned MATCH
master_lab_paket         id                       bigint unsigned      live=unsigned ref=unsigned MATCH
master_lab_paket         harga                    decimal(12,2)        live=signed   ref=signed   MATCH
master_lab_paket         status_aktif             tinyint(1)           live=signed   ref=signed   MATCH
master_lab_tindakan      id                       bigint unsigned      live=unsigned ref=unsigned MATCH
master_lab_tindakan      harga                    decimal(12,2)        live=signed   ref=signed   MATCH
master_lab_tindakan      status_aktif             tinyint(1)           live=signed   ref=signed   MATCH

signed columns audited   = 5
unsigned columns audited = 18
total numeric columns    = 23
```

The 5 signed columns are `lab_hasil.is_abnormal`, `master_lab_paket.status_aktif`,
`master_lab_paket.harga`, `master_lab_tindakan.status_aktif` and
`master_lab_tindakan.harga`. **No integer in batch I is unsigned-but-written-signed
or vice versa**, and per the brief's warning the decision was made **per column from
the DDL**, not by assuming. Note this batch has **no** `apotek_stok`-style signed
*quantity*: its only signed columns are two `TINYINT(1)` flags and two
`DECIMAL(12,2)` prices, and `DECIMAL` is never signed in the SQL's usage here. The
`unsignedBigInteger` helper was used for all 18 `BIGINT UNSIGNED` columns including
the three bare ones, and `boolean()` for all three `TINYINT(1)` columns.

### Index audit — name, uniqueness, and ORDERED column list

```
-- master_lab_tindakan: 2 indexes
   master_lab_tindakan_kode_unique   NON_UNIQUE=0 unique=YES cols=[kode]  => inline/engine-named, compared BY SEMANTICS (rule 10)
   PRIMARY                           NON_UNIQUE=0 unique=YES cols=[id]   => PRIMARY KEY (declared)
-- master_lab_paket: 1 index
   PRIMARY                           NON_UNIQUE=0 unique=YES cols=[id]   => PRIMARY KEY (declared)
-- lab_paket_item: 2 indexes
   lab_paket_item_tindakan_id_foreign NON_UNIQUE=1 unique=no cols=[tindakan_id] => NOT in the DDL, InnoDB FK-support index, implied (27c6ca8)
   PRIMARY                           NON_UNIQUE=0 unique=YES cols=[paket_id, tindakan_id] => PRIMARY KEY (declared)
-- lab_permintaan: 5 indexes
   lab_permintaan_dokter_id_foreign       NON_UNIQUE=1 unique=no cols=[dokter_id]         => implied
   lab_permintaan_faskes_lab_id_foreign   NON_UNIQUE=1 unique=no cols=[faskes_lab_id]     => implied
   lab_permintaan_nomor_permintaan_unique NON_UNIQUE=0 unique=YES cols=[nomor_permintaan] => inline, BY SEMANTICS
   lab_permintaan_pasien_id_foreign       NON_UNIQUE=1 unique=no cols=[pasien_id]         => implied
   PRIMARY                                 NON_UNIQUE=0 unique=YES cols=[id]              => declared
-- lab_permintaan_detail: 4 indexes
   lab_permintaan_detail_lab_permintaan_id_foreign NON_UNIQUE=1 unique=no cols=[lab_permintaan_id] => implied
   lab_permintaan_detail_paket_id_foreign  NON_UNIQUE=1 unique=no cols=[paket_id]  => implied
   lab_permintaan_detail_tindakan_id_foreign NON_UNIQUE=1 unique=no cols=[tindakan_id] => implied
   PRIMARY                                 NON_UNIQUE=0 unique=YES cols=[id]  => declared
-- lab_hasil: 3 indexes
   lab_hasil_lab_permintaan_id_foreign    NON_UNIQUE=1 unique=no cols=[lab_permintaan_id] => implied
   lab_hasil_tindakan_id_foreign          NON_UNIQUE=1 unique=no cols=[tindakan_id] => implied
   PRIMARY                                NON_UNIQUE=0 unique=YES cols=[id]  => declared
```

**Batch I declares NO named index and NO named constraint anywhere in `:847-919`.**
There is not one `idx_*` or `uq_*` between those lines. Consequently rule 10's
by-name comparison never fires in this batch: the only two uniques are the **inline**
`UNIQUE`s on `master_lab_tindakan.kode` (`:849`) and
`lab_permintaan.nomor_permintaan` (`:878`), both compared by semantics, and MySQL's
`kode` and Laravel's `master_lab_tindakan_kode_unique` are the same constraint. The
one name-authoritative index in the batch is `lab_paket_item`'s composite PK, and its
**column order** `(paket_id, tindakan_id)` is the contract.

**The nine implied FK-support indexes** (1 + 3 + 3 + 2) are absent from the DDL and
are correctly tolerated by `SchemaDiffer::diffIndexes()` (commit `27c6ca8`). They must
**not** be suppressed by adding covering indexes of our own — verified by the fact
that the table reaches `Discrepancies: 0` with all nine present.

---

## 3. FK reconciliation — 10 declared, 10 live, 0 invented, 0 missing

```
lab_hasil              lab_hasil_lab_permintaan_id_foreign  [lab_permintaan_id] -> lab_permintaan      (id)  ON DELETE CASCADE    ON UPDATE NO ACTION
lab_hasil              lab_hasil_tindakan_id_foreign        [tindakan_id]        -> master_lab_tindakan (id)  ON DELETE NO ACTION   ON UPDATE NO ACTION
lab_paket_item         lab_paket_item_paket_id_foreign      [paket_id]           -> master_lab_paket    (id)  ON DELETE CASCADE    ON UPDATE NO ACTION
lab_paket_item         lab_paket_item_tindakan_id_foreign   [tindakan_id]        -> master_lab_tindakan (id)  ON DELETE NO ACTION   ON UPDATE NO ACTION
lab_permintaan         lab_permintaan_dokter_id_foreign     [dokter_id]          -> dokter              (id)  ON DELETE NO ACTION   ON UPDATE NO ACTION
lab_permintaan         lab_permintaan_faskes_lab_id_foreign [faskes_lab_id]      -> faskes              (id)  ON DELETE NO ACTION   ON UPDATE NO ACTION
lab_permintaan         lab_permintaan_pasien_id_foreign     [pasien_id]          -> pasien              (id)  ON DELETE NO ACTION   ON UPDATE NO ACTION
lab_permintaan_detail  lab_permintaan_detail_lab_permintaan_id_foreign [lab_permintaan_id] -> lab_permintaan (id) ON DELETE CASCADE ON UPDATE NO ACTION
lab_permintaan_detail  lab_permintaan_detail_paket_id_foreign [paket_id]          -> master_lab_paket    (id)  ON DELETE NO ACTION   ON UPDATE NO ACTION
lab_permintaan_detail  lab_permintaan_detail_tindakan_id_foreign [tindakan_id]     -> master_lab_tindakan (id)  ON DELETE NO ACTION   ON UPDATE NO ACTION

live FK count per table:  master_lab_tindakan 0 | master_lab_paket 0 | lab_paket_item 2 | lab_permintaan 3 | lab_permintaan_detail 3 | lab_hasil 2
TOTAL live FKs across batch I = 10
```

The DDL's `FOREIGN KEY` clauses for these six tables, enumerated by the parser: **10**
(`:872`, `:873`, `:889`, `:890`, `:891`, `:900`, `:901`, `:902`, `:917`, `:918`).
**10 live = 10 declared. No FK invented, none missing, no `ON DELETE` action
mismatched.** The four cascades (`:872`, `:900`, `:917` and the parent side of
`lab_paket_item`) and the six implicit `NO ACTION`s reproduce exactly.

### The three bare columns, proven FK-free

Proven against **`information_schema.REFERENTIAL_CONSTRAINTS` joined to
`KEY_COLUMN_USAGE`** — deliberately **not** by reading `SHOW CREATE TABLE`, which only
prints the constraints that exist and so cannot distinguish "absent" from "not looked
for". **A column with zero foreign keys does not appear in that result set at all, so
"no row" is the expected evidence and a query returning nothing is a pass, not a
failed lookup.** Each column was separately confirmed to *exist*, so "no row" cannot
be confused with "no column".

```
lab_permintaan.rekam_medis_id      FKs=0   column exists=YES (bigint unsigned, null=YES)
    SQL :879  |   rekam_medis_id BIGINT UNSIGNED NULL,
    (all FK rows for lab_permintaan: 3 -> per-column breakdown above is ZERO, as required)
lab_permintaan.konsultasi_id       FKs=0   column exists=YES (bigint unsigned, null=YES)
    SQL :880  |   konsultasi_id BIGINT UNSIGNED NULL,
    (all FK rows for lab_permintaan: 3 -> per-column breakdown above is ZERO, as required)
lab_hasil.diperiksa_oleh           FKs=0   column exists=YES (bigint unsigned, null=YES)
    SQL :914  |   diperiksa_oleh BIGINT UNSIGNED NULL,
    (all FK rows for lab_hasil: 2 -> per-column breakdown above is ZERO, as required)
```

`SHOW CREATE TABLE` corroborates independently and visibly: `rekam_medis_id`,
`konsultasi_id` and `diperiksa_oleh` appear with **no `KEY` line and no
`CONSTRAINT` line**, while every genuinely-constrained column in the same tables has
both.

### The asymmetry, and why the two bare columns are not an oversight

`lab_permintaan` carries **three** real foreign keys (`:889` `pasien_id`,
`:890` `dokter_id`, `:891` `faskes_lab_id`) and **two** bare reference-shaped columns.
The three constrained ones are what the request's *validity* depends on — a patient,
an ordering doctor, and the facility that will run the test. The two bare ones are
**provenance**, and both are nullable precisely because a request can have neither: a
walk-in patient at a laboratory, or a request raised by a doctor who never held a
teleconsultation. `lab_hasil.diperiksa_oleh` is nullable for the same class of reason —
a verifying pathologist at the `faskes` running the test need not have a platform
account. **That asymmetry is deliberate and is commented as such at the declaration
site in `000058` and `000060`.**

---

## 4. The three trap comments, quoted verbatim from disk

### TRAP 1 — `lab_hasil.diperiksa_oleh` has NO foreign key

`2026_10_01_000060_lab_hasil_table.php:13-19` (class docblock):

```
 * ## TRAP 1 — `diperiksa_oleh` HAS **NO FOREIGN KEY**. IT IS BARE **BY CONTRACT**.
 *
 * `diperiksa_oleh BIGINT UNSIGNED NULL` (`:914`) reads exactly like a reference to
 * `users(id)`, it is named exactly like one, and it is the **only** column in this
 * table that is a plausible user reference. **The DDL declares no `FOREIGN KEY` for
 * it.** The table's foreign keys are on `lab_permintaan_id` (`:917`) and
 * `tindakan_id` (`:918`) and on nothing else.
```

`2026_10_01_000060_lab_hasil_table.php:193-212` (at the declaration itself):

```
            // ## TRAP 1 - `diperiksa_oleh BIGINT UNSIGNED NULL` (:914) HAS **NO
            // FOREIGN KEY**. IT IS BARE BY CONTRACT.
            //
            // DO NOT write `->foreign('diperiksa_oleh')->references('id')->on('users')`
            // here. `users` is table 12 and exists many batches earlier, so the
            // constraint would SUCCEED at migration time and become permanent
            // `extra_foreign_key` drift while `migrate:fresh`, `php -l` and the whole
            // unit suite all stayed green. The plan's generated no-FK list names this
            // column at :914, but the plan's todo-15 PROSE DOES NOT MENTION IT AT
            // ALL, and that omission is exactly how an invented constraint gets
            // written. The substantive reason: a verifying pathologist at the
            // `faskes` running the test need not have a platform account, so a
            // foreign key would make a legitimate external verifier
            // unrepresentable - the same reasoning that leaves
            // `audit_log.user_id` (`:1120`, a bare `user_id BIGINT UNSIGNED NULL`
            // with no FOREIGN KEY) and `artikel.reviewer_user_id` (`:1078`) bare.
            // The plan gives the rationale for `audit_log.user_id` as "deliberately
            // unconstrained so the log survives user deletion"; that sentence is the
            // PLAN's, not a `COMMENT` in the DDL - `:1120` itself carries no comment
            // text, so do not read the justification as if the SQL stated it.
```

### TRAP 2 — `lab_paket_item` has NO `id`; it is a composite-PK join table

`2026_10_01_000057_lab_paket_item_table.php:10-25`:

```
 * ## TRAP 2 — THIS TABLE HAS **NO `id` COLUMN AT ALL**. IT IS A COMPOSITE-PRIMARY-KEY JOIN TABLE.
 *
 * The DDL is four declarations long and **all four** of them are visible in
 * `:869`-`:873`:
 *
 * ```
 *   paket_id BIGINT UNSIGNED NOT NULL,
 *   tindakan_id BIGINT UNSIGNED NOT NULL,
 *   PRIMARY KEY (paket_id, tindakan_id),
 *   FOREIGN KEY (paket_id) REFERENCES master_lab_paket(id) ON DELETE CASCADE,
 * ```
 *
 * **2 columns, 1 index, 2 foreign keys, 0 checks.** There is no `id`, no surrogate
 * key, no `created_at`/`updated_at`, no `harga`, no `urutan`, no `qty` and no
 * `status_aktif` — a package's line items are exactly which test is in which
 * bundle, and nothing else.
```

and at the declaration site, `000057:78-79` plus the class docblock's warning that it
is **one of exactly four** such tables in the contract (`role_permissions` 15,
`user_roles` 16, `dokter_faskes` 33, `lab_paket_item` 57 — rule 3 of
`docs/migration-order.md` names all four), that `$table->id()` would be wrong twice
over (it emits `BIGINT UNSIGNED` *and* creates a column the DDL lacks), and that
**todo 19's model needs `public $incrementing = false`, a two-element
`protected $primaryKey = ['paket_id', 'tindakan_id']` and `public $timestamps =
false`.** `SHOW CREATE TABLE lab_paket_item` confirms it: no `id` column, and
`PRIMARY KEY (\`paket_id\`,\`tindakan_id\`)`.

### TRAP 3 — two reference-shaped columns on `lab_permintaan` are bare

`2026_10_01_000058_lab_permintaan_table.php:15-32` (class docblock):

```
 * ## TRAP 3 — TWO REFERENCE-SHAPED COLUMNS ARE **BARE**, AND THE ASYMMETRY IS DELIBERATE
 *
 * The DDL declares **exactly three** `FOREIGN KEY` clauses for this table
 * (`:889`-`:891`) — on `pasien_id`, `dokter_id` and `faskes_lab_id` — and **none** on
 * the two columns that look exactly as much like a reference:
 *
 * | Column | SQL line | Declared type | Foreign keys on this table? |
 * | --- | --- | --- | --- |
 * | `rekam_medis_id` | **`:879`** | `BIGINT UNSIGNED NULL` | **none — bare** |
 * | `konsultasi_id` | **`:880`** | `BIGINT UNSIGNED NULL` | **none — bare** |
 *
 * Both are bare **by contract**. `rekam_medis` is table 42 and `konsultasi` is table
 * 38, so both targets exist long before this migration runs and `->foreign()` on
 * either would **succeed** and become permanent `extra_foreign_key` drift —
 * `migrate:fresh` stays green the whole time it does. This is the same omission that
 * produced `resep.konsultasi_id` and `resep.rekam_medis_id` in batch H, and it is
 * the reason this paragraph exists: **the plan's own todo-15 prose does not mention
 * either column, and absence from a brief is exactly how an invented constraint gets
 * written.** It has already happened twice in this project.
```

and at the two declarations, `000058:161-167` (`TRAP 3a`) and `000058:172-176`
(`TRAP 3b`), each naming its own SQL line, each stating that the target table already
exists so the constraint would succeed, and each stating that it is not deferred.

---

## 5. RULE 1b — executable-statement audit (no swallowed statements)

Method: each file is run through **PHP's own `token_get_all()`**, `T_COMMENT` and
`T_DOC_COMMENT` tokens are **discarded**, and the remaining executable text is scanned
for `$table->method('column'` calls. Constraint calls (`foreign`, `primary`, `unique`,
`index`, …) are classified separately and excluded from the column count. A
declaration swallowed by a `//` comment therefore disappears from the count — which
is the whole point. Each count is then compared against the reference DDL's column
count from `SqlSchemaParser`, and the **order** is compared too.

| File | comment tokens discarded | `Schema::create` present in executable text | **executable column declarations** | **SQL column count** | order identical | duplicate | missing | extra |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `2026_10_01_000055_master_lab_tindakan_table.php` | 44 | yes | **10** | **10** | yes | none | none | none |
| `2026_10_01_000056_master_lab_paket_table.php` | 26 | yes | **5** | **5** | yes | none | none | none |
| `2026_10_01_000057_lab_paket_item_table.php` | 35 | yes | **2** | **2** | yes | none | none | none |
| `2026_10_01_000058_lab_permintaan_table.php` | 54 | yes | **11** | **11** | yes | none | none | none |
| `2026_10_01_000059_lab_permintaan_detail_table.php` | 30 | yes | **5** | **5** | yes | none | none | none |
| `2026_10_01_000060_lab_hasil_table.php` | 78 | yes | **11** | **11** | yes | none | none | none |
| **total** | | | **44** | **44** | | | | |

**All six match the SQL: no swallowed statements, no duplicated column, no extra
column, and the declaration order is identical to the DDL's.** The `constraint calls`
detected were exactly the 10 `foreign` calls and the one `primary` call, and nothing
else — no stray `unique()` that would have created an index the DDL does not have.

A **second, independent implementation** of the same count (written separately, for
the corruption scan) produced the identical figures 10/5/2/11/5/11, which is a
cross-check that the count is not an artefact of one script.

---

## 6. RULE 1 — mechanical citation verification

Per **A.16**, no inline `:NNN` was trusted. A script extracted every citation from all
six files and printed the **actual** `telemedicine_test.sql` line at each number, and
each was read against the claim it supports.

```
files scanned            : 6
total citation TOKENS    : 257 (repeats included)
distinct SQL lines cited : 95
SQL line span cited      : 365 - 1335
```

Every one of the 257 was checked. **Citations wrong: 0.** The span matters: 26 of them
are *outside* the batch's own DDL (`:847-919`) and the seed (`:1236-1340`) — the
cross-table references most likely to be transcribed wrong — and each of those was
individually printed and read: `:365` (`faskes.tipe`), `:686`
(`rekam_medis_lampiran.tipe`), `:724`/`:835` (`harga_jual`), `:749`/`:802`/`:831`
(pharmacy `apotek_id`), `:758` (`resep.qr_token`), `:810`-`:811`, `:840` (`uq_stok`),
`:941` (`referensi_id`), `:940` (`referensi_tipe`), `:947`-`:948`, `:1022`-`:1023`,
`:1041` (`notifikasi.tipe`), `:1078`, `:1120`, `:1153`, `:1161`-`:1163`.

**0 of 257 wrong. The three bare-column citations the brief supplied (`:879`, `:880`,
`:914`) are all correct** — each is the exact `CREATE TABLE` line of the column it
names.

One comment was **tightened** during this pass rather than left as written: the
`000060` docblock quoted the plan's rationale for `audit_log.user_id` as though the
DDL said it, when `:1120` carries no `COMMENT` text. It now says explicitly that the
rationale is the **plan's** and not a `COMMENT` in the DDL. That is the A.15 defect
class caught in the act rather than shipped.

---

## 7. RULE 2 — corruption scan, full Unicode range, per file

Final assertion set (after the auditor itself was corrected — see §11):

| File | CJK / fullwidth | any disallowed code point | U+FFFD | BOM | unresolved `table.column` refs | executable cols |
| --- | --- | --- | --- | --- | --- | --- |
| `000055_master_lab_tindakan_table.php` | **0** | 0 | 0 | 0 | 0 | 10 |
| `000056_master_lab_paket_table.php` | **0** | 0 | 0 | 0 | 0 | 5 |
| `000057_lab_paket_item_table.php` | **0** | 0 | 0 | 0 | 0 | 2 |
| `000058_lab_permintaan_table.php` | **0** | 0 | 0 | 0 | 0 | 11 |
| `000059_lab_permintaan_detail_table.php` | **0** | 0 | 0 | 0 | 0 | 5 |
| `000060_lab_hasil_table.php` | **0** | 0 | 0 | 0 | 0 | 11 |
| `docs/schema-notes.md` (appended section) | **0** | 0 | 0 | 0 | 0 | — |

Regex used, per A.17: `[\u3000-\u9FFF\uFF00-\uFFEF]` → **0 in every file**, and the
same plus a full non-ASCII enumeration. The only non-ASCII code points present across
all six migrations are **U+2014 EM DASH (94)** and **U+2026 HORIZONTAL ELLIPSIS (3)** —
ordinary English punctuation. Calibrated against the pre-existing committed
`2026_10_01_000054_apotek_stok_table.php`, which contains 15 em-dashes, so matching it
is correct rather than defective. `docs/schema-notes.md`: CJK 0, U+FFFD 0, no BOM, and
`git diff --numstat` reads **254 insertions / 0 deletions** — a pure append, so no
pre-existing byte was rewritten. All three files inspected as UTF-8 pass
`mb_check_encoding`.

### Corruptions I introduced and caught

Per RULE 2's instruction to scan for "transposed letters in identifiers, corrupted
index names and corrupted ENUM values", five were found and fixed:

| Where | Corrupted text | Corrected to | Caught by |
| --- | --- | --- | --- |
| `000057` docblock | `tandidato_id` | `tindakan_id` | read-back immediately after the write |
| `000059` docblock | `` `tSO NULL` `` | `` **Both `tindakan_id` and `paket_id` NULL** `` | read-back immediately after the write |
| `schema-notes.md:1077` | `lab_paket_item_tendency_id_foreign` | `lab_paket_item_tindakan_id_foreign` | the token audit, which flagged it as "not in the SQL" |
| `schema-notes.md:1078` | `` \`t indulge_id\` `` | `` `tindakan_id` `` | same |
| `schema-notes.md:1116` | `` `t sneaky_id` `` | `` `tindakan_id` `` | same |
| `schema-notes.md:1143` | `puskes_assignment` | `puskesmas` | same |

The last four are the exact class todo 13 and todo 14 each shipped and caught. The
`schema-notes.md` ones were found by a token audit that flags every snake_case or
backticked token **not present in `telemedicine_test.sql`**, and the replacements were
then re-verified individually (`tindakan_id` present 8×, `puskesmas` present 1×,
`lab_paket_item_tindakan_id_foreign` correctly **absent from the DDL**, since it is an
InnoDB-generated name — which is precisely what the sentence it appears in asserts).
Two Indonesian illustrative words (`Reaktif`, `Tidak Cedera`) were also replaced with
English (`positive`, `not detected`) so the prose cannot be mistaken for a
transcription from the DDL.

### A.7/A.8 false-green traps, demonstrated

The brief's own corrupted scope argument was run **deliberately as a control**:

```
$ php artisan sehatly:verify-schema --tables=master_lab_tindres

 scope master_lab_tindres
 Discrepancies: 1 (0 drift, 1 informational)
 unknown_requested_table master_lab_tindres expected: - | actual: -
 PASS — 75 tables, 2 views verified. Nothing was written.
control exit = 0
```

**A misspelled table name exits 0, prints a green "PASS — 75 tables, 2 views
verified" banner, and verifies nothing.** A.8's second false-green trap, reproduced
end-to-end. The real run's `scope` line was therefore read rather than its exit code
or its banner, and the banner in `--tables` mode is meaningless in any case (A.7).

---

## 8. Acceptance criteria

**1. `migrate:fresh` exits 0 against BOTH databases.**
`telemedisin_db` → **exit 0**. `telemedisin_db_test` (fresh shell,
`$env:DB_DATABASE='telemedisin_db_test'`) → **exit 0**. Both re-run at the end after
the QA probes.

**2. Scoped verify exits 0 with `Discrepancies: 0`, scope confirms 6 real names.**

```
 scope master_lab_tindakan, master_lab_paket, lab_paket_item, lab_permintaan, lab_permintaan_detail, lab_hasil

 Live schema
 counts tables=67 views=0 columns=567 indexes=190 foreign_keys=86 checks=0
 information_schema columns=567 indexes=190 foreign_keys=86 checks=0

 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.
```

**All 6 real names are on the `scope` line.** Exit code **0**. The same run against
`telemedisin_db_test` also gives `Discrepancies: 0` with the same 6-name scope.

**3. FULL unfiltered verify — the true numbers, not the brief's.**

```
$ php artisan sehatly:verify-schema
 scope all expected tables
 Discrepancies: 25 (17 drift, 8 informational)

 FAIL — 17 discrepancies. The live schema does not match telemedicine_test.sql. Nothing was written.
```

**The brief predicted `Discrepancies: 15 (15 drift, 8 informational)`. That is
wrong, and per A.19 the true figure was derived by enumerating the rows rather than
counting from the prediction:**

| Category | Count | Which todo owns it |
| --- | --- | --- |
| `missing_table` | **15** | todo 16 → **7**; todo 17 → **8**; todo 18 → **0** |
| `missing_view` | **2** | todo 18 (`v_dokter_katalog` `:1170`, `v_pendapatan_bulanan` `:1190`) |
| `deferred_foreign_key` (informational) | 1 | todo 18 / migration 76 (`fk_vital_rm`) |
| `documented_extra_table` (informational) | 7 | none — registered extras |
| **drift** | **17** | 15 + 2 |
| **total** | **25** | |

The 15 missing tables, enumerated:
`akses_rekam_medis_log, artikel, artikel_kategori, audit_log, home_care_pesanan,
invoice, klaim_bpjs, master_metode_pembayaran, master_promo, notifikasi, pembayaran,
persetujuan_pdp, promo_redemption, refund, ulasan_dokter` — **unattributed: none.**

**Two arithmetic errors in the brief, both now corrected against measurement.** It
said "todo 16's 7, todo 17's 7, todo 18's 1". In fact **todo 17 owns 8 tables**
(contract rows 68-75, all `ORPHAN` or M5-compliance), and **todo 18 owns 0 tables** —
its rows 76-78 are a deferred `ALTER` and two views. 7 + 8 + 0 = 15. **A.9's own
table is right and the brief is wrong**: it predicts `15` missing after todo 15, `8`
after todo 16 and `0` after todo 17, which is exactly what was measured.

**No batch A-H regression** — see §9.

**4. Unit suite.**

```
 [derive] migrations=63 Schema::create calls=66 extracted=66 | CREATE VIEW calls=0 extracted=0 | contract tables=75 views=2 | derived-missing tables=15 views=2 | registry=7
{"tool":"pest","result":"passed","tests":93,"passed":93,"assertions":473,"duration_ms":6986}
```

**exit 0, 93 tests, 93 passed, 473 assertions.**
`tests/Unit/Console/VerifySchemaCommandTest.php` was **NOT edited** — confirmed by
`git status` and by the commit's path list in §12. The **derived missing count moved
21 → 15 exactly as A.9 predicts**, and the registry is still derived as **7**. Per
A.9 this suite derives rather than pins, so no edit was owed; had it pinned, this
would have been a real finding to report instead.

**5. Zero-match control.**

```
$ php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9
{"tool":"pest","result":"failed","tests":0,"passed":0,"assertions":0,"duration_ms":3,"raw":["No tests found."]}
```

**exit 1.** Zero-match measured, not assumed — the suite is not vacuously green.

**6. Table counts — measured, and the brief's 67 is right.**

```
telemedisin_db      : base tables = 67   of which contract tables = 60   extras = 7
telemedisin_db_test : base tables = 67   of which contract tables = 60   extras = 7
```

**67 per database**, decomposed by measurement rather than asserted: 60 contract
tables (migrations `000001`-`000060`) + 7 registered extras (`migrations`, `cache`,
`cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`). The
baseline before this todo was **61** = 54 contract + 7, so the delta is exactly the
six tables added. The verifier's own `Live schema` line independently reports
`tables=67`.

---

## 9. Adversarial classes

### `misleading_success_output` — APPLIES, probed

`migrate:fresh` exiting 0 and a green suite prove nothing here, and this todo proved it
rather than asserting it. Three mutations, each reverted byte-identically:

| Mutation | `php -l` | `migrate:fresh` | unit suite | `verify-schema` | Verdict |
| --- | --- | --- | --- | --- | --- |
| `lab_hasil.satuan` → `NOT NULL` | exit 0 | exit 0 | **93/93 green** | **exit 1**, `column_nullable lab_hasil.satuan expected: NULL \| actual: NOT NULL` | **caught** |
| `lab_hasil.file_pdf_url` declaration **swallowed by a `//` comment** | exit 0 | exit 0 | **93/93 green** | **exit 1**, `missing_column lab_hasil.file_pdf_url expected: varchar(500) NULL DEFAULT <none> \| actual: -` | **caught** |

Both restore steps proved byte-identity: `8D097A857AB1B25DD56174B1B4A6E55808ACD9DA60FE410AD2483656674B40E5`
before and after, with `git diff --numstat` on the file returning **empty** both
times, and `Discrepancies: 0` / exit 0 re-confirmed afterwards.

**Sibling columns confirmed untouched by the nullability probe** (required, because
flipping several at once would pass while being wrong). Every column of `lab_hasil`
compared live against the DDL under the mutation:

```
  id                   live=NOT NULL ddl=NOT NULL unchanged (agrees)
  lab_permintaan_id    live=NOT NULL ddl=NOT NULL unchanged (agrees)
  tindakan_id          live=NOT NULL ddl=NOT NULL unchanged (agrees)
  nilai                live=NOT NULL ddl=NOT NULL unchanged (agrees)
  satuan               live=NOT NULL ddl=NULL    *** THE ONE MUTATED COLUMN ***
  nilai_rujukan        live=NULL    ddl=NULL    unchanged (agrees)
  is_abnormal          live=NOT NULL ddl=NOT NULL unchanged (agrees)
  keterangan           live=NULL    ddl=NULL    unchanged (agrees)
  diperiksa_oleh       live=NULL    ddl=NULL    unchanged (agrees)
  tanggal_hasil        live=NOT NULL ddl=NOT NULL unchanged (agrees)
  file_pdf_url         live=NULL    ddl=NULL    unchanged (agrees)
  => exactly 1 column in lab_hasil disagrees with the DDL. All 10 others still agree.
```

Schema-wide, the probe was confined to exactly one column:

```
columns whose live nullability disagrees with the DDL, schema-wide: 1 -> [lab_hasil.satuan]
=> the probe was confined to ONE column. Confirmed.
```

**Batches A-H regression spot-checks** — every one compared live against the DDL, not
against a remembered value. **All pass.**

```
[PASS] master_agama.id is TINYINT UNSIGNED (type DERIVED from the DDL, not typed)  DDL=tinyint unsigned=true | live=tinyint unsigned
[PASS] master_agama.id has NO AUTO_INCREMENT                                          live EXTRA=-
[PASS] users.dihapus_at is timestamp (not datetime)                                 live COLUMN_TYPE=timestamp
[PASS] pasien.tinggi_badan_cm is decimal(5,1)                                       live COLUMN_TYPE=decimal(5,1)
[PASS] pasien.tinggi_badan_cm matches the DDL                                       DDL=decimal(5,1) live=decimal(5,1)
[PASS] dokter.durasi_default_menit is SMALLINT UNSIGNED (DERIVED)                   DDL=smallint unsigned=true | live=smallint unsigned
[PASS] dokter_faskes has NO id column                                               column count=4
[PASS] booking has NO unique spanning (dokter_id, tanggal_kunjungan, slot_mulai)    no such index among 8
[PASS] rekam_medis still has 28 columns                                             live column count=28 (DDL=28)
[PASS] rekam_medis.status_dokumen defaults to 'final'                              live COLUMN_DEFAULT='final' type=enum('draft','final','diamendemen')
[PASS] rekam_medis has `plan` and NOT `renanca`                                    DDL plan=text
[PASS] rekam_medis has `asesmen` (one s)                                            DDL asesmen=text
[PASS] apotek_stok.jumlah_stok is SIGNED int                                       live COLUMN_TYPE=int
[PASS] apotek_stok.stok_minimum is SIGNED int                                      live COLUMN_TYPE=int
[PASS] apotek_stok.uq_stok present BY NAME                                         index count on apotek_stok = 3
[PASS] uq_stok column order is (apotek_id, obat_id)                                live cols=apotek_id,obat_id
[PASS] pasien_penjamin.faskes_rujukan_id FK count is 0                             REFERENTIAL_CONSTRAINTS rows = 0
[PASS] fk_vital_rm is still ABSENT (migration 76 has not run)                      rows = 0
[PASS] dokter_pendidikan.dokter_id exists (FK chain intact)                        type=bigint unsigned
REGRESSION SPOT-CHECK RESULT: ALL PASS
```

A.10/A.11's invariant holds: `pasien_penjamin.faskes_rujukan_id` still carries **0**
foreign keys, and `fk_vital_rm` is still **absent** — so this batch's `down()` /
`up()` cycle and the deferred-FK registry are untouched. Also confirmed, because
todo 11's executor disclosed a `dokter_perunjuk_id` misspelling in a *comment* while
the code was right: **no identifier in any of the six files is absent from the DDL**,
which is precisely the check that would catch that class (§5, §7).

### `stale_state` — APPLIES, checked

- `bootstrap/cache/config.php`: **absent** before, after `php artisan config:clear`
  (exit 0), and at the end.
- Sort order confirmed: six files after `000054`, `000055` lowest.
- **The TEST database was migrated** (`migrate:fresh` with
  `$env:DB_DATABASE='telemedisin_db_test'`, exit 0) and a scoped verify was run
  **against it** — `live database mysql / telemedisin_db_test`, `Discrepancies: 0`.
  The Unit suite reads the live test database and does not migrate, so this mattered.
- No spurious-NULL `information_schema` read was observed. Every column read came
  back populated, and where a NULL was possible (`COLUMN_DEFAULT`) the schema was
  named explicitly (`TABLE_SCHEMA = ?` with the connection's database) and the value
  cross-read against `SHOW CREATE TABLE`. The `DEFAULT_GENERATED` value on
  `dibuat_at`/`diubah_at` was recognised as MySQL 8's real form, not treated as a
  missing default.

### `dirty_worktree` — APPLIES, checked

`git status --porcelain` at the start showed only orchestrator-owned entries:
` M .omo/plans/sehatly-telemedicine-platform.md` and two untracked paths
(`.omo/evidence/task-3-sehatly.md`, `.omo/start-work/`). **All three were left
untouched** and none appears in the commit. `git show --name-only --format="" HEAD`
lists only this todo's 8 paths (§12). No `-A`, no `.`, no `-a`, no `-u`, no `stash`,
no `checkout .`, no `restore .`, no `clean`, no `reset`, no `--amend`, no `push`, and
no commit to `main`.

### `hung_or_long_commands` — APPLIES, controlled

Every `migrate:fresh`, `migrate`, `migrate:rollback` and test run was executed inside a
`Start-Job` with an explicit `Wait-Job -Timeout 240` (or 400) and the **observed exit
code read from the job's output**, never inferred. Each completed well inside the
timeout. The pre-existing `mysqld` was **not** killed, and the user's
`php artisan serve` (PID 22288) was **not** touched — no process was terminated at
any point in this todo. One `Start-Process -PassThru` invocation returned an empty
`.ExitCode` (a .NET handle-caching quirk); that was **not** treated as success — the
command was re-run inside a job and the real exit code captured.

### `repeated_interruptions` — APPLIES, convergence proven

Schema fingerprint = every `table.column:type:nullability` triple except the
`migrations` ledger, hashed.

```
fingerprint A : tables(minus migrations)=66  column fingerprints=564
               sha256 = 79faf0ff89500c464e3ae9ad5b74dd0463bc0dc74185e26e417a5be32a5d02a6
migrate:fresh (exit 0)
fingerprint B : tables(minus migrations)=66  column fingerprints=564
               sha256 = 79faf0ff89500c464e3ae9ad5b74dd0463bc0dc74185e26e417a5be32a5d02a6
CONVERGENT (identical fingerprints) = True
```

**Rollback cycle — children before parents, confirmed from the migrator's own output:**

```
$ php artisan migrate:rollback --step=6
 2026_10_01_000060_lab_hasil_table .. 17.45ms DONE
 2026_10_01_000059_lab_permintaan_detail_table .. 15.55ms DONE
 2026_10_01_000058_lab_permintaan_table .. 18.18ms DONE
 2026_10_01_000057_lab_paket_item_table .. 12.52ms DONE
 2026_10_01_000056_master_lab_paket_table .. 9.22ms DONE
 2026_10_01_000055_master_lab_tindakan_table .. 10.29ms DONE
rollback exit = 0
```

All six gone; base tables 67 → **61**; ledger 57 rows. The order is the proof that
children roll back first: `lab_hasil` (60) and `lab_permintaan_detail` (59), which
carry FKs to `lab_permintaan`, are dropped **before** `lab_permintaan` (58), which is
before `lab_paket_item` (57), before its two parents (56, 55). `migrate` then re-applied
all six, **exit 0**.

### Ruled out with a one-line reason

- **`malformed_input`** — no parser was authored; the only parsing was the project's
  own `SqlSchemaParser`, which was **not** modified and which fails loudly (exit 2) on
  malformed DDL by design.
- **`prompt_injection`** — `telemedicine_test.sql` is first-party DDL read strictly as
  a specification. It carries **39 `COMMENT` literals** of ordinary Indonesian domain
  text (e.g. `'E-resep berlaku 7 hari'` `:755`, `'Apotek penuh (faskes tipe apotek)'`
  `:749`, `'Polimorfik'` `:941`, `'Reviewer medis (revisi medis)'` `:1078`) and
  **none is an instruction**. `SqlSchemaParser::collectInlineConstraints()` explicitly
  skips `COMMENT` payloads so their text is never scanned for keywords. **Nothing
  resembling an instruction was found and nothing was acted on.** One
  near-instruction-shaped string exists — `telemedicine_test.sql:538` carries the
  literal `'NULL = ...'` inside a `COMMENT` — and it is data; the parser was already
  hardened against it by todo 6.
- **`cancel_resume`** — no resumable user flow; this todo's work is a single
  non-interactive commit with no partial state to resume.
- **`flaky_tests`** — deterministic. The suite is 93/93 on **five** separate
  invocations in this todo (baseline, under each of the two mutations, after the
  `schema-notes.md` append, and after restore), and the zero-match control
  deterministically returns exit 1. No retries, no ordering dependence, no shared
  state.

---

## 10. Deferred-constraint position

**This batch owes no deferred constraint. `fk_vital_rm` remains the only one, and it
belongs to migration 76.**

All **10** of batch I's foreign keys point at a table that already exists when the
migration declaring it runs: `pasien` 20 (batch C), `faskes` 28 and `dokter` 31 (batch
D), and `master_lab_tindakan` 55, `master_lab_paket` 56 and `lab_permintaan` 58 —
**three of them from inside this same batch, earlier in the same commit.** The
*Deferred constraints* registry in `docs/schema-notes.md` is **unchanged and still holds
exactly one row**, confirmed live: the verifier prints
`notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred constraint)`
and `information_schema.REFERENTIAL_CONSTRAINTS` returns **0** rows for
`fk_vital_rm`.

**`fk_vital_rm` was not added, not registered, and not touched.** No new registry row
was created for any column in this batch, and in particular **not** for the three bare
columns — a registry row would promise a constraint the DDL never declares, which is
the exact mistake A.11 killed for `pasien_penjamin.faskes_rujukan_id`.

---

## 11. The swallowed-statement probe (RULE 1b, run live)

`lab_hasil.file_pdf_url`'s declaration was commented out with `//`, reproducing the
defect class todo 14 disclosed about itself.

```
sha256 BEFORE = 8D097A857AB1B25DD56174B1B4A6E55808ACD9DA60FE410AD2483656674B40E5
line 232: // $table->string('file_pdf_url', 500)->nullable();   <-- SWALLOWED BY THE COMMENT
sha256 AFTER  = 03D2A3BCA25687BD2773E6519CA824C16E4F38ED8A8D9E5D1500AFA0F50C91D2
```

| Check | Predicted | Observed |
| --- | --- | --- |
| `php -l` | **exit 0** | **exit 0** — `No syntax errors detected` |
| `php artisan migrate:fresh` | **exit 0** | **exit 0** — `2026_10_01_000060_lab_hasil_table .. 150.43ms DONE` |
| `php artisan test tests/Unit` | green | **`{"tests":93,"passed":93,"assertions":473}`** |
| static executable-statement count | 10 vs 11 | **10 vs 11 → MISMATCH detected** |
| `sehatly:verify-schema` | **exit 1** | **exit 1** — `missing_column lab_hasil.file_pdf_url expected: varchar(500) NULL DEFAULT <none> \| actual: -` |
| live column count | 567 → 566 | `counts tables=67 … columns=566` (both routes agreed) |

Restored byte-identically (`8D097A85…B40E5`, `git diff` empty), `migrate:fresh` exit 0,
`Discrepancies: 0`, and the static audit back to `ALL FILES MATCH THE SQL`.

### What this quantifies

**In this project, a green build, a green `migrate:fresh` and a 93/93 green test
suite together detect a missing column ZERO percent of the time.** All three were
green over the swallowed declaration. The parity verifier is the only check in the
loop that sees a column that is *absent*, and a comment-stripping static count catches
it too, without a database round-trip. A comment audit is the only kind of check that
sees a comment that is *wrong* — which is why the three trap comments and the
`DEFAULT 1` / `DEFAULT 0` polarity note are written into the files themselves and not
only into this evidence file.

---

## 12. Commit

`vendor/bin/pint` was run **bare, with no path argument** (A.7: naming `bootstrap`
explicitly would override pint's exclude). Pint exit **0**; it reformatted only this
executor's own new files, before staging.

```
$ git add -- database/migrations docs/schema-notes.md .omo/evidence/task-15-sehatly.md
$ git commit -m "feat(db): migrate laboratory tables" -- database/migrations docs/schema-notes.md .omo/evidence/task-15-sehatly.md
```

Post-commit assertions:

- `git diff --cached --name-only` → **empty** (nothing left staged).
- `git show --name-only --format="" HEAD` → **only** this todo's paths:
  `database/migrations/2026_10_01_000055_master_lab_tindakan_table.php`,
  `database/migrations/2026_10_01_000056_master_lab_paket_table.php`,
  `database/migrations/2026_10_01_000057_lab_paket_item_table.php`,
  `database/migrations/2026_10_01_000058_lab_permintaan_table.php`,
  `database/migrations/2026_10_01_000059_lab_permintaan_detail_table.php`,
  `database/migrations/2026_10_01_000060_lab_hasil_table.php`,
  `docs/schema-notes.md`, `.omo/evidence/task-15-sehatly.md` — **8 paths, 6 added, 2
  added/modified, 0 deletions.**
- `git status --porcelain` still shows only the three orchestrator-owned entries.

---

## 13. Data safety

**`telemedicine_test.sql`: unchanged.** SHA-256
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` verified on disk
at the start and again after the commit; 59,604 bytes; never opened for writing.

| Database | Before | After | Domain rows |
| --- | --- | --- | --- |
| `telemedisin_db` | 61 tables, ledger 57 rows | **67 tables**, ledger 63 rows | **0** — the only non-empty table is `migrations` |
| `telemedisin_db_test` | 61 tables, ledger 57 rows | **67 tables**, ledger 63 rows | **0** — the only non-empty table is `migrations` |
| `sehatly` | 10 tables, ledger 5 rows | **10 tables, ledger 5 rows** | unchanged; 1 `sessions` row, as before |
| `db_simprapkl` | 26 tables, 111 rows | 26 tables, 111 rows | untouched |
| `gawaiseken` | 18 tables, 81 rows | 18 tables, 81 rows | untouched |
| `laravel` | 5 tables, 4 rows | 5 tables, 4 rows | untouched |
| `manajemen-surat` | 0 tables | 0 tables | untouched |
| `trading_journal` | 12 tables, 22 rows | 12 tables, 22 rows | untouched |
| `ukk` | 12 tables, 17 rows | 12 tables, 17 rows | untouched |
| `ukk_pengaduan_sekolah` | 15 tables, 41 rows | 15 tables, 41 rows | untouched |

`sehatly`'s five original migration rows were read back individually and are unchanged:
`0001_01_01_000000_create_users_table`, `0001_01_01_000001_create_cache_table`,
`0001_01_01_000002_create_jobs_table`, `2024_01_01_000000_create_passkeys_table`,
`2025_08_14_170933_add_two_factor_columns_to_users_table` — including the **passkeys**
row todo 7 deleted from `database/migrations/`, which is correct: `sehatly` is never
migrated by this project and its own ledger is untouched.

**Probe rows: none were ever created.** Every QA mutation in §9 and §11 was a
**source-file** mutation followed by a `migrate:fresh`, so no probe ever reached a
table. `migrate:fresh` drops and recreates every table, so even a hypothetical row
could not have survived. The row census in the table above is the proof: the **only**
non-empty tables in either project database are the `migrations` ledgers. No
transaction-and-rollback was needed, because nothing was written.

**No file was deleted.** `git show --stat HEAD` reports 0 deletions, and
`git status` shows no unexpected file. Todo 14's executor disclosed deleting a file it
had not created; this todo created six files, modified one, created this evidence file,
and deleted **nothing**.

---

## 14. Cleanup receipts

- All scratch files live in `%TEMP%\opencode\t15\` and `%TEMP%\opencode\` — **never in
  the repository**. `git status --porcelain` shows no untracked file inside the repo
  other than the two orchestrator-owned ones that were already there.
- `bootstrap/cache/config.php`: **absent** (verified after the final `config:clear`).
- **No leftover process.** Every long-running command ran inside a `Start-Job` and
  every job was `Remove-Job -Force`d in the same statement that read its output. The
  pre-existing `mysqld` and the user's `php artisan serve` (PID 22288) were never
  stopped.

---

## 15. Disclosures

**Things I got wrong and corrected**

1. **Five identifier corruptions introduced during authoring, all caught before
   commit**: `tandidato_id` and `` `tSO NULL` `` in two docblocks, and
   `lab_paket_item_tendency_id_foreign`, `` `t indulge_id` ``, `` `t sneaky_id` `` and
   `puskes_assignment` in `docs/schema-notes.md`. Fixed and re-verified individually
   (§7). The first two were caught by reading the file back immediately after writing
   it; the last four by the token audit.
2. **One attribution inaccuracy in a comment**, caught by the citation pass and fixed
   before commit: `000060` quoted the plan's rationale for `audit_log.user_id` as
   though `:1120` carried it. `:1120` is `user_id BIGINT UNSIGNED NULL` with no
   `COMMENT`. The comment now says the rationale is the plan's.
3. **Two non-English illustrative words replaced** (`Reaktif`, `Tidak Cedera` →
   `positive`, `not detected`) so prose cannot be mistaken for a DDL transcription.
4. **Five further corruptions introduced into THIS evidence file while writing it**,
   and caught by the same token audit before the commit: `master_lab_tindraND` (×2),
   `master_lab_tindsight`, `master_lab_tindicated` — all three manglings of
   `master_lab_tindakan` — plus `konsulang.status` for `konsultasi.status` and
   `invoice.re|English_tipe` for `invoice.referensi_tipe`. All five are now 0
   occurrences and the correct spellings are verified present. **This is disclosed
   rather than quietly repaired because it is the sharpest available demonstration of
   the point this todo set out to prove:** the same corruption class that
   `migrate:fresh`, `php -l` and a 93/93 suite all stayed green through — it is
   invisible to every check in this project except a token audit that compares each
   identifier against the DDL, and it was introduced *into the very document
   reporting on it*, after the report had already been written and verified once.

**Auditors of mine that cried wolf — five, all diagnosed in place before reporting,
per A.18 and RULE 4**

1. **Corruption scanner v1** flagged all six files for "non-ASCII > 0". Read in place:
   the only code points are 94 em-dashes and 3 ellipses, and the pre-existing committed
   `000054` has 15 em-dashes, so this is house style, not corruption. Threshold was
   wrong, not the files.
2. **Corruption scanner v1** also flagged 51 "unknown identifiers". Read in place:
   verifier discrepancy kinds (`column_type`, `extra_foreign_key`, …), Laravel's
   default timestamp names the files deliberately name as what *not* to emit, MySQL's
   `information_schema`, the reference filename, and `uq_kode` / `urutan` / `qty` —
   all mentioned **because they are absent**. Naive allow-list, not a finding.
3. **Corruption scanner v2** flagged `telemedicine_test.sql` and
   `information_schema.referential_constraints` as unresolvable `table.column` refs,
   and mis-counted columns as 4/14/8/13 instead of 2/11/5/11. Diagnosed: my regex
   matched the filename as a `table.column` pair, and its column regex did not exclude
   constraint calls. Both fixed; the corrected count **cross-validated RULE 1b exactly**.
4. **Index auditor v1** printed `unique=no` for `PRIMARY` and for both
   `*_unique` indexes. Diagnosed: `information_schema.STATISTICS.NON_UNIQUE` returns an
   int and I compared with `=== '0'`. Re-ran with an int cast — `NON_UNIQUE=0`,
   `unique=YES` for all three. (This one failed in the *safe* direction, but it would
   have made the by-name/by-semantics evidence in §2 unreadable, so it was fixed
   rather than excused.)
5. **Regression spot-checker v1** reported 2 failures, then 1. Diagnosed: I had
   **hand-typed** the expected literals `tinyint(3) unsigned` and `smallint(5)
   unsigned` — a direct **RULE 3** violation on my part — and MySQL 8 emits no display
   width. The second round still failed because I then wrote
   `!str_contains($id->ty, 'signed')`, and **`'tinyint unsigned'` contains the substring
   `'signed'`**. Both bugs proven in place:
   `str_contains('tinyint unsigned','signed') === true`, while
   `str_ends_with(trim('tinyint unsigned'),'unsigned') === true`. Expected values are
   now **derived from the parser's model of the DDL**, never typed.

Also disclosed: **two scratch scripts of mine had PHP syntax errors** — `.$($x ? a : b)`
(`$(` is valid only inside string interpolation, not in code) and assigning to
`($cond ? $a : $b)[]` (cannot assign to a temporary expression). Both were in my own
audit tooling under `%TEMP%`, never in a committed file, and both were found by
`php -l` on the scratch script itself.

**Could not verify**

- Whether `NOT NULL DEFAULT_GENERATED` on a `DATETIME` would fold the same way it does
  on a `TIMESTAMP` — **no `DATETIME` in batch I carries a `CURRENT_TIMESTAMP` default**,
  so the question does not arise here. It may in a later batch.
- Whether any of the **39 `COMMENT` literals** in the SQL encode a *semantic*
  instruction this schema under-implements. That is a design question outside a
  migration todo, and none of them is an instruction in the prompt-injection sense.
- The plan's own line-index table has **no row for any of the six laboratory tables**,
  so there was nothing in it to cross-check my `:NNN`s against. Every citation was
  resolved from the SQL directly instead — which is the A.16 procedure, but it means
  this batch had **no** independent second source at all.

**Found but deliberately NOT fixed**

- **The plan's "Multi-line ENUMs — treat as single units" list is five entries short.**
  Measured: **eleven** declarations span more than one physical line —
  `booking.status` (515-516), `konsultasi.status` (542-543),
  `konsultasi_chat.tipe_pesan` (568-569), `master_obat.bentuk_sediaan` (713-714),
  `resep.status` (751-752), `pesanan_obat.status` (810-811),
  **`lab_permintaan.status` (884-885)**, `invoice.status` (947-948),
  `klaim_bpjs.status` (1022-1023), `home_care_pesanan.status` (1104-1105),
  `persetujuan_pdp.jenis` (1137-1138). The plan's list and **rule 6 of
  `docs/migration-order.md`** both carry the same six, and todo 14's entry in
  `schema-notes.md` already added a seventh while asserting the list was otherwise
  complete. **Not edited**: `.omo/plans/` is orchestrator-owned and under active edit,
  and `docs/migration-order.md` is not in this todo's commit pathspec. Both are
  **reported** instead, and the finding is recorded in `schema-notes.md`.
- **The plan's todo-15 prose cites `invoice.referensi_tipe` at `:941`; it is at
  `:940`** (`:941` is `referensi_id`). Seventh consecutive batch with a wrong inline
  citation. Not edited — same reason. The migration cites `:940` and says so.
- **`000041_rujukan_table.php:93-96`** still claims `diagnosis_kerja` is repeated on
  `surat_keterangan`, which has no such column — a pre-existing defect todo 13
  deliberately declined to fix because widening the commit scope would have broken the
  "only your paths" assertion. Left for its own dispatch; touching it here would repeat
  that mistake.
- **`000027_pasien_penjamin_table.php`'s docblock** was corrected by A.11 and is
  correct; nothing outstanding there.

**Silent acceptance avoided:** every claim above that could have been wrong was opened
and read in place with its subject attached before being reported, and five of my own
checks were withdrawn after diagnosis rather than shipped as findings.
