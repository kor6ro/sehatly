<?php

declare(strict_types=1);

namespace App\Services\Pasien;

use App\Models\Booking;
use App\Models\Dokter;
use App\Models\Pasien;
use App\Models\PasienAlergi;
use App\Models\PasienAnggotaKeluarga;
use App\Models\PesananObat;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The one place that answers "may this caller act on this patient record?".
 *
 * ## The rule, in full
 *
 * ```
 * 1. ownPasien(User $user): Pasien
 *      pas = Pasien::where('user_id', $user.id).first()
 *      null -> AccessDeniedHttpException, i.e. 403
 *
 * 2. every child row is reached ONLY as
 *      Child::whereBelongsTo($pas)      -- an explicit where on the foreign key
 *      or $pas->relation()              -- which compiles to the same thing
 *
 * 3. a row addressed by {id} that is not found under that scope is a 404
 *    (ModelNotFoundException), never a 403
 * ```
 *
 * **Why the root is a lookup and not a claim.** `pasien.user_id` is
 * `BIGINT UNSIGNED NOT NULL UNIQUE` (`telemedicine_test.sql:220`), so at most one
 * `pasien` row can name a given `users` row. That single uniqueness is what makes "your
 * own record" decidable at all: there is never a question of which of several rows
 * belongs to the caller, and there is no list to page through. Everything below it -
 * family members, allergies, bookings, prescriptions, invoices - is reachable only
 * through that one root, so a rule that holds at the root holds everywhere.
 *
 * ## 403 for the caller, 404 for the row, and the two are not interchangeable
 *
 * | situation | answer | why |
 * |---|---|---|
 * | the account is not a patient account, or has no `pasien` row | **403** | the refusal is about the *caller* and discloses nothing about any other patient's data |
 * | the row exists but belongs to a different patient | **404** | a 403 would confirm the row exists, which is a cross-tenant existence oracle |
 *
 * The plan's todo 21 states the second rule explicitly ("receives 404 (not 403, not
 * 200) so existence is not leaked across tenants"), and it is the same rule
 * `AuthController::devicesDestroy()` already applies to another account's
 * `user_devices.device_id`. Applying it here costs nothing and is the only choice that
 * is safe under enumeration.
 *
 * ## `whereBelongsTo` is the explicit form, on purpose
 *
 * `$pasien->pasienAlergi()` would produce equivalent SQL, but it spreads the scope
 * across four call sites where a reader has to resolve the relation to see the filter.
 * Writing `PasienAlergi::query()->whereBelongsTo($pasien)` in {@see alergiQuery()} and
 * the same for the family query makes the tenant filter the first thing on the query,
 * and it is the framework's own API for exactly this
 * (`Illuminate\Database\Eloquent\Concerns\QueriesRelationships::whereBelongsTo`, which
 * resolves the `BelongsTo` relationship name from the related model when none is
 * given). Future code that queries `pasien_alergi` directly instead of through here is
 * then visibly the odd one out.
 *
 * ## 23 bare columns have no relation, and none of them is used here
 *
 * `pasien_alergi.dicatat_oleh_user_id` (`:281`) carries a reference-shaped name and
 * **no** foreign key, which is why `PasienAlergi` has no `dicatatOleh()` relation. This
 * class therefore writes and reads the raw id and says so; inventing a `belongsTo(User)`
 * would be a guess about a schema fact. The same applies to `pasien.provinsi_id`,
 * `pasien.kabupaten_kota_id`, `pasien.kecamatan_id` and `pasien.kelurahan_id`, which
 * have no relations either - they are validated with `Rule::exists` in the FormRequest
 * instead, which is the only check the schema permits.
 *
 * ## No `permission:` and no `tipe:` on any route this serves
 *
 * Read against `RbacCatalog::PERMISSIONS` in full, the 24 codes name booking, jadwal,
 * konsultasi, rekam_medis, surat_keterangan, resep, obat, pesanan, pembayaran, promo,
 * notifikasi, audit, pdp and dokter actions - **none** of them names a patient profile,
 * a family member or an allergy. `EnsurePermission` throws a `LogicException` (a 500)
 * for an unknown code, and adding a code to the catalogue is a policy change in
 * `app/Support/Rbac/`, which is not this todo's to make.
 *
 * `tipe:pasien` is the one gate that *would* resolve, and it is deliberately not used:
 * it answers "which account type is this", which cannot express "this row is yours",
 * and it would be a second, strictly weaker gate in front of the real one. A
 * `pasien`-typed account with no `pasien` row passes `tipe:pasien` and would then reach
 * step 1 and be refused with a 403 anyway - so the service is load-bearing regardless,
 * and two gates answering one question is one more thing to keep in sync.
 *
 * `perawat` and `kurir` are real `users.tipe` values that hold **no** role, so any
 * `permission:` code would permanently lock those two account types out of every route
 * carrying it. That is not this todo's problem - neither type owns a `pasien` row - but
 * it is why the rule is a data check rather than a grant.
 */
final class PasienRecordAccess
{
    /**
     * The largest page any list endpoint will serve, per the plan's todo 21.
     */
    public const PER_PAGE_MAX = 100;

    /**
     * The page size a list request gets when it asks for none.
     *
     * Chosen rather than the cap because a patient has a handful of family members and
     * allergies, not thousands, and 15 is the conventional default for a list whose
     * size is unknown. The cap above is still what protects a client that asks for
     * everything.
     */
    public const PER_PAGE_DEFAULT = 15;

    /**
     * The `pasien` row the caller owns, or a 403.
     *
     * The `SoftDeletes` global scope on `Pasien` means a soft-deleted row is reported
     * exactly like one that never existed, so a deleted patient profile cannot be
     * resurrected through a still-live account.
     *
     * The query goes through the model rather than `DB::table()` even though the model
     * costs a little more, because the soft-delete scope lives on the model and skipping
     * it to save a lookup would make a deleted profile writable.
     *
     * @throws AccessDeniedHttpException when the account owns no `pasien` row
     */
    public function ownPasien(User $user): Pasien
    {
        $pasien = $this->ownPasienOrNull($user);

        if ($pasien === null) {
            throw new AccessDeniedHttpException('Endpoint ini hanya untuk akun pasien.');
        }

        return $pasien;
    }

    /**
     * The `pasien` row the caller owns, or `null`.
     *
     * The nullable twin of {@see ownPasien()}, exactly as {@see ownDokter()} is
     * the nullable twin of {@see ownDokterOrFail()}. It exists because a caller
     * can legitimately own NEITHER profile row and the caller still needs to be
     * told which case it is: `KonsultasiAccess::sisiDanKonsultasi()` asks the
     * patient question and the doctor question in turn, and may only answer 403
     * ("this account owns no profile at all") once BOTH have come back null. A
     * 403 thrown by {@see ownPasien()} on the first miss would make that
     * distinction unrepresentable, and the two answers are not the same: 403 is
     * about the caller, 404 is about the row.
     *
     * The query is the one {@see ownPasien()} already ran, extracted rather than
     * re-stated, so the two cannot drift about the `SoftDeletes` scope.
     */
    public function ownPasienOrNull(User $user): ?Pasien
    {
        return Pasien::query()
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * The caller's `dokter` row, or `null`.
     *
     * Used only by `GET /api/v1/me`, which reports whichever of the two a doctor account
     * happens to own. A doctor account with no `dokter` row is not an authorisation
     * failure - it is an incomplete profile - so this returns `null` and the caller
     * publishes `null` rather than refusing the whole response. Every patient endpoint
     * uses {@see ownPasien()} instead, where a missing row genuinely is a 403.
     */
    public function ownDokter(User $user): ?Dokter
    {
        return Dokter::query()
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * Every `pasien_anggota_keluarga` row of one patient, and nothing else.
     *
     * @return Builder<PasienAnggotaKeluarga>
     */
    public function anggotaKeluargaQuery(Pasien $pasien): Builder
    {
        return PasienAnggotaKeluarga::query()->whereBelongsTo($pasien);
    }

    /**
     * Every `pasien_alergi` row of one patient, and nothing else.
     *
     * @return Builder<PasienAlergi>
     */
    public function alergiQuery(Pasien $pasien): Builder
    {
        return PasienAlergi::query()->whereBelongsTo($pasien);
    }

    /**
     * One family member of this patient, or a 404.
     *
     * The `whereBelongsTo` is inside {@see anggotaKeluargaQuery()}, so a row belonging
     * to somebody else is simply not found and the caller cannot tell it apart from a
     * row that never existed. That is the whole point: the check **is** the query, not a
     * filter applied after the row has been loaded.
     *
     * @throws ModelNotFoundException, rendered as the 404 envelope by `bootstrap/app.php`
     */
    public function anggotaKeluargaOrFail(Pasien $pasien, int $id): PasienAnggotaKeluarga
    {
        $anggota = $this->anggotaKeluargaQuery($pasien)->whereKey($id)->first();

        if ($anggota === null) {
            throw (new ModelNotFoundException)->setModel(PasienAnggotaKeluarga::class, [$id]);
        }

        return $anggota;
    }

    /**
     * One allergy of this patient, or a 404.
     *
     * @throws ModelNotFoundException, rendered as the 404 envelope by `bootstrap/app.php`
     */
    public function alergiOrFail(Pasien $pasien, int $id): PasienAlergi
    {
        $alergi = $this->alergiQuery($pasien)->whereKey($id)->first();

        if ($alergi === null) {
            throw (new ModelNotFoundException)->setModel(PasienAlergi::class, [$id]);
        }

        return $alergi;
    }

    /**
     * Every booking of this patient, as a scope.
     *
     * The same rule as every other child row: the tenant filter IS the query,
     * so somebody else's booking is simply not found rather than refused.
     *
     * @return Builder<Booking>
     */
    public function bookingQuery(Pasien $pasien): Builder
    {
        return Booking::query()->whereBelongsTo($pasien);
    }

    /**
     * Every medicine order of this patient, as a scope.
     *
     * The same rule as every other child row: the tenant filter IS the query,
     * so somebody else's order is simply not found rather than refused.
     *
     * `pesanan_obat.pasien_id` is `BIGINT UNSIGNED NOT NULL` (`:801`) with a
     * real foreign key to `pasien(id)` (`:815`), so the scope is total - there is
     * no order that names no patient and therefore escapes it.
     *
     * @return Builder<PesananObat>
     */
    public function pesananObatQuery(Pasien $pasien): Builder
    {
        return PesananObat::query()->whereBelongsTo($pasien);
    }

    /**
     * One booking of this patient, or a 404.
     *
     * @throws ModelNotFoundException, rendered as the 404 envelope by `bootstrap/app.php`
     */
    public function bookingOrFail(Pasien $pasien, int $id): Booking
    {
        $booking = $this->bookingQuery($pasien)->whereKey($id)->first();

        if ($booking === null) {
            throw (new ModelNotFoundException)->setModel(Booking::class, [$id]);
        }

        return $booking;
    }

    /**
     * The doctor profile of this account, or a 403.
     *
     * `ownDokter()` answers null for a `dokter`-typed account with no `dokter`
     * row, and an empty list would tell that account nothing is wrong with it.
     * The profile is incomplete, so the answer is 403 rather than an empty
     * list.
     *
     * @throws AccessDeniedHttpException, rendered as the 403 envelope by `bootstrap/app.php`
     */
    public function ownDokterOrFail(User $user): Dokter
    {
        $dokter = $this->ownDokter($user);

        if ($dokter === null) {
            throw new AccessDeniedHttpException('Endpoint ini hanya untuk akun dokter.');
        }

        return $dokter;
    }

    /**
     * Every booking on this doctor's row, as a scope.
     *
     * The doctor-side mirror of {@see bookingQuery()}: the row belongs to the
     * doctor, so a booking on another doctor's row is simply not found.
     *
     * @return Builder<Booking>
     */
    public function dokterBookingQuery(Dokter $dokter): Builder
    {
        return Booking::query()->whereBelongsTo($dokter);
    }

    /**
     * One booking on this doctor's row, or a 404.
     *
     * @throws ModelNotFoundException, rendered as the 404 envelope by `bootstrap/app.php`
     */
    public function dokterBookingOrFail(Dokter $dokter, int $id): Booking
    {
        $booking = $this->dokterBookingQuery($dokter)->whereKey($id)->first();

        if ($booking === null) {
            throw (new ModelNotFoundException)->setModel(Booking::class, [$id]);
        }

        return $booking;
    }

    /**
     * Is the account a patient account at all?
     *
     * Kept as a named predicate so the ENUM value appears in one place and a test can
     * assert the rule without going through HTTP. `ownPasien()` does not need it - a row
     * keyed by `user_id` is the authority, not the `tipe` column - and that is
     * deliberate: the `pasien` row is what makes the account a patient, and this
     * predicate exists for the reporting endpoints that have no row to work from.
     */
    public function isPatientAccount(User $user): bool
    {
        return $user->tipe === 'pasien';
    }

    /**
     * The page size a list request asked for, already clamped.
     *
     * The cap is applied here as well as in the FormRequest so a `per_page` arriving
     * from anywhere else cannot bypass it.
     */
    public function perPage(int $requested): int
    {
        return max(1, min($requested, self::PER_PAGE_MAX));
    }
}
