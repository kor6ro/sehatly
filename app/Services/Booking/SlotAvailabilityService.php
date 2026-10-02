<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\Dokter;
use App\Models\DokterJadwal;
use App\Models\DokterLibur;
use App\Services\Dokter\DokterDirectoryService;
use App\Support\Dokter\StrBerlaku;
use App\Support\WaktuIndonesia;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Feature\Dokter\SlotAvailabilityTest;

/**
 * The doctor's bookable-slot computation: four rules, subtracted from each other.
 *
 * ## The four rules and the DDL each one comes from
 *
 * | rule | what it removes | DDL |
 * | --- | --- | --- |
 * | 1. working windows | everything not inside an active, in-force `dokter_jadwal` row for that weekday | `dokter_jadwal` `:470`-`:488` |
 * | 2. LIBUR | every slot of the day, when a `dokter_libur` row names that date | `dokter_libur` `:490`-`:496` |
 * | 3. Collision | slots whose overlapping consuming-booking count has reached the row's `kuota_per_sesi` | `booking` `:498`-`:530`, quota at `:479` |
 * | 4. STR | the entire answer, when the licence does not cover the consultation date | `dokter.str_berlaku_sampai` `:414` |
 *
 * A fifth, smaller rule the plan also names -- an elapsed slot on *today* -- is
 * rule 1b and is documented on its own below.
 *
 * ## Asia/Jakarta wall clock, never an instant
 *
 * `$tanggal`, `jam_mulai` and `jam_selesai` are **unzoned wall clock**. The DDL
 * stores them as `DATE` and `TIME` (`:476`-`:477`, `:507`-`:509`) and nothing in
 * this schema carries a zone, so this class never constructs a `Y-m-d H:i:s`
 * local datetime and never converts. A 23:30 slot is published as `23:30:00`,
 * not shifted to `16:30:00` by seven hours. {@see ZONA_WAKTU} is published so a
 * response can label the values, and
 * {@see SlotAvailabilityTest} asserts it is deliberately
 * NOT `config('app.timezone')`, which is UTC.
 *
 * ## Rule 1: working windows
 *
 * The window is `dokter_jadwal.jam_mulai` (`:476`) to `jam_selesai` (`:477`),
 * selected when all four hold:
 *
 * - `hari` (`:475`, `TINYINT UNSIGNED`, comment `0=Minggu s.d. 6=Sabtu`) equals
 *   the requested date's day of week. That comment is PHP's own `date('w')`
 *   numbering, which is what `Carbon::dayOfWeek` returns, so the two agree
 *   without a translation table. It is also the validation the DDL's missing
 *   `CHECK (hari BETWEEN 0 AND 6)` needs: an out-of-range value such as 9 can
 *   never equal a real weekday, so such a row is inert rather than dangerous.
 * - `status_aktif = 1` (`:482`).
 * - `berlaku_mulai <= $tanggal` (`:480`).
 * - `berlaku_sampai IS NULL` (open-ended) **or** `berlaku_sampai >= $tanggal`
 *   (`:481`).
 *
 * ### `berlaku_sampai` is INCLUSIVE, and for the same reason STR is
 *
 * `berlaku_sampai` is a `DATE` with no time component and the column is named
 * `berlaku sampai` -- "valid through". The last moment of validity is the end
 * of the date it names, so a window whose `berlaku_sampai` is exactly the
 * requested date still runs on that date. This is the same reading
 * {@see StrBerlaku} applies to `str_berlaku_sampai` and the same reasoning; it
 * is spelled here rather than shared because the two columns are unrelated and
 * no class owns both.
 *
 * ### The slot step, and its two edges
 *
 * Candidates step `durasi_slot_menit` (`:478`) from `jam_mulai` to
 * `jam_selesai`. Two edges are decided rather than inherited:
 *
 * - **The end is reachable.** A `09:00`-`09:15` window with a 15-minute duration
 *   offers exactly one slot, `09:00`-`09:15`. An implementation using `<` on the
 *   end would publish a doctor with no availability at all, and one using `<=`
 *   on the step would publish a zero-length slot. The step condition is
 *   `mulai + durasi <= jam_selesai`.
 * - **A partial trailing slot is never offered.** A `09:00`-`09:50` window
 *   offers three slots and stops at `09:45`; the leftover five minutes is not a
 *   bookable consultation. Emitting `09:45`-`10:00` would book a patient into
 *   ten minutes the doctor declared they do not work.
 *
 * ### The slot length is `dokter_jadwal.durasi_slot_menit`, NOT `dokter.durasi_default_menit`
 *
 * Both columns exist and both are `SMALLINT UNSIGNED NOT NULL DEFAULT 15`:
 * `dokter_jadwal.durasi_slot_menit` at `:478` and `dokter.durasi_default_menit`
 * at `:422`. They answer different questions. The per-window column is the slot
 * length for that window, is nullable nowhere and varies per row; the
 * doctor-level column is a profile-wide default for a consultation length,
 * which is what a *new* schedule row is created with. At read time the schedule
 * row's own value is always present, so reading the doctor-level value would
 * ignore a per-row setting the DDL goes to the trouble of storing.
 *
 * {@see SlotAvailabilityTest} writes 20 into
 * `dokter.durasi_default_menit` and 15 into the schedule row, and the boundary
 * assertions only pass when the schedule row wins -- so the distinction is
 * pinned by a fixture that would have to change to lose it.
 *
 * ### Rows the DDL permits and this service refuses
 *
 * `MySQL TIME` accepts `-100:00:00` through `+838:59:59`, and the plan's own
 * constraint list requires this class to handle the wrap explicitly. Two checks,
 * both in {@see windowLayak()}:
 *
 * - `jam_selesai <= jam_mulai` is refused by a fail-fast guard. A wrap is
 *   representable but is not a window, and naive `H:i:s` parsing of one produces
 *   a negative or a beyond-24:00 value that no string comparison can order. A
 *   zero-length window is the same defect with the same answer. The guard is
 *   redundant with the step loop, which also refuses both, and is kept as a
 *   second line of defence; {@see windowLayak()} says which of the two is
 *   load-bearing and which is not, and that was measured.
 * - `durasi_slot_menit = 0` is refused outright. Unsigned means `0` is a legal
 *   stored value, and `$mulaiSlot += 0` never terminates.
 *
 * A third edge is per-slot rather than per-row: a candidate that would end at or
 * after `24:00:00` is dropped, so a `22:00`-`23:59` window with 60-minute slots
 * still offers `22:00`-`23:00`. `24:00:00` is storable but is not a time of day,
 * and the response publishes `H:i:s`.
 *
 * ### Self-overlapping windows are both honoured
 *
 * `dokter_jadwal` has no unique on `(dokter_id, hari)` and nothing prevents two
 * active rows for the same weekday with overlapping `jam_mulai`/`jam_selesai`;
 * `idx_jadwal` (`:487`) is an index, not a constraint. Merging or de-duplicating
 * them would silently invent an offering nobody published, so both rows are
 * honoured and each slot carries the `jadwal_id` it came from. That field is not
 * in the plan's published slot shape and is carried anyway: `booking.jadwal_id`
 * is nullable (`:504`) precisely so an instant booking can exist without one,
 * and with two windows on the same weekday a client otherwise cannot tell which
 * row a 09:00 slot belongs to. It is service-internal until a resource decides
 * otherwise.
 *
 * ## Rule 2: LIBUR is a whole-day subtraction
 *
 * `dokter_libur` (`:490`-`:496`) is `dokter_id`, `tanggal DATE NOT NULL` and
 * `alasan`, with no `status_aktif`, no unique on `(dokter_id, tanggal)` and no
 * index beyond the FK. There is no finer granularity in the DDL, so a holiday
 * closes the day.
 *
 * ### A holiday CLOSES the day's slots rather than removing them
 *
 * The plan's prose says "subtract every date in `dokter_libur`" and its own
 * agent-executable acceptance criterion says "a test with a `dokter_libur` row
 * asserts every slot that day is `tersedia: false`". Those two are not the same
 * answer, and the criterion is the one that is checked, so the criterion wins:
 * the day's candidates are still published, each with `tersedia = false` and
 * `alasan = 'libur'`. An empty list could not distinguish a holiday from a
 * doctor who simply does not work that weekday, and `alasan` exists precisely to
 * carry the reason. Both readings agree that no slot on that day is bookable.
 *
 * The comparison is per doctor and per date, so a holiday cannot leak between
 * doctors.
 *
 * ## Rule 3: Collision, and the quota trap
 *
 * `booking` (`:498`-`:530`) stores `tanggal_kunjungan DATE NOT NULL` (`:507`),
 * `slot_mulai TIME NOT NULL` (`:508`), `slot_selesai TIME NOT NULL` (`:509`)
 * and an eight-value `status` ENUM (`:515`-`:516`).
 *
 * - **Consuming statuses.** `dibatalkan` and `kadaluarsa` release a slot: the
 *   patient is not coming and the payment window closed. The other six consume
 *   it. The test derives the six from the DDL ENUM minus the two, so a ninth
 *   status breaks the suite until the service has been asked the question.
 * - **Half-open overlap.** `booking.slot_mulai < slot.slot_selesai` **and**
 *   `booking.slot_selesai > slot.slot_mulai`, both strict. So a booking ending
 *   exactly when a slot begins, and a booking starting exactly when a slot ends,
 *   both leave the slot free. Adjacent consultations are legal, and the
 *   `<=`/`<=` spelling is what would make a doctor with back-to-back bookings
 *   look over-committed.
 * - **The quota, not a boolean.** A slot is free when the count of overlapping
 *   consuming bookings is **strictly less than** `dokter_jadwal.kuota_per_sesi`,
 *   which is `NULL`-able (`:479`) and therefore read as 1. A `count === 0` check
 *   is the trap this schema sets: it makes every `kuota_per_sesi > 1` window
 *   permanently unbookable, because a single booking would read as taken no
 *   matter how many seats the window has. The k-th booking fills the window, so
 *   with a quota of 2 the slot is open after one booking and closed after two.
 *
 * ### One query per window, exact counting per slot
 *
 * The count is a **superset query per window**, not a count query per slot. The
 * window query asks for the consuming bookings that overlap the window's own
 * span, which is a proven superset of everything overlapping any slot inside it,
 * because every candidate slot is generated inside the window by construction.
 * The exact per-slot count is then done by {@see hitungBertumpuk()}, using the
 * same strict comparison. Because the SQL filter is a superset, the PHP filter
 * cannot miss a booking, and one comparison for both is what keeps them from
 * disagreeing.
 *
 * The alternative -- a `select count(*)` per candidate slot -- is the shape todo
 * 27 uses inside its transaction, and it is right there because it must
 * serialise on a lock. Here it would be an unauthenticated endpoint issuing up
 * to a hundred queries for one doctor with a nine-hour window.
 *
 * ### No `TIME()` wrapper on the comparison
 *
 * The overlap is a bare `where('slot_mulai', '<', $x)` rather than
 * `whereTime()`. `whereTime()` renders `time(column)`, and MySQL's `TIME()`
 * folds a beyond-24:00 value back into the 24-hour clock -- precisely the `:208`
 * hazard. MySQL compares `TIME` numerically, so a bare comparison is both
 * correct and the one that does not wrap.
 *
 * ## Rule 4: STR, and what it refuses
 *
 * `str_berlaku_sampai DATE NOT NULL` (`:414`). The boundary itself -- inclusive,
 * fail-closed on a NULL expiry -- is **not** decided here. It lives in
 * {@see StrBerlaku}, which todo 22 established and
 * {@see DokterDirectoryService} already uses, so the
 * codebase has one spelling of it rather than two that happen to agree today.
 *
 * **The reference day is the requested consultation date, not today.** The
 * directory asks "may this doctor be listed now?" and compares against today.
 * This method asks "may a consultation proceed on date D?" and compares against
 * D. Nothing in the schema invalidates a doctor the day the date passes, so
 * today-based filtering would let a patient book a visit months after the
 * licence lapsed. Same rule, applied to the day the answer is actually about.
 *
 * **The refusal is an empty list, not an exception.** That mirrors
 * {@see DokterDirectoryService::find()}, which returns
 * `null` for STR-expired, unverified, absent and soft-deleted alike so an
 * unauthenticated caller cannot enumerate the licence state of every account.
 * An empty list is the same information-hiding. A caller that genuinely needs to
 * tell "licence lapsed" from "no schedule" needs a domain exception, which is
 * the endpoint's todo to raise.
 *
 * ### `getJadwal()` is NOT gated by the STR, and that asymmetry is deliberate
 *
 * {@see getJadwal()} returns the weekly window template and applies only
 * `status_aktif`. A template is a profile attribute, not an offer to practise
 * medicine, and gating it would mean a second STR check for no patient-safety
 * gain and two gates for a caller to keep in step. `getSlotTerbuka()` is the one
 * gate. The test file asserts both halves of this asymmetry, so it cannot become
 * accidental.
 *
 * ## Rule 1b: an elapsed slot on today
 *
 * When the requested date **is** today, a slot whose `jam_selesai` has already
 * passed is not bookable. The comparison is `jam_selesai <= now`, so a slot
 * ending at exactly this instant is already over. `now` is `Carbon::now()`,
 * which honours `Carbon::setTestNow()`, and the "is this today" comparison is
 * made against the database's own calendar day for the reason
 * {@see DokterDirectoryService} gives: `config/app.php` is
 * UTC while the schema stores naive wall clock, so a PHP-built date would
 * compare two different clocks and be a day out for seven hours every evening.
 * A future date is untouched by this rule.
 *
 * ## `alasan` precedence
 *
 * One reason per unavailable slot, first match wins:
 * `libur` > `lewat_waktu` > `penuh`. The day being off outranks everything; a
 * slot that has already ended outranks capacity, because "this has passed" is
 * the more useful thing to tell a patient than "full" about a slot three hours
 * ago. `null` means available.
 */
class SlotAvailabilityService
{
    /**
     * The wall-clock zone every `tanggal` and `H:i:s` value is authored in.
     *
     * Deliberately not `config('app.timezone')`, which is UTC: these are
     * unzoned Jakarta wall-clock values in the DDL, and converting them is the
     * seven-hour-offset defect the plan's timezone todo exists to prevent.
     *
     * @var string
     */
    public const ZONA_WAKTU = 'Asia/Jakarta';

    /**
     * The two `booking.status` values that RELEASE a slot.
     *
     * `booking.status` is the eight-value ENUM at `:515`-`:516`; these are the
     * two terminal states, and the remaining six consume the slot.
     *
     * @var list<string>
     */
    public const STATUS_TIDAK_MENGKONSUMSI = ['dibatalkan', 'kadaluarsa'];

    /** `alasan` for a slot closed by a `dokter_libur` row. */
    public const ALASAN_LIBUR = 'libur';

    /** `alasan` for a slot whose consuming-booking count has reached the quota. */
    public const ALASAN_PENUH = 'penuh';

    /** `alasan` for a slot on today that has already ended. */
    public const ALASAN_LEWAT_WAKTU = 'lewat_waktu';

    /**
     * `dokter_jadwal.kuota_per_sesi` is `SMALLINT UNSIGNED NULL` (`:479`), so a
     * NULL is a real stored value and not "unlimited". It is read as 1.
     */
    public const KUOTA_DEFAULT = 1;

    /** Seconds in a day. `24:00:00` is storable in a `TIME` and is not a time of day. */
    private const DETIK_PER_HARI = 86400;

    /**
     * `dokter_jadwal.hari` is `0=Minggu s.d. 6=Sabtu` (`:475`), which is PHP's
     * own `date('w')` numbering. There is no translation and no range to clamp.
     */
    private const HARI_PERTAMA = 0;

    private const JUMLAH_HARI = 7;

    /**
     * The resolved "today" for one instance, cached per request.
     *
     * Two calls in the same request must agree on the boundary or a midnight
     * rollover between them would answer differently. Null until first use.
     *
     * `CarbonInterface`, never `Carbon`: `WaktuIndonesia::now()` returns a
     * `Carbon\CarbonImmutable`, which is a **sibling** of
     * `Illuminate\Support\Carbon` and not an instance of it, so a narrower
     * property type raises a `TypeError` the moment the rule is exercised.
     */
    private ?CarbonInterface $hariIni = null;

    /**
     * `GET /api/v1/dokter/{dokter}/jadwal` -- the weekly window template.
     *
     * Seven keys, always present, `0` = Minggu through `6` = Saturdays, so a
     * client can render a whole week without probing which days exist. A doctor
     * with no schedule rows therefore answers seven empty days, **not** an empty
     * list: the shape is the contract, and collapsing it would leave a caller
     * unable to tell "no rows" from "no such key".
     *
     * Each day's rows are ordered by `jam_mulai` then `id`, which makes the
     * order total: two rows for the same weekday are permitted by the DDL and
     * `jam_mulai` alone ties often.
     *
     * **Not date-filtered**, because there is no date to filter by. A row whose
     * `berlaku_sampai` has passed is still a published template; whether it runs
     * on a given day is {@see getSlotTerbuka()}'s question. It **is**
     * `status_aktif`-filtered, because an inactive window is not a published
     * one. And it is **not** STR-gated, for the reason the class docblock gives.
     *
     * @return array<int, list<array{
     *     jadwal_id: int,
     *     hari: int,
     *     tipe_layanan: string,
     *     faskes_id: int|null,
     *     jam_mulai: string,
     *     jam_selesai: string,
     *     durasi_slot_menit: int,
     *     kuota_per_sesi: int|null
     * }>>
     */
    public function getJadwal(Dokter $dokter): array
    {
        $kelompok = array_fill(self::HARI_PERTAMA, self::JUMLAH_HARI, []);

        $baris = $this->queryJadwal($dokter)
            ->orderBy('jam_mulai')
            ->orderBy('id')
            ->get();

        foreach ($baris as $jadwal) {
            $hari = (int) $jadwal->hari;

            // `hari` is `TINYINT UNSIGNED` with no CHECK, so 0-255 are storable
            // and anything outside 0-6 has no key to land in. Dropping it here is
            // the validation the missing constraint needs.
            if ($hari < self::HARI_PERTAMA || $hari >= self::JUMLAH_HARI) {
                continue;
            }

            $kelompok[$hari][] = [
                'jadwal_id' => (int) $jadwal->getKey(),
                'hari' => $hari,
                'tipe_layanan' => (string) $jadwal->tipe_layanan,
                'faskes_id' => $jadwal->faskes_id === null ? null : (int) $jadwal->faskes_id,
                'jam_mulai' => (string) $jadwal->jam_mulai,
                'jam_selesai' => (string) $jadwal->jam_selesai,
                'durasi_slot_menit' => (int) $jadwal->durasi_slot_menit,
                // The DDL's own `null`, not a substituted 1: a client can tell
                // which windows are genuinely uncapped from which the caller has
                // to assume one seat.
                'kuota_per_sesi' => $jadwal->kuota_per_sesi === null ? null : (int) $jadwal->kuota_per_sesi,
            ];
        }

        return $kelompok;
    }

    /**
     * `GET /api/v1/dokter/{dokter}/slot` -- the bookable slots on one date.
     *
     * Returns an empty list for: a lapsed STR, no window for that weekday, and a
     * doctor with no schedule rows at all. A holiday does **not** return an empty
     * list -- it returns the day's slots, every one closed. A malformed `$tanggal`
     * throws rather than returning; see below.
     *
     * ## Why a malformed `$tanggal` throws instead of returning
     *
     * `Carbon::createFromFormat('Y-m-d', '2026-13-45')` does **not** fail: PHP
     * overflows month 13 and day 45 into `2027-02-14`. A service that trusted it
     * would answer a question about February for a request that asked about a
     * date which does not exist, and the plan wants a 422 for exactly that input.
     * The round trip through `format('Y-m-d')` is the only thing that catches the
     * rollover. The `InvalidArgumentException` is the hook an endpoint catches to
     * build that 422; a `date_format:Y-m-d` rule in a FormRequest is the same
     * rule at the edge, and both may coexist.
     *
     * @param  string  $tanggal  `Y-m-d`, Asia/Jakarta wall clock
     * @param  CarbonInterface|null  $acuan  the "today" the elapsed-slot rule compares against; the Jakarta calendar day by default
     * @return list<array{
     *     jadwal_id: int,
     *     jam_mulai: string,
     *     jam_selesai: string,
     *     tipe_layanan: string,
     *     faskes_id: int|null,
     *     tersedia: bool,
     *     alasan: string|null
     * }>
     *
     * @throws InvalidArgumentException when `$tanggal` is not a real calendar date
     */
    public function getSlotTerbuka(Dokter $dokter, string $tanggal, ?CarbonInterface $acuan = null): array
    {
        $hari = $this->tanggalValid($tanggal);

        // Rule 4, before any other work: no licence, no slots, and not even a
        // schedule read. The reference day is the consultation date.
        if (! StrBerlaku::berlakuPada($dokter->str_berlaku_sampai, $hari)) {
            return [];
        }

        $jadwal = $this->queryJadwal($dokter)
            ->where('hari', (int) $hari->dayOfWeek)
            ->where('berlaku_mulai', '<=', $tanggal)
            ->where(function ($inner) use ($tanggal): void {
                $inner->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tanggal);
            })
            ->orderBy('id')
            ->get();

        if ($jadwal->isEmpty()) {
            return [];
        }

        // Rule 2, one comparison for the whole day rather than one per slot.
        $libur = $this->hariLibur($dokter, $tanggal);

        // Rule 1b, only when the requested date IS today. `WaktuIndonesia::now()`
        // honours `setTestNow()` and is read once, so every slot of the day agrees.
        //
        // ZONE MATTERS, AND IT IS NOT COSMETIC. `sudahLewat` is compared against a
        // `TIME` column, which `docs/timezone-policy.md` is explicit is a wall
        // clock ("17:00 at the clinic"), not an instant. Reading "now" as UTC and
        // comparing it against that wall clock made every slot between
        // `now(WIB) - 7h` and `now(WIB)` look bookable - so a 09:00 WIB appointment
        // stayed available until 16:00 WIB. Both sides of this comparison are now
        // Jakarta wall clocks, and neither passes through a conversion.
        $sudahLewat = $tanggal === $this->hariIni($acuan)->toDateString()
            ? WaktuIndonesia::now()
            : null;

        $slot = [];

        foreach ($jadwal as $satu) {
            if (! $this->windowLayak($satu)) {
                continue;
            }

            foreach ($this->slotDariWindow($satu, $tanggal, $libur, $sudahLewat) as $satuSlot) {
                $slot[] = $satuSlot;
            }
        }

        return $slot;
    }

    /**
     * A doctor's active windows, unfiltered by weekday or validity date.
     *
     * `status_aktif` is the only predicate here because it is the only one both
     * public methods agree on; the weekday and validity-window predicates differ
     * by question and are applied by the caller.
     *
     * @return Builder<DokterJadwal>
     */
    private function queryJadwal(Dokter $dokter): Builder
    {
        return DokterJadwal::query()
            ->where('dokter_id', (int) $dokter->getKey())
            ->where('status_aktif', true);
    }

    /**
     * Rule 2: does `dokter_libur` close `$tanggal` for this doctor?
     *
     * `dokter_libur` (`:490`-`:496`) has no unique on `(dokter_id, tanggal)` and
     * no index beyond the FK, so duplicate rows are storable and the question is
     * asked as an existence test rather than as a single row read.
     * `whereDate()` is a semantic no-op on a `DATE` column, and there is no
     * secondary index here to lose either way.
     */
    private function hariLibur(Dokter $dokter, string $tanggal): bool
    {
        return DokterLibur::query()
            ->where('dokter_id', (int) $dokter->getKey())
            ->whereDate('tanggal', $tanggal)
            ->exists();
    }

    /**
     * Is this window serviceable at all?
     *
     * `durasi_slot_menit < 1` is the check that is load-bearing on its own: the
     * step loop would never terminate on a duration of 0.
     *
     * The `jam_selesai <= jam_mulai` check is a **fail-fast guard, not the thing
     * that refuses a wrap**, and that was measured rather than assumed. Removing
     * it leaves every test green, because the step condition
     * `$mulaiSlot + $durasi <= $selesai` already fails immediately when the
     * start is numerically after the end -- for a `22:00`-`02:00` wrap the very
     * first iteration fails, and a zero-length window fails the same way. It is
     * kept because it states the invariant instead of leaving it emergent: a
     * future change to the step loop that taught it to handle a wrap would
     * otherwise publish overnight slots with no second line of defence, and
     * because the second failure mode -- a zero duration -- really is silent.
     *
     * Neither refusal is an error the caller can act on, so the row is simply
     * not published: the same fail-closed answer as a lapsed licence, and the
     * reason it is not an exception is that one corrupt row must not take the
     * whole day down with a 500.
     */
    private function windowLayak(DokterJadwal $jadwal): bool
    {
        if ((int) $jadwal->durasi_slot_menit < 1) {
            return false;
        }

        return self::detik((string) $jadwal->jam_selesai) > self::detik((string) $jadwal->jam_mulai);
    }

    /**
     * Every candidate slot of one window, already decided.
     *
     * The consuming bookings are fetched ONCE for the whole window as a proven
     * superset, and the exact per-slot count is then done by
     * {@see hitungBertumpuk()} with the same strict comparison.
     *
     * @return list<array<string, mixed>>
     */
    private function slotDariWindow(
        DokterJadwal $jadwal,
        string $tanggal,
        bool $libur,
        ?CarbonInterface $sudahLewat,
    ): array {
        $mulai = self::detik((string) $jadwal->jam_mulai);
        $selesai = self::detik((string) $jadwal->jam_selesai);
        $durasi = (int) $jadwal->durasi_slot_menit * 60;

        $bertabrakan = $this->bookingBertumpuk((int) $jadwal->dokter_id, $tanggal, $mulai, $selesai);
        $kuota = $jadwal->kuota_per_sesi === null ? self::KUOTA_DEFAULT : (int) $jadwal->kuota_per_sesi;

        $slot = [];

        for ($mulaiSlot = $mulai; $mulaiSlot + $durasi <= $selesai; $mulaiSlot += $durasi) {
            $akhirSlot = $mulaiSlot + $durasi;

            // A slot ending at or after midnight is storable but is not a time of
            // day, and the response publishes `H:i:s`.
            if ($akhirSlot >= self::DETIK_PER_HARI) {
                break;
            }

            $jamSelesai = $this->keWaktu($akhirSlot);
            $alasan = $this->keputusan($libur, $sudahLewat, $jamSelesai, $bertabrakan, $mulaiSlot, $akhirSlot, $kuota);

            $slot[] = [
                'jadwal_id' => (int) $jadwal->getKey(),
                'jam_mulai' => $this->keWaktu($mulaiSlot),
                'jam_selesai' => $jamSelesai,
                'tipe_layanan' => (string) $jadwal->tipe_layanan,
                'faskes_id' => $jadwal->faskes_id === null ? null : (int) $jadwal->faskes_id,
                // Derived from the reason rather than decided separately, so the
                // two can never disagree.
                'tersedia' => $alasan === null,
                'alasan' => $alasan,
            ];
        }

        return $slot;
    }

    /**
     * Rule 3: the consuming bookings that overlap a whole window. The superset.
     *
     * The overlap is a bare `where`, never `whereTime()`. See the class docblock
     * for why the `TIME()` wrapper is the wrong call here.
     *
     * @return list<array{slot_mulai: int, slot_selesai: int}>
     */
    private function bookingBertumpuk(int $dokterId, string $tanggal, int $mulai, int $selesai): array
    {
        $baris = DB::table('booking')
            ->select(['slot_mulai', 'slot_selesai'])
            ->where('dokter_id', $dokterId)
            ->where('tanggal_kunjungan', $tanggal)
            ->whereNotIn('status', self::STATUS_TIDAK_MENGKONSUMSI)
            ->where('slot_mulai', '<', $this->keWaktu($selesai))
            ->where('slot_selesai', '>', $this->keWaktu($mulai))
            ->get();

        $bertabrakan = [];

        foreach ($baris as $satu) {
            $bertabrakan[] = [
                'slot_mulai' => self::detik((string) $satu->slot_mulai),
                'slot_selesai' => self::detik((string) $satu->slot_selesai),
            ];
        }

        return $bertabrakan;
    }

    /**
     * The exact per-slot count, taken from the superset.
     *
     * Half-open, both ends strict: a booking ending exactly when the slot begins
     * and a booking starting exactly when the slot ends both leave it free.
     *
     * @param  list<array{slot_mulai: int, slot_selesai: int}>  $bertabrakan
     */
    private function hitungBertumpuk(array $bertabrakan, int $mulaiSlot, int $akhirSlot): int
    {
        $jumlah = 0;

        foreach ($bertabrakan as $satu) {
            if ($satu['slot_mulai'] < $akhirSlot && $satu['slot_selesai'] > $mulaiSlot) {
                $jumlah++;
            }
        }

        return $jumlah;
    }

    /**
     * `alasan`, or `null` when the candidate is bookable. First match wins.
     *
     * Precedence is `libur` > `lewat_waktu` > `penuh`; the class docblock gives
     * the reasoning. The two date-based reasons come first precisely because they
     * are absolute -- a day off and a finished slot cannot be booked regardless
     * of how many seats are free.
     *
     * @param  list<array{slot_mulai: int, slot_selesai: int}>  $bertabrakan
     */
    private function keputusan(
        bool $libur,
        ?CarbonInterface $sudahLewat,
        string $jamSelesai,
        array $bertabrakan,
        int $mulaiSlot,
        int $akhirSlot,
        int $kuota,
    ): ?string {
        if ($libur) {
            return self::ALASAN_LIBUR;
        }

        if ($sudahLewat !== null && self::detik($jamSelesai) <= self::detik($sudahLewat->format('H:i:s'))) {
            return self::ALASAN_LEWAT_WAKTU;
        }

        // Strictly less than, so the k-th booking fills a quota of k. This
        // comparison, and not `=== 0`, is the entire quota rule.
        if ($this->hitungBertumpuk($bertabrakan, $mulaiSlot, $akhirSlot) < $kuota) {
            return null;
        }

        return self::ALASAN_PENUH;
    }

    /**
     * Seconds since midnight for an `H:i:s` (or beyond-24:00) string.
     *
     * Seconds, not a string comparison, and that is the whole point. MySQL
     * compares `TIME` numerically, so `25:00:00` sorts after `09:00:00`; PHP
     * compares the strings the driver returns and `25:00:00` sorts BEFORE
     * `09:00:00`. Using the PHP ordering against a MySQL-ordered query is how a
     * wrap turns into a silent wrong answer, so both sides are normalised here.
     * The hour component is read as a plain integer, which is what makes a
     * beyond-24:00 value work at all.
     *
     * **This is the ONE place the conversion exists, and it is `public static`
     * because two surfaces now need it.** The F14 admin schedule service
     * (`App\Services\Admin\AdminJadwalService`) compares `jam_mulai`/`jam_selesai`
     * when it validates a new weekly window, and it used to carry a byte-for-byte
     * private copy of this method. Two copies of a normalisation that every other
     * layer's ordering depends on is a rule that can drift, and a drifted one would
     * show up as a window the admin API accepts and the slot service then reads
     * differently. A second caller is not a reason for a second implementation.
     */
    public static function detik(string $waktu): int
    {
        $bagian = explode(':', $waktu);

        return ((int) ($bagian[0] ?? 0)) * 3600
            + ((int) ($bagian[1] ?? 0)) * 60
            + (int) ($bagian[2] ?? 0);
    }

    /**
     * Seconds since midnight back to `H:i:s`.
     */
    private function keWaktu(int $detik): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($detik, 3600), intdiv($detik % 3600, 60), $detik % 60);
    }

    /**
     * Parse a `Y-m-d` request date, refusing the calendar's rollover.
     *
     * `createFromFormat` accepts `2026-13-45` and returns `2027-02-14`, so the
     * round trip is what actually validates the input.
     *
     * @throws InvalidArgumentException when the string is not a real calendar date
     */
    /**
     * Parse a `Y-m-d` request date, refusing the calendar's rollover.
     *
     * `createFromFormat` accepts `2026-13-45` and returns `2027-02-14`, so the
     * round trip is what actually validates the input.
     *
     * The return type stays `Illuminate\Support\Carbon`, not `CarbonInterface`:
     * the value's only two consumers are `$hari->dayOfWeek` and
     * `StrBerlaku::berlakuPada()`, and widening the whole path to an immutable
     * would force a second, unrelated type change in `StrBerlaku` for no gain. The
     * immutable values live in `hariIni()` and in the elapsed-slot comparison,
     * which is where the policy actually needs them.
     *
     * @throws InvalidArgumentException when the string is not a real calendar date
     */
    private function tanggalValid(string $tanggal): Carbon
    {
        $hari = Carbon::createFromFormat('!Y-m-d', $tanggal);

        if ($hari === false || $hari->format('Y-m-d') !== $tanggal) {
            throw new InvalidArgumentException(
                'tanggal must be a real calendar date in Y-m-d, got ['.$tanggal.']',
            );
        }

        return $hari->startOfDay();
    }

    /**
     * Today, on the clinic's calendar, or the caller's explicit day.
     *
     * `config/app.php` is UTC while `dibuat_at`/`diubah_at` are
     * `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` and the rest of the schema stores
     * naive wall clock, so a PHP-built UTC date would compare a Jakarta calendar
     * day against a UTC one.
     *
     * It was `SELECT CURDATE()`, and that was a bug that happened to be invisible:
     * `CURDATE()` is the **server's** wall clock, so it answered correctly only
     * because this host is set to WIB. Pinning the connection to `+00:00` - which
     * `docs/timezone-policy.md` requires for every `TIMESTAMP` read - would have
     * silently turned "today" into the UTC day, and a clinic seven hours ahead
     * would open its book on the wrong date between midnight and seven in the
     * morning WIB. A clinic's "today" is a business fact, so it is computed in the
     * clinic's zone rather than read off whichever server is answering.
     *
     * Cached per instance so two calls in one request cannot disagree across a
     * midnight rollover.
     */
    private function hariIni(?CarbonInterface $acuan = null): CarbonInterface
    {
        if ($acuan !== null) {
            return $acuan->copy()->startOfDay();
        }

        if ($this->hariIni === null) {
            $this->hariIni = WaktuIndonesia::now()->startOfDay();
        }

        return $this->hariIni->copy();
    }
}
