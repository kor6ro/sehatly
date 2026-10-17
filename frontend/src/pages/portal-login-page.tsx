import { useState } from 'react';
import { Navigate } from 'react-router';
import { MasukDialog } from '@/components/auth/masuk-dialog';
import { Button } from '@/components/ui/button';
import { useSession } from '@/hooks/use-session';
import { PORTAL_NAMA, type Portal } from '@/lib/portal';

/**
 * The sign-in surface of a staff portal: the SAME `MasukDialog` the patient landing
 * page opens, over a portal-branded frame.
 *
 * ## One door, not five (the plan's section 2.5, as amended)
 *
 * The plan sketched five per-portal pages with phone + password + optional 2FA per
 * portal. That was rejected in the round's own design review: the backend has exactly
 * one sign-in exchange (`POST /auth/masuk` -> OTP -> verify), a second credential type
 * would be a client invention no endpoint verifies, and five pages would be five
 * copies of one dialog. What the portals gained instead is the part that matters -
 * `RequirePortalAuth` reads the verified account's `tipe` from `/me` and shows the
 * wrong-portal page to anyone who signed into the wrong doorway - so a single door per
 * portal loses nothing it ever had.
 *
 * ## Why a page around the dialog at all
 *
 * On a staff subdomain there is no landing page to host the dialog (the marketing
 * sections are the patient portal's), and a dialog floating over a blank document is
 * not a login screen. This frame gives the portal a name, one sentence of instruction,
 * and the dialog open on arrival - and the same re-open button `LandingPage` keeps
 * after a close, so dismissing the dialog is never a dead end.
 *
 * ## Where the visitor lands after verifying
 *
 * Inside `MasukDialog`: `/` for a finished account, `/profil/edit/{id}?sign_up=true`
 * for a shell. Both resolve on every staff portal table - `/` redirects to
 * `PORTAL_HOME` under the guard, and `/profil/edit/:userId` is registered outside the
 * shell for the completion form - so nobody exits this flow onto a blank address. A
 * session that already exists is bounced straight to `/`: signing in is not something
 * to offer somebody who is signed in, and `/` resolves to the workspace or to the
 * wrong-portal page, which is the honest answer either way.
 */
export function PortalLoginPage({ portal }: { portal: Portal }) {
    const adaSesi = useSession();
    const [dibuka, setDibuka] = useState(true);

    if (adaSesi) {
        return <Navigate to="/" replace />;
    }

    return (
        <main className="flex min-h-screen flex-col items-center justify-center gap-6 p-4">
            <img src="/logo.svg" alt="Sehatly" className="size-10" />

            <div className="text-center">
                <h1 className="text-lg font-semibold">Masuk ke {PORTAL_NAMA[portal]}</h1>

                <p className="text-muted-foreground text-sm">
                    Gunakan nomor telepon yang terdaftar untuk portal ini.
                </p>
            </div>

            {/**
             * The dialog is open on arrival (the `/?masuk=1` flag's job on the patient
             * portal); closing it leaves this button rather than an empty screen.
             */}
            {dibuka ? (
                <MasukDialog open onOpenChange={setDibuka} />
            ) : (
                <Button type="button" onClick={() => setDibuka(true)}>
                    Masuk
                </Button>
            )}
        </main>
    );
}
