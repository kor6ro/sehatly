import { Link } from 'react-router';
import {
    AlertCircle,
    ClipboardList,
    HeartPulse,
    LogOut,
    ShieldCheck,
    Stethoscope,
    User,
} from 'lucide-react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { logout } from '@/lib/api/auth';
import { meOptions } from '@/lib/api/me';
import { ApiError } from '@/lib/http';
import { clearTokens, getRefreshToken } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
import { formatWaktu } from '@/lib/format';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState } from '@/components/states/error-state';
import { ForbiddenState } from '@/components/states/error-state';
import { PageHeader } from '@/components/layout/page-header';

/**
 * `/dashboard` - the signed-in landing page, and the first real authenticated read.
 *
 * ## The three states are all reachable, and none of them is "nothing"
 *
 * - **loading**: a skeleton, because `/me` is three queries server-side and a flash of
 *   empty content reads as a broken page.
 * - **error**: `ErrorState` with a retry, except for a 401 - which the transport has
 *   already turned into a refresh or a sign-out, so reaching this branch with a 401 means
 *   the recovery itself failed and the user is being navigated away.
 * - **"empty"**: `MeController` documents that an account may own **neither** a `pasien`
 *   nor a `dokter` row and still be a legitimate caller. So "this account has no patient
 *   record" is a real state, not an error, and it gets its own copy that says which
 *   account type is affected and what the patient screens will do.
 */
export function DashboardPage() {
    const me = useQuery(meOptions());

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
        },
    });

    if (me.isPending) {
        return (
            <>
                <PageHeader title="Dashboard" description="Memuat data akun..." />

                <SkeletonRows rows={4} />
            </>
        );
    }

    if (me.isError) {
        return (
            <>
                <PageHeader title="Dashboard" />

                <ErrorState
                    error={
                        me.error instanceof ApiError && me.error.isUnauthorized
                            ? new ApiError(me.error.status, 'Sesi berakhir. Silakan masuk kembali.')
                            : me.error
                    }
                    onRetry={() => {
                        void me.refetch();
                    }}
                />
            </>
        );
    }

    const user = me.data.data.user;
    const isPasien = user.tipe === 'pasien';

    return (
        <>
            <PageHeader
                title={`Selamat datang, ${user.nama_lengkap}`}
                description="Ringkasan akun dan akses cepat."
                action={
                    <Button
                        type="button"
                        variant="outline"
                        disabled={signOut.isPending}
                        onClick={() => {
                            signOut.mutate();
                        }}
                    >
                        {signOut.isPending ? <Spinner /> : <LogOut />}

                        Keluar
                    </Button>
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <User className="size-4" />

                            Akun
                        </CardTitle>

                        <CardDescription>
                            Data akun Anda yang terdaftar di Sehatly.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="flex flex-col gap-4">
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Detail label="Nama lengkap" value={user.nama_lengkap} />

                            <Detail label="Tipe akun" value={user.tipe} />

                            <Detail
                                label="Nomor telepon"
                                value={user.no_telepon}
                            />

                            <Detail
                                label="Email"
                                value={user.email ?? '-'}
                            />

                            <Detail label="Bahasa" value={user.bahasa} />

                            <Detail
                                label="Terakhir masuk"
                                value={formatWaktu(user.last_login_at)}
                            />
                        </dl>

                        <Separator />

                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={user.status === 'aktif' ? 'default' : 'secondary'}>
                                {user.status}
                            </Badge>

                            <Badge
                                variant={
                                    user.telepon_terverifikasi ? 'default' : 'secondary'
                                }
                            >
                                {user.telepon_terverifikasi
                                    ? 'Telepon terverifikasi'
                                    : 'Telepon belum diverifikasi'}
                            </Badge>

                            <Badge
                                variant={user.email_terverifikasi ? 'default' : 'secondary'}
                            >
                                {user.email_terverifikasi
                                    ? 'Email terverifikasi'
                                    : 'Email belum diverifikasi'}
                            </Badge>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Stethoscope className="size-4" />

                            Akses
                        </CardTitle>
                    </CardHeader>

                    <CardContent className="flex flex-col gap-2">
                        <NavLink
                            to="/profil"
                            icon={<ClipboardList />}
                            label="Profil pasien"
                            disabled={!isPasien}
                        />

                        <NavLink
                            to="/profil/keluarga"
                            icon={<HeartPulse />}
                            label="Anggota keluarga"
                            disabled={!isPasien}
                        />

                        <NavLink
                            to="/dokter"
                            icon={<Stethoscope />}
                            label="Direktori dokter"
                        />
                    </CardContent>
                </Card>
            </div>

            {user.pasien === undefined ? (
                <ForbiddenState
                    detail="Akun ini tidak memiliki data pasien. Halaman profil, anggota keluarga, dan alergi hanya dapat dibuka oleh akun yang memiliki data pasien."
                />
            ) : null}
        </>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>

            <dd className="text-sm font-medium">{value}</dd>
        </div>
    );
}

/**
 * A dashboard destination.
 *
 * A disabled entry states *why* it is disabled rather than hiding it, because an account
 * with no `pasien` row is a legitimate caller and the patient screens' 403 is about the
 * caller, not about a missing feature.
 */
function NavLink({
    to,
    icon,
    label,
    disabled = false,
}: {
    to: string;
    icon: React.ReactNode;
    label: string;
    disabled?: boolean;
}) {
    if (disabled) {
        return (
            <div
                className="text-muted-foreground flex items-center gap-2 rounded-md border border-dashed px-3 py-2 text-sm"
                aria-disabled
            >
                {icon}

                <span className="flex-1">{label}</span>

                <AlertCircle className="size-4" />
            </div>
        );
    }

    return (
        <Link
            to={to}
            className="hover:bg-accent hover:text-accent-foreground flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors"
        >
            {icon}

            <span className="flex-1">{label}</span>

            <ShieldCheck className="size-4" />
        </Link>
    );
}
