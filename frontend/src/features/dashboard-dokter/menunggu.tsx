import { useState } from 'react';
import { Link } from 'react-router';
import { useMutation } from '@tanstack/react-query';
import { Check, Hourglass, Loader2, Pill } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import {
    labelStatusKonsultasiDasbor,
    terimaKonsultasiMutation,
    TIPE_KONSULTASI_LABEL,
} from '@/lib/api/konsultasi';
import { formatRentangJamZona, formatWaktuZona } from '@/lib/waktu';
import type { KonsultasiDaftar } from '@/lib/api/types';
import { KonsultasiStatusBadge } from '@/features/konsultasi/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/states/error-state';
import { SkeletonRows } from '@/components/states/loading-state';

/**
 * The "Menunggu diterima" strip and the "Konsultasi berlangsung" list.
 *
 * Both read the same `GET /konsultasi` page: the strip is the
 * `menunggu_dokter` rank, which the server orders first, and the second list is
 * `berlangsung` + `menunggu_resep`. Accepting a consultation moves its row between
 * the two on the refetch the mutation's invalidation triggers, which is why the
 * strip carries only the accept action and the active list carries the record and
 * prescription links.
 */
export function StripMenunggu({
    rows,
    loading,
    error,
    onRetry,
    online,
    onKonflik,
}: {
    rows: KonsultasiDaftar[];
    loading: boolean;
    error: unknown;
    onRetry: () => void;
    online: boolean;
    onKonflik: () => void;
}) {
    const [pengumuman, setPengumuman] = useState('');

    return (
        <section aria-label="Konsultasi menunggu diterima">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Hourglass aria-hidden />

                        Menunggu diterima ({rows.length})
                    </CardTitle>

                    <CardDescription>
                        Konsultasi yang belum dimulai. Satu ketukan "Terima" menandai
                        konsultasi berlangsung.
                    </CardDescription>
                </CardHeader>

                <CardContent className="flex flex-col gap-3">
                    <p role="status" aria-live="polite" className="sr-only">
                        {pengumuman}
                    </p>

                    {loading ? (
                        <SkeletonRows rows={2} />
                    ) : error !== null ? (
                        <ErrorState
                            error={error}
                            title="Gagal memuat konsultasi."
                            onRetry={onRetry}
                        />
                    ) : rows.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Tidak ada konsultasi yang menunggu.
                        </p>
                    ) : (
                        <ul data-slot="f13-menunggu" className="flex flex-col gap-3">
                            {rows.map((row) => (
                                <BarisMenunggu
                                    key={row.id}
                                    row={row}
                                    online={online}
                                    onKonflik={onKonflik}
                                    onDiterima={() => {
                                        setPengumuman('Konsultasi diterima.');
                                    }}
                                />
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

function BarisMenunggu({
    row,
    online,
    onKonflik,
    onDiterima,
}: {
    row: KonsultasiDaftar;
    online: boolean;
    onKonflik: () => void;
    onDiterima: () => void;
}) {
    const terima = useMutation(terimaKonsultasiMutation(row.id));

    const kirim = (): void => {
        terima.mutate(undefined, {
            onSuccess: () => {
                dispatchFlash({ level: 'success', message: 'Konsultasi diterima.' });

                onDiterima();
            },
            onError: (error) => {
                /**
                 * A 422 here is the two-device race: the server's state machine has
                 * already left `menunggu_dokter`, so the row on this screen is stale.
                 * The parent refetches the list rather than this row retrying.
                 */
                if (error instanceof ApiError && error.isValidation) {
                    onKonflik();

                    return;
                }

                dispatchFlash({
                    level: 'error',
                    message:
                        error instanceof ApiError
                            ? error.message
                            : 'Konsultasi gagal diterima.',
                });
            },
        });
    };

    return (
        <li
            data-slot="f13-baris-menunggu"
            data-konsultasi-id={row.id}
            className="flex flex-col gap-3 rounded-md border p-3"
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex flex-col gap-1">
                    <p className="font-medium">{row.pasien?.nama_lengkap ?? 'Pasien'}</p>

                    <p className="text-muted-foreground text-sm">
                        {waktuKonsultasi(row)}
                    </p>
                </div>

                <KonsultasiStatusBadge
                    status={row.status}
                    label={labelStatusKonsultasiDasbor(row.status)}
                />
            </div>

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    data-testid="f13-aksi"
                    className="h-11"
                    disabled={terima.isPending || !online}
                    onClick={kirim}
                >
                    {terima.isPending ? (
                        <Loader2 className="animate-spin" aria-hidden />
                    ) : (
                        <Check aria-hidden />
                    )}

                    Terima
                </Button>

                <Button asChild variant="outline" data-testid="f13-aksi" className="h-11">
                    <Link to={`/konsultasi/${row.id}`}>Buka</Link>
                </Button>
            </div>
        </li>
    );
}

/**
 * The consultations that are already running, and the two links a doctor needs on
 * them. `menunggu_resep` is included because the record and the prescription are
 * exactly what that state is waiting for. The strip owns the loading and error
 * states of the shared list, so this section renders only when rows exist.
 */
export function DaftarKonsultasiAktif({ rows }: { rows: KonsultasiDaftar[] }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <section aria-label="Konsultasi berlangsung">
            <Card data-slot="f13-aktif">
                <CardHeader>
                    <CardTitle>Konsultasi berlangsung ({rows.length})</CardTitle>
                </CardHeader>

                <CardContent className="flex flex-col gap-3">
                    <ul className="flex flex-col gap-3">
                        {rows.map((row) => (
                            <li
                                key={row.id}
                                data-slot="f13-baris-aktif"
                                className="flex flex-col gap-3 rounded-md border p-3"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="flex flex-col gap-1">
                                        <p className="font-medium">
                                            {row.pasien?.nama_lengkap ?? 'Pasien'}
                                        </p>

                                        <p className="text-muted-foreground text-sm">
                                            {waktuKonsultasi(row)}
                                        </p>
                                    </div>

                                    <KonsultasiStatusBadge status={row.status} />
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        asChild
                                        variant="outline"
                                        data-testid="f13-aksi"
                                        className="h-11"
                                    >
                                        <Link to={`/konsultasi/${row.id}`}>
                                            Buka konsultasi
                                        </Link>
                                    </Button>

                                    <Button
                                        asChild
                                        data-testid="f13-aksi"
                                        className="h-11"
                                    >
                                        <Link to={`/konsultasi/${row.id}/resep`}>
                                            <Pill aria-hidden />

                                            Tulis resep
                                        </Link>
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </CardContent>
            </Card>
        </section>
    );
}

function waktuKonsultasi(row: KonsultasiDaftar): string {
    const tipe = TIPE_KONSULTASI_LABEL[row.tipe] ?? row.tipe;

    if (row.booking !== null && row.booking.tanggal_kunjungan !== null) {
        return `${tipe} • ${formatRentangJamZona(
            row.booking.slot_mulai,
            row.booking.slot_selesai,
            row.booking.tanggal_kunjungan,
        )}`;
    }

    return row.mulai_at === null ? tipe : `${tipe} • ${formatWaktuZona(row.mulai_at)}`;
}
