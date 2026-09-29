import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router';
import { meOptions } from '@/lib/api/me';
import { konsultasiOptions } from '@/lib/api/konsultasi';
import { ResepComposer } from '@/features/resep/resep-composer';
import { ResepDetail } from '@/features/resep/resep-detail';
import { PageHeader } from '@/components/layout/page-header';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';
import { ApiError } from '@/lib/http';
import type { Resep } from '@/lib/api/types';

/**
 * `/konsultasi/:id/resep` - compose a prescription off one consultation.
 *
 * ## A doctor only, and the gate is a `tipe:` check on the server
 *
 * `POST /konsultasi/{id}/resep` carries `tipe:dokter` plus `permission:resep.buat`, and
 * `KonsultasiAccess::untukDokter()` additionally refuses a doctor attached to a different
 * consultation. A patient is therefore offered no composer at all rather than a disabled
 * one: `rekam-medis-page.tsx` gives the same reason for omitting its editor, and rendering
 * controls a caller cannot use is what turns a 403 into a support ticket.
 *
 * The consultation is read first because the composer needs its id and because a
 * consultation the doctor is not party to must 404 rather than render an empty form.
 */
export function ResepComposePage() {
    const { id } = useParams<{ id: string }>();
    const konsultasiId = Number(id);
    const navigate = useNavigate();

    const [terbuat, setTerbuat] = useState<Resep | null>(null);

    const konsultasi = useQuery({
        ...konsultasiOptions(konsultasiId),
        enabled: Number.isInteger(konsultasiId),
    });

    const saya = useQuery(meOptions());

    if (!Number.isInteger(konsultasiId)) {
        return (
            <>
                <PageHeader title="Resep elektronik" />

                <NotFoundState
                    title="Konsultasi tidak ditemukan"
                    detail="Alamat halaman harus berisi id konsultasi berupa angka."
                />
            </>
        );
    }

    if (konsultasi.isPending) {
        return (
            <>
                <PageHeader
                    title="Resep elektronik"
                    description="Memuat konsultasi."
                />

                <SkeletonRows rows={4} />
            </>
        );
    }

    if (konsultasi.isError) {
        return (
            <>
                <PageHeader title="Resep elektronik" />

                {konsultasi.error instanceof ApiError &&
                konsultasi.error.isForbidden ? (
                    <ForbiddenState />
                ) : (
                    <ErrorState
                        error={konsultasi.error}
                        onRetry={() => {
                            void konsultasi.refetch();
                        }}
                    />
                )}
            </>
        );
    }

    const user = saya.data?.data.user ?? null;
    const boleh = user?.tipe === 'dokter';

    return (
        <>
            <PageHeader
                title={`Resep untuk konsultasi #${konsultasiId}`}
                description="POST /api/v1/konsultasi/{id}/resep. Peringatan kontraindikasi dihitung server-side dan memerlukan pengakuan tertulis sebelum resep tersimpan."
            />

            {boleh ? (
                <ResepComposer
                    konsultasiId={konsultasiId}
                    onSelesai={(resep) => {
                        setTerbuat(resep);
                    }}
                />
            ) : (
                <ForbiddenState detail="Hanya akun dokter yang dapat menulis resep." />
            )}

            {/**
             * The saved prescription is rendered here rather than only toasted, because the
             * doctor needs to see the warning set the 201 returned - including the clashing
             * pair the 422 could only say existed - and the `berlaku_sampai` day they just
             * committed the patient to.
             */}
            {terbuat === null ? null : (
                <div className="mt-4 flex flex-col gap-4">
                    <ResepDetail
                        resep={terbuat}
                        verifikasi={null}
                        warningGrup={null}
                    />

                    <button
                        type="button"
                        className="text-primary self-start text-sm underline"
                        onClick={() => {
                            void navigate(`/resep/${terbuat.id}`);
                        }}
                    >
                        Buka detail resep
                    </button>
                </div>
            )}
        </>
    );
}
