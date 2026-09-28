<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three `konsultasi.tipe` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:541`:
 *
 * ```
 * tipe ENUM('chat','video_call','telepon') NOT NULL,
 * ```
 *
 * ## Why this is NOT the same vocabulary as `booking.tipe_layanan`
 *
 * `booking.tipe_layanan` (`telemedicine_test.sql:506`) is a FOUR-value ENUM:
 * `chat`, `video_call`, `kunjungan_klinik`, `home_visit`. The two columns have
 * the same name and two different value sets, sharing only `chat` and
 * `video_call`. That is the project's standing token-audit trap - two
 * same-named ENUMs spelled differently - and it is why this file exists rather
 * than a `Rule::in([...])` literal, and why `KonsultasiTest` asserts this list
 * against the DDL with `toBe` for both columns so a value can never be copied
 * from one into the other.
 *
 * ## The two values with no `booking` counterpart
 *
 * `telepon` is a telephone konsultasi and has no `booking.tipe_layanan`
 * equivalent, so it is reachable only through the instant "Tanya Dokter" form.
 * Conversely `kunjungan_klinik` and `home_visit` are IN-PERSON visits: a
 * `konsultasi` row is a telemedicine session (`konsultasi_chat` hangs off it, and
 * `room_id` is documented at `:544` as a video SDK room), so a booking-backed
 * konsultasi whose service type is either of those is refused rather than
 * coerced. `KonsultasiService` states that refusal in the Indonesian message a
 * patient actually sees.
 */
enum KonsultasiTipe: string
{
    case Chat = 'chat';
    case VideoCall = 'video_call';
    case Telepon = 'telepon';

    /**
     * Every value, in the DDL's declaration order.
     *
     * @return list<string>
     */
    public static function nilai(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
