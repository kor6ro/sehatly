import { useState } from 'react';
import { Link, NavLink } from 'react-router';
import {
    ArrowRight,
    CalendarDays,
    ChevronDown,
    FileText,
    Menu,
    MessagesSquare,
    Pill,
    Stethoscope,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { ThemeToggle } from '@/components/layout/theme-toggle';
import { LoginDialog } from '@/components/auth/login-dialog';
import { getAccessToken } from '@/lib/token';
import { cn } from '@/lib/utils';

/**
 * The header for the pre-session surfaces - today `/`, the landing page.
 *
 * ## Why this is not `AppShell`'s header
 *
 * `AppShell` renders a sidebar and an account header, both of which need a signed-in
 * account to mean anything. A visitor who has not authenticated yet needs the opposite:
 * a way in, a way to browse the public directory, and no account chrome at all. So this
 * is a second, much smaller frame rather than a conditional branch inside the shell.
 *
 * ## The shape is borrowed; the labels are ours
 *
 * The layout follows the Indonesian telemedicine convention this product competes in:
 * mark and wordmark on the left, a short nav of service entry points, one filled
 * call-to-action on the right. Two deliberate differences from the reference:
 *
 * - No endorsement badge. The reference shows a ministry-of-health endorsement. Claiming
 *   one here would be a statement of fact about Sehatly that nobody has made, so the slot
 *   simply does not exist in this component.
 * - Every menu entry points at a route that is registered in `app/router.tsx`. There are
 *   no placeholder links: a signed-out visitor who picks "Chat dengan Dokter" lands on
 *   `RequireAuth`, which hands them to `/login` - the correct outcome, reached through
 *   the router rather than through a link that goes nowhere.
 *
 * ## Why the wordmark is text and not a second logo file
 *
 * `public/logo.svg` was the Laravel/React scaffold logo until this branch replaced it,
 * which is a mismatch that only shows up in production: `npm run dev` serves
 * `web/public/logo.svg` (the Sehatly mark) while Apache serves `public/logo.svg`. Both
 * paths now hold the same bytes, and the wordmark is rendered as text so that the name
 * in the header can never disagree with the name in `<title>` again.
 */
type NavItem = {
    to: string;
    label: string;
    description: string;
    icon: LucideIcon;
};

/** Service entry points, each resolving to a real route. */
const LAYANAN: NavItem[] = [
    {
        to: '/konsultasi',
        label: 'Chat dengan Dokter',
        description: 'Konsultasi teks & video',
        icon: MessagesSquare,
    },
    {
        to: '/booking',
        label: 'Booking Janji Temu',
        description: 'Pilih jadwal dokter',
        icon: CalendarDays,
    },
    {
        to: '/rekam-medis',
        label: 'Rekam Medis',
        description: 'Riwayat kesehatan Anda',
        icon: FileText,
    },
    {
        to: '/pasien/resep',
        label: 'Resep & Apotek',
        description: 'Antar obat ke rumah',
        icon: Pill,
    },
];

/** The practitioner side, mirroring the patient-side list above. */
const UNTUK_DOKTER: NavItem[] = [
    {
        to: '/dokter/dashboard',
        label: 'Dashboard Dokter',
        description: 'Antrean & pasien hari ini',
        icon: Stethoscope,
    },
    {
        to: '/dokter/booking',
        label: 'Jadwal Praktik',
        description: 'Kelola slot & hari libur',
        icon: CalendarDays,
    },
];

/** The one nav entry that needs no session at all. */
const DIREKTORI = '/dokter';

function navLinkClass({ isActive }: { isActive: boolean }): string {
    return cn(
        'inline-flex items-center rounded-lg px-3 py-2 text-[15px] font-medium transition-colors',
        isActive
            ? 'bg-secondary text-primary'
            : 'text-foreground/75 hover:bg-secondary hover:text-primary',
    );
}

/**
 * A nav entry with a disclosure chevron.
 *
 * `asChild` puts Radix's `data-state` on the `<Button>`, which is what the rotated
 * chevron keys off (`group-data-[state=open]:rotate-180`) - so the arrow turns with the
 * menu without a second source of truth for "is this open?".
 */
function NavDropdown({
    label,
    items,
    onNavigate,
}: {
    label: string;
    items: NavItem[];
    /** Closes the mobile sheet, where the same list renders inline. */
    onNavigate?: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    className="group gap-1 px-3 text-[15px] font-medium text-foreground/75 hover:bg-secondary hover:text-primary"
                >
                    {label}
                    <ChevronDown className="size-4 opacity-60 transition-transform group-data-[state=open]:rotate-180" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" className="w-72 p-1.5">
                <DropdownMenuLabel className="text-muted-foreground px-2 py-1.5 text-xs font-medium">
                    {label}
                </DropdownMenuLabel>

                {items.map((item) => (
                    <DropdownMenuItem key={item.to} asChild>
                        <Link
                            to={item.to}
                            onClick={onNavigate}
                            className="gap-3 px-2 py-2.5"
                        >
                            <span className="bg-secondary flex size-8 shrink-0 items-center justify-center rounded-md">
                                <item.icon className="text-primary size-4" />
                            </span>

                            <span className="flex min-w-0 flex-col">
                                <span className="truncate text-sm font-medium">
                                    {item.label}
                                </span>

                                <span className="text-muted-foreground truncate text-xs">
                                    {item.description}
                                </span>
                            </span>
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The full nav, rendered inline on desktop and inside the sheet on mobile. */
function NavList({
    className,
    onNavigate,
}: {
    className?: string;
    onNavigate?: () => void;
}) {
    return (
        <nav
            aria-label="Navigasi utama"
            className={cn('flex items-center gap-1', className)}
        >
            <NavLink to={DIREKTORI} className={navLinkClass} onClick={onNavigate}>
                Direktori Dokter
            </NavLink>

            <NavDropdown
                label="Layanan Kesehatan"
                items={LAYANAN}
                onNavigate={onNavigate}
            />

            <NavDropdown
                label="Untuk Dokter"
                items={UNTUK_DOKTER}
                onNavigate={onNavigate}
            />
        </nav>
    );
}

/**
 * The right-hand action cluster.
 *
 * The single decision it makes is what the visitor is here to do: nobody has a session,
 * so the action is "Masuk"; somebody does, and it collapses to one route into the app.
 * `getAccessToken()` is read on render rather than cached, because this header mounts
 * once per page load and the token is written by the OTP step in the same session.
 *
 * There is deliberately no "Daftar" button here, and "Masuk" opens a dialog rather than
 * navigating: the bar offers one door, which is how the front page this header is modelled
 * on behaves, and a visitor without an account meets "Nomor belum terdaftar" inside that
 * dialog with the one action that fixes it. `onMasuk` is handed in rather than this
 * component owning a dialog, because it renders twice - top bar and mobile sheet - and two
 * instances would each hold their own challenge and race over `sessionStorage`.
 */
function ActionButtons({
    className,
    onMasuk,
}: {
    className?: string;
    onMasuk: () => void;
}) {
    const authenticated = getAccessToken() !== null;

    if (authenticated) {
        return (
            <div className={cn('flex items-center gap-2', className)}>
                <Button asChild className="rounded-lg font-medium">
                    <Link to="/dashboard">
                        Dashboard
                        <ArrowRight className="size-4" />
                    </Link>
                </Button>
            </div>
        );
    }

    return (
        <div className={cn('flex items-center gap-2', className)}>
            <Button onClick={onMasuk} className="rounded-lg font-medium">
                Masuk
            </Button>
        </div>
    );
}

export function LandingHeader() {
    const [open, setOpen] = useState(false);
    const [loginOpen, setLoginOpen] = useState(false);

    return (
        <header
            data-slot="landing-header"
            className="bg-card/95 border-b supports-[backdrop-filter]:bg-card/80 sticky top-0 z-40 w-full backdrop-blur"
        >
            <div className="mx-auto flex h-16 max-w-[1280px] items-center gap-3 px-4 md:h-[72px] md:gap-6 md:px-6">
                <Link
                    to="/"
                    className="flex shrink-0 items-center gap-2"
                    aria-label="Sehatly - beranda"
                >
                    <img
                        src="/logo.svg"
                        alt=""
                        aria-hidden="true"
                        className="size-9 shrink-0"
                    />

                    <span className="text-foreground text-xl font-bold tracking-tight md:text-[1.35rem]">
                        Sehatly
                    </span>
                </Link>

                <NavList className="hidden lg:flex" />

                {/*
                    `ml-auto` lives on the toggle rather than on a wrapper around the
                    whole right-hand cluster: it pushes the toggle, the action buttons
                    and the menu button to the right without re-indenting the sheet
                    below, and it keeps the nav glued to the logo when it appears.
                */}
                <ThemeToggle className="ml-auto" />

                <ActionButtons onMasuk={() => setLoginOpen(true)} />

                <Sheet open={open} onOpenChange={setOpen}>
                    <SheetTrigger asChild>
                        <Button
                            variant="outline"
                            size="icon"
                            className="lg:hidden"
                            aria-label="Buka menu navigasi"
                        >
                            <Menu className="size-5" />
                        </Button>
                    </SheetTrigger>

                    <SheetContent side="right" className="w-[300px] sm:w-[340px]">
                        <SheetHeader>
                            <SheetTitle className="flex items-center gap-2">
                                <img
                                    src="/logo.svg"
                                    alt=""
                                    aria-hidden="true"
                                    className="size-7"
                                />

                                <span className="text-lg font-bold tracking-tight">
                                    Sehatly
                                </span>
                            </SheetTitle>
                        </SheetHeader>

                        <NavList
                            className="flex-col items-stretch gap-1 px-4"
                            onNavigate={() => {
                                setOpen(false);
                            }}
                        />

                        <ActionButtons
                            className="mt-auto px-4 pb-4"
                            onMasuk={() => {
                                // The sheet closes first: two overlays stacked on one
                                // Escape press is a fight the user always loses.
                                setOpen(false);
                                setLoginOpen(true);
                            }}
                        />
                        {/*
                            No custom close button here: `SheetContent` already renders
                            `SheetPrimitive.Close` (with a screen-reader "Tutup" label) at
                            `absolute top-4 right-4`. Adding a second one only stacks two
                            X icons on the same coordinates - which is exactly what the
                            first draft of this component did.
                        */}
                    </SheetContent>
                </Sheet>

                {/*
                    One dialog for the whole header. Both "Masuk" buttons reach it through
                    `onMasuk`, so the top bar and the sheet cannot each be mid-challenge at
                    the same time.
                */}
                <LoginDialog open={loginOpen} onOpenChange={setLoginOpen} />
            </div>
        </header>
    );
}
