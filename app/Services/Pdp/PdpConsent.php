<?php

declare(strict_types=1);

namespace App\Services\Pdp;

use App\Enums\PersetujuanPdpJenis;
use App\Models\PersetujuanPdp;
use App\Models\User;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The one answer to "has this account consented to this?".
 *
 * ## Why the LATEST recorded row and not the highest version
 *
 * F02's owner decision made `persetujuan_pdp` an **append-only ledger**: the
 * `uq_consent` unique key was dropped, so a person may hold any number of rows
 * for the same document version, and the current status is the **latest
 * recorded row per `(user_id, jenis)`** - append order, which is `id` order
 * because the write path enforces the active version on every insert.
 *
 * The old rule read the row with the maximum `versi_dokumen`, which was a
 * STRING order over a `VARCHAR(20)` (`telemedicine_test.sql:1139`) and therefore
 * a convention rather than a guarantee: `v2.0` sorts above `v10.0`. It also
 * made a withdrawal on the same version unrepresentable. Both problems are gone
 * with the ledger: a withdrawal is a new row carrying `disetujui = 0`, and the
 * latest row wins regardless of what its version string looks like.
 *
 * ## An unknown `jenis` is a PROGRAMMING error, not a 403
 *
 * The same distinction `RbacCatalog::permissionsFor()` and `EnsurePermission` make: a
 * code the catalogue does not have is a broken caller, and answering 403 would let a
 * misspelled `jenis` look like a patient who refused - the worst possible failure mode
 * for a consent check, because it is silent and it blocks a legitimate clinical
 * action. A `LogicException` surfaces as a sanitised 500 and a log line instead.
 *
 * ## This is the reusable service todo 47 needs
 *
 * The plan's todo 34 asks for exactly this class and names todo 47 as its second
 * consumer, so the signature takes the ACCOUNT rather than a `pasien` id: the PDP
 * consent belongs to a person, and both the patient-facing and the admin-facing
 * consumers have a `User` to hand.
 */
final class PdpConsent
{
    /**
     * The one kind this class gates an operation on today: sharing clinical data with
     * another facility. It is a value of `persetujuan_pdp.jenis` (`:1137-1138`) and the
     * test asserts it against the parsed DDL rather than trusting this line.
     */
    public const JENIS_BERBAGI_DATA = PersetujuanPdpJenis::BerbagiDataMedis->value;

    /**
     * Is the latest recorded consent of `$jenis` for `$user` an approval?
     *
     * The predicate twin of {@see require()}, so a caller that wants to BRANCH rather
     * than refuse - to show a consent prompt, for instance - gets the same answer from
     * the same code rather than re-implementing the ledger rule.
     */
    public function disetujui(User $user, string $jenis): bool
    {
        $terbaru = $this->versiTerbaru($user, $jenis);

        return $terbaru !== null && (bool) $terbaru->disetujui;
    }

    /**
     * The consent this account needs, or a 403.
     *
     * 403 and not 422: the refusal is a POLICY decision about the caller, the same
     * answer `AccessDeniedHttpException` gives everywhere else in this application,
     * and `bootstrap/app.php` renders it as the 403 envelope without a field map. The
     * alternative - a 422 naming `berbagi_data_medis` - would confirm to a doctor that
     * the specific consent kind is the missing one, which is a fact about the PATIENT
     * the caller did not have before asking. The doctor already knows they are being
     * refused; the message is a constant and the detail is the doctor's business to
     * obtain through the consent flow.
     *
     * @throws AccessDeniedHttpException when there is no consent, or the latest one is a refusal
     * @throws LogicException when `$jenis` is not a value of the DDL ENUM
     */
    public function require(User $user, string $jenis): PersetujuanPdp
    {
        $terbaru = $this->versiTerbaru($user, $jenis);

        if ($terbaru === null || ! $terbaru->disetujui) {
            throw new AccessDeniedHttpException(
                'Persetujuan berbagi data medis tidak diberikan oleh pasien.'
            );
        }

        return $terbaru;
    }

    /**
     * The three-state answer: `true`, `false`, or `null` for "no row at all".
     *
     * ## Why this is not {@see disetujui()} with a different name
     *
     * `disetujui()` collapses `null` into `false`, which is the right direction
     * for a GATE: an account with no consent row must be refused, and "refused"
     * and "never asked" lead to the same place. It is the wrong shape for a
     * READ, and this endpoint is a read: a consent checklist whose unchecked box
     * and whose ticked-then-unticked box both render as "no" is a checklist that
     * cannot show a person what is on record about them, which is the whole point
     * of publishing the record at all.
     *
     * So the two are deliberately different methods with deliberately different
     * return types, and this docblock is the only place that has to say so:
     *
     * | state | `effective()` | `disetujui()` |
     * | --- | --- | --- |
     * | no row at all | `null` | `false` |
     * | latest row says yes | `true` | `true` |
     * | latest row says no | `false` | `false` |
     *
     * @throws LogicException when `$jenis` is not a value of the DDL ENUM
     */
    public function effective(User $user, string $jenis): ?bool
    {
        $terbaru = $this->versiTerbaru($user, $jenis);

        return $terbaru === null ? null : (bool) $terbaru->disetujui;
    }

    /**
     * The latest recorded row of `$jenis` for `$user`, or `null`.
     *
     * `orderByDesc('id')` is the ledger's append order. `id` is a
     * `BIGINT UNSIGNED AUTO_INCREMENT` primary key (`:1135`), so it is
     * monotonic per insert and is the only ordering the rule needs: the write
     * path enforces the active version, so every row for one `(user, jenis)`
     * carries the same version until the catalogue advances, and a withdrawal
     * on the same version is simply a later row.
     *
     * `disetujui_at` is a `DATETIME NOT NULL` (`:1141`) and is NOT used as a
     * tiebreaker: it is the moment the person decided, which is a fact about the
     * act, while `id` is the moment the server recorded it. Two decisions in the
     * same second are still ordered by `id`, and a client-supplied instant can
     * never reorder the ledger.
     *
     * @throws LogicException when `$jenis` is not a value of the DDL ENUM
     */
    public function versiTerbaru(User $user, string $jenis): ?PersetujuanPdp
    {
        if (! in_array($jenis, PersetujuanPdpJenis::nilai(), true)) {
            throw new LogicException(
                'Unknown persetujuan_pdp jenis ['.$jenis.']. The DDL ENUM at telemedicine_test.sql:1137-1138 '
                .'holds only: '.implode(', ', PersetujuanPdpJenis::nilai()).'.'
            );
        }

        return PersetujuanPdp::query()
            ->where('user_id', $user->getKey())
            ->where('jenis', $jenis)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The moment a consent was given, for an audit line.
     *
     * `disetujui_at` is `DATETIME NOT NULL` (`:1141`) and is a rule-(1) INSTANT under
     * the plan's todo 51 policy, so it is ISO-8601 UTC. Exposed here rather than read
     * off the model by three future callers, because the conversion is the kind of
     * thing that has to be decided once.
     */
    public function disetujuiAt(?PersetujuanPdp $consent): ?string
    {
        if ($consent === null || $consent->disetujui_at === null) {
            return null;
        }

        return Carbon::instance($consent->disetujui_at)->toISOString();
    }

    /**
     * One entry per `jenis` in DDL order, carrying the row the ledger reads.
     *
     * The ordering is read from the DDL by {@see PersetujuanPdpJenis::nilai()}
     * rather than from whatever order a query happened to return, because the
     * consumer is a consent CHECKLIST: five slots, in a fixed order, each either
     * carrying the effective answer or explicitly `null`. A checklist that
     * omitted the unanswered kinds would push the "has this person answered
     * this yet" rule into every client, and three clients would answer it three
     * ways.
     *
     * @return list<array{jenis: string, baris: PersetujuanPdp|null}>
     */
    public function ringkasan(User $user): array
    {
        return app(PdpConsentService::class)->ringkasan($user);
    }
}
