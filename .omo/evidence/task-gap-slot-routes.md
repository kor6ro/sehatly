# Gap closure: the two doctor schedule endpoints

Executor task: land `GET /api/v1/dokter/{dokter}/jadwal` and
`GET /api/v1/dokter/{dokter}/slot`, which todo 26 built the service for and
deliberately did not publish. `.omo/plans/sehatly-telemedicine-platform.md:462`
names both endpoints as todo 26's own deliverables, so todo 26's acceptance
criterion `php artisan route:list --path=api/v1/dokter` "includes the 2 new
routes" was unsatisfiable until now. This is finding F1 of
`.omo/evidence/task-26-sehatly.md` closing, and the root cause was the orchestrator's
own brief to todo 26, which forbade it from writing controllers and routes.

`.omo/plans/` is orchestrator-owned. **No checkbox was marked.**

## The two routes

```
GET|HEAD api/v1/dokter/{dokter}/jadwal   dokter.jadwal   Api\V1\DokterController@jadwal
GET|HEAD api/v1/dokter/{dokter}/slot     dokter.slot     Api\V1\DokterController@slot
```

Middleware: the stateless `api` group only. **No `auth:sanctum`, no
`permission:`, no `tipe:`** on either, for the same reason the three directory
routes carry none: a patient picks a consultation date and has to be able to see
which hours are free *before* they hold a token, `EnsurePermission` answers 401
for an anonymous caller, and `perawat` and `kurir` are real `users.tipe` values
(`telemedicine_test.sql:139`) that hold no role and therefore no grant.
Eligibility is decided inside the controller by `DokterDirectoryService::find()`,
never by a middleware, so "public" costs no data. Both carry
`->whereNumber('dokter')`, so a non-numeric segment is a router 404 and can never
reach a query.

They are registered in `routes/api.php` after `dokter/{dokter}`. A two-segment
wildcard cannot swallow a three-segment path, so no ordering could shadow them;
`DokterJadwalSlotEndpointTest` proves that behaviourally rather than by comment.

### Verbatim `route:list`

```
$ php artisan route:list --path=api/v1
 GET|HEAD api/v1/auth/devices .. auth.devices.index > Api\V1\AuthController@devicesIndex
 POST api/v1/auth/devices .. auth.devices.store > Api\V1\AuthController@devicesStore
 DELETE api/v1/auth/devices/{deviceId} .. auth.devices.destroy > Api\V1\AuthController@devicesDestroy
 POST api/v1/auth/login .. auth.login > Api\V1\AuthController@login
 POST api/v1/auth/logout .. auth.logout > Api\V1\AuthController@logout
 POST api/v1/auth/otp/verify .. auth.otp.verify > Api\V1\AuthController@verifyOtp
 POST api/v1/auth/refresh .. auth.refresh > Api\V1\AuthController@refresh
 POST api/v1/auth/register .. auth.register > Api\V1\AuthController@register
 POST api/v1/booking .. booking.store > Api\V1\BookingController@store
 PUT api/v1/booking/{id}/batalkan .. booking.batalkan > Api\V1\BookingController@batalkan
 GET|HEAD api/v1/dokter .. dokter.index > Api\V1\DokterController@index
 GET|HEAD api/v1/dokter/booking .. dokter.booking.index > Api\V1\BookingController@indexDokter
 GET|HEAD api/v1/dokter/{dokter} .. dokter.show > Api\V1\DokterController@show
 GET|HEAD api/v1/dokter/{dokter}/jadwal .. dokter.jadwal > Api\V1\DokterController@jadwal
 GET|HEAD api/v1/dokter/{dokter}/slot .. dokter.slot > Api\V1\DokterController@slot
 GET|HEAD api/v1/master-spesialisasi .. master-spesialisasi.index > Api\V1\DokterController@spesialisasiIndex
 GET|HEAD api/v1/me .. me > Api\V1\MeController@show
 GET|HEAD api/v1/pasien/alergi .. pasien.alergi.index > Api\V1\PasienController@alergiIndex
 POST api/v1/pasien/alergi .. pasien.alergi.store > Api\V1\PasienController@alergiStore
 PUT api/v1/pasien/alergi/{id} .. pasien.alergi.update > Api\V1\PasienController@alergiUpdate
 DELETE api/v1/pasien/alergi/{id} .. pasien.alergi.destroy > Api\V1\PasienController@alergiDestroy
 GET|HEAD api/v1/pasien/anggota-keluarga pasien.anggota-keluarga.index > Api\V1\PasienController@anggotaKeluargaIndex
 POST api/v1/pasien/anggota-keluarga .. pasien.anggota-keluarga.store > Api\V1\PasienController@anggotaKeluargaStore
 PUT api/v1/pasien/anggota-keluarga/{id} .. pasien.anggota-keluarga.update > Api\V1\PasienController@anggotaKeluargaUpdate
 DELETE api/v1/pasien/anggota-keluarga/{id} .. pasien.anggota-keluarga.destroy > Api\V1\PasienController@anggotaKeluargaDestroy
 GET|HEAD api/v1/pasien/booking .. pasien.booking.index > Api\V1\BookingController@indexPasien
 GET|HEAD api/v1/pasien/profil .. pasien.profil.show > Api\V1\PasienController@profilShow
 PUT api/v1/pasien/profil .. pasien.profil.update > Api\V1\PasienController@profilUpdate

 Showing [28] routes
```

`php artisan route:list --path=api/v1/dokter` exits 0 and shows 5 routes, of which
2 are new. Before this change it showed 3.

### Exact response shapes, captured through the real HTTP kernel

Not a paraphrase: the raw response bodies, dispatched through
`Illuminate\Contracts\Http\Kernel` against a live database. `dokter_id=32`,
`jadwal_id=26`, a 09:00-10:00 Monday window at 30 minutes with `kuota_per_sesi = 2`
and a holiday on Tuesday 2026-12-08.

```
GET /api/v1/dokter/32/jadwal
HTTP 200
{"success":true,"data":{"jadwal":{"0":[],"1":[{"jadwal_id":26,"hari":1,"tipe_layanan":"online","faskes_id":null,"jam_mulai":"09:00:00","jam_selesai":"10:00:00","durasi_slot_menit":30,"kuota_per_sesi":2}],"2":[],"3":[],"4":[],"5":[],"6":[]}},"message":"Jadwal dokter berhasil dimuat.","meta":{"current_page":1,"last_page":1,"per_page":1,"total":1,"from":1,"to":1}}

GET /api/v1/dokter/32/slot?tanggal=2026-12-07
HTTP 200
{"success":true,"data":{"tanggal":"2026-12-07","timezone":"Asia\/Jakarta","slots":[{"jadwal_id":26,"jam_mulai":"09:00:00","jam_selesai":"09:30:00","tipe_layanan":"online","faskes_id":null,"tersedia":true,"alasan":null},{"jadwal_id":26,"jam_mulai":"09:30:00","jam_selesai":"10:00:00","tipe_layanan":"online","faskes_id":null,"tersedia":true,"alasan":null}]},"message":"Ketersediaan jam berhasil dimuat.","meta":{"current_page":1,"last_page":1,"per_page":2,"total":2,"from":1,"to":2}}

GET /api/v1/dokter/32/slot?tanggal=2026-13-45
HTTP 422
{"success":false,"message":"The given data was invalid.","errors":{"tanggal":["The tanggal field must match the format Y-m-d."]}}

GET /api/v1/dokter/33/jadwal                              (status_verifikasi = pending)
HTTP 404
{"success":false,"message":"Resource not found.","errors":{}}

GET /api/v1/dokter/33/slot?tanggal=2026-12-07             (status_verifikasi = pending)
HTTP 404
{"success":false,"message":"Resource not found.","errors":{}}

GET /api/v1/dokter/999999/jadwal
HTTP 404
{"success":false,"message":"Resource not found.","errors":{}}

GET /api/v1/dokter/bukan-angka/slot?tanggal=2026-12-07
HTTP 404
{"success":false,"message":"Resource not found.","errors":{}}

POST /api/v1/dokter/32/jadwal
HTTP 405
{"success":false,"message":"The POST method is not supported for route api\/v1\/dokter\/32\/jadwal. Supported methods: GET, HEAD.","errors":{}}
```

`?page=99&per_page=1000000` returns a byte-identical body to the plain request
above; see "Pagination" below.

## The 404 decision, and the test that proves it

**Every ineligible doctor is `404 {"success":false,"message":"Resource not found.","errors":{}}`
on BOTH routes, and the body is byte-identical to the one `GET /dokter/{dokter}`
already publishes and to the one the kernel publishes for an unmatched path.**

Per case, on both routes:

| case | how the fixture makes it | answer |
| --- | --- | --- |
| nonexistent | id = max(three ids) + 1000 | 404 `Resource not found.` |
| unverified | `dokter.status_verifikasi = 'pending'` (`:427`, default) | 404 `Resource not found.` |
| STR-expired | `str_berlaku_sampai = CURDATE() - 1 day` (`:414`) | 404 `Resource not found.` |
| soft-deleted | `users.dihapus_at` set (`:148`) | 404 `Resource not found.` |
| id `0` | no such row | 404 `Resource not found.` |
| non-numeric | router `whereNumber` miss | 404 `Resource not found.` |

The test is `the_four_ineligible_doctor_cases_answer_ONE_404_envelope_on_BOTH_routes`.
It drives all six cases through **all three** `dokter/{id}*` routes, collects 18
bodies, and asserts `array_unique(array_map('json_encode', $bodies))` has one
element.

### Why the message is the router's, and not a new one

The brief said to stay consistent with todo 22 "unless you have a strong reason".
The strong reason is the same one `DokterDirectoryService`'s own docblock gives
for replacing a `ModelNotFoundException` message in
`bootstrap/app.php:285-294`: publishing a *different* string for "no such
doctor" than for a malformed id segment lets an unauthenticated caller tell the
two apart, and distinguishing them is exactly the enumeration channel
`status_verifikasi` exists to close. A doctor-level 404 that said
`Dokter tidak ditemukan.` would make
`GET /dokter/abc/jadwal` (router 404) distinguishable from
`GET /dokter/999999/jadwal` (controller 404) from one anonymous request with no
credentials. `DokterDirectoryTest:453-481` already pins the uniformity of the
sibling route, with `non_numeric` and `absent` in the same one-body assertion for
precisely this reason. One body, six reasons, one status, three routes.

The cost is stated rather than hidden, in the "Frontend" section below.

### The one thing that is NOT a 404, and why

A doctor who **is** eligible but whose licence does not cover the **requested
date** gets `200` with `slots: []`, not a 404. That is the service's rule 4, and
it is a different question from the directory's: "may this doctor be listed
now" versus "may a visit happen on date D".
`BOUNDARY__the_STR_is_checked_against_the_CONSULTATION_date__and_today_only_gates_who_is_listed`
pins the pair, with a dynamically computed future Monday so the test holds on any
weekday and any date the suite is run: the doctor's licence expires TODAY, so the
directory gate admits them and `GET /jadwal` answers 200 with the window, and then
`GET /slot?tanggal=<next Monday>` answers 200 with `slots: []`.
`an_STR_expired_doctor_is_404_even_on_a_date_the_licence_WOULD_have_covered`
pins the other direction: a licence that lapsed YESTERDAY is a 404 even when the
requested date is yesterday itself, because the directory gate never lets the
date be asked about. The asymmetry is the point -- a 404 hides the licence state,
an empty list would publish it to an anonymous caller.

## Reuse, not re-derivation

`SlotAvailabilityService` was read in full and **not modified**.
`App\Support\Dokter\StrBerlaku` was read in full and **not modified**. `git diff`
on both is empty and `git status` shows neither.

Every rule the service owns is exercised through the endpoint rather than
reimplemented:

- half-open overlap, strict at both ends -- `BOUNDARY__a_booking_that_merely_touches_a_slot_does_not_collide_with_it`
- quota as `count < (kuota_per_sesi ?? 1)`, not a boolean --
  `BOUNDARY__the_quota__not_a_boolean__decides_a_slot_-_two_seats__one_booking__still_open`
- inclusive `berlaku_sampai` and the reachable window end -- inherited, and the
  service's own test file already pins them through the service
- a partial trailing slot never offered -- `the_slot_read_answers_tanggal__timezone_and_slots_exactly_as_the_plan_names_them`
  (four 15-minute slots in a 09:00-10:00 window, end reached exactly)
- a doctor with no `dokter_jadwal` rows has no bookable slots --
  `a_doctor_with_no_schedule_rows_answers_seven_EMPTY_days__not_an_empty_list` and
  `a_weekday_with_no_window__and_a_doctor_with_no_window_at_all__are_both_an_empty_list`
- `dokter_libur` CLOSES the day -- `BOUNDARY__a_holiday_closes_the_day_with_alasan_libur_on_every_published_slot`
- STR inclusive `>=`, fail-closed on NULL, against the **consultation date** -- the
  pair above
- the 7-value status exclusion set -- `a_dibatalkan_booking_does_not_consume_a_slot__and_a_consuming_status_does`

Plus a direct parity test,
`the_endpoint_publishes_the_service_answer_verbatim__so_nothing_is_re_derived`,
which asserts the decoded `data.slots` **equals** `getSlotTerbuka()`'s return
value element for element on a fixture carrying three different answers at once
(a filled quota, an untouched neighbour, and a second window with a different
`tipe_layanan`).

The eligibility gate is `DokterDirectoryService::find()` -- the directory's own
service, not a locally written `where('status_verifikasi', ...)`. That reuses
`v_dokter_katalog`'s `WHERE` (`:1183`-`:1185`), the `StrBerlaku` boundary and the
`dihapus_at` guard by construction. `find()` eager-loads five relations these
routes never read; that cost is already paid by `DokterController::show()` on the
sibling route, and a cheaper `exists()`-shaped variant of the same service would
be a second entry point through which the eligibility rule could be read.

### Two mutations, both caught

**Mutation 1 -- drop the eligibility gate.** Replacing
`$this->directory->find((int) $dokter)` with
`Dokter::query()->find((int) $dokter)` in both new actions:
24 tests, **22 passed, 2 failed** -- the two 404 tests, `Expected 404 but received 200`.
Reverted; SHA of the file restored from the pre-mutation copy.

**Mutation 2 -- re-derive availability.** Wrapping the service answer so every
slot reads `tersedia => true, alasan => null`:
24 tests, **17 passed, 7 failed** -- the parity test, the quota boundary, the
touching-booking boundary, the holiday, the status exclusion, the elapsed-slot
rule and the `alasan` vocabulary. Reverted.

The first attempt at mutation 2 used PHP's `+` array operator, which keeps the
LEFT operand's value for a duplicate key, so the mutation was a no-op and the
suite stayed **24/24 green**. That is worth recording: a mutation that does not
mutate looks exactly like a test suite that cannot fail. `array_merge` is what
actually applied it.

## Validation of `tanggal`

`IndexSlotDokterRequest` applies `required|string|date_format:Y-m-d`.

`date_format` and not `date` because `date` accepts a dozen spellings including
relative ones PHP invents (`now`, `+1 week`) and `2026-12-7`; a schedule
question has exactly one addressable form.

The rollover is the trap and the rule catches it: PHP's
`DateTime::createFromFormat('Y-m-d', '2026-13-45')` does **not** fail, it
overflows month 13 and day 45 into 2027-02-14, and `date_format` round-trips the
parsed value back through `format()` before comparing. Measured through HTTP:
`2026-13-45`, `2026-02-30`, `2026-12-7`, `07-12-2026`, `bukan-tanggal`, `now`
and `''` are all 422 with `errors.tanggal`, and `2026-12-07` is 200.

The service keeps its own `InvalidArgumentException` and its own round trip. The
controller deliberately does **not** wrap the call in a catch: with the rule in
front of it no input reaches the service that the service would refuse, so a
catch there would be unreachable code. The service's throw stays pinned by
`SlotAvailabilityTest:1092`.

Validation runs **before** the doctor lookup, so a malformed date cannot probe
existence: `validation_runs_before_the_doctor_lookup__so_a_bad_date_cannot_probe_existence`
asserts the 422 body is identical for an existing and a nonexistent id.

## Pagination

**Neither route pages, and neither accepts `page` or `per_page`.** Both answer
`ApiResponse::singlePageMeta()`, the project block in its degenerate single-page
form, so a client parses one list envelope rather than two.

The decision, and the reason it is not a cap: `slots` is the candidate set of ONE
date, bounded by that weekday's `dokter_jadwal` rows, and the weekly read is
structurally seven keys that must all be present. Paging the slot list would not
merely truncate it, it would publish a **lie** -- a day with forty candidates
rendered as a day with fifteen, with the missing twenty-five reading as
nonexistent rather than unavailable. The project's `max:100` cap
(`DokterDirectoryService::PER_PAGE_MAX`) is for the opposite situation, a
potentially unbounded collection; there is nothing unbounded here to cap.

`neither_route_pages__and_a_per__page_sent_anyway_cannot_grow_or_shrink_the_answer`
asserts `?page=99&per_page=1000000` returns a body equal to the plain request's
and the same `meta`. The real kernel confirms it byte for byte in the evidence
above.

`meta.total` on the weekly read counts **window rows**, not the seven day keys,
so a doctor with no schedule reports `0` and not `7`. Asserted.

## The JSON object, not a JSON array

`getJadwal()` returns `array_fill(0, 7, [])`, and PHP's `json_encode` renders an
integer-keyed `0..6` array as `[[], []]` -- a JSON **array**. The plan, the
service docblock and the web client's `JadwalMinggu = Record<string, JadwalHari[]>`
all describe a keyed map, so `DokterJadwalResource` casts it to an object. The
cast's behaviour was measured, not assumed:

```
cast:     {"0":[],"1":[{"jadwal_id":7}],"6":[]}
nested:   {"jadwal":{"0":[],"1":[{"jadwal_id":7}],"6":[]}}
uncast:   [[],[]]
```

and the raw body above shows `"jadwal":{"0":[],...,"6":[]}`.
`the_weekly_read_answers_the_project_envelope_with_a_seven_key_object` asserts
`isObject()` plus seven readable keys on every run, so the cast cannot rot.

## DDL citations, read from the file

Every line below was read out of `telemedicine_test.sql` for this task, not taken
from the plan's `:NNN` citations, several of which are wrong in this project.

| what | line | verbatim |
| --- | --- | --- |
| `dokter_jadwal` table | 470-488 | |
| `dokter_jadwal.faskes_id` | 473 | `BIGINT UNSIGNED NULL COMMENT 'NULL = layanan online murni'` |
| `dokter_jadwal.tipe_layanan` | 474 | `ENUM('online','klinik','home_visit') NOT NULL DEFAULT 'online'` -- THREE values |
| `dokter_jadwal.hari` | 475 | `TINYINT UNSIGNED NOT NULL COMMENT '0=Minggu s.d. 6=Sabtu'` |
| `dokter_jadwal.jam_mulai` / `jam_selesai` | 476-477 | `TIME NOT NULL` |
| `dokter_jadwal.durasi_slot_menit` | 478 | `SMALLINT UNSIGNED NOT NULL DEFAULT 15` |
| `dokter_jadwal.kuota_per_sesi` | 479 | `SMALLINT UNSIGNED NULL` |
| `dokter_jadwal.berlaku_mulai` / `berlaku_sampai` | 480-481 | `DATE NOT NULL` / `DATE NULL` |
| `dokter_jadwal.status_aktif` | 482 | `TINYINT(1) NOT NULL DEFAULT 1` |
| `dokter_jadwal.dibuat_at` / `diubah_at` | 483-484 | `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` (+ ON UPDATE) |
| `idx_jadwal` | 487 | `INDEX idx_jadwal (dokter_id, hari, status_aktif)` |
| `dokter_libur` table | 490-496 | `dokter_id` 492, `tanggal DATE NOT NULL` 493, `alasan VARCHAR(200) NULL` 494, **no timestamps at all** |
| `booking` table | 498-530 | |
| `booking.tipe_layanan` | 506 | `ENUM('chat','video_call','kunjungan_klinik','home_visit')` -- FOUR values, a different enum from `:474` |
| `booking.tanggal_kunjungan` | 507 | `DATE NOT NULL` |
| `booking.slot_mulai` / `slot_selesai` | 508-509 | `TIME NOT NULL` |
| `booking.status` | 515-516 | the eight-value ENUM, `no_show` eighth |
| `booking.dibuat_at` / `diubah_at` | 520-521 | `TIMESTAMP` |
| `dokter.str_berlaku_sampai` | 414 | `DATE NOT NULL` |
| `dokter.tipe` | 412 | the seven-value ENUM |
| `dokter.tersedia_telemedisin` / `status_verifikasi` / `status_aktif` | 426 / 427 / 430 | |
| `users.dihapus_at` | 148 | `TIMESTAMP NULL DEFAULT NULL` |
| `v_dokter_katalog` `WHERE` | 1183-1185 | `status_verifikasi = 'terverifikasi' AND status_aktif = 1 AND tersedia_telemedisin = 1` |

**`tipe_layanan` is two different enums.** `dokter_jadwal.tipe_layanan` at `:474`
is `('online','klinik','home_visit')`; `booking.tipe_layanan` at `:506` is
`('chat','video_call','kunjungan_klinik','home_visit')`. Only `home_visit` is
shared. Both resources name the schedule row's own value, and the token audit
below resolved both vocabularies against the DDL's 243 enum values rather than
against a hand-typed list.

**Timestamps are `dibuat_at` / `diubah_at`**, and neither resource publishes one.
`every_column_the_two_responses_publish_is_a_real_column__and_no_timestamp_is_published`
re-parses the DDL and asserts `dibuat_at` and `diubah_at` exist on
`dokter_jadwal` while `created_at`, `updated_at` and `deleted_at` do **not**, that
`dokter_libur` has no timestamp column at all, and that the soft-delete marker is
`users.dihapus_at` and not `deleted_at`. Both resources are allow-lists, so a
timestamp would not reach the wire by accident either -- `DokterResource` is the
precedent for that and the test asserts the exact key set.

## The two resources

`app/Http/Resources/DokterSlotResource.php` -- one candidate slot, seven keys,
pure pass-through of the service's own row.

`app/Http/Resources/DokterJadwalResource.php` -- the week map, `{"jadwal":
{...seven keys...}}`, with the eight window keys named inline. A day is a bare
list rather than an object, so a resource per day would be a resource publishing
nothing of its own; naming the eight keys in one place is the allow-list.

`app/Http/Requests/Dokter/IndexSlotDokterRequest.php` -- the one query parameter.

`app/Http/Controllers/Api/V1/DokterController.php` -- two new actions, `jadwal()`
at line 208 and `slot()` at line 270, plus a private `jumlahBaris()` at 304.
`DokterController` now has five actions.

## Frontend: what changes and what does not

**`web/` was not modified.** `git status` shows no `web/` path.

**For an eligible doctor -- which is every doctor the directory lists -- the
route-absent panel disappears with NO frontend change.** The response shape is
byte-for-byte what `web/src/lib/api/jadwal.ts` already declares:

| frontend type | wire |
| --- | --- |
| `request<SlotHari>` -> `data.tanggal` | `data.tanggal` |
| `timezone: 'Asia/Jakarta'` | `"Asia/Jakarta"` |
| `Slot[]` with `jadwal_id, jam_mulai, jam_selesai, tipe_layanan, faskes_id, tersedia, alasan` | those exact seven keys, no more, no fewer |
| `request<{jadwal: JadwalMinggu}>` -> `data.jadwal` | `data.jadwal` |
| `JadwalMinggu = Record<string, JadwalHari[]>` | a JSON object with keys `"0"`..`"6"` |
| `JadwalHari` = 8 window keys | those exact eight keys |
| `AlasanSlot = 'libur' \| 'lewat_waktu' \| 'penuh'` and `null` | exactly those three strings and `null` |
| `ApiResult.meta?: ApiMeta` | the project `meta` block, as a sibling of `data` |

`SlotPicker` reads `query.data?.data.slots` (`slot-picker.tsx:78`), which is
exactly `data.slots`, and `JadwalMinggu` had no consumer at all -- my search for
`jadwalOptions`, `fetchJadwalDokter`, `JadwalMinggu` and `HARI_NAMES` across
`web/src` returned only the definitions inside `jadwal.ts` itself.

**One residual, stated precisely rather than left to be discovered.** For an
*ineligible* doctor, `SlotPicker` will still render the route-absent panel, not
`NotFoundState`. The reason is the server side, and it is deliberate:

- `jadwalEndpointBelumTerdaftar()` (`web/src/lib/api/jadwal.ts:206-221`) returns
  true when `status === 404 && message === 'Resource not found.'`.
- This server publishes exactly that string for a doctor-level 404, because
  `DokterController` does (`:155`, `:213`, `:275`) and so does the kernel's
  exception handler (`bootstrap/app.php:290-294`).
- So the client's discriminator, which was written to tell "route not deployed"
  from "doctor not bookable", can no longer tell them apart -- the second state
  will render as the first.

The frontend's own documentation asserts the opposite, and that assertion is
**false** against the code it describes:

- `jadwal.ts:41-43` -- "`DokterController` answers its own Indonesian sentence for
  a doctor who is absent, unverified, inactive, off telemedicine or STR-expired".
  Measured: `DokterController::show()` answers `ApiResponse::error('Resource not
  found.', [], 404)`. There is no Indonesian sentence for that case, and there
  never was.
- `jadwal.ts:200` -- the same claim again.
- `slot-picker.tsx:29` and `:34-35` -- "`DokterController`'s own Indonesian
  message", same false claim.

That is a stale comment in `web/`, out of this task's scope, and it is reported
rather than fixed. The behaviour to be aware of: after this change the
route-absent panel is reachable **only** for a doctor who cannot be booked (and
for a non-numeric id segment, which the router 404s with the same string). Before
this change it was reachable for every doctor. If todo 28's owner wants
`NotFoundState` for an ineligible doctor, the fix is one of two, and both are
frontend work: give the client a second signal, or have the server publish a
doctor-level 404 message -- which would forfeit the absent-versus-malformed
uniformity `DokterDirectoryTest:453-481` pins. I did not make that call, because
it trades a stated API invariant for a client convenience and the brief asked for
consistency.

## Test database

**`telemedisin_db_gapslot`**, a private database created for this task
(`CREATE DATABASE ... utf8mb4_unicode_ci`, then migrated by the suite's own
`RefreshDatabase`). `phpunit.xml` was **not** modified: it is shared config and a
permanent `DB_DATABASE` change there would hand my private database to every
concurrent executor. The override is a shell environment variable for each run:

```
$env:DB_DATABASE='telemedisin_db_gapslot'; php artisan test
```

Proof the override takes effect and is not assumed: the database did not exist
before the first run and now holds 84 tables and 81 `migrations` rows, which only
a migration run against it could have produced. PHPUnit's `<env>` elements do not
override an already-set variable without `force="true"`, and Dotenv's immutable
loader does not overwrite the environment either.

The shared `telemedisin_db_test` was deliberately not used, for the reason three
previous executors recorded: concurrent `migrate:fresh` runs tear it down
mid-suite (MySQL 1213 deadlock, then 1050 already exists, then 1146 `migrations`
does not exist).

## Suite

Baseline measured by the orchestrator: 474 tests, 474 passed, 8838 assertions.
Final on `telemedisin_db_gapslot`:

```
{"tool":"pest","result":"failed","tests":498,"passed":496,"assertions":9100,"duration_ms":173379,"failed":2,"failures":[
 {"test":"P\\Tests\\Unit\\Console\\VerifySchemaCommandTest::__pest_evaluable_the_JSON_report_is_machine_readable__and_its_exit_code_matches_its_verdict",
  "file":"tests/Unit/Console/VerifySchemaCommandTest.php","line":185,
  "message":"Failed asserting that two strings are identical. --- Expected +++ Actual -'telemedisin_db_test' +'telemedisin_db_gapslot'"},
 {"test":"P\\Tests\\Unit\\DatabaseEngineTest::__pest_evaluable_the_test_suite_runs_on_the_MySQL_8_test_database",
  "file":"tests/Unit/DatabaseEngineTest.php","line":23,
  "message":"Failed asserting that two strings are identical. --- Expected +++ Actual -'telemedisin_db_test' +'telemedisin_db_gapslot'"}]}
```

**Delta: +24 tests, +262 assertions, zero regressions.** 496 + the 2
database-name pins = 498.

The 2 failures are **not** mine and **not** defects in the code under test: both
assert `config('database.connections.mysql.database') === 'telemedisin_db_test'`
by literal, so they fail on *any* per-executor database. Ledger entries 27, 30
and 31 record the same two. Proven rather than asserted -- run with the shell
variable unset, so the shared database is used:

```
$ php artisan test tests/Unit/DatabaseEngineTest.php tests/Unit/Console/VerifySchemaCommandTest.php
{"tool":"pest","result":"passed","tests":14,"passed":14,"assertions":174}
```

The new file alone:

```
$ php artisan test --filter=DokterJadwalSlotEndpointTest
{"tool":"pest","result":"passed","tests":24,"passed":24,"assertions":276}
```

Todo 26's own file, re-measured on the same private database rather than
inherited: `--filter=SlotAvailabilityTest` gives 37 tests, 37 passed, 285
assertions, which matches the brief's "37 tests".

**No test is skipped.** `markTestSkipped` is not used anywhere in the new file,
and todo 54's zero-skipped gate is unaffected.

## Red then green

`routes/api.php`'s two registrations were commented out (six lines, restored from
a byte copy afterwards) and the new file run against the shipped table minus
those two routes:

```
RED:   24 tests, 4 passed, 20 failed, 125 assertions
GREEN: 24 tests, 24 passed, 276 assertions
```

Every RED failure was a 404 from the router except one, and the exception is the
point of recording it: `the_reasons_a_slot_is_closed_are_exactly_the_service_constants_and_nothing_else`
failed for a reason that had nothing to do with the routes -- it asserted the
`alasan` list in **precedence** order (`libur`, `lewat_waktu`, `penuh`) while
building it in **declaration** order (`libur`, `penuh`, `lewat_waktu`). The
assertion was wrong, not the code, and it now compares the two as sets with a
comment saying why pinning either order would pin an accident. Three further
fixture defects were found and fixed the same way: a "full slot" fixture whose
second booking touched rather than overlapped, a "booking that merely touches"
fixture whose second booking coincided exactly with a slot, and a reachability
claim for `lewat_waktu` whose fixture never produced it (it now freezes the clock
and builds a window whose step lands a boundary on the frozen `now`).

## The route-closure test I had to update

`PasienProfileTest:1394` asserts a **closed set** of every `api/v1` route. Adding
two routes made it fail -- which is that assertion working as designed, not a
breakage. Both lists in it were updated: the closed set, and the `$anonymous`
set that names every route permitted to omit `auth:sanctum`. The comment
explaining *why* the set is closed (a closed set that quietly forgives a
concurrently-wired route is the drift the assertion exists to catch) is why I
added the entries explicitly rather than widening a filter.

## Audits

**Non-ASCII gate, A.26's pattern.** `[^\x00-\x7F\u2013\u2014\u2022\u2026\u2192\u2212\u00A7\u2225]`
over all six authored files: **0 violations, 0 non-ASCII bytes total**, so nothing
needed the allowed set at all. It earned its keep on the way there: the test
file's first draft contained a full-width comma and two CJK characters
(`$,?? = $this->getJson(`), which is exactly the corruption class the gate exists
for and which no PHP linter would have named. Three more ASCII-shape defects in
the same draft (a missing `{` in a string concatenation, and two identifiers with
stray spaces) were caught by `php -l` rather than by the encoding gate, which is
worth knowing: the gate and the linter fail on disjoint things.

**Token audit, A.26's second half.** Every `snake_case` token in the six files,
resolved through the project's own `SqlSchemaParser` against the reference SQL:

```
DDL vocabulary: 77 tables/views, 352 columns, 31 named indexes, 2 named foreign keys, 243 enum values
token audit: 133 distinct snake_case tokens across 6 files, 0 unresolved
exit 0
```

A token resolves against a DDL column, table/view, named index, named foreign
key, **any of the 243 DDL enum values**, a declared constant on the four classes
this gap touches, a route name, a key these two resources publish, a
`function_exists()` PHP function, or a short named allow-list. The enum-value
bucket is the one that matters: it is what proves `online` / `klinik` /
`home_visit` and `chat` / `video_call` / `kunjungan_klinik` / `home_visit` and
`dokter_umum` are spelled as the DDL spells them, which is the `doker_umum` class
of defect A.26 records finding in another file.

**Pint**, scoped to the six owned files only, never repo-wide: `exit 0` after
fixing `ordered_imports`, `phpdoc_trim` and `fully_qualified_strict_types` on my
own files, and `no_superfluous_phpdoc_tags` removing one `@return object` that
added nothing over the native return type. Ledger entry 31 records why a
repo-wide run is unsafe here: `fully_qualified_strict_types` will add a
production-to-test import into a production class. In these six files every
import it added points at another production class, so the result was reviewed
line by line before being kept. Note for whoever runs Pint next: `vendor\bin\pint.bat`
picks up `C:\php-8.2.29` and refuses to run on this project, which requires
`^8.3.0`; the working invocation is
`php vendor\laravel\pint\builds\pint --test <files>` with the 8.4 binary.

**`sehatly:verify-schema`** exits 0: `Discrepancies: 7 (0 drift, 7 informational)`,
all seven being the registered extra tables (`cache`, `cache_locks`,
`failed_jobs`, `job_batches`, `jobs`, `migrations`, `personal_access_tokens`).
**The command is `sehatly:verify-schema`, not `verify-schema`** -- the short name
does not resolve, and Artisan then *prompts* `Do you want to run "sehatly:verify-schema"
instead? (yes/no)`, which hung two of my runs for 20 minutes each waiting on stdin
that was not there. The stray process was identified by its command line and
stopped. Nothing was written: the command is read-only by contract and reports so.

**`telemedicine_test.sql` is byte-identical.**
`Get-FileHash -Algorithm SHA256` =
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, and
`git status --porcelain telemedicine_test.sql` is empty.

## Version facts, verified rather than inherited

- `laravel/framework` is **v13.33.0**, read out of `composer.lock`
  (`"name": "laravel/framework", "version": "v13.33.0"`), matching
  `composer.json`'s `^13.17`. Not 12.
- The brief's two traps were avoided by construction, and I note them because
  neither has a test to fail: no `enum:` cast was used, so the silent no-op
  cannot be present; and no trait and no model were authored, so the
  `CREATED_AT` redeclaration trap has no surface here. `DokterJadwal::CREATED_AT`
  and `Dokter::` both already carry `dibuat_at` / `diubah_at` correctly, and the
  new resources read no timestamp at all.
- No `mobile/` directory was created, no Flutter added, and nothing under `web/`
  or `packages/` was touched.
- `database/migrations/` and `database/seeders/` are untouched; `git diff`
  confirms it.

## Files

| path | change |
| --- | --- |
| `app/Http/Requests/Dokter/IndexSlotDokterRequest.php` | new, `tanggal` validation |
| `app/Http/Resources/DokterSlotResource.php` | new, one slot row |
| `app/Http/Resources/DokterJadwalResource.php` | new, the week map |
| `app/Http/Controllers/Api/V1/DokterController.php` | +2 actions, +1 private helper |
| `routes/api.php` | +2 route registrations, +31 lines of comment |
| `tests/Feature/Dokter/DokterJadwalSlotEndpointTest.php` | new, 24 tests |
| `tests/Feature/Pasien/PasienProfileTest.php` | +15 lines: the two routes into the closed set and the anonymous set |
| `.omo/evidence/task-gap-slot-routes.md` | this file |
| `.omo/start-work/ledger.jsonl` | one line |

Untracked and **not** mine, never staged: `.omo/evidence/task-3-sehatly.md` and
`.playwright-mcp/`.

## Findings

**F1** The plan's todo 26 acceptance criterion ("`route:list --path=api/v1/dokter`
includes the 2 new routes") was unsatisfiable when todo 26 was closed, because
the brief to todo 26 forbade controllers and routes. It is satisfied now; the
plan's checkbox was already marked and I did not touch it.

**F2** The plan cites `telemedicine_test.sql:470-496`, `:498-534` and `:474`.
`:474` is right (`dokter_jadwal.tipe_layanan`). `:470-496` is right for
`dokter_jadwal` + `dokter_libur` but `:496` is the closing `) ENGINE=InnoDB` of
`dokter_libur`, not part of it, and `:498-534` runs one line past `booking`'s
closing `) ENGINE=InnoDB` at `:530` into the section banner. Harmless, but they
are not the table extents.

**F3** `web/src/lib/api/jadwal.ts:41-43`, `:200` and
`web/src/features/booking/slot-picker.tsx:29`, `:34-35` state that
`DokterController` answers an Indonesian sentence for an ineligible doctor. It
answers `Resource not found.` -- measured at `DokterController.php:155`. Four
false claims about a file whose message the client then keys on, and the sixth
false finding in this project's history (A.25) arrived again, in the one
direction the brief did not ask about: not "a line number nobody checked" but
"a behaviour nobody re-read".

**F4** The route-absent panel is now reachable only for an ineligible doctor. That
is the cost of the uniform 404, and it is a one-line frontend fix if wanted; see
"Frontend" for both options and why the decision is not mine to take unilaterally.

**F5** `artisan verify-schema` is not a command. `sehatly:verify-schema` is, and
the wrong name makes Artisan prompt interactively rather than fail, which is a
20-minute hang under a non-interactive runner. Recorded because the next
executor will do the same thing.

**F6** `vendor\bin\pint.bat` resolves PHP 8.2.29 from `C:\php-8.2.29` and refuses
to run on this project (`^8.3.0`). Use
`php vendor\laravel\pint\builds\pint`.
