<?php

declare(strict_types=1);

namespace App\Services\Invoice;

use Illuminate\Validation\ValidationException;

/**
 * The result of evaluating the promo rule set against one purchase.
 *
 * ## Why this is a value object and not an array
 *
 * Three callers need the same evaluation and answer three different questions
 * with it:
 *
 * 1. `InvoiceService::buat()` needs the two money figures and must REFUSE when
 *    the evaluation failed, carrying every failure under its own key.
 * 2. `POST /promo/validasi` needs the same two figures plus the reasons, and
 *    must NOT refuse - a caller asking "would this code work for me?" deserves
 *    an answer rather than an error.
 * 3. The tests need the failure list on its own.
 *
 * One shape serves all three, and `errors()` is DERIVED from `alasan` rather
 * than maintained beside it, so a reason cannot exist in one and not the other.
 *
 * ## `alasan` is a list, and `errors()` is a field-keyed map over it
 *
 * `alasan` is ordered by rule, and the order is the order the 422 body carries:
 * `kode`, `status_aktif`, `jendela_waktu`, `min_transaksi`, `kuota`. Two
 * reasons can share a field, and both cases are real storage rather than
 * contrivance:
 *
 * - An INVERTED window. There is no CHECK on `mulai_at <= selesai_at` anywhere
 *   in the DDL, so `mulai_at > selesai_at` is a legal row, and at any instant
 *   between the two it satisfies "not started" AND "already expired" at once.
 * - A patient who has spent both the total allowance and their own. The two
 *   counters are independent - `promo_redemption` has no unique key on
 *   `(promo_id, pasien_id)` - so one patient can exhaust both.
 *
 * Both survive as a LIST. The envelope maps a field to a list of messages, and
 * collapsing either pair to one string loses a fact a client needs.
 *
 * The object is otherwise inert: no clock read, no database, no randomness.
 * That is what makes it safe to hand to a caller and what lets `validasi`
 * return it without a transaction.
 */
final class PromoHitungan
{
    public const KODE_TIDAK_DITEMUKAN = 'kode_tidak_ditemukan';

    public const KODE_TIDAK_AKTIF = 'tidak_aktif';

    public const KODE_BELUM_MULAI = 'belum_mulai';

    public const KODE_SUDAH_BERAKHIR = 'sudah_berakhir';

    public const KODE_MINIMUM = 'minimum_belum_terpenuhi';

    public const KODE_KUOTA_TOTAL = 'kuota_total_habis';

    public const KODE_KUOTA_USER = 'kuota_user_habis';

    /**
     * The field each reason lands on, which is NOT the column it was read from
     * for two of them: the window is ONE rule read from two columns, and the
     * two quotas are ONE concept read from two columns.
     *
     * Public because the reason CODE is the stable half of the contract and a
     * client may key off it, while the 422 keys off the field name.
     *
     * @var array<string, string>
     */
    public const KOLOM = [
        self::KODE_TIDAK_DITEMUKAN => 'kode',
        self::KODE_TIDAK_AKTIF => 'status_aktif',
        self::KODE_BELUM_MULAI => 'jendela_waktu',
        self::KODE_SUDAH_BERAKHIR => 'jendela_waktu',
        self::KODE_MINIMUM => 'min_transaksi',
        self::KODE_KUOTA_TOTAL => 'kuota',
        self::KODE_KUOTA_USER => 'kuota',
    ];

    /**
     * @param  string  $diskon  the GRANTED discount; `0.00` when the promo is refused
     * @param  string  $biayaPengiriman  the shipping charge AFTER any `gratis_ongkir`
     * @param  list<array{kode: string, kolom: string, pesan: string}>  $alasan
     */
    public function __construct(
        public readonly bool $valid,
        public readonly string $diskon,
        public readonly string $biayaPengiriman,
        public readonly array $alasan,
    ) {}

    /**
     * The result when no promo was named: nothing is discounted, nothing is
     * waived, and the shipping charge passes through untouched.
     */
    public static function tanpaPromo(string $biayaPengiriman): self
    {
        return new self(true, '0.00', $biayaPengiriman, []);
    }

    /**
     * The result for a code that is not in the catalogue at all.
     *
     * A named constructor rather than a hand-built `new self(...)` at the call
     * site, because the two facts involved - the reason CODE and the FIELD it
     * reports on - are coupled by {@see KOLOM} and a caller that spelled the
     * field out itself would be able to drift from the map.
     *
     * The shipping charge PASSES THROUGH rather than being zeroed: a code that
     * does not exist waives nothing, and a preview that quoted a free delivery
     * for a typo would be a price the apply path would not honour.
     */
    public static function kodeTidakDitemukan(string $biayaPengiriman): self
    {
        return new self(false, '0.00', $biayaPengiriman, [[
            'kode' => self::KODE_TIDAK_DITEMUKAN,
            'kolom' => self::KOLOM[self::KODE_TIDAK_DITEMUKAN],
            'pesan' => 'Kode promo tidak ditemukan.',
        ]]);
    }

    public function ditolak(): bool
    {
        return ! $this->valid;
    }

    /**
     * The field a reason code reports on.
     */
    public static function kolomUntuk(string $kode): string
    {
        return self::KOLOM[$kode] ?? 'promo';
    }

    /**
     * The 422 body: a map of field to a NON-EMPTY list of messages.
     *
     * Empty when the evaluation passed, so a caller may throw unconditionally
     * and the envelope is right either way.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->alasan as $satu) {
            $errors[$satu['kolom']][] = $satu['pesan'];
        }

        return $errors;
    }

    /**
     * The 422 for a refused evaluation, and the 200 body for an accepted one.
     *
     * @return never
     */
    public function tolak(): void
    {
        throw ValidationException::withMessages($this->errors());
    }
}
