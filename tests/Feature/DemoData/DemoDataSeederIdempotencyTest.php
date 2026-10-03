<?php

declare(strict_types=1);

use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/demo3c-helpers.php';

/*
|--------------------------------------------------------------------------
| F3-02 - the demo seeder is idempotent, and does not destroy
|--------------------------------------------------------------------------
|
| ## Why this file exists
|
| The F2 gate raised a BLOCKER on `database/seeders/RbacSeeder.php`: it ran
| three plain `insert()` calls, so a second `db:seed` against a populated
| database was fatal with MySQL 1062. The fix made it `upsert` /
| `insertOrIgnore` on the DDL natural keys. **`DemoDataSeeder` must not
| reintroduce that defect**, and "I wrote it with upsert" is a claim rather
| than a measurement - so this file measures it the same way
| `RbacSeederIdempotencyTest` does.
|
| ## The three weaker tests that were available and were not written
|
| 1. **"a second run does not throw" is weak alone.** A seeder that silently
|    `TRUNCATE`s and re-inserts also satisfies it, and would delete a
|    developer's real bookings on a command that reads like a read.
|    `every statement a re-run issues is a read or an insert` is the assertion
|    that rules that implementation out.
| 2. **The rows must be byte-identical, not merely present.** `users.uuid` and
|    `users.kata_sandi_hash` are both written, and a bcrypt hash re-salted on
|    every run would make "nothing changed" false by construction - so the
|    seeder stores a FIXED hash and this file compares the whole snapshot.
| 3. **The 1062 itself is asserted as a permanent control**, so if a future
|    driver ever stopped raising it this file would notice rather than quietly
|    pass a seeder that had stopped being non-idempotent.
|
| ## The full chain is proved TWICE elsewhere
|
| `php artisan db:seed` run twice against a clean scratch database - the
| documented boot path, both runs, no MySQL 1062 - is in
| `.omo/evidence/F3C-notifications-and-demo-data.md`. This file is the
| standalone half: the seeder on its own, against a populated database, with
* no truncate in front of it, which is the case `DatabaseSeeder` hides.
*/

beforeEach(function (): void {
    demo3cSeed(['butuh-demo']);
    demo3cIkatOtp();
});

/**
 * Every row this seeder writes, every column, in a deterministic order.
 *
 * `id` is included deliberately: a re-seed that renumbered `users` would break
 * every `user_roles`, `pasien`, `dokter` and `user_devices` foreign key
 * pointing at the old value, and a name-only comparison would not see it.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function demo3cSnapshot(): array
{
    $tabel = [
        'users' => ['uuid', 'nama_lengkap', 'email', 'no_telepon', 'kata_sandi_hash', 'tipe', 'status', 'bahasa', 'telepon_terverifikasi', 'email_terverifikasi'],
        // `nik_cipher` replaced `nik` in migration 2026_10_01_000079. The demo
        // seeder writes no NIK, so the column is NULL on every row and this
        // comparison stays stable - which matters, because a `NikCipher` payload
        // embeds a random IV and would NOT be byte-identical across two runs.
        'pasien' => ['user_id', 'nomor_rm', 'nik_cipher', 'jenis_kelamin', 'tanggal_lahir', 'tempat_lahir', 'rhesus', 'pekerjaan', 'alamat_lengkap', 'kode_pos', 'tinggi_badan_cm', 'berat_badan_kg'],
        'dokter' => ['user_id', 'tipe', 'nomor_str', 'str_berlaku_sampai', 'nomor_sip', 'sip_berlaku_sampai', 'pengalaman_tahun', 'bio', 'durasi_default_menit', 'rating_rata_rata', 'jumlah_ulasan', 'jumlah_konsultasi', 'tersedia_telemedisin', 'status_verifikasi', 'status_aktif'],
        'dokter_spesialisasi' => ['dokter_id', 'spesialisasi_id', 'is_utama'],
        'dokter_jadwal' => ['dokter_id', 'faskes_id', 'tipe_layanan', 'hari', 'jam_mulai', 'jam_selesai', 'durasi_slot_menit', 'kuota_per_sesi', 'berlaku_mulai', 'berlaku_sampai', 'status_aktif'],
        'faskes' => ['kode_faskes', 'nama', 'tipe', 'alamat', 'telepon', 'email', 'status_aktif'],
        'apotek_stok' => ['apotek_id', 'obat_id', 'jumlah_stok', 'stok_minimum', 'harga_jual'],
        'pasien_alergi' => ['pasien_id', 'tipe_alergen', 'nama_alergen', 'reaksi', 'keparahan', 'dicatat_oleh_user_id'],
        'user_roles' => ['user_id', 'role_id'],
    ];

    $snapshot = [];

    foreach ($tabel as $nama => $kolom) {
        $baris = DB::table($nama)->select($kolom)->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        // Sorted in PHP rather than by `orderBy`, because a table with NO unique
        // key (`dokter_jadwal`) and a `select` list of several columns would make
        // a single-column `ORDER BY` an unstable order - and an unstable order
        // makes a byte-identical comparison fail for reasons that have nothing
        // to do with the seeder.
        usort(
            $baris,
            static fn (array $a, array $b): int => strcmp(
                (string) json_encode($a),
                (string) json_encode($b),
            ),
        );

        $snapshot[$nama] = $baris;
    }

    return $snapshot;
}

/**
 * Run {@see DemoDataSeeder} with the query log recording, and return the SQL it
 * emitted.
 *
 * @return list<string>
 */
function demo3cJalankanDenganLog($test): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $test->seed(DemoDataSeeder::class);
    } finally {
        DB::disableQueryLog();
    }

    return array_map(
        static fn (array $entry): string => (string) $entry['query'],
        DB::getQueryLog(),
    );
}

// =====================================================================
// The defect this file exists to pin
// =====================================================================

test('the defect is real: a plain re-insert of a seeded demo account is MySQL 1062', function (): void {
    $this->seed(DemoDataSeeder::class);

    $akun = DemoDataSeeder::akun(DemoDataSeeder::AKUN_PASIEN);

    expect(DB::table('users')->where('no_telepon', $akun['no_telepon'])->count())->toBe(1);

    $insertLagi = fn (): int => DB::table('users')->insert([
        'uuid' => '00000000-0000-4000-8000-0000000000ff',
        'nama_lengkap' => 'Percobaan kedua',
        'no_telepon' => $akun['no_telepon'],
        'kata_sandi_hash' => $akun['hash'],
        'tipe' => 'pasien',
        'status' => 'aktif',
    ]);

    expect($insertLagi)->toThrow(QueryException::class, '1062');

    // The join table's duplicate is a composite PRIMARY KEY rather than a
    // unique index, and it is the other 1062 a naive re-run would hit.
    $pair = DB::table('user_roles')->orderBy('user_id')->orderBy('role_id')->first();

    expect($pair)->not->toBeNull();

    $insertLagi = fn (): int => DB::table('user_roles')->insert([
        'user_id' => (int) $pair->user_id,
        'role_id' => (int) $pair->role_id,
    ]);

    expect($insertLagi)->toThrow(QueryException::class, '1062');
});

// =====================================================================
// The idempotency
// =====================================================================

test('seeding DemoDataSeeder a second time against the same populated tables succeeds', function (): void {
    $this->seed(DemoDataSeeder::class);

    $setelahPertama = demo3cSnapshot();

    // The call that would have been fatal.
    $this->seed(DemoDataSeeder::class);

    expect(demo3cSnapshot())->toBe($setelahPertama);
});

test('seeding DemoDataSeeder repeatedly is stable, and never grows a table', function (): void {
    $this->seed(DemoDataSeeder::class);

    $setelahPertama = demo3cSnapshot();

    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    expect(demo3cSnapshot())->toBe($setelahPertama);

    // Absolute numbers, so a trim of the fixture is a visible edit here rather
    // than a seeder still idempotent over quietly fewer rows.
    $pasienUserId = (int) DB::table('users')
        ->where('no_telepon', DemoDataSeeder::akun(DemoDataSeeder::AKUN_PASIEN)['no_telepon'])
        ->value('id');

    expect(DB::table('pasien')->where('user_id', $pasienUserId)->count())->toBe(1)
        ->and(DB::table('user_roles')->where('user_id', $pasienUserId)->count())->toBe(1)
        ->and(DB::table('dokter_jadwal')->count())->toBe(7)
        ->and(DB::table('apotek_stok')->count())->toBe(2)
        ->and(DB::table('pasien_alergi')->count())->toBe(1);
});

test('a second run leaves no duplicate account, schedule, stock row or grant', function (): void {
    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    foreach (DemoDataSeeder::namaAkun() as $nama) {
        $akun = DemoDataSeeder::akun($nama);

        expect(DB::table('users')->where('no_telepon', $akun['no_telepon'])->count())->toBe(1)
            ->and(DB::table('users')->where('email', $akun['email'])->count())->toBe(1)
            ->and(DB::table('users')->where('uuid', $akun['uuid'])->count())->toBe(1);
    }

    // `(dokter_id, hari, jam_mulai, jam_selesai, berlaku_mulai)` has NO unique
    // key in the DDL, so "no duplicate" is a count of DISTINCT tuples rather
    // than a count of rows - which is the only way it can mean anything.
    $jadwalUnik = DB::table('dokter_jadwal')
        ->selectRaw('DISTINCT dokter_id, hari, jam_mulai, jam_selesai, berlaku_mulai')
        ->get()
        ->count();

    expect(DB::table('dokter_jadwal')->count())->toBe($jadwalUnik)
        ->and(DB::table('apotek_stok')->count())
        ->toBe(DB::table('apotek_stok')->selectRaw('DISTINCT apotek_id, obat_id')->get()->count())
        ->and(DB::table('pasien_alergi')->count())
        ->toBe(DB::table('pasien_alergi')->selectRaw('DISTINCT pasien_id, nama_alergen')->get()->count());
});

// =====================================================================
// Why upsert rather than delete-then-insert
// =====================================================================

test('every statement a re-run issues is a read or an insert', function (): void {
    $this->seed(DemoDataSeeder::class);

    $sebelum = demo3cSnapshot();

    $sql = demo3cJalankanDenganLog($this);

    // `select` is expected and required: the natural-key lookups are how the
    // seeder finds the rows it already wrote, which is what keeps a pre-existing
    // row's id - and therefore every foreign key pointing at it - stable.
    $bukanBacaAtauInsert = array_values(array_filter(
        $sql,
        static fn (string $statement): bool => ! str_starts_with(strtolower(ltrim($statement)), 'insert')
            && ! str_starts_with(strtolower(ltrim($statement)), 'select'),
    ));

    // Stronger than "no DELETE and no UPDATE": a re-run adds nothing, removes
    // nothing and changes no id. It also rules out the delete-then-insert shape,
    // which would satisfy every other test in this file.
    expect($bukanBacaAtauInsert)->toBe([], 'A re-run must not rewrite or empty a table it already seeded: '.implode(' | ', $bukanBacaAtauInsert));

    expect(demo3cSnapshot())->toBe($sebelum);
});

test('a re-run never empties a table this seeder did not write', function (): void {
    $this->seed(DemoDataSeeder::class);

    // A developer's own booking, in a table this seeder never touches. If the
    // seeder truncated anything on a re-run, this row would be the casualty and
    // the assertion below is what catches it.
    $pasienId = (int) DB::table('pasien')->orderBy('id')->value('id');
    $dokterId = (int) DB::table('dokter')->orderBy('id')->value('id');
    $userId = (int) DB::table('users')->orderBy('id')->value('id');

    $bookingId = (int) DB::table('booking')->insertGetId([
        'nomor_booking' => 'BKPANJANG01',
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'dibuat_oleh_user_id' => $userId,
        'tipe_layanan' => 'chat',
        'tanggal_kunjungan' => '2030-01-01',
        'slot_mulai' => '09:00:00',
        'slot_selesai' => '09:15:00',
        'status' => 'menunggu_pembayaran',
    ]);

    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    expect(DB::table('booking')->where('id', $bookingId)->count())->toBe(1);
});

test('a drifted demo account is repaired by the next run, without renumbering it', function (): void {
    $this->seed(DemoDataSeeder::class);

    $akun = DemoDataSeeder::akun(DemoDataSeeder::AKUN_PASIEN);
    $userId = (int) DB::table('users')->where('no_telepon', $akun['no_telepon'])->value('id');

    // The reason `users` is UPSERTED and not `insertOrIgnore`d: a matching
    // `no_telepon` is not proof of a correct row, so a stale name is repaired
    // rather than kept while the seeder reports success.
    DB::table('users')->where('id', $userId)->update(['nama_lengkap' => 'Nama yang tidak ditulis siapa pun']);

    expect((string) DB::table('users')->where('id', $userId)->value('nama_lengkap'))
        ->toBe('Nama yang tidak ditulis siapa pun');

    $this->seed(DemoDataSeeder::class);

    expect((string) DB::table('users')->where('id', $userId)->value('nama_lengkap'))->toBe($akun['nama']);

    // And the id did not move: `user_roles`, `pasien` and `user_devices` all
    // reference `users.id`, so a renumbered row would silently re-point them.
    expect((int) DB::table('users')->where('no_telepon', $akun['no_telepon'])->value('id'))->toBe($userId)
        ->and(DB::table('pasien')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('user_roles')->where('user_id', $userId)->count())->toBe(1);
});

test('the demo seeder refuses to run in production', function (): void {
    // The guard is a real branch, so it is exercised rather than assumed: a
    // seeder that silently skips in production is a seeder nobody can tell is
    // skipping. The environment is RESTORED in a `finally` because
    // `detectEnvironment()` mutates the container, and a leak here would change
    // every test that ran after it.
    $asal = app()->environment();

    app()->detectEnvironment(fn (): string => 'production');

    try {
        // `$this->seed()`, NOT `$this->artisan('db:seed', ...)`: `db:seed` is a
        // `ConfirmableTrait` command and, in `production`, `TestCase::seed()`
        // would reach its `Do you really wish to run this command?` prompt -
        // which is a Mockery `askQuestion()` on a console double with no
        // expectations, and a failure that says nothing about the seeder.
        // `--force` is the documented way to say yes without being asked.
        $this->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])
            ->assertSuccessful();
    } finally {
        app()->detectEnvironment(fn (): string => $asal);
    }

    expect(DB::table('users')
        ->where('no_telepon', DemoDataSeeder::akun(DemoDataSeeder::AKUN_PASIEN)['no_telepon'])
        ->count())->toBe(0);

    // The seeder is still usable afterwards, which is the half a leaked
    // environment would break.
    $this->seed(DemoDataSeeder::class);

    expect(DB::table('users')
        ->where('no_telepon', DemoDataSeeder::akun(DemoDataSeeder::AKUN_PASIEN)['no_telepon'])
        ->count())->toBe(1);
});
