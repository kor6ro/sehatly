# F3C -- notification wiring (F3-05) and reachable demo data (F3-02)

**Executor role:** executor. I did not build this project. I read `.omo/evidence/F3-manual-qa.md`,
reproduced both findings, fixed them under TDD, and measured everything below.

| | |
| --- | --- |
| My commits | `14aec3f` (F3-05), `f058a88` (F3-02) |
| HEAD when F3-05 landed | `14aec3f`, on top of `154b1f9` |
| HEAD when F3-02 landed | `f058a88`, on top of `f9c438e` |
| Scratch databases created (named, not shared) | `sehatly_f3c_scratch`, `sehatly_f3c_seedproof`, `sehatly_f3c_final`, `sehatly_f3c_wtsuite`, `sehatly_f3c_wt2` |
| `phpunit.xml` | **untouched** |
| `telemedicine_test.sql` | **untouched by me** -- see Sec.7 |
| Migration / index / constraint added | **none** |
| `migrate:fresh` or `migrate:rollback` against `telemedisin_db` or `telemedisin_db_test` | **none** |
| `markTestSkipped` | **not used anywhere** |
| Final suite (isolated worktree at `f058a88`) | **1193 tests, 1193 passed, 22812 assertions, 0 failed, 0 errors, 0 skipped, exit 0** |

---

## 0. Two things I got wrong first, because the report is worth more than the fix if it isn't

### 0.1 I corrupted my own source while writing it, three separate times

Writing the new test files, my output silently substituted **non-ASCII garbage for ASCII
identifiers**: `$kons` + `<U+30D9><U+30EB>` + `ultasId`, `$dunya` for `$dunia`, `biaya_kons-ec-online` for
`biaya_konsultasi_online`, `siapDip` + `<U+627F><U+5305>` + `()`, `/api/v1/kons` + `<U+30D9><U+30EB><U+30C8>` + `ultasId`.
Every one of them looked correct in the tool result. This is the same defect class as the
`referencia` for `referensi` that cost this project 48 test failures, and it is invisible to a
decoder and to a compile.

A byte-level scan over raw bytes caught all of them. The scan is
`C:\Users\axioo\AppData\Local\Temp\opencode\scan-ascii.ps1` (outside the repository), and it is
the check that matters: it reads `[System.IO.File]::ReadAllBytes` and counts bytes `> 127`, so a
corrupt-ASCII identifier or a mojibake sequence cannot hide behind a UTF-8 decode. **The result
over every file I touched is in Sec.6.**

### 0.2 I destroyed `vendor/` and had to rebuild it

To measure my commit in isolation I created a `git worktree` and made its `vendor` a **junction**
to the repository's. When I removed the worktree with `git worktree remove --force`, git
followed the junction and **emptied the real `vendor/` directory** (52 packages -> 0). `node_modules`
at the repository root was emptied by the same accident.

Restored with `composer install --no-interaction` (52 entries, `artisan --version` ->
`Laravel Framework 13.33.0`) and `npm install`. Nothing is lost and nothing in the tree depends
on the empty root `node_modules` -- the root `package.json` declares no dependencies; the real
tree is `web/node_modules`, which was never touched. **The lesson is recorded here because the
brief's own hazard list says "do not delete a file you did not create", and a junction in a
worktree turns a scoped delete into an unscoped one.** The isolated measurement was repeated in a
worktree with its own `composer install` and **no junction of any kind**.

### 0.3 A correction to the F3 report's own arithmetic, offered because it changed what I had to do

F3 Sec.4 records the contract DDL as SHA-256 `AEFE2247E00F...`. The committed blob hashes
`D056EE5C25E4...` and the working-tree checkout hashes `AEFE2247E00F...` -- **the two differ only by
LF versus CRLF**, and the working tree is the CRLF one. So at the moment I started, the DDL was
**byte-identical to the mandated baseline** and I had nothing to do about it.

I found this out by reverting the one line another agent had changed and re-hashing:

```
current working tree   : line 222 = "  nik_cipher TEXT NULL COMMENT 'WAJIB dienkripsi ...',"
line 222 reverted      : "  nik CHAR(16) NULL UNIQUE COMMENT 'WAJIB dienkripsi ...',"
reverted sha256        : AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
brief's mandated value : AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
```

**The reversion reproduces the mandated digest exactly**, so the DDL's *content* was untouched
when I started and the digest difference is line endings. I report this because I initially
believed the file had been edited and said so; it had not.

---

## 1. F3-05 -- the five notification triggers, and the real action that now fires each

### 1.1 The defect

F3 Journey 12: *"nothing in `app/` inserts a row into `notifikasi`"*. The service exists and
implements all five triggers; **nothing calls it**. F3 performed four of the five domain actions
and `GET /api/v1/notifikasi` answered `total: 0` after every one.

### 1.2 Where the wiring went, and why there

`NotificationService`'s own docblock is explicit: *"it is called by the module services, never by
a controller"*. Every call site is therefore in a **service**, inside that service's **existing
transaction**, so a rollback of the domain write takes the inbox row with it. An inbox row about a
booking that does not exist is worse than no row.

| `tipe` | `NotificationService` method | call site | recipient | why that recipient |
| --- | --- | --- | --- | --- |
| `booking` | `bookingDibuat` | `BookingService::create()` | the booking's patient | the creator confirms a record the server now holds |
| `booking` | `bookingDibatalkan` | `BookingService::batalkan()` | **the party that did not cancel** | the actor already knows; the other party is the one left holding a dead appointment |
| `pembayaran` | `pembayaranSelesai` | `PaymentService::terimaWebhook()` | the invoice's patient | and **only** on the `$lunas` branch |
| `resep` | `resepSiap` | `ResepVerifikasiService::tulis()` | the prescription's patient | and **only** on an advancing verification |
| `chat` | `pesanBaru` | `KonsultasiService::kirim()` | the other side of the transcript | read off `$sisi`, not `users.tipe` |

Three of the five are deliberately **narrower** than the event they hang off, because a wider
notification is a false claim the inbox then repeats:

- a **duplicate webhook delivery cannot notify twice** -- the dedupe branch returns before the
  notification line is reachable, so the guarantee is structural rather than a counter;
- a **`gagal` payment notifies nobody** -- the invoice is not `lunas`;
- a **rejected prescription is never announced as ready** -- `resepSiap` fires on
  `ResepVerifikasiStatus::maju()` only, which is the same set the status walk already decided,
  and the service's own prose ("Resep Anda sudah diverifikasi dan siap") is only true there;
- a **system chat line notifies nobody** -- `konsultasi.chat` refuses `TIPE_PESAN_SISTEM` before
  `kirim()` can reach the notification line, so there is no "nobody is waiting on it" case.

### 1.3 RED -> GREEN, transcript

The test file is `tests/Feature/Notifikasi/NotifikasiTriggerTest.php`. Its rule is in its own
header: **every test performs the DOMAIN ACTION over HTTP and then counts the rows.** A test that
called `NotificationService::bookingDibuat()` directly would pass with the wiring removed, which
is exactly the shape of test that let this defect ship.

RED -- the run before any wiring. The four negative tests already pass (they assert absence); the
eight that assert a row exists all fail on the row, and two of them fail on the API status they
were asserting *after* the row:

```console
$ php artisan test tests/Feature/Notifikasi/NotifikasiTriggerTest.php
{"tool":"pest","result":"failed","tests":12,"passed":2,"assertions":36,"duration_ms":25672,"failed":8,
 "failures":[
  {"test":"...creating_a_booking_over_the_API_writes_a_booking_notification_to_the_patient",
   "message":"Expecting null not to be null .","line":108},
  {"test":"...the_booking_notification_is_readable_through_GET__notifikasi",
   "message":"Failed asserting that 0 is identical to 1.","line":138},
  {"test":"...cancelling_a_booking_over_the_API_notifies_the_party_that_did_not_cancel",
   "message":"Expecting null not to be null .","line":165},
  {"test":"...a_doctor_cancelling_a_booking_notifies_the_patient__and_the_reason_travels",
   "message":"Failed asserting that actual size 0 matches expected size 1.","line":198},
  {"test":"...a_settled_invoice_over_the_signed_webhook_writes_a_payment_notification",
   "message":"Expecting null not to be null .","line":259},
  {"test":"...a_duplicate_webhook_delivery_does_not_notify_twice",
   "message":"Failed asserting that 0 is identical to 1.","line":292},
  {"test":"...a_pharmacist_verifying_a_prescription_writes_a_prescription_notification",
   "message":"Expecting null not to be null .","line":405},
  {"test":"...a_doctor_sending_a_chat_message_notifies_the_patient__and_the_reverse",
   "message":"Expecting null not to be null .","line":452}]}
```

Note what the RED did **not** say: the interaction-override test reached
`data.acknowledgement.diminta === true` and the rejected-prescription test got its 201 on the run
that produced this RED. Those two were already-correct product behaviour; the defect was that the
prescription it produced announced nothing.

GREEN -- the same command after wiring:

```console
$ php artisan test tests/Feature/Notifikasi/NotifikasiTriggerTest.php
{"tool":"pest","result":"passed","tests":12,"passed":12,"assertions":76,"duration_ms":23542}
```

Between RED and GREEN two genuine bugs in my own wiring surfaced, both caught by the tests rather
than by reading:

1. `(int) $pembuat->tipe === 'pasien'` -- casting a string to `int` made the counterparty branch
   dead, so **every** cancellation notified the patient. Found by
   `cancelling_a_booking_over_the_API_notifies_the_party_that_did_not_cancel` (the doctor got
   nothing). Fixed to `$pembuat->tipe === 'pasien'`.
2. The cancellation reason arrived as `null`, because the request field is `alasan_pembatalan`
   and I had sent `alasan`. Found by the assertion on `notifikasi.isi`.

### 1.4 The rows are audited, and that is asserted

`notifikasi.user_id` references `users` (`telemedicine_test.sql:1046`), so `AuditScope`'s
foreign-key closure from `users` reaches it and the global `AuditObserver` writes one `audit_log`
`create` row per notification. The first test asserts the audit row exists by
`record_id` -- a builder insert would leave none, and that is why the service saves through the
model.

---

## 2. F3-02 -- the four unreachable journeys

### 2.1 The defect, and the honest reading of the root cause

F3 was right that the cause is seed data. `DevFixtureSeeder.php:268` sets
`password_hash(bin2hex(random_bytes(32)))` for every fixture account, so the fixture dataset --
3 doctors, 2 patients, 2 facilities -- is permanently unloginable, and with no reachable doctor
there is no way to write a prescription, verify one, check out an order or open a medical record.

F3 also recorded the second half, which is the part that decides whether a seeder is a fix:
**there is no endpoint anywhere in the 74 that creates a `dokter_jadwal` row.** So a freshly
seeded database can never offer a bookable slot, which is why `GET /dokter/1/slot` answered
`{"slots":[]}` (F3-08) while `POST /booking` still answered 201 with `jadwal_id: null`.

### 2.2 What the seeder creates, and what it deliberately does not

`database/seeders/DemoDataSeeder.php` creates the **preconditions that have no public API**:

| row | why it has to be a seeder |
| --- | --- |
| 3 `users` + 3 `user_roles` | `POST /auth/register` only creates `tipe = 'pasien'`; there is no way to obtain a doctor or a pharmacist |
| 1 `pasien` | the patient record a registered account is given |
| 1 `dokter` + 1 `dokter_spesialisasi` | no write endpoint for a doctor profile exists |
| 7 `dokter_jadwal` | **no write endpoint for a schedule exists** -- this is the F3-08 root cause |
| 1 `faskes` (apotek) + 2 `apotek_stok` | no write endpoint for a pharmacy or its stock exists |
| 1 `pasien_alergi` | gives the patient a `kontraindikasi` warning without inventing a drug-interaction claim |

It creates **no** `booking`, `konsultasi`, `resep`, `invoice`, `pembayaran`, `pesanan_obat` or
`notifikasi` row. Every one of those is written by the production service, through the production
endpoint, on behalf of an account that really logged in -- so a row's existence is always evidence
that the API produced it, and **no journey in this evidence file is "reachable" because a seeder
inserted it**.

### 2.3 The three demo accounts, and how a human logs in

| account | phone | email | password | role | `tipe` |
| --- | --- | --- | --- | --- | --- |
| Demo Pasien Sehatly | `081000000001` | `demo.pasien@sehatly.test` | `Demo#Pasien2026` | `pasien` | `pasien` |
| Demo Dokter Sehatly | `081000000002` | `demo.dokter@sehatly.test` | `Demo#Dokter2026` | `dokter` | `dokter` |
| Demo Apoteker Sehatly | `081000000003` | `demo.apoteker@sehatly.test` | `Demo#Apoteker2026` | `apoteker` | `apoteker` |

**These are obviously fake and I am saying so plainly:**

- **`0810` is not an allocated Indonesian mobile operator prefix**, so no number here can reach a
  person even if it were dialled. The Indonesian prefixes in service are 0811-0819, 0821-0829,
  0852-0859, 0877-0878, 0881-0889 and 0895-0899.
- **`.test` is reserved by RFC 6761 and can never resolve.**
- **No token and no OTP is committed anywhere.** The only credentials in the repository are these
  three published demo passwords, and `DemoDataSeeder::run()` refuses to create any of them when
  `app()->environment('production')` -- a branch the test exercises and the environment is restored
  in a `finally`.
- **No NIK is written, anywhere.** `pasien.nik` is left `NULL`. See Sec.5.

The three `uuid` values are fixed literals and so are the three bcrypt hashes. That is not
tidy-ness: `users.uuid` is `UNIQUE` and bcrypt is salted, so a re-run that regenerated either would
collide or make "the second run changed nothing" false by construction. With fixed literals the
idempotency test compares **byte-identical** rows rather than merely equal counts.

### 2.4 The four journeys, and the exact API call that reaches each

`tests/Feature/DemoData/DemoJourneyTest.php`. All of it runs on one set of accounts, in this
order, and each step is a real HTTP request with a token minted by the real two-step login
(`POST /auth/login` -> OTP from the bound `OtpSender` -> `POST /auth/otp/verify`; `login` is
asserted to return **no** token, which is the design F3 verified as correct).

| # | F3 journey | the call that now reaches it | result |
| --- | --- | --- | --- |
| 1 | **Prescription + interaction override** | `POST /api/v1/konsultasi/{id}/resep` as the demo **DOCTOR** | **201**, `acknowledgement.diminta = true`, `acknowledgement.catatan_dodio` echoed, a `kontraindikasi` warning in `data.warning` |
| 2 | **Order checkout** | `POST /api/v1/resep/{id}/checkout` as the demo **PATIENT** | **201** |
| 3 | **Order tracking** | `GET /api/v1/pesanan-obat/{id}` as the demo **PATIENT** | **200** with a non-empty `data.pesanan.tracking` |
| 4 | **Doctor half of the medical record** | `POST /api/v1/konsultasi/{id}/rekam-medis` as the demo **DOCTOR** | **201**; the patient then reads it back with `GET /api/v1/rekam-medis/{id}` -> **200** |

> The path in row 1 is `/api/v1/konsultasi/{id}/resep`; the table above repeats the
> `/api/v1/` prefix for the reader. Row 4 is the only doctor-only write among the four, and the
> patient token is still refused 403 on it -- which is asserted, because the point is that the
> *doctor* is now reachable, not that the guard changed.

**F3's four reproductions, before and after, on the same named calls:**

| F3 observation | now |
| --- | --- |
| `POST /konsultasi/12/resep` -> **403** | 201 as the demo doctor |
| `GET /api/v1/pasien/resep` -> **`total: 0`** | the demo patient's own history, non-empty, containing the prescription just written |
| `POST /resep/1/checkout` -> **422** `{"alamat_kirim":["... prohibited"]}` | 201 -- the address is DERIVED from the profile, which the seeder fills in, and the test sends no address at all |
| `GET /pesanan-obat/1` -> **404** | 200 for the demo patient's own order |

### 2.5 What F3's list of unexercisable steps is now

F3 Sec.7 listed fifteen steps it could not perform. This change moves **six** of them, and I am
listing the nine that remain, because a list that only records wins is not evidence:

**Now reachable:** pharmacy verification (`POST /resep/{id}/verifikasi`); prescription compose with
the interaction warning **and its override**; order checkout; order tracking; writing a medical
record; and **a consultation with a doctor actually replying** -- the transcript now contains
lines from both sides, which F3 recorded as "every message in consultation 12 is mine".

**Still not reachable, unchanged by this work:** NIK masking (F3-03, see Sec.5); booking a slot
through the **web UI** -- the SPA still offers no call-to-action on the doctor detail page
(F3-13), so the slot is picked over the API; a production SPA build; the pure-Dart client; and
video/WebRTC. The slot **data** is now there, so F3-13 is a UI gap with a solved backend rather
than a data gap -- that distinction is the whole of what this change contributed to it.

### 2.6 What is still a weakness, stated rather than hidden

- The checkout **requires the caller to name a pharmacy** (`apotek_id`) when the prescription names
  none, which `ResepService` always leaves NULL. That is the documented rule
  (`CheckoutResepRequest`'s own docblock), not something I changed, but it means the demo journey
  passes an id the SPA would have to obtain from `GET /obat/{id}/stok`.
- The demo patient's address is a snapshot string with no address table behind it, which is what
  `pesanan_obat.alamat_kirim` is.
- The demo doctor runs a 08:00-12:00 window on **all seven weekdays**. That is deliberate -- one
  `dokter_jadwal` row makes the doctor bookable one day in seven, and F3 hit an empty picker on a
  date with no row -- but it is a fixture, not a clinic's real rota.

---

## 3. Seeding discipline -- the twice-in-a-row clean-DB transcript

**Scratch database: `sehatly_f3c_seedproof`**, created with
`CREATE DATABASE sehatly_f3c_seedproof CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`. It is
private to this run. `telemedisin_db` and `telemedisin_db_test` were never migrated, rolled back
or seeded by anything below.

```console
$ DB_DATABASE=sehatly_f3c_seedproof php artisan migrate:fresh --seed --force      # RUN 1
   ... 78 migrations DONE ...
   Database\Seeders\ArtikelKategoriSeeder .. 5 ms DONE
   Database\Seeders\RbacSeeder .. 23 ms DONE
   Database\Seeders\DevFixtureSeeder .. 399 ms DONE
   Database\Seeders\DemoDataSeeder .. 88 ms DONE
EXIT RUN 1 = 0

$ DB_DATABASE=sehatly_f3c_seedproof php artisan migrate:fresh --seed --force      # RUN 2
   Database\Seeders\DevFixtureSeeder .. 382 ms DONE
   Database\Seeders\DemoDataSeeder .. 74 ms DONE
EXIT RUN 2 = 0

$ DB_DATABASE=sehatly_f3c_seedproof php artisan db:seed --force                   # RUN 3 (populated)
   Database\Seeders\RbacSeeder .. 14 ms DONE
   Database\Seeders\DevFixtureSeeder .. 409 ms DONE
   Database\Seeders\DemoDataSeeder .. 75 ms DONE
EXIT RUN 3 = 0

$ DB_DATABASE=sehatly_f3c_seedproof php artisan db:seed --force                   # RUN 4 (populated again)
   Database\Seeders\RbacSeeder .. 15 ms DONE
   Database\Seeders\DevFixtureSeeder .. 365 ms DONE
   Database\Seeders\DemoDataSeeder .. 106 ms DONE
EXIT RUN 4 = 0
```

**Four runs, four exit 0, no MySQL 1062 anywhere.** Runs 1 and 2 are the documented boot path
twice. Runs 3 and 4 are the case `DatabaseSeeder`'s own `TRUNCATE` would hide, and they are the
F2 regression re-proved.

The standalone case, which no truncate protects:

```console
$ DB_DATABASE=sehatly_f3c_seedproof php artisan db:seed --class=DemoDataSeeder --force   # x3
   Database\Seeders\DemoDataSeeder .. 43 ms DONE   run 1 EXIT = 0
   Database\Seeders\DemoDataSeeder .. 35 ms DONE   run 2 EXIT = 0
   Database\Seeders\DemoDataSeeder .. 28 ms DONE   run 3 EXIT = 0

$ DB_DATABASE=sehatly_f3c_seedproof php artisan db:seed --class=RbacSeeder --force     # x2 (F2 control)
   Database\Seeders\RbacSeeder .. 31 ms DONE        run 1 EXIT = 0
   Database\Seeders\RbacSeeder .. 20 ms DONE        run 2 EXIT = 0
```

Census read back after all four runs plus the five standalone runs:

```console
$ php census.php sehatly_f3c_seedproof
master_provinsi 38   master_agama 7      master_golongan_darah 4   master_pendidikan 8
master_status_pernikahan 4   master_hubungan_keluarga 7   master_spesialisasi 16
master_penjamin 6     master_metode_pembayaran 14 master_icd10 15     master_icd9cm 6
master_obat 7         master_lab_tindakan 10     master_lab_paket 3    artikel_kategori 6
roles 5   permissions 24   role_permissions 69   user_roles 3
lab_paket_item 7   obat_interaksi 2   faskes 3   dokter_spesialisasi 5
dokter 4   pasien 3   users 8   dokter_jadwal 7   apotek_stok 2   pasien_alergi 1
------------------------------------------------------------------
TOTAL 294

duplicate users.no_telepon: 0
role grants (3):
  081000000003 -> apoteker
  081000000002 -> dokter
  081000000001 -> pasien
dokter_jadwal rows=7 distinct natural keys=7
pasien rows with a non-null nik: 2
```


`294` = the 274 the seeder tree already wrote, plus **20** from `DemoDataSeeder` (3 `users`,
3 `user_roles`, 1 `pasien`, 1 `dokter`, 1 `dokter_spesialisasi`, 7 `dokter_jadwal`, 1 `faskes`,
2 `apotek_stok`, 1 `pasien_alergi`). **`pasien` rows with a non-null NIK: 2** -- those are
`DevFixtureSeeder`'s two pre-existing plaintext NIKs, not mine; the demo patient's is `NULL`
(Sec.5).

### 3.1 Why the seeder is idempotent, per table

| table | natural key | how |
| --- | --- | --- |
| `users` | `no_telepon` `UNIQUE` (`:137`) | `upsert`, updating every other column |
| `pasien` | `user_id` `UNIQUE` (`:220`) | `upsert` |
| `dokter` | `user_id` `UNIQUE` (`:411`) | `upsert` |
| `dokter_spesialisasi` | `(dokter_id, spesialisasi_id)` `UNIQUE` (`:444`) | `upsert` |
| `faskes` | `kode_faskes` `UNIQUE` (`:362`) | `upsert` |
| `apotek_stok` | `(apotek_id, obat_id)` `UNIQUE` (`:840`) | `upsert` |
| `user_roles` | `(user_id, role_id)` PK (`:174`) | `insertOrIgnore` through `RoleAssigner` |
| `dokter_jadwal` | **no unique key** | existence check on `(dokter_id, hari, jam_mulai, jam_selesai, berlaku_mulai)` |
| `pasien_alergi` | **no unique key** | existence check on `(pasien_id, nama_alergen)` |

`upsert` is not usable on the last two: MySQL's `ON DUPLICATE KEY UPDATE` fires on any unique
index, and these tables declare none -- `dokter_jadwal`'s only index is
`idx_jadwal (dokter_id, hari, status_aktif)` (`:487`), which is not unique and does not even cover
the times. Adding one is a migration, which the DDL forbids. An explicit existence check is the
honest substitute, and it keeps **every statement a re-run issues a read or an insert**.

`upsert` rather than `insertOrIgnore` for the first six is the F2 lesson applied: a matching
natural key is not proof of a correct row, so a drifted `nama_lengkap` is **repaired** rather than
kept while the seeder reports success. There is a test for exactly that, and it also asserts the
`users.id` does not move -- `user_roles`, `pasien` and `user_devices` all reference it.

### 3.2 `tests/Feature/DemoData/DemoDataSeederIdempotencyTest.php`

Eight tests, mirroring `RbacSeederIdempotencyTest`'s structure, and including the three weaker
tests that were available and not written:

1. **the control** -- a plain re-insert of a seeded `users.no_telepon`, and of a `user_roles` pair,
   is still MySQL 1062, so "the second run succeeded" cannot pass for the wrong reason;
2. **a second run is byte-identical**, and a third and a fourth add nothing;
3. **no duplicate** -- for the two keyless tables, "no duplicates" is a count of `DISTINCT` natural
   keys rather than a count of rows, which is the only way it can mean anything;
4. **every statement a re-run issues is a read or an insert**, read from the query log, which
   rules out the truncate-then-reinsert shape that would satisfy every other test;
5. **a re-run never empties a table this seeder did not write** -- a developer's own `booking` row
   is planted and must survive two further runs;
6. **a drifted row is repaired without renumbering it**;
7. **the production guard** -- the seeder writes nothing under `production`, and still works
   afterwards;
8. **a re-run never grows a table** -- absolute counts (1 patient, 1 grant, 7 schedule rows,
   2 stock rows, 1 allergy).

```console
$ DB_DATABASE=sehatly_f3c_scratch php artisan test tests/Feature/DemoData/
{"tool":"pest","result":"passed","tests":17,"passed":17,"assertions":182,"duration_ms":16990}
```

### 3.3 `DatabaseSeeder` changed, and why

Two changes, both forced and both documented in that file's docblock:

- `SEEDED_TABLES` gains `pasien_alergi`, `apotek_stok` and `dokter_jadwal`. They carry real
  foreign keys onto tables the reset already truncates, and **`TRUNCATE` with
  `FOREIGN_KEY_CHECKS = 0` does not cascade** -- leaving them untruncated would strand their rows
  against parent ids the re-seed then reassigns to different accounts.
- `DemoDataSeeder` is called **last**, because it needs `roles` (it grants roles), `ObatSeeder`
  (it stocks drugs), `SpesialisasiSeeder` and `MasterWilayahSeeder`.

The docblock's old claim -- *"The 26th owned table, `user_roles`, is written by nobody in this
tree"* -- is now **false**, and I marked it as false in place rather than deleting it. The
`RbacSeeder` half still holds: the kernel grants nothing. What changed is that a seeder in the
tree may now write `user_roles`, and only for accounts `DemoDataSeeder::namaAkun()` names.

---

## 4. Two existing assertions I changed, and exactly how

`tests/Unit/RbacMigrateFreshSeedTest.php` had two assertions that my change genuinely invalidates.
Both are marked `CHANGED BY F3-02` in place, with the reason, and both were made **stronger or
more precise** rather than relaxed.

**`test_no_user_roles_row_is_seeded` -> `test_no_role_is_granted_to_an_account_outside_the_demo_set`.**
It asserted `user_roles` is empty after `migrate:fresh --seed`, on the rule that no seeder assigns
roles. That rule **cannot be true now** and cannot be made true: a doctor and a pharmacist holding
no role are 403 on every route they exist to use, which is precisely the BLOCKER. The replacement
asserts the three demo accounts exist, hold exactly one role each, and that the table holds
**exactly three** grants belonging to **exactly those users**. A count of zero would have been the
wrong assertion; three, on the right users, is a stronger one. A second test,
`test_the_rbac_kernel_alone_grants_no_role`, re-runs `RbacSeeder` on the seeded database and
proves the **kernel** still creates no grant at all -- the part that was actually load-bearing, and
which no later seeder can take away.

**`test_the_todo_18_development_fixtures_still_land`.** It asserted exact table **counts**
(5 users, 2 patients, 3 doctors, 2 facilities, 4 specialisation links) and a count cannot survive
adding a seeder to the chain. Its own docblock says why it exists: *"adding two seeders to a chain
is exactly the change that silently drops somebody else's rows"*. So the assertions became
**natural-key** based -- `DevFixtureSeeder`'s five accounts by `email`, its two patients by
`nomor_rm`, its three doctors by `nomor_str`, its two facilities by `kode_faskes` -- which is
**strictly stronger**: a seeder that dropped or renumbered one of them still fails, and a third
fixture seeder added later will not break it. The two tables no other seeder writes
(`lab_paket_item` = 7, `obat_interaksi` = 2) still assert as counts. A new
`test_the_f3c_demo_accounts_land_too` asserts the three accounts land and that each one
**authenticates with its published password**.

---

## 5. NIK -- the open violation I did not touch, and what my data therefore cannot show

F3-03 is recorded as an **open scope violation owned by the orchestrator and the product owner**:
`pasien.nik` is a plaintext `CHAR(16)` (`:222`) with **no blind-index column in `pasien` at
all**, `NIK_CIPHER_KEY` is absent from `.env`, and a Laravel ciphertext cannot fit in 16
characters. I did not fix it, did not design around it, and added **no migration**.

**Consequence, stated rather than papered over: my demo data cannot demonstrate NIK masking.**
The demo patient's `nik` is `NULL`, which is asserted by
`the demo seeder writes NO NIK, so it adds no new 16-digit identifier`. I chose `NULL` over an
obviously-fake 16-digit placeholder on purpose: `CHAR(16)` in this schema holds **plaintext**,
so any value written there is a value that surfaces unmasked and reads as a person's identifier,
and writing one would make the masking question look answered when nothing in this build can
answer it.

The census in Sec.3 confirms the position: `pasien` rows with a non-null `nik` = **2**, both of them
`DevFixtureSeeder`'s pre-existing plaintext values. My seeder adds **zero**.

*(Unprompted, for whoever owns the schema: `DemoDataSeeder` is the only seeder in the chain that
does **not** write `pasien.nik`, which is why it survived the concurrent NIK-column change in
Sec.7 with no edit of its own.)*

---

## 6. The byte-level scan

`scan-ascii.ps1` reads raw bytes and counts bytes `> 127` per file, and reports a UTF-8 BOM per
file. Over **every file I touched**:

```console
$ scan-ascii.ps1 -Paths <the 14 files: 13 code files + this evidence file>
OK       non-ascii=0    bom=False .omo\evidence\F3C-notifications-and-demo-data.md
OK       non-ascii=0    bom=False app\Services\Konsultasi\KonsultasiService.php
OK       non-ascii=0    bom=False app\Services\Payment\PaymentService.php
OK       non-ascii=0    bom=False app\Services\Resep\ResepVerifikasiService.php
OK       non-ascii=0    bom=False database\seeders\DemoDataSeeder.php
OK       non-ascii=0    bom=False database\seeders\DatabaseSeeder.php
OK       non-ascii=0    bom=False tests\Feature\Notifikasi\NotifikasiTriggerTest.php
OK       non-ascii=0    bom=False tests\Feature\Notifikasi\notifikasi-helpers.php
OK       non-ascii=0    bom=False tests\Feature\DemoData\DemoAkunTest.php
OK       non-ascii=0    bom=False tests\Feature\DemoData\DemoJourneyTest.php
OK       non-ascii=0    bom=False tests\Feature\DemoData\DemoDataSeederIdempotencyTest.php
OK       non-ascii=0    bom=False tests\Feature\DemoData\demo3c-helpers.php
OK       non-ascii=0    bom=False tests\Unit\RbacMigrateFreshSeedTest.php
FAIL     non-ascii=12   bom=False app\Services\Booking\BookingService.php
      line 49: * retried inside the transaction; anything else - including a lock-wait 1205
      line 50: * - propagates so a competitor can observe it.
      line 345: * When a schedule publishes the start, that published slot decides - the
      line 355: * expressible as a schedule row - not even to refuse. Every other type
```

**No BOM anywhere, and the only non-ASCII bytes in the set are 12 em-dashes in
`BookingService.php`'s PRE-EXISTING prose** at lines 49, 50, 345 and 355 -- all of them outside
the hunks I added. Every byte I wrote is ASCII.

`php -l` is clean on all 13 files (only errors would have printed).

### 6.1 A latent defect the scan led me to, which is NOT mine and NOT the DDL's fault

While diagnosing a test failure I found a reproducible, unreported defect:

`master_provinsi.id` is `TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` (`telemedicine_test.sql:59`).
**255 is both the row ceiling and the value MySQL clamps the counter to once it is reached**, and
after that **every** insert into the table fails with

```text
SQLSTATE[HY000]: General error: 1467 Failed to read auto-increment value from storage engine
```

which reads like an InnoDB statistics fault and is not one. Measured on this host:

- a freshly created database reports `master_provinsi auto_increment = 1`;
- after **seventeen** Feature tests that seed the 38-row province list it reports `255` and
  refuses every insert, **for the life of the database**;
- `ALTER TABLE ... AUTO_INCREMENT = 1` does **not** lower it; only dropping and recreating the table
  does, which is why `migrate:fresh` hides it and `db:seed` does not;
- **`telemedisin_db_test.master_provinsi` is sitting at exactly `255` right now.**

The mechanism is that **InnoDB does not roll the auto-increment counter back with a transaction**,
so every test that seeds that table inside `RefreshDatabase`'s wrapper burns 38 permanent counter
values and throws them away. **Six such tests saturate a `TINYINT` key permanently**, and the
failure mode is an error that names neither the table nor the cause.

I did **not** change the schema -- the DDL is read-only law and the fix belongs to whoever owns it.
I did stop my own tests from feeding it: `tests/Feature/DemoData/demo3c-helpers.php` writes the
**one** `master_provinsi` row the demo seeder resolves, at the **explicit** `id = 11` the DDL's own
tuple order assigns to `kode = '31'`, so the counter settles at 12 and never grows. The comment in
that helper says why, with the measurement.

**This is very likely the cause of intermittent suite failures nobody has attributed** -- and it is
a plausible explanation for several of the `1146 Table ... doesn't exist` / `1050 Table ... already
exists` errors I saw from runs racing a second `php artisan test` process in this same working
tree.

---

## 7. The tree moved under me -- reported, not hidden

**Another agent worked in this same working tree throughout my run.** It is visible in the
evidence rather than inferred:

- HEAD advanced past my commits: `154b1f9` -> `14aec3f` (mine) -> `f9c438e` -> `f058a88` (mine) ->
  `442abda` -> `d8feb5d`. My two commits are in that history; I added nothing on top of anyone
  else's work and committed nothing of theirs.
- `442abda feat(pasien): store the NIK as an encrypted payload in nik_cipher TEXT` **edits the
  contract DDL**, which my brief calls read-only law. It is **exactly one line** -- line 222,
  `nik CHAR(16) NULL UNIQUE` -> `nik_cipher TEXT NULL` -- and I proved that by reverting it and
  reproducing the mandated digest `AEFE2247E00F...` exactly (Sec.0.3). **I did not make that edit and I
  have not reverted it.**
- At the moment I measured the main tree it was **red**: 1210 tests, 1103 passed, 2 failed,
  **105 errors**, 0 skipped. Every one traces to that in-flight change: 99 x
  `Unknown column 'nik' in 'field list'` (product code and `DevFixtureSeeder` still selecting a
  column the new migration renamed), 6 x `NIK_CIPHER_KEY is not set`, and the 9 errors in
  `RbacMigrateFreshSeedTest` all originate at `DevFixtureSeeder.php:386` inserting `pasien.nik`.
  None of the 99 or the 9 is in code I wrote.

**So I measured my own commit in an isolated `git worktree` with its own `composer install`, no
junction, and the contract DDL restored to the exact bytes the brief mandates.**

---

## 8. The final full-suite numbers

Isolated worktree at **`f058a88`** (my commit), private database `sehatly_f3c_wt2`,
`telemedicine_test.sql` at the mandated `AEFE2247E00F...`:

```console
$ php artisan test
{"tool":"pest","result":"passed","tests":1193,"passed":1193,"assertions":22812,"duration_ms":636715}
exit=0
```

**The reporter control, asserted before the count is read.** The Pest JSON reporter **omits the
`failed` and `errors` keys entirely when the count is zero**, so a naive parser reads green as
red. I checked the key's presence first:

```console
$ node -e "...const h=k=>Object.prototype.hasOwnProperty.call(j,k);
             console.log('failed key present:',h('failed'),'value=',j.failed);
             console.log('tests=',j.tests,'passed=',j.passed,'assertions=',j.assertions);
             console.log('errors key present:',h('errors'),'errors=',j.errors);
             console.log('skipped key present:',h('skipped'));..."
failed key present: false value= undefined
tests= 1193 passed= 1193 assertions= 22812
errors key present: false errors= undefined
skipped key present: false
```

So: **`failed` absent -> zero; `errors` absent -> zero; `skipped` absent -> zero; `passed == tests`;
exit 0.** The keys are absent precisely because the counts are zero, which is the case the
omission rule exists for, and `passed === tests` is the corroboration.

**Against the brief's baseline** -- 1162 tests, 22541 assertions, 0 failed, 0 skipped -- the delta is
**+31 tests** (12 notification + 17 demo-data + 2 from the `RbacMigrateFreshSeedTest` split) and
**+271 assertions**, with **nothing** previously passing now failing.

**`route:list` is unchanged at 74 operations** -- no route was added, removed or re-guarded.

### 8.1 `pint --test` is already red on this repository, and I did not try to flip it

```console
$ php vendor/bin/pint --test          # whole tree
{"tool":"pint","result":"fail","files":[...]}      # 91 files
```

91 files fail, **88 of which I never touched** -- `app/Enums/PembayaranStatus.php`,
`app/Services/Invoice/InvoiceService.php`, `tests/Contract/*`, `tests/Feature/KonsultasiTest.php`
and so on, with the same fixers this Pint version names everywhere
(`fully_qualified_strict_types`, `ordered_imports`, `unary_operator_spaces`,
`not_operator_with_successor_space`, `line_qualified`). **The committed code does not satisfy this
Pint version**, so running it on the files I touched would make them inconsistent with every other
file in the repository and would reformat hundreds of unrelated lines inside four services I only
added a constructor parameter and a call to. I matched the surrounding committed style instead,
and I am reporting the pre-existing state rather than pretending to have fixed it.

### 8.2 `verify-schema` and the generated contract

`php artisan sehatly:verify-schema` exits 0 on the tree as committed, and the generated contract is
untouched by me -- I added no route, no request rule and no response field.
`sehatly:openapi --check` reports `two exports agree: sha256=b9e30b49ea9ba82b...`, 286466 bytes.

---

## 9. Constraints honoured

| constraint | how |
| --- | --- |
| TDD, failing test first, transcript kept | Sec.1.3 is a real RED->GREEN pair; Sec.2.4 is the same for the journeys; Sec.3.2 is the idempotency file |
| Seeder idempotent, not the F2 regression | Sec.3, four runs plus five standalone runs, and `RbacSeeder` re-proved as a control |
| No `migrate:fresh` / `rollback` on `telemedisin_db` or `telemedisin_db_test` | five private scratch databases, all named in the header |
| `phpunit.xml` untouched | `git diff` on it is empty; the private database is selected by `$env:DB_DATABASE`, which PHPUnit's `<env>` does not override without `force="true"` |
| `telemedicine_test.sql` not modified | Sec.0.3 and Sec.7; the only content change in the tree is another agent's one-line edit, measured and attributed |
| No migration, index or constraint; no DDL column type changed | none added; Sec.6.1 explains why the one real hazard is *reported* and not patched |
| Legacy `sehatly` database untouched | never connected to |
| `web/**`, `packages/**`, `docs/**` untouched | `git diff --stat` on those paths is empty for my commits |
| No `markTestSkipped` | grep finds none in the files I added; suite reports 0 skipped |
| No file I did not create deleted | **Sec.0.2 records the one exception and its repair** -- I emptied `vendor/` by removing a junction and rebuilt it |
| Never `git add -A` | every add in this task is an explicit path list |
| `.omo/plans/` untouched | no checkbox marked |
| Ledger append-only, built with `node -e`, validated before commit | Sec.10 |
| `CarbonImmutable` is a sibling of `Carbon` | no `Carbon` type was introduced or narrowed anywhere; the only new time call is `WaktuIndonesia::now()`, which returns `CarbonInterface` |
| PHP binary | every command prefixed with `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe`; bare `php` is not on `PATH` |

---

## 10. The ledger line

Appended **once**, with `node -e "JSON.stringify(...)"` writing through
`fs.appendFileSync` -- never a shell redirect, because a redirect into that file is what destroyed
62 committed entries in this project before. Before appending, the first 111 lines were hashed; the
prefix is byte-identical afterwards. The new line was then parsed back and the whole file
re-validated.

**Pre-existing, not mine, and left alone:** three ledger lines (55, 66, 70) do not parse as JSON.
They were already unparseable before I appended; I did not rewrite them, because the brief says
append only and never rewrite that file. **108 lines parsed before my append, 109 after.**

---

## 11. `git show --stat`

```console
$ git show --stat 14aec3f        # F3-05
commit 14aec3f
Author: Ahmadz <ahmadz@gmail.com>
Date:   Wed Sep 30 2026

    fix(notifikasi): wire NotificationService to the five real domain events

 app/Services/Booking/BookingService.php            | 32 +-
 app/Services/Konsultasi/KonsultasiService.php      | 17 +
 app/Services/Payment/PaymentService.php            | 13 +
 app/Services/Resep/ResepVerifikasiService.php      | 15 +
 tests/Feature/Notifikasi/NotifikasiTriggerTest.php | 485 +++++++++++++++++++++
 tests/Feature/Notifikasi/notifikasi-helpers.php    | 227 ++++++++++
 6 files changed, 787 insertions(+), 2 deletions(-)
```

```console
$ git show --stat f058a88        # F3-02
commit f058a88
Author: Ahmadz <ahmadz@gmail.com>
Date:   Wed Sep 30 2026

    feat(seed): demo accounts so the four unreachable journeys are reachable

 database/seeders/DatabaseSeeder.php                      |  66 +++--
 database/seeders/DemoDataSeeder.php                      | 569 ++++++++++++++++++++++
 tests/Feature/DemoData/DemoAkunTest.php                  | 155 +++++
 tests/Feature/DemoData/DemoDataSeederIdempotencyTest.php | 337 ++++++++++++++
 tests/Feature/DemoData/DemoJourneyTest.php               | 381 +++++++++++++
 tests/Feature/DemoData/demo3c-helpers.php                | 337 ++++++++++++
 tests/Unit/RbacMigrateFreshSeedTest.php                  |  96 ++-
 7 files changed, 1911 insertions(+), 29 deletions(-)
```

(Exact per-file counts are reproduced by `git show --stat` at those two commits; the command is in
the report so it can be re-run rather than taken on trust.)

---

## 12. Unfinished, and what I would do next

1. **NIK masking is still not demonstrable.** F3-03 is an orchestrator-owned scope violation and
   the fix is a schema change, so Sec.5 stands as a limitation of my data rather than a claim about
   the product. Nothing here moves it.
2. **`dokter_jadwal` saturates.** Sec.6.1. The real fix is to widen `master_provinsi.id` out of
   `TINYINT UNSIGNED`, which is DDL law. Reported, not touched.
3. **Booking through the web UI is still impossible** (F3-13): the slot data now exists and the
   API publishes it, but the SPA still renders no call-to-action on the doctor detail page. That
   is a `web/**` change and out of my remit.
4. **The checkout needs the caller to name a pharmacy** when the prescription names none (Sec.2.6).
   The data is reachable through `GET /obat/{id}/stok`, but a client must make that round trip,
   and the SPA checkout form has no field for it. Reported.
5. **`pint --test` is red on 91 files** and I deliberately did not make it redder or greener
   (Sec.8.1). Somebody should decide whether the committed style or this Pint version is the standard,
   because as it stands CI's `pint --test` step cannot pass.
6. **The main tree is currently red** because of the concurrent NIK work (Sec.7). My two commits are
   green; the tree on top of them is not, and it is not my code. If that work is abandoned rather
   than finished, `442abda` and `d8feb5d` need reverting -- **not by me**.
7. **`vendor/` and the root `node_modules` were emptied by my mistake and rebuilt** (Sec.0.2). They
   are correct now (`composer install` from the committed `composer.lock`, 52 entries, artisan
   boots), but the working tree was disturbed and anyone whose PHP process held an autoloaded
   class at that moment will have needed a restart.
