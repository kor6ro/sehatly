import { Link } from 'react-router';
import { EmptyState } from '@/shared/components/states/empty-state';
import { PageHeader } from '@/shared/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { PORTAL_NAMA, portalBeranda, portalTipe, type Portal } from '@/lib/portal';

/**
 * The three route-level screens the staff portals add: wrong portal, unknown address,
 * and workspace-not-built-yet.
 *
 * ## Why the wrong-portal answer looks like a 404
 *
 * The plan's section 2.4 rendered the guard's refusal as "Akses ditolak - portal ini
 * hanya untuk: admin, superadmin". That sentence is an invitation to probe: it confirms
 * the address exists and states the exact vocabulary the portal wants, so somebody with
 * the wrong account learns which accounts to aim for. Halodoc's own behaviour is the
 * opposite - a signed-in user on the wrong surface is told the page is not found, and
 * nothing about the surface is confirmed - so this is the shape the user chose over the
 * plan's plain `ForbiddenState`.
 *
 * The exit is the difference between the two audiences the guard produces:
 *
 * - A signed-in person on the wrong portal is a REAL user with a home to go to, so
 *   {@link PortalSalahPage} offers "Kembali ke beranda" pointing at THEIR portal's
 *   origin - computed by prefix swap (`portalBeranda`), never from a stored list, so it
 *   works on any base domain.
 * - Somebody who simply mistyped gets {@link PortalTidakDitemukanPage}, whose only way
 *   out is `/` on THIS portal - which the router resolves to the workspace when signed
 *   in and to `/login` when not, so one button is the right exit for both.
 */

/**
 * Signed in, but `/me` says this account's `tipe` belongs to another portal.
 *
 * The description deliberately does not name the portal's allowed `tipe` (see the note
 * above); it only says the address is not available to THIS account, which is the one
 * thing the visitor already knows about themselves.
 */
export function PortalSalahPage({
    portal,
    tipe,
}: {
    portal: Portal;
    tipe: string | null | undefined;
}) {
    /**
     * `perawat` and any future portal-less `tipe` resolve to the patient portal, which
     * is where a bare account profile already lives - the same fallback the app shell
     * makes. The absolute URL matters: a relative link would come back to this portal's
     * `/`, land on the guard again, and loop.
     */
    const rumah = portalBeranda(portalTipe(tipe));

    return (
        <main className="flex min-h-screen flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Halaman tidak ditemukan" />

            <EmptyState
                title="Halaman ini tidak tersedia"
                description={`Alamat yang Anda buka tidak tersedia di ${PORTAL_NAMA[portal]}. Kembali ke beranda untuk melanjutkan dengan akun Anda.`}
                action={
                    <Button asChild variant="outline">
                        <a href={rumah}>Kembali ke beranda</a>
                    </Button>
                }
            />
        </main>
    );
}

/**
 * `*` on a staff portal: an address no route table claims. The plan registers this
 * wildcard (section 2.3's example ends with `path: '*'`), and it is what keeps a
 * mistyped URL on a staff subdomain from falling through to a blank document.
 */
export function PortalTidakDitemukanPage({ portal }: { portal: Portal }) {
    return (
        <main className="flex min-h-screen flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Halaman tidak ditemukan" />

            <EmptyState
                title="Alamat ini tidak dikenal"
                description={`Periksa kembali tautan yang Anda buka, atau kembali ke beranda ${PORTAL_NAMA[portal]}.`}
                action={
                    <Button asChild variant="outline">
                        <Link to="/">Kembali ke beranda</Link>
                    </Button>
                }
            />
        </main>
    );
}

/**
 * A portal home whose screen is not part of this round.
 *
 * `PORTAL_HOME` names `/kurir/pesanan` and `/marketing/dashboard` (plan sections 2.3
 * and 5), but their pages belong to Steps 15-16. The route exists so a real
 * `kurir`/`marketing` account can sign in, pass `RequirePortalAuth` and find out
 * honestly where it stands - rather than landing on "Halaman tidak ditemukan", which
 * would say the address is unknown to a portal that has just confirmed it owns the
 * account. One leaf per pending portal, deleted when its page arrives.
 */
export function PortalDalamPengerjaan({ portal }: { portal: Portal }) {
    return (
        <main className="flex min-h-screen flex-col gap-6 p-4 md:p-6">
            <PageHeader title={PORTAL_NAMA[portal]} />

            <EmptyState
                title="Halaman ini sedang disiapkan"
                description={`Layar kerja ${PORTAL_NAMA[portal]} belum dibuat pada rilis ini. Silakan kembali ke beranda.`}
                action={
                    <Button asChild variant="outline">
                        <Link to="/">Kembali ke beranda</Link>
                    </Button>
                }
            />
        </main>
    );
}
