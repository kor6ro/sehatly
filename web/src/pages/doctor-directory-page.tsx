import { useState } from 'react';
import { useLocation } from 'react-router';
import { LandingHeader } from '@/components/layout/landing-header';
import { LoginDialog } from '@/components/auth/login-dialog';
import { Toaster } from '@/components/ui/sonner';
import { PageHeader } from '@/components/layout/page-header';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { LandingFooter } from '@/features/landing/landing-footer';
import { DirektoriDokter } from '@/features/dokter/direktori';

/**
 * `/dokter` - the full doctor directory: search, sixteen specialisations, type, sort,
 * count, cards, pagination, and every offline and empty state around them.
 *
 * ## Why it is a page again
 *
 * It was a page, then a section of the landing page, and the flip that put it back here
 * was about what each surface is FOR. The landing page answers a PICK - a visitor who
 * chose "Dokter Gigi" gets a heading, a count, a few cards and a door to the rest, which
 * is the whole of what they asked. This page is what a visitor gets when they want to
 * browse: to compare, to filter, to sort, to page. Printing that on the landing page too
 * would put sixteen specialisations and a sort control in front of somebody who only
 * wanted to know whether a dentist is free.
 *
 * Every door into it is in the navigation - the panel's rail points at the landing page's
 * answer, `Lihat semua dokter`, the bar's search, the drawer, the "Solusi" tiles and the
 * footer all point here - so nothing on the landing page is a hover away from a screen
 * nobody offered it.
 *
 * ## Why `key={location.key}`
 *
 * `DirektoriDokter` seeds its query and its filters from the address ONCE, on mount: a
 * chip cleared inside the results must not be overwritten by the address it came from.
 * But this page can be reached twice without unmounting - the bar's search field sits in
 * the header, which this page also renders, so a second search is a navigation to the very
 * same path - and a URL that did not change is then the only thing that did not. Remount
 * on every navigation is the honest fix: a new arrival is a new question, and the answer
 * starts from the marker or the address rather than from a filter the visitor can no
 * longer see. It also makes `Lihat semua dokter` mean it, however the visitor got here.
 *
 * ## Why the landing chrome, and not the bare fragment it used to be
 *
 * The page as it first existed was `<PageHeader>` and nothing else - no bar, no footer,
 * no way out except the browser's Back button, which is a dead end dressed as a screen.
 * `LandingHeader` is the navigation this page's doors live in, and it is the same bar the
 * landing page carries, so a visitor who came here from it is still on the site they
 * started on. `LoginDialog` and `Toaster` come with it for the reasons `LandingPage`
 * documents: one dialog instance for two copies of the bar, and a listener for the flash
 * messages the OTP flows dispatch.
 */
export function DoctorDirectoryPage() {
    useDocumentTitle('Direktori dokter | Sehatly');

    const location = useLocation();
    const [loginOpen, setLoginOpen] = useState(false);

    return (
        <div className="bg-background flex min-h-screen flex-col">
            <LandingHeader onMasuk={() => setLoginOpen(true)} />

            <main className="flex-1">
                <div className="mx-auto w-full max-w-[1280px] px-4 py-8 md:px-6 md:py-10">
                    {/**
                     * F03 §4.3's header copy, verbatim: `Direktori dokter` over
                     * `Temukan dokter yang tepat, lalu pesan jadwal konsultasi.` It is an
                     * `h1` here rather than the section heading it was on the landing
                     * page, because this address has a document title of its own and the
                     * heading should agree with it.
                     */}
                    <PageHeader
                        title="Direktori dokter"
                        description="Temukan dokter yang tepat, lalu pesan jadwal konsultasi."
                    />

                    <div className="mt-6">
                        <DirektoriDokter key={location.key} />
                    </div>
                </div>
            </main>

            <LandingFooter />

            <LoginDialog open={loginOpen} onOpenChange={setLoginOpen} />

            <Toaster />
        </div>
    );
}
