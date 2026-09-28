<?php

declare(strict_types=1);

namespace App\Services\RekamMedis;

use App\Models\RekamMedis;
use App\Models\User;
use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The single answer to "may this account reach this medical record, and as whom?".
 *
 * ## The ownership rule is todo 32's SHAPE, reused rather than re-derived
 *
 * A `rekam_medis` row names no account. It stores `pasien_id` (:624) and
 * `dokter_id` (:626), each a foreign key to a PROFILE table, and the account is one
 * hop further out: `pasien.user_id` (:220) and `dokter.user_id` (:411). So
 * membership is a two-hop walk, walked through the relations the rest of the
 * application already uses - {@see PasienRecordAccess::ownPasienOrNull()} and
 * {@see PasienRecordAccess::ownDokter()} - rather than through raw joins, so the
 * answer cannot disagree with `KonsultasiAccess`, which asks the same two
 * questions and delegates its patient/doctor resolution to the same two methods.
 *
 * ## The probe is `DB::table()` and NEVER a model, and that is load-bearing
 *
 * {@see probe()} selects three columns and nothing else, through the query
 * builder. A `RekamMedis::query()->first(['id','pasien_id','dokter_id'])` would
 * hydrate a model, trip {@see GuardsMedicalRecordRead}, and throw - and it would
 * also mean the AUTHORISATION check had already opened a record, which is the one
 * thing the access log exists to make visible.
 *
 * So the split is:
 *
 * - the probe reads ownership columns only, hydrates nothing, and is the reason a
 *   404 or a 403 writes ZERO `akses_rekam_medis_log` rows;
 * - {@see RekamMedisAccessLogger::baca()} reads the record itself, and only then
 *   writes the log.
 *
 * `KonsultasiChannelAccess` answers the same question for a consultation with
 * `->exists()`, which is likewise non-hydrating. This is the third shape of the
 * same rule in the codebase and it is the only one that has to be spelled, because
 * the answer needs two columns rather than a boolean.
 *
 * ## 404 for the row, 403 for the caller, and the two are not interchangeable
 *
 * | situation | answer | why |
 * | --- | --- | --- |
 * | the account owns a `pasien` or `dokter` row but the record is not theirs | **404** | a 403 would confirm the record exists, which is a cross-tenant existence oracle over a sequential `BIGINT UNSIGNED` id |
 * | the account owns NEITHER profile row and is not an oversight type | **403** | the refusal is about the caller and discloses nothing about the record |
 * | the id does not exist | **404** | the same answer as "not yours", so the two are indistinguishable |
 *
 * `admin` and `superadmin` are the oversight allowance. `RekamMedisRead::PETUGAS`
 * is read from `users.tipe`'s seven-value ENUM (:139) and both names are members,
 * so the list cannot name an account type the schema does not have.
 */
final class RekamMedisAccess
{
    /**
     * The two account types that may read any record, for oversight.
     *
     * @var list<string>
     */
    public const PETUGAS = ['admin', 'superadmin'];

    public function __construct(
        private readonly PasienRecordAccess $pasien,
    ) {}

    /**
     * The record's ownership triple, or the caller's refusal.
     *
     * @return array{id: int, pasien_id: int, dokter_id: int, tanggal_periksa: string}
     *
     * @throws ModelNotFoundException 404 for "not yours" and for "no such record"
     * @throws AccessDeniedHttpException 403 for an account that owns no profile row
     */
    public function probe(User $caller, int $id): array
    {
        $baris = DB::table('rekam_medis')
            ->where('id', $id)
            ->first(['id', 'pasien_id', 'dokter_id', 'tanggal_periksa']);

        if ($baris === null) {
            throw (new ModelNotFoundException)->setModel(RekamMedis::class, [$id]);
        }

        return [
            'id' => (int) $baris->id,
            'pasien_id' => (int) $baris->pasien_id,
            'dokter_id' => (int) $baris->dokter_id,
            'tanggal_periksa' => RekamMedisReadScope::tanggalAsString($baris->tanggal_periksa),
        ];
    }

    /**
     * The record's side relative to `$caller`, for a READ.
     *
     * The patient side is tried first, then the doctor side, then oversight - the
     * same order `KonsultasiAccess::sisiDanKonsultasi()` uses, so an account that
     * somehow owns both profiles is the patient in both services and the two cannot
     * disagree.
     *
     * @return array{0: string, 1: array{id: int, pasien_id: int, dokter_id: int, tanggal_periksa: string}}
     *                                                                                                      the side (`pasien`, `dokter` or `petugas`) and the ownership triple
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException
     */
    public function sisiUntukBaca(User $caller, int $id): array
    {
        $identitas = $this->probe($caller, $id);

        $pasien = $this->pasien->ownPasienOrNull($caller);
        $dokter = $this->pasien->ownDokter($caller);

        if ($pasien !== null && (int) $pasien->getKey() === $identitas['pasien_id']) {
            return ['pasien', $identitas];
        }

        if ($dokter !== null && (int) $dokter->getKey() === $identitas['dokter_id']) {
            return ['dokter', $identitas];
        }

        if ($this->adalahPetugas($caller)) {
            return ['petugas', $identitas];
        }

        // Neither profile row, and not an oversight type: the refusal is about the
        // caller. `apoteker`, `perawat` and `kurir` all land here, and they all hold
        // no role in `RbacCatalog::ROLES` so no `permission:` gate could have let
        // them through either.
        if ($pasien === null && $dokter === null) {
            throw new AccessDeniedHttpException('Rekam medis hanya dapat diakses oleh pasien, dokter, atau petugas.');
        }

        throw (new ModelNotFoundException)->setModel(RekamMedis::class, [$id]);
    }

    /**
     * The record's ownership triple, and nothing else, for a DOCTOR-ONLY write.
     *
     * `tipe:dokter` in the route middleware has already answered "which account type
     * is this"; what is left is ownership. A `dokter`-typed account with no `dokter`
     * row is 403, because the refusal is about the caller's incomplete profile. A
     * doctor who owns a profile but is not THIS record's doctor is 404, because a
     * 403 would confirm the record exists. A patient who reaches a write route is
     * refused at `tipe:dokter` before this method runs, and lands here as 404 rather
     * than as a leaked 403.
     *
     * @return array{id: int, pasien_id: int, dokter_id: int, tanggal_periksa: string}
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException
     */
    public function untukDokter(User $caller, int $id): array
    {
        $identitas = $this->probe($caller, $id);
        $dokter = $this->pasien->ownDokterOrFail($caller);

        if ((int) $dokter->getKey() !== $identitas['dokter_id']) {
            throw (new ModelNotFoundException)->setModel(RekamMedis::class, [$id]);
        }

        return $identitas;
    }

    /**
     * Is this account one of the two oversight types?
     *
     * A `tipe` value is a hard fact about the `users` row, so it is read off the
     * authenticated principal rather than re-queried - the same reasoning
     * `App\Support\Rbac\Caller` gives.
     */
    public function adalahPetugas(User $caller): bool
    {
        return in_array((string) $caller->tipe, self::PETUGAS, true);
    }

    /**
     * The `tujuan_akses` value this side of the record reads for.
     *
     * `akses_rekam_medis_log.tujuan_akses` is a FIVE-value ENUM (:1151):
     * `perawatan`, `klaim`, `audit`, `pasien_sendiri`, `kepentingan_hukum`. Three of
     * them are reachable from this service and two are not:
     *
     * - `pasien_sendiri` - the patient reading their own record.
     * - `perawatan` - the record's own doctor.
     * - `audit` - an oversight account.
     *
     * `klaim` and `kepentingan_hukum` have NO producer, and that is a DDL fact
     * rather than an omission: `RbacCatalog::USER_TYPES` holds seven `users.tipe`
     * values (:139) and not one of them is a claims officer or a legal officer, so
     * there is no account this application can authenticate that would deserve
     * either value. Inventing an eighth `tipe` is a DDL change, which is forbidden.
     *
     * The value is DERIVED and never supplied by the wire, which is a deliberate
     * departure from the plan's `log(int $rekamMedisId, User $accessor, string
     * $tujuan)`. A caller-chosen purpose is the one input an attacker would choose:
     * labelling a snooping read `perawatan` is the single most dangerous value in
     * the ENUM, because `perawatan` is what an auditor reads as legitimate care.
     * The plan's parameter is kept on the logger's `log()` so the method name and
     * shape survive, but the only value that ever reaches it is this one.
     */
    public function tujuanUntuk(string $sisi): string
    {
        return match ($sisi) {
            'pasien' => 'pasien_sendiri',
            'dokter' => 'perawatan',
            default => 'audit',
        };
    }
}
