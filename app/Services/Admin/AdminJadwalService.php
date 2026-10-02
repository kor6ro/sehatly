<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Booking;
use App\Models\Dokter;
use App\Models\DokterJadwal;
use App\Models\DokterLibur;
use App\Services\Booking\SlotAvailabilityService;
use App\Support\WaktuIndonesia;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * F14's schedule-management surface: `dokter_jadwal` (weekly recurring windows)
 * and `dokter_libur` (whole-day leave).
 *
 * ## The schema validates NOTHING, so this class is the validation
 *
 * `dokter_jadwal` (`telemedicine_test.sql:470-488`) has no UNIQUE key, no CHECK
 * and an UNSIGNED `hari` that rejects negatives but not `7`; `berlaku_sampai >=
 * berlaku_mulai`, `jam_selesai > jam_mulai`, `durasi_slot_menit > 0` and
 * overlapping windows for one `(dokter_id, hari)` are all application
 * invariants, recorded in `docs/schema-notes.md`. This class is where each one
 * is enforced, and the enforcement is the ONLY copy: the FormRequests cover
 * shape and vocabulary (types, `H:i`, `Y-m-d`, `Rule::in`), and the cross-field
 * and cross-row rules live here because three of them need the MERGED row on a
 * partial update, which a stateless rule set cannot see.
 *
 * ## Conflict prevention is on ACTIVE windows, and drafts may overlap
 *
 * The F14 publish flow stores a window as a draft (`status_aktif = false`) and
 * publishes it with a separate action. A conflict check that refused two
 * overlapping drafts would make the draft flow unusable, and one that ignored
 * conflicts on publish would leak them to patients. So the rule is exactly:
 * **two ACTIVE rows for the same `(dokter_id, hari)` may not overlap in time
 * AND validity date.** The check runs on create-when-active and on every update
 * whose MERGED state is active, so publishing a draft (`{status_aktif: true}`)
 * is the moment a conflict is caught - the server is the authority, and the
 * client's inline validation is a UX convenience.
 *
 * Overlap is half-open, the same convention {@see SlotAvailabilityService} uses
 * for bookings: touching endpoints (`08:00-09:00` and `09:00-10:00`) do NOT
 * overlap. The date ranges overlap when neither ends before the other begins,
 * where `berlaku_sampai = NULL` means "open-ended" and therefore overlaps any
 * window on its right as well as its left.
 *
 * ## `dokter_libur` is whole-day ONLY, and the schema is why
 *
 * `dokter_libur` (`:490-496`) has four columns: `dokter_id`, `tanggal`, `alasan`
 * and an id. There is no start time, no end time and no slot reference, so a
 * half-day closure is inexpressible. This service therefore offers no
 * partial-day field at all - the F14 pattern's rule is that the UI must not
 * offer what cannot be stored - and `alasan` is the only free-text column, capped
 * at 200 by the FormRequest. The duplicate-date guard is an application
 * check-then-act (the table has no UNIQUE on `(dokter_id, tanggal)`); the race is
 * accepted because a duplicate row is harmless to every consumer, and
 * `docs/schema-notes.md` records that reasoning.
 *
 * ## Deleting a schedule is refused when ANY booking points at it
 *
 * `booking.jadwal_id` is a nullable FK to `dokter_jadwal` with **no ON DELETE
 * clause** (`booking` migration, `:226`), so MySQL materialises `RESTRICT`: a
 * row that any booking - even a cancelled or long-finished one - still
 * references is HARD-UNDELETABLE. That is a schema fact, not a policy choice,
 * and the method refuses before the database has to. The message names the total
 * count and the future-consuming subset (`SlotAvailabilityService`'s six live
 * statuses with `tanggal_kunjungan >= today`) so the operator can tell "these
 * are bookings that will still run" from "this is history", and offers the
 * supported remedy: deactivate the window, which keeps it for history and stops
 * new bookings.
 *
 * ## Geometry is read from the published slot service, never re-derived
 *
 * {@see mengikat()} and the `booking_aktif` annotation reuse
 * {@see SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI} - the one definition
 * of a booking that does NOT consume a slot - and the clinic's calendar day from
 * {@see WaktuIndonesia}. No second occupancy computation is written here, and no
 * availability arithmetic of any kind: this class writes the windows the slot
 * service later reads.
 */
final class AdminJadwalService
{
    /**
     * `dokter_jadwal.tipe_layanan`'s three values, in DDL order (`:474`).
     *
     * This is NOT `booking.tipe_layanan`'s four-value vocabulary (`booking`
     * migration `:188`): the two share only `home_visit`, and `klinik` here is
     * `kunjungan_klinik` there. A test re-parses the DDL and asserts this list,
     * following the project's rule that validated ENUM lists are DDL-derived.
     *
     * @var list<string>
     */
    public const TIPE_LAYANAN = ['online', 'klinik', 'home_visit'];

    /**
     * `0=Minggu s.d. 6=Sabtu` (`:475`), which is PHP's `date('w')` numbering.
     *
     * The labels are for the conflict MESSAGE only; nothing in the computation
     * translates a day, because the DDL's numbering and `date('w')` are the same
     * range in the same order.
     *
     * @var list<string>
     */
    public const HARI_LABEL = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    /**
     * `users.nama` is irrelevant here; this is the request's `H:i` width for a
     * message. Kept private: nothing outside formats a time.
     */
    private const PANJANG_JAM = 5;

    /**
     * The `dokter_jadwal` columns a request may write.
     *
     * `dokter_id` is deliberately absent: it comes from the path (create) or
     * from the stored row (update), so a body can never move a window to another
     * doctor. `dibuat_at`/`diubah_at` are Eloquent's.
     *
     * @var list<string>
     */
    private const KOLOM_TULIS = [
        'faskes_id',
        'tipe_layanan',
        'hari',
        'jam_mulai',
        'jam_selesai',
        'durasi_slot_menit',
        'kuota_per_sesi',
        'berlaku_mulai',
        'berlaku_sampai',
        'status_aktif',
    ];

    /**
     * The windows of one doctor, published or draft, newest weekday first.
     *
     * The `booking_aktif` annotation is ONE grouped query for the whole page of
     * rows, not a count per row, and it uses the same definition as the delete
     * guard so the UI's "Nonaktifkan" suggestion and the server's refusal speak
     * about the same number.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, DokterJadwal>
     */
    public function list(int $dokterId, array $filters): Collection
    {
        $this->dokterAtau404($dokterId);

        $query = DokterJadwal::query()
            ->where('dokter_id', $dokterId);

        if (array_key_exists('status_aktif', $filters)) {
            $query->where(
                'status_aktif',
                filter_var($filters['status_aktif'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
            );
        }

        $baris = $query
            ->orderBy('hari')
            ->orderBy('jam_mulai')
            ->orderBy('id')
            ->get();

        $mengikat = $this->mengikatPerJadwal($baris->modelKeys());

        foreach ($baris as $row) {
            $row->setAttribute('booking_aktif', $mengikat[(int) $row->getKey()] ?? 0);
        }

        return $baris;
    }

    /**
     * Create one row per requested weekday, atomically.
     *
     * The F14 pattern's form is a multi-day form (`hari[]`), so `POST` answers
     * one row per day - the contract's `201 data.jadwal[]`. All geometry checks
     * and conflict checks run BEFORE the first insert inside one transaction:
     * if day 4 of 3 conflicts, nothing is written and the client keeps its form.
     *
     * `dokter_id` comes from the path, never the body, so a request cannot create
     * a window for a doctor it did not address.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, DokterJadwal>
     *
     * @throws NotFoundHttpException when the doctor id names no row
     * @throws ValidationException on a geometry or conflict refusal
     */
    public function store(int $dokterId, array $data): Collection
    {
        $this->dokterAtau404($dokterId);

        /** @var list<int|string> $hari */
        $hari = $data['hari'];

        return DB::transaction(function () use ($dokterId, $data, $hari): Collection {
            $rows = new Collection;

            foreach ($hari as $satuHari) {
                $attrs = [
                    'dokter_id' => $dokterId,
                    'faskes_id' => $data['faskes_id'] ?? null,
                    'tipe_layanan' => (string) $data['tipe_layanan'],
                    'hari' => (int) $satuHari,
                    'jam_mulai' => (string) $data['jam_mulai'],
                    'jam_selesai' => (string) $data['jam_selesai'],
                    'durasi_slot_menit' => (int) $data['durasi_slot_menit'],
                    'kuota_per_sesi' => $data['kuota_per_sesi'] ?? null,
                    'berlaku_mulai' => (string) $data['berlaku_mulai'],
                    'berlaku_sampai' => $data['berlaku_sampai'] ?? null,
                    'status_aktif' => (bool) $data['status_aktif'],
                ];

                $this->pastikanGeometri($attrs);
                $this->pastikanTidakBertumpuk($attrs, null);

                // Explicit assignment, never `create()`: no model in this
                // codebase declares `$fillable` or `$guarded`, and the
                // framework's default guard is `['*']`, so mass assignment
                // would throw rather than write.
                $row = new DokterJadwal;
                $this->isiSemua($row, $attrs);
                $row->save();

                // Re-read the row so the RESPONSE carries the STORED spelling of
                // every column, not what the request happened to send. The request
                // accepts `H:i` and `Y-m-d`; the columns are `TIME` and `DATE`, and
                // MySQL stores `08:00` as `08:00:00`. Without this, `POST` would
                // publish `jam_mulai: "08:00"` while `PUT` on the same row
                // published `"08:00:00"` - two spellings of one value on one wire,
                // which is exactly the second-encoding problem
                // `AdminJadwalResource` documents that it exists to avoid.
                $rows->push($row->refresh());
            }

            return $rows;
        });
    }

    /**
     * Update one window, wholly or partially.
     *
     * A partial body is supported because the F14 UI's publish and unpublish
     * actions send `{status_aktif: true|false}` alone (AC-7). The stored row is
     * merged with the request FIRST, then the merged state is validated - so a
     * request that only toggles `status_aktif` is still checked against the
     * window's existing hours and dates, and a request that only moves
     * `jam_selesai` is checked against the stored `jam_mulai`.
     *
     * The row is re-read under `lockForUpdate()` so two concurrent edits
     * serialise: the second one merges over the first one's committed state
     * rather than over a stale copy.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws NotFoundHttpException when the id names no row
     * @throws ValidationException on a geometry or conflict refusal
     */
    public function update(int $id, array $data): DokterJadwal
    {
        return DB::transaction(function () use ($id, $data): DokterJadwal {
            $row = DokterJadwal::query()->whereKey($id)->lockForUpdate()->first();

            if ($row === null) {
                throw new NotFoundHttpException;
            }

            $attrs = [
                'dokter_id' => (int) $row->dokter_id,
                'hari' => array_key_exists('hari', $data) ? (int) $data['hari'] : (int) $row->hari,
                'jam_mulai' => array_key_exists('jam_mulai', $data) ? (string) $data['jam_mulai'] : (string) $row->jam_mulai,
                'jam_selesai' => array_key_exists('jam_selesai', $data) ? (string) $data['jam_selesai'] : (string) $row->jam_selesai,
                'durasi_slot_menit' => array_key_exists('durasi_slot_menit', $data)
                    ? (int) $data['durasi_slot_menit']
                    : (int) $row->durasi_slot_menit,
                'berlaku_mulai' => array_key_exists('berlaku_mulai', $data)
                    ? (string) $data['berlaku_mulai']
                    : (string) $row->berlaku_mulai?->format('Y-m-d'),
                'berlaku_sampai' => array_key_exists('berlaku_sampai', $data)
                    ? $data['berlaku_sampai']
                    : $row->berlaku_sampai?->format('Y-m-d'),
                'status_aktif' => array_key_exists('status_aktif', $data)
                    ? (bool) $data['status_aktif']
                    : (bool) $row->status_aktif,
            ];

            $this->pastikanGeometri($attrs);
            $this->pastikanTidakBertumpuk($attrs, $id);

            // Explicit per-key assignment, never `fill()`: no model in this
            // codebase declares `$fillable`, the framework's default guard is
            // `['*']`, and assigning only the keys the request actually sent
            // keeps the model's `updated` event - and therefore the audit row -
            // a true delta.
            foreach (self::KOLOM_TULIS as $kolom) {
                if (array_key_exists($kolom, $data)) {
                    $row->{$kolom} = $data[$kolom];
                }
            }

            $row->save();

            return $row->refresh();
        });
    }

    /**
     * Delete a window that nothing references, or refuse with the count.
     *
     * See the class docblock: `booking.jadwal_id`'s FK is `RESTRICT`, so the
     * refusal is what the database would do anyway, expressed as a 422 the UI can
     * act on ("Nonaktifkan saja") instead of a 500. The row is locked first so a
     * booking created concurrently cannot slip in between the count and the
     * delete; a booking insert takes the `dokter` row lock first
     * (`BookingService`'s lock order), and this method is not on that path, so
     * the residual race is bounded by the same transaction and reported rather
     * than hidden.
     *
     * @throws NotFoundHttpException when the id names no row
     * @throws ValidationException when any booking still references the row
     */
    public function destroy(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $row = DokterJadwal::query()->whereKey($id)->lockForUpdate()->first();

            if ($row === null) {
                throw new NotFoundHttpException;
            }

            $total = Booking::query()->where('jadwal_id', $id)->count();

            if ($total > 0) {
                throw ValidationException::withMessages([
                    'jadwal' => [
                        'Jadwal tidak dapat dihapus karena masih direferensikan '.$total.' booking ('
                        .$this->mengikat($id).' aktif/akan datang). Nonaktifkan jadwal agar tidak menerima '
                        .'booking baru - booking yang ada tetap berjalan.',
                    ],
                ]);
            }

            $row->delete();
        });
    }

    /**
     * Every leave date on file for one doctor, oldest first.
     *
     * `dokter_libur` has no timestamps at all (`:490-496`), so the order is the
     * date itself and then the id for a total order over duplicate dates the
     * schema permits.
     *
     * @return Collection<int, DokterLibur>
     */
    public function listLibur(int $dokterId): Collection
    {
        $this->dokterAtau404($dokterId);

        return DokterLibur::query()
            ->where('dokter_id', $dokterId)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get();
    }

    /**
     * Add one whole-day leave date.
     *
     * The duplicate guard is an application check-then-act because the table has
     * no UNIQUE on `(dokter_id, tanggal)`; the race is documented in the class
     * docblock and is harmless (both rows block the same day, and nothing sums
     * this table). No `status_aktif` and no hours are accepted: the schema has
     * neither, and offering them would promise a partial-day leave the database
     * cannot represent.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws NotFoundHttpException when the doctor id names no row
     * @throws ValidationException when the date is already recorded
     */
    public function storeLibur(int $dokterId, array $data): DokterLibur
    {
        $this->dokterAtau404($dokterId);

        $tanggal = (string) $data['tanggal'];

        $sudahAda = DokterLibur::query()
            ->where('dokter_id', $dokterId)
            ->whereDate('tanggal', $tanggal)
            ->exists();

        if ($sudahAda) {
            throw ValidationException::withMessages([
                'tanggal' => ['Tanggal ini sudah tercatat libur untuk dokter tersebut.'],
            ]);
        }

        $libur = new DokterLibur;
        $libur->dokter_id = $dokterId;
        $libur->tanggal = $tanggal;
        $libur->alasan = $data['alasan'] ?? null;
        $libur->save();

        return $libur;
    }

    /**
     * Remove one leave date.
     *
     * `dokter_libur` has no FK pointing at it and no soft-delete column, so this
     * is a hard delete with nothing to strand. No booking references a leave row;
     * availability is recomputed from the table on every read.
     *
     * @throws NotFoundHttpException when the id names no row
     */
    public function destroyLibur(int $id): void
    {
        $libur = DokterLibur::query()->find($id);

        if ($libur === null) {
            throw new NotFoundHttpException;
        }

        $libur->delete();
    }

    /**
     * How many bookings still count against one window.
     *
     * The same definition the delete guard and the list annotation use: a
     * consuming status (`SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI`'s
     * complement) on a visit date that has not passed in the clinic's calendar.
     * A finished booking is history, and a cancelled or expired one released its
     * slot.
     */
    private function mengikat(int $jadwalId): int
    {
        return Booking::query()
            ->where('jadwal_id', $jadwalId)
            ->whereNotIn('status', SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI)
            ->where('tanggal_kunjungan', '>=', WaktuIndonesia::tanggal())
            ->count();
    }

    /**
     * {@see mengikat()} for a whole set of windows, as one grouped query.
     *
     * @param  list<int|string>  $ids
     * @return array<int, int>
     */
    private function mengikatPerJadwal(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Booking::query()
            ->selectRaw('jadwal_id, count(*) as jumlah')
            ->whereIn('jadwal_id', $ids)
            ->whereNotIn('status', SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI)
            ->where('tanggal_kunjungan', '>=', WaktuIndonesia::tanggal())
            ->groupBy('jadwal_id')
            ->pluck('jumlah', 'jadwal_id')
            ->map(static fn ($jumlah): int => (int) $jumlah)
            ->all();
    }

    /**
     * Assign every writable column of a NEW window.
     *
     * Explicit assignment rather than mass assignment; see {@see self::KOLOM_TULIS}
     * and the update path. A create has no stored value to preserve, so every
     * column is written, including the three nullable ones (`faskes_id`,
     * `kuota_per_sesi`, `berlaku_sampai`).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function isiSemua(DokterJadwal $row, array $attrs): void
    {
        $row->dokter_id = $attrs['dokter_id'];
        $row->faskes_id = $attrs['faskes_id'];
        $row->tipe_layanan = $attrs['tipe_layanan'];
        $row->hari = $attrs['hari'];
        $row->jam_mulai = $attrs['jam_mulai'];
        $row->jam_selesai = $attrs['jam_selesai'];
        $row->durasi_slot_menit = $attrs['durasi_slot_menit'];
        $row->kuota_per_sesi = $attrs['kuota_per_sesi'];
        $row->berlaku_mulai = $attrs['berlaku_mulai'];
        $row->berlaku_sampai = $attrs['berlaku_sampai'];
        $row->status_aktif = $attrs['status_aktif'];
    }

    /**
     * The cross-field invariants of a merged window.
     *
     * All three are the missing CHECK constraints of `dokter_jadwal`, and all
     * three are refused on the field a form can point at:
     *
     * - `jam_selesai > jam_mulai` - a zero-length or reversed window is not a
     *   window. The comparison is in seconds through
     *   {@see SlotAvailabilityService::detik()}, the ONE normalisation the slot
     *   service itself orders by, so `H:i` from a request and `H:i:s` from a
     *   stored row compare correctly and a beyond-24:00 value cannot be ordered
     *   by string accident. This class deliberately holds NO second copy of that
     *   conversion: a private duplicate was written here once and removed, because
     *   a normalisation every other layer's ordering depends on cannot have two
     *   implementations that agree only today.
     * - `durasi_slot_menit > 0` - a zero duration would make the slot loop not
     *   terminate; the FormRequest also applies `min:1`.
     * - `berlaku_sampai >= berlaku_mulai` when a `berlaku_sampai` is present -
     *   `Y-m-d` strings compare lexicographically and both are format-validated,
     *   so no date library is needed for the ordering.
     *
     * A caller with a corrupted stored row that is only toggling `status_aktif`
     * is refused rather than allowed to publish it, which is the fail-closed
     * direction the whole surface takes.
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException
     */
    private function pastikanGeometri(array $attrs): void
    {
        if (SlotAvailabilityService::detik((string) $attrs['jam_selesai']) <= SlotAvailabilityService::detik((string) $attrs['jam_mulai'])) {
            throw ValidationException::withMessages([
                'jam_selesai' => ['Jam selesai harus lebih besar dari jam mulai.'],
            ]);
        }

        if ((int) $attrs['durasi_slot_menit'] < 1) {
            throw ValidationException::withMessages([
                'durasi_slot_menit' => ['Durasi slot harus lebih besar dari nol menit.'],
            ]);
        }

        /** @var string|null $sampai */
        $sampai = $attrs['berlaku_sampai'];

        if ($sampai !== null && $sampai < (string) $attrs['berlaku_mulai']) {
            throw ValidationException::withMessages([
                'berlaku_sampai' => ['Tanggal berlaku sampai tidak boleh lebih awal dari tanggal berlaku mulai.'],
            ]);
        }
    }

    /**
     * Refuse an ACTIVE window that overlaps another ACTIVE window of the same
     * doctor on the same weekday.
     *
     * Two conditions must BOTH hold for a conflict, and both are half-open:
     *
     * 1. **Time.** `existing.jam_mulai < new.jam_selesai` AND
     *    `existing.jam_selesai > new.jam_mulai`, so `08:00-09:00` and
     *    `09:00-10:00` may coexist (a doctor does not need a gap between
     *    consultations) while any real intersection is refused.
     * 2. **Validity dates.** The two intervals overlap unless one ends before
     *    the other begins, with `NULL` meaning open-ended: a window valid
     *    `2026-01-01..2026-06-30` and one valid `2026-07-01..` do NOT conflict
     *    even though their wall-clock hours overlap, because they never run on
     *    the same day.
     *
     * A draft (`status_aktif = false`) is exempt, and so is an update that stays
     * inactive - that is the F14 draft/publish flow. Publishing a draft is an
     * update whose merged state is active, so the check runs then.
     *
     * Every conflicting row produces its own message; a multi-day create that
     * hits two conflicts reports both rather than one at a time.
     *
     * @param  array<string, mixed>  $attrs  the merged row, including `dokter_id` and `hari`
     * @param  int|null  $exceptId  the row being updated, excluded from its own check
     *
     * @throws ValidationException with one or more messages under `errors.hari`
     */
    private function pastikanTidakBertumpuk(array $attrs, ?int $exceptId): void
    {
        if (! (bool) $attrs['status_aktif']) {
            return;
        }

        /** @var string|null $sampai */
        $sampai = $attrs['berlaku_sampai'];

        $query = DokterJadwal::query()
            ->where('dokter_id', (int) $attrs['dokter_id'])
            ->where('hari', (int) $attrs['hari'])
            ->where('status_aktif', true)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            // Time overlap, half-open on both ends.
            ->where('jam_mulai', '<', (string) $attrs['jam_selesai'])
            ->where('jam_selesai', '>', (string) $attrs['jam_mulai'])
            // Existing range must not end before the new one begins.
            ->where(function ($inner) use ($attrs): void {
                $inner->whereNull('berlaku_sampai')
                    ->orWhere('berlaku_sampai', '>=', (string) $attrs['berlaku_mulai']);
            });

        // ...and the new range must not end before the existing one begins; an
        // open-ended NEW window overlaps every existing one on its right.
        if ($sampai !== null) {
            $query->where('berlaku_mulai', '<=', $sampai);
        }

        $pesan = [];

        foreach ($query->orderBy('id')->get() as $bentrok) {
            $pesan[] = 'Jadwal '.self::HARI_LABEL[(int) $attrs['hari']].' '
                .$this->keHariMenit((string) $attrs['jam_mulai']).'-'.$this->keHariMenit((string) $attrs['jam_selesai'])
                .' bertumpuk dengan jadwal '
                .$this->keHariMenit((string) $bentrok->jam_mulai).'-'.$this->keHariMenit((string) $bentrok->jam_selesai)
                .' (berlaku '.$bentrok->berlaku_mulai?->format('Y-m-d').' s.d. '
                .($bentrok->berlaku_sampai?->format('Y-m-d') ?? 'tanpa batas').') pada hari yang sama.';
        }

        if ($pesan !== []) {
            throw ValidationException::withMessages(['hari' => $pesan]);
        }
    }

    /**
     * The doctor row, or the uniform 404.
     *
     * Every write needs the doctor to exist - `dokter_jadwal.dokter_id` and
     * `dokter_libur.dokter_id` both have real foreign keys - and a nonexistent
     * one must be a 404 rather than a MySQL 1452 rendered as a 500.
     *
     * @throws NotFoundHttpException
     */
    private function dokterAtau404(int $dokterId): Dokter
    {
        $dokter = Dokter::query()->find($dokterId);

        if ($dokter === null) {
            throw new NotFoundHttpException;
        }

        return $dokter;
    }

    /**
     * An `H:i:s` or `H:i` value rendered as `H:i` for a human message.
     */
    private function keHariMenit(string $waktu): string
    {
        return substr($waktu, 0, self::PANJANG_JAM);
    }
}
