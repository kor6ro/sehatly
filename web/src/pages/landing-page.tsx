import { useState } from 'react';
import { useSearchParams } from 'react-router';
import { LandingHeader } from '@/components/layout/landing-header';
import { LoginDialog } from '@/components/auth/login-dialog';
import { Toaster } from '@/components/ui/sonner';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { HeroCarousel } from '@/features/landing/hero-carousel';
import { DokterPilihanSection } from '@/features/landing/dokter-pilihan';
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
    const [params, setParams] = useSearchParams();

    /**
     * `?spesialisasi=` is this page's own state, not a filter aimed at another screen.
     *
     * The header's rail and its "Sering dicari" shortcuts point here (`/?spesialisasi=…`)
     * rather than at `/dokter`, because a visitor who picks "Dokter Gigi" has asked one
     * question and does not need the directory's search, sort and pagination to answer it.
     * A query parameter rather than component state keeps the pick a real link: shareable,
     * Back-able, and reachable with the keyboard like any other anchor.
     */
    const spesialisasi = params.get('spesialisasi');

    const tutupPilihan = () => {
        const berikut = new URLSearchParams(params);
        berikut.delete('spesialisasi');
        setParams(berikut);
    };

    return (
        <div className="bg-background flex min-h-screen flex-col">
            <LandingHeader onMasuk={() => setLoginOpen(true)} />

            <main className="flex-1">
                <HeroCarousel />

                <DokterPilihanSection kode={spesialisasi} onTutup={tutupPilihan} />

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
