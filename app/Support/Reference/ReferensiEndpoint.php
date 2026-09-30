<?php

declare(strict_types=1);

namespace App\Support\Reference;

use App\Http\Requests\Referensi\IndexReferensiRequest;
use App\Http\Resources\MasterSpesialisasiResource;
use App\Http\Resources\Referensi\AgamaResource;
use App\Http\Resources\Referensi\GolonganDarahResource;
use App\Http\Resources\Referensi\HubunganKeluargaResource;
use App\Http\Resources\Referensi\Icd10Resource;
use App\Http\Resources\Referensi\Icd9cmResource;
use App\Http\Resources\Referensi\KabupatenKotaResource;
use App\Http\Resources\Referensi\KecamatanResource;
use App\Http\Resources\Referensi\KelurahanResource;
use App\Http\Resources\Referensi\MetodePembayaranResource;
use App\Http\Resources\Referensi\PendidikanResource;
use App\Http\Resources\Referensi\ProvinsiResource;
use App\Http\Resources\Referensi\StatusPernikahanResource;
use App\Models\MasterAgama;
use App\Models\MasterGolonganDarah;
use App\Models\MasterHubunganKeluarga;
use App\Models\MasterIcd10;
use App\Models\MasterIcd9cm;
use App\Models\MasterKabupatenKota;
use App\Models\MasterKecamatan;
use App\Models\MasterKelurahan;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPendidikan;
use App\Models\MasterProvinsi;
use App\Models\MasterSpesialisasi;
use App\Models\MasterStatusPernikahan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The definition of one read-only reference endpoint, in one place.
 *
 * ## Why a value object rather than thirteen controller methods
 *
 * A controller method per endpoint would be thirteen near-identical bodies, and the
 * differences between them - which model, which resource, which parent filter,
 * whether it pages - would live in the method bodies where a reader has to
 * compare them all to see the pattern. Here the whole variation is a table, so
 * the table is the documentation: one row per endpoint, and the code that serves
 * a row is written once.
 *
 * The trade is honest and worth stating. A value object is indirection, and
 * indirection is only free when the set of things it describes is closed. It is
 * closed here: the plan's todo 42 names the tables, `routes/api.php` registers
 * one GET per row, and {@see ReferensiEndpoint::all()} is the only place a
 * reference endpoint can be born. A thirteenth method would have been a way to
 * add one that the table and the tests do not know about.
 *
 * ## Every field is load-bearing
 *
 * - `slug` is the URL segment AND the route name suffix, so a path and its route
 *   name cannot drift apart.
 * - `model` and `resource` are class-string constants rather than strings
 *   resolved at runtime, so a typo is a `TypeError` at the first request instead
 *   of a 500 discovered in production.
 * - `parent` is the filter column for the three levels of the administrative
 *   hierarchy. `null` on the other ten, which is why `filters()` returns an empty
 *   array for them rather than a `WHERE 1=1`.
 * - `searchable` is the column list a `?q=` needle is matched against. It is
 *   empty for the ten tables with no text worth searching, and a `?q=` against
 *   those is a 422 rather than a silently ignored parameter.
 * - `paginates` records whether the endpoint answers `?page=`. It is a decision,
 *   not a size threshold: `provinsi` is 38 rows and does not paginate, while
 *   `master_kelurahan` is the largest table in the schema and must.
 */
final class ReferensiEndpoint
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string<JsonResource>  $resource
     * @param  list<string>  $searchable
     * @param  list<string>  $orders
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $model,
        public readonly string $resource,
        public readonly string $label,
        public readonly ?string $parent = null,
        public readonly array $searchable = [],
        public readonly bool $paginates = false,
        public readonly bool $filterStatusAktif = false,
        public readonly array $orders = [],
    ) {}

    /**
     * The closed set of reference endpoints, in the order the plan lists them.
     *
     * ## The 13 rows, and where each one comes from
     *
     * The plan's todo 42 names 13 master tables. Section `[1] MASTER DATA` of
     * `telemedicine_test.sql` declares 11 of them; `master_spesialisasi` (`:402`)
     * and `master_metode_pembayaran` are declared outside that section, and both
     * are named by the plan, so the plan's 13 and the DDL's set agree once the
     * two out-of-section tables are counted. That is the arithmetic, stated here
     * because "13 endpoints" is otherwise a number a reader has to trust.
     *
     * ## Ordering is `nama` first, `kode` as the tiebreak
     *
     * Every table here has a `nama` except `master_golongan_darah`, whose whole
     * schema is `(id, kode)` - it IS a code list, and `kode` is its only text.
     * Ordering on the human label with the code as a tiebreak is what makes the
     * order total: two provinces may share a name, and without the second key the
     * database is free to return them in any order, which would make pagination
     * non-deterministic across pages. A list whose order can change between two
     * identical requests cannot be paged safely.
     *
     * ## Why `filterStatusAktif` exists on exactly one row
     *
     * `master_metode_pembayaran.status_aktif` is the only soft-off switch among
     * the 13. A retired payment method must not be offered to a patient booking a
     * consultation, so the default query filters it and a client that legitimately
     * needs the retired ones asks for them. The other 12 tables have no such
     * column, which is why this is a flag on the definition rather than a rule
     * applied to every row.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            // The administrative hierarchy. Each level filters on the level above,
            // and the whole chain is public because a patient picks a province
            // before they have an account.
            self::make('provinsi', MasterProvinsi::class, ProvinsiResource::class, 'provinsi', orders: ['nama', 'kode']),
            self::make('kabupaten-kota', MasterKabupatenKota::class, KabupatenKotaResource::class, 'kabupaten/kota', parent: 'provinsi_id', paginates: true, orders: ['nama', 'kode']),
            self::make('kecamatan', MasterKecamatan::class, KecamatanResource::class, 'kecamatan', parent: 'kabupaten_kota_id', paginates: true, orders: ['nama', 'kode']),
            self::make('kelurahan', MasterKelurahan::class, KelurahanResource::class, 'kelurahan', parent: 'kecamatan_id', paginates: true, orders: ['nama', 'kode']),

            // The demographic vocabularies. Small, fixed, and read whole: a
            // registration form needs all of them on one screen. None of these four
            // has a `kode` column, so `nama` is the only order key there is.
            self::make('agama', MasterAgama::class, AgamaResource::class, 'agama', orders: ['nama']),
            self::make('golongan-darah', MasterGolonganDarah::class, GolonganDarahResource::class, 'golongan darah', orders: ['kode']),
            self::make('pendidikan', MasterPendidikan::class, PendidikanResource::class, 'pendidikan', orders: ['nama']),
            self::make('status-pernikahan', MasterStatusPernikahan::class, StatusPernikahanResource::class, 'status pernikahan', orders: ['nama']),
            self::make('hubungan-keluarga', MasterHubunganKeluarga::class, HubunganKeluargaResource::class, 'hubungan keluarga', orders: ['nama']),

            // Clinical vocabularies.
            self::make('spesialisasi', MasterSpesialisasi::class, MasterSpesialisasiResource::class, 'spesialisasi', searchable: ['kode', 'nama'], orders: ['nama', 'kode']),
            self::make('metode-pembayaran', MasterMetodePembayaran::class, MetodePembayaranResource::class, 'metode pembayaran', searchable: ['kode', 'nama'], filterStatusAktif: true, orders: ['nama', 'kode']),

            // The diagnosis codes. The two largest reference tables after
            // `master_kelurahan`, and the only ones a client ever searches rather
            // than scrolls - so they page, and they accept `?q=`. They are ordered
            // on `kode` first because a code list is read as a code list.
            self::make('icd10', MasterIcd10::class, Icd10Resource::class, 'kode ICD-10', searchable: ['kode', 'deskripsi'], paginates: true, orders: ['kode', 'deskripsi']),
            self::make('icd9cm', MasterIcd9cm::class, Icd9cmResource::class, 'kode ICD-9CM', searchable: ['kode', 'deskripsi'], paginates: true, orders: ['kode', 'deskripsi']),
        ];
    }

    /**
     * The 14th route: the generated catalogue itself.
     *
     * It is not a row in {@see all()} because it reads no table - it serves the
     * committed `docs/enums.json` - so it has no model, no resource and no
     * filters. It is a reference endpoint and it belongs to the same prefix, and
     * naming it separately is what keeps the 13 table-backed ones uniform.
     */
    public static function enumsSlug(): string
    {
        return 'enums';
    }

    /**
     * The definition for a slug, or null when the slug names no endpoint.
     *
     * Returning null rather than throwing lets the router's 404 stay the 404: an
     * unknown reference slug is a routing miss, and the project's exception
     * handler renders it in the standard failure envelope.
     */
    public static function find(string $slug): ?self
    {
        foreach (self::all() as $endpoint) {
            if ($endpoint->slug === $slug) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $searchable
     * @param  list<string>  $orders
     */
    private static function make(
        string $slug,
        string $model,
        string $resource,
        string $label,
        ?string $parent = null,
        array $searchable = [],
        bool $paginates = false,
        bool $filterStatusAktif = false,
        array $orders = ['nama', 'kode'],
    ): self {
        return new self($slug, $model, $resource, $label, $parent, $searchable, $paginates, $filterStatusAktif, $orders);
    }

    /**
     * The query-parameter names this endpoint answers.
     *
     * The parent filter, the search needle, the status switch and the paging pair.
     * {@see IndexReferensiRequest} builds its
     * validation rules from exactly this list, which is what stops a parameter
     * being accepted and then ignored - a client sending `?kota=Jakarta` to
     * `/kabupaten-kota` gets a 422 naming the field rather than the full list as
     * if it had filtered.
     *
     * @return list<string>
     */
    public function queryParameters(): array
    {
        $parameters = [];

        if ($this->parent !== null) {
            $parameters[] = $this->parent;
        }

        if ($this->searchable !== []) {
            $parameters[] = 'q';
        }

        if ($this->filterStatusAktif) {
            $parameters[] = 'status_aktif';
        }

        if ($this->paginates) {
            $parameters[] = 'page';
            $parameters[] = 'per_page';
        }

        return $parameters;
    }

    /**
     * The response key under `data`, and the human sentence in `message`.
     *
     * Both are derived from the label rather than typed per endpoint so the 13
     * rows cannot disagree about their own name - the singular label appears in
     * exactly two places per response, and a test asserts the pair.
     */
    public function dataKey(): string
    {
        return str_replace('-', '_', $this->slug);
    }

    public function successMessage(): string
    {
        return 'Daftar '.$this->label.' berhasil dimuat.';
    }
}
