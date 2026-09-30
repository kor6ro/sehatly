<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PembayaranGateway;
use App\Enums\PembayaranStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\InvoiceService;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/*
|--------------------------------------------------------------------------
| Todo 45 - shared fixtures for the payment and webhook test files
|--------------------------------------------------------------------------
|
| `require_once`d by each of them, so a single file is runnable on its own.
| The prefix is `pay45` because Pest loads every test file into ONE process and
| `inv44*`, `bkc*`, `bku*`, `slot*` and `rx39*` are already taken.
|
| ## Why the clock is frozen and then ADVANCED
|
| The duplicate-delivery acceptance criterion is "exactly one state change",
| which cannot be observed on a table whose only stamp is a `TIMESTAMP` written
| with `ON UPDATE CURRENT_TIMESTAMP`. Every row would carry the same instant for
| two deliveries in the same second, and "still the same value" would prove
| nothing. So the clock is frozen at {@see PAY45_DETIK}, the first delivery is
| made, the clock is then ADVANCED by an hour, and the second delivery is made.
|
| Any re-application therefore writes a DIFFERENT `dibayar_at` / `lunas_at` /
| `diubah_at`, so every one of the three assertions below turns the criterion
| into a measurement rather than a tautology:
|
| - `pembayaran.dibayar_at` is still the FIRST delivery's instant;
| - `invoice.lunas_at` is still the FIRST delivery's instant;
| - `audit_log` holds exactly ONE `update` row for each of the three tables.
|
| The audit counter is the third one because it is the only assertion that is
| not a timestamp: `pembayaran` declares no `diubah_at` at all
| (telemedicine_test.sql:958-973 - `dibuat_at` at :969 is the only stamp), so
| the audit log is the project's own record of "this row was written again".
|
*/

/** The frozen instant every test starts from: a UTC INSTANT. */
const PAY45_DETIK = '2026-03-11 10:00:00';

/** How far the clock moves between the first and the second delivery. */
const PAY45_MAJU_DETIK = 3600;

/** The `master_metode_pembayaran.kode` prefix; `PAY45` + 8 = 12 of VARCHAR(30). */
const PAY45_PANJANG_KODE = 12;

/** The one gateway this todo binds, and the one every happy-path test uses. */
const PAY45_GATEWAY = 'midtrans';

/** The header the signature travels in. */
const PAY45_HEADER_TTD = 'X-Payment-Signature';

function pay45KunciJam(): void
{
    Carbon::setTestNow(Carbon::parse(PAY45_DETIK, 'UTC'));
}

/**
 * Move the frozen clock forward by `$detik` seconds.
 *
 * Used to make a second delivery's write VISIBLE: without it, a re-application
 * inside the same frozen instant would leave every timestamp identical and the
 * "exactly one state change" assertion would be satisfied by a no-op that was
 * in fact two writes.
 */
function pay45MajuJam(int $detik = PAY45_MAJU_DETIK): void
{
    Carbon::setTestNow(Carbon::parse(PAY45_DETIK, 'UTC')->addSeconds($detik));
}

function pay45LepasJam(): void
{
    Carbon::setTestNow();
}

/**
 * A `users` row. The NOT NULL columns with no default are `uuid` (:134),
 * `nama_lengkap` (:135), `no_telepon` (:137) and `kata_sandi_hash` (:138).
 */
function pay45User(string $nama, string $tipe = 'pasien', ?string $role = 'pasien'): User
{
    $id = pay45Catat('users', (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]));

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row. `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are NOT NULL with no default.
 */
function pay45Pasien(int $userId, array $ubah = []): int
{
    return pay45Catat('pasien', (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Pembayaran No. 1, Jakarta',
    ], $ubah)));
}

/** A patient account plus its `pasien` row, as the pair a controller works with. */
function pay45AkunPasien(string $nama = 'Pasien Pembayaran'): array
{
    $user = pay45User($nama);
    $pasienId = pay45Pasien((int) $user->getKey());

    return [$user, $pasienId];
}

/**
 * A request with NO credentials at all, for the 401 assertions.
 *
 * TWO things have to be undone, and missing either makes the "anonymous" probe
 * pass for the wrong reason:
 *
 * 1. {@see pay45Ajax()} installs the bearer token as a DEFAULT header on the
 *    TestCase, and a default header rides along on every later request;
 * 2. the auth manager is a SINGLETON and its guard caches the resolved user, so
 *    the previous request's `User` is still attached even with no header at all.
 *    `forgetGuards()` is what todo 44's helper does for the same reason.
 *
 * Without both, the probe is authenticated as whoever the previous request was
 * and answers 403 - which reads as "the guard refused them" and is in fact "the
 * guard never ran".
 */
function pay45TanpaAuth(): TestCase
{
    app('auth')->forgetGuards();

    return test()->flushHeaders();
}

/**
 * The test case, already carrying `$user`'s bearer token.
 *
 * Returns the TestCase itself rather than a header array, because
 * `withHeader()` returns `$this` and every use here is
 * `pay45Ajax($user)->postJson(...)`. Returning a bare array would make that read
 * `pay45Ajax($user)->postJson(...)` against an array, which is the kind of
 * failure that reads like a broken test rather than a broken helper.
 */
function pay45Ajax(User $user): TestCase
{
    app('auth')->forgetGuards();

    $token = $user->createToken('pay45', ['*'], now()->addHour())->plainTextToken;

    return test()->withHeader('Authorization', 'Bearer '.$token);
}

/**
 * A `master_metode_pembayaran` row.
 *
 * `kode` (:927) and `nama` (:928) are NOT NULL with no default; `tipe` (:929)
 * is a nine-value ENUM; `biaya_admin_flat` (:931) and `biaya_admin_persen`
 * (:932) both default to 0 and are NOT NULL, so a method with no admin fee is
 * legal and needs no explicit zero.
 *
 * @param  array<string, mixed>  $ubah
 */
function pay45Metode(array $ubah = []): int
{
    return pay45Catat('master_metode_pembayaran', (int) DB::table('master_metode_pembayaran')->insertGetId(array_merge([
        'kode' => 'PAY45-'.Str::upper(Str::random(8)),
        'nama' => 'Metode Uji Pembayaran',
        'tipe' => 'va_bank',
        'penyedia' => 'Bank Uji',
    ], $ubah)));
}

/** A `dokter` row: `user_id`, `tipe`, `nomor_str`, `str_berlaku_sampai` are NOT NULL. */
function pay45Dokter(int $userId, array $ubah = []): int
{
    return pay45Catat('dokter', (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-PAY45-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah)));
}

/**
 * A `booking` row, the `referensi_tipe = 'booking'` source.
 *
 * `pasien_id` (:501), `dokter_id` (:503), the two slot `TIME` columns (:508,
 * :509) and `dibuat_oleh_user_id` (:519) are NOT NULL. `status` (:515-516) is
 * an eight-value ENUM defaulting to `menunggu_pembayaran`, which is the state
 * the settlement has to move it out of.
 *
 * @param  array<string, mixed>  $ubah
 */
function pay45Booking(int $pasienId, array $ubah = []): int
{
    $dokterUserId = pay45User('Dokter Uji Pembayaran', 'dokter', 'dokter')->getKey();
    $dokterId = pay45Dokter($dokterUserId);

    return pay45Catat('booking', (int) DB::table('booking')->insertGetId(array_merge([
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'dibuat_oleh_user_id' => $dokterUserId,
        'nomor_booking' => 'BPAY45'.Str::upper(Str::random(8)),
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => '2026-03-11',
        'slot_mulai' => '09:00:00',
        'slot_selesai' => '09:20:00',
        'status' => 'menunggu_pembayaran',
    ], $ubah)));
}

/**
 * A `resep` row, the `referensi_tipe = 'resep'` source. `pasien_id` is :747.
 *
 * @param  array<string, mixed>  $ubah
 */
function pay45Resep(int $pasienId, array $ubah = []): int
{
    $dokterUserId = pay45User('Dokter Uji Resep Bayar', 'dokter', 'dokter')->getKey();
    $dokterId = pay45Dokter($dokterUserId);

    return pay45Catat('resep', (int) DB::table('resep')->insertGetId(array_merge([
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'nomor_resep' => 'RXPAY45'.Str::upper(Str::random(8)),
        'qr_token' => (string) Str::uuid(),
        'tipe' => 'digital',
        'status' => 'aktif',
        'tanggal_resep' => '2026-03-11',
        'berlaku_sampai' => '2026-03-18',
    ], $ubah)));
}

/**
 * A `pesanan_obat` row, the `referensi_tipe = 'pesanan_obat'` source.
 *
 * @param  array<string, mixed>  $ubah
 */
function pay45PesananObat(int $pasienId, array $ubah = []): int
{
    $apotekId = pay45Catat('faskes', (int) DB::table('faskes')->insertGetId([
        'nama' => 'Apotek Uji Pembayaran',
        'tipe' => 'apotek',
        'alamat' => 'Jl. Uji Apotek Bayar No. 2, Jakarta',
        'status_aktif' => 1,
    ]));

    return pay45Catat('pesanan_obat', (int) DB::table('pesanan_obat')->insertGetId(array_merge([
        'nomor_pesanan' => 'POPAY45'.Str::upper(Str::random(8)),
        'pasien_id' => $pasienId,
        'apotek_id' => $apotekId,
        'tipe' => 'resep_dokter',
        'status' => 'menunggu_pembayaran',
        'alamat_kirim' => 'Jl. Uji Pembayaran No. 1, Jakarta',
        'subtotal' => '120000.00',
        'biaya_kirim' => '20000.00',
        'total' => '140000.00',
    ], $ubah)));
}

/**
 * A REAL invoice, minted through the production service rather than inserted.
 *
 * The point is the assertion {@see pay45JumlahInvoice()} exists to support: the
 * payment amount is `invoice.total`, and `invoice.total` already carries
 * `biaya_admin`. A hand-inserted invoice would not prove that the fee the
 * patient is charged is the fee `InvoiceService` computed, only that two
 * hand-written numbers agreed.
 *
 * @param  array<string, mixed>  $ubah
 */
function pay45Invoice(
    string $referensiTipe,
    int $referensiId,
    int $pasienId,
    ?int $metodeId = null,
    array $ubah = [],
): Invoice {
    $invoice = app(InvoiceService::class)->buat(
        referensiTipe: $referensiTipe,
        referensiId: $referensiId,
        pasienId: $pasienId,
        lines: [pay45Baris()],
        metodeId: $metodeId,
    );

    if ($ubah !== []) {
        $invoice->forceFill($ubah)->save();
    }

    pay45Catat('invoice', (int) $invoice->getKey());

    return $invoice;
}

/** One invoice line as the service takes it: a DECIMAL STRING, a positive count. */
function pay45Baris(string $hargaSatuan = '150000.00', int $jumlah = 1): array
{
    return ['harga_satuan' => $hargaSatuan, 'jumlah' => $jumlah];
}

/** The amount a `pembayaran` row for `$invoice` must carry, as a decimal string. */
function pay45JumlahInvoice(Invoice $invoice): string
{
    return (string) $invoice->total;
}

/**
 * The `pembayaran` row the payment endpoint created for `$invoice`, or null.
 */
function pay45PembayaranBaris(int $invoiceId): ?object
{
    return DB::table('pembayaran')->where('invoice_id', $invoiceId)->orderBy('id')->first();
}

/**
 * Every `pembayaran` row for `$invoice`, oldest first.
 *
 * @return list<object>
 */
function pay45PembayaranSemua(int $invoiceId): array
{
    return DB::table('pembayaran')->where('invoice_id', $invoiceId)->orderBy('id')->get()->all();
}

/**
 * The raw webhook body this todo's mock gateway speaks, and its signature.
 *
 * ## The signature is computed HERE, in the test, not by the implementation
 *
 * The whole claim under test is "HMAC-SHA256 over the raw request body, keyed
 * by the per-gateway secret in `config/services.php`, compared with
 * `hash_equals`". If the test asked the implementation to sign, it would pass
 * against ANY scheme the implementation chose, including no scheme at all. So
 * the HMAC is computed here from `config()` directly, which also means the test
 * fails if the key is read from anywhere else.
 *
 * `json_encode` is called with the same flags `AuditLogWriter` uses, so the
 * bytes signed here are exactly the bytes put on the wire.
 *
 * @param  array<string, mixed>  $tambahan  extra keys, stored verbatim and
 *                                          ignored by the service
 * @return array{0: string, 1: array<string, string>}
 */
function pay45Webhook(
    string $nomorReferensi,
    string $status = 'berhasil',
    ?string $jumlah = null,
    string $gateway = PAY45_GATEWAY,
    array $tambahan = [],
    ?string $secret = null,
    ?string $signature = null,
): array {
    $body = array_merge([
        'gateway' => $gateway,
        'nomor_referensi' => $nomorReferensi,
        'status' => $status,
        'jumlah' => $jumlah ?? '150000.00',
    ], $tambahan);

    $raw = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $kunci = $secret ?? (string) config('services.payment.gateways.'.$gateway.'.webhook_secret');

    $ttd = $signature ?? hash_hmac('sha256', $raw, $kunci);

    return [$raw, [pay45HeaderTtD() => $ttd]];
}

/** The header name, upper-cased, as Symfony spells an `HTTP_` server entry. */
function pay45HeaderTtD(): string
{
    return 'HTTP_'.strtoupper(str_replace('-', '_', PAY45_HEADER_TTD));
}

/**
 * POST a signed webhook to `POST /api/v1/webhook/payment/{gateway}`.
 *
 * `call()` with a raw `$content` rather than `postJson()`, because the
 * signature is over the BYTES and `postJson()` re-encodes the array - so a
 * test that signed `json_encode($array)` and posted `json_encode($array)`
 * through two different encoders would be testing an accident of formatting.
 *
 * @param  array<string, string>  $server
 */
function pay45Kirim(string $gateway, string $raw, array $server): TestResponse
{
    return test()->call(
        'POST',
        '/api/v1/webhook/payment/'.$gateway,
        [],
        [],
        [],
        array_merge($server, ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']),
        $raw,
    );
}

/**
 * Count `audit_log` `update` rows for one table and one record.
 *
 * `AuditLogWriter` writes `aksi = 'update'` (:1121), `tabel_target` (:1122) and
 * `record_id` (:1123) for every Eloquent update on an audited model, and
 * `Pembayaran`, `Invoice` and `Booking` are all inside the derived scope. So
 * this is the project's own "this row was written again" counter, and it needs
 * no column of its own.
 */
function pay45HitungUpdate(string $tabel, int $recordId): int
{
    return DB::table('audit_log')
        ->where('aksi', 'update')
        ->where('tabel_target', $tabel)
        ->where('record_id', (string) $recordId)
        ->count();
}

/**
 * Run `$aksi` and return the `$tipe` it threw, or fail the test naming both.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $tipe
 * @return T
 */
function pay45Tangkap(callable $aksi, string $tipe): Throwable
{
    try {
        $hasil = $aksi();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($tipe, 'Expected ['.$tipe.'] but got ['.get_class($e).']: '.$e->getMessage());

        /** @var T $e */
        return $e;
    }

    Assert::fail('Expected ['.$tipe.'] but nothing was thrown. Returned: '.var_export($hasil, true));
}

// =====================================================================
// DDL assertions
// =====================================================================

function pay45Spec(): SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

/**
 * @return list<string>
 */
function pay45Enum(string $table, string $column): array
{
    $type = pay45Spec()->table($table)->columns[$column]->type;

    preg_match_all("/'([^']+)'/", (string) $type, $matches);

    return $matches[1];
}

function pay45DdlLine(int $n): ?string
{
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    return $lines[$n - 1] ?? null;
}

/**
 * The lines of one `CREATE TABLE` block, from the opening statement to the
 * closing `ENGINE` line INCLUSIVE.
 *
 * @return list<string>
 */
function pay45Blok(string $table): array
{
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    $mulai = null;

    foreach ($lines as $i => $line) {
        if (str_starts_with(trim($line), 'CREATE TABLE '.$table.' ')) {
            $mulai = $i;

            break;
        }
    }

    Assert::assertNotNull($mulai, "telemedicine_test.sql has no CREATE TABLE {$table}");

    $blok = [];

    for ($i = $mulai; $i < count($lines); $i++) {
        $blok[] = $lines[$i];

        if (str_contains($lines[$i], ') ENGINE=')) {
            return $blok;
        }
    }

    Assert::fail("telemedicine_test.sql: the {$table} block has no closing ENGINE line");
}

/**
 * Assert `telemedicine_test.sql:$line` contains `$token`, so a citation is
 * proved against the file rather than quoted from the plan.
 */
function pay45AssertLine(int $line, string $token): void
{
    $actual = pay45DdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual.'] and does not contain ['.$token.']',
    );
}

/**
 * Assert `telemedicine_test.sql:$line` does NOT contain `$token`.
 *
 * The negative assertion is the load-bearing half for a citation about a
 * MISSING thing - `nomor_referensi` having no unique key, `pembayaran` having
 * no index on it. A positive search cannot prove an absence.
 */
function pay45AssertLineLacks(int $line, string $token): void
{
    $actual = pay45DdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringNotContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual.'] and unexpectedly contains ['.$token.']',
    );
}

// =====================================================================
// Fixture collection and teardown
// =====================================================================

/**
 * Every row the fixtures create, recorded as they are created.
 *
 * A hand-listed teardown is a second list of the fixtures, and the two drift.
 * The collector IS the fixture list.
 *
 * @var array<string, list<int>>
 */
function pay45Catat(string $tabel, int $id): int
{
    $GLOBALS['pay45_dibuat'][$tabel][] = $id;

    return $id;
}

/**
 * @return array<string, list<int>>
 */
function pay45Dibuat(): array
{
    return (array) ($GLOBALS['pay45_dibuat'] ?? []);
}

function pay45Bersihkan(): void
{
    $GLOBALS['pay45_dibuat'] = [];
}

/**
 * Every table whose rows these tests create, children before parents.
 *
 * `pembayaran` is NOT in this list and that is deliberate: the payment row is
 * written by the SERVICE under test, not by a fixture, so the collector never
 * sees its id. Listing it here with empty ids would silently do nothing, and
 * the `invoice` delete below it would then fail with MySQL 1451 - which is
 * exactly what happened before {@see pay45BersihkanSemua()} removed payments by
 * `invoice_id` instead. The two tables the service writes are therefore reached
 * through what the collector CAN see: every payment names an invoice this test
 * created.
 *
 * @return list<string>
 */
function pay45Tabel(): array
{
    return [
        'promo_redemption',
        'invoice',
        'booking',
        'resep',
        'pesanan_obat',
        'pesanan_obat_tracking',
        'apotek_stok',
        'dokter_jadwal',
        'dokter',
        'faskes',
        'user_roles',
        'user_devices',
        'user_refresh_tokens',
        'user_otp',
        'pasien',
        'master_promo',
        'master_metode_pembayaran',
        'users',
    ];
}

/**
 * Delete every fixture this test created, children first.
 *
 * ## `pembayaran` and `refund` go FIRST, by `invoice_id`
 *
 * `pembayaran.invoice_id` is foreign-keyed to `invoice(id)` (:960, :970) and
 * `refund.pembayaran_id` to `pembayaran(id)` (:976, :982), so neither can be
 * reached by a collected id - the rows are written by the service. Naming the
 * invoices does reach them, and doing it in this order is what keeps the
 * `invoice` delete from failing with 1451.
 *
 * ## `audit_log` is emptied LAST and unconditionally
 *
 * The observer writes a row for every audited model this file touches, and
 * `record_id` is a BARE column (:1123) with no foreign key - so nothing
 * cascades those rows and nothing would ever remove them. Leaving them behind
 * would make a later test's `pay45HitungUpdate()` counter read rows it did not
 * write, and that counter is the acceptance criterion, so the cleanup has to be
 * airtight rather than best-effort.
 */
function pay45BersihkanSemua(): void
{
    $dibuat = pay45Dibuat();

    $invoiceIds = $dibuat['invoice'] ?? [];

    if ($invoiceIds !== []) {
        $paymentIds = DB::table('pembayaran')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();

        if ($paymentIds !== []) {
            DB::table('refund')->whereIn('pembayaran_id', $paymentIds)->delete();
        }

        DB::table('pembayaran')->whereIn('invoice_id', $invoiceIds)->delete();
    }

    foreach (pay45Tabel() as $tabel) {
        $ids = $dibuat[$tabel] ?? [];

        if ($ids !== []) {
            DB::table($tabel)->whereIn('id', $ids)->delete();
        }
    }

    DB::table('user_roles')->whereIn('user_id', $dibuat['users'] ?? [])->delete();
    DB::table('audit_log')->delete();

    pay45Bersihkan();
}

beforeEach(function (): void {
    pay45Bersihkan();
});

afterEach(function (): void {
    pay45LepasJam();
    pay45BersihkanSemua();
});

// Re-exported so a test file can name an enum member without a second import.
function pay45StatusBerhasil(): string
{
    return PembayaranStatus::Berhasil->value;
}

function pay45StatusLunas(): string
{
    return InvoiceStatus::Lunas->value;
}

function pay45StatusMenunggu(): string
{
    return InvoiceStatus::MenungguPembayaran->value;
}

/**
 * Is `$kandidat` one of the four `pembayaran.gateway` ENUM members?
 */
function pay45GatewayDikenal(string $kandidat): bool
{
    return in_array($kandidat, PembayaranGateway::nilai(), true);
}
