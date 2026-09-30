<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F-009 - the bulk notification read is chunked, and still audited per row
|--------------------------------------------------------------------------
|
| `PUT /api/v1/notifikasi/baca-semua` used to read every unread id and then
| re-fetch and save each row one at a time: O(unread) round trips inside a single
| request, which a large inbox turns into thousands of statements. F-009 walks the
| unread rows 500 at a time with `chunkById()` and wraps each chunk in its own
| transaction, while keeping the per-row `save()` that the global `AuditObserver`
| depends on.
|
| The assertion that matters is the SELECT COUNT, not the wall time: it is the
| difference between O(unread) and O(unread / 500), it is measurable, and it fails
| the moment somebody removes the chunking. The audit assertion is the other half:
| chunking must not become a reason to swap the model save for a builder `update`,
| which fires no event and would leave this table's writes unaudited.
|
| Fixtures are local (prefix `nq`), for the reason the other suites give: a helper
| shared with another file is a helper whose meaning can move under this one.
|
*/

// ------------------------------------------------------------------ helpers

/** A `users` row of a given `tipe`, carrying `$role`. */
function nqUser(string $tipe, string $role): User
{
    $id = (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => 'Pengguna Notifikasi '.Str::upper(Str::random(4)),
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);

    app(RoleAssigner::class)->assign($id, $role);

    return User::query()->findOrFail($id);
}

/**
 * `$jumlah` unread notifications for `$userId`. `tipe` is NOT NULL with no default
 * (`telemedicine_test.sql:1041`), so it is set explicitly; `judul` and `isi` are the
 * two other NOT NULL text columns.
 */
function nqNotifikasi(int $userId, int $jumlah): void
{
    $rows = [];

    for ($i = 1; $i <= $jumlah; $i++) {
        $rows[] = [
            'user_id' => $userId,
            'judul' => 'Notifikasi '.$i,
            'isi' => 'Isi notifikasi '.$i,
            'tipe' => 'sistem',
            'dibuat_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('notifikasi')->insert($chunk);
    }
}

/** Act as `$user` with a real Sanctum bearer token. */
function nqAs(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken(
        $user->createToken('notifikasi-bound-test', ['*'], now()->addHour())->plainTextToken,
    );
}

// ------------------------------------------------------------------- setup

beforeEach(function (): void {
    // `notifikasi.lihat` is granted to `pasien`, so the route's `permission:` guard
    // resolves and the test measures the endpoint rather than the seeder.
    $this->seed(RbacSeeder::class);
});

// ------------------------------------------------------------------- tests

test('baca-semua memproses inbox besar per-chunk dan tetap menulis audit per perubahan', function (): void {
    $user = nqUser('pasien', 'pasien');
    nqNotifikasi($user->getKey(), 501);

    $selects = 0;

    // Registered AFTER the fixtures, so the count is the endpoint's own queries.
    DB::listen(function ($query) use (&$selects): void {
        $sql = strtolower(trim($query->sql));

        if (str_starts_with($sql, 'select') && str_contains($sql, 'notifikasi')) {
            $selects++;
        }
    });

    $response = nqAs($user)->putJson('/api/v1/notifikasi/baca-semua');

    $response->assertOk();
    expect($response->json('data.ditandai'))->toBe(501);

    expect(DB::table('notifikasi')
        ->where('user_id', $user->getKey())
        ->whereNull('dibaca_at')
        ->count())->toBe(0);

    // 501 rows / 500 per chunk = 2 chunk SELECTs. The pre-F-009 implementation read
    // one id list and then re-fetched each row: 502 selects. The bound is the whole
    // point of the change, and it fails if the chunking is removed.
    expect($selects)->toBeLessThanOrEqual(6, "expected O(unread/500) selects, got {$selects}");

    // And the audit trail is intact: one `audit_log` row per notification that
    // actually changed. A builder `update()` would make this zero.
    expect(DB::table('audit_log')
        ->where('tabel_target', 'notifikasi')
        ->where('aksi', 'update')
        ->count())->toBe(501);
});
