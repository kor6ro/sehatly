<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\IndexReferensiRequest;
use App\Http\Resources\Json\AnonymousResourceCollection;
use App\Support\ApiResponse;
use App\Support\Reference\ReferensiEndpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 14 public, read-only reference endpoints under `/api/v1/referencia/`.
 *
 * ## What this surface is for
 *
 * Every value a client must choose BEFORE it has an account: a province, a religion, a
 * blood group, a payment method, a diagnosis code. A patient registering on a phone
 * needs a province list, and they need it before they hold a token. That single fact
 * decides the guards.
 *
 * ## Why NONE of the 14 routes carries `auth`, `permission:` or `tipe:`
 *
 * `RbacCatalog` holds no permission code that names reference data, so a
 * `permission:` here would mean inventing policy - and `EnsurePermission` answers an
 * unknown code with a **500**, not a 403. A `tipe:` gate would be worse than useless:
 * `perawat` and `kurir` are real `users.tipe` ENUM values
 * (`telemedicine_test.sql:139`) that hold no role in `RbacCatalog::ROLES` and
 * therefore no grant, so ANY gate locks those two account types out of a dropdown.
 *
 * The public `/dokter` routes make the same argument (see `DokterController`), and the
 * price of being public - which is paid here too - is that the responses carry the
 * MINIMUM: reference labels and codes, no patient, no account, no clinical record. Every
 * one of the 13 tables is a lookup vocabulary seeded from `telemedicine_test.sql`; not
 * one of them is a person's data.
 *
 * ## Thirteen routes, one method
 *
 * All 13 table-backed endpoints are registered as separate named routes that point here,
 * and {@see IndexReferensiRequest::endpoint()} tells the method which one it is serving
 * by reading the route NAME (`referensi.<slug>`). Thirteen near-identical method bodies
 * would have put the only real variation - which model, which filter, whether it pages -
 * in thirteen places, and the variation is what a reader needs to see. It is a table in
 * {@see ReferensiEndpoint}.
 *
 * ## The 14th route, `/referensi/enums`, reads no table
 *
 * It serves the committed `docs/enums.json` - the very bytes
 * `php artisan sehatly:enums` wrote and `php artisan sehatly:enums --check` verifies.
 * Reading the file rather than re-deriving it is the point: the catalogue a client
 * fetches and the catalogue a generated Dart or TypeScript enum is compiled from are then
 * the same bytes by construction, and they cannot drift. A missing file is a deployment
 * fault and answers 500, deliberately: the endpoint must never answer 200 with an empty
 * or guessed catalogue.
 *
 * ## Ordering is total, which is what makes pagination safe
 *
 * Every endpoint orders on {@see ReferensiEndpoint::$orders} and the last key is unique
 * in all 13 tables. That matters more than it looks: if two rows could tie, the database
 * would be free to return them in either order, so a client paging through the list
 * could see a row twice or miss it entirely.
 */
class ReferensiController extends Controller
{
    /**
     * `GET /api/v1/referencia/{slug}` - the 13 table-backed reference lists.
     */
    public function index(IndexReferensiRequest $request): JsonResponse
    {
        $endpoint = $request->endpoint();
        $query = $this->query($endpoint, $request);

        if (! $endpoint->paginates) {
            $rows = $query->get();
            $total = $rows->count();

            return $this->respond(
                $endpoint,
                $this->collection($endpoint, $rows),
                ApiResponse::singlePageMeta($total),
            );
        }

        $rows = $query->paginate($request->perPage())->withQueryString();

        return $this->respond(
            $endpoint,
            $this->collection($endpoint, $rows->getCollection()),
            ApiResponse::pageMeta($rows),
        );
    }

    /**
     * `GET /api/v1/referencia/enums` - the generated ENUM catalogue.
     *
     * `data` is the catalogue object exactly as `docs/enums.json` holds it, and
     * `meta` is the standard single-page block over the number of ENUM COLUMNS, so a
     * client parses it with the same code it uses for the other 13 lists.
     */
    public function enums(): JsonResponse
    {
        $path = base_path('docs/enums.json');

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                'The generated ENUM catalogue is missing or unreadable at docs/enums.json. '
                .'Run `php artisan sehatly:enums` to regenerate it.',
            );
        }

        $contents = (string) file_get_contents($path);
        $catalogue = json_decode($contents, true);

        if (! is_array($catalogue)) {
            throw new RuntimeException(
                'docs/enums.json is not a JSON object ('.json_last_error_msg().'). '
                .'Run `php artisan sehatly:enums` to regenerate it.',
            );
        }

        return ApiResponse::success(
            ['enums' => $catalogue],
            'Daftar enum berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::singlePageMeta(count($catalogue)),
        );
    }

    /**
     * Build the query for one endpoint, in a fixed order.
     *
     * The order is not cosmetic: the parent filter and the `status_aktif` default are
     * applied BEFORE the search so the SQL reads parent-then-needle, and the ORDER BY is
     * applied last because it is a modifier on the whole thing. Every clause is
     * conditional on the endpoint's own definition, so an endpoint with no parent simply
     * never emits one - there is no `WHERE 1=1` placeholder to be mistaken for a filter.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function query(ReferensiEndpoint $endpoint, Request $request): Builder
    {
        /** @var Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = $endpoint->model::query();

        if ($endpoint->parent !== null && $request->filled($endpoint->parent)) {
            $query->where($endpoint->parent, (int) $request->integer($endpoint->parent));
        }

        // Active-only by DEFAULT, and only for the one table that has the column.
        // `?status_aktif=0` asks for the retired rows explicitly; `?status_aktif=1`
        // asks the same thing the default already does, and both are validated as
        // booleans, so `?status_aktif=ya` is a 422 rather than a silent "off".
        if ($endpoint->filterStatusAktif && ! $request->has('status_aktif')) {
            $query->where('status_aktif', 1);
        } elseif ($endpoint->filterStatusAktif) {
            $query->where('status_aktif', $request->boolean('status_aktif') ? 1 : 0);
        }

        if ($endpoint->searchable !== [] && $request->filled('q')) {
            // LIKE with a leading wildcard, grouped so the ORs cannot escape the
            // closure and re-bind to the outer query. `$like` is bound, never
            // interpolated, so a needle containing `%` or `_` is matched literally
            // for the `_` and is a wildcard for the `%` - the latter is the documented
            // behaviour of a substring search.
            $needle = '%'.$request->string('q')->toString().'%';

            $query->where(function (Builder $inner) use ($endpoint, $needle): void {
                foreach ($endpoint->searchable as $column) {
                    $inner->orWhere($column, 'like', $needle);
                }
            });
        }

        foreach ($endpoint->orders as $column) {
            $query->orderBy($column);
        }

        return $query;
    }

    /**
     * Wrap the rows in the endpoint's resource, under its own `data` key.
     *
     * The key is derived from the slug (`kabupaten-kota` -> `kabupaten_kota`) so a client
     * reads `data.kabupaten_kota` for one endpoint and `data.provinsi` for another, and
     * neither can be renamed without changing a URL.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @return AnonymousResourceCollection
     */
    private function collection(ReferensiEndpoint $endpoint, $rows): AnonymousResourceCollection
    {
        /** @var class-string<JsonResource> $resource */
        $resource = $endpoint->resource;

        return $resource::collection($rows);
    }

    /**
     * The one place a reference response is assembled.
     *
     * Both shapes go through here so `success`/`data`/`message`/`meta` is spelled once.
     * `meta` is always a top-level fourth key - never nested inside `data` - which is
     * what lets a client read pagination identically across all 14 endpoints.
     *
     * @param  array<string, int|null>  $meta
     */
    private function respond(ReferensiEndpoint $endpoint, AnonymousResourceCollection $data, array $meta): JsonResponse
    {
        return ApiResponse::success(
            [$endpoint->dataKey() => $data],
            $endpoint->successMessage(),
            Response::HTTP_OK,
            $meta,
        );
    }

    /**
     * The live ENUM column count, for diagnostics.
     *
     * Deliberately NOT used by any response. The catalogue endpoint reads the committed
     * file, so this exists for a developer comparing a live schema to the artefact
     * without shelling out to the exporter, and it is the same base-table-only filter
     * `EnumCatalogue::fromInformationSchema()` applies - a view column is not an ENUM
     * this project owns.
     */
    public function liveEnumColumnCount(): int
    {
        return (int) DB::selectOne(
            'select count(*) as c'
            .' from information_schema.COLUMNS c'
            .' join information_schema.TABLES t'
            .'   on t.TABLE_SCHEMA = c.TABLE_SCHEMA and t.TABLE_NAME = c.TABLE_NAME'
            .' where c.TABLE_SCHEMA = database() and c.DATA_TYPE = ? and t.TABLE_TYPE = ?',
            ['enum', 'BASE TABLE'],
        )->c;
    }
}
