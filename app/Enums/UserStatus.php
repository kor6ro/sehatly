<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The full vocabulary of `users.status`.
 *
 * Migration 000090 widens the column's ENUM from the four values the DDL
 * shipped to these seven, keeping it an ENUM so `telemedicine_test.sql:140`
 * and `docs/enums.json` remain the single declaration of its members - both
 * `sehatly:verify-schema` and `sehatly:enums` compare the live column against
 * that file. This enum is the single source of truth for every allowed value.
 *
 * | Value                  | Who sets it          | Meaning |
 * | ---------------------- | -------------------- | ------- |
 * | `pending_verifikasi`   | Register (pasien)    | OTP not yet verified |
 * | `menunggu_persetujuan` | Register (marketing) | Waiting for admin approval |
 * | `diundang`             | Admin                | Invitation sent, password not set |
 * | `aktif`                | System               | Fully active |
 * | `ditolak`              | Admin                | Marketing registration rejected |
 * | `nonaktif`             | Admin                | Suspended (history preserved) |
 * | `ditangguhkan`         | System               | Temporarily blocked |
 */
enum UserStatus: string
{
    case PendingVerifikasi   = 'pending_verifikasi';
    case MenungguPersetujuan = 'menunggu_persetujuan';
    case Diundang            = 'diundang';
    case Aktif               = 'aktif';
    case Ditolak             = 'ditolak';
    case Nonaktif            = 'nonaktif';
    case Ditangguhkan        = 'ditangguhkan';

    /**
     * Status yang TIDAK boleh login.
     * AuthController::login() checks this before issuing an OTP.
     */
    public function bolehLogin(): bool
    {
        return match ($this) {
            self::Aktif, self::PendingVerifikasi => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingVerifikasi   => 'Menunggu verifikasi OTP',
            self::MenungguPersetujuan => 'Menunggu persetujuan',
            self::Diundang            => 'Diundang',
            self::Aktif               => 'Aktif',
            self::Ditolak             => 'Ditolak',
            self::Nonaktif            => 'Nonaktif',
            self::Ditangguhkan        => 'Ditangguhkan',
        };
    }
}
