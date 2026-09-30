import { useCallback, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import {
    Loader2,
    MessagesSquare,
    Play,
    Receipt,
    Stethoscope,
} from 'lucide-react';
import { ApiError } from '@/lib/http';
import { formatRupiah, formatWaktu } from '@/lib/format';
import {
    fetchRiwayatPesan,
    konsultasiOptions,
    riwayatPesanOptions,
    tandaiPesanDibaca,
    TIPE_KONSULTASI_LABEL,
} from '@/lib/api/konsultasi';
import { meOptions } from '@/lib/api/me';
import { isEmptyPage, isPastLastPage } from '@/lib/api/pagination';
import { useKonsultasiChannel } from '@/hooks/use-konsultasi-channel';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';
import { EmptyState } from '@/components/states/empty-state';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { KonsultasiStatusBadge } from '@/features/konsultasi/status-badge';
import { ChatWindow } from '@/features/konsultasi/chat-window';
import { SoapForm } from '@/features/konsultasi/soap-form';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { dispatchFlash } from '@/lib/flash';

const PER_HALAMAN = 50;

/**
 * `/konsultasi/:id` - the consultation, its transcript, and the SOAP note.
 *
 * ## Two reads, and a third one this page deliberately does NOT make
 *
 * `GET /konsultasi/{id}` and `GET /konsultasi/{id}/chat` are ordinary reads.
 * `GET /rekam-medis/{id}` is not: every completed read writes an
 * `akses_rekam_medis_log` row. So this page never fetches a medical record and never
 * keeps one across a navigation - the record has its own page, and that page owns the
 * accounting. Fetching it here would write a log row nobody asked for.
 *
 * ## The transcript is delivered once, and the SENDER is the common case
 *
 * The page holds the REST history and the live frames as two lists and lets
 * `useKonsultasiChannel` hand back a merged, ordered, deduplicated one. The case worth
 * naming is the AUTHOR'S: the server broadcasts to the whole private channel and the
 * author is subscribed to it, so the author receives their own message over the socket
 * as well as in the POST response. `data-duplicates-suppressed` is therefore expected
 * to be non-zero after every send, and a count of zero would mean the dedupe is NOT
 * running.
 *
 * ## The chat list is paginated, so older messages are reachable
 *
 * `GET /konsultasi/{id}/chat` is the one ASCENDING list in the application and it
 * pages. Showing page 1 only would silently truncate a long consultation, so the
 * control is here; and because "no messages at all" and "past the last page" produce
 * the same empty `data` array, the two are told apart with `meta.total` through the
 * shared helpers rather than by the array length.
 */
export function KonsultasiPage() {
    const { id } = useParams<{ id: string }>();
    const konsultasiId = Number(id);
    const [halaman, setHalaman] = useState(1);

    const queryClient = useQueryClient();

    useFlashToast();

    const sesi = useQuery({
        ...konsultasiOptions(konsultasiId),
        enabled: Number.isInteger(konsultasiId),
    });

    const riwayat = useQuery({
        ...riwayatPesanOptions(konsultasiId, {
            page: halaman,
            per_page: PER_HALAMAN,
        }),
        enabled: Number.isInteger(konsultasiId),
    });

    const saya = useQuery(meOptions());

    /**
     * The resync fetch, held in a `useCallback` because `useKonsultasiChannel` reads
     * it through a ref. A fresh identity on every render must not unsubscribe and
     * resubscribe the channel: that would be one `POST /api/broadcasting/auth` per
     * render against an endpoint that rate limits, and the symptom is a chat stuck
     * permanently on "menghubungkan".
     *
     * It reads page 1, not the current page: the gap a reconnect opened is at the
     * END of the ascending transcript, so the newest page is the one that closes it.
     */
    const ambilRiwayat = useCallback(async () => {
        if (!Number.isInteger(konsultasiId)) {
            return [];
        }

        const hasil = await fetchRiwayatPesan(konsultasiId, {
            page: 1,
            per_page: PER_HALAMAN,
        });

        return hasil.data.pesan;
    }, [konsultasiId]);

    const halamanPesan = useMemo(
        () => riwayat.data?.data.pesan ?? [],
        [riwayat.data],
    );

    const channel = useKonsultasiChannel(konsultasiId, {
        initial: halamanPesan,
        fetchHistory: ambilRiwayat,
    });

    const tandaiDibaca = useCallback(async () => {
        if (!Number.isInteger(konsultasiId)) {
            return;
        }

        try {
            await tandaiPesanDibaca(konsultasiId);
        } catch {
            /**
             * Mark-read is a convenience, never a gate on reading the transcript, and
             * a refusal here must not interrupt anything the user can see. Swallowed
             * rather than surfaced because surfacing it would put an error in front of
             * a screen that is working correctly.
             */
        }
    }, [konsultasiId]);

    if (!Number.isInteger(konsultasiId)) {
        return (
            <>
                <PageHeader title="Konsultasi" />

                <NotFoundState
                    title="Konsultasi tidak ditemukan"
                    detail="Alamat halaman harus berisi nomor konsultasi berupa angka."
                />
            </>
        );
    }

    if (sesi.isPending) {
        return (
            <>
                <PageHeader title="Konsultasi" description="Memuat sesi." />

                <SkeletonRows rows={4} />
            </>
        );
    }

    if (sesi.isError) {
        /**
         * A 404 means a row that is not the caller's or is not there at all -
         * `KonsultasiAccess` answers 404 rather than 403 precisely so existence is not
         * leaked across tenants - so the card says only that, in Indonesian, and offers no
         * retry, because asking again cannot change it. The F3-06 fix: this used to fall
         * through to `ErrorState`, which pairs the Indonesian heading with Laravel's own
         * English "Resource not found."
         */
        if (sesi.error instanceof ApiError && sesi.error.isNotFound) {
            return (
                <>
                    <PageHeader title="Konsultasi" />

                    <NotFoundState
                        title="Konsultasi tidak ditemukan"
                        detail="Id tersebut tidak ada atau bukan milik pihak yang berhak. Daftar konsultasi milik akun ini ada di halaman Konsultasi."
                        action={
                            <Button asChild variant="outline" size="sm">
                                <Link to="/konsultasi">
                                    <MessagesSquare aria-hidden />

                                    Daftar konsultasi saya
                                </Link>
                            </Button>
                        }
                    />
                </>
            );
        }

        return (
            <>
                <PageHeader title="Konsultasi" />

                {sesi.error instanceof ApiError && sesi.error.isForbidden ? (
                    <ForbiddenState />
                ) : (
                    <ErrorState
                        error={sesi.error}
                        onRetry={() => {
                            void sesi.refetch();
                        }}
                    />
                )}
            </>
        );
    }

    const konsultasi = sesi.data.data.konsultasi;
    const user = saya.data?.data.user ?? null;

    /**
     * The SOAP form is doctor-only, and the two gates are named rather than guessed:
     * `PUT /konsultasi/{id}/selesai` carries `tipe:dokter` and
     * `permission:konsultasi.selesai`, so a patient is refused 403 at the server.
     * Hiding the form is the client-side half of that same rule.
     */
    const bolehSoap = user?.tipe === 'dokter';

    return (
        <>
            <PageHeader
                title={`Konsultasi #${konsultasi.id}`}
                description="Percakapan Anda dengan dokter. Riwayat pesan dimuat ulang dari server."
                action={<KonsultasiStatusBadge status={konsultasi.status} />}
            />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Play aria-hidden />

                                Percakapan
                            </CardTitle>

                            <CardDescription>
                                Pesan hanya dapat dilihat oleh Anda dan dokter
                                yang menangani konsultasi ini.
                            </CardDescription>
                        </CardHeader>

                        <CardContent className="flex flex-col gap-4">
                            {riwayat.isPending ? (
                                <SkeletonRows rows={5} />
                            ) : riwayat.isError ? (
                                <ErrorState
                                    error={riwayat.error}
                                    onRetry={() => {
                                        void riwayat.refetch();
                                    }}
                                />
                            ) : isPastLastPage(riwayat.data.meta, halamanPesan.length) ? (
                                <EmptyState
                                    compact
                                    title="Halaman ini di luar jangkauan"
                                    description="Transkrip masih ada, tetapi halaman yang diminta melewati halaman terakhir. Kembali ke halaman pertama untuk membacanya."
                                    action={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setHalaman(1);
                                            }}
                                        >
                                            Ke halaman pertama
                                        </Button>
                                    }
                                />
                            ) : isEmptyPage(riwayat.data.meta, halamanPesan.length) &&
                              channel.messages.length === 0 ? (
                                <EmptyState
                                    compact
                                    title="Belum ada pesan"
                                    description="Tidak ada satu pun pesan pada konsultasi ini. Riwayat dibaca dari halaman pertama dan tidak difilter."
                                />
                            ) : (
                                <ChatWindow
                                    konsultasiId={konsultasi.id}
                                    pesan={channel.messages}
                                    subscriptionState={channel.subscriptionState}
                                    stats={channel.stats}
                                    onResubscribe={channel.resubscribe}
                                    onMarkRead={tandaiDibaca}
                                />
                            )}

                            <Pagination
                                meta={riwayat.data?.meta}
                                onPageChange={(next) => {
                                    setHalaman(next);
                                }}
                            />
                        </CardContent>
                    </Card>

                    {bolehSoap ? <SoapForm konsultasi={konsultasi} /> : null}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Receipt aria-hidden />

                            Ringkasan
                        </CardTitle>
                    </CardHeader>

                    <CardContent className="flex flex-col gap-2 text-sm">
                        <Baris
                            label="Pasien"
                            nilai={konsultasi.pasien?.nama_lengkap ?? '-'}
                        />
                        <Baris
                            label="Dokter"
                            nilai={konsultasi.dokter?.nama_lengkap ?? '-'}
                        />
                        <Baris
                            label="Tipe"
                            nilai={
                                TIPE_KONSULTASI_LABEL[konsultasi.tipe] ??
                                konsultasi.tipe
                            }
                        />
                        <Baris
                            label="Biaya"
                            nilai={formatRupiah(konsultasi.biaya_konsultasi)}
                        />
                        <Baris label="Mulai" nilai={formatWaktu(konsultasi.mulai_at)} />
                        <Baris
                            label="Selesai"
                            nilai={formatWaktu(konsultasi.selesai_at)}
                        />
                        <Baris
                            label="Durasi"
                            nilai={
                                konsultasi.total_durasi_detik === null
                                    ? '-'
                                    : `${konsultasi.total_durasi_detik} detik`
                            }
                        />

                        {bolehSoap ? null : (
                            <p className="text-muted-foreground flex items-start gap-2 text-xs">
                                <Stethoscope aria-hidden className="mt-0.5 size-3" />

                                Form SOAP hanya ditampilkan untuk akun dokter.
                                Pasien tidak dapat menulis catatan ini.
                            </p>
                        )}

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="w-fit"
                            onClick={() => {
                                void tandaiPesanDibaca(konsultasi.id)
                                    .then(() => {
                                        dispatchFlash({
                                            level: 'info',
                                            message: 'Pesan ditandai sudah dibaca.',
                                        });

                                        void queryClient.invalidateQueries({
                                            queryKey: [
                                                'v1',
                                                'konsultasi',
                                                'chat',
                                                konsultasi.id,
                                            ],
                                        });
                                    })
                                    .catch(() => {
                                        dispatchFlash({
                                            level: 'error',
                                            message: 'Pesan gagal ditandai dibaca.',
                                        });
                                    });
                            }}
                        >
                            {riwayat.isFetching ? (
                                <Loader2 className="animate-spin" />
                            ) : null}

                            Tandai sudah dibaca
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function Baris({ label, nilai }: { label: string; nilai: string }) {
    return (
        <div className="flex flex-col gap-0.5 sm:flex-row sm:gap-2">
            <span className="text-muted-foreground w-full text-xs sm:w-36">{label}</span>

            <span className="break-words">{nilai}</span>
        </div>
    );
}
