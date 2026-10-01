<?php

declare(strict_types=1);

namespace App\Services\Konsultasi;

use App\Models\Konsultasi;
use App\Models\Pasien;
use App\Models\User;
use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The single answer to "may this account reach this konsultasi, and as whom?".
 *
 * ## One ownership rule, and it is todo 31's
 *
 * A konsultasi names no account. `konsultasi` stores `pasien_id`
 * (`telemedicine_test.sql:539`) and `dokter_id` (`:540`), each a foreign key to a
 * PROFILE table (`:558`, `:559`), and the account is one hop further out:
 * `pasien.user_id` (`:220`) and `dokter.user_id` (`:411`). So membership is a
 * two-hop walk, and it is walked here by **delegating to
 * {@see KonsultasiChannelAccess::allows()}** rather than by writing the comparison
 * a second time. That delegation is the reason this class exists at all instead
 * of four inline `whereHas()` calls: the rule already has one implementation, and
 * the HTTP surface must not be able to decide differently from the socket
 * surface about the same three inputs.
 *
 * `PasienRecordAccess` is reused too, but for a different question. It owns "does
 * this account own a patient profile, and a doctor profile?", and
 * {@see PasienRecordAccess::ownPasienOrNull()} /
 * {@see PasienRecordAccess::ownDokter()} are the two nullable twins this class
 * needs - a `superadmin` owns neither, and the 403 has to be answerable for that.
 * What `PasienRecordAccess` does NOT own is konsultasi scoping, and no
 * `konsultasiQuery()` was added to it: a `konsultasi` row is not reachable from a
 * `pasien` root by a single scoped query, because the doctor half of the rule has
 * no `pasien`-rooted form. Adding one would have created a third copy of a rule
 * that already has two consumers.
 *
 * ## 404 for the row, 403 for the caller, and the two are not interchangeable
 *
 * The same split `PasienRecordAccess` documents, applied to this table:
 *
 * | situation | answer | why |
 * | --- | --- | --- |
 * | the account is not a party and not `admin`/`superadmin` | **404** | a 403 would confirm the row exists, which is a cross-tenant existence oracle |
 * | the account is not a party and owns no profile row at all | **403** | the refusal is about the caller and discloses nothing about the konsultasi |
 *
 * The 403 is reachable for `superadmin`, which is the one role that holds
 * `konsultasi.chat` (`RbacCatalog::PERMISSIONS`, `konsultasi.chat`) while owning
 * neither profile row. A `perawat` or `kurir` never reaches this class: they hold
 * no role, so `permission:` answers 403 in the middleware first.
 *
 * ## The plan's `admin`/`superadmin` allowance, and where it stops
 *
 * The plan's todo 32 says `GET /konsultasi/{id}` "must be scoped so only the
 * session's patient, its doctor, or an `admin`/`superadmin` can read it". That
 * allowance is implemented once, in {@see findForRead()}, and is read off
 * {@see TIPE_PETUGAS} rather than being spelled at four call sites. Both names
 * are values of the `users.tipe` ENUM at `telemedicine_test.sql:139` and members
 * of `RbacCatalog::USER_TYPES`, which `KonsultasiTest` asserts, so the list cannot
 * name an account type the schema does not have.
 *
 * It applies to the **read** paths only. A `superadmin` may read a konsultasi,
 * may not send a message in it ({@see sisiDanKonsultasi()} answers 403, because
 * the account owns no profile row), and may not complete it - the two doctor-only
 * routes carry `tipe:dokter`, which `tipe:superadmin` does not satisfy. That
 * asymmetry is the catalogue's data rather than a choice made here: `superadmin`
 * holds all 24 permissions but is not a `dokter`.
 *
 * **The REST allowance and the realtime allowance therefore differ**, and that is
 * reported rather than papered over: `KonsultasiChannelAccess` - the rule
 * `routes/channels.php` delegates to - admits only the two parties, so an `admin`
 * can read a transcript over HTTP and cannot join its socket. Closing that would
 * mean editing todo 31's rule, which this todo does not own. The narrower of the
 * two is the realtime one, so the discrepancy under-grants rather than
 * over-grants.
 */
final class KonsultasiAccess
{
    /**
     * The two account types the plan lets read any konsultasi, for oversight.
     *
     * @var list<string>
     */
    public const TIPE_PETUGAS = ['admin', 'superadmin'];

    public function __construct(
        private readonly KonsultasiChannelAccess $channel,
        private readonly PasienRecordAccess $pasien,
    ) {}

    /**
     * The konsultasi for a READ, or a 404.
     *
     * The party test is {@see KonsultasiChannelAccess::allows()} verbatim. The row
     * is then loaded with the four relations every read publishes, so a
     * konsultasi response cannot N+1.
     *
     * @throws ModelNotFoundException, rendered as the 404 envelope by `bootstrap/app.php`
     */
    public function findForRead(User $caller, int $id): Konsultasi
    {
        if (! $this->channel->allows($caller, $id) && ! $this->adalahPetugas($caller)) {
            throw (new ModelNotFoundException)->setModel(Konsultasi::class, [$id]);
        }

        return $this->muatan($id);
    }

    /**
     * The konsultasi and the side the caller is on, for a WRITE.
     *
     * A chat message is authored by one of the two parties, so a write has to know
     * WHICH one - `konsultasi_chat.pengirim_tipe` is a three-value ENUM
     * (`telemedicine_test.sql:567`) and only the profile rows can say which of the
     * two applies. The patient side is tried first, then the doctor side, which is
     * the same order `BookingService::batalkan()` uses, so an account that somehow
     * owns both profiles is the patient when cancelling a booking and the patient
     * when sending a message.
     *
     * The scoping is `where('pasien_id', ...)` / `where('dokter_id', ...)` rather
     * than a `whereHas()` walk, and that is deliberate: this method already knows
     * which side it is looking for, so the narrower column comparison answers the
     * question, and it cannot disagree with {@see findForRead()} because that one is
     * the boolean "is a party at all" delegated to todo 31's rule.
     *
     * ## 403 and 404, and which of the two a non-party gets
     *
     * An account that owns NEITHER profile row gets 403 - the refusal is about the
     * caller. An account that owns one but is not party to THIS konsultasi gets
     * 404, because a 403 would confirm the row exists.
     *
     * @return array{0: string, 1: Konsultasi} the side (`pasien` or `dokter`) and the row
     *
     * @throws AccessDeniedHttpException|ModelNotFoundException
     */
    public function sisiDanKonsultasi(User $caller, int $id): array
    {
        $pasien = $this->pasien->ownPasienOrNull($caller);
        $dokter = $this->pasien->ownDokter($caller);

        if ($pasien !== null) {
            $row = $this->terkunci($id, 'pasien_id', $pasien->getKey());

            if ($row !== null) {
                return ['pasien', $row];
            }
        }

        if ($dokter !== null) {
            $row = $this->terkunci($id, 'dokter_id', $dokter->getKey());

            if ($row !== null) {
                return ['dokter', $row];
            }
        }

        if ($pasien === null && $dokter === null) {
            throw new AccessDeniedHttpException('Endpoint ini hanya untuk akun pasien atau dokter.');
        }

        throw (new ModelNotFoundException)->setModel(Konsultasi::class, [$id]);
    }

    /**
     * The konsultasi for a DOCTOR-ONLY write, or a refusal.
     *
     * `tipe:dokter` in the route middleware has already answered "which account
     * type is this"; what is left is the ownership question. A `dokter`-typed
     * account with no `dokter` row is 403, because the refusal is about the
     * caller's incomplete profile. A doctor who owns a profile but is not THIS
     * konsultasi's doctor is 404, because a 403 would confirm the row exists.
     *
     * @throws AccessDeniedHttpException|ModelNotFoundException
     */
    public function untukDokter(User $caller, int $id): Konsultasi
    {
        $dokter = $this->pasien->ownDokterOrFail($caller);

        $row = $this->terkunci($id, 'dokter_id', $dokter->getKey());

        if ($row === null) {
            throw (new ModelNotFoundException)->setModel(Konsultasi::class, [$id]);
        }

        return $row;
    }

    /**
     * The patient profile the caller owns, or a 403.
     *
     * `POST /konsultasi/mulai` is a patient action, and the question it needs is
     * exactly `PasienRecordAccess::ownPasien()`'s: an account with no `pasien` row
     * cannot start a konsultasi, whoever it is. That includes `dokter` and
     * `admin`, which is the point of delegating rather than re-querying.
     *
     * @throws AccessDeniedHttpException
     */
    public function ownPasien(User $caller): Pasien
    {
        return $this->pasien->ownPasien($caller);
    }

    /**
     * Is this account one of the two oversight types?
     */
    public function adalahPetugas(User $caller): bool
    {
        return in_array((string) $caller->tipe, self::TIPE_PETUGAS, true);
    }

    /**
     * Load one konsultasi with the four relations every read publishes.
     *
     * `konsultasiBaca` is F08's per-participant read state. It is eager-loaded
     * here rather than at one call site so `GET /konsultasi/{id}` and every
     * lifecycle write response publish the same `baca` block from the same
     * `KonsultasiResource`, and none of them pays a second query.
     */
    public function muatan(int $id): Konsultasi
    {
        return Konsultasi::query()
            ->whereKey($id)
            ->with(['pasien.user', 'dokter.user', 'booking', 'konsultasiBaca'])
            ->firstOrFail();
    }

    /**
     * One konsultasi, scoped by a single ownership column.
     *
     * The tenant filter IS the query, so another party's row is simply not found -
     * the same property `PasienRecordAccess::bookingOrFail()` documents.
     */
    private function terkunci(int $id, string $kolom, int $profileId): ?Konsultasi
    {
        return Konsultasi::query()
            ->whereKey($id)
            ->where($kolom, $profileId)
            ->first();
    }
}
