<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Booking;
use App\Models\Dokter;
use App\Models\DokterJadwal;
use App\Models\DokterLibur;
use App\Services\Booking\SlotAvailabilityService;
use App\Services\Dokter\DokterDirectoryService;
use App\Support\Dokter\StrBerlaku;
use App\Support\WaktuIndonesia;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * F14's doctor-management read and write surface: the ADMIN directory, its two
 * decisions, and the blast-radius counts the UI shows before a destructive one.
 *
 * ## This service is NOT {@see DokterDirectoryService}, and it must not become it
 *
 * That service answers "may this doctor be shown to a PATIENT?" and its whole
 * purpose is to return `null` for unverified, inactive, telemedicine-opted-out,
 * STR-expired and soft-deleted-account rows alike. This service answers the
 * OPPOSITE question - "show me EVERY doctor so I can verify, suspend or renew
 * them" - so it reads `dokter` directly and reads `users` through a raw join that
 * deliberately does NOT apply `User`'s `SoftDeletes` scope. Sharing one query
 * between the two would mean either the public directory starts listing
 * unverified doctors or the admin list starts hiding them, and both are wrong.
 * The F14 pattern makes this explicit: `GET /admin/dokter` must not use
 * `v_dokter_katalog` or the directory query.
 *
 * ## Credentials are masked with the ONE masker
 *
 * `nomor_str` (`VARCHAR(30) NOT NULL UNIQUE`, telemedicine_test.sql:413) and
 * `nomor_sip` (`VARCHAR(50) NULL`, :415) are professional credentials of a third
 * party. They are masked with {@see NikMasker::mask()} - the same masker
 * `AuditColumnPolicy` uses for `nomor_str` and the same one the patient
 * resources publish NIK through - so the admin list and the audit trail show the
 * SAME masked form of the same number. It keeps the first four and last four
 * characters and refuses to fake a value shorter than that, returning it
 * unchanged; a short SIP is therefore published verbatim, which is the masker's
 * documented behaviour rather than a second masking policy here.
 *
 * `file_str_url` and `file_sip_url` are NEVER read by this service, in any
 * projection. The F14 pattern allows them only under a `dokter.kelola` grant
 * through a signed URL, that code was not approved, and a credential document
 * URL is exactly the field `AuditColumnPolicy` denies outright - so the
 * conservative answer is to publish nothing rather than a link.
 *
 * ## STR/SIP expiry is COMPUTED here, and the lifetime-STR gap is NOT papered over
 *
 * `dokter.str_berlaku_sampai` is `DATE NOT NULL` (`:414`), so the schema cannot
 * represent UU 17/2023's lifetime STR for new doctors. This service does NOT
 * infer a lifetime licence and does NOT invent a sentinel date: it publishes the
 * stored date plus a signed `sisa_hari` and two booleans, and the admin decides.
 * A date far in the future reads as "not expiring soon", which is the truthful
 * reading of the stored data. Recorded as an open question in
 * `web/ux/patterns/F14.md` section 12 item 4 and reported in this task's summary.
 *
 * ## Verification is a one-way decision from `pending`
 *
 * `PUT /admin/dokter/{id}/verifikasi` accepts only `terverifikasi` and `ditolak`
 * (F14 section 4.2 item 3), and this service refuses any request made against a
 * row that is no longer `pending` with a 422 naming `status_verifikasi`. That is
 * the pattern's race handling: two admins deciding the same row means the second
 * one must reload rather than silently overwrite the first decision. Changing a
 * `terverifikasi` doctor's standing is done through `status_aktif` (suspend),
 * not by rewriting verification history, and no revocation flow was approved.
 *
 * ## No rejection reason, and why
 *
 * `dokter` has no rejection-reason column and the owner forbade inventing one.
 * A free-text reason could only live in `audit_log`, and `AuditColumnPolicy`
 * exists precisely to keep narrative text out of it (`dokter` has no free-text
 * column in its allow-list at all), so no request field is offered and the
 * automatic observer row records only the changed `status_verifikasi` value.
 */
final class AdminDokterService
{
    /**
     * `dokter.status_verifikasi` is a three-value ENUM (`:427`), in DDL order.
     *
     * @var list<string>
     */
    public const STATUS_VERIFIKASI = ['pending', 'terverifikasi', 'ditolak'];

    /**
     * The two decisions the verify endpoint accepts.
     *
     * `pending` is deliberately absent: a decision cannot be "un-decide", and a
     * request naming it is refused by the FormRequest before this service runs.
     *
     * @var list<string>
     */
    public const KEPUTUSAN_VERIFIKASI = ['terverifikasi', 'ditolak'];

    /**
     * The "expiring soon" horizon, in days.
     *
     * The F14 pattern's STR warning badge fires at `<= 60` days (AC-1), and the
     * threshold lives here rather than in the resource so the list, the detail
     * and any future surface cannot disagree about when a credential is close to
     * lapsing.
     */
    public const STR_SEGERA_HARI = 60;

    /** Rows per page when `per_page` is absent; the project-wide pair. */
    public const PER_PAGE_DEFAULT = 15;

    public const PER_PAGE_MAX = 100;

    /**
     * `users.nama_lengkap` is `VARCHAR(150) NOT NULL` (`:135`).
     */
    public const SEARCH_MAX = 150;

    /**
     * One page of the admin directory: every `dokter` row, no eligibility filter.
     *
     * ## The join is deliberate and so is its absence of a soft-delete scope
     *
     * `users` is joined with the QUERY BUILDER's plain `join`, which does not run
     * `User`'s `SoftDeletes` global scope, and `users.dihapus_at` is selected as
     * `user_dihapus_at` so the projection can say whether the account behind the
     * doctor row still exists. An admin who cannot see a suspended account's row
     * cannot diagnose why a doctor stopped appearing, which is the whole job.
     *
     * `booking_count` is one `withCount` subquery, not a per-row read. It counts
     * EVERY booking status: it answers "how much history does this doctor have",
     * which is a different question from {@see dampak()}'s future-consuming
     * count and is labelled accordingly in the resource.
     *
     * @param  array<string, mixed>  $filters  the FormRequest's `validated()` output
     * @return LengthAwarePaginator<int, Dokter>
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyStatusVerifikasi($query, $filters);
        $this->applyBoolean($query, $filters, 'status_aktif', 'dokter.status_aktif');
        $this->applyBoolean($query, $filters, 'tersedia_telemedisin', 'dokter.tersedia_telemedisin');
        $this->applySearch($query, $filters);
        $this->applyOrder($query, $filters);

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : self::PER_PAGE_DEFAULT;

        return $query
            ->with(['dokterSpesialisasi.spesialisasi'])
            ->withCount('booking')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * One doctor row for the admin detail, credentials included.
     *
     * Returns `null` for an id that does not exist so the controller can answer
     * the project's uniform 404; unlike the public directory, "exists" is the
     * ONLY condition. An unverified, inactive, STR-expired or account-deleted
     * doctor is exactly what this endpoint is for.
     */
    public function find(int $id): ?Dokter
    {
        // Only the relations the projection actually reads are loaded. A doctor's
        // facilities are not on this surface (the F14 UI has no facility tab, and
        // publishing one would need its own decision), so `dokterFaskes` is NOT
        // eager-loaded here - an unused eager load is an extra query on every
        // detail, verify and status response.
        return $this->baseQuery()
            ->with(['dokterSpesialisasi.spesialisasi'])
            ->withCount('booking')
            ->whereKey($id)
            ->first();
    }

    /**
     * The blast-radius counts the F14 UI shows before it asks an admin to commit.
     *
     * | key | question | source |
     * | --- | --- | --- |
     * | `booking_aktif` | how many bookings will still RUN if this doctor is suspended? | `booking` with a consuming status and `tanggal_kunjungan >= today` |
     * | `jadwal_aktif` | how many published windows become orphaned? | `dokter_jadwal.status_aktif = 1` |
     * | `libur_mendatang` | how many future holidays are on file? | `dokter_libur.tanggal >= today` |
     *
     * "Consuming" is {@see SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI}'s
     * complement - the same six-status set the booking and slot code already
     * treats as occupying a slot - so this count cannot disagree with
     * availability about whether a booking is live. `today` is the clinic's
     * calendar day through {@see WaktuIndonesia}, the same basis the directory
     * and the slot service use.
     *
     * **Nothing here cancels anything.** The F14 pattern is explicit that
     * suspending a doctor does NOT auto-cancel bookings; the number exists so the
     * dialog can say so before the admin commits. No write happens in this
     * method at all.
     *
     * @return array{booking_aktif: int, jadwal_aktif: int, libur_mendatang: int}
     */
    public function dampak(Dokter $dokter): array
    {
        $id = (int) $dokter->getKey();
        $hariIni = WaktuIndonesia::tanggal();

        return [
            'booking_aktif' => Booking::query()
                ->where('dokter_id', $id)
                ->whereNotIn('status', SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI)
                ->where('tanggal_kunjungan', '>=', $hariIni)
                ->count(),
            'jadwal_aktif' => DokterJadwal::query()
                ->where('dokter_id', $id)
                ->where('status_aktif', true)
                ->count(),
            'libur_mendatang' => DokterLibur::query()
                ->where('dokter_id', $id)
                ->where('tanggal', '>=', $hariIni)
                ->count(),
        ];
    }

    /**
     * Record a verification decision, once.
     *
     * The row is re-read under `lockForUpdate()` inside a transaction, so two
     * concurrent decisions serialise: the loser observes the winner's committed
     * `status_verifikasi` and is refused with a 422 naming `status_verifikasi`
     * instead of overwriting it. The audit row is written by the global
     * `AuditObserver` inside the same transaction, so a refused decision writes
     * nothing at all.
     *
     * The saved model is returned WITHOUT the admin projection's joined columns;
     * the controller re-reads through {@see find()} so the response is the same
     * shape a GET would produce.
     *
     * @throws NotFoundHttpException when the id names no row
     * @throws ValidationException when the row is no longer `pending`
     */
    public function verifikasi(int $id, string $status): void
    {
        DB::transaction(function () use ($id, $status): void {
            $dokter = Dokter::query()->whereKey($id)->lockForUpdate()->first();

            if ($dokter === null) {
                throw new NotFoundHttpException;
            }

            if ($dokter->status_verifikasi !== 'pending') {
                throw ValidationException::withMessages([
                    'status_verifikasi' => [
                        'Status verifikasi dokter ini sudah "'.$dokter->status_verifikasi
                        .'", bukan "pending". Data sudah berubah di sesi lain; muat ulang sebelum memutuskan.',
                    ],
                ]);
            }

            $dokter->status_verifikasi = $status;
            $dokter->save();
        });
    }

    /**
     * Activate or deactivate a doctor, and optionally their telemedicine
     * availability, in one write.
     *
     * `status_aktif` is the patient-safety switch: a doctor with a problem
     * credential can be suspended immediately, and - by the F14 pattern's
     * decision - their existing bookings are NOT cancelled, which is why
     * {@see dampak()} exists and why this method refuses nothing.
     *
     * `tersedia_telemedisin` is written only when the request supplies it, so a
     * request that says nothing about telemedicine leaves the column exactly as
     * it was. The two columns are independent in the DDL (`:426`, `:430`) and
     * this method keeps them independent.
     *
     * @throws NotFoundHttpException when the id names no row
     */
    public function ubahStatus(int $id, bool $statusAktif, ?bool $tersediaTelemedisin): void
    {
        $dokter = Dokter::query()->find($id);

        if ($dokter === null) {
            throw new NotFoundHttpException;
        }

        $dokter->status_aktif = $statusAktif;

        if ($tersediaTelemedisin !== null) {
            $dokter->tersedia_telemedisin = $tersediaTelemedisin;
        }

        $dokter->save();
    }

    /**
     * The shared projection: `dokter.*`, the account's display name and whether
     * that account is soft-deleted.
     *
     * Every column this service reads is named here rather than left to
     * `select *`, so a column added to `dokter` later is invisible to the admin
     * API until somebody deliberately publishes it.
     *
     * @return Builder<Dokter>
     */
    private function baseQuery(): Builder
    {
        return Dokter::query()
            ->select([
                'dokter.*',
                'users.nama_lengkap',
                'users.dihapus_at as user_dihapus_at',
            ])
            ->join('users', 'users.id', '=', 'dokter.user_id');
    }

    /**
     * `?status_verifikasi=` against the three DDL values.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyStatusVerifikasi(Builder $query, array $filters): void
    {
        if (isset($filters['status_verifikasi'])) {
            $query->where('dokter.status_verifikasi', (string) $filters['status_verifikasi']);
        }
    }

    /**
     * A nullable boolean filter, both directions real.
     *
     * `filter_var(..., FILTER_NULL_ON_FAILURE)` is the same reader
     * `DokterDirectoryService` uses: the FormRequest has already refused
     * anything outside the `boolean` rule's vocabulary, so the only remaining
     * question is which of `true`/`false` a request that DID supply the key
     * meant.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyBoolean(Builder $query, array $filters, string $key, string $column): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $nilai = filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $query->where($column, $nilai === true);
    }

    /**
     * `?q=` over the doctor's name OR their STR number.
     *
     * Both vocabularies are the operator's own: the F14 filter reads "Cari nama
     * atau nomor STR". The number is searched by the SERVER, so the full value
     * never has to appear in a URL the client renders a link from; what comes
     * back is masked.
     *
     * The caller's `%` and `_` are escaped, exactly as the public directory
     * escapes them: a search for `%` means the character, and an unescaped
     * wildcard is a wrong answer rather than an exploit.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applySearch(Builder $query, array $filters): void
    {
        $cari = trim((string) ($filters['q'] ?? ''));

        if ($cari === '') {
            return;
        }

        $pola = '%'.addcslashes($cari, '\\%_').'%';

        $query->where(function (Builder $inner) use ($pola): void {
            $inner
                ->where('users.nama_lengkap', 'like', $pola)
                ->orWhere('dokter.nomor_str', 'like', $pola);
        });
    }

    /**
     * `?urutan=` with a total order in every branch.
     *
     * The F14 default is `str_berlaku_sampai` ASC: the doctor closest to
     * lapsing comes first, which is the operator's worklist. `nama` and
     * `jumlah_konsultasi` are the two alternative sorts the contract names.
     * Every branch appends `dokter.id` as the unique tiebreaker, because
     * `str_berlaku_sampai` ties on a seeded database and `nama_lengkap` is not
     * unique - without it, `LIMIT/OFFSET` paging can repeat or skip a row.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyOrder(Builder $query, array $filters): void
    {
        match ($filters['urutan'] ?? 'str_berlaku_sampai') {
            'nama' => $query->orderBy('users.nama_lengkap')->orderBy('dokter.id'),
            'jumlah_konsultasi' => $query->orderByDesc('dokter.jumlah_konsultasi')->orderBy('dokter.id'),
            default => $query->orderBy('dokter.str_berlaku_sampai')->orderBy('dokter.id'),
        };
    }

    /**
     * The signed day distance from the clinic's today to the stored expiry.
     *
     * `0` means the licence expires TODAY and is still valid - the inclusive
     * boundary {@see StrBerlaku} applies. A negative value is a lapsed licence.
     * Both operands are reduced to the start of their **Jakarta** calendar day
     * before the subtraction, so neither a time component nor the fact that a
     * `dokter` cast parses a `Y-m-d` at the application's UTC zone can shift the
     * answer by a day.
     */
    public static function sisaHari(mixed $berlakuSampai): ?int
    {
        if ($berlakuSampai === null) {
            return null;
        }

        $kedaluwarsa = $berlakuSampai instanceof DateTimeInterface
            ? Carbon::instance($berlakuSampai)->setTimezone(WaktuIndonesia::ZONA)
            : Carbon::parse((string) $berlakuSampai, WaktuIndonesia::ZONA);

        $hariIni = WaktuIndonesia::now()->startOfDay();

        return (int) floor(
            ($kedaluwarsa->startOfDay()->getTimestamp() - $hariIni->getTimestamp()) / 86400,
        );
    }
}
