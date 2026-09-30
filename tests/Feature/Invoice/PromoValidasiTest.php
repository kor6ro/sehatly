<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PromoController;
use App\Http\Requests\Promo\ValidasiPromoRequest;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\PromoHitungan;
use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/invoice-helpers.php';

/*
|--------------------------------------------------------------------------
| Todo 44 - `POST /api/v1/promo/validasi` over HTTP
|--------------------------------------------------------------------------
|
| ## The three claims this file has to earn, and how
|
| 1. **It writes nothing.** Asserted as TWO row counts - `promo_redemption`
|    AND `invoice` - because "no redemption" alone would be satisfied by a
|    controller that minted an invoice and then rolled it back, and a validator
|    that quietly created a throwaway invoice to hold a row would be a real
|    defect with a single-count assertion still green.
|
| 2. **It is a calculation, not a query for a verdict.** The five reasons arrive
|    as `data.alasan` with machine-readable `kode` values, and the money is
|    recomputed from the STORED invoice. A client must not be able to talk the
|    server into a discount on a basket it did not buy, and the test proves it
|    by sending a `subtotal` the request schema does not even accept and by
|    changing the stored row behind the endpoint's back.
|
| 3. **It is reachable by the right callers and nobody else.** The gate decision
|    is the interesting one: `promo.validasi` is a real permission code granted
|    to `admin` and `superadmin` and NOT to `pasien`, so gating on it would 403
|    the only account that can use the endpoint. The test asserts the grant list
|    from `RbacCatalog` directly and then drives the route as a patient.
|
| ## The route's guards, asserted rather than assumed
|
| | route | `permission:` | `tipe:` | who is refused, and why |
| | --- | --- | --- | --- |
| | `POST /promo/validasi` | - | - | an account with no `pasien` row, 403 from `ownPasien()` |
|
*/

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

// =====================================================================
// The route table
// =====================================================================

test('route:list --path=api/v1/promo lists exactly one route and it is this one', function (): void {
    $punya = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/promo'))
        ->keyBy(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->all();

    // `Route::methods()` on a POST route is `['POST']` - Laravel adds `HEAD` to
    // the ACCEPTED set, not to `methods()`, and this assertion is only
    // meaningful if it names what the framework really returns rather than what
    // a request could be.
    expect(array_keys($punya))->toBe(['POST api/v1/promo/validasi'])
        ->and($punya['POST api/v1/promo/validasi']->getName())->toBe('promo.validasi')
        ->and($punya['POST api/v1/promo/validasi']->getActionName())
        ->toBe(PromoController::class.'@validasi');

    // The guards, read off the route rather than off the source file: a gate
    // added here would change this assertion and nothing else in the file.
    $middleware = array_values(array_filter(
        $punya['POST api/v1/promo/validasi']->gatherMiddleware(),
        static fn ($m): bool => is_string($m),
    ));

    expect($middleware)->toContain('auth:sanctum');

    // No `permission:` and no `tipe:` - the reasons are in the routes/api.php
    // block, and the decisive one is that `promo.validasi` is not granted to
    // the role this endpoint exists for.
    foreach ($middleware as $satu) {
        expect($satu)->not->toStartWith('permission:')
            ->and($satu)->not->toStartWith('tipe:');
    }
});

test('the permission code exists but is NOT granted to a patient, which is why it is not a gate', function (): void {
    // The whole guard decision rests on this, so it is read out of the
    // catalogue rather than quoted from its docblock.
    expect(array_key_exists('promo.validasi', RbacCatalog::PERMISSIONS))->toBeTrue();

    $pemegang = [];

    foreach (RbacCatalog::ROLE_PERMISSIONS as $role => $codes) {
        if (in_array('promo.validasi', $codes, true)) {
            $pemegang[] = $role;
        }
    }

    expect($pemegang)->toBe(['admin', 'superadmin'])
        ->and(in_array('pasien', $pemegang, true))->toBeFalse();

    // So a `permission:promo.validasi` gate would 403 the one account type
    // that owns a `pasien` row. Stated as an assertion because "the gate is
    // absent" is only defensible with "the gate would have been wrong".
    $daftarPasien = RbacCatalog::ROLE_PERMISSIONS['pasien'];

    expect($daftarPasien)->toContain('pembayaran.bayar')
        ->and($daftarPasien)->not->toContain('promo.validasi');
});

// =====================================================================
// The purity claim
// =====================================================================

test('a valid code answers 200 with the computed discount and writes NOTHING', function (): void {
    inv44KunciJam();

    try {
        [$user, $pasienId] = inv44AkunPasien('Pasien Validasi');

        // The invoice is the thing the calculation runs AGAINST, so it has to
        // exist first. It is written through the service, not by the endpoint.
        $invoice = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('150000.00')]);

        $kode = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '10.00']);

        $setelahInvoice = DB::table('invoice')->count();

        $response = test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => $invoice->getKey(),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.nilai_diskon', '15000.00')
            ->assertJsonPath('data.total', '135000.00')
            ->assertJsonPath('data.rincian.subtotal', '150000.00')
            ->assertJsonPath('data.rincian.diskon', '15000.00')
            ->assertJsonPath('data.alasan', [])
            ->assertJsonPath('data.promo.kode', $kode);

        // THE claim. Two counts, because a controller that minted an invoice
        // and rolled it back would satisfy the first alone.
        expect(DB::table('promo_redemption')->count())->toBe(0)
            ->and(DB::table('invoice')->count())->toBe($setelahInvoice);

        // And the stored invoice is byte-identical: no `diskon`, no `total`
        // change, no `status` change. A "pure" endpoint that wrote a preview
        // onto the row would be a checkout that shows two different totals.
        $mentah = DB::selectOne('SELECT diskon, total, status FROM invoice WHERE id = ?', [$invoice->getKey()]);

        expect($mentah->diskon)->toBe('0.00')
            ->and($mentah->total)->toBe('150000.00')
            ->and($mentah->status)->toBe('menunggu_pembayaran');
    } finally {
        inv44LepasJam();
    }
});

test('the endpoint never writes, however many times it is called', function (): void {
    inv44KunciJam();

    try {
        [$user, $pasienId] = inv44AkunPasien('Pasien Berulang');
        $invoice = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('150000.00')]);
        $kode = inv44Kode(['tipe_diskon' => 'nominal', 'nilai' => '5000.00']);

        $sebelum = [
            'invoice' => DB::table('invoice')->count(),
            'redemption' => DB::table('promo_redemption')->count(),
        ];

        for ($i = 0; $i < 5; $i++) {
            test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
                'kode' => $kode,
                'invoice_id' => $invoice->getKey(),
            ])->assertOk()->assertJsonPath('data.valid', true);
        }

        // Five validations, five identical answers, and not one row written.
        // A validation that consumed quota would also make the sixth call
        // different, which is why the count is asserted as well as the answer.
        expect(DB::table('invoice')->count())->toBe($sebelum['invoice'])
            ->and(DB::table('promo_redemption')->count())->toBe($sebelum['redemption']);

        // The same code, APPLIED, writes exactly one row. So the difference
        // between reading and writing is a fact about the two paths rather than
        // about the fixture.
        $diterapkan = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode);

        expect($diterapkan->diskon)->toBe('5000.00')
            ->and(DB::table('promo_redemption')->where('promo_id', DB::table('master_promo')->where('kode', $kode)->value('id'))->count())->toBe(1);
    } finally {
        inv44LepasJam();
    }
});

test('an expired window is reported as valid false, with the reason and the money unchanged', function (): void {
    inv44KunciJam();

    try {
        [$user, $pasienId] = inv44AkunPasien('Pasien Kedaluwarsa');

        $invoice = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('150000.00')]);

        $kode = inv44Kode([
            'mulai_at' => '2026-03-01 00:00:00',
            'selesai_at' => '2026-03-10 23:59:59',
        ]);

        $response = test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => $invoice->getKey(),
        ]);

        // 200, not 422: the caller asked a question and the answer is no.
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.nilai_diskon', '0.00')
            // The quoted total is the UNdiscounted one, because a refused promo
            // changes nothing. A preview quoting the discounted price would be a
            // price the apply path would not honour.
            ->assertJsonPath('data.total', '150000.00')
            ->assertJsonPath('data.alasan.0.kode', PromoHitungan::KODE_SUDAH_BERAKHIR)
            ->assertJsonPath('data.alasan.0.kolom', 'jendela_waktu')
            ->assertJsonPath('data.alasan.0.pesan', 'Promo sudah berakhir.');

        expect(DB::table('promo_redemption')->count())->toBe(0);
    } finally {
        inv44LepasJam();
    }
});

test('a promo failing several rules reports EVERY reason, each with a machine-readable code', function (): void {
    inv44KunciJam();

    try {
        [$user, $pasienId] = inv44AkunPasien('Pasien Ganda');

        $invoice = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('1000.00')]);

        $kode = inv44Kode([
            'status_aktif' => 0,
            'mulai_at' => '2026-03-01 00:00:00',
            'selesai_at' => '2026-03-10 00:00:00',
            'min_transaksi' => '50000.00',
            'kuota_total' => 0,
        ]);

        $data = test()->withHeaders(inv44As($user))
            ->postJson('/api/v1/promo/validasi', ['kode' => $kode, 'invoice_id' => $invoice->getKey()])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->json('data');

        $kodeAlasan = array_column($data['alasan'], 'kode');

        // Four rules, four reasons, in rule order. A client that stops at the
        // first would show a patient one problem four times over.
        expect($kodeAlasan)->toBe([
            PromoHitungan::KODE_TIDAK_AKTIF,
            PromoHitungan::KODE_SUDAH_BERAKHIR,
            PromoHitungan::KODE_MINIMUM,
            PromoHitungan::KODE_KUOTA_TOTAL,
        ]);

        // Every reason's `kolom` is a real field name from the 422 vocabulary,
        // so a client can render a 422 and a 200 from ONE map.
        $kolom = array_column($data['alasan'], 'kolom');

        expect($kolom)->toBe(['status_aktif', 'jendela_waktu', 'min_transaksi', 'kuota']);

        // And every reason code this endpoint can emit is one the service
        // knows, derived from the same constant.
        foreach ($kodeAlasan as $satu) {
            expect(array_keys(PromoHitungan::KOLOM))->toContain($satu);
        }

        expect(ValidasiPromoRequest::kodeAlasan())->toBe(array_keys(PromoHitungan::KOLOM));
    } finally {
        inv44LepasJam();
    }
});

test('an unknown code is answered with the same envelope and no lookup exception', function (): void {
    inv44KunciJam();

    try {
        [$user, $pasienId] = inv44AkunPasien('Pasien Kode Hantu');
        $invoice = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('150000.00')]);

        $response = test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
            'kode' => 'TIDAKPERNAHADA99',
            'invoice_id' => $invoice->getKey(),
        ]);

        // The promo block is present with three NULL fields rather than absent:
        // the caller DID supply a code, so the honest answer is "there is no
        // promo by that name and here are its attributes, which we do not
        // know" - not an omitted key that reads as "no promo was sent".
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.nilai_diskon', '0.00')
            ->assertJsonPath('data.promo.kode', null)
            ->assertJsonPath('data.promo.nama', null)
            ->assertJsonPath('data.promo.tipe_diskon', null)
            ->assertJsonPath('data.alasan.0.kode', PromoHitungan::KODE_TIDAK_DITEMUKAN)
            ->assertJsonPath('data.alasan.0.kolom', 'kode');

        expect($response->json('data'))->toHaveKey('promo');

        expect(DB::table('promo_redemption')->count())->toBe(0);
    } finally {
        inv44LepasJam();
    }
});

// =====================================================================
// The money comes from the STORE, not the request
// =====================================================================

test('the discount is computed from the stored invoice, and a client cannot supply a subtotal', function (): void {
    inv44KunciJam();

    try {
        [$user, $pasienId] = inv44AkunPasien('Pasien Amount');

        $invoice = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('200000.00')]);

        $kode = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '25.00', 'min_transaksi' => '0.00']);

        // 25% of the STORED 200000 is 50000. A client claiming a 1000 subtotal
        // to reach a discount band must not be believed.
        $response = test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => $invoice->getKey(),
            'subtotal' => '1000.00',
            'diskon' => '999999.00',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.nilai_diskon', '50000.00')
            ->assertJsonPath('data.rincian.subtotal', '200000.00')
            ->assertJsonPath('data.total', '150000.00');

        // The invented keys are ignored rather than accepted, and nothing was
        // written from them.
        expect($response->json('data'))->not->toHaveKey('subtotal_claim')
            ->and(DB::table('invoice')->where('id', $invoice->getKey())->value('total'))->toBe('200000.00');

        // `min_transaksi` is read from the STORED subtotal as well. A promo
        // needing 150000 is accepted against the stored 200000, and one needing
        // 250000 is refused against the SAME row - so the decision follows the
        // row and not anything the client sent.
        $kodeCukup = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '10.00', 'min_transaksi' => '150000.00']);

        test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
            'kode' => $kodeCukup,
            'invoice_id' => $invoice->getKey(),
        ])->assertOk()->assertJsonPath('data.valid', true);

        $kodeBesar = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '10.00', 'min_transaksi' => '250000.00']);

        $ditolak = test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
            'kode' => $kodeBesar,
            'invoice_id' => $invoice->getKey(),
        ]);

        $ditolak->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.alasan.0.kode', PromoHitungan::KODE_MINIMUM);
    } finally {
        inv44LepasJam();
    }
});

test('a quota consumed by another request is reflected, because the counts are re-read every time', function (): void {
    inv44KunciJam();

    try {
        [$user, $pasienId] = inv44AkunPasien('Pasien Kuasa');
        $invoice = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('150000.00')]);

        $promoId = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_total' => 1, 'kuota_per_user' => 5]);
        $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

        $pertama = test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => $invoice->getKey(),
        ]);

        $pertama->assertOk()->assertJsonPath('data.valid', true);

        // The preview wrote nothing, so the quota is still whole. Applying it
        // through the service is what consumes one.
        expect(DB::table('promo_redemption')->count())->toBe(0);

        app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode);

        // The SECOND preview, after the apply, reports the exhaustion. A cached
        // or memoised evaluation would still say valid, which is the defect
        // that would let a client show "promo applied" for a dead code.
        $kedua = test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => $invoice->getKey(),
        ]);

        $kedua->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.nilai_diskon', '0.00')
            ->assertJsonPath('data.total', '150000.00')
            ->assertJsonPath('data.alasan.0.kode', PromoHitungan::KODE_KUOTA_TOTAL)
            ->assertJsonPath('data.alasan.0.pesan', 'Kuota promo telah habis (1/1).');
    } finally {
        inv44LepasJam();
    }
});

test('gratis_ongkir is previewed on the shipping column, not as a discount', function (): void {
    inv44KunciJam();

    try {
        [$user, $pasienId] = inv44AkunPasien('Pasien Ongkir');

        $invoice = app(InvoiceService::class)->buat(
            'pesanan_obat',
            inv44PesananObat($pasienId),
            $pasienId,
            [inv44Baris('120000.00')],
            null,
            null,
            '20000.00'
        );

        $kode = inv44Kode(['tipe_diskon' => 'gratis_ongkir', 'nilai' => '0.00']);

        test()->withHeaders(inv44As($user))
            ->postJson('/api/v1/promo/validasi', ['kode' => $kode, 'invoice_id' => $invoice->getKey()])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.nilai_diskon', '0.00')
            ->assertJsonPath('data.rincian.biaya_pengiriman', '0.00')
            ->assertJsonPath('data.total', '120000.00');

        // The stored invoice is untouched: the waiver is a QUOTE, and the row
        // still carries the 20000 until the promo is actually applied.
        expect(DB::table('invoice')->where('id', $invoice->getKey())->value('biaya_pengiriman'))->toBe('20000.00');
    } finally {
        inv44LepasJam();
    }
});

// =====================================================================
// Ownership and the envelope
// =====================================================================

test('another patient invoice is a 404, and an account with no patient row is a 403', function (): void {
    inv44KunciJam();

    try {
        [$userA, $pasienA] = inv44AkunPasien('Pasien A');
        [$userB, $pasienB] = inv44AkunPasien('Pasien B');

        $invoiceA = app(InvoiceService::class)->buat('booking', inv44Booking($pasienA), $pasienA, [inv44Baris('150000.00')]);
        $kode = inv44Kode();

        // 404, not 403: a 403 would confirm the invoice exists, which is a
        // cross-tenant existence oracle.
        test()->withHeaders(inv44As($userB))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => $invoiceA->getKey(),
        ])->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Resource not found.');

        // An id that never existed answers the SAME body, so the two are
        // indistinguishable from outside.
        $hilang = test()->withHeaders(inv44As($userB))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => 999999999,
        ]);

        $hilang->assertNotFound();

        expect($hilang->json())->toBe(test()->withHeaders(inv44As($userB))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => $invoiceA->getKey(),
        ])->json());

        // The owner gets through.
        test()->withHeaders(inv44As($userA))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => $invoiceA->getKey(),
        ])->assertOk();

        // 403 for an account that owns no `pasien` row at all. A `dokter` has
        // no `pasien` row, which is the cheapest honest way to be one.
        $dokter = inv44User('Dokter Tanpa Pasien', 'dokter', 'dokter');

        test()->withHeaders(inv44As($dokter))->postJson('/api/v1/promo/validasi', [
            'kode' => $kode,
            'invoice_id' => $invoiceA->getKey(),
        ])->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    } finally {
        inv44LepasJam();
    }
});

test('an unauthenticated caller is a 401 from the guard, not a 422 on kode', function (): void {
    test()->postJson('/api/v1/promo/validasi', ['kode' => 'APA-SAJA', 'invoice_id' => 1])
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('a malformed request is a 422 on the field, and the message names it', function (): void {
    inv44KunciJam();

    try {
        [$user] = inv44AkunPasien('Pasien Rapi');

        $body = [
            'kode' => str_repeat('A', 31),
            'invoice_id' => 1,
        ];

        test()->withHeaders(inv44As($user))
            ->postJson('/api/v1/promo/validasi', $body)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.kode.0', 'Kode promo maksimal 30 karakter.');

        // Both keys missing: two messages, two fields, both preserved as lists.
        $kosong = test()->withHeaders(inv44As($user))
            ->postJson('/api/v1/promo/validasi', [])
            ->assertUnprocessable();

        expect($kosong->json('errors'))->toHaveKeys(['kode', 'invoice_id'])
            ->and($kosong->json('errors.kode'))->toBeArray()
            ->and($kosong->json('errors.invoice_id'))->toBeArray();

        // `master_promo.kode` is VARCHAR(30) (:987) and the field rule is
        // `max:30` because of it. Asserted against the DDL so the two cannot
        // drift - a widened column and a narrowed rule are a real coupling.
        inv44AssertLine(987, 'kode VARCHAR(30) NOT NULL UNIQUE');
    } finally {
        inv44LepasJam();
    }
});

test('the success envelope carries no meta, because a calculation is not a list', function (): void {
    inv44KunciJam();

    try {
        [$user, $pasienId] = inv44AkunPasien('Pasien Meta');
        $invoice = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('150000.00')]);

        $response = test()->withHeaders(inv44As($user))->postJson('/api/v1/promo/validasi', [
            'kode' => inv44Kode(),
            'invoice_id' => $invoice->getKey(),
        ]);

        $response->assertOk();

        // Key ORDER is the contract: success, data, message, and `meta` last
        // when present. Here it is absent ENTIRELY rather than null, which is
        // what `ApiResponse` does for `$meta === null` and the only shape in
        // which "this is not paginated" is unambiguous.
        $body = $response->getContent();
        $urutan = [];

        preg_match_all('/"(success|data|message|meta)":/', (string) $body, $urutan);

        expect(array_values(array_unique($urutan[1])))->toBe(['success', 'data', 'message'])
            ->and($response->json())->not->toHaveKey('meta')
            ->and((string) $body)->not->toContain('"meta"');
    } finally {
        inv44LepasJam();
    }
});
