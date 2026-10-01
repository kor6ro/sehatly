import { Link, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Eye, PenLine, Trash2, Undo2 } from 'lucide-react';
import { dokumenOptions, persetujuanOptions, versiAktif } from '@/lib/api/pdp-persetujuan';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { jalurKembali } from '@/lib/pdp/kembali';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { PageHeader } from '@/components/layout/page-header';
import { OfflineBanner } from '@/components/offline-banner';
import { LoadingState, SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState } from '@/components/states/error-state';
import { PdpSlot } from '@/features/pdp/pdp-slot';
import { CATATAN_KEPUTUSAN } from '@/features/pdp/pdp-teks';

/**
 * `/profil/privasi` - the five-slot consent checklist.
 *
 * ## Two reads, and both are needed before a decision can be made
 *
 * `GET /pdp/persetujuan` answers what is currently effective per slot.
 * `GET /pdp/dokumen` answers which document version is active, and that is the value the
 * write must echo back - so the screen waits for both before rendering a control. A
 * control that could not name a version would produce a pointless 422.
 *
 * ## The return path is a query parameter, and it is sanitised
 *
 * A booking or payment that the gate blocked links here with `?kembali=<internal path>`,
 * and the header offers the way back. The parameter carries a route, never a consent
 * status or value: F02 keeps the tab title and the URL free of anything about the record.
 * {@link jalurKembali} refuses anything that is not an internal path, so a crafted link
 * cannot turn this screen into an open redirect.
 *
 * ## The desktop layout's second column
 *
 * At 1280 px the checklist and a "Hak Anda" card sit side by side; below `lg` the card
 * follows the list. It restates the UU PDP rights the screen is an exercise of, which is
 * the one piece of context a patient deciding under Pasal 20-22 should not have to go
 * looking for.
 */
export function PrivasiPage() {
    useDocumentTitle('Privasi dan data');

    const [searchParams] = useSearchParams();
    const kembali = jalurKembali(searchParams.get('kembali'));
    const online = useOnlineStatus();

    const persetujuan = useQuery(persetujuanOptions());
    const dokumen = useQuery(dokumenOptions());

    const header = (
        <PageHeader
            title="Privasi dan data"
            description="Kelola persetujuan Anda atas data pribadi di Sehatly, sesuai UU No. 27 Tahun 2022."
            action={
                <Button asChild variant="outline">
                    <Link to={kembali ?? '/profil'}>
                        <ArrowLeft />

                        {kembali === null ? 'Profil' : labelKembali(kembali)}
                    </Link>
                </Button>
            }
        />
    );

    if (persetujuan.isPending || dokumen.isPending) {
        return (
            <>
                {header}

                <OfflineBanner />

                <LoadingState label="Memuat persetujuan...">
                    <SkeletonRows rows={5} />
                </LoadingState>
            </>
        );
    }

    if (persetujuan.isError || dokumen.isError) {
        return (
            <>
                {header}

                <OfflineBanner />

                <ErrorState
                    error={persetujuan.error ?? dokumen.error}
                    onRetry={() => {
                        void persetujuan.refetch();
                        void dokumen.refetch();
                    }}
                />
            </>
        );
    }

    const daftar = persetujuan.data.data.persetujuan;
    const katalog = dokumen.data.data.dokumen;
    const semuaKosong = daftar.every((entri) => entri.efektif === null);

    return (
        <>
            {header}

            <OfflineBanner />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                <div className="flex flex-col gap-4">
                    <p className="text-sm">
                        Ada 5 hal yang perlu Anda putuskan. Tidak ada pilihan yang
                        sudah ditentukan untuk Anda.
                    </p>

                    {semuaKosong ? (
                        <EmptyState
                            compact
                            title="Belum ada keputusan Anda"
                            description="Pilih Setujui atau Tidak setujui pada tiap hal di bawah."
                        />
                    ) : null}

                    {daftar.map((entri) => (
                        <PdpSlot
                            key={entri.jenis}
                            entri={entri}
                            versiAktif={versiAktif(katalog, entri.jenis)}
                            berlakuSejak={
                                katalog.find((baris) => baris.jenis === entri.jenis)
                                    ?.berlaku_sejak ?? null
                            }
                            online={online}
                        />
                    ))}
                </div>

                <HakAndaCard />
            </div>
        </>
    );
}

function labelKembali(jalur: string): string {
    if (jalur.startsWith('/booking')) {
        return 'Kembali ke booking';
    }

    if (jalur.startsWith('/pembayaran')) {
        return 'Kembali ke pembayaran';
    }

    return 'Kembali';
}

const HAK: ReadonlyArray<{ icon: typeof Eye; teks: string }> = [
    { icon: Eye, teks: 'Melihat data pribadi yang disimpan tentang Anda.' },
    { icon: PenLine, teks: 'Meminta perbaikan data yang tidak benar.' },
    { icon: Undo2, teks: 'Menarik persetujuan kapan saja, tanpa syarat.' },
    { icon: Trash2, teks: 'Meminta data Anda dihapus.' },
];

function HakAndaCard() {
    return (
        <Card data-slot="pdp-hak">
            <CardHeader>
                <CardTitle className="text-base">Hak Anda (UU PDP)</CardTitle>
            </CardHeader>

            <CardContent>
                <ul className="flex flex-col gap-3 text-sm">
                    {HAK.map(({ icon: Ikon, teks }) => (
                        <li key={teks} className="flex items-start gap-2">
                            <Ikon
                                aria-hidden
                                className="text-muted-foreground mt-0.5 size-4 shrink-0"
                            />

                            {teks}
                        </li>
                    ))}
                </ul>

                <Separator className="my-4" />

                <p className="text-muted-foreground text-sm">{CATATAN_KEPUTUSAN}</p>
            </CardContent>
        </Card>
    );
}
