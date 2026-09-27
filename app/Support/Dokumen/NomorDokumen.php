<?php

declare(strict_types=1);

namespace App\Support\Dokumen;

use Illuminate\Support\Str;

/**
 * Collision-shaped document numbers: `PREFIX` + consultation `Ymd` + 6
 * uppercase alphanumerics.
 *
 * The date inside the number is the CONSULTATION date, not today: the number
 * is what a patient reads out to a clinic receptionist, so it has to name the
 * day they are coming. The 16-character result fits the `VARCHAR(30)` both
 * `booking.nomor_booking` (`:500`) and `invoice.nomor_invoice` (`:938`)
 * declare.
 *
 * The sequence source is injected so the candidates are PREDICTABLE under
 * test: the collision test plants the exact numbers the first attempts will
 * draw, which is the only way to force a genuine MySQL 1062 through a genuine
 * insert. The closure receives the 1-based attempt number and must return the
 * 6-character sequence. The default source is random, so two numbers are never
 * the same by construction and the uniqueness of the column is what the retry
 * loop in `BookingService` exists for.
 */
final class NomorDokumen
{
    public const PREFIX_BOOKING = 'BK';

    public const PREFIX_INVOICE = 'INV';

    private const ACAK = 6;

    private int $percobaan = 0;

    /**
     * @param  (callable(int): string)|null  $urutan
     */
    public function __construct(
        private readonly mixed $urutan = null,
    ) {}

    /**
     * The next candidate number for `$prefix` on the consultation date
     * `$tanggal` (`Y-m-d`; dashes are stripped, so both `Y-m-d` and `Ymd`
     * read the same).
     */
    public function berikutnya(string $prefix, string $tanggal): string
    {
        $this->percobaan++;

        $ymd = (string) preg_replace('/[^0-9]/', '', $tanggal);

        $acak = $this->urutan === null
            ? Str::upper(Str::random(self::ACAK))
            : Str::upper((string) call_user_func($this->urutan, $this->percobaan));

        return $prefix.$ymd.$acak;
    }
}
