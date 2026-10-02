<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The seven `notifikasi.tipe` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:1041`, a SINGLE-line ENUM:
 *
 * ```
 * tipe ENUM('booking','pembayaran','resep','chat','lab','promo','sistem') NOT NULL,
 * ```
 *
 * ## Two of the seven have no producer yet, and that is recorded rather than filled
 *
 * `lab` and `promo` exist because the schema names them. Todo 47 creates
 * notifications for the five events the modules it can see actually produce -
 * booking created and cancelled, payment settled, prescription ready, and a new
 * chat message - and refuses to invent a sixth and seventh. A notification type
 * with no producer is a value a client must handle and no service can emit, and
 * that is worse than the column not having it: the tests assert the list against
 * the parsed DDL, so dropping a value fails the suite rather than producing a
 * client that can never receive one.
 *
 * ## The same reasoning as {@see PersetujuanPdpJenis}
 *
 * The list is asserted against the parsed DDL with `toBe` on every test run, order
 * included, because a value differing from the schema by one letter still looks
 * right in a diff and would answer a 500 at the INSERT - MySQL rejects an ENUM
 * value outside its list, and the client would see a server fault for a typo in
 * the application.
 */
enum NotifikasiTipe: string
{
    case Booking = 'booking';
    case Pembayaran = 'pembayaran';
    case Resep = 'resep';
    case Chat = 'chat';
    case Lab = 'lab';
    case Promo = 'promo';
    case Sistem = 'sistem';

    /**
     * Every value, in the DDL's declaration order.
     *
     * @return list<string>
     */
    public static function nilai(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The values `NotificationService` can actually write today.
     *
     * A SUB-SET of {@see nilai()}, and the reason it is a subset rather than the
     * whole list is in the class docblock. It exists so the service's own
     * vocabulary is a checked thing rather than four string literals that a
     * reader has to verify against `:1041` by eye.
     *
     * `Sistem` joined the subset in F04: `NotificationService::ulasanDiminta()`
     * is a real producer of a service notice (a review invitation after a
     * consultation completes). `lab` and `promo` remain without one.
     *
     * @return list<string>
     */
    public static function nilaiYangDipakai(): array
    {
        return [
            self::Booking->value,
            self::Pembayaran->value,
            self::Resep->value,
            self::Chat->value,
            self::Sistem->value,
        ];
    }
}
