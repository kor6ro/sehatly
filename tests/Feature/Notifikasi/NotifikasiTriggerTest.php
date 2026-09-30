<?php

declare(strict_types=1);

use App\Enums\ResepStatus;
use Database\Seeders\MetodePembayaranSeeder;
use Database\Seeders\ObatSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/notifikasi-helpers.php';

/*
|--------------------------------------------------------------------------
| F3-05 - the five notification triggers, driven from the real flows
|--------------------------------------------------------------------------
|
| ## The defect this file exists to pin
|
| The F3 manual-QA gate recorded F3-05 as MAJOR: `NotificationService`
| implements all five triggers the notification centre empty state names, and
| **nothing in `app/` ever calls it**. F3 exercised four of the five domain
| actions a user can perform - created a booking, settled a payment through the
| signed webhook, started a consultation and sent a chat message - and
| `GET /api/v1/notifikasi` answered `total: 0` after every one of them.
|
| "The service exists" is not a defence of an unreachable feature, and neither
| is a test that calls `NotificationService::bookingDibuat()` directly and
| proves the service works. **Every test below performs the DOMAIN ACTION over
| HTTP and then counts the `notifikasi` rows it produced.** A direct call to
| the service would pass with the wiring removed, which is exactly the shape of
| test that let this defect ship.
|
| ## Each trigger, and the flow that now fires it
|
| | `tipe` | method | fired by the real action |
| | --- | --- | --- |
| | `booking` | `bookingDibuat` | `POST /api/v1/booking` |
| | `booking` | `bookingDibatalkan` | `PUT /api/v1/booking/{id}/batalkan` |
| | `pembayaran` | `pembayaranSelesai` | a correctly signed `POST /api/v1/webhook/payment/{gateway}` |
| | `resep` | `resepSiap` | `POST /api/v1/resep/{id}/verifikasi` by a pharmacist |
| | `chat` | `pesanBaru` | `POST /api/v1/konsultasi/{id}/chat` |
|
| ## The rows are read through the query builder
|
| See `notifikasi-helpers.php`. Hydrating the `Notifikasi` model would fire no
| event but would make the assertion depend on a cast, and `DB::table()` cannot
| write an `audit_log` row that would change the count being measured.
|
| ## `notifikasi` is in the audit scope, so every row is audited
|
| `notifikasi.user_id` references `users` (:1046), so the `AuditScope`
| foreign-key closure from `users` reaches it and the global `AuditObserver`
| writes one `audit_log` `create` row per notification. That is intended - the
| service docblock says the save goes through the MODEL deliberately - and it
| is why the first test asserts the audit row exists rather than only the
| inbox row.
|
| ## No `markTestSkipped` anywhere
|
| The suite gate is zero skipped. A test that could not run would be a
| mechanism for hiding a red, not a way of being honest about one.
*/

beforeEach(function (): void {
    // Three seeders and no more: `ObatSeeder` for a catalogue drug the
    // prescription names, `MetodePembayaranSeeder` for a method the payment
    // endpoint accepts, and `RbacSeeder` for the roles the guards read. The
    // rest of the seed chain is irrelevant to these five flows and seeding it
    // would only widen the surface a failure could come from.
    $this->seed([
        ObatSeeder::class,
        MetodePembayaranSeeder::class,
        RbacSeeder::class,
    ]);
});

// =====================================================================
// Trigger 1 - booking dibuat
// =====================================================================

test('CONTROL: the notifikasi table is empty before any domain action', function (): void {
    $dunia = ntf5Dunia();

    expect(DB::table('notifikasi')->count())->toBe(0)
        ->and(ntf5Baris((int) $dunia['pasien']->getKey()))->toBe([]);
});

test('creating a booking over the API writes a booking notification to the patient', function (): void {
    $dunia = ntf5Dunia();

    $response = ntf5As($dunia['pasien'])->postJson('/api/v1/booking', [
        'dokter_id' => $dunia['dokterId'],
        'tipe_layanan' => 'chat',
        'tanggal_kunjungan' => ntf5Tanggal(),
        'slot_mulai' => '09:00:00',
        'keluhan' => 'Sakit kepala sejak tiga hari.',
    ]);

    $response->assertCreated();

    $bookingId = (int) $response->json('data.booking.id');

    expect($bookingId)->toBeGreaterThan(0);

    $baris = ntf5Satu((int) $dunia['pasien']->getKey(), 'booking');

    expect($baris)->not->toBeNull();
    expect((string) $baris->judul)->toBe('Booking berhasil dibuat.');
    expect((string) $baris->tautan)->toBe('/api/v1/booking/'.$bookingId);
    expect(json_decode((string) $baris->payload, true))
        ->toBe(['booking_id' => $bookingId]);
    expect($baris->dibaca_at)->toBeNull();

    // The row is in the audit scope, so it is a MODEL write and not a builder
    // insert - which is what makes it a durable record rather than a side
    // effect.
    expect(DB::table('audit_log')
        ->where('aksi', 'create')
        ->where('tabel_target', 'notifikasi')
        ->where('record_id', (string) $baris->id)
        ->count())->toBe(1);
});

test('the booking notification is readable through GET /notifikasi', function (): void {
    $dunia = ntf5Dunia();

    ntf5As($dunia['pasien'])->postJson('/api/v1/booking', [
        'dokter_id' => $dunia['dokterId'],
        'tipe_layanan' => 'chat',
        'tanggal_kunjungan' => ntf5Tanggal(),
        'slot_mulai' => '09:00:00',
    ])->assertCreated();

    $response = ntf5As($dunia['pasien'])->getJson('/api/v1/notifikasi');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('meta.unread'))->toBe(1)
        ->and($response->json('data.notifikasi.0.tipe'))->toBe('booking');
});

// =====================================================================
// Trigger 2 - booking dibatalkan
// =====================================================================

test('cancelling a booking over the API notifies the party that did not cancel', function (): void {
    $dunia = ntf5Dunia();

    $bookingId = (int) ntf5As($dunia['pasien'])->postJson('/api/v1/booking', [
        'dokter_id' => $dunia['dokterId'],
        'tipe_layanan' => 'chat',
        'tanggal_kunjungan' => ntf5Tanggal(),
        'slot_mulai' => '09:00:00',
    ])->assertCreated()->json('data.booking.id');

    // The patient cancels, so the DOCTOR is the party left holding a cancelled
    // appointment and is the one told about it.
    ntf5As($dunia['pasien'])
        ->putJson('/api/v1/booking/'.$bookingId.'/batalkan', ['alasan_pembatalan' => 'Jadwal berubah mendadak.'])
        ->assertOk();

    $untukDokter = ntf5Satu((int) $dunia['dokter']->getKey(), 'booking');

    expect($untukDokter)->not->toBeNull();
    expect((string) $untukDokter->judul)->toBe('Booking dibatalkan.');
    expect((string) $untukDokter->tautan)->toBe('/api/v1/booking/'.$bookingId);
    // Canonicalising, not ordered: MySQL's `JSON` type normalises an object's
    // member order, so a key-order comparison would be asserting the storage
    // engine rather than the payload.
    expect(json_decode((string) $untukDokter->payload, true))->toEqualCanonicalizing([
        'booking_id' => $bookingId,
        'alasan' => 'Jadwal berubah mendadak.',
    ]);

    // The patient is the actor here, so the only row in their inbox is the
    // creation confirmation - no self-notification about their own act.
    expect(ntf5Satu((int) $dunia['pasien']->getKey(), 'booking')->judul)
        ->toBe('Booking berhasil dibuat.');
});

test('a doctor cancelling a booking notifies the patient, and the reason travels', function (): void {
    $dunia = ntf5Dunia();

    $bookingId = (int) ntf5As($dunia['pasien'])->postJson('/api/v1/booking', [
        'dokter_id' => $dunia['dokterId'],
        'tipe_layanan' => 'chat',
        'tanggal_kunjungan' => ntf5Tanggal(),
        'slot_mulai' => '09:00:00',
    ])->assertCreated()->json('data.booking.id');

    ntf5As($dunia['dokter'])
        ->putJson('/api/v1/booking/'.$bookingId.'/batalkan', ['alasan_pembatalan' => 'Dokter berhalangan.'])
        ->assertOk();

    $pembatalan = array_values(array_filter(
        ntf5Baris((int) $dunia['pasien']->getKey()),
        static fn (object $row): bool => (string) $row->judul === 'Booking dibatalkan.',
    ));

    expect($pembatalan)->toHaveCount(1);
    expect((string) $pembatalan[0]->isi)->toBe('Booking Anda dibatalkan: Dokter berhalangan.');
    expect(json_decode((string) $pembatalan[0]->payload, true)['alasan'])->toBe('Dokter berhalangan.');
});

// =====================================================================
// Trigger 3 - pembayaran selesai
// =====================================================================

/**
 * A booking plus its invoice, both created through the real API.
 *
 * @return array{0: int, 1: object}
 */
function ntf5InvoiceBaru(array $dunia): array
{
    $bookingId = (int) ntf5As($dunia['pasien'])->postJson('/api/v1/booking', [
        'dokter_id' => $dunia['dokterId'],
        'tipe_layanan' => 'chat',
        'tanggal_kunjungan' => ntf5Tanggal(),
        'slot_mulai' => '09:00:00',
    ])->assertCreated()->json('data.booking.id');

    $invoice = DB::table('invoice')
        ->where('referensi_tipe', 'booking')
        ->where('referensi_id', $bookingId)
        ->first();

    expect($invoice)->not->toBeNull();

    return [$bookingId, $invoice];
}

test('a settled invoice over the signed webhook writes a payment notification', function (): void {
    $dunia = ntf5Dunia();
    [, $invoice] = ntf5InvoiceBaru($dunia);

    $metode = (int) DB::table('master_metode_pembayaran')->orderBy('id')->value('id');

    ntf5As($dunia['pasien'])
        ->postJson('/api/v1/invoice/'.$invoice->id.'/bayar', ['metode_id' => $metode])
        ->assertCreated();

    $pembayaran = DB::table('pembayaran')->where('invoice_id', $invoice->id)->first();

    expect($pembayaran)->not->toBeNull();

    // Nothing yet: the invoice is only `menunggu_pembayaran` until a provider
    // says otherwise, so the trigger must NOT fire on payment INITIATION.
    expect(ntf5Satu((int) $dunia['pasien']->getKey(), 'pembayaran'))->toBeNull();

    [$raw, $server] = ntf5Webhook(
        (string) $pembayaran->gateway,
        (string) $pembayaran->nomor_referensi,
        (string) $pembayaran->jumlah,
    );

    ntf5Kirim((string) $pembayaran->gateway, $raw, $server)->assertOk();

    $baris = ntf5Satu((int) $dunia['pasien']->getKey(), 'pembayaran');

    expect($baris)->not->toBeNull();
    expect((string) $baris->judul)->toBe('Pembayaran berhasil.');
    expect((string) $baris->tautan)->toBe('/api/v1/invoice/'.$invoice->id);
    expect(json_decode((string) $baris->payload, true))->toBe(['invoice_id' => (int) $invoice->id]);
});

test('a duplicate webhook delivery does not notify twice', function (): void {
    $dunia = ntf5Dunia();
    [, $invoice] = ntf5InvoiceBaru($dunia);

    $metode = (int) DB::table('master_metode_pembayaran')->orderBy('id')->value('id');

    ntf5As($dunia['pasien'])
        ->postJson('/api/v1/invoice/'.$invoice->id.'/bayar', ['metode_id' => $metode])
        ->assertCreated();

    $pembayaran = DB::table('pembayaran')->where('invoice_id', $invoice->id)->first();

    [$raw, $server] = ntf5Webhook(
        (string) $pembayaran->gateway,
        (string) $pembayaran->nomor_referensi,
        (string) $pembayaran->jumlah,
    );

    ntf5Kirim((string) $pembayaran->gateway, $raw, $server)->assertOk();
    ntf5Kirim((string) $pembayaran->gateway, $raw, $server)->assertOk();

    // "Exactly one notification" is the whole of the duplicate contract, and it
    // is only a measurement because the counter is read on the table rather
    // than inferred from a response body.
    expect(DB::table('notifikasi')
        ->where('user_id', $dunia['pasien']->getKey())
        ->where('tipe', 'pembayaran')
        ->count())->toBe(1);
});

test('a failed payment writes no payment notification', function (): void {
    $dunia = ntf5Dunia();
    [, $invoice] = ntf5InvoiceBaru($dunia);

    $metode = (int) DB::table('master_metode_pembayaran')->orderBy('id')->value('id');

    ntf5As($dunia['pasien'])
        ->postJson('/api/v1/invoice/'.$invoice->id.'/bayar', ['metode_id' => $metode])
        ->assertCreated();

    $pembayaran = DB::table('pembayaran')->where('invoice_id', $invoice->id)->first();

    [$raw, $server] = ntf5Webhook(
        (string) $pembayaran->gateway,
        (string) $pembayaran->nomor_referensi,
        (string) $pembayaran->jumlah,
        'gagal',
    );

    ntf5Kirim((string) $pembayaran->gateway, $raw, $server)->assertOk();

    expect(ntf5Satu((int) $dunia['pasien']->getKey(), 'pembayaran'))->toBeNull();
});

// =====================================================================
// Triggers 4 and 5 need a consultation, so the fixture builds one
// =====================================================================

/**
 * An instant consultation opened by the patient against the fixture doctor.
 *
 * The `dokter_id` form of `POST /api/v1/konsultasi/mulai` is the one that needs
 * no booking and no payment, so these tests do not inherit a dependency on the
 * settlement flow above.
 */
function ntf5Konsultasi(array $dunia): int
{
    $response = ntf5As($dunia['pasien'])->postJson('/api/v1/konsultasi/mulai', [
        'dokter_id' => $dunia['dokterId'],
        'tipe' => 'chat',
    ]);

    $response->assertCreated();

    return (int) $response->json('data.konsultasi.id');
}

/** A catalogue drug the prescription can name, by `kode_obat`. */
function ntf5Obat(string $kode = 'OBT-0002'): int
{
    return (int) DB::table('master_obat')->where('kode_obat', $kode)->value('id');
}

// =====================================================================
// Trigger 4 - resep siap
// =====================================================================

test('a pharmacist verifying a prescription writes a prescription notification', function (): void {
    $dunia = ntf5Dunia();
    $konsultasiId = ntf5Konsultasi($dunia);

    $resepId = (int) ntf5As($dunia['dokter'])->postJson('/api/v1/konsultasi/'.$konsultasiId.'/resep', [
        'items' => [[
            'obat_id' => ntf5Obat(),
            'aturan_pakai' => '3 x 1 kapsul sesudah makan',
            'jumlah' => 10,
        ]],
    ])->assertCreated()->json('data.resep.id');

    // A `kontraindikasi` warning makes the acknowledgement MANDATORY, so the
    // test drives the override rather than the happy path - the journey F3
    // could not reach.
    DB::table('obat_interaksi')->insert([
        'obat_a_id' => min(ntf5Obat(), ntf5Obat('OBT-0005')),
        'obat_b_id' => max(ntf5Obat(), ntf5Obat('OBT-0005')),
        'tingkat' => 'kontraindikasi',
        'deskripsi' => null,
    ]);

    $override = ntf5As($dunia['dokter'])->postJson('/api/v1/konsultasi/'.$konsultasiId.'/resep', [
        'catatan_dodio' => 'Pasien menyetujui kombinasi ini setelah penjelasan.',
        'items' => [[
            'obat_id' => ntf5Obat(),
            'aturan_pakai' => '3 x 1 kapsul sesudah makan',
            'jumlah' => 10,
        ], [
            'obat_id' => ntf5Obat('OBT-0005'),
            'aturan_pakai' => '2 x 1 tablet sesudah makan',
            'jumlah' => 10,
        ]],
    ]);

    $override->assertCreated();
    expect($override->json('data.acknowledgement.diminta'))->toBeTrue();

    // Nothing yet: the prescription exists but it is not READY. `resepSiap` is
    // the moment it becomes dispensable, which is the pharmacy signature.
    expect(ntf5Satu((int) $dunia['pasien']->getKey(), 'resep'))->toBeNull();

    $verifikasi = ntf5As($dunia['apoteker'])
        ->postJson('/api/v1/resep/'.$resepId.'/verifikasi', [
            'status' => 'sesuai',
            'catatan' => 'Kombinasi diperiksa, resep siap diserahkan.',
        ]);

    $verifikasi->assertCreated();
    expect($verifikasi->json('data.resep.status'))->toBe(ResepStatus::Diverifikasi->value);

    $baris = ntf5Satu((int) $dunia['pasien']->getKey(), 'resep');

    expect($baris)->not->toBeNull();
    expect((string) $baris->judul)->toBe('Resep siap.');
    expect((string) $baris->tautan)->toBe('/api/v1/resep/'.$resepId);
    expect(json_decode((string) $baris->payload, true))->toBe(['resep_id' => $resepId]);
});

test('a REJECTED prescription writes no prescription notification', function (): void {
    $dunia = ntf5Dunia();
    $konsultasiId = ntf5Konsultasi($dunia);

    $resepId = (int) ntf5As($dunia['dokter'])->postJson('/api/v1/konsultasi/'.$konsultasiId.'/resep', [
        'items' => [[
            'obat_id' => ntf5Obat(),
            'aturan_pakai' => '3 x 1 kapsul sesudah makan',
            'jumlah' => 10,
        ]],
    ])->assertCreated()->json('data.resep.id');

    ntf5As($dunia['apoteker'])
        ->postJson('/api/v1/resep/'.$resepId.'/verifikasi', [
            'status' => 'ditolak',
            'catatan' => 'Dosis tidak sesuai dengan protokol.',
        ])
        ->assertCreated();

    // "Ready" is the claim the notification makes. A rejected prescription is
    // not ready, so a row here would be a lie the inbox then repeats.
    expect(ntf5Satu((int) $dunia['pasien']->getKey(), 'resep'))->toBeNull();
});

// =====================================================================
// Trigger 5 - pesan baru
// =====================================================================

test('a doctor sending a chat message notifies the patient, and the reverse', function (): void {
    $dunia = ntf5Dunia();
    $konsultasiId = ntf5Konsultasi($dunia);

    ntf5As($dunia['dokter'])
        ->postJson('/api/v1/konsultasi/'.$konsultasiId.'/chat', [
            'tipe_pesan' => 'teks',
            'isi' => 'Selamat siang, apa keluhan Anda?',
        ])
        ->assertCreated();

    $untukPasien = ntf5Satu((int) $dunia['pasien']->getKey(), 'chat');

    expect($untukPasien)->not->toBeNull();
    expect((string) $untukPasien->judul)->toBe('Pesan baru.');
    expect((string) $untukPasien->tautan)->toBe('/api/v1/konsultasi/'.$konsultasiId.'/chat');
    expect(json_decode((string) $untukPasien->payload, true))->toBe([
        'konsultasi_id' => $konsultasiId,
        'pengirim_user_id' => (int) $dunia['dokter']->getKey(),
    ]);

    // The doctor is NOT notified of their own message.
    expect(ntf5Satu((int) $dunia['dokter']->getKey(), 'chat'))->toBeNull();

    ntf5As($dunia['pasien'])
        ->postJson('/api/v1/konsultasi/'.$konsultasiId.'/chat', [
            'tipe_pesan' => 'teks',
            'isi' => 'Sakit kepala sejak tiga hari.',
        ])
        ->assertCreated();

    expect(ntf5Satu((int) $dunia['dokter']->getKey(), 'chat'))->not->toBeNull();
});

test('a SYSTEM line never notifies anybody, because nobody is waiting on it', function (): void {
    $dunia = ntf5Dunia();
    ntf5Konsultasi($dunia);

    // `POST /konsultasi/mulai` writes a `pengirim_tipe = 'sistem'` chat row.
    expect(DB::table('konsultasi_chat')->where('pengirim_tipe', 'sistem')->count())->toBe(1);

    expect(ntf5Baris((int) $dunia['pasien']->getKey()))->toBe([])
        ->and(ntf5Baris((int) $dunia['dokter']->getKey()))->toBe([]);
});
