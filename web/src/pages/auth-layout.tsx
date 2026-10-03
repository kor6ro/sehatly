import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { cn } from '@/lib/utils';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * The layout for the two screens that exist before a session does: `/login` and `/otp`.
 *
 * ## Why these are not in `AppShell`
 *
 * `AppShell` renders the sidebar and the account header, both of which need a signed-in
 * account to mean anything. Rendering a patient navigation to a visitor who has just
 * typed a wrong password is worse than rendering nothing, so the pre-session screens get
 * their own frame and the shell mounts only behind `RequireAuth`.
 *
 * `ProfilEditPage` is deliberately NOT one of them: it runs after `otp/verify`, so the
 * token is real by the time it renders. It still carries its own thin bar rather than
 * `AppShell`, because the account reaching it has no patient record for the shell to
 * navigate to yet.
 *
 * ## The visual weight is on the form
 *
 * The card is centred in a min-height viewport with the product name above it, so the
 * first thing on screen is the one control the user came for. Everything here is composed
 * from the design system's existing tokens - `bg-muted/30`, `text-muted-foreground`,
 * `rounded-xl` via `Card` - and adds no new colour or spacing value.
 */
export function AuthLayout({
    title,
    description,
    children,
    footer,
    className,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    /** The line under the card. There is no second door to cross-link to any more. */
    footer?: ReactNode;
    className?: string;
}) {
    return (
        <main
            data-slot="auth-layout"
            className={cn(
                'bg-muted/30 flex min-h-screen flex-col items-center justify-center gap-6 px-4 py-10',
                className,
            )}
        >
            <div className="flex flex-col items-center gap-1 text-center">
                <img
                    src="/logo.svg"
                    alt="Sehatly"
                    className="size-12"
                />

                <Link
                    to="/"
                    className="text-foreground text-xl font-semibold tracking-tight"
                >
                    Sehatly
                </Link>

                <p className="text-muted-foreground text-sm">
                    Telemedicine untuk pasien Indonesia
                </p>
            </div>

            <Card className="w-full max-w-md">
                <CardHeader>
                    <CardTitle className="text-lg">{title}</CardTitle>

                    {description === undefined ? null : (
                        <CardDescription>{description}</CardDescription>
                    )}
                </CardHeader>

                <CardContent className="flex flex-col gap-4">{children}</CardContent>
            </Card>

            {footer === undefined ? null : (
                <p className="text-muted-foreground text-sm">{footer}</p>
            )}
        </main>
    );
}
