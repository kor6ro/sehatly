<?php

declare(strict_types=1);

use App\Enums\PesananObatStatus;
use Database\Seeders\RbacSeeder;
use App\Models\PesananObat;
use App\Models\PesananObatTracking;
use App\Services\PesananObat\PesananObatService;
use App\Services\PesananObat\PesananObatStateMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/pesanan46-helpers.php';

beforeEach(function (): void {
    // Declared in THIS file, not in `pesanan46-helpers.php`: that file is
    // `require_once`d by three test files, so a hook declared in it is
    // registered for the FIRST one only and the other two run with none. The
    // symptom is 35 errors of "RbacCatalog::ROLES names pasien but `roles`
    // holds no such row" in a full-suite run that passes file by file, because
    // `po46Selesai()` has to delete the RBAC catalogue DURABLY (the
    // `RefreshDatabase` wrapper was committed) and the next file has nothing to
    // re-seed it.
    po46Bersihkan();
    po46KunciJam();
    $this->seed(RbacSeeder::class);
});

afterEach(function (): void {
    po46LepasJam();
});

/*
|--------------------------------------------------------------------------
| Todo 46 - the tracking trail and the order's status machine
|--------------------------------------------------------------------------
|
| ## WHERE THE VOCABULARY COMES FROM, and it is not the tracking column
|
| `pesanan_obat_tracking.status` is `VARCHAR(100) NOT NULL` (`:822`). It has no
| ENUM, no CHECK and no index, and it SHARES A NAME with `pesanan_obat.status`,
| which IS a six-value ENUM declared across two lines (`:810`-`:811`). So the DDL
| contains two different notions called `status` and only one of them is
| constrained.
|
| The vocabulary this application writes into the trail is therefore DERIVED from
| the ORDER's ENUM, and the derivation is the whole content of the design:
|
| - the trail is an APPEND-ONLY history of the order's own status changes, and
|   every row it holds carries a member of `PesananObatStatus::nilai()`;
| - the order's CURRENT state is read from `pesanan_obat.status`, never
|   inferred by taking the last trail row.
|
| `docs/schema-notes.md` requires exactly this of todo 46 ("must validate this
| column in the application and must read the order's state from
| `pesanan_obat.status`, never from here"), and the test below proves the second
| half as an observable property: the trail and the order are WRITTEN
| independently, so a test can make them disagree and assert which one the API
| reports.
|
| A courier's own vocabulary (`in_transit`, `kirim`) has nowhere to live here: no
| `pesanan_obat` status means it, so accepting it would record a state no UI has
| a badge for and no state machine can advance from.
|
| ## NO ROUTE drives a transition, and that is a decision to state
|
| The plan names three endpoints for this todo and this file registers none of
| them, because the plan names none for a status write: the payment webhook
| (todo 45) is the caller that moves an order off `menunggu_pembayaran`, and the
| pharmacy's dispatch is a later module's surface. Inventing a fourth endpoint
| would be inventing a write surface the plan does not describe, so
| {@see PesananObatService::ubahStatus()} is a public SERVICE method and every
| transition is driven through it against the real database.
|
| ## `pesanan_obat_tracking` has NO TIMESTAMPS
|
| Five columns (`:820`-`:825`) and none of them is `dibuat_at` or `diubah_at`;
| the only time is `waktu DATETIME NOT NULL` (`:825`). The model is
*  `$timestamps = false` and the service writes `waktu` from the application
 *  clock. Nothing in this file may assume `created_at` or `updated_at`, and one
//  test asserts the columns are absent at the DATABASE level rather than only
//  absent from the resource.
*/

// =====================================================================
// The vocabulary, derived
// =====================================================================

test('the trail vocabulary is the ORDER enum, and the column it is written to is VARCHAR(100)', function (): void {
    $kolom = po46Spec()->table('pesanan_obat_tracking')->columns['status'];

    // The DDL really does permit any string here. Asserted, because the whole
    // narrowing is invisible from the schema and a reader who has not seen it
    // will assume the column is constrained.
    expect($kolom->type)->toBe('varchar(100)')
        ->and($kolom->nullable)->toBeFalse()
        ->and(po46Enum('pesanan_obat', 'status'))->toBe(PesananObatStatus::nilai())
        ->and(PesananObatStatus::nilai())->toHaveCount(6);

    // And the application refuses a value outside it. `bolehDilacak()` is the
    // predicate the state machine owns; it must be exactly "is a member of the
    // order's enum", not a second vocabulary of its own.
    $mesin = app(PesananObatStateMachine::class);

    foreach (PesananObatStatus::nilai() as $status) {
        expect($mesin->bolehDilacak($status))->toBeTrue();
    }

    foreach (['in_transit', 'kirim', 'DIBATALKAN', 'selesai ', 'selesaii', 'diterima', ''] as $asing) {
        expect($mesin->bolehDilacak($asing))->toBeFalse("[" . $asing . '] was accepted as a tracking status');
    }
});

test('the trail has NO created_at and NO updated_at, and `waktu` is the creation stamp', function (): void {
    $kolom = array_map(
        static fn ($row): string => (string) $row->Field,
        DB::select('SHOW COLUMNS FROM pesanan_obat_tracking'),
    );

    expect($kolom)->toBe(['id', 'pesanan_obat_id', 'status', 'keterangan', 'lokasi', 'waktu'])
        ->and($kolom)->not->toContain('dibuat_at')
        ->and($kolom)->not->toContain('diubah_at')
        ->and($kolom)->not->toContain('created_at')
        ->and($kolom)->not->toContain('updated_at');

    // The model agrees, and the base class would write a `created_at` column
    // that does not exist if it did not.
    expect((new PesananObatTracking)->usesTimestamps())->toBeFalse();
});

// =====================================================================
// Every transition, and the refusal for every other pair
// =====================================================================

test('EVERY legal edge of the map moves the order and appends exactly one trail row', function (): void {
    $mesin = app(PesananObatStateMachine::class);
    $layanan = app(PesananObatService::class);
    $dijalankan = [];

    foreach (PesananObatStateMachine::TRANSISI as $dari => $tujuan) {
        foreach ($tujuan as $ke) {
            $dunia = po46Dunia();

            $dibuat = test()->withToken(po46As($dunia['user'])['Authorization'])
                ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
                ->assertCreated();

            $id = (int) $dibuat->json('data.pesanan.id');

            // Walk the order onto the state this edge leaves FROM, through the
            // service, so the edge under test is the one being exercised rather
            // than a state planted behind the machine's back.
            $sebelum = DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->count();

            po46Jalur($layanan, $id, (string) $dibuat->json('data.pesanan.status'), $dari);

            $sebelum = DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->count();

            $layanan->ubahStatus($id, $ke, 'Langkah '.$dari.' ke '.$ke, 'Jakarta');

            $pesanan = PesananObat::query()->findOrFail($id);
            $jejak = DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->orderBy('id')->get();

            // The order moved, and the trail grew by exactly ONE row.
            expect((string) $pesanan->status)->toBe($ke)
                ->and($jejak)->toHaveCount($sebelum + 1)
                ->and((string) $jejak->last()->status)->toBe($ke)
                ->and((string) $jejak->last()->keterangan)->toBe('Langkah '.$dari.' ke '.$ke)
                ->and((string) $jejak->last()->lokasi)->toBe('Jakarta')
                // `waktu` is the creation stamp this table has instead of one.
                ->and((string) $jejak->last()->waktu)->not->toBe('');

            // And the trail is append-only: every status the walk passed through
            // is STILL THERE, in its original row, because a transition appends
            // and never rewrites. Read the rows by id rather than by status so a
            // rewritten row would show up as a changed `keterangan` rather than
            // as a missing status.
            foreach (DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->orderBy('id')->get() as $satu) {
                if ((string) $satu->status === $ke) {
                    continue;
                }

                expect($satu->keterangan)->not->toBe('Langkah '.$dari.' ke '.$ke);
            }

            $dijalankan[] = $dari.' -> '.$ke;
        }
    }

    // EIGHT edges: four along the happy path and `dibatalkan` from each of the
    // four live states. 6x6 = 36 pairs, of which 8 are legal and 28 are not.
    expect($dijalankan)->toHaveCount(8)
        ->and($dijalankan)->toBe([
            'menunggu_pembayaran -> diproses',
            'menunggu_pembayaran -> dibatalkan',
            'diproses -> siap',
            'diproses -> dibatalkan',
            'siap -> sedang_dikirim',
            'siap -> dibatalkan',
            'sedang_dikirim -> selesai',
            'sedang_dikirim -> dibatalkan',
        ]);
});

test('EVERY illegal pair of the six-by-six matrix is refused with two messages and writes nothing', function (): void {
    // The full 6x6 matrix rather than a sample: 36 pairs, of which 9 are legal,
    // so 28 must be refused. A sampled matrix would miss a typo in the map.
    $mesin = app(PesananObatStateMachine::class);
    $layanan = app(PesananObatService::class);
    $nilai = PesananObatStatus::nilai();

    $permitted = 0;
    $ditolak = 0;

    foreach ($nilai as $dari) {
        foreach ($nilai as $ke) {
            $dunia = po46Dunia();

            $dibuat = test()->withToken(po46As($dunia['user'])['Authorization'])
                ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
                ->assertCreated();

            $id = (int) $dibuat->json('data.pesanan.id');

            po46Jalur($layanan, $id, (string) $dibuat->json('data.pesanan.status'), $dari);

            $sebelum = (int) DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->count();

            $sah = $mesin->boleh((string) DB::table('pesanan_obat')->where('id', $id)->value('status'), $ke);

            if ($sah) {
                $permitted++;

                continue;
            }

            $e = po46Tangkap(
                static fn () => $layanan->ubahStatus($id, $ke, null, null, null),
                ValidationException::class,
            );

            $pesan = $e->errors()['status'];

            expect($pesan)->toHaveCount(2)
                ->and($pesan[0])->toBe('Transisi status pesanan tidak diperbolehkan.')
                ->and($pesan[1])->toBe(sprintf('Status "%s" tidak dapat menjadi "%s".', $dari, $ke));

            // NOTHING was written: the status did not move and the trail did not
            // grow, so a refused transition is invisible to a reader of the
            // order.
            expect((string) DB::table('pesanan_obat')->where('id', $id)->value('status'))->toBe($dari)
                ->and((int) DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->count())->toBe($sebelum);

            $ditolak++;
        }
    }

    expect($permitted)->toBe(8)
        ->and($ditolak)->toBe(28);
});

test('a terminal order refuses everything, and a value that is not a status is a DIFFERENT 422', function (): void {
    $layanan = app(PesananObatService::class);

    foreach (PesananObatStateMachine::TERMINAL as $terminal) {
        $dunia = po46Dunia();

        $dibuat = test()->withToken(po46As($dunia['user'])['Authorization'])
            ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
            ->assertCreated();

        $id = (int) $dibuat->json('data.pesanan.id');

        po46Jalur($layanan, $id, (string) $dibuat->json('data.pesanan.status'), $terminal);

        expect((string) DB::table('pesanan_obat')->where('id', $id)->value('status'))->toBe($terminal);

        foreach (PesananObatStatus::nilai() as $ke) {
            po46Tangkap(
                static fn () => $layanan->ubahStatus($id, $ke),
                ValidationException::class,
            );
        }
    }

    // A value outside the ENUM is refused BEFORE the map is consulted, and its
    // message names the six legal values - a broken caller and a workflow rule
    // are two different problems with two different fixes.
    $dunia = po46Dunia();

    $dibuat = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    $asing = po46Tangkap(
        static fn () => $layanan->ubahStatus((int) $dibuat->json('data.pesanan.id'), 'in_transit'),
        ValidationException::class,
    );

    expect($asing->errors()['status'])->toHaveCount(2)
        ->and($asing->errors()['status'][0])->toBe('Status pesanan tidak dikenal.')
        ->and($asing->errors()['status'][1])->toContain('menunggu_pembayaran, diproses, siap, sedang_dikirim, selesai, dibatalkan');
});

test('a courier tracking number is accepted on ONE edge and refused on the rest', function (): void {
    // `pesanan_obat.no_resi VARCHAR(50) NULL` (`:806`) is free text, NOT unique
    // and NOT indexed, and nullable while `kurir` is nullable too. A number on an
    // order that has not shipped is a courier reference on a row describing a
    // parcel that has not left, so the service refuses it rather than storing it.
    $layanan = app(PesananObatService::class);

    $dunia = po46Dunia();

    $dibuat = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', ['kurir' => 'jne'])
        ->assertCreated()
        ->assertJsonPath('data.pesanan.kurir', 'jne')
        ->assertJsonPath('data.pesanan.no_resi', null);

    $id = (int) $dibuat->json('data.pesanan.id');

    // `menunggu_pembayaran -> diproses` with a number: refused.
    $salah = po46Tangkap(
        static fn () => $layanan->ubahStatus($id, 'diproses', null, null, 'JNE123456789'),
        ValidationException::class,
    );

    expect($salah->errors()['no_resi'])->toHaveCount(2)
        ->and($salah->errors()['no_resi'][0])->toBe('Nomor resi hanya dapat diisi saat status pesanan menjadi sedang_dikirim.')
        ->and((string) DB::table('pesanan_obat')->where('id', $id)->value('status'))->toBe('menunggu_pembayaran');

    // Walk to the dispatch edge and supply it there.
    $layanan->ubahStatus($id, 'diproses');
    $layanan->ubahStatus($id, 'siap');
    $layanan->ubahStatus($id, 'sedang_dikirim', 'Tiba di Sorting Center', 'Jakarta', 'JNE123456789');

    expect((string) DB::table('pesanan_obat')->where('id', $id)->value('no_resi'))->toBe('JNE123456789');
});

test('the order state is read from pesanan_obat.status, NEVER from the last trail row', function (): void {
    // The two are written in the same transaction, so they can only be made to
    // disagree by writing behind the service's back - which is exactly what this
    // test does, and which is the only way to prove the read direction.
    //
    // The DDL permits this: `pesanan_obat_tracking.status` is a `VARCHAR(100)`
    // (`:822`), so `diproses` is writable there by any writer, and a trail whose
    // last row says `sedang_dikirim` beside an order reading `menunggu_pembayaran`
    // is perfectly representable. The API must report the ORDER.
    $dunia = po46Dunia();

    $dibuat = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    $id = (int) $dibuat->json('data.pesanan.id');

    DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)
        ->update(['status' => 'sedang_dikirim']);

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->getJson('/api/v1/pesanan-obat/'.$id);

    $response->assertOk()
        ->assertJsonPath('data.pesanan.status', 'menunggu_pembayaran')
        // The trail is published as stored, INCLUDING the value the service
        // would not write: this response is a record, not a filter. A client
        // that disagrees with itself is told so by the data rather than being
        // shown a sanitised version of it.
        ->assertJsonPath('data.pesanan.tracking.0.status', 'sedang_dikirim');

    expect((string) DB::table('pesanan_obat')->where('id', $id)->value('status'))->toBe('menunggu_pembayaran');
});

test('the trail is published oldest-first and a second checkout for the same prescription adds a second trail', function (): void {
    // Two orders on one prescription is legal: `pesanan_obat.resep_id` carries a
    // foreign key (`:814`) and no UNIQUE, and refusing it would be policy this
    // todo has no basis to invent. What must hold is that each order's trail is
    // its OWN and the patient's order read is scoped to them.
    $dunia = po46Dunia();

    $pertama = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    $kedua = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    $idA = (int) $pertama->json('data.pesanan.id');
    $idB = (int) $kedua->json('data.pesanan.id');

    expect($idA)->not->toBe($idB)
        ->and(DB::table('pesanan_obat_tracking')->whereIn('pesanan_obat_id', [$idA, $idB])->count())->toBe(2);

    app(PesananObatService::class)->ubahStatus($idA, 'diproses', 'Diproses', 'Jakarta');

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->getJson('/api/v1/pesanan-obat/'.$idA);

    // The trail of the order asked for, oldest first, and NOT the other order's.
    $status = array_column($response->json('data.pesanan.tracking'), 'status');

    expect($status)->toBe(['menunggu_pembayaran', 'diproses'])
        ->and(DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $idB)->count())->toBe(1);

    // And the stock was reserved TWICE, once per order, because the decrement is
    // a single writer called once per checkout. That is the property todo 45's
    // webhook has to respect when it settles the payment: it must NOT decrement
    // again.
    expect((int) DB::table('apotek_stok')->where('id', $dunia['stok'])->value('jumlah_stok'))->toBe(80);
});

test('a status change is stamped from the application clock, and the clock is the test s', function (): void {
    $dunia = po46Dunia();

    $dibuat = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    $id = (int) $dibuat->json('data.pesanan.id');

    // The checkout row.
    $awal = DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->orderBy('id')->first();

    expect((string) $awal->waktu)->toBe(po46Jam()->format('Y-m-d H:i:s'));

    // Move the clock and advance the order, so a `CURRENT_TIMESTAMP` default
    // would be visibly different from the application's clock.
    Carbon::setTestNow(Carbon::parse('2026-03-12 08:30:00'));

    app(PesananObatService::class)->ubahStatus($id, 'diproses');

    $akhir = DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->orderByDesc('id')->first();

    expect((string) $akhir->waktu)->toBe('2026-03-12 08:30:00')
        ->and((string) $akhir->waktu)->not->toBe((string) $awal->waktu);
});

/**
 * Walk an order to `$tujuan` through the REAL service, so the edge under test is
 * the one the map declares rather than a state planted behind its back.
 *
 * A shortest path over {@see PesananObatStateMachine::TRANSISI}, in the same
 * spirit as `ResepVerifikasiService::jalur()`.
 *
 * @param  string  $dari  the state the order is in now
 * @param  string  $tujuan  the state this test is about to leave FROM
 */
function po46Jalur(PesananObatService $layanan, int $id, string $dari, string $tujuan): void
{
    if ($dari === $tujuan) {
        return;
    }

    $parent = [$dari => null];
    $antrian = [$dari];

    while ($antrian !== []) {
        $sekarangSekarang = array_shift($antrian);

        foreach (PesananObatStateMachine::TRANSISI[$sekarangSekarang] ?? [] as $berikutnya) {
            if (array_key_exists($berikutnya, $parent)) {
                continue;
            }

            $parent[$berikutnya] = $sekarangSekarang;

            if ($berikutnya === $tujuan) {
                $langkah = array_reverse(po46Rantai($parent, $tujuan));

                foreach (array_slice($langkah, 1) as $satu) {
                    $layanan->ubahStatus($id, $satu);
                }

                return;
            }

            $antrian[] = $berikutnya;
        }
    }

    throw new RuntimeException(sprintf('No legal path from "%s" to "%s".', $dari, $tujuan));
}

/**
 * @param  array<string, ?string>  $parent
 * @return non-empty-list<string>
 */
function po46Rantai(array $parent, string $tujuan): array
{
    $rantai = [$tujuan];
    $sekarang = $tujuan;

    while (($parent[$sekarang] ?? null) !== null) {
        $sekarang = (string) $parent[$sekarang];
        $rantai[] = $sekarang;
    }

    return $rantai;
}
