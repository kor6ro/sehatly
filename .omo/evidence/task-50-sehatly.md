# Task 50 - deterministic NIK encryption with an HMAC blind index, and API masking

Commits: `24e3005` (cipher, exceptions, config, resources, tests) and `51f79bd`
(the two test additions that closed the mutation survivors).

Runtime: PHP 8.4.17 nts, `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe`,
prefixed on every invocation. laravel/framework 13.33. Per-executor database
`telemedisin_db_test_t50`, provisioned with `create database` + `migrate:fresh --seed`.
`phpunit.xml` untouched.

---

## 1. Every `nik` column in the schema, read from the file

A sweep of all 75 parsed tables for a column named `nik` returns exactly two, and
`tests/Feature/Pasien/NikCipherAuditTest.php` asserts that count, so a third one
cannot appear unnoticed:

| table | line | declaration | nullable | unique |
|---|---|---|---|---|
| `pasien` | `telemedicine_test.sql:222` | `nik CHAR(16) NULL UNIQUE COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP'` | yes | yes, one single-column UNIQUE |
| `pasien_anggota_keluarga` | `telemedicine_test.sql:263` | `nik CHAR(16) NULL,` | yes | **no** |

Surrounding anchors, so the citations are checkable rather than asserted:
`CREATE TABLE pasien` at `:218`, `user_id BIGINT UNSIGNED NOT NULL UNIQUE` at
`:220`, closing `) ENGINE=InnoDB;` at `:256`; `CREATE TABLE pasien_anggota_keluarga`
at `:259`, closing `) ENGINE=InnoDB;` at `:272`.

The sibling identifier on the same row is `pasien.nomor_kk CHAR(16) NULL` at
`:223`. It is **not** a NIK and is not treated as one; see section 7.

Plan-citation check: the plan's own `:222-223` for `pasien.nik`/`.nomor_kk` and
its `:263` for `pasien_anggota_keluarga.nik` are all **correct**. The plan's
inline citation for this pair is one of the accurate ones, which is not the usual
case in this plan, so it is stated rather than assumed.

---

## 2. FINDING: `CHAR(16)` cannot hold ciphertext, so a migration is unavoidable

**Stated plainly: a 16-character column cannot hold ciphertext. The DDL declares
`pasien.nik CHAR(16)`, so writing the payload there is arithmetically impossible,
not merely a bad default, and the schema change is unavoidable. I have not written
the migration - migrations are schema authority and a separate concern. Someone
has to author the SQL in section 2.1 and record it in `docs/schema-notes.md`.**

This is proved by the database, not argued. `tests/Feature/Pasien/NikCipherTest.php`
reads the live column out of `information_schema` (confirming `data_type = char`,
`character_maximum_length = 16`, `is_nullable = YES`, and one UNIQUE on it) and
then performs a **real insert into the real `pasien.nik` column carrying a real
payload**:

```
[ditolak=true, kode=1406]
```

MySQL answers 1406, "Data too long for column 'nik'", and the row count is
unchanged - a refusal, not a silent truncation. The same test then inserts the
same payload into a `CHAR(16)` probe column (1406) and a `TEXT` probe column
(stored, read back byte-identical).

The arithmetic behind it: a 16-digit NIK is 16 bytes, PKCS#7 always adds at least
one padding byte, so AES-256-CBC produces **32 bytes** of ciphertext - 44 base64
characters if hex, 64 if you double-encode. The payload `App\Support\NikCipher`
actually stores is **88 characters**, which is a deliberate choice and not
padding: 6 header bytes (4 magic + 2 key id) + 16 IV + 32 ciphertext + 12 HMAC tag
= 66 bytes = exactly 22 base64 groups, so no `=` is stored. See section 5 for why
the IV and the tag are there at all.

The plan's own rule at `sehatly-telemedicine-platform.md:58` says it directly -
"NEVER encrypt a value into a column narrower than its ciphertext" - which makes
`CHAR(16)` a self-contradiction in the declared schema. That is the finding.

### 2.1 The migration somebody has to author

For `pasien`, where the DDL mandates UNIQUE and the blind index carries it:

```sql
ALTER TABLE pasien
  ADD COLUMN nik_cipher TEXT NULL
    COMMENT 'AES-256-CBC payload: NKC1 magic, 2-byte key id, 16-byte IV, 32-byte ciphertext, 12-byte HMAC-SHA-256 tag; base64, 88 chars',
  ADD COLUMN nik_index CHAR(16) NULL
    COMMENT 'HMAC-SHA-256 blind index of the plaintext, first 16 base64 characters (96 bits); deterministic, unique-comparable, not reversible',
  ADD UNIQUE KEY uq_pasien_nik_index (nik_index);
```

For `pasien_anggota_keluarga`, which carries **no** UNIQUE on `nik` at `:263`, so
it gets the cipher column and **not** the index:

```sql
ALTER TABLE pasien_anggota_keluarga
  ADD COLUMN nik_cipher TEXT NULL
    COMMENT 'AES-256-CBC payload; see NikCipher::MAGIC';
```

Notes the migration author needs:

- **The original columns stay.** The plan is explicit that `pasien.nik` is kept
  for legacy and imported plaintext rows: readable, never written by the
  application. Do **not** drop it, and do **not** drop the UNIQUE that is on it -
  dropping it would weaken the declared schema for legacy rows and this todo's
  instruction is that no index or constraint is added or removed here.
- **Two UNIQUE constraints then exist**: the DDL's on `pasien.nik` and the new
  `uq_pasien_nik_index`. That is correct and intentional. Application rows write
  `nik = NULL` (so the DDL's UNIQUE is satisfied by every NULL, which is how MySQL
  treats it) and the real uniqueness work happens on the index. The parity
  verifier will report the two ADDED columns and the ADDED key; those belong in
  `docs/schema-notes.md` as documented extras, the way the seven Laravel tables
  already in the schema are.
- **Order matters for a live table**: add the columns, backfill
  `nik_cipher`/`nik_index` from the existing plaintext `nik` rows, then deploy the
  application that writes them, then decide about the legacy column. A backfill
  is the only window in which the same NIK exists as both plaintext and payload.
- **`docs/schema-notes.md` was not edited by me.** It is a shared document and a
  concurrent executor is active in the same tree; todo 46 recorded the same
  decision. The DDL above is the content that needs to land there.

### 2.2 What is deliberately NOT done here, and why

- No migration, no `ALTER`, no index, no constraint. Not in
  `database/migrations/`, not in `telemedicine_test.sql`.
- No cast. A `NikEncrypted` Eloquent cast is the plan's shape, but a cast is
  useless until the column exists, and writing one now would be an untestable
  class. The class that IS testable today, `App\Support\NikCipher`, has the exact
  signatures the cast will need: `encrypt(): string`, `decrypt(?string): ?string`,
  `index(string): string`, `indexMatches(?string, string): bool`, `mask(?string, ?string): ?string`.
- The `enum:` cast was not used and would have been a silent no-op on
  laravel/framework 13.33, so nothing in this todo depends on it.

---

## 3. The HMAC index, and why it is not a hash of the ciphertext

### 3.1 The property, proved three ways

| claim | how it is proved |
|---|---|
| same NIK twice, same index | 1000 successive `index()` calls on one NIK, all equal, and each paired with an `encrypt()` that is NOT equal |
| different NIKs, different indexes | 4000 generated 16-digit NIKs yield 4000 distinct 16-character indexes |
| the index is not reversible | it does not contain the NIK, is not `substr(base64_encode($nik),0,16)`, does not decode to it, and is not a bare digest (below) |
| it is KEYED, not hashed | `substr(base64_encode(hash('sha256',$nik)),0,16)` is computed in the test and is **not** the index; and a different `NIK_CIPHER_KEY` produces a different index, so the key is inside the MAC |
| it fits the column the UNIQUE will live on | 16 characters, `/^[A-Za-z0-9+\/]{16}$/`, no `=`, and round-trips byte-for-byte through a real `CHAR(16)` column |

A bare digest is reproducible by anyone holding the column - the NIK space is
10^16, which is enumerable - so the keyed MAC is the whole argument for calling
this a blind index rather than a slow-motion plaintext. A database-only leak, a
backup, a replica, a binlog or a stolen dump, is not enough to confirm a guessed
identity.

The width is 16 characters because 16 base64 characters carry 12 bytes, so **96
of the 256 HMAC bits survive** the truncation. The expected birthday collision
over the whole Indonesian population of roughly 1.3e8 NIKs is on the order of
1e-11. Halving the width to 8 characters would leave 48 bits and make collisions
likely enough to matter inside a national registry, so the width is a constant
that is asserted, and a mutation that halves it is killed by four tests.

### 3.2 The UNIQUE proof, executed in SQL rather than argued

`the HMAC index makes the DDL UNIQUE fire on a duplicate NIK and a ciphertext hash cannot`
puts both designs in **one** MySQL temporary table and makes the engine decide:

```sql
CREATE TEMPORARY TABLE t50_nik_probe (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nik_cipher TEXT NULL,
  nik_index CHAR(16) NULL,
  nik_cipher_index CHAR(16) NULL,
  UNIQUE KEY uq_nik_index (nik_index)
)
```

- One NIK, two payloads (random IV), **one** HMAC index. Second insert: refused,
  driver code **1062**.
- A third insert of the **same NIK**, indexed by a digest **of the ciphertext**
  instead: **admitted**. The table then holds two rows for one NIK.
- A different NIK: admitted. The index is not so coarse that everything collides.

That second bullet is the proof. A ciphertext-derived index cannot enforce the
DDL's UNIQUE, because the payload carries a random IV so one NIK has as many
payloads as it has encryptions. The only way to make a ciphertext-derived index
deterministic is to fix the IV, which converts the whole column into
deterministic encryption and leaks equality everywhere the ciphertext is read -
the data file, the binlog, a backup, any query log.

Equality leakage is a cost this project has to pay **once**. It is paid in the
index, which is a column built for lookup and nothing else. It is not also paid in
the ciphertext, which exists so a human-authorised read can recover the value.

Mutation **M6** re-introduces the shortcut (`index()` MACs `encrypt()` instead of
the plaintext) and is killed by 5 tests.

### 3.3 The temporary tables leave no residue

The UNIQUE experiment needs a shape that does not exist yet, so it runs in a MySQL
**TEMPORARY** table. That is not a convenience, it is the reason this todo can
prove the property at all without a migration: MySQL reports a temporary table in
**neither** `information_schema.TABLES` **nor** `information_schema.STATISTICS`,
which is asserted directly in
`the temporary probe table is invisible to the schema parity verifier`, together
with a row insert so the two empty assertions cannot pass vacuously. A visible
table would mean the experiment was adding schema behind the verifier's back and
every later `sehatly:verify-schema` would report drift.

`sehatly:verify-schema` exit 0, "PASS - 75 tables, 2 views verified. Nothing was
written." after the whole run.

---

## 4. The blind index is a genuine trade-off, not a free win

**What it gives away.** The index makes equal NIKs **linkable**. Anyone holding
the column - and the column is what a `UNIQUE` index is built over, so it is what
reaches backups, replicas and query logs - can group rows and learn which
patients share an identity, which in a telemedicine product reveals household and
family structure and tells an observer which rows to correlate across tables. An
observer who holds the column **and** `NIK_CIPHER_KEY` can go further and
**confirm** a guessed NIK by recomputing one index and looking for a match, which
turns the column from a grouping key into a confirmation oracle. That is why
nothing in this design ever decrypts a row to answer "does this NIK already
exist", and why `indexMatches()` is the only comparison and uses `hash_equals`.

**What it buys.** The DDL *mandates* `UNIQUE` on `pasien.nik`
(`telemedicine_test.sql:222`), so deduplication is a stated integrity requirement
that cannot be dropped in the name of a nicer privacy property. A registry that
admits two rows for one person is a data-quality defect that propagates into every
clinical record attached to that person, and the cipher is in no position to
second-guess the schema about that.

**Why it is acceptable here, narrowly.** Because the benefit is an integrity
guarantee the DDL already demands, and the cost is confined to a column that
exists for lookup. And the cost is **not** paid on
`pasien_anggota_keluarga`: `:263` carries **no** `UNIQUE`, so an index on that
table would buy no integrity guarantee at all while still linking every relative
of every patient. That table's migration in section 2.1 therefore carries
`nik_cipher` and no index, and `index()` is an explicit call so the decision is
visible at the call site rather than implied.

If the product later decides that `pasien_anggota_keluarga` must reject a
duplicate NIK, the index is one column away - and that is a decision with a
privacy cost attached, so it should be made deliberately rather than inherited.

---

## 5. The payload is encrypted AND authenticated, and why that changed

The first implementation was AES-256-CBC with a magic marker, a key id, a stored
IV and a PKCS#7 padding check. The suite found a real hole in it:
`iv zeroed` - a real payload whose 16-byte IV was replaced with zeroes - was
**not** refused. `openssl_decrypt` returned 17 bytes of plausible-looking garbage
that passed the padding check. That is the classic CBC malleability, and it
succeeds on wrong input once in 256; the suite happened to hit it on the run that
first exercised the case.

A masked NIK built from those bytes would have been served to a client as a real
patient identifier. So the payload became **encrypt-then-MAC**: a 12-byte
HMAC-SHA-256 tag over the header, the IV and the ciphertext, verified with
`hash_equals` **before** `openssl_decrypt` is called. The ordering is load-bearing;
the other order is the padding oracle. The MAC uses its own HKDF-derived subkey,
so a compromise of the index key does not hand over the cipher key or the tag key.

The test does **not** assert the 1-in-256 event, because a 1-in-256 assertion is
a flaky test. What it asserts is the property that removes the dice: a body with
one flipped bit in the **tag** is refused, a body with one flipped bit in the
**ciphertext** is refused, and both refusals are `corrupt()` rather than
`unknownKey()`, which proves the header was read first and the tag second.
Mutation **M2** deletes the tag check and is killed.

The tag also changed the stored length from 72 to 88 characters, which makes the
`CHAR(16)` finding stronger, not weaker.

---

## 6. Key source, missing key, and rotation

### 6.1 Where the key comes from

`NIK_CIPHER_KEY` in the environment, read by `config/nik.php` into `nik.key`.
**Not `APP_KEY`, and not derived from it.** `APP_KEY` rotates for reasons that
have nothing to do with patient identity - a suspected session-cookie compromise,
a new deployment - and because the blind index is derived from the same secret,
rotating `APP_KEY` would re-derive every index and make the duplicate-registration
check report every NIK as untaken while clinical records become unreadable. An
unrelated security action would become destructive. Mutation **M10** points
`config/nik.php` at `APP_KEY` and is killed by a test that re-requires the config
file with a controlled environment.

Generation, per environment:

```
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
```

`.env.example` gains `NIK_CIPHER_KEY=` and `NIK_CIPHER_PREVIOUS_KEYS=`, both
**empty**, with the reasoning inline. `.gitignore` already excludes `.env`; the
test asserts that. A test greps `app/Support/NikCipher.php`, `config/nik.php` and
`.env.example` for a 44-character base64 literal (the shape of a 32-byte key) and
requires zero hits - a key in a tracked file is not a key.

### 6.2 What happens if it is missing

It **fails loudly on every entry point** - `encrypt()`, `index()`, `decrypt()` -
with `MissingNikCipherKeyException`, whose message names `NIK_CIPHER_KEY`, says
`base64`, says `32`, and gives the one-line command that produces one. There is no
fallback, no default, and no substitution.

This guard is load-bearing and the suite **demonstrates** why rather than
asserting it: `openssl_encrypt` does **not** validate key length. Handed the 6-byte
string `'pendek'` it zero-pads and returns a perfectly formed 32-byte ciphertext.
An application with a broken key would look completely healthy - writes succeed,
reads succeed - while every patient identity is encrypted under a value nobody
recorded. The test proves openssl accepts the short key and then proves
`NikCipher` refuses it.

The same applies to a wrong-length key: 16, 31 and 33 bytes, non-base64, and
base64-of-nothing are all rejected. A `base64:` prefix is accepted, because that
is Laravel's own convention for `APP_KEY` and an operator who has generated one
key should not have to remember which of two shapes this one wants.

`hasKey()` exists for a boot-time health check, and is never a substitute for the
guards.

### 6.3 Rotation

The root key is expanded by `hash_hkdf` into **three independent subkeys** - cipher,
index, MAC - with distinct `info` labels, so holding one does not give you the
others. The index key is the one that touches the most infrastructure (it is what
the `UNIQUE` is built over), and it still cannot decrypt a payload.

Every payload carries a **two-byte key id** derived from the cipher subkey that
wrote it. `NIK_CIPHER_PREVIOUS_KEYS` accepts older keys and `decrypt()` reads a
row written by any of them, so the new key can be deployed **before** the rows are
rewritten. The order is:

1. set the new `NIK_CIPHER_KEY`, move the old one into `NIK_CIPHER_PREVIOUS_KEYS`,
   deploy. Reads keep working - proved: a payload written under key A reads back
   under key B with A listed, and is **refused** with A absent.
2. re-encrypt every `nik_cipher` row, comparing `NikCipher::keyFingerprint()`
   against the value each row was written with. `indexKeyFingerprint()` names the
   index key in force, which is what a runbook reads to know which pass is
   outstanding.
3. once no row references the old key, empty `NIK_CIPHER_PREVIOUS_KEYS`.

**The thing rotation breaks, stated rather than discovered:** the index is
re-derived. A row re-encrypted under the new key gets a new index, so until
*every* row has been rewritten the registration uniqueness check reports a
duplicate NIK as untaken. That is asserted directly - the old key's index and the
new key's index are asserted **different**, and `indexMatches()` against the old
index is asserted `false` under the new key. It is why rotation is a
re-encryption pass and not a config edit.

The MAC is verified under the key that **wrote** the payload, not the key in
force. That was a real bug found by the rotation test: checking it against the
current key rejects every row from before a rotation, which is the exact opposite
of what the previous-key list is for.

`config()` is read on every call rather than memoised, so there is no cache to
stale and the rotation test is three lines rather than a cache-clearing ritual.

---

## 7. API masking: one masker, one entry point, five resources

`App\Support\NikMasker` is reused, not reimplemented. `NikCipher::mask()`
**delegates** to it; a second implementation of "keep four, bullet the rest, keep
four" would be a second place for the next edit to go wrong.

Six call sites now route a `nik` key through `NikCipher::mask($payload, $legacy)`:

| resource | `nik` source |
|---|---|
| `PasienResource` | its own `nik` |
| `PasienAnggotaKeluargaResource` | its own `nik` |
| `BookingResource` | `$this->resource->pasien->nik` |
| `KonsultasiResource` | `$this->resource->pasien->nik` |
| `RekamMedisResource` | `$this->resource->pasien->nik` |
| `SuratKeteranganResource` | `$this->resource->pasien->nik` |

**This is a behavioural no-op today**, which is the point: `nik_cipher` does not
exist, so it reads as `null` and the legacy plaintext column is what gets masked.
The output is character-identical to what the resources published before, and the
whole pre-existing suite that asserts masked NIKs in real HTTP bodies
(`BookingTest`, `KonsultasiTest`, `RekamMedisTest`, `SuratKeteranganTest`,
`AuditLoggingTest`, `RedactionAbsenceTest`, `RedactionGateTest`) still passes. The
call is written for the shape both columns will have, so the migration lands and
the resources need no second edit.

The one masker is enforced structurally, not by convention. A test walks every
file in `app/Http/Resources`, finds every `'nik' =>` line, and requires each one to
contain `NikCipher::mask(`; the resulting resource set is asserted as a closed list
of six; and no resource except `PasienResource` may call `NikMasker::mask(` at all.
`PasienResource` is allowed exactly one direct call, and it is pinned to
`'nomor_kk'`.

**Why `nomor_kk` is the exception.** `pasien.nomor_kk CHAR(16)` (`:223`) is the
head-of-household family-card number, not the national identity this todo scoped,
and **no cipher column is proposed for it** - so there is nothing to decrypt and
routing it through `NikCipher::mask(null, ...)` would be a lie about a column that
will not exist. It goes straight to the shared rule, and the one allowed call site
is pinned so that cannot quietly become two.

A structural test also requires the body of `PasienResource` to contain neither
the plaintext, nor a payload, nor an index, and to contain the mask
exactly once.

---

## 8. Red, then green

**RED**, before any implementation existed:

```
{"tool":"pest","result":"failed","tests":22,"passed":0,"assertions":12,"duration_ms":9803,"failed":1,...}
"errors":21
  21 x  "Class \"App\\Support\\NikCipher\" not found"
   1 x  the one test that references no NikCipher symbol
```

The 21 errors are all the right reason - the class under test does not exist yet -
and the one test that passed is the structural resource test, which is the harness
proving itself: the resource instantiations work before the cipher does.

**Four harness defects surfaced during the red-to-green loop, all mine, all fixed
before they could mask a real signal:**

1. `$kolom->data_type` was an undefined property. `information_schema` returns its
   column labels **uppercased**, so every one is now aliased (`as tipe`,
   `as panjang`, `as boleh_null`).
2. The body assertion looked for a literal mask, but `json_encode` escapes U+2022
   to `<`. Now `JSON_UNESCAPED_UNICODE`, which is the right assertion anyway -
   it checks the characters the response really contains.
3. `RekamMedisResource::adalahTerkini()` reads the `ran` amendment chain off the
   model rather than re-querying, because a read that does not log is the one
   thing that design refuses. An unset relation is a fixture problem, so the
   fixture sets it.
4. `/^\d{4}\x{2022}{8}\d{4}$/` raised "Regular expression cannot be matched:
   Internal error" - `\x{...}` over a byte subject. Added the `u` modifier.
5. Two tests set a flag in an outer scope from inside a **closure** and then
   asserted it. A closure assigning to a captured variable changes its own copy,
   so the missing-key test would have passed whatever the code did. Both now use
   `&$flag`, and the reason is in a comment so nobody removes it.

**GREEN**, `NikCipher` only: 22/22, 2217 assertions. With the audit file: 29/29,
2274 assertions.

**One real implementation bug the tests caught**: the first draft read
`config('NIK_CIPHER_KEY')` - the environment variable name - where it needed
`config('nik.key')` - the config key. That returns null forever and every call site
raises. The constants are now split into `ENV_KEY` / `CONFIG_KEY` with a docblock
explaining that they are different names for different things, so the two cannot
be confused again.

---

## 9. Mutation harness, control first

`C:\Users\axioo\AppData\Local\Temp\opencode\t50-mutation.php` - in the temp
directory, not in the repository, because a mutation harness that ships would be a
thing that can fail CI for reasons unrelated to the code under test.

**CONTROL, RUN FIRST, and it was green:**

```
CONTROL green=YES tests=29 -- result=passed tests=29 assertions=2274 failedKeyPresent=no
```

`failedKeyPresent=no` is **recorded, not asserted**, and that is the point: the
pest JSON reporter omits `failed` and `errors` when they are zero, so a naive
parser reads a green run as red. The verdict is a conjunction - a result word, a
non-zero test count, every failure count present-or-zero, and `passed === tests` -
so a lost run, an empty suite and an absent key are three different ways of being
wrong and each conjunct closes one.

All **ten** mutations killed, each reverted:

| # | mutation | killed by |
|---|---|---|
| M1 | ignore the key id in the header | 1 error |
| M2 | drop the authentication tag check | 1 failure |
| M3 | derive the index from an **unkeyed** digest | 3 failures |
| M4 | halve the index to 8 characters (96 -> 48 bits) | 4 failures |
| M5 | use a fixed zero IV, making the ciphertext deterministic | 4 failures |
| M6 | index the **ciphertext** instead of the plaintext | 5 failures |
| M7 | accept any base64 length as a key | 1 failure |
| M8 | publish the raw NIK when no payload is present | 3 failures |
| M9 | drop the magic-marker check | 1 failure (after the fix) |
| M10 | point `config/nik.php` at `APP_KEY` | 1 failure (after the fix) |

**The harness hurt itself first, and that is worth recording.** The verdict
function had an operator-precedence bug
(`array_key_exists($key, $report ? 'yes' : 'no')`) that threw a TypeError. Because
the revert was a straight-line statement *after* the run, the crash skipped it -
and the next mutation was then applied on top of the first, so the tree carried
**two** live mutations until `git checkout HEAD -- app/Support/NikCipher.php` was
run by hand. The revert now lives in a `finally`, and the reasoning is in a
comment in the harness. A harness that can leave the code it is measuring mutated
is worse than no harness.

**Two mutations survived the first pass, and both were real gaps in the tests,
not in the code:**

- **M9 (drop the magic marker) survived** because a 16-digit plaintext is only
  12 bytes once base64-decoded, so the **length** check refuses it first. The
  test was proving the wrong guard. Fixed by using a value that decodes to the
  *right* length and is still not a payload, and by asserting the message names
  `NKC1` and "plaintext" - so an operator reading a refusal is told which check
  fired.
- **M10 (`config/nik.php` reads `APP_KEY`) survived** because every test sets
  `config('nik.key')` directly and therefore never evaluates the config file.
  Fixed by re-requiring `config/nik.php` with a controlled `$_ENV` and asserting
  the result: null with only `APP_KEY` set, the NIK value with it set, and the
  previous-key list split on commas with blanks dropped.

The parse-error guard is in place too: a mutation leaving an unparseable file is
rejected before the run, because erroring every test looks like the strongest
possible signal and measures nothing.

---

## 10. Byte-level scans

Two independent scans, because a gate that shares code with the code it gates
cannot report a broken gate.

**In-suite** (`NikCipherAuditTest`): 13 authored files read as **raw bytes**,
walked with `ord()`, never decoded, 0 bytes above 0x7F, 0 BOMs, with a total-byte
floor so a broken harness cannot pass by reading nothing.

**Out-of-band** (`t50-byte-scan.php`, 14 files including `.env.example`):

```
files=14
total_bytes=136403
non_ascii_bytes=0
bom=0
homoglyph_or_dash_hits=0
VERDICT: CLEAN
```

The homoglyph check decodes as UTF-8 and names any codepoint in thirteen ranges:
Cyrillic, Greek, Cyrillic supplement, dash-like, quote-like, ellipsis, NBSP,
minus, multiplication, bullet, fullwidth, smart quotes. A Cyrillic U+0430 is
valid UTF-8, survives `mb_strtolower()` and matches `/^[a-z]/` - only a byte
comparison against 0x7F catches it, and this project's own history is why.

**The gate caught me.** The first audit run reported bytes 1132 and 1133 of the
audit test itself, `0xC2 0xAB`: a comment that had been written with a literal
U+00AB instead of naming the codepoint. Both are gone, and the incident is in the
file's header so the same mistake is not re-explained later.

The mask character is written as the PHP escape `"\u{2022}"` everywhere, which is
what makes a whole-file ASCII assertion possible at all.

---

## 11. Test output

**Control, before any of this existed**, on the private DB: `1025/1025 passed,
16800 assertions`.

**This todo's own suites:** `29/29 passed, 2274 assertions`.

**Full suite, after the change:** `1074 tests, 1070 passed, 19578 assertions,
3 failed, 1 error`. Every one of the four is the concurrent executor's, and this
was **proved rather than argued**: the six resources were reverted to
`HEAD~1` byte-for-byte and the same three files were re-run, producing the
identical failures - `permission:notifikasi.lihat` three times in
`AuthFlowTest:1301` and `PasienProfileTest:1780`, and six extra routes
(`api/v1/notifikasi`, `api/v1/notifikasi/baca-semua`,
`api/v1/notifikasi/{id}/baca`, `api/v1/pdp/persetujuan` GET and POST) in the
`PasienProfileTest:1403` closed set. That executor's work is uncommitted in
`routes/api.php`, `AppServiceProvider.php` and `PdpConsent.php` with eleven
untracked files including `tests/Feature/Pdp/`, and one failure message even
contains a **torn read** of the file it is asserting on -
`api/v1/pasien/anggota-kel..a/{id}` - which is what an edit in progress looks like
from the outside. Nothing in that output mentions a NIK.

My own isolation: `route:list --path=api/v1` reports **74** routes, of which 69
are the baseline set and **5 are the concurrent executor's**. This todo adds
**zero** routes. `sehatly:verify-schema` exit 0, 75 tables, 2 views, 0 drift.

`telemedicine_test.sql` SHA-256
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, byte-identical
- asserted by a test rather than promised in prose. `php -l` clean on all twelve
authored PHP files. `mobile/` absent. `database/migrations/`,
`database/seeders/`, `web/` and `packages/` untouched. No index and no constraint
added. No `migrate:rollback`, no `artisan serve`. Every commit used an explicit
pathspec naming only this todo's files.

### `git show --stat`

```
commit 24e3005eba6f206421d130b946fb6ccbd65faf71
Author: Ahmadz <ahmadz@gmail.com>
Date:   Tue Sep 29 08:06:11 2026 +0700

    feat(security): encrypt NIK with an HMAC blind index and mask it at the API

 .env.example                                       |  14 +
 app/Http/Resources/BookingResource.php             |   3 +-
 app/Http/Resources/KonsultasiResource.php          |   3 +-
 app/Http/Resources/PasienResource.php              |  12 +-
 app/Http/Resources/PasienAnggotaKeluargaResource.php |   9 +-
 app/Http/Resources/RekamMedisResource.php          |   3 +-
 app/Http/Resources/SuratKeteranganResource.php     |   3 +-
 app/Support/NikCipher.php                          | 583 +++++++++++++
 app/Support/Security/MissingNikCipherKeyException.php |  42 ++
 app/Support/Security/NikDecryptionException.php    |  75 ++
 config/nik.php                                     |  66 ++
 tests/Feature/Pasien/NikCipherAuditTest.php        | 358 +++++++
 tests/Feature/Pasien/NikCipherTest.php             | 915 +++++++++
 13 files changed, 2080 insertions(+), 6 deletions(-)

commit 51f79bd4a2928c443aa4e336db70c09f189cbdb8
    test(security): close the two mutation survivors - the marker check and the config key source

 tests/Feature/Pasien/NikCipherTest.php | 65 ++++++++++++++++++++++++++++++++++
 1 file changed, 65 insertions(+)
```

---

## 12. Unfinished, and what someone else has to do

1. **The migration in section 2.1 must be authored.** Nothing in this todo can be
   used in production until it exists; today the cipher is implemented, tested
   and unreachable, and every resource is masking a legacy plaintext value
   through it. This is the one blocking item.
2. **The backfill.** Section 2.1's DDL creates the columns; nothing moves the
   existing plaintext NIKs into them. That is a data migration with its own
   plan.
3. **`docs/schema-notes.md` was not edited** - a shared document with a concurrent
   executor active. The DDL and the trade-offs in sections 2.1 and 4 are the
   content that needs to land there.
4. **`AuditColumnPolicy` is a known gap.** It calls `NikMasker::mask($value)` for
   the `nik` column, which is correct today. When `pasien.nik_cipher` exists, the
   audit writer will mask the **payload** rather than the identity, so a future
   audit row for a patient write will read eight bullets after an `NKC1` prefix. A cast or a policy
   entry that decrypts first is needed then. Not in scope here and not silently
   ignored.
5. **The `NikEncrypted` Eloquent cast** from the plan's design is not written.
   `NikCipher` has the signatures it needs; writing a cast against a column that
   does not exist would be an untestable class.
6. **A rotation command.** `keyFingerprint()` and `previous_keys` are the
   mechanism; the re-encryption pass in section 6.3 step 2 is a command nobody has
   written.
7. **The four full-suite failures are not mine and are not fixed here** - see
   section 11. They belong to the executor working on the notification and PDP
   surface in the same tree.

---

## 13. The ledger, and a hazard in it worth naming

One line, built with `node -e` and `JSON.stringify`, written to a **temp file**
first and validated as parsing before anything was appended. The append used
`fs.appendFileSync` - no shell redirection anywhere near the file, and the growth
was measured and reported: `grew=19692 appended=19692 prefix_preserved=true`.

**A hazard was found and had to be repaired.** The concurrent executor had
appended its todo-47 line with **no trailing newline**, so the append-only write
fused the two entries into one line and the file's last line no longer parsed
(`Unexpected non-whitespace character after JSON at position 223`). The repair was
a **single-byte newline inserted at the known offset**, guarded by an explicit
assertion that the prefix was preserved byte-for-byte, that the suffix after the
insert point was untouched, and that the total grew by exactly 1 - the guard
refuses to write if any of those fail. Afterwards: **69 non-empty lines, all 69
parse, 0 unparseable**, and this todo's entry is last.

Three blank lines pre-date this todo, left by earlier appends; they are not damage
and were not touched.

**The hazard is general.** The ledger's integrity depends on every append ending
in a newline, and nothing enforces that. A `>>` redirect, an `echo` without a
trailing newline, or a writer that trims before appending will silently fuse two
entries. Any executor that appends here should either end its line with `\n` or
verify the last byte of the file is one before appending.

`.omo/plans/` is orchestrator-owned and was not touched: no checkbox was marked.

