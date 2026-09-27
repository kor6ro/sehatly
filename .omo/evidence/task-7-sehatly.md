# Task 7 evidence — migration order contract + migration batch A (master data, SQL tables 1-11)

Plan: `.omo/plans/sehatly-telemedicine-platform.md`, todo 7 (lines 298-304).
Branch: `feat/sehatly-telemedicine`.
PHP: `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` = **8.4.17** (project-local;
bare `php` on `PATH` is 8.2.29 and fails Laravel's `^8.3` platform check).
MySQL: **8.0.30**, connection `telemedisin_db` (dev) from `.env`.

Every command below was run with the project-local PHP first on `PATH`:

```
$env:PATH = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64;$env:PATH"
```

---

## 0. Headline: one criterion is green, one is not, and the reason is not a migration

| Acceptance criterion | Result |
| --- | --- |
| 1. `docs/migration-order.md`: 75 data rows + 3 post-table rows, line numbers match `^CREATE TABLE` | **PASS** — 75/75, positional diff EMPTY (section 2) |
| 2. `php artisan migrate:fresh` on an empty MySQL 8 database exits 0 | **PASS** — exit 0 (section 3) |
| 3. `php artisan migrate:rollback --step=11` then `php artisan migrate` exits 0 | **PASS** — exit 0 / exit 0 (section 4) |
| 4. `verify-schema --tables=master_provinsi,master_agama,master_icd10` exits 0 | **PASS** — exit 0, `Discrepancies: 0` (section 6) |
| 5. A.4 scaffold disposition complete | **PASS, with a forced third deletion** — 3 migrations deleted, not 2 (section 5) |
| 6. Commit contains only the intended paths | **PASS** — 13 `A` + 3 `D` + 1 `M` = 17 files changed (section 11) |
| Manual-QA happy path over **all 11** batch-A tables, `Discrepancies: 0` | **FAIL, exit 1, 3 discrepancies — a verifier defect, not schema drift** (section 7) |

The one red is worth reading before anything else: **`master_kabupaten_kota`,
`master_kecamatan` and `master_kelurahan` cannot reach `Discrepancies: 0` under todo 6's
verifier, no matter how they are written.** Section 7 proves it is the verifier and not
the migrations. The plan's own criterion 4 is green because it deliberately names the
three batch-A tables that have no foreign key.

---

## 1. Starting state (measured, not assumed)

```
$ php artisan tinker … / information_schema
telemedisin_db tables=0
telemedisin_db_test tables=0
sehatly tables=10
version=8.0.30
default db=telemedisin_db
sehatly tables: cache, cache_locks, failed_jobs, job_batches, jobs, migrations, passkeys, password_reset_tokens, sessions, users
```

Migrations on disk before this todo (6 files):

```
0001_01_01_000000_create_users_table.php
0001_01_01_000001_create_cache_table.php
0001_01_01_000002_create_jobs_table.php
2024_01_01_000000_create_passkeys_table.php
2025_08_14_170933_add_two_factor_columns_to_users_table.php
2026_09_26_222801_create_personal_access_tokens_table.php
```

---

## 2. Criterion 1 — the order contract matches the SQL, proved mechanically

The check compares the three contract columns (`#`, `SQL line`, `Table`) of every markdown
row against a `^CREATE TABLE` walk of `telemedicine_test.sql`, **positionally** — a 75/75
match with an empty diff, not an eyeball comparison. The same script also asserts that each
row's `Migration filename` cell equals `2026_10_01_<zero-padded row>_<table>_table.php`.

```
$ php sn-05-ordercheck.php
reference CREATE TABLE count : 75
contract data rows          : 75
contract post-table rows    : 3

positional diff (line number + table name, in order): EMPTY
filename column check: all 75 filenames match their row number and table name
EXIT=0
```

Cross-checked with the plan's own shell idiom, which also reports 75:

```
$ (Select-String -Path telemedicine_test.sql -Pattern '^CREATE TABLE').Count
75
$ Select-String -Path telemedicine_test.sql -Pattern '^CREATE TABLE' | Select-Object -First 5
58 CREATE TABLE master_provinsi (
64 CREATE TABLE master_kabupaten_kota (
72 CREATE TABLE master_kecamatan (
80 CREATE TABLE master_kelurahan (
89 CREATE TABLE master_agama (
$ … | Select-Object -Last 3
1118 CREATE TABLE audit_log (
1134 CREATE TABLE persetujuan_pdp (
1147 CREATE TABLE akses_rekam_medis_log (
```

### `docs/migration-order.md` structure

```
| # | SQL line | Table | Migration filename | Batch (todo) | Module | Model | Resource | Controller |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 58 | `master_provinsi` | `2026_10_01_000001_master_provinsi_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 2 | 64 | `master_kabupaten_kota` | `2026_10_01_000002_master_kabupaten_kota_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 3 | 72 | `master_kecamatan` | `2026_10_01_000003_master_kecamatan_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 4 | 80 | `master_kelurahan` | `2026_10_01_000004_master_kelurahan_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 5 | 89 | `master_agama` | `2026_10_01_000005_master_agama_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| …                                                                                     |
| 75 | 1147 | `akses_rekam_medis_log` | `2026_10_01_000075_akses_rekam_medis_log_table.php` | K (17) | M5 compliance | 19 | — | — |

| # | SQL line | Object | Migration filename | Todo | What it does |
| 76 | 1161 | `fk_vital_rm` (deferred FK) | `2026_10_01_000076_add_deferred_foreign_keys_table.php` | 18 | … |
| 77 | 1170 | `v_dokter_katalog` (view) | `2026_10_01_000077_create_v_dokter_katalog_view_table.php` | 18 | … |
| 78 | 1190 | `v_pendapatan_bulanan` (view) | `2026_10_01_000078_create_v_pendapatan_bulanan_view_table.php` | 18 | … |
```

The contract also records, for every later batch author: the filename pattern, the
non-renumbering rule, the 14 module-orphaned tables, and twelve parity rules (§"Parity
rules that apply to every row above").

### Correction to the plan's schema-reality list, measured

The plan states "28 tables have neither `dibuat_at` nor `diubah_at`" and "18 tables have
`dibuat_at` only". Both are wrong. Counted off the reference DDL with the plan's own
`App\Support\Schema\SqlSchemaParser`:

| Shape | Measured | Plan says |
| --- | --- | --- |
| neither `dibuat_at` nor `diubah_at` | **39** | 28 (and its list has 29 entries) |
| `dibuat_at` only | **19** | 18 (`audit_log` omitted) |
| `diubah_at` only | **1** (`apotek_stok`) | 1 |
| both | **16** | not stated |

The plan's "neither" list wrongly includes `apotek_stok` (which has `diubah_at` at `:837`)
and omits `master_provinsi`, `master_kabupaten_kota`, `master_kecamatan`, `master_kelurahan`,
`master_penjamin`, `master_spesialisasi`, `master_metode_pembayaran`, `master_promo`,
`persetujuan_pdp` and `artikel_kategori`. **Todo 19 has an acceptance criterion asserting
`$timestamps === false` for "all 28 tables in the 'neither' list"; it must assert 39, or it
will silently under-test 11 tables.** Recorded in `docs/migration-order.md` and
`docs/schema-notes.md`.

**Consequence for batch A: none of the 11 tables has any timestamp column, so
`$table->timestamps()` is applied zero times and the mandatory raw
`ON UPDATE CURRENT_TIMESTAMP` `ALTER` is applied zero times.** The rule is documented in the
contract for the 16 tables that do need it; the first batch author who hits one is todo 10
(`faskes`) or todo 9 (`pasien`).

---

## 3. Criterion 2 — `migrate:fresh` exits 0

```
$ php artisan config:clear
 INFO Configuration cache cleared successfully.
EXIT=0
$ Test-Path bootstrap\cache\config.php
False

$ php artisan migrate:fresh

 Dropping all tables .. 34.30ms DONE

 INFO Preparing database.
 Creating migration table .. 19.26ms DONE

 INFO Running migrations.
 0001_01_01_000001_create_cache_table .. 58.65ms DONE
 0001_01_01_000002_create_jobs_table .. 146.04ms DONE
 2026_09_26_222801_create_personal_access_tokens_table .. 69.03ms DONE
 2026_10_01_000001_master_provinsi_table .. 30.93ms DONE
 2026_10_01_000002_master_kabupaten_kota_table .. 72.34ms DONE
 2026_10_01_000003_master_kecamatan_table .. 60.09ms DONE
 2026_10_01_000004_master_kelurahan_table .. 79.06ms DONE
 2026_10_01_000005_master_agama_table .. 13.89ms DONE
 2026_10_01_000006_master_golongan_darah_table .. 15.91ms DONE
 2026_10_01_000007_master_pendidikan_table .. 15.00ms DONE
 2026_10_01_000008_master_status_pernikahan_table .. 19.03ms DONE
 2026_10_01_000009_master_hubungan_keluarga_table .. 13.81ms DONE
 2026_10_01_000010_master_icd10_table .. 53.10ms DONE
 2026_10_01_000011_master_icd9cm_table .. 23.93ms DONE

EXIT=0
```

Ordering, straight from the migrator's own output: the surviving scaffolds
(`0001_01_01_000001`, `0001_01_01_000002`, `2026_09_26_222801`) all run **before**
`2026_10_01_000001`, exactly as `docs/migration-order.md` claims, and the 11 files run in
index order. No foreign key is emitted before its parent exists.

### The first `migrate:fresh` did NOT pass — a collision A.4 did not cover

The first run, with only the two A.4-named deletions applied, failed:

```
 2024_01_01_000000_create_passkeys_table .. 561.59ms FAIL

   Illuminate\Database\QueryException
  SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'users'
  (Connection: mysql, …, Database: telemedisin_db, SQL: alter table `passkeys` add constraint
  `passkeys_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade)

migrate:fresh EXIT=1
```

`2024_01_01_000000_create_passkeys_table.php:16` declares
`$table->foreignId('user_id')->constrained()->cascadeOnDelete()`. Once the scaffold `users`
migration is gone, `users` does not exist until todo 8, and the FK cannot resolve — so
**every** `migrate:fresh` between now and todo 8 fails, i.e. ten todos (8-17) lose their own
acceptance criterion for a reason that has nothing to do with their schema. A.4 anticipated
this class ("do not leave it implicit") and left the decision to todo 7; the decision is
therefore forced rather than chosen. See section 5.

---

## 4. Criterion 3 — `rollback --step=11` then `migrate`, both exit 0

```
$ php artisan migrate:rollback --step=11

 INFO Rolling back migrations.

 2026_10_01_000011_master_icd9cm_table .. 11.69ms DONE
 2026_10_01_000010_master_icd10_table .. 5.72ms DONE
 2026_10_01_000009_master_hubungan_keluarga_table .. 5.35ms DONE
 2026_10_01_000008_master_status_pernikahan_table .. 7.10ms DONE
 2026_10_01_000007_master_pendidikan_table .. 6.51ms DONE
 2026_10_01_000006_master_golongan_darah_table .. 6.25ms DONE
 2026_10_01_000005_master_agama_table .. 6.73ms DONE
 2026_10_01_000004_master_kelurahan_table .. 9.26ms DONE
 2026_10_01_000003_master_kecamatan_table .. 8.62ms DONE
 2026_10_01_000002_master_kabupaten_kota_table .. 6.85ms DONE
 2026_10_01_000001_master_provinsi_table .. 5.35ms DONE

EXIT=0

$ php artisan migrate

 INFO Running migrations.

 2026_10_01_000001_master_provinsi_table .. 28.98ms DONE
 2026_10_01_000002_master_kabupaten_kota_table .. 72.40ms DONE
 2026_10_01_000003_master_kecamatan_table .. 68.58ms DONE
 2026_10_01_000004_master_kelurahan_table .. 73.30ms DONE
 2026_10_01_000005_master_agama_table .. 13.86ms DONE
 2026_10_01_000006_master_golongan_darah_table .. 14.07ms DONE
 2026_10_01_000007_master_pendidikan_table .. 13.59ms DONE
 2026_10_01_000008_master_status_pernikahan_table .. 13.94ms DONE
 2026_10_01_000009_master_hubungan_keluarga_table .. 13.00ms DONE
 2026_10_01_000010_master_icd10_table .. 54.27ms DONE
 2026_10_01_000011_master_icd9cm_table .. 25.39ms DONE

EXIT=0
```

The rollback drops in exact reverse creation order, so the three child tables
(`master_kelurahan` → `master_kecamatan` → `master_kabupaten_kota` → `master_provinsi`) go
before their parents and no `down()` hits a dependency. Every `down()` is
`Schema::dropIfExists(<own table>)`; none of the 11 owns a FK on another of the 11 except
the three wilayah parent references, which is why the order is sufficient.

**Resume/idempotence proof:** the `information_schema` dump (section 6b) taken after
`migrate:fresh` and again after this rollback+migrate cycle is **byte-identical**:

```
$ Compare-Object out-infschema.txt out-infschema2.txt
IDENTICAL: the rollback+migrate cycle converged to a byte-identical information_schema dump
```

---

## 5. A.4 scaffold disposition — three deletions, and why the third was forced

| Path | Action | Reason |
| --- | --- | --- |
| `database/migrations/0001_01_01_000000_create_users_table.php` | **deleted** | Creates `users`, which **collides** with SQL table 12 (`:132`). Filename order means `0001_…` runs first and todo 8's `2026_10_01_000012_users_table.php` would die with `SQLSTATE 42S01 Table 'users' already exists`. Also created `password_reset_tokens` and `sessions`. |
| `database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php` | **deleted** | **Unrepresentable**: `->after('password')` cannot resolve — `telemedicine_test.sql:138` has `kata_sandi_hash` and no `password`. TOTP is not in the 75-table schema; auth is OTP (`user_otp`, `:179`) + Sanctum. |
| `database/migrations/2024_01_01_000000_create_passkeys_table.php` | **deleted** (A.4's open decision) | Its `foreignId('user_id')->constrained()->cascadeOnDelete()` cannot resolve once the scaffold `users` migration is gone — section 3 shows the hard failure. A.4's two options ("keep and document", "drop together with Fortify in todo 30") are both unexecutable: keeping it makes `migrate:fresh` red for todos 7-17, and todo 30 is after all of them. |

`password_reset_tokens` and `sessions` therefore **do not exist** and are **not** in the
registry. The sanctioned knock-on transient is recorded in `docs/schema-notes.md`:
`config/session.php` and `app/Providers/FortifyServiceProvider.php` still reference sessions
until todo 30 removes the Inertia/Fortify surface. `config/fortify.php:123-156` and
`FortifyServiceProvider.php:109-115` still hold their `passkeys` feature block; nothing
queries the table at boot, and `laravel/passkeys` stays in `composer.json` (removing a
package is not this todo's business), so no `/api/v1` route is affected. Todo 19 already
instructs the model rewrite to drop `PasskeyUser` / `PasskeyAuthenticatable`.

### Registry enforcement contract, proved in both directions

`docs/schema-notes.md` was regenerated in this commit. Live check: **7 registered extras,
0 undocumented**.

Documented extras (all informational, none drift):

```
 documented_extra_table cache_locks
 documented_extra_table failed_jobs
 documented_extra_table job_batches
 documented_extra_table migrations
 documented_extra_table personal_access_tokens
 documented_extra_table cache
 documented_extra_table jobs
```

To prove the *other* direction without touching the committed file, the verifier was run
against a scratch registry (outside the repo, since deleted) that omitted `cache` and
`jobs`:

```
$ php artisan sehatly:verify-schema --notes=<scratch>            # unfiltered
 Discrepancies: 76 (71 drift, 5 informational)
 undocumented_extra_table cache expected: no entry in docs/schema-notes.md | actual: present in the live schema
 undocumented_extra_table jobs expected: no entry in docs/schema-notes.md | actual: present in the live schema
 FAIL — 71 discrepancies.
EXIT=1
```

And a missing registry is still exit 2, never a silent pass:

```
$ php artisan sehatly:verify-schema --notes=<nonexistent>
 ERROR verify-schema could not run: The extra-table registry is mandatory but was not found at …
EXIT=2
```

### A.7's hard dependency is discharged

Deleting the two-factor migration is what makes todo 18's green reachable: the three
`users.two_factor_*` columns would otherwise be reported as `extra_column` **drift**
permanently, because there is no column-level forgiveness. The "Registered extra columns"
section is gone from `docs/schema-notes.md`, with a note recording why it is gone so the
columns are not re-introduced by a later scaffold.

---

## 6. Criterion 4 + the manual-QA happy path

### 6a. `verify-schema --tables=master_provinsi,master_agama,master_icd10` — exit 0

```
$ php artisan sehatly:verify-schema --tables=master_provinsi,master_agama,master_icd10

 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables)
 scope master_provinsi, master_agama, master_icd10

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: booking.status (515-516), home_care_pesanan.status (1104-1105), invoice.status (947-948), klaim_bpjs.status (1022-1023), konsultasi.status (542-543), konsultasi_chat.tipe_pesan (568-569), lab_permintaan.status (884-885), master_obat.bentuk_sediaan (713-714), persetujuan_pdp.jenis (1137-1138), pesanan_obat.status (810-811), resep.status (751-752)
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on pasien_tanda_vital (line 1161)

 Live schema
 counts tables=18 views=0 columns=74 indexes=36 foreign_keys=3 checks=0
 information_schema columns=74 indexes=36 foreign_keys=3 checks=0

 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.

 PASS — 75 tables, 2 views verified. Nothing was written.

EXIT=0
```

**A.7 trap, observed live:** the banner says "**75 tables, 2 views verified**" although
`scope master_provinsi, master_agama, master_icd10` — three tables were verified. The banner
is formatted from the full reference model. The truthful signals are `Discrepancies: 0` and
`exit 0`, and both are green.

### 6b. `verify-schema --tables=<all 11>` — exit 1, 3 discrepancies, all one known defect

```
$ php artisan sehatly:verify-schema --tables=master_provinsi,master_kabupaten_kota,master_kecamatan,master_kelurahan,master_agama,master_golongan_darah,master_pendidikan,master_status_pernikahan,master_hubungan_keluarga,master_icd10,master_icd9cm

 …
 Discrepancies: 3 (3 drift, 0 informational)
 extra_index master_kabupaten_kota expected: - | actual: master_kabupaten_kota_provinsi_id_foreign INDEX (provinsi_id)
 extra_index master_kecamatan expected: - | actual: master_kecamatan_kabupaten_kota_id_foreign INDEX (kabupaten_kota_id)
 extra_index master_kelurahan expected: - | actual: master_kelurahan_kecamatan_id_foreign INDEX (kecamatan_id)

 FAIL — 3 discrepancies. The live schema does not match telemedicine_test.sql. Nothing was written.

EXIT=1
```

There is **not one** `column_*`, `missing_column`, `extra_column`, `index_name`,
`index_columns`, `missing_index`, `missing_foreign_key`, `extra_foreign_key`,
`foreign_key_action`, `missing_primary_key` or `table_engine` entry for any of the 11
tables. The three entries are all `extra_index`, and they are the verifier's blind spot, not
schema drift. Section 7 proves it.

### 6c. `information_schema` — the real column shapes, queried, not inferred

`migrate:fresh` exiting 0 is not evidence, so every column of all 11 tables was read back
out of `information_schema.COLUMNS` / `.STATISTICS` / `.KEY_COLUMN_USAGE` and compared with
the SQL line by line.

```
database: telemedisin_db

== master_provinsi
   id             tinyint unsigned         null=NO  default=NULL     extra=auto_increment key=PRI
   kode           char(2)                  null=NO  default=NULL     extra=(empty)        key=UNI
   nama           varchar(100)             null=NO  default=NULL     extra=(empty)        key=
   IDX master_provinsi_kode_unique            non_unique=0 col=kode
   IDX PRIMARY                                non_unique=0 col=id

== master_kabupaten_kota
   id             smallint unsigned        null=NO  default=NULL     extra=auto_increment key=PRI
   provinsi_id    tinyint unsigned         null=NO  default=NULL     extra=(empty)        key=MUL
   kode           char(4)                  null=NO  default=NULL     extra=(empty)        key=UNI
   nama           varchar(100)             null=NO  default=NULL     extra=(empty)        key=
   IDX master_kabupaten_kota_kode_unique      non_unique=0 col=kode
   IDX master_kabupaten_kota_provinsi_id_foreign non_unique=1 col=provinsi_id
   IDX PRIMARY                                non_unique=0 col=id
   FK  master_kabupaten_kota_provinsi_id_foreign provinsi_id -> master_provinsi.id

== master_kecamatan
   id             smallint unsigned        null=NO  default=NULL     extra=auto_increment key=PRI
   kabupaten_kota_id smallint unsigned     null=NO  default=NULL     extra=(empty)        key=MUL
   kode           char(7)                  null=NO  default=NULL     extra=(empty)        key=UNI
   nama           varchar(100)             null=NO  default=NULL     extra=(empty)        key=
   IDX master_kecamatan_kabupaten_kota_id_foreign non_unique=1 col=kabupaten_kota_id
   IDX master_kecamatan_kode_unique           non_unique=0 col=kode
   IDX PRIMARY                                non_unique=0 col=id
   FK  master_kecamatan_kabupaten_kota_id_foreign kabupaten_kota_id -> master_kabupaten_kota.id

== master_kelurahan
   id             mediumint unsigned       null=NO  default=NULL     extra=auto_increment key=PRI
   kecamatan_id   smallint unsigned        null=NO  default=NULL     extra=(empty)        key=MUL
   kode           char(10)                 null=NO  default=NULL     extra=(empty)        key=UNI
   nama           varchar(100)             null=NO  default=NULL     extra=(empty)        key=
   IDX master_kelurahan_kecamatan_id_foreign  non_unique=1 col=kecamatan_id
   IDX master_kelurahan_kode_unique           non_unique=0 col=kode
   IDX PRIMARY                                non_unique=0 col=id
   FK  master_kelurahan_kecamatan_id_foreign  kecamatan_id -> master_kecamatan.id

== master_agama
   id             tinyint unsigned         null=NO  default=NULL     extra=(empty)        key=PRI
   nama           varchar(50)              null=NO  default=NULL     extra=(empty)        key=
   IDX PRIMARY                                non_unique=0 col=id

== master_golongan_darah
   id             tinyint unsigned         null=NO  default=NULL     extra=(empty)        key=PRI
   kode           enum('A','B','AB','O')   null=NO  default=NULL     extra=(empty)        key=
   IDX PRIMARY                                non_unique=0 col=id

== master_pendidikan
   id             tinyint unsigned         null=NO  default=NULL     extra=(empty)        key=PRI
   nama           varchar(50)              null=NO  default=NULL     extra=(empty)        key=
   IDX PRIMARY                                non_unique=0 col=id

== master_status_pernikahan
   id             tinyint unsigned         null=NO  default=NULL     extra=(empty)        key=PRI
   nama           enum('belum_menikah','menikah','cerai_hidup','cerai_mati') null=NO  default=NULL  extra=(empty)  key=
   IDX PRIMARY                                non_unique=0 col=id

== master_hubungan_keluarga
   id             tinyint unsigned         null=NO  default=NULL     extra=(empty)        key=PRI
   nama           varchar(50)              null=NO  default=NULL     extra=(empty)        key=
   IDX PRIMARY                                non_unique=0 col=id

== master_icd10
   id             int unsigned             null=NO  default=NULL     extra=auto_increment key=PRI
   kode           varchar(8)               null=NO  default=NULL     extra=(empty)        key=UNI
   deskripsi      varchar(255)             null=NO  default=NULL     extra=(empty)        key=
   IDX idx_icd10                              non_unique=1 col=kode
   IDX master_icd10_kode_unique               non_unique=0 col=kode
   IDX PRIMARY                                non_unique=0 col=id

== master_icd9cm
   id             int unsigned             null=NO  default=NULL     extra=auto_increment key=PRI
   kode           varchar(8)               null=NO  default=NULL     extra=(empty)        key=UNI
   deskripsi      varchar(255)             null=NO  default=NULL     extra=(empty)        key=
   IDX master_icd9cm_kode_unique              non_unique=0 col=kode
   IDX PRIMARY                                non_unique=0 col=id

== SHOW CREATE TABLE master_icd10 (redundant idx_icd10 + inline UNIQUE)
CREATE TABLE `master_icd10` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deskripsi` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `master_icd10_kode_unique` (`kode`),
  KEY `idx_icd10` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

Every parity rule that could have been got wrong, checked against the SQL:

| Rule | Expected from SQL | Observed in `information_schema` | |
| --- | --- | --- | --- |
| PK width `master_provinsi` | `TINYINT UNSIGNED` | `tinyint unsigned` | OK |
| PK width `master_kabupaten_kota` / `master_kecamatan` | `SMALLINT UNSIGNED` | `smallint unsigned` | OK |
| PK width `master_kelurahan` | `MEDIUMINT UNSIGNED` | `mediumint unsigned` | OK |
| PK width `master_icd10` / `master_icd9cm` | `INT UNSIGNED` | `int unsigned` | OK |
| PK width the 5 master-umum tables | `TINYINT UNSIGNED` | `tinyint unsigned` | OK |
| non-auto-increment: `EXTRA` empty on `master_agama`, `master_golongan_darah`, `master_pendidikan`, `master_status_pernikahan`, `master_hubungan_keluarga` | empty | `extra=(empty)` on all 5 | OK |
| auto-increment present on the other 6 | `auto_increment` | `extra=auto_increment` on all 6 | OK |
| no timestamps anywhere in batch A | no `dibuat_at`/`diubah_at` | none present | OK |
| `master_icd10` redundant `idx_icd10` **plus** `UNIQUE (kode)` | both | `KEY idx_icd10 (kode)` + `UNIQUE KEY … (kode)` | OK |
| `master_icd9cm` has **no** secondary index | none | only PRIMARY + the unique | OK |
| `kode` widths `CHAR(2)`, `CHAR(4)`, `CHAR(7)`, `CHAR(10)`, `VARCHAR(8)` | exact | `char(2)`, `char(4)`, `char(7)`, `char(10)`, `varchar(8)` | OK |
| ENUM member order `A,B,AB,O` and the 4 marriage states | verbatim | `enum('A','B','AB','O')`, `enum('belum_menikah','menikah','cerai_hidup','cerai_mati')` | OK |
| FK targets and implicit `RESTRICT` | 3 FKs to the parent wilayah table | 3 FKs, all resolving | OK |
| every `id` / `kode` / `nama` `NOT NULL` | `NOT NULL` | `null=NO` on all 33 columns | OK |

---

## 7. Adversarial class: `misleading_success_output` — probed, and it fired

### 7a. The `--tables` filter restricts both sides

```
$ php artisan sehatly:verify-schema --tables=master_provinsi,users
 scope master_provinsi, users
 Discrepancies: 1 (1 drift, 0 informational)
 missing_table users expected: 16 columns, 4 indexes, 0 foreign keys, 0 checks | actual: -
EXIT=1
```

`master_provinsi` produced no entry (it exists and matches) while `users` is reported
missing with the reference model's own summary — so the filter really is looking at both
sides, and the pass in 6a is not a constant.

### 7b. Unfiltered run: the 11 tables carry no structural drift

```
$ php artisan sehatly:verify-schema --json
ok: false  discrepancy_count: 76  drift_count: 69
registered extras: 7
live: {"driver":"mysql","database":"telemedisin_db","information_schema_counts":{"columns":74,"indexes":36,"foreign_keys":3,"checks":0}}
kinds: {"documented_extra_table":7,"extra_index":3,"missing_table":64,"missing_view":2}

--- discrepancies naming one of the 11 batch-A tables (3):
   extra_index      master_kabupaten_kota  [null,null,"master_kabupaten_kota_provinsi_id_foreign INDEX (provinsi_id)"]
   extra_index      master_kecamatan       [null,null,"master_kecamatan_kabupaten_kota_id_foreign INDEX (kabupaten_kota_id)"]
   extra_index      master_kelurahan       [null,null,"master_kelurahan_kecamatan_id_foreign INDEX (kecamatan_id)"]

missing_table count: 64 (expected 64 — the 64 tables todos 8-17 have not written yet)
informational entries: 7
   documented_extra_table     cache
   documented_extra_table     cache_locks
   documented_extra_table     failed_jobs
   documented_extra_table     job_batches
   documented_extra_table     jobs
   documented_extra_table     migrations
   documented_extra_table     personal_access_tokens
```

64 `missing_table` is exactly right (75 − 11), 2 `missing_view` is todo 18's, and the only
entries touching batch A are the three `extra_index` ones.

### 7c. Root cause of the three `extra_index` entries — proven, not theorised

Reproduced on a throwaway pair of tables in `telemedisin_db_test` using the **reference
DDL verbatim** (both tables dropped again immediately; the database is back to 0 tables):

```
CREATE TABLE `probe_kab` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `provinsi_id` tinyint unsigned NOT NULL,
  `kode` char(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode` (`kode`),
  KEY `provinsi_id` (`provinsi_id`),        <-- MySQL created this; the DDL never wrote it
  CONSTRAINT `probe_kab_ibfk_1` FOREIGN KEY (`provinsi_id`) REFERENCES `probe_prov` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
probe tables left: 0
```

So the **reference import itself** produces the index. `SqlSchemaParser` records only a
`PRIMARY KEY`, a name-bearing `INDEX`/`UNIQUE KEY`, an inline `UNIQUE` and an inline
`PRIMARY KEY` — never InnoDB's implicit FK-support index — and
`SchemaDiffer::diffIndexes()` reports every unconsumed live index as `extra_index` **drift**
(`app/Support/Schema/SchemaDiffer.php:235-241`).

Scale of the defect, computed over the reference DDL: **80 of the 105 foreign keys** have no
covering index in the DDL, so a fully migrated database will carry 80 such entries and
todo 18's `75 tables, 2 views verified` exit 0 is **unreachable until the differ accounts
for them**. Batch A's share is 3.

Both "fixes" available to a migration author are worse than the defect: dropping the foreign
key loses a real constraint and reports `missing_foreign_key` instead, and naming the index
yourself only changes which name appears. The minimal correct fix is one behaviour in
`diffIndexes()`: do not report an `extra_index` whose column list is exactly the
local-column list of a matched expected foreign key. It belongs to todo 18, which owns the
green. Recorded in `docs/schema-notes.md` (new section) and in `docs/migration-order.md`
(new section, so todos 8-17 do not each "fix" it in their own migration).

---

## 8. Negative QA — the verifier detects a real parity break

```
$ alter table `master_provinsi` modify `kode` char(3) not null
--- BEFORE: char(2)
--- AFTER the deliberate widening: char(3)

$ php artisan sehatly:verify-schema --tables=master_provinsi

 Discrepancies: 1 (1 drift, 0 informational)
 column_type master_provinsi.kode expected: char(2) | actual: char(3)

 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.

EXIT=1
```

Restored with the exact inverse statement, and re-verified:

```
$ alter table `master_provinsi` modify `kode` char(2) not null
RESTORED: char(2)

$ php artisan sehatly:verify-schema --tables=master_provinsi

 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.

 PASS — 75 tables, 2 views verified. Nothing was written.

EXIT=0
```

So the verifier is not a rubber stamp: it catches a one-character type widening, names the
exact column, and goes green again once the widening is undone.

---

## 9. Remaining adversarial classes

- **`stale_state` — ruled out.** `bootstrap/cache/config.php` does not exist and did not
  exist at any point (`Test-Path` → `False` before and after). `php artisan config:clear`
  was run before and after the QA sequence: exit 0 both times. The migrator's own output
  proves the ordering claim: `2026_09_26_222801` runs before `2026_10_01_000001`, and the 11
  files run in index order. `$env:GIT_INDEX_FILE` is unset.
- **`dirty_worktree` — handled precisely.** Three tracked files are deleted, all by explicit
  path (`git rm -- <path>`, one call per path, no bulk add, no `-u`, no `reset`, no
  `checkout .`, no `stash`). `git diff --cached --name-status` before staging showed exactly
  those three `D` entries and nothing else. `.omo/plans/sehatly-telemedicine-platform.md`
  (modified) and `.omo/start-work/` + `.omo/evidence/task-3-sehatly.md` (untracked) are the
  orchestrator's and were never staged. `telemedicine_test.sql` is untouched.
- **`hung_or_long_commands` — no hangs.** Every command was given an explicit timeout
  (120-300 s). The longest was `migrate:fresh` at well under a second of DDL time. No
  command timed out and no exit code was inferred — each is quoted above as observed. The
  pre-existing `mysqld` and the user's `php artisan serve` were never signalled: no
  `Stop-Process`, no `taskkill`, no service control was issued at any point.
- **`repeated_interruptions` — probed, and the procedure is idempotent.** The one
  interruption that actually happened was the first `migrate:fresh` failing on `passkeys`
  after `cache` and `jobs` had already been created. Re-running `migrate:fresh` after the fix
  converged (`Dropping all tables .. DONE`, then a clean full run), and the
  rollback→migrate cycle reproduced a byte-identical `information_schema` dump (section 4).
  Resume is therefore safe: always converge with `migrate:fresh`, never by re-running the
  failing migration alone.
- **`malformed_input` — N/A.** No parser was authored; this todo consumes Laravel's own
  migration/schema builder. The only hand-written input is `docs/migration-order.md`, and it
  is validated mechanically (section 2).
- **`prompt_injection` — N/A, checked.** `telemedicine_test.sql` is first-party local data
  read as a specification. It contains no instruction-shaped text addressed to an agent: its
  only prose is the Indonesian section banner (`[1] MASTER DATA`, "aman untuk re-import
  berkali-kali") and 40 column `COMMENT`s, all of which are data the verifier strips before
  tokenising. The 106th `FOREIGN KEY` token in the file is a comment at `:1158`, which the
  parser already ignores. Nothing in it was acted on as an instruction.
- **`cancel_resume` — N/A.** No resumable user flow exists yet; that is todos 20/45/46.
- **`flaky_tests` — N/A.** Migrations are deterministic; the same command was run five times
  with identical results. The real misleading-output risk is the `--tables` PASS banner
  (section 6a) and the InnoDB index defect (section 7c), both documented above.

---

## 10. Data safety and cleanup receipts

```
$ git diff --exit-code telemedicine_test.sql
EXIT=0
$ (Get-FileHash telemedicine_test.sql -Algorithm SHA256).Hash
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
SHA256 MATCHES the declared value
bytes=59604
```

Database state after all QA:

```
telemedisin_db         tables=18  [cache, cache_locks, failed_jobs, job_batches, jobs, master_agama,
                                     master_golongan_darah, master_hubungan_keluarga, master_icd10,
                                     master_icd9cm, master_kabupaten_kota, master_kecamatan, master_kelurahan,
                                     master_pendidikan, master_provinsi, master_status_pernikahan,
                                     migrations, personal_access_tokens]
telemedisin_db_test    tables=0  []
sehatly                tables=10  migration rows=5  [cache, cache_locks, failed_jobs, job_batches,
                                     jobs, migrations, passkeys, password_reset_tokens, sessions, users]
```

- `sehatly`: **10 tables, 5 migration rows — identical to the pre-todo measurement.** No
  `migrate`, `migrate:fresh`, `db:seed`, `DROP`, `TRUNCATE` or `ALTER` was ever run against
  it; every statement this todo issued targeted `telemedisin_db`, and the two throwaway
  probe tables lived in `telemedisin_db_test` and were dropped (verified 0 tables).
- The other schemas on the server — `db_simprapkl`, `gawaiseken`, `manajemen-surat`,
  `trading_journal`, `ukk`, `ukk_pengaduan_sekolah`, `laravel`, plus the MySQL system
  schemas — were listed read-only and never written.
- `php artisan install:api` was **never** invoked in any form (A.5).
- No `mobile/` directory and no `pubspec.yaml` was created.
- Pint was run **bare** (no path argument), per A.7:
  `{"tool":"pint","result":"passed"}`, exit 0, and `pint --test` re-run afterwards also
  exit 0. `bootstrap/cache/packages.php` and `services.php` were not touched.
- Temp fixtures: every scratch script, capture file and the deliberate
  `master_provinsi.kode` widening probe lived in
  `C:\Users\axioo\AppData\Local\Temp\opencode\` (outside the repository) and was deleted
  after the evidence above was assembled. Nothing was left inside the repo, and no process
  was left running.
- `git status --porcelain` at commit time contains only this todo's paths plus the
  orchestrator's three entries (`.omo/plans/…`, `.omo/evidence/task-3-sehatly.md`,
  `.omo/start-work/`).

---

## 11. Files committed / deleted

Created (13):

```
docs/migration-order.md
database/migrations/2026_10_01_000001_master_provinsi_table.php
database/migrations/2026_10_01_000002_master_kabupaten_kota_table.php
database/migrations/2026_10_01_000003_master_kecamatan_table.php
database/migrations/2026_10_01_000004_master_kelurahan_table.php
database/migrations/2026_10_01_000005_master_agama_table.php
database/migrations/2026_10_01_000006_master_golongan_darah_table.php
database/migrations/2026_10_01_000007_master_pendidikan_table.php
database/migrations/2026_10_01_000008_master_status_pernikahan_table.php
database/migrations/2026_10_01_000009_master_hubungan_keluarga_table.php
database/migrations/2026_10_01_000010_master_icd10_table.php
database/migrations/2026_10_01_000011_master_icd9cm_table.php
.omo/evidence/task-7-sehatly.md
```

Modified (1): `docs/schema-notes.md`

Deleted (3): the two A.4-named scaffold migrations plus
`2024_01_01_000000_create_passkeys_table.php`, which could not coexist with todo 8's
`users` (section 3, section 5).

**The task brief expected exactly 2 deletions; the third was forced by the FK collision
above, and A.4 explicitly assigned that decision to todo 7. It is called out here because
it changes the expected `git show --name-status` shape from "11 A + 2 D" to
"13 A + 3 D + 1 M" — 13 added (11 migrations, this file, `docs/migration-order.md`),
3 deleted, and `docs/schema-notes.md` modified, for 17 files changed in total.**

No Model, Resource, Controller, seeder, factory or route was created for any of the 11
tables — todo 19 owns the models, todo 18 the seeders, todo 42 the reference-data
endpoints. No migration beyond 1-11 was authored.
