<?php

declare(strict_types=1);

namespace App\Services\Dokter;

use App\Models\Dokter;
use App\Models\MasterSpesialisasi;
use App\Support\Dokter\StrBerlaku;
use App\Support\Schema\SqlSchemaParser;
use App\Support\WaktuIndonesia;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The public doctor directory: one place where "may this doctor be shown to a
 * patient?" is decided, and the only place the answer is written down.
 *
 * ## The two eligibility rules, and where each one comes from
 *
 * | rule | where it is enforced | DDL |
 * | --- | --- | --- |
 * | 1. verification | `v_dokter_katalog`'s own `WHERE` | `d.status_verifikasi = 'terverifikasi'` at `:1183`; column at `:427` |
 * | 2. STR not expired | {@see StrBerlaku}, called from {@see strBelumKedaluwarsa()} in this class | `d.str_berlaku_sampai` at `:414` |
 *
 * **Rule 1 is delegated, never restated.** The view is the DDL's own expression of
 * the visibility rule and it is read verbatim (migration 77 copies `:1170-1187`
 * byte-for-byte), so re-writing `status_verifikasi = 'terverifikasi'` in PHP would
 * be a second source of truth that could disagree with the first the moment either
 * changed. The same reasoning covers `status_aktif = 1` (`:1184`) and
 * `tersedia_telemedisin = 1` (`:1185`), which the view also encodes.
 *
 * **Rule 2 cannot be delegated, because the view does not contain it.** Reading
 * `:1179-1187` in full: the view's `FROM`/`JOIN`s are `dokter`, `users`,
 * `dokter_spesialisasi` and `master_spesialisasi`, and its `WHERE` is exactly the
 * three predicates above. `str_berlaku_sampai` appears nowhere in it. A directory
 * built on the view alone would therefore keep listing a doctor whose STR lapsed
 * last week, which is the patient-safety defect this class exists to prevent. So
 * the view supplies the row set and the `GROUP_CONCAT` specialisation list, and this
 * class adds the one predicate the DDL asks for and the view omits.
 *
 * The two are applied in the same query rather than in two passes, and both joins
 * added for rule 2 are 1:1 (`d.id` is `dokter`'s PK at `:410`, `u.id` is `users`'s PK
 * at `:133`), so `paginate()`'s `count(*)` stays correct.
 *
 * ## A third predicate the plan does not name: `users.dihapus_at IS NULL`
 *
 * `users.dihapus_at TIMESTAMP NULL` (`:148`) is the soft-delete marker, and
 * `User` carries `SoftDeletes` with `const DELETED_AT = 'dihapus_at'`. The view's
 * join is a plain `JOIN users u ON u.id = d.user_id` (`:1180`) with no `dihapus_at`
 * term, so a soft-deleted account's doctor stays in the public directory and its
 * owner's name keeps being served anonymously.
 *
 * `Dokter::user()` is declared `hasOne(User::class, 'user_id')`, which does **not**
 * inherit `User`'s `SoftDeletes` global scope, so the scope cannot be relied on to
 * hide it either. The predicate is therefore written explicitly, with the same
 * fail-closed reading as rule 2: an account whose state is not known-positive is not
 * shown. This is an addition to the plan's stated rules and is reported as a
 * finding in `.omo/evidence/task-22-sehatly.md`.
 *
 * ## The STR boundary is INCLUSIVE, and that is a decision rather than a default
 *
 * `str_berlaku_sampai` is a `DATE` (`:414`), not a `DATETIME`. It has no
 * time-of-day component, so it cannot lapse at some instant during the day: the last
 * moment of validity is the **end** of the date it names. `berlaku sampai
 * 2026-09-27` therefore means "valid through 2026-09-27", and the predicate is
 *
 *     str_berlaku_sampai >= <today>
 *
 * not `>`. A doctor whose STR expires today is still licensed today and is listed; a
 * doctor whose STR expired yesterday is not. The off-by-one in the other direction
 * (`>`) is not a safety win either: it would hide a currently-licensed doctor for a
 * whole day, which is a bookable-consultation denial rather than a patient-safety
 * protection. The boundary is pinned by a test on the exact boundary date, on both
 * sides of it.
 *
 * ## `<today>` is the ASIA/JAKARTA calendar day, and that is the load-bearing word
 *
 * The predicate above is incomplete as written, and the missing half is a real
 * compliance defect rather than a missing docblock. `<today>` is **not** the
 * server's date and **not** `config('app.timezone')`'s date. It is the clinic's
 * calendar day, read through {@see WaktuIndonesia} - see the section below for
 * the measurement. Written out in full, the rule is:
 *
 *     d.str_berlaku_sampai >= (today in Asia/Jakarta)
 *
 * Two different date bases are in play in this class, and they are not
 * interchangeable:
 *
 * | value | basis | where it comes from |
 * | --- | --- | --- |
 * | rule 1: `status_verifikasi = 'terverifikasi'` | none - it is a state, not a date | the view's own `WHERE` at `:1183` |
 * | `u.dihapus_at IS NULL` | none - also a state, and a `TIMESTAMP` that is only tested for NULLity, never compared | written at `:148` |
 * | rule 2: `str_berlaku_sampai >= <today>` | **Asia/Jakarta wall clock** | {@see today()}, which asks {@see WaktuIndonesia::now()} |
 *
 * Only the third row carries a date basis at all. `dihapus_at` is a `TIMESTAMP`
 * (an instant) and is only asked whether it is NULL, so no zone is involved; the
 * two state predicates cannot lapse at all. Everything that is a *day* in this
 * class therefore has exactly one basis, and it is the clinic's.
 *
 * {@see \Tests\Feature\Dokter\DokterStrZonaWaktuTest} pins that basis at both ends
 * of the seven-hour window in which the two candidates differ, and also asserts
 * the value the query BOUNDS rather than only the row set it returns.
 *
 * **The decision itself moved to {@see StrBerlaku} in todo 26, and this
 * section is now the ORIGIN of that decision rather than a second copy of
 * it.** `SlotAvailabilityService` needs the same boundary against a different
 * reference day, and a private method here could not be shared. The operator,
 * the inclusivity and the fail-closed NULL handling all live in that one class
 * now. This section stays because it is the argument for the decision, and an
 * argument that travels with the code is worth more than a cross-reference.
 *
 * ## What a NULL expiry means: EXCLUDED
 *
 * `str_berlaku_sampai` is `DATE NOT NULL` (`:414`), so the DDL makes a NULL
 * impossible - MySQL rejects one with 1048 regardless of `sql_mode`, because
 * `NOT NULL` is a hard constraint and not a strict-mode warning. `DokterDirectoryTest`
 * re-parses `:414` with the project's own `SqlSchemaParser` and asserts the column is
 * non-nullable, so the claim rests on the DDL rather than on this paragraph.
 *
 * The query still carries `whereNotNull`, because the predicate has to be
 * *well-defined* rather than merely *currently unreachable*: "we do not know when
 * this licence ends" is not evidence that the licence is valid, so the reading is
 * fail-closed. If the column were ever relaxed to nullable, this predicate already
 * excludes the unknown rather than admitting it.
 *
 * ## Why "today" is read from the CLINIC, and not from the database or from PHP
 *
 * `config/app.php` sets the application timezone to `UTC`, and
 * `config/database.php` pins the MySQL session to `+00:00` (`'timezone' =>
 * env('DB_TIMEZONE', '+00:00')`, for the `TIMESTAMP` reason quoted there). WIB is
 * UTC+07:00, so **for seven hours every day - 00:00 to 07:00 WIB - the UTC
 * calendar day and the Jakarta calendar day are different dates.** Anything that
 * asks the database or `Carbon` for "today" is therefore a day out during that
 * window, and a `DATE` comparison against it is a compliance defect rather than a
 * cosmetic one: it lists a doctor whose STR expired yesterday and hides one whose
 * STR runs out today.
 *
 * **This method used to ask the database.** It read
 * `SELECT CURDATE() AS hari` on the argument that "the same clock MySQL used when
 * it wrote the column" is the clock to compare with. That argument was wrong twice
 * over. The column was not written by MySQL - `str_berlaku_sampai` is authored by
 * an operator as a `DATE` and is a `DATE` in the DDL, so the wall clock it is
 * written in is the clinic's, not the server's. And once todo 51 pinned the
 * session to `+00:00`, `CURDATE()` had silently become the UTC day, so the
 * "agreement" it relied on was agreement with a clock nobody in the business uses.
 * Measured on this host at 2026-09-29 18:25 UTC: the Laravel connection reported
 * `@@session.time_zone = +00:00` and `CURDATE() = 2026-09-29` while
 * {@see WaktuIndonesia::tanggal()} reported `2026-09-30`.
 *
 * {@see WaktuIndonesia::now()} is the same instant as `now()` expressed on the
 * clinic's wall clock, which is the one value that is both the current moment and
 * the current day in the zone the licence is written in. It also honours
 * `Carbon::setTestNow()`, which `CURDATE()` never could - a boundary that cannot be
 * frozen cannot be tested at either end of its own window.
 *
 * {@see $asOf} still lets a caller (only a test, in practice) state the day
 * explicitly; the default is now the clinic's day rather than the server's.
 *
 * ## Ordering is total, on purpose
 *
 * The plan asks for `rating_rata_rata DESC` then `jumlah_konsultasi DESC`. That pair
 * is **not** a total order: on a fresh database every doctor carries the DDL
 * defaults `0.00` and `0` (`:423`, `:425`), and `rating_rata_rata` is
 * `DECIMAL(3,2)`, so ties are the normal case rather than the corner case. MySQL
 * leaves the relative order of tied rows unspecified, so `LIMIT/OFFSET` pagination
 * over that order can repeat a row on one page and skip it on the next.
 *
 * `dokter_id ASC` is therefore appended as a third sort key. It is `d.id` (`:1172`),
 * unique, so the order becomes total and pages become disjoint and stable. The plan's
 * two keys are kept in its order; the tiebreaker is additive.
 *
 * **The tiebreaker is asserted on the emitted SQL, not on the page contents, and
 * that is a measured decision rather than a stylistic one.** Deleting the
 * `orderBy('v_dokter_katalog.dokter_id')` line leaves every behavioural test green:
 * `v_dokter_katalog` groups on `d.id`, InnoDB answers that in clustered-index
 * order, and the untied rows therefore come back ascending by `d.id` anyway -
 * indistinguishable from the tiebreaker having done its job. A future index change,
 * a different optimiser, or a `WHERE` that stops using the clustered index would flip
 * the pages with nothing red. `DokterDirectoryTest`'s "the emitted ORDER BY really
 * carries the unique tiebreaker" asserts the SQL string instead, and the pagination
 * test keeps the behavioural half of the claim.
 */
class DokterDirectoryService
{
    /**
     * `dokter.tipe`, the seven values of `telemedicine_test.sql:412`, in DDL order.
     *
     * This is the **seven-value** ENUM, not `master_spesialisasi.tipe`'s three-value
     * one (`:406`); the two share only `dokter_umum`, and `'spesialis'` is not
     * `'dokter_spesialis'`. `DokterDirectoryTest` re-parses `:412` with
     * {@see SqlSchemaParser} and asserts this list is
     * byte-identical to the DDL's, in the DDL's order, so a typo here fails the suite
     * rather than reaching a `Rule::in` that silently rejects every real request.
     *
     * @var list<string>
     */
    public const TIPE_DOKTER = [
        'dokter_umum',
        'dokter_spesialis',
        'dokter_gigi',
        'psikolog',
        'bidan',
        'perawat',
        'apoteker',
    ];

    /** Rows per page when `per_page` is absent. */
    public const PER_PAGE_DEFAULT = 15;

    /**
     * The project-wide cap on `per_page`.
     *
     * The plan's todo 21 fixes it at 100 for every list endpoint, and an
     * unauthenticated public endpoint is where an uncapped `per_page` would be most
     * abusable, so the same number is used here.
     */
    public const PER_PAGE_MAX = 100;

    /**
     * `dokter.nomor_str` is `VARCHAR(30) NOT NULL UNIQUE` (`:413`).
     *
     * The route segment is bounded by the DDL's own storage width so a 4 KB
     * identifier cannot be carried into a query parameter, and so the value the DDL
     * could hold is a value the endpoint accepts.
     */
    public const ID_MAX = 20;

    /**
     * `master_spesialisasi.kode` is `VARCHAR(10) NOT NULL UNIQUE` (`:404`).
     *
     * `spesialisasi` accepts either that code or the `SMALLINT UNSIGNED` id (`:403`),
     * and both fit inside ten characters.
     */
    public const SPESIALISASI_MAX = 10;

    /** `users.nama_lengkap` is `VARCHAR(150) NOT NULL` (`:135`). */
    public const SEARCH_MAX = 150;

    /**
     * The resolved "today" for one instance, cached per request.
     *
     * Two directory calls in the same request must agree on the boundary, or a
     * midnight rollover between the list and a detail fetch could make one say
     * "eligible" and the other say "expired". Null until the first call.
     *
     * `CarbonInterface`, never `Carbon`: {@see WaktuIndonesia::now()} returns a
     * `Carbon\CarbonImmutable`, which is a **sibling** of
     * `Illuminate\Support\Carbon` and not an instance of it, so a narrower
     * property type raises a `TypeError` the first time the rule is exercised -
     * on a path that looks correct in every editor and in every code review.
     */
    private ?CarbonInterface $today = null;

    /**
     * `GET /api/v1/dokter` - one page of eligible doctors.
     *
     * @param  array<string, mixed>  $filters  the FormRequest's `validated()` output
     * @param  CarbonInterface|null  $asOf  the reference day for rule 2, overriding
     *                                     {@see today()}; `null` means the clinic's today
     * @return LengthAwarePaginator<int, DokterKatalog>
     */
    public function list(array $filters, ?CarbonInterface $asOf = null): LengthAwarePaginator
    {
        $query = $this->query($asOf);

        $this->applyTipe($query, $filters);
        $this->applySpesialisasi($query, $filters);
        $this->applySearch($query, $filters);
        $this->applyTersediaTelemedisin($query, $filters);

        return $query
            ->orderByDesc('v_dokter_katalog.rating_rata_rata')
            ->orderByDesc('v_dokter_katalog.jumlah_konsultasi')
            ->orderBy('v_dokter_katalog.dokter_id')
            ->paginate($this->perPage($filters))
            ->withQueryString();
    }

    /**
     * `GET /api/v1/dokter/{dokter}` - one eligible doctor's full profile, or `null`.
     *
     * **`null` is the answer for absent, unverified, inactive, telemedicine-opted-out,
     * STR-expired and soft-deleted-account alike.** They are not distinguished: a
     * caller that could tell "this id is not verified" from "this id does not exist"
     * could enumerate the verification state of every doctor account in the system
     * from an unauthenticated endpoint, which is exactly what rule 1 exists to
     * prevent. The controller turns every `null` into the same 404 envelope.
     *
     * `spesialisasi`, `pendidikan` and `faskes` are eager-loaded here rather than in
     * the resource, so the resource cannot accidentally issue N+1 queries if it is
     * ever reused. `DokterSpesialisasi` is ordered `is_utama DESC` first (the plan's
     * rule) and `DokterPendidikan` `tahun_lulus DESC`; both get a unique tiebreaker
     * appended for the same reason the list order does - a `SMALLINT UNSIGNED NULL`
     * year and a `TINYINT(1)` flag both tie often.
     */
    public function find(int $dokterId, ?CarbonInterface $asOf = null): ?Dokter
    {
        /** @var Dokter|null $dokter */
        $dokter = $this->query($asOf)
            ->where('v_dokter_katalog.dokter_id', $dokterId)
            ->first();

        if ($dokter === null) {
            return null;
        }

        // `v_dokter_katalog` has no `foto_profil`, no `jumlah_ulasan` and no
        // `status_aktif`, so the detail row is re-read from `dokter` plus its
        // `user` relation. The eligibility join above already proved the row exists
        // and is eligible, so this second read cannot widen the answer.
        $detail = Dokter::query()
            ->with([
                'user',
                // The eager-load closure is handed the RELATION (`HasMany`), not a
                // `Builder`, and its return value is discarded - so neither the
                // parameter nor the return may be type-hinted to `Builder`.
                'dokterSpesialisasi' => fn ($q) => $q
                    ->orderByDesc('is_utama')
                    ->orderBy('spesialisasi_id'),
                'dokterSpesialisasi.spesialisasi',
                'dokterPendidikan' => fn ($q) => $q
                    // MySQL sorts `NULL` last under `DESC`, so a doctor with an
                    // unrecorded graduation year lists their dated qualifications
                    // first and the unknown one last.
                    ->orderByDesc('tahun_lulus')
                    ->orderBy('id'),
                'dokterFaskes' => fn ($q) => $q
                    ->orderByDesc('is_utama')
                    ->orderBy('faskes_id'),
                'dokterFaskes.faskes',
            ])
            ->find($dokterId);

        // The row cannot vanish between the two reads in any realistic case, but a
        // `null` here must still be a 404 rather than a resource built over `null`,
        // so the same answer is returned rather than an exception.
        return $detail instanceof Dokter ? $detail : null;
    }

    /**
     * `GET /api/v1/master-spesialisasi` - the filter's reference list.
     *
     * `master_spesialisasi` is `AUTO_INCREMENT` on its `id` (`:403`) and its
     * `kode` is the DDL's own stable handle (`:404`), so the rows are ordered by
     * `kode` rather than by `id`: an id is a per-database artefact while a code is
     * what a client persists and what the DDL seeds (`:1237`-`:1252`).
     *
     * @return Collection<int, MasterSpesialisasi>
     */
    public function spesialisasi(): Collection
    {
        return MasterSpesialisasi::query()->orderBy('kode')->get();
    }

    /**
     * The eligible-doctor query: the view, plus rule 2, plus `dihapus_at`.
     *
     * Every column of the view and of `dokter` is table-qualified from here on,
     * because the two share `tipe`, `rating_rata_rata`, `jumlah_konsultasi` and
     * `biaya_konsultasi_online` and an unqualified reference is a 1052 "Column
     * ambiguous" at best.
     *
     * @return Builder<DokterKatalog>
     */
    private function query(?CarbonInterface $asOf): Builder
    {
        $query = DokterKatalog::query()
            ->select(DokterKatalog::kolomTerpilih())
            ->join('dokter as d', 'd.id', '=', 'v_dokter_katalog.dokter_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->whereNull('u.dihapus_at');

        $this->strBelumKedaluwarsa($query, $asOf);

        return $query;
    }

    /**
     * Rule 2, on its own, so the boundary has exactly one spelling in the codebase.
     *
     * The decision itself lives in {@see StrBerlaku}, which todo 26's
     * `SlotAvailabilityService` also calls. Both services ask the same question
     * -- is this licence valid on this day -- against different reference days,
     * and only this one is about visibility while the other is about a specific
     * consultation. Two spellings of the inclusive boundary would be two sources
     * of truth, and the looser one is the one that would let an unlicensed
     * doctor take a consultation.
     *
     * **The reference day this method passes is the ASIA/JAKARTA day** - see the
     * `<today>` section of the class docblock. `SlotAvailabilityService` passes a
     * DATE the caller asked about instead, which is a third basis again and is
     * correct there: it is neither "now" nor a wall clock, it is the consultation
     * date the request named.
     *
     * Nested inside a `where(function ...)` by that class rather than by this
     * method because a caller may itself be inside a nested closure; the
     * grouping keeps the `NOT NULL` and the comparison from ever being separated
     * by an `or` a future filter might introduce. The `or` case is the one that
     * would matter: `A OR B` with `A` being a two-clause rule splits into
     * `A1 OR A2`, and `A2` alone admits the NULL.
     */
    private function strBelumKedaluwarsa(Builder $query, ?CarbonInterface $asOf): void
    {
        StrBerlaku::terapkan($query, 'd.str_berlaku_sampai', $this->today($asOf));
    }

    /**
     * Today, on the **clinic's** calendar, or the caller's explicit day.
     *
     * The basis is `Asia/Jakarta` and the source is {@see WaktuIndonesia::now()},
     * which is the same instant as `now()` expressed on that wall clock. It is
     * deliberately neither `SELECT CURDATE()` nor `Carbon::today()`: the MySQL
     * session is pinned to `+00:00` and `config/app.php` is `UTC`, so for seven
     * hours every day both of those name the day BEFORE the clinic's. The class
     * docblock carries the measurement and the consequence.
     *
     * Cached per instance so every predicate in one request compares against the
     * same value; a midnight rollover between the list query and the detail query
     * must not be able to answer them differently.
     */
    private function today(?CarbonInterface $asOf): CarbonInterface
    {
        if ($asOf !== null) {
            return $asOf->copy()->startOfDay();
        }

        if ($this->today === null) {
            $this->today = WaktuIndonesia::now()->startOfDay();
        }

        return $this->today->copy();
    }

    /**
     * `per_page`, already bounded by {@see PER_PAGE_MAX} in the FormRequest.
     */
    private function perPage(array $filters): int
    {
        $perPage = $filters['per_page'] ?? self::PER_PAGE_DEFAULT;

        return is_numeric($perPage) ? (int) $perPage : self::PER_PAGE_DEFAULT;
    }

    /**
     * `?tipe=` against `dokter.tipe`'s seven values.
     *
     * Applied to the **view's** column rather than `d.tipe`. They are the same
     * expression (`d.tipe` aliased as `tipe` at `:1174`), and using the view's keeps
     * every filter in one place in this method; the alias is a DDL-written identity,
     * not a join artefact.
     */
    private function applyTipe(Builder $query, array $filters): void
    {
        if (isset($filters['tipe'])) {
            $query->where('v_dokter_katalog.tipe', (string) $filters['tipe']);
        }
    }

    /**
     * `?spesialisasi=` by `master_spesialisasi.kode` **or** by its `id`.
     *
     * The view has no specialisation id and no `kode` - only the `GROUP_CONCAT` of
     * names (`:1178`) - so the filter has to go back to `dokter_spesialisasi`
     * (`:437`-`:445`). Both accepted forms are matched in one `EXISTS`, so a caller
     * does not have to know which vocabulary it is holding: `SP.PD` fails the id
     * comparison and `3` fails the `kode` comparison, and neither can be ambiguous
     * because `kode` is a non-numeric code in every one of the DDL's 16 seed rows.
     *
     * `EXISTS` and not a join: `uq_dokter_spes (dokter_id, spesialisasi_id)` (`:444`)
     * stops one doctor appearing twice per specialisation, but a doctor with three
     * specialisations would still fan out to three rows under a join, which would
     * inflate both `count(*)` and `per_page` and let one doctor occupy three slots
     * on one page. `EXISTS` cannot.
     */
    private function applySpesialisasi(Builder $query, array $filters): void
    {
        if (! isset($filters['spesialisasi'])) {
            return;
        }

        $nilai = (string) $filters['spesialisasi'];

        $query->whereExists(function ($sub) use ($nilai): void {
            $sub->selectRaw('1')
                ->from('dokter_spesialisasi')
                ->join('master_spesialisasi', 'master_spesialisasi.id', '=', 'dokter_spesialisasi.spesialisasi_id')
                ->whereColumn('dokter_spesialisasi.dokter_id', 'v_dokter_katalog.dokter_id')
                // `whereExists` hands the closure a QUERY builder, not the Eloquent
                // one, so neither this closure nor the nested `where` closure may be
                // type-hinted to `Illuminate\Database\Eloquent\Builder`.
                ->where(function ($inner) use ($nilai): void {
                    $inner->where('master_spesialisasi.kode', $nilai);

                    // The id branch is added only for a numeric value, so the string
                    // `'SP.PD'` is never implicitly cast to the number 0 on its way
                    // into a `SMALLINT UNSIGNED` comparison.
                    if (ctype_digit($nilai)) {
                        $inner->orWhere('master_spesialisasi.id', (int) $nilai);
                    }
                });
        });
    }

    /**
     * `?search=` against `users.nama_lengkap` (`:135`), case-insensitively.
     *
     * The view already selects that column (`:1173`), so this is a filter on a
     * published projection and needs no join of its own.
     *
     * **The caller's `%` and `_` are escaped.** `addcslashes` neutralises the three
     * LIKE metacharacters and MySQL's default LIKE escape character is a backslash,
     * so a search for `%` matches a literal percent sign instead of every row. It is
     * not a security control - nothing here is injectable, the value is a bound
     * parameter - but an unescaped wildcard is a wrong answer rather than an exploit,
     * and a caller typing `100%` means the characters, not the wildcard.
     */
    private function applySearch(Builder $query, array $filters): void
    {
        if (! isset($filters['search'])) {
            return;
        }

        $cari = trim((string) $filters['search']);

        if ($cari === '') {
            return;
        }

        $query->where(
            'v_dokter_katalog.nama_lengkap',
            'like',
            '%'.addcslashes($cari, '\\%_').'%',
        );
    }

    /**
     * `?tersedia_telemedisin=` - applied in both directions, and one of them is
     * always empty.
     *
     * `v_dokter_katalog` requires `d.tersedia_telemedisin = 1` in its own `WHERE`
     * (`:1185`), so every row in the projection already has it. The filter is
     * therefore applied for real rather than ignored: `= 1` restates the view and
     * changes nothing, and `= 0` asks for doctors the view has already removed, so
     * the honest answer is an empty page.
     *
     * The alternative - leaving `= 0` unfiltered and returning every eligible
     * doctor - answers a different question than the one asked, which is a worse
     * bug than the empty result is. And the alternative of dropping the view's
     * predicate so the filter has something to do would mean re-deriving the whole
     * visibility rule in this class and would list a doctor who has opted out of
     * telemedicine, which migration 77's docblock names as one of the three
     * predicates that must survive.
     */
    private function applyTersediaTelemedisin(Builder $query, array $filters): void
    {
        if (! array_key_exists('tersedia_telemedisin', $filters)) {
            return;
        }

        // `tersedia_telemedisin` is `TINYINT(1) NOT NULL DEFAULT 1` (`:426`). The
        // value is whatever the query string carried, so `FILTER_VALIDATE_BOOLEAN`
        // with `FILTER_NULL_ON_FAILURE` is the right reader: it accepts the six
        // spellings Laravel's `boolean` rule accepts and answers `null` for anything
        // else, which the FormRequest has already turned into a 422.
        $nilai = filter_var($filters['tersedia_telemedisin'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $query->where('d.tersedia_telemedisin', $nilai === true);
    }
}
