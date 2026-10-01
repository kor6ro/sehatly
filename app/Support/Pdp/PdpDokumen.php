<?php

declare(strict_types=1);

namespace App\Support\Pdp;

use App\Enums\PersetujuanPdpJenis;
use App\Services\Pdp\PdpConsent;
use App\Services\Pdp\PdpConsentService;
use LogicException;

/**
 * The server's answer to "which version of each PDP document is current?".
 *
 * ## The owner's decision this class implements
 *
 * `GET /api/v1/pdp/dokumen` is the version authority. Before it existed, a
 * client had to invent a `versi_dokumen` (the F02 benchmark's open question #1),
 * and the write path could only refuse a version that was not the highest one
 * already recorded - which is a fact about the CALLER's history, not about the
 * document. This class reads the active version from `config/pdp.php`, so the
 * catalogue is a deployment fact and the client echoes back exactly what the
 * server published.
 *
 * ## Why a support class and not a table
 *
 * The five entries are fixed deployment data with no per-user rows, no foreign
 * keys and no history of their own - the history is the append-only ledger in
 * `persetujuan_pdp`. A table would need a migration, a model, a seeder and a
 * second source of truth for the same five values, and the repository has no
 * stronger pattern for a fixed catalogue (`config/push.php`, `config/otp.php`
 * and `config/nik.php` are the same shape). The choice is recorded here because
 * the alternative - a `pdp_dokumen` table - is the first thing a later reader
 * would reach for.
 *
 * ## The version shape is asserted, not assumed
 *
 * `versi` must match `v` plus exactly two digits (`v01`..`v99`). The ledger no
 * longer orders by the string - it orders by append id - but the value is what
 * a client displays and echoes back, and the owner's decision keeps the
 * zero-padded convention. A misconfigured `1.0` or `v1` raises a
 * `LogicException` (a sanitised 500 and a log line) rather than being published
 * as an active version no client can match.
 *
 * ## The comparison is STRICT, and that is a decision
 *
 * The write path compares the incoming `versi_dokumen` against
 * {@see versiAktif()} with `!==`. The ledger no longer relies on MySQL's
 * case-insensitive collation for ordering or uniqueness - it orders by append
 * id - so there is no reason to accept a different spelling of the same
 * version. The server publishes `v01`; the client echoes `v01`; `V01` is a
 * different string and is refused with the same 422 as any other non-active
 * version.
 *
 * ## An unknown `jenis` is a PROGRAMMING error, not a 404
 *
 * The same distinction {@see PdpConsent} makes: a `jenis` the
 * DDL ENUM does not hold is a broken caller, and answering "not found" would
 * let a misspelled kind look like a document that does not exist. The list is
 * read from {@see PersetujuanPdpJenis::nilai()}, which is itself asserted
 * against the parsed DDL on every test run, so this class cannot drift from the
 * schema by one letter.
 */
final class PdpDokumen
{
    /**
     * The only version shape this catalogue accepts: `v` plus two digits.
     *
     * Zero-padded so `v01`..`v99` are fixed width, which is the owner's
     * convention and what a client displays. `v100` is deliberately not
     * representable; a catalogue that outgrows 99 versions needs a decision, not
     * a silent three-digit value.
     */
    public const POLA_VERSI = '/^v\d{2}$/';

    /**
     * Every `jenis` with its active version, in the DDL's own order.
     *
     * The caller-facing shape is a checklist of five, so this returns one entry
     * per ENUM member whether or not a consent row exists - the same reason
     * {@see PdpConsentService::ringkasan()} has no holes.
     *
     * @return list<array{jenis: string, versi_dokumen: string, berlaku_sejak: string}>
     */
    public function semua(): array
    {
        $hasil = [];

        foreach (PersetujuanPdpJenis::nilai() as $jenis) {
            $hasil[] = ['jenis' => $jenis] + $this->aktif($jenis);
        }

        return $hasil;
    }

    /**
     * The active version and effective date of one `jenis`.
     *
     * @return array{versi_dokumen: string, berlaku_sejak: string}
     *
     * @throws LogicException when `$jenis` is not a DDL ENUM member, when the
     *                        config entry is missing, or when its `versi` is not
     *                        the zero-padded `vNN` shape
     */
    public function aktif(string $jenis): array
    {
        if (! in_array($jenis, PersetujuanPdpJenis::nilai(), true)) {
            throw new LogicException(
                'Unknown persetujuan_pdp jenis ['.$jenis.']. The DDL ENUM at telemedicine_test.sql:1137-1138 '
                .'holds only: '.implode(', ', PersetujuanPdpJenis::nilai()).'.'
            );
        }

        $entri = config('pdp.dokumen.'.$jenis);

        if (! is_array($entri) || ! isset($entri['versi'], $entri['berlaku_sejak'])) {
            throw new LogicException(
                'config/pdp.php has no active document for jenis ['.$jenis.']. Every DDL ENUM member needs '
                .'a `versi` and a `berlaku_sejak`, or GET /api/v1/pdp/dokumen would publish a hole.'
            );
        }

        $versi = (string) $entri['versi'];

        if (preg_match(self::POLA_VERSI, $versi) !== 1) {
            throw new LogicException(
                'config/pdp.php declares versi ['.$versi.'] for jenis ['.$jenis.'], which is not the '
                .'zero-padded vNN shape (v01..v99). A version a client cannot echo back is not a version.'
            );
        }

        return [
            'versi_dokumen' => $versi,
            'berlaku_sejak' => (string) $entri['berlaku_sejak'],
        ];
    }

    /**
     * The active version string of one `jenis`, which is what the write path
     * compares an incoming `versi_dokumen` against.
     *
     * @throws LogicException under the same conditions as {@see aktif()}
     */
    public function versiAktif(string $jenis): string
    {
        return $this->aktif($jenis)['versi_dokumen'];
    }
}
