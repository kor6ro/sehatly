import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { meOptions } from '@/lib/api/me';
import { ApiError } from '@/lib/http';
import { Button } from '@/components/ui/button';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { SkeletonRows } from '@/components/states/loading-state';

/**
 * The F14 route guard: the account must be `admin` or `superadmin`.
 *
 * ## Why the role, and not a permission code
 *
 * The F14 backend gates the whole `/admin` group on `tipe:admin,superadmin` and
 * the write routes carry no `permission:` at all - `dokter.kelola` and
 * `jadwal.kelola` were proposed and never approved. The read routes' codes
 * (`dokter.lihat`, `jadwal.lihat`, `laporan.lihat`, `audit.lihat`, `pdp.kelola`)
 * are not published by `GET /me`, so a client-side per-code check is impossible
 * as well as wrong: the account type is the guard the server actually enforces.
 *
 * ## `GET /me` is shared with the shell
 *
 * `AppShell` already reads it, so a page behind this gate costs one cached
 * query, not a second round trip. Mounting the gate means the page's own
 * requests only fire for an account that may make them, which is why the
 * forbidden case never produces a stray 403 (AC-11).
 */
export function isAdminTipe(tipe: string | undefined): boolean {
    return tipe === 'admin' || tipe === 'superadmin';
}

export function AdminGate({ children }: { children: ReactNode }) {
    const me = useQuery(meOptions());

    if (me.isPending) {
        return <SkeletonRows rows={4} />;
    }

    if (me.isError) {
        return (
            <ErrorState
                error={me.error}
                onRetry={() => {
                    void me.refetch();
                }}
            />
        );
    }

    if (!isAdminTipe(me.data?.data.user?.tipe)) {
        return (
            <ForbiddenState
                title="Akses ditolak"
                detail="Anda tidak memiliki izin untuk membuka halaman ini."
                action={
                    <Button asChild variant="outline" className="mt-1 min-h-11">
                        <Link to="/dashboard">Kembali ke dasbor</Link>
                    </Button>
                }
            />
        );
    }

    return <>{children}</>;
}

/**
 * The panel error state, with 403 split out as a refusal rather than a failure.
 *
 * A server-side permission revoked under a still-admin account answers 403, and
 * the F14 pattern's rule is that a 403 is `ForbiddenState` with a way back - not
 * a retry that cannot help. Every other status keeps `ErrorState` and its
 * retryable behaviour.
 */
export function AdminErrorState({
    error,
    onRetry,
    title,
}: {
    error: unknown;
    onRetry?: () => void;
    title?: string;
}) {
    if (error instanceof ApiError && error.isForbidden) {
        return (
            <ForbiddenState
                title="Akses ditolak"
                detail="Anda tidak memiliki izin untuk membuka halaman ini."
                action={
                    <Button asChild variant="outline" className="mt-1 min-h-11">
                        <Link to="/dashboard">Kembali ke dasbor</Link>
                    </Button>
                }
            />
        );
    }

    return <ErrorState error={error} onRetry={onRetry} title={title} />;
}
