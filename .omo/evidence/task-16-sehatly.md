# Todo 16 — Migration batch J (invoicing, payment, refund, promo, BPJS claim)

**Branch** `feat/sehatly-telemedicine` · **SQL tables 61-67** · SQL section `[11]`,
`telemedicine_test.sql:921-1034`
· **PHP** `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17), prepended
to `PATH` as the first statement of every shell invocation
· **MySQL** 8.0.30 · **Date** 2026-09-27

Every command below was run inside `Start-Job` with an explicit `Wait-Job -Timeout`,
and every exit code is read from the job's own output. Per the adversarial class
`hung_or_long_commands`, an empty `.ExitCode` is treated as failure — no
`Start-Process -PassThru` was used at all, because todo 15 hit exactly that .NET
handle-caching quirk.

---

## 0. Read-only law, proved byte-unchanged

```
(Get-FileHash telemedicine_test.sql -Algorithm SHA256).Hash
  = AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5   (BEFORE)
  = AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5   (AFTER)
git diff --exit-code telemedicine_test.sql   ->  exit 0
```

Matches the hash mandated by the brief, unchanged at the end. A.3's warning applies
and is respected: the on-disk file is CRLF, so the canonical check is
`git diff --exit-code`, not a byte-hash of `git cat-file`.

---

## 1. Files created

| # | File | SQL lines | Columns | Named non-PK indexes | FKs |
|---|---|---|---|---|---|
| 61 | `database/migrations/2026_10_01_000061_master_metode_pembayaran_table.php` | `:925-934` | 8 | 0 (+ inline `UNIQUE kode`) | 0 |
| 62 | `database/migrations/2026_10_01_000062_invoice_table.php` | `:936-956` | 15 | `idx_invoice`, `idx_ref` | 1 |
| 63 | `database/migrations/2026_10_01_000063_pembayaran_table.php` | `:958-973` | 11 | `idx_bayar_status` | 2 |
| 64 | `database/migrations/2026_10_01_000064_refund_table.php` | `:975-983` | 6 | 0 | 1 |
| 65 | `database/migrations/2026_10_01_000065_master_promo_table.php` | `:985-998` | 12 | 0 (+ inline `UNIQUE kode`) | 0 |
| 66 | `database/migrations/2026_10_01_000066_promo_redemption_table.php` | `:1000-1010` | 6 | 0 | 3 |
| 67 | `database/migrations/2026_10_01_000067_klaim_bpjs_table.php` | `:1012-1030` | 15 | `idx_klaim_status` | 0 |
| | **TOTAL** | | **73** | **4** | **7** |

**1 file modified:** `docs/schema-notes.md` (new *Batch-J schema limitations the
database cannot enforce (todo 16)* section appended; 347 additions, 1 deletion —
the single deletion is the previous section's last line, re-emitted as the anchor
for the new section because the file previously ended without a newline).
Both registries (*Registered extra tables*, *Deferred constraints*) are untouched:
**7 extra rows, 1 deferred row**, re-counted by parsing the markdown after the edit.

Nothing else was created. **No Model, Resource, Controller, seeder, factory or
route** — todos 19, 44, 45 and 46 own those. **No table outside 61-67.**

---

## 2. Acceptance criteria

| # | Criterion | Command | Exit | Result |
|---|---|---|---|---|
| 1a | `migrate:fresh` on `telemedisin_db` | `php artisan migrate:fresh` | **0** | all 7 batch-J migrations `DONE` |
| 1b | `migrate:fresh` on `telemedisin_db_test` | `$env:DB_DATABASE='telemedisin_db_test'; php artisan migrate:fresh` (fresh shell) | **0** | all 7 `DONE` |
| 2 | scoped verifier | `php artisan sehatly:verify-schema --tables=master_metode_pembayaran,invoice,pembayaran,refund,master_promo,promo_redemption,klaim_bpjs` | **0** | `Discrepancies: 0 (0 drift, 0 informational)` |
| 3 | full verifier | `php artisan sehatly:verify-schema` | **1** | `Discrepancies: 18 (10 drift, 8 informational)` |
| 4 | unit suite | `php artisan test tests/Unit` | **0** | `{"tests":93,"passed":93,"assertions":473}` |
| 5 | zero-match control | `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` | **1** | `{"result":"failed","tests":0,...,"raw":["No tests found."]}` |
| 6 | table count | measured by enumeration, not arithmetic | — | **74** per database |

`tests/Unit/Console/VerifySchemaCommandTest.php` **not edited** — `git status
--porcelain tests/` is empty. Its derived count moved exactly as A.9 projected:

```
[derive] migrations=70 Schema::create calls=73 extracted=73 | CREATE VIEW calls=0 extracted=0
        | contract tables=75 views=2 | derived-missing tables=8 views=2 | registry=7
```

15 → 8 missing, suite 93/93, **no edit to the test file**.

### 2 — the `scope` line, and the A.7/A.8 traps

```
 scope master_metode_pembayaran, invoice, pembayaran, refund, master_promo, promo_redemption, klaim_bpjs
 Discrepancies: 0 (0 drift, 0 informational)
 PASS — 75 tables, 2 views verified. Nothing was written.
```

All **7** real names are echoed. The `PASS — 75 tables, 2 views verified` banner is
**the A.7/A.8 misleading banner**: it is formatted from the *full* reference model
after verifying 7 tables. The `Discrepancies:` line and the exit code are the
truthful outputs; the banner is not evidence of anything here.

### 2 — deliberately misspelled control, run as a demonstration

```
php artisan sehatly:verify-schema --tables=master_metode_pembryar,invoice
  exit 0
  Discrepancies: 1 (0 drift, 1 informational)
  unknown_requested_table master_metode_pembryar expected: - | actual: -
  PASS — 75 tables, 2 views verified. Nothing was written.
```

**A.8's second false-green trap reproduced end-to-end: exit 0 and a green PASS
banner while `master_metode_pembayaran` was never verified.** The only signal is
the `Discrepancies:` count moving 0 → 1. This is why the real run's `scope` line was
read and not its exit code.

### 3 — full unfiltered verifier, and the still-missing set, derived

```
 Discrepancies: 18 (10 drift, 8 informational)
 FAIL — 10 discrepancies. The live schema does not match telemedicine_test.sql. Nothing was written.
```

The 18 is **enumerated**, not summed from a prediction:

- **10 drift**
  - **8 `missing_table`** — `notifikasi`, `ulasan_dokter`, `artikel_kategori`,
    `artikel`, `home_care_pesanan`, `audit_log`, `persetujuan_pdp`,
    `akses_rekam_medis_log`. All eight are todo 17's, none is mine.
  - **2 `missing_view`** — `v_dokter_katalog`, `v_pendapatan_bulanan`. Both todo 18's.
- **8 informational**
  - **1 `deferred_foreign_key`** — `fk_vital_rm` on `pasien_tanda_vital`. Confirmed
    still absent live (§7 check P), so batch J registered nothing.
  - **7 `documented_extra_table`** — `cache`, `cache_locks`, `failed_jobs`,
    `job_batches`, `jobs`, `migrations`, `personal_access_tokens`.

8 + 2 = 10 drift; 1 + 7 = 8 informational; 10 + 8 = **18**. The orchestrator's
prediction of `18 (10 drift, 8 informational)` is **correct**, and it is correct for
the reasons above rather than by arithmetic.

Set-difference cross-check, independent of the verifier:

```
schema=telemedisin_db  contract=75  live=74
EXTRAS (7): cache, cache_locks, failed_jobs, job_batches, jobs, migrations, personal_access_tokens
MISSING (8): notifikasi, ulasan_dokter, artikel_kategori, artikel, home_care_pesanan,
             audit_log, persetujuan_pdp, akses_rekam_medis_log
```

### 6 — table count, derived by enumeration

```
[PASS] M  table count = 67 contract + 7 extras = 74 (enumerated)
       live=74  contractPresent=67  extras=7
       (cache,cache_locks,failed_jobs,job_batches,jobs,migrations,personal_access_tokens)
```

**74 per database**, identical on both. The orchestrator's arithmetic of
`67 contract + 7 registered extras` is **confirmed**, and confirmed by intersecting
two sets rather than by adding two numbers. **74 = 70 migration files + 4?** No —
70 migrations, of which 3 scaffolds create 6 tables and 67 create one each:
`6 + 67 = 73`, plus Laravel's own `migrations` ledger = **74**. Both derivations
agree.

---

## 3. Rule 1b — swallowed-statement audit (REQUIRED, not optional)

Per A.21, `migrate:fresh`, `php -l` and the unit suite catch a missing column **0 %
of the time** between them, because the suite derives its expectations from the
migration *files*. Four independent derivations of the ordered column list were
computed and cross-compared.

| Derivation | What it is |
|---|---|
| **B** | my own `CREATE TABLE` walk of `telemedicine_test.sql` — paren-balanced, quote-aware, body starts *after* the table's opening paren |
| **E** | the project's own `App\Support\Schema\SqlSchemaParser` |
| **C** | `token_get_all()` on the migration, `T_COMMENT`/`T_DOC_COMMENT` discarded, walking `$table` `->` `T_STRING` `(` and requiring the first argument to be a string literal, with an explicit non-column method blocklist |
| **D** | a hand-rolled **string-aware** comment stripper (handles `//`, `#`, `/* */` and single- and double-quoted strings) followed by a regex — a different mechanism from C, not the same code twice |

### Per-file executable column-declaration counts

| File | C (`token_get_all`) | D (strip+regex) | DDL B | DDL E | verdict |
|---|---|---|---|---|---|
| `…000061_master_metode_pembayaran_table.php` | **8** | **8** | **8** | **8** | MATCH |
| `…000062_invoice_table.php` | **15** | **15** | **15** | **15** | MATCH |
| `…000063_pembayaran_table.php` | **11** | **11** | **11** | **11** | MATCH |
| `…000064_refund_table.php` | **6** | **6** | **6** | **6** | MATCH |
| `…000065_master_promo_table.php` | **12** | **12** | **12** | **12** | MATCH |
| `…000066_promo_redemption_table.php` | **6** | **6** | **6** | **6** | MATCH |
| `…000067_klaim_bpjs_table.php` | **15** | **15** | **15** | **15** | MATCH |
| **TOTAL** | **73** | **73** | **73** | **73** | `allMatch=true` |

**`all_match_sql_column_counts_and_order = true`**, and not only the counts: the
four lists are compared with `===` on the **ordered array of names**, so
`B == E`, `C == D` and `B == C` all hold per file. A reordering would fail.

The full per-file output also prints **every** `$table->…` call as C classified it,
so the count is auditable rather than asserted. For `invoice`, for example:

```
unsignedBigInteger(id) -> COLUMN      index((non-string)) -> not-a-column
string(nomor_invoice) -> COLUMN       index((non-string)) -> not-a-column
unsignedBigInteger(pasien_id) -> COLUMN   foreign(pasien_id) -> not-a-column
… 15 COLUMN entries …
```

`foreign()` takes a string and would be miscounted without the blocklist, and
`index([...])` takes an array and is excluded by the first-argument test — the two
guards are independent, which is why two implementations agreeing is worth
something.

---

## 4. Rule 2 — corruption scan and token audit

### 4a — full-Unicode CJK / fullwidth scan (the A.17 recipe)

```
[regex]::Matches($text, '[\u3000-\u9FFF\uFF00-\uFFEF]').Count   # run per file
```

| File | CJK | `U+FFFD` | BOM | CRLF | bare LF | bytes |
|---|---|---|---|---|---|---|
| `…000061_master_metode_pembayaran_table.php` | **0** | 0 | no | 0 | 156 | 8 602 |
| `…000062_invoice_table.php` | **0** | 0 | no | 0 | 312 | 18 633 |
| `…000063_pembayaran_table.php` | **0** | 0 | no | 0 | 262 | 15 303 |
| `…000064_refund_table.php` | **0** | 0 | no | 0 | 181 | 10 015 |
| `…000065_master_promo_table.php` | **0** | 0 | no | 0 | 231 | 13 312 |
| `…000066_promo_redemption_table.php` | **0** | 0 | no | 0 | 233 | 13 880 |
| `…000067_klaim_bpjs_table.php` | **0** | 0 | no | 0 | 360 | 21 889 |
| `docs/schema-notes.md` | **0** | 0 | no | 0 | — | 110 786 |

**TOTAL CJK/fullwidth across all 8 files I touched: 0.** No BOM, no CRLF
introduced, LF preserved — because **no file was round-tripped through PowerShell
5.1 `Get-Content`/`Set-Content`**. All edits used the file-edit tools, and all
non-ASCII inspection used `[System.IO.File]::ReadAllText`.

> **Disclosure.** Early in this todo I did run `Get-Content` on
> `2026_10_01_000048_obat_interaksi_table.php` to survey house style, and its
> output rendered the file's em-dashes as a `?` followed by a replacement glyph.
> That is the A.17 trap firing on
> **my own read**, and it is why I switched to `[System.IO.File]::ReadAllLines` and
> the read tool immediately. No file was modified by that read; the corruption was
> in the terminal output, not on disk. `000048` is untouched by this todo.

`git diff --numstat` after the `docs/schema-notes.md` edit, which is A.17's
prescribed check:

```
347	1	docs/schema-notes.md
```

347 additions against the section I wrote, and the 1 deletion is the re-anchored
last line of the previous section. Read, not assumed.

### 4b — token audit

Every **bare snake_case** token (outside backticks) and every **identifier-shaped
word inside a backticked span** was extracted and checked against a vocabulary
derived from `telemedicine_test.sql` itself — every table name, column name, index
name, ENUM member, string literal and FK target in the file. Backticked spans are
reduced to identifier words first, so a quoted fragment like
`` `VARCHAR(30) NOT NULL UNIQUE` `` is reduced to `VARCHAR` rather than treated as
one unknown identifier.

**863 token occurrences extracted; 376 identifier-shaped tokens checked against the
vocabulary; 32 distinct tokens absent, every one read in place.** All 32:

| Token | Occ. | Disposition |
|---|---|---|
| `order.md` | 16 | fragment of `docs/migration-order.md` |
| `CURRENT_TIMESTAMP` | 8 | SQL function |
| `telemedicine_test.sql`, `telemedicine_test` | 11 | the DDL filename |
| `_10_01_000076` | 4 | fragment of the migration filename `2026_10_01_000076_add_deferred_foreign_keys_table.php` |
| `voided_at` (backticked + bare) | 2 | **deliberate** — `promo_redemption` has no such column, and the comment says so |
| `webhook_event_id` | 1 | **deliberate** — asserted absent from the DDL |
| `missing_index` | 1 | the discrepancy kind negative probe (a) produced |
| `biaya_pengirpikan` | 1 | **deliberate** — the *plan's* misspelling, quoted in the comment that corrects it (§8) |
| `master_metode_pembayaran_kode_unique` | 1 | the name Laravel *would* generate for the inline `UNIQUE`, cited in prose per rule 10 |
| `column_unsigned` | 1 | a discrepancy kind name |
| `DATE_FORMAT` | 2 | SQL function |

**Zero unknown identifiers of my own.** The audit earned its keep: it caught
**`nomor_referencia`** (2 occurrences, a mangling of `nomor_referensi` in my own
TRAP 2 comment) and **`enum_value`** (a discrepancy kind I claimed exists and does
not). Both are in §9.

`docs/schema-notes.md` was scanned with the same tool (vocabulary extended with
every migration filename, every `docs/*.md`, every class and every discrepancy-kind
string literal in `app/Support/Schema/*.php`): **30 distinct unknown tokens, all
read.** Five are in my new section — `webhook_event_id`, `voided_at`,
`missing_index`, `biaya_pengirpikan` (all deliberate, as above); the other 25 are
**pre-existing** content in the file (`v_dokter_katalog`, `created_at`,
`updated_at`, `password_reset_tokens`, `master_lab_tindres`, …). The scan also
caught **`tendency_icd9cm`** in my new prose — a corruption of `tindakan_icd9cm` —
now fixed. Four further non-`snake_case` corruptions in the same edit were caught
by a separate targeted pattern scan (`konsfax`, `kons Presley_chat`, a `` `promo`-free ``
fragment, and a code span broken across a line by `` `pembayaran. `` / `` jumlah` ``);
all four are fixed and the re-scan reports **0**.

**Corruptions I introduced and caught: 11** across the batch (3 in the migrations,
8 in `schema-notes.md`) — listed in full in §9.

---

## 5. Rule 1 — citation verification, mechanically

**162 distinct `:NNN` citation tokens** were extracted from the 7 migrations.
For each, the named SQL line was printed and read against the claim the comment
makes. Method: regex `` `:(\d{2,4})(?:-(\d{2,4}))?` `` per file, deduplicated per
file, then `sqlLines[n-1]` (and `[m-1]` for a range) printed in full. Sample of
the output, formatted `migration-line | citation | the actual SQL line`:

```
000062 m9    :936-956  | CREATE TABLE invoice (  ///  ) ENGINE=InnoDB;
000062 m20   :940      | referensi_tipe ENUM('booking','konsultasi','resep','pesanan_obat','lab_permintaan','home_care') NOT NULL,
000062 m21   :941      | referensi_id BIGINT UNSIGNED NOT NULL COMMENT 'Polimorfik',
000062 m31   :953      | FOREIGN KEY (pasien_id) REFERENCES patients(id),
000062 m51   :955      | INDEX idx_ref (referensi_tipe, referensi_id)
000062 m64   :954      | INDEX idx_invoice (pasien_id, status),
000062 m70   :947      | status ENUM('draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan',
000062 m70   :948      | 'refund_sebagian','refund_penuh') NOT NULL DEFAULT 'menunggu_pembayaran',
000063 m23   :972      | INDEX idx_bayar_status (status, dibayar_at)
000063 m14   :970      | FOREIGN KEY (invoice_id) REFERENCES invoice(id),
000067 m68   :1022     | status ENUM('draft','diajukan','terkirim','disetujui','ditolak','perlu_perbaikan')
000067 m68   :1023     | NOT NULL DEFAULT 'draft',
```

**All 162 verified. Zero wrong citations in the 7 migrations.** Every `:NNN` in
them names the line it claims.

The **plan's** prose citations for this batch were checked the same way and **four
are wrong** — see §8. In every case the *line-index table* inside the plan (lines
126-131) and inside `docs/migration-order.md` rows 61-67 carries the **correct**
line, exactly as A.16 predicts.

---

## 6. ENUM audit — values and order, and the wrapped-ENUM question

All **9** ENUM columns in the batch, in SQL order, cross-checked between my own
paren-balanced walk (**B**) and the project parser (**E**). Every list is compared
with `===`, so both value *and* order must match:

| Table | Column | n | Values, in SQL order | B vs E |
|---|---|---|---|---|
| `master_metode_pembayaran` | `tipe` | **9** | `va_bank,e_wallet,qris,kartu_kredit,gerai_retail,cod,tunai,bpjs,asuransi` | ORDER+EQUALITY MATCH |
| `invoice` | `referensi_tipe` | **6** | `booking,konsultasi,resep,pesanan_obat,lab_permintaan,home_care` | ORDER+EQUALITY MATCH |
| `invoice` | `status` | **7** | `draft,menunggu_pembayaran,lunas,kadaluarsa,dibatalkan,refund_sebagian,refund_penuh` | ORDER+EQUALITY MATCH |
| `pembayaran` | `gateway` | **4** | `midtrans,xendit,doku,flip` | ORDER+EQUALITY MATCH |
| `pembayaran` | `status` | **5** | `pending,berhasil,gagal,kedaluwarsa,refund` | ORDER+EQUALITY MATCH |
| `refund` | `status` | **4** | `diajukan,diproses,berhasil,ditolak` | ORDER+EQUALITY MATCH |
| `master_promo` | `tipe_diskon` | **3** | `persen,nominal,gratis_ongkir` | ORDER+EQUALITY MATCH |
| `klaim_bpjs` | `tipe_layanan` | **2** | `rawat_jalan,rawat_inap` | ORDER+EQUALITY MATCH |
| `klaim_bpjs` | `status` | **6** | `draft,diajukan,terkirim,disetujui,ditolak,perlu_perbaikan` | ORDER+EQUALITY MATCH |

**`invoice.status` is 7 values, not 5.** The brief asked for the true number and
order and for the count to be my own: **7**, in the order above, read from
**`:947` AND `:948`**.

### The wrapped-ENUM count: 5, derived — and the `wrapped decls 11` trap

Derivation (mine, over the whole file): for every line containing `ENUM(`, find
the first `ENUM(`, paren-balance **on that line only**, and record the line if the
paren never closes there.

```
count = 5, at lines 515, 713, 751, 947, 1137
  515  status          ENUM('menunggu_pembayaran','terjadwal','check_in','berlangsung','selesai',
  713  bentuk_sediaan  ENUM('tablet','kaplet','kapsul','sirup','salep','krim','gel',
  751  status          ENUM('aktif','diproses','diverifikasi','difenuhi','dikirim','selesai',
  947  status          ENUM('draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan',
  1137 jenis           ENUM('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis',
```

Resolving each to `table.column` by name search gives exactly A.20's five:
`booking.status (515-516)`, `master_obat.bentuk_sediaan (713-714)`,
`resep.status (751-752)`, **`invoice.status (947-948)`**,
`artikel_kategori.jenis (1137-1138)`. **`invoice.status` is the only wrapped ENUM
batch J owns.**

**A.20's second half, confirmed by reading `:1022` directly:** `klaim_bpjs.status`
opens *and closes* `ENUM(...)` on `:1022`; `:1023` carries only
`NOT NULL DEFAULT 'draft',`. It is **not** wrapped. All four lines an earlier report
added to the wrapped list close their own `ENUM(...)`.

**On the verifier's `wrapped decls 11`.** `sehatly:verify-schema` prints
`wrapped decls 11` on every run. I inspected what that number counts rather than
repeating it: `SchemaSpec::multiLineColumns()` returns declarations whose `endLine`
exceeds `line`, which is true for `konsultasi.status (542-543)` and
`konsultasi_chat.tipe_pesan (568-569)` even though both **close their own
`ENUM(...)` on their own line** (verified by reading `:542` and `:568`). So "the
ENUM value list continues onto the next line" is **5** and "the column declaration
spans two lines" is **11** — two different questions, two correct answers. **I am
not offering 11 as corroboration of 5, because it was never asked that question**,
which is the whole of A.20's standing rule. This is recorded in
`docs/schema-notes.md` too, because it is live inside the project's own tooling
output.

---

## 7. Parity — `information_schema` dump for all 7 tables

Full dumps are in §7a-§7d. Every expected value is derived from
`telemedicine_test.sql`, never hand-typed (A.14), and the signedness flag is
**derived** with `str_ends_with(trim($ty), 'unsigned')` — not `!str_contains($ty,
'signed')`, which A.14 records as a trap because `'tinyint unsigned'` *contains*
`'signed'`.

### 7a — 17/17 regression spot-checks, both databases

```
RESULT: 17 checks, 0 failed        (telemedisin_db)
RESULT: 17 checks, 0 failed        (telemedisin_db_test)
```

| | Check | Measured |
|---|---|---|
| A | `master_agama.id` `tinyint unsigned`, no `AUTO_INCREMENT` | live `tinyint unsigned`, `EXTRA` empty, derived `autoIncrement=false` |
| B | `users.dihapus_at` is `timestamp`, not `datetime` | live `timestamp` |
| C | `pasien.tinggi_badan_cm` `decimal(5,1)` ≠ `berat_badan_kg` `decimal(5,2)` | both exact |
| D | `dokter.durasi_default_menit` `smallint unsigned` | live `smallint unsigned` |
| E | `dokter_faskes` has **no** `id`, composite PK | `id columns=0`, `UNIQUE(dokter_id,faskes_id)` |
| F | `booking` has **no** unique on `(dokter_id,tanggal_kunjungan,slot_mulai)` | absent; the 8 real indexes printed in full |
| G | `rekam_medis` 28 columns, `status_dokumen` default `'final'` | live cols=28, DDL cols=28, default `final` |
| H | `apotek_stok.jumlah_stok` + `.stok_minimum` **signed** `int` | live `int` / `int`, DDL `unsigned=false` / `false` |
| I | `lab_paket_item` composite PK, no `id` | `id columns=0`, `PRIMARY=UNIQUE(paket_id,tindakan_id)` |
| J | `resep.konsultasi_id` / `.rekam_medis_id` 0 FKs (A.11) | 0 / 0 |
| K | `pasien_penjamin.faskes_rujukan_id` FK count still 0 (A.10/A.11) | 0 |
| L | my 3 bare columns exist **and** are FK-free | see §7c |
| M | 74 tables = 67 contract + 7 extras, **enumerated** | 74 / 67 / 7 |
| N | **zero domain rows** | totalRows=70, ledger=70, domainRows=**0** |
| O | 0 views (todo 18 owns both) | 0 |
| P | `fk_vital_rm` still absent — batch J registered nothing | 0 FKs |
| Q | 7 live FKs reconcile 1:1 with the 7 DDL `FOREIGN KEY` clauses | `0/0 1/1 2/1 1/1 0/0 3/3 0/0`, totals 7 and 7 |

### 7b — signedness audit, 35 integer/decimal columns

```
AUDITED 35 integer/decimal columns: 19 unsigned, 16 signed
```

The 19 unsigned: `master_metode_pembayaran.id` (`smallint unsigned`);
`master_promo.kuota_total` (`int unsigned`), `.kuota_per_user` (`tinyint unsigned`);
and the 16 `bigint unsigned` id/FK columns across the batch. The 16 signed are
**14 `DECIMAL`s** (money and quota amounts, signed in the DDL, correctly emitted by
Laravel's `decimal()`) **plus the two `tinyint(1)` booleans** — which the audit
prints with the note `(tinyint(1) - boolean(), NOT an integer width)`, because
`tinyint(1)` is a display width MySQL preserves for booleans while it strips it
from every other integer type. **No display width was hand-typed anywhere.**

### 7c — FK reconciliation, and the three bare columns proven FK-free

Proven with `information_schema.REFERENTIAL_CONSTRAINTS` **joined to**
`KEY_COLUMN_USAGE` — **not** by reading `SHOW CREATE TABLE`, which only shows the
constraints that exist and so cannot distinguish "absent" from "not looked for".
Each bare column was **separately confirmed to exist**, so "no row returned" cannot
be confused with "no column":

```
invoice.referensi_id    columnExists=YES  type=bigint unsigned  nullable=NO   fkConstraints=0
klaim_bpjs.booking_id    columnExists=YES  type=bigint unsigned  nullable=YES  fkConstraints=0
klaim_bpjs.rekam_medis_id columnExists=YES type=bigint unsigned  nullable=YES  fkConstraints=0
```

And the 7 live foreign keys, every one with `NO ACTION` on both events — no
cascade, no explicit restrict, anywhere in batch J:

```
invoice           invoice_pasien_id_foreign          -> pasien                   (pasien_id -> id)         NO ACTION / NO ACTION
pembayaran        pembayaran_invoice_id_foreign      -> invoice                  (invoice_id -> id)        NO ACTION / NO ACTION
pembayaran        pembayaran_metode_id_foreign       -> master_metode_pembayaran (metode_id -> id)         NO ACTION / NO ACTION
promo_redemption  promo_redemption_promo_id_foreign  -> master_promo             (promo_id -> id)          NO ACTION / NO ACTION
promo_redemption  promo_redemption_pasien_id_foreign -> pasien                   (pasien_id -> id)         NO ACTION / NO ACTION
promo_redemption  promo_redemption_invoice_id_foreign-> invoice                  (invoice_id -> id)        NO ACTION / NO ACTION
refund            refund_pembayaran_id_foreign       -> pembayaran               (pembayaran_id -> id)     NO ACTION / NO ACTION
TOTAL FK constraints on the 7 batch-J tables = 7
```

Measured by scanning `telemedicine_test.sql:921-1034` (all of section `[11]`):
**zero `ON DELETE` and zero `ON UPDATE` inside any `FOREIGN KEY` clause.** The only
two `ON UPDATE` occurrences in the range are the `diubah_at` column definitions at
`:952` and `:1028`. **Batch J contains no cascade anywhere.**

**`invented_fks_found: []` and `invented_indexes_found: []`** — the 7 live FKs are
exactly the 7 DDL clauses, and every live index is either a DDL-declared index, an
inline `UNIQUE`, the primary key, or an InnoDB implicit FK-support index treated as
implied by commit `27c6ca8`.

### 7d — indexes verified by name **and** order

```
invoice           idx_ref           seq=1 referensi_tipe   seq=2 referensi_id    <- order is the contract
invoice           idx_invoice       seq=1 pasien_id        seq=2 status
pembayaran        idx_bayar_status  seq=1 status           seq=2 dibayar_at
klaim_bpjs        idx_klaim_status  seq=1 status                                <- single column
invoice           PRIMARY           seq=1 id
invoice           invoice_nomor_invoice_unique            UNIQUE (nomor_invoice)
klaim_bpjs        klaim_bpjs_nomor_sep_unique             UNIQUE (nomor_sep)
master_metode_pembayaran  master_metode_pembayaran_kode_unique  UNIQUE (kode)
master_promo      master_promo_kode_unique                 UNIQUE (kode)
pembayaran        pembayaran_invoice_id_foreign    (invoice_id)     <- InnoDB implicit
pembayaran        pembayaran_metode_id_foreign     (metode_id)      <- InnoDB implicit
promo_redemption  promo_redemption_promo_id_foreign / _pasien_id_ / _invoice_id_   <- 3 implicit
refund            refund_pembayaran_id_foreign     (pembayaran_id)  <- InnoDB implicit
```

`invoice` has **no** implicit support index for `pasien_id`, because
`idx_invoice (pasien_id, status)` already covers it — which is why check A.10's
caveat in `docs/migration-order.md` warns against reading a reported
`extra_index` as a migration bug.

**Absent by contract, proven by enumeration** (not by reading `SHOW CREATE TABLE`):

```
telemedisin_db        pembayaran.nomor_referensi  indexRowsOnThisColumn=0  totalIndexRowsOnTable=5  => CONFIRMED ABSENT
telemedisin_db_test   pembayaran.nomor_referencia indexRowsOnThisColumn=0  totalIndexRowsOnTable=5  => CONFIRMED ABSENT
```

The same query on `invoice.referensi_id` returns
`indexRowsOnThisColumn=1  FOUND idx_ref seq=2` — i.e. it is the **second** column of
`idx_ref`, which is why TRAP 1b exists and why the order matters.

---

## 8. Plan / brief errors found, all verified against the SQL

| # | What was claimed | What `telemedicine_test.sql` says | Line |
|---|---|---|---|
| 1 | plan todo-16 prose: `biaya_pengirpikan` | **`biaya_pengiriman DECIMAL(14,2) NOT NULL DEFAULT 0`** — a transposed identifier in a list an executor copies from | **945** |
| 2 | plan todo-16 prose: both `invoice` indexes "at `:953-954`" | `INDEX idx_invoice` is at **954**, `INDEX idx_ref` at **955**; **`:953` is the `FOREIGN KEY (pasien_id)` clause** | 953-955 |
| 3 | plan "Webhook idempotency has no dedupe key" bullet: `idx_bayar_status (status, dibayar_at)` at `:970` | at **`:972`**; **`:970` is `FOREIGN KEY (invoice_id) REFERENCES invoice(id)`** | 970 / 972 |
| 4 | plan todo-15 prose (line 374): `invoice.referensi_tipe` at `:941` | at **`:940`**; `:941` is `referensi_id BIGINT UNSIGNED NOT NULL COMMENT 'Polimorfik'` | 940 / 941 |
| 5 | `docs/migration-order.md` rule 6: the multi-line-ENUM list | names **six**, two of which are **not** wrapped (`konsultasi.status` `:542`, `konsultasi_chat.tipe_pesan` `:568` each close their own `ENUM(...)`), and **omits `invoice.status (947-948)`**, which is. The wrapped list is **5** | 542, 547, 568, 947 |
| 6 | the dispatch brief: "plan cites the pair with `idx_invoice` at `:953-954`" | same as #2 — the brief inherited the plan's off-by-one | 954-955 |
| 7 | the dispatch brief: "the SQL defines only `idx_bayar_status …` (plan cites `:970`)" | same as #3 — `:972` | 972 |
| 8 | plan todo 16's `Commit:` line: `feat(db): migrate invoice, payment, refund, promo and BPJS claim tables` | the brief mandates `feat(db): migrate invoicing, payment, refund, promo and BPJS tables`. **The brief wins on the commit message** (it is the instruction); recorded because the plan and the brief disagree | — |

Findings 1-4 are the **eighth consecutive batch** to find a wrong inline `:NNN` in
this plan's prose, and in every case the *line-index table* is right and the prose
is wrong — A.16 and A.19 again. None is fixed here: `.omo/plans/` is
orchestrator-owned and under active edit, and `docs/migration-order.md` rule 6 is
outside this todo's commit scope. **Reported, not fixed** (§11).

---

## 9. Corruptions and false claims I introduced, and corrected

Recorded in full because seven executors in a row have earned their keep by
disclosing rather than concealing.

**Corruptions of my own, 11 total, all caught before commit:**

| # | What | Where | Fix |
|---|---|---|---|
| 1 | `Neighborhood...` — a non-English fragment spliced into a comment | `000061` `tipe` comment | rewritten |
| 2 | `nomor_reperti` — mangled `nomor_referensi` | `000063` TRAP 2 heading | rewritten |
| 3 | `nomor_referencia` (x2) — mangled `nomor_referensi` | `000063` class docblock + inline | found by the **token audit** |
| 4 | `tendency_icd9cm` — mangled `tindakan_icd9cm` | `000067` class docblock | rewritten |
| 5 | `penyedis` — mangled `penyedia` | `000067` class docblock | rewritten |
| 6 | a code span broken across a line by `` `pembayaran. `` / `` jumlah` `` | `schema-notes.md` | rewritten |
| 7 | `resep.konsfax` + a `… precisely:` fragment | `schema-notes.md` | rewritten |
| 8 | `tendency_icd9cm` | `schema-notes.md` | found by the **token audit** |
| 9 | `` `promo`-free `` — a nonsense qualifier | `schema-notes.md` | rewritten |
| 10 | `kons Presley_chat.tipe_pesan` — a space inside an identifier | `schema-notes.md` | rewritten |
| 11 | a `**not**` line at column 0 breaking a docblock | `000067` | re-indented |

**False claims I made and corrected — the A.15 comment-accuracy class:**

1. **`enum_value` is not a discrepancy kind.** `000063` claimed that harmonising
   `kadaluwarsa`/`kadaluarsa` "is `enum_value` drift". Read
   `app/Support/Schema/SchemaDiffer.php:166-170`: `diffColumn()` compares
   `$want->type` against `$have->type`, and an ENUM's canonical type **is** the
   whole ordered value list, so a changed member or order is reported as
   **`column_type`**. Corrected in place, with the mechanism named.
2. **A self-contradictory sentence about the two expiry spellings.** `000063` said
   `kedaluwarsa` was "the same spelling as `invoice.status`'s fourth value at
   `:947`" and then said `invoice.status` spells it `kadaluarsa` — in the same
   sentence. Measured over the whole file: `kedaluwarsa` appears as an ENUM member
   **3** times (`rujukan.status` `:610`, `resep.status` `:752`, `pembayaran.status`
   `:966`), `kadaluarsa` **2** (`booking.status` `:516`, `invoice.status` `:947`);
   the `d` form is also the only one used for expiry *column* names (`:184`, `:208`,
   `:836`). Rewritten with the measurement in it.
3. **Three wrong superlatives, found by enumeration and removed:**
   `000063` called itself "the widest read in batch J after `invoice`" — with 11
   columns it is **fourth** of seven (`invoice` 15, `klaim_bpjs` 15, `master_promo`
   12, then 11, then `master_metode_pembayaran` 8, `refund` and `promo_redemption`
   6). The full ordering is now printed in the docblock. `000064` called itself
   "the smallest table in batch J" — it is **tied** with `promo_redemption` at 6.
   `000067` said it was "the only table in batch J" with no InnoDB implicit support
   index — so are `master_metode_pembayaran` (61) and `master_promo` (65), and all
   three are now named.
4. **A wrong `:NNN` on a real claim.** `v_pendapatan_bulanan`'s
   `DATE_FORMAT(p.dibayar_at, '%Y-%m')` is at **`:1191`**, not inside the
   `:1190-:1196` span I first cited. Both `000062` and `000063` now cite `:1191`
   explicitly alongside the view's own range.

### Auditors of mine that cried wolf, diagnosed in place (A.18)

Five, all fixed as **auditor** bugs, none reported as a schema finding:

1. **My DDL paren-walk returned 1 column named `CREATE` for every table.** The body
   accumulator started at the `CREATE TABLE` line, so the table's own opening paren
   put every subsequent comma at depth ≥ 1 and the top-level split never ran. Fixed
   by starting the body after that paren.
2. **My ENUM extractor returned every value empty, then n−1 values.** Two bugs: a
   backtracking regex that captured across newlines, and a paren counter initialised
   to 0 when the `ENUM(`'s own paren had already been consumed, so the closing paren
   took the counter to −1 and the `break` never fired — which then swept the
   `DEFAULT '…'` literal into the value list. Fixed; all 9 lists now match.
3. **Three regression checks reported FAIL that were auditor bugs.** `SqlSchemaParser`
   stores `type` and `unsigned` as **separate** fields, so `$live === $e->type`
   compared `tinyint unsigned` against `tinyint`; and the parser keeps
   `COLUMN_DEFAULT` quoted while `information_schema` reports it unquoted, so
   `'final'` was compared against `''final''`. Fixed by **deriving** the expected
   display type as `type . (unsigned ? ' unsigned' : '')` and trimming quotes — which
   is Rule 3 applied to the auditor instead of the schema.
4. **My comment-accuracy auditor produced four successive false positives**, and I
   stopped rather than tuned it green. The residual was a "comment mentions a
   DEFAULT but live has none" check, which is **unsound in three ways at once**: a
   comment correctly *asserting* the absence of a default has to mention the word; a
   comment quoting a neighbouring table's DDL mentions it; and a backticked
   discrepancy name such as `column_default` contains the substring. **I deleted the
   unsound direction rather than tuning it**, because shaping an instrument until it
   agrees with its author is A.18's failure mode wearing a fix's clothes. The
   remaining **forward** direction — comment asserts X, live says not-X — is the
   sound one and the only one that can catch an A.15-class defect, because that is
   exactly the shape such a defect has. Two of the four flags were real
   ambiguities in my prose and I rephrased both (`invoice.status`'s hypothetical
   mis-read, and `master_promo.kuota_total`'s rejected `integer()` alternative).

**Comment-accuracy cross-check, final state:**

```
COMMENT-ACCURACY CROSS-CHECK: 80 column declarations paired with their comment
  NO CONTRADICTIONS FOUND          exit 0
```

80 declarations — 73 columns plus 7 `foreign()` calls — each paired with the DDL
type phrase and `:NNN` in the comment block immediately above it and with what
`information_schema` says, cross-checked on: base type, width, signedness,
nullability, default presence, `AUTO_INCREMENT`, and helper-to-type parity
(`char()`→`char`, `json()`→`json`, `date()`→`date`, `dateTime()`→`datetime`,
`timestamp()`→`timestamp`, `boolean()`→`tinyint(1)`, each `unsigned*()`→its width,
and `string()` never producing a `char`). The full paired dump is in
`t16-comments.out.txt`.

---

## 10. The four trap comments, quoted verbatim

### TRAP 1 — `invoice` is a polymorphic hub and `referensi_id` is BARE

From `2026_10_01_000062_invoice_table.php`, the class docblock:

> ## TRAP 1 — `referensi_id` IS **BARE**, AND NO FOREIGN KEY IS POSSIBLE
>
> `referensi_tipe ENUM('booking','konsultasi','resep','pesanan_obat',
> 'lab_permintaan','home_care') NOT NULL` (`:940`) and `referensi_id BIGINT UNSIGNED
> NOT NULL COMMENT 'Polimorfik'` (`:941`) are a **discriminated union**: the
> discriminator says which of six tables `referensi_id` points at.
>
> **Do NOT write `->foreign('referensi_id')->references('id')->on(...)`.** Four
> reasons, all pointing the same way:
>
>  1. **It is not expressible.** A foreign key names exactly one parent table, and
>     this column's parent is a function of `referensi_tipe`. There is no DDL that
>     constrains a value against a table chosen at insert time.
>  2. **The DDL declares no `FOREIGN KEY` clause for it.** The only clause in this
>     `CREATE TABLE` is `:953`, on `pasien_id`. The plan's own generated
>     reference-shaped-but-no-FK list names `invoice.referensi_id` explicitly.
>  3. **It would be a parity break, not just a design error.** Every target exists
>     by the time this migration runs — `booking` 37, `konsultasi` 38, `resep` 49,
>     `pesanan_obat` 52 are all earlier batches and `lab_permintaan` 58 /
>     `home_care_pesanan` 72 are later — so a constraint would *succeed* and become
>     permanent `extra_foreign_key` drift while `migrate:fresh`, `php -l` and the
>     entire 93-test unit suite stayed green.
>  4. **Ownership is a service-layer concern and the schema says so.** The `Polimorfik`
>     `COMMENT` at `:941` is the DDL's own acknowledgement that the pairing is not
>     a join. A service that writes an invoice must derive `referensi_tipe` from the
>     record it is billing and must verify the `referensi_id` exists *in that table*
>     before inserting; nothing in the database will do either.
>
> **The width is nevertheless correct for all six targets, and that is not
> coincidence.** All six parent tables declare `id BIGINT UNSIGNED PRIMARY KEY
> AUTO_INCREMENT` — `booking` (`:499`), `konsultasi` (`:537`), `resep` (`:743`),
> `pesanan_obat` (`:798`), `lab_permintaan` (`:877`) and `home_care_pesanan`
> (`:1094`) — which is what lets one column hold any of them.

And the inline comment at the column, which is what a reader lands on:

> // ## TRAP 1 - `referensi_id BIGINT UNSIGNED NOT NULL` (:941) IS **BARE**,
> // AND NO FOREIGN KEY IS POSSIBLE.
> //
> // The DDL's own COMMENT on this line is `Polimorfik`. This column is a
> // discriminated union with `referensi_tipe` (:940): the parent table is
> // chosen at insert time from six candidates, and a foreign key can name
> // only one parent. DO NOT write
> // `->foreign('referensi_id')->references('id')->on('...')` - it is not
> // expressible, the DDL declares no FOREIGN KEY clause for it, and every
> // one of the six targets already exists by the time this migration runs,
> // so a constraint would SUCCEED and become permanent
> // `extra_foreign_key` drift while `migrate:fresh`, `php -l` and the whole
> // 93-test unit suite all stayed green.
> //
> // Ownership is a SERVICE-LAYER responsibility: the writer must derive
> // `referensi_tipe` from the record being billed and must confirm the
> // `referensi_id` exists in THAT table. Nothing in the database checks
> // either, and a mismatched pair is a dangling reference that every
> // downstream read will treat as a real row.
> //
> // Proven FK-free against information_schema.REFERENTIAL_CONSTRAINTS
> // joined to KEY_COLUMN_USAGE - not by reading SHOW CREATE TABLE, which
> // only shows constraints that exist and cannot distinguish "absent"
> // from "not looked for". The column was separately confirmed to EXIST as
> // `bigint unsigned` NOT NULL, so "no row" cannot be read as "no column".

**TRAP 1b** — `INDEX idx_ref (referensi_tipe, referensi_id)` (`:955`), both the
class docblock and the inline comment, records that the **column order is part of
the contract** (`referensi_tipe` first) and that "the negative QA in this todo's
evidence file measured what happens when it is removed: `migrate:fresh` exits 0,
the 93-test unit suite stays 93/93, and **only** `verify-schema` fails".

### TRAP 2 — `pembayaran.nomor_referensi` has no index, and the trade-off

From `2026_10_01_000063_pembayaran_table.php`, the class docblock:

> ## TRAP 2 — `nomor_referensi` HAS **NO INDEX AND NO UNIQUE**, and that is the
> ## contract. The webhook-idempotency check is a full scan.
>
> `nomor_referensi VARCHAR(100) NULL COMMENT 'Transaction ID payment gateway'`
> (`:963`). It is the column a payment-gateway callback is matched on, and the DDL
> gives it **no `UNIQUE` and no index of any kind**. The statement ends at `:973`;
> the table's only non-primary index is `INDEX idx_bayar_status (status, dibayar_at)`
> (`:972`), which cannot help because a webhook arrives knowing the transaction id
> and nothing about `status` or `dibayar_at`.
>
> **Do NOT add an index on `nomor_referensi`.** `telemedicine_test.sql` is
> read-only law, an added index is `extra_index` drift, and
> `docs/migration-order.md` rule 7 says so explicitly. There is no
> `webhook_event_id` column to index instead — the DDL has none.
>
> **The performance trade-off, stated so it is not rediscovered as a bug.**
>
>  - **Correctness** is unaffected. The idempotency check is a plain
>    `where nomor_referensi = ?` existence query — written together with the
>    `gateway` predicate — executed *inside* the update transaction. Nothing about a
>    missing index changes the answer, only how long it takes to get it.
>  - **Cost.** Every webhook callback is a full table scan of `pembayaran`. The
>    table is expected to hold one row per payment attempt, so the scan grows
>    linearly with lifetime order volume. At low volume this is invisible; it is
>    also a lock-acquisition cost, because a full scan under InnoDB's default
>    `REPEATABLE READ` takes next-key locks that widen the webhook's write
>    contention. This is a real operational cost and the DDL chose it.
>  - **Uniqueness is NOT guaranteed by the database.** A duplicate `nomor_referensi`
>    is representable, and two concurrent callbacks for the same transaction can
>    both pass the existence check before either commits — the check-then-act race
>    is accepted, exactly as the plan's own schema-reality list records. Todo 45's
>    webhook handler must therefore be **idempotent in its side effects** (setting
>    `status` and `dibayar_at` twice is harmless; crediting a wallet twice is not)
>    rather than relying on the check to make the operation unique.
>  - **NULL is the majority case for non-gateway methods.** `COD` and `tunai` are
>    settled inside the platform and have no gateway transaction id, so a
>    `nomor_referensi IS NULL` row is normal. A lookup must therefore never assume
>    the column is populated, and must not treat a NULL match as an idempotent
>    replay of some other NULL row.

### TRAP 3 — `promo_redemption.invoice_id NOT NULL` resolves the spec ambiguity

From `2026_10_01_000066_promo_redemption_table.php`, the class docblock:

> ## TRAP 3 — `invoice_id` is `NOT NULL`, and that RESOLVES the spec's ambiguity
>
> `invoice_id BIGINT UNSIGNED NOT NULL` (`:1004`). **Decision, recorded here so todo
> 44 and todo 45 do not re-derive it differently:**
>
> **A `promo_redemption` row is written when a promo is APPLIED TO AN INVOICE — it
> is NOT written by a pure `POST /promo/validasi` check.**
>
> The reasoning is forced by the DDL, not chosen. A validation that has not yet
> touched an invoice has nothing to point at: the column is `NOT NULL`, there is no
> `DEFAULT`, and no `CHECK` relaxes it. Emitting a redemption row at validation time
> would require either a nullable column (which the DDL forbids — and which would
> then be `column_nullable` drift), a placeholder invoice, or a second table for
> un-committed validations. None of those exists in the contract.
>
> Therefore:
>
>  - **`POST /promo/validasi` must be a pure read.** It evaluates `master_promo`'s
>    window, `status_aktif`, `min_transaksi`, `nilai`/`maks_diskon` and the
>    caller's prior usage, and **writes nothing**. A validation that "uses up" a
>    quota would consume a redemption for an invoice that may never be created.
>  - **The redemption row is written when the invoice is created with the discount
>    applied**, inside the same transaction as the invoice and the payment attempt.
>    That is the moment `referensi_id` exists and the moment the quota is genuinely
>    consumed.
>  - **`nilai_diskon` (`:1005`) is the discount AS APPLIED**, i.e. after
>    `maks_diskon` capping and after `gratis_ongkir` resolves to a shipping amount.
>    It is a snapshot: editing `master_promo.nilai` afterwards must not retroactively
>    change what a past invoice was discounted by, and nothing keeps them in step.
>  - **Two failed paths follow, and both are real.** An invoice created with a promo
>    and then abandoned consumes a redemption with no way to release it, and a
>    re-applied promo on a *new* invoice for the same cart consumes a second one.
>    There is no `status`, no `voided_at` and no unique key on this table to make
>    either recoverable, so the quota is consumed permanently. If that matters to
>    the business, the release has to be a service-level compensating delete.
>
> **Do NOT make `invoice_id` nullable to accommodate the validation call.** That is
> `column_nullable` drift, it is exactly the mistake this comment exists to prevent,
> and it would make the plan's own failure-QA assertion — that the verifier flags
> `promo_redemption.invoice_id` if emitted as nullable — pass for the wrong reason.

### TRAP 4 — `klaim_bpjs` gets a migration only

From `2026_10_01_000067_klaim_bpjs_table.php`, the class docblock:

> ## TRAP 4 — THIS TABLE GETS A MIGRATION ONLY. The spec allows V-Claim to be
> ## stubbed, and no service, client or endpoint may be built against it.
>
> `docs/migration-order.md` row 67 records `Module: ORPHAN`, `Resource: —`,
> `Controller: —`. That is the plan guardrail, not an omission: the BPJS V-Claim
> integration is an external government system reached over a network API, and the
> spec's Q7=A answer lets it be stubbed. **Todos 19, 44, 45 and 46 own everything
> else; this todo authors the table and nothing else.** No Model, no Resource, no
> Controller, no seeder, no route, no HTTP client.
>
> The table must still exist in full, and for two concrete reasons beyond
> referential tidiness: `master_metode_pembayaran.tipe` (`:929`) carries a `'bpjs'`
> member and the seed at `:1278` inserts a `BPJS` method with
> `penyedia` = `'BPJS Kesehatan'`, so a payment *can* be routed to BPJS even though
> the claim submission is stubbed; and the `nomor_sep`/`nomor_kartu` pair is the
> eligibility data a stubbed claim row would carry.

and, in the same file, the two FK-less columns the plan's todo-16 prose never
mentions:

> ## TWO FK-LESS COLUMNS, BARE BY CONTRACT — `booking_id` and `rekam_medis_id`
>
> `booking_id BIGINT UNSIGNED NULL` (`:1014`) and `rekam_medis_id BIGINT UNSIGNED
> NULL` (`:1015`). This `CREATE TABLE` declares **no `FOREIGN KEY` clause at all**,
> and both columns are named in the plan's generated reference-shaped-but-no-FK
> list.
>
> **Do NOT write a foreign key on either.** Both targets exist well before this
> migration runs — `booking` is table 37 (batch E) and `rekam_medis` is table 42
> (batch G) — so a constraint would **succeed** at migration time and become
> permanent `extra_foreign_key` drift while `migrate:fresh`, `php -l` and the entire
> 93-test unit suite stayed green.
>
> **Both are absent from the plan's own todo-16 prose**, which names only
> `booking_id`-shaped traps indirectly and never mentions either column. That
> omission is exactly how an invented constraint gets written, and this defect class
> has already occurred three times in this project (`resep.konsultasi_id` in batch H,
> `pasien_penjamin.faskes_rujukan_id` before that, `lab_hasil.diperiksa_oleh` in
> batch I). Hence the explicit warning.

`nomor_kartu` is `char('nomor_kartu', 13)`, with the reason spelled out: storing 13
characters is identical under `CHAR(13)` and `VARCHAR(13)`, but `CHAR` is
blank-padded on retrieval and strips trailing spaces, so a hand-typed 10-digit
number silently becomes 13 characters and any comparison against a 13-digit value
behaves differently **without erroring**. `varchar(13)` would be a parity break.

---

## 11. Negative QA — both probes, restored byte-identically

### (a) A missing index — the most valuable probe in the batch

`$table->index(['referensi_tipe', 'referensi_id'], 'idx_ref');` removed from
`2026_10_01_000062_invoice_table.php`.

| check | result on a schema missing `idx_ref` |
|---|---|
| `php -l` | **exit 0** — it is a valid PHP file |
| `php artisan migrate:fresh` | **exit 0** — the DDL is valid; it just omits an index |
| `php artisan test tests/Unit` | **exit 0 — 93/93, 473 assertions** |
| **`sehatly:verify-schema --tables=invoice`** | **exit 1** |

```
 Discrepancies: 1 (1 drift, 0 informational)
 missing_index invoice expected: idx_ref INDEX (referensi_tipe, referensi_id) | actual: -
 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.
```

The live index count also moved **211 → 210**, visible in the run's own
`counts … indexes=210` line.

**Answer to "report whether anything catches it": nothing but `verify-schema`
catches it.** Not `migrate:fresh`, not `php -l`, not the entire 93-test unit suite.
An index removal only degrades query performance, never correctness, so no
functional test in this project — present or future — can fail on it. This is
A.21's measurement extended from a missing *column* to a missing *index*, and it is
the same result: for schema truth, `verify-schema` **is** the check.

### (b) A wrapped-ENUM mis-read

**b1 — truncate `invoice.status` to the 5 values on `:947`, keep `NOT NULL` and the
default:**

| check | result |
|---|---|
| `migrate:fresh` | **exit 0** — and note MySQL 8.0.30 accepted it, because `menunggu_pembayaran` *is* one of the surviving five |
| `php artisan test tests/Unit` | **exit 0 — 93/93, 473 assertions** |
| `verify-schema --tables=invoice` | **exit 1** |

```
 Discrepancies: 1 (1 drift, 0 informational)
 column_type invoice.status
   expected: enum('draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan','refund_sebagian','refund_penuh')
   actual:   enum('draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan')
```

**b2 — the complete `:947`-alone mis-read: 5 values, `->nullable()`, no default:**

```
 Discrepancies: 2 (2 drift, 0 informational)
 column_nullable invoice.status expected: NOT NULL | actual: NULL
 column_type     invoice.status expected: enum(…7 values…) | actual: enum(…5 values…)
```

Both halves of A.20's claim are flagged, and the verifier prints the **full ordered
value list on both sides**, which proves it compares ENUM lists as complete ordered
sequences rather than as sets.

> **One honest nuance, discovered by the probe.** `column_default` is **not**
> reported in b2, because `SchemaDiffer::diffColumn()` returns early once nullability
> differs — its own comment in `app/Support/Schema/SchemaDiffer.php` (lines 179-181)
> says "Nullability is the root cause here; comparing the default on top of it would
> report one change twice and bury the real one." So the missing default is *masked*
> by the nullability report rather than independently reported. That is a deliberate
> design choice in the differ, and it does not weaken the probe: the schema is still
> rejected with exit 1.

### Restoration, with proof

```
PRISTINE  SHA256 = CFEC6DDF23684243EABC29F76B71310FD70BDF2DC7200B1CF98CBE2D032937A0
RESTORED  SHA256 = CFEC6DDF23684243EABC29F76B71310FD70BDF2DC7200B1CF98CBE2D032937A0
MATCH - byte-identical restore after probes (a), (b1), (b2)
first 3 bytes = 60,63,112  ("<?p")  -> no BOM
CRLF count = 0
git diff --numstat  ->  0 changes to the file beyond its intended content
```

The restored file was re-audited after restoration: the Rule 1b four-way
comparison still reports `TOTALS: DDL B=73 E=73 | MIG C=73 D=73 | allMatch=true`,
and `php -l` exits 0. **No probe artifact survived.**

---

## 12. Convergence and the rollback cycle

### Convergence — schema fingerprint across independent rebuilds

The fingerprint is a SHA-256 over every `information_schema.COLUMNS` row
(name, type, nullability, default, extra, ordinal), every `STATISTICS` row
(name, uniqueness, seq, column), every `REFERENTIAL_CONSTRAINTS` row, and every
view definition, in a fixed order.

| rebuild | `telemedisin_db` | `telemedisin_db_test` |
|---|---|---|
| 2nd `migrate:fresh` | `b9850c7248d89bcf64d38fdf821f9af47e8dcda2b3a2064cd033e733be269eff` | `b9850c7248d89bcf64d38fdf821f9af47e8dcda2b3a2064cd033e733be269eff` |
| after `rollback --step=7` + `migrate` | `b9850c7248d89bcf64d38fdf821f9af47e8dcda2b3a2064cd033e733be269eff` | — |

**972 structure lines each; three independent rebuilds, one hash.** Both databases
are byte-identical in structure.

### Rollback — children before parents

```
php artisan migrate:rollback --step=7        -> exit 0
 2026_10_01_000067_klaim_bpjs_table              13.50ms DONE
 2026_10_01_000066_promo_redemption_table        14.78ms DONE
 2026_10_01_000065_master_promo_table             6.64ms DONE
 2026_10_01_000064_refund_table                   7.99ms DONE
 2026_10_01_000063_pembayaran_table              14.31ms DONE
 2026_10_01_000062_invoice_table                  8.52ms DONE
 2026_10_01_000061_master_metode_pembayaran_table  5.61ms DONE
```

Exact reverse-dependency order: `klaim_bpjs` before `promo_redemption` (which
FKs it), before `master_promo`, before `refund`, before `pembayaran`, before
`invoice`, before `master_metode_pembayaran`. No MySQL 1824, because no child is
dropped after its parent. The rollback returned the database to **67** live tables
and `migrate` restored it to **74** with the identical fingerprint above.

---

## 13. Deferred constraints — this batch owes none

**`fk_vital_rm` (`pasien_tanda_vital.rekam_medis_id`, SQL section `[14]`
`:1161-1163`) is the only row in the *Deferred constraints* registry and the only
constraint migration `2026_10_01_000076` adds. Batch J defers nothing and
registers nothing.** The registry is unchanged (1 row, re-counted by parsing the
markdown) and `pasien_tanda_vital.rekam_medis_id` still has **0** live foreign keys
(check P).

All 7 of this batch's foreign keys point at a table that already exists when its own
migration runs — `pasien` 20 (batch C), and `master_metode_pembayaran` 61,
`invoice` 62, `pembayaran` 63, `master_promo` 65, **four of them from inside this
same batch**, earlier in the same commit. Nothing is deferred *to* this batch
either: the earliest referent is `pasien` in batch C.

**`fk_vital_rm` was not added here.** Per A.10/A.11 it is migration 76's job, and
the three edits its resolution will need (delete the `## Deferred constraints`
section, remove the `DeferredConstraintRegistry::fromMarkdown()` call from
`VerifySchemaParity`, remove the `$registeredDeferrals` line from
`VerifySchemaCommandTest.php`) are todo 18's, not mine.

**The three FK-less columns in this batch are bare by contract, NOT deferred** —
`invoice.referensi_id`, `klaim_bpjs.booking_id`, `klaim_bpjs.rekam_medis_id`. All
three of their target tables already exist, so a registry row would promise a
constraint the DDL never declares, and the constraint would in any case be
`extra_foreign_key` drift. The `pembayaran.nomor_referensi` absent index is a
fourth "absent by contract" item and is documented in the migration comment rather
than in a registry, because the registry only forgives *named constraints*.

---

## 14. Data safety

### Before / after census of every application database

Read-only, via `information_schema`. No `mysqld` was stopped and the user's
`php artisan serve` (PID 22288) was never touched.

| schema | before: tables / views / rows | after: tables / views / rows | verdict |
|---|---|---|---|
| `telemedisin_db` | 67 / 0 / 63 | **74 / 0 / 70** | +7 tables (this batch), +7 ledger rows, **0 domain rows** |
| `telemedisin_db_test` | 67 / 0 / 63 | **74 / 0 / 70** | identical |
| `sehatly` | 10 / 0 / 6, **5 migration rows** | **10 / 0 / 6, 5 migration rows** | **untouched** |
| `db_simprapkl` | 26 / 0 / 111 | 26 / 0 / 111 | untouched |
| `gawaiseken` | 18 / 0 / 81 | 18 / 0 / 81 | untouched |
| `laravel` | 5 / 0 / 4 | 5 / 0 / 4 | untouched |
| `manajemen-surat` | 0 / 0 / 0 | 0 / 0 / 0 | untouched |
| `trading_journal` | 12 / 0 / 22 | 12 / 0 / 22 | untouched |
| `ukk` | 12 / 0 / 17 | 12 / 0 / 17 | untouched |
| `ukk_pengaduan_sekolah` | 15 / 0 / 41 | 15 / 0 / 41 | untouched |

`telemedisin_db` and `telemedisin_db_test`: **74 tables, 0 domain rows** — all 70
rows are the `migrations` ledger (3 scaffold + 67 contract), measured by summing
every base table and subtracting the ledger. `sehatly` is **10 tables with its
original 5 migration rows**, unchanged. **Every unrelated database is byte-for-byte
identical in table count and row count.**

### Probe artifacts

The only rows ever created were by `migrate:fresh` and `migrate`, which write the
ledger and nothing else; both databases are left at 0 domain rows, and
`migrate:rollback --step=7` + `migrate` converged to the same fingerprint. No
`INSERT` of domain data appears in any migration, and **no seeder was created or
run**.

---

## 15. Adversarial classes

**`misleading_success_output` — APPLIES, probed three ways.** (i) Rule 1b's
four-way executable-statement count matched the DDL's ordered column list on all
7 files, 73 = 73, so no declaration was swallowed into a comment. (ii) The comment
-accuracy cross-check paired **80** declarations with their comments and the live
schema: 0 contradictions after two real prose ambiguities were rephrased. (iii)
Batch A-I regression: **17/17 green on both databases**, covering all 12 batches
A-I items the brief named plus 5 more. (iv) Probes (a) and (b) demonstrated the
failure mode directly: a missing index and a truncated ENUM both left
`migrate:fresh` and the 93-test suite **fully green**.

**`stale_state` — CLEARED.** `bootstrap/cache/config.php` absent before
(`Test-Path` → `False`) and `php artisan config:clear` run before the first
`migrate:fresh` and again before the final verification pass; still absent
afterwards. `$env:GIT_INDEX_FILE` unset. All 7 files sort after `000060` and
`2026_10_01_000061` is the lowest of the batch (the migrator's own output lists
them in order, `000061` … `000067`, immediately after `000060_lab_hasil_table`).
**Both databases were migrated**, in separate fresh shells with
`$env:DB_DATABASE='telemedisin_db_test'` for the test one. **Every
`information_schema` query in this file names its schema explicitly** — the helper
takes `$schema` as a required argument and interpolates
`pdo()->quote($schema)`; there is no unqualified query anywhere.

**`dirty_worktree` — CLEAN.** Before: `M .omo/plans/sehatly-telemedicine.md`,
`?? .omo/evidence/task-3-sehatly.md`, `?? .omo/start-work/`. After: the same three,
plus exactly my nine paths. `.omo/plans/` is orchestrator-owned and under active
edit, `.omo/start-work/` and `.omo/evidence/task-3-sehatly.md` are orchestrator-owned
and untracked. **The commit contains only my paths**, asserted two ways after
committing: `git diff --cached --name-only` empty, and `git show --name-only
--format="" HEAD` listing only the 7 migrations + `docs/schema-notes.md` +
`.omo/evidence/task-16-sehatly.md`.

**`hung_or_long_commands` — CLEARED.** Every artisan invocation ran inside
`Start-Job` with `Wait-Job -Timeout` (600-900 s) and its exit code read from the
job's output stream, with a 99/98 sentinel if the job timed out or returned
nothing. No `Start-Process -PassThru` was used, so the empty-`.ExitCode` .NET
handle-caching quirk could not occur; had it occurred it would have been treated
as failure, per the brief. The pre-existing `mysqld` and the user's
`php artisan serve` (PID 22288) were never killed or signalled.

**`repeated_interruptions` — CLEARED.** Convergence is proven by one fingerprint
across three independent rebuilds (§12), and the rollback cycle drops children
before parents with `migrate` restoring the identical fingerprint.

**`malformed_input` — RULED OUT.** No parser was authored. The only file read by a
parser this todo touched is `telemedicine_test.sql`, and
`SqlSchemaParser::collectInlineConstraints()` already skips `COMMENT` payloads, so
their text is never keyword-scanned. The one hand-rolled parser I wrote (the
stripper in §3 method D) is string-aware: it tracks `'` and `"` state and does not
treat a `//` or `/*` inside a string literal as a comment, and it was validated
against files containing `'Polimorfik'`, `'Transaction ID payment gateway'` and
`'Surat Eligibilitas Peserta (V-Claim)'`.

**`prompt_injection` — RULED OUT, and nothing resembling an instruction was
found.** `telemedicine_test.sql` is first-party DDL read strictly as a
specification. Its `COMMENT` payloads were enumerated rather than obeyed: there are
exactly **8** `COMMENT` clauses in the whole file, of which this batch owns two —
`referensi_id … COMMENT 'Polimorfik'` (`:941`) and
`nomor_referensi … COMMENT 'Transaction ID payment gateway'` (`:963`) — plus
`nomor_sep … COMMENT 'Surat Eligibilitas Peserta (V-Claim)'` (`:1016`). All three
are noun phrases describing the column. **None is an instruction, none contains an
imperative, and none was acted upon as anything but text to be reproduced
faithfully.** `SqlSchemaParser::collectInlineConstraints()` skips `COMMENT`
payloads, so they cannot reach a keyword scan. Nothing in the file or in
`docs/` attempted to redirect this work.

**`cancel_resume` — RULED OUT.** No resumable user flow. Every step is a fresh
`php artisan` invocation with no session state, and the fingerprint in §12 is the
convergence proof that a restart from any point reaches the same schema.

**`flaky_tests` — RULED OUT.** Deterministic. The suite ran **six** times in this
todo (baseline, after each of probes a / b1 / b2, after the `schema-notes.md` edit,
and in the final pass) and returned `93/93, 473 assertions` on **every** run.
Zero-match is measured, not assumed: `--filter=ThisTestNameCannotPossiblyExistZzz9`
exits **1** with `"No tests found."`.

---

## 16. Cleanup

- All scratch files live in `%TEMP%` (`C:\Users\axioo\AppData\Local\Temp\opencode\`)
  and `%TEMP%` itself — **never in the repository**. Nothing under
  `C:\Users\axioo\Desktop\sehatly` other than the 9 committed paths was created.
  Deleted on completion: `t16-census.php`, `t16-schema.php`, `t16-audit1.php`,
  `t16-audit2.php`, `t16-audit2.out.txt`, `t16-comments.php`,
  `t16-comments.out.txt`, `t16-regress.php`, `t16-run.ps1`, `t16-job.ps1`,
  `t16-scan-md.php`, `t16-invoice-pristine.php`, `t16-fp-telemedisin_db.txt`,
  `t16-fp-telemedisin_db_test.txt`, and the 14 `t16-<label>.{out,err}.txt` job logs.
- `bootstrap/cache/config.php`: **absent** (`Test-Path` → `False`).
- No leftover process: every `Start-Job` was `Remove-Job`-ed, including on the
  timeout path. No `mysqld` or `php artisan serve` was started or stopped.
- **No file I did not create was deleted.** No stray file was found; had one been
  found it would have been reported, not removed.

---

## 17. Committed paths

`git add -- database/migrations docs/schema-notes.md .omo/evidence/task-16-sehatly.md`
`git commit -m "feat(db): migrate invoicing, payment, refund, promo and BPJS tables" -- <same paths>`

```
database/migrations/2026_10_01_000061_master_metode_pembayaran_table.php
database/migrations/2026_10_01_000062_invoice_table.php
database/migrations/2026_10_01_000063_pembayaran_table.php
database/migrations/2026_10_01_000064_refund_table.php
database/migrations/2026_10_01_000065_master_promo_table.php
database/migrations/2026_10_01_000066_promo_redemption_table.php
database/migrations/2026_10_01_000067_klaim_bpjs_table.php
docs/schema-notes.md
.omo/evidence/task-16-sehatly.md
```

9 paths, 7 of them `A` (new) and 1 `M` (`docs/schema-notes.md`), plus this evidence
file. **0 deletions.**

---

## 18. Found but deliberately NOT fixed

1. **`docs/migration-order.md` rule 6's multi-line-ENUM list is wrong and is the text
   an executor will follow.** It names six entries; two (`konsultasi.status` `:542`,
   `konsultasi_chat.tipe_pesan` `:568`) are not wrapped and it omits `invoice.status`
   (`:947`-`:948`), which is. The true list is five. This file is outside my commit
   scope, and the same reasoning that left `000041_rujukan_table.php:93-96`'s stale
   `diagnosis_kerja` claim in place (A.19) applies: the correction is dispatched
   separately rather than smuggled into a migration batch. **It is a real, live,
   wrong instruction.**
2. **`.omo/plans/sehatly-telemedicine-platform.md` — five wrong citations**, listed
   in full in §8. Orchestrator-owned and under active edit; reported, not touched.
3. **The plan's todo-16 `Commit:` line disagrees with the brief's mandated commit
   message** (§8 row 8). The brief's was used, as instructed.
4. **`000041_rujukan_table.php:93-96`'s stale `diagnosis_kerja` claim** (A.19) — a
   pre-existing defect in a file I did not touch, correctly left in place.
5. **`master_promo.tipe_layanan`… no. `promo_redemption`'s missing uniqueness, and
   every unenforced quota in this batch** (§10, TRAP 3; and the `master_promo`
   docblock). These are **real schema limitations, not migration defects** — the DDL
   declares none and one cannot be added. Recorded in `docs/schema-notes.md` and in
   the migration comments so todos 44-46 inherit them, and **not** "fixed" by
   inventing an index.
6. **`SchemaDiffer::diffColumn()` masks `column_default` behind `column_nullable`**
   (its own comment at `app/Support/Schema/SchemaDiffer.php` lines 179-181 says
   "Nullability is the root cause here; comparing the default on top of it would
   report one change twice and bury the real one"). Discovered by probe (b2),
   deliberate, and it does not weaken the rejection — exit 1 either way. Not changed.
7. **`master_metode_pembayaran.tipe` has 3 ENUM values with no seed row**
   (`kartu_kredit`, `gerai_retail`, `asuransi`) and `master_promo` has none at all.
   Todo 18's seeder decision; recorded, not acted on.

---

## 19. Summary of every count, and how it was derived

Per A.20: **no count in this file is taken from another tool on trust.** Each was
computed here and the computation is shown.

| Count | Value | Derivation |
|---|---|---|
| columns in batch J | **73** | four independent derivations, §3, all `===` on the ordered name list |
| per-file column counts | 8 / 15 / 11 / 6 / 12 / 6 / 15 | same, printed per file |
| executable statements per file | 8 / 15 / 11 / 6 / 12 / 6 / 15 | `token_get_all` (C) and strip+regex (D), agreeing |
| ENUM lists in batch J | **9** | enumeration of columns whose DDL type is `ENUM`, §6 |
| `invoice.status` values | **7** | read from `:947` **and** `:948`, cross-checked against the parser |
| wrapped ENUMs in the whole contract | **5** | my own paren-balance walk, 5 lines, each resolved to `table.column`, §6 |
| live FKs on the 7 tables | **7** | `REFERENTIAL_CONSTRAINTS` ⋈ `KEY_COLUMN_USAGE`, §7c |
| DDL `FOREIGN KEY` clauses on the 7 tables | **7** | the same query run over the parsed reference model |
| signed/unsigned integer+decimal columns | **35** = 19 unsigned + 16 signed | `information_schema` filter, flag **derived** by `str_ends_with(trim($ty),'unsigned')` |
| signedness of the three bare columns | 3 columns, **0** FKs each | `KEY_COLUMN_USAGE.REFERENCED_TABLE_NAME IS NOT NULL`, with existence confirmed |
| tables per database | **74** = 67 contract + 7 extras | set intersection and set difference, §7a check M |
| domain rows | **0** | sum of every base table minus the `migrations` ledger |
| full-verify discrepancy split | **18** = 10 drift + 8 informational | 8 `missing_table` + 2 `missing_view`; 1 `deferred_foreign_key` + 7 `documented_extra_table` |
| citation tokens checked | **162** distinct | regex extraction, deduplicated per file, each line printed |
| token occurrences audited | **863** extracted / **376** identifier-shaped checked | two-pass extraction against a DDL-derived vocabulary |
| unknown tokens | **32** distinct / 46 occurrences | every one read in place and classified, §4b |
| CJK / fullwidth characters | **0** | the A.17 regex, run per file on all 8 files I touched |
| comment-accuracy cross-checks | **80** declarations | one per `$table->…` call with a string first argument |
| regression spot-checks | **17**, run on 2 databases | §7a, all expectations derived from `SqlSchemaParser` |
| `ON DELETE` clauses in section `[11]` | **0** | scan of `:921-1034` |
| `COMMENT` clauses in the file | **8** total, **3** in this batch | enumeration |
| `ENUM` members spelled `kadaluwarsa` / `kadaluarsa` | **3** / **2** | `Select-String` over the whole file, each line printed |
| `master_metode_pembayaran` seed rows | **14**, covering **6 of 9** `tipe` values | counted from `:1264`-`:1278` |
| `INSERT INTO` statements in the file | **15**, **0** naming `master_promo` | enumeration |

**The one figure I deliberately did NOT report as a measurement** is the verifier's
`wrapped decls 11`. I inspected what it counts, found it answers a different
question, and said so (§6) rather than using it as agreement.

---

## 20. Commands and exit codes, in order

| # | Command | Exit |
|---|---|---|
| 1 | `php artisan config:clear` | 0 |
| 2 | census script (read-only, before) | 0 |
| 3 | `php -l` × 8 migrations | 0 each |
| 4 | Rule 1b four-way audit | 0, `allMatch=true` |
| 5 | Rule 2 CJK scan + token audit | 0, 0 CJK |
| 6 | Rule 1 citation extraction (162 tokens printed) | 0 |
| 7 | `php artisan migrate:fresh` (`telemedisin_db`) | **0** |
| 8 | `$env:DB_DATABASE='telemedisin_db_test'; php artisan migrate:fresh` | **0** |
| 9 | `sehatly:verify-schema --tables=<7 real names>` | **0**, `Discrepancies: 0` |
| 10 | `sehatly:verify-schema --tables=master_metode_pembryar,invoice` (control) | **0**, `unknown_requested_table` |
| 11 | `sehatly:verify-schema` (unfiltered) | **1**, `Discrepancies: 18` |
| 12 | `php artisan test tests/Unit` | **0**, 93/93/473 |
| 13 | `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` | **1** |
| 14 | `information_schema` columns / indexes / FKs / bare-column / noindex / signedness dumps | 0 |
| 15 | `SHOW CREATE TABLE` × 7 | 0 |
| 16 | probe (a): remove `idx_ref` → `migrate:fresh` | 0 |
| 17 | probe (a): `verify-schema --tables=invoice` | **1**, `missing_index idx_ref` |
| 18 | probe (a): `php artisan test tests/Unit` | **0**, 93/93/473 |
| 19 | restore; SHA-256 compared to pristine | MATCH |
| 20 | probe (b1): truncate `invoice.status` → `migrate:fresh` | 0 |
| 21 | probe (b1): `verify-schema --tables=invoice` | **1**, `column_type` |
| 22 | probe (b1): `php artisan test tests/Unit` | **0**, 93/93/473 |
| 23 | probe (b2): truncate + `->nullable()` → `migrate:fresh` | 0 |
| 24 | probe (b2): `verify-schema --tables=invoice` | **1**, `column_type` + `column_nullable` |
| 25 | restore; SHA-256 compared to pristine | MATCH |
| 26 | 2nd `migrate:fresh` × both databases | 0, 0 |
| 27 | fingerprint × both databases | identical |
| 28 | `php artisan migrate:rollback --step=7` | **0** |
| 29 | set-difference: back to 67 live tables | 0 |
| 30 | `php artisan migrate` | **0** |
| 31 | fingerprint after rollback + migrate | identical to step 27 |
| 32 | 17-check regression suite × both databases | 0, 0 |
| 33 | comment-accuracy cross-check (80 declarations) | 0, 0 contradictions |
| 34 | re-run of all four audits after the last edits | 0 |
| 35 | final `migrate:fresh` × both databases | 0, 0 |
| 36 | final scoped verify / typo control / full verify | 0 / 0 / 1 |
| 37 | final `test tests/Unit` / zero-match control | 0 / 1 |
| 38 | final census, SHA-256, `git diff --exit-code telemedicine_test.sql` | 0, hash match, 0 |
| 39 | `vendor/bin/pint` (bare, no path argument) | see §Commit |
| 40 | `git add` / `git commit` with explicit pathspecs | 0 |

---

## 21. Commit

`vendor/bin/pint` was run **bare**, with no path argument, immediately before
staging — per A.7, naming `bootstrap` or `.` would override pint's default exclude
and make it try to reformat `bootstrap/cache/packages.php`.

```
git add -- database/migrations docs/schema-notes.md .omo/evidence/task-16-sehatly.md
git commit -m "feat(db): migrate invoicing, payment, refund, promo and BPJS tables" \
            -- database/migrations docs/schema-notes.md .omo/evidence/task-16-sehatly.md
```

Post-commit assertions:

```
git diff --cached --name-only              -> (empty)
git show --name-only --format="" HEAD       -> the 9 paths in §17, and nothing else
git status --porcelain                      -> the 3 pre-existing orchestrator entries only
```

No `git add -A`, `git add .`, `git commit -a`, `git add -u`, `git stash`,
`git checkout .`, `git restore .`, `git clean`, `git reset`, `--amend`, `push`, or
any commit to `main`. No `php artisan install:api` in any form (A.5). No path
argument to pint.
