<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

use Illuminate\Validation\Rule;

/**
 * The field set of `pasien_alergi`, shared by the create and update requests.
 *
 * ## The two ENUM lists are the DDL's, and the test suite re-parses them
 *
 * ```
 * pasien_alergi.tipe_alergen ENUM('obat','makanan','lingkungan','lainnya') NOT NULL  -- :277
 * pasien_alergi.keparahan    ENUM('ringan','sedang','berat','anafilaksis') NOT NULL
 *                                        DEFAULT 'ringan'                            -- :280
 * ```
 *
 * The plan asks for "`Rule::enum`-equivalent rules so an out-of-enum value is a 422".
 * `Rule::in()` is that equivalent here, and deliberately **not** `Rule::enum()`:
 * `Illuminate\Validation\Rules\Enum` requires a real PHP enum class, and on
 * laravel/framework 13.33 a string `enum:` cast is a silent no-op for the same reason -
 * both need a class, and this project has no PHP enum for a MySQL ENUM (every model casts
 * these columns to `string`). Transcribing the four and four values here and re-parsing
 * them out of `telemedicine_test.sql` on every test run is what makes the transcription
 * safe, and it is the same gate that caught `doker_umum` for `dokter_umum` in a previous
 * batch.
 *
 * ## `keparahan` is optional on create and absent from an update's write set
 *
 * The column is `NOT NULL DEFAULT 'ringan'` (`:280`), so an omitted value is filled by
 * the database. It is `nullable` in the rules so a client may send `null` to mean "use the
 * default", and the controller writes the stored value when the key is absent, rather than
 * restating `'ringan'` in PHP - the DDL is the authority for the default, and a second
 * copy of it in application code is a second thing to keep true.
 *
 * ## `pasien_id` and `dicatat_oleh_user_id` are not in the rules
 *
 * `pasien_id` is the tenant key: written from the caller's own `pasien` row, never read
 * from a request. `dicatat_oleh_user_id` is one of the DDL's 23 bare columns
 * (`:281`, `BIGINT UNSIGNED NULL`, **no** `FOREIGN KEY`), which is why `PasienAlergi` has
 * no `dicatatOleh()` relation; it is written from the caller's `users.id`. A client that
 * could set either one could attribute an allergy to a different patient or a different
 * author.
 *
 * ## `nama_alergen` is free text, and is NOT validated against `master_obat`
 *
 * `pasien_alergi.nama_alergen` is `VARCHAR(150) NOT NULL` (`:278`) with no foreign key.
 * A `exists:master_obat,nama_generik` rule would be a plausible-looking rule that is wrong:
 * an allergy is frequently to a food, a latex or a household chemical that has no row in a
 * medicine catalogue, and the schema's recorded limitation is that allergy matching is
 * best-effort name comparison against the `resep_item.nama_obat` snapshot. The column is
 * therefore validated as a length and nothing more.
 */
abstract class AlergiRequest extends PasienRequest
{
    /**
     * `pasien_alergi.tipe_alergen` at `telemedicine_test.sql:277`.
     *
     * @var list<string>
     */
    public const TIPE_ALERGEN = ['obat', 'makanan', 'lingkungan', 'lainnya'];

    /**
     * `pasien_alergi.keparahan` at `telemedicine_test.sql:280`.
     *
     * @var list<string>
     */
    public const KEPARAHAN = ['ringan', 'sedang', 'berat', 'anafilaksis'];

    /**
     * `pasien_alergi.keparahan DEFAULT 'ringan'` at `telemedicine_test.sql:280`.
     *
     * Quoted so a test can assert the DDL's default equals this constant rather than
     * trusting that the two were transcribed from the same line.
     */
    public const KEPARAHAN_DEFAULT = 'ringan';

    /**
     * The DDL's own column order, used to write the row in a stable sequence.
     *
     * `telemedicine_test.sql:277`-`:282`. `pasien_id` and `dicatat_oleh_user_id` are
     * server-written and so are not in it.
     *
     * @var list<string>
     */
    private const COLUMN_ORDER = [
        'tipe_alergen',
        'nama_alergen',
        'reaksi',
        'keparahan',
    ];

    /**
     * Is this the create request, where `tipe_alergen` and `nama_alergen` must be present?
     *
     * `true` for `POST`: `pasien_alergi.tipe_alergen` and `pasien_alergi.nama_alergen` are
     * both `NOT NULL` with no default (`:277`, `:278`), so a create that omitted one is
     * MySQL 1364 - a 500. `false` for `PUT`, which is a partial update for the reason
     * given on {@see AnggotaKeluargaRequest::isCreate()}.
     */
    abstract protected function isCreate(): bool;

    /**
     * The validated keys to write onto `pasien_alergi`, in the DDL's order.
     *
     * @return list<string>
     */
    public function alergiKeys(): array
    {
        $present = array_keys($this->validated());

        return array_values(array_filter(
            self::COLUMN_ORDER,
            static fn (string $key): bool => in_array($key, $present, true),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isCreate() ? 'required' : 'sometimes';

        return [
            'tipe_alergen' => [$presence, 'string', Rule::in(self::TIPE_ALERGEN)],
            // pasien_alergi.nama_alergen VARCHAR(150) NOT NULL (:278)
            'nama_alergen' => [$presence, 'string', 'min:2', 'max:150'],
            // pasien_alergi.reaksi VARCHAR(255) NULL (:279)
            'reaksi' => ['nullable', 'string', 'max:255'],
            // pasien_alergi.keparahan ENUM('ringan','sedang','berat','anafilaksis')
            // NOT NULL DEFAULT 'ringan' (:280)
            'keparahan' => ['nullable', 'string', Rule::in(self::KEPARAHAN)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipe_alergen' => 'tipe alergen',
            'nama_alergen' => 'nama alergen',
            'reaksi' => 'reaksi',
            'keparahan' => 'keparahan',
        ];
    }
}
