<?php

declare(strict_types=1);

namespace App\Services\Resep;

use App\Enums\ResepStatus;
use App\Enums\ResepVerifikasiStatus;
use App\Models\Resep;
use App\Models\ResepVerifikasi;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The one place a `resep.status` is allowed to change, and the 8x8 map that
 * says which changes are legal.
 *
 * ## Why a class and not `if` checks at the call sites
 *
 * `resep.status` is `ENUM('aktif','diproses','diverifikasi','dipenuhi','dikirim',
 * 'selesai','kedaluwarsa','dibatalkan') NOT NULL DEFAULT 'aktif'` at
 * `telemedicine_test.sql:751`-`:752` - **eight** members, not the two the plan's
 * todo 40 text names, and the declaration WRAPS onto a second line so reading
 * `:751` alone yields five and makes the column look like a different one.
 *
 * MySQL's ENUM accepts any of its eight members on write, so a skipped step -
 * a prescription that goes `aktif` straight to `dipenuhi` without ever being
 * verified - is a value the DATABASE cannot refuse. The only place that can be
 * refused is here, which is why the map is a single named constant rather than
 * a chain of comparisons in a controller, a service and a command that have to
 * agree with each other and with the DDL.
 *
 * ## The map, and the three shapes of edge
 *
 * ```
 * aktif        -> diproses, kedaluwarsa, dibatalkan
 * diproses     -> diverifikasi, kedaluwarsa, dibatalkan
 * diverifikasi -> dipenuhi, kedaluwarsa, dibatalkan
 * dipenuhi    -> dikirim, kedaluwarsa, dibatalkan
 * dikirim      -> selesai, kedaluwarsa, dibatalkan
 * selesai      -> (terminal)
 * kedaluwarsa  -> (terminal)
 * dibatalkan   -> (terminal)
 * ```
 *
 * 1. **The happy path is a PATH.** `aktif -> diproses -> diverifikasi ->
 *    dipenuhi -> dikirim -> selesai`, and nothing skips a step. `aktif ->
 *    diverifikasi` is refused, which is what makes "a pharmacist picked this up
 *    and signed it off" an observable fact rather than an assumption - and it
 *    is why `ResepVerifikasiService::verifikasi()` walks `aktif` through
 *    `diproses` instead of writing `diverifikasi` directly.
 * 2. **The two exits are available from every LIVE state.** `kedaluwarsa` and
 *    `dibatalkan` are reachable from `aktif` through `dikirim`, because a
 *    prescription can lapse or be called off at any point in that window and
 *    the DDL gives no other column that could record it. Nothing is reachable
 *    FROM either of them.
 * 3. **The three terminals are terminal.** `ObatInteraksiService::STATUS_AKHIR`
 *    (`:249`) is the same three, and the two sets partition the enum - which
 *    `ObatInteraksiServiceTest` already asserts against the parsed DDL, so a
 *    ninth state would fail there and here.
 *
 * ## The refusal is a 422 with TWO messages on ONE field
 *
 * `ValidationException` collapses to the envelope's `errors` map, and
 * `ApiResponse::error()` casts it to an object so an empty set encodes as `{}`.
 * Two messages on `status` is the case the envelope contract names and todo 39
 * proves for `catatan_dodio`: the request breaks two independent rules - the
 * step is illegal, AND this step is not reachable from where the row is - and
 * a client is told both. Filed on `status` because that is the field the caller
 * actually sent and the one it can act on.
 *
 * ## A state the ENUM does not hold is refused
 *
 * {@see boleh()} is a lookup in a map keyed by real members, so a status string
 * that is not one of the eight - a typo, a `booking.status` value, a NULL -
 * answers `false` and is refused. There is no branch that treats an unknown
 * state as permissive, because "we did not recognise this so we allowed it" is
 * how a prescription reaches a customer in a state no UI has a badge for.
 */
final class ResepStateMachine
{
    /**
     * The legal edges, keyed by the state they leave.
     *
     * **The keys are asserted against `ResepStatus::nilai()` by the test**, so
     * a state added to the ENUM without a decision here fails the suite instead
     * of becoming unreachable.
     *
     * @var array<string, list<string>>
     */
    public const TRANSISI = [
        'aktif' => ['diproses', 'kedaluwarsa', 'dibatalkan'],
        'diproses' => ['diverifikasi', 'kedaluwarsa', 'dibatalkan'],
        'diverifikasi' => ['dipenuhi', 'kedaluwarsa', 'dibatalkan'],
        'dipenuhi' => ['dikirim', 'kedaluwarsa', 'dibatalkan'],
        'dikirim' => ['selesai', 'kedaluwarsa', 'dibatalkan'],
        'selesai' => [],
        'kedaluwarsa' => [],
        'dibatalkan' => [],
    ];

    /**
     * The states nothing leads out of.
     *
     * The same three as `ObatInteraksiService::STATUS_AKHIR` (`:249`), and the
     * same three whose keys in {@see TRANSISI} are empty lists - a test asserts
     * both, so the two cannot drift apart.
     *
     * @var list<string>
     */
    public const TERMINAL = ['selesai', 'kedaluwarsa', 'dibatalkan'];

    /**
     * The states a prescription may be DISPENSED from.
     *
     * The plan's rule is "apoteker wajib verifikasi sebelum `dipenuhi`" -
     * `telemedicine_test.sql:785`, the table's own COMMENT - so `diverifikasi`
     * is the entry point and everything downstream of it is still dispensed.
     * The two live states BEFORE it are not, and that exclusion is the whole
     * content of the rule: a prescription is handed over only after a
     * pharmacist has signed it.
     *
     * @var list<string>
     */
    public const BISA_DIPENUHUI = ['diverifikasi', 'dipenuhi', 'dikirim'];

    /**
     * The states a pharmacist may act on.
     *
     * Both are LIVE and neither has been verified: a prescription arrives in
     * `aktif` the moment it is written (`ResepService::buat()` uses the DDL's own
     * `DEFAULT 'aktif'`), and the pharmacy takes it into `diproses`. Nothing
     * after those two is verifiable, because nothing after them can still be
     * signed.
     *
     * @var list<string>
     */
    public const BISA_DIVERIFIKASI = ['aktif', 'diproses'];

    /**
     * Is `$dari` -> `$ke` one of the legal edges?
     *
     * A lookup rather than a comparison chain, so an unknown `$dari` or an
     * unknown `$ke` is a MISS and not a permissive default. Total: it never
     * throws and never reaches the database.
     */
    public function boleh(string $dari, string $ke): bool
    {
        return in_array($ke, self::TRANSISI[$dari] ?? [], true);
    }

    /**
     * Is this one of the eight `resep.status` members at all?
     *
     * The check {@see boleh()} needs and does not perform, separated so a
     * caller can tell "this is not a status" from "this step is not legal" -
     * two different 422s, because the first is a broken caller and the second
     * is a workflow rule.
     */
    public function adalahStatus(string $kandidat): bool
    {
        return array_key_exists($kandidat, self::TRANSISI);
    }

    public function adalahTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public function bisaDipenuhi(string $status): bool
    {
        return in_array($status, self::BISA_DIPENUHUI, true);
    }

    public function bisaDiverifikasi(string $status): bool
    {
        return in_array($status, self::BISA_DIVERIFIKASI, true);
    }

    /**
     * Is this prescription past its validity?
     *
     * `resep.berlaku_sampai` is a `DATE` (`:755`) and NOTHING in the schema
     * reacts to it - no trigger, no generated column, no event - so a
     * prescription whose validity lapsed three days ago can still read `aktif`.
     * The flag is therefore a PHP comparison, and it is INCLUSIVE, matching
     * `ObatInteraksiService`'s date guard: valid through today means still
     * valid today.
     *
     * The stored `kedaluwarsa` state is honoured whatever the date says, so the
     * flag is true for BOTH reasons a prescription can be unusable - which is
     * the honest answer, because an operator who expired a row early and a
     * prescription that aged out are the same fact to whoever reads it.
     *
     * **STATIC, and load-bearing.** `ResepResource` publishes this flag on
     * every surface - the create response, the detail, the history - and
     * `ResepAccess` publishes it beside them. Two implementations of "has this
     * lapsed" that can disagree is how a patient is told their prescription is
     * valid on the list screen and expired on the detail screen.
     */
    public static function kedaluwarsa(Resep $resep): bool
    {
        if ((string) $resep->status === ResepStatus::Kedaluwarsa->value) {
            return true;
        }

        $sampai = $resep->berlaku_sampai;

        // `DateTimeInterface`, not `Carbon`. A `date` cast on a model that
        // hydrates immutably hands back a `Carbon\CarbonImmutable`, which is
        // NOT an `Illuminate\Support\Carbon`, so a concrete-class check here
        // reads a perfectly good date as "no date" and answers `false` for a
        // prescription that lapsed months ago. Probed, not assumed - see
        // `.omo/evidence/task-40-sehatly.md`.
        if (! $sampai instanceof DateTimeInterface) {
            return false;
        }

        return $sampai->format('Y-m-d') < Carbon::today()->toDateString();
    }

    /**
     * Is this prescription closed for good?
     *
     * Two reasons, and the second is the one the DDL forces. A `selesai`,
     * `kedaluwarsa` or `dibatalkan` state is terminal by the map. A `ditolak`
     * verification is terminal by `resep_verifikasi.resep_id` being `UNIQUE`
     * (`:788`): the one verification the row can hold has been spent, so the
     * prescription can never be corrected and re-submitted no matter what the
     * status column happens to hold.
     *
     * **STATIC, for the same reason as {@see kedaluwarsa()}.** A client's
     * "can I still act on this" affordance must not depend on which endpoint
     * answered.
     */
    public static function terminal(Resep $resep): bool
    {
        if (in_array((string) $resep->status, self::TERMINAL, true)) {
            return true;
        }

        if ($resep->relationLoaded('resepVerifikasi')) {
            $verifikasi = $resep->getRelation('resepVerifikasi');

            return $verifikasi instanceof ResepVerifikasi
                && (string) $verifikasi->status === ResepVerifikasiStatus::Ditolak->value;
        }

        return ResepVerifikasi::query()
            ->where('resep_id', $resep->getKey())
            ->where('status', ResepVerifikasiStatus::Ditolak->value)
            ->exists();
    }

    /**
     * Refuse an illegal transition, naming both the step and where the row is.
     *
     * Returns normally for a LEGAL edge: the callers derive their step from
     * {@see TRANSISI}, so a legal one is the ordinary case and the method is
     * "make sure this is allowed, and complain if it is not" rather than a
     * `never` that a bug in a caller would trip. The 422 is the whole point;
     * a `LogicException` on the happy path would have been a second opinion
     * about a map the caller already read.
     *
     * @throws ValidationException when `$dari` -> `$ke` is not a legal edge
     */
    public function pastikan(string $dari, string $ke, string $field = 'status'): void
    {
        if ($this->boleh($dari, $ke)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => [
                'Transisi resep tidak diperbolehkan.',
                sprintf('Status "%s" tidak dapat menjadi "%s".', $dari, $ke),
            ],
        ]);
    }
}
