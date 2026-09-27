# Task 9 — Migration batch C (patient and clinical sub-tables) — evidence

Plan: `.omo/plans/sehatly-telemedicine-platform.md` todo 9 (lines 314-320)
Contract: `docs/migration-order.md` rows 20-27
Reference DDL: `telemedicine_test.sql:218-358` (+ `:1161-1163`)
Branch: `feat/sehatly-telemedicine` · Base commit: `2d0fc5b`
PHP: `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17) — first line of
every shell invocation below was `$env:PATH = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64;$env:PATH"`,
because bare `php` on PATH is 8.2.29 and fails Laravel's `^8.3`.

---

## 0. HEADLINE FINDING — acceptance criterion 2 is unsatisfiable at this position

**Criterion 2 (`verify-schema --tables=<8 names>` exits 0, `Discrepancies: 0`) is
structurally impossible at todo 9, and it is a plan-sequencing defect of exactly the
class Appendix A.6 describes — not an executor defect and not a migration bug.**

The plan's own todo-9 body (line 315) is self-contradictory on this point. It says:

> `pasien_tanda_vital` is created here **without** its `rekam_medis_id` foreign key
> — that FK is added by migration 76 per the SQL's section `[14]` (`:1161-1163`)
> because `rekam_medis` does not exist yet.

and then (line 318) demands `verify-schema` exit 0. Both cannot hold, because
`SqlSchemaParser` deliberately folds the section-`[14]` `ALTER TABLE` into
`pasien_tanda_vital`'s **expected** model (`app/Support/Schema/SqlSchemaParser.php:284-300`,
"`ALTER TABLE ... ADD CONSTRAINT` carries the deferred foreign key
(`telemedicine_test.sql:1161-1163`), so it folds into the table it targets"). The
verifier's own banner states it:

```
 named FKs fk_vital_rm on patients_tanda_vital (line 1161)
```

So the reference model **requires** `fk_vital_rm`, the live schema **cannot** have it
at migration position 25, and the differ reports it as `missing_foreign_key` drift →
exit 1. This is the *first* time in the project that the reference model contains an
object the live schema cannot yet hold, which is why todos 7 and 8 both reached exit 0
and todo 9 cannot.

### 0.1 Proof that the constraint is impossible here, not merely inconvenient

The SQL's own statement, attempted verbatim against `telemedisin_db`, and the same for
`pasien_penjamin.faskes_rujukan_id -> faskes`:

```
BEFORE: FKs in telemedisin_db = 23
BEFORE: table rekam_medis exists = 0
BEFORE: table faskes exists      = 0
BEFORE: FKs on patients_signs_of_vital = 1
BEFORE: FKs on patients_insurer        = 2

--- ATTEMPT: SQL :1161-1163 verbatim (fk_vital_rm)
    SQL: ALTER TABLE patients_signs_of_vital ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE SET NULL
    RESULT: REJECTED by MySQL -> SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'rekam_medis'

--- ATTEMPT: patients_insurer.faskes_rujukan_id -> faskes
    SQL: ALTER TABLE patients_insurer ADD CONSTRAINT faskes_rujukan_fk FOREIGN KEY (faskes_rujukan_id) REFERENCES faskes(id)
    RESULT: REJECTED by MySQL -> SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'faskes'

AFTER: FKs in telemedisin_db = 23
AFTER: constraint named fk_vital_rm     = 0
AFTER: FKs on patients_signs_of_vital   = 1
AFTER: FKs on patients_insurer          = 2
AFTER: total table count in telemedisin_db        = 34

=> Both constraints are structurally impossible at migration position 25;
   migration 2026_10_01_000076 (todo 18) owns them.
```

Exit code `0` (the script itself completed; the two ALTERs were rejected server-side).
MySQL error **1824** is InnoDB refusing to open a non-existent referenced table. State
is provably unchanged: 23 FKs, 34 tables, `fk_vital_rm` absent before and after.

`rekam_medis` is SQL table **42** and `faskes` is SQL table **28** — both **after**
position 25 in `docs/migration-order.md`. `pasien_riwayat_penyakit` (row 23) and
`master_penjamin` (row 26) are the only other batch-C rows whose constraints could ever
have been affected, and neither has a deferred one.

### 0.2 The single discrepancy is the deferred FK and nothing else

Isolating the one table, and the other seven:

```
$ php artisan sehatly:verify-schema --tables=patients_tanda_vital
 scope patients_tanda_vital
 Discrepancies: 1 (1 drift, 0 informational)
 missing_foreign_key patients_tanda_vital expected: fk_vital_rm FOREIGN KEY (rekam_medis_id) -> rekam_medis (id) ON DELETE SET NULL ON UPDATE RESTRICT | actual: -
 FAIL — 1 discrepancy. ...
exit 1

$ php artisan sehatly:verify-schema --tables=patients,patients_family,patients_allergies,patients_disease_history,patients_immunizations,master_insurer,patients_insurer
 scope patients, patients_family, patients_allergies, patients_disease_history, patients_immunizations, master_insurer, patients_insurer
 Discrepancies: 0 (0 drift, 0 informational)
 PASS — 75 tables, 2 views verified. Nothing was written.
exit 0
```

**The other seven tables reach `Discrepancies: 0` and exit 0.** `pasien_tanda_vital`
has exactly one discrepancy and it is the documented deferral. So 95 of 95 columns,
all 8 primary keys, all named indexes and all 13 present foreign keys are at parity
(§3, §4 below).

### 0.3 What I did NOT do to force a green

Each of these would have produced `Discrepancies: 0` and every one of them is forbidden
by the plan's own guardrails:

| Tempting "fix" | Why it is wrong |
|---|---|
| Create `rekam_medis` early | Todo 13 owns table 42. "MUST NOT author any table outside 20-27." |
| Add the `ALTER` to `2026_10_01_000025` | MySQL 1824, proven above. Also duplicates todo 18's migration 76. |
| `DROP`/`--sql` a trimmed copy of the reference to hide `:1161-1163` | `telemedicine_test.sql` is read-only law; a doctored reference is exactly the false green the verifier exists to prevent. |
| Teach the differ to ignore `fk_vital_rm` | Todo 6 owns `SchemaDiffer`; silently forgiving a missing constraint is worse than a red exit code, and it would make todo 18's green vacuous. |
| Add a note to `docs/schema-notes.md`'s registry table | The registry forgives extra **tables**, not missing constraints; and a backticked first cell would be parsed as a registered extra table. |

**Recommended plan amendment (for the orchestrator, not for me):** todo 9's criterion 2
should read "`Discrepancies: 1`, and the single row must be the
`missing_foreign_key … fk_vital_rm` deferral recorded in `docs/schema-notes.md`; the
other seven tables must reach `Discrepancies: 0`", exactly parallel to how A.6
rescoped todo 6's criterion 1. Alternatively the plan could state that criterion 2 is
**deferred to todo 18** (which is where the constraint lands), as A.6 did for todo 6.

---

## 1. Manual-QA channel — headline commands, verbatim, with exit codes

```
$ php artisan config:clear
 INFO Configuration cache cleared successfully.
exit 0

$ php artisan migrate:fresh --no-interaction
 Dropping all tables .. 116.19ms DONE

 INFO Preparing database.

 Creating migration table .. 18.99ms DONE

 INFO Running migrations.

 0001_01_01_000001_create_cache_table .. 56.91ms DONE
 0001_01_01_000002_create_jobs_table .. 82.87ms DONE
 2026_09_26_222801_create_personal_access_tokens_table .. 49.17ms DONE
 2026_10_01_000001_master_provinsi_table .. 27.63ms DONE
 2026_10_01_000002_master_kabupaten_kota_table .. 75.21ms DONE
 2026_10_01_000003_master_kecamatan_table .. 66.01ms DONE
 2026_10_01_000004_master_kelurahan_table .. 62.67ms DONE
 2026_10_01_000005_master_agama_table .. 12.71ms DONE
 2026_10_01_000006_master_golongan_darah_table .. 12.89ms DONE
 2026_10_01_000007_master_pendidikan_table .. 13.04ms DONE
 2026_10_01_000008_master_status_pernikahan_table .. 13.60ms DONE
 2026_10_01_000009_master_hubungan_keluarga_table .. 15.16ms DONE
 2026_10_01_000010_master_icd10_table .. 37.65ms DONE
 2026_10_01_000011_master_icd9cm_table .. 27.14ms DONE
 2026_10_01_000012_users_table .. 59.76ms DONE
 2026_10_01_000013_roles_table .. 29.81ms DONE
 2026_10_01_000014_permissions_table .. 25.34ms DONE
 2026_10_01_000015_role_permissions_table .. 107.07ms DONE
 2026_10_01_000016_user_roles_table .. 114.33ms DONE
 2026_10_01_000017_user_otp_table .. 59.29ms DONE
 2026_10_01_000018_user_devices_table .. 69.97ms DONE
 2026_10_01_000019_user_refresh_tokens_table .. 47.60ms DONE
 2026_10_01_000020_pasien_table .. 359.79ms DONE
 2026_10_01_000021_pasien_anggota_keluarga_table .. 90.95ms DONE
 2026_10_01_000022_pasien_alergi_table .. 47.79ms DONE
 2026_10_01_000023_pasien_riwayat_penyakit_table .. 66.51ms DONE
 2026_10_01_000024_pasien_imunisasi_table .. 44.11ms DONE
 2026_10_01_000025_pasien_tanda_vital_table .. 66.72ms DONE
 2026_10_01_000026_master_penjamin_table .. 13.90ms DONE
 2026_10_01_000027_pasien_penjamin_table .. 110.77ms DONE
exit 0
```

```
$ php artisan sehatly:verify-schema --tables=pasien,pasien_anggota_keluarga,pasien_alergi,pasien_riwayat_penyakit,pasien_imunisasi,pasien_tanda_vital,master_penjamin,pasien_penjamin
 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables)
 scope pasien, patients_anggota_keluarga, patients_allergies, patients_disease_history, patients_immunizations, patients_tanda_vital, master_insurer, patients_insurer

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: bookings.status (515-516), home_care_orders.status (1104-1105), invoices.status (947-948), bpjs_claims.status (1022-1023), consultations.status (542-543), consultation_chat.message_type (568-569), lab_requests.status (884-885), medicines.dosage_form (713-714), pdp_consents.type (1137-1138), medicine_orders.status (810-811), prescriptions.status (751-752)
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on patients_tanda_vital (line 1161)

 Live schema
 counts tables=34 views=0 columns=217 indexes=80 foreign_keys=23 checks=0
 information_schema columns=217 indexes=80 foreign_keys=23 checks=0

 Discrepancies: 1 (1 drift, 0 informational)
 missing_foreign_key patients_tanda_vital expected: fk_vital_rm FOREIGN KEY (rekam_medis_id) -> rekam_medis (id) ON DELETE SET NULL ON UPDATE RESTRICT | actual: -

 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.
exit 1
```

**A.7 / A.8 traps, explicitly ruled out for this run:**

- **Banner:** `PASS — 75 tables, 2 views verified` is meaningless in `--tables` mode
  (it is formatted from the *full* reference model). In the 8-table run above the
  banner correctly reads `FAIL`, and the authoritative signals are
  `Discrepancies: 1 (1 drift, 0 informational)` and exit 1.
- **`unknown_requested_table` false green:** the echoed `scope` line lists **all 8 real
  names** — `pasien, patients_anggota_keluarga, patients_allergies, patients_disease_history,
  patients_immunizations, patients_tanda_vital, master_insurer, patients_insurer` — and
  the live count is `tables=34`, so no requested name was silently dropped. I also
  confirmed independently that each of the 8 names exists in the live schema (§6).
- `notes registry docs/schema-notes.md (7 registered extra tables)` — the registry is
  still exactly 7 after my append, because I added prose bullets and **no** new table
  row (§7).

`telemedisin_db_test` was migrated separately (PHPUnit's connection, §5) and carries a
**byte-identical** schema fingerprint to `telemedisin_db`.

---

## 2. Acceptance criteria, item by item

| # | Criterion | Result |
|---|---|---|
| 1 | `php artisan migrate:fresh` exits 0 | **PASS**, exit 0, 34 tables |
| 2 | `verify-schema --tables=<8>` exit 0, `Discrepancies: 0` | **FAIL — structurally unsatisfiable.** exit 1, `Discrepancies: 1`, and the single row is the documented `fk_vital_rm` deferral. The other 7 tables reach `Discrepancies: 0` / exit 0. See §0. |
| 3 | `SHOW CREATE TABLE patients_tanda_vital`: `rekam_medis_id` present, **no** FK yet | **PASS** — see §4 |
| 4 | `SHOW CREATE TABLE patients`: `dihapus_at timestamp NULL` (not `datetime`) and `INDEX idx_pasien_lahir` | **PASS** — see §4 |
| 5 | `php artisan test tests/Unit` exit 0, count ≥ 74, test file **not** edited | **PASS** — exit 0, **74** tests / 74 passed / 351 assertions; `git diff -- tests/` empty |
| 6 | `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` exits 1 | **PASS** — exit 1, `No tests found.` |
| 7 | Negative QA: `tinggi_badan_cm` at `DECIMAL(5,2)` must be flagged by name | **PASS** — exit 1, `column_type patients.tinggi_badan_cm expected: decimal(5,1) \| actual: decimal(5,2)`; restored → exit 0. See §8. |

### Criterion 5 in detail — A.9's projection confirmed, no edit to the test

```
$ php artisan test tests/Unit

  [derive] migrations=30 Schema::create calls=33 extracted=33 | CREATE VIEW calls=0 extracted=0 | contract tables=75 views=2 | derived-missing tables=48 views=2 | registry=7
{"tool":"pest","result":"passed","tests":74,"passed":74,"assertions":351,"duration_ms":3102}
exit 0
```

- **74 tests, all 74 passed** — exactly the todo-8b baseline, `>= 74` satisfied, and the
  count did not silently drop.
- The `[derive]` line is the receipt that A.9's mandated fix is in place and that adding
  8 migrations exercised it correctly: **`derived-missing tables=48`**, which is
  **precisely** the value A.9's projection table predicts for todo 9 (56 → 48), derived
  at runtime from **33 `Schema::create` calls / 33 extracted** (so the walk is not
  vacuous — the test asserts `extracted === calls`) rather than pinned.
- `registry=7` is likewise derived from `count(ExtraTableRegistry::fromMarkdown(...))`,
  and is still 7 after my `docs/schema-notes.md` append.
- **No edit to `tests/Unit/Console/VerifySchemaCommandTest.php`**, confirmed by
  `git diff --stat -- tests/` returning empty. I did not open that file for writing at
  any point.

---

## 3. `information_schema` parity dump — all 8 tables, 95 columns, line by line

`telemedisin_db` (identical for `telemedisin_db_test`; both fingerprints §5).
Every row's `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT` and `EXTRA` compared against
`telemedicine_test.sql:218-358` by hand, not by the differ alone.

```
-- TABLE patients (31 columns)
  #   COLUMN_NAME              COLUMN_TYPE                  NULL COLUMN_DEFAULT           EXTRA
  1   id                       bigint unsigned              NO   NULL                     auto_increment
  2   user_id                  bigint unsigned              NO   NULL                     (none)
  3   nomor_rm                 varchar(20)                  YES  NULL                     (none)
  4   nik                      char(16)                     YES  NULL                     (none)
  5   nomor_kk                 char(16)                     YES  NULL                     (none)
  6   nomor_ihs_satusehat      varchar(50)                  YES  NULL                     (none)
  7   jenis_kelamin            enum('L','P')                NO   NULL                     (none)
  8   tanggal_lahir            date                         NO   NULL                     (none)
  9   tempat_lahir             varchar(100)                 YES  NULL                     (none)
  10  golongan_darah_id        tinyint unsigned             YES  NULL                     (none)
  11  rhesus                   enum('positif','negatif','tidak_diketahui') NO   tidak_diketahui          (none)
  12  agama_id                 tinyint unsigned             YES  NULL                     (none)
  13  pendidikan_id            tinyint unsigned             YES  NULL                     (none)
  14  pekerjaan                varchar(100)                 YES  NULL                     (none)
  15  status_pernikahan_id     tinyint unsigned             YES  NULL                     (none)
  16  alamat_lengkap           text                         NO   NULL                     (none)
  17  provinsi_id              tinyint unsigned             YES  NULL                     (none)
  18  kabupaten_kota_id        smallint unsigned            YES  NULL                     (none)
  19  kecamatan_id             smallint unsigned            YES  NULL                     (none)
  20  kelurahan_id             mediumint unsigned           YES  NULL                     (none)
  21  rt                       varchar(5)                   YES  NULL                     (none)
  22  rw                       varchar(5)                   YES  NULL                     (none)
  23  kode_pos                 char(5)                      YES  NULL                     (none)
  24  catatan_alergi           text                         YES  NULL                     (none)
  25  tinggi_badan_cm          decimal(5,1)                 YES  NULL                     (none)
  26  berat_badan_kg           decimal(5,2)                 YES  NULL                     (none)
  27  is_meninggal             tinyint(1)                   NO   0                        (none)
  28  tanggal_meninggal        date                         YES  NULL                     (none)
  29  dibuat_at                timestamp                    NO   CURRENT_TIMESTAMP        DEFAULT_GENERATED
  30  diubah_at                timestamp                    NO   CURRENT_TIMESTAMP        DEFAULT_GENERATED on update CURRENT_TIMESTAMP
  31  dihapus_at               timestamp                    YES  NULL                     (none)

-- TABLE patients_family (10 columns)
  1   id                       bigint unsigned              NO   NULL                     auto_increment
  2   pasien_id                bigint unsigned              NO   NULL                     (none)
  3   hubungan_id              tinyint unsigned             NO   NULL                     (none)
  4   nik                      char(16)                     YES  NULL                     (none)
  5   nama_lengkap             varchar(150)                 NO   NULL                     (none)
  6   jenis_kelamin            enum('L','P')                NO   NULL                     (none)
  7   tanggal_lahir            date                         NO   NULL                     (none)
  8   no_telepon               varchar(20)                  YES  NULL                     (none)
  9   catatan_alergi           text                         YES  NULL                     (none)
  10  dibuat_at                timestamp                    NO   CURRENT_TIMESTAMP        DEFAULT_GENERATED

-- TABLE patients_allergies (8 columns)
  1   id                       bigint unsigned              NO   NULL                     auto_increment
  2   pasien_id                bigint unsigned              NO   NULL                     (none)
  3   tipe_alergen             enum('obat','makanan','lingkungan','lainnya') NO   NULL                     (none)
  4   nama_alergen             varchar(150)                 NO   NULL                     (none)
  5   reaksi                   varchar(255)                 YES  NULL                     (none)
  6   keparahan                enum('ringan','sedang','berat','anafilaksis') NO   ringan                   (none)
  7   dicatat_oleh_user_id     bigint unsigned              YES  NULL                     (none)
  8   dibuat_at                timestamp                    NO   CURRENT_TIMESTAMP        DEFAULT_GENERATED

-- TABLE patients_disease_history (9 columns)
  1   id                       bigint unsigned              NO   NULL                     auto_increment
  2   pasien_id                bigint unsigned              NO   NULL                     (none)
  3   tipe                     enum('pribadi','keluarga')   NO   NULL                     (none)
  4   nama_penyakit            varchar(150)                 NO   NULL                     (none)
  5   icd10_kode               varchar(8)                   YES  NULL                     (none)
  6   tahun_terdiagnosis       year                         YES  NULL                     (none)
  7   status                   enum('aktif','kronis','sembuh') NO   aktif                    (none)
  8   keterangan               text                         YES  NULL                     (none)
  9   dibuat_at                timestamp                    NO   CURRENT_TIMESTAMP        DEFAULT_GENERATED

-- TABLE patients_immunizations (8 columns)
  1   id                       bigint unsigned              NO   NULL                     auto_increment
  2   pasien_id                bigint unsigned              NO   NULL                     (none)
  3   nama_vaksin              varchar(150)                 NO   NULL                     (none)
  4   tanggal                  date                         NO   NULL                     (none)
  5   dosis_ke                 tinyint unsigned             YES  NULL                     (none)
  6   no_batch                 varchar(50)                  YES  NULL                     (none)
  7   pemberi                  varchar(150)                 YES  NULL                     (none)
  8   dibuat_at                timestamp                    NO   CURRENT_TIMESTAMP        DEFAULT_GENERATED

-- TABLE patients_tanda_vital (15 columns)
  1   id                       bigint unsigned              NO   NULL                     auto_increment
  2   pasien_id                bigint unsigned              NO   NULL                     (none)
  3   rekam_medis_id           bigint unsigned              YES  NULL                     (none)
  4   sistolik                 smallint unsigned            YES  NULL                     (none)
  5   diastolik                smallint unsigned            YES  NULL                     (none)
  6   nadi                     smallint unsigned            YES  NULL                     (none)
  7   suhu                     decimal(4,1)                 YES  NULL                     (none)
  8   laju_pernapasan          smallint unsigned            YES  NULL                     (none)
  9   spo2                     tinyint unsigned             YES  NULL                     (none)
  10  tinggi_cm                decimal(5,1)                 YES  NULL                     (none)
  11  berat_kg                 decimal(5,2)                 YES  NULL                     (none)
  12  glukosa_darah            decimal(6,1)                 YES  NULL                     (none)
  13  sumber                   enum('mandiri','dokter','perawat','iot_device') NO   mandiri                  (none)
  14  diukur_at                datetime                     NO   NULL                     (none)
  15  dibuat_at                timestamp                    NO   CURRENT_TIMESTAMP        DEFAULT_GENERATED

-- TABLE master_insurer (4 columns)
  1   id                       smallint unsigned            NO   NULL                     auto_increment
  2   nama                     varchar(150)                 NO   NULL                     (none)
  3   tipe                     enum('bpjs','asuransi_swasta','perusahaan','tunai') NO   NULL                     (none)
  4   status_aktif             tinyint(1)                   NO   1                        (none)

-- TABLE patients_insurer (10 columns)
  1   id                       bigint unsigned              NO   NULL                     auto_increment
  2   pasien_id                bigint unsigned              NO   NULL                     (none)
  3   penjamin_id              smallint unsigned            NO   NULL                     (none)
  4   nomor_peserta            varchar(30)                  NO   NULL                     (none)
  5   kelas_rawat              enum('kelas_1','kelas_2','kelas_3') YES  NULL                     (none)
  6   faskes_rujukan_id        bigint unsigned              YES  NULL                     (none)
  7   masa_berlaku_akhir       date                         YES  NULL                     (none)
  8   status_aktif             tinyint(1)                   NO   1                        (none)
  9   file_kartu               varchar(500)                 YES  NULL                     (none)
  10  dibuat_at                timestamp                    NO   CURRENT_TIMESTAMP        DEFAULT_GENERATED

TOTAL COLUMNS ACROSS THE 8 TABLES: 95
```

### 3.1 The specific traps the task called out, each asserted individually

| Assertion | Observed | DDL line |
|---|---|---|
| `golongan_darah_id` is **TINYINT** unsigned (not BIGINT) | `tinyint unsigned` | `:228` |
| `agama_id` is **TINYINT** unsigned | `tinyint unsigned` | `:230` |
| `pendidikan_id` is **TINYINT** unsigned | `tinyint unsigned` | `:231` |
| `status_pernikahan_id` is **TINYINT** unsigned | `tinyint unsigned` | `:233` |
| `tinggi_badan_cm` scale **1** | `decimal(5,1)` | `:243` |
| `berat_badan_kg` scale **2** | `decimal(5,2)` | `:244` |
| `jenis_kelamin` enum order `'L','P'` | `enum('L','P')` | `:225` |
| `rhesus` enum order + default | `enum('positif','negatif','tidak_diketahui')` DEFAULT `'tidak_diketahui'` | `:229` |
| `dihapus_at` is **timestamp**, not datetime | `timestamp`, nullable YES | `:249` |
| `suhu` / `tinggi_cm` / `berat_kg` / `glukosa_darah` scales | `decimal(4,1)` / `decimal(5,1)` / `decimal(5,2)` / `decimal(6,1)` | `:319,322,323,324` |
| `sumber` enum order | `enum('mandiri','dokter','perawat','iot_device')` | `:325` |
| `master_penjamin.id` is **SMALLINT** unsigned AI | `smallint unsigned` / `auto_increment` | `:334` |
| `tahun_terdiagnosis` is **YEAR** | `year` | `:292` |
| `spo2` is TINYINT unsigned, not boolean | `tinyint unsigned` | `:321` |
| `is_meninggal` / `status_aktif` default | `DEFAULT '0'` / `DEFAULT '1'`, `NOT NULL` | `:245,337,348` |

`is_meninggal` and both `status_aktif` columns print `DEFAULT '0'` / `DEFAULT '1'`
quoted in `information_schema` while the DDL writes it unquoted — the documented
MySQL-8 default-quoting fold in `docs/schema-notes.md`, and a real reason not to treat
`information_schema` output as byte-comparable to the DDL.

### 3.2 Index set (`information_schema.STATISTICS`)

```
-- patients
   idx_pasien_lahir                     INDEX   (tanggal_lahir)              <- NAMED, from :255
   patients_religion_id_foreign         INDEX   (religion_id)
   patients_blood_type_id_foreign       INDEX   (blood_type_id)
   patients_nik_unique                  INDEX   (nik)
   patients_ihs_number_unique           INDEX   (ihs_number)
   patients_medrec_number_unique        INDEX   (medrec_number)
   patients_education_id_foreign        INDEX   (education_id)
   patients_marital_status_id_foreign   INDEX   (marital_status_id)
   patients_user_id_unique              INDEX   (user_id)
   PRIMARY                              INDEX   (id)                         <- implied

-- patients_allergies
   patients_allergies_patient_id_foreign  INDEX (patient_id)                 <- implied by the FK
   PRIMARY                                INDEX (id)

-- patients_family
   patients_family_relationship_id_foreign  INDEX (relationship_id)          <- implied by the FK
   patients_family_patient_id_foreign       INDEX (patient_id)               <- implied by the FK
   PRIMARY                                   INDEX (id)

-- patients_immunizations
   patients_immunizations_patient_id_foreign  INDEX (patient_id)
   PRIMARY                                      INDEX (id)

-- patients_insurer
   patients_insurer_patient_id_foreign   INDEX   (patient_id)                 <- implied by the FK
   PRIMARY                              INDEX   (id)
   uq_peserta                           INDEX   (insurer_id, member_number)   <- NAMED, from :353

-- patients_disease_history
   idx_icd10                              INDEX (icd10_code)                 <- NAMED, from :297
   patients_disease_history_patient_id_foreign  INDEX (patient_id)
   PRIMARY                                     INDEX (id)

-- patients_tanda_vital
   idx_vital_pasien                        INDEX (patient_id, measured_at)   <- NAMED, from :329
   PRIMARY                                   INDEX (id)

-- master_insurer
   PRIMARY                                   INDEX (id)
```

Notes:

- **`uq_peserta` round-trips its exact SQL name** on `(penjamin_id, nomor_peserta)`.
  Named keys are the only ones the verifier compares by name on
  `(TABLE_NAME, INDEX_NAME)`, so the spelling is load-bearing — and it is present.
- **`idx_icd10` present on `patients_disease_history`**, and the same name still exists
  on `master_icd10` from batch A (`idx_icd10 (kode)`). Two tables, one name: legal in
  MySQL, keyed separately by the verifier. Nothing was "corrected" from `:291` to
  `:297` (the plan contradicts itself here and `docs/schema-notes.md` records that the
  authoritative index wins).
- **`idx_vital_pasien (patient_id, measured_at)` satisfies InnoDB's implicit
  FK-support index for the `patient_id` foreign key**, so MySQL created no second index
  — visible in the absence of any `patients_tanda_vital_patient_id_foreign` KEY line.
- The four `patients_*_id_foreign` KEY lines on `patients` are InnoDB's implicit
  FK-support indexes (there is no covering index in the DDL). Per `27c6ca8` the differ
  treats these as implied rather than `extra_index` drift — and indeed the 8-table run
  reported **0** index discrepancies. I did **not** edit a migration to silence them.

### 3.3 Present foreign keys (13) and composite primary keys

```
  patients                          patients_religion_id_foreign            religion_id          -> master_religion              (id)  ON DELETE NO ACTION
  patients                          patients_blood_type_id_foreign         blood_type_id        -> master_blood_type             (id)  ON DELETE NO ACTION
  patients                          patients_education_id_foreign          education_id         -> master_education             (id)  ON DELETE NO ACTION
  patients                          patients_marital_status_id_foreign     marital_status_id    -> master_marital_status        (id)  ON DELETE NO ACTION
  patients                          patients_user_id_foreign               user_id              -> users                         (id)  ON DELETE NO ACTION
  patients_allergies                patients_allergies_patient_id_foreign  patient_id           -> patients                      (id)  ON DELETE CASCADE
  patients_family                   patients_family_relationship_id_foreign relationship_id    -> master_relationship          (id)  ON DELETE NO ACTION
  patients_family                   patients_family_patient_id_foreign     patient_id           -> patients                      (id)  ON DELETE CASCADE
  patients_immunizations            patients_immunizations_patient_id_foreign patient_id       -> patients                      (id)  ON DELETE CASCADE
  patients_insurer                  patients_insurer_patient_id_foreign    patient_id           -> patients                      (id)  ON DELETE CASCADE
  patients_insurer                  patients_insurer_insurer_id_foreign    insurer_id           -> master_insurer                (id)  ON DELETE NO ACTION
  patients_disease_history          patients_disease_history_patient_id_foreign patient_id    -> patients                      (id)  ON DELETE CASCADE
  patients_tanda_vital              patients_tanda_vital_patient_id_foreign patient_id          -> patients                      (id)  ON DELETE CASCADE
  TOTAL FKs ON THE 8 TABLES: 13
```

All six `pasien_id` child FKs carry `ON DELETE CASCADE` exactly as the DDL writes them
(`:270, :283, :296, :309, :328, :351`); all five master/`users` FKs carry MySQL's
implicit `NO ACTION`, which the differ folds on both sides.

```
role_permissions PRIMARY (2): role_id, permission_id
user_roles         PRIMARY (2): user_id, role_id
patients_insurer   PRIMARY (1): id
```

Batch B's two composite PKs survived, and `patients_insurer` has the ordinary surrogate
`id` primary key with `uq_peserta` as a separate unique index.

---

## 4. `SHOW CREATE TABLE` — the four required tables, verbatim

```
=============== SHOW CREATE TABLE telemedisin_db.pasien ===============
CREATE TABLE `patients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `nomor_rm` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nomor rekam medis aplikasi: RM-YYYYMM-XXXXXX',
  `nik` char(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP',
  `nomor_kk` char(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nomor_ihs_satusehat` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nomor Induk Satu Sehat (Kemenkes)',
  `jenis_kelamin` enum('L','P') COLLATE utf8mb4_unicode_ci NOT NULL,
  `tanggal_lahir` date NOT NULL,
  `tempat_lahir` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `golongan_darah_id` tinyint unsigned DEFAULT NULL,
  `rhesus` enum('positif','negatif','tidak_diketahui') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tidak_diketahui',
  `agama_id` tinyint unsigned DEFAULT NULL,
  `pendidikan_id` tinyint unsigned DEFAULT NULL,
  `pekerjaan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_pernikahan_id` tinyint unsigned DEFAULT NULL,
  `alamat_lengkap` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `provinsi_id` tinyint unsigned DEFAULT NULL,
  `kabupaten_kota_id` smallint unsigned DEFAULT NULL,
  `kecamatan_id` smallint unsigned DEFAULT NULL,
  `kelurahan_id` mediumint unsigned DEFAULT NULL,
  `rt` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rw` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kode_pos` char(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `catatan_alergi` text COLLATE utf8mb4_unicode_ci,
  `tinggi_badan_cm` decimal(5,1) DEFAULT NULL,
  `berat_badan_kg` decimal(5,2) DEFAULT NULL,
  `is_meninggal` tinyint(1) NOT NULL DEFAULT '0',
  `tanggal_meninggal` date DEFAULT NULL,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diubah_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `dihapus_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `patients_user_id_unique` (`user_id`),
  UNIQUE KEY `patients_medrec_number_unique` (`medrec_number`),
  UNIQUE KEY `patients_nik_unique` (`nik`),
  UNIQUE KEY `patients_ihs_number_unique` (`ihs_number`),
  KEY `patients_blood_type_id_foreign` (`blood_type_id`),
  KEY `patients_religion_id_foreign` (`religion_id`),
  KEY `patients_education_id_foreign` (`education_id`),
  KEY `patients_marital_status_id_foreign` (`marital_status_id`),
  KEY `idx_pasien_lahir` (`date_of_birth`),
  CONSTRAINT `patients_religion_id_foreign` FOREIGN KEY (`religion_id`) REFERENCES `master_religion` (`id`),
  CONSTRAINT `patients_blood_type_id_foreign` FOREIGN KEY (`blood_type_id`) REFERENCES `master_blood_type` (`id`),
  CONSTRAINT `patients_education_id_foreign` FOREIGN KEY (`education_id`) REFERENCES `master_education` (`id`),
  CONSTRAINT `patients_marital_status_id_foreign` FOREIGN KEY (`marital_status_id`) REFERENCES `master_marital_status` (`id`),
  CONSTRAINT `patients_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Criterion 4 receipts, read straight off the above:**

- `` `dihapus_at` timestamp NULL DEFAULT NULL `` — **present**, and the string
  `datetime` appears **nowhere** in the table. It is emitted by
  `$table->softDeletes('dihapus_at')`, not by a hand-rolled `dateTime()`.
- `` KEY `idx_pasien_lahir` (`date_of_birth`) `` — present, exact name.
- `diubah_at` carries `ON UPDATE CURRENT_TIMESTAMP` from the raw
  `DB::statement('ALTER TABLE patients MODIFY diubah_at …')`, which is the only way to
  express it in Laravel 13.
- `nik` is `char(16)` and **kept** `char(16)`. I did not widen it. See §11.
- All four master FKs are `tinyint unsigned` in the column list, and the four
  `CONSTRAINT` lines reference the TINYINT master tables.

```
=============== SHOW CREATE TABLE telemedisin_db.pasien_tanda_vital ===============
CREATE TABLE `patients_tanda_vital` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint unsigned NOT NULL,
  `rekam_medis_id` bigint unsigned DEFAULT NULL,
  `systolic` smallint unsigned DEFAULT NULL,
  `diastolic` smallint unsigned DEFAULT NULL,
  `pulse` smallint unsigned DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `respiratory_rate` smallint unsigned DEFAULT NULL,
  `spo2` tinyint unsigned DEFAULT NULL,
  `height_cm` decimal(5,1) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `blood_glucose` decimal(6,1) DEFAULT NULL,
  `source` enum('mandiri','dokter','perawat','iot_device') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mandiri',
  `measured_at` datetime NOT NULL,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vital_pasien` (`patient_id`,`measured_at`),
  CONSTRAINT `patients_tanda_vital_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Criterion 3 receipt:** `` `rekam_medis_id` bigint unsigned DEFAULT NULL `` is
**present**, and the table has **exactly one** `CONSTRAINT` line — the `patient_id`
one. There is no `FOREIGN KEY (rekam_medis_id)`, and no `KEY (rekam_medis_id)` either,
so nothing in the schema even hints at the constraint until todo 18. §3.4 proves the
absence from `REFERENTIAL_CONSTRAINTS` directly, and §0.1 proves the constraint cannot
be created yet.

```
=============== SHOW CREATE TABLE telemedisin_db.pasien_penjamin ===============
CREATE TABLE `patients_insurer` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint unsigned NOT NULL,
  `insurer_id` smallint unsigned NOT NULL,
  `member_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '13 digit untuk BPJS',
  `care_class` enum('kelas_1','kelas_2','kelas_3') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `faskes_rujukan_id` bigint unsigned DEFAULT NULL COMMENT 'Faskes tingkat 1 (untuk BPJS)',
  `valid_until` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `card_file` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_peserta` (`insurer_id`,`member_number`),
  KEY `patients_insurer_patient_id_foreign` (`patient_id`),
  CONSTRAINT `patients_insurer_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patients_insurer_insurer_id_foreign` FOREIGN KEY (`insurer_id`) REFERENCES `master_insurer` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`UNIQUE KEY uq_peserta (insurer_id, member_number)` — the SQL's exact name.
`faskes_rujukan_id` is a bare nullable `bigint unsigned` with **no** `CONSTRAINT` line
and **no** `KEY` line. Note there is no `patients_insurer_insurer_id_foreign` KEY
either: the `uq_peserta` leftmost prefix covers that FK, so InnoDB created nothing.

```
=============== SHOW CREATE TABLE telemedisin_db.pasien_riwayat_penyakit ===============
CREATE TABLE `patients_disease_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint unsigned NOT NULL,
  `type` enum('pribadi','keluarga') COLLATE utf8mb4_unicode_ci NOT NULL,
  `disease_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icd10_code` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diagnosis_year` year DEFAULT NULL,
  `status` enum('aktif','kronis','sembuh') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `patients_disease_history_patient_id_foreign` (`patient_id`),
  KEY `idx_icd10` (`icd10_code`),
  CONSTRAINT `patients_disease_history_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`` KEY `idx_icd10` (`icd10_code`) `` present, and **no** `CONSTRAINT` touching
`icd10_code` — the bare-indexed, deliberately-unconstrained column.

### 3.4 FK-absence proofs, from `information_schema.REFERENTIAL_CONSTRAINTS`

Each of these is a count against the live schema, not an inference from `SHOW CREATE`:

```
  [0] patients_tanda_vital.rekam_medis_id  -> any FK at all  -> OK (0 = absent, as required)
  [0] constraint named fk_vital_rm anywhere  -> OK (0 = absent, as required)
  [0] patients_disease_history.icd10_code -> any FK at all  -> OK (0 = absent, as required)
  [0] ANY FK referencing master_icd10 (code)  -> OK (0 = absent, as required)
  [0] ANY FK referencing faskes  -> OK (0 = absent, as required)
  [0] ANY FK referencing rekam_medis  -> OK (0 = absent, as required)
  [0] patients_insurer.faskes_rujukan_id -> any FK at all  -> OK (0 = absent, as required)
  [0] patients_allergies.recorded_by_user_id -> any FK at all  -> OK (0 = absent, as required)
  FK-ABSENCE PROBES FAILED: 0

-- referenced tables that DO NOT EXIST YET (why the two constraints are deferred) --
   rekam_medis: ABSENT
   faskes: ABSENT
   rekam_medis_diagnosa: ABSENT
   dokter: ABSENT
   booking: ABSENT
```

Identical output from `telemedisin_db` and `telemedisin_db_test`. So the last row —
`pasien_alergi.dicatat_oleh_user_id` (`:281`) has no FK — is proven at the metadata
level, not just by reading the DDL back.

---

## 5. Data safety — exact counts

```
  db_simprapkl               tables=26   views=0    rows=111    migrations_ledger=22    non-empty={"divisi":2,"group":7,"jenis_kegiatan":6,"kriteria_penilaian":7,"migrations":22,"notifications":1,"periode_pkl":1,"periode_pkl_user":17,"presensi":1,"presensi_setting":1,"presensi_status":10,"program_keahlian":2,"sekolah":6,"user":28}
  gawaiseken                 tables=18   views=0    rows=81     migrations_ledger=18    non-empty={"cache":1,"categories":11,"migrations":18,"product_images":20,"products":20,"sessions":2,"user_profiles":4,"users":5}
  laravel                    tables=5    views=0    rows=4      migrations_ledger=4     non-empty={"migrations":4}
  manajemen-surat             tables=0    views=0    rows=0      migrations_ledger=-     non-empty={}
  mysql                      tables=37   views=0    rows=5302   migrations_ledger=-     (system)
  performance_schema         tables=111  views=0    rows=304019 migrations_ledger=-     (system)
  sehatly                    tables=10   views=0    rows=6      migrations_ledger=5     non-empty={"migrations":5,"sessions":1}
  sys                        tables=1    views=100  rows=6      migrations_ledger=-     (system)
  telemedisin_db             tables=34   views=0    rows=30     migrations_ledger=30    non-empty={"migrations":30}
  telemedisin_db_test        tables=34   views=0    rows=30     migrations_ledger=30    non-empty={"migrations":30}
  trading_journal            tables=12   views=0    rows=22     migrations_ledger=6     non-empty={"accounts":2,"cache":2,"migrations":6,"sessions":4,"strategies":2,"trades":5,"users":1}
  ukk                        tables=12   views=0    rows=17     migrations_ledger=6     non-empty={"feedback":2,"kategori":2,"migrations":6,"pengaduan":2,"sessions":3,"users":2}
  ukk_pengaduan_sekolah      tables=15   views=0    rows=41     migrations_ledger=9     non-empty={"cache":2,"jurusans":2,"kategoris":9,"kelas":4,"migrations":9,"pengaduans":2,"sessions":4,"tahun_ajarans":2,"tanggapans":2,"users":5}
```

- **`telemedisin_db`: 34 tables, ZERO domain rows.** The only non-empty table is
  `migrations` with 30 rows (26 → 30 is exactly the 8 batch-C migrations… 22 + 8 = 30).
  No `pasien`, `pasien_penjamin` or any other table holds a single row.
- **`telemedisin_db_test`: 34 tables, ZERO domain rows**, ledger 30, same
  `migrations`-only non-empty set. Migrated separately because PHPUnit points
  `DB_DATABASE` at it via `phpunit.xml:26-27`; the command was run with
  `$env:DB_DATABASE="telemedisin_db_test"` and `db:show` confirmed
  `Database .. telemedisin_db_test` before `migrate:fresh` ran.
- **`sehatly`: 10 tables, UNTOUCHED.** Still `cache, cache_locks, failed_jobs,
  job_batches, jobs, migrations, passkeys, password_reset_tokens, sessions, users`
  with its original **5** ledger rows (`0001_01_01_000000_create_users_table`,
  `0001_01_01_000001_create_cache_table`, `0001_01_01_000002_create_jobs_table`,
  `2024_01_01_000000_create_passkeys_table`,
  `2025_08_14_170933_add_two_factor_columns_to_users_table`) and its pre-existing 1
  `sessions` row. Identical to the pre-task baseline. No statement in this task ever
  named it.
- **Every unrelated application database is byte-unchanged**: `db_simprapkl` (26),
  `gawaiseken` (18), `laravel` (5), `manajemen-surat` (0), `trading_journal` (12),
  `ukk` (12), `ukk_pengaduan_sekolah` (15) — all counts match the pre-task baseline and
  all were touched by `SELECT` only.

Schema fingerprint (sha256 over `SHOW CREATE TABLE` for every base table, with
`AUTO_INCREMENT=N` stripped so an insert cycle cannot perturb it):

```
database : telemedisin_db
tables   : 34
sha256   : f555d20765167b4c8a0b6f7e074b19d6462d916efde34842048656e46e7c9746
bytes    : 21886
database : telemedisin_db_test
tables   : 34
sha256   : f555d20765167b4c8a0b6f7e074b19d6462d916efde34842048656e46e7c9746
bytes    : 21886
```

Byte-identical. The dev and test schemas cannot have drifted apart.

---

## 6. Rollback cycle and convergence (`repeated_interruptions`)

`down()` correctness, children before parents, on `telemedisin_db`:

```
$ php artisan migrate:rollback --step=8 --no-interaction

 INFO Rolling back migrations.

 2026_10_01_000027_pasien_penjamin_table .. 12.93ms DONE
 2026_10_01_000026_master_penjamin_table .. 4.67ms DONE
 2026_10_01_000025_pasien_tanda_vital_table .. 9.06ms DONE
 2026_10_01_000024_pasien_imunisasi_table .. 9.01ms DONE
 2026_10_01_000023_pasien_riwayat_penyakit_table .. 7.94ms DONE
 2026_10_01_000022_pasien_alergi_table .. 9.01ms DONE
 2026_10_01_000021_pasien_anggota_keluarga_table .. 10.03ms DONE
 2026_10_01_000020_pasien_table .. 11.61ms DONE
exit 0
```

Strict reverse creation order — the five `pasien` children drop before `pasien` itself,
`patients_insurer` before `master_insurer`, and no MySQL 3730 (drop table referenced
by a foreign key) occurred. Post-rollback fingerprint: `26` tables,
`0c8919345c20e8cca4c7ac64e028476131b1117f3f35eae3c241f8c4425f399c`.

Re-apply, then prove `migrate` and `migrate:fresh` converge on the same schema:

```
$ php artisan migrate --no-interaction          # exit 0
$ php artisan migrate:fresh --no-interaction    # exit 0

after rollback+migrate:  tables=34  sha256=f555d20765167b4c8a0b6f7e074b19d6462d916efde34842048656e46e7c9746
after migrate:fresh:     tables=34  sha256=f555d20765167b4c8a0b6f7e074b19d6462d916efde34842048656e46e7c9746
```

**Identical.** `migrate` after a `--step=8` rollback reproduces `migrate:fresh` exactly.

### 6.1 A real interrupted `migrate`, and convergence from it

Not a hypothetical — it happened during this task and is worth recording. While setting
up the negative QA I piped `php artisan migrate:fresh` through
`Select-Object -First 3`; PowerShell closed the pipe, which terminated artisan
**mid-migration**. Observed:

```
 Dropping all tables .. 179.42ms DONE
 INFO Preparing database.
 Creating migration table .. 20.65ms DONE
MIGRATE_EXIT=-1
```

and the next command found a **partial schema**:

```
 counts tables=1 views=0 columns=3 indexes=1 foreign_keys=0 checks=0
 missing_table patients expected: 31 columns, 6 indexes, 5 foreign keys, 0 checks | actual: -
```

One table, three columns, eight batch-C migrations never applied. The lesson is
operational and I am recording it as a finding: **never truncate an artisan pipeline
with `Select-Object -First`/`-Last`; redirect to a file with `>` and read the file
afterwards.** Every later command in this task used redirection.

Convergence from the partial state, with the drift still injected (so this is a
*worse* starting point than the real one):

```
$ php artisan migrate:fresh --no-interaction      # exit 0
  2026_10_01_000020_pasien_table .. 321.21ms DONE
  ... through 2026_10_01_000027_pasien_penjamin_table .. 123.07ms DONE
```

and after restoring `tinggi_badan_cm` a further `migrate:fresh` reproduced fingerprint
`f555d207…` for the last time. The final committed state was produced by a
`migrate:fresh` that ran to completion from scratch, so no partial artifact survives.

---

## 7. `docs/schema-notes.md` — the three additions

Appended as a new `## Batch-C deferred and deliberately unconstrained columns (todo 9)`
section, immediately after the batch-B limitations block. **Deliberately written as
prose `- ` bullets, not as markdown table rows**, for a reason that is load-bearing
rather than cosmetic:

`ExtraTableRegistry::fromMarkdown()` (`app/Support/Schema/ExtraTableRegistry.php:42`)
scans **every line of the whole file** for
`/^\|\s*`([A-Za-z0-9_]+)`\s*\|(.*)\|\s*$/`. A new row beginning `| `pasien_tanda_vital.rekam_medis_id` |`…
would be swallowed as a registered extra table and would **forgive a table that is
really drift**. The file's own registry section already carries that warning
("Do not add any other markdown table in this file whose first cell is a backticked
identifier"). None of the three is an extra *table* anyway — all three are columns the
SQL itself declares, so the extra-table registry is the wrong instrument for them.

The section records, each with a one-line justification:

- **`patients_tanda_vital.rekam_medis_id`** — column present, foreign key deferred to
  migration `2026_10_01_000076` per SQL section `[14]` (`:1161-1163`), which adds
  `CONSTRAINT fk_vital_rm … ON DELETE SET NULL`. Justification: `rekam_medis` is SQL
  table 42 (batch G, todo 13) and this column is created at position 25, so declaring
  the constraint here fails with MySQL 1824. Notes that until todo 18 the column has no
  row in `REFERENTIAL_CONSTRAINTS` and that that absence is the correct state, and that
  adding the constraint early is `extra_foreign_key` drift.
- **`patients_insurer.faskes_rujukan_id`** — column present as a nullable unsigned
  `BIGINT`, foreign key deferred because `faskes` is SQL table 28 (`:360`, batch D,
  todo 10). Records explicitly that **todo 18 owns this** — do not re-declare it in a
  new migration, do not widen the column to `foreignId()` semantics.
- **The bare-indexed `patients_disease_history.icd10_code`** — indexed, no FK, by
  design: the DDL declares `INDEX idx_icd10 (icd10_code)` (`:297`) and no
  `FOREIGN KEY` even though `master_icd10` exists from batch A and the column is
  exactly the master's `code` type, so `->constrained('master_icd10','code')` would
  work and still be drift. Extends the same reasoning to
  `patients_allergies.recorded_by_user_id` (`:281`, no FK). Notes that `idx_icd10` is
  reused as a name on two tables (`:119` and `:297`) and that the verifier keys on
  `(TABLE_NAME, INDEX_NAME)`.

The section closes by restating that the registry still has exactly **seven** entries
after todo 9, because no batch-C migration creates a table outside the 75-table
contract — and that the count is re-derived on every test run rather than pinned.

**Registry enforcement contract preserved.** The `## Why this file exists` section is
untouched: documented extra = informational, undocumented extra = drift exit 1,
missing/empty registry = exit 2. Confirmed live: the command still prints
`notes registry docs/schema-notes.md (7 registered extra tables)`, and the derived
registry test reports `registry=7`.

---

## 8. Negative / failure QA — the DECIMAL-scale probe

`pasien` is the widest table in the batch, so `tinggi_badan_cm` is the column a
"harmonise the decimals" refactor would flatten. The probe:

1. Change `decimal('tinggi_badan_cm', 5, 1)` → `decimal('tinggi_badan_cm', 5, 2)`.
2. `migrate:fresh`, assert the live type really changed.
3. `verify-schema --tables=pasien` → **must exit 1 and name that exact column**.
4. Restore the file, `migrate:fresh`, assert the live type is back to `decimal(5,1)`.
5. `verify-schema --tables=pasien` → **must exit 0 again**.

`berat_badan_kg` stays at `DECIMAL(5,2)` at every step. That is the point: a "fix"
that flattened **both** columns to the same scale would sail through this probe while
being wrong, so the sibling column is left alone and asserted separately.

### Step 1-2 — drift injected, live schema shows it

```
$ (edit 2026_10_01_000020_pasien_table.php)
- $table->decimal('tinggi_badan_cm', 5, 1)->nullable();
+ $table->decimal('tinggi_badan_cm', 5, 2)->nullable();
  $table->decimal('berat_badan_kg', 5, 2)->nullable();      <- deliberately unchanged

$ php artisan migrate:fresh --no-interaction
exit 0

$ SHOW CREATE TABLE patients
  `tinggi_badan_cm` decimal(5,2) DEFAULT NULL,
  `berat_badan_kg`  decimal(5,2) DEFAULT NULL,     <- sibling untouched throughout
```

### Step 3 — verifier flags it, by name. RAW OUTPUT:

```
$ php artisan sehatly:verify-schema --tables=pasien
 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables)
 scope patients

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: bookings.status (515-516), home_care_orders.status (1104-1105), invoices.status (947-948), bpjs_claims.status (1022-1023), consultations.status (542-543), consultation_chat.message_type (568-569), lab_requests.status (884-885), medicines.dosage_form (713-714), pdp_consents.type (1137-1138), medicine_orders.status (810-811), prescriptions.status (751-752)
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on patients_tanda_vital (line 1161)

 Live schema
 counts tables=34 views=0 columns=217 indexes=80 foreign_keys=23 checks=0
 information_schema columns=217 indexes=80 foreign_keys=23 checks=0

 Discrepancies: 1 (1 drift, 0 informational)
 column_type patients.tinggi_badan_cm expected: decimal(5,1) | actual: decimal(5,2)

 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.
exit 1
```

It names `patients.tinggi_badan_cm`, the kind (`column_type`), both the expected and
the actual type, and it does **not** name `berat_badan_kg` — the verifier is
column-precise, not table-precise.

### Step 4 — restored exactly, proven by hash

```
$ Copy-Item <pristine copy> database/migrations/2026_10_01_000020_pasien_table.php
RESTORED 104: $table->decimal('tinggi_badan_cm', 5, 1)->nullable();
RESTORED 105: $table->decimal('berat_badan_kg', 5, 2)->nullable();
SHA256_AFTER_RESTORE=4511C7D4FB66C35D12B69F5E4BA481A68504371E3D663DFBA721C4747FEF65BB
SHA256_PRISTINE  =4511C7D4FB66C35D12B69F5E4BA481A68504371E3D663DFBA721C4747FEF65BB
```

Byte-identical to the pre-probe file — the restore is a file copy, not a re-typed edit,
so no whitespace or comment drift was introduced.

```
$ php artisan migrate:fresh --no-interaction
exit 0
$ SHOW CREATE TABLE patients
  `tinggi_badan_cm` decimal(5,1) DEFAULT NULL,     <- restored
  `berat_badan_kg`  decimal(5,2) DEFAULT NULL,     <- never touched
```

### Step 5 — verifier green again. RAW OUTPUT:

```
$ php artisan sehatly:verify-schema --tables=patients
 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables)
 scope patients

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on patients_tanda_vital (line 1161)

 Live schema
 counts tables=34 views=0 columns=217 indexes=80 foreign_keys=23 checks=0
 information_schema columns=217 indexes=80 foreign_keys=23 checks=0

 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.
 PASS — 75 tables, 2 views verified. Nothing was written.
exit 0
```

(The `PASS` banner's "75 tables, 2 views" is the A.7 trap and is meaningless here — the
authoritative signals are `Discrepancies: 0` and exit 0, and the echoed `scope` line
reads `patients` with no `unknown_requested_table` row.)

---

## 9. Adversarial classes

### 9.1 `misleading_success_output` — **APPLIES, probed**

`migrate:fresh` exiting 0 proves nothing about parity; it only proves the DDL was
accepted. Probe: every column of all 8 tables was read from `information_schema` and
compared **line by line** against `telemedicine_test.sql:218-358` by hand, not through
the differ — §3 is that dump, 95 columns, with §3.1 listing each named trap
individually. Specifically confirmed:

- the four TINYINT master FK widths (`golongan_darah_id`, `agama_id`, `pendidikan_id`,
  `status_pernikahan_id` — all `tinyint unsigned`; using `foreignId()` would have been
  `bigint unsigned` **and** an InnoDB type-mismatch error);
- `tinggi_badan_cm decimal(5,1)` versus `berat_badan_kg decimal(5,2)`, and the same
  pair in `patients_tanda_vital` (`tinggi_cm decimal(5,1)`, `berat_kg decimal(5,2)`);
- both `pasien` ENUM value lists in order (`enum('L','P')`,
  `enum('positif','negatif','tidak_diketahui')`), plus `tipe_alergen`, `keparahan`,
  `tipe`, `status`, `sumber`, `master_penjamin.tipe` and `kelas_rawat` — 9 ENUMs, all
  in the SQL's order, ENUM order being the sort index;
- `dihapus_at` is `timestamp`, and the string `datetime` does not occur in the
  `patients` DDL;
- FK **absence** where the SQL has none, proven from `REFERENTIAL_CONSTRAINTS` with 8
  separate zero-count probes (§3.4), not read off the DDL.

Batch A and B did not regress — spot-checks from the live schema:

```
  master_provinsi        id    tinyint unsigned   nullable=NO   extra=auto_increment
  master_agama           id    tinyint unsigned   nullable=NO   extra=(none)          <- no AI
  master_blood_type      id    tinyint unsigned   nullable=NO   extra=(none)          <- no AI
  master_education       id    tinyint unsigned   nullable=NO   extra=(none)          <- no AI
  master_marital_status  id    tinyint unsigned   nullable=NO   extra=(none)          <- no AI
  master_relationship    id    tinyint unsigned   nullable=NO   extra=(none)          <- no AI
  master_icd10           code  varchar(8)         nullable=NO   extra=(none)
  users                  dibuat_at    timestamp   nullable=NO   extra=DEFAULT_GENERATED
  users                  dihapus_at   timestamp   nullable=YES  extra=(none)          <- timestamp, not datetime
  users                  diubah_at    timestamp   nullable=NO   extra=DEFAULT_GENERATED on update CURRENT_TIMESTAMP
  patients_insurer       PRIMARY (1): id
role_permissions   PRIMARY (2): role_id, permission_id
user_roles         PRIMARY (2): user_id, role_id
```

and the exact AUTO_INCREMENT accounting rather than a vibe:

```
ASSERT batch A == 6/5: PASS
  batch A WITH auto_increment id (6/11, expected 6): master_icd10, master_icd9cm, master_kabupaten_kota, master_kecamatan, master_kelurahan, master_provinsi
  batch A WITHOUT auto_increment id (5/11, expected 5): master_agama, master_blood_type, master_education, master_marital_status, master_relationship
  batch B tables present: 8/8
  batch C tables present: 8/8
  batch C WITH auto_increment id (8/8, expected 8): master_insurer, patients, patients_allergies, patients_family, patients_immunizations, patients_insurer, patients_disease_history, patients_tanda_vital
```

`master_agama` still `tinyint unsigned` with **no** AUTO_INCREMENT ✓.
`users.dihapus_at` still `timestamp` ✓. Batch B's two composite PKs intact ✓.

**The probe found a real thing, and it was the exit code, not the schema.** A green
`migrate:fresh` sat next to a red `verify-schema` (§0). The lesson is that criterion 1
and criterion 2 are independent signals and only the second one has any parity content.

### 9.2 `stale_state` — **APPLIES, probed**

- `bootstrap/cache/config.php` — **`False`**, i.e. it does not exist. Checked directly
  with `Test-Path` rather than by looking for a cache-hit speedup.
- `php artisan config:clear` run **before** the first `migrate:fresh` (exit 0) and again
  **after** the final `verify-schema` (exit 0), both printing
  `INFO Configuration cache cleared successfully.`
- Filename ordering: my 8 files sort **after** batch B's `000019`, and
  `2026_10_01_000020_pasien_table.php` is the lowest of mine. The full sorted listing
  (30 files) is in §11. `2026_10_01_000020` → `_000027`, no gaps, no duplicates, no
  renumbering of an existing row.
- Because a config cache would have pinned `DB_DATABASE=telemedisin_db` and silently
  redirected the `telemedisin_db_test` migration, I verified the override landed before
  running it: `php artisan db:show` printed `Database .. telemedisin_db_test` and
  `Open Connections .. 1` in the same command as the `migrate:fresh`.
- Environment note from A.5, checked: `GIT_INDEX_FILE = []` (unset) and
  `git worktree list` shows the expected linked worktree at
  `.kilo/worktrees/spectacular-melon` alongside the main one. I did not touch either
  index.

### 9.3 `dirty_worktree` — **APPLIES, probed**

`git status --porcelain` before staging:

```
 M .omo/plans/sehatly-telemedicine-platform.md        <- ORCHESTRATOR's; left alone
 M docs/schema-notes.md                               <- MINE
?? .omo/evidence/task-3-sehatly.md                    <- PRIOR EXECUTOR's; left alone
?? .omo/start-work/                                   <- ORCHESTRATOR's; left alone
?? database/migrations/2026_10_01_000020_pasien_table.php                  <- MINE
?? database/migrations/2026_10_01_000021_pasien_anggota_keluarga_table.php <- MINE
?? database/migrations/2026_10_01_000022_pasien_alergi_table.php          <- MINE
?? database/migrations/2026_10_01_000023_pasien_riwayat_penyakit_table.php <- MINE
?? database/migrations/2026_10_01_000024_pasien_imunisasi_table.php       <- MINE
?? database/migrations/2026_10_01_000025_pasien_tanda_vital_table.php     <- MINE
?? database/migrations/2026_10_01_000026_master_penjamin_table.php        <- MINE
?? database/migrations/2026_10_01_000027_pasien_penjamin_table.php       <- MINE
```

The three non-mine entries are exactly the ones the task flagged: the plan file is the
orchestrator's, `.omo/start-work/` is untracked and not mine, and
`.omo/evidence/task-3-sehatly.md` belongs to a prior executor. **All three were left
untouched, unstaged and uncommitted.** I read the plan but never wrote to it.

Staging used explicit pathspecs only — no `git add -A`, `.`, `-u` or `-a` — so a
mis-scoped add could not have swept the orchestrator's files in:

```
git add -- database/migrations docs/schema-notes.md .omo/evidence/task-9-sehatly.md
git commit -m "feat(db): migrate patient, allergy, history, immunisation, vital and insurer tables" -- <same paths>
```

Note that `database/migrations` as a pathspec is deliberate and safe here: the only
untracked files under it are my 8, verified by the porcelain listing above. The commit
recipe used `git commit -- <paths>` as well, so even a pre-staged foreign path could not
have been included.

No `git stash`, `checkout .`, `restore .`, `clean`, `reset`, `--amend`, `push`, and
nothing was committed to `main` (branch was `feat/sehatly-telemedicine` at both the
start and the end).

### 9.4 `hung_or_long_commands` — **APPLIES, probed**

Every artisan invocation got an explicit timeout. Longest observed: `migrate:fresh` at
**≈2.1 s** of migration work (`patients` 359.79 ms is the slowest single migration, the
`ALTER TABLE patients MODIFY diubah_at` being the expensive part); the full
`migrate:fresh` wall clock is a few seconds. Nothing approached a timeout; no
metadata-lock contention occurred at any point; no `SHOW PROCESSLIST` showed a
blocked query. `verify-schema` runs in ~1 s and is `SELECT`-only, so it cannot lock.

No process was killed. The pre-existing `mysqld` and the user's `php artisan serve` were
left running. The only processes this task terminated were the ones I started myself —
including the artisan process that a `Select-Object -First 3` accidentally closed,
which is recorded in §6.1 as a self-inflicted interruption with its recovery.

The two ALTER attempts in §0.1 failed instantly (1824 is a resolve-time error), so they
could not hold a metadata lock.

### 9.5 `repeated_interruptions` — **APPLIES, probed**

- A genuine mid-`migrate` kill occurred (§6.1), leaving a 1-table / 3-column partial
  schema. Recovery was `migrate:fresh` to convergence, and the final fingerprint
  `f555d207…` was reached from that partial state with the drift still injected — a
  strictly worse starting point than the real one.
- `migrate:rollback --step=8` then `migrate` succeeds on `telemedisin_db`, children
  before parents, exit 0 both, and converges on the same fingerprint as
  `migrate:fresh` (§6). `down()` is therefore correct, not merely non-throwing: had any
  child been dropped after its parent, MySQL 3730 would have surfaced.
- The negative QA is itself a second interrupt-and-restore cycle: migration edited,
  schema rebuilt, verifier consulted, file restored **by hash-verified copy**, schema
  rebuilt, verifier consulted. Both endpoints green (§8).

### 9.6 Ruled out, with reasons

- **`malformed_input` — N/A.** This todo authors no parser. Input handling belongs to
  `SqlSchemaParser` / `ExtraTableRegistry` (todo 6), whose malformed-input behaviour is
  already covered by two tests in `VerifySchemaCommandTest.php` ("an unparseable
  reference exits 2 with a clear message, never 0" and "a missing extra-table registry
  exits 2"), both of which pass in this run. The only input I authored is 8 migration
  files, each `php -l` clean.
- **`prompt_injection` — N/A, with the check actually performed.**
  `telemedicine_test.sql` is first-party data read as a **specification**, never as
  instructions. I read `:218-358` and `:1161-1163` as DDL. I scanned the sections I
  used for anything instruction-shaped — imperative text aimed at a reader rather than
  at a parser — and found **none**: the only prose in range is section header comments
  (`-- [3] PASIEN`, `-- Anggota keluarga (didaftarkan oleh pasien, TANPA akun sendiri)`,
  `-- 3.1 Penjamin bayar (BPJS & Asuransi)`, `-- [14] FOREIGN KEY TAMBAHAN (hindari
  ketergantungan silang saat CREATE)`) plus 5 `COMMENT` clauses, all of which describe
  the data. Nothing that reads like an instruction to an agent, and nothing acted on
  outside the DDL. The file is byte-unchanged: SHA-256
  `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, 59,604 bytes,
  matching the plan's law value.
- **`cancel_resume` — N/A.** No resumable user flow exists or is authored here; resumable
  flows are todos 20 (auth), 45 (payment) and 46 (order). A migration is idempotent
  only in the `migrate:fresh` sense, and that is what §6 exercised. The closest real
  resumability question — a half-applied batch — is `repeated_interruptions`, handled
  above.
- **`flaky_tests` — N/A, and the zero-match risk is measured.** The suite is
  deterministic: 74/74, 351 assertions, 3.1 s, on a single run, with no
  randomisation, no clock/network dependency and no parallelism. The specific risk the
  task names — a filter matching zero tests and reading as a pass — is **measured, not
  assumed**: `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` exits **1**
  with `{"result":"failed","tests":0,...,"raw":["No tests found."]}`. So a green
  `tests/Unit` result means 74 real tests ran, and the count is printed in the JSON
  summary where a drop is visible.

---

## 10. Cleanup receipts

- **Temp files: all outside the repo, all deleted.** Created in
  `C:\Users\axioo\AppData\Local\Temp\opencode\`: `t9-dbstate.php`, `t9-parity.php`,
  `t9-parity-dev.txt`, `t9-parity-test.txt`, `t9-showcreate.php`, `t9-showcreate.txt`,
  `t9-deferred-proof.php`, `t9-deferred-proof.txt`, `t9-fingerprint.php`,
  `t9-pasien-pristine.php`, `t9-negqa-migrate.txt`, `t9-negqa-showcreate.txt`,
  `t9-negqa-out.txt`, `t9-restore-migrate.txt`, `t9-restore-showcreate.txt`,
  `t9-restore-out.txt`, `t9-rollback.txt`, `t9-remigrate.txt`, `t9-converge-migrate.txt`,
  `t9-unit.txt`, `t9-zeromatch.txt`, `t9-final-migrate-fresh.txt`,
  `t9-final-verify8.txt`. The scratch directory was verified empty of `t9-*` afterwards.
  **Nothing was ever written under the repository except the 9 tracked paths in §11.**
- **No leftover process.** Nothing from this task is still running; no `mysqld` and no
  `php artisan serve` was killed, stopped or restarted.
- **`telemedicine_test.sql` byte-unchanged**: SHA-256
  `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, 59,604 bytes —
  identical to the plan's law value. Confirmed before and after the task.
- **`sehatly` still 10 tables** with its original 5 ledger rows, untouched (§5).
- **No unrelated database touched** — every other schema is byte-for-byte at its
  baseline (§5), read-only.
- **No `mobile/` directory and no `pubspec.yaml`** were created; `laravel/passkeys`,
  `config/fortify.php` and `FortifyServiceProvider` were not modified.
- **`php artisan install:api` was never run**, in any form (A.5).
- **Pint was run bare**, with no path argument (A.7):
  `vendor/bin/pint` → `{"tool":"pint","result":"passed"}`, exit 0. Not once was `pint`
  given `bootstrap` or `.`, which would have overridden its cache exclude.

---

## 11. Files created and changed

**Created (8)** — all under `database/migrations/`:

```
2026_10_01_000020_pasien_table.php
2026_10_01_000021_pasien_anggota_keluarga_table.php
2026_10_01_000022_pasien_alergi_table.php
2026_10_01_000023_pasien_riwayat_penyakit_table.php
2026_10_01_000024_pasien_imunisasi_table.php
2026_10_01_000025_pasien_tanda_vital_table.php
2026_10_01_000026_master_penjamin_table.php
2026_10_01_000027_pasien_penjamin_table.php
```

**Changed (2)**:

- `docs/schema-notes.md` — one appended section, seven lines of registry contract
  untouched (§7).
- `.omo/evidence/task-9-sehatly.md` — this file.

**Not touched, verified:** `telemedicine_test.sql` · `docs/migration-order.md` ·
`tests/**` (`git diff --stat -- tests/` empty) · `phpunit.xml` · `config/database.php` ·
`.env` / `.env.example` · `composer.json` / `composer.lock` · `bootstrap/**` · `routes/**`
· anything under `app/**` · `.omo/plans/**` · `.omo/start-work/**` ·
`.omo/evidence/task-3-sehatly.md`.

**Created no Model, Resource, Controller, seeder, factory or route.** No Model exists
for any of the 8 tables — todo 19 owns those. No table outside 20-27 was authored.

**No foreign key was added that the SQL does not have.** 13 FKs created, matching the
DDL's 13 `FOREIGN KEY` lines in `:218-358` exactly; the 14th (`fk_vital_rm`) is
deferred and recorded. And 5 absences are affirmatively recorded in `docs/schema-notes.md`.

**Nothing was created outside 20-27** except the documented deferred constraints, which
are migrations todo 18 will author.

### The `pasien.nik` decision, stated explicitly

`nik` is `char(16)`, nullable, with its UNIQUE index — **exactly as `:222` declares**,
**not widened**. AES-256-CBC ciphertext of a 16-byte NIK is 32 bytes (44 chars base64 /
64 hex), which does not fit `CHAR(16)` and raises MySQL error 1406 in strict mode. That
is a real defect, it is already recorded in the plan's "Must NOT have" list, and it is
**todo 50's** to fix (ciphertext in a `TEXT` column plus a fixed-width 16-char HMAC
carrying the unique index, which is precisely why the deterministic-encryption design
needs this index to survive). Widening the column here would have been an undocumented,
unrequested schema change to one of the most sensitive identifiers in the system, and it
would have broken the index todo 50 depends on. Left alone deliberately.

---

## 12. Commit

```
$ vendor/bin/pint
{"tool":"pint","result":"passed"}
exit 0

$ git add -- database/migrations docs/schema-notes.md .omo/evidence/task-9-sehatly.md
$ git commit -m "feat(db): migrate patient, allergy, history, immunisation, vital and insurer tables" -- database/migrations docs/schema-notes.md .omo/evidence/task-9-sehatly.md
```

Post-commit assertions are recorded in the DoneClaim: `git diff --cached --name-only`
empty, and `git show --name-only --format="" HEAD` listing only the 10 paths above
(8 migrations + `docs/schema-notes.md` + this evidence file). The orchestrator's
`.omo/plans/…` modification, `.omo/start-work/` and `.omo/evidence/task-3-sehatly.md`
are absent from the commit and remain exactly as found.
