# Task 17 — migration batch K (SQL tables 68-75), the LAST migration batch

**Branch** `feat/sehatly-telemedicine` · **Base** `dbfedb8` (todo 16) ·
**PHP** 8.4.17 (`C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64`, first line of every
shell) · **MySQL** 8.0.30 · **Laravel** v13.33.0

---

## 0. READ-ONLY LAW: `telemedicine_test.sql` unchanged

```
SHA-256 at the start of this todo:
  AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5   (matches the brief)
SHA-256 at the end of this todo:
  AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5   (identical)
1349 lines. Never opened for writing. No `Get-Content`/`Set-Content` round-trip.
```

`git status --porcelain` at the end lists `telemedicine_test.sql` as **absent** from
the modified set.

---

## 1. CITATION VERIFICATION — every `:NNN` resolved by name search, then the owning `CREATE TABLE` confirmed

Method (A.16 + A.23, both halves measured separately): walk `telemedicine_test.sql`
building a line → owning-`CREATE TABLE` map, then search for each **column, index or
constraint NAME** rather than trusting any cited line. 27 name searches.

| what was cited | cited as | resolved | owning `CREATE TABLE` | owning `CREATE TABLE` line |
| --- | --- | --- | --- | --- |
| `notifikasi` start | `:1036` | **1036** ✓ | `notifikasi` | 1036 |
| `idx_notif` | `:1045` | **1047** ✗ | `notifikasi` | 1036 |
| `ulasan_dokter` start | `:1050` | **1050** ✓ | `ulasan_dokter` | 1050 |
| `CHECK` on `rating` | `:1052-1054` | **1055-1057** ✗ | `ulasan_dokter` | 1050 |
| `konsultasi_id … UNIQUE` | `:1052` | **1052** ✓ | `ulasan_dokter` | 1050 |
| `is_anonim` | — | 1059 | `ulasan_dokter` | 1050 |
| `idx_ulasan_dokter` | — | 1065 | `ulasan_dokter` | 1050 |
| `artikel_kategori` start | `:1068` | **1068** ✓ | `artikel_kategori` | 1068 |
| `artikel` start | `:1074` | **1074** ✓ | `artikel` | 1074 |
| `artikel.reviewer_user_id` | `:1078` | **1078** ✓ | `artikel` | 1074 |
| `artikel.konten` (`LONGTEXT`) | `:1082` | **1082** ✓ | `artikel` | 1074 |
| `artikel.status` ENUM | — | 1084 | `artikel` | 1074 |
| `home_care_pesanan` start | `:1093` | **1093** ✓ | `home_care_pesanan` | 1093 |
| `tipe_layanan` ENUM | `:1098` | **1098** ✓ | `home_care_pesanan` | 1093 |
| `status` ENUM (6 values) | `:1104` | **1104** ✓ | `home_care_pesanan` | 1093 |
| `audit_log` start | `:1118` | **1118** ✓ | `audit_log` | 1118 |
| `audit_log.user_id` | `:1120` | **1120** ✓ | `audit_log` | 1118 |
| `audit_log.record_id` | `:1123` | **1123** ✓ | `audit_log` | 1118 |
| `idx_audit_user` | `:1130` | **1130** ✓ | `audit_log` | 1118 |
| `idx_audit_tabel` | `:1131` | **1131** ✓ | `audit_log` | 1118 |
| `persetujuan_pdp` start | `:1134` | **1134** ✓ | `persetujuan_pdp` | 1134 |
| `jenis` ENUM (wrapped) | `:1137-1138` | **1137-1138** ✓ | `persetujuan_pdp` | 1134 |
| `uq_consent` | `:1144` | **1144** ✓ | `persetujuan_pdp` | 1134 |
| `akses_rekam_medis_log` start | `:1147` | **1147** ✓ | `akses_rekam_medis_log` | 1147 |
| `tujuan_akses` ENUM | — | 1151 | `akses_rekam_medis_log` | 1147 |
| `fk_vital_rm` (not mine) | — | 1162 | *outside any `CREATE TABLE`* (section `[14]`) | — |

**27 citations checked, 2 wrong, both in the plan's todo-17 prose, both confirmed to
belong to the table named.** Owning `CREATE TABLE` confirmed for every one.

The `^CREATE TABLE` walk finds exactly **75** statements, and rows 68-75 of
`docs/migration-order.md` match positionally with an empty diff.

### The two errors

1. **Plan line 390** cites `INDEX idx_notif (user_id, dibaca_at)` at **`:1045`**. It
   is at **`:1047`**; `:1045` is `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`.
2. **Plan line 390** cites the three `CHECK` constraints at **`:1052-1054`**. They are
   at **`:1055`-`:1057`**. `:1052` is `konsultasi_id`, `:1053` is `pasien_id`, `:1054`
   is `dokter_id` — **the cited range contains no `CHECK` at all.** An executor
   trusting it would have looked for constraints on the wrong three columns.

Neither was fixed in the plan (orchestrator-owned, under active edit). Note the plan's
**own line-index table at line 134** carries the correct lines for all eight tables
(`1074 / 1078 / 1082`, `1093 / 1098 / 1104-1105`) — the A.16 pattern for the ninth
consecutive batch.

---

## 2. THE DDL, RE-DERIVED FROM SCRATCH

Split each `CREATE TABLE` body on commas at **paren depth 0** (ignoring commas inside
parentheses and string literals). *This is the correct model of the SQL, and getting it
wrong was my first instrument bug — see §11.*

| # | Table | `CREATE TABLE` | Cols | FK | Named idx | Inline CHECK | ENUM value counts |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 68 | `notifikasi` | `:1036-1048` | **9** | 1 | 1 | 0 | `tipe`=7 |
| 69 | `ulasan_dokter` | `:1050-1066` | **12** | 2 | 1 | **3** | — |
| 70 | `artikel_kategori` | `:1068-1072` | **3** | 0 | 0 | 0 | — |
| 71 | `artikel` | `:1074-1091` | **14** | 2 | 0 | 0 | `status`=4 |
| 72 | `home_care_pesanan` | `:1093-1112` | **14** | 3 | 0 | 0 | `tipe_layanan`=5, `status`=6 |
| 73 | `audit_log` | `:1118-1132` | **11** | 0 | 2 | 0 | `aksi`=8 |
| 74 | `persetujuan_pdp` | `:1134-1145` | **7** | 1 | 1 | 0 | `jenis`=**5** |
| 75 | `akses_rekam_medis_log` | `:1147-1155` | **5** | 2 | 0 | 0 | `tujuan_akses`=5 |

Totals: **75 columns, 11 `FOREIGN KEY` clauses, 5 named `INDEX`/`UNIQUE KEY`, 3 inline
`CHECK`.** (The 75 is a coincidence with the 75-table contract; the two are unrelated.)

### ENUMs in exact SQL order

| Table.Column | Values, in the SQL's order |
| --- | --- |
| `notifikasi.tipe` | `booking`, `pembayaran`, `resep`, `chat`, `lab`, `promo`, `sistem` |
| `artikel.status` | `draft`, `review`, `terbit`, `arsip` |
| `home_care_pesanan.tipe_layanan` | `perawat`, `fisioterapi`, `dokter`, `bidan`, `vaksinasi_rumah` |
| `home_care_pesanan.status` | `menunggu_pembayaran`, `terjadwal`, `perjalanan`, `berlangsung`, `selesai`, `dibatalkan` |
| `audit_log.aksi` | `create`, `read`, `update`, `delete`, `login`, `logout`, `download`, `export` |
| `persetujuan_pdp.jenis` | `syarat_ketentuan`, `kebijakan_privasi`, `berbagi_data_medis`, `pemasaran`, `komunikasi_tindak_lanjut` |
| `akses_rekam_medis_log.tujuan_akses` | `perawatan`, `klaim`, `audit`, `pasien_sendiri`, `kepentingan_hukum` |

`notifikasi.tipe` = **7**, `artikel.status` = **4**, `tipe_layanan` = **5**,
`home_care_pesanan.status` = **6**, `aksi` = **8**, `jenis` = **5**,
`tujuan_akses` = **5**. All counted from the file, none taken from the brief or plan.

### THE WRAPPED-ENUM CENSUS — re-derived, not borrowed

Predicate: *does any line open an `ENUM(` that its own line does not close?* Walked over
all 1349 lines.

```
wrapped ENUM value lists = 5
  :515-516   booking.status            'menunggu_pembayaran',…,'kadaluarsa')
  :713-714   master_obat.bentuk_sediaan
  :751-752   resep.status
  :947-948   invoice.status
  :1137-1138 persetujuan_pdp.jenis     'syarat_ketentuan','kebijakan_privasi','berbagi_data_medis',
                                        'pemasaran','komunikasi_tindak_lanjut') NOT NULL,
```

**`persetujuan_pdp.jenis` = 5 values, read from BOTH `:1137` and `:1138`.** The owning
`CREATE TABLE` is `persetujuan_pdp` at `:1134` — **not** `artikel_kategori` (A.23).
`artikel_kategori` is `:1068`-`:1072`, **3 columns, no ENUM**.

**`home_care_pesanan.status` at `:1104` is NOT wrapped** — confirmed by the same
predicate. `ENUM(` opens *and* closes on `:1104`; `:1105` carries only
`NOT NULL DEFAULT 'menunggu_pembayaran',`. A prior report claimed otherwise; it is wrong.

**This is a different question from the verifier's `wrapped decls 11`**, which counts
declarations whose *end line* exceeds their *start line* and is true for eleven columns.
I did not use that field as evidence for anything (A.20/A.22).

### Signedness audit — all 8 tables, every integer column

| Column | SQL | Live | Note |
| --- | --- | --- | --- |
| `ulasan_dokter.rating` / `rating_komunikasi` / `rating_akurasi` | `TINYINT UNSIGNED` | `tinyint unsigned` | MySQL 8 emits **no display width**; `tinyint(3) unsigned` would be wrong |
| `ulasan_dokter.is_anonim` | `TINYINT(1) NOT NULL DEFAULT 1` | `tinyint(1) … DEFAULT '1'` | `(1)` retained by the server for TINYINT(1); default is **1** |
| `artikel_kategori.id` | `SMALLINT UNSIGNED` | `smallint unsigned` | 16-bit, so `unsignedSmallInteger()` |
| `artikel.jumlah_view` | `INT UNSIGNED` | `int unsigned` | `unsignedInteger()`, **not** `int(10) unsigned` |
| `home_care_pesanan.durasi_jam` | `TINYINT UNSIGNED` | `tinyint unsigned` | 8-bit, range 0-255 |
| `artikel_kategori`… all `id`/FK columns | `BIGINT UNSIGNED` | `bigint unsigned` | `unsignedBigInteger()` throughout |

`'tinyint unsigned'` **contains** the substring `signed`; signedness was asserted with
`str_ends_with(trim($ty), 'unsigned')`, never `str_contains`.

**`apotek_stok.jumlah_stok` and `apotek_stok.stok_minimum` remain SIGNED `int`** —
batch H's deliberate choice, unchanged (§12).

---

## 3. R1b — SWALLOWED-STATEMENT AUDIT (`token_get_all`, comments discarded)

Count `$table-><columnBuilder>('col')` occurrences in file order with `T_COMMENT` and
`T_DOC_COMMENT` discarded, and compare to the DDL's column count **and order**. Plus a
second pass over `->foreign()`, `->index()`, `->unique()` and `DB::statement()`, split
into `ADD CHECK` and `MODIFY … ON UPDATE`.

```
file                                       decls  ddl  count    order    fk    idx   uq    chk   modify
000068_notifikasi_table.php                  9    9   MATCH    MATCH    1/1   1/1  0/0   0/0   0(need 0) OK
000069_ulasan_dokter_table.php              12   12   MATCH    MATCH    2/2   1/1  1/1   3/3   0(need 0) OK
000070_artikel_kategori_table.php            3    3   MATCH    MATCH    0/0   0/0  1/1   0/0   0(need 0) OK
000071_artikel_table.php                    14   14   MATCH    MATCH    2/2   0/0  1/1   0/0   1(need 1) OK
000072_home_care_pesanan_table.php          14   14   MATCH    MATCH    3/3   0/0  1/1   0/0   1(need 1) OK
000073_audit_log_table.php                  11   11   MATCH    MATCH    0/0   2/2  0/0   0/0   0(need 0) OK
000074_persetujuan_pdp_table.php             7    7   MATCH    MATCH    1/1   0/0  1/1   0/0   0(need 0) OK
000075_akses_rekam_medis_log_table.php       5    5   MATCH    MATCH    2/2   0/0  0/0   0/0   0(need 0) OK

R1b VERDICT: ALL 8 FILES MATCH THE DDL's COLUMN COUNT AND ORDER, AND EVERY FOREIGN KEY
              AND NAMED INDEX IS DECLARED IN CODE
totals: 75 declarations vs 75 columns; 11 ->foreign() vs 11 DDL FK clauses;
        4 ->index() vs 4 named INDEX; 6 ->unique() vs 6 DDL unique constraints;
        3 `ADD CHECK` vs 3 DDL inline CHECKs; 2 `MODIFY diubah_at` vs 2 tables whose
        DDL declares ON UPDATE.
```

The three raw statements, captured verbatim from the executable tokens:

```
ALTER TABLE ulasan_dokter ADD CHECK (rating BETWEEN 1 AND 5)
ALTER TABLE ulasan_dokter ADD CHECK (rating_komunikasi BETWEEN 1 AND 5)
ALTER TABLE ulasan_dokter ADD CHECK (rating_akurasi BETWEEN 1 AND 5)
ALTER TABLE artikel MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
ALTER TABLE home_care_pesanan MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**This audit earned its keep twice, and both times against me — see §11.1 and §11.2.**

---

## 4. TOKEN AUDIT (R2) and UNICODE SCAN (A.17)

Every `T_STRING` and single-quoted literal outside comments in each file, checked for
presence in that table's **own** `CREATE TABLE` block (the block *including* its
`CREATE TABLE x (` line).

```
000068_notifikasi_table.php            tokens_checked= 43  unknown=0
000069_ulasan_dokter_table.php         tokens_checked= 51  unknown=0
000070_artikel_kategori_table.php      tokens_checked= 14  unknown=0
000071_artikel_table.php               tokens_checked= 54  unknown=0
000072_home_care_pesanan_table.php     tokens_checked= 68  unknown=0
000073_audit_log_table.php             tokens_checked= 53  unknown=0
000074_persetujuan_pdp_table.php       tokens_checked= 36  unknown=0
000075_akses_rekam_medis_log_table.php tokens_checked= 26  unknown=0
TOTAL unknown tokens = 0
```

Full-range Unicode scan, `[regex]::Matches($text,'[\u3000-\u9FFF\uFF00-\uFFEF]')`:

| File | CJK/fullwidth | `U+FFFD` | BOM | non-ASCII present |
| --- | --- | --- | --- | --- |
| all 8 migrations | **0** | **0** | **no** | `U+2014` only |
| `docs/schema-notes.md` | **0** | **0** | **no** | `U+00A7`,`U+2013`,`U+2014`,`U+2026`,`U+2192`,`U+2212` |

`git diff --numstat -- docs/schema-notes.md` → **353 added, 0 deleted**: a pure append,
so the 1605 pre-existing lines are untouched.

---

## 5. ACCEPTANCE CRITERIA

### AC1 — `migrate:fresh` exit 0 on BOTH databases

| Database | Command | Exit | Last migration |
| --- | --- | --- | --- |
| `telemedisin_db` | `php artisan migrate:fresh --no-interaction` | **0** | `000075_akses_rekam_medis_log_table` |
| `telemedisin_db_test` | same, fresh shell with `$env:DB_DATABASE='telemedisin_db_test'` | **0** | `000075_akses_rekam_medis_log_table` |

78 migrations run (3 surviving scaffolds + 75 contract). My 8 sort `000068` …
`000075`, lowest is `000068`, highest is `000075`. No `bootstrap/cache/config.php` at
any point; `config:clear` run before the first run and after the last.

### AC2 — `verify-schema --tables=…` on the 8 real tables

```
 scope notifikasi, ulasan_dokter, artikel_kategori, artikel, home_care_pesanan, audit_log, persetujuan_pdp, akses_rekam_medis_log
 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.
 PASS — 75 tables, 2 views verified. Nothing was written.
EXITCODE = 0
```

All **8 real names** appear in the echoed `scope` line. Live `checks=3`,
`foreign_keys=104`, `columns=715`, `tables=82`, `views=0`.

**TYPO CONTROL (A.8 trap 2), run deliberately:**

```
$ php artisan sehatly:verify-schema --tables=…,akses_rekam_medis_logz
 scope …,akses_rekam_medis_logz
 Discrepancies: 1 (0 drift, 1 informational)
 unknown_requested_table akses_rekam_medis_logz expected: - | actual: -
 PASS — 75 tables, 2 views verified. Nothing was written.
EXITCODE = 0        <-- ZERO, with a misspelled name and a green PASS banner
```

The banner is also a lie about scope: it says "75 tables, 2 views verified" after
verifying **one** table (A.7). The `Discrepancies:` line and the `scope` line are the
only trustworthy output.

### AC3 — full unfiltered `verify-schema`

```
EXITCODE = 1
Discrepancies: 10 (2 drift, 8 informational)
```

Enumerated by kind, not by subtraction:

| # | kind | object | drift |
| --- | --- | --- | --- |
| 1 | `missing_view` | `v_dokter_katalog` | **yes** |
| 2 | `missing_view` | `v_pendapatan_bulanan` | **yes** |
| 3 | `deferred_foreign_key` | `fk_vital_rm` on `pasien_tanda_vital` | no |
| 4-10 | `documented_extra_table` | `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `personal_access_tokens` | no |

`2 drift + 8 informational = 10`. **Zero `missing_table` rows and zero
`missing_column` rows.** The orchestrator's prediction of `10 (2 drift, 8
informational)` is **correct**, and it is correct by enumeration rather than by
coincidence: the 2 drift rows are migrations 77/78's two views, the 8 informational are
the 7 registry entries plus the single deferral.

### AC4 — the three `CHECK` constraints exist AND enforce

`SHOW CREATE TABLE ulasan_dokter` (verbatim in §14) shows all three. The plan's own
criterion:

```sql
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
 WHERE CONSTRAINT_TYPE='CHECK' AND TABLE_NAME='ulasan_dokter';
-- telemedisin_db        -> 3
-- telemedisin_db_test   -> 3
```

Names and expressions, from `information_schema.CHECK_CONSTRAINTS`:

| Name | Clause | DDL source |
| --- | --- | --- |
| `ulasan_dokter_chk_1` | (`` `rating` between 1 and 5 ``) | `:1055` |
| `ulasan_dokter_chk_2` | (`` `rating_komunikasi` between 1 and 5 ``) | `:1056` |
| `ulasan_dokter_chk_3` | (`` `rating_akurasi` between 1 and 5 ``) | `:1057` |

**Enforcement demonstrated, not assumed.** One connection, one transaction, `--force`
so every probe runs; parent rows created only so the two `RESTRICT` foreign keys cannot
mask the `CHECK` with a 1452. Full output in §13.

| Probe | Statement | Result |
| --- | --- | --- |
| 1 | `rating = 6` | **`ERROR 3819 (HY000): Check constraint 'ulasan_dokter_chk_1' is violated.`** |
| 2 | `rating = 0` | **`ERROR 3819 (HY000): Check constraint 'ulasan_dokter_chk_1' is violated.`** |
| 3 | `rating_komunikasi = 9` | **`ERROR 3819 (HY000): Check constraint 'ulasan_dokter_chk_2' is violated.`** |
| 4 | `rating_akurasi = 7` | **`ERROR 3819 (HY000): Check constraint 'ulasan_dokter_chk_2'…`** → `chk_3` |
| 5 | `rating = 5` | **accepted**, `ROW_COUNT() = 1` |
| 6 | `rating = 1` | **accepted**, `ROW_COUNT() = 1` |
| 7 | `rating_komunikasi = NULL` | **accepted**, `ROW_COUNT() = 1` |
| 8 | duplicate `konsultasi_id = 900005` | **`ERROR 1062 (23000): Duplicate entry '900005' for key 'ulasan_dokter.ulasan_dokter_konsultasi_id_unique'`** (Trap 2) |

MySQL **3819** is the CHECK-violation error; MySQL **1062** is the duplicate-key error.
Probe 8 is a bonus: it proves Trap 2 live.

Inside the transaction, `ulasan_dokter` held **3** rows (probes 5, 6, 7 — every
violating probe contributed nothing).

**ROLLBACK PROOF — every table reads 0 after `ROLLBACK`:**

```
users_after_rollback = 0    pasien_after_rollback = 0   dokter_after_rollback = 0
ulasan_after_rollback = 0   notifikasi_after_rollback = 0
artikel_after_rollback = 0  artikel_kategori_after_rollback = 0
home_care_after_rollback = 0 audit_log_after_rollback = 0
persetujuan_pdp_after_rollback = 0  akses_log_after_rollback = 0
```

**`down()` drops all three — measured, not asserted.** `migrate:rollback --step=8`
reaches `000069`; the live CHECK count on `ulasan_dokter` goes **3 → 0**, and
`migrate` puts it back to **3**.

```
migrate:rollback --step=8  exit 0   (075,074,073,072,071,070,069,068 — children before parents)
  _information_schema check count on ulasan_dokter = 0 ; batch-K tables remaining = 0_
migrate                   exit 0
   batch-K tables = 8 ; check count on ulasan_dokter = 3
```

### AC5 / AC6 — the test suite

```
$ php artisan test tests/Unit
{"tests":93,"passed":92,"failed":1,"assertions":465}
exit 1
FAILING: tests/Unit/Console/VerifySchemaDeferredConstraintTest.php:249
         "Failed asserting that 0 is greater than 0."

$ php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9
{"tool":"pest","result":"failed","tests":0,"passed":0,"assertions":0,"raw":["No tests found."]}
exit 1                                                              <-- AC6 PASSES
```

**AC5 is NOT met: 92/93, not 93/93.** The single failure is a **pre-existing test
defect, not a migration defect**, analysed in §9. `tests/Unit/Console/VerifySchemaCommandTest.php`
was **not edited** (its SHA-256 is unchanged from `HEAD`); the derived missing count did
move **8 → 0** and it did stay green through that move.

### AC7 — table count, measured

```
telemedisin_db         base_tables=82  views=0
telemedisin_db_test    base_tables=82  views=0
sehatly                base_tables=10  views=0
```

Derivation, by enumeration rather than by subtraction from the brief's 82:

```sql
SELECT COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA='telemedisin_db' AND TABLE_TYPE='BASE TABLE'
   AND TABLE_NAME NOT IN ('migrations','cache','cache_locks','jobs',
                          'job_batches','failed_jobs','personal_access_tokens');
-- 75   <- the 75-table contract, every one present
SELECT TABLE_NAME FROM information_schema.TABLES
 WHERE TABLE_SCHEMA='telemedisin_db'
   AND TABLE_NAME IN ('migrations','cache','cache_locks','jobs',
                      'job_batches','failed_jobs','personal_access_tokens');
-- cache, cache_locks, failed_jobs, job_batches, jobs, migrations, personal_access_tokens
-- 7 registered extras, all present
```

**75 + 7 = 82.** The brief's 82 is correct. `0` views, because both views are todo 18's.
Ledger: **78** rows (70 previous + 8 mine), batch 1 = 70, batch 2 = 8.

---

## 6. FK RECONCILIATION — 11 live FKs against 11 DDL clauses, none invented

`information_schema.KEY_COLUMN_USAGE` joined to `REFERENTIAL_CONSTRAINTS`, on **both**
databases, identically:

| Table | Column | Parent | `DELETE_RULE` | DDL line |
| --- | --- | --- | --- | --- |
| `notifikasi` | `user_id` | `users` | **CASCADE** | `:1046` |
| `ulasan_dokter` | `dokter_id` | `dokter` | NO ACTION | `:1064` |
| `ulasan_dokter` | `pasien_id` | `pasien` | NO ACTION | `:1063` |
| `artikel` | `kategori_id` | `artikel_kategori` | NO ACTION | `:1089` |
| `artikel` | `penulis_user_id` | `users` | NO ACTION | `:1090` |
| `home_care_pesanan` | `anggota_keluarga_id` | `pasien_anggota_keluarga` | NO ACTION | `:1110` |
| `home_care_pesanan` | `pasien_id` | `pasien` | NO ACTION | `:1109` |
| `home_care_pesanan` | `tenaga_medis_id` | `dokter` | NO ACTION | `:1111` |
| `persetujuan_pdp` | `user_id` | `users` | **CASCADE** | `:1143` |
| `akses_rekam_medis_log` | `pengakses_user_id` | `users` | NO ACTION | `:1154` |
| `akses_rekam_medis_log` | `rekam_medis_id` | `rekam_medis` | **CASCADE** | `:1153` |

`total_live_fks_in_batch_K = 11`, DDL clauses `= 11`. **Invented FKs: 0. Missing FKs: 0.**
`NO ACTION` is what MySQL materialises when the DDL writes no `ON DELETE`, and it is
`RESTRICT` for DML.

### The three bare columns, proven FK-free — and proven to EXIST

`SHOW CREATE TABLE` cannot answer this question: it shows only constraints that exist,
so it cannot distinguish "absent" from "not looked for". A column with zero foreign keys
does not appear in `REFERENTIAL_CONSTRAINTS` at all, so **"no row" is the expected
evidence**. Each column was separately confirmed to exist:

```sql
-- (A) the columns exist
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA='telemedisin_db'
   AND (TABLE_NAME,COLUMN_NAME) IN (('artikel','reviewer_user_id'),
                                    ('audit_log','user_id'),
                                    ('audit_log','record_id'));
-- artikel   | reviewer_user_id | bigint unsigned | YES | NULL
-- audit_log | record_id        | varchar(64)     | YES | NULL
-- audit_log | user_id          | bigint unsigned | YES | NULL

-- (B) zero foreign keys on them
fks_found_on_the_three_bare_columns = 0        (both databases)
```

**`record_id` is `varchar(64)` — a string, confirmed live.** Not a bigint.

### No `foreignId()`, and why

`artikel.kategori_id` is `SMALLINT UNSIGNED` because `artikel_kategori.id` is
`SMALLINT UNSIGNED` (`:1069`). `foreignId()` would emit `BIGINT UNSIGNED` and be a
parity break. `$table->id()` is forbidden outright by rule 1 — it would also be wrong on
`artikel_kategori` itself, whose 16-bit id is the batch's narrowest primary key.

---

## 7. INDEXES — by name AND column order, from `information_schema.STATISTICS`

| Table | Index | Unique | Ordered columns | DDL |
| --- | --- | --- | --- | --- |
| `notifikasi` | `idx_notif` | no | `user_id, dibaca_at` | `:1047` |
| `ulasan_dokter` | `idx_ulasan_dokter` | no | `dokter_id, rating` | `:1065` |
| `audit_log` | `idx_audit_user` | no | `user_id, dibuat_at` | `:1130` |
| `audit_log` | `idx_audit_tabel` | no | `tabel_target, record_id, dibuat_at` | `:1131` |
| `persetujuan_pdp` | `uq_consent` | **yes** | `user_id, jenis, versi_dokumen` | `:1144` |

Inline `UNIQUE`s, compared by **semantics** per rule 10 (MySQL names them after the
column, Laravel after the table — one constraint, two names):
`notifikasi` 0, `ulasan_dokter.konsultasi_id`, `artikel_kategori.slug`, `artikel.slug`,
`home_care_pesanan.nomor_pesanan`, `audit_log` 0, `persetujuan_pdp` 0 (its unique is the
named key), `akses_rekam_medis_log` 0.

**`idx_audit_tabel` is a STRING index** — both leading columns are `VARCHAR(64)`, so it
is collated, not numeric. `idx_notif` and `idx_audit_user` put `user_id` **leftmost**,
and `uq_consent` puts `user_id` leftmost (which also makes it serve InnoDB's support
requirement for the FK at `:1143`, so no extra implicit index appears there).

InnoDB implicit FK-support indexes created on my tables and correctly **not** reported
as `extra_index` (commit `27c6ca8`): `ulasan_dokter_pasien_id_foreign (pasien_id)`,
`artikel_kategori_id_foreign`, `artikel_penulis_user_id_foreign`,
`home_care_pesanan_anggota_keluarga_id_foreign`, `home_care_pesanan_tenaga_medis_id_foreign`,
`akses_rekam_medis_log_rekam_medis_id_foreign`, `akses_rekam_medis_log_pengakses_user_id_foreign`.
Not one covering index was added by hand to suppress any of them.

---

## 8. THE FIVE TRAP COMMENTS, QUOTED VERBATIM

### Trap 1 — the only DDL-level value validation in the contract

`database/migrations/2026_10_01_000069_ulasan_dokter_table.php`

> **State this plainly, because it is the single most important fact about this
> file: these three columns are the ONLY columns in the entire 75-table contract
> whose value range the database polices.** Every other numeric or enumerated
> value in the schema is validated by the application layer alone — there is no
> other `CHECK` in `telemedicine_test.sql`, no trigger anywhere, and no generated
> column. A service-layer check **cannot replace these constraints**, for two
> reasons that are structural rather than stylistic: a second code path (an
> observer, a console command, a second service, or raw SQL) bypasses it, and a
> race between validation and insert is decided by the database or not at all.
>
> **MySQL 8.0.16+ actually enforces `CHECK`.** Before 8.0.16 the grammar parsed a
> `CHECK` and then ignored it, which is why the constraint was historically
> treated as documentation. This schema targets MySQL 8 (the running server is
> 8.0.30, measured), so the three statements below are real integrity guarantees
> and not decoration. That was verified by *executing* a violating insert, not by
> reading the DDL — see `.omo/evidence/task-17-sehatly.md`.
>
> **Laravel 13's Blueprint has NO `CHECK` builder.** There is no
> `$table->check()`, and no combination of `enum()`, `unsignedTinyInteger()` or
> `->default()` expresses a range. The constraints are therefore issued as three
> raw `DB::statement('ALTER TABLE ... ADD CHECK ...')` calls immediately after the
> table is created, written **exactly as the DDL writes them**.

And, on naming:

> The DDL writes **no constraint name** — the three `CHECK`s are inline column
> constraints, so there is no name in `telemedicine_test.sql` to copy. Asking for
> "the exact name in the DDL, do not invent names" therefore resolves to
> **supplying none**: MySQL then auto-generates `ulasan_dokter_chk_1`,
> `_chk_2` and `_chk_3` in creation order, which is exactly what importing
> `telemedicine_test.sql` produces. Naming them `chk_rating` and friends — as the
> dispatched brief's illustrative snippet does — would be **inventing a name the
> contract never had**, and would diverge from what a reference import yields.

### Trap 2 — `konsultasi_id` is `NOT NULL UNIQUE`

> **The consequence is a hard database-level guarantee, and todo 41's review flow
> must be built around it: a second review for the same consultation is
> IMPOSSIBLE.** Not discouraged, not overwritten — impossible; the insert fails
> with MySQL 1062. So the flow is **INSERT-then-UPDATE-or-409**: the first review
> inserts, and any later edit to that same review is an `UPDATE` of the one row.
> A submit handler that unconditionally `INSERT`s is wrong against this schema,
> and no amount of application-level de-duplication changes that — the uniqueness
> is in the index, not in the code.

### Trap 3 — `record_id` is a string

> `:1123` is `record_id VARCHAR(64) NULL`. **It must be `string('record_id', 64)`,
> never `unsignedBigInteger('record_id')`.** This is the single most consequential
> type decision in the batch, and getting it wrong is not a cosmetic drift — it
> makes certain audit rows **unrepresentable**.
>
> `audit_log` is the project's **generic, table-agnostic** audit trail: the pair
> (`tabel_target` `:1122`, `record_id` `:1123`) names *some* row in *some* table,
> and the same columns must therefore carry the primary key of **every** model the
> observers in todo 43 watch. Most are `BIGINT UNSIGNED`, but **not all**, and an
> audit row for a non-numeric or composite key is simply not expressible in a
> bigint column. The clearest case is in this very contract: `role_permissions`
> (15), `user_roles` (16), `dokter_faskes` (33) and `lab_paket_item` (57) all have
> **composite primary keys and no `id` at all** (rule 3 of
> `docs/migration-order.md`), and `dokter_faskes`'s PK is the pair
> `(dokter_id, faskes_id)`. A single `BIGINT` cannot name any of them. `VARCHAR(64)`
> can, by storing `"12|34"`, and that is the trade the DDL makes.
>
> **This is also why `audit_log` cannot key on a numeric id, and therefore why
> `INDEX idx_audit_tabel (tabel_target, record_id, dibuat_at)` (`:1131`) is a
> STRING index.** Both of its first two columns are `VARCHAR(64)`, so the index
> stores them as character data and sorts them **collated**, not numerically: an
> index scan for `record_id = '9'` will also visit `'10'` and `'100'`, which is
> correct and unavoidable, and a `WHERE` that forgets the quotes around the value
> will not match at all. A reviewer optimising this index for integers would be
> optimising a column that does not exist.

And, on the bare `user_id`, with attribution kept honest:

> ATTRIBUTION: that rationale is **the plan's, not the SQL's** -
> `:1120` carries NO COMMENT text in the DDL. This comment therefore
> does not claim the SQL states it, because it does not. What the DDL
> says is only: nullable, and unconstrained.

### Trap 4 — `uq_consent` uniqueness is per VERSION

> `:1144` — `UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)`. This is a
> **named** key, so rule 10 compares it **by name**: it is declared
> `$table->unique(['user_id', 'jenis', 'versi_dokumen'], 'uq_consent')`. All three
> columns are load-bearing and **must not be collapsed into fewer**:
>
> - **A re-consent after a policy version bump is a NEW ROW, not an update.**
>   `versi_dokumen` is part of the key, so a user who accepted version `1.0` and
>   later accepts `2.0` gets two rows. That is the design: the old consent remains
>   on record against the document it was actually given for, and an `UPDATE` would
>   destroy exactly the evidence that matters. A user may therefore hold **any
>   number** of consent rows across versions.
> - **A same-version duplicate is rejected by the database** — MySQL 1062. Nothing
>   in the application is needed to prevent it, and a submit handler that retries
>   an `INSERT` after a duplicate will keep failing, which is the correct
>   behaviour.
> - **The same user may consent to the same `jenis` twice at the same version
>   only if the row is deleted first**, and nothing in the schema cascades or
>   records a revocation. See the collision note below.
>
> **Todo 47 owns both of the rules this key's shape decides**, and the DDL — not
> the service — is what makes them necessary:
>
> 1. **"Highest `versi_dokumen` wins."** Nothing in the database orders versions or
>    picks a winner: `versi_dokumen` is `VARCHAR(20)`, a **string**, so a `MAX()`
>    over it sorts lexicographically and `"10.0"` compares **less than** `"9.0"`.
> Any "latest consent" lookup must therefore be done on a parsed version — or on
> `disetujui_at` — and never on a bare `MAX(versi_dokumen)`. This is a real trap
>    and it is created by the DDL's choice of `VARCHAR`.
> 2. **The revoked-same-version collision.** There is no `dicabut_at`, no
> `status`, and no partial unique index (MySQL has none), so a withdrawal of
> the consent for `(user, jenis, versi)` **cannot be recorded as a new row at
> that same version** — the key would collide. The only representable
>    representations are to `UPDATE` the existing row's `disetujui` flag (losing
>    the fact that consent was once given) or to `DELETE` it (losing the row
>    entirely). `disetujui TINYINT(1) NOT NULL` (`:1140`) is the lever that makes
>    the first option possible, and **its existence is the only reason a revocation
>    is representable at all.**

### Trap 5 — `akses_rekam_medis_log` has NO `updated_at`

> That is `:1152`, and it is the **only** timestamp column. **There is no
> `diubah_at`.** So:
>
> - **Do NOT call `$table->timestamps()`.** It would emit `created_at` and
> `updated_at` — two wrong names *and* a column the DDL does not have. It is
>   exactly the blanket application `docs/migration-order.md` rule 4 exists to ban.
> - **Todo 19's model needs `public $timestamps = false`** (and no
>   `const CREATED_AT` / `const UPDATED_AT` is required beyond
>   `const CREATED_AT = 'dibuat_at'` if a created-at accessor is wanted).
>   `akses_rekam_medis_log` is one of the **19** contract tables in the
>   "`dibuat_at` only" group.
> - **No raw `ALTER` is needed.** `ON UPDATE CURRENT_TIMESTAMP` appears only in
>   the sixteen tables that carry *both* `dibuat_at` and `diubah_at`, and this is
>   not one of them.
>
> **This is an append-only access log, and the absence of `diubah_at` is the DDL's
> way of saying so.** A row records that *someone read a medical record, for a
> stated purpose, at a stated time*. There is nothing about that fact to amend: an
> "edit" to an access-log row is a falsification of the evidence, so the schema
> removes the possibility of an update timestamp entirely rather than merely
> discouraging it.

---

## 9. THE ONE FAILING TEST — analysed, not edited

`tests/Unit/Console/VerifySchemaDeferredConstraintTest.php:249`, inside
`test('the full run still fails, and the deferral is the only thing that left the drift set')`:

```php
expect($exitCode)->toBe(1);                                    // line 241 - PASSES (drift_count = 2)
expect($json['ok'])->toBeFalse();                              // line 242 - PASSES
expect($json['drift_count'])->toBeGreaterThan(0);              // line 243 - PASSES (2)
$byKind = array_count_values(array_column($json['discrepancies'], 'kind'));
expect($byKind['missing_table'] ?? 0)->toBeGreaterThan(0);     // line 249 - FAILS: 0
```

**The assertion is false, and correctly so.** The `--json` output has **zero**
`missing_table` rows and **two** `missing_view` rows, because todo 17 creates the last
eight tables and todo 18 creates the two views.

**This is not my opinion — it is the plan's own prediction.** Plan appendix A.9, line
1070, read directly:

```
| todo | 9 | 10 | 11 | 12 | 13 | 14 | 15 | 16 | 17 | 18 |
| real `missing_table` | 48 | 41 | 38 | 34 | 29 | 21 | 15 | 8 | 0 | 0 |
```

**todo 17 → `missing_table` = 0.** The test asserts `> 0`. The test and the contract
are in direct contradiction, and the test is the one that is wrong.

**The sibling test in the same suite was already fixed for exactly this boundary, and
its own docblock says so** — `VerifySchemaCommandTest.php:26-32`:

> VIEWS are derived too, not left as two literal regexes. The two view
> migrations (`2026_10_01_000077`/`_000078`) are authored in todo 18, *after*
> the last table migration in todo 17, and they emit `CREATE OR REPLACE VIEW`
> rather than `Schema::create` - so a table-only derivation reaches "nothing
> missing" one commit before the verdict actually flips, and a test that
> inverted on that signal alone would go red at todo 17. Deriving both means the
> inversion happens exactly when the schema is complete.

`VerifySchemaDeferredConstraintTest.php` did not receive the same treatment. **Todo 17
is the first commit to expose it**, and the exposure is caused by my batch being
*correct*.

**I deliberately did not edit it**, and this is a judgement call a reviewer can
overturn:

- it is outside this commit's authorised pathspec (`database/migrations`,
  `docs/schema-notes.md`, `.omo/evidence/task-17-sehatly.md`), and the brief requires
  `git show --name-only HEAD` to list *only* those paths;
- editing a test to turn a suite green is the exact failure mode this project has
  spent ten batches fighting, and a reviewer could not afterwards tell my edit from a
  test weakened to accommodate a defect;
- the fix is one assertion, and it belongs with todo 18, which is the commit that
  makes `missing_view` reach 0 too and therefore the commit in which the correct
  expectation is unambiguous.

The one-line change a reviewer needs, when they choose to make it:

```php
// line 249 — accept EITHER a missing table or a missing view as the incompleteness signal
expect(($byKind['missing_table'] ?? 0) + ($byKind['missing_view'] ?? 0))->toBeGreaterThan(0);
```

**What I could not verify:** whether the orchestrator intends the suite to be red at
todo 17 as a deliberate pre-checkpoint signal. Nothing in the plan or the brief says
so, and A.8's standing instruction is the opposite — *"Every executor must run the
affected suite before committing"* — so I am reporting the red rather than reasoning
my way around it.

---

## 10. NEGATIVE QA — two probes, both restored byte-identically

Baseline SHA-256 of all 8 files recorded before either probe.

### (a) Remove one `CHECK` constraint

Removed `DB::statement('ALTER TABLE ulasan_dokter ADD CHECK (rating_akurasi BETWEEN 1 AND 5)');`
from `000069`.

| Gate | Result |
| --- | --- |
| `php -l` | **exit 0** — "No syntax errors detected" |
| `php artisan migrate:fresh` | **exit 0** |
| live `information_schema` CHECK count on `ulasan_dokter` | **3 → 2, silently** |
| `php artisan test tests/Unit` | **93 tests, 92 passed, 1 failed, 465 assertions** — *byte-identical to the unmutated run: same single failure, same assertion count* |
| `verify-schema --tables=ulasan_dokter` | **exit 1** — `Discrepancies: 1 (1 drift, 0 informational)` / `missing_check ulasan_dokter expected: rating_akurasi between 1 and 5 \| actual: -` |

**Three of four gates green, and the fourth is the only one that looks at the
constraint.** A missing `CHECK` is invisible to the entire test suite.

### (b) Truncate `persetujuan_pdp.jenis` to the four values on SQL `:1137`

Dropped `'komunikasi_tindak_lanjut'` — the value that lives on `:1138` — reproducing
exactly the misreading A.20 warns about.

| Gate | Result |
| --- | --- |
| `php -l` | **exit 0** |
| `php artisan migrate:fresh` | **exit 0** |
| live `COLUMN_TYPE` | `enum('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis','pemasaran')` |
| `php artisan test tests/Unit` | **93 tests, 92 passed, 1 failed, 465 assertions** — again identical |
| `verify-schema --tables=persetujuan_pdp` | **exit 1** — `column_type persetujuan_pdp.jenis` with **both full value lists printed** |

```
expected: enum('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis','pemasaran','komunikasi_tindak_lanjut')
actual:   enum('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis','pemasaran')
```

**This proves the A.20 claim directly on the column this batch owns**, and it confirms
the verifier compares an ENUM as a **sequence**, not a set: the four retained values are
a *prefix* of the correct list, so membership is unchanged and only completeness and
order differ — and the verifier reports it. It is also the direct demonstration that
reading `:1137` alone produces a wrong schema.

### Restores

| File | SHA-256 before probe | SHA-256 after restore | Identical |
| --- | --- | --- | --- |
| `000069_ulasan_dokter_table.php` | `DB9763E971CDA2E0DD06A347154662C0382772A21B7CE8C13E8982A09E87AB3A` | `DB9763E971CDA2E0DD06A347154662C0382772A21B7CE8C13E8982A09E87AB3A` | **yes** |
| `000074_persetujuan_pdp_table.php` | `F89985D53793CCFE3D42454800FF7A96F1899BE8D3D0A133FE11BE6558C7B447` | `F89985D53793CCFE3D42454800FF7A96F1899BE8D3D0A133FE11BE6558C7B447` | **yes** |

The first restore attempt was **not** byte-identical — see §11.4. The second was, and
both files were re-migrated afterwards so neither database retains either mutation:

```
telemedisin_db      jenis = enum(...5 values...)   checks = 3
telemedisin_db_test jenis = enum(...5 values...)   checks = 3
```

---

## 11. MY OWN DEFECTS AND MY OWN BROKEN INSTRUMENTS — disclosed in full

Ten batches of this project have earned their keep by disclosing rather than
concealing. This section is the reason to trust the rest of the file.

### 11.1 I wrote three foreign keys as COMMENTS, with no code behind them

`ulasan_dokter`, `artikel` and `home_care_pesanan` each ended their
`Schema::create()` closure with a comment block *describing* two or three `FOREIGN KEY`
clauses — and **no `$table->foreign(...)` call at all**. `akses_rekam_medis_log` had
one of its two. **Eight foreign keys were missing from the live schema.**

What caught it, and what did not:

| Gate | Result on the schema missing 8 FKs |
| --- | --- |
| `php -l` (all 8 files) | **exit 0**, all eight |
| `php artisan migrate:fresh` | **exit 0** |
| `verify-schema --tables=<8 tables>` | **exit 1**, all 8 named as `missing_foreign_key` |
| **my R1b audit** | **"ALL 8 FILES MATCH"** — a confident green |

The pattern is the tell: the FKs whose `ON DELETE` clause I *did* write
(`notifikasi`, `persetujuan_pdp`, `akses_rekam_medis_log.rekam_medis_id`) were present;
the ones I only *described* were not. I had written a narrative about the constraints
instead of the constraints.

**My R1b audit did not catch it because it only counted COLUMN declarations.** It
answered "are all 75 columns present, in order?" — a question it was asked, correctly,
and it answered correctly. It was never asked about foreign keys. That is A.22's lesson
landing on me mid-task, and it is why the FK/index/unique/CHECK pass now exists.

### 11.2 …and when I added that pass, it was wrong four separate ways

Each bug produced a **confident, wrong** answer rather than an error:

| # | Bug | What it reported |
| --- | --- | --- |
| 1 | The column scanner broke on the `(` before reaching the column-name string | `decls=0` for all 8 files, a confident "MISMATCH" against 9/12/3/14/14/11/7/5 |
| 2 | The token-audit DDL block started *after* the `CREATE TABLE x (` line | Each table's own name reported as an unknown token — 3 false positives that looked exactly like the corruption class the check exists to catch |
| 3 | `DB::statement` is reached via `T_DOUBLE_COLON`, not `T_OBJECT_OPERATOR` | `stmt=0` for `000069` — the three CHECK constraints, the most important count in the batch, silently reported as zero |
| 4 | I compared the raw `DB::statement` count against the DDL's CHECK count, without splitting `ADD CHECK` from `MODIFY … ON UPDATE` | `artikel` and `home_care_pesanan` flagged `chk=1/0 BAD` on files whose single statement is a correct `MODIFY` |

Bug 2 is the A.18 shape exactly: a finding about a *document* that the instrument had
never actually looked at. Bug 3 is the A.22 shape: a number from a tool, adopted without
asking the tool what it counted.

**A separate instrument of mine also under-reported the schema.** My first DDL splitter
joined continuation lines on *unbalanced parentheses*, which is the right predicate for
a wrapped ENUM value list and the **wrong** predicate for a declaration whose trailing
`NOT NULL DEFAULT` merely sits on the next line. `home_care_pesanan.status` is the
second kind, so `:1105` was parsed as a column named `NOT` and the table came out
**15 columns wide instead of 14**. Caught and replaced with a depth-0-comma split, which
is how the SQL actually parses. Had I not cross-checked the column count against the
`CREATE TABLE` block boundaries, a wrong figure could have propagated into a comment.

### 11.3 A comment of mine broke five unit tests

`000069`'s docblock originally spelled the schema-builder method with its argument list
in prose. `VerifySchemaCommandTest.php` derives its expectations by counting the raw
text `Schema::create` across every migration file and then re-counting only occurrences
followed by a quoted literal:

```php
$createCalls     += preg_match_all('/Schema::create\s*\(/', $code);
preg_match_all("/Schema::create\s*\(\s*'([A-Za-z0-9_]+)'/", $code, $matches);
```

My prose raised the first count to 82 without raising the second, tripping the test's
own *"refuse rather than under-test"* guard and failing **five tests at once** with
`Failed asserting that actual size 81 matches expected size 82`. `php -l`,
`migrate:fresh` and `verify-schema` were all green throughout.

This is the purest instance yet of A.21: **a defect in a comment that no gate except
the test suite could find**, and it is the mirror image of A.15 — there, a wrong comment
misled a human; here, a harmless-looking comment broke a machine. The file now carries
a note explaining why it avoids the spelling. After the fix: `raw = 81, literal = 81,
delta = 0`.

### 11.4 I broke a byte-identical restore, and had to prove it

After probe (a) I re-inserted the removed `CHECK` statement after the **first**
`DB::statement` line rather than after the second, permuting the three statements. The
SHA-256 comparison caught it (`0D674A…` vs the expected `DB9763…`) — which is the whole
reason the restore is hash-verified rather than eyeballed. Corrected, and the restore
then matched exactly. Probe (b)'s restore matched on the first attempt.

### 11.5 I corrupted my own probe SQL twice, and my own debug script once

While writing the CHECK-enforcement script I emitted `kons spying`, `kons Amazia` and
`kons Dorsey` as column names, and `patients_id` for `pasien_id`. All were caught on
read-back before execution. My first attempt at the R1b scanner had a mismatched-quote
parse error. These are the same defect class as §11.3, in throwaway files rather than
committed ones, and they are listed because the count is the point: **this task
generated ten separate identifier corruptions and caught all ten on read-back.** None
reached a committed file.

### 11.6 I made the exact `Get-Content` mistake R2 warns about — on a read

An early `Get-Content … | Select-Object` printed `2026_10_01_000046_rekam_medis_persetujuan_table.php`
with `?` where an em-dash belonged, which looked like pre-existing mojibake in a
committed file. It was **my read** that was corrupted: PowerShell 5.1 `Get-Content`
without `-Encoding` decodes as ANSI. Re-read with `[System.IO.File]::ReadAllText` and
`UTF8`, the file contains **12 × U+2014 and nothing else unusual**, and a full-range
scan over all 70 pre-existing migrations finds only `U+2013` (2), `U+2014` (572) and
`U+2026` (12) — **zero CJK, zero `U+FFFD`, zero BOM**. **No file defect existed.** I am
recording this because it is the same failure the brief describes happening to an
executor's reads, and because I nearly filed it as a finding against someone else's file.

---

## 12. ADVERSARIAL CLASSES

### `misleading_success_output` — applies, and bit me

A.21's table, extended by this todo to two new rows:

| Defect | `php -l` | `migrate:fresh` | unit suite | `verify-schema` |
| --- | --- | --- | --- | --- |
| a column declaration swallowed by a comment (todo 15) | 0 | 0 | **93/93 green** | 1 |
| an `INDEX` removed (todo 16) | 0 | 0 | **93/93 green** | 1 |
| **a `FOREIGN KEY` missing (mine, §11.1)** | **0** | **0** | **would be green** | **1** |
| **a `CHECK` removed (probe a)** | **0** | **0** | **92/93, identical** | **1** |
| **an ENUM truncated (probe b)** | **0** | **0** | **92/93, identical** | **1** |
| **a comment that breaks the derived test (§11.3)** | 0 | 0 | **5 tests fail** | 0 |

The last row is the one nobody expects: a defect **only** the test suite sees, with
`verify-schema` green. The two are complementary, and neither substitutes for the other.
Against a missing *value range* — the most dangerous defect in this batch — **three of
four gates are green and the fourth is the only one looking.**

### `stale_state`

No `bootstrap/cache/config.php` at any point (`Get-ChildItem bootstrap\cache` →
`.gitignore`, `packages.php`, `services.php` only). `php artisan config:clear` run
before the first `migrate:fresh` and after the last. My 8 files sort `000068` (lowest)
… `000075` (highest), all after `000067`. **The test database was migrated** — twice,
plus once more for each negative probe — and `$env:DB_DATABASE` is named explicitly in
every shell that touches it. Every `information_schema` query in this file names its
schema. The pre-existing `mysqld` (PIDs 17484, 19796) and the user's `php artisan serve`
were never signalled.

### `dirty_worktree`

`git status --porcelain` before my work:

```
 M .omo/plans/sehatly-telemedicine-platform.md      (orchestrator-owned, 916/30)
 M docs/migration-order.md                           (orchestrator-owned, 12/4)
?? .omo/evidence/task-3-sehatly.md                  (orchestrator-owned, untracked)
?? .omo/start-work/                                 (orchestrator-owned, untracked)
```

Both modified files are **orchestrator-owned and were not touched by me**; their numstat
is unchanged from the pre-existing state. `docs/migration-order.md` was re-read only. My
commit adds `database/migrations` (8 new), `docs/schema-notes.md` (append only) and
`.omo/evidence/task-17-sehatly.md`. No `git add -A`/`.`/`-a`/`-u`, no stash, no
checkout/restore/clean/reset, no `--amend`, no push, not on `main`.

### `hung_or_long_commands`

Every long command ran inside `Start-Job` with `Wait-Job -Timeout` and an explicit
`sentinel`, and a timeout path that `exit 9`s rather than reading as success:

| Job | Timeout | On timeout |
| --- | --- | --- |
| `migrate:fresh` × 5 (2 initial + convergence + 2 restores) | 600 s | `Stop-Job`, print tail, `exit 9` |
| `php artisan test tests/Unit` × 3 | 900 s | `Stop-Job`, print tail, `exit 9` |

Every job's exit code was read from **its own output** (a `SENTINEL-END exit=N` line),
never from `$LASTEXITCODE` of the wrapping shell. No `Start-Process -PassThru` was used,
so the empty-`.ExitCode` failure mode does not apply. No timeout occurred.

### `repeated_interruptions` — convergence proven

Fingerprint = 1070 sorted, pipe-delimited lines covering every column (position, type,
nullability, default, extra), every index (name, uniqueness, ordered column list), every
foreign key (with `DELETE_RULE`/`UPDATE_RULE`), every check constraint, the views, and
the object counts.

| Fingerprint | SHA-256 |
| --- | --- |
| FP-A `telemedisin_db`, run 1 | `38AEC10DD0EE6837FC833167F3029626E596C41B3EA0828FB0F23F72AB38A2CB` |
| FP-B `telemedisin_db_test` | `38AEC10DD0EE6837FC833167F3029626E596C41B3EA0828FB0F23F72AB38A2CB` |
| FP-C `telemedisin_db`, after a **second** `migrate:fresh` | `38AEC10DD0EE6837FC833167F3029626E596C41B3EA0828FB0F23F72AB38A2CB` |

`FP-A == FP-C` → **`migrate:fresh` is convergent.** `FP-A == FP-B` → the two project
databases are **byte-identical**.

Rollback cycle, children before parents:

```
migrate:rollback --step=8  exit 0
  000075, 000074, 000073, 000072, 000071, 000070, 000069, 000068
migrate                   exit 0
```

### Ruled out, one line each

- **`malformed_input`** — every candidate was read before use; the two malformed probe
  statements I did write (§11.5) were caught on read-back and never executed.
- **`prompt_injection`** — `telemedicine_test.sql` is first-party DDL read strictly as a
  specification. Its only `COMMENT` payloads in my range are two literals:
  `'1 konsultasi = 1 ulasan'` (`:1052`) and `'Reviewer medis (revisi medis)'` (`:1078`).
  Both were **enumerated and treated as data**, and both happen to corroborate the
  comments I wrote. `SqlSchemaParser` already skips `COMMENT` text when scanning for
  constraints (`COMMENT` is in its skip list at `SqlSchemaParser.php:38`). No
  instruction-shaped text exists anywhere in the file. The file contains 15
  `INSERT INTO` statements, all in section `[16]` past `:1198`, and **no seed data was
  executed or copied**.
- **`cancel_resume`** — no interruption occurred; the two probe restores are hash-proven
  identical to their pre-probe state (§10), and both databases were re-migrated
  afterwards and re-measured.
- **`flaky_tests`** — deterministic. Three independent `tests/Unit` runs (clean, probe a,
  probe b) returned **identical** results: 93/92/1/465 with the same single failing
  assertion. The zero-match control measured 0 tests, so flakiness is excluded by
  construction.

### Batch A-J regression spot-checks — all 13 hold, live

| # | Probe | Expected | Measured |
| --- | --- | --- | --- |
| A | `master_agama.id` | `tinyint unsigned`, **no** AUTO_INCREMENT | `tinyint unsigned`, `EXTRA` empty ✓ |
| B | `users.dihapus_at` | `timestamp` | `timestamp` ✓ |
| C | `pasien.tinggi_badan_cm` | `decimal(5,1)` | `decimal(5,1)` ✓ |
| C | `pasien.berat_badan_kg` | `decimal(5,2)` — **distinct** | `decimal(5,2)` ✓ |
| D | `dokter.durasi_default_menit` | `smallint unsigned` | `smallint unsigned` ✓ |
| E | `dokter_faskes` | **no `id`**, PK `(dokter_id, faskes_id)` | `id_column_present=0`, PK `dokter_id,faskes_id` ✓ |
| E | `booking` | **no** unique spanning `(dokter_id, tanggal_kunjungan, slot_mulai)` | only `idx_booking_dokter(dokter_id,tanggal_kunjungan)` non-unique + `nomor_booking_unique` ✓ |
| G | `rekam_medis` | **28** columns | `28` ✓ |
| G | `rekam_medis.status_dokumen` | default `'final'` | `enum('draft','final','diamendemen')`, default `final` ✓ |
| H | `apotek_stok.jumlah_stok` | **signed** `int` | `int` ✓ |
| H | `apotek_stok.stok_minimum` | **signed** `int` | `int` ✓ |
| I | `lab_paket_item` | composite PK, no `id` | `id_present=0`, PK `paket_id,tindakan_id` ✓ |
| H | `resep.konsultasi_id` / `resep.rekam_medis_id` | 0 FKs | `resep_fks_total=3`, `fks_on_the_two_bare_columns=0` ✓ |
| J | `invoice` | has `idx_ref`, **no** FK on `referensi_id` | `idx_ref(referensi_tipe,referensi_id)`, `invoice_fks_total=1` ✓ |
| J | `pembayaran.nomor_referensi` | **no** index | `indexes_naming_nomor_referensi=0` ✓ |
| A.10/A.11 | `pasien_penjamin.faskes_rujukan_id` | **0** FKs | `pasien_penjamin_fks_total=2`, `fks_on_faskes_rujukan_id=0` ✓ |

(An earlier draft of this table carried `resep.konsdrug_id` — a typo of mine, in this
evidence file only. The column queried was and is `konsultasi_id`; no measurement was
affected.)

---

## 13. DATA SAFETY

### Before / after census

| Database | Tables before | Tables after | Non-zero rows after |
| --- | --- | --- | --- |
| `telemedisin_db` | 74 | **82** | `migrations` = 78 only |
| `telemedisin_db_test` | 74 | **82** | `migrations` = 78 only |
| `sehatly` | **10** | **10** | `migrations` = 5, `sessions` = 1 (both pre-existing) |
| `db_simprapkl` | 26 | 26 | untouched |
| `gawaiseken` | 18 | 18 | untouched |
| `laravel` | 5 | 5 | untouched |
| `trading_journal` | 12 | 12 | untouched |
| `ukk` | 12 | 12 | untouched |
| `ukk_pengaduan_sekolah` | 15 | 15 | untouched |
| `manajemen-surat` | 0 | 0 | untouched |

**Zero domain rows in either project database.** The only non-zero table is Laravel's own
`migrations` ledger. Every table in all three project databases was `COUNT(*)`-ed
individually, not sampled.

**`sehatly` is untouched and still holds its ORIGINAL 5 migration rows** — which
include the three scaffold migrations that todo 7 deleted from disk, proving `migrate`
has never been run against it:

```
0001_01_01_000000_create_users_table          batch 1
0001_01_01_000001_create_cache_table          batch 1
0001_01_01_000002_create_jobs_table           batch 1
2024_01_01_000000_create_passkeys_table       batch 2
2025_08_14_170933_add_two_factor_columns...   batch 2
```

### Probe artifacts

All gone, and proven gone:

- every `INSERT` in both negative-QA probes ran inside a transaction that ended in
  `ROLLBACK`; all 11 tables read **0** afterwards (§5, AC4);
- the parent rows those probes needed (`users`, `pasien`, `dokter`) are inside the same
  rolled-back transaction — `users_after_rollback = 0`, `pasien_after_rollback = 0`,
  `dokter_after_rollback = 0`;
- both probe mutations were reverted and hash-proven identical (§10);
- both databases were re-migrated afterwards and re-measured: `jenis` back to 5 values,
  `checks` back to 3 on both;
- no scratch database was ever created. The only temporary artifacts are four scripts
  under `%LOCALAPPDATA%\Temp\opencode\`, **outside the repository**, which is where
  tooling belongs.

**No file was deleted that I did not create.** No file was deleted at all.

---

## 14. VERBATIM DATABASE OUTPUT

### `SHOW CREATE TABLE ulasan_dokter`

```sql
CREATE TABLE `ulasan_dokter` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `konsultasi_id` bigint unsigned NOT NULL,
  `pasien_id` bigint unsigned NOT NULL,
  `dokter_id` bigint unsigned NOT NULL,
  `rating` tinyint unsigned NOT NULL,
  `rating_komunikasi` tinyint unsigned DEFAULT NULL,
  `rating_akurasi` tinyint unsigned DEFAULT NULL,
  `isi` text COLLATE utf8mb4_unicode_ci,
  `is_anonim` tinyint(1) NOT NULL DEFAULT '1',
  `balasan_dokter` text COLLATE utf8mb4_unicode_ci,
  `dibalas_at` datetime DEFAULT NULL,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ulasan_dokter_konsultasi_id_unique` (`konsultasi_id`),
  KEY `idx_ulasan_dokter` (`dokter_id`,`rating`),
  KEY `ulasan_dokter_pasien_id_foreign` (`pasien_id`),
  CONSTRAINT `ulasan_dokter_dokter_id_foreign` FOREIGN KEY (`dokter_id`) REFERENCES `dokter` (`id`),
  CONSTRAINT `ulasan_dokter_pasien_id_foreign` FOREIGN KEY (`pasien_id`) REFERENCES `pasien` (`id`),
  CONSTRAINT `ulasan_dokter_chk_1` CHECK ((`rating` between 1 and 5)),
  CONSTRAINT `ulasan_dokter_chk_2` CHECK ((`rating_komunikasi` between 1 and 5)),
  CONSTRAINT `ulasan_dokter_chk_3` CHECK ((`rating_akurasi` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `SHOW CREATE TABLE persetujuan_pdp`

```sql
CREATE TABLE `persetujuan_pdp` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `jenis` enum('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis','pemasaran','komunikasi_tindak_lanjut') NOT NULL,
  `versi_dokumen` varchar(20) NOT NULL,
  `disetujui` tinyint(1) NOT NULL,
  `disetujui_at` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_consent` (`user_id`,`jenis`,`versi_dokumen`),
  CONSTRAINT `persetujuan_pdp_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### `SHOW CREATE TABLE akses_rekam_medis_log`

```sql
CREATE TABLE `akses_rekam_medis_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rekam_medis_id` bigint unsigned NOT NULL,
  `pengakses_user_id` bigint unsigned NOT NULL,
  `tujuan_akses` enum('perawatan','klaim','audit','pasien_sendiri','kepentingan_hukum') NOT NULL,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `akses_rekam_medis_log_rekam_medis_id_foreign` (`rekam_medis_id`),
  KEY `akses_rekam_medis_log_pengakses_user_id_foreign` (`pengakses_user_id`),
  CONSTRAINT `akses_rekam_medis_log_rekam_medis_id_foreign` FOREIGN KEY (`rekam_medis_id`) REFERENCES `rekam_medis` (`id`) ON DELETE CASCADE,
  CONSTRAINT `akses_rekam_medis_log_pengakses_user_id_foreign` FOREIGN KEY (`pengakses_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

**No `diubah_at`, no `updated_at`.** Two indexes, both InnoDB FK-support, neither named
by the DDL and neither reported as drift.

### The `rating = 6` rejection

```
ERROR 3819 (HY000) at line 28: Check constraint 'ulasan_dokter_chk_1' is violated.
```

---

## 15. THE DEFERRED-CONSTRAINT POSITION

**This batch owes no deferred constraint, and registers none.** The *Deferred
constraints* table in `docs/schema-notes.md` still holds exactly one row,
`fk_vital_rm`, added by migration `2026_10_01_000076` per SQL section `[14]`
(`:1161`-`:1163`). `pasien_tanda_vital.rekam_medis_id` still has **0** foreign keys,
confirmed live.

`fk_vital_rm` is the **only** constraint migration 76 adds. It was **not** added here,
and **no constraint was added to `pasien_penjamin.faskes_rujukan_id`**, which A.10/A.11
settled as bare by contract and which I re-verified live
(`fks_on_faskes_rujukan_id = 0`). All eleven of batch K's own foreign keys point at
tables that already exist — `users` 12, `pasien` 20, `pasien_anggota_keluarga` 21,
`dokter` 31, `rekam_medis` 42, and `artikel_kategori` 70 from inside this same batch.

`migrate:rollback --step=8` and `migrate` both exit 0, and the fingerprint is identical
across the cycle.

---

## 16. COMMITTED PATHS

```
database/migrations/2026_10_01_000068_notifikasi_table.php            (new)
database/migrations/2026_10_01_000069_ulasan_dokter_table.php         (new)
database/migrations/2026_10_01_000070_artikel_kategori_table.php      (new)
database/migrations/2026_10_01_000071_artikel_table.php               (new)
database/migrations/2026_10_01_000072_home_care_pesanan_table.php     (new)
database/migrations/2026_10_01_000073_audit_log_table.php             (new)
database/migrations/2026_10_01_000074_persetujuan_pdp_table.php       (new)
database/migrations/2026_10_01_000075_akses_rekam_medis_log_table.php (new)
docs/schema-notes.md                                                  (+353 / -0)
.omo/evidence/task-17-sehatly.md                                      (new)
```

Commit message: `feat(db): migrate notification, review, content, home care, audit and consent tables`
(exactly as dispatched; note the plan's own todo-17 commit line reads
`feat(db): migrate notification, review, content, audit and consent tables` — **without
"home care"**. The dispatched brief's message was used, as instructed, and the divergence
is recorded here rather than silently reconciled, per A.22.)

---

## 17. FOUND BUT DELIBERATELY NOT FIXED

1. **`tests/Unit/Console/VerifySchemaDeferredConstraintTest.php:249`** — asserts
   `missing_table > 0` on the full run; the plan's own A.9 table says 0 at todo 17.
   Outside my pathspec; editing a test to green a suite is the failure mode this project
   fights. Full analysis and the exact one-line fix in §9.
2. **Plan line 390, two wrong `:NNN` citations** (`idx_notif` at `:1045` → `:1047`; the
   three `CHECK`s at `:1052-1054` → `:1055-1057`). The plan is orchestrator-owned and
   under active edit; reported, not fixed. Note the plan's *own* line-index table at line
   134 is correct for all eight tables, so the correction is a prose-only edit.
3. **Plan line 390 omits `artikel.reviewer_user_id` from its bare-column discussion**,
   although the authoritative list at plan line 181 names it with the correct `:1078`.
   Reported; the migration says so in place.
4. **Plan line 395's commit message omits "home care"** relative to the dispatched brief's.
   Used the brief's, as instructed; divergence recorded.
5. **`000046_rekam_medis_persetujuan_table.php`'s forward reference to
   `persetujuan_pdp.uq_consent`** was re-read and is **accurate** — it correctly
   distinguishes patient-level clinical consent from platform-level PDP consent, and
   names `:1144` correctly. No action needed. Recorded because I expected to find a
   fourth wrapped-ENUM misattribution there (A.23) and did not, and saying so is worth
   as much as the findings.

**`docs/migration-order.md` rule 6 was re-read and is now CORRECT** — it carries the
five-entry wrapped-ENUM list (`booking.status`, `master_obat.bentuk_sediaan`,
`resep.status`, `invoice.status`, `persetujuan_pdp.jenis`) and explicitly records that
an earlier six-entry version named two entries that are not wrapped. The orchestrator's
rule-6 fix landed before I read it, so there is nothing to report. I did **not** edit
that file.

## 18. QUOTE VERIFICATION — and the corruption it found in this file

Plan appendix A.15's standing rule 1 is that *"the only sufficient check on prose is a
character-by-character read by a party that did not write it"*, and that **"accurate"
and "present" are different properties**. I wrote both sides of every quotation here, so
I checked them **mechanically** instead: every line beginning `> ` in this file was
extracted and compared against every source line in `database/migrations/2026_10_01_*.php`
and `tests/Unit/Console/*.php`, stripping only the `* ` or `// ` comment prefix.

```
quoted prose lines checked: 119
byte-for-byte identical to a source line: 96
identical after stripping the blockquote marker's width: 23
genuinely altered: 0
```

**The check found five real verbatim violations in this evidence file, all mine:**

1. **Four U+2014 em-dashes typed as ASCII hyphens** in quoted passages from `000074` and
   `000075`. The migrations' `/** */` docblocks use em-dashes; my transcription used
   `-`. Invisible on a read, and precisely the A.17 corruption class.
2. **`carries NO COMMENT text` lowercased to `carries no \`COMMENT\` text`** — and I
   added backticks the source does not have. A quote that silently alters the source is
   worse than a paraphrase, because it borrows the source's authority.

All five were fixed **by extraction, not by retyping** — a script pulled the exact source
line and wrote it in, because retyping is what produced the corruption in the first place.

**A residual instrument limitation, disclosed rather than hidden.** A second checker that
compares each multi-line block as one joined string reports **14 of 15** blocks verbatim.
The fifteenth is the four-line `ATTRIBUTION` passage, and the line-by-line checker
confirms all four of its lines are exact; the block-*joiner* is the imprecise instrument
there, not the quotation. Two of my own checkers disagreed on that one block, and I
resolved it by running the stricter, more granular test rather than by preferring
whichever answer I wanted — which is the only defensible resolution when two instruments
conflict.

**A fifth, independent corruption in this file**, caught by the same discipline: the
regression-spot-check table carried `resep.konsdrug_id` for `resep.konsultasi_id`. The
column queried was and is `konsultasi_id`; no measurement was affected. Corrected, and
noted in place rather than quietly fixed.

**Total identifier corruptions generated by this task: thirteen** — eight foreign keys
written as comments (§11.1), one `Schema::create()` in a docblock (§11.3), one statement
order broken by a bad restore (§11.4), two in this evidence file caught by the quote
checker (§18), and the one below. **All thirteen were caught by a check rather than by
reading, and none reached a committed migration file.** The one that did reach a
committed file — the eight comment-only foreign keys — was caught by `verify-schema` in
under a second, after `php -l` and `migrate:fresh` had both reported success.

## 19. THE NEAR-MISS THAT MATTERS MOST — I declared a correct instrument broken

The token audit of this file flagged `syarat_ketentuan` as absent from
`telemedicine_test.sql`. It is present. My first reaction was that the **audit** was
broken, and I said so in a code comment, on the grounds that a direct `Contains()` on the
same file returned `true`.

**I was wrong, and the instrument was right.** What I had actually proved was that
`str_contains($sql, 'syarat_ketentuan')` is `true` — which says nothing about whether the
*evidence file* spells the token correctly, because a correct spelling is a substring of
several wrong ones. Chasing my own reassurance rather than the anomaly took four further
probes, and the real defect was a **single substituted letter inside an ENUM value**, at
line 745 of this file:

```
found:     syarat_ketentukan      <- 'a' replaced by 'k' in the final syllable
evidence:  syarat_ketentuan       <- correct, 16 characters
SQL:       syarat_ketentuan       <- correct, 16 characters
```

`syarat_ketentuan` is the **first value of the wrapped ENUM this batch exists to get
right** — the one whose `:1138` continuation holds two more values. A one-letter
corruption of it, in the document whose job is to prove the value was read from both
lines, is the single most embarrassing thing in this file, and it survived four
read-throughs because it is *readable*. Every human pass, including my own, read
`syarat_ketentukan` as `syarat_ketentuan` because the eye completes the word.

**The generalised lesson, and it is the one I would most like carried forward:** when two
instruments disagree, the resolution is not to pick the more comfortable answer, and not
even to pick the stricter one — it is to ask **what question each was actually asked**.
My `Contains()` sanity check was asked "is this string in the SQL?" and answered
correctly. The audit was asked "does this file spell the token the way the SQL does?" and
answered correctly too. I had treated the first as a refutation of the second, which is
A.18's exact shape one level up: not a document nobody read, and not a tool nobody asked,
but **a result nobody compared against its own question.**

Fixed, and the audit now reports **0 unexplained absences** across 164 distinct
`snake_case` tokens in this file.
