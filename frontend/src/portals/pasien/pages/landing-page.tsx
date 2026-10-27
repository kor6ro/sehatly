import { useEffect, useState } from 'react';
import { Navigate, useSearchParams } from 'react-router';
import { LandingHeader } from '@/portals/pasien/components/layout/landing-header';
import { MasukDialog } from '@/shared/components/auth/masuk-dialog';
import { Toaster } from '@/components/ui/sonner';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { HeroCarousel } from '@/portals/pasien/features/landing/hero-carousel';
import { JejakDokter } from '@/portals/pasien/features/landing/jejak-dokter';
import { DirektoriDokter, adaPertanyaanDirektori } from '@/portals/pasien/features/dokter/direktori';
import { LandingFooter } from '@/portals/pasien/features/landing/landing-footer';
import {
    ArtikelSection,
    CekMandiriSection,
    KamusSection,
    ObatSection,
    PromoSection,
    SolusiSection,
    TestimoniSection,
} from '@/portals/pasien/features/landing/sections';

/**
 * `/` - the landing page, the home of every account in this app, AND the doctor
 * directory. There is no `/dokter` page any more: the address redirects here.
 *
 * ## Why it is no longer a chooser
 *
 * This route used to render "Pilih tujuan" with two buttons, because the alternative was
 * a redirect chain: sending a signed-out visitor to `/dashboard` bounced them to
 * `/masuk`, and sending them to a directory put a catalogue in front of somebody looking
 * for their own profile. Both of those assumed a destination. The landing page does not
 * assume anything - it is the one screen a signed-out visitor, a patient, a doctor and an
 * admin can all read, so every door in the app now opens onto it instead of onto the
 * dashboard: the sign-in dialog, the sign-up completion form, and the guard that used to
 * hand a visitor to the `/masuk` page. That page is gone; what used to be its address is
 * this one with `?masuk=1`, which is what {@link masukDitanya} reads to open the dialog.
 *
 * The dashboard did not go away; it moved one menu entry away. `LandingHeader`'s account
 * pill carries it, which is also the only place a visitor learns that it exists.
 *
 * ## The two screens this one address can be
 *
 * The whole doctor answer is the ANSWER to a question the header asks, and an answer
 * belongs on the page that was asked. So this route renders one of two bodies:
 *
 * | question | address | body |
 * | --- | --- | --- |
 * | none | `/` | the carousel and the seven sections below it |
 * | a filter or a search | `/?spesialisasi=…`, `/?semua=1` | the breadcrumb and `DirektoriDokter` |
 *
 * A doctor is no longer a third option. A card opens `/dokter/{id}`, a page of its own
 * with the about text, the education, every venue with its coordinates, the reviews and
 * the schedule - which a column beside a list has no room for, and which is an address a
 * patient can send to somebody. The third body this used to render, `ProfilDokterInline`
 * under `/?dokter=…`, is gone with it, and the address that used to reach it is redirected
 * in {@link alihProfilInline} rather than left to fall through to the marketing sections.
 *
 * The two bodies are mutually exclusive on purpose. A patient who typed a name into the bar
 * is looking at a result set, and the promo strip and the medical dictionary underneath it
 * are not part of the answer - which is why they are not rendered, rather than merely
 * pushed down.
 *
 * The one question the address may NOT carry is a free-text search (F03 §9: "kanker" is a
 * condition and a URL is pasted into chats), so `adaPertanyaanDirektori` counts a
 * `sessionStorage` query alongside the parameters.
 *
 * ## Why the page owns the dialog
 *
 * `LandingHeader` renders its "Masuk" action twice - in the top bar and inside the mobile
 * sheet - and two `MasukDialog` instances would each hold their own OTP challenge and race
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
     * `/?masuk=1` - the address that replaced the `/masuk` page.
     *
     * Every guard, every expired session and every 401 used to move the visitor to
     * `/masuk`, a screen whose whole job was to collect one phone number before sending
     * them on to `/otp`. There is no such screen any more: the dialog on THIS page holds
     * the entire exchange, so the redirect target became this page with a flag on it.
     *
     * The flag opens the dialog rather than merely landing here, because the alternative
     * answers an expired session with a marketing page and a button the visitor has to go
     * and find. A visitor who dismisses it is not asked again - see {@link ubahMasuk}.
     */
    const mintaMasuk = masukDitanya(params);

    useEffect(() => {
        if (mintaMasuk) {
            setLoginOpen(true);
        }
    }, [mintaMasuk]);

    /**
     * Read before anything renders, so an old address never paints a landing page that is
     * about to leave: the visitor sees the directory, not a frame of the front page
     * flashing behind it. `?dokter=` is read first because it is a destination, not a
     * filter, and it belongs on its own route now.
     */
    const alih = alihProfilInline(params) ?? alihDirektoriLama(params);

    if (alih !== null) {
        return <Navigate to={alih} replace />;
    }

    const bertanya = adaPertanyaanDirektori(params);

    /**
     * Closing drops the flag, so `?masuk=1` stays a request rather than becoming a state
     * of this address: pressing "Masuk" in the header after dismissing the dialog must
     * not reopen it, and a `replace` keeps the dismissed flag out of history so Kembali
     * does not bring it back.
     */
    const ubahMasuk = (open: boolean): void => {
        setLoginOpen(open);

        if (open || !mintaMasuk) {
            return;
        }

        const berikut = new URLSearchParams(params);

        berikut.delete(MASUK_PARAM);

        setParams(berikut, { replace: true });
    };

    return (
        <div className="bg-background flex min-h-screen flex-col">
            <LandingHeader onMasuk={() => setLoginOpen(true)} />

            <main className="flex-1">
                {/**
                 * The banner leads the front page and the answer alike - a category
                 * page whose banner sits above its breadcrumb is the shape this reference
                 * uses. The profile is the one thing it used to step aside for, and that
                 * is gone with `?dokter=`: there is no third body left to make room for.
                 */}
                <HeroCarousel />

                {/*
                    Two bodies, never both: a question gets the answer, everything
                    else gets the marketing sections.
                */}
                {bertanya ? (
                    <>
                        <JejakDokter />

                        <DirektoriDokter />
                    </>
                ) : (
                    <>
                        <SolusiSection />
                        <PromoSection />
                        <ObatSection />
                        <KamusSection />
                        <ArtikelSection />
                        <CekMandiriSection />
                        <TestimoniSection />
                    </>
                )}
            </main>

            <LandingFooter />

            <MasukDialog open={loginOpen} onOpenChange={ubahMasuk} />

            <Toaster />
        </div>
    );
}

/**
 * The flag that asks `/` to open the sign-in dialog.
 *
 * It is a parameter rather than a route because there is no route left to point at: the
 * exchange it used to start lives inside the dialog, and a visitor sent here by
 * `RequireAuth` or by an expired session is on the landing page either way. The value is
 * `1` and nothing else, so a stray `?masuk=0` left by a bookmark is not read as a request.
 */
const MASUK_PARAM = 'masuk';

/** Whether this address is asking for the dialog to be open. See {@link MASUK_PARAM}. */
function masukDitanya(params: URLSearchParams): boolean {
    return params.get(MASUK_PARAM) === '1';
}

/**
 * The address of the third body this page no longer has: a profile opened in place over
 * the list it came from.
 *
 * `/?dokter={id}` was this app's own writing while `ProfilDokterInline` lived here, so it
 * sits in history and in links people already sent. The October 2026 redesign gives every
 * profile a page of its own at `/dokter/{id}`, so the old address becomes the new one
 * rather than falling through to the marketing sections - which is what an unmatched
 * parameter would otherwise do, silently, to a link somebody kept.
 *
 * The redirect is `replace` for the same reason `alihDirektoriLama` uses it: a visitor
 * arriving from a bookmark must not press Kembali and watch the address they came from
 * appear to change its mind.
 *
 * An unusable `dokter` value is passed through untouched. `DoctorDetailPage` already
 * answers a doctor it cannot find with a real error state, and that is a better reply than
 * a redirect that quietly answered "no such parameter".
 */
function alihProfilInline(params: URLSearchParams): string | null {
    const id = params.get('dokter');

    if (id === null || id === '') {
        return null;
    }

    return `/dokter/${id}`;
}

/**
 * The one landing address that used to OPEN the directory, turned into the address the
 * directory now is.
 *
 * `/?direktori=semua` was this app's own writing - "Lihat semua dokter", the drawer, an
 * empty search - so it sits in history, in bookmarks and in links people already sent. It
 * becomes `/?semua=1`, which is the same promise with the parameter the directory actually
 * reads, and anything else on the address is carried across: a redirect that dropped the
 * filter would answer half a question.
 *
 * `?search=` is deliberately NOT rewritten away. This page never writes one (F03 §9), but
 * dropping one an older build already wrote would trade a privacy rule for a wrong
 * answer: the visitor asked for "Rina" and would be shown everybody.
 */
function alihDirektoriLama(params: URLSearchParams): string | null {
    if (!params.has('direktori')) {
        return null;
    }

    const berikut = new URLSearchParams(params);

    berikut.delete('direktori');
    berikut.set('semua', '1');

    return `/?${berikut.toString()}`;
}
