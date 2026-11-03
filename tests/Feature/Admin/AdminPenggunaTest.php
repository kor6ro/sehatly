<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\NikMasker;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| The account-management round: `/api/v1/admin/pengguna`
|--------------------------------------------------------------------------
|
| Four routes - the directory read, the create, the status toggle and the
| password reset - behind `tipe:admin,superadmin` plus the owner-approved
| `pengguna.lihat`/`pengguna.kelola` catalogue pair (the 34th and 35th codes).
|
| **What this file proves, and why each half matters:**
|
| - The READ is permission-gated on its own code: a patient fails the party
|   gate, an `admin` without the role fails `pengguna.lihat`, and the phone in
|   every row is MASKED while `q` still matches the STORED number - the search
|   finds, the response redacts.
| - The `staf` alias expands to the five staff types rather than being a ninth
|   `users.tipe` value, so the sidebar's staff tab, the dashboard's staff count
|   and this filter answer one definition.
| - The CREATE writes `status='aktif'`, `telepon_terverifikasi=1` AND the role
|   in one transaction; `tipe` outside `TIPE_BUAT` is a 422, a duplicate number
|   is a 422 on `no_telepon`, and an `admin` minting an `admin` is a 403 that a
|   `superadmin` passes - the actor-vs-target rule on the create side.
| - The STATUS toggle revokes the target's refresh tokens on `nonaktif` and
|   leaves another account's rows alone; moving YOUR OWN status is a 422, and
|   an `admin` acting on an `admin` row is a 403.
| - The RESET answers the plaintext exactly ONCE and stores only a digest; the
|   `admin`-on-`admin` 403 holds here too.
| - Every unknown `{id}` is the uniform 404, every tokenless call the uniform
|   401, and the create lands an audit `create` row whose `data_baru` holds the
|   masked number and NO `kata_sandi_hash` key.
|
| **Fixtures come from `f14-helpers.php`** (`f14Admin`, `f14Superadmin`,
| `f14User`, `f14As`, `f14TanpaToken`, `f14BersihkanAuditPengguna`), and
| `RefreshDatabase` is bound by `tests/Pest.php`.
|
*/

require_once __DIR__.'/f14-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

/**
 * @return array<string, mixed>
 */
function pgPayload(array $ubah = []): array
{
    return array_merge([
        'nama_lengkap' => 'Staf Apotek Uji',
        'no_telepon' => '081234567000',
        'password' => 'Rahasia#12345',
        'tipe' => 'apoteker',
    ], $ubah);
}

// ------------------------------------------------------------- the directory

test('the directory answers a page of masked rows with the standard meta', function (): void {
    $staf = f14User('Apoteker Halaman', 'apoteker');

    f14As(f14Admin());

    $response = $this->getJson('/api/v1/admin/pengguna');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Daftar pengguna berhasil dimuat.')
        ->assertJsonStructure([
            'data' => ['pengguna' => [['id', 'nama_lengkap', 'no_telepon', 'email', 'tipe', 'status', 'telepon_terverifikasi', 'last_login_at', 'dibuat_at']]],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ]);

    $baris = collect($response->json('data.pengguna'));

    expect($baris->firstWhere('id', $staf->getKey())['no_telepon'])
        ->toBe(NikMasker::mask((string) $staf->no_telepon))
        // The full number appears NOWHERE in the widest projection in the
        // product - the F01 masking rule, at the only boundary that leaves the
        // server.
        ->and($response->getContent())->not->toContain((string) $staf->no_telepon);

    // `kata_sandi_hash` is not in the allow-list; a digest is a credential.
    expect($response->getContent())->not->toContain('kata_sandi_hash');
});

test('the staf alias expands to the five staff types and excludes pasien and dokter', function (): void {
    $apoteker = f14User('Apoteker Alias', 'apoteker');
    $kurir = f14User('Kurir Alias', 'kurir');
    f14Pasien();
    f14User('Dokter Alias', 'dokter');

    f14As(f14Admin());

    $ids = collect($this->getJson('/api/v1/admin/pengguna?tipe=staf')->json('data.pengguna'))
        ->pluck('id')
        ->all();

    expect($ids)->toContain($apoteker->getKey())
        ->toContain($kurir->getKey())
        ->and($ids)->not->toContain(
            User::query()->where('tipe', 'pasien')->value('id'),
        )->not->toContain(
            User::query()->where('tipe', 'dokter')->value('id'),
        );
});

test('q matches the stored phone while the row still answers masked, and a future dibuat_dari empties the page', function (): void {
    $apoteker = f14User('Apoteker Cari', 'apoteker');
    $apoteker->no_telepon = '081122334455';
    $apoteker->save();

    f14As(f14Admin());

    // The operator types the REAL number they know; the stored value is what
    // the LIKE runs against.
    $cari = $this->getJson('/api/v1/admin/pengguna?q=081122334455');
    $cari->assertOk();

    $baris = collect($cari->json('data.pengguna'))->firstWhere('id', $apoteker->getKey());

    expect($baris)->not->toBeNull()
        ->and($baris['no_telepon'])->toBe(NikMasker::mask('081122334455'))
        ->and($cari->getContent())->not->toContain('081122334455');

    // `dibuat_dari` is inclusive of the NAMED day; a date after every row's
    // `dibuat_at` empties the page rather than erroring.
    $this->getJson('/api/v1/admin/pengguna?dibuat_dari='.now()->addDay()->format('Y-m-d'))
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

test('an out-of-vocabulary tipe or status is a 422 naming the field, not an empty page', function (): void {
    f14As(f14Admin());

    $this->getJson('/api/v1/admin/pengguna?tipe=adminx')
        ->assertStatus(422)
        ->assertJsonPath('errors.tipe.0', 'The selected tipe akun is invalid.');

    $this->getJson('/api/v1/admin/pengguna?status=mengambang')
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'The selected status is invalid.');
});

test('the read is gated by the party and by pengguna.lihat: patient 403, admin without the role 403, superadmin 200', function (): void {
    // The patient holds `pasien.lihat`-shaped grants but the party gate
    // refuses the account type before any permission is consulted.
    f14As(f14Pasien()['user']);
    $this->getJson('/api/v1/admin/pengguna')->assertStatus(403);

    // The type passes the party gate; the missing `pengguna.lihat` grant is
    // what refuses it - the assertion that proves the permission does the work.
    f14As(f14User('Admin tanpa peran', 'admin'));
    $this->getJson('/api/v1/admin/pengguna')->assertStatus(403);

    f14As(f14Superadmin());
    $this->getJson('/api/v1/admin/pengguna')->assertOk();
});

// ---------------------------------------------------------------- the create

test('creating a staff account writes status aktif, a verified phone and the role in one go', function (): void {
    f14BersihkanAuditPengguna();
    f14As(f14Admin());

    $response = $this->postJson('/api/v1/admin/pengguna', pgPayload());

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Akun pengguna berhasil dibuat.')
        ->assertJsonPath('data.pengguna.tipe', 'apoteker')
        ->assertJsonPath('data.pengguna.status', 'aktif')
        ->assertJsonPath('data.pengguna.telepon_terverifikasi', true)
        ->assertJsonPath('data.pengguna.no_telepon', NikMasker::mask('081234567000'));

    $user = User::query()->where('no_telepon', '081234567000')->firstOrFail();

    expect(Hash::check('Rahasia#12345', (string) $user->kata_sandi_hash))->toBeTrue()
        ->and(DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->getKey())
            ->value('roles.nama'))->toBe('apoteker');
});

test('the phone folds +62 to 08 before validation, so the stored row and the unique guard see one value', function (): void {
    f14As(f14Admin());

    $this->postJson('/api/v1/admin/pengguna', pgPayload(['no_telepon' => '+6281234567001']))
        ->assertStatus(201);

    expect(User::query()->where('no_telepon', '081234567001')->exists())->toBeTrue();
});

test('a duplicate number is a 422 on no_telepon and no row is written', function (): void {
    $ada = f14User('Pemilik Nomor', 'apoteker');

    f14As(f14Admin());

    $sebelum = User::query()->count();

    $this->postJson('/api/v1/admin/pengguna', pgPayload(['no_telepon' => (string) $ada->no_telepon]))
        ->assertStatus(422)
        ->assertJsonPath('errors.no_telepon.0', 'The nomor telepon has already been taken.');

    expect(User::query()->count())->toBe($sebelum);
});

test('tipe outside TIPE_BUAT is a 422: superadmin seed-only, dokter via /admin/dokter, pasien self-registers', function (string $tipe): void {
    f14As(f14Superadmin());

    $this->postJson('/api/v1/admin/pengguna', pgPayload(['tipe' => $tipe]))
        ->assertStatus(422)
        ->assertJsonPath('errors.tipe.0', 'The selected tipe akun is invalid.');
})->with(['superadmin', 'dokter', 'pasien', 'perawat']);

test('an admin minting an admin is 403; a superadmin passes the same body with 201', function (): void {
    f14As(f14Admin());

    $this->postJson('/api/v1/admin/pengguna', pgPayload(['tipe' => 'admin']))
        ->assertStatus(403)
        ->assertJsonPath('message', 'Hanya superadmin yang boleh membuat akun admin.');

    expect(User::query()->where('no_telepon', '081234567000')->exists())->toBeFalse();

    f14As(f14Superadmin());

    $this->postJson('/api/v1/admin/pengguna', pgPayload(['tipe' => 'admin']))
        ->assertStatus(201)
        ->assertJsonPath('data.pengguna.tipe', 'admin');
});

test('the create is audited on users with the masked number and no hash key', function (): void {
    f14BersihkanAuditPengguna();
    f14As(f14Admin());

    $this->postJson('/api/v1/admin/pengguna', pgPayload())->assertStatus(201);

    $baris = DB::table('audit_log')
        ->where('tabel_target', 'users')
        ->where('aksi', 'create')
        ->orderByDesc('id')
        ->first();

    expect($baris)->not->toBeNull('the automatic observer must write a create row for users');

    $baru = json_decode((string) $baris->data_baru, true);

    expect($baru)->toBeArray()
        ->and($baru['no_telepon'] ?? null)->toBe(NikMasker::mask('081234567000'))
        ->and((string) $baris->data_baru)->not->toContain('081234567000')
        ->and($baru)->not->toHaveKey('kata_sandi_hash');
});

// ------------------------------------------------------------ the status gate

test('deactivating revokes that account refresh tokens and leaves another account alone', function (): void {
    $target = f14User('Kurir Nonaktif', 'kurir');
    $lain = f14User('Kurir Lain', 'kurir');

    DB::table('user_refresh_tokens')->insert([
        ['user_id' => $target->getKey(), 'token_hash' => 'hash-target', 'kedaluwarsa_at' => now()->addDay()],
        ['user_id' => $lain->getKey(), 'token_hash' => 'hash-lain', 'kedaluwarsa_at' => now()->addDay()],
    ]);

    f14As(f14Admin());

    $this->putJson('/api/v1/admin/pengguna/'.$target->getKey().'/status', ['status' => 'nonaktif'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pengguna.status', 'nonaktif');

    expect((int) DB::table('user_refresh_tokens')->where('user_id', $target->getKey())->value('dicabut'))->toBe(1)
        ->and((int) DB::table('user_refresh_tokens')->where('user_id', $lain->getKey())->value('dicabut'))->toBe(0)
        ->and($target->fresh()->status)->toBe('nonaktif');

    // And back: the two poles are the whole writable vocabulary.
    $this->putJson('/api/v1/admin/pengguna/'.$target->getKey().'/status', ['status' => 'aktif'])
        ->assertOk()
        ->assertJsonPath('data.pengguna.status', 'aktif');
});

test('a caller may not move their OWN status: 422 on status, row untouched', function (): void {
    // The self-refusal runs BEFORE the actor-vs-target rule, so it answers the
    // same way for both account types that reach this surface.
    $admin = f14Admin();

    f14As($admin);

    $this->putJson('/api/v1/admin/pengguna/'.$admin->getKey().'/status', ['status' => 'nonaktif'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'Anda tidak dapat mengubah status akun sendiri.');

    expect($admin->fresh()->status)->toBe('aktif');

    $superadmin = f14Superadmin();

    f14As($superadmin);

    $this->putJson('/api/v1/admin/pengguna/'.$superadmin->getKey().'/status', ['status' => 'nonaktif'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'Anda tidak dapat mengubah status akun sendiri.');

    expect($superadmin->fresh()->status)->toBe('aktif');
});

test('an admin acting on an admin row is 403; a superadmin passes the same body', function (): void {
    $target = f14User('Admin Target', 'admin');

    f14As(f14Admin());

    $this->putJson('/api/v1/admin/pengguna/'.$target->getKey().'/status', ['status' => 'nonaktif'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Hanya superadmin yang boleh mengubah akun admin.');

    expect($target->fresh()->status)->toBe('aktif');

    f14As(f14Superadmin());

    $this->putJson('/api/v1/admin/pengguna/'.$target->getKey().'/status', ['status' => 'nonaktif'])
        ->assertOk();

    expect($target->fresh()->status)->toBe('nonaktif');
});

test('an unknown id is the uniform 404 and a status outside the two poles is a 422', function (): void {
    f14As(f14Admin());

    $this->putJson('/api/v1/admin/pengguna/999999/status', ['status' => 'nonaktif'])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Resource not found.');

    $target = f14User('Status Uji', 'kurir');

    $this->putJson('/api/v1/admin/pengguna/'.$target->getKey().'/status', ['status' => 'ditangguhkan'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'The selected status akun is invalid.');
});

// ----------------------------------------------------------- the reset-sandi

test('reset-sandi answers the new plaintext once, stores only a digest, and changes the login', function (): void {
    $target = f14User('Kurir Lupa Sandi', 'kurir');

    f14As(f14Admin());

    $response = $this->postJson('/api/v1/admin/pengguna/'.$target->getKey().'/reset-sandi');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Kata sandi berhasil diatur ulang.')
        ->assertJsonPath('data.pengguna.id', $target->getKey());

    $baru = (string) $response->json('data.password_baru');

    // The operator hands THIS over out of band; the server keeps no copy.
    expect(strlen($baru))->toBe(16)
        ->and(Hash::check($baru, (string) $target->fresh()->kata_sandi_hash))->toBeTrue()
        ->and(Hash::check('RahasiaLamaYangTebak!', (string) $target->fresh()->kata_sandi_hash))->toBeFalse();

    // The reset itself is audited as an event; the digest never reaches the log.
    $baris = DB::table('audit_log')
        ->where('tabel_target', 'users')
        ->where('record_id', (string) $target->getKey())
        ->orderByDesc('id')
        ->first();

    expect($baris)->not->toBeNull()
        ->and((string) $baris->data_baru)->not->toContain('kata_sandi_hash');
});

test('reset-sandi on an admin row is 403 for an admin and 201 for a superadmin, 404 for an unknown id', function (): void {
    $target = f14User('Admin Lupa Sandi', 'admin');

    f14As(f14Admin());

    $this->postJson('/api/v1/admin/pengguna/'.$target->getKey().'/reset-sandi')
        ->assertStatus(403)
        ->assertJsonPath('message', 'Hanya superadmin yang boleh mengatur ulang sandi akun admin.');

    f14As(f14Superadmin());

    $this->postJson('/api/v1/admin/pengguna/999999/reset-sandi')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Resource not found.');

    $this->postJson('/api/v1/admin/pengguna/'.$target->getKey().'/reset-sandi')
        ->assertStatus(200)
        ->assertJsonPath('data.pengguna.tipe', 'admin');
});

// -------------------------------------------------------------- the envelopes

test('every route answers the uniform 401 without a token', function (): void {
    f14TanpaToken();

    $expected = '{"success":false,"message":"Unauthenticated.","errors":{}}';

    expect($this->getJson('/api/v1/admin/pengguna')->getContent())->toBe($expected)
        ->and($this->postJson('/api/v1/admin/pengguna', pgPayload())->getContent())->toBe($expected)
        ->and($this->putJson('/api/v1/admin/pengguna/1/status', ['status' => 'nonaktif'])->getContent())->toBe($expected)
        ->and($this->postJson('/api/v1/admin/pengguna/1/reset-sandi')->getContent())->toBe($expected);
});
