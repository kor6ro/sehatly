import { useRef, useState, type KeyboardEvent, type RefObject } from 'react';
import { Link, useNavigate } from 'react-router';
import { CalendarDays, Clock } from 'lucide-react';
import { labelTipeLayanan } from '@/lib/api/booking';
import { describeRange, isEmptyPage, isPastLastPage } from '@/lib/api/pagination';
import { ApiError, type ApiMeta } from '@/lib/http';
import { formatRentangJamZona } from '@/lib/waktu';
import type { Booking, KonsultasiDaftar } from '@/lib/api/types';
import { BookingStatusBadge } from '@/features/booking/booking-status-badge';
import { Pagination } from '@/components/layout/pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { SkeletonRows } from '@/components/states/loading-state';

export type Kepadatan = 'ringkas' | 'lengkap';

export type RentangAntrean = 'hari_ini' | 'besok' | 'semua';

/**
 * "Antrean hari ini": the doctor's own bookings for the selected day, with the
 * consultations that already exist for them.
 *
 * `BookingResource` publishes no `konsultasi_id`, and there is no doctor-side
 * mapping endpoint, so the link to a consultation is resolved by matching the
 * consultation list's `booking.id` against the booking row. A booking whose
 * consultation does not exist yet therefore has no "Buka konsultasi" action at all -
 * the UI never invents an id (`_global.md` §1.4).
 *
 * The complaint is medical text: it renders at `text-base`, wraps fully, and is the
 * only thing the "Ringkas" density removes. `line-clamp`/`truncate` must never be
 * added to it, which is why it is not rendered through a clamped summary line.
 */
export function AntreanDokter({
    rows,
    meta,
    loading,
    error,
    onRetry,
    onPageChange,
    halaman,
    rentang,
    onRentangChange,
    tanggalLabel,
    kepadatan,
    onKepadatanChange,
    konsultasiByBooking,
}: {
    rows: Booking[];
    meta: ApiMeta | undefined;
    loading: boolean;
    error: unknown;
    onRetry: () => void;
    onPageChange: (page: number) => void;
    halaman: number;
    rentang: RentangAntrean;
    onRentangChange: (rentang: RentangAntrean) => void;
    tanggalLabel: string;
    kepadatan: Kepadatan;
    onKepadatanChange: (kepadatan: Kepadatan) => void;
    konsultasiByBooking: Map<number, KonsultasiDaftar>;
}) {
    const navigate = useNavigate();
    const refs = useRef<Array<HTMLLIElement | null>>([]);
    const [barisFokus, setBarisFokus] = useState(0);

    const fokusKe = (index: number): void => {
        const berikut = Math.max(0, Math.min(rows.length - 1, index));

        setBarisFokus(berikut);
        refs.current[berikut]?.focus();
    };

    const padaTombolBaris = (event: KeyboardEvent<HTMLLIElement>, index: number): void => {
        if (event.target !== event.currentTarget) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            fokusKe(index + 1);

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            fokusKe(index - 1);

            return;
        }

        if (event.key === 'Enter') {
            const tujuan = tujuanBaris(rows[index], konsultasiByBooking);

            if (tujuan !== null) {
                event.preventDefault();
                void navigate(tujuan);
            }
        }
    };

    return (
        <section aria-label="Antrean hari ini" className="flex flex-col gap-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h2 className="text-lg font-semibold">Antrean hari ini</h2>

                    <p className="text-muted-foreground text-sm">{tanggalLabel}</p>
                </div>

                <Button asChild variant="link" className="h-11">
                    <Link to="/dokter/booking">
                        <CalendarDays aria-hidden />

                        Lihat semua booking
                    </Link>
                </Button>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div
                    role="group"
                    aria-label="Rentang tanggal antrean"
                    className="flex items-center gap-1"
                >
                    <TombolPilihan
                        aktif={rentang === 'hari_ini'}
                        label="Hari ini"
                        onClick={() => {
                            onRentangChange('hari_ini');
                        }}
                    />

                    <TombolPilihan
                        aktif={rentang === 'besok'}
                        label="Besok"
                        onClick={() => {
                            onRentangChange('besok');
                        }}
                    />

                    <TombolPilihan
                        aktif={rentang === 'semua'}
                        label="Semua"
                        onClick={() => {
                            onRentangChange('semua');
                        }}
                    />
                </div>

                <div
                    role="group"
                    aria-label="Kepadatan tampilan"
                    className="flex items-center gap-1"
                >
                    <TombolPilihan
                        aktif={kepadatan === 'ringkas'}
                        label="Ringkas"
                        onClick={() => {
                            onKepadatanChange('ringkas');
                        }}
                    />

                    <TombolPilihan
                        aktif={kepadatan === 'lengkap'}
                        label="Lengkap"
                        onClick={() => {
                            onKepadatanChange('lengkap');
                        }}
                    />
                </div>
            </div>

            {loading ? (
                <SkeletonRows rows={4} />
            ) : error !== null ? (
                error instanceof ApiError && error.isForbidden ? (
                    <ForbiddenState detail="Daftar booking dokter hanya tersedia untuk akun dokter." />
                ) : (
                    <ErrorState error={error} title="Gagal memuat antrean." onRetry={onRetry} />
                )
            ) : rows.length === 0 && isEmptyPage(meta, rows.length) ? (
                <EmptyState
                    title={
                        rentang === 'semua'
                            ? 'Belum ada booking.'
                            : `Belum ada booking pada ${tanggalLabel}.`
                    }
                    description="Antrean menampilkan janji temu pada akun dokter ini. Jadwal besok dapat dilihat tanpa menunggu."
                    action={
                        rentang === 'besok' ? undefined : (
                            <Button
                                type="button"
                                variant="outline"
                                className="h-11"
                                data-testid="f13-aksi"
                                onClick={() => {
                                    onRentangChange('besok');
                                }}
                            >
                                Lihat jadwal besok
                            </Button>
                        )
                    }
                />
            ) : isPastLastPage(meta, rows.length) ? (
                <EmptyState
                    title="Halaman ini kosong"
                    description={`Halaman ${String(meta?.current_page ?? halaman)} di luar jangkauan. Ada ${String(meta?.total ?? 0)} booking yang tersedia.`}
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
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
                    {describeRange(meta) === null ? null : (
                        <p className="text-muted-foreground text-sm">{describeRange(meta)}</p>
                    )}

                    <ul data-slot="f13-antrean" className="flex flex-col gap-3">
                        {rows.map((row, index) => (
                            <BarisAntrean
                                key={row.id}
                                row={row}
                                index={index}
                                terpilih={index === barisFokus}
                                refs={refs}
                                kepadatan={kepadatan}
                                konsultasi={konsultasiByBooking.get(row.id)}
                                onFokus={setBarisFokus}
                                onKeyDown={padaTombolBaris}
                            />
                        ))}
                    </ul>

                    <Pagination meta={meta} onPageChange={onPageChange} />
                </>
            )}
        </section>
    );
}

export function tujuanBaris(
    row: Booking | undefined,
    konsultasiByBooking: Map<number, KonsultasiDaftar>,
): string | null {
    if (row === undefined) {
        return null;
    }

    const konsultasi = konsultasiByBooking.get(row.id);

    return konsultasi === undefined ? null : `/konsultasi/${konsultasi.id}`;
}

function BarisAntrean({
    row,
    index,
    terpilih,
    refs,
    kepadatan,
    konsultasi,
    onFokus,
    onKeyDown,
}: {
    row: Booking;
    index: number;
    terpilih: boolean;
    refs: RefObject<Array<HTMLLIElement | null>>;
    kepadatan: Kepadatan;
    konsultasi: KonsultasiDaftar | undefined;
    onFokus: (index: number) => void;
    onKeyDown: (event: KeyboardEvent<HTMLLIElement>, index: number) => void;
}) {
    const tanggal = row.tanggal_kunjungan ?? new Date().toISOString().slice(0, 10);

    return (
        <li
            data-slot="f13-baris-antrean"
            data-booking-id={row.id}
            data-terpilih={terpilih ? 'true' : 'false'}
            ref={(element) => {
                refs.current[index] = element;
            }}
            tabIndex={terpilih ? 0 : -1}
            onFocus={() => {
                onFokus(index);
            }}
            onKeyDown={(event) => {
                onKeyDown(event, index);
            }}
            className="rounded-lg outline-none focus-visible:ring-ring/50 focus-visible:ring-[3px]"
        >
            <Card>
                <CardContent className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex flex-col gap-1">
                            <p className="font-medium">
                                {row.pasien?.nama_lengkap ?? 'Pasien'}
                            </p>

                            <p className="text-muted-foreground flex flex-wrap items-center gap-2 text-sm">
                                <Clock aria-hidden className="size-4" />

                                {formatRentangJamZona(
                                    row.slot_mulai,
                                    row.slot_selesai,
                                    tanggal,
                                )}

                                {' • '}

                                {labelTipeLayanan(row.tipe_layanan)}
                            </p>

                            <p className="text-muted-foreground font-mono text-xs">
                                {row.nomor_booking}
                            </p>
                        </div>

                        <BookingStatusBadge status={row.status} />
                    </div>

                    {kepadatan === 'lengkap' &&
                    row.keluhan !== null &&
                    row.keluhan !== '' ? (
                        <p
                            data-slot="f13-keluhan"
                            className="max-w-[70ch] text-base break-words"
                        >
                            {row.keluhan}
                        </p>
                    ) : null}

                    {konsultasi === undefined ? (
                        <p className="text-muted-foreground text-sm">
                            Konsultasi belum dimulai pasien.
                        </p>
                    ) : (
                        <div>
                            <Button
                                asChild
                                variant="outline"
                                data-testid="f13-aksi"
                                className="h-11"
                            >
                                <Link to={`/konsultasi/${konsultasi.id}`}>
                                    Buka konsultasi
                                </Link>
                            </Button>
                        </div>
                    )}
                </CardContent>
            </Card>
        </li>
    );
}

function TombolPilihan({
    aktif,
    label,
    onClick,
}: {
    aktif: boolean;
    label: string;
    onClick: () => void;
}) {
    return (
        <Button
            type="button"
            variant={aktif ? 'secondary' : 'outline'}
            aria-pressed={aktif}
            data-testid="f13-aksi"
            className="h-11"
            onClick={onClick}
        >
            {label}
        </Button>
    );
}
