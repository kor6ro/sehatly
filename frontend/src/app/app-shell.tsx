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

function AppSidebar() {
    const navigate = useNavigate();
    const location = useLocation();
    const me = useQuery(meOptions());
    const { setOpenMobile } = useSidebar();

    const pathname = location.pathname;
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

            void navigate('/login', { replace: true });
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
