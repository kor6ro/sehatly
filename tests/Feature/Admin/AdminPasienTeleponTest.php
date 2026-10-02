<?php

declare(strict_types=1);

use App\Models\UserRefreshToken;
use App\Support\NikMasker;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| F01: the support path that corrects a patient's phone number
|--------------------------------------------------------------------------
|
| `PUT /api/v1/admin/pasien/{id}/telepon` - the endpoint F01's pattern section 12
| item 4 decided ("cukup lewat CS di fase ini"; self-service remains out of scope).
|
| **What this file proves, and why each half matters:**
|
| - The number is NORMALISED through `App\Support\Telepon`, so `+62…` and `08…`
|   are one value for both the unique guard and the write - the same P0 the auth
|   flow closed.
| - A number held by ANOTHER account is a 422 naming `no_telepon`, while the
|   patient's own number is not a collision with itself.
| - The permission is `pasien.kelola` (F01's owner-approved catalogue addition,
|   granted to `admin` and `superadmin` ONLY) on top of the party gate, and the
|   test drives all three refusals: a patient fails the party gate, an `admin`
|   without the role fails the permission, and a `superadmin` passes both.
| - The audit trail is automatic (`users` is in `AuditScope`) and the number in it
|   is MASKED by `AuditColumnPolicy`, so the full value appears in neither the
|   response nor the log.
|
| **Fixtures come from `f14-helpers.php`** (`f14Admin`, `f14Superadmin`,
| `f14Pasien`, `f14User`, `f14As`, `f14TanpaToken`, `f14BersihkanAuditPengguna`),
| and `RefreshDatabase` is bound by `tests/Pest.php`, so every fixture user writes
| an audit `create` row that is cleaned before the act under assertion.
|
*/

require_once __DIR__.'/f14-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

test('an admin changes a patient phone: +62 is normalised to 08 and the response is masked', function (): void {
    $akun = f14Pasien();

    f14BersihkanAuditPengguna();

    f14As(f14Admin());

    $response = $this->putJson('/api/v1/admin/pasien/'.$akun['pasien']->getKey().'/telepon', [
        'no_telepon' => '+6281299998888',
    ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('message', 'Nomor telepon berhasil diperbarui.');
    // The response publishes the mask, never the full number, even though the
    // caller supplied it.
    $response->assertJsonPath('data.pasien.no_telepon', NikMasker::mask('081299998888'));
    expect($response->getContent())->not->toContain('081299998888')
        ->and($response->getContent())->not->toContain('+6281299998888');

    // The canonical local form is what the database stores (`Telepon`'s decision),
    // and the support path is the identity check, so the flag is set.
    $pengguna = $akun['user']->fresh();
    expect($pengguna->no_telepon)->toBe('081299998888')
        ->and((bool) $pengguna->telepon_terverifikasi)->toBeTrue();
});

test('the change is audited on users, and the audit row holds the masked number only', function (): void {
    $akun = f14Pasien();

    f14BersihkanAuditPengguna();

    f14As(f14Admin());

    $this->putJson('/api/v1/admin/pasien/'.$akun['pasien']->getKey().'/telepon', [
        'no_telepon' => '081299998888',
    ])->assertOk();

    $baris = DB::table('audit_log')
        ->where('tabel_target', 'users')
        ->where('record_id', (string) $akun['user']->getKey())
        ->orderByDesc('id')
        ->first();

    expect($baris)->not->toBeNull('the automatic observer must write an update row for users');
    expect($baris->aksi)->toBe('update');

    $baru = json_decode((string) $baris->data_baru, true);

    // `AuditColumnPolicy::MASKED` maps `no_telepon` to the project masker, so the
    // row answers "did the number change?" without holding the number.
    expect($baru)->toBeArray()
        ->and($baru['no_telepon'])->toBe(NikMasker::mask('081299998888'))
        ->and((string) $baris->data_baru)->not->toContain('081299998888');
});

test('a number already held by another account is a 422 on no_telepon, and nothing is written', function (): void {
    $akun = f14Pasien();
    $lain = f14Pasien();

    // The other account's number is set through the model only to arrange the
    // collision; its audit noise is cleared before the act.
    $lain['user']->no_telepon = '081299998888';
    $lain['user']->save();

    f14BersihkanAuditPengguna();

    f14As(f14Admin());

    // The international spelling of the SAME number, so the unique guard can only
    // pass this test if normalisation ran before validation.
    $response = $this->putJson('/api/v1/admin/pasien/'.$akun['pasien']->getKey().'/telepon', [
        'no_telepon' => '+6281299998888',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    $response->assertJsonPath('errors.no_telepon.0', 'The nomor telepon has already been taken.');

    expect($akun['user']->fresh()->no_telepon)->not->toBe('081299998888')
        ->and((bool) $akun['user']->fresh()->telepon_terverifikasi)->toBeFalse();
});

test('re-submitting the patient own number is not a duplicate of itself', function (): void {
    $akun = f14Pasien();
    $sendiri = (string) $akun['user']->no_telepon;

    f14BersihkanAuditPengguna();

    f14As(f14Admin());

    $this->putJson('/api/v1/admin/pasien/'.$akun['pasien']->getKey().'/telepon', [
        'no_telepon' => $sendiri,
    ])->assertOk();

    expect($akun['user']->fresh()->no_telepon)->toBe($sendiri);
});

test('unknown and soft-deleted patients are 404, not 422 or 500', function (): void {
    $akun = f14Pasien();

    f14As(f14Admin());

    $this->putJson('/api/v1/admin/pasien/999999/telepon', ['no_telepon' => '081299998881'])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Resource not found.');

    $akun['pasien']->delete();

    $this->putJson('/api/v1/admin/pasien/'.$akun['pasien']->getKey().'/telepon', ['no_telepon' => '081299998882'])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Resource not found.');
});

test('the write is gated by the party and by pasien.kelola: patient 403, admin without the role 403, superadmin 200', function (): void {
    $akun = f14Pasien();
    $pasienLain = f14Pasien();

    // The patient holds a `pasien` role but the party gate refuses the account
    // type before any grant is consulted.
    f14As($akun['user']);
    $this->putJson('/api/v1/admin/pasien/'.$pasienLain['pasien']->getKey().'/telepon', [
        'no_telepon' => '081299998883',
    ])->assertStatus(403);

    // An admin account WITHOUT the role: the type passes the party gate, and the
    // missing `pasien.kelola` grant is what refuses it. This is the assertion that
    // proves the permission does the work rather than the type alone.
    f14As(f14User('Admin tanpa peran', 'admin'));
    $this->putJson('/api/v1/admin/pasien/'.$pasienLain['pasien']->getKey().'/telepon', [
        'no_telepon' => '081299998883',
    ])->assertStatus(403);

    // The oversight account holds the whole catalogue, `pasien.kelola` included.
    f14As(f14Superadmin());
    $this->putJson('/api/v1/admin/pasien/'.$pasienLain['pasien']->getKey().'/telepon', [
        'no_telepon' => '081299998883',
    ])->assertOk();

    expect($pasienLain['user']->fresh()->no_telepon)->toBe('081299998883');
});

test('an unauthenticated caller is refused 401 with the standard envelope', function (): void {
    $akun = f14Pasien();

    f14TanpaToken();

    $response = $this->putJson('/api/v1/admin/pasien/'.$akun['pasien']->getKey().'/telepon', [
        'no_telepon' => '081299998884',
    ]);

    $response->assertStatus(401);
    expect($response->getContent())->toBe('{"success":false,"message":"Unauthenticated.","errors":{}}');
});

test('a malformed number is a 422 on no_telepon and the stored number is untouched', function (): void {
    $akun = f14Pasien();
    $lama = (string) $akun['user']->no_telepon;

    f14As(f14Admin());

    $this->putJson('/api/v1/admin/pasien/'.$akun['pasien']->getKey().'/telepon', [
        'no_telepon' => 'bukan-nomor',
    ])->assertStatus(422)
        ->assertJsonPath('errors.no_telepon.0', 'The nomor telepon field format is invalid.');

    expect($akun['user']->fresh()->no_telepon)->toBe($lama);
});

test('the unchanged token tables are not touched by the phone write', function (): void {
    // A cheap guard against a future edit wiring a session side effect into what is
    // an identity-only write: no refresh row is created or revoked by this route.
    $akun = f14Pasien();

    f14As(f14Admin());

    $this->putJson('/api/v1/admin/pasien/'.$akun['pasien']->getKey().'/telepon', [
        'no_telepon' => '081299998885',
    ])->assertOk();

    expect(UserRefreshToken::query()->count())->toBe(0);
});
