# Migration order contract

The **single source of truth** for the whole 75-table schema effort. Every migration
batch in todos 8-17 takes its filename, its table, and its position from this file, which
is what makes the batches safe to author in parallel: two executors working on different
batches cannot collide on a filename, cannot create a table twice, and cannot
out-order each other's foreign keys.

`telemedicine_test.sql` is read-only law. Nothing in this file overrides it — the order
below is **the SQL's own order**, which is already dependency-safe, not a convenient
re-ordering. The order is also Laravel's: `migrate` sorts migrations by filename, so
`2026_10_01_000001` runs before `2026_10_01_000075` runs before the three post-table
migrations, and every `FOREIGN KEY` therefore points at a table that already exists.

## Contract

- **Filename pattern:** `2026_10_01_NNNNNN_<table>_table.php`, where `NNNNNN` is the
  zero-padded row number below (`000001` … `000075`) and `<table>` is the table name
  exactly as the SQL spells it. Three post-table migrations continue the same sequence as
  `2026_10_01_000076` … `000078`.
- **Index = creation order.** Never renumber a row to "fix" an order. If a table must
  move, move the row *and* the migration, in one commit, and re-run
  `php artisan migrate:fresh`.
- **`SQL line` is the `CREATE TABLE` line** in `telemedicine_test.sql` (1-indexed), i.e. the
  first line of the statement, not the closing `) ENGINE=InnoDB;`. All 75 are mechanically
  verified against a `^CREATE TABLE` walk of the file — see
  [Verification](#verification) below.
- **Todo** is the todo that authors that migration. **Model** is the todo that authors the
  Eloquent model. `Resource` / `Controller` name the todo that authors them, or `—` when
  the table is deliberately not exposed.
- **14 tables are module-orphaned** (plan guardrail, `Must NOT have`): migrations and
  Models only, **never** an invented endpoint. They are marked `ORPHAN` below.

## The 75 tables

| # | SQL line | Table | Migration filename | Batch (todo) | Module | Model | Resource | Controller |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 58 | `master_provinsi` | `2026_10_01_000001_master_provinsi_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 2 | 64 | `master_kabupaten_kota` | `2026_10_01_000002_master_kabupaten_kota_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 3 | 72 | `master_kecamatan` | `2026_10_01_000003_master_kecamatan_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 4 | 80 | `master_kelurahan` | `2026_10_01_000004_master_kelurahan_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 5 | 89 | `master_agama` | `2026_10_01_000005_master_agama_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 6 | 94 | `master_golongan_darah` | `2026_10_01_000006_master_golongan_darah_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 7 | 99 | `master_pendidikan` | `2026_10_01_000007_master_pendidikan_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 8 | 104 | `master_status_pernikahan` | `2026_10_01_000008_master_status_pernikahan_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 9 | 109 | `master_hubungan_keluarga` | `2026_10_01_000009_master_hubungan_keluarga_table.php` | A (7) | M1 referensi | 19 | 42 | 42 |
| 10 | 115 | `master_icd10` | `2026_10_01_000010_master_icd10_table.php` | A (7) | M3 diagnosis entry | 19 | 42 | 42 |
| 11 | 122 | `master_icd9cm` | `2026_10_01_000011_master_icd9cm_table.php` | A (7) | M3 diagnosis entry | 19 | 42 | 42 |
| 12 | 132 | `users` | `2026_10_01_000012_users_table.php` | B (8) | M1 auth | 19 | 21 | 20 |
| 13 | 151 | `roles` | `2026_10_01_000013_roles_table.php` | B (8) | M1 RBAC | 19 | — | — |
| 14 | 157 | `permissions` | `2026_10_01_000014_permissions_table.php` | B (8) | M1 RBAC | 19 | — | — |
| 15 | 163 | `role_permissions` | `2026_10_01_000015_role_permissions_table.php` | B (8) | M1 RBAC | 19 | — | — |
| 16 | 171 | `user_roles` | `2026_10_01_000016_user_roles_table.php` | B (8) | M1 RBAC | 19 | — | — |
| 17 | 179 | `user_otp` | `2026_10_01_000017_user_otp_table.php` | B (8) | M1 auth | 19 | — | — |
| 18 | 190 | `user_devices` | `2026_10_01_000018_user_devices_table.php` | B (8) | M1 auth | 19 | 20 | 20 |
| 19 | 204 | `user_refresh_tokens` | `2026_10_01_000019_user_refresh_tokens_table.php` | B (8) | M1 auth | 19 | — | — |
| 20 | 218 | `pasien` | `2026_10_01_000020_pasien_table.php` | C (9) | M1 patient | 19 | 21 | 21 |
| 21 | 259 | `pasien_anggota_keluarga` | `2026_10_01_000021_pasien_anggota_keluarga_table.php` | C (9) | M1 patient | 19 | 21 | 21 |
| 22 | 274 | `pasien_alergi` | `2026_10_01_000022_pasien_alergi_table.php` | C (9) | M1 patient | 19 | 21 | 21 |
| 23 | 286 | `pasien_riwayat_penyakit` | `2026_10_01_000023_pasien_riwayat_penyakit_table.php` | C (9) | M1 patient | 19 | 21 | 21 |
| 24 | 300 | `pasien_imunisasi` | `2026_10_01_000024_pasien_imunisasi_table.php` | C (9) | M1 patient | 19 | 21 | 21 |
| 25 | 312 | `pasien_tanda_vital` | `2026_10_01_000025_pasien_tanda_vital_table.php` | C (9) | M3 consultation | 19 | 33 | 33 |
| 26 | 333 | `master_penjamin` | `2026_10_01_000026_master_penjamin_table.php` | C (9) | **ORPHAN** | 19 | — | — |
| 27 | 340 | `pasien_penjamin` | `2026_10_01_000027_pasien_penjamin_table.php` | C (9) | **ORPHAN** | 19 | — | — |
| 28 | 360 | `faskes` | `2026_10_01_000028_faskes_table.php` | D (10) | M1 directory | 19 | 22 | 22 |
| 29 | 388 | `faskes_layanan` | `2026_10_01_000029_faskes_layanan_table.php` | D (10) | M1 directory | 19 | 22 | 22 |
| 30 | 402 | `master_spesialisasi` | `2026_10_01_000030_master_spesialisasi_table.php` | D (10) | M1 directory | 19 | 42 | 42 |
| 31 | 409 | `dokter` | `2026_10_01_000031_dokter_table.php` | D (10) | M1 directory | 19 | 22 | 22 |
| 32 | 437 | `dokter_spesialisasi` | `2026_10_01_000032_dokter_spesialisasi_table.php` | D (10) | M1 directory | 19 | 22 (embedded) | — |
| 33 | 447 | `dokter_faskes` | `2026_10_01_000033_dokter_faskes_table.php` | D (10) | **ORPHAN** | 19 | — | — |
| 34 | 457 | `dokter_pendidikan` | `2026_10_01_000034_dokter_pendidikan_table.php` | D (10) | M1 directory | 19 | 22 (embedded) | — |
| 35 | 470 | `dokter_jadwal` | `2026_10_01_000035_dokter_jadwal_table.php` | E (11) | M2 schedule | 19 | 26 | 26 |
| 36 | 490 | `dokter_libur` | `2026_10_01_000036_dokter_libur_table.php` | E (11) | M2 schedule | 19 | 26 | 26 |
| 37 | 498 | `booking` | `2026_10_01_000037_booking_table.php` | E (11) | M2 booking | 19 | 27 | 27 |
| 38 | 536 | `konsultasi` | `2026_10_01_000038_konsultasi_table.php` | F (12) | M3 consultation | 19 | 32 | 32 |
| 39 | 563 | `konsultasi_chat` | `2026_10_01_000039_konsultasi_chat_table.php` | F (12) | M3 chat | 19 | 32 | 32 |
| 40 | 581 | `surat_keterangan` | `2026_10_01_000040_surat_keterangan_table.php` | F (12) | M3 referral | 19 | 34 | 34 |
| 41 | 599 | `rujukan` | `2026_10_01_000041_rujukan_table.php` | F (12) | M3 referral | 19 | 34 | 34 |
| 42 | 621 | `rekam_medis` | `2026_10_01_000042_rekam_medis_table.php` | G (13) | M3 record | 19 | 33 | 33 |
| 43 | 657 | `rekam_medis_diagnosa` | `2026_10_01_000043_rekam_medis_diagnosa_table.php` | G (13) | M3 record | 19 | 33 | 33 |
| 44 | 669 | `rekam_medis_tindakan` | `2026_10_01_000044_rekam_medis_tindakan_table.php` | G (13) | M3 record | 19 | 33 | 33 |
| 45 | 681 | `rekam_medis_lampiran` | `2026_10_01_000045_rekam_medis_lampiran_table.php` | G (13) | M3 record | 19 | 33 | 33 |
| 46 | 692 | `rekam_medis_persetujuan` | `2026_10_01_000046_rekam_medis_persetujuan_table.php` | G (13) | M3 record | 19 | 33 | 33 |
| 47 | 708 | `master_obat` | `2026_10_01_000047_master_obat_table.php` | H (14) | M4 pharmacy | 19 | 38 | 38 |
| 48 | 731 | `obat_interaksi` | `2026_10_01_000048_obat_interaksi_table.php` | H (14) | M4 pharmacy | 19 | 38 | — |
| 49 | 742 | `resep` | `2026_10_01_000049_resep_table.php` | H (14) | M4 pharmacy | 19 | 39 | 39 |
| 50 | 767 | `resep_item` | `2026_10_01_000050_resep_item_table.php` | H (14) | M4 pharmacy | 19 | 39 | 39 |
| 51 | 786 | `resep_verifikasi` | `2026_10_01_000051_resep_verifikasi_table.php` | H (14) | M4 pharmacy | 19 | 40 | 40 |
| 52 | 797 | `pesanan_obat` | `2026_10_01_000052_pesanan_obat_table.php` | H (14) | M5 order | 19 | 46 | 46 |
| 53 | 819 | `pesanan_obat_tracking` | `2026_10_01_000053_pesanan_obat_tracking_table.php` | H (14) | M5 order | 19 | 46 | 46 |
| 54 | 829 | `apotek_stok` | `2026_10_01_000054_apotek_stok_table.php` | H (14) | M5 order | 19 | 46 | 46 |
| 55 | 847 | `master_lab_tindakan` | `2026_10_01_000055_master_lab_tindakan_table.php` | I (15) | **ORPHAN** | 19 | — | — |
| 56 | 860 | `master_lab_paket` | `2026_10_01_000056_master_lab_paket_table.php` | I (15) | **ORPHAN** | 19 | — | — |
| 57 | 868 | `lab_paket_item` | `2026_10_01_000057_lab_paket_item_table.php` | I (15) | **ORPHAN** | 19 | — | — |
| 58 | 876 | `lab_permintaan` | `2026_10_01_000058_lab_permintaan_table.php` | I (15) | **ORPHAN** | 19 | — | — |
| 59 | 894 | `lab_permintaan_detail` | `2026_10_01_000059_lab_permintaan_detail_table.php` | I (15) | **ORPHAN** | 19 | — | — |
| 60 | 905 | `lab_hasil` | `2026_10_01_000060_lab_hasil_table.php` | I (15) | **ORPHAN** | 19 | — | — |
| 61 | 925 | `master_metode_pembayaran` | `2026_10_01_000061_master_metode_pembayaran_table.php` | J (16) | M5 payment | 19 | 42 | 42 |
| 62 | 936 | `invoice` | `2026_10_01_000062_invoice_table.php` | J (16) | M5 payment | 19 | 45 | 45 |
| 63 | 958 | `pembayaran` | `2026_10_01_000063_pembayaran_table.php` | J (16) | M5 payment | 19 | 45 | 45 |
| 64 | 975 | `refund` | `2026_10_01_000064_refund_table.php` | J (16) | M5 payment | 19 | 45 | 45 |
| 65 | 985 | `master_promo` | `2026_10_01_000065_master_promo_table.php` | J (16) | M5 payment | 19 | 45 | 45 |
| 66 | 1000 | `promo_redemption` | `2026_10_01_000066_promo_redemption_table.php` | J (16) | M5 payment | 19 | 45 | 45 |
| 67 | 1012 | `klaim_bpjs` | `2026_10_01_000067_klaim_bpjs_table.php` | J (16) | **ORPHAN** | 19 | — | — |
| 68 | 1036 | `notifikasi` | `2026_10_01_000068_notifikasi_table.php` | K (17) | M5 notification | 19 | 47 | 47 |
| 69 | 1050 | `ulasan_dokter` | `2026_10_01_000069_ulasan_dokter_table.php` | K (17) | **ORPHAN** | 19 | — | — |
| 70 | 1068 | `artikel_kategori` | `2026_10_01_000070_artikel_kategori_table.php` | K (17) | **ORPHAN** | 19 | — | — |
| 71 | 1074 | `artikel` | `2026_10_01_000071_artikel_table.php` | K (17) | **ORPHAN** | 19 | — | — |
| 72 | 1093 | `home_care_pesanan` | `2026_10_01_000072_home_care_pesanan_table.php` | K (17) | **ORPHAN** | 19 | — | — |
| 73 | 1118 | `audit_log` | `2026_10_01_000073_audit_log_table.php` | K (17) | M5 compliance | 19 | — | — |
| 74 | 1134 | `persetujuan_pdp` | `2026_10_01_000074_persetujuan_pdp_table.php` | K (17) | M5 compliance | 19 | 47 | 47 |
| 75 | 1147 | `akses_rekam_medis_log` | `2026_10_01_000075_akses_rekam_medis_log_table.php` | K (17) | M5 compliance | 19 | — | — |

Rows 13-19 and 73/75 have `—` for Resource/Controller because **no endpoint in the plan's
scope reads them**: the RBAC tables are read by the `permission:` middleware (todo 4) and
`user_otp` / `user_refresh_tokens` are auth-flow internals, while `audit_log` and
`akses_rekam_medis_log` are written by observers/services (todos 33, 43) and have no
read endpoint in todos 19-49. That is a deliberate scope decision, not an omission.

## Post-table migrations (3 rows)

These run after all 75 tables. They are not tables, so they carry no `CREATE TABLE` line of
their own — the two views are `CREATE OR REPLACE VIEW` and the deferred FK is an `ALTER`.

| # | SQL line | Object | Migration filename | Todo | What it does |
| --- | --- | --- | --- | --- | --- |
| 76 | 1161 | `fk_vital_rm` (deferred FK) | `2026_10_01_000076_add_deferred_foreign_keys_table.php` | 18 | `ALTER TABLE pasien_tanda_vital ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE SET NULL`, plus the deferred `pasien_penjamin.faskes_rujukan_id -> faskes(id)` FK recorded in todo 9. `down()` drops constraints before any table. |
| 77 | 1170 | `v_dokter_katalog` (view) | `2026_10_01_000077_create_v_dokter_katalog_view_table.php` | 18 | Raw `DB::statement('CREATE OR REPLACE VIEW v_dokter_katalog AS ...')` copied verbatim from `:1170-1187` **including** `GROUP_CONCAT(s.nama SEPARATOR ', ')`. MySQL-only, so it cannot be expressed fluently. `public $withinTransaction = false`; `down()` starts with `DROP VIEW IF EXISTS`. |
| 78 | 1190 | `v_pendapatan_bulanan` (view) | `2026_10_01_000078_create_v_pendapatan_bulanan_view_table.php` | 18 | Raw `CREATE OR REPLACE VIEW` from `:1190-1196`, including `DATE_FORMAT(p.dibayar_at, '%Y-%m')`. `public $withinTransaction = false`; `down()` starts with `DROP VIEW IF EXISTS`. |

## Pre-existing migrations that are NOT part of this contract

Every `laravel/…` scaffold migration that existed before this contract was authored. The
Fate column is the authority: a `DELETED` row names a file that is **absent from disk, absent
from `git ls-tree HEAD database/migrations/`, and absent from the `telemedisin_db.migrations`
ledger.** Re-check with those three, not with this table:

```powershell
Get-ChildItem database\migrations | Select-Object -ExpandProperty Name
git ls-tree --name-only HEAD database/migrations/
# then: select migration from telemedisin_db.migrations order by migration
```

| Migration | Tables | Fate |
| --- | --- | --- |
| `0001_01_01_000001_create_cache_table.php` | `cache`, `cache_locks` | **KEPT.** Legitimate extra, registered in `docs/schema-notes.md`. |
| `0001_01_01_000002_create_jobs_table.php` | `jobs`, `job_batches`, `failed_jobs` | **KEPT.** Legitimate extra, registered. |
| `2026_09_26_222801_create_personal_access_tokens_table.php` | `personal_access_tokens` | **KEPT.** Sanctum, published in todo 3, registered. |
| `0001_01_01_000000_create_users_table.php` | `users`, `password_reset_tokens`, `sessions` | **DELETED in todo 7** (plan Appendix A.4): it collides with row 12 `users`, and `password_reset_tokens` / `sessions` are not among the 75. |
| `2025_08_14_170933_add_two_factor_columns_to_users_table.php` | (adds `users.two_factor_*`) | **DELETED in todo 7** (plan Appendix A.4): `->after('password')` cannot resolve because the SQL's `users` has `kata_sandi_hash` and no `password`. |
| `2024_01_01_000000_create_passkeys_table.php` | `passkeys` | **DELETED in todo 7** — the decision plan Appendix A.4 explicitly left to todo 7, and the full reasoning is in `docs/schema-notes.md` ("Scaffold-migration disposition"). **Do not restore it.** It declares `foreignId('user_id')->constrained()->cascadeOnDelete()`, and it sorts at `2024_01_01_000000`, i.e. **before every `2026_10_01_*` row**, while `users` is not created until todo 8 (row 12). Re-adding the file therefore hard-fails `migrate:fresh` with `SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'users'` before a single batch-A table is created, breaking the `migrate:fresh` acceptance criterion of all ten of todos 8-17. `laravel/passkeys` stays in `composer.json` and `config/fortify.php` / `app/Providers/FortifyServiceProvider.php` keep their `passkeys` feature block until todo 30 removes the whole web-auth surface; nothing queries the table at boot, so its absence cannot affect `/api/v1`. |

All **three** surviving scaffolds sort **before** `2026_10_01_000001`, so the order above is
unaffected: `0001_01_01_000001` < `0001_01_01_000002` < `2026_09_26_222801` < `2026_10_01_000001`.
Verified in todo 7's evidence against the migrator's own output and re-verified against disk,
`git ls-tree HEAD` and the `migrations` ledger when the passkeys row was corrected.

The three deleted files also mean the registry in `docs/schema-notes.md` has **seven** entries,
not ten: `passkeys`, `password_reset_tokens` and `sessions` are gone with them.
`tests/Unit/Console/VerifySchemaCommandTest.php` derives that seven from the live migration set
rather than pinning a literal list, so the next scaffold removal fails the test instead of
silently going stale.


## Parity rules that apply to every row above

These are the rules that make the naive implementation wrong. Every batch author must
read them before writing a migration.

1. **`$table->id()` is forbidden.** It emits `BIGINT UNSIGNED`. Use the exact width the SQL
   declares: `$table->unsignedTinyInteger('id')`, `unsignedSmallInteger`, `unsignedMediumInteger`,
   `unsignedInteger`, or `unsignedBigInteger` (18 of the 75 are not `BIGINT`; see the plan's
   "Primary-key widths" constraint). `foreignId()` is equally wrong for any FK pointing at
   one of those 18.
2. **Non-auto-increment primaries.** `master_agama`, `master_golongan_darah`,
   `master_pendidikan`, `master_status_pernikahan` and `master_hubungan_keluarga` declare
   `TINYINT UNSIGNED PRIMARY KEY` with **no** `AUTO_INCREMENT`, and the SQL's seed inserts
   explicit ids. Their migrations must not add
   `->autoIncrement()`; their models need `public $incrementing = false` (todo 19) and their
   seeders must use `DB::table()->insert()` with explicit ids (todo 18) because Eloquent
   `create()` would send a NULL id and fail with MySQL 1366.
3. **Composite-PK join tables have no `id` at all**: `role_permissions` (15),
   `user_roles` (16), `dokter_faskes` (33), `lab_paket_item` (57).
4. **`$table->timestamps()` must never be blanket-applied.** The schema is wildly
   inconsistent, and the plan's own tally of it is wrong. Measured directly off the
   reference DDL with the plan's own parser (`SqlSchemaParser`), the 75 tables split:

   | Shape | Count | Notes |
   | --- | --- | --- |
   | neither `dibuat_at` nor `diubah_at` | **39** | the plan says 28 and lists 29; it wrongly counts `apotek_stok` (which *has* `diubah_at`) and omits **11**: `master_provinsi`, `master_kabupaten_kota`, `master_kecamatan`, `master_kelurahan`, `master_penjamin`, `master_spesialisasi`, `konsultasi_chat`, `master_metode_pembayaran`, `master_promo`, `artikel_kategori`, `persetujuan_pdp`. **Derivation: 29 − 1 + 11 = 39. Todo 19's `$timestamps = false` test must assert 39, not 28.** |
   | `dibuat_at` only | **19** | the plan says 18; `audit_log` (`:1129`) is the one it omits. **Derivation: 18 + 1 = 19.** |
   | `diubah_at` only | **1** | `apotek_stok` (`:837`). No `dibuat_at` at all. |
   | both | **16** | these 16 are the only tables that need the raw `ON UPDATE` `ALTER` in rule 5: `artikel`, `booking`, `dokter`, `dokter_jadwal`, `faskes`, `home_care_pesanan`, `invoice`, `klaim_bpjs`, `konsultasi`, `lab_permintaan`, `master_obat`, `pasien`, `pesanan_obat`, `rekam_medis`, `resep`, `users`. |

   39 + 19 + 1 + 16 = 75, so the split is exhaustive. It was re-measured off the reference
   DDL with `App\Support\Schema\SqlSchemaParser` when this section was corrected, not copied
   from the plan or from todo 7's evidence.

   `konsultasi_chat` (contract row 39, not a count) is in the "neither" group by column name,
   but its created-at column is `terkirim_at` (`:575`), so its model needs
   `const CREATED_AT = 'terkirim_at'`.
5. **For every `dibuat_at`/`diubah_at` pair: `->useCurrent()` *and* a raw follow-up
   `DB::statement('ALTER TABLE x MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')`**
   in the same migration. Laravel 13 has no Blueprint helper for `ON UPDATE CURRENT_TIMESTAMP`.
6. **`$table->enum('col', [...])` in the SQL's exact order** — ENUM order is semantic: it is
   the sort index. Multi-line ENUMs (`booking.status` at `:515-516`, `konsultasi.status` at
   `:542-543`, `konsultasi_chat.tipe_pesan` at `:568-569`, `master_obat.bentuk_sediaan` at
   `:713-714`, `resep.status` at `:751-752`, `persetujuan_pdp.jenis` at `:1137-1138`) are
   read as one unit.
7. **Declare `->index([...])` / `->unique([...])` explicitly** instead of relying on
   `constrained()` shorthand, so the index set is visible in the migration rather than
   implied. Do **not** add an index the SQL does not have: the verifier reports an
   `extra_index` as drift, and several SQL FKs legitimately have no covering index —
   **and see [the InnoDB FK-index caveat](#inno-db-fk-support-index-a-verifier-defect-not-a-migration-bug)
   before you decide that a reported `extra_index` means your migration is wrong.**
8. **`$table->json()`** for JSON columns, **`$table->year()`** for `YEAR`
   (`pasien_riwayat_penyakit.tahun_terdiagnosis`, `:292`), **`$table->softDeletes('dihapus_at')`**
   for the two tables that have it (`users` `:148`, `pasien` `:249`) — it emits exactly
   `timestamp NULL`, which is what the SQL says; a hand-rolled `dateTime()` would emit
   `datetime` and is the parity break.
9. **Never add a `FOREIGN KEY` to a column the SQL leaves bare.** Eight columns look like
   references and have none (see the plan's list). `pasien_penjamin.faskes_rujukan_id`
   (`:346`) is the sharpest case: the DDL declares no `FOREIGN KEY` for it, so the column
   is bare **by contract** — plan appendix A.10 / A.11 settled that, and the old ordering
   argument is dead now that `faskes` exists (batch D, migration 28). Nothing about it is
   deferred and no constraint is owed, so migration `2026_10_01_000076` must **not** add
   one; adding it would be `extra_foreign_key` drift. The only column in the contract with
   a genuinely deferred FK is `pasien_tanda_vital.rekam_medis_id` (`:315`), which the SQL's
   own section `[14]` (`:1161-1163`) really does add — that one is the single row of the
   *Deferred constraints* registry in `docs/schema-notes.md`, and it is not a licence to
   constrain anything else.
10. **Inline `UNIQUE` is compared by semantics, named keys by name.** MySQL names an inline
    `UNIQUE` after its column (`kode`); Laravel names it `master_provinsi_kode_unique`.
    Both are the same constraint, and the verifier knows that. Only a name the SQL wrote
    (`idx_icd10`, `idx_jadwal`, `uq_stok`, …) is compared by name, keyed on
    `(TABLE_NAME, INDEX_NAME)` — `idx_icd10` appears on two different tables
    (`master_icd10:119` and `pasien_riwayat_penyakit:297`), which is legal in MySQL.
11. **`master_icd10` reproduces a redundant index on purpose:** `INDEX idx_icd10 (kode)`
    (`:119`) *alongside* `UNIQUE (kode)`. Both must exist.
12. **Comments are not compared** (they are documentation, and `:538` even stores the
    literal string `'NULL = ...'` inside one), but copy them anyway so the migration is
    readable next to the SQL.

## InnoDB FK-support index: a verifier defect, not a migration bug

**Read this before you "fix" an `extra_index` in your batch.** 80 of the 105 foreign keys in
`telemedicine_test.sql` reference columns that no index in the DDL covers. InnoDB requires an
index on the referencing columns, so MySQL **creates one itself**, named after the column, and
prints it in `SHOW CREATE TABLE`:

```sql
CREATE TABLE master_kabupaten_kota (
  ...
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode` (`kode`),
  KEY `provinsi_id` (`provinsi_id`),          -- <-- created by MySQL, absent from the DDL
  CONSTRAINT `master_kabupaten_kota_ibfk_1` FOREIGN KEY (`provinsi_id`) REFERENCES `master_provinsi` (`id`)
)
```

Importing `telemedicine_test.sql` produces exactly that. So does a correct migration. The
reference model built by `App\Support\Schema\SqlSchemaParser` only records a `PRIMARY KEY`, a
name-bearing `INDEX`/`UNIQUE KEY`, an inline `UNIQUE` and an inline `PRIMARY KEY` — it never
synthesises InnoDB's implicit index — and `SchemaDiffer::diffIndexes()` used to report every
unconsumed live index as `extra_index` **drift**. Consequence **while the defect was open**:
any table with an uncovered FK could not reach `Discrepancies: 0`, no matter how faithfully it
was migrated. That is no longer true — see "ALREADY FIXED" below. The measurement and the
migration guidance are kept because the underlying InnoDB behaviour has not changed.

In batch A this affects exactly three tables — `master_kabupaten_kota`, `master_kecamatan`,
`master_kelurahan` (one each). The other eight batch-A tables have no foreign key, which is
why the plan's own acceptance criterion uses `--tables=master_provinsi,master_agama,master_icd10`
and why the unfiltered run at full parity will carry 80 such entries.

**The correct migration is the one that reproduces the SQL: declare the foreign key and do
not add a covering index of your own.** Omitting the FK to silence the verifier loses a real
constraint (and produces `missing_foreign_key` instead); naming the index explicitly just
changes which name appears. The defect belongs to `SchemaDiffer`, not to a migration, and
is recorded in `docs/schema-notes.md`.

### ALREADY FIXED — commit `27c6ca8`. Do not re-apply it.

The section above originally ended "it is todo 18's to make". **It is not todo 18's any
more.** The fix landed in commit **`27c6ca8`** (`fix(dev): treat InnoDB FK-support indexes as
implied rather than drift`), which is one commit after `c6d0beb`:

> `SchemaDiffer::diffIndexes()` now builds the set of *implied* indexes from the local-column
> lists of the foreign keys that **matched**, and skips any leftover live index whose ordered
> column list is exactly one of them.

That is the minimal correct fix, and it is stricter than a prefix match on purpose: a
deliberate composite index such as `(provinsi_id, nama)`, or a reordered `(b, a)` for a key on
`(a, b)`, is still reported — no engine would ever create either on its own. The rationale is
recorded on the method itself (`app/Support/Schema/SchemaDiffer.php`, `diffIndexes()`).

**Consequences for todos 8-18:**

- **Todo 18 must NOT re-apply it.** Re-deriving a fix that is already in `HEAD` either
  duplicates the behaviour or, worse, "simplifies" it back into the prefix match and
  reintroduces the drift.
- **A batch author must still not "fix" an `extra_index` by hand.** The guidance above is
  unchanged and still load-bearing: if you see an `extra_index` naming a bare FK-support
  index, it is a *different* index (a real prefix mismatch, or an index your migration
  invented). Do not edit your migration to silence it — compare its column list against the
  FK's local columns first.
- **The measurement is retained** and is the calibration reference for re-checking:
  **105 foreign keys, 80 with no covering index** in the reference DDL (batch A's share is 3:
  `master_kabupaten_kota`, `master_kecamatan`, `master_kelurahan`, one each).

Re-measure with `App\Support\Schema\SqlSchemaParser`, which returns exactly 105/80. A naive
index regex reports **0 uncovered**, because it matches the `KEY (...)` tail of
`FOREIGN KEY (...) REFERENCES ...`; that is the trap the number is here to prevent.

## Verification

The line numbers above are not transcribed by hand. Todo 7 proves the match mechanically,
and the check is cheap enough for any batch author to re-run:

```powershell
# The 75 CREATE TABLE lines, in file order
Select-String -Path telemedicine_test.sql -Pattern '^CREATE TABLE' | ForEach-Object { "$($_.LineNumber) $($_.Line -replace '^CREATE TABLE ','' -replace ' \(','')" }

# Row count of the data table in the contract (expect 75)
(Select-String -Path docs/migration-order.md -Pattern '^\| \d+ \| \d+ \| `').Count
```

The authoritative check used in todo 7's evidence compares the three columns
(`#`, `SQL line`, `Table`) of every markdown row against a `^CREATE TABLE` walk of the
file, positionally — a 75/75 match with an empty diff. Re-run it after editing this file;
if a row moves, the filename's `NNNNNN` moves with it or the contract is lying.

## Adding a table later

If the schema genuinely needs a 76th table, it goes at the **end** of the data table
(`2026_10_01_000076_…`), before the deferred-FK row, and this file is updated in the same
commit with the SQL line number it came from. Never insert a row in the middle: a
migration that already ran in someone's dev database is not re-run by `migrate`, so a
renumbered prefix silently produces a half-created schema.
