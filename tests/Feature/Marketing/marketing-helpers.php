<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-9 marketing fixtures - shared by every `tests/Feature/Marketing/*` file
|--------------------------------------------------------------------------
|
| Required once per file the way `kurir-helpers.php`, `f14-helpers.php` and
| the other per-directory helper files are: `beforeEach()` lives in the TEST
| FILES, because a hook declared here would register only for the first file
| that requires it (the failure `pesanan46-helpers.php`'s tail documents).
|
| The prefix is `mkt` - `kur`, `po46`, `rx39`, `rx40`, `inv44`, `bkc`, `kns`,
| `slot`, `f14` and `hero` are taken, and Pest loads every test file into ONE
| process.
*/

/**
 * One `users` row, optionally with `$role` granted through the seeded
 * catalogue.
 *
 * The NOT NULL columns with no default are `uuid`, `nama_lengkap`,
 * `no_telepon`, `kata_sandi_hash`, and `tipe` (the defaulted eight-value
 * ENUM) is set explicitly anyway because the whole point of this directory
 * is the account's type.
 *
 * @param  string  $tipe  one of `users.tipe` ENUM members
 * @param  string|null  $role  one of `RbacCatalog::ROLES`, or null for none
 */
function mktUser(string $tipe = 'marketing', ?string $role = 'marketing'): User
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
 * Bearer headers for `$user`, with a real Sanctum token.
 *
 * The expiry is ABSOLUTE (one year from now) for the reason `kurAs()` gives:
 * a relative token would age out silently if any file in the same process had
 * frozen the clock and not yet released it, and that failure reads as a broken
 * endpoint rather than as a fixture that aged.
 *
 * @return array<string, string>
 */
function mktAs(User $user): array
{
    app('auth')->forgetGuards();

    $token = $user->createToken('mkt', ['*'], Carbon::now()->addYear())->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * One `artikel_kategori` row (required because `artikel.kategori_id` is a
 * NOT NULL foreign key).
 */
function mktKategori(): int
{
    return (int) DB::table('artikel_kategori')->insertGetId([
        'nama' => 'Kesehatan Uji '.Str::upper(Str::random(6)),
        'slug' => 'kesehatan-'.Str::lower(Str::random(8)),
    ]);
}

/**
 * One `artikel` row. Defaults write a fresh kategori and a fresh penulis so
 * every call is self-contained; `$data` overrides any column.
 *
 * `penulis_user_id` is NOT NULL and foreign-keyed to `users(id)`, so a valid
 * `users` row must exist first - which this helper guarantees by default.
 *
 * @param  array<string, mixed>  $data  extra columns, e.g. `judul`, `slug`, `status`
 */
function mktArtikel(array $data = []): int
{
    return (int) DB::table('artikel')->insertGetId(array_merge([
        'kategori_id' => mktKategori(),
        'penulis_user_id' => mktUser()->getKey(),
        'judul' => 'Judul Artikel Uji '.Str::upper(Str::random(8)),
        'slug' => 'artikel-'.Str::lower(Str::random(8)),
        'ringkasan' => 'Ringkasan uji artikel.',
        'konten' => 'Konten uji artikel yang cukup panjang.',
        'cover_url' => null,
        'status' => 'draft',
        'jumlah_view' => 0,
        'published_at' => null,
        'dibuat_at' => '2026-03-11 09:00:00',
        'diubah_at' => '2026-03-11 09:00:00',
    ], $data));
}

/**
 * One `master_promo` row. `$data` overrides any column.
 *
 * @param  array<string, mixed>  $data  extra columns, e.g. `kode`, `nama`, `status_aktif`
 */
function mktPromo(array $data = []): int
{
    return (int) DB::table('master_promo')->insertGetId(array_merge([
        'kode' => 'PROMO'.Str::upper(Str::random(8)),
        'nama' => 'Promo Uji '.Str::upper(Str::random(8)),
        'tipe_diskon' => 'persen',
        'nilai' => '10.00',
        'min_transaksi' => '0.00',
        'maks_diskon' => null,
        'kuota_total' => null,
        'kuota_per_user' => 1,
        'mulai_at' => '2026-03-11 00:00:00',
        'selesai_at' => '2026-12-31 23:59:59',
        'status_aktif' => 1,
    ], $data));
}
