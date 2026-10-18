<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-9 - CRUD artikel portal marketing (api/v1/marketing/artikel)
|--------------------------------------------------------------------------
|
| Apa yang dibuktikan oleh berkas ini, dan mengapa masing-masing bagian penting:
|
| - CENSUS: lima route artikel di bawah `api/v1/marketing/artikel`, masing-masing
|   di belakang `tipe:marketing` (party gate) dan `permission:artikel.kelola`
|   (permission gate), dan `whereNumber('id')` pada keempat route yang punya
|   `{id}` (semua kecuali `GET index`). Sebuah edit yang menyisipkan route
|   dengan kode salah akan ditangkap sini.
| - INDEX/SHOW/STORE/UPDATE/DESTROY: masing-masing menjawab status yang tepat
|   dan melakukan tugasnya (termasuk `penulis_user_id` diisi dari akun
|   terautentikasi, bukan dari body, serta `status = 'arsip'` tanpa hard delete
|   pada destroy).
| - GATE: pemanggilan tidak terautentikasi 401, pasien 403 (lintas-tipe), superadmin
|   403 (party gate `tipe:marketing` menolak walaupun superadmin memegang semua
|   izin), dan marketing tanpa role 403 (permission gate).
| - LINTAS-TIPE: pasien mengakses `GET /marketing/artikel` → 403.
|
| Setiap test positif membuat user dan token sendiri (`$h = mktAs(mktUser())`),
# tidak menyimpan ke properti `$this`, yang tidak ada satu pun berkas dalam suite
| ini yang gunakan.
*/

require_once __DIR__.'/marketing-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

// ------------------------------------------------------------- the census

test('lima route artikel ada di belakang kedua gate dengan izin yang terdokumentasi', function (): void {
    $diharapkan = [
        'GET api/v1/marketing/artikel' => 'permission:artikel.kelola',
        'GET api/v1/marketing/artikel/{id}' => 'permission:artikel.kelola',
        'POST api/v1/marketing/artikel' => 'permission:artikel.kelola',
        'PUT api/v1/marketing/artikel/{id}' => 'permission:artikel.kelola',
        'DELETE api/v1/marketing/artikel/{id}' => 'permission:artikel.kelola',
    ];

    $rute = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/v1/marketing/artikel'))
        ->keyBy(fn ($r): string => $r->methods()[0].' '.$r->uri());

    expect($rute->keys()->sort()->values()->all())
        ->toBe(collect(array_keys($diharapkan))->sort()->values()->all());

    foreach ($diharapkan as $kunci => $izin) {
        expect($rute[$kunci]->gatherMiddleware())
            ->toContain('tipe:marketing')
            ->toContain($izin);

        // `whereNumber('id')` menyimpan `[0-9]+` dalam `$wheres` publik wildcard -
        // hanya pada keempat route yang PUNYA wildcard; `GET index` tak punya
        // parameter dan oleh karena itu tak punya constraint untuk diassert.
        if (str_contains($kunci, '{id}')) {
            expect($rute[$kunci]->wheres)->toHaveKey('id', '[0-9]+');
        }
    }
});

// ------------------------------------------------------------- index

test('artikel index menjawab 200 dan data.artikel adalah array', function (): void {
    $h = mktAs(mktUser());

    mktArtikel(['status' => 'terbit']);
    mktArtikel(['status' => 'draft']);

    $baris = $this->getJson('/api/v1/marketing/artikel', $h)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.artikel');

    expect($baris)->toBeArray()->not->toBeEmpty();
});

test('artikel index menjawab array kosong saat tidak ada baris', function (): void {
    $h = mktAs(mktUser());

    $this->getJson('/api/v1/marketing/artikel', $h)
        ->assertOk()
        ->assertJsonPath('data.artikel', []);
});

// ------------------------------------------------------------- show

test('artikel show menjawab 200 dengan kunci sesuai ArtikelResource', function (): void {
    $h = mktAs(mktUser());

    $id = mktArtikel();

    $baris = $this->getJson('/api/v1/marketing/artikel/'.$id, $h)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.artikel');

    expect(array_keys($baris))->toBe([
        'id', 'kategori_id', 'kategori', 'penulis_user_id', 'judul', 'slug',
        'ringkasan', 'konten', 'cover_url', 'status', 'jumlah_view',
        'published_at', 'dibuat_at', 'diubah_at',
    ]);

    expect($baris['id'])->toBe((int) $id);
});

test('artikel show menjawab 404 untuk id yang tidak ada', function (): void {
    $h = mktAs(mktUser());

    $this->getJson('/api/v1/marketing/artikel/999999', $h)->assertNotFound();
});

// ------------------------------------------------------------- store

test('artikel store menjawab 201, membuat baris baru, dan penulis_user_id diisi dari akun terautentikasi', function (): void {
    $pengguna = mktUser();
    $h = mktAs($pengguna);
    $kategori = mktKategori();
    $judul = 'Judul Baru Store'.Str::upper(Str::random(6));
    $slug = 'artikel-baru-store-'.Str::lower(Str::random(8));

    $this->postJson('/api/v1/marketing/artikel', [
        'kategori_id' => $kategori,
        'judul' => $judul,
        'slug' => $slug,
        'ringkasan' => 'Ringkasan baru.',
        'konten' => 'Konten baru yang lengkap.',
        'cover_url' => null,
        'status' => 'draft',
    ], $h)->assertCreated()->assertJsonPath('success', true);

    $baris = DB::table('artikel')->where('slug', $slug)->first();

    expect($baris)->not->toBeNull()
        ->and($baris->penulis_user_id)->toBe((int) $pengguna->getKey())
        ->and($baris->kategori_id)->toBe($kategori);
});

test('artikel store menolak body tanpa field wajib', function (): void {
    $h = mktAs(mktUser());

    $this->postJson('/api/v1/marketing/artikel', [], $h)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kategori_id', 'judul', 'slug', 'konten', 'status']);
});

// ------------------------------------------------------------- update

test('artikel update menjawab 200 dan memperbarui baris', function (): void {
    $h = mktAs(mktUser());
    $judulBaru = 'Judul Diperbarui'.Str::upper(Str::random(6));

    $id = mktArtikel(['judul' => 'Judul Lama']);

    $this->putJson('/api/v1/marketing/artikel/'.$id, [
        'judul' => $judulBaru,
    ], $h)->assertOk()->assertJsonPath('success', true);

    expect(DB::table('artikel')->where('id', $id)->value('judul'))
        ->toBe($judulBaru);
});

// ------------------------------------------------------------- destroy

test('artikel destroy menjawab 200, mengatur status ke arsip, dan baris masih ada', function (): void {
    $h = mktAs(mktUser());

    $id = mktArtikel(['status' => 'draft']);

    $this->deleteJson('/api/v1/marketing/artikel/'.$id, [], $h)
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(DB::table('artikel')->where('id', $id)->value('status'))->toBe('arsip')
        ->and(DB::table('artikel')->where('id', $id)->exists())->toBeTrue();
});

// ------------------------------------------------------------- gates

test('pemanggilan tidak terautentikasi mendapatkan 401 pada semua route artikel', function (): void {
    $this->getJson('/api/v1/marketing/artikel')->assertUnauthorized();
    $this->getJson('/api/v1/marketing/artikel/1')->assertUnauthorized();
    $this->postJson('/api/v1/marketing/artikel', [], [])->assertUnauthorized();
    $this->putJson('/api/v1/marketing/artikel/1', [], [])->assertUnauthorized();
    $this->deleteJson('/api/v1/marketing/artikel/1', [], [])->assertUnauthorized();
});

test('akun pasien ditolak 403 oleh party gate pada route artikel', function (): void {
    $pasien = mktUser('pasien', 'pasien');

    $this->getJson('/api/v1/marketing/artikel', mktAs($pasien))->assertForbidden();
    $this->putJson('/api/v1/marketing/artikel/1', [], mktAs($pasien))->assertForbidden();
});

test('superadmin ditolak 403 oleh party gate tipe:marketing walaupun memegang semua izin', function (): void {
    $superadmin = mktUser('superadmin', 'superadmin');

    $this->getJson('/api/v1/marketing/artikel', mktAs($superadmin))->assertForbidden();
    $this->postJson('/api/v1/marketing/artikel', [], mktAs($superadmin))->assertForbidden();
});

test('akun marketing tanpa role ditolak 403 oleh permission gate', function (): void {
    $tanpaPeran = mktUser('marketing', null);

    // `tipe:marketing` LULUS pemanggil ini - tipe akun benar - sehingga 403
    // yang datang membuktikan lapisan kedua ada secara independen dari yang
    // pertama, yang adalah keseluruhan argumen dua-gate.
    $this->getJson('/api/v1/marketing/artikel', mktAs($tanpaPeran))->assertForbidden();
    $this->postJson('/api/v1/marketing/artikel', [], mktAs($tanpaPeran))->assertForbidden();
});

// ------------------------------------------------------------- lintas-tipe

test('pasien mengakses GET /marketing/artikel mendapat 403 lintas-tipe', function (): void {
    $pasien = mktUser('pasien', 'pasien');

    $this->getJson('/api/v1/marketing/artikel', mktAs($pasien))->assertForbidden();
});
