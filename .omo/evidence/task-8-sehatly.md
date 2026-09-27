# Task 8 evidence — migration batch B (SQL tables 12–19)

Executor lane, branch `feat/sehatly-telemedicine`. Every shell line below ran
with project-local PHP first:
`$env:PATH = "C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64;$env:PATH"`
(PHP 8.4.17; bare `php` is 8.2.29 and fails Laravel `^8.3`).

Commit-message note: the plan body (line 312) says
`feat(db): migrate users, RBAC, OTP, device and refresh-token tables`, but the
executor brief mandates exactly
`feat(db): add users, RBAC, OTP, devices and refresh token migrations`.
The brief wins; the discrepancy is recorded here.

## 1. Manual-QA channel, verbatim

### `php artisan migrate:fresh` → exit 0

```
Dropping all tables .. 100.71ms DONE

INFO Preparing database.

Creating migration table .. 22.07ms DONE

INFO Running migrations.

0001_01_01_000001_create_cache_table .. 55.55ms DONE
0001_01_01_000002_create_jobs_table .. 68.33ms DONE
2026_09_26_222801_create_personal_access_tokens_table .. 61.02ms DONE
2026_10_01_000001_master_provinsi_table .. 25.46ms DONE
2026_10_01_000002_master_kabupaten_kota_table .. 56.35ms DONE
2026_10_01_000003_master_kecamatan_table .. 79.86ms DONE
2026_10_01_000004_master_kelurahan_table .. 141.65ms DONE
2026_10_01_000005_master_agama_table .. 23.83ms DONE
2026_10_01_000006_master_golongan_darah_table .. 30.93ms DONE
2026_10_01_000007_master_pendidikan_table .. 20.15ms DONE
2026_10_01_000008_master_status_pernikahan_table .. 12.56ms DONE
2026_10_01_000009_master_hubungan_keluarga_table .. 13.68ms DONE
2026_10_01_000010_master_icd10_table .. 34.45ms DONE
2026_10_01_000011_master_icd9cm_table .. 23.13ms DONE
2026_10_01_000012_users_table .. 58.64ms DONE
2026_10_01_000013_roles_table .. 29.47ms DONE
2026_10_01_000014_permissions_table .. 33.61ms DONE
2026_10_01_000015_role_permissions_table .. 59.36ms DONE
2026_10_01_000016_user_roles_table .. 64.85ms DONE
2026_10_01_000017_user_otp_table .. 44.52ms DONE
2026_10_01_000018_user_devices_table .. 65.34ms DONE
2026_10_01_000019_user_refresh_tokens_table .. 49.83ms DONE

EXIT=0
```

This is the second full run (the first was `--force`, then a
`rollback --step=8` + `migrate` cycle — §8 — then this canonical bare run).
Three runs, identical final state: the procedure is idempotent.

### `php artisan sehatly:verify-schema --tables=users,roles,permissions,role_permissions,user_roles,user_otp,user_devices,user_refresh_tokens` → exit 0

```
Sehatly schema parity verifier — read-only, non-zero on drift

reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
live database mysql / telemedisin_db
notes registry docs/schema-notes.md (7 registered extra tables)
scope users, roles, permissions, role_permissions, user_roles, user_otp, user_devices, user_refresh_tokens

Parsed reference model (proof the parser is not vacuous)
counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
wrapped decls 11 — each read as ONE unit: booking.status (515-516), home_care_pesanan.status (1104-1105), invoice.status (947-948), klaim_bpjs.status (1022-1023), konsultasi.status (542-543), konsultasi_chat.tipe_pesan (568-569), lab_permintaan.status (884-885), master_obat.bentuk_sediaan (713-714), persetujuan_pdp.jenis (1137-1138), pesanan_obat.status (810-811), resep.status (751-752)
named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
named FKs fk_vital_rm on pasien_tanda_vital (line 1161)

Live schema
counts tables=26 views=0 columns=122 indexes=54 foreign_keys=10 checks=0
information_schema columns=122 indexes=54 foreign_keys=10 checks=0

Discrepancies: 0 (0 drift, 0 informational)
none — the live schema is byte-for-byte equivalent to the reference DDL.

PASS — 75 tables, 2 views verified. Nothing was written.

EXIT=0
```

False-green-trap accounting (A.7/A.8): the PASS banner's "75 tables, 2 views"
is formatted from the full reference model, not the scope — meaningless here.
The authoritative signals are `Discrepancies: 0`, exit 0, and the echoed
`scope` line listing all 8 real names (`users, roles, permissions,
role_permissions, user_roles, user_otp, user_devices, user_refresh_tokens`) —
no `unknown_requested_table` row, so no typo hid behind a green exit.

## 2. SHOW CREATE TABLE outputs

### users — `dihapus_at timestamp NULL DEFAULT NULL`, no `datetime`

```sql
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_lengkap` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_telepon` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kata_sandi_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt/argon2',
  `tipe` enum('pasien','dokter','perawat','apoteker','kurir','admin','superadmin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pasien',
  `status` enum('pending_verifikasi','aktif','nonaktif','ditangguhkan') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_verifikasi',
  `foto_profil` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bahasa` enum('id','en') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'id',
  `telepon_terverifikasi` tinyint(1) NOT NULL DEFAULT '0',
  `email_terverifikasi` tinyint(1) NOT NULL DEFAULT '0',
  `last_login_at` datetime DEFAULT NULL,
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diubah_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `dihapus_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_uuid_unique` (`uuid`),
  UNIQUE KEY `users_no_telepon_unique` (`no_telepon`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

`dihapus_at timestamp NULL DEFAULT NULL` present; the token `datetime` appears
nowhere in the column list (it occurs only in `last_login_at datetime`,
which is what `:145` declares). Scaffold columns `name`, `email_verified_at`,
`password`, `remember_token` absent.

### role_permissions — no `id`

```sql
CREATE TABLE `role_permissions` (
  `role_id` smallint unsigned NOT NULL,
  `permission_id` smallint unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `role_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

(`KEY ..._permission_id_foreign` is InnoDB's own FK-support index — the
`permission_id` FK has no covering index in the DDL. The verifier's
implied-index rule forgives it; `Discrepancies: 0` confirms. `role_id` rides
the leftmost column of the composite PK.)

### user_roles — no `id`

```sql
CREATE TABLE `user_roles` (
  `user_id` bigint unsigned NOT NULL,
  `role_id` smallint unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `user_roles_role_id_foreign` (`role_id`),
  CONSTRAINT `user_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_roles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### user_refresh_tokens — no unique on `token_hash`, no `device_id`

```sql
CREATE TABLE `user_refresh_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kedaluwarsa_at` datetime NOT NULL,
  `dicabut` tinyint(1) NOT NULL DEFAULT '0',
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_refresh_tokens_user_id_foreign` (`user_id`),
  CONSTRAINT `user_refresh_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### master_agama (batch-A regression) — `tinyint unsigned`, no AUTO_INCREMENT

```sql
CREATE TABLE `master_agama` (
  `id` tinyint unsigned NOT NULL,
  `nama` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## 3. information_schema parity dump (misleading_success_output probe)

Queried directly, not via the verifier: every column's `COLUMN_TYPE`,
`IS_NULLABLE`, `COLUMN_DEFAULT`, `EXTRA` against the SQL line by line, and
the composite PKs in `information_schema.KEY_COLUMN_USAGE`.

```
TABLE_NAME          COLUMN_NAME            COLUMN_TYPE                                                                          IS_NULLABLE  COL_DEFAULT        EXTRA
users               id                     bigint unsigned                                                                      NO           <none>             auto_increment
users               uuid                   char(36)                                                                             NO           <none>
users               nama_lengkap           varchar(150)                                                                         NO           <none>
users               email                  varchar(255)                                                                         YES          <none>
users               no_telepon             varchar(20)                                                                          NO           <none>
users               kata_sandi_hash        varchar(255)                                                                         NO           <none>
users               tipe                   enum('pasien','dokter','perawat','apoteker','kurir','admin','superadmin')             NO           pasien
users               status                 enum('pending_verifikasi','aktif','nonaktif','ditangguhkan')                           NO           pending_verifikasi
users               foto_profil            varchar(500)                                                                         YES          <none>
users               bahasa                 enum('id','en')                                                                      NO           id
users               telepon_terverifikasi  tinyint(1)                                                                           NO           0
users               email_terverifikasi    tinyint(1)                                                                           NO           0
users               last_login_at          datetime                                                                             YES          <none>
users               dibuat_at              timestamp                                                                            NO           CURRENT_TIMESTAMP  DEFAULT_GENERATED
users               diubah_at              timestamp                                                                            NO           CURRENT_TIMESTAMP  DEFAULT_GENERATED on update CURRENT_TIMESTAMP
users               dihapus_at             timestamp                                                                            YES          <none>
roles               id                     smallint unsigned                                                                    NO           <none>             auto_increment
roles               nama                   varchar(50)                                                                          NO           <none>
roles               deskripsi              varchar(255)                                                                         YES          <none>
permissions         id                     smallint unsigned                                                                    NO           <none>             auto_increment
permissions         kode                   varchar(100)                                                                         NO           <none>
permissions         nama                   varchar(100)                                                                         NO           <none>
role_permissions    role_id                smallint unsigned                                                                    NO           <none>
role_permissions    permission_id          smallint unsigned                                                                    NO           <none>
user_roles          user_id                bigint unsigned                                                                      NO           <none>
user_roles          role_id                smallint unsigned                                                                    NO           <none>
user_otp            id                     bigint unsigned                                                                      NO           <none>             auto_increment
user_otp            user_id                bigint unsigned                                                                      NO           <none>
user_otp            kode_hash              varchar(255)                                                                         NO           <none>
user_otp            tujuan                 enum('verifikasi_telepon','verifikasi_email','reset_kata_sandi','login')              NO           <none>
user_otp            kedaluwarsa_at         datetime                                                                             NO           <none>
user_otp            sudah_dipakai          tinyint(1)                                                                           NO           0
user_otp            dibuat_at              timestamp                                                                            NO           CURRENT_TIMESTAMP  DEFAULT_GENERATED
user_devices        id                     bigint unsigned                                                                      NO           <none>             auto_increment
user_devices        user_id                bigint unsigned                                                                      NO           <none>
user_devices        device_id              varchar(255)                                                                         NO           <none>
user_devices        platform               enum('android','ios','web')                                                          NO           <none>
user_devices        fcm_token              varchar(255)                                                                         YES          <none>
user_devices        app_versi              varchar(20)                                                                          YES          <none>
user_devices        aktif                  tinyint(1)                                                                           NO           1
user_devices        last_active_at         datetime                                                                             YES          <none>
user_devices        dibuat_at              timestamp                                                                            NO           CURRENT_TIMESTAMP  DEFAULT_GENERATED
user_refresh_tokens id                     bigint unsigned                                                                      NO           <none>             auto_increment
user_refresh_tokens user_id                bigint unsigned                                                                      NO           <none>
user_refresh_tokens token_hash             varchar(255)                                                                         NO           <none>
user_refresh_tokens kedaluwarsa_at         datetime                                                                             NO           <none>
user_refresh_tokens dicabut                tinyint(1)                                                                           NO           0
user_refresh_tokens dibuat_at              timestamp                                                                            NO           CURRENT_TIMESTAMP  DEFAULT_GENERATED
```

Line-by-line verdict: 48/48 columns match (`users` 16 = `:133-148`,
`roles` 3, `permissions` 3, `role_permissions` 2, `user_roles` 2,
`user_otp` 7, `user_devices` 9, `user_refresh_tokens` 6).
`email` is nullable with a UNIQUE index; `tipe`/`status`/`bahasa`/`tujuan`/
`platform` carry the SQL's values in the SQL's order with the SQL's defaults;
`diubah_at` carries `on update CURRENT_TIMESTAMP`; `dihapus_at` is
`timestamp`, not `datetime`; scaffold columns `name`, `email_verified_at`,
`password`, `remember_token` are gone.

Composite PKs in `KEY_COLUMN_USAGE` (not just `SHOW CREATE TABLE`):
`role_permissions.PRIMARY = (role_id:1, permission_id:2)`,
`user_roles.PRIMARY = (user_id:1, role_id:2)`.
Named key round-trip: `user_devices.uq_device = (user_id, device_id)`.
Uniqueness semantics: `users.{uuid,no_telepon,email}`, `roles.nama`,
`permissions.kode` each UNIQUE (server/Laravel spellings of the index name
differ — `users_email_unique` vs `email` — which the verifier compares by
semantics per the documented normalisation rule).

## 4. Insert experiments

Against `telemedisin_db` (raw `mysql.exe`, `--batch --raw`):

NULL-email double insert — SUCCEEDED (proves nullable-UNIQUE semantics):

```sql
INSERT INTO users (uuid, nama_lengkap, email, no_telepon, kata_sandi_hash) VALUES
('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','Uji Null Satu',NULL,'089999900001','x'),
('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','Uji Null Dua',NULL,'089999900002','x');
SELECT id, uuid, email, no_telepon FROM users;
-- id  uuid                                   email  no_telepon
-- 1   aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa   NULL   089999900001
-- 2   bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb   NULL   089999900002
-- EXIT=0
```

Duplicate `no_telepon` — FAILED as required (exit 1,
`ERROR 1062 (23000): Duplicate entry '089999900001' for key
'users.users_no_telepon_unique'`). The duplicate row used `email = NULL`,
so the failure is unambiguously the phone uniqueness, not the email index.

Probe rows cleaned up afterwards: `DELETE FROM users;` →
`users_rows = 0`. `telemedisin_db` left fully migrated to batch B, all tables
empty — deliberate state.

## 5. Test-suite summaries

- `php artisan test tests/Feature/UsersTableSchemaTest.php` → exit 0,
  3 passed, 8 assertions. Single-file invocation: none of the pre-existing
  broken Feature auth tests entered scope.
- `php artisan test tests/Unit` → exit 0, **73 passed (73 tests, 325
  assertions)** — meets the >= 73 criterion with no silent count drop.
- `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` → exit 1,
  "No tests found", 0 tests. The zero-match risk reads as failure, not green.

### Why `tests/Unit/Console/VerifySchemaCommandTest.php` was edited

Running the new Feature test migrates `telemedisin_db_test` via
`RefreshDatabase` (0 → 26 tables), which broke three tests that pinned the
todo-7-era 0-table state: the unfiltered run expected `master_provinsi` and
`users` among the missing (they now exist), the JSON test asserted
`drift_count == discrepancy_count` (the 7 documented extras are now
informational: 58 drift vs 65 total), and the narrow-scope test used
`--tables=master_provinsi` (now exits 0). Updated to the batch-B truth
without weakening: the offender list re-points at still-missing tables
(`pasien`, `booking`, `ulasan_dokter`, `persetujuan_pdp`, `audit_log`),
`missing_table` count 75 → 56 (= 75 − 19 created), JSON pins the
discrepancy−drift decomposition to exactly the 7 registry entries, narrow
scope re-points at `--tables=booking` (still exit 1 naming it). The registry
enforcement test itself (derived from the live migration set) passes
untouched — the contract `documented extra = informational / undocumented
extra = drift (exit 1) / missing registry = exit 2` is intact. Each comment
in the file says what to re-point when todos 9–17 land.

## 6. docs/schema-notes.md additions

New section "Batch-B schema limitations the database cannot enforce (todos 8,
20, 45)" — four entries, each with a one-line justification; no new markdown
table, so the registry parser is unaffected (registry still exactly 7):

1. `user_refresh_tokens.token_hash` has no UNIQUE → full table scan per
   refresh; rotation needs an application-level existence check (model for
   todo 45's idempotency work).
2. `user_refresh_tokens` has no `device_id` → per-device revocation
   impossible; revocation is per user or per token.
3. `user_otp` has no attempt-counter column → brute-force protection is
   application-only in todo 20's rate limiter.
4. `users.kata_sandi_hash` is `NOT NULL` with no OTP-only representation →
   OTP-only signup must generate a random unusable hash.

## 7. Data safety

| Database | Before | After | Verdict |
|---|---|---|---|
| `telemedisin_db` (mine) | 18 tables | 26 tables, all empty | migrated, deliberate |
| `telemedisin_db_test` (mine) | 0 tables | 26 tables, all empty (`users` 0 rows, ledger 22 rows) | migrated by `RefreshDatabase`, deliberate, documented in the new test file |
| `sehatly` (protected) | 10 tables, 5 ledger rows | 10 tables, 5 ledger rows | untouched |
| `db_simprapkl` 26, `gawaiseken` 18, `laravel` 5, `trading_journal` 12, `ukk` 12, `ukk_pengaduan_sekolah` 15, `manajemen-surat` 0 | same | same | untouched, read-only queries only |
| `telemedicine_test.sql` | — | `git diff --exit-code` exits 0 | byte-unchanged |

`php artisan config:clear` ran before and after; `bootstrap/cache/config.php`
never existed during this lane. No process was killed (`mysqld`, PID 22288
`php artisan serve` left alone). All `migrate` invocations targeted
`telemedisin_db` / `telemedisin_db_test` only. No temp file was created
outside the repo (queries ran via `mysql.exe -e`); nothing to delete.

## 8. Adversarial results

- **misleading_success_output** — PROBED. Did not trust `migrate:fresh`
  exit 0: the §3 dump asserts all 48 columns' type/nullability/default/extra
  plus both composite PKs in `KEY_COLUMN_USAGE`. All match; ENUM orders and
  defaults exact; scaffold columns absent.
- **stale_state** — CHECKED. No `bootstrap/cache/config.php` at start or end;
  `config:clear` before and after. Filename order verified in the
  `migrate:fresh` log: `000012` is the lowest of mine, after batch A's
  `000011` and Sanctum's `2026_09_26_*`. Scaffold/2FA/passkeys migrations
  confirmed absent from `database/migrations/` (todo 7's deletions intact).
- **dirty_worktree** — CHECKED. Commit contains only my 12 paths (§9).
  `.omo/plans/sehatly-telemedicine-platform.md` (M),
  `.omo/start-work/` + `.omo/evidence/task-3-sehatly.md` (untracked) are the
  orchestrator's / a prior executor's — left untouched.
- **hung_or_long_commands** — OBSERVED. `migrate:fresh` took ~1.3 s wall
  (longest single migration `master_kelurahan` 141 ms); nothing blocked on
  metadata locks. Every command ran with an explicit timeout; all returned
  observed exit codes. No process killed.
- **repeated_interruptions** — PROBED. `migrate:rollback --step=8` dropped
  exactly the 8 batch-B tables (18 remained), `migrate` re-created all 8,
  and `verify-schema --tables=…` returned to `Discrepancies: 0` — `down()`
  methods are correct and the procedure converges idempotently. (Rollback
  touched only `telemedisin_db`, never `sehatly`.)
- **malformed_input** — N/A: no parser authored. (The verifier's own
  malformed-input handling is todo 6's tested property, untouched.)
- **prompt_injection** — N/A: `telemedicine_test.sql` was read purely as a
  DDL specification. Nothing in it reads as an instruction; no action was
  taken on anything but column/key definitions.
- **cancel_resume** — N/A: no resumable user flow in this batch (todos
  20/45/46 own OTP/refresh-token flows).
- **flaky_tests** — N/A: deterministic. The zero-match filter run proves the
  runner reports "No tests found" as exit 1 rather than a silent pass.

Observation (not acted on): Laravel 13's grammar *does* list an `OnUpdate`
column modifier and `useCurrentOnUpdate()` sets `onUpdate`, so a Blueprint
path for `ON UPDATE CURRENT_TIMESTAMP` may exist — but the binding contract
(`docs/migration-order.md` rule 5) mandates the raw `ALTER`, all 16
both-timestamp tables must stay uniform, and this lane followed the mandate.

## 9. Paths committed (explicit pathspec, no `-A`/`.`)

- `database/migrations/2026_10_01_000012_users_table.php` (new)
- `database/migrations/2026_10_01_000013_roles_table.php` (new)
- `database/migrations/2026_10_01_000014_permissions_table.php` (new)
- `database/migrations/2026_10_01_000015_role_permissions_table.php` (new)
- `database/migrations/2026_10_01_000016_user_roles_table.php` (new)
- `database/migrations/2026_10_01_000017_user_otp_table.php` (new)
- `database/migrations/2026_10_01_000018_user_devices_table.php` (new)
- `database/migrations/2026_10_01_000019_user_refresh_tokens_table.php` (new)
- `docs/schema-notes.md` (batch-B limitation section appended)
- `tests/Feature/UsersTableSchemaTest.php` (new negative-QA test)
- `tests/Unit/Console/VerifySchemaCommandTest.php` (three state pins
  re-pointed to batch-B truth; registry contract untouched)
- `.omo/evidence/task-8-sehatly.md` (this file)

Post-commit checks: `git diff --cached --name-only` empty;
`git show --name-only --format="" HEAD` lists only the 12 paths above.
Final `git status --porcelain` shows nothing of mine.
