<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Pdp\PdpConsent;

/**
 * The five `persetujuan_pdp.jenis` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:1137-1138`, a WRAPPED ENUM whose value list continues onto
 * the following physical line:
 *
 * ```
 * jenis ENUM('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis',
 *            'pemasaran','komunikasi_tindak_lanjut') NOT NULL,
 * ```
 *
 * ## Exactly one of the five is cross-faskes data sharing
 *
 * {@see PdpConsent} gates one operation on `berbagi_data_medis`, and
 * the other four are named by the DDL rather than by this todo: terms, privacy policy,
 * marketing and follow-up communication. None of the other four means "may I read my
 * own record" either, which is why issuing a non-referral letter to a patient
 * consults no consent row at all - reading your own letter to your own doctor is not
 * a disclosure to a third party, and the schema offers no consent kind for it.
 *
 * ## Same reasoning as {@see SuratKeteranganTipe}
 *
 * The list is asserted against the parsed DDL with `toBe` on every test run, order
 * included, because a value differing from the schema by one letter still looks right
 * in a diff and would answer 403 for a patient who did consent.
 */
enum PersetujuanPdpJenis: string
{
    case SyaratKetentuan = 'syarat_ketentuan';
    case KebijakanPrivasi = 'kebijakan_privasi';
    case BerbagiDataMedis = 'berbagi_data_medis';
    case Pemasaran = 'pemasaran';
    case KomunikasiTindakLanjut = 'komunikasi_tindak_lanjut';

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
