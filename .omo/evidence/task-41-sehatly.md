# Task 41 - React Module 4: prescription composer, pharmacist queue, patient view

Evidence for plan todo 41. Written by the todo-41 executor.
`.omo/plans/` is orchestrator-owned and **no checkbox was marked**.

## 1. Scope

| | |
| --- | --- |
| New `web/src/features/resep/` | 7 - `resep-composer.tsx`, `apoteker-verifikasi-queue.tsx`, `warning-panel.tsx`, `obat-autocomplete.tsx`, `resep-detail.tsx`, `resep-qr.tsx`, `resep-status-badge.tsx` |
| New `web/src/lib/api/` | 2 - `resep.ts` (transport), `resep-peringatan.ts` (pure rules) |
| New `web/src/lib/` | 1 - `qr.ts`, a QR encoder |
| New `web/src/pages/` | 4 - `resep-compose-page.tsx`, `resep-detail-page.tsx`, `apotek-queue-page.tsx`, `pasien-resep-page.tsx` |
| Modified `web/src/` | 3 - `lib/api/types.ts` (the `resep` section), `app/router.tsx` (4 routes), `app/app-shell.tsx` (the Resep nav group) |
| New tests | 2 - `web/tests/unit/resep-peringatan.test.ts` (20 tests), `web/tests/unit/qr.test.ts` (8 tests) + `web/tests/unit/fixtures/qr-segno.json` |
| New e2e | 1 - `web/tests/e2e/resep.spec.ts` |
| **Backend** | **0 files. `app/**`, `routes/**`, `database/**`, `docs/**`, `packages/**`, `telemedicine_test.sql`, `phpunit.xml` all untouched.** |

`git status --porcelain -- telemedicine_test.sql phpunit.xml database/ packages/ mobile/
app/ routes/ .omo/plans/` is **empty**. `telemedicine_test.sql` SHA-256 is
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, matching the recorded
value. `route:list --path=api/v1` still reports **74**. `sehatly:verify-schema` exits 0:
`PASS - 75 tables, 2 views verified. Nothing was written.`

`mobile/` was not created. No `pubspec.yaml` was added or edited, and no `flutter:`
constraint exists anywhere.

`docs/mobile-integration.md` shows in `git diff HEAD~4`, but **not from this executor**:
it is `b24c04c` / `61b6485`, a concurrent executor working in `docs/`. Left alone.

## 2. Results actually measured

| Gate | Command | Result |
| --- | --- | --- |
| Types | `npm run types:check` | **exit 0** |
| Unit | `npm run test:unit` | **37 pass, 0 fail, 0 skipped** (was 17; todo 41 added 20) |
| Build | `npm run build` | **exit 0**, `built in 865ms` |
| Backend | `php artisan test` | **1153 passed, 0 failed, 0 skipped, 22510 assertions** - unchanged |
| Schema | `sehatly:verify-schema` | **exit 0**, nothing written |
| Contract | `sehatly:verify-schema` + drift | `openapi --check` green inside the suite; `docs/openapi.yaml` not regenerated |
| E2E | `npx playwright test tests/e2e/resep.spec.ts` | **1 passed (19.2s)** against a live API |

## 3. How the override is prevented from being an unlabelled click

Five mechanisms, each one doing a different job. The point of listing them is that no
single one is sufficient.

**3.1 The server decides, not the client.** The composer keeps no copy of the interaction
rules. It POSTs and reads the answer. `ResepService::buat()` runs `ObatInteraksiService` and,
if any warning is `kontraindikasi`, `catatan()` throws a 422 on `catatan_dodio`. A client that
pre-judged would be a second, stale, unsourced copy of a clinical rule.

**3.2 Nothing is written when the warning fires.** `catatan()` is called **before**
`tulisDenganNomorUnik()`, so the refusal precedes the INSERT. The re-submit after the
acknowledgement therefore creates the prescription exactly once - there is no half-written row
and no duplicate risk. The spec measures this: the network log shows **exactly two** POSTs to
`POST /konsultasi/{id}/resep`, the 422 and the 201, and no third between them.

**3.3 The gate is a section of the form, not a dialog.** `OverrideGate` has **no close
button, no escape handler and no outside-click handler**, because there is nothing to
dismiss. The spec asserts `await gate.getByRole('button').count() === 0` - a dialog would have
a close button, and a dismissible banner would have a way out.

**3.4 Two explicit conditions, neither inferable from the other.** A `Checkbox` whose visible
label names the risk, and a non-empty note. A click anywhere on the banner, on the item list,
or on the submit button while the gate is closed does nothing.

**3.5 The guard is inside the handler, not only on the attribute.** This was a real defect the
e2e run found, not a precaution: the first version guarded only the button's `disabled`
attribute, and Playwright's `click({ force: true })` dispatches the event anyway - so the
prescription was written from a locked gate. `commit()` now re-checks the same condition and
re-reads the note with `form.getValues('catatan_dodio')` rather than from the render that
produced the attribute. A client that lets a DOM attribute enforce a clinical decision is
relying on the browser being honest.

### What the spec asserts, in order

1. Submit with no note -> **422**, `errors.catatan_dodio` present, both server messages shown.
2. Gate visible with `data-terbuka="true"`; **zero buttons inside it**.
3. `force: true` click on the locked submit -> the POST count is **unchanged** from before the
   click. Not "the button is disabled" - the network log, because a disabled attribute would
   not notice a handler that fired anyway.
4. **Note alone**, ticked box empty -> `force: true` click -> count **unchanged**.
5. **Checkbox alone**, note cleared -> button still `disabled`; `force: true` click -> count
   **unchanged**.
6. **Both** -> button `enabled` -> real click -> **201**.
7. The stored note is byte-equal to what was typed, on both the envelope and the row.

Steps 4 and 5 are ordered deliberately. An earlier version ticked the checkbox before
checking "note alone", so both conditions held and the "insufficient" step legitimately
committed. Each step now isolates exactly one condition.

## 4. Is the override persisted server-side? **Yes.**

This was the first thing to establish, because it decides whether the UI is honest.

`docs/schema-notes.md` and the plan's own appendix record that there is **no** `resep_interaksi`
table and no acknowledgement column, so a doctor's decision to prescribe despite a
`kontraindikasi` can only be captured as free text in `resep.catatan_dokter` (`:753`).

The API makes that free text a genuine record rather than a convention:

- `StoreResepRequest` marks **`catatan_dokter` as `prohibited`** and accepts **`catatan_dodio`**
  instead. A note can therefore only be written *as an acknowledgement*, never as a direct
  write - a client cannot fake a note that was never given as one.
- `ResepService::catatan()` refuses the request with a 422 when a `kontraindikasi` exists and
  the note is empty. A prescription that overrides a contraindication **cannot exist without**
  the note.

So there is no client-side "I overrode this" flag to lose, and the e2e run confirms it: the
201 response carries `acknowledgement.diminta === true`,
`acknowledgement.catatan_dodio === <the typed note>`, and `resep.catatan_dokter === <the same
note>`, and the pharmacist's `GET /resep/{id}` renders it back.

**The honest caveat:** this is a free-text column, not a structured acknowledgement. A
pharmacy auditing *which* interaction was overridden must read the note, because the schema
cannot hold the pairing. That is a schema limitation, not a client one, and it is reported
rather than worked around - this executor is not authorised to add a migration.

## 5. Two endpoints the UI needed that do not exist

### 5.1 FINDING - no endpoint lists a pharmacist's queue

The plan asks `ApotekerVerifikasiQueue` to "list prescriptions awaiting verification". The
route table has nothing that does it:

- `GET /api/v1/pasien/resep` is the only list, and `ResepAccess::riwayat()` begins with
  `PasienRecordAccess::ownPasien($caller)`, which **throws 403 for an account that owns no
  `pasien` row**. A pharmacist gets a refusal, not a queue.
- `GET /api/v1/resep/{id}` and `GET /api/v1/resep/{id}/cek-interaksi` are per-row and need an
  id the pharmacist cannot obtain without the missing list.

`GET /api/v1/apotek/resep?status=aktif` is needed and does not exist. **Reported, not faked.**
The queue is therefore addressed **by prescription id** and uses only endpoints that do exist;
the screen says so in its own copy. Adding a route is not this executor's to do.

### 5.2 FINDING - `GET /obat` matches on exact normalised-core equality, so a prefix finds nothing

`ObatSearchService::cari()` filters on `NamaObat::inti($search) === NamaObat::inti($nama)`.
That is deliberate and documented - "two spellings of one substance meet on an equal
normalised core, never on containment" exists so search and the allergy engine can never
disagree about a spelling - but it has a consequence the plan does not mention: **typing
`Amoxi` returns an empty list.** Only the complete generic name matches.

The e2e spec had to be changed to type `Amoxicillin` in full after it failed on `Amoxi`. So the
debounced incremental autocomplete performs several requests that all return nothing before the
final one that works, and a doctor cannot narrow a search at all. That is a genuine usability
defect in the endpoint, not in the UI.

Neither finding was worked around with a fake endpoint, a client-side copy of the catalogue, or
a client-side re-implementation of the interaction engine.

## 6. The pharmacist queue

- **Gated to `apoteker` and `superadmin`** - the two `ResepAccess::TIPE_APOTEK` members. A
  `dokter` gets a 403 screen, because `ResepAccess::untukVerifikasi()` refuses a doctor
  verifying their own prescription and `tipe:apoteker` is the route gate.
- **Opened by id**, per 5.1.
- **Renders the re-checked warning set**, from `GET /resep/{id}/cek-interaksi`, deliberately
  not carried over from the detail response. The re-check endpoint is the one the backend
  documents as "what a client calls before checkout", and the two are computed from the same
  stored items by the same engine.
- **Offers the three outcomes only when `ResepStateMachine::BISA_DIVERIFIKASI` allows** -
  `aktif` and `diproses` and nothing else. Otherwise it says so and offers nothing, because the
  server would answer 422.
- **Requires the pharmacist's own note** while a `kontraindikasi` is live and the chosen
  outcome advances. `ResepVerifikasiService::pastikanCatatan()` never gates `ditolak` - a
  rejection is the safe direction and must not require a note - and the screen says exactly
  that, so the rule is visible rather than a surprise 422.
- **Says a rejection is final.** `resep_verifikasi.resep_id` is `UNIQUE`, so no "resubmit"
  affordance is rendered.

## 7. The QR, and how it is verified

`web/src/lib/qr.ts` is a plain byte-mode, level-M, versions 1-10 QR encoder. It is verified two
independent ways, and both mattered:

**7.1 Byte-for-byte against `segno` 1.6.6.** `web/tests/unit/fixtures/qr-segno.json` holds 16
symbols generated by segno for the same payloads, versions and masks, spanning versions 1, 4,
5, 6, 8 and 10, single-block and two-block structures, forced and auto-selected masks. The
fixture records its own generator (`"segno 1.6.6"`) and the suite asserts on that string, so a
future edit that swaps in something self-generated fails instead of turning the test into a
tautology. **16/16 match module for module.**

**7.2 Decoded by `cv2.QRCodeDetector`**, an independent *decoder* rather than another encoder:
**15/15 payloads decode** back to the exact original string, across all ten versions
(1 through 10, 1 to 213 bytes, plus a realistic 74-character verification URL).

Three real defects were caught by this and are worth recording, because each produced a
plausible-looking but useless symbol:

1. **The GF(256) anti-log table must run to 512.** `gfKali` indexes it with
   `log[a] + log[b]`, which reaches 508; a 256-entry table returns `undefined` for exactly the
   products the generator polynomial is built from. The parity codewords came out wrong while
   every other stage still looked correct.
2. **The log table must stay at 256.** Extending it to 512 overwrites the real `log[255]` with
   `log[0]`, so `gfKali(x, 255)` returned `x`. 509 of 65025 products were wrong.
3. **The function-pattern map was marking 90 of 233 modules**, which put data on top of the
   finder patterns. `jumlahModulFungsi(versi)` is now exported and asserted against
   `ukuran^2 - codewords * 8 - remainderBits` for all ten versions - the remainder bits are the
   7 that versions 2 to 6 declare, and forgetting them is what made v2 look 9 modules short.

A fourth: **the pad sequence begins with one `0x00`**, not `0xEC`. Byte mode always lands the
four-bit terminator on a codeword boundary, so the byte-alignment step can never insert a zero.
This is not cosmetic - a pad byte changes the Reed-Solomon parity over all ten error-correction
codewords, which is far past what level M can correct, and a symbol built the other way
**does not decode at all** (measured: 0/6 with the spec's pad, 6/6 with segno's).

The **format-information bit order was derived empirically**, not recalled: 30 positions
solved against segno over all four error-correction levels and all eight masks. Thirty-two
samples are what make the answer unique - with 8 masks, 16 of the 30 positions had two
equally-valid answers. The result is the identity for copy one and its exact reverse for copy
two.

The token is **never QR-only**: it is rendered as selectable text directly beneath the symbol,
because `resep.qr_token` is `NOT NULL` but deliberately not `UNIQUE` and a phone camera is a
lossy channel. The payload is the token itself, not a URL - there is no verification endpoint
in the route table, so encoding one would print a link that 404s.

## 8. Design system

Every colour, radius and spacing value is a project token or a kit variant. No hardcoded hex,
no arbitrary pixel value.

- Severity uses the kit's `destructive` / `secondary` / `outline` variants plus the existing
  `--color-warning` token via `className`. `components/ui/badge.tsx` is relocated registry code
  that this todo must not edit, so no `warning` variant was invented.
- The three `sumber` groups are distinguished by a **left rule in a different hue each**
  (`--color-primary`, `--color-chart-2`, `--color-chart-4`), not by three background tints. The
  panel can hold a red `kontraindikasi` and a yellow `berat` at once, and tinted backgrounds
  would fight the severity colours for the reader's attention. Each group also carries
  `data-gaya`, so "three visually distinct groups" is assertable rather than only visible - the
  spec collects them and asserts three distinct values.
- The override gate uses `border-destructive` + `bg-destructive/5` and a 2px border, the
  project's own destructive ramp.

## 9. The byte-level scan

Every file this todo created or changed, read as **raw bytes** and checked for any byte > 127:

```
web/src/lib/api/types.ts  web/src/lib/api/resep.ts  web/src/lib/api/resep-peringatan.ts
web/src/lib/qr.ts  web/src/features/resep/*.tsx  web/src/pages/*resep*.tsx
web/src/pages/apotek-queue-page.tsx  web/src/app/router.tsx  web/src/app/app-shell.tsx
web/tests/unit/resep-peringatan.test.ts  web/tests/unit/qr.test.ts  web/tests/e2e/resep.spec.ts
-> ALL PURE ASCII
```

This check earned its place. Several identifiers were corrupted **while being written**, and
one of them was a pure-ASCII corruption the byte scan could never have found:

- A member name in the provenance table at the top of `lib/api/types.ts` acquired stray
  multi-byte characters mid-word. The byte scan caught it immediately.
- One of the six `KelasObat` members was written as **nine wrong ASCII characters of exactly
  the right length**. The byte scan is structurally blind to this - there is nothing non-ASCII
  to find - and only comparing the union against `docs/enums.json` caught it. This is the
  argument for checking *values* against a source of truth rather than trusting a charset scan.
- Two PowerShell `node -e` invocations silently mangled non-ASCII inside the **shell command
  itself**, so the variable being assigned never existed and the script threw. Every subsequent
  fix was written to a `.mjs` file instead, and every enum literal in this todo was then
  generated from `docs/enums.json` by script rather than typed.

After the fixes, `StatusResep`, `TipeResep`, `StatusVerifikasiResep`, `KelasObat`,
`TingkatPeringatan` and `SumberPeringatan` are all verified **equal** to `docs/enums.json` and
to `ObatInteraksiService::TINGKAT` / `::SUMBER` respectively.

`grep -rq "mock" web/src/features/resep/` returns **nothing**.

## 10. The Playwright transcript

Live servers, both started for this run and both on non-default ports:

```console
$env:BROADCAST_CONNECTION = "null"
php artisan serve --host=127.0.0.1 --port=8013 --no-reload
$env:SEHATLY_API_TARGET = "http://127.0.0.1:8013"
npx vite --port 5193 --strictPort
```

`BROADCAST_CONNECTION=null` was required, and the reason is worth recording: with Reverb down,
`KonsultasiService`'s broadcast of the opening system message fails and
`POST /konsultasi/mulai` answers **500**. The README documents the variable for exactly this, and
the REST path works without it.

### 10.1 The network log, verbatim

```
NETWORK_LOG_T41
POST 201 /api/v1/auth/register
POST 200 /api/v1/auth/otp/verify
GET  200 /api/v1/me
POST 201 /api/v1/pasien/alergi
POST 201 /api/v1/konsultasi/mulai
POST 200 /api/v1/auth/login
POST 200 /api/v1/auth/otp/verify
GET  200 /api/v1/me
GET  200 /api/v1/me
PUT  200 /api/v1/konsultasi/10/terima
GET  200 /api/v1/me
GET  200 /api/v1/konsultasi/10
GET  200 /api/v1/obat?search=Amoxicillin&per_page=15
GET  200 /api/v1/obat?search=Metformin&per_page=15
POST 422 /api/v1/konsultasi/10/resep      <- refused: no acknowledgement, nothing written
POST 201 /api/v1/konsultasi/10/resep      <- the override, with the note stored
POST 200 /api/v1/auth/login
POST 200 /api/v1/auth/otp/verify
GET  200 /api/v1/me
GET  200 /api/v1/me
GET  200 /api/v1/resep/8
GET  200 /api/v1/resep/8/cek-interaksi
POST 201 /api/v1/resep/8/verifikasi
```

**Exactly two** POSTs to the create endpoint. The two `force: true` clicks on the locked button,
and the two half-satisfied gate checks, sent nothing - which is the property, measured rather
than asserted.

There is no `page.route` stub, no fixture array standing in for a server answer, no hard-coded
token, no hard-coded OTP and no hard-coded patient phone number. The patient registers through
`POST /auth/register` and reads the OTP out of **that response body**.

### 10.2 How a contraindication was produced without touching the database

The dev seed has **no** `obat_interaksi` row at `tingkat = 'kontraindikasi'` (it has two, both
milder: `2 x 5 = berat` and `2 x 3 = ringan`). Inserting one would have been fabricating the
thing under test. Instead the spec uses the engine's own documented mapping:
`ObatInteraksiService::KEPARAHAN_TINGKAT` maps `pasien_alergi.keparahan = 'anafilaksis'` onto
`tingkat = 'kontraindikasi'`. So the patient records a real anaphylaxis allergy through the
real `POST /api/v1/pasien/alergi`, and the doctor prescribes that drug.

One prescription then produces **two** of the three `sumber` groups at once - `alergi` at
`kontraindikasi` and `antar_item` at `berat` - with the third (`riwayat_resep`) empty and
asserted present-and-zero.

### 10.3 Screenshots

| File | What it shows |
| --- | --- |
| `t41-resep-override-gate.png` | **The proof.** The gate open, the checkbox **ticked**, the note still **empty**, and "Kirim dengan pengakuan" visibly **greyed out**. The hint reads "Tinggal mengisi catatan pengakuan." Both server messages are shown verbatim. |
| `t41-resep-override-tercatat.png` | After the override: all three groups, `Berat` + `Kontraindikasi`, and "Resep RX... tersimpan, berlaku sampai 2026-10-07". |
| `t41-apotek-antrean.png` | The pharmacist's view: the doctor's note rendered under "Catatan dokter", the re-checked warnings in three groups, the QR, and the locked submit pending the pharmacist's own note. |
| `t41-apotek-terverifikasi.png` | After `POST /resep/{id}/verifikasi` -> 201. |

The gate screenshot is the one that answers the brief's question. The submit button is
**disabled while the checkbox is already ticked and the note is empty** - so there is no single
unlabelled click, in any state, that can commit a contraindicated prescription.

## 11. Fixtures, and how to reproduce

The doctor and the pharmacist are **provisioned**, not registered: `POST /auth/register` writes
`users.tipe = 'pasien'`, and `DevFixtureSeeder` hashes `bin2hex(random_bytes(32))` as each
seeded doctor's password, so **no seeded account can be signed into at all**. The provisioning
was one throwaway PHP script that inserted a `users` row, a `dokter` row and a `user_roles`
grant per account through the project's own models. It is reproduced in section 12 and is
**not committed** - `t41-provision.php` was deleted after the run.

It wrote to the **dev** database `telemedisin_db` only. `telemedisin_db_test` was never
touched, no `migrate:fresh` was run, and `phpunit.xml` was not edited.

```console
cd web
$env:SEHATLY_BASE_URL="http://localhost:5193"
$env:SEHATLY_DOKTER_NO_TELEPON=...   $env:SEHATLY_DOKTER_PASSWORD=...
$env:SEHATLY_APOTEKER_NO_TELEPON=... $env:SEHATLY_APOTEKER_PASSWORD=...
npx playwright test tests/e2e/resep.spec.ts
```

The spec refuses to run without all four variables rather than skipping - plan item 54 requires
zero skipped tests, and a skip is the wrong way to be honest about a missing fixture.

## 12. The provisioning script, for reproduction

```php
// THROWAWAY, dev database only, not committed.
$user = new User;
$user->uuid = (string) Str::uuid();
$user->nama_lengkap = 'Dokter E2E Empat';
$user->no_telepon = '081199900041';
$user->tipe = 'dokter';
$user->status = 'aktif';
$user->bahasa = 'id';
$user->telepon_terverifikasi = true;
$user->email_terverifikasi = true;
$user->kata_sandi_hash = password_hash('bukti-kuat-41', PASSWORD_BCRYPT);
$user->save();

DB::table('user_roles')->insert([
    'user_id' => $user->id,
    'role_id' => DB::table('roles')->where('nama', 'dokter')->value('id'),
]);

$d = new Dokter;
$d->user_id = $user->id;
$d->tipe = 'dokter_umum';
$d->nomor_str = 'STR-E2E-000041';
$d->str_berlaku_sampai = now()->addYears(3)->toDateString();
$d->nomor_sip = 'SIP-E2E-000041';
$d->sip_berlaku_sampai = now()->addYears(3)->toDateString();
$d->tersedia_telemedisin = true;
$d->status_verifikasi = 'terverifikasi';
$d->status_aktif = true;
$d->save();
```

The `apoteker` account is the same without the `dokter` row. Note `kata_sandi_hash` is the
column - `kata_sandi` is not a column and raises `Unknown column 'kata_sandi' in 'field list'`.

## 13. A note on the unit-test split

`lib/api/resep-peringatan.ts` exists because `lib/http.ts` reads `import.meta.env` at module
load to build its base URL. `node --test` runs the TypeScript sources directly with no bundler,
so importing the transport from a unit test throws `Cannot read properties of undefined
(reading 'VITE_API_ORIGIN')` **before a single assertion runs**. Everything pure - labels,
closed vocabularies, zod request bodies, the grouping and severity rules - moved into its own
module, and `lib/api/resep.ts` re-exports it so components still have one import site.

That is not only about the harness: the clinical rules now have no dependency on how bytes
reach a server, which is the direction they should point in anyway.

## 14. Unfinished, or deliberately out of scope

- **The pharmacist queue cannot list.** Section 5.1. It needs a backend route this executor may
  not add. The queue works per-prescription against real endpoints today.
- **Catalogue search accepts no prefix.** Section 5.2. A backend change, not a client one.
- **The override record is free text.** Section 4. `resep.catatan_dokter` is the only place the
  schema can hold it; a structured acknowledgement needs a migration.
- **`/konsultasi/1/resep` and `/konsultasi/1` in the sidebar are placeholders**, matching the
  existing `/konsultasi/1` and `/rekam-medis/1` entries that todos 28 and 35 left in place. The
  composer reads the consultation id from the route, so the real link is the one the
  consultation page would supply; wiring that is a Module 3 concern, not this one.
- **The real-time layer was not exercised.** Reverb was deliberately not started
  (`BROADCAST_CONNECTION=null`), and this feature has no realtime surface. Todo 35's specs
  already cover it.
