import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { AlertCircle, Check, LogOut, Monitor, SearchX, Smartphone } from 'lucide-react';
import { logout, logoutAll } from '@/lib/api/auth';
import { devicesOptions, revokeDeviceMutation } from '@/lib/api/devices';
import type { UserDevice } from '@/lib/api/types';
import { ApiError } from '@/lib/http';
import { getDeviceId, getRefreshToken, clearTokens } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { formatWaktuZona } from '@/lib/waktu';
import { labelPerangkat, labelPlatform, perangkatAktif } from '@/lib/perangkat';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageHeader } from '@/components/layout/page-header';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState } from '@/components/states/error-state';

/**
 * `/profil/perangkat` - `GET /auth/devices`, `DELETE /auth/devices/{deviceId}`, and the
 * two session-ending actions.
 *
 * ## What each action actually does, because they are not interchangeable
 *
 * | control | endpoint | effect |
 * | --- | --- | --- |
 * | `Cabut` (per row) | `DELETE /auth/devices/{deviceId}` | removes the row from the list only |
 * | `Keluar dari perangkat ini` | `POST /auth/logout` | revokes THIS browser's refresh token and access token |
 * | `Keluar dari semua perangkat` | `POST /auth/logout-all` | revokes every token and deactivates every device |
 *
 * The per-row revoke cannot end the other device's session: `user_refresh_tokens` has no
 * `device_id` column, so the server cannot map a session to a device row. That mapping is
 * deferred, and the dialog copy states the limit instead of promising a revocation the
 * API does not perform.
 *
 * The server publishes `device_id`, so "Perangkat ini" is the row whose id equals the
 * locally stored installation id. Without a local id there is no badge rather than a
 * guessed one. A revoked row stays in the response with `aktif = false`; it is filtered
 * out of "Perangkat yang masuk" because it is no longer signed in.
 */
export function DevicesPage() {
    useDocumentTitle('Perangkat yang masuk | Sehatly');

    const navigate = useNavigate();
    const devices = useQuery(devicesOptions());
    const revoke = useMutation(revokeDeviceMutation());
    const [target, setTarget] = useState<UserDevice | null>(null);
    const [konfirmasiSemua, setKonfirmasiSemua] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [revokeError, setRevokeError] = useState<unknown>(null);

    const localDeviceId = getDeviceId();

    /**
     * `onSettled`, not `onSuccess`: the point of signing out is the local state, and a
     * sign-out that fails server-side must still remove the pair from this browser.
     */
    const keluar = useMutation({
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

    const keluarSemua = useMutation({
        mutationFn: () => logoutAll(),
        onSettled: () => {
            clearTokens();
            queryClient.clear();

            dispatchFlash({
                level: 'info',
                message: 'Anda telah keluar dari semua perangkat.',
            });

            void navigate('/login', { replace: true });
        },
    });

    function konfirmasiCabut(): void {
        if (target === null) {
            return;
        }

        revoke.mutate(target.device_id, {
            onSuccess: () => {
                setNotice('Perangkat telah dicabut.');
                setRevokeError(null);
            },
            onError: (error) => {
                setNotice(null);
                setRevokeError(error);
            },
            onSettled: () => {
                setTarget(null);
            },
        });
    }

    if (devices.isPending) {
        return (
            <>
                <PageHeader
                    title="Perangkat yang masuk"
                    description="Cabut perangkat yang tidak Anda kenali."
                />

                <SkeletonRows rows={3} />
            </>
        );
    }

    if (devices.isError) {
        return (
            <>
                <PageHeader title="Perangkat yang masuk" />

                <ErrorState
                    error={devices.error}
                    onRetry={() => {
                        void devices.refetch();
                    }}
                />
            </>
        );
    }

    const rows = devices.data.data.devices.filter(perangkatAktif);

    return (
        <>
            <PageHeader
                title="Perangkat yang masuk"
                description="Cabut perangkat yang tidak Anda kenali."
            />

            {notice === null ? null : (
                <div role="status" className="text-foreground flex items-center gap-2 text-sm">
                    <Check aria-hidden className="text-success size-4 shrink-0" />
                    {notice}
                </div>
            )}

            {revokeError instanceof ApiError && revokeError.isNotFound ? (
                <Alert variant="destructive" role="alert">
                    <SearchX />
                    <AlertTitle>Perangkat tidak ditemukan.</AlertTitle>
                    <AlertDescription>
                        <p>
                            Perangkat ini mungkin sudah tidak terdaftar pada akun Anda.
                        </p>
                    </AlertDescription>
                </Alert>
            ) : null}

            {revokeError !== null &&
            !(revokeError instanceof ApiError && revokeError.isNotFound) ? (
                <Alert variant="destructive" role="alert">
                    <AlertCircle />
                    <AlertTitle>Gagal mencabut perangkat</AlertTitle>
                    <AlertDescription>
                        <p>
                            {revokeError instanceof ApiError
                                ? revokeError.message
                                : 'Terjadi kesalahan yang tidak diketahui.'}
                        </p>
                    </AlertDescription>
                </Alert>
            ) : null}

            {rows.length === 0 ? (
                <EmptyState
                    title="Belum ada perangkat terdaftar."
                    description="Perangkat yang Anda gunakan untuk masuk akan muncul di sini."
                />
            ) : (
                <ul className="flex flex-col gap-3" data-slot="device-list">
                    {rows.map((device) => {
                        const iniPerangkatIni =
                            localDeviceId !== null && device.device_id === localDeviceId;

                        return (
                            <li
                                key={device.device_id}
                                data-slot="device-row"
                                data-platform={device.platform}
                                className="border-border flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex items-start gap-3">
                                    {device.platform === 'web' ? (
                                        <Monitor
                                            aria-hidden
                                            className="text-muted-foreground mt-0.5 size-5 shrink-0"
                                        />
                                    ) : (
                                        <Smartphone
                                            aria-hidden
                                            className="text-muted-foreground mt-0.5 size-5 shrink-0"
                                        />
                                    )}

                                    <div className="flex flex-col gap-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-medium">
                                                {labelPerangkat(device)}
                                            </p>

                                            {iniPerangkatIni ? (
                                                <Badge variant="secondary">
                                                    Perangkat ini
                                                </Badge>
                                            ) : null}
                                        </div>

                                        <p className="text-muted-foreground text-sm">
                                            {labelPlatform(device.platform)} • Terakhir
                                            aktif {formatWaktuZona(device.last_active_at)}
                                        </p>
                                    </div>
                                </div>

                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11 shrink-0"
                                    onClick={() => {
                                        setNotice(null);
                                        setRevokeError(null);
                                        setTarget(device);
                                    }}
                                >
                                    Cabut
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}

            <section
                data-slot="device-logout-actions"
                className="border-border mt-6 flex flex-col gap-3 border-t pt-6"
            >
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    disabled={keluar.isPending}
                    onClick={() => {
                        keluar.mutate();
                    }}
                    data-slot="device-logout"
                >
                    <LogOut aria-hidden />

                    {keluar.isPending ? 'Keluar...' : 'Keluar dari perangkat ini'}
                </Button>

                <Button
                    type="button"
                    variant="destructive"
                    className="min-h-11"
                    disabled={keluarSemua.isPending}
                    onClick={() => {
                        setKonfirmasiSemua(true);
                    }}
                    data-slot="device-logout-all"
                >
                    <LogOut aria-hidden />

                    Keluar dari semua perangkat
                </Button>

                <p className="text-muted-foreground text-xs">
                    Keluar dari perangkat ini hanya mengakhiri sesi di peramban ini.
                    Keluar dari semua perangkat mengakhiri seluruh sesi akun Anda.
                </p>
            </section>

            <Dialog
                open={target !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setTarget(null);
                    }
                }}
            >
                <DialogContent data-slot="device-revoke-dialog">
                    <DialogHeader>
                        <DialogTitle>Cabut perangkat ini?</DialogTitle>
                        <DialogDescription>
                            Perangkat ini dihapus dari daftar. Sesi yang sedang aktif di
                            perangkat tersebut tidak otomatis berakhir dan harus keluar
                            sendiri.
                        </DialogDescription>
                    </DialogHeader>

                    <p className="text-muted-foreground text-xs">
                        Mengakhiri sesi perangkat lain dari daftar ini belum tersedia;
                        gunakan Keluar dari semua perangkat bila Anda kehilangan
                        kendali atas akun.
                    </p>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => {
                                setTarget(null);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            className="min-h-11"
                            disabled={revoke.isPending}
                            onClick={konfirmasiCabut}
                        >
                            {revoke.isPending ? 'Mencabut...' : 'Ya, cabut'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={konfirmasiSemua}
                onOpenChange={(open) => {
                    setKonfirmasiSemua(open);
                }}
            >
                <DialogContent data-slot="device-logout-all-dialog">
                    <DialogHeader>
                        <DialogTitle>Keluar dari semua perangkat?</DialogTitle>
                        <DialogDescription>
                            Semua perangkat yang masuk dengan akun ini akan keluar,
                            termasuk perangkat ini. Anda perlu masuk kembali di setiap
                            perangkat.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => {
                                setKonfirmasiSemua(false);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            className="min-h-11"
                            disabled={keluarSemua.isPending}
                            onClick={() => {
                                keluarSemua.mutate();
                            }}
                            data-slot="device-logout-all-confirm"
                        >
                            {keluarSemua.isPending
                                ? 'Mengakhiri semua sesi...'
                                : 'Ya, keluar dari semua'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
