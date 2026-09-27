<?php

declare(strict_types=1);

namespace App\Services\Booking;

use RuntimeException;

/**
 * A booking write refused for a slot reason.
 *
 * There is no `App\Exceptions` namespace in this project; domain exceptions
 * live beside their service (`Services\Auth\OtpRejected` is the template), so
 * this one lives beside `BookingService`.
 *
 * A bare `RuntimeException` would render as a sanitised 500
 * (`bootstrap/app.php`), so the controller catches this and converts it to a
 * 422 with `errors()`. The exception is never left to bubble.
 */
final class SlotTakenException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    private function __construct(
        string $message,
        private readonly array $errors,
    ) {
        parent::__construct($message);
    }

    /**
     * The field-keyed payload the 422 envelope carries.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The slot is occupied: the locking current read counted at or above the
     * quota (`count < ($kuota_per_sesi ?? 1)` failed).
     */
    public static function penuh(): self
    {
        $pesan = 'Slot sudah penuh untuk waktu ini.';

        return new self($pesan, ['slot' => [$pesan]]);
    }

    /**
     * The requested start is not among the slots the doctor's schedule
     * publishes for the date: outside every window, on a weekday the doctor
     * does not work, from a corrupt window, or from a schedule row that is not
     * this doctor's. No window arithmetic is re-derived here;
     * `SlotAvailabilityService::getSlotTerbuka()` is the decider.
     */
    public static function tidakDipublikasikan(): self
    {
        $pesan = 'Slot tidak dipublikasikan oleh jadwal dokter.';

        return new self($pesan, ['slot' => [$pesan]]);
    }

    /**
     * The whole day is closed by a `dokter_libur` row. `SlotAvailabilityService`
     * reports it as `alasan = 'libur'` and this service refuses for the same
     * reason.
     */
    public static function tidakTersedia(): self
    {
        $pesan = 'Slot tidak tersedia pada tanggal tersebut.';

        return new self($pesan, ['slot' => [$pesan]]);
    }

    /**
     * The requested date IS today and the derived end is already past
     * (`jam_selesai <= now`), read against the database calendar day.
     */
    public static function sudahLewat(): self
    {
        $pesan = 'Slot sudah lewat.';

        return new self($pesan, ['slot' => [$pesan]]);
    }

    /**
     * The doctor's licence does not cover the consultation date.
     * `StrBerlaku::berlakuPada()` is the decider both services call, and the
     * reference day is the consultation date, never today.
     */
    public static function strKedaluwarsa(): self
    {
        $pesan = 'Surat Tanda Registrasi dokter sudah tidak berlaku pada tanggal kunjungan.';

        return new self($pesan, ['slot' => [$pesan]]);
    }

    /**
     * An instant booking whose doctor default would make `slot_selesai` equal
     * `slot_mulai`. `dokter.durasi_default_menit` is `SMALLINT UNSIGNED NOT
     * NULL`, so a zero is a legal stored value; a `TIME` still needs a positive
     * length, exactly as `SlotAvailabilityService::windowLayak()` refuses a
     * zero-length window.
     */
    public static function durasiNol(): self
    {
        $pesan = 'Durasi konsultasi dokter tidak valid untuk membuat slot.';

        return new self($pesan, ['slot' => [$pesan]]);
    }

    /**
     * The plan's Oracle finding: `nomor_booking VARCHAR(30) NOT NULL UNIQUE`
     * means a collision under concurrency is an unhandled 500 unless it is
     * retried. Three genuine duplicate-key collisions still leave nothing
     * written, because the retry loop runs inside the transaction.
     */
    public static function nomorHabis(): self
    {
        $pesan = 'Nomor booking tidak dapat dibuat. Silakan coba lagi.';

        return new self($pesan, ['nomor_booking' => [$pesan]]);
    }
}
