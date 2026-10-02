import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { AlertCircle, Check, Monitor, SearchX, Smartphone } from 'lucide-react';
import { devicesOptions, revokeDeviceMutation } from '@/lib/api/devices';
import type { UserDevice } from '@/lib/api/types';
import { ApiError } from '@/lib/http';
import { getDeviceId } from '@/lib/token';
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
 * `/profil/perangkat` - `GET /auth/devices` plus `DELETE /auth/devices/{deviceId}`.
 *
 * The server publishes `device_id`, so "Perangkat ini" is the row whose id equals the
 * locally stored installation id. Without a local id there is no badge rather than a
 * guessed one. A revoked row stays in the response with `aktif = false`; it is filtered
 * out of "Perangkat yang masuk" because it is no longer signed in.
 */
export function DevicesPage() {
    useDocumentTitle('Perangkat yang masuk | Sehatly');

    const devices = useQuery(devicesOptions());
    const revoke = useMutation(revokeDeviceMutation());
    const [target, setTarget] = useState<UserDevice | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [revokeError, setRevokeError] = useState<unknown>(null);

    const localDeviceId = getDeviceId();

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

            <Dialog
                open={target !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setTarget(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cabut perangkat ini?</DialogTitle>
                        <DialogDescription>
                            Perangkat ini akan keluar dan harus masuk kembali.
                        </DialogDescription>
                    </DialogHeader>

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
        </>
    );
}
