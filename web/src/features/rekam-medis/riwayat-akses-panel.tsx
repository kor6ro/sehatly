import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { History } from 'lucide-react';
import { ApiError } from '@/lib/http';
import {
    aksesRekamMedisOptions,
    LABEL_PERAN_AKSES,
    LABEL_TUJUAN_AKSES,
} from '@/lib/api/rekam-medis';
import { formatWaktuZona } from '@/lib/waktu';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';

/**
 * F10's access-log surface: the patient's own audit trail for one record.
 *
 * ## Three fields, never a fourth
 *
 * `GET /rekam-medis/{id}/akses` publishes `{waktu, peran, tujuan_akses}` and
 * deliberately not the actor's name or id. The `peran` value answers "what kind of
 * account opened this" - the legitimate transparency question - without exposing who
 * the individual was, which is the data-minimisation rule `AksesRekamMedisResource`
 * documents under UU PDP 27/2022 Pasal 5/34. Nothing here may join or invent a name.
 *
 * ## The log loads on demand, and the record does not wait for it
 *
 * The query is `enabled` only after the action is used, so opening a detail writes the
 * one log row for the record itself and nothing else. The page is fully readable while
 * this panel is closed or loading.
 */
export function RiwayatAksesPanel({ rekamMedisId }: { rekamMedisId: number }) {
    const [terbuka, setTerbuka] = useState(false);
    const [halaman, setHalaman] = useState(1);

    const akses = useQuery({
        ...aksesRekamMedisOptions(rekamMedisId, { page: halaman, per_page: 15 }),
        enabled: terbuka,
    });

    const baris = akses.data?.data.akses ?? [];

    return (
        <section
            aria-labelledby="riwayat-akses-judul"
            className="flex flex-col gap-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2
                    id="riwayat-akses-judul"
                    className="text-lg font-semibold"
                >
                    Riwayat akses
                </h2>

                <Button
                    type="button"
                    className="min-h-11"
                    aria-expanded={terbuka}
                    aria-controls="riwayat-akses-isi"
                    data-slot="riwayat-akses-aksi"
                    onClick={() => {
                        setTerbuka((nilai) => !nilai);
                    }}
                >
                    <History aria-hidden />

                    {terbuka ? 'Sembunyikan riwayat akses' : 'Lihat riwayat akses'}
                </Button>
            </div>

            <p className="text-muted-foreground text-sm">
                Hanya waktu, peran, dan tujuan akses yang dicatat. Nama petugas atau
                dokter yang membuka tidak ditampilkan.
            </p>

            {terbuka ? (
                <div
                    id="riwayat-akses-isi"
                    data-slot="riwayat-akses"
                    className="flex flex-col gap-3"
                >
                    {akses.isPending ? (
                        <SkeletonRows rows={3} />
                    ) : akses.isError ? (
                        akses.error instanceof ApiError && akses.error.isNotFound ? (
                            <NotFoundState
                                title="Riwayat akses tidak ditemukan"
                                detail="Catatan akses untuk rekam medis ini tidak ada atau bukan milik akun ini."
                                action={
                                    <Button asChild variant="outline" size="sm" className="min-h-11">
                                        <Link to="/rekam-medis">Daftar rekam medis saya</Link>
                                    </Button>
                                }
                            />
                        ) : akses.error instanceof ApiError && akses.error.isForbidden ? (
                            <ForbiddenState
                                detail="Akun ini tidak memiliki data pasien, sehingga riwayat akses tidak dapat dimuat."
                                action={
                                    <Button asChild variant="outline" size="sm" className="min-h-11">
                                        <Link to="/rekam-medis">Daftar rekam medis saya</Link>
                                    </Button>
                                }
                            />
                        ) : (
                            <ErrorState
                                error={akses.error}
                                title="Gagal memuat riwayat akses."
                                onRetry={() => {
                                    void akses.refetch();
                                }}
                            />
                        )
                    ) : baris.length === 0 ? (
                        <p className="text-base" data-slot="riwayat-akses-kosong">
                            Belum ada catatan akses.
                        </p>
                    ) : (
                        <>
                            <ul className="flex flex-col gap-2">
                                {baris.map((catatan, index) => (
                                    <li
                                        key={`${catatan.waktu}-${index}`}
                                        data-slot="riwayat-akses-baris"
                                        className="flex flex-col gap-1 rounded-md border p-3"
                                    >
                                        <span className="text-muted-foreground text-sm tabular-nums">
                                            {formatWaktuZona(catatan.waktu)}
                                        </span>

                                        <span className="text-base break-words">
                                            {LABEL_PERAN_AKSES[catatan.peran] ?? catatan.peran}{' '}
                                            -{' '}
                                            {LABEL_TUJUAN_AKSES[catatan.tujuan_akses] ??
                                                catatan.tujuan_akses}
                                        </span>
                                    </li>
                                ))}
                            </ul>

                            <Pagination
                                meta={akses.data?.meta}
                                onPageChange={setHalaman}
                            />
                        </>
                    )}
                </div>
            ) : null}
        </section>
    );
}
