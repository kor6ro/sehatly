<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M6 courier fixtures - shared by every `tests/Feature/Kurir/*` file
|--------------------------------------------------------------------------
|
| Required once per file the way `f14-helpers.php`, `pesanan46-helpers.php` and
| the other per-directory helper files are: `beforeEach()` lives in the TEST
| FILES, because a hook declared here would register only for the first file
| that requires it (the failure `pesanan46-helpers.php`'s tail documents).
|
| The prefix is `kur` - `po46`, `rx39`, `rx40`, `inv44`, `bkc`, `kns`, `slot`,
| `f14` and `hero` are taken, and Pest loads every test file into ONE process.
|
| ## The `pasien` user gets NO role, and no token is ever minted for it
|
| `pesanan_obat.pasien_id` is a NOT NULL foreign key (`:100`), so every order
| fixture needs a `pasien` row, and that row needs a `users` row. The patient
| never authenticates in this directory - the courier does - so the account is
| a bare foreign-key parent, `RoleAssigner` is not called for it, and the
| `roles` seeding a courier needs is the test file's own `beforeEach` job.
|
| ## The order fixture is `tipe = 'resep_dokter'` with `resep_id` NULL, and holds MONEY
|
| The column's DEFAULT is already `resep_dokter` and nothing in this module
| reads `resep_id` (the courier payload withholds it - that is the point of the
| module), so a null prescription link on a resep-typed row is the fixture's
| honest state rather than a shortcut: there is no `resep` in the courier's
| world at all. The three money columns are written NON-ZERO on purpose - the
| privacy test asserts the response withholds values the database really has,
| which a zero-filled fixture could not prove (a missing key and an absent
| value would be indistinguishable).
*/

/**
 * One `users` row, optionally with `$role` granted through the seeded catalogue.
 *
 * The NOT NULL columns with no default are the same five `po46User()` writes:
 * `uuid`, `nama_lengkap`, `no_telepon`, `kata_sandi_hash` and the defaulted
 * eight-value `tipe` ENUM is set explicitly anyway because the whole point of
 * this directory is the account's type.
 */
function kurUser(string $tipe = 'kurir', ?string $role = 'kurir'): User
{
    $id = (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => 'Pengguna Uji '.Str::upper(Str::random(6)),
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
 * One `pasien` row for `$userId`: the three NOT NULL columns `po46Pasien()`
 * names (`jenis_kelamin`, `tanggal_lahir`, `alamat_lengkap`).
 */
function kurPasien(int $userId): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Kurir No. 7, Jakarta',
    ]);
}

/**
 * One `faskes` row - the pickup point every order fixture points at.
 *
 * Its name is recognisable in assertions, because the courier payload's
 * `apotek` block is asserted by name: a hospital row would be representable
 * too (`:365` constrains nothing), so the fixture stays `apotek` while the
 * RESOURCE documents the unconstraint.
 */
function kurApotek(): int
{
    return (int) DB::table('faskes')->insertGetId([
        'nama' => 'Apotek Uji Kurir '.Str::upper(Str::random(4)),
        'tipe' => 'apotek',
        'alamat' => 'Jl. Apotek Uji No. 2, Jakarta',
        'status_aktif' => 1,
    ]);
}

/**
 * One `pesanan_obat` row in `$status`, with its own patient and pharmacy.
 *
 * `dibuat_at`/`diubah_at` are written EXPLICITLY: the queue orders by
 * `diubah_at DESC` inside each status group, so a test proving that order must
 * control the column rather than hope for insert timing - and MySQL's
 * `ON UPDATE CURRENT_TIMESTAMP` reacts only to an UPDATE, never to the INSERT
 * that supplies a value.
 *
 * @param  array<string, mixed>  $ubah  extra columns, e.g. `diubah_at`
 */
function kurPesanan(string $status, array $ubah = []): int
{
    $pasien = kurPasien((int) kurUser('pasien', null)->getKey());

    return (int) DB::table('pesanan_obat')->insertGetId(array_merge([
        'nomor_pesanan' => 'KUR'.Str::upper(Str::random(20)),
        'pasien_id' => $pasien,
        'resep_id' => null,
        'apotek_id' => kurApotek(),
        'tipe' => 'resep_dokter',
        'alamat_kirim' => 'Jl. Tujuan Kirim No. 9, Jakarta',
        'subtotal' => '150000.00',
        'biaya_kirim' => '10000.00',
        'total' => '160000.00',
        'status' => $status,
        'dibuat_at' => '2026-03-11 09:00:00',
        'diubah_at' => '2026-03-11 09:00:00',
    ], $ubah));
}

/**
 * One `pesanan_obat_tracking` row on `$pesananId`.
 *
 * The table has no `dibuat_at`/`diubah_at` - only `waktu` - which is why this
 * helper names it explicitly rather than letting a default decide.
 *
 * @param  array<string, mixed>  $ubah
 */
function kurTracking(int $pesananId, string $status, array $ubah = []): int
{
    return (int) DB::table('pesanan_obat_tracking')->insertGetId(array_merge([
        'pesanan_obat_id' => $pesananId,
        'status' => $status,
        'keterangan' => null,
        'lokasi' => null,
        'waktu' => '2026-03-11 09:00:00',
    ], $ubah));
}

/**
 * Bearer headers for `$user`, with a real Sanctum token.
 *
 * The expiry is ABSOLUTE (one year from now) for the reason `po46As()` gives:
 * a relative token would age out silently if any file in the same process had
 * frozen the clock and not yet released it, and that failure reads as a broken
 * endpoint rather than as a fixture that aged.
 *
 * @return array<string, string>
 */
function kurAs(User $user): array
{
    app('auth')->forgetGuards();

    $token = $user->createToken('kur', ['*'], Carbon::now()->addYear())->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}
