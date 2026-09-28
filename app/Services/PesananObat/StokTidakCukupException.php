<?php

declare(strict_types=1);

namespace App\Services\PesananObat;

use RuntimeException;

/**
 * A medicine-order write refused for a stock or pharmacy reason.
 *
 * There is no `App\Exceptions` namespace in this project; domain exceptions
 * live beside their service (`Services\Booking\SlotTakenException` and
 * `Services\Auth\OtpRejected` are the templates), so this one lives beside
 * `ApotekStokService` and `PesananObatService`.
 *
 * A bare `RuntimeException` would render as a sanitised 500
 * (`bootstrap/app.php`), so the controller catches this and converts it to a
 * 422 with `errors()`. The exception is never left to bubble.
 *
 * ## Why the messages name the drug and the two numbers
 *
 * `apotek_stok.jumlah_stok` is a SIGNED `INT` with no `CHECK`
 * (`telemedicine_test.sql:833`), so "you asked for 3, there is 1" is a fact the
 * caller can act on and a fact the pharmacy can explain. A refusal that said
 * only "stok tidak cukup" would make a client show the same message whether the
 * order wanted 2 of 10 or 100 of 1, and the second of those is a case where the
 * patient should be offered another pharmacy rather than an error.
 */
final class StokTidakCukupException extends RuntimeException
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
     * The pharmacy named by `apotek_id` does not exist.
     *
     * `pesanan_obat.apotek_id` is `BIGINT UNSIGNED NOT NULL` (`:802`) with a
     * real foreign key to `faskes(id)` (`:816`), so MySQL would answer 1452 for
     * a bad id; refusing in the service turns that into a 422 naming the field
     * instead of a sanitised 500.
     */
    public static function apotekTidakAda(int $apotekId): self
    {
        $pesan = "Apotek dengan id {$apotekId} tidak ditemukan.";

        return new self($pesan, ['apotek_id' => [$pesan]]);
    }

    /**
     * The row exists but is not a pharmacy.
     *
     * `faskes.tipe` is a five-value ENUM `('rumah_sakit','klinik','puskesmas',
     * 'apotek','laboratorium')` (`:365`) and nothing constrains the three
     * pharmacy columns to `'apotek'`, so a hospital is representable as the
     * dispensing pharmacy. TWO messages, because the caller broke one rule
     * (this is not a pharmacy) and needs one fact to fix it (which it is).
     */
    public static function bukanApotek(string $tipe): self
    {
        return new self('Faskes yang dipilih bukan apotek.', [
            'apotek_id' => [
                'Faskes yang dipilih bukan apotek.',
                sprintf('faskes.tipe saat ini "%s"; hanya "apotek" yang dapat melayani pesanan obat.', $tipe),
            ],
        ]);
    }

    /**
     * The pharmacy is a real `apotek` but is switched off.
     *
     * `faskes.status_aktif TINYINT(1) NOT NULL DEFAULT 1` (`:378`), so an
     * inactive pharmacy is a real stored state rather than a fiction, and
     * ordering from a closed pharmacy would produce a parcel nobody collects.
     */
    public static function apotekTidakAktif(): self
    {
        $pesan = 'Apotek yang dipilih tidak aktif.';

        return new self($pesan, ['apotek_id' => [$pesan]]);
    }

    /**
     * The pharmacy carries no `apotek_stok` row for this drug at all.
     *
     * Distinct from "has none left": a missing row means the pharmacy has never
     * been stocked with the drug, and `jumlah_stok` has no value to compare
     * against - which is why the shortage is reported as `0` rather than left
     * blank.
     */
    public static function tidakDicatat(
        int $apotekId,
        int $obatId,
        string $namaObat,
        int $tersedia,
        int $diminta,
    ): self {
        $pesan = sprintf(
            'Stok "%s" tidak tercatat di apotek yang dipilih (tersedia %d, diminta %d).',
            $namaObat,
            $tersedia,
            $diminta,
        );

        return new self($pesan, [
            'apotek_id' => [
                $pesan,
                'Pilih apotek lain melalui endpoint GET /api/v1/obat/{id}/stok untuk melihat apotek alternatif.',
            ],
        ]);
    }

    /**
     * The pharmacy has the drug but not enough of it.
     *
     * TWO messages on ONE field, and the second names where the alternatives
     * are: the plan's spec rule is that a pharmacy with none of the drug must
     * offer the others, and the alternatives live on the stock-read endpoint
     * rather than in a 422 body, because a 422's `errors` map is field-keyed
     * strings and a list of pharmacies is data, not a message.
     */
    public static function tidakCukup(
        int $apotekId,
        int $obatId,
        string $namaObat,
        int $tersedia,
        int $diminta,
    ): self {
        $pesan = sprintf(
            'Stok "%s" tidak mencukupi di apotek yang dipilih (tersedia %d, diminta %d).',
            $namaObat,
            $tersedia,
            $diminta,
        );

        return new self($pesan, [
            'apotek_id' => [
                $pesan,
                'Pilih apotek lain melalui endpoint GET /api/v1/obat/{id}/stok untuk melihat apotek alternatif.',
            ],
        ]);
    }
}
