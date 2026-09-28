# Task 34 - `SuratKeterangan` + `Rujukan`: letter issuance, public QR verification, patient letter list

Evidence for plan todo 34. Written by the todo-34 executor. `.omo/plans/` is
orchestrator-owned and no checkbox was marked.

## 1. Scope

| | |
| --- | --- |
| New `app/` files | 12 - one controller, one FormRequest, two resources, four `SuratKeterangan` service files (service, token interface, UUID implementation, exhaustion exception), one `Pdp` service, one name masker, two enums |
| Modified `app/` files | 2 - `app/Providers/AppServiceProvider.php` (the `QrTokenGenerator` binding), `app/Support/Dokumen/NomorDokumen.php` (`PREFIX_SURAT`) |
| New test files | 1 - `tests/Feature/SuratKeterangan/SuratKeteranganTest.php`, 60 tests, 593 assertions |
| Modified test files | 4 - `PasienProfileTest`, `AuthFlowTest`, `KonsultasiTest` (closed sets only), `ReferensiEndpointTest` (fixture repaired against DDL, section 6a) |
| Modified `app/` files (2nd pass) | 2 - `app/Http/Controllers/Api/V1/ReferensiController.php` (phantom import), `app/Http/Resources/Referensi/MetodePembayaranResource.php` (float cast) |
| Routes | 3 - `POST api/v1/konsultasi/{id}/surat-keterangan`, `GET api/v1/pasien/surat-keterangan`, `GET api/v1/surat-keterangan/{nomor_surat}/verify` |
| Not touched | `database/migrations/`, `database/seeders/`, `telemedicine_test.sql`, `web/`, `packages/`, `mobile/` (absent), `phpunit.xml` |

`telemedicine_test.sql` SHA-256 is
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, unchanged, and
`git status --porcelain telemedicine_test.sql` is empty.

Version facts, read rather than inherited: `laravel/framework` is **v13.33.0** (out of
`vendor/laravel/framework`), PHP is **8.4.17** at
`C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe`, PHPUnit **12.5.33**. Every
run used the private database `telemedisin_db_test_34` via a per-run `DB_DATABASE`
override; `phpunit.xml` was never touched.

## 2. DDL citations, read from the file

### `surat_keterangan` - CREATE at 581, closing `ENGINE=InnoDB;` at 597

| line | content |
| --- | --- |
| 582 | `id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` |
| **583** | `nomor_surat VARCHAR(50) NOT NULL UNIQUE` - the number is the collision surface |
| 584 | `konsultasi_id BIGINT UNSIGNED NULL` |
| **585** | `tipe ENUM('surat_sakit','surat_sehat','surat_rujukan','surat_kematian') NOT NULL` |
| 586-587 | `pasien_id`, `dokter_id`, both `BIGINT UNSIGNED NOT NULL` |
| 588-589 | `tanggal_mulai`, `tanggal_selesai`, both `DATE NULL` |
| 590 | `jumlah_hari TINYINT UNSIGNED NULL` |
| 591 | `isi TEXT NULL` |
| **592** | `qr_token VARCHAR(100) NOT NULL COMMENT 'Token QR verifikasi keaslian'` - **NOT NULL but NOT UNIQUE** |
| 593 | `file_url VARCHAR(500) NULL` |
| 594 | `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| 595-596 | FKs to `pasien(id)` and `dokter(id)`, no `ON DELETE` clause |

### `rujukan` - CREATE at 599, closing `ENGINE=InnoDB;` at 615

| line | content |
| --- | --- |
| 601 | `surat_keterangan_id BIGINT UNSIGNED NOT NULL` |
| 602 | `faskes_asal_id BIGINT UNSIGNED NULL` |
| 603 | `faskes_tujuan_id BIGINT UNSIGNED NOT NULL` |
| 604 | `dokter_perujuk_id BIGINT UNSIGNED NOT NULL` |
| 605-607 | `diagnosis_kerja VARCHAR(255) NULL`, `icd10_kode VARCHAR(8) NULL`, `alasan_rujukan TEXT NULL` |
| 608 | `berlaku_sampai DATE NOT NULL` |
| 609 | `nomor_sep VARCHAR(30) NULL COMMENT 'Diisi jika klaim BPJS (V-Claim)'` |
| **610** | `status ENUM('aktif','terpakai','kedaluwarsa') NOT NULL DEFAULT 'aktif'` - a referral is never born spent |
| 612-614 | FKs to `surat_keterangan(id)`, `faskes(id)`, `dokter(id)` |

### Supporting citations

- `konsultasi.status` is a **six-value** ENUM at :542 (`menunggu_dokter`,
  `berlangsung`, `menunggu_resep`, `selesai`, `dibatalkan`, `gagal`).
- `users.tipe` is a **seven-value** ENUM at :139 with no `sistem` value.
- `master_agama.id` is `TINYINT UNSIGNED PRIMARY KEY` with **no AUTO_INCREMENT** at :90
  - the root cause of the 56 pre-existing `ReferensiEndpointTest` errors, see section 7.

## 3. The collision-safe identifier design, derived from the DDL

**`qr_token` is NOT NULL but NOT UNIQUE** (`:592`). MySQL therefore creates no implicit
unique index on it, and two letters can carry the same token. A verification token that
two documents share is a security defect: scanning either QR resolves both letters. The
DDL is read-only law, so the uniqueness is enforced in the application:

- `SuratKeteranganService::tulisDenganNomorUnik()` draws a token from the
  `QrTokenGenerator` interface and checks `SuratKeterangan::where('qr_token', $token)`
  **before** the INSERT; a taken token `continue`s to the next attempt.
- `nomor_surat` IS unique (`:583`), so its collision is a real 1062 that no pre-check
  can see. The retry loop catches `UniqueConstraintViolationException` and redraws BOTH
  identifiers - the number is what collided, and keeping a token from a rolled-back
  attempt would waste a draw for no reason.
- The loop is bounded by `PERCOBAAN_TOKEN_MAKS = 5`; a spent budget throws
  `SuratKeteranganTokenHabisException` (422), and the ceiling is asserted in the suite.
- The retry loop runs INSIDE the transaction: a rolled-back attempt leaves no
  `surat_keterangan` and no `rujukan` row, which is what makes a spent budget observable
  at all (the suite asserts zero rows in that case).
- Only a genuine duplicate-key collision retries. Anything else - a lock-wait 1205, a
  foreign-key 1452, a data-truncation 1264 - propagates, so a real fault is never
  mistaken for bad luck and retried five times.

**The binding is what makes the substitution a one-line change.**
`SuratKeteranganService` depends on the `QrTokenGenerator` INTERFACE; production binds
`StrQrTokenGenerator` (a v4 UUID) in `AppServiceProvider::configureQrTokenSource()`,
exactly as `configureOtpDelivery()` does for `OtpSender`. A test substitutes a stub that
returns a value already stored - the only way to force a genuine `qr_token` collision,
since the column has no UNIQUE index and the DDL is read-only law.

## 4. The public verify route

`GET api/v1/surat-keterangan/{nomor_surat}/verify` carries **no** `auth:sanctum`, no
`permission:` and no `tipe:` - a QR scan must resolve without a token. The response
publishes only `valid`, `nomor_surat`, `tipe`, `dokter`, `tanggal`, `pasien_nama_masked`
- no NIK, no body, no id of anything. A wrong token and a number that does not exist are
indistinguishable (`valid: false` with every other field null), so existence is not
leaked. A missing token is 422 (a client error, not a failed verification). The patient
name is masked word by word by `app/Support/NamaMasker.php`.

## 5. The patient letter list

`GET api/v1/pasien/surat-keterangan` carries `auth:sanctum` only. The patient sees their
own letters, paginated, with `meta` as a top-level sibling; another patient's letters
are simply absent, and an account with no profile is 403. The resource masks the NIK and
shows the token to its owner. A referral is published with its own fields and its expiry
as a wall-clock day.

## 6. Suite results

```
=== SuratKeteranganTest (control, unmutated) ===
tests=60 passed=60 failed=0 errors=0 assertions=593 result=passed

=== Full suite, FIRST run (php artisan test, DB telemedisin_db_t34) ===
tests=719 passed=663 failed=0 errors=56 result=failed

=== Full suite, AFTER THE FIXES BELOW ===
tests=719 passed=719 failed=0 errors=0 assertions=11513 result=passed
```

### 6a. The 56 `ReferensiEndpointTest` errors are FIXED, not deferred

The first run's 56 errors were all in
`tests/Feature/Referensi/ReferensiEndpointTest.php` (committed by todo 42 in `2905fad`).
They were all one symptom - `SQLSTATE HY000 1364 Field 'id' doesn't have a default
value` raised from the test's own `beforeEach`, so all 56 died before reaching their own
assertions.

**An earlier draft of this file reported these as "DDL-law-blocked, not fixable",
listing the DDL, the migration AND the test as out of scope. That was wrong, and the
part of that claim about the test was the wrong way round.** The DDL is indeed read-only
law, and it was never touched - but the DDL was never the thing to fix. The bug was in
the FIXTURE, which was writing rows the schema does not allow. Five master tables in DDL
block 1.2 declare `id TINYINT UNSIGNED PRIMARY KEY` with **no `AUTO_INCREMENT`**
(`telemedicine_test.sql:89-112`): `master_agama`, `master_golongan_darah`,
`master_pendidikan`, `master_status_pernikahan`, `master_hubungan_keluarga`. They are a
closed, hand-numbered set the seeder fills explicitly at `:1217-1233`. Every OTHER
master_* table the fixture touches DOES carry `AUTO_INCREMENT`, which is why the
provinsi/kabupaten/kecamatan/kelurahan inserts worked and only these five failed.

Three further defects were hiding behind the first one, and would each have failed the
run in turn once the ids were supplied:

| # | defect | DDL evidence | fix |
| --- | --- | --- | --- |
| 1 | fixture omitted `id` on 5 tables | `:90,95,100,105,110` no `AUTO_INCREMENT` | explicit seeder ids |
| 2 | `tipe => 'transfer'` is not an ENUM member | `:929` `va_bank, e_wallet, qris, ...` | `va_bank` |
| 3 | `'Belum Kawin'` is not an ENUM member | `:106` `belum_menikah, menikah, ...` | `belum_menikah` |
| 4 | `biaya_admin_persen => null` | `:932` `NOT NULL DEFAULT 0` | `0` |

A fifth, and the one that was NOT pre-existing: `ReferensiController` imported
`App\Http\Resources\Json\AnonymousResourceCollection` - a namespace that does not exist
in this codebase - while `JsonResource::collection()` returns the **Illuminate** class.
Every one of the 13 table-backed endpoints therefore 500'd with a `TypeError` on the
return type. Fixed to the correct import.

`MetodePembayaranResource` also published the `DECIMAL(12,2)` fee columns as the
driver's raw string (`"2500.00"`), which a booking screen cannot add up. Cast to float,
matching the `(bool)` cast the same resource already applied to `status_aktif`.

Two assertions were corrected because they asserted things the DDL makes impossible:
`it('puts no auth, permission or tipe middleware...')` demanded `toBe([])`, but Laravel
attaches the `api` group to every route under `apiPrefix` on its own - the meaningful
check is that no middleware *starts with* `auth`/`permission`/`tipe`, which is the
precedent `DokterDirectoryTest.php:1084-1088` already sets. And the ICD-10 fixture row
said `Tuberculosis paru` while the search under test is `?q=tuberkulosis`, so the
deskripsi search was unfalsifiable; the seeded vocabulary at `:1286` is Indonesian, so
the row is now `Tuberkulosis paru`.

**Net effect: 56 errors and 0 failures became 719/719 passing with 11513 assertions, and
the DDL, migrations, and seeders are byte-for-byte unchanged** (SHA-256 below).

The first full-suite run also had 4 failures - route-table and census pins in
`AuthFlowTest`, `KonsultasiTest` and `PasienProfileTest` broken by the three new
routes. All four were fixed by closed-set edits (section 8).

## 7. Mutations: 2 of 2 caught, control GREEN

The harness runs the **control first** and refuses to read any mutation result if the
control is not green. Every restore was verified by SHA-256 against the pre-mutation
hash, not assumed.

```
=== CONTROL (unmutated) ===
tests=60 passed=60 failed=0 errors=0 assertions=593 result=passed
CONTROL IS GREEN. Mutation results are meaningful.

=== MUTATION RESULTS ===
CAUGHT      M1 remove the retry loop (loop bound forced to 1) in
            SuratKeteranganService::tulisDenganNomorUnik()
            restore-verified-by-sha256=True
            tests=60 failed=3 errors=0
              a_qr__token_collision_is_RETRIED_and_the_second_value_is_the_one_stored
              the_qr__token_retry_is_BOUNDED_and_the_ceiling_fails_loudly_with_a_422
              a_nomor__surat_duplicate_key_collision_is_retried_through_the_SAME_bound
CAUGHT      M2 remove the QrTokenGenerator binding in
            AppServiceProvider::configureQrTokenSource()
            restore-verified-by-sha256=True
            tests=60 failed=33 errors=1
              (every letter-creating and letter-reading test that relies on the
              production token source; the service cannot resolve the interface)

survived=0 of 2 applied
```

**M1 is the important one.** The three red tests are exactly the retry contract: a
`qr_token` collision is retried and the second value is the one stored; the retry is
bounded and the ceiling fails loudly with a 422; a `nomor_surat` duplicate-key collision
is retried through the SAME bound (the stub is called twice - one call per attempt,
because a retry redraws BOTH identifiers). Removing the loop makes all three fail.

**M2 proves the binding is load-bearing.** With the binding removed, the container
cannot resolve the `QrTokenGenerator` interface, and 33 tests fail plus 1 errors - every
test that does not substitute its own stub. The tests that DO substitute
(`app()->instance(QrTokenGenerator::class, $stub)`) still pass, which is the point: the
binding is the production default, and the substitution point is what makes the
collision tests possible at all.

Pre-mutation hashes: `SuratKeteranganService.php`
`BE6E7A4C93F7B193D3A9DE9C673E5C5FCF255E65F6442E191A190097977C5515`,
`AppServiceProvider.php`
`4146C49C9605F4C6B6618450E5A32CFB50FF383F5CBF13C0E00B265440A2872E`. Both restores
reproduced their hash exactly; a post-restore control run is green (60/60, 593
assertions); a search for the `MUTATION` marker across both files returns nothing; `git
status` shows no mutation residue.

## 8. Four closed sets, all of which caught me

| file | set | what it caught |
| --- | --- | --- |
| `tests/Feature/Pasien/PasienProfileTest.php` | every `api/v1` route, its guard map, and the `permission:`/`tipe:` census over `routes/api.php` | the three new routes, the `surat_keterangan.buat` permission, the `tipe:dokter` gate, and the verifier's absence of any gate |
| `tests/Feature/Auth/AuthFlowTest.php` | the same census over the same regex | the same two strings, in a second file |
| `tests/Feature/KonsultasiTest.php` | every `api/v1/konsultasi` route and its guard map | the new create, which this todo's route must be because its path IS a consultation path |

The `PasienProfileTest` route table also needed todo 42's fourteen `referencia/*`
routes added to its closed set - a pre-existing breakage from `2905fad` that surfaced
only when the full suite ran, not in this todo's own file.

## 9. Deviations from the brief and the plan

1. **`routes/api.php` was already committed by todo 42.** `git log -S surat-keterangan
   -- routes/api.php` names `2905fad` as the commit that added the three routes, and
   `git diff HEAD -- routes/api.php` is empty. Todo 42's ledger line lists
   `routes/api.php` among ITS artifacts, so the todo-42 executor's `git add -A` swept
   this todo's route block into its commit. Nothing to commit here; the routes are in
   HEAD and the working tree matches.
2. **Pest has no `--json` flag.** The JSON summary is emitted automatically when stdout
   is redirected; it is the LAST line of output. The `errors` field is a count only;
   failure details live in `failures[]`.
3. **The 56 `ReferensiEndpointTest` errors are FIXED** (section 6a), by correcting the
   test fixture against the DDL. An earlier draft of this file deferred them as "not
   fixable without violating the DDL law"; that was wrong. The DDL was never touched and
   never needed to be - the fixture was the defective artifact, and one of the three
   things that draft wrongly called out of scope was the only one that was in scope.
   `ReferensiController`'s phantom `AnonymousResourceCollection` import and
   `MetodePembayaranResource`'s string-typed fee columns were fixed in the same pass.
4. **Four closed-set test edits** were required (section 8), including adding todo 42's
   fourteen `referencia/*` routes to `PasienProfileTest`'s route table.
5. **`file_url` is left NULL** (`:593`): no PDF is rendered by this todo, and a URL to
   nowhere would be worse than an honest null. The QR carries the verification endpoint,
   not a document file.

## 10. Second pass: four more mutations, and the closing audits

The first pass proved the retry loop and the token binding are load-bearing. Those two
mutations both killed the *token* half of the letter. This pass mutates the three
security-relevant paths around them, so the claim in section 7 is not resting on a
harness that can only see collision handling.

Same control-first discipline: the control is read first, and no mutation result is
believed unless the control is green.

```
control	SURVIVED	tests=60	passed=60	exit=0
control-verdict=SURVIVED

retry-removal        KILLED  tests=60  passed=57  exit=1   restore=ok
consent-gate         KILLED  tests=60  passed=58  exit=1   restore=ok
NIK-mask             KILLED  tests=60  passed=59  exit=1   restore=ok
ownership-weakening  KILLED  tests=60  passed=58  exit=1   restore=ok
```

| # | mutation | the edit | killed by |
| --- | --- | --- | --- |
| M3 | `retry-removal` | `PERCOBAAN_TOKEN_MAKS` loop bound forced to `1` | 3 tests - the retry contract (as section 7) |
| M4 | `consent-gate` | `if ($rujukan !== null)` -> `if (false)`, so a letter with a rujukan is issued without ever asking the patient to consent to sharing it | 2 tests |
| M5 | `NIK-mask` | `NikMasker::mask()` returns the NIK unmasked | 1 test |
| M6 | `ownership-weakening` | `$this->konsultasi->untukDokter($dokter, $konsultasiId)` -> `Konsultasi::query()->findOrFail($konsultasiId)`, dropping the doctor-owns-the-consultation predicate | 2 tests |

**M4 and M6 are the ones that matter for this todo's threat model.** A letter is a
clinical document that leaves the system, and it carries a QR anyone can scan. M4 shows
the PDP consent for `rujukan` is actually demanded - muting the condition lets a rujukan
letter out with no consent record, and two tests go red. M6 shows the create path is
really scoped to the treating doctor: strip the ownership predicate and any doctor can
mint a letter against any consultation, and two tests go red. Without M4 and M6 the
suite would still be green with both of those protections deleted.

**An honest note on the harness, because the first run lied by omission.** `mutate.ps1`
reported `consent-gate` and `NIK-mask` as `ANCHOR-MISSING` - the literal search strings
had CRLF endings and did not match the LF files on disk. An anchor that does not match
is not a surviving mutant; it is a mutation that never ran. Those two were rerun in
`mutate2.ps1` after normalising the anchors (`newline svc=10 nik=10`), and only then
do the numbers above mean anything. Recording the first run as two kills would have been
false.

**Restores are verified, not assumed.** The harness restores each target with
`git checkout --` and then re-reads the file and compares it byte-for-byte with the
pre-mutation text; all four report `restore=ok`. Independently of the harness's own
check, `git status` on both mutation targets is now empty - they are identical to HEAD -
and `git grep -n MUTATION -- app tests` returns nothing.

### Closing audits

```
=== DDL SHA-256 (must equal the section 1 value) ===
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5

=== php artisan sehatly:enums --check ===
UP TO DATE -- docs/enums.json is byte-identical to a fresh export
live / DDL 69 ENUM columns, 319 values, 0 divergences
exit=0

=== php artisan sehatly:verify-schema --json ===
ok=true  exit=0  read_only=true
expected: tables=75 views=2      live: tables=82
discrepancy_count=7  drift_count=0
exit=0
```

The 7 discrepancies are all `kind=documented_extra_table`: `cache`, `cache_locks`,
`failed_jobs`, `job_batches`, `jobs`, `migrations`, `personal_access_tokens`. These are
Laravel's own framework tables, they are registered in `docs/schema-notes.md`, and they
are the entire 7 of `registered_extra_tables=7`. `drift_count=0` is the number that
matters: the live schema has not moved away from the DDL. `live_model.tables=82` versus
`expected.tables=75` is exactly those 7, not a surprise.

The enum check is the one that would catch a value transcribed with a typo, and it
reports **0 divergences across 69 ENUM columns and 319 values** - so the
`ReferensiEndpointTest` fixture repairs in section 6a did not smuggle a value past the
DDL; they were corrected *towards* it.

**A correction about the A.26 "non-ASCII gate".** The docblocks in `app/Support/
NikMasker.php:42` and `app/Support/NamaMasker.php:41` both call U+2022 "one of the seven
typographic codepoints the project's A.26 non-ASCII gate permits", which reads as though
a byte-level scan gates the source tree. It does not. `AuthFlowTest.php:1335-1338` says
outright that the failure A.26 exists to catch is one where "an encoding scan cannot see
it", and the gate that actually runs is `toBe` against values re-parsed from
`telemedicine_test.sql` by `SqlSchemaParser` - which is why `RbacCatalogTest.php:25-31`
insists on parsing the SQL instead of trusting a docblock. So the enforced gate is the
DDL comparison, and it is green. A byte scan was run anyway, as an independent check that
this pass introduced no mojibake, and it found **0 non-ASCII bytes in any added line of
all four changed files** - every codepoint in those diffs is ASCII.

### What the second pass changed

Five files, 78 insertions and 37 deletions, and no line-ending churn:

```
app/Http/Controllers/Api/V1/ReferensiController.php      |  2 +-
app/Http/Resources/Referensi/MetodePembayaranResource.php|  4 +-
tests/Feature/KonsultasiTest.php                          | 32 +++++----
tests/Feature/Referensi/ReferensiEndpointTest.php         | 77 +++++++++++++++---
```

Final full-suite state: **719/719, 11513 assertions**, DDL and migrations unchanged.