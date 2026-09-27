# Task 13 evidence — Migration batch G (medical record and its sub-tables)

Plan: `.omo/plans/sehatly-telemedicine-platform.md` todo 13 (plan lines 355-361).
Branch `feat/sehatly-telemedicine`. PHP `8.4.17` from
`C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64` (first line of every shell).
MySQL `8.0.30` at `127.0.0.1:3306`, user `root`, empty password.

Deliverable: `2026_10_01_000042_rekam_medis_table.php` …
`2026_10_01_000046_rekam_medis_persetujuan_table.php`, plus one new section in
`docs/schema-notes.md`. Nothing else was created. No Model, Resource, Controller,
seeder, factory or route was authored (todos 19 and 33 own those).

---

## 1. Rule 0 — the DDL is the transcript, and the plan's prose is not

`telemedicine_test.sql` was read directly for every value written. `git diff
--exit-code telemedicine_test.sql` → **exit 0**. Worktree SHA-256 at the end:
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, identical to
the value in the brief. Size 59 604 bytes. A.3's line-integrity trap was respected:
`git diff --exit-code` is the canonical check, not a byte-hash of
`git cat-file -p HEAD:…` against the worktree.

### 1a. Plan/brief errors found (the SQL won every time)

| # | what the plan / brief claimed | what `telemedicine_test.sql` actually says | line |
|---|---|---|---|
| 1 | `status_tindak_lanjut ENUM('pulang_dengan_obat','kontrol','rujak','rawat_inap','ke_igd')` — plan line 356 **and** the dispatch brief both spell the third value **`rujak`** | **`rujuk`** (with a K) | **643** |
| 2 | "six `riwayat_*`/`psikososial` text columns" — plan line 356, unnamed | **four** columns are named `riwayat_*`: `riwayat_penyakit_sekarang`, `riwayat_penyakit_dahulu`, `riwayat_keluarga`, `riwayat_psikososial`. The **six**-column narrative block is `:631`-`:636`, and its other two members are `keluhan_utama` (`:631`) and `hasil_pemeriksaan_fisik` (`:636`) | 631-636 |
| 3 | `INDEX idx_rm_pasien` cited at **`:655`** | `:654` (`:655` is `) ENGINE=InnoDB;`) | 654 |
| 4 | `status_dokumen` cited at **`:654`** | `:645` | 645 |
| 5 | `versi` cited at **`:655`** | `:646` | 646 |
| 6 | `INDEX idx_diag_icd10` cited at **`:665`** | `:666` (`:665` is the `FOREIGN KEY` line) | 666 |
| 7 | Plan line 179 says `rekam_medis_tindakan.icd9cm_kode` is at `:671`; the plan's own authoritative line-index table says `:672` | `:672` (`:671` is `rekam_medis_id`) — the **line-index table is right**, per A.16 | 672 |
| 8 | A.14's corrected note calls `rekam_medis` the "**third**-widest" table, "23 columns, behind `pasien` 31 and `rekam_medis` 28" | `rekam_medis` has **28** columns and is the **second**-widest. Measured with the project's own `SqlSchemaParser`: `pasien` 31, `rekam_medis` **28**, `dokter` 23, `booking` **22** | — |
| 9 | Dispatch brief: "`rekam_medis_tindakan.icd9cm_kode` … also carries **NO FOREIGN KEY**" | correct | 672 |
| 10 | Dispatch brief: "`rekam_medis_diagnosa.is_terkonfirmasi TINYINT(1)` DEFAULT 0" | `is_terkonfirmasi TINYINT(1) **NOT NULL** DEFAULT 0` — the `NOT NULL` was omitted from the brief but reproduced | 664 |
| 11 | Dispatch brief spot-check: "`konsultasi` still has no FK on `booking_id` beyond its UNIQUE" | The SQL **does** declare `FOREIGN KEY (booking_id) REFERENCES booking(id)` at `:557`, and the live schema has exactly one (`konsultasi_booking_id_foreign`). "Beyond its UNIQUE" reads as "no extra FK"; measured FK count is 1, which is the SQL's own. No regression, but the phrasing is misleading. | 557 |

### 1b. A pre-existing comment defect found in a batch-F file — reported, not edited

`2026_10_01_000041_rujukan_table.php:93-96` claims:

> `diagnosis_kerja VARCHAR(255) NULL` (`:605`) repeats the short working-diagnosis
> label that `konsULTIA.diagnosis_kerja` (`:552`) and **`surat_keterangan` also
> carry**

`surat_keterangan` (`:581`-`:597`) has **no** `diagnosis_kerja` column. Measured:
`diagnosis_kerja` occurs at exactly three lines in the whole DDL — `:552`
(`konsultasi`), `:605` (`rujukan`), `:641` (`rekam_medis`). So the label is a
**third** copy, not a fourth, and `surat_keterangan` is not one of them.

This is exactly A.15's defect class (a comment contradicting the schema) in a
file owned by todo 12. **I did not edit it**: my brief scopes this commit to my own
paths, and silently widening it would break the "commit contains only your paths"
assertion. It is todo 12's to fix, and todo 19's `RekamMedis` docblock should not
inherit the claim. My own `000042` comment states the corrected fact and says
explicitly that the sibling claim is wrong.

---

## 2. Rule 1 — the two load-bearing traps, quoted verbatim

### TRAP (a) — `status_dokumen` defaults to `'final'`

From `2026_10_01_000042_rekam_medis_table.php` (class docblock, and repeated as
an inline comment directly above the `->default('final')` call):

```
 * **(a) TRAP — `status_dokumen` DEFAULTS TO `'final'`, NOT `'draft'` (`:645`).**
 * The DDL reads
 *
 *     status_dokumen ENUM('draft','final','diamendemen') NOT NULL DEFAULT 'final',
 *
 * three values in that exact order, and the default is the **second** of the
 * three, not the first. `draft` sorts first because ENUM member order is the sort
 * index, which makes the default easy to mis-transcribe as `draft` on a fast
 * read; live `information_schema.COLUMNS.COLUMN_DEFAULT` is the string `final`.
 *
 * **Consequence, and it is the whole point of this note: todo 33's record service
 * MUST pass `status_dokumen` explicitly on create.** A record inserted without the
 * column lands in the immutable `final` state, and the schema offers no way back
 * other than writing `diamendemen` — there is no `updated_at`-gated edit
 * permission and no trigger — so a mistake at creation time is not recoverable by
 * omitting the field again. Anything that creates records in bulk (seeders,
 * importers, the todo 32 consultation-completion path) inherits the same trap.
```

### TRAP (b) — `versi` is an amendment counter with no linkage column

```
 * **(b) TRAP — `versi` IS AN AMENDMENT COUNTER WITH NO LINKAGE COLUMN (`:646`).**
 * `versi TINYINT UNSIGNED NOT NULL DEFAULT 1` is the amendment counter and the
 * statement declares **four** `FOREIGN KEY` clauses (`:650`-`:653`, on
 * `pasien_id`, `faskes_id`, `dokter_id`, `konsultasi_id`) and **not one** of them
 * is a self-reference: there is no `parent_id`, no `rekam_medis_id`, no
 * `amends_id`, and no self-referencing `FOREIGN KEY` on `id`. **The amendment
 * chain is therefore a convention, not a constraint, and the database cannot
 * enforce it in any direction:**
 *
 *   - it cannot stop two rows both being `versi = 1`;
 *   - it cannot stop two rows both being `versi = 1, status_dokumen = 'final'`;
 *   - it cannot stop `versi = 5` existing with no `versi = 4`;
 *   - it cannot stop a chain from being written out of order.
 *
 * The chain must be reconstructed by grouping on
 * `(pasien_id, dokter_id, tanggal_periksa)` — the triple above is the only thing
 * the schema holds constant across versions of one record, and it is a
 * **convention**: nothing in the database guarantees that two rows sharing it are
 * versions of the same record, so a doctor who examines the same patient twice on
 * the same `tanggal_periksa` is representable and would be misread as a second
 * version of the first. `konsultasi_id` (`:627`, nullable) is a *better* thread
 * key when it is present, because a consultation is one encounter, but it is
 * nullable, so it cannot be the only key. Todo 19's model docblock and
 * `docs/schema-notes.md` both carry this; it is todo 33 that owns the write
 * path.
 *
 * `versi` is declared `TINYINT UNSIGNED`, so the signed `tinyInteger()` helper is
 * wrong twice: it would emit a signed column, and it would admit a negative
 * version number. `unsignedTinyInteger()` is the only correct call.
```

Recorded as a limitation the database cannot enforce in
`docs/schema-notes.md` → "Batch-G schema limitations the database cannot enforce
(todo 13)", first bullet, headed "**Amendment linkage is a convention, not a
constraint**".

### Comment-accuracy read-back (A.15 standing rule 2)

Every comment in all five files was re-read against the code immediately beneath
it and against the DDL, character by character. **Defects found and fixed in my
own prose — 9 of them, all pre-commit:**

1. `000042` claimed "one of only **four** `DATETIME NOT NULL` columns in the
   batch". Measured: the batch has **four** `DATETIME` columns, **three** of them
   `NOT NULL` (`:630`, `:675`, `:700`) and one nullable (`:647`). Rewritten with
   the correct decomposition.
2. `000042` claimed the default is the "**last-but-one**" ENUM member. True but
   needlessly oblique; rewritten to "the **second** of the three", matching the
   inline comment.
3. `000042` repeated A.14's "third-widest" superlative and attributed 23 columns
   to `booking`. Replaced with the measured ranking (§1a #8).
4. `000042` asserted the SATUSEHAT width rationale ("the width is the
   SATUSEHAT `encounter.id` allowance") as fact. The DDL states no reason. Now
   marked as an inference.
5. `000042` said "a record is **never** hard-deleted". The schema permits a hard
   `DELETE` and the sub-tables cascade. Rewritten: hard deletion is a last resort
   and the amendment flow is the only non-destructive alternative.
6. `000043` claimed `$table->timestamps()` "would produce **six** drift rows" —
   an unverifiable count inherited from an existing note in `schema-notes.md`.
   Replaced with the derivable statement (two `extra_column` rows).
7. `000043` claimed the `VARCHAR(8)` width "is wider than the longest ICD-10 code
   in practice". Not derivable from the DDL. Replaced with "treat 8 as the
   contract rather than as a claim about how long an ICD-10 code can be", plus
   the two matching master citations `:117` / `:124`.
8. `000044` said `icd9cm_kode` was "the **one** place in this batch where the
   no-FK rule costs a query plan". `rekam_medis_lampiran.diunggah_oleh` (`:687`)
   is also unindexed. Both are now named.
9. `000046` called `ditandatangani_at` "**minute**-level". `DATETIME` is declared
   with no fractional-seconds precision, so it stores whole **seconds**. Fixed in
   both the docblock and the inline comment.

Also softened, in the same pass, four speculative "why" claims that the DDL does
not state (`000044`'s nullable-doctor rationale, `000045`'s nullable-signature
rationale and its reference to a `docs/timezone-policy.md` that does not exist
yet, `000042`'s "a draft record is not signed yet", and `000043`'s "a differential
is the expected starting state"). Each is now labelled as a reading of the shape
rather than a schema rule.

Two further claims were **verified** rather than softened: `dokter` has no
`dihapus_at` (the only two in the DDL are `users` `:148` and `pasien` `:249`),
and `akses_rekam_medis_log` (`:1147`) carries `pengakses_user_id` (`:1150`), which
records who *read* a record, not who attached a file.

---

## 3. The six narrative columns, derived from the DDL (not named in the plan)

`:631`-`:636`, in DDL order, all `TEXT NULL`:

| # | column | SQL line | DDL comment |
|---|---|---|---|
| 1 | `keluhan_utama` | 631 | — |
| 2 | `riwayat_penyakit_sekarang` | 632 | — |
| 3 | `riwayat_penyakit_dahulu` | 633 | — |
| 4 | `riwayat_keluarga` | 634 | — |
| 5 | `riwayat_psikososial` | 635 | `'Merokok, alkohol, aktivitas fisik'` |
| 6 | `hasil_pemeriksaan_fisik` | 636 | — |

**Only four of the six are named `riwayat_*`** (rows 2-5, which also cover
`psikososial`). The other two are the presenting complaint and the physical-exam
result. The plan's "six `riwayat_*`/`psikososial` text columns" therefore names a
count that does not exist under that description; the six-column *block* is real
and is what the plan was reaching for. All six are in the docblock by name, and
so are the four literal `riwayat_*` names.

Column counts, measured with `SqlSchemaParser`: `rekam_medis` **28**,
`rekam_medis_diagnosa` **7**, `rekam_medis_tindakan` **7**,
`rekam_medis_lampiran` **7**, `rekam_medis_persetujuan` **8**.

---

## 4. Rule 3 — every expected literal derived, never typed

Two independent auditors, both written from source and both run against the live
schema:

- **ENUM-order audit** (`%TEMP%\opencode\t13\enums2.php`): extracts the 7 ENUM
  value lists from the `Schema::create` bodies of my five files with a regex, and
  separately extracts them from the DDL by line, then requires each emitted list
  to be **identical in value and order** to a DDL list and every DDL list to be
  covered. Result: `all 7 ENUM lists identical in value AND order, 0 orphans
  either way`.
- **Column parity audit** (`%TEMP%\opencode\t13\audit.php`): **does not reuse the
  project's `SqlSchemaParser` or `TypeNormaliser`.** Both sides are normalised by
  the auditor's own fold table, so a bug in the project's normaliser cannot hide a
  mismatch and vice versa. Folds encoded, each one because its absence produced a
  phantom mismatch in a previous batch:
  1. integer display widths dropped (`int(11)` → `int`), MySQL 8.0.19 deprecated them;
  2. `ENUM`/`SET` member order and case **preserved** (data, not spelling);
  3. numeric default quoting normalised (`'0'` == `0`);
  4. surrounding single quotes stripped on a non-numeric default (`'final'` == `final`);
  5. a nullable column with no `DEFAULT` == `DEFAULT NULL`;
  6. `COMMENT '…'` stripped before parsing (it may contain commas, `--` or quotes);
  7. `ON UPDATE CURRENT_TIMESTAMP` peeled off **before** the `DEFAULT` match — without this the greedy `DEFAULT` regex swallows it and every `diubah_at` reports a phantom mismatch;
  8. inline `PRIMARY KEY` recorded into the PK set, not only the table-level `PRIMARY KEY (…)` form — without this the sub-tables, whose PK is inline on `id`, hit a fatal;
  9. **the last line of the `CREATE TABLE` body is flushed** — it carries no trailing comma, so a naive `explode(',')` drops it;
  10. primary-key columns folded to `NOT NULL` on both sides.

Result on `telemedisin_db` **and** `telemedisin_db_test`:

```
===== rekam_medis =====
  columns compared: 28 (ref 28 / live 28)
===== rekam_medis_diagnosa =====   columns compared: 7
===== rekam_medis_tindakan =====   columns compared: 7
===== rekam_medis_lampiran =====   columns compared: 7
===== rekam_medis_persetujuan ==   columns compared: 8
==== TOTALS ====
columns compared: 57
unsigned flags audited: 16
mismatches: 0
```

Identifier sanity check, independent of both auditors: every column name passed to
`->string/->text/->enum/->dateTime/->date/->timestamp/->unsignedBigInteger/->boolean/->char/->index`
in the five files was regex-matched against the DDL text — **56 identifiers, 0 not
found**. This is the check that would have caught a todo-12-style misspelling.

---

## 5. Mechanical citation verification

Method: every `:NNN` in the five new files was extracted by regex, de-duplicated,
and the **actual SQL line printed** for each. **80 distinct lines cited, 0 wrong.**
Each was then compared against the claim made about it. Full dump:

```
  117 | kode VARCHAR(8) NOT NULL UNIQUE,                                     (master_icd10.kode)
  124 | kode VARCHAR(8) NOT NULL UNIQUE,                                     (master_icd9cm.kode)
  148 | dihapus_at TIMESTAMP NULL DEFAULT NULL                               (users)
  249 | dihapus_at TIMESTAMP NULL DEFAULT NULL,                              (pasien)
  291 | icd10_kode VARCHAR(8) NULL,                                         (pasien_riwayat_penyakit)
  548 | catatan_subjektif TEXT NULL,                                        (konsultasi SOAP 1)
  551 | catatan_plan TEXT NULL,                                             (konsultasi SOAP 4)
  552 | diagnosis_kerja VARCHAR(255) NULL,                                   (konsultasi)
  592 | qr_token VARCHAR(100) NOT NULL COMMENT 'Token QR verifikasi keaslian',(surat_keterangan)
  605 | diagnosis_kerja VARCHAR(255) NULL,                                   (rujukan)
  621 | CREATE TABLE rekam_medis (
  622 | id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  623 | uuid CHAR(36) NOT NULL UNIQUE,
  627 | konsultasi_id BIGINT UNSIGNED NULL,
  628 | satusehat_encounter_id VARCHAR(50) NULL,
  629 | tipe_kunjungan ENUM('telemedisin','rawat_jalan','rawat_inap','igd','home_visit') NOT NULL,
  630 | tanggal_periksa DATETIME NOT NULL,
  631 | keluhan_utama TEXT NULL,
  632 | riwayat_penyakit_sekarang TEXT NULL,
  633 | riwayat_penyakit_dahulu TEXT NULL,
  634 | riwayat_keluarga TEXT NULL,
  635 | riwayat_psikososial TEXT NULL COMMENT 'Merokok, alkohol, aktivitas fisik',
  636 | hasil_pemeriksaan_fisik TEXT NULL,
  637 | subjektif TEXT NULL COMMENT 'SOAP - S',
  640 | plan TEXT NULL COMMENT 'SOAP - P',
  641 | diagnosis_kerja VARCHAR(255) NULL,
  643 | status_tindak_lanjut ENUM('pulang_dengan_obat','kontrol','rujuk','rawat_inap','ke_igd') NULL,
  644 | jadwal_kontrol DATE NULL,
  645 | status_dokumen ENUM('draft','final','diamendemen') NOT NULL DEFAULT 'final',
  646 | versi TINYINT UNSIGNED NOT NULL DEFAULT 1,
  647 | ditandatangani_at DATETIME NULL,
  648 | dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  649 | diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  650 | FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  653 | FOREIGN KEY (konsultasi_id) REFERENCES konsultasi(id),
  654 | INDEX idx_rm_pasien (pasien_id, tanggal_periksa)
  657 | CREATE TABLE rekam_medis_diagnosa (
  658 | id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  659 | rekam_medis_id BIGINT UNSIGNED NOT NULL,
  660 | icd10_kode VARCHAR(8) NOT NULL,
  661 | deskripsi VARCHAR(255) NULL,
  662 | jenis ENUM('utama','sekunder','diferensial','komplikasi') NOT NULL,
  663 | tipe_kasus ENUM('baru','lama') NOT NULL DEFAULT 'baru',
  664 | is_terkonfirmasi TINYINT(1) NOT NULL DEFAULT 0,
  665 | FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE,
  666 | INDEX idx_diag_icd10 (icd10_kode)
  669 | CREATE TABLE rekam_medis_tindakan (
  670 | id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  671 | rekam_medis_id BIGINT UNSIGNED NOT NULL,
  672 | icd9cm_kode VARCHAR(8) NULL,
  673 | nama_tindakan VARCHAR(255) NOT NULL,
  674 | keterangan TEXT NULL,
  675 | tanggal_tindakan DATETIME NOT NULL,
  676 | dokter_pelaksana_id BIGINT UNSIGNED NULL,
  677 | FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE,
  678 | FOREIGN KEY (dokter_pelaksana_id) REFERENCES dokter(id)
  681 | CREATE TABLE rekam_medis_lampiran (
  682 | id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  683 | rekam_medis_id BIGINT UNSIGNED NOT NULL,
  684 | nama_file VARCHAR(255) NOT NULL,
  685 | file_url VARCHAR(500) NOT NULL,
  686 | tipe ENUM('hasil_lab','radiologi','foto_klinis','dokumen_lain') NOT NULL,
  687 | diunggah_oleh BIGINT UNSIGNED NOT NULL,
  688 | dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  689 | FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE
  692 | CREATE TABLE rekam_medis_persetujuan (
  693 | id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  694 | rekam_medis_id BIGINT UNSIGNED NOT NULL,
  695 | tipe ENUM('general_consent','persetujuan_tindakan','penolakan_tindakan') NOT NULL,
  696 | isi_persetujuan TEXT NOT NULL,
  697 | ditandatangani_oleh VARCHAR(150) NOT NULL,
  698 | hubungan_dengan_pasien VARCHAR(50) NULL,
  699 | tanda_tangan_url VARCHAR(500) NULL,
  700 | ditandatangani_at DATETIME NOT NULL,
  701 | FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE
 1134 | CREATE TABLE persetujuan_pdp (
 1144 | UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)
 1147 | CREATE TABLE akses_rekam_medis_log (
 1150 | pengakses_user_id BIGINT UNSIGNED NOT NULL,
 1161 | ALTER TABLE pasien_tanda_vital
 1162 |   ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)
 1163 |   REFERENCES rekam_medis(id) ON DELETE SET NULL;
```

Ranges cited (`:622-654`, `:631-:636`, `:637-:640`, `:650-:653`, `:658-666`,
`:670-678`, `:682-689`, `:693-701`) were each checked by line arithmetic against
the SQL: 33, 6, 4, 4, 9, 9, 8 and 9 lines respectively, and every range's stated
decomposition (columns + `FOREIGN KEY` lines + `INDEX` lines) sums exactly.

---

## 6. Acceptance criteria

### 6.1 `php artisan migrate:fresh` — both databases

```
php artisan config:clear                                        -> exit 0
php artisan migrate:fresh --no-interaction   (telemedisin_db)      -> exit 0
$env:DB_DATABASE='telemedisin_db_test'; php artisan migrate:fresh -> exit 0
```

The test run was done in a **fresh shell with a real `DB_DATABASE` environment
variable**, because Laravel's dotenv is immutable and a real env var beats
`.env`. Confirmed by `php artisan db:show --json` reporting
`"database":"telemedisin_db_test"`. Both databases were migrated again after the
comment read-back edits, so the greens above describe the **final** files.

### 6.2 `verify-schema --tables=<5>` — A.7/A.8 traps

```
 live database mysql / telemedisin_db
 scope rekam_medis, rekam_medis_diagnosa, rekam_medis_tindakan, rekam_medis_lampiran, rekam_medis_persetujuan
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 counts tables=53 views=0 columns=435 indexes=146 foreign_keys=61 checks=0
 Discrepancies: 0 (0 drift, 0 informational)
 PASS — 75 tables, 2 views verified. Nothing was written.
exit 0
```

Identical on `telemedisin_db_test` (`live database mysql / telemedisin_db_test`,
same `scope`, same `Discrepancies: 0`, exit 0).

Both A.7/A.8 traps handled explicitly:

- **The `scope` line lists all five real names** — read and confirmed, not
  inferred from the exit code. This is what rules out a typo producing a green
  `unknown_requested_table`.
- **The PASS banner was ignored.** It says "75 tables, 2 views verified" after
  verifying five, exactly as A.7 documents. The load-bearing signals are
  `Discrepancies: 0` and exit 0, both of which are truthful.
- `0 informational` is itself a positive result: `fk_vital_rm` is
  outside this `--tables` scope, so it is not even listed.

### 6.3 `SHOW CREATE TABLE rekam_medis` — the SOAP spellings and the default

```
CREATE TABLE `rekam_medis` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pasien_id` bigint unsigned NOT NULL,
  `faskes_id` bigint unsigned DEFAULT NULL,
  `dokter_id` bigint unsigned NOT NULL,
  `konsULTasi_id` bigint unsigned DEFAULT NULL,
  `satusehat_encounter_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipe_kunjungan` enum('telemedisin','rawat_jalan','rawat_inap','igd','home_visit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `tanggal_periksa` datetime NOT NULL,
  `keluhan_utama` text COLLATE utf8mb4_unicode_ci,
  `riwayat_penyakit_sekarang` text COLLATE utf8mb4_unicode_ci,
  `riwayat_penyakit_dahulu` text COLLATE utf8mb4_unicode_ci,
  `riwayat_keluarga` text COLLATE utf8mb4_unicode_ci,
  `riwayat_psikososial` text COLLATE utf8mb4_unicode_ci COMMENT 'Merokok, alkohol, aktivitas fisik',
  `hasil_pemeriksaan_fisik` text COLLATE utf8mb4_unicode_ci,
  `subjektif` text COLLATE utf8mb4_unicode_ci COMMENT 'SOAP - S',
  `objektif` text COLLATE utf8mb4_unicode_ci COMMENT 'SOAP - O',
  `asesmen` text COLLATE utf8mb4_unicode_ci COMMENT 'SOAP - A',
  `plan` text COLLATE utf8mb4_unicode_ci COMMENT 'SOAP - P',
  `diagnosis_kerja` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instruksi_tindak_lanjut` text COLLATE utf8mb4_unicode_ci,
  `status_tindak_lanjut` enum('pulang_dengan_obat','kontrol','rujuk','rawat_inap','ke_igd') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jadwal_kontrol` date DEFAULT NULL,
  `status_dokumen` enum('draft','final','diamendemen') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'final',
  `versi` tinyint unsigned NOT NULL DEFAULT '1',
  `ditandatangani_at` datetime DEFAULT NULL,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diubah_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rekam_medis_uuid_unique` (`uuid`),
  KEY `rekam_medis_faskes_id_foreign` (`faskes_id`),
  KEY `rekam_medis_dokter_id_foreign` (`dokter_id`),
  KEY `rekam_medis_konsultasi_id_foreign` (`konsultasi_id`),
  KEY `idx_rm_pasien` (`pasien_id`,`tanggal_periksa`),
  CONSTRAINT `rekam_medis_dokter_id_foreign` FOREIGN KEY (`dokter_id`) REFERENCES `dokter` (`id`),
  CONSTRAINT `rekam_medis_faskes_id_foreign` FOREIGN KEY (`faskes_id`) REFERENCES `faskes` (`id`),
  CONSTRAINT `rekam_medis_konsultasi_id_foreign` FOREIGN KEY (`konsultasi_id`) REFERENCES `konsultasi` (`id`),
  CONSTRAINT `rekam_medis_pasien_id_foreign` FOREIGN KEY (`pasien_id`) REFERENCES `pasien` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

> **Typo in the block above, corrected here:** `` `konsULTasi_id` `` is a
> transcription error made while pasting this file's own output into this
> evidence file. The live column is `` `konsultasi_id` ``, as the
> `information_schema` dump in §7 shows and as the `KEY`/`CONSTRAINT` lines in the
> same block show. This is the exact corruption class A.17 describes, caught by
> re-reading, and it is recorded rather than quietly fixed because the point of
> this file is that re-reading is the only check that works on prose.

Each acceptance assertion proved by regex over the live `SHOW CREATE TABLE`
output, and independently by `information_schema`:

| assertion | result |
|---|---|
| `plan` present | **true** |
| `rencana` absent | **true** |
| `asesmen` present | **true** |
| `assesmen` absent | **true** |
| `status_dokumen` defaulting to `'final'` | **true** |

`information_schema.COLUMNS` for `status_dokumen`:
`enum('draft','final','diamendemen')`, `IS_NULLABLE = NO`, `COLUMN_DEFAULT = final`.

`SHOW CREATE TABLE` for the other four tables is in §7's block set.

### 6.4 Unit suite

```
 [derive] migrations=49 Schema::create calls=52 extracted=52 | CREATE VIEW calls=0 extracted=0
          | contract tables=75 views=2 | derived-missing tables=29 views=2 | registry=7
{"tool":"pest","result":"passed","tests":93,"passed":93,"assertions":473,"duration_ms":5653}
exit 0
```

**93 tests, 93 passed, 473 assertions.** `tests/Unit/Console/VerifySchemaCommandTest.php`
was **not** edited. The derived missing count moved **34 → 29**, which is exactly
the value A.9's table predicts for todo 13, and the registry stayed at **7**. The
suite is green with no edit, as A.9 requires.

### 6.5 Zero-match control

```
php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9
{"tool":"pest","result":"failed","tests":0,"passed":0,"assertions":0,"raw":["No tests found."]}
exit 1
```

### 6.6 Table count — measured, and the brief's arithmetic is right

```
+-------------------+-------+
| TABLE_SCHEMA      | tables|
+-------------------+-------+
| db_simprapkl      |     26|
| gawaiseken        |     18|
| laravel           |      5|
| sehatly           |     10|
| telemedisin_db    |     53|
| telemedisin_db_test|     53|
| trading_journal   |     12|
| ukk               |     12|
| ukk_pengaduan_sekolah |  15|
+-------------------+-------+

telemedisin_db: registered_extras=7, contract_tables=46, total=53
```

**53 tables per database = 46 contract + 7 registered extras.** The expected
arithmetic is confirmed by measurement. `46 + 7 = 53`.

---

## 7. `information_schema` parity dump — all five tables

Full `COLUMNS` dump (`TABLE_NAME`, `ORDINAL_POSITION`, `COLUMN_NAME`,
`COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`, `EXTRA`) is reproduced in
`.omo/evidence/` companion output during the run; the decisive rows:

```
rekam_medis             1  id                      bigint unsigned    NO   NULL   auto_increment
rekam_medis             2  uuid                    char(36)           NO   NULL
rekam_medis             7  satusehat_encounter_id  varchar(50)        YES  NULL
rekam_medis             8  tipe_kunjungan          enum('telemedisin','rawat_jalan','rawat_inap','igd','home_visit')  NO  NULL
rekam_medis             9  tanggal_periksa         datetime           NO   NULL
rekam_medis            14  riwayat_psikososial     text               YES  NULL
rekam_medis            16  subjektif               text               YES  NULL
rekam_medis            17  objektif                text               YES  NULL
rekam_medis            18  asesmen                 text               YES  NULL
rekam_medis            19  plan                    text               YES  NULL
rekam_medis            22  status_tindak_lanjut    enum('pulang_dengan_obat','kontrol','rujuk','rawat_inap','ke_igd')  YES  NULL
rekam_medis            24  status_dokumen          enum('draft','final','diamendemen')  NO  final
rekam_medis            25  versi                   tinyint unsigned   NO   1
rekam_medis            27  dibuat_at               timestamp          NO   CURRENT_TIMESTAMP  DEFAULT_GENERATED
rekam_medis            28  diubah_at               timestamp          NO   CURRENT_TIMESTAMP  DEFAULT_GENERATED on update CURRENT_TIMESTAMP
rekam_medis_diagnosa    3  icd10_kode              varchar(8)         NO   NULL
rekam_medis_diagnosa    6  tipe_kasus              enum('baru','lama')  NO  baru
rekam_medis_diagnosa    7  is_terkonfirmasi        tinyint(1)         NO   0
rekam_medis_tindakan    3  icd9cm_kode             varchar(8)         YES  NULL
rekam_medis_tindakan    7  dokter_pelaksana_id     bigint unsigned    YES  NULL
rekam_medis_lampiran    5  tipe                    enum('hasil_lab','radiologi','foto_klinis','dokumen_lain')  NO  NULL
rekam_medis_lampiran    6  diunggah_oleh           bigint unsigned    NO   NULL
rekam_medis_lampiran    7  dibuat_at               timestamp          NO   CURRENT_TIMESTAMP  DEFAULT_GENERATED
rekam_medis_persetujuan 3  tipe                    enum('general_consent','persetujuan_tindakan','penolakan_tindakan')  NO  NULL
rekam_medis_persetujuan 8  ditandatangani_at       datetime           NO   NULL
```

Unsigned flags audited: **16** across the batch (`rekam_medis` 6 — `id`,
`pasien_id`, `faskes_id`, `dokter_id`, `konsultasi_id`, `versi`; `diagnosa` 2;
`tindakan` 3; `lampiran` 3; `persetujuan` 2). Every one confirmed
`unsigned` in `COLUMN_TYPE`, including `versi` as `tinyint unsigned`.

### Indexes — by name and by ordered column

```
rekam_medis              idx_rm_pasien                              NON_UNIQUE=1  seq1 pasien_id          seq2 tanggal_periksa
rekam_medis              PRIMARY                                    NON_UNIQUE=0  seq1 id
rekam_medis              rekam_medis_uuid_unique                    NON_UNIQUE=0  seq1 uuid
rekam_medis              rekam_medis_faskes_id_foreign              NON_UNIQUE=1  seq1 faskes_id
rekam_medis              rekam_medis_dokter_id_foreign              NON_UNIQUE=1  seq1 dokter_id
rekam_medis              rekam_medis_konsultasi_id_foreign          NON_UNIQUE=1  seq1 konsultasi_id
rekam_medis_diagnosa     idx_diag_icd10                             NON_UNIQUE=1  seq1 icd10_kode
rekam_medis_diagnosa     PRIMARY                                    NON_UNIQUE=0  seq1 id
rekam_medis_diagnosa     rekam_medis_diagnosa_rekam_medis_id_foreign NON_UNIQUE=1 seq1 rekam_medis_id
rekam_medis_tindakan     PRIMARY                                    NON_UNIQUE=0  seq1 id
rekam_medis_tindakan     rekam_medis_tindakan_rekam_medis_id_foreign NON_UNIQUE=1 seq1 rekam_medis_id
rekam_medis_tindakan     rekam_medis_tindakan_dokter_pelaksana_id_foreign NON_UNIQUE=1 seq1 dokter_pelaksana_id
rekam_medis_lampiran     PRIMARY                                    NON_UNIQUE=0  seq1 id
rekam_medis_lampiran     rekam_medis_lampiran_rekam_medis_id_foreign NON_UNIQUE=1 seq1 rekam_medis_id
rekam_medis_persetujuan  PRIMARY                                    NON_UNIQUE=0  seq1 id
rekam_medis_persetujuan  rekam_medis_persetujuan_rekam_medis_id_foreign NON_UNIQUE=1 seq1 rekam_medis_id
```

Both **named** keys reproduced with the right name **and** the right column order:
`idx_rm_pasien (pasien_id, tanggal_periksa)` — `pasien_id` first, and
`idx_diag_icd10 (icd10_kode)`. The inline `UNIQUE` on `uuid` appears as
`rekam_medis_uuid_unique`, Laravel's spelling; per rule 10 an inline `UNIQUE` is
compared by semantics, so this is correct and **not** a name mismatch. The three
single-column `*_foreign` keys on `rekam_medis` and the four elsewhere are MySQL's
own FK-support indexes (absent from the DDL) and are treated as implied rather than
as `extra_index` since `27c6ca8`. `rekam_medis` has **no** separate index on
`pasien_id` because `idx_rm_pasien`'s leftmost column already covers the FK.
`rekam_medis_tindakan` has **no** index on `icd9cm_kode` and
`rekam_medis_lampiran` **none** on `diunggah_oleh` — both correct, the DDL names
neither.

### Foreign keys — reconciliation

```
rekam_medis             rekam_medis_pasien_id_foreign         -> pasien(id)        NO ACTION  NO ACTION
rekam_medis             rekam_medis_faskes_id_foreign         -> faskes(id)        NO ACTION  NO ACTION
rekam_medis             rekam_medis_dokter_id_foreign         -> dokter(id)        NO ACTION  NO ACTION
rekam_medis             rekam_medis_konsultasi_id_foreign     -> konsultasi(id)    NO ACTION  NO ACTION
rekam_medis_diagnosa    rekam_medis_diagnosa_rekam_medis_id_foreign       -> rekam_medis(id)  CASCADE    NO ACTION
rekam_medis_tindakan    rekam_medis_tindakan_rekam_medis_id_foreign       -> rekam_medis(id)  CASCADE    NO ACTION
rekam_medis_tindakan    rekam_medis_tindakan_dokter_pelaksana_id_foreign  -> dokter(id)       NO ACTION  NO ACTION
rekam_medis_lampiran    rekam_medis_lampiran_rekam_medis_id_foreign       -> rekam_medis(id)  CASCADE    NO ACTION
rekam_medis_persetujuan rekam_medis_persetujuan_rekam_medis_id_foreign    -> rekam_medis(id)  CASCADE    NO ACTION
```

**9 live foreign keys = the 9 `FOREIGN KEY` clauses in the DDL**
(`:650`, `:651`, `:652`, `:653`, `:665`, `:677`, `:678`, `:689`, `:701`).
**None invented, none missing.** Delete rules: 4 `CASCADE` (the four sub-tables'
`rekam_medis_id`), 5 `NO ACTION` (all of `rekam_medis`'s own four, plus
`dokter_pelaksana_id`) — which is MySQL's implicit result of the DDL declaring no
`ON DELETE` clause, and is the deliberate mismatch recorded in
`docs/schema-notes.md`. `faskes_id` on `rekam_medis` is the only **nullable**
foreign key on the parent table and is unconstrained in shape, not in rule.

### The four bare columns, proven FK-free

Queried through `information_schema.REFERENTIAL_CONSTRAINTS` joined to
`KEY_COLUMN_USAGE` — **not** by reading `SHOW CREATE TABLE`, per the brief:

```
+------------------+--------------------------+----------+
| TABLE_NAME       | COLUMN_NAME              | fk_count |
+------------------+--------------------------+----------+
| rekam_medis      | satusehat_encounter_id   |        0 |
| rekam_medis_diagnosa | icd10_kode          |        0 |
| rekam_medis_lampiran | diunggah_oleh        |        0 |
| rekam_medis_tindakan | icd9cm_kode          |        0 |
+------------------+--------------------------+----------+
```

| column | SQL line | why bare |
|---|---|---|
| `rekam_medis.satusehat_encounter_id` | 628 | SATUSEHAT encounter id — a string from a national health system outside this schema. A FK would be meaningless even if a lookup table existed. **Not named in the plan's todo-13 prose at all.** |
| `rekam_medis_diagnosa.icd10_kode` | 660 | Bare indexed string; `idx_diag_icd10` (`:666`) makes it fast and validates nothing. `master_icd10` (table 10) exists and matches `VARCHAR(8)`. |
| `rekam_medis_tindakan.icd9cm_kode` | 672 | Bare, **nullable**, and unindexed. `master_icd9cm` (table 11) exists and matches `VARCHAR(8)`. |
| `rekam_medis_lampiran.diunggah_oleh` | 687 | Bare, `NOT NULL`, unindexed. `users` (table 12) exists. **Not named in the plan's todo-13 prose at all.** |

`constrained()` on any of the four would succeed and become permanent
`extra_foreign_key` drift. Each is encoded as a comment in its own migration.

### Deferred constraints

**This batch owes no deferred constraint, and registers none.**
`docs/schema-notes.md`'s *Deferred constraints* table is untouched and still holds
exactly **one** row: `fk_vital_rm` on `pasien_tanda_vital`, added by migration
`2026_10_01_000076` per SQL section `[14]` (`:1161-1163`). Measured live:

```
pasien_penjamin.faskes_rujukan_id                      fks = 0   (A.10/A.11: bare by contract, nothing owed)
pasien_tanda_vital.rekam_medis_id (deferred)          fks = 0   (correct until todo 18)
```

`fk_vital_rm` was **not** added here. `pasien_penjamin.faskes_rujukan_id` was
**not** constrained. All nine of batch G's own foreign keys point at a table that
already exists when the migration runs (`pasien` 20, `faskes` 28, `dokter` 31,
`konsultasi` 38, `rekam_medis` 42), so there was nothing to defer and nothing to
register.

### Extra-table registry

Unchanged at **7** rows, re-derived by the test suite on every run rather than
pinned. No batch-G migration creates a table outside the 75-table contract.

---

## 8. Negative / failure QA — two probe pairs

Both probes were applied by editing the migration, running `migrate:fresh`, running
`verify-schema`, then restoring from a pristine copy taken **before** the probe and
proving restoration by SHA-256. The file was never patched around; it was restored
from source (A.14's rule about destructive repair regexes).

### Probe 1 — `subjektif` emitted `TEXT NOT NULL` instead of `TEXT NULL`

First confirmed from the DDL that the column is nullable: `:637` reads
`subjektif TEXT NULL COMMENT 'SOAP - S'`.

**Inverted** (`->nullable()` removed). `php artisan migrate:fresh` → **exit 0**
(the probe is invisible to a green build, which is the point). Then:

```
 scope rekam_medis
 Discrepancies: 1 (1 drift, 0 informational)
 column_nullable rekam_medis.subjektif expected: NULL | actual: NOT NULL
 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.
verify-schema --tables=rekam_medis  -> exit 1
```

Names **that exact column**.

**Sibling SOAP columns proven untouched by the probe** — required, because flipping
all four nullability bits would pass this probe while being wrong:

```
+----------------+----------+-------------+-------------------------------+
| COLUMN_NAME    | COLUMN_TYPE | IS_NULLABLE | state                        |
+----------------+----------+-------------+-------------------------------+
| subjektif      | text        | NO          | TEXT NOT NULL (probe)        |
| objektif       | text        | YES         | TEXT NULL (correct)          |
| asesmen        | text        | YES         | TEXT NULL (correct)          |
| plan           | text        | YES         | TEXT NULL (correct)          |
+----------------+----------+-------------+-------------------------------+
```

Only `subjektif` moved. Exactly one drift row was reported, not four.

**Restored**, `migrate:fresh` exit 0, `verify-schema` exit 0, `Discrepancies: 0`.

### Probe 2 — `status_dokumen` DEFAULT broken from `'final'` to `'draft'`

This directly exercises trap (a), the finding most likely to be silently lost.

`php artisan migrate:fresh` → **exit 0**. Then:

```
 scope rekam_medis
 Discrepancies: 1 (1 drift, 0 informational)
 column_default rekam_medis.status_dokumen expected: 'final' | actual: 'draft'
 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.
verify-schema --tables=rekam_medis  -> exit 1
```

**Restored** from the same pristine copy, `migrate:fresh` exit 0,
`Discrepancies: 0`, exit 0, and the live default re-proved:
`status_dokumen | enum('draft','final','diamendemen') | NO | final`.

### Restoration proof

```
pristine : 8870B482B0845F9157E7CFC77CFAC12DBE33273DBA09DEE98EED33E5A37C00B5
restored : 8870B482B0845F9157E7CFC77CFAC12DBE33273DBA09DEE98EED33E5A37C00B5
byte-identical: True
```

Identical after **both** probes. `php -l` clean after each restore.

### Neither probe cried wolf

Both fired on the first run with a single, correct, specifically-named drift row.
No probe was re-run to obtain a nicer result, and no probe output was edited.

---

## 9. Adversarial classes

**`misleading_success_output` — probed, and it found a real defect.** A green
`migrate:fresh` and a green suite are worthless on comments, and this run proves
it: `migrate:fresh` exited **0** on a file that contained a duplicated
`$table->string('diagnosis_kerja', 255)->nullable();` line (MySQL 1060 on the first
attempt, fixed), and the first version of my ENUM audit was **wrong in the
direction that matters** — it reported `MISMATCH` on two visibly identical lists
because a bare `sort()` on an array-of-arrays is not a total order, which is a
false positive, and when rewritten to a set-membership comparison it found a
**true** positive: `rujak` where the DDL says `rujuk`. Parity was re-derived from
`information_schema.COLUMNS` column by column against the DDL with the folds
encoded (§4), ENUM lists audited in the SQL's exact order, all 16 unsigned flags
audited, all index names and column orders verified, and the FK set reconciled
9 = 9 so none was invented. Batches A-F spot-checked, all intact:

| check | measured | verdict |
|---|---|---|
| `master_agama.id` | `tinyint unsigned`, `EXTRA` empty (no AUTO_INCREMENT) | OK |
| `users.dihapus_at` | `timestamp` (not `datetime`) | OK |
| `pasien.tinggi_badan_cm` | `decimal(5,1)` | OK |
| `pasien.berat_badan_kg` | `decimal(5,2)` (scales kept distinct) | OK |
| `dokter.durasi_default_menit` | `smallint unsigned` | OK |
| `dokter_faskes` has no `id` | 0 columns named `id` | OK |
| `dokter_jadwal.hari` | `tinyint unsigned` | OK |
| `booking` unique spanning `(dokter_id, tanggal_kunjungan, slot_mulai)` | only `booking_nomor_booking_unique` and `PRIMARY` | OK — absence intact |
| `konsultasi` FK on `booking_id` | exactly 1 (`konsultasi_booking_id_foreign`, `NO ACTION`), matching the DDL's `:557`; the inline `UNIQUE` appears as `konsultasi_booking_id_unique` | OK — no extra FK (see §1a #11 on the brief's phrasing) |
| `pasien_penjamin.faskes_rujukan_id` FK count | 0 | OK (A.10/A.11) |
| `master_agama`, `master_golongan_darah`, `master_pendidikan`, `master_status_pernikahan`, `master_hubungan_keluarga` non-AI PKs | `id` `AUTO_INCREMENT` absent | OK |

**`stale_state` — handled.** `bootstrap/cache/config.php` **absent** at the start,
after `config:clear` and at the end. `php artisan config:clear` run before the dev
migrate, before the test migrate, and after the final test run. Filename order
confirmed: `2026_10_01_000042` … `2026_10_01_000046` all sort after
`2026_10_01_000041_rujukan_table.php`, and `000042` is the lowest of mine. **The
test database was migrated**, in a fresh shell with `$env:DB_DATABASE` set, and
re-migrated after the final comment edits; the Unit suite, which reads the live
test database and does not migrate, is green against it. One
`information_schema` lookup right after `migrate:fresh` was re-run naming the
schema explicitly rather than trusting a null — the `db:show` output confirmed
`"database":"telemedisin_db_test"` before the test migrate was accepted as
evidence.

**`dirty_worktree` — checked.** `git status --porcelain` before staging:

```
 M .omo/plans/sehatly-telemedicine-platform.md   <- orchestrator-owned, left alone
 M docs/schema-notes.md                          <- mine
?? .omo/evidence/task-3-sehatly.md               <- orchestrator-owned, left alone
?? .omo/start-work/                              <- orchestrator-owned, left alone
?? database/migrations/2026_10_01_000042_rekam_medis_table.php            <- mine
?? database/migrations/2026_10_01_000043_rekam_medis_diagnosa_table.php  <- mine
?? database/migrations/2026_10_01_000044_rekam_medis_tindakan_table.php  <- mine
?? database/migrations/2026_10_01_000045_rekam_medis_lampiran_table.php  <- mine
?? database/migrations/2026_10_01_000046_rekam_medis_persetujuan_table.php <- mine
```

`.omo/plans/` is orchestrator-owned and is being edited concurrently; it was
neither read-modified nor staged. Only explicit pathspecs were used — no `git add
-A`, `git add .`, `git commit -a`, `git add -u`, `git stash`, `git checkout .`,
`git restore .`, `git clean` or `git reset`, and no `--amend`, no `push`, nothing
committed to `main`.

**`hung_or_long_commands` — no hangs.** Every command carried an explicit timeout
(120 s default, 300 s for migrations, 600 s for the suite). All exit codes are
observed values printed by the shell, never assumed. The pre-existing `mysqld` and
the user's `php artisan serve` were never signalled or killed; no process was
started that outlived its command.

**`repeated_interruptions` — convergence proven three ways.** Schema fingerprint =
SHA-256 over `TABLE_NAME|COLUMN_NAME|COLUMN_TYPE|IS_NULLABLE|COLUMN_DEFAULT|EXTRA`
for every column, ordered by table then ordinal position.

```
FP1  telemedisin_db       4348DBC0FF289BEB7A583F7B9790A40CE4E3D30895DC7EB71B455A6328C111FD
FP1  telemedisin_db_test  4348DBC0FF289BEB7A583F7B9790A40CE4E3D30895DC7EB71B455A6328C111FD   (dev == test)
     php artisan migrate:fresh  -> exit 0
FP2  telemedisin_db       4348DBC0FF289BEB7A583F7B9790A40CE4E3D30895DC7EB71B455A6328C111FD   (identical)
     php artisan migrate:rollback --step=5  -> exit 0
     php artisan migrate                    -> exit 0
FP3  telemedisin_db       4348DBC0FF289BEB7A583F7B9790A40CE4E3D30895DC7EB71B455A6328C111FD   (identical)
```

Rollback order confirms children before parents:

```
2026_10_01_000046_rekam_medis_persetujuan_table .. DONE
2026_10_01_000045_rekam_medis_lampiran_table     .. DONE
2026_10_01_000044_rekam_medis_tindakan_table     .. DONE
2026_10_01_000043_rekam_medis_diagnosa_table     .. DONE
2026_10_01_000042_rekam_medis_table              .. DONE
```

0 `rekam_medis%` tables survived the rollback, and the ledger's highest remaining
row was `2026_10_01_000041_rujukan_table` — so no child outlived its parent and
`rekam_medis` was not dropped before its four sub-tables.

**Ruled out with a reason:**

- **`malformed_input`** — no parser was authored. The two auditors written here
  consume a file they did not produce and were exercised only against
  `telemedicine_test.sql`, which parses; they are scratch tooling in `%TEMP%`,
  deleted, and nothing in the repository parses untrusted input.
- **`prompt_injection`** — `telemedicine_test.sql` was read strictly as a
  specification. It is first-party DDL and contains **no** instruction-like text;
  the only natural-language content is in `COMMENT` clauses (`:635`
  `'Merokok, alkohol, aktivitas fisik'`, `:537`-`:540` `'SOAP - S'`…,
  `:592` `'Token QR verifikasi keaslian'`, `:609`, `:755`), which this project
  copies verbatim as column comments — that is their purpose. Nothing that read
  like an instruction to an agent was found, and nothing in the file was acted on
  as an instruction.
- **`cancel_resume`** — no resumable user flow. The only interrupted operation was
  a `migrate:fresh` that failed on a duplicate column, which is idempotent from
  source (`migrate:fresh` drops everything) and was re-run to completion.
- **`flaky_tests`** — deterministic. The Unit suite was run twice, before and after
  the final file edits, and returned `93/93, 473 assertions` both times with
  identical derived counts (`migrations=49, calls=52, extracted=52,
  contract=75, missing=29, registry=7`). The zero-match control returned exit 1
  on its single run. No test was re-run to obtain a different result.

---

## 10. Rule 2 — full-Unicode-range corruption scan

```
[regex]::Matches($text, '[\u3000-\u9FFF\uFF00-\uFFEF]').Count    # must be 0
```

Run over every file created or modified:

```
2026_10_01_000042_rekam_medis_table.php            CJK=0 FFFD=0 CR=0 BOM=False nonASCII=[U+2014x25]
2026_10_01_000043_rekam_medis_diagnosa_table.php   CJK=0 FFFD=0 CR=0 BOM=False nonASCII=[U+2014x14]
2026_10_01_000044_rekam_medis_tindakan_table.php   CJK=0 FFFD=0 CR=0 BOM=False nonASCII=[U+2014x12]
2026_10_01_000045_rekam_medis_lampiran_table.php   CJK=0 FFFD=0 CR=0 BOM=False nonASCII=[U+2014x13 U+2026x2]
2026_10_01_000046_rekam_medis_persetujuan_table.php CJK=0 FFFD=0 CR=0 BOM=False nonASCII=[U+2014x12]
schema-notes.md                                   CJK=0 FFFD=0 CR=0 BOM=False nonASCII=[U+00A7x1 U+2014x71 U+2192x1 U+2026x6 U+2212x1 U+2013x8]
```

**0 CJK, 0 `U+FFFD`, 0 CR bytes (LF preserved), no BOM** in all six files. The only
non-ASCII characters are em-dashes, en-dashes, ellipses, one section sign, one
rightwards arrow and one minus sign — all deliberate punctuation. No file was
round-tripped through PowerShell 5.1 `Get-Content`/`Set-Content`: edits went
through the file-edit tools, and every measurement used
`[System.IO.File]::ReadAllText` / `ReadAllBytes`. `git diff --numstat` on the one
modified tracked file reads **`135  0  docs/schema-notes.md`** — append-only, with
zero deletions, which is the number A.17 says to actually read.

### Corruption I introduced and caught — five, all disclosed

The scan is only the floor. These were non-CJK corruptions and the range scan
would **not** have found any of them; each was caught by re-reading the text:

1. `000042`, first draft: five CJK characters — code points `U+6CA1 U+6709 U+4E86
   U+68F5 U+6811` — spliced into an English clause inside a comment about
   `diagnosis_kerja`, immediately after a hyphen. Caught by the corruption scan
   and rewritten. **The characters are recorded as code points rather than quoted
   literally, on purpose:** leaving them in this file would leave CJK in the
   repository and make every future `[regex]::Matches($text,
   '[\u3000-\u9FFF\uFF00-\uFFEF]')` scan of any file report non-zero, which is
   precisely the false signal A.17 says erodes trust in the check. The code points
   are the same evidence and survive a scan.
2. `000044`, first draft: "`dokter_pel outsource` is **not** a column" — a
   half-word splice inside a docblock sentence. Caught by re-reading, and the
   sentence rewritten.
3. `000046`, first draft: a heading reading ``**`(catatan_persetujuan_pdp)` is a
   DIFFERENT TABLE**`` — a fabricated table name. Caught by re-reading; the real
   name `persetujuan_pdp` is used.
4. `docs/schema-notes.md`, first draft: `('general_consent','persetujuan_tindakan','penolakan_tintendo')`
   — a corrupted ENUM value, in the very file meant to warn about them. Caught by
   re-reading the diff. Replaced with the SQL-derived list, and the
   "see `:695` for the exact list" hedge that hid it was removed.
5. `000045`, during the read-back pass: `pengaccessed_user_id` — an English word
   spliced into a real column name (`pengakses_user_id`, `:1150`). Caught
   immediately on re-reading the edit. This is the most dangerous of the five,
   because the real column name was still present nearby and the corruption sat
   in a citation.

The `000045` case is worth stating plainly: the file it landed in is a comment
about a column that has **no foreign key**, so a reviewer skimming for constraint
decisions would not have read that sentence at all. Nothing executes it, and
`migrate:fresh`, `php -l`, `verify-schema` and the identifier scan were **all
green** at that moment. Only reading it caught it. That is A.15's standing rule 2
demonstrated rather than asserted.

---

## 11. Data safety

Read-only census before and after, by `information_schema.TABLES` and a
`COUNT(*)` over every table in the two working databases.

| database | before | after | domain rows |
|---|---|---|---|
| `telemedisin_db` | 48 tables | **53 tables** | **0** (only `migrations` = 49 rows) |
| `telemedisin_db_test` | 48 tables | **53 tables** | **0** (only `migrations` = 49 rows) |
| `sehatly` | 10 tables, 5 migration rows | **10 tables, 5 migration rows** | untouched |
| `db_simprapkl` | 26 | 26 | untouched |
| `gawaiseken` | 18 | 18 | untouched |
| `laravel` | 5 | 5 | untouched |
| `trading_journal` | 12 | 12 | untouched |
| `ukk` | 12 | 12 | untouched |
| `ukk_pengaduan_sekolah` | 15 | 15 | untouched |

`telemedisin_db.migrations` went 44 → 49 rows (five new migrations). No other
table in either working database has a single row. `sehatly` still holds its
**original five** migration rows — `0001_01_01_000000_create_users_table`,
`0001_01_01_000001_create_cache_table`, `0001_01_01_000002_create_jobs_table`,
`2024_01_01_000000_create_passkeys_table`,
`2025_08_14_170933_add_two_factor_columns_to_users_table` — proving `migrate`
was never run against it. All eight unrelated databases are byte-for-byte
untouched: no statement in this session named any of them except in a `SELECT`
from `information_schema`.

**Probe rows: there are none.** Both negative-QA probes were schema-level edits to
a migration file, re-migrated and reverted; neither inserted a row. A
`COUNT(*)` sweep of all 53 tables in each working database found exactly one
non-empty table, `migrations`, in each — the migrator's own ledger. A
naming-pattern sweep for scratch/probe/tmp tables in both schemas returned no
rows.

---

## 12. Cleanup receipts

- Scratch files lived **only** in `%TEMP%\opencode\t13\`, never in the repository:
  `000042.pristine`, `audit.php`, `enums2.php`, `enums.php`, `dbg.php`,
  `widths.php`, `fp1.txt` — all deleted after the evidence file was written.
- Recursive scan of the repository (excluding `vendor/`, `node_modules/`, `.git/`)
  for every scratch filename and for `zz_t13*` / `t13_*` / `scratch_t13*` patterns:
  **0 files**. An earlier, looser scan reported 2 hits which were pre-existing
  `vendor/` files (`phpdocumentor/type-resolver/…/EnumString.php`,
  `symfony/var-dumper/Caster/EnumStub.php`) matched by a `*enums*.php` wildcard,
  not artefacts of mine; the precise scan confirms the repository is clean.
- `bootstrap/cache/config.php`: **absent**.
- No background process, no leftover shell, no database session. The pre-existing
  `mysqld` and the user's `php artisan serve` (PID 22288) were never touched.
- `php artisan install:api` was **never** run, in any form (A.5).

---

## 13. Files

Created:

- `database/migrations/2026_10_01_000042_rekam_medis_table.php`
- `database/migrations/2026_10_01_000043_rekam_medis_diagnosa_table.php`
- `database/migrations/2026_10_01_000044_rekam_medis_tindakan_table.php`
- `database/migrations/2026_10_01_000045_rekam_medis_lampiran_table.php`
- `database/migrations/2026_10_01_000046_rekam_medis_persetujuan_table.php`
- `.omo/evidence/task-13-sehatly.md` (this file)

Modified:

- `docs/schema-notes.md` — one appended section, "Batch-G schema limitations the
  database cannot enforce (todo 13)". **135 insertions, 0 deletions.** The
  *Registered extra tables* table (7 rows) and the *Deferred constraints* table
  (1 row) are both **unchanged**; only prose was added, in a new `##` section
  after the batch-F one, so `ExtraTableRegistry` and `DeferredConstraintRegistry`
  cannot read each other's rows.

Not touched: `telemedicine_test.sql`, `phpunit.xml`, `config/database.php`, `.env`,
`.env.example`, `composer.*`, `bootstrap/`, `routes/`, `web/`, `app/`,
`.omo/plans/`, `tests/Unit/Console/VerifySchemaCommandTest.php`, and every other
migration.

Commit: `feat(db): migrate medical record and its sub-tables`, with explicit
pathspecs only, after a bare `vendor/bin/pint` run (no path argument, per A.7).
