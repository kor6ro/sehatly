import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ClipboardCheck, Loader2, ShieldAlert } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import {
    CATATAN_APOTEKER_MAKS,
    STATUS_BISA_DIVERIFIKASI,
    STATUS_VERIFIKASI,
    perluCatatan,
    semuaPeringatan,
    cekInteraksiOptions,
    resepOptions,
    verifikasiResepMutation,
    verifikasiResepSchema,
    type VerifikasiResepInput,
} from '@/lib/api/resep';
import type { StatusVerifikasiResep } from '@/lib/api/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldTextarea, FormErrorSummary } from '@/components/form/field';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';
import { WarningPanel } from '@/features/resep/warning-panel';
import { ResepDetail } from '@/features/resep/resep-detail';

/**
 * One prescription from the queue, opened for the pharmacist's decision.
 *
 * ## The row comes from the queue, the detail comes from the row's own read
 *
 * `AntreanVerifikasiList` renders `GET /resep` (`ResepAntreanResource`), which publishes
 * no clinical content. This component takes the id a row handed over and reads
 * `GET /resep/{id}` - gated per row by `ResepAccess::untukBaca()` - plus
 * `GET /resep/{id}/cek-interaksi`, then submits the single answer through
 * `POST /resep/{id}/verifikasi`. The queue list never needed the drug names; this screen
 * is the one place a pharmacist reads them.
 *
 * ## A contraindication demands a note from the SECOND reader
 *
 * `ResepVerifikasiService::pastikanCatatan()` refuses `sesuai` and `ada_koreksi` with an empty
 * `catatan` when a `kontraindikasi` is present, and never refuses `ditolak` - a rejection is
 * the safe direction and must not be gated behind a note. The field is therefore required
 * exactly when the warning set calls for it AND the chosen outcome advances, which is the
 * server's rule read rather than re-derived.
 *
 * ## The warnings are re-read here, not taken from the detail response
 *
 * `GET /resep/{id}` already publishes the current warning set, so the queue could reuse it.
 * It does not, because this screen's whole job is the decision: the re-check endpoint is the
 * one the backend documents as "what a client calls before checkout", and the two answers are
 * computed from the same stored items by the same engine. One extra read buys a panel that is
 * explicitly the pharmacist's own pre-check rather than a value carried over from another
 * reader's response.
 */
export function ApotekerVerifikasiQueue({
    resepId,
    onSelesai,
}: {
    resepId: number;
    onSelesai: () => void;
}) {
    return (
        <div data-slot="apoteker-verifikasi-queue" className="flex flex-col gap-4">
            <AntreanResep key={resepId} resepId={resepId} onSelesai={onSelesai} />
        </div>
    );
}

function AntreanResep({
    resepId,
    onSelesai,
}: {
    resepId: number;
    onSelesai: () => void;
}) {
    const detail = useQuery(resepOptions(resepId));
    const cek = useQuery({ ...cekInteraksiOptions(resepId) });

    const [status, setStatus] = useState<StatusVerifikasiResep | null>(null);
    const [catatan, setCatatan] = useState('');

    const kirim = useMutation(verifikasiResepMutation(resepId));

    if (detail.isPending) {
        return <SkeletonRows rows={6} />;
    }

    if (detail.isError) {
        return (
            <>
                {detail.error instanceof ApiError && detail.error.isForbidden ? (
                    <ForbiddenState detail="Akun ini bukan akun apoteker, sehingga tidak dapat membuka antrean verifikasi." />
                ) : detail.error instanceof ApiError && detail.error.isNotFound ? (
                    <NotFoundState
                        title="Resep tidak ditemukan"
                        detail="Id tersebut tidak ada atau bukan milik pihak yang berhak."
                    />
                ) : (
                    <ErrorState
                        error={detail.error}
                        onRetry={() => {
                            void detail.refetch();
                        }}
                    />
                )}
            </>
        );
    }

    const resep = detail.data.data.resep;
    const warningGrup = cek.data?.data.warning_grup ?? null;

    /**
     * `ResepStateMachine::BISA_DIVERIFIKASI` is `aktif` and `diproses` and nothing else, so
     * an outcome is only offered for those two. A `diverifikasi` prescription has already
     * been signed and a terminal one never can be.
     */
    const bisa = STATUS_BISA_DIVERIFIKASI.some((s) => s === resep.status);

    const semua = warningGrup === null ? [] : semuaPeringatan(warningGrup);

    const butuhCatatan = perluCatatan(semua) && status !== 'ditolak';

    const terkunci = status === null || (butuhCatatan && catatan.trim() === '');

    const kirimSekarang = (): void => {
        if (status === null) {
            return;
        }

        const parsed = verifikasiResepSchema.safeParse({
            status,
            ...(catatan.trim() === '' ? {} : { catatan: catatan.trim() }),
        } satisfies VerifikasiResepInput);

        if (!parsed.success) {
            dispatchFlash({
                level: 'error',
                message: 'Pilihan verifikasi tidak valid.',
            });

            return;
        }

        kirim
            .mutateAsync(parsed.data)
            .then((hasil) => {
                dispatchFlash({ level: 'success', message: hasil.message });
                onSelesai();
            })
            .catch((error: unknown) => {
                dispatchFlash({
                    level: 'error',
                    message:
                        error instanceof ApiError
                            ? error.message
                            : 'Permintaan gagal.',
                });
            });
    };

    return (
        <>
            <ResepDetail
                resep={resep}
                verifikasi={detail.data.data.verifikasi}
                warningGrup={warningGrup}
            />

            <Card data-slot="verifikasi-form" className="mt-4">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <ClipboardCheck aria-hidden />

                        Keputusan apoteker
                    </CardTitle>

                    <CardDescription>
                        Satu resep hanya dapat diverifikasi sekali, dan setiap
                        keputusan bersifat final.
                    </CardDescription>
                </CardHeader>

                <CardContent className="flex flex-col gap-4">
                    <FormErrorSummary error={kirim.error} />

                    {bisa ? null : (
                        <p
                            data-slot="verifikasi-tidak-bisa"
                            className="text-muted-foreground text-sm"
                        >
                            Resep berstatus &quot;{resep.status}&quot; tidak dapat
                            diverifikasi, sehingga pilihan ini tidak ditawarkan.
                        </p>
                    )}

                    {warningGrup === null ? null : (
                        <WarningPanel grup={warningGrup} ringkas />
                    )}

                    <fieldset
                        data-slot="verifikasi-pilihan"
                        className="flex flex-col gap-2"
                    >
                        <legend className="text-sm font-medium">
                            Hasil verifikasi
                        </legend>

                        <div className="flex flex-wrap gap-2">
                            {STATUS_VERIFIKASI.map((nilai) => (
                                <Button
                                    key={nilai}
                                    type="button"
                                    data-slot="verifikasi-pilih"
                                    data-status={nilai}
                                    variant={
                                        status === nilai ? 'default' : 'outline'
                                    }
                                    disabled={!bisa || kirim.isPending}
                                    onClick={() => {
                                        setStatus(nilai);
                                    }}
                                >
                                    {nilai === 'sesuai'
                                        ? 'Sesuai'
                                        : nilai === 'ada_koreksi'
                                          ? 'Ada koreksi'
                                          : 'Ditolak'}
                                </Button>
                            ))}
                        </div>
                    </fieldset>

                    <Field
                        label={
                            butuhCatatan
                                ? 'Catatan apoteker (wajib diisi)'
                                : 'Catatan apoteker (opsional)'
                        }
                        required={butuhCatatan}
                        hint={
                            butuhCatatan
                                ? 'Peringatan kontraindikasi yang muncul saat verifikasi memerlukan pengakuan apoteker.'
                                : undefined
                        }
                        errors={
                            kirim.error instanceof ApiError
                                ? kirim.error.fieldErrors('catatan')
                                : []
                        }
                    >
                        <FieldTextarea
                            data-slot="verifikasi-catatan"
                            rows={3}
                            maxLength={CATATAN_APOTEKER_MAKS}
                            disabled={!bisa || kirim.isPending}
                            value={catatan}
                            onChange={(e) => {
                                setCatatan(e.target.value);
                            }}
                        />
                    </Field>

                    {butuhCatatan ? (
                        <p
                            data-slot="verifikasi-perlu-catatan"
                            className="text-warning flex items-center gap-1.5 text-xs"
                        >
                            <ShieldAlert aria-hidden className="size-3.5" />

                            Diperlukan untuk &quot;Sesui&quot; dan &quot;Ada
                            koreksi&quot; selama kontraindikasi aktif. Penolakan tidak
                            memerlukannya.
                        </p>
                    ) : null}
                </CardContent>

                <CardFooter>
                    <Button
                        type="button"
                        disabled={!bisa || terkunci || kirim.isPending}
                        onClick={kirimSekarang}
                    >
                        {kirim.isPending ? (
                            <Loader2 className="animate-spin" />
                        ) : null}

                        Kirim verifikasi
                    </Button>
                </CardFooter>
            </Card>
        </>
    );
}
