import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router';
import { ArrowLeft } from 'lucide-react';
import { dokterDetailOptions } from '@/lib/api/dokter';
import { ApiError } from '@/lib/http';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { OfflineBanner } from '@/components/offline-banner';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, NotFoundState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { ProfilDokterIsi } from '@/features/dokter-profil/profil-dokter-isi';
import { Jejak } from '@/features/landing/jejak-dokter';

/**
 * One doctor's profile, opened IN the landing page rather than at an address of its own.
 *
 * ## Why in place
 *
 * A patient who picks a card out of the answer is reading a list, and sending them to a
 * second page to read one row - then making them press Back to get the list - is two
 * navigations for one decision. `?dokter={id}` therefore replaces the list on the same
 * address, and "Kembali ke daftar" removes exactly that parameter: same specialisation,
 * same type, same query, same page underneath.
 *
 * ## Why `/dokter/:id` still exists
 *
 * This is where a card on the LANDING page points. A card reached from outside the list -
 * from a booking, a notification, a consultation - has no list behind it, so it still
 * opens the profile as a page. One body (`ProfilDokterIsi`) serves both, which is the
 * only reason two addresses for one profile are not two profiles.
 *
 * ## The 404 is still the interesting state
 *
 * `DokterController::show()` answers one 404 for six different situations (the id never
 * existed, the account is unverified, inactive, off-telemedisin, STR-expired or deleted).
 * One status for all six, on purpose: a caller who could tell them apart could enumerate
 * the verification state of every doctor account from an unauthenticated endpoint. So the
 * copy here says only what the client is allowed to say, and stops - a spinner left
 * running against a request that has already answered 404 is the failure mode this state
 * exists to prevent, which is why `retry: 0` rides on the query.
 */
export function ProfilDokterInline({ id }: { id: string }) {
    const online = useOnlineStatus();
    const [params] = useSearchParams();

    const detail = useQuery(dokterDetailOptions(id));

    const nama = detail.data?.data.dokter.nama_lengkap;

    /**
     * Back to the list underneath, keeping every filter the visitor was standing in.
     * Computed rather than taken from `history`, because a profile opened straight from a
     * shared link has no history to go back to.
     */
    const kembali = (() => {
        const berikut = new URLSearchParams(params);

        berikut.delete('dokter');

        // Same guard the card came in with: a visit whose only question was a search
        // carries no parameter, and an empty address is the marketing sections, not the
        // list this button promises to return to.
        if (berikut.toString() === '') {
            berikut.set('semua', '1');
        }

        return `/?${berikut.toString()}`;
    })();

    return (
        <>
            <Jejak
                butir={[
                    { label: 'Home', to: '/' },
                    { label: 'Direktori dokter', to: '/?semua=1' },
                    { label: nama ?? 'Profil dokter' },
                ]}
            />

            <section
                data-slot="landing-profil"
                className="border-border bg-muted w-full border-y"
            >
                <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 md:px-6 md:py-8">
                    <Button asChild variant="ghost" className="min-h-11 w-fit">
                        <Link to={kembali}>
                            <ArrowLeft aria-hidden />

                            Kembali ke daftar
                        </Link>
                    </Button>

                    {/**
                     * The wrapper carries the id the offline CTA points at through
                     * `aria-describedby`. `OfflineBanner` renders `null` while online, so
                     * the wrapper is empty then and the attribute is only set offline.
                     */}
                    <div id="dokter-alasan-offline">
                        <OfflineBanner message="Anda sedang offline. Jadwal dan pemesanan tidak dikirim sampai koneksi kembali." />
                    </div>

                    {detail.isPending ? (
                        <SkeletonRows rows={5} />
                    ) : detail.isError ? (
                        detail.error instanceof ApiError && detail.error.isNotFound ? (
                            <NotFoundState />
                        ) : (
                            <ErrorState
                                error={detail.error}
                                onRetry={() => {
                                    void detail.refetch();
                                }}
                            />
                        )
                    ) : (
                        <ProfilDokterIsi
                            dokter={detail.data.data.dokter}
                            online={online}
                        />
                    )}
                </div>
            </section>
        </>
    );
}
