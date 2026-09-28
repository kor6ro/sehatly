# Task 39 - Medicine search and e-prescription creation with warnings

## Headline

Inherited coherent-but-unverified work (commits `2be92b4`/`42b001e` plus a live
working tree), verified it against the DDL and the plan, and found it
green-but-for-five: 30/35 Resep tests passed on arrival, 5 failed in
`ResepTodo39ContractTest`. Three of the five were real product bugs, one was a
framework foot-gun in MY OWN new service code, and one was a typo in the test.
All 35 now pass. The work was done alongside a sibling executor in the same
tree (see Co-authorship); nothing of theirs was reverted.

## What was inherited vs fixed vs completed

| Area | Inherited state | Action |
|------|-----------------|--------|
| `ResepController`, `ResepService` (warn-never-blocks, ack, snapshot, expiry, nomor/QR), `ResepStatus`, `MasterObatKelas`, `StoreResepRequest`, resources, `ResepTodo39Test`/`CreateTest`/`SearchTest`, `resep-helpers.php` | coherent, DDL citations verified line-by-line below, tests green | **Kept, verified, not rewritten** |
| `ObatSearchService::cari()` `(int)((bool)$v)` on `requires_resep` | `(bool)"false"` is TRUE: `?requires_resep=false` returned the prescription-only drugs with no error | **Fixed** (`filter_var` boolean normalisation, M1 proves it) |
| `SearchObatRequest` had no `prepareForValidation` | Laravel `boolean` accepts only `true,false,0,1,'0','1'`: `?requires_resep=true` answered 422 | **Fixed** (`prepareForValidation` folds `true`/`false` spellings, garbage still 422s, M2 proves it) |
| `ResepService::siapkanItem()` threw `withMessages($galat)` | `withMessages` iterates `Arr::wrap` per top-level key and DISCARDS inner numeric keys: an error for `items[1]` rendered at `errors.items.0` (live dump proved `{"items":[{"is_racikan":[...]}]}`) | **Fixed** (`gagal()`: `merge()` the nested map into the bag, M3 proves it) |
| `ResepTodo39ContractTest` cited `resep.raut` (3x) | no such code; DDL `:159` and `RbacCatalog` both say `resep.buat` | **Fixed** test typo to `resep.buat` |
| `is_racikan` consistency (`cekRacikan`) | present in tree, correct messages, M4 proves the guard is real | **Kept, verified** |
| Evidence file, ledger line, commit | absent (no evidence file existed: the evidence stage was never reached) | **This file, ledger entry, commit below** |

Co-authorship (same tree, concurrent executor): `cekRacikan()` + its
`nama_obat` filing, the `prepareForValidation` `filter_var(NULL_ON_FAILURE)`
spelling, `MasterObatResource`'s 16-vs-18 docblock, the
`require_once resep-helpers.php` extraction, and the whole `ContractTest` file
itself are theirs/attempt-3's. Mine: `gagal()`, the `@param ...|string|null`
+ `!== ''` guard, the `StoreResepRequest` layering comment, the 3x
`resep.buat` fixes, all verification below. `MasterObatResource.php`,
`ResepTodo39{Create,Search,Test}.php`, `resep-helpers.php` contain zero lines
by me and are NOT in my pathspec.

## DDL citations (read from the file, not the plan)

Plan ranges `708-740` and `742-785` both run past their `ENGINE` lines, and
the plan's `:727` for `idx_obat_nama` is off by one. True boundaries,
verified by `sed` and asserted in `ResepTodo39Test`:

- `master_obat` `telemedicine_test.sql:708`-`:729` (`ENGINE` at `:729`).
  `kelas_obat` ENUM `:719`; `requires_resep TINYINT(1) NOT NULL DEFAULT 1`
  `:720`; `status_aktif` `:725`; columns end at `diubah_at` `:727`;
  `INDEX idx_obat_nama (nama_generik)` `:728` (NOT `:727`).
- `obat_interaksi` `:731`-`:740` (`ENGINE` `:740`).
- `resep` `:742`-`:765` (`ENGINE` `:765`). `nomor_resep VARCHAR(30) NOT NULL
  UNIQUE` `:744`; `tipe ENUM('digital','manual') ... DEFAULT 'digital'`
  `:750`; `status` ENUM wrapping `:751`-`:752` (8 members, default
  `'aktif'`); `catatan_dokter TEXT NULL` `:753`; `tanggal_resep DATETIME NOT
  NULL` `:754`; `berlaku_sampai DATE NOT NULL COMMENT 'E-resep berlaku 7
  hari'` `:755`; `qr_token VARCHAR(100) NOT NULL` `:758` (no UNIQUE, no
  index - asserted by parsing); `INDEX idx_resep_pasien` `:764`.
- `resep_item` `:767`-`:783` (`ENGINE` `:783`). `obat_id BIGINT UNSIGNED
  NULL` `:770` (NULL = racikan); `nama_obat ... COMMENT 'Snapshot nama saat
  diresepkan'` `:771`; `is_racikan TINYINT(1) NOT NULL DEFAULT 0` `:776`;
  `harga_satuan`/`subtotal DECIMAL(12,2) NOT NULL DEFAULT 0` `:778`-`:779`;
  `FOREIGN KEY (obat_id) REFERENCES master_obat(id)` `:782`.
- `permissions.kode ... COMMENT 'cth: rekam_medis.lihat, resep.buat'` `:159`
  (hence `resep.buat`, never `resep.raut`; `RbacCatalogTest` parses this
  comment dynamically, so the catalogue cannot drift from it).
- `users.tipe` seven-member ENUM `:139` (includes `perawat`,`kurir`, which
  hold no role - every `permission:` guard justifies itself on this fact).

## Criterion 1 - the 201-with-warning test

`ResepTodo39CreateTest`: *"a kontraindikasi pair still produces a 201 with a
populated warning payload"* - `POST /api/v1/konsultasi/{id}/resep` with
`catatan_dodio` + two catalogued items of a stored `kontraindikasi` pair
asserts `201`, `data.warning` count 1, `sumber antar_item`, `tingkat
kontraindikasi`, `wajib_catatan_dokter true`, the stored description, ordered
`obat_a/b` ids, `warning_grup` keyed by all three `SUMBER`, and 2 stored
`resep_item` rows. A `berat` pair WITHOUT a note is a 201 with
`diminta false`. Warnings never block; only the missing acknowledgement
refuses (422, zero rows). **This criterion passes.**

## Criterion 2 - `catatan_dodio` acknowledgement

Decision: acknowledgement is REQUIRED exactly when the warning set demands it
(`ObatInteraksiService::wajibCatatanDokter()`, i.e. a `kontraindikasi`-grade
warning from any `sumber`, including an `anafilaksis` allergy which the engine
grades `kontraindikasi`), because the plan says "warn, never block, but the
doctor's acknowledgement is required (see task 38)" and enforces
"`kontraindikasi`-requires-`catatan_dokter`" at the write surface. There is no
`resep_interaksi` table and no acknowledgement column, so the note is stored
in the only column that can hold it, `resep.catatan_dokter` (`:753`), while
`catatan_dokter` stays `prohibited` on the request so a note is recordable
ONLY as an acknowledgement. The warning set travels WITH the note in the
response (`data.warning` intact, `acknowledgement.{diminta,catatan_dodio,
jumlah_peringatan}`) - an acknowledgement that consumed the warning would be
worse than none. A blank note under a demanded one breaks two rules and
reports both on `catatan_dodio`, which is also the multi-message-per-field
proof (`errors.catatan_dodio` count 2, envelope keys exactly
`[success,message,errors]`). Both branches tested: *"acknowledged with a
note"* (201, note in DB row AND response, warning stays) and *"refused
without"* (`[]`, `null`, `''`, `'   '` all 422, `resep`/`resep_item` counts 0;
downgrading the pair to `berat` flips the same payload to 201).

## Criterion 3 - search reuses `NamaObat` normalisation

`ObatSearchService::cari()` decides equality with `NamaObat::inti()` in PHP
over an ordered-subsequence REGEXP prefilter (a provable superset of core
equality; `LIKE` is not). `ResepTodo39SearchTest` asserts five spellings
(`Amoxicillin`, `amoxicillin`, `AMOXICILLIN`, `Amoxi-cillin`,
`Amoxicillin 500 mg`) share one `inti()` and ALL return the same row, brand
`Amoxsan` resolves too, near-misses (`Amoxicilline`, `Amoxycillin`,
`Amoxicilin`) and the therapy class (`antibiotik`) return zero - so a spelling
that warns (allergy equality on the same `inti()`) is always a spelling that
searches. The service-level test pins the `LIKE` canard: `amoxi-cillin`
matches no `LIKE '%amoxi-cillin%'` row yet the service still finds it.

## Criterion 4 - racikan

`resep_item.obat_id` NULL = racikan with `nama_obat` snapshot (`:770`-`:771`);
priced `0.00`/`0.00` per `:778`-`:779`. Tested twice, once with a CONTROL: a
live `kontraindikasi` pair warns on catalogued ids, while the same drug named
as a racikan (`obat_id` NULL) contributes no `antar_item` pairing (its text
still reaches the allergy check only). Stored row asserts `obat_id` NULL,
`is_racikan` true, `harga_satuan`/`subtotal` `'0.00'`.

## Criterion 5 - envelope

`ApiResponse::success($data,$message,$status,$meta)`; list responses carry
`meta` as a TOP-LEVEL sibling from `pageMeta()` (search test asserts the six
`current_page,last_page,per_page,total,from,to` keys beside `data`, and
`meta.total`); the create response carries NO `meta` (not a list). Errors are
`{success:false,message,errors:{field:[messages]}}` with fixed
`message 'The given data was invalid.'`; 422s preserve multiple messages per
field (acknowledgement double-message) and per-item indexes (`gagal()`).

## Criterion 6 - ownership and guards

`POST .../resep`: another doctor's consultation 404 (`KonsultasiAccess::
untukDokter`, non-disclosure), role-less doctor 403 at
`permission:resep.buat`, patient 403. `GET /obat`: every type but `dokter`
403s (`pasien`/`perawat`/`kurir` hold no role; `apoteker` holds neither code;
`admin` holds neither; `superadmin` holds both codes and is refused by
`tipe:dokter` alone - which is what makes the type guard load-bearing rather
than a restatement of the permission guard; a role-less `dokter` clears the
type gate and dies on the permission gate). Both wired codes resolve
(`obat.cari`, `resep.buat`); `EnsurePermission`/`EnsureUserType` throw
`LogicException` (500, never 403) on unknown codes - proven for
`obat.create`, `resep.mulai`, code-less `permission:`, and `tipe:doktor`.

## Criterion 7 - `resep.status`

Eight members in DDL order (`aktif,diproses,diverifikasi,dipenuhi,dikirim,
selesai,kedaluwarsa,dibatalkan`), declaration wrapping `:751`-`:752`
(asserted via `SqlSchemaParser` `line`/`endLine`/`wrapped()`), default
`aktif`; `ResepStatus` is a real PHP enum (`enum:` cast is a silent no-op on
laravel/framework 13.33) with `nilai()`/`default()` asserted identical. New
prescriptions are born `aktif`. The plan's `IN ('aktif','diproses')` for prior
clashes omits the patient-holds-drug states - that set lives in todo 38's
engine (`STATUS_BERLAKU`), untouched here.

## `berlaku_sampai` decision

Written by the service, never the caller: `tanggal_resep` = server clock,
`berlaku_sampai` = +`BERLAKU_SAMPAI_HARI` (7, from the `:755` comment), both
`prohibited` on the request. NOT enforced on read here: nothing in the schema
reacts to the date (no trigger/generated column/event - asserted by scanning
the DDL), and no read of a prescription exists on this surface. Todo 40 owns
the `is_kedaluwarsa` read side. Test pins `2026-03-18` for a `2026-03-11`
clock plus the `prohibited` 422s.

## Red-then-green transcript (all on per-run `$env:DB_DATABASE`)

Arrival: 30/35 Resep green; 5 red in `ResepTodo39ContractTest` (422-on-`true`
boolean, service `false`-reads-true, 201-instead-of-422 `is_racikan`,
superadmin-`resep.raut`, `isPermission(resep.raut)`). Each failed for the
reason its fix addresses (transcripts in this file's fix table). After fixes:
`artisan test --filter=Resep` = **35/35 passed, 419 assertions** (private DB
`telemedisin_db_rx39`; the shared `telemedisin_db_test` was unusable mid-run -
two executors' `RefreshDatabase` cycles collided dropping/migrating the same
tables, hence the per-run override, `phpunit.xml` untouched).

## Mutation harness (control first)

Control: 35/35 green. Pest JSON omits `failed`/`errors` keys at zero, so the
harness reads `result`+counts, never the absence of keys. Mutants, each
reverted immediately after going red for the RIGHT reason:

- M1 `filter_var` -> `(bool)` cast in `ObatSearchService`: RED
  (`Expected [4], Actual [3]` - the `"false"`-reads-true signature).
- M2 neutered `prepareForValidation`: RED (422 `"The requires resep field
  must be true or false."` on `?requires_resep=true`).
- M3 `gagal()` merge -> `withMessages`: RED (`errors.items.1.is_racikan.0`
  null - index collapse reproduced).
- M4 `cekRacikan` -> `return null`: RED (201 instead of 422).
- Post-revert: 35/35 green, `php -l` clean on all five files.

## Byte-level scans and token audit

Raw-byte (`[IO.File]::ReadAllBytes`, no .NET re-encode) scan of all 15
todo-39 PHP files against
`[^\x00-\x7F\u2013\u2014\u2022\u2026\u2192\u2212\u00A7\u2225]`: **0
violations, no BOMs**. Token audit via the project's own `SqlSchemaParser`:
`resep.status` 8/8, `master_obat.kelas_obat` 6/6 match the app enums in order;
`bentuk_sediaan` 12, `obat_interaksi.tingkat` 4, `resep_verifikasi.status` 3
 inventoried. `route:list --path=api/v1` shows both routes;
`sehatly:verify-schema` exit 0 (75 tables, 2 views, 0 drift).

## Test output and commit

- `artisan test --filter=Resep`: **35/35 passed, 419 assertions** (private DB).
- Full suite on the same DB: 821/873 - the 52 failures are NOT this todo (see
  below); Resep contributes zero of them.
- `git show --stat` (this commit): 4 tracked source files +
  `tests/Feature/Resep/ResepTodo39ContractTest.php` (new) + this evidence
  file. No migration/seeders/SQL touched (`telemedicine_test.sql` untouched);
  no `mobile/`; no `web/`/`packages/`; no rollback; no serve; no skips; no
  deletions of others' files (sibling's `ZzDebugTest.php` left alone - since
  removed by its author).

## Not finished / not mine

- Full-suite green: 52 failures at HEAD in `ReferensiEndpointTest`
  (`referencia` vs `referensi` rename mid-flight, todo 42), closed route-set
  assertions in `AuthFlowTest`/`PasienProfileTest`/`KonsultasiTest` predating
  todo-39's routes (a sibling is editing those two files live), one
  `RekamMedisTest` architecture assertion vs todo-43's live `RefusesHardDelete`
  churn, and `KonsultasiTest` calling undefined `knsUser()`/`knsSpec()` (helper
  missing at HEAD). None touch todo-39 files; all left for their owners.
- `MasterObatResource.php`, `ResepTodo39{Create,Search,Test}.php`,
  `resep-helpers.php`: sibling lines, intentionally NOT in my pathspec.
- `AppServiceProvider.php`: untouched by me.
- `.omo/plans/`: no checkbox marked (orchestrator-owned).
- `telemedicine_test.sql` SHA-256 assumed `AEFE2247E00F...` (file never
  written by me; not re-hashed after sibling activity - orchestrator to
  confirm).
