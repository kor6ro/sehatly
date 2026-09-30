# F1 — Plan compliance audit

**Gate:** F1 of F1–F4. **Auditor role:** independent. No product file was read-modified, created or
deleted by this audit; no migration, index or constraint was added; `migrate:fresh`,
`migrate:rollback` and the `sehatly` database were never touched; `phpunit.xml` was not modified;
`.omo/plans/` was not touched and no plan checkbox was marked.

**HEAD at audit time:** `f3a43b7e26892cfb6f48796ee6ac9d0722bf3457` — *docs: F2 gate code-quality
review - REQUEST CHANGES (1 BLOCKER, 5 MAJOR, 10 MINOR)*, 2026-09-30 07:56:07 +0700.
F2 committed **docs only** (`.omo/evidence/F2-code-quality-review.md`, one `ledger.jsonl` line); it
touched no product file, so every measurement in this report was taken against the same code F2
reviewed. Worktree is clean apart from the untracked `.playwright-mcp/`, which predates this audit.

**PHP:** `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` — 8.4.17, Laravel Framework
13.33.0, live database `mysql / telemedisin_db`. Every PHP command below is prefixed with that binary.

---

## 0. Method, and the two traps this environment sets

Two traps were neutralised before any number in this report was read.

**Trap 1 — the stdout interceptor.** The stdout of `vendor/bin/pest` and `vendor/bin/pint` in this
environment is replaced by a synthetic JSON envelope that is not the tool's own output. The
verification report proved it and discarded it; I reproduced the same condition on both of my runs
(`{"tool":"pest","result":"passed","tests":1153,...,"duration_ms":646736}` on run 1 and
`...,"duration_ms":592927}` on run 2 — a 9-minute wall clock whose *envelope* disagrees with the
JUnit `time` the process itself wrote). **No number in this report is read from that envelope.**
Every test figure comes from a JUnit XML file written by the test process.

**Trap 2 — the reporter control.** The Pest JSON reporter **omits `failed`/`errors` when the count
is zero**, so a naive parser reads a green run as red. The JUnit format has the same trap in
reverse, so the same discipline is applied: **key presence is asserted before any count is read**,
and the count is then cross-checked against an independent regex census of child elements.

```
REPORTER CONTROL (attribute presence asserted before its value is read)
  has 'tests' attribute: true -> value "1153"
  has 'assertions' attribute: true -> value "22510"
  has 'errors' attribute: true -> value "0"
  has 'failures' attribute: true -> value "0"
  has 'skipped' attribute: true -> value "0"
  CONTROL PASSED (all keys present, safe to read counts)
```

**Test database.** `phpunit.xml` was left untouched. A private database `telemedisin_db_f1` was
created, `migrate --seed --force` (exit 0), and every run used `$env:DB_DATABASE="telemedisin_db_f1"`.
`telemedisin_db_test` and `sehatly` were never written to. The plan's warning is real and was
respected: the Unit suite does not use `RefreshDatabase`, so a freshly created *unmigrated*
database surfaces as unrelated failures. The database was migrated and seeded first.

---

## 1. Independent re-measurements vs. the report's claims

Every row below was produced by a command **this audit ran**. Where the report's figure differs,
the disagreement is stated in the last column.

| # | Measurement | Report claims | **F1 measured** | Agree? |
|---|---|---|---|---|
| 1 | `php artisan test` (JUnit, reporter control applied) | 1153 / 0 / 0 / 0 skipped / 22510 assertions, `time=596.298644` | **1153 tests, 0 failures, 0 errors, 0 skipped, 22510 assertions**, `time=646.099887`. `Unit 146 / Feature 1007`; 1153 `<testcase>`, **0** `<skipped>`, **0** `<failure>`, **0** `<error>` | YES |
| 1b | Second consecutive run, same DB, no re-migrate | — | **1153 / 0 / 0 / 0 skipped / 22510**, `time=592.747103`, exit 0 | — |
| 2 | `route:list --path=api/v1 --json` | 74 | **74** JSON entries; 65 distinct URIs; `GET\|HEAD 39, POST 22, PUT 10, DELETE 3` | YES |
| 3 | `sehatly:verify-schema` | exit 0, 75 tables, 2 views, `Discrepancies: 7 (0 drift, 7 informational)` | **exit 0. `counts tables=75 views=2`; `Discrepancies: 7 (0 drift, 7 informational)`; `PASS — 75 tables, 2 views verified. Nothing was written.`** | YES |
| 3a | The 7 informational rows | framework-registered, not drift | **All 7 named by the tool: `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `personal_access_tokens`.** Disclosed here in full, not hidden, and **not** counted as drift. | YES |
| 4 | `sehatly:openapi --check` | exit 0, 74 routes / 65 paths / 74 ops, `openapi.yaml 286466 bytes sha256 b9e30b49…` | **exit 0. Identical figures, identical sha256 `b9e30b49ea9ba82b74dd1528d4b3420c11d18c8681fd670016c206b5877ac077`, 286466 bytes.** | YES |
| 4a | `sehatly:enums --check` | exit 0, 69 ENUM columns, 319 values, 0 divergences | **exit 0, 69 ENUM columns, 319 values, 0 divergences, sha256 `ed87600a…`** | YES |
| 5 | `node tools/check-doc-links.mjs` | exit 0, 29 links / 19 repo paths / 53 api-path mentions / 19 dart blocks | **exit 0, identical counts.** Also `--expect-sections 14 docs/mobile-integration.md` → exit 0 | YES |
| 6 | SHA-256 `telemedicine_test.sql` | `AEFE2247E00F…`, 59 604 bytes | **`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, 59604 bytes**; `git diff --exit-code telemedicine_test.sql` → exit 0 | YES |
| 7 | `mobile/` absent, no `flutter:` constraint | absent; one pubspec, no `flutter:` key | **absent on disk; `git ls-files mobile` empty. Exactly one `pubspec.yaml` (790 B), `sdk: '>=3.13.0 <4.0.0'`, no `flutter:`/`flutter_test:`/`flutter_web_plugins:` key, no `sdk: flutter`, no flutter dependency. All 4 `flutter` occurrences are prose.** | YES |
| 8 | `vendor/bin/pint --test` | exit 1, 87 files, 307 violations | **exit 1. `<testsuite name="PHP CS Fixer" tests="87" assertions="307" failures="307" errors="0">`** | YES |
| 9 | `npm run types:check` / `test:unit` / `build` | exit 0 / 37 pass 0 skipped / exit 0 | **exit 0 / `tests 37, pass 37, fail 0, skipped 0` / exit 0, `✓ built`, 1 097.26 kB chunk** | YES |
| 10 | `dart analyze` / `dart test` | exit 0, 132 tests | **`No issues found!` exit 0 / `00:00 +132: All tests passed!` exit 0** (Dart 3.13.2) | YES |
| 11 | `composer run contract` | (not run by the report) | **exit 0** — enums UP TO DATE, openapi UP TO DATE, Contract suite **201 tests / 201 passed / 2669 assertions / 0 failures / 0 errors / 0 skipped** (reporter control applied to its JUnit) | — |
| 12 | 75-table parity, re-derived not copied | 75 = 75 | **75 `CREATE TABLE`, 75 DISTINCT table names, 0 duplicated names; 2 `CREATE OR REPLACE VIEW`; 15 `INSERT INTO`; 69 `ENUM(`; 3 `CHECK (`; 6 `UNIQUE KEY`; 1 `ALTER TABLE`** — all from a regex over the DDL byte stream | — |

### 1.1 Disagreements, stated rather than smoothed over

| # | Disagreement | Resolution |
|---|---|---|
| D1 | **JUnit file bytes and hash differ.** Report: 438 023 B, SHA-256 `394D5766…`. Mine: **438 027 B, `A5B8DBA0…`**. | **Not tampering, and not a defect.** The JUnit embeds per-test `time` attributes, so the bytes differ every run. The **counts** (1153 / 22510 / 0 / 0 / 0) reproduce exactly. Flagged so the hash mismatch is not misread. |
| D2 | **`FOREIGN KEY` count: 106 vs 105.** The report flagged this as unresolved and attributed it to a wrapped ENUM. | **RESOLVED, and the report's hypothesis was wrong.** The 106th occurrence is inside a SQL **comment on line 1158**: `-- [14] FOREIGN KEY TAMBAHAN (hindari ketergantungan silang saat CREATE)`. There are therefore **105 real FK clauses** and the verifier's parser is correct. This does not affect the table count. |
| D3 | **§3 quotes the notes registry as `(7 registered extra tables; ...)`.** My run prints `(7 registered extra tables; no deferred-constraint registry, retired in todo 18 when its last row resolved)`. | Cosmetic — the report elided with `...`. No defect. |
| D4 | **§8 reports `users = 7` after seeding.** | On a **clean** `migrate --seed` I measure **5** (3 `dokter`, 2 `pasien`). The report's 7 includes rows its own walkthrough created through the API. Not an overstatement, but 7 is walkthrough state, not seeded state. |
| D5 | **The report does not state that the NIK is stored in PLAINTEXT**, nor that `nik_cipher`/`nik_index` do not exist. §7.2 says only that the DDL still says `CHAR(16)` and no migration was written. | **Materially understated.** Measured directly: `pasien` holds 2 rows whose `nik` classifies as `PLAINTEXT-DIGITS(len=16)`, and the two columns the cipher is designed to use **do not exist in any database on the host**. See §3. |
| D6 | **`README.md` carries two stale measured figures** the report did not flag. | `README.md:142` `php artisan test # 1100 tests, 21630 assertions` — actual **1153 / 22510**. `README.md:145` `npm --prefix web run test:unit # 14 web unit tests` — actual **37**. The repository misdescribes its own numbers. |
| D7 | **F2's BLOCKER contradicts my test result.** F2 (`f3a43b7`) reports `errors=62 failures=0 skipped=0 assertions=21950` on "a private database created, migrated and seeded the documented way". I measured **1153/1153/0/0/22510 twice**, exit 0 both times, and validated each with the reporter control. | **The mechanism is real and I confirmed it statically**: `RbacSeeder` uses three bare `DB::table(...)->insert(...)` calls (`RbacSeeder.php:154,176,198`) with **no truncate and no upsert**, and there are **28** `->seed(RbacSeeder::class)` call sites across the Feature suite (F2 said eleven — it undercounted). The suite is therefore safe **only** because `RefreshDatabase` rolls each test back. **I could not reproduce 62 errors and do not claim F2 is wrong**; I report it as an **unresolved reproducibility risk on the single most load-bearing number in the project**, with a named mechanism. |

---

## 2. The fifteen success criteria

Verdicts: **MET** = satisfied with the cited evidence · **PARTIAL** = the criterion is conjunctive or
composite and a material part of it is absent or unproven · **NOT MET** · **UNVERIFIABLE**.

| # | Criterion (abbreviated) | **Verdict** | Backing artifact + command output |
|---|---|---|---|
| 1 | `verify-schema` exits 0 with **75 tables and 2 views**, DDL byte-unchanged | **MET** | `sehatly:verify-schema` → **exit 0**, `counts tables=75 views=2`, `Discrepancies: 7 (0 drift, 7 informational)`, `PASS — 75 tables, 2 views verified. Nothing was written.` SHA-256 `AEFE2247E00F…` 59604 B, `git diff --exit-code telemedicine_test.sql` **exit 0**. 75 re-derived independently from the DDL byte stream: **75 `CREATE TABLE`, 75 distinct names, 0 duplicates**, +2 views. The 7 informational are named in §1.3 and are not drift. |
| 2 | `php artisan test` fully green, **zero skipped**; every module has a happy- and a failure-path feature test | **MET** | JUnit (reporter control passed): **tests 1153, assertions 22510, failures 0, errors 0, SKIPPED 0**. Corroborated three ways: the `skipped="0"` **attribute is present**; **0** `<skipped>` elements across 1153 `<testcase>`; **0** `<failure>` and **0** `<error>`. `Unit 146 / Feature 1007`. Per-module happy+failure suites: `BookingTest` 35, `KonsultasiTest` 38, `RekamMedisTest` 49, `ResepTodo40Test` 25, `CheckoutTest` 35, `PasienProfileTest` 102, `AuthFlowTest` 54. `grep` for `markTestSkipped\|->skip(\|skipIf` across `tests/` returns **2 hits, both inside a `tests/TestCase.php` docblock** quoting a helper that was deleted — no live skip anywhere. |
| 3 | ~50 endpoints under `/api/v1`, envelope, `auth:sanctum` + `permission:`/`tipe:`, **each documented with a working curl recipe in its module summary** | **PARTIAL** | **API half is met and exceeds the plan:** 74 live `/api/v1` routes (plan said ~50). Envelope + guards proven by `ApiKernelTest` (16), `RbacMiddlewareTest` (18) and, live, `SanctumAuthConformanceTest` — **148 tests / 738 assertions inside the green Contract run**, driving 49 bearer operations to `401` **twice each** (98 real requests). **Documentation half FAILS:** across all 7 files in `docs/modules/` there are **2 occurrences of `curl`** and **17 total `/api/v1/` path mentions** for 74 endpoints. **Modules 4 and 5 have no summary at all** — `docs/modules/` contains only `README.md`, `modul-1-{ringkasan,auth,pasien,dokter}.md`, `modul-2-jadwal-booking.md`, `modul-3-konsultasi-rekam-medis.md`. The index itself records module 2 as `**belum lengkap**` with `**0**` endpoints. |
| 4 | All POST/PUT/PATCH validate through a `FormRequest`; return an `ApiResource`; no raw model serialised; no `kata_sandi_hash` or unmasked `nik` | **MET** | The `FormRequest` rule is enforced **at generation time** from the controller method signature: a violating route fails `sehatly:openapi`, which exits 0. Non-vacuity is proven in the same green run — `a mutated route table -- a POST with no FormRequest -- IS detected` (`OpenApiCommandTest`, 16 tests / **1817** assertions), plus a red/green drift proof on a hand-edited `openapi.yaml` and on `paths_table.dart`. Three exemptions exist, each with a written reason in `app/Support/OpenApi/RouteInventory.php` and a test asserting the list cannot grow. `kata_sandi_hash` appears in `UserResource`/`BookingResource` **docblocks only** — the 15 published `UserResource` keys do not include it — and absence is enforced live by `RedactionAbsenceTest` (8) and `RedactionGateTest` (8 / **745** assertions). NIK is masked at the surface: `PasienResource.php:93` `NikCipher::mask(...)`, same in `BookingResource`. |
| 5 | React client in `web/` hits the real API, **no mock data anywhere**, explicit loading/error/empty states, `types:check` and `build` clean | **MET** | `npm run types:check` → **exit 0**, no diagnostics. `npm run test:unit` → **`tests 37, pass 37, fail 0, skipped 0`**. `npm run build` → **exit 0**, `✓ 3363 modules transformed`, `✓ built`. Mock scan over **131** files in `web/src` for `MOCK_\|mockData\|fixtureData\|faker\|lorem ipsum\|PLACEHOLDER_DATA\|TODO_FAKE` → **NONE**. The 1 097.26 kB chunk exceeds Vite's 500 kB **advisory** — a warning, not a failure. |
| 6 | Pure-Dart package, `dart analyze` + `dart test` green, no Flutter SDK, **and a fresh integration test completing auth→booking→consultation→prescription→checkout against the live server using only the package's public API** | **PARTIAL** | **Library half fully met:** `dart analyze` → **`No issues found!`** exit 0 (Dart 3.13.2); `dart test` → **`00:00 +132: All tests passed!`** exit 0. Pure-Dart proven: `sdk: '>=3.13.0 <4.0.0'`, no `flutter:` key, no flutter dependency, `mobile/` absent — enforced as a permanent test by `NoFlutterMobileTest` (4 / 19), and README records that this guard was verified **red** first. **Proof half ABSENT:** `Test-Path packages\sehatly_api_client\test\integration` → **False**; the only subdirectory of `test/` is `support/`. The plan's wording is explicit that this test is what makes the handoff "provably correct", and that "if the mobile team would need to write anything the package does not already provide, this test fails and the gap is closed." It was not written, so **the self-sufficiency claim is unproven and the gap is not closed** — and §4.5/§4.6 below show two independent reasons it would fail today. |
| 7 | `mobile-integration.md` with all 12 required sections; every endpoint it names exists; every Dart block passes `dart format`; link checker resolves every path | **MET** | File exists, 75 851 B, **17 `## ` headings of which 14 are numbered sections 1–14** — exceeds 12. `node tools/check-doc-links.mjs` → **exit 0**, `OK -- 1 document(s); 29 links, 19 repo paths, 53 api-path mentions, 19 dart blocks, 0 external URLs skipped`. The `docs:check` invocation `--expect-sections 14` → **exit 0**. All 9 checker `note` lines are **declared** exceptions (`path declared absent mobile/`, `api group (not a route)`, `endpoint asserted ABSENT /api/v1/invoice/{}`) — the document declares where a named path does not exist rather than claiming it does. Sub-clause caveat: "every Dart block passes `dart format`" is verified **by the checker** (19 blocks) and I confirmed the checker's exit code; I did not re-format the 19 extracted blocks by hand. |
| 8 | `enums.json` generated from `information_schema`, byte-identical to a fresh export, **contains every ENUM**; the **14** `/api/v1/referensi/*` endpoints reachable **without** a token | **MET** | `sehatly:enums --check` → **exit 0**, `UP TO DATE -- docs/enums.json is byte-identical to a fresh export`, `live / DDL 69 ENUM columns, 319 values, 0 divergences`, sha256 `ed87600aba847dbf27c8dfb1a590a88e42efabfeefe56817e9d5e527a52c2be6`. Completeness independently confirmed: the DDL byte stream contains **69** `ENUM(` — matching the exporter's 69. Route count and spelling verified live: **14** `api/v1/referensi/*` routes, **0** `api/v1/referencia/*`; `docs/openapi.yaml` publishes `referensi` **28** times and `referencia` **0** times. Unauthenticated reachability proven live in the green run: `it answers all 14 routes to an unauthenticated caller` (14 tests / 84 assertions) and `it registers all 14 as GET, so a write is a 405 rather than a 403` (13 / 39). |
| 9 | `openapi.yaml` generated from the live route table, a schema for every registered route, **`composer contract` passes**, including "a route with no schema fails the build" | **MET** | `sehatly:openapi --check` → **exit 0**, `routes read 74 under api/v1`, `65 paths, 74 operations`, sha256 `b9e30b49…` / 286466 B. **`composer run contract` → exit 0**, running `sehatly:enums --check` + `sehatly:openapi --check` + the Contract suite, which I validated separately through the reporter control: **201 tests, 201 passed, 2669 assertions, 0 failures, 0 errors, 0 skipped**; 201 `<testcase>`, 0 `<skipped>`, 0 `<failure>`, 0 `<error>`; `ContractDivergenceTest` 9, `EnvelopeConformanceTest` 22, `SanctumAuthConformanceTest` 148, plus `SpecRouteParityTest` and `StatusReachabilityTest`. Bidirectional parity 74 ↔ 74, **0 missing in either direction**, asserted both in-process via a ghost route and out-of-band with a red transcript. The "no schema ⇒ build fails" rule is enforced at generation and proven non-vacuous by the mutated-route test quoted in criterion 4. |
| 10 | Realtime chat over Reverb, private per-consultation channel, de-duplicated against REST history, recovers missed messages on reconnect; Dart `RealtimeClient` same contract with `authHeaders` re-read per subscribe | **PARTIAL** | **Channel contract is tested:** `ConsultationChannelTest` 18 tests / 75 assertions; `routes/channels.php`; the event is `chat.pesan` on `konsultasi.{id}`. **But the de-dup and reconnect halves have no live evidence in the final pass** — §8 of the verification report states plainly that **no browser was driven and no realtime reconnect was exercised**. **And one documented property is affirmatively falsified** (§4.1 below): the API is coupled to the broadcaster, so the plan's own todo-54 QA scenario (c) — "stop `reverb:start` mid-walk and assert the REST chat path still works while realtime is degraded" — **fails**, and README's claim that `BROADCAST_CONNECTION=null` makes the REST path independent is false as written. |
| 11 | Drug-interaction warnings for **both orderings** of an `obat_interaksi` pair, clashes with active prior prescriptions, normalised allergy matches, surfaced in the UI **without silently blocking the doctor** | **MET** | `ObatInteraksiServiceTest` — **39 tests / 314 assertions**; its header states "BIDIRECTIONALITY IS NOT OPTIONAL… `obat_interaksi` stores an ORDERED pair… Every pair below is therefore asserted in BOTH orderings", and §10 is a mutation that **deletes the reverse-direction lookup and asserts the tests go red**. Live confirmation from the walkthrough: `GET /resep/1/cek-interaksi` returned a real `antar_item` warning at `tingkat: "berat"`, and a second run produced a `riwayat_resep` warning. "Without silently blocking" is evidenced by two screenshots — `t41-resep-override-gate.png` and `t41-resep-override-tercatat.png` — showing an override gate that records the doctor's decision. Documented limitation (the plan itself, line 193): the override is recordable only as free text in `resep.catatan_dokter`, as no `resep_interaksi` table and no acknowledgement column exist. |
| 12 | Every CRUD on `rekam_medis`, `resep` and sensitive patient data writes an `audit_log` row **via the global observer (no controller writes it)**, with **NIK and password redacted**; every `rekam_medis` read writes an `akses_rekam_medis_log` row | **MET** | **No controller writes the audit log:** grep for `AuditLogWriter\|audit_log` across `app/Http/Controllers/**` → **0 hits**. Registration is global: `AppServiceProvider.php:160` `configureAuditObservers()` → `AuditObserverRegistrar::registerAll()`. Redaction enforced live: `RedactionGateTest` 8 / **745** assertions over `['kata_sandi_hash','token_hash','kode_hash','qr_token','fcm_token']`; `RedactionAbsenceTest` 8 / 86 assertions plants a NIK fingerprint and asserts it appears in no form and that the **column name** appears nowhere. Access trail: `RekamMedisAccessLogger::log()` writes `AksesRekamMedisLog`, and the negative cases are proven too — *"a refusal that opened no record writes no log row"*, *"an id that was never there writes no log row"*. Suite: `AuditLoggingTest` 16, `AuditObserverRegistrationTest` 6 / **237**, `AuditRowTest` 9, `ArchitectureTest` 10, `HardDeleteTest` 6 / 110, `RekamMedisTest` 49. **Criterion 12's own text is fully met.** Separately, the plan's NIK-**at-rest** requirement is **NOT met** — see §3. |
| 13 | A duplicate payment webhook produces **exactly one state change**; PDP consent enforced before referral creation; medical records can only be soft-deleted | **MET** | **All three clauses hold.** (a) Duplicate webhook — see §4.5: `huntap()` is a `SELECT … FOR UPDATE` on a row that exists before any delivery, and `PaymentConcurrencyTest` proves both halves with two real MySQL connections (3 tests / 24 assertions, green). (b) PDP before referral: `SuratKeteranganService.php:255` calls `$this->consent->require($pasien->user, PdpConsent::JENIS_BERBAGI_DATA)`, and the `Rujukan` row is only inserted at **L615–624**, after that gate. `PdpConsent::versiTerbaru()` (L149) implements the plan's documented "read the **highest** `versi_dokumen` and honour its `disetujui`" rule, which is the only representation the DDL permits. `PdpNotificationTest` 20 / 580, `TokenAuditPdpTest` 6 / 85. (c) Soft-delete only: `RekamMedis` uses `RefusesHardDelete` (L9, L70), whose docblock states `delete()` and `forceDelete()` both throw; `HardDeleteTest` 6 / 110. **FINDING-48-race is REFUTED — see §4.5.** |
| 14 | `docs/` contains **the five module summaries**, `mobile-integration.md`, `enums.json`, `contract-conformance.md`, `openapi.yaml`, `schema-notes.md`, `migration-order.md`, `timezone-policy.md`, `pre-existing-defects.md`, `verification-report.md` | **PARTIAL** | **15 of the 17 named artefacts exist**, all substantial: `schema-notes.md` 147 338 B · `openapi.yaml` 286 466 B · `verification-report.md` 39 429 B · `mobile-integration.md` 75 851 B · `migration-order.md` 29 420 B · `contract-conformance.md` 23 864 B · `pre-existing-defects.md` 21 999 B · `timezone-policy.md` 15 532 B · `enums.json` 9 007 B. **Missing: two of the five module summaries** — there is no `modul-4-*` and no `modul-5-*` file. The index is honest about it: `docs/modules/README.md` lists only modules 1–3 and marks module 2 `**belum lengkap**` / `**0**` endpoints. |
| 15 | Pre-existing uncommitted work, including the untracked rebranding assets, **fully intact** against the todo-1 baseline; **no `mobile/` or Flutter project created** | **MET** | Baseline commit `b443f3b chore(api): baseline worktree and sync composer manifest with lock` exists and `1d87435` records its SHA. `2d3b3b first commit` → `b443f3b` are adjacent, so nothing was orphaned or rewritten; 176 commits total. `mobile/` absent on disk, `git ls-files mobile` empty; the only tracked `*mobile*` paths are `docs/mobile-integration.md` and `web/src/hooks/use-mobile.tsx`. Exactly one `pubspec.yaml` in 831 tracked files, no `flutter:` SDK constraint (§1 row 7). Enforced as a permanent test, `NoFlutterMobileTest` (4 / 19). |

### Tally

**MET 11 · PARTIAL 4 · NOT MET 0 · UNVERIFIABLE 0.**
PARTIAL: **3, 6, 10, 14.**

---

## 3. The `CHAR(16)` / todo-50 gap — CONFIRMED, and larger than reported

The brief lists this as known-open. It is confirmed by direct measurement, and the report understates
it. The gap is **not** "the column type is still `CHAR(16)` while the plan wants `TEXT`". It is that
**the NIK encryption never reaches the database at all, and plaintext national ID numbers are stored
in the clear.**

| Evidence | Measurement |
|---|---|
| The DDL | `telemedicine_test.sql:222` → `nik CHAR(16) NULL UNIQUE COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP'`; `:263` → `pasien_anggota_keluarga.nik CHAR(16) NULL` |
| The migration | `2026_10_01_000020_pasien_table.php:72` → `$table->char('nik', 16)->nullable()->unique()`. Its own docblock (L51–58) says it is "reproduced **exactly** as declared, without widening it" and that "Todo 50 owns the fix" |
| **Any migration that widens it** | **NONE.** Every `ALTER TABLE … MODIFY` in all migrations targets `diubah_at`; there is no `->change()` anywhere in `database/migrations/` |
| The **live** column type | `information_schema.COLUMNS`: `pasien.nik` `COLUMN_TYPE=char(16)`, `CHARACTER_MAXIMUM_LENGTH=16`, `COLUMN_KEY=UNI` — in **all 30+ databases on the host**, including `telemedisin_db_f1`, which I created, migrated and seeded myself. So this is **not** a stale-database artifact |
| The columns the cipher is designed to use | `NikCipher::COL_PAYLOAD = 'nik_cipher'`, `COL_INDEX = 'nik_index'`. Queried across every schema on the host: **these columns do not exist in any database** |
| Does the ciphertext fit? | `NikCipher::PAYLOAD_LENGTH = 6 + 16 + 32 + 12 = 66` bytes → **88 base64 characters**. `CHAR(16)` cannot hold it, exactly as the class's own docblock says |
| **What is actually stored** | `SELECT` over `pasien` in `telemedisin_db` and `telemedisin_db_f1`: **2 rows with a non-null `nik`, both classify as `PLAINTEXT-DIGITS(len=16)`** — raw 16-digit national ID numbers, in the clear, in the column the DDL itself annotates as mandatory-to-encrypt under UU PDP |
| Is there a write path? | `AnggotaKeluargaRequest.php:128` → `'nik' => ['nullable','string','digits:16']`, written to `pasien_anggota_keluarga.nik` (`CHAR(16)`), **in plaintext**. No `pasien` write path exists (`RegisterRequest` and the profile request have no `nik` rule) |
| The read path | `PasienResource.php:93` → `NikCipher::mask($this->resource->nik_cipher, $this->resource->nik)`. `nik_cipher` is absent, so it reads `null` and the **plaintext** column is what gets masked |

**Verdict: NOT MET, and it is a scope violation, not only a column-type violation.** The plan states
NIK encryption as a **Must-have** (line 31) and as a **Must-NOT-have guardrail** (line 58: "NEVER
encrypt a value into a column narrower than its ciphertext… Ciphertext goes in a `TEXT` column; a
fixed-width 16-char HMAC carries the unique index. See todo 50."), and todo 50's acceptance criterion
requires it. The cipher itself is a complete, well-tested, 23-test/2225-assertion library. **It is
not wired to persistence.** `task-50-sehatly.md` is honest about this in its body — §2.1 is headed
*"The migration somebody has to author"*, §2.2 states "No migration, no ALTER, no index, no
constraint", and L378 states *"This is a behavioural no-op today, which is the point: `nik_cipher`
does not exist"* — so this is a **handoff that was never executed**, not a false claim. The gap is
recorded as open in `verification-report.md` §7.2 and in three `open-finding-for-F1` ledger entries.

**F2 disagrees on severity.** `F2-code-quality-review.md` §6b grades this **MINOR** and *"latent
rather than live"*, on the grounds that *"no endpoint writes a NIK at all, so nothing is truncated and
no plaintext NIK is at rest."* **The first half of that premise does not hold**: the family-member
endpoint accepts and writes a 16-digit NIK, and **two `pasien` rows do hold plaintext NIKs right
now** in the live development database. F1 grades it as a live compliance gap.

---

## 4. The four items the brief requires be audited as open

### 4.1 `WALK-54-BROADCASTER` — the API is coupled to the broadcaster — **CONFIRMED, high**

`verification-report.md` §6.1, corroborated by the `open-finding-for-F1` ledger entry, which names
exactly what it falsifies. I did not re-run the server experiment (it requires a live Reverb pair and
a mutated broadcast path); I confirm the finding stands as reported and that its consequences for
criterion 10 are real. `phpunit.xml` sets `BROADCAST_CONNECTION=null`, so **no test can ever exercise
this failure path** — which is why it survived 54 todos.

### 4.2 Conformance gap — **CONFIRMED at 57/74, 403 coverage 0 — and worse than stated**

`docs/contract-conformance.md` headline table, read verbatim:

```
| Live responses validated against their published $ref | 57 / 74 (77%) |
| Live responses that fail that validation              | 17 / 74 (23%) |
| $ref           | 57 + 17 = 74   (arithmetic checks out) |
```

`403` coverage is **0** across all 49 bearer operations, and `429` coverage is **0** across 3.
"Success `2xx` body" is uncovered for **all 49** bearer operations. The document names the largest
gap itself: *"no authenticated success path in this suite at all."* Seven findings are documented, and
the **document is the wrong side in all seven** — the fix belongs in the generator, which todo 49 was
forbidden to edit. `ContractDivergenceTest` holds each one as a tripwire: green while the defect
exists, red the moment the generator is corrected.

**I confirmed the mechanism behind the 0.** Across all 12 seeder files, the count of **executable**
`user_roles` references is **0**. `DatabaseSeeder.php:102` says in its own docblock: *"The 26th owned
table, `user_roles`, is written by nobody in this tree."* `RbacSeeder.php:73`: *"## It writes no
`user_roles` rows."* And empirically, after my own clean `migrate --seed`: **`user_roles` = 0 rows**.
The gap is **not a missing test; it is a missing writer**, exactly as the report states. This is a
correctly-diagnosed, honestly-disclosed gap — and it is a real hole in criterion 3's permission
coverage and in criterion 6's integration-test precondition.

### 4.3 The six defects `verification-report.md` §6 found — do any invalidate a success criterion?

| § | Defect | Invalidates a criterion? |
|---|---|---|
| 6.1 | Broadcaster coupling: `POST /konsultasi/mulai` → **HTTP 500** with Reverb down; the write is **already committed**; the 500 leaves **no log entry** | **Yes — criterion 10**, and it falsifies the plan's own todo-54 QA scenario (c) and a README claim. Also means the plan's "realtime is degraded but the API works" property is false. |
| 6.2 | `promo/validasi` computes a discount it **never applies**: answered `valid:true, nilai_diskon 34650.00, total 326850.00`, yet `invoice.diskon` stayed `0.00`, `invoice.total` stayed `361500.00`, `promo_redemption` held **0** rows, and the subsequent payment **charged the undiscounted 361 500.00** | **No numbered criterion**, but it is money-affecting and reachable by any client that trusts the endpoint's own success payload. It makes criterion 3's published contract a lie for this operation. |
| 6.3 | `notifikasi` has **no writer**: `NotificationService` is `final` with five producers and **no caller anywhere in `app/`** | **No numbered criterion directly**; it makes the notification feature inert and weakens criterion 5 (the client hits a real endpoint that can never return anything the application wrote). **I confirmed it independently**: the only non-docblock reference to `NotificationService` in all of `app/` is its own `final class` declaration. |
| 6.4 | **No shipped seeder writes `user_roles`**, so every fixture account 403s on every `permission:`-guarded route | **Yes — it is the mechanism behind §4.2's 403 coverage of 0**, which weakens criterion 3's guard claim. **Independently confirmed** (§4.2). |
| 6.5 | The booking's and the order's **invoice id are never exposed**, and there is no `GET /api/v1/invoice/{id}` | **Yes — it is a direct cause of criterion 6 being PARTIAL.** A client following the published API cannot learn the invoice id, so it cannot pay or discount, so the required integration test could not pass if written. `check-doc-links.mjs` independently shows the doc declares `/api/v1/invoice/{}` as **asserted ABSENT** at `mobile-integration.md:1341` and `:1609`. |
| 6.6 | A **freshly seeded database cannot support the plan's own walkthrough**, and there is no API to create the rows it needs | **Yes — a direct cause of criterion 6 being PARTIAL**, and it degrades criterion 5 as a demonstrable property. **I reproduced it independently and it is starker than reported.** |

**My independent reproduction of §6.6** — fresh `telemedisin_db_f1`, `migrate --seed --force`, exit 0:

```
dokter_jadwal      rows=0        <- no schedule -> no slots -> booking impossible
apotek_stok        rows=0        <- no stock to sell
master_promo       rows=0        <- no promo to apply
faskes                 =2        tipe: klinik=1, laboratorium=1   <- NO tipe='apotek'
users                   =5        dokter=3, pasien=2            <- NO apoteker account
user_roles              =0        <- no RBAC grant for anyone
notifikasi              =0
roles                   =5        (pasien, dokter, apoteker, admin, superadmin exist as ROLES
                                    but no ACCOUNT of any of those types can be obtained)
```

There is no `POST` route for `dokter_jadwal`, `master_promo`, `faskes` or `apotek_stok`, and no
role-assignment endpoint. **On a fresh install, booking, prescription fulfilment, promo application
and pharmacist verification are unreachable through the product surface — not merely unseeded.**

**None of the six invalidates criterion 1 or criterion 2.** Schema parity and the test suite were both
independently re-verified green, twice. Three of the six (6.1, 6.5, 6.6) are direct causes of a
PARTIAL verdict elsewhere.

---

## 5. The fourth item: **`FINDING-48-race` — REFUTED**

The brief lists this as known-open and states the concurrent case is "unproven and unfixed". **I read
the code and ran the tests. That is wrong, and I say so plainly: the finding is refuted.**

The ledger entry (`open-finding-for-F1`, id `FINDING-48-race`) claims:

> *"A row-level lock on a query that matches NO row locks nothing, so two CONCURRENT first deliveries
> of the same event can both find no row and both proceed to **insert**."*

and gives as its reason: *"the suite has no such test."*

**Refutation 1 — the claimed failure mode is not expressible in this code.** `huntap()`
(`app/Services/Payment/PaymentService.php:441–454`) is:

```php
$pembayaran = Pembayaran::query()
    ->where('gateway', $gateway)
    ->where('nomor_referensi', $referensi)
    ->lockForUpdate()
    ->first();

if ($pembayaran === null) {
    throw (new ModelNotFoundException)->setModel(Pembayaran::class, [$referensi]);
}
return $pembayaran;
```

It is a **lookup that throws on a miss**. It is not a check-then-insert idempotency guard, and
`terimaWebhook()` contains **no insert at all** — the only `new Pembayaran` in the flow is in
`mulai()` at L266–289, the payment-**initiation** path, which commits the row *before* any gateway
delivery can exist. So "both find no row and both proceed to insert" describes code that is not
there. The premise "a lock matching no row locks nothing" is true of `SELECT … FOR UPDATE` in
general, but **`huntap` never proceeds past a no-row match** — that branch is a 404.

**Refutation 2 — the missing index is not load-bearing for correctness here.** The factual half of the
claim is **TRUE** and I confirmed it: `information_schema.STATISTICS` on `pembayaran` shows only
`PRIMARY`, `idx_bayar_status (status, dibayar_at)`, and two FK indexes — **no unique index on
`(gateway, nomor_referensi)`, and no index on `nomor_referensi` at all.** But `lockForUpdate()` on an
**existing** row is an InnoDB **current read**: it blocks on a held lock and then re-reads the **latest
committed** version, which is exactly the property the guard needs. Index absence costs a full scan
and gap locks — a **throughput** cost, not a correctness one.

**Refutation 3 — the suite HAS such a test, and it proves the exact property the claim calls
unproven.** `tests/Feature/Payment/PaymentConcurrencyTest.php`, **3 tests / 24 assertions, green in my
run** (`PaymentConcurrencyTest tests=3 assertions=24 errors=0 failures=0`), using two real
interleaved MySQL connections on separate PDO handles with `innodb_lock_wait_timeout = 1` and
`REPEATABLE READ` asserted on both:

1. `the real settlement path BLOCKS on the pembayaran row lock a concurrent delivery holds` — runs the
   **real, unmodified** `PaymentService::terimaWebhook()` on the second connection, asserts MySQL
   error **1205**, and asserts connection B issued the `SELECT … FOR UPDATE` and **no** `update` on
   `pembayaran` before colliding. The test carries a note that an earlier version of that assertion
   **survived deleting `lockForUpdate()` entirely** and was rewritten to have teeth.
2. `a NON-locking read of the same row does NOT wait, which is why the FOR UPDATE is the defence` —
   the control: a plain consistent read returns `pending` instantly while A holds the lock, so a plain
   `first()` **would** have double-written.
3. `the row lock is a CURRENT read: a blocked delivery wakes up seeing the settled status` — asserts
   B's `FOR UPDATE` returns **`berhasil`** after A commits. The test names this *"the single property
   the idempotency guard depends on: a second delivery that blocked must re-read the settled row."*

The method's own docblock (L414–431) documents the mechanism line by line, and the class docblock
(L85–96) records the one real residual in as many words: *"That is a throughput cost, not a
correctness one… A future migration that adds `INDEX (gateway, nomor_referencia)` would turn the scan
into a point probe… it is NOT made here, because the plan forbids it."*

**Serial case** additionally proven live by the walkthrough: `duplicate:false` then `duplicate:true`.

**Corroboration.** F2 reached the same conclusion independently: *"PaymentService::huntap() is NOT
defective — the row exists before any webhook, FOR UPDATE is a current read, and the no-index full
scan locks MORE rows than needed, so exclusion is stronger than documented."*

**One genuinely unguarded race, disclosed as an observation and NOT counted against criterion 13.**
`mulai()` (L254–264) checks for an existing `pending` row with a **plain consistent read inside a
transaction, with no lock on the invoice**, and `pembayaran` has no uniqueness on
`(invoice_id, status)`. Two concurrent `POST /invoice/{id}/bayar` calls could therefore both pass the
check and create two `pending` rows with different references. **I did not run this and do not claim
it reproduces.** F2 identified the same line independently. It is outside `FINDING-48-race` and
outside criterion 13 as written, and it is recorded here so it is not lost.

---

## 6. Process audit

### 6.1 DDL integrity — **MET**

SHA-256 `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, 59 604 bytes,
`git diff --exit-code telemedicine_test.sql` exit 0, at HEAD. The hash equals the value the plan
itself states, so the file was never modified at any point in the plan's 176-commit history, not
merely unchanged at HEAD. `migrate:fresh`, `migrate:rollback` and the `sehatly` database were not
touched by this audit. **This is the cleanest process result in the project and it is fully earned.**

### 6.2 Zero skipped tests — **MET**

`skipped="0"` with the attribute **present**; **0** `<skipped>` elements across 1153 `<testcase>`;
**0** `<failure>`; **0** `<error>`. Independently corroborated on the Contract suite: 201 tests,
`skipped="0"`, 0 `<skipped>`. The `markTestSkipped` census returns 2 hits, both inside a
`tests/TestCase.php` docblock quoting a deleted helper. **Zero is zero.**

### 6.3 Token hygiene — **CLEAN. No finding.**

Byte-level scan of **all 831 tracked files**. Any byte `>= 0x80` in a code file is a defect; em-dashes
in Markdown prose are not.

- **Code files containing any non-ASCII byte: ZERO.** Across `.php`, `.ts`, `.tsx`, `.js`, `.mjs`,
  `.cjs`, `.dart`, `.sql`, `.json`, `.yml`, `.xml`, `.css`.
- **Mojibake families** (`Ã`/`Â`/`â€`/`ï¿½`/U+FFFD) in code: **0**.
- **Cyrillic/Greek homoglyphs** in code: **0** — the exact class of defect that caused the 48-failure
  `referencia`/`referensi` incident.

The `referencia` spellings that exist are all pure-ASCII prose describing the historical typo:
stale docblocks in `ReferensiController.php:21,74,102` and thirteen `Referensi\*Resource.php` files
(e.g. *"for `GET /api/v1/referencia/agama`"* — the live route is `referensi`), plus
`PaymentService.php:94`, plus evidence and ledger text. **No identifier is affected.** Critically, the
**live route prefix is the correct `referensi`**: 14 `api/v1/referensi/*` routes, 0
`api/v1/referencia/*`; `docs/openapi.yaml` publishes `referensi` 28× and `referencia` 0×. The
corruption is fully repaired in code; only comment text still describes the old spelling. That is a
cosmetic doc-accuracy defect, not a functional one, and I do not inflate it.

**Outside code, and not mine to fix:** `.omo/plans/sehatly-telemedicine-platform.md` carries real
mojibake — `ΓÇö` at L154/L158, `â€"` at L1651/L1668/L1687. Orchestrator-owned prose, pre-existing.

### 6.4 Evidence integrity — the evidence base is honest; four process gaps

**Existence.** 74 files in `.omo/evidence/`: **59 Markdown + 15 PNG screenshots**. No file is empty,
truncated, or a `TODO`/placeholder stub; the smallest is 3 321 B and it contains real results.

**Against the brief's overstatement tests — what I found, and what I deliberately did NOT call a
finding:**

| Test | Result |
|---|---|
| Impossible test counts (>1153 tests, >22510 assertions) | **NONE FOUND** across all 59 files |
| Out-of-window timestamps | **35 bare dates outside the window, ALL FALSE POSITIVES** — every one is a frozen-clock test fixture (`Carbon::setTestNow` at 2026-03-11, 2026-12-07) or a **deliberately invalid** date used to prove date validation (`2026-13-45`, `2026-02-30` at `task-gap-slot-routes.md:245,247`). This is evidence of rigour, not of fabrication |
| Green prose beside a red summary | **NONE FOUND.** Every hit is a legitimate red→green narrative: mutation harnesses (`task-38`: *"ALL MUTATIONS KILLED, AND THE SUITE IS GREEN AFTER THE REVERT"* beside a deliberate `719/711, 8 failed`), controls (`task-53`: `MUTATED exit=1`), zero-match controls, and honest self-corrections (`task-47:127`: *"This todo's own files were NOT Pint-clean, and the earlier draft of this file said they were. Both the count and the framing were wrong"*) |
| The 7 framework tables counted as drift | **NONE FOUND.** ~90 `Discrepancies:` lines exist; the `0` ones are **scoped** `--tables=<N names>` runs from Waves 1–2, and the executors distinguished the two correctly. `task-18:868` states outright: *"**`Discrepancies: 0` in the brief is arithmetically impossible**"* |
| Conformance claimed clean / 403 claimed covered | **NONE FOUND.** The single hit is `task-54:299` reporting it as **NO**. `task-49` and `contract-conformance.md` both self-report the 0s |
| pint / `dart format` claimed clean | ~20 files print `{"tool":"pint","result":"passed"}`. **I checked the dates and these are NOT overstatements**: `task-4`, `task-6`, `task-7` and `task-21` were all committed **2026-09-27**, and the pint debt lands at `f4327d3` on **2026-09-28 07:44**. The claims were true when made. Most are also explicitly scoped (*"scoped to the 22 files this todo owns and never repo-wide"*), and `task-47` and `task-51b` both honestly report the repo-wide failure count at their time. **I decline to call a true historical claim an overstatement** |
| NIK claimed encrypted at rest | `task-50`'s **body is honest** — §2.1 is headed *"The migration somebody has to author"*, §2.2 says "No migration, no ALTER, no index, no constraint", L378 says *"This is a behavioural no-op today… `nik_cipher` does not exist"*. **But its headline (L1) and its recorded commit message (L596, `feat(security): encrypt NIK with an HMAC blind index and mask it at the API`) claim encryption that does not exist.** That is the one overstatement I do report, and it is confined to the title and commit line |

**Real process gaps:**

1. **Four todos have no evidence file: 23, 25, 28, 29.** The plan states *"Evidence: `.omo/evidence/`
   … One file per todo: `.omo/evidence/task-<N>-sehatly.md`."* 50 of 54 comply. 23 = web auth/OTP
   screens, 25 = Module 1 summary, 28 = web calendar, 29 = Dart realtime/push — all deliverable
   artefacts, and all four are React/Dart work that **no browser was ever driven against**.
2. **Todo 40 has no ledger entry** (96 entries, 81 `task-completed`, 53 distinct todo numbers).
3. **`task-40-sehatly.md` is the weakest evidence file in the set**: 34 lines, no command-output
   blocks, no red/green transcript, no mutation-harness detail, and it claims *"Baseline
   `php artisan test`: 872/872 PASSED, 14365 assertions"* and *"7 mutations killed"* that cannot be
   re-derived from the file. Not proven false — **unverifiable from the artefact**, which for an
   evidence file is the defect.
4. **The three `open-finding-for-F1` findings that todo 54 recorded are still open**, and one
   (`FINDING-48-race`) is wrong (§5). The mechanism for surfacing them worked; the content of one
   did not survive re-reading.

---

## 7. Findings **not** covered by any of the fifteen criteria, added by this audit

1. **NIK encryption absent from the persistence path; 2 `pasien` rows hold plaintext NIKs** (§3).
   Violates a Must-have (line 31), a guardrail (line 58) and todo 50's acceptance criterion. This is
   a live UU PDP compliance gap, not a documentation gap.
2. **`RbacSeeder` is not idempotent and is re-seeded 28 times** — three bare `insert()` calls, no
   truncate, no upsert. The suite is safe only because `RefreshDatabase` rolls each test back. This is
   the mechanism behind F2's BLOCKER and is an **unresolved reproducibility risk on the project's
   single most load-bearing number** (§1.1 D7). I reproduced 1153/1153 twice and do not claim F2 is
   wrong, but the number is not order-independent by construction.
3. **`README.md` misdescribes the project's own measurements** — `1100 tests, 21630 assertions`
   (actual 1153 / 22510) and `14 web unit tests` (actual 37) — and its CI section lists
   `pint --test` as a **gating job** that exits 1 on 87 files. `.github/workflows/contract.yml` cannot
   be green.
4. **Three written `FormRequest` exemptions** to the DoD (`POST /webhook/payment/{gateway}`,
   `PUT /notifikasi/{id}/baca`, `PUT /notifikasi/baca-semua`), each with a recorded reason in
   `RouteInventory.php` and a test asserting the list cannot grow. Disclosed and bounded — I record it
   as a deviation, not a hole.
5. **Migration filenames are dated `2026_10_01_*`**, i.e. after the last commit. This is a naming
   convention mandated by the plan (line 240), not a false timestamp. Noted only so it is not
   misread as one.

---

## 8. VERDICT

# FAIL

**Not PASS-WITH-FINDINGS.** Four of the fifteen criteria are not met, and one plan **Must-have** is
absent with plaintext national identity data at rest. A pass here would not be earned, and the brief
is explicit that an unearned pass is worse than a fail.

**What is genuinely, verifiably solid — and I will not take any of it back:**

- **Criterion 1** is fully met and the cleanest result in the project. DDL byte-identical
  (`AEFE2247…`, 59 604 B) across 176 commits; 75 tables + 2 views, **0 drift**, exit 0, and the 75
  re-derived independently from the DDL byte stream — 75 statements, 75 distinct names, 0 duplicates.
- **Criterion 2** is fully met and **reproduced twice**: 1153/1153, 0 failures, 0 errors,
  **0 skipped**, 22 510 assertions, validated through an asserted reporter control and a second
  independent child-element census. The 201-test Contract suite is green too.
- **Criteria 4, 5, 7, 8, 9, 11, 12, 13, 15** are all met, each with a cited command or a cited test.
  Criterion 9's `composer contract` exits 0 and I re-validated the Contract suite myself.
- **Token hygiene is perfect**: zero non-ASCII bytes in any of 831 tracked code files, zero mojibake,
  zero homoglyphs — the 48-failure `referencia` class is fully repaired, and the live routes use the
  correct spelling.
- **The evidence culture is genuinely excellent and I say so without qualification.** Not one
  impossible count, not one fabricated timestamp, not one green-beside-red overstatement across 59
  files. `contract-conformance.md` volunteers that it validates only **57/74** and that it has **zero**
  authenticated success coverage. `verification-report.md` opens by declaring **its own todo's bar NOT
  met**. Three executors documented the falsification of their own earlier claims (A.18, A.20, A.25).
  That is a team that told the truth about itself, and it is why this audit could be short.

**Why FAIL, specifically:**

| # | Reason | Weight |
|---|---|---|
| 1 | **NIK encryption never reaches the database.** `pasien.nik` is `char(16)` in all 30+ databases including one I built; `nik_cipher`/`nik_index` do not exist anywhere; **2 rows hold plaintext 16-digit national IDs**; the family-member endpoint writes a raw `digits:16` NIK to `CHAR(16)`. The plan's Must-have, its guardrail at line 58, and todo 50's acceptance criterion are all unmet. The DDL column is annotated `'WAJIB dienkripsi … sesuai UU PDP'`. | **Compliance, live, patient data** |
| 2 | **The mobile-handoff proof does not exist.** `test/integration/` is absent. The plan required it precisely so that "if the mobile team would need to write anything the package does not already provide, this test fails and the gap is closed." It was not written, and two independently-measured defects (§6.5, §6.6) mean it would fail today. The "self-sufficient and **provably correct**" claim is unsupported. | **Named acceptance criterion, absent** |
| 3 | **A fresh install cannot execute the core product flow**, and no API can create the missing rows. Booking, prescription fulfilment, promo and pharmacist verification are unreachable on a clean database. | **Scope of deliverable** |
| 4 | **A money-affecting defect is live**: `promo/validasi` promises a 34 650.00 discount, the discount is never persisted, and the patient is charged the undiscounted 361 500.00. | **Correctness / money** |
| 5 | **A write endpoint 500s when Reverb is down, non-atomically, with no log entry** — falsifying the plan's own QA scenario and a README claim. `phpunit.xml` sets `BROADCAST_CONNECTION=null`, so no test can ever catch it. | **Availability, untestable as configured** |
| 6 | **Two of five module summaries do not exist**, the third declares itself incomplete with 0 endpoints, and there are **2 curl blocks for 74 endpoints** against a criterion demanding a working recipe for each. | **Named acceptance criterion, absent** |
| 7 | **Realtime dedup/reconnect is unproven** — no browser was driven in the final pass. | **Named acceptance criterion, unproven** |
| 8 | **The acceptance number's reproducibility is contested** and the mechanism is confirmed: a non-idempotent seeder re-seeded 28 times, safe only by transaction rollback. I reproduced green twice; F2 measured 62 errors. Not resolved by this audit. | **Trust in every number above** |
| 9 | **`pint --test` exits 1 on 87 files / 307 violations** while `README.md`'s CI lists it as a **gating job** — the repository's declared CI cannot be green. `.omo/evidence/` is missing 4 of 54 files, the ledger is missing todo 40, and `README.md` misstates two of its own measurements. | **Process, self-declared gates** |

**None of these are reasons to distrust the engineering.** The schema work, the contract generation,
the concurrency work, the audit trail, the drift checks and the verification culture are all
demonstrably good. The failures are of **completeness and of live compliance**, concentrated in
Module 5's business logic, the mobile-handoff proof, the documentation, and the NIK.

**Recommendation: FAIL, with a re-gate scoped to items 1–7.** Items 1, 2, 4 and 6 are each a
single-track piece of work with a named acceptance criterion. Item 1 is the one I would not defer:
it is the only finding where patient identity data is stored in the clear against an explicit
instruction in the contract DDL.

---

## 9. Reproduction

```powershell
$php = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe"
$env:DB_DATABASE = "telemedisin_db_f1"      # private; phpunit.xml untouched

# create + seed the private database (never migrate:fresh, never telemedisin_db_test, never sehatly)
& $php "…\f1_mkdb.php" telemedisin_db_f1
& $php artisan migrate --seed --force                       # exit 0

# the suite, with the reporter control (JUnit, not the intercepted stdout)
& $php vendor\bin\pest --colors=never --log-junit="$env:TEMP\f1-junit.xml"
node "…\parse-junit.cjs" "$env:TEMP\f1-junit.xml"          # asserts key presence BEFORE reading counts

# the mechanical gates
(& $php artisan route:list --path=api/v1 --json | ConvertFrom-Json).Count      # 74
& $php artisan sehatly:verify-schema                                          # exit 0, 75 tables, 2 views
& $php artisan sehatly:openapi --check                                         # exit 0
& $php artisan sehatly:enums --check                                           # exit 0
node tools/check-doc-links.mjs                                                 # exit 0
(Get-FileHash telemedicine_test.sql -Algorithm SHA256).Hash                    # AEFE2247E00F…
git diff --exit-code telemedicine_test.sql                                      # exit 0
Test-Path mobile                                                               # False

# the two findings this audit resolved
node "…\tokenscan.cjs" .          # 831 tracked files -> 0 non-ASCII bytes in any CODE file
node "…\evidence.cjs" .          # 59 evidence files -> 0 impossible counts, 0 fabricated timestamps
```

**Ledger entry appended** to `.omo/start-work/ledger.jsonl` — one line, built with
`JSON.stringify`, validated as parsing **before** the write, append-only via `fs.appendFileSync`,
never a redirect target. Measured effect: **654 948 → 673 612 bytes, 97 → 98 non-empty lines, 0
parse failures before and after, and the byte-for-byte prefix of all prior entries verified
identical** (proving the append rewrote nothing). The append was also guarded: it refuses to run if
the file does not end in a newline, or if any existing line fails to parse.
