<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Obat\ObatInteraksiService;
use App\Services\PesananObat\ApotekStokService;
use App\Services\Resep\ResepVerifikasiService;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * **DEMO ACCOUNTS AND THEIR RELATIONSHIPS. Every row here is invented.**
 *
 * ## Why this seeder exists
 *
 * The F3 manual-QA gate raised a BLOCKER (F3-02): four of the thirteen
 * requested journeys - prescription with the interaction override, order
 * checkout, order tracking, and the doctor half of the medical record - could
 * not be exercised at all. **The cause was seed data, not missing code.**
 * {@see DevFixtureSeeder} gives every fixture account
 * `password_hash(bin2hex(random_bytes(32)))` - a 64-character random password
 * nobody knows - so the entire fixture dataset is permanently unloginable, and
 * with no reachable doctor there is no way to write a prescription, verify one,
 * check out an order or open a medical record.
 *
 * This seeder therefore supplies the preconditions that have **no public API
 * at all**: a doctor account, a pharmacist account, a doctor's schedule, a
 * pharmacy and its stock. It deliberately does NOT create the booking, the
 * consultation, the prescription, the order or the payment - those are domain
 * rows, and a seeder that created them would make the journeys "reachable" only
 * to somebody reading the database. The journeys are performed over the HTTP
 * API by `tests/Feature/DemoData/DemoJourneyTest.php`, with a token minted by
 * the real two-step login, and a row appearing in `notifikasi` or `pesanan_obat`
 * is the only thing any of those tests counts.
 *
 * ## IT IS IDEMPOTENT, and that is the load-bearing property
 *
 * `RbacSeeder` was made idempotent by the F2 gate after it proved fatal on a
 * second `db:seed`, and this class must not reintroduce that defect. Every write
 * below resolves on a DDL-declared natural key:
 *
 * | table | key | how |
 * | --- | --- | --- |
 * | `users` | `no_telepon` (`:137` UNIQUE) | `upsert`, updating every other column |
 * | `pasien` | `user_id` (`:220` UNIQUE) | `upsert` |
 * | `dokter` | `user_id` (`:411` UNIQUE) | `upsert` |
 * | `dokter_spesialisasi` | `(dokter_id, spesialisasi_id)` (`:444` UNIQUE) | `upsert` |
 * | `faskes` | `kode_faskes` (`:362` UNIQUE) | `upsert` |
 * | `apotek_stok` | `(apotek_id, obat_id)` (`:840` UNIQUE) | `upsert` |
 * | `user_roles` | `(user_id, role_id)` primary key (`:174`) | `insertOrIgnore` via {@see RoleAssigner} |
 * | `dokter_jadwal` | **none** | existence check on `(dokter_id, hari, jam_mulai, jam_selesai, berlaku_mulai)`, then insert |
 * | `pasien_alergi` | **none** | existence check on `(pasien_id, nama_alergen)`, then insert |
 *
 * The two tables with no unique key are the reason `upsert` is not used
 * everywhere: MySQL's `ON DUPLICATE KEY UPDATE` fires on ANY unique index, and a
 * table with none cannot be upserted without adding an index - which the DDL
 * forbids. An explicit existence check is the honest substitute, and it keeps
 * every statement a re-run issues a read or an insert.
 *
 * `users.uuid` is `CHAR(36) NOT NULL UNIQUE` (`:134`) and the password hash is
 * a **fixed literal** rather than `password_hash()` of a fresh salt, so a re-run
 * writes byte-identical rows and "the second run changed nothing" is a
 * measurement rather than a claim.
 *
 * ## THE PII IS OBVIOUSLY FAKE, and here is the list
 *
 * - **Phone numbers are `081000000001`, `081000000002`, `081000000003`.** `0810`
 *   is not an allocated Indonesian mobile operator prefix, so no dialled number
 *   in this repository can reach a person. `no_telepon` is `VARCHAR(20)`, and the
 *   OTP path is `LogOtpSender`, so nothing is sent anywhere either.
 * - **Email addresses are `@sehatly.test`.** `.test` is reserved by RFC 6761 and
 *   can never resolve.
 * - **NO NIK IS WRITTEN, ANYWHERE.** `pasien.nik` is left `NULL`. F3-03 records
 *   an OPEN scope violation owned by the orchestrator - the column is a
 *   plaintext `CHAR(16)` with no blind index and `NIK_CIPHER_KEY` is unset - and
 *   this seeder does not fix it, does not design around it and adds no
 *   migration. **It follows that the demo data cannot demonstrate NIK
 *   masking**, and that is stated rather than papered over. Writing a
 *   16-digit placeholder would put a number in the repository that reads as a
 *   person's NIK and would make the masking question look answered.
 * - **No token and no OTP is committed.** The only credentials here are three
 *   documented demo passwords, and a demo account whose password nobody knows is
 *   precisely the defect this seeder exists to remove.
 *
 * ## IT DOES NOT RUN IN PRODUCTION
 *
 * A demo account is a convenience for a local boot, and a convenience that
 * silently exists on a production database is a backdoor. The whole body is
 * skipped unless the environment is not `production`, and the skip is a
 * `WARNING` line rather than a silent return.
 *
 * ## ORDER
 *
 * MUST run after {@see RbacSeeder} (it assigns roles, and `RoleAssigner` throws
 * on a role name `roles` does not hold), after {@see MasterUmumSeeder} and
 * {@see MasterWilayahSeeder} (it reads four master rows by name and refuses to
 * insert a dangling reference) and after {@see ObatSeeder} and
 * {@see SpesialisasiSeeder} (the stocked drugs and the doctor's specialisation).
 * {@see DatabaseSeeder} therefore calls it last.
 */
final class DemoDataSeeder extends Seeder
{
    /**
     * The three account keys, and the only three this class creates.
     *
     * They are exactly the three roles the four unreachable journeys need: a
     * patient who can book and check out, a doctor who can consult, prescribe
     * and open a medical record, and a pharmacist who can sign a prescription
     * so it becomes dispensable at all
     * ({@see ResepVerifikasiService::siapDipenuhi()}).
     */
    public const AKUN_PASIEN = 'pasien';

    public const AKUN_DOKTER = 'dokter';

    public const AKUN_APOTEKER = 'apoteker';

    /**
     * The three demo passwords, in public constants rather than in a private
     * array, because a demo credential that cannot be read from the code is a
     * demo credential nobody can use.
     *
     * They are PUBLISHED, not secret: a local boot has to be able to log in,
     * and {@see shouldRun()} refuses to create any of them in `production`.
     */
    public const KATA_SANDI_PASIEN = 'Demo#Pasien2026';

    public const KATA_SANDI_DOKTER = 'Demo#Dokter2026';

    public const KATA_SANDI_APOTEKER = 'Demo#Apoteker2026';

    /**
     * The three accounts, by their natural key.
     *
     * `uuid` is a FIXED string per account rather than a generated one, because
     * `users.uuid` is `UNIQUE` and a re-run that generated a fresh UUID would
     * collide with the row it is trying to update. `kata_sandi_hash` is a fixed
     * bcrypt literal for the same reason - `password_hash()` is salted, so a
     * fresh hash on every run would make "the second run changed nothing" false
     * by construction.
     *
     * @var array<string, array{no_telepon: string, email: string, nama: string, tipe: string, role: string, uuid: string, kata_sandi: string, hash: string}>
     */
    private const AKUN = [
        self::AKUN_PASIEN => [
            'no_telepon' => '081000000001',
            'email' => 'demo.pasien@sehatly.test',
            'nama' => 'Demo Pasien Sehatly',
            'tipe' => 'pasien',
            'role' => 'pasien',
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'kata_sandi' => self::KATA_SANDI_PASIEN,
            'hash' => '$2y$10$d0GZWBk8o61bakvZs/H4SuKI.jdxejs6HkuSQNlX3ZHggQXZ7ia9S',
        ],
        self::AKUN_DOKTER => [
            'no_telepon' => '081000000002',
            'email' => 'demo.dokter@sehatly.test',
            'nama' => 'Demo Dokter Sehatly',
            'tipe' => 'dokter',
            'role' => 'dokter',
            'uuid' => '00000000-0000-4000-8000-000000000002',
            'kata_sandi' => self::KATA_SANDI_DOKTER,
            'hash' => '$2y$10$wzep8DTr12oKcQDx.emFP.Tmp23KQHxUXtRkA5Lvze2vIA1y450JK',
        ],
        self::AKUN_APOTEKER => [
            'no_telepon' => '081000000003',
            'email' => 'demo.apoteker@sehatly.test',
            'nama' => 'Demo Apoteker Sehatly',
            'tipe' => 'apoteker',
            'role' => 'apoteker',
            'uuid' => '00000000-0000-4000-8000-000000000003',
            'kata_sandi' => self::KATA_SANDI_APOTEKER,
            'hash' => '$2y$10$nSQAcWX/4E77GTtCAwT1EuVnwdq.4hEtRJuWFELM9SOCRKFwhqz8W',
        ],
    ];

    /**
     * `dokter_jadwal.tipe_layanan` is a three-value ENUM (`:474`) and `chat` is
     * not one of them, so an online window is `online`.
     */
    private const JADWAL_TIPE_LAYANAN = 'online';

    /**
     * The consultation window, as `TIME` wall clocks.
     *
     * `08:00:00`-`12:00:00` at `durasi_slot_menit = 15` publishes sixteen slots a
     * day, which is enough for a demonstrator to pick one and for the quota of
     * ten not to be the thing that blocks a second booking.
     */
    private const JADWAL_JAM_MULAI = '08:00:00';

    private const JADWAL_JAM_SELESAI = '12:00:00';

    private const JADWAL_DURASI_MENIT = 15;

    private const JADWAL_KUOTA = 10;

    /**
     * `berlaku_mulai` is `DATE NOT NULL` (`:480`) and `SlotAvailabilityService`
     * publishes a window only when `berlaku_mulai <= $tanggal`, so an open-ended
     * window from a fixed past day is what makes a slot bookable on ANY date a
     * demonstrator picks. `berlaku_sampai` is left NULL - the column is
     * nullable and NULL is the open-ended case the service reads.
     */
    private const JADWAL_BERLAKU_MULAI = '2020-01-01';

    /**
     * `dokter.str_berlaku_sampai` and `sip_berlaku_sampai` are the two dates
     * `StrBerlaku` reads, and `2099-12-31` is the same value the project's own
     * test fixtures use, so the demo doctor is never excluded for a licence.
     */
    private const STR_SAMPAI = '2099-12-31';

    /**
     * `master_spesialisasi.kode` for the demo doctor's one specialisation.
     *
     * `SpesialisasiSeeder` seeds `UMUM`, and `dokter_spesialisasi.is_utama` is
     * `TINYINT(1) NOT NULL DEFAULT 0` (`:441`) so it is written explicitly: a
     * primary specialisation has to be opted into rather than inherited.
     */
    private const SPESIALISASI_KODE = 'UMUM';

    /**
     * The `apotek_stok` drugs, by `master_obat.kode_obat` (`:1308`-`:1317`).
     *
     * `OBT-0002` Amoxicillin and `OBT-0005` Metformin are ALSO the pair
     * `DevFixtureSeeder` records an `obat_interaksi` between, and `OBT-0002` is
     * the drug the demo patient's recorded allergy names. One basket therefore
     * exercises all three interaction sources - drug/drug, history and allergy -
     * without this seeder asserting a single clinical claim of its own.
     *
     * @var list<string>
     */
    private const OBAT_STOK = ['OBT-0002', 'OBT-0005'];

    /** The stock the demo pharmacy holds for each of {@see OBAT_STOK}. */
    private const STOK_JUMLAH = 500;

    /** The allergy the demo patient carries, and how severe it is recorded. */
    private const ALERGI_NAMA = 'Amoxicillin';

    /**
     * `anafilaksis` is a `pasien_alergi.keparahan` member (`:280`) which
     * {@see ObatInteraksiService} maps to the interaction
     * severity `kontraindikasi` - the ONLY severity that makes a doctor's
     * acknowledgement (`catatan_dodio`) MANDATORY. That is the override journey,
     * and an allergy the patient already has is the one interaction a fixture
     * can assert honestly: it is a fact about the DEMO PATIENT, not a claim
     * about the two drugs.
     */
    private const ALERGI_KEPARAHAN = 'anafilaksis';

    /**
     * The demo accounts, keyed by {@see AKUN_PASIEN} and its two siblings.
     *
     * The public accessor, so a test or a README can state a demo login
     * without hard-coding a phone number in a second place.
     *
     * @return array{no_telepon: string, email: string, nama: string, tipe: string, role: string, uuid: string, kata_sandi: string, hash: string}
     */
    public static function akun(string $nama): array
    {
        if (! isset(self::AKUN[$nama])) {
            throw new RuntimeException("DemoDataSeeder::akun() was given [{$nama}], which is not one of: "
                .implode(', ', array_keys(self::AKUN)).'.');
        }

        return self::AKUN[$nama];
    }

    /**
     * The account keys this class creates, in the order it creates them.
     *
     * @return list<string>
     */
    public static function namaAkun(): array
    {
        return array_keys(self::AKUN);
    }

    /**
     * Run the demo seed.
     *
     * The production guard is the FIRST statement, so a production database is
     * never written to even partially.
     */
    public function run(): void
    {
        if (! $this->bolehJalan()) {
            // The LOG, and not `$this->command`, because `seed()` gives a seeder a
            // mocked `OutputStyle` under the test suite and writing through it
            // from a test is a Mockery error rather than a message. A seeder that
            // skips silently in production is a seeder nobody can tell is
            // skipping, so this line is the record.
            Log::warning('DemoDataSeeder dilewati: environment ini production, sehingga akun demo tidak dibuat.');

            return;
        }

        // The `users` rows FIRST: every other write in this class resolves its
        // owner by `no_telepon`, so nothing can be written before the accounts
        // exist.
        $this->tulisAkun();

        $pasienId = $this->tulisPasien();
        $dokterId = $this->tulisDokter();
        $apotekId = $this->tulisApotek();

        $this->tulisSpesialisasi($dokterId);
        $this->tulisJadwal($dokterId);
        $this->tulisStok($apotekId);
        $this->tulisAlergi($pasienId);
    }

    /**
     * May this seeder write at all?
     *
     * `app()->environment('production')` rather than a config flag, so a
     * deployment that forgets the flag is still safe.
     */
    private function bolehJalan(): bool
    {
        return ! app()->environment('production');
    }

    // =================================================================
    // users + user_roles
    // =================================================================

    /**
     * The three `users` rows and their three `user_roles` grants.
     *
     * `no_telepon` is chosen over `email` as the conflict target because it is
     * `NOT NULL UNIQUE` (`:137`) while `email` is nullable-unique (`:136`) - two
     * rows may both hold `NULL` there, so it cannot carry an identity. The
     * update list deliberately EXCLUDES nothing: a drifted row is repaired
     * rather than preserved, which is the reason `RbacSeeder` chose `upsert`
     * over `insertOrIgnore` and the reason this seeder does the same.
     *
     * The role grants go through {@see RoleAssigner}, which is
     * `insertOrIgnore` on the composite primary key, so a re-run grants nothing
     * twice and never revokes a grant it did not make.
     */
    private function tulisAkun(): void
    {
        $rows = [];

        foreach (self::AKUN as $akun) {
            $rows[] = [
                'uuid' => $akun['uuid'],
                'nama_lengkap' => $akun['nama'],
                'email' => $akun['email'],
                'no_telepon' => $akun['no_telepon'],
                'kata_sandi_hash' => $akun['hash'],
                'tipe' => $akun['tipe'],
                'status' => 'aktif',
                'bahasa' => 'id',
                'telepon_terverifikasi' => 1,
                'email_terverifikasi' => 1,
            ];
        }

        DB::table('users')->upsert(
            $rows,
            ['no_telepon'],
            ['uuid', 'nama_lengkap', 'email', 'kata_sandi_hash', 'tipe', 'status', 'bahasa', 'telepon_terverifikasi', 'email_terverifikasi'],
        );

        $assigner = app(RoleAssigner::class);

        foreach (self::AKUN as $akun) {
            $assigner->assign($this->userId($akun['no_telepon']), $akun['role']);
        }
    }

    /**
     * The `users` row id for one demo account's phone number.
     *
     * @throws RuntimeException when the row is absent, which can only mean an
     *                          earlier statement in this class failed
     */
    private function userId(string $noTelepon): int
    {
        $id = (int) DB::table('users')->where('no_telepon', $noTelepon)->value('id');

        if ($id === 0) {
            throw new RuntimeException("DemoDataSeeder: `users` has no row for no_telepon [{$noTelepon}].");
        }

        return $id;
    }

    // =================================================================
    // pasien
    // =================================================================

    /**
     * The demo patient's `pasien` row, upserted on `user_id`.
     *
     * The four master ids are resolved by NAME rather than hard-coded, exactly as
     * {@see DevFixtureSeeder} does, so a renumbered master table fails loudly
     * here instead of silently mislabelling a patient. `nik` and `nomor_kk` are
     * **not in the row at all** - see the class docblock.
     */
    private function tulisPasien(): int
    {
        $akun = self::AKUN[self::AKUN_PASIEN];

        DB::table('pasien')->upsert([[
            'user_id' => $this->userId($akun['no_telepon']),
            'nomor_rm' => 'RM-DEMO-000001',
            'jenis_kelamin' => 'P',
            'tanggal_lahir' => '1992-04-09',
            'tempat_lahir' => 'Bandung',
            'golongan_darah_id' => $this->masterId('master_golongan_darah', 'kode', 'B'),
            'rhesus' => 'positif',
            'agama_id' => $this->masterId('master_agama', 'nama', 'Islam'),
            'pendidikan_id' => $this->masterId('master_pendidikan', 'nama', 'Sarjana (S1)'),
            'pekerjaan' => 'Karyawan/swasta (data demo)',
            'status_pernikahan_id' => $this->masterId('master_status_pernikahan', 'nama', 'menikah'),
            // The address is the SNAPSHOT `PesananObatService` takes for
            // `pesanan_obat.alamat_kirim`, because that field is `prohibited` on
            // the checkout request and derived from the profile. A demo order
            // with no address is a demo order that cannot be placed.
            'alamat_lengkap' => 'Jl. Demo Sehatly No. 1, Bandung, Jawa Barat 40115',
            'kode_pos' => '40115',
            'tinggi_badan_cm' => '165.0',
            'berat_badan_kg' => '58.50',
        ]], ['user_id'], [
            'nomor_rm', 'jenis_kelamin', 'tanggal_lahir', 'tempat_lahir', 'golongan_darah_id',
            'rhesus', 'agama_id', 'pendidikan_id', 'pekerjaan', 'status_pernikahan_id',
            'alamat_lengkap', 'kode_pos', 'tinggi_badan_cm', 'berat_badan_kg',
        ]);

        return (int) DB::table('pasien')->where('user_id', $this->userId($akun['no_telepon']))->value('id');
    }

    // =================================================================
    // dokter + dokter_spesialisasi + dokter_jadwal
    // =================================================================

    /**
     * The demo doctor's `dokter` row, upserted on `user_id`.
     *
     * `status_verifikasi = 'terverifikasi'` and `tersedia_telemedisin = 1` are
     * the other two of the three predicates `v_dokter_katalog` filters on
     * (`telemedicine_test.sql:1170`-`:1187`), and all three are written
     * explicitly because a `dokter` row inserted with the column defaults is
     * INVISIBLE to the directory - the exact trap
     * {@see DevFixtureSeeder} documents. `str_berlaku_sampai` is
     * {@see STR_SAMPAI} so `StrBerlaku` never excludes the doctor.
     */
    private function tulisDokter(): int
    {
        $akun = self::AKUN[self::AKUN_DOKTER];

        DB::table('dokter')->upsert([[
            'user_id' => $this->userId($akun['no_telepon']),
            'tipe' => 'dokter_umum',
            'nomor_str' => 'STR-DEMO-0001',
            'str_berlaku_sampai' => self::STR_SAMPAI,
            'nomor_sip' => 'SIP-DEMO-0001',
            'sip_berlaku_sampai' => self::STR_SAMPAI,
            'pengalaman_tahun' => 9,
            'bio' => 'Profil dokter demo untuk pengujian telemedicine (data fiktif).',
            'biaya_konsultasi_online' => '50000.00',
            'durasi_default_menit' => self::JADWAL_DURASI_MENIT,
            'rating_rata_rata' => '4.80',
            'jumlah_ulasan' => 210,
            'jumlah_konsultasi' => 640,
            'tersedia_telemedisin' => 1,
            'status_verifikasi' => 'terverifikasi',
            'status_aktif' => 1,
        ]], ['user_id'], [
            'tipe', 'nomor_str', 'str_berlaku_sampai', 'nomor_sip', 'sip_berlaku_sampai',
            'pengalaman_tahun', 'bio', 'durasi_default_menit', 'rating_rata_rata',
            'jumlah_ulasan', 'jumlah_konsultasi', 'tersedia_telemedisin',
            'status_verifikasi', 'status_aktif',
        ]);

        return (int) DB::table('dokter')->where('user_id', $this->userId($akun['no_telepon']))->value('id');
    }

    /**
     * One `dokter_spesialisasi` row, upserted on the pair the DDL makes unique.
     *
     * `uq_dokter_spes (dokter_id, spesialisasi_id)` at `:444` is the only
     * uniqueness this table has, so it is both the conflict target and the
     * natural key.
     */
    private function tulisSpesialisasi(int $dokterId): void
    {
        DB::table('dokter_spesialisasi')->upsert([[
            'dokter_id' => $dokterId,
            'spesialisasi_id' => $this->masterId('master_spesialisasi', 'kode', self::SPESIALISASI_KODE),
            'is_utama' => 1,
        ]], ['dokter_id', 'spesialisasi_id'], ['is_utama']);
    }

    /**
     * SEVEN `dokter_jadwal` rows, one per weekday `0`-`6`.
     *
     * ## Why every weekday, and not one
     *
     * `dokter_jadwal.hari` is `TINYINT UNSIGNED NOT NULL COMMENT '0=Minggu s.d.
     * 6=Sabtu'` (`:475`) and `SlotAvailabilityService` selects a window when it
     * EQUALS the requested date's day of week. One row therefore makes the demo
     * doctor bookable on one day in seven - and F3 hit an empty slot picker on a
     * date that had no row. Seven rows make every date a demonstrator might pick
     * offer a slot, which is what "the documented boot path produces a usable
     * system" has to mean for this feature.
     *
     * ## Why an existence check rather than `upsert`
     *
     * This table declares NO unique key at all - its only index is
     * `idx_jadwal (dokter_id, hari, status_aktif)` (`:487`), which is not
     * unique and does not even cover the times. `upsert` needs a unique index to
     * hang its `ON DUPLICATE KEY UPDATE` on, and adding one is a migration the
     * DDL forbids. So the natural key is stated here and checked, and only a
     * genuinely absent window is inserted. Every statement a re-run issues is
     * then a `select` or an `insert`, which is the same property
     * `RbacSeederIdempotencyTest` asserts for the RBAC kernel.
     */
    private function tulisJadwal(int $dokterId): void
    {
        for ($hari = 0; $hari <= 6; $hari++) {
            $sudah = DB::table('dokter_jadwal')
                ->where('dokter_id', $dokterId)
                ->where('hari', $hari)
                ->where('tipe_layanan', self::JADWAL_TIPE_LAYANAN)
                ->where('jam_mulai', self::JADWAL_JAM_MULAI)
                ->where('jam_selesai', self::JADWAL_JAM_SELESAI)
                ->where('berlaku_mulai', self::JADWAL_BERLAKU_MULAI)
                ->exists();

            if ($sudah) {
                continue;
            }

            DB::table('dokter_jadwal')->insert([
                'dokter_id' => $dokterId,
                // NULL = purely online, which is what `faskes_id`'s own COMMENT
                // at `:473` means and what keeps the window from depending on a
                // facility this seeder does not have to create.
                'faskes_id' => null,
                'tipe_layanan' => self::JADWAL_TIPE_LAYANAN,
                'hari' => $hari,
                'jam_mulai' => self::JADWAL_JAM_MULAI,
                'jam_selesai' => self::JADWAL_JAM_SELESAI,
                'durasi_slot_menit' => self::JADWAL_DURASI_MENIT,
                'kuota_per_sesi' => self::JADWAL_KUOTA,
                'berlaku_mulai' => self::JADWAL_BERLAKU_MULAI,
                'berlaku_sampai' => null,
                'status_aktif' => 1,
            ]);
        }
    }

    // =================================================================
    // faskes (apotek) + apotek_stok
    // =================================================================

    /**
     * The demo pharmacy, upserted on `kode_faskes`.
     *
     * `tipe = 'apotek'` is the five-value ENUM member `ApotekStokService::apotek()`
     * requires, and `kode_faskes VARCHAR(20)` is `UNIQUE` (`:362`) so it is both
     * the identity and the conflict target. The name says DEMO in plain text so
     * a screenshot of a demo order cannot be mistaken for a real pharmacy.
     */
    private function tulisApotek(): int
    {
        $provinsi = DB::table('master_provinsi')->where('kode', '31')->value('id');

        if ($provinsi === null) {
            throw new RuntimeException('DemoDataSeeder: master_provinsi has no kode 31 (DKI Jakarta). Run MasterWilayahSeeder first.');
        }

        DB::table('faskes')->upsert([[
            'kode_faskes' => 'FASKES-DEMO-001',
            'nama' => 'Apotek Demo Sehatly (data fiktif)',
            'tipe' => 'apotek',
            'alamat' => 'Jl. Demo Apotek No. 2, Bandung, Jawa Barat',
            'provinsi_id' => (int) $provinsi,
            'telepon' => '0225550001',
            'email' => 'apotek.demo@sehatly.test',
            'status_aktif' => 1,
        ]], ['kode_faskes'], ['nama', 'tipe', 'alamat', 'provinsi_id', 'telepon', 'email', 'status_aktif']);

        return (int) DB::table('faskes')->where('kode_faskes', 'FASKES-DEMO-001')->value('id');
    }

    /**
     * `apotek_stok` for the drugs {@see OBAT_STOK} names, upserted on the DDL
     * pair.
     *
     * `UNIQUE KEY uq_stok (apotek_id, obat_id)` at `:840` is the only reason
     * this can be an `upsert` at all, and it is also the lock the guarded
     * decrement in {@see ApotekStokService} addresses.
     * `harga_jual` is copied from the catalogue row rather than invented, so the
     * order total a demo produces is the catalogue price.
     */
    private function tulisStok(int $apotekId): void
    {
        $rows = [];

        foreach (self::OBAT_STOK as $kode) {
            $obat = DB::table('master_obat')->where('kode_obat', $kode)->first();

            if ($obat === null) {
                throw new RuntimeException("DemoDataSeeder: master_obat has no kode_obat [{$kode}]. Run ObatSeeder first.");
            }

            $rows[] = [
                'apotek_id' => $apotekId,
                'obat_id' => (int) $obat->id,
                'jumlah_stok' => self::STOK_JUMLAH,
                'stok_minimum' => 10,
                'harga_jual' => (string) $obat->harga_jual,
            ];
        }

        DB::table('apotek_stok')->upsert($rows, ['apotek_id', 'obat_id'], ['jumlah_stok', 'stok_minimum', 'harga_jual']);
    }

    // =================================================================
    // pasien_alergi
    // =================================================================

    /**
     * The demo patient's recorded drug allergy.
     *
     * Like `dokter_jadwal`, this table has no unique key - its only index is the
     * implicit one on the `pasien_id` foreign key - so the natural key is
     * `(pasien_id, nama_alergen)` and it is checked rather than upserted.
     *
     * ## Why an allergy and not a new `obat_interaksi` row
     *
     * {@see DevFixtureSeeder} records the reason it leaves
     * `obat_interaksi.deskripsi` NULL: "a clinical interaction description is
     * not something a fixture should assert, and inventing one would be a
     * fabricated medical claim in a repository." This class holds to the same
     * rule and takes the *same* severity route without adding a drug/drug claim:
     * `pasien_alergi.keparahan = 'anafilaksis'` maps to the interaction severity
     * `kontraindikasi` in {@see ObatInteraksiService}, which
     * is the only severity that makes the doctor's acknowledgement MANDATORY -
     * which is the override journey F3 could not exercise.
     */
    private function tulisAlergi(int $pasienId): void
    {
        $sudah = DB::table('pasien_alergi')
            ->where('pasien_id', $pasienId)
            ->where('nama_alergen', self::ALERGI_NAMA)
            ->exists();

        if ($sudah) {
            return;
        }

        DB::table('pasien_alergi')->insert([
            'pasien_id' => $pasienId,
            'tipe_alergen' => 'obat',
            'nama_alergen' => self::ALERGI_NAMA,
            'reaksi' => 'Rasa tidak nyaman pada kulit (data demo).',
            'keparahan' => self::ALERGI_KEPARAHAN,
            'dicatat_oleh_user_id' => null,
        ]);
    }

    // =================================================================
    // master lookups
    // =================================================================

    /**
     * A master row's id, by natural key, or a loud failure.
     *
     * A fixture that silently inserted a `NULL` because a master table was
     * empty would produce a demo patient with no religion and a green
     * `verify-schema`, which is exactly the `misleading_success_output` class
     * this project keeps refusing.
     *
     * @throws RuntimeException
     */
    private function masterId(string $tabel, string $kolom, string $nilai): int
    {
        $id = DB::table($tabel)->where($kolom, $nilai)->value('id');

        if ($id === null) {
            throw new RuntimeException("DemoDataSeeder: `{$tabel}` has no `{$kolom}` = [{$nilai}]. Run the master seeders first.");
        }

        return (int) $id;
    }
}
