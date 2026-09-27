<?php

declare(strict_types=1);

namespace App\Services\Dokter;

use App\Models\Dokter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only Eloquent projection of the view `v_dokter_katalog`.
 *
 * ## Why this class is NOT in `app/Models/`
 *
 * The plan's todo 22 says "wrap it in a `DokterKatalog` model", naming no namespace.
 * `App\Models` is not available for it, and the reason is a checked invariant rather
 * than a preference: `tests/Unit/Models/ModelFoundationTest.php:184-189` asserts that
 * `glob(app/Models/*.php)` yields **exactly 75** classes, one per contract table, and
 * the plan's own todo 19 acceptance criterion is `ls app/Models/*.php | wc -l`
 * equalling 75. A 76th file there breaks both, and todo 19 is closed. This view is
 * not one of the DDL's 75 tables, so the count must not move.
 *
 * ## The primary key is `dokter_id`, and that is load-bearing
 *
 * `telemedicine_test.sql:1172` is `d.id AS dokter_id`. The view therefore exposes **no
 * column called `id`**, so Eloquent's default `getKeyName()` of `id` produces
 * `where id = ?` against a result set that has no such column: `find()` and
 * `whereKey()` silently return nothing rather than raising, and route-model-binding
 * on this model would 404 every request. `protected $primaryKey = 'dokter_id'`
 * is what makes `DokterKatalog::find(3)` work, and `DokterDirectoryTest` pins that
 * with a test so a future "tidy-up" that drops the property fails loudly.
 *
 * ## `$timestamps = false`, and no `CREATED_AT` constant
 *
 * The view selects seven columns (`:1172-1178`) and neither `dibuat_at` nor
 * `diubah_at` is among them, so Eloquent has nothing to write and nothing to read.
 * Declaring the Indonesian `CREATED_AT`/`UPDATED_AT` constants here would make
 * `save()` try to write two columns the view does not have. The property is the
 * whole mechanism; the constants must stay absent.
 *
 * ## `$incrementing = false`: the projection is never written
 *
 * Nothing may be inserted through this model, so it must not claim it can allocate a
 * key. `keyType` is left at Eloquent's `'int'` default because `d.id` is
 * `BIGINT UNSIGNED` (`:410`) and `whereKey()`'s integer fast path depends on it.
 *
 * ## The three columns the view does NOT carry
 *
 * Migration 77's docblock records this, and it is restated because the service
 * depends on it:
 *
 * - `dokter.foto_profil` lives on `users` (`:141`), not on `dokter`, and the view
 *   joins `users` only to read `nama_lengkap` (`:1173`, `:1180`). The detail
 *   endpoint therefore reads the `user` relation from the `dokter` table, not here.
 * - `jumlah_ulasan` is `dokter.jumlah_ulasan` (`:424`), absent from the view's
 *   column list. Same answer.
 * - `status_aktif` is `dokter.status_aktif` (`:430`), and the view *filters* on it
 *   (`:1184`) without selecting it. A row in this projection is active by
 *   construction, so the detail endpoint re-reads it rather than assuming it.
 *
 * ## `spesialisasi` is a `GROUP_CONCAT` string, not a relation
 *
 * `:1178` aggregates `master_spesialisasi.nama` into one comma-separated value, and
 * the view carries **no** specialisation id and **no** `kode`. Filtering by
 * specialisation therefore has to join back to `dokter_spesialisasi`
 * (`:437-445`), which is what {@see DokterDirectoryService} does with an
 * `EXISTS` subquery. Two further properties of that aggregate are the DDL's, not
 * this class's: `telemedicine_test.sql` sets no `group_concat_max_len`, so the
 * server's session value (1024 by default) silently truncates a doctor with many
 * specialisations; and because the second `LEFT JOIN` is a `LEFT` one, a
 * `dokter_spesialisasi` row pointing at a deleted `master_spesialisasi` row yields
 * a `NULL` name, so `spesialisasi` is nullable by construction.
 *
 * ## `tipe` here is `dokter.tipe`, the SEVEN-value ENUM
 *
 * `:1174` selects `d.tipe` (`:412`) under a bare column name, and that ENUM shares
 * exactly **one** member, `dokter_umum`, with `master_spesialisasi.tipe`'s
 * three-value ENUM (`:406`). In particular `'spesialis'` is **not**
 * `'dokter_spesialis'`, and no other string comparison between the two columns
 * matches anything. A `?tipe=` doctor filter is therefore validated against the
 * seven `dokter.tipe` values and nothing else; see
 * {@see DokterDirectoryService::TIPE_DOKTER}.
 *
 * @property int|string|null $dokter_id
 * @property string|null $nama_lengkap
 * @property string|null $tipe
 * @property string|null $biaya_konsultasi_online
 * @property string|null $rating_rata_rata
 * @property int|null $jumlah_konsultasi
 * @property string|null $spesialisasi
 * @property-read Dokter $dokter
 */
class DokterKatalog extends Model
{
    /**
     * The view's seven column names, in the DDL's select-list order.
     *
     * `telemedicine_test.sql:1172`-`:1178` names exactly seven: `dokter_id`,
     * `nama_lengkap`, `tipe`, `biaya_konsultasi_online`, `rating_rata_rata`,
     * `jumlah_konsultasi`, `spesialisasi`. The list is needed because
     * {@see DokterDirectoryService} joins `dokter` and `users` into the same query,
     * and an unqualified `select *` would hydrate this model with `d.id`,
     * `d.user_id`, `d.nomor_str` and friends as well - including two different
     * columns both called `tipe`, whose resolution order is MySQL's business and not
     * this repository's. Selecting by name removes the ambiguity and the leak.
     *
     * `DokterDirectoryTest` reads the live view's column list out of
     * `information_schema` and asserts it is exactly this array, so a future edit to
     * the view that adds, drops or renames a column fails the suite instead of
     * silently publishing or dropping a field.
     *
     * @var list<string>
     */
    public const KOLOM = [
        'dokter_id',
        'nama_lengkap',
        'tipe',
        'biaya_konsultasi_online',
        'rating_rata_rata',
        'jumlah_konsultasi',
        'spesialisasi',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'v_dokter_katalog';

    /**
     * The model's primary key.
     *
     * `telemedicine_test.sql:1172` aliases `d.id` to `dokter_id`. Without this,
     * Eloquent queries a non-existent `id` column and every lookup returns nothing
     * without erroring.
     *
     * @var string
     */
    protected $primaryKey = 'dokter_id';

    /**
     * Indicates if the model should be timestamped.
     *
     * The view exposes neither `dibuat_at` nor `diubah_at`.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * False because a view cannot allocate a key: nothing in this model may be
     * inserted.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The `dokter` row behind this catalogue row.
     *
     * `v_dokter_katalog.dokter_id` is `d.id` (`:1172`) and `Dokter`'s key is `id`
     * (`:410`), so the join is 1:1 and this is a real `belongsTo` rather than a
     * guess. It exists so the STR-expiry predicate has a relation to hang off.
     *
     * @return BelongsTo<Dokter, $this>
     */
    public function dokter(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_id');
    }

    /**
     * {@see KOLOM}, table-qualified, ready for `Builder::select()`.
     *
     * The qualifier is what makes the seven names unambiguous once `dokter` and
     * `users` are joined into the same statement: four of them (`tipe`,
     * `rating_rata_rata`, `jumlah_konsultasi`, `biaya_konsultasi_online`) exist in
     * both `v_dokter_katalog` and `dokter` with the same meaning today and no
     * guarantee that they will tomorrow.
     *
     * @return list<string>
     */
    public static function kolomTerpilih(): array
    {
        return array_map(
            static fn (string $kolom): string => 'v_dokter_katalog.'.$kolom,
            self::KOLOM,
        );
    }
}
