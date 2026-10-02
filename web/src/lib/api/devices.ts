import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type { UserDevice } from '@/lib/api/types';

/**
 * `GET|POST /api/v1/auth/devices` and `DELETE /api/v1/auth/devices/{deviceId}`.
 *
 * `UserDeviceResource` publishes `device_id`, so "perangkat ini" is decided by comparing
 * against the locally stored installation id (`lib/token.ts`, `getDeviceId()`). When that
 * id is absent there is no badge rather than a wrong one.
 */

export async function fetchDevices() {
    return request<{ devices: UserDevice[] }>('auth/devices');
}

export async function revokeDevice(deviceId: string) {
    return request<{ device: UserDevice }>(
        `auth/devices/${encodeURIComponent(deviceId)}`,
        {
            method: 'DELETE',
            retry: 0,
        },
    );
}

export const devicesQueryKey = ['v1', 'auth', 'devices'] as const;

export function devicesOptions() {
    return queryOptions({
        queryKey: devicesQueryKey,
        queryFn: fetchDevices,
        staleTime: 30_000,
    });
}

export function revokeDeviceMutation() {
    return mutationOptions({
        mutationFn: revokeDevice,
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: devicesQueryKey });
        },
    });
}
