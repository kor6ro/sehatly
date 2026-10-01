<?php

declare(strict_types=1);

use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/payment-helpers.php';

/*
|--------------------------------------------------------------------------
| F06 - the invoice read endpoint, its explicit Policy, and the IDOR proof
|--------------------------------------------------------------------------
|
| Before this route the SPA asked the patient to type an invoice id by hand and
| could not show a payment status at all. `GET /api/v1/invoice/{id}` closes that
| gap, and this file is the evidence for the three things the owner asked for:
|
| 1. **The read works for its owner** - 200, the created `nomor_invoice`, and
|    `total` as the DECIMAL STRING the rest of the contract publishes, never a
|    JSON number.
| 2. **A real Policy exists** - `InvoicePolicy::view()` is asserted DIRECTLY
|    with `Gate::forUser()`, not only through the controller. A policy that is
|    only ever proved through a query that already scopes would be a claim about
|    the query, not about the policy; the unit assertion is what makes a future
|    edit that drops the query scope still fail closed.
| 3. **Patient B cannot read patient A's invoice, and cannot learn it exists** -
|    the answer is **404** with the kernel's sanitised 'Resource not found.',
|    never 403, and A's `nomor_invoice` appears nowhere in B's body. The rule is
|    `PasienRecordAccess`'s: 403 is about the CALLER (no `pasien` row), 404 is
|    about the ROW (not yours, or absent).
|
| ## The route carries `permission:pembayaran.bayar`, because it is the only code
|
| `RbacCatalog` has no `pembayaran.lihat`; inventing one would make
| `EnsurePermission` throw a LogicException (a sanitised 500) for every caller.
| The single payment code is granted to `pasien` and `superadmin`, so the
| 403 dataset below is exactly the three account types it refuses: `dokter`,
| `apoteker` and `admin`.
|
| ## `webhook_payload` is a `pembayaran` column and is never published
|
| The one test that plants a payment row with a recognisable payload asserts the
| body contains the payment - and that the payload and the column name are
| absent from the raw bytes, not merely from one key.
|
| Fixtures are the shared `pay45*` helpers; the one local helper is `invid*`,
| because Pest loads every test file into ONE process and a second `pay45*`
| definition would collide.
|
| ## Why the clock is frozen
|
| `pay45KunciJam()` pins `Carbon::setTestNow()` so the two payment rows this
| file inserts sort deterministically and `dibuat_at` is a stable instant. The
| shared `afterEach` releases it, so no later file inherits the freeze.
|
*/

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    pay45KunciJam();
});

afterEach(function (): void {
    pay45LepasJam();
});

/**
 * One `pembayaran` row for `$invoiceId`, inserted directly.
 *
 * `PaymentService::mulai()` is exercised by the payment files; this endpoint is
 * a READ, so the fixture only has to be a legal row. `webhook_payload` is set
 * when given so the omission assertion has a recognisable value to look for.
 *
 * `metode_id` (:961) is `NOT NULL` and foreign-keyed to
 * `master_metode_pembayaran` (:971), so a real method row is required and is
 * created by {@see pay45Metode()}. The row is not recorded with `pay45Catat()`:
 * `pembayaran` is deleted by `invoice_id` in {@see pay45BersihkanSemua()}, which
 * is the only cleanup that can reach a child the fixture list cannot name.
 *
 * @param  array<string, mixed>|null  $payload
 */
function invidPembayaran(int $invoiceId, int $metodeId, string $referensi, ?array $payload = null): int
{
    return (int) DB::table('pembayaran')->insertGetId([
        'invoice_id' => $invoiceId,
        'metode_id' => $metodeId,
        'jumlah' => '150000.00',
        'nomor_referensi' => $referensi,
        'va_number' => '8808'.random_int(10000000, 99999999),
        'gateway' => 'midtrans',
        'status' => 'pending',
        'webhook_payload' => $payload === null
            ? null
            : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

// =====================================================================
// The owner
// =====================================================================

test('the owner opens their own invoice: 200, the created number, and money as strings', function (): void {
    [$user, $pasienId] = pay45AkunPasien('Pasien Pemilik F06');

    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);

    $response = pay45Ajax($user)->getJson('/api/v1/invoice/'.$invoice->getKey());

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Tagihan berhasil dimuat.')
        ->assertJsonPath('data.invoice.nomor_invoice', $invoice->nomor_invoice)
        ->assertJsonPath('data.invoice.referensi_tipe', 'booking')
        ->assertJsonPath('data.invoice.referensi_id', $bookingId)
        ->assertJsonPath('data.invoice.status', 'menunggu_pembayaran');

    // `data.invoice.total` is the figure the production service computed, not a
    // number this test wrote down, and it is a JSON STRING.
    expect($response->json('data.invoice.total'))->toBe((string) $invoice->total)
        ->and($response->json('data.invoice.total'))->toBeString()
        ->and($response->json('data.invoice.id'))->toBe((int) $invoice->getKey());

    foreach (['subtotal', 'diskon', 'biaya_admin', 'biaya_pengiriman', 'total'] as $kolom) {
        expect($response->json("data.invoice.{$kolom}"))->toBeString();
    }

    expect($response->json('data.invoice.jatuh_tempo'))->toBeNull()
        ->and($response->json('data.invoice.lunas_at'))->toBeNull()
        ->and($response->json('data.invoice.dibuat_at'))->toBeString()
        // No payment has been initiated, so the history is an empty list rather
        // than a missing key.
        ->and($response->json('data.invoice.pembayaran'))->toBe([]);
});

// =====================================================================
// The IDOR proof - the one the owner demanded
// =====================================================================

test('patient B opening patient A\'s invoice gets 404, never 403, and never learns the number', function (): void {
    [$pasienA, $pasienIdA] = pay45AkunPasien('Pasien A F06');
    [$pasienB] = pay45AkunPasien('Pasien B F06');

    $invoiceA = pay45Invoice('booking', pay45Booking($pasienIdA), $pasienIdA);

    $response = pay45Ajax($pasienB)->getJson('/api/v1/invoice/'.$invoiceA->getKey());

    // The sanitised message the kernel renders for a ModelNotFoundException. A
    // 403 would confirm the row exists, which is the cross-tenant oracle
    // `PasienRecordAccess` forbids.
    $response->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Resource not found.')
        ->assertJsonPath('data', null);

    // The number A's invoice carries appears NOWHERE in B's body - not in a
    // message, not in an error detail, not in a stray key.
    expect($response->getContent())->not->toContain((string) $invoiceA->nomor_invoice);

    // And B's own row is untouched by the attempt.
    expect(DB::table('invoice')->where('id', $invoiceA->getKey())->exists())->toBeTrue();
});

test('an invoice id that exists for nobody is the SAME 404 envelope as another patient\'s row', function (): void {
    [$user] = pay45AkunPasien('Pasien F06 Tanpa Invoice');

    $response = pay45Ajax($user)->getJson('/api/v1/invoice/999999999');

    $response->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Resource not found.');
});

// =====================================================================
// The gate in front: permission and auth
// =====================================================================

test('a non-patient account holding no pembayaran.bayar is refused with 403', function (string $tipe, string $role): void {
    $user = pay45User('Akun F06 '.$tipe, $tipe, $role);

    // The middleware refuses before the controller runs, so the id names no
    // invoice on purpose: the 403 must not depend on a row existing, or it
    // would be answering the wrong question.
    $response = pay45Ajax($user)->getJson('/api/v1/invoice/1');

    $response->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'This action is unauthorized.');
})->with([
    'dokter' => ['dokter', 'dokter'],
    'apoteker' => ['apoteker', 'apoteker'],
    'admin' => ['admin', 'admin'],
]);

test('an unauthenticated caller gets 401 before any invoice is read', function (): void {
    $response = pay45TanpaAuth()->getJson('/api/v1/invoice/1');

    $response->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('a non-numeric id is a router 404, so no id parsing is the API\'s problem', function (): void {
    [$user] = pay45AkunPasien('Pasien F06 Id Aneh');

    $response = pay45Ajax($user)->getJson('/api/v1/invoice/bukan-angka');

    $response->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});

// =====================================================================
// The payment history
// =====================================================================

test('a payment row is published through PembayaranResource, newest first, and webhook_payload is not', function (): void {
    [$user, $pasienId] = pay45AkunPasien('Pasien F06 Riwayat');

    $invoice = pay45Invoice('booking', pay45Booking($pasienId), $pasienId);
    $metodeId = pay45Metode();

    // Inserted oldest first so a "newest first" answer has to reorder them, and
    // with payloads whose JSON is unmistakable in the raw body.
    $pertama = invidPembayaran(
        (int) $invoice->getKey(),
        $metodeId,
        'F06-REF-PERTAMA',
        ['rahasia' => 'payload-webhook-pertama-tidak-boleh-muncul'],
    );

    $kedua = invidPembayaran(
        (int) $invoice->getKey(),
        $metodeId,
        'F06-REF-KEDUA',
        ['rahasia' => 'payload-webhook-kedua-tidak-boleh-muncul'],
    );

    $response = pay45Ajax($user)->getJson('/api/v1/invoice/'.$invoice->getKey());

    $response->assertOk()
        ->assertJsonPath('data.invoice.pembayaran.0.id', $kedua)
        ->assertJsonPath('data.invoice.pembayaran.0.nomor_referensi', 'F06-REF-KEDUA')
        ->assertJsonPath('data.invoice.pembayaran.1.id', $pertama)
        ->assertJsonPath('data.invoice.pembayaran.1.nomor_referensi', 'F06-REF-PERTAMA');

    expect($response->json('data.invoice.pembayaran'))->toHaveCount(2)
        // Money is a string here too: `PembayaranResource` casts it.
        ->and($response->json('data.invoice.pembayaran.0.jumlah'))->toBe('150000.00');

    $body = (string) $response->getContent();

    expect($body)->not->toContain('webhook_payload')
        ->and($body)->not->toContain('payload-webhook-pertama-tidak-boleh-muncul')
        ->and($body)->not->toContain('payload-webhook-kedua-tidak-boleh-muncul');
});

// =====================================================================
// The Policy, asserted directly - independent of the tenant-scoped query
// =====================================================================

test('InvoicePolicy::view() allows the owner and denies another patient, proved with Gate::forUser', function (): void {
    // This assertion never touches the route and never touches
    // `PembayaranController::show()`. It is the policy ITSELF: if a future edit
    // dropped the `whereBelongsTo($pasien)` scope from the controller, the
    // idempotent fetch would start returning another patient's invoice and THIS
    // test would already have said the policy refuses it.
    [$pemilik, $pasienId] = pay45AkunPasien('Pasien Pemilik Policy');
    [$lain] = pay45AkunPasien('Pasien Lain Policy');

    $invoice = pay45Invoice('booking', pay45Booking($pasienId), $pasienId);

    expect(Gate::forUser($pemilik)->allows('view', $invoice))->toBeTrue()
        ->and(Gate::forUser($lain)->allows('view', $invoice))->toBeFalse();

    // A patient-typed account that happens to hold no `pasien` row is denied
    // too - the policy follows the ROW, not the `tipe` column.
    $tanpaProfil = pay45User('Pasien Tanpa Profil Policy', 'pasien', null);

    expect(Gate::forUser($tanpaProfil)->allows('view', $invoice))->toBeFalse();
});
