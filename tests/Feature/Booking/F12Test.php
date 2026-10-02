<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PembayaranStatus;
use App\Http\Requests\Booking\BookingRequest;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\MasterMetodePembayaran;
use App\Services\Payment\PaymentGatewayService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/f12-helpers.php';

/*
|--------------------------------------------------------------------------
| F12 backend - the cancellation ledger, the hybrid refund rule, the policy
| surface, the same-row reschedule and the patient refund list
|--------------------------------------------------------------------------
|
| The P0 this file pins: before F12, `BookingService::batalkan()` set the
| linked invoice to `dibatalkan` unconditionally, so cancelling a PAID booking
| left `pembayaran.status = 'berhasil'`, no `refund` row and an invoice that
| denied the money - the exact state the refund migration warns the ledger
| cannot explain. Every test below reads the THREE rows back from storage
| rather than trusting the response, because the invariant is about rows.
|
| **Pest closure tests, not a PHPUnit class** (`tests/Pest.php` binds
| `RefreshDatabase` to `->in('Feature')`).
|
| **The helper prefix is `f12`**, in `f12-helpers.php` beside this file.
|
*/

beforeEach(function (): void {
    // `RbacSeeder` writes `roles`, `permissions` and `role_permissions`:
    // `RoleAssigner` resolves a role name against `roles`, and every
    // `permission:` gate resolves a code against `permissions`. Without it
    // every route answers 500 or 403.
    $this->seed(RbacSeeder::class);
});

// =====================================================================
// A. The P0 ledger fix
// =====================================================================

test('cancelling a PAID booking writes exactly one full refund and moves all three rows together', function (): void {
    $s = f12Skenario();
    $pembayaran = f12Bayar($s['invoice'], 'e_wallet');
    $bookingId = (int) $s['booking']->getKey();

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$bookingId.'/batalkan', [
        'alasan_pembatalan' => 'Jadwal berubah, mohon dananya dikembalikan.',
    ])->assertOk()
        ->assertJsonPath('data.booking.status', 'dibatalkan')
        ->assertJsonPath('data.booking.dibatalkan_oleh', 'pasien');

    $refunds = f12Refund((int) $pembayaran->getKey());

    // EXACTLY ONE row - not zero (the old defect) and not two.
    expect($refunds)->toHaveCount(1);

    $refund = $refunds[0];

    expect((string) $refund->jumlah)->toBe((string) $pembayaran->jumlah)
        // `jumlah <= pembayaran.jumlah`: the owner decided full refund, and
        // the schema permits a larger one, so the bound is asserted.
        ->and((float) $refund->jumlah)->toBeLessThanOrEqual((float) $pembayaran->jumlah)
        ->and($refund->status)->toBe('berhasil')
        ->and($refund->alasan)->toContain((string) $s['booking']->nomor_booking)
        // The reason must not copy the patient's free text from the booking:
        // `alasan_pembatalan` may carry clinical detail, and `refund.alasan`
        // is an operator surface.
        ->and($refund->alasan)->not->toContain('Jadwal berubah');

    $s['invoice']->refresh();
    $pembayaran->refresh();

    expect($s['invoice']->status)->toBe(InvoiceStatus::RefundPenuh->value)
        ->and($pembayaran->status)->toBe(PembayaranStatus::Refund->value)
        ->and($s['booking']->refresh()->status)->toBe('dibatalkan');

    // The refund transition is audited through `audit_log` (the `refund`
    // table has no `diubah_at`, and F12 decided not to add one).
    expect(DB::table('audit_log')
        ->where('tabel_target', 'refund')
        ->where('record_id', (string) $refund->getKey())
        ->exists())->toBeTrue();
});

test('cancelling an UNPAID booking writes no refund row and cancels the invoice', function (): void {
    $s = f12Skenario();

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/batalkan', [])
        ->assertOk()
        ->assertJsonPath('data.booking.status', 'dibatalkan');

    $s['invoice']->refresh();

    expect($s['invoice']->status)->toBe(InvoiceStatus::Dibatalkan->value)
        ->and(DB::table('pembayaran')->where('invoice_id', $s['invoice']->getKey())->count())->toBe(0)
        ->and(DB::table('refund')->count())->toBe(0);
});

test('a second cancellation is refused by the status guard and creates no second refund', function (): void {
    $s = f12Skenario();
    $pembayaran = f12Bayar($s['invoice'], 'qris');
    $url = '/api/v1/booking/'.$s['booking']->getKey().'/batalkan';

    f12As($s['user']);

    test()->putJson($url, [])->assertOk();
    test()->putJson($url, [])->assertUnprocessable()->assertJsonStructure(['errors' => ['status']]);

    $pembayaran->refresh();
    $s['invoice']->refresh();

    expect(f12Refund((int) $pembayaran->getKey()))->toHaveCount(1)
        ->and($pembayaran->status)->toBe(PembayaranStatus::Refund->value)
        ->and($s['invoice']->status)->toBe(InvoiceStatus::RefundPenuh->value)
        ->and($s['booking']->refresh()->status)->toBe('dibatalkan');
});

test('an existing non-rejected refund row blocks a second row and skips the gateway', function (): void {
    $s = f12Skenario();
    $pembayaran = f12Bayar($s['invoice'], 'e_wallet');

    DB::table('refund')->insert([
        'pembayaran_id' => $pembayaran->getKey(),
        'jumlah' => (string) $pembayaran->jumlah,
        'alasan' => 'Refund yang sudah diajukan sebelumnya.',
        'status' => 'diproses',
    ]);

    // If the idempotency guard failed, this gateway WOULD be called and the
    // test would see a second row; `dipanggil` being 0 is the proof that the
    // guard short-circuits before any provider conversation.
    $fake = f12GatewayRusak(false);
    app()->instance(PaymentGatewayService::class, $fake);

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/batalkan', [])->assertOk();

    $pembayaran->refresh();
    $s['invoice']->refresh();

    expect(DB::table('refund')->count())->toBe(1)
        ->and($fake->dipanggil)->toBe(0)
        ->and($pembayaran->status)->toBe(PembayaranStatus::Refund->value)
        ->and($s['invoice']->status)->toBe(InvoiceStatus::RefundPenuh->value);
});

// =====================================================================
// B. The hybrid refund rule
// =====================================================================

test('an automatic-capability method refunds through the gateway and lands berhasil', function (string $tipe): void {
    $s = f12Skenario();
    $pembayaran = f12Bayar($s['invoice'], $tipe);

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/batalkan', [])->assertOk();

    $refunds = f12Refund((int) $pembayaran->getKey());

    expect($refunds)->toHaveCount(1)
        ->and($refunds[0]->status)->toBe('berhasil');
})->with(['e_wallet', 'qris', 'kartu_kredit']);

test('a manual-capability method is filed as diajukan for an admin and never reaches the gateway', function (string $tipe): void {
    $s = f12Skenario();
    $pembayaran = f12Bayar($s['invoice'], $tipe);

    $fake = f12GatewayRusak(false);
    app()->instance(PaymentGatewayService::class, $fake);

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/batalkan', [])->assertOk();

    $refunds = f12Refund((int) $pembayaran->getKey());

    expect($refunds)->toHaveCount(1)
        ->and($refunds[0]->status)->toBe('diajukan')
        ->and($fake->dipanggil)->toBe(0);
})->with(['va_bank', 'gerai_retail', 'cod', 'tunai', 'bpjs', 'asuransi']);

test('a gateway refusal leaves the refund in diproses, NEVER ditolak', function (): void {
    $s = f12Skenario();
    $pembayaran = f12Bayar($s['invoice'], 'e_wallet');

    $fake = f12GatewayRusak(false);
    app()->instance(PaymentGatewayService::class, $fake);

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/batalkan', [])->assertOk();

    $refunds = f12Refund((int) $pembayaran->getKey());

    expect($fake->dipanggil)->toBe(1)
        ->and($refunds)->toHaveCount(1)
        ->and($refunds[0]->status)->toBe('diproses')
        ->and($refunds[0]->status)->not->toBe('ditolak')
        // The ledger pair still moved: `diproses` is a manual fallback, not a
        // reason to leave the money unexplained.
        ->and($pembayaran->refresh()->status)->toBe(PembayaranStatus::Refund->value)
        ->and($s['invoice']->refresh()->status)->toBe(InvoiceStatus::RefundPenuh->value);
});

test('a gateway transport exception leaves the refund in diproses and is logged', function (): void {
    Log::spy();

    $s = f12Skenario();
    $pembayaran = f12Bayar($s['invoice'], 'kartu_kredit');

    $fake = f12GatewayRusak(true);
    app()->instance(PaymentGatewayService::class, $fake);

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/batalkan', [])->assertOk();

    $refunds = f12Refund((int) $pembayaran->getKey());

    expect($refunds)->toHaveCount(1)
        ->and($refunds[0]->status)->toBe('diproses')
        ->and($refunds[0]->status)->not->toBe('ditolak');

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $pesan): bool => $pesan === 'refund.gateway.gagal')
        ->once();
});

test('the automatic list is read from config, not hardcoded in the service', function (): void {
    expect(config('payment.metode_tipe_refund_otomatis'))->toBe(['e_wallet', 'qris', 'kartu_kredit']);

    $sumber = (string) file_get_contents(base_path('app/Services/Payment/RefundService.php'));

    expect($sumber)->toContain('metode_tipe_refund_otomatis')
        ->and($sumber)->not->toContain("'e_wallet'")
        ->and($sumber)->not->toContain("'kartu_kredit'");
});

// =====================================================================
// C. The same-row reschedule
// =====================================================================

test('a reschedule moves the SAME row and leaves the booking number, price and invoice alone', function (): void {
    $s = f12Skenario();
    $bookingId = (int) $s['booking']->getKey();
    $invoiceId = (int) $s['invoice']->getKey();
    $nomor = (string) $s['booking']->nomor_booking;
    $total = (string) $s['invoice']->total;

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$bookingId.'/jadwal-ulang', [
        'jadwal_id' => $s['jadwal_id'],
        'tanggal_kunjungan' => F12_TANGGAL_LAIN,
        'slot_mulai' => '09:30:00',
        'slot_selesai' => '09:45:00',
    ])->assertOk()
        ->assertJsonPath('data.booking.id', $bookingId)
        ->assertJsonPath('data.booking.nomor_booking', $nomor)
        ->assertJsonPath('data.booking.tanggal_kunjungan', F12_TANGGAL_LAIN)
        ->assertJsonPath('data.booking.slot_mulai', '09:30:00')
        ->assertJsonPath('data.booking.slot_selesai', '09:45:00')
        ->assertJsonPath('data.booking.status', 'menunggu_pembayaran');

    $fresh = $s['booking']->refresh();

    expect((int) $fresh->getKey())->toBe($bookingId)
        ->and((string) $fresh->nomor_booking)->toBe($nomor)
        ->and((int) $fresh->jadwal_id)->toBe((int) $s['jadwal_id'])
        ->and($fresh->tanggal_kunjungan?->toDateString())->toBe(F12_TANGGAL_LAIN)
        ->and((string) $fresh->slot_mulai)->toBe('09:30:00')
        ->and($fresh->status)->toBe('menunggu_pembayaran');

    $invoice = Invoice::query()->findOrFail($invoiceId);

    expect((int) $invoice->getKey())->toBe($invoiceId)
        ->and((string) $invoice->total)->toBe($total)
        ->and($invoice->status)->toBe(InvoiceStatus::MenungguPembayaran->value)
        ->and(Invoice::query()
            ->where('referensi_tipe', 'booking')
            ->where('referensi_id', $bookingId)
            ->count())->toBe(1);

    // The other party is told with a GENERIC body: the push-rendered `isi`
    // carries no date, no time and no clinical text; the new schedule travels
    // in the inbox payload.
    $notifikasi = DB::table('notifikasi')
        ->where('user_id', $s['dokter_user']->getKey())
        ->where('tautan', '/api/v1/booking/'.$bookingId)
        ->orderByDesc('id')
        ->first();

    expect($notifikasi)->not->toBeNull()
        ->and($notifikasi->judul)->toBe('Jadwal booking diubah.')
        ->and($notifikasi->isi)->toBe('Jadwal booking Anda telah dipindahkan.')
        ->and($notifikasi->isi)->not->toContain(F12_TANGGAL_LAIN)
        ->and($notifikasi->isi)->not->toContain('09:30');

    $payload = json_decode((string) $notifikasi->payload, true);

    expect($payload['booking_id'])->toBe($bookingId)
        ->and($payload['tanggal_kunjungan'])->toBe(F12_TANGGAL_LAIN)
        ->and($payload['slot_mulai'])->toBe('09:30:00');
});

test('there is no invented reschedule limit: the same row moves twice', function (): void {
    $s = f12Skenario();
    $url = '/api/v1/booking/'.$s['booking']->getKey().'/jadwal-ulang';

    f12As($s['user']);

    test()->putJson($url, [
        'jadwal_id' => $s['jadwal_id'],
        'tanggal_kunjungan' => F12_TANGGAL,
        'slot_mulai' => '09:15:00',
    ])->assertOk();

    test()->putJson($url, [
        'jadwal_id' => $s['jadwal_id'],
        'tanggal_kunjungan' => F12_TANGGAL,
        'slot_mulai' => '09:30:00',
    ])->assertOk();

    $fresh = $s['booking']->refresh();

    expect((string) $fresh->slot_mulai)->toBe('09:30:00')
        ->and($fresh->status)->toBe('menunggu_pembayaran');
});

test('a taken target slot is a 422 slot and the old schedule is completely unchanged', function (): void {
    $s = f12Skenario();
    $tanggalLama = $s['booking']->tanggal_kunjungan?->toDateString();
    $mulaiLama = (string) $s['booking']->slot_mulai;
    $selesaiLama = (string) $s['booking']->slot_selesai;

    // Another patient occupies the 09:30 slot on the same doctor.
    $lain = f12User('Pasien F12 Lain', 'pasien');
    f12Pasien($lain);
    f12As($lain);

    test()->postJson('/api/v1/booking', f12Payload($s['dokter']->getKey(), [
        'jadwal_id' => $s['jadwal_id'],
        'slot_mulai' => '09:30:00',
    ]))->assertCreated();

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/jadwal-ulang', [
        'jadwal_id' => $s['jadwal_id'],
        'tanggal_kunjungan' => F12_TANGGAL,
        'slot_mulai' => '09:30:00',
    ])->assertUnprocessable()->assertJsonStructure(['errors' => ['slot']]);

    $fresh = $s['booking']->refresh();

    expect($fresh->tanggal_kunjungan?->toDateString())->toBe($tanggalLama)
        ->and((string) $fresh->slot_mulai)->toBe($mulaiLama)
        ->and((string) $fresh->slot_selesai)->toBe($selesaiLama);
});

test('a non-party reschedule is a 404 and changes nothing', function (): void {
    $s = f12Skenario();
    $bekas = [
        (string) $s['booking']->slot_mulai,
        (string) $s['booking']->slot_selesai,
    ];

    $penyusup = f12User('Penyusup F12', 'pasien');
    f12Pasien($penyusup);
    f12As($penyusup);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/jadwal-ulang', [
        'jadwal_id' => $s['jadwal_id'],
        'tanggal_kunjungan' => F12_TANGGAL_LAIN,
        'slot_mulai' => '09:30:00',
    ])->assertNotFound()->assertJsonPath('success', false);

    $fresh = $s['booking']->refresh();

    expect([(string) $fresh->slot_mulai, (string) $fresh->slot_selesai])->toBe($bekas)
        ->and($fresh->tanggal_kunjungan?->toDateString())->toBe(F12_TANGGAL);
});

test('an account owning neither a patient nor a doctor row is refused with 403', function (): void {
    $s = f12Skenario();

    // An `admin` holds `booking.batal` and so passes the route gate; the
    // service refuses because the account owns no profile row.
    f12As(f12User('Admin F12', 'admin'));

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/jadwal-ulang', [
        'jadwal_id' => $s['jadwal_id'],
        'tanggal_kunjungan' => F12_TANGGAL_LAIN,
        'slot_mulai' => '09:30:00',
    ])->assertForbidden();

    expect((string) $s['booking']->refresh()->slot_mulai)->toBe('09:00:00');
});

test('the reschedulable set is the two pre-consultation states, narrower than the cancel guard', function (): void {
    expect(BookingRequest::STATUS_BISA_DIJADWAL_ULANG)->toBe(['menunggu_pembayaran', 'terjadwal'])
        // `check_in` and `no_show` are cancellable under the old guard but NOT
        // reschedulable: this is the relationship the constants must keep.
        ->and(BookingRequest::STATUS_TIDAK_BISA_DIBATALKAN)->not->toContain('check_in')
        ->and(BookingRequest::STATUS_TIDAK_BISA_DIBATALKAN)->not->toContain('no_show')
        ->and(BookingRequest::STATUS_BISA_DIJADWAL_ULANG)->not->toContain('check_in')
        ->and(BookingRequest::STATUS_BISA_DIJADWAL_ULANG)->not->toContain('no_show');
});

test('a check_in booking cannot be rescheduled and is left as it was', function (): void {
    $s = f12Skenario();

    DB::table('booking')->where('id', $s['booking']->getKey())->update(['status' => 'check_in']);

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/jadwal-ulang', [
        'jadwal_id' => $s['jadwal_id'],
        'tanggal_kunjungan' => F12_TANGGAL_LAIN,
        'slot_mulai' => '09:30:00',
    ])->assertUnprocessable()->assertJsonStructure(['errors' => ['status']]);

    expect($s['booking']->refresh()->status)->toBe('check_in');
});

test('a schedule row belonging to another doctor is refused as unpublished', function (): void {
    $s = f12Skenario();

    [, $dokterLain] = f12DokterAkun();
    $jadwalLain = f12Jadwal($dokterLain->getKey());

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/jadwal-ulang', [
        'jadwal_id' => $jadwalLain,
        'tanggal_kunjungan' => F12_TANGGAL_LAIN,
        'slot_mulai' => '09:30:00',
    ])->assertUnprocessable()->assertJsonStructure(['errors' => ['slot']]);

    expect((string) $s['booking']->refresh()->slot_mulai)->toBe('09:00:00');
});

test('slot_selesai is accepted but checked against the published slot', function (): void {
    $s = f12Skenario();

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/jadwal-ulang', [
        'jadwal_id' => $s['jadwal_id'],
        'tanggal_kunjungan' => F12_TANGGAL_LAIN,
        'slot_mulai' => '09:30:00',
        'slot_selesai' => '10:30:00',
    ])->assertUnprocessable()->assertJsonStructure(['errors' => ['slot_selesai']]);

    expect((string) $s['booking']->refresh()->slot_mulai)->toBe('09:00:00');
});

// =====================================================================
// D. The cancellation-policy surface
// =====================================================================

test('the policy endpoint publishes the server-computed free/full policy for a paid booking', function (): void {
    $s = f12Skenario();
    $pembayaran = f12Bayar($s['invoice'], 'e_wallet');
    $metode = MasterMetodePembayaran::query()->findOrFail((int) $pembayaran->metode_id);

    f12As($s['user']);

    test()->getJson('/api/v1/booking/'.$s['booking']->getKey().'/kebijakan')
        ->assertOk()
        ->assertJsonPath('data.kebijakan.gratis', true)
        ->assertJsonPath('data.kebijakan.biaya', '0.00')
        ->assertJsonPath('data.kebijakan.jumlah_refund', (string) $pembayaran->jumlah)
        ->assertJsonPath('data.kebijakan.tujuan.metode_id', (int) $pembayaran->metode_id)
        ->assertJsonPath('data.kebijakan.tujuan.label', (string) $metode->nama)
        ->assertJsonPath('data.kebijakan.tujuan.tipe', 'e_wallet')
        // No SLA was decided; the server must not invent one.
        ->assertJsonPath('data.kebijakan.sla', null);
});

test('the UNPAID policy says 0.00 and names no destination', function (): void {
    $s = f12Skenario();

    f12As($s['user']);

    test()->getJson('/api/v1/booking/'.$s['booking']->getKey().'/kebijakan')
        ->assertOk()
        ->assertJsonPath('data.kebijakan.gratis', true)
        ->assertJsonPath('data.kebijakan.biaya', '0.00')
        ->assertJsonPath('data.kebijakan.jumlah_refund', '0.00')
        ->assertJsonPath('data.kebijakan.tujuan', null)
        ->assertJsonPath('data.kebijakan.sla', null);
});

test('the policy is party-scoped: 404 for a non-party, 403 for no profile, 401 for anonymous', function (): void {
    $s = f12Skenario();

    $penyusup = f12User('Penyusup Kebijakan', 'pasien');
    f12Pasien($penyusup);
    f12As($penyusup);

    test()->getJson('/api/v1/booking/'.$s['booking']->getKey().'/kebijakan')->assertNotFound();

    f12As(f12User('Admin Kebijakan', 'admin'));
    test()->getJson('/api/v1/booking/'.$s['booking']->getKey().'/kebijakan')->assertForbidden();

    f12TanpaAuth();
    test()->getJson('/api/v1/booking/'.$s['booking']->getKey().'/kebijakan')->assertUnauthorized();
});

// =====================================================================
// E. The patient refund list
// =====================================================================

test('the refund list returns the caller own refunds newest first with the project meta block', function (): void {
    $s = f12Skenario();
    $pembayaranPertama = f12Bayar($s['invoice'], 'e_wallet');
    $bookingPertama = (int) $s['booking']->getKey();

    f12As($s['user']);

    test()->putJson('/api/v1/booking/'.$bookingPertama.'/batalkan', [])->assertOk();

    // A second booking for the same patient on the next Monday, paid with a
    // manual method, so the list carries both statuses.
    test()->postJson('/api/v1/booking', f12Payload($s['dokter']->getKey(), [
        'jadwal_id' => $s['jadwal_id'],
        'tanggal_kunjungan' => F12_TANGGAL_LAIN,
    ]))->assertCreated();

    $bookingKedua = Booking::query()
        ->where('pasien_id', $s['pasien']->getKey())
        ->orderByDesc('id')
        ->firstOrFail();

    $invoiceKedua = Invoice::query()
        ->where('referensi_tipe', 'booking')
        ->where('referensi_id', $bookingKedua->getKey())
        ->firstOrFail();

    $pembayaranKedua = f12Bayar($invoiceKedua, 'va_bank');

    test()->putJson('/api/v1/booking/'.$bookingKedua->getKey().'/batalkan', [])->assertOk();

    test()->getJson('/api/v1/pasien/refund')
        ->assertOk()
        ->assertJsonCount(2, 'data.refund')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        // Newest first: the second cancellation is row zero, and a tie on
        // `dibuat_at` (one-second resolution) is broken by `refund.id`.
        ->assertJsonPath('data.refund.0.booking_id', (int) $bookingKedua->getKey())
        ->assertJsonPath('data.refund.0.status', 'diajukan')
        ->assertJsonPath('data.refund.0.jumlah', (string) $pembayaranKedua->jumlah)
        ->assertJsonPath('data.refund.0.metode.id', (int) $pembayaranKedua->metode_id)
        ->assertJsonPath('data.refund.0.metode.label', 'Metode F12 va_bank')
        ->assertJsonPath('data.refund.1.booking_id', $bookingPertama)
        ->assertJsonPath('data.refund.1.status', 'berhasil')
        ->assertJsonPath('data.refund.1.jumlah', (string) $pembayaranPertama->jumlah)
        ->assertJsonPath('data.refund.1.metode.id', (int) $pembayaranPertama->metode_id)
        ->assertJsonStructure(['data' => ['refund' => [
            ['id', 'booking_id', 'jumlah', 'metode' => ['id', 'label'], 'status', 'dibuat_at'],
        ]]]);

    // Pagination really paginates and the meta block moves with it.
    test()->getJson('/api/v1/pasien/refund?per_page=1&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data.refund')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2);
});

test('another patient refunds are absent from the list', function (): void {
    $s = f12Skenario();
    $pembayaran = f12Bayar($s['invoice'], 'qris');

    f12As($s['user']);
    test()->putJson('/api/v1/booking/'.$s['booking']->getKey().'/batalkan', [])->assertOk();

    expect(f12Refund((int) $pembayaran->getKey()))->toHaveCount(1);

    $lain = f12User('Pasien Lain Refund', 'pasien');
    f12Pasien($lain);
    f12As($lain);

    test()->getJson('/api/v1/pasien/refund')
        ->assertOk()
        ->assertJsonCount(0, 'data.refund')
        ->assertJsonPath('meta.total', 0);
});

test('a doctor account is refused the refund list', function (): void {
    f12As(f12User('Dokter Refund Cek', 'dokter'));

    test()->getJson('/api/v1/pasien/refund')->assertForbidden();
});

test('a pasien-typed account with no patient row is refused with 403', function (): void {
    // The role grants `pembayaran.bayar`, so the route gate passes and the
    // service's own `ownPasien()` raises the caller-level 403.
    f12As(f12User('Pasien Tanpa Profil', 'pasien'));

    test()->getJson('/api/v1/pasien/refund')->assertForbidden();
});

test('the refund list validates its query string like every other list', function (): void {
    $s = f12Skenario();
    f12As($s['user']);

    test()->getJson('/api/v1/pasien/refund?per_page=0')
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['per_page']]);

    test()->getJson('/api/v1/pasien/refund?page=abc')
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['page']]);
});

// =====================================================================
// Route wiring (the census files assert the closed sets; this names the
// three F12 routes and their guards directly)
// =====================================================================

test('the three F12 routes are registered with the guards the ledger and list need', function (): void {
    $peta = [];

    foreach (app('router')->getRoutes()->getRoutes() as $rute) {
        $peta[$rute->methods()[0].' '.$rute->uri()] = $rute->gatherMiddleware();
    }

    expect($peta)->toHaveKey('GET api/v1/booking/{id}/kebijakan')
        ->and($peta)->toHaveKey('PUT api/v1/booking/{id}/jadwal-ulang')
        ->and($peta)->toHaveKey('GET api/v1/pasien/refund');

    expect($peta['GET api/v1/booking/{id}/kebijakan'])->toContain('auth:sanctum')
        ->and($peta['GET api/v1/booking/{id}/kebijakan'])->toContain('permission:booking.lihat')
        ->and($peta['PUT api/v1/booking/{id}/jadwal-ulang'])->toContain('auth:sanctum')
        ->and($peta['PUT api/v1/booking/{id}/jadwal-ulang'])->toContain('permission:booking.batal')
        ->and($peta['GET api/v1/pasien/refund'])->toContain('auth:sanctum')
        ->and($peta['GET api/v1/pasien/refund'])->toContain('permission:pembayaran.bayar');
});
