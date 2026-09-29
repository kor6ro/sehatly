# Task 36 — `docs/mobile-integration.md`, plus a link checker that can fail

**Status: complete.** Every acceptance criterion in the plan's todo 36 is met.
Two things are left exactly as they were found and are reported below as findings
rather than fixed, because fixing either one is out of scope for this todo.

**Commits:** `5c69f90`, `6b7b25b`, `61b6485`, `b24c04c`

---

## 1. The load-bearing point, and how the document leads with it

This repository ships **no Flutter app**. There is no `mobile/` directory and no
`flutter:` SDK constraint in any `pubspec.yaml`. The mobile entry point is the
**pure-Dart** package `packages/sehatly_api_client`, whose `pubspec.yaml` carries
one runtime dependency (`dio`) and no `flutter:` key at all.

The guide says this in its **first screen**, before the `pubspec.yaml` snippet
and before the installation section, and it explains the consequence: the package
resolves, analyzes and tests with a bare `dart` binary, so a CI runner without
Flutter can verify it, and the three things that genuinely need a platform — a
hardware-backed keystore, a push token, a live socket — are **interfaces** in the
package and implementations in the consuming app.

`docs/mobile-integration.md:10-36` is the "Read this before you install anything"
section. It is not a disclaimer bolted onto the end; it is the first thing a
reader sees, because a guide that says only *"add this to your pubspec"* sends
every integrator into a Flutter install they do not need.

The guardrail is automated: `tests/Unit/NoFlutterMobileTest.php` walks every
`pubspec.yaml` and fails the PHP suite on a `flutter:` constraint, a
`flutter` dependency, or a `mobile/` directory. It was re-run after this todo
(4 passed, 19 assertions, exit 0) and was not weakened.

---

## 2. The 14 sections, and the plan's disagreement with itself

The plan disagrees with itself about how many sections this document has. That is
worth stating plainly rather than quietly picking one number.

| source | count | wording |
| --- | --- | --- |
| todo 36 "What to do / Must NOT do" | **14** | `(1)` through `(14)`, each with its required content spelled out |
| todo 36 "Acceptance criteria" | **14** | "`docs/mobile-integration.md` exists with all 14 numbered sections" |
| the plan's final Definition-of-Done list, item 7 | **12** | "exists with all 12 required sections" |
| the executor brief | **12** | a suggested 12-topic shape, which differs from the plan's 12 in composition |

I followed the **todo body (14)**, because it is the only enumeration that names
what each section must contain, and because every one of the brief's 12 topics is
covered inside those 14. The brief's "installation and pubspec", "base URL and
environment config" and "Dio client setup with interceptors" are subsections 1.2,
1.4 and 2.4 of the plan's sections 1 and 2 rather than standalone sections; the
brief's "testing against a local server" is covered by the `10.0.2.2` trap in 1.3
and by the `flutter run --dart-define` command beside it.

| # | heading |
| --- | --- |
| 1 | Prerequisites, environment and installation |
| 2 | Auth lifecycle |
| 3 | Token storage |
| 4 | Error catalogue |
| 5 | Pagination |
| 6 | Date, time and timezone |
| 7 | File uploads |
| 8 | Masked and sensitive fields |
| 9 | Realtime chat |
| 10 | Push notifications |
| 11 | **Contoh integrasi dengan GetX** |
| 12 | Server-side rules the client must NOT re-implement |
| 13 | Endpoint cookbook |
| 14 | Troubleshooting |

`grep -c "^| " docs/mobile-integration.md` = **160** table rows, against the plan's
floor of 45. The document is 1646 lines.

The document is in English, with the plan's one required Indonesian heading
preserved verbatim and Indonesian kept where it is a wire value (endpoint names,
field names, server messages).

---

## 3. `dart analyze` and `dart format` — the real output, verbatim

`dart` is not on `PATH`; it is at
`C:\Users\axioo\AppData\Local\Microsoft\WinGet\Packages\Google.DartSDK_Microsoft.Winget.Source_8wekyb3d8bbwe\dart-sdk\bin\dart.exe`.
Both commands were **run**, not assumed.

### 3.1 `dart analyze` — exit 0

```text
Dart SDK version: 3.13.2 (stable) (Tue Aug 25 01:01:12 2026 -0700) on "windows_x64"
```

```console
PS> dart analyze
Analyzing sehatly_api_client...
No issues found!
ANALYZE_EXIT=0
```

### 3.2 `dart format --output=none --set-exit-if-changed .` — exit 1, and why

**BASELINE, before this todo changed anything:**

```console
PS> dart format --output=none --set-exit-if-changed .
Changed lib\src\generated\enums.dart
Changed lib\src\generated\paths_table.dart
Changed lib\src\generated\request_bodies.dart
Changed test\interceptor_test.dart
Changed tool\audit_wire_fields.dart
Formatted 40 files (5 changed) in 0.20 seconds.
FORMAT_EXIT=1
```

Five files. This todo formatted the **two hand-written** ones
(`test/interceptor_test.dart`, `tool/audit_wire_fields.dart` — 130 insertions,
113 deletions, purely line-wrapping, semantics-preserving) and committed that
separately as `5c69f90`. Re-verified: `dart analyze` exit 0, `dart test` 132
passed exit 0.

**FINAL, after this todo:**

```console
PS> dart format --output=none --set-exit-if-changed .
Changed lib\src\generated\enums.dart
Changed lib\src\generated\paths_table.dart
Changed lib\src\generated\request_bodies.dart
Formatted 40 files (3 changed) in 0.26 seconds.
FORMAT_EXIT=1
```

**The three remaining files are not fixable, and the reason is structural.** All
three are written by `php artisan sehatly:openapi` and compared **byte-for-byte
by SHA-256** by `php artisan sehatly:openapi --check`. Reformatting them fails the
drift check, which is a build gate. The generator emits one collection element
per line for byte determinism rather than reproducing the formatter's cost model,
and the repository `README.md` already declares this gap in its "Known gaps"
section, naming `dart analyze` as the enforced gate instead. I did not weaken the
drift check to make a formatter happy.

`dart test` after the reformat: **132 passed, exit 0**.

---

## 4. The link checker

**Path: `tools/check-doc-links.mjs`** (reachable as `npm run docs:check`).

### 4.1 What it actually validates

| # | check | how it decides |
| --- | --- | --- |
| 1 | **Relative Markdown links resolve** | every `](target)` and `[x]: target` is resolved against the document's own directory and then against the repository root; a target that does not exist is a failure with a line number |
| 2 | **Anchors resolve** | a `#fragment` is slugified with GitHub's algorithm and looked up in the **target document's own headings**, read from disk. This is a genuine cross-document check: it is what makes a renamed heading in another file break the link |
| 3 | **Repository paths named in inline code exist** | every inline-code span shaped like a repo path is resolved against the root. IANA media-type roots (`text/`, `application/`, ...) are excluded, because the upload MIME allow-list is not a list of directories |
| 4 | **Every `/api` path is a real route** | the route table is read from the running application (`php artisan route:list --json`, absolute 8.4.17 binary); placeholders collapse to `{}` on both sides; a path the router does not register is a failure |
| 5 | **Every fenced `dart` block parses and is already formatted** | each block is written to a temp file and handed to `dart format --output=none --set-exit-if-changed`. Exit **65** = parse error, exit **1** = would be reformatted, exit **127** = no Dart binary. All three are failures, reported as three different things |
| 6 | **The GetX acceptance rule** | the section titled `## <n>. Contoh integrasi dengan GetX` must contain `extends GetxController`, `extends Bindings`, `Get.lazyPut`, `Get.lazyPut<SehatlyApiClient>` and `SehatlyApiClient`, and must contain **neither** `Dio(` nor `http.` |
| 7 | **Shape** | the numbered `## N.` sections are present and are `1..n` in order (14 here), there is exactly one level-1 heading, and there are at least 45 table rows (160 here) |

Plus two **negative** assertions, which is the part that matters for this
document's subject matter. A claim that something does *not* exist has to be
checked too, or it rots into a permanent exemption:

| list | entry | what happens if it stops being true |
| --- | --- | --- |
| `PATHS_EXPECTED_ABSENT` | `mobile/` | the tool **fails**: a guide saying "there is no Flutter app here" beside a `mobile/` directory is the exact failure the guide exists to prevent |
| `ENDPOINTS_EXPECTED_ABSENT` | `api/v1/invoice/{}` | the tool **fails**: the plan's polling endpoint would then exist and the document's "this does not exist" sentence would be a lie |

A **route group** reference is accepted only when the candidate is a genuine
prefix of at least one registered route, and never when it contains a `{...}`
placeholder. So `/api/v1/referensi` (a family of fourteen endpoints) passes and
`/api/v1/nope-xyz` does not — but `/api/v1/invoice/{id}` cannot sneak through that
rule even though it *is* a prefix of `POST /api/v1/invoice/{id}/bayar`.

### 4.2 What it does with external URLs, and why

**External `http://` and `https://` targets are counted, listed and NOT fetched
by default.** `--external` opts in. Three reasons, in the tool's header:

1. A gate that depends on the network fails for reasons that have nothing to do
   with the document. A red build that means "the internet was down" is a red
   build people learn to ignore, and then the real red builds go with it.
2. Link-rot checking is a *different* job with a *different* cadence. It wants to
   run nightly, cache, and keep reporting the same dead URL for a week. Folding it
   into the commit gate makes the gate unfixable in one commit.
3. What the commit gate actually needs to catch is the class of mistake a human
   makes — a path renamed in this repository, a heading reworded, a route deleted
   from `routes/api.php` — and all three are decidable from the working tree with
   no network.

`--external` also distinguishes **dead** (4xx) from **unreachable** (a transport
error), because "we could not reach it" and "it is gone" are different claims and
the tool does not conflate them. This guide contains **zero** external URLs, so the
question is currently moot — but the decision is recorded rather than left implicit.

### 4.3 The checker fails — the transcript

A copy of the guide was written with **six** injected defects, one per check
family. Verbatim, from the repository root.

**Step 1 — inject the defects.**

```text
> node break-doc.cjs
wrote docs/zz-mobile-integration.BROKEN.md with 6 injected defects:
  - a relative link whose target file does not exist
  - a cross-document anchor that is not a heading in the target
  - a repo path in inline code that does not exist
  - an endpoint with no registered route
  - a raw network client inside the GetX section
  - a dart block with a syntax error
```

**Step 2 — run the checker on the broken copy. Non-zero exit.**

```console
PS> node tools/check-doc-links.mjs --dart C:\...\dart-sdk\bin\dart.exe --php C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe --expect-sections 14 docs/zz-mobile-integration.BROKEN.md

FAIL -- 7 finding(s)

  [link] ...\docs\zz-mobile-integration.BROKEN.md:747
    relative target does not resolve: docs/tidak-ada.md (tried docs/docs/tidak-ada.md and docs/tidak-ada.md)
  [anchor] ...\docs\zz-mobile-integration.BROKEN.md:1635
    anchor #an-anchor-that-was-never-written is not a heading in docs/modules/README.md
  [path] ...\docs\zz-mobile-integration.BROKEN.md:942
    repository path does not exist: routes/tidak-ada.php
  [endpoint] ...\docs\zz-mobile-integration.BROKEN.md:702
    no registered route matches /api/v1/tidak-ada (normalised: /api/v1/tidak-ada)
  [dart] ...\docs\zz-mobile-integration.BROKEN.md:135
    dart block #1 does not parse:
      Could not format because the source could not be parsed:

      line 8, column 62 of ...\block-01.dart: Expected an identifier.
        |
      8 | final String emulatorBaseUrl = const String.fromEnvironment( ;
        |                                                              ^
        v
  [dart] ...\docs\zz-mobile-integration.BROKEN.md:1201
    dart block #13 parses but is NOT dart-format clean; run `dart format` on it
  [getx] ...\docs\zz-mobile-integration.BROKEN.md:1225
    section 11 contains `Dio(`, which bypasses sehatly_api_client

EXIT=1
```

All six injected defects produced findings, plus a seventh that the `Dio(` line
also disturbed the block's formatting — which is exactly the compound-failure case
a checker exists to surface. The plan's two named QA scenarios are both covered:
**(a)** `/api/v1/tidak-ada` is reported by the cross-check, **(b)** an injected raw
network client in the GetX example is rejected by the acceptance check.

**Step 3 — remove the copy and re-run. Exit 0.**

```console
PS> Remove-Item -LiteralPath "docs\zz-mobile-integration.BROKEN.md" -Force
PS> Test-Path "docs\zz-mobile-integration.BROKEN.md"
False

PS> node tools/check-doc-links.mjs --dart C:\...\dart-sdk\bin\dart.exe --php C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe --expect-sections 14 docs/mobile-integration.md
OK -- 1 document(s); 29 links, 19 repo paths, 53 api-path mentions, 19 dart blocks, 0 external URLs skipped (pass --external to fetch them)
EXIT=0
```

**Step 4 — the same thing through the npm script the document advertises.**

```console
PS> npm run docs:check
EXIT=0
```

The checker was additionally proven red on a synthetic probe, before the guide
existed, for each individual failure mode: a missing relative file, a bad anchor,
an unknown route, an unformatted dart block, a non-parsing dart block, and a raw
network client in the GetX section each produced their own finding, and a probe
with a satisfied run reported `OK` and exit 0. A checker that has never failed has
never been tested; this one has been failed on purpose, six ways, and fixed.

---

## 5. Where the guide and the real API disagree — findings, not fixes

The brief's rule was: if a sample is wrong because the API disagrees, report it,
do not edit the app. Nine findings. **Nothing in `app/**` or `routes/api.php` was
touched.**

### 5.1 The plan names an endpoint that does not exist

The plan's todo 36 section 12 says: *"webhook/payment state changes
asynchronously, so poll `GET /invoice/{id}` rather than assuming the pay call
settled it."*

**There is no such route.** The route table has
`POST /api/v1/invoice/{id}/bayar` and **no read at all** on the invoice resource.
`routes/api.php` documents why: the only invoice route is permission-scoped with
`permission:pembayaran.bayar`, which "cannot express 'is this invoice yours'", and
that is the question a client would need to ask.

The guide points the client at `GET /api/v1/pesanan-obat/{id}` — the pollable
authenticated read for an order — and says in the same sentence that the plan's
endpoint does not exist. The checker asserts that absence, so the sentence fails
the build the day the endpoint lands and the document is updated.

### 5.2 The plan's error catalogue lists a status the API never returns

The plan enumerates "200/201/**204**, 401, 403, ...". **No endpoint returns 204.**
`app/Http/Controllers/Api/V1/PasienController.php:258` says so in its own
docblock: every delete answers 200 with `{"id": 7, "deleted": true}` rather than
returning 204 with an empty body. The guide's status table marks 204 *"never
returned — do not write a 204 branch"*.

### 5.3 The plan's cookbook implies a package coverage that does not exist

`sehatly_api_client` wraps **Module 1 only** — `client.auth`, `client.me`,
`client.pasien`, `client.dokter`. Modules 2 to 5 have **no endpoint class**. They
are reachable through `client.transport`, which still supplies the bearer header,
the single-flight refresh and the typed `ApiException`, and every path exists as a
constant in the generated table. The guide says exactly that, and every one of the
13 constants it uses was verified to exist in
`packages/sehatly_api_client/lib/src/generated/paths_table.dart` (this caught a
real mistake: the constant is `apiv1dokterdokterslot`, not `apiv1dokterslot`).

### 5.4 There is no OTP resend endpoint

The plan asks for "the OTP resend and attempt-limit rules from task 52". There is
no `POST /auth/otp/kirim`. Resending is a **second `POST /api/v1/auth/login`**,
throttled at 5/min on the identifier, and issuing a new OTP invalidates the
previous unused one. The guide says this rather than inventing a route.

The attempt limit that does exist is worth stating precisely: **5 attempts per
issued code in a 300-second window, keyed on the code's own row id, and the sixth
attempt does not merely return 429 — it burns the code.**

### 5.5 Nine of the twelve registered rate limiters are not mounted

Only three are reachable: `auth-login` (5/min), `auth-otp-send` (10/min) and
`auth-otp-verify` (5 per code per 300s). The other nine — `auth-refresh`,
`booking`, `checkout`, `promo-validasi`, `chat`, `webhook-payment`,
`auth-register`, `auth-login-ip`, `otp-kirim`, `otp-kirim-jam` — are registered,
unit-asserted and driven by a probe route in the test suite, but **no route names
them**. This is already a documented finding in `.omo/evidence/task-52-sehatly.md`
and in the `AppServiceProvider` inventory table, which says so in those words.

The guide therefore documents the **mounted** set only. It never tells the mobile
team to expect a 429 the API will not send. The fix is one
`->middleware('throttle:...')` line per route in `routes/api.php`, which this todo
may not make.

### 5.6 `SehatlyEnvironment.local` cannot be used from an Android emulator

The enum's `local` value is `http://127.0.0.1:8000/api/v1`, and an Android
emulator's `127.0.0.1` is *the emulator itself*. The guide gives the `10.0.2.2`
route, and notes that it has to go through the low-level `SehatlyApiClient(...)`
constructor because that is the only way to express a base URL the enum does not
carry. The `flutter run --dart-define=...` command is beside it.

### 5.7 Stale claims inside the Dart package, reported and not fixed

| file | claim | reality |
| --- | --- | --- |
| `lib/src/api/paths.dart:5` | "route:list reports exactly 22 routes" | 74 |
| `lib/src/core/api_exception.dart:75` | the booking surface "has not been built" | todo 27 landed; `POST /api/v1/booking` and `PUT /api/v1/booking/{id}/batalkan` are registered |
| `lib/src/core/api_exception.dart:85` | the PDP consent gate "does not exist yet" | todo 47 landed; `App\Services\Pdp\PdpConsent` gates `surat_rujukan` and returns 403 |
| package `README.md`, "Not built yet" | todo 31's broadcaster and todo 32's chat endpoints are unbuilt | both landed: `config/broadcasting.php` exists, `withBroadcasting()` is called, `routes/channels.php` declares the one channel, and `GET|POST /api/v1/konsultasi/{id}/chat` plus `POST .../chat/baca` are registered |

None of these was edited. They are outside this todo, and two of them sit in files
the generated-contract tests read.

### 5.8 `docs/modules/README.md` under-reports module 2

It lists module 2 as *belum lengkap / 0 endpoints*, while the route table has
five module-2 routes: `GET /api/v1/dokter/booking`,
`GET /api/v1/dokter/{dokter}/jadwal`, `GET /api/v1/dokter/{dokter}/slot`,
`POST /api/v1/booking` and `PUT /api/v1/booking/{id}/batalkan`. That file is
todo 35's. Reported, not fixed.

### 5.9 My own first draft was wrong in eight places, and the tooling caught it

Worth recording, because it is the reason two of the checks above exist. A scan
of every backticked `snake_case` identifier against the application, the DDL and
the package, plus a scan of every Capitalised type and every generated path
constant in a sample, found and I fixed:

- `POST /api/v1/booking`: there is **no `slot_selesai`** on the request — the end
  time is the schedule window's — and `dokter_id` and `tipe_layanan` are
  additionally required;
- `POST /api/v1/resep/{id}/checkout`: the fields are `metode_id` and
  `kode_promo`, not `metode_pembayaran_id` and `alamat_pengiriman`;
- `POST /api/v1/invoice/{id}/bayar`: the field is `metode_id`, not `metode`;
- `POST /api/v1/pdp/persetujuan`: `versi_dokumen` is a **string** (max 20), not an
  integer;
- `GET /api/v1/konsultasi/{id}/chat`: accepts **only** `per_page`; the
  consultation is in the path, so `konsultasi_id` is not a parameter, and the
  rows arrive under `data.pesan`;
- `GET /api/v1/dokter/{dokter}/slot` and `.../jadwal`: `dokter` is the path
  segment, not a query parameter, and `tanggal` is the slot endpoint's only
  required parameter;
- `GET /api/v1/resep/{id}/cek-interaksi` and `GET /api/v1/obat/{id}/stok`: no
  `id` query parameter;
- a nonsense token I typed into the prose (`kons Consultation`, and three others),
  and a mistyped repo path (`patient_profil` for `pasien/profil`).

All verified against the real `FormRequest` classes and against
`docs/enums.json` before the commit.

---

## 6. Byte-level non-ASCII scan

Run with a raw-byte reader over every file this todo touched.

```console
PS> node byte-scan.cjs docs/mobile-integration.md tools/check-doc-links.mjs package.json

docs/mobile-integration.md
  BOM: false   CR bytes: 0   LF bytes: 1646
  CLEAN: no BOM, LF only, code blocks pure ASCII; prose uses U+2022 x36

tools/check-doc-links.mjs
  BOM: false   CR bytes: 0   LF bytes: 1179
  CLEAN: no BOM, LF only, code blocks pure ASCII

package.json
  BOM: false   CR bytes: 0   LF bytes: 15
  CLEAN: no BOM, LF only, code blocks pure ASCII

ALL CLEAN
```

- **No UTF-8 BOM** in any of the three files. PowerShell 5.1 has no
  `-Encoding utf8NoBOM`, so every write used `New-Object Text.UTF8Encoding($false)`
  or Node's `fs.writeFileSync(..., 'utf8')`, both of which omit the BOM.
- **LF only**, matching `.gitattributes`' `* text=auto eol=lf`. The tool's working
  copy had picked up 1179 CR bytes from an earlier
  `[IO.File]::WriteAllLines` round-trip and was rewritten to LF; the committed blob
  was already LF (git normalises on add) but the working copy is now honest too.
- **Code blocks are pure ASCII.** The error-catalogue decision tree originally used
  box-drawing characters (`U+251C`, `U+2500`, `U+2502`, `U+2514`) and was rewritten
  with `|--` / `` `-- `` / `|` so the whole document sits inside the project's
  seven-codepoint prose allow-list.
- **Prose uses `U+2022` (bullet) 36 times**, which is on the allow-list and is the
  same character `App\Support\NikMasker` and `NamaMasker` use, so the NIK-mask
  example in section 8.1 renders exactly as the server emits it.

---

## 7. Orchestrator verification

Every command below was **run** from the repository root after this todo, with
the absolute PHP binary (bare `php` on `PATH` is 8.2 and cannot boot the app).

```console
PS> C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe artisan test
{"tool":"pest","result":"passed","tests":1153,"passed":1153,"assertions":22510,"duration_ms":532824}
EXIT=0

PS> C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe artisan test --filter=NoFlutterMobileTest
{"tool":"pest","result":"passed","tests":4,"passed":4,"assertions":19,"duration_ms":454}
EXIT=0

PS> C:\laragon\...\php.exe artisan route:list --path=api/v1 --json | C:\laragon\...\php.exe -r "echo count(...), PHP_EOL;"
74

PS> C:\laragon\...\php.exe artisan sehatly:enums --check
 live / DDL 69 ENUM columns, 319 values, 0 divergences
 sha256 ed87600aba847dbf27c8dfb1a590a88e42efabfeefe56817e9d5e527a52c2be6
EXIT=0

PS> C:\laragon\...\php.exe artisan sehatly:openapi --check
 request_bodies.dart 128362 bytes, sha256 e3912bcbe1385f88d2703a6692477cfcb3dfc3c89f44e10d4a78544367fe78b3
 paths_table.dart 19203 bytes, sha256 994cd90e7b9a2e1ff496cfe81842c8f9906a6e4e07abcd1908d29050776b9fdc
EXIT=0

PS> C:\laragon\...\php.exe artisan sehatly:verify-schema
 PASS -- 75 tables, 2 views verified. Nothing was written.
EXIT=0

PS> Test-Path mobile
False
```

And in `packages/sehatly_api_client`: `dart analyze` exit 0,
`dart test` **132 passed** exit 0, `dart format --set-exit-if-changed` exit 1 on
exactly the three byte-locked generated files (Section 3.2).

### Not modified

`app/**`, `routes/api.php`, `database/**`, `web/**`, `telemedicine_test.sql`,
`docs/openapi.yaml`, `docs/enums.json`, `docs/timezone-policy.md`,
`docs/contract-conformance.md`, `docs/schema-notes.md`, `docs/migration-order.md`,
`docs/pre-existing-defects.md`, `docs/modules/**`, `README.md`, and
`packages/sehatly_api_client/pubspec.yaml`. No migration, index or constraint was
added. `migrate:fresh`, `migrate:rollback` and the `sehatly` database were not
touched. `markTestSkipped` was not used. No file I did not create was deleted.
`git add -A` was never used — every commit staged explicit paths, and a concurrent
executor's untracked `web/src/pages/*` and `web/src/features/resep/` files were
left alone.

The only files this todo created or modified are:

| file | change |
| --- | --- |
| `docs/mobile-integration.md` | **new**, 1646 lines |
| `tools/check-doc-links.mjs` | **new**, 1179 lines |
| `package.json` | one added script, `docs:check` |
| `packages/sehatly_api_client/test/interceptor_test.dart` | `dart format` only |
| `packages/sehatly_api_client/tool/audit_wire_fields.dart` | `dart format` only |

---

## 8. Ledger

One line appended to `.omo/start-work/ledger.jsonl`, built with
`JSON.stringify` and **parsed back before the append** so a malformed line could
never be written. Append-only via `fs.appendFileSync(..., { flag: 'a' })`; no git
output was ever redirected into it.

```text
appended 9984 bytes
total lines: 85 | malformed: 0
last task: 36. docs/mobile-integration.md -- the integration guide
ends with newline: true
$ git diff --numstat -- .omo/start-work/ledger.jsonl
1	0	.omo/start-work/ledger.jsonl
```

**1 insertion, 0 deletions.** The 84 pre-existing entries are untouched and all 85
parse.

`.omo/plans/` is orchestrator-owned; **no checkbox was marked**.

---

## 9. `git show --stat`

```text
commit 61b64859824f8bc68d1acc72b591481485e82fbc
Author: Ahmadz <ahmadz@gmail.com>
Date:   Wed Sep 30 03:02:24 2026 +0700

    docs(mobile): add the Flutter team integration guide, 14 sections
    ...

 docs/mobile-integration.md | 1632 +++++++++++++++++++++++++++++++++++++++++++
 package.json               |   3 +-
 tools/check-doc-links.mjs  |  128 +++-
 3 files changed, 1750 insertions(+), 13 deletions(-)
```

```text
commit b24c04c
    docs(mobile): six cross-document anchors, ASCII decision tree, checker fixes
 docs/mobile-integration.md |  36 +++++++++++++++++++++---
 1 file changed, 36 insertions(+), 22 deletions(-)
```

Earlier in the todo, as separate commits so nothing is lost to a kill:

```text
commit 5c69f90
    style(sehatly_api_client): dart format the two hand-written files
 packages/sehatly_api_client/test/interceptor_test.dart   |   8 +-
 packages/sehatly_api_client/tool/audit_wire_fields.dart  | 235 +++++++----------
 2 files changed, 130 insertions(+), 113 deletions(-)

commit 6b7b25b
    tools(docs): a link, path, route and Dart-block checker that can fail
 tools/check-doc-links.mjs | 1075 +++++++++++++++++++++++++++++++++++++
 1 file changed, 1075 insertions(+)
```

---

## 10. What is unfinished

**Nothing in the plan's acceptance criteria for todo 36 is outstanding.** Stated
explicitly rather than left for the reader to infer:

1. `docs/mobile-integration.md` exists with all 14 numbered sections of the todo
   body — Section 2.
2. A link-check script confirms every relative link and every referenced repo path
   resolves — Section 4.1, and proven red in Section 4.3.
3. A script cross-checks every endpoint path in the document against
   `php artisan route:list --path=api/v1 --json` with **zero** unknown paths —
   Section 4.1 check 4, 53 api-path mentions resolved.
4. Every fenced `dart` block is extracted and handed to
   `dart format --output=none --set-exit-if-changed` — 19 blocks, all clean — and
   the GetX section contains an `extends GetxController` block and an
   `extends Bindings` block that reference `SehatlyApiClient` with **no** raw
   network-client call — Section 4.1 checks 5 and 6, and the injection in Section 4.3 proves
   the negative half bites.
5. `grep -c "^| " docs/mobile-integration.md` = **160**, against a floor of 45.
6. `test ! -e mobile` still succeeds, and
   `find . -maxdepth 2 -name pubspec.yaml -not -path './vendor/*' -not -path './packages/*'`
   returns nothing — Section 7. No Flutter project was introduced.

Deliberately left as found, and reported as findings rather than fixed:

- the three byte-locked generated Dart files are not `dart format` clean, and
  cannot be without failing the OpenAPI drift check (Section 3.2);
- nine of the twelve registered rate limiters are unmounted (Section 5.5);
- the stale claims inside the Dart package and in `docs/modules/README.md`
  (Section 5.7, Section 5.8).

Each of those needs a change in a file this todo is not permitted to make.
