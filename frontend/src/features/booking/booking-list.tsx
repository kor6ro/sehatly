import { useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import {
    AlertCircle,
    Ban,
    Bell,
    CalendarClock,
    Check,
    CheckCircle2,
    Wallet,
    WifiOff,
    XCircle,
} from 'lucide-react';
import {
    batalkanBookingMutation,
    bisaDibatalkan,
    bisaDijadwalkanUlang,
    kebijakanBookingOptions,
    labelStatusBooking,
    labelTipeLayanan,
    STATUS_BOOKING,
} from '@/lib/api/booking';
import type { KebijakanPembatalan } from '@/lib/api/booking';
import type { Refund } from '@/lib/api/refund';
import { BookingStatusBadge, BookingStatusNote } from '@/features/booking/booking-status-badge';
import { RefundCard } from '@/features/booking/refund-card';
import { RescheduleDialog } from '@/features/booking/reschedule-dialog';
import { describeRange, isEmptyPage, isPastLastPage } from '@/lib/api/pagination';
import { ApiError } from '@/lib/http';
import { formatTanggal, formatRupiah, formatWaktu } from '@/lib/format';
import { formatJamZona, formatRentangJamZona } from '@/lib/waktu';
import type { Booking, StatusBooking } from '@/lib/api/types';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { OfflineBanner } from '@/components/offline-banner';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Field, FieldInput, FieldSelect, FieldTextarea } from '@/components/form/field';
import { SelectItem } from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * The shared body of both booking lists.
 *
 * `GET /api/v1/pasien/booking` and `GET /api/v1/dokter/booking` are the same envelope, the
 * same `meta` block, the same eight statuses and the same cancel endpoint, and they differ
 * in exactly three ways: the path, the extra `tanggal` filter the doctor list accepts, and
 * whether `BookingResource` publishes the nested `pasien` (it does on the doctor list only,
 * because that is the one list serving somebody who is not the patient).
 *
 * So the two lists share one component and pass those three differences in, rather than
 * being two near-copies that would drift on the first status the DDL ever adds.
 *
 * ## The three states that need opposite copy
 *
 * `isEmptyPage` and `isPastLastPage` are separate decisions on the same two numbers. An
 * account with no bookings (`meta.total === 0`) and a caller on page 9 of a 1-page result
 * (`total: 1, last_page: 1, current_page: 9`, rows `[]`) both render an empty array, and
 * measured against the live API that second case is real: it answers
 * `{"booking":[],"meta":{"current_page":9,"last_page":1,"per_page":15,"total":1,"from":null,"to":null}}`.
 * One is a state to explain; the other is a navigation mistake to undo.
 *
 * ## `nomor_antrian` is not rendered
 *
 * The DDL declares the column and nothing enforces it, so a fresh booking publishes
 * `nomor_antrian: null` - confirmed on a live 201. Rendering the field at all would put a
 * blank where a number belongs and would promise a queue position the system has never
 * assigned. `nomor_booking` is the identifier a patient can actually quote, and it is
 * published by `NomorDokumen`; that is the one shown.
 *
 * ## F12: the full cancellation, reschedule and refund surface
 *
 * The dialog below implements the pattern's `[SIAP]` slice: an explicit reason vocabulary
 * over the single `alasan_pembatalan` column, the three consequence lines, a visible
 * "Batal", a `destructive` confirm, one in-flight latch, an inline success card and an
 * inline 422. It now also renders the **server's** policy (`GET /booking/{id}/kebijakan`)
 * and keeps the confirm disabled until that policy is on screen, so no cancellation can
 * be confirmed without its consequence visible (AC-9 / AC-R1).
 *
 * The row's "Jadwal ulang" opens the same-row reschedule dialog (AC-R2/AC-R3), and a
 * cancelled row renders the refund the backend wrote, matched by `booking_id` (AC-R4..R6).
 * Nothing here computes a fee, an SLA, a refund amount or a completion date: every one of
 * those is either rendered from the server or absent.
 */

/**
 * The five quick reasons, in the order the pattern lists them.
 *
 * They are the UI's own wording, not enum members: `alasan_pembatalan` is one free-text
 * `VARCHAR(255)`, so the chosen chip is written into it verbatim and nothing new is stored.
 * "Lainnya" is the only member that reveals the `textarea`.
 */
const ALASAN_CEPAT = [
    'Jadwal saya berubah',
    'Belum bisa hadir',
    'Sudah konsultasi di tempat lain',
    'Alasan pribadi',
    'Lainnya',
] as const;

type AlasanCepat = (typeof ALASAN_CEPAT)[number];

/**
 * The privacy warning that sits beside the free-text note, per F12 §9 and UU PDP 27/2022.
 */
const HINT_PRIVASI =
    'Jangan tuliskan detail kondisi medis atau keluhan — cukup alasan umum.';

/**
 * The patient's refund rows plus the query's own three states, passed down from the page
 * that owns the `GET /pasien/refund` read.
 *
 * The list is shared with the doctor-side screen, and `GET /pasien/refund` is
 * patient-scoped, so the doctor list passes nothing and renders no refund surface at all.
 */
export type RefundState = {
    byBookingId: Map<number, Refund>;
    loading: boolean;
    error: unknown;
    onRetry: () => void;
};

export function BookingList({
    filters,
    onPageChange,
    onFilterChange,
    tanggal,
    onTanggalChange,
    loading,
    error,
    meta,
    rows,
    doctorName,
    refundState,
    denganBannerOffline = false,
    onRetry,
    headerTitle,
    headerDescription,
    headerAction,
}: {
    filters: { page: number; per_page: number; status?: StatusBooking };
    onPageChange: (page: number) => void;
    onFilterChange: (status: StatusBooking | undefined) => void;
    /** Doctor list only. `null` on the patient list, which has no such filter. */
    tanggal: string | null;
    /** Omitted entirely on the patient list, which is what hides the date input. */
    onTanggalChange?: (value: string) => void;
    loading: boolean;
    error: unknown;
    meta: import('@/lib/http').ApiMeta | undefined;
    rows: Booking[];
    /** Doctor list only: a `dokter_id -> name` map, since the resource has no `dokter` key. */
    doctorName?: (booking: Booking) => string;
    /** Patient list only. See {@link RefundState}. */
    refundState?: RefundState;
    /**
     * Whether this list renders the shared offline banner itself.
     *
     * Defaults to `false` because the list is embedded in `booking-create-page`, whose
     * `BookingForm` already mounts an `OfflineBanner`; rendering another one put two
     * `[data-testid="offline-banner"]` elements on one screen. The two list pages pass
     * `true`; the create page does not.
     */
    denganBannerOffline?: boolean;
    onRetry: () => void;
    headerTitle: string;
    headerDescription: string;
    headerAction?: React.ReactNode;
}) {
    const [membatalkan, setMembatalkan] = useState<Booking | null>(null);
    const [dialogTerbuka, setDialogTerbuka] = useState(false);
    const [alasanCepat, setAlasanCepat] = useState<AlasanCepat | null>(null);
    const [catatanAlasan, setCatatanAlasan] = useState('');
    const [cancelError, setCancelError] = useState<unknown>(null);
    const [suksesBatal, setSuksesBatal] = useState(false);
    const [menjadwalkan, setMenjadwalkan] = useState<Booking | null>(null);
    const [dialogJadwalTerbuka, setDialogJadwalTerbuka] = useState(false);
    const [suksesJadwal, setSuksesJadwal] = useState<{
        lama: Booking;
        baru: Booking;
    } | null>(null);

    /**
     * The policy read is keyed by the booking whose dialog is open and is fetched lazily:
     * id `0` disables it, so nothing is requested until a patient opens a cancel dialog.
     * The confirm button is gated on this query's state rather than on a client-side
     * computation - see {@link KebijakanBlok}.
     */
    const kebijakan = useQuery(kebijakanBookingOptions(membatalkan?.id ?? 0));

    const kebijakanData = kebijakan.data?.data.kebijakan;
    const kebijakanSiap = kebijakanData !== undefined;

    /**
     * The synchronous double-submit latch.
     *
     * `batalkan.isPending` disables the button on the next render, but two clicks
     * dispatched inside one task (a double click, a trackpad, a scripted `.click()` pair)
     * both reach the handler before that render. The ref is set before `mutate()` is
     * called, so the second invocation returns immediately and exactly one
     * `PUT /booking/{id}/batalkan` leaves the client.
     */
    const mengirimRef = useRef(false);

    const online = useOnlineStatus();

    const batalkan = useMutation(batalkanBookingMutation());

    const range = describeRange(meta);

    const namaDokterTerpilih =
        membatalkan === null
            ? ''
            : (doctorName?.(membatalkan) ??
              `Dokter #${String(membatalkan.dokter_id)}`);

    function bukaDialog(row: Booking): void {
        setCancelError(null);
        setAlasanCepat(null);
        setCatatanAlasan('');
        setMembatalkan(row);
        setDialogTerbuka(true);
    }

    /**
     * The request body's `alasan_pembatalan`, or `null` when there is nothing to send.
     *
     * A chip fills the column directly; "Lainnya" with an empty note is NOT a reason, so it
     * falls through to no field at all. The server treats the field as `nullable|string|max:255`,
     * and an absent key is the `{}` the pattern requires when the patient skips.
     */
    function alasanDikirim(): string | null {
        if (alasanCepat === null) {
            return null;
        }

        if (alasanCepat === 'Lainnya') {
            const catatan = catatanAlasan.trim();

            return catatan === '' ? null : catatan.slice(0, 255);
        }

        return alasanCepat;
    }

    function konfirmasiBatal(): void {
        if (
            membatalkan === null ||
            !online ||
            !kebijakanSiap ||
            mengirimRef.current
        ) {
            return;
        }

        mengirimRef.current = true;

        const alasanFinal = alasanDikirim();

        batalkan.mutate(
            {
                id: membatalkan.id,
                input:
                    alasanFinal === null
                        ? {}
                        : { alasan_pembatalan: alasanFinal },
            },
            {
                /**
                 * No `dispatchFlash` here, and the reason is privacy rather than taste:
                 * the server's message names the booking, and the pattern's success
                 * confirmation is an inline card that a screen reader can re-read. Nothing
                 * about the doctor or the reason is put into a toast.
                 */
                onSuccess: () => {
                    mengirimRef.current = false;
                    setSuksesBatal(true);
                    setDialogTerbuka(false);
                },
                onError: (mutationError) => {
                    mengirimRef.current = false;
                    setCancelError(mutationError);
                },
            },
        );
    }

    return (
        <>
            <PageHeader
                title={headerTitle}
                description={headerDescription}
                action={headerAction}
            />

            {denganBannerOffline ? (
                <OfflineBanner message="Anda sedang offline. Pembatalan dan jadwal ulang memerlukan koneksi." />
            ) : null}

            <Card>
                <CardContent>
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field label="Status">
                            <FieldSelect
                                value={filters.status ?? 'semua'}
                                onValueChange={(value) => {
                                    onFilterChange(
                                        value === 'semua' ? undefined : (value as StatusBooking),
                                    );
                                }}
                            >
                                <SelectItem value="semua">Semua status</SelectItem>

                                {STATUS_BOOKING.map((row) => (
                                    <SelectItem key={row} value={row}>
                                        {labelStatusBooking(row)}
                                    </SelectItem>
                                ))}
                            </FieldSelect>
                        </Field>

                        {onTanggalChange === undefined ? null : (
                            <Field
                                label="Tanggal kunjungan"
                                hint="Dikirim sebagai Y-m-d. Kosongkan untuk semua tanggal."
                            >
                                <FieldInput
                                    type="date"
                                    value={tanggal ?? ''}
                                    onChange={(event) => {
                                        onTanggalChange(event.target.value);
                                    }}
                                />
                            </Field>
                        )}
                    </div>
                </CardContent>
            </Card>

            {suksesBatal ? (
                <div
                    role="status"
                    aria-live="polite"
                    data-slot="cancel-success"
                    className="border-success/40 bg-success/10 flex items-start gap-2 rounded-lg border p-3"
                >
                    <CheckCircle2
                        aria-hidden
                        className="text-success mt-0.5 size-4 shrink-0"
                    />

                    <div className="flex flex-col gap-0.5 text-sm">
                        <p className="font-medium">Janji temu dibatalkan.</p>

                        <p>
                            Slot ini sudah dilepas dan dapat dipesan orang lain.
                        </p>
                    </div>
                </div>
            ) : null}

            {suksesJadwal === null ? null : (
                <div
                    role="status"
                    aria-live="polite"
                    data-slot="reschedule-success"
                    className="border-success/40 bg-success/10 flex items-start gap-2 rounded-lg border p-3"
                >
                    <CheckCircle2
                        aria-hidden
                        className="text-success mt-0.5 size-4 shrink-0"
                    />

                    <div className="flex flex-col gap-0.5 text-sm">
                        <p className="font-medium">
                            Jadwal berhasil dipindahkan.
                        </p>

                        <p>
                            Jadwal lama{' '}
                            {formatTanggal(
                                suksesJadwal.lama.tanggal_kunjungan,
                            )}{' '}
                            pukul{' '}
                            {formatJamZona(
                                suksesJadwal.lama.slot_mulai,
                                suksesJadwal.lama.tanggal_kunjungan ?? '',
                            )}{' '}
                            menjadi{' '}
                            {formatTanggal(
                                suksesJadwal.baru.tanggal_kunjungan,
                            )}{' '}
                            pukul{' '}
                            {formatJamZona(
                                suksesJadwal.baru.slot_mulai,
                                suksesJadwal.baru.tanggal_kunjungan ?? '',
                            )}
                            .
                        </p>
                    </div>
                </div>
            )}

            {loading ? (
                <SkeletonRows rows={4} />
            ) : error !== null ? (
                /**
                 * A 403 here is a **capability refusal**, not a broken request.
                 * `GET /dokter/booking` carries `tipe:dokter`, and a patient account
                 * calling it gets 403 with Laravel's own `This action is unauthorized.` -
                 * measured. `ErrorState` withholds its retry button for a 403 precisely
                 * because no number of retries changes the answer, and the account-level
                 * copy says so rather than inviting one.
                 */
                error instanceof ApiError && error.isForbidden ? (
                    <ForbiddenState detail={error.message} />
                ) : (
                    <ErrorState
                        error={error}
                        onRetry={onRetry}
                    />
                )
            ) : isEmptyPage(meta, rows.length) ? (
                <EmptyState
                    title="Belum ada booking"
                    description="Daftar ini menampilkan booking pada akun ini. Booking pertama akan muncul di sini setelah berhasil dibuat."
                />
            ) : isPastLastPage(meta, rows.length) ? (
                <EmptyState
                    title="Halaman ini kosong"
                    description={`Halaman ${String(meta?.current_page ?? filters.page)} di luar jangkauan. Ada ${String(meta?.total ?? 0)} booking yang tersedia.`}
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                onPageChange(1);
                            }}
                        >
                            Kembali ke halaman pertama
                        </Button>
                    }
                />
            ) : (
                <>
                    {range === null ? null : (
                        <p className="text-muted-foreground text-sm">{range}</p>
                    )}

                    <ul data-slot="booking-rows" className="flex flex-col gap-3">
                        {rows.map((row) => (
                            <li key={row.id}>
                                <BookingRow
                                    row={row}
                                    namaDokter={doctorName?.(row)}
                                    canCancel={bisaDibatalkan(row.status)}
                                    canReschedule={bisaDijadwalkanUlang(
                                        row.status,
                                    )}
                                    deleting={batalkan.isPending}
                                    online={online}
                                    refund={refundState?.byBookingId.get(
                                        row.id,
                                    )}
                                    refundState={refundState}
                                    onCancel={() => {
                                        if (!online) {
                                            return;
                                        }

                                        bukaDialog(row);
                                    }}
                                    onReschedule={() => {
                                        if (!online) {
                                            return;
                                        }

                                        setSuksesJadwal(null);
                                        setMenjadwalkan(row);
                                        setDialogJadwalTerbuka(true);
                                    }}
                                />
                            </li>
                        ))}
                    </ul>

                    <Pagination meta={meta} onPageChange={onPageChange} />
                </>
            )}

            <Dialog
                open={dialogTerbuka}
                onOpenChange={(open) => {
                    setDialogTerbuka(open);
                }}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto">
                    {membatalkan === null ? null : (
                        <>
                            <DialogHeader>
                                <DialogTitle>Batalkan janji temu?</DialogTitle>

                                <DialogDescription className="text-base">
                                    Janji temu {membatalkan.nomor_booking} dengan{' '}
                                    {namaDokterTerpilih} pada{' '}
                                    {formatTanggal(
                                        membatalkan.tanggal_kunjungan,
                                    )}{' '}
                                    pukul{' '}
                                    {formatJamZona(
                                        membatalkan.slot_mulai,
                                        membatalkan.tanggal_kunjungan ?? '',
                                    )}{' '}
                                    akan dibatalkan.
                                </DialogDescription>
                            </DialogHeader>

                            <ul
                                data-slot="cancel-consequences"
                                className="flex flex-col gap-2"
                            >
                                <li className="flex items-start gap-2 text-sm">
                                    <CalendarClock
                                        aria-hidden
                                        className="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    />

                                    <span>
                                        Slot ini akan dilepas dan dapat dipesan
                                        orang lain.
                                    </span>
                                </li>

                                <li className="flex items-start gap-2 text-sm">
                                    <Ban
                                        aria-hidden
                                        className="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    />

                                    <span>
                                        Setelah dikonfirmasi, pembatalan tidak
                                        dapat dipulihkan.
                                    </span>
                                </li>

                                <li className="flex items-start gap-2 text-sm">
                                    <Bell
                                        aria-hidden
                                        className="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    />

                                    <span>
                                        {namaDokterTerpilih} akan menerima
                                        pemberitahuan beserta alasan Anda.
                                    </span>
                                </li>
                            </ul>

                            {online ? null : (
                                <p className="text-muted-foreground flex items-start gap-2 text-sm">
                                    <WifiOff
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0"
                                    />

                                    Anda sedang offline. Pembatalan memerlukan
                                    koneksi internet.
                                </p>
                            )}

                            <div data-slot="cancel-policy">
                                {kebijakan.isPending ? (
                                    <div data-slot="cancel-policy-skeleton">
                                        <SkeletonRows rows={2} />
                                    </div>
                                ) : kebijakanData === undefined ? (
                                    <Alert
                                        variant="destructive"
                                        data-slot="cancel-policy-error"
                                    >
                                        <AlertCircle />

                                        <AlertTitle className="text-foreground">
                                            Kebijakan pembatalan tidak dapat
                                            dimuat
                                        </AlertTitle>

                                        <AlertDescription>
                                            <p>
                                                Rincian kebijakan belum dapat
                                                dimuat. Coba lagi.
                                            </p>

                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="mt-2 min-h-11"
                                                onClick={() => {
                                                    void kebijakan.refetch();
                                                }}
                                            >
                                                Coba lagi
                                            </Button>
                                        </AlertDescription>
                                    </Alert>
                                ) : (
                                    <KebijakanBlok kebijakan={kebijakanData} />
                                )}
                            </div>

                            <Separator />

                            <div
                                data-slot="cancel-reason"
                                className="flex flex-col gap-2"
                            >
                                <p className="text-sm font-medium">
                                    Alasan pembatalan{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (opsional)
                                    </span>
                                </p>

                                <ToggleGroup
                                    type="single"
                                    value={alasanCepat ?? ''}
                                    onValueChange={(value) => {
                                        setAlasanCepat(
                                            value === ''
                                                ? null
                                                : (value as AlasanCepat),
                                        );
                                    }}
                                    aria-label="Pilihan cepat alasan pembatalan"
                                    data-slot="cancel-reason-chips"
                                    className="flex flex-wrap gap-2"
                                >
                                    {ALASAN_CEPAT.map((pilihan) => (
                                        <ToggleGroupItem
                                            key={pilihan}
                                            value={pilihan}
                                            className="border-input min-h-11 rounded-md border px-3"
                                        >
                                            {alasanCepat === pilihan ? (
                                                <Check aria-hidden />
                                            ) : null}

                                            {pilihan}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>

                                {alasanCepat === 'Lainnya' ? (
                                    <Field
                                        label="Catatan alasan"
                                        hint={HINT_PRIVASI}
                                        errors={
                                            cancelError instanceof ApiError
                                                ? cancelError.fieldErrors(
                                                      'alasan_pembatalan',
                                                  )
                                                : []
                                        }
                                    >
                                        <FieldTextarea
                                            rows={3}
                                            maxLength={255}
                                            value={catatanAlasan}
                                            placeholder="Contoh: jadwal saya berubah."
                                            onChange={(event) => {
                                                setCatatanAlasan(
                                                    event.target.value,
                                                );
                                            }}
                                        />
                                    </Field>
                                ) : (
                                    <p className="text-muted-foreground text-xs">
                                        {HINT_PRIVASI}
                                    </p>
                                )}
                            </div>

                            {cancelError === null ? null : (
                                <CancelErrorNotice
                                    error={cancelError}
                                    onReload={onRetry}
                                />
                            )}

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => {
                                        setDialogTerbuka(false);
                                    }}
                                >
                                    Batal
                                </Button>

                                <Button
                                    type="button"
                                    variant="destructive"
                                    className="min-h-11"
                                    data-slot="confirm-cancel"
                                    disabled={!kebijakanSiap}
                                    aria-disabled={
                                        !online ||
                                        batalkan.isPending ||
                                        !kebijakanSiap ||
                                        undefined
                                    }
                                    onClick={konfirmasiBatal}
                                >
                                    {batalkan.isPending ? (
                                        <Spinner />
                                    ) : (
                                        <XCircle />
                                    )}

                                    Ya, batalkan janji temu
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <RescheduleDialog
                booking={menjadwalkan}
                namaDokter={
                    menjadwalkan === null
                        ? ''
                        : (doctorName?.(menjadwalkan) ??
                          `Dokter #${String(menjadwalkan.dokter_id)}`)
                }
                open={dialogJadwalTerbuka}
                onOpenChange={setDialogJadwalTerbuka}
                online={online}
                onSuccess={(lama, baru) => {
                    setSuksesJadwal({ lama, baru });
                }}
                onReload={onRetry}
            />
        </>
    );
}

function CancelErrorNotice({
    error,
    onReload,
}: {
    error: unknown;
    onReload: () => void;
}) {
    if (!(error instanceof ApiError)) {
        return (
            <Alert variant="destructive" data-slot="cancel-error">
                <AlertCircle />

                <AlertTitle>Pembatalan gagal</AlertTitle>

                <AlertDescription>
                    <p>
                        Tidak dapat membatalkan janji temu. Periksa koneksi lalu
                        coba lagi.
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    if (error.fieldErrors('status').length > 0) {
        return (
            <Alert variant="destructive" data-slot="cancel-error">
                <AlertCircle />

                <AlertTitle>Janji ini tidak dapat dibatalkan</AlertTitle>

                <AlertDescription>
                    <p>
                        Janji dengan status ini tidak dapat dibatalkan. Muat
                        ulang halaman untuk melihat status terbaru.
                    </p>

                    <Button
                        type="button"
                        variant="outline"
                        className="mt-2 min-h-11"
                        onClick={onReload}
                    >
                        Muat ulang
                    </Button>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Alert variant="destructive" data-slot="cancel-error">
            <AlertCircle />

            <AlertTitle>Pembatalan gagal</AlertTitle>

            <AlertDescription>
                <p>{error.message}</p>
            </AlertDescription>
        </Alert>
    );
}

/**
 * The server's cancellation policy, rendered as natural language.
 *
 * The client decides two things only: which sentence the server's own `tujuan` calls for,
 * and how to format its money. `gratis`, `biaya`, `jumlah_refund`, `tujuan` and `sla` all
 * come off the wire, so a future policy change reaches the dialog without a client release.
 * `sla` is `null` today; when it is not, it is a server string and is rendered verbatim
 * rather than converted into a date this client would have to compute.
 */
function KebijakanBlok({ kebijakan }: { kebijakan: KebijakanPembatalan }) {
    return (
        <div
            data-slot="cancel-policy-detail"
            className="bg-muted/40 flex flex-col gap-1 rounded-lg border p-3 text-sm"
        >
            <p>
                {kebijakan.gratis ? (
                    <>
                        Pembatalan ini <strong>gratis</strong>.
                    </>
                ) : (
                    <>
                        Pembatalan ini dikenakan biaya{' '}
                        <strong className="tabular-nums">
                            {formatRupiah(kebijakan.biaya)}
                        </strong>
                        .
                    </>
                )}
            </p>

            {kebijakan.tujuan === null ? (
                <p>Tidak ada dana yang perlu dikembalikan.</p>
            ) : (
                <p>
                    Dana yang kembali{' '}
                    <strong className="tabular-nums">
                        {formatRupiah(kebijakan.jumlah_refund)}
                    </strong>{' '}
                    ke <strong>{kebijakan.tujuan.label}</strong>.
                </p>
            )}

            {kebijakan.sla === null ? null : (
                <p>Dana dikembalikan dalam {kebijakan.sla}.</p>
            )}
        </div>
    );
}

function BookingRow({
    row,
    namaDokter,
    canCancel,
    canReschedule,
    deleting,
    online,
    refund,
    refundState,
    onCancel,
    onReschedule,
}: {
    row: Booking;
    namaDokter: string | undefined;
    canCancel: boolean;
    canReschedule: boolean;
    deleting: boolean;
    online: boolean;
    refund: Refund | undefined;
    refundState: RefundState | undefined;
    onCancel: () => void;
    onReschedule: () => void;
}) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <p className="font-medium font-mono text-sm">
                            {row.nomor_booking}
                        </p>

                        <p className="text-muted-foreground text-sm">
                            {formatTanggal(row.tanggal_kunjungan)} •{' '}
                            {formatRentangJamZona(
                                row.slot_mulai,
                                row.slot_selesai,
                                row.tanggal_kunjungan ?? '',
                            )}
                        </p>
                    </div>

                    <BookingStatusBadge status={row.status} />
                </div>

                <Separator />

                <dl className="grid gap-3 sm:grid-cols-3">
                    <Cell
                        label="Tipe layanan"
                        value={labelTipeLayanan(row.tipe_layanan)}
                    />

                    <Cell
                        label="Dokter"
                        value={namaDokter ?? `Dokter #${String(row.dokter_id)}`}
                    />

                    {/**
                     * `pasien` is published by `whenLoaded()`, and only the doctor-side
                     * list eager-loads it. A patient reading their own bookings gets no
                     * such key at all, which is why the cell falls back to a dash rather
                     * than to a name that would have to be looked up.
                     */}
                    <Cell
                        label="Pasien"
                        value={row.pasien?.nama_lengkap ?? '-'}
                    />
                </dl>

                {row.pasien === undefined ? null : (
                    <p className="text-muted-foreground text-xs">
                        NIK {row.pasien.nik ?? '-'} (dimasking oleh server)
                    </p>
                )}

                {row.keluhan === null || row.keluhan === '' ? null : (
                    <p className="text-sm">{row.keluhan}</p>
                )}

                {row.lampiran_keluhan === null ||
                row.lampiran_keluhan.length === 0 ? null : (
                    <ul className="flex flex-col gap-1">
                        {row.lampiran_keluhan.map((item, index) => (
                            <li
                                key={`${item.nama ?? 'lampiran'}-${String(index)}`}
                                className="text-muted-foreground text-xs"
                            >
                                {item.nama ?? '(tanpa nama)'}:{' '}
                                {item.url ?? '(tanpa URL)'}
                            </li>
                        ))}
                    </ul>
                )}

                {row.is_rujukan ? (
                    <p className="text-muted-foreground text-xs">
                        Booking rujukan.
                    </p>
                ) : null}

                {row.is_konsultasi_lanjutan ? (
                    <p className="text-muted-foreground text-xs">
                        Konsultasi lanjutan.
                    </p>
                ) : null}

                <BookingStatusNote
                    status={row.status}
                    dibatalkanOleh={row.dibatalkan_oleh}
                    alasan={row.alasan_pembatalan}
                />

                <p className="text-muted-foreground text-xs">
                    Dibuat {formatWaktu(row.dibuat_at)}
                </p>

                {/**
                 * `bisaDibatalkan` is the F12 UI guard - `menunggu_pembayaran` and
                 * `terjadwal` only - which is deliberately narrower than the server's own
                 * `STATUS_TIDAK_BISA_DIBATALKAN` (see `lib/api/booking.ts`). A status
                 * outside it gets a sentence, never a dead button, and the server still
                 * re-checks inside the transaction so a stale client cannot force a write.
                 */}
                {canCancel ? (
                    <div className="flex flex-wrap items-center gap-2">
                        {canReschedule ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                data-slot="reschedule-booking"
                                aria-disabled={!online || undefined}
                                onClick={() => {
                                    if (!online) {
                                        return;
                                    }

                                    onReschedule();
                                }}
                            >
                                <CalendarClock />

                                Jadwal ulang
                            </Button>
                        ) : null}

                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            data-slot="cancel-booking"
                            disabled={deleting}
                            aria-disabled={!online || deleting || undefined}
                            onClick={onCancel}
                        >
                            <XCircle />

                            Batalkan
                        </Button>
                    </div>
                ) : (
                    <div className="flex flex-col items-start gap-2">
                        <p
                            className="text-muted-foreground text-xs"
                            data-slot="cancel-blocked-note"
                        >
                            {penjelasanTidakBisaDibatalkan(row.status)}
                        </p>

                        {refund === undefined ? null : (
                            <RefundLink bookingId={row.id} />
                        )}
                    </div>
                )}

                {refund !== undefined ? (
                    <RefundCard refund={refund} />
                ) : row.status === 'dibatalkan' && refundState !== undefined ? (
                    refundState.loading ? (
                        <div data-slot="refund-loading">
                            <SkeletonRows rows={1} />
                        </div>
                    ) : refundState.error != null ? (
                        <p
                            data-slot="refund-unavailable"
                            className="text-muted-foreground text-xs"
                        >
                            Status pengembalian dana belum tersedia.{' '}
                            <button
                                type="button"
                                className="focus-visible:ring-ring rounded-md underline underline-offset-4 focus-visible:ring-2 focus-visible:outline-none"
                                onClick={refundState.onRetry}
                            >
                                Coba lagi
                            </button>
                        </p>
                    ) : null
                ) : null}
            </CardContent>
        </Card>
    );
}

function RefundLink({ bookingId }: { bookingId: number }) {
    return (
        <a
            href={`#refund-${String(bookingId)}`}
            data-slot="refund-link"
            className="focus-visible:ring-ring inline-flex min-h-11 items-center gap-1.5 rounded-md text-sm font-medium underline underline-offset-4 focus-visible:ring-2 focus-visible:outline-none"
        >
            <Wallet aria-hidden className="size-4" />

            Lihat pengembalian dana
        </a>
    );
}

function penjelasanTidakBisaDibatalkan(status: StatusBooking): string {
    switch (status) {
        case 'check_in':
            return 'Anda sudah check in. Hubungi klinik bila perlu membatalkan.';
        case 'berlangsung':
            return 'Konsultasi sedang berlangsung sehingga janji tidak dapat dibatalkan.';
        case 'selesai':
            return 'Konsultasi sudah selesai sehingga janji tidak dapat dibatalkan.';
        case 'dibatalkan':
            return 'Janji temu ini sudah dibatalkan.';
        case 'kadaluarsa':
            return 'Janji temu ini sudah kedaluwarsa.';
        case 'no_show':
            return 'Janji ini ditandai tidak hadir sehingga tidak dapat dibatalkan.';
        default:
            return 'Janji dengan status ini tidak dapat dibatalkan.';
    }
}

function Cell({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>

            <dd className="text-sm font-medium">{value}</dd>
        </div>
    );
}
