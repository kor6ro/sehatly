# Evidence: todo 53 — the OpenAPI contract, both clients' types, and the README

## What was built

`php artisan sehatly:openapi` walks the **live** route table and emits four
artefacts from one walk:

| Artefact | SHA-256 | Bytes |
| --- | --- | --- |
| `docs/openapi.yaml` | `af881290aca91a7895f2a3c8bc6a8c2898d3c95fa8d0e6ca493128013e9605b5` | 286464 |
| `packages/sehatly_api_client/lib/src/generated/enums.dart` | `ed37322f9b11a7e2bb2a9872d06245ef4977ebe98518b5af96f9761821364c84` | 150895 |
| `.../request_bodies.dart` | `e3912bcbe1385f88d2703a6692477cfcb3dfc3c89f44e10d4a78544367fe78b3` | 128362 |
| `.../paths_table.dart` | `994cd90e7b9a2e1ff496cfe81842c8f9906a6e4e07abcd1908d29050776b9fdc` | 19203 |
| `web/src/types/api.d.ts` | `cb8b707b3e6e448a996cdc76a00621e9315ada8ebd92573c742d1a3bcef06f04` | 312224 |

Files created:

- `app/Support/OpenApi/RouteInventory.php` — the live-route walk
- `app/Support/OpenApi/OpenApiDocumentBuilder.php` — document construction
- `app/Support/OpenApi/DartContractGenerator.php` — the Dart half, same walk
- `app/Support/OpenApi/OpenApiGenerationException.php`
- `app/Console/Commands/GenerateOpenApi.php`
- `tests/Unit/Console/OpenApiCommandTest.php` — 16 tests
- `tests/Unit/NoFlutterMobileTest.php` — 4 tests
- `tools/openapi-types/{package.json,package-lock.json,.gitignore,README.md}`
- `web/src/types/api.d.ts`, `web/src/types/api.contract.ts`
- `README.md`, `.github/workflows/contract.yml`

Files modified: `composer.json` (added the `contract` script), `package.json`
(added `types:generate`).

---

## The route count the generator read

**74.** That is `RouteInventory::$routesRead` — the number of `Route` objects under
`api/v1` in the live collection, i.e. the number the HTTP kernel would dispatch.

```
routes_read   74
unique_paths  65   (nine paths carry two methods each)
operations    74
form_requests 40
```

Cross-checked against `route:list`, and the test asserts the two agree rather
than asserting a literal:

```console
php artisan route:list --path=api/v1 --json | php -r "echo count(json_decode(stream_get_contents(STDIN), true)), PHP_EOL;"
74
```

`jq` is not installed on this host, so the plan's `| jq length` is the portable
`php -r` form everywhere, as the plan's todo 1 requires.

The 65-vs-74 distinction matters: quoting 74 where 65 is meant would make the
generator's honesty unfalsifiable, so both numbers are published.

---

## The drift check, demonstrated failing

### Control first — a green run

```console
$ php artisan sehatly:openapi --check

  UP TO DATE -- every generated file is byte-identical to a fresh export
 routes read 74 under api/v1
 paths / operations 65 paths, 74 operations
 openapi.yaml 286464 bytes, sha256 af881290aca91a7895f2a3c8bc6a8c2898d3c95fa8d0e6ca493128013e9605b5
 enums.dart 150895 bytes, sha256 ed37322f9b11a7e2bb2a9872d06245ef4977ebe98518b5af96f9761821364c84
 request_bodies.dart 128362 bytes, sha256 e3912bcbe1385f88d2703a6692477cfcb3dfc3c89f44e10d4a78544367fe78b3
 paths_table.dart 19203 bytes, sha256 994cd90e7b9a2e1ff496cfe81842c8f9906a6e4e07abcd1908d29050776b9fdc

exit=0
```

### The mutation — one hand-edited path in `docs/openapi.yaml`

`'/api/v1/konsultasi/{id}/chat':` renamed to `'/api/v1/konsultasi/{id}/chats':`.

```console
$ php artisan sehatly:openapi --check

 DRIFT -- docs/openapi.yaml is not a fresh export. Run `php artisan sehatly:openapi`.
 on disk 286465 bytes, sha256 8e43d4f5a9853f92c805c6bea3e9dee55eaff2111f71329cd896b0214b376b5b
 fresh export 286464 bytes, sha256 af881290aca91a7895f2a3c8bc6a8c2898d3c95fa8d0e6ca493128013e9605b5
 first difference byte 48868: on disk "rEnvelope' } } } '/api/v1/konsultasi/{id}/chat", fresh "rEnvelope' } } } '/api/v1/konsultasi/{id}/chat"
 routes read 74

 No file was overwritten. Commit the regenerated copies, or revert the hand-edit.

exit=1
```

Then, in the same sequence:

```console
GREEN control exit=0
MUTATED   exit=1
RESTORED  exit=0
git diff --stat -- docs/openapi.yaml   ->  (empty)
```

Two things the transcript proves beyond "exit 1":

- **The check refused rather than repaired.** The file's SHA-256 after the failed
  run was still `8e43d4f5…` — the hand-edited bytes. A check that overwrites the
  drift has destroyed the evidence of it.
- **The red was caused by the mutation, not by ambient breakage.** The green run
  before and the green run after both exit 0 on the same route table.

### The same, for the Dart half

`const String apiv1dokter = '/api/v1/dokter';` → `'/api/v1/doctor'`:

```console
 DRIFT -- packages/sehatly_api_client/lib/src/generated\paths_table.dart is not a fresh export.
MUTATED dart exit=1
untouched: True
RESTORED dart exit=0
git status --porcelain -- packages/   ->  (empty)
```

### And the same, for the DoD check — a mutated route table

`a mutated route table -- a POST with no FormRequest -- IS detected` registers
`POST /api/v1/openapi-dod-probe` **in memory** (so `routes/api.php`, which other
executors own, is untouched), points it at a controller method whose signature
takes a bare `Illuminate\Http\Request`, and asserts the command refuses:

```
expect($offenders)->toHaveCount(1, 'the probe route was not picked up by the inventory')
expect($offenders[0]['exempt'])->toBeFalse('a brand-new unvalidated POST was accepted as exempt')
expect(Artisan::call('sehatly:openapi', ['--json' => true]))->toBe(1, 'the command published a document containing an unvalidated POST')
expect($report['ok'])->toBeFalse()
expect(array_column($report['unexempt_writes'], 'path'))->toContain('/api/v1/openapi-dod-probe')
```

The collection is rebuilt without the probe in a `finally`, then `--check` is
asserted green again.

### In the suite, permanently

All four live in `OpenApiCommandTest.php` and run on every `php artisan test`:

| Test | What it proves |
| --- | --- |
| `the committed document is a fresh export -- the GREEN control` | the check passes when it should |
| `a hand-edit to one path in docs/openapi.yaml is DETECTED and refused -- the RED` | exit 1, `DRIFT`, byte offset, file untouched, then restored and green |
| `the generated Dart files are a fresh export, and a hand-edit to one is DETECTED` | the same for `paths_table.dart` |
| `a mutated route table -- a POST with no FormRequest -- IS detected` | the DoD check fails when a violating route exists |

The hand-edit test asserts its own mutation is not a no-op
(`expect($mutated)->not->toBe($original, 'the hand-edit did not apply')`), so a
change in the YAML dumper that stopped quoting the key turns it into a loud
failure rather than a test that silently proves nothing.

---

## Double-generation SHA-256 proof

Two exports over an unchanged route table produce identical bytes. The suite
asserts it on every run and prints the hash:

```
  [determinism] two exports agree: sha256=af881290aca91a7895f2a3c8bc6a8c2898d3c95fa8d0e6ca493128013e9605b5 bytes=286464
```

`regeneration is deterministic: two exports over an unchanged route table are
byte-identical` calls the command twice, hashes the file after each, and asserts
both the hashes and the bytes match — then restores in a `finally`.

What makes it deterministic, by construction rather than by luck:

- operations sorted by (path, method) with `strcmp`, not registration order;
- keyed blocks (`components/schemas`, `tags`) sorted by `strcmp` or emitted in a
  declared order;
- ENUM values in `docs/enums.json` order — MySQL's declaration order, which the
  numeric index depends on — never sorted;
- no timestamp, no host, no absolute path, no database name in any artefact;
- exactly one trailing newline (Symfony's dumper already appends one; appending
  again produced `\n\n`, which `git diff` reports on every regeneration);
- LF only, no BOM, ASCII only — all three asserted byte-wise by the suite.

---

## The mobile guard test, and what it asserts

`tests/Unit/NoFlutterMobileTest.php`, 4 tests, all green.

The plan's guardrail was `test ! -e mobile` in prose — the **only** plan
guardrail in 54 todos that had never had automatic proof. It is now a test.

1. **No `mobile/` at the repository root** — asserted as a directory *and* as a
   file, because `test ! -e mobile` rejects both.
2. **No `pubspec.yaml` declares a `flutter:` SDK constraint.** Every
   `pubspec.yaml` in the repository is found by walking `base_path()` (skipping
   `vendor`, `node_modules`, `.git`, `build`, `.dart_tool`), not by a hard-coded
   list, so a second package added later is covered. Forbidden keys:
   `flutter`, `flutter_test`, `flutter_web_plugins`.
3. **No `pubspec.yaml` depends on `flutter`** under `dependencies:` or
   `dev_dependencies:`.
4. **The pure-Dart client still depends on `dio` and still wraps it** — the guard
   in the other direction. A "no Flutter" test satisfied by gutting the package
   is not a guard, it is vandalism, so `pubspec.yaml` must still list `dio` and
   `lib/src/client.dart` must still import `package:dio/dio.dart`.

Non-vacuity: the walk must actually find
`packages/sehatly_api_client/pubspec.yaml`, and the suite asserts it did, with
the found list in the failure message.

YAML is parsed by key, not grepped: a naive `grep -r flutter packages/` would flag
the README and the docblock that *explains* the storage split.

### Verified failing

```console
# A probe pubspec.yaml with a flutter: constraint and a flutter dependency
RED -> result=failed tests=4 passed=2 failed=2
  "…/.guard_probe/pubspec.yaml declares a \"flutter:\" SDK constraint. That makes
   the package untestable without the Flutter SDK and is exactly what todo 24
   chose not to do. Remove the constraint. Found keys: sdk, flutter"
  "…/.guard_probe/pubspec.yaml declares `flutter` under dependencies. A pure-Dart
   package cannot depend on the Flutter SDK; the concrete flutter_secure_storage
   implementation belongs in the consuming Flutter app, behind the TokenStore
   interface."

# A bare mobile/ directory
RED -> result=failed tests=4 passed=3 failed=1
  "A mobile/ directory exists at the repository root. This repository ships NO
   Flutter app: the mobile entry point is the pure-Dart package
   packages/sehatly_api_client plus docs/mobile-integration.md. Delete the
   directory, or amend the plan first -- do not delete it from under the executor
   that created it."

# Both removed afterwards; mobile absent
```

`test ! -e mobile` passes: the directory does not exist.

---

## The envelope, in the schema

Measured from the committed document:

```
PaginatedEnvelope keys: success,data,message,meta
ErrorEnvelope errors type:            object
ErrorEnvelope errors value type:      array
```

- **`meta` is a top-level fourth key**, a sibling of `data` — not nested inside
  it. `ApiResponse::success()` appends it after `message` precisely so adding
  pagination cannot renumber the three keys every existing client already reads,
  and `PaginatedEnvelope` mirrors that with
  `required: [success, data, message, meta]`. `SuccessEnvelope` has exactly
  `success, data, message` and `additionalProperties: false`, so "this response
  is not paginated" is checkable rather than a client-side guess.
- **`errors` is `field -> array of messages`**, with
  `additionalProperties: {type: array, items: {type: string}, minItems: 1}`.
  `ValidationException::errors()` returns `array<string, list<string>>` and a
  field really can fail more than one rule; flattening to `{field: string}` would
  describe something the framework cannot return, and a client built on that
  shows the first message and hides the rest.
- **`errors` is typed `object`** because `ApiResponse::error()` casts it to
  `(object)`, so an empty set encodes `{}` not `[]`. That is also why 401/403/404
  /429/500 can all reference the same `ErrorEnvelope`.
- **Auth is a Sanctum bearer** — `components.securitySchemes.sanctum`,
  `type: http, scheme: bearer`, with the rotation contract spelled out (the server
  revokes the presented refresh token on use, so a spent token means the session
  is unrecoverable). Per operation: 49 authenticated publish
  `security: [{sanctum: []}]`, 25 anonymous publish `security: []` — an explicit
  statement, not an absence.

### Status codes actually published

```
200 => 52    201 => 22    401 => 49    403 => 49    404 => 74    422 => 52    429 => 3    500 => 74
```

The counts are consequences of the route table, not a list:
`401`/`403` appear exactly where `auth:sanctum` does (49), `429` exactly where
`throttle:` does (3), `422` exactly where a `FormRequest` does (52 of 74 — the
three exemptions and the 19 reads have no body to validate).

### The rate limits are read, not invented

```yaml
x-ratelimit:
  limiter: auth-login
  max: 5
  decay_seconds: 60
```

`auth-otp-verify` → 5/60, `auth-otp-send` → 10/60. These come from calling each
named limiter's own closure with a synthetic request and reading `Limit::$maxAttempts`
and `$decaySeconds` — the values `AppServiceProvider` registers. `AppServiceProvider`
is **read** here and never written (another executor owns it). The suite re-reads
the limiter and asserts each published number matches, so editing the provider
changes the document.

---

## Byte-level scans

Raw-byte reads (`[System.IO.File]::ReadAllBytes`, no text-mode decoding):

| File | Bytes | BOM | CR | non-ASCII | last byte |
| --- | --- | --- | --- | --- | --- |
| `docs/openapi.yaml` | 286464 | none | 0 | 0 | `0a` |
| `.../generated/enums.dart` | 150895 | none | 0 | 0 | `0a` |
| `.../generated/request_bodies.dart` | 128362 | none | 0 | 0 | `0a` |
| `.../generated/paths_table.dart` | 19203 | none | 0 | 0 | `0a` |
| `web/src/types/api.d.ts` | 312224 | none | 0 | 0 | `0a` |

Exactly one trailing newline on each; the suite asserts the last byte is `0a` and
the second-to-last is not `0a`. The BOM check reads the first three bytes as raw
bytes rather than through a text-mode helper, which would interpret or strip the
very bytes it is looking for. Zero CR bytes and zero non-ASCII bytes: the
descriptions are English on purpose, and a stray high byte is a sign someone typed
prose the next machine's locale will render differently — in a file that is
compared byte for byte.

### Token audit against the DDL

`docs/enums.json` is todo 42's catalogue, generated from the live
`information_schema` **and** cross-checked against `telemedicine_test.sql` — 69
base-table ENUM columns, 319 values, 0 divergences, verified this round
(`sehatly:enums --check` exit 0, sha256
`ed87600aba847dbf27c8dfb1a590a88e42efabfeefe56817e9d5e527a52c2be6`).

The enum-value bucket is audited into the OpenAPI document by
`OpenApiDocumentTest::the_ENUM_catalogue_in_the_document_is_exactly_docs_enums_json`,
which walks all 69 columns and asserts **ordered** equality — a sorted list would
be a different type, because MySQL's numeric index is the declaration order. It
also spot-checks `booking.status` (contains `menunggu_pembayaran` and
`kadaluarsa`) and `users.tipe` (7 values).

All 69 columns became `Enum<Table><Column>` component schemas in the YAML and 69
Dart enums. No value was retyped by hand anywhere: `web/src/types/api.d.ts` gets
them from the YAML via `openapi-typescript`, and the Dart `enums.dart` from the
same `docs/enums.json` read.

---

## The Dart situation, stated honestly

`dart` **is** installed and was run — it is simply not on `PATH`:

```
C:\Users\axioo\AppData\Local\Microsoft\WinGet\Packages\Google.DartSDK_Microsoft.Winget.Source_8wekyb3d8bbwe\dart-sdk\bin\dart.exe
Dart SDK version: 3.13.2 (stable) (Tue Aug 25 01:01:12 2026 -0700) on "windows_x64"
```

| Command | Result |
| --- | --- |
| `dart pub get` | resolved (dependencies already present) |
| `dart analyze` | **No issues found** — exit 0 |
| `dart test` | **132 tests, all passed** — exit 0 |
| `dart format --set-exit-if-changed lib/src/generated` | **exit 1 — NOT clean** |

The generated Dart is **not** `dart format` clean, and that is a deliberate choice
rather than an oversight. `--check` compares bytes, so the generator must emit
deterministic output; reproducing `dart format`'s line-breaking would mean
reproducing its cost model, and shelling out to the formatter after writing would
make the bytes depend on the installed formatter's version. The generator emits
one collection element per line, which is formatting-stable and byte-stable.
`dart analyze` is the enforced gate, and it is clean. The reasoning is recorded in
`DartContractGenerator::collection()` and in the README's "Known gaps".

Three real Dart constraints were found and fixed, each with a probe file proving
the diagnosis rather than a guess:

1. **The last enum value needs `;`, not `,`.** With a trailing comma the parser
   ends the enum body and every value above becomes `extra_positional_arguments`
   — 319 errors in one file, all from one character. Verified with a two-member
   enum.
2. **A static member named `values` is illegal inside an enum** — compile error,
   not a shadow. Renamed to `members`.
3. **The member NAME is lowerCamelCase while `wireValue` carries the DDL value
   verbatim.** A wire string the database would reject with a 1264 is therefore
   still visible, and `tryParse` returns `null` for it rather than throwing.

### What is and is not generated on the Dart side

Generated: `enums.dart` (69 enums), `request_bodies.dart` (40 classes, required
fields as constructor parameters, `Rule::in([...])` as a `static const Set<String>`),
`paths_table.dart` (every registered path + its methods).

**Not** generated, and named in the file header: response DTOs. `docs/openapi.yaml`
types every response's `data` as an untyped object on purpose; deriving a response
shape would mean running the application, or far worse duplicating every
`App\Http\Resources\*` class in a second place. `lib/src/model/dto.dart` remains
the response side, each DTO naming the resource it was transcribed from, and it
was **not** modified. The envelope stays in `lib/src/core/api_envelope.dart` —
a second generated parser for the same body would be the problem this task
exists to remove.

---

## The TypeScript side

```console
npm run types:generate     # openapi-typescript 7.13.0, 165ms
npm run types:check        # tsc --noEmit, exit 0
npm run build              # vite build, exit 0
```

**The generator is isolated in `tools/openapi-types/`, not in `web/`.**
`openapi-typescript@7.13.0` declares `typescript: ^5.x` as a *peer* dependency and
calls that compiler's JavaScript API (`ts.factory`). `web/` compiles against
`typescript: ^7` (todo 3's pin). npm cannot satisfy both in one tree: installing
into `web/` fails resolution outright, and `--legacy-peer-deps` produces a
generator that crashes on `ts.factory` being undefined. **It did crash exactly
that way**, which is why the tree is split. Two dependency trees for two tools is
a smaller problem than a generator that cannot run. Versions are pinned exactly
because the output file is committed and 7.13.0 collapses a single-value-type
schema with an `enum` into the union itself.

`web/src/types/api.contract.ts` exists because a generated file nothing imports
cannot fail. It instantiates a type-level assertion per envelope fact:

| Assertion | Failure branch |
| --- | --- |
| `meta` is the `PaginatedMeta` block | `meta-is-not-the-pagination-block` |
| `data` carries no nested `meta` | `meta-is-nested-inside-data` |
| `errors[email]` is a list | `errors-are-flattened-to-one-string-per-field` |
| `SuccessEnvelope` declares no `errors` key | `success-envelope-declares-errors` |
| a paginated op references `PaginatedEnvelope` | `a-paginated-operation-does-not-reference-PaginatedEnvelope` |
| a non-paginated one references `SuccessEnvelope` | `a-non-paginated-operation-references-PaginatedEnvelope` |
| `tipe_layanan` is a closed set | `booking.tipe_layanan-is-not-a-closed-set-in-the-generated-types` |
| `EnumBookingStatus` is a closed set | `booking.status-is-not-a-closed-set-in-the-generated-types` |
| an authenticated op publishes 401 | `an-authenticated-operation-publishes-no-401` |
| a body-less write publishes no 422 | `a-bodyless-write-publishes-a-422-it-can-never-produce` |
| an anonymous route publishes no 401 | `an-anonymous-operation-publishes-a-401` |

Each resolves to `true` or a **string naming what broke**, through
`Assert<T extends true>` — because `type X = Foo extends Bar ? never` is
evaluated lazily, and a bare conditional stops checking without saying so.

**Mutation-verified:** adding a probe that assumes `errors[email]` is a single
string makes `types:check` fail with a named error; removing it passes.

```console
src/types/api.contract.ts(315,14): error TS2322:
  Type '"still-a-list"' is not assignable to type '"flattened-to-one-string-per-field"'.
```

Two gaps are **named** in the file header rather than papered over:
`openapi-typescript` 7.13.0 does not project `minItems: 1` onto a non-empty tuple,
and does not project per-operation `security` at all. Both are checked in the PHP
suite instead, where the document itself is read.

---

## Every README command, run

Each was executed before being written down.

| Command | Exit | Result |
| --- | --- | --- |
| `composer validate` | 0 | `./composer.json is valid` |
| `vendor/bin/pint --test` (touched paths) | 0 | `{"result":"passed"}` |
| `php artisan db:create-test-database` | 0 | `Database [telemedisin_db_test] already exists, nothing to create.` |
| `php artisan migrate:fresh --seed --force` | 0 | 78 migrations + `DevFixtureSeeder` DONE |
| `php artisan migrate --force` (fresh private DB) | 0 | 78 migrations DONE, then `db:seed` exit 0; probe DB dropped afterwards |
| `php artisan sehatly:verify-schema` | 0 | `PASS — 75 tables, 2 views verified. Nothing was written.` |
| `php artisan sehatly:enums --check` | 0 | `UP TO DATE`, 69 columns, 319 values, 0 divergences |
| `php artisan sehatly:openapi --check` | 0 | `UP TO DATE` for all four generated files |
| `composer run contract` | 0 | both `--check` commands green |
| `php artisan test` | 0 | **1100 tests, 1100 passed, 21630 assertions** |
| `npm run types:generate` | 0 | regenerated `api.d.ts`; `git diff --stat` **empty** |
| `npm run types:check` | 0 | no output = clean |
| `npm run build` | 0 | `✓ built in 8.70s`, 3327 modules |
| `npm --prefix web run test:unit` | 0 | `tests 14, pass 14, fail 0` |
| `dart analyze` | 0 | `No issues found!` |
| `dart test` | 0 | `132: All tests passed!` |
| `test ! -e mobile` | 0 | absent |
| `php artisan route:list --path=api/v1 --json \| php -r "…count(…)"` | 0 | `74` |

Two README claims were verified against the files rather than assumed:
`web/vite.config.ts:46` is `cssMinify: 'esbuild'`, and `docs/mobile-integration.md`
and `docs/contract-conformance.md` **do not exist** — the README says so instead
of linking them.

---

## Mutation-harness control

The Pest JSON reporter **omits** `failed` and `errors` when zero, so a parser that
reads `failed` unconditionally calls a green run red. Control run before relying
on it:

```console
# a probe test that is red on purpose
RED   -> result=failed tests=1 passed=0 failed=1   has 'failed' key: True
# the real guard
GREEN -> result=passed tests=4 passed=4             has 'failed' key: False
```

Every result quoted in this document was read through the `result` field for the
same reason.

---

## Test output

```console
$ php artisan test
{"tool":"pest","result":"passed","tests":1100,"passed":1100,"assertions":21630,"duration_ms":494513}
```

Up from the 1080 baseline: +16 `OpenApiCommandTest` + 4 `NoFlutterMobileTest`.
Zero skipped, zero failed, zero errors.

The generator tests alone: `tests 20, passed 20, assertions 1836`.

---

## Constraints honoured

- `app/Providers/AppServiceProvider.php` **not modified** — read only, and only
  via `RateLimiter`'s registered closures.
- `app/Services/Auth/`, `app/Services/Pasien/`, `app/Http/Controllers/**` **not
  modified**.
- `packages/sehatly_api_client` **existing sources not modified**; the three
  generated files are new, under `lib/src/generated/`.
- `database/migrations/`, `database/seeders/`, `telemedicine_test.sql` **not
  touched**. No index or constraint added.
- `phpunit.xml` **not modified**; the per-run override was `$env:DB_DATABASE`.
- No `markTestSkipped`, no skips at all.
- Nothing deleted that this task did not create.
- `git add` was always path-scoped; never `git add -A`.
- `.omo/plans/` untouched; no checkbox marked.
- `.omo/start-work/ledger.jsonl` appended with one `node -e` line, parsed back
  before committing; git output never redirected into it.

---

## Commits

```
84d73bb docs: project README, composer contract script, and the contract CI workflow
04c01be style: pint the OpenApi generator and its tests
0fe6387 feat(api): generate the Dart contract from the same route walk
d58ed24 chore(tools): lock the openapi-typescript toolchain
2c73aa5 web(api): generate api.d.ts from docs/openapi.yaml and assert its types
991b848 docs(api): generate docs/openapi.yaml from the live route table
fc52f91 test: guard the no-Flutter-app guardrail with a real test
```

---

## Unfinished / not done

1. **`docs/mobile-integration.md` and `docs/contract-conformance.md` do not
   exist.** The plan names both and both belong to other todos (49/50 and 36). The
   README's docs index says they are absent rather than linking them. The plan's
   todo 53 text asks for a docs index linking them, so this todo's index is
   incomplete by the plan's own definition — the fix belongs to whoever owns those
   documents.
2. **The FormRequest DoD has three documented exemptions** rather than zero. Each
   carries a written reason in `RouteInventory::FORM_REQUEST_EXEMPTIONS` and the
   test asserts the list cannot grow silently. Two of them
   (`PUT /notifikasi/{id}/baca`, `PUT /notifikasi/baca-semua`) have no body at all,
   which is the strongest possible case for the exemption, but they are
   exemptions and the README says so.
3. **`dart format` is not clean on the generated Dart**, as explained above.
   `dart analyze` is clean and is the enforced gate.
4. **Response payload schemas are untyped in the document.** Deliberate and stated
   in `info.description` and in the README: deriving them would mean duplicating
   every `App\Http\Resources\*` class.
5. **The CI workflow was not executed** — no GitHub runner was available here.
   Every command it runs was run locally and its exit code is recorded above, but
   the YAML itself has not been run end to end. The `migrate --database=mysql
   --env=testing` step in the PHP job is the least-verified line in it: locally
   the test database is migrated by overriding `$env:DB_DATABASE` per run, and
   that override is what a reviewer should check first if the job goes red.
6. **`npm --prefix tools/openapi-types ci`** in CI assumes the committed
   `package-lock.json` resolves on Linux. It was resolved on Windows only.

### Recorded without measurement, and why

Two claims in this document are not measurements, and are marked rather than
presented as evidence:

- **`data`'s shape.** The document publishes `data` as `object`. What a *given*
  endpoint puts there is not recorded here because it is `lib/src/model/dto.dart`'s
  job and that file was not read for this todo beyond confirming it still exists
  and names its resource. The `info.description` in `docs/openapi.yaml` states the
  limit explicitly and points at that file.
- **The `migrate --database=mysql --env=testing` line in the PHP CI job.** Not
  run locally; see item 5.
