<?php

declare(strict_types=1);

namespace App\Services\Dokter;

use App\Enums\KonsultasiStatus;
use App\Models\Konsultasi;
use App\Models\Pasien;
use App\Models\UlasanDokter;
use App\Models\User;
use App\Services\Konsultasi\KonsultasiAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The `ulasan_dokter` surface: the public review list, its recomputed aggregate,
 * the patient's write, and the doctor's reply.
 *
 * ## The aggregate is RECOMPUTED on read, and the two `dokter` counters are ignored
 *
 * `dokter.rating_rata_rata` (`telemedicine_test.sql:423`) and `dokter.jumlah_ulasan`
 * (`:424`) have no writer anywhere in this application: the DDL seeds them at `0.00`
 * and `0`, and nothing maintains them. Serving those columns would publish a rating
 * no patient ever gave. {@see agregat()} therefore computes `COUNT`/`AVG` from
 * `ulasan_dokter` on every read, over the existing `idx_ulasan_dokter
 * (dokter_id, rating)` index the DDL already provides, and **no write path in this
 * service updates the stored columns** - a deliberate decision, not an omission.
 *
 * The method is PUBLIC and takes only a doctor id so the F03 directory resource can
 * reuse it for the list projection instead of deriving a second average. The shape
 * is:
 *
 * ```php
 * [
 *     'jumlah' => int,                       // every review of this doctor
 *     'rata_rata' => float|null,             // null when `jumlah` is 0
 *     'rata_rata_komunikasi' => float|null,  // null when no sub-rating was given
 *     'rata_rata_akurasi' => float|null,
 *     'distribusi' => ['1' => int, ..., '5' => int],  // always all five keys
 * ]
 * ```
 *
 * **`rata_rata` is `null` - not `0.0` - when there are no reviews.** A zero is a
 * rating, and a brand-new doctor with no reviews would appear to have been rated
 * zero. The API stays honest and the UI decides when to hide it (F04 hides the
 * summary below five reviews; that rule is the UI's, not this service's).
 *
 * ## One grouped query, not five counts
 *
 * The whole aggregate is one `GROUP BY rating` query, which is what the
 * `(dokter_id, rating)` index was built for. `COUNT(rating_komunikasi)` counts only
 * non-NULL rows, so a review that scored the overall rating and not the breakdown
 * does not drag the sub-average toward zero - SQL's `AVG` ignoring NULLs is correct
 * here and the explicit `COUNT` makes that visible rather than assumed.
 *
 * ## The write is INSERT-only, and the unique index is the rule
 *
 * `ulasan_dokter.konsultasi_id` is `NOT NULL UNIQUE` (`:1052`), so one consultation
 * has at most one review at the DATABASE level. {@see simpan()} therefore inserts
 * and translates MySQL's 1062 into the same 422 a pre-check would have produced, so
 * a double-tap cannot race the check. There is deliberately no update path: the
 * review body is not edited (`dibuat_at` is the only timestamp, `:1062`), and a
 * doctor's answer is the separate {@see balas()} write.
 *
 * Only the consultation's OWN patient may write, and only once its status is
 * `selesai`. Ownership is `KonsultasiAccess::ownPasien()` (403 when the caller owns
 * no patient row) plus a `pasien_id`-scoped lookup (404 for another patient's row,
 * byte-identical to a row that does not exist). The status refusal is a 422 through
 * `errors.status`, the same shape `KonsultasiService::ubahStatus()` uses for a state
 * machine violation.
 *
 * ## A doctor may reply to any review of their own, of any rating
 *
 * {@see balas()} resolves the caller's own `dokter` row (403 when there is none)
 * and scopes the lookup by `dokter_id` (404 for another doctor's review), so a
 * reply is a plain `UPDATE` of `balasan_dokter` + `dibalas_at`. There is no rating
 * threshold: decision F04 #5 makes replies optional for all ratings.
 */
final class UlasanDokterService
{
    /**
     * Rows per page when `per_page` is absent.
     *
     * The F04 list is a reading surface, not a worklist; ten rows is a screenful.
     */
    public const PER_PAGE_DEFAULT = 10;

    /**
     * The F04 cap. Deliberately 50, not the project-wide 100: this list is public
     * and unauthenticated, and a page of reviews is the largest payload any
     * Sehatly list serves.
     */
    public const PER_PAGE_MAX = 50;

    /** Highest rating, and the width of the distribution map. */
    public const RATING_MAKS = 5;

    /** Lowest rating. */
    public const RATING_MIN = 1;

    /** Newest first - decision F04 #6, and the default. */
    public const SORT_DEFAULT = 'terbaru';

    /**
     * The closed sort vocabulary.
     *
     * `membantu` ("most helpful") is deliberately absent: it needs a vote table
     * the schema does not have, and inventing one would be a schema change F04's
     * owner scope forbids.
     *
     * @var list<string>
     */
    public const SORT_VALUES = ['terbaru', 'tertinggi', 'terendah'];

    public function __construct(
        private readonly KonsultasiAccess $access,
    ) {}

    /**
     * The doctor's recomputed aggregate, for the list `meta` block and F03's reuse.
     *
     * @return array{
     *     jumlah: int,
     *     rata_rata: float|null,
     *     rata_rata_komunikasi: float|null,
     *     rata_rata_akurasi: float|null,
     *     distribusi: array<string, int>
     * }
     */
    public function agregat(int $dokterId): array
    {
        $baris = UlasanDokter::query()
            ->where('dokter_id', $dokterId)
            ->selectRaw(
                'rating, COUNT(*) as jumlah,'
                .' SUM(rating_komunikasi) as jumlah_komunikasi,'
                .' COUNT(rating_komunikasi) as banyak_komunikasi,'
                .' SUM(rating_akurasi) as jumlah_akurasi,'
                .' COUNT(rating_akurasi) as banyak_akurasi'
            )
            ->groupBy('rating')
            ->get();

        // All five keys are always present, so a client renders five bars without
        // a null check and `distribusi['5']` is never "undefined".
        $distribusi = [];

        for ($bintang = self::RATING_MIN; $bintang <= self::RATING_MAKS; $bintang++) {
            $distribusi[(string) $bintang] = 0;
        }

        $total = 0;
        $jumlahBintang = 0;
        $jumlahKomunikasi = 0;
        $banyakKomunikasi = 0;
        $jumlahAkurasi = 0;
        $banyakAkurasi = 0;

        foreach ($baris as $satu) {
            $bintang = (int) $satu->rating;
            $banyak = (int) $satu->jumlah;

            $distribusi[(string) $bintang] = $banyak;
            $total += $banyak;
            $jumlahBintang += $bintang * $banyak;
            $jumlahKomunikasi += (int) $satu->jumlah_komunikasi;
            $banyakKomunikasi += (int) $satu->banyak_komunikasi;
            $jumlahAkurasi += (int) $satu->jumlah_akurasi;
            $banyakAkurasi += (int) $satu->banyak_akurasi;
        }

        return [
            'jumlah' => $total,
            'rata_rata' => $total === 0 ? null : round($jumlahBintang / $total, 2),
            'rata_rata_komunikasi' => $banyakKomunikasi === 0 ? null : round($jumlahKomunikasi / $banyakKomunikasi, 2),
            'rata_rata_akurasi' => $banyakAkurasi === 0 ? null : round($jumlahAkurasi / $banyakAkurasi, 2),
            'distribusi' => $distribusi,
        ];
    }

    /**
     * The `jumlah` of {@see agregat()}, for MANY doctors in ONE grouped query.
     *
     * F03's directory list publishes a review count on every row, and asking
     * {@see agregat()} once per row would run one aggregate per doctor - N+1 by
     * construction. This is the batch spelling of the SAME count, over the same
     * `idx_ulasan_dokter (dokter_id, rating)` index (`telemedicine_test.sql:1065`),
     * so "the number of reviews" still has exactly one definition: `COUNT(*)` of
     * the doctor's `ulasan_dokter` rows. Nothing here reads `dokter.jumlah_ulasan`.
     *
     * A doctor with no review is ABSENT from the result rather than present with
     * `0`. The caller already knows which ids it asked about, so "no group" is
     * the honest SQL answer for "nothing to count"; it also means a future
     * caller cannot mistake "not asked about" for "reviewed zero times".
     * {@see DokterDirectoryService} fills the absent ids with `0`.
     *
     * @param  list<int>  $dokterIds
     * @return array<int, int> dokter_id => jumlah
     */
    public function jumlahUntuk(array $dokterIds): array
    {
        if ($dokterIds === []) {
            return [];
        }

        $baris = UlasanDokter::query()
            ->whereIn('ulasan_dokter.dokter_id', $dokterIds)
            ->selectRaw('ulasan_dokter.dokter_id, COUNT(*) as jumlah')
            ->groupBy('ulasan_dokter.dokter_id')
            ->get();

        $jumlah = [];

        foreach ($baris as $satu) {
            $jumlah[(int) $satu->dokter_id] = (int) $satu->jumlah;
        }

        return $jumlah;
    }

    /**
     * One page of a doctor's reviews, newest first unless asked otherwise.
     *
     * `rating` narrows the LIST only; the aggregate the controller publishes is
     * computed over every review, so clicking a distribution bar never changes
     * the distribution it was clicked on.
     *
     * Every order ends with `id DESC`: `dibuat_at` is a `TIMESTAMP` with
     * one-second resolution (`:1062`), so a burst of reviews ties often and
     * without the unique tiebreaker MySQL could repeat a row on one page and
     * skip it on the next. `pasien.user` is eager-loaded because the resource
     * asks for the display name of every non-anonymous review.
     *
     * @param  array<string, mixed>  $filters  the FormRequest's `validated()` output
     * @return LengthAwarePaginator<int, UlasanDokter>
     */
    public function daftar(int $dokterId, array $filters): LengthAwarePaginator
    {
        $query = UlasanDokter::query()
            ->where('ulasan_dokter.dokter_id', $dokterId)
            ->with('pasien.user');

        if (isset($filters['rating'])) {
            $query->where('ulasan_dokter.rating', (int) $filters['rating']);
        }

        $this->terapkanUrutan($query, (string) ($filters['sort'] ?? self::SORT_DEFAULT));

        $perPage = $filters['per_page'] ?? self::PER_PAGE_DEFAULT;

        return $query
            ->paginate(is_numeric($perPage) ? (int) $perPage : self::PER_PAGE_DEFAULT)
            ->withQueryString();
    }

    /**
     * Store the caller-patient's review of one `selesai` consultation.
     *
     * The aggregate columns on `dokter` are deliberately NOT written here: they
     * are never read (see the class docblock), and a write path that updated them
     * would be a second source of truth that could drift from {@see agregat()}.
     *
     * @param  array<string, mixed>  $data  the FormRequest's `validated()` output
     *
     * @throws ValidationException|ModelNotFoundException|AccessDeniedHttpException
     */
    public function simpan(User $caller, int $konsultasiId, array $data): UlasanDokter
    {
        // 403 for an account with no `pasien` row - a doctor, an admin, or a
        // patient-typed account whose profile is missing. The refusal is about
        // the caller, so it discloses nothing about the consultation.
        $pasien = $this->access->ownPasien($caller);

        // The tenant filter IS the query: another patient's consultation and one
        // that does not exist are the same 404, so the endpoint is not an
        // existence oracle over a sequential id space.
        $konsultasi = Konsultasi::query()
            ->whereKey($konsultasiId)
            ->where('pasien_id', $pasien->getKey())
            ->first();

        if ($konsultasi === null) {
            throw (new ModelNotFoundException)->setModel(Konsultasi::class, [$konsultasiId]);
        }

        if ((string) $konsultasi->status !== KonsultasiStatus::Selesai->value) {
            throw ValidationException::withMessages([
                'status' => ['Ulasan hanya dapat ditulis setelah konsultasi selesai.'],
            ]);
        }

        try {
            $ulasan = $this->tulis($pasien, $konsultasi, $data);
        } catch (UniqueConstraintViolationException) {
            // `konsultasi_id` is `NOT NULL UNIQUE` (`:1052`): the database, not a
            // pre-check, is what makes "one review per consultation" true. The
            // 1062 is translated into the same 422 a pre-check would produce, so
            // a double-tap cannot tell the two paths apart.
            throw ValidationException::withMessages([
                'konsultasi_id' => ['Konsultasi ini sudah memiliki ulasan.'],
            ]);
        }

        return $ulasan;
    }

    /**
     * The owning doctor's reply to one of their own reviews.
     *
     * A reply is an `UPDATE` of the SAME row (`balasan_dokter`, `dibalas_at`);
     * there is no reply table and no reply uniqueness, and a second `PUT`
     * replaces the previous answer rather than appending. Ownership is scoped in
     * the query, so another doctor's review is a 404 - identical to an id that
     * does not exist.
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException
     */
    public function balas(User $caller, int $ulasanId, string $balasan): UlasanDokter
    {
        $dokter = $this->access->ownDokter($caller);

        $ulasan = UlasanDokter::query()
            ->whereKey($ulasanId)
            ->where('dokter_id', $dokter->getKey())
            ->with('pasien.user')
            ->first();

        if ($ulasan === null) {
            throw (new ModelNotFoundException)->setModel(UlasanDokter::class, [$ulasanId]);
        }

        $ulasan->balasan_dokter = $balasan;
        $ulasan->dibalas_at = Carbon::now();
        $ulasan->save();

        return $ulasan;
    }

    /**
     * `?sort=` -> the ORDER BY, in one place.
     *
     * `terbaru` is the decision F04 #6 default. The two score orders fall back to
     * newest first inside a tie, so the list is stable and "highest" does not
     * return an arbitrary five-star review first.
     *
     * @param  Builder<UlasanDokter>  $query
     */
    private function terapkanUrutan(Builder $query, string $sort): void
    {
        match ($sort) {
            'tertinggi' => $query
                ->orderByDesc('ulasan_dokter.rating')
                ->orderByDesc('ulasan_dokter.dibuat_at')
                ->orderByDesc('ulasan_dokter.id'),
            'terendah' => $query
                ->orderBy('ulasan_dokter.rating')
                ->orderByDesc('ulasan_dokter.dibuat_at')
                ->orderByDesc('ulasan_dokter.id'),
            default => $query
                ->orderByDesc('ulasan_dokter.dibuat_at')
                ->orderByDesc('ulasan_dokter.id'),
        };
    }

    /**
     * The INSERT itself, so the caller can translate the duplicate-key race.
     *
     * `is_anonim` is the DDL's default when the request omits it: `TINYINT(1) NOT
     * NULL DEFAULT 1` (`:1059`), so an absent flag means ANONYMOUS and the patient
     * has to opt in to being named.
     *
     * @param  array<string, mixed>  $data
     */
    private function tulis(Pasien $pasien, Konsultasi $konsultasi, array $data): UlasanDokter
    {
        $ulasan = new UlasanDokter;
        $ulasan->konsultasi_id = $konsultasi->getKey();
        $ulasan->pasien_id = $pasien->getKey();
        $ulasan->dokter_id = $konsultasi->dokter_id;
        $ulasan->rating = (int) $data['rating'];
        $ulasan->rating_komunikasi = isset($data['rating_komunikasi']) ? (int) $data['rating_komunikasi'] : null;
        $ulasan->rating_akurasi = isset($data['rating_akurasi']) ? (int) $data['rating_akurasi'] : null;
        $ulasan->isi = isset($data['isi']) && $data['isi'] !== '' ? (string) $data['isi'] : null;
        $ulasan->is_anonim = (bool) ($data['is_anonim'] ?? true);
        $ulasan->save();

        // The response resource reads the reviewer's masked name for a
        // non-anonymous review, and the write response must not pay a lazy load.
        $pasien->loadMissing('user');
        $ulasan->setRelation('pasien', $pasien);

        return $ulasan;
    }
}
