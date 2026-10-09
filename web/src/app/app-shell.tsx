import type { LucideIcon } from 'lucide-react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router';
import {
    AlarmClock,
    Bell,
    CalendarDays,
    ClipboardCheck,
    ClipboardList,
    FileHeart,
    HeartPulse,
    LogOut,
    MessagesSquare,
    MonitorSmartphone,
    Package,
    Pill,
    ShieldAlert,
    ShieldCheck,
    Stethoscope,
} from 'lucide-react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { logout } from '@/lib/api/auth';
import { meOptions } from '@/lib/api/me';
import { clearTokens, getRefreshToken } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Toaster } from '@/components/ui/sonner';
import { NotificationBell } from '@/features/notifikasi/notification-bell';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarSeparator,
    SidebarTrigger,
    useSidebar,
} from '@/components/ui/sidebar';

/**
 * The chrome around every signed-in screen.
 *
 * ## The flash listener lives in `Toaster`, and only there
 *
 * `hooks/use-flash-toast.ts` listens for the `sehatly:flash` DOM event because the
 * Inertia-era source it replaced does not exist in a Vite SPA. The listener is mounted by
 * `components/ui/sonner.tsx`'s `Toaster`, which is the component that renders the toasts
 * and is mounted once per signed-in screen. This shell used to call `useFlashToast()` as
 * well, which registered a **second** listener and rendered every flash twice; the
 * duplicate is removed here rather than in the kit, so the kit stays the single owner of
 * its own feed.
 *
 * ## Why the nav is a hand-written list and not a generated one
 *
 * There are sixteen destinations and no nested sections, so a generated nav would be
 * sixteen lines of configuration plus a component to interpret it. A list is the honest
 * amount of machinery for this many items.
 *
 * ## What the menu shows: the patient product, and nothing else
 *
 * Every `users.tipe` now sees the patient menu, and an account that is not a patient
 * sees the two destinations all of them may open. The practitioner and clinic entrances
 * (the old admin nav, `/dokter/dashboard`, `/dokter/booking`, `/apotek/resep`, "Tulis
 * resep") are held back until they get a door of their own; the routes stay registered
 * and server-guarded, so they still answer by URL. The full reasoning sits on the
 * `<SidebarContent>` block below, next to the branch it describes.
 *
 * ## No destination carries a made-up id (F3-06)
 *
 * Six of the links used to end in a hardcoded `/1` - `/konsultasi/1`, `/rekam-medis/1`,
 * `/checkout/1`, `/pesanan/1`, `/pembayaran/1` and the doctor's `/konsultasi/1/resep`. Id
 * `1` is a row in somebody else's tenant, so every one of them was a 404 card for every
 * other account. Each now points at the id-free index route beside it, and each of those
 * resolves the caller's own real ids from `GET /api/v1/pasien/resep` or says plainly that
 * the API publishes no list. See `lib/api/tujuan.ts` for the resolver and
 * `.omo/evidence/F3B-web-robustness.md` for the two destinations that need a backend
 * change and therefore do not have one.
 *
 * ## This is the same markup on a phone as on a desktop (F3-04)
 *
 * `components/ui/sidebar.tsx` renders this exact subtree into a Radix `Sheet` below
 * 768 px, so the drawer cannot drift from the sidebar: there is one nav, rendered twice by
 * the kit. What the shell adds is {@link MobileNavBar} - the trigger the kit expects and
 * this shell never rendered.
 */
export function AppShell() {
    return (
        <SidebarProvider>
            <SignedInLayout />
        </SidebarProvider>
    );
}

function SignedInLayout() {
    return (
        <div className="flex min-h-screen w-full">
            <AppSidebar />

            <SidebarInset>
                <MobileNavBar />

                <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                    <Outlet />
                </main>
            </SidebarInset>

            <Toaster />
        </div>
    );
}

/**
 * The navigation bar that only exists below the `md` breakpoint.
 *
 * ## F3-04: at 390 px the sidebar was not rendered and nothing replaced it
 *
 * `components/ui/sidebar.tsx` is the shadcn sidebar, and below 768 px it renders its
 * content into a Radix `Sheet` that is **closed** until something opens it. The kit ships
 * `SidebarTrigger` for exactly that, and this shell rendered none - so on a phone the whole
 * menu was mounted and invisible, and a patient could reach three of thirteen destinations
 * by typing a URL. Measured at 390 px before this fix: no `aside`, no
 * `[data-sidebar="trigger"]`, and no open `dialog`.
 *
 * The fix is the kit's own trigger in the kit's own `Sheet`, not a hand-rolled drawer: a
 * second navigation surface would be a second visual language on the one screen a phone
 * user lives in.
 *
 * ## `md:hidden`, so a desktop is unchanged
 *
 * From 768 px up the fixed sidebar is visible again and a trigger would be a second,
 * redundant way to open the same panel, so the bar disappears rather than sitting there
 * offering a duplicate control.
 *
 * ## The notification bell stays in the sidebar footer, and is NOT duplicated here
 *
 * Two bells would be two live `GET /notifikasi` subscriptions with two unread counts on one
 * screen. Inside the sheet the footer is one scroll away, and a phone drawer is a
 * full-height panel, so nothing is hidden by leaving the bell there.
 */
function MobileNavBar() {
    return (
        <div
            data-slot="mobile-nav"
            className="bg-background sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b border-border px-3 md:hidden"
        >
            <SidebarTrigger className="size-9 shrink-0" />

            <img
                src="/logo.svg"
                alt="Sehatly"
                className="size-7 shrink-0"
            />

            <span className="text-sm font-semibold">Sehatly</span>
        </div>
    );
}

/**
 * Whether a destination is the one the user is looking at.
 *
 * A path-segment comparison rather than a bare `startsWith`, so `/checkout` does not light
 * up while the user reads `/checkout/12`, and `/dashboard` - the one destination that is a
 * sibling of nothing - cannot match a path that merely begins with the same letters.
 *
 * `NavLink` still emits its own `aria-current`, so assistive technology is unaffected; this
 * only drives the visible `data-active` styling `SidebarMenuButton` applies, which without
 * it marked nothing at all.
 */
function tujuanAktif(pathname: string, tujuan: string): boolean {
    if (tujuan === '/dashboard') {
        return pathname === '/dashboard';
    }

    return pathname === tujuan || pathname.startsWith(`${tujuan}/`);
}

/**
 * One destination, in the shape both the fixed panel and the mobile `Sheet` need.
 *
 * `onClick` is the mobile half of the fix and is inert on a desktop: closing a `Sheet`
 * nobody opened is a state write on state that is already `false`.
 */
function MenuLink({
    to,
    icon: Ikon,
    label,
    pathname,
    onNavigate,
}: {
    to: string;
    icon: LucideIcon;
    label: string;
    pathname: string;
    onNavigate: () => void;
}) {
    return (
        <SidebarMenuItem>
            <SidebarMenuButton asChild isActive={tujuanAktif(pathname, to)}>
                <NavLink to={to} onClick={onNavigate}>
                    <Ikon />

                    {label}
                </NavLink>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

function AppSidebar() {
    const navigate = useNavigate();
    const location = useLocation();
    const me = useQuery(meOptions());
    const { setOpenMobile } = useSidebar();

    const pathname = location.pathname;

    /**
     * Navigating from the mobile drawer has to close it.
     *
     * The `Sheet` does not close itself on a link click, so without this the user picks a
     * destination, the page changes behind a panel that is still covering it, and the only
     * way forward is the overlay.
     */
    const tutupDrawer = (): void => {
        setOpenMobile(false);
    };

    const signOut = useMutation({
        mutationFn: async () => {
            const refreshToken = getRefreshToken();

            if (refreshToken === null) {
                return null;
            }

            return logout(refreshToken);
        },
        /**
         * `onSettled`, not `onSuccess`: the point of this branch is the local state, and a
         * sign-out that fails server-side must still remove the pair from this browser.
         * Leaving a token behind after the user pressed "Keluar" is the worse of the two
         * outcomes.
         */
        onSettled: () => {
            clearTokens();
            queryClient.clear();

            dispatchFlash({ level: 'info', message: 'Anda telah keluar.' });

            void navigate('/login', { replace: true });
        },
    });

    const user = me.data?.data.user ?? null;

    /**
     * The only branch the sidebar still needs: is this account a patient?
     *
     * Every other `users.tipe` used to carry its own group here - `admin` had its own
     * nav (`/admin/dokter`, `/admin/laporan`, `/admin/audit-log`, `/admin/hero`),
     * `dokter` had "Dasbor dokter" and "Tulis resep", `apoteker` had the pharmacy
     * queue - and all of those are held back together, because what the product
     * presents at the moment is the PATIENT experience and those entrances have not
     * been given a door of their own yet. The server-side guards behind them are
     * untouched (`tipe:` and `permission:` in `routes/api.php`), so hiding a menu
     * costs nothing but a label: the route still answers the account it always
     * answered, by URL.
     */
    const isPasien = user?.tipe === 'pasien';

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <p className="flex items-center gap-2 px-2 text-sm font-semibold">
                    <img
                        src="/logo.svg"
                        alt="Sehatly"
                        className="size-7 shrink-0"
                    />

                    Sehatly
                </p>

                <p className="text-muted-foreground truncate px-2 text-xs">
                    {user?.nama_lengkap ?? 'Memuat akun...'}
                </p>
            </SidebarHeader>

            <SidebarSeparator />

            <SidebarContent>
                {/**
                 * The whole visible menu is the PATIENT product: the patient menu for a
                 * patient, and the two destinations every `users.tipe` may open for
                 * anyone else (see the branch at the bottom).
                 *
                 * The practitioner and clinic entrances used to live here too: an
                 * `admin` nav (`/admin/dokter`, `/admin/laporan`, `/admin/audit-log`,
                 * `/admin/persetujuan-pdp`, `/admin/hero`), a "Dokter" group
                 * (`/dokter/dashboard`), "Booking masuk" (`/dokter/booking`), "Tulis
                 * resep" and "Antrean apoteker" (`/apotek/resep`). All of them are held
                 * back until they get a dedicated door of their own, because what this
                 * build presents is the patient product and an entrance without a door
                 * is not an entrance, it is a stray link.
                 *
                 * This is F14's own RBAC rule - "a control the account may not use is
                 * not rendered" - applied to the whole role surface at once instead of
                 * link by link. The ROUTES are untouched: they stay registered in
                 * `app/router.tsx` and answer exactly the `tipe:`/`permission:` guards
                 * the server puts on them, so `/admin/hero` still opens by URL for the
                 * owner today, and re-adding a labelled entrance later is a change to
                 * this block alone - no router, no API, no migration.
                 *
                 * An account that is NOT a patient therefore gets only the two
                 * destinations every `users.tipe` may open - `/dashboard`, which reads
                 * the account's own profile, and the public directory - rather than a
                 * patient menu whose links would answer 403.
                 */}
                {isPasien ? (
                    <>
                        <SidebarGroup>
                            <SidebarGroupLabel>Pasien</SidebarGroupLabel>

                            <SidebarMenu>
                                <MenuLink
                                    to="/dashboard"
                                    icon={ClipboardList}
                                    label="Dashboard"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />

                                <MenuLink
                                    to="/profil"
                                    icon={ClipboardList}
                                    label="Profil"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />

                                <MenuLink
                                    to="/profil/keluarga"
                                    icon={HeartPulse}
                                    label="Anggota keluarga"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />

                                <MenuLink
                                    to="/profil/alergi"
                                    icon={ShieldAlert}
                                    label="Alergi"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />

                                <MenuLink
                                    to="/profil/privasi"
                                    icon={ShieldCheck}
                                    label="Privasi dan data"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />

                                <MenuLink
                                    to="/profil/perangkat"
                                    icon={MonitorSmartphone}
                                    label="Perangkat"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />
                            </SidebarMenu>
                        </SidebarGroup>

                        <SidebarGroup>
                            <SidebarGroupLabel>Umum</SidebarGroupLabel>

                            <SidebarMenu>
                                <MenuLink
                                    to="/?direktori=semua"
                                    icon={Stethoscope}
                                    label="Direktori dokter"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />
                            </SidebarMenu>
                        </SidebarGroup>

                        {/**
                         * "Booking masuk" (`/dokter/booking`, the doctor's list of
                         * incoming bookings) is part of the deferred doctor entrance,
                         * so only the patient's own list is offered here. It used to be
                         * rendered for every account and answered a patient with the
                         * 403 the server returns for `tipe:dokter`.
                         */}
                        <SidebarGroup>
                            <SidebarGroupLabel>Booking</SidebarGroupLabel>

                            <SidebarMenu>
                                <MenuLink
                                    to="/booking"
                                    icon={CalendarDays}
                                    label="Booking saya"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />
                            </SidebarMenu>
                        </SidebarGroup>

                        {/**
                         * F11's two self-service screens. The endpoints carry
                         * `permission:notifikasi.lihat`, which every role holds except
                         * `perawat`/`kurir`; they are here for the patient who owns the
                         * reminder, and the doctor's copy belongs with the doctor
                         * entrance that does not exist yet.
                         */}
                        <SidebarGroup>
                            <SidebarGroupLabel>Notifikasi &amp; pengingat</SidebarGroupLabel>

                            <SidebarMenu>
                                <MenuLink
                                    to="/profil/notifikasi"
                                    icon={Bell}
                                    label="Notifikasi"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />

                                <MenuLink
                                    to="/pengingat"
                                    icon={AlarmClock}
                                    label="Pengingat"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />
                            </SidebarMenu>
                        </SidebarGroup>

                        {/**
                         * Module 3's two screens, and the two that needed the F3-06
                         * fix: both point at an id-free index route, so `/konsultasi`
                         * and `/rekam-medis` resolve the caller's own ids from their
                         * prescription history or say that there are none. `/1` is a row
                         * in another tenant's database.
                         */}
                        <SidebarGroup>
                            <SidebarGroupLabel>Konsultasi</SidebarGroupLabel>

                            <SidebarMenu>
                                <MenuLink
                                    to="/konsultasi"
                                    icon={MessagesSquare}
                                    label="Konsultasi"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />

                                <MenuLink
                                    to="/rekam-medis"
                                    icon={FileHeart}
                                    label="Rekam medis"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />
                            </SidebarMenu>
                        </SidebarGroup>

                        {/**
                         * Module 4, patient half: the prescription history is
                         * patient-owned data (`GET /pasien/resep`). "Tulis resep"
                         * (`tipe:dokter`) and "Antrean apoteker" (`tipe:apoteker`) are
                         * part of the deferred practitioner entrance and are not
                         * rendered - a group with nothing the patient may use in it
                         * would be a heading over an empty list.
                         */}
                        <SidebarGroup>
                            <SidebarGroupLabel>Resep</SidebarGroupLabel>

                            <SidebarMenu>
                                <MenuLink
                                    to="/pasien/resep"
                                    icon={Pill}
                                    label="Riwayat resep"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />
                            </SidebarMenu>
                        </SidebarGroup>

                        {/**
                         * Module 5. All three point at index routes: `/pesanan` and
                         * `/pembayaran` cannot be resolved from anything because the API
                         * publishes no order list and no invoice list, so those pages
                         * say so and point at the checkout confirmation - the only
                         * moment a patient is given an order number. The previous
                         * `/pesanan/1` and `/pembayaran/1` were 404 cards.
                         */}
                        <SidebarGroup>
                            <SidebarGroupLabel>Obat dan pembayaran</SidebarGroupLabel>

                            <SidebarMenu>
                                <MenuLink
                                    to="/checkout"
                                    icon={Package}
                                    label="Checkout resep"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />

                                <MenuLink
                                    to="/pesanan"
                                    icon={Package}
                                    label="Lacak pesanan"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />

                                <MenuLink
                                    to="/pembayaran"
                                    icon={ClipboardCheck}
                                    label="Bayar"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />
                            </SidebarMenu>
                        </SidebarGroup>
                    </>
                ) : (
                    <SidebarGroup>
                        <SidebarGroupLabel>Umum</SidebarGroupLabel>

                        <SidebarMenu>
                            <MenuLink
                                to="/dashboard"
                                icon={ClipboardList}
                                label="Dashboard"
                                pathname={pathname}
                                onNavigate={tutupDrawer}
                            />

                            <MenuLink
                                to="/?direktori=semua"
                                icon={Stethoscope}
                                label="Direktori dokter"
                                pathname={pathname}
                                onNavigate={tutupDrawer}
                            />
                        </SidebarMenu>
                    </SidebarGroup>
                )}
            </SidebarContent>

            <SidebarFooter>
                <div className="flex items-center gap-2">
                    <NotificationBell />

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="flex-1"
                        disabled={signOut.isPending}
                        onClick={() => {
                            signOut.mutate();
                        }}
                    >
                        {signOut.isPending ? <Spinner /> : <LogOut />}

                        Keluar
                    </Button>
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
