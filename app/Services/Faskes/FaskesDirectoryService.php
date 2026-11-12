<?php

declare(strict_types=1);

namespace App\Services\Faskes;

use App\Models\Faskes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public venue list behind `GET /api/v1/faskes`, built for the landing page's
 * "Apotek Terdekat" menu.
 *
 * ## Why this is its own service instead of a query in the controller
 *
 * Two reasons, both about drift rather than about size. The Haversine expression
 * must appear exactly once ({@see JARAK_KM_SQL}): the SELECT that measures and the
 * ORDER BY that sorts are the same formula, and a second spelling of it is a second
 * thing that can disagree. And the vocabulary of `tipe` lives here as
 * {@see TIPE_FASKES}, so the rule that refuses `?tipe=apotekx` and the query that
 * narrows to `tipe = 'apotek'` read one list rather than two copies of the migration's
 * ENUM declaration.
 *
 * ## It reads `faskes` directly, not through a view
 *
 * `DokterDirectoryService` reads `v_dokter_katalog` because a doctor's eligibility is
 * a join over six tables. A venue has no such question: `status_aktif = 1` is the whole
 * of "may the public see it", so a `where()` on the model is the complete filter and a
 * view would be a second name for the same rows.
 *
 * ## A venue without a map pin stays on the list
 *
 * The demo row (`FASKES-DEMO-001`) and any venue entered without coordinates have
 * `latitude IS NULL`, so their distance is `NULL`. They are ordered LAST when a
 * position was supplied ({@see urut()}) and they are still listed - the same decision
 * `DokterDirectoryService` makes for a doctor who practises nowhere. Dropping them
 * would make the panel's emptiness depend on whether somebody filled in two decimal
 * fields, and an empty panel reads as "no pharmacies" rather than "no coordinates".
 */
class FaskesDirectoryService
{
    /**
     * The `faskes.tipe` ENUM, in declaration order.
     *
     * Mirrors `2026_10_01_000028_faskes_table.php:78` and the `faskes.tipe` block of
     * `docs/enums.json`, which is what the generated contract publishes as the query
     * parameter's `enum`. Declared here rather than read from `docs/enums.json` at
     * runtime, because the validator must not depend on a file that only exists after
     * `php artisan sehatly:enums` has been run against a live database.
     *
     * @var list<string>
     */
    public const TIPE_FASKES = ['rumah_sakit', 'klinik', 'puskesmas', 'apotek', 'laboratorium'];

    /**
     * What `GET /faskes` answers when the caller names no `tipe`.
     *
     * The route exists for the pharmacy menu, so the pharmacy is the default and a
     * caller asking for nothing gets what the menu is. A caller wanting the clinics
     * says so; a default of "every type" would make the panel's headline and its
     * contents disagree.
     */
    public const TIPE_DEFAULT = 'apotek';

    /** The panel's page size: the rows it shows without asking for a second page. */
    public const PER_PAGE_DEFAULT = 8;

    /** The project-wide list cap (plan todo 21), on an endpoint with no `auth:sanctum`. */
    public const PER_PAGE_MAX = 100;

    /**
     * The Haversine distance in kilometres from the request's position to the venue,
     * over the row's own `latitude`/`longitude`.
     *
     * Three bindings, in this order: the caller's latitude (used twice, for their own
     * cosine and for the sine term), the caller's longitude, the caller's latitude
     * again. The `LEAST`/`GREATEST` clamp keeps floating-point drift from pushing
     * `ACOS` outside `[-1, 1]`, which MySQL answers with `NULL` - and a `NULL`
     * distance for two points that are metres apart would sort the closest venue
     * last. The clamp's worst case is a slightly flat `0`.
     *
     * @var string
     */
    private const JARAK_KM_SQL = '6371 * ACOS(LEAST(1.0, GREATEST(-1.0, '
        .'COS(RADIANS(?)) * COS(RADIANS(faskes.latitude)) '
        .'* COS(RADIANS(faskes.longitude) - RADIANS(?)) '
        .'+ SIN(RADIANS(?)) * SIN(RADIANS(faskes.latitude)))))';

    /**
     * The active venues of one `tipe`, nearest first when a position was given.
     *
     * `page` and `per_page` are already validated by {@see IndexFaskesRequest}, so
     * this method takes the validated array as-is and never sees a raw query string.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $tipe = $filters['tipe'] ?? null;

        $query = Faskes::query()
            ->where('status_aktif', true)
            ->where('tipe', is_string($tipe) && $tipe !== '' ? $tipe : self::TIPE_DEFAULT);

        $posisi = $this->koordinat($filters);

        $this->ukur($query, $posisi);
        $this->urut($query, $posisi !== null);

        return $query
            ->paginate($this->perPage($filters))
            ->withQueryString();
    }

    /**
     * Add the distance column, or add nothing at all.
     *
     * A null position reads as no position: the validator refuses `?lat=` without
     * `lng` ({@see IndexFaskesRequest}), and this second check exists for the absent
     * case, where there is simply nothing to measure from. The row list is then
     * ordered by name rather than by a distance that does not exist.
     *
     * @param  Builder<Faskes>  $query
     * @param  array{lat: float, lng: float}|null  $posisi
     */
    private function ukur(Builder $query, ?array $posisi): void
    {
        if ($posisi === null) {
            return;
        }

        $query->selectRaw('faskes.*, '.self::JARAK_KM_SQL.' AS jarak_km', [
            $posisi['lat'],
            $posisi['lng'],
            $posisi['lat'],
        ]);
    }

    /**
     * The total order: distance first when it exists, name otherwise, `id` always.
     *
     * `jarak_km IS NULL` is the leading key because MySQL sorts `NULL` FIRST under
     * `ASC`, which would put every venue without a pin ahead of the nearest one with
     * it. Sorting the flag first sends those rows to the end while every measured row
     * keeps its real order.
     *
     * The `id` tiebreaker is the same reason `DokterDirectoryService` appends one:
     * two venues at the same rounded distance, or two venues with the same name, are
     * common, and a `LIMIT/OFFSET` page over an order that is not total can repeat or
     * drop a row between page 1 and page 2.
     *
     * @param  Builder<Faskes>  $query
     */
    private function urut(Builder $query, bool $terukur): void
    {
        if ($terukur) {
            $query->orderByRaw('jarak_km IS NULL')
                ->orderByRaw('jarak_km');
        } else {
            $query->orderBy('nama');
        }

        $query->orderBy('id');
    }

    /**
     * The caller's position as floats, or `null` when there is not a complete one.
     *
     * `is_numeric` rather than `is_float`: everything arriving from a query string is
     * a string, and the validator has already bounded both axes - this is only the
     * "is there a pair at all" question.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function koordinat(array $filters): ?array
    {
        $lat = $filters['lat'] ?? null;
        $lng = $filters['lng'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    /**
     * The page size after validation, defaulting to {@see PER_PAGE_DEFAULT}.
     *
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $per = $filters['per_page'] ?? null;

        return is_numeric($per) ? (int) $per : self::PER_PAGE_DEFAULT;
    }
}
