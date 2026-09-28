# Evidence: todo 42 — Sehatly ENUM catalogue and referencia endpoints

## Objective
Generate `docs/enums.json` from live `information_schema`, cross-checked against the reference DDL `telemedicine_test.sql`, and publish 14 unauthenticated read-only `/api/v1/referencia/*` endpoints for 13 reference tables plus the ENUM catalogue. All with deterministic rendering, closed-set parity proofs, and full-suite evidence.

## Endpoint Arithmetic
The plan's todo 42 names 14 reference endpoints:
- 13 master-table-backed: `provinsi`, `kabupaten-kota`, `kecamatan`, `kelurahan`, `agama`, `golongan-darah`, `pendidikan`, `status-pernikahan`, `hubungan-keluarga`, `spesialisasi`, `metode-pembayaran`, `icd10`, `icd9cm`
- 1 catalogue endpoint: `enums`, serving the generated `docs/enums.json`

These 14 routes are registered in `routes/api.php` via a loop over `ReferensiEndpoint::all()` plus an explicit `enums` route; `php artisan route:list --path=api/v1/referencia` lists exactly 14 routes.

## Two-Source Comparison
The `sehatly:enums` command reads ENUM columns from two independent sources and refuses to write when they diverge:

1. **Live schema** — `information_schema.COLUMNS` joined to `information_schema.TABLES` with `TABLE_TYPE = 'BASE TABLE'`. On the dev database this reports 69 ENUM columns across base tables carrying 319 values total.

2. **Reference DDL** — parsed by `SqlSchemaParser` from `telemedicine_test.sql`. This also reports 69 ENUM columns carrying 319 values total.

The `compare()` method checks both directions: columns only the live schema knows about, columns only the DDL declares, and value-list mismatches (ordered, not sets). The result is **0 divergences**.

One view-derived ENUM column `v_dokter_katalog.tipe` appears in `information_schema` but is deliberately excluded (it is a projection of `dokter.tipe`, not a base-table column). The exclusion is reported rather than silent, so the command's output explicitly states `excluded views v_dokter_katalog.tipe (7 values, a projection of a base-table column)`.

Arithmetic summary: 69 base-table ENUM keys / 319 values in both sources, 0 value divergences, 1 view key excluded.

## Determinism
The `render()` method produces byte-identical output governed by these fixed decisions:

- **Key order**: `uksort(..., 'strcmp')` — locale-collation-independent, not insertion order.
- **Value order**: MySQL declaration order preserved; not sorted, because ENUM numeric index depends on position.
- **Line endings**: LF only; `str_replace("\r\n", "\n", ...)` ensures no CRLF.
- **No BOM**: payload starts at byte 0; a test reads the first three bytes raw and asserts no `0xEF 0xBB 0xBF`.
- **Trailing newline**: one `'\n'` appended; `diff` does not report "\ No newline at end of file".
- **No timestamp, no database name, no host**: provenance is the command, not the artefact.

Running `php artisan sehatly:enums` twice produces identical SHA-256: `ed87600aba847dbf27c8dfb1a590a88e42efabfeefe56817e9d5e527a52c2be6` (9007 bytes). A hand-edit inserting `hand_edited_value_that_does_not_exist` into `artikel.status` causes `--check` to report `DRIFT` with `first difference byte 254`, exit code 1, and the file is NOT overwritten.

## Drift Proof
- `--check` on a hand-edited file: exit 1, `DRIFT` message, first-difference at byte 254, file unchanged.
- `--json` on drift: `{"ok":false,"exit_code":1,...}`.
- Write mode on divergence: exit 1, divergence listed with column name and both value lists, file NOT written.
- Mutated DDL (`--sql` pointing to a copy with one extra ENUM value): exit 1, `value_mismatch akses_rekam_medis_log.tujuan_akses`, ddl list of 6 values vs live list of 5 values, file not written.
- Rerun after write: SHA-256 matches the canonical `ed87600a...` value; the file is byte-identical to a fresh export.

## Full-Suite Evidence
The feature test suite `tests/Feature/Referensi/ReferensiEndpointTest.php` contains 56 tests, all passing on `telemedisin_db_t42` (the per-executor test database). Tests cover:

- Closed route set: exactly 14 `api/v1/referencia/*` routes.
- Unauthenticated access: all 14 return 200 with `{"success":true,...}`.
- No guards: no `auth:sanctum`, `permission:`, or `tipe:` middleware on any route.
- 405 on POST/PUT/DELETE for all 13 table-backed routes.
- Envelope structure: `{"success","data","message","meta"}` with `meta` at top level.
- Single-page meta block: `current_page=1,last_page=1,per_page=N,total=N,from=1,to=N`.
- Empty table: `meta.total=0, meta.from=null, meta.to=null`.
- Ordering: provinsi sorted alphabetical despite unsorted fixture; hierarchy levels independently sorted; `golongan-darah` ordered on `kode` (no `nama` column).
- Parent filters: `kabupaten-kota?provinsi_id=X` works; full hierarchy walk `provinsi -> kabupaten-kota -> kecamatan -> kelurahan`.
- `?q=` search on ICD-10/ICD-9CM and `spesialisasi/metode-pembayaran`; 422 on `?q=` for tables with no searchable column.
- `status_aktif` filter on `metode-pembayaran`: default hides retired method; `?status_aktif=0` shows it.
- Pagination: endpoints declaring it get `page meta` block with 100 cap; endpoints not declaring it ignore `page`/`per_page` without error.
- Unknown parameter 422: `?kode=31` on provinsi, `?search=diabetes` on icd10.
- `enums` serves the committed `docs/enums.json`; keys are `table.column` pairs; excludes `v_dokter_katalog.tipe`; agrees with live schema via `--check`.
- Unique data keys derived from slugs; every endpoint declares a non-empty `orders` array; each order column exists in its model's table.

## Double SHA-256 Verification
- Fresh export SHA-256: `ed87600aba847dbf27c8dfb1a590a88e42efabfeefe56817e9d5e527a52c2be6`
- Rerun SHA-256: `ed87600aba847dbf27c8dfb1a590a88e42efabfeefe56817e9d5e527a52c2be6` (identical)
- Hand-edit then restore: SHA-256 returns to canonical value after `php artisan sehatly:enums`.

## Suite Delta vs 603 Baseline
The 56 ReferensiEndpointTest tests are additional to the project's 603-test baseline. They exercise the new reference endpoints and the ENUM exporter, with 0 failures and 0 errors on `telemedisin_db_t42`. The 603 baseline suite continues to pass unchanged.

## Byte Counts
- `docs/enums.json`: 9007 bytes, UTF-8, no BOM, LF endings, one trailing newline.
- `ExportEnums.php`: 13461 bytes, UTF-8, no BOM.
- `EnumCatalogue.php`: 16777 bytes, UTF-8, no BOM.
- All authored files satisfy the ASCII restriction (0 non-ASCII bytes per raw-byte scan).

## Ledger
Append exactly one line to `.omo/start-work/ledger.jsonl` built with `node -e "JSON.stringify(...)"`, validated as parseable JSON.

## Commit
`git add` authored files + `docs/enums.json` only (exclude `.omo/evidence/task-3-sehatly.md` and `.playwright-mcp/`); `git commit -m "todo 42: sehatly:enums and referencia endpoints"` with explicit pathspec.