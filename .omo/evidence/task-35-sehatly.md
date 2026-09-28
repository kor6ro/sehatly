# Task 35 - web: realtime consultation chat, SOAP form, medical record

Executor report. HEAD on arrival `1ced91a`; tree clean apart from two untracked paths
that are not this task's (`.omo/evidence/task-3-sehatly.md`, `.playwright-mcp/`).

## What the previous executor left, and what it got wrong

Twenty files were already committed. The realtime core (`socket.ts`,
`konsultasi-realtime.ts`, `dedupe.ts`), the transcript merge, the envelope/decimal/
pagination plumbing, and the access-log discipline were **correct and I kept them**.
Four defects were real, three of them in shipped application code.

### 1. `soap-form.tsx` label did not match the spec or the other two forms - FIXED

`LABEL_SOAP.catatan_asessment` was `'Asesment (A)'`. `rekam-medis-view.tsx:112` and
`rekam-medis-edit-form.tsx:303` both said `'Asesmen (A)'`, and the spec drove
`getByLabel('Asesmen (A)')`. Playwright's `getByLabel` is substring-based, and
`"Asesmen (A)"` is **not** a substring of `"Asesment (A)"` (the character after `n` is
`t`, not a space), so the SOAP e2e test failed on a label while asserting nothing about
the form. Corrected to `'Asesmen (A)'`; the in-file comment claiming the label "is the
DDL's spelling too" was false on both counts and was corrected with it - the DDL's
spelling lives in the wire key `catatan_asessment` ("asessment", two `s`).

### 2. `rekam-medis-view.tsx` chain-head badge could never be lit - FIXED

`<VersiBadge versi={entri.versi} terbaru={entri.versi === 255} />`. `255` is
`TINYINT UNSIGNED`'s ceiling, not a version, so the condition held only for a chain 255
amendments deep. The one badge separating the current document from its superseded
ancestors was permanently off. Now derived the way `RekamMedisResource` derives
`adalah_versi_terkini`: the entry with the highest `versi`.

### 3. The amendment form was unreachable on a signed record - FIXED

`rekam-medis-edit-form.tsx` gated the field set on `!draft`. An amendment is only ever
offered on a **signed** record, so `draft` is false by definition in amendment mode:
clicking "Ajukan amandemen" swapped the fourteen fields for the read-only paragraph and
left "Konfirmasi kirim sebagai amandemen" wired to a form that was not on screen. Gate
is now `!draft && mode !== 'amandemen'`. Confirmed by the spec, which then got past
`getByLabel('Asesmen (A)').fill(...)` for the first time.

### 4. The spec's test budget was smaller than its own waits - FIXED

`tungguReverbHidup()` polls 60 x 500 ms, a full 30 s, against Playwright's 30 s default
- unwinnable on the worst legal path. Under `mode: 'serial'` the failure also skipped the
two tests after it, so a timeout was reported as "the SOAP and medical-record tests did
not run". `test.setTimeout(240_000)` on the outage test. Once fixed, the SOAP test ran
for the first time and exposed defect 5.

### 5. The spec never accepted the consultation, so the SOAP note could not be written

`POST /konsultasi/mulai` leaves the row in `menunggu_dokter`;
`KonsultasiService::selesai()` has no edge from there to `selesai` and `mulai_at` is
still null (`terima()` is its only writer). Both guards are collected before either
throws, so the server answered **one 422 carrying `errors.status` AND
`errors.mulai_at`** - the correct multi-field refusal, which the spec reported as "the
SOAP form does not save". Added `terimaKonsNyamta()` calling the real `PUT /terima`.
The UI exposes no accept control, so the call is a bare `fetch` inside the doctor's
page reusing the bearer the app already holds, exactly as `mulaiKonsultasi` does.

## The dedupe key, and why it is the only correct choice

**`konsultasi_chat.id`**, the row's own primary key.

Verified, not assumed. `KonsultasiController::siarkan()` passes
`(new KonsultasiChatResource($pesan))->resolve($request)` into
`KonsultasiMessageSent::dispatch()`, and `broadcastWith()` returns `$this->message`
verbatim. The socket body is therefore the **same resolved array** as `data.pesan` in
`GET /chat` and in `POST /chat`, so `id` is the same number from the same row on both
transports. A key built from any other field would suppress nothing while looking like
it worked: `konsultasi_id` collapses every message in one transcript; `isi` +
`terkirim_at` loses a genuine repeat of the same sentence in the same second
(`terkirim_at` is one-second resolution, so a burst ties); `pengirim_user_id` collapses
both parties.

## The tests

### Dedupe across both transports - `tests/unit/realtime-reconnect.test.ts`

`gabungTranscript()` is the render-level gate (a REST page is not a *delivery*, so
`MessageDedupe` cannot gate it). Both arrival orders are asserted, which an e2e spec
cannot do because the frame and the refetch race and only one is ever observed:

```
a row delivered by BOTH transports renders exactly once, in either arrival order
  gabungTranscript(rest=[1,2], live=[2,3]) -> [1,2,3]
  gabungTranscript(live=[2,3], rest=[1,2]) -> [1,2,3]
two genuinely different messages are both rendered, however identical their text
  [11, 12] with identical isi and terkirim_at -> both kept
```

### Reconnect: subscribe -> messages -> drop -> recover -> messages

Driven through a **fake transport** - a 20-line `RealtimeSocket` with no port open and
no broker. It is the protocol's own seam (the same one `packages/sehatly_api_client`
uses on the Dart side) and is **not** a mock of the API: nothing stands in for a server
answer, no HTTP is faked, no payload canned, no route intercepted. Only the WebSocket is
replaced, because it is the one component whose failures are nondeterministic.

The test fixes the order the e2e spec cannot:

- `subscribePulse` -> message 3 live (1, 2 seeded from REST)
- drop -> every subscription drops to `pending` (a `confirmed` state over a dead socket
  is a lie, and a check waiting for `confirmed` after a drop would pass instantly)
- messages 4 and 5 are written while down: no frame arrives, which is why a resync exists
- recover -> **re-subscribe asserted BEFORE resync** by comparing positions in a shared
  operation log; exactly one re-subscribe, `attempts === 2`
- backfill returns `[1,2,3,4,5]`

```
delivered            [3, 4, 5]        gap delivered, overlap suppressed
resyncCount          1
duplicateSuppressed  3                ids 1, 2, 3 withheld
rendered transcript  [1, 2, 3, 4, 5]  every message once: nothing lost, nothing doubled
```

**Mutation-checked.** Disabling the dedupe (`if (this.seen.has(id))` -> `if (false)`)
makes it fail with `actual: [3,1,2,3,4,5]` against `expected: [3,4,5]` - precisely the
duplication the test exists to prevent. Reverted via `git checkout`.

Also covered: a backfill is DELIVERED while a history page is SEEDED (they are
opposites, and conflating them loses the gap); a refused subscribe is surfaced and
marked `refused`; `disconnect()` keeps the subscription registry and the dedupe set.

### Making that testable

`node --test` runs the sources with no bundler, so only import-clean modules were
reachable - the wrong constraint. `tests/unit/alias-hooks.mjs` maps the `@/` alias from
`tsconfig.json` via `module.registerHooks`; `lib/realtime/channel.ts` (naming rules) and
`lib/realtime/transcript.ts` (the merge) are new dependency-free leaves so
`konsultasi-realtime.ts` can load without dragging in `laravel-echo` and
`import.meta.env`. `socket.ts` re-exports the three channel symbols, so no other
importer changed. `tsconfig.json` was NOT modified.

## The auth payload

The brief said `echo.ts` omits `authEndpoint`. **It does not** - line 108 reads
`authEndpoint: BROADCAST_AUTH_ENDPOINT`. Inherited and correct; verified the derivation
rather than repeating it:

```
$ php artisan route:list -v --path=broadcasting
 GET|POST|HEAD api/broadcasting/auth .. Broadcasting > BroadcastController@authenticate
 | api
 | Illuminate\Auth\Middleware\Authenticate:sanctum
```

**Sanctum bearer, and no CSRF token exists to send.** The `api` group is the stateless
one `withRouting(apiPrefix: 'api/v1')` builds, so there is no `VerifyCsrfToken`;
`auth:sanctum` is satisfied by `Authorization: Bearer <token>` alone. Confirmed on the
wire before the spec ran:

```
auth WITHOUT bearer: HTTP 401
```

and per run, exactly one authorised call per context:

```
BROADCAST_AUTH_CALLS
POST 200 /api/broadcasting/auth
POST 200 /api/broadcasting/auth
```

`auth.headers` is mutated per subscribe rather than using `options.bearerToken`, which
`Connector.setOptions()` reads once from the constructor and never again.

## The access-log interaction - my UI does NOT fetch per render

`RekamMedisAccessLogger::baca()` writes exactly one `akses_rekam_medis_log` row per
completed read, and the write is what opens the read scope.

**Correction to the brief:** the log is *not* written by a model `retrieved` event.
`GuardsMedicalRecordRead` registers a `retrieved` listener, but it is a read-scope
**guard** - it refuses to hydrate a row that was not opened through the logger. Putting
the log there would fire on every eager load of the four child collections. The logging
is explicit in `baca()`/`untukTulis()`. The UI obligation is the same either way.

No fetch-per-render, and this is structural rather than incidental:

- `queryKey` is `['v1','rekam-medis', id]` and nothing else - a key containing a fresh
  `Date.now()` would be a read per render and a log row per render;
- one `useQuery` for the whole document: `RekamMedisResource` publishes the record, the
  four child collections and `ran` in a single response, so there is nothing to fan out;
- the read view and the edit form are never both mounted;
- `refetchOnWindowFocus: false` project-wide, so a tab-switch cannot manufacture a row;
- `konsultasi-page.tsx` never fetches a medical record at all.

**One honest exception, not fixed.** `amandemenRekamMedisMutation` caches under the NEW
row's id while the page renders the PARENT's id, and its own docblock says "refetching
is therefore not optional here, and this mutation does it" - which the code does not do.
So after an amendment the doctor keeps seeing the pre-amendment document until a manual
refetch. I tried caching under both keys; that is *worse*, because the amendment
response carries no `ran` and the chain then renders zero entries. Reverted to the
inherited behaviour rather than commit an unproven regression. Correct fix is to
navigate to the amended head, which needs a real refetch - see the gap list.

## The two-context exchange - PASSED, twice

Live Laravel + live Reverb + live Vite, all booted and torn down inside one invocation
(the harness reaps background processes *between* invocations, which is what stalled the
previous executor; the script never has to survive a boundary).

```
ok 1  a two-context exchange over Reverb, deduped across both transports (15.2s)
  x 2  a Reverb outage degrades to REST and recovers without loss or duplication (41.2s)
  1 passed (58.9s)
```

Patient and doctor each register/sign in through the real endpoints, the OTP is read out
of the live response body (`APP_ENV=local`), and the patient's message is asserted
`toHaveCount(1)` on both screens after the refetch has landed and again after a 2.5 s
settle - then keyed on `[data-pesan-id="<server id>"]` so the row is counted by identity
rather than by its text.

```
NETWORK_LOG_PASIEN                        NETWORK_LOG_DOKTER
POST 201 /api/v1/auth/register             POST 200 /api/v1/auth/login
POST 200 /api/v1/auth/otp/verify           POST 200 /api/v1/auth/otp/verify
GET  200 /api/v1/me                       GET  200 /api/v1/me
GET  200 /api/v1/konsultasi/50            GET  200 /api/v1/konsultasi/50
GET  200 /api/v1/konsultasi/50/chat       GET  200 /api/v1/konsultasi/50/chat
POST 200 /api/broadcasting/auth           POST 200 /api/broadcasting/auth
POST 201 /api/v1/konsultasi/50/chat       POST 201 /api/v1/kons artist's chat
```

Screenshots: `web/playwright-report/realtime-pasien.png`, `realtime-dokter.png`
(artefacts are gitignored).

## BLOCKED BY THE HARNESS

**The live Reverb outage/recovery e2e.** The broker is killed, then
`startReverb()` spawns a detached `php.exe` to bring it back, and the restart never
binds 8080: `tungguReverbHidup()` polls its full 30 s and throws. Measured **twice**,
identically, while Reverb was demonstrably startable in the same invocation - the runner
had started one 40 s earlier on the same command line. I replaced the previous
executor's `cmd /c start /b` indirection with a direct detached `spawn`; same result. A
background process does not survive inside this harness, which is the same wall the
previous executor looped on.

Unverifiable over a live broker, therefore:

- `data-connected` flipping to `false` after the kill
- the "Mode REST, tanpa realtime" strip while degraded
- the history surviving the outage (REST degradation)
- `data-resyncs` becoming non-zero after "Hubungkan ulang"
- live delivery actually restored afterwards

The reconnect *semantics* are not unverified - they are covered deterministically by the
fake-transport test above, which asserts loss, duplication, and re-subscribe-before-
resync. What is missing is the live-broker confirmation of the same properties.

## Not finished

1. **The amendment-chain e2e assertion.** It reaches the amendment form now (defect 3
   fixed) and the fill succeeds, then finds 0 chain entries. Diagnosis: the amendment
   response carries no `ran`, so neither the inherited cache write (page keeps the
   pre-amendment document) nor caching under both keys (0 chain entries) is right. The
   fix is to navigate to the amended head, which needs a real refetch. Not committed,
   because I could not prove it.
2. The four assertions listed under BLOCKED.

## Gates

`npm run types:check` -> exit 0, no output.
`npm run build` -> exit 0, `3327 modules transformed`, `built in 10.26s`, one 500 kB
chunk-size advisory (pre-existing, `dist/` gitignored).
`npm run test:unit` -> **14 tests, 14 pass, 0 fail** (7 inherited + 7 new).
`npx playwright test` -> 1 passed, 1 failed, 2 not run (see above).

## A.26 scans

- **Non-ASCII**: 110 `.ts/.tsx/.mjs/.json` files under `web/` read and matched against
  `[^\x00-\x7F\u2013\u2014\u2022\u2026\u2192\u2212\u00A7\u2225]` at the byte level:
  **0 violations**. Three corruptions introduced while authoring were caught by this
  scan and fixed before committing.
- **Token audit**: every key of `KonsultasiPesan`, `Konsultasi`, `RekamMedis`,
  `RekamMedisRantai` checked against `KonsultasiChatResource` / `KonsultasiResource` /
  `RekamMedisResource`; `ApiMeta` against `ApiResponse::pageMeta()`; `IssuedToken`
  against `AuthTokenResource`. **94 keys, 0 missing.**
- No `enum:` cast was used (silent no-op on laravel/framework 13.33). No type or lint
  error disabled. No `skip` / `markTestSkipped`. No mock data or MSW interceptor. No
  real token, OTP, phone or NIK in a committed file. `tsconfig.json`, `packages/`,
  `app/`, `routes/`, `database/`, `tests/`, `docs/` and `telemedicine_test.sql`
  untouched. No `migrate:fresh`, no `migrate:rollback`, nothing against
  `telemedisin_db_test` (`.env` targets `telemedisin_db`).
- The e2e doctor's credential is provisioned by a throwaway script **outside the
  repository** (temp dir) through the project's own models, and reaches the spec only as
  `SEHATLY_DOKTER_NO_TELEPON` / `SEHATLY_DOKTER_PASSWORD`. `POST /auth/register` writes
  `users.tipe = 'pasien'`, so a `dokter` cannot be obtained through the public API.
- `tests/` is outside `tsconfig.json`'s `include`, so the unit tests are **not** covered
  by `types:check`. They are executed by Node's type stripping, which is strip-only and
  rejects parameter properties, enums and namespaces.

## Files changed

- `web/package.json` - `test:unit` gains the alias hook
- `web/src/lib/realtime/channel.ts` - NEW, naming rules, no transport
- `web/src/lib/realtime/transcript.ts` - NEW, the render-level merge
- `web/src/lib/realtime/socket.ts` - imports and re-exports from `channel`
- `web/src/lib/realtime/konsultasi-realtime.ts` - `konsultasiChannel` from the leaf
- `web/src/hooks/use-konsultasi-channel.ts` - merge comes from the leaf
- `web/src/features/konsultasi/soap-form.tsx` - label fixed
- `web/src/features/rekam-medis/rekam-medis-view.tsx` - chain-head badge fixed
- `web/src/features/rekam-medis/rekam-medis-edit-form.tsx` - amendment form reachable
- `web/tests/unit/alias-hooks.mjs` - NEW
- `web/tests/unit/realtime-reconnect.test.ts` - NEW, 7 tests
- `web/tests/e2e/konsultasi.spec.ts` - timeout, accept step, strict-mode selectors,
  Reverb restart
