import { useParams } from 'react-router';
import { useQuery, useMutation } from '@tanstack/react-query';
import { MapPin, Package, Truck, CheckCircle } from 'lucide-react';
import {
    kurirPesananOptions,
    ambilPesananMutation,
    antarPesananMutation,
} from '@/lib/api/kurir';
import { formatWaktu } from '@/lib/format';
import { ApiError } from '@/lib/http';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { SkeletonRows } from '@/shared/components/states/loading-state';
import { ErrorState, ForbiddenState } from '@/shared/components/states/error-state';
import { KurirStatusBadge } from '@/portals/kurir/features/kurir/kurir-status-badge';

export function KurirPesananDetailPage() {
    const { id } = useParams();
    const pesananId = Number(id);

    const pesanan = useQuery(kurirPesananOptions(pesananId));

    const ambil = useMutation(ambilPesananMutation(pesananId));
    const antar = useMutation(antarPesananMutation(pesananId));

    if (pesanan.isPending) {
        return <SkeletonRows rows={6} />;
    }

    if (pesanan.isError) {
        if (pesanan.error instanceof ApiError && pesanan.error.isForbidden) {
            return <ForbiddenState detail="Halaman ini hanya untuk akun kurir." />;
        }

        return (
            <ErrorState
                error={pesanan.error}
                onRetry={() => void pesanan.refetch()}
            />
        );
    }

    const p = pesanan.data.data.pesanan;
    const isPending = ambil.isPending || antar.isPending;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <h1 className="text-xl font-semibold">{p.nomor_pesanan}</h1>
                <KurirStatusBadge status={p.status} />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Detail Pesanan</CardTitle>
                </CardHeader>

                <CardContent className="flex flex-col gap-3 text-sm">
                    {p.apotek === null ? null : (
                        <div className="flex items-start gap-2">
                            <MapPin
                                aria-hidden
                                className="text-muted-foreground mt-0.5 size-4 shrink-0"
                            />

                            <div>
                                <p className="font-medium">{p.apotek.nama}</p>
                                {p.apotek.alamat ? (
                                    <p className="text-muted-foreground">
                                        {p.apotek.alamat}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                    )}

                    <div className="flex items-start gap-2">
                        <Truck
                            aria-hidden
                            className="text-muted-foreground mt-0.5 size-4 shrink-0"
                        />

                        <div>
                            <p className="font-medium">Tujuan pengiriman</p>
                            <p className="text-muted-foreground">{p.alamat_kirim}</p>
                        </div>
                    </div>

                    {p.no_resi ? (
                        <div className="flex items-start gap-2">
                            <Package
                                aria-hidden
                                className="text-muted-foreground mt-0.5 size-4 shrink-0"
                            />

                            <div>
                                <p className="font-medium">No. Resi</p>
                                <p className="text-muted-foreground">{p.no_resi}</p>
                            </div>
                        </div>
                    ) : null}

                    {p.dibuat_at ? (
                        <p className="text-muted-foreground text-xs">
                            Dibuat {formatWaktu(p.dibuat_at)}
                        </p>
                    ) : null}
                </CardContent>
            </Card>

            {p.tracking && p.tracking.length > 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Riwayat Status</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <ol className="flex flex-col gap-2">
                            {p.tracking.map((t) => (
                                <li
                                    key={t.id}
                                    className="text-muted-foreground flex items-start gap-2 text-sm"
                                >
                                    <CheckCircle
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0 text-green-500"
                                    />

                                    <div>
                                        <p className="text-foreground font-medium">
                                            {t.status}
                                        </p>

                                        {t.keterangan ? (
                                            <p>{t.keterangan}</p>
                                        ) : null}

                                        {t.lokasi ? (
                                            <p>{t.lokasi}</p>
                                        ) : null}

                                        <p className="text-xs">
                                            {formatWaktu(t.waktu)}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>
            ) : null}

            {p.status === 'siap' ? (
                <Button
                    disabled={isPending}
                    onClick={() => void ambil.mutate({})}
                >
                    <Truck aria-hidden className="size-4" />
                    Ambil Pesanan
                </Button>
            ) : p.status === 'sedang_dikirim' ? (
                <Button
                    disabled={isPending}
                    onClick={() => void antar.mutate({})}
                >
                    <CheckCircle aria-hidden className="size-4" />
                    Konfirmasi Terkirim
                </Button>
            ) : null}
        </div>
    );
}
