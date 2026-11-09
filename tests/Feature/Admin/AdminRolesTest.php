<?php

declare(strict_types=1);

use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;

/*
|--------------------------------------------------------------------------
| The role catalogue: `/api/v1/admin/roles`
|--------------------------------------------------------------------------
|
| ONE read behind `tipe:admin,superadmin` plus `pengguna.lihat` - the
| directory's own read code, because the only consumer of this catalogue is
| the directory's per-role page (each sidebar item opens
| `/admin/pengguna?tipe=<role>` and shows what that role is).
|
| **What this file proves, and why the first assertion is the important one:**
|
| - The payload IS the catalogue: every `RbacCatalog::ROLES` row appears once
|   in catalogue order, each with its `ROLE_DESCRIPTIONS` entry and exactly
|   the `ROLE_PERMISSIONS` codes in order, each wearing its `PERMISSIONS`
|   name. That pins the sidebar's "what this role may do" card to the policy
|   `permission:` middleware enforces - a drift between them would advertise
|   a grant the server disagrees with, which is the failure mode this
|   endpoint exists to make visible rather than to introduce.
| - `superadmin` answers the whole catalogue (35), the same fact
|   `RbacCatalogTest` asserts on the constants themselves.
| - The gate is the pair the directory read has: a patient is refused by the
|   PARTY gate before any permission is consulted, an `admin` without
|   `pengguna.lihat` by the permission (the assertion that proves the code
|   does the work), and the same tokenless call answers the uniform 401
|   envelope.
|
| Fixtures come from `f14-helpers.php` (`f14Admin`, `f14Superadmin`,
| `f14User`, `f14Pasien`, `f14As`, `f14TanpaToken`), and `RefreshDatabase` is
| bound by `tests/Pest.php`.
|
*/

require_once __DIR__.'/f14-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

test('the catalogue answers every role with its description and exactly the permissions it holds', function (): void {
    f14As(f14Admin());

    $response = $this->getJson('/api/v1/admin/roles');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Daftar role berhasil dimuat.')
        ->assertJsonStructure([
            'data' => ['roles' => [['role', 'deskripsi', 'izin' => [['kode', 'nama']]]]],
        ]);

    $roles = collect($response->json('data.roles'));

    // One entry per role, in catalogue order - a missing or reordered role
    // would leave a sidebar page with no card to render.
    expect($roles->pluck('role')->all())->toBe(RbacCatalog::ROLES);

    foreach (RbacCatalog::ROLES as $role) {
        $entri = $roles->firstWhere('role', $role);
        $izin = collect($entri['izin']);

        expect($entri['deskripsi'])->toBe(RbacCatalog::ROLE_DESCRIPTIONS[$role])
            ->and($izin->pluck('kode')->all())->toBe(RbacCatalog::ROLE_PERMISSIONS[$role])
            ->and($izin->pluck('nama')->all())->toBe(array_map(
                static fn (string $kode): string => RbacCatalog::PERMISSIONS[$kode],
                RbacCatalog::ROLE_PERMISSIONS[$role],
            ));
    }

    // The one number an operator reads at a glance: superadmin holds all 35,
    // and every other role holds a strict subset of them.
    expect($roles->firstWhere('role', 'superadmin')['izin'])->toHaveCount(count(RbacCatalog::PERMISSIONS))
        ->and($roles->firstWhere('role', 'kurir')['izin'])->toHaveCount(3);
});

test('the read is gated by the party and by pengguna.lihat: patient 403, admin without the role 403, superadmin 200', function (): void {
    // The patient holds grants of its own, but the party gate
    // `tipe:admin,superadmin` refuses the account type first.
    f14As(f14Pasien()['user']);
    $this->getJson('/api/v1/admin/roles')->assertStatus(403);

    // The type passes the party gate; the missing `pengguna.lihat` grant is
    // what refuses it - the assertion that proves the permission does the work.
    f14As(f14User('Admin tanpa peran', 'admin'));
    $this->getJson('/api/v1/admin/roles')->assertStatus(403);

    f14As(f14Superadmin());
    $this->getJson('/api/v1/admin/roles')->assertOk();
});

test('the route answers the uniform 401 without a token', function (): void {
    f14TanpaToken();

    expect($this->getJson('/api/v1/admin/roles')->getContent())
        ->toBe('{"success":false,"message":"Unauthenticated.","errors":{}}');
});
