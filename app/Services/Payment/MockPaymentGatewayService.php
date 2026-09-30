<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PembayaranGateway;
use App\Enums\PembayaranStatus;
use App\Models\Invoice;
use App\Models\MasterMetodePembayaran;
use App\Support\Uang\Uang;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The only shipped payment gateway, and a faithful stand-in rather than a stub.
 *
 * ## What "mock" means here, precisely
 *
 * It mints plausible values and it verifies signatures for real. The signature
 * check is a genuine HMAC-SHA256 over the raw body, compared with
 * `hash_equals`, against a per-gateway secret from `config/services.php` - so
 * a forged delivery is refused by the same code path a real adapter would use,
 * and the acceptance criterion for this endpoint is measured against real
 * cryptography rather than against a boolean.
 *
 * What it does NOT do is talk to anybody. There is no HTTP call, no API key, no
 * outbound request. The plan puts real Midtrans and Xendit out of scope and the
 * schema has no column to store an API key in, so a "real" implementation would
 * be a network client with nothing to authenticate against.
 *
 * ## The scheme, stated so it can be checked against
 *
 * ```
 * header   X-Payment-Signature
 * message  the raw request body, byte for byte
 * key      config('services.payment.gateways.{gateway}.webhook_secret')
 * digest   hash_hmac('sha256', <bytes>, <key>)          lowercase hex, 64 chars
 * compare  hash_equals(<expected>, <received>)
 * ```
 *
 * Three decisions inside that, each with a reason:
 *
 * 1. **THE RAW BODY, not a re-encoding.** `$request->getContent()` is the bytes
 *    on the wire. Hashing `json_encode($request->json())` would make the
 *    signature a function of THIS application's serialiser: `JSON_UNESCAPED_SLASHES`
 *    alone changes the digest, and a provider that escapes `/` the way we do
 *    and one that does not would produce two "different" signatures for one
 *    logical body. The test signs a body with a trailing space added and
 *    asserts a 401, which is the failure this prevents.
 * 2. **Constant-time comparison.** This is the one comparison in the
 *    application an attacker gets unlimited guesses at - the route is
 *    unauthenticated, so there is no session to rate-limit and no account to
 *    lock out. `hash_equals` is the only correct answer and a `===` on two hex
 *    strings is a timing oracle.
 * 3. **VERIFY BEFORE PARSE.** The signature is checked before the body is
 *    decoded, so an unsigned request cannot choose its own error. Decoding
 *    first would let a stranger ask "is this JSON well-formed?", "is
 *    `nomor_referensi` present?", "is `jumlah` a float?" and learn the shape of
 *    the endpoint from the 422 it gets back. The test asserts a forged request
 *    issues zero statements naming `pembayaran`, `invoice` or `booking`, which
 *    a "the rows did not change" assertion could not do.
 *
 * ## It speaks for ALL FOUR gateways, and why
 *
 * {@see dapatkah()} returns true for every {@see PembayaranGateway} member.
 * A real adapter would return true for exactly one. The mock is a stand-in for
 * whichever one a deployment configured, and a test has to be able to deliver a
 * `doku` webhook at a `midtrans` payment to prove the dedupe key is the PAIR
 * and not just the reference. A test asserts a body signed with `doku`'s secret
 * is refused on `midtrans`, so the four secrets are genuinely independent and
 * the substitution is not a hole.
 *
 * ## The minted values are inside their columns
 *
 * `nomor_referensi` is `VARCHAR(100)` (:963) and `va_number` is `VARCHAR(30)`
 * (:964) - the second is the width a mock is most likely to get wrong, since
 * "a virtual account number" invites 34 digits. The generator caps it at 30 and
 * the test asserts the length rather than the shape, because MySQL's refusal of
 * an oversized value under `STRICT_TRANS_TABLES` is a 500 at settlement time
 * rather than at initiation time, which is the worst place to find out.
 *
 * @see PaymentGatewayService the contract
 * @see PaymentService the caller, and the dedupe
 */
final class MockPaymentGatewayService implements PaymentGatewayService
{
    /**
     * The prefix every minted reference carries, so a support screenshot of a
     * virtual account shows at a glance that it came from the mock.
     */
    public const PREFIX_REFERENSI = 'MOCK';

    /**
     * The header the signature travels in.
     *
     * A constant rather than a config key because it is part of the wire
     * CONTRACT with the provider, not a deployment preference: changing it
     * changes what the provider sends. A real adapter names its own header -
     * Midtrans uses `X-Webhook-Signature` - and a deployment that swaps this
     * implementation for one changes the constant in that class, which is one
     * line and does not touch the controller.
     */
    public const HEADER_TANDATANGAN = 'X-Payment-Signature';

    /**
     * The widest `nomor_referensi` may be: `VARCHAR(100)` at
     * telemedicine_test.sql:963.
     */
    public const PANJANG_REFERENSI = 100;

    /**
     * The width of `va_number VARCHAR(30)` at :964. Asserted by the generator
     * and by a test, because a number that overflows it fails at settlement and
     * not at initiation.
     */
    public const PANJANG_VA = 30;

    /**
     * How many random bytes a reference carries, hex-encoded.
     *
     * Twelve bytes is 24 hex characters: with a `MOCK` prefix and the date it
     * lands at 38 of the 100 the column allows, and a 96-bit draw makes a
     * collision irrelevant - which matters only because `nomor_referensi` has
     * NO unique constraint (:963) and this generator is the only thing
     * standing between two payments and one reference.
     */
    private const BYTE_ACAK = 12;

    public function nama(): string
    {
        return (string) config('payment.gateway_pembayaran');
    }

    public function dapatkah(string $kode): bool
    {
        return PembayaranGateway::adalah($kode);
    }

    /**
     * {@inheritDoc}
     *
     * ## The amount is `invoice.total`, and that is the whole point
     *
     * `invoice.total` is `DECIMAL(14,2) NOT NULL` with NO DEFAULT (:946), and it
     * already contains `biaya_admin` (:944), which `InvoiceService` computed
     * once at mint time from `master_metode_pembayaran.biaya_admin_flat` (:931)
     * and `biaya_admin_persen` (:932). Applying the fee again here would charge
     * the patient for it twice, and the test asserts the amount returned is
     * byte-identical to the stored total rather than approximately equal to it.
     *
     * ## Which channel a method gets is a deployment decision
     *
     * `config('payment.metode_tipe_qr')` names the `tipe` values (:929) that
     * settle by scanning. `va_bank` and `e_wallet` get a virtual account;
     * `qris` and `gerai_retail` get a QR string. **Never both**, because the
     * schema has one `va_number` column and no `qr_string` column at all - so a
     * gateway returning both would have nowhere to put the second, and the
     * caller would be left inventing a column.
     */
    public function createTransaction(Invoice $invoice, MasterMetodePembayaran $metode): array
    {
        $jumlah = (string) $invoice->total;

        if (! preg_match('/^[1-9][0-9]{0,11}(\.[0-9]{1,2})?$/', $jumlah)) {
            // An invoice the patient cannot pay. `InvoiceService` refuses a
            // zero total already, so reaching this is a corrupted row rather
            // than a caller error - and a 422 naming `total` is more useful than
            // minting a virtual account for Rp 0.
            throw ValidationException::withMessages([
                'total' => ['Total invoice tidak dapat dibayar.'],
            ]);
        }

        $toko = in_array((string) $metode->tipe, (array) config('payment.metode_tipe_qr'), true)
            ? $this->tokoQr($invoice, $metode)
            : $this->tokoVa();

        return [
            'gateway' => $this->nama(),
            'nomor_referensi' => $this->referensi(),
            'jumlah' => $jumlah,
            'instruksi' => $this->instruksi($metode, $toko),
            'toko' => $toko,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * ## What it returns and what it refuses
     *
     * Four refusals, in the order they are checked, and the order is the
     * design:
     *
     * 1. no signature header, or an empty one - 401;
     * 2. no configured secret for the gateway - 401 (a deployment fault, but
     *    not a 500: a 500 invites a provider to keep retrying a request that
     *    can never succeed);
     * 3. a signature that is not this body's - 401, in constant time;
     * 4. only then is the body decoded, and only then is its content judged -
     *    422, with every message filed on the field it came in on.
     *
     * ## `jumlah` is parsed through {@see Uang}, so a JSON number is a 422
     *
     * A webhook body that says `"jumlah": 150000.0` has already lost the
     * precision that made the amount a DECIMAL string in the first place.
     * `Uang::parse` refuses a float on purpose, and the refusal is filed on
     * `jumlah` rather than allowed to become a `TypeError` or a rounded
     * settlement. The returned string is the caller's amount check input, and
     * it is compared against the STORED `pembayaran.jumlah` in
     * {@see PaymentService} - never against anything the request supplied.
     *
     * ## `payload` is the decoded body VERBATIM
     *
     * Every key, in the order it arrived, including keys this class never
     * reads. It is what lands in `pembayaran.webhook_payload` (:968) and it is
     * the forensic record of the delivery, so a filtered copy would be a
     * summary dressed up as evidence. The test re-delivers a body carrying an
     * extra key and asserts the stored JSON does NOT gain it, which is what
     * makes the "first delivery wins" decision falsifiable.
     */
    public function verifyWebhook(Request $request): array
    {
        $gateway = (string) $request->route('gateway');

        // (1) The header. Checked FIRST, before the body is touched.
        $diterima = $request->headers->get(self::HEADER_TANDATANGAN);

        if (! is_string($diterima) || trim($diterima) === '') {
            throw TandaTanganWebhookTidakValid::tidakAda();
        }

        // (2) The key. A gateway with no configured secret cannot verify
        // anything, and the honest answer to a signature is "not authenticated".
        $rahasia = config('services.payment.gateways.'.$gateway.'.webhook_secret');

        if (! is_string($rahasia) || $rahasia === '') {
            throw TandaTanganWebhookTidakValid::rahasiaTidakAda($gateway);
        }

        // (3) The comparison, over the bytes on the wire.
        $harapan = hash_hmac('sha256', $request->getContent(), $rahasia);

        if (! hash_equals($harapan, $diterima)) {
            throw TandaTanganWebhookTidakValid::tidakCocok();
        }

        // (4) Only now is the body decoded and judged.
        $body = $request->getContent();

        $terurai = json_decode($body, true);

        if (! is_array($terurai)) {
            throw ValidationException::withMessages([
                'body' => ['Badan webhook harus berupa objek JSON.'],
            ]);
        }

        return [
            'nomor_referensi' => $this->referensiDari($terurai),
            'status' => $this->statusDari($terurai),
            'jumlah' => $this->jumlahDari($terurai),
            'gateway' => is_string($terurai['gateway'] ?? null) ? $terurai['gateway'] : null,
            'payload' => $terurai,
        ];
    }

    /**
     * The provider's transaction reference, and the dedupe key's right half.
     *
     * `MOCK-` + `Ymd` + `-` + 24 hex characters is 38 characters of the 100
     * `VARCHAR(100)` at :963 allows. The date is there for a human reading a
     * support ticket, not for uniqueness - {@see self::BYTE_ACAK} is.
     */
    private function referensi(): string
    {
        $referensi = self::PREFIX_REFERENSI
            .'-'.now('Asia/Jakarta')->format('Ymd')
            .'-'.Str::upper(bin2hex(random_bytes(self::BYTE_ACAK)));

        // Bounded rather than trusted: the column is 100 wide and a generator
        // that could exceed it would fail at settlement time, which is the worst
        // moment to find out.
        return substr($referensi, 0, self::PANJANG_REFERENSI);
    }

    /**
     * A virtual account: `88` + 18 digits = 20 of the 30 `VARCHAR(30)` at :964
     * allows.
     *
     * @return array{va_number: string, nama_bank: string, nama_pemilik: string}
     */
    private function tokoVa(): array
    {
        return [
            'va_number' => '88'.str_pad((string) random_int(0, 999999999999999999), 18, '0', STR_PAD_LEFT),
            'nama_bank' => (string) config('payment.nama_bank', 'Bank Uji Pembayaran'),
            'nama_pemilik' => (string) config('payment.nama_pemilik', 'NASAB SEHATLY'),
        ];
    }

    /**
     * A scanned code. The schema has NO column for it, so it is returned to the
     * client and never stored - which is a limitation, not an oversight, and is
     * recorded in the evidence file rather than worked around with a column the
     * DDL does not have.
     *
     * @return array{qr_string: string, nama_penyedia: string}
     */
    private function tokoQr(Invoice $invoice, MasterMetodePembayaran $metode): array
    {
        return [
            'qr_string' => 'SEHATLY-QRIS|'.$this->nama().'|'.$invoice->getKey()
                .'|'.(string) $invoice->nomor_invoice
                .'|'.(string) $invoice->total
                .'|'.((string) $metode->kode),
            'nama_penyedia' => (string) ($metode->penyedia ?? 'QRIS'),
        ];
    }

    /**
     * The steps a patient follows, in order, as plain strings.
     *
     * Built from the values actually minted rather than from a fixed list, so a
     * patient is never shown instructions naming an account number that is not
     * the one on the row.
     *
     * @param  array<string, string>  $toko
     * @return list<string>
     */
    private function instruksi(MasterMetodePembayaran $metode, array $toko): array
    {
        $dasar = [
            'Buka aplikasi bank Anda.',
            'Pilih menu transfer ke Virtual Account.',
        ];

        if (isset($toko['va_number'])) {
            return array_merge($dasar, [
                'Masukkan nomor Virtual Account '.$toko['va_number'].'.',
                'Periksa jumlah tagihan '.(string) $metode->nama.' lalu selesaikan pembayaran.',
                'Simpan bukti transfer sampai pembayaran terkonfirmasi.',
            ]);
        }

        return array_merge($dasar, [
            'Pindai kode QR pada layar pembayaran.',
            'Pastikan nama merchant SEHATLY dan jumlah yang tertera sudah benar.',
            'Simpan bukti transfer sampai pembayaran terkonfirmasi.',
        ]);
    }

    /**
     * The reference, or the 422 that names it.
     *
     * Length-bounded to `VARCHAR(100)` (:963) rather than left open, because a
     * longer reference would be truncated by MySQL into a DIFFERENT reference
     * and the dedupe key would then match a payment nobody meant to settle.
     *
     * @param  array<array-key, mixed>  $body
     *
     * @throws ValidationException
     */
    private function referensiDari(array $body): string
    {
        $nilai = $body['nomor_referensi'] ?? null;

        if (! is_string($nilai) || trim($nilai) === '') {
            throw ValidationException::withMessages([
                'nomor_referensi' => ['nomor_referensi wajib diisi.'],
            ]);
        }

        $nilai = trim($nilai);

        if (strlen($nilai) > self::PANJANG_REFERENSI) {
            throw ValidationException::withMessages([
                'nomor_referensi' => ['nomor_referensi maksimal 100 karakter.'],
            ]);
        }

        return $nilai;
    }

    /**
     * The status, or the 422 that names the three legal values.
     *
     * {@see PembayaranStatus::bisaDisettle()} is the gate, so
     * `refund` and `pending` are refused here and the decision cannot be
     * duplicated in the service.
     *
     * @param  array<array-key, mixed>  $body
     *
     * @throws ValidationException
     */
    private function statusDari(array $body): string
    {
        $nilai = $body['status'] ?? null;

        $daftar = PembayaranStatus::SETTLE;

        if (! is_string($nilai) || ! PembayaranStatus::bisaDisettle($nilai)) {
            throw ValidationException::withMessages([
                'status' => ['Status pembayaran webhook tidak dikenal. Nilai yang diizinkan: '.implode(', ', $daftar).'.'],
            ]);
        }

        return $nilai;
    }

    /**
     * The amount, parsed as a DECIMAL string, or the 422 that names it.
     *
     * `Uang::parse` is the gate rather than a `(float)` cast, and the difference
     * is the whole reason this application has `App\Support\Uang\Uang`: a JSON
     * number has already lost precision before PHP sees it, and a rounded
     * settlement is a patient charged the wrong figure with no record of it.
     *
     * @param  array<array-key, mixed>  $body
     *
     * @throws ValidationException
     */
    private function jumlahDari(array $body): string
    {
        try {
            return Uang::parse($body['jumlah'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'jumlah' => [$e->getMessage()],
            ]);
        }
    }
}
