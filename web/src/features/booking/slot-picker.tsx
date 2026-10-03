import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CircleAlert, Clock, MapPin } from 'lucide-react';
import { labelAlasanSlot, slotOptions } from '@/lib/api/jadwal';
import { formatRentangJamZona } from '@/lib/waktu';
import type { Slot, TanggalSlot } from '@/lib/api/jadwal';
import { ApiError } from '@/lib/http';
import { Badge } from '@/components/ui/badge';
import { LoadingState, SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, NotFoundState } from '@/components/states/error-state';

/**
 * The slot half of the booking flow, reading `GET /api/v1/dokter/{dokter}/slot`.
 *
 * ## Four states, and the 404 is one of them
 *
 * | situation | what the server said | what this renders |
 * | --- | --- | --- |
 * | in flight | - | `SkeletonRows` |
 * | 5xx / network | an error that could succeed next time | `ErrorState` **with** a retry |
 * | 404 | `Resource not found.` | `NotFoundState` |
 * | 200, `slots: []` | "no bookable slots on this date" | `EmptyState` |
 * | 200, rows | the published slots | the slot grid |
 *
 * ## The route exists, and the old "not deployed" branch was wrong
 *
 * Measured live on 2026-10-01: `GET /api/v1/dokter/{dokter}/slot?tanggal=...` answers
 * **200** with `timezone: "Asia/Jakarta"` and a `slots` array (16 rows on a seeded
 * schedule), and `GET /api/v1/dokter/{dokter}/jadwal` answers 200 as well. The earlier
 * "endpoint belum terdaftar" panel and its free-text time box were built on a stale
 * measurement and have been removed; the pattern file records the correction.
 *
 * The remaining 404 is the **doctor** being ineligible, and it is deliberately not
 * distinguished from an unmatched path: `DokterController::slot()` publishes the same
 * `Resource not found.` body for all six ineligibility reasons *and* the kernel publishes
 * it for an unmatched route, so a client-side discriminator would be guessing. The page's
 * own doctor-detail gate has already answered 404 before this component mounts, so this
 * branch is a race guard rather than the primary path.
 *
 * ## Why the client never computes a slot
 *
 * Every one of `SlotAvailabilityService`'s four rules is a **server** answer: the window
 * subtraction from `dokter_jadwal`, the `dokter_libur` holiday closure, the quota-aware
 * overlap count, and the STR boundary evaluated against the **consultation date** rather
 * than today. Re-deriving any of them in the browser would need the same joins in
 * JavaScript, would drift from the server the first time a rule changed, and - worst -
 * would make the UI *wrong* rather than merely incomplete. So this component renders what
 * it is given and refuses to guess.
 *
 * ## Times are converted for display, never for submission
 *
 * `jam_mulai` / `jam_selesai` are Asia/Jakarta wall clock. They are rendered through
 * {@link formatRentangJamZona}, which converts to the device zone and appends the zone
 * label (`09.00–09.15 WIB`); the `H:i:s` string handed to `onSelect` is the server's own
 * value, unconverted, because that is what `POST /booking` validates.
 */
export function SlotPicker({
    dokterId,
    tanggal,
    selected,
    onSelect,
    className,
}: {
    dokterId: string;
    tanggal: TanggalSlot | null;
    selected: string | null;
    /**
     * The chosen start time as `H:i:s`, plus the whole published row it came from.
     *
     * The string is what `POST /booking` validates; the row carries the `jadwal_id` and
     * `jam_selesai` a reschedule must name (`PUT /booking/{id}/jadwal-ulang`), which no
     * client-side recomputation could produce. Callers that only need the time can keep a
     * one-argument handler.
     */
    onSelect: (jamMulai: string, slot: Slot) => void;
    className?: string;
}) {
    const query = useQuery({
        ...slotOptions(dokterId, tanggal ?? ''),
        enabled: tanggal !== null,
    });

    const slots = useMemo(() => query.data?.data.slots ?? [], [query.data]);

    if (tanggal === null) {
        return (
            <EmptyState
                compact
                title="Pilih tanggal terlebih dahulu"
                description="Ketersediaan jam hanya dapat dimuat setelah tanggal kunjungan dipilih."
            />
        );
    }

    if (query.isPending) {
        return (
            <LoadingState label="Memuat ketersediaan jam...">
                <SkeletonRows rows={3} />
            </LoadingState>
        );
    }

    if (query.isError) {
        if (query.error instanceof ApiError && query.error.isNotFound) {
            return (
                <NotFoundState
                    title="Dokter tidak dapat dipesan"
                    detail="Profil dokter ini tidak tersedia di direktori, sehingga jadwalnya tidak dapat dimuat."
                />
            );
        }

        return (
            <ErrorState
                error={query.error}
                onRetry={() => {
                    void query.refetch();
                }}
            />
        );
    }

    if (slots.length === 0) {
        /**
         * A published empty list is a real answer, not a gap. It is what
         * `getSlotTerbuka()` returns for a lapsed STR, for a weekday with no window, and
         * for a doctor with no `dokter_jadwal` row at all. The copy names all three
         * reasons because the endpoint deliberately collapses them into one empty list -
         * distinguishing them would leak a doctor's licence state to an unauthenticated
         * caller, and that is the service's own design decision rather than an oversight.
         */
        return (
            <EmptyState
                compact
                title="Tidak ada jam tersedia"
                description="Server tidak memublikasikan jam yang dapat dipesan pada tanggal ini. Penyebabnya dapat berupa hari tanpa jadwal, seluruh kuota terisi, hari libur dokter, atau STR yang tidak berlaku pada tanggal kunjungan."
            />
        );
    }

    const tersedia = slots.filter((slot) => slot.tersedia);

    return (
        <div className={className}>
            <SlotGrid
                slots={slots}
                tanggal={tanggal}
                selected={selected}
                onSelect={onSelect}
            />

            <p className="text-muted-foreground mt-3 text-xs">
                {tersedia.length} dari {slots.length} jam dapat dipesan. Jam yang
                tidak tersedia tetap ditampilkan beserta alasannya.
            </p>
        </div>
    );
}

function SlotGrid({
    slots,
    tanggal,
    selected,
    onSelect,
}: {
    slots: Slot[];
    tanggal: TanggalSlot;
    selected: string | null;
    onSelect: (jamMulai: string, slot: Slot) => void;
}) {
    /**
     * A `div` with `role="listbox"` and `role="option"` buttons as direct children.
     *
     * The earlier `ul`/`li` wrapper put an implicit `listitem` between the listbox and its
     * options, which is not a permitted child of `listbox` and is exactly what axe's
     * `aria-required-children` rule reports. The list semantics were never load-bearing
     * here - the options are the list - so the wrapper is gone rather than papered over
     * with a role.
     */
    return (
        <div
            role="listbox"
            aria-label="Jam yang dapat dipilih"
            data-slot="slot-picker"
            className="grid grid-cols-2 gap-2 sm:grid-cols-3"
        >
            {slots.map((slot) => (
                <SlotButton
                    key={`${slot.jadwal_id}-${slot.jam_mulai}`}
                    slot={slot}
                    tanggal={tanggal}
                    selected={selected === slot.jam_mulai}
                    onSelect={onSelect}
                />
            ))}
        </div>
    );
}

function SlotButton({
    slot,
    tanggal,
    selected,
    onSelect,
}: {
    slot: Slot;
    tanggal: TanggalSlot;
    selected: boolean;
    onSelect: (jamMulai: string, slot: Slot) => void;
}) {
    const alasan = slot.alasan === null ? null : labelAlasanSlot(slot.alasan);

    /**
     * An unavailable slot is rendered as a **disabled button that still says why**, not
     * hidden. Hiding it would leave a patient who knows the doctor works 09:00-12:00
     * staring at a half-empty grid with no explanation, and `alasan` exists on the wire
     * for exactly this: the service publishes the day's candidates on a holiday with
     * `tersedia: false` and `alasan: 'libur'` rather than returning an empty list, so the
     * reason can be shown.
     *
     * `min-h-11` is the 44 px touch target `AGENTS.md` requires, and the explicit
     * `focus-visible` ring is what makes keyboard focus visible on a raw `<button>` that
     * does not inherit the shadcn `Button` treatment.
     */
    return (
        <button
            type="button"
            role="option"
            data-slot="slot-option"
            aria-selected={selected}
            disabled={!slot.tersedia}
            onClick={() => {
                onSelect(slot.jam_mulai, slot);
            }}
            className={[
                'focus-visible:ring-ring flex min-h-11 w-full flex-col gap-1 rounded-md border px-3 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none',
                selected
                    ? 'border-primary bg-primary text-primary-foreground'
                    : slot.tersedia
                      ? 'border-input bg-background hover:bg-accent hover:text-accent-foreground'
                      : 'border-input bg-muted/50 text-muted-foreground cursor-not-allowed',
            ].join(' ')}
        >
            <span className="flex items-center gap-1.5 text-sm font-medium tabular-nums">
                <Clock aria-hidden className="size-3.5" />

                {formatRentangJamZona(
                    slot.jam_mulai,
                    slot.jam_selesai,
                    tanggal,
                )}
            </span>

            {slot.tersedia ? null : (
                <span className="text-destructive text-xs">{alasan}</span>
            )}

            {/**
             * `faskes_id` is published because the column carries meaning: the DDL comment
             * at `telemedicine_test.sql:473` reads "NULL = layanan online murni". A `null`
             * is therefore "online", not "unknown", and showing a venue placeholder for it
             * would be wrong.
             */}
            <span
                className={[
                    'flex items-center gap-1 text-xs',
                    selected
                        ? 'text-primary-foreground'
                        : 'text-muted-foreground',
                ].join(' ')}
            >
                <MapPin aria-hidden className="size-3" />

                {slot.faskes_id === null ? 'Layanan online' : `Faskes #${slot.faskes_id}`}
            </span>
        </button>
    );
}

/**
 * A compact notice for a `slot` 422, rendered above the form rather than as a toast.
 *
 * `ApiError.isSlotTaken` matches status 422 plus a top-level `slot` key, which is exactly
 * what the Dart client's `ApiException.isSlotTaken` matches, so the two clients classify
 * the same refusal identically. The message is the server's own - `Slot sudah penuh untuk
 * waktu ini.`, `Slot tidak dipublikasikan oleh jadwal dokter.`, `Slot sudah lewat.`, `Slot
 * tidak tersedia pada tanggal tersebut.` - and it is shown verbatim rather than replaced
 * with generic failure copy, because each of the four means something different to a
 * patient choosing a different time.
 */
export function SlotTakenNotice({ messages }: { messages: string[] }) {
    if (messages.length === 0) {
        return null;
    }

    return (
        <div
            role="alert"
            data-slot="slot-taken-notice"
            className="border-destructive/40 bg-destructive/10 flex flex-col gap-1.5 rounded-lg border p-4"
        >
            <p className="flex items-center gap-2 font-medium">
                <CircleAlert aria-hidden className="size-4" />

                Jam yang dipilih tidak dapat dipesan
            </p>

            <ul className="flex flex-col gap-0.5">
                {messages.map((message) => (
                    <li key={message} className="text-sm">
                        {message}
                    </li>
                ))}
            </ul>

            <Badge variant="outline" className="w-fit">
                Status 422, field `slot`
            </Badge>
        </div>
    );
}
