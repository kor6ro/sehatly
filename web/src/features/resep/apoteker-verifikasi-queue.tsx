import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ClipboardCheck, Loader2, Search, ShieldAlert } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';
import { WarningPanel } from '@/features/resep/warning-panel';
import { ResepDetail } from '@/features/resep/resep-detail';

/**
 * The pharmacist's verification queue, addressed BY ID because it has to be.
 *
 * ## FINDING: no endpoint lists a pharmacist's queue
 *
 * The plan asks this component to "list prescriptions awaiting verification", and the route
 * table has nothing that does it:
 *
 * - `GET /api/v1/pasien/resep` is the only list, and `ResepAccess::riwayat()` begins with
 *   `PasienRecordAccess::ownPasien($caller)`, which **throws 403 for an account owning no
 *   `pasien` row**. A pharmacist gets a refusal, not a queue.
 * - `GET /api/v1/resep/{id}` and `GET /api/v1/resep/{id}/cek-interaksi` are per-row, and
 *   need an id the pharmacist cannot obtain without the missing list.
 *
 * So `GET /api/v1/apotek/resep?status=aktif` is needed and does not exist. It is reported as
 * a finding in `.omo/evidence/task-41-sehatly.md` rather than faked, and adding a route is
 * not this executor's to do. This component therefore uses only endpoints that exist: the
 * pharmacist opens a prescription by id and everything else follows.
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
    onTerpilih,
}: {
    resepId: number | null;
    onTerpilih: (id: number | null) => void;
}) {
    const [mentah, setMentah] = useState('');

    return (
        <div data-slot="apoteker-verifikasi-queue" className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Search aria-hidden />

                        Buka resep untuk diverifikasi
                    </CardTitle>

                    <CardDescription>
                        Masukkan id resep. Antrean tidak dapat ditampilkan di
                        halaman ini, jadi setiap resep dibuka satu per satu.
                    </CardDescription>
                </CardHeader>

                <CardContent>
                    <form
                        className="flex items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();

                            const id = Number(mentah.trim());

                            if (Number.isInteger(id) && id > 0) {
                                onTerpilih(id);
                            }
                        }}
                    >
                        <Field label="Id resep" className="flex-1">
                            <Input
                                data-slot="apotek-id-resep"
                                type="number"
                                min={1}
                                value={mentah}
                                onChange={(e) => {
                                    setMentah(e.target.value);
                                }}
                                placeholder="mis. 1"
                            />
                        </Field>

                        <Button type="submit" variant="outline">
                            Buka
                        </Button>
                    </form>
                </CardContent>
            </Card>

            {resepId === null ? null : (
                <AntreanResep
                    key={resepId}
                    resepId={resepId}
                    onSelesai={() => {
                        onTerpilih(null);
                    }}
                />
            )}
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
