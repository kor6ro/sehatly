import { useState } from 'react';
import { Link, NavLink, useNavigate } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import {
    CalendarDays,
    ChevronDown,
    FileText,
    LayoutDashboard,
    LogOut,
    Menu,
    MessagesSquare,
    Pill,
    Settings,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { logout } from '@/lib/api/auth';
import { meOptions } from '@/lib/api/me';
import { clearTokens, getRefreshToken } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
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
import { useSession } from '@/hooks/use-session';
import { cn } from '@/lib/utils';

/**
 * The header for `/` - the landing page, which is home to every visitor: signed out,
 * signed in as a patient, or signed in as a doctor.
 *
 * ## Why this is not `AppShell`'s header
 *
 * `AppShell` renders a sidebar and an account header, both of which need a signed-in
 * account to mean anything, and its sidebar would put a patient workspace in front of
 * somebody who has not asked for it. The landing page needs the opposite: a way in, a way
 * to browse the public directory, and - once there IS a session - the account's own name
 * instead of the button that used to be there. So this is a second, much smaller frame
 * rather than a conditional branch inside the shell.
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

/**
 * The full nav, rendered inline on desktop and inside the sheet on mobile.
 *
 * ## Why there is no "Untuk Dokter" menu
 *
 * The practitioner entrances (`/dokter/dashboard`, `/dokter/booking`) and the whole
 * `/admin` group are being kept out of the public face until they get a dedicated door
 * of their own: what the landing page shows is the PATIENT product. The routes are not
 * deleted, only unadvertised - they stay registered in `app/router.tsx` and still answer
 * the `tipe:`/`permission:` guards the server puts on them, so `/admin/hero` opens by URL
 * today and a labelled entrance can be re-added later without touching a route.
 */
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
        </nav>
    );
}

/**
 * The right-hand action cluster.
 *
 * The single decision it makes is what the visitor is here to do: nobody has a session,
 * so the action is "Masuk"; somebody does, and it collapses into the account pill below.
 *
 * ## Why this is a hook and not `getAccessToken() !== null`
 *
 * A plain read during render is correct and permanently stale - the token is written by
 * the OTP step and by `clearTokens()`, neither of which renders anything. Two screens
 * broke that way: a visitor who signed in through the dialog on this very page kept the
 * "Masuk" button, and a visitor who signed out left the pill's `/me` query subscribed,
 * so its refetch fired with no credentials and the transport, reading that as a session
 * expiry, moved them to `/login`. `useSession` subscribes to the store instead, which
 * makes both directions a re-render of exactly this component.
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
    const authenticated = useSession();

    if (authenticated) {
        return (
            <div className={cn('flex items-center gap-2', className)}>
                <AkunPill />
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

/** The two initials a name contributes: the first letters of its first two words. */
function inisial(nama: string): string {
    return nama
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((kata) => kata.charAt(0).toUpperCase())
        .join('');
}

/**
 * The signed-in half of {@link ActionButtons}: avatar, name, gear, chevron.
 *
 * ## Why a pill and not the "Dashboard" button it replaces
 *
 * The landing page is now the home every account returns to after signing in, so its
 * header has to answer "who am I?" rather than "where do I go?" - the same reason the
 * reference front page shows a name and a gear instead of a call to action. The workspace
 * is one menu entry away instead of being the whole bar, and a visitor who has not signed
 * in still sees the one button they need.
 *
 * ## Why `Profil` is conditional and `Dasbor` is not
 *
 * `GET /pasien/profil` answers 403 to every account without a `pasien` row, so offering
 * it to a doctor would render a link to a refusal. `Dasbor` is the one screen every
 * `users.tipe` can open - the sidebar behind it is simply different per role - which is
 * the same rule `AppShell` follows: a control that may not be used is not rendered.
 *
 * ## Why `/me` is a query and not a prop
 *
 * The header has no parent that knows the account: it renders on `/`, outside `AppShell`
 * and outside `RequireAuth`. `meOptions()` shares its cache key with every other reader of
 * "who am I", so the name here is the same object the dashboard greets with, and signing
 * out clears it along with everything else.
 */
function AkunPill() {
    const navigate = useNavigate();
    const adaSesi = useSession();

    /**
     * `enabled` keyed on the session, never `true`.
     *
     * The parent unmounts this pill the moment `clearTokens()` announces, but the two
     * updates race: if this component renders once in between, a query that refetches on
     * an empty cache would call `/me` with no `Authorization` header, and the transport
     * answers that 401 by declaring the session expired and moving the page to `/login` -
     * which is how signing OUT of the landing page used to land you on the sign-IN
     * screen. Gated on the session, that refetch cannot start at all.
     */
    const me = useQuery({ ...meOptions(), enabled: adaSesi });

    const user = me.data?.data.user ?? null;
    const nama = user?.nama_lengkap ?? '';
    const isPasien = user?.tipe === 'pasien';

    const signOut = useMutation({
        mutationFn: async () => {
            const refreshToken = getRefreshToken();

            if (refreshToken === null) {
                return null;
            }

            return logout(refreshToken);
        },
        /**
         * `onSettled`, not `onSuccess`, for the reason `AppShell` gives: the point of this
         * branch is the local pair. A sign-out the server rejects must still remove the
         * token from this browser, and it must land the visitor on the landing page
         * either way - where, without a token, the pill is replaced by "Masuk".
         */
        onSettled: () => {
            clearTokens();
            queryClient.clear();

            dispatchFlash({ level: 'info', message: 'Anda telah keluar.' });

            void navigate('/', { replace: true });
        },
    });

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    data-slot="landing-akun"
                    className="border-border bg-background hover:bg-secondary flex h-10 items-center gap-2 rounded-full border py-1 pl-1 pr-2.5 text-sm font-medium"
                >
                    <span className="bg-primary text-primary-foreground flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                        {nama === '' ? <UserRound className="size-4" /> : inisial(nama)}
                    </span>

                    <span className="max-w-[9rem] truncate md:max-w-[12rem]">
                        {nama === '' ? 'Akun saya' : nama}
                    </span>

                    <span aria-hidden="true" className="bg-border h-5 w-px" />

                    <Settings className="text-muted-foreground size-4 shrink-0" />
                    <ChevronDown className="text-muted-foreground size-4 shrink-0" />
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-60 p-1.5">
                <DropdownMenuLabel className="text-muted-foreground truncate px-2 py-1.5 text-xs font-medium">
                    {nama === '' ? 'Akun Sehatly' : nama}
                </DropdownMenuLabel>

                <DropdownMenuItem asChild>
                    <Link to="/dashboard" className="gap-3 px-2 py-2.5">
                        <span className="bg-secondary flex size-8 shrink-0 items-center justify-center rounded-md">
                            <LayoutDashboard className="text-primary size-4" />
                        </span>

                        <span className="truncate text-sm font-medium">Dasbor</span>
                    </Link>
                </DropdownMenuItem>

                {isPasien ? (
                    <DropdownMenuItem asChild>
                        <Link to="/profil" className="gap-3 px-2 py-2.5">
                            <span className="bg-secondary flex size-8 shrink-0 items-center justify-center rounded-md">
                                <UserRound className="text-primary size-4" />
                            </span>

                            <span className="truncate text-sm font-medium">Profil</span>
                        </Link>
                    </DropdownMenuItem>
                ) : null}

                <DropdownMenuItem
                    data-slot="landing-akun-keluar"
                    disabled={signOut.isPending}
                    onSelect={() => signOut.mutate()}
                    className="gap-3 px-2 py-2.5"
                >
                    <span className="bg-secondary flex size-8 shrink-0 items-center justify-center rounded-md">
                        <LogOut className="text-primary size-4" />
                    </span>

                    <span className="truncate text-sm font-medium">Keluar</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * `onMasuk` belongs to the page, not to the header.
 *
 * The landing body has its own "Masuk" action, and two `LoginDialog` instances would each
 * hold their own challenge and race over the one `sessionStorage` key that carries it -
 * so the page owns the dialog and hands the opener down. The header renders it nowhere.
 */
export function LandingHeader({ onMasuk }: { onMasuk: () => void }) {
    const [open, setOpen] = useState(false);

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

                <ActionButtons onMasuk={onMasuk} />

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
                                onMasuk();
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
            </div>
        </header>
    );
}
