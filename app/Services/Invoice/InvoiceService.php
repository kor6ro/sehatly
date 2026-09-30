<?php

declare(strict_types=1);

namespace App\Services\Invoice;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Konsultasi;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPromo;
use App\Models\Pasien;
use App\Models\PesananObat;
use App\Models\Resep;
use App\Support\Dokumen\NomorDokumen;
use App\Support\Uang\Uang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The one entry point every invoice in this application is minted through.
 *
 * ## `referensi_id` is a BARE column, so there is no relation to lean on
 *
 * `invoice.referensi_id` is `BIGINT UNSIGNED NOT NULL COMMENT 'Polimorfik'`
 * (telemedicine_test.sql:941) and `invoice.referensi_tipe` is a six-value ENUM
 * (:940). The ONLY foreign key on `invoice` is `pasien_id` (:953) - verified by
 * reading the whole table body, and asserted by a test that walks the declared
 * relations off the `Invoice` class and finds exactly `pasien()`,
 * `pembayaran()` and `promoRedemption()`.
 *
 * So there is NO Eloquent relation for the polymorphic reference, and this
 * service does not invent one. A `belongsTo` to any of the four reachable
 * tables would be a guess about a schema fact, and a polymorphic `morphTo`
 * would need a `referensi_type` column that does not exist. Instead the
 * reference is resolved through {@see SUMBER_REFERENSI}, a whitelist keyed by
 * the ENUM value, and everything else - "does this type exist", "is it in
 * scope", "whose row is it" - is decided explicitly and refuses rather than
 * guessing.
 *
 * ## Referential integrity is THIS SERVICE's job, and the two out-of-scope types say why
 *
 * Two of the six ENUM members - `lab_permintaan` and `home_care` - are legal
 * values naming tables this module does not reach. They are refused with a
 * message that says they are out of SCOPE, which is a different problem from an
 * invalid value and needs a different fix. Nothing is written, and no table
 * outside the four is touched.
 *
 * ## Ownership: another patient's row is a 404, never a 403
 *
 * The referenced row's `pasien_id` is compared against the caller's. A mismatch
 * is a `ModelNotFoundException` scoped to that patient's query, so a 403 would
 * confirm the row exists - a cross-tenant existence oracle - and a row that does
 * not exist at all answers the same 404 for the same reason. `pasien_id` is
 * `NOT NULL` on all four sources (:501, :539, :747, :801), so the check is total
 * rather than best-effort.
 *
 * ## The duplicate guard is an application-level guard, and this is its limit
 *
 * There is no uniqueness on `(referensi_tipe, referensi_id)`. `INDEX idx_ref`
 * (:955) makes the existence check a fast index probe but is NOT unique - the
 * tests assert `UNIQUE` is absent from that line. So the check is a
 * TOCTOU-guarded read inside the transaction: two concurrent `buat()` calls for
 * the same reference can both read "no invoice" and both insert.
 *
 * The schema forbids adding the index that would close it, so the choice is to
 * refuse the SECOND call loudly and say so here rather than pretend it is
 * impossible. It is the same limitation the plan records for
 * `pembayaran.nomor_referensi` in todo 45 and for `promo_redemption` in
 * {@see PromoService}. The consequence is stated rather than hidden: this is a
 * guard, not a guarantee, and a caller retrying after a duplicate-`nomor`
 * failure is safe while two simultaneous creates are not.
 *
 * ## `invoice.total` has NO default, and that decides the whole write
 *
 * `subtotal`, `diskon`, `biaya_admin` and `biaya_pengiriman` all declare
 * `NOT NULL DEFAULT 0` (:942-945). `total` declares `NOT NULL` with **no
 * default** (:946). So a service that trusted any of the defaults would produce
 * a row MySQL refuses outright with error 1364 under
 * `STRICT_TRANS_TABLES` - which the test suite proves by issuing exactly that
 * INSERT and asserting the driver code.
 *
 * The decision: **every one of the five money columns is written explicitly,
 * and `status` is written explicitly too even though it does default to
 * `menunggu_pembayaran` (:948).** The DDL default is real and `BookingService`
 * relies on it, but relying on it here would mean a column's default is what
 * decides an invoice's state, and a change to that default in a later migration
 * would silently change what an unpaid invoice is. An empty `lines` array is
 * REFUSED rather than producing a `0.00` invoice: the column would accept it,
 * and a patient handed a zero-value invoice is a defect no constraint catches.
 *
 * ## The money, and the seven parameters
 *
 * `subtotal` is the sum of the lines. `diskon` is the promo's grant. `biaya_admin`
 * is the method's flat fee plus a percentage of **`subtotal - diskon`**, not of
 * `subtotal`: an admin fee is a charge on what the patient actually pays, and
 * charging it on the pre-discount figure is how a "2.5% admin fee" quietly
 * becomes 2.5% of more. `biaya_pengiriman` is the shipping charge, which
 * `gratis_ongkir` zeroes. `total` is the sum of the four.
 *
 * Every amount is a decimal STRING, because every amount is a JSON string on
 * the wire and a `float` has already lost precision by the time PHP parses it.
 * See {@see Uang} for the parsing, the half-up rounding and the capacity guard.
 *
 * The parameters after `$lines` are named rather than positional in every
 * caller, because a positional `null, 'PROMO20', '20000.00'` is unreadable and
 * `null` is a legal value for two of them.
 */
class InvoiceService
{
    /**
     * Asia/Jakarta, for the two things that are LOCAL wall-clock rather than
     * instants: the promo window (`master_promo.mulai_at` / `selesai_at`) and
     * the date inside `nomor_invoice`.
     *
     * `config('app.timezone')` is `'UTC'` (config/app.php:68) and stays there
     * - the plan's todo 51 keeps it and asserts it. What todo 51 also requires
     * is that these two columns NOT be read as UTC, because a promo would
     * activate and expire seven hours off. The zone is a constant rather than a
     * config key because WIB has no daylight saving: a named zone resolves to a
     * fixed +07:00 forever, so a lookup would buy nothing.
     */
    public const ZONA_WALL_CLOCK = 'Asia/Jakarta';

    /**
     * The four `referensi_tipe` values this module can reach, each naming the
     * model whose table the id points into.
     *
     * PUBLIC and asserted by a test, because the map is the whole of the
     * polymorphic resolution: if a fifth entry appeared, the DDL citation tests
     * would not notice and the "two of six are out of scope" claim would become
     * false without anything going red.
     *
     * Sorted as the four names appear in this file rather than as the ENUM
     * orders them (`booking, konsultasi, resep, pesanan_obat`), so the map reads
     * alphabetically and a diff on it is a diff on the set, not on the order.
     *
     * @var array<string, class-string<Model>>
     */
    public const SUMBER_REFERENSI = [
        'booking' => Booking::class,
        'konsultasi' => Konsultasi::class,
        'pesanan_obat' => PesananObat::class,
        'resep' => Resep::class,
    ];

    /**
     * The two ENUM members that are legal values naming tables outside Modules
     * 1-5, mapped to the message that says so.
     *
     * Kept as a map rather than an `in_array` check so the refusal can NAME the
     * value, which is the difference between a caller learning what to do and a
     * caller learning only that something was wrong.
     *
     * @var array<string, string>
     */
    private const DI_LUAR_LINGKUP = [
        'lab_permintaan' => 'Jenis referensi [lab_permintaan] valid belum diimplementasikan pada lingkup ini.',
        'home_care' => 'Jenis referensi [home_care] valid belum diimplementasikan pada lingkup ini.',
    ];

    /**
     * All six `referensi_tipe` members, in the DDL's own order (:940), so the
     * "not known" message names them the way the schema lists them.
     *
     * Written out rather than sorted: sorting would put `home_care` second and
     * `resep` last, which is alphabetical rather than schema order, and a
     * caller reading the message should meet the values in the order the
     * database declares them.
     */
    private const SEMUA_TIPE_REFERENSI = [
        'booking',
        'konsultasi',
        'resep',
        'pesanan_obat',
        'lab_permintaan',
        'home_care',
    ];

    /**
     * The state a new invoice is born in.
     *
     * `invoice.status` is a seven-value ENUM `NOT NULL DEFAULT
     * 'menunggu_pembayaran'` (:947-948) and the DDL default is right, but the
     * value is written explicitly anyway - see the class docblock. Named so the
     * one string that decides an unpaid invoice's state has one occurrence.
     */
    public const STATUS_BARU = 'menunggu_pembayaran';

    /**
     * How many candidates a genuine `nomor_invoice` collision may consume
     * before the write gives up.
     *
     * `nomor_invoice` is `VARCHAR(30) NOT NULL UNIQUE` (:938) and
     * {@see NomorDokumen} draws six random alphanumerics, so a collision is
     * rare but real, and an unhandled one is a 1062 - a sanitised 500. Three
     * attempts at 1-in-831,600 per draw is the same budget `BookingService` uses
     * for `nomor_booking`, and the retry runs INSIDE the transaction so a spent
     * attempt leaves no orphan row.
     */
    private const PERCOBAAN_NOMOR_MAKS = 3;

    public function __construct(
        private readonly NomorDokumen $nomor,
        private readonly PromoService $promo,
    ) {}

    /**
     * Mint one invoice for one referenced record.
     *
     * @param  string  $referensiTipe  one of `invoice.referensi_tipe`'s six ENUM
     *                                 members (telemedicine_test.sql:940)
     * @param  int  $referensiId  the id in that table; `BIGINT UNSIGNED` (:941),
     *                            so zero and negatives are refused rather than
     *                            queried
     * @param  int  $pasienId  the patient the invoice belongs to, and the one the
     *                         referenced row must already belong to
     * @param  list<array{harga_satuan?: mixed, jumlah?: mixed}>  $lines  the
     *                                                                    purchase. `harga_satuan` is a DECIMAL string and
     *                                                                    `jumlah` a positive integer.
     * @param  int|null  $metodeId  the payment method whose admin fee applies.
     *                              `null` means none, and the fee is 0.00.
     * @param  string|null  $kodePromo  a promo to apply. `null` means none.
     * @param  mixed  $biayaPengiriman  the shipping charge, a DECIMAL string.
     *                                  `0.00` is legal and means nothing to
     *                                  ship for.
     *
     * `mixed` rather than `string` for the last two, and that is deliberate: a
     * JSON number arriving where a money string belongs is a CONTRACT problem
     * and the answer has to be a 422 naming the field, not a `TypeError` from
     * PHP's own signature check. `Uang::parse()` is the gate, and it refuses
     * floats, booleans, nulls and arrays with a message a caller can act on. A
     * typed `string` would have turned every one of those into a 500. The line
     * prices inside `$lines` are untyped for the same reason.
     *
     * @throws ValidationException on a bad reference type, a non-positive
     *                             reference id, malformed money, an empty
     *                             `lines`, an unknown or inactive method, a
     *                             duplicate `(referensi_tipe, referensi_id)`, or
     *                             a promo that fails any rule
     * @throws ModelNotFoundException when the referenced row does not exist or
     *                                belongs to another patient
     */
    public function buat(
        string $referensiTipe,
        int $referensiId,
        int $pasienId,
        array $lines,
        ?int $metodeId = null,
        ?string $kodePromo = null,
        mixed $biayaPengiriman = '0.00',
    ): Invoice {
        $model = $this->sumberUntuk($referensiTipe);
        $pengiriman = $this->uang($biayaPengiriman, 'biaya_pengiriman', true);
        $subtotal = $this->subtotalDari($lines);

        // The whole thing is one transaction: the reference read, the duplicate
        // check, the promo lock, the redemption insert and the invoice insert
        // either all land or none does. A refused promo must leave NO invoice,
        // because the duplicate guard reads `invoice` - a row written before the
        // refusal would make the retry fail with the wrong error.
        return DB::transaction(function () use ($referensiTipe, $referensiId, $pasienId, $model, $subtotal, $pengiriman, $metodeId, $kodePromo): Invoice {
            $this->pastikanMilik($model, $referensiId, $pasienId);
            $this->tolakDuplikat($referensiTipe, $referensiId);

            $promo = $kodePromo === null ? null : $this->cariPromo($kodePromo);

            // The promotion is evaluated and consumed BEFORE the invoice row
            // exists, because `promo_redemption.invoice_id` is NOT NULL (:1004)
            // and needs a real id. So the invoice is written first with a
            // provisional total, then the promo is applied, then the total is
            // corrected - all inside this one transaction, so a failure anywhere
            // rolls the whole thing back and nothing is left half-priced.
            $invoice = $this->tulis($referensiTipe, $referensiId, $pasienId, $subtotal, $pengiriman, $metodeId, [
                'diskon' => '0.00',
                'biaya_admin' => $this->biayaAdmin($metodeId, $subtotal, '0.00'),
                'biaya_pengiriman' => $pengiriman,
            ]);

            $diskon = '0.00';
            $pengirimanAkhir = $pengiriman;

            if ($promo !== null) {
                // FIRST statement of the quota decision: the `master_promo` row
                // lock. See PromoService for why the ordering is the whole
                // correctness argument.
                $hitungan = $this->promo->terapkan(
                    $promo,
                    $subtotal,
                    $pengiriman,
                    $pasienId,
                    (int) $invoice->getKey(),
                );

                $diskon = $hitungan->diskon;
                $pengirimanAkhir = $hitungan->biayaPengiriman;
            }

            $admin = $this->biayaAdmin($metodeId, $subtotal, $diskon);

            $total = Uang::jumlah(Uang::kurang($subtotal, $diskon), $admin, $pengirimanAkhir);

            if (Uang::lt($total, '0.00')) {
                throw ValidationException::withMessages([
                    'lines' => ['Total invoice tidak boleh negatif.'],
                ]);
            }

            if (Uang::gt($total, Uang::BATAS_DECIMAL_14_2)) {
                throw ValidationException::withMessages([
                    'lines' => ['Total invoice melebihi kapasitas kolom DECIMAL(14,2).'],
                ]);
            }

            $invoice->diskon = $diskon;
            $invoice->biaya_admin = $admin;
            $invoice->biaya_pengiriman = $pengirimanAkhir;
            $invoice->total = $total;
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * The model for `$referensiTipe`, or the right 422.
     *
     * Three outcomes, three messages, because they are three different problems
     * with three different fixes and a caller who is told only "invalid" learns
     * nothing:
     *
     * 1. not a member of the ENUM at all - the six legal values are named;
     * 2. a member that is out of scope - the value is named and the gap is named;
     * 3. in scope - the model comes back.
     *
     * @return class-string<Model>
     *
     * @throws ValidationException
     */
    private function sumberUntuk(string $referensiTipe): string
    {
        $sumber = self::SUMBER_REFERENSI[$referensiTipe] ?? null;

        if ($sumber !== null) {
            return $sumber;
        }

        if (isset(self::DI_LUAR_LINGKUP[$referensiTipe])) {
            throw ValidationException::withMessages([
                'referensi_tipe' => [self::DI_LUAR_LINGKUP[$referensiTipe]],
            ]);
        }

        $semua = self::SEMUA_TIPE_REFERENSI;

        throw ValidationException::withMessages([
            'referensi_tipe' => [
                'Jenis referensi tidak dikenal. Nilai yang diizinkan: '.implode(', ', $semua).'.',
            ],
        ]);
    }

    /**
     * The referenced row, and the 404 that is the same 404 for a row that does
     * not exist.
     *
     * `referensi_id` is `BIGINT UNSIGNED` (:941), so zero and negatives cannot
     * name a row and are refused before a query is built.
     *
     * @param  class-string<Model>  $model
     *
     * @throws ValidationException|ModelNotFoundException
     */
    private function pastikanMilik(string $model, int $referensiId, int $pasienId): void
    {
        if ($referensiId < 1) {
            throw ValidationException::withMessages([
                'referensi_id' => ['referensi_id harus lebih besar dari nol.'],
            ]);
        }

        $ada = $model::query()
            ->whereKey($referensiId)
            ->where('pasien_id', $pasienId)
            ->exists();

        if ($ada) {
            return;
        }

        // Re-thrown with the id so the 404 names what was asked for without
        // saying whether the row exists for somebody else. `bootstrap/app.php`
        // replaces the message with 'Resource not found.', so the body discloses
        // nothing; the model and the id travel for the log.
        throw (new ModelNotFoundException)->setModel($model, [$referensiId]);
    }

    /**
     * The duplicate guard, and the transaction it lives in.
     *
     * The probe is `(referensi_tipe, referensi_id)`, which is exactly
     * `INDEX idx_ref` (:955) - an index PROBE, not a scan. That index is not
     * unique, which the tests assert against the DDL line, so this is an
     * application-level guard with a known race rather than a database
     * guarantee. It is stated in the class docblock rather than glossed.
     *
     * @throws ValidationException
     */
    private function tolakDuplikat(string $referensiTipe, int $referensiId): void
    {
        $ada = Invoice::query()
            ->where('referensi_tipe', $referensiTipe)
            ->where('referensi_id', $referensiId)
            ->first();

        if ($ada === null) {
            return;
        }

        throw ValidationException::withMessages([
            'referensi_id' => [
                "Invoice untuk referensi ini sudah dibuat dengan nomor {$ada->nomor_invoice}.",
            ],
        ]);
    }

    /**
     * The promo row, or the 422 that says the code is not in the catalogue.
     *
     * The lookup is on `kode`, which is `VARCHAR(30) NOT NULL UNIQUE` (:987), so
     * it is an index probe and a case the column's own collation decides. This
     * service does not case-fold first: it matches the code the way the column
     * stores it, and the test records which of the two outcomes the server
     * gives rather than assuming one.
     *
     * @throws ValidationException
     */
    private function cariPromo(string $kode): MasterPromo
    {
        $kode = trim($kode);

        if ($kode === '') {
            throw ValidationException::withMessages([
                'kode' => ['Kode promo wajib diisi.'],
            ]);
        }

        $promo = MasterPromo::query()->where('kode', $kode)->first();

        if ($promo === null) {
            throw ValidationException::withMessages([
                'kode' => ['Kode promo tidak ditemukan.'],
            ]);
        }

        return $promo;
    }

    /**
     * The sum of the line items, or the 422s naming the line that broke.
     *
     * A `lines` key rather than `lines.0.harga_satuan` would hide WHICH line is
     * wrong, and a caller sending eleven items does not want to count them.
     *
     * @param  array<array-key, mixed>  $lines
     *
     * @throws ValidationException
     */
    private function subtotalDari(array $lines): string
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => ['Invoice minimal memiliki satu baris.'],
            ]);
        }

        $subtotal = '0.00';

        foreach (array_values($lines) as $nomor => $baris) {
            if (! is_array($baris)) {
                throw ValidationException::withMessages([
                    "lines.{$nomor}" => ['Baris invoice harus berupa objek.'],
                ]);
            }

            $harga = $this->uang(
                $baris['harga_satuan'] ?? null,
                "lines.{$nomor}.harga_satuan",
                false,
                Uang::BATAS_DECIMAL_12_2
            );

            $jumlah = $this->jumlah($baris['jumlah'] ?? null, "lines.{$nomor}.jumlah");

            $subtotal = Uang::jumlah($subtotal, Uang::kali($harga, $jumlah));
        }

        if (Uang::gt($subtotal, Uang::BATAS_DECIMAL_14_2)) {
            throw ValidationException::withMessages([
                'lines' => ['Subtotal invoice melebihi kapasitas kolom DECIMAL(14,2).'],
            ]);
        }

        return $subtotal;
    }

    /**
     * A money value from the API boundary, or the 422 on the field it came in.
     *
     * @throws ValidationException
     */
    private function uang(mixed $nilai, string $kolom, bool $bolehNol, string $batas = Uang::BATAS_DECIMAL_14_2): string
    {
        try {
            return Uang::parse($nilai, $bolehNol, $batas);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([$kolom => [$e->getMessage()]]);
        }
    }

    /**
     * A line quantity: a positive INTEGER, never a string with a decimal in it
     * and never a float.
     *
     * `DECIMAL` would be the wrong column for a count and a float would let
     * `1.5` through as "one and a bit of a tablet", so the accept list is
     * `int` plus a digit string and nothing else.
     *
     * @throws ValidationException
     */
    private function jumlah(mixed $nilai, string $kolom): int
    {
        if (is_int($nilai)) {
            $jumlah = $nilai;
        } elseif (is_string($nilai) && preg_match('/^[1-9][0-9]{0,4}$/', $nilai) === 1) {
            // Five digits, bounded at 65535 below: `resep_item.jumlah` is
            // `SMALLINT UNSIGNED` (telemedicine_test.sql:774), and an invoice
            // line is no larger than the line it came from.
            $jumlah = (int) $nilai;
        } else {
            throw ValidationException::withMessages([
                $kolom => ['jumlah harus berupa bilangan bulat positif.'],
            ]);
        }

        if ($jumlah < 1 || $jumlah > 65535) {
            throw ValidationException::withMessages([
                $kolom => ['jumlah harus berupa bilangan bulat positif.'],
            ]);
        }

        return $jumlah;
    }

    /**
     * The admin fee: the method's flat charge plus a percentage of
     * `subtotal - diskon`.
     *
     * The percentage base is the POST-DISCOUNT amount, and that is the decision:
     * an admin fee is a charge on what the patient actually pays, so charging it
     * on the pre-discount figure makes "2.5% admin fee" quietly mean 2.5% of
     * more whenever a promo is in play - the fee rises exactly when the patient
     * gets a better deal. The test asserts both the discounted and the
     * undiscounted figure so the movement is visible.
     *
     * An unknown id and an inactive row are both refused on `metode_id`:
     * `status_aktif` is `TINYINT(1) NOT NULL DEFAULT 1` (:933), so an inactive
     * method is a real storable state rather than a fiction, and a method nobody
     * can pay with should not be selectable at checkout.
     *
     * @throws ValidationException
     */
    private function biayaAdmin(?int $metodeId, string $subtotal, string $diskon): string
    {
        if ($metodeId === null) {
            return '0.00';
        }

        $metode = MasterMetodePembayaran::query()
            ->whereKey($metodeId)
            ->where('status_aktif', 1)
            ->first();

        if ($metode === null) {
            throw ValidationException::withMessages([
                'metode_id' => ['Metode pembayaran tidak ditemukan atau tidak aktif.'],
            ]);
        }

        $dasar = Uang::kurang($subtotal, $diskon);
        $persen = (string) $metode->biaya_admin_persen;

        $persentase = Uang::nol($persen)
            ? '0.00'
            : Uang::persenDari($dasar, $persen);

        return Uang::jumlah((string) $metode->biaya_admin_flat, $persentase);
    }

    /**
     * Write the invoice, retrying a genuine unique-key collision on
     * `nomor_invoice`.
     *
     * The provisional money values are passed in so the FIRST insert already
     * carries every one of the five columns: `total` has no default (:946), so
     * a first attempt that omitted it would fail with error 1364 rather than
     * with the collision the retry exists for, and the retry would be testing
     * the wrong thing.
     *
     * @param  array{diskon: string, biaya_admin: string, biaya_pengiriman: string}  $uang
     *
     * @throws ValidationException when every candidate number collides
     */
    private function tulis(
        string $referensiTipe,
        int $referensiId,
        int $pasienId,
        string $subtotal,
        string $pengiriman,
        ?int $metodeId,
        array $uang,
    ): Invoice {
        $tanggal = Carbon::now(self::ZONA_WALL_CLOCK)->format('Y-m-d');

        for ($percobaan = 1; $percobaan <= self::PERCOBAAN_NOMOR_MAKS; $percobaan++) {
            $invoice = new Invoice;
            $invoice->nomor_invoice = $this->nomor->berikutnya(NomorDokumen::PREFIX_INVOICE, $tanggal);
            $invoice->pasien_id = $pasienId;
            $invoice->referensi_tipe = $referensiTipe;
            $invoice->referensi_id = $referensiId;
            $invoice->subtotal = $subtotal;
            $invoice->diskon = $uang['diskon'];
            $invoice->biaya_admin = $uang['biaya_admin'];
            $invoice->biaya_pengiriman = $uang['biaya_pengiriman'];
            $invoice->total = Uang::jumlah(
                Uang::kurang($subtotal, $uang['diskon']),
                $uang['biaya_admin'],
                $uang['biaya_pengiriman']
            );
            // Written, not defaulted. See the class docblock on `status`.
            $invoice->status = self::STATUS_BARU;

            try {
                $invoice->save();

                return $invoice;
            } catch (UniqueConstraintViolationException $e) {
                if ($percobaan === self::PERCOBAAN_NOMOR_MAKS) {
                    break;
                }

                // The only unique key on `invoice` other than the primary is
                // `nomor_invoice` (:938) and `pasien_id` is not unique, so a
                // violation here IS the number. Rethrowing would be a 500; the
                // loop is inside the transaction, so a spent attempt has left
                // nothing behind.
            }
        }

        throw ValidationException::withMessages([
            'nomor_invoice' => ['Nomor invoice tidak dapat dibuat. Silakan coba lagi.'],
        ]);
    }
}
