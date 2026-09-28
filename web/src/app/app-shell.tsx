import { NavLink, Outlet, useNavigate } from 'react-router';
import {
    CalendarDays,
    ClipboardCheck,
    ClipboardList,
    FileHeart,
    HeartPulse,
    LogOut,
    MessagesSquare,
    ShieldAlert,
    Stethoscope,
} from 'lucide-react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { logout } from '@/lib/api/auth';
import { meOptions } from '@/lib/api/me';
import { clearTokens, getRefreshToken } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Toaster } from '@/components/ui/sonner';
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
} from '@/components/ui/sidebar';

/**
 * The chrome around every signed-in screen.
 *
 * ## Why the flash listener is mounted here
 *
 * `hooks/use-flash-toast.ts` listens for the `sehatly:flash` DOM event because the
 * Inertia-era source it replaced does not exist in a Vite SPA. Nothing mounted it, so every
 * `dispatchFlash()` from `lib/http.ts` and from the CRUD screens was being raised into a
 * void with a `<Toaster />` rendered and never fed. Mounting it here is what makes the
 * existing `sonner.tsx` relocation work at all.
 *
 * ## Why the nav is a hand-written list and not `SidebarMenuButton`'s collapsed variant
 *
 * There are four destinations and no nested sections, so a generated nav would be four
 * lines of configuration plus a component to interpret it. A list is the honest amount of
 * machinery for this many items, and the patient-only entries are filtered on
 * `user.tipe === 'pasien'` so a non-patient account is not offered a 403.
 */
export function AppShell() {
    return (
        <SidebarProvider>
            <SignedInLayout />
        </SidebarProvider>
    );
}

function SignedInLayout() {
    useFlashToast();

    return (
        <div className="flex min-h-screen w-full">
            <AppSidebar />

            <SidebarInset>
                <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                    <Outlet />
                </main>
            </SidebarInset>

            <Toaster />
        </div>
    );
}

function AppSidebar() {
    const navigate = useNavigate();
    const me = useQuery(meOptions());

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

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <p className="px-2 text-sm font-semibold">Sehatly</p>

                <p className="text-muted-foreground truncate px-2 text-xs">
                    {user?.nama_lengkap ?? 'Memuat akun...'}
                </p>
            </SidebarHeader>

            <SidebarSeparator />

            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Pasien</SidebarGroupLabel>

                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild>
                                <NavLink to="/dashboard">
                                    <ClipboardList />

                                    Dashboard
                                </NavLink>
                            </SidebarMenuButton>
                        </SidebarMenuItem>

                        {isPasien ? (
                            <>
                                <SidebarMenuItem>
                                    <SidebarMenuButton asChild>
                                        <NavLink to="/profil">
                                            <ClipboardList />

                                            Profil
                                        </NavLink>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>

                                <SidebarMenuItem>
                                    <SidebarMenuButton asChild>
                                        <NavLink to="/profil/keluarga">
                                            <HeartPulse />

                                            Anggota keluarga
                                        </NavLink>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>

                                <SidebarMenuItem>
                                    <SidebarMenuButton asChild>
                                        <NavLink to="/profil/alergi">
                                            <ShieldAlert />

                                            Alergi
                                        </NavLink>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </>
                        ) : null}
                    </SidebarMenu>
                </SidebarGroup>

                <SidebarGroup>
                    <SidebarGroupLabel>Umum</SidebarGroupLabel>

                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild>
                                <NavLink to="/dokter">
                                    <Stethoscope />

                                    Direktori dokter
                                </NavLink>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>

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
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild>
                                <NavLink to="/booking">
                                    <CalendarDays />

                                    Booking saya
                                </NavLink>
                            </SidebarMenuButton>
                        </SidebarMenuItem>

                        <SidebarMenuItem>
                            <SidebarMenuButton asChild>
                                <NavLink to="/dokter/booking">
                                    <ClipboardCheck />

                                    Booking masuk
                                </NavLink>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>

                {/**
                 * Module 3's two screens. Both are here rather than filtered by
                 * account type because each one is a real surface for both sides:
                 * `GET /konsultasi/{id}` and `GET /rekam-medis/{id}` are readable by
                 * the patient, the doctor, and `admin`/`superadmin`, and the SOAP form
                 * and the record editor are gated inside the page on `user.tipe`
                 * rather than here. Hiding a link a signed-in account is entitled to
                 * follow would be a worse failure than showing one whose content
                 * explains the refusal.
                 */}
                <SidebarGroup>
                    <SidebarGroupLabel>Konsultasi</SidebarGroupLabel>

                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild>
                                <NavLink to="/konsultasi/1">
                                    <MessagesSquare />

                                    Konsultasi
                                </NavLink>
                            </SidebarMenuButton>
                        </SidebarMenuItem>

                        <SidebarMenuItem>
                            <SidebarMenuButton asChild>
                                <NavLink to="/rekam-medis/1">
                                    <FileHeart />

                                    Rekam medis
                                </NavLink>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-full"
                    disabled={signOut.isPending}
                    onClick={() => {
                        signOut.mutate();
                    }}
                >
                    {signOut.isPending ? <Spinner /> : <LogOut />}

                    Keluar
                </Button>
            </SidebarFooter>
        </Sidebar>
    );
}
