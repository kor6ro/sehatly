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
 * ## Why the HIGHEST `versi_dokumen` and not the first row
 *
 * `telemedicine_test.sql:1144` is `UNIQUE KEY uq_consent (user_id, jenis,
 * versi_dokumen)`, and `disetujui TINYINT(1) NOT NULL` (`:1140`) has no nullable twin.
 * Together those two mean **consent cannot be revoked for a given document version**:
 * a second row carrying the same `(user_id, jenis, versi_dokumen)` would collide with
 * the unique key, and changing the existing row's `disetujui` in place is a rewrite of
 * the record of what the person agreed to rather than a new agreement.
 *
 * The only representable revocation is therefore a NEW `versi_dokumen` carrying
 * `disetujui = 0`. A check that read the FIRST matching row would keep honouring a
 * consent the person has since withdrawn, so this reads the highest version and
 * honours ITS `disetujui`. That is a convention rather than an enforced ordering, and
 * the consequence is stated in the test that pins it: `versi_dokumen` is
 * `VARCHAR(20)` (`:1139`), NOT an integer, so "highest" is the column's own STRING
 * order - `v2.0` sorts above `v10.0` because `'2' > '1'` at the second position. There
 * is no numeric column to cast, so the mitigation is a convention on the writer
 * (fixed-width or zero-padded version strings) and this class does not paper over it.
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
     * Is the highest-version consent of `$jenis` for `$user` an approval?
     *
     * The predicate twin of {@see require()}, so a caller that wants to BRANCH rather
     * than refuse - to show a consent prompt, for instance - gets the same answer from
     * the same code rather than re-implementing the version rule.
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
     * @throws LogicException            when `$jenis` is not a value of the DDL ENUM
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
     * | highest version says yes | `true` | `true` |
     * | highest version says no | `false` | `false` |
     *
     * @throws LogicException when `$jenis` is not a value of the DDL ENUM
     */
    public function effective(User $user, string $jenis): ?bool
    {
        $terbaru = $this->versiTerbaru($user, $jenis);

        return $terbaru === null ? null : (bool) $terbaru->disetujui;
    }

    /**
     * The highest `versi_dokumen` row of `$jenis` for `$user`, or `null`.
     *
     * `orderByDesc('versi_dokumen')` is a STRING order because the column is
     * `VARCHAR(20)` (`:1139`) and the database's default collation is
     * `utf8mb4_unicode_ci` (`telemedicine_test.sql:13`) - see the class docblock
     * for why that is a convention rather than a numeric comparison and what it
     * costs. `disetujui_at` is a `DATETIME NOT NULL` (`:1141`) and is NOT used as
     * a tiebreaker, because `uq_consent` already makes `(user_id, jenis,
     * versi_dokumen)` unique: there is never a second row to break a tie with, and
     * ordering by a wall-clock `DATETIME` would be a different - and
     * unsynchronised - notion of "latest" for a document whose version number is
     * the authority.
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
            ->orderByDesc('versi_dokumen')
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
     * One entry per `jenis` in DDL order, carrying the row the version rule reads.
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
