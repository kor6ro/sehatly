<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-9 - CRUD promo portal marketing (api/v1/marketing/promo)
|--------------------------------------------------------------------------
|
| Apa yang dibuktikan oleh berkas ini, dan mengapa masing-masing bagian penting:
|
| - CENSUS: lima route promo di bawah `api/v1/marketing/promo`, masing-masing
|   di belakang `tipe:marketing` (party gate) dan `permission:promo.kelola`
|   (permission gate), dan `whereNumber('id')` pada keempat route yang punya
|   `{id}` (semua kecuali `GET index`).
| - INDEX/SHOW/STORE/UPDATE/DESTROY: masing-masing menjawab status yang tepat
|   dan melakukan tugasnya (termasuk `status_aktif = 0` tanpa hard delete pada
|   destroy).
| - GATE: pemanggilan tidak terautentikasi 401, pasien 403 (lintas-tipe),
|   superadmin 403 (party gate `tipe:marketing` menolak walaupun superadmin
|   memegang semua izin), dan marketing tanpa role 403 (permission gate).
|
| Setiap test positif membuat user dan token sendiri (`$h = mktAs(mktUser())`),
| tidak menyimpan ke properti `$this`.
*/

require_once __DIR__.'/marketing-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

// ------------------------------------------------------------- the census

test('lima route promo ada di belakang kedua gate dengan izin yang terdokumentasi', function (): void {
    $diharapkan = [
        'GET api/v1/marketing/promo' => 'permission:promo.kelola',
        'GET api/v1/marketing/promo/{id}' => 'permission:promo.kelola',
        'POST api/v1/marketing/promo' => 'permission:promo.kelola',
        'PUT api/v1/marketing/promo/{id}' => 'permission:promo.kelola',
        'DELETE api/v1/marketing/promo/{id}' => 'permission:promo.kelola',
    ];

    $rute = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/v1/marketing/promo'))
        ->keyBy(fn ($r): string => $r->methods()[0].' '.$r->uri());

    expect($rute->keys()->sort()->values()->all())
        ->toBe(collect(array_keys($diharapkan))->sort()->values()->all());

    foreach ($diharapkan as $kunci => $izin) {
        expect($rute[$kunci]->gatherMiddleware())
            ->toContain('tipe:marketing')
            ->toContain($izin);

        if (str_contains($kunci, '{id}')) {
            expect($rute[$kunci]->wheres)->toHaveKey('id', '[0-9]+');
        }
    }
});

// ------------------------------------------------------------- index

test('promo index menjawab 200 dan data.promo adalah array', function (): void {
    $h = mktAs(mktUser());

    mktPromo(['status_aktif' => 1]);
    mktPromo(['status_aktif' => 0]);

    $baris = $this->getJson('/api/v1/marketing/promo', $h)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.promo');

    expect($baris)->toBeArray()->not->toBeEmpty();
});

test('promo index menjawab array kosong saat tidak ada baris', function (): void {
    $h = mktAs(mktUser());

    $this->getJson('/api/v1/marketing/promo', $h)
        ->assertOk()
        ->assertJsonPath('data.promo', []);
});

// ------------------------------------------------------------- show

test('promo show menjawab 200 dengan kunci sesuai PromoResource', function (): void {
    $h = mktAs(mktUser());

    $id = mktPromo();

    $baris = $this->getJson('/api/v1/marketing/promo/'.$id, $h)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.promo');

    expect(array_keys($baris))->toBe([
        'id', 'kode', 'nama', 'tipe_diskon', 'nilai', 'min_transaksi',
        'maks_diskon', 'kuota_total', 'kuota_per_user', 'mulai_at',
        'selesai_at', 'status_aktif', 'dibuat_at', 'diubah_at',
    ]);

    expect($baris['id'])->toBe((int) $id);
});

test('promo show menjawab 404 untuk id yang tidak ada', function (): void {
    $h = mktAs(mktUser());

    $this->getJson('/api/v1/marketing/promo/999999', $h)->assertNotFound();
});

// ------------------------------------------------------------- store

test('promo store menjawab 201 dan membuat baris baru', function (): void {
    $h = mktAs(mktUser());
    $kode = 'PROMO-'.Str::upper(Str::random(8));
    $nama = 'Promo Baru Store'.Str::upper(Str::random(6));

    $this->postJson('/api/v1/marketing/promo', [
        'kode' => $kode,
        'nama' => $nama,
        'tipe_diskon' => 'persen',
        'nilai' => '10.00',
        'min_transaksi' => '0.00',
        'mulai_at' => '2026-03-11 00:00:00',
        'selesai_at' => '2026-12-31 23:59:59',
    ], $h)->assertCreated()->assertJsonPath('success', true);

    $baris = DB::table('master_promo')->where('kode', $kode)->first();

    expect($baris)->not->toBeNull()
        ->and($baris->nama)->toBe($nama);
});

test('promo store menolak body tanpa field wajib', function (): void {
    $h = mktAs(mktUser());

    $this->postJson('/api/v1/marketing/promo', [], $h)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kode', 'nama', 'tipe_diskon', 'nilai', 'mulai_at', 'selesai_at']);
});

// ------------------------------------------------------------- update

test('promo update menjawab 200 dan memperbarui baris', function (): void {
    $h = mktAs(mktUser());
    $namaBaru = 'Promo Diperbarui'.Str::upper(Str::random(6));

    $id = mktPromo(['nama' => 'Promo Lama']);

    $this->putJson('/api/v1/marketing/promo/'.$id, [
        'nama' => $namaBaru,
    ], $h)->assertOk()->assertJsonPath('success', true);

    expect(DB::table('master_promo')->where('id', $id)->value('nama'))
        ->toBe($namaBaru);
});

// ------------------------------------------------------------- destroy

test('promo destroy menjawab 200, mengatur status_aktif ke 0, dan baris masih ada', function (): void {
    $h = mktAs(mktUser());

    $id = mktPromo(['status_aktif' => 1]);

    $this->deleteJson('/api/v1/marketing/promo/'.$id, [], $h)
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(DB::table('master_promo')->where('id', $id)->value('status_aktif'))->toBe(0)
        ->and(DB::table('master_promo')->where('id', $id)->exists())->toBeTrue();
});

// ------------------------------------------------------------- gates

test('pemanggilan tidak terautentikasi mendapatkan 401 pada semua route promo', function (): void {
    $this->getJson('/api/v1/marketing/promo')->assertUnauthorized();
    $this->getJson('/api/v1/marketing/promo/1')->assertUnauthorized();
    $this->postJson('/api/v1/marketing/promo', [], [])->assertUnauthorized();
    $this->putJson('/api/v1/marketing/promo/1', [], [])->assertUnauthorized();
    $this->deleteJson('/api/v1/marketing/promo/1', [], [])->assertUnauthorized();
});

test('akun pasien ditolak 403 oleh party gate pada route promo', function (): void {
    $pasien = mktUser('pasien', 'pasien');

    $this->getJson('/api/v1/marketing/promo', mktAs($pasien))->assertForbidden();
    $this->putJson('/api/v1/marketing/promo/1', [], mktAs($pasien))->assertForbidden();
});

test('superadmin ditolak 403 oleh party gate tipe:marketing walaupun memegang semua izin', function (): void {
    $superadmin = mktUser('superadmin', 'superadmin');

    $this->getJson('/api/v1/marketing/promo', mktAs($superadmin))->assertForbidden();
    $this->postJson('/api/v1/marketing/promo', [], mktAs($superadmin))->assertForbidden();
});

test('akun marketing tanpa role ditolak 403 oleh permission gate', function (): void {
    $tanpaPeran = mktUser('marketing', null);

    // `tipe:marketing` LULUS pemanggil ini - tipe akun benar - sehingga 403
    // yang datang membuktikan lapisan kedua ada secara independen dari yang
    // pertama, yang adalah keseluruhan argumen dua-gate.
    $this->getJson('/api/v1/marketing/promo', mktAs($tanpaPeran))->assertForbidden();
    $this->postJson('/api/v1/marketing/promo', [], mktAs($tanpaPeran))->assertForbidden();
});
