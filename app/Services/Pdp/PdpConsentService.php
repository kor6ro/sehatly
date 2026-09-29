<?php

declare(strict_types=1);

namespace App\Services\Pdp;

use App\Enums\PersetujuanPdpJenis;
use App\Models\PersetujuanPdp;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The WRITE path for one decision about one document version, and the only place
 * the version rule is enforced on the way in.
 *
 * ## THE RULE, restated as code
 *
 * `telemedicine_test.sql:1134`-`:1145` gives `persetujuan_pdp` three columns that
 * decide everything:
 *
 * ```
 * :1139  versi_dokumen VARCHAR(20) NOT NULL,
 * :1140  disetujui      TINYINT(1) NOT NULL,
 * :1144  UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)
 * ```
 *
 * The unique key spans THREE columns and `disetujui` is `NOT NULL` with no
 * nullable twin, so **a decision about a given document version is immutable**.
 * A revocation can therefore only be recorded as a NEW `versi_dokumen` carrying
 * `disetujui = 0`, and {@see PdpConsent} reads the answer off the row with the
 * maximum `versi_dokumen`.
 *
 * That read is only safe if the WRITE side agrees with it, so this class refuses
 * anything that could not become the effective row:
 *
 * | incoming | outcome |
 * | --- | --- |
 * | version HIGHER than the maximum | written; supersedes every lower version |
 * | version EQUAL, same `disetujui` | the existing row, untouched - an idempotent re-send |
 * | version EQUAL, different `disetujui` | refused: the revoked-same-version collision |
 * | version LOWER than the maximum | refused: it could never be read |
 *
 * **The defect this prevents.** A store that accepted the last two would let a
 * revocation at version N be undone by a consent at version N-1, and would report
 * a success for a row that changes nothing. The gate in
 * {@see PdpConsent::require()} reads the maximum, so a resurrected row is
 * invisible - the client would be told "withdrawn" while the refusal never lands.
 *
 * ## Every comparison is done IN SQL, and that is load-bearing
 *
 * `telemedicine_test.sql:11`-`:13` creates the database with
 * `COLLATE utf8mb4_unicode_ci`, and no `CREATE TABLE` in the file overrides it, so
 * `versi_dokumen` is compared **case-insensitively and accent-insensitively**.
 * `'v1.0'` and `'V1.0'` are the same value to `uq_consent` and different strings
 * to PHP's `strcmp()`.
 *
 * A pre-check written in PHP would therefore DISAGREE with the unique key, and the
 * disagreement is not theoretical: a client holding `V1.0` against a stored `v1.0`
 * would pass a `strcmp` pre-check and then be killed by a raw 1062. So the equality
 * test is `where('versi_dokumen', $versi)` and the ordering test is
 * `where('versi_dokumen', '>', $versi)` - both evaluated by MySQL, in the same
 * collation the constraint uses, so the two can never drift. That also means
 * `'V1.0'` against a stored `'v1.0'` is correctly treated as a COLLISION, which is
 * what the DDL would do to the INSERT.
 *
 * ## The unique violation is caught anyway
 *
 * The pre-check above is application logic and can be raced by a second writer
 * between its SELECT and its INSERT. `uq_consent` fires regardless, so the
 * exception is caught and mapped to the SAME refusal the pre-check produces. A race
 * is a normal outcome of a concurrent write, and answering it 500 would mean the
 * collision is only handled when nobody else is looking.
 *
 * ## `disetujui_at` is the moment of the DECISION, for either answer
 *
 * The column is `DATETIME NOT NULL` (`:1141`) with no nullable twin, so a refusal
 * must carry an instant too. It is the instant the person withdrew, taken from the
 * application clock - never from the request, because "when did you decide" is not
 * a client-supplied fact and a caller who could set it could backdate a consent
 * record. The request forbids the field outright rather than ignoring it, for the
 * reason `CheckoutResepRequest` gives: a caller who sent it believes they set it.
 *
 * ## `ip_address` is captured, not chosen
 *
 * `ip_address VARCHAR(45) NULL` (`:1142`) is `NULL`-able, so it is written from
 * `$request->ip()` when the caller is behind the API and left NULL otherwise. A
 * client-supplied address would be a self-attested audit trail.
 *
 * ## There is NO upsert, and `ON DUPLICATE KEY UPDATE` is the defect named
 *
 * The plan's todo 47 text says "upserting against `uq_consent`". An upsert here
 * would `UPDATE` the existing row's `disetujui`, `disetujui_at` and `ip_address` -
 * which is the revoked-same-version collision performed silently, and would
 * destroy the record of what the person originally agreed to while making the
 * endpoint answer 201. The table is a HISTORY; a superseded decision is a NEW row,
 * and this class never edits one.
 */
final class PdpConsentService
{
    /**
     * Record one decision, or explain why the version rule refuses it.
     *
     * @param  string|null  $ip  the request's address, or null outside a request
     *
     * @throws PerubahanVersiException         on the collision or an out-of-order write
     * @throws LogicException                   when `$jenis` is not a value of the DDL ENUM
     * @throws UniqueConstraintViolationException never - it is caught and mapped
     */
    public function catat(User $user, string $jenis, string $versi, bool $disetujui, ?string $ip = null): PersetujuanPdp
    {
        if (! in_array($jenis, PersetujuanPdpJenis::nilai(), true)) {
            throw new LogicException(
                'Unknown persetujuan_pdp jenis ['.$jenis.']. The DDL ENUM at telemedicine_test.sql:1137-1138 '
                .'holds only: '.implode(', ', PersetujuanPdpJenis::nilai()).'.'
            );
        }

        $userId = (int) $user->getKey();

        // R1a / R1b, decided in MySQL's own collation. See the class docblock
        // for why a PHP `strcmp` here would disagree with `uq_consent`.
        $sama = PersetujuanPdp::query()
            ->where('user_id', $userId)
            ->where('jenis', $jenis)
            ->where('versi_dokumen', $versi)
            ->first();

        if ($sama !== null) {
            if ((bool) $sama->disetujui === $disetujui) {
                // The idempotent re-send. The recorded decision is returned
                // UNCHANGED - no write, therefore no `audit_log` row either,
                // because the audit records decisions and not HTTP calls.
                return $sama;
            }

            throw PerubahanVersiException::tabrakan($versi, $disetujui);
        }

        // R1c. `where('versi_dokumen', '>', ...)` and not `!=` above: a strictly
        // lower version is refused, and so is any version below the maximum, so
        // the check is a real ordering and not a "different" test.
        $adaYangLebihTinggi = PersetujuanPdp::query()
            ->where('user_id', $userId)
            ->where('jenis', $jenis)
            ->where('versi_dokumen', '>', $versi)
            ->exists();

        if ($adaYangLebihTinggi) {
            throw PerubahanVersiException::lebihLama($versi);
        }

        // R1d. The row is saved through the MODEL, never the query builder: the
        // global `AuditObserver` is an Eloquent observer, and a builder insert
        // fires no event, so a consent record written that way would be the one
        // write in this table that leaves no `audit_log` row.
        $baris = new PersetujuanPdp;
        $baris->user_id = $userId;
        $baris->jenis = $jenis;
        $baris->versi_dokumen = $versi;
        $baris->disetujui = $disetujui;
        $baris->disetujui_at = Carbon::now();
        $baris->ip_address = $ip;

        try {
            $baris->save();
        } catch (UniqueConstraintViolationException $e) {
            // The racing writer. Same outcome, same messages - see the class
            // docblock for why this is not a 500.
            throw PerubahanVersiException::tabrakan($versi, $disetujui);
        }

        return $baris;
    }

    /**
     * Every `jenis` with the row the version rule would read, in DDL order.
     *
     * The caller-facing shape is a checklist, and a checklist with a hole in it
     * makes a client invent its own "has this person answered yet" rule. So this
     * returns one entry per ENUM member whether or not a row exists, and the
     * missing case is `null` rather than absent.
     *
     * @return list<array{jenis: string, baris: PersetujuanPdp|null}>
     */
    public function ringkasan(User $user): array
    {
        $tercatat = PersetujuanPdp::query()
            ->where('user_id', (int) $user->getKey())
            ->orderByDesc('versi_dokumen')
            ->get()
            ->groupBy('jenis');

        $hasil = [];

        foreach (PersetujuanPdpJenis::nilai() as $jenis) {
            $hasil[] = [
                'jenis' => $jenis,
                // `get()` came back ordered by `versi_dokumen DESC`, so the FIRST
                // row of each group IS the maximum - the same row
                // `PdpConsent::versiTerbaru()` reads, read by the same ordering
                // in the same collation. `first()` on the group would be a second
                // spelling of that rule.
                'baris' => ($tercatat[$jenis] ?? null)?->first(),
            ];
        }

        return $hasil;
    }
}
