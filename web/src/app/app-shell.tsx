import type { LucideIcon } from 'lucide-react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router';
import {
    AlarmClock,
    BarChart3,
    Bell,
    CalendarDays,
    ClipboardCheck,
    ClipboardList,
    FileHeart,
    HeartPulse,
    LayoutDashboard,
    LogOut,
    MessagesSquare,
    MonitorSmartphone,
    Package,
    Pill,
    ScrollText,
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
 * There are thirteen destinations and no nested sections, so a generated nav would be
 * thirteen lines of configuration plus a component to interpret it. A list is the honest
 * amount of machinery for this many items, and the patient-only entries are filtered on
 * `user.tipe === 'pasien'` so a non-patient account is not offered a 403.
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

/**
 * The whole navigation an `admin`/`superadmin` account sees.
 *
 * ## Why it replaces the generic nav instead of adding to it
 *
 * The F14 pattern's RBAC boundary is "a menu the account may not use is not
 * rendered". The generic nav offers patient-owned booking, consultation and
 * prescription destinations that an admin cannot open (the role holds no
 * `rekam_medis.lihat`/`resep.lihat` and the patient routes are profile-owned),
 * so the honest answer is a nav of the admin's own destinations plus the public
 * directory - which is exactly the AC-11 assertion (zero Rekam medis/Resep
 * links on an admin account) rather than a disabled menu.
 */
function AdminNav({
    pathname,
    onNavigate,
}: {
    pathname: string;
    onNavigate: () => void;
}) {
    return (
        <>
            <SidebarGroup>
                <SidebarGroupLabel>Umum</SidebarGroupLabel>

                <SidebarMenu>
                    <MenuLink
                        to="/dashboard"
                        icon={ClipboardList}
                        label="Dashboard"
                        pathname={pathname}
                        onNavigate={onNavigate}
                    />

                    <MenuLink
                        to="/dokter"
                        icon={Stethoscope}
                        label="Direktori dokter"
                        pathname={pathname}
                        onNavigate={onNavigate}
                    />
                </SidebarMenu>
            </SidebarGroup>

            <SidebarGroup>
                <SidebarGroupLabel>Admin klinik</SidebarGroupLabel>

                <SidebarMenu>
                    <MenuLink
                        to="/admin/dokter"
                        icon={HeartPulse}
                        label="Dokter"
                        pathname={pathname}
                        onNavigate={onNavigate}
                    />

                    <MenuLink
                        to="/admin/laporan"
                        icon={BarChart3}
                        label="Laporan"
                        pathname={pathname}
                        onNavigate={onNavigate}
                    />

                    <MenuLink
                        to="/admin/audit-log"
                        icon={ScrollText}
                        label="Jejak audit"
                        pathname={pathname}
                        onNavigate={onNavigate}
                    />

                    <MenuLink
                        to="/admin/persetujuan-pdp"
                        icon={ShieldCheck}
                        label="Persetujuan PDP"
                        pathname={pathname}
                        onNavigate={onNavigate}
                    />
                </SidebarMenu>
            </SidebarGroup>
        </>
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
    const isPasien = user?.tipe === 'pasien';
    const isDokter = user?.tipe === 'dokter';
    const isApotek = user?.tipe === 'apoteker' || user?.tipe === 'superadmin';

    /**
     * F14's account-type guard, and the reason the nav is split further down.
     *
     * The backend's whole `/admin` group is `tipe:admin,superadmin`. The role
     * holds no `rekam_medis.lihat` and no `resep.lihat` (`RbacCatalog`), so for
     * an admin the clinical groups are not merely irrelevant - following them
     * would be a 403. The F14 pattern's RBAC rule is "a control that may not be
     * used is not rendered", so an admin nav carries the admin destinations and
     * the public directory, and the patient/doctor/clinical groups are omitted.
     * This branch is why AC-11 can assert zero Rekam medis/Resep links for an
     * admin account.
     */
    const isAdmin = user?.tipe === 'admin' || user?.tipe === 'superadmin';

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
                {isAdmin ? (
                    <AdminNav pathname={pathname} onNavigate={tutupDrawer} />
                ) : (
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

                        {isPasien ? (
                            <>
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
                            </>
                        ) : null}
                    </SidebarMenu>
                </SidebarGroup>

                <SidebarGroup>
                    <SidebarGroupLabel>Umum</SidebarGroupLabel>

                    <SidebarMenu>
                        <MenuLink
                            to="/dokter"
                            icon={Stethoscope}
                            label="Direktori dokter"
                            pathname={pathname}
                            onNavigate={tutupDrawer}
                        />
                    </SidebarMenu>
                </SidebarGroup>

                {/**
                 * F13's dashboard is a doctor-only home: every endpoint behind it is
                 * `tipe:dokter`, so offering it to a patient would be a 403 card. The
                 * group is hidden rather than disabled for that reason - unlike the
                 * booking list below, where the refusal itself is informative.
                 */}
                {isDokter ? (
                    <SidebarGroup>
                        <SidebarGroupLabel>Dokter</SidebarGroupLabel>

                        <SidebarMenu>
                            <MenuLink
                                to="/dokter/dashboard"
                                icon={LayoutDashboard}
                                label="Dasbor dokter"
                                pathname={pathname}
                                onNavigate={tutupDrawer}
                            />
                        </SidebarMenu>
                    </SidebarGroup>
                ) : null}

                {/**
                 * Booking is shown to every signed-in account, not only to patients,
                 * because `GET /api/v1/dokter/booking` is a real doctor-side surface and
                 * `tipe:dokter` is the only thing separating the two. Hiding it from a
                 * doctor would hide the one list they can actually read, and showing it to
                 * a patient costs them a 403 screen that explains why - which is the
                 * server's own contract, not a client-side guess.
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

                        <MenuLink
                            to="/dokter/booking"
                            icon={ClipboardCheck}
                            label="Booking masuk"
                            pathname={pathname}
                            onNavigate={tutupDrawer}
                        />
                    </SidebarMenu>
                </SidebarGroup>

                {/**
                 * F11's two self-service screens, for the two roles the pattern names -
                 * the patient who owns the reminder and the doctor who receives the
                 * notification. The endpoints carry `permission:notifikasi.lihat`,
                 * which every role holds except `perawat`/`kurir`; those two are
                 * already outside this nav, and admin gets {@link AdminNav} instead.
                 */}
                {isPasien || isDokter ? (
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
                ) : null}

                {/**
                 * Module 3's two screens, and the two that needed the F3-06 fix. Both point
                 * at an id-free index route now: `/konsultasi` and `/rekam-medis` resolve
                 * the caller's own ids from their prescription history, or say that there
                 * are none. `/1` is a row in another tenant's database.
                 *
                 * Both are here rather than filtered by account type because each one is a
                 * real surface for both sides: `GET /konsultasi/{id}` and
                 * `GET /rekam-medis/{id}` are readable by the patient, the doctor, and
                 * `admin`/`superadmin`, and the SOAP form and the record editor are gated
                 * inside the page on `user.tipe` rather than here. Hiding a link a
                 * signed-in account is entitled to follow would be a worse failure than
                 * showing one whose content explains the refusal.
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
                 * Module 4's screens, split by who each one is for rather than rendered
                 * for everyone.
                 *
                 * The prescription composer and the pharmacy queue are the two genuinely
                 * single-audience screens here: `POST /konsultasi/{id}/resep` carries
                 * `tipe:dokter` and `POST /resep/{id}/verifikasi` carries `tipe:apoteker`, so
                 * showing either to the wrong account type costs a 403 screen. The history
                 * is patient-owned data, so it is offered to the patient alone.
                 *
                 * "Tulis resep" points at `/konsultasi` and no longer at
                 * `/konsultasi/1/resep`: a prescription hangs off a consultation, and id `1`
                 * is not this doctor's consultation. The index is where a consultation
                 * would be chosen; its content states the limit for a doctor, who has no
                 * prescription history to derive one from.
                 */}
                <SidebarGroup>
                    <SidebarGroupLabel>Resep</SidebarGroupLabel>

                    <SidebarMenu>
                        {isDokter ? (
                            <MenuLink
                                to="/konsultasi"
                                icon={Pill}
                                label="Tulis resep"
                                pathname={pathname}
                                onNavigate={tutupDrawer}
                            />
                        ) : null}

                        {isApotek ? (
                            <MenuLink
                                to="/apotek/resep"
                                icon={ClipboardCheck}
                                label="Antrean apoteker"
                                pathname={pathname}
                                onNavigate={tutupDrawer}
                            />
                        ) : null}

                        {isPasien ? (
                            <MenuLink
                                to="/pasien/resep"
                                icon={Pill}
                                label="Riwayat resep"
                                pathname={pathname}
                                onNavigate={tutupDrawer}
                            />
                        ) : null}
                    </SidebarMenu>
                </SidebarGroup>

                {/**
                 * Module 5. Shown to every signed-in account rather than filtered by type,
                 * because the routes behind them are different surfaces and only the
                 * checkout write is `pasien`-gated: `GET /pesanan-obat/{id}` also serves
                 * `apoteker` and `admin`. Hiding a link an account may follow would be a
                 * worse failure than showing one whose content explains the refusal.
                 *
                 * All three point at index routes. `/pesanan` and `/pembayaran` cannot be
                 * resolved from anything: the API publishes no order list and no invoice
                 * list, so those pages say so and point at the checkout confirmation, which
                 * is the only moment a patient is given an order number. The previous
                 * `/pesanan/1` and `/pembayaran/1` were 404 cards for everyone else.
                 */}
                <SidebarGroup>
                    <SidebarGroupLabel>Obat dan pembayaran</SidebarGroupLabel>

                    <SidebarMenu>
                        {isPasien ? (
                            <MenuLink
                                to="/checkout"
                                icon={Package}
                                label="Checkout resep"
                                pathname={pathname}
                                onNavigate={tutupDrawer}
                            />
                        ) : null}

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
