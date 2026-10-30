import { useEffect, useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router';
import {
    AlarmClock,
    Bell,
    CalendarDays,
    ClipboardCheck,
    ClipboardList,
    FileHeart,
    LogOut,
    MessagesSquare,
    MonitorSmartphone,
    Package,
    Pill,
    ShieldAlert,
    ShieldCheck,
    Stethoscope,
    Tag,
} from 'lucide-react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { detectPortal, PORTAL_NAMA, type Portal } from '@/lib/portal';
import { logout } from '@/lib/api/auth';
import { meOptions } from '@/lib/api/me';
import { clearTokens, getRefreshToken } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Toaster } from '@/components/ui/sonner';
import { NotificationBell } from '@/shared/features/notifikasi/notification-bell';
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

type ItemMenuPortal = { to: string; icon: LucideIcon; label: string };
type GrupMenuPortal = { grup: string; item: readonly ItemMenuPortal[] };

/**
 * Each staff portal's sidebar (plan section 2.3), keyed by portal and rendered when
 * `detectPortal()` says this bundle is serving that subdomain.
 *
 * These are the entrances the patient sidebar used to hold back - the "dedicated door
 * of their own" its comment promised - and they list exactly the leaves their route
 * tables register. `kurir` and `marketing` each have ONE item today because their
 * workspaces are Steps 15-16; the items point at the portals' real home addresses,
 * which render the honest placeholder leaf rather than a 404.
 *
 * Adding a portal screen later is a leaf in its route table plus one entry here -
 * nothing else reads this map.
 */
const MENU_PORTAL: Record<Exclude<Portal, 'pasien'>, readonly GrupMenuPortal[]> = {
    admin: [
        {
            grup: 'Utama',
            item: [{ to: '/admin/dashboard', icon: ClipboardList, label: 'Dashboard' }],
        },
        {
            grup: 'Klinik',
            item: [{ to: '/admin/dokter', icon: Stethoscope, label: 'Dokter' }],
        },
        {
            grup: 'Laporan',
            item: [
                { to: '/admin/laporan', icon: ClipboardCheck, label: 'Laporan & Statistik' },
            ],
        },
        {
            grup: 'Sistem',
            item: [
                { to: '/admin/audit-log', icon: FileHeart, label: 'Audit log' },
                {
                    to: '/admin/persetujuan-pdp',
                    icon: ShieldCheck,
                    label: 'Persetujuan PDP',
                },
                { to: '/admin/hero', icon: MonitorSmartphone, label: 'Hero landing' },
            ],
        },
    ],
    dokter: [
        {
            grup: 'Dokter',
            item: [
                { to: '/dokter/dashboard', icon: ClipboardList, label: 'Dashboard' },
                { to: '/dokter/booking', icon: CalendarDays, label: 'Booking masuk' },
            ],
        },
    ],
    apotek: [
        {
            grup: 'Apotek',
            item: [
                { to: '/apotek/dashboard', icon: ClipboardList, label: 'Dashboard' },
                { to: '/apotek/resep', icon: Pill, label: 'Antrean resep' },
            ],
        },
    ],
    kurir: [
        {
            grup: 'Kurir',
            item: [{ to: '/kurir/pesanan', icon: Package, label: 'Pesanan' }],
        },
    ],
    marketing: [
        {
            grup: 'Marketing',
            item: [
                { to: '/marketing/dashboard', icon: ClipboardCheck, label: 'Dashboard' },
                { to: '/marketing/artikel', icon: FileHeart, label: 'Artikel' },
                { to: '/marketing/promo', icon: Tag, label: 'Promo' },
            ],
        },
    ],
};

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

                <TopBar />

                <main className="flex flex-1 flex-col gap-6 overflow-visible p-4 md:p-6">
                    <Outlet />
                </main>
            </SidebarInset>

            <Toaster />
        </div>
    );
}
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
 * The desktop top bar: the portal's name on the left; the running clock, the
 * notification bell and the account avatar on the right.
 *
 * `hidden md:flex` - `MobileNavBar` owns the small screen, and two bars
 * stacked on a phone is chrome the content cannot afford. The clock ticks
 * every30 seconds rather than every second: this bar is a glance, not a
 * stopwatch, and re-rendering the shell once a minute for a minute display is
 * a trade nothing here needs. The avatar links to the profile editor, the one
 * address every portal registers for "who am I".
 */
function TopBar() {
    const me = useQuery(meOptions());
    const user = me.data?.data.user ?? null;
    const portal = detectPortal();

    const [sekarang, setSekarang] = useState(() => new Date());

    useEffect(() => {
        const id = window.setInterval(() => setSekarang(new Date()), 30_000);

        return () => window.clearInterval(id);
    }, []);

    const tanggal = new Intl.DateTimeFormat('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(sekarang);

    const jam = new Intl.DateTimeFormat('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(sekarang);

    return (
        <div className="bg-background sticky top-0 z-20 hidden h-14 shrink-0 items-center justify-between gap-3 border-b border-border px-4 md:flex">
            <p className="text-sm font-semibold">{PORTAL_NAMA[portal]}</p>

            <div className="flex items-center gap-3">
                <p className="text-muted-foreground hidden text-sm lg:inline">
                    {tanggal} • {jam}
                </p>

                <NotificationBell />

                <Link
                    to={user === null ? '#' : `/profil/edit/${user.id}`}
                    title="Profil"
                    className="bg-primary text-primary-foreground grid size-8 shrink-0 place-items-center rounded-full text-xs font-semibold"
                >
                    {ambilInisial(user?.nama_lengkap)}
                </Link>
            </div>
        </div>
    );
}

function ambilInisial(nama: string | undefined): string {
    if (!nama) {
        return '?';
    }

    const bagian = nama.trim().split(/\s+/);
    const huruf = (bagian[0]?.[0] ?? '') + (bagian[1]?.[0] ?? '');

    return huruf.toUpperCase() || '?';
}
function tujuanAktif(pathname: string, tujuan: string): boolean {
    if (tujuan === '/dashboard') {
        return pathname === '/dashboard';
    }

    return pathname === tujuan || pathname.startsWith(`${tujuan}/`);
}

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
 * The staff portal's menu, rendered whenever the hostname names a portal whose sidebar
 * is not the patient one. See {@link MENU_PORTAL} for the map it draws from.
 */
function StaffMenu({
    portal,
    pathname,
    onNavigate,
}: {
    portal: Exclude<Portal, 'pasien'>;
    pathname: string;
    onNavigate: () => void;
}) {
    return (
        <>
            {MENU_PORTAL[portal].map((grup) => (
                <SidebarGroup key={grup.grup}>
                    <SidebarGroupLabel>{grup.grup}</SidebarGroupLabel>

                    <SidebarMenu>
                        {grup.item.map((item) => (
                            <MenuLink
                                key={item.to}
                                to={item.to}
                                icon={item.icon}
                                label={item.label}
                                pathname={pathname}
                                onNavigate={onNavigate}
                            />
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}

function AppSidebar() {
    const navigate = useNavigate();
    const location = useLocation();
    const me = useQuery(meOptions());
    const { setOpenMobile } = useSidebar();

    const pathname = location.pathname;
    const portal = detectPortal();
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
        onSettled: () => {
            clearTokens();
            queryClient.clear();

            dispatchFlash({ level: 'info', message: 'Anda telah keluar.' });

            /**
             * `/`, deliberately WITHOUT `?masuk=1`.
             *
             * The `/masuk` page this replaces stood behind a logout for years, but a
             * sign-in form is not what somebody who just chose to leave should be shown:
             * the flash above says so, and it is rendered by `LandingPage`'s `Toaster`,
             * which a dialog would sit on top of. The header's own "Masuk" button is one
             * click away for anyone who meant to change accounts rather than to stop.
             */
            void navigate('/', { replace: true });
        },
    });

    const user = me.data?.data.user ?? null;
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
                 * On a STAFF subdomain the menu is that portal's own (`MENU_PORTAL` /
                 * `StaffMenu`): the practitioner and clinic entrances moved out of this
                 * table with the multi-portal split (docs/update-plan-pilihan-a.md,
                 * Steps 9-13) and now live behind their own `RequirePortalAuth`, one
                 * subdomain per role.
                 *
                 * What remains below is the PATIENT portal's menu, for a patient - and
                 * for anyone else on THIS portal the two destinations every `users.tipe`
                 * may open (`/dashboard`, the public directory). That fallback is still
                 * honest on the patient portal: a perawat or a staff account can open
                 * sehatly.test, and the account profile page reads their own row.
                 *
                 * This is F14's own RBAC rule - "a control the account may not use is
                 * not rendered" - applied by giving each role its own door instead of
                 * link by link. The staff route tables and this map are the two halves
                 * of that door: a new portal screen is a leaf plus one entry, nothing
                 * else.
                 */}
                {portal !== 'pasien' ? (
                    <StaffMenu
                        portal={portal}
                        pathname={pathname}
                        onNavigate={tutupDrawer}
                    />
                ) : isPasien ? (
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
                                    to="/?semua=1"
                                    icon={Stethoscope}
                                    label="Dokter Terdekat"
                                    pathname={pathname}
                                    onNavigate={tutupDrawer}
                                />
                            </SidebarMenu>
                        </SidebarGroup>

                        {/**
                         * "Booking masuk" (`/dokter/booking`, the doctor's list of
                         * incoming bookings) belongs to the doctor portal now, so only
                         * the patient's own list is offered here. It used to be
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
                         * `perawat` (the multi-portal round granted it to `kurir` as
                         * well); they are here for the patient who owns the reminder,
                         * and the doctor's copy belongs with the doctor portal's
                         * screens.
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
                         * patient-owned data (`GET /pasien/resep`). "Tulis resep" and
                         * "Antrean apoteker" live on the dokter and apotek portals now
                         * - a group with nothing the patient may use in it would be a
                         * heading over an empty list.
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
                                to="/?semua=1"
                                icon={Stethoscope}
                                label="Dokter Terdekat"
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
