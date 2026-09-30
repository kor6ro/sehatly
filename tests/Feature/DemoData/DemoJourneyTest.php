<?php

declare(strict_types=1);

use App\Enums\PesananObatStatus;
use App\Enums\ResepStatus;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/demo3c-helpers.php';

/*
|--------------------------------------------------------------------------
| F3-02 - the four unreachable journeys, reached through the real API
|--------------------------------------------------------------------------
|
| F3 recorded BLOCKER F3-02 and named the four calls that refused:
|
| | F3 observation | this file |
| | --- | --- |
| | `POST /konsultasi/12/resep` -> 403 | 201, as the demo DOCTER, with the interaction override |
| | `GET /api/v1/pasien/resep` -> `total: 0` | the demo PATIENT's own history, non-empty |
| | `POST /resep/1/checkout` -> 422 | 201, with the address derived from the profile |
| | `GET /pesenan-obat/1` -> 404 | 200 for the demo PATIENT's own order |
|
| ## The rule this file holds itself to
|
| **The seeder creates the PRECONDITIONS; this file creates the JOURNEY.**
| `DemoDataSeeder` writes the three accounts, the doctor's schedule, the
| pharmacy and its stock - the things no public API can create. Every row that
| makes up a journey is written HERE, by the production services, through the
| production endpoints, on behalf of an account that really logged in. Nothing
| below inserts a `booking`, a `konsultasi`, a `resep`, an `invoice`, a
| `pembayaran` or a `pesanan_obat` row behind the API's back, so a row's
| existence is always evidence that the API produced it.
|
| ## The spine, in order, on one set of accounts
|
| 1. the demo PATIENT books a slot the PUBLIC availability endpoint published
| 2. the invoice is paid and a signed webhook settles it -> booking `terjadwal`
| 3. the booking becomes a consultation and the demo DOCTER accepts it
| 4. both parties exchange a chat message
| 5. the demo DOCTER writes a prescription carrying a `kontraindikasi`
|    warning, which makes `catatan_dodio` MANDATORY - the override
| 6. the demo APOTEKER verifies it, which is what makes it dispensable
| 7. the demo PATIENT checks the prescription out -> an order
| 8. the demo PATIENT reads the order -> tracking
| 9. the demo DOCTER writes the medical record off the same consultation
|
| Every one of steps 1-9 is a documented user journey, and the F3 gate could
| perform none of them.
*/

beforeEach(function (): void {
    demo3cSeed(['master', 'rbac', 'demo']);
    demo3cIkatOtp();
});

/**
 * Steps 1-3, plus the chat of step 4, and the ids every later step needs.
 *
 * @return array{booking: int, invoice: int, konsultasi: int, pasienToken: string, dokterToken: string}
 */
function demo3cTulangPunggung(): array
{
    $pasienToken = demo3cTokenAkun(DemoDataSeeder::AKUN_PASIEN);
    $dokterToken = demo3cTokenAkun(DemoDataSeeder::AKUN_DOKTER);

    if ($pasienToken === null || $dokterToken === null) {
        throw new RuntimeException('demo3cTulangPunggung: a demo account could not be logged in.');
    }

    $dokterId = demo3cDokterId();
    $tanggal = demo3cTanggal();

    // (1) The slot is READ from the public availability endpoint rather than
    // invented, so the booking below names a `jadwal_id` the product itself
    // published. F3 recorded this endpoint answering `{"slots":[]}` on a freshly
    // seeded database, because nothing anywhere could create a `dokter_jadwal`.
    $slots = demo3cAs()->getJson('/api/v1/dokter/'.$dokterId.'/slot?tanggal='.$tanggal);

    $slots->assertOk();

    $slot = $slots->json('data.slots.0');

    if (! is_array($slot)) {
        throw new RuntimeException('demo3cTulangPunggung: the demo doctor published no slot on '.$tanggal);
    }

    demo3cAs($pasienToken)->postJson('/api/v1/booking', [
        'dokter_id' => $dokterId,
        'jadwal_id' => (int) $slot['jadwal_id'],
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => $tanggal,
        'slot_mulai' => (string) $slot['jam_mulai'],
        'keluhan' => 'Sakit kepala sejak tiga hari (laporan demo).',
    ])->assertCreated();

    $booking = DB::table('booking')->orderByDesc('id')->first();

    if ($booking === null) {
        throw new RuntimeException('demo3cTulangPunggung: the API reported a booking and the table holds none.');
    }

    // The schedule row is now ATTACHED, which is exactly what F3-08 found
    // missing on every booking a freshly seeded database could produce.
    expect((int) $booking->jadwal_id)->toBe((int) $slot['jadwal_id']);

    // (2) The invoice the booking minted, paid through the real endpoint and
    // settled by a correctly signed provider delivery.
    $invoice = DB::table('invoice')
        ->where('referensi_tipe', 'booking')
        ->where('referensi_id', $booking->id)
        ->first();

    if ($invoice === null) {
        throw new RuntimeException('demo3cTulangPunggung: the booking minted no invoice.');
    }

    demo3cAs($pasienToken)
        ->postJson('/api/v1/invoice/'.$invoice->id.'/bayar', ['metode_id' => demo3cMetode()])
        ->assertCreated();

    $pembayaran = DB::table('pembayaran')->where('invoice_id', $invoice->id)->first();

    if ($pembayaran === null) {
        throw new RuntimeException('demo3cTulangPunggung: the payment endpoint wrote no pembayaran row.');
    }

    [$badan, $server, $gateway] = demo3cWebhook(
        (string) $pembayaran->gateway,
        (string) $pembayaran->nomor_referensi,
        (string) $pembayaran->jumlah,
    );

    demo3cKirim($badan, $server, $gateway)->assertOk();

    // The settlement advanced the booking, which is the only thing that makes
    // step 3 legal: `KonsultasiService::STATUS_BOOKING_BISA_MULAI` names
    // `terjadwal` and `check_in` and nothing else.
    expect((string) DB::table('booking')->where('id', $booking->id)->value('status'))->toBe('terjadwal');

    // (3) The booking becomes a consultation and the doctor accepts it.
    $mulai = demo3cAs($pasienToken)->postJson('/api/v1/konsultasi/mulai', [
        'booking_id' => (int) $booking->id,
    ]);

    $mulai->assertCreated();

    $konsultasiId = (int) $mulai->json('data.konsultasi.id');

    demo3cAs($dokterToken)->putJson('/api/v1/konsultasi/'.$konsultasiId.'/terima')->assertOk();

    expect((string) DB::table('konsultasi')->where('id', $konsultasiId)->value('status'))->toBe('berlangsung');

    // (4) Both parties speak. F3 recorded, as item 9 of its unexercisable list,
    // that "every message in consultation 12 is mine" - it never had a doctor
    // to reply.
    demo3cAs($dokterToken)->postJson(
        '/api/v1/konsultasi/'.$konsultasiId.'/chat',
        ['tipe_pesan' => 'teks', 'isi' => 'Selamat siang, apa keluhan Anda?'],
    )->assertCreated();

    demo3cAs($pasienToken)->postJson(
        '/api/v1/konsultasi/'.$konsultasiId.'/chat',
        ['tipe_pesan' => 'teks', 'isi' => 'Sakit kepala sejak tiga hari.'],
    )->assertCreated();

    return [
        'booking' => (int) $booking->id,
        'invoice' => (int) $invoice->id,
        'konsultasi' => $konsultasiId,
        'pasienToken' => $pasienToken,
        'dokterToken' => $dokterToken,
    ];
}

/**
 * A prescription the demo DOCTER writes with the interaction override, and its
 * id.
 *
 * The bare prescription is attempted FIRST and must be refused, because the
 * override is the claim under test and a test that only ever sends the note
 * would pass with the mandate removed.
 */
function demo3cResepDenganOverride(string $dokterToken, int $konsultasiId): int
{
    $tanpaCatatan = demo3cAs($dokterToken)->postJson(
        '/api/v1/konsultasi/'.$konsultasiId.'/resep',
        ['items' => [[
            'obat_id' => demo3cObat('OBT-0002'),
            'aturan_pakai' => '3 x 1 kapsul sesudah makan',
            'jumlah' => 10,
        ]]],
    );

    $tanpaCatatan->assertStatus(422);

    $catatan = 'Efek sampingnya sudah dijelaskan kepada pasien dan disetujui.';

    $diterima = demo3cAs($dokterToken)->postJson(
        '/api/v1/konsultasi/'.$konsultasiId.'/resep',
        [
            'catatan_dodio' => $catatan,
            'items' => [[
                'obat_id' => demo3cObat('OBT-0002'),
                'aturan_pakai' => '3 x 1 kapsul sesudah makan',
                'jumlah' => 10,
            ]],
        ],
    );

    $diterima->assertCreated();

    // The warning really is a `kontraindikasi` and the acknowledgement really
    // is what was demanded - read off the response the product publishes, not
    // inferred from the request having succeeded.
    expect($diterima->json('data.acknowledgement.diminta'))->toBeTrue();
    expect($diterima->json('data.acknowledgement.catatan_dodio'))->toBe($catatan);
    expect(array_column($diterima->json('data.warning'), 'tingkat'))->toContain('kontraindikasi');

    return (int) $diterima->json('data.resep.id');
}

// =====================================================================
// Journey 1 - prescription + interaction override
// =====================================================================

test('JOURNEY 1: the demo DOCTER composes a prescription and overrides a contraindication', function (): void {
    $punggung = demo3cTulangPunggung();

    $resepId = demo3cResepDenganOverride($punggung['dokterToken'], $punggung['konsultasi']);

    expect($resepId)->toBeGreaterThan(0);

    // F3 recorded `GET /api/v1/pasien/resep` answering `total: 0` because no
    // patient could ever obtain a prescription. It is the PATIENT's own
    // history, read with the patient's own token.
    $riwayat = demo3cAs($punggung['pasienToken'])->getJson('/api/v1/pasien/resep');

    $riwayat->assertOk();
    expect($riwayat->json('meta.total'))->toBeGreaterThan(0);
    expect($riwayat->json('data.resep.0.id'))->toBe($resepId);

    // And the interaction endpoint F3 got a 404 for, on the same prescription.
    $cek = demo3cAs($punggung['pasienToken'])->getJson('/api/v1/resep/'.$resepId.'/cek-interaksi');

    $cek->assertOk();
    expect($cek->json('data.resep_id'))->toBe($resepId);
    expect($cek->json('data.wajib_catatan'))->toBeTrue();
});

// =====================================================================
// Journeys 2, 3 and 4 - pharmacy signature, checkout, tracking
// =====================================================================

test('JOURNEYS 2-4: pharmacy verification, order checkout and order tracking', function (): void {
    $punggung = demo3cTulangPunggung();
    $apotekerToken = demo3cTokenAkun(DemoDataSeeder::AKUN_APOTEKER);

    if ($apotekerToken === null) {
        throw new RuntimeException('The demo pharmacist could not be logged in.');
    }

    $resepId = demo3cResepDenganOverride($punggung['dokterToken'], $punggung['konsultasi']);

    // A checkout before the pharmacy signs is refused, and the reason is the
    // status - the rule `resep_verifikasi`'s own COMMENT states.
    $terlaluDulu = demo3cAs($punggung['pasienToken'])
        ->postJson('/api/v1/resep/'.$resepId.'/checkout', ['apotek_id' => demo3cApotekId()]);

    $terlaluDulu->assertStatus(422);
    expect($terlaluDulu->json('errors.status'))->not->toBeNull();

    // The demo PHARMACIST signs it. F3 could not even reach this route:
    // `user 7` (`apoteker`) held a password nobody knew.
    $verifikasi = demo3cAs($apotekerToken)->postJson('/api/v1/resep/'.$resepId.'/verifikasi', [
        'status' => 'sesuai',
        'catatan' => 'Resep diperiksa dan siap untuk diserahkan.',
    ]);

    $verifikasi->assertCreated();
    expect($verifikasi->json('data.resep.status'))->toBe(ResepStatus::Diverifikasi->value);

    // The checkout. F3 recorded `POST /resep/1/checkout` answering 422 because
    // it SENT `alamat_kirim`, which the request PROHIBITS - the address is
    // derived from the patient's own profile, which the demo seeder filled in.
    // Nothing of the sort is sent here at all.
    $checkout = demo3cAs($punggung['pasienToken'])->postJson('/api/v1/resep/'.$resepId.'/checkout', [
        'tipe' => 'resep_dokter',
        'apotek_id' => demo3cApotekId(),
        'biaya_kirim' => '15000.00',
        'kurir' => 'internal',
    ]);

    $checkout->assertCreated();

    $pesananId = (int) $checkout->json('data.pesanan.id');

    expect($pesananId)->toBeGreaterThan(0);
    expect((string) DB::table('pesanan_obat')->where('id', $pesananId)->value('status'))
        ->toBe(PesananObatStatus::default());

    // The shipping address is the profile SNAPSHOT, not a request field.
    expect((string) DB::table('pesanan_obat')->where('id', $pesananId)->value('alamat_kirim'))
        ->toBe('Jl. Demo Sehatly No. 1, Bandung, Jawa Barat 40115');

    // The stock the seeder stocked was really decremented by the checkout.
    $apotekId = (int) DB::table('pesanan_obat')->where('id', $pesananId)->value('apotek_id');

    expect((int) DB::table('apotek_stok')
        ->where('apotek_id', $apotekId)
        ->where('obat_id', demo3cObat('OBT-0002'))
        ->value('jumlah_stok'))->toBe(490);

    // ORDER TRACKING. F3 recorded `GET /pesenan-obat/1` -> 404 because the only
    // orders in the table belonged to an unloginable patient.
    $lacak = demo3cAs($punggung['pasienToken'])->getJson('/api/v1/pesanan-obat/'.$pesananId);

    $lacak->assertOk();
    expect($lacak->json('data.pesanan.id'))->toBe($pesananId);
    expect($lacak->json('data.pesanan.resep_id'))->toBe($resepId);
    expect($lacak->json('data.pesanan.tracking'))->not->toBeEmpty();

    // And the stock endpoint the alternatives list is built from answers too.
    demo3cAs($punggung['pasienToken'])
        ->getJson('/api/v1/obat/'.demo3cObat('OBT-0002').'/stok')
        ->assertOk();
});

// =====================================================================
// Journey 5 - the doctor half of the medical record
// =====================================================================

test('JOURNEY 5: the demo DOCTER writes the medical record, and the patient reads it', function (): void {
    $punggung = demo3cTulangPunggung();

    // A patient token is refused - the same 403 F3 recorded. The point is that
    // the DOCTOR is now reachable, not that the guard changed.
    demo3cAs($punggung['pasienToken'])
        ->postJson('/api/v1/konsultasi/'.$punggung['konsultasi'].'/rekam-medis', ['keluhan_utama' => 'Dicoba oleh pasien.'])
        ->assertStatus(403);

    $simpan = demo3cAs($punggung['dokterToken'])->postJson(
        '/api/v1/konsultasi/'.$punggung['konsultasi'].'/rekam-medis',
        [
            'keluhan_utama' => 'Sakit kepala sejak tiga hari.',
            'subjektif' => 'Nyeri skor 6 dari 10, tidak disertai demam.',
            'objektif' => 'Tekanan darah 120/80 mmHg.',
            'asesmen' => 'Nyeri kepala primer.',
            'plan' => 'Analgesik, hidrasi cukup, kontrol bila memburuk.',
        ],
    );

    $simpan->assertCreated();

    $rekamId = (int) $simpan->json('data.rekam_medis.id');

    expect($rekamId)->toBeGreaterThan(0);

    // And the patient can read their own record back through the same endpoint
    // F3 got a 404 for.
    $baca = demo3cAs($punggung['pasienToken'])->getJson('/api/v1/rekam-medis/'.$rekamId);

    $baca->assertOk();
    expect($baca->json('data.rekam_medis.id'))->toBe($rekamId);
    expect($baca->json('data.rekam_medis.keluhan_utama'))->toBe('Sakit kepala sejak tiga hari.');
});

// =====================================================================
// The two inboxes, seen from the demo accounts
// =====================================================================

test('the demo accounts receive notifications from the real flows', function (): void {
    $punggung = demo3cTulangPunggung();

    $untukPasien = demo3cAs($punggung['pasienToken'])->getJson('/api/v1/notifikasi');
    $untukDokter = demo3cAs($punggung['dokterToken'])->getJson('/api/v1/notifikasi');

    // F3 answered `total: 0` after a booking, a settled payment, a started
    // consultation and a sent message. All four happened here.
    expect($untukPasien->json('meta.total'))->toBe(3)
        ->and($untukDokter->json('meta.total'))->toBe(1);

    $tipePasien = array_column($untukPasien->json('data.notifikasi'), 'tipe');

    sort($tipePasien);

    expect($tipePasien)->toBe(['booking', 'chat', 'pembayaran']);
    expect(array_column($untukDokter->json('data.notifikasi'), 'tipe'))->toBe(['chat']);
});
