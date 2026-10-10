import { useState } from 'react';
import { Navigate, useSearchParams } from 'react-router';
import { LandingHeader } from '@/components/layout/landing-header';
import { LoginDialog } from '@/components/auth/login-dialog';
import { Toaster } from '@/components/ui/sonner';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { HeroCarousel } from '@/features/landing/hero-carousel';
import { DirektoriSection } from '@/features/landing/direktori-section';
import { LandingFooter } from '@/features/landing/landing-footer';
import {
    ArtikelSection,
    CekMandiriSection,
    KamusSection,
    ObatSection,
    PromoSection,
    SolusiSection,
    TestimoniSection,
} from '@/features/landing/sections';

/**
 * `/` - the landing page, and the home of every account in this app.
 *
 * ## Why it is no longer a chooser
 *
 * This route used to render "Pilih tujuan" with two buttons, because the alternative was
 * a redirect chain: sending a signed-out visitor to `/dashboard` bounced them to
 * `/login`, and sending them to `/dokter` put a directory in front of somebody looking
 * for their own profile. Both of those assumed a destination. The landing page does not
 * assume anything - it is the one screen a signed-out visitor, a patient, a doctor and an
 * admin can all read, so every door in the app now opens onto it instead of onto the
 * dashboard: OTP verification, the sign-in dialog, the sign-up completion form, and
 * `/login` itself when a session already exists.
 *
 * The dashboard did not go away; it moved one menu entry away. `LandingHeader`'s account
 * pill carries it, which is also the only place a visitor learns that it exists.
 *
 * ## What it carries of the doctor directory
 *
 * Only the answer to a pick. `DirektoriSection` prints a specialisation's name, its
 * count, its cards and a door to the rest, and only while `?spesialisasi=` says so; the
 * whole directory - search, sixteen specialisations, type, sort - is `/dokter`, a page of
 * its own whose doors are the navigation.
 *
 * The two addresses this page used to answer with, `/?direktori=semua` and `/?search=…`,
 * still work and forward to that page, carrying their parameter with them. They are the
 * addresses this very app used to write, so they sit in history, in bookmarks and in
 * links people already sent: a redirect that dropped `?search=` would answer somebody's
 * search with the whole table, which is worse than a 404 because it looks like an answer.
 * `replace` is used so the old address is not left in history as a stop that bounces.
 *
 *
 * ## Why the page owns the dialog
 *
 * `LandingHeader` renders its "Masuk" action twice - in the top bar and inside the mobile
 * sheet - and two `LoginDialog` instances would each hold their own OTP challenge and race
 * over the one `sessionStorage` key that carries it. So the page owns the single instance
 * and hands the opener down, exactly as the previous `RootPage` did.
 *
 * ## Why `<Toaster />` is mounted here at all
 *
 * It is the only thing this page shares with `AppShell`, and it is not optional: the
 * flash messages for "Selamat datang kembali" and "Akun kamu sudah jadi" are dispatched
 * by the sign-in and completion flows, whose destination is now THIS screen. `Toaster`
 * is what listens for them (`useFlashToast` runs inside it), so a landing page without
 * one would swallow the sentence that tells the visitor their account exists.
 */
export function LandingPage() {
    useDocumentTitle('Beranda | Sehatly');

    const [loginOpen, setLoginOpen] = useState(false);
    const [params] = useSearchParams();

    /**
     * Read before anything renders, so an old address never paints a landing page that is
     * about to leave: the visitor sees the directory, not a frame of the front page
     * flashing behind it.
     */
    const alih = alihDirektoriLama(params);

    if (alih !== null) {
        return <Navigate to={alih} replace />;
    }

    return (
        <div className="bg-background flex min-h-screen flex-col">
            <LandingHeader onMasuk={() => setLoginOpen(true)} />

            <main className="flex-1">
                <HeroCarousel />

                <DirektoriSection />

                <SolusiSection />
                <PromoSection />
                <ObatSection />
                <KamusSection />
                <ArtikelSection />
                <CekMandiriSection />
                <TestimoniSection />
            </main>

            <LandingFooter />

            <LoginDialog open={loginOpen} onOpenChange={setLoginOpen} />

            <Toaster />
        </div>
    );
}

/**
 * The two landing addresses that used to OPEND the directory section, turned into the
 * address of the page the directory is now - `null` for every other landing URL.
 *
 * | old address | who wrote it | becomes |
 * | --- | --- | --- |
 * | `/?direktori=semua` | "Lihat semua dokter", the drawer, an empty search | `/dokter` |
 * | `/?search=…` | the search pill in the bar, or on the phone | `/dokter?search=…` |
 *
 * `?spesialisasi=` deliberately stays put: a pick is answered ON this page by
 * `DirektoriSection`, which is the whole reason a pick points here. It is carried along
 * only when it arrives in company with one of the two above, because an old link could
 * have written both, and a redirect that kept the query but dropped the filter would
 * answer half a question.
 *
 * The `?search=` value is passed through rather than swallowed. §9 forbids this app from
 * WRITING a free-text query into an address, and it never does any more - but dropping
 * one that an older build already wrote would trade a privacy rule for a wrong answer:
 * the visitor asked for "Rina" and would be shown everybody.
 */
function alihDirektoriLama(params: URLSearchParams): string | null {
    if (!params.has('search') && !params.has('direktori')) {
        return null;
    }

    const tujuan = new URLSearchParams();

    for (const kunci of ['search', 'spesialisasi'] as const) {
        const nilai = params.get(kunci);
        if (nilai !== null) tujuan.set(kunci, nilai);
    }

    const tanya = tujuan.toString();

    return tanya === '' ? '/dokter' : `/dokter?${tanya}`;
}
