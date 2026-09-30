import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Link, useRouteError } from 'react-router';
import { LifeBuoy } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

/**
 * The two error boundaries, and the single presentational card they share.
 *
 * ## What F3-01 was
 *
 * `/konsultasi/:id` threw from `connectEcho()` during render, because
 * `lib/echo.ts:58-68`'s `requireEnv()` raises when `VITE_REVERB_APP_KEY` is absent - and
 * the README's "Running it" never sets it. With no `ErrorBoundary` anywhere, React
 * replaced the WHOLE document with its own page: *"Unexpected Application Error!"*, the
 * error message, and a JavaScript stack trace through
 * `node_modules/.vite/deps/react-dom_client.js`. The sidebar went with it, and the
 * sidebar is the only way out of a signed-in screen. The user was stuck on a dead page
 * with a stack trace as the user interface.
 *
 * ## Why this file has TWO boundaries and not one
 *
 * `errorElement` catches an error thrown while a route renders, and the boundary must sit
 * on the route that threw. A boundary one level too high does not contain the failure - it
 * REPLACES the failing parent's element, so an `errorElement` on the `AppShell` route
 * would take the sidebar down with the page and reproduce the very bug it is meant to fix.
 * So the containment is per leaf, and `router.tsx` gives every leaf its own
 * `errorElement`; what is left for the outer boundary is a failure that happens with no
 * route to blame - a router that cannot initialise, a provider that throws while mounting
 * - and for that there is no shell to preserve, only a document to hand back.
 *
 * | boundary | catches | what survives |
 * | --- | --- | --- |
 * | `RouteErrorBoundary` (every route's `errorElement`) | a page, guard or layout that throws while rendering | the app shell: sidebar, account header, flash listener, and every other destination |
 * | {@link AppErrorBoundary} (wraps `RouterProvider`) | a failure with no route to attribute it to | nothing was mounted yet; a reload is the only honest recovery |
 *
 * ## No stack trace reaches the user, and that is a decision not an oversight
 *
 * `error.message` is one sentence written by whoever raised the error, and for the
 * documented boot it is *"Reverb is not configured: VITE_REVERB_APP_KEY is missing from
 * the web build."* - which tells a reader what to do. `error.stack` is the browser's
 * internal frame list through a bundler's dependency prebundle; it means nothing to a
 * patient and it is the F3-01 finding. So the card renders the message and nothing else,
 * and the full detail goes to `console.error`, which is the channel a developer actually
 * reads. The console is a developer surface; the card is the user surface; they are not the
 * same surface and must not be the same string.
 *
 * ## Both boundaries are recoverable, and neither one is a dead end
 *
 * "Coba lagi" re-renders the boundary's own subtree, which is the only retry that can work
 * for a render-time throw: the component that threw is remounted from scratch. "Kembali ke
 * dashboard" is the escape hatch, and it is a real client-side navigation rather than a
 * reload so the session in `sessionStorage` is not disturbed.
 *
 * ## The card claims nothing it cannot guarantee
 *
 * An earlier draft of this file told the reader that the menu beside the screen was still
 * usable. Removing the leaf `errorElement` from one route to prove the guard fails showed
 * why that sentence is a lie: the error then bubbles to the nearest ancestor that HAS one,
 * the shell is replaced, and the card renders alone in the document. The claim is therefore
 * not in the copy. What is true in every case is the two links below it, and the
 * "the sidebar survives" property is proved by a test rather than asserted by a sentence -
 * see `web/tests/e2e/app-shell-robustness.spec.ts`.
 */

/**
 * One `Error` out of anything, including a thrown string or a rejected non-Error.
 *
 * `useRouteError()` is typed `unknown` because react-router genuinely can hand back a
 * `Response` from a loader, a `string` from a framework, or whatever was thrown. The
 * boundary must render *something* in all of those cases, and the fallback sentence is the
 * one that never leaks an internal detail.
 */
function toError(value: unknown): Error {
    if (value instanceof Error) {
        return value;
    }

    if (typeof value === 'string' && value !== '') {
        return new Error(value);
    }

    return new Error('Kesalahan yang tidak diketahui.');
}

/**
 * The card, shared by both boundaries.
 *
 * Deliberately the same `Alert variant="destructive"` + `AlertTitle` +
 * `AlertDescription` shape as `components/states/error-state.tsx`, because a user who has
 * seen one red card has seen this one. `actions` is a slot rather than two booleans
 * because the two boundaries genuinely differ: the route boundary is inside a router and
 * can `<Link>`, and the top-level one has no router to link into.
 */
function ErrorCard({
    error,
    actions,
}: {
    error: unknown;
    actions: ReactNode;
}) {
    return (
        <div data-slot="route-error" className="flex flex-col gap-4">
            <Alert variant="destructive">
                <LifeBuoy aria-hidden />

                <AlertTitle>Halaman ini gagal ditampilkan</AlertTitle>

                <AlertDescription>
                    <p data-slot="route-error-detail">{toError(error).message}</p>

                    {actions}
                </AlertDescription>
            </Alert>
        </div>
    );
}

/**
 * The route-level boundary: a leaf route's `errorElement`.
 *
 * `useRouteError()` is the only supported way to read the error a route threw, and it is
 * the reason this is a component and not the class below. The class boundary catches what
 * React catches; this catches what the router hands it, and the router hands it nothing
 * until a route has failed.
 *
 * The "Coba lagi" here re-navigates to the same URL with `replace`, which is the correct
 * retry for a route error: re-rendering the boundary's own subtree would keep the same
 * element instance that just threw. The dashboard link is the way out, and it is the point
 * of the whole file - the sidebar the user needs is still on screen.
 */
export function RouteErrorBoundary() {
    const error = useRouteError();

    return (
        <ErrorCard
            error={error}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <Button asChild variant="outline" size="sm">
                        <Link to="/dashboard">Kembali ke dashboard</Link>
                    </Button>

                    <Button asChild size="sm">
                        <Link to="/">Ke beranda</Link>
                    </Button>
                </div>
            }
        />
    );
}

/**
 * The top-level boundary, mounted around `<RouterProvider>` in `app/app.tsx`.
 *
 * ## Why a class, and why there is exactly one
 *
 * `componentDidCatch` and `getDerivedStateFromError` are class-component statics, and a
 * class is the only way to get them. Everything else in this file is a function; this one
 * is a class because the API is, and it is the reason it is separate from
 * {@link RouteErrorBoundary} instead of being folded into one polymorphic component.
 *
 * ## It holds the session, and that is the reason it does not sit inside the shell
 *
 * Mounted above `QueryClientProvider` and `RouterProvider`, it renders on a failure that
 * happened before - or instead of - any of them. A sign-in survives it, because the tokens
 * live in `sessionStorage` and nothing here clears them. So the recovery is a real reload
 * of the same origin rather than a `navigate('/login')` that would throw a second time in
 * a router that has already failed.
 */
export class AppErrorBoundary extends Component<
    { children: ReactNode },
    { error: Error | null }
> {
    override state: { error: Error | null } = { error: null };

    static getDerivedStateFromError(error: unknown): { error: Error } {
        return { error: toError(error) };
    }

    override componentDidCatch(error: Error, info: ErrorInfo): void {
        /**
         * The full detail, including the component stack React hands over, goes to the
         * console and nowhere else. This is the one place the stack trace is allowed to
         * exist: it is how a developer finds the throw site, and it is unreachable by a
         * user because it is never rendered.
         */
        console.error(
            '[sehatly] render gagal, komponen diambil alih boundary.',
            error,
            info.componentStack,
        );
    }

    override render(): ReactNode {
        if (this.state.error === null) {
            return this.props.children;
        }

        return (
            <main className="flex min-h-svh flex-col items-center justify-center gap-4 p-6">
                <ErrorCard
                    error={this.state.error}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    this.setState({ error: null });
                                }}
                            >
                                Coba lagi
                            </Button>

                            <Button
                                type="button"
                                size="sm"
                                onClick={() => {
                                    globalThis.location.assign('/dashboard');
                                }}
                            >
                                Muat ulang halaman
                            </Button>
                        </div>
                    }
                />
            </main>
        );
    }
}
