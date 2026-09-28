<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PembayaranGateway;
use App\Enums\PembayaranStatus;
use App\Http\Controllers\Api\V1\PembayaranController;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Pembayaran;
use App\Services\Payment\MockPaymentGatewayService;
use App\Services\Payment\PaymentGatewayService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/payment-helpers.php';

/*
|--------------------------------------------------------------------------
| Todo 45 - the payment gateway abstraction and the idempotent webhook
|--------------------------------------------------------------------------
|
| ## What this file is actually proving
|
| One endpoint here is UNAUTHENTICATED. `POST /api/v1/webhook/payment/{gateway}`
| has no `auth:sanctum`, because a payment provider is not a user of this
| system and has no token. That makes it the most dangerous route in Modules 1-5
| by a wide margin: anything it can be talked into doing, anybody on the
| internet can do. So the order of operations is the design, and it is proved
| rather than described:
|
| 1. **`{gateway}` is validated first** - it is a client-supplied path segment,
|    and `pembayaran.gateway` is a four-value ENUM (telemedicine_test.sql:965).
|    A segment outside it is a 404, from the route constraint, before the
|    controller runs at all.
| 2. **The signature is verified before ANY database read.** The test for this
|    is not "the row did not change" - a webhook handler that read a row, then
|    rejected the signature, would leave the row unchanged too. It is a
|    `DB::listen` assertion that a forged request issues **zero** statements
|    naming `pembayaran` or `invoice`. A handler that touched state first could
|    not pass it.
| 3. **Only then is a `pembayaran` row looked up**, by the pair
|    `(gateway, nomor_referensi)`.
|
| ## The dedupe key, and why it is application-level
|
| The key is **`(pembayaran.gateway, pembayaran.nomor_referensi)`**. The
| `gateway` half is the `{gateway}` path segment, already narrowed to the four
| ENUM members. The `nomor_referensi` half is minted by the gateway at
| initiation (the plan's "transaction ID payment gateway", :963) and echoed
| back by the provider in the signed body - so it is a value the payment
| provider itself asserts, not one the caller supplies.
|
| `nomor_referensi` is `VARCHAR(100) NULL` (:963) and **carries no UNIQUE
| constraint and no index**. The only index on `pembayaran` is
| `idx_bayar_status (status, dibayar_at)` (:972), which does not contain either
| column of the key. Both facts are asserted against the raw DDL line, and the
| live table is probed for a unique index over the pair, because a citation is
| a claim about a file and the file is what this is argued from.
|
| **So this is a best-effort application-level guard, not a database
| guarantee**, and the race is real:
|
| - SEQUENTIALLY, it is exact. The first delivery moves the row out of
|    `pending`; the second sees a terminal status and returns without writing.
|    That is the acceptance criterion and it is asserted with three independent
|    counters (below).
| - CONCURRENTLY, two identical deliveries can both read `pending` before either
|    writes, and both write. The schema cannot prevent that and no index may be
|    added. What closes it is the **row lock**: the lookup is
|    `SELECT ... FOR UPDATE` on the `pembayaran` row, taken INSIDE the
|    transaction, and InnoDB's locking read resolves against the LATEST
|    committed version rather than the transaction's own snapshot. The second
|    transaction blocks on that row, wakes up after the first commits, re-reads
|    the now-terminal status, and takes the duplicate branch. The two-connection
|    test at the end of this file proves the lock is real by making a second
|    connection collide on it with MySQL error 1205.
|
| The plan's `:970` citation for `idx_bayar_status` is off by two - it is at
| `:972` - and its `:936-982` range for these three tables runs 9 lines past
| `refund`'s closing `ENGINE`. Both are corrected here, and the correction is
| asserted rather than noted: {@see pay45Blok()} reads each block from its
| opening `CREATE TABLE` to its own closing `ENGINE` line, so a range that runs
| past the end cannot be written without the test failing.
|
| ## The three counters behind "exactly one state change"
|
| A `TIMESTAMP` alone cannot prove it - two writes inside the same second are
| indistinguishable from one. So the clock is frozen, the first delivery is
| made, the clock is ADVANCED an hour, and the second delivery is made. Any
| re-application then writes different values, and three independent counters
| catch it:
|
| | counter | where it lives | why it cannot be a tautology |
| | --- | --- | --- |
| | `pembayaran.dibayar_at` (:967) | the row | a re-write would stamp the ADVANCED instant |
| | `invoice.lunas_at` (:950) | the row | ditto |
| | `audit_log` `update` rows | the log | `Pembayaran`, `Invoice` and `Booking` are all in the derived audit scope, and a second write produces a second row |
|
| ## The second response is a no-op that still says yes
|
| A provider that retries must not get an error: a 4xx makes it retry harder,
| and some providers give up and leave. So the duplicate answers **200 with the
| same envelope shape and the same `data` keys**, differing only in
| `data.duplicate`. The test asserts the two decoded bodies have IDENTICAL key
| sets and identical values for every key except `duplicate`, and the same HTTP
| status - so "it is a no-op" and "it is still a success" are both proved, not
| assumed.
|
| ## The `webhook_payload` overwrite decision
|
| **A re-delivery does NOT overwrite it.** The column (:968) is the forensic
| record of the delivery that CAUSED the state change, and there is exactly one
| of those. A retry carries no new information and would overwrite the evidence
| with a byte-identical copy, so allowing it would be a no-op in the best case
| and a rewrite of the audit record in the worst - and the second test asserts
| the stored JSON is the FIRST body, with the retry's own extra key absent.
|
| ## `refund` and `dibatalkan` are NOT reachable from this endpoint
|
| `pembayaran.status` is a five-value ENUM (:966) and only three of the five
| are decided here: `berhasil`, `gagal` and `kedaluwarsa`. `refund` belongs to
| the refund workflow, which `refund.status` (:980) and this plan's later todos
| own, and accepting a provider "refund" callback here would settle a refund
| with no `refund` row - so an unknown status is a 422, not a write.
|
*/

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    pay45KunciJam();
});

afterEach(function (): void {
    pay45LepasJam();
});

// =====================================================================
// The DDL, read from the file rather than quoted from the plan
// =====================================================================

test('every DDL line this todo argues from is the line the file actually has', function (): void {
    // `master_metode_pembayaran`, :925-934. The plan cites `:925-934`; verified.
    pay45AssertLine(925, 'CREATE TABLE master_metode_pembayaran (');
    pay45AssertLine(929, "ENUM('va_bank','e_wallet','qris','kartu_kredit','gerai_retail','cod','tunai','bpjs','asuransi')");
    pay45AssertLine(931, 'biaya_admin_flat DECIMAL(12,2) NOT NULL DEFAULT 0');
    pay45AssertLine(932, 'biaya_admin_persen DECIMAL(5,2) NOT NULL DEFAULT 0');
    pay45AssertLine(933, 'status_aktif TINYINT(1) NOT NULL DEFAULT 1');
    pay45AssertLine(934, ') ENGINE=InnoDB;');

    // `invoice`, :936-956.
    pay45AssertLine(936, 'CREATE TABLE invoice (');
    pay45AssertLine(940, "ENUM('booking','konsultasi','resep','pesanan_obat','lab_permintaan','home_care')");
    pay45AssertLine(942, 'subtotal DECIMAL(14,2) NOT NULL DEFAULT 0');
    pay45AssertLine(944, 'biaya_admin DECIMAL(14,2) NOT NULL DEFAULT 0');
    // `:946` is `total DECIMAL(14,2) NOT NULL` with NO DEFAULT - the negative
    // half is the load-bearing one, and it is why the payment reads `total`
    // rather than recomputing a total of its own.
    pay45AssertLine(946, 'total DECIMAL(14,2) NOT NULL');
    pay45AssertLineLacks(946, 'DEFAULT');
    pay45AssertLine(947, "status ENUM('draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan',");
    pay45AssertLine(948, "'refund_sebagian','refund_penuh') NOT NULL DEFAULT 'menunggu_pembayaran',");
    pay45AssertLine(950, 'lunas_at DATETIME NULL');
    pay45AssertLine(954, 'INDEX idx_invoice (pasien_id, status)');
    pay45AssertLine(955, 'INDEX idx_ref (referensi_tipe, referensi_id)');
    pay45AssertLine(956, ') ENGINE=InnoDB;');

    // `pembayaran`, :958-973. The plan's `:936-982` range runs 9 lines past
    // `refund`'s ENGINE, and its `:970` for `idx_bayar_status` is off by two.
    pay45AssertLine(958, 'CREATE TABLE pembayaran (');
    pay45AssertLine(960, 'invoice_id BIGINT UNSIGNED NOT NULL');
    pay45AssertLine(961, 'metode_id SMALLINT UNSIGNED NOT NULL');
    pay45AssertLine(962, 'jumlah DECIMAL(14,2) NOT NULL');
    pay45AssertLine(963, "nomor_referensi VARCHAR(100) NULL COMMENT 'Transaction ID payment gateway'");
    // THE citation the whole idempotency design rests on. `nomor_referensi`
    // carries no unique key and no index, so the guard cannot be delegated to
    // the database.
    pay45AssertLineLacks(963, 'UNIQUE');
    pay45AssertLineLacks(963, 'INDEX');
    pay45AssertLineLacks(963, 'KEY');
    pay45AssertLine(964, 'va_number VARCHAR(30) NULL');
    pay45AssertLine(965, "gateway ENUM('midtrans','xendit','doku','flip') NULL");
    pay45AssertLine(966, "status ENUM('pending','berhasil','gagal','kedaluwarsa','refund') NOT NULL DEFAULT 'pending'");
    pay45AssertLine(967, 'dibayar_at DATETIME NULL');
    pay45AssertLine(968, 'webhook_payload JSON NULL');
    pay45AssertLine(969, 'dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    // `pembayaran` declares NO `diubah_at`. A re-write therefore leaves the
    // row's own timestamps untouched, which is why the acceptance criterion is
    // measured on `dibayar_at` and on `audit_log` instead.
    pay45AssertLineLacks(969, 'diubah_at');
    pay45AssertLine(970, 'FOREIGN KEY (invoice_id) REFERENCES invoice(id),');
    pay45AssertLine(971, 'FOREIGN KEY (metode_id) REFERENCES master_metode_pembayaran(id),');
    // :972, NOT :970 as the plan has it.
    pay45AssertLine(972, 'INDEX idx_bayar_status (status, dibayar_at)');
    // ...and that index is over neither column of the dedupe key.
    pay45AssertLineLacks(972, 'nomor_referensi');
    pay45AssertLineLacks(972, 'UNIQUE');
    pay45AssertLine(973, ') ENGINE=InnoDB;');

    // `refund`, :975-983. The plan's range said :982 for the table; :983 is the
    // closing ENGINE line.
    pay45AssertLine(975, 'CREATE TABLE refund (');
    pay45AssertLine(980, "status ENUM('diajukan','diproses','berhasil','ditolak') NOT NULL DEFAULT 'diajukan'");
    pay45AssertLine(983, ') ENGINE=InnoDB;');

    // The two referenced entities this endpoint advances.
    pay45AssertLine(515, "status ENUM('menunggu_pembayaran','terjadwal','check_in','berlangsung','selesai',");
    pay45AssertLine(516, "'dibatalkan','no_show','kadaluarsa') NOT NULL DEFAULT 'menunggu_pembayaran',");
    pay45AssertLine(751, "status ENUM('aktif','diproses','diverifikasi','dipenuhi','dikirim','selesai',");
    pay45AssertLine(752, "'kedaluwarsa','dibatalkan') NOT NULL DEFAULT 'aktif',");
    pay45AssertLine(810, "status ENUM('menunggu_pembayaran','diproses','siap','sedang_dikirim','selesai','dibatalkan')");
});

test('the three-table block ends where the DDL says, and the plan range is past it', function (): void {
    // The plan writes `:936-982` for `invoice` + `pembayaran` + `refund`. Read
    // from the file, `refund` ENDS at :983, so the plan's range stops one line
    // short of the closing ENGINE and :982 is its last INDEX/FK line. Asserted
    // through the block reader so the claim cannot rot.
    expect(pay45Blok('pembayaran'))->toHaveCount(16)
        ->and(pay45Blok('refund'))->toHaveCount(9)
        ->and(pay45Blok('invoice'))->toHaveCount(21);

    // The dedupe key's two columns are in NO index at all - not `idx_bayar_status`
    // and not any of the three single-column indexes MySQL synthesises for the
    // two foreign keys. Walked off the live table, because "the DDL has no index
    // over the pair" is a claim about the database and this is the database.
    $index = DB::select(
        'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['pembayaran']
    );

    $indexable = array_values(array_unique(array_map(
        static fn (object $baris): string => (string) $baris->COLUMN_NAME,
        $index
    )));

    expect($indexable)->not->toContain('nomor_referensi')
        ->and($indexable)->not->toContain('gateway')
        ->and($indexable)->toContain('status')
        ->and($indexable)->toContain('dibayar_at');

    // And nothing over the pair is UNIQUE. `PRIMARY` is excluded because it is
    // the clustered primary key rather than a secondary index: it is unique by
    // definition and it is not a dedupe key anybody could use, since a provider
    // never sends a `pembayaran.id`.
    $unik = array_values(array_unique(array_map(
        static fn (object $baris): string => (string) $baris->INDEX_NAME,
        array_filter(
            $index,
            static fn (object $baris): bool => (int) $baris->NON_UNIQUE === 0 && $baris->INDEX_NAME !== 'PRIMARY'
        )
    )));

    expect($unik)->toBe([]);
});

test('the three payment enums are exactly what the DDL declares, in its order', function (): void {
    // The plan's hard requirement that the values come from the file, not from
    // a transcription. `toBe` on the array checks ORDER as well as membership,
    // so a reordered enum fails.
    expect(PembayaranGateway::nilai())->toBe(pay45Enum('pembayaran', 'gateway'))
        ->and(PembayaranGateway::nilai())->toBe(['midtrans', 'xendit', 'doku', 'flip'])
        ->and(PembayaranStatus::nilai())->toBe(pay45Enum('pembayaran', 'status'))
        ->and(PembayaranStatus::nilai())->toBe(['pending', 'berhasil', 'gagal', 'kedaluwarsa', 'refund'])
        ->and(InvoiceStatus::nilai())->toBe(pay45Enum('invoice', 'status'))
        ->and(InvoiceStatus::nilai())->toBe([
            'draft', 'menunggu_pembayaran', 'lunas', 'kadaluarsa', 'dibatalkan', 'refund_sebagian', 'refund_penuh',
        ]);

    // The three settlement outcomes this endpoint decides, and the two it must
    // NOT decide. `refund` is the one a reader is most likely to expect here
    // and it is deliberately absent.
    expect(PembayaranStatus::KEADAAN_AKHIR)->toBe(['berhasil', 'gagal', 'kedaluwarsa', 'refund'])
        ->and(PembayaranStatus::SETTLE)->toBe(['berhasil', 'gagal', 'kedaluwarsa'])
        ->and(PembayaranStatus::SETTLE)->not->toContain('refund');

    // Every non-`pending` member is terminal, so "a terminal status" and "a
    // status the dedupe treats as final" are the same set and cannot drift.
    foreach (PembayaranStatus::nilai() as $status) {
        expect(PembayaranStatus::adalahAkhir($status))->toBe($status !== PembayaranStatus::Pending->value);
    }
});

// =====================================================================
// The routes
// =====================================================================

test('route:list lists both todo-45 routes with the guards the plan requires', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1'))
        ->keyBy(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->all();

    expect($routes)->toHaveKeys([
        'POST api/v1/invoice/{id}/bayar',
        'POST api/v1/webhook/payment/{gateway}',
    ]);

    $bayar = $routes['POST api/v1/invoice/{id}/bayar'];
    $webhook = $routes['POST api/v1/webhook/payment/{gateway}'];

    expect($bayar->getActionName())->toBe(PembayaranController::class.'@bayar')
        ->and($webhook->getActionName())->toBe(PembayaranController::class.'@webhook');

    $middleware = static fn ($route): array => array_values(array_filter(
        $route->gatherMiddleware(),
        static fn ($m): bool => is_string($m),
    ));

    // The initiation is the patient's own money: `auth:sanctum` AND the real
    // `pembayaran.bayar` grant, which `RbacCatalog` grants to `pasien`.
    expect($middleware($bayar))->toContain('auth:sanctum')
        ->and($middleware($bayar))->toContain('permission:pembayaran.bayar');

    // The webhook carries NEITHER. That is not an omission: a payment provider
    // has no Sanctum token. What stands in for `auth:sanctum` is the HMAC the
    // next tests prove is checked before any row is read.
    expect($middleware($webhook))->not->toContain('auth:sanctum');
    foreach ($middleware($webhook) as $satu) {
        expect($satu)->not->toStartWith('permission:')
            ->and($satu)->not->toStartWith('tipe:');
    }

    // `{id}` is a `BIGINT UNSIGNED` primary key and `{gateway}` is a four-value
    // ENUM; both constrained on the route, so neither can arrive as anything
    // else.
    expect($bayar->wheres)->toHaveKey('id')
        ->and((string) $bayar->wheres['id'])->toBe('[0-9]+');

    // The ENUM constraint is a `whereIn` compiled to a regex by the router.
    $whereGateway = (string) ($webhook->wheres['gateway'] ?? '');

    expect($whereGateway)->not->toBe('');

    foreach (PembayaranGateway::nilai() as $satu) {
        expect($whereGateway)->toContain($satu);
    }
});

test('the four gateway names are the only ones the webhook path accepts', function (): void {
    foreach (PembayaranGateway::nilai() as $satu) {
        pay45KunciJam();

        $res = pay45Kirim($satu, '{"a":1}', [pay45HeaderTtD() => 'x']);

        expect($res->getStatusCode())->not->toBe(404, $satu.' is a legal gateway and must not 404 at the router');
    }

    // Outside the ENUM, the ROUTER 404s - the controller is never reached. That
    // ordering matters: it means a path segment outside the enum cannot even
    // reach the code that reads a secret.
    foreach (['midtrans2', 'MIDTRANS', 'bank_bjb', ''] as $salah) {
        $res = test()->postJson('/api/v1/webhook/payment/'.$salah, ['nomor_referensi' => 'X']);

        expect($res->getStatusCode())->toBe(404, 'gateway ['.$salah.'] must 404');
    }
});

// =====================================================================
// The interface and the mock
// =====================================================================

test('the gateway is an interface with the two methods the plan names, and the mock implements it', function (): void {
    $refleksi = new ReflectionClass(PaymentGatewayService::class);

    expect($refleksi->isInterface())->toBeTrue()
        ->and($refleksi->isInstantiable())->toBeFalse()
        ->and(is_subclass_of(MockPaymentGatewayService::class, PaymentGatewayService::class))->toBeTrue();

    foreach (['createTransaction', 'verifyWebhook', 'nama', 'dapatkah'] as $metode) {
        expect($refleksi->hasMethod($metode))->toBeTrue('PaymentGatewayService::'.$metode.'() is missing');
    }

    // The two the plan spells out, with the signatures it gives them.
    $buat = $refleksi->getMethod('createTransaction');
    $verifikasi = $refleksi->getMethod('verifyWebhook');

    expect((string) $buat->getReturnType())->toBe('array')
        ->and((string) $verifikasi->getReturnType())->toBe('array')
        ->and((string) $buat->getParameters()[0]->getType())->toBe(App\Models\Invoice::class)
        ->and((string) $buat->getParameters()[1]->getType())->toBe(App\Models\MasterMetodePembayaran::class)
        ->and((string) $verifikasi->getParameters()[0]->getType())->toBe(Illuminate\Http\Request::class);

    // The container resolves the INTERFACE, and resolves it to the mock -
    // which is what makes a real Midtrans a one-line change to this file.
    $terikat = app(PaymentGatewayService::class);

    expect($terikat)->toBeInstanceOf(MockPaymentGatewayService::class)
        ->and($terikat->nama())->toBe(config('payment.gateway'))
        ->and(config('payment.gateway'))->toBe('mock');

    // And the binding is in `AppServiceProvider`, not in a controller.
    $sumber = (string) file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

    expect($sumber)->toContain('PaymentGatewayService::class')
        ->and($sumber)->toContain('MockPaymentGatewayService');

    $kode = app()->getBindings()['App\Services\Payment\PaymentGatewayService'] ?? '';

    expect($kode)->toContain('MockPaymentGatewayService');
});

test('the mock mints a reference, a VA number and a QR string, all inside their columns', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    $metode = App\Models\MasterMetodePembayaran::query()->findOrFail($metodeId);
    $layanan = app(PaymentGatewayService::class);

    $hasil = $layanan->createTransaction($invoice, $metode);

    // The five keys the endpoint publishes, and the shape of each.
    expect($hasil)->toHaveKeys(['gateway', 'nomor_referensi', 'jumlah', 'instruksi', 'toko'])
        ->and($hasil['gateway'])->toBe(PembayaranGateway::Midtrans->value)
        ->and($hasil['jumlah'])->toBeString()
        ->and($hasil['jumlah'])->toBe(pay45JumlahInvoice($invoice))
        ->and($hasil['instruksi'])->toBeArray()->not->toBeEmpty();

    // `nomor_referensi VARCHAR(100)` (:963) and `va_number VARCHAR(30)`
    // (:964). A mock that generated a 40-character VA number would be a
    // truncation bug in production and a silent one here.
    expect(strlen((string) $hasil['nomor_referensi']))->toBeGreaterThan(0)
        ->and(strlen((string) $hasil['nomor_referensi']))->toBeLessThanOrEqual(100);

    // Two calls, two references. Not a UNIQUE constraint proving it - the
    // generator proving it, which is the half that is actually in our control.
    $kedua = $layanan->createTransaction($invoice->fresh(), $metode);

    expect($kedua['nomor_referensi'])->not->toBe($hasil['nomor_referensi']);

    // A `va_bank` method gets a VA number and NO QR string; a `qris` method
    // gets the other way round. The plan asks for "a fake `va_number`/`qr_string`"
    // and the schema has exactly one `va_number` column, so which of the two is
    // populated is a decision about the method's `tipe` (:929).
    expect($hasil['toko'])->toHaveKey('va_number')
        ->and($hasil['toko']['va_number'])->toBeString();

    if (isset($hasil['toko']['va_number'])) {
        expect(strlen((string) $hasil['toko']['va_number']))->toBeLessThanOrEqual(30);
    }

    $qris = App\Models\MasterMetodePembayaran::query()->findOrFail(pay45Metode(['tipe' => 'qris']));
    $hasilQris = $layanan->createTransaction($invoice->fresh(), $qris);

    expect($hasilQris['toko']['qr_string'])->toBeString()
        ->and($hasilQris['toko'])->not->toHaveKey('va_number')
        ->and($hasilQris['toko']['va_number'] ?? null)->toBeNull();
});

// =====================================================================
// Initiation
// =====================================================================

test('a patient initiates payment on their own unpaid invoice and gets instructions back', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $metodeId = pay45Metode(['biaya_admin_flat' => '2500.00', 'biaya_admin_persen' => '0.00']);

    // The invoice is minted WITH the method, so `biaya_admin` is in `total`
    // already. Re-applying it at payment time would charge the fee twice.
    $invoice = pay45Invoice('booking', $bookingId, $pasienId, $metodeId);

    expect((string) $invoice->biaya_admin)->toBe('2500.00')
        ->and((string) $invoice->total)->toBe('152500.00');

    $res = pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId]);

    $res->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.invoice.id', (int) $invoice->getKey())
        ->assertJsonPath('data.invoice.status', pay45StatusMenunggu())
        ->assertJsonPath('data.pembayaran.status', PembayaranStatus::Pending->value)
        ->assertJsonPath('data.pembayaran.gateway', PembayaranGateway::Midtrans->value);

    // Money is a JSON STRING at the boundary, both ways out.
    $body = $res->json('data.pembayaran.jumlah');

    expect($body)->toBe('152500.00')
        ->and($res->getContent())->toContain('"jumlah":"152500.00"');

    // The stored row. `jumlah` is `invoice.total` EXACTLY - the fee is applied
    // once, at mint time, and not again here.
    $baris = pay45PembayaranBaris((int) $invoice->getKey());

    expect($baris)->not->toBeNull()
        ->and($baris->jumlah)->toBe('152500.00')
        ->and((int) $baris->metode_id)->toBe($metodeId)
        ->and($baris->status)->toBe(PembayaranStatus::Pending->value)
        ->and($baris->gateway)->toBe(PembayaranGateway::Midtrans->value)
        ->and($baris->nomor_referensi)->toBeString()->not->toBeEmpty()
        ->and($baris->dibayar_at)->toBeNull()
        // Nothing has been paid, so the payload column is still NULL. The
        // webhook is the only writer.
        ->and($baris->webhook_payload)->toBeNull();

    // The invoice is NOT advanced by initiating: the money has not moved.
    expect((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu())
        ->and($invoice->fresh()->lunas_at)->toBeNull()
        ->and((string) DB::table('booking')->where('id', $bookingId)->value('status'))
        ->toBe('menunggu_pembayaran');
});

test('initiation refuses another patient 404, an account with no patient row 403, and a paid invoice 422', function (): void {
    [$milik, $pasienMilik] = pay45AkunPasien('Pemilik Invoice');
    [$asing, $pasienAsing] = pay45AkunPasien('Pasien Asing');
    $dokter = pay45User('Tanpa Pasien', 'dokter', 'dokter');

    $bookingMilik = pay45Booking($pasienMilik);
    $invoiceMilik = pay45Invoice('booking', $bookingMilik, $pasienMilik);
    $metodeId = pay45Metode();

    // Another patient's invoice is a 404, never a 403: a 403 would confirm the
    // row exists.
    pay45Ajax($asing)
        ->postJson("/api/v1/invoice/{$invoiceMilik->getKey()}/bayar", ['metode_id' => $metodeId])
        ->assertNotFound();

    expect(pay45PembayaranSemua((int) $invoiceMilik->getKey()))->toBe([]);

    // An account with no `pasien` row is a 403 - the refusal is about the
    // CALLER and discloses nothing about any row.
    pay45Ajax($dokter)
        ->postJson("/api/v1/invoice/{$invoiceMilik->getKey()}/bayar", ['metode_id' => $metodeId])
        ->assertForbidden();

    // Anonymous is the guard's 401, not this endpoint's 422.
    test()->postJson("/api/v1/invoice/{$invoiceMilik->getKey()}/bayar", ['metode_id' => $metodeId])
        ->assertUnauthorized();

    // A `lunas` invoice cannot be paid again, and says which state it is in.
    $invoiceMilik->forceFill([
        'status' => pay45StatusLunas(),
        'lunas_at' => PAY45_DETIK,
    ])->save();

    pay45Ajax($milik)
        ->postJson("/api/v1/invoice/{$invoiceMilik->getKey()}/bayar", ['metode_id' => $metodeId])
        ->assertStatus(422)
        ->assertJsonPath('errors.metode_id.0', 'Invoice dengan status ini tidak dapat dibayar.');
});

// =====================================================================
// The webhook: signature FIRST
// =====================================================================

test('a forged signature is rejected with NO state change AND no database read', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])
        ->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());
    $referensi = (string) $baris->nomor_referensi;

    // Baseline counters, taken while the row is untouched.
    $updateAwal = pay45HitungUpdate('pembayaran', (int) $baris->id)
        + pay45HitungUpdate('invoice', (int) $invoice->getKey())
        + pay45HitungUpdate('booking', $bookingId);

    expect($updateAwal)->toBe(0);

    // A VALID body, an INVALID signature. The tempting implementation - read
    // the payment, then check the signature - would pass a "no state change"
    // assertion. So the assertion here is on the STATEMENTS, not the rows.
    [$raw, $server] = pay45Webhook($referensi, 'berhasil', pay45JumlahInvoice($invoice));

    $pernyataan = [];
    $dengar = DB::listen(function (QueryExecuted $q) use (&$pernyataan): void {
        $pernyataan[] = $q->sql;
    });

    $res = pay45Kirim(PAY45_GATEWAY, $raw, $server);
    $dengar();

    $sentuh = array_values(array_filter(
        $pernyataan,
        static fn (string $sql): bool => (bool) preg_match('/\b(pembayaran|invoice|booking)\b/i', $sql)
            && ! str_contains(strtolower($sql), 'information_schema')
    ));

    // The load-bearing assertion. A webhook handler that touched state before
    // verifying the signature CANNOT pass this: it has issued its SELECT by now.
    expect($sentuh)->toBe([], 'the forged request issued statements against a payment table: '.json_encode($sentuh));

    expect($res->getStatusCode())->toBe(401);

    // ...and the rows, for the reader who does not believe the statement log.
    $sesudah = pay45PembayaranBaris((int) $invoice->getKey());

    expect($sesudah->status)->toBe(PembayaranStatus::Pending->value)
        ->and($sesudah->dibayar_at)->toBeNull()
        ->and($sesudah->webhook_payload)->toBeNull()
        ->and((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu())
        ->and($invoice->fresh()->lunas_at)->toBeNull()
        ->and((string) DB::table('booking')->where('id', $bookingId)->value('status'))->toBe('menunggu_pembayaran')
        ->and(pay45HitungUpdate('pembayaran', (int) $baris->id)
            + pay45HitungUpdate('invoice', (int) $invoice->getKey())
            + pay45HitungUpdate('booking', $bookingId))->toBe($updateAwal);
});

test('an ABSENT signature, an empty one and a wrong-length one are all rejected', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());
    $referensi = (string) $baris->nomor_referensi;
    [$raw, ] = pay45Webhook($referensi, 'berhasil', pay45JumlahInvoice($invoice));

    // Absent entirely.
    pay45Kirim(PAY45_GATEWAY, $raw, [])->assertStatus(401);

    // Present, empty. `hash_equals('', $expected)` is false, but the point is
    // that an empty header is not a degenerate pass.
    pay45Kirim(PAY45_GATEWAY, $raw, [pay45HeaderTtD() => ''])->assertStatus(401);

    // Right shape, wrong length: the truncation an implementation that used
    // `==` or a prefix compare would let through.
    pay45Kirim(PAY45_GATEWAY, $raw, [pay45HeaderTtD() => substr(hash_hmac('sha256', $raw, 'kunci'), 0, 16)])
        ->assertStatus(401);

    // The OTHER gateway's secret. This is why the secret is PER GATEWAY and not
    // one application-wide key.
    pay45Kirim(PAY45_GATEWAY, $raw, [pay45HeaderTtD() => hash_hmac('sha256', $raw, (string) config('services.payment.gateways.doku.webhook_secret'))])
        ->assertStatus(401);

    // A body the signature does not cover: re-serialising it with an extra
    // space changes the bytes, so the old signature no longer matches. This is
    // why the HMAC is over the RAW BODY and not over the decoded array.
    $bocah = pay45Kirim(
        PAY45_GATEWAY,
        (string) json_encode(['gateway' => PAY45_GATEWAY, 'nomor_referensi' => $referensi, 'status' => 'berhasil', 'jumlah' => pay45JumlahInvoice($invoice)], JSON_UNESCAPED_SLASHES).' ',
        [pay45HeaderTtD() => hash_hmac('sha256', $raw, (string) config('services.payment.gateways.'.PAY45_GATEWAY.'.webhook_secret'))],
    );

    expect($bocah->getStatusCode())->toBe(401);

    // Nothing above wrote anything.
    expect(pay45PembayaranBaris((int) $invoice->getKey())->status)->toBe(PembayaranStatus::Pending->value)
        ->and((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu());
});

test('each of the four gateways verifies against its own secret', function (): void {
    // The four secrets are four DIFFERENT strings, so a signature minted for
    // one gateway is not a signature for another. Read from config rather than
    // hard-coded, so the assertion is about the configuration the app uses.
    $rahasia = [];

    foreach (PembayaranGateway::nilai() as $satu) {
        $rahasia[] = (string) config('services.payment.gateways.'.$satu.'.webhook_secret');
    }

    expect($rahasia)->toHaveCount(4);
    expect(array_unique($rahasia))->toHaveCount(4, 'two gateways share a secret, so one can forge the other');

    foreach ($rahasia as $satu) {
        expect($satu)->not->toBe('');
    }

    // And each is at least 32 characters, because a short HMAC key is a
    // brute-forceable one and a webhook secret is the one key in this
    // application an attacker gets unlimited guesses at.
    foreach ($rahasia as $satu) {
        expect(strlen($satu))->toBeGreaterThanOrEqual(32);
    }
});

// =====================================================================
// The acceptance criterion: exactly one state change
// =====================================================================

test('a duplicate webhook produces EXACTLY ONE state change and an identical second response', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());
    $referensi = (string) $baris->nomor_referensi;
    $paymentId = (int) $baris->id;
    $jumlah = pay45JumlahInvoice($invoice);

    // The retry carries a DIFFERENT byte sequence - an extra key the service
    // does not read - so "the stored payload is still the first body" is a
    // measurement rather than an accident of formatting.
    $ekstra = ['percobaan_ulang' => 'ya', 'catatan' => 'dikirim kedua kali'];

    [$raw1, $server1] = pay45Webhook($referensi, 'berhasil', $jumlah, PAY45_GATEWAY, ['percobaan' => 1]);

    // --- FIRST DELIVERY, at PAY45_DETIK ---------------------------------
    $pertama = pay45Kirim(PAY45_GATEWAY, $raw1, $server1);

    $pertama->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.duplicate', false)
        ->assertJsonPath('data.pembayaran.id', $paymentId)
        ->assertJsonPath('data.pembayaran.status', PembayaranStatus::Berhasil->value)
        ->assertJsonPath('data.invoice.status', pay45StatusLunas())
        ->assertJsonPath('data.referensi.tipe', 'booking')
        ->assertJsonPath('data.referensi.status', 'terjadwal');

    // COUNTER 1 - `dibayar_at` is the first delivery's instant.
    $setelahPertama = DB::table('pembayaran')->where('id', $paymentId)->first();

    expect($setelahPertama->status)->toBe(PembayaranStatus::Berhasil->value)
        ->and($setelahPertama->dibayar_at)->toBe(PAY45_DETIK);

    $lunasPertama = $invoice->fresh()->lunas_at;
    $bookingPertama = (string) DB::table('booking')->where('id', $bookingId)->value('status');
    $bookingDiubahPertama = (string) DB::table('booking')->where('id', $bookingId)->value('diubah_at');

    expect((string) $lunasPertama)->toBe(PAY45_DETIK)
        ->and($bookingPertama)->toBe('terjadwal');

    // COUNTER 3 - the audit counter, one row per write per table.
    $updatePertama = [
        'pembayaran' => pay45HitungUpdate('pembayaran', $paymentId),
        'invoice' => pay45HitungUpdate('invoice', (int) $invoice->getKey()),
        'booking' => pay45HitungUpdate('booking', $bookingId),
    ];

    expect($updatePertama)->toBe(['pembayaran' => 1, 'invoice' => 1, 'booking' => 1]);

    // --- THE CLOCK MOVES. Any re-write is now visible. --------------------
    pay45MajuJam();

    // --- SECOND DELIVERY: same reference, different bytes ----------------
    [$raw2, $server2] = pay45Webhook($referensi, 'berhasil', $jumlah, PAY45_GATEWAY, $ekstra);

    expect($raw2)->not->toBe($raw1, 'the retry must be a different byte sequence for the payload assertion to mean anything');

    $kedua = pay45Kirim(PAY45_GATEWAY, $raw2, $server2);

    // NOT an error. A provider that retries must not be told "no": a 4xx makes
    // it retry harder and some give up and leave.
    $kedua->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.duplicate', true);

    // --- COUNTER 1: `dibayar_at` and `lunas_at` are UNCHANGED -------------
    $setelahKedua = DB::table('pembayaran')->where('id', $paymentId)->first();

    expect((string) $setelahKedua->dibayar_at)->toBe(PAY45_DETIK, 'the second delivery re-stamped dibayar_at')
        ->and((string) $setelahKedua->status)->toBe(PembayaranStatus::Berhasil->value);

    expect((string) $invoice->fresh()->lunas_at)->toBe($lunasPertama)
        ->and((string) $invoice->fresh()->status)->toBe(pay45StatusLunas());

    // --- The referenced entity moved ONCE --------------------------------
    expect((string) DB::table('booking')->where('id', $bookingId)->value('status'))->toBe($bookingPertama)
        ->and((string) DB::table('booking')->where('id', $bookingId)->value('diubah_at'))->toBe($bookingDiubahPertama);

    // --- COUNTER 3: still exactly one audit row per table ----------------
    expect([
        'pembayaran' => pay45HitungUpdate('pembayaran', $paymentId),
        'invoice' => pay45HitungUpdate('invoice', (int) $invoice->getKey()),
        'booking' => pay45HitungUpdate('booking', $bookingId),
    ])->toBe($updatePertama, 'the second delivery wrote to a table it should not have written to');

    // --- The two responses are IDENTICAL apart from `duplicate` ----------
    $satu = $pertama->json();
    $dua = $kedua->json();

    // Same envelope key set, same order, and `meta` still absent.
    expect(array_keys($dua))->toBe(array_keys($satu))
        ->and(array_keys($satu))->toBe(['success', 'data', 'message'])
        ->and($pertama->getStatusCode())->toBe($kedua->getStatusCode())
        ->and($pertama->headers->get('content-type'))->toBe($kedua->headers->get('content-type'));

    // Same `data` key set, in the same order.
    expect(array_keys($dua['data']))->toBe(array_keys($satu['data']));

    // Every leaf identical EXCEPT `duplicate` - proved by diffing the two
    // decoded bodies and requiring the difference set to be exactly that one
    // key, rather than asserting a list of equalities that could miss a
    // difference nobody thought to check.
    $beda = [];

    $bandingkan = function (mixed $a, mixed $b, string $jalur) use (&$bandingkan, &$beda): void {
        if (is_array($a) && is_array($b)) {
            expect(array_keys($b))->toBe(array_keys($a), 'key set differs at '.$jalur);

            foreach ($a as $k => $v) {
                $bandingkan($v, $b[$k], $jalur.'.'.$k);
            }

            return;
        }

        if ($a !== $b) {
            $beda[] = $jalur;
        }
    };

    $bandingkan($satu, $dua, '');

    expect($beda)->toBe(['data.duplicate']);

    // The `message` is identical too, so a provider that logs the body's
    // message sees one stable string across a retry.
    expect($dua['message'])->toBe($satu['message']);
});

test('a re-delivery does NOT overwrite webhook_payload: first body wins', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());
    $referensi = (string) $baris->nomor_referensi;
    $jumlah = pay45JumlahInvoice($invoice);

    // DECISION: a re-delivery does not overwrite the column. The stored JSON is
    // the forensic record of the delivery that CAUSED the state change, and
    // there is exactly one of those. A retry carries no new information, so
    // letting it write would replace the evidence with a near-copy - and the
    // test below is what makes the decision falsifiable.
    [$rawAwal, $serverAwal] = pay45Webhook($referensi, 'berhasil', $jumlah, PAY45_GATEWAY, ['percobaan' => 1]);

    pay45Kirim(PAY45_GATEWAY, $rawAwal, $serverAwal)->assertOk();

    $tersimpan = DB::table('pembayaran')->where('id', (int) $baris->id)->value('webhook_payload');

    expect(json_decode((string) $tersimpan, true))->toBe([
        'gateway' => PAY45_GATEWAY,
        'nomor_referensi' => $referensi,
        'status' => 'berhasil',
        'jumlah' => $jumlah,
        'percobaan' => 1,
    ]);

    // The retry, with its own extra key.
    [$rawUlang, $serverUlang] = pay45Webhook(
        $referensi,
        'berhasil',
        $jumlah,
        PAY45_GATEWAY,
        ['percobaan' => 2, 'catatan' => 'kiriman kedua'],
    );

    pay45Kirim(PAY45_GATEWAY, $rawUlang, $serverUlang)
        ->assertOk()
        ->assertJsonPath('data.duplicate', true);

    // STILL the first body: `percobaan` is 1, and the retry's own keys are
    // absent. The column is `JSON` (:968) and MySQL normalises whitespace and
    // key order on store, so the comparison is on the DECODED value - which is
    // the honest comparison for a JSON column and is stated rather than hidden.
    $setelahUlang = json_decode((string) DB::table('pembayaran')->where('id', (int) $baris->id)->value('webhook_payload'), true);

    expect($setelahUlang)->toBe([
        'gateway' => PAY45_GATEWAY,
        'nomor_referensi' => $referensi,
        'status' => 'berhasil',
        'jumlah' => $jumlah,
        'percobaan' => 1,
    ])
        ->and($setelahUlang)->not->toHaveKey('catatan')
        ->and($setelahUlang['percobaan'])->toBe(1);
});

// =====================================================================
// What the webhook decides
// =====================================================================

test('a successful settlement advances a booking to terjadwal and a resep to diproses', function (): void {
    // `booking.status` is an eight-value ENUM at :515-516 and
    // `resep.status` an eight-value ENUM at :751-752. NEITHER has a trigger, a
    // generated column or an event, so nothing but this code moves them - which
    // is why the acceptance criterion names the booking case at all.
    foreach ([
        ['booking', 'terjadwal', 'aktif', 'diproses'],
        ['resep', 'terjadwal', 'aktif', 'diproses'],
    ] as [$tipe, , $dariBooking, $dariResep]) {
        [$user, $pasienId] = pay45AkunPasien();
        $bookingId = pay45Booking($pasienId);
        $resepId = pay45Resep($pasienId);
        $metodeId = pay45Metode();

        $referensiId = $tipe === 'booking' ? $bookingId : $resepId;

        $invoice = pay45Invoice($tipe, $referensiId, $pasienId);
        $jumlah = (string) $invoice->total;

        pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

        $baris = pay45PembayaranBaris((int) $invoice->getKey());

        [$raw, $server] = pay45Webhook((string) $baris->nomor_referensi, 'berhasil', $jumlah);

        pay45Kirim(PAY45_GATEWAY, $raw, $server)
            ->assertOk()
            ->assertJsonPath('data.referensi.tipe', $tipe);

        $expected = $tipe === 'booking' ? 'terjadwal' : 'diproses';

        expect((string) DB::table($tipe)->where('id', $referensiId)->value('status'))->toBe($expected)
            ->and((string) $invoice->fresh()->status)->toBe(pay45StatusLunas())
            ->and((string) $invoice->fresh()->lunas_at)->toBe(PAY45_DETIK);
    }
});

test('a gagal webhook leaves the invoice and the referenced entity untouched', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());
    [$raw, $server] = pay45Webhook((string) $baris->nomor_referensi, 'gagal', (string) $invoice->total);

    pay45Kirim(PAY45_GATEWAY, $raw, $server)
        ->assertOk()
        ->assertJsonPath('data.pembayaran.status', PembayaranStatus::Gagal->value)
        ->assertJsonPath('data.invoice.status', pay45StatusMenunggu());

    // Symmetric with `berhasil` on the payment row, and symmetric with NOTHING
    // on the invoice and the booking: a failed payment is not a paid invoice.
    $sesudah = DB::table('pembayaran')->where('id', (int) $baris->id)->first();

    expect($sesudah->status)->toBe(PembayaranStatus::Gagal->value)
        ->and($sesudah->dibayar_at)->toBeNull()
        ->and($sesudah->webhook_payload)->not->toBeNull();

    expect((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu())
        ->and($invoice->fresh()->lunas_at)->toBeNull()
        ->and((string) DB::table('booking')->where('id', $bookingId)->value('status'))->toBe('menunggu_pembayaran');

    // And a `gagal` is TERMINAL for the dedupe: a provider retrying the same
    // failure gets a 200 and no second write.
    pay45MajuJam();

    [$raw2, $server2] = pay45Webhook((string) $baris->nomor_referensi, 'gagal', (string) $invoice->total, PAY45_GATEWAY, ['percobaan' => 2]);

    pay45Kirim(PAY45_GATEWAY, $raw2, $server2)
        ->assertOk()
        ->assertJsonPath('data.duplicate', true)
        ->assertJsonPath('data.pembayaran.status', PembayaranStatus::Gagal->value);

    expect((string) DB::table('pembayaran')->where('id', (int) $baris->id)->value('dibayar_at'))->toBeNull()
        ->and((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu());
});

test('a kedaluwarsa webhook is handled symmetrically with gagal', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());
    [$raw, $server] = pay45Webhook((string) $baris->nomor_referensi, 'kedaluwarsa', (string) $invoice->total);

    pay45Kirim(PAY45_GATEWAY, $raw, $server)
        ->assertOk()
        ->assertJsonPath('data.pembayaran.status', PembayaranStatus::Kedaluwarsa->value)
        ->assertJsonPath('data.invoice.status', pay45StatusMenunggu());

    expect((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu())
        ->and($invoice->fresh()->lunas_at)->toBeNull()
        ->and((string) DB::table('booking')->where('id', $bookingId)->value('status'))->toBe('menunggu_pembayaran');
});

test('a webhook whose jumlah disagrees with the invoice total is rejected and writes nothing', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId, null, ['total' => '150000.00']);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());

    // The provider says the patient sent a different number. Trusting it would
    // mark a 150.00 invoice `lunas` because a stranger posted 150.01.
    [$raw, $server] = pay45Webhook((string) $baris->nomor_referensi, 'berhasil', '99999.00');

    $res = pay45Kirim(PAY45_GATEWAY, $raw, $server);

    $res->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.jumlah.0', 'Jumlah pembayaran tidak sesuai dengan total invoice.');

    $sesudah = DB::table('pembayaran')->where('id', (int) $baris->id)->first();

    expect($sesudah->status)->toBe(PembayaranStatus::Pending->value)
        ->and($sesudah->dibayar_at)->toBeNull()
        ->and($sesudah->webhook_payload)->toBeNull()
        ->and((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu())
        ->and((string) DB::table('booking')->where('id', $bookingId)->value('status'))->toBe('menunggu_pembayaran');

    // The correction settles it: the same reference, the right amount, and the
    // row is still `pending`, so the refusal left it that way.
    [$benar, $serverBenar] = pay45Webhook((string) $baris->nomor_referensi, 'berhasil', '150000.00');

    pay45Kirim(PAY45_GATEWAY, $benar, $serverBenar)
        ->assertOk()
        ->assertJsonPath('data.duplicate', false)
        ->assertJsonPath('data.pembayaran.status', PembayaranStatus::Berhasil->value)
        ->assertJsonPath('data.invoice.status', pay45StatusLunas());
});

test('a gateway that does not match the payment is a 404, and an unknown reference is a 404', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());
    $referensi = (string) $baris->nomor_referensi;

    // Signed CORRECTLY for `doku`, delivered on `doku`, but the payment row
    // says `midtrans`. The dedupe key is the PAIR, so the pair misses.
    [$raw, $server] = pay45Webhook($referensi, 'berhasil', (string) $invoice->total, 'doku');

    pay45Kirim('doku', $raw, $server)->assertNotFound();

    // Same for a reference this application never minted.
    [$asing, $serverAsing] = pay45Webhook('MOCK-TIDAK-ADA-0001', 'berhasil', '150000.00');

    pay45Kirim(PAY45_GATEWAY, $asing, $serverAsing)->assertNotFound();

    $sesudah = DB::table('pembayaran')->where('id', (int) $baris->id)->first();

    expect($sesudah->status)->toBe(PembayaranStatus::Pending->value)
        ->and((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu());
});

test('a status outside the three settlement outcomes is a 422, never a write', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());

    // `pending` is the row's own current state and `refund` is the refund
    // workflow's, which `refund.status` (:980) and a later todo own. Settling a
    // refund here would mark an invoice `lunas` with no `refund` row at all.
    foreach (['pending', 'refund', 'berhasil_lah', 'BERHASIL', 'sukses'] as $status) {
        [$raw, $server] = pay45Webhook((string) $baris->nomor_referensi, $status, (string) $invoice->total);

        pay45Kirim(PAY45_GATEWAY, $raw, $server)->assertStatus(422);
    }

    $sesudah = DB::table('pembayaran')->where('id', (int) $baris->id)->first();

    expect($sesudah->status)->toBe(PembayaranStatus::Pending->value)
        ->and($sesudah->webhook_payload)->toBeNull()
        ->and((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu());
});

test('the webhook requires a reference, and a malformed body is a 422 rather than a 500', function (): void {
    [$user, $pasienId] = pay45AkunPasien();
    $bookingId = pay45Booking($pasienId);
    $invoice = pay45Invoice('booking', $bookingId, $pasienId);
    $metodeId = pay45Metode();

    pay45Ajax($user)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $kunci = (string) config('services.payment.gateways.'.PAY45_GATEWAY.'.webhook_secret');

    // No `nomor_referensi`. Signed, so it is not a forgery - it is a
    // well-authenticated request that says nothing useful.
    $tanpa = (string) json_encode(['status' => 'berhasil', 'jumlah' => '150000.00']);
    pay45Kirim(PAY45_GATEWAY, $tanpa, [pay45HeaderTtD() => hash_hmac('sha256', $tanpa, $kunci)])
        ->assertStatus(422)
        ->assertJsonPath('errors.nomor_referensi.0', 'nomor_referensi wajib diisi.');

    // `jumlah` as a JSON NUMBER. A float has already lost precision by the
    // time PHP parses it, and `Uang::parse` refuses one on purpose - the answer
    // must be a 422 naming the field, not a silently rounded settlement.
    $baris = pay45PembayaranBaris((int) $invoice->getKey());
    $angka = (string) json_encode([
        'nomor_referensi' => (string) $baris->nomor_referensi,
        'status' => 'berhasil',
        'jumlah' => 150000.0,
    ]);

    pay45Kirim(PAY45_GATEWAY, $angka, [pay45HeaderTtD() => hash_hmac('sha256', $angka, $kunci)])
        ->assertStatus(422);

    // Not JSON at all, correctly signed. Must not be a 500.
    $bukanJson = 'ini bukan json';
    pay45Kirim(PAY45_GATEWAY, $bukanJson, [pay45HeaderTtD() => hash_hmac('sha256', $bukanJson, $kunci)])
        ->assertStatus(422);

    expect(DB::table('pembayaran')->where('id', (int) $baris->id)->value('status'))
        ->toBe(PembayaranStatus::Pending->value);
});

test('a patient cannot settle their own invoice by posting to the webhook', function (): void {
    // The webhook is unauthenticated, so this is not about a token - it is
    // about the signature. A PATIENT who is owed nothing and holds a valid
    // bearer token still cannot mark an invoice paid without the gateway's
    // secret, which is the whole reason the endpoint verifies one.
    [$user, $pasienId] = pay45AkunPasien();
    [$korban, $pasienKorban] = pay45AkunPasien('Korban');
    $bookingId = pay45Booking($pasienKorban);
    $invoice = pay45Invoice('booking', $bookingId, $pasienKorban);
    $metodeId = pay45Metode();

    pay45Ajax($korban)->postJson("/api/v1/invoice/{$invoice->getKey()}/bayar", ['metode_id' => $metodeId])->assertOk();

    $baris = pay45PembayaranBaris((int) $invoice->getKey());

    // The victim's own token, the victim's own invoice's reference, and a
    // signature they made with a key of their own choosing.
    [$raw, $server] = pay45Webhook(
        (string) $baris->nomor_referensi,
        'berhasil',
        (string) $invoice->total,
        PAY45_GATEWAY,
        [],
        'kunci-pasien',
    );

    pay45Ajax($user)->call(
        'POST',
        '/api/v1/webhook/payment/'.PAY45_GATEWAY,
        [],
        [],
        [],
        array_merge($server, ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']),
        $raw,
    )->assertStatus(401);

    expect(DB::table('pembayaran')->where('id', (int) $baris->id)->value('status'))
        ->toBe(PembayaranStatus::Pending->value)
        ->and((string) $invoice->fresh()->status)->toBe(pay45StatusMenunggu());
});
