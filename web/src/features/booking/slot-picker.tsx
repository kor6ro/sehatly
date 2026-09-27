import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CircleAlert, Clock, Info, MapPin } from 'lucide-react';
import {
    jadwalEndpointBelumTerdaftar,
    labelAlasanSlot,
    slotOptions,
} from '@/lib/api/jadwal';
import { jamKeHms } from '@/lib/tanggal';
import { formatJam } from '@/lib/format';
import type { Slot, TanggalSlot } from '@/lib/api/jadwal';
import { ApiError } from '@/lib/http';
import { Badge } from '@/components/ui/badge';
import { Field, FieldInput } from '@/components/form/field';
import { LoadingState, SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, NotFoundState } from '@/components/states/error-state';

/**
 * The slot half of the booking flow, reading `GET /api/v1/dokter/{dokter}/slot`.
 *
 * ## Five states, and the two 404s are not one of them
 *
 * | situation | what the server said | what this renders |
 * | --- | --- | --- |
 * | in flight | - | `SkeletonRows` |
 * | 5xx / network | an error that could succeed next time | `ErrorState` **with** a retry |
 * | 404, route absent | `Resource not found.` | {@link EndpointBelumTerdaftar} |
 * | 404, doctor absent | `DokterController`'s own Indonesian message | `NotFoundState` |
 * | 200, `slots: []` | "no bookable slots on this date" | `EmptyState` |
 * | 200, rows | the published slots | the slot grid |
 *
 * The two 404s share a status and nothing else, and collapsing them is the defect this
 * component exists to avoid. `DokterController::show()` answers one 404 for six situations
 * - absent, unverified, inactive, off telemedicine, STR-expired, soft-deleted - and
 * publishes a single generic message precisely so nobody can enumerate them. An unmatched
 * **path** instead gets Laravel's router message. The message is therefore a reliable
 * discriminator, and it is the difference between "this deployment has not deployed the
 * slot feature" and "that doctor cannot be booked", which need opposite actions.
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
 */
export function SlotPicker({
    dokterId,
    tanggal,
    selected,
    onSelect,
    jamManual,
    onJamManualChange,
    className,
}: {
    dokterId: string;
    tanggal: TanggalSlot | null;
    selected: string | null;
    onSelect: (jamMulai: string) => void;
    /**
     * The free-text fallback, and its setter. Shown **only** when the slot endpoint is
     * absent - see {@link EndpointBelumTerdaftar} for why that is the one case where the
     * client may ask for a time instead of publishing one.
     */
    jamManual: string;
    onJamManualChange: (value: string) => void;
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
        if (jadwalEndpointBelumTerdaftar(query.error)) {
            return (
                <EndpointBelumTerdaftar
                    dokterId={dokterId}
                    tanggal={tanggal}
                    jamManual={jamManual}
                    onJamManualChange={onJamManualChange}
                />
            );
        }

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
    selected,
    onSelect,
}: {
    slots: Slot[];
    selected: string | null;
    onSelect: (jamMulai: string) => void;
}) {
    return (
        <ul
            role="listbox"
            aria-label="Jam yang dapat dipilih"
            className="grid grid-cols-2 gap-2 sm:grid-cols-3"
        >
            {slots.map((slot) => (
                <li key={`${slot.jadwal_id}-${slot.jam_mulai}`}>
                    <SlotButton
                        slot={slot}
                        selected={selected === slot.jam_mulai}
                        onSelect={onSelect}
                    />
                </li>
            ))}
        </ul>
    );
}

function SlotButton({
    slot,
    selected,
    onSelect,
}: {
    slot: Slot;
    selected: boolean;
    onSelect: (jamMulai: string) => void;
}) {
    const alasan = slot.alasan === null ? null : labelAlasanSlot(slot.alasan);

    /**
     * An unavailable slot is rendered as a **disabled button that still says why**, not
     * hidden. Hiding it would leave a patient who knows the doctor works 09:00-12:00
     * staring at a half-empty grid with no explanation, and `alasan` exists on the wire
     * for exactly this: the service publishes the day's candidates on a holiday with
     * `tersedia: false` and `alasan: 'libur'` rather than returning an empty list, so the
     * reason can be shown.
     */
    return (
        <button
            type="button"
            role="option"
            aria-selected={selected}
            disabled={!slot.tersedia}
            onClick={() => {
                onSelect(slot.jam_mulai);
            }}
            className={[
                'flex w-full flex-col gap-1 rounded-md border px-3 py-2 text-left transition-colors',
                selected
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'border-input bg-background hover:bg-accent hover:text-accent-foreground',
                slot.tersedia ? '' : 'cursor-not-allowed opacity-60',
            ].join(' ')}
        >
            <span className="flex items-center gap-1.5 text-sm font-medium tabular-nums">
                <Clock aria-hidden className="size-3.5" />

                {formatJam(slot.jam_mulai)}

                <span className="text-xs font-normal opacity-70">
                    - {formatJam(slot.jam_selesai)}
                </span>
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
            <span className="text-muted-foreground flex items-center gap-1 text-xs">
                <MapPin aria-hidden className="size-3" />

                {slot.faskes_id === null ? 'Layanan online' : `Faskes #${slot.faskes_id}`}
            </span>
        </button>
    );
}

/**
 * The state for a 404 that means **the route is not registered on this deployment**.
 *
 * ## What is being said, and why it is not an error to retry
 *
 * Measured against the dev server:
 *
 * ```
 * GET /api/v1/dokter/1/jadwal            -> 404 {"success":false,"message":"Resource not found.","errors":{}}
 * GET /api/v1/dokter/1/slot?tanggal=...  -> 404 {"success":false,"message":"Resource not found.","errors":{}}
 * ```
 *
 * `SlotAvailabilityService` implements all four rules and is endpoint-agnostic, but
 * `DokterController` has only `index`, `show` and `spesialisasiIndex` and `routes/api.php`
 * registers no `jadwal` or `slot` path - see finding F1 in
 * `.omo/evidence/task-26-sehatly.md`, which records this as that todo's deliberate scope
 * decision. So the honest screen says so, names the path, and offers a retry for the case
 * where the deployment is simply an older one.
 *
 * ## Why a free-text time is offered here and ONLY here
 *
 * This is the one situation where the client may ask the patient for a time rather than
 * publish one, and the condition is not arbitrary. `BookingService::geometriOtomatis()`
 * has a documented path for exactly this case: a start that no schedule publishes, for a
 * doctor with no `dokter_jadwal` row, is an **instant** booking, and the server derives the
 * end from `dokter.durasi_default_menit` itself. Verified live: `09:00:00` on a
 * schedule-less doctor returned 201 with `slot_selesai: "09:15:00"` and `jadwal_id: null`.
 *
 * So the input below carries **no** availability logic. It does not guess a window, does
 * not check a quota, does not consult a holiday, and does not reason about the STR. It
 * collects a string and hands it to the authoritative party, which answers 201 or a 422
 * on `slot` - and that 422 is rendered inline by the form.
 *
 * It is gated on the route being **absent** rather than on the list being empty, and that
 * distinction is the whole design: an empty `slots` array is a positive statement that
 * nothing is bookable, and answering it with a free-text box would tell a patient to pick
 * a time the server has just said does not exist.
 */
function EndpointBelumTerdaftar({
    dokterId,
    tanggal,
    jamManual,
    onJamManualChange,
}: {
    dokterId: string;
    tanggal: TanggalSlot;
    jamManual: string;
    onJamManualChange: (value: string) => void;
}) {
    return (
        <div
            role="status"
            data-slot="slot-endpoint-belum-terdaftar"
            data-testid="slot-endpoint-belum-terdaftar"
            className="border-warning/40 bg-warning/10 flex flex-col gap-4 rounded-lg border p-4"
        >
            <div className="flex flex-col gap-1.5">
                <p className="flex items-center gap-2 font-medium">
                    <Info aria-hidden className="size-4" />

                    Server belum mempublikasikan jadwal dokter
                </p>

                <p className="text-muted-foreground text-sm">
                    Endpoint{' '}
                    <code className="font-mono text-xs">
                        GET /api/v1/dokter/{dokterId}/slot
                    </code>{' '}
                    menjawab 404 pada deployment ini, sehingga daftar jam yang
                    dipublikasikan dokter tidak dapat dimuat. Klien tidak
                    menebak ketersediaan jam.
                </p>
            </div>

            <div className="flex flex-col gap-2">
                <p className="text-sm">
                    <span className="font-medium">Atau tentukan jam mulai sendiri.</span>{' '}
                    <span className="text-muted-foreground">
                        Permintaan tetap divalidasi penuh oleh server: bila slot
                        tidak dipublikasikan, sudah lewat, sudah penuh, atau STR
                        dokter tidak berlaku pada {tanggal}, permintaan akan
                        ditolak dan alasannya ditampilkan pada formulir.
                    </span>
                </p>

                <Field
                    label="Jam mulai (HH:MM)"
                    hint="Dikirim sebagai H:i:s, misalnya 09:00 menjadi 09:00:00."
                    className="max-w-48"
                >
                    <FieldInput
                        type="time"
                        value={jamManual}
                        step={60}
                        onChange={(event) => {
                            onJamManualChange(event.target.value);
                        }}
                    />
                </Field>
            </div>
        </div>
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

/** `jamKeHms` re-exported so the form needs one import for the time boundary. */
export { jamKeHms };
