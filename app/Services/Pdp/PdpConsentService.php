<?php

declare(strict_types=1);

namespace App\Services\Pdp;

use App\Enums\PersetujuanPdpJenis;
use App\Models\PersetujuanPdp;
use App\Models\User;
use App\Support\Pdp\PdpDokumen;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The WRITE path for one decision about the ACTIVE document version, and the
 * only place the version rule is enforced on the way in.
 *
 * ## The owner's decision, restated as code
 *
 * `persetujuan_pdp` is an **append-only ledger**. The `uq_consent` unique key
 * that used to make `(user_id, jenis, versi_dokumen)` unique was dropped by F02
 * (see `docs/schema-notes.md`), so a person may hold any number of rows for the
 * same document version, and the CURRENT status is the **latest recorded row
 * per `(user_id, jenis)`** - append order, which is `id` order because the
 * active version is enforced on every write.
 *
 * | incoming | outcome |
 * | --- | --- |
 * | `versi_dokumen` is NOT the active version of `jenis` | refused: 422 on `versi_dokumen` |
 * | the active version, same answer as the latest row | the latest row, untouched - an idempotent re-send (200) |
 * | the active version, different answer | a NEW row is appended; it becomes the effective one (201) |
 *
 * **Withdrawal is allowed anytime, instantly, on the SAME version.** The old
 * rule could only represent a revocation as a higher version, which meant a
 * person could not change their mind until the document itself advanced. The
 * ledger removes that coupling: `disetujui = false` at the active version is a
 * new row, and the latest row wins.
 *
 * **The active version is the server's, not the client's.** It comes from
 * {@see PdpDokumen}, which reads `config/pdp.php`; the client learns it from
 * `GET /api/v1/pdp/dokumen` and echoes it back. A client can no longer invent a
 * version, and a stale client is refused loudly instead of writing a row that
 * could never be the effective one.
 *
 * ## Why the row is appended through the MODEL
 *
 * The global `AuditObserver` is an Eloquent observer, and a builder insert
 * fires no event, so a consent record written that way would be the one write
 * in this table that leaves no `audit_log` row. The idempotent re-send writes
 * nothing and therefore produces no audit row either: the audit records
 * DECISIONS, not HTTP calls.
 *
 * ## `disetujui_at` is the moment of the DECISION, for either answer
 *
 * The column is `DATETIME NOT NULL` (`telemedicine_test.sql:1141`) with no
 * nullable twin, so a refusal must carry an instant too. It is the instant the
 * person decided, taken from the application clock - never from the request,
 * because "when did you decide" is not a client-supplied fact and a caller who
 * could set it could backdate a consent record. The request forbids the field
 * outright rather than ignoring it, for the reason `CheckoutResepRequest` gives:
 * a caller who sent it believes they set it.
 *
 * ## `ip_address` is captured, not chosen
 *
 * `ip_address VARCHAR(45) NULL` (`:1142`) is `NULL`-able, so it is written from
 * `$request->ip()` when the caller is behind the API and left NULL otherwise. A
 * client-supplied address would be a self-attested audit trail.
 *
 * ## There is NO upsert, and `ON DUPLICATE KEY UPDATE` is still the defect
 *
 * An upsert would `UPDATE` the latest row's `disetujui`, `disetujui_at` and
 * `ip_address` - destroying the record of what the person originally decided
 * while making the endpoint answer 201. The table is a HISTORY; a superseded
 * decision is a NEW row, and this class never edits one.
 */
final class PdpConsentService
{
    public function __construct(
        private readonly PdpDokumen $dokumen,
    ) {}

    /**
     * Record one decision about the active version, or explain why it is refused.
     *
     * @param  string|null  $ip  the request's address, or null outside a request
     *
     * @throws PerubahanVersiException when `$versi` is not the active version
     * @throws LogicException when `$jenis` is not a value of the DDL ENUM
     */
    public function catat(User $user, string $jenis, string $versi, bool $disetujui, ?string $ip = null): PersetujuanPdp
    {
        if (! in_array($jenis, PersetujuanPdpJenis::nilai(), true)) {
            throw new LogicException(
                'Unknown persetujuan_pdp jenis ['.$jenis.']. The DDL ENUM at telemedicine_test.sql:1137-1138 '
                .'holds only: '.implode(', ', PersetujuanPdpJenis::nilai()).'.'
            );
        }

        // R1. The server is the version authority. A version that is not the
        // active one is refused BEFORE any read of the caller's history, so the
        // refusal is a fact about the document and not about what this account
        // happens to have recorded.
        $aktif = $this->dokumen->versiAktif($jenis);

        if ($versi !== $aktif) {
            throw PerubahanVersiException::tidakAktif($versi, $aktif);
        }

        $userId = (int) $user->getKey();

        // R2. The effective row is the LATEST recorded one, by append order.
        // `id` is the append order: the active version is enforced above, so
        // every row this service writes carries the same version until the
        // catalogue advances, and `id` is the only ordering the ledger needs.
        // `versi_dokumen` is deliberately NOT the ordering key any more - the
        // old string-order rule is what made a withdrawal impossible on the
        // same version.
        $terakhir = PersetujuanPdp::query()
            ->where('user_id', $userId)
            ->where('jenis', $jenis)
            ->orderByDesc('id')
            ->first();

        if ($terakhir !== null && (bool) $terakhir->disetujui === $disetujui) {
            // The idempotent re-send. The recorded decision is returned
            // UNCHANGED - no write, therefore no `audit_log` row either,
            // because the audit records decisions and not HTTP calls.
            return $terakhir;
        }

        // R3. A new decision - including a withdrawal on the SAME version -
        // appends a row. The SERVER's canonical version is written, not the
        // caller's string: they are equal today because R1's comparison is
        // strict, and writing the server's value keeps the ledger canonical if
        // that comparison is ever relaxed.
        $baris = new PersetujuanPdp;
        $baris->user_id = $userId;
        $baris->jenis = $jenis;
        $baris->versi_dokumen = $aktif;
        $baris->disetujui = $disetujui;
        $baris->disetujui_at = Carbon::now();
        $baris->ip_address = $ip;
        $baris->save();

        return $baris;
    }

    /**
     * Record a decision about whatever version is ACTIVE, without the caller
     * having to know it.
     *
     * This exists for the registration flow, where the consents are written
     * inside the account-creation transaction and there is no client-supplied
     * `versi_dokumen` to compare: the server just wrote the account, so it is
     * also the server that stamps the current version. The alternative -
     * `AuthController` reading `PdpDokumen` itself and calling {@see catat()} -
     * would put the version lookup in a second place, which is exactly the
     * duplication this service exists to prevent.
     *
     * The rule is unchanged from {@see catat()}: a brand-new account has no
     * prior row, so this appends; the ledger stays append-only and the version
     * stays canonical.
     *
     * @param  string|null  $ip  the request's address, or null outside a request
     *
     * @throws LogicException when `$jenis` is not a value of the DDL ENUM
     */
    public function catatVersiAktif(User $user, string $jenis, bool $disetujui, ?string $ip = null): PersetujuanPdp
    {
        return $this->catat($user, $jenis, $this->dokumen->versiAktif($jenis), $disetujui, $ip);
    }

    /**
     * Every `jenis` with the row the ledger would read, in DDL order.
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
            ->orderByDesc('id')
            ->get()
            ->groupBy('jenis');

        $hasil = [];

        foreach (PersetujuanPdpJenis::nilai() as $jenis) {
            $hasil[] = [
                'jenis' => $jenis,
                // `get()` came back ordered by `id DESC`, so the FIRST row of
                // each group IS the latest recorded one - the same row
                // {@see PdpConsent::versiTerbaru()} reads, read by the same
                // ordering. `first()` on the group would be a second spelling of
                // that rule.
                'baris' => ($tercatat[$jenis] ?? null)?->first(),
            ];
        }

        return $hasil;
    }
}
