<?php

declare(strict_types=1);

namespace App\Services\Resep;

use App\Models\Resep;
use App\Models\ResepVerifikasi;
use App\Models\User;
use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The one answer to "may this account read or verify this prescription?".
 *
 * ## Three readers, and the disjunction a route gate cannot express
 *
 * The plan's todo 40 says `GET /api/v1/resep/{id}` "is accessible to the
 * prescribing doctor, the patient, and a pharmacist". That is three SEPARATE
 * ownership questions, not one, and `resep.lihat` is held by four roles in
 * `RbacCatalog::ROLE_PERMISSIONS` - `pasien`, `dokter`, `apoteker` and
 * `superadmin` - so a route gate can only answer "does this account hold the
 * code", never "is this row its prescription". The gate is on the route; the
 * row is settled here.
 *
 * | caller | answer |
 * | --- | --- |
 * | the patient who owns `resep.pasien_id` | the row |
 * | the doctor who wrote `resep.dokter_id` | the row |
 * | `apoteker`, or an oversight account | any prescription |
 * | owns a profile, but none of the above | **404** |
 * | owns NO profile row at all | **403** |
 *
 * ## 404 for the row, 403 for the caller, and the two are not interchangeable
 *
 * The same split `PasienRecordAccess` and `KonsultasiAccess` document, and the
 * same reason: a 403 on a row that exists confirms it exists, which over a
 * sequential `BIGINT` key is a cross-tenant existence oracle. A caller that
 * owns no profile is refused about ITSELF, which discloses nothing about any
 * prescription.
 *
 * ## The oversight half is a NAMED list, read off `users.tipe`
 *
 * `TIPE_APOTEK` is spelled here rather than re-derived at a call site, and the
 * test asserts every member is a `RbacCatalog::USER_TYPES` value - the same
 * discipline `KonsultasiAccess::TIPE_PETUGAS` follows, and the reason a
 * `tipe:apoteker,superadmin` gate on the READ route is not used instead: a
 * route gate is a conjunction, this is a disjunction, and the two sides read
 * different columns.
 *
 * `admin` is deliberately absent from the read disjunction and from
 * `RbacCatalog::ROLE_PERMISSIONS['apoteker']`: it holds NO `resep.lihat` at all,
 * so it is refused by the middleware before this class runs. Listing it here
 * would be dead code that reads as a grant.
 */
final class ResepAccess
{
    /**
     * The account types that may read ANY prescription.
     *
     * `apoteker` is the pharmacy queue: a pharmacist's job is to see
     * prescriptions they did not write. `superadmin` is the oversight account
     * the plan's other read surfaces grant, and it holds `resep.lihat` without
     * owning a `pasien` or a `dokter` row.
     *
     * @var list<string>
     */
    public const TIPE_APOTEK = ['apoteker', 'superadmin'];

    public function __construct(private readonly PasienRecordAccess $pasien) {}

    /**
     * Is this account one of the pharmacy-or-oversight types?
     */
    public function adalahApotek(User $caller): bool
    {
        return in_array((string) $caller->tipe, self::TIPE_APOTEK, true);
    }

    /**
     * The prescription for a READ, or a refusal.
     *
     * The row is loaded with the three relations every read publishes, so a
     * detail response cannot N+1 on the verification, the patient or the
     * doctor.
     *
     * @throws AccessDeniedHttpException|ModelNotFoundException
     */
    public function untukBaca(User $caller, int $id): Resep
    {
        // The pharmacy half first, and it is a `findOrFail`: the tenant filter
        // IS the query for the other two halves, and there is no tenant here.
        if ($this->adalahApotek($caller)) {
            return $this->muatan($id);
        }

        $pasien = $this->pasien->ownPasienOrNull($caller);
        $dokter = $this->pasien->ownDokter($caller);

        if ($pasien !== null) {
            $row = $this->terkunci($id, 'pasien_id', (int) $pasien->getKey());

            if ($row !== null) {
                return $this->muatan((int) $row->getKey());
            }
        }

        if ($dokter !== null) {
            $row = $this->terkunci($id, 'dokter_id', (int) $dokter->getKey());

            if ($row !== null) {
                return $this->muatan((int) $row->getKey());
            }
        }

        // Owns a profile, but this is not its prescription. 403 would confirm
        // the row exists.
        if ($pasien === null && $dokter === null) {
            throw new AccessDeniedHttpException('Endpoint ini hanya untuk akun pasien, dokter, atau apoteker.');
        }

        throw (new ModelNotFoundException)->setModel(Resep::class, [$id]);
    }

    /**
     * The prescription a pharmacist may VERIFY, or a refusal.
     *
     * The pharmacy filter is the same as for a read, plus one rule the plan
     * names explicitly and that a route gate cannot express: **a doctor may not
     * verify their own prescription.** `tipe:apoteker` on the route already
     * excludes a `dokter`-typed account, but an account can be BOTH - a real
     * possibility in this schema, because `dokter.user_id` (`:411`) and
     * `users.tipe` (`:139`) are independent columns and nothing checks that a
     * `dokter` row's user is typed `dokter`. A pharmacist who also practises
     * would otherwise be signing off their own prescription, and the separation
     * of duties the whole table exists for would be one `tipe` value away from
     * gone.
     *
     * @throws AccessDeniedHttpException|ModelNotFoundException
     */
    public function untukVerifikasi(User $caller, int $id): Resep
    {
        $resep = $this->untukBaca($caller, $id);

        $dokter = $this->pasien->ownDokter($caller);

        if ($dokter !== null && (int) $dokter->getKey() === (int) $resep->dokter_id) {
            throw new AccessDeniedHttpException('Dokter tidak dapat memverifikasi resep miliknya sendiri.');
        }

        return $resep;
    }

    /**
     * The caller's own prescriptions, newest first, paginated.
     *
     * The tenant filter IS the query, so another patient's prescription is
     * simply absent rather than refused - the property
     * `PasienRecordAccess::bookingQuery()` documents, applied to a table the
     * plan's history endpoint is named after.
     *
     * The order is `tanggal_resep DESC, id DESC` rather than `dibuat_at`:
     * `tanggal_resep` is the `DATETIME` the clinician means by "when this was
     * prescribed" (`:754`), `dibuat_at` is a `TIMESTAMP` (`:759`) that MySQL
     * converts between sessions, and `id` breaks a tie so a burst cannot
     * repeat or drop a row between two pages. The same reasoning
     * `SuratKeteranganService::daftarUntukPasien()` gives, on a column there
     * with one second of resolution.
     */
    public function riwayat(User $caller, ?string $status, int $perPage, int $page): LengthAwarePaginator
    {
        $pasien = $this->pasien->ownPasien($caller);

        $query = Resep::query()
            ->whereBelongsTo($pasien)
            ->with(['resepItem', 'resepVerifikasi.apotekerUser'])
            ->orderByDesc('tanggal_resep')
            ->orderByDesc('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($this->pasien->perPage($perPage), ['*'], 'page', $page)
            ->withQueryString();
    }

    /**
     * The pharmacist's verification queue, newest first, paginated.
     *
     * ## NO tenant filter, and that is the definition of the queue
     *
     * {@see riwayat()} begins with `ownPasien()`; this method deliberately does
     * not. `TIPE_APOTEK`'s own docblock gives the reason: a pharmacist's job is
     * to see prescriptions they did not write, so the queue is the one read in
     * this module that is intentionally cross-patient. There is no "whose row"
     * question left to ask, which is why this method takes no `User`: the
     * audience is settled by the route's `tipe:apoteker` +
     * `permission:resep.verifikasi` pair - the SAME pair the verify write
     * carries - and a `User` parameter re-checked here could only disagree with
     * it (`TIPE_APOTEK` includes `superadmin`, which the route refuses).
     *
     * ## `$status` is one verifiable state, or null for both
     *
     * `aktif` and `diproses` are the only states a prescription can still be
     * signed from ({@see ResepStateMachine::BISA_DIVERIFIKASI}), so the
     * unfiltered queue is exactly that `whereIn`. The filter's closed set is
     * enforced upstream in `AntreanResepRequest`; this method trusts the value
     * it is handed the same way {@see riwayat()} trusts `RiwayatResepRequest`,
     * and the `whereIn` shape means an out-of-set value could only ever narrow
     * to one status and never widen the query.
     *
     * ## `withCount` and `with('resepVerifikasi')` are load-bearing
     *
     * `ResepAntreanResource` publishes `jumlah_item` and `terminal`, and
     * `ResepStateMachine::terminal()` reads the eager-loaded verification for
     * every non-terminal row - which is every row here. Both relations are
     * loaded once for the page rather than once per row.
     *
     * The order is `tanggal_resep DESC, id DESC` for the reason {@see riwayat()}
     * documents at length: `tanggal_resep` is the clinician's own `DATETIME`,
     * and `id` breaks a tie so a burst cannot repeat or drop a row between two
     * pages.
     */
    public function antrean(?string $status, int $perPage, int $page): LengthAwarePaginator
    {
        $query = Resep::query()
            ->with('resepVerifikasi')
            ->withCount('resepItem')
            ->whereIn(
                'status',
                $status === null ? ResepStateMachine::BISA_DIVERIFIKASI : [$status],
            )
            ->orderByDesc('tanggal_resep')
            ->orderByDesc('id');

        return $query->paginate($this->pasien->perPage($perPage), ['*'], 'page', $page)
            ->withQueryString();
    }

    /**
     * Has this prescription ALREADY been verified?
     *
     * The pre-check in front of the UNIQUE key, and the reason a 422 can be
     * returned with a readable message rather than a driver string. It is NOT
     * the mechanism - the mechanism is the constraint at
     * `telemedicine_test.sql:788` - and the test proves both: this answers the
     * common case, and a `creating` hook plants the racing row in between so the
     * constraint is what actually stops the second INSERT.
     */
    public function sudahDiverifikasi(int $resepId): ?ResepVerifikasi
    {
        return ResepVerifikasi::query()->where('resep_id', $resepId)->first();
    }

    /**
     * Is this prescription past its validity?
     *
     * A delegate, not an implementation: the rule is
     * {@see ResepStateMachine::kedaluwarsa()} because `ResepResource` publishes
     * the same flag on every surface and two implementations of "has this
     * lapsed" that can disagree is how a patient is told their prescription is
     * valid on the list screen and expired on the detail screen.
     */
    public function kedaluwarsa(Resep $resep): bool
    {
        return ResepStateMachine::kedaluwarsa($resep);
    }

    /**
     * Is this prescription closed for good?
     *
     * A delegate, for the same reason as {@see kedaluwarsa()}. True for a
     * terminal status AND for a `ditolak` verification, which is terminal by
     * `resep_verifikasi.resep_id` being `UNIQUE` (`:788`).
     */
    public function terminal(Resep $resep): bool
    {
        return ResepStateMachine::terminal($resep);
    }

    /**
     * Load one prescription with everything the detail and the history publish.
     */
    private function muatan(int $id): Resep
    {
        return Resep::query()
            ->whereKey($id)
            ->with(['resepItem', 'resepVerifikasi.apotekerUser', 'pasien.user', 'dokter.user'])
            ->firstOrFail();
    }

    /**
     * One prescription, scoped by a single ownership column.
     *
     * The tenant filter IS the query, so another party's row is simply not
     * found rather than refused.
     */
    private function terkunci(int $id, string $kolom, int $profileId): ?Resep
    {
        return Resep::query()->whereKey($id)->where($kolom, $profileId)->first();
    }
}
