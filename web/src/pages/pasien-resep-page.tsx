import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { FileText, Pill } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { meOptions } from '@/lib/api/me';
import { LABEL_STATUS_RESEP, riwayatResepOptions } from '@/lib/api/resep';
import type { StatusResep } from '@/lib/api/types';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    KedaluwarsaBadge,
    StatusResepBadge,
} from '@/features/resep/resep-status-badge';

/**
 * `/pasien/resep` - the caller's own prescriptions, with a badge for all eight states.
 *
 * ## A patient-only list, and the 403 says so
 *
 * `GET /api/v1/pasien/resep` calls `ResepAccess::riwayat()`, which starts with
 * `PasienRecordAccess::ownPasien($caller)` and **throws 403 for an account that owns no
 * `pasien` row**. So a doctor or a pharmacist opening this route gets a refusal, and the
 * refusal is about THEM - the copy must not say the list is empty, because that would be a
 * different fact.
 *
 * ## All eight statuses get a badge, and the expiry badge is separate again
 *
 * `ObatInteraksiService::STATUS_BERLAKU` is the first five values and `STATUS_AKHIR` the
 * last three; the same partition is `ResepStateMachine::TERMINAL`. A patient reading
 * "Dipolean" must be able to tell a finished course from a running one at a glance, which
 * is what the badge variant split does. `is_kedaluwarsa` is a SECOND badge because nothing
 * in the schema reacts to `berlaku_sampai`, so a prescription can read `aktif` and still be
 * unusable.
 */
export function PasienRiwayatResepPage() {
    const [halaman, setHalaman] = useState(1);
    const [status, setStatus] = useState<StatusResep | ''>('');

    const saya = useQuery(meOptions());

    const user = saya.data?.data.user ?? null;
    const boleh = user === null || user.tipe === 'pasien';

    const riwayat = useQuery({
        ...riwayatResepOptions({
            page: halaman,
            per_page: 15,
            ...(status === '' ? {} : { status }),
        }),
        enabled: boleh,
    });

    return (
        <>
            <PageHeader
                title="Riwayat resep"
                description="Setiap resep menampilkan status, tanggal berlaku, dan kode QR verifikasi."
            />

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant={status === '' ? 'default' : 'outline'}
                    onClick={() => {
                        setStatus('');
                        setHalaman(1);
                    }}
                >
                    Semua
                </Button>

                {(Object.keys(LABEL_STATUS_RESEP) as StatusResep[]).map((nilai) => (
                    <Button
                        key={nilai}
                        type="button"
                        size="sm"
                        data-slot="riwayat-filter"
                        data-status={nilai}
                        variant={status === nilai ? 'default' : 'outline'}
                        onClick={() => {
                            setStatus(nilai);
                            setHalaman(1);
                        }}
                    >
                        {LABEL_STATUS_RESEP[nilai]}
                    </Button>
                ))}
            </div>

            {!boleh ? (
                <ForbiddenState detail="Hanya akun pasien yang dapat melihat riwayat resep sendiri." />
            ) : riwayat.isPending ? (
                <SkeletonRows rows={5} />
            ) : riwayat.isError ? (
                riwayat.error instanceof ApiError &&
                riwayat.error.isForbidden ? (
                    <ForbiddenState detail="Akun ini tidak memiliki data pasien, sehingga riwayat resep tidak dapat dimuat." />
                ) : (
                    <ErrorState
                        error={riwayat.error}
                        onRetry={() => {
                            void riwayat.refetch();
                        }}
                    />
                )
            ) : riwayat.data.data.resep.length === 0 ? (
                <EmptyState
                    title="Belum ada resep"
                    description="Resep yang ditulis dokter untuk Anda akan muncul di sini beserta kode QR untuk diverifikasi apoteker."
                />
            ) : (
                <>
                    <ul data-slot="riwayat-resep" className="flex flex-col gap-3">
                        {riwayat.data.data.resep.map((resep) => (
                            <li key={resep.id}>
                                <Card data-slot="riwayat-resep-item" data-status={resep.status}>
                                    <CardContent className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="flex flex-col gap-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link
                                                    to={`/resep/${resep.id}`}
                                                    className="text-sm font-medium underline"
                                                >
                                                    {resep.nomor_resep}
                                                </Link>

                                                <StatusResepBadge status={resep.status} />

                                                {resep.is_kedaluwarsa ? (
                                                    <KedaluwarsaBadge />
                                                ) : null}
                                            </div>

                                            <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                                <Pill aria-hidden className="size-3" />

                                                {(resep.items ?? []).length} item - berlaku
                                                sampai {resep.berlaku_sampai ?? '-'}
                                            </p>
                                        </div>

                                        <Button asChild variant="outline" size="sm">
                                            <Link to={`/resep/${resep.id}`}>
                                                <FileText />

                                                Detail
                                            </Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>

                    <Pagination
                        meta={riwayat.data.meta}
                        onPageChange={setHalaman}
                    />
                </>
            )}
        </>
    );
}
