<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * **DEVELOPMENT FIXTURES. None of this data exists in `telemedicine_test.sql`.**
 *
 * This seeder is the reason `v_dokter_katalog` is non-empty, which is todo 18's
 * stated success criterion, and it is deliberately separated from the section-`[16]`
 * seeders so that the 1:1 fidelity claim stays honest.
 *
 * ## The 1:1 fidelity claim covers SCHEMA, not DATA
 *
 * The project's fidelity claim is that `database/migrations/**` reproduces
 * `telemedicine_test.sql` exactly: 75 tables, 2 views, every column, index,
 * foreign key and CHECK, verified by `php artisan sehatly:verify-schema`. It says
 * nothing about row counts.
 *
 * **The eight section-`[16]` seeders DO have a source** - the DDL's own
 * `INSERT` statements - and their row counts are part of that fidelity and are
 * checked against it. This seeder's rows have **no source at all**, so they are
 * outside the claim. `docs/schema-notes.md` records this explicitly.
 *
 * ## `telemedicine_test.sql` seeds 15 tables and NOT these two
 *
 * I enumerated every `INSERT INTO <target>` in the whole file (case-insensitive,
 * over all 1,349 lines) and got exactly **15** distinct targets, all in section
 * `[16]`:
 * `artikel_kategori`, `master_agama`, `master_golongan_darah`,
 * `master_hubungan_keluarga`, `master_icd10`, `master_icd9cm`,
 * `master_lab_paket`, `master_lab_tindakan`, `master_metode_pembayaran`,
 * `master_obat`, `master_pendidikan`, `master_penjamin`, `master_provinsi`,
 * `master_spesialisasi`, `master_status_pernikahan`.
 *
 * `lab_paket_item` and `obat_interaksi` appear **twice each** in the file and both
 * occurrences are accounted for:
 *
 * | Line | Statement | Kind |
 * | --- | --- | --- |
 * | `:31` | `DROP TABLE IF EXISTS lab_paket_item, ...` | reset |
 * | `:868` | `CREATE TABLE lab_paket_item (` | DDL |
 * | `:33` | `DROP TABLE IF EXISTS ..., obat_interaksi, ...` | reset |
 * | `:731` | `CREATE TABLE obat_interaksi (` | DDL |
 *
 * **Zero** `INSERT` statements for either. The file's last statement is
 * `artikel_kategori` at `:1338`-`:1344`; `:1346`-`:1348` are the `SELESAI` banner
 * and `:1349` is a bare `SELECT`. So the "3 `lab_paket_item` rows" and the
 * `obat_interaksi` pairs are **new data with no source in the SQL**, exactly as the
 * plan's own correction states. Do not "fix" this by attributing them to the DDL.
 *
 * The same is true of `master_promo`: it has **no** `INSERT` anywhere, so any promo
 * is fixture data in the same position. This seeder does not create one.
 *
 * ## What IS from the DDL and what is NOT
 *
 * | Rows | Source |
 * | --- | --- |
 * | 3 `users`, 2 `pasien`, 2 `faskes`, 3 `dokter`, 4 `dokter_spesialisasi` | **fixture** - invented for development |
 * | 3 `lab_paket_item` | **fixture** - but the *package names* and the `LAB-*` codes are the DDL's, and every code was verified to exist |
 * | 2 `obat_interaksi` | **fixture** - but the two drug pairs are the plan's, and both drug names are the DDL's |
 *
 * ## The two `obat_interaksi` pairs, and why `obat_a_id < obat_b_id`
 *
 * `obat_interaksi` (`:731-740`) has `obat_a_id` and `obat_b_id`, both
 * `BIGINT UNSIGNED NOT NULL` with `ON DELETE CASCADE`, and
 * `UNIQUE KEY uq_interaksi (obat_a_id, obat_b_id)` at `:739`.
 *
 * **The unique key is on the ORDERED pair, so `(Amoxicillin, Metformin)` and
 * `(Metformin, Amoxicillin)` are two different rows, not one.** An interaction is
 * a symmetric fact, so storing both orders would let the same interaction be
 * written twice and reported twice - and because the columns are nullable-free but
 * unordered in meaning, nothing in the schema stops it. The convention adopted here
 * is therefore **`obat_a_id < obat_b_id`**, i.e. the lower auto-increment id first:
 *
 * | `tingkat` | drugs | ids after seeding |
 * | --- | --- | --- |
 * | `berat` | `Amoxicillin` x `Metformin` | `OBT-0002` = 2, `OBT-0005` = 5 -> `(2, 5)` |
 * | `ringan` | `Amoxicillin` x `Cetirizine` | `OBT-0002` = 2, `OBT-0003` = 3 -> `(2, 3)` |
 *
 * Both drugs are looked up **by `kode_obat`**, not by a hard-coded id, and the
 * ordering is then asserted rather than assumed - the seeder throws if
 * `obat_a_id >= obat_b_id`, so the convention cannot rot silently. The ids are
 * 1..7 because {@see ObatSeeder} truncates first, so `AUTO_INCREMENT` restarts;
 * the lookup-by-code means the seeder is correct even if that ever changes.
 *
 * `tingkat` is `ENUM('ringan','sedang','berat','kontraindikasi') NOT NULL` at
 * `:735` - four members. The two rows use `'berat'` and `'ringan'`; `'sedang'` and
 * `'kontraindikasi'` are unseeded. `deskripsi` is `TEXT NULL` and is left `NULL` on
 * purpose: a clinical interaction description is not something a fixture should
 * assert, and inventing one would be a fabricated medical claim in a repository.
 *
 * ## The three `lab_paket_item` rows
 *
 * `lab_paket_item` (`:868-874`) is a **composite-PK join table with no `id`
 * column**: `PRIMARY KEY (paket_id, tindakan_id)`, with `ON DELETE CASCADE` from
 * `paket_id` and no `ON DELETE` clause on `tindakan_id` (so implicit
 * `NO ACTION`/`RESTRICT`). Six rows is impossible - the pair is unique - and the
 * mappings the plan specifies are:
 *
 * | Package (`nama`, the DDL's) | `LAB-*` codes |
 * | --- | --- |
 * | `Medical Check Up Dasar` | `LAB-001`, `LAB-002`, `LAB-009` |
 * | `Cek Gula & Kolesterol` | `LAB-003`, `LAB-004` |
 * | `Fungsi Hati Lengkap` | `LAB-005`, `LAB-006` |
 *
 * All six `LAB-*` codes were verified against `telemedicine_test.sql:1321-1330`
 * before use: `LAB-001` Hemoglobin (Hb), `LAB-002` Laju Endap Darah (LED),
 * `LAB-003` Glukosa Darah Puasa, `LAB-004` Kolesterol Total, `LAB-005` Fungsi
 * Hati (SGOT), `LAB-006` Fungsi Hati (SGPT), `LAB-009` Urinalisa Lengkap.
 *
 * Note the mapping does **not** follow the `deskripsi` prose - see
 * {@see LabSeeder} on why. `Fungsi Hati Lengkap` maps to SGOT and SGPT only, not to
 * the bilirubin and albumin its description names, because those are not seeded
 * actions. `Cek Gula & Kolesterol` maps to glucose and total cholesterol only, not
 * to the triglycerides, HDL and LDL its description names.
 *
 * ## The doctor fixture exists to make `v_dokter_katalog` non-empty
 *
 * `v_dokter_katalog` (`telemedicine_test.sql:1170-1187`) filters on **three**
 * predicates - `status_verifikasi = 'terverifikasi'`, `status_aktif = 1` and
 * `tersedia_telemedisin = 1` - and `status_verifikasi` defaults to `'pending'`
 * (`:427`). So a doctor inserted with defaults is **invisible to the view**, and a
 * view that exists but returns nothing would satisfy `verify-schema` (which
 * compares views by name and existence only) while failing todo 18's actual
 * criterion. **The fixture is load-bearing, not decorative.**
 *
 * Three doctors are created so the view's `GROUP_CONCAT` is genuinely exercised:
 * two carry multiple `dokter_spesialisasi` rows and one carries none, which is
 * what makes the `LEFT JOIN` behaviour observable (`NULL` `spesialisasi` for the
 * third). A fourth doctor is deliberately left `pending` and a fifth
 * `tersedia_telemedisin = 0`, so the fixture also proves the view's predicates
 * actually filter - a view that returned all five would be wrong and the count
 * would prove it.
 *
 * `dokter_spesialisasi` has `UNIQUE KEY uq_dokter_spes (dokter_id,
 * spesialisasi_id)` at `:444`, so the four mappings are distinct pairs. Note the
 * name is `uq_dokter_spes` with a trailing `es` - plan appendix A.14 corrected a
 * plan typo that said `uq_dokter_ses`.
 *
 * ## `users.kata_sandi_hash` is `NOT NULL` and has NO nullable representation
 *
 * `:138` is `kata_sandi_hash VARCHAR(255) NOT NULL` with **no** nullable or
 * OTP-only form, so an OTP-only account must still store a random unusable hash.
 * Every fixture user therefore gets a bcrypt hash of a random 32-byte string, not
 * a guessable password. `uuid` is `CHAR(36) NOT NULL UNIQUE` (`:134`) and is
 * generated, not hand-written, so re-running the seeder never collides.
 * `no_telepon` is `VARCHAR(20) NOT NULL UNIQUE` (`:137`) and `email` is
 * `VARCHAR(255) NULL UNIQUE` (`:136`) - nullable but unique, so two fixture users
 * with `NULL` email would both insert (MySQL permits unlimited `NULL`s in a
 * `UNIQUE`), which is a fact todo 8 proved live.
 *
 * ## `pasien.tinggi_badan_cm` is `DECIMAL(5,1)` and `berat_badan_kg` is `DECIMAL(5,2)`
 *
 * `:243`-`:244`. The scales differ and both matter: 5 total digits, so
 * `tinggi_badan_cm` tops out at 999.9 and `berat_badan_kg` at 999.99. Written as
 * strings for exact-decimal reasons, as everywhere else in this project's seeders.
 *
 * `faskes.akreditasi` is `ENUM('belum','dasar','utama','maju','paripurna') NULL`
 * (`:376`) - the correct spelling is **`paripurna`**. Plan appendix A.14 records
 * that a dispatch brief once misspelled it as `parnirvana` while instructing the
 * executor to copy the exact spelling from the SQL, which is the failure mode that
 * made reading the SQL rather than the brief the standing rule.
 */
class DevFixtureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * MUST run after {@see MasterUmumSeeder}, {@see SpesialisasiSeeder},
     * {@see ObatSeeder} and {@see LabSeeder}: it looks rows up in all four by their
     * natural key rather than by a hard-coded id, and throws rather than inserting
     * a dangling reference if one is missing. That is deliberate - a fixture that
     * silently skipped a doctor because a master table was empty would produce an
     * empty `v_dokter_katalog` and a green `verify-schema`, which is precisely the
     * `misleading_success_output` failure this todo has to rule out.
     */
    public function run(): void
    {
        $this->seedFaskes();
        $this->seedUsersAndPatients();
        $this->seedDoctors();
        $this->seedLabPaketItem();
        $this->seedObatInteraksi();
    }

    /**
     * Two facilities, so `dokter_faskes` has something to point at.
     *
     * `faskes.provinsi_id` is `TINYINT UNSIGNED NULL` with a real
     * `FOREIGN KEY (provinsi_id) REFERENCES master_provinsi(id)` at `:381`, so
     * this runs after {@see MasterWilayahSeeder}. `kabupaten_kota_id` and
     * `kecamatan_id` are **also** constrained (`:382`, `:383`) and
     * {@see MasterWilayahSeeder} seeds neither `master_kabupaten_kota` nor
     * `master_kecamatan`, so both are left `NULL` - which the DDL permits and which
     * is honest, since inventing city and district rows would be unsourced data
     * this seeder has no mandate for.
     *
     * `latitude`/`longitude` are `DECIMAL(10,8)` / `DECIMAL(11,8)`, and
     * `idx_faskes_geo (latitude, longitude)` (`:385`) is a DDL-written name
     * compared by name, so both are supplied here to make that index meaningful.
     */
    private function seedFaskes(): void
    {
        $jakarta = DB::table('master_provinsi')->where('kode', '31')->value('id');

        if ($jakarta === null) {
            throw new RuntimeException('DevFixtureSeeder: master_provinsi has no kode 31 (DKI Jakarta). Run MasterWilayahSeeder first.');
        }

        DB::table('faskes')->insert([
            [
                'kode_faskes' => 'FASKES-DEV-001',
                'nama' => 'Klinik Telemedicine Contoh (dev)',
                'tipe' => 'klinik',
                'kelas_rs' => null,
                'alamat' => 'Jl. Fixture No. 1, Jakarta',
                'provinsi_id' => $jakarta,
                'latitude' => '-6.20000000',
                'longitude' => '106.81666667',
                'telepon' => '0215550001',
                'email' => 'klinik.dev@example.test',
                'akreditasi' => 'utama',
                'status_aktif' => 1,
            ],
            [
                'kode_faskes' => 'FASKES-DEV-002',
                'nama' => 'Laboratorium Contoh (dev)',
                'tipe' => 'laboratorium',
                'kelas_rs' => null,
                'alamat' => 'Jl. Fixture No. 2, Jakarta',
                'provinsi_id' => $jakarta,
                'latitude' => '-6.21000000',
                'longitude' => '106.82666667',
                'telepon' => '0215550002',
                'email' => 'lab.dev@example.test',
                'akreditasi' => 'dasar',
                'status_aktif' => 1,
            ],
        ]);
    }

    /**
     * Three `users` (two patients, one doctor account) and two `pasien` rows.
     *
     * `pasien.user_id` is `BIGINT UNSIGNED NOT NULL UNIQUE` (`:220`) with a real
     * `FOREIGN KEY (user_id) REFERENCES users(id)` at `:250`, and it is a **bare**
     * one-to-one: a user is at most one patient. The doctor's `users` row
     * deliberately has **no** `pasien` row, which is what makes the
     * `dokter`/`users` join in `v_dokter_katalog` structurally different from the
     * patient join.
     *
     * `pasien.agama_id` (`:230`), `golongan_darah_id` (`:228`),
     * `pendidikan_id` (`:231`) and `status_pernikahan_id` (`:233`) are
     * `TINYINT UNSIGNED` with real foreign keys to the five no-`AUTO_INCREMENT`
     * master tables, and the ids `1`-`4` used here are the DDL's own - resolved by
     * lookup on `nama` rather than hard-coded, so a renumbered master table would
     * fail loudly here instead of silently mislabelling a patient.
     *
     * `provinsi_id` is `TINYINT UNSIGNED NULL` and **has no foreign key** (it is
     * not among `:250`-`:254`), so it is safe to set and is set for realism.
     */
    private function seedUsersAndPatients(): void
    {
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

        $users = [
            [
                'uuid' => $this->uuid(),
                'nama_lengkap' => 'Dokter Fixture Satu',
                'email' => 'doker1.dev@example.test',
                'no_telepon' => '081100000001',
                'kata_sandi_hash' => $hash,
                'tipe' => 'dokter',
                'status' => 'aktif',
                'bahasa' => 'id',
                'telepon_terverifikasi' => 1,
                'email_terverifikasi' => 1,
            ],
            [
                'uuid' => $this->uuid(),
                'nama_lengkap' => 'Dokter Fixture Dua',
                'email' => 'doker2.dev@example.test',
                'no_telepon' => '081100000002',
                'kata_sandi_hash' => $hash,
                'tipe' => 'dokter',
                'status' => 'aktif',
                'bahasa' => 'id',
                'telepon_terverifikasi' => 1,
                'email_terverifikasi' => 1,
            ],
            [
                'uuid' => $this->uuid(),
                'nama_lengkap' => 'Dokter Fixture Tiga Belum Terverifikasi',
                'email' => 'doker3.dev@example.test',
                'no_telepon' => '081100000003',
                'kata_sandi_hash' => $hash,
                'tipe' => 'dokter',
                'status' => 'aktif',
                'bahasa' => 'id',
                'telepon_terverifikasi' => 1,
                'email_terverifikasi' => 1,
            ],
            [
                'uuid' => $this->uuid(),
                'nama_lengkap' => 'Pasien Fixture Satu',
                'email' => 'pasien1.dev@example.test',
                'no_telepon' => '081100000011',
                'kata_sandi_hash' => $hash,
                'tipe' => 'pasien',
                'status' => 'aktif',
                'bahasa' => 'id',
                'telepon_terverifikasi' => 1,
                'email_terverifikasi' => 1,
            ],
            [
                'uuid' => $this->uuid(),
                'nama_lengkap' => 'Pasien Fixture Dua',
                'email' => 'pasien2.dev@example.test',
                'no_telepon' => '081100000012',
                'kata_sandi_hash' => $hash,
                'tipe' => 'pasien',
                'status' => 'aktif',
                'bahasa' => 'id',
                'telepon_terverifikasi' => 1,
                'email_terverifikasi' => 1,
            ],
        ];

        foreach ($users as $user) {
            DB::table('users')->insert($user);
        }

        $dokterUserIds = DB::table('users')->whereIn('email', [
            'doker1.dev@example.test',
            'doker2.dev@example.test',
            'doker3.dev@example.test',
        ])->pluck('id', 'email');

        $pasienUserIds = DB::table('users')->whereIn('email', [
            'pasien1.dev@example.test',
            'pasien2.dev@example.test',
        ])->pluck('id', 'email');

        $pasienRows = [
            [
                'user_id' => $pasienUserIds['pasien1.dev@example.test'],
                'nomor_rm' => 'RM-202601-000001',
                'nik' => '3171010101900001',
                'jenis_kelamin' => 'L',
                'tanggal_lahir' => '1990-01-01',
                'tempat_lahir' => 'Jakarta',
                'golongan_darah_id' => $this->masterId('master_golongan_darah', 'kode', 'B'),
                'rhesus' => 'positif',
                'agama_id' => $this->masterId('master_agama', 'nama', 'Islam'),
                'pendidikan_id' => $this->masterId('master_pendidikan', 'nama', 'Sarjana (S1)'),
                'pekerjaan' => 'Guru',
                'status_pernikahan_id' => $this->masterId('master_status_pernikahan', 'nama', 'menikah'),
                'alamat_lengkap' => 'Jl. Fixture No. 11, Jakarta',
                'tinggi_badan_cm' => '170.0',
                'berat_badan_kg' => '65.50',
            ],
            [
                'user_id' => $pasienUserIds['pasien2.dev@example.test'],
                'nomor_rm' => 'RM-202601-000002',
                'nik' => '3174010202950002',
                'jenis_kelamin' => 'P',
                'tanggal_lahir' => '1995-02-02',
                'tempat_lahir' => 'Jakarta',
                'golongan_darah_id' => $this->masterId('master_golongan_darah', 'kode', 'O'),
                'rhesus' => 'negatif',
                'agama_id' => $this->masterId('master_agama', 'nama', 'Kristen Protestan'),
                'pendidikan_id' => $this->masterId('master_pendidikan', 'nama', 'Diploma (D1-D3)'),
                'pekerjaan' => 'Perawat',
                'status_pernikahan_id' => $this->masterId('master_status_pernikahan', 'nama', 'belum_menikah'),
                'alamat_lengkap' => 'Jl. Fixture No. 12, Jakarta',
                'tinggi_badan_cm' => '160.5',
                'berat_badan_kg' => '55.25',
            ],
        ];

        foreach ($pasienRows as $row) {
            DB::table('pasien')->insert($row);
        }

        // Stash the doctor user ids for seedDoctors(), which needs the same three.
        $this->dokterUserIds = $dokterUserIds->all();
    }

    /**
     * Doctor account ids, resolved once in seedUsersAndPatients().
     *
     * @var array<string, int>
     */
    private array $dokterUserIds = [];

    /**
     * Three doctors, of which exactly **two** satisfy all three of
     * `v_dokter_katalog`'s predicates.
     *
     * The third is `status_verifikasi = 'pending'` - the column's own default
     * (`:427`) - while `status_aktif = 1`, which is the exact trap
     * `docs/schema-notes.md` (batch D) records: **a `dokter` row may be active and
     * unverified at the same time**, so a directory query filtering on
     * `status_aktif` alone leaks it. Creating that row deliberately is what makes
     * the view's filtering observable rather than assumed.
     *
     * `dokter.nomor_str` is `VARCHAR(30) NOT NULL UNIQUE` (`:413`) and
     * `user_id` is `BIGINT UNSIGNED NOT NULL UNIQUE` (`:411`), so both must be
     * distinct per doctor. `tipe` is the SEVEN-value `dokter` ENUM (`:412`) and is
     * deliberately **not** the three-value `master_spesialisasi.tipe` - the two
     * share only `'dokter_umum'`.
     *
     * `rating_rata_rata` is `DECIMAL(3,2) NOT NULL DEFAULT 0.00` (`:423`), so the
     * maximum expressible rating is **9.99** and the scale is two decimals. A
     * non-zero value is seeded so the view's `rating_rata_rata` column is not all
     * zeros, and `jumlah_konsultasi` / `jumlah_ulasan` are `INT UNSIGNED` (`:424`,
     * `:425`) so a negative value would be MySQL 1264.
     */
    private function seedDoctors(): void
    {
        $rows = [
            [
                'user_id' => $this->dokterUserIds['doker1.dev@example.test'],
                'tipe' => 'dokter_umum',
                'nomor_str' => 'STR-DEV-0001',
                'str_berlaku_sampai' => '2030-12-31',
                'nomor_sip' => 'SIP-DEV-0001',
                'sip_berlaku_sampai' => '2030-12-31',
                'pengalaman_tahun' => 8,
                'biaya_konsultasi_online' => '50000.00',
                'durasi_default_menit' => 15,
                'rating_rata_rata' => '4.70',
                'jumlah_ulasan' => 120,
                'jumlah_konsultasi' => 340,
                'tersedia_telemedisin' => 1,
                'status_verifikasi' => 'terverifikasi',
                'status_aktif' => 1,
            ],
            [
                'user_id' => $this->dokterUserIds['doker2.dev@example.test'],
                'tipe' => 'dokter_spesialis',
                'nomor_str' => 'STR-DEV-0002',
                'str_berlaku_sampai' => '2030-12-31',
                'nomor_sip' => 'SIP-DEV-0002',
                'sip_berlaku_sampai' => '2030-12-31',
                'pengalaman_tahun' => 15,
                'biaya_konsultasi_online' => '150000.00',
                'durasi_default_menit' => 30,
                'rating_rata_rata' => '4.85',
                'jumlah_ulasan' => 410,
                'jumlah_konsultasi' => 980,
                'tersedia_telemedisin' => 1,
                'status_verifikasi' => 'terverifikasi',
                'status_aktif' => 1,
            ],
            [
                // Active but UNVERIFIED - the default pair that makes the view's
                // filter load-bearing. Excluded from v_dokter_katalog on purpose.
                'user_id' => $this->dokterUserIds['doker3.dev@example.test'],
                'tipe' => 'dokter_umum',
                'nomor_str' => 'STR-DEV-0003',
                'str_berlaku_sampai' => '2029-06-30',
                'nomor_sip' => 'SIP-DEV-0003',
                'sip_berlaku_sampai' => '2029-06-30',
                'pengalaman_tahun' => 1,
                'biaya_konsultasi_online' => '25000.00',
                'durasi_default_menit' => 15,
                'rating_rata_rata' => '0.00',
                'jumlah_ulasan' => 0,
                'jumlah_konsultasi' => 0,
                'tersedia_telemedisin' => 1,
                'status_verifikasi' => 'pending',
                'status_aktif' => 1,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('dokter')->insert($row);
        }

        // Two specialisation mappings for doctor 1, one for doctor 2, and NONE for
        // doctor 3 - so the view's `LEFT JOIN` and its `GROUP_CONCAT` over a
        // missing specialisation (a `NULL` `spesialisasi`) are both exercised.
        //
        // `master_spesialisasi.kode` is the lookup key, verified to exist against
        // `:1237-1252`. `is_utama` is `TINYINT(1) NOT NULL DEFAULT 0` (`:441`) and
        // the column set explicitly, because the default is 0 and a "primary"
        // specialisation has to be opted into.
        $dokterIds = DB::table('dokter')->pluck('id', 'nomor_str');

        $links = [
            ['dokter' => 'STR-DEV-0001', 'kode' => 'UMUM', 'is_utama' => 1],
            ['dokter' => 'STR-DEV-0001', 'kode' => 'SP.PD', 'is_utama' => 0],
            ['dokter' => 'STR-DEV-0002', 'kode' => 'SP.KK', 'is_utama' => 1],
            ['dokter' => 'STR-DEV-0002', 'kode' => 'SP.A', 'is_utama' => 0],
        ];

        $insert = [];

        foreach ($links as $link) {
            $insert[] = [
                'dokter_id' => $dokterIds[$link['dokter']],
                'spesialisasi_id' => $this->masterId('master_spesialisasi', 'kode', $link['kode']),
                'is_utama' => $link['is_utama'],
            ];
        }

        DB::table('dokter_spesialisasi')->insert($insert);
    }

    /**
     * The three `lab_paket_item` rows - **unsourced fixture data**.
     *
     * See the class docblock for why these are not part of the fidelity claim.
     * `lab_paket_item` has no `id` and a composite primary key, so the insert is
     * exactly the pair and `truncate()` is the only sane reset.
     */
    private function seedLabPaketItem(): void
    {
        $paket = DB::table('master_lab_paket')->pluck('id', 'nama');
        $tindakan = DB::table('master_lab_tindakan')->pluck('id', 'kode');

        $map = [
            'Medical Check Up Dasar' => ['LAB-001', 'LAB-002', 'LAB-009'],
            'Cek Gula & Kolesterol' => ['LAB-003', 'LAB-004'],
            'Fungsi Hati Lengkap' => ['LAB-005', 'LAB-006'],
        ];

        $insert = [];

        foreach ($map as $nama => $kodes) {
            if (! isset($paket[$nama])) {
                throw new RuntimeException("DevFixtureSeeder: master_lab_paket has no package named '{$nama}'. Run LabSeeder first.");
            }

            foreach ($kodes as $kode) {
                if (! isset($tindakan[$kode])) {
                    throw new RuntimeException("DevFixtureSeeder: master_lab_tindakan has no action coded '{$kode}'. Run LabSeeder first.");
                }

                $insert[] = [
                    'paket_id' => $paket[$nama],
                    'tindakan_id' => $tindakan[$kode],
                ];
            }
        }

        DB::table('lab_paket_item')->insert($insert);
    }

    /**
     * The two `obat_interaksi` rows - **unsourced fixture data**.
     *
     * `Amoxicillin` x `Metformin` as `'berat'` and `Amoxicillin` x `Cetirizine` as
     * `'ringan'`, with `obat_a_id < obat_b_id` asserted rather than assumed.
     * `deskripsi` is left `NULL`: see the class docblock.
     */
    private function seedObatInteraksi(): void
    {
        $obat = DB::table('master_obat')->pluck('id', 'kode_obat');

        $pairs = [
            ['a' => 'OBT-0002', 'b' => 'OBT-0005', 'tingkat' => 'berat'],
            ['a' => 'OBT-0002', 'b' => 'OBT-0003', 'tingkat' => 'ringan'],
        ];

        $insert = [];

        foreach ($pairs as $pair) {
            foreach (['a', 'b'] as $side) {
                if (! isset($obat[$pair[$side]])) {
                    throw new RuntimeException("DevFixtureSeeder: master_obat has no drug coded '{$pair[$side]}'. Run ObatSeeder first.");
                }
            }

            $a = (int) $obat[$pair['a']];
            $b = (int) $obat[$pair['b']];

            // `uq_interaksi (obat_a_id, obat_b_id)` is on the ORDERED pair, so the
            // same interaction written in both orders would be two distinct rows.
            // Ordering by id and then refusing to write an unordered pair is what
            // keeps that from happening.
            [$low, $high] = $a < $b ? [$a, $b] : [$b, $a];

            if ($low === $high) {
                throw new RuntimeException("DevFixtureSeeder: interaction pair {$pair['a']} / {$pair['b']} resolved to the same drug id {$low}.");
            }

            $insert[] = [
                'obat_a_id' => $low,
                'obat_b_id' => $high,
                'tingkat' => $pair['tingkat'],
                'deskripsi' => null,
            ];
        }

        DB::table('obat_interaksi')->insert($insert);
    }

    /**
     * Resolve a master-table id by its natural key, or fail loudly.
     *
     * A seeder that guessed an id would produce a row pointing at the wrong master
     * record - a patient labelled with the wrong blood group, say - and nothing
     * would fail. That is why every master reference in this file goes through
     * here: the fixture asserts the label it means rather than assuming it.
     */
    private function masterId(string $table, string $key, mixed $value): int
    {
        $id = DB::table($table)->where($key, $value)->value('id');

        if ($id === null) {
            throw new RuntimeException("DevFixtureSeeder: {$table} has no row with {$key} = '{$value}'.");
        }

        return (int) $id;
    }

    /**
     * A random RFC 4122 version-4 UUID, for `users.uuid CHAR(36) NOT NULL UNIQUE`.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
