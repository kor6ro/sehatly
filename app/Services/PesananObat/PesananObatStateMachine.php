<?php

declare(strict_types=1);

namespace App\Services\PesananObat;

use App\Enums\PesananObatStatus;
use Illuminate\Validation\ValidationException;

/**
 * The one place a `pesanan_obat.status` may change, and the 6x6 map that says
 * which changes are legal.
 *
 * ## The vocabulary is DERIVED, and the derivation is the whole point
 *
 * `pesanan_obat.status` is a six-value ENUM at `telemedicine_test.sql:810`
 * and `:811`:
 *
 * ```
 * status ENUM('menunggu_pembayaran','diproses','siap','sedang_dikirim','selesai','dibatalkan')
 *        NOT NULL DEFAULT 'menunggu_pembayaran',
 * ```
 *
 * MySQL's ENUM accepts any of its six members on write, so a skipped step - an
 * order that goes `menunggu_pembayaran` straight to `selesai` without ever
 * being packed - is a value the DATABASE cannot refuse. The only place that can
 * refuse is here, which is why the map is a single named constant rather than a
 * chain of comparisons spread across a service, a controller and a command that
 * have to agree with each other and with the DDL.
 *
 * ## The map, and the two shapes of edge
 *
 * ```
 * menunggu_pembayaran -> diproses, dibatalkan
 * diproses            -> siap, dibatalkan
 * siap                -> sedang_dikirim, dibatalkan
 * sedang_dikirim      -> selesai, dibatalkan
 * selesai             -> (terminal)
 * dibatalkan          -> (terminal)
 * ```
 *
 * 1. **The happy path is a PATH.** `menunggu_pembayaran -> diproses -> siap
 *    -> sedang_dikirim -> selesai`, and nothing skips a step. `menunggu_
 *    pembayaran -> siap` is refused, which is what makes "the pharmacy packed
 *    this parcel" an observable fact rather than an assumption.
 * 2. **`dibatalkan` is reachable from every LIVE state**, because an order can
 *    be called off before it ships and the DDL gives no other column that
 *    could record it. Nothing is reachable FROM `selesai` or `dibatalkan`.
 *
 * There is no `kedaluwarsa` member here even though `resep.status` has one
 * (`:751`-`:752`): an order that is never paid is not "expired", it is
 * `menunggu_pembayaran` forever, and inventing a seventh member would put a
 * value in the column the DDL does not declare.
 *
 * ## WHY THE TRACKING TRAIL REUSES THIS VOCABULARY
 *
 * `pesanan_obat_tracking.status` is `VARCHAR(100) NOT NULL` (`:822`) - free
 * text, no ENUM, no CHECK - while sharing a NAME with the order's ENUM. That
 * asymmetry is the sharpest trap in this batch of the DDL: **any** string is
 * writable there, so `in_transit`, `kirim`, a typo, or a `dibatalkan` row
 * sitting beside a `selesai` one are all perfectly representable, and an order's
 * state read from the trail is a state read from free text.
 *
 * `docs/schema-notes.md` (the "pesanan_obat_tracking.status is a VARCHAR(100)"
 * entry) requires this todo to validate that column in the application and to
 * read the order's state from `pesanan_obat.status`, never from the trail. So:
 *
 * - the trail is an APPEND-ONLY history of the order's own status changes, and
 *   every row it holds carries a value from {@see PesananObatStatus};
 * - the order's current state is ALWAYS read from `pesanan_obat.status`, and
 *   never inferred by taking the last trail row.
 *
 * That is a narrowing of what the column permits, and it is deliberate: a
 * courier's own vocabulary (`in_transit`) has nowhere to live in this schema
 * anyway, because there is no `pesanan_obat` status meaning it, so accepting it
 * would record a state no UI has a badge for and no state machine can advance
 * from.
 *
 * ## The refusal is a 422 with TWO messages on ONE field
 *
 * `ValidationException` collapses to the envelope's `errors` map, and
 * `ApiResponse::error()` casts it to an object so an empty set encodes as `{}`.
 * Two messages on `status` is the case the envelope contract names: the caller
 * broke two independent rules - the step is illegal, AND this step is not
 * reachable from where the row is - and a client is told both. Filed on
 * `status` because that is the field the caller actually sent.
 */
final class PesananObatStateMachine
{
    /**
     * The legal edges, keyed by the state they leave.
     *
     * The keys are asserted against `PesananObatStatus::nilai()` by the test,
     * so a value added to the ENUM without a decision here fails the suite
     * instead of becoming unreachable.
     *
     * @var array<string, list<string>>
     */
    public const TRANSISI = [
        'menunggu_pembayaran' => ['diproses', 'dibatalkan'],
        'diproses' => ['siap', 'dibatalkan'],
        'siap' => ['sedang_dikirim', 'dibatalkan'],
        'sedang_dikirim' => ['selesai', 'dibatalkan'],
        'selesai' => [],
        'dibatalkan' => [],
    ];

    /**
     * The states nothing leads out of.
     *
     * A delivered order and a cancelled one are both final: there is no
     * `pesanan_obat` status meaning "the parcel came back", so the only honest
     * representation of a failed delivery is a `dibatalkan` order rather than
     * reopening a `selesai` one.
     *
     * @var list<string>
     */
    public const TERMINAL = ['selesai', 'dibatalkan'];

    /**
     * The states an order may be advanced to by the pharmacy or the courier.
     *
     * `menunggu_pembayaran` is absent because an order is not "advanced" INTO
     * it: it is born there by
     * {@see \App\Services\PesananObat\PesananObatService::buat()}.
     *
     * @var list<string>
     */
    public const BISA_DIPAKAI = ['diproses', 'siap', 'sedang_dikirim', 'selesai', 'dibatalkan'];

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
     * Is this one of the six `pesanan_obat.status` members at all?
     *
     * The check {@see boleh()} needs and does not perform, separated so a
     * caller can tell "this is not a status" from "this step is not legal" -
     * two different 422s, because the first is a broken caller and the second is
     * a workflow rule.
     */
    public function adalahStatus(string $kandidat): bool
    {
        return in_array($kandidat, PesananObatStatus::nilai(), true);
    }

    public function adalahTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * Is this a value `pesanan_obat_tracking.status` may hold?
     *
     * The narrowing described in the class docblock, made into a predicate so
     * a trail row cannot be written with a value the order state machine could
     * never reach. Deliberately NOT a `Rule::in()` at the request boundary
     * alone: the trail is written by the service on a status transition, so the
     * gate has to live where the transition is validated.
     */
    public function bolehDilacak(string $status): bool
    {
        return $this->adalahStatus($status);
    }

    /**
     * Refuse an illegal transition, naming both the step and where the row is.
     *
     * Returns normally for a LEGAL edge: the callers derive their step from
     * {@see TRANSISI}, so a legal one is the ordinary case and this method is
     * "make sure this is allowed, and complain if it is not" rather than a
     * `never` that a bug in a caller would trip. The 422 is the whole point; a
     * `LogicException` on the happy path would have been a second opinion about
     * a map the caller already read.
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
                'Transisi status pesanan tidak diperbolehkan.',
                sprintf('Status "%s" tidak dapat menjadi "%s".', $dari, $ke),
            ],
        ]);
    }
}
