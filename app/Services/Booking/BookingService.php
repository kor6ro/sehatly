<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Http\Requests\Booking\BookingRequest;
use App\Models\Booking;
use App\Models\Dokter;
use App\Models\DokterJadwal;
use App\Models\Invoice;
use App\Models\Pasien;
use App\Models\User;
use App\Services\Notifikasi\NotificationService;
use App\Services\Pasien\PasienRecordAccess;
use App\Services\Payment\RefundService;
use App\Support\Dokter\StrBerlaku;
use App\Support\Dokumen\NomorDokumen;
use App\Support\WaktuIndonesia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Creates, lists, cancels and expires bookings.
 *
 * ## The one serialisation point
 *
 * Every write runs in a `DB::transaction` whose FIRST statement is a
 * pessimistic row lock on the `dokter` row (`lockForUpdate()`), whether or
 * not `jadwal_id` is supplied. An earlier draft locked `dokter_jadwal` when
 * `jadwal_id` was present and `dokter` otherwise; under that design a
 * scheduled request and an instant request for the same doctor and
 * overlapping time lock DIFFERENT rows, run concurrently, and both observe a
 * count below quota. The `dokter` row exists for every doctor and is the same
 * row for every competing booking, so locking it serialises all of them. When
 * `jadwal_id` IS supplied the `dokter_jadwal` row is locked second, never
 * first.
 *
 * ## The capacity answer is a current read
 *
 * The overlap count is `SELECT id ... FOR UPDATE` counted in PHP, never a
 * snapshot `COUNT(*)`: under `REPEATABLE READ` a plain consistent read still
 * sees the pre-transaction snapshot, while the locking read always observes
 * the latest committed version. Only `UniqueConstraintViolationException` is
 * retried inside the transaction; anything else — including a lock-wait 1205
 * — propagates so a competitor can observe it.
 *
 * ## No window arithmetic is re-derived here
 *
 * A scheduled start must be among the slots
 * `SlotAvailabilityService::getSlotTerbuka()` publishes for the date, and the
 * service's `libur` / `lewat_waktu` answers are reused by reason code. What is
 * NOT reused is the availability count itself, which has to run inside this
 * transaction, after the locks, as the current read above.
 */
class BookingService
{
    /**
     * Genuine duplicate-key collisions retried before the write gives up.
     */
    private const NOMOR_PERCOBAAN_MAX = 3;

    public function __construct(
        private readonly SlotAvailabilityService $slot,
        private readonly NomorDokumen $nomor,
        private readonly PasienRecordAccess $access,
        private readonly NotificationService $notifikasi,
        private readonly RefundService $refund,
    ) {}

    /**
     * Create a booking for `$pasien`, attributed to `$pembuat`, with already
     * validated `$data`.
     *
     * `jadwal_id` is optional: when it names a schedule row of this doctor the
     * geometry comes from that row; when it is absent the published slot the
     * schedule resolves to is used; when nothing publishes the start, the
     * instant path derives the length from `dokter.durasi_default_menit`.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SlotTakenException
     */
    public function create(Pasien $pasien, User $pembuat, array $data): Booking
    {
        return DB::transaction(function () use ($pasien, $pembuat, $data): Booking {
            $dokter = Dokter::query()
                ->whereKey((int) $data['dokter_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $tanggal = (string) $data['tanggal_kunjungan'];
            $slotMulai = (string) $data['slot_mulai'];

            $jadwal = $this->kunciJadwal($dokter, $data);

            if ($jadwal !== null) {
                [$slotSelesai, $faskesId, $kuota] = $this->geometriTerjadwal($dokter, $jadwal, $tanggal, $slotMulai);
            } else {
                [$slotSelesai, $faskesId, $kuota] = $this->geometriOtomatis(
                    $dokter, $tanggal, $slotMulai, (string) $data['tipe_layanan'],
                );
            }

            $this->pastikanKapasitas($dokter, $tanggal, $slotMulai, $slotSelesai, $kuota);

            $booking = $this->simpanDenganNomorUnik(
                $pasien, $pembuat, $dokter, $jadwal, $data,
                $tanggal, $slotMulai, $slotSelesai, $faskesId,
            );

            // F3-05. The write is INSIDE this transaction on purpose, so a
            // rollback of the booking takes the notification with it: an inbox
            // row about a booking that does not exist is worse than no row.
            $this->notifikasi->bookingDibuat($pasien->user, (int) $booking->getKey());

            return $booking;
        });
    }

    /**
     * The caller's own bookings, filtered by status, paginated with the
     * project meta block. `dokter` and `jadwal` are eager-loaded, per the
     * plan, so a list cannot N+1.
     *
     * @param  array<string, mixed>  $filter
     */
    public function listPasien(Pasien $pasien, array $filter): LengthAwarePaginator
    {
        return $this->daftar($this->access->bookingQuery($pasien), $filter, ['dokter', 'jadwal']);
    }

    /**
     * The bookings on the caller's own `dokter` row, filterable by
     * consultation date and status. `pasien.user` is eager-loaded because the
     * response publishes the patient with a masked NIK.
     *
     * @param  array<string, mixed>  $filter
     */
    public function listDokter(Dokter $dokter, array $filter): LengthAwarePaginator
    {
        $query = $this->access->dokterBookingQuery($dokter);

        if (! empty($filter['tanggal'])) {
            $query->whereDate('tanggal_kunjungan', (string) $filter['tanggal']);
        }

        return $this->daftar($query, $filter, ['pasien.user', 'dokter', 'jadwal']);
    }

    /**
     * Cancel one booking as `$pembuat`.
     *
     * Ownership first: the caller's patient row scopes patient bookings and
     * the caller's doctor row scopes doctor bookings, so another tenant's row
     * is a 404 that discloses nothing, while an account with neither row is a
     * 403 about the caller. Then the status guard: a booking that is already
     * over, or already ended as `dibatalkan` / `kadaluarsa`, is refused and
     * left exactly as it was.
     *
     * ## The money side is a LEDGER TRANSITION, not an invoice stamp
     *
     * This method used to set the linked invoice to `dibatalkan`
     * unconditionally. For a PAID booking that produced exactly the state
     * `database/migrations/2026_10_01_000064_refund_table.php:21-25` warns the
     * ledger cannot explain: `pembayaran.status = 'berhasil'`, an invoice that
     * says `dibatalkan`, and NO `refund` row - money in, no record of where it
     * went. That was F12's P0 and it is gone. The transition is now
     * {@see RefundService::catatPembatalan()}, called INSIDE this transaction
     * so the booking, the invoice and the payment move together:
     *
     * - unpaid booking -> invoice `dibatalkan`, no refund row;
     * - paid booking -> one `refund` row (full amount), `pembayaran.status =
     *   'refund'`, invoice `refund_penuh`, with the row's first status decided
     *   by the method's refund capability.
     *
     * The booking status guard is also what makes a double cancel idempotent:
     * the second call is refused before any of this runs, and
     * `RefundService`'s own non-`ditolak` guard is the second line of defence.
     *
     * @throws ValidationException
     */
    public function batalkan(User $pembuat, int $id, ?string $alasan): Booking
    {
        return DB::transaction(function () use ($pembuat, $id, $alasan): Booking {
            $booking = $this->bookingUntuk($pembuat, $id);

            if (in_array($booking->status, BookingRequest::STATUS_TIDAK_BISA_DIBATALKAN, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Booking dengan status tersebut tidak dapat dibatalkan.'],
                ]);
            }

            $booking->status = 'dibatalkan';
            $booking->dibatalkan_oleh = self::dibatalkanOleh((string) $pembuat->tipe);
            $booking->alasan_pembatalan = $alasan;
            $booking->save();

            // The ledger transition. Loads the linked invoice and its
            // `pembayaran` rows under row locks and decides between the unpaid
            // branch (invoice `dibatalkan`, no refund row) and the paid branch
            // (one full refund row, payment `refund`, invoice `refund_penuh`).
            $this->refund->catatPembatalan($booking);

            $booking = $booking->refresh();

            // F3-05. The recipient is the OTHER party. A cancellation is
            // something the person who did not ask for it is usually unhappy
            // about, and the actor already knows - telling them would be noise
            // in their own inbox. `pasien.user_id` and `dokter.user_id` are both
            // NOT NULL with a real foreign key to `users`, so both exist.
            $untuk = $pembuat->tipe === 'pasien'
                ? $booking->dokter?->user
                : $booking->pasien?->user;

            if ($untuk !== null) {
                $this->notifikasi->bookingDibatalkan(
                    $untuk,
                    (int) $booking->getKey(),
                    $alasan === null || trim($alasan) === '' ? 'Tanpa alasan.' : $alasan,
                );
            }

            return $booking;
        });
    }

    /**
     * The uniform cancellation policy for one booking, as the server computes
     * it.
     *
     * The route is the F12 policy surface, and the client MUST NOT compute any
     * of it: the owner decided cancellation is free at any time with a full
     * refund, and the numbers come from {@see RefundService::kebijakan()} -
     * which reads the money actually captured rather than deriving it. Party
     * scope is the same rule {@see batalkan()} uses, in the same order: another
     * tenant's row is a 404, an account with neither profile row is a 403.
     *
     * @return array{gratis: bool, biaya: string, jumlah_refund: string, tujuan: array{metode_id: int, label: string, tipe: string}|null, sla: null}
     */
    public function kebijakan(User $pembuat, int $id): array
    {
        return $this->refund->kebijakan($this->bookingUntuk($pembuat, $id));
    }

    /**
     * Move one booking to a new slot of the SAME doctor, on the SAME row.
     *
     * The owner's decision: a reschedule is an update of `booking`, never a
     * new booking and never a new invoice. `nomor_booking`, `pasien_id`,
     * `dokter_id`, the price and the invoice are all untouched - only
     * `jadwal_id`, `faskes_id` (the new window's venue), `tanggal_kunjungan`,
     * `slot_mulai` and `slot_selesai` change. No reschedule-count limit is
     * applied because none was decided; see `web/ux/patterns/F12.md` section
     * 12.
     *
     * ## The locking order is `create()`'s, and that is the whole mechanism
     *
     * The `dokter` row is locked FIRST - before this booking's own row and
     * before the `dokter_jadwal` row - exactly as {@see create()} does, so a
     * reschedule and a concurrent create for the same doctor serialise on the
     * same lock instead of deadlocking against each other's booking-row locks.
     * The booking row is then re-read under `lockForUpdate()` and the status
     * guard runs on THAT read, so a status that changed between the ownership
     * check and the lock is seen.
     *
     * Availability is NOT re-derived: the new start must be among the slots
     * `SlotAvailabilityService::getSlotTerbuka()` publishes for the date and
     * must belong to the named `${jadwal_id}`, and the capacity answer is
     * `pastikanKapasitas()` - the same locking current read `create()` uses -
     * with this booking's own id EXCLUDED, because the row already occupies a
     * slot and must not count against itself.
     *
     * Old-schedule safety is structural rather than best-effort: every
     * refusal above happens BEFORE the first assignment, so a taken slot, a
     * holiday, an elapsed slot or an unpublished start leaves the row exactly
     * as it was. The update runs last, inside the transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SlotTakenException
     * @throws ValidationException
     */
    public function jadwalUlang(User $pembuat, int $id, array $data): Booking
    {
        return DB::transaction(function () use ($pembuat, $id, $data): Booking {
            // The tenant read, NOT locked: it only resolves which doctor to
            // lock first.
            $bookingAwal = $this->bookingUntuk($pembuat, $id);

            // LOCK ORDER 1: the doctor, the same one serialisation point
            // `create()` uses for every booking of this doctor.
            $dokter = Dokter::query()
                ->whereKey((int) $bookingAwal->dokter_id)
                ->lockForUpdate()
                ->firstOrFail();

            // LOCK ORDER 2: this booking's own row, then the guard on THAT
            // read.
            $booking = Booking::query()
                ->whereKey((int) $bookingAwal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array((string) $booking->status, BookingRequest::STATUS_BISA_DIJADWAL_ULANG, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Booking dengan status tersebut tidak dapat dijadwalkan ulang.'],
                ]);
            }

            // LOCK ORDER 3: the named schedule row, scoped to this doctor. A
            // row belonging to another doctor is not a slot this doctor
            // publishes, and it is refused without disclosing whether it
            // exists.
            $jadwal = DokterJadwal::query()
                ->whereKey((int) $data['jadwal_id'])
                ->where('dokter_id', $dokter->getKey())
                ->lockForUpdate()
                ->first();

            if ($jadwal === null) {
                throw SlotTakenException::tidakDipublikasikan();
            }

            $tanggal = (string) $data['tanggal_kunjungan'];
            $slotMulai = (string) $data['slot_mulai'];

            // The same published-slot decision `create()` makes: the start
            // must be a slot the schedule publishes for the date AND belong to
            // the schedule row the request named.
            $slot = collect($this->slot->getSlotTerbuka($dokter, $tanggal))
                ->firstWhere('jam_mulai', $slotMulai);

            if ($slot === null || (int) $slot['jadwal_id'] !== (int) $jadwal->getKey()) {
                throw SlotTakenException::tidakDipublikasikan();
            }

            $this->tolakAlasan($slot);

            $slotSelesai = (string) $slot['jam_selesai'];

            // `slot_selesai` is accepted for the client that already holds the
            // published slot, and CHECKED rather than trusted: the length
            // belongs to the schedule row (`durasi_slot_menit`, :478), never
            // to request arithmetic. A disagreement is a 422 naming the field
            // instead of a silently accepted wrong end time.
            if (isset($data['slot_selesai']) && (string) $data['slot_selesai'] !== $slotSelesai) {
                throw ValidationException::withMessages([
                    'slot_selesai' => ['Jam selesai tidak sesuai dengan slot yang dipublikasikan.'],
                ]);
            }

            $kuota = $jadwal->kuota_per_sesi === null
                ? SlotAvailabilityService::KUOTA_DEFAULT
                : (int) $jadwal->kuota_per_sesi;

            // The same locking current read `create()` uses, with this row
            // excluded: the move must not count the booking's own old slot as
            // an occupant of the new one.
            $this->pastikanKapasitas(
                $dokter,
                $tanggal,
                $slotMulai,
                $slotSelesai,
                $kuota,
                (int) $booking->getKey(),
            );

            $booking->jadwal_id = $jadwal->getKey();
            $booking->faskes_id = $slot['faskes_id'] === null ? null : (int) $slot['faskes_id'];
            $booking->tanggal_kunjungan = $tanggal;
            $booking->slot_mulai = $slotMulai;
            $booking->slot_selesai = $slotSelesai;
            $booking->save();

            // The OTHER party, exactly as a cancellation notifies: the actor
            // already knows. The body is generic and carries no clinical text;
            // the new schedule travels in the inbox payload, not the push
            // `isi`. See `NotificationService::bookingJadwalUlang()`.
            $untuk = $pembuat->tipe === 'pasien'
                ? $booking->dokter?->user
                : $booking->pasien?->user;

            if ($untuk !== null) {
                $this->notifikasi->bookingJadwalUlang(
                    $untuk,
                    (int) $booking->getKey(),
                    $tanggal,
                    $slotMulai,
                );
            }

            return $booking->refresh();
        });
    }

    /**
     * The booking the caller may act on, or the refusal that discloses nothing.
     *
     * The one ownership rule every booking action shares: the caller's own
     * `pasien` row scopes patient bookings, the caller's own `dokter` row
     * scopes doctor bookings, a row outside that scope is a 404 (never a 403,
     * which would confirm it exists), and an account owning NEITHER profile
     * row is a 403 about the caller. Extracted rather than restated so
     * `batalkan()`, `kebijakan()` and `jadwalUlang()` cannot drift apart.
     *
     * @throws AccessDeniedHttpException when the account owns no profile row
     */
    private function bookingUntuk(User $pembuat, int $id): Booking
    {
        $pasien = Pasien::query()->where('user_id', $pembuat->getKey())->first();

        if ($pasien !== null) {
            return $this->access->bookingOrFail($pasien, $id);
        }

        $dokter = Dokter::query()->where('user_id', $pembuat->getKey())->first();

        if ($dokter === null) {
            throw new AccessDeniedHttpException('Endpoint ini hanya untuk pemilik booking.');
        }

        return $this->access->dokterBookingOrFail($dokter, $id);
    }

    /**
     * Flip stale `menunggu_pembayaran` bookings to `kadaluarsa` and return how
     * many rows moved.
     *
     * The schema has NO column recording when the payment window closes, so
     * the expiry is COMPUTED from `tanggal_kunjungan` + `slot_mulai`. Only
     * `menunggu_pembayaran` rows move - a paid booking is never touched however
     * old it is - so a second run is a no-op.
     *
     * **Both reference values are the CLINIC's, and each is on the same clock
     * as the column it is compared with.** `tanggal_kunjungan` is a `DATE` and
     * `slot_mulai` is a `TIME`, and per `docs/timezone-policy.md` rule 2 both are
     * Asia/Jakarta wall clocks with no offset and no conversion - so "now" for
     * both has to be the Jakarta wall clock, which is {@see WaktuIndonesia::now()}.
     *
     * This method read `SELECT CURDATE()` for the day and `Carbon::now()` for
     * the time of day. Both were UTC: the MySQL session is pinned to `+00:00`
     * and `config/app.php` is `UTC`, while WIB is +07:00. So for the seven hours
     * from 00:00 to 07:00 WIB the day was yesterday's and the clock was seven
     * hours slow, and a booking for a 09:00 slot stayed payable until 16:00 -
     * the same seven-hour window `SlotAvailabilityService` had already closed
     * for the slot read and which was left open here. The two values are read
     * ONCE so a sweep cannot judge a row against one day and its slot against
     * another.
     */
    public function kadaluarsa(): int
    {
        $sekarang = WaktuIndonesia::now();
        $hariIni = $sekarang->format(WaktuIndonesia::FORMAT_TANGGAL);
        $jamIni = $sekarang->format(WaktuIndonesia::FORMAT_WAKTU);

        return Booking::query()
            ->where('status', 'menunggu_pembayaran')
            ->where(function ($query) use ($hariIni, $jamIni): void {
                $query->where('tanggal_kunjungan', '<', $hariIni)
                    ->orWhere(function ($sama) use ($hariIni, $jamIni): void {
                        $sama->where('tanggal_kunjungan', $hariIni)
                            ->where('slot_mulai', '<=', $jamIni);
                    });
            })
            ->update(['status' => 'kadaluarsa']);
    }

    /**
     * Who cancelled, drawn from `users.tipe` (`:139`).
     *
     * Total over the seven ENUM values: the two account types that can own a
     * booking map to themselves and the remaining five map to `sistem`, which
     * is the only third value `booking.dibatalkan_oleh` (`:517`) offers. A
     * value outside the ENUM still answers, because a missing entry must not
     * become a `null` that MySQL 1264 would reject.
     */
    public static function dibatalkanOleh(string $tipe): string
    {
        return match ($tipe) {
            'pasien' => 'pasien',
            'dokter' => 'dokter',
            default => 'sistem',
        };
    }

    /**
     * The explicitly named schedule row, locked second and scoped to this
     * doctor. A row belonging to another doctor is not a slot this doctor
     * publishes.
     *
     * @param  array<string, mixed>  $data
     */
    private function kunciJadwal(Dokter $dokter, array $data): ?DokterJadwal
    {
        if (empty($data['jadwal_id'])) {
            return null;
        }

        $jadwal = DokterJadwal::query()
            ->whereKey((int) $data['jadwal_id'])
            ->where('dokter_id', $dokter->getKey())
            ->lockForUpdate()
            ->first();

        if ($jadwal === null) {
            throw SlotTakenException::tidakDipublikasikan();
        }

        return $jadwal;
    }

    /**
     * Geometry for an explicitly named schedule row.
     *
     * The start must be among the slots the schedule publishes for the date;
     * the end, the venue and the quota come from that published slot and its
     * row, never from request arithmetic.
     *
     * @return array{string, int|null, int}
     *
     * @throws SlotTakenException
     */
    private function geometriTerjadwal(Dokter $dokter, DokterJadwal $jadwal, string $tanggal, string $slotMulai): array
    {
        $slot = collect($this->slot->getSlotTerbuka($dokter, $tanggal))
            ->firstWhere('jam_mulai', $slotMulai);

        if ($slot === null || (int) $slot['jadwal_id'] !== (int) $jadwal->getKey()) {
            throw SlotTakenException::tidakDipublikasikan();
        }

        $this->tolakAlasan($slot);

        return [
            (string) $slot['jam_selesai'],
            $slot['faskes_id'] === null ? null : (int) $slot['faskes_id'],
            $jadwal->kuota_per_sesi === null
                ? SlotAvailabilityService::KUOTA_DEFAULT
                : (int) $jadwal->kuota_per_sesi,
        ];
    }

    /**
     * Geometry when the request names no schedule row.
     *
     * When a schedule publishes the start, that published slot decides — the
     * two lengths come from two different columns (`dokter_jadwal` for a
     * scheduled start, `dokter.durasi_default_menit` for an instant one) and
     * must never be mixed. When nothing publishes it and the doctor runs
     * schedules at all, the start is outside every window and is not a slot at
     * all. Only a doctor with no schedule row takes the instant path, which is
     * the case the `dokter` row lock exists for.
     *
     * `chat` never consults the schedule at all: `dokter_jadwal.tipe_layanan`
     * has only three values, none of them `chat`, so an instant chat is NOT
     * expressible as a schedule row — not even to refuse. Every other type
     * resolves the published slot first.
     *
     * @return array{string, int|null, int}
     *
     * @throws SlotTakenException
     */
    private function geometriOtomatis(Dokter $dokter, string $tanggal, string $slotMulai, string $tipeLayanan): array
    {
        $slot = $tipeLayanan === 'chat'
            ? null
            : collect($this->slot->getSlotTerbuka($dokter, $tanggal))->firstWhere('jam_mulai', $slotMulai);

        if ($slot !== null) {
            $this->tolakAlasan($slot);

            $jadwal = DokterJadwal::query()
                ->whereKey((int) $slot['jadwal_id'])
                ->where('dokter_id', $dokter->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return [
                (string) $slot['jam_selesai'],
                $slot['faskes_id'] === null ? null : (int) $slot['faskes_id'],
                $jadwal->kuota_per_sesi === null
                    ? SlotAvailabilityService::KUOTA_DEFAULT
                    : (int) $jadwal->kuota_per_sesi,
            ];
        }

        /**
         * The THIRD reference day, and the only one of the three that is not
         * "now": the date the request named.
         *
         * `DokterDirectoryService` answers against the clinic's today and
         * `SlotAvailabilityService` answers against the consultation date; this is
         * the same second basis, for an instant booking that has no
         * `dokter_jadwal` row to ask about. `$tanggal` is a `Y-m-d` the caller
         * sent, so it is a day in whatever calendar it was written in and no zone
         * is involved: `StrBerlaku::berlakuPada()` reduces both operands to
         * `startOfDay()` before comparing, which is what makes the parse's own
         * zone irrelevant rather than merely harmless.
         */
        if (! StrBerlaku::berlakuPada($dokter->str_berlaku_sampai, Carbon::parse($tanggal))) {
            throw SlotTakenException::strKedaluwarsa();
        }

        if ($tipeLayanan !== 'chat' && DokterJadwal::query()->where('dokter_id', $dokter->getKey())->where('status_aktif', true)->exists()) {
            throw SlotTakenException::tidakDipublikasikan();
        }

        $durasi = (int) $dokter->durasi_default_menit;

        if ($durasi < 1) {
            throw SlotTakenException::durasiNol();
        }

        $slotSelesai = Carbon::parse($slotMulai)->addMinutes($durasi)->format('H:i:s');

        // Rule 1b, only when the requested date IS today, against the CLINIC's
        // calendar day and the CLINIC's clock: `tanggal_kunjungan` is a `DATE` and
        // `slot_selesai` is a `TIME`, and `docs/timezone-policy.md` rule 2 says both
        // are Asia/Jakarta wall clocks stored exactly as authored. `config/app.php`
        // is `UTC` and the MySQL session is pinned to `+00:00`, so this used to ask
        // the database for the day and PHP for the time of day and get two UTC
        // values - which meant the guard did not fire at all for a clinic booking
        // made between 00:00 and 07:00 WIB, and fired seven hours late for the
        // rest of the day. `{@see WaktuIndonesia::now()}` is the same instant as
        // `now()` on the clinic's wall clock, which is the only clock the two
        // columns are written in.
        $sekarang = WaktuIndonesia::now();

        if ($tanggal === $sekarang->format(WaktuIndonesia::FORMAT_TANGGAL)
            && $slotSelesai <= $sekarang->format(WaktuIndonesia::FORMAT_WAKTU)) {
            throw SlotTakenException::sudahLewat();
        }

        return [$slotSelesai, null, SlotAvailabilityService::KUOTA_DEFAULT];
    }

    /**
     * Refuse a published slot the schedule already decided against. `penuh`
     * falls through: the pre-lock read may be stale, so only the locking
     * current read below is allowed to answer capacity.
     *
     * @param  array<string, mixed>  $slot
     *
     * @throws SlotTakenException
     */
    private function tolakAlasan(array $slot): void
    {
        match ($slot['alasan']) {
            SlotAvailabilityService::ALASAN_LIBUR => throw SlotTakenException::tidakTersedia(),
            SlotAvailabilityService::ALASAN_LEWAT_WAKTU => throw SlotTakenException::sudahLewat(),
            default => null,
        };
    }

    /**
     * The authoritative capacity answer: overlapping live bookings, counted as
     * a current read inside the lock.
     *
     * Half-open on both ends, exactly as
     * `SlotAvailabilityService::hitungBertumpuk()` decides it, and strictly
     * less than the quota, so the k-th booking fills a quota of k. `SELECT id
     * ... FOR UPDATE` (counted in PHP, never an aggregate) always observes the
     * latest committed version, which is what makes the retry-after-commit in
     * the concurrency proof see the row the first transaction wrote.
     *
     * `$kecualiBookingId` exists for the reschedule move and for nothing else.
     * A booking being moved out of one slot and into another is COUNTED by the
     * query while it still exists on its old slot, so without the exclusion a
     * move within a window whose quota is 1 would count the row against itself
     * (`terisi >= kuota` with `terisi = 1`) and refuse every move, including a
     * no-op one to the same slot. `create()` never passes it: a new row has no
     * id yet and must lose to every occupant.
     *
     * @throws SlotTakenException
     */
    private function pastikanKapasitas(
        Dokter $dokter,
        string $tanggal,
        string $slotMulai,
        string $slotSelesai,
        int $kuota,
        ?int $kecualiBookingId = null,
    ): void {
        $terisi = Booking::query()
            ->select('id')
            ->where('dokter_id', $dokter->getKey())
            ->where('tanggal_kunjungan', $tanggal)
            ->whereNotIn('status', SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI)
            ->where('slot_mulai', '<', $slotSelesai)
            ->where('slot_selesai', '>', $slotMulai)
            ->when($kecualiBookingId !== null, fn (Builder $kueri) => $kueri->where('id', '!=', $kecualiBookingId))
            ->lockForUpdate()
            ->pluck('id')
            ->count();

        if ($terisi >= $kuota) {
            throw SlotTakenException::penuh();
        }
    }

    /**
     * Write the booking and its invoice with collision-safe numbers.
     *
     * The retry loop runs INSIDE the transaction: a rolled-back attempt leaves
     * no `booking` and no `invoice` behind, and only a genuine duplicate-key
     * collision retries. `status` is left to the DDL default
     * (`'menunggu_pembayaran'`), so the row is refreshed before it is handed
     * back.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SlotTakenException
     */
    private function simpanDenganNomorUnik(
        Pasien $pasien,
        User $pembuat,
        Dokter $dokter,
        ?DokterJadwal $jadwal,
        array $data,
        string $tanggal,
        string $slotMulai,
        string $slotSelesai,
        ?int $faskesId,
    ): Booking {
        for ($percobaan = 1; $percobaan <= self::NOMOR_PERCOBAAN_MAX; $percobaan++) {
            try {
                $booking = new Booking;
                $booking->nomor_booking = $this->nomor->berikutnya(NomorDokumen::PREFIX_BOOKING, $tanggal);
                $booking->pasien_id = $pasien->getKey();
                $booking->anggota_keluarga_id = $data['anggota_keluarga_id'] ?? null;
                $booking->dokter_id = $dokter->getKey();
                $booking->jadwal_id = $jadwal?->getKey();
                $booking->faskes_id = $faskesId;
                $booking->tipe_layanan = $data['tipe_layanan'];
                $booking->tanggal_kunjungan = $tanggal;
                $booking->slot_mulai = $slotMulai;
                $booking->slot_selesai = $slotSelesai;
                $booking->keluhan = $data['keluhan'] ?? null;
                $booking->lampiran_keluhan = $data['lampiran_keluhan'] ?? null;
                $booking->is_rujukan = (bool) ($data['is_rujukan'] ?? false);
                $booking->is_konsultasi_lanjutan = (bool) ($data['is_konsultasi_lanjutan'] ?? false);
                $booking->dibuat_oleh_user_id = $pembuat->getKey();
                $booking->save();

                // A referral is a fact about provenance, not a coupon: the
                // flags change nothing about the money.
                $biaya = (string) $dokter->biaya_konsultasi_online;

                $invoice = new Invoice;
                $invoice->nomor_invoice = $this->nomor->berikutnya(NomorDokumen::PREFIX_INVOICE, $tanggal);
                $invoice->pasien_id = $pasien->getKey();
                $invoice->referensi_tipe = 'booking';
                $invoice->referensi_id = $booking->getKey();
                $invoice->subtotal = $biaya;
                $invoice->diskon = '0.00';
                $invoice->biaya_admin = '0.00';
                $invoice->biaya_pengiriman = '0.00';
                $invoice->total = $biaya;
                $invoice->save();

                return $booking->refresh();
            } catch (UniqueConstraintViolationException) {
                if ($percobaan >= self::NOMOR_PERCOBAAN_MAX) {
                    throw SlotTakenException::nomorHabis();
                }
            }
        }

        throw SlotTakenException::nomorHabis();
    }

    /**
     * One filtered, ordered, paginated booking list.
     *
     * The order is total, so paging cannot repeat or skip a row. `id` alone
     * is the unique tiebreaker.
     *
     * @param  array<string, mixed>  $filter
     * @param  list<string>  $relasi
     */
    private function daftar(Builder $query, array $filter, array $relasi): LengthAwarePaginator
    {
        if (! empty($filter['status'])) {
            $query->where('status', (string) $filter['status']);
        }

        return $query
            ->with($relasi)
            ->orderBy('tanggal_kunjungan')
            ->orderBy('slot_mulai')
            ->orderBy('id')
            ->paginate($this->access->perPage((int) ($filter['per_page'] ?? PasienRecordAccess::PER_PAGE_DEFAULT)))
            ->withQueryString();
    }
}
