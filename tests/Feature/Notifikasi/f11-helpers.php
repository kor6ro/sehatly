<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Notifikasi\PushDispatcher;
use App\Support\Rbac\RoleAssigner;
use App\Support\WaktuIndonesia;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F11 - shared fixtures for the preference, reminder and scheduler tests
|--------------------------------------------------------------------------
|
| `require_once`d by each test file, so any one of them is runnable on its own.
| The prefix is `f11` because Pest loads every test file into ONE process and
| these are global functions - `ntf5`, `nq`, `rx39`, `rx40`, `po46`, `pay45`,
| `inv44`, `kns`, `bkc`, `aud`, `rmd` and `skt` are already taken.
|
| Nothing here writes a `notifikasi` or `pengingat_terkirim` row: every test in
| this directory counts what the endpoint or the scheduler produced, and a
| fixture that wrote one would make the count a measurement of the fixture.
*/

/**
 * A `users` row, with the role the flow under test needs.
 *
 * `uuid` (:133), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are the NOT NULL columns with no default; the
 * password is a random unusable hash, as `DevFixtureSeeder` does.
 */
function f11User(string $nama, string $tipe = 'pasien', ?string $role = 'pasien'): User
{
    $id = (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row; `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are NOT NULL with no default.
 */
function f11Pasien(int $userId): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => $userId,
        'nomor_rm' => 'RM-F11-'.Str::upper(Str::random(8)),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji F11 No. 1, Jakarta',
    ]);
}

/**
 * A `dokter` row; the four NOT NULL columns with no default are `user_id`,
 * `tipe`, `nomor_str` and `str_berlaku_sampai` (:411-:414).
 */
function f11Dokter(int $userId): int
{
    return (int) DB::table('dokter')->insertGetId([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-F11-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
        'tersedia_telemedisin' => 1,
        'durasi_default_menit' => 15,
        'biaya_konsultasi_online' => '50000.00',
    ]);
}

/**
 * A `booking` row between the two fixtures, for the janji_temu ownership rules.
 */
function f11Booking(int $pasienId, int $dokterId, int $olehUserId): int
{
    return (int) DB::table('booking')->insertGetId([
        'nomor_booking' => 'BK-F11-'.Str::upper(Str::random(8)),
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'tipe_layanan' => 'chat',
        'tanggal_kunjungan' => WaktuIndonesia::now()->addDays(2)->format('Y-m-d'),
        'slot_mulai' => '09:00:00',
        'slot_selesai' => '09:15:00',
        'status' => 'terjadwal',
        'dibuat_oleh_user_id' => $olehUserId,
    ]);
}

/**
 * An active device with an FCM token, so `NotificationService::dorong()` has
 * something to push to. Returns the token.
 */
function f11Perangkat(int $userId, string $token = 'f11-token'): string
{
    DB::table('user_devices')->insert([
        'user_id' => $userId,
        'device_id' => 'f11-'.Str::upper(Str::random(10)),
        'platform' => 'android',
        'fcm_token' => $token,
        'aktif' => 1,
        'last_active_at' => now(),
        'dibuat_at' => now(),
    ]);

    return $token;
}

/**
 * Record a PDP consent decision directly, as the append-only ledger stores it.
 *
 * The scheduler only READS the latest row per `(user_id, jenis)`, so writing
 * the row here is the whole fixture; `disetujui_at` is NOT NULL even for a
 * refusal.
 */
function f11Setuju(int $userId, string $jenis, bool $disetujui = true): void
{
    DB::table('persetujuan_pdp')->insert([
        'user_id' => $userId,
        'jenis' => $jenis,
        'versi_dokumen' => 'v01',
        'disetujui' => $disetujui ? 1 : 0,
        'disetujui_at' => now(),
    ]);
}

/**
 * The test case, already carrying `$user`'s bearer token.
 */
function f11As(User $user): TestCase
{
    app('auth')->forgetGuards();

    $token = $user->createToken('f11', ['*'], now()->addHour())->plainTextToken;

    return test()->withHeader('Authorization', 'Bearer '.$token);
}

/**
 * A `PushDispatcher` that records every push instead of sending it.
 *
 * Bound into the container by {@see f11Push()}; the scheduler resolves it
 * through `NotificationService`'s constructor, so the observation point is the
 * real seam rather than a spy on a private method.
 */
final class F11PushPalsu implements PushDispatcher
{
    /** @var list<array<string, mixed>> */
    public array $terkirim = [];

    public function kirim(string $token, array $muatan): void
    {
        $this->terkirim[] = ['token' => $token] + $muatan;
    }
}

/**
 * Bind the fake dispatcher and return it. Call BEFORE the request/command that
 * would resolve `NotificationService`.
 */
function f11Push(): F11PushPalsu
{
    $palsu = new F11PushPalsu;

    app()->instance(PushDispatcher::class, $palsu);

    return $palsu;
}

/**
 * The number of `notifikasi` rows written for `$userId`, optionally of one tipe.
 */
function f11Notifikasi(int $userId, ?string $tipe = null): int
{
    $query = DB::table('notifikasi')->where('user_id', $userId);

    if ($tipe !== null) {
        $query->where('tipe', $tipe);
    }

    return $query->count();
}

/**
 * The number of `pengingat_terkirim` rows for `$pengingatId`.
 */
function f11Terkirim(int $pengingatId): int
{
    return DB::table('pengingat_terkirim')->where('pengingat_id', $pengingatId)->count();
}

/**
 * The canonical create payload for an obat reminder; override per test.
 *
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function f11PayloadObat(array $ubah = []): array
{
    return array_merge([
        'jenis' => 'obat',
        'judul' => 'Amoxicillin 500 mg',
        'keterangan' => '3 x 1 kapsul sesudah makan',
        'dosis' => '1 kapsul',
        'jumlah_per_hari' => 3,
        'tanggal_mulai' => WaktuIndonesia::now()->format('Y-m-d'),
        'lama_hari' => 5,
        'waktu' => ['07:00', '12:00', '19:00'],
    ], $ubah);
}
