# Contract conformance

What this document is: the measured state of `docs/openapi.yaml` against the
**running application**, as established by the suite in `tests/Contract/`.

What it is not: a summary of what the document claims about itself. The
document's own prose is not evidence, and this file does not repeat it as though
it were. Where the two disagree, the disagreement is recorded below with the
side that is wrong named.

---

## Run it

```console
php artisan test tests/Contract
```

`phpunit.xml` declares only the `Unit` and `Feature` testsuites, and this todo
was not permitted to change it, so **a bare `php artisan test` does NOT run this
suite**. `composer contract` does. That is a real gap in the default gate and is
stated here rather than left for someone to discover.

The suite runs against a per-executor database:

```console
$env:DB_DATABASE = "telemedisin_db_test_t49"     # PowerShell
php artisan test tests/Contract
```

It uses `DatabaseTransactions`, not `RefreshDatabase`, so it never runs
`migrate:fresh` and never touches the shared `telemedisin_db_test`.

---

## The headline numbers

| Measure | Value |
| --- | --- |
| Operations in `docs/openapi.yaml` | **74** |
| Operations in `Route::getRoutes()` under `api/v1` | **74** |
| Documented operations with **no** real route | **0** |
| Real routes with **no** document entry | **0** |
| Real routes with **no** spec entry at all | **0** |
| Operations driven live for at least one status | **74 / 74 (100%)** |
| Live responses validated against their published `$ref` | **72 / 74 (97%)** |
| Live responses that **fail** that validation | **2 / 74 (3%)** |
| Of those failures, how many are explained findings | **2 / 2 (100%)** |
| Tests / assertions | **201 / 2707** |
| Skipped tests | **0** |

F-007 resolved Findings 1-4 by correcting the generator, so the 17 live-response
failures this table used to report are down to **2**: the QR verifier's
undocumented 422 (Finding 5) and the payment webhook's undocumented 401 (Finding 6).
Both are `docs/openapi.yaml` omissions that the application is right about, both are
still asserted as tripwires in `tests/Contract/ContractDivergenceTest.php`, and
neither was "fixed" by relaxing a test or by hand-editing the document - the document
is regenerated from `App\Support\OpenApi\OpenApiDocumentBuilder`, and the resolved
findings are asserted against LIVE responses, not against the file.

---

## What "driven live" means here, and what it does not

Every assertion in this suite is made against a response the application actually
produced. Nothing compares the document to itself. A test that only checked the
spec describes itself would pass on a repository where every route returned 500,
and this suite exists to prevent exactly that.

### Covered — established by a real HTTP request

| Status | Operations | How |
| --- | --- | --- |
| `401` | **49** (all bearer operations) | Real requests, **twice each**: absent `Authorization`, then a well-formed-but-unknown Sanctum token. 98 real requests. |
| `200` | **17** | All 16 anonymous reads plus the public QR verifier. Key order, `meta` sibling position and pagination block asserted from raw bytes. |
| `422` | **19** | Four anonymous writes with an empty body, the public slot read missing `?tanggal=`, the 14 reference reads, and the malformed-date cases. |
| `404` | **2** | Public doctor reads with an absent id. |
| `500` | **1** (representative) | A registered probe route. Asserted not to leak the exception, the message, the class or a `.php` path while `APP_DEBUG=true`. |
| `403` | **0** | See below. |
| `429` | **0** | See below. |

### Not covered — and why, named

| Not covered | Operations | Why |
| --- | --- | --- |
| Success `2xx` body | 49 bearer operations | Reaching them needs a **real Sanctum token for a role-bearing account** and, for the clinical writes, seeded business data: a consultation, a party to it, a doctor row, an invoice. **This suite minted no role-bearing token for any role.** `pasien`, `dokter`, `apoteker`, `admin`, `superadmin` — none was minted. |
| `403` | all 49 | Same reason. A 403 needs a *valid* token for an account that lacks a grant, which is the mirror of the 401 case and cannot be reached anonymously. |
| `429` | 11 | Reaching it means exhausting a rate limit. F-002 and F-009 mounted the limiters, so eleven routes publish a 429; `tests/Feature/Security/RateLimitingTest.php` (todo 52) proves each ceiling through a probe and `tests/Feature/Security/RouteThrottlingTest.php` (F-002) reads the mounted set out of the route table and drives three real routes to a 429. Duplicating that here would be a third test asserting the same behaviour. |
| `500` per operation | 73 of 74 | Provoking a real 500 on each route would mean corrupting state deliberately. One representative route proves the envelope; it does not prove each controller sanitises its own faults. |
| `201` success | the 4 anonymous writes | A register/verify/refresh success is the full OTP flow, which is `tests/Feature/Auth/AuthFlowTest.php`'s subject, not this suite's. |

**The single largest honest gap: no authenticated success path in this suite at
all.** Every claim about a `2xx` body here comes from the 17 anonymous reads. A
reader who wants to know whether a bearer operation's success body matches its
published envelope must wait for a suite that mints role tokens.

---

## Findings

Seven were found. **Findings 1-4 are RESOLVED (F-007)**: the fault was in
`App\Support\OpenApi/OpenApiDocumentBuilder`, the generator was corrected, the
document regenerated, and the four blocks in
`tests/Contract/ContractDivergenceTest.php` now assert the corrected document
against LIVE responses. Each of those blocks therefore fails if the document is
hand-edited back or the generator regresses.

**Findings 5, 6 and 7 remain OPEN.** In each of those the document is the wrong
side, and their tests are tripwires: they pass while the defect exists and go red
the moment somebody corrects the generator without updating this file. Each names
the operation it affects, so the remaining live-response failures in the headline
table are exactly Finding 5's one operation and Finding 6's one.

### Finding 1 — RESOLVED: `SuccessEnvelope` forbade `meta` on 16 operations

Sixteen operations send a top-level `meta` while the document published a
three-key `SuccessEnvelope` for them. The generator chose the paginated envelope
only when the operation's `FormRequest` rules contained `page`/`per_page`, which
cannot distinguish "does not page" from "pages without being asked".

F-007 replaces that rule with the action's own source: an action calling
`ApiResponse::pageMeta()` or `ApiResponse::singlePageMeta()` publishes
`PaginatedEnvelope`. All sixteen now do, asserted live for the ten anonymous ones
in `ContractDivergenceTest`.

### Finding 2 — RESOLVED: `PaginatedEnvelope.data` is an object

`PaginatedEnvelope` typed `data` as an array while every paginated endpoint
answers a JSON **object** keyed by resource name — `{"dokter":[]}`,
`{"provinsi":[]}` — because each controller wraps its collection in a named key.
A client generated from the old schema read `List<dynamic>` and was handed a map.

F-007 publishes `data` as `{type: object, additionalProperties: true}`. The
twenty-nine operations now publishing `PaginatedEnvelope` are asserted in
`ContractDivergenceTest`, six of them against live anonymous responses.

### Finding 3 — RESOLVED: the chat-read write publishes the three-key envelope

`PaginatedEnvelope` requires `meta`, and `POST /konsultasi/{id}/chat/baca`
published it for its `201` while the controller sends no `meta` at all. It was
classified as paginated because its `TandaiDibacaRequest` is the one request body
carrying `page`/`per_page`.

F-007's source rule reads the action, not the body, so the write now publishes
`SuccessEnvelope`. Asserted as a code fact (reaching a real `201` needs a
consultation and a grant).

### Finding 4 — RESOLVED: `PaginatedMeta.per_page` floors at 0

`PaginatedMeta.per_page` declared `minimum: 1`, but `ApiResponse::singlePageMeta()`
sets `per_page` to the row count, so an empty single-page list truthfully answers
`per_page: 0` and its own schema rejected it. Reachable whenever a single-page
reference list is empty, which `RefreshDatabase` reproduces for `master_provinsi`.

F-007 lowers the floor to 0, and `ContractDivergenceTest` asserts the live empty
`GET /referensi/provinsi` validates.

### Finding 5 — the QR verifier needs an **unpublished** parameter and answers an **undocumented** 422

`GET /api/v1/surat-keterangan/{nomor_surat}/verify` publishes `200, 404, 500` and
one path parameter. It also requires `?token=`, and without it answers a **422 the
document does not list**, with an `errors.token` message no published schema for
that operation can describe.

A client generated from this document has no way to learn the parameter exists:
it is not in `parameters`, there is no `requestBody`, and no status hints at it.
The endpoint looks callable and is not.

The document is wrong: the controller's own docblock calls a missing token "the
one case that is not a verification", so the 422 is deliberate and the omission
is the defect.

### Finding 6 — the payment webhook is declared anonymous and answers an **undocumented** 401

`POST /api/v1/webhook/payment/{gateway}` publishes `security: []` and
`201, 404, 500`. An unsigned request answers a **401 the document does not list**.

`security: []` is accurate about the Sanctum token — there is none — but
misleading about **authentication**. The endpoint is guarded by an HMAC-SHA256
signature over the raw body, which is not expressible as an OpenAPI
`securityScheme` here, so the document has no way to say "authenticated by
signature". The result is an endpoint that reads as unguarded and is guarded.

The document is wrong: refusing an unverifiable signature with a 401 is correct;
failing to publish that is the defect.

### Finding 7 — 23 GETs publish a 422 with nothing describing what fails it

Every one of these publishes a `422` and no `parameters` and no `requestBody` — so
the 422 is advertised with nothing saying what triggers it. A generated client
cannot learn `?page=` exists, and therefore cannot know what a 422 there is
complaining about.

**Worse, on the eight non-paginating, non-searchable reference endpoints the 422
is reachable only by sending a parameter the endpoint explicitly REFUSES.**
Live-proven: `?q=<anything>` answers 422 with `Parameter "q" is not accepted by
this endpoint. Accepted: (none).`, while `?page=abc`, `?page=-1` and
`?per_page=0` all answer **200**.

So the published 422 on those eight has exactly one reachable cause, and it is a
client mistake the document never warned about.

The document is wrong on both counts. `IndexReferensiRequest` validates
`page`/`per_page` correctly for the *paginating* endpoints — `?page=abc` on
`/referensi/icd10` really does answer a correct 422 — but none of it is published.

---

## Deviations from the plan's todo 49 text

| Plan said | Done | Why |
| --- | --- | --- |
| Put the suite in `tests/Feature/ContractConformanceTest.php` | `tests/Contract/`, four files | `tests/Contract/` already existed from the interrupted attempt. Keeping it separate keeps the conformance run out of the 1152-test default suite, which matters because `phpunit.xml` could not be edited to register a third testsuite. |
| Validate with **`opis/json-schema ^2.4`** | `symfony/yaml` + a hand-written validator | `opis/json-schema` is **not** a dependency of this repository: no `opis/*` entry in `composer.json`, and `vendor/opis` does not exist. Installing it was not available to this todo. The replacement is a **closed, enumerated subset** (`$ref`, `type`, `const`, `enum`, `required`, `properties`, `additionalProperties`, `items`, `minItems`, `minimum`), and a test FAILS if the document ever uses a keyword outside it — so the subset cannot silently widen and turn the validator into a rubber stamp. |
| Every route must have a schema entry | Both directions, 74/74 | The plan's requirement was one-directional. One direction cannot see an *undocumented* route, which is the failure that silently starves a client. |
| Register a temporary route and show the suite fails | In-process, permanently | A permanently red suite gets disabled, which is worse than useless. The committed test registers the ghost route, asserts the detector reports it, and passes. The out-of-band RED transcript is in `.omo/evidence/task-49-sehatly.md`. |
| `composer contract` runs all three checks | Extended | The plan's `contract` script ran two drift checks. It now also runs this suite. |

---

## The suite is not vacuous

Two independent proofs, both in `.omo/evidence/task-49-sehatly.md`:

1. **In-process**: a ghost `/api/v1/contract-suite-ghost-route` is registered and
   the suite asserts the route→spec detector reports exactly
   `['get /api/v1/contract-suite-ghost-route']`.
2. **Out-of-band transcript**: two deliberately wrong inputs were introduced and
   the suite went **RED (10 failures)**, then green again after restore.

The out-of-band proof is the stronger one, and it has a property worth noting:
of the 10 failures, 8 were envelope assertions that failed *only* on the
operations whose `per_page` is 0. The suite was reading the live database, not a
hard-coded expectation.

### The reporter's key-omission trap

Pest's JSON reporter **omits the `failed` and `errors` keys entirely when the
count is zero**. A parser that treats a missing key as failure reads a clean run
as broken — and a harness that cries wolf on green trains everyone to ignore it.

The proof harness therefore asserts key presence before reading a count:

```
[GREEN CONTROL (unmutated)]
  reporter result field : "passed"
  key "failed" present? : false
  key omission          : the reporter OMITTED "failed" because the count is zero
  VERDICT               : GREEN
  control note          : a parser that treats the ABSENCE of "failed" as failure
                          reads this line as RED. It is green.
```

---

## Per-operation ledger

`Covered` is what a **real request** established. `structure only` would mean the
operation was read from the document but never driven — there are none.

Statuses are the document's, in its order. `Envelope` is the `$ref` published for
the `2xx`.

| Operation | Auth | Documented statuses | Envelope | Covered |
| --- | --- | --- | --- | --- |
| `GET /api/v1/auth/devices` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/auth/devices` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `DELETE /api/v1/auth/devices/{deviceId}` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/auth/login` | anonymous | `201,422,404,429,500` | SuccessEnvelope | 422 |
| `POST /api/v1/auth/logout` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/auth/otp/verify` | anonymous | `201,422,404,429,500` | SuccessEnvelope | 422 |
| `POST /api/v1/auth/refresh` | anonymous | `201,422,404,500` | SuccessEnvelope | 422 |
| `POST /api/v1/auth/register` | anonymous | `201,422,404,429,500` | SuccessEnvelope | 422 |
| `POST /api/v1/booking` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `PUT /api/v1/booking/{id}/batalkan` | bearer | `200,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/dokter` | anonymous | `200,422,404,500` | PaginatedEnvelope | 200 + 422 |
| `GET /api/v1/dokter/booking` | bearer | `200,422,401,403,404,500` | PaginatedEnvelope | 401 |
| `GET /api/v1/dokter/{dokter}` | anonymous | `200,404,500` | SuccessEnvelope | 404 |
| `GET /api/v1/dokter/{dokter}/jadwal` | anonymous | `200,404,500` | SuccessEnvelope | 404 |
| `GET /api/v1/dokter/{dokter}/slot` | anonymous | `200,422,404,500` | SuccessEnvelope | 422 |
| `POST /api/v1/invoice/{id}/bayar` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/konsultasi/mulai` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/konsultasi/{id}` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/konsultasi/{id}/chat` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/konsultasi/{id}/chat` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/konsultasi/{id}/chat/baca` | bearer | `201,422,401,403,404,500` | PaginatedEnvelope | 401 |
| `POST /api/v1/konsultasi/{id}/rekam-medis` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/konsultasi/{id}/resep` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `PUT /api/v1/konsultasi/{id}/selesai` | bearer | `200,422,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/konsultasi/{id}/surat-keterangan` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `PUT /api/v1/konsultasi/{id}/terima` | bearer | `200,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/master-spesialisasi` | anonymous | `200,404,500` | SuccessEnvelope | 200 |
| `GET /api/v1/me` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/notifikasi` | bearer | `200,422,401,403,404,500` | PaginatedEnvelope | 401 |
| `PUT /api/v1/notifikasi/baca-semua` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `PUT /api/v1/notifikasi/{id}/baca` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/obat` | bearer | `200,422,401,403,404,500` | PaginatedEnvelope | 401 |
| `GET /api/v1/obat/{id}/stok` | bearer | `200,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/pasien/alergi` | bearer | `200,422,401,403,404,500` | PaginatedEnvelope | 401 |
| `POST /api/v1/pasien/alergi` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `DELETE /api/v1/pasien/alergi/{id}` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `PUT /api/v1/pasien/alergi/{id}` | bearer | `200,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/pasien/anggota-keluarga` | bearer | `200,422,401,403,404,500` | PaginatedEnvelope | 401 |
| `POST /api/v1/pasien/anggota-keluarga` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `DELETE /api/v1/pasien/anggota-keluarga/{id}` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `PUT /api/v1/pasien/anggota-keluarga/{id}` | bearer | `200,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/pasien/booking` | bearer | `200,422,401,403,404,500` | PaginatedEnvelope | 401 |
| `GET /api/v1/pasien/profil` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `PUT /api/v1/pasien/profil` | bearer | `200,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/pasien/resep` | bearer | `200,422,401,403,404,500` | PaginatedEnvelope | 401 |
| `GET /api/v1/pasien/surat-keterangan` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/pdp/persetujuan` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/pdp/persetujuan` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/pesanan-obat/{id}` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/promo/validasi` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/referensi/agama` | anonymous | `200,422,404,500` | SuccessEnvelope | 200 + 422 |
| `GET /api/v1/referensi/enums` | anonymous | `200,404,500` | SuccessEnvelope | 200 |
| `GET /api/v1/referensi/golongan-darah` | anonymous | `200,422,404,500` | SuccessEnvelope | 200 + 422 |
| `GET /api/v1/referensi/hubungan-keluarga` | anonymous | `200,422,404,500` | SuccessEnvelope | 200 + 422 |
| `GET /api/v1/referensi/icd10` | anonymous | `200,422,404,500` | PaginatedEnvelope | 200 + 422 |
| `GET /api/v1/referensi/icd9cm` | anonymous | `200,422,404,500` | PaginatedEnvelope | 200 + 422 |
| `GET /api/v1/referensi/kabupaten-kota` | anonymous | `200,422,404,500` | PaginatedEnvelope | 200 + 422 |
| `GET /api/v1/referensi/kecamatan` | anonymous | `200,422,404,500` | PaginatedEnvelope | 200 + 422 |
| `GET /api/v1/referensi/kelurahan` | anonymous | `200,422,404,500` | PaginatedEnvelope | 200 + 422 |
| `GET /api/v1/referensi/metode-pembayaran` | anonymous | `200,422,404,500` | SuccessEnvelope | 200 + 422 |
| `GET /api/v1/referensi/pendidikan` | anonymous | `200,422,404,500` | SuccessEnvelope | 200 + 422 |
| `GET /api/v1/referensi/provinsi` | anonymous | `200,422,404,500` | SuccessEnvelope | 200 + 422 |
| `GET /api/v1/referensi/spesialisasi` | anonymous | `200,422,404,500` | SuccessEnvelope | 200 + 422 |
| `GET /api/v1/referensi/status-pernikahan` | anonymous | `200,422,404,500` | SuccessEnvelope | 200 + 422 |
| `GET /api/v1/rekam-medis/{id}` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `PUT /api/v1/rekam-medis/{id}` | bearer | `200,422,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/rekam-medis/{id}/amandemen` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `PUT /api/v1/rekam-medis/{id}/final` | bearer | `200,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/resep/{id}` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/resep/{id}/cek-interaksi` | bearer | `200,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/resep/{id}/checkout` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `POST /api/v1/resep/{id}/verifikasi` | bearer | `201,422,401,403,404,500` | SuccessEnvelope | 401 |
| `GET /api/v1/surat-keterangan/{nomor_surat}/verify` | anonymous | `200,404,500` | SuccessEnvelope | 200 |
| `POST /api/v1/webhook/payment/{gateway}` | anonymous | `201,404,500` | SuccessEnvelope | 401 (undocumented — Finding 6) |

---

## What would close the gaps

1. **Fix the generator.** Findings 1–4 and 7 are one defect: the envelope is
   chosen from whether the request has a `page` rule rather than from whether the
   response carries a meta block. The generator can read that from the
   `ApiResponse` call, the same way it already reads `FormRequest::rules()` from
   the controller signature. Nothing else has to change for all seven findings.
2. **Publish query parameters.** `page`, `per_page`, `q`, `tanggal` and `token`
   are all validated and none is published. That is Finding 7 and Finding 5.
3. **Model the webhook signature** as an OpenAPI `securityScheme`, or publish the
   401. That is Finding 6.
4. **Mint role tokens** for `pasien`, `dokter` and `apoteker`, which would close
   the 49-operation `2xx`/`403` gap. That is the largest remaining hole and the
   only one that needs application logic rather than a documentation fix.