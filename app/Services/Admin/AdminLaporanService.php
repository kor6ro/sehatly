<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\InvoiceStatus;
use App\Http\Requests\Booking\BookingRequest;
use App\Models\Booking;
use App\Models\Invoice;
use App\Support\Rbac\RbacCatalog;
use App\Support\Uang\Uang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * F14's three report aggregates: bookings by status, settled revenue, and the
 * visit-lifecycle counts.
 *
 * ## Aggregates ONLY - this class cannot leak clinical content
 *
 * Every query below selects counts, dates and money. There is no `pasien` join,
 * no `rekam_medis` read, no `keluhan` and no diagnosis anywhere in this file,
 * which is the structural half of the F14 privacy rule: `admin` holds no
 * `rekam_medis.lihat` and no `resep.lihat` in
 * {@see RbacCatalog}, and a report that cannot name a patient
 * cannot surface a patient's care. The booking count is grouped by status and
 * date; the money report is grouped by invoice date; the attendance report is
 * grouped by status and date. Daily rows carry no ids.
 *
 * ## The three date bases, and why they differ
 *
 * | report | column | basis | treatment |
 * | --- | --- | --- | --- |
 * | booking | `booking.tanggal_kunjungan` | clinic wall-clock `DATE` | filtered directly as `Y-m-d` |
 * | pendapatan | `invoice.lunas_at` | UTC instant (`DATETIME`) | the WIB range is converted to UTC instants; days are grouped back in WIB |
 * | kehadiran | `booking.tanggal_kunjungan` | clinic wall-clock `DATE` | filtered directly as `Y-m-d` |
 *
 * A `DATE` in this schema is never converted (`docs/timezone-policy.md`), so the
 * first and third compare the request's dates directly. `lunas_at` is an
 * instant, so it goes through {@see RentangHari} - otherwise every report would
 * lose the seven evening hours of the clinic's day.
 *
 * ## Revenue is "settled", not "captured"
 *
 * The revenue report sums `invoice.total` where `invoice.status = 'lunas'` -
 * {@see InvoiceStatus::Lunas} - and NOTHING else. F12's refund ledger moves a
 * refunded booking's invoice to `refund_penuh` (`RefundService::catatPembatalan()`),
 * so a refunded invoice is excluded and the figure is money the clinic KEEPS.
 * A `refund_sebagian` invoice is also excluded, which is consistent: partial
 * refunds have no writer today, and if one arrives the report must decide
 * explicitly whether to show gross or net rather than silently including it.
 *
 * **The number is read, never recomputed.** `invoice.total` is the stored total
 * (`InvoiceService`/`BookingService` wrote it when the invoice was issued), so
 * this report cannot disagree with the invoice a patient holds. `SUM` over
 * `DECIMAL(14,2)` returns a string and is normalised through {@see Uang}, which
 * never goes through a float.
 *
 * **`meta.peringatan_ledger` is deliberately absent.** The F14 pattern's open
 * question 5 asked whether the revenue report should carry a warning while
 * `BookingService::batalkan()` destroyed the ledger; F12 then fixed that P0
 * (`b672a75`, the refund row + payment status + invoice status move together in
 * `RefundService`), so there is no known ledger defect left to warn about.
 * Publishing a permanent `false` warning would be noise; the report states its
 * basis in this docblock and in the field names instead.
 *
 * ## Attendance and the writer-less `no_show`
 *
 * The attendance report counts the three visit-lifecycle statuses the task
 * names: `check_in`, `selesai` and `no_show`. **`no_show` currently has NO
 * writer**: no endpoint moves a booking into it (recorded in
 * `web/ux/patterns/F14.md` and F13's open questions), so its count is always
 * zero on a live database and the key is published anyway - a key that vanishes
 * would make the field look unimplemented, while a zero whose meaning is
 * documented is the honest reading. When a writer ships, this report starts
 * reporting it without a schema or contract change.
 *
 * `berlangsung` is deliberately NOT part of the set: the task names
 * `check_in`/`no_show`/`selesai`, and a consultation in progress is not an
 * attendance outcome.
 *
 * ## Empty ranges are a valid answer, not an error
 *
 * Every method returns zeroed summaries and an empty `harian` list for a range
 * with no rows. A report over a quiet week is a real report; a 404 would tell
 * the operator their query was wrong.
 */
final class AdminLaporanService
{
    /**
     * The three statuses an attendance report is about, in display order.
     *
     * `check_in` first because it is the live half of the answer, then
     * `selesai`, then the writer-less `no_show`. See the class docblock.
     *
     * @var list<string>
     */
    public const STATUS_KEHADIRAN = ['check_in', 'selesai', 'no_show'];

    /**
     * `GET /admin/laporan/booking` - counts by status over a date range.
     *
     * `ringkasan.per_status` always carries all EIGHT DDL statuses, zeros
     * included, so a client renders a fixed table rather than one that changes
     * width with the data; the list is {@see BookingRequest::STATUS_SEMUA},
     * which the booking suite asserts is the DDL's own ENUM in order.
     *
     * `harian[]` carries only the days that have at least one booking, ordered
     * ascending, each with its own per-status map. Synthesizing zero rows for
     * every day of a 366-day range would be 3000 wasted objects and would make
     * "no data" indistinguishable from "data not loaded".
     *
     * @param  array<string, mixed>  $filter
     * @return array{ringkasan: array{total: int, per_status: array<string, int>}, harian: list<array<string, mixed>>}
     */
    public function booking(array $filter): array
    {
        $base = $this->bookingBase($filter);

        $perStatus = $this->perStatus((clone $base));

        $harian = [];
        $baris = (clone $base)
            ->selectRaw('tanggal_kunjungan, status, count(*) as jumlah')
            ->groupBy('tanggal_kunjungan', 'status')
            ->orderBy('tanggal_kunjungan')
            ->get();

        foreach ($baris as $satu) {
            $tanggal = $satu->tanggal_kunjungan?->format('Y-m-d') ?? (string) $satu->tanggal_kunjungan;

            $harian[$tanggal] ??= [
                'tanggal' => $tanggal,
                'total' => 0,
                'per_status' => array_fill_keys(BookingRequest::STATUS_SEMUA, 0),
            ];

            $jumlah = (int) $satu->jumlah;
            $harian[$tanggal]['per_status'][(string) $satu->status] = $jumlah;
            $harian[$tanggal]['total'] += $jumlah;
        }

        return [
            'ringkasan' => [
                'total' => array_sum($perStatus),
                'per_status' => $perStatus,
            ],
            'harian' => array_values($harian),
        ];
    }

    /**
     * `GET /admin/laporan/pendapatan` - settled revenue over a date range.
     *
     * `ringkasan.total` and every `harian[].total` are DECIMAL STRINGS with two
     * places (`"1245000.00"`), never JSON numbers, for the reason {@see Uang}
     * exists: a float has already lost the cent. `jumlah_invoice` counts the
     * invoices that make up the total, not the days.
     *
     * The day key is the WIB calendar day of `lunas_at` - see {@see RentangHari}
     * - so the numbers line up with the booking and attendance reports, which
     * are keyed on the clinic's dates by construction.
     *
     * @param  array<string, mixed>  $filter
     * @return array{ringkasan: array{total: string, jumlah_invoice: int}, harian: list<array<string, mixed>>}
     */
    public function pendapatan(array $filter): array
    {
        $rentang = RentangHari::keUtc((string) $filter['dari'], (string) $filter['sampai']);
        $hari = RentangHari::tanggalWib('lunas_at');

        $base = Invoice::query()
            ->where('status', InvoiceStatus::Lunas->value)
            ->whereNotNull('lunas_at')
            ->whereBetween('lunas_at', [$rentang['mulai'], $rentang['akhir']]);

        $total = (clone $base)->sum('total');
        $jumlahInvoice = (clone $base)->count();

        $harian = (clone $base)
            ->selectRaw($hari.' as tanggal, sum(total) as total, count(*) as jumlah_invoice')
            ->groupByRaw($hari)
            ->orderBy('tanggal')
            ->get()
            ->map(static fn ($baris): array => [
                'tanggal' => (string) $baris->tanggal,
                'total' => self::formatUang($baris->total),
                'jumlah_invoice' => (int) $baris->jumlah_invoice,
            ])
            ->values()
            ->all();

        return [
            'ringkasan' => [
                'total' => self::formatUang($total),
                'jumlah_invoice' => $jumlahInvoice,
            ],
            'harian' => $harian,
        ];
    }

    /**
     * `GET /admin/laporan/kehadiran` - visit outcomes over a date range.
     *
     * `ringkasan.total` is the sum of the three tracked statuses - the
     * attendance universe - not the range's whole booking count; cancelled and
     * expired bookings are not attendance facts. Each of the three is also
     * published separately, and `harian[]` repeats the breakdown per day.
     *
     * This report is the one that cannot be fully trusted today: `no_show` has
     * no writer, so the key is present and always zero. See the class docblock;
     * the honest thing is a documented zero, not a hidden column.
     *
     * @param  array<string, mixed>  $filter
     * @return array{ringkasan: array<string, int>, harian: list<array<string, mixed>>}
     */
    public function kehadiran(array $filter): array
    {
        $dari = (string) $filter['dari'];
        $sampai = (string) $filter['sampai'];

        $base = Booking::query()
            ->whereBetween('tanggal_kunjungan', [$dari, $sampai])
            ->whereIn('status', self::STATUS_KEHADIRAN);

        $ringkasan = [
            'total' => 0,
            'check_in' => 0,
            'selesai' => 0,
            'no_show' => 0,
        ];

        $harian = [];

        $baris = (clone $base)
            ->selectRaw('tanggal_kunjungan, status, count(*) as jumlah')
            ->groupBy('tanggal_kunjungan', 'status')
            ->orderBy('tanggal_kunjungan')
            ->get();

        foreach ($baris as $satu) {
            $tanggal = $satu->tanggal_kunjungan?->format('Y-m-d') ?? (string) $satu->tanggal_kunjungan;
            $status = (string) $satu->status;
            $jumlah = (int) $satu->jumlah;

            $harian[$tanggal] ??= [
                'tanggal' => $tanggal,
                'total' => 0,
                'check_in' => 0,
                'selesai' => 0,
                'no_show' => 0,
            ];

            $harian[$tanggal][$status] = $jumlah;
            $harian[$tanggal]['total'] += $jumlah;

            $ringkasan[$status] += $jumlah;
            $ringkasan['total'] += $jumlah;
        }

        return [
            'ringkasan' => $ringkasan,
            'harian' => array_values($harian),
        ];
    }

    /**
     * The range filter both booking-keyed reports share.
     *
     * An optional `dokter_id` narrows the booking report; it is a real
     * `Rule::exists('dokter','id')` at the FormRequest, so a doctor that does
     * not exist is a 422 rather than an empty report.
     *
     * @param  array<string, mixed>  $filter
     * @return Builder<Booking>
     */
    private function bookingBase(array $filter): Builder
    {
        $query = Booking::query()
            ->whereBetween('tanggal_kunjungan', [(string) $filter['dari'], (string) $filter['sampai']]);

        if (isset($filter['dokter_id'])) {
            $query->where('dokter_id', (int) $filter['dokter_id']);
        }

        return $query;
    }

    /**
     * A count per `booking.status`, zero-filled over the whole ENUM.
     *
     * @param  Builder<Booking>  $query
     * @return array<string, int>
     */
    private function perStatus(Builder $query): array
    {
        /** @var Collection<string, int|string> $hasil */
        $hasil = $query
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        $perStatus = [];

        foreach (BookingRequest::STATUS_SEMUA as $status) {
            $perStatus[$status] = (int) ($hasil[$status] ?? 0);
        }

        return $perStatus;
    }

    /**
     * A money value as the two-place decimal string the API publishes.
     *
     * `SUM` over no rows returns `null` and over rows returns a string; both are
     * normalised here. `Uang::normal()` is the project's string-safe formatter
     * and never routes through a float.
     */
    public static function formatUang(mixed $nilai): string
    {
        if ($nilai === null) {
            return '0.00';
        }

        return Uang::normal((string) $nilai);
    }
}
