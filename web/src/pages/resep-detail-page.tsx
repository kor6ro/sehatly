import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { ApiError } from '@/lib/http';
import { meOptions } from '@/lib/api/me';
import { resepOptions } from '@/lib/api/resep';
import { ResepDetail } from '@/features/resep/resep-detail';
import {  } from '@/features/resep/resep-composer';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';

/**
 * `/resep/:id` - one prescription, readable by its three parties.
 *
 * ## 403 and 404 are different facts and get different words
 *
 * `ResepAccess::untukBaca()` is explicit about the split: an account that owns no profile
 * row at all gets a 403 about ITSELF, and an account that owns one but not this row gets a
 * 404, because a 403 on a row that exists is a cross-tenant existence oracle over a
 * sequential key. So the copy says "not entitled" for one and "not found" for the other,
 * and neither offers a retry - neither is fixed by asking again.
 *
 * ## A doctor gets a composer for their OWN prescription, and only when there is room for one
 *
 * `POST /konsultasi/{id}/resep` is keyed on a CONSULTATION, not on a prescription, so a prescription
 * read here has `konsultasi_id` and a doctor may write a further prescription off that
 * consultation. The affordance is offered only for a `dokter` and only when the row is
 * unexpired, because `resep.berlaku_sampai` and `is_kedaluwarsa` are the server's own
 * inputs to that decision.
 */
export function ResepDetailPage() {
    const { id } = useParams<{ id: string }>();
    const resepId = Number(id);

    const resep = useQuery({
        ...resepOptions(resepId),
        enabled: Number.isInteger(resepId),
    });

    const saya = useQuery(meOptions());

    if (!Number.isInteger(resepId)) {
        return (
            <>
                <PageHeader title="Detail resep" />

                <NotFoundState
                    title="Resep tidak ditemukan"
                    detail="Alamat halaman harus berisi id resep berupa angka."
                />
            </>
        );
    }

    if (resep.isPending) {
        return (
            <>
                <PageHeader
                    title="Detail resep"
                    description="Memuat resep beserta peringatan interaksi yang dihitung ulang."
                />

                <SkeletonRows rows={6} />
            </>
        );
    }

    if (resep.isError) {
        return (
            <>
                <PageHeader title="Detail resep" />

                {resep.error instanceof ApiError && resep.error.isForbidden ? (
                    <ForbiddenState />
                ) : resep.error instanceof ApiError && resep.error.isNotFound ? (
                    <NotFoundState
                        title="Resep tidak ditemukan"
                        detail="Id tersebut tidak ada atau bukan milik pihak yang berhak."
                    />
                ) : (
                    <ErrorState
                        error={resep.error}
                        onRetry={() => {
                            void resep.refetch();
                        }}
                    />
                )}
            </>
        );
    }

    const data = resep.data.data.resep;
    const user = saya.data?.data.user ?? null;

    const bolehTulis =
        user?.tipe === 'dokter' &&
        data.konsultasi_id !== null &&
        !data.is_kedaluwarsa &&
        !data.terminal;

    return (
        <>
            <PageHeader
                title={`Resep ${data.nomor_resep}`}
                description="Dibaca dari GET /api/v1/resep/{id}. Peringatan dihitung dari item yang tersimpan, bukan dari respons 201 yang dilihat dokter."
                action={
                    bolehTulis ? (
                        <Button asChild variant="outline">
                            <a href={`/konsultasi/${data.konsultasi_id}/resep`}>
                                Tulis resep lanjutan
                            </a>
                        </Button>
                    ) : undefined
                }
            />

            <ResepDetail
                resep={data}
                verifikasi={resep.data.data.verifikasi}
                warningGrup={resep.data.data.warning_grup}
            />

            {/**
             * The composer is reachable from a consultation, not from a prescription, so it
             * is never mounted here. Rendering it would need a `konsultasiId` this route does
             * not carry, and guessing one would write a prescription off the wrong
             * consultation.
             */}
            {data.konsultasi_id === null ? (
                <p className="text-muted-foreground text-sm">
                    Resep ini tidak tertaut ke konsultasi mana pun, sehingga tidak dapat
                    dilanjutkan dari layar ini.
                </p>
            ) : null}
        </>
    );
}
