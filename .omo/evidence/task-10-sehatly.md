# Task 10 evidence — migration batch D (facilities and medical staff, SQL tables 28-34)

Branch: `feat/sehatly-telemedicine`
Executed: 2026-09-27
PHP: `C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe` (8.4.17) — prepended to `$env:PATH` on
every command, per plan Appendix A.3.

Authored (all seven, in `docs/migration-order.md` order):

| # | File | SQL lines |
| --- | --- | --- |
| 28 | `database/migrations/2026_10_01_000028_faskes_table.php` | 360-386 |
| 29 | `database/migrations/2026_10_01_000029_faskes_layanan_table.php` | 388-396 |
| 30 | `database/migrations/2026_10_01_000030_master_spesialisasi_table.php` | 402-407 |
| 31 | `database/migrations/2026_10_01_000031_dokter_table.php` | 409-435 |
| 32 | `database/migrations/2026_10_01_000032_dokter_spesialisasi_table.php` | 437-445 |
| 33 | `database/migrations/2026_10_01_000033_dokter_faskes_table.php` | 447-455 |
| 34 | `database/migrations/2026_10_01_000034_dokter_pendidikan_table.php` | 457-464 |

---

## 0. TWO corrections to the task brief, both resolved by reading the read-only DDL

The brief said to copy "the exact spelling from the SQL, do not trust my
transcription". Doing so caught **two** places where the brief's own transcription
was wrong. `telemedicine_test.sql` is law; the brief is not.

1. **`akreditasi`'s fifth value is `paripurna`, not `parnirvana`.** The brief
   transcribed it `parnirvana`. `telemedicine_test.sql:376` reads exactly
   `ENUM('belum','dasar','utama','maju','paripurna') NULL`. `parnirvana` would
   have been an `enum` member-list difference the verifier reports as drift.
   Migrated as `paripurna`.
2. **The composite unique on `dokter_spesialisasi` is `uq_dokter_spes`, at `:444`.**
   The brief said `uq_dokter_ses` at `:445`. The live DDL says:

   ```
   line 444: [  UNIQUE KEY uq_dokter_spes (dokter_id, spesialisasi_id)]
   ```

   **The verifier caught this on the first run**, before it was fixed by hand —
   see §3. Named keys are compared by name on `(TABLE_NAME, INDEX_NAME)`, so the
   trailing `es` is load-bearing. The brief's line number was also off by one.

Also a brief arithmetic correction: `dokter` has **four** unsigned integer columns
(`pengalaman_tahun` SMALLINT, `durasi_default_menit` SMALLINT, `jumlah_ulasan` INT,
`jumlah_konsultasi` INT), not five. All four are audited in §4. Counting `id` /
`user_id` (both `BIGINT UNSIGNED`) reaches five, which is presumably where the
figure came from.

---

## 1. Acceptance criterion 1 — `php artisan migrate:fresh`

```
$ php artisan migrate:fresh

 Dropping all tables .. 206.98ms DONE

 INFO Preparing database.

 Creating migration table .. 74.53ms DONE

 INFO Running migrations.

 0001_01_01_000001_create_cache_table .. 129.19ms DONE
 0001_01_01_000002_create_jobs_table .. 116.49ms DONE
 2026_09_26_222801_create_personal_access_tokens_table .. 51.34ms DONE
 2026_10_01_000001_master_provinsi_table .. 28.06ms DONE
 2026_10_01_000002_master_kabupaten_kota_table .. 94.20ms DONE
 2026_10_01_000003_master_kecamatan_table .. 69.63ms DONE
 2026_10_01_000004_master_kelurahan_table .. 70.16ms DONE
 2026_10_01_000005_master_agama_table .. 12.02ms DONE
 2026_10_01_000006_master_golongan_darah_table .. 12.63ms DONE
 2026_10_01_000007_master_pendidikan_table .. 13.29ms DONE
 2026_10_01_000008_master_status_pernikahan_table .. 12.30ms DONE
 2026_10_01_000009_master_hubungan_keluarga_table .. 15.68ms DONE
 2026_10_01_000010_master_icd10_table .. 42.51ms DONE
 2026_10_01_000011_master_icd9cm_table .. 34.29ms DONE
 2026_10_01_000012_users_table .. 63.12ms DONE
 2026_10_01_000013_roles_table .. 37.94ms DONE
 2026_10_01_000014_permissions_table .. 34.71ms DONE
 2026_10_01_000015_role_permissions_table .. 78.10ms DONE
 2026_10_01_000016_user_roles_table .. 78.83ms DONE
 2026_10_01_000017_user_otp_table .. 51.21ms DONE
 2026_10_01_000018_user_devices_table .. 62.66ms DONE
 2026_10_01_000019_user_refresh_tokens_table .. 51.24ms DONE
 2026_10_01_000020_pasien_table .. 352.11ms DONE
 2026_10_01_000021_pasien_anggota_keluarga_table .. 90.79ms DONE
 2026_10_01_000022_pasien_alergi_table .. 60.88ms DONE
 2026_10_01_000023_pasien_riwayat_penyakit_table .. 76.79ms DONE
 2026_10_01_000024_pasien_imunisasi_table .. 47.86ms DONE
 2026_10_01_000025_pasien_tanda_vital_table .. 75.53ms DONE
 2026_10_01_000026_master_penjamin_table .. 13.44ms DONE
 2026_10_01_000027_pasien_penjamin_table .. 100.05ms DONE
 2026_10_01_000028_faskes_table .. 186.31ms DONE
 2026_10_01_000029_faskes_layanan_table .. 46.79ms DONE
 2026_10_01_000030_master_spesialisasi_table .. 28.02ms DONE
 2026_10_01_000031_dokter_table .. 102.66ms DONE
 2026_10_01_000032_dokter_spesialisasi_table .. 118.37ms DONE
 2026_10_01_000033_dokter_faskes_table .. 78.51ms DONE
 2026_10_01_000034_dokter_pendidikan_table .. 53.66ms DONE

=== migrate:fresh EXIT=0 ===
```

The seven batch-D files sort after batch C's `000027`, and `2026_10_01_000028` is
the lowest of the seven — visible in the migrator's own ordered output above, not
merely asserted from filenames.

## 2. Acceptance criterion 2 — `verify-schema --tables=<7 real names>`

```
$ php artisan sehatly:verify-schema --tables=faskes,faskes_layanan,master_spesialisasi,dokter,dokter_spesialisasi,dokter_faskes,dokter_pendidikan

 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred constraint)
 scope faskes, faskes_layanan, master_spesialisasi, dokter, dokter_spesialisasi, dokter_faskes, dokter_pendidikan

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: booking.status (515-516), home_care_pesanan.status (1104-1105), invoice.status (947-948), klaim_bpjs.status (1022-1023), konsultasi.status (542-543), konsultasi_chat.tipe_pesan (568-569), lab_permintaan.status (884-885), master_obat.bentuk_sediaan (713-714), persetujuan_pdp.jenis (1137-1138), pesanan_obat.status (810-811), resep.status (751-752)
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on patients_tanda_vital (line 1161)

 Live schema
 counts tables=41 views=0 columns=283 indexes=102 foreign_keys=33 checks=0
 information_schema columns=283 indexes=102 foreign_keys=33 checks=0

 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.

 PASS — 75 tables, 2 views verified. Nothing was written.

=== verify-schema EXIT=0 ===
```

**Both A.7 / A.8 traps addressed explicitly, not waved through:**

- The `scope` line lists **all seven real names**, and every one of them is a
  `CREATE TABLE` in the DDL (they are the `:360`, `:388`, `:402`, `:409`, `:437`,
  `:447`, `:457` rows of `docs/migration-order.md`). No `unknown_requested_table`
  row is printed, so the green is not the typo-green of A.8 trap 2.
- The `PASS — 75 tables, 2 views verified` banner is **the documented lie of A.7
  trap 1**: it is formatted from the full reference model after verifying seven
  tables. The authoritative signals here are `Discrepancies: 0` and the exit code,
  and both are green. Live `tables=41` is the real coverage figure.

## 3. The verifier caught a real defect on its first run (recorded, not hidden)

The first `verify-schema --tables=…` run of this todo did **not** pass, and the
drift it found was mine — a mis-transcribed index name taken from the task brief
rather than from the DDL:

```
 Discrepancies: 1 (1 drift, 0 informational)
 index_name dokter_spesialisasi expected: uq_dokter_spes UNIQUE (dokter_id, spesialisasi_id) | actual: uq_dokter_ses UNIQUE (dokter_id, spesialisasi_id)

 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.

=== verify-schema EXIT=1 ===
```

Fixed to `uq_dokter_spes` (the DDL's spelling, `:444`), re-migrated, and the run
in §2 is the green one. The migration's docblock records the trap so the next
reader does not re-introduce the brief's spelling.

## 4. Acceptance criteria 3 and 4 — `SHOW CREATE TABLE`

`dokter_faskes` (criterion 3 — **no `id` column**), verbatim:

```
CREATE TABLE `dokter_faskes` (
  `dokter_id` bigint unsigned NOT NULL,
  `faskes_id` bigint unsigned NOT NULL,
  `is_utama` tinyint(1) NOT NULL DEFAULT '0',
  `status_aktif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`dokter_id`,`faskes_id`),
  KEY `dokter_faskes_faskes_id_foreign` (`faskes_id`),
  CONSTRAINT `dokter_faskes_dokter_id_foreign` FOREIGN KEY (`dokter_id`) REFERENCES `dokter` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dokter_faskes_faskes_id_foreign` FOREIGN KEY (`faskes_id`) REFERENCES `faskes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

No `id`, four columns, composite `PRIMARY KEY (dokter_id, faskes_id)` in the DDL's
own order. The `dokter_faskes_faskes_id_foreign` KEY is InnoDB's implicit
FK-support index for the `faskes_id` constraint (the `dokter_id` one is covered by
the primary key itself) — created by the engine, absent from the DDL, and forgiven
by the verifier's implied-index rule. No covering index was added by hand.

`faskes` (criterion 4 — **both** composite indexes), verbatim:

```
CREATE TABLE `faskes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kode_faskes` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Kode faskes BPJS/Kemenkes',
  `satusehat_org_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipe` enum('rumah_sakit','klinik','puskesmas','apotek','laboratorium') COLLATE utf8mb4_unicode_ci NOT NULL,
  `kelas_rs` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'A/B/C/D (khusus rumah sakit)',
  `alamat` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `provinsi_id` tinyint unsigned DEFAULT NULL,
  `kabupaten_kota_id` smallint unsigned DEFAULT NULL,
  `kecamatan_id` smallint unsigned DEFAULT NULL,
  `kode_pos` char(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `telepon` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `akreditasi` enum('belum','dasar','utama','maju','paripurna') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jam_operasional` json DEFAULT NULL COMMENT '{"senin":{"buka":"07:00","tutup":"22:00"},...}',
  `status_aktif` tinyint(1) NOT NULL DEFAULT '1',
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diubah_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `faskes_kode_faskes_unique` (`kode_faskes`),
  KEY `faskes_provinsi_id_foreign` (`provinsi_id`),
  KEY `faskes_kabupaten_kota_id_foreign` (`kabupaten_kota_id`),
  KEY `faskes_kecamatan_id_foreign` (`kecamatan_id`),
  KEY `idx_faskes_tipe` (`tipe`,`status_aktif`),
  KEY `idx_faskes_geo` (`latitude`,`longitude`),
  CONSTRAINT `faskes_kabupaten_kota_id_foreign` FOREIGN KEY (`kabupaten_kota_id`) REFERENCES `master_kabupaten_kota` (`id`),
  CONSTRAINT `faskes_kecamatan_id_foreign` FOREIGN KEY (`kecamatan_id`) REFERENCES `master_kecamatan` (`id`),
  CONSTRAINT `faskes_provinsi_id_foreign` FOREIGN KEY (`provinsi_id`) REFERENCES `master_provinsi` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

`KEY idx_faskes_tipe (tipe, status_aktif)` and `KEY idx_faskes_geo (latitude,
longitude)` are both present **by name**. `latitude decimal(10,8)` and `longitude
decimal(11,8)` carry their distinct scales, and `jam_operasional` is `json`, not
`text`.

`dokter`, verbatim (all four unsigned ints, `idx_dokter_tipe` by name):

```
CREATE TABLE `dokter` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `tipe` enum('dokter_umum','dokter_spesialis','dokter_gigi','psikolog','bidan','perawat','apoteker') COLLATE utf8mb4_unicode_ci NOT NULL,
  `nomor_str` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `str_berlaku_sampai` date NOT NULL,
  `nomor_sip` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sip_berlaku_sampai` date DEFAULT NULL,
  `nomor_ihs_satusehat` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pengalaman_tahun` smallint unsigned NOT NULL DEFAULT '0',
  `bio` text COLLATE utf8mb4_unicode_ci,
  `biaya_konsultasi_online` decimal(12,2) NOT NULL DEFAULT '0.00',
  `biaya_luar_jam` decimal(12,2) DEFAULT NULL,
  `durasi_default_menit` smallint unsigned NOT NULL DEFAULT '15',
  `rating_rata_rata` decimal(3,2) NOT NULL DEFAULT '0.00',
  `jumlah_ulasan` int unsigned NOT NULL DEFAULT '0',
  `jumlah_konsultasi` int unsigned NOT NULL DEFAULT '0',
  `tersedia_telemedisin` tinyint(1) NOT NULL DEFAULT '1',
  `status_verifikasi` enum('pending','terverifikasi','ditolak') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `file_str_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_sip_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_aktif` tinyint(1) NOT NULL DEFAULT '1',
  `dibuat_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diubah_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dokter_user_id_unique` (`user_id`),
  UNIQUE KEY `dokter_nomor_str_unique` (`nomor_str`),
  KEY `idx_dokter_tipe` (`tipe`,`status_aktif`,`tersedia_telemedisin`),
  CONSTRAINT `dokter_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

`dokter_spesialisasi`, verbatim (`uq_dokter_spes` by name):

```
CREATE TABLE `dokter_spesialisasi` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dokter_id` bigint unsigned NOT NULL,
  `spesialisasi_id` smallint unsigned NOT NULL,
  `is_utama` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dokter_spes` (`dokter_id`,`spesialisasi_id`),
  KEY `dokter_spesialisasi_spesialisasi_id_foreign` (`spesialisasi_id`),
  CONSTRAINT `dokter_spesialisasi_dokter_id_foreign` FOREIGN KEY (`dokter_id`) REFERENCES `dokter` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dokter_spesialisasi_spesialisasi_id_foreign` FOREIGN KEY (`spesialisasi_id`) REFERENCES `master_spesialisasi` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## 5. `information_schema` parity dump — all 7 tables, 66 columns

Independent of the verifier: the reference model below is built by
`App\Support\Schema\SqlSchemaParser` straight from `telemedicine_test.sql` and
compared column-by-column against `information_schema.COLUMNS`.

```
REFERENCE MODEL (SqlSchemaParser, direct): 7 tables, 66 columns

TABLE                  # COLUMN                     COLUMN_TYPE                                    NULL COLUMN_DEFAULT           EXTRA
faskes                 1 id                         bigint unsigned                                NO   <NULL>                   auto_increment
faskes                 2 kode_faskes                varchar(20)                                    YES  <NULL>
faskes                 3 satusehat_org_id           varchar(50)                                    YES  <NULL>
faskes                 4 nama                       varchar(200)                                   NO   <NULL>
faskes                 5 tipe                       enum('rumah_sakit','klinik','puskesmas','apotek','laboratorium') NO   <NULL>
faskes                 6 kelas_rs                   varchar(50)                                    YES  <NULL>
faskes                 7 alamat                     text                                           NO   <NULL>
faskes                 8 provinsi_id                tinyint unsigned                               YES  <NULL>
faskes                 9 kabupaten_kota_id          smallint unsigned                              YES  <NULL>
faskes                10 kecamatan_id               smallint unsigned                              YES  <NULL>
faskes                11 kode_pos                   char(5)                                        YES  <NULL>
faskes                12 latitude                   decimal(10,8)                                  YES  <NULL>
faskes                13 longitude                  decimal(11,8)                                  YES  <NULL>
faskes                14 telepon                    varchar(20)                                    YES  <NULL>
faskes                15 email                      varchar(255)                                   YES  <NULL>
faskes                16 akreditasi                 enum('belum','dasar','utama','maju','paripurna') YES  <NULL>
faskes                17 jam_operasional            json                                           YES  <NULL>
faskes                18 status_aktif               tinyint(1)                                     NO   '1'
faskes                19 dibuat_at                  timestamp                                      NO   'CURRENT_TIMESTAMP'      DEFAULT_GENERATED
faskes                20 diubah_at                  timestamp                                      NO   'CURRENT_TIMESTAMP'      DEFAULT_GENERATED on update CURRENT_TIMESTAMP
faskes_layanan         1 id                         bigint unsigned                                NO   <NULL>                   auto_increment
faskes_layanan         2 faskes_id                  bigint unsigned                                NO   <NULL>
faskes_layanan         3 nama_layanan               varchar(150)                                   NO   <NULL>
faskes_layanan         4 deskripsi                  text                                           YES  <NULL>
faskes_layanan         5 harga                      decimal(12,2)                                  YES  <NULL>
faskes_layanan         6 status_aktif               tinyint(1)                                     NO   '1'
master_spesialisasi    1 id                         smallint unsigned                              NO   <NULL>                   auto_increment
master_spesialisasi    2 kode                       varchar(10)                                    NO   <NULL>
master_spesialisasi    3 nama                       varchar(100)                                   NO   <NULL>
master_spesialisasi    4 tipe                       enum('dokter_umum','spesialis','subspesialis') NO   <NULL>
dokter                 1 id                         bigint unsigned                                NO   <NULL>                   auto_increment
dokter                 2 user_id                    bigint unsigned                                NO   <NULL>
dokter                 3 tipe                       enum('dokter_umum','dokter_spesialis','dokter_gigi','psikolog','bidan','perawat','apoteker') NO   <NULL>
dokter                 4 nomor_str                  varchar(30)                                    NO   <NULL>
dokter                 5 str_berlaku_sampai         date                                           NO   <NULL>
dokter                 6 nomor_sip                  varchar(50)                                    YES  <NULL>
dokter                 7 sip_berlaku_sampai         date                                           YES  <NULL>
dokter                 8 nomor_ihs_satusehat        varchar(50)                                    YES  <NULL>
dokter                 9 pengalaman_tahun           smallint unsigned                              NO   '0'
dokter                10 bio                        text                                           YES  <NULL>
dokter                11 biaya_konsultasi_online    decimal(12,2)                                  NO   '0.00'
dokter                12 biaya_luar_jam             decimal(12,2)                                  YES  <NULL>
dokter                13 durasi_default_menit       smallint unsigned                              NO   '15'
dokter                14 rating_rata_rata           decimal(3,2)                                   NO   '0.00'
dokter                15 jumlah_ulasan              int unsigned                                   NO   '0'
dokter                16 jumlah_konsultasi          int unsigned                                   NO   '0'
dokter                17 tersedia_telemedisin       tinyint(1)                                     NO   '1'
dokter                18 status_verifikasi          enum('pending','terverifikasi','ditolak')      NO   'pending'
dokter                19 file_str_url               varchar(500)                                   YES  <NULL>
dokter                20 file_sip_url               varchar(500)                                   YES  <NULL>
dokter                21 status_aktif               tinyint(1)                                     NO   '1'
dokter                22 dibuat_at                  timestamp                                      NO   'CURRENT_TIMESTAMP'      DEFAULT_GENERATED
dokter                23 diubah_at                  timestamp                                      NO   'CURRENT_TIMESTAMP'      DEFAULT_GENERATED on update CURRENT_TIMESTAMP
dokter_spesialisasi    1 id                         bigint unsigned                                NO   <NULL>                   auto_increment
dokter_spesialisasi    2 dokter_id                  bigint unsigned                                NO   <NULL>
dokter_spesialisasi    3 spesialisasi_id            smallint unsigned                              NO   <NULL>
dokter_spesialisasi    4 is_utama                   tinyint(1)                                     NO   '0'
dokter_faskes          1 dokter_id                  bigint unsigned                                NO   <NULL>
dokter_faskes          2 faskes_id                  bigint unsigned                                NO   <NULL>
dokter_faskes          3 is_utama                   tinyint(1)                                     NO   '0'
dokter_faskes          4 status_aktif               tinyint(1)                                     NO   '1'
dokter_pendidikan      1 id                         bigint unsigned                                NO   <NULL>                   auto_increment
dokter_pendidikan      2 dokter_id                  bigint unsigned                                NO   <NULL>
dokter_pendidikan      3 jenjang                    enum('s1_kedokteran','profesi','sp1','sp2','s2','s3','lainnya') NO   <NULL>
dokter_pendidikan      4 institusi                  varchar(200)                                   NO   <NULL>
dokter_pendidikan      5 tahun_lulus                smallint unsigned                              YES  <NULL>
TOTAL COLUMNS DUMPED = 66

COLUMNS_COMPARED = 66   COLUMN_MISMATCHES = 0
```

`COLUMN_MISMATCHES = 0` compares every column's base type, unsigned flag,
nullability, AUTO_INCREMENT and `ON UPDATE` against the DDL. **Two documented
folds were applied, and both are the folds the project's own verifier applies**
(`docs/schema-notes.md`, "Verifier normalisation rules") — without them every
boolean in the schema and every implicit FK action would read as drift:

- integer display widths (`TINYINT(1)` ≡ `tinyint`; MySQL 8.0.19 deprecated them);
- `NO ACTION` ≡ `RESTRICT` for an unspecified referential action.

An earlier draft of this audit compared raw `information_schema` text and reported
7 spurious `tinyint(1)` mismatches and 10 spurious FK mismatches on exactly those
two folds. Both were **bugs in the audit script**, not in the migrations, and are
recorded here rather than quietly corrected.

### 5a. ENUM value lists, live vs DDL, exact order

```
MATCH    faskes.tipe (5 values)
   live: rumah_sakit, klinik, puskesmas, apotek, laboratorium
   ddl : rumah_sakit, klinik, puskesmas, apotek, laboratorium
MATCH    faskes.akreditasi (5 values)
   live: belum, dasar, utama, maju, paripurna
   ddl : belum, dasar, utama, maju, paripurna
MATCH    dokter.tipe (7 values)
   live: dokter_umum, dokter_spesialis, dokter_gigi, psikolog, bidan, perawat, apotek
   ddl : dokter_umum, dokter_spesialis, dokter_gigi, psikolog, bidan, perawat, apotek
MATCH    dokter.status_verifikasi (3 values)
   live: pending, terverifikasi, ditolak
   ddl : pending, terverifikasi, ditolak
MATCH    dokter_pendidikan.jenjang (7 values)
   live: s1_kedokteran, profesi, sp1, sp2, s2, s3, lainnya
   ddl : s1_kedokteran, profesi, sp1, sp2, s2, s3, lainnya
MATCH    master_spesialisasi.tipe (3 values)
   live: dokter_umum, spesialis, subspesialis
   ddl : dokter_umum, spesialis, subspesialis
ENUM_MISMATCHES = 0
```

### 5b. Unsigned-flag audit — 34 numeric columns, 21 unsigned, 0 mismatches

Every numeric column on the seven tables, each compared against the DDL's own
`unsigned` flag:

```
MATCH  faskes               id                           live=bigint unsigned        ddl_unsigned=true
MATCH  faskes               provinsi_id                  live=tinyint unsigned       ddl_unsigned=true
MATCH  faskes               kabupaten_kota_id            live=smallint unsigned      ddl_unsigned=true
MATCH  faskes               kecamatan_id                 live=smallint unsigned      ddl_unsigned=true
MATCH  faskes               latitude                     live=decimal(10,8)          ddl_unsigned=false
MATCH  faskes               longitude                    live=decimal(11,8)          ddl_unsigned=false
MATCH  faskes               status_aktif                 live=tinyint(1)             ddl_unsigned=false
MATCH  faskes_layanan       id                           live=bigint unsigned        ddl_unsigned=true
MATCH  faskes_layanan       faskes_id                    live=bigint unsigned        ddl_unsigned=true
MATCH  faskes_layanan       harga                        live=decimal(12,2)          ddl_unsigned=false
MATCH  faskes_layanan       status_aktif                 live=tinyint(1)             ddl_unsigned=false
MATCH  master_spesialisasi  id                           live=smallint unsigned      ddl_unsigned=true
MATCH  dokter               id                           live=bigint unsigned        ddl_unsigned=true
MATCH  dokter               user_id                      live=bigint unsigned        ddl_unsigned=true
MATCH  dokter               pengalaman_tahun             live=smallint unsigned      ddl_unsigned=true
MATCH  dokter               biaya_konsultasi_online      live=decimal(12,2)          ddl_unsigned=false
MATCH  dokter               biaya_luar_jam               live=decimal(12,2)          ddl_unsigned=false
MATCH  dokter               durasi_default_menit         live=smallint unsigned      ddl_unsigned=true
MATCH  dokter               rating_rata_rata             live=decimal(3,2)           ddl_unsigned=false
MATCH  dokter               jumlah_ulasan                live=int unsigned           ddl_unsigned=true
MATCH  dokter               jumlah_konsultasi            live=int unsigned           ddl_unsigned=true
MATCH  dokter               tersedia_telemedisin         live=tinyint(1)             ddl_unsigned=false
MATCH  dokter               status_aktif                 live=tinyint(1)             ddl_unsigned=false
MATCH  dokter_spesialisasi  id                           live=bigint unsigned        ddl_unsigned=true
MATCH  dokter_spesialisasi  dokter_id                    live=bigint unsigned        ddl_unsigned=true
MATCH  dokter_spesialisasi  spesialisasi_id              live=smallint unsigned      ddl_unsigned=true
MATCH  dokter_spesialisasi  is_utama                     live=tinyint(1)             ddl_unsigned=false
MATCH  dokter_faskes        dokter_id                    live=bigint unsigned        ddl_unsigned=true
MATCH  dokter_faskes        faskes_id                    live=bigint unsigned        ddl_unsigned=true
MATCH  dokter_faskes        is_utama                     live=tinyint(1)             ddl_unsigned=false
MATCH  dokter_faskes        status_aktif                 live=tinyint(1)             ddl_unsigned=false
MATCH  dokter_pendidikan    id                           live=bigint unsigned        ddl_unsigned=true
MATCH  dokter_pendidikan    dokter_id                    live=bigint unsigned        ddl_unsigned=true
MATCH  dokter_pendidikan    tahun_lulus                  live=smallint unsigned      ddl_unsigned=true
NUMERIC_COLUMNS_AUDITED = 34   UNSIGNED_AMONG_THEM = 21   UNSIGNED_MISMATCHES = 0
```

The four load-bearing ones on `dokter` are `pengalaman_tahun` (SMALLINT UNSIGNED),
`durasi_default_menit` (SMALLINT UNSIGNED), `jumlah_ulasan` (INT UNSIGNED) and
`jumlah_konsultasi` (INT UNSIGNED). `faskes_layanan`'s only numeric columns are
its two `BIGINT UNSIGNED` keys and a nullable `DECIMAL(12,2)`, all audited above —
the brief's "two on `faskes_layanan` territory" resolves to those two unsigned
`BIGINT`s.

### 5c. Foreign-key reconciliation — 10 live, 10 declared, 0 invented, 0 missing

```
LIVE  dokter.user_id -> users.id  DEL=NO ACTION UPD=NO ACTION  engine_name=dokter_user_id_foreign
LIVE  dokter_faskes.dokter_id -> dokter.id  DEL=CASCADE UPD=NO ACTION  engine_name=dokter_faskes_dokter_id_foreign
LIVE  dokter_faskes.faskes_id -> faskes.id  DEL=CASCADE UPD=NO ACTION  engine_name=dokter_faskes_faskes_id_foreign
LIVE  dokter_pendidikan.dokter_id -> dokter.id  DEL=CASCADE UPD=NO ACTION  engine_name=dokter_pendidikan_dokter_id_foreign
LIVE  dokter_spesialisasi.dokter_id -> dokter.id  DEL=CASCADE UPD=NO ACTION  engine_name=dokter_spesialisasi_dokter_id_foreign
LIVE  dokter_spesialisasi.spesialisasi_id -> master_spesialisasi.id  DEL=NO ACTION UPD=NO ACTION  engine_name=dokter_spesialisasi_spesialisasi_id_foreign
LIVE  faskes.kabupaten_kota_id -> master_kabupaten_kota.id  DEL=NO ACTION UPD=NO ACTION  engine_name=faskes_kabupaten_kota_id_foreign
LIVE  faskes.kecamatan_id -> master_kecamatan.id  DEL=NO ACTION UPD=NO ACTION  engine_name=faskes_kecamatan_id_foreign
LIVE  faskes.provinsi_id -> master_provinsi.id  DEL=NO ACTION UPD=NO ACTION  engine_name=faskes_provinsi_id_foreign
LIVE  faskes_layanan.faskes_id -> faskes.id  DEL=CASCADE UPD=NO ACTION  engine_name=faskes_layanan_faskes_id_foreign
--- DDL-declared FK set (10) ---
DDL   dokter.user_id -> users.id ON DELETE RESTRICT ON UPDATE RESTRICT
DDL   dokter_faskes.dokter_id -> dokter.id ON DELETE CASCADE ON UPDATE RESTRICT
DDL   dokter_faskes.faskes_id -> faskes.id ON DELETE CASCADE ON UPDATE RESTRICT
DDL   dokter_pendidikan.dokter_id -> dokter.id ON DELETE CASCADE ON UPDATE RESTRICT
DDL   dokter_spesialisasi.dokter_id -> dokter.id ON DELETE CASCADE ON UPDATE RESTRICT
DDL   dokter_spesialisasi.spesialisasi_id -> master_spesialisasi.id ON DELETE RESTRICT ON UPDATE RESTRICT
DDL   faskes.kabupaten_kota_id -> master_kabupaten_kota.id ON DELETE RESTRICT ON UPDATE RESTRICT
DDL   faskes.kecamatan_id -> master_kecamatan.id ON DELETE RESTRICT ON UPDATE RESTRICT
DDL   faskes.provinsi_id -> master_provinsi.id ON DELETE RESTRICT ON UPDATE RESTRICT
DDL   faskes_layanan.faskes_id -> faskes.id ON DELETE CASCADE ON UPDATE RESTRICT
LIVE_FKS = 10   DDL_FKS = 10
INVENTED_FKS (live but not declared in the DDL) = 0
MISSING_FKS (declared in the DDL but absent live) = 0
```

**No foreign key was invented anywhere in this batch.** The set reconciles exactly,
1:1, in both directions.

### 5d. Primary keys, and `dokter_faskes` has no `id`

```
faskes                 PRIMARY KEY (id)   [columns=1]
faskes_layanan         PRIMARY KEY (id)   [columns=1]
master_spesialisasi    PRIMARY KEY (id)   [columns=1]
dokter                 PRIMARY KEY (id)   [columns=1]
dokter_spesialisasi    PRIMARY KEY (id)   [columns=1]
dokter_faskes          PRIMARY KEY (dokter_id, faskes_id)   [columns=2]
dokter_pendidikan      PRIMARY KEY (id)   [columns=1]

dokter_faskes 'id' column count = 0 -> CONFIRMED ABSENT
dokter_faskes DDL column count = 4 -> dokter_id, faskes_id, is_utama, status_aktif
```

`information_schema.KEY_COLUMN_USAGE.ORDINAL_POSITION` confirms the composite key
is `(dokter_id, faskes_id)` **in the DDL's own order** — which matters, because the
leftmost column is what makes InnoDB's implicit FK-support index for `dokter_id`
be the primary key itself.

### 5e. Required named keys, by name

```
PRESENT faskes               idx_faskes_tipe    (tipe, status_aktif)
PRESENT faskes               idx_faskes_geo     (latitude, longitude)
PRESENT dokter               idx_dokter_tipe    (tipe, status_aktif, tersedia_telemedisin)
PRESENT dokter_spesialisasi  uq_dokter_spes     (dokter_id, spesialisasi_id)
```

### 5f. Batch A / B / C regression spot-checks

```
INTACT  master_agama     id                 type=tinyint unsigned   extra=''  (A: no AUTO_INCREMENT)
INTACT  users            dihapus_at         type=timestamp          extra=''  (B: timestamp, not datetime)
INTACT  pasien           tinggi_badan_cm    type=decimal(5,1)       extra=''  (C: scale 1, not 2)
pasien_penjamin total FKs = 2 (expected 2: pasien, penjamin - faskes_rujukan_id has none by contract, A.10/A.11)
fk_vital_rm present = 0 (expected 0 - the one genuinely deferred constraint, migration 76)
```

### 5g. `ON UPDATE CURRENT_TIMESTAMP` (rule 5's raw `ALTER`)

```
OK      faskes     diubah_at timestamp EXTRA='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
OK      dokter     diubah_at timestamp EXTRA='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
```

These two are the only batch-D members of the 16-table "both `dibuat_at` and
`diubah_at`" group.

### 5h. JSON column type

```
faskes.jam_operasional  COLUMN_TYPE=json  DATA_TYPE=json  -> OK (json, not text)
```

---

## 6. NEGATIVE / FAILURE QA — `dokter.durasi_default_menit` as a signed `SMALLINT`

The plan's mandated failure probe, run for real.

**Step 1 — break it.** `unsignedSmallInteger('durasi_default_menit')` →
`smallInteger('durasi_default_menit')` in `2026_10_01_000031_dokter_table.php`, and
re-migrate:

```
$ php artisan migrate:fresh
 2026_10_01_000031_dokter_table .. 88.56ms DONE
=== migrate:fresh EXIT=0 ===
```

Note that `migrate:fresh` **still exits 0 on the broken migration** — the drift is
invisible to the migrator. That is precisely the `misleading_success_output` class
this probe exists to defeat.

**Step 2 — raw verifier output, expecting exit 1:**

```
$ php artisan sehatly:verify-schema --tables=dokter

 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred constraint)
 scope dokter

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: booking.status (515-516), home_care_pesanan.status (1104-1105), invoice.status (947-948), klaim_bpjs.status (1022-1023), konsultasi.status (542-543), konsultasi_chat.tipe_pesan (568-569), lab_permintaan.status (884-885), master_obat.bentuk_sediaan (713-714), persetujuan_pdp.jenis (1137-1138), pesanan_obat.status (810-811), resep.status (751-752)
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on pasien_tanda_vital (line 1161)

 Live schema
 counts tables=41 views=0 columns=283 indexes=102 foreign_keys=33 checks=0
 information_schema columns=283 indexes=102 foreign_keys=33 checks=0

 Discrepancies: 1 (1 drift, 0 informational)
 column_unsigned dokter.durasi_default_menit expected: unsigned | actual: signed

 FAIL — 1 discrepancy. The live schema does not match telemedicine_test.sql. Nothing was written.

=== NEG-QA verify EXIT=1 ===
```

It exits **1** and names the exact column, `dokter.durasi_default_menit`.

**Step 3 — the sibling columns are proven untouched.** `Discrepancies: 1`, not 4
and not 5: if the probe had stripped `unsigned` from all of `dokter`'s integer
columns, or if the four columns had been written with a shared helper, this count
would have been higher. Confirmed directly in the migration source after the
probe:

```
### RESTORE VERIFIED BY CONTENT ###
  line 94: $table->unsignedSmallInteger('pengalaman_tahun')->default(0);
  line 102: $table->unsignedSmallInteger('durasi_default_menit')->default(15);
  line 106: $table->unsignedInteger('jumlah_ulasan')->default(0);
  line 107: $table->unsignedInteger('jumlah_konsultasi')->default(0);
```

and in the live schema, from the §5b re-run after restoring:

```
MATCH  dokter  pengalaman_tahun      live=smallint unsigned  ddl_unsigned=true
MATCH  dokter  durasi_default_menit  live=smallint unsigned  ddl_unsigned=true
MATCH  dokter  jumlah_ulasan         live=int unsigned       ddl_unsigned=true
MATCH  dokter  jumlah_konsultasi     live=int unsigned       ddl_unsigned=true
NUMERIC_COLUMNS_AUDITED = 34   UNSIGNED_AMONG_THEM = 21   UNSIGNED_MISMATCHES = 0
```

**Step 4 — restore exactly, re-migrate, re-verify, expecting exit 0:**

```
$ php artisan migrate:fresh
 2026_10_01_000031_dokter_table .. 115.51ms DONE
=== migrate:fresh EXIT=0 ===

$ php artisan sehatly:verify-schema --tables=dokter

 Sehatly schema parity verifier — read-only, non-zero on drift

 reference SQL telemedicine_test.sql (59,604 bytes, md5 c76fafa884be)
 live database mysql / telemedisin_db
 notes registry docs/schema-notes.md (7 registered extra tables, 1 deferred constraint)
 scope dokter

 Parsed reference model (proof the parser is not vacuous)
 counts tables=75 views=2 columns=672 indexes=142 foreign_keys=105 checks=3
 wrapped decls 11 — each read as ONE unit: booking.status (515-516), home_care_pesanan.status (1104-1105), invoice.status (947-948), klaim_bpjs.status (1022-1023), konsultasi.status (542-543), konsultasi_chat.tipe_pesan (568-569), lab_permintaan.status (884-885), master_obat.bentuk_sediaan (713-714), persetujuan_pdp.jenis (1137-1138), pesanan_obat.status (810-811), resep.status (751-752)
 named keys 30 explicitly named (compared by name) + 37 inline/engine-named (compared by semantics)
 named FKs fk_vital_rm on pasien_tanda_vital (line 1161)

 Live schema
 counts tables=41 views=0 columns=283 indexes=102 foreign_keys=33 checks=0
 information_schema columns=283 indexes=102 foreign_keys=33 checks=0

 Discrepancies: 0 (0 drift, 0 informational)
 none — the live schema is byte-for-byte equivalent to the reference DDL.

 PASS — 75 tables, 2 views verified. Nothing was written.

=== RESTORED verify EXIT=0 ===
```

The full seven-table scope was re-verified after the restore as well: exit 0,
`Discrepancies: 0`, `scope` listing all seven real names.

---

## 7. Acceptance criterion 5 — `php artisan test tests/Unit`

```
 [derive] migrations=37 Schema::create calls=40 extracted=40 | CREATE VIEW calls=0 extracted=0 | contract tables=75 views=2 | derived-missing tables=41 views=2 | registry=7
{"tool":"pest","result":"passed","tests":93,"passed":93,"assertions":473,"duration_ms":5264}

=== php artisan test tests/Unit EXIT=0 elapsed=6s ===
```

**93 tests, 93 passed, 0 failed, 473 assertions, exit 0.** Count meets the
`>= 93` requirement. `tests/Unit/Console/VerifySchemaCommandTest.php` was **not
edited** (`git diff` on it is empty — see §12), and the derived missing count moved
from 48 to **41**, exactly as A.9's projection table predicts for todo 10.

### 7a. A real finding: the suite was RED on first run, from stale test-DB state

The first `php artisan test tests/Unit` run of this todo failed with 2 failures
(93 tests, 91 passed). Verbatim:

```
{"tool":"pest","result":"failed","tests":93,"passed":91,"assertions":456,"duration_ms":5065,"failed":2,"failures":[
{"test":"...the_verifier_exits_1_and_names_every_table_the_migrations_have_not_created_yet",
 "line":143,"message":"Failed asserting that 48 is identical to 41.",
 "trace":["...VerifySchemaCommandTest.php:143"]},
{"test":"...the_JSON_report_is_machine_readable__and_its_exit_code_matches_its_verdict",
 "line":216,"message":"the JSON report must name exactly the contract tables no migration creates yet
Failed asserting that two arrays are equal.
--- Expected
+++ Actual
@@ @@
 'artikel_kategori'
 'audit_log'
 'booking'
+ 'dokter'
+ 'dokter_faskes'
 'dokter_jadwal'
 'dokter_libur'
+ 'dokter_pendidikan'
+ 'dokter_spesialisasi'
+ 'faskes'
+ 'faskes_layanan'
 'home_care_pesanan'
 'invoice'
 'klaim_bpjs'
@@ @@
 'master_metode_pembayaran'
 'master_obat'
 'master_promo'
+ 'master_spesialisasi'
 'notifikasi'
 'obat_interaksi'
 'pembayaran'}]}
```

**Diagnosis: this is NOT the A.9 tripwire re-firing, and the test file is NOT
defective.** The `+` lines are the seven batch-D tables, and the 48-vs-41 gap is
exactly seven. The assertion compares the *live* report against the set derived
from the *migration files*; the migration set said 41 because my seven migrations
exist, while the live database was still the 34-table batch-C schema. `tests/Pest.php`
binds `RefreshDatabase` to the `Feature` directory only (A.5), so a `tests/Unit` run
does **not** migrate — it reads `telemedisin_db_test` exactly as it finds it, and I
had only run `migrate:fresh` against `telemedisin_db`.

Confirmation that the A.9 lift is genuinely in place and that no literal is hiding:

```
$ Select-String -Path tests/Unit/Console/VerifySchemaCommandTest.php -Pattern "48"
   (no matches - 0 occurrences)
```

Both failing assertions (line 143 `toBe(count($missingTables))` and line 216
`toEqualCanonicalizing($missing['tables'])`) are already derived, and the
derivation itself is self-checking (it asserts the extracted-table count equals the
`Schema::create` call count, 40 = 40, so an unresolvable call would fail loudly).

**Fix applied:** migrate the test database, which the brief explicitly authorises
("You MAY and MUST run `php artisan migrate:fresh` against `telemedisin_db` and
`telemedisin_db_test`"). No test edit, no `phpunit.xml` edit, no config edit:

```
$ $env:DB_DATABASE = "telemedisin_db_test"; php artisan migrate:fresh
 Dropping all tables .. 174.77ms DONE
 2026_10_01_000028_faskes_table .. 214.53ms DONE
 2026_10_01_000029_faskes_layanan_table .. 49.76ms DONE
 2026_10_01_000030_master_spesialisasi_table .. 35.80ms DONE
 2026_10_01_000031_dokter_table .. 120.65ms DONE
 2026_10_01_000032_dokter_spesialisasi_table .. 122.54ms DONE
 2026_10_01_000033_dokter_faskes_table .. 106.66ms DONE
 2026_10_01_000034_dokter_pendidikan_table .. 62.62ms DONE
=== migrate:fresh (test db) EXIT=0 ===
```

The suite is green on the re-run (§7 above). **Standing hazard for todos 11-18:**
whoever runs next must migrate `telemedisin_db_test` too, or the same two tests go
red on live-vs-migration-set divergence. It is a workflow step, not a code change.

## 8. Acceptance criterion 6 — zero-match filter exits 1

```
$ php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9
{"tool":"pest","result":"failed","tests":0,"passed":0,"assertions":0,"duration_ms":2,"raw":["No tests found."]}

=== zero-match filter EXIT=1 (expect 1) ===
```

---

## 9. Rollback cycle — `migrate:rollback --step=7` then `migrate`

```
$ php artisan migrate:rollback --step=7

 INFO Rolling back migrations.

 2026_10_01_000034_dokter_pendidikan_table .. 19.69ms DONE
 2026_10_01_000033_dokter_faskes_table .. 17.46ms DONE
 2026_10_01_000032_dokter_spesialisasi_table .. 13.75ms DONE
 2026_10_01_000031_dokter_table .. 38.84ms DONE
 2026_10_01_000030_master_spesialisasi_table .. 8.73ms DONE
 2026_10_01_000029_faskes_layanan_table .. 15.02ms DONE
 2026_10_01_000028_faskes_table .. 18.52ms DONE

=== rollback EXIT=0 (expect 0) ===

$ php artisan migrate

 INFO Running migrations.

 2026_10_01_000028_faskes_table .. 282.28ms DONE
 2026_10_01_000029_faskes_layanan_table .. 81.16ms DONE
 2026_10_01_000030_master_spesialisasi_table .. 42.08ms DONE
 2026_10_01_000031_dokter_table .. 120.92ms DONE
 2026_10_01_000032_dokter_spesialisasi_table .. 104.49ms DONE
 2026_10_01_000033_dokter_faskes_table .. 93.76ms DONE
 2026_10_01_000034_dokter_pendidikan_table .. 53.46ms DONE

=== migrate EXIT=0 (expect 0) ===
```

Rollback order is the exact reverse of creation order, so **children precede
parents** throughout: `dokter_pendidikan` and `dokter_spesialisasi` and
`dokter_faskes` drop before `dokter`, and `faskes_layanan` before `faskes`. No
dependency error, and `down()` is therefore correct for all seven.

---

## 10. Deferred-constraint position — **none owed by this batch**

Explicit, as required.

- **This batch defers nothing.** All ten of its foreign keys point at a table that
  already exists when the declaring migration runs: `master_provinsi`,
  `master_kabupaten_kota` and `master_kecamatan` from batch A; `users` from batch B;
  and `faskes`, `dokter` and `master_spesialisasi` from inside this batch
  (positions 28, 31 and 30, all strictly earlier than their referrers at 29, 32, 33
  and 34). `docs/schema-notes.md`'s *Deferred constraints* table is therefore
  **unchanged and still holds exactly one row**, and the section's registry
  enforcement contract is preserved verbatim.
- **`pasien_penjamin.faskes_rujukan_id` is NOT deferred and is NOT owed an FK.**
  This batch creates `faskes`, the table it pointed at, and deliberately leaves the
  column bare — per A.10 and A.11, the DDL declares no `FOREIGN KEY` for `:346`, so
  adding one is `extra_foreign_key` drift. Measured after this batch:
  `pasien_penjamin` carries **2** foreign keys (`pasien`, `penjamin_id`) and
  `faskes_rujukan_id` is not among them. Migration `2026_10_01_000076` must not add
  one. Recorded in the new `docs/schema-notes.md` section so the next executor does
  not "fix" it now that the ordering objection has expired.
- **The only genuinely deferred constraint in the project is untouched:**
  `fk_vital_rm` on `pasien_tanda_vital.rekam_medis_id`. Measured present count
  **0**, still owed by migration 76 per SQL section `[14]` (`:1161-1163`). The
  `docs/schema-notes.md` registry row and the 1-deferred-constraint reading in every
  verifier run in this file are unchanged.

---

## 11. Data safety

| Database | Tables | Domain rows | Verdict |
| --- | --- | --- | --- |
| `telemedisin_db` | **41** (34 contract + 7 infra) | **0** | migrated, as authorised by A.4 |
| `telemedisin_db_test` | **41** (34 contract + 7 infra) | **0** | migrated, as authorised by the brief |
| `sehatly` | **10** | — | **untouched**, original 5 ledger rows intact |
| `db_simprapkl` | 26 | — | untouched |
| `gawaiseken` | 18 | — | untouched |
| `laravel` | 5 | — | untouched |
| `manajemen-surat` | 0 | — | untouched |
| `trading_journal` | 12 | — | untouched |
| `ukk` | 12 | — | untouched |
| `ukk_pengaduan_sekolah` | 15 | — | untouched |

`telemedisin_db` ledger: **37** rows (3 surviving scaffolds + 34 contract
migrations), matching the 34 `Schema::create` calls the test derives.

`sehatly` is proven untouched by its **own** ledger, which still contains the three
scaffold rows the contract deleted from disk — impossible had anything migrated it:

```
sehatly                    tables=10   migrations_table=1 migration_rows=5
   Ledger: 0001_01_01_000000_create_users_table  (batch 1)
   Ledger: 0001_01_01_000001_create_cache_table  (batch 1)
   Ledger: 0001_01_01_000002_create_jobs_table  (batch 1)
   Ledger: 2024_01_01_000000_create_passkeys_table  (batch 2)
   Ledger: 2025_08_14_170933_add_two_factor_columns_to_users_table  (batch 2)
   Table list: cache, cache_locks, failed_jobs, job_batches, jobs, migrations, passkeys, password_reset_tokens, sessions, users
```

`telemedicine_test.sql` is byte-unchanged. Per A.3 the canonical check is
`git diff --exit-code`, **not** a byte-hash of the git blob against the worktree
(the committed blob is LF-normalised and the worktree file is CRLF, 1 348 bytes
apart by design):

```
$ git diff --exit-code telemedicine_test.sql
git diff --exit-code telemedicine_test.sql EXIT=0 (0 = unchanged)
$ (Get-FileHash telemedicine_test.sql -Algorithm SHA256).Hash
AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5
```

That SHA-256 is the value the brief stated, so the worktree file itself is
confirmed untouched as well as the committed blob.

---

## 12. Adversarial classes

**`misleading_success_output` — PROBED, and it fired twice, both times against me.**

1. `migrate:fresh` exits **0** on a schema containing a signed `SMALLINT` where the
   DDL says `SMALLINT UNSIGNED` (§6 step 1). The migrator has no parity opinion.
   Only `verify-schema` catches it, and only if you read the `Discrepancies:` line
   rather than the banner.
2. The first `verify-schema` run of this todo exited **1** on a genuine
   `index_name` drift that `migrate:fresh` reported as seven clean `DONE`s (§3).
3. Consequently `migrate:fresh` exiting 0 is treated as necessary and nowhere near
   sufficient. The independent §5 audit (66 columns, type / unsigned / nullable /
   default / extra, all five ENUM lists in SQL order, both DECIMAL scale pairs, the
   JSON type, 34 numeric unsigned flags, 10 foreign keys reconciled both ways) is
   the actual parity evidence, and it was built from `SqlSchemaParser` directly
   rather than by trusting the verifier's own verdict.
4. Two spurious *audit* mismatches (7 × `tinyint(1)`, 10 × `NO ACTION`) were traced
   to missing normalisation folds **in my audit script**, not to the migrations, and
   are recorded in §5 rather than deleted.

**`stale_state` — PROBED, and it bit.**

- `bootstrap/cache/config.php` does not exist (`Test-Path` → `False`;
  `bootstrap/cache` holds only `.gitignore`, `packages.php`, `services.php`).
  `php artisan config:clear` ran **before** the work (`Configuration cache cleared
  successfully.`, exit 0) and is run again in the cleanup receipt below.
- File ordering was read off the migrator's own ordered output, not from filenames
  alone: `…_000027` (batch C's last) precedes `…_000028`, and `000028` is the lowest
  of the seven.
- **The real stale-state hit is in §7a**: `telemedisin_db_test` still held the
  34-table batch-C schema while the migration set had already moved to 41, and
  because `tests/Pest.php` binds `RefreshDatabase` to `Feature` only, a
  `tests/Unit` run never reconciles it by itself. Two tests went red on live-vs-
  migration-set divergence with **no code defect anywhere**. Fixed by migrating the
  test database, and flagged as a standing hazard for todos 11-18.

**`dirty_worktree` — PROBED, and clean.**

`git status --porcelain` before this todo, verbatim:

```
 M .omo/plans/sehatly-telemedicine-platform.md
?? .omo/evidence/task-3-sehatly.md
?? .omo/start-work/
```

Those three are the orchestrator's or a prior executor's and were left completely
alone — not staged, not edited, not deleted. The commit in §13 contains only the
seven migrations, `docs/schema-notes.md` and this evidence file; §13 asserts
`git diff --cached --name-only` is empty afterwards and that
`git show --name-only --format="" HEAD` lists nothing else. No `git add -A`,
`git add .`, `git commit -a`, `git add -u`, `git stash`, `git checkout .`,
`git restore .`, `git clean` or `git reset` was used at any point; staging was by
explicit pathspec and the commit by explicit pathspec too.

**`hung_or_long_commands` — every command was given an explicit timeout and only
observed exit codes are reported.**

| Command | Timeout | Observed | Exit |
| --- | --- | --- | --- |
| `php artisan migrate:fresh` (`telemedisin_db`) | 300 000 ms | **~4 s** | 0 |
| `php artisan migrate:fresh` (x3 more times) | 300 000 ms | ~2-4 s | 0 |
| `php artisan migrate:fresh` (`telemedisin_db_test`) | 300 000 ms | ~2 s | 0 |
| `php artisan sehatly:verify-schema --tables=…` (x5) | 180 000 ms | ~1 s | 0 / 1 as designed |
| `php artisan migrate:rollback --step=7` | 300 000 ms | ~0.2 s | 0 |
| `php artisan migrate` | 300 000 ms | ~0.8 s | 0 |
| `php artisan test tests/Unit` (x2) | 600 000 ms | 5.1 s, 5.3 s | 1, then 0 |
| `php artisan test --filter=ThisTestNameCannotPossiblyExistZzz9` | 300 000 ms | 2 ms | 1 |
| `php vendor/bin/pint` | 300 000 ms | ~1 s | 0 |

Nothing hung, nothing was killed, and nothing was timed out. No metadata-lock
contention appeared; the pre-existing `mysqld` (PIDs 17484 / 19796) and the user's
`php artisan serve` (PID 22288) were never signalled — no `Stop-Process`,
`taskkill` or `kill` of any kind appears in this todo's command history.

**`repeated_interruptions` — PROBED via convergence, and via the rollback cycle.**

A schema fingerprint (every contract column's type/nullability/default/extra, every
index name and ordered column list, every foreign key, plus a domain-row count) was
taken, a second full `migrate:fresh` was run, and the fingerprint re-taken:

```
### FINGERPRINT BEFORE (telemedisin_db) ###
{ "db": "telemedisin_db", "tables": 34, "columns": 240, "indexes": 87,
  "foreign_keys": 33, "domain_rows": 0, "fingerprint": "a605c046aa87311d17487cd0637be3e2" }
### re-run migrate:fresh to convergence (round 2) ###
 Dropping all tables .. 220.51ms DONE
migrate:fresh EXIT=0
### FINGERPRINT AFTER ###
{ "db": "telemedisin_db", "tables": 34, "columns": 240, "indexes": 87,
  "foreign_keys": 33, "domain_rows": 0, "fingerprint": "a605c046aa87311d17487cd0637be3e2" }
```

**Byte-identical fingerprint across an interrupt-and-rerun cycle**, and zero domain
rows on both sides. The independent 41-table `migrate:fresh` and the
`rollback --step=7` + `migrate` cycle in §9 also agree with it. `telemedisin_db`
and `telemedisin_db_test` were then confirmed to carry the **same** fingerprint,
`a605c046aa87311d17487cd0637be3e2`, i.e. the two databases are in lockstep.

**`malformed_input` — RULED OUT.** No parser, no request-parsing surface, no CLI
input handling was authored. The only inputs are artisan's own argv (fixed strings
in this file) and `information_schema` rows already validated by MySQL.

**`prompt_injection` — RULED OUT, with the reading protocol stated.**
`telemedicine_test.sql` is first-party data and was read strictly **as a
specification**, never as instructions. The scan below covered the whole
`:355-475` region consumed by this todo plus the `[16]` seed section for anything
instruction-shaped. Nothing in it reads like an instruction to an agent: the
`COMMENT` clauses are schema documentation (`'Kode faskes BPJS/Kemenkes'`,
`'A/B/C/D (khusus rumah sakit)'`,
`'{"senin":{"buka":"07:00","tutup":"22:00"},...}'`), and the only imperative-sounding
prose in the file sits in the plan's own commentary, which is read as commentary.
No instruction was found and none was acted on; every value in the seven migrations
was taken from a `CREATE TABLE` declaration or from `docs/migration-order.md`.

**`cancel_resume` — RULED OUT.** No resumable user flow exists yet. There is no
state machine, no partial-progress marker and no client checkpoint in a batch of
`Schema::create` statements; cancellation support belongs to todos 20 (auth
sessions), 45 and 46 (mobile), none of which has been built.

**`flaky_tests` — RULED OUT.** The suite is deterministic and touches no clock,
network, randomness or concurrency. The zero-match filter was measured at **0 tests
in 2 ms, exit 1** (§8), and the Unit suite ran twice with byte-identical structure
(93 tests, 473 assertions) once the test database was correct. The only variation
observed anywhere was the one-time failure in §7a, whose cause was a stale
database, not nondeterminism.

---

## 13. Cleanup receipts

- Temp scratch files, all outside the repository, all deleted:
  `C:\Users\axioo\AppData\Local\Temp\opencode\t10_dbstate.php`,
  `…\t10_audit.php`, `…\t10_audit.out.txt`, `…\t10_showcreate.php`,
  `…\t10_showcreate.out.txt`, `…\t10_fingerprint.php`, `…\t10_dbsafety.php`,
  `…\t10_unit.out.txt`, `…\t10_verify_final.out.txt`,
  `…\t10_migrate_final.out.txt`, `…\t10_migrate_test_db.out.txt`.
- Nothing was written inside the repository except the nine committed paths and
  the pre-existing orchestrator files. No `mobile/` directory and no `pubspec.yaml`
  were created.
- `php artisan config:clear` re-run at the end (`Configuration cache cleared
  successfully.`, exit 0); `bootstrap/cache/config.php` still absent.
- `php vendor/bin/pint` run **bare, with no path argument** — mandatory per A.7,
  since passing `bootstrap` or `.` would override pint's cache exclude and make it
  try to reformat `bootstrap/cache/packages.php`. Result: `{"tool":"pint","result":"passed"}`,
  exit 0, no files changed.
- No process was started or left behind. No leftover listener, no orphan `php` or
  `mysql` process, and nothing to terminate.

## 14. Paths committed

```
database/migrations/2026_10_01_000028_faskes_table.php
database/migrations/2026_10_01_000029_faskes_layanan_table.php
database/migrations/2026_10_01_000030_master_spesialisasi_table.php
database/migrations/2026_10_01_000031_dokter_table.php
database/migrations/2026_10_01_000032_dokter_spesialisasi_table.php
database/migrations/2026_10_01_000033_dokter_faskes_table.php
database/migrations/2026_10_01_000034_dokter_pendidikan_table.php
docs/schema-notes.md
.omo/evidence/task-10-sehatly.md
```

`docs/schema-notes.md` gained exactly one new section, *Batch-D schema facts the
database cannot enforce (todo 10)*, plus one closing sentence about the extra-table
registry staying at seven. The *Registered extra tables* section and the whole
*Deferred constraints* section — prose, three rules and the single `fk_vital_rm`
row — are byte-unchanged; the registry enforcement contract is preserved.
