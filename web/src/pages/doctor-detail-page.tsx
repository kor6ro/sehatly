import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import { ArrowLeft, Video } from 'lucide-react';
import type { ReactNode } from 'react';
import { dokterDetailOptions } from '@/lib/api/dokter';
import { ApiError } from '@/lib/http';
import { formatDecimal, formatWaktu } from '@/lib/format';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { PageHeader } from '@/components/layout/page-header';
import { OfflineBanner } from '@/components/offline-banner';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, NotFoundState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CredentialPanel } from '@/features/dokter-profil/credential-panel';
import { DoctorProfileHero } from '@/features/dokter-profil/doctor-profile-hero';
import { SchedulePreview } from '@/features/dokter-profil/schedule-preview';

/**
 * `/dokter/:id` - one doctor's public profile, with no session required.
 *
 * ## What F04 adds to what was already here
 *
 * The page previously stopped at a static identity card: no schedule, no booking CTA, and
 * the fee/credentials buried in a long `dl`. F04 §4.3/§10 keeps every honest fallback this
 * page already had (`Bio belum diisi`, `Riwayat pendidikan belum dicatat`, `Belum ada
 * afiliasi`) and adds the three things a patient decides with:
 *
 * | block | source |
 * | --- | --- |
 * | hero with `Pesan jadwal` -> `/booking/{id}` | `DokterDetailResource` |
 * | `Jadwal terdekat` + `Lihat jadwal lengkap` | `GET /dokter/{id}/jadwal` + `/slot` |
 * | `Kredensial & verifikasi` (collapsed) | `DokterDetailResource` |
 *
 * ## The reviews block is deliberately absent
 *
 * F04's review acceptance criteria (AC-4/5/6/9) are `[TERBLOKIR backend]`: no `/ulasan`
 * route exists, and `dokter.rating_rata_rata`/`jumlah_ulasan` are stored aggregates that
 * are never recomputed from `ulasan_dokter` (F04 blocker #5). So this page requests no
 * review endpoint, renders no distribution, no sub-ratings and no review list. The two
 * server scalars are kept only as plain numbers in `Informasi lain` - exactly what the API
 * published, with no stars and no summary sentence that would imply a review UI exists.
 *
 * ## The 404 is still the interesting state
 *
 * `DokterController::show()` answers one 404 for **six** different situations: the id never
 * existed, `status_verifikasi` is not `terverifikasi`, the account is inactive,
 * `tersedia_telemedisin` is 0, `str_berlaku_sampai` has passed, or the account is
 * soft-deleted. One status for all six, on purpose - a caller who could tell "unverified"
 * from "absent" could enumerate the verification state of every doctor account from an
 * unauthenticated endpoint, which is exactly what the rule protects.
 *
 * So the copy here says only what the client is allowed to say, and the page **stops**.
 * A spinner left running against a request that has already answered 404 is the failure
 * mode this state exists to prevent, which is why `retry: 0` is set on the query and the
 * 4xx rule in `lib/query-client.ts` agrees with it.
 *
 * A non-numeric segment behaves identically: the route parameter is typed `string` and the
 * controller casts it to `int`, where `"abc"` becomes `0` and matches no row.
 */
export function DoctorDetailPage() {
    const { id } = useParams<{ id: string }>();

    const online = useOnlineStatus();

    const detail = useQuery(dokterDetailOptions(id ?? ''));

    /**
     * `document.title` is `{nama dokter} | Sehatly` once the profile is known, and a fixed
     * non-identifying title while it loads or fails. F04 AC-11: the title may name the
     * doctor (public data the URL already implies) and must never carry review text, an STR
     * or a NIK - none of which this page has.
     */
    const namaDokter = detail.data?.data.dokter.nama_lengkap;

    useDocumentTitle(
        namaDokter === undefined ? 'Profil dokter | Sehatly' : `${namaDokter} | Sehatly`,
    );

    if (detail.isPending) {
        return (
            <Halaman>
                <PageHeader title="Profil dokter" description="Memuat profil..." />

                <SkeletonRows rows={5} />
            </Halaman>
        );
    }

    if (detail.isError) {
        if (detail.error instanceof ApiError && detail.error.isNotFound) {
            return (
                <Halaman>
                    <PageHeader title="Profil dokter" />

                    <NotFoundState
                        action={
                            <Button asChild variant="outline" size="sm" className="min-h-11">
                                <Link to="/dokter">
                                    <ArrowLeft />

                                    Kembali ke direktori
                                </Link>
                            </Button>
                        }
                    />
                </Halaman>
            );
        }

        return (
            <Halaman>
                <PageHeader title="Profil dokter" />

                <ErrorState
                    error={detail.error}
                    onRetry={() => {
                        void detail.refetch();
                    }}
                />

                <Button asChild variant="outline" className="min-h-11 w-fit">
                    <Link to="/dokter">
                        <ArrowLeft />

                        Kembali ke direktori
                    </Link>
                </Button>
            </Halaman>
        );
    }

    const dokter = detail.data.data.dokter;

    return (
        <Halaman>
            <Button asChild variant="ghost" className="min-h-11 w-fit">
                <Link to="/dokter">
                    <ArrowLeft />

                    Kembali ke direktori
                </Link>
            </Button>

            <PageHeader
                title="Profil dokter"
                description="Lihat kredensial, jadwal, dan ulasan pasien sebelum memesan."
            />

            {/**
             * The wrapper carries the id the offline CTA points at through
             * `aria-describedby`. `OfflineBanner` renders `null` while online, so the
             * wrapper is empty then and the attribute is only set offline - the same
             * pattern the public directory uses.
             */}
            <div id="dokter-alasan-offline">
                <OfflineBanner message="Anda sedang offline. Jadwal dan pemesanan tidak dikirim sampai koneksi kembali." />
            </div>

            <DoctorProfileHero dokter={dokter} online={online} />

            <SchedulePreview dokterId={String(dokter.id)} />

            <CredentialPanel dokter={dokter} />

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Tentang dokter</CardTitle>
                </CardHeader>

                <CardContent>
                    {dokter.bio === null || dokter.bio === '' ? (
                        <EmptyState
                            compact
                            title="Bio belum diisi"
                            description="Dokter ini belum menambahkan deskripsi singkat pada profilnya."
                        />
                    ) : (
                        <p className="text-base leading-relaxed">{dokter.bio}</p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Informasi lain</CardTitle>
                </CardHeader>

                <CardContent className="flex flex-col gap-4">
                    {dokter.tersedia_telemedisin ? (
                        <Badge variant="outline" className="border-success/60 w-fit">
                            <Video aria-hidden className="text-success" />

                            Tersedia telemedisin
                        </Badge>
                    ) : null}

                    {/**
                     * The aggregates are shown as the server's own scalars - no stars, no
                     * "dari 5", no distribution - because the review endpoint that would
                     * make a summary honest does not exist yet (F04 §12 #2). Labelling
                     * them plainly keeps the numbers from being read as a verified
                     * review summary.
                     */}
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Cell
                            label="Rating"
                            value={formatDecimal(dokter.rating_rata_rata, 2)}
                        />

                        <Cell
                            label="Jumlah ulasan"
                            value={
                                dokter.jumlah_ulasan === null
                                    ? '-'
                                    : String(dokter.jumlah_ulasan)
                            }
                        />

                        <Cell label="Terdaftar" value={formatWaktu(dokter.dibuat_at)} />
                    </dl>
                </CardContent>
            </Card>
        </Halaman>
    );
}

/**
 * The page frame: a readable column, the same shape `StaticPage` gives the public
 * documents. The doctor profile is one task per screen (decide, then book), so it stays a
 * single column at every width instead of inventing a second one for the desktop.
 */
function Halaman({ children }: { children: ReactNode }) {
    return (
        <main className="mx-auto flex min-h-screen w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            {children}
        </main>
    );
}

function Cell({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>

            <dd className="text-sm font-medium">{value}</dd>
        </div>
    );
}
