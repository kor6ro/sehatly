<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four `surat_keterangan.tipe` values, in the DDL's own order.
 *
 * `telemedicine_test.sql:585`:
 *
 * ```
 * tipe ENUM('surat_sakit','surat_sehat','surat_rujukan','surat_kematian') NOT NULL,
 * ```
 *
 * ## Why this is a class and not a `Rule::in([...])` literal
 *
 * Three executors this month shipped a hand-typed literal that had silently become a
 * different string, one of them inside a permission name. An ENUM list is exactly that
 * shape: a value differing from the schema by one letter still looks right in a diff,
 * would answer 422 for a legitimate request, and would pass review. The closed set is
 * therefore asserted against the DDL with `toBe` on every test run, which checks order
 * as well as membership, so a transposed pair fails too.
 *
 * ## `surat_rujukan` is the one value with a second row behind it
 *
 * The other three are a single `surat_keterangan` row. A referral is a letter AND a
 * `rujukan` row hanging off it (`rujukan.surat_keterangan_id` is `NOT NULL` with a real
 * foreign key at `:601` and `:612`), and it is the only one of the four that requires
 * an approved `persetujuan_pdp` row of `jenis = 'berbagi_data_medis'`, because a
 * referral is the one letter type that hands clinical content to another facility.
 * See {@see \App\Services\SuratKeterangan\SuratKeteranganService}.
 *
 * ## The fourth value has no counterpart anywhere in the schema
 *
 * `surat_kematian` is a death certificate. Nothing in the DDL restricts which patients
 * one may be issued to - `pasien.is_meninggal` (`telemedicine_test.sql:245`) exists and
 * is not consulted here - so the letter type is storable for any patient. That is a
 * schema limitation and is reported rather than papered over: enforcing it would mean
 * refusing a request the column accepts.
 */
enum SuratKeteranganTipe: string
{
    case SuratSakit = 'surat_sakit';
    case SuratSehat = 'surat_sehat';
    case SuratRujukan = 'surat_rujukan';
    case SuratKematian = 'surat_kematian';

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
     * The two types that describe a PERIOD, and therefore carry
     * `tanggal_mulai`, `tanggal_selesai` and `jumlah_hari`.
     *
     * A sickness certificate names a rest period and a referral letter carries the
     * period the patient was told to rest for. The other two describe a MOMENT: a
     * health certificate attests to the patient's condition today and a death
     * certificate records a date, so all three period columns are absent rather than
     * zero.
     *
     * @return list<string>
     */
    public static function tipeDenganPeriode(): array
    {
        return [self::SuratSakit->value, self::SuratRujukan->value];
    }

    /**
     * Does this letter type describe a period rather than a moment?
     */
    public static function punyaPeriode(self $tipe): bool
    {
        return in_array($tipe->value, self::tipeDenganPeriode(), true);
    }

    /**
     * Is this the one type that also writes a `rujukan` row?
     */
    public static function denganRujukan(self $tipe): bool
    {
        return $tipe === self::SuratRujukan;
    }
}
