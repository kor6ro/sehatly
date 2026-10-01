import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { XCircle } from 'lucide-react';
import {
    batalkanBookingMutation,
    bisaDibatalkan,
    labelStatusBooking,
    labelTipeLayanan,
    STATUS_BOOKING,
} from '@/lib/api/booking';
import { BookingStatusBadge, BookingStatusNote } from '@/features/booking/booking-status-badge';
import { describeRange, isEmptyPage, isPastLastPage } from '@/lib/api/pagination';
import { ApiError } from '@/lib/http';
import { formatTanggal, formatWaktu } from '@/lib/format';
import { formatRentangJamZona } from '@/lib/waktu';
import type { Booking, StatusBooking } from '@/lib/api/types';
import { dispatchFlash } from '@/lib/flash';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Field, FieldInput, FieldSelect } from '@/components/form/field';
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
 */
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
    onRetry: () => void;
    headerTitle: string;
    headerDescription: string;
    headerAction?: React.ReactNode;
}) {
    const [membatalkan, setMembatalkan] = useState<Booking | null>(null);
    const [alasan, setAlasan] = useState('');
    const [cancelError, setCancelError] = useState<unknown>(null);

    const batalkan = useMutation(batalkanBookingMutation());

    const range = describeRange(meta);

    return (
        <>
            <PageHeader
                title={headerTitle}
                description={headerDescription}
                action={headerAction}
            />

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
                    deleting={batalkan.isPending}
                                    onCancel={() => {
                                        setCancelError(null);
                                        setAlasan('');
                                        setMembatalkan(row);
                                    }}
                                />
                            </li>
                        ))}
                    </ul>

                    <Pagination meta={meta} onPageChange={onPageChange} />
                </>
            )}

            <Dialog
                open={membatalkan !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setMembatalkan(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Batalkan booking</DialogTitle>

                        <DialogDescription>
                            Booking {membatalkan?.nomor_booking ?? ''} akan
                            berstatus `dibatalkan` dan slotnya dilepas agar dapat
                            dipesan kembali. Tindakan ini dicatat pada server
                            dengan nama akun Anda.
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        label="Alasan pembatalan"
                        errors={
                            cancelError instanceof ApiError
                                ? cancelError.fieldErrors('alasan_pembatalan')
                                : []
                        }
                        hint="Opsional, maksimal 255 karakter."
                    >
                        <FieldInput
                            value={alasan}
                            maxLength={255}
                            placeholder="Contoh: jadwal saya berubah."
                            onChange={(event) => {
                                setAlasan(event.target.value);
                            }}
                        />
                    </Field>

                    {cancelError instanceof ApiError ? (
                        <p className="text-destructive text-sm">
                            {cancelError.message}
                        </p>
                    ) : null}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setMembatalkan(null);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            disabled={batalkan.isPending}
                            onClick={() => {
                                if (membatalkan === null) {
                                    return;
                                }

                                batalkan.mutate(
                                    {
                                        id: membatalkan.id,
                                        input:
                                            alasan.trim() === ''
                                                ? {}
                                                : {
                                                      alasan_pembatalan:
                                                          alasan.trim(),
                                                  },
                                    },
                                    {
                                        onSuccess: (result) => {
                                            setMembatalkan(null);

                                            dispatchFlash({
                                                level: 'success',
                                                message: result.message,
                                            });
                                        },
                                        onError: (error) => {
                                            /**
                                             * Left open on failure, and the error shown
                                             * inline. A 422 on `status` - the server's
                                             * `Booking dengan status tersebut tidak
                                             * dapat dibatalkan.` - means the booking
                                             * ended between the render and the click,
                                             * and closing the dialog would throw that
                                             * away.
                                             */
                                            setCancelError(error);
                                        },
                                    },
                                );
                            }}
                        >
                            {batalkan.isPending ? (
                                <Spinner />
                            ) : (
                                <XCircle />
                            )}

                            Batalkan booking
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function BookingRow({
    row,
    namaDokter,
    canCancel,
    deleting,
    onCancel,
}: {
    row: Booking;
    namaDokter: string | undefined;
    canCancel: boolean;
    deleting: boolean;
    onCancel: () => void;
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
                        value={namaDokter ?? `Dokter #${row.dokter_id}`}
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
                 * `STATUS_TIDAK_BISA_DIBATALKAN` is the server's own guard, reproduced
                 * verbatim: `berlangsung`, `selesai`, `dibatalkan`, `kadaluarsa`.
                 * Hiding the button is a courtesy - the service re-checks the status
                 * inside the transaction, so a stale client cannot force it - and a 422
                 * is still rendered inline if it happens.
                 */}
                {canCancel ? (
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={deleting}
                            onClick={onCancel}
                        >
                            <XCircle />

                            Batalkan
                        </Button>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-xs">
                        Status `{'{'}{row.status}{'}'}` tidak dapat dibatalkan.
                    </p>
                )}
            </CardContent>
        </Card>
    );
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
