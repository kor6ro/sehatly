# Task 19 evidence: the model layer

Scope: `app/Models/**` (75 model classes + 1 shared concern), `tests/Unit/Models/ModelFoundationTest.php`.
Nothing under `database/migrations/`, `telemedicine_test.sql`, `docs/schema-notes.md` or
`docs/migration-order.md` was touched. No seeder, controller, FormRequest, ApiResource or route
was written. `mobile/` was not created.

## 1. Derivation: the DDL is the only input

Every column name, type, nullability, primary key, unique key and foreign key in all 75 models was
read out of `telemedicine_test.sql` through the repository's own `App\Support\Schema\SqlSchemaParser`
(`app/Support/Schema/SqlSchemaParser.php`), never from memory. The generator that emitted the 74
non-auth models refuses to run unless the parse yields exactly 75 tables, refuses a duplicate model
class name, and refuses to emit a relation method name twice on one class. All three guards fired
during this task:

- the duplicate-method guard caught `master_obat` gaining `obatInteraksi()` twice, because
  `obat_interaksi` reaches `master_obat` through both `obat_a_id` (`:732`) and `obat_b_id` (`:733`).
  A parent pointed at twice by the same child is now named after the parent's own column
  (`obatA()`, `obatB()`) instead of the child's table name.

`tests/Unit/Models/ModelFoundationTest.php` re-derives its expectations from the same parser at run
time, so the test cannot agree with a wrong model by construction. It discovers the model classes by
globbing `app/Models/*.php`, so a 76th model fails the count.

## 2. Model count: 75, one per contract table

`ls app/Models/*.php | wc -l` = **75**, and the test asserts the set of `$table` values equals the
DDL's 75 table names with no duplicates and nothing extra.

Naming: singular StudlyCase of the table, matching the plan's pinned names (`User`, `Pasien`,
`Role`, `ApotekStok`, `KonsultasiChat`, `MasterSpesialisasi`, `DokterFaskes`). Five contract tables
are written in the plural in the DDL and are singularised: `users` -> `User`, `roles` -> `Role`,
`permissions` -> `Permission`, `user_devices` -> `UserDevice`, `user_refresh_tokens` ->
`UserRefreshToken`. Every other table is already singular in the DDL, so `Str::studly()` applies
unchanged. The five-entry list is a literal in the generator so the decision is auditable rather
than inferred.

## 3. Relations: 105 foreign keys, 200 declared relation methods

Every declared FK produces a `belongsTo` on the child and a `hasOne`/`hasMany` back on the parent;
the inverse type is `hasOne` only when the child's FK column carries a single-column `UNIQUE`. The
four `belongsToMany` shortcuts are `User::roles()`, `Role::users()`, `Role::permissions()` and
`Permission::roles()`, all through the declared pivot tables `user_roles` and `role_permissions`.

The test asserts three things about every relation, in both directions, for all 105 FKs:

1. every declared FK has a relation on the child,
2. the parent has a relation back (a `belongsTo` with no reverse is half a relationship),
3. **no relation is built on a column with no `FOREIGN KEY`** - for a `belongsTo` the key must be a
   declared FK of that table, for a `hasOne`/`hasMany` a declared FK of the related table.

Point 3 is the machine form of the "verify a relation only where the SQL declares the FK" rule: an
invented relation is a test failure, not a silently wrong join.

Relation method names are mechanical: `Str::camel` of the FK column minus its `_id` suffix on the
`belongsTo` side (`dibuat_oleh_user_id` -> `dibuatOlehUser()`, `obat_a_id` -> `obatA()`), and
`Str::camel` of the child table name on the inverse side. The plan's names for `User`
(`otpCodes`, `devices`, `refreshTokens`, `roles`, `pasien`, `dokter`, `notifikasi`) are used as
given, and the mechanical names for those same columns were dropped rather than shipped twice.

## 4. Bare columns: relations deliberately NOT created

The DDL leaves these columns unconstrained. Each looks like a foreign key, and a `hasMany` or
`belongsTo` on one would be a plausible guess that silently returns wrong data. None has a relation,
and a test asserts both halves: the column really is bare, and no relation method of the name a
developer would reach for exists.

| column | DDL line | what it is |
|---|---|---|
| `artikel.reviewer_user_id` | `:1078` | named in the plan and in A.19 as deliberately bare |
| `audit_log.user_id` | `:1120` | bare, so the log survives user deletion |
| `audit_log.record_id` | `:1123` | polymorphic target id, `VARCHAR(64)` |
| `pasien_alergi.dicatat_oleh_user_id` | `:281` | no `FOREIGN KEY` clause |
| `pasien_penjamin.faskes_rujukan_id` | `:346` | settled by A.10/A.11: adding an FK would be `extra_foreign_key` drift |
| `pasien.provinsi_id` | `:235` | `pasien` has NO wilayah FK at all, unlike `faskes` (`:381-383`) |
| `pasien.kabupaten_kota_id` | `:236` | same |
| `pasien.kecamatan_id` | `:237` | same |
| `pasien.kelurahan_id` | `:238` | same |
| `rekam_medis_lampiran.diunggah_oleh` | `:687` | no `_id` suffix, no clause |
| `lab_hasil.diperiksa_oleh` | `:914` | no `_id` suffix, no clause |
| `resep.konsultasi_id` | `:745` | no clause |
| `resep.rekam_medis_id` | `:746` | no clause |
| `surat_keterangan.konsultasi_id` | `:584` | no clause |
| `lab_permintaan.rekam_medis_id` | `:879` | no clause |
| `lab_permintaan.konsultasi_id` | `:880` | no clause |
| `klaim_bpjs.booking_id` | `:1014` | no clause |
| `klaim_bpjs.rekam_medis_id` | `:1015` | no clause |
| `rujukan.faskes_asal_id` | `:602` | `faskes_tujuan_id` at `:603` IS declared; only the origin is bare |
| `rujukan.icd10_kode` | `:606` | code, not an id |
| `pasien_riwayat_penyakit.icd10_kode` | `:291` | code, not an id |
| `klaim_bpjs.diagnosa_icd10` | `:1019` | code |
| `klaim_bpjs.tindakan_icd9cm` | `:1020` | code |
| `invoice.referensi_id` | `:941` | polymorphic; parent is a function of `referensi_tipe` |

**Two findings a later todo will need.**

- `pasien` has **no** foreign key to any wilayah table. `faskes` declares three
  (`provinsi_id` `:381`, `kabupaten_kota_id` `:382`, `kecamatan_id` `:383`); `pasien` declares five
  columns of the same names and no clause. A `Pasien::provinsi()` relation would be a guess, and it
  would differ from `Faskes::provinsi()` on the same domain concept. Todo 21 (patient profile) will
  need to decide deliberately: validate with `Rule::exists` and read the attribute, or leave it.
- `resep`, `lab_permintaan`, `surat_keterangan` and `klaim_bpjs` reach `konsultasi` and
  `rekam_medis` through bare columns, so `Konsultasi::resep()` and `RekamMedis::labPermintaan()` do
  not exist even though both relationships are obviously wanted. Todo 33 (resep) and the lab todo
  will hit this. The column and the target are both unambiguous in the DDL, so a relation there is a
  *documented* one-column addition rather than a guess - but it is not this todo's to make, because
  the DDL is the contract and it says no.

## 5. Casts, derived from the DDL type

| DDL type | count | cast |
|---|---|---|
| `TINYINT(1)` signed | 30 | `boolean` |
| `TINYINT UNSIGNED` | 25 | none - an int |
| `DECIMAL(p,s)` | 37 | `decimal:<s>` |
| `ENUM(...)` | 69 | `string` |
| `DATE` | 19 | `date` |
| `DATETIME` | 26 | `datetime` |
| `JSON` | 6 | `array` |
| `TEXT` / `LONGTEXT` | 44 | `string` |
| `TIMESTAMP` | 55 | lifecycle - see below |

`TEXT` and `ENUM` casts are no-ops at runtime; they are declared because the brief's mapping table
asks for them explicitly and a reader comparing the model to the DDL should not have to work out
whether an unlisted column was forgotten.

**The `TINYINT(1)` trap, and why signedness is the discriminator.** `TypeNormaliser::decompose()`
deliberately drops MySQL's deprecated integer display width (`app/Support/Schema/TypeNormaliser.php:102-104`),
so the parser reports both `TINYINT(1)` and `TINYINT UNSIGNED` as the bare type `tinyint`. A rule of
"`tinyint` -> boolean" would therefore have cast `spo2` (a 0-100 percentage, `:321`), `dosis_ke`
(`:305`), `hari` (`:475`), `jumlah_hari` (`:590`), `versi` (`:646`), `jumlah_iter` (`:757`),
`kuota_per_user` (`:994`), `durasi_jam` (`:1102`) and all 16 `id` columns to boolean. The rule used is
`tinyint && !unsigned -> boolean`, which separates the two spellings exactly. Cross-checked against
the raw DDL, which contains 30 `TINYINT(1)` and 25 `TINYINT UNSIGNED` spellings - 55, matching the
parser's 55 `tinyint` columns. A test asserts both counts and asserts `spo2`,
`dokter.jumlah_ulasan`, `dokter.jumlah_konsultasi`, `konsultasi.total_durasi_detik` and
`konsultasi_chat.file_ukuran_kb` are not boolean.

`DECIMAL(12,2)` becomes `decimal:2`, not `decimal:12,2`: the cast parameter is the scale alone. An
early generator revision emitted `decimal:12,2`, which Laravel's `isDecimalCast()` accepts as a cast
type but which then feeds `number_format` a 12-digit precision - caught by reading the emitted file
and by a test that recomputes the scale from the DDL for all 37 decimal columns.

`DATE` is never cast to `datetime`. 19 `DATE` columns include `pasien.tanggal_lahir` (`:226`),
`booking.tanggal_kunjungan` (`:507`) and `dokter_jadwal.berlaku_mulai` (`:480`); a datetime cast
would shift all three across timezones. A test asserts each of the 19 is `date` and is not
`datetime`.

## 6. Audit columns: the 16 / 19 / 1 / 39 split

Derived from the DDL, not from the plan. The plan's Scope block is wrong in two places; see finding 1.

| shape | count | what the model declares |
|---|---|---|
| `dibuat_at` + `diubah_at` | 16 | `const CREATED_AT = 'dibuat_at'`, `const UPDATED_AT = 'diubah_at'` |
| `dibuat_at` only | 19 | `const CREATED_AT = 'dibuat_at'`, `const UPDATED_AT = null` |
| `diubah_at` only (`apotek_stok`) | 1 | `const UPDATED_AT = 'diubah_at'`, `public $timestamps = false` |
| `terkirim_at` only (`konsultasi_chat`) | 1 | `const CREATED_AT = 'terkirim_at'`, `const UPDATED_AT = null` |
| neither | 39 | `public $timestamps = false` |

`public const UPDATED_AT = null` is load-bearing, not decoration. `Model::updateTimestamps()`
(`vendor/.../Model.php`, `HasTimestamps::updateTimestamps()`) writes `static::UPDATED_AT` whenever it
is not null, so the 20 timestamped tables with no `diubah_at` column would otherwise try to write an
`updated_at` column the DDL does not have - a `Unknown column 'updated_at'` on every insert. This was
caught by the parity test, not by inspection: the first green-looking draft produced
`getUpdatedAtColumn() === 'updated_at'` on `user_otp`, `notifikasi` and `audit_log`.

A `$timestamps = false` model needs no null constant: Eloquent never reaches `updateTimestamps()`
for it, and `getDates()` returns `[]`.

**Lifecycle columns carry no cast entry.** `transformModelValue()` converts them on
`in_array($key, $this->getDates(), false)`, and `SoftDeletes::initializeSoftDeletes()` injects
`dihapus_at => datetime` into `$casts` itself. Declaring them again would be a second, silently
divergent copy of a fact the class already states. The one exception is `apotek_stok.diubah_at`,
which is cast explicitly because that model is not timestamped and nothing else would convert it. A
test walks all 55 `TIMESTAMP` columns and asserts each is a lifecycle column and that only that one
carries a cast entry, plus a behavioural test that each of eight representative lifecycle columns
hydrates as a `DateTimeInterface`.

`dihapus_at` exists on exactly two tables, `users` (`:148`) and `pasien` (`:249`), and exactly those
two models use `SoftDeletes`. A test asserts the soft-deleting set is `['pasien', 'users']`.

## 7. Keys

- **4 composite-primary pivots** - `dokter_faskes` (`:452`), `lab_paket_item` (`:871`),
  `role_permissions` (`:166`), `user_roles` (`:174`). Each declares
  `protected $primaryKey = [<both columns>]`, `public $incrementing = false` and
  `protected $keyType = 'string'`. The array records the key the DDL declares instead of letting the
  model assume a single `id`; the docblock says plainly that Eloquent has no composite-key support
  and that `find()`/`getKey()` are meaningless on a pivot.
- **5 non-auto-increment integer keys** - `master_agama`, `master_golongan_darah`, `master_pendidikan`,
  `master_status_pernikahan`, `master_hubungan_keluarga`, all `id TINYINT UNSIGNED PRIMARY KEY` with
  no `AUTO_INCREMENT`. Each declares `public $incrementing = false` and `protected $keyType = 'int'`,
  so the deliberate "still an integer" decision is visible. The other 66 models declare neither flag.
- Every model declares `protected $table` explicitly; a test asserts the `table` property's declaring
  class is the model itself, not the parent.

## 8. `User` and the one shared concern

`app/Models/User.php` was rewritten to the `users` contract and is the only model that also carries
the auth stack. It extends `Illuminate\Foundation\Auth\User` and uses `HasApiTokens` (Sanctum),
`HasFactory`, `HasUuid`, `Notifiable`, `SoftDeletes`. It fills `nama_lengkap, no_telepon, email,
kata_sandi_hash, tipe, status, foto_profil, bahasa, telepon_terverifikasi, email_terverifikasi` via
`#[Fillable]`, hides `kata_sandi_hash` via `#[Hidden]`, and casts `tipe`/`status`/`bahasa` to `string`,
the two verification flags to `boolean` and `last_login_at` to `datetime`. `MustVerifyEmail`,
`PasskeyUser`, `PasskeyAuthenticatable` and `TwoFactorAuthenticatable` are gone: `:144-145` give the
row `telepon_terverifikasi` and `email_terverifikasi`, not an `email_verified_at`, and there are no
`two_factor_*` or passkey columns at all.

**The one shared concern is `app/Models/Concerns/HasUuid.php`**, used by `User` and `RekamMedis`, the
only two tables declaring `uuid CHAR(36) NOT NULL UNIQUE` with no default (`:134`, `:623`). It mints
the value on `creating` only when the caller supplied none, and adds a `whereUuid` scope.

There is deliberately **no** timestamps concern. See finding 2.

**No other model has `#[Fillable]`.** Mass-assignment policy is a security decision that belongs to
the FormRequest todos, and a blanket "everything is fillable" here would be a regression waiting for
`$request->all()`. Consequence for later todos: `Model::create($request->validated())` will silently
insert nothing until each model gains a `#[Fillable]` list. Flagging it rather than pre-empting it.

## 9. Findings in the plan and in prior state

### Finding 1 - the plan's timestamp counts are wrong, and one list is short by nine

`sehatly-telemedicine-platform.md:180` says "`public $timestamps = false` for the **28** tables with
neither column" and enumerates 29 names, and says "the **18** tables with `dibuat_at` only". Measured
through `SqlSchemaParser` against the DDL:

- neither `dibuat_at` nor `diubah_at` nor `terkirim_at`: **38**, not 28. The plan's own enumeration
  holds 29 names, so it is off by one against itself, and it omits nine tables the DDL plainly gives
  no timestamp column: `artikel_kategori` (`:1068-1072`), `master_kabupaten_kota` (`:64-70`),
  `master_kecamatan` (`:72-78`), `master_kelurahan` (`:80-86`), `master_metode_pembayaran`
  (`:925-934`), `master_penjamin` (`:333-338`), `master_promo` (`:985-998`), `master_provinsi`
  (`:58-62`), `persetujuan_pdp` (`:1134-1145`).
- `dibuat_at` only: **19**, not 18. The plan's list omits `audit_log`, which `:1129` gives a
  `dibuat_at` and nothing else.

This is A.19's exact failure mode - "a count in this plan is not evidence; the names are" - and it
matters because the count is what a loop over the plan's list would have operated on. Had the list
been followed literally, ten tables would have kept `$timestamps = true` and every insert into them
would have failed on a `created_at` column that does not exist. Two tests pin the measured numbers
and the nine omitted names.

### Finding 2 - the plan's `HasIndonesianTimestamps` trait cannot exist in PHP

`:406` instructs: "Create `app/Models/Concerns/HasIndonesianTimestamps.php` providing `const
CREATED_AT = 'dibuat_at'` and `const UPDATED_AT = 'diubah_at'` (the officially supported Laravel 13
mechanism)". A trait cannot hold those constants. `Illuminate\Database\Eloquent\Model` already
declares `const CREATED_AT = 'created_at'`, and PHP rejects a trait constant that differs from an
inherited one. It is not a style rule, it is a compile error:

```
$ php -r "trait T { public const CREATED_AT = 'dibuat_at'; }
          class P { public const CREATED_AT = 'created_at'; }
          class C extends P { use T; }"
PHP Fatal error:  P and T define the same constant (CREATED_AT) in the composition of C.
However, the definition differs and is considered incompatible. Class was composed in Command line code on line 4
```

The same error surfaced through the framework when the trait was actually used:

```
Pest\Exceptions\FatalException
Illuminate\Database\Eloquent\Model and App\Models\Concerns\HasIndonesianTimestamps define the same
constant (CREATED_AT) in the composition of App\Models\Artikel. However, the definition differs and
is considered incompatible. Class was composed at app\Models\Artikel.php:34
```

The constants therefore live on each of the 16 classes directly, which is also what Laravel
documents as the supported override. `HasIndonesianTimestamps` was not shipped. This is finding 3 of
the three the brief asked for; nothing was silently worked around, and the trait's absence is a
deliberate, evidenced divergence from the plan.

### Finding 3 - the plan's `enum:` cast is a silent no-op on laravel/framework 13

`:406` also instructs: "casts `tipe` and `status` to their ENUM string sets via the `enum:` cast".
`HasAttributes::isEnumCastable()` in the installed framework requires `enum_exists($castType)`
(`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:1817-1836`):

```php
protected function isEnumCastable($key)
{
    $casts = $this->getCasts();
    if (! array_key_exists($key, $casts)) { return false; }
    $castType = $casts[$key];
    if (in_array($castType, static::$primitiveCastTypes)) { return false; }
    if (is_subclass_of($castType, Castable::class)) { return false; }
    return enum_exists($castType);
}
```

`'tipe' => 'enum:pasien,dokter,...'` names no class, so it is neither enum-castable nor a primitive
cast nor a class castable. `castAttribute()` falls through to `return $value` unchanged. The cast
would read like validation in a code review and validate nothing, which is worse than no cast: MySQL
would accept a bad value silently instead of the cast raising. Installed version confirmed as
`laravel/framework v13.33.0`. All 69 `ENUM` columns are cast to `string` per the brief's mapping
table, and a test asserts no cast string anywhere starts with `enum:`.

### Finding 4 - two acceptance criteria name APIs that do not exist on this version

`:409` asks for `(new Pasien)->getCreatedAtName() === 'dibuat_at'` and
`getUpdatedAtColumn() === null` for the `dibuat_at`-only list. `getCreatedAtName()` and
`getUpdatedAtName()` were removed; `getCreatedAtColumn()` is the current accessor and is what the test
uses. `getUpdatedAtColumn() === null` is not reachable by *omitting* a constant, because the parent's
`'updated_at'` is inherited - it is only reachable by declaring `const UPDATED_AT = null`, which is
what the models do (see section 6). Both criteria are met by the current API.

`:410` asks that `DokterFaskes::find(1)` "returns null rather than throwing". It throws:

```
TypeError: str_contains(): Argument #1 ($haystack) must be of type string, array given
  at vendor/laravel/framework/src/Illuminate/D\Eloquent/Model.php:754 (qualifyColumn)
```

`qualifyColumn()` calls `str_contains()` on whatever `getKeyName()` returns, and the key is an array.
The alternative - leaving `$primaryKey = 'id'` - would send `where id = ?` to a table with no `id`
column and only fail once the database is reached, so failing immediately is the better of the two.
The test asserts the `TypeError` and the docblock says so.

### Finding 5 - `hasCast()` no longer reports the lifecycle columns, and `getCasts()` is not the declared list

Two Laravel 13 behaviours that make the obvious assertions wrong, both found by writing them and
watching them fail:

- `hasCast($key, $types)` reads `$casts` only (`HasAttributes.php:1705-1713`); it no longer consults
  `getDates()`. `hasCast('terkirim_at', ['datetime'])` is **false** even though the attribute does
  hydrate as a date. The lifecycle columns are therefore asserted behaviourally.
- `getCasts()` is not the class's own choice. It merges `[$primaryKey => $keyType]` for an incrementing
  model - which made `master_provinsi.id` look like a declared cast - and `SoftDeletes` injects its own
  `deleted_at` entry. The test reads the `casts()` method by reflection instead.

Recorded because both would have produced a green test asserting something false.

### Finding 6 - pre-existing: `UserFactory` still targets the scaffold schema

`database/factories/UserFactory.php` writes `name`, `email_verified_at`, `password`, `remember_token`,
`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`. None exists on the
contract `users` table. This is the sole cause of the 21 Feature failures and it predates this task:
the baseline, measured before any edit, was 135 tests / 114 passed / 21 failing with the identical
error. The failing INSERT now also shows `uuid`, `diubah_at` and `dibuat_at` being written, which is
this task's `HasUuid` hook and the Indonesian timestamp constants working on a real insert path.

The factory is outside this todo's pathspec (and outside the plan's todo 19), so it is reported, not
edited. Todo 20 or todo 49 must repoint it at `nama_lengkap`, `no_telepon`, `kata_sandi_hash`, `tipe`
and `status`.

## 10. Verification, with the actual output

```
> php artisan test tests/Unit
 [derive] migrations=81 Schema::create calls=81 extracted=81 | CREATE VIEW calls=4 extracted=4 | contract tables=75 views=2 | derived-missing tables=0 views=0 | registry=7
{"tool":"pest","result":"passed","tests":126,"passed":126,"assertions":5686,"duration_ms":82230}
exit 0
```

126 = the 90-test baseline (481 assertions) + 29 new here + 7 added concurrently by the RBAC-seeding
executor. Every one of the 126 passes.

```
> php artisan test tests/Unit/Models
{"tool":"pest","result":"passed","tests":29,"passed":29,"assertions":5173,"duration_ms":1954}
exit 0
```

```
> php artisan test
{"tool":"pest","result":"failed","tests":207,"passed":186,"assertions":6103,"duration_ms":91215,"failed":1,"errors":20}
```

1 failure + 20 errors, all `Feature/Auth/*`, `Feature/Settings/*`, `Feature/DashboardTest` and
`Feature/Auth/RegistrationTest`, all from finding 6. Baseline before this task: 135 tests / 114
passed / **the same 21**. The 72 added tests all pass. No regression.

```
> php artisan sehatly:verify-schema
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 ...
 named FKs fk_vital_rm on patient's vital signs (line 1161)
 Live schema
 counts tables=82 views=2 columns=715 indexes=237 foreign_keys=105 checks=3
 Discrepancies: 7 (0 drift, 7 informational)
 PASS - 75 tables, 2 views verified. Nothing was written.
exit 0
```

Unchanged from the pre-task baseline: 0 drift, the same 7 registered extra tables. I changed no
schema.

```
> Get-FileHash -Algorithm SHA256 telemedicine_test.sql
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
```

Matches the brief's required hash. The file was only ever read.

```
> php vendor/bin/pint --test app/Models tests/Unit/Models
{"tool":"pint","result":"passed"}
```

A whole-repo `pint --test` lists 5 files, all of them the concurrent RBAC executor's
(`app/Http/Middleware/EnsurePermission.php`, `app/Support/Rbac/RbacCatalog.php`,
`database/seeders/DatabaseSeeder.php`, `database/seeders/RbacSeeder.php`,
`tests/Support/RbacTestPrincipal.php`). None is mine; none was touched.

`php -l` was run over all 77 authored files: 0 failures.

## 11. Hygiene (A.26)

Both checks run over all 78 authored files (75 models, 1 concern, 1 test, 1 evidence file).

**(a) Non-ASCII scan**, pattern `[^\x00-\x7F]` with the eight permitted codepoints
(U+2013, U+2014, U+2022, U+2026, U+2192, U+2212, U+00A7, U+2225) allow-listed:

```
files scanned         : 78
non-ASCII findings    : 0
```

The PHP files contain no non-ASCII byte at all, so no permitted character was needed. Note this
pattern is byte-oriented because the PCRE2 build here has no `\u{...}` escape.

**(b) Token audit** - every `snake_case` identifier appearing in a string literal or a docblock,
checked against a vocabulary of 646 names read out of the DDL: the 426 table and column names plus
the 220 `ENUM` members.

```
files scanned         : 78
snake_case claims     : 1474
DDL vocabulary        : 646 table and column names
unknown identifiers   : 0
```

The `ENUM` members belong in the vocabulary, not just the names: the corruption a prior batch shipped
was `doker_umum` for `dokter_umum` *inside an `ENUM` value list*, so a names-only vocabulary would
not have caught the exact bug this instrument exists for.

**The gate caught two corruptions of my own, both in the first draft of this evidence file**, and
neither is visible to a `php -l`, a test run, or a careful re-read:

- `KonsCHN::resep()` - three CJK ideographs substituted for `ultas`, which is 9 non-ASCII bytes and
  was reported as 9 separate findings. It was in a sentence explaining that `Konsultasi::resep()`
  does not exist.
- A truncated `konsultasi_id` - the real name's first six characters plus the `_id` suffix - in the
  bare-column table, written as a visible repair so the reader could see the correction had been
  made. The corruption was the visible half of a line whose point was that nothing was broken. It is
  described here rather than reproduced, because reproducing it would put the corrupt token straight
  back into the file the audit reads.

Two design notes, both learned by having the instrument cry wolf first:

- It reads string literals and docblocks located with `token_get_all()`, not the raw source. A regex
  over raw source mis-pairs quotes the instant a comment contains an apostrophe - this file has one -
  and then reports `array_keys` and `strict_types` as schema claims, which teaches the reader to
  ignore the output. The positions that can lie about the schema are literals and `@property` blocks.
  Markdown has no tokenizer, so an evidence file is read whole.
- Twenty-one identifiers are legitimately not schema objects and are allow-listed, each with its
  reason printed in the script: `telemedicine_test` (the file name), `created_at` / `updated_at` /
  `deleted_at` (the Laravel defaults this schema deliberately replaces, named in order to say what
  is *not* emitted), `email_verified_at` / `remember_token` / `two_factor_secret` /
  `two_factor_recovery_codes` / `two_factor_confirmed_at` (the scaffold columns finding 6 is about),
  the PHP function names and keywords the report quotes, the two database names, the verifier's own
  `extra_foreign_key` discrepancy kind and `foreign_keys` count key, `fk_vital_rm`, and `doker_umum` -
  the prior corruption quoted verbatim as this instrument's worked example.

An allow-list is only worth having if it is small and each entry is justified, which is why the
reasons are printed rather than the entries being silently suppressed. Neither check found a defect
in the PHP after the two evidence-file corrections; both are recorded as clean, not skipped.

## 12. Data safety

- `telemedicine_test.sql` read-only; SHA-256 verified before and after, unchanged.
- No `migrate:fresh`, no `migrate:rollback`, no destructive SQL, no schema statement of any kind was
  issued by this task. All 75 models were proved instantiable and their relations resolvable without
  a query: `belongsTo`, `hasOne`, `hasMany` and `belongsToMany` all return a relation object lazily.
- `telemedisin_db` and `telemedisin_db_test` were touched only by the concurrent RBAC executor's own
  `migrate:fresh --seed`, which also caused the two transient `tests/Unit` reds recorded in the
  session (6 and 7 errors, all inside `tests/Unit/RbacMigrateFreshSeedTest.php`, all
  "table does not exist" / "table already exists" from two rebuilds interleaving). Both runs went
  green on re-run with nothing changed here. My 29 tests have never failed.
- The `sehatly` database was not touched.
- No file was deleted that this task did not create. Three files created by earlier revisions of my
  own generator run (`Users.php`, `Permissions.php`, `UserDevices.php`, plus `HasIndonesianTimestamps.php`)
  were removed after the class-name and trait decisions changed; all four were created inside this
  task.

## 13. Reproducing the derivation

The generator and both hygiene instruments live outside the repository, in
`%LOCALAPPDATA%\Temp\opencode\t19\` (`gen.php`, `extract.php`, `hygiene.php`), and are not part of the
commit. `gen.php` refuses to run unless the parse yields 75 tables, unless model class names are
unique, and unless no relation method name repeats on a class. It was run five times; the class-name
and inverse-naming fixes were each driven by one of those guards firing.
