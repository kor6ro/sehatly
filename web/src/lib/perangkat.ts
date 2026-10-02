import type { UserDevice } from '@/lib/api/types';

/**
 * The device API publishes `device_id`, `platform` and `app_versi` - no human device name.
 * These labels are built from what the server actually sends, without echoing the raw
 * installation id (F01 §9 keeps `device_id` out of aria labels, toasts, URLs and titles).
 */
export function labelPlatform(platform: string): string {
    switch (platform) {
        case 'android':
            return 'Android';
        case 'ios':
            return 'iOS';
        default:
            return 'Web';
    }
}

export function labelPerangkat(
    device: Pick<UserDevice, 'platform' | 'app_versi'>,
): string {
    const versi =
        device.app_versi === null || device.app_versi === ''
            ? ''
            : ` ${device.app_versi}`;

    return `Perangkat ${labelPlatform(device.platform)}${versi}`;
}

/**
 * Only an active row is a device that is "masuk". The endpoint returns deactivated rows
 * too (a device revoked from elsewhere), and those are not offered a "Cabut" action. A row
 * missing `aktif` is treated as active so an older payload still renders rather than
 * disappearing.
 */
export function perangkatAktif(device: UserDevice): boolean {
    return device.aktif !== false;
}
