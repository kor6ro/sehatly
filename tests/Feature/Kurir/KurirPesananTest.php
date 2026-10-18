<?php

declare(strict_types=1);

use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| M6 - the courier's four delivery routes (plan section 5.2)
|--------------------------------------------------------------------------
|
| **What this file proves, and why each half matters:**
|
| - The CENSUS: four routes under `api/v1/kurir/`, every one behind the
|   `tipe:kurir` party gate, the permission split documented in `routes/api.php`
|   (reads and the claim carry `pesanan.update`, the confirmation carries
|   `pesanan.antar`), `whereNumber` on every `{id}`, and `pesanan.lihat` - the
|   code F46's refusal is built on - appearing NOWHERE under the prefix. The
|   last assertion is the architectural one: this module exists instead of a
|   widened `GET /pesanan-obat/{id}`, and a future edit that slipped the
|   withheld code in would silently undo that boundary.
| - The QUEUE answers exactly the two actionable statuses, packed orders first
|   and newest activity inside each group - with the excluded fixtures
|   deliberately stamped LATER than the included ones, so a globally-newest
|   query would fail the test rather than pass it sideways.
| - The PAYLOAD withholds the money, the prescription and the patient id while
|   the database holds all three: the privacy boundary is asserted against
|   non-zero fixtures, because a zero-filled row could not distinguish a
|   withheld key from an absent value.
| - Both GATES refuse: an unauthenticated caller 401, a patient 403 from the
|   party gate, SUPERADMIN 403 from the same gate (the deliberate asymmetry
|   with F14's `tipe:admin,superadmin` group - an oversight account cannot
|   confirm a parcel it never carried), and a role-less courier 403 from the
|   permission gate.
| - The DETAIL serves the queue plus `selesai` and refuses everything else
|   with a body IDENTICAL to a nonexistent id's - the courier surface must not
|   become an existence oracle for orders outside its scope.
| - Both WRITES ride the ONE state machine: claim `siap -> sedang_dikirim`
|   with the default words, the optional tracking number stamped on the edge
|   that allows it, confirm `sedang_dikirim -> selesai` with its own defaults,
|   wrong-state and unknown ids a 404 before any transition is attempted, a
|   second claimant finding nothing and the trail written exactly once, and a
|   `no_resi` at confirmation refused 422 by the REQUEST (`prohibited`) rather
|   than reaching the workflow message.
| - The LIFECYCLE walks one order queue -> claim -> queue -> confirm -> detail,
|   the four calls in the order a courier actually makes them.
|
| **What is deliberately NOT re-proved here:** F46's refusal of the courier is
| `CheckoutTest`'s "the order read refuses dokter, perawat and kurir"; the 6x6
| transition map and its locked writes are `PesananObatTrackingTest`'s. This
| file drives both through HTTP and asserts this module's OWN contracts only.
|
| **Fixture note:** orders are `tipe = 'resep_dokter'` with `resep_id` NULL and
| NON-ZERO money - see `kurir-helpers.php`'s header for why both are deliberate.
| Nothing in this module reads `resep_id`, which is the whole argument of the
| resource that publishes this surface.
|
| Every positive test mints its courier and its bearer like the `f14-helpers`
| files do - a local `$h = kurAs(kurUser())`, never a `$this->` property,
| which no file in this suite uses.
*/

require_once __DIR__.'/kurir-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

// ------------------------------------------------------------- the census

test('the four courier routes exist behind both gates with the documented permission split', function (): void {
    $diharapkan = [
        'GET api/v1/kurir/pesanan' => 'permission:pesanan.update',
        'GET api/v1/kurir/pesanan/{id}' => 'permission:pesanan.update',
        'PUT api/v1/kurir/pesanan/{id}/ambil' => 'permission:pesanan.update',
        'PUT api/v1/kurir/pesanan/{id}/antar' => 'permission:pesanan.antar',
    ];

    $rute = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/v1/kurir/'))
        ->keyBy(fn ($r): string => $r->methods()[0].' '.$r->uri());

    expect($rute->keys()->sort()->values()->all())
        ->toBe(collect(array_keys($diharapkan))->sort()->values()->all());

    foreach ($diharapkan as $kunci => $izin) {
        expect($rute[$kunci]->gatherMiddleware())
            ->toContain('tipe:kurir')
            ->toContain($izin)
            ->not->toContain('permission:pesanan.lihat');

        // `whereNumber('id')` stores `[0-9]+` in the wildcard's public `$wheres`
        // - only on the three routes that HAVE a wildcard; the queue read has
        // no parameter and therefore no constraint to assert.
        if (str_contains($kunci, '{id}')) {
            expect($rute[$kunci]->wheres)->toHaveKey('id', '[0-9]+');
        }
    }
});

// ------------------------------------------------------------- the queue

test('the queue answers only the actionable statuses, packed first, newest activity first', function (): void {
    $h = kurAs(kurUser());

    // The four EXCLUDED rows are stamped LATER than every included one, so a
    // query ordered by time alone would fail this test by returning them.
    kurPesanan('dibatalkan', ['diubah_at' => '2026-03-11 12:30:00']);
    kurPesanan('menunggu_pembayaran', ['diubah_at' => '2026-03-11 12:20:00']);
    kurPesanan('diproses', ['diubah_at' => '2026-03-11 12:10:00']);
    kurPesanan('selesai', ['diubah_at' => '2026-03-11 12:00:00']);

    $siapTua = kurPesanan('siap', ['diubah_at' => '2026-03-11 10:00:00']);
    $siapBaru = kurPesanan('siap', ['diubah_at' => '2026-03-11 11:00:00']);
    $dikirim = kurPesanan('sedang_dikirim', ['diubah_at' => '2026-03-11 11:30:00']);

    $response = $this->getJson('/api/v1/kurir/pesanan', $h);

    $response->assertOk()->assertJsonPath('success', true);

    // Exact id SEQUENCE: the three included ids in the documented order, and
    // by construction none of the four excluded ones.
    expect(array_column($response->json('data.pesanan'), 'id'))
        ->toBe([$siapBaru, $siapTua, $dikirim]);
});

test('the queue answers an empty array on an install with nothing to deliver', function (): void {
    $h = kurAs(kurUser());

    $this->getJson('/api/v1/kurir/pesanan', $h)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pesanan', []);
});

test('the courier payload withholds the money, the prescription and the patient id', function (): void {
    $h = kurAs(kurUser());

    $id = kurPesanan('siap');
    $apotekId = (int) DB::table('pesanan_obat')->where('id', $id)->value('apotek_id');
    $apotekNama = (string) DB::table('faskes')->where('id', $apotekId)->value('nama');

    $baris = $this->getJson('/api/v1/kurir/pesanan', $h)
        ->assertOk()
        ->json('data.pesanan.0');

    // The EXACT key set, in order: an added key fails this as surely as a
    // leaked one, which a toHaveKeys() allow-list would not do.
    expect(array_keys($baris))->toBe([
        'id', 'nomor_pesanan', 'status', 'tipe', 'alamat_kirim',
        'kurir', 'no_resi', 'apotek', 'dibuat_at', 'diubah_at',
    ]);

    // The database holds non-zero money and a patient-owned address; the wire
    // holds the destination snapshot and the pickup point, nothing clinical
    // and nothing financial.
    expect($baris['alamat_kirim'])->toBe('Jl. Tujuan Kirim No. 9, Jakarta')
        ->and($baris['apotek'])->toBe([
            'id' => $apotekId,
            'nama' => $apotekNama,
            'alamat' => 'Jl. Apotek Uji No. 2, Jakarta',
        ]);
});

test('an unauthenticated caller is refused the courier queue', function (): void {
    $this->getJson('/api/v1/kurir/pesanan')->assertUnauthorized();
});

test('a patient account is refused the courier queue by the party gate', function (): void {
    $pasien = kurUser('pasien', 'pasien');

    $this->getJson('/api/v1/kurir/pesanan', kurAs($pasien))->assertForbidden();
});

test('superadmin is refused the courier queue by the party gate despite holding both codes', function (): void {
    $superadmin = kurUser('superadmin', 'superadmin');

    // The deliberate asymmetry with F14's `tipe:admin,superadmin` group: the
    // catalogue grants superadmin `pesanan.update` and `pesanan.antar` (it
    // holds every code), and the PARTY gate still refuses - a delivery
    // confirmation asserts a physical handover an oversight account cannot
    // witness. See the route block's and the controller's docblocks.
    $this->getJson('/api/v1/kurir/pesanan', kurAs($superadmin))->assertForbidden();
});

test('a courier account with no role is refused by the permission gate', function (): void {
    $tanpaPeran = kurUser('kurir', null);

    // `tipe:kurir` PASSES this caller - the account type is right - so the
    // 403 that follows proves the second layer exists independently of the
    // first, which is the whole two-gate argument.
    $this->getJson('/api/v1/kurir/pesanan', kurAs($tanpaPeran))->assertForbidden();
});

// ------------------------------------------------------------- the detail

test('the detail answers for a packed order with its tracking trail', function (): void {
    $h = kurAs(kurUser());

    $id = kurPesanan('siap');
    kurTracking($id, 'siap', ['keterangan' => 'Dikemas apotek', 'lokasi' => 'Bandung']);
    kurTracking($id, 'siap', ['keterangan' => 'Siap diambil', 'lokasi' => 'Bandung']);

    $response = $this->getJson('/api/v1/kurir/pesanan/'.$id, $h);

    $response->assertOk()->assertJsonPath('success', true);

    expect($response->json('data.pesanan.status'))->toBe('siap')
        ->and($response->json('data.pesanan.tracking'))->toHaveCount(2)
        ->and($response->json('data.pesanan.tracking.0.keterangan'))->toBe('Dikemas apotek')
        ->and($response->json('data.pesanan.tracking.0.lokasi'))->toBe('Bandung');
});

test('the detail survives a reload for a delivered order', function (): void {
    $h = kurAs(kurUser());

    // One state WIDER than the queue, deliberately: after `antar` the screen a
    // courier is on must survive a refresh instead of 404ing on the order it
    // just closed. See `KurirPesananService::TERLIHAT`.
    $id = kurPesanan('selesai');

    $this->getJson('/api/v1/kurir/pesanan/'.$id, $h)
        ->assertOk()
        ->assertJsonPath('data.pesanan.status', 'selesai');
});

test('orders outside the courier surface answer exactly like orders that never existed', function (): void {
    $h = kurAs(kurUser());

    $dibatalkan = kurPesanan('dibatalkan');

    $luar = $this->getJson('/api/v1/kurir/pesanan/'.$dibatalkan, $h)->assertNotFound();
    $tidakAda = $this->getJson('/api/v1/kurir/pesanan/999999', $h)->assertNotFound();

    // Byte-identical envelopes: the caller cannot tell "exists but not yours"
    // from "never existed", so the surface is not an existence oracle. The
    // handler's fixed 'Resource not found.' is what makes this assertable.
    expect($luar->json())->toBe($tidakAda->json());

    // The two other invisible states go the same way.
    $this->getJson('/api/v1/kurir/pesanan/'.kurPesanan('menunggu_pembayaran'), $h)->assertNotFound();
    $this->getJson('/api/v1/kurir/pesanan/'.kurPesanan('diproses'), $h)->assertNotFound();
});

test('a non-numeric order id is a router 404', function (): void {
    // `whereNumber('id')`: NO route matches, so no middleware runs either -
    // the router itself answers before authentication could 401.
    $this->getJson('/api/v1/kurir/pesanan/abc')->assertNotFound();
});

// ------------------------------------------------------------- the claim

test('claiming a packed order moves it and appends the default tracking row', function (): void {
    $h = kurAs(kurUser());

    $id = kurPesanan('siap');

    $response = $this->putJson('/api/v1/kurir/pesanan/'.$id.'/ambil', [], $h);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pesanan.status', 'sedang_dikirim')
        ->assertJsonPath('message', 'Pesanan berhasil diambil dan sedang dikirim.');

    // The write answer carries the same non-null pickup block the reads do
    // (`load('apotek')` in the service) and deliberately NOT the trail.
    expect($response->json('data.pesanan.apotek'))->toBeArray()
        ->and($response->json('data.pesanan'))->not->toHaveKey('tracking');

    expect(DB::table('pesanan_obat')->where('id', $id)->value('status'))
        ->toBe('sedang_dikirim');

    $tulis = DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->get();

    expect($tulis)->toHaveCount(1)
        ->and($tulis[0]->status)->toBe('sedang_dikirim')
        ->and($tulis[0]->keterangan)->toBe('Pesanan diambil kurir dan sedang dikirim.')
        ->and($tulis[0]->waktu)->not->toBeNull();
});

test('the claim stamps a supplied tracking number and keeps the courier own words', function (): void {
    $h = kurAs(kurUser());

    $id = kurPesanan('siap');

    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/ambil', [
        'no_resi' => 'JNE-001-KURIR',
        'keterangan' => 'Diambil pukul 10.00',
        'lokasi' => 'Depot Bandung',
    ], $h)->assertOk()->assertJsonPath('data.pesanan.no_resi', 'JNE-001-KURIR');

    expect(DB::table('pesanan_obat')->where('id', $id)->value('no_resi'))
        ->toBe('JNE-001-KURIR');

    $tulis = DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->first();

    expect($tulis?->keterangan)->toBe('Diambil pukul 10.00')
        ->and($tulis?->lokasi)->toBe('Depot Bandung');
});

test('an order the courier may not act on is a 404, not a state-machine error', function (): void {
    $h = kurAs(kurUser());

    // Both wrong-state ids answer 404 from the scoped FIND before the
    // transition is ever attempted: a claim must not reveal which state an
    // out-of-scope order is in through a 422 naming it.
    $this->putJson('/api/v1/kurir/pesanan/'.kurPesanan('diproses').'/ambil', [], $h)->assertNotFound();
    $this->putJson('/api/v1/kurir/pesanan/'.kurPesanan('selesai').'/ambil', [], $h)->assertNotFound();
    $this->putJson('/api/v1/kurir/pesanan/999999/ambil', [], $h)->assertNotFound();
});

test('the second claimant finds no packed order and the trail is written once', function (): void {
    $h = kurAs(kurUser());

    $id = kurPesanan('siap');

    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/ambil', [], $h)->assertOk();
    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/ambil', [], $h)->assertNotFound();

    // No second tracking row: the state machine's lock decided the race, and
    // the loser's answer is the shared 404 rather than a conflict naming the
    // winner.
    expect(DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->count())->toBe(1)
        ->and(DB::table('pesanan_obat')->where('id', $id)->value('status'))->toBe('sedang_dikirim');
});

test('the claim needs the courier party', function (): void {
    $id = kurPesanan('siap');
    $pasien = kurUser('pasien', 'pasien');

    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/ambil', [], kurAs($pasien))->assertForbidden();

    expect(DB::table('pesanan_obat')->where('id', $id)->value('status'))->toBe('siap');
});

// ------------------------------------------------------------- the confirmation

test('confirming delivery moves the order and appends the default words', function (): void {
    $h = kurAs(kurUser());

    $id = kurPesanan('sedang_dikirim');

    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/antar', [], $h)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pesanan.status', 'selesai')
        ->assertJsonPath('message', 'Pesanan berhasil ditandai selesai.');

    $tulis = DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->get();

    expect($tulis)->toHaveCount(1)
        ->and($tulis[0]->status)->toBe('selesai')
        ->and($tulis[0]->keterangan)->toBe('Pesanan telah diterima penerima.');
});

test('an unshipped parcel cannot be confirmed', function (): void {
    $h = kurAs(kurUser());

    // The confirmation's scoped FIND is `sedang_dikirim`: a packed order must
    // be CLAIMED first, so this is 404 (not yours to confirm yet) rather than
    // a state-machine 422 describing the workflow to an outsider.
    $this->putJson('/api/v1/kurir/pesanan/'.kurPesanan('siap').'/antar', [], $h)->assertNotFound();
});

test('a tracking number may not be stamped at delivery', function (): void {
    $h = kurAs(kurUser());

    // `prohibited` on `AntarPesananRequest`: the refusal arrives as a field-
    // level 422 naming the contract, before `resiUntuk()`'s workflow message
    // could describe a parcel that already shipped.
    $id = kurPesanan('sedang_dikirim');

    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/antar', ['no_resi' => 'RESI-TERLAMBAT'], $h)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['no_resi']);

    expect(DB::table('pesanan_obat')->where('id', $id)->value('status'))
        ->toBe('sedang_dikirim')
        ->and(DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->count())->toBe(0);
});

// ------------------------------------------------------------- the lifecycle

test('a courier can walk a packed order to delivered and it leaves the queue', function (): void {
    $h = kurAs(kurUser());

    $id = kurPesanan('siap');

    // 1. It is in the queue, under the courier account.
    $antrean = collect($this->getJson('/api/v1/kurir/pesanan', $h)->assertOk()->json('data.pesanan'));
    expect($antrean->pluck('id')->all())->toContain($id);

    // 2. Claimed.
    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/ambil', ['no_resi' => 'RESI-LIVE-1'], $h)
        ->assertOk()
        ->assertJsonPath('data.pesanan.status', 'sedang_dikirim');

    // 3. Still listed - it is in transit, which is actionable.
    $antrean = collect($this->getJson('/api/v1/kurir/pesanan', $h)->assertOk()->json('data.pesanan'));
    expect($antrean->pluck('id')->all())->toContain($id);

    // 4. Confirmed.
    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/antar', ['keterangan' => 'Diterima langsung'], $h)
        ->assertOk()
        ->assertJsonPath('data.pesanan.status', 'selesai');

    // 5. Gone from the queue, still readable on the detail screen.
    $antrean = $this->getJson('/api/v1/kurir/pesanan', $h)->assertOk()->json('data.pesanan');
    expect(array_column($antrean, 'id'))->not->toContain($id);

    $this->getJson('/api/v1/kurir/pesanan/'.$id, $h)
        ->assertOk()
        ->assertJsonPath('data.pesanan.status', 'selesai')
        ->assertJsonPath('data.pesanan.no_resi', 'RESI-LIVE-1');
});

// ------------------------------------------------------------- notifikasi pasien (DG9)

test('mengambil pesanan membuat notifikasi pesanan telah diambil untuk pasien pemesan', function (): void {
    $h = kurAs(kurUser());

    $id = kurPesanan('siap');
    $nomorPesanan = (string) DB::table('pesanan_obat')->where('id', $id)->value('nomor_pesanan');
    $pasienUserId = (int) DB::table('pasien')
        ->where('id', (int) DB::table('pesanan_obat')->where('id', $id)->value('pasien_id'))
        ->value('user_id');

    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/ambil', [], $h)
        ->assertOk()
        ->assertJsonPath('data.pesanan.status', 'sedang_dikirim');

    $baris = DB::table('notifikasi')
        ->where('user_id', $pasienUserId)
        ->where('judul', 'Pesanan telah diambil')
        ->where('tipe', 'sistem')
        ->first();

    expect($baris)->not->toBeNull();

    $payload = json_decode((string) $baris->payload, true);

    expect((string) $baris->isi)->toContain($nomorPesanan)
        ->and((string) $baris->isi)->toContain('sedang dikirim')
        ->and($baris->tautan)->toBe('/api/v1/pesanan-obat/'.$id)
        ->and($baris->dibaca_at)->toBeNull()
        ->and($payload)->toBeArray()
        ->and($payload['pesanan_id'])->toBe((int) $id)
        ->and($payload['nomor_pesanan'])->toBe($nomorPesanan)
        ->and($payload['status'])->toBe('sedang_dikirim');
});

test('antar membuat notifikasi pesanan telah diterima untuk pasien pemesan', function (): void {
    $h = kurAs(kurUser());

    $id = kurPesanan('sedang_dikirim');
    $nomorPesanan = (string) DB::table('pesanan_obat')->where('id', $id)->value('nomor_pesanan');
    $pasienUserId = (int) DB::table('pasien')
        ->where('id', (int) DB::table('pesanan_obat')->where('id', $id)->value('pasien_id'))
        ->value('user_id');

    $this->putJson('/api/v1/kurir/pesanan/'.$id.'/antar', [], $h)
        ->assertOk()
        ->assertJsonPath('data.pesanan.status', 'selesai');

    $baris = DB::table('notifikasi')
        ->where('user_id', $pasienUserId)
        ->where('judul', 'Pesanan telah diterima')
        ->where('tipe', 'sistem')
        ->first();

    expect($baris)->not->toBeNull()
        ->and((string) $baris->isi)->toContain($nomorPesanan);
});
