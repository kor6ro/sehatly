# Task 4 - RBAC kernel: `permission:` and `tipe:` middleware plus seeders

Branch `feat/sehatly-telemedicine`. Executed after todo 18 per plan appendix **A.12**,
in parallel with todo 19 (models) which was building `app/Models/**` concurrently.

**Every statement below is a measurement.** Where a figure is arithmetic rather than
measured, it says so, because that distinction is the one this project has been bitten
by repeatedly (plan appendix A.22, A.23, A.25).

---

## 1. What shipped

| Path | Kind | Lines | Purpose |
| --- | --- | --- | --- |
| `app/Support/Rbac/RbacCatalog.php` | new | 421 | The single source of truth: 7 `tipe` values, 5 roles, 24 permission codes, the role -> permission map, the display-name rule |
| `app/Support/Rbac/Caller.php` | new | 114 | One implementation of "who is calling, and what is their `users.tipe`" for both middlewares |
| `app/Support/Rbac/RoleAssigner.php` | new | 176 | `user_roles` grant/revoke/read, the `AssignRole` helper todo 19 wraps |
| `app/Http/Middleware/EnsurePermission.php` | new | 155 | The `permission:` gate, one `EXISTS` query |
| `app/Http/Middleware/EnsureUserType.php` | new | 114 | The `tipe:` gate, restricted to the DDL's ENUM |
| `bootstrap/app.php` | edited | +30/-0 | Registers the two aliases |
| `database/seeders/RbacSeeder.php` | new | 244 | 5 roles, 24 permissions, 69 grants |
| `database/seeders/DatabaseSeeder.php` | edited | +58/-10 | Calls `RbacSeeder`, owns the 4 RBAC tables, two doc corrections |
| `tests/Support/RbacTestPrincipal.php` | new | 127 | Minimal local `Authenticatable` (todo 19 owns the real model) |
| `tests/Feature/RbacMiddlewareTest.php` | new | 342 | 18 middleware tests |
| `tests/Feature/RbacCatalogTest.php` | new | 288 | 16 catalogue/DDL-parity tests |
| `tests/Unit/RbacMigrateFreshSeedTest.php` | new | 209 | 7 end-to-end `migrate:fresh --seed` tests |

**43 new tests, 364 assertions, all passing.** `pint --test` on the whole repository:
`{"tool":"pint","result":"passed"}`, exit 0. `php -l` clean on all 12 authored PHP files.

### Middleware aliases registered

`bootstrap/app.php`, inside the existing `withMiddleware()` closure, using the
documented Laravel 13 form:

```php
$middleware->alias([
    'permission' => EnsurePermission::class,
    'tipe' => EnsureUserType::class,
]);
```

Usable as `->middleware(['auth:sanctum', 'permission:booking.buat'])`. Comma-separated
values mean **any of**: `tipe:admin,superadmin`, `permission:resep.lihat,resep.verifikasi`.

`RbacMiddlewareTest::test_both_middlewares_are_registered_under_their_route_aliases`
asserts the two entries by class name, so a rename that dropped an alias fails the suite.

---

## 2. The permission vocabulary - every name and its justification

**No name was invented.** The 24 codes are the plan's own list (its todo-4 line names 23,
its acceptance criteria name `resep.verifikasi`), and the `<resource>.<aksi>` shape is the
DDL's own convention. `telemedicine_test.sql:159`:

```
kode VARCHAR(100) NOT NULL UNIQUE COMMENT 'cth: rekam_medis.lihat, resep.buat',
```

Both of the DDL's own example codes are real catalogue codes, which is asserted:
`RbacCatalogTest::test_every_permission_code_follows_the_ddl_own_naming_convention`
re-derives the two example codes out of the SQL with a regex and checks them against
`RbacCatalog::PERMISSIONS` - so the convention is read from the contract, not from a
constant sitting next to it.

| # | `permissions.kode` | `permissions.nama` | Source of the name |
| --- | --- | --- | --- |
| 1 | `booking.buat` | Buat Booking | plan todo 4 |
| 2 | `booking.lihat` | Lihat Booking | plan todo 4 |
| 3 | `booking.batal` | Batal Booking | plan todo 4 |
| 4 | `jadwal.lihat` | Lihat Jadwal | plan todo 4 |
| 5 | `konsultasi.mulai` | Mulai Konsultasi | plan todo 4 |
| 6 | `konsultasi.chat` | Chat Konsultasi | plan todo 4 |
| 7 | `konsultasi.selesai` | Selesai Konsultasi | plan todo 4 |
| 8 | `rekam_medis.lihat` | Lihat Rekam Medis | plan todo 4 **and** the DDL comment `:159` |
| 9 | `rekam_medis.simpan` | Simpan Rekam Medis | plan todo 4 |
| 10 | `rekam_medis.final` | Final Rekam Medis | plan todo 4 |
| 11 | `surat_keterangan.buat` | Buat Surat Keterangan | plan todo 4 |
| 12 | `resep.buat` | Buat Resep | plan todo 4 **and** the DDL comment `:159` |
| 13 | `resep.lihat` | Lihat Resep | plan todo 4 |
| 14 | `resep.verifikasi` | Verifikasi Resep | plan todo 4 acceptance criteria + todo 39 pharmacist endpoint |
| 15 | `obat.cari` | Cari Obat | plan todo 4 |
| 16 | `pesanan.buat` | Buat Pesanan | plan todo 4 |
| 17 | `pesanan.lihat` | Lihat Pesanan | plan todo 4 |
| 18 | `pembayaran.bayar` | Bayar Pembayaran | plan todo 4 |
| 19 | `promo.validasi` | Validasi Promo | plan todo 4 |
| 20 | `notifikasi.lihat` | Lihat Notifikasi | plan todo 4 |
| 21 | `audit.lihat` | Lihat Audit | plan todo 4 |
| 22 | `pdp.kelola` | Kelola PDP | plan todo 4 |
| 23 | `dokter.lihat` | Lihat Dokter | plan todo 4 |
| 24 | `dokter.profil` | Profil Dokter | plan todo 4 |

`nama` is **derived, not authored**: `ucfirst(action) + ' ' + ucfirst-words(resource)`,
with one documented acronym exception (`pdp` -> `PDP`, because the DDL creates
`persetujuan_pdp` at `:1134` and the plan's todo 47 calls it "PDP consent" in prose).
`RbacCatalogTest::test_every_display_name_is_derived_from_its_own_code` asserts every one
of the 24 equals `displayNameFor()` of its own key, so a hand-typed label that drifts is
a test failure. The rule produces mildly stiff Indonesian in two places
("Bayar Pembayaran", "Final Rekam Medis"); that trade is recorded in the class docblock
and accepted deliberately, because these are admin-facing labels, not user-facing copy.

`permissions.kode` is `VARCHAR(100)` (`:159`) and `permissions.nama` is `VARCHAR(100)`
(`:160`); both lengths are asserted per code.

### Two names deliberately NOT created

- **`booking.create`, `booking.cancel`** - English verbs. The DDL's example and the plan
  both use Indonesian. `EnsurePermission` treats an unknown code as a `LogicException`
  (HTTP 500) rather than a 403, so `permission:booking.create` is a build-breaking
  mistake instead of a route that mysteriously denies every caller forever while
  blaming the caller's token. Asserted:
  `test_an_unknown_permission_code_fails_loudly_instead_of_denying_everyone`.
- **anything for `perawat` or `kurir`** - see the `tipe` section.

### The role -> permission map, and the rule that produced it

Rule: **grant a permission to a role only where the plan names that role as a consumer
of the action, or where the action is intrinsic to that role's own data.** Where the plan
is silent, the narrow reading was taken and the gap is recorded rather than filled.

| Role | `users.tipe`? | Grants | Reasoning, per grant |
| --- | --- | --- | --- |
| `pasien` | yes | 13 | `booking.buat/lihat/batal` = todo 27's patient surface (`POST /api/v1/booking`, `GET /api/v1/pasien/booking`, `PUT /api/v1/booking/{id}/batalkan`); `jadwal.lihat` to pick a slot; `konsultasi.chat` (patient is half a consultation); `rekam_medis.lihat` and `resep.lihat` because todo 39 says `GET /api/v1/resep/{id}` is readable by "the prescribing doctor, the patient, and a pharmacist"; `pesanan.*` + `pembayaran.bayar` because the patient is the payer; `notifikasi.lihat`; `dokter.lihat`/`dokter.profil` for the directory. **No clinical write** - a patient may read their own record, not write one. |
| `dokter` | yes | 16 | `konsultasi.mulai`/`selesai` are doctor-only per todo 32; `rekam_medis.simpan`/`final` are the doctor's SOAP write from the same todo; `resep.buat` + `obat.cari` are todo 39's e-prescription creation and its "doctor-only (`tipe:dokter`)" medicine search; `surat_keterangan.buat` is todo 34; `booking.lihat`/`batal` are todo 27's doctor-side list and its cancellation, which the plan says records `dibatalkan_oleh` *from `users.tipe`* "so a doctor cancelling yields `'dokter'`". |
| `apoteker` | yes | 6 | `resep.verifikasi` is todo 39's pharmacist-only endpoint; `resep.lihat` + `pesanan.lihat` are what verification and fulfilment read; `dokter.lihat`/`profil` + `notifikasi.lihat` are shared surfaces. **`obat.cari` deliberately absent** - see finding 3. |
| `admin` | yes | 10 | `promo.validasi` + `pdp.kelola` are the two administrative registries. `audit.lihat` is separated from everything clinical **on purpose**: todo 47's own acceptance criterion argues that widening `audit.lihat` "widens the blast radius", so it goes to no role that also holds a clinical write. **No `rekam_medis.*`, no `resep.buat`, no `konsultasi.*`** - an administrator is not a clinician, and giving the role clinical write would put a write-capable grant on the account type that can read the audit log. |
| `superadmin` | yes | **24 (all)** | Written out in full, not computed, so the seeded table *is* the policy. |

**69 grants total = 13 + 16 + 6 + 10 + 24.** Measured, not arithmetic: see section 6.

**There is no code-level `superadmin` bypass anywhere in the middleware.** A hidden
bypass would make every permission revocable in name only and would put a security
policy in code where a data change cannot reach it.
`test_superadmin_holds_exactly_the_whole_catalogue` and
`test_the_seeded_superadmin_holds_every_permission_live` both assert the live data.

The map is deliberately **data, not code**: todos 20/21/22/47 can correct it with no code
change and no migration.

---

## 3. The `tipe` vocabulary - read from the DDL, and checked against it every run

`telemedicine_test.sql:139`, verbatim:

```
  tipe ENUM('pasien','dokter','perawat','apoteker','kurir','admin','superadmin') NOT NULL DEFAULT 'pasien',
```

`RbacCatalog::USER_TYPES` is those seven values in that order. **This is checked, not
asserted in prose**: `RbacCatalogTest::test_the_user_type_list_is_the_DDL_enum_verbatim`
re-parses `telemedicine_test.sql` with the project's own
`App\Support\Schema\SqlSchemaParser` - the same parser `sehatly:verify-schema` uses -
reads `users.tipe`'s canonical type string `enum('a','b',...)`, and compares with
`toBe`, which checks **order as well as membership** (ENUM stores an ordinal index).
A second test asserts the count is 7 separately, so a truncated read cannot pass by
agreeing with a catalogue that is wrong in the same way.

| Value | A role? | Notes |
| --- | --- | --- |
| `pasien` | yes | |
| `dokter` | yes | |
| `perawat` | **no** | DDL account type with no role. See finding 2. |
| `apoteker` | yes | |
| `kurir` | **no** | DDL account type with no role. See finding 2. |
| `admin` | yes | |
| `superadmin` | yes | |

**Every role name is a `tipe` value**, and that is a checked invariant
(`test_every_role_name_is_a_DDL_user_type`). It is not a coincidence: `:519` records
`booking.dibatalkan_oleh` as taken *from* `users.tipe`, so account type and role describe
the same axis, and a role named `dokter_umum` or `superadmin_web` would be a second
conflicting vocabulary.

`tipe:` accepts any of the seven and rejects everything else with a 500, never a 403:
`tipe:doktor` is a typo, and a 403 would report it as an authorisation decision about a
real user and point the investigation at the wrong file. Asserted by
`test_tipe_rejects_a_value_outside_the_DDL_enum_instead_of_403ing`.

---

## 4. Behaviour

### `permission:` - one query, flat in the caller's roles

```sql
SELECT EXISTS (
  SELECT 1 FROM role_permissions
  INNER JOIN user_roles   ON user_roles.role_id   = role_permissions.role_id
  INNER JOIN permissions  ON permissions.id      = role_permissions.permission_id
  WHERE user_roles.user_id = ? AND permissions.kode IN (?, ?)
)
```

`role_permissions` is the table both others are reached through, and MySQL resolves it
from `user_roles.role_id` and `permissions.id`, the latter being the primary key. One
`IN` list, one `EXISTS`, no per-role loop - so the cost does not grow with the number of
roles or permissions the caller holds. **No eager loading is used anywhere**, because the
question is a boolean and there is no collection to load into.

Proven, not asserted:
- `test_the_permission_check_costs_at_most_two_queries_whatever_the_caller_holds` -
  a caller holding **all five roles and 69 grants**, counted with `DB::listen`, must cost
  <= 2.
- `test_the_permission_check_costs_the_same_for_a_caller_with_no_roles` - a caller with
  zero roles must take the same code path, or a revoked account would behave differently
  from one that never had roles.

### 401 before 403, and never a redirect

| Situation | Status | Body (byte-exact) |
| --- | --- | --- |
| no `auth:sanctum`, caller anonymous | 401 | `{"success":false,"message":"Unauthenticated.","errors":{}}` |
| `permission:`/`tipe:` reached anonymously (route forgot the guard) | 401 | same |
| caller lacks the grant | 403 | `{"success":false,"message":"This action is unauthorized.","errors":{}}` |
| caller has the wrong `tipe` | 403 | same |
| unknown `permission:`/`tipe:` value | 500 | `{"success":false,"message":"Internal server error.","errors":{}}` |

Both strings are byte-identical to the ones `bootstrap/app.php`'s exception renderer
produces, so a client cannot tell which layer denied it - asserted with
`assertSame`/`toBe` on the raw body, not a shape check. The responses are **returned**
rather than thrown, because a thrown `AuthenticationException` is rendered by
`shouldRenderJsonWhen()`, which is false for a non-`api/*` path; returning it makes the
body independent of the request path. Every test asserts `Location` is absent.

The plan's mandated failure scenario is covered: an unauthenticated request to a
`permission:`-protected route returns the 401 envelope, not 403 and not a redirect to
`/login` (`ApiKernelTest` already proves the `login` route exists, so a 302 there would
be reachable).

### `tipe:` and `permission:` are independent gates

`tipe_admits_the_named_account_type` and `tipe_ignores_roles_and_reads_the_users_column`
together pin this: a `dokter` who also holds the `admin` **role** is still refused by
`tipe:admin,superadmin`. Both are load-bearing because the plan uses both - todo 32
writes "`tipe:dokter` + an ownership check", todo 39 writes the medicine search is
"doctor-only (`tipe:dokter`)".

### The principal in the tests is a fixture; the rows behind it are real

`tests/Support/RbacTestPrincipal.php` is a minimal `Authenticatable` + `HasApiTokens`
because `app/Models/**` is todo 19's and was being written concurrently. **The `users`
rows and the `user_roles` grants behind it are real rows written through the query
builder**, because `user_roles.user_id` carries a real foreign key to `users(id)`
(`:175`) and a grant cannot be tested against a user id that exists in no row. What is
faked is the model, not the data. `app/Models/**` was not created, read for content, or
modified by this todo.

---

## 5. The `DatabaseSeeder` chain after this change

```
$ php artisan migrate:fresh --seed      # 78 migrations, 11 seeders, exit 0
```

| # | Seeder | Source |
| --- | --- | --- |
| 1 | `MasterWilayahSeeder` | DDL `:16.1` |
| 2 | `MasterUmumSeeder` | DDL `:16.2` |
| 3 | `SpesialisasiSeeder` | DDL `:16.3` |
| 4 | `PenjaminSeeder` | DDL `:16.4` |
| 5 | `MetodePembayaranSeeder` | DDL `:16.5` |
| 6 | `IcdSeeder` | DDL `:16.6` |
| 7 | `ObatSeeder` | DDL `:16.7` |
| 8 | `LabSeeder` | DDL `:16.8` |
| 9 | `ArtikelKategoriSeeder` | DDL `:16.9` |
| **10** | **`RbacSeeder`** | **no DDL source (this todo)** |
| **11** | **`DevFixtureSeeder`** | no DDL source (todo 18) - still last, unchanged |

The nine DDL seeders and `DevFixtureSeeder`'s position are **exactly as todo 18 left
them**; this todo inserted one call between them. `RbacSeeder` sits there because it has
no dependency on either group: it writes only `roles`, `permissions` and
`role_permissions` and resolves every id by natural key, so it reads nothing.

`SEEDED_TABLES` gained `roles`, `permissions`, `role_permissions` and `user_roles`.
**`user_roles` is owned but written by nobody**: it is listed so a re-seed clears a
developer's manual grants, and `RbacSeeder` deliberately creates none, because assigning
a role to a real account is an application action taken through `RoleAssigner`. Asserted
by `test_the_seeder_writes_no_user_roles_row_is_seeded` and
`test_no_user_roles_row_is_seeded`.

### Nothing in this RBAC data exists in the DDL

Enumerated directly, over all 1,349 lines: **15 `INSERT` statements into 15 distinct
tables**, all in section `[16]`. `roles`, `permissions`, `role_permissions` and
`user_roles` are not among them. So the RBAC contents are application data and are
outside the 1:1 fidelity claim, exactly like `DevFixtureSeeder`'s rows. What the DDL
*does* fix is the shape, and this seeder honours it: **none of the three tables has
`dibuat_at` or `diubah_at`**, so passing timestamps would be MySQL 1054 on all three;
`role_permissions` has **no `id`**, so it gets exactly the pair.

`RbacSeeder` resolves every id by natural key (`roles.nama`, `permissions.kode`) rather
than assuming `AUTO_INCREMENT` starts at 1, and throws rather than inserting a dangling
id. It also throws if any catalogue code is granted to **no** role, so a permission
added to the list without being mapped is a seed failure rather than a route that can
never be reached.

---

## 6. Acceptance

### 6a. `php artisan migrate:fresh --seed` - exit 0

```
 2026_10_01_000076_add_deferred_foreign_keys_table .. 48.75ms DONE
 2026_10_01_000077_create_v_dokter_katalog_view_table .. 5.63ms DONE
 2026_10_01_000078_create_v_pendapatan_bulanan_view_table .. 4.93ms DONE

 INFO Seeding database.

 Database\Seeders\MasterWilayahSeeder .. RUNNING
 Database\Seeders\MasterWilayahSeeder .. 5 ms DONE
 ...
 Database\Seeders\ArtikelKategoriSeeder .. RUNNING
 Database\Seeders\ArtikelKategoriSeeder .. 4 ms DONE

 Database\Seeders\RbacSeeder .. RUNNING
 Database\Seeders\RbacSeeder .. 13 ms DONE

 Database\Seeders\DevFixtureSeeder .. RUNNING
 Database\Seeders\DevFixtureSeeder .. 314 ms DONE

EXITCODE=0
```

78 migrations, 11 seeders. Read back from `telemedisin_db` immediately afterwards
(`COUNT(*)`):

```
'roles' => 5,             'permissions' => 24,
'role_permissions' => 69, 'user_roles' => 0,
```

and todo 18's data, proving this todo's inserts did not disturb it:

```
'article_kategori' 6, 'master_agama' 7, 'master_golongan_darah' 4,
'master_hubungan_keluarga' 7, 'master_icd10' 15, 'master_icd9cm' 6,
'master_lab_paket' 3, 'master_lab_tindakan' 10,
'master_metode_pembayaran' 14, 'master_obat' 7, 'master_pendidikan' 8,
'master_penjamin' 6, 'master_provinsi' 38, 'master_spesialisasi' 16,
'master_status_pernikahan' 4            -> section [16] total 151
'users' 5, 'pasien' 2, 'dokter' 3, 'faskes' 2, 'dokter_spesialisasi' 4,
'lab_paket_item' 7, 'obat_interaksi' 2  -> fixture total 25
```

`tests/Unit/RbacMigrateFreshSeedTest.php` runs the real command in `setUp()` (throwing
with the output on a non-zero exit) so **every** test in the file is downstream of it, and
re-asserts all of the above plus the 69 grants and `superadmin`'s 24 codes read back
through the same join the middleware uses.

It is a **Unit** test, not a Feature one, for a mechanical reason: `tests/Pest.php` binds
`RefreshDatabase` to the Feature directory and `RefreshDatabase` runs `migrate:fresh`
**without** `--seed`, so a Feature test cannot observe a seeded database at all. It also
avoids `TRUNCATE` inside a wrapping transaction, which MySQL implicitly commits.

### 6b. `php artisan test` - no regression

Uncontended run:

```
summary: result=failed tests=207 passed=186 assertions=6103 failed=1 errors=20
VERDICT: all 21 failing tests are the known pre-existing Fortify/Inertia scaffold set.
         Zero failures come from any todo 4 file.
```

This todo's own 43 tests, run alone:

```
{"tool":"pest","result":"passed","tests":43,"passed":43,"assertions":364,"duration_ms":97578}
EXIT=0
```

**`php artisan test` is NOT green, and was not green before this todo started.** The
brief's acceptance criterion says it should be. It cannot be, and the 21 failures are
not mine - see finding 1. The honest summary is the **delta**, which is zero:

| | before this todo | after |
| --- | --- | --- |
| `php artisan test --testsuite=Unit` | 90 tests, 90 passed, 481 assertions | 126 tests, 126 passed, 5686 assertions |
| full suite, failing tests | 21 (1 failure + 20 errors) | **21 (1 failure + 20 errors) - the same 21** |
| tests attributable to todo 4 | - | 43, all passing |

The baseline was measured, not assumed: `php artisan test --testsuite=Unit` returned
`{"tool":"pest","result":"passed","tests":90,"passed":90,"assertions":481}` before any
edit, and `php artisan test --testsuite=Feature` returned `tests=45, passed=24,
failed=1, errors=20` before any edit. Todo 19's own ledger entry, written
independently and concurrently, records `php artisan test -> 207 tests, 186 passed, 1
failed and 20 errors` and names the same cause. **Two executors measured the same
numbers.**

### 6c. `php artisan sehatly:verify-schema` - exit 0, unchanged

```
 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables; ...)
 Parsed reference model ... tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 Live schema ... tables=82 views=2 columns=715 indexes=237 foreign_keys=105 checks=3

 Discrepancies: 7 (0 drift, 7 informational)
 documented_extra_table cache ... | actual: present in the live schema
 documented_extra_table cache_locks ... | actual: present in the live schema
 documented_extra_table failed_jobs ... | actual: present in the live schema
 documented_extra_table job_batches ... | actual: present in the live schema
 documented_extra_table jobs ... | actual: present in the live schema
 documented_extra_table migrations ... | actual: present in the live schema
 documented_extra_table personal_access_tokens ... | actual: present in the live schema

 PASS - 75 tables, 2 views verified. Nothing was written.

EXITCODE=0
```

**Identical to the pre-task baseline, including the count of 7.** The 7 are the
registered extra tables, informational by design; per plan appendix A.25 that number is
not to be driven to 0. This todo added no migration and no table, so parity could not
have moved - and it did not.

### 6d. Read-only law held

```
Get-FileHash telemedicine_test.sql -Algorithm SHA256
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
```

Byte-identical to the brief. `database/migrations/**` untouched, `routes/api.php`
untouched, no controller written, no `mobile/` directory, nothing under `app/Models/`
created or modified, `sehatly` database never opened for writing.

---

## 7. A.26 hygiene - both halves, on every authored file

### (a) Non-ASCII scan, regex `[^\x00-\x7F\u2013\u2014\u2022\u2026\u2192\u2212\u00A7\u2225]`

```
app/Support/Rbac/RbacCatalog.php               violations=0
app/Support/Rbac/Caller.php                    violations=0
app/Support/Rbac/RoleAssigner.php              violations=0
app/Http/Middleware/EnsurePermission.php       violations=0
app/Http/Middleware/EnsureUserType.php         violations=0
bootstrap/app.php                              violations=0
database/seeders/RbacSeeder.php                violations=0
database/seeders/DatabaseSeeder.php            violations=0
tests/Feature/RbacMiddlewareTest.php           violations=0
tests/Feature/RbacCatalogTest.php              violations=0
tests/Unit/RbacMigrateFreshSeedTest.php        violations=0
tests/Support/RbacTestPrincipal.php            violations=0
TOTAL non-ASCII violations across authored files: 0
```

### (b) Token audit: every `snake_case` identifier checked against the DDL

**100 distinct tokens, 61 resolved, 39 unresolved - and every one of the 39 is accounted
for:**

- **22 are PHP standard-library functions** matched by the `[a-z_]+` pattern:
  `array_diff`, `array_filter`, `array_key_exists`, `array_keys`, `array_map`,
  `array_sum`, `array_unique`, `array_values`, `base_path`, `ctype_digit`,
  `file_get_contents`, `get_class`, `get_debug_type`, `in_array`, `is_int`, `is_string`,
  `preg_match`, `preg_split`, `property_exists`, `random_bytes`, `str_contains`,
  `password_hash`. Not identifiers at all.
- **1 is a PHP language construct**: `strict_types`, from `declare(strict_types=1)`.
- **4 are Laravel/scaffold identifiers that deliberately do NOT exist in the DDL** and
  are named precisely because they must not: `created_at` and `remember_token` (todo 8
  dropped both from `users`; `RbacTestPrincipal` returns `null` for the remember token
  with that reason), `personal_access_tokens` (a registered extra table), `sidebar_state`
  (an Inertia cookie name, pre-existing in `bootstrap/app.php`).
- **2 are database names, verified in the DDL**: `telemedisin_db` at `:11` and `:15`,
  and `telemedicine_test` is the reference file's own name.
- **1 is a framework-generated constraint name quoted in a pre-existing error message**:
  `master_kabupaten_kota_provinsi_id_foreign`, in `DatabaseSeeder`'s verbatim MySQL 1701
  text, checked against the live schema.
- **1 is the word `snake_case`** in a docblock describing this very audit.
- **8 are PHPUnit test method names** in `RbacMigrateFreshSeedTest`:
  `test_migrate_fresh_seed_exits_zero`, `test_no_user_roles_row_is_seeded`,
  `test_the_rbac_rows_exist_after_migrate_fresh_seed`,
  `test_the_role_permission_grants_exist_after_migrate_fresh_seed`,
  `test_the_seeded_superadmin_holds_every_permission_live`,
  `test_the_todo_18_development_fixtures_still_land`,
  `test_the_todo_18_section_16_rows_survive_the_rbac_inserts`.

Every DDL identifier the audit resolved is a real one, checked against the parsed
contract: `roles`, `permissions`, `role_permissions`, `user_roles`, `nama`, `deskripsi`,
`kode`, `tipe`, `status`, `bahasa`, `uuid`, `nama_lengkap`, `no_telepon`,
`kata_sandi_hash`, `role_id`, `permission_id`, `user_id`, `pasien`, `dokter`, `faskes`,
`pasien_penjamin`, `lab_paket_item`, `obat_interaksi`, `dokter_spesialisasi`,
`article_kategori` and the 15 section-`[16]` tables, the 20+ `*_seeder` class names from
todo 18, and every `tipe` ENUM member.

### The audit caught one real defect - and it was NOT mine

`DatabaseSeeder`'s call table named the column
`pasien_anggota_keluarga.hubungan_keluarga_id`. **The DDL calls it `hubungan_id`**
(`:262`, `TINYINT UNSIGNED NOT NULL`, `FOREIGN KEY (hubungan_id) REFERENCES
master_hubungan_keluarga(id)` at `:271`) - the `_keluarga` belongs to the *table* it
references, not to the column name. This is a pre-existing todo-18 comment defect and
exactly the class A.26 describes: a corrupted ASCII identifier that no encoding scan can
see, and one the parity verifier cannot catch because it only compares columns it can
see. Corrected in `DatabaseSeeder.php` (a file this todo owns an edit in) and reported
rather than edited in `database/seeders/MasterUmumSeeder.php:62`, which carries the same
wrong name and is not this todo's file - per A.15's rule that a stale claim is only fixed
in the places you happen to look.

The correction deliberately does **not** reproduce the wrong spelling verbatim, only in
truncated form, so the next token audit over this file does not report it again and the
next reader does not grep for it. That is todo 19's reasoning, adopted.

### And it caught one of my own, before it shipped

The first draft of `RbacCatalog::ROLE_PERMISSIONS` contained
`'kons jeepasio.selesai'` where `konsultasi.selesai` belongs - a corrupted ASCII
identifier of precisely the `doker_umum` class A.26 was written about, found by the same
audit and fixed in place. It is disclosed because a hygiene report that only ever
reports zero findings is indistinguishable from a hygiene report that was not run.

---

## 8. Findings

### Finding 1 - `php artisan test` cannot be green at this commit, and the 21 failures are pre-existing

**The brief's acceptance criterion is unsatisfiable here.** Measured before any edit:
`--testsuite=Feature` gave `tests=45, passed=24, failed=1, errors=20`. The 21 are the
Laravel/Fortify/Inertia **scaffold** tests:

| File | Count | Cause |
| --- | --- | --- |
| `tests/Feature/Auth/AuthenticationTest.php` | 3 errors | `UserFactory` inserts `name`, `email_verified_at`, `password`, `remember_token`, `two_factor_*` |
| `tests/Feature/Auth/EmailVerificationTest.php` | 3 errors | same |
| `tests/Feature/Auth/PasswordConfirmationTest.php` | 3 errors | same |
| `tests/Feature/Auth/PasswordResetTest.php` | 3 errors | same |
| `tests/Feature/Auth/RegistrationTest.php` | 1 failure | same |
| `tests/Feature/DashboardTest.php` | 1 error | same + the Inertia surface |
| `tests/Feature/Settings/PasswordUpdateTest.php` | 2 errors | same |
| `tests/Feature/Settings/ProfileUpdateTest.php` | 5 errors | same |

Every one is `SQLSTATE[42S22] Unknown column 'name' in 'field list'` on
`telemedicine_test.sql`'s `users`, which todo 8 migrated to have no `name`, no
`password`, no `email_verified_at` and no `remember_token`. The single root cause is
`database/factories/UserFactory.php`, which still targets the scaffold schema.

**I did not make it green, and I am not going to.** The fix is either rewriting
`UserFactory` against the DDL columns (todo 19's model territory, and a real
mass-assignment decision) or deleting the scaffold surface todo 30 removes. Both are
other todos' files. Reporting a green suite here would have meant either breaking
something or claiming a result I did not produce - the second time in this project an
executor has been handed a criterion that cannot be met as stated (the first was
`v_pendapatan_bulanan` returning 0 rows, where inventing a successful payment would
have "passed" criterion 4).

What *is* true, and is the meaningful claim: **this todo adds 43 passing tests and zero
failures, and the failing set is byte-for-byte the set that was already failing.**

### Finding 2 - `perawat` and `kurir` are DDL account types with no role

`users.tipe` has seven values; the plan names five roles. `perawat` and `kurir` are
therefore accounts that **can authenticate, hold no role, and are authorised by `tipe:`
alone and by no `permission:` at all.** I took the narrow reading - inventing two roles
would be inventing policy - and recorded the consequence rather than papering over it.
Asserted by `test_tipe_accepts_an_ENUM_value_and_such_an_account_holds_no_permission`.
**Todos 20/21/22 must decide whether these two account types need permissions.** The
answer is a data change in `RbacCatalog::ROLE_PERMISSIONS` plus a re-seed, not a code
change.

### Finding 3 - `obat.cari` is granted to `dokter` alone, and that is probably too narrow

Plan todo 39 states the medicine search "is doctor-only (`tipe:dokter`)". A pharmacist
verifying a prescription plausibly needs drug lookup, but the
plan does not name a pharmacist as a consumer of that action, so the narrow reading was
taken. **Flagged for the pharmacy todos (todo 22's directory, the `resep` module's
verification flow) rather than filled by guesswork.** A second, independent check would
be needed anyway: the route carries `tipe:dokter`, so granting a pharmacist `obat.cari`
would change nothing until that route's gate changed too.

### Finding 4 - the plan's `:137` citation for `users.tipe` is wrong

Plan todo 4 cites ``telemedicine_test.sql:137` (`users.tipe` ENUM of 7)`. **`:137` is
`no_telepon VARCHAR(20) NOT NULL UNIQUE`**; the ENUM is at **`:139`**. The count of seven
is right; the line number is not. Per plan appendix A.16 the plan's inline `:NNN`
citations are known-unreliable, so this is recorded as a confirmation of A.16 rather
than a new defect - and the citation is now **guarded by a test**
(`test_the_DDL_citation_constant_still_points_at_the_users_tipe_enum`), so a
renumbering of the reference file fails the suite instead of leaving a wrong line number
standing in a docblock.

`RbacCatalog::DDL_USER_TYPES_LINE` carries the correct `:139`, and every `:NNN` in the
files this todo authored was read out of the file rather than taken from the plan.

### Finding 5 - the plan's line-index table for `bootstrap/app.php` is stale

Plan todo 4 says "Register both in `bootstrap/app.php:17-25`". That range is inside
`withRouting()`. The alias registration belongs in the `withMiddleware()` closure, which
at HEAD was `:52-59`. Recorded because the brief instructed reading files before
asserting their state (A.25) and because the plan's own line-index table being off by
three dozen lines is the same class of claim as `:137`.

### Finding 6 - two stale figures in `DatabaseSeeder`'s own row-count table, found while editing it

The table read "7 fixture tables / **21** rows / 22 tables / **172** total" and attributed
**3** rows to `lab_paket_item`. The measured value is **7**: `DevFixtureSeeder`'s package
map links three `LAB-*` codes to `Medical Check Up Dasar`, two to `Cek Gula & Kolesterol`
and two to `Fungsi Hati Lengkap`, and `telemedicine_test.sql` contains **zero** `INSERT`
statements for `lab_paket_item` (it appears only at `:31` in the reset block and `:868` in
the `CREATE TABLE`). The corrected total is 151 + 25 + 98 = **274** across **25** tables.

**Nothing in the code was wrong - only the prose**, which is plan appendix A.15's defect
class exactly: a comment nobody executes, so nothing fails when it drifts. The same stale
"3" survives in `database/seeders/LabSeeder.php` and in `database/seeders/DevFixtureSeeder.php`;
both are left alone because they are not this todo's files, and both are reported here.

### Finding 7 - `tests/Pest.php`'s `RefreshDatabase` does not reach PHPUnit-class tests

This is a repo property, discovered the hard way: this todo's Feature tests were written
as `class RbacMiddlewareTest extends TestCase` first, and **every test after the first
failed with `1062 Duplicate entry 'pasien' for key 'roles.roles_nama_unique'`** because
`pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')` binds the
trait to Pest's **closure** tests and not to a plain PHPUnit class in the same directory.
`tests/Feature/ApiKernelTest.php` is such a class, and its docblock's "No database is
touched" is literally true for that reason.

Both of this todo's Feature files were rewritten as Pest closures, which is the repo's
own documented convention for DB-touching Feature tests
(`tests/Feature/UsersTableSchemaTest.php` states it and proves it with
`expect(...)->toBe(2)` on `users`). **`tests/Feature/ApiKernelTest.php` is affected by
this too** - it simply happens to touch no database, so nothing shows. Worth knowing
before the next Feature test is written as a class.

### Finding 8 - the acceptance criterion for this todo is inherently a database-mutating test

"After `migrate:fresh --seed` the RBAC rows exist" cannot be a `RefreshDatabase` test,
because `RefreshDatabase` runs `migrate:fresh` **without** `--seed`. So
`RbacMigrateFreshSeedTest` runs the real command in the **Unit** suite, and the
consequence is that **any `php artisan test tests/Unit` now rebuilds
`telemedisin_db_test`**. This is a genuine new hazard for concurrent executors, and it
is not hypothetical: it fired three times in this session and once in todo 19's. The
signature is unmistakable - `1050 Table 'cache' already exists` and `1146 Table
'migrations' doesn't exist` from two `migrate:fresh` rebuilds interleaving on one
database. Every occurrence went green on a re-run with nothing changed here, and the
other executor observed the same thing from the other side:

> "tests/Unit went red twice during the session, 6 then 7 errors, all inside the RBAC
> executor tests/Unit/RbacMigrateFreshSeedTest.php ... Both runs went green on re-run
> with nothing changed here."

Recorded rather than hidden, because a reader who sees a red `tests/Unit` and does not
know this will chase a bug that is not there.

### Finding 9 - the brief's own example route would have returned 500

The brief's outcome line gives `->middleware(['auth:sanctum','permission:booking.create'])` as
the usage example. **`booking.create` is not a permission code in this vocabulary** - the
DDL's comment at `:159` and the plan both use Indonesian action verbs, so the code is
`booking.buat`. Under the chosen fail-loud policy that example returns **500**, not 403
and not 200, and
`test_an_unknown_permission_code_fails_loudly_instead_of_denying_everyone` pins that.
Flagged so nobody discovers it by surprise in todo 20. (The plan's own acceptance
criteria consistently use the Indonesian codes - `permission:konsultasi.selesai`,
`permission:resep.verifikasi` - so the brief's `booking.create` reads as an illustrative
slip rather than a real requirement.)

---

## 9. Follow-ups for later todos

1. **Todos 20/21/22/47** may revise `RbacCatalog::ROLE_PERMISSIONS` as a **data** change.
   Nothing in `RbacSeeder`, the middleware or the schema needs to move.
2. **Todos 20/21/22** must decide whether `perawat` and `kurir` get roles (finding 2).
3. **The pharmacy todos** must decide whether `apoteker` gets `obat.cari` (finding 3).
4. **Todo 19** should wire `RoleAssigner` into the `User` model, since the plan asked for
   an `AssignRole` helper on `User` and `app/Models/**` is todo 19's. The intended call
   site is in `RoleAssigner`'s class docblock.
5. **Todo 20** owns `routes/api.php` and is the first consumer: it will hit the
   fail-loud 500 on any `permission:` code it invents rather than outside the catalogue
   (finding 9).
6. **`tests/Feature/ApiKernelTest.php`** is a PHPUnit class in a `RefreshDatabase`
   directory (finding 7). Harmless today, a trap for whoever next adds a database
   assertion to it.

## 10. Data safety

| Check | Result |
| --- | --- |
| `telemedicine_test.sql` SHA-256 | `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5` - unchanged |
| `database/migrations/**` | untouched; `git status` shows no migration in the diff |
| `routes/api.php`, any controller | untouched |
| `app/Models/**` | untouched by this todo; todo 19's concurrent work left alone |
| `sehatly` database | never opened for writing (10 tables, 5 migration-ledger rows, unchanged) |
| `migrate:rollback` | never run |
| destructive SQL | exactly one `migrate:fresh --seed`, on `telemedisin_db`, which the acceptance requires; plus `migrate:fresh --seed` executed **by the test suite against `telemedisin_db_test` only** |
| files deleted that this todo did not create | none |
| commits | one, with an explicit pathspec naming only the 12 files above plus this evidence file and the ledger line |
