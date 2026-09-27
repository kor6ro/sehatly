<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

use App\Models\Pasien;
use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Validates `PUT /api/v1/pasien/profil`.
 *
 * ## The writable set is a CLOSED list, and that is the security control
 *
 * The plan's rule is: "It must not allow changing `tipe`, `status`, `no_telepon`, or
 * `nik` - those are not in the FormRequest's validated keys, so mass-assignment cannot
 * reach them." The mechanism matters more than the list. Nothing here stops a caller from
 * *sending* those keys; what stops them is that `validated()` returns only the keys named
 * below, so a payload carrying `tipe: 'superadmin'` is validated, the extra key is
 * dropped, and the controller never sees it. A test asserts the row is unchanged.
 *
 * The same reasoning excludes four further columns the plan's prose does not name:
 *
 * | not writable | why |
 * | --- | --- |
 * | `jenis_kelamin`, `tanggal_lahir` | collected at registration and immutable from here on purpose. Both are part of the identity every past medical record was written against; correcting them silently would leave the history inconsistent with the profile. |
 * | `rhesus` | clinical. A blood-group change is a laboratory event, not a profile edit. |
 * | `is_meninggal`, `tanggal_meninggal` | set by record-keeping, not by the account holder. Published read-only by `PasienResource`. |
 * | `nomor_kk`, `nomor_ihs_satusehat` | `nomor_kk` is a family identifier, and `nomor_ihs_satusehat` (`:224`) is `NULL UNIQUE` Kemenkes SATUSEHAT state no client in this project may set. |
 * | `catatan_alergi` | the DDL's own second, unsynchronised allergy source (`:242`). `GET /api/v1/pasien/alergi` is the API's allergy surface; letting this endpoint write the free-text one too is how two disagreeing lists get created. |
 * | `nomor_rm` | `RM-YYYYMM-<users.id>`, written once at registration. It is an identity, not a preference. |
 * | `user_id` | the ownership key. Written by nobody, ever. |
 *
 * Every one of them is therefore unreachable without a schema change, which is the point:
 * the rule is structural rather than a list somebody has to remember to honour in a
 * controller.
 *
 * ## `nama_lengkap` is a `users` column and the only one on this route
 *
 * `pasien` has no name column; `users.nama_lengkap` is `VARCHAR(150) NOT NULL` (`:135`)
 * and it is the one field on this request the controller writes to a second table. It is
 * included because a profile form with a name field that does not save is a bug report on
 * day one, and because the plan's list names it first.
 *
 * ## `Rule::exists` on all eight reference columns, including the four bare ones
 *
 * The plan asks for "`Rule::exists('master_agama','id')` style rules so a bad FK id is a
 * 422, not a 500". Four of the eight have real foreign keys, so `exists` is belt and
 * braces there. The other four - `pasien.provinsi_id`, `kabupaten_kota_id`, `kecamatan_id`
 * and `kelurahan_id` (`:236`-`:238`) - are part of the DDL's 23 bare columns: they
 * reference a `master_*` table and carry **no** `FOREIGN KEY` at all. For those `exists`
 * is the *only* referential check the schema permits, and without it a bad id would be
 * written and silently point at nothing.
 *
 * ## The wilayah chain is checked for coherence, which is an addition
 *
 * `master_kelurahan -> master_kecamatan -> master_kabupaten_kota -> master_provinsi` is a
 * chain of three `NOT NULL` references, and nothing in the schema stops a write that puts
 * a Bandung kelurahan beside a Bali province. {@see after()} refuses that, by walking up
 * from whichever of the four is present and comparing the ancestor against the value the
 * request supplied or the row already holds. **This is beyond the plan's literal text**
 * and is recorded as such: an incoherent address is a real data bug in an app that
 * schedules a visit by province, and the check costs at most three indexed primary-key
 * reads.
 *
 * @see PasienRecordAccess for the ownership rule
 */
class UpdatePasienProfileRequest extends PasienRequest
{
    /**
     * The four `pasien` columns that reference a `master_*` table with a real foreign key.
     *
     * Ordered as the DDL declares them (`:228`, `:230`, `:231`, `:233`) so a reader can
     * diff the two lists by eye.
     *
     * @var array<string, string>
     */
    private const FOREIGN_KEY_COLUMNS = [
        'golongan_darah_id' => 'master_golongan_darah',
        'agama_id' => 'master_agama',
        'pendidikan_id' => 'master_pendidikan',
        'status_pernikahan_id' => 'master_status_pernikahan',
    ];

    /**
     * The four `pasien` columns that reference a `master_*` table with **no** foreign key.
     *
     * Part of the DDL's 23 bare columns, so `exists` is the whole of the referential
     * check. Ordered widest-first to narrowest, which is also the order
     * {@see assertWilayahCoherent()} walks up from.
     *
     * @var list<string>
     */
    private const BARE_REFERENCE_COLUMNS = [
        'provinsi_id',
        'kabupaten_kota_id',
        'kecamatan_id',
        'kelurahan_id',
    ];

    /**
     * Each bare reference column, the table it points at, and the column in **that** table
     * naming the parent of the same level.
     *
     * `parent_column` is null for `provinsi_id`, which is the root of the chain.
     *
     * @var array<string, array{tabel: string, parent_column: ?string}>
     */
    private const WILAYAH_CHAIN = [
        'provinsi_id' => ['tabel' => 'master_provinsi', 'parent_column' => null],
        'kabupaten_kota_id' => ['tabel' => 'master_kabupaten_kota', 'parent_column' => 'provinsi_id'],
        'kecamatan_id' => ['tabel' => 'master_kecamatan', 'parent_column' => 'kabupaten_kota_id'],
        'kelurahan_id' => ['tabel' => 'master_kelurahan', 'parent_column' => 'kecamatan_id'],
    ];

    /**
     * The one key on this request that lands on `users` rather than on `pasien`.
     */
    public const USERS_KEY = 'nama_lengkap';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            // users.nama_lengkap VARCHAR(150) NOT NULL (:135)
            'nama_lengkap' => ['sometimes', 'required', 'string', 'min:3', 'max:150'],
            // patients.tempat_lahir VARCHAR(100) NULL (:227)
            'tempat_lahir' => ['sometimes', 'nullable', 'string', 'max:100'],
            // patients.pekerjaan VARCHAR(100) NULL (:232)
            'pekerjaan' => ['sometimes', 'nullable', 'string', 'max:100'],
            // patients.alamat_lengkap TEXT NOT NULL (:234)
            'alamat_lengkap' => ['sometimes', 'required', 'string', 'min:5', 'max:2000'],
            // patients.rt / rw VARCHAR(5) NULL (:239-240)
            'rt' => ['sometimes', 'nullable', 'string', 'max:5', 'regex:/^[0-9]{1,3}$/'],
            'rw' => ['sometimes', 'nullable', 'string', 'max:5', 'regex:/^[0-9]{1,3}$/'],
            // patients.kode_pos CHAR(5) NULL (:241)
            'kode_pos' => ['sometimes', 'nullable', 'string', 'size:5', 'regex:/^[0-9]{5}$/'],
        ];

        foreach (self::FOREIGN_KEY_COLUMNS as $column => $table) {
            $rules[$column] = ['sometimes', 'nullable', 'integer', 'exists:'.$table.',id'];
        }

        foreach (self::BARE_REFERENCE_COLUMNS as $column) {
            $rules[$column] = [
                'sometimes',
                'nullable',
                'integer',
                'exists:'.self::WILAYAH_CHAIN[$column]['tabel'].',id',
            ];
        }

        // patients.tinggi_badan_cm DECIMAL(5,1) NULL (:243). `decimal:0,1` is the
        // column's own scale, so a value that would be silently rounded on write is a 422
        // instead. The bounds are the widest values a human body can plausibly have, not
        // a clinical rule: the column would accept 9999.9 and this refuses it, recorded
        // so nobody reads the range as medicine.
        $rules['tinggi_badan_cm'] = ['sometimes', 'nullable', 'numeric', 'decimal:0,1', 'min:30', 'max:300'];

        // patients.berat_badan_kg DECIMAL(5,2) NULL (:244). Same reasoning, scale 2.
        $rules['berat_badan_kg'] = ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:1', 'max:500'];

        return $rules;
    }

    /**
     * Refuse a `provinsi_id` / `kabupaten_kota_id` / `kecamatan_id` / `kelurahan_id` set
     * that cannot all be part of one administrative chain.
     *
     * The rule only ever compares a value the request supplied, or one already on the
     * row, against a value looked up from the table - and only when both are present. A
     * request naming a single one of the four is still checked against what the row holds,
     * because leaving the ancestors stale is the bug being guarded.
     *
     * The raw request input is read rather than `validated()`, deliberately:
     * `Illuminate\Validation\Validator::validated()` throws whenever the message bag is
     * non-empty, and this callback is where messages are added - so calling it from here
     * would turn a 422 into a thrown `ValidationException` on the first error.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->assertWilayahCoherent($validator);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nama_lengkap' => 'nama lengkap',
            'tempat_lahir' => 'tempat lahir',
            'pekerjaan' => 'pekerjaan',
            'alamat_lengkap' => 'alamat lengkap',
            'golongan_darah_id' => 'golongan darah',
            'agama_id' => 'agama',
            'pendidikan_id' => 'pendidikan',
            'status_pernikahan_id' => 'status perkawinan',
            'provinsi_id' => 'provinsi',
            'kabupaten_kota_id' => 'kabupaten kota',
            'kecamatan_id' => 'kecamatan',
            'kelurahan_id' => 'kelurahan',
            'rt' => 'rt',
            'rw' => 'rw',
            'kode_pos' => 'kode pos',
            'tinggi_badan_cm' => 'tinggi badan',
            'berat_badan_kg' => 'berat badan',
        ];
    }

    /**
     * The `pasien` keys this request validated, as a plain list.
     *
     * The controller writes exactly these onto the `pasien` row and nothing else, so the
     * closed writable set the class docblock promises is a property of the returned array
     * rather than of a list the controller has to remember to honour. A key absent from
     * the rules can never appear here, whatever the client sent.
     *
     * @return list<string>
     */
    public function pasienKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->validated()),
            static fn (string $key): bool => $key !== self::USERS_KEY,
        ));
    }

    /**
     * Walk up from the most specific wilayah id present and confirm every ancestor agrees
     * with the value the request supplied or the row already holds.
     */
    private function assertWilayahCoherent(Validator $validator): void
    {
        $candidates = $this->wilayahCandidates();

        if ($candidates === []) {
            return;
        }

        // Narrowest first: a `kelurahan_id` implies all three of its ancestors, so
        // starting there resolves the longest chain in the fewest lookups.
        $leaf = null;

        foreach (array_reverse(self::BARE_REFERENCE_COLUMNS) as $column) {
            if (array_key_exists($column, $candidates)) {
                $leaf = $column;

                break;
            }
        }

        if ($leaf === null) {
            return;
        }

        $current = $candidates[$leaf];
        $column = $leaf;

        while (self::WILAYAH_CHAIN[$column]['parent_column'] !== null) {
            $parentColumn = self::WILAYAH_CHAIN[$column]['parent_column'];
            $parentId = DB::table(self::WILAYAH_CHAIN[$column]['tabel'])
                ->where('id', $current)
                ->value($parentColumn);

            // `Rule::exists` has already run, so the row exists and the column is
            // `NOT NULL`; a null here would mean the schema changed under the request, and
            // guessing is worse than skipping the check.
            if ($parentId === null) {
                return;
            }

            if (array_key_exists($parentColumn, $candidates) && (int) $candidates[$parentColumn] !== (int) $parentId) {
                $validator->errors()->add($parentColumn, 'Nilai ini tidak sesuai dengan '.$column.' yang dipilih.');

                return;
            }

            $current = $parentId;
            $column = $parentColumn;
        }
    }

    /**
     * The four wilayah values in force after this request: what the client sent, or what
     * the row already holds for a key the client did not send.
     *
     * The stored row is read with a direct keyed query rather than through
     * {@see ownPasien()}, which throws a 403. It must not: a validation callback has no
     * business producing an authorisation failure, and the controller raises the 403
     * immediately afterwards if one is owed. An account with no `pasien` row simply
     * contributes no stored values, and the check falls back to the supplied ones.
     *
     * @return array<string, int>
     */
    private function wilayahCandidates(): array
    {
        $user = $this->user();

        $candidates = [];

        if ($user !== null) {
            $pasien = Pasien::query()->where('user_id', $user->getKey())->first();

            if ($pasien !== null) {
                foreach (self::BARE_REFERENCE_COLUMNS as $column) {
                    $stored = $pasien->{$column};

                    if ($stored !== null) {
                        $candidates[$column] = (int) $stored;
                    }
                }
            }
        }

        foreach (self::BARE_REFERENCE_COLUMNS as $column) {
            if ($this->filled($column)) {
                $candidates[$column] = (int) $this->input($column);
            }
        }

        return $candidates;
    }
}
